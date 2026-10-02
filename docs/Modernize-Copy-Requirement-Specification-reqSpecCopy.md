# Modernize: Copy Requirement Specification (`reqSpecCopy`)

- **Issue:** [#1797](https://github.com/sebiboga/testlink-upgraded/issues/1797) (enhancement) — bugs found while testing: [#1798](https://github.com/sebiboga/testlink-upgraded/issues/1798), [#1799](https://github.com/sebiboga/testlink-upgraded/issues/1799), [#1800](https://github.com/sebiboga/testlink-upgraded/issues/1800)
- **Legacy screen:** `lib/requirements/reqSpecEdit.php` actions `copy` / `doCopy` → `gui/templates/dashio/requirements/reqSpecCopy.tpl`
- **Modern screen:** `gui/templates/requirements/reqSpecCopy.html`
- **BFF:** `api/reqspeccopy/index.php`
- **Entry point:** a new **Copy Requirement Specification** button on the modern Requirement Specification Viewer (`gui/templates/requirements/reqSpecView.html`) + `$actions->reqSpecCopy`

## Why this screen

The TODO section of `docs/MODERNIZATION-STATUS.md` is empty (every ASIDE entry maps to a
modern screen), so the smallest coherent legacy-only slice was picked: the
requirement-specification **copy popup**, the last un-ported `reqSpecEdit.php` action.

Two things made it worth doing on its own:

1. **The capability had no entry point at all.** Both legacy viewer templates only
   *assigned* `{$req_spec_copy_url}` (`dashio:53`, `tl-classic:50`) and never used it,
   and `reqSpecViewButtons.inc.tpl` has no copy-spec button — the popup was reachable
   by URL only, already in 1.9.20. 2.0.1 kept no modern replacement, so the modern
   Requirement Specification Viewer now finally gets a real toolbar button.
2. **The legacy write was unsafe.** `reqSpecEdit.php` runs `checkRights()` against the
   **SESSION** context only, while `containerID` and `tproject_id` came straight out of
   the request body — a copy could be aimed at any node of any test project. It also
   offered the source specification as its own destination, and its
   `target_position` (top / bottom) radio pair was **dead UI**: `doCopy()` called
   `copy_to()` with four arguments and never read the position.

## Legacy behaviour carried over

| Legacy | Modern |
|---|---|
| Source card: doc id, title, test project, prefix, author, scope | same, scope rendered as **escaped plain text** (`stripHtml`) |
| Destination = the test project that owns the source | kept as the default, but a project **switcher** is offered |
| Container `<select>` of the whole tree | indented list, **source subtree removed** |
| `target_position` radios (rendered, never used) | **actually honoured** — `node_order` renumbered in two collision-free phases |
| Message `A copy of Req. Spec (DOCID:… - …) has been done (DOCID:… [n])` | verbatim |
| `Copy` / `Cancel` | `Copy` + `Open the copy` shortcut to the new specification |

## Hardening over legacy

- `mgt_view_req` **and** `mgt_modify_req` on the **destination** project (the legacy
  screen only ever checked the session project).
- `mgt_view_req` on the project that **owns** the source, so a specification cannot be
  read (or its metadata leaked) across projects.
- The destination node is *proved* to be a test project / requirement specification of
  the destination project (`rscResolveContainer`), so a foreign container is a 400.
- A destination **inside the source subtree** is refused
  (`destination_inside_source`) — legacy happily copied a spec into its own child.
- `bffSameOriginGuard()` + `bffEnforceSession()` on the write, stable machine codes on
  every error answer (400/401/403/404/405).
- The legacy `copy` / `doCopy` URLs are now a **non-mutating 302 shim** placed *before*
  `new reqSpecCommands()` / `initialize_gui()`, so the old write is not executed and the
  request does not even reach the GUI initializers. It carries `?legacy_intent=`, and
  the popup then says *"Nothing has been copied yet"*.

## BFF contract

```
GET  ?action=init&req_spec_id=N[&tproject_id=P]
       → source, destination, default_project_id, destinations[], rights, tproject_fallback_from
GET  ?action=projects          → the test projects the caller may write into
POST ?action=copy&req_spec_id=N&container_id=M[&tproject_id=P]&target_position=top|bottom
       → new{id,doc_id,title}, position
```

## Bugs found and fixed

| Issue | What |
|---|---|
| [#1798](https://github.com/sebiboga/testlink-upgraded/issues/1798) | `?action=projects` listed **orphan** `node_type_id=1` nodes (a `nodes_hierarchy` row with no `testprojects` row, which `testproject::delete()` leaves behind) as destinations. `rscResolveContainer` proves the destination against `testprojects`, so every pick of one died with 400. Now an `INNER JOIN testprojects … AND TP.active = 1`. |
| [#1799](https://github.com/sebiboga/testlink-upgraded/issues/1799) | Five screen defects: (1) the success box was wiped by the post-copy `load()`, so a successful copy looked like a no-op; (2) `showState()` picked "the first `<p>` without a `<code>`", which is the **access-denied hint** — every error card showed the wrong explanation and an **empty machine code**; (3) the first repair of (2) lower-cased `notFound` while the markup id is `notFoundCode`, so it silently did nothing; (4) a stale `?tproject_id=` (exactly what the legacy shim hands over — the SESSION id) produced zero containers, an empty selector and a disabled Copy; (5) the `?legacy_intent=` notice was wiped twice and threw `ReferenceError: p is not defined`. |
| [#1800](https://github.com/sebiboga/testlink-upgraded/issues/1800) | Event Viewer: `E_WARNING Undefined property: tlUser::$id` on every denial (`tlUser` has no `->id`; the id is the protected `dbID` of `tlObject`), and one `Trying to access array offset on null` pair per shim hit because the redirect ran after `initialize_gui()`. |

## Test results

Suite **1797** = **25 / 25 PASS** (`tmp/TLU_Test_Cases.md`), covering the two positions,
the project root, a cross-project copy, the empty-destination state, the 400/401/403/404/405
matrix, both legacy shim branches, the toolbar button, the "every other `reqSpecEdit.php`
action still works" regression, and the Romanian bundle (0 missing `data-i18n` keys).
Event Viewer clean — three consecutive shim hits add **0** rows.

## Screenshots

- `docs/screenshots/issue-1797-reqspeccopy-init.png` — the screen
- `docs/screenshots/issue-1797-reqspeccopy-success.png` — after a successful copy
- `docs/screenshots/issue-1797-reqspeccopy-no-target.png` — no destination available
- `docs/screenshots/issue-1797-reqspeccopy-notfound.png` — the 404 card + machine code
- `docs/screenshots/issue-1797-reqspeccopy-legacy-notice.png` — the `?legacy_intent=` notice
- `docs/screenshots/issue-1797-reqspeccopy-locale-ro.png` — Romanian

## Mandatory code review and the follow-up fixes (#1801, #1802, #1803)

The review found **no exploitable authorization hole** in the BFF (rights are enforced on the
destination project and on the project owning the source, the destination node is *proved* to
belong to the addressed project, source-subtree destinations are refused, and the id-resolution
order avoids a pre-authorization existence oracle). It did find three real defects, all fixed and
re-verified here — Suite 1801 = 15/15 PASS.

| Issue | Defect | Fix |
|-------|--------|-----|
| [#1801](https://github.com/sebiboga/testlink-upgraded/issues/1801) | `rscApplyTargetPosition()` renumbered the destination's children **sorted by `id`**, not by `node_order` — every copy silently reversed an arrangement the user had made by dragging; and it skipped requirement children, so the new spec could tie with a requirement on the same `(parent_id, node_order)` | collect every child with its `node_order`, order by `(node_order ASC, id ASC)`, renumber in two collision-free phases, actually check the `exec_query()` result (409 `position_write_failed`), default position `top` |
| [#1802](https://github.com/sebiboga/testlink-upgraded/issues/1802) | **stored XSS** — the scope was rendered through `document.createElement('div').innerHTML`, which is *not* an inert sink (`window.__xss === true` with a planted `<img onerror>`); plus the real `copy_to()` failure reason discarded behind a two-armed `copy_failed` ternary, the `legacy_intent` notice overwriting the success confirmation, `json_encode()` returning an empty HTTP 200 for non-UTF-8 titles, the container selection snapping back to the project root, and `footers.reqSpecCopy` never rendered | `DOMParser` + `body.textContent` (the approach `searchView.html` / `reqSpecView.html` already use), surface `$op['msg']` and the committed `partial` id, notice only while `LAST_NEW_ID === 0`, `JSON_INVALID_UTF8_SUBSTITUTE`, preserve/reload the selection, render the footer from its i18n key |
| [#1803](https://github.com/sebiboga/testlink-upgraded/issues/1803) | `requirement_spec_mgr::copy_to()` ran `$sdx <= count($subtree)` — one element past the end — so `$elem['node_type_id']` on `null` raised an `E_WARNING` row **on every recursive copy** (also affects reqSpecTreeCopy and the plan-level copies) | off-by-one fixed; 0 new `events` rows for a recursive copy of a specification with a child specification and requirements |

Live proof of the #1801 fix: with `node_order` seeded to disagree with `id`
(`232`=1, `228`=2), a **bottom** copy yielded `232:1  228:2  new:3` and a **top** copy yielded
`new:1  232:2  228:3  …` — the arrangement survives. Copying into a specification that already
holds a requirement renumbered the requirement too (`spec:1  spec:2  spec:3  requirement:4`).
