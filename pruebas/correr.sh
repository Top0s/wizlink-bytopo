#!/bin/bash
# Prueba de punta a punta del sitio contra una copia local montada como Hostinger (Apache + PHP 8.3, https, con el
# nombre wizlink.bytopo.net apuntando a esta computadora). Nunca contra el sitio real: empieza borrando el acceso, el
# segundo factor, las corridas y la bitácora de la copia.
# Uso: pruebas/correr.sh <zip de una corrida de WizLink> "<nombre de la corrida, AAAA-MM-DD HH.MM>" [carpeta wizlink-private]
set -e
aqui="$(cd "$(dirname "$0")" && pwd)"
privado="${3:-/srv/wizlink/wizlink-private}"
rm -rf "$privado/estado" "$privado/corridas" "$privado/registro"
mkdir -p "$privado" && chown -R www-data:www-data "$privado" 2>/dev/null || true
php "$aqui/e2e.php" "$1" "$2" "$privado"
