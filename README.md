<p align="center">
  <img src="logo/logo completo.png" alt="FarmaSintonia" width="360">
</p>

<p align="center"><i>Compatibilità e principi attivi in sintonia.</i></p>

---

FarmaSintonia è una web app che aiuta chi assume più di un farmaco
contemporaneamente a capire se sono compatibili tra loro — a partire dai
principi attivi — così come indicato nei bugiardini ufficiali, e a ottenere
un riepilogo degli effetti collaterali che più farmaci hanno in comune.

## Il problema

Chi segue più terapie contemporaneamente lo sa: ogni nuovo specialista
consultato tende a prescrivere per il proprio sintomo, senza sempre avere
sotto mano il quadro completo dei farmaci già assunti. Il rischio è che si
creino incompatibilità o si sommino effetti collaterali che, presi
singolarmente, sembrano farmaco per farmaco nella norma. È una situazione
tipica soprattutto oltre i 40 anni, quando la probabilità di seguire più di
una terapia contemporaneamente cresce.

FarmaSintonia non sostituisce una consulenza medica: dà al paziente uno
strumento per arrivare da medico o farmacista con qualche domanda in più e
qualche informazione già in mano.

## Cosa fa

1. **Ricerca farmaco** per nome commerciale, con autocompletamento.
2. **Elenco terapia**: si aggiungono i farmaci che si stanno assumendo.
3. **Analisi delle interazioni/incompatibilità** tra tutti i farmaci
   nell'elenco, basata sui testi ufficiali del Riassunto delle
   Caratteristiche del Prodotto (RCP) — riscontro deterministico con
   **evidenze testuali citate**, più una sintesi in linguaggio comprensibile
   assistita da un modello linguistico.
4. **Effetti collaterali aggregati**: quali effetti indesiderati sono
   comuni a più farmaci dell'elenco.
5. **Report scaricabile in PDF**, con il disclaimer medico sempre visibile.

> ⚠️ **Avvertenza medica.** Analisi informativa automatizzata basata
> esclusivamente sui documenti AIFA presenti nel file sorgente. Non
> costituisce diagnosi o prescrizione, non dimostra che una combinazione
> sia sicura e non deve essere usata per iniziare, sospendere o modificare
> una terapia senza medico o farmacista.

## Perché è fatto così

- **Solo Open Data AIFA pubblici**, mai l'API privata dell'app mobile
  ufficiale AIFA. Quest'ultima esiste ed è più ricca, ma richiederebbe di
  pubblicare in un repository open source l'analisi di un'API non
  documentata con credenziali OAuth incorporate nell'app — un rischio non
  necessario quando i dati pubblici bastano allo scopo. I dati vengono
  dalle "Liste dei farmaci" ufficiali AIFA (CSV pubblici) e dall'API
  pubblica e non autenticata di `api.aifa.gov.it` per i documenti RCP/FI.
- **Nessun dato personale salvato lato server.** L'elenco terapia — un
  dato che riguarda la salute di chi lo usa — vive solo nella sessione
  della richiesta, mai in un database: niente account, niente cronologia
  lato server. È la scelta più semplice e anche la più prudente dal punto
  di vista della privacy.
- **PHP 8.3 + Slim Framework**, non uno stack più "trendy": l'app gira su
  un'infrastruttura (Apache + PHP-FPM + MySQL) già in produzione, e usare
  lo stesso stack significa zero servizi nuovi da mantenere.
- **Riscontro deterministico prima, LLM dopo.** Le interazioni segnalate
  citano l'estratto testuale reale della sezione RCP interessata: non è
  un'affermazione generata e basta, è verificabile risalendo alla fonte.
  Il modello linguistico interviene solo per riformulare in linguaggio
  comprensibile ciò che è già stato trovato nei documenti ufficiali.
- **RCP (Riassunto delle Caratteristiche del Prodotto), non il foglietto
  illustrativo (FI).** AIFA pubblica entrambi, ma solo l'RCP ha una
  struttura a sezioni numerate standard (4.3 controindicazioni, 4.4
  avvertenze, 4.5 interazioni, 4.8 effetti indesiderati, 6.2
  incompatibilità), identica farmaco per farmaco: è ciò che rende possibile
  il sezionamento automatico e il confronto tra farmaci diversi. Il FI, più
  semplice da leggere, generalizza spesso le interazioni invece di
  elencare le sostanze specifiche — usarlo rischierebbe di far perdere
  interazioni reali. Il linguaggio più tecnico dell'RCP è comunque reso
  comprensibile dalla sintesi LLM descritta sopra, senza sacrificare la
  completezza clinica.
- **Interfaccia basata su [Bootstrap Italia](https://italia.github.io/bootstrap-italia/)**
  (Design System Italia), scelto per l'accessibilità WCAG AA già integrata
  — rilevante per un pubblico che comprende spesso persone over 40 — e
  restylizzato con l'identità grafica del progetto.

## Uso dell'IA

Il modello linguistico non lavora "a memoria": per ogni farmaco in elenco
riceve il testo effettivamente estratto dalle sezioni RCP pertinenti
(controindicazioni, avvertenze, interazioni, effetti indesiderati,
incompatibilità), e viene istruito esplicitamente ad analizzare solo quei
testi, senza usare conoscenze esterne né inventare interazioni.

Detto questo, è giusto dirlo con chiarezza: si tratta di un'istruzione data
al modello, non di un vincolo tecnico assoluto — un modello linguistico non
può essere "disconnesso" dalla propria conoscenza pregressa come si
scollegherebbe un cavo di rete. Per questo ogni interazione mostrata
riporta da dove viene: quando corrisponde a un riscontro trovato per
corrispondenza testuale diretta nei documenti (non una citazione
autoselezionata dal modello), è etichettata come **evidenza verificata nei
documenti AIFA**; quando è solo il modello a segnalarla, senza quella
corrispondenza, è etichettata come **valutazione dell'intelligenza
artificiale** — comunque basata sui testi RCP che gli sono stati forniti,
ma senza una verifica indipendente che l'affermazione sia ancorata
letteralmente al documento.

## Come si esegue in locale

Richiede PHP 8.3+, Composer, un server MySQL 8 o MariaDB 10.6+, e Python
3.10+ (solo per l'import dati AIFA, un'operazione una tantum — non serve a
runtime).

```bash
# 1. Dipendenze PHP
composer install

# 2. Configurazione
cp .env.example .env
# valorizzare DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD, OPENAI_API_KEY e
# OPENAI_MODEL (opzionale: senza chiave l'app funziona comunque, limitata
# alla sola analisi deterministica).

# 3. Schema del database (crea database e tabelle: richiede un utente con
# privilegi di amministrazione, es. root)
mysql -u root -p < db/schema.sql

# poi un utente dedicato a permessi minimi per l'app (stesso pattern di
# deploy/vps-db-setup.sql):
mysql -u root -p -e "
  CREATE USER 'farmasintonia'@'localhost' IDENTIFIED BY 'una-password-a-scelta';
  GRANT SELECT, INSERT, UPDATE, DELETE ON farmasintonia.* TO 'farmasintonia'@'localhost';
"

# 4. Import dei dati pubblici AIFA (una tantum)
python3 -m venv deploy/.venv
deploy/.venv/bin/pip install -r deploy/requirements.txt
deploy/.venv/bin/python deploy/import_aifa.py \
    --host 127.0.0.1 --port 3306 \
    --user farmasintonia --password <password-scelta-sopra> --database farmasintonia

# 5. Avvio in sviluppo
php -S localhost:8000 -t public
```

## Fonti dati

- [Liste dei farmaci — Open Data AIFA](https://www.aifa.gov.it/web/guest/liste-dei-farmaci)
- API pubblica `api.aifa.gov.it` per i documenti RCP/FI (nessuna
  autenticazione richiesta)

## Licenza

Rilasciato con licenza **MIT** — vedi [`LICENSE`](LICENSE).
Le licenze delle librerie di terze parti utilizzate sono elencate in
[`NOTICE.md`](NOTICE.md).

## Sviluppato con

Sviluppato con [Claude Code](https://claude.com/claude-code) (sviluppo
agentico).

## Autore

Carlo Puglisi — [github.com/carlopug](https://github.com/carlopug)
