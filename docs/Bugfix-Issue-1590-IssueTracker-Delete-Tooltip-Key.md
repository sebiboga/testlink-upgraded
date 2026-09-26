# Bug fix — Issue #1590: Issue Tracker delete icon used an undefined label key

## Symptom

The legacy Dashio **Issue Tracker** list rendered its per-row delete icon with
an **empty tooltip**, and every page load wrote one `E_WARNING` row per
unlinked tracker into the `events` table (the Event Viewer source).

Two distinct symptoms, one cause:

- **Visible:** the `fa-minus-circle` delete icon appeared with `title=""` — no
  text on hover.
- **Invisible:** `E_WARNING Undefined array key "testproject_alt_delete"` landed
  in `events`, once for every row that was able to display the icon.

Neither breaks the workflow: the icon renders, the `delete_confirmation()`
handler still fires, and the list still works. The defect is cosmetic plus
Event Viewer noise, and only affects users holding `issuetracker_management`
(right 31).

Entry point:
`http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=1`.

## Environment and fixtures

- TestLink 2.0.1, PHP 8.3.35, MariaDB on the local CI instance, commit
  `922e86e53` (branch `fix/issue-1590`).
- The database is freshly imported on every run, so **`testprojects` and
  `issuetrackers` are both empty** and must be recreated:

```sql
INSERT INTO testprojects (id,notes,color,active,option_reqs,option_priority,option_automation,options,prefix)
VALUES (1,'Repro project for #1590','#9BD9BD',1,0,0,0,'','REPRO');
INSERT INTO issuetrackers (name,type,cfg) VALUES ('ReproBugTracker1590',1,'');
INSERT INTO issuetrackers (name,type,cfg) VALUES ('ReproBugTracker1590-B',1,'');
INSERT INTO users (id,login,password,role_id,email,first,last,locale,default_testproject_id,active)
VALUES (90,'viewer1590',MD5('viewer1590'),1,'v@x.y','View','Er','en_GB',1,1);
```

- `admin`/`admin` — holds right 31 `issuetracker_management`.
- `viewer1590` — used for the non-manager path.

> ⚠️ **`issuetrackers.type` must be a real key of `tlIssueTracker::$systems`**
> (`lib/functions/tlIssueTracker.class.php:31`). `type=1` (bugzilla/xmlrpc)
> works. A fixture with `type=0` makes the screen return **HTTP 500** and floods
> `events` with five unrelated warnings from `tlIssueTracker.class.php:170-171`
> and `:604-605`. That is a **separate pre-existing defect**, not #1590 — see
> *Known remaining issues* below.

## Reproduction before the fix

```bash
# login (the login POST uses tl_login / tl_password, not login / password)
curl -s -c cj2.txt http://localhost:8082/login.php -o /dev/null
curl -s -b cj2.txt -c cj2.txt -X POST -d "tl_login=admin&tl_password=admin" \
     http://localhost:8082/login.php
# render the screen
curl -s -b cj2.txt "http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=1" -o itv.html
# read the tooltip and the Event Viewer
grep -o 'title="[^"]*"' itv.html
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "SELECT id,log_level,description FROM events ORDER BY id DESC LIMIT 3;"
```

Measured pre-fix result:

```
view=200 size=12306

$ grep -o 'title="[^"]*"' itv.html
title="Check connection"      <- the BTS env-check cell, correct
title=""                      <- the delete icon: EMPTY
```

```
+----+----------+---------------------------------------------------------------+
| id | log_level | description                                                   |
+----+----------+---------------------------------------------------------------+
| 24 |         2 | E_WARNING Undefined array key "testproject_alt_delete"         |
|    |          |   - in gui/templates_c/2563b481..._0.file.issueTrackerView.tpl.php
|    |          |   - Line 134                                                   |
+----+----------+---------------------------------------------------------------+
```

**Scaling proof** — exactly one warning per row that can display the icon:

| unlinked trackers | `grep -c 'fa-minus-circle'` | cumulative `events` rows mentioning the key |
|---|---|---|
| 1 | 1 | 1 |
| 2 | 2 | **3** (1 previous + 2 new) |

## Root cause chain

1. `gui/templates/dashio/issuetrackers/issueTrackerView.tpl:12-16` — the
   template's single `{lang_get var='labels' ...}` call populates `$labels`
   with `th_issuetracker, th_issuetracker_type, th_delete, th_description,
   menu_assign_kw_to_tc, title_issuetracker_mgmt, btn_create, alt_delete,
   th_issuetracker_env, check_bts_connection, bts_check_ok, bts_check_ko`.
   **`alt_delete` is loaded. `testproject_alt_delete` is not.**

2. `gui/templates/dashio/issuetrackers/issueTrackerView.tpl:81` — inside
   `{if $gui->canManage != ""}` / `{if $item_def.link_count == 0}` the delete
   icon reads a key that was never loaded:

   ```
   <i class="fas fa-minus-circle" title="{$labels.testproject_alt_delete}"
   ```

3. Smarty compiles that reference to a **raw array read with no guard** — no
   `isset()`, no `|default` — confirmed in the generated cache
   `gui/templates_c/2563b481caa80ddabd651341d294cdd1f0429b57_0.file.issueTrackerView.tpl.php:134`:

   ```php
   <?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_alt_delete'];?>
   ```

   PHP 8 therefore emits `E_WARNING: Undefined array key` **and** echoes the
   empty string. That single line produces *both* halves of the symptom.

4. TestLink's error handler persists the warning to the `events` table, which is
   why the defect shows up in the Event Viewer as well as on screen.

**Why it breaks now:** it does not — it is **pre-existing**, not a regression.
`issueTrackerView.tpl` has always used `{$labels.testproject_alt_delete}` while
only ever loading `alt_delete`, and the warning reproduces on the untouched
branch. The sibling `codeTrackerView.tpl` was already corrected (by #1580 for
the key, #1582 for the column contract); this Issue Tracker twin was simply
missed by that sweep. The #1591 CHANGELOG entry explicitly left it open as
"the still-undefined `testproject_alt_delete` tooltip key remains #1590".

### The key is not missing — the *reference* is wrong

An important distinction that rules out the obvious wrong fix:
`testproject_alt_delete` **is** a real, fully translated string in every locale
bundle. It means *"Delete the Test project and all related data."*
(`locale/en_US/strings.txt:1635`).

```
$ grep -rl testproject_alt_delete --include=*.tpl gui/templates/
gui/templates/dashio/issuetrackers/issueTrackerView.tpl     <- the bug
gui/templates/dashio/project/projectView.tpl                <- correct user
gui/templates/tl-classic/project/projectView.tpl            <- correct user
```

`projectView.tpl:31` loads the key and `projectView.tpl:153` uses it — both
consistent, both left untouched. So **adding** the key, or renaming it, would
put the semantically wrong string ("Delete the Test project and all related
data.") on a per-issue-tracker delete button.

## The fix

One line, in
`gui/templates/dashio/issuetrackers/issueTrackerView.tpl:81`:

```diff
-                <i class="fas fa-minus-circle" title="{$labels.testproject_alt_delete}"
+                <i class="fas fa-minus-circle" title="{$labels.alt_delete}"
```

**Why this method.** `alt_delete` is already loaded by this very template at
line 15, resolves to `"delete"` in every locale bundle
(`locale/en_US/strings.txt:77`, `locale/en_GB/strings.txt:71`), and is the
identical key the sibling `codeTrackerView.tpl:78` uses for the same icon on
the sibling screen (fixed by #1580). One line, zero new translation work, zero
risk to any other screen.

**Alternatives rejected:**

| Alternative | Why rejected |
|---|---|
| Add `testproject_alt_delete` to the `{lang_get}` list | Wrong semantics — the tooltip would read "Delete the Test project and all related data." on a tracker delete button. |
| Add `\|default` to silence the warning | Hides the symptom; the tooltip stays empty. |
| Rename the i18n key globally | Breaks the two correct `projectView.tpl` call sites, which legitimately want that string. |
| `php -l` the template | Useless — `.tpl` files are Smarty, not PHP; the real gate is the regenerated `templates_c` PHP. |

## Verification

- **Primary symptom — the warning is gone.** Baseline `testproject_alt_delete`
  rows cleared, screen re-rendered, re-queried: **zero rows**.
- **Primary symptom — the tooltip is restored.** With two unlinked trackers:
  `title="Check connection"`, `title="delete"`, `title="Check connection"`,
  `title="delete"` (was `title=""`).
- **Live browser DOM** (not curl):
  `[{"title":"delete","hasOnclick":true},{"title":"delete","hasOnclick":true}]`
  — the `delete_confirmation(...)` handler is untouched on both icons.
- **Icon count preserved:** `grep -c 'fa-minus-circle'` → `2`, so nothing was
  hidden to silence the warning.

Full regression matrix (9/9 PASS, recorded in `tmp/TLU_Test_Cases.md`):

| # | Case | Result | Verdict |
|---|---|---|---|
| 1 | admin, 1 unlinked tracker | HTTP 200, 1 icon, `title="delete"`, 0 new Warning rows | PASS |
| 2 | admin, 2 unlinked trackers | HTTP 200, 2 icons, both `title="delete"`, 0 new rows | PASS |
| 3 | tracker **linked** (`testproject_issuetracker`) | icons 2 → **1**; the linked row correctly loses the icon; 0 new rows | PASS |
| 4 | non-manager (`canManage` empty) | page renders fully (11556 bytes), **0** delete icons — the guard is intact; 0 new rows | PASS |
| 5 | sibling Code Tracker view | HTTP 200, unchanged, 0 new rows | PASS |
| 6 | `projectView.tpl` — the legitimate owner of `testproject_alt_delete` | static: commit touches 1 file; key loaded at `:31`, used at `:153` — untouched | PASS (static) |
| 7 | locales `de_DE` / `fr_FR` / `es_ES` / `ro_RO` / `it_IT` | `de_DE` → `title="löschen"`; `fr_FR`/`es_ES` localized; `ro_RO`/`it_IT` (no key) → graceful en_GB fallback `"delete"`; 0 new Warning rows | PASS |
| 8 | live browser DOM | both icons `title="delete"`, `hasOnclick: true` | PASS |
| 9 | Event Viewer after the whole matrix | **0** new `log_level IN (1,2,3)` rows from this screen | PASS |

### Gotchas found while reproducing (each cost time)

- **`issuetrackers.type` must be a valid system key.** `type=0` → HTTP 500 and
  five unrelated warnings from `tlIssueTracker.class.php:170-171,604-605`.
- **Locale tests need a NEW SESSION.** `$_SESSION['locale']` is cached, so
  updating `users.locale` alone keeps rendering English — that produced a false
  negative on the first attempt at case 7. Use a fresh cookie jar per locale.
- **Case 4 is untestable with the stock role matrix.** `role_rights` grants
  `issuetracker_view` (32) and `issuetracker_management` (31) **only to role 8
  (admin)**; a tester/leader account is redirected before the template is
  reached. I inserted `(role_id=7, right_id=32)` temporarily and removed it
  afterwards.
- **Session expiry is easy to misread.** An expired session returns a 204-byte
  `login.php?note=expired` stub that looks like an empty result set.
- The `gui/templates_c/` Smarty cache recompiles automatically on template
  mtime change; no manual purge is needed before re-testing.

## Known remaining issues (found while testing, **not** fixed here — out of scope)

Per `ai/FIX-ISSUE.md` §3 these are separate defects and are **not** touched by
this one-line fix. They are recorded here so the next run inherits the
evidence.

1. **`issuetrackers.type` outside `tlIssueTracker::$systems` makes the screen
   return HTTP 500** and logs 5 warnings per render
   (`tlIssueTracker.class.php:170-171,604-605`: `Undefined array key 0` and
   `Trying to access array offset on null`). Reachable whenever a tracker row
   carries a type that is absent from, or disabled in, the `$systems` map.
   Related to the already-filed #1597 (unknown code-tracker type → 500).
2. **7 of 20 locale bundles have no `alt_delete` key** — `es_AR`, `fi_FI`,
   `id_ID`, `it_IT`, `ko_KR`, `ro_RO`, `ru_RU`. They fall back to en_GB and log
   a `log_level 32` LOCALIZATION *info* event, not a Warning. This is
   pre-existing and shared with the already-fixed `codeTrackerView.tpl`; it is
   a translation-completeness gap, not a code defect.

## Files changed

- `gui/templates/dashio/issuetrackers/issueTrackerView.tpl` — **the fix**,
  1 line (`+1 / -1`).
- `docs/screenshots/issue-1590-issueTrackerView-delete-tooltip.png` — post-fix
  evidence.
- `docs/Bugfix-Issue-1590-IssueTracker-Delete-Tooltip-Key.md` — this mirror.
- `CHANGELOG` — one-line 2.0.1 summary.
- `tmp/TLU_Test_Cases.md` — regression suite (gitignored, local).
- `tmp/wiki-repo/Bugfix-Issue-1590-IssueTracker-Delete-Tooltip-Key.md` — the
  GitHub Wiki page with the screenshot.

## Result

Issue #1590 is fixed. The delete icon on the Issue Tracker list now carries the
localized **delete** tooltip for managers, the `E_WARNING "Undefined array key
testproject_alt_delete"` is gone from the Event Viewer, and the sibling Code
Tracker screen plus the two `projectView.tpl` files that legitimately use that
key are all untouched — the whole fix is a single line in one template. The
production and test commits were pushed on `fix/issue-1590` as `0fcf6b793` and
`d109806f5`; the documentation phase is tracked by the same branch and issue.
