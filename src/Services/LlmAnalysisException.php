<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

/**
 * Errore LLM previsto e serializzabile nel rapporto finale: non deve mai
 * far fallire l'intera richiesta, solo il campo analisi_llm del rapporto.
 */
final class LlmAnalysisException extends \RuntimeException
{
    public function __construct(
        public readonly string $codice,
        string $messaggio,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($messaggio);
    }

    public function toArray(): array
    {
        return [
            'codice' => $this->codice,
            'messaggio' => $this->getMessage(),
            'http_status' => $this->httpStatus,
        ];
    }
}
