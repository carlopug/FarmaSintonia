(function () {
  'use strict';

  var BASE_PATH = window.FS_BASE_PATH || '';
  var SESSION_KEY = 'farmasintonia:terapia';

  var elVuoto = document.getElementById('risultati-vuoto');
  var elLoading = document.getElementById('risultati-loading');
  var elErrore = document.getElementById('risultati-errore');
  var elContenuto = document.getElementById('risultati-contenuto');
  var elAvvisi = document.getElementById('risultati-avvisi');

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function leggiElenco() {
    try {
      var raw = sessionStorage.getItem(SESSION_KEY);
      var lista = raw ? JSON.parse(raw) : [];
      return Array.isArray(lista) ? lista : [];
    } catch (e) {
      return [];
    }
  }

  function mostraErrore(messaggio) {
    elLoading.hidden = true;
    elErrore.hidden = false;
    elErrore.textContent = messaggio;
  }

  function renderAvvisi(avvisi) {
    if (!avvisi || avvisi.length === 0) {
      elAvvisi.innerHTML = '';
      return;
    }
    elAvvisi.innerHTML = '<div class="alert alert-warning mb-4"><strong>Nota.</strong><ul class="mb-0">'
      + avvisi.map(function (a) { return '<li>' + escapeHtml(a) + '</li>'; }).join('')
      + '</ul></div>';
  }

  function renderFarmaciTabella(farmaci) {
    document.getElementById('tabella-farmaci').innerHTML = farmaci.map(function (f) {
      return '<tr><td>' + escapeHtml(f.denominazione_farmaco) + '</td><td>' + escapeHtml(f.aic6) + '</td>'
        + '<td>' + escapeHtml((f.principi_attivi || []).join(', ')) + '</td></tr>';
    }).join('');
  }

  function badgeClassificazione(classificazione) {
    var etichette = {
      interazione_o_cautela: ['Da valutare con attenzione', 'bg-danger'],
      menzione_da_valutare: ['Da valutare', 'bg-secondary'],
      assenza_interazione_dichiarata: ['Assenza dichiarata nel testo', 'bg-success'],
    };
    var coppia = etichette[classificazione] || ['Da valutare', 'bg-secondary'];
    return '<span class="badge ' + coppia[1] + '">' + coppia[0] + '</span>';
  }

  function renderInterazioni(interazioni) {
    document.getElementById('interazioni-nota').textContent = interazioni.nota || '';

    var potenziali = interazioni.potenziali_interazioni_esplicite || [];
    var container = document.getElementById('interazioni-container');

    if (potenziali.length === 0) {
      container.innerHTML = '<p class="text-muted">Nessuna menzione incrociata trovata tra i farmaci di questo elenco '
        + 'nei testi RCP disponibili. Questo non dimostra che la combinazione sia sicura: parlane comunque con il medico o il farmacista.</p>';
    } else {
      container.innerHTML = potenziali.map(function (coppia) {
        var evidenze = (coppia.evidenze || []).map(function (e) {
          return '<blockquote class="fs-evidence">'
            + '"' + escapeHtml(e.estratto) + '"'
            + '<footer class="blockquote-footer">Fonte: RCP ' + escapeHtml(e.farmaco_fonte)
            + (e.numero_sezione ? ', sez. ' + escapeHtml(e.numero_sezione) : '')
            + (e.titolo ? ' ' + escapeHtml(e.titolo) : '') + '</footer>'
            + '</blockquote>';
        }).join('');

        var peggiore = coppia.evidenze.some(function (e) { return e.classificazione_testuale === 'interazione_o_cautela'; })
          ? 'interazione_o_cautela'
          : 'menzione_da_valutare';

        return '<div class="card fs-pair-card"><div class="card-body">'
          + '<div class="d-flex justify-content-between align-items-start gap-2">'
          + '<h3 class="h6 mb-2">' + escapeHtml(coppia.farmaci.join(' × ')) + ' — ' + escapeHtml(coppia.principi_attivi.join(', ')) + '</h3>'
          + badgeClassificazione(peggiore)
          + '</div>' + evidenze + '</div></div>';
      }).join('');
    }

    var neutre = interazioni.menzioni_di_assenza_interazione || [];
    if (neutre.length > 0) {
      container.innerHTML += '<div class="mt-2"><p class="text-muted small mb-1">'
        + 'Coppie con menzione esplicita di assenza di interazione nel testo:</p><ul class="small text-muted">'
        + neutre.map(function (c) { return '<li>' + escapeHtml(c.farmaci.join(' e ')) + '</li>'; }).join('')
        + '</ul></div>';
    }
  }

  function renderEffettiCollaterali(effetti) {
    var perFarmaco = effetti.per_farmaco || [];
    document.getElementById('effetti-container').innerHTML = perFarmaco.map(function (farmaco, indice) {
      var sezioni = farmaco.sezioni_effetti_collaterali || [];
      var corpo = sezioni.length === 0
        ? '<p class="text-muted small mb-0">Nessuna sezione effetti collaterali trovata nel RCP di questo farmaco.</p>'
        : sezioni.map(function (s) {
          return '<p><strong>' + (s.titolo ? escapeHtml(s.titolo) : 'Sez. ' + escapeHtml(s.numero_sezione)) + '</strong><br>'
            + escapeHtml(s.testo) + '</p>';
        }).join('');

      var id = 'effetti-' + indice;
      return '<div class="accordion-item">'
        + '<h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#' + id + '">'
        + escapeHtml(farmaco.denominazione_farmaco) + '</button></h3>'
        + '<div id="' + id + '" class="accordion-collapse collapse"><div class="accordion-body">' + corpo + '</div></div>'
        + '</div>';
    }).join('');
  }

  function livelloRischioBadge(livello) {
    var mappa = {
      controindicata: 'bg-danger',
      maggiore: 'bg-danger',
      moderata: 'bg-warning text-dark',
      minore: 'bg-secondary',
      non_determinabile: 'bg-secondary',
    };
    return '<span class="badge ' + (mappa[livello] || 'bg-secondary') + '">' + escapeHtml(livello || 'non_determinabile') + '</span>';
  }

  function renderSintesiLlm(analisiLlm) {
    var container = document.getElementById('sintesi-llm-container');

    // Se la sintesi LLM non è stata eseguita, va segnalato (mai un errore
    // silenzioso): qui l'utente ha anche un pulsante per ritentare.
    if (!analisiLlm.eseguita) {
      container.innerHTML = '<h2 class="h5">Sintesi assistita da un modello linguistico</h2>'
        + '<div class="alert alert-warning">'
        + '<strong>Sintesi temporaneamente non disponibile.</strong> '
        + escapeHtml(analisiLlm.motivo || 'si è verificato un problema imprevisto') + '. '
        + 'Il resoconto qui sopra, con le evidenze testuali citate, resta comunque valido.'
        + '</div>'
        + '<button type="button" id="btn-riprova-llm" class="btn btn-outline-secondary btn-sm">Riprova più tardi</button>';
      return;
    }

    var r = analisiLlm.risultato;
    var html = '<h2 class="h5">Sintesi assistita da un modello linguistico</h2>';

    html += '<div class="mb-3"><h3 class="h6">Quadro generale</h3><p>' + escapeHtml(r.riepilogo_terapia) + '</p></div>';

    if ((r.interazioni || []).length > 0) {
      html += '<div class="mb-3"><h3 class="h6">Interazioni valutate dal modello</h3>'
        + r.interazioni.map(function (i) {
          return '<div class="card fs-pair-card mb-2"><div class="card-body">'
            + '<div class="d-flex justify-content-between align-items-start gap-2">'
            + '<h4 class="h6 mb-1">' + escapeHtml((i.farmaci_coinvolti || []).join(' × ')) + '</h4>'
            + livelloRischioBadge(i.livello_rischio)
            + '</div>'
            + '<p class="small text-muted mb-1">Tipo: ' + escapeHtml(i.tipo) + '</p>'
            + '<p>' + escapeHtml(i.sintesi) + '</p>'
            + (i.conseguenze_potenziali && i.conseguenze_potenziali.length
              ? '<p class="small mb-1"><strong>Conseguenze potenziali:</strong> ' + escapeHtml(i.conseguenze_potenziali.join('; ')) + '</p>' : '')
            + (i.evidenze_testuali && i.evidenze_testuali.length
              ? i.evidenze_testuali.map(function (e) { return '<blockquote class="fs-evidence">"' + escapeHtml(e) + '"</blockquote>'; }).join('') : '')
            + '<p class="small mb-0"><strong>Azione prudenziale:</strong> ' + escapeHtml(i.azione_prudenziale) + '</p>'
            + '</div></div>';
        }).join('') + '</div>';
    }

    if ((r.effetti_collaterali_aggregati || []).length > 0) {
      html += '<div class="mb-3"><h3 class="h6">Effetti indesiderati aggregati</h3>'
        + r.effetti_collaterali_aggregati.map(function (g) {
          return '<div class="mb-2"><p class="mb-1"><strong>' + escapeHtml(g.categoria) + '</strong></p><ul class="mb-1">'
            + (g.effetti || []).map(function (e) {
              return '<li>' + escapeHtml(e.effetto) + ' <span class="text-muted small">(' + escapeHtml((e.farmaci_associati || []).join(', ')) + ')</span></li>';
            }).join('') + '</ul>'
            + (g.possibile_sovrapposizione ? '<p class="small text-muted mb-0">' + escapeHtml(g.possibile_sovrapposizione) + '</p>' : '')
            + '</div>';
        }).join('') + '</div>';
    }

    if ((r.rischi_cumulativi || []).length > 0) {
      html += '<div class="alert alert-warning"><strong>Rischi cumulativi descritti.</strong><ul class="mb-0">'
        + r.rischi_cumulativi.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul></div>';
    }

    if ((r.segnali_di_allarme_da_riferire_subito || []).length > 0) {
      html += '<div class="alert alert-danger"><strong>Segnali di allarme da riferire subito.</strong><ul class="mb-0">'
        + r.segnali_di_allarme_da_riferire_subito.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul></div>';
    }

    if ((r.domande_per_medico_o_farmacista || []).length > 0) {
      html += '<div class="mb-2"><h3 class="h6">Domande per il medico o il farmacista</h3><ul>'
        + r.domande_per_medico_o_farmacista.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul></div>';
    }

    if ((r.limitazioni || []).length > 0) {
      html += '<p class="small text-muted">' + escapeHtml(r.limitazioni.join(' ')) + '</p>';
    }

    container.innerHTML = html;
  }

  var ultimoRapporto = null;
  var btnPdf = document.getElementById('btn-scarica-pdf');

  function renderRisultati(rapporto) {
    ultimoRapporto = rapporto;
    renderAvvisi(rapporto.avvisi);

    document.getElementById('stat-farmaci').textContent = rapporto.numero_farmaci;
    document.getElementById('stat-interazioni').textContent =
      (rapporto.analisi_deterministica.interazioni.potenziali_interazioni_esplicite || []).length;

    renderFarmaciTabella(rapporto.farmaci_analizzati);
    renderInterazioni(rapporto.analisi_deterministica.interazioni);
    renderEffettiCollaterali(rapporto.analisi_deterministica.effetti_collaterali);
    renderSintesiLlm(rapporto.analisi_llm);

    elLoading.hidden = true;
    elContenuto.hidden = false;
  }

  btnPdf.addEventListener('click', function () {
    if (!ultimoRapporto) {
      return;
    }
    var testoOriginale = btnPdf.textContent;
    btnPdf.disabled = true;
    btnPdf.textContent = 'Generazione in corso…';

    fetch(BASE_PATH + '/api/report', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rapporto: ultimoRapporto }),
    })
      .then(function (res) {
        if (!res.ok) {
          return Promise.reject('Errore durante la generazione del PDF (HTTP ' + res.status + ').');
        }
        return res.blob();
      })
      .then(function (blob) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'farmasintonia-report.pdf';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
      })
      .catch(function (errore) {
        window.alert(typeof errore === 'string' ? errore : 'Errore imprevisto durante la generazione del PDF.');
      })
      .finally(function () {
        btnPdf.disabled = false;
        btnPdf.textContent = testoOriginale;
      });
  });

  function eseguiAnalisi(elencoCorrente) {
    return fetch(BASE_PATH + '/api/analizza', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ codici_aic: elencoCorrente.map(function (f) { return f.codice_aic; }) }),
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) {
          return Promise.reject(data && data.errore ? data.errore : 'Errore durante l\'analisi (HTTP ' + res.status + ').');
        }
        return data;
      });
    });
  }

  // Delegato sul contenitore: il pulsante "Riprova più tardi" viene
  // ricreato ogni volta che la sezione si ri-renderizza.
  document.getElementById('sintesi-llm-container').addEventListener('click', function (event) {
    if (event.target.id !== 'btn-riprova-llm') {
      return;
    }
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Nuovo tentativo in corso…';

    eseguiAnalisi(leggiElenco())
      .then(renderRisultati)
      .catch(function (errore) {
        renderSintesiLlm({
          eseguita: false,
          motivo: typeof errore === 'string' ? errore : 'errore imprevisto durante il nuovo tentativo',
        });
      });
  });

  var elenco = leggiElenco();
  if (elenco.length === 0) {
    elLoading.hidden = true;
    elVuoto.hidden = false;
    return;
  }

  eseguiAnalisi(elenco)
    .then(renderRisultati)
    .catch(function (errore) {
      mostraErrore(typeof errore === 'string' ? errore : 'Errore imprevisto durante l\'analisi. Riprova più tardi.');
    });
})();
