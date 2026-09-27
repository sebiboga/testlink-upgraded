#!/usr/bin/env bash
# Refs #1638 - assertion harness for the modern Attachment Delete popup
# (gui/templates/attachments/attachmentDelete.html + api/attachmentsdelete).
# Exits non-zero on the first failing assertion count > 0.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 2

BASE="http://localhost:8082"
API="$BASE/api/attachmentsdelete/index.php"
SCREEN="$BASE/gui/templates/attachments/attachmentDelete.html"
DB="mysql -h127.0.0.1 -P3306 -utestlink -ptestlink -D testlink -N -B -e"
JAR="$(mktemp)"
XHR=(-H 'X-Requested-With: XMLHttpRequest')
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n     expected: %s\n     got:      %s\n' "$1" "$2" "$3"; }
is()   { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "$3" "$2"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "contains '$3'" "$(printf '%.200s' "$2")";; esac; }
hasnt(){ case "$2" in *"$3"*) bad "$1" "NOT contains '$3'" "$(printf '%.200s' "$2")";; *) ok "$1";; esac; }

restore_cfg() { git checkout -- config.inc.php 2>/dev/null; }
trap 'restore_cfg; rm -f "$JAR"' EXIT

# code() -> HTTP status ; body() -> body
code() { curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$@"; }
body() { curl -s -b "$JAR" "$@"; }

echo "== 1. fixtures"
OUT="$(php tmp/fixtures_1638.php 2>&1 | tail -1)"
has "fixture built" "$OUT" "FIXTURE_OK"
fld() { sed -n "s/.*[[:space:]]$1=\([0-9]*\).*/\1/p" <<<"$2"; }
TP=$(fld tproject "$OUT"); TPL=$(fld tplan "$OUT"); SUITE=$(fld suite "$OUT")
TC=$(fld tcase "$OUT"); TCV=$(fld tcv "$OUT"); EXEC=$(fld exec "$OUT")
ATC=$(fld att_tc "$OUT"); AEX=$(fld att_exec "$OUT"); APL=$(fld att_plan "$OUT")
EV_BASE=$($DB "SELECT IFNULL(MAX(id),0) FROM events;" 2>/dev/null)

echo "== 2. authentication (#1639)"
is "anon init -> 401"        "$(curl -s -o /dev/null -w '%{http_code}' "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "401"
has "anon init code"         "$(curl -s "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "NOT_AUTHENTICATED"
is "anon delete -> 401"      "$(curl -s -o /dev/null -w '%{http_code}' -X POST "${XHR[@]}" "$API?action=delete&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "401"
is "row survives anon delete" "$($DB "SELECT COUNT(*) FROM attachments WHERE id=$ATC;")" "1"
is "curl session unauth"     "$(code "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "401"
is "login"                   "$(curl -s -c "$JAR" -o /dev/null -w '%{http_code}' -X POST -d 'tl_login=admin&tl_password=admin' "$BASE/login.php")" "200"
is "authed init -> 200"      "$(code "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "200"

echo "== 3. init payloads (3 owner kinds)"
j() { body "$@" | python3 -c 'import json,sys;d=json.load(sys.stdin);print(json.dumps(d,sort_keys=True))'; }
qa() { python3 -c "import json,sys;print(json.loads(sys.stdin.read())[\"$1\"][\"$2\"])"; }
B=$(j "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")
is  "tc title"      "$(j "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC" | qa attachment title)" "ADEL-1638-testcase"
is  "tc file_name"  "$(j "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC" | qa attachment file_name)" "ADEL-1638-testcase.txt"
is  "tc size"       "$(j "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC" | qa attachment file_size)" "21"
is  "tc owner lbl"  "$(j "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC" | qa attachment owner_label)" "$TC - ADEL test case"
is  "tc owner tbl"  "$(j "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC" | qa attachment owner_table)" "nodes_hierarchy"
is  "tc owner id"   "$(j "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC" | qa attachment owner_id)" "$TC"
is  "tc download"   "$(j "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC" | python3 -c 'import json,sys;print(json.load(sys.stdin)["download_url"])')" "/api/attachments/index.php?action=download&id=$ATC"
is  "tc legacy code" "$(j "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC" | python3 -c 'import json,sys;print(json.load(sys.stdin)["legacy_code"])')" "ADEL-01"
is  "exec owner lbl" "$(j "$API?action=init&id=$AEX&table=executions&fk_id=$EXEC" | qa attachment owner_label)" "$EXEC"
PLBL=$(j "$API?action=init&id=$APL&table=testplans&fk_id=$TPL" | qa attachment owner_label)
is  "plan owner lbl = name #id" "${PLBL##*#}" "$TPL"
has "plan owner lbl has a name"  "$PLBL" "ADEL"
is  "plan owner tbl" "$(j "$API?action=init&id=$APL&table=testplans&fk_id=$TPL" | qa attachment owner_table)" "testplans"
is  "json content-type" "$(curl -s -b "$JAR" -o /dev/null -w '%{content_type}' "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "application/json; charset=utf-8"

echo "== 4. ownership proof / validation"
is  "wrong fk_id -> 403"   "$(code "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TCV")" "403"
has "wrong fk_id code"     "$(body "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TCV")" "ATTACHMENT_NOT_ALLOWED"
is  "wrong table -> 403"   "$(code "$API?action=init&id=$ATC&table=executions&fk_id=$TC")" "403"
is  "table only  -> 403"   "$(code "$API?action=init&id=$ATC&table=nodes_hierarchy")" "403"
is  "fk only     -> 403"   "$(code "$API?action=init&id=$ATC&fk_id=$TC")" "403"
is  "no id       -> 400"   "$(code "$API?action=init&table=nodes_hierarchy&fk_id=$TC")" "400"
has "no id code"           "$(body "$API?action=init&table=nodes_hierarchy&fk_id=$TC")" "INVALID_ID"
is  "id=abc      -> 400"   "$(code "$API?action=init&id=abc&table=nodes_hierarchy&fk_id=$TC")" "400"
is  "id=0        -> 400"   "$(code "$API?action=init&id=0&table=nodes_hierarchy&fk_id=$TC")" "400"
is  "unknown id  -> 404"   "$(code "$API?action=init&id=999999&table=nodes_hierarchy&fk_id=$TC")" "404"
has "unknown id code"      "$(body "$API?action=init&id=999999&table=nodes_hierarchy&fk_id=$TC")" "ATTACHMENT_NOT_FOUND"
is  "unknown action -> 400" "$(code "$API?action=bogus")" "400"
has "unknown action code"  "$(body "$API?action=bogus")" "UNKNOWN_ACTION"

echo "== 5. verb hardening (the legacy page deleted on GET)"
is  "GET delete  -> 405"  "$(code "$API?action=delete&id=$APL&table=testplans&fk_id=$TPL")" "405"
has "GET delete code"    "$(body "$API?action=delete&id=$APL&table=testplans&fk_id=$TPL")" "METHOD_NOT_ALLOWED"
is  "POST init   -> 405"  "$(code -X POST "${XHR[@]}" "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "405"
is  "row survives GET delete" "$($DB "SELECT COUNT(*) FROM attachments WHERE id=$APL;")" "1"

echo "== 6. CSRF / same-origin guard (api/_guard.php)"
is  "POST w/o proof       -> 403" "$(code -X POST "$API?action=delete&id=$APL&table=testplans&fk_id=$TPL")" "403"
is  "POST foreign Origin  -> 403" "$(code -X POST -H 'Origin: https://evil.invalid' "$API?action=delete&id=$APL&table=testplans&fk_id=$TPL")" "403"
is  "POST same-origin Ref -> 200" "$(code -X POST -H 'Referer: http://localhost:8082/x' "$API?action=delete&id=$APL&table=testplans&fk_id=$TPL")" "200"
is  "plan row gone"               "$($DB "SELECT COUNT(*) FROM attachments WHERE id=$APL;")" "0"
is  "re-init deleted  -> 404"     "$(code "$API?action=init&id=$APL&table=testplans&fk_id=$TPL")" "404"
is  "delete again     -> 404"     "$(code -X POST "${XHR[@]}" "$API?action=delete&id=$APL&table=testplans&fk_id=$TPL")" "404"
is  "wrong owner delete -> 403"   "$(code -X POST "${XHR[@]}" "$API?action=delete&id=$ATC&table=executions&fk_id=$EXEC")" "403"

echo "== 7. delete side effects + audit"
R=$(body -X POST "${XHR[@]}" "$API?action=delete&id=$ATC&table=nodes_hierarchy&fk_id=$TC")
has "delete ok"        "$R" '"status":"ok"'
has "delete echoes id" "$R" "\"deleted_id\":$ATC"
has "legacy code"      "$R" '"legacy_code":"ADEL-03"'
is  "row deleted"      "$($DB "SELECT COUNT(*) FROM attachments WHERE id=$ATC;")" "0"
is  "file unlinked"    "$(find upload_area -name 'ADEL-1638-testcase*' 2>/dev/null | wc -l)" "0"
is  "audit row"        "$($DB "SELECT COUNT(*) FROM events WHERE object_type='attachments' AND object_id=$ATC AND log_level=16 AND activity='DELETE';")" "1"
has "audit label"      "$($DB "SELECT description FROM events WHERE object_type='attachments' AND object_id=$ATC AND log_level=16 LIMIT 1;")" "audit_attachment_deleted"
is  "delete 2nd (exec)" "$(code -X POST "${XHR[@]}" "$API?action=delete&id=$AEX&table=executions&fk_id=$EXEC")" "200"
is  "3rd attachment still there (no cascade)" "$($DB "SELECT COUNT(*) FROM attachments WHERE id=$ATC;")" "0"

echo "== 8. attachments disabled (config_get('attachments')->enabled)"
# 1-7 consumed every attachment of the first fixture run: rebuild it
OUT2="$(php tmp/fixtures_1638.php 2>&1 | tail -1)"
has "fixture rebuilt" "$OUT2" "FIXTURE_OK"
ATC=$(fld att_tc "$OUT2"); TC=$(fld tcase "$OUT2"); EXEC=$(fld exec "$OUT2")
EV_BASE2=$($DB "SELECT IFNULL(MAX(id),0) FROM events;" 2>/dev/null)
sed -i 's/^\(\$tlCfg->attachments->enabled[[:space:]]*=[[:space:]]*\)TRUE;/\1FALSE;/' config.inc.php
is  "config toggle applied" "$(grep -c 'attachments->enabled = FALSE;' config.inc.php)" "1"
sleep 3   # opcache.revalidate_freq=2: a request inside that window reuses the cached config.inc.php
is  "init disabled -> 403"    "$(code "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "403"
has "init disabled code"      "$(body "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "ATTACHMENTS_DISABLED"
is  "delete disabled -> 403"  "$(code -X POST "${XHR[@]}" "$API?action=delete&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "403"
restore_cfg
sleep 3   # same opcache window before asserting the re-enabled path
is  "config.inc.php restored" "$(git status --porcelain config.inc.php | wc -l)" "0"
is  "re-enabled -> 200"        "$(code "$API?action=init&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "200"
is  "delete after re-enable"   "$(code -X POST "${XHR[@]}" "$API?action=delete&id=$ATC&table=nodes_hierarchy&fk_id=$TC")" "200"

echo "== 9. legacy shim lib/attachments/attachmentdelete.php"
ANON=$(curl -s "$BASE/lib/attachments/attachmentdelete.php?id=$ATC")
has "anon -> login script"  "$ANON" "login.php?note=expired"
has "anon keeps destination" "$ANON" "destination="
is  "authed -> 302"          "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/lib/attachments/attachmentdelete.php?id=$ATC")" "302"
LOC=$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' "$BASE/lib/attachments/attachmentdelete.php?id=$ATC&table=nodes_hierarchy&fk_id=$TC")
has "302 -> modern popup"    "$LOC" "attachmentDelete.html"
has "302 forwards id"        "$LOC" "id=$ATC"
has "302 forwards table"     "$LOC" "table=nodes_hierarchy"
has "302 forwards fk_id"     "$LOC" "fk_id=$TC"
is  "shim GET did NOT delete" "$($DB "SELECT COUNT(*) FROM attachments WHERE id=$ATC;")" "0"

echo "== 9b. code-review assertions"
has  "401 bounces to login.php"   "$(cat gui/templates/attachments/attachmentDelete.html)" "login.php?note=expired"
has  "CSRF 403 has its own state" "$(cat gui/templates/attachments/attachmentDelete.html)" "adel.errBlocked"
has  "Cancel falls back to index" "$(cat gui/templates/attachmentDelete.html 2>/dev/null || cat gui/templates/attachments/attachmentDelete.html)" "window.history.length > 1"
has  "failed DELETE stays retryable" "$(cat gui/templates/attachments/attachmentDelete.html)" "keepActions"
is   "no dead verb branch"         "$(grep -c 'global \$method' api/attachmentsdelete/index.php)" "0"
is   "json_encode is UTF-8 safe"   "$(grep -c 'JSON_INVALID_UTF8_SUBSTITUTE' api/attachmentsdelete/index.php)" "2"
is   "nosniff header"              "$(grep -c 'X-Content-Type-Options' api/attachmentsdelete/index.php)" "1"
is   "column probe has no LIKE"    "$(grep -c 'SHOW COLUMNS FROM ' api/attachmentsdelete/index.php)" "1"
is   "reqViewVersions ctx (dashio)"   "$(grep -c 'attach_tableName="req_versions"' gui/templates/dashio/requirements/reqViewVersions.tpl)" "2"
is   "reqViewVersions ctx (classic)"  "$(grep -c 'attach_tableName="req_versions"' gui/templates/tl-classic/requirements/reqViewVersions.tpl)" "2"
is   "session list appends (tc)"       "$(grep -c 'getAttachmentInfosFrom($this,$currentVersionID,true,1)\|getAttachmentInfosFrom($this,$version' lib/functions/testcase.class.php)" "2"
is   "session list appends (reqView)"  "$(grep -c 'getAttachmentInfosFrom($req_mgr,$kiwi,true,1)' lib/requirements/reqView.php)" "1"
is   "attach_id escaped in href"       "$(grep -c 'attach_id|escape:' gui/templates/dashio/include/attachments.inc.tpl gui/templates/dashio/include/inc_attachments.tpl gui/templates/tl-classic/attachments.inc.tpl gui/templates/tl-classic/inc_attachments.tpl | grep -c ':1')" "4"

echo "== 10. static wiring + i18n"
for t in gui/templates/dashio/include/inc_attachments.tpl gui/templates/tl-classic/inc_attachments.tpl \
         gui/templates/dashio/include/attachments.inc.tpl  gui/templates/tl-classic/attachments.inc.tpl; do
  has "$t forwards owner" "$(cat "$t")" "deleteAttachment_onClick(b,t,i,'"
done
has "library builds &table=" "$(cat gui/javascript/testlink_library.js)" "&table=\" + encodeURIComponent(table)"
has "library builds &fk_id=" "$(cat gui/javascript/testlink_library.js)" "&fk_id=\" + encodeURIComponent(fkId)"
is  "\$actions->attachmentDelete present" "$(grep -c 'actions->attachmentDelete' lib/functions/common.php)" "1"
is  "shim has no destructive code" "$(grep -c 'deleteAttachment\|attachmentRepository' lib/attachments/attachmentdelete.php)" "0"
is  "BFF is POST-only"      "$(grep -c "delete requires POST" api/attachmentsdelete/index.php)" "1"
is  "BFF fails closed"      "$(grep -c 'NOT_AUTHENTICATED' api/attachmentsdelete/index.php)" "2"
is  "BFF enforces session"  "$(grep -c 'bffEnforceSession' api/attachmentsdelete/index.php)" "1"
python3 - <<'PY'
import json, re, sys
H = open('gui/templates/attachments/attachmentDelete.html', encoding='utf-8').read()
used = set(re.findall(r'data-i18n(?:-placeholder)?="([^"]+)"', H)) | set(re.findall(r"TLi18n\.t\('([^']+)'", H))
base = None; bad = []
for loc in ('de','en','es','fr','it','ja','pt','ro','ru','zh'):
    d = json.load(open(f'gui/templates/i18n/{loc}.json', encoding='utf-8'))
    keys = {k for k in d if k.startswith('adel.')} | ({'footers.attachmentDelete'} & set(d))
    if base is None: base = keys
    elif keys != base: bad.append(f'{loc}: key set differs ({len(keys)} vs {len(base)})')
    miss = [k for k in used if k not in d]
    if miss: bad.append(f'{loc}: missing {sorted(miss)}')
    if loc == 'en' and [k for k in used if k not in d]: bad.append('en: undefined key used by the screen')
print('  \033[32mPASS\033[0m i18n: %d adel.* keys, %d used by the screen, identical in 10 bundles' % (len(base), len(used)))
for b in bad: print('  \033[31mFAIL\033[0m i18n:', b)
sys.exit(1 if bad else 0)
PY
if [ $? -eq 0 ]; then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); fi
for f in gui/templates/i18n/*.json; do python3 -m json.tool "$f" >/dev/null || { FAIL=$((FAIL+1)); echo "  FAIL invalid json $f"; }; done
PASS=$((PASS+1)); ok "all 10 locale bundles are valid JSON"

echo "== 11. served markup: every visible string is an i18n key with an EN fallback"
D=$(curl -s -b "$JAR" "$SCREEN?id=$ATC&table=nodes_hierarchy&fk_id=$TC")
has  "screen is standalone HTML" "$D" "<!DOCTYPE html>"
has  "script is the single entry point" "$D" "var API = '/api/attachmentsdelete/index.php';"
has  "title has data-i18n"       "$D" 'data-i18n="adel.title">Delete Attachment<'
has  "delete button has data-i18n" "$D" 'data-i18n="adel.confirmTitle"'
has  "footer has data-i18n"      "$D" 'data-i18n="footers.attachmentDelete"'
hasnt "no Smarty include"         "$D" '{include'
hasnt "no legacy delete url"     "$D" 'lib/attachments/attachmentdelete.php'
is    "no inline onerror="        "$(grep -c 'onerror=' <<<"$D")" "0"
is    "no hardcoded error text in JS" "$(grep -cE "statusText|alert\(" <<<"$D")" "0"

echo "== 12. Event Viewer: no new ERROR/WARNING from the screen"
$DB "SELECT id,log_level,description FROM events WHERE id>$EV_BASE AND log_level IN (1,2);" > /tmp/adel_ev.txt 2>/dev/null
is  "no new ERROR/FATAL rows" "$(grep -c . /tmp/adel_ev.txt)" "0"
is  "audit DELETE rows"        "$($DB "SELECT COUNT(*) FROM events WHERE id>$EV_BASE AND log_level=16 AND object_type='attachments' AND activity='DELETE';")" "4"
rm -f /tmp/adel_ev.txt

echo
printf 'RESULT: %d PASS / %d FAIL\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
