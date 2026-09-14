# MainWP ASE Manager

**MainWP ASE Manager** is a [MainWP Dashboard](https://mainwp.com/) extension (not a child plugin) that lets a dashboard administrator push the **Admin and Site Enhancements (ASE)** plugin configuration to one or more connected child sites from a single screen. You paste an ASE settings JSON export, select child sites, and click **Sync Settings**; the extension overwrites the `admin_site_enhancements` option on each selected child site using MainWP's built-in `update_option` child action, so no companion child plugin is required.

---

## How it works

The sync pipeline is:

1. The admin pastes an ASE settings JSON export into the **ASE Settings JSON** textarea on the extension page ([`render_page()`](includes/class-mainwp-ase-manager.php:116)).
2. The browser script ([`assets/js/mainwp-ase-manager.js`](assets/js/mainwp-ase-manager.js:82)) collects the JSON and the checked child site IDs, then POSTs them via `fetch()` to WordPress AJAX with action `mainwp_ase_manager_sync` and the `security` nonce.
3. The AJAX handler ([`ajax_sync_settings()`](includes/class-mainwp-ase-manager.php:166)) validates the nonce and permissions, decodes the JSON into an array, and passes it to the sync engine.
4. [`MainWP_ASE_Manager_Sync::sync()`](includes/class-mainwp-ase-manager-sync.php:27) loops over the selected site IDs and, for each site, calls MainWP's [`MainWP\Dashboard\MainWP_Connect::fetch_url_authed()`](includes/class-mainwp-ase-manager-sync.php:79) with the built-in `update_option` child action.
5. The child site runs MainWP's built-in `update_option` handler, which overwrites the `admin_site_enhancements` option with the pushed value.

The option payload is serialized with `maybe_serialize()` and `base64_encode()`d before being sent ([`MainWP_ASE_Manager_Sync::sync()`](includes/class-mainwp-ase-manager-sync.php:69)). The synced option key is `admin_site_enhancements` by default and can be overridden with the `mainwp_ase_manager_option_name` filter.

---

## Exact folder structure

```
wordpressmainwpasemanager/
├── mainwp-ase-manager.php                 # Root main file / bootstrap class
├── includes/
│   ├── class-mainwp-ase-manager.php       # MainWP_ASE_Manager_Page (menu, assets, AJAX)
│   └── class-mainwp-ase-manager-sync.php  # MainWP_ASE_Manager_Sync (sync engine)
├── assets/
│   ├── js/
│   │   └── mainwp-ase-manager.js          # Vanilla JS AJAX sync UI
│   └── css/
│       └── mainwp-ase-manager.css         # Extension page styles
├── composer.json
├── manifest.json
└── readme.txt
```

---

## Requirements

- **MainWP Dashboard** plugin active on the dashboard site (the extension shows an admin notice if it is not active — see [`is_mainwp_active()`](mainwp-ase-manager.php:155) and [`admin_notice_mainwp_required()`](mainwp-ase-manager.php:172)).
- **PHP >= 8.1**.
- **WordPress >= 5.0**.
- **Admin and Site Enhancements (ASE)** plugin installed and active on the **child sites** that will receive the settings.

---

## Installation

1. Install the plugin as a standard WordPress plugin on the **MainWP Dashboard site** — either upload the zip through **Plugins > Add New > Upload Plugin**, or copy the plugin folder into `wp-content/plugins/`.
2. Ensure the **MainWP Dashboard** plugin is already installed and activated first. If it is not, the plugin displays an error notice in the admin area rather than loading its functionality.
3. Activate **MainWP ASE Manager** through the **Plugins** screen.
4. Locate the new **ASE Manager** submenu under the **MainWP** dashboard menu (parent slug `mainwp_tab`).

---

## Usage

1. **Generate an ASE settings JSON export** from your configured master/staging site. ASE's settings export produces a JSON copy of your configuration. Use ASE's export feature to obtain that JSON and copy it to your clipboard. (If the exact button location differs between ASE versions, look for ASE's "export settings" or "export configuration" option in its settings screen.) The JSON must decode to an associative array matching the shape of the `admin_site_enhancements` option.

2. **Paste the JSON** into the **ASE Settings JSON** textarea on the **MainWP > ASE Manager** page (textarea `id="ase_settings_json"`).

3. **Select child sites** using the MainWP site selection box on the page (checkboxes are named `selected_sites[]`). Group selection is disabled, so select individual child sites.

4. Click **Sync Settings** (button `id="mainwp-ase-manager-sync"`).

5. Read the **per-site results** in the results area (div `id="mainwp-ase-manager-results"`). Each selected site is listed with either a success message or an error describing what went wrong.

---

## Testing the sync

Use the following checklist to verify a sync end-to-end:

1. **On a child site**, before syncing, note the current ASE configuration — or clear it (for example, reset ASE to defaults) so the incoming change is obvious.
2. **Run the sync** from the dashboard: paste the JSON, select the child site, and click **Sync Settings**.
3. **Verify the option value** on the child site. Add a temporary debug snippet or use a database/options viewer to confirm that `get_option( 'admin_site_enhancements' )` now matches the pasted JSON.
4. **Confirm the ASE settings UI** on the child reflects the pushed values (open ASE's settings screen and check the features/settings that you changed in the export).
5. **Troubleshoot failures** using the per-site messages:
   - **Site offline / connection error** — ensure the child site is online and reachable from the dashboard, then retry.
   - **MainWP communication error** — confirm MainWP Dashboard is up to date and the child site is connected; the sync relies on MainWP 5.x-era APIs (`MainWP\Dashboard\MainWP_Connect::fetch_url_authed`).
   - **Invalid JSON** — the handler rejects JSON that does not decode to a non-empty settings array; re-export from ASE and re-paste.
   - **No sites selected** — the UI and AJAX handler both reject an empty selection; check at least one child site.

---

## Companion child snippet (optional)

**No companion child snippet is required.** The extension pushes settings through MainWP's built-in `update_option` child action, so the MainWP Child plugin already knows how to apply the option to the child site.

If you want to independently verify the result, you can use the **MainWP Dashboard > Extensions > Custom Dashboard Snippets** feature to run the following plain PHP snippet against a child site. It reads and prints a readable summary of the current `admin_site_enhancements` option:

```php
<?php
$settings = get_option( 'admin_site_enhancements', array() );

if ( empty( $settings ) ) {
	echo 'No admin_site_enhancements option found (or it is empty).';
} else {
	echo 'admin_site_enhancements contains ' . count( $settings ) . ' top-level key(s):' . "\n\n";
	echo wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}
```

**How to run it:** In MainWP Dashboard, go to **Extensions > Custom Dashboard Snippets**, create a new snippet, paste the code above, save it, then execute the snippet against the child site you synced. The output shows the option key count and a pretty-printed JSON representation so you can compare it against the JSON you pushed.

---

## Security & coding standards

- **Nonce verification** — the AJAX endpoint verifies a nonce via MainWP's `MainWP\Dashboard\MainWP_Post_Handler::secure_request( 'mainwp_ase_manager_sync' )`, with a `check_ajax_referer()` fallback ([`ajax_sync_settings()`](includes/class-mainwp-ase-manager.php:168)).
- **Capability check** — both page rendering and the AJAX handler require `current_user_can( 'manage_options' )` ([`render_page()`](includes/class-mainwp-ase-manager.php:117), [`ajax_sync_settings()`](includes/class-mainwp-ase-manager.php:174)).
- **MainWP right check** — the page additionally checks MainWP's `mainwp_current_user_have_right( 'dashboard', 'access_global_dashboard' )` ([`render_page()`](includes/class-mainwp-ase-manager.php:121)).
- **Input sanitization** — the pasted JSON is unslashed but intentionally **not** HTML-sanitized (ASE settings can contain CSS/code with `<` and `>` characters), then decoded with `json_decode()` and validated as a non-empty array; site IDs are cast with `absint()` ([`ajax_sync_settings()`](includes/class-mainwp-ase-manager.php:171)).
- **Output escaping** — server-rendered admin strings are escaped with `esc_html_e()`; AJAX result messages are plain human-readable strings rendered in the browser with `textContent` (no HTML injection).
- **ABSPATH guards** — every PHP file exits if `ABSPATH` is not defined.
- **i18n** — all user-facing strings use the `mainwp-ase-manager` text domain.

---

## Known limitations / notes

- **Only `admin_site_enhancements` is synced.** ASE also stores a secondary key, `admin_site_enhancements_extra`, which is **not** currently synced. It can be added later, or the synced option name can be changed with the `mainwp_ase_manager_option_name` filter.
- **The JSON must be valid and match the ASE option array shape.** The handler only checks that the JSON decodes to an array; it does not validate that the array matches ASE's expected structure, so malformed-but-valid JSON could still produce an unexpected option value on child sites.
- **MainWP 5.x-era APIs required.** The extension uses `MainWP\Dashboard\MainWP_Connect::fetch_url_authed()` and `MainWP\Dashboard\MainWP_DB::get_website_by_id()`, so very old MainWP versions may not be compatible.
