<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Fetch on-demand + cache dei documenti RCP/FI da api.aifa.gov.it: endpoint
 * pubblico senza chiave, chiave di cache (cod_farmaco, codice_ditta,
 * tipo_documento), gestione esplicita del 404 come esito normale.
 */
final class RcpFetcher
{
    private const API_URL_TEMPLATE = 'https://api.aifa.gov.it/aifa-bdf-eif-be/1.0.0/organizzazione/%s/farmaci/%s/stampati';

    private const BOILERPLATE_MARKERS = [
        'Documento reso disponibile da AIFA',
        'Esula dalla competenza dell',
        'immissione in commercio (o titolare AIC)',
        'Agenzia Italiana del Farmaco',
        'essere ritenuta responsabile in alcun modo',
    ];

    private readonly Client $http;

    public function __construct(private readonly PDO $db, ?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    /**
     * Garantisce che il documento sia in cache (tabelle farmaci_documenti*).
     * Ritorna true se disponibile (già in cache o appena scaricato), false
     * se AIFA non ha il documento per questo farmaco (404 — esito normale,
     * non un errore).
     */
    public function assicuraDocumento(string $codFarmaco, string $codiceDitta, string $tipo): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM farmaci_documenti WHERE cod_farmaco = :cf AND codice_ditta = :cd AND tipo_documento = :t'
        );
        $stmt->execute(['cf' => $codFarmaco, 'cd' => $codiceDitta, 't' => $tipo]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }

        $farmacoInt = (string) (int) $codFarmaco;
        $url = sprintf(self::API_URL_TEMPLATE, $codiceDitta, $farmacoInt);

        try {
            $res = $this->http->get($url, ['query' => ['ts' => $tipo]]);
        } catch (RequestException $e) {
            if ($e->getResponse() !== null && $e->getResponse()->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }

        $bytes = (string) $res->getBody();
        $this->salvaDocumento($codFarmaco, $codiceDitta, $tipo, $url, $bytes);

        return true;
    }

    private function salvaDocumento(string $codFarmaco, string $codiceDitta, string $tipo, string $url, string $bytes): void
    {
        [$testoRaw, $numPagine] = $this->estraiTestoPdf($bytes);
        $testo = $this->rimuoviBoilerplate($this->normalizzaVirgolette($testoRaw));

        $dataDisponibilita = null;
        if (preg_match('/Documento reso disponibile da AIFA il (\d{2}\/\d{2}\/\d{4})/u', $testoRaw, $m) === 1) {
            $dataDisponibilita = $m[1];
        }

        $variantiTesto = $this->dividiInVarianti($testo);

        $this->db->beginTransaction();
        try {
            $nomeFile = sprintf('%s_%06d_%06d.pdf', $tipo, (int) $codiceDitta, (int) $codFarmaco);
            $stmt = $this->db->prepare(
                'INSERT INTO farmaci_documenti
                    (cod_farmaco, codice_ditta, tipo_documento, url_origine, nome_file,
                     data_disponibilita_aifa, num_pagine, num_varianti, testo_completo)
                 VALUES (:cf, :cd, :t, :url, :nome, :data, :pagine, :nvarianti, :testo)
                 ON DUPLICATE KEY UPDATE
                    url_origine = VALUES(url_origine), nome_file = VALUES(nome_file),
                    data_disponibilita_aifa = VALUES(data_disponibilita_aifa),
                    num_pagine = VALUES(num_pagine), num_varianti = VALUES(num_varianti),
                    testo_completo = VALUES(testo_completo), scaricato_il = CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                'cf' => $codFarmaco,
                'cd' => $codiceDitta,
                't' => $tipo,
                'url' => $url,
                'nome' => $nomeFile,
                'data' => $dataDisponibilita,
                'pagine' => $numPagine,
                'nvarianti' => count($variantiTesto),
                'testo' => $testo,
            ]);

            $stmt = $this->db->prepare(
                'SELECT id FROM farmaci_documenti WHERE cod_farmaco = :cf AND codice_ditta = :cd AND tipo_documento = :t'
            );
            $stmt->execute(['cf' => $codFarmaco, 'cd' => $codiceDitta, 't' => $tipo]);
            $documentoId = (int) $stmt->fetchColumn();

            $this->db->prepare('DELETE FROM farmaci_documenti_varianti WHERE documento_id = :id')
                ->execute(['id' => $documentoId]);

            foreach ($variantiTesto as $indice => $varianteTesto) {
                [$denominazione, $sezioni, $infoAmm] = $this->estraiSezioni($varianteTesto);

                $stmtVar = $this->db->prepare(
                    'INSERT INTO farmaci_documenti_varianti
                        (documento_id, indice_variante, denominazione_variante, informazioni_amministrative)
                     VALUES (:doc, :idx, :denom, :infoamm)'
                );
                $stmtVar->execute([
                    'doc' => $documentoId,
                    'idx' => $indice + 1,
                    'denom' => $denominazione,
                    'infoamm' => $infoAmm,
                ]);
                $varianteId = (int) $this->db->lastInsertId();

                $stmtSez = $this->db->prepare(
                    'INSERT INTO farmaci_documenti_sezioni
                        (variante_id, sezione_codice, sezione_titolo, contenuto, ordine)
                     VALUES (:v, :c, :t, :cont, :o)'
                );
                foreach ($sezioni as [$codice, $titolo, $contenuto, $ordine]) {
                    $stmtSez->execute([
                        'v' => $varianteId,
                        'c' => $codice,
                        't' => $titolo,
                        'cont' => $contenuto,
                        'o' => $ordine,
                    ]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0:string,1:int} */
    private function estraiTestoPdf(string $bytes): array
    {
        $parser = new PdfParser();
        $pdf = $parser->parseContent($bytes);

        return [$pdf->getText(), count($pdf->getPages())];
    }

    private function normalizzaVirgolette(string $testo): string
    {
        return str_replace(['’', '‘', '“', '”'], ["'", "'", '"', '"'], $testo);
    }

    private function rimuoviBoilerplate(string $testo): string
    {
        $righe = preg_split('/\R/u', $testo) ?: [];
        $filtrate = array_filter($righe, static function (string $riga): bool {
            foreach (self::BOILERPLATE_MARKERS as $marker) {
                if (str_contains($riga, $marker)) {
                    return false;
                }
            }

            return true;
        });

        return implode("\n", $filtrate);
    }

    /**
     * Un PDF AIFA puo' contenere piu' RCP/FI concatenati: li separa sui
     * punti in cui ricomincia la sezione 1 (denominazione).
     *
     * @return list<string>
     */
    private function dividiInVarianti(string $testoPulito): array
    {
        // NB: [ \t]* (non \s*) subito dopo ^: \s include anche \n, quindi un
        // "^\s*" a inizio pattern "risucchierebbe" all'indietro le righe
        // vuote precedenti nel match, spostando l'inizio prima della riga
        // interessata invece di ancorarlo ad essa.
        preg_match_all('/^[ \t]*1\.[ \t]+DENOMINAZIONE\b/mu', $testoPulito, $m, PREG_OFFSET_CAPTURE);
        $inizi = array_map(static fn (array $match): int => $match[1], $m[0]);
        if ($inizi === []) {
            return [$testoPulito];
        }

        $varianti = [];
        foreach ($inizi as $i => $start) {
            $end = $inizi[$i + 1] ?? strlen($testoPulito);
            $varianti[] = substr($testoPulito, $start, $end - $start);
        }

        return $varianti;
    }

    /**
     * Ritorna [denominazione, sezioni, informazioni_amministrative]. Le
     * sezioni 1-6 (e sottosezioni) sono affidabili nei documenti AIFA; dalla
     * 7 in poi (titolare, numeri AIC, date) il testo grezzo va in
     * informazioni_amministrative (impaginazione meno regolare — dato
     * comunque già pulito in ana_confezioni).
     *
     * @return array{0: ?string, 1: list<array{0:string,1:string,2:string,3:int}>, 2: string}
     */
    private function estraiSezioni(string $varianteTesto): array
    {
        $tops = $this->trovaSezioni('/^[ \t]*(\d{1,2})\.[ \t]+(\S.{2,100}?)[ \t]*$/mu', $varianteTesto);
        $subs = $this->trovaSezioni('/^[ \t]*(\d{1,2}\.\d{1,2})[ \t]+(?:\1[ \t]+)?(\S.{2,100}?)[ \t]*$/mu', $varianteTesto);

        $coreTops = array_values(array_filter(
            $tops,
            static fn (array $t): bool => ctype_digit($t['codice']) && (int) $t['codice'] >= 1 && (int) $t['codice'] <= 6
        ));
        $coreSubs = array_values(array_filter(
            $subs,
            static function (array $s): bool {
                $primo = explode('.', $s['codice'])[0];

                return ctype_digit($primo) && (int) $primo >= 1 && (int) $primo <= 6;
            }
        ));

        $marcatori = array_merge($coreTops, $coreSubs);
        usort($marcatori, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        if ($marcatori === []) {
            return [null, [], trim($varianteTesto)];
        }

        $denominazione = null;
        $sezioni = [];
        foreach ($marcatori as $i => $marcatore) {
            $fineContenuto = $marcatori[$i + 1]['start'] ?? null;
            $contenuto = $fineContenuto !== null
                ? trim(substr($varianteTesto, $marcatore['end'], $fineContenuto - $marcatore['end']))
                : '';
            $sezioni[] = [$marcatore['codice'], $marcatore['titolo'], $contenuto, $i];
            if ($marcatore['codice'] === '1' && $denominazione === null) {
                $denominazione = trim(explode("\n", $contenuto, 2)[0]);
            }
        }

        // confine tra l'ultima sezione core e le sezioni amministrative (7 in
        // poi): il primo top-level con codice > 6 dopo l'ultimo marcatore core.
        $ultimoMarcatore = $marcatori[count($marcatori) - 1];
        $ultimoPos = $ultimoMarcatore['start'];
        $successiviNonCore = array_values(array_filter(
            $tops,
            static fn (array $t): bool => $t['start'] > $ultimoPos && (!ctype_digit($t['codice']) || (int) $t['codice'] > 6)
        ));
        $confine = $successiviNonCore[0]['start'] ?? null;

        $fineUltima = $confine ?? strlen($varianteTesto);
        $ultimaSezione = array_pop($sezioni);
        $sezioni[] = [
            $ultimaSezione[0],
            $ultimaSezione[1],
            trim(substr($varianteTesto, $ultimoMarcatore['end'], $fineUltima - $ultimoMarcatore['end'])),
            $ultimaSezione[3],
        ];

        $infoAmm = $confine !== null ? trim(substr($varianteTesto, $confine)) : '';

        return [$denominazione, $sezioni, $infoAmm];
    }

    /** @return list<array{start:int,end:int,codice:string,titolo:string}> */
    private function trovaSezioni(string $pattern, string $testo): array
    {
        preg_match_all($pattern, $testo, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $risultato = [];
        foreach ($matches as $m) {
            $risultato[] = [
                'start' => $m[0][1],
                'end' => $m[0][1] + strlen($m[0][0]),
                'codice' => $m[1][0],
                'titolo' => trim($m[2][0]),
            ];
        }

        return $risultato;
    }
}
