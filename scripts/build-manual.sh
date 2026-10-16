#!/bin/bash
#
# Builds the user manual of the app (manual_app/*.md) into static HTML under
# build/manual/, ready for scripts/deploy-manual.sh.
#
#   manual_app/USER_GUIDE.md          -> build/manual/index.html
#   manual_app/guia/03_alicuotas_y_pagos.md -> build/manual/alicuotas-y-pagos/index.html
#   manual_app/assets/*.png           -> build/manual/img/*.png
#   manual_bot/USER_GUIDE.md          -> build/manual/whatsapp/index.html
#
# The conversion itself (link rewriting, screenshot grouping, pager) lives in
# scripts/manual/manual.lua; the page layout and CSS in scripts/manual/template.html.
# Fails on a link to a missing page, a missing screenshot or an anchor that
# does not exist in the target page.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"
MANUAL="$SRC/manual_app"
BOT="$SRC/manual_bot"
TOOLS="$SCRIPT_DIR/manual"
OUT="$SRC/build/manual"

command -v pandoc >/dev/null || { echo "pandoc no está instalado." >&2; exit 1; }

rm -rf "$OUT"
mkdir -p "$OUT/img"

cp "$MANUAL"/assets/*.png "$OUT/img/"
cp "$SRC/assets/crespcord-icon.png" "$OUT/img/"
cp "$TOOLS/htaccess" "$OUT/.htaccess"

render() { # <source.md> <output.html> <root>
  pandoc -f gfm -t html5 --standalone \
    --template="$TOOLS/template.html" \
    --lua-filter="$TOOLS/manual.lua" \
    --toc --toc-depth=2 \
    -M root="$3" -M source_dir="$MANUAL" \
    "$1" -o "$2"
}

echo "Generando el manual..."

render "$MANUAL/USER_GUIDE.md" "$OUT/index.html" ""
for page in "$MANUAL"/guia/*.md; do
  name="$(basename "$page" .md)"
  # Same rule as slug_of() in manual.lua: drop the order prefix, _ -> -.
  slug="$(echo "${name#[0-9][0-9]_}" | tr '_' '-')"
  mkdir -p "$OUT/$slug"
  render "$page" "$OUT/$slug/index.html" "../"
done

# The WhatsApp bot manual (manual_bot/, copied from the crespcord-lambda repo):
# a single page with its own numbered index, so no --toc, and its own img/ so
# its screenshots never collide with the app's.
if [ -f "$BOT/USER_GUIDE.md" ]; then
  mkdir -p "$OUT/whatsapp/img"
  cp "$BOT"/assets/*.png "$OUT/whatsapp/img/"
  cp "$SRC/assets/crespcord-icon.png" "$OUT/whatsapp/img/"
  pandoc -f gfm -t html5 --standalone \
    --template="$TOOLS/template.html" \
    --lua-filter="$TOOLS/manual.lua" \
    -M root="" -M source_dir="$BOT" \
    -M footer="CrespCord · Manual del asistente de pagos por WhatsApp" \
    "$BOT/USER_GUIDE.md" -o "$OUT/whatsapp/index.html"
else
  echo "Aviso: no existe manual_bot/USER_GUIDE.md; se omite el manual de WhatsApp." >&2
fi

# Every #anchor must exist as an id in the page it points to.
broken=0
for html in "$OUT"/index.html "$OUT"/*/index.html; do
  dir="$(dirname "$html")"
  while IFS= read -r href; do
    target="${href%%#*}"
    anchor="${href#*#}"
    if [ -z "$target" ]; then
      file="$html"
    else
      file="$dir/${target}index.html"
      [ "$target" = "./" ] && file="$dir/index.html"
    fi
    if [ ! -f "$file" ] || ! grep -q "id=\"$anchor\"" "$file"; then
      echo "Ancla rota en ${html#$OUT/}: $href" >&2
      broken=1
    fi
  done < <(grep -o 'href="[^"]*#[^"]*"' "$html" | sed 's/^href="//; s/"$//' | grep -v '^https\?:' || true)
done
[ "$broken" -eq 0 ] || exit 1

echo "Manual generado en build/manual/ ($(find "$OUT" -name index.html | wc -l | tr -d ' ') páginas)."
