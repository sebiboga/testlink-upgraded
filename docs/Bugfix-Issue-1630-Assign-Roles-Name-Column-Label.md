# Bugfix — Issue #1630: "Last Name" column header over a fused full-name cell in the role-assign grids

**Screens:** Test Management → User Management → **Assign Test Project Roles**
(`gui/templates/usermanagement/usersAssignProject.html`) and its twin
**Assign Test Plan Roles** (`gui/templates/usermanagement/usersAssignPlan.html`).

**Status:** fixed and verified (regression suite 14/14 PASS).
**Labels:** `bug`. **Refs:** #1630.

---

## 1. Symptom

The 3rd column of the user grid was headed **"Last Name"** while its cells
contained the user's **full name** — so the label contradicted the data on
every row and in every locale:

```
  #   Login        Last Name              Assigned Role          <-- BEFORE
  1   adesigner    Ann Designer           <inherited> tester
  2   admin        Testlink Administrator <inherited> admin

  #   Login        Name                   Assigned Role          <-- AFTER
  1   adesigner    Ann Designer           <inherited> tester
  2   admin        Testlink Administrator <inherited> admin
```

Only the *label* was wrong. The cell content was always correct.

## 2. Root cause

This is a **2.0.1 modernisation regression**, not legacy behaviour.

The legacy template `gui/templates/dashio/usermanagement/usersAssign.tpl:242`
(deleted in commit `ab387af72`) rendered a **single** cell under a **single**
generic localized header:

```smarty
{$user->login|escape} ({$user->firstName|escape} {$user->lastName|escape})
```

Because the name and the label were one and the same, no column header could
ever disagree with the data.

The 2.0.1 rewrite split that cell into two columns — `Login` + `Name` — but
borrowed the wrong existing i18n key for the fused one, because the bundles had
no "full name" key at all.

### The chain, hop by hop

| # | Hop | Location |
|---|-----|----------|
| 1 | BFF fuses first+last into one `name` field | `api/roles/index.php:785` (project grid), `:949` (plan grid) → `'name' => $u->getDisplayName()` |
| 2 | `getDisplayName()` expands the username format | `lib/functions/tlUser.class.php:525-533` |
| 3 | Format is `%first% %last%` | `config.inc.php:749` |
| 4 | JSON reaches the page as one fused string | `api/roles/index.php:803` |
| 5 | The page renders it into ONE grid column | `usersAssignProject.html:428`, `usersAssignPlan.html:377` |
| 6 | **← the defect** that column's `<th>` was bound to the *last-name-only* key | `usersAssignProject.html:86`, `usersAssignPlan.html:88` → `<th data-i18n="user.lastName">Name</th>` |
| 7 | …and that key means "Last Name" in every bundle | `gui/templates/i18n/en.json` → `"user.lastName": "Last Name"` |

Note the irony: the **hard-coded HTML fallback** inside those `<th>` elements was
already the correct word `Name`. The label only became wrong once `TLi18n`
resolved `user.lastName` at load time.

## 3. The fix

**Method chosen:** introduce a dedicated i18n key for the fused name and point
the two headers at it. This keeps the data path untouched (the BFF was always
correct) and keeps the key semantically honest for each language.

1. Added `"user.fullName"` to **all 10** locale bundles
   (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`), placed
   alphabetically between `user.firstName` and `user.generateApiKey` to preserve
   the strictly-sorted `user.*` run in each file.
2. Repointed `usersAssignProject.html:86` and `usersAssignPlan.html:88` to
   `data-i18n="user.fullName"`.

### Translations

| bundle | `user.fullName` | `user.lastName` | distinct? |
|---|---|---|---|
| en | Name | Last Name | yes |
| de | Name | Nachname | yes |
| es | Nombre | Apellido | yes |
| fr | **Nom complet** | Nom | yes |
| it | Nome | Cognome | yes |
| ja | 氏名 | 姓 | yes |
| pt | Nome | Apelido | yes |
| ro | **Nume complet** | Nume | yes |
| ru | Полное имя | Фамилия | yes |
| zh | 姓名 | 姓 | yes |

`fr` and `ro` are bolded on purpose. The first attempt used plain `Nom` / `Nume`,
which the code review caught as a **silent no-op**: in French and Romanian the
conventional surname label *is* "Nom" / "Nume" (paired with "Prénom" / "Prenume"),
so the bug would have remained unfixed — merely invisible — in those two
languages. The explicit `complet` ("full") forms make the fix real everywhere.

## 4. What was deliberately NOT changed

`gui/templates/usermanagement/usersView.html:106-107` (and its form labels at
`:146`/`:150`) keep `user.firstName` / `user.lastName`. That grid genuinely shows
**two separate columns** fed by genuinely split data
(`api/users/index.php:110-111`, `:176-177`), so its keys are correct. A blanket
re-pointing of the key, or "fixing" the `user.lastName` value in `en.json`,
would have broken it. Verified live after the change:

```
usersView.html (de): ["Login","Vorname","Nachname","E-Mail", …]
rows:               ["adesigner","Ann","Designer","ann@example.org", …]
```

The BFF (`api/roles/index.php`) and `tlUser.class.php` needed **no** change.

## 5. Alternatives rejected

* **Re-point the `<th>` at `user.login`** — wrong label ("Login" over a name column).
* **Split the fused `name` back into two columns** — would break legacy parity
  (legacy showed one `First Last` cell) and need a BFF change. Out of scope for a
  label defect.
* **Change the value of `user.lastName` in `en.json`** — would corrupt
  `usersView.html:106-107`, which legitimately has a Last Name column.
* **Hard-code the header as plain "Name" with no key** — would break
  internationalisation (rule 3) and regress all 9 non-English locales.

## 6. Verification

Regression suite: `tmp/TLU_Test_Cases.md`, section
"Regression — Issue #1630" — **14 cases, 14 PASS**, including:

* both role-assign grids, in `en` and after real locale switches (`de`, `ro`, `fr`);
* `de` chosen deliberately as the strongest single assertion, because
  `user.lastName` (`Nachname`) ≠ `user.fullName` (`Name`) there, so it actually
  distinguishes fix from pre-fix state;
* `usersView.html` asserted **unchanged**;
* the hard-coded i18n fallback;
* all 10 bundles served over HTTP with a real full-name word + `json.tool` valid;
* browser console 0 errors / 0 warnings;
* the `events` table — 1 row, `log_level=16` (INFO, `audit_login_succeeded`),
  **0 Error/Warning entries**.

### Screenshots

| Before | After |
|---|---|
| `docs/screenshots/issue-1630-before.png` — header "Last Name" | `docs/screenshots/issue-1630-after.png` — header "Name" |

## 7. Gotchas for the next agent

* The freshly imported DB has **0 test projects** and only the `admin` user, and
  `custom_fields` / `cfield_node_types` are empty, so the project combo returns
  `projects: []` and the grid has to be driven from the console
  (`loadUsers(1)`) to render rows. Unrelated to this bug.
* Project **names are not** in the `testprojects` table (it only holds `prefix`).
* Switching locale = `localStorage.setItem('tl_locale','<loc>')` + a full reload
  (`gui/templates/i18n/i18n.js:28`); the bundle is fetched once at load.
* `fr` and `ro` were the two locales that could not *visually* reveal this bug
  before the `complet` correction — use `de` or `en` for assertions here.
* `user.lastName` is **not** globally wrong. Only the two fused-name headers were
  mis-bound; `usersView.html` uses it correctly.

## 8. Commits

| commit | content |
|---|---|
| `336e9ec95` | the fix: `user.fullName` in 10 bundles + repoint the 2 `<th>`s |
| `3a905a03e` | regression suite (14 cases) + before/after screenshots |
| `e7b6ffca2` | code-review follow-ups: fr/ro explicit labels + alphabetical key placement |
| (this commit) | CHANGELOG + docs + wiki mirror |
