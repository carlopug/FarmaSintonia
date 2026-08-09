<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

use PDO;

/**
 * Autocompletamento farmaco: cerca in ana_confezioni per denominazione,
 * principio attivo o codice ATC. Dati pubblici già presenti in cache
 * locale (import bulk), nessuna chiamata esterna qui.
 *
 * `ana_confezioni.descrizione` è testo libero AIFA e contiene sia il
 * dosaggio/forma sia la quantità in confezione (es. "5 MG - COMPRESSE
 * RIVESTITE CON FILM - ... - 28 COMPRESSE"): a parità di farmaco e dosaggio
 * esistono quindi più righe che differiscono solo per la confezione (14/28/
 * 98 compresse, ecc.). Per l'autocompletamento non è utile mostrarle tutte:
 * si deduplica per (denominazione, dosaggio estratto), tenendo la prima
 * confezione trovata come rappresentante — cod_farmaco/codice_ditta/
 * principi attivi sono comunque identici tra le confezioni di uno stesso
 * dosaggio, quindi la scelta del rappresentante non influisce sull'analisi.
 */
final class FarmacoRicerca
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function cerca(string $termine, int $limite = 20): array
    {
        $termine = trim($termine);
        if (mb_strlen($termine, 'UTF-8') < 3) {
            return [];
        }

        // Ricerca "inizia per" (non "contiene"): permette a MySQL di usare
        // gli indici su denominazione/codice_atc/principio_attivo invece di
        // una scansione completa delle tabelle (160k+ righe) a ogni carattere
        // digitato. Le tre condizioni sono unite con UNION invece di OR:
        // un OR misto a una EXISTS correlata impedisce all'ottimizzatore di
        // usare qualunque indice (verificato con EXPLAIN: type=ALL anche con
        // gli indici disponibili), mentre ogni ramo di una UNION può usare il
        // proprio indice in modo indipendente.
        $like = $termine . '%';
        $stmt = $this->db->prepare(
            '(SELECT c.codice_aic, c.cod_farmaco, c.codice_ditta, c.denominazione,
                     c.descrizione, c.codice_atc
              FROM ana_confezioni c
              WHERE c.denominazione LIKE :t1)
             UNION
             (SELECT c.codice_aic, c.cod_farmaco, c.codice_ditta, c.denominazione,
                     c.descrizione, c.codice_atc
              FROM ana_confezioni c
              WHERE c.codice_atc LIKE :t2)
             UNION
             (SELECT c.codice_aic, c.cod_farmaco, c.codice_ditta, c.denominazione,
                     c.descrizione, c.codice_atc
              FROM ana_confezioni c
              JOIN ana_principi_attivi p2 ON p2.codice_aic = c.codice_aic
              WHERE p2.principio_attivo LIKE :t3)
             ORDER BY denominazione
             LIMIT :limite'
        );
        $stmt->bindValue(':t1', $like);
        $stmt->bindValue(':t2', $like);
        $stmt->bindValue(':t3', $like);
        $stmt->bindValue(':limite', $limite * 5, PDO::PARAM_INT);
        $stmt->execute();
        $righeGrezze = $stmt->fetchAll();

        // Principi attivi recuperati con una seconda query aggregata, solo
        // sui codici AIC effettivamente candidati: evita di ripetere una
        // subquery correlata per ogni riga scartata dal LIMIT/dedup a monte.
        $codiciAic = array_column($righeGrezze, 'codice_aic');
        $principiPerAic = [];
        if ($codiciAic !== []) {
            $segnaposto = implode(',', array_fill(0, count($codiciAic), '?'));
            $stmtPa = $this->db->prepare(
                "SELECT codice_aic, GROUP_CONCAT(DISTINCT principio_attivo SEPARATOR ', ') AS principi_attivi
                 FROM ana_principi_attivi
                 WHERE codice_aic IN ({$segnaposto})
                 GROUP BY codice_aic"
            );
            $stmtPa->execute($codiciAic);
            foreach ($stmtPa->fetchAll() as $riga) {
                $principiPerAic[(string) $riga['codice_aic']] = (string) $riga['principi_attivi'];
            }
        }

        $visti = [];
        $risultati = [];
        foreach ($righeGrezze as $row) {
            $descrizione = (string) ($row['descrizione'] ?? '');
            $dosaggio = self::estraiDosaggio($descrizione);
            $chiave = self::normalizza((string) $row['denominazione']) . '|'
                . self::normalizza($dosaggio ?? $descrizione);

            if (isset($visti[$chiave])) {
                continue;
            }
            $visti[$chiave] = true;

            $principiGrezzi = $principiPerAic[(string) $row['codice_aic']] ?? null;
            $principi = $principiGrezzi !== null
                ? array_map('trim', explode(',', $principiGrezzi))
                : [];

            $risultati[] = [
                'codice_aic' => $row['codice_aic'],
                'cod_farmaco' => $row['cod_farmaco'],
                'codice_ditta' => $row['codice_ditta'],
                'denominazione' => $row['denominazione'],
                'descrizione' => $descrizione,
                'dosaggio' => $dosaggio,
                'codice_atc' => $row['codice_atc'],
                'principi_attivi' => $principi,
            ];

            if (count($risultati) >= $limite) {
                break;
            }
        }

        return $risultati;
    }

    /**
     * Estrae il dosaggio (es. "5 MG", "120 mg/5 ml") dall'inizio di
     * ana_confezioni.descrizione, dove compare in modo pressoché costante
     * nei dati AIFA. Ritorna null se non riconosciuto, senza bloccare nulla
     * a valle (dedup e visualizzazione degradano semplicemente al testo
     * completo/nessun dettaglio).
     */
    private static function estraiDosaggio(string $descrizione): ?string
    {
        // Alcune descrizioni iniziano con un qualificatore ("BAMBINI 250 MG
        // SUPPOSTE...", "?NEONATI 62,5 MG..." — il "?" è un carattere non
        // riconosciuto residuo dell'import CSV) prima del numero: si scarta
        // ogni prefisso non numerico per arrivare al dosaggio vero e proprio.
        $senzaPrefisso = preg_replace('/^[^\d]*(?=\d)/u', '', $descrizione) ?? $descrizione;

        $pattern = '/^\s*([\d.,]+\s*(?:mg|mcg|µg|g|ml|%|u\.?i\.?)'
            . '(?:\s*\/\s*[\d.,]+\s*(?:mg|mcg|µg|g|ml))?)/iu';
        if (preg_match($pattern, $senzaPrefisso, $m) !== 1) {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', $m[1]) ?? $m[1]);
    }

    private static function normalizza(string $valore): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $valore) ?? $valore), 'UTF-8');
    }
}
