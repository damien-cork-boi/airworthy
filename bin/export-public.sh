#!/usr/bin/env bash
# Copies the public parts of this repository into a separate folder that is its own git
# repository: the public source-and-build-tools repo WordPress.org requires (guideline 4).
#
# Usage: bin/export-public.sh [target folder]   (default: ../airworthy-public)
#
# Only listed paths are copied (an allowlist, so new private files never leak by default).
# The internal docs (ACCURACY, FOLLOWUPS, RELEASE, FINDINGS), the engine experiments, the
# website and the brand sources stay private. The export then stops if any copied file
# names the two implode() bugs that haven't been reported to their authors yet.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="${1:-$ROOT/../airworthy-public}"
mkdir -p "$TARGET"
TARGET="$(cd "$TARGET" && pwd)"

if [ "$TARGET" = "$ROOT" ]; then
	echo "ERROR: the target is this repository." >&2
	exit 1
fi

INCLUDE=(
	plugin
	bin/build.sh
	bin/sbom.php
	scoper.inc.php
	composer.json
	composer.lock
	.phpcs.xml.dist
	tests
	engine-test/fixtures/plugins
	engine-test/fixtures/wporg
	docs/ENGINE.md
	docs/VULNERABILITY-HANDLING.md
	docs/sbom.cdx.json
	SECURITY.md
	.github/workflows/ci.yml
)

echo "==> Copying into $TARGET"
# Clear the previous export (keeping its .git), then copy the allowlist.
find "$TARGET" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
for path in "${INCLUDE[@]}"; do
	mkdir -p "$TARGET/$(dirname "$path")"
	# Built libraries (plugin/vendor, a top-level vendor) aren't source. The test fixtures'
	# own vendor/ folders are: the scanner must scan bundled library code too.
	case "$path" in
		engine-test/*) skip=() ;;
		*) skip=( --exclude vendor ) ;;
	esac
	rsync -a "${skip[@]+"${skip[@]}"}" --exclude node_modules --exclude .DS_Store --exclude secret.php "$ROOT/$path" "$TARGET/$(dirname "$path")/"
done
cp "$ROOT/public/README.md" "$TARGET/README.md"
cp "$ROOT/public/LICENSE" "$TARGET/LICENSE"
cat > "$TARGET/.gitignore" <<'EOF'
/build/
/dist/
/tools/
vendor/
!/engine-test/fixtures/plugins/*/vendor/
.DS_Store
# Deliberately unreadable fixture (chmod 000); recreated by tests/run.sh
/engine-test/fixtures/plugins/fx-unreadable/inc/secret.php
EOF

# The website and Airworthy Pro aren't part of the public repo: drop their CI jobs, and
# Pro's paths and text domain from the coding-standards config.
python3 - "$TARGET/.github/workflows/ci.yml" "$TARGET/.phpcs.xml.dist" <<'EOF'
import re, sys
p = sys.argv[1]
s = open(p).read()
s = re.sub(r"\n  site:\n.*?(?=\n  build:\n)", "\n", s, flags=re.S)
s = re.sub(r"\n  plugin-check-pro:\n.*?(?=\n  test:\n)", "\n", s, flags=re.S)
open(p, "w").write(s)
p = sys.argv[2]
s = open(p).read()
s = re.sub(r"\t<file>pro/[^<]*</file>\n", "", s)
s = s.replace("\t\t\t\t<element value=\"airworthy-pro\"/>\n", "")
open(p, "w").write(s)
EOF

echo "==> Checking nothing private was copied"
if grep -rIilE "Advanced_Data_Table|rackspace|opencloud|ObjectStore|reversed implode|wedops|marryhq|/Users/|build-pro|pro/src|airworthy-pro[.\"/]|plugin-check-pro" "$TARGET" --exclude-dir=.git; then
	echo "ERROR: the files above mention something private (see bin/export-public.sh)." >&2
	exit 1
fi

if [ ! -d "$TARGET/.git" ]; then
	git -C "$TARGET" init -q -b main
fi
echo "Exported $(find "$TARGET" -type f ! -path '*/.git/*' | wc -l | tr -d ' ') files to $TARGET"
echo "Review, then: git -C \"$TARGET\" add -A && git -C \"$TARGET\" commit -m \"Airworthy X.Y.Z\" && git -C \"$TARGET\" push"
