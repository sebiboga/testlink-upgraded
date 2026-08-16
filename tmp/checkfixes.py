#!/usr/bin/env python3
import json, subprocess, sys

with open('/home/sebi/testlink/tmp/commit_buckets.json') as f:
    commits = {c['hash']: c for c in json.load(f)}

import re
def parse_files(path):
    data = []
    cur = None
    with open(path) as fh:
        for line in fh:
            line = line.rstrip('\n')
            if line.startswith('=== '):
                m = re.match(r'=== (\w+) (.*)', line)
                if cur: data.append(cur)
                cur = {'hash': m.group(1), 'subject': m.group(2), 'files': []}
            else:
                cur['files'].append(line.strip())
    if cur: data.append(cur)
    return {d['hash']: d for d in data}

filemap = parse_files('/home/sebi/testlink/tmp/commit_files.txt')

# Candidate commits to check against sebiboga: PHP8, security, TQ/TL20 youtrack bugs
candidates = {
 '145e587e4':'LDAP', '424eac7a8':'LDAP', '5b8e7ec3c':'req-search-fix',
 'a6304f6b8':'TQ77','943949415':'TQ87','de51ac538':'TQ61',
 '63d319127':'TQ64sec','e6e6956f6':'TQ64sec','99f3b915e':'TQ17',
 '3f6839d37':'TQ29','eaf398319':'TQ14','86da69acd':'TQ25','29b6ee4ab':'TQ30sec',
 '7fa95e604':'TQ26','5c0ae8800':'TQ21','d3a4dda49':'TQ34','46c7b27fa':'TQ12',
 'b7c03573a':'TQ20','71576103d':'TQ48sec','ac70cea9a':'TQ47sec',
 'aecf49d4d':'TL72','82f3fac2b':'TL63','ce3ac3e37':'TL65','5d46467a0':'TL72',
 '7a9e357b0':'TL51','d633aa732':'TL11','0e8e9730c':'TL2',
 # PHP8
 '5e009eddf':'PHP8.2.4','5aed12d0d':'PHP8strftime','dafd3f777':'PHP8',
 '39b887c6d':'PHP8countable','a8ace4f2c':'PHP8dynprop','4dbd2c29c':'PHP8${var}',
 '126298a3c':'PHP8implode','ef4c192d4':'PHP8cast','d88ae41ca':'PHP8fix',
 'a21ed9fd8':'PHP8count','f74dc118a':'PHP8sizeof','51531607b':'PHP8count',
 'eca1bdd61':'PHP8count','eb8cc3f6c':'PHP8obj','62ca35192':'PHP8each',
 '57962103e':'PHP8each','7b2ea5f06':'PHP8strftime','c98fd7076':'PHP8cast',
 '8e8062ffc':'PHP8createfn','35ff4386f':'PHP8deprec','16fb00c4b':'PHP8curl',
 'a68f9b9db':'bulk-cfield-fix','a0ffa0086':'platforms-fix','a21ed9fd8':'count',
 '9b9096665':'TN2023-debug','2f7cdde48':'smarty-warn','16bf57ef8':'typo-warn',
 'db9dce370':'fix-warning','ebfd5d6d1':'null-warnings','a5b790eab':'null-access',
 '2c847b363':'null-checks','b7de887fc':'cfields-attr','793bb959a':'cfields-attr',
 '85aef60f2':'readme','5bb3712a2':'kint',
}

def exists_in(branch, path):
    r = subprocess.run(['git','cat-file','-e',f'{branch}:{path}'],
                       capture_output=True, cwd='/home/sebi/testlink')
    return r.returncode == 0

def added_lines_of(commit, path):
    r = subprocess.run(['git','show',commit,'--',path],
                       capture_output=True, text=True, cwd='/home/sebi/testlink')
    if r.returncode != 0:
        r = subprocess.run(['git','show',f'{commit}:{path}'],
                           capture_output=True, text=True, cwd='/home/sebi/testlink')
        return None
    added = []
    for line in r.stdout.splitlines():
        if line.startswith('+') and not line.startswith('+++'):
            s = line[1:].strip()
            if len(s) >= 12:
                added.append(s)
    return added

def search_in_branch(branch, path, lines):
    r = subprocess.run(['git','show',f'{branch}:{path}'],
                       capture_output=True, text=True, cwd='/home/sebi/testlink')
    if r.returncode != 0:
        return None
    content = r.stdout
    found = []
    for s in lines:
        if s in content:
            found.append(s)
    return found

rows = []
for h, label in candidates.items():
    c = commits[h]
    fm = filemap[h]['files']
    paths = sorted(set(c.get('app_files', [])) | set(p for p in fm if p.startswith('cfg/') or p=='linkto.php' or p.startswith('gui/javascript/') or p.startswith('gui/templates/conf/') or p.startswith('locale/')))
    for path in paths:
        if path in rows or path.startswith('vendor/') or path.startswith('third_party/') or path.startswith('gui/templates/dashio/') or path.startswith('gui/themes/dashio/') or path.startswith('gui/templates_c/'):
            continue
        exists = exists_in('sebiboga', path)
        status = 'NO-FILE-in-sebiboga'
        n_found = ''
        if exists:
            added = added_lines_of(h, path)
            if added is None:
                status = 'ERR'
            else:
                found = search_in_branch('sebiboga', path, added)
                n_found = f'{len(found)}/{len(added)}'
                status = 'APPLIED' if (found and len(found) >= max(1,len(added)//2)) else 'NOT-APPLIED'
        rows.append((h,label,path,exists,status,n_found))

with open('/home/sebi/testlink/tmp/check_results.txt','w') as f:
    for h,label,path,exists,status,n_found in rows:
        f.write(f"{h} {label} {status} {n_found} {path}\n")
print("done", len(rows))
