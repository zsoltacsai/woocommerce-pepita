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
dc up -d --wait --remove-orphans db wordpress-hpos wordpress-legacy cli-hpos cli-legacy
source "$ENV_FILE"

status=0

for item in "hpos|http://wordpress-hpos|yes|cli-hpos" "legacy|http://wordpress-legacy|no|cli-legacy"; do
	IFS='|' read -r suffix host expected_hpos service <<< "$item"
	for attempt in $(seq 1 60); do
		if wp_cli "$service" "$host" core is-installed >/dev/null 2>&1; then break; fi
		sleep 2
		if [[ "$attempt" == 60 ]]; then echo "WordPress $suffix is not installed; run integration/scripts/setup.sh first." >&2; exit 2; fi
	done
	{
		echo "=== $(date -u +%FT%TZ) $suffix (expected HPOS=$expected_hpos) ==="
		dc exec -T -e "WPS_IT_SITE_URL=$host" "$service" wp --path=/var/www/html eval-file /integration/tests/seed.php
		dc exec -T -e "WPS_IT_BASE_URL=$host" -e "WPS_IT_EXPECT_HPOS=$expected_hpos" "$service" wp --path=/var/www/html eval-file /integration/tests/run.php
	} 2>&1 | tee -a "$RESULT_FILE" || status=1
done

echo "Results: $RESULT_FILE"
exit "$status"
