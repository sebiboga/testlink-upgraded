#!/usr/bin/env bash
# Verifying harness for issue #1652 - requirement / requirement spec log viewer.
# Non-zero exit on the first FAIL count > 0. Every assertion is echoed PASS/FAIL.
set -u
BASE="http://localhost:8082"
DB="mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -B -e"
API="$BASE/api/logviewer/index.php"
JAR=/tmp/verify_1652_cookies.txt
JAR2=/tmp/verify_1652_norights.txt
JAR3=/tmp/verify_1652_projonly.txt
DEAD=/tmp/verify_1652_dead.txt
P=0; F=0
ok(){ P=$((P+1)); printf 'PASS %s\n' "$1"; }
no(){ F=$((F+1)); printf 'FAIL %s\n' "$1"; }
chk(){ # chk <desc> <expected> <actual>
  if [ "$2" = "$3" ]; then ok "$1 ($3)"; else no "$1 (expected '$2', got '$3')"; fi
}
contains(){ # contains <desc> <needle> <haystack>
  case "$3" in *"$2"*) ok "$1";; *) no "$1 (missing '$2' in: $(printf '%.160s' "$3"))";; esac
}
absent(){ case "$3" in *"$2"*) no "$1 (unexpected '$2')";; *) ok "$1";; esac; }
code(){ curl -s -o /tmp/v1652.body -w '%{http_code}' "$@"; }
jqf(){ python3 -c "import json,sys
d=json.load(open('/tmp/v1652.body'))
try: print(eval('d'+sys.argv[1]))
except Exception as e: print('PARSE_ERR')" "$1" 2>/dev/null; }

echo "=== 0. fixture ==="
FX=$(php tmp/fixtures_1652.php 2>&1)
contains "fixture ran" "FIXTURE_OK" "$FX"
# Parse the machine-readable FIXTURE_OK line. NOTE: the fixture deletes and
# recreates the projects, so ids differ on every run - never hardcode them.
FK=$(printf '%s' "$FX" | grep -o 'FIXTURE_OK .*' | head -1)
fv(){ printf '%s' "$FK" | tr ' ' '\n' | sed -n "s/^$1=//p" | head -1; }
TPROJECT=$(fv tproject); ALT=$(fv alt); SPEC=$(fv spec)
SPEC_REV1=$(fv spec_rev1); SPEC_REV2=$(fv spec_rev2)
SPEC_EMPTY=$(fv spec_empty); SPEC_EMPTY_REV1=$(fv spec_empty_rev1)
SPEC_ALT_REV1=$(fv spec_alt_rev1)
REQ1=$(fv req1); REQ1_V1=$(fv req1_v1); REQ1_V2=$(fv req1_v2)
REQ2=$(fv req2); REQ2_REV=$(fv req2_rev1)
for v in TPROJECT ALT SPEC SPEC_REV1 SPEC_REV2 SPEC_EMPTY SPEC_EMPTY_REV1 \
         SPEC_ALT_REV1 REQ1 REQ1_V1 REQ1_V2 REQ2 REQ2_REV; do
  if [ -n "${!v}" ] && [ "${!v}" -gt 0 ] 2>/dev/null; then
    ok "fixture id $v=${!v}"
  else no "fixture id $v missing"; fi
done
EV0=$($DB "SELECT IFNULL(MAX(id),0) FROM events;")

echo "=== 1. auth ==="
rm -f $JAR $JAR2 $JAR3 $DEAD
code "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT" >/dev/null
chk "anonymous -> 401" 401 "$(code "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "anonymous code" "session_expired" "$(jqf "['code']")"
code "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT" >/dev/null
printf '# Netscape HTTP Cookie File\nlocalhost\tFALSE\t/\tFALSE\t1790587000\tPHPSESSID\tdeadbeefdeadbeefdeadbeefdead\n' > $DEAD
chk "dead session id -> 401" 401 "$(code -b $DEAD "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
curl -s -c $JAR -b $JAR -o /dev/null "$BASE/login.php"
curl -s -c $JAR -b $JAR -o /dev/null -d "tl_login=admin&tl_password=admin" "$BASE/login.php"
chk "admin session -> 200" 200 "$(code -b $JAR "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
contains "admin session cookie present" "PHPSESSID" "$(cat $JAR)"

echo "=== 2. no-rights user -> 403, project-scoped role -> 200 on its own project ==="
BCRYPT=$(php -r 'echo password_hash("Passw0rd!x", PASSWORD_BCRYPT);')
# NB: users.cookie_string carries a UNIQUE key, so every fixture user needs its
# own non-empty value or the second INSERT dies with ER_DUP_ENTRY.
$DB "DELETE FROM users WHERE login IN ('norights1652','projonly1652');" >/dev/null
$DB "DELETE FROM users WHERE cookie_string IN ('1652-norights','1652-projonly');" >/dev/null
$DB "INSERT INTO users (login,password,role_id,email,first,last,locale,active,cookie_string)
     VALUES ('norights1652','$BCRYPT',3,'nr@tl.invalid','No','Rights','en_GB',1,'1652-norights');" >/dev/null
if [ "$($DB "SELECT COUNT(*) FROM users WHERE login='norights1652' AND role_id=3 AND active=1;")" = "1" ]; then
  ok "no-rights fixture user created (role_id 3)"; else no "no-rights fixture user missing"; fi
# A user with NO global rights but a role grant (role 4 = test designer, which
# carries mgt_view_req) on ONE project only. This is the production
# user_testproject_roles path, and it takes a different branch in
# tlUser::hasRight() than either the admin (global) or the role-3 user.
$DB "INSERT INTO users (login,password,role_id,email,first,last,locale,active,cookie_string)
     VALUES ('projonly1652','$BCRYPT',3,'po@tl.invalid','Project','Only','en_GB',1,'1652-projonly');" >/dev/null
PU=$($DB "SELECT id FROM users WHERE login='projonly1652' LIMIT 1;")
if [ -n "$PU" ] && [ "$PU" -gt 0 ] 2>/dev/null; then
  ok "project-scoped fixture user created (global role_id 3)"; else no "project-scoped fixture user missing"; fi
$DB "DELETE FROM user_testproject_roles WHERE user_id=$PU;" >/dev/null
$DB "INSERT INTO user_testproject_roles (user_id,testproject_id,role_id) VALUES ($PU,$TPROJECT,4);" >/dev/null
if [ "$($DB "SELECT COUNT(*) FROM user_testproject_roles WHERE user_id=$PU AND testproject_id=$TPROJECT AND role_id=4;")" = "1" ]; then
  ok "project-scoped role grant stored (role 4 on tproject $TPROJECT)"; else no "project-scoped role grant missing"; fi
rm -f $JAR3
curl -s -c $JAR3 -b $JAR3 -o /dev/null "$BASE/login.php"
curl -s -c $JAR3 -b $JAR3 -o /dev/null -d "tl_login=projonly1652&tl_password=Passw0rd%21x" "$BASE/login.php"
contains "project-scoped user session cookie present" "PHPSESSID" "$(cat $JAR3)"
curl -s -c $JAR2 -b $JAR2 -o /dev/null "$BASE/login.php"
curl -s -c $JAR2 -b $JAR2 -o /dev/null -d "tl_login=norights1652&tl_password=Passw0rd%21x" "$BASE/login.php"
chk "no-rights user -> 403" 403 "$(code -b $JAR2 "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "403 code" "NOT_PERMITTED" "$(jqf "['code']")"

echo "=== 3. happy paths (200) ==="
# object_id is the Doc ID (doc_id column), NOT the numeric row id.
for spec in "requirement_spec_version|$SPEC_REV2|RS-LOGV|rev#2" \
            "requirement_spec_version|$SPEC_EMPTY_REV1|RS-LOGV-EMPTY|rev#1" \
            "requirement_version|$REQ1_V1|REQ-LOGV-1|v1 / rev#1" \
            "requirement_version|$REQ1_V2|REQ-LOGV-1|v2 / rev#1" \
            "requirement|$REQ2_REV|REQ-LOGV-2|rev#1"; do
  IFS='|' read -r ty id oid vlab <<< "$spec"
  c=$(code -b $JAR "$API?action=log&type=$ty&id=$id&tproject_id=$TPROJECT")
  chk "200 $ty id=$id" 200 "$c"
  chk "  status" "ok" "$(jqf "['status']")"
  chk "  object_label resolves (not a name column)" "ok" "$([ -n "$(jqf "['context']['object_label']")" ] && [ "$(jqf "['context']['object_label']")" != "None" ] && echo ok || echo no)"
  chk "  object_id" "$oid" "$(jqf "['context']['object_id']")"
  chk "  parent_name (testproject::getName)" "ok" "$([ -n "$(jqf "['context']['parent_name']")" ] && [ "$(jqf "['context']['parent_name']")" != "None" ] && echo ok || echo no)"
  chk "  version_label" "$vlab" "$(jqf "['context']['version_label']")"
done

echo "=== 4. payload is PLAIN TEXT, never markup ==="
code -b $JAR "$API?action=log&type=requirement_spec_version&id=$SPEC_REV2&tproject_id=$TPROJECT" >/dev/null
TXT=$(jqf "['log']['text']")
# The payload is PLAIN TEXT: the legacy <p> wrapper is normalized away, while
# inner markup survives as literal characters (it is rendered with .text(), so
# it can never become DOM). Presence of "<" is therefore correct, not a leak.
absent "legacy <p> wrapper normalized away" "<p>" "$TXT"
absent "no &lt;p&gt; entity form either" "&lt;p&gt;" "$TXT"
contains "inner <script> kept as literal text" "<script>" "$TXT"
contains "decoded entity kept as text" "&" "$TXT"
contains "markup entities decoded to text" "alert(1)" "$TXT"
contains "ampersand preserved" "&" "$TXT"
contains "newlines preserved" "
" "$TXT"
chk "is_empty false" "False" "$(jqf "['log']['is_empty']")"
code -b $JAR "$API?action=log&type=requirement_spec_version&id=$SPEC_EMPTY_REV1&tproject_id=$TPROJECT" >/dev/null
chk "empty log -> is_empty true" "True" "$(jqf "['log']['is_empty']")"
chk "empty log -> text empty" "" "$(jqf "['log']['text']")"
chk "empty log -> raw_length 0" "0" "$(jqf "['log']['raw_length']")"
chk "empty log -> legacy_note" "empty_log_message" "$(jqf "['log']['legacy_note_empty']")"

echo "=== 5. not found (404) ==="
chk "unknown id -> 404" 404 "$(code -b $JAR "$API?action=log&type=requirement_spec_version&id=99999999&tproject_id=$TPROJECT")"
chk "unknown id -> code" "NOT_FOUND" "$(jqf "['code']")"
chk "wrong node type -> 404" 404 "$(code -b $JAR "$API?action=log&type=requirement_spec_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "foreign project (alt spec, wrong tproject) -> 404" 404 "$(code -b $JAR "$API?action=log&type=requirement_spec_version&id=$SPEC_ALT_REV1&tproject_id=$TPROJECT")"
chk "foreign project (own tproject) -> 200" 200 "$(code -b $JAR "$API?action=log&type=requirement_spec_version&id=$SPEC_ALT_REV1&tproject_id=$ALT")"
chk "requirement alias on spec row -> 404" 404 "$(code -b $JAR "$API?action=log&type=requirement&id=$SPEC_REV2&tproject_id=$TPROJECT")"

echo "=== 6. validation (400) ==="
chk "missing action -> 400" 400 "$(code -b $JAR "$API?type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "unknown action -> 400" 400 "$(code -b $JAR "$API?action=bogus&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "missing type -> 400" 400 "$(code -b $JAR "$API?action=log&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "type traversal -> 400" 400 "$(code -b $JAR "$API?action=log&type=../../etc/passwd&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "missing id -> 400" 400 "$(code -b $JAR "$API?action=log&type=requirement_version&tproject_id=$TPROJECT")"
chk "id=0 -> 400" 400 "$(code -b $JAR "$API?action=log&type=requirement_version&id=0&tproject_id=$TPROJECT")"
chk "id=abc -> 400" 400 "$(code -b $JAR "$API?action=log&type=requirement_version&id=abc&tproject_id=$TPROJECT")"
chk "SQL injection id -> 400" 400 "$(code -b $JAR "$API?action=log&type=requirement_version&id=$REQ1_V1%20OR%201=1&tproject_id=$TPROJECT")"
chk "tproject_id=abc -> 0 (no crash)" 200 "$(code -b $JAR "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=abc")"
$DB "SELECT COUNT(*) FROM req_versions WHERE 1=2 OR id > 0;" >/dev/null

echo "=== 7. verbs (405) + CSRF (403) ==="
# Unsafe verbs without same-origin proof are refused by the shared CSRF guard
# (403) before the method guard is even reached; WITH the proof the method guard
# answers 405. GET is a safe, read-only verb and stays 200.
chk "POST no same-origin proof -> 403" 403 "$(code -b $JAR -X POST "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "PUT no same-origin proof -> 403" 403 "$(code -b $JAR -X PUT "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "DELETE no same-origin proof -> 403" 403 "$(code -b $JAR -X DELETE "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "POST with same-origin proof -> 405" 405 "$(code -b $JAR -H 'X-Requested-With: XMLHttpRequest' -X POST "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "PUT with same-origin proof -> 405" 405 "$(code -b $JAR -H 'X-Requested-With: XMLHttpRequest' -X PUT "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "DELETE with same-origin proof -> 405" 405 "$(code -b $JAR -H 'X-Requested-With: XMLHttpRequest' -X DELETE "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "POST with foreign Origin + proof -> 405" 405 "$(code -b $JAR -H 'Origin: https://evil.invalid' -H 'X-Requested-With: XMLHttpRequest' -X POST "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "PUT with foreign Origin + proof -> 405" 405 "$(code -b $JAR -H 'Origin: https://evil.invalid' -H 'X-Requested-With: XMLHttpRequest' -X PUT "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "GET stays 200 (safe read-only verb)" 200 "$(code -b $JAR -H 'Origin: https://evil.invalid' "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "safe GET still works after CSRF attempts" 200 "$(code -b $JAR "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"

echo "=== 8. legacy shims: escaped fragment for XHR, 302 for a browser ==="
# The legacy Ext.ToolTip call sites are still live and autoLoad these URLs, and
# Ext injects the response BODY as HTML - so an XHR caller must get a FRAGMENT,
# never a redirect (which would dump the whole modern page into the tooltip)
# and never the raw unescaped blob (the legacy XSS).
frag(){ curl -s -b $JAR -H "X-Requested-With: XMLHttpRequest" "$1"; }
shim(){ curl -s -b $JAR -o /dev/null -w '%{redirect_url}' "$1"; }

F1=$(frag "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_REV2")
contains "XHR getreqspeclog returns a fragment" "tlLogFragment" "$F1"
absent "XHR fragment is not a document" "DOCTYPE" "$F1"
absent "XHR fragment carries no <html>" "<html" "$F1"
absent "XHR fragment carries no <script> of its own" "<script" "$F1"
absent "XHR fragment ESCAPES the script tag (legacy XSS closed)" "<script>alert" "$F1"
contains "XHR fragment escapes angle brackets" "&lt;script&gt;" "$F1"
contains "XHR fragment keeps newlines as <br />" "<br />" "$F1"
contains "XHR fragment keeps the ampersand escaped" "&amp;" "$F1"
chk "XHR getreqspeclog on the EMPTY revision -> legacy 'Log Message is empty' hint" "Log Message is empty" "$(frag "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_EMPTY_REV1" | sed -e 's/<[^>]*>//g')"
F2=$(frag "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1")
contains "XHR getreqlog returns a fragment" "tlLogFragment" "$F2"
contains "XHR getreqlog escapes the ampersand" "&amp;" "$F2"
F3=$(frag "$BASE/lib/ajax/getreqlog.php?item_id=$REQ2_REV")
contains "XHR getreqlog revision fragment" "tlLogFragment" "$F3"
absent "XHR getreqlog fragment carries no <script>" "<script" "$F3"
chk "XHR getreqspeclog scopes out a foreign project (empty hint, no log)" "Log Message is empty" "$(frag "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_ALT_REV1&tproject_id=$TPROJECT" | sed -e 's/<[^>]*>//g')"
chk "XHR getreqlog scopes out a foreign project (empty hint, no log)" "Log Message is empty" "$(frag "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1&tproject_id=$ALT" | sed -e 's/<[^>]*>//g')"
absent "XHR getreqlog foreign-project scope leaks no log text" "Version 1 of" "$(frag "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1&tproject_id=$ALT")"

# --- legacy fragment authorization (the 1.9.20 SQL was `WHERE id = <intval>`
#     with NO rights check, so any authenticated user could read any project's
#     log; the shims must now derive the owning project and enforce mgt_view_req
#     even when the caller states no tproject_id) ---
# fragc captures status AND body in ONE curl call: a shared temp file read
# out-of-band would be cross-contaminated by a concurrent CI run.
fragc(){ curl -s -b "$1" -H "X-Requested-With: XMLHttpRequest" -w '\n@@%{http_code}' "$2"; }
# fragc_code / fragc_body split the combined answer.
fragc_code(){ printf '%s' "$1" | tail -1 | sed 's/^@@//'; }
fragc_body(){ printf '%s' "$1" | sed '$d'; }
# fragc_text strips the markup so the assertion compares the exact visible text.
fragc_text(){ printf '%s' "$1" | sed '$d' | sed -e 's/<[^>]*>//g'; }

DENIED='User has not needed right to do requested action'
A=$(fragc $JAR2 "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_REV2")
chk "XHR getreqspeclog for a no-rights user -> 403" 403 "$(fragc_code "$A")"
chk "XHR getreqspeclog denial body is EXACTLY the legacy no-right text" "$DENIED" "$(fragc_text "$A")"
absent "XHR getreqspeclog denial leaks no spec log text" "Second revision of the spec" "$(fragc_body "$A")"
A=$(fragc $JAR2 "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1")
chk "XHR getreqlog for a no-rights user (no tproject_id stated) -> 403" 403 "$(fragc_code "$A")"
absent "XHR getreqlog denial leaks no requirement log text" "Version 1 of" "$(fragc_body "$A")"
A=$(fragc $JAR2 "$BASE/lib/ajax/getreqlog.php?item_id=$REQ2_REV")
chk "XHR getreqlog revision for a no-rights user -> 403" 403 "$(fragc_code "$A")"
A=$(fragc $JAR2 "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_ALT_REV1")
chk "XHR getreqspeclog cross-project for a no-rights user -> 403" 403 "$(fragc_code "$A")"
absent "XHR cross-project denial leaks no ALT log text" "ALT project secret log" "$(fragc_body "$A")"
A=$(fragc $JAR2 "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1&tproject_id=$ALT")
chk "XHR getreqlog with a foreign tproject_id is scoped out (empty hint)" 200 "$(fragc_code "$A")"
absent "XHR getreqlog foreign tproject_id leaks no log text" "Version 1 of" "$(fragc_body "$A")"
# an authorized admin still gets the log, and the admin's browser deep link for a
# REVISION must resolve to type=requirement, not type=requirement_version
A=$(fragc $JAR "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_ALT_REV1")
chk "XHR getreqspeclog cross-project for an admin -> 200" 200 "$(fragc_code "$A")"
contains "XHR getreqspeclog cross-project for an admin still returns the log" "ALT project secret log" "$(fragc_body "$A")"

# --- the PRODUCTION rights path: a user who only holds mgt_view_req through
#     user_testproject_roles on ONE project (tlUser::hasRight() replaces the
#     global rights with the project rights on that branch - neither the global
#     admin nor the role-3 user reaches it) ---
A=$(fragc $JAR3 "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_REV2")
chk "XHR getreqspeclog for a project-scoped role -> 200 on its OWN project" 200 "$(fragc_code "$A")"
contains "XHR getreqspeclog for a project-scoped role returns the log" "Second revision of the spec" "$(fragc_body "$A")"
A=$(fragc $JAR3 "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1")
chk "XHR getreqlog for a project-scoped role -> 200 on its OWN project" 200 "$(fragc_code "$A")"
contains "XHR getreqlog for a project-scoped role returns the log" "Version 1 of" "$(fragc_body "$A")"
A=$(fragc $JAR3 "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_ALT_REV1")
chk "XHR getreqspeclog for a project-scoped role -> 403 on a FOREIGN project" 403 "$(fragc_code "$A")"
absent "XHR project-scoped denial leaks no ALT log text" "ALT project secret log" "$(fragc_body "$A")"
A=$(fragc $JAR3 "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1&tproject_id=$ALT")
# REQ1_V1 lives in $TPROJECT, so a foreign tproject_id scopes the row out
# BEFORE the right is evaluated: not-found (empty hint), never the log.
chk "XHR getreqlog for a project-scoped role with a foreign tproject_id -> scoped out" 200 "$(fragc_code "$A")"
absent "XHR project-scoped foreign scope leaks no log text" "Version 1 of" "$(fragc_body "$A")"
chk "BFF for a project-scoped role -> 200 on its OWN project" 200 "$(code -b $JAR3 "$API?action=log&type=requirement_version&id=$REQ1_V1&tproject_id=$TPROJECT")"
chk "BFF for a project-scoped role -> 403 on a FOREIGN project" 403 "$(code -b $JAR3 "$API?action=log&type=requirement_spec_version&id=$SPEC_ALT_REV1")"

SH1=$(shim "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_REV2")
contains "browser getreqspeclog -> 302 to the modern screen" "logViewer.html?type=requirement_spec_version&id=$SPEC_REV2" "$SH1"
SH2=$(shim "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1&tproject_id=$TPROJECT")
contains "browser getreqlog -> 302 requirement_version" "type=requirement_version&id=$REQ1_V1" "$SH2"
contains "browser getreqlog forwards tproject_id" "tproject_id=$TPROJECT" "$SH2"
SH3=$(shim "$BASE/lib/ajax/getreqlog.php?item_id=$REQ2_REV")
contains "browser getreqlog revision -> 302 requirement (legacy auto-detect)" "type=requirement&id=$REQ2_REV" "$SH3"
absent "browser getreqlog revision is NOT sent to the requirement_version screen" "type=requirement_version" "$SH3"
SH4=$(shim "$BASE/lib/ajax/getreqspeclog.php?item_id=$REQ1_V1&tproject_id=$TPROJECT")
contains "browser getreqspeclog scopes a foreign project -> 302 anyway (BFF answers)" "type=requirement_spec_version&id=$REQ1_V1" "$SH4"
AN1=$(curl -s "$BASE/lib/ajax/getreqspeclog.php?item_id=$SPEC_REV2")
contains "anon getreqspeclog -> login.php note=expired" "login.php?note=expired" "$AN1"
AN2=$(curl -s "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1")
contains "anon getreqlog -> login.php note=expired" "login.php?note=expired" "$AN2"
AN3=$(curl -s -H "X-Requested-With: XMLHttpRequest" "$BASE/lib/ajax/getreqlog.php?item_id=$REQ1_V1")
contains "anon XHR getreqlog -> login.php note=expired" "login.php?note=expired" "$AN3"
absent "anon shim leaks no log text" "Version 1 of" "$AN1$AN2$AN3"
absent "getreqlog has no discarded duplicate query" 'SELECT id FROM nodes_hierarchy' "$(cat lib/ajax/getreqlog.php)"
# the legacy fragment branch must resolve the owning project and check the right;
# needle the actual CALL (a comment mentioning mgt_view_req would pass otherwise)
contains "getreqlog fragment derives the owning test project" "RSPEC.testproject_id " "$(cat lib/ajax/getreqlog.php)"
contains "getreqlog fragment enforces mgt_view_req" "hasRight(\$db, 'mgt_view_req', \$owningProjectId)" "$(cat lib/ajax/getreqlog.php)"
contains "getreqspeclog fragment enforces mgt_view_req" "hasRight(\$db, 'mgt_view_req', \$owningProjectId)" "$(cat lib/ajax/getreqspeclog.php)"
contains "getreqlog revision branch proves the node type" "RN.node_type_id" "$(cat lib/ajax/getreqlog.php)"
contains "getreqspeclog branch proves the node type" "SNH.node_type_id" "$(cat lib/ajax/getreqspeclog.php)"
contains "both shims escape with ENT_SUBSTITUTE" "ENT_SUBSTITUTE" "$(cat lib/ajax/getreqlog.php)$(cat lib/ajax/getreqspeclog.php)"
contains "getreqlog browser deep link auto-detects the node type" "GETREQLOG_TYPE_VERSION" "$(cat lib/ajax/getreqlog.php)"
# The legacy Ext.ToolTip consumers must keep existing and must keep pointing here.
for tpl in reqSpecCompareRevisions reqSpecViewRevision include/reqSpecViewJS.inc \
            reqViewVersions reqViewRevisionRO reqCompareVersions; do
  f="gui/templates/dashio/requirements/$tpl.tpl"
  if [ -f "$f" ]; then
    if grep -q "Ext.ToolTip" "$f" && grep -qE "lib/ajax/(getreqlog|getreqspeclog)\.php\?item_id=" "$f"; then
      ok "legacy tooltip consumer $tpl.tpl still served as a fragment"
    else no "legacy tooltip consumer $tpl.tpl lost its Ext.ToolTip contract"; fi
  else no "legacy tooltip consumer $tpl.tpl missing"; fi
done

echo "=== 9. static checks: BFF ==="
S=api/logviewer/index.php
contains "BFF requires common.php" "common.php" "$(cat $S)"
contains "BFF enforces the session" "bffEnforceSession" "$(cat $S)"
contains "BFF checks mgt_view_req" "mgt_view_req" "$(cat $S)"
contains "BFF checks the owning project" "requested test project" "$(cat $S)"
contains "BFF has a JSON shutdown backstop" "register_shutdown_function" "$(cat $S)"
absent "no dead tproject_name read" "tproject_name" "$(cat $S)"
absent "no nonexistent req_specs.name" "RSPEC.name" "$(cat $S)"
absent "no nonexistent testprojects.name" "TP.name" "$(cat $S)"
contains "test project name via testproject::getName" "testproject::getName" "$(cat $S)"
for f in "$S" lib/ajax/getreqlog.php lib/ajax/getreqspeclog.php lib/functions/common.php; do
  php -l "$f" >/dev/null 2>&1 && ok "php -l $f" || no "php -l $f"
done

echo "=== 10. static checks: screen markup ==="
SC=gui/templates/requirements/logViewer.html
contains "standalone DOCTYPE" "<!DOCTYPE html>" "$(cat $SC)"
absent "no Smarty include" "{include" "$(cat $SC)"
absent "no raw .html() of the log" "logBody').html" "$(cat $SC)"
contains "log body set with .text() (XSS-safe)" "logBody').text(lastText)" "$(cat $SC)"
contains "401 redirects to login" "login.php?note=expired" "$(cat $SC)"
contains "403 renders the access-denied state" "xhr.status === 403" "$(cat $SC)"
contains "404 renders the not-found state" "xhr.status === 404" "$(cat $SC)"
contains "empty log toggles the placeholder" "logEmpty" "$(cat $SC)"
contains "empty log disables Copy" "btnCopy').prop('disabled'" "$(cat $SC)"
contains "the mislabelled row is now Test project" "logv.testProject" "$(cat $SC)"
contains "owning spec Doc ID row added" "logv.specDocId" "$(cat $SC)"
absent "no inline onerror=" "onerror=" "$(cat $SC)"
absent "no hardcoded alert()" "alert(" "$(cat $SC)"

echo "=== 11. static checks: caller wiring ==="
contains "common.php exposes \$actions->logViewer" '$actions->logViewer' "$(cat lib/functions/common.php)"
contains "common.php points at the modern screen" "/gui/templates/requirements/logViewer.html" "$(cat lib/functions/common.php)"
for s in reqSpecView reqSpecCompare reqCompare; do
  f="gui/templates/requirements/$s.html"
  contains "$s defines openLogViewer" "function openLogViewer" "$(cat $f)"
  contains "$s renders the open icon" "log-open" "$(cat $f)"
  contains "$s targets the logViewer screen" "requirements/logViewer.html" "$(cat $f)"
done
# Review: the open affordance must be a real focusable link, not a bare <i>
# (click-only, aria-hidden, unreachable by keyboard/screen reader, and on touch
# the only path to the log since the hover tooltip does not exist there).
for s2 in reqSpecView reqSpecCompare reqCompare; do
  f="gui/templates/requirements/$s2.html"
  contains "$s2 renders a real <a href> affordance" "'<a class=\"fa fa-external-link log-open\"" "$(cat $f)"
  contains "$s2 gives the affordance an href" "logViewerUrl(" "$(cat $f)"
  contains "$s2 gives the affordance an aria-label" "aria-label=\"' +" "$(cat $f)"
  contains "$s2 gives the affordance a focus ring" "log-open:focus-visible" "$(cat $f)"
  contains "$s2 blocks the anchor default" "e.preventDefault();" "$(cat $f)"
  absent "$s2 affordance is not aria-hidden" "log-open\" data-item-id=\"' + rev.item_id +
        '\" title=\"' + TLi18n.t('logv.openFull') + '\" aria-hidden" "$(cat $f)"
done

# The remaining mentions are comments documenting the legacy affordance that was
# replaced, so only non-comment code may be free of the legacy URLs.
# Strip /* ... */ blocks and // line comments textually (the tokenizer cannot
# see JS comments inside an .html file, and a naive line filter misses
# block-comment bodies), then count what is left.
live(){ python3 -c "
import re,sys
t=open(sys.argv[1],encoding='utf-8').read()
t=re.sub(r'/\*.*?\*/','',t,flags=re.S)
t=re.sub(r'//[^\n]*','',t)
print(len(re.findall(re.escape(sys.argv[2]),t)))" "$1" "$2"; }
for pair in "reqSpecView:getreqspeclog.php" "reqSpecCompare:getreqspeclog.php" "reqCompare:getreqlog.php"; do
  s2="${pair%%:*}"; e2="${pair##*:}"
  chk "$s2 makes no live call to $e2" "0" "$(live gui/templates/requirements/$s2.html "$e2")"
done

echo "=== 12. i18n: 24 keys, identical set in all 10 bundles, all valid JSON ==="
EXPKEYS=$(python3 -c "
import json
d=json.load(open('gui/templates/i18n/en.json',encoding='utf-8'))
print(' '.join(sorted(k for k in d if k.startswith('logv.'))))")
NKEYS=$(printf '%s' "$EXPKEYS" | wc -w)
chk "logv.* key count" "24" "$NKEYS"
USED=$(python3 -c "
import re
s=open('gui/templates/requirements/logViewer.html',encoding='utf-8').read()
keys=set(re.findall(r\"logv\.[A-Za-z0-9_]+\",s))
keys.discard('logv.type_')   # built at runtime as 'logv.type_' + c.type
keys |= {'logv.type_requirement_spec_version','logv.type_requirement_version','logv.type_requirement'}
print(' '.join(sorted(keys)))")
for loc in en ro de es fr it pt ru ja zh; do
  f="gui/templates/i18n/$loc.json"
  python3 -m json.tool "$f" >/dev/null 2>&1 && ok "$loc.json valid JSON" || no "$loc.json invalid JSON"
  K=$(python3 -c "
import json
d=json.load(open('$f',encoding='utf-8'))
print(' '.join(sorted(k for k in d if k.startswith('logv.'))))")
  chk "$loc key set matches en" "$EXPKEYS" "$K"
  MISS=""
  for k in $USED; do python3 -c "
import json,sys
d=json.load(open('$f',encoding='utf-8'))
sys.exit(0 if '$k' in d else 1)" || MISS="$MISS $k"; done
  chk "$loc defines every key the screen uses" "" "$MISS"
  EMPTY=$(python3 -c "
import json
d=json.load(open('$f',encoding='utf-8'))
print(' '.join(k for k in d if k.startswith('logv.') and not str(d[k]).strip()))")
  chk "$loc has no empty logv.* value" "" "$EMPTY"
done
chk "en label is Test project" "Test project" "$(python3 -c "import json;print(json.load(open('gui/templates/i18n/en.json',encoding='utf-8'))['logv.testProject'])")"
chk "ro label is Proiect de test" "Proiect de test" "$(python3 -c "import json;print(json.load(open('gui/templates/i18n/ro.json',encoding='utf-8'))['logv.testProject'])")"
absent "en label no longer claims Requirement specification" "Requirement specification" "$(python3 -c "import json;print(json.load(open('gui/templates/i18n/en.json',encoding='utf-8'))['logv.testProject'])")"

echo "=== 13. Event Viewer: no new ERROR/WARNING ==="
NEW=$($DB "SELECT COUNT(*) FROM events WHERE id > $EV0 AND log_level IN (1,2);")
chk "no new log_level IN (1,2) row" "0" "$NEW"
$DB "SELECT id, log_level, LEFT(description,120) FROM events WHERE id > $EV0 AND log_level IN (1,2);"

echo
echo "======================================"
echo "Result: $P PASS / $F FAIL"
echo "======================================"
[ "$F" -eq 0 ] || exit 1
