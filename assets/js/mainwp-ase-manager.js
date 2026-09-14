/**
 * MainWP ASE Manager admin script.
 *
 * Handles the "Sync Settings" button on the extension page: collects the
 * pasted JSON and selected child sites, POSTs them to the AJAX endpoint, and
 * renders the per-site results.
 *
 * @package MainWP_ASE_Manager
 */
( function () {
	'use strict';

	/**
	 * Replace the contents of a container with a plain status message.
	 *
	 * @param {string}  message   Message text.
	 * @param {Element} container Target container element.
	 */
	function renderMessage( message, container ) {
		container.innerHTML = '';

		const paragraph = document.createElement( 'p' );
		paragraph.className = 'mainwp-ase-manager-status';
		paragraph.textContent = message;
		container.appendChild( paragraph );
	}

	/**
	 * Replace the contents of a container with an inline error message.
	 *
	 * @param {string}  message   Error message text.
	 * @param {Element} container Target container element.
	 */
	function renderError( message, container ) {
		container.innerHTML = '';

		const paragraph = document.createElement( 'p' );
		paragraph.className = 'mainwp-ase-manager-error';
		paragraph.textContent = message;
		container.appendChild( paragraph );
	}

	/**
	 * Render the per-site sync results into the container.
	 *
	 * @param {Array}   results   Array of result entries.
	 * @param {Element} container Target container element.
	 */
	function renderResults( results, container ) {
		container.innerHTML = '';

		if ( ! results.length ) {
			renderMessage( window.mainwpAseManager.i18n.noResults, container );
			return;
		}

		const list = document.createElement( 'ul' );
		list.className = 'mainwp-ase-manager-results-list';

		results.forEach( function ( item ) {
			const listItem = document.createElement( 'li' );
			listItem.className = item.success ? 'mainwp-ase-manager-success' : 'mainwp-ase-manager-error';
			listItem.textContent = item.message || item.site_name || 'Unknown site';
			list.appendChild( listItem );
		} );

		container.appendChild( list );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const button = document.getElementById( 'mainwp-ase-manager-sync' );
		const results = document.getElementById( 'mainwp-ase-manager-results' );
		const textarea = document.getElementById( 'ase_settings_json' );

		if ( ! button || ! results || ! textarea || ! window.mainwpAseManager ) {
			return;
		}

		const config = window.mainwpAseManager;
		const originalButtonLabel = button.textContent;

		button.addEventListener( 'click', function () {
			const json = textarea.value;
			const checked = document.querySelectorAll( 'input[name="selected_sites[]"]:checked' );
			const siteIds = [];

			checked.forEach( function ( input ) {
				siteIds.push( input.value );
			} );

			if ( 0 === siteIds.length ) {
				renderError( config.i18n.noSites, results );
				return;
			}

			button.disabled = true;
			button.textContent = config.i18n.syncing;
			renderMessage( config.i18n.syncing, results );

			const params = new URLSearchParams();
			params.append( 'action', 'mainwp_ase_manager_sync' );
			params.append( 'security', config.nonce );
			params.append( 'ase_settings_json', json );

			// Repeated `selected_sites[]` keys: one entry per selected site ID.
			siteIds.forEach( function ( siteId ) {
				params.append( 'selected_sites[]', siteId );
			} );

			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: params.toString()
			} )
				.then( function ( response ) {
					return response.json().catch( function () {
						throw new Error( config.i18n.invalidResponse );
					} );
				} )
				.then( function ( data ) {
					if ( data && true === data.success ) {
						const payload = ( data.data && data.data.results ) ? data.data.results : [];
						renderResults( payload, results );
					} else {
						const message = ( data && data.data && data.data.message ) ? data.data.message : config.i18n.unknownError;
						renderError( message, results );
					}
				} )
				.catch( function ( error ) {
					renderError( error.message || config.i18n.requestFailed, results );
				} )
				.finally( function () {
					button.disabled = false;
					button.textContent = originalButtonLabel;
				} );
		} );
	} );
} )();
