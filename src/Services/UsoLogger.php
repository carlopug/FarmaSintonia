<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

use PDO;

/**
 * Logging interno: nessun dato personale né sensibile, solo utilizzo
 * aggregato dell'app — quali farmaci (dati AIFA pubblici, non dati del
 * paziente) vengono analizzati insieme e quanto spesso, ed errori della
 * sintesi LLM. Serve come indicatore per possibili sviluppi futuri, non per
 * il funzionamento dell'app né per identificare alcun utente (§4/§10:
 * l'elenco terapia resta comunque non persistito se non in questa forma
 * aggregata e anonima — nessun collegamento a IP, sessione o persona).
 */
final class UsoLogger
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<array{denominazione:string, codice_aic?: string}> $farmaci
     */
    public function registraAnalisi(array $farmaci, bool $llmEseguita, bool $llmDallaCache): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO log_analisi (numero_farmaci, llm_eseguita, llm_dalla_cache) VALUES (:n, :e, :c)'
        );
        $stmt->execute([
            'n' => count($farmaci),
            'e' => $llmEseguita ? 1 : 0,
            'c' => $llmDallaCache ? 1 : 0,
        ]);
        $analisiId = (int) $this->db->lastInsertId();

        $stmtFarmaco = $this->db->prepare(
            'INSERT INTO log_analisi_farmaci (analisi_id, codice_aic, denominazione) VALUES (:a, :aic, :denom)'
        );
        foreach ($farmaci as $farmaco) {
            $stmtFarmaco->execute([
                'a' => $analisiId,
                'aic' => (string) ($farmaco['codice_aic'] ?? ''),
                'denom' => (string) ($farmaco['denominazione'] ?? ''),
            ]);
        }
    }

    public function registraErroreLlm(string $codiceErrore, string $messaggio, ?string $modello, ?int $httpStatus): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO log_errori_llm (codice_errore, messaggio, modello, http_status) VALUES (:c, :m, :mo, :h)'
        );
        $stmt->execute([
            'c' => $codiceErrore,
            'm' => $messaggio,
            'mo' => $modello,
            'h' => $httpStatus,
        ]);
    }
}
