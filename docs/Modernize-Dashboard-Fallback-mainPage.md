# Modernize-Dashboard-Fallback-mainPage (#1555)



The modern dashboard — `gui/templates/mainpage/mainPage.html` + BFF
`api/mainpage/index.php` — has been the mainframe landing since #780. This
tracker closes the last legacy `lib/general/*` standalone screen: the legacy
**`lib/general/mainPage.php`** controller. It still rendered the legacy Smarty
dashboard at stale deep links and shipped a stale zero-test-projects bootstrap
(`redirect('...lib/project/projectEdit.php?doAction=create')`) even though the
running frameset (`index.php getReturnWorkArea()`) already points the mainframe
at the modern screen. It is now a thin session-guarded **302 redirect shim**.

## Background

The **TODO section of `docs/MODERNIZATION-STATUS.md` is empty**: every ASIDE
entry was already verified to map to a modern `gui/templates/**/*.html` screen
+ BFF. Per the `modernize.yml` guidance ("if the TODO section is empty, say so
in one line, then pick the smallest coherent legacy standalone screen"), the
legacy Dashboard controller was picked — the last non-shim `lib/general/*`
full-page controller still rendering legacy HTML (navBar, asideMenu and
show_help were already shimmed in #1547/#1548/#1552).

## Deliverables

- **Shim:** `lib/general/mainPage.php` — a session-guarded redirect:
  1. `testlinkInitPage($db, TRUE)` (preserves the anonymous → login contract,
     exactly like the legacy controller did).
  2. Refs #967 parity zero-test-projects bootstrap: admin who can
     `mgt_modify_product` on a system with **no test project at all** →
     302 to the **modern** `gui/templates/projects/projectEdit.html` (the
     legacy inline redirect to `lib/project/projectEdit.php?doAction=create`
     is deleted).
  3. Otherwise → 302 to `_SESSION['basehref']gui/templates/mainpage/
     mainPage.html?tproject_id=<session>&tplan_id=<request-or-session>`,
     forwarding the legacy `?testplan=` deep-link parameter.
- **Dead code removed:** ~600 lines of legacy dashboard helpers
  (`getDashboardData`, `getTestCaseGrowthData`, `getBugsTestedData`,
  `getProjectIssuesData`, `getUserDocumentation`, the old `init_args`/
  `initializeGui`/`getGrants` full page logic). The modern BFF already ports
  every widget.
- **Relocation:** `getUserDocumentation()` moved into
  `lib/functions/common.php` — `initUserEnv()` (called by every legacy page
  that renders the menu, feeding `$gui->docs`) still depends on it, so leaving
  it in the deleted controller body would have been a latent PHP fatal.
- **No new i18n keys, no link-switch change:** the screen + BFF were already
  fully localized and `$actions->dashboard`/`getReturnWorkArea()` already point
  at the modern screen.

## Verification

- **Login frameset** (admin, fixture project DASH id=1 / plan id=2): mainframe
  is the modern `mainPage.html`; Dashboard header + context `DASH`/`Plan DASH`;
  tcGrowth widget renders (2 test cases this month); console clean.
- **Deep link:** in the session, `lib/general/mainPage.php?testplan=2` → 302 to
  `mainPage.html?tproject_id=1&tplan_id=2`; window title "Dashboard"; widgets
  render.
- **Anonymous:** `lib/general/mainPage.php` → legacy login redirect
  (`login.php?note=expired&destination=...`), no dashboard HTML, no error.
- **Zero-project bootstrap:** with an empty DB, admin lands on the modern
  Project Create/Edit screen (`projectEdit.html`), not the legacy
  `projectEdit.php` (observed on the fresh import before fixture seeding).
- `php -l` clean on both changed files; Event Viewer clean (no ≥32 rows);
  console clean.

## Test cases

Suite **1555 (Screen — Legacy Dashboard fallback)** in `tmp/TLU_Test_Cases.md`.

Screenshot: `docs/screenshots/issue-1555-mainpage-shim.png`