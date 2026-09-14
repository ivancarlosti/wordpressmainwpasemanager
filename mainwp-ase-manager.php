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
 * Requires PHP:      8.1
 * Author:            ivancarlosti
 * Author URI:        https://ivancarlos.com.br
 * Text Domain:       mainwp-ase-manager
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Absolute path to the plugin main file.
 *
 * Used by classes that live in the `includes/` directory to resolve asset
 * URLs and other plugin-relative paths.
 */
define( 'MAINWP_ASE_MANAGER_FILE', __FILE__ );

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

		// Do not initialize unless MainWP Dashboard is active.
		if ( ! $plugin->is_mainwp_active() ) {
			add_action( 'admin_notices', array( $plugin, 'admin_notice_mainwp_required' ) );
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
	 * @return bool
	 */
	public function is_mainwp_active() {
		if ( defined( 'MAINWP_PLUGIN_FILE' ) ) {
			return true;
		}

		if ( class_exists( 'MainWP\Dashboard\MainWP_System' ) ) {
			return true;
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
