/**
 * Payments screen: Stripe test/live mode keys and connection test. Talks
 * only to /service-crew/v1/payment-settings and
 * /payment-settings/test-connection — see class-service-crew-payments.php.
 * The setup wizard's Payments step (admin/js/app-wizard.js) covers the same
 * fields against the same endpoints for first-run onboarding; this screen is
 * where they're revisited later. Same sticky-savebar pattern as the Settings
 * screen.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'payments' !== root.getAttribute( 'data-view' ) ) {
		return;
	}

	var savebar = null;
	var isDirty = false;

	function markDirty() {
		if ( isDirty ) {
			return;
		}
		isDirty = true;
		if ( savebar ) {
			savebar.classList.add( 'is-visible' );
		}
	}

	function clearDirty() {
		isDirty = false;
		if ( savebar ) {
			savebar.classList.remove( 'is-visible' );
		}
	}

	function ensureSavebar( onSave, onDiscard ) {
		if ( savebar ) {
			return savebar;
		}

		savebar = SCApp.el( 'div', { class: 'sc-savebar' }, [
			SCApp.el( 'span', { class: 'sc-savebar__label', text: 'You have unsaved changes.' } ),
			SCApp.el( 'div', { class: 'sc-savebar__actions' }, [
				SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: 'Discard', onClick: onDiscard } ),
				SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--primary', text: 'Save changes', onClick: onSave } ),
			] ),
		] );

		document.body.appendChild( savebar );
		return savebar;
	}

	/**
	 * A key/secret input plus its help text. Disabled and explained instead of
	 * editable when a wp-config.php constant overrides it — editing it here
	 * would have no effect since the constant always wins server-side.
	 */
	function keyField( label, value, locked, help ) {
		var input = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: value } );
		if ( locked ) {
			input.setAttribute( 'disabled', 'disabled' );
		}

		var field = SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: label } ),
			input,
			SCApp.el( 'p', { class: 'sc-help', text: locked ? 'Defined in wp-config.php — this field is ignored.' : help } ),
		] );

		return { input: input, field: field };
	}

	function render( settings ) {
		root.innerHTML = '';

		var page = SCApp.el( 'div', { class: 'sc-settings-page' } );

		var modeCard = SCApp.sectionCard( 'dashicons-admin-generic', 'Active mode', 'Which set of keys below is used for real checkouts. Keep test mode selected until ready to accept real payments.' );

		var testRadio = SCApp.el( 'input', { type: 'radio', name: 'sc_payment_mode', value: 'test' } );
		var liveRadio = SCApp.el( 'input', { type: 'radio', name: 'sc_payment_mode', value: 'live' } );
		( 'live' === settings.mode ? liveRadio : testRadio ).checked = true;

		modeCard.appendChild( SCApp.el( 'div', { class: 'sc-toggle-row' }, [
			SCApp.el( 'label', { class: 'sc-toggle' }, [ testRadio, SCApp.el( 'span', { text: 'Test mode' } ) ] ),
			SCApp.el( 'label', { class: 'sc-toggle' }, [ liveRadio, SCApp.el( 'span', { text: 'Live mode' } ) ] ),
		] ) );
		page.appendChild( modeCard );

		var grid = SCApp.el( 'div', { class: 'sc-settings-grid' } );

		var testCard = SCApp.sectionCard( 'dashicons-money-alt', 'Test mode keys', 'From the Stripe dashboard, Developers → API keys, in test mode.' );
		var testPub = keyField( 'Publishable key', settings.test_publishable_key, false, 'Safe to expose to the browser.' );
		var testSecret = keyField( 'Secret key', settings.test_secret_key, 'wp-config' === settings.test_secret_key_source, 'Never shown in full once saved.' );
		var testWebhook = keyField( 'Webhook signing secret', settings.test_webhook_secret, 'wp-config' === settings.test_webhook_secret_source, 'From the webhook endpoint created for this site.' );
		testCard.appendChild( testPub.field );
		testCard.appendChild( testSecret.field );
		testCard.appendChild( testWebhook.field );

		var liveCard = SCApp.sectionCard( 'dashicons-lock', 'Live mode keys', 'From the Stripe dashboard, Developers → API keys, in live mode.' );
		var livePub = keyField( 'Publishable key', settings.live_publishable_key, false, 'Safe to expose to the browser.' );
		var liveSecret = keyField( 'Secret key', settings.live_secret_key, 'wp-config' === settings.live_secret_key_source, 'Never shown in full once saved.' );
		var liveWebhook = keyField( 'Webhook signing secret', settings.live_webhook_secret, 'wp-config' === settings.live_webhook_secret_source, 'From the webhook endpoint created for this site.' );
		liveCard.appendChild( livePub.field );
		liveCard.appendChild( liveSecret.field );
		liveCard.appendChild( liveWebhook.field );

		grid.appendChild( testCard );
		grid.appendChild( liveCard );
		page.appendChild( grid );

		var webhookCard = SCApp.sectionCard( 'dashicons-admin-links', 'Webhook', 'Paste this URL into the Stripe Dashboard (Developers → Webhooks → Add endpoint) and enable the events below, for either mode — Stripe payment confirmations arrive here.' );
		webhookCard.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Webhook URL' } ),
			SCApp.buildCopyField( settings.webhook_url ),
		] ) );
		webhookCard.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Events to enable' } ),
			SCApp.el( 'ul', { class: 'sc-event-list' }, ( settings.webhook_events || [] ).map( function ( event ) {
				return SCApp.el( 'li', {}, [ SCApp.el( 'code', { text: event } ) ] );
			} ) ),
		] ) );
		page.appendChild( webhookCard );

		var pagesCard = SCApp.sectionCard( 'dashicons-admin-page', 'Checkout pages', "Where an instant booking's Stripe checkout returns to, if it wasn't opened from a page with the booking widget on it (that page is always preferred when it's known)." );
		var successPageField = SCApp.el( 'div', { class: 'sc-page-dropdown' } );
		successPageField.innerHTML = settings.success_page_dropdown;
		var cancelPageField = SCApp.el( 'div', { class: 'sc-page-dropdown' } );
		cancelPageField.innerHTML = settings.cancel_page_dropdown;

		pagesCard.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Success page' } ), successPageField ] ) );
		pagesCard.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Cancel page' } ), cancelPageField ] ) );
		page.appendChild( pagesCard );

		function collect() {
			return {
				mode: liveRadio.checked ? 'live' : 'test',
				test_publishable_key: testPub.input.value,
				test_secret_key: testSecret.input.value,
				test_webhook_secret: testWebhook.input.value,
				live_publishable_key: livePub.input.value,
				live_secret_key: liveSecret.input.value,
				live_webhook_secret: liveWebhook.input.value,
				success_page_id: Number( ( successPageField.querySelector( 'select' ) || {} ).value || 0 ),
				cancel_page_id: Number( ( cancelPageField.querySelector( 'select' ) || {} ).value || 0 ),
			};
		}

		var testButton = SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: 'Save & test connection' } );
		testButton.addEventListener( 'click', function () {
			testButton.setAttribute( 'disabled', 'disabled' );

			SCApp.request( { path: '/service-crew/v1/payment-settings', method: 'PUT', data: collect() } ).then( function ( saved ) {
				return SCApp.request( { path: '/service-crew/v1/payment-settings/test-connection', method: 'POST', data: { mode: saved.mode } } );
			} ).then( function () {
				SCApp.toast( 'Stripe connected — keys saved and verified.' );
				load();
			} ).catch( function () {
				testButton.removeAttribute( 'disabled' );
			} );
		} );
		page.appendChild( testButton );

		root.appendChild( page );

		page.addEventListener( 'input', markDirty );
		page.addEventListener( 'change', markDirty );

		ensureSavebar(
			function () {
				SCApp.request( { path: '/service-crew/v1/payment-settings', method: 'PUT', data: collect() } ).then( function ( saved ) {
					SCApp.toast( 'Payment settings saved.' );
					render( saved );
				} );
			},
			function () { load(); }
		);
		clearDirty();
	}

	function load() {
		SCApp.request( { path: '/service-crew/v1/payment-settings' } ).then( function ( settings ) {
			render( settings );
		} );
	}

	load();
} )();
