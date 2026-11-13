#!/usr/bin/env bash
#
# RR20 adversarial runner - EXPERIMENTAL (see RR20-ADVERSARIAL-TEST-PLAN.md).
#
# Two genuinely concurrent HTTP requests hit a disposable integration store:
#   Worker A: paused inside PHP at the final WooCommerce save.
#   Worker B: sent after the claim lease is aged past 15 minutes.
# Then A is resumed (variant "resume") or killed (variant "kill").
#
# Usage: bash docs/m9b/rr20/run-rr20.sh <hpos|legacy> <resume|kill> [final|early]
# Requires: Docker access, a store provisioned with integration/scripts/setup.sh.
#
# Operations performed (review before running): curl POSTs to 127.0.0.1 only;
# `docker compose exec/cp` against the wps-pepita-integration project only;
# SQL UPDATE of one claim row (the synthetic external id created by this run);
# creation/removal of wp-content/rr20 and one mu-plugin inside the disposable
# web container. Nothing outside the integration compose project is touched.
#
set -euo pipefail

SUFFIX="${1:?hpos|legacy}"
VARIANT="${2:?resume|kill}"
MODE="${3:-final}"  # final | early | control
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
source "$ROOT/integration/scripts/common.sh"

case "$SUFFIX" in
	hpos)   SVC=cli-hpos;   WEB=wordpress-hpos;   PORT="${HPOS_PORT:-8080}" ;;
	legacy) SVC=cli-legacy; WEB=wordpress-legacy; PORT="${LEGACY_PORT:-8081}" ;;
	*) echo "bad store" >&2; exit 2 ;;
esac
SITE="http://wordpress-$SUFFIX"

KEY="$(awk -F= '$1=="WPS_ORDER_API_KEY"{print substr($0,index($0,"=")+1)}' "$ENV_FILE")"
EXT="RR20-$(date -u +%s)-$SUFFIX-$VARIANT-$MODE"
OUT="$ROOT/integration/results/rr20-$EXT"
mkdir -p "$OUT"
URL="http://127.0.0.1:$PORT/wp-json/wps/v1/pepita/order?apikey=$KEY"
RR="/var/www/html/wp-content/rr20"

# Candidate identity is recorded with the evidence.
sha256sum "$ROOT/dist/wp-pepita-sync.zip" | tee "$OUT/package.sha256"
git -C "$ROOT" rev-parse HEAD | tee "$OUT/source.sha"

wp() { dc exec -T -e "WPS_IT_SITE_URL=$SITE" -e "WPS_IT_BASE_URL=$SITE" "$SVC" wp --path=/var/www/html "$@"; }
in_web() { dc exec -T "$WEB" sh -ec "$1"; }
DBNAME="wps_${SUFFIX}"
sql() { dc exec -T db sh -ec 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --table "$0" -e "$1"' "$DBNAME" "$1"; }

PFX="$(wp db prefix 2>/dev/null | tr -d '\r\n')"
PFX="${PFX:-wp_}"
CLAIMS="${PFX}wps_pepita_orders"

# --- payload: reuse the CI fixture and seeded products ---------------------
cat > "$OUT/payload.php" <<PHP
<?php
require_once '/integration/tests/bootstrap.php';
echo wp_json_encode( WPS_IT::order_payload( 'order-transfer-unpaid.json', '$EXT' ) );
PHP
dc cp "$OUT/payload.php" "$SVC:/tmp/rr20-payload.php"
wp eval-file /tmp/rr20-payload.php > "$OUT/payload.json"
python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$OUT/payload.json" || { echo "FAIL: payload.json is not valid JSON"; exit 3; }

# --- arm the harness ---------------------------------------------------------
in_web "mkdir -p $RR && echo $MODE > $RR/mode && chmod 777 $RR"
dc cp "$ROOT/docs/m9b/rr20/wps-rr20-pause.php" "$WEB:/var/www/html/wp-content/mu-plugins/wps-rr20-pause.php"
in_web "chmod 644 /var/www/html/wp-content/mu-plugins/wps-rr20-pause.php"

cat > "$OUT/snapshot.php" <<'PHP'
<?php
$ext = getenv( 'RR20_EXT' );
$ids = wc_get_orders( array( 'limit' => 100, 'return' => 'ids', 'status' => 'any', 'cache_results' => false ) );
$rows = array();
foreach ( $ids as $id ) {
	$o = wc_get_order( $id );
	if ( ! $o || $ext !== (string) $o->get_meta( '_wps_pepita_order_id' ) ) { continue; }
	$rows[] = array(
		'id' => $id, 'status' => $o->get_status(), 'in_progress' => $o->get_meta( '_wps_pepita_in_progress' ),
		'imported_at' => $o->get_meta( '_wps_pepita_imported_at' ), 'total' => $o->get_total(), 'items' => count( $o->get_items() ),
		'stock_reduced' => $o->get_meta( '_order_stock_reduced' ), 'notes' => count( wc_get_order_notes( array( 'order_id' => $id ) ) ),
	);
}
$stock = array();
foreach ( wc_get_products( array( 'limit' => 100 ) ) as $p ) { $stock[ $p->get_sku() ] = $p->get_stock_quantity(); }
echo wp_json_encode( array( 'orders' => $rows, 'stock' => $stock ), JSON_PRETTY_PRINT );
PHP
dc cp "$OUT/snapshot.php" "$SVC:/tmp/rr20-snapshot.php"

snapshot() { # $1 label
	sql "SELECT external_id,status,attempts,wc_order_id,LEFT(payload_hash,12) AS hash,created_at,updated_at FROM ${CLAIMS} WHERE external_id='$EXT'" > "$OUT/$1.claims.txt" 2>&1 || true
	dc exec -T -e "RR20_EXT=$EXT" -e "WPS_IT_SITE_URL=$SITE" "$SVC" wp --path=/var/www/html eval-file /tmp/rr20-snapshot.php > "$OUT/$1.state.json" 2>&1 || true
	sql "SELECT COUNT(*) AS orphan_postmeta FROM ${PFX}postmeta pm LEFT JOIN ${PFX}posts p ON p.ID=pm.post_id WHERE p.ID IS NULL" > "$OUT/$1.orphans.txt" 2>&1 || true
	if sql "SHOW TABLES LIKE '${PFX}wc_orders'" 2>/dev/null | grep -q wc_orders; then
		sql "SELECT COUNT(*) AS orphan_order_notes FROM ${PFX}comments c LEFT JOIN ${PFX}wc_orders o ON o.id=c.comment_post_ID WHERE c.comment_type='order_note' AND o.id IS NULL AND c.comment_post_ID NOT IN (SELECT ID FROM ${PFX}posts)" >> "$OUT/$1.orphans.txt" 2>&1 || true
	else
		sql "SELECT COUNT(*) AS orphan_order_notes FROM ${PFX}comments c LEFT JOIN ${PFX}posts p ON p.ID=c.comment_post_ID WHERE c.comment_type='order_note' AND p.ID IS NULL" >> "$OUT/$1.orphans.txt" 2>&1 || true
	fi
	if sql "SHOW TABLES LIKE '${PFX}wc_orders_meta'" 2>/dev/null | grep -q wc_orders_meta; then
		sql "SELECT COUNT(*) AS orphan_hpos_meta FROM ${PFX}wc_orders_meta m LEFT JOIN ${PFX}wc_orders o ON o.id=m.order_id WHERE o.id IS NULL" >> "$OUT/$1.orphans.txt" 2>&1 || true
	fi
}

snapshot 0-before

if [[ "$MODE" == control ]]; then
	# Control: one undisturbed import and one replay; establishes the baseline (stock, status).
	curl -sS -m 60 -o "$OUT/A.body" -w '%{http_code}\n' -H 'Content-Type: application/json' -d @"$OUT/payload.json" "$URL" > "$OUT/A.code"
	echo n/a > "$OUT/B.code"; : > "$OUT/B.body"
	snapshot 3-B-done
	curl -sS -m 60 -o "$OUT/C.body" -w '%{http_code}\n' -H 'Content-Type: application/json' -d @"$OUT/payload.json" "$URL" > "$OUT/C.code"
	snapshot 5-after-retry
else
	# --- Worker A: real HTTP request, blocks inside PHP at the final save ---------
	( curl -sS -m 200 -o "$OUT/A.body" -w '%{http_code}\n' -H 'Content-Type: application/json' -d @"$OUT/payload.json" "$URL" > "$OUT/A.code" 2> "$OUT/A.err" || echo 000 > "$OUT/A.code" ) &
	A_PID=$!

	for _ in $(seq 1 150); do in_web "test -f $RR/entered" && break; sleep 0.2; done
	in_web "test -f $RR/entered" || { echo "FAIL: worker A never reached the pause point (calibrate hook)"; in_web "cat $RR/trace.jsonl" || true; kill "$A_PID" 2>/dev/null || true; exit 3; }
	snapshot 1-A-paused

	# --- Force lease expiry: age the claim past the 15-minute window -------------
	sql "UPDATE ${CLAIMS} SET updated_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE WHERE external_id='$EXT'" > "$OUT/lease-aging.txt" 2>&1
	snapshot 2-lease-aged

	# --- Worker B: competing recovery worker (concurrent with paused A) ----------
	curl -sS -m 60 -o "$OUT/B.body" -w '%{http_code}\n' -H 'Content-Type: application/json' -d @"$OUT/payload.json" "$URL" > "$OUT/B.code" 2> "$OUT/B.err" || echo 000 > "$OUT/B.code"
	snapshot 3-B-done

	# --- Resume or kill A --------------------------------------------------------
	if [[ "$VARIANT" == resume ]]; then
		in_web "touch $RR/resume"
	else
		in_web "kill -9 \$(cat $RR/entered)" || true
	fi
	wait "$A_PID" || true
	sleep 2
	snapshot 4-A-finished

	# --- Retry without manual DB intervention ------------------------------------
	curl -sS -m 60 -o "$OUT/C.body" -w '%{http_code}\n' -H 'Content-Type: application/json' -d @"$OUT/payload.json" "$URL" > "$OUT/C.code" 2> "$OUT/C.err" || echo 000 > "$OUT/C.code"
	snapshot 5-after-retry

fi

dc cp "$WEB:$RR/trace.jsonl" "$OUT/trace.jsonl" || true
dc cp "$WEB:/var/www/html/wp-content/debug.log" "$OUT/debug.log" 2>/dev/null || true

# --- Disarm and remove the harness ------------------------------------------
in_web "rm -rf $RR /var/www/html/wp-content/mu-plugins/wps-rr20-pause.php"

echo "Evidence directory: $OUT"
echo "RR20 $SUFFIX/$VARIANT: A=$(cat "$OUT/A.code") B=$(cat "$OUT/B.code") C=$(cat "$OUT/C.code")"
for f in B C; do echo "--- $f body"; cat "$OUT/$f.body"; echo; done
for l in 0-before 1-A-paused 3-B-done 4-A-finished 5-after-retry; do [[ -f "$OUT/$l.claims.txt" ]] || continue; echo "--- $l claims"; cat "$OUT/$l.claims.txt"; echo "--- $l state"; cat "$OUT/$l.state.json"; echo "--- $l orphans"; cat "$OUT/$l.orphans.txt"; done
echo "--- trace"; cat "$OUT/trace.jsonl" 2>/dev/null || true
python3 "$ROOT/docs/m9b/rr20/evaluate.py" "$OUT" || { echo "RR20 INVARIANT VIOLATION in $SUFFIX/$VARIANT/$MODE"; exit 1; }
