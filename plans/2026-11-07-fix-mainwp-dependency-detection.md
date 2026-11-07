# Fix false "requires MainWP Dashboard" notice

Date: 2026-11-07

## Context

MainWP ASE Manager 1.0.0 shows the error banner

> MainWP ASE Manager requires the MainWP Dashboard plugin to be installed and activated.

even when MainWP Dashboard 6.4.1 is installed and activated.

Root cause: `MainWP_ASE_Manager::bootstrap()` evaluated the dependency while the plugin file was
still being included. WordPress includes active plugins in the order of the `active_plugins`
option, which is sorted alphabetically. `mainwp-ase-manager/mainwp-ase-manager.php` compares
against `mainwp/mainwp.php` as `mainwp-` vs `mainwp/` (`-` is 0x2D, `/` is 0x2F), so this plugin is
always included **before** MainWP Dashboard. At that moment:

- `MAINWP_PLUGIN_FILE` / `MAINWP_PLUGIN_DIR` / `MAINWP_PLUGIN_URL` (defined in MainWP's
  `mainwp.php`) do not exist yet;
- `MainWP\Dashboard\MainWP_System` (instantiated from MainWP's `mainwp.php` at include time) is not
  loaded yet.

Both signals the old `is_mainwp_active()` tested therefore returned `false`, the extension bailed out
and registered `admin_notice_mainwp_required()` on `admin_notices` instead of its admin menu.

## Goal

- No false dependency notice when MainWP Dashboard is active, regardless of plugin folder names and
  alphabetical load order.
- The notice still appears when MainWP Dashboard is really inactive.
- Updates (GitHub updater, "Check for updates" link, update notice) keep working with MainWP
  Dashboard deactivated, as documented in `docs/testing.md`.

## Steps

1. In `mainwp-ase-manager.php`, keep the update hooks registered during inclusion and move the
   dependency gate into a new `maybe_init()` method hooked on `plugins_loaded` at priority `99`
   (after every plugin file has been included, and before `admin_menu` fires).
2. Expand `is_mainwp_active()` to accept any MainWP Dashboard signal:
   `MAINWP_PLUGIN_FILE` / `MAINWP_PLUGIN_DIR` / `MAINWP_PLUGIN_URL`,
   `MainWP\Dashboard\MainWP_System`, `mainwp_current_user_can()` (namespaced or global), and a last
   resort lookup of the active plugins.
3. Add `is_mainwp_plugin_active()`: `is_plugin_active( 'mainwp/mainwp.php' )` when
   `wp-admin/includes/plugin.php` is loaded, otherwise read `active_plugins` and (multisite)
   `active_sitewide_plugins` directly so front end requests do not need to load an admin file.
4. Add `tests/test-mainwp-dependency-detection.php`, a WordPress-free stub harness with five
   scenarios (MainWP loaded after us, class only, option only, network activated, inactive).
5. Update `README.md` (requirements + installation) and `docs/testing.md` (new harness section).

## Risks

- **Priority on `plugins_loaded`:** MainWP Dashboard instantiates `MainWP_System` at include time, so
  its constants and classes exist at any `plugins_loaded` priority. Priority `99` leaves room for
  MainWP helper functions loaded on `plugins_loaded`. `admin_menu` and `wp_ajax_*` fire later, so
  registering those hooks there is safe.
- **Custom MainWP folder names:** a renamed MainWP folder is still detected through
  `MAINWP_PLUGIN_FILE` / the `MainWP\Dashboard\MainWP_System` class; only the option lookup is
  hardcoded to `mainwp/mainwp.php`.
- **`is_plugin_active()` availability:** not defined on front end requests; the option fallback keeps
  detection working there without requiring `wp-admin/includes/plugin.php`.
- **Version bump:** left to the semantic-release workflow (a `fix:` commit produces a patch release).

## Verification

```bash
php -l mainwp-ase-manager.php
php -l tests/test-mainwp-dependency-detection.php
php tests/test-mainwp-dependency-detection.php   # 5/5 scenarios pass
sh tests/validate-plugin-layout.sh
```

Running the same harness against the pre-fix `mainwp-ase-manager.php` fails 3 of 5 scenarios,
including `mainwp-loaded-after-us`, which reproduces the reported banner.
