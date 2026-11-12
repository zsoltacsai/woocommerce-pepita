#!/usr/bin/env bash
set -euo pipefail
source "$(dirname -- "$0")/common.sh"

RESULT_DIR="$PROJECT_DIR/integration/results"
mkdir -p "$RESULT_DIR"
RESULT_FILE="$RESULT_DIR/m8-$(date -u +%Y%m%dT%H%M%SZ).log"
if ! docker info >"$RESULT_FILE" 2>&1; then
	echo "BLOCKED: Docker daemon unavailable; no WordPress/WooCommerce integration cases ran." | tee -a "$RESULT_FILE" >&2
	cat "$RESULT_FILE" >&2
	exit 2
fi
dc up -d --wait db wordpress-hpos wordpress-legacy cli
source "$ENV_FILE"

status=0

for item in "hpos:http://wordpress-hpos:yes" "legacy:http://wordpress-legacy:no"; do
	IFS=: read -r suffix host expected_hpos <<< "$item"
	site_path="/var/www/html/$suffix"
	for attempt in $(seq 1 60); do
		if wp_cli "$site_path" "$host" core is-installed >/dev/null 2>&1; then break; fi
		sleep 2
		if [[ "$attempt" == 60 ]]; then echo "WordPress $suffix is not installed; run integration/scripts/setup.sh first." >&2; exit 2; fi
	done
	{
		echo "=== $(date -u +%FT%TZ) $suffix (expected HPOS=$expected_hpos) ==="
		dc exec -T -e "WPS_IT_BASE_URL=$host" -e "WPS_IT_EXPECT_HPOS=$expected_hpos" cli wp --path="$site_path" eval-file /integration/tests/run.php
	} 2>&1 | tee -a "$RESULT_FILE" || status=1
done

echo "Results: $RESULT_FILE"
exit "$status"
