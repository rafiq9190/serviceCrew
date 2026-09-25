/**
 * Bookings screen: a table of the most recent bookings (newest first),
 * talking to GET /service-crew/v1/bookings — see
 * class-service-crew-bookings-controller.php's list_bookings() — plus an
 * inline status dropdown wired to PUT /bookings/{id}/status. This is the
 * plan's "minimal admin bookings list", not the Phase 1c dispatch board: no
 * crew suggestion or assignment (class-service-crew-matching.php and
 * class-service-crew-assignments.php don't exist yet, and there's no Crew
 * REST controller yet either to list who could be assigned), no filters,
 * no pagination.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'bookings' !== root.getAttribute( 'data-view' ) ) {
		return;
	}

	// Mirrors Service_Crew_Bookings::ADMIN_SETTABLE_STATUSES — see that
	// constant's docblock for why this isn't the plan's full status list.
	var ADMIN_SETTABLE_STATUSES = [ 'awaiting_payment', 'confirmed', 'completed', 'cancelled' ];

	var STATUS_LABELS = {
		awaiting_payment: 'Awaiting payment',
		requested: 'Requested',
		quoted: 'Quoted',
		quote_expired: 'Quote expired',
		quote_rejected: 'Quote rejected',
		pending_approval: 'Pending approval',
		confirmed: 'Confirmed',
		assigned: 'Assigned',
		on_the_way: 'On the way',
		in_progress: 'In progress',
		completed: 'Completed',
		cancelled: 'Cancelled',
	};

	function formatMoney( amount ) {
		return '$' + ( Math.round( ( Number( amount ) || 0 ) * 100 ) / 100 ).toFixed( 2 );
	}

	function formatDate( iso ) {
		if ( ! iso ) {
			return '—';
		}

		var date = new Date( iso + 'T00:00:00' );

		if ( 'function' === typeof date.toLocaleDateString ) {
			return date.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
		}

		return iso;
	}

	function formatCreatedAt( mysqlDatetime ) {
		if ( ! mysqlDatetime ) {
			return '';
		}

		// MySQL DATETIME ("YYYY-MM-DD HH:MM:SS") isn't directly parseable by
		// Date() in every browser without the "T" separator.
		var date = new Date( mysqlDatetime.replace( ' ', 'T' ) + 'Z' );

		if ( isNaN( date.getTime() ) || 'function' !== typeof date.toLocaleString ) {
			return mysqlDatetime;
		}

		return date.toLocaleString( undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } );
	}

	function load() {
		SCApp.request( { path: '/service-crew/v1/bookings' } ).then( function ( bookings ) {
			render( bookings || [] );
		} );
	}

	function buildFlags( booking ) {
		var flags = [];

		if ( Number( booking.flag_outside_coverage ) ) {
			flags.push( 'Outside coverage' );
		}

		if ( Number( booking.flag_address_review ) ) {
			flags.push( 'Address needs review' );
		}

		if ( Number( booking.flag_address_approximate ) ) {
			flags.push( 'Address approximate' );
		}

		if ( Number( booking.is_emergency ) ) {
			flags.push( 'Emergency' );
		}

		if ( ! flags.length ) {
			return SCApp.el( 'span', { class: 'sc-help', style: 'margin: 0;', text: '—' } );
		}

		return SCApp.el( 'div', { class: 'sc-bookings-table__flags' }, flags.map( function ( label ) {
			return SCApp.el( 'span', { class: 'sc-badge sc-badge--flag', text: label } );
		} ) );
	}

	function buildStatusCell( booking ) {
		var select = SCApp.el( 'select', { class: 'sc-input sc-status-select sc-status-select--' + booking.status } );

		ADMIN_SETTABLE_STATUSES.forEach( function ( value ) {
			var option = SCApp.el( 'option', { value: value, text: STATUS_LABELS[ value ] || value } );

			if ( value === booking.status ) {
				option.setAttribute( 'selected', 'selected' );
			}

			select.appendChild( option );
		} );

		// A status this list doesn't offer as a manual choice (e.g. a future
		// Phase 1c crew-workflow status) still needs to be shown, not
		// silently swapped for one of the choices above.
		if ( -1 === ADMIN_SETTABLE_STATUSES.indexOf( booking.status ) ) {
			var current = SCApp.el( 'option', { value: booking.status, text: STATUS_LABELS[ booking.status ] || booking.status, selected: 'selected' } );
			select.insertBefore( current, select.firstChild );
		}

		select.addEventListener( 'change', function () {
			var previous = booking.status;
			select.disabled = true;

			SCApp.request( {
				path: '/service-crew/v1/bookings/' + booking.id + '/status',
				method: 'PUT',
				data: { status: select.value },
			} ).then( function () {
				booking.status = select.value;
				select.className = 'sc-input sc-status-select sc-status-select--' + booking.status;
				select.disabled = false;
				SCApp.toast( 'Status updated.' );
			} ).catch( function () {
				select.value = previous;
				select.disabled = false;
			} );
		} );

		return select;
	}

	function buildRow( booking ) {
		var tr = SCApp.el( 'tr', {} );

		tr.appendChild( SCApp.el( 'td', {}, [
			SCApp.el( 'div', { text: booking.service_title || 'Deleted service' } ),
			SCApp.el( 'div', { class: 'sc-help', style: 'margin: 0;', text: 'Booked ' + formatCreatedAt( booking.created_at ) } ),
		] ) );

		tr.appendChild( SCApp.el( 'td', {}, [
			SCApp.el( 'div', { text: booking.customer_name } ),
			SCApp.el( 'div', { class: 'sc-help', style: 'margin: 0;', text: booking.customer_email + ( booking.customer_phone ? ' · ' + booking.customer_phone : '' ) } ),
		] ) );

		tr.appendChild( SCApp.el( 'td', { text: formatDate( booking.preferred_date ) + ( booking.arrival_window ? ' · ' + booking.arrival_window : '' ) } ) );

		tr.appendChild( SCApp.el( 'td', {}, [
			SCApp.el( 'div', { text: 'Deposit ' + formatMoney( booking.deposit_amount ) } ),
			SCApp.el( 'div', { class: 'sc-help', style: 'margin: 0;', text: 'Paid ' + formatMoney( booking.amount_paid ) + ' of ' + formatMoney( booking.customer_total ) } ),
		] ) );

		tr.appendChild( SCApp.el( 'td', {}, [ buildFlags( booking ) ] ) );

		tr.appendChild( SCApp.el( 'td', {}, [ buildStatusCell( booking ) ] ) );

		return tr;
	}

	function render( bookings ) {
		root.innerHTML = '';

		var panel = SCApp.el( 'div', { class: 'sc-app-panel sc-app-panel--bookings' } );

		panel.appendChild( SCApp.el( 'h2', { text: 'Recent bookings' } ) );
		panel.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			text: 'The ' + bookings.length + ' most recent bookings, newest first. No crew assignment yet — that arrives with the dispatch board.',
		} ) );

		if ( ! bookings.length ) {
			panel.appendChild( SCApp.el( 'div', { class: 'sc-app-empty', text: 'No bookings yet.' } ) );
			root.appendChild( panel );
			return;
		}

		var table = SCApp.el( 'table', { class: 'sc-bookings-table' } );
		var thead = SCApp.el( 'thead' );
		thead.appendChild( SCApp.el( 'tr', {}, [
			SCApp.el( 'th', { text: 'Service' } ),
			SCApp.el( 'th', { text: 'Customer' } ),
			SCApp.el( 'th', { text: 'Date & window' } ),
			SCApp.el( 'th', { text: 'Payment' } ),
			SCApp.el( 'th', { text: 'Flags' } ),
			SCApp.el( 'th', { text: 'Status' } ),
		] ) );
		table.appendChild( thead );

		var tbody = SCApp.el( 'tbody' );
		bookings.forEach( function ( booking ) {
			tbody.appendChild( buildRow( booking ) );
		} );
		table.appendChild( tbody );

		panel.appendChild( table );
		root.appendChild( panel );
	}

	load();
} )();
