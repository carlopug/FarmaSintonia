#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Importa le "Liste dei farmaci" (open data AIFA) in un database MariaDB.

Scarica i CSV direttamente dagli URL pubblici AIFA (richiede una macchina
con accesso diretto a internet, es. il PC di sviluppo o la VPS di
produzione) e li carica nelle tabelle definite in schema.sql.

Uso:
    pip install -r requirements.txt
    python import_aifa.py                # importa tutte le tabelle
    python import_aifa.py --only ana_atc  # importa solo una tabella (debug)
    python import_aifa.py --list          # elenca le tabelle configurate

Connessione DB (modificabile anche da riga di comando, vedi --help):
    host=localhost porta=3307 db=aifa utente=aifa password=aifa
"""

import argparse
import csv
import io
import sys
from datetime import datetime

import pymysql
import requests

# www.aifa.gov.it non invia la catena di certificati completa (manca
# l'intermediate CA): browser e curl la risolvono comunque perche' usano
# l'attestato di sistema (Windows/macOS) che fa "AIA fetching" automatico,
# ma la libreria requests/certifi di Python no e fallisce con
# CERTIFICATE_VERIFY_FAILED. 'truststore' fa usare a Python lo stesso
# trust store del sistema operativo, risolvendo il problema in modo
# corretto (senza disabilitare la verifica TLS). Se il pacchetto non e'
# installato lo script funziona comunque, ma su www.aifa.gov.it potrebbe
# fallire: in tal caso esegui "pip install truststore".
try:
    import truststore
    truststore.inject_into_ssl()
except ImportError:
    pass

DB_CONFIG = dict(
    host="127.0.0.1",
    port=3307,
    user="aifa",
    password="aifa",
    database="aifa",
    charset="utf8mb4",
)

# ----------------------------------------------------------------------------
# Configurazione dei dataset: un elemento per ogni tabella/CSV.
#   table        : nome tabella (deve esistere gia', vedi schema.sql)
#   url          : URL pubblico del CSV su aifa.gov.it
#   header_line  : indice (0-based) della riga che contiene l'header nel CSV
#                  (alcuni file AIFA hanno 1-2 righe di titolo/nota prima
#                  dell'header vero e proprio)
#   ncols        : numero di colonne significative da mantenere (alcuni file
#                  hanno decine di colonne vuote finali dovute a celle unite
#                  nel foglio Excel originale)
# ----------------------------------------------------------------------------
DATASETS = [
    dict(
        table="ana_confezioni",
        url="https://drive.aifa.gov.it/farmaci/confezioni_fornitura.csv",
        header_line=0,
        ncols=15,
    ),
    dict(
        table="ana_principi_attivi",
        url="https://drive.aifa.gov.it/farmaci/PA_confezioni.csv",
        header_line=0,
        ncols=4,
    ),
    dict(
        table="ana_atc",
        url="https://drive.aifa.gov.it/farmaci/atc.csv",
        header_line=0,
        ncols=2,
    ),
    dict(
        table="trasp_equivalenti",
        url="https://www.aifa.gov.it/documents/20142/825643/Lista_farmaci_equivalenti.csv",
        header_line=0,
        ncols=12,
    ),
    dict(
        table="classe_a_principio_attivo",
        url="https://www.aifa.gov.it/documents/20142/3815901/Classe_A_per_principio_attivo_28-02-2026.csv",
        header_line=0,
        ncols=10,
    ),
    dict(
        table="classe_a_nome_commerciale",
        url="https://www.aifa.gov.it/documents/20142/3815901/Classe_A_per_nome_commerciale_28-02-2026.csv",
        header_line=0,
        ncols=10,
    ),
    dict(
        table="classe_h_principio_attivo",
        url="https://www.aifa.gov.it/documents/20142/3815901/Classe_H_per_principio_attivo_28-02-2026.csv",
        header_line=0,
        ncols=8,
    ),
    dict(
        table="classe_h_nome_commerciale",
        url="https://www.aifa.gov.it/documents/20142/3815901/Classe_H_per_nome_commerciale_28-02-2026.csv",
        header_line=0,
        ncols=8,
    ),
    dict(
        table="sostanze_attive_generici",
        url="https://www.aifa.gov.it/documents/20142/1725163/Liste_sostanze_attive_settembre_2022.csv",
        header_line=0,
        ncols=3,
    ),
    dict(
        table="classe_cnn",
        url="https://www.aifa.gov.it/documents/20142/847358/lista_farmaci_valutati_inserimento_classe_Cnn_08.07.2026.csv",
        header_line=0,
        ncols=14,
    ),
    dict(
        table="uso_speciale_648",
        url="https://www.aifa.gov.it/documents/20142/3805601/elenco-farmaci-MR-l648_23.06.2026.csv",
        header_line=2,
        ncols=5,
    ),
    dict(
        table="uso_speciale_648_malattie_rare",
        url="https://www.aifa.gov.it/documents/20142/3805601/lista-farmaci-malattie-rare_23.06.2026.csv",
        header_line=2,
        ncols=5,
    ),
    dict(
        table="uso_compassionevole",
        url="https://www.aifa.gov.it/documents/20142/847411/programmi_uso_compassionevole_03.06.2026.csv",
        header_line=2,
        ncols=6,
    ),
    dict(
        table="farmaci_carenti",
        url="https://www.aifa.gov.it/documents/20142/847339/elenco_medicinali_carenti.csv",
        header_line=2,
        ncols=13,
    ),
    dict(
        table="registri_monitoraggio_attivi",
        url="https://www.aifa.gov.it/documents/20142/3258518/Elenco_Registri_PT_attivi_17.07.2026.csv",
        header_line=0,
        ncols=15,
    ),
    dict(
        table="registri_monitoraggio_chiusi",
        url="https://www.aifa.gov.it/documents/20142/3258518/Elenco_Registri_PT_chiusi_17.07.2026.csv",
        header_line=0,
        ncols=16,
    ),
    dict(
        table="strutture_sanitarie_abilitate",
        url="https://www.aifa.gov.it/documents/20142/1826055/Strutture_registri_regioni_2026-06-26.csv",
        header_line=0,
        ncols=5,
    ),
    dict(
        table="farmaci_orfani",
        url="https://www.aifa.gov.it/documents/20142/842593/Lista-farmaci-orfani-2025.csv",
        header_line=0,
        ncols=7,
    ),
]

BATCH_SIZE = 2000
REQUEST_HEADERS = {"User-Agent": "Mozilla/5.0 (compatible; aifa-import-script/1.0)"}


def download_text(url: str, timeout: int = 60) -> str:
    """Scarica un URL e restituisce il testo, provando piu' encoding.

    I CSV AIFA non dichiarano sempre un charset corretto: proviamo utf-8
    stretto, poi cp1252 (il piu' comune per file prodotti con Excel in
    Italia), poi latin-1 con sostituzione dei caratteri non validi come
    ultima spiaggia.
    """
    resp = requests.get(url, headers=REQUEST_HEADERS, timeout=timeout)
    resp.raise_for_status()
    raw = resp.content

    for enc in ("utf-8-sig", "utf-8", "cp1252"):
        try:
            return raw.decode(enc)
        except UnicodeDecodeError:
            continue
    return raw.decode("latin-1", errors="replace")


def parse_rows(text: str, header_line: int, ncols: int):
    """Ritorna (header, righe) usando csv.reader (gestisce virgolette,
    campi multilinea, ecc. correttamente, a differenza di uno split ingenuo)."""
    reader = csv.reader(io.StringIO(text), delimiter=";", quotechar='"')
    all_rows = list(reader)

    if header_line >= len(all_rows):
        raise ValueError(f"header_line={header_line} oltre la fine del file ({len(all_rows)} righe)")

    header = all_rows[header_line][:ncols]
    data_rows = []
    for row in all_rows[header_line + 1:]:
        if not row or all(cell.strip() == "" for cell in row):
            continue  # riga vuota
        row = row[:ncols]
        if len(row) < ncols:
            row = row + [""] * (ncols - len(row))
        # stringa vuota -> NULL
        row = [cell.strip() if cell.strip() != "" else None for cell in row]
        data_rows.append(row)
    return header, data_rows


def load_table(conn, table: str, url: str, header_line: int, ncols: int):
    print(f"[{table}] scarico {url} ...")
    text = download_text(url)
    header, rows = parse_rows(text, header_line, ncols)
    print(f"[{table}] {len(rows)} righe da importare (colonne origine: {header})")

    with conn.cursor() as cur:
        cur.execute(f"TRUNCATE TABLE `{table}`")

        # Recupera i nomi colonna reali della tabella (esclude id/auto increment
        # e la eventuale PK dichiarata esplicitamente, mantenendo l'ordine di
        # definizione in schema.sql per fare l'insert posizionale).
        cur.execute(f"SHOW COLUMNS FROM `{table}`")
        table_cols = [r[0] for r in cur.fetchall() if r[0] not in ("id",)]
        table_cols = table_cols[:ncols]

        if len(table_cols) != ncols:
            raise ValueError(
                f"[{table}] attese {ncols} colonne dati ma la tabella ne ha {len(table_cols)}: {table_cols}"
            )

        placeholders = ", ".join(["%s"] * ncols)
        col_list = ", ".join(f"`{c}`" for c in table_cols)
        insert_sql = f"INSERT INTO `{table}` ({col_list}) VALUES ({placeholders})"

        inserted = 0
        for i in range(0, len(rows), BATCH_SIZE):
            batch = rows[i:i + BATCH_SIZE]
            cur.executemany(insert_sql, batch)
            inserted += len(batch)

        cur.execute(
            "INSERT INTO import_log (tabella, url_origine, righe_importate) VALUES (%s, %s, %s)",
            (table, url, inserted),
        )
    conn.commit()
    print(f"[{table}] OK: {inserted} righe importate.")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--only", help="importa solo la tabella indicata (per test/debug)")
    parser.add_argument("--list", action="store_true", help="elenca le tabelle configurate ed esce")
    parser.add_argument("--host", default=DB_CONFIG["host"])
    parser.add_argument("--port", type=int, default=DB_CONFIG["port"])
    parser.add_argument("--user", default=DB_CONFIG["user"])
    parser.add_argument("--password", default=DB_CONFIG["password"])
    parser.add_argument("--database", default=DB_CONFIG["database"])
    args = parser.parse_args()

    if args.list:
        for d in DATASETS:
            print(f"{d['table']:<32} <- {d['url']}")
        return

    datasets = DATASETS
    if args.only:
        datasets = [d for d in DATASETS if d["table"] == args.only]
        if not datasets:
            print(f"Tabella sconosciuta: {args.only}. Usa --list per vedere le opzioni.", file=sys.stderr)
            sys.exit(1)

    conn = pymysql.connect(
        host=args.host, port=args.port, user=args.user,
        password=args.password, database=args.database, charset="utf8mb4",
    )
    print(f"Connesso a MariaDB {args.host}:{args.port}/{args.database} come {args.user}")

    started = datetime.now()
    errors = []
    for d in datasets:
        try:
            load_table(conn, d["table"], d["url"], d["header_line"], d["ncols"])
        except Exception as exc:  # noqa: BLE001 - vogliamo continuare con le altre tabelle
            print(f"[{d['table']}] ERRORE: {exc}", file=sys.stderr)
            errors.append((d["table"], str(exc)))

    conn.close()
    elapsed = datetime.now() - started
    print(f"\nCompletato in {elapsed}. {len(datasets) - len(errors)}/{len(datasets)} tabelle OK.")
    if errors:
        print("Tabelle con errori:")
        for table, err in errors:
            print(f"  - {table}: {err}")
        sys.exit(1)


if __name__ == "__main__":
    main()
