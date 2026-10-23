#!/bin/bash
# Smoke test for a built image: starts it, scans a real file over HTTP and
# checks the result, history, deletion and security headers.
#
#   tests/smoke.sh [image]        (default: ai-scanner:latest)
set -euo pipefail

IMAGE="${1:-ai-scanner:latest}"
NAME="scanner-smoke-$$"
PORT="${SMOKE_PORT:-18080}"
BASE="http://127.0.0.1:$PORT"
DIR="$(cd "$(dirname "$0")" && pwd)"
JAR="$(mktemp)"
FAILED=0

cleanup() {
  [ "$FAILED" = 1 ] && docker logs "$NAME" 2>&1 | tail -30
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  rm -f "$JAR"
}
trap cleanup EXIT

fail() { echo "FAIL $1"; FAILED=1; exit 1; }
ok() { echo "  ok   $1"; }

docker run -d --name "$NAME" -p "127.0.0.1:$PORT:8080" "$IMAGE" >/dev/null

# MariaDB initialises on first start; give it up to a minute
for _ in $(seq 60); do
  curl -sf -o /dev/null "$BASE/" && break
  sleep 1
done
curl -sf -o /dev/null "$BASE/" || fail "page did not come up"
ok "dashboard responds"

HEADERS="$(curl -sI "$BASE/")"
grep -qi '^content-security-policy:.*default-src' <<<"$HEADERS" || fail "Content-Security-Policy header missing"
grep -qi '^x-frame-options: *deny' <<<"$HEADERS" || fail "X-Frame-Options header missing"
grep -qi '^x-powered-by' <<<"$HEADERS" && fail "PHP version is exposed"
ok "security headers set, PHP not exposed"

[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/../app/Detector.php")" = 404 ] || fail "app/ is reachable"
ok "app code not served"

PAGE="$(curl -s -c "$JAR" -b "$JAR" "$BASE/")"
TOKEN="$(grep -oP 'name="csrf" content="\K[^"]+' <<<"$PAGE")"
[ -n "$TOKEN" ] || fail "no CSRF token on the page"

BAD="$(curl -s -b "$JAR" -H 'X-CSRF-Token: wrong' -F "image=@$DIR/fixtures/camera.png" "$BASE/scan.php")"
grep -q '"ok":false' <<<"$BAD" || fail "scan accepted a wrong CSRF token"
ok "wrong CSRF token rejected"

SCAN="$(curl -s -b "$JAR" -H "X-CSRF-Token: $TOKEN" -F "image=@$DIR/fixtures/camera.png" "$BASE/scan.php")"
grep -q '"ok":true' <<<"$SCAN" || fail "scan failed: $SCAN"
grep -q '"level":"camera"' <<<"$SCAN" || fail "camera photo not recognised: $SCAN"
ok "real photo scanned as camera data"

SCAN="$(curl -s -b "$JAR" -H "X-CSRF-Token: $TOKEN" -F "image=@$DIR/fixtures/c2pa-algorithmic.jpg" "$BASE/scan.php")"
grep -q '"level":"likely"' <<<"$SCAN" || fail "C2PA declaration not detected: $SCAN"
ok "C2PA manifest read inside the container"

HIST="$(curl -s "$BASE/history.php")"
grep -q '"total":2' <<<"$HIST" || fail "history does not hold 2 scans: $HIST"
ok "scans saved to the database"

ID="$(grep -oP '"scans":\[\{"id":\K[0-9]+' <<<"$HIST")"
DEL="$(curl -s -b "$JAR" -H "X-CSRF-Token: $TOKEN" -d "id=$ID" "$BASE/delete.php")"
grep -q '"deleted":1' <<<"$DEL" || fail "delete one failed: $DEL"
DEL="$(curl -s -b "$JAR" -H "X-CSRF-Token: $TOKEN" -d "all=1" "$BASE/delete.php")"
grep -q '"deleted":1' <<<"$DEL" || fail "clear all failed: $DEL"
grep -q '"total":0' <<<"$(curl -s "$BASE/history.php")" || fail "history not empty after clear"
ok "delete one and clear all"

echo "smoke test passed"
