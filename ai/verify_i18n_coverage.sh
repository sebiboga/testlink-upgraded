#!/usr/bin/env bash
# verify_i18n_coverage.sh — key-SET gate for the gui/templates/i18n bundles.
#
# WHY THIS EXISTS — issue #1844. The only i18n gate the toolchain had was
# `python3 -m json.tool <file>` (ai/AGENTS.md rule 3, ai/IMPLEMENT-TASK.md §3,
# ai/FIX-ISSUE.md §5, ai/ISSUES.md §4, ai/MERGE-STALE-BRANCHES.md). That proves
# WELL-FORMEDNESS, never COVERAGE: `{"role.noRights": null}` is valid JSON. A
# feature that adds its keys to en.json and forgets the other nine bundles ships
# silently, because gui/templates/i18n/i18n.js:175 falls back to the raw key —
#
#   var str = _strings[key] || key;
#
# — so the user sees "role.noRights" painted on screen instead of a label, with no
# error, no log line and no failed build. #1840 is the French instance; the same
# class was measured in six of the ten bundles (de/es/it 26 keys, ja/pt/ru 20,
# ro 6, zh 40). Five separate commits shipped that drift, including 9d380b9dc,
# whose own message claims "rctc.* keys in all 10 locale bundles".
#
# THE INVARIANT: every key defined in en.json must exist in every other bundle.
# Drift is one-directional in practice (measured `extra` keys = 0 in all nine),
# so en.json is the correct reference. Extra keys are REPORTED but do not fail:
# a bundle may legitimately carry a key en.json has since dropped.
#
# Values are flattened before comparing, so a bundle that stores "a.b.c" as a
# nested object is compared against a flat en.json on equal footing — TLi18n
# looks up flat keys (i18n.js:175), so flattening is what the runtime does.
#
# USAGE
#   bash ai/verify_i18n_coverage.sh [--report] [--max-print N] [<dir>]
#
#   --report      WARN only: print the gaps but exit 0. For triage only.
#   --max-print N cap the keys printed per bundle (default 20, 0 = all).
#   <dir>        directory holding the bundles (default gui/templates/i18n).
#
# EXIT: 0 = every bundle covers en.json (or --report), 1 = at least one bundle
#        is missing keys, or a bundle is not valid JSON.
#
# The script only reads files; it never rewrites a bundle.
#
# Refs #1844 (Refs #1840).

set -uo pipefail

REPORT_ONLY=0
MAX_PRINT=20
DIR=""

while [ $# -gt 0 ]; do
  case "$1" in
    --report) REPORT_ONLY=1; shift ;;
    --max-print) MAX_PRINT="${2:-}"; shift 2 ;;
    --max-print=*) MAX_PRINT="${1#--max-print=}"; shift ;;
    -h|--help) sed -n '2,36p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*) printf 'unknown option: %s (see --help)\n' "$1" >&2; exit 1 ;;
    *) DIR="$1"; shift ;;
  esac
done

case "$MAX_PRINT" in
  ''|*[!0-9]*) printf 'FAIL --max-print needs a non-negative integer, got: %s\n' "$MAX_PRINT" >&2; exit 1 ;;
esac

cd "$(git rev-parse --show-toplevel 2>/dev/null || echo .)" || exit 1
[ -n "$DIR" ] || DIR="gui/templates/i18n"

TLU_I18N_DIR="$DIR" TLU_I18N_REPORT="$REPORT_ONLY" TLU_I18N_MAX="$MAX_PRINT" python3 - <<'PY'
import glob, json, os, sys

d = os.environ['TLU_I18N_DIR']
report_only = os.environ['TLU_I18N_REPORT'] == '1'
max_print = int(os.environ['TLU_I18N_MAX'])
ref_path = os.path.join(d, 'en.json')


def flat(node, prefix=''):
    if isinstance(node, dict):
        for k, v in node.items():
            yield from flat(v, '%s%s' % (prefix, k) if prefix else k)
    else:
        yield prefix, node


def strings(path):
    with open(path, encoding='utf-8') as fh:
        return {k: v for k, v in flat(json.load(fh)) if isinstance(v, str) and v}


if not os.path.isfile(ref_path):
    sys.exit('FAIL %s not found — the reference bundle is missing, nothing can be compared'
             % ref_path)

try:
    ref = strings(ref_path)
except Exception as exc:
    sys.exit('FAIL %s is not valid JSON: %s' % (ref_path, exc))
if not ref:
    sys.exit('FAIL %s holds no string values — refusing to gate on an empty reference'
             % ref_path)

bundles = sorted(p for p in glob.glob(os.path.join(d, '*.json'))
                 if os.path.basename(p) != 'en.json')
if not bundles:
    sys.exit('FAIL no locale bundles found in %s' % d)

print('i18n coverage gate — reference: %s (%d keys, %d bundle(s) compared)'
      % (ref_path, len(ref), len(bundles)))

failing = []
for path in bundles:
    name = os.path.basename(path)
    try:
        cur = strings(path)
    except Exception as exc:
        print('FAIL %s is not valid JSON: %s' % (name, exc))
        failing.append(name)
        continue
    missing = sorted(k for k in ref if k not in cur)
    extra = sorted(k for k in cur if k not in ref)
    if missing:
        print('%s %s — missing %d key(s) that en.json defines:'
              % ('WARN' if report_only else 'FAIL', name, len(missing)))
        for k in missing[:max_print]:
            print('      - %s' % k)
        if len(missing) > max_print:
            print('      ... and %d more' % (len(missing) - max_print))
        failing.append(name)
        continue
    suffix = ''
    if extra:
        suffix = ' (%d key(s) not in en.json: informational)' % len(extra)
    print('PASS %s — %d keys, 0 missing%s' % (name, len(cur), suffix))

print('---')
print('%d bundle(s) passed, %d failed%s'
      % (len(bundles) - len(failing), len(failing),
         ' (--report: exit 0)' if report_only else ''))
if failing and not report_only:
    print('i18n rule (ai/AGENTS.md rule 3): keys go into ALL %d locale bundles, '
          'not only en.json. Translate them in: %s'
          % (len(bundles) + 1, ', '.join(sorted(os.path.basename(f)[:-5] for f in failing))))
sys.exit(1 if (failing and not report_only) else 0)
PY
exit $?