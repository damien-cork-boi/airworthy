#!/usr/bin/env bash
# Builds the distributable plugin:
#   1. installs the scan engine (production deps only) from plugin/composer.json
#   2. prefixes it with PHP-Scoper (scoper.inc.php) into the plugin's vendor/ folder
#   3. regenerates the scoped Composer autoloader
#   4. copies the plugin source and zips it to dist/airworthy.zip
#
# Usage: bin/build.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="airworthy"
BUILD="$ROOT/build"
STAGE="$BUILD/stage"
OUT="$BUILD/$SLUG"
SCOPER="$ROOT/tools/php-scoper.phar"

if [ ! -f "$SCOPER" ]; then
	mkdir -p "$ROOT/tools"
	curl -sSL -o "$SCOPER" https://github.com/humbug/php-scoper/releases/latest/download/php-scoper.phar
fi

rm -rf "$BUILD"
mkdir -p "$STAGE" "$OUT" "$ROOT/dist"

echo "==> Installing scan engine"
cp "$ROOT/plugin/composer.json" "$STAGE/"
[ -f "$ROOT/plugin/composer.lock" ] && cp "$ROOT/plugin/composer.lock" "$STAGE/"
composer install --working-dir="$STAGE" --no-dev --no-interaction --no-progress --prefer-dist --quiet
# Keep the lock file with the source so every build uses identical engine versions.
cp "$STAGE/composer.lock" "$ROOT/plugin/composer.lock"

echo "==> Scoping under Airworthy\\Vendor"
AIRWORTHY_VENDOR_DIR="$STAGE/vendor" php "$SCOPER" add-prefix \
	--config="$ROOT/scoper.inc.php" \
	--output-dir="$OUT/vendor" \
	--force --no-interaction --quiet
# PHP-Scoper can swallow patcher errors, so check the PHPCS autoloader patches really landed.
AUTOLOAD="$OUT/vendor/squizlabs/php_codesniffer/autoload.php"
if ! grep -q "'Airworthy\\\\\\\\Vendor\\\\\\\\PHP_CodeSniffer\\\\\\\\'" "$AUTOLOAD" \
	|| ! grep -q 'strpos($nsPrefix, ' "$AUTOLOAD"; then
	echo "ERROR: PHP_CodeSniffer autoload patch missing in scoped build (see scoper.inc.php)." >&2
	exit 1
fi
if grep -qE "^define\('T_" "$OUT/vendor/squizlabs/php_codesniffer/src/Util/Tokens.php"; then
	echo "ERROR: unguarded T_* constant definitions left in scoped Tokens.php (see scoper.inc.php)." >&2
	exit 1
fi
# Scoper writes paths relative to the finder root; composer metadata must sit where
# `composer dump-autoload` expects it.
cp "$STAGE/composer.json" "$OUT/composer.json"

echo "==> Regenerating scoped autoloader"
composer dump-autoload --working-dir="$OUT" --classmap-authoritative --no-dev --quiet
rm "$OUT/composer.json"

echo "==> Copying plugin source"
rsync -a --exclude composer.json --exclude composer.lock --exclude vendor "$ROOT/plugin/" "$OUT/"

echo "==> Zipping"
rm -f "$ROOT/dist/$SLUG.zip"
( cd "$BUILD" && zip -qr "$ROOT/dist/$SLUG.zip" "$SLUG" )
rm -rf "$STAGE"

echo "Built $OUT"
echo "Zip:  dist/$SLUG.zip ($(du -h "$ROOT/dist/$SLUG.zip" | cut -f1)), unpacked $(du -sh "$OUT" | cut -f1)"
