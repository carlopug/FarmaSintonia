<?php

declare(strict_types=1);

namespace FarmaSintonia\Services;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Generazione del report PDF (mPDF, HTML+CSS). Le parti neutre (titoli,
 * testo, tabelle) usano la palette di brand; i callout semantici
 * (info/warning/danger) restano sui colori convenzionali, non di brand.
 *
 * Nessuna attribuzione euristica "effetto → farmaco" dal solo testo:
 * "farmaci_associati" è un campo obbligatorio nello schema JSON della
 * risposta LLM (vedi LlmSintesi::jsonSchema), quindi è sempre presente.
 *
 * Nessuna scrittura su disco: il PDF è generato in memoria e restituito
 * come stringa di byte — la risposta HTTP lo invia direttamente in
 * download, coerente con l'architettura stateless dell'app.
 */
final class ReportPdf
{
    private const NAVY = '#1A365D';
    private const TEAL = '#0D9488';
    private const GRAY = '#374151';
    private const GRAY_MUTED = '#6B7280';
    private const WHITE = '#FFFFFF';

    private const PALE_BLUE = '#EAF4F8';
    private const BLUE_ACCENT = '#176B87';
    private const PALE_TEAL = '#E8F5F2';
    private const PALE_AMBER = '#FFF4D6';
    private const AMBER_ACCENT = '#946200';
    private const PALE_RED = '#FDECEA';
    private const RED_ACCENT = '#A61B1B';

    public function genera(array $rapporto): string
    {
        $disclaimer = trim((string) ($rapporto['avvertenza_medica'] ?? ''));

        $mpdf = new Mpdf([
            'format' => 'A4',
            'margin_left' => 16,
            'margin_right' => 16,
            'margin_top' => 20,
            'margin_bottom' => 28,
            'margin_header' => 8,
            'margin_footer' => 8,
            'default_font_size' => 9.5,
        ]);

        $mpdf->SetTitle('Analisi di compatibilità farmacologica — FarmaSintonia');
        $mpdf->SetAuthor('FarmaSintonia — analisi automatizzata su documenti AIFA RCP');

        $mpdf->SetHTMLHeader($this->headerHtml());
        $mpdf->SetHTMLFooter($this->footerHtml($disclaimer));

        $mpdf->WriteHTML($this->stileHtml());
        $mpdf->WriteHTML($this->corpoHtml($rapporto));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function headerHtml(): string
    {
        return '<div style="font-family:sans-serif;font-size:7pt;font-weight:bold;color:' . self::NAVY . ';'
            . 'border-bottom:0.5pt solid #B8D2DE;padding-bottom:2pt;">FARMASINTONIA — REPORT DI COMPATIBILITÀ</div>';
    }

    private function footerHtml(string $disclaimer): string
    {
        return '<div style="background:' . self::PALE_AMBER . ';border:0.5pt solid #D99A00;border-radius:2pt;'
            . 'padding:3pt 6pt;font-size:6.6pt;color:' . self::RED_ACCENT . ';text-align:center;font-family:sans-serif;">'
            . '<strong>AVVERTENZA MEDICA</strong><br>' . nl2br($this->escape($disclaimer))
            . '</div>'
            . '<div style="text-align:right;font-size:6.5pt;color:' . self::GRAY_MUTED . ';font-family:sans-serif;margin-top:1pt;">'
            . 'Pagina {PAGENO} di {nbpg}</div>';
    }

    private function stileHtml(): string
    {
        return '<style>
            body { font-family: sans-serif; color: ' . self::GRAY . '; }
            h1 { color: ' . self::TEAL . '; font-size: 15pt; margin: 8pt 0 5pt; }
            h2 { color: ' . self::NAVY . '; font-size: 11.5pt; margin: 7pt 0 4pt; }
            h3 { color: ' . self::TEAL . '; font-size: 9.5pt; margin: 5pt 0 2pt; }
            .fs-title { color: ' . self::NAVY . '; font-size: 21pt; font-weight: bold; margin-bottom: 3pt; }
            .fs-subtitle { color: ' . self::GRAY_MUTED . '; font-size: 9.5pt; margin-bottom: 9pt; }
            table.fs-table { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
            table.fs-summary th { background: ' . self::NAVY . '; color: ' . self::WHITE . '; padding: 4pt; font-size: 7.5pt; text-transform: uppercase; }
            table.fs-summary td { background: ' . self::PALE_BLUE . '; text-align: center; padding: 6pt; border: 0.5pt solid #A9C8D4; font-size: 10pt; }
            table.fs-medicines th { background: ' . self::TEAL . '; color: ' . self::WHITE . '; padding: 4pt; font-size: 7.5pt; text-align: left; }
            table.fs-medicines td { border: 0.35pt solid #AFC8C4; padding: 4pt; font-size: 8.5pt; vertical-align: top; }
            table.fs-medicines tr:nth-child(even) td { background: ' . self::PALE_TEAL . '; }
            .fs-callout { border-left: 3pt solid; padding: 5pt 7pt; margin-bottom: 6pt; font-size: 8.5pt; }
            .fs-callout-title { font-weight: bold; margin-bottom: 2pt; }
            .fs-callout-info { background: ' . self::PALE_BLUE . '; border-color: ' . self::BLUE_ACCENT . '; }
            .fs-callout-warning { background: ' . self::PALE_AMBER . '; border-color: ' . self::AMBER_ACCENT . '; }
            .fs-callout-danger { background: ' . self::PALE_RED . '; border-color: ' . self::RED_ACCENT . '; }
            .fs-evidence { background: #F3F5F7; border-left: 2pt solid #9CA3AF; padding: 4pt 6pt; margin: 3pt 0 5pt; font-size: 8.2pt; }
            ul.fs-list { margin: 2pt 0 6pt 14pt; padding: 0; font-size: 8.5pt; }
            .fs-pagebreak { page-break-before: always; }
        </style>';
    }

    private function corpoHtml(array $rapporto): string
    {
        $interazioni = $rapporto['analisi_deterministica']['interazioni'] ?? [];
        $llm = $rapporto['analisi_llm'] ?? [];

        $html = '<div class="fs-title">Analisi di compatibilità farmacologica</div>';
        $html .= '<div class="fs-subtitle">Interazioni potenziali, principi attivi ed effetti indesiderati ricavati '
            . 'dai riassunti delle caratteristiche del prodotto (RCP) ufficiali AIFA.</div>';

        $html .= $this->tabellaRiepilogo($rapporto);
        $html .= $this->callout(
            'Come leggere il rapporto',
            'Le segnalazioni indicano corrispondenze nei documenti RCP e devono essere valutate da un '
            . 'professionista. La sintesi organizza interazioni, possibili conseguenze, rischi cumulativi '
            . 'ed effetti indesiderati aggregati.',
            'info'
        );

        $html .= '<h1>Farmaci analizzati</h1>';
        $html .= $this->tabellaFarmaci($rapporto['farmaci_analizzati'] ?? []);

        $html .= '<div class="fs-pagebreak"></div><h1>Interazioni e incompatibilità potenziali</h1>';
        $html .= $this->callout(
            'Interpretazione prudente',
            (string) ($interazioni['nota'] ?? "L'assenza di una segnalazione non dimostra compatibilità."),
            'warning'
        );
        $html .= $this->sezioneInterazioni($interazioni);

        $html .= $this->sezioneLlm($llm);

        return $html;
    }

    private function tabellaRiepilogo(array $rapporto): string
    {
        $numeroFarmaci = (int) ($rapporto['numero_farmaci'] ?? 0);
        $numeroInterazioni = count($rapporto['analisi_deterministica']['interazioni']['potenziali_interazioni_esplicite'] ?? []);

        return '<table class="fs-table fs-summary"><tr><th>Farmaci</th><th>Interazioni testuali</th></tr>'
            . '<tr><td>' . $numeroFarmaci . '</td><td>' . $numeroInterazioni . '</td></tr>'
            . '</table>';
    }

    private function tabellaFarmaci(array $farmaci): string
    {
        $righe = '';
        foreach ($farmaci as $f) {
            $righe .= '<tr><td>' . $this->escape((string) ($f['denominazione_farmaco'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($f['aic6'] ?? '')) . '</td>'
                . '<td>' . $this->escape(implode(', ', $f['principi_attivi'] ?? [])) . '</td></tr>';
        }

        return '<table class="fs-table fs-medicines"><tr><th>Medicinale</th><th>AIC6</th><th>Principio/i attivo/i</th></tr>'
            . $righe . '</table>';
    }

    private function sezioneInterazioni(array $interazioni): string
    {
        $html = '';
        $potenziali = $interazioni['potenziali_interazioni_esplicite'] ?? [];
        if ($potenziali === []) {
            $html .= '<p>Nessuna menzione incrociata diretta rilevata.</p>';
        } else {
            foreach ($potenziali as $indice => $coppia) {
                $titolo = implode(' + ', $coppia['farmaci'] ?? []);
                $html .= '<h2>' . ($indice + 1) . '. ' . $this->escape($titolo) . '</h2>';
                $html .= '<p><strong>Principi attivi:</strong> ' . $this->escape(implode(', ', $coppia['principi_attivi'] ?? [])) . '</p>';
                $html .= $this->callout('Stato', 'Potenziale interazione documentale da sottoporre a medico o farmacista.', 'danger');
                foreach ($coppia['evidenze'] ?? [] as $indiceEvidenza => $evidenza) {
                    $html .= '<h3>Evidenza ' . ($indiceEvidenza + 1) . '</h3>';
                    $html .= '<div class="fs-evidence">' . nl2br($this->escape((string) ($evidenza['estratto'] ?? ''))) . '</div>';
                }
            }
        }

        $neutre = $interazioni['menzioni_di_assenza_interazione'] ?? [];
        if ($neutre !== []) {
            $html .= '<h2>Menzioni di assenza di interazione</h2>';
            foreach ($neutre as $coppia) {
                $html .= '<h3>' . $this->escape(implode(' + ', $coppia['farmaci'] ?? [])) . '</h3>';
            }
        }

        return $html;
    }

    private function sezioneLlm(array $llm): string
    {
        // Se la sintesi LLM non è stata eseguita, il fallimento va segnalato
        // (mai un errore silenzioso), con l'invito a rigenerare il report
        // più tardi: un PDF non può offrire un pulsante "riprova".
        $html = '<div class="fs-pagebreak"></div><h1>Sintesi assistita da modello linguistico</h1>';

        if (empty($llm['eseguita'])) {
            $motivo = trim((string) ($llm['motivo'] ?? '')) ?: 'si è verificato un problema imprevisto';

            return $html . $this->callout(
                'Sintesi temporaneamente non disponibile',
                $motivo . '. Il resoconto sopra riportato, con le evidenze testuali citate, resta comunque '
                . 'valido. Genera nuovamente il report più tardi per includere la sintesi.',
                'warning'
            );
        }

        $r = $llm['risultato'] ?? [];

        if (!empty($r['riepilogo_terapia'])) {
            $html .= '<h2>Quadro generale</h2><p>' . nl2br($this->escape((string) $r['riepilogo_terapia'])) . '</p>';
        }

        $html .= '<h2>Interazioni valutate dal modello</h2>';
        $interazioniLlm = $r['interazioni'] ?? [];
        if ($interazioniLlm === []) {
            $html .= '<p>Nessuna interazione strutturata restituita.</p>';
        }
        foreach ($interazioniLlm as $item) {
            $titolo = implode(' / ', $item['farmaci_coinvolti'] ?? []) ?: 'Interazione';
            $html .= '<h3>' . $this->escape($titolo) . '</h3>';
            $html .= '<p>' . nl2br($this->escape((string) ($item['sintesi'] ?? ''))) . '</p>';
            $html .= $this->callout(
                'Classificazione',
                'Tipo: ' . (string) ($item['tipo'] ?? 'non determinabile')
                . ' — Livello: ' . (string) ($item['livello_rischio'] ?? 'non determinabile'),
                'warning'
            );
            $html .= $this->elencoPuntato($item['conseguenze_potenziali'] ?? []);
            $evidenze = $item['evidenze_testuali'] ?? [];
            if ($evidenze !== []) {
                $html .= '<h3>Evidenze testuali</h3>' . $this->elencoPuntato($evidenze);
            }
            if (!empty($item['azione_prudenziale'])) {
                $html .= $this->callout('Indicazione prudenziale', (string) $item['azione_prudenziale'], 'info');
            }
        }

        $html .= '<h2>Effetti indesiderati aggregati</h2>';
        foreach ($r['effetti_collaterali_aggregati'] ?? [] as $gruppo) {
            $html .= '<h3>' . $this->escape((string) ($gruppo['categoria'] ?? 'Categoria')) . '</h3><ul class="fs-list">';
            foreach ($gruppo['effetti'] ?? [] as $effetto) {
                $farmaciAssociati = $effetto['farmaci_associati'] ?? [];
                $etichetta = count($farmaciAssociati) === 1 ? 'Farmaco' : 'Farmaci';
                $causa = $farmaciAssociati !== []
                    ? $etichetta . ': ' . implode(', ', $farmaciAssociati)
                    : 'Farmaco: attribuzione non determinabile dai dati disponibili';
                $html .= '<li><strong>' . $this->escape((string) ($effetto['effetto'] ?? '')) . '</strong> — '
                    . '<span style="color:' . self::TEAL . ';">' . $this->escape($causa) . '</span></li>';
            }
            $html .= '</ul>';
            if (!empty($gruppo['possibile_sovrapposizione'])) {
                $html .= '<p><strong>Possibile sovrapposizione:</strong> ' . $this->escape((string) $gruppo['possibile_sovrapposizione']) . '</p>';
            }
        }

        if (!empty($r['rischi_cumulativi'])) {
            $html .= '<h2>Rischi cumulativi descritti</h2>' . $this->calloutElenco('', $r['rischi_cumulativi'], 'warning');
        }
        if (!empty($r['segnali_di_allarme_da_riferire_subito'])) {
            $html .= '<h2>Segnali di allarme da riferire subito</h2>'
                . $this->calloutElenco('', $r['segnali_di_allarme_da_riferire_subito'], 'danger');
        }
        if (!empty($r['domande_per_medico_o_farmacista'])) {
            $html .= '<h2>Domande per medico o farmacista</h2>' . $this->elencoPuntato($r['domande_per_medico_o_farmacista']);
        }

        return $html;
    }

    private function elencoPuntato(array $valori): string
    {
        $items = '';
        foreach ($valori as $valore) {
            $valore = trim((string) $valore);
            if ($valore === '') {
                continue;
            }
            $items .= '<li>' . $this->escape($valore) . '</li>';
        }

        return $items === '' ? '' : '<ul class="fs-list">' . $items . '</ul>';
    }

    private function callout(string $titolo, string $testo, string $tipo): string
    {
        $titoloHtml = $titolo !== '' ? '<div class="fs-callout-title">' . $this->escape($titolo) . '</div>' : '';

        return '<div class="fs-callout fs-callout-' . $tipo . '">' . $titoloHtml . nl2br($this->escape($testo)) . '</div>';
    }

    /** @param list<string> $valori */
    private function calloutElenco(string $titolo, array $valori, string $tipo): string
    {
        $testo = implode("\n", array_map(static fn ($v): string => '- ' . trim((string) $v), $valori));

        return $this->callout($titolo, $testo, $tipo);
    }

    private function escape(string $valore): string
    {
        return htmlspecialchars($valore, ENT_QUOTES, 'UTF-8');
    }
}
