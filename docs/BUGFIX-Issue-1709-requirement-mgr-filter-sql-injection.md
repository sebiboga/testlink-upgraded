# Bugfix: requirement_mgr::get_by_id() filter key SQL injection (Issue #1709)

**Issue**: requirement_mgr::get_by_id() interpolates filter array KEY raw into SQL - unvalidated key becomes arbitrary WHERE fragment.

**Root cause**: In `lib/functions/requirement_mgr.class.php::get_by_id()`, the filter building loop interpolates `$field2filter` (the array key) directly into the SQL string without escaping or allow-listing. Values were quoted but keys were not.

**Fix**: Added allow-list validation and proper escaping for filter keys/values using ADODB qstr. Also sanitized filters in related helpers (`requirement_spec_mgr.class.php`, minor hardening in `testcase.class.php`).

**Files changed**
- lib/functions/requirement_mgr.class.php (get_by_id: filter allow-list + qstr)
- lib/functions/requirement_spec_mgr.class.php (get_requirements and link_status paths sanitized)
- lib/functions/testcase.class.php (hardened filter handling)

**Verification**: PHP syntax checks pass; regression test added to `tmp/TLU_Test_Cases.md` (Regression — Issue #1709). No behavioral change for valid filters; unknown/malicious filter keys are now ignored safely.

