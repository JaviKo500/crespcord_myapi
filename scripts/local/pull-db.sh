#!/bin/bash
#
# Dumps the production database to .local/dumps/prod-raw-<timestamp>.sql.gz.
#
# Read-only on the server and nothing is written there: the dump is streamed
# compressed over SSH, and the credentials are read from settings.php into a
# process substitution, never into a file.
#
# mysqldump is used directly because `drush sql-dump --tables-list` is ignored
# by this Drush and dumps the whole database. Every table keeps its structure;
# the ones listed in NO_DATA_PATTERNS come without rows.
#
# The file holds REAL personal data until scripts/local/import-db.sh has
# sanitized it; import-db.sh deletes it when it is done.
#
#   --yes   skip the confirmation prompt

set -euo pipefail

. "$(dirname "$0")/lib.sh"
[ "${1:-}" = "--yes" ] && MYAPI_LOCAL_ASSUME_YES=1
load_prod_target

# Tables dumped without rows. SQL LIKE patterns on the name after the dr_
# prefix (so _ and % must be escaped when literal).
NO_DATA_PATTERNS=(
  'cache' 'cache\_%'              # rebuilt on demand
  'watchdog' 'accesslog'          # logs
  'sessions'                      # live sessions of real users
  'flood' 'semaphore' 'batch'     # transient state
  'history'                       # per-user "read" marks
  'queue'                         # pending pushes and mails to real people
  'search\_index' 'search\_dataset' 'search\_total' 'search\_node\_links'
  'ctools\_object\_cache' 'ctools\_css\_cache'
  'views\_data\_export\_object\_cache'
  'feeds\_log' 'job\_schedule'    # no scheduled feed imports in local
  'rules\_scheduler'             # scheduled reminders to real people
  'my\_api\_tokens'               # access/refresh tokens of real sessions
  'myapi\_password\_reset\_tokens'
)
if [ "${DUMP_SKIP_FIELD_REVISIONS:-1}" = "1" ]; then
  # ~6.7 GB of old revisions. Current values live in dr_field_data_*.
  NO_DATA_PATTERNS+=('field\_revision\_%')
fi

WHERE=""
for pattern in "${NO_DATA_PATTERNS[@]}"; do
  WHERE="${WHERE:+$WHERE OR }table_name LIKE 'dr\\_${pattern}'"
done

require_free_mb 3072
confirm_prod "Se va a volcar la base de producción con mysqldump --single-transaction (sin datos de caché, logs, sesiones, colas ni revisiones de campos)."

STAMP="$(date +%Y%m%d-%H%M)"
OUT="$DUMPS/prod-raw-$STAMP.sql.gz"
PARTIAL="$OUT.partial"
trap 'rm -f "$PARTIAL"' EXIT

echo "Volcando producción a $OUT (tarda unos minutos)..."

# Quoted heredoc: nothing below is expanded locally. NO_DATA_WHERE travels as
# the first argument of the remote shell.
ssh -i "$PROD_KEY" -o BatchMode=yes -o ServerAliveInterval=30 "$PROD_SERVER" \
  "bash -s -- $(printf '%q' "$WHERE")" > "$PARTIAL" <<'REMOTE'
set -euo pipefail
NO_DATA_WHERE="$1"
SETTINGS=/var/www/html/sites/default/settings.php

# [client] section built from settings.php, only ever seen through a pipe.
client_cnf() {
  sudo php -r 'include $argv[1]; $d = $databases["default"]["default"];
    echo "[client]\nuser=\"{$d["username"]}\"\npassword=\"{$d["password"]}\"\nhost={$d["host"]}\n";
    if (!empty($d["port"])) { echo "port={$d["port"]}\n"; }' "$SETTINGS"
}
DB="$(sudo php -r 'include $argv[1]; echo $databases["default"]["default"]["database"];' "$SETTINGS")"

IGNORE=()
while read -r table; do
  [ -n "$table" ] && IGNORE+=("--ignore-table=$DB.$table")
done < <(mysql --defaults-extra-file=<(client_cnf) -N -B "$DB" \
  -e "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND ($NO_DATA_WHERE)")
echo "Tablas sin datos: ${#IGNORE[@]}" >&2

{
  # 1) structure of every table; 2) rows of the ones that keep them.
  mysqldump --defaults-extra-file=<(client_cnf) --single-transaction --quick --no-data "$DB"
  mysqldump --defaults-extra-file=<(client_cnf) --single-transaction --quick \
    --no-create-info --skip-triggers "${IGNORE[@]}" "$DB"
} | nice -n 19 gzip -c
REMOTE

gzip -t "$PARTIAL"
# mysqldump ends every successful run with this comment; the stream holds two.
COMPLETED="$(gzip -dc "$PARTIAL" | grep -c '^-- Dump completed' || true)"
if [ "$COMPLETED" != "2" ]; then
  echo "El volcado está incompleto (marcas de fin: $COMPLETED de 2). Se descarta." >&2
  exit 1
fi

chmod 600 "$PARTIAL"
mv "$PARTIAL" "$OUT"
trap - EXIT

# Only the newest raw dump is kept.
find "$DUMPS" -name 'prod-raw-*.sql.gz' ! -name "$(basename "$OUT")" -delete

echo "Volcado completo: $OUT ($(du -h "$OUT" | cut -f1))."
echo "Contiene datos reales sin sanear. Siguiente paso: make import"
