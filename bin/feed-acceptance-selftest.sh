#!/usr/bin/env bash
# Offline self-test of bin/feed-acceptance-check.py against the fixtures in tests/feed-acceptance/.
set -u
cd "$(dirname "$0")/.."
T=tests/feed-acceptance
C="python3 -I bin/feed-acceptance-check.py"
fail=0

$C $T/pass-full.xml $T/pass-stock.xml --staging-host example.test/staging --json /tmp/fac-pass.json >/dev/null
[ $? -eq 0 ] || { echo "FAIL: clean feeds must pass"; fail=1; }

$C $T/pass-full.xml $T/fail-stock.xml --staging-host example.test/staging >/tmp/fac-stock.out
[ $? -eq 1 ] && grep -q "id mismatch" /tmp/fac-stock.out && grep -q "quantity/availability differs" /tmp/fac-stock.out || { echo "FAIL: stock mismatch must be blocking"; fail=1; }

$C $T/fail-full.xml $T/pass-stock.xml --staging-host example.test/staging >/tmp/fac-full.out
[ $? -eq 1 ] && grep -q "duplicate product ids" /tmp/fac-full.out && grep -q "invalid GTIN" /tmp/fac-full.out && grep -q "non-positive price" /tmp/fac-full.out && grep -q "not .jpg" /tmp/fac-full.out || { echo "FAIL: content defects must be blocking"; fail=1; }

# Identifier stability: a baseline that contains an id missing from the new feed must fail.
printf '{"full_ids": ["wps-10","wps-11","wps-404"]}' > /tmp/fac-base.json
$C $T/pass-full.xml $T/pass-stock.xml --staging-host example.test/staging --baseline /tmp/fac-base.json >/tmp/fac-base.out
[ $? -eq 1 ] && grep -q "baseline disappeared" /tmp/fac-base.out || { echo "FAIL: missing baseline id must be blocking"; fail=1; }

# Malformed XML is a usage error (exit 2), not a pass.
echo "<broken" > /tmp/fac-broken.xml
$C /tmp/fac-broken.xml $T/pass-stock.xml --staging-host example.test/staging >/dev/null 2>&1
[ $? -eq 2 ] || { echo "FAIL: malformed XML must exit 2"; fail=1; }

[ $fail -eq 0 ] && echo "feed-acceptance self-test: PASS" || echo "feed-acceptance self-test: FAIL"
exit $fail
