# Issue 645 — E_WARNING 'Trying to access array offset on false' at execSetResults.php:1545-1547

## Issue #645

**Test Case Execution → Execute Tests**: opening any test case for execution
(`lib/execute/execSetResults.php`) produced **three E_WARNING rows per
page load** into the Event Viewer (`events` table), all at lines 1545–1547:

```
E_WARNING: Trying to access array offset on false
  in .../lib/execute/execSetResults.php on Line 1545
E_WARNING: Trying to access array offset on false
  in .../lib/execute/execSetResults.php on Line 1546
E_WARNING: Trying to access array offset on false
  in .../lib/execute/execSetResults.php on Line 1547
```

## Root Cause

### `get_by_id()` returns `false` when no row matches

`execSetResults.php:1543` calls:

```php
$build_info = $buildMgr->get_by_id($argsObj->build_id);
```

`build.class.php:428-430` executes a SQL query and returns the result of
`$this->db->fetch_array($result)`. When no row matches (invalid or
nonexistent `build_id`), `fetch_array()` returns `false`.

Lines 1545–1547 then index into the result without guarding:

```php
$gui->build_name = $build_info['name'];      // Line 1545
$gui->build_notes = $build_info['notes'];     // Line 1546
$gui->build_is_open = ($build_info['is_open'] == 1 ? 1 : 0); // Line 1547
```

When `$build_info` is `false`, PHP 8.x raises "Trying to access array offset on false".

### When it triggers

The execution form can be reached with a sentinel or invalid `build_id`
(e.g. `setting_platform=-1`), causing the build lookup to fail. The screen
still renders (the `build_name` is overwritten later at line 1561 from a
different source), but the three warnings pollute the Event Viewer.

## The Fix (HOW)

Wrap the three property reads in an `is_array()` guard with safe defaults:

```diff
  $build_info = $buildMgr->get_by_id($argsObj->build_id);

-  $gui->build_name = $build_info['name'];
-  $gui->build_notes = $build_info['notes'];
-  $gui->build_is_open = ($build_info['is_open'] == 1 ? 1 : 0);
+  if(is_array($build_info)) {
+    $gui->build_name = $build_info['name'];
+    $gui->build_notes = $build_info['notes'];
+    $gui->build_is_open = ($build_info['is_open'] == 1 ? 1 : 0);
+  } else {
+    $gui->build_name = '';
+    $gui->build_notes = '';
+    $gui->build_is_open = 0;
+  }
```

Why this way:
- `is_array()` correctly handles the `false` return from `get_by_id()`.
- Safe defaults (`''`, `''`, `0`) match what the screen expects when
  build info is unavailable.
- `$gui->build_name` is overwritten at line 1561 from a different source
  anyway, so the default doesn't affect visible output for valid builds.
- Minimal change: only the three affected lines are touched.

Alternatives rejected: modifying `get_by_id()` to return an empty array
would change the contract for all callers; adding a `build_id` existence
check earlier would require more code and more risk.

## Verification

Reproduced pre-fix (events 10-15 in a clean DB with execSetResults.php loaded),
then verified post-fix:

| Check | Result |
|---|---|
| Direct exec form load via JS mainframe.src | Zero E_WARNING at lines 1545-1547 |
| Execution form renders with valid build | Build info populated correctly |
| Event Viewer check | No new warnings from the guarded lines |
| PHP syntax check | `php -l` passes |

Regression suite: `tmp/TLU_Test_Cases.md` **Suite 47** — 2/2 PASS.

## Files Changed

- `lib/execute/execSetResults.php` — `is_array()` guard around `$build_info` access
