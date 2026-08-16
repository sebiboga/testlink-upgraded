import re
from collections import Counter

with open('/home/sebi/testlink/tmp/commit_summary.txt') as f:
    lines = f.readlines()

commits = []
cur = None
for ln in lines:
    ln = ln.rstrip('\n')
    m = re.match(r'^([0-9a-f]{8,}) \[(.*?)\] (.*)$', ln)
    if m:
        if cur:
            commits.append(cur)
        cur = {'hash': m.group(1), 'bucket': m.group(2), 'msg': m.group(3), 'files': ''}
    else:
        cur['files'] += ln
if cur:
    commits.append(cur)

def bcount(c, b):
    m = re.search(r'(^|[,=])' + b + r'=([0-9]+)', c['bucket'])
    return int(m.group(2)) if m else 0

def total(c):
    return sum(int(v) for v in re.findall(r'=(\d+)', c['bucket']))

def files_only(c, *buckets):
    for b, n in re.findall(r'([a-z]+)=(\d+)', c['bucket']):
        if b not in buckets and int(n) > 0:
            return False
    return True

# ---- explicit hash sets ----
php8 = {
 'a21ed9fd8','ef4c192d4','5e009eddf','39b887c6d','16fb00c4b','d88ae41ca','f74dc118a',
 'a68f9b9db','5bb3712a2','7b2ea5f06','d3a4dda49','126298a3c','51531607b','eca1bdd61',
 '62ca35192','57962103e','8e8062ffc','c98fd7076','db9dce370','5aed12d0d','0e8e9730c',
 'dafd3f77','a8ace4f2','4dbd2c29','eb8cc3f6','35ff4386','85c495a1','dfb6d72f',
 'a5b790ea','ebfd5d6d','438a61d7','1cb3505f','a67d818c','a0ffa0086','1460a730f',
}
security = {'63d319127','e6e6956f6','ac70cea9a','71576103d','29b6ee4ab'}

arch = {
 # aliens
 '6bc71a45','92619fd5','e0931089','aac772a3','0bd080c0','f6bfe8ce','6cf7f581',
 'c2dae752','de01f1c0','1866086b','3cd0a2ad','1a6aa283','b192e460','0a0d7b34',
 'e8074cf2','8965e69b','d42c1dfe','9fb5fd42','9a588491','690f48d2','a73d7c6ee',
 # slim4 / REST
 '673bbe58','a86f3420','682b2355','57ad44c6','1fa3f2f4','bc58d731',
 # checkRights -> init_args, initContext, initUserEnv
 '606111d9','0933d9b0','51ef9cf1','a449e2d3','d6e78090','341fdb03','62295c11',
 '57a16a26','53893627',
 # milestone/build classes, platform is_open, hasRightOnProj, new config
 '80698645','e578afce','05fcc160','ba385a8c','796b0cee','f736df4c','d97ab858',
 '2b5ea140','eefa1af1','d709ae5d','323d2d64','c1ed9503','12944267','6e763126f',
 # composer platform / bootstrap merge / template URL refactor / global context
 'c3b14ad7','b4916d04','00c36954','fc43f750','fc3d21c2','ac805058','d69ca425',
 '516e2c21','475d55bb','a3a83011','8c3c759b','aac772a3',
 # aligned-to-dashio / wip dev churn on 2.0 branch
 '01099fc5','fcae4599','32d66e79','6e0631ce','d6c58b47','70b805b1','fbe0c41b',
 '88459873','446db1ac','420cc6b6','7a6c436d','695ece6d','272387bc','d6c58b47',
 'b2a4c3770','c7df5c7c8','9f2d9a61f','1e0a0c6c0','0b6f99bf2','6e3d5a0b6',
}

# youtrack references detected in message (bugfix/feature tickets)
yt_re = re.compile(r'(TQ342023|TN2023|TL20PHP82202304|TL2|#\d{4,}|youTrack|youtrack)', re.I)

cats = Counter()
out = {}
for c in commits:
    h, msg, files = c['hash'], c['msg'], c['files']
    m = msg.lower()
    if h in php8:
        cat = 'php8'
    elif h in security:
        cat = 'security'
    elif h in arch:
        cat = 'arch'
    elif files_only(c, 'vendor') and bcount(c,'vendor')>0:
        cat = 'vendor'
    elif files_only(c, 'dashio') and bcount(c,'dashio')>0:
        cat = 'dashio'
    elif files_only(c, 'other'):
        cat = 'misc'
    elif yt_re.search(msg):
        cat = 'ticket'
    elif re.search(r'php\s*8|deprecat|\$\{|\bnull\b', m) and not re.search(r'\bwip\b', m):
        cat = 'php8'
    elif re.search(r'\balien|slim|rest\b|checkrights|initcontext|inituserenv|composer|bootstrap|dashio|architectur|\bwip\b|aligned', m):
        cat = 'arch'
    elif re.search(r'code layout|typo|comment|missing string|missing l10n|missing localiz|l10n|README|git setup|debug|var_dump|file mode|document|docum|missing ;|missing col', m):
        cat = 'misc'
    elif re.search(r'fix|fixed|crash|error|bug|wrong|missing', m):
        cat = 'ticket'
    else:
        cat = 'arch'
    cats[cat] += 1
    out.setdefault(cat, []).append((h[:8], msg[:80], files.strip()))

for k in sorted(cats):
    print(f"{k}: {cats[k]}")

with open('tmp/cat_result.txt','w') as f:
    for k in sorted(cats):
        f.write(f"\n## {k} ({cats[k]})\n")
        for h, msg, files in out[k]:
            f.write(f"{h} {msg}\n")
            if files:
                f.write(f"    {files[:180]}\n")
print("wrote tmp/cat_result.txt")
