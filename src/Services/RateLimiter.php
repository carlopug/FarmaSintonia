<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

use PDO;

/**
 * Rate limiting minimo per IP sugli endpoint che chiamano l'LLM, per non
 * consumare budget OpenAI in modo incontrollato su un endpoint
 * pubblicamente raggiungibile. Finestra scorrevole semplice basata su una
 * tabella MySQL/MariaDB (portabile ovunque, a differenza di APCu la cui
 * presenza in php-fpm sulla VPS di destinazione non è garantita).
 *
 * Nota: lettura+scrittura non atomiche (due query separate) — accettabile
 * per il volume di richieste atteso, non pensato per concorrenza elevata
 * sullo stesso IP.
 */
final class RateLimiter
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $limite = 10,
        private readonly int $finestraSecondi = 3600,
    ) {
    }

    public function consentito(string $ip): bool
    {
        $ora = new \DateTimeImmutable();
        $stmt = $this->db->prepare('SELECT finestra_inizio, conteggio FROM rate_limit_llm WHERE ip_address = :ip');
        $stmt->execute(['ip' => $ip]);
        $riga = $stmt->fetch();

        if ($riga === false) {
            $this->db->prepare('INSERT INTO rate_limit_llm (ip_address, finestra_inizio, conteggio) VALUES (:ip, :ora, 1)')
                ->execute(['ip' => $ip, 'ora' => $ora->format('Y-m-d H:i:s')]);

            return true;
        }

        $inizioFinestra = new \DateTimeImmutable((string) $riga['finestra_inizio']);
        if (($ora->getTimestamp() - $inizioFinestra->getTimestamp()) > $this->finestraSecondi) {
            $this->db->prepare('UPDATE rate_limit_llm SET finestra_inizio = :ora, conteggio = 1 WHERE ip_address = :ip')
                ->execute(['ip' => $ip, 'ora' => $ora->format('Y-m-d H:i:s')]);

            return true;
        }

        if ((int) $riga['conteggio'] >= $this->limite) {
            return false;
        }

        $this->db->prepare('UPDATE rate_limit_llm SET conteggio = conteggio + 1 WHERE ip_address = :ip')
            ->execute(['ip' => $ip]);

        return true;
    }
}
