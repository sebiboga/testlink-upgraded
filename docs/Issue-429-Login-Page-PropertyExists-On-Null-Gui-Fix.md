# Issue 429 — Login page fatal: `property_exists()` called on null `$gui`

**Issue:** [#429](https://github.com/sebiboga/testlink-upgraded/issues/429)
**Branch:** `fix/issue-429` · **Fix commit (pre-existing):** `01b11e300` · **Verification run:** 2026-08-23
**Status:** FIXED & VERIFIED (fix landed in `01b11e300`; this run verified it end-to-end and closed the issue)

## Symptom

On PHP 8.3, rendering a page whose template includes
`gui/templates/dashio/include/inc_head.tpl` **without** a controller-assigned `$gui`
object produced a fatal blank page:

```
TypeError: property_exists(): Argument #1 ($object_or_class) must be of type object|string, null given
in gui/templates/dashio/include/inc_head.tpl on line 66
```

Reported against the login flow; any TLSmarty render of an inc_head-consuming
template with unassigned `$gui` hit the same fatal.

## Root cause chain

1. `4fe583e2d` introduced the Dashio theme for TestLink 2.0.1 on PHP 8.3.
2. Every Dashio shell screen includes `inc_head.tpl` (common HTML head).
3. Pre-fix `inc_head.tpl` old lines 66 & 70 ran `{if property_exists($gui,'tproject_id')}`
   / `{'tplan_id'}` unconditionally (`git show 01b11e300~1:gui/templates/dashio/include/inc_head.tpl`).
4. With no `$smarty->assign('gui', …)` on the render path, Smarty yields `null`
   into the compiled expression.
5. PHP ≥ 8 turns `property_exists(null, …)` from silent `false` (PHP 7 behaviour)
   into a thrown `TypeError` → fatal, white page.

## Fix — already landed, verified by this run

Commit `01b11e300` added an `isset($gui) &&` guard before both calls:

```smarty
{if isset($gui) && property_exists($gui,'tproject_id')}
  {$tproject_id = $gui->tproject_id}
{/if}

{if isset($gui) && property_exists($gui,'tplan_id')}
  {$tplan_id = $gui->tplan_id}
{/if}
```

Now at `inc_head.tpl:58` and `inc_head.tpl:62`. Guarding the shared include
protects every consumer page at once; alternatives (per-controller dummy `$gui`,
Smarty nullsafe operator) were rejected — Smarty 3 has no `?->`.

## Verification evidence (2026-08-23, PHP 8.3.33)

| Check | Result |
|---|---|
| Mechanism repro: `property_exists(null,…)` on this build | TypeError, byte-identical message |
| Smarty harness (repo's `vendor/smarty`), unguarded pattern | FATAL TypeError reproduced |
| Smarty harness, guarded pattern | renders OK |
| Landed fix present on default branch @ `104fecdd3` | guards at lines 58/62 |
| `GET /login.php` | HTTP 200, full form HTML, zero fatal strings |
| Browser login POST admin/admin | main frame loads (navBar/asideMenu/mainframe) |
| inc_head-consuming screen (projectEdit create) | fully rendered |
| `/login.php?note=expired` + logout cycle | clean 200 renders both paths |
| Event Viewer (`events` table) after full matrix | only AUDIT rows (login/logout); **zero Error/Warning** |



## Residual pattern risk (documented, not reachable)

~40 further unguarded `property_exists($gui,…)` sites exist across
`dashio`/`tl-classic` templates (e.g. `attachments.inc.tpl:132,136,140,144`,
`keywords.inc.tpl:59,63,66`, `relations.inc.tpl:71,75`). None is reachable with
a null `$gui`: each template also dereferences `$gui->…` unconditionally
elsewhere (so null would fatal there regardless), and every current controller
of those include sites assigns a gui object. No code change required for #429;
listed for future hardening sweeps only.

## Regression suite

See `Suite 429` in `tmp/TLU_Test_Cases.md (repo root)` (10 checks, all PASS).
