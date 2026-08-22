# package.json / yarn.lock Sync Verification — PASS

## Issue #590

Verification request (no code change allowed): confirm that `package.json`
and `yarn.lock` are in sync, that dependencies are correctly installed,
which Yarn flavor the project needs, and whether any CI/CD workflow already
runs `yarn install`.

## The Approach — how it was verified

The authoritative check is `yarn install --frozen-lockfile`: with this flag
Yarn refuses to touch the lockfile and hard-fails with
`Your lockfile needs to be updated` whenever `yarn.lock` does not satisfy
`package.json`. A zero exit code therefore *proves* sync — no manual diffing
guesswork needed. Around that check, six targeted inspections answered each
point of the issue:

| # | Check | Method | Finding |
|---|-------|--------|---------|
| 1 | Files exist | `ls package.json yarn.lock` | Both present at repo root |
| 2 | Lockfile synced with manifest | Read both + frozen-lockfile run | Single dep `tablednd@^1.0.3` ↔ single entry `tablednd@^1.0.3 → 1.0.3`; exact match |
| 3 | Dependencies installed | Frozen-lockfile linking + inspect | `node_modules/tablednd@1.0.3` complete (dist incl. `jquery.tablednd.min.js`) |
| 4 | Yarn flavor / version | `head -3 yarn.lock`, `yarn --version` | `# yarn lockfile v1` → **Yarn Classic** required; verified with **1.22.22** on Node v20.20.2 |
| 5 | CI/CD runs yarn install? | grep `.github/workflows/` for `yarn` | **No workflow references yarn at all** |
| 6 | Differences manifest vs lockfile | `git diff -- package.json yarn.lock` after install | Empty — untouched, zero drift |

Frozen-lockfile result: `Done in 0.23s.` exit 0.

## Key discovery: node_modules is vendored

CI doesn't need `yarn install` because `node_modules/` is **committed to git**
(33 tracked files, from commit `521d92f55` "add table drag & drop feature …
tablednd jquery plugin installed using yarn"). The single dependency exists
only to serve the legacy `tl-classic/testcases/steps_horizontal.inc.tpl`
template (drag & drop reorder of test steps). Modernized screens bundle their
own assets and don't use it.

Two hygiene observations (deliberately NOT changed — issue forbids file
modifications):

- `node_modules/.yarn-integrity` is tracked and was generated on macOS
  (`systemParams: darwin-x64-72`). On Linux, any yarn run regenerates it as
  `linux-x64-115`; harmless runtime metadata, restored untouched during this
  verification.
- `node_modules/tablednd/.scannerwork/` contains SonarQube scratch files that
  ideally wouldn't be committed.

## Report

```text
Status: PASS
Necesită `yarn install`: NU
`package.json` și `yarn.lock` sincronizate: DA
Dependențele sunt instalate: DA
```

No local `yarn install` is required; nothing needs to be updated or committed
in Git for dependency purposes.

Refs: GitHub issue #590 · Regression suite #8.x in `tmp/TLU_Test_Cases.md`
