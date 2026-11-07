# Testing

## Static checks

Every PHP file must lint cleanly:

```bash
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
```

The packaged tree must not look like several plugins. WordPress reads every `*.php` file in the plugin
root **and one directory below it** as a potential plugin, so a second `Plugin Name:` header would be
offered for activation ("The plugin does not have a valid header"):

```bash
sh tests/validate-plugin-layout.sh
```

## Release ZIP check

The only asset WordPress can install is `mainwp-ase-manager.zip`, whose single root folder is the
plugin folder:

```bash
unzip -Z1 mainwp-ase-manager.zip | cut -d/ -f1 | sort -u
# exactly one line: mainwp-ase-manager
```

Any other result (several lines) means the archive was built from the repository root and will fail
with *"The package contains more than one folder."*

## Forced update check (no WordPress needed)

`includes/class-mainwp-ase-manager-github-updater.php` only needs a handful of WordPress functions, so
the asset selection logic can be exercised with a stub harness in `/tmp`:

```php
<?php
define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MAINWP_ASE_MANAGER_BASENAME', 'mainwp-ase-manager/mainwp-ase-manager.php' );

$transients = array();
function get_site_transient( $key ) { global $transients; return isset( $transients[ $key ] ) ? $transients[ $key ] : false; }
function set_site_transient( $key, $value, $ttl = 0 ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_site_transient( $key ) { global $transients; unset( $transients[ $key ] ); return true; }
function wp_remote_get( $url, $args = array() ) { return $GLOBALS['http_response']; }
function is_wp_error( $value ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return 200; }
function wp_remote_retrieve_body( $response ) { return $response; }

class MainWP_ASE_Manager { const VERSION = '2.0.0'; }

require '/path/to/plugin/includes/class-mainwp-ase-manager-github-updater.php';

// Feed the real asset list captured from the GitHub API.
$assets = json_decode( '[{"name":"wordpressmainwpasemanager-2.0.0.zip","browser_download_url":"https://github.com/ivancarlosti/wordpressmainwpasemanager/releases/download/v2.0.0/wordpressmainwpasemanager-2.0.0.zip"},{"name":"mainwp-ase-manager.zip","browser_download_url":"https://github.com/ivancarlosti/wordpressmainwpasemanager/releases/download/v2.0.0/mainwp-ase-manager.zip"}]' );
$GLOBALS['http_response'] = json_encode( array( 'tag_name' => 'v2.0.1', 'html_url' => 'https://github.com/ivancarlosti/wordpressmainwpasemanager/releases/tag/v2.0.1', 'assets' => $assets ) );

$result = MainWP_ASE_Manager_Github_Updater::check_for_updates();
// state: update, version: 2.0.1, installed: 2.0.0
```

`get_package_url()` must return the `…/download/v2.0.1/mainwp-ase-manager.zip` URL even though
`wordpressmainwpasemanager-2.0.0.zip` is listed first — that ordering is what made "Update now"
intermittently fail.

## Manual verification in WordPress

1. `wp plugin list --name=mainwp-ase-manager` and note the installed version.
2. Deactivate the **MainWP Dashboard** plugin.
3. **Plugins → Installed Plugins** → **Check for updates**. The link must still be present and must
   report the outcome — this is the regression the updater-before-dependency-check wiring prevents.
4. When a newer tag exists, click **Update now** → the native upgrader runs and ends on the plugin
   page with the new version.
5. Reactivate MainWP Dashboard, open **MainWP → ASE Manager** and confirm the footer renders the
   version, the update state and both links.
6. Open **Dashboard → Updates** and confirm the plugin is gone from the list once updated.
