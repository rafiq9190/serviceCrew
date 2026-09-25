/**
 * Shared helpers for the ServiceCrew custom admin app (Services, Discounts,
 * Settings, the setup wizard). wp.apiFetch's nonce/root-URL middleware is
 * registered inline by Service_Crew_Admin_App/Service_Crew_Wizard's own
 * enqueue_assets() before this file runs.
 */
window.SCApp = ( function () {
	'use strict';

	/**
	 * Builds a DOM element. attrs['text'] sets textContent; attrs['onXxx']
	 * (function) binds an event listener; everything else is set via
	 * setAttribute (safe for user-provided values — never innerHTML).
	 */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];

			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 0 === key.indexOf( 'on' ) && 'function' === typeof value ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else {
				node.setAttribute( key, value );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );

		return node;
	}

	function toast( message, type ) {
		var container = document.getElementById( 'sc-app-toasts' );

		if ( ! container ) {
			container = el( 'div', { id: 'sc-app-toasts', class: 'sc-app-toasts' } );
			document.body.appendChild( container );
		}

		var note = el( 'div', { class: 'sc-app-toast sc-app-toast--' + ( type || 'success' ), text: message } );
		container.appendChild( note );

		setTimeout( function () {
			note.classList.add( 'sc-app-toast--out' );
			setTimeout( function () {
				if ( note.parentNode ) {
					note.parentNode.removeChild( note );
				}
			}, 200 );
		}, 3000 );
	}

	function request( options ) {
		return wp.apiFetch( options ).catch( function ( error ) {
			toast( ( error && error.message ) || 'Something went wrong.', 'error' );
			throw error;
		} );
	}

	var WEEKDAYS = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
	var WEEKDAY_LABELS = {
		monday: 'Monday',
		tuesday: 'Tuesday',
		wednesday: 'Wednesday',
		thursday: 'Thursday',
		friday: 'Friday',
		saturday: 'Saturday',
		sunday: 'Sunday',
	};

	/**
	 * A titled card with an icon, used by both the Settings screen and the
	 * setup wizard's scheduling step so the two share one visual language
	 * instead of two hand-tuned copies.
	 */
	function sectionCard( iconClass, title, help, wide ) {
		var card = el( 'div', { class: 'sc-settings-card' + ( wide ? ' sc-settings-card--wide' : '' ) } );

		card.appendChild( el( 'div', { class: 'sc-settings-card__header' }, [
			el( 'span', { class: 'dashicons ' + iconClass + ' sc-settings-card__icon', 'aria-hidden': 'true' } ),
			el( 'div', {}, [
				el( 'h3', { text: title } ),
				help ? el( 'p', { class: 'sc-help', text: help } ) : null,
			] ),
		] ) );

		return card;
	}

	function repeaterRow( fields, onRemove ) {
		var row = el( 'div', { class: 'sc-repeater-row' } );

		fields.forEach( function ( field ) {
			// A time/number field (field.narrow) doesn't need to stretch as
			// wide as a free-text label — without this every field grows
			// equally, which on a wide row leaves time pickers with a lot of
			// empty space around "09:00".
			row.appendChild( el( 'div', { class: 'sc-field' + ( field.narrow ? ' sc-field--narrow' : '' ) }, [
				el( 'label', { text: field.label } ),
				field.input,
			] ) );
		} );

		row.appendChild( el( 'button', { type: 'button', class: 'sc-link-danger', text: 'Remove', onClick: onRemove } ) );

		return row;
	}

	/**
	 * @param {Object} businessHours Keyed by weekday, {enabled,start,end}.
	 * @param {Function} onChange Called whenever a field in this card changes.
	 */
	function buildBusinessHoursCard( businessHours, onChange ) {
		onChange = onChange || function () {};
		var card = sectionCard( 'dashicons-clock', 'Business hours', 'Days and hours the business takes bookings. Closed days keep their times saved but greyed out.', true );

		WEEKDAYS.forEach( function ( day ) {
			var config = businessHours[ day ] || { enabled: false, start: '09:00', end: '18:00' };

			var startInput = el( 'input', { type: 'time', class: 'sc-input sc-hours-start', value: config.start } );
			var endInput = el( 'input', { type: 'time', class: 'sc-input sc-hours-end', value: config.end } );

			var row = el( 'div', { class: 'sc-hours-row' + ( config.enabled ? '' : ' sc-hours-row--disabled' ), 'data-day': day } );

			var checkbox = el( 'input', {
				type: 'checkbox',
				class: 'sc-hours-enabled',
				onChange: function ( event ) {
					row.classList.toggle( 'sc-hours-row--disabled', ! event.target.checked );
					startInput.disabled = ! event.target.checked;
					endInput.disabled = ! event.target.checked;
					onChange();
				},
			} );
			if ( config.enabled ) {
				checkbox.setAttribute( 'checked', 'checked' );
			} else {
				startInput.disabled = true;
				endInput.disabled = true;
			}

			row.appendChild( el( 'label', { class: 'sc-toggle sc-hours-row__day' }, [ checkbox, el( 'span', { text: WEEKDAY_LABELS[ day ] } ) ] ) );
			row.appendChild( el( 'div', { class: 'sc-hours-row__times' }, [ startInput, el( 'span', { class: 'sc-hours-row__to', text: 'to' } ), endInput ] ) );

			card.appendChild( row );
		} );

		return card;
	}

	function buildArrivalWindowsCard( arrivalWindows, onChange ) {
		onChange = onChange || function () {};
		// Wide (full grid row), not a regular grid cell: each row has three
		// side-by-side fields (label + two time pickers) plus Remove, which
		// got cramped at a single grid column's ~340-400px width.
		var card = sectionCard( 'dashicons-calendar-alt', 'Arrival windows', 'Shown to the customer as a preference, not a hard slot — it never blocks a booking.', true );

		var list = el( 'div', { class: 'sc-repeater-list sc-arrival-window-list' } );

		function addRow( windowRow ) {
			windowRow = windowRow || {};
			var row = repeaterRow(
				[
					{ label: 'Label', input: el( 'input', { type: 'text', class: 'sc-input sc-window-label', value: windowRow.label || '' } ) },
					{ label: 'Start', narrow: true, input: el( 'input', { type: 'time', class: 'sc-input sc-window-start', value: windowRow.start || '' } ) },
					{ label: 'End', narrow: true, input: el( 'input', { type: 'time', class: 'sc-input sc-window-end', value: windowRow.end || '' } ) },
				],
				function () {
					if ( row.parentNode ) {
						row.parentNode.removeChild( row );
					}
					onChange();
				}
			);
			list.appendChild( row );
		}

		arrivalWindows.forEach( addRow );

		card.appendChild( list );
		card.appendChild( el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: '+ Add window', onClick: function () { addRow(); onChange(); } } ) );
		card.list = list;

		return card;
	}

	function buildHolidaysCard( holidays, onChange ) {
		onChange = onChange || function () {};
		var card = sectionCard( 'dashicons-flag', 'Holidays & closed dates', 'These dates never count as bookable, on top of anything closed manually on the dispatch board.' );

		var list = el( 'div', { class: 'sc-repeater-list sc-holiday-list' } );

		function addRow( holidayRow ) {
			holidayRow = holidayRow || {};
			var row = repeaterRow(
				[
					{ label: 'Date', input: el( 'input', { type: 'date', class: 'sc-input sc-holiday-date', value: holidayRow.date || '' } ) },
					{ label: 'Label', input: el( 'input', { type: 'text', class: 'sc-input sc-holiday-label', value: holidayRow.label || '' } ) },
				],
				function () {
					if ( row.parentNode ) {
						row.parentNode.removeChild( row );
					}
					onChange();
				}
			);
			list.appendChild( row );
		}

		holidays.forEach( addRow );

		card.appendChild( list );
		card.appendChild( el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: '+ Add date', onClick: function () { addRow(); onChange(); } } ) );
		card.list = list;

		return card;
	}

	function buildTravelOvertimeCard( travelBufferMinutes, overtime, onChange ) {
		onChange = onChange || function () {};
		var card = sectionCard( 'dashicons-car', 'Travel buffer & overtime' );

		card.appendChild( el( 'div', { class: 'sc-field' }, [
			el( 'label', { text: 'Travel buffer (minutes)' } ),
			el( 'input', { type: 'number', min: '0', step: '5', class: 'sc-input sc-travel-buffer', value: travelBufferMinutes } ),
			el( 'p', { class: 'sc-help', text: "Fixed gap counted between an employee's jobs on the same day." } ),
		] ) );

		var maxHoursField = el( 'div', { class: 'sc-field' + ( overtime.allowed ? '' : ' sc-hidden' ) }, [
			el( 'label', { text: 'Max overtime hours per employee per day' } ),
			el( 'input', { type: 'number', min: '0', step: '0.5', class: 'sc-input sc-overtime-max', value: overtime.max_hours_per_day } ),
		] );

		var overtimeToggle = el( 'input', {
			type: 'checkbox',
			class: 'sc-overtime-allowed',
			onChange: function ( event ) {
				maxHoursField.classList.toggle( 'sc-hidden', ! event.target.checked );
				onChange();
			},
		} );
		if ( overtime.allowed ) {
			overtimeToggle.setAttribute( 'checked', 'checked' );
		}

		card.appendChild( el( 'label', { class: 'sc-toggle', style: 'margin-bottom: 14px;' }, [ overtimeToggle, el( 'span', { text: 'Allow overtime (approved per job on the dispatch board)' } ) ] ) );
		card.appendChild( maxHoursField );

		return card;
	}

	function buildTimersCard( timers ) {
		var card = sectionCard( 'dashicons-clock', 'Timers', 'Defaults from the setup wizard; change them any time.' );

		card.appendChild( el( 'div', { class: 'sc-field' }, [
			el( 'label', { text: 'No-response wait (hours)' } ),
			el( 'input', { type: 'number', min: '1', class: 'sc-input sc-timer-no-response', value: timers.no_response_hours } ),
		] ) );

		card.appendChild( el( 'div', { class: 'sc-field' }, [
			el( 'label', { text: 'Quote validity (days)' } ),
			el( 'input', { type: 'number', min: '1', class: 'sc-input sc-timer-quote-validity', value: timers.quote_validity_days } ),
		] ) );

		card.appendChild( el( 'div', { class: 'sc-field' }, [
			el( 'label', { text: 'Accepted-not-scheduled reminder (days)' } ),
			el( 'input', { type: 'number', min: '1', class: 'sc-input sc-timer-quote-reminder', value: timers.quote_reminder_days } ),
		] ) );

		return card;
	}

	return {
		el: el,
		toast: toast,
		request: request,
		WEEKDAYS: WEEKDAYS,
		WEEKDAY_LABELS: WEEKDAY_LABELS,
		sectionCard: sectionCard,
		repeaterRow: repeaterRow,
		buildBusinessHoursCard: buildBusinessHoursCard,
		buildArrivalWindowsCard: buildArrivalWindowsCard,
		buildHolidaysCard: buildHolidaysCard,
		buildTravelOvertimeCard: buildTravelOvertimeCard,
		buildTimersCard: buildTimersCard,
	};
} )();
