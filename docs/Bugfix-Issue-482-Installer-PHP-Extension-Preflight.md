# Issue 482 — Installer pre-flight check missed mbstring/openssl/pdo_mysql and never blocked on a missing required PHP extension

**Issue:** [#482](https://github.com/sebiboga/testlink-upgraded/issues/482)
**Branch:** `fix/issue-482`
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

On any fresh install, the installer's environment check page
(`install/index.php` → *New installation* → *Check*, served by
`install/installCheck.php`) probed only 7 extensions: pgsql,
mysqli/mysqlnd, mssql/sqlsrv, gd, ldap, json, curl. Half of the modern
required set — **mbstring**, **openssl**, **pdo_mysql** — was never
mentioned on any host. A machine missing them got
"Your system is prepared for TestLink configuration (no fatal problem
found)." and sailed straight into DB setup, only to die later with e.g.
`Call to undefined function mb_substr()` — exactly the cryptic failure
mode reported in #482. Additionally, even for the extensions that WERE
probed, a missing one produced only cosmetic warning text: the error
counter was never incremented, so the installer could never block.

## Root cause chain

1. `install/installCheck.php:40-42` runs `reportCheckingWeb($errors)`.
2. `reportCheckingWeb()` (`lib/functions/configCheck.php`) delegates to
   `checkPhpExtensions()`.
3. `checkPhpExtensions()` carried a hardcoded `$checks` array frozen in
   the 1.9.x era: legacy DB drivers (pgsql/mssql) were listed while
   mbstring/openssl/pdo_mysql were absent.
4. The same function never incremented `$errCounter` — `installCheck.php`
   saw `$errors == 0` unconditionally and always offered *Continue*.
5. `README.md` "System Requirements" documented zero PHP extensions.

Impact proof for the worst gap: 215+ hard `mb_*()` call sites exist
outside third_party (63× `mb_substr`, 54× `mb_strlen`,
41× `mb_convert_encoding`, …). Without mbstring the app cannot survive
its first request.

## Fix approach (the HOW)

`checkPhpExtensions()` was reworked around an explicit two-tier
classification instead of a flat list:

| Tier | Extensions | Behavior when missing |
|------|------------|----------------------|
| REQUIRED | mysqli/mysqlnd (version-dependent probe kept), mbstring, openssl | red `FAILED - REQUIRED EXTENSION MISSING!` row + Debian/Ubuntu package hint + `$errCounter++` → installer blocks |
| RECOMMENDED / OPTIONAL | gd, curl, ldap, pdo_mysql, pgsql, mssql/sqlsrv, json | amber warning row with package hint, non-blocking (unchanged severity) |

Why this split: mbstring is a hard runtime dependency (see call-site
counts), mysqli is the DB driver, openssl backs TLS SMTP via bundled
PHPMailer — without any of these TestLink cannot run at all, so blocking
is correct. curl/gd/ldap keep their legacy semantics because TestLink
degrades gracefully without them (feature disabled / internal auth
fallback). pdo_mysql has zero direct usage in core or api today but is
part of the issue's required-from-php.ini list, so it is now at least
reported (classified optional, honest feedback text). json became
bundled-and-always-on in PHP 8.0, so its row was demoted to informational.

Alternatives rejected: (a) blocking on ALL seven issue-listed extensions
— rejected because it would regress working installs that legitimately
run without ldap/curl; (b) a third standalone validator script —
rejected as duplicate surface; the extended pre-flight page plus
`install/util/sysinfo.php` already cover suggestion #3 of the issue.
Blast radius: `checkPhpExtensions()` has exactly one call path
(`installCheck.php` → `reportCheckingWeb`); runtime gd/ldap checks in
`checkForExtensions()`/`checkForLDAPExtension()` are untouched.

## Files changed

- `lib/functions/configCheck.php` — two-tier `checkPhpExtensions()`, blocking REQUIRED tier, package hints
- `README.md` — new "PHP Extensions" section under System Requirements (table + `sudo apt install php-mysql php-mbstring php-openssl php-gd php-ldap php-curl php-xml php-zip`)
- `tmp/repro_482.php` — verification harness (extracts the real function, swaps only the `extension_loaded()` probe)

## Verification evidence

- Healthy host, live page: HTTP 200; all 10 rows render; the three
  `[REQUIRED]` rows show OK; "Your system is prepared..." + Continue.



- Missing-extension simulation (`php tmp/repro_482.php`): 15/15 PASS —
  mbstring missing ⇒ REQUIRED markup + apt hint + errCounter=1;
  openssl missing ⇒ errCounter=1; curl missing ⇒ warning only,
  errCounter=0; mysqlnd missing (PHP ≥ 8.2) ⇒ errCounter=1.
- Regression suite 482.R1–R8 in `tmp/TLU_Test_Cases.md`: 8/8 PASS.
- Event Viewer delta across the whole matrix: 0 new Error/Warning events
  (`events` table empty before and after).

## RESUME

```
curl -s "http://localhost:8082/install/installCheck.php?type=new"   # R1 live check
php tmp/repro_482.php                                               # R2-R6 harness
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2,4,8)"     # R7 event delta
```
