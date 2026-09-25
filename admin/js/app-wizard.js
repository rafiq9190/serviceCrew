/**
 * First-run setup wizard: business basics, scheduling, the mandatory
 * address-lookup consent + test, a first service, an optional first crew
 * member, and a finish checklist. See class-service-crew-wizard.php for why
 * there is no "Payments" step yet (Phase 1b-1) and why steps 2 and 5 talk
 * straight to the existing scheduling-settings/services endpoints instead of
 * a wizard-owned copy of them.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'wizard' !== root.getAttribute( 'data-view' ) ) {
		return;
	}

	var isModal = Boolean( window.SC_WIZARD && window.SC_WIZARD.modal );

	function closeModal() {
		var overlay = document.getElementById( 'sc-wizard-modal-overlay' );
		if ( overlay && overlay.parentNode ) {
			overlay.parentNode.removeChild( overlay );
		}
	}

	/**
	 * Adds the × close control and Esc-to-close, once, outside of #sc-app-root
	 * so it survives every step's root.innerHTML = '' re-render. Only in
	 * modal mode — the full page (reopened from the ServiceCrew menu) has
	 * nothing to "close" back to.
	 */
	function setupModalChrome() {
		if ( ! isModal || ! root.parentNode ) {
			return;
		}

		root.parentNode.insertBefore(
			SCApp.el( 'button', { type: 'button', class: 'sc-wizard-modal-close', 'aria-label': 'Close', text: '×', onClick: closeModal } ),
			root
		);

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				closeModal();
			}
		} );
	}

	var STEPS = [
		{ key: 'basics', label: 'Business basics' },
		{ key: 'scheduling', label: 'Scheduling' },
		{ key: 'address', label: 'Address lookup' },
		{ key: 'first_service', label: 'First service' },
		{ key: 'first_crew', label: 'First crew member' },
		{ key: 'finish', label: 'Finish' },
	];

	var state = {
		stepIndex: 0,
		wizardState: { current_step: STEPS[ 0 ].key, completed_steps: [], consent_accepted: false, address_test_passed: false, dismissed: false },
		basics: null,
		schedulingSettings: null,
	};

	function stepIndexForKey( key ) {
		var index = -1;
		STEPS.forEach( function ( step, i ) {
			if ( step.key === key ) {
				index = i;
			}
		} );
		return -1 === index ? 0 : index;
	}

	function saveWizardState( patch ) {
		state.wizardState = Object.assign( {}, state.wizardState, patch );
		return SCApp.request( { path: '/service-crew/v1/wizard/state', method: 'PUT', data: state.wizardState } ).then( function ( saved ) {
			state.wizardState = saved;
		} );
	}

	function markStepComplete( key ) {
		var completed = state.wizardState.completed_steps.slice();
		if ( -1 === completed.indexOf( key ) ) {
			completed.push( key );
		}
		return completed;
	}

	function goToStep( index ) {
		state.stepIndex = Math.max( 0, Math.min( STEPS.length - 1, index ) );
		saveWizardState( { current_step: STEPS[ state.stepIndex ].key } );
		render();
	}

	function renderProgress() {
		var list = SCApp.el( 'ol', { class: 'sc-wizard-progress' } );

		STEPS.forEach( function ( step, index ) {
			var isDone = -1 !== state.wizardState.completed_steps.indexOf( step.key );
			var isCurrent = index === state.stepIndex;
			var classes = 'sc-wizard-progress__step';
			if ( isCurrent ) {
				classes += ' is-current';
			}
			if ( isDone ) {
				classes += ' is-done';
			}

			list.appendChild( SCApp.el( 'li', { class: classes }, [
				SCApp.el( 'span', { class: 'sc-wizard-progress__dot', text: isDone ? '✓' : String( index + 1 ) } ),
				SCApp.el( 'span', { class: 'sc-wizard-progress__label', text: step.label } ),
			] ) );
		} );

		return list;
	}

	function renderShell( bodyEl, onBack, onNext, nextLabel, nextDisabled ) {
		root.innerHTML = '';

		var page = SCApp.el( 'div', { class: 'sc-wizard-page' } );

		page.appendChild( SCApp.el( 'div', { class: 'sc-wizard-header' }, [
			SCApp.el( 'span', { class: 'dashicons dashicons-groups sc-wizard-header__icon', 'aria-hidden': 'true' } ),
			SCApp.el( 'div', {}, [
				SCApp.el( 'h1', { text: 'ServiceCrew setup' } ),
				SCApp.el( 'p', { class: 'sc-help', text: 'A few steps to get bookings running. You can reopen this any time from the ServiceCrew menu.' } ),
			] ),
		] ) );

		page.appendChild( renderProgress() );

		var card = SCApp.el( 'div', { class: 'sc-app-panel sc-wizard-card' }, [ bodyEl ] );
		page.appendChild( card );

		var actions = SCApp.el( 'div', { class: 'sc-wizard-actions' } );

		if ( onBack ) {
			actions.appendChild( SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: 'Back', onClick: onBack } ) );
		} else {
			actions.appendChild( SCApp.el( 'span', {} ) );
		}

		if ( onNext ) {
			var nextBtn = SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--primary', text: nextLabel || 'Next', onClick: onNext } );
			if ( nextDisabled ) {
				nextBtn.setAttribute( 'disabled', 'disabled' );
			}
			actions.appendChild( nextBtn );
		}

		page.appendChild( actions );
		root.appendChild( page );
	}

	// ---- Step 1: business basics -------------------------------------------

	function renderBasicsStep() {
		var basics = state.basics;

		var body = SCApp.el( 'div', {} );
		body.appendChild( SCApp.el( 'h2', { text: 'Business basics' } ) );

		var nameInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: basics.business_name } );
		body.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Business name' } ), nameInput ] ) );

		var timezoneInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: basics.timezone } );
		var currencyInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: basics.currency, maxlength: '3', style: 'text-transform:uppercase;' } );

		body.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Timezone' } ), timezoneInput, SCApp.el( 'p', { class: 'sc-help', text: 'A PHP timezone identifier, e.g. America/New_York.' } ) ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Currency code' } ), currencyInput, SCApp.el( 'p', { class: 'sc-help', text: 'Three letters, e.g. USD.' } ) ] ),
		] ) );

		var emailInput = SCApp.el( 'input', { type: 'email', class: 'sc-input', value: basics.admin_alert_email } );
		body.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Admin alert email' } ), emailInput, SCApp.el( 'p', { class: 'sc-help', text: 'Where new-booking and dispatch alerts are sent.' } ) ] ) );

		var colorInput = SCApp.el( 'input', { type: 'text', class: 'sc-input sc-wizard-color', value: basics.brand_color } );
		body.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Brand colour' } ), colorInput ] ) );

		var logoId = basics.logo_id || 0;
		var logoPreview = SCApp.el( 'div', { class: 'sc-wizard-logo-preview' } );
		var logoButton = SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: logoId ? 'Change logo' : 'Choose logo' } );
		var logoRemove = SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--ghost' + ( logoId ? '' : ' sc-hidden' ), text: 'Remove' } );

		function refreshLogoPreview() {
			logoPreview.innerHTML = '';
			if ( logoId && window.wp && window.wp.media ) {
				var attachment = window.wp.media.attachment( logoId );
				attachment.fetch().then( function () {
					var url = attachment.get( 'sizes' ) && attachment.get( 'sizes' ).thumbnail ? attachment.get( 'sizes' ).thumbnail.url : attachment.get( 'url' );
					logoPreview.appendChild( SCApp.el( 'img', { src: url, class: 'sc-wizard-logo-preview__img' } ) );
				} );
			}
			logoRemove.classList.toggle( 'sc-hidden', ! logoId );
		}

		logoButton.addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			var frame = window.wp.media( { title: 'Choose a logo', multiple: false, library: { type: 'image' } } );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				logoId = attachment.id;
				refreshLogoPreview();
			} );
			frame.open();
		} );

		logoRemove.addEventListener( 'click', function () {
			logoId = 0;
			refreshLogoPreview();
		} );

		body.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Logo' } ),
			logoPreview,
			SCApp.el( 'div', { class: 'sc-wizard-logo-actions' }, [ logoButton, logoRemove ] ),
		] ) );

		refreshLogoPreview();

		function collect() {
			return {
				business_name: nameInput.value,
				timezone: timezoneInput.value,
				currency: currencyInput.value,
				admin_alert_email: emailInput.value,
				brand_color: colorInput.value,
				logo_id: logoId,
			};
		}

		renderShell(
			body,
			null,
			function () {
				SCApp.request( { path: '/service-crew/v1/wizard/business-basics', method: 'PUT', data: collect() } ).then( function ( saved ) {
					state.basics = saved;
					saveWizardState( { completed_steps: markStepComplete( 'basics' ) } ).then( function () {
						goToStep( state.stepIndex + 1 );
					} );
				} );
			},
			'Next'
		);

		if ( window.jQuery && window.jQuery.fn.wpColorPicker ) {
			window.jQuery( colorInput ).wpColorPicker();
		}
	}

	// ---- Step 2: scheduling (reuses the Settings screen's own building blocks) --

	function renderSchedulingStep() {
		var settings = state.schedulingSettings;
		var body = SCApp.el( 'div', {} );

		body.appendChild( SCApp.el( 'h2', { text: 'Scheduling' } ) );
		body.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'Sensible defaults are already filled in — adjust anything that does not match how the business runs. The rest (tax, minimum deposit) lives in Settings, reachable any time from the ServiceCrew menu.' } ) );

		var cards = {};
		cards.hours = SCApp.buildBusinessHoursCard( settings.business_hours );
		body.appendChild( cards.hours );

		var grid = SCApp.el( 'div', { class: 'sc-settings-grid' } );
		cards.windows = SCApp.buildArrivalWindowsCard( settings.arrival_windows );
		cards.holidays = SCApp.buildHolidaysCard( settings.holidays );
		cards.travel = SCApp.buildTravelOvertimeCard( settings.travel_buffer_minutes, settings.overtime );
		cards.timers = SCApp.buildTimersCard( settings.timers );
		grid.appendChild( cards.windows );
		grid.appendChild( cards.holidays );
		grid.appendChild( cards.travel );
		grid.appendChild( cards.timers );
		body.appendChild( grid );

		function collect() {
			var businessHours = {};
			body.querySelectorAll( '.sc-hours-row' ).forEach( function ( row ) {
				businessHours[ row.getAttribute( 'data-day' ) ] = {
					enabled: row.querySelector( '.sc-hours-enabled' ).checked,
					start: row.querySelector( '.sc-hours-start' ).value,
					end: row.querySelector( '.sc-hours-end' ).value,
				};
			} );

			var arrivalWindows = Array.prototype.slice.call( cards.windows.list.querySelectorAll( '.sc-repeater-row' ) ).map( function ( row ) {
				return { label: row.querySelector( '.sc-window-label' ).value, start: row.querySelector( '.sc-window-start' ).value, end: row.querySelector( '.sc-window-end' ).value };
			} );

			var holidays = Array.prototype.slice.call( cards.holidays.list.querySelectorAll( '.sc-repeater-row' ) ).map( function ( row ) {
				return { date: row.querySelector( '.sc-holiday-date' ).value, label: row.querySelector( '.sc-holiday-label' ).value };
			} );

			return Object.assign( {}, settings, {
				business_hours: businessHours,
				arrival_windows: arrivalWindows,
				holidays: holidays,
				travel_buffer_minutes: parseInt( body.querySelector( '.sc-travel-buffer' ).value, 10 ) || 0,
				overtime: {
					allowed: body.querySelector( '.sc-overtime-allowed' ).checked,
					max_hours_per_day: parseFloat( body.querySelector( '.sc-overtime-max' ).value ) || 0,
				},
				timers: {
					no_response_hours: parseInt( body.querySelector( '.sc-timer-no-response' ).value, 10 ) || 1,
					quote_validity_days: parseInt( body.querySelector( '.sc-timer-quote-validity' ).value, 10 ) || 1,
					quote_reminder_days: parseInt( body.querySelector( '.sc-timer-quote-reminder' ).value, 10 ) || 1,
				},
			} );
		}

		renderShell(
			body,
			function () { goToStep( state.stepIndex - 1 ); },
			function () {
				SCApp.request( { path: '/service-crew/v1/scheduling-settings', method: 'PUT', data: collect() } ).then( function ( saved ) {
					state.schedulingSettings = saved;
					saveWizardState( { completed_steps: markStepComplete( 'scheduling' ) } ).then( function () {
						goToStep( state.stepIndex + 1 );
					} );
				} );
			},
			'Next'
		);
	}

	// ---- Step 3: address lookup consent (mandatory, cannot be skipped) ----

	function renderAddressStep() {
		var body = SCApp.el( 'div', {} );
		body.appendChild( SCApp.el( 'h2', { text: 'Address lookup' } ) );
		body.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			text: 'ServiceCrew looks up customer and crew addresses using Nominatim (OpenStreetMap), a free address lookup service. The address and ZIP you enter are sent to their servers to get map coordinates. This step cannot be skipped.',
		} ) );

		var consentCheckbox = SCApp.el( 'input', { type: 'checkbox', class: 'sc-wizard-consent' } );
		if ( state.wizardState.consent_accepted ) {
			consentCheckbox.setAttribute( 'checked', 'checked' );
		}
		body.appendChild( SCApp.el( 'label', { class: 'sc-toggle', style: 'margin: 14px 0;' }, [ consentCheckbox, SCApp.el( 'span', { text: 'I understand addresses are sent to Nominatim for lookup.' } ) ] ) );

		var addressInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', placeholder: 'Street address' } );
		var zipInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', placeholder: 'ZIP / postal code' } );

		body.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Test address' } ), addressInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'ZIP' } ), zipInput ] ),
		] ) );

		var resultEl = SCApp.el( 'p', { class: 'sc-help' } );
		var testButton = SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: 'Test this address' } );

		var nextBtn;

		function refreshNextState() {
			var canProceed = consentCheckbox.checked && state.wizardState.address_test_passed;
			if ( nextBtn ) {
				nextBtn.toggleAttribute( 'disabled', ! canProceed );
			}
		}

		testButton.addEventListener( 'click', function () {
			resultEl.textContent = 'Testing…';
			SCApp.request( { path: '/service-crew/v1/wizard/test-address', method: 'POST', data: { address: addressInput.value, zip: zipInput.value } } ).then( function ( result ) {
				var passed = 'failed' !== result.quality;
				state.wizardState.address_test_passed = passed;
				resultEl.textContent = passed
					? 'Lookup succeeded (' + result.quality + ' match). You can continue.'
					: 'Lookup failed for that address. Try a different address or ZIP.';
				resultEl.classList.toggle( 'sc-notice', ! passed );
				refreshNextState();
			} );
		} );

		consentCheckbox.addEventListener( 'change', refreshNextState );

		body.appendChild( testButton );
		body.appendChild( resultEl );

		renderShell(
			body,
			function () { goToStep( state.stepIndex - 1 ); },
			function () {
				saveWizardState( {
					consent_accepted: true,
					address_test_passed: true,
					completed_steps: markStepComplete( 'address' ),
				} ).then( function () {
					goToStep( state.stepIndex + 1 );
				} );
			},
			'Next',
			true
		);

		nextBtn = root.querySelector( '.sc-wizard-actions .sc-btn--primary' );
		refreshNextState();
	}

	// ---- Step 5: first service (thin wrapper over the Services endpoint) --

	function renderFirstServiceStep() {
		var body = SCApp.el( 'div', {} );
		body.appendChild( SCApp.el( 'h2', { text: 'First service' } ) );
		body.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'One priced service to get started — add more, and reorganize into categories, any time from Services in the ServiceCrew menu.' } ) );

		var titleInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', placeholder: 'e.g. Standard House Cleaning' } );
		body.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Service name' } ), titleInput ] ) );

		var priceInput = SCApp.el( 'input', { type: 'number', step: '0.01', min: '0', class: 'sc-input', value: 0 } );
		var durationInput = SCApp.el( 'input', { type: 'number', step: '1', min: '0', class: 'sc-input', value: 0 } );
		body.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Price ($)' } ), priceInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Duration (minutes)' } ), durationInput ] ),
		] ) );

		var skipLink = SCApp.el( 'button', { type: 'button', class: 'sc-link-danger', text: 'Skip for now — add a service later' } );
		body.appendChild( skipLink );

		function goNext() {
			saveWizardState( { completed_steps: markStepComplete( 'first_service' ) } ).then( function () {
				goToStep( state.stepIndex + 1 );
			} );
		}

		skipLink.addEventListener( 'click', goNext );

		renderShell(
			body,
			function () { goToStep( state.stepIndex - 1 ); },
			function () {
				var title = titleInput.value.trim();
				if ( '' === title ) {
					goNext();
					return;
				}

				SCApp.request( {
					path: '/service-crew/v1/services',
					method: 'POST',
					data: {
						title: title,
						parent_id: 0,
						pricing: { price_mode: 'flat', price: parseFloat( priceInput.value ) || 0, duration_minutes: parseInt( durationInput.value, 10 ) || 0 },
						components: [],
					},
				} ).then( goNext );
			},
			'Next'
		);
	}

	// ---- Step 6: first crew member (skippable) -----------------------------

	function renderFirstCrewStep() {
		var body = SCApp.el( 'div', {} );
		body.appendChild( SCApp.el( 'h2', { text: 'First crew member' } ) );
		body.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'Optional — add the first employee or vendor now, or skip and add crew later from the Crew menu. No login is created here.' } ) );

		var nameInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', placeholder: 'Full name' } );
		body.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Name' } ), nameInput ] ) );

		var employeeRadio = SCApp.el( 'input', { type: 'radio', name: 'sc_wizard_crew_type', value: 'employee' } );
		employeeRadio.checked = true;
		var vendorRadio = SCApp.el( 'input', { type: 'radio', name: 'sc_wizard_crew_type', value: 'vendor' } );

		body.appendChild( SCApp.el( 'div', { class: 'sc-toggle-row' }, [
			SCApp.el( 'label', { class: 'sc-toggle' }, [ employeeRadio, SCApp.el( 'span', { text: 'Employee' } ) ] ),
			SCApp.el( 'label', { class: 'sc-toggle' }, [ vendorRadio, SCApp.el( 'span', { text: 'Vendor' } ) ] ),
		] ) );

		var phoneInput = SCApp.el( 'input', { type: 'tel', class: 'sc-input' } );
		var emailInput = SCApp.el( 'input', { type: 'email', class: 'sc-input' } );
		body.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Phone' } ), phoneInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Email' } ), emailInput ] ),
		] ) );

		var addressInput = SCApp.el( 'input', { type: 'text', class: 'sc-input' } );
		var zipInput = SCApp.el( 'input', { type: 'text', class: 'sc-input' } );
		var radiusInput = SCApp.el( 'input', { type: 'number', min: '0', step: '1', class: 'sc-input' } );
		body.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Address' } ), addressInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'ZIP' } ), zipInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Coverage radius (mi)' } ), radiusInput, SCApp.el( 'p', { class: 'sc-help', text: 'Blank = unlimited (default for employees).' } ) ] ),
		] ) );

		var skipLink = SCApp.el( 'button', { type: 'button', class: 'sc-link-danger', text: 'Skip for now — add crew later' } );
		body.appendChild( skipLink );

		function goNext() {
			saveWizardState( { completed_steps: markStepComplete( 'first_crew' ) } ).then( function () {
				goToStep( state.stepIndex + 1 );
			} );
		}

		skipLink.addEventListener( 'click', goNext );

		renderShell(
			body,
			function () { goToStep( state.stepIndex - 1 ); },
			function () {
				var name = nameInput.value.trim();
				if ( '' === name ) {
					goNext();
					return;
				}

				SCApp.request( {
					path: '/service-crew/v1/wizard/crew-member',
					method: 'POST',
					data: {
						name: name,
						type: vendorRadio.checked ? 'vendor' : 'employee',
						phone: phoneInput.value,
						email: emailInput.value,
						address: addressInput.value,
						zip: zipInput.value,
						radius: radiusInput.value,
					},
				} ).then( goNext );
			},
			'Next'
		);
	}

	// ---- Step 7: finish checklist ------------------------------------------

	function checklistRow( label, done, note ) {
		return SCApp.el( 'div', { class: 'sc-wizard-checklist__row' }, [
			SCApp.el( 'span', { class: 'sc-wizard-checklist__icon dashicons ' + ( done ? 'dashicons-yes-alt is-done' : 'dashicons-marker is-pending' ), 'aria-hidden': 'true' } ),
			SCApp.el( 'span', { class: 'sc-wizard-checklist__label', text: label } ),
			note ? SCApp.el( 'span', { class: 'sc-help', text: note } ) : null,
		] );
	}

	function renderFinishStep() {
		var body = SCApp.el( 'div', {} );
		body.appendChild( SCApp.el( 'h2', { text: 'Finish setup' } ) );
		body.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'Here is what is done and what is still missing. Anything missing can be finished later — nothing here blocks using the plugin.' } ) );

		var list = SCApp.el( 'div', { class: 'sc-wizard-checklist' } );
		body.appendChild( list );

		renderShell(
			body,
			function () { goToStep( state.stepIndex - 1 ); },
			function () {
				saveWizardState( { dismissed: true, completed_steps: markStepComplete( 'finish' ) } ).then( function () {
					window.location.href = ( window.SC_WIZARD && window.SC_WIZARD.dashboardUrl ) || '';
				} );
			},
			'Finish'
		);

		SCApp.request( { path: '/service-crew/v1/wizard/summary' } ).then( function ( summary ) {
			list.appendChild( checklistRow( 'Business basics', summary.business_basics_done ) );
			list.appendChild( checklistRow( 'Scheduling', summary.scheduling_done ) );
			list.appendChild( checklistRow( 'Address lookup', summary.address_done ) );
			list.appendChild( checklistRow( 'Payments', summary.payments_done, 'Coming in a later update — the quote form works without it.' ) );
			list.appendChild( checklistRow( 'First service', summary.first_service_done, summary.first_service_done ? '' : 'No services yet — instant booking has nothing to sell until one exists.' ) );
			list.appendChild( checklistRow( 'First crew member', summary.first_crew_done, summary.first_crew_done ? '' : 'No crew yet — optional, but nothing can be assigned until one exists.' ) );
		} );
	}

	var STEP_RENDERERS = {
		basics: renderBasicsStep,
		scheduling: renderSchedulingStep,
		address: renderAddressStep,
		first_service: renderFirstServiceStep,
		first_crew: renderFirstCrewStep,
		finish: renderFinishStep,
	};

	function render() {
		STEP_RENDERERS[ STEPS[ state.stepIndex ].key ]();
	}

	setupModalChrome();

	SCApp.request( { path: '/service-crew/v1/wizard/state' } )
		.then( function ( wizardState ) {
			state.wizardState = wizardState;
			state.stepIndex = stepIndexForKey( wizardState.current_step );
			return SCApp.request( { path: '/service-crew/v1/wizard/business-basics' } );
		} )
		.then( function ( basics ) {
			state.basics = basics;
			return SCApp.request( { path: '/service-crew/v1/scheduling-settings' } );
		} )
		.then( function ( settings ) {
			state.schedulingSettings = settings;
			render();
		} );
} )();
