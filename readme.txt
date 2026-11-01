=== MainWP ASE Manager ===
Contributors: ivancarlosti
Tags: mainwp, admin, site enhancements, ase, settings
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 8.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Push Admin and Site Enhancements (ASE) settings from your MainWP Dashboard to child sites.

== Description ==

MainWP ASE Manager lets you paste an Admin and Site Enhancements (ASE) settings JSON export from a configured staging site, select child sites, and push those settings to every selected site. The extension overwrites the `admin_site_enhancements` option on each child site using MainWP's built-in `update_option` child action, so no companion child plugin is required.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Open **MainWP > ASE Manager**, paste an ASE settings JSON export, select child sites, and click **Sync Settings**.

== Changelog ==

= Unreleased =
* Requires PHP 8.2 or later (previously 8.1). The plugin now deactivates itself with an admin notice when the site runs an older PHP version.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= Unreleased =
The plugin now requires PHP 8.2. On older PHP versions it deactivates itself and shows an admin notice.

= 1.0.0 =
Initial release.
