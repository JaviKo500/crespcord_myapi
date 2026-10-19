#!/bin/bash
#
# Builds the user manual of the app (manual_app/*.md) into static HTML under
# build/manual/, ready for scripts/deploy-manual.sh.
#
#   manual_app/USER_GUIDE.md          -> build/manual/index.html
#   manual_app/guia/03_alicuotas_y_pagos.md -> build/manual/alicuotas-y-pagos/index.html
#   manual_app/assets/*.png           -> build/manual/img/*.png
#   manual_bot/USER_GUIDE.md          -> build/manual/whatsapp/index.html
#   manual_backoffice/USER_GUIDE.md   -> build/manual-backoffice/index.html
#   manual_backoffice/guia/02_pagos.md -> build/manual-backoffice/pagos/index.html
#   manual_backoffice/assets/*.png    -> build/manual-backoffice/img/*.png
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
BACKOFFICE="$SRC/manual_backoffice"
TOOLS="$SCRIPT_DIR/manual"
OUT="$SRC/build/manual"
# Outside $OUT on purpose (SPEC 133): $OUT is published as public static files,
# and the back-office manual must only ever be served through Drupal.
BO_OUT="$SRC/build/manual-backoffice"

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
# its screenshots never collide with the app's. Its screenshots are landscape
# WhatsApp Desktop captures, so it uses the wide layout: at the 280 px phone
# width they shrink to unreadable.
if [ -f "$BOT/USER_GUIDE.md" ]; then
  mkdir -p "$OUT/whatsapp/img"
  cp "$BOT"/assets/*.png "$OUT/whatsapp/img/"
  cp "$SRC/assets/crespcord-icon.png" "$OUT/whatsapp/img/"
  pandoc -f gfm -t html5 --standalone \
    --template="$TOOLS/template.html" \
    --lua-filter="$TOOLS/manual.lua" \
    -M root="" -M source_dir="$BOT" \
    -M footer="CrespCord · Manual del asistente de pagos por WhatsApp" -M wide=true \
    "$BOT/USER_GUIDE.md" -o "$OUT/whatsapp/index.html"
else
  echo "Aviso: no existe manual_bot/USER_GUIDE.md; se omite el manual de WhatsApp." >&2
fi

# The back-office manual (manual_backoffice/), for the 'backend' and
# 'administrador edificio' roles: same layout as the app manual (index plus one
# page per guia/*.md), in its own tree with its own img/. CAPTURAS.md is an
# internal note for whoever maintains it and is never published.
if [ -f "$BACKOFFICE/USER_GUIDE.md" ]; then
  rm -rf "$BO_OUT"
  mkdir -p "$BO_OUT/img"
  cp "$BACKOFFICE"/assets/*.png "$BO_OUT/img/"
  cp "$SRC/assets/crespcord-icon.png" "$BO_OUT/img/"
  render_backoffice() { # <source.md> <output.html> <root>
    pandoc -f gfm -t html5 --standalone \
      --template="$TOOLS/template.html" \
      --lua-filter="$TOOLS/manual.lua" \
      --toc --toc-depth=2 \
      -M root="$3" -M source_dir="$BACKOFFICE" \
      -M footer="CrespCord · Manual del back office" \
      -M brand="Manual del back office" -M wide=true \
      "$1" -o "$2"
  }
  render_backoffice "$BACKOFFICE/USER_GUIDE.md" "$BO_OUT/index.html" ""
  for page in "$BACKOFFICE"/guia/*.md; do
    name="$(basename "$page" .md)"
    slug="$(echo "${name#[0-9][0-9]_}" | tr '_' '-')"
    mkdir -p "$BO_OUT/$slug"
    render_backoffice "$page" "$BO_OUT/$slug/index.html" "../"
  done
else
  echo "Aviso: no existe manual_backoffice/USER_GUIDE.md; se omite el manual del back office." >&2
fi

# Every #anchor must exist as an id in the page it points to.
broken=0
for html in "$OUT"/index.html "$OUT"/*/index.html "$BO_OUT"/index.html "$BO_OUT"/*/index.html; do
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
      echo "Ancla rota en ${html#$SRC/build/}: $href" >&2
      broken=1
    fi
  done < <(grep -o 'href="[^"]*#[^"]*"' "$html" | sed 's/^href="//; s/"$//' | grep -v '^https\?:' || true)
done
[ "$broken" -eq 0 ] || exit 1

echo "Manual generado en build/manual/ ($(find "$OUT" -name index.html | wc -l | tr -d ' ') páginas)."
if [ -d "$BO_OUT" ]; then
  echo "Manual del back office en build/manual-backoffice/ ($(find "$BO_OUT" -name index.html | wc -l | tr -d ' ') páginas), solo para Drupal."
fi
