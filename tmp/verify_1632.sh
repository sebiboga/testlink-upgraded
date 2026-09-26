#!/usr/bin/env bash
# Suite 1632 — Import Platforms standalone screen + api/platformsimport BFF
# Reusable asserting harness. Exits non-zero on ANY failure.
#
# Preconditions (see tmp/TLU_Test_Cases.md, Suite 1632):
#   - app on http://localhost:8082 (docroot = repo root), MariaDB testlink on 127.0.0.1
#   - fixtures from tmp/fixtures_1632.php already executed (prints tproject_id / tplan_id)
# Usage: bash tmp/verify_1632.sh
set -uo pipefail

BASE="http://localhost:8082"
API="$BASE/api/platformsimport/index.php"
SHIM="$BASE/lib/platforms/platformsImport.php"
DB="mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -B -e"
TMPD=$(mktemp -d)
PASS=0; FAIL=0
trap 'rm -rf "$TMPD"' EXIT

# ---------------------------------------------------------------- helpers ---
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n     expected: %s\n     actual:   %s\n' "$1" "$2" "$3"; }
is()   { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "$3" "$2"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "contains '$3'" "$2";; esac; }
hasnt(){ case "$2" in *"$3"*) bad "$1" "NOT containing '$3'" "$2";; *) ok "$1";; esac; }

login() { # $1=user $2=pass $3=cookiejar
  rm -f "$3"
  curl -s -c "$3" -d "tl_login=$1&tl_password=$2" "$BASE/login.php" -o /dev/null
  curl -s -b "$3" -c "$3" "$BASE/index.php" -o /dev/null
}
code() { curl -s -o "$TMPD/body" -w '%{http_code}' "$@"; }
jq_()  { python3 -c "import json,sys;d=json.load(open('$TMPD/body'));print($1)" 2>/dev/null; }

ADMIN="$TMPD/admin.txt"; VIEW="$TMPD/view.txt"; NONE="$TMPD/none.txt"
TP=16; PLAN=17

printf '== Suite 1632 — Import Platforms ==\n\n'

# ------------------------------------------------------- 0. fixtures live ---
if ! $DB "SELECT 1 FROM testprojects WHERE id=$TP" | grep -q 1; then
  printf 'FATAL: project %s missing — run: php tmp/fixtures_1632.php\n' "$TP"; exit 2
fi
ok "fixture project $TP (PIMP) exists"

# ------------------------------------------------- 1. legacy 302 shim -------
printf '\n-- legacy shim --\n'
login admin admin "$ADMIN"
is "shim 302 with project+plan" \
   "$(code -b "$ADMIN" -o /dev/null -w '%{http_code}:%{redirect_url}' "$SHIM?tproject_id=$TP&tplan_id=$PLAN")" \
   "302:$BASE/gui/templates/platforms/platformsImport.html?tproject_id=$TP&tplan_id=$PLAN"
is "shim 302 project only" \
   "$(code -b "$ADMIN" -o /dev/null -w '%{redirect_url}' "$SHIM?tproject_id=$TP")" \
   "$BASE/gui/templates/platforms/platformsImport.html?tproject_id=$TP"
is "shim honours testproject_id/testplan_id aliases" \
   "$(code -b "$ADMIN" -o /dev/null -w '%{redirect_url}' "$SHIM?testproject_id=$TP&testplan_id=$PLAN")" \
   "$BASE/gui/templates/platforms/platformsImport.html?tproject_id=$TP&tplan_id=$PLAN"
is "shim follows through to 200" \
   "$(code -b "$ADMIN" -L -o /dev/null -w '%{http_code}' "$SHIM?tproject_id=$TP&tplan_id=$PLAN")" "200"
has "shim sends anonymous users to the login page" \
   "$(curl -s "$SHIM?tproject_id=$TP")" "login.php?note=expired"

# ------------------------------------------------- 2. init (admin) ---------
printf '\n-- init / context --\n'
is "init 200" "$(code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' "$API?action=init&tproject_id=$TP&tplan_id=$PLAN")" "200"
is "init status ok"        "$(jq_ "d['status']")" "ok"
is "init project name"     "$(jq_ "d['tproject']['name']")" "PIMP"
is "init echoes tplan_id"  "$(jq_ "d['tplan_id']")" "$PLAN"
is "init counts platforms" "$(jq_ "d['platform_count']")" "$($DB "SELECT COUNT(*) FROM platforms WHERE testproject_id=$TP")"
is "init import_types"     "$(jq_ "','.join(d['import_types'])")" "XML"
is "init upload cap 10 MB" "$(jq_ "d['import_limit_bytes']")" "10485760"
is "init doc link root-relative" "$(jq_ "d['file_formats_doc']")" "/docs/tl-file-formats.pdf"
is "init grants platform_management" "$(jq_ "d['grants']['platform_management']")" "yes"

# ------------------------------------------------- 3. import contract ------
printf '\n-- import: create / update / skip --\n'
cat > "$TMPD/good.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<platforms>
  <platform><name>HarnessNew</name><notes>created by suite 1632</notes>
    <enable_on_design>1</enable_on_design><enable_on_execution>1</enable_on_execution><is_open>1</is_open></platform>
  <platform><name>Win11</name><notes>updated by suite 1632</notes>
    <enable_on_design>0</enable_on_design><enable_on_execution>1</enable_on_execution><is_open>0</is_open></platform>
  <platform><notes>no name element</notes></platform>
</platforms>
XML
$DB "DELETE FROM platforms WHERE testproject_id=$TP AND name='HarnessNew'" >/dev/null
is "import 200" "$(code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' -F "tproject_id=$TP" -F "tplan_id=$PLAN" -F "uploadedFile=@$TMPD/good.xml" "$API?action=import")" "200"
is "imported 1"  "$(jq_ "d['imported']")" "1"
is "updated 1"   "$(jq_ "d['updated']")" "1"
is "skipped 1"   "$(jq_ "d['skipped']")" "1"
is "node_count 3" "$(jq_ "d['node_count']")" "3"
is "per-row codes" "$(jq_ "','.join(r['code'] for r in d['ok']+d['ko'])")" "IMPORTED,UPDATED,BAD_LINE"
is "created row persisted"  "$($DB "SELECT notes FROM platforms WHERE testproject_id=$TP AND name='HarnessNew'")" "created by suite 1632"
is "updated row persisted"  "$($DB "SELECT notes FROM platforms WHERE testproject_id=$TP AND name='Win11'")" "updated by suite 1632"
is "update wrote the flags" "$($DB "SELECT CONCAT(enable_on_design,enable_on_execution,is_open) FROM platforms WHERE testproject_id=$TP AND name='Win11'")" "010"
is "imported_total matches DB" "$(jq_ "d['imported_total']")" "$($DB "SELECT COUNT(*) FROM platforms WHERE testproject_id=$TP")"

printf '\n-- import: repeated name in one file --\n'
cat > "$TMPD/dup.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<platforms>
  <platform><name>HarnessDup</name><notes>first</notes><is_open>1</is_open></platform>
  <platform><name>HarnessDup</name><notes>second</notes><is_open>0</is_open></platform>
</platforms>
XML
$DB "DELETE FROM platforms WHERE testproject_id=$TP AND name='HarnessDup'" >/dev/null
code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' -F "tproject_id=$TP" -F "uploadedFile=@$TMPD/dup.xml" "$API?action=import" >/dev/null
is "dup: one import + one update" "$(jq_ "str(d['imported'])+'/'+str(d['updated'])")" "1/1"
is "dup: exactly one row, not two" "$($DB "SELECT COUNT(*) FROM platforms WHERE testproject_id=$TP AND name='HarnessDup'")" "1"
is "dup: 2nd node's values win (regression: update(0,...))" \
   "$($DB "SELECT CONCAT(notes,'/',is_open) FROM platforms WHERE testproject_id=$TP AND name='HarnessDup'")" "second/0"

# ------------------------------------------------- 4. malformed / empty ----
printf '\n-- import: rejected payloads --\n'
printf 'this is definitely not xml at all' > "$TMPD/notxml.xml"
printf '<?xml version="1.0"?><platforms><platform><name>x</notes></platforms>' > "$TMPD/malformed.xml"
printf '<?xml version="1.0" encoding="UTF-8"?>\n<platforms/>\n' > "$TMPD/emptyroot.xml"
: > "$TMPD/zero.xml"

t() { # $1=label $2=file $3=want_code $4=want_error
  is "$1 http" "$(code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' -F "tproject_id=$TP" -F "uploadedFile=@$2" "$API?action=import")" "$3"
  is "$1 error_code" "$(jq_ "d.get('error_code','-')")" "$4"
}
t "not-xml"        "$TMPD/notxml.xml"     422 WRONG_FORMAT
t "malformed-xml"  "$TMPD/malformed.xml"  422 WRONG_FORMAT
t "zero-byte file" "$TMPD/zero.xml"       422 WRONG_FORMAT
has "libxml detail is reported" "$(jq_ "len(d.get('xml_errors',[]))>0")" "True"

is "valid-but-empty root 200" \
   "$(code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' -F "tproject_id=$TP" -F "uploadedFile=@$TMPD/emptyroot.xml" "$API?action=import")" "200"
is "empty root node_count 0" "$(jq_ "d['node_count']")" "0"
is "empty root imported 0"   "$(jq_ "d['imported']")" "0"

dd if=/dev/zero of="$TMPD/big.xml" bs=1024 count=3072 2>/dev/null
printf '<?xml version="1.0"?><platforms><platform><name>Big</name><notes>' > "$TMPD/big.xml"
dd if=/dev/zero bs=1024 count=3072 2>/dev/null >> "$TMPD/big.xml"
printf '</notes></platform></platforms>' >> "$TMPD/big.xml"
is "oversized file 413" \
   "$(code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' -F "tproject_id=$TP" -F "uploadedFile=@$TMPD/big.xml" "$API?action=import")" "413"

# ------------------------------------------------- 5. protocol errors -----
printf '\n-- protocol / id errors --\n'
e() { is "$1" "$(code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' "$2")" "$3"; is "$1 code" "$(jq_ "d.get('error_code','-')")" "$4"; }
e "unknown project"   "$API?action=init&tproject_id=999999" 404 TEST_PROJECT_NOT_FOUND
e "missing project"   "$API?action=init"                    400 INVALID_TPROJECT_ID
e "non-numeric id"    "$API?action=init&tproject_id=abc"    400 INVALID_TPROJECT_ID
e "unknown action"    "$API?action=bogus&tproject_id=$TP"   400 UNKNOWN_ACTION
e "no action"         "$API?tproject_id=$TP"                400 UNKNOWN_ACTION
e "GET on import"     "$API?action=import&tproject_id=$TP"  405 METHOD_NOT_ALLOWED
is "POST on init 405" "$(code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' -X POST "$API?action=init&tproject_id=$TP")" "405"
is "import without a file" \
   "$(code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' -X POST -F "tproject_id=$TP" "$API?action=import")" "422"
is "import without a file code" "$(jq_ "d['error_code']")" "NO_FILE"
is "import without project" \
   "$(code -b "$ADMIN" -H 'X-Requested-With: XMLHttpRequest' -X POST -F "uploadedFile=@$TMPD/good.xml" "$API?action=import")" "400"
is "anonymous init 401" "$(code -H 'X-Requested-With: XMLHttpRequest' "$API?action=init&tproject_id=$TP")" "401"

# ------------------------------------------------- 6. rights + audit ------
printf '\n-- rights and audit trail --\n'
login pimpviewer1632 pimp1632 "$VIEW"
login pimpno1632    pimp1632 "$NONE"

is "viewer init 200 (legacy blocked this page entirely)" \
   "$(code -b "$VIEW" -H 'X-Requested-With: XMLHttpRequest' "$API?action=init&tproject_id=$TP")" "200"
isnt_managed() { [ "$1" = "None" ] || [ "$1" = "" ] || [ "$1" = "null" ]; }
if jq_ "d['grants']['platform_management']" | grep -qiE '^(none|)$|null'; then
  ok "viewer has no platform_management (grants null)"
else bad "viewer has no platform_management (grants null)" "null/None" "$(jq_ "d['grants']['platform_management']")"; fi
is "viewer import 403" \
   "$(code -b "$VIEW" -H 'X-Requested-With: XMLHttpRequest' -F "tproject_id=$TP" -F "uploadedFile=@$TMPD/good.xml" "$API?action=import")" "403"
is "viewer import code" "$(jq_ "d['error_code']")" "NO_RIGHTS"

is "no-rights init 403" \
   "$(code -b "$NONE" -H 'X-Requested-With: XMLHttpRequest' "$API?action=init&tproject_id=$TP")" "403"
is "no-rights init code" "$(jq_ "d['error_code']")" "NO_RIGHTS"
hasnt "no-rights init leaks no project name" "$(cat "$TMPD/body")" '"name"'
hasnt "no-rights init leaks no platform count" "$(cat "$TMPD/body")" 'platform_count'

ge1() { if [ "${1:-0}" -ge 1 ]; then ok "$2"; else bad "$2" ">=1" "${1:-0}"; fi; }
ge1 "$($DB "SELECT COUNT(*) FROM events WHERE object_type='users' AND activity='IMPORT' AND description LIKE '%pimpno1632%view%'")"    "denied view is audited (legacy wrote this via checkUserRightsFor)"
ge1 "$($DB "SELECT COUNT(*) FROM events WHERE object_type='users' AND activity='IMPORT' AND description LIKE '%pimpviewer1632%import%'")" "denied import is audited"

# ------------------------------------------------- 7. i18n / markup -------
printf '\n-- screen assets --\n'
is "screen served" "$(code "$BASE/gui/templates/platforms/platformsImport.html?tproject_id=$TP")" "200"
has "screen loads the i18n module" "$(curl -s "$BASE/gui/templates/platforms/platformsImport.html")" "i18n/i18n.js"
has "screen calls the BFF"        "$(curl -s "$BASE/gui/templates/platforms/platformsImport.html")" "api/platformsimport/index.php"
hasnt "no legacy Smarty include"  "$(curl -s "$BASE/gui/templates/platforms/platformsImport.html")" "dashio/platforms"
has "platformsView launches the standalone screen" \
    "$(curl -s "$BASE/gui/templates/platforms/platformsView.html")" "platformsImport.html"
hasnt "platformsView has no inline import modal left" \
    "$(curl -s "$BASE/gui/templates/platforms/platformsView.html")" "doImport"
is "common.php registers the action" \
   "$(grep -c '\$actions->platformsImport = ' lib/functions/common.php)" "1"

miss=0
for loc in en ro de fr es it pt ru ja zh; do
  for k in $(python3 - <<PY
import json
d = json.load(open("gui/templates/i18n/$loc.json", encoding="utf-8"))
print(" ".join(k for k in d if k.startswith("pimp.")))
PY
  ); do :; done
  python3 -m json.tool "gui/templates/i18n/$loc.json" >/dev/null || { bad "bundle $loc is valid JSON" "valid" "invalid"; miss=1; }
done
[ "$miss" = 0 ] && ok "all 10 locale bundles are valid JSON"

python3 - <<'PY' || exit 1
import json, re, sys
locs = ["en","ro","de","fr","es","it","pt","ru","ja","zh"]
html = open("gui/templates/platforms/platformsImport.html", encoding="utf-8").read()
js = html.split("<script>",1)[1]
used = set(re.findall(r"t\('([A-Za-z0-9_.]+)'", js)) | set(re.findall(r'data-i18n="([A-Za-z0-9_.]+)"', html))
used = {k for k in used if k.startswith("pimp.")}
bad = []
for l in locs:
    d = json.load(open("gui/templates/i18n/%s.json" % l, encoding="utf-8"))
    for k in sorted(used - set(d)):
        bad.append("%s missing %s" % (l, k))
if bad:
    print("  FAIL i18n parity\n    " + "\n    ".join(bad)); sys.exit(1)
print("  ok   every referenced pimp.* key exists in all 10 bundles (%d keys)" % len(used))
PY

printf '\n== %d PASS / %d FAIL ==\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
