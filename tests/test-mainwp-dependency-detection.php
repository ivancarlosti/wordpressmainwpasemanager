<?php
/**
 * Dependency detection harness for MainWP_ASE_Manager.
 *
 * Reproduces the WordPress plugin load order that made the plugin show
 * "MainWP ASE Manager requires the MainWP Dashboard plugin to be installed and
 * activated." while MainWP Dashboard 6.4.1 was active:
 *
 *   WordPress includes active plugins in the order of the `active_plugins`
 *   option, which is alphabetical. `mainwp-ase-manager/mainwp-ase-manager.php`
 *   sorts before `mainwp/mainwp.php` ("-" is 0x2D, "/" is 0x2F), so the
 *   dependency check used to run before MainWP had defined MAINWP_PLUGIN_FILE
 *   or loaded MainWP\Dashboard\MainWP_System.
 *
 * The harness stubs the handful of WordPress functions used while the plugin
 * file is included and while `plugins_loaded` and `admin_notices` fire, then
 * asserts which hooks each scenario registers.
 *
 * Usage:
 *   php tests/test-mainwp-dependency-detection.php            # all scenarios
 *   php tests/test-mainwp-dependency-detection.php <scenario> # single scenario
 *
 * @package MainWP_ASE_Manager
 */

$scenario = isset( $argv[1] ) ? $argv[1] : '';

if ( '' === $scenario ) {
	$scenarios = array(
		'mainwp-loaded-after-us',
		'mainwp-active-class-only',
		'mainwp-active-option-only',
		'mainwp-network-active',
		'mainwp-inactive',
	);

	$failed = 0;
	foreach ( $scenarios as $name ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $name );
		$code    = 0;
		passthru( $command, $code );
		if ( 0 !== $code ) {
			$failed++;
		}
	}

	if ( $failed > 0 ) {
		printf( "\n❌ %d of %d scenario(s) failed.\n", $failed, count( $scenarios ) );
		exit( 1 );
	}

	printf( "\n✅ All %d dependency detection scenarios passed.\n", count( $scenarios ) );
	exit( 0 );
}

// ---------------------------------------------------------------------------
// WordPress stubs.
// ---------------------------------------------------------------------------

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['test_hooks']         = array();
$GLOBALS['test_options']       = array( 'active_plugins' => array() );
$GLOBALS['test_site_options']  = array();
$GLOBALS['test_multisite']     = false;
$GLOBALS['test_failures']      = array();

/**
 * Record a hook registration.
 *
 * @param string $hook     Hook name.
 * @param mixed  $callback Callback.
 * @return void
 */
function test_register_hook( $hook, $callback ) {
	$GLOBALS['test_hooks'][ $hook ][] = $callback;
}

/**
 * Stub of add_action().
 *
 * @param string $hook          Hook name.
 * @param mixed  $callback      Callback.
 * @param int    $priority      Priority (ignored by the harness).
 * @param int    $accepted_args Accepted args (ignored by the harness).
 * @return bool
 */
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	test_register_hook( $hook, $callback );
	return true;
}

/**
 * Stub of add_filter().
 *
 * @param string $hook          Hook name.
 * @param mixed  $callback      Callback.
 * @param int    $priority      Priority (ignored by the harness).
 * @param int    $accepted_args Accepted args (ignored by the harness).
 * @return bool
 */
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	test_register_hook( $hook, $callback );
	return true;
}

/**
 * Stub of register_activation_hook().
 *
 * @param string $file     Plugin file.
 * @param mixed  $callback Callback.
 * @return void
 */
function register_activation_hook( $file, $callback ) {
	test_register_hook( 'activate_' . basename( $file ), $callback );
}

/**
 * Stub of register_deactivation_hook().
 *
 * @param string $file     Plugin file.
 * @param mixed  $callback Callback.
 * @return void
 */
function register_deactivation_hook( $file, $callback ) {
	test_register_hook( 'deactivate_' . basename( $file ), $callback );
}

/**
 * Stub of plugin_basename().
 *
 * @param string $file Absolute plugin file path.
 * @return string
 */
function plugin_basename( $file ) {
	return 'mainwp-ase-manager/' . basename( $file );
}

/**
 * Stub of plugin_dir_url().
 *
 * @param string $file Absolute plugin file path.
 * @return string
 */
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/mainwp-ase-manager/';
}

/**
 * Stub of get_option().
 *
 * @param string $name    Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default;
}

/**
 * Stub of get_site_option().
 *
 * @param string $name    Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_site_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['test_site_options'] ) ? $GLOBALS['test_site_options'][ $name ] : $default;
}

/**
 * Stub of is_multisite().
 *
 * @return bool
 */
function is_multisite() {
	return (bool) $GLOBALS['test_multisite'];
}

/**
 * Run every callback registered for a hook.
 *
 * @param string $hook Hook name.
 * @return void
 */
function test_do_hook( $hook ) {
	if ( empty( $GLOBALS['test_hooks'][ $hook ] ) ) {
		return;
	}

	foreach ( $GLOBALS['test_hooks'][ $hook ] as $callback ) {
		call_user_func( $callback );
	}
}

/**
 * Whether a hook has a callback that is the given method on the given class.
 *
 * @param string $hook      Hook name.
 * @param string $class     Class the method belongs to.
 * @param string $method    Method name.
 * @return bool
 */
function test_hook_has_method( $hook, $class, $method ) {
	if ( empty( $GLOBALS['test_hooks'][ $hook ] ) ) {
		return false;
	}

	foreach ( $GLOBALS['test_hooks'][ $hook ] as $callback ) {
		if ( is_array( $callback ) && is_object( $callback[0] ) && $callback[0] instanceof $class && $method === $callback[1] ) {
			return true;
		}
	}

	return false;
}

/**
 * Record an assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure description.
 * @return void
 */
function test_assert( $condition, $message ) {
	if ( ! $condition ) {
		$GLOBALS['test_failures'][] = $message;
	}
}

// ---------------------------------------------------------------------------
// Scenario setup.
// ---------------------------------------------------------------------------

$ours = 'mainwp-ase-manager/mainwp-ase-manager.php';

switch ( $scenario ) {
	case 'mainwp-loaded-after-us':
		// MainWP Dashboard is active and sorts after this plugin, so it has not
		// been included yet when this plugin file runs. This is the exact
		// situation that produced the false "requires MainWP Dashboard" notice.
		$GLOBALS['test_options']['active_plugins'] = array( $ours, 'mainwp/mainwp.php' );
		break;

	case 'mainwp-active-class-only':
		// MainWP booted: its namespaced system class exists, no constant set.
		eval( 'namespace MainWP\Dashboard; class MainWP_System {}' );
		$GLOBALS['test_options']['active_plugins'] = array( $ours );
		break;

	case 'mainwp-active-option-only':
		// Only the stored active plugins list knows about MainWP Dashboard:
		// no constants, classes or functions of it are available yet.
		$GLOBALS['test_options']['active_plugins'] = array( $ours, 'mainwp/mainwp.php' );

		/**
		 * Stub of is_plugin_active() as loaded by wp-admin/includes/plugin.php.
		 *
		 * @param string $plugin Plugin basename.
		 * @return bool
		 */
		function is_plugin_active( $plugin ) {
			return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
		}
		break;

	case 'mainwp-network-active':
		// Multisite network activation: only active_sitewide_plugins knows it.
		$GLOBALS['test_multisite']                               = true;
		$GLOBALS['test_options']['active_plugins']               = array( $ours );
		$GLOBALS['test_site_options']['active_sitewide_plugins'] = array( 'mainwp/mainwp.php' => 1700000000 );
		break;

	case 'mainwp-inactive':
		// MainWP Dashboard is not installed/activated at all.
		$GLOBALS['test_options']['active_plugins'] = array( $ours );
		break;

	default:
		fwrite( STDERR, sprintf( "Unknown scenario: %s\n", $scenario ) );
		exit( 2 );
}

require __DIR__ . '/../mainwp-ase-manager.php';

// The update hooks must exist regardless of the dependency state.
test_assert( ! empty( $GLOBALS['test_hooks']['init'] ), 'The GitHub updater init hook is not registered.' );
test_assert( ! empty( $GLOBALS['test_hooks']['plugin_row_meta'] ), 'The plugin row meta filter is not registered.' );
test_assert( ! empty( $GLOBALS['test_hooks']['admin_post_mainwp_ase_manager_check_updates'] ), 'The manual update check handler is not registered.' );

if ( 'mainwp-loaded-after-us' === $scenario ) {
	// Regression guard: the dependency check must NOT run during inclusion,
	// otherwise MainWP Dashboard looks missing only because it loads later.
	test_assert( empty( $GLOBALS['test_hooks']['admin_menu'] ), 'admin_menu was hooked before plugins_loaded.' );
	test_assert(
		! test_hook_has_method( 'admin_notices', 'MainWP_ASE_Manager', 'admin_notice_mainwp_required' ),
		'The "requires MainWP Dashboard" notice was registered during plugin inclusion (false negative).'
	);

	// MainWP Dashboard 6.4.1 mainwp.php runs now: MAINWP_PLUGIN_FILE is defined.
	define( 'MAINWP_PLUGIN_FILE', '/srv/wp-content/plugins/mainwp/mainwp.php' );
}

test_do_hook( 'plugins_loaded' );

if ( 'mainwp-inactive' !== $scenario ) {
	test_assert(
		test_hook_has_method( 'admin_menu', 'MainWP_ASE_Manager_Page', 'register_admin_menu' ),
		'The ASE Manager submenu is not registered even though MainWP Dashboard is active.'
	);
	test_assert(
		test_hook_has_method( 'wp_ajax_mainwp_ase_manager_sync', 'MainWP_ASE_Manager_Page', 'ajax_sync_settings' ),
		'The sync AJAX handler is not registered even though MainWP Dashboard is active.'
	);
	test_assert(
		! test_hook_has_method( 'admin_notices', 'MainWP_ASE_Manager', 'admin_notice_mainwp_required' ),
		'The "requires MainWP Dashboard" notice is shown even though MainWP Dashboard is active.'
	);
} else {
	test_assert(
		test_hook_has_method( 'admin_notices', 'MainWP_ASE_Manager', 'admin_notice_mainwp_required' ),
		'The "requires MainWP Dashboard" notice is missing while MainWP Dashboard is inactive.'
	);
	test_assert( empty( $GLOBALS['test_hooks']['admin_menu'] ), 'admin_menu was hooked while MainWP Dashboard is inactive.' );
}

if ( ! empty( $GLOBALS['test_failures'] ) ) {
	foreach ( $GLOBALS['test_failures'] as $failure ) {
		fwrite( STDERR, sprintf( "::error::%s\n", $failure ) );
	}
	fwrite( STDERR, sprintf( "❌ Scenario failed: %s\n", $scenario ) );
	exit( 1 );
}

printf( "✅ Scenario passed: %s\n", $scenario );
exit( 0 );
