# Task — Issue #976: Delete legacy codeTrackerView + codeTrackerEdit

**Status:** done · **Issue:** [#976](https://github.com/sebiboga/testlink-upgraded/issues/976) (task, closed) · **Refs #969**

## Goal

Retire the legacy 1.9.20 Code Tracker screens now that the modern screen
`gui/templates/codetracker/codetrackerView.html` (backed by
`api/codetracker/index.php`) covers every gap the issue enumerates.

Unlike #957 and #966, this issue carries an explicit precondition:

> Before deleting, confirm the modern screen covers #969-#975 (rights, manage
> gating, link-count gating, connection check, env check, used-by display, cfg
> template) and re-run the tests in `tmp/TLU_Test_Cases.md` for codetrackerView.

Both halves were done before deleting anything, and both are reproduced below.

## Parity gate — #969-#975

All seven are closed `COMPLETED` upstream **and** present in the code on
`origin/sebiboga`. A closed issue proves nothing on its own, so each feature was
located in the shipped source:

| Issue | Feature | Where it lives now |
|---|---|---|
| #969 | `codetracker_view` read gate, every route | `api/codetracker/index.php` — list + `/meta/types` + `/{id}` |
| #970 | `codetracker_management` write gate + UI-action gating | same file; `canManage` in the list payload drives the toolbar |
| #971 | link-count delete gating | `link_count` / `links` in the list payload |
| #972 | per-tracker connection status | `POST /{id}/test_connection` → `{connected: bool}` |
| #973 | environment check | `env_check_ok` / `env_check_msg` |
| #974 | used-on-test-project display | `links` map + the info-icon toggle |
| #975 | per-type configuration template | `GET /cfg-template?type=N` → `getCfgTemplate()` |

The two enabled interfaces match the precondition recorded in the #975 suite
exactly — `code=1 → stash` and `code=200 → github`, from
`tlCodeTracker::getTypes()` (`lib/functions/tlCodeTracker.class.php:28-29`).

## Test re-run

Baseline against the modern stack, then the identical harness after the deletion.
Both runs are the same script; it cleans up its own `ZZ976_*` fixtures first, so
it is re-runnable.

**20/20 BFF assertions** — #975 (3 cfg-template cases + types), full CRUD
round-trip (create → duplicate rejected → list → detail → 404 → update → delete →
empty), #973, #974, #970 `canManage`, #972 (GET 405 / POST 200 / unknown id 404 /
unknown action 404).

**4/4 rights assertions** — the fixtures from the #969 suite, recreated
temporarily and removed afterwards:

| Check | Expected | Actual |
|---|---|---|
| role-3 no-rights user, list route | 403 | 403 `No permission` |
| role-3 no-rights user, `/meta/types` | 403 | 403 `No permission` |
| `codetracker_view`-only user, list + `/meta/types` | 200 | 200, `canManage:false` |
| `codetracker_view`-only user, `POST` create | 403, nothing written | 403, 0 rows |

**Screen sweep** — legacy 3/3 now 404, modern 4/4 render, browser console clean.

## Removed — 8 files, 1167 lines

| File | Lines | Role |
|---|---|---|
| `lib/codetrackers/codeTrackerView.php` | 71 | legacy list controller + `checkRights` |
| `lib/codetrackers/codeTrackerEdit.php` | 185 | legacy create/edit/delete/`checkConnection` |
| `lib/codetrackers/codeTrackerCommands.class.php` | 274 | command helper, only used by the two above |
| `gui/templates/dashio/codetrackers/codeTrackerView.tpl` | 103 | legacy list template |
| `gui/templates/dashio/codetrackers/codeTrackerEdit.tpl` | 245 | legacy edit template |
| `gui/templates/tl-classic/codetrackers/codeTrackerView.tpl` | 95 | alternate theme copy |
| `gui/templates/tl-classic/codetrackers/codeTrackerEdit.tpl` | 148 | alternate theme copy |
| `lib/ajax/getcodetrackercfgtemplate.php` | 44 | cfg-template helper, superseded by `GET /cfg-template` |

The three now-empty directories (`lib/codetrackers/`,
`gui/templates/dashio/codetrackers/`, `gui/templates/tl-classic/codetrackers/`)
disappear with them — git does not track empty directories.

### Explicitly kept

The issue names these as shared and they are: `lib/functions/tlCodeTracker.class.php`
(755 lines — the model behind the modern BFF, the test-project link logic and the
execute screens) and `lib/codetrackerintegration/*` (4 interface files, used by
the BFF `test_github` / `test_connection` paths).

## Already pointing at the modern screen

No change was needed, confirmed rather than assumed:

- `lib/functions/common.php:1974` — `$actions->codeTrackerView` already resolves to
  `/gui/templates/codetracker/codetrackerView.html?{$ctx}`
- `gui/templates/dashio/aside.tpl:85` — consumes `$gui->uri->codeTrackerView`
- `api/aside/index.php:326-327` — same, for the BFF-driven sidebar

## One live link retargeted

`gui/templates/tl-classic/mainPageLeft.tpl:86` still pointed at the retired
controller. It is dead code — `lib/functions/tlsmarty.inc.php:101` hardcodes
`template_dir` to `gui/templates/dashio/` — but the same line was retargeted for
cfields and issue trackers in #957/#966, so leaving it would have been the odd one
out. Retargeted to the modern path, extending the existing comment.

## Dangling provenance references

The legacy controllers and templates carried the provenance comments of the
modern port — the single most valuable thing in them, since they record *why*
each modern behaviour exists. But they cite **line numbers in files that no
longer exist**, so a reader following `codeTrackerEdit.tpl:163-179` lands nowhere.
Same defect class as the `36b7b6b51` hash already found stale in this document.

A sweep found **54** such references across 6 files — 20 created by this deletion,
plus 21 left behind by #957 and #966:

| File | Count |
|---|---|
| `gui/templates/codetracker/codetrackerView.html` | 18 |
| `api/codetracker/index.php` | 17 |
| `api/issuetracker/index.php` | 12 |
| `gui/templates/issuetracker/issuetrackerView.html` | 5 |
| `api/cfields/index.php` | 1 |
| `lib/functions/tlIssueTracker.class.php` | 1 |

Each was rewritten from `NAME.ext:144-172` to `NAME.ext (removed in #976)`
(marking #957 / #966 where applicable), keeping the surrounding sentence intact
and annotating only the first mention per line. The information content is
preserved — what was ported, and which issue — only the unresolvable coordinates
are gone. Seven further references carried a query string or an orphaned line
range instead of `name:lines` and were fixed by hand.

The rewrite was deliberately conservative: it only touches a reference whose
target basename **no longer exists anywhere in the tree**. That is why
`gui/templates/tl-classic/cfields/cfieldsView.tpl` and
`gui/templates/dashio/cfields/cfieldsEdit.tpl` were left alone — the tl-classic
cfields templates were never deleted (only their dashio twins were), so those
references still resolve.

## Reported, not removed

Same policy as #966 — an orphan is not automatically dead, and deleting config
on a hunch is how cross-locale breakage happens.

| Orphan | Why kept |
|---|---|
| `config.inc.php:691-694` — `$tlCfg->gui->codeTrackerView->pagination` | No reader anywhere. Same shape as the `issueTrackerView` block reported at #966; a future DataTables wiring could legitimately want it. |
| `cfg/userRightMatrix.php:76` — `"$cf_admin_url/cfieldsView.php" => array("cfield_view",)` | Maps a **deleted** controller to a right. The whole file is consumed by nothing, so the entry is inert, but the file is a rights matrix — worth a deliberate decision, not a side effect of a cleanup PR. |
| `gui/templates/conf/input_dimensions.conf` — `[codeTrackerView]` (:339), `[codeTrackerEdit]` (:318) | No PHP reference to the file at all. Same as the `[issueTracker*]` sections reported at #966. |
| `gui/templates/tl-classic/cfields/cfieldsEdit.tpl:27,480` and `cfieldsView.tpl:9` — `{$viewAction="lib/cfields/cfieldsView.php"}` | Live-looking Smarty pointing at a deleted controller, but in dead theme code. Belongs to #957's cluster, not this issue's. |

## Files touched

```
 gui/templates/tl-classic/mainPageLeft.tpl            |   7 +-
 api/codetracker/index.php                            |  25 +-
 gui/templates/codetracker/codetrackerView.html       |  22 +-
 api/issuetracker/index.php                           |  18 +-
 gui/templates/issuetracker/issuetrackerView.html      |   7 +-
 lib/functions/tlIssueTracker.class.php               |   4 +-
 api/cfields/index.php                                |   2 +-
 docs/SCREEN-COMPARE-STATUS.md                        |   2 +-
 CHANGELOG                                            |  n +
 + 8 deleted                                          | 1167 ----
```

## Verification

- `php -l` clean on all four modified PHP files.
- BFF harness 20/20 after deletion (identical to the pre-deletion baseline).
- Rights 4/4 with temporary fixtures, removed afterwards; `codetrackers`,
  `users`, `roles`, `role_rights` back to their pre-test state.
- Legacy `codeTrackerView.php`, `codeTrackerEdit.php`, `getcodetrackercfgtemplate.php`
  → 404. Modern `codetrackerView.html`, `issuetrackerView.html`, `cfieldsView.html`,
  `mainPage.html` → render. Browser console clean.
- Zero surviving live references to any of the 8 removed files.
