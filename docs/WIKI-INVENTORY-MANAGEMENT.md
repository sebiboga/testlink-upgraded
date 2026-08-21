# Inventory Management — Modernized Screen

**Path:** ASIDE menu > Projects > *Inventory management*
**URL:** `gui/templates/inventory/inventoryView.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/inventory/index.php` (session-based auth, JSON I/O)
**Prerequisite:** the test project must have *Enable Inventory* checked
(`options.inventoryEnabled`) **and** the user must hold `project_inventory_view`
(the ASIDE link is hidden otherwise — see grant normalization fix for issue #535).
**Replaces:** legacy `lib/inventory/inventoryView.php` (ExtJS grid) +
`getInventory.php` + `setInventory.php` + `deleteInventory.php` +
`lib/ajax/getUsersWithRight.php` (owner combo source). All writes go through
TestLink's own `tlInventory` class, so validation semantics are identical to 1.9.20.


---

## What it does

Manages the device/hardware inventory of a test project (servers, switches,
test machines). One row per device with:

| Column | Notes |
|--------|-------|
| Host name | required, max 255 chars, unique per project (case-insensitive) |
| IP Address | optional, IPv4 with octet range check, unique per project when set |
| Purpose | free text, max 2000 chars |
| Hardware | free text, max 2000 chars |
| Owner | user holding `project_inventory_view`; defaults to the logged-in user on create; "(no owner)" entry allowed |
| Notes | free text, max 2000 chars |

## Actions

- **Create Device** (toolbar, `project_inventory_management` right): opens the
  "Define a device data" modal.
- **Edit**: click the device name or double-click the row — same modal prefilled.
- **Delete** (row trash icon + confirm dialog): removes the device; both create
  and delete write AUDIT events (`audit_inventory_created` / `audit_inventory_deleted`),
  exactly like the legacy screen.



## Validation (legacy parity via `tlInventory::checkInventoryData()`)

| Condition | Server code | UI message |
|-----------|-------------|------------|
| empty name | -2 | "The device cannot have an empty name." |
| duplicate name (case-insensitive) | -1 | "A device with this name already exists in the project." |
| duplicate IP | -4 | "A device with this IP address already exists in the project." |
| DB write failure | -8 | generic DB error message |
| malformed IPv4 (client-side) | — | "Must be a numeric IP address (e.g. 192.168.0.1)." |

## Permissions

| Right | Effect |
|-------|--------|
| `project_inventory_view` | see the grid (BFF enforces on GET; legacy leaked reads, the BFF is stricter) |
| `project_inventory_management` | Create/Edit/Delete; BFF returns `403 NO_RIGHTS` otherwise |

Users without the view right who open the URL directly get an in-place
"no appropriate rights" message instead of the grid; the Create button is hidden
for view-only users.

## BFF endpoints

| Method & path | Purpose |
|---------------|---------|
| `GET /?tproject_id=N` | device list (+ owner login, rights, inventoryEnabled flag, current user id) |
| `GET /owners?tproject_id=N` | users with `project_inventory_view` + "(no owner)" entry (`getUsersWithRight.php` parity) |
| `POST /` | create device (`tlInventory::setInventory`, machineID=0) |
| `PUT /{id}` | update device |
| `DELETE /{id}?tproject_id=N` | delete device (`tlInventory::deleteInventory`) |

## i18n

All labels/messages/placeholders use `inv.*` keys added to **all 10 locale
bundles** (`en, ro, de, es, fr, it, ja, pt, ru, zh`), applied through the
client-side `TLi18n` module with live locale switcher support.

## Testing

Suite 30 in `tmp/TLU_Test_Cases.md`: 17/17 PASS (CRUD, validation matrix,
dblclick edit, search, locale switch, permission paths, Event Viewer clean).

---

# Inventory - Test Data Management 📦 (concept)

**Inventory** este sistemul de gestiune al **datelor de test** pe care le folosești la execuție:
test accounts, API credentials, data sets, medii de test (URL-uri, endpoint-uri),
credențiale și fișiere sample. Ecranele modernizate 2.0.1 gestionează *dispozitivele*
(servere, switch-uri, mașini de test) ale proiectului; principiile de mai jos rămân valabile:

- Centralizează datele de test — consistență între testeri, refolosire, refresh ușor între rulări.
- Nu folosi date de producție; rotește credențialele; mască valorile sensibile.
- Documentează fiecare item (scop, hardware, note) și urmărește schimbările prin audit trail.

---

**Inventory: ESSENTIAL pentru consistent test data! 🎯**
