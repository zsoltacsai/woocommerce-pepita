#!/usr/bin/env bash
#
# RR20 adversarial runner - UNEXECUTED DRAFT (see RR20-ADVERSARIAL-TEST-PLAN.md).
#
# Two genuinely concurrent HTTP requests hit a disposable integration store:
#   Worker A: paused inside PHP at the final WooCommerce save.
#   Worker B: sent after the claim lease is aged past 15 minutes.
# Then A is resumed (variant "resume") or killed (variant "kill").
#
# Usage: bash docs/m9b/rr20/run-rr20.sh <hpos|legacy> <resume|kill>
# Requires: Docker access, a store provisioned with integration/scripts/setup.sh.
# Never point this at any store other than the integration containers.
#
set -euo pipefail

SUFFIX="${1:?hpos|legacy}"
VARIANT="${2:?resume|kill}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
source "$ROOT/integration/scripts/common.sh"

case "$SUFFIX" in
	hpos)   SVC=cli-hpos;   WEB=wordpress-hpos;   PORT="${HPOS_PORT:-8080}" ;;
	legacy) SVC=cli-legacy; WEB=wordpress-legacy; PORT="${LEGACY_PORT:-8081}" ;;
	*) echo "bad store" >&2; exit 2 ;;
esac

KEY="$(awk -F= '$1=="WPS_ORDER_API_KEY"{print substr($0,index($0,"=")+1)}' "$ENV_FILE")"
EXT="RR20-$(date -u +%s)-$SUFFIX-$VARIANT"
OUT="$ROOT/integration/results/rr20-$EXT"
mkdir -p "$OUT"
URL="http://127.0.0.1:$PORT/wp-json/wps/v1/pepita/order?apikey=$KEY"

# Candidate identity must be recorded with the evidence.
sha256sum "$ROOT/dist/wp-pepita-sync.zip" | tee "$OUT/package.sha256"
git -C "$ROOT" rev-parse HEAD | tee "$OUT/source.sha"

wp() { dc exec -T "$SVC" wp --path=/var/www/html "$@"; }
sh_in_web() { dc exec -T "$WEB" sh -ec "$1"; }

# --- payload: reuse the CI fixture, substituting the seeded product ---------
wp eval "
require '/integration/tests/bootstrap.php';
echo wp_json_encode( WPS_IT::order_payload( 'order-transfer-unpaid.json', '$EXT' ) );
" > "$OUT/payload.json"

# --- arm the harness (mu-plugin copied into the disposable web container) ---
sh_in_web "mkdir -p /var/www/html/wp-content/rr20 && rm -rf /var/www/html/wp-content/rr20/* && touch /var/www/html/wp-content/rr20/mode"
dc cp "$ROOT/docs/m9b/rr20/wps-rr20-pause.php" "$WEB:/var/www/html/wp-content/mu-plugins/wps-rr20-pause.php"
sh_in_web "chown -R www-data:www-data /var/www/html/wp-content/rr20"

snapshot() { # $1 label
	wp db query "SELECT external_id,status,attempts,wc_order_id,payload_hash,updated_at FROM \$(wp db prefix 2>/dev/null || echo wp_)wps_pepita_order_claims WHERE external_id='$EXT'\G" > "$OUT/$1.claims.txt" 2>&1 || true
	wp eval "
\$ids = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => 'any' ) );
\$rows = array();
foreach ( \$ids as \$id ) { \$o = wc_get_order( \$id );
  if ( ! \$o || '$EXT' !== (string) \$o->get_meta( '_wps_pepita_order_id' ) ) { continue; }
  \$rows[] = array( 'id' => \$id, 'status' => \$o->get_status(), 'in_progress' => \$o->get_meta( '_wps_pepita_in_progress' ),
    'imported_at' => \$o->get_meta( '_wps_pepita_imported_at' ), 'total' => \$o->get_total(), 'items' => count( \$o->get_items() ),
    'stock_reduced' => \$o->get_meta( '_order_stock_reduced' ), 'notes' => count( wc_get_order_notes( array( 'order_id' => \$id ) ) ) ); }
echo wp_json_encode( \$rows, JSON_PRETTY_PRINT );
" > "$OUT/$1.orders.json"
	wp eval "echo wp_json_encode( array_map( fn(\$p)=>array( 'sku'=>\$p->get_sku(), 'stock'=>\$p->get_stock_quantity() ), wc_get_products( array( 'limit'=>-1 ) ) ) );" > "$OUT/$1.stock.json"
	wp db query "SELECT COUNT(*) AS orphan_postmeta FROM \$(wp db prefix)postmeta pm LEFT JOIN \$(wp db prefix)posts p ON p.ID=pm.post_id WHERE p.ID IS NULL" > "$OUT/$1.orphans.txt" 2>&1 || true
}

snapshot 0-before

# --- Worker A: real HTTP request, will block inside PHP at the final save ----
curl -sS -m 200 -o "$OUT/A.body" -w '%{http_code}\n' -H 'Content-Type: application/json' -d @"$OUT/payload.json" "$URL" > "$OUT/A.code" &
A_PID=$!

for _ in $(seq 1 100); do sh_in_web "test -f /var/www/html/wp-content/rr20/entered" && break; sleep 0.2; done
sh_in_web "test -f /var/www/html/wp-content/rr20/entered" || { echo "FAIL: worker A never reached the pause point (calibrate hook)"; kill $A_PID 2>/dev/null; exit 3; }
snapshot 1-A-paused

# --- Force lease expiry: age the claim past the 15-minute window -------------
wp db query "UPDATE \$(wp db prefix)wps_pepita_order_claims SET updated_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE WHERE external_id='$EXT'"
snapshot 2-lease-aged

# --- Worker B: competing recovery worker (concurrent with paused A) ----------
curl -sS -m 60 -o "$OUT/B.body" -w '%{http_code}\n' -H 'Content-Type: application/json' -d @"$OUT/payload.json" "$URL" > "$OUT/B.code"
snapshot 3-B-done

# --- Resume or kill A --------------------------------------------------------
if [[ "$VARIANT" == resume ]]; then
	sh_in_web "touch /var/www/html/wp-content/rr20/resume"
	wait $A_PID || true
else
	sh_in_web "kill -9 \$(cat /var/www/html/wp-content/rr20/entered)" || true
	wait $A_PID || true
fi
snapshot 4-A-finished

# --- Retry without manual DB intervention ------------------------------------
curl -sS -m 60 -o "$OUT/C.body" -w '%{http_code}\n' -H 'Content-Type: application/json' -d @"$OUT/payload.json" "$URL" > "$OUT/C.code"
snapshot 5-after-retry

dc cp "$WEB:/var/www/html/wp-content/rr20/trace.jsonl" "$OUT/trace.jsonl" || true
dc cp "$WEB:/var/www/html/wp-content/debug.log" "$OUT/debug.log" 2>/dev/null || true

# --- Disarm and remove the harness ------------------------------------------
sh_in_web "rm -rf /var/www/html/wp-content/rr20 /var/www/html/wp-content/mu-plugins/wps-rr20-pause.php"

echo "Evidence directory: $OUT"
echo "A=$(cat "$OUT/A.code") B=$(cat "$OUT/B.code") C=$(cat "$OUT/C.code")"
echo "Now evaluate the invariants in RR20-ADVERSARIAL-TEST-PLAN.md section 5 against $OUT (do not auto-PASS)."
