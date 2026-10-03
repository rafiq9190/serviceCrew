/**
 * Emails screen: SMTP delivery configuration (class-service-crew-smtp.php)
 * plus every email type from the plan's Emails table (see
 * ServiceCrew-Plan-v2.md), each with an on/off toggle and editable
 * subject/body (class-service-crew-emails.php). A template whose trigger
 * doesn't exist yet (assignment/dispatch/PWA/cron emails — Phase 1c/1d) is
 * shown with a "Not sent yet" note instead of hiding it, so this screen is a
 * complete, plan-accurate list from day one even though only some rows fire
 * today. Same sticky-savebar pattern as the Settings/Payments screens; "Save
 * changes" writes both /email-settings and /smtp-settings together since
 * they're one screen to the admin even though they're two REST resources.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'emails' !== root.getAttribute( 'data-view' ) ) {
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

	/* ---- SMTP card ------------------------------------------------------ */

	function buildSmtpCard( smtp ) {
		var card = SCApp.sectionCard(
			'dashicons-admin-site-alt3',
			'Email delivery (SMTP)',
			"Send every ServiceCrew email through your own mailbox instead of the server's default (often unreliable or spam-flagged) mail() — no third-party plugin, this talks straight to your SMTP provider."
		);

		var enabledToggle = SCApp.el( 'input', { type: 'checkbox', class: 'sc-smtp-enabled' } );
		if ( smtp.enabled ) {
			enabledToggle.setAttribute( 'checked', 'checked' );
		}
		card.appendChild( SCApp.el( 'label', { class: 'sc-toggle', style: 'margin-bottom: 12px;' }, [ enabledToggle, SCApp.el( 'span', { text: 'Send through SMTP' } ) ] ) );

		var fromEmailInput = SCApp.el( 'input', { type: 'email', class: 'sc-input', value: smtp.from_email, placeholder: 'bookings@yourbusiness.com' } );
		var fromNameInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: smtp.from_name, placeholder: 'Your Business Name' } );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'From address' } ), fromEmailInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'From name' } ), fromNameInput ] ),
		] ) );

		var hostInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: smtp.host, placeholder: 'smtp.yourprovider.com' } );
		var portInput = SCApp.el( 'input', { type: 'number', class: 'sc-input', value: smtp.port, min: '1', max: '65535' } );
		var encryptionSelect = SCApp.el( 'select', { class: 'sc-input' } );
		[ [ 'tls', 'TLS (STARTTLS, usually port 587)' ], [ 'ssl', 'SSL (usually port 465)' ], [ 'none', 'None' ] ].forEach( function ( pair ) {
			var option = SCApp.el( 'option', { value: pair[ 0 ], text: pair[ 1 ] } );
			if ( pair[ 0 ] === smtp.encryption ) {
				option.setAttribute( 'selected', 'selected' );
			}
			encryptionSelect.appendChild( option );
		} );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'SMTP host' } ), hostInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Port' } ), portInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Encryption' } ), encryptionSelect ] ),
		] ) );

		var usernameInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: smtp.username } );
		var passwordInput = SCApp.el( 'input', { type: 'password', class: 'sc-input', value: smtp.password, autocomplete: 'new-password' } );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Username' } ), usernameInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [
				SCApp.el( 'label', { text: 'Password' } ),
				passwordInput,
				SCApp.el( 'p', { class: 'sc-help', text: 'Never shown in full once saved.' } ),
			] ),
		] ) );

		var testEmailInput = SCApp.el( 'input', { type: 'email', class: 'sc-input', placeholder: 'you@example.com' } );
		var testResult = SCApp.el( 'p', { class: 'sc-help' } );
		var testButton = SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary',
			text: 'Save & send test email',
			onClick: function () {
				if ( ! testEmailInput.value ) {
					testResult.classList.add( 'sc-notice' );
					testResult.textContent = 'Enter an address to send the test to.';
					return;
				}

				testButton.setAttribute( 'disabled', 'disabled' );
				testResult.classList.remove( 'sc-notice' );
				testResult.textContent = 'Saving and sending…';

				SCApp.request( { path: '/service-crew/v1/smtp-settings', method: 'PUT', data: collectSmtp() } ).then( function ( saved ) {
					smtp = saved;
					return SCApp.request( { path: '/service-crew/v1/smtp-settings/test-email', method: 'POST', data: { to: testEmailInput.value } } );
				} ).then( function () {
					testResult.textContent = 'Sent — check ' + testEmailInput.value + '.';
					testButton.removeAttribute( 'disabled' );
				} ).catch( function () {
					testResult.classList.add( 'sc-notice' );
					testResult.textContent = 'Send failed — see the notice above for details.';
					testButton.removeAttribute( 'disabled' );
				} );
			},
		} );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Send a test to' } ), testEmailInput ] ),
		] ) );
		card.appendChild( testButton );
		card.appendChild( testResult );

		function collectSmtp() {
			return {
				enabled: enabledToggle.checked,
				from_email: fromEmailInput.value,
				from_name: fromNameInput.value,
				host: hostInput.value,
				port: Number( portInput.value ) || smtp.port,
				encryption: encryptionSelect.value,
				username: usernameInput.value,
				password: passwordInput.value,
			};
		}

		return { card: card, collect: collectSmtp };
	}

	/* ---- Template cards --------------------------------------------------- */

	function buildTemplateCard( key, template ) {
		var card = SCApp.sectionCard( 'dashicons-email', template.label, template.wired ? '' : "Not sent automatically yet — the feature that triggers this email hasn't been built." );

		var toggle = SCApp.el( 'input', { type: 'checkbox', class: 'sc-email-enabled' } );
		if ( template.enabled ) {
			toggle.setAttribute( 'checked', 'checked' );
		}
		card.appendChild( SCApp.el( 'label', { class: 'sc-toggle', style: 'margin-bottom: 12px;' }, [ toggle, SCApp.el( 'span', { text: 'Enabled' } ) ] ) );

		var subjectInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: template.subject } );
		var bodyInput = SCApp.el( 'textarea', { class: 'sc-input', rows: '6' } );
		bodyInput.value = template.body;

		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Subject' } ), subjectInput ] ) );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Body' } ),
			bodyInput,
			SCApp.el( 'p', { class: 'sc-help', text: 'Placeholders: {customer_name} {customer_email} {booking_id} {service_name} {quote_title} {date} {arrival_window} {amount} {deposit_amount} {site_name}' } ),
		] ) );

		return { key: key, card: card, toggle: toggle, subjectInput: subjectInput, bodyInput: bodyInput };
	}

	function render( emailSettings, smtpSettings ) {
		root.innerHTML = '';

		var page = SCApp.el( 'div', { class: 'sc-settings-page' } );

		var smtpResult = buildSmtpCard( smtpSettings );
		page.appendChild( smtpResult.card );

		page.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			text: 'Every email ServiceCrew can send, in one place. Switch any of them off, or edit the subject/body — placeholders are filled in automatically when it sends.',
		} ) );

		var rows = Object.keys( emailSettings ).map( function ( key ) {
			return buildTemplateCard( key, emailSettings[ key ] );
		} );

		var grid = SCApp.el( 'div', { class: 'sc-settings-grid' } );
		rows.forEach( function ( row ) { grid.appendChild( row.card ); } );
		page.appendChild( grid );
		root.appendChild( page );

		function collectTemplates() {
			var data = {};
			rows.forEach( function ( row ) {
				data[ row.key ] = {
					enabled: row.toggle.checked,
					subject: row.subjectInput.value,
					body: row.bodyInput.value,
				};
			} );
			return data;
		}

		page.addEventListener( 'input', markDirty );
		page.addEventListener( 'change', markDirty );

		ensureSavebar(
			function () {
				Promise.all( [
					SCApp.request( { path: '/service-crew/v1/email-settings', method: 'PUT', data: collectTemplates() } ),
					SCApp.request( { path: '/service-crew/v1/smtp-settings', method: 'PUT', data: smtpResult.collect() } ),
				] ).then( function ( results ) {
					SCApp.toast( 'Email settings saved.' );
					render( results[ 0 ], results[ 1 ] );
				} );
			},
			function () { load(); }
		);
		clearDirty();
	}

	function load() {
		Promise.all( [
			SCApp.request( { path: '/service-crew/v1/email-settings' } ),
			SCApp.request( { path: '/service-crew/v1/smtp-settings' } ),
		] ).then( function ( results ) {
			render( results[ 0 ], results[ 1 ] );
		} );
	}

	load();
} )();
