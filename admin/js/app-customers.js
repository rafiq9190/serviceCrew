/**
 * Customers screen — the Phase 1e "customer list/search" (+ a narrowed
 * "customer detail") task: a searchable list talking to
 * GET /service-crew/v1/customers (see class-service-crew-customers-controller.php),
 * with a per-row "View bookings" toggle expanding that customer's booking
 * history via GET /customers/{id}/bookings.
 *
 * Narrower than the plan's full "customer detail" (booking history with
 * fulfiller, payment history, notes timeline including attachments) — this
 * only shows what's already on each booking row itself (date, service/quote
 * title, status, totals). Who fulfilled each job, a payment-by-payment
 * ledger and the notes timeline are a separate, not-yet-built follow-up.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'customers' !== root.getAttribute( 'data-view' ) ) {
		return;
	}

	var CUSTOMERS_COLUMN_COUNT = 5;

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

	// Which customer's booking-history row is currently expanded, if any.
	var expandedId = null;

	var customersCache = [];
	var searchValue = '';
	var optedInOnly = false;

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

		var date = new Date( mysqlDatetime.replace( ' ', 'T' ) + 'Z' );

		if ( isNaN( date.getTime() ) || 'function' !== typeof date.toLocaleDateString ) {
			return mysqlDatetime;
		}

		return date.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
	}

	function load() {
		var path = '/service-crew/v1/customers';
		var query = [];

		if ( searchValue.trim() ) {
			query.push( 'search=' + encodeURIComponent( searchValue.trim() ) );
		}

		if ( optedInOnly ) {
			query.push( 'opted_in=1' );
		}

		if ( query.length ) {
			path += '?' + query.join( '&' );
		}

		SCApp.request( { path: path } ).then( function ( customers ) {
			customersCache = customers || [];
			render();
		} );
	}

	function buildFiltersBar() {
		var bar = SCApp.el( 'div', { class: 'sc-customers-filters' } );

		var searchInput = SCApp.el( 'input', { type: 'search', class: 'sc-input', placeholder: 'Search name or email…', value: searchValue } );
		searchInput.addEventListener( 'input', function () {
			searchValue = searchInput.value;
			window.clearTimeout( searchInput.scDebounce );
			searchInput.scDebounce = window.setTimeout( load, 300 );
		} );

		var optedInLabel = SCApp.el( 'label', { class: 'sc-customers-filters__checkbox' } );
		var optedInCheckbox = SCApp.el( 'input', { type: 'checkbox' } );
		optedInCheckbox.checked = optedInOnly;
		optedInCheckbox.addEventListener( 'change', function () {
			optedInOnly = optedInCheckbox.checked;
			load();
		} );
		optedInLabel.appendChild( optedInCheckbox );
		optedInLabel.appendChild( SCApp.el( 'span', { text: 'Opted in to offers/emails only' } ) );

		bar.appendChild( searchInput );
		bar.appendChild( optedInLabel );

		return bar;
	}

	function buildRow( customer ) {
		var tr = SCApp.el( 'tr', { class: customer.id === expandedId ? 'sc-bookings-table__row--expanded' : '' } );

		tr.appendChild( SCApp.el( 'td', { text: customer.name || '—' } ) );
		tr.appendChild( SCApp.el( 'td', {}, [
			SCApp.el( 'div', { text: customer.email } ),
			SCApp.el( 'div', { class: 'sc-help', style: 'margin: 0;', text: customer.phone || '' } ),
		] ) );
		tr.appendChild( SCApp.el( 'td', {}, [
			customer.marketing_opt_in
				? SCApp.el( 'span', { class: 'sc-badge', text: 'Opted in' } )
				: SCApp.el( 'span', { class: 'sc-help', style: 'margin: 0;', text: '—' } ),
		] ) );
		tr.appendChild( SCApp.el( 'td', { text: formatCreatedAt( customer.created_at ) } ) );

		var button = SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary sc-btn--small',
			text: customer.id === expandedId ? 'Close' : 'View bookings',
			onClick: function () {
				expandedId = customer.id === expandedId ? null : customer.id;
				render();
			},
		} );
		tr.appendChild( SCApp.el( 'td', {}, [ button ] ) );

		return tr;
	}

	function buildBookingsDetailRow( customer ) {
		var tr = SCApp.el( 'tr', { class: 'sc-quote-detail-row' } );
		var td = SCApp.el( 'td', { colspan: String( CUSTOMERS_COLUMN_COUNT ) } );
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card', text: 'Loading…' } );

		td.appendChild( card );
		tr.appendChild( td );

		SCApp.request( { path: '/service-crew/v1/customers/' + customer.id + '/bookings' } ).then( function ( bookings ) {
			card.innerHTML = '';
			card.appendChild( renderBookingsTable( bookings || [] ) );
		} );

		return tr;
	}

	function renderBookingsTable( bookings ) {
		var wrap = SCApp.el( 'div' );
		wrap.appendChild( SCApp.el( 'h4', { text: 'Booking history' } ) );

		if ( ! bookings.length ) {
			wrap.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'No bookings yet for this customer.' } ) );
			return wrap;
		}

		var table = SCApp.el( 'table', { class: 'sc-bookings-table' } );
		var thead = SCApp.el( 'thead' );
		thead.appendChild( SCApp.el( 'tr', {}, [
			SCApp.el( 'th', { text: 'Service / Quote' } ),
			SCApp.el( 'th', { text: 'Date & window' } ),
			SCApp.el( 'th', { text: 'Status' } ),
			SCApp.el( 'th', { text: 'Total' } ),
			SCApp.el( 'th', { text: 'Paid' } ),
		] ) );
		table.appendChild( thead );

		var tbody = SCApp.el( 'tbody' );
		bookings.forEach( function ( booking ) {
			var label = booking.service_title || ( 'quote' === booking.source ? ( booking.quote_title || 'Quote request' ) : 'Deleted service' );

			tbody.appendChild( SCApp.el( 'tr', {}, [
				SCApp.el( 'td', { text: label } ),
				SCApp.el( 'td', { text: formatDate( booking.preferred_date ) + ( booking.arrival_window ? ' · ' + booking.arrival_window : '' ) } ),
				SCApp.el( 'td', { text: STATUS_LABELS[ booking.status ] || booking.status } ),
				SCApp.el( 'td', { text: formatMoney( booking.customer_total ) } ),
				SCApp.el( 'td', { text: formatMoney( booking.amount_paid ) } ),
			] ) );
		} );
		table.appendChild( tbody );

		wrap.appendChild( table );
		return wrap;
	}

	function render() {
		root.innerHTML = '';

		var panel = SCApp.el( 'div', { class: 'sc-app-panel sc-app-panel--bookings' } );

		panel.appendChild( SCApp.el( 'h2', { text: 'Customers' } ) );
		panel.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			text: 'Every customer found via a booking or quote. "View bookings" shows what\'s on each booking row itself — fulfiller and payment-by-payment history aren\'t surfaced here yet.',
		} ) );

		panel.appendChild( buildFiltersBar() );

		if ( ! customersCache.length ) {
			panel.appendChild( SCApp.el( 'div', { class: 'sc-app-empty', text: 'No customers found.' } ) );
			root.appendChild( panel );
			return;
		}

		var table = SCApp.el( 'table', { class: 'sc-bookings-table' } );
		var thead = SCApp.el( 'thead' );
		thead.appendChild( SCApp.el( 'tr', {}, [
			SCApp.el( 'th', { text: 'Name' } ),
			SCApp.el( 'th', { text: 'Contact' } ),
			SCApp.el( 'th', { text: 'Marketing' } ),
			SCApp.el( 'th', { text: 'First seen' } ),
			SCApp.el( 'th', { text: 'Actions' } ),
		] ) );
		table.appendChild( thead );

		var tbody = SCApp.el( 'tbody' );
		customersCache.forEach( function ( customer ) {
			tbody.appendChild( buildRow( customer ) );

			if ( customer.id === expandedId ) {
				tbody.appendChild( buildBookingsDetailRow( customer ) );
			}
		} );
		table.appendChild( tbody );

		panel.appendChild( table );
		root.appendChild( panel );
	}

	load();
} )();
