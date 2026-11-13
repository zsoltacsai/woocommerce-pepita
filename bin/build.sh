#!/usr/bin/env bash
#
# Build a distributable plugin ZIP.
#
# Usage: bash bin/build.sh
# Output: dist/wp-pepita-sync.zip
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="wp-pepita-sync"
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"
ZIP="$DIST/$SLUG.zip"

command -v zip >/dev/null 2>&1 || {
	echo "Hiba: a 'zip' parancs nem érhető el." >&2
	exit 1
}

echo "==> Takarítás"
rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

echo "==> Fájlok másolása"
cp "$ROOT/woocommerce-pepita.php" "$STAGE/"
cp "$ROOT/uninstall.php" "$STAGE/"
cp "$ROOT/README.md" "$STAGE/"
cp "$ROOT/CHANGELOG.md" "$STAGE/"
cp "$ROOT/LICENSE" "$STAGE/" 2>/dev/null || true
cp -R "$ROOT/includes" "$STAGE/includes"
cp -R "$ROOT/assets" "$STAGE/assets"

# Remove development leftovers that must never ship.
find "$STAGE" -name '.DS_Store' -delete
find "$STAGE" -name '*.map' -delete
find "$STAGE" -type f -exec chmod 644 {} \;
find "$STAGE" -type d -exec chmod 755 {} \;

# Normalize timestamps for reproducible builds.
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --pretty=%ct 2>/dev/null || date +%s)}"
find "$STAGE" -exec touch -d "@$SOURCE_DATE_EPOCH" {} +

echo "==> Csomagolás"
(
	cd "$DIST"
	zip -rq -X "$ZIP" "$SLUG"
)

echo "==> Ellenőrzés"
unzip -tq "$ZIP" >/dev/null
COUNT="$(unzip -Z1 "$ZIP" | wc -l | tr -d ' ')"
SIZE="$(du -h "$ZIP" | cut -f1)"

echo "Kész: $ZIP"
echo "Fájlok: $COUNT, méret: $SIZE"

# Fail loudly if a forbidden path slipped in.
if unzip -Z1 "$ZIP" | grep -Eq '/(tests|bin|dist|\.git)/'; then
	echo "Hiba: a csomag fejlesztői fájlokat tartalmaz." >&2
	exit 1
fi
