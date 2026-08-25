# ISSUES.md — How to DOCUMENT bugs: creation → investigation → fix

**Why this file exists:** agents used to "do things" on GitHub Issues and nobody could
understand afterwards what happened, why, or whether it was verified. Documentation IS
the deliverable. A fix without a documented trail counts as NOT DONE.
**Reference implementation (read it before your first run):**
[#568 — BLOCKER: no report can be generated](https://github.com/sebiboga/testlink-upgraded/issues/568)
— its comment trail is the gold standard every bug must follow.

---

## 0. Golden rules

1. **Every bug issue gets the `bug` label AT CREATION** (`gh issue create --label bug ...`).
   The CI triage skips anything labeled `enhancement` / `task` / `investigation` /
   `documentation` — unlabeled issues break the factory.
2. **Never post a naked claim.** Every statement ("it freezes", "it works now") carries
   MEASURED evidence next to it: log lines, console output, CPU numbers, timings.
3. **No step without documenting the previous step.** One work step = one checkpoint
   comment, posted IMMEDIATELY after the step, BEFORE starting the next one.
4. **Write for someone who was not there.** Exact URLs clicked, exact commands run,
   exact file:line touched, exact commit hashes.

---

## 1. CREATION — when you find/report a bug

```bash
gh issue create --label bug --title "<AREA>: <symptom> — <impact>" --body ...
```

Body MUST contain, in this order:

| Section | Content |
|---|---|
| **Symptom** | What happens, where, since when. Quantify ("all reports", "every view"). |
| **Repro steps** | Numbered, click-by-click, with entry-point URLs. Must be executable by a stranger. |
| **Expected** | What SHOULD happen. |
| **Suspected root cause** | Hypothesis ONLY, clearly marked as unconfirmed, with candidate commits/files. |
| **Evidence** | What you already measured (log excerpts, console errors, screenshots). |
| **Priority** | blocker / major / minor + one line why. |

Example title: `reqView.php: 3x E_WARNING "Undefined property..." on every view`.

---

## 2. INVESTIGATION — reproduce & measure before touching ANY code

Post ONE comment on the issue containing:

1. **Environment**: app URL, DB, credentials used, dataset (project/plan ids).
2. **Exact repro steps** performed against THAT environment.
3. **Measured evidence, layer by layer** — prove WHERE the fault lives:
   - server access log (did the request arrive? when did it complete?)
   - server process CPU while symptom occurs (idle = not a loop; 100% = loop)
   - browser console errors (exact message, occurrence count)
   - browser network panel (which request fired / didn't fire)
4. **REFINED diagnosis** — correct any wrong initial assumption explicitly.
   (On #568 the report "infinite loop" was disproven: PHP idle 0% CPU, zero requests,
   console `TypeError ... setting 'location'` ×4 → dead navigation handler.)

> Rule: if you cannot reproduce, say so in the same format — what you tried, what you
> expected to see vs saw. Never fake evidence.

---

## 3. ROOT CAUSE — before writing the fix

One comment with:

- **Root cause chain**, each hop backed by `file:line` (and commit hash when relevant).
- **Why it breaks NOW** — which change/context introduced it (regression source).
- **Blast radius** — grep counts / affected call sites / other screens sharing the path.
- **Fix plan** — concrete code sketch, fallback behaviour, and the regression matrix
  you will run afterwards (enumerate every case).

---

## 4. FIXING — checkpoint comments, one per step

Cadence: **implement one step → immediately comment → only then next step.**

```markdown
## 🔧 FIX checkpoint N/M — <what this step did>

**STEP PERFORMED** — <exactly what you just did>
**RESULT — measured:** <command output / console state / grep counts / syntax check>
**DISCOVERIES** — <surprises, corrections to earlier claims>
**PLAN** — <the single next step>
**FILES** — <files touched or 'none (testing only)'>
```

Mandatory gates inside the sequence:
- syntax gate before browser testing (`node --check`, `php -l`, `python3 -m json.tool`);
- live verification of the PRIMARY symptom;
- regression matrix from §3 executed item by item, results written here.

---

## 5. EVIDENCE — screenshots & logs

- UI bugs: full-page screenshot BEFORE and AFTER fix.
  Store under `docs/screenshots/issue-<n>-<slug>.png`, commit to repo, link in the
  comment (`![report generated](docs/screenshots/issue-568-testplan-report-generated.png)`).
- Backend bugs: paste the relevant log TAIL (few lines, with timestamps) directly into
  the comment. Redact secrets.
- Timings: quote `Accepted → [200]` pairs from the access log when performance matters.

---

## 6. CLOSURE — push, final comment, close decision

1. Commit message: conventional prefix + one-line cause + `Refs #<n>`
   (`Fixes #<n>` ONLY when you personally verified the fix error-free AND the fix is pushed).
2. Final comment on the issue:
   - **commit hash(es)** + push range,
   - diff summary (file, +/-),
   - **VERIFIED vs REMAINING checklist** (explicitly unfinished items stay checked-off false),
   - RESUME block (how to re-test in one command/browser path).
3. Close the issue ONLY when: fix pushed ✓ primary symptom verified ✓ regression matrix
   passed ✓ Event Viewer shows no new Error/Warning ✓. Otherwise keep OPEN with the
   remaining checklist visible at the top of your last comment.

---

## 7. Performance complaints are issues too

If something is slow, do not say "it's slow" in chat. Measure, then file with:
`Accepted → [200]` timestamp pairs proving duration, dataset size (to show it is
unreasonable), and the timeout signature if present (e.g. exactly 60s ⇒ suspect
`default_socket_timeout` / outbound fetch). See issue **#569** (60s report generation)
as the worked example.
