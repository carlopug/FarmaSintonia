(function () {
  'use strict';

  var BASE_PATH = window.FS_BASE_PATH || '';
  var SESSION_KEY = 'farmasintonia:terapia';

  var elVuoto = document.getElementById('risultati-vuoto');
  var elLoading = document.getElementById('risultati-loading');
  var elLoadingTesto = document.getElementById('risultati-loading-testo');
  var elErrore = document.getElementById('risultati-errore');
  var elContenuto = document.getElementById('risultati-contenuto');
  var elAvvisi = document.getElementById('risultati-avvisi');

  // Nessun segnale di avanzamento reale dal server (è un'unica chiamata
  // /api/analizza): questi messaggi si alternano solo per far percepire che
  // l'attesa sta procedendo, non riflettono fasi effettivamente completate.
  var MESSAGGI_ATTESA = [
    'Recupero il Riassunto delle Caratteristiche del Prodotto da AIFA…',
    'Analizzo le sezioni su interazioni e controindicazioni…',
    'Elaboro i testi con l\'intelligenza artificiale…',
    'Ragiono sulle interazioni tra i farmaci…',
    'Aggrego gli effetti collaterali in comune…',
    'Sto quasi finendo, un attimo ancora…',
  ];
  var timerAttesa = null;

  function avviaMessaggiAttesa() {
    var indice = 0;
    elLoadingTesto.textContent = MESSAGGI_ATTESA[0];
    fermaMessaggiAttesa();
    timerAttesa = setInterval(function () {
      indice = (indice + 1) % MESSAGGI_ATTESA.length;
      elLoadingTesto.style.opacity = '0';
      setTimeout(function () {
        elLoadingTesto.textContent = MESSAGGI_ATTESA[indice];
        elLoadingTesto.style.opacity = '1';
      }, 200);
    }, 2600);
  }

  function fermaMessaggiAttesa() {
    if (timerAttesa) {
      clearInterval(timerAttesa);
      timerAttesa = null;
    }
  }

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
    fermaMessaggiAttesa();
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

  function livelloRischioBadge(livello) {
    var mappa = {
      controindicata: 'bg-danger',
      maggiore: 'bg-danger',
      moderata: 'bg-warning text-dark',
      da_valutare: 'bg-warning text-dark',
      minore: 'bg-secondary',
      non_determinabile: 'bg-secondary',
    };
    var etichette = {
      controindicata: 'Combinazione controindicata',
      maggiore: 'Rischio maggiore',
      moderata: 'Rischio moderato',
      da_valutare: 'Rischio da valutare',
      minore: 'Rischio minore',
      non_determinabile: 'Rischio non determinabile',
    };
    var chiave = livello || 'non_determinabile';
    return '<span class="badge ' + (mappa[chiave] || 'bg-secondary') + '">'
      + escapeHtml(etichette[chiave] || 'Rischio non determinabile') + '</span>';
  }

  function badgeOrigine(origine) {
    var mappa = {
      llm_e_riscontro_automatico: ['Evidenza verificata nei documenti AIFA', 'bg-success'],
      solo_llm: ["Valutazione dell'intelligenza artificiale", 'bg-secondary'],
      solo_riscontro_automatico: ['Riscontro automatico, non ancora valutato dal modello', 'bg-warning text-dark'],
    };
    var coppia = mappa[origine] || mappa.solo_llm;
    return '<span class="badge ' + coppia[1] + '">' + coppia[0] + '</span>';
  }

  function renderEvidenze(evidenze) {
    return (evidenze || []).map(function (e) {
      var fonte = e.farmaco_fonte
        ? '<footer class="blockquote-footer">Fonte: RCP ' + escapeHtml(e.farmaco_fonte)
          + (e.numero_sezione ? ', sez. ' + escapeHtml(e.numero_sezione) : '')
          + (e.titolo ? ' ' + escapeHtml(e.titolo) : '') + '</footer>'
        : '';
      return '<blockquote class="fs-evidence">"' + escapeHtml(e.estratto) + '"' + fonte + '</blockquote>';
    }).join('');
  }

  function renderInterazioniUnificate(analisiUnificata, notaDeterministica) {
    var elQuadro = document.getElementById('quadro-generale');
    elQuadro.innerHTML = analisiUnificata.riepilogo_terapia
      ? '<p>' + escapeHtml(analisiUnificata.riepilogo_terapia) + '</p>'
      : '';

    var elNota = document.getElementById('nota-sintesi-non-disponibile');
    if (!analisiUnificata.sintesi_disponibile) {
      elNota.innerHTML = '<div class="alert alert-info fs-callout small mb-3">'
        + 'Sintesi IA non disponibile in questo momento — qui sotto il riscontro sui documenti ufficiali AIFA. '
        + escapeHtml(analisiUnificata.motivo_sintesi_non_disponibile || 'si è verificato un problema imprevisto')
        + ' <button type="button" id="btn-riprova-llm" class="btn btn-link btn-sm p-0 align-baseline">Riprova più tardi</button>'
        + '</div>';
    } else {
      elNota.innerHTML = '';
    }

    document.getElementById('interazioni-nota').textContent = notaDeterministica || '';

    var interazioni = analisiUnificata.interazioni || [];
    var container = document.getElementById('interazioni-container');
    if (interazioni.length === 0) {
      container.innerHTML = '<p class="text-muted">Nessuna interazione rilevata tra i farmaci di questo elenco '
        + 'nei testi RCP disponibili. Questo non dimostra che la combinazione sia sicura: parlane comunque con il medico o il farmacista.</p>';
      return;
    }

    container.innerHTML = interazioni.map(function (item) {
      return '<div class="card fs-pair-card"><div class="card-body">'
        + '<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">'
        + '<h3 class="h6 mb-2">' + escapeHtml((item.farmaci_coinvolti || []).join(' + ')) + '</h3>'
        + '<div class="d-flex gap-1 flex-wrap">' + badgeOrigine(item.origine) + livelloRischioBadge(item.livello_rischio) + '</div>'
        + '</div>'
        + (item.sintesi ? '<p>' + escapeHtml(item.sintesi) + '</p>' : '')
        + (item.conseguenze_potenziali && item.conseguenze_potenziali.length
          ? '<p class="small mb-1"><strong>Conseguenze potenziali:</strong> ' + escapeHtml(item.conseguenze_potenziali.join('; ')) + '</p>' : '')
        + renderEvidenze(item.evidenze)
        + (item.azione_prudenziale ? '<p class="small mb-0"><strong>Azione prudenziale:</strong> ' + escapeHtml(item.azione_prudenziale) + '</p>' : '')
        + '</div></div>';
    }).join('');
  }

  function renderEffettiUnificati(analisiUnificata, effettiPerFarmaco) {
    var gruppi = analisiUnificata.effetti_collaterali_aggregati || [];
    var elAggregati = document.getElementById('effetti-aggregati-container');
    elAggregati.innerHTML = gruppi.length === 0
      ? '<p class="text-muted small mb-0">Aggregazione per categoria non disponibile in questo momento; qui sotto il testo integrale per farmaco.</p>'
      : gruppi.map(function (g) {
        return '<div><p class="mb-1"><strong>' + escapeHtml(g.categoria) + '</strong></p><ul class="mb-1">'
          + (g.effetti || []).map(function (e) {
            return '<li>' + escapeHtml(e.effetto) + ' <span class="text-muted small">(' + escapeHtml((e.farmaci_associati || []).join(', ')) + ')</span></li>';
          }).join('') + '</ul>'
          + (g.possibile_sovrapposizione ? '<p class="small text-muted mb-0">' + escapeHtml(g.possibile_sovrapposizione) + '</p>' : '')
          + '</div>';
      }).join('');

    var perFarmaco = effettiPerFarmaco || [];
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

  function renderSintesiExtra(analisiUnificata) {
    var container = document.getElementById('sintesi-extra-container');
    var html = '';

    if ((analisiUnificata.rischi_cumulativi || []).length > 0) {
      html += '<div class="alert alert-warning"><strong>Rischi cumulativi descritti.</strong><ul class="mb-0">'
        + analisiUnificata.rischi_cumulativi.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul></div>';
    }

    if ((analisiUnificata.segnali_di_allarme || []).length > 0) {
      html += '<div class="alert alert-danger"><strong>Segnali di allarme da riferire subito.</strong><ul class="mb-0">'
        + analisiUnificata.segnali_di_allarme.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul></div>';
    }

    if ((analisiUnificata.domande_per_medico || []).length > 0) {
      html += '<div class="mb-2"><h2 class="h5">Domande per il medico o il farmacista</h2><ul>'
        + analisiUnificata.domande_per_medico.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul></div>';
    }

    if ((analisiUnificata.limitazioni || []).length > 0) {
      html += '<p class="small text-muted">' + escapeHtml(analisiUnificata.limitazioni.join(' ')) + '</p>';
    }

    container.innerHTML = html;
  }

  var ultimoRapporto = null;
  var btnPdf = document.getElementById('btn-scarica-pdf');

  function renderRisultati(rapporto) {
    ultimoRapporto = rapporto;
    renderAvvisi(rapporto.avvisi);

    var analisiUnificata = rapporto.analisi_unificata || {};

    document.getElementById('stat-farmaci').textContent = rapporto.numero_farmaci;
    document.getElementById('stat-interazioni').textContent = (analisiUnificata.interazioni || []).length;

    renderFarmaciTabella(rapporto.farmaci_analizzati);
    renderInterazioniUnificate(analisiUnificata, (rapporto.analisi_deterministica.interazioni || {}).nota);
    renderEffettiUnificati(analisiUnificata, (rapporto.analisi_deterministica.effetti_collaterali || {}).per_farmaco);
    renderSintesiExtra(analisiUnificata);

    fermaMessaggiAttesa();
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
  document.getElementById('nota-sintesi-non-disponibile').addEventListener('click', function (event) {
    if (event.target.id !== 'btn-riprova-llm') {
      return;
    }
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Nuovo tentativo in corso…';

    eseguiAnalisi(leggiElenco())
      .then(renderRisultati)
      .catch(function (errore) {
        document.getElementById('nota-sintesi-non-disponibile').innerHTML =
          '<div class="alert alert-info fs-callout small mb-3">Nuovo tentativo non riuscito: '
          + escapeHtml(typeof errore === 'string' ? errore : 'errore imprevisto') + '</div>';
      });
  });

  var elenco = leggiElenco();
  if (elenco.length === 0) {
    elLoading.hidden = true;
    elVuoto.hidden = false;
    return;
  }

  avviaMessaggiAttesa();
  eseguiAnalisi(elenco)
    .then(renderRisultati)
    .catch(function (errore) {
      mostraErrore(typeof errore === 'string' ? errore : 'Errore imprevisto durante l\'analisi. Riprova più tardi.');
    });
})();
