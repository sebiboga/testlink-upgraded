#!/usr/bin/env python3
"""Executable test suite for issue #1676 — usersAssignPlan.html Name column must
never hide the user's first/last names (legacy usersAssign.tpl:242 rendered
"login (first last)" unconditionally, independent of $tlCfg->username_format).

Run from repo root:  python3 tmp/suite_1676.py

Part A  client unit tests - the SHIPPED userNameCell() is extracted out of
        gui/templates/usermanagement/usersAssignPlan.html and evaluated in node,
        so the assertions run against the real function, not a copy of it.
Part B  live HTTP - real login, real BFF call: meta/tplan-roles must ship
        firstName/lastName next to the getDisplayName() value, for every format.
"""
import json
import re
import subprocess
import sys
import os
import urllib.request
import urllib.parse
import http.cookiejar
import tempfile
import os.path

BASE = os.environ.get("TL_BASE", "http://localhost:8082")
HTML = "gui/templates/usermanagement/usersAssignPlan.html"
API = BASE + "/api/roles/index.php/meta/tplan-roles?tproject_id=1&tplan_id=2"

results = []


def check(cid, ok, detail):
    results.append((cid, "PASS" if ok else "FAIL", detail))
    print(("PASS" if ok else "FAIL") + "  " + cid + "  " + str(detail))


CONFIG = "custom_config.inc.php"
_saved = {}


def set_format(fmt):
    """Write the gitignored custom_config.inc.php that overrides
    $tlCfg->username_format (config.inc.php:2208 requires it last). A pre-existing
    file is SAVED on first touch and restored by reset_format() - the harness
    never destroys a local config it did not create."""
    if fmt is None:
        return
    if fmt not in _saved:
        _saved[fmt] = open(CONFIG).read() if os.path.exists(CONFIG) else None
    with open(CONFIG, "w") as fh:
        fh.write("<?php\n$tlCfg->username_format = \"%s\";\n" % fmt)


def reset_format():
    """Restore custom_config.inc.php exactly as it was before the run."""
    if not _saved:
        return
    original = _saved.pop(list(_saved)[0])
    if original is None:
        if os.path.exists(CONFIG):
            os.remove(CONFIG)
    else:
        with open(CONFIG, "w") as fh:
            fh.write(original)


def api_payload(fmt):
    """Log in over real HTTP and return the decoded tplan-roles payload."""
    set_format(fmt)
    cj = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    data = urllib.parse.urlencode({"tl_login": "admin", "tl_password": "admin"}).encode()
    req = urllib.request.Request(BASE + "/login.php", data=data)
    op.open(req, timeout=30).read()
    return json.loads(op.open(API, timeout=30).read().decode())


def shipped_cell_function(path=HTML):
    src = open(path).read()
    # extract ONLY the pure helper (the rest of the inline script needs jQuery/DOM)
    fn = re.search(r"function userNameCell\(u\) \{.*?\n\}", src, re.S).group(0)
    runner = """
const cases = %s;
console.log(JSON.stringify(cases.map(c => userNameCell({name: c[0], firstName: c[1], lastName: c[2]}))));
""" % json.dumps([
        # name, first, last  -> expected cell text
        ["Anna Designer", "Anna", "Designer"],          # default format: unchanged
        ["Anna Designer an1676designer", "Anna", "Designer"],  # +login: unchanged
        ["Designer, Anna", "Anna", "Designer"],         # %%last%%, %%first%%: unchanged
        ["an1676designer", "Anna", "Designer"],         # %%login%%: legacy info restored
        ["an1676designer@localhost", "Anna", "Designer"],  # %%email%%: restored
        ["", "Testlink", "Administrator"],              # empty display -> bare names
        ["an1676noname", "", ""],                      # no names -> display only
        ["Guest", "Gus", "Guest"],                      # only one of them in format
        ["Designer", "Anna", "Designer"],               # %%last%% only -> re-appended
    ])
    combined = os.path.join(tempfile.gettempdir(), "suite_1676_run.js")
    with open(combined, "w") as fh:
        fh.write(fn + "\n" + runner)
    r = subprocess.run(["node", combined], capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit("node failed: " + r.stderr)
    return json.loads(r.stdout.strip().splitlines()[-1])


print("== Part A: shipped userNameCell() semantics ==")
expected = [
    "Anna Designer",
    "Anna Designer an1676designer",
    "Designer, Anna",
    "an1676designer (Anna Designer)",
    "an1676designer@localhost (Anna Designer)",
    "Testlink Administrator",
    "an1676noname",
    "Guest (Gus Guest)",
    "Designer (Anna Designer)",
]
got = shipped_cell_function()
for i, (exp, act) in enumerate(zip(expected, got), start=1):
    check("A%d" % i, exp == act, "expected %r got %r" % (exp, act))

print("== Part B: live BFF payload (real login, real HTTP) ==")
for fmt, label in [("%first% %last%", "default"), ("%login%", "login-only"),
                   ("%email%", "email-only")]:
    j = api_payload(fmt)
    items = j.get("items") or []
    by_login = {i["login"]: i for i in items}
    designer = by_login.get("an1676designer", {})
    noname = by_login.get("an1676noname", {})
    has_raw = ("firstName" in designer and "lastName" in designer
               and designer.get("firstName") == "Anna"
               and designer.get("lastName") == "Designer")
    check("B1-%s" % label, has_raw,
          "raw names shipped for %s: firstName=%r lastName=%r name=%r"
          % (fmt, designer.get("firstName"), designer.get("lastName"), designer.get("name")))
    check("B2-%s" % label, "name" in designer and "login" in designer and "roleID" in designer,
          "existing payload keys untouched: name=%r login=%r roleID=%r"
          % (designer.get("name"), designer.get("login"), designer.get("roleID")))
    check("B3-%s" % label, noname.get("firstName") == "" and noname.get("lastName") == "",
          "empty names survive the cast: %r / %r" % (noname.get("firstName"), noname.get("lastName")))

# name field must STILL be the getDisplayName() value (BFF is not made responsible
# for composing the cell - the client does, so both columns stay reusable)
j_default = api_payload("%first% %last%")
d = {i["login"]: i for i in (j_default.get("items") or [])}["an1676designer"]
check("B4", d["name"] == "Anna Designer", "BFF name is still getDisplayName(): %r" % d["name"])
j_login = api_payload("%login%")
d2 = {i["login"]: i for i in (j_login.get("items") or [])}["an1676designer"]
check("B5", d2["name"] == "an1676designer", "BFF name follows username_format: %r" % d2["name"])

print("== Part C: the shared legacy cell on the TEST-PROJECT twin screen ==")
proj_html = "gui/templates/usermanagement/usersAssignProject.html"
check("C1", "userNameCell(u)" in open(proj_html).read(), "twin screen ships the helper")
check("C2", "esc(userNameCell(u))" in open(proj_html).read(), "twin Name cell renders through esc()")
twin = shipped_cell_function(proj_html)
for i, (exp, act) in enumerate(zip(expected, twin), start=3):
    check("C%d" % i, exp == act, "twin expected %r got %r" % (exp, act))
j_twin = api_payload("%login%")
plan = api_payload("%first% %last%")
check("C12", j_twin.get("items", [{}])[0].get("firstName") is not None
      and {i["login"]: i for i in plan.get("items", [])}.get("an1676designer", {}).get("firstName") == "Anna",
      "tproject-roles ships the raw names too (usersAssignProject.html)")

reset_format()  # leave the environment exactly as it was

bad = [c for c, s, _ in results if s == "FAIL"]
print("\n%d/%d PASS" % (len(results) - len(bad), len(results)))
sys.exit(1 if bad else 0)
