# Modernized: Bug Add / Link popup (bugAdd)

Issue: [Refs #1560](https://github.com/sebiboga/testlink-upgraded/issues/1560)
Legacy: `lib/execute/bugAdd.php` + `gui/templates/dashio/execute/bugAdd.tpl`
Modern: `gui/templates/execute/bugAdd.html` + BFF `api/bugadd/index.php`

## What was modernized

The "Bug Add / Link" popup lets an executor attach bugs to an execution (and to
a specific step), create a new issue on the project's bug tracker and append
notes to existing issues. It was the last standalone `lib/execute/*` screen
without a modern counterpart. It is now a Dashio Bootstrap popup with three tabs:

- **Link bug** — type a bug/issue id, optional note with `%%EXECID%%` /
  `%%TESTER%%` / `%%BUILD%%` / `%%TPLAN%%` / `%%TCNAME%%` style tag
  substitution, optional "add link in tracker to this execution (or its print
  view)". Reuses the legacy `generateIssueText()` on the server so tag
  substitution and the direct-link block behave exactly like 1.9.20.
- **Create issue** — title + description + tracker metadata selects (issue
  type, priority, component, version) when the tracker enables user
  interaction, plus the same TL-link options. Creation goes through the legacy
  `exec.inc.php:addIssue()` so audit (`audit_executionbug_added`) and
  `execution_bugs` bookkeeping are identical to legacy.
- **Add note** — append a note to an already-linked issue. The bug id is
  prefilled (read-only) from the `bug_id` URL parameter, mirroring how the
  executor flows open the popup for an existing bug.

The old controller became a session-guarded 302 shim that forwards to the
modern popup (the pattern used by every other modernized screen).

## BFF (api/bugadd/index.php)

- `GET ?action=init&exec_id=N&user_action=link|create|add_note[&tcstep_id=S]`
  — context card fields, execution defaults, tracker capabilities
  (`enabled`, `create_issue_url`, max id/summary lengths, `tl_can_*`), and
  the metadata the create form needs: `edit_issue_attr` + `issue_metadata`
  (type/priority/component/version, in the `{items:{id:name},isMultiSelect}`
  wrapper shape some trackers emit).
- `POST ?action=link|create|add_note` — JSON body, writes `execution_bugs`,
  returns the created bug id (additive `$ret['bug_id']` in `addIssue()`),
  and logs `audit_executionbug_added`.
- Security: session auth (401), `testplan_execute` right gate (403), the
  shared `bffSameOriginGuard()` CSRF proof for non-safe verbs (403), strict
  validation (404 unknown execution, 400 bad id / bad syntax / nonexistent
  bug), 405 on wrong method.
- The testplan API key is resolved server-side only (`bugAddArgs()`); it is
  never part of the client-facing payload.

## Issue tracker integration

Because the demo environment has no live bug tracker, the screen is tested
against a bundled **test double**: `lib/issuetrackerintegration/
mantisrestInterface.class.php` (issuetracker type 24). It is session-backed,
implements the same contract real trackers do (`checkBugIDSyntax`,
`checkBugIDExistence`, `addIssue`, `addNote`, `getBug*ForHTMLSelect`, …), and
accepts the reserved demo band 15600000..15609999 so fixtures are
deterministic across PHP processes. Created ids stay inside that band by capping
the counter.

## Rights / authz

- anonymous → 401
- logged-in user without `testplan_execute` on the plan → 403
- missing/unknown execution → 404
- non-finite id, missing body, wrong format, nonexistent bug → 400

## i18n

`buga.*` (36 keys) + `footers.bugAdd` added to all 10 locale JSON bundles
(en/ro/de/es/fr/it/ja/pt/ru/zh). No hardcoded strings in the modern page; the
locale switcher works like on every modern screen.

## Legacy gaps fixed while testing

- `exec.inc.php:addIssue()` previously never exposed the created issue id to
  callers; the BFF create response would report success with an empty id
  (legacy callers only consumed `msg`/`status_ok`, so adding `$ret['bug_id']`
  is purely additive).
- `locale/en_GB/strings.txt` was missing `tc_name` / `tc_external_id`, which
  made `generateIssueText()` log LOCALIZATION warnings for `en_GB` whenever a
  note/link flow used tag substitution. Keys added.
- The demo `norights` fixture user was created with `auth_method='TestLink'`
  (external password management), so it could never authenticate for the 403
  path; fixtures now use `DB`.

## Test coverage

Suite appended to `tmp/TLU_Test_Cases.md` — **12/12 PASS**:
BFF init/authz matrix/error contract, link + tag-substituted notes, create
with metadata + returned id, add-note, browser flows (success/error boxes,
readonly prefill, ro_RO locale, not-found state), Event Viewer hygiene.

## Fixtures

`tmp/fixtures_1560.php` (project "BugAdd Demo" / plan "BugAdd Plan" / tc
"BugAdd Login Check" / executions 3+4 / tracker double / `norights` user via
`tmp/mkuser_norights.php`). Screenshots under `tmp/shots/bugadd_*.png`.