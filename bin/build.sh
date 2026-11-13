#!/usr/bin/env bash
#
# Build a byte-reproducible distributable plugin ZIP.
#
# Usage:   bash bin/build.sh
# Output:  dist/wp-pepita-sync.zip (+ dist/wp-pepita-sync.zip.sha256)
#
# Reproducibility contract
# ------------------------
# The same package inputs always produce the same SHA-256, on any machine,
# in any time zone, at any wall-clock time:
#
#   * Time zone is forced to UTC (ZIP stores local DOS timestamps).
#   * Every file and directory timestamp is the release epoch in
#     bin/release-epoch (UTC seconds). It is a committed, versioned value:
#     bump it deliberately when the package inputs change. It never depends on
#     the current time, on git history, or on the commit that contains the
#     generated ZIP (which cannot contain its own commit time).
#     SOURCE_DATE_EPOCH, if set, overrides it (reproducible-builds.org
#     convention); there is NO wall-clock fallback - a missing/invalid epoch
#     fails the build.
#   * Entries are written in a fixed C-locale sorted order, with normalized
#     modes (files 0644, directories 0755), no extra fields (-X), fixed
#     compression level, no owner/group data.
#
# Verify: bash bin/verify-reproducible.sh
#
set -euo pipefail

export TZ=UTC
export LC_ALL=C
umask 022

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="wp-pepita-sync"
DIST="${WPS_DIST_DIR:-$ROOT/dist}"
STAGE="$DIST/$SLUG"
ZIP="$DIST/$SLUG.zip"

command -v zip >/dev/null 2>&1 || {
	echo "Hiba: a 'zip' parancs nem érhető el." >&2
	exit 1
}

# --- Source date (UTC epoch seconds), never the wall clock -------------------
if [[ -n "${SOURCE_DATE_EPOCH:-}" ]]; then
	EPOCH="$SOURCE_DATE_EPOCH"
else
	[[ -f "$ROOT/bin/release-epoch" ]] || { echo "Hiba: hiányzik a bin/release-epoch." >&2; exit 1; }
	EPOCH="$(grep -v '^[[:space:]]*#' "$ROOT/bin/release-epoch" | tr -d '[:space:]')"
fi
[[ "$EPOCH" =~ ^[0-9]{9,11}$ ]] || { echo "Hiba: érvénytelen release epoch: '$EPOCH'." >&2; exit 1; }
# ZIP/DOS timestamps have 2-second resolution: round down to an even second so
# the stored time is exactly the intended one on every zip implementation.
EPOCH=$(( EPOCH - EPOCH % 2 ))

echo "==> Takarítás"
rm -rf "$STAGE" "$ZIP" "$ZIP.sha256"
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

# Normalize modes and timestamps (symlinks are not allowed in the package).
if find "$STAGE" -type l | grep -q .; then
	echo "Hiba: szimbolikus link a csomagban." >&2
	exit 1
fi
find "$STAGE" -type f -exec chmod 644 {} +
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -exec touch -d "@$EPOCH" {} +

echo "==> Csomagolás"
(
	cd "$DIST"
	find "$SLUG" | LC_ALL=C sort | zip -q -X -9 -@ "$ZIP"
)

echo "==> Ellenőrzés"
unzip -tq "$ZIP" >/dev/null
COUNT="$(unzip -Z1 "$ZIP" | wc -l | tr -d ' ')"
SIZE="$(du -h "$ZIP" | cut -f1)"

# Fail loudly if a forbidden path slipped in.
if unzip -Z1 "$ZIP" | grep -Eq '/(tests|bin|dist|\.git)/'; then
	rm -f "$ZIP"
	echo "Hiba: a csomag fejlesztői fájlokat tartalmaz." >&2
	exit 1
fi

(cd "$DIST" && sha256sum "$SLUG.zip" > "$SLUG.zip.sha256")

echo "Kész: $ZIP"
echo "Fájlok: $COUNT, méret: $SIZE, release epoch: $EPOCH (UTC)"
cat "$DIST/$SLUG.zip.sha256"
