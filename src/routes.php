<?php

declare(strict_types=1);

use FarmaSintonia\Services\Compatibilita;
use FarmaSintonia\Services\Db;
use FarmaSintonia\Services\FarmacoRicerca;
use FarmaSintonia\Services\LlmAnalysisException;
use FarmaSintonia\Services\LlmCache;
use FarmaSintonia\Services\LlmSintesi;
use FarmaSintonia\Services\RateLimiter;
use FarmaSintonia\Services\RcpFetcher;
use FarmaSintonia\Services\ReportPdf;
use FarmaSintonia\Services\UsoLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

/**
 * Risolve un elenco di codici AIC in un rapporto di compatibilità completo
 * (dati anagrafici → cache RCP on-demand → analisi deterministica →
 * eventuale sintesi LLM). Condivisa da /api/analizza e /api/report perché
 * entrambe partono dagli stessi codici AIC.
 */
function farmasintonia_costruisci_rapporto(PDO $db, array $codiciAic, bool $vuoleLlm, string $ipCliente): array
{
    $fetcher = new RcpFetcher($db);
    $avvisi = [];
    $farmaci = [];

    foreach ($codiciAic as $codiceAic) {
        $stmt = $db->prepare(
            'SELECT codice_aic, cod_farmaco, codice_ditta, denominazione, descrizione
             FROM ana_confezioni WHERE codice_aic = :aic'
        );
        $stmt->execute(['aic' => $codiceAic]);
        $confezione = $stmt->fetch();
        if ($confezione === false) {
            $avvisi[] = "farmaco con codice AIC {$codiceAic} non trovato";
            continue;
        }

        $stmtPa = $db->prepare('SELECT DISTINCT principio_attivo FROM ana_principi_attivi WHERE codice_aic = :aic');
        $stmtPa->execute(['aic' => $codiceAic]);
        $principiAttivi = array_column($stmtPa->fetchAll(), 'principio_attivo');

        $codFarmaco = (string) $confezione['cod_farmaco'];
        $codiceDitta = (string) $confezione['codice_ditta'];

        try {
            $disponibile = $fetcher->assicuraDocumento($codFarmaco, $codiceDitta, 'RCP');
        } catch (\Throwable $e) {
            $avvisi[] = "RCP di {$confezione['denominazione']} non recuperabile al momento: " . $e->getMessage();
            $disponibile = false;
        }

        $sezioniRaw = [];
        if ($disponibile) {
            $stmtSez = $db->prepare(
                'SELECT s.sezione_codice, s.sezione_titolo, s.contenuto
                 FROM farmaci_documenti d
                 JOIN farmaci_documenti_varianti v ON v.documento_id = d.id
                 JOIN farmaci_documenti_sezioni s ON s.variante_id = v.id
                 WHERE d.cod_farmaco = :cf AND d.codice_ditta = :cd AND d.tipo_documento = "RCP"'
            );
            $stmtSez->execute(['cf' => $codFarmaco, 'cd' => $codiceDitta]);
            $sezioniRaw = $stmtSez->fetchAll();
        } else {
            $avvisi[] = "RCP non disponibile per {$confezione['denominazione']}: analisi basata solo sui dati anagrafici per questo farmaco";
        }

        $farmaci[] = [
            'codice_aic' => $codiceAic,
            'denominazione' => $confezione['denominazione'],
            'aic6' => substr($codiceAic, 0, 6),
            'principi_attivi' => $principiAttivi,
            'sezioni' => Compatibilita::categorizzaSezioni($sezioniRaw),
        ];
    }

    if ($farmaci === []) {
        return ['errore' => 'nessuno dei codici AIC forniti è stato trovato', 'avvisi' => $avvisi];
    }

    $rapporto = (new Compatibilita())->costruisciRapporto($farmaci);
    $rapporto['avvisi'] = $avvisi;

    // Sintesi LLM: opt-out per singola richiesta con
    // "llm": false nel corpo; sempre e comunque un fallback esplicito (mai
    // un errore silenzioso) se la chiave non è configurata, se il limite
    // per IP è superato, o se OpenAI non risponde correttamente — l'analisi
    // deterministica sopra resta comunque completa.
    $apiKey = trim((string) ($_ENV['OPENAI_API_KEY'] ?? ''));
    $modello = trim((string) ($_ENV['OPENAI_MODEL'] ?? '')) ?: 'gpt-5.6-luna';
    $logger = new UsoLogger($db);

    if (!$vuoleLlm) {
        $rapporto['analisi_llm']['motivo'] = 'sintesi LLM disattivata per questa richiesta ("llm": false)';
    } elseif ($apiKey === '') {
        $rapporto['analisi_llm']['motivo'] = 'OPENAI_API_KEY non configurata: analisi limitata al riscontro deterministico';
    } else {
        // Cache dei risultati LLM (LlmCache/llm_sintesi_cache): la chiave
        // dipende dal testo RCP effettivamente inviato al modello, non solo
        // dall'elenco farmaci — richiamare più volte /api/analizza sulla
        // stessa terapia non richiama OpenAI (lento, a pagamento) ogni volta.
        // Una lettura di cache non consuma la quota del rate limiter: non
        // sta chiamando OpenAI, non c'è nulla da limitare.
        $chiaveCache = LlmSintesi::chiaveCache($farmaci, $modello);
        $cache = new LlmCache($db);
        $risultatoCache = $cache->leggi($chiaveCache);

        if ($risultatoCache !== null) {
            $rapporto['analisi_llm'] = [
                'richiesta' => true,
                'eseguita' => true,
                'modello' => $modello,
                'risultato' => $risultatoCache,
                'motivo' => null,
                'errore' => null,
                'dalla_cache' => true,
            ];
        } elseif (!(new RateLimiter($db))->consentito($ipCliente)) {
            $rapporto['analisi_llm'] = [
                'richiesta' => true,
                'eseguita' => false,
                'modello' => $modello,
                'risultato' => null,
                'motivo' => 'limite di richieste LLM per questo indirizzo IP raggiunto; riprovare più tardi '
                    . "(l'analisi deterministica sopra resta comunque valida)",
                'errore' => ['codice' => 'rate_limit_locale', 'messaggio' => 'limite orario superato', 'http_status' => 429],
            ];
            $logger->registraErroreLlm('rate_limit_locale', 'limite orario superato', $modello, 429);
        } else {
            try {
                $risultatoLlm = (new LlmSintesi($apiKey))->analizza($farmaci, $modello);
                $cache->scrivi($chiaveCache, $modello, $risultatoLlm);
                $rapporto['analisi_llm'] = [
                    'richiesta' => true,
                    'eseguita' => true,
                    'modello' => $modello,
                    'risultato' => $risultatoLlm,
                    'motivo' => null,
                    'errore' => null,
                    'dalla_cache' => false,
                ];
            } catch (LlmAnalysisException $e) {
                $rapporto['analisi_llm'] = [
                    'richiesta' => true,
                    'eseguita' => false,
                    'modello' => $modello,
                    'risultato' => null,
                    'motivo' => $e->getMessage(),
                    'errore' => $e->toArray(),
                ];
                $logger->registraErroreLlm($e->codice, $e->getMessage(), $modello, $e->httpStatus);
            }
        }
    }

    $logger->registraAnalisi($farmaci, !empty($rapporto['analisi_llm']['eseguita']), !empty($rapporto['analisi_llm']['dalla_cache']));

    return $rapporto;
}

return function (App $app): void {
    $app->get('/', function (Request $request, Response $response) {
        return Twig::fromRequest($request)->render($response, 'home.twig');
    });

    $app->get('/terapia', function (Request $request, Response $response) {
        return Twig::fromRequest($request)->render($response, 'terapia.twig');
    });

    $app->get('/risultati', function (Request $request, Response $response) {
        return Twig::fromRequest($request)->render($response, 'risultati.twig');
    });

    $app->get('/credits', function (Request $request, Response $response) {
        return Twig::fromRequest($request)->render($response, 'credits.twig');
    });

    // GET /api/farmaci/cerca?q=... — autocompletamento farmaco.
    $app->get('/api/farmaci/cerca', function (Request $request, Response $response) {
        $termine = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $risultati = (new FarmacoRicerca(Db::connection()))->cerca($termine);

        $response->getBody()->write(json_encode(['risultati' => $risultati], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    });

    // POST /api/analizza — riceve l'elenco terapia (codici AIC), garantisce la
    // cache RCP per ciascun farmaco (fetch on-demand se mancante) ed esegue
    // l'analisi di compatibilità, deterministica + sintesi LLM.
    $app->post('/api/analizza', function (Request $request, Response $response) {
        $corpo = $request->getParsedBody();
        $listaGrezza = is_array($corpo) ? ($corpo['codici_aic'] ?? null) : null;
        $codiciAic = is_array($listaGrezza)
            ? array_values(array_unique(array_map('strval', $listaGrezza)))
            : [];

        if ($codiciAic === []) {
            $response->getBody()->write(json_encode(
                ['errore' => 'specificare almeno un codice AIC in "codici_aic"'],
                JSON_UNESCAPED_UNICODE
            ));

            return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $vuoleLlm = !(is_array($corpo) && array_key_exists('llm', $corpo) && $corpo['llm'] === false);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        $rapporto = farmasintonia_costruisci_rapporto(Db::connection(), $codiciAic, $vuoleLlm, $ip);

        $response->getBody()->write(json_encode($rapporto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $status = isset($rapporto['errore']) ? 404 : 200;

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    });

    // POST /api/report — genera il PDF del report. Accetta un
    // rapporto già calcolato (stesso oggetto restituito da /api/analizza, che
    // il browser ha già in memoria dopo aver mostrato la pagina Risultati) per
    // evitare di rieseguire fetch RCP e chiamata LLM una seconda volta — il
    // PDF così è garantito identico a ciò che l'utente ha già visto a
    // schermo. In alternativa accetta "codici_aic" e ricalcola da zero.
    $app->post('/api/report', function (Request $request, Response $response) {
        $corpo = $request->getParsedBody();

        $rapporto = is_array($corpo) ? ($corpo['rapporto'] ?? null) : null;
        if (!is_array($rapporto)) {
            $listaGrezza = is_array($corpo) ? ($corpo['codici_aic'] ?? null) : null;
            $codiciAic = is_array($listaGrezza)
                ? array_values(array_unique(array_map('strval', $listaGrezza)))
                : [];
            if ($codiciAic === []) {
                $response->getBody()->write(json_encode(
                    ['errore' => 'specificare "rapporto" (già calcolato) oppure "codici_aic"'],
                    JSON_UNESCAPED_UNICODE
                ));

                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            $vuoleLlm = !(is_array($corpo) && array_key_exists('llm', $corpo) && $corpo['llm'] === false);
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
            $rapporto = farmasintonia_costruisci_rapporto(Db::connection(), $codiciAic, $vuoleLlm, $ip);
        }

        if (isset($rapporto['errore'])) {
            $response->getBody()->write(json_encode($rapporto, JSON_UNESCAPED_UNICODE));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $pdf = (new ReportPdf())->genera($rapporto);
        $response->getBody()->write($pdf);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="farmasintonia-report.pdf"')
            ->withHeader('Content-Length', (string) strlen($pdf));
    });
};
