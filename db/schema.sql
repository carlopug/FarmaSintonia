-- ============================================================================
-- Schema per il database "farmasintonia" (MariaDB 10.6 / MySQL 8 compatibile)
-- Mirror delle "Liste dei farmaci" open data AIFA:
-- https://www.aifa.gov.it/web/guest/liste-dei-farmaci
--
-- Nome del database volutamente in minuscolo (non "FarmaSintonia"): su Linux
-- MySQL/MariaDB tratta di norma i nomi di database/tabella in modo
-- case-sensitive, mentre su Windows sono case-insensitive di default — usare
-- solo minuscole evita disallineamenti tra l'ambiente di sviluppo locale
-- (Windows) e la VPS di produzione (Ubuntu/Linux).
--
-- Note generali:
--  - Tutte le colonne sono importate come testo (VARCHAR/TEXT): i dati AIFA
--    contengono placeholder tipo "N.D.", formati data non uniformi, prezzi
--    con virgola come separatore decimale, ecc. Cast/normalizzazione vanno
--    fatti a valle con viste o query (vedi README).
--  - Il codice AIC compare in formati diversi a seconda del file: a 9 cifre
--    (codice di confezione, es. in ana_confezioni/ana_principi_attivi) o a
--    6 cifre (codice di farmaco/principio, es. nelle liste di trasparenza,
--    carenze, orfani). Per fare join affidabili confrontare i primi 6
--    caratteri (LEFT(codice_aic, 6)) oppure normalizzare con LPAD/TRIM.
--  - Il codice ATC e' la chiave di join secondaria verso ana_atc.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS farmasintonia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE farmasintonia;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. ANAGRAFICA DEI FARMACI (nucleo centrale, aggiornamento: giorno precedente)
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS ana_confezioni;
CREATE TABLE ana_confezioni (
    codice_aic          VARCHAR(20)  NOT NULL,   -- CODICE_AIC (9 cifre, chiave di confezione)
    cod_farmaco         VARCHAR(20),              -- COD_FARMACO (raggruppa le confezioni dello stesso farmaco)
    cod_confezione      VARCHAR(20),              -- COD_CONFEZIONE
    denominazione       VARCHAR(500),             -- DENOMINAZIONE
    descrizione         VARCHAR(500),             -- DESCRIZIONE (confezione)
    codice_ditta        VARCHAR(20),              -- CODICE_DITTA
    ragione_sociale      VARCHAR(255),             -- RAGIONE_SOCIALE (titolare AIC)
    stato_amministrativo VARCHAR(100),             -- STATO_AMMINISTRATIVO
    tipo_procedura      VARCHAR(100),             -- TIPO_PROCEDURA
    forma               VARCHAR(255),             -- FORMA farmaceutica
    codice_atc          VARCHAR(20),              -- CODICE_ATC -> ana_atc.codice_atc
    pa_associati         TEXT,                     -- PA_ASSOCIATI (principi attivi, forma compatta) — TEXT anziché VARCHAR: confezioni con molti principi attivi combinati superano i 500 caratteri
    fornitura           VARCHAR(255),             -- FORNITURA (regime di fornitura)
    link_fi             TEXT,                     -- LINK_FI (foglio illustrativo)
    link_rcp            TEXT,                     -- LINK_RCP (riassunto caratteristiche prodotto)
    PRIMARY KEY (codice_aic),
    KEY idx_cod_farmaco (cod_farmaco),
    KEY idx_codice_atc (codice_atc),
    KEY idx_denominazione (denominazione(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (confezioni_fornitura.csv): anagrafica confezioni commerciali, una riga per codice_aic (9 cifre) — denominazione, ditta, forma, ATC, principi attivi sintetici, regime di fornitura, link a FI/RCP ufficiali.';

DROP TABLE IF EXISTS ana_principi_attivi;
CREATE TABLE ana_principi_attivi (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    codice_aic       VARCHAR(20) NOT NULL,        -- CODICE_AIC -> ana_confezioni.codice_aic
    principio_attivo VARCHAR(500),                -- PRINCIPIO_ATTIVO
    quantita         VARCHAR(50),                 -- QUANTITA (dosaggio numerico, come testo)
    unita_misura     VARCHAR(50),                 -- UNITA_MISURA
    KEY idx_codice_aic (codice_aic),
    KEY idx_principio_attivo (principio_attivo(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (PA_confezioni.csv): principi attivi per confezione (join su codice_aic), con quantità e unità di misura del dosaggio.';

DROP TABLE IF EXISTS ana_atc;
CREATE TABLE ana_atc (
    codice_atc  VARCHAR(20) NOT NULL,             -- CODICE_ATC
    descrizione VARCHAR(500),                     -- DESCRIZIONE
    PRIMARY KEY (codice_atc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (atc.csv): dizionario dei codici ATC con descrizione testuale, lookup per ana_confezioni.codice_atc.';

-- ----------------------------------------------------------------------------
-- 2. LISTE DI TRASPARENZA / PREZZI
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS trasp_equivalenti;
CREATE TABLE trasp_equivalenti (
    id                      BIGINT AUTO_INCREMENT PRIMARY KEY,
    principio_attivo        VARCHAR(500),
    confezione_riferimento  VARCHAR(500),
    codice_atc              VARCHAR(20),
    codice_aic              VARCHAR(20),
    farmaco                 VARCHAR(500),
    confezione              VARCHAR(500),
    ditta                    VARCHAR(255),
    prezzo_riferimento_ssn  VARCHAR(50),
    prezzo_pubblico         VARCHAR(50),
    differenza              VARCHAR(50),
    nota                     VARCHAR(500),
    codice_gruppo_equivalenza VARCHAR(50),
    KEY idx_codice_aic (codice_aic),
    KEY idx_codice_atc (codice_atc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (Liste di Trasparenza, Lista_farmaci_equivalenti.csv): farmaci equivalenti per gruppo di equivalenza, con prezzo al pubblico e prezzo di riferimento SSN.';

DROP TABLE IF EXISTS classe_a_principio_attivo;
CREATE TABLE classe_a_principio_attivo (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    principio_attivo      VARCHAR(500),
    descrizione_gruppo    VARCHAR(500),
    denominazione_confezione VARCHAR(500),
    prezzo_al_pubblico    VARCHAR(50),
    titolare_aic          VARCHAR(255),
    codice_aic            VARCHAR(20),
    codice_gruppo_equivalenza VARCHAR(50),
    lista_trasparenza     VARCHAR(50),
    solo_lista_regione    VARCHAR(255),
    metri_cubi_ossigeno   VARCHAR(50),
    KEY idx_codice_aic (codice_aic),
    KEY idx_principio_attivo (principio_attivo(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (Classe_A_per_principio_attivo.csv): farmaci di Classe A (rimborsati SSN), ordinati per principio attivo, con prezzo al pubblico e titolare AIC.';

DROP TABLE IF EXISTS classe_a_nome_commerciale;
CREATE TABLE classe_a_nome_commerciale LIKE classe_a_principio_attivo;
ALTER TABLE classe_a_nome_commerciale COMMENT='Mirror AIFA (Classe_A_per_nome_commerciale.csv): stesso contenuto di classe_a_principio_attivo, ordinato per nome commerciale.';

DROP TABLE IF EXISTS classe_h_principio_attivo;
CREATE TABLE classe_h_principio_attivo (
    id                        BIGINT AUTO_INCREMENT PRIMARY KEY,
    principio_attivo          VARCHAR(500),
    descrizione_gruppo        VARCHAR(500),
    denominazione_confezione  VARCHAR(500),
    prezzo_al_pubblico        VARCHAR(50),
    prezzo_ex_factory         VARCHAR(50),
    prezzo_massimo_cessione   VARCHAR(50),
    titolare_aic              VARCHAR(255),
    codice_aic                VARCHAR(20),
    KEY idx_codice_aic (codice_aic),
    KEY idx_principio_attivo (principio_attivo(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (Classe_H_per_principio_attivo.csv): farmaci di Classe H (uso ospedaliero/erogazione diretta), con prezzo ex-factory e prezzo massimo di cessione.';

DROP TABLE IF EXISTS classe_h_nome_commerciale;
CREATE TABLE classe_h_nome_commerciale LIKE classe_h_principio_attivo;
ALTER TABLE classe_h_nome_commerciale COMMENT='Mirror AIFA (Classe_H_per_nome_commerciale.csv): stesso contenuto di classe_h_principio_attivo, ordinato per nome commerciale.';

-- ----------------------------------------------------------------------------
-- 3. LISTE TEMATICHE / REGISTRI
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS sostanze_attive_generici;
CREATE TABLE sostanze_attive_generici (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    procedura        VARCHAR(50),
    principio_attivo VARCHAR(500),
    numero_domande   VARCHAR(50),
    KEY idx_principio_attivo (principio_attivo(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (Liste_sostanze_attive.csv): principi attivi con domande di autorizzazione come farmaco generico/equivalente in corso.';

DROP TABLE IF EXISTS classe_cnn;
CREATE TABLE classe_cnn (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    denominazione_farmaco TEXT,
    procedura             VARCHAR(255),
    codice_atc            VARCHAR(20),
    principio_attivo      VARCHAR(500),
    titolare              VARCHAR(255),
    parere_chmp           VARCHAR(50),
    decisione_commissione VARCHAR(50),
    data_guue             VARCHAR(50),
    data_cts              VARCHAR(50),
    tipologia             VARCHAR(255),
    aic_farmaco           VARCHAR(50),
    numero_ue_confezioni  VARCHAR(255),
    provvedimento_aifa    TEXT,
    numero_e_data_guri    VARCHAR(255),
    KEY idx_codice_atc (codice_atc),
    KEY idx_aic_farmaco (aic_farmaco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (lista_farmaci_valutati_inserimento_classe_Cnn.csv): farmaci non ancora negoziati ai fini della rimborsabilità (classe C-nn), con iter regolatorio e provvedimento AIFA.';

DROP TABLE IF EXISTS uso_speciale_648;
CREATE TABLE uso_speciale_648 (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    link_determinazione TEXT,
    rettifica            VARCHAR(255),
    principio_attivo     VARCHAR(500),
    indicazione_terapeutica TEXT,
    gazzetta_ufficiale    TEXT,                     -- TEXT anziché VARCHAR: valori con più riferimenti GU concatenati superano i 255 caratteri
    KEY idx_principio_attivo (principio_attivo(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (elenco-farmaci-MR-l648.csv): farmaci erogabili a carico SSN ex L.648/96, uso al di fuori delle indicazioni approvate senza alternative terapeutiche valide.';

DROP TABLE IF EXISTS uso_speciale_648_malattie_rare;
CREATE TABLE uso_speciale_648_malattie_rare LIKE uso_speciale_648;
ALTER TABLE uso_speciale_648_malattie_rare COMMENT='Mirror AIFA (lista-farmaci-malattie-rare.csv): stessa lista dell\'uso speciale L.648/96, dal dataset dedicato alle malattie rare.';

DROP TABLE IF EXISTS uso_compassionevole;
CREATE TABLE uso_compassionevole (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    azienda_farmaceutica VARCHAR(255),
    principio_attivo_nome_commerciale VARCHAR(500),
    indicazione_terapeutica TEXT,
    inizio_programma     VARCHAR(100),
    stato_programma      VARCHAR(100),
    note                 TEXT,
    KEY idx_principio_attivo (principio_attivo_nome_commerciale(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (programmi_uso_compassionevole.csv): programmi di uso compassionevole attivi/conclusi, farmaci non ancora autorizzati resi disponibili senza alternative terapeutiche.';

DROP TABLE IF EXISTS farmaci_carenti;
CREATE TABLE farmaci_carenti (
    id                     BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome_medicinale        VARCHAR(500),
    codice_aic             VARCHAR(20),
    principio_attivo       VARCHAR(500),
    forma_farmaceutica_dosaggio VARCHAR(500),
    titolare_aic           VARCHAR(255),
    data_inizio            VARCHAR(50),
    fine_presunta          VARCHAR(50),
    equivalente            VARCHAR(255),
    motivazioni            TEXT,
    suggerimenti_indicazioni_aifa TEXT,
    nota_aifa              TEXT,
    classe_rimborsabilita  VARCHAR(50),
    codice_atc             VARCHAR(20),
    KEY idx_codice_aic (codice_aic),
    KEY idx_codice_atc (codice_atc),
    KEY idx_principio_attivo (principio_attivo(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (elenco_medicinali_carenti.csv): farmaci in carenza/indisponibilità sul mercato italiano, con data inizio, fine presunta ed eventuale equivalente disponibile.';

DROP TABLE IF EXISTS registri_monitoraggio_attivi;
CREATE TABLE registri_monitoraggio_attivi (
    id                     BIGINT AUTO_INCREMENT PRIMARY KEY,
    scheda                 VARCHAR(255),
    farmaco                VARCHAR(500),
    principio_attivo       VARCHAR(500),
    codice_atc             VARCHAR(255),            -- più codici ATC concatenati per scheda, non un codice singolo come in ana_atc
    indicazione_autorizzata TEXT,
    indicazione_rimborsata TEXT,
    patologia              VARCHAR(500),
    tipologia_monitoraggio VARCHAR(255),
    stato_monitoraggio     VARCHAR(100),
    area_terapeutica       VARCHAR(255),
    data_inizio_monitoraggio VARCHAR(50),
    tipologia_mea          VARCHAR(255),
    stato_mea              VARCHAR(100),
    data_inizio_mea        VARCHAR(50),
    data_fine_validita_mea VARCHAR(50),
    KEY idx_codice_atc (codice_atc),
    KEY idx_principio_attivo (principio_attivo(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (Elenco_Registri_PT_attivi.csv): registri di monitoraggio attivi per farmaci ad alto costo o a rimborso condizionato, con patologia e indicazione autorizzata/rimborsata.';

DROP TABLE IF EXISTS registri_monitoraggio_chiusi;
CREATE TABLE registri_monitoraggio_chiusi (
    id                     BIGINT AUTO_INCREMENT PRIMARY KEY,
    scheda                 VARCHAR(255),
    farmaco                VARCHAR(500),
    principio_attivo       VARCHAR(500),
    codice_atc             VARCHAR(255),            -- più codici ATC concatenati per scheda, non un codice singolo come in ana_atc
    indicazione_autorizzata TEXT,
    indicazione_rimborsata TEXT,
    patologia              VARCHAR(500),
    tipologia_monitoraggio VARCHAR(255),
    stato_monitoraggio     VARCHAR(100),
    area_terapeutica       VARCHAR(255),
    data_inizio_monitoraggio VARCHAR(50),
    data_termine_monitoraggio VARCHAR(50),
    tipologia_mea          VARCHAR(255),
    stato_mea              VARCHAR(100),
    data_inizio_mea        VARCHAR(50),
    data_fine_validita_mea VARCHAR(50),
    KEY idx_codice_atc (codice_atc),
    KEY idx_principio_attivo (principio_attivo(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (Elenco_Registri_PT_chiusi.csv): registri di monitoraggio chiusi, stessa struttura di registri_monitoraggio_attivi.';

DROP TABLE IF EXISTS strutture_sanitarie_abilitate;
CREATE TABLE strutture_sanitarie_abilitate (
    id                            BIGINT AUTO_INCREMENT PRIMARY KEY,
    medicinale                    VARCHAR(500),
    descrizione_sintetica_patologia VARCHAR(500),
    indicazione_terapeutica_rimborsata TEXT,
    struttura_sanitaria_abilitata  VARCHAR(500),
    regione                        VARCHAR(100),
    KEY idx_medicinale (medicinale(191)),
    KEY idx_regione (regione)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (Strutture_registri_regioni.csv): strutture sanitarie regionali abilitate a prescrivere/erogare farmaci soggetti a registro di monitoraggio.';

DROP TABLE IF EXISTS farmaci_orfani;
CREATE TABLE farmaci_orfani (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    descrizione_farmaco VARCHAR(500),
    data_inizio_reg    VARCHAR(20),
    aic_6_digit        VARCHAR(20),
    codice_atc         VARCHAR(20),
    principio_attivo   VARCHAR(500),
    classe             VARCHAR(20),
    data_fine          VARCHAR(20),
    KEY idx_aic (aic_6_digit),
    KEY idx_codice_atc (codice_atc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Mirror AIFA (Lista-farmaci-orfani.csv): farmaci designati orfani da EMA per il trattamento di malattie rare, con periodo di designazione.';

-- ----------------------------------------------------------------------------
-- 4. DOCUMENTI (RCP/FI) SCARICATI ON-DEMAND DA api.aifa.gov.it
--    A differenza delle tabelle precedenti (mirror 1:1 dei CSV AIFA), queste
--    tabelle NON vengono popolate dall'import bulk ma dal servizio di fetch
--    RCP/FI (src/Services/RcpFetcher.php), farmaco per farmaco, su richiesta.
--    Un singolo PDF restituito da api.aifa.gov.it per un cod_farmaco puo'
--    contenere piu' documenti concatenati (una specialita' medicinale spesso
--    raggruppa piu' formulazioni/dosaggi con RCP distinti): da qui la tabella
--    "varianti" tra il documento scaricato e le sue sezioni.
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS farmaci_documenti_sezioni;
DROP TABLE IF EXISTS farmaci_documenti_varianti;
DROP TABLE IF EXISTS farmaci_documenti;

CREATE TABLE farmaci_documenti (
    id                      BIGINT AUTO_INCREMENT PRIMARY KEY,
    cod_farmaco             VARCHAR(20) NOT NULL,        -- ana_confezioni.cod_farmaco
    codice_ditta            VARCHAR(20) NOT NULL,        -- ana_confezioni.codice_ditta
    tipo_documento          VARCHAR(10) NOT NULL,        -- 'RCP' o 'FI'
    url_origine             TEXT,
    nome_file               VARCHAR(255),
    data_disponibilita_aifa VARCHAR(20),                 -- da "Documento reso disponibile da AIFA il ..."
    num_pagine              INT,
    num_varianti            INT,                         -- quanti documenti concatenati nel bundle
    testo_completo          LONGTEXT,                    -- testo integrale estratto, come rete di sicurezza
    scaricato_il            DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_farmaco_ditta_tipo (cod_farmaco, codice_ditta, tipo_documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Cache applicativa (non da import bulk): un documento RCP o FI scaricato on-demand da api.aifa.gov.it per farmaco, chiave (cod_farmaco, codice_ditta, tipo_documento).';

CREATE TABLE farmaci_documenti_varianti (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    documento_id          BIGINT NOT NULL,
    indice_variante       INT NOT NULL,                  -- posizione nel bundle (1, 2, 3...)
    denominazione_variante VARCHAR(500),                 -- da sezione 1 (es. "KESTINE 10 mg compresse...")
    informazioni_amministrative MEDIUMTEXT,               -- testo grezzo sezioni finali (titolare/AIC/date), numerazione non affidabile
    FOREIGN KEY (documento_id) REFERENCES farmaci_documenti(id) ON DELETE CASCADE,
    KEY idx_documento_id (documento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Formulazioni/dosaggi distinti individuati all\'interno di uno stesso PDF RCP/FI concatenato (farmaci_documenti).';

CREATE TABLE farmaci_documenti_sezioni (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    variante_id     BIGINT NOT NULL,
    sezione_codice  VARCHAR(10),                         -- es. '1', '4.1', '6.3'
    sezione_titolo  VARCHAR(255),                        -- es. 'Indicazioni terapeutiche'
    contenuto       MEDIUMTEXT,
    ordine          INT,
    FOREIGN KEY (variante_id) REFERENCES farmaci_documenti_varianti(id) ON DELETE CASCADE,
    KEY idx_variante_id (variante_id),
    KEY idx_sezione_codice (sezione_codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Testo di ogni variante RCP/FI sezionato per numero (4.3 controindicazioni, 4.5 interazioni, 4.8 effetti indesiderati, ecc.) — fonte dati per l\'analisi di compatibilità.';

-- ----------------------------------------------------------------------------
-- Tabella di controllo import (utile per sapere quando/cosa e' stato caricato)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS import_log;
CREATE TABLE import_log (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    tabella      VARCHAR(100) NOT NULL,
    url_origine  TEXT NOT NULL,
    righe_importate INT,
    eseguito_il  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Registro tecnico delle esecuzioni di deploy/import_aifa.py: tabella, URL sorgente, righe importate e data di ogni import bulk.';

-- ----------------------------------------------------------------------------
-- Rate limiting minimo per IP sugli endpoint che chiamano l'LLM: finestra
-- scorrevole semplice, un contatore per indirizzo IP.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS rate_limit_llm;
CREATE TABLE rate_limit_llm (
    ip_address      VARCHAR(45) NOT NULL PRIMARY KEY,  -- IPv4 o IPv6
    finestra_inizio DATETIME NOT NULL,
    conteggio       INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Rate limiting per IP sugli endpoint che chiamano l\'LLM: contatore a finestra temporale, nessun dato personale persistito.';

-- ----------------------------------------------------------------------------
-- Cache dei risultati di sintesi LLM (Services/LlmSintesi.php): la chiave
-- dipende dal contenuto testuale RCP effettivamente inviato al modello, non
-- solo dall'elenco farmaci, quindi si invalida da sola se un documento RCP
-- viene ri-scaricato con testo diverso. Evita di richiamare l'API OpenAI
-- (lenta, a pagamento) per una combinazione di farmaci già analizzata.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS llm_sintesi_cache;
CREATE TABLE llm_sintesi_cache (
    chiave     CHAR(64) NOT NULL PRIMARY KEY,   -- sha256(modello + payload compatto)
    modello    VARCHAR(100) NOT NULL,
    risultato  LONGTEXT NOT NULL,               -- JSON del campo "risultato" di analisi_llm
    creato_il  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Cache dei risultati di sintesi LLM già calcolati, chiave sul contenuto RCP inviato al modello — evita chiamate OpenAI ripetute per la stessa combinazione di farmaci.';

-- ----------------------------------------------------------------------------
-- Logging interno (Services/UsoLogger.php): nessun dato personale/sensibile,
-- solo utilizzo aggregato dell'applicazione — quali farmaci (dati AIFA
-- pubblici, non dati del paziente) vengono analizzati insieme e quanto
-- spesso, ed errori della sintesi LLM. Utile come indicatore aggregato per
-- sviluppi futuri, non per il funzionamento dell'app né per
-- l'identificazione di alcun utente.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS log_analisi_farmaci;
DROP TABLE IF EXISTS log_analisi;

CREATE TABLE log_analisi (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    eseguito_il     DATETIME DEFAULT CURRENT_TIMESTAMP,
    numero_farmaci  INT NOT NULL,
    llm_eseguita    TINYINT(1) NOT NULL DEFAULT 0,
    llm_dalla_cache TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_eseguito_il (eseguito_il)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Log anonimo di utilizzo: una riga per ogni analisi eseguita (numero farmaci, esito sintesi LLM), nessun dato personale.';

CREATE TABLE log_analisi_farmaci (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    analisi_id    BIGINT NOT NULL,
    codice_aic    VARCHAR(20) NOT NULL,
    denominazione VARCHAR(500),
    FOREIGN KEY (analisi_id) REFERENCES log_analisi(id) ON DELETE CASCADE,
    KEY idx_analisi_id (analisi_id),
    KEY idx_codice_aic (codice_aic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Log anonimo: farmaci (dati AIFA pubblici) coinvolti in ciascuna analisi in log_analisi — utile per capire quali farmaci/combinazioni ricorrono più spesso.';

DROP TABLE IF EXISTS log_errori_llm;
CREATE TABLE log_errori_llm (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    avvenuto_il   DATETIME DEFAULT CURRENT_TIMESTAMP,
    codice_errore VARCHAR(100) NOT NULL,
    messaggio     TEXT,
    modello       VARCHAR(100),
    http_status   INT,
    KEY idx_avvenuto_il (avvenuto_il)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Log degli errori della sintesi LLM (codice, messaggio, modello, http status) per diagnosticare problemi con l\'API OpenAI.';

SET FOREIGN_KEY_CHECKS = 1;
