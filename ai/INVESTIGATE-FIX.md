# INVESTIGATE-FIX.md — How to Investigate, Fix & Document Issues

**Purpose:** This file defines the workflow for handling issues labeled
`investigation` — analyzing code, identifying problems, implementing fixes,
and producing documentation that follows the project standard.

**When to use:** When picking up an issue labeled `investigation` from the
GitHub Issues list. These issues are NOT auto-picked by `fix-bug.yml` — they
require explicit assignment.

---

## MANDATORY: 4 Comments on Every Issue

Every investigation issue MUST have exactly these 4 comment sections posted
on the GitHub issue, IN THIS ORDER, BEFORE the issue can be closed:

| Comment | Section | When to post |
|---|---|---|
| COMMENT 1 | `## INVESTIGATION` | BEFORE touching any code |
| COMMENT 2 | `## ROOT CAUSE` | BEFORE implementing fix |
| COMMENT 3 | `## FIX checkpoint N/M` | AFTER EACH implemented step |
| COMMENT 4 | `## VERIFICATION` | BEFORE closing the issue |

**A fix without all 4 mandatory comments counts as NOT DONE.**

---

## 1. Issue Selection

- Pick the NEWEST open issue labeled `investigation`.
- Read the FULL body and ALL comments with `gh issue view <number> --comments`.
- Understand what the issue describes: usually a code analysis with
  recommendations for fixes.

```bash
gh issue list --state open --label investigation --limit 50 \
  --json number,title,labels,createdAt \
  --jq 'sort_by(.createdAt) | reverse | first'
```

---

## 2. COMMENT 1: INVESTIGATION

Post this comment BEFORE touching any code. Template:

```markdown
## INVESTIGATION

### Environment
- App URL: http://localhost:8082
- DB: MariaDB 127.0.0.1:3306, database testlink, user testlink, password testlink
- Credentials: admin/admin
- Dataset: <describe what's in the DB>

### Files examined
1. `file.php:42-67` — <what you found>
2. `related.php:12` — <how it connects to the issue>
3. `config.php:88` — <configuration that affects this>

### Code flow analysis
<step-by-step execution path with line numbers>

Example:
```
file.php:10-15  → Load config, init DB
file.php:20-25  → Get user input from $_GET
file.php:30-35  → Process input (NO VALIDATION HERE)
file.php:40-42  → Use in SQL query / output to browser
```

### Key findings
1. **Finding 1 (file.php:30)** — <specific issue with line reference>
2. **Finding 2 (file.php:42)** — <specific issue with line reference>

### Attack vectors / failure modes
- **Vector 1:** <how the vulnerability can be exploited>
- **Vector 2:** <another way to trigger the failure>
```

---

## 3. COMMENT 2: ROOT CAUSE

Post this comment BEFORE implementing the fix. Template:

```markdown
## ROOT CAUSE

### Root cause chain
1. `file.php:42` — <what happens here>
2. `file.php:58` — <what happens next>
3. `file.php:73` — <the failure point>

### Why it breaks now (if regression)
<Which change introduced the problem, with commit hash>

### Blast radius
- Number of affected call sites: <count>
- Conditions to trigger: <specific conditions>
- Default config: <whether vulnerable path is reached by default>

### Fix plan
<Concrete approach, fallback behavior, regression matrix you will run>
```

---

## 4. COMMENT 3: FIX CHECKPOINTS

Post ONE comment after EACH implemented step. Template:

```markdown
## FIX checkpoint N/M — <what this step did>

**STEP PERFORMED** — <exactly what you just did>

**RESULT — measured:**
- `php -l file.php` → No syntax errors
- `curl ...` → <actual output>
- <other evidence>

**DISCOVERIES** — <surprises, corrections to earlier claims>

**PLAN** — <the single next step>

**FILES** — <files touched or 'none (testing only)'>
```

Example for a 3-step fix:
- `## FIX checkpoint 1/3 — fix SQL injection in file.php`
- `## FIX checkpoint 2/3 — add input validation in file.php`
- `## FIX checkpoint 3/3 — add tests for file.php`

---

## 5. COMMENT 4: VERIFICATION

Post this comment BEFORE closing the issue. Template:

```markdown
## VERIFICATION

### Commit hashes + push range
- `abc1234` — fix(security): prevent SQL injection in file.php (Refs #<n>)
- `def5678` — docs: add Bugfix Issue #<n> documentation
- Branch: `fix/issue-<n>` (pushed to origin)

### Diff summary (file, +/−)
- `file.php` — lines 30-45: added input validation, escaped SQL output (+12, −3)

### Verification matrix
| # | Check | Expected | Result |
|---|---|---|---|
| 1 | SQL injection via type param | Query rejected, no error | ✅ PASS |
| 2 | Normal page load | Renders correctly | ✅ PASS |
| 3 | Event Viewer | No new errors | ✅ PASS |

### Event Viewer check
- Pre-fix: 2 warnings (existing)
- Post-fix: 2 warnings (unchanged)
- New errors introduced: 0

### RESUME block
To re-test: `curl "http://localhost:8082/file.php?type=malicious"`
Expected: HTTP 400 or redirect to safe page
```

---

## 6. Documentation File

After posting all comments, create a documentation file:

```
docs/Bugfix-Issue-<n>-<Short-Title>.md
```

### Required sections (same content as the 4 comments):

```markdown
# Issue <n> — <Descriptive title>

**Issue:** [#<n>](https://github.com/sebiboga/testlink-upgraded/issues/<n>)
**Commit:** `<hash>`
**Status:** VERIFIED-FIXED (<date>)

## Investigation
<same content as COMMENT 1>

## Root cause chain
<same content as COMMENT 2>

## Fix approach (the HOW)
### Chosen fix
<implementation>

### Why this approach
<reasons>

### Rejected alternatives
<what you considered and rejected>

## Files changed
<specific line numbers>

## Verification performed (<date>)
<same content as COMMENT 4>

## Notes
<any additional observations>
```

---

## 7. Git & Commit Rules

### Branch naming
```bash
git checkout -b fix/issue-<n>-<short-slug>
```

### Commit messages
```bash
# Fix commit
git commit -m "fix(<area>): <one-line description> — Refs #<n>"

# Documentation commit
git commit -m "docs: add Bugfix Issue #<n> <short title> documentation"
```

### Push
```bash
git push sebiboga sebiboga
```

### Issue closure
```bash
gh issue close <n> --comment "## VERIFICATION

### Commit hashes
<hashes>

### Verification matrix
<table>

### RESUME block
<how to re-test>"
```

---

## 8. Quality Checklist

Before closing, verify:

- [ ] COMMENT 1 (INVESTIGATION) posted with Environment, Files examined, Code flow, Key findings
- [ ] COMMENT 2 (ROOT CAUSE) posted with Root cause chain, Blast radius, Fix plan
- [ ] COMMENT 3 (FIX checkpoint) posted for EACH step with STEP PERFORMED, RESULT, DISCOVERIES, PLAN, FILES
- [ ] COMMENT 4 (VERIFICATION) posted with Commit hashes, Diff summary, Verification matrix, Event Viewer
- [ ] Documentation file created in docs/ with all sections
- [ ] No 'evil.com' or similar — use neutral placeholders
- [ ] All file:line references are specific and accurate
- [ ] Event Viewer shows no new errors
- [ ] Issue closed with verification evidence

---

## 9. Example: Issue #706 (XSS fixes)

### COMMENT 1: INVESTIGATION
```
## INVESTIGATION

### Environment
- App URL: http://localhost:8082
- DB: MariaDB 127.0.0.1:3306, database testlink, user testlink, password testlink
- Credentials: admin/admin
- Dataset: Fresh import, admin user with cookie_string

### Files examined
1. `logout.php:33-36` — addslashes bypass via </script> injection
2. `lnl.php:137` — raw output of user-controlled $args->type
3. `index.php:122-126` — weak blocklist filter for reqURI

### Code flow analysis
logout.php:26 → $std built from $_GET['viewer']
logout.php:30 → $lo = $std when logoutUrl empty
logout.php:33 → $safeUrl = addslashes($lo) — does NOT escape < >
logout.php:34-36 → echo "<script>location.href='$safeUrl';</script>"

### Key findings
1. addslashes escapes ', ", \, NUL but NOT <, >, /
2. Attacker injects </script><script>alert(1)</script> via viewer param
3. Script block closes before JS parser runs

### Attack vectors
- Vector 1: </script><script> injection via viewer GET param
- Vector 2: data: URI via reqURI param in index.php
```

### COMMENT 2: ROOT CAUSE
```
## ROOT CAUSE

### Root cause chain
1. logout.php:26 — $args->viewer flows into $std unsanitized
2. logout.php:33 — addslashes() is SQL-quoting, not XSS mitigation
3. logout.php:34-36 — Output in <script> block allows breakout

### Blast radius
- 3 files affected (logout.php, lnl.php, index.php)
- Trigger: any user with URL access (no auth required for some paths)
- Default config: vulnerable paths reached by default

### Fix plan
1. Replace JS redirect with header("Location:") in logout.php
2. Add htmlspecialchars() to lnl.php:137
3. Replace blocklist with allowlist in index.php:122-126
```

### COMMENT 3: FIX checkpoints
```
## FIX checkpoint 1/3 — logout.php: replaced JS redirect with PHP header()

**STEP PERFORMED** — Replaced addslashes + JS echo with header("Location:")
**RESULT — measured:**
- `php -l logout.php` → No syntax errors
- `curl "logout.php?viewer=</script><script>alert(1)</script>"` → HTTP 302
**DISCOVERIES** — None
**PLAN** — Fix lnl.php:137
**FILES** — logout.php

## FIX checkpoint 2/3 — lnl.php:137 — added htmlspecialchars()

**STEP PERFORMED** — Wrapped $args->type in htmlspecialchars()
**RESULT — measured:**
- `php -l lnl.php` → No syntax errors
- `curl "lnl.php?type=<script>alert(1)</script>"` → Output: &lt;script&gt;
**DISCOVERIES** — None
**PLAN** — Fix index.php:122-126
**FILES** — lnl.php

## FIX checkpoint 3/3 — index.php: replaced blocklist with allowlist

**STEP PERFORMED** — Replaced stripos blocklist with preg_match allowlist
**RESULT — measured:**
- `php -l index.php` → No syntax errors
- `curl "index.php?reqURI=data:text/html,<script>alert(1)</script>"` → Falls back to mainPage.php
**DISCOVERIES** — None
**PLAN** — Run verification matrix
**FILES** — index.php
```

### COMMENT 4: VERIFICATION
```
## VERIFICATION

### Commit hashes
- `ed454f51f` — fix(xss): sanitize output in logout.php, lnl.php, index.php
- `7f52fd524` — docs(xss): add bugfix documentation for issue #706

### Diff summary (3 files, +9 / -11)
- logout.php — removed 5 lines (addslashes + JS redirect), added 2 lines (header + exit)
- lnl.php — line 137: wrapped in htmlspecialchars()
- index.php — lines 122-126: replaced blocklist with allowlist

### Verification matrix
| # | Check | Expected | Result |
|---|---|---|---|
| 1 | logout.php </script> injection | HTTP 302, no JS body | ✅ PASS |
| 2 | lnl.php <script> in type param | Output escaped | ✅ PASS |
| 3 | index.php data: URI | Falls back to mainPage.php | ✅ PASS |
| 4 | index.php vbscript: URI | Falls back to mainPage.php | ✅ PASS |
| 5 | Event Viewer | No new errors | ✅ PASS |

### RESUME block
Re-test: `curl "http://localhost:8082/logout.php?viewer=</script><script>alert(1)</script>"`
Expected: HTTP 302 redirect to login.php
```

---

## 10. Common Patterns

### Security issues
- SQL injection: unescaped user input in queries
- XSS: unescaped output in HTML/JS
- Header injection: unescaped `\r\n` in HTTP headers
- Open redirect: unvalidated URLs in redirect targets
- Secrets in code: real credentials committed to repo

### Code quality issues
- Dead code: unreachable statements
- Undefined variables: PHP 8 warnings
- Wrong return types: boolean instead of object
- Debug artifacts: `echo`, `var_dump`, `print_r` left in production

### Systemic issues
- Same pattern repeated across multiple files
- Helper function with vulnerability used by many callers
- Config value used without validation in multiple places
