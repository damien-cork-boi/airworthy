#!/usr/bin/env bash
# Builds the distributable plugin:
#   1. installs the scan engine (production deps only) from plugin/composer.json
#   2. prefixes it with PHP-Scoper (scoper.inc.php) into the plugin's vendor/ folder
#   3. regenerates the scoped Composer autoloader
#   3b. bundles Action Scheduler (unscoped) in lib/
#   4. copies the plugin source and zips it to dist/airworthy.zip
#
# Usage: bin/build.sh            release build from the committed composer.lock
#        bin/build.sh --update   re-resolve dependencies after editing plugin/composer.json
#        DEV_BUILD=1 bin/build.sh   development build: also includes src/Dev.php (previews)
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
if [ "${1:-}" = "--update" ]; then
	composer update --working-dir="$STAGE" --no-dev --no-interaction --no-progress --prefer-dist --quiet
else
	# Fails if composer.json and composer.lock disagree: engine versions only change on purpose.
	if ! composer validate --working-dir="$STAGE" --no-check-all --no-check-publish --quiet 2>/dev/null; then
		echo "ERROR: plugin/composer.lock is out of date with composer.json. Run: bin/build.sh --update" >&2
		exit 1
	fi
	composer install --working-dir="$STAGE" --no-dev --no-interaction --no-progress --prefer-dist --quiet
fi
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

echo "==> Bundling Action Scheduler (unscoped: it picks the newest copy loaded by any plugin)"
mkdir -p "$OUT/lib"
rsync -a --exclude tests --exclude docs --exclude '*.md' --exclude phpunit.xml.dist --exclude phpcs.xml \
	"$STAGE/vendor/woocommerce/action-scheduler/" "$OUT/lib/action-scheduler/"

echo "==> Copying plugin source"
# src/Dev.php (previews for screenshots/tests) only goes into development builds.
DEV_EXCLUDE="--exclude src/Dev.php"
[ "${DEV_BUILD:-0}" = "1" ] && DEV_EXCLUDE=""
rsync -a --exclude composer.json --exclude composer.lock --exclude vendor $DEV_EXCLUDE "$ROOT/plugin/" "$OUT/"

echo "==> Zipping"
rm -f "$ROOT/dist/$SLUG.zip"
( cd "$BUILD" && zip -qr "$ROOT/dist/$SLUG.zip" "$SLUG" )
rm -rf "$STAGE"

echo "Built $OUT"
echo "Zip:  dist/$SLUG.zip ($(du -h "$ROOT/dist/$SLUG.zip" | cut -f1)), unpacked $(du -sh "$OUT" | cut -f1)"
