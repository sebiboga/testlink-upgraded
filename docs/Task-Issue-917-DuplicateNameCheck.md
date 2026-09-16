# Task 917 — Duplicate test case name check/warning in Test Specification editor

**Issue:** [#917](https://github.com/sebiboga/testlink-upgraded/issues/917)
**Status:** IMPLEMENTED (2026-09-16)

## The gap

Legacy Test Specification (`gui/templates/dashio/testcases/include/tcEditViewer.inc.tpl:36`) wires
`onkeyup="javascript:checkTCaseDuplicateName(Ext.get('testcase_id').getValue(),
Ext.get('testcase_name').getValue(), Ext.get('testsuite_id').getValue(),
'testcase_name_warning')"` with `<span id="testcase_name_warning" class="warning">`.
The JS function `gui/javascript/tcase_utils.js:42` sends a GET to
`lib/ajax/checkTCaseDuplicateName.php`, which calls `tree::nodeNameExists()`
(`lib/functions/tree.class.php:1347`): counts sibling testcase nodes in the same
suite with the same name (excluding self) and returns the localized message
`lang_get('name_already_exists')` ("Name:%s already exists"). Warning only --
save is never blocked.

The modern `testSpec.html` editor had no duplicate-name check: typing a
duplicate TC name in the create/edit form saved silently with no warning.

## Legacy behavior (port source)

- Input: testcase_id, testcase_name, testsuite_id, testproject_id.
- Rights: `mgt_view_tc` OR `mgt_modify_tc` on the owning test project.
- SQL: `SELECT count(0) FROM nodes_hierarchy WHERE node_type_id=<testcase>
  AND name=:name AND parent_id=:suite AND id<>:testcase_id` (exclude self on update).
- Output: `{status, duplicate, message}` -- message is the localized warning, empty when no duplicate.

## Modern implementation

**Backend `api/testcases/index.php`**
- `GET ?action=check_name&name=..&testcase_id=..&testsuite_id=..&testproject_id=..`
  (line ~1590, after the `get` action). Resolves owning project via
  `owningProjectOf()` or falls back to session. Gates on `mgt_view_tc` OR
  `mgt_modify_tc`. When `testsuite_id` is missing/zero (edit mode where the
  caller doesn't know the parent), derives it from `nodes_hierarchy` using the
  testcase node's `parent_id`. Excludes the testcase itself on edit.
  Returns `{status:"ok", duplicate:bool, message:string}` via
  `lang_get('name_already_exists')`.

**Frontend `gui/templates/testcases/testSpec.html`**
- `#tcNameInput` gets `onkeyup="checkDuplicateName()"` (line ~1109).
- New red inline `<div class="name-warning" id="tcNameWarning">` under the name
  field (CSS `.name-warning { color:#e6605e; font-size:12px }`).
- `checkDuplicateName()` function (debounced 300ms): reads name, determines
  testcase_id (`editMode.tcase_id` in update mode, else 0) and suite
  (`editMode.parent_id` in create mode, else 0 for BFF to derive), calls
  `apiGet('check_name', {...})`, renders the localized warning or clears it.
  Initial check fires on form render (create / edit / version-switch).
- Save is NOT blocked -- exactly like legacy (warning only).

**i18n (all 10 locale bundles)**
- `tspec.nameAlreadyExists` added: en "Name: %s already exists",
  de "Name: %s ist bereits vorhanden", es "Nombre: %s ya existe",
  fr "Nom : %s existe déjà", it "Nome: %s esiste già",
  ja "名前「%s」は既に存在します", pt "Nome: %s já existe",
  ro "Numele: %s există deja", ru "Имя %s уже существует",
  zh "名称「%s」已存在".

## Verified scenarios

1. **Create new TC** "Alpha" in an empty suite -- no warning (first instance).
2. **Create duplicate TC** "Alpha" when one already exists in the same suite --
   inline warning "Name:Alpha already exists" shown; save is allowed (legacy parity).
3. **Edit existing TC** "Alpha" keeping own name -- NO warning (self excluded).
4. **Rename TC** "Beta" to "Alpha" -- inline warning "Name:Alpha already exists" shown.
5. BFF returns `duplicate:false` with empty message when name is unique.
6. 500 from the BFF (`t()` undefined function) -- fixed before browser verification.
