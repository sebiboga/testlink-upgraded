# Issue 1790 — `suiteMove` BFF: the `move` path still split its 404s by message, keeping ids and node types enumerable

**Issue:** [#1790](https://github.com/sebiboga/testlink-upgraded/issues/1790)
**Branch:** `sebiboga` · **Fix commit:** see the repo log (single commit with `Fixes #1790`)
**Screen:** `gui/templates/testcases/suiteMove.html` · **BFF:** `api/suitemove/index.php`
**Status:** FIXED & VERIFIED (2026-10-02)
**Predecessors:** [#1759](https://github.com/sebiboga/testlink-upgraded/issues/1759) (the same
class, `init`/`reorder` container axis) · [#1779](https://github.com/sebiboga/testlink-upgraded/issues/1779)
(the unentitled-project guard) · [#1761](https://github.com/sebiboga/testlink-upgraded/issues/1761) /
[#1762](https://github.com/sebiboga/testlink-upgraded/issues/1762) (the twin defects on
`api/tcreorder` / `api/tcstepsreorder`, fixed by the same author, same lane)

## Symptom

`nodes_hierarchy` ids are **global** across all test projects. #1759 and #1779 closed the
`container_id` axis, but the `move` action kept answering **different bodies for a caller-supplied
`node_id` / `new_parent_id`** depending on the real reason:

| request (`sm1759a`, rights on project A only) | before | after |
|---|---|---|
| `node_id=<suite of project B>` | `404 Suite has no owning test project` | `404 Suite not found` |
| `node_id=999999` | `404 Suite not found` | `404 Suite not found` |
| `new_parent_id=<test case, own project>` | **`400` `Destination is not a test suite`** | `404 Destination not found` |
| `new_parent_id=<test case, foreign project>` | **`400` `Destination is not a test suite`** | `404 Destination not found` |
| `new_parent_id=<root of a foreign project>` | `404 Destination has no owning test project` | `404 Destination not found` |
| `new_parent_id=999999` | `404 Destination not found` | `404 Destination not found` |

The **wrong-type** branch was the worst of them on two counts: it named the **node type** of an
arbitrary id — even one inside a project the caller has no rights on — *and* split the status, so
the status alone told a test case apart from an id that exists nowhere.

Severity is lower than #1779: these refusals run **after** rights on the moved node's project are
proven, so the caller must already hold rights on *some* project. It is still an enumeration oracle
for the ids and node types of every test project of the installation.

## Root cause chain

1. `api/suitemove/index.php`, `case 'move'` — after #1779 the *rights* guard was opaque, but the
   pre-#1779 code had deliberately kept **distinct, informative wordings** on the move path, and
   #1779 hardened only the guard. Four branches were left:
   * orphan `node_id` and foreign-project `node_id` → `Suite has no owning test project`
   * `new_parent_id` of the wrong node type → `400 Destination is not a test suite`
   * `new_parent_id` with no owning project → `Destination has no owning test project`
2. `suitMoveRequireSuite()` (`api/suitemove/index.php:575`) carried **three** wordings of its own:
   `Node not found` / `Node is not a test suite` / `Node belongs to another test project`.

## The fix

* Every refusal on the **node axis** now answers `404 Suite not found`; every refusal on the
  **destination axis** answers `404 Destination not found`. The wrong-type branch changed status
  from `400 bad_request` to `404 not_found` — that status split was part of the oracle.
* All three wordings of `suitMoveRequireSuite()` collapsed onto the single absent-node answer.
* **The wrong-type branch of `suitMoveRequireSuite()` is live, not defence in depth.** `move`
  proves existence and ownership before calling it but **never the node type**, so `node_id` naming
  a project root or a test case of an *entitled* project reaches it. Pre-fix that branch answered
  `Suite is not a test suite`, so the **node axis leaked node types** exactly like the destination
  axis — a leak the issue itself did not list. It is pinned by rows N10–N15 of the new harness.
* While in the same block, `new_parent_id` supplied as an **array** (`new_parent_id[]=x`) was cast
  with `(string)`, raising `E_WARNING Array to string conversion` and writing a warning row into
  `events` on every such request — reachable by any logged-in user (rule 12). Now an explicit
  `is_array()` guard answering the same `400`, with no notice. Pinned by rows K4/K5.

## Verification

`tmp/fixtures_1759.php` (extended by this run with one test case per project, because the fixture
held suites only and the wrong-type branch could not be exercised with it) + `tmp/verify_1790.php`:

* **57/57 PASS** on the fixed code, **44 PASS / 13 FAIL** with `api/suitemove/index.php` reverted —
  the suite is discriminating, not vacuous.
* Byte equality is asserted, not just the status; `sameBytes()` fails loudly if a request never
  reached the endpoint, so it cannot pass by comparing two empty bodies.
* Legitimate writes are asserted for real: the DB `parent_id` is read back after each move
  (`top`/`bottom`, onto a suite, onto the project root, plus a restore).
* 0 new ERROR/WARNING rows in `events` (`V1`).

## Notes for the next agent

* **A harness bug was found and fixed here that affected the #1761 suite too.** `fixtures_1759.php`
  and `fixtures_1761.php` both create suites named `A-suite-1` / `B-suite-1`, and both harnesses
  resolved them by **bare name**, so each took whichever row the index returned first. With both
  fixtures loaded, `verify_1761.php` reported four baffling failures (`Container not found`, "only
  1 child") because it was silently testing the *other* fixture's suites. Every child lookup in both
  harnesses is now scoped by `parent_id`. **When adding a fixture, namespace its node names, or
  scope every lookup by `parent_id`.**
* The shell harnesses of this lane (`tmp/verify_1759.sh`, `tmp/verify_1779.sh`) shell out to the
  `mysql` **client**, which is not installed in the dev container; that is why the new suite is PHP.
* Out of scope, found during review, **not** fixed here (no existence leak, no privilege change):
  a JSON request body (`Content-Type: application/json`) leaves `$_REQUEST` empty, so the
  "unusable destination" guard at `new_parent_id` is bypassed and the move degrades to an
  in-container reorder. Pre-existing; the endpoint is form-encoded throughout.
* Timing side channel (pre-existing, class-level, also present in `tcreorder` / `tcstepsreorder`):
  measured 8.7 ms for an absent id, 10.7 ms for a foreign suite, 14.5 ms for an existing-but-wrong-
  type id. Bodies are byte-identical, work is not. Closing it needs constant-work validation and is
  a class decision, not this issue's job.
* `suitemove` still repeats its literals (`Container not found` ×5, `Suite not found` ×6,
  `Destination not found` ×3) where the sibling endpoints now have single-source helpers
  (`tcreoNoSuchContainer()`, `tsroNoSuchVersion()`). Correct today, one edit away from regressing.