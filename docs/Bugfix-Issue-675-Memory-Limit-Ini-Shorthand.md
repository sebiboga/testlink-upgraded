# memory_limit ini-shorthand mis-parsed as MegaBytes in installer check (Issue #675)

Issue: https://github.com/sebiboga/testlink-upgraded/issues/675
Fix commit: `12d10e954` on branch `fix/issue-675`

## Symptom

`install/installCheck.php` (*Web and PHP configuration* table) rendered a false
warning whenever PHP ran with a `G`/`K`-suffixed `memory_limit`:

```
tab-warning'>1 MegaBytes - We suggest 64 MB in order to manage hundred of test cases
```

…although `1G` (= 1024 MB) is far above the 64 MB recommendation. `2G` showed
"2 MegaBytes"; `32K` would have warned "32 MegaBytes".

## Root cause

`lib/functions/configCheck.php` — `check_php_settings()` parsed the value with

```php
$memory_limit = intval(str_ireplace('M','',ini_get('memory_limit')));
```

Only the letter `M` was stripped before `intval()`, so any other PHP ini
shorthand collapsed to its leading digits (`intval('1G') === 1`). The `-1`
unlimited sentinel added by #484 worked because it survives this cast.

## The fix

Suffix-aware parsing inside `check_php_settings()` only:

* value read once via `strtolower(trim(ini_get('memory_limit')))`;
* `'-1'` → unlimited sentinel (unchanged behavior from #484);
* unit switch: `g` → ×1024 MB · `m` → ×1 MB · `k` → ÷1024 MB
  · no suffix → bytes ÷ 1048576 (PHP treats bare ints as bytes);
* display formatted to at most 2 decimals so sub-MB values render sanely;
* warning gate / OK branch now driven by `$memory_limit_unlimited`.

## Verification

| ini value | Pre-fix | Post-fix |
|---|---|---|
| `1G` | ⚠ "1 MegaBytes" | OK (1024 MegaBytes) |
| `2G` | ⚠ "2 MegaBytes" | OK (2048 MegaBytes) |
| `512M` | OK (512 MegaBytes) | unchanged |
| `64M` | OK (64 MegaBytes) | unchanged |
| `-1` | OK (unlimited) | unchanged |
| `131072K` | (n/a) | OK (128 MegaBytes) |
| `134217728` (bytes) | (n/a) | OK (128 MegaBytes) |

Live HTTP against a server started with `-d memory_limit=1G`: row switched from
`tab-warning "1 MegaBytes"` to `tab-success "OK (1024 MegaBytes)"`. Main app
login regression OK; `events` table: no new Error/Warning entries.

Regression suite: *Suite 675* in `tmp/TLU_Test_Cases.md` (10/10 PASS).

Screenshots: `docs/screenshots/issue-675-before-fix-1G-warning.png`,
`docs/screenshots/issue-675-after-fix-1G-ok.png`.
