#!/usr/bin/env bash
# Regression harness for issue #1625 — reqMgrSystemView.php?id=<n> empty HTTP 500.
# Asserts and exits non-zero on failure (so it can never "pass" against broken code).
#
# Usage: bash tmp/verify_1625.sh
# Preconditions: app on http://localhost:8082, MariaDB testlink@127.0.0.1:3306.
set -u
BASE="http://localhost:8082"
COOKIE=$(mktemp)
TMPD=$(mktemp -d)
PASS=0; FAIL=0
MYSQL="mysql -N -h 127.0.0.1 -utestlink -ptestlink testlink"

say() { printf '%s\n' "$*"; }
ok()   { PASS=$((PASS+1)); say "  PASS  $1"; }
ko()   { FAIL=$((FAIL+1)); say "  FAIL  $1"; }

# --- login (2.0.1 BFF, needs same-origin proof) -------------------------------
curl -s -c "$COOKIE" -b "$COOKIE" \
  -H "Origin: $BASE" -H "Referer: $BASE/login.php" \
  -d "login=admin&password=admin" "$BASE/api/auth/login" > /dev/null
if curl -s -b "$COOKIE" "$BASE/index.php" | grep -q "location.href='$BASE/login.php'"; then
  say "FATAL: login failed (still redirected to the login page)"; rm -f "$COOKIE"; rm -rf "$TMPD"; exit 2
fi

cleanup() { $MYSQL -e "DELETE FROM reqmgrsystems WHERE name IN ('TLU1625 Contour','TLU1625 Ghost');" >/dev/null 2>&1
            rm -f "$COOKIE"; rm -rf "$TMPD"; }
trap cleanup EXIT INT TERM

# --- fixture: a contour (type 1) row, i.e. an implementation class that is not shipped
$MYSQL -e "DELETE FROM reqmgrsystems WHERE name='TLU1625 Contour';" >/dev/null 2>&1
$MYSQL -e "INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('TLU1625 Contour',1,'{}');" >/dev/null
RID=$($MYSQL -e "SELECT id FROM reqmgrsystems WHERE name='TLU1625 Contour';")

events_max() { $MYSQL -e "SELECT COALESCE(MAX(id),0) FROM events;"; }
php_warn_new(){ $MYSQL -e "SELECT COUNT(*) FROM events WHERE id > $1 AND log_level=2;"; }

# --- case 1: a real row with a type whose class cannot be loaded ---------------
say "Case 1: ?id=$RID (existing type-1 row) -> 200, KO badge, no new PHP warning"
W=$(events_max)
CODE=$(curl -s -b "$COOKIE" -o "$TMPD/1.html" -w '%{http_code}' "$BASE/lib/reqmgrsystems/reqMgrSystemView.php?id=$RID")
BYTES=$(wc -c < "$TMPD/1.html")
[ "$CODE" = "200" ] && ok "HTTP 200 (pre-fix: 500 with 0 bytes)" || ko "HTTP $CODE (expected 200)"
[ "$BYTES" -gt 5000 ] && ok "body rendered ($BYTES bytes)" || ko "body only $BYTES bytes (expected a real page)"
grep -q 'TLU1625 Contour' "$TMPD/1.html" && ok "the probed row is listed" || ko "the probed row is missing"
grep -q 'fa-times-circle' "$TMPD/1.html" && ok "KO badge rendered (pre-existing localized badge)" || ko "no KO badge"
[ "$(php_warn_new $W)" = "0" ] && ok "0 new E_WARNING rows" || ko "$(php_warn_new $W) new E_WARNING rows"

# --- case 2: a non-existent id must not 500 nor invent a row -----------------
say "Case 2: ?id=99999 (no such row) -> 200, exactly one data row, no phantom"
CODE=$(curl -s -b "$COOKIE" -o "$TMPD/2.html" -w '%{http_code}' "$BASE/lib/reqmgrsystems/reqMgrSystemView.php?id=99999")
[ "$CODE" = "200" ] && ok "HTTP 200 (pre-fix: 500)" || ko "HTTP $CODE (expected 200)"
# expected <tr> = 1 header + one per existing reqmgrsystems row; a phantom row would make it +1
EXPECTED_TR=$(($($MYSQL -e "SELECT COUNT(*) FROM reqmgrsystems;") + 1))
ROWS=$(grep -c '<tr>' "$TMPD/2.html")
[ "$ROWS" = "$EXPECTED_TR" ] && ok "$ROWS <tr> = header + $((EXPECTED_TR-1)) real row(s), no phantom" \
                               || ko "$ROWS <tr> (expected $EXPECTED_TR -> a phantom row was added)"

# --- case 3: listing without id must stay clean (the #1593 noise) ------------
say "Case 3: no id -> 200 and 0 new E_WARNING rows (was 2 per request)"
W=$(events_max)
CODE=$(curl -s -b "$COOKIE" -o "$TMPD/3.html" -w '%{http_code}' "$BASE/lib/reqmgrsystems/reqMgrSystemView.php")
[ "$CODE" = "200" ] && ok "HTTP 200" || ko "HTTP $CODE (expected 200)"
[ "$(php_warn_new $W)" = "0" ] && ok "0 new E_WARNING rows" || ko "$(php_warn_new $W) new E_WARNING rows"
grep -q 'TLU1625 Contour' "$TMPD/3.html" && ok "row still listed" || ko "row disappeared"

# --- case 4: degenerate ids never reach the probe ---------------------------
say "Case 4: ?id=abc / id=0 / id=-1 -> 200, no probe, unchanged listing"
W=$(events_max)
for q in "id=abc" "id=0" "id=-1"; do
  CODE=$(curl -s -b "$COOKIE" -o "$TMPD/4.html" -w '%{http_code}' "$BASE/lib/reqmgrsystems/reqMgrSystemView.php?$q")
  [ "$CODE" = "200" ] && ok "?$q -> HTTP 200" || ko "?$q -> HTTP $CODE"
  grep -q 'fa-times-circle' "$TMPD/4.html" && ko "?$q ran the probe (KO badge present)" || ok "?$q did not run the probe"
done
[ "$(php_warn_new $W)" = "0" ] && ok "0 new E_WARNING rows across the 3 requests" || ko "$(php_warn_new $W) new E_WARNING rows"

# --- case 5: a type code absent from \$systems must not be fatal -------------
say "Case 5: a row whose type is not a key of \$systems -> 200, row still listed"
$MYSQL -e "DELETE FROM reqmgrsystems WHERE name='TLU1625 Ghost';" >/dev/null 2>&1
$MYSQL -e "INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('TLU1625 Ghost',99,'{}');" >/dev/null
GID=$($MYSQL -e "SELECT id FROM reqmgrsystems WHERE name='TLU1625 Ghost';")
CODE=$(curl -s -b "$COOKIE" -o "$TMPD/5.html" -w '%{http_code}' "$BASE/lib/reqmgrsystems/reqMgrSystemView.php?id=$GID")
[ "$CODE" = "200" ] && ok "HTTP 200 (no fatal)" || ko "HTTP $CODE (expected 200)"
grep -q 'TLU1625 Ghost' "$TMPD/5.html" && ok "the row is still listed" || ko "the row is not listed"
$MYSQL -e "DELETE FROM reqmgrsystems WHERE name='TLU1625 Ghost';" >/dev/null 2>&1

# --- case 6: the two hardened twins must not regress ------------------------
say "Case 6: issueTrackerView.php / codeTrackerView.php still 200 with 0 new warnings"
W=$(events_max)
for p in "/lib/issuetrackers/issueTrackerView.php" "/lib/codetrackers/codeTrackerView.php"; do
  CODE=$(curl -s -b "$COOKIE" -o /dev/null -w '%{http_code}' "$BASE$p")
  [ "$CODE" = "200" ] && ok "$p -> HTTP 200" || ko "$p -> HTTP $CODE"
done
[ "$(php_warn_new $W)" = "0" ] && ok "0 new E_WARNING rows" || ko "$(php_warn_new $W) new E_WARNING rows"

# --- case 7: the modernized 2.0.1 BFF that fronts this area ---------------
say "Case 7: api/reqmgrsystems/index.php (modern screen) still returns the row"
curl -s -b "$COOKIE" -H "Referer: $BASE/" -o "$TMPD/7.json" "$BASE/api/reqmgrsystems/index.php"
grep -q '"status":"ok"' "$TMPD/7.json" && ok "BFF status ok" || ko "BFF did not return status ok"
grep -q 'TLU1625 Contour' "$TMPD/7.json" && ok "row present in the BFF payload" || ko "row missing from the BFF payload"

# --- case 8: syntax + the edit screen of the same area ----------------------
say "Case 8: php -l on both touched files, and the ReqMgr edit screen with a valid action"
php -l lib/functions/tlReqMgrSystem.class.php >/dev/null 2>&1 && ok "php -l tlReqMgrSystem.class.php" || ko "php -l tlReqMgrSystem.class.php"
php -l lib/reqmgrsystems/reqMgrSystemView.php >/dev/null 2>&1 && ok "php -l reqMgrSystemView.php" || ko "php -l reqMgrSystemView.php"
CODE=$(curl -s -b "$COOKIE" -o /dev/null -w '%{http_code}' "$BASE/lib/reqmgrsystems/reqMgrSystemEdit.php?doAction=create")
[ "$CODE" = "200" ] && ok "reqMgrSystemEdit.php?doAction=create -> HTTP 200" || ko "-> HTTP $CODE"

# --- case 9: the SECOND copy of the probe (edit screen "check connection") ----
say "Case 9: reqMgrSystemEdit.php?doAction=checkConnection -> 200, no fatal (was 500/0 B)"
W=$(events_max)
for u in "doAction=checkConnection&id=$RID" "doAction=checkConnection&id=99999" "doAction=checkConnection"; do
  CODE=$(curl -s -b "$COOKIE" -o /dev/null -w '%{http_code}' "$BASE/lib/reqmgrsystems/reqMgrSystemEdit.php?$u")
  [ "$CODE" = "200" ] && ok "?$u -> HTTP 200" || ko "?$u -> HTTP $CODE (expected 200)"
done
# The 3 warnings this route logs come from a DIFFERENT pre-existing defect
# (reqMgrSystemCommands.class.php:72 reads $obj->l18n['checkConnection'], which
# init_labels() never fills in this class) that the 500 used to mask. It is tracked
# as its own bug; here we only assert that the repaired path adds nothing itself.
WARN_FROM_FIX=$($MYSQL -e "SELECT COUNT(*) FROM events WHERE id > $W AND log_level=2
   AND (description LIKE '%contoursoap%' OR description LIKE '%tlReqMgrSystem%');")
[ "$WARN_FROM_FIX" = "0" ] && ok "0 new E_WARNING rows from the repaired connection probe" \
                            || ko "$WARN_FROM_FIX new E_WARNING rows from the repaired probe"
say "  INFO  $(( $(php_warn_new $W) - ${WARN_FROM_FIX} )) further E_WARNING row(s) from the still-open"
say "  INFO  reqMgrSystemCommands::initGuiBean() l18n gap (its own bug), not from this fix"

# --- cleanup ---------------------------------------------------------------
cleanup

say ""
say "verify_1625.sh: $PASS PASS / $FAIL FAIL"
[ "$FAIL" = "0" ] || exit 1
exit 0
