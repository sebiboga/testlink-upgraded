# TestLink Event Viewer — Wiki

The Event Viewer is a redeveloped, modern screen for real-time monitoring of all system events in TestLink. It replaces the legacy event viewer with a clean HTML+JavaScript interface powered by its own BFF (Backend-for-Frontend) API.

**Path:** System > Event Viewer  
**URL:** `gui/templates/eventviewer/eventviewer.html`  
**Prerequisite:** You must be logged in with the `mgt_view_events` right (all users by default).

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Filters](#2-filters)
3. [Charts](#3-charts)
4. [Events Table](#4-events-table)
5. [Row Detail View](#5-row-detail-view)
6. [Delete Events](#6-delete-events)
7. [API Endpoints](#7-api-endpoints)
8. [Color Scheme](#8-color-scheme)

---

## 1. Screen Layout

The page is divided into four horizontal sections:

| Section | Description |
|---------|-------------|
| **Header** | Teal banner with title "Event Viewer" and subtitle "real-time log monitoring" |
| **Filters bar** | Dark charcoal bar with filter controls |
| **Charts row** | Two side-by-side chart panels |
| **Events table** | DataTables-powered table with expandable rows |
| **Footer** | Total event count and generation timestamp |

No iframes, no PHP rendering, no Smarty templates — pure static HTML with JavaScript calling the BFF API.

---

## 2. Filters

The filter bar sits at the top and allows narrowing down the displayed events. All filters are applied server-side via query string parameters.

| Filter | Type | Description |
|--------|------|-------------|
| **Log Levels** | Multi-select (default: all selected) | Filter by event severity. Hold Ctrl/Cmd to select multiple. Available levels: AUDIT, ERROR, WARNING, INFO, DEBUG, L18N |
| **User** | Single-select dropdown | Filter events by a specific user. "All users" shows events from everyone |
| **From** | Date picker (dd/mm/yyyy) | Start date of the date range filter |
| **To** | Date picker (dd/mm/yyyy) | End date of the date range filter |
| **Apply** | Button | Reloads the table, charts, and footer with the current filter settings |
| **Clear Events** | Button (admin only) | Deletes events matching the current filters (see [Delete Events](#6-delete-events)) |

**Note:** The date pickers use the `daterangepicker` library but are configured to accept a single date per field (From and To), not a range.

---

## 3. Charts

Two charts provide a visual overview of event distribution:

### Events by Level (Doughnut Chart)

- Shows the count and percentage of events for each log level
- Color-coded to match the level badges in the table
- Includes an inline legend on the right side of the chart
- Uses Chart.js v1 `Doughnut` with 45% inner cutout

### Events per Day (Line Chart)

- Shows the number of events per day over the last 30 days
- Area-filled line chart with the TestLink teal accent color
- Automatically generated from the filtered dataset
- Uses Chart.js v1 `Line` with bezier curves

Both charts update whenever the **Apply** button is clicked with new filter settings.

---

## 4. Events Table

A DataTables-powered table listing all matching events, sorted by timestamp descending (newest first).

### Table Columns

| Column | Width | Description |
|--------|-------|-------------|
| (expand) | 30px | Clickable chevron icon to toggle the detail row |
| Timestamp | auto | Formatted as `dd/mm/yyyy hh:mm:ss` |
| Level | auto | Colored badge showing the log level (AUDIT, ERROR, WARNING, INFO, DEBUG, L18N) |
| User | auto | Display name of the user who triggered the event, or `-` for system events |
| Description | auto | The event description text. Clicking the row also expands the detail view |

### Behavior

- **Pagination:** 25 rows per page by default
- **Sorting:** Click any column header (except expand and description) to sort ascending/descending
- **Search:** Use the DataTables search box to filter the visible rows client-side
- **Row expand:** Click the chevron icon or the description text to expand/collapse the detail row

---

## 5. Row Detail View

Expanding a row reveals additional metadata about the event in a bordered detail panel:

| Field | Description |
|-------|-------------|
| Source | The system component that generated the event |
| Session ID | The transaction/session ID (shown with `#` prefix) |
| Object Type | The type of object involved (e.g., test case, test plan) |
| Object ID | The database ID of the object |
| Activity | The activity code describing what happened |
| Timestamp | Full timestamp of the event |

The detail data is fetched on-demand via `GET /api/eventviewer/index.php/events/{id}`.

---

## 6. Delete Events

Users with the `events_mgt` right (typically admins) see a **Clear Events** button in the filter bar.

- Clicking the button shows a confirmation dialog: *"Delete events matching current filters?"*
- Events are deleted based on the **currently selected log level filter**
- If no log levels are selected, all events are deleted (use with caution)
- After deletion, the table and charts reload automatically
- The button is hidden for users without the `events_mgt` right

---

## 7. API Endpoints

The Event Viewer uses a dedicated BFF API at `/api/eventviewer/index.php`. All endpoints require an authenticated session.

### Metadata

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/events/meta/logLevels` | Returns available log level codes and names |
| GET | `/events/meta/users` | Returns all users (id, login, displayName) for the user filter dropdown |
| GET | `/events/meta/rights` | Returns the current user's `canDelete` and `canView` permissions |

### Events

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/events` | List events with optional filters (see below) |
| GET | `/events/{id}` | Get full details of a single event |

#### GET /events — Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `logLevel` | Comma-separated ints | Filter by log level codes (e.g., `logLevel=1,2`) |
| `user` | Comma-separated ints | Filter by user IDs |
| `startDate` | String (dd/mm/yyyy) | Events from this date onwards |
| `endDate` | String (dd/mm/yyyy) | Events up to this date |
| `limit` | Integer (default: 500) | Maximum number of events returned |

#### Event Object

```json
{
  "id": 42,
  "timestamp": 1692000000,
  "timestampFormatted": "15/08/2023 14:00:00",
  "logLevelCode": 3,
  "logLevel": "INFO",
  "description": "User admin logged in",
  "source": "login",
  "userID": 1,
  "userName": "admin",
  "userDisplayName": "Administrator",
  "transactionID": 12345,
  "objectID": 1,
  "objectType": "users",
  "activityCode": "login"
}
```

### Statistics

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/events/stats/byLevel` | Returns event counts grouped by log level |
| GET | `/events/stats/perDay` | Returns daily event counts for the last 30 days |

### Deletion

| Method | Endpoint | Description |
|--------|----------|-------------|
| DELETE | `/events` | Delete events. Body: `{"logLevel": [1,2]}` to delete specific levels, or `{}` to delete all. Requires `events_mgt` right. |

---

## 8. Color Scheme

The Event Viewer uses the standard Dashio color palette:

| Element | Color | Hex |
|---------|-------|-----|
| Header background | Teal | `#4ECDC4` |
| Filter bar background | Dark charcoal | `#22242a` |
| AUDIT badge | Teal | `#4ECDC4` |
| ERROR badge | Red | `#e6605e` |
| WARNING badge | Amber | `#f0ad4e` |
| INFO badge | Blue | `#3498db` |
| DEBUG badge | Gray | `#8f8f8f` |
| L18N badge | Purple | `#9b59b6` |
| Active page indicator | Teal | `#4ECDC4` |

---

## Files

| File | Purpose |
|------|---------|
| `gui/templates/eventviewer/eventviewer.html` | Frontend page (HTML + JS + CSS, no PHP) |
| `api/eventviewer/index.php` | BFF API (plain PHP, no framework) |
