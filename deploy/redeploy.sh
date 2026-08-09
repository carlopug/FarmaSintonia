#!/usr/bin/env bash
# Redeploy di FarmaSintonia sulla VPS: git pull + sistemazione permessi.
#
# La cartella del progetto deve restare di proprietà di www-data (perché
# Apache/php-fpm possano leggerla), ma un
# `git pull` lanciato da un utente diverso da www-data (es. carlodev) non
# può scrivere in quella cartella così com'è. Questo script fa il giro
# completo in automatico: riprende la proprietà, fa il pull, la restituisce
# a www-data.
#
# Uso (dalla cartella del progetto, o da qualunque altra):
#   deploy/redeploy.sh
#
# Se il pull si interrompe per un conflitto, lo script si ferma prima di
# restituire la proprietà a www-data: la cartella resta tua, così puoi
# risolvere il conflitto senza lottare anche con i permessi.

set -euo pipefail

PROGETTO_DIR="/var/www/html/farmasintonia"
UTENTE_DEPLOY="$(whoami)"
UTENTE_WEB="www-data"

cd "$PROGETTO_DIR"

echo "==> Riprendo la proprietà come ${UTENTE_DEPLOY} per poter scrivere"
sudo chown -R "${UTENTE_DEPLOY}:${UTENTE_DEPLOY}" "$PROGETTO_DIR"

echo "==> git pull"
git pull

echo "==> Ripristino la proprietà a ${UTENTE_WEB} (necessaria per Apache/php-fpm)"
sudo chown -R "${UTENTE_WEB}:${UTENTE_WEB}" "$PROGETTO_DIR"

echo "==> Reload PHP8.3 FPM"
sudo systemctl reload php8.3-fpm

echo "==> Fatto."
echo "    Nota: se composer.json/composer.lock sono cambiati in questo pull,"
echo "    esegui anche 'composer install --no-dev --optimize-autoloader'"
echo "    prima di rimettere i permessi (o rilancia lo script dopo)."
