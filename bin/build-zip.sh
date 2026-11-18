#!/usr/bin/env bash
# Builds motored-core.zip containing ONLY runtime files.
# Usage: bin/build-zip.sh [output-dir]   (default: ./build)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/build}"
SLUG="motored-core"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

command -v zip >/dev/null 2>&1 || { echo "error: 'zip' is required" >&2; exit 1; }

# Runtime allow-list: anything not listed here never ships.
RUNTIME=(
  "motored-core.php"
  "uninstall.php"
  "readme.txt"
  "LICENSE"
  "src"
  "templates"
  "assets"
  "languages"
)

mkdir -p "$STAGE/$SLUG" "$OUT"
for item in "${RUNTIME[@]}"; do
  if [ -e "$ROOT/$item" ]; then
    cp -R "$ROOT/$item" "$STAGE/$SLUG/$item"
  fi
done

# Strip placeholders and dev-only leftovers from the shipped tree.
find "$STAGE/$SLUG" \( -name '.gitkeep' -o -name '*.md' -o -name '*.po~' -o -name '*.pot~' \) -type f -delete

rm -f "$OUT/$SLUG.zip"
( cd "$STAGE" && zip -rq "$OUT/$SLUG.zip" "$SLUG" )
# Guard: third-party reference copies and dev folders must never ship.
if unzip -l "$OUT/$SLUG.zip" | grep -E "/(reference|tests|docs|vendor|\.claude)/" >/dev/null; then
  echo "error: dev-only folders found in the zip" >&2
  exit 1
fi
echo "Built $OUT/$SLUG.zip"
unzip -l "$OUT/$SLUG.zip" 2>/dev/null | tail -n +4 | head -n -2 || true
