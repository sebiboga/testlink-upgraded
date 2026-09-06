# Bugfix — Issue #1073: fix-bug factory triage mis-selects unlabeled enhancement as a bug

## Problem

The autonomous bug-fixing factory (`fix-bug.yml`, rulebook `ai/FIX-ISSUE.md`, triage in
`ai/AGENTS.md` #21) is supposed to pick the **newest open bug** to fix. Instead, on this
run it picked issue **#1073 "Enhancement: Add Metrics & Trends dashboards…"** as its bug
candidate.

Why: `fix-bug.yml` selects *"the newest open issue that does NOT carry a work-type label
(`enhancement`, `task`, `investigation`, `documentation`)"*. A batch of **21 issues
(1053–1073)** was opened on 2026-09-06 **without any label**. Those issues are all
feature/analysis proposals (enhancement feature-requests plus #1054 ISTQB Compliance
Review), but because they carried no work-type label the factory cannot tell them apart
from genuine bugs — so it kept returning the newest one (#1073) and burning an entire
2-hour agent run trying to "reproduce" a feature request. If left unlabeled, the next 21
fix-bug runs would each waste their budget on one enhancement.

## Measured evidence (pre-fix)

- Open issue count: **190**.
- Label histogram of all open issues: `task` → 158, `enhancement` → 11, unlabeled `[]` → 21, `bug` → **0**.
- Triage command output (pre-fix):
  ```bash
  gh issue list --state open --limit 200 --json number,title,labels,createdAt \
    --jq 'map(select([.labels[].name] | any(. == "enhancement" or . == "task" or . == "investigation" or . == "documentation") | not)) | first'
  # => {"createdAt":"2026-09-06T10:58:15Z","labels":[],"number":1073,"title":"Enhancement: Add Metrics \u0026 Trends dashboards..."}
  ```
- #1073 body verified by reading it: a **feature proposal** ("Add a dedicated Metrics &
  Trends area under the Reports menu…") with an Acceptance-criteria list. No symptom, no
  regression. Not a bug.

## Root Cause

1. `ai/AGENTS.md` #21 defines triage: `fix-bug.yml` picks the newest open issue without a
   work-type label.
2. Issues 1053–1073 were created with `labels: []` (metadata defect — the creators did
   not apply the required work-type labels).
3. Because those issues are invisible to the factory's skip-list, they are indistinguishable
   from bugs, so the triage returns them as "the bug to fix".

Blast radius: 21 unlabeled issues out of 190 (≈11%). No application code path affected —
the defect is purely in the CI triage **metadata**.

## Fix (minimal)

Per AGENTS.md #21 ("ALWAYS label them correctly so the triage works"), each unlabeled
issue got its correct work-type label:

- 20 feature-request issues → `enhancement`:
  `gh issue edit 1053 1055 1056 1057 1058 1059 1060 1061 1062 1063 1064 1065 1066 1067 1068 1069 1070 1071 1072 1073 --add-label enhancement`
- #1054 "ISTQB Compliance Review" (analysis/study → matrix + backlog, not a feature) → `investigation`:
  `gh issue edit 1054 --add-label investigation`

## Verification (error-free)

1. **Triage now returns no bug candidate**: the jq from above emits **empty**.
2. **Bug-label open count**: 0 (no genuine open bug ever existed).
3. **No unlabeled open issues remain**: 0.
4. **Labels read back**: #1073 `enhancement`, #1054 `investigation`, #1068 `enhancement`.

## Regression

`tmp/TLU_Test_Cases.md` → `Regression — Issue #1073` suite (RF-1073.1 … RF-1073.7),
result 7/7 PASS. No application code or i18n bundles touched.

## Files changed

None (metadata-only fix on GitHub issues; no repo source files altered on this run —
this document and the TLU suite are the only local artifacts).

## Refs

#1073 — this issue was the selected target; the actual defect fixed is the factory
triage metadata that caused enhancement #1073 to be mis-selected as a bug.
