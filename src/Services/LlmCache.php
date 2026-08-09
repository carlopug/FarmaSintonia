<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

use PDO;

/**
 * Cache dei risultati di sintesi LLM (tabella llm_sintesi_cache), chiave su
 * LlmSintesi::chiaveCache() — evita di richiamare l'API OpenAI (lenta, a
 * pagamento) per una combinazione di farmaci già analizzata con lo stesso
 * modello e lo stesso contenuto RCP.
 */
final class LlmCache
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function leggi(string $chiave): ?array
    {
        $stmt = $this->db->prepare('SELECT risultato FROM llm_sintesi_cache WHERE chiave = :k');
        $stmt->execute(['k' => $chiave]);
        $riga = $stmt->fetch();
        if ($riga === false) {
            return null;
        }

        $decodificato = json_decode((string) $riga['risultato'], true);

        return is_array($decodificato) ? $decodificato : null;
    }

    public function scrivi(string $chiave, string $modello, array $risultato): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO llm_sintesi_cache (chiave, modello, risultato)
             VALUES (:k, :m, :r)
             ON DUPLICATE KEY UPDATE modello = VALUES(modello), risultato = VALUES(risultato), creato_il = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'k' => $chiave,
            'm' => $modello,
            'r' => json_encode($risultato, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
