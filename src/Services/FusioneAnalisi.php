<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

/**
 * Fonde il riscontro deterministico (Compatibilita) con la sintesi LLM
 * (LlmSintesi) in un'unica lista di interazioni, LLM al centro: ogni voce
 * dell'LLM viene ancorata alle evidenze verificate algoritmicamente quando
 * la coppia di farmaci corrisponde a un riscontro deterministico; le
 * coppie trovate solo dal riscontro automatico (l'LLM non le ha riprese, o
 * l'LLM non è disponibile) restano comunque visibili, in coda, etichettate
 * come non ancora valutate dal modello — nessun riscontro va mai perso.
 */
final class FusioneAnalisi
{
    private const ORDINE_RISCHIO = [
        'controindicata' => 0,
        'maggiore' => 1,
        'moderata' => 2,
        'da_valutare' => 3,
        'minore' => 4,
        'non_determinabile' => 5,
    ];

    public function fondi(array $farmaci, array $interazioniDeterministiche, array $analisiLlm): array
    {
        $lookup = self::costruisciLookup($farmaci);
        $indiceCoppie = self::indicizzaCoppie($interazioniDeterministiche['potenziali_interazioni_esplicite'] ?? []);

        $eseguita = !empty($analisiLlm['eseguita']);
        $risultato = $eseguita ? ($analisiLlm['risultato'] ?? []) : [];

        $usate = [];
        $interazioni = [];

        if ($eseguita) {
            foreach ($risultato['interazioni'] ?? [] as $item) {
                $nomiRisolti = self::risolviNomi((array) ($item['farmaci_coinvolti'] ?? []), $lookup);
                $chiave = self::chiaveCoppia($nomiRisolti);
                $evidenzeDeterministiche = $chiave !== null ? ($indiceCoppie[$chiave] ?? null) : null;

                if ($evidenzeDeterministiche !== null) {
                    $usate[$chiave] = true;
                    $evidenze = array_map(
                        static fn (array $e): array => self::evidenzaVerificata($e),
                        $evidenzeDeterministiche
                    );
                    $origine = 'llm_e_riscontro_automatico';
                } else {
                    $evidenze = array_map(
                        static fn ($testo): array => self::evidenzaNonVerificata((string) $testo),
                        (array) ($item['evidenze_testuali'] ?? [])
                    );
                    $origine = 'solo_llm';
                }

                $interazioni[] = [
                    'farmaci_coinvolti' => $item['farmaci_coinvolti'] ?? [],
                    'principi_attivi_coinvolti' => $item['principi_attivi_coinvolti'] ?? [],
                    'tipo' => $item['tipo'] ?? 'non_determinabile',
                    'livello_rischio' => $item['livello_rischio'] ?? 'non_determinabile',
                    'sintesi' => (string) ($item['sintesi'] ?? ''),
                    'conseguenze_potenziali' => $item['conseguenze_potenziali'] ?? [],
                    'evidenze' => $evidenze,
                    'azione_prudenziale' => (string) ($item['azione_prudenziale'] ?? ''),
                    'origine' => $origine,
                ];
            }
        }

        // Coppie deterministiche non riprese dall'LLM (o tutte, se la sintesi
        // LLM non è disponibile): mai omesse, solo etichettate diversamente.
        foreach ($interazioniDeterministiche['potenziali_interazioni_esplicite'] ?? [] as $coppia) {
            $chiave = self::chiaveCoppia($coppia['farmaci'] ?? []);
            if ($chiave === null || isset($usate[$chiave])) {
                continue;
            }
            $peggiore = self::classificazionePeggiore($coppia['evidenze'] ?? []);
            $interazioni[] = [
                'farmaci_coinvolti' => $coppia['farmaci'] ?? [],
                'principi_attivi_coinvolti' => $coppia['principi_attivi'] ?? [],
                'tipo' => 'non_determinabile',
                'livello_rischio' => $peggiore === 'interazione_o_cautela' ? 'da_valutare' : 'non_determinabile',
                'sintesi' => 'Riscontro automatico nei documenti ufficiali AIFA: menzione diretta trovata, '
                    . 'non ancora valutata dal modello linguistico.',
                'conseguenze_potenziali' => [],
                'evidenze' => array_map(
                    static fn (array $e): array => self::evidenzaVerificata($e),
                    $coppia['evidenze'] ?? []
                ),
                'azione_prudenziale' => 'Da segnalare comunque al medico o al farmacista.',
                'origine' => 'solo_riscontro_automatico',
            ];
        }

        usort($interazioni, static function (array $a, array $b): int {
            $ra = self::ORDINE_RISCHIO[$a['livello_rischio']] ?? 5;
            $rb = self::ORDINE_RISCHIO[$b['livello_rischio']] ?? 5;

            return $ra !== $rb ? $ra <=> $rb : self::pesoOrigine($a['origine']) <=> self::pesoOrigine($b['origine']);
        });

        $motivoNonDisponibile = $eseguita ? null : (trim((string) ($analisiLlm['motivo'] ?? '')) ?: null);

        return [
            'sintesi_disponibile' => $eseguita,
            'motivo_sintesi_non_disponibile' => $motivoNonDisponibile,
            'riepilogo_terapia' => $eseguita ? ($risultato['riepilogo_terapia'] ?? null) : null,
            'interazioni' => $interazioni,
            'effetti_collaterali_aggregati' => $eseguita ? ($risultato['effetti_collaterali_aggregati'] ?? []) : [],
            'rischi_cumulativi' => $eseguita ? ($risultato['rischi_cumulativi'] ?? []) : [],
            'segnali_di_allarme' => $eseguita ? ($risultato['segnali_di_allarme_da_riferire_subito'] ?? []) : [],
            'domande_per_medico' => $eseguita ? ($risultato['domande_per_medico_o_farmacista'] ?? []) : [],
            'limitazioni' => $eseguita ? ($risultato['limitazioni'] ?? []) : [],
        ];
    }

    /**
     * @param list<array{denominazione:string,principi_attivi:list<string>}> $farmaci
     * @return array<string,string> testo normalizzato (denominazione o principio attivo) -> denominazione canonica
     */
    private static function costruisciLookup(array $farmaci): array
    {
        $lookup = [];
        foreach ($farmaci as $farmaco) {
            $denominazione = (string) $farmaco['denominazione'];
            $normDenominazione = Compatibilita::normalizzaTesto($denominazione);
            if ($normDenominazione !== '') {
                $lookup[$normDenominazione] = $denominazione;
            }
            foreach ($farmaco['principi_attivi'] ?? [] as $principioAttivo) {
                $norm = Compatibilita::normalizzaTesto((string) $principioAttivo);
                if ($norm !== '') {
                    $lookup[$norm] = $denominazione;
                }
            }
        }

        return $lookup;
    }

    /**
     * Risolve nomi liberi (come restituiti dall'LLM, non vincolati a un enum
     * nello schema JSON) alle denominazioni canoniche usate dal riscontro
     * deterministico, tramite corrispondenza esatta o di sottostringa sul
     * testo normalizzato.
     *
     * @param list<string> $nomiLiberi
     * @param array<string,string> $lookup
     * @return list<string>
     */
    private static function risolviNomi(array $nomiLiberi, array $lookup): array
    {
        $risolti = [];
        foreach ($nomiLiberi as $nomeLibero) {
            $norm = Compatibilita::normalizzaTesto((string) $nomeLibero);
            if ($norm === '') {
                continue;
            }
            $denominazione = $lookup[$norm] ?? null;
            if ($denominazione === null) {
                foreach ($lookup as $chiave => $valore) {
                    if (str_contains($norm, $chiave) || str_contains($chiave, $norm)) {
                        $denominazione = $valore;
                        break;
                    }
                }
            }
            if ($denominazione !== null && !in_array($denominazione, $risolti, true)) {
                $risolti[] = $denominazione;
            }
        }

        return $risolti;
    }

    /** @param list<string> $nomi */
    private static function chiaveCoppia(array $nomi): ?string
    {
        $unici = array_values(array_unique(array_filter(array_map(
            static fn ($n): string => Compatibilita::normalizzaTesto((string) $n),
            $nomi
        ))));
        if (count($unici) !== 2) {
            return null;
        }
        sort($unici);

        return implode('|', $unici);
    }

    /** @return array<string,list<array<string,mixed>>> chiave_coppia -> evidenze deterministiche */
    private static function indicizzaCoppie(array $potenziali): array
    {
        $indice = [];
        foreach ($potenziali as $coppia) {
            $chiave = self::chiaveCoppia($coppia['farmaci'] ?? []);
            if ($chiave !== null) {
                $indice[$chiave] = $coppia['evidenze'] ?? [];
            }
        }

        return $indice;
    }

    private static function classificazionePeggiore(array $evidenze): string
    {
        foreach ($evidenze as $evidenza) {
            if (($evidenza['classificazione_testuale'] ?? '') === 'interazione_o_cautela') {
                return 'interazione_o_cautela';
            }
        }

        return 'menzione_da_valutare';
    }

    private static function pesoOrigine(string $origine): int
    {
        return $origine === 'solo_riscontro_automatico' ? 1 : 0;
    }

    /** @return array{estratto:string,farmaco_fonte:?string,numero_sezione:?string,titolo:?string,verificata:bool} */
    private static function evidenzaVerificata(array $e): array
    {
        return [
            'estratto' => (string) ($e['estratto'] ?? ''),
            'farmaco_fonte' => $e['farmaco_fonte'] ?? null,
            'numero_sezione' => $e['numero_sezione'] ?? null,
            'titolo' => $e['titolo'] ?? null,
            'verificata' => true,
        ];
    }

    /** @return array{estratto:string,farmaco_fonte:?string,numero_sezione:?string,titolo:?string,verificata:bool} */
    private static function evidenzaNonVerificata(string $testo): array
    {
        return [
            'estratto' => $testo,
            'farmaco_fonte' => null,
            'numero_sezione' => null,
            'titolo' => null,
            'verificata' => false,
        ];
    }
}
