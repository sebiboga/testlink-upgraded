#!/usr/bin/env python3
import re, collections, os, json

data = []
cur = None
with open('/home/sebi/testlink/tmp/commit_files.txt') as f:
    for line in f:
        line = line.rstrip('\n')
        if line.startswith('=== '):
            m = re.match(r'=== (\w+) (.*)', line)
            if cur: data.append(cur)
            cur = {'hash': m.group(1), 'subject': m.group(2), 'files': []}
        else:
            cur['files'].append(line.strip())
if cur: data.append(cur)

def bucket(f):
    if f.startswith('vendor/'): return 'vendor'
    if f.startswith('third_party/'): return 'thirdparty'
    if f.startswith('gui/templates/dashio/') or f.startswith('gui/themes/dashio/') or f.startswith('css/dashio') or 'dashio' in f: return 'dashio'
    if f.startswith('gui/templates/tl-classic/'): return 'tl-classic'
    if f.startswith('lib/') or f.startswith('cfg/') or f.startswith('gui/') or f.startswith('locale/') or f.startswith('install/') or f.startswith('docs/') or f.startswith('tests/') or f.startswith('sql/'): return 'app'
    return 'other'

summary = []
for c in data:
    buckets = collections.Counter(bucket(f) for f in c['files'])
    app_files = [f for f in c['files'] if bucket(f) in ('app','tl-classic','other') and not f.startswith('vendor/')]
    summary.append({'hash': c['hash'], 'subject': c['subject'], 'buckets': dict(buckets), 'app_files': app_files})

with open('/home/sebi/testlink/tmp/commit_buckets.json','w') as f:
    json.dump(summary, f, indent=1)

# Print compact per-commit view
for s in summary:
    b = s['buckets']
    parts = [f"{k}={v}" for k,v in sorted(b.items())]
    app = ','.join(s['app_files'])
    if len(app) > 160: app = app[:157] + '...'
    print(f"{s['hash']} [{','.join(parts)}] {s['subject'][:80]}")
    if app: print(f"      {app}")
