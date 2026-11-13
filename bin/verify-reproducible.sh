#!/usr/bin/env bash
#
# Prove that the distribution ZIP is reproducible.
#
# Builds the committed tree (git archive HEAD, i.e. a clean checkout without
# local edits or leftover dist/ staging files) several times in separate
# directories, in different time zones, and requires:
#   1. identical SHA-256 for every build, and
#   2. the same SHA-256 as the committed dist/wp-pepita-sync.zip.
#
# Usage: bash bin/verify-reproducible.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

committed="$(git -C "$ROOT" show HEAD:dist/wp-pepita-sync.zip | sha256sum | cut -d' ' -f1)"
echo "Committed dist/wp-pepita-sync.zip : $committed"

status=0
declare -a seen=()
i=0
for tz in UTC Europe/Budapest America/Los_Angeles Asia/Tokyo UTC; do
	i=$((i + 1))
	dir="$WORK/build-$i"
	mkdir -p "$dir"
	git -C "$ROOT" archive HEAD | tar -x -C "$dir"
	(cd "$dir" && env -u SOURCE_DATE_EPOCH TZ="$tz" bash bin/build.sh >/dev/null)
	sum="$(sha256sum "$dir/dist/wp-pepita-sync.zip" | cut -d' ' -f1)"
	printf 'Clean build %d (TZ=%-20s): %s\n' "$i" "$tz" "$sum"
	seen+=("$sum")
done

for s in "${seen[@]}"; do
	[[ "$s" == "${seen[0]}" ]] || { echo "FAIL: builds differ across time zones / clean checkouts" >&2; status=1; break; }
done
[[ "${seen[0]}" == "$committed" ]] || { echo "FAIL: committed ZIP differs from a clean rebuild (rebuild with bin/build.sh and commit dist/)" >&2; status=1; }

[[ $status -eq 0 ]] && echo "OK: reproducible, SHA-256 ${seen[0]}"
exit $status
