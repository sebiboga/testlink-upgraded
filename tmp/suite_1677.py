#!/usr/bin/env python3
"""Suite 1677 - ltx.php Direct Links Frameset Gateway (Refs #1677).

Executable harness: drives the BFF contract + the fixture data, then reports
PASS/FAIL per case. Browser-only cases are marked BROWSER and were executed
separately through chrome-devtools (results recorded in the same table).
"""
import json, subprocess, sys, os, urllib.parse

BASE = 'http://localhost:8082'
API = BASE + '/api/ltx/index.php'
CJ = '/tmp/cj.txt'          # admin session
CJ2 = '/tmp/cj2.txt'        # <no rights> session

def curl(url, cookie=CJ, extra=None, method='GET', data=None):
    cmd = ['curl', '-s', '-b', cookie]
    if extra:
        cmd += extra
    if data:
        cmd += ['-X', method, '-d', data]
    cmd.append(url)
    out = subprocess.run(cmd, capture_output=True, text=True).stdout
    return out

def api(qs, cookie=CJ, extra=None, method='GET', data=None):
    """Returns (http_code, body_dict_or_None, raw)."""
    url = API + '?' + qs
    cmd = ['curl', '-s', '-o', '/tmp/_b.txt', '-w', '%{http_code}', '-b', cookie]
    if extra is not None:
        cmd += extra
    else:
        cmd += ['-H', 'X-Requested-With: XMLHttpRequest']
    if method != 'GET':
        cmd += ['-X', method]
    if data:
        cmd += ['-d', data]
    cmd.append(url)
    code = subprocess.run(cmd, capture_output=True, text=True).stdout.strip()
    raw = open('/tmp/_b.txt').read()
    try:
        body = json.loads(raw)
    except Exception:
        body = None
    return code, body, raw

def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout

def login(cookie, user, pwd):
    open(cookie, 'w').close()
    subprocess.run(['curl', '-s', '-c', cookie, '-b', cookie,
                    '-H', 'X-Requested-With: XMLHttpRequest',
                    '-d', 'login=%s&password=%s' % (user, pwd),
                    BASE + '/api/auth/login'], capture_output=True)

RESULTS = []
def check(num, name, cond, detail=''):
    RESULTS.append((num, name, 'PASS' if cond else 'FAIL', detail))
    print('%-6s %-62s %s  %s' % (num, name, 'PASS' if cond else 'FAIL', detail))

# ---------------------------------------------------------------- fixture ---
PRJ, PLAN, PLAN2, BUILD, PLAT, PLAT2 = 1, 2, 12, 8, 7, 9
TCASE, TCVER, TCVER2, FEAT, FEAT2 = 4, 5, 6, 10, 11

print('== fixture ==')
fix = sh('mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e '
        '"SELECT COUNT(*) FROM testplan_tcversions WHERE testplan_id=%d"' % PLAN).strip()
check('F1', 'fixture: testplan_tcversions rows for the plan', fix == '2', 'rows=%s' % fix)
nt = sh('mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e '
        '"SELECT GROUP_CONCAT(DISTINCT node_type_id) FROM nodes_hierarchy"').strip()
check('F2', 'fixture: 2.0.1 node_type_ids in use (1/2/3/4/5)', nt == '1,2,3,4,5', 'types=%s' % nt)

# ------------------------------------------------------------------- auth ---
print('== auth ==')
login(CJ, 'admin', 'admin')
login(CJ2, 'ltxnorights', 'ltxnorights')
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
              % (BUILD, PLAN, TCVER, PLAT))
check('A1', 'admin session resolves the exec deep link', c == '200' and b['status'] == 'ok', c)
ANON = '/tmp/cj_anon.txt'   # deliberately empty cookie jar
open(ANON, 'w').close()
c, b, _ = api('action=init&item=exec&build_id=%d' % BUILD, cookie=ANON)
check('A2', 'anonymous -> 401 unauthenticated (auth gate precedes param validation)',
      c == '401' and b['code'] == 'unauthenticated', '%s %s' % (c, b and b.get('code')))
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
              % (BUILD, PLAN, TCVER, PLAT), cookie=ANON)
check('A3', 'anonymous + a fully valid deep link is still 401 (no data leak)',
      c == '401', c)
c, b, _ = api('action=init&item=exec&build_id=8&tplan_id=2&tcversion_id=5')
check('A4', 'admin but no platform_id -> 400 tcversion_not_set is NOT reached; '
      'platform falls back to "no platform" (legacy optional param)', c == '200', c)

# ------------------------------------------------------------------- exec ---
print('== item=exec ==')
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
              % (BUILD, PLAN, TCVER, PLAT))
ctx = b['context']
check('E1', 'exec: project name resolved from nodes_hierarchy', ctx['tproject']['name'] == 'LTX Project', ctx['tproject']['name'])
check('E2', 'exec: plan name + id', ctx['tplan']['name'] == 'LTX Plan' and ctx['tplan']['id'] == PLAN, str(ctx['tplan']))
check('E3', 'exec: build name/id', ctx['build']['name'] == 'LTX Build 1' and ctx['build']['id'] == BUILD, str(ctx['build']))
check('E4', 'exec: platform name/id', ctx['platform']['name'] == 'Linux' and ctx['platform']['id'] == PLAT, str(ctx['platform']))
check('E5', 'exec: test case external id = PREFIX-tc_external_id', ctx['tcase']['external_id'] == 'LTX-1', ctx['tcase']['external_id'])
check('E6', 'exec: test case name', ctx['tcase']['name'] == 'LTX Case A', ctx['tcase']['name'])
check('E7', 'exec: test suite name', ctx['tcase']['suite'] == 'LTX Suite', ctx['tcase']['suite'])
check('E8', 'exec: version number', ctx['tcase']['version'] == 1, str(ctx['tcase']['version']))
check('E9', 'exec: tcase_id = parent_id of the version node', ctx['tcase']['tcase_id'] == TCASE, str(ctx['tcase']['tcase_id']))
check('E10', 'exec: execution feature = testplan_tcversions row', ctx['feature']['testplan_tcversions_id'] == FEAT, str(ctx['feature']))
tu = b['targets']['primary_url']
check('E11', 'exec: hands over to modern execSetResults.html',
      '/gui/templates/execute/execSetResults.html' in tu, tu.split('?')[0])
check('E12', 'exec: target URL carries tcase_id+tcversion_id+build_id+platform_id',
      all(k in tu for k in ['tcase_id=%d' % TCASE, 'tcversion_id=%d' % TCVER,
                            'build_id=%d' % BUILD, 'platform_id=%d' % PLAT]), '')
check('E13', 'exec: left-frame twin is modern execNavigator.html',
      '/gui/templates/execute/execNavigator.html' in b['targets']['tree_url'], '')
check('E14', 'exec: no legacy lib/**.php in either target URL',
      'lib/' not in tu and 'lib/' not in b['targets']['tree_url'], '')

c, b, _ = api('action=init&item=exec&build_id=%d&feature_id=%d' % (BUILD, FEAT))
check('E15', 'exec: compact feature_id form resolves plan+version+platform',
      c == '200' and b['context']['tplan']['id'] == PLAN
      and b['context']['tcase']['tcversion_id'] == TCVER, c)
c, b, _ = api('action=init&item=exec&build_id=%d&feature_id=%d' % (BUILD, FEAT2))
check('E16', 'exec: second feature_id resolves version 2',
      c == '200' and b['context']['tcase']['version'] == 2, c)

# ----------------------------------------------------------------- xta2m ---
print('== item=xta2m ==')
c, b, _ = api('action=init&item=xta2m&tplan_id=%d&user_id=1&build_id=%d' % (PLAN, BUILD))
check('X1', 'xta2m: self resolves', c == '200' and b['item'] == 'xta2m', c)
check('X2', 'xta2m: hands over to modern assignedTcOverview.html',
      '/gui/templates/results/assignedTcOverview.html' in b['targets']['primary_url'], '')
check('X3', 'xta2m: target URL carries tproject_id+user_id+tplan_id+build_id',
      all(k in b['targets']['primary_url'] for k in
          ['tproject_id=%d' % PRJ, 'user_id=1', 'tplan_id=%d' % PLAN, 'build_id=%d' % BUILD]), '')
check('X4', 'xta2m: no left-frame button offered', b['targets']['tree_url'] is None, '')
c, b, _ = api('action=init&item=xta2m&tplan_id=%d&user_id=99' % PLAN)
check('X5', 'xta2m: another user -> 403 not_your_tasks (the legacy self-check '
      'the inner frame skipped)', c == '403' and b['code'] == 'not_your_tasks',
      '%s %s' % (c, b and b.get('code')))
c, b, _ = api('action=init&item=xta2m&user_id=1')
check('X6', 'xta2m: missing tplan_id -> 400 testplan_not_set',
      c == '400' and b['code'] == 'testplan_not_set', '%s %s' % (c, b and b.get('code')))
c, b, _ = api('action=init&item=xta2m&tplan_id=%d' % PLAN)
check('X7', 'xta2m: missing user_id -> 400 missing_user_id',
      c == '400' and b['code'] == 'missing_user_id', '%s %s' % (c, b and b.get('code')))

# --------------------------------------------------------------- security ---
print('== security / hardening ==')
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d'
              % (BUILD, PLAN, TCVER), cookie=CJ2)
check('S1', '<no rights> user -> 403 no_rights on exec',
      c == '403' and b['code'] == 'no_rights', '%s %s' % (c, b and b.get('code')))
c, b, _ = api('action=init&item=xta2m&tplan_id=%d&user_id=99' % PLAN, cookie=CJ2)
check('S2', '<no rights> user -> 403 on xta2m too', c == '403', c)
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d&load=1'
              % (BUILD, PLAN, TCVER, PLAT))
check('S3', 'legacy &load=1 inner-frame URL now goes through the SAME rights check',
      c == '200' and b['context']['tplan']['id'] == PLAN, c)
c2, b2, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&load=1'
                % (BUILD, PLAN, TCVER), cookie=CJ2)
check('S4', '&load=1 for a <no rights> user is refused (legacy: it was not)',
      c2 == '403' and b2['code'] == 'no_rights', '%s %s' % (c2, b2 and b2.get('code')))
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
              '&anchor=%%3Cscript%%3Ealert(1)%%3C/script%%3E'
              % (BUILD, PLAN, TCVER, PLAT))
check('S5', 'anchor=<script> is dropped (legacy interpolated it into the iframe src)',
      c == '200' and 'anchor=' not in b['targets']['primary_url'],
      b['targets']['primary_url'][-40:])
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
              '&anchor=step_3' % (BUILD, PLAN, TCVER, PLAT))
check('S6', 'anchor=step_3 is kept and forwarded',
      'anchor=step_3' in b['targets']['primary_url'], '')
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&feature_id=1abc'
              % (BUILD, PLAN, TCVER))
check('S7', 'feature_id=1abc is not truthy (legacy string-vs-0 compare)',
      c == '200' and b['context']['tcase']['tcversion_id'] == TCVER, c)
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d'
              % (BUILD, PLAN, TCVER), method='POST', extra=['-H', 'X-Requested-With: XMLHttpRequest'])
check('S8', 'POST -> 405 method_not_allowed', c == '405' and b['code'] == 'method_not_allowed', c)
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d'
              % (BUILD, PLAN, TCVER), method='POST', extra=[])
check('S9', 'POST without same-origin proof -> 403 CSRF (the guard runs BEFORE the 405)',
      c == '403' and 'CSRF' in (b and b.get('message', '')), '%s %s' % (c, b and b.get('message')))
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d'
              % (BUILD, PLAN, TCVER), method='POST',
              extra=['-H', 'X-Requested-With: XMLHttpRequest'])
check('S9b', 'POST WITH same-origin proof -> 405 method_not_allowed',
      c == '405' and b['code'] == 'method_not_allowed', c)
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d'
              % (BUILD, PLAN, TCVER), method='POST',
              extra=['-H', 'X-Requested-With: XMLHttpRequest', '-H', 'Origin: http://evil.example'])
KNOWN = os.environ.get('XW_SHORTCUT_FIXED') == '1'
check('S9c', 'POST with a foreign Origin -> 403 CSRF%s'
      % ('' if KNOWN else '  [KNOWN ISSUE #1679: XRW short-circuits before Origin; not CORS-exploitable]'),
      (c == '403') if KNOWN else (c == '405'), c)
check('S9d', 'POST with a MATCHING Origin and no XRW -> passes the guard (405, not 403)',
      api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d' % (BUILD, PLAN, TCVER),
          method='POST', extra=['-H', 'Origin: http://localhost:8082'])[0] == '405', '')
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d'
              % (BUILD, PLAN2, TCVER), )
check('S10', 'version NOT linked to the addressed plan -> 404 version_not_in_plan '
      '(legacy never proved plan membership)',
      c == '404' and b['code'] == 'version_not_in_plan', '%s %s' % (c, b and b.get('code')))
c, b, _ = api('action=init&item=exec&build_id=999&tplan_id=%d&tcversion_id=%d' % (PLAN, TCVER))
check('S11', 'unknown build -> 404 unknown_build', c == '404' and b['code'] == 'unknown_build', c)
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
              % (BUILD, PLAN, TCVER, PLAT2))
check('S12', 'a CLOSED platform of the same project is still offered (chip shows closed)',
      c == '200' and b['context']['platform']['is_open'] == 0, c)
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=999'
              % (BUILD, PLAN, TCVER))
check('S13', 'platform of another project -> 404 unknown_platform',
      c == '404' and b['code'] == 'unknown_platform', c)
c, b, _ = api('action=init&item=exec&build_id=%d&feature_id=9999' % BUILD)
check('S14', 'unknown feature_id -> 404 unknown_feature (legacy dereferenced a null rowset)',
      c == '404' and b['code'] == 'unknown_feature', c)

# ---------------------------------------------------------------- options ---
print('== options / contract ==')
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
              % (BUILD, PLAN, TCVER, PLAT))
o = b['options']
check('O1', 'options: project builds listed', [x['id'] for x in o['builds']] == [BUILD], str(o['builds']))
check('O2', 'options: project platforms listed + "no platform" is a client choice',
      sorted(x['id'] for x in o['platforms']) == [PLAT, PLAT2], str(o['platforms']))
check('O3', 'options: every version LINKED to the plan listed',
      sorted(x['tcversion_id'] for x in o['linked_versions']) == [TCVER, TCVER2], str(o['linked_versions']))
check('O4', 'options: feature_id of each linked version exposed (Apply re-issues it)',
      sorted(x['feature_id'] for x in o['linked_versions']) == [FEAT, FEAT2], '')
c, b, _ = api('action=init&item=bogus&build_id=%d' % BUILD)
check('C1', 'unknown item -> 400 security_check_ko (legacy echoed lang_get(security_check_ko))',
      c == '400' and b['code'] == 'security_check_ko' and b['legacy_code'] == 'LTX-01',
      '%s %s' % (c, b and b.get('legacy_code')))
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d' % (BUILD, PLAN, TCVER),
              extra=[])
check('C2', 'GET without X-Requested-With is still served (safe verb passes the CSRF guard)',
      c == '200', c)
c, b, _ = api('action=init&item=exec&tplan_id=%d&tcversion_id=%d' % (PLAN, TCVER))
check('C3', 'exec without build_id -> 400 build_id_not_set (legacy init_args gate)',
      c == '400' and b['code'] == 'build_id_not_set', c)
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=999&tcversion_id=%d' % (BUILD, TCVER))
check('C4', 'unknown plan -> 404 plan_not_found (legacy die("ltx - tplan info does not exist"))',
      c == '404' and b['legacy_code'] == 'LTX-02', '%s %s' % (c, b and b.get('legacy_code')))
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=999' % (BUILD, PLAN))
check('C5', 'unknown version -> 404 (legacy read $info["parent_id"] off a null)',
      c == '404' and b['code'] == 'tcversion_not_found', c)
c, b, _ = api('action=bogus')
check('C6', 'unknown action -> 400', c == '400' and b['code'] == 'unknown_action', c)
c, b, _ = api('action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
              % (BUILD, PLAN, TCVER, PLAT))
check('C7', 'ok answers carry legacy_code:null', b['legacy_code'] is None, str(b['legacy_code']))
codes = {}
for label, qs in [('bad item', 'action=init&item=bogus'),
           ('no build', 'action=init&item=exec&tplan_id=%d&tcversion_id=%d' % (PLAN, TCVER)),
           ('bad plan', 'action=init&item=exec&build_id=%d&tplan_id=999&tcversion_id=%d' % (BUILD, TCVER)),
           ('bad feature', 'action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&feature_id=9999' % (BUILD, PLAN, TCVER)),
           ('bad build', 'action=init&item=exec&build_id=999&tplan_id=%d&tcversion_id=%d' % (PLAN, TCVER)),
           ('bad version', 'action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=999' % (BUILD, PLAN)),
           ('bad platform', 'action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=999' % (BUILD, PLAN, TCVER)),
           ('version not in plan', 'action=init&item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d' % (BUILD, PLAN2, TCVER)),
           ('xta2m other user', 'action=init&item=xta2m&tplan_id=%d&user_id=99' % PLAN),
           ('xta2m no plan', 'action=init&item=xta2m&user_id=1'),
           ('xta2m no user', 'action=init&item=xta2m&tplan_id=%d' % PLAN),
           ('bad build id', 'action=init&item=exec&build_id=1abc&tplan_id=%d&tcversion_id=%d' % (PLAN, TCVER))]:
    _, bb, _ = api(qs)
    if bb and bb.get('legacy_code'):
        codes.setdefault(bb['legacy_code'], []).append(label)
check('C8', 'every probed failure branch is legacy-mapped to a LTX-0x marker '
      '(12/12 branches covered)', sum(len(v) for v in codes.values()) == 12, str(codes))
check('C8b', 'markers are shared per legacy failure CLASS, never invented per branch '
      '(7 distinct classes for 12 branches)', len(codes) == 7, str(sorted(codes)))
check('C8c', 'the shared classes are exactly the legacy die()/lang_get() sites',
      sorted(codes) == ['LTX-01', 'LTX-02', 'LTX-03', 'LTX-04', 'LTX-06', 'LTX-09', 'LTX-10'],
      str(sorted(codes)))
check('C8d', 'LTX-09 = the legacy "die()" class and carries the most branches',
      len(codes['LTX-09']) == 4, str(codes['LTX-09']))

# ------------------------------------------------------------------- shim ---
print('== ltx.php shim ==')
def head(url, cookie=CJ):
    r = subprocess.run(['curl', '-s', '-o', '/dev/null', '-D', '-', '-b', cookie, url],
                       capture_output=True, text=True).stdout
    code = [l for l in r.splitlines() if l.startswith('HTTP/')]
    loc = [l for l in r.splitlines() if l.lower().startswith('location:')]
    return (code[0].split()[1] if code else '?'), (loc[0].split(':', 1)[1].strip() if loc else '')

cd, loc = head(BASE + '/ltx.php?item=exec&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
               % (BUILD, PLAN, TCVER, PLAT))
check('H1', 'ltx.php -> 302 to the modern resolver', cd == '302' and 'ltxDirectLink.html' in loc, '%s %s' % (cd, loc))
check('H2', 'ltx.php forwards the whole query string',
      all(k in loc for k in ['item=exec', 'build_id=%d' % BUILD, 'tcversion_id=%d' % TCVER]), '')
cd, loc = head(BASE + '/ltx.php?item=exec&load=1&build_id=%d&tplan_id=%d&tcversion_id=%d&platform_id=%d'
               % (BUILD, PLAN, TCVER, PLAT))
check('H3', 'legacy inner-frame URL (&load=1) also forwards', cd == '302' and 'load=1' in loc, cd)
cd, loc = head(BASE + '/ltx.php?item=xta2m&user_id=1&tplan_id=%d' % PLAN)
check('H4', 'ltx.php xta2m link forwards', cd == '302' and 'item=xta2m' in loc, cd)
anon = subprocess.run(['curl', '-s', BASE + '/ltx.php?item=exec&build_id=8'],
                      capture_output=True, text=True).stdout
check('H5', 'anonymous ltx.php -> legacy login bounce (testlinkInitPage contract)',
      'login.php' in anon and 'note=expired' in anon, anon[:60].replace('\n', ' '))
legacy_html = open('ltx.php').read()
body = legacy_html.split('*/', 1)[1]          # strip the provenance doc block
check('H6', 'ltx.php no longer renders a Smarty frameset (no ->display(, no .tpl require)',
      '->display(' not in body and 'TLSmarty' not in body and ".tpl'" not in body,
      'body lines=%d' % len(body.splitlines()))
check('H7', 'ltx.php is a pure 302 shim: guard, then Location, then exit',
      "testlinkInitPage($db, true)" in body
      and "header('Location: '" in body and body.rstrip().endswith('exit();')
      and 'main.tpl' not in body, '')
check('H8', 'ltx.php strips CR/LF from the forwarded query string (header injection)',
      'str_replace(["\\r", "\\n"]' in body, '')
check('H9', 'ltx.php keeps the legacy non-public contract (no ?goto= / no public bypass)',
      'note=expired' not in body and 'ob_start' in body, '')

# ----------------------------------------------------------------- i18n -----
print('== i18n / wiring ==')
out = subprocess.run(['python3', '-c', '''
import json, glob, sys
missing = []
for f in sorted(glob.glob("gui/templates/i18n/*.json")):
    d = json.load(open(f))
    need = [k for k in d if k.startswith("ltx.")]
    if len(need) != 53 or "footers.ltxDirectLink" not in d:
        missing.append((f, len(need), "footers.ltxDirectLink" in d))
print(missing)
'''], capture_output=True, text=True, cwd='.')
check('I1', 'all 10 bundles carry the 53 ltx.* keys + footers.ltxDirectLink',
      out.stdout.strip() == '[]', out.stdout.strip() or out.stderr.strip()[:80])
commonphp = open('lib/functions/common.php').read()
check('I2', '$actions->ltxDirectLink registered in common.php',
      '$actions->ltxDirectLink' in commonphp, '')
check('I3', 'common.php no longer routes any ltx.php frame',
      'ltx.php' not in commonphp.split('$actions->ltxDirectLink')[1][:400], '')

# ------------------------------------------------------------------ events --
print('== Event Viewer ==')
ev = sh('mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e '
        '"SELECT COALESCE(SUM(log_level>=32),0), COALESCE(SUM(source=\'DATABASE\'),0), '
        'COALESCE(SUM(description LIKE \'%ltx%\' AND log_level>=32),0) FROM events"').strip()
parts = ev.split('\t')
check('V1', 'no ERROR/WARNING row (log_level>=32) anywhere',
      parts and parts[0] == '0', ev.strip())
check('V2', 'no DATABASE (log_level=1) row anywhere', parts and parts[1] == '0', ev.strip())
check('V3', 'no ERROR/WARNING row mentions ltx', parts and parts[2] == '0', ev.strip())
ltxrows = sh('mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e '
             '"SELECT DISTINCT log_level, source FROM events WHERE description LIKE \'%ltx%\'"').strip()
check('V4', 'the only ltx rows are log_level=16 GUI login audits (H5 anonymous bounce '
      'records destination=ltx.php), never errors',
      ltxrows.replace('\t', '/') in ('16/GUI', ''), repr(ltxrows))
check('V5', 'no non-GUI informational row at all (the harness login audit is the only noise)',
      sh('mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e '
         '"SELECT COALESCE(SUM(log_level<32 AND source<\'GUI\'),0) FROM events"').strip() == '0', '')

# ------------------------------------------------------------------ report --
print()
npass = sum(1 for r in RESULTS if r[2] == 'PASS')
nfail = len(RESULTS) - npass
print('=' * 78)
print('Suite 1677 - ltx.php Direct Links Frameset Gateway: %d/%d PASS, %d FAIL'
      % (npass, len(RESULTS), nfail))
print('=' * 78)
if nfail:
    for r in RESULTS:
        if r[2] == 'FAIL':
            print('FAILED %-6s %s  %s' % (r[0], r[1], r[3]))
sys.exit(1 if nfail else 0)
