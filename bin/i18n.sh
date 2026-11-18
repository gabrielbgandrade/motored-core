#!/usr/bin/env bash
# Regenerates languages/motored-core.pot, merges es_AR and compiles the .mo.
#
# Preferred tools (GNU gettext): xgettext, msgmerge, msgfmt.
# Optional: WP-CLI ("wp i18n make-pot") produces a better .pot when available.
#
# Fallbacks when a tool is missing:
#   - No xgettext and no WP-CLI: edit languages/motored-core.pot by hand and keep it in sync
#     with every __() / esc_html__() call (grep for "motored-core").
#   - No msgfmt: compile with Python's bundled script, e.g.
#       python3 /usr/lib/python3*/Tools/i18n/msgfmt.py -o languages/motored-core-es_AR.mo languages/motored-core-es_AR.po
#     or any equivalent .po -> .mo compiler. The repo's .mo was generated with a small Python
#     implementation of the GNU .mo format (see docs/PROJECT.md).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LANG_DIR="$ROOT/languages"
POT="$LANG_DIR/motored-core.pot"
PO="$LANG_DIR/motored-core-es_AR.po"
MO="$LANG_DIR/motored-core-es_AR.mo"

have() { command -v "$1" >/dev/null 2>&1; }

cd "$ROOT"

if have wp && wp i18n --help >/dev/null 2>&1; then
  wp i18n make-pot . "$POT" --slug=motored-core --domain=motored-core --exclude=vendor,tests,docs,bin,build,.claude
elif have xgettext; then
  find src motored-core.php uninstall.php -name '*.php' -print0 \
    | xargs -0 xgettext --language=PHP --from-code=UTF-8 \
        --keyword=__ --keyword=_e --keyword=_x:1,2c --keyword=_n:1,2 \
        --keyword=esc_html__ --keyword=esc_attr__ --keyword=esc_html_e --keyword=esc_attr_e \
        --package-name="Motored Core" --package-version="$(grep -m1 'Version:' motored-core.php | awk '{print $NF}')" \
        --add-comments=translators --sort-by-file -o "$POT"
else
  echo "warn: neither 'wp i18n' nor xgettext found; leaving $POT untouched." >&2
fi

if have msgmerge && [ -f "$PO" ]; then
  msgmerge --update --backup=none --no-fuzzy-matching "$PO" "$POT"
else
  echo "warn: msgmerge not found (or no .po); update $PO by hand." >&2
fi

if have msgfmt; then
  msgfmt --check --output-file="$MO" "$PO"
  echo "Compiled $MO"
else
  echo "warn: msgfmt not found; compile $PO to $MO with the fallback in this script's header." >&2
  exit 1
fi
