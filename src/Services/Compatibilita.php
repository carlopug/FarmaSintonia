<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

/**
 * Analisi deterministica di compatibilità, basata sulle sezioni RCP già
 * strutturate in farmaci_documenti_sezioni. La sintesi LLM è gestita
 * separatamente da LlmSintesi/LlmCache; qui l'esito viene solo riportato
 * nel rapporto finale.
 */
final class Compatibilita
{
    public const AVVERTENZA_MEDICA = 'Analisi informativa automatizzata basata esclusivamente sui documenti '
        . 'AIFA presenti nel file sorgente. Non costituisce diagnosi o prescrizione, '
        . 'non dimostra che una combinazione sia sicura e non deve essere usata per '
        . 'iniziare, sospendere o modificare una terapia senza consulto del medico o del farmacista.';

    /** Mappa sezione RCP (§3) -> categoria usata dall'analisi. */
    private const SEZIONE_CATEGORIA = [
        '4.3' => 'controindicazioni',
        '4.4' => 'avvertenze_e_precauzioni',
        '4.5' => 'interazioni_con_altri_farmaci',
        '4.8' => 'effetti_collaterali',
        '6.2' => 'incompatibilita_farmaceutiche',
    ];

    private const CATEGORIE_RICERCA = [
        'interazioni_con_altri_farmaci',
        'controindicazioni',
        'avvertenze_e_precauzioni',
        'incompatibilita_farmaceutiche',
    ];

    private const PATTERN_ASSENZA = [
        'nessuna interazione',
        'non sono state riportate interazioni',
        'non sono state osservate interazioni',
        'non interagisce',
        'assenza di interazioni',
    ];

    private const PATTERN_RISCHIO = [
        'controindicat', 'evitare', 'cautela', 'rischio', 'aumento', 'aumenta',
        'riduzione', 'riduce', 'potenzia', 'prolungamento', 'concomitante',
        'concomitanza', 'associazione', 'interazione',
    ];

    /**
     * Raggruppa le righe grezze di farmaci_documenti_sezioni (tutte le
     * varianti del documento RCP, aggregate) nelle categorie di analisi.
     *
     * @param list<array{sezione_codice:?string,sezione_titolo:?string,contenuto:?string}> $sezioniRcp
     * @return array<string,list<array{tipo_documento:string,numero_sezione:?string,titolo:?string,testo:string}>>
     */
    public static function categorizzaSezioni(array $sezioniRcp): array
    {
        $perCategoria = [];
        foreach ($sezioniRcp as $sezione) {
            $codice = trim((string) ($sezione['sezione_codice'] ?? ''));
            $categoria = self::SEZIONE_CATEGORIA[$codice] ?? null;
            if ($categoria === null) {
                continue;
            }
            $testo = trim((string) ($sezione['contenuto'] ?? ''));
            if ($testo === '') {
                continue;
            }
            $perCategoria[$categoria][] = [
                'tipo_documento' => 'RCP',
                'numero_sezione' => $codice,
                'titolo' => $sezione['sezione_titolo'] ?? null,
                'testo' => $testo,
            ];
        }

        return $perCategoria;
    }

    /**
     * @param list<array{denominazione:string,aic6:string,principi_attivi:list<string>,sezioni:array<string,list<array<string,mixed>>>}> $farmaci
     */
    public function costruisciRapporto(array $farmaci): array
    {
        return [
            'avvertenza_medica' => self::AVVERTENZA_MEDICA,
            'numero_farmaci' => count($farmaci),
            'farmaci_analizzati' => array_map(
                static fn (array $f): array => [
                    'denominazione_farmaco' => $f['denominazione'],
                    'aic6' => $f['aic6'],
                    'principi_attivi' => $f['principi_attivi'],
                ],
                $farmaci
            ),
            'fonti_documentali' => $this->fontiDocumentali($farmaci),
            'analisi_deterministica' => [
                'interazioni' => $this->interazioniDeterministiche($farmaci),
                'effetti_collaterali' => $this->effettiCollateraliDeterministici($farmaci),
            ],
            'analisi_llm' => [
                'richiesta' => false,
                'eseguita' => false,
                'modello' => null,
                'risultato' => null,
                'motivo' => 'non richiesta: la sintesi assistita da LLM non è ancora disponibile in questa versione',
                'errore' => null,
            ],
        ];
    }

    private function interazioniDeterministiche(array $farmaci): array
    {
        $potenziali = [];
        $neutre = [];
        $n = count($farmaci);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $evidenze = array_merge(
                    $this->evidenzeIncrociate($farmaci[$i], $farmaci[$j]),
                    $this->evidenzeIncrociate($farmaci[$j], $farmaci[$i])
                );
                if ($evidenze === []) {
                    continue;
                }

                $coppia = [
                    'farmaci' => [$farmaci[$i]['denominazione'], $farmaci[$j]['denominazione']],
                    'principi_attivi' => self::stringheUniche(
                        array_merge($farmaci[$i]['principi_attivi'], $farmaci[$j]['principi_attivi'])
                    ),
                    'evidenze' => $evidenze,
                ];
                $rischiose = array_values(array_filter(
                    $evidenze,
                    static fn (array $e): bool => $e['classificazione_testuale'] !== 'assenza_interazione_dichiarata'
                ));
                if ($rischiose !== []) {
                    $coppia['evidenze'] = $rischiose;
                    $coppia['stato'] = 'potenziale_interazione_da_valutare';
                    $potenziali[] = $coppia;
                } else {
                    $coppia['stato'] = 'solo_assenza_interazione_dichiarata';
                    $neutre[] = $coppia;
                }
            }
        }

        return [
            'potenziali_interazioni_esplicite' => $potenziali,
            'menzioni_di_assenza_interazione' => $neutre,
            'nota' => "Sono rilevate solo menzioni testuali dirette tra farmaci/principi presenti "
                . "nell'elenco. L'assenza di una coppia non prova compatibilità.",
        ];
    }

    /** @return list<array<string,mixed>> */
    private function evidenzeIncrociate(array $source, array $target): array
    {
        $terminiRicerca = [...$target['principi_attivi'], $target['denominazione']];
        $evidenze = [];
        $visti = [];
        foreach (self::CATEGORIE_RICERCA as $categoria) {
            foreach ($source['sezioni'][$categoria] ?? [] as $sezione) {
                $testo = (string) $sezione['testo'];
                foreach ($terminiRicerca as $termine) {
                    $indice = self::trovaTermine($testo, $termine);
                    if ($indice < 0) {
                        continue;
                    }
                    $estratto = self::estrattoEvidenza($testo, $indice);
                    $chiave = $categoria . '|' . self::normalizzaTesto($termine) . '|' . self::normalizzaTesto($estratto);
                    if (isset($visti[$chiave])) {
                        continue;
                    }
                    $visti[$chiave] = true;
                    $evidenze[] = [
                        'farmaco_fonte' => $source['denominazione'],
                        'aic6_fonte' => $source['aic6'],
                        'termine_cercato' => $termine,
                        'categoria' => $categoria,
                        'tipo_documento' => $sezione['tipo_documento'],
                        'numero_sezione' => $sezione['numero_sezione'],
                        'titolo' => $sezione['titolo'],
                        'classificazione_testuale' => self::classificaEvidenza($estratto),
                        'estratto' => $estratto,
                    ];
                }
            }
        }

        return $evidenze;
    }

    private function effettiCollateraliDeterministici(array $farmaci): array
    {
        $perFarmaco = [];
        $totaleSezioni = 0;
        foreach ($farmaci as $farmaco) {
            $viste = [];
            $sezioni = [];
            foreach ($farmaco['sezioni']['effetti_collaterali'] ?? [] as $sezione) {
                $testo = trim((string) $sezione['testo']);
                $norm = self::normalizzaTesto($testo);
                if ($testo === '' || isset($viste[$norm])) {
                    continue;
                }
                $viste[$norm] = true;
                $sezioni[] = [
                    'tipo_documento' => $sezione['tipo_documento'],
                    'numero_sezione' => $sezione['numero_sezione'],
                    'titolo' => $sezione['titolo'],
                    'testo' => $testo,
                ];
            }
            $totaleSezioni += count($sezioni);
            $perFarmaco[] = [
                'denominazione_farmaco' => $farmaco['denominazione'],
                'aic6' => $farmaco['aic6'],
                'principi_attivi' => $farmaco['principi_attivi'],
                'sezioni_effetti_collaterali' => $sezioni,
            ];
        }

        return [
            'per_farmaco' => $perFarmaco,
            'numero_sezioni_uniche' => $totaleSezioni,
            'nota' => 'I testi completi sono preservati per non omettere eventi rari o a frequenza non nota. '
                . "L'aggregazione tra farmaci con effetto in comune richiede la sintesi LLM (non ancora disponibile).",
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fontiDocumentali(array $farmaci): array
    {
        $categorie = [...self::CATEGORIE_RICERCA, 'effetti_collaterali'];
        $risultato = [];
        $viste = [];
        foreach ($farmaci as $farmaco) {
            foreach ($categorie as $categoria) {
                foreach ($farmaco['sezioni'][$categoria] ?? [] as $sezione) {
                    $chiave = implode('|', [
                        self::normalizzaTesto($farmaco['denominazione']),
                        $categoria,
                        (string) $sezione['numero_sezione'],
                        self::normalizzaTesto((string) $sezione['titolo']),
                    ]);
                    if (isset($viste[$chiave])) {
                        continue;
                    }
                    $viste[$chiave] = true;
                    $risultato[] = [
                        'farmaco_fonte' => $farmaco['denominazione'],
                        'aic6_fonte' => $farmaco['aic6'],
                        'categoria' => $categoria,
                        'tipo_documento' => $sezione['tipo_documento'],
                        'numero_sezione' => $sezione['numero_sezione'],
                        'titolo' => $sezione['titolo'],
                    ];
                }
            }
        }

        return $risultato;
    }

    /** @param list<string> $valori @return list<string> */
    private static function stringheUniche(array $valori): array
    {
        $risultato = [];
        $viste = [];
        foreach ($valori as $valore) {
            $pulito = trim((string) preg_replace('/[ \t]+/u', ' ', (string) $valore));
            $norm = self::normalizzaTesto($pulito);
            if ($pulito !== '' && !isset($viste[$norm])) {
                $viste[$norm] = true;
                $risultato[] = $pulito;
            }
        }

        return $risultato;
    }

    private static function terminUtilizzabile(string $termine): bool
    {
        $norm = self::normalizzaTesto($termine);

        return mb_strlen($norm, 'UTF-8') >= 4 && preg_match('/\p{L}/u', $norm) === 1;
    }

    /**
     * Cerca $termine (parola intera, senza distinguere accenti/maiuscole) in
     * $testo. Ritorna l'indice a CARATTERI (non byte) nel testo originale,
     * calcolato in modo sicuro per multibyte a partire dal match trovato nel
     * testo normalizzato — evita di troncare un carattere UTF-8 a metà in
     * estrattoEvidenza().
     */
    private static function trovaTermine(string $testo, string $termine): int
    {
        if (!self::terminUtilizzabile($termine)) {
            return -1;
        }
        $testoNorm = self::normalizzaTesto($testo);
        $termineNorm = self::normalizzaTesto($termine);
        $pattern = '/(?<!\w)' . preg_quote($termineNorm, '/') . '(?!\w)/u';
        if (preg_match($pattern, $testoNorm, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return -1;
        }

        return mb_strlen(substr($testoNorm, 0, $m[0][1]), 'UTF-8');
    }

    private static function estrattoEvidenza(string $testoOriginale, int $indiceApproxCaratteri, int $raggio = 260): string
    {
        if ($indiceApproxCaratteri < 0) {
            return trim(mb_substr($testoOriginale, 0, $raggio * 2, 'UTF-8'));
        }
        $lunghezza = mb_strlen($testoOriginale, 'UTF-8');
        $inizio = max(0, $indiceApproxCaratteri - $raggio);
        $fine = min($lunghezza, $indiceApproxCaratteri + $raggio);
        $estratto = trim(mb_substr($testoOriginale, $inizio, $fine - $inizio, 'UTF-8'));
        if ($inizio > 0) {
            $estratto = '...' . $estratto;
        }
        if ($fine < $lunghezza) {
            $estratto .= '...';
        }

        return $estratto;
    }

    private static function classificaEvidenza(string $estratto): string
    {
        $norm = self::normalizzaTesto($estratto);
        foreach (self::PATTERN_ASSENZA as $pattern) {
            if (str_contains($norm, $pattern)) {
                return 'assenza_interazione_dichiarata';
            }
        }
        foreach (self::PATTERN_RISCHIO as $pattern) {
            if (str_contains($norm, $pattern)) {
                return 'interazione_o_cautela';
            }
        }

        return 'menzione_da_valutare';
    }

    /**
     * Rimozione diacritici + minuscolo + spazi collassati. Usa iconv//TRANSLIT
     * invece di Normalizer (ext-intl): quest'ultima non è garantita disponibile
     * in ogni ambiente PHP, mentre iconv/mbstring sono una dipendenza più
     * portabile, già richiesta altrove nel progetto.
     */
    public static function normalizzaTesto(string $valore): string
    {
        $traslitterato = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valore);
        $senzaAccenti = $traslitterato !== false ? $traslitterato : $valore;
        $collassato = preg_replace('/\s+/u', ' ', mb_strtolower($senzaAccenti, 'UTF-8')) ?? '';

        return trim($collassato);
    }
}
