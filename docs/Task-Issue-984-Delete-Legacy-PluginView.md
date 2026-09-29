# Task — Issue #984: Delete legacy pluginView

**Status:** done · **Issue:** [#984](https://github.com/sebiboga/testlink-upgraded/issues/984) (task, closed) · **Refs #983**

## Goal

Retire the legacy 1.9.20 plugin-management screen now that
`gui/templates/plugins/pluginView.html` (backed by `api/plugins/index.php`)
matches it. #983 — the parity analysis this issue depends on — is
`CLOSED/COMPLETED`.

## What the issue got wrong

Two corrections to the issue body, both verified against the tree:

**It cites `lib/functions/common.php:1891-1893` for `$actions->pluginView`.**
The assignment is at **`common.php:1988`**; lines 1891-1893 are unrelated
context in a different function. The claim itself holds — `$actions->pluginView`
does resolve to `/gui/templates/plugins/pluginView.html?{$ctx}`.

**It says nothing about `cfg/const.inc.php:914`.** That line is a live
`$tlCfg->guiTopMenu[8]` registration still pointing at
`lib/plugins/pluginView.php`, and the issue's "no other lib/ file references
it" claim does not survive it. It is covered below.

## Precondition

`api/plugins/index.php` (146 lines) enforces `mgt_plugins` once, before the
route switch, so it guards every route by construction:

```php
if (!$user->hasRight($db, 'mgt_plugins')) {
  bffFail(403, 'No permission');
}
```

The legacy controller enforced the same right via `checkRights()`
(`lib/plugins/pluginView.php:86-88`), so the right is not weakened by the
deletion.

## Removed — 3 files, 315 lines

| File | Lines |
|---|---|
| `lib/plugins/pluginView.php` | 88 |
| `gui/templates/dashio/plugins/pluginView.tpl` | 112 |
| `gui/templates/tl-classic/plugins/pluginView.tpl` | 112 |

`lib/plugins/` is left empty and disappears with them.

## One live config reference retargeted

`cfg/const.inc.php:912-916` registered Plugins in the top menu with the legacy
URL:

```php
$tlCfg->guiTopMenu[8] = array('label' => 'title_plugins',
                              'url' => 'lib/plugins/pluginView.php',
```

Now `'gui/templates/plugins/pluginView.html'`, with the same marker comment the
adjacent `guiTopMenu[7]` carries from #1579.

Being precise about what this is worth, because it is easy to overstate: the
config really does execute — `testlinkInitPage()` calls `initTopMenu()`
(`common.php:517`) on every page load, and `initTopMenu()` reads the URL
verbatim at `common.php:410`. But the HTML it builds lands in
`$_SESSION['testprojectTopMenu']`, whose only renderer is
`gui/templates/tl-classic/navBar.tpl:48` — dead, since `tlsmarty.inc.php:101`
pins `template_dir` to `gui/templates/dashio/`.

So the old URL was reachable configuration pointing at a deleted file, not a
link any user can click today. It is retargeted for consistency with the
precedent one entry above, not to fix a visible break. The path is bare, like
entry [7]'s, because `initTopMenu` does not append context — verified by
loading `pluginView.html` with no query string, which renders correctly from
the session.

## Provenance comments

Two comments named the legacy controller without citing line numbers, so they
did not dangle, but both now point at a file that is gone. Annotated in place
rather than left to mislead:

- `lib/functions/common.php:1987` — `lib/plugins/pluginView.php (removed in #984)`
- `api/plugins/index.php:6` — `lib/plugins/pluginView.php (removed in #984)`

## Verification

- **12/12 BFF assertions**, identical before and after deletion: `GET
  ?action=list` shape, `canManage`, install → present in `installed` and absent
  from `available`, uninstall, and the three rejections (unknown plugin 404,
  path-traversal name 400, invalid id 400), plus `GET` without an action 405.
  The suite performs a real install/uninstall round-trip of `TLTest` and
  asserts the `plugins` table returns to its starting state — it was 0 rows
  before and after.
- **`mgt_plugins` gate**: a temporary role-98 / user-9100 fixture with no
  plugin rights received `403 {"status":"error","message":"No permission"}` on
  `?action=list`. Both fixture rows were deleted afterwards.
- Legacy `lib/plugins/pluginView.php` → **404**. Modern
  `pluginView.html` → renders. `php -l` clean on the three edited files.
  Browser console clean on a fresh load.
- Baseline before deletion, for contrast: the legacy URL returned **200** and
  rendered the old tables, as the issue predicted.

## Files touched

```
 cfg/const.inc.php                                |   2 +-
 lib/functions/common.php                         |   3 +-
 api/plugins/index.php                            |   2 +-
 lib/plugins/pluginView.php                       |  88 ----
 gui/templates/dashio/plugins/pluginView.tpl      | 112 ----
 gui/templates/tl-classic/plugins/pluginView.tpl  | 112 ----
```
