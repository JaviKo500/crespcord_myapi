#!/bin/bash
#
# Verifies that scripts/local/sanitize.sql left no real e-mail address behind.
#
# sanitize.sql names the columns it cleans, so a text field added to the site
# later would slip through unnoticed. This scans every text column of the
# account, node and field tables for an address outside @example.test and
# fails if it finds one, naming the column to add to sanitize.sql.

set -euo pipefail

. "$(dirname "$0")/lib.sh"

# One "SELECT '<table>.<column>', COUNT(*) ..." per text column, generated
# from information_schema so the list is never out of date.
GENERATOR="
SELECT CONCAT(
  'SELECT ''', table_name, '.', column_name, ''', COUNT(*) FROM \`', table_name,
  '\` WHERE \`', column_name, '\` REGEXP ''[A-Za-z0-9._-]+@(?!example[.]test)[A-Za-z0-9-]+[.][A-Za-z]'' HAVING COUNT(*) > 0;')
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND data_type IN ('varchar', 'text', 'mediumtext', 'longtext')
  AND (table_name LIKE 'dr\\\\_field\\\\_data\\\\_%'
    OR table_name LIKE 'dr\\\\_field\\\\_revision\\\\_%'
    OR table_name IN ('dr_users', 'dr_node', 'dr_node_revision', 'dr_comment'))
"

QUERIES="$(db_root -N -B "$DB_NAME" -e "$GENERATOR")"
# Several hundred statements: through stdin, not as an argument.
LEFT="$(printf '%s\n' "$QUERIES" | db_root -N -B "$DB_NAME")"

if [ -n "$LEFT" ]; then
  echo "Quedan direcciones de correo reales tras el saneado (columna, filas):" >&2
  echo "$LEFT" >&2
  echo "Añade esas columnas a scripts/local/sanitize.sql y vuelve a importar." >&2
  exit 1
fi

echo "Saneado comprobado: ninguna dirección real en cuentas, títulos ni campos de texto."
