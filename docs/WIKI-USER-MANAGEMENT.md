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
| **Data Table** | Lists all active and inactive users (soft-deleted users are hidden) |
| **Search box** | Filters the table by any column |
| **Show entries** | Controls how many rows are displayed per page |

### Table Columns

| Column | Description |
|--------|-------------|
| # | Auto-incremented user ID |
| Login | The username used to log in |
| Name | The user's display name (first + last) |
| E-mail | The user's email address |
| Global Role | The system-wide role assigned to this user |
| Locale | The UI language preference |
| Active | **Active** (green badge) or **Inactive** (red badge) |
| Actions | Edit, Enable/Disable, Delete icons |

### Actions

- **Edit** (pencil icon): Opens the edit modal to change user details and global role
- **Enable/Disable** (toggle icon): Toggles the user between Active (1) and Inactive (0) states
- **Delete** (trash icon): Soft-deletes the user (sets `active = 2`, hidden from UI but retained in database). **Not available for the admin user**

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
