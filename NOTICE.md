# NOTICE — Licenze di terze parti

FarmaSintonia è rilasciato con licenza **MIT** (vedi [`LICENSE`](LICENSE)).
Questo file elenca, per trasparenza, le librerie di terze parti usate come
dipendenze (mai committate in questo repository: vengono scaricate da chi
esegue `composer install`, secondo i rispettivi termini) e le relative
licenze, verificate direttamente sulla fonte al momento della stesura.

| Libreria | Uso nel progetto | Licenza | Note |
|---|---|---|---|
| [Slim Framework](https://www.slimframework.com/) (`slim/slim`) | Routing dell'applicazione | MIT | Nessuna restrizione rilevante. |
| [slim/psr7](https://github.com/slimphp/Slim-Psr7) | Implementazione PSR-7 richiesta da Slim | MIT | Nessuna restrizione rilevante. |
| [Twig](https://twig.symfony.com/) (`slim/twig-view`) | Motore di template (pagine HTML) | BSD-3-Clause | Nessuna restrizione rilevante. |
| [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) | Caricamento configurazione da `.env` | BSD-3-Clause | Nessuna restrizione rilevante. |
| [Guzzle](https://docs.guzzlephp.org/) | Client HTTP (chiamate a `api.aifa.gov.it` e all'API OpenAI) | MIT | Nessuna restrizione rilevante. |
| [smalot/pdfparser](https://github.com/smalot/pdfparser) | Estrazione testo dai PDF RCP/FI | LGPL-3.0 | Usata come libreria non modificata; la LGPL è pensata apposta per essere impiegata da progetti con licenza diversa dalla propria. |
| [mpdf/mpdf](https://github.com/mpdf/mpdf) | Generazione del report PDF | **GPL-2.0-only** | Copyleft. Usata come dipendenza via Composer (mai ridistribuita come parte di questo repository) e come servizio web, non come pacchetto software distribuito agli utenti finali: la GPLv2, a differenza della AGPL, non impone obblighi di copyleft per il solo utilizzo via rete. Dichiarata qui esplicitamente per trasparenza. |
| [Bootstrap Italia](https://italia.github.io/bootstrap-italia/) (v2.18.3, via CDN) | Componenti UI/CSS di base (Design System Italia), restylizzati con l'identità grafica del progetto | BSD-3-Clause | Nessuna restrizione rilevante. |
| [PHPUnit](https://phpunit.de/) | Solo sviluppo (`require-dev`): test mirati sul porting della logica di analisi compatibilità e sul parsing RCP | BSD-3-Clause | Dipendenza di sviluppo, non presente nel codice eseguito in produzione. |

## Dati

I dati farmacologici utilizzati provengono dagli **Open Data pubblici
AIFA** ("Liste dei farmaci") e dall'**API pubblica non autenticata**
`api.aifa.gov.it` per i documenti RCP/FI. Non viene utilizzata alcuna API
privata né alcuna credenziale non pubblica — vedi `README.md` per i
dettagli e le motivazioni di questa scelta.

## Modello linguistico

L'analisi assistita da modello linguistico utilizza l'API di **OpenAI**
tramite chiamata HTTP diretta (nessun SDK di terze parti aggiunto come
dipendenza). L'uso è soggetto ai termini di servizio OpenAI; nessun dato
personale identificativo dell'utente viene inviato — solo nomi di farmaci
ed estratti testuali dai documenti RCP/FI ufficiali.
