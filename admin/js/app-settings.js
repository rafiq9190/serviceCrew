/**
 * Settings screen: weekly business hours, arrival windows, holidays/closed
 * dates, travel buffer, overtime allowance, the three admin timers, the
 * site-wide tax rate/mode, and the minimum-deposit-by-booking-amount
 * brackets. Talks only to /service-crew/v1/scheduling-settings — see
 * class-service-crew-settings.php. One sticky save bar covers every section
 * instead of a per-card Save button, since a change to any field here is
 * part of the same settings object server-side.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'settings' !== root.getAttribute( 'data-view' ) ) {
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

	function buildTaxCard( taxRatePercent, taxMode ) {
		var card = SCApp.sectionCard( 'dashicons-money-alt', 'Tax', 'Applied site-wide to every total shown to a customer, after any advance-payment discount.' );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Tax rate (%)' } ),
			SCApp.el( 'input', { type: 'number', min: '0', max: '100', step: '0.01', class: 'sc-input sc-tax-rate', value: taxRatePercent } ),
		] ) );

		var modeSelect = SCApp.el( 'select', { class: 'sc-input sc-tax-mode' }, [
			SCApp.el( 'option', { value: 'exclusive', text: 'Add tax on top of prices' } ),
			SCApp.el( 'option', { value: 'inclusive', text: 'Prices already include tax (deduct it out)' } ),
		] );
		modeSelect.value = 'inclusive' === taxMode ? 'inclusive' : 'exclusive';

		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Tax mode' } ),
			modeSelect,
		] ) );

		return card;
	}

	function buildDepositTiersCard( tiers ) {
		var card = SCApp.sectionCard( 'dashicons-tag', 'Minimum deposit', 'No payment, no booking — the minimum the customer must pay now, by booking amount. The bracket with the highest amount at or below the booking total applies.' );

		var list = SCApp.el( 'div', { class: 'sc-repeater-list sc-deposit-tier-list' } );

		function addRow( tierRow ) {
			tierRow = tierRow || {};
			var row = SCApp.repeaterRow(
				[
					{ label: 'Booking amount at least', input: SCApp.el( 'input', { type: 'number', min: '0', step: '0.01', class: 'sc-input sc-deposit-amount', value: tierRow.min_booking_amount || 0 } ) },
					{ label: 'Minimum deposit (%)', input: SCApp.el( 'input', { type: 'number', min: '1', max: '100', step: '1', class: 'sc-input sc-deposit-percent', value: tierRow.deposit_percent || '' } ) },
				],
				function () {
					if ( row.parentNode ) {
						row.parentNode.removeChild( row );
					}
					markDirty();
				}
			);
			list.appendChild( row );
		}

		tiers.forEach( addRow );

		card.appendChild( list );
		card.appendChild( SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: '+ Add bracket', onClick: function () { addRow(); markDirty(); } } ) );
		card.list = list;

		return card;
	}

	var cards = {};

	function render( settings ) {
		root.innerHTML = '';

		var page = SCApp.el( 'div', { class: 'sc-settings-page' } );

		cards.hours = SCApp.buildBusinessHoursCard( settings.business_hours, markDirty );
		page.appendChild( cards.hours );

		var grid = SCApp.el( 'div', { class: 'sc-settings-grid' } );
		cards.windows = SCApp.buildArrivalWindowsCard( settings.arrival_windows, markDirty );
		cards.holidays = SCApp.buildHolidaysCard( settings.holidays, markDirty );
		cards.travel = SCApp.buildTravelOvertimeCard( settings.travel_buffer_minutes, settings.overtime, markDirty );
		cards.timers = SCApp.buildTimersCard( settings.timers );
		cards.tax = buildTaxCard( settings.tax_rate_percent, settings.tax_mode );
		cards.deposits = buildDepositTiersCard( settings.minimum_deposit_tiers );

		grid.appendChild( cards.windows );
		grid.appendChild( cards.holidays );
		grid.appendChild( cards.travel );
		grid.appendChild( cards.timers );
		grid.appendChild( cards.tax );
		grid.appendChild( cards.deposits );
		page.appendChild( grid );

		root.appendChild( page );

		page.addEventListener( 'input', markDirty );
		page.addEventListener( 'change', markDirty );

		ensureSavebar( save, function () { load(); } );
		clearDirty();
	}

	function collect() {
		var businessHours = {};
		root.querySelectorAll( '.sc-hours-row' ).forEach( function ( row ) {
			businessHours[ row.getAttribute( 'data-day' ) ] = {
				enabled: row.querySelector( '.sc-hours-enabled' ).checked,
				start: row.querySelector( '.sc-hours-start' ).value,
				end: row.querySelector( '.sc-hours-end' ).value,
			};
		} );

		var arrivalWindows = Array.prototype.slice.call( cards.windows.list.querySelectorAll( '.sc-repeater-row' ) ).map( function ( row ) {
			return {
				label: row.querySelector( '.sc-window-label' ).value,
				start: row.querySelector( '.sc-window-start' ).value,
				end: row.querySelector( '.sc-window-end' ).value,
			};
		} );

		var holidays = Array.prototype.slice.call( cards.holidays.list.querySelectorAll( '.sc-repeater-row' ) ).map( function ( row ) {
			return {
				date: row.querySelector( '.sc-holiday-date' ).value,
				label: row.querySelector( '.sc-holiday-label' ).value,
			};
		} );

		var depositTiers = Array.prototype.slice.call( cards.deposits.list.querySelectorAll( '.sc-repeater-row' ) ).map( function ( row ) {
			return {
				min_booking_amount: parseFloat( row.querySelector( '.sc-deposit-amount' ).value ) || 0,
				deposit_percent: parseFloat( row.querySelector( '.sc-deposit-percent' ).value ) || 0,
			};
		} );

		return {
			business_hours: businessHours,
			arrival_windows: arrivalWindows,
			holidays: holidays,
			travel_buffer_minutes: parseInt( root.querySelector( '.sc-travel-buffer' ).value, 10 ) || 0,
			overtime: {
				allowed: root.querySelector( '.sc-overtime-allowed' ).checked,
				max_hours_per_day: parseFloat( root.querySelector( '.sc-overtime-max' ).value ) || 0,
			},
			timers: {
				no_response_hours: parseInt( root.querySelector( '.sc-timer-no-response' ).value, 10 ) || 1,
				quote_validity_days: parseInt( root.querySelector( '.sc-timer-quote-validity' ).value, 10 ) || 1,
				quote_reminder_days: parseInt( root.querySelector( '.sc-timer-quote-reminder' ).value, 10 ) || 1,
			},
			tax_rate_percent: parseFloat( root.querySelector( '.sc-tax-rate' ).value ) || 0,
			tax_mode: root.querySelector( '.sc-tax-mode' ).value,
			minimum_deposit_tiers: depositTiers,
		};
	}

	function save() {
		SCApp.request( { path: '/service-crew/v1/scheduling-settings', method: 'PUT', data: collect() } ).then( function ( saved ) {
			SCApp.toast( 'Settings saved.' );
			render( saved );
		} );
	}

	function load() {
		SCApp.request( { path: '/service-crew/v1/scheduling-settings' } ).then( function ( settings ) {
			render( settings );
		} );
	}

	load();
} )();
