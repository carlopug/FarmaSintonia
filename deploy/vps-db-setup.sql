-- ============================================================================
-- Setup del database FarmaSintonia sulla VPS (MySQL 8, istanza già attiva,
-- condivisa con altri progetti già in produzione).
--
-- Da eseguire UNA VOLTA come utente amministrativo MySQL (es. root), PRIMA
-- di caricare db/schema.sql. Crea solo il database e un utente dedicato a
-- permessi minimi, isolato dagli altri schemi/utenti già presenti sulla VPS.
--
-- Uso:
--   mysql -u root -p < deploy/vps-db-setup.sql
--   mysql -u root -p farmasintonia < db/schema.sql
--
-- IMPORTANTE: sostituire CAMBIA_QUESTA_PASSWORD con una password generata
-- (es. `openssl rand -base64 24`) prima di eseguire lo script. La stessa
-- password va poi salvata nel file .env di produzione (mai in git).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS farmasintonia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'farmasintonia'@'localhost' IDENTIFIED BY 'CAMBIA_QUESTA_PASSWORD';

-- Permessi minimi: lettura/scrittura sui dati, isolato dagli altri schemi
-- già presenti sulla VPS (nessun privilegio concesso al di fuori di
-- farmasintonia.*). DROP è incluso non per DDL manuale (le modifiche di
-- struttura restano compito di un utente amministrativo via db/schema.sql),
-- ma perché deploy/import_aifa.py usa TRUNCATE per il refresh idempotente
-- dei dati: TRUNCATE TABLE richiede il privilegio DROP in MySQL (è
-- implementato internamente come drop+ricrea), senza non fallirebbe con
-- errore 1142 "DROP command denied".
GRANT SELECT, INSERT, UPDATE, DELETE, DROP ON farmasintonia.* TO 'farmasintonia'@'localhost';

FLUSH PRIVILEGES;

-- Verifica (facoltativa) dopo l'esecuzione:
-- SHOW GRANTS FOR 'farmasintonia'@'localhost';
