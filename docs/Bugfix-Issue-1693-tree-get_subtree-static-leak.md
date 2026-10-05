# Bugfix: tree::_get_subtree() static state causes cross-call contamination

## Issue
[#1693 — tree::_get_subtree(): 'static $my' is process-wide, so one direct call silently empties the subtree of the next (tree.class.php:873)](https://github.com/sebiboga/testlink-upgraded/issues/1693)

## Root Cause
- `tree::_get_subtree()` declared `static $my` (lib/functions/tree.class.php:873-884), shared across all instances and calls in the same PHP process.
- Defaults initialized only once (`if(!$my)`). 
- Subsequent calls did `array_merge($my['filters'], (array)$filters)` and `array_merge($my['options'], (array)$options)`; keys omitted by the caller were preserved from the previous call, causing cross-call/options contamination.
- When a call with `order_cfg=rspec` ran first, a later bare-options call retained rspec options and returned the wrong subtree set.

This was the twin of #1607 (which fixed `_get_subtree_rec()`).

## Fix
Applied minimal change in `lib/functions/tree.class.php`:
- Removed `static $my` and one-time init in `_get_subtree()`.
- Initialize local `$defaultsFilters` and `$defaultsOptions` on every call.
- Use `array_merge($defaultsFilters, (array)$filters)` and `array_merge($defaultsOptions, (array)$options)`.
- Pass merged context in recursion (`$my['filters']`, `$my['options']`) instead of raw args.

## Verification
Repro script `tmp/verify_leak5.php`:
- Before fix: 0:13, 1:2, 2:2 (leak — call 2 matched call 1, not pristine)
- After fix:  0:13, 1:2, 2:13 (fixed — call 2 equals pristine)

## Files Changed
- `lib/functions/tree.class.php` (removed static state, per-call defaults, recursion uses merged context)

## Commit
- 487c7ccc1 fix(tree): remove process-wide static state in _get_subtree() causing cross-call contamination
