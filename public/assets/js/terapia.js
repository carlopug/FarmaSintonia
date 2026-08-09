(function () {
  'use strict';

  var BASE_PATH = window.FS_BASE_PATH || '';
  var SESSION_KEY = 'farmasintonia:terapia';
  var MIN_FARMACI = 2;

  var searchInput = document.getElementById('farmaco-search');
  var suggerimenti = document.getElementById('farmaco-suggerimenti');
  var elenco = document.getElementById('elenco-terapia');
  var contatore = document.getElementById('elenco-count');
  var btnAnalizza = document.getElementById('btn-analizza');

  var debounceTimer = null;

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

  function salvaElenco(lista) {
    sessionStorage.setItem(SESSION_KEY, JSON.stringify(lista));
  }

  function renderElenco() {
    var lista = leggiElenco();
    contatore.textContent = String(lista.length);

    if (lista.length === 0) {
      elenco.innerHTML = '<li class="list-group-item fs-elenco-vuoto">'
        + 'Nessun farmaco aggiunto: cerca un farmaco qui sopra per iniziare.</li>';
    } else {
      elenco.innerHTML = lista.map(function (farmaco) {
        var principi = (farmaco.principi_attivi || []).join(', ');
        var dettagli = [farmaco.dosaggio, principi].filter(Boolean).join(' · ');
        return '<li class="list-group-item d-flex justify-content-between align-items-center" data-codice-aic="'
          + escapeHtml(farmaco.codice_aic) + '">'
          + '<span><strong>' + escapeHtml(farmaco.denominazione) + '</strong>'
          + (dettagli ? ' <span class="text-muted small">' + escapeHtml(dettagli) + '</span>' : '')
          + '</span>'
          + '<button type="button" class="btn btn-sm btn-outline-secondary fs-btn-rimuovi">Rimuovi</button>'
          + '</li>';
      }).join('');
    }

    btnAnalizza.disabled = lista.length < MIN_FARMACI;
  }

  function aggiungiFarmaco(farmaco) {
    var lista = leggiElenco();
    if (lista.some(function (f) { return f.codice_aic === farmaco.codice_aic; })) {
      return;
    }
    lista.push(farmaco);
    salvaElenco(lista);
    renderElenco();
  }

  function rimuoviFarmaco(codiceAic) {
    var lista = leggiElenco().filter(function (f) { return f.codice_aic !== codiceAic; });
    salvaElenco(lista);
    renderElenco();
  }

  function nascondiSuggerimenti() {
    suggerimenti.innerHTML = '';
    suggerimenti.hidden = true;
  }

  function renderSuggerimenti(risultati) {
    if (risultati.length === 0) {
      suggerimenti.innerHTML = '<li class="list-group-item text-muted">Nessun farmaco trovato.</li>';
      suggerimenti.hidden = false;
      return;
    }

    suggerimenti.innerHTML = risultati.map(function (farmaco) {
      var principi = (farmaco.principi_attivi || []).join(', ');
      return '<li class="list-group-item list-group-item-action d-flex justify-content-between align-items-center fs-suggerimento" '
        + 'style="cursor:pointer" data-farmaco=\'' + JSON.stringify(farmaco).replace(/'/g, '&#39;') + '\'>'
        + '<span>' + escapeHtml(farmaco.denominazione)
        + (farmaco.dosaggio ? ' <span class="text-muted small">' + escapeHtml(farmaco.dosaggio) + '</span>' : '')
        + (principi ? '<br><span class="text-muted small">' + escapeHtml(principi) + '</span>' : '')
        + '</span>'
        + '<span class="badge bg-secondary">+ Aggiungi</span>'
        + '</li>';
    }).join('');
    suggerimenti.hidden = false;
  }

  function cercaFarmaci(termine) {
    fetch(BASE_PATH + '/api/farmaci/cerca?q=' + encodeURIComponent(termine))
      .then(function (res) { return res.ok ? res.json() : Promise.reject(res.status); })
      .then(function (data) { renderSuggerimenti(data.risultati || []); })
      .catch(function () { nascondiSuggerimenti(); });
  }

  searchInput.addEventListener('input', function () {
    var termine = searchInput.value.trim();
    window.clearTimeout(debounceTimer);
    if (termine.length < 3) {
      nascondiSuggerimenti();
      return;
    }
    debounceTimer = window.setTimeout(function () { cercaFarmaci(termine); }, 300);
  });

  suggerimenti.addEventListener('click', function (event) {
    var riga = event.target.closest('.fs-suggerimento');
    if (!riga) {
      return;
    }
    var farmaco = JSON.parse(riga.getAttribute('data-farmaco').replace(/&#39;/g, "'"));
    aggiungiFarmaco(farmaco);
    searchInput.value = '';
    nascondiSuggerimenti();
    searchInput.focus();
  });

  elenco.addEventListener('click', function (event) {
    if (!event.target.classList.contains('fs-btn-rimuovi')) {
      return;
    }
    var riga = event.target.closest('li[data-codice-aic]');
    if (riga) {
      rimuoviFarmaco(riga.getAttribute('data-codice-aic'));
    }
  });

  document.addEventListener('click', function (event) {
    if (!searchInput.contains(event.target) && !suggerimenti.contains(event.target)) {
      nascondiSuggerimenti();
    }
  });

  btnAnalizza.addEventListener('click', function () {
    if (btnAnalizza.disabled) {
      return;
    }
    window.location.href = BASE_PATH + '/risultati';
  });

  renderElenco();
})();
