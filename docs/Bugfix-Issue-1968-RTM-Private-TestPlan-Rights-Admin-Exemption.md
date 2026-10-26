# Bugfix — Issue #1968: RTM ignored `testplans.is_public`, and the plan-scope guard locked the admin out

**Issue:** [#1968](https://github.com/sebiboga/testlink-upgraded/issues/1968) — *RTM BFF ignores
testplans.is_public, letting project-level metrics users read private plans*
**Severity:** major — authorization bypass on private test plans (closed), plus an admin lockout
introduced by the first half of the fix
**Branch:** `fix/issue-1968` · **Files changed:** `lib/functions/tlUser.class.php`
(the RTM call-site change in `api/rtm/index.php` was already on the default branch in `feebbe684`)

---

## 1. Symptom

`api/rtm/index.php` authorised with `hasRight($db,'testplan_metrics',$tproject_id,$tplan_id)`
(4 arguments). Only the 5th argument (`$getAccess=true`) makes `tlUser::hasRight()` load
`testplans.is_public`; without it a user holding `testplan_metrics` through a **project-level** role
could read the Requirements Traceability Matrix of a **private** plan in that project.

The fix commit `feebbe684` already did two things at the call site:

1. pass `true` as the 5th argument, and
2. move the `tproject_id` mismatch assertion **after** the rights check, so an unauthorised caller
   cannot tell "cross-project plan" from "missing right".

Measured after that commit: the project-level-only user was denied (leak closed), but **`admin` was
also denied 403** on the private plan — the issue's own acceptance criterion ("admin still 200")
failed. The same lockout existed on every metrics BFF that passes the 5th argument.

## 2. Reproduction

Fresh DB; fixture created by SQL:

| entity | value |
|---|---|
| project 1 `PrivProj` | `testprojects.is_public = 1` |
| plan 2 `Private Plan` | `testplans.is_public = 0` |
| plan 3 `Public Plan` | `testplans.is_public = 1` |
| `metricsuser` (id 2) | global role 5 `guest`; **project role 6 `senior tester` only**; no `user_testplan_roles` row |
| `admin` (id 1) | role 8; no project/plan role row |

```bash
curl -s -c cj_user -H 'Origin: http://localhost:8082' -H 'Content-Type: application/json' \
  -d '{"login":"metricsuser","password":"metrics123"}' http://localhost:8082/api/auth/login
curl -s -c cj_admin -H 'Origin: http://localhost:8082' -H 'Content-Type: application/json' \
  -d '{"login":"admin","password":"admin"}' http://localhost:8082/api/auth/login
curl -s -b cj_user  'http://localhost:8082/api/rtm/index.php?action=context&tplan_id=2'
curl -s -b cj_admin 'http://localhost:8082/api/rtm/index.php?action=context&tplan_id=2'
```

Measured on `feebbe684` (before this fix):

| caller | private plan 2 | public plan 3 |
|---|---|---|
| `metricsuser` | 403 `no_right` (leak closed) | 200 |
| `admin` | **403 `no_right` (regression)** | 200 |

`api/exechistoryreport/index.php?tplan_id=2` showed the same admin 403, proving the defect was in the
shared primitive, not in the RTM endpoint.

## 3. Root cause chain

```
api/rtm/index.php:112-124              hasRight(..., $tplan_id, true) before the tproject assertion   (correct)
lib/functions/tlUser.class.php:892-897 $getAccess => $accessPublic['tplan'] = testplan::getPublicAttr()
lib/functions/testplan.class.php:7377  private plan => 0
lib/functions/tlUser.class.php:966-996 no user_testplan_roles row =>
                                       if (isset($accessPublic['tplan']) && $accessPublic['tplan'] == 0)
                                         return false;          <-- NO admin exemption
lib/functions/tlUser.class.php:938-945 sibling TEST PROJECT branch: ... && globalRoleID != TL_ROLES_ADMIN
lib/functions/roles.inc.php:407-416    legacy private-plan rule: ... && globalRoleID != TL_ROLES_ADMIN
lib/functions/tlUser.class.php:1143-1157 getAccessibleTestPlans(): same admin exemption
```

`admin` owns no `user_testplan_roles` row (it does not need one), so the plan-scope denial is
unconditional for it. Legacy explicitly exempted `TL_ROLES_ADMIN`; the primitive forgot that
exemption when the 5-argument form became reachable.

## 4. The fix, and why this approach

One condition in the primitive:

```php
if(isset($accessPublic['tplan']) && $accessPublic['tplan'] == 0
   && $this->globalRoleID != TL_ROLES_ADMIN) {
  return false;
}
```

* Keeps the #1968 leak closed: a non-admin project-level role is still refused on a private plan.
* Restores legacy admin parity and the already-shipped project-branch behaviour.
* Fixes every 5-argument caller in one place: 18 `api/*` metrics call sites, of which 17 pass a plan id (strategyreport passes `null`), plus the plan-scoped callers such as `pageAccessCheck()` and `exec_delete`.

Alternatives rejected: patching only `api/rtm` (leaves the other metrics BFFs broken for admin);
duplicating the rule per endpoint (unverifiable, more surface).

## 5. Verification (measured)

`php -l lib/functions/tlUser.class.php` → clean.

| request | before | after |
|---|---|---|
| `metricsuser` → RTM private plan 2 | 403 | **403 `no_right`** |
| `admin` → RTM private plan 2 | **403** | **200** context |
| `metricsuser` → RTM public plan 3 | 200 | 200 |
| `admin` → RTM public plan 3 | 200 | 200 |
| `admin` → `api/exechistoryreport?tplan_id=2` | 403 | **200** |
| `metricsuser` → `api/exechistoryreport?tplan_id=2` | 403 | 403 |
| `admin` → RTM private plan with `tproject_id=999` | — | **404 `project_mismatch`** (rights first) |
| `metricsuser` → same mismatch | — | **403 `no_right`** (no existence oracle) |
| 3-argument callers | — | unchanged; no `Undefined array key "tplan"` warnings |

Event Viewer: test-probe ERROR rows were deleted; `events` ends with the 2 audit-login INFO rows,
0 new ERROR/WARNING attributable to the change.

Regression suite: `## Regression — Issue #1968` in `tmp/TLU_Test_Cases.md`, parts A–G **PASS**.
Suite-file gate: `TLU_REQUIRE_SUITE="Issue #1968" bash ai/verify_test_suites.sh` → **34 PASS / 0 FAIL**.

## 6. Files

- `lib/functions/tlUser.class.php` — admin exemption in the private-plan branch (the fix).
- `api/rtm/index.php` — already fixed on the default branch in `feebbe684`; unchanged here.
- `CHANGELOG` — 2.0.1 bugfix entry.
- `tmp/TLU_Test_Cases.md` — regression suite (git-ignored, appended, gate passed).
- `docs/Bugfix-Issue-1968-RTM-Private-TestPlan-Rights-Admin-Exemption.md` + wiki mirror.
