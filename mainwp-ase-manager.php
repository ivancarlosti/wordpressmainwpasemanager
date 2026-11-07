<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * MainWP ASE Manager - Push Admin and Site Enhancements settings to child sites.
 *
 * This plugin allows a MainWP Dashboard admin to paste an ASE (Admin and Site
 * Enhancements) settings JSON export, select child sites, and push those
 * settings to the selected sites by overwriting the `admin_site_enhancements`
 * option on each child site.
 *
 * @package   MainWP_ASE_Manager
 * @category  General
 * @author    Ivan Carlos
 * @copyright 2007-2026 Ivan Carlos Consultoria
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 * @link      https://github.com/ivancarlosti/wordpressmainwpasemanager
 *
 * @wordpress-plugin
 * Plugin Name:       MainWP ASE Manager
 * Plugin URI:        https://github.com/ivancarlosti/wordpressmainwpasemanager
 * Description:       Push Admin and Site Enhancements (ASE) settings to your MainWP child sites from a single dashboard.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      8.2
 * Author:            ivancarlosti
 * Author URI:        https://ivancarlos.com.br
 * Text Domain:       mainwp-ase-manager
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

/*
 * Hard requirement: PHP 8.2+.
 *
 * Kept in sync with the "Requires PHP" plugin header and
 * composer.json. WordPress validates the header on install and activation, but
 * a site can still auto-update the plugin while running an older PHP version,
 * so the plugin disables itself and explains why instead of failing later.
 */
define( 'MAINWP_ASE_MANAGER_MIN_PHP', '8.2' );

if ( version_compare( PHP_VERSION, MAINWP_ASE_MANAGER_MIN_PHP, '<' ) ) {

	/**
	 * Admin notice shown when the site runs an unsupported PHP version.
	 *
	 * @return void
	 */
	function mainwp_ase_manager_php_version_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					// translators: 1: plugin name, 2: required PHP version, 3: current PHP version.
					__( '%1$s requires PHP %2$s or later. This site runs PHP %3$s, so the plugin has been deactivated.', 'mainwp-ase-manager' ),
					'MainWP ASE Manager',
					MAINWP_ASE_MANAGER_MIN_PHP,
					PHP_VERSION
				)
			)
		);
	}

	/**
	 * Deactivate the plugin when the site runs an unsupported PHP version.
	 *
	 * @return void
	 */
	function mainwp_ase_manager_php_version_deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		deactivate_plugins( plugin_basename( __FILE__ ) );

		// Drop the "Plugin activated." notice for a plugin that never started.
		if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			unset( $_GET['activate'] );
		}
	}

	add_action( 'admin_notices', 'mainwp_ase_manager_php_version_notice' );
	add_action( 'admin_init', 'mainwp_ase_manager_php_version_deactivate' );

	return;
}

/**
 * Absolute path to the plugin main file.
 *
 * Used by classes that live in the `includes/` directory to resolve asset
 * URLs and other plugin-relative paths.
 */
define( 'MAINWP_ASE_MANAGER_FILE', __FILE__ );

/**
 * Plugin basename, e.g. mainwp-ase-manager/mainwp-ase-manager.php.
 *
 * Computed once here because `plugin_basename()` reflects the folder the
 * plugin is actually installed in, which is not necessarily the plugin slug
 * (a git clone lands in `wordpressmainwpasemanager/`). The GitHub updater
 * needs it to build the native WordPress upgrade URL.
 */
define( 'MAINWP_ASE_MANAGER_BASENAME', plugin_basename( __FILE__ ) );

/**
 * MainWP_ASE_Manager class.
 *
 * Bootstrap and singleton for the plugin. It detects MainWP Dashboard, wires
 * up the admin menu, assets and AJAX endpoint, and autoloads plugin classes.
 *
 * @package MainWP_ASE_Manager
 * @category General
 */
if ( ! class_exists( 'MainWP_ASE_Manager' ) ) {
class MainWP_ASE_Manager {

	/**
	 * Singleton instance of self.
	 *
	 * @var MainWP_ASE_Manager
	 */
	protected static $_instance = null;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Plugin basename of the MainWP Dashboard plugin.
	 *
	 * @var string
	 */
	const MAINWP_BASENAME = 'mainwp/mainwp.php';

	/**
	 * Constructor.
	 *
	 * Kept minimal: all bootstrap work happens in bootstrap().
	 *
	 * @return void
	 */
	public function __construct() {}

	/**
	 * Create (if needed) and return a singleton of self.
	 *
	 * @return MainWP_ASE_Manager
	 */
	public static function instance() {
		if ( null === self::$_instance ) {
			self::bootstrap();
		}
		return self::$_instance;
	}

	/**
	 * Autoloader for the plugin's own classes.
	 *
	 * Maps snake_case class names to files inside `includes/`:
	 * `MainWP_ASE_Manager_Sync` -> `includes/class-mainwp-ase-manager-sync.php`.
	 *
	 * @param string $class_name Fully-qualified class name.
	 *
	 * @return void
	 */
	public static function autoload( $class_name ) {
		// Only autoload classes that belong to this plugin.
		if ( 0 !== strpos( $class_name, 'MainWP_ASE_Manager_' ) ) {
			return;
		}

		// Explicit map for classes whose file name does not follow the generic convention.
		$class_map = array(
			'MainWP_ASE_Manager_Page' => '/includes/class-mainwp-ase-manager.php',
		);

		if ( isset( $class_map[ $class_name ] ) ) {
			$filepath = __DIR__ . $class_map[ $class_name ];
		} else {
			$filename = strtolower( str_replace( '_', '-', $class_name ) );
			$filepath = __DIR__ . '/includes/class-' . $filename . '.php';
		}

		if ( file_exists( $filepath ) ) {
			require_once $filepath;
		}
	}

	/**
	 * Instantiate the plugin and hook into WordPress.
	 *
	 * The plugin only wires up its functionality when the MainWP Dashboard
	 * plugin is active.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		// Register the plugin's custom autoloader before instantiating classes.
		spl_autoload_register( array( __CLASS__, 'autoload' ) );

		$plugin = new self();
		self::$_instance = $plugin;

		/*
		 * Updates are independent of MainWP Dashboard: the updater, the row
		 * links, the manual check handler and its notice are registered before
		 * the dependency check below, so a deactivated MainWP Dashboard never
		 * hides plugin updates and WordPress cron keeps checking for them.
		 */
		add_action( 'init', array( __CLASS__, 'init_github_updater' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'add_plugin_row_meta' ), 10, 2 );
		add_action( 'admin_post_mainwp_ase_manager_check_updates', array( __CLASS__, 'handle_check_updates' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_update_check_notice' ) );

		/*
		 * The dependency check must not run here. WordPress includes active
		 * plugins in the order stored in the `active_plugins` option, which is
		 * alphabetical, so `mainwp-ase-manager/mainwp-ase-manager.php` is
		 * always included before `mainwp/mainwp.php` ("-" sorts before "/").
		 * At this point MainWP Dashboard has not defined MAINWP_PLUGIN_FILE nor
		 * loaded MainWP\Dashboard\MainWP_System, so an early check always
		 * reports MainWP as missing and shows a false "requires MainWP
		 * Dashboard" notice. `maybe_init()` instead runs on `plugins_loaded`,
		 * after every plugin file has been included.
		 */
		add_action( 'plugins_loaded', array( $plugin, 'maybe_init' ), 99 );
	}

	/**
	 * Wire up the extension once the MainWP Dashboard dependency is known.
	 *
	 * Runs late on `plugins_loaded`, i.e. after every active plugin file has
	 * been included, so MainWP Dashboard has already defined its constants and
	 * loaded its classes regardless of the alphabetical plugin load order.
	 *
	 * @return void
	 */
	public function maybe_init() {
		// Do not initialize unless MainWP Dashboard is active.
		if ( ! $this->is_mainwp_active() ) {
			add_action( 'admin_notices', array( $this, 'admin_notice_mainwp_required' ) );
			return;
		}

		$page = MainWP_ASE_Manager_Page::instance();

		add_action( 'admin_menu', array( $page, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) );
		add_action( 'wp_ajax_mainwp_ase_manager_sync', array( $page, 'ajax_sync_settings' ) );
	}

	/**
	 * Whether the MainWP Dashboard plugin is active.
	 *
	 * Several independent signals are tested because this check runs on
	 * `plugins_loaded`, where MainWP Dashboard may have been included before or
	 * after this plugin depending on its folder name.
	 *
	 * @return bool
	 */
	public function is_mainwp_active() {
		// MainWP Dashboard defines these in mainwp.php, before it boots.
		if ( defined( 'MAINWP_PLUGIN_FILE' ) || defined( 'MAINWP_PLUGIN_DIR' ) || defined( 'MAINWP_PLUGIN_URL' ) ) {
			return true;
		}

		if ( class_exists( 'MainWP\Dashboard\MainWP_System' ) ) {
			return true;
		}

		// MainWP Dashboard ships its helper functions in includes/functions.php.
		if ( function_exists( 'MainWP\Dashboard\mainwp_current_user_can' ) || function_exists( 'mainwp_current_user_can' ) ) {
			return true;
		}

		// Last resort: read what WordPress itself uses to decide what to load.
		return self::is_mainwp_plugin_active();
	}

	/**
	 * Whether the MainWP Dashboard plugin is listed as active in the options.
	 *
	 * Fallback for installs where MainWP Dashboard has not defined any of its
	 * constants, classes or functions yet. `is_plugin_active()` only exists once
	 * wp-admin/includes/plugin.php is loaded (not on front end requests), so the
	 * plugin lists are read directly in that case. `is_plugin_active()` already
	 * covers network activated plugins.
	 *
	 * @return bool
	 */
	protected static function is_mainwp_plugin_active() {
		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( self::MAINWP_BASENAME );
		}

		if ( in_array( self::MAINWP_BASENAME, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
			return isset( $network_active[ self::MAINWP_BASENAME ] );
		}

		return false;
	}

	/**
	 * Admin notice shown when MainWP Dashboard is not active.
	 *
	 * @return void
	 */
	public function admin_notice_mainwp_required() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'MainWP ASE Manager requires the MainWP Dashboard plugin to be installed and activated.', 'mainwp-ase-manager' );
		echo '</p></div>';
	}

	/**
	 * Initialize the GitHub updater.
	 *
	 * Runs on `init` and also during the WordPress cron job so the plugin can
	 * be updated automatically, mirroring the updater bootstrap used by
	 * plugins that rely on a custom update server.
	 *
	 * @return void
	 */
	public static function init_github_updater() {
		$doing_cron = defined( 'DOING_CRON' ) && DOING_CRON;

		if ( ! current_user_can( 'manage_options' ) && ! $doing_cron ) {
			return;
		}

		new MainWP_ASE_Manager_Github_Updater(
			MAINWP_ASE_MANAGER_BASENAME,
			self::VERSION
		);
	}

	/**
	 * Add update related links to the plugin row meta on the Plugins screen.
	 *
	 * "Check for updates" performs a real, forced GitHub Releases lookup — the
	 * result is reported in an admin notice and in the native WordPress update
	 * row — while "Release notes" opens the plugin releases page on GitHub.
	 *
	 * @param array  $plugin_meta An array of the plugin's metadata.
	 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
	 *
	 * @return array
	 */
	public static function add_plugin_row_meta( $plugin_meta, $plugin_file ) {
		if ( strpos( $plugin_file, 'mainwp-ase-manager.php' ) === false ) {
			return $plugin_meta;
		}

		if ( current_user_can( 'update_plugins' ) ) {
			$plugin_meta[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( MainWP_ASE_Manager_Github_Updater::check_url() ),
				esc_html__( 'Check for updates', 'mainwp-ase-manager' )
			);
		}

		$plugin_meta[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			'https://github.com/ivancarlosti/wordpressmainwpasemanager/releases',
			esc_html__( 'Release notes', 'mainwp-ase-manager' )
		);

		return $plugin_meta;
	}

	/**
	 * Handle the "Check for updates" link on the Plugins screen.
	 *
	 * Forces a GitHub Releases lookup, stores the outcome in a short lived
	 * per-user transient and redirects back to the Plugins screen, where
	 * `show_update_check_notice()` reports it.
	 *
	 * @return void
	 */
	public static function handle_check_updates() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to check for plugin updates on this site.', 'mainwp-ase-manager' ) );
		}

		check_admin_referer( 'mainwp_ase_manager_check_updates' );

		if ( ! class_exists( 'MainWP_ASE_Manager_Github_Updater' ) ) {
			wp_die( esc_html__( 'The update checker is not available.', 'mainwp-ase-manager' ) );
		}

		$result = MainWP_ASE_Manager_Github_Updater::check_for_updates();

		set_transient(
			MainWP_ASE_Manager_Github_Updater::RESULT_CACHE_KEY . '_' . get_current_user_id(),
			$result,
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( self_admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Display the result of a manual "Check for updates" request.
	 *
	 * The result is stored in a short lived per-user transient by
	 * `handle_check_updates()` and removed once rendered, so the notice
	 * appears exactly once.
	 *
	 * @return void
	 */
	public static function show_update_check_notice() {
		if ( ! class_exists( 'MainWP_ASE_Manager_Github_Updater' ) ) {
			return;
		}

		$key    = MainWP_ASE_Manager_Github_Updater::RESULT_CACHE_KEY . '_' . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) || empty( $result['state'] ) ) {
			return;
		}

		delete_transient( $key );

		$version   = isset( $result['version'] ) ? $result['version'] : '';
		$installed = isset( $result['installed'] ) ? $result['installed'] : self::VERSION;
		$release   = isset( $result['url'] ) ? $result['url'] : '';

		if ( 'update' === $result['state'] ) {
			$message = sprintf(
				// translators: 1: New version number, 2: Installed version number.
				__( '<strong>MainWP ASE Manager %1$s is available.</strong> You are running version %2$s.', 'mainwp-ase-manager' ),
				esc_html( $version ),
				esc_html( $installed )
			);

			$actions = '';

			if ( current_user_can( 'update_plugins' ) ) {
				$actions .= sprintf(
					'<a class="button button-primary" href="%1$s">%2$s</a>',
					esc_url( MainWP_ASE_Manager_Github_Updater::update_url() ),
					esc_html__( 'Update now', 'mainwp-ase-manager' )
				);
			}

			if ( '' !== $release ) {
				$actions .= sprintf(
					' <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $release ),
					esc_html__( 'Release notes', 'mainwp-ase-manager' )
				);
			}

			printf(
				'<div class="notice notice-warning"><p>%1$s</p><p>%2$s</p></div>',
				wp_kses_post( $message ),
				wp_kses_post( $actions )
			);

			return;
		}

		if ( 'current' === $result['state'] ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						// translators: %s: Installed version number.
						__( 'MainWP ASE Manager is up to date (version %s).', 'mainwp-ase-manager' ),
						$installed
					)
				)
			);

			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The update check could not reach GitHub. Please try again in a few minutes.', 'mainwp-ase-manager' )
		);
	}

	/**
	 * Plugin activation callback.
	 *
	 * No setup is required on activation; the hook is registered for future use.
	 *
	 * @return void
	 */
	public static function activate() {}

	/**
	 * Plugin deactivation callback.
	 *
	 * No cleanup is required on deactivation; the hook is registered for future use.
	 *
	 * @return void
	 */
	public static function deactivate() {}
}
MainWP_ASE_Manager::instance();

register_activation_hook( __FILE__, array( 'MainWP_ASE_Manager', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MainWP_ASE_Manager', 'deactivate' ) );

} // End if ( ! class_exists( 'MainWP_ASE_Manager' ) )
