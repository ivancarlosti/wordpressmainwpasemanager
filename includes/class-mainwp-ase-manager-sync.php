<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MainWP_ASE_Manager_Sync class.
 *
 * Pushes the given ASE settings array to one or more MainWP child sites by
 * overwriting the ASE settings option via MainWP's built-in `update_option`
 * child action.
 *
 * @package MainWP_ASE_Manager
 * @category General
 */
if ( ! class_exists( 'MainWP_ASE_Manager_Sync' ) ) {
class MainWP_ASE_Manager_Sync {

	/**
	 * Sync ASE settings to the given child sites.
	 *
	 * @param array $settings Decoded ASE settings array.
	 * @param array $site_ids List of child site IDs.
	 *
	 * @return array[] Per-site result entries.
	 */
	public static function sync( array $settings, array $site_ids ) {
		$option_name = apply_filters( 'mainwp_ase_manager_option_name', 'admin_site_enhancements' );
		$results     = array();

		foreach ( $site_ids as $site_id ) {
			$site_id = absint( $site_id );

			$result = array(
				'site_id'   => $site_id,
				'site_name' => sprintf(
					/* translators: %d: child site ID. */
					__( 'Site #%d', 'mainwp-ase-manager' ),
					$site_id
				),
				'success'   => false,
				'message'   => '',
			);

			if ( ! class_exists( 'MainWP\Dashboard\MainWP_DB' ) ) {
				$result['message'] = __( 'MainWP Dashboard database class is not available.', 'mainwp-ase-manager' );
				$results[]         = $result;
				continue;
			}

			$website = MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );

			if ( empty( $website ) ) {
				$result['message'] = sprintf(
					/* translators: %d: child site ID. */
					__( 'Failed on Site #%d: site not found.', 'mainwp-ase-manager' ),
					$site_id
				);
				$results[]         = $result;
				continue;
			}

			if ( isset( $website->name ) && '' !== $website->name ) {
				$result['site_name'] = $website->name;
			}

			// The built-in `update_option` child action expects a base64-encoded,
			// serialized option value.
			$post_data = array(
				'option_name'  => $option_name,
				'option_value' => base64_encode( maybe_serialize( $settings ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by the MainWP child `update_option` action.
			);

			try {
				if ( ! class_exists( 'MainWP\Dashboard\MainWP_Connect' ) ) {
					throw new Exception( __( 'MainWP Connect class is not available.', 'mainwp-ase-manager' ) );
				}

				$information = MainWP\Dashboard\MainWP_Connect::fetch_url_authed( $website, 'update_option', $post_data );

				$error = self::extract_error( $information );

				if ( '' !== $error ) {
					$result['message'] = sprintf(
						/* translators: 1: child site name, 2: error message. */
						__( 'Failed on %1$s: %2$s', 'mainwp-ase-manager' ),
						$result['site_name'],
						$error
					);
				} else {
					$result['success'] = true;
					$result['message'] = sprintf(
						/* translators: %s: child site name. */
						__( 'Successfully synced to %s.', 'mainwp-ase-manager' ),
						$result['site_name']
					);
				}
			} catch ( Exception $e ) {
				$result['message'] = sprintf(
					/* translators: 1: child site name, 2: error message. */
					__( 'Failed on %1$s: %2$s', 'mainwp-ase-manager' ),
					$result['site_name'],
					$e->getMessage()
				);
			}

			$results[] = $result;
		}

		return $results;
	}

	/**
	 * Extract a human-readable error message from a MainWP child response.
	 *
	 * Returns an empty string when the response unambiguously indicates success;
	 * any other return value is a human-readable failure message.
	 *
	 * @param mixed $information Decoded child response.
	 *
	 * @return string Error message, or an empty string when the response is successful.
	 */
	private static function extract_error( $information ) {
		// `fetch_url_authed()` returns `false` when the request itself fails.
		if ( false === $information ) {
			return __( 'Child site returned a failure for update_option.', 'mainwp-ase-manager' );
		}

		// `null` is not a valid MainWP child response.
		if ( null === $information ) {
			return __( 'Child site returned an empty response.', 'mainwp-ase-manager' );
		}

		// MainWP child responses for built-in actions are typically arrays shaped
		// like `array( 'result' => true )` on success or `array( 'result' => false )`
		// with `error` / `error_message` keys on failure.
		if ( is_array( $information ) ) {
			if ( ! empty( $information['error_message'] ) ) {
				return (string) $information['error_message'];
			}

			if ( ! empty( $information['error'] ) ) {
				return is_string( $information['error'] )
					? $information['error']
					: __( 'Unknown error returned by the child site.', 'mainwp-ase-manager' );
			}

			if ( array_key_exists( 'result', $information ) ) {
				$result = $information['result'];

				// `false`, `0`, and `'0'` are unambiguously failures.
				if ( false === $result || 0 === $result || '0' === $result ) {
					return __( 'The child site reported that the update_option request failed.', 'mainwp-ase-manager' );
				}

				// Any truthy `result` value indicates success.
				if ( $result ) {
					return '';
				}

				return __( 'The child site reported that the update_option request failed.', 'mainwp-ase-manager' );
			}

			// Backward-compatible: errorless arrays without a `result` key are
			// treated as success.
			return '';
		}

		// Scalar responses: `true` means success; any other scalar is a failure.
		if ( true === $information ) {
			return '';
		}

		return __( 'Child site returned an unexpected response.', 'mainwp-ase-manager' );
	}
}
} // End if ( ! class_exists( 'MainWP_ASE_Manager_Sync' ) )
