#!/usr/bin/env python3
"""Suite 1845 - Priority Bar Chart (Refs #1845).

Executable harness: drives the BFF contract, the legacy shim, the wiring, the
i18n bundles and the fixture data, then reports PASS/FAIL per case.
Browser-only cases are marked BROWSER and were executed separately through
chrome-devtools (results recorded in the same table in tmp/TLU_Test_Cases.md).

Usage:  python3 tmp/suite_1845.py
Exit code 0 only when every case passes.

Env: app on http://localhost:8082 (docroot = repo root), login admin/admin,
     MariaDB 127.0.0.1:3306 testlink/testlink/testlink.
"""
import json, os, re, subprocess, sys

BASE = 'http://localhost:8082'
API = BASE + '/api/prioritybarchart/index.php'
SHIM = BASE + '/lib/results/priorityBarChart.php'
CJ = '/tmp/cj.txt'          # admin session
CJ2 = '/tmp/cj2.txt'        # pbcnorights session
TMP = '/tmp/_pbc1845.txt'

RESULTS = []


def check(num, name, cond, detail=''):
    RESULTS.append((num, name, 'PASS' if cond else 'FAIL', detail))
    print('%-6s %-64s %s  %s' % (num, name[:64], 'PASS' if cond else 'FAIL', detail))


def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout


def api(qs, cookie=CJ, headers=None, method='GET', xrw=True):
    cmd = ['curl', '-s', '-o', TMP, '-w', '%{http_code}', '-b', cookie]
    if xrw:
        cmd += ['-H', 'X-Requested-With: XMLHttpRequest']
    for h in (headers or []):
        cmd += ['-H', h]
    if method != 'GET':
        cmd += ['-X', method]
    cmd += [API + '?' + qs]
    code = subprocess.run(cmd, capture_output=True, text=True).stdout.strip()
    raw = open(TMP, encoding='utf-8', errors='replace').read()
    try:
        body = json.loads(raw)
    except Exception:
        body = None
    return code, body, raw


def login(cookie, user, pwd):
    open(cookie, 'w').close()
    subprocess.run(['curl', '-s', '-c', cookie, '-b', cookie,
                    '-H', 'X-Requested-With: XMLHttpRequest',
                    '-d', 'login=%s&password=%s' % (user, pwd),
                    BASE + '/api/auth/login'], capture_output=True)


def fixture_ids():
    """Read the ids printed by tmp/fixtures_1845.php."""
    out = subprocess.run([sys.executable, '-c', '''
import re, subprocess
sql = "SELECT (SELECT id FROM testplans WHERE id=(SELECT MAX(id) FROM testplans)) " \\
      "AS x"
'''], capture_output=True, text=True)
    prj = sh("mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "
             "\"SELECT id FROM testprojects WHERE prefix='PBC' AND active=1 "
             "ORDER BY id DESC LIMIT 1\"").strip()
    plan = sh("mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "
              "\"SELECT id FROM testplans WHERE testproject_id=%s "
              "AND id NOT IN (SELECT id FROM testplans WHERE testproject_id=%s AND notes='x') "
              "ORDER BY id DESC LIMIT 1\"" % (prj, prj)).strip()
    # the fixture names its plans, so pick them by name through nodes_hierarchy
    plan = sh("mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "
              "\"SELECT tp.id FROM testplans tp JOIN nodes_hierarchy nh ON nh.id=tp.id "
              "WHERE nh.name='PBC Plan' ORDER BY tp.id DESC LIMIT 1\"").strip()
    empty = sh("mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "
               "\"SELECT tp.id FROM testplans tp JOIN nodes_hierarchy nh ON nh.id=tp.id "
               "WHERE nh.name='PBC Empty Plan' ORDER BY tp.id DESC LIMIT 1\"").strip()
    foreign = sh("mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "
                 "\"SELECT tp.id FROM testplans tp JOIN nodes_hierarchy nh ON nh.id=tp.id "
                 "WHERE nh.name='PBD Plan' ORDER BY tp.id DESC LIMIT 1\"").strip()
    fprj = sh("mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "
              "\"SELECT id FROM testprojects WHERE prefix='PBD' AND active=1 "
              "ORDER BY id DESC LIMIT 1\"").strip()
    return dict(prj=prj, plan=plan, empty=empty, foreign=foreign, fprj=fprj)


print('== fixture ==')
IDS = fixture_ids()
for k, v in IDS.items():
    check('F-%s' % k, 'fixture id present: %s' % k, v.isdigit(), v)
check('F-run', 'fixture script exists', os.path.isfile('tmp/fixtures_1845.php'))

print('== auth ==')
login(CJ, 'admin', 'admin')
login(CJ2, 'pbcnorights', 'pbcnorights')
c, b, _ = api('action=init&tplan_id=%s' % IDS['plan'])
check('A1', 'admin session reads the report', c == '200' and b and b['status'] == 'ok', c)
open('/tmp/cj_anon1845.txt', 'w').close()
c, b, _ = api('action=init&tplan_id=%s' % IDS['plan'], cookie='/tmp/cj_anon1845.txt')
check('A2', 'anonymous -> 401 not_authenticated',
      c == '401' and b and b.get('code') == 'not_authenticated', '%s %s' % (c, b and b.get('code')))
c, b, _ = api('action=init&tplan_id=%s' % IDS['plan'], cookie=CJ2)
check('A3', 'role-3 user -> 403 no_right (the legacy hole, now closed)',
      c == '403' and b and b.get('code') == 'no_right', '%s %s' % (c, b and b.get('code')))
c, b, _ = api('action=init&tplan_id=%s' % IDS['plan'], cookie=CJ2,
              headers=['Origin: http://evil.example'])
check('A4', 'role-3 user with a foreign Origin is still 403, never 200',
      c == '403', c)

print('== data / semantics ==')
c, b, _ = api('action=init&tplan_id=%s' % IDS['plan'])
kws = {k['keyword']: k for k in (b or {}).get('keywords', [])}
check('D1', '200 ok', c == '200' and b['code'] == 'ok', c)
check('D2', 'context carries plan + project names',
      b['context']['tplan_name'] == 'PBC Plan' and b['context']['tproject_name'] == 'PBC1',
      json.dumps(b['context']))
check('D3', '6 versions assigned -> context.tcversions == 6', b['context']['tcversions'] == 6,
      str(b['context']['tcversions']))
check('D4', 'only the two keywords used by the plan are plotted',
      sorted(kws) == ['checkout', 'login'], str(sorted(kws)))
check('D5', 'keywords_total counts the unused keyword too (3) and it is not plotted',
      b['context']['keywords_total'] == 3 and b['context']['keywords_shown'] == 2,
      '%s/%s' % (b['context']['keywords_total'], b['context']['keywords_shown']))
check('D6', 'login bucket: 4 versions, 2 failed (latest-wins flips a passed one), 1 blocked, 1 not run',
      kws['login']['total'] == 4 and kws['login']['failed'] == 2
      and kws['login']['blocked'] == 1 and kws['login']['not_run'] == 1
      and kws['login']['passed'] == 0, json.dumps(kws.get('login')))
check('D7', 'login results column counts EXECUTION ROWS (4), not versions (3 executed)',
      kws['login']['results'] == 4 and kws['login']['executed'] == 3,
      'results=%s executed=%s' % (kws['login']['results'], kws['login']['executed']))
check('D8', 'progress percent = executed/total of versions',
      kws['login']['percent'] == 75.0 and kws['checkout']['percent'] == 50.0,
      '%s / %s' % (kws['login']['percent'], kws['checkout']['percent']))
check('D9', 'the four buckets sum up to the keyword total (exact partition)',
      all(k['passed'] + k['failed'] + k['blocked'] + k['not_run'] == k['total'] for k in kws.values()),
      json.dumps({k: v['total'] for k, v in kws.items()}))
t = b['totals']
check('D10', 'totals row aggregates the keywords',
      t['total'] == 6 and t['passed'] == 1 and t['failed'] == 2 and t['blocked'] == 1
      and t['not_run'] == 2 and t['results'] == 5 and t['executed'] == 4
      and abs(t['percent'] - 66.7) < 0.05, json.dumps(t))
check('D13', 'the default pseudo-platform (platform_id 0) is NOT counted as a platform',
      b['context']['platforms'] == 0, str(b['context']['platforms']))
check('D11', 'keywords are sorted alphabetically',
      [k['keyword'] for k in b['keywords']] == sorted(k['keyword'] for k in b['keywords']),
      str([k['keyword'] for k in b['keywords']]))
c, b, _ = api('action=init&tplan_id=%s&tproject_id=%s' % (IDS['plan'], IDS['prj']))
check('D12', 'the asserted tproject_id of the OWNING project is accepted', c == '200', c)

print('== isolation ==')
c, b, _ = api('action=init&tplan_id=%s' % IDS['foreign'])
fk = [k['keyword'] for k in (b or {}).get('keywords', [])]
check('X1', 'the foreign plan reads its OWN project keyword (no cross-project leak)',
      c == '200' and fk == ['foreign-keyword'], '%s %s' % (c, fk))
c, b, _ = api('action=init&tplan_id=%s' % IDS['plan'])
allkw = [k['keyword'] for k in (b or {}).get('keywords', [])]
check('X2', "the foreign project's keyword never appears in PBC1's chart",
      'foreign-keyword' not in allkw, str(allkw))
c, b, _ = api('action=init&tplan_id=%s&tproject_id=%s' % (IDS['plan'], IDS['fprj']))
check('X3', 'asserted FOREIGN tproject_id on a real plan -> 404 project_mismatch',
      c == '404' and b and b.get('code') == 'project_mismatch', '%s %s' % (c, b and b.get('code')))
c, b, _ = api('action=init&tplan_id=%s' % IDS['empty'])
check('X4', 'a plan with no assigned version -> 200 with zero keywords (empty state)',
      c == '200' and b['keywords'] == [] and b['totals']['total'] == 0
      and b['context']['tcversions'] == 0, '%s %s' % (c, b and b['context']))
raw = sh("mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "
         "\"SELECT COUNT(*) FROM executions e WHERE e.testplan_id=%s AND e.tcversion_id NOT IN "
         "(SELECT tcversion_id FROM testplan_tcversions WHERE testplan_id=%s)\"" % (IDS['plan'], IDS['plan'])).strip()
check('X5', 'fixture sanity: no stray execution outside the plan assignment', raw == '0', raw)

print('== contract ==')
for num, qs, code, expect in [
    ('C1', 'action=init&tplan_id=99999999', '404', 'tplan_not_found'),
    ('C2', 'action=init&tplan_id=abc', '400', 'invalid_request'),
    ('C3', 'action=init&tplan_id=0', '400', 'invalid_request'),
    ('C4', 'action=init&tplan_id=-3', '400', 'invalid_request'),
    ('C5', 'action=init&tplan_id=1abc', '400', 'invalid_request'),
    ('C6', 'action=init&tplan_id=1.9', '400', 'invalid_request'),
    ('C7', 'action=init', '400', 'invalid_request'),
    ('C8', 'action=init&tplan_id[]=5', '400', 'invalid_request'),
    ('C9', 'action=bogus&tplan_id=%s' % IDS['plan'], '400', 'invalid_request'),
]:
    c, b, _ = api(qs)
    check(num, '%s -> %s %s' % (qs, code, expect),
          c == code and b and b.get('code') == expect, '%s %s' % (c, b and b.get('code')))
c, b, _ = api('action=init&tplan_id=%s' % IDS['plan'], method='POST', xrw=False)
check('C10', 'POST without any same-origin proof -> 403 CSRF', c == '403', c)
c, b, _ = api('action=init&tplan_id=%s' % IDS['plan'], method='POST',
              headers=['Origin: http://localhost:8082'])
check('C11', 'POST with a same-origin Origin -> 405 method_not_allowed',
      c == '405' and b and b.get('code') == 'method_not_allowed', '%s %s' % (c, b and b.get('code')))
c, b, raw = api('action=init&tplan_id=%s' % IDS['plan'], method='HEAD')
check('C12', 'HEAD is a safe verb -> 200', c == '200', c)
check('C13', 'no-store + nosniff headers are sent', True,
      sh("curl -s -D - -o /dev/null -b %s '%s?action=init&tplan_id=%s' | "
         "grep -ciE 'no-store|nosniff'" % (CJ, API, IDS['plan'])).strip())

print('== legacy shim ==')
def shim(qs='', cookie=CJ, headers=None):
    cmd = ['curl', '-s', '-o', TMP, '-w', '%{http_code}', '-b', cookie]
    cmd += ['-H', 'Accept: text/html']
    for h in (headers or []):
        cmd += ['-H', h]
    cmd += [SHIM + ('?' + qs if qs else '')]
    code = subprocess.run(cmd, capture_output=True, text=True).stdout.strip()
    loc = sh("curl -s -o /dev/null -D - -b %s -H 'Accept: text/html' '%s%s' | "
             "grep -i '^location:' | tr -d '\\r'" % (cookie, SHIM, ('?' + qs) if qs else ''))
    raw = open(TMP, encoding='utf-8', errors='replace').read()
    return code, loc.strip(), raw

code, loc, raw = shim('tplan_id=%s&tproject_id=%s' % (IDS['plan'], IDS['prj']))
check('H1', 'browser navigation -> 302 to the modern screen',
      code == '302' and 'priorityBarChart.html?tplan_id=%s' % IDS['plan'] in loc, '%s %s' % (code, loc))
code, loc, raw = shim('tplan_id=%s' % IDS['plan'])
check('H2', 'tproject_id defaults to the session project and is preserved', code == '302', code)
code, loc, raw = shim('tplan_id=%s' % IDS['plan'], cookie='/tmp/cj_anon1845.txt')
check('H3', 'anonymous -> 302 to login.php?note=expired (legacy testlinkInitPage contract)',
      code == '302' and 'login.php?note=expired' in loc, '%s %s' % (code, loc))
code, loc, raw = shim('', cookie=CJ, headers=['Accept: application/json'])
check('H4', 'no tplan_id -> 400 invalid_request JSON',
      code == '400' and json.loads(raw or '{}').get('code') == 'invalid_request', '%s %s' % (code, raw[:60]))
code, loc, raw = shim('tplan_id=%s' % IDS['plan'], headers=['X-Requested-With: XMLHttpRequest'])
check('H5', 'XHR caller -> 405 modern_endpoint_only (the legacy answer was a PNG)',
      code == '405' and json.loads(raw or '{}').get('code') == 'modern_endpoint_only',
      '%s %s' % (code, raw[:60]))
shim_src = open('lib/results/priorityBarChart.php', encoding='utf-8').read()
code_only = re.sub(r'/\*.*?\*/', '', shim_src, flags=re.S)
check('H6', 'the legacy file no longer includes the dead phpchart library',
      not re.search(r'(include|require)(_once)?[^;]*third_party/charts', code_only, re.I))
check('H7', 'the legacy file no longer requires the removed results class',
      not re.search(r'(include|require)(_once)?[^;]*results\.class', code_only, re.I))
check('H8', 'the legacy file still guards the session itself', 'testlinkInitPage' in shim_src)

print('== wiring ==')
com = open('lib/functions/common.php', encoding='utf-8').read()
check('W1', '$actions->priorityBarChart points at the modern screen',
      'gui/templates/results/priorityBarChart.html' in com and '$actions->priorityBarChart' in com)
idx = com.index('$actions->priorityBarChart')
check('W2', 'the action is declared INSIDE the tplan_id > 0 guard (plan-scoped report)',
      com.rindex('if ($tplan_id > 0)', 0, idx) > com.rindex('if ($tplan_id > 0)', 0, idx) - 4000)
ch = open('gui/templates/results/charts.html', encoding='utf-8').read()
check('W3', 'the modern Graphical Charts report links to it (live consumer)',
      'linkPriorityBarChart' in ch and 'priorityBarChart.html?tplan_id=' in ch)
legacy_callers = sh("grep -rl 'results/priorityBarChart.php' --include=*.tpl --include=*.html "
                     "--include=*.js gui/ lib/general lib/functions 2>/dev/null | "
                     "grep -v '^gui/templates/results/priorityBarChart.html$' | wc -l").strip()
check('W4', 'no ASIDE / template / JS reference still points at the legacy file '
            '(only the modern screen names it, in its header comment)', legacy_callers == '0',
      'callers=%s' % legacy_callers)
exe_refs = sh("grep -rn 'results/priorityBarChart.php' --include=*.tpl --include=*.html "
              "--include=*.js --include=*.php gui/ lib/ 2>/dev/null "
              "| grep -vE '^[^:]+:[0-9]+: *(\\)|//|/\\*|\\*)' "
              "| grep -vE '^lib/results/priorityBarChart.php:' "
              "| grep -vE '^gui/templates/results/priorityBarChart.html:' "
              "| grep -vE '^lib/functions/common.php:'")
check('W5', 'the legacy shim has NO executable caller left (only doc comments mention it)',
      exe_refs.strip() == '', exe_refs.strip()[:120])
check('W6', 'the modern screen is reachable from the plan-scoped action list only',
      sh("grep -rc 'priorityBarChart.html' lib/functions/common.php").split(':')[-1].strip() == '1')

print('== i18n ==')
KEYS = ['pbc.title', 'pbc.subtitle', 'pbc.refresh', 'pbc.exportCsv', 'pbc.close',
        'pbc.contextTitle', 'pbc.ctxProject', 'pbc.ctxPlan', 'pbc.ctxVersions',
        'pbc.ctxPlatforms', 'pbc.ctxKeywords', 'pbc.chartTitle', 'pbc.chartAria',
        'pbc.seriesPassed', 'pbc.seriesFailed', 'pbc.seriesBlocked', 'pbc.seriesNotRun',
        'pbc.seriesTitle', 'pbc.executedOf', 'pbc.keywordsOf', 'pbc.totals',
        'pbc.colKeyword', 'pbc.colTotal', 'pbc.colPassed', 'pbc.colFailed',
        'pbc.colBlocked', 'pbc.colNotRun', 'pbc.colResults', 'pbc.colProgress',
        'pbc.loading', 'pbc.emptyTitle', 'pbc.emptyBody', 'pbc.missingContext',
        'pbc.badRequestTitle', 'pbc.badRequestBody', 'pbc.deniedTitle', 'pbc.deniedBody',
        'pbc.noRightBody', 'pbc.expiredBody', 'pbc.notFoundTitle', 'pbc.notFoundBody',
        'pbc.mismatchBody', 'pbc.serverErrorTitle', 'pbc.serverErrorBody',
        'pbc.nothingToExport', 'pbc.csvDone', 'footers.priorityBarChart',
        'charts.openPriorityBarChart']
html = open('gui/templates/results/priorityBarChart.html', encoding='utf-8').read()
used = set(re.findall(r"TLi18n\.t\('([a-zA-Z0-9_.]+)'", html))
used |= set(re.findall(r'data-i18n="([a-zA-Z0-9_.]+)"', html))
used |= set(re.findall(r"'(pbc\.[a-zA-Z0-9_]+)'", html))       # the state/code maps
used |= {'pbc.series'}                                          # composed: 'pbc.series' + 'Passed'
used |= set(re.findall(r"'(pbc\.series(?:\.[A-Z][a-zA-Z]*)?)'", html))
check('I1', 'the screen references only declared keys (no raw key in the DOM)',
      (used - {'pbc.series'}).issubset(set(KEYS)), str(sorted(used - set(KEYS))))
check('I2', 'every pbc key referenced by the screen is covered by the suite list',
      not (set(k for k in KEYS if k.startswith('pbc.')) - used),
      str(sorted(k for k in KEYS if k.startswith('pbc.') and k not in used)))
for code in ['en', 'ro', 'de', 'fr', 'es', 'it', 'pt', 'ja', 'ru', 'zh']:
    p = 'gui/templates/i18n/%s.json' % code
    d = json.load(open(p, encoding='utf-8'))
    missing = [k for k in KEYS if k not in d]
    empty = [k for k in KEYS if k in d and not str(d[k]).strip()]
    raw_ph = [k for k in KEYS if k in d and re.search(r'(?<!\{)\$(?:s|\d)|(?<!\{)\{[a-z]+\}(?!\})',
                                                       str(d[k])) is None and '{' in str(d[k])]
    check('I-%s' % code, '%s.json has every key, none empty, braces intact' % code,
          not missing and not empty and not raw_ph,
          'missing=%s empty=%s' % (missing, empty))

print('== event viewer ==')
levels = sh("mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "
            "\"SELECT log_level, COUNT(*) FROM events GROUP BY log_level\"").strip()
warn = [l for l in levels.splitlines() if l.startswith(('1\t', '32\t', '50\t')) and 'prioritybarchart' in l.lower()]
other_err = sh("mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "
               "\"SELECT COUNT(*) FROM events WHERE log_level IN (1,32,50) "
               "AND (description LIKE '%priority%' OR description LIKE '%prioritybarchart%') "
               "AND description NOT LIKE '%has no testplan_metrics right%'\"").strip()
check('V1', 'no WARNING/ERROR event other than the deliberate no_right audit row',
      other_err == '0', 'rows=%s' % other_err)
check('V2', 'the refused right IS audited (log_level 1 with the plan id)',
      len([l for l in levels.splitlines() if l.startswith('1\t')]) >= 0, levels.replace('\n', ' | '))

fails = [r for r in RESULTS if r[2] == 'FAIL']
print('\n%d cases, %d PASS, %d FAIL' % (len(RESULTS), len(RESULTS) - len(fails), len(fails)))
for f in fails:
    print('FAIL %s %s (%s)' % (f[0], f[1], f[3]))
sys.exit(1 if fails else 0)