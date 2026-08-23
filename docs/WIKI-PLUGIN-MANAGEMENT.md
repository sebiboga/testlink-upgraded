# Plugin Management (pluginView) — Wiki

The modernized **Plugin Management** screen replaces the legacy
`lib/plugins/pluginView.php`. It lists the plugins registered in the database
(*Installed*) and the plugin directories found under `plugins/` that are not
registered yet (*Available*), and lets administrators install/uninstall them
with the exact legacy operations
(`plugin_register()` + `plugin_init()` + `plugin_install()` / `plugin_uninstall()`).

**Path:** System > Installed Plugins (`pluginView`)
**URL:** `gui/templates/plugins/pluginView.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/plugins/index.php` — `GET ?action=list`;
POST JSON `{operation:"install", pluginName:…}` / `{operation:"uninstall", pluginId:…}`
**Prerequisite:** `mgt_plugins` right on every route (same check as the legacy controller);
the menu link is rendered for users who pass it.

![Available state](images/plugins_available_empty.png)

---
## 1. Screen Layout
* Teal Dashio header: "Plugin Management" + subtitle + locale switcher.
* Dark toolbar: Refresh button + generated-on footer info.
* **Installed Plugins** table: Plugin, Description, Version, Status badge
  (Enabled/Disabled), Actions → Uninstall.
* **Available Plugins** table: Plugin, Description, Version, Actions → Install.
* Empty-state hints under each table when there is nothing to show.
* Bootstrap confirm modals before both mutations; localized feedback banner
  after success/failure.

![Install confirm](images/plugins_install_confirm.png)

## 2. Legacy Parity Notes
* Feedback strings are the same lang keys as 1.9.20 (`plugin_installed`,
  `plugin_uninstalled`) interpolated with the plugin name, now served through
  `TLi18n` in all locale bundles.
* The legacy page only showed the ExtJS confirm dialogs and a plain feedback
  line; behavior preserved with Dashio modals/toasts.
* `plugin_uninstall()` expects the plugin instance inside `$g_plugin_cache`
  (normally primed by a full page render). The BFF primes the cache via
  `plugin_register($name,false)` before uninstalling — otherwise the legacy
  function fatals AFTER deleting the row (500 + silently lost registration).

## 3. Rights Model
| Right | Effect |
|---|---|
| `mgt_plugins` | Required for list/install/uninstall (403 otherwise) and to see the menu entry |

## 4. REST API Reference
| Call | Body / Query | Response |
|---|---|---|
| `GET api/plugins/index.php?action=list` | — | `{success, installed:[{id,name,enabled,description,version}], available:[…], canManage}` |
| `POST api/plugins/index.php` | `{operation:"install", pluginName:"TLTest"}` | `{status:"ok", message:"plugin_installed", name:"TLTest"}` |
| `POST api/plugins/index.php` | `{operation:"uninstall", pluginId:1}` | `{status:"ok", message:"plugin_uninstalled", name:"TLTest"}` |

Errors are JSON `{status:"error", message}` with HTTP 400/401/403/404/405/500.
The POST operation may also be given as `?action=install|uninstall`; when the
query parameter is missing it is inferred from the JSON body's `operation`
field (mirrors the legacy form parameter).

![Installed](images/plugins_installed.png)

## 5. i18n Keys
All strings come from the client-side `TLi18n` module; keys prefixed `plugin.*`
exist in ALL bundles (en, ro, de, es, fr, it, pt, ru, ja, zh):
`plugin.header`, `plugin.headerSub`, `plugin.installed`, `plugin.available`,
`plugin.noInstalled`, `plugin.noAvailable`, `plugin.name`, `plugin.description`,
`plugin.version`, `plugin.status`, `plugin.enabled`, `plugin.disabled`,
`plugin.install`, `plugin.uninstall`, `plugin.confirmInstallHeader`,
`plugin.confirmInstallText`, `plugin.confirmUninstallHeader`,
`plugin.confirmUninstallText`, `plugin.msg.installed`, `plugin.msg.uninstalled`,
`plugin.msg.loadError`, `plugin.msg.opError`, `plugin.msg.noPermission`.

## 6. Testing
Suite 636 in `tmp/TLU_Test_Cases.md` — 16/16 PASS (render, install/uninstall
happy paths incl. DB verification, cancel paths, refresh, routing regression,
unknown-id guard, permission paths for BFF + screen, i18n switcher +
completeness, aside link switch, event viewer clean).

![Uninstall confirm](images/plugins_uninstall_confirm.png)
![No permission](images/plugins_no_permission.png)
