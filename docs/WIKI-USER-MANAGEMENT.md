# TestLink User Management — Wiki

TestLink provides four screens for managing users, roles, and role assignments. All four are accessible from the **System** section of the left navigation sidebar and share a common tab bar for quick navigation between screens.

**Prerequisite:** You must be logged in as **admin** (or a user with the `mgt_users` and/or `user_role_assignment` rights).

---

## Table of Contents

1. [User Management](#1-user-management)
2. [Role Management](#2-role-management)
3. [Assign Test Project Roles](#3-assign-test-project-roles)
4. [Assign Test Plan Roles](#4-assign-test-plan-roles)
5. [Role Hierarchy](#5-role-hierarchy)
6. [Common Workflows](#6-common-workflows)

---

## 1. User Management

**Path:** System > User Management  
**URL:** `gui/templates/usermanagement/usersView.html`  
**Purpose:** Create, edit, enable/disable, and delete user accounts.

### Screen Layout

| Element | Description |
|---------|-------------|
| **Create User** button | Opens the create user modal |
| **Export** button | Redirects to the modern User Management Export screen |
| **Manage user** box | Login lookup (legacy `manage_user` form): type a login and click **Manage user** to jump straight to that user's edit modal; an unknown login shows a localized "login does not exist" message |
| **Data Table** | Lists all active and inactive users (soft-deleted users are hidden) |
| **Search box** | Filters the table by any column |
| **Show entries** | Controls how many rows are displayed per page |

### Manage User Lookup (legacy parity, issue #881)

Legacy `gui/templates/dashio/usermanagement/usersView.tpl` showed a "Login [...] Manage user"
form below the buttons that posted to `usersEdit.php?doAction=edit&login=<login>`, where
`initializeGui()` resolves the login to a user id via `tlUser::doesUserExist()` and opens
the edit screen — or shows `login_does_not_exist` for an unknown login.

The modern screen reimplements this in the toolbar:

1. Type a full login into the lookup box (placeholder = Login, maxlength 30, required).
2. Click **Manage user** → the BFF resolves it (`GET /api/users/index.php?login=<login>`)
   and opens the **Edit User** modal for that user.
3. Unknown login → localized alert "Login ___ does not exist"
   (`user.loginDoesNotExist`, present in all 10 locale bundles); empty input is blocked
   client-side (HTML5 `required`), and an empty parameter to the API returns 400
   `login_required`.

Screenshot: `docs/screenshots/issue-881-manage-user-lookup.png`.

### Table Columns

| Column | Description |
|--------|-------------|
| # | Auto-incremented user ID |
| Login | The username used to log in |
| Name | The user's display name (first + last) |
| E-mail | The user's email address |
| Global Role | The system-wide role assigned to this user |
| Locale | The UI language preference (e.g. `en_GB`, `fr_FR`) — legacy parity `usersView.php:173` (`th_locale`) |
| Active | **Active** (green badge) or **Inactive** (red badge) |
| Expiration Date | Localized expiration date of the account (`localize_dateOrTimeStamp(null,null,'date_format',…)` server-side, e.g. `31/12/2026`); empty cell when no expiry set — legacy parity `usersView.php:262-267`. Legacy gap #883 |
| Actions | Edit, Reset password, Enable/Disable, Delete icons |

### Actions

- **Edit** (pencil icon): Opens the edit modal to change user details and global role
- **Reset password** (key icon, issue #884): Generates a new random password for the user, then either emails it (default `password_reset_send_method = send_password_by_mail`) or returns it to be displayed on screen (`display_on_screen`). Shown only for users whose effective authentication method has `allowPasswordManagement = true` (legacy `usersEdit.tpl:169-178` parity)
- **Enable/Disable** (toggle icon): Toggles the user between Active (1) and Inactive (0) states
- **Delete** (trash icon): Soft-deletes the user (sets `active = 2`, hidden from UI but retained in database). **Not available for the admin user**

### Reset password (issue #884)

The legacy edit screen had a dedicated **Reset password** form
(`gui/templates/dashio/usermanagement/usersEdit.tpl:339-356` → `usersEdit.php:222-269`
`createNewPassword()` → `lib/functions/users.inc.php:167-225` `resetPassword()`). The
modern screen ports it to a row action backed by a new BFF route:

- **UI** (`gui/templates/usermanagement/usersView.html`): a key icon in the Actions
  column per row, gated by `canResetPassword(u)` against the `/meta/authentication`
  payload (the user's `authentication` value, falling back to the configured default
  method — same rule as the legacy template). Clicking shows a confirm dialog, then
  `POST /api/users/index.php/{id}/reset-password`; the result is shown as an alert:
  the bundle message for *sent-by-mail*, `user.passwordShownOnScreen` with the fresh
  password for *display-on-screen*, or the localized error.
- **BFF** (`api/users/index.php`, `POST /users/{id}/reset-password`), mirroring the
  legacy `createNewPassword()` flow step by step:
  1. `tlUser::isPasswordMgtExternal($u->authentication)` → refuse with
     `password_mgmt_external` (defense-in-depth; the UI hides the action anyway);
  2. `config_get('demoMode')` → refuse (`demo_reset_password_disabled`);
  3. reads `password_reset_send_method`; unless it is `display_on_screen`, validates
     `config_get('smtp_host')` with `Zend_Validate_Hostname(ALLOW_ALL)` →
     `invalid_smtp_hostname` on failure;
  4. calls the legacy `resetPassword($db,$userId,$method)` (email send via
     `email_send()` when by-mail) and on success logs
     `logAuditEvent(tls('audit_pwd_reset_requested',$login),'PWD_RESET',$id,'users')`.
- **Config**: `config_get('password_reset_send_method')` decides send-by-mail vs
  display-on-screen. With the default unconfigured SMTP host the by-mail path returns
  the same message as legacy: *"Password Reset can not be done. Reason: SMTP Hostname
  seems to be invalid"*.
- New i18n keys in all 10 locale bundles: `user.resetPassword`,
  `user.resetPasswordConfirm`, `user.passwordResetSent`,
  `user.passwordShownOnScreen`, `user.passwordResetInvalidSmtp`,
  `user.passwordResetDenied`, `user.resetFailed`, `user.passwordResetError`.
- Screenshots: `docs/screenshots/issue-884-usersView-reset-password-actions.png`.

### Generate API key (issue #885)

The legacy edit screen offered a **Generate a new key** button
(`gui/templates/dashio/usermanagement/usersEdit.tpl:351-355`) gated on
`$tlCfg->api->enabled` and wired to `usersEdit.php:274-318` `createNewAPIKey()`.
The modern screen ports it to a row action backed by a new BFF route:

- **UI** (`gui/templates/usermanagement/usersView.html`): an **id-card icon** in the
  Actions column per row, rendered only when the `/meta/authentication` payload
  reports `apiEnabled = true` (`canGenerateApiKey()` — legacy `$tlCfg->api->enabled`
  parity). Clicking shows a confirm dialog (`user.generateApiKeyConfirm`), then
  `POST /api/users/index.php/{id}/generate-apikey`; the result is shown as an alert:
  *"New API key has been sent via mail"* (`user.apiKeySent`) or the localized error.
- **BFF** (`api/users/index.php`, `POST /users/{id}/generate-apikey`), mirroring the
  legacy `createNewAPIKey()` flow step by step:
  1. re-checks `$tlCfg->api->enabled` server-side → `403 api_disabled`
     (defense-in-depth; the UI hides the action anyway);
  2. validates `config_get('smtp_host')` with `Zend_Validate_Hostname(ALLOW_ALL)` →
     `400 invalid_smtp_hostname` on failure (same gate as legacy). The legacy lang key
     `apikey_cannot_be_reseted_invalid_smtp_hostname` is not defined in any
     `strings.txt` bundle (lang_get would emit a "not localized" WARNING event), so the
     BFF returns a plain message and the UI maps the `code` to the localized
     `user.apiKeyInvalidSmtp`;
  3. calls `APIKey::addKeyForUser($id)` (`lib/functions/APIKey.class.php:38-50`
     `UPDATE users SET script_key = md5(8×mt_rand())`) and reads the fresh key via
     `getAPIKey()`;
  4. emails the key via `@email_send(...)` (subject `mail_apikey_subject`, body
     `your_apikey_is` + key + `contact_admin`). A delivery failure is caught and written
     to the PHP error log only (`error_log`) — never to the Event Viewer (no new
     WARNING events), matching legacy's silent `@` suppression;
  5. on success logs `logAuditEvent(tls('audit_user_apikey_set',$login),'CREATE',...)`.
- **Config**: `config_get('smtp_host')` + the legacy `mail_apikey_subject` /
  `your_apikey_is` / `from_email` globals. With the default unconfigured SMTP host the
  route returns `400 invalid_smtp_hostname`, same as legacy.
- New i18n keys in all 10 locale bundles: `user.generateApiKey`,
  `user.generateApiKeyConfirm`, `user.apiKeySent`, `user.apiKeyFailed`,
  `user.apiKeyInvalidSmtp`, `user.apiKeyDisabled`.
- Screenshots: `docs/screenshots/issue-885-apikey-generate-row-action.png`,
  `docs/screenshots/issue-885-apikey-generate-confirm.png`.

### Create/Edit User Modal

| Field | Required | Description |
|-------|----------|-------------|
| Login | Yes | Unique username (4-25 chars, alphanumeric + underscores) |
| Password | Yes (create only) | Minimum length enforced by system config |
| First Name | Yes | User's first name |
| Last Name | Yes | User's last name |
| E-mail | Yes | Valid email address |
| Global Role | Yes | Select from available roles (e.g., admin, test designer, tester, etc.) |
| Locale | No | UI language (e.g., en_GB, ro_RO) |
| Authentication method | No | Legacy parity `usersEdit.php:440-451` (issue #882): `Default (configured)` — value `''`, uses `config_get('authentication')['method']` — plus one option per `authentication['domain']` key (DB/LDAP), pulled from the new BFF meta endpoint `GET /api/users/index.php/meta/authentication`. Persisted into `users.auth_method` on create/update. |
| Expiration Date | No | Date picker (native `type=date`) + **Clear Date** button. Empty or cleared → `expiration_date = NULL`; otherwise stored ISO `Y-m-d` via `tlUser::setExpirationDate()`. Hidden entirely for users listed in `config_get('noExpDateUsers')` (default `['admin']`) — legacy `expDateEnabled` `usersEdit.php:462-467`; the BFF re-applies the same guard server-side. |

### Authentication method & Expiration Date (issue #882)

Legacy `usersEdit.tpl:268-304` let the admin choose the authentication method (Default/DB/LDAP) and set an expiration date with a calendar+clear control. The modern modal now matches:

- **Authentication method** dropdown is populated from `GET /api/users/index.php/meta/authentication` — first option `Default (<configured method>)` (value `''` = follow `authentication['method']`), then one option per domain key. Selected value persists to `users.auth_method` on both create and update (legacy `initializeUserProperties` parity).
- **Expiration Date** `input[type=date]` + **Clear Date** button. Empty date → `NULL`; a valid date is written via `tlUser::setExpirationDate()` (ISO `Y-m-d`). For users in `noExpDateUsers` (admin) the field is hidden in the modal **and** the BFF refuses to write it — double protection.
- The BFF `POST`/`PUT` now refresh the in-memory user after writing the expiry so the JSON response returns the stored `expirationDate`.
- New i18n keys in all 10 locale bundles: `user.authenticationMethod`, `user.defaultAuthMethod`, `user.clearDate` (plus existing `user.expirationDate`).

Screenshots: `docs/screenshots/issue-882-create-modal-auth-expdate.png`, `docs/screenshots/issue-882-edit-admin-exp-hidden.png`, `docs/screenshots/issue-882-grid-expiration-dates.png`.

### Password required on create (issue #888)

Legacy creates a user only with a **non-empty password**: the create form blocks submit with `warning_empty_pwd` (`usersEdit.tpl:153-155` sets `check_password=1`, `validateForm` hook, password input `required`) and the server rejects empty passwords — `doCreate()` checks `setPassword()` and treats `tlUser::E_PWDEMPTY` (< 0) as a failure, so `writeToDB()` never runs (`usersEdit.php:153-171`).

The modern screen now enforces the same rule on **both** layers:

- **BFF** (`api/users/index.php`, POST create): the `setPassword()` return value is checked — anything `< tl::OK` (i.e. `E_PWDEMPTY` = "The password must not be empty!") returns HTTP 400 `{status:error, code:'warning_empty_pwd', message:<legacy string>}` **before** `writeToDB()`, so no blank-password row can be persisted (not even via a direct API POST). `S_PWDMGTEXTERNAL` (2 >= OK) remains allowed, matching legacy `doCreate`, which validates the password against the configured default auth method.
- **UI** (`usersView.html`): the password input carries `required` in the create modal (removed again on edit — "leave empty to keep"), and `saveUser()` blocks an empty-password create client-side with the localized `user.passwordRequired` message, focusing the field — mirroring legacy `validateForm(f, check_password=1)`.
- **i18n**: new key `user.passwordRequired` in all 10 locale bundles, used for both the client-side block and the server 400 mapping.

Screenshots: `docs/screenshots/issue-888-create-blank-password-blocked.png`, `docs/screenshots/issue-888-create-with-password-ok.png`.

### Demo Mode (read-only gating, issue #887)

When `$tlCfg->demoMode = ON;` in `config.inc.php` the whole User Management
screen becomes read-only, mirroring legacy `usersEdit.tpl:312-355` where the
Save button is replaced by the `demo_update_user_disabled` note (on `doUpdate`)
and the Reset password / Generate key form by `demo_reset_password_disabled`.

- **BFF enforcement** (`api/users/index.php`): a shared `demoModeBlockedWrite()`
  helper (mirror of `api/userinfo`) returns HTTP 403 `{status:error,
  code:'demo_mode'}` from every write route — POST create, PUT update,
  PUT `/active`, DELETE, POST `reset-password` and POST `generate-apikey`.
  The UI can never bypass it.
- **UI gating** (`usersView.html`): with demo mode on a read-only amber banner
  appears under the header, the **Create User** toolbar button disappears, grid
  rows show only the **Edit** icon (no reset-password / generate-API-key /
  enable-disable / delete), and the create/edit modal replaces **Save** with a
  `user.demoUpdateDisabled` notice and disables every field. Cancel/close stay
  usable.
- **Discovery**: `GET /api/users/index.php/meta/authentication` now returns
  `demoMode` so the front-end knows the state.
- New i18n keys in all 10 locale bundles (values from the legacy
  `strings.txt`): `user.demoUpdateDisabled`, `user.demoResetPasswordDisabled`.

Screenshots: `docs/screenshots/887_1_demo_on.png`,
`docs/screenshots/887_2_modal_readonly.png`,
`docs/screenshots/887_3_normal_state.png`.

### Show Event History in the edit modal (issue #886)

Legacy `usersEdit.tpl:189-193` showed a question/help icon (title
`show_event_history`) next to the "User Details" legend that drilled into the
Event Viewer pre-filtered to the edited user's audit activity via
`showEventHistoryFor(user_id,'users')`. The icon was gated on
`grants->mgt_view_events` (`usersEdit.php:457-458`, `hasRight('mgt_view_events')`).

The modern edit modal replicates it:

- **BFF** (`api/users/index.php` `GET /meta/grants`): the grants payload now
  includes `mgt_view_events` (`'yes'`/`'no'`), mirroring the legacy separate
  assignment in `usersEdit.php:457-458` (the value is deliberately NOT part of
  `getGrantsForUserMgmt()`, exactly like legacy).
- **UI** (`usersView.html`): a **Show event history** button
  (`#btnEventHistory`, `fa-history` + `data-i18n="user.showEventHistory"`)
  appears in the edit modal header only while editing an existing user **and**
  the current logged-in user holds `mgt_view_events`. It is hidden in the
  create modal and for users without the right. Clicking it submits a hidden
  GET form (`object_id=<user id>&object_type=users`) which opens the modern
  Event Viewer at `eventviewer.html?object_id=<id>&object_type=users` in a new
  tab, showing "Filtered by users #<id>" — the same pattern as the user profile
  (`userInfo.html`) and milestones (`planMilestones.html`) screens.
- The Event Viewer BFF (`api/eventviewer/index.php`) independently enforces
  `mgt_view_events` (403 without it), so the filter cannot be abused.
- New i18n key in all 10 locale bundles: `user.showEventHistory` (values match
  the existing `profile.showEventHistory` translations).

Screenshots: `docs/screenshots/issue-886-users-edit-event-history.png`
(edit modal with the button), `docs/screenshots/issue-886-eventviewer-filtered-user.png`
(Event Viewer filtered to user #1).

### Operation Feedback (issue #890)

Legacy `lib/usermanagement/usersView.php:47` (disable) and
`lib/usermanagement/usersEdit.php:169/214` (create/update) filled
`$gui->user_feedback` with localized messages — `user_created` ("User %s was
successfully created"), `user_disabled` ("User %s was successfully disabled"),
`getUserErrorMessage()` for failures — rendered as a banner by
`inc_update.tpl`. The modern screen used to close the modal / reload the grid
after every write with no success feedback at all.

The 2.0.1 screen now mirrors that feedback with a Dashio toast (bottom-right,
auto-hides after ~3 s, teal for success / red for errors):

- **BFF** (`api/users/index.php`): every write-success route now returns
  `feedback_key` with the same key namespace legacy used for
  `$gui->user_feedback`: POST `/users` → `user_created`, PUT `/users/{id}` →
  `user_updated`, PUT `/users/{id}/active` → `user_enabled`|`user_disabled`,
  DELETE `/users/{id}` → `user_deleted`.
- **UI** (`gui/templates/usermanagement/usersView.html`): a fixed `.toast`
  element (same pattern as `cfieldsAssignView.html` / `planUpdateTC.html`) plus
  a `userFeedback(feedbackKey, login)` mapper to the client-side i18n keys.
  Create/update save (`saveUser()`), enable/disable (`toggleActive()`) and
  delete (`deleteUser()`) all show the localized toast with the affected login;
  toggle/delete errors moved from `alert()` to the red toast; the confirm
  dialogs (`user.confirmDelete/Disable/Enable`) and the status badges
  (`user.active`/`user.inactive`) are localized too.
- **i18n**: new keys in all 10 bundles — `user.feedback.created`,
  `user.feedback.updated`, `user.feedback.disabled`, `user.feedback.enabled`,
  `user.feedback.deleted`, `user.confirmDelete`, `user.confirmDisable`,
  `user.confirmEnable` — translations taken from the legacy
  `locale/<XX>/strings.txt` messages.

Screenshot: `docs/screenshots/issue-890-operation-feedback-toast.png` (create
toast "User <login> was successfully created").

### Tips

- Only users with `active = 1` (Active) appear in the table
- Deleted users (`active = 2`) are hidden but preserved for audit trail
- The admin user cannot be deleted or disabled
- Changes to other users are logged in the Event Viewer as AUDIT events

---

## 2. Role Management

**Path:** System > Role Management  
**URL:** `gui/templates/usermanagement/rolesView.html`  
**Purpose:** Create custom roles and manage their permission sets (rights).

### Screen Layout

| Element | Description |
|---------|-------------|
| **+ Create Role** button | Opens the create role modal |
| **Data Table** | Lists all roles (system + custom) |
| **Search box** | Filters roles by name or description |
| **Show entries** | Rows per page (10/25/50/100) |

### Table Columns

| Column | Description |
|--------|-------------|
| ID | Role ID |
| Name | Role display name |
| Description | Role description text |
| Rights | Color-coded permission tags (read/write/execute) |
| Type | **System** (grey badge) or **Custom** (teal badge) |
| Actions | Edit, Delete icons |

### System Roles (IDs 1-3)

System roles **cannot be edited or deleted**. They are protected by the system:

| ID | Name | Description |
|----|------|-------------|
| 1 | \<reserved system role 1\> | Reserved for internal use |
| 2 | \<reserved system role 2\> | Reserved for internal use |
| 3 | \<no rights\> | Empty role with no permissions |

### Built-in Custom Roles (IDs 4-9)

These roles ship with TestLink and can be edited (but not deleted):

| ID | Name | Typical Use |
|----|------|-------------|
| 4 | test designer | Design and manage test cases |
| 5 | guest | Read-only access to metrics |
| 6 | senior tester | Execute tests + create builds |
| 7 | tester | Basic test execution |
| 8 | admin | Full system administration |
| 9 | leader | Project and plan management |

### Create/Edit Role Modal

| Field | Description |
|-------|-------------|
| Name | Role name (required, must be unique) |
| Description | Optional description |
| Rights | Grid of checkboxes — select permissions for this role |
| **Select All** link | Toggles all checkboxes on/off |

### Rights Categories

Rights are color-coded in the table:

| Tag Color | Category | Examples |
|-----------|----------|----------|
| Blue | Read | `mgt_view_tc`, `mgt_view_req`, `testplan_metrics` |
| Red | Write | `mgt_modify_tc`, `mgt_modify_req`, `mgt_modify_product` |
| Green | Execute | `testplan_execute` |

### Deleting a Role

- Only **Custom** roles can be deleted (roles with IDs > 3)
- Before deletion, the system shows how many users currently have this role assigned
- If users are assigned, you must reassign them to another role before deletion

### Tips

- Create roles with only the permissions needed (principle of least privilege)
- Role rights apply globally — they are not project-specific
- Changes to roles immediately affect all users assigned to that role

---

## 3. Assign Test Project Roles

**Path:** System > Assign Test Project Roles  
**URL:** `gui/templates/usermanagement/usersAssignProject.html`  
**Purpose:** Assign a role to each user at the **test project** level.

### How It Works

Test project roles control what a user can do **within a specific test project**. This overrides or supplements the user's global role for project-scoped activities.

### Screen Layout

| Element | Description |
|---------|-------------|
| **Test Project** dropdown | Select which project to manage |
| **User Table** | Lists all active users with their current project role assignment |
| **Save Changes** button | Enabled only when changes are detected |

### Workflow

1. Select a **Test Project** from the dropdown
2. The table loads all active users and their current role assignment for that project
3. Change the **Assigned Role** dropdown for any user
4. Modified rows are highlighted in yellow with a **modified** badge
5. Click **Save Changes** to persist

### Table Columns

| Column | Description |
|--------|-------------|
| # | User ID |
| Login | Username |
| Name | Display name |
| Assigned Role | Dropdown with available roles + "-- no role --" |

### Role Options

| Option | Meaning |
|--------|---------|
| -- no role -- | User has no project-specific role (inherits global role for public projects) |
| test designer | Can manage test cases within this project |
| guest | Read-only metrics access |
| senior tester | Execute + create builds |
| tester | Basic test execution |
| admin | Full project administration |
| leader | Project management |
| Test Manager | Custom role (if created) |

### Access Rules

- Users see only projects they have access to (based on global role + project public/private settings)
- Admin users see all projects regardless of assignments
- A user with **no project role** on a private project will NOT have access to it

### Tips

- Project roles **do not override** the global role — they **supplement** it
- For public projects, any user with a valid global role can participate unless explicitly denied
- For private projects, users must be explicitly assigned a role

---

## 4. Assign Test Plan Roles

**Path:** System > Assign Test Plan Roles  
**URL:** `gui/templates/usermanagement/usersAssignPlan.html`  
**Purpose:** Assign role overrides to users for a **specific test plan**.

### How It Works

Test plan roles allow you to **narrow or change** a user's permissions for a specific test plan, overriding their test project role. This is useful when a user needs different permissions for different test plans within the same project.

### Screen Layout

| Element | Description |
|---------|-------------|
| **Test Project** dropdown | Select the parent project |
| **Test Plan** dropdown | Select the plan (loads after project is chosen) |
| **User Table** | Lists users with inherited role and plan override |
| **Save Changes** button | Enabled only when changes are detected |

### Workflow

1. Select a **Test Project** from the first dropdown
2. The **Test Plan** dropdown populates with active plans in that project
3. Select a **Test Plan**
4. The table loads all active users with:
   - Their **inherited role** from the test project assignment
   - Their current **plan role override** (if any)
5. Change the **Plan Role Override** for any user
6. Click **Save Changes** to persist

### Table Columns

| Column | Description |
|--------|-------------|
| # | User ID |
| Login | Username |
| Name | Display name |
| Inherited Role | The role inherited from the test project (read-only) |
| Plan Role Override | Dropdown to set a plan-specific override |

### Role Override Options

| Option | Meaning |
|--------|---------|
| -- no override -- | User uses their inherited project role (default) |
| test designer | Override to test designer for this plan only |
| guest | Override to read-only for this plan only |
| senior tester | Override to senior tester |
| tester | Override to tester |
| admin | Override to admin |
| leader | Override to leader |
| Test Manager | Custom role override |

### Role Resolution Order

When determining what a user can do in a test plan, TestLink checks (in order):

1. **Test plan role** (highest priority) — if set, this overrides everything
2. **Test project role** — the role assigned at the project level
3. **Global role** (lowest priority) — the user's system-wide role

### Example

> User `tester1` has:
> - Global role: `tester`
> - Project role for P2: `tester`
> - Plan role override for "smoke" plan in P2: `senior tester`
>
> Result: On the "smoke" test plan, `tester1` operates as a `senior tester` (can create builds, execute tests, view metrics). On all other plans in P2, they operate as a `tester`.

### Tips

- Use plan overrides sparingly — they add complexity to the permission model
- The **Inherited Role** column helps you understand what the user's effective role would be without an override
- Setting "-- no override --" removes the plan-specific override and falls back to the inherited project role

---

## 5. Role Hierarchy

```
Global Role (system-wide, assigned in User Management)
  └── Test Project Role (assigned in Assign Test Project Roles)
        └── Test Plan Role Override (assigned in Assign Test Plan Roles)
```

- **Global role** applies everywhere unless overridden
- **Project role** applies within a specific project (supplements global)
- **Plan role override** applies within a specific test plan (overrides project role)

### Predefined Role Hierarchy (by power level)

| Level | Role | Capabilities |
|-------|------|-------------|
| Highest | admin | Full access to everything |
| High | leader | Project + plan management, user assignment |
| Medium | senior tester | Execute tests, create builds, view metrics |
| Low | test designer | Design and manage test cases |
| Lowest | tester | Execute tests only |
| Read-only | guest | View metrics only |
| None | \<no rights\> | No access (ID 3) |

---

## 6. Common Workflows

### Adding a New Tester

1. Go to **User Management** > click **Create User**
2. Fill in login, name, email, password; select **tester** as global role
3. Go to **Assign Test Project Roles** > select the project
4. Set the user's role to **tester** for that project
5. (Optional) Go to **Assign Test Plan Roles** > select project + plan
6. Set a plan override if needed

### Granting Admin Access to One Project

1. Go to **Assign Test Project Roles** > select the project
2. Set the user's role to **admin** for that project only
3. Their global role remains unchanged — admin access is limited to this project

### Restricting a User on One Test Plan

1. Go to **Assign Test Plan Roles** > select project + plan
2. Set the user's **Plan Role Override** to **guest** (read-only)
3. On all other plans they retain their normal project role

### Creating a Custom Role

1. Go to **Role Management** > click **+ Create Role**
2. Enter a name and description
3. Use **Select All** to quickly check all permissions, then uncheck what you don't need
4. Click **Save**
5. The new role appears in all role assignment dropdowns across the system

---

## 7. Rights Enforcement (`mgt_users`)

Since issue #879, **every** User Management BFF endpoint enforces the legacy
`mgt_users` right (right id 13) on the session user — same check as legacy
`lib/usermanagement/usersView.php` `checkRights()`, also enforced in legacy
`usersEdit.php` and `usersExport.php`.

### Behavior

- A user **with** `mgt_users` (e.g. admin): gets `GET /api/users`, `POST`,
  `PUT`, `DELETE` and `GET /meta/grants` — full management.
- A user **without** `mgt_users` (e.g. guest): any request to `/api/users/…`
  returns `403 {"status":"error","message":"no_permissions_for_action","right":"mgt_users"}`.
  No user data leaks; no mutation is possible.
- The denial is also written to the audit trail as
  `audit_security_user_right_missing` (AUTH event).

### Grant-gated tabs

The modern User Management screen calls `GET /api/users/meta/grants`, which
mirrors legacy `getGrantsForUserMgmt()` (`lib/functions/users.inc.php`). The
resulting grant map controls which tabs the user sees:

| Tab | Required grant |
|-----|----------------|
| User Management | `user_mgmt` |
| Role Management | `role_mgmt` |
| Assign Test Project Roles | `tproject_user_role_assignment` |
| Assign Test Plan Roles | `tplan_user_role_assignment` |

Legacy parity: when the user holds `mgt_users`, `tproject/tplan` assignment
grants are forced to `"yes"` (those tabs stay visible). A user without
`mgt_users` sees only the no-access notice (i18n `user.noRights`) instead of
the tab bar. This mirrors legacy `gui/templates/dashio/usermanagement/menu.inc.tpl`.
