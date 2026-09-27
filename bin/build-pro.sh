#!/usr/bin/env bash
# Builds the Airworthy Pro add-on zip: dist/airworthy-pro-<version>.zip (plus build/airworthy-pro/).
# Pro is sold from airworthywp.com, never on WordPress.org, and needs the free plugin.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/pro"
OUT="$ROOT/build/airworthy-pro"
VERSION="$(sed -n "s/.*define( 'AIRWORTHY_PRO_VERSION', '\([^']*\)' ).*/\1/p" "$SRC/airworthy-pro.php")"
[ -n "$VERSION" ] || { echo "Could not read AIRWORTHY_PRO_VERSION." >&2; exit 1; }
grep -q "Version:  *$VERSION\$" "$SRC/airworthy-pro.php" || { echo "Header Version and AIRWORTHY_PRO_VERSION differ." >&2; exit 1; }

rm -rf "$OUT" && mkdir -p "$OUT" "$ROOT/dist"
rsync -a --exclude '.DS_Store' "$SRC/" "$OUT/"
find "$OUT" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
ZIP="$ROOT/dist/airworthy-pro-$VERSION.zip"
rm -f "$ZIP"
( cd "$ROOT/build" && zip -qr "$ZIP" airworthy-pro )
echo "Zip:  dist/airworthy-pro-$VERSION.zip ($(du -h "$ZIP" | cut -f1))"
