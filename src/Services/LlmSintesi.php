<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Sintesi assistita da LLM: chiamata HTTP diretta via Guzzle all'API REST
 * di OpenAI (Chat Completions con output strutturato via JSON Schema), non
 * l'SDK ufficiale, per un controllo esplicito su timeout ed errori.
 *
 * Interviene solo COME SINTESI sopra l'analisi deterministica già trovata
 * in Compatibilita.php: non sostituisce il riscontro testuale, lo riformula
 * in linguaggio comprensibile e aggrega gli effetti collaterali tra farmaci
 * (aggregazione che l'analisi deterministica non fa).
 */
final class LlmSintesi
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    private const CATEGORIE_COMPATTE = [
        'controindicazioni',
        'avvertenze_e_precauzioni',
        'interazioni_con_altri_farmaci',
        'effetti_collaterali',
        'incompatibilita_farmaceutiche',
    ];

    private readonly Client $http;

    public function __construct(private readonly string $apiKey, ?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 60]);
    }

    /**
     * @param list<array{denominazione:string,aic6:string,principi_attivi:list<string>,sezioni:array<string,list<array<string,mixed>>>}> $farmaci
     * @return array<string,mixed> struttura LLMReport (vedi jsonSchema())
     *
     * @throws LlmAnalysisException
     */
    public function analizza(array $farmaci, string $modello): array
    {
        if (trim($this->apiKey) === '') {
            throw new LlmAnalysisException('token_mancante', 'impostare OPENAI_API_KEY nel file .env');
        }

        $corpo = [
            'model' => $modello,
            'messages' => [
                ['role' => 'system', 'content' => $this->promptSistema()],
                ['role' => 'user', 'content' => $this->promptUtente(self::payloadCompatto($farmaci))],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'rapporto_compatibilita',
                    'strict' => true,
                    'schema' => $this->jsonSchema(),
                ],
            ],
        ];

        try {
            $risposta = $this->http->post(self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $corpo,
            ]);
        } catch (RequestException $e) {
            throw $this->eccezioneDaRichiesta($e);
        }

        $corpoRisposta = json_decode((string) $risposta->getBody(), true);
        $contenuto = $corpoRisposta['choices'][0]['message']['content'] ?? null;
        if (!is_string($contenuto)) {
            throw new LlmAnalysisException('risposta_openai_non_valida', 'il modello non ha restituito un contenuto strutturato');
        }

        $decodificato = json_decode($contenuto, true);
        if (!is_array($decodificato)) {
            throw new LlmAnalysisException('risposta_openai_non_valida', 'il modello ha restituito JSON non valido');
        }

        return $decodificato;
    }

    private function eccezioneDaRichiesta(RequestException $e): LlmAnalysisException
    {
        $risposta = $e->getResponse();
        if ($risposta === null) {
            return new LlmAnalysisException(
                'connessione_openai_fallita',
                'impossibile connettersi a OpenAI (timeout o errore di rete): ' . $e->getMessage()
            );
        }

        $status = $risposta->getStatusCode();
        $corpo = json_decode((string) $risposta->getBody(), true);
        $codiceApi = is_array($corpo) ? ($corpo['error']['code'] ?? null) : null;

        if ($status === 401) {
            return new LlmAnalysisException('autenticazione_openai_fallita', 'chiave OpenAI non valida o non autorizzata per il progetto', $status);
        }
        if ($status === 429 && $codiceApi === 'insufficient_quota') {
            return new LlmAnalysisException(
                'quota_openai_esaurita',
                "quota API OpenAI esaurita: controllare crediti, fatturazione e limite mensile del progetto; l'analisi deterministica resta comunque disponibile",
                $status
            );
        }
        if ($status === 429) {
            return new LlmAnalysisException('limite_richieste_openai', 'limite temporaneo di richieste OpenAI raggiunto; riprovare più tardi', $status);
        }

        $messaggioApi = is_array($corpo) ? ($corpo['error']['message'] ?? null) : null;

        return new LlmAnalysisException(
            'errore_api_openai',
            "OpenAI ha restituito HTTP {$status}" . ($messaggioApi ? ": {$messaggioApi}" : ''),
            $status
        );
    }

    /**
     * Chiave di cache per LlmCache: stesso contenuto testuale inviato al
     * modello (payload compatto) + nome del modello. Si invalida da sola se
     * il testo RCP cambia (es. ri-fetch di un documento aggiornato) perché
     * la chiave dipende dal contenuto, non solo dall'elenco di farmaci.
     */
    public static function chiaveCache(array $farmaci, string $modello): string
    {
        $payload = json_encode(self::payloadCompatto($farmaci), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $modello . '|' . $payload);
    }

    /** @return array{medicinali: list<array<string,mixed>>} */
    private static function payloadCompatto(array $farmaci): array
    {
        $compatto = [];
        foreach ($farmaci as $farmaco) {
            $item = [
                'denominazione_farmaco' => $farmaco['denominazione'],
                'aic6' => $farmaco['aic6'],
                'principi_attivi' => $farmaco['principi_attivi'],
            ];
            foreach (self::CATEGORIE_COMPATTE as $categoria) {
                $visti = [];
                $testi = [];
                foreach ($farmaco['sezioni'][$categoria] ?? [] as $sezione) {
                    $testo = trim((string) $sezione['testo']);
                    if ($testo === '' || isset($visti[$testo])) {
                        continue;
                    }
                    $visti[$testo] = true;
                    $testi[] = [
                        'tipo_documento' => $sezione['tipo_documento'],
                        'numero_sezione' => $sezione['numero_sezione'],
                        'titolo' => $sezione['titolo'],
                        'testo' => $testo,
                    ];
                }
                $item[$categoria] = $testi;
            }
            $compatto[] = $item;
        }

        return ['medicinali' => $compatto];
    }

    private function promptSistema(): string
    {
        return 'Sei un analizzatore prudente di documenti regolatori farmaceutici. '
            . 'Analizza esclusivamente i testi RCP forniti. Non usare conoscenze '
            . 'esterne e non inventare interazioni. Distingui interazioni esplicite, '
            . 'rischi indiretti o sovrapposizioni e casi non determinabili. Se per la '
            . 'stessa coppia di farmaci più sezioni del RCP (es. avvertenze ed effetti '
            . 'indesiderati) descrivono lo stesso rischio clinico, unisci tutto in una '
            . "sola voce di 'interazioni' con tutte le evidenze pertinenti; crea voci "
            . 'distinte per quella coppia solo quando i rischi descritti sono '
            . 'clinicamente diversi tra loro. Non '
            . 'consigliare di iniziare, sospendere o cambiare dosaggi. Se la gravità '
            . 'non è dichiarata nel testo usa non_determinabile. Raggruppa gli effetti '
            . 'collaterali senza omettere eventi gravi, rari o a frequenza non nota. '
            . 'Per ogni singolo effetto collaterale indica esattamente tutti e soli i '
            . 'farmaci e principi attivi presenti nei documenti forniti che possono '
            . 'causarlo; se lo stesso effetto compare per più farmaci, includili tutti. '
            . 'Scrivi in italiano e ricorda che serve conferma di medico o farmacista.';
    }

    private function promptUtente(array $payload): string
    {
        return 'Valuta la possibile assunzione contemporanea di tutti i medicinali '
            . 'presenti. Produci il rapporto strutturato richiesto, citando nelle '
            . "evidenze brevi passaggi dei testi forniti. Dati:\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * JSON Schema per l'output strutturato (strict mode) della risposta
     * OpenAI. In strict mode l'API richiede "required" su tutte le
     * proprietà e "additionalProperties: false" a ogni livello; vincoli
     * come minItems non sono supportati in strict mode e vengono quindi
     * omessi qui (restano solo come intento, non come garanzia lato API).
     */
    private function jsonSchema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];

        $interazione = [
            'type' => 'object',
            'properties' => [
                'farmaci_coinvolti' => $stringArray,
                'principi_attivi_coinvolti' => $stringArray,
                'tipo' => [
                    'type' => 'string',
                    'enum' => ['esplicita', 'potenziale_indiretta', 'duplicazione_o_sovrapposizione', 'non_determinabile'],
                ],
                'livello_rischio' => [
                    'type' => 'string',
                    'enum' => ['controindicata', 'maggiore', 'moderata', 'minore', 'non_determinabile'],
                ],
                'sintesi' => ['type' => 'string'],
                'conseguenze_potenziali' => $stringArray,
                'evidenze_testuali' => $stringArray,
                'azione_prudenziale' => ['type' => 'string'],
            ],
            'required' => [
                'farmaci_coinvolti', 'principi_attivi_coinvolti', 'tipo', 'livello_rischio',
                'sintesi', 'conseguenze_potenziali', 'evidenze_testuali', 'azione_prudenziale',
            ],
            'additionalProperties' => false,
        ];

        $effettoSingolo = [
            'type' => 'object',
            'properties' => [
                'effetto' => ['type' => 'string'],
                'farmaci_associati' => $stringArray,
                'principi_attivi_associati' => $stringArray,
            ],
            'required' => ['effetto', 'farmaci_associati', 'principi_attivi_associati'],
            'additionalProperties' => false,
        ];

        $gruppoEffetti = [
            'type' => 'object',
            'properties' => [
                'categoria' => ['type' => 'string'],
                'effetti' => ['type' => 'array', 'items' => $effettoSingolo],
                'farmaci_associati' => $stringArray,
                'principi_attivi_associati' => $stringArray,
                'possibile_sovrapposizione' => ['type' => 'string'],
            ],
            'required' => ['categoria', 'effetti', 'farmaci_associati', 'principi_attivi_associati', 'possibile_sovrapposizione'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'avvertenza' => ['type' => 'string'],
                'riepilogo_terapia' => ['type' => 'string'],
                'interazioni' => ['type' => 'array', 'items' => $interazione],
                'effetti_collaterali_aggregati' => ['type' => 'array', 'items' => $gruppoEffetti],
                'rischi_cumulativi' => $stringArray,
                'segnali_di_allarme_da_riferire_subito' => $stringArray,
                'domande_per_medico_o_farmacista' => $stringArray,
                'limitazioni' => $stringArray,
            ],
            'required' => [
                'avvertenza', 'riepilogo_terapia', 'interazioni', 'effetti_collaterali_aggregati',
                'rischi_cumulativi', 'segnali_di_allarme_da_riferire_subito',
                'domande_per_medico_o_farmacista', 'limitazioni',
            ],
            'additionalProperties' => false,
        ];
    }
}
