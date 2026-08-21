#!/usr/bin/env python3
"""Validate all GitHub Actions workflows before pushing.

Checks per step: YAML parse, bash -n syntax, PROMPT+= lint (catches lines
like `PROMPT+"text"` missing '=' which bash -n accepts but explode at runtime),
and a runtime simulation of prompt-builder scripts (everything up to the real
command is executed; the command line itself is cut off).

Usage: python3 .github/scripts/validate-workflows.py
Exit code 0 = all green.
"""
import glob
import re
import subprocess
import sys
import tempfile

import yaml

fail = 0
for path in sorted(glob.glob('.github/workflows/*.yml')):
    wf = path.split('/')[-1]
    try:
        d = yaml.safe_load(open(path))
    except yaml.YAMLError as e:
        print(f"FAIL {wf}: YAML parse: {e}")
        fail += 1
        continue
    for job in d.get('jobs', {}).values():
        for s in job.get('steps', []):
            if 'run' not in s:
                continue
            name = s.get('name', '?')
            script = s['run']
            label = f"{wf}/{name}"

            with tempfile.NamedTemporaryFile(mode='w', suffix='.sh', delete=False) as f:
                f.write(script)
                tmp = f.name
            r = subprocess.run(['bash', '-n', tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print(f"FAIL {label}: bash -n: {r.stderr}")
                fail += 1
                continue

            bad = [l for l in script.splitlines()
                   if re.match(r'\s*[A-Z_]+\+(?!==?)', l)]
            if bad:
                print(f"FAIL {label}: VAR+ lines missing '=': {bad}")
                fail += 1
                continue

            if re.search(r'^\s*(PROMPT|MSG)\+="?', script, re.M):
                # Build a SAFE simulation copy: stub out the real command
                # (opencode run ...) including its backslash continuations,
                # keep all control flow so loops/conditionals are exercised.
                out, skipping = [], False
                for line in script.splitlines():
                    if re.match(r'^\s*opencode run\b', line):
                        out.append('echo "[stubbed opencode run]"')
                        skipping = line.rstrip().endswith('\\')
                        continue
                    if skipping:
                        skipping = line.rstrip().endswith('\\')
                        continue
                    out.append(line)
                sim = '\n'.join(out) + '\n'
                with tempfile.TemporaryDirectory() as td:
                    sp = f"{td}/sim.sh"
                    open(sp, 'w').write(sim)
                    r2 = subprocess.run(
                        ['bash', sp], capture_output=True, text=True,
                        env={'PATH': '/usr/bin:/bin',
                             'DEADLINE_EPOCH_FILE': '.ci_deadline_epoch',
                             'SCREEN_INPUT': 'SimScreen',
                             'MODEL_INPUT': 'sim/model'},
                        cwd=td)
                if r2.returncode != 0:
                    print(f"FAIL {label}: runtime sim: {r2.stderr.strip()}")
                    fail += 1
                    continue
                print(f"OK   {label}: syntax+lint+runtime sim")
            else:
                print(f"OK   {label}: syntax+lint")

print("---")
sys.exit(1 if fail else 0)
