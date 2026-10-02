## Issue #1782 — api/attachmentsdelete: every attachment delete answers 403 NO_RIGHT (wrong attAuthOwnerAllowed() args)

### Root cause
`api/attachmentsdelete/index.php` was calling `attAuthOwnerAllowed()` with the wrong signature: it passed the table name string as the 3rd argument, but the function expects an array context from `attAuthResolveContext()`. This caused the auth check to fail closed for every request.

### Fix
Replaced the call with `attAuthCheckOwner($db, $user, $realTable, $fkId, true)` (write=true for deletion). This resolves the context correctly and applies appropriate permission checks.

### Verification
- Admin session: `POST /api/attachmentsdelete/index.php?action=delete` with valid id/table/fk_id returns `200 {"status":"ok",...}`
- DB: the attachment row is removed as expected
- Minimal change only to the affected call

### Files changed
- api/attachmentsdelete/index.php
