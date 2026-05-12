#!/usr/bin/env bash
#
# verify-tracking.sh
#
# Post-deploy smoke test for the server-side tracking stack.
# Run from local machine OR from Hostinger SSH — pings the live endpoints
# and prints what landed.
#
# Usage:
#   ./scripts/verify-tracking.sh [base_url]
#
# Defaults to https://moonlightotakunights.com if no base URL is given.

set -euo pipefail

BASE="${1:-https://moonlightotakunights.com}"
TEST_EMAIL="qa+$(date +%s)@moonlightotakunights.com"
EVENT_ID="verify_$(date +%s)_$RANDOM"

echo "=== verify-tracking ==="
echo "base url:  $BASE"
echo "test email: $TEST_EMAIL"
echo "event_id:   $EVENT_ID"
echo

# ---- 1. Health check ----
echo "[1] health check on /api/track-beacon.php (expect 405 to GET)"
HTTP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/api/track-beacon.php")
echo "    HTTP $HTTP"
if [ "$HTTP" != "405" ] && [ "$HTTP" != "200" ]; then
  echo "    ✗ beacon endpoint not reachable"
  exit 1
fi
echo "    ✓ endpoint reachable"
echo

# ---- 2. Fire a scroll_depth beacon ----
echo "[2] POST scroll_depth beacon"
RESP=$(curl -s -X POST "$BASE/api/track-beacon.php" \
  -H 'Content-Type: application/json' \
  -d "{\"event\":\"scroll_depth\",\"label\":\"75\",\"url\":\"$BASE/?qa=1\",\"event_id\":\"$EVENT_ID\"}")
echo "    response: $RESP"
echo "$RESP" | grep -q '"ok":true' && echo "    ✓ beacon accepted" || { echo "    ✗ beacon rejected"; exit 1; }
echo

# ---- 3. Fire a Guild signup (Lead conversion) ----
echo "[3] POST guild-signup (Lead conversion)"
GUILD_RESP=$(curl -s -X POST "$BASE/api/guild-signup.php" \
  -H 'Content-Type: application/json' \
  -d "{\"name\":\"QA Verify\",\"email\":\"$TEST_EMAIL\",\"source\":\"guild_homepage\"}")
echo "    response: $GUILD_RESP"
echo "$GUILD_RESP" | grep -q '"ok":true' && echo "    ✓ signup accepted" || { echo "    ✗ signup rejected"; exit 1; }
echo

echo "=== next steps ==="
echo "1. Meta Events Manager → Test Events tab → confirm 'Lead' + 'ViewContent' arrive within 30s"
echo "2. GA4 Realtime → confirm 'generate_lead' and 'view_content' fired"
echo "3. SSH to Hostinger and run:"
echo "   mysql -u u833453975_mon_admin -p u833453975_mon_dashboard \\"
echo "     -e \"SELECT event_name,event_id,http_status,created_at FROM tracking_log ORDER BY id DESC LIMIT 10;\""
echo "4. Confirm the rows for event_id=$EVENT_ID and the Lead have http_status=200"
echo
echo "done."
