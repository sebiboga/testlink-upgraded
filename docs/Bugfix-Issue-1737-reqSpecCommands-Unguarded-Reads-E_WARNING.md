# Issue #1737 — `reqSpecCommands::deleteFile()` / `bulkReqMon()` each wrote one E_WARNING row into the Event Viewer per request (2 unguarded reads)

**Issue:** [#1737](https://github.com/sebiboga/testlink-upgraded/issues/1737)
**Commits:** `263b56388` (fix) + `93b38e488` (regression suite)
**Branch:** `fix/issue-1737` (pushed; the CI workflow lands it)
**Status:** VERIFIED-FIXED — regression suite **4/4 PASS** (Suite "Regression — Issue #1737")
**Scope of this document:** the two Event-Viewer noise paths of the legacy Requirement
Specification editor. Found while testing [#1736](https://github.com/sebiboga/testlink-upgraded/issues/1736).

## Symptom

Two methods of `lib/requirements/reqSpecCommands.class.php`, reached through the legacy editor
`lib/requirements/reqSpecEdit.php`, each wrote **one `log_level=2` (`E_WARNING`) row into the
Event Viewer per request**:

```
E_WARNING Undefined property: stdClass::$uploadOp
    - lib/requirements/reqSpecCommands.class.php - Line 844   (every doAction=deleteFile)
E_WARNING foreach() argument must be of type array|object, null given
    - lib/requirements/reqSpecCommands.class.php - Line 877   (every doAction=bulkReqMon on a spec with no requirements yet)
```

The HTTP responses themselves are normal (`deleteFile` 302 to `reqSpecView.php`, `bulkReqMon`
200), so from the outside the requests are indistinguishable from the intended path. The defect
exists only in the log — same "Event-Viewer-only" class as #1736, #1731, #1654. Measured on the
freshly imported DB: `MAX(events.id)=2` before, and after the two requests rows `id=3`
(`:877`) and `id=4` (`:844`) appeared.

## The approach, and why this one

### Root cause

**`:844`** — `initGuiObjForAttachmentOperations()` reads `$argsObj->uploadOp` unconditionally, but
only `fileUpload()` ever creates that property:

```php
814  function fileUpload(&$argsObj,$request) {
816    $argsObj->uploadOp = fileUploadManagement(...);   // ONLY writer
819    return $this->initGuiObjForAttachmentOperations($argsObj);
825  function deleteFile(&$argsObj) {
827    deleteAttachment($this->db,$argsObj->file_id);
828    return $this->initGuiObjForAttachmentOperations($argsObj);  // never sets uploadOp
835  private function initGuiObjForAttachmentOperations($argsObj) {
844    $guiObj->uploadOp = $argsObj->uploadOp;  // ← E_WARNING
```

`$argsObj` is the `args` bean from `reqSpecEdit.php:init_args()` (`:271-290`); its `iParams` never
declares `uploadOp`. `:819` and `:828` are the only two callers of the helper.

**`:877`** — `bulkReqMon()` iterates a possibly-`null` collection:

```php
858    $obj = $this->initGuiBean();          // initGuiBean() (:65) does NOT set ->items
865    if( $my['options']['get_items'] ) {
869      $obj->items = $this->reqSpecMgr->get_requirements(...);  // null when 0 reqs
877    foreach($obj->items as $xdx => &$itx) { ... }   // ← E_WARNING
```

`get_requirements()` returns nothing for a spec with no requirements, so `$obj->items` stays
`null` — the common "just created the spec" case.

### Reachability

Both verbs are whitelisted by `reqSpecEdit.php` (#1736 dispatch) and gated only by
`checkRights()` (`mgt_view_req` **and** `mgt_modify_req`). `deleteFile` is the attachment-delete
URL built at `requirement_spec_mgr.class.php:2601`; `bulkReqMon` is the bulk-monitor button at
`gui/templates/dashio/requirements/reqSpecView.tpl:58`. So any user who can edit requirements
writes one row per click — noise exactly where a real Error would need to be spotted.

### The fix

Two one-line, behaviour-neutral changes in `lib/requirements/reqSpecCommands.class.php`:

1. `deleteFile()` initialises `$argsObj->uploadOp = null;` before calling the helper. A delete has
   no upload status, and `renderGui()` (`reqSpecEdit.php:460`) already treats `null` as "nothing to
   report", so the response is byte-for-byte what it was.
2. `bulkReqMon()` normalises the collection once: `$obj->items = (array)($obj->items ?? array());`
   It covers both `null` (0 requirements) and undefined (`get_items=false`), and gives
   `reqBulkMon.tpl`'s `foreach`/`count` a real array.

### Alternatives rejected

- **Drop the `$guiObj->uploadOp = $argsObj->uploadOp;` assignment from
  `initGuiObjForAttachmentOperations()`** (the ticket's other suggestion). Rejected: with the
  assignment gone, `fileUpload` would leave `$guiObj->uploadOp` **unset**, and
  `reqSpecEdit.php:460`'s `null != $guiObj->uploadOp` would raise a *new* undefined-property
  warning on the upload path. Initialising in `deleteFile()` is the minimal correct fix.
- **Guard only the `foreach` with `(array)`.** Rejected in favour of normalising `$obj->items`
  itself, so the `null` never reaches the downstream template either.

Neither change alters the response for a well-formed request, and no user-facing string changed,
so **no i18n keys were added**.

## Verification

Suite "Regression — Issue #1737", `tmp/TLU_Test_Cases.md` — **4/4 PASS**. Fixture
`tmp/fixtures_1737.php`; watermark `MAX(events.id)` before each request:

| Case | Result |
|---|---|
| `bulkReqMon` on a spec with 0 requirements | 200, 10217 B, **0** new events (was 1 × `:877`) |
| `deleteFile&file_id=999999` on a throwaway spec | 302, 0 B, **0** new events (was 1 × `:844`) |
| `bulkReqMon` on a spec with 2 requirements (no regression) | 200, 11303 B, 2 `itemSet` rows, `startMon` enabled, 0 events |
| `fileUpload` (no regression) | 302 → `reqSpecView.php…&uploadOPStatusCode=0`, 0 events |
| `create` / `edit` / `reorder` (no regression) | 200, 0 new events |
| `php -l` | no syntax errors |

## Blast radius

| Measure | Value |
|---|---|
| Affected members | `deleteFile` + `bulkReqMon`, 2 of the 18 GUI actions |
| Callers of `initGuiObjForAttachmentOperations()` | `reqSpecCommands.class.php:819` (fileUpload), `:828` (deleteFile) |
| `deleteFile` reachable from | `requirement_spec_mgr.class.php:2601` |
| `bulkReqMon` reachable from | `gui/templates/dashio/requirements/reqSpecView.tpl:58` |
| Rows per affected request | 1 × `log_level=2` |
