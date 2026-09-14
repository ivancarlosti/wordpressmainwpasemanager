<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MainWP_ASE_Manager_Page class.
 *
 * Handles the extension admin page rendering, asset enqueueing and the AJAX
 * endpoint that pushes ASE settings to the selected child sites.
 *
 * @package MainWP_ASE_Manager
 * @category General
 */
if ( ! class_exists( 'MainWP_ASE_Manager_Page' ) ) {
class MainWP_ASE_Manager_Page {

	/**
	 * Singleton instance of self.
	 *
	 * @var MainWP_ASE_Manager_Page
	 */
	protected static $_instance = null;

	/**
	 * Create (if needed) and return a singleton of self.
	 *
	 * @return MainWP_ASE_Manager_Page
	 */
	public static function instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Register the extension sub-page under the MainWP menu.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'mainwp_tab',
			__( 'ASE Manager', 'mainwp-ase-manager' ),
			__( 'ASE Manager', 'mainwp-ase-manager' ),
			'manage_options',
			'mainwp-ase-manager',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the extension CSS and JS, only on this extension's page.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'mainwp-ase-manager' !== $page ) {
			return;
		}

		$base_url = plugin_dir_url( MAINWP_ASE_MANAGER_FILE );

		wp_enqueue_style(
			'mainwp-ase-manager',
			$base_url . 'assets/css/mainwp-ase-manager.css',
			array(),
			MainWP_ASE_Manager::VERSION
		);

		wp_enqueue_script(
			'mainwp-ase-manager',
			$base_url . 'assets/js/mainwp-ase-manager.js',
			array(),
			MainWP_ASE_Manager::VERSION,
			true
		);

		wp_localize_script(
			'mainwp-ase-manager',
			'mainwpAseManager',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mainwp_ase_manager_sync' ),
				'i18n'    => array(
					'syncing'         => __( 'Syncing...', 'mainwp-ase-manager' ),
					'noSites'         => __( 'Please select at least one child site.', 'mainwp-ase-manager' ),
					'noResults'       => __( 'No results returned.', 'mainwp-ase-manager' ),
					'invalidResponse' => __( 'Invalid JSON response from server.', 'mainwp-ase-manager' ),
					'unknownError'    => __( 'An unknown error occurred.', 'mainwp-ase-manager' ),
					'requestFailed'   => __( 'Request failed. Please try again.', 'mainwp-ase-manager' ),
				),
			)
		);
	}

	/**
	 * Render the extension admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( function_exists( 'MainWP\Dashboard\mainwp_current_user_have_right' )
			&& ! MainWP\Dashboard\mainwp_current_user_have_right( 'dashboard', 'access_global_dashboard' ) ) {
			return;
		}

		// The MainWP site selection box renders checkboxes named `selected_sites[]`
		// whose values are the selected child site IDs. Group selection is disabled
		// below, so only individual child sites can be selected.
		$selected_websites = array();
		$selected_groups   = array();
		?>
		<div class="wrap mainwp-ase-manager-wrap">
			<h1><?php esc_html_e( 'MainWP ASE Manager', 'mainwp-ase-manager' ); ?></h1>
			<p><?php esc_html_e( 'Paste an Admin and Site Enhancements (ASE) settings JSON export below, select the child sites to update, then click Sync Settings to overwrite the ASE settings option on each selected site.', 'mainwp-ase-manager' ); ?></p>

			<form id="mainwp-ase-manager-form" method="post" action="">

				<p>
					<label for="ase_settings_json"><strong><?php esc_html_e( 'ASE Settings JSON', 'mainwp-ase-manager' ); ?></strong></label><br>
					<textarea name="ase_settings_json" id="ase_settings_json" rows="15" class="large-text code"></textarea>
				</p>

				<h2><?php esc_html_e( 'Select Child Sites', 'mainwp-ase-manager' ); ?></h2>
				<?php
				do_action( 'mainwp_select_sites_box', '', 'checkbox', false, true, '', '', $selected_websites, $selected_groups, true, array() );
				?>

				<p class="mainwp-ase-manager-actions">
					<button type="button" id="mainwp-ase-manager-sync" class="button button-primary"><?php esc_html_e( 'Sync Settings', 'mainwp-ase-manager' ); ?></button>
				</p>

				<div id="mainwp-ase-manager-results" class="mainwp-ase-manager-results"></div>
			</form>
		</div>
		<?php
	}

	/**
	 * AJAX handler for `wp_ajax_mainwp_ase_manager_sync`.
	 *
	 * Validates the request, decodes the pasted JSON, then delegates the
	 * per-site sync to MainWP_ASE_Manager_Sync.
	 *
	 * @return void
	 */
	public function ajax_sync_settings() {
		// Secure the request against CSRF using MainWP's post handler when available.
		if ( class_exists( 'MainWP\Dashboard\MainWP_Post_Handler' ) ) {
			MainWP\Dashboard\MainWP_Post_Handler::instance()->secure_request( 'mainwp_ase_manager_sync' );
		} elseif ( ! check_ajax_referer( 'mainwp_ase_manager_sync', 'security', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'mainwp-ase-manager' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'mainwp-ase-manager' ) ) );
		}

		// Do not HTML-sanitize the raw JSON: ASE stores custom CSS and code
		// snippets that may contain `<`, `>`, and other HTML-like characters,
		// which sanitize_textarea_field() would strip or corrupt before decoding.
		$json = isset( $_POST['ase_settings_json'] ) ? wp_unslash( $_POST['ase_settings_json'] ) : '';
		$settings = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $settings ) || empty( $settings ) ) {
			wp_send_json_error( array( 'message' => __( 'The provided ASE settings are not valid JSON or do not decode to a settings array.', 'mainwp-ase-manager' ) ) );
		}

		$site_ids = isset( $_POST['selected_sites'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['selected_sites'] ) ) : array();
		$site_ids = array_filter( $site_ids );

		if ( empty( $site_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No sites selected. Please select at least one child site.', 'mainwp-ase-manager' ) ) );
		}

		$results = MainWP_ASE_Manager_Sync::sync( $settings, $site_ids );

		wp_send_json_success( array( 'results' => $results ) );
	}
}
} // End if ( ! class_exists( 'MainWP_ASE_Manager_Page' ) )
