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
 *
 * Quote rows (source === 'quote') additionally get a "Manage quote" toggle
 * that expands an inline detail row: the customer's description/photos,
 * a price/service/duration/crew form that marks the quote `quoted`
 * (PUT /bookings/{id}/quote), a reject action with a required reason
 * (PUT /bookings/{id}/quote-reject), and — once quoted — a date/window
 * picker that generates a single-use deposit pay-page link to copy and send
 * by hand (POST /bookings/{id}/quote-deposit-link; there is no
 * class-service-crew-emails.php yet to send it automatically). See
 * class-service-crew-quotes.php for the admin-side logic this wraps.
 *
 * Any booking with amount_paid > 0 also gets a "Refund" toggle that expands
 * an inline amount + reason form (POST /bookings/{id}/refund) — the Phase
 * 1b-1 "Admin refund action" row. See
 * Service_Crew_Bookings::refund_booking() for the admin-side logic this
 * wraps; the server, not this form, is what caps the amount at what's
 * actually left to refund.
 *
 * A `confirmed`/`assigned` booking also gets an "Assign crew" toggle — the
 * first real slice of the Phase 1c dispatch board: every employee
 * (GET /crew?type=employee), ordered by suggestion rank
 * (GET /bookings/{id}/suggestions, see class-service-crew-matching.php),
 * with checkboxes to pick the team and a radio to mark the lead, saved via
 * PUT /bookings/{id}/assignment (class-service-crew-assignments.php). V1
 * scope: employee-only, saving is immediately final — no accept/decline
 * step, no suggestion tiebreak by workload yet (every candidate ties at 0
 * load server-side, so ranking here is distance-only). Overtime approval,
 * admin-only surcharge, emergency approval UI and reschedule are separate,
 * still not-built pieces of the dispatch board — this is only the
 * crew-assignment slice of it.
 *
 * A booking with a remaining balance (customer_total > amount_paid) on a
 * `confirmed`/`assigned`/`completed` status gets a "Collect balance" toggle:
 * either mark it collected outside the system (cash/check, optional note —
 * POST /bookings/{id}/balance/collect), or generate a single-use pay-page
 * link to copy and send by hand (POST /bookings/{id}/balance/link) — same
 * token/pay-page mechanism the quote-deposit link already uses. See
 * Service_Crew_Bookings::mark_balance_collected()/create_balance_payment_link().
 *
 * A `confirmed`/`assigned` booking also gets a "Reschedule" toggle — a new
 * date + arrival window, saved via PUT /bookings/{id}/reschedule
 * (Service_Crew_Bookings::reschedule()). Per the plan, conflicts are
 * warnings, never blocks: the change always applies, and the save toast
 * calls out if the new slot already looks taken and/or an assigned crew
 * member isn't marked available on the new date. Cancelling itself needs no
 * dedicated UI — it's still the existing status dropdown, which now also
 * releases any active crew assignments (Service_Crew_Assignments::release_all()).
 *
 * The "Assign crew" card also has an "Overtime" section underneath its own
 * Save button — one approve-hours mini-form per currently-active assignment
 * (POST /bookings/{id}/overtime, see class-service-crew-overtime.php), plus
 * a list of what's already been recorded. Hidden if Settings' "Allow
 * overtime" toggle is off, same gate the server enforces. Admin-only,
 * case-by-case, no crew-initiated request step (V1 scope) — the surcharge
 * decision recorded here is for the admin's own bookkeeping only; actually
 * collecting it isn't wired to a payment yet (see that class's own docblock).
 *
 * Every booking also gets a "Notes" toggle (no status restriction) — the
 * Phase 1e "Notes UI on the board", the first real consumer of
 * Service_Crew_Notes::get_notes_for_booking(). Shows the full timeline
 * (quote description/photos, quote-reject reason, the employee job-status
 * page's completion note/photos, and any admin-added note) plus a plain
 * "Add note" box (POST /bookings/{id}/notes).
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'bookings' !== root.getAttribute( 'data-view' ) ) {
		return;
	}

	var TABLE_COLUMN_COUNT = 7;

	// Mirrors Service_Crew_Bookings::ADMIN_SETTABLE_STATUSES — see that
	// constant's docblock for why this isn't the plan's full status list.
	var ADMIN_SETTABLE_STATUSES = [ 'awaiting_payment', 'confirmed', 'completed', 'cancelled' ];

	// A quote is only manageable from this screen in these statuses — once
	// confirmed (deposit paid) or cancelled there's nothing left to set here.
	// `quote_expired` is included so re-pricing it (Service_Crew_Quotes::set_price()
	// doesn't block that status, only quote_rejected) is how the plan's "can be
	// reopened with a new date" actually happens — re-pricing sets a fresh
	// quote_expires_at the same way the first pricing did.
	var QUOTE_MANAGEABLE_STATUSES = [ 'requested', 'quoted', 'quote_expired' ];

	// A booking can have its balance collected once it's reached a real,
	// paid state — matches the statuses Service_Crew_Bookings::confirm_booking()
	// can actually leave a booking in beyond `awaiting_payment`/`quoted`.
	var BALANCE_COLLECTIBLE_STATUSES = [ 'confirmed', 'assigned', 'completed' ];

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

	// Which booking's quote detail row is currently expanded, if any.
	var expandedId = null;

	// Which booking's refund form is currently expanded, if any — independent
	// of expandedId so a quoted-and-now-paid booking can have both open.
	var refundExpandedId = null;

	// Which booking's crew-assignment form is currently expanded, if any —
	// independent of the other two toggles for the same reason.
	var assignExpandedId = null;

	// A booking can have its crew assigned once it's confirmed (deposit
	// paid) — matches Service_Crew_Assignments::assign_crew()'s own guard
	// against assigning a cancelled/completed booking.
	var ASSIGNABLE_STATUSES = [ 'confirmed', 'assigned' ];

	// Which booking's balance-collection form is currently expanded, if any —
	// independent of the other toggles for the same reason.
	var balanceExpandedId = null;

	// Which booking's reschedule form is currently expanded, if any —
	// independent of the other toggles for the same reason.
	var rescheduleExpandedId = null;

	// A booking can be rescheduled once it's reached a real, paid state —
	// matches Service_Crew_Bookings::reschedule()'s own guard against
	// rescheduling a cancelled/completed booking.
	var RESCHEDULABLE_STATUSES = [ 'confirmed', 'assigned' ];

	// Which booking's notes timeline is currently expanded, if any —
	// independent of the other toggles for the same reason. Available on
	// every booking regardless of status — notes are useful throughout a
	// job's life, not just one stage of it.
	var notesExpandedId = null;

	// Labels for note_type values already being written elsewhere in this
	// plugin (class-service-crew-quotes.php, the employee job-status page) —
	// an admin-added note has no special type (Service_Crew_Notes::TYPE_NOTE)
	// and needs no label at all.
	var NOTE_TYPE_LABELS = {
		quote_description: 'Quote description',
		quote_photo: 'Quote photo',
		quote_rejected: 'Quote rejected',
	};

	// Lazily fetched and cached the first time a quote is expanded — most
	// admin sessions never open a quote at all, so there's no reason to load
	// these on every visit to this screen.
	var servicesPromise = null;
	var schedulingPromise = null;
	var employeesPromise = null;

	function loadEmployees() {
		if ( ! employeesPromise ) {
			employeesPromise = SCApp.request( { path: '/service-crew/v1/crew?type=employee' } );
		}

		return employeesPromise;
	}

	function loadServices() {
		if ( ! servicesPromise ) {
			servicesPromise = SCApp.request( { path: '/service-crew/v1/services' } ).then( function ( services ) {
				return ( services || [] ).filter( function ( service ) {
					return ! service.has_children && 'publish' === service.status;
				} );
			} );
		}

		return servicesPromise;
	}

	function loadSchedulingSettings() {
		if ( ! schedulingPromise ) {
			schedulingPromise = SCApp.request( { path: '/service-crew/v1/scheduling-settings' } );
		}

		return schedulingPromise;
	}

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

	function formatExpiresAt( mysqlDatetime ) {
		if ( ! mysqlDatetime ) {
			return '';
		}

		return formatCreatedAt( mysqlDatetime );
	}

	var bookingsCache = [];

	function load() {
		SCApp.request( { path: '/service-crew/v1/bookings' } ).then( function ( bookings ) {
			bookingsCache = bookings || [];
			render( bookingsCache );
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

	function buildActionsCell( booking ) {
		var buttons = [];

		if ( 'quote' === booking.source ) {
			buttons.push( SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--secondary sc-btn--small',
				text: booking.id === expandedId ? 'Close' : 'Manage quote',
				onClick: function () {
					expandedId = booking.id === expandedId ? null : booking.id;
					render( bookingsCache );
				},
			} ) );
		}

		if ( Number( booking.amount_paid ) > 0 ) {
			buttons.push( SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--secondary sc-btn--small',
				text: booking.id === refundExpandedId ? 'Close' : 'Refund',
				onClick: function () {
					refundExpandedId = booking.id === refundExpandedId ? null : booking.id;
					render( bookingsCache );
				},
			} ) );
		}

		if ( -1 !== ASSIGNABLE_STATUSES.indexOf( booking.status ) ) {
			buttons.push( SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--secondary sc-btn--small',
				text: booking.id === assignExpandedId ? 'Close' : 'Assign crew',
				onClick: function () {
					assignExpandedId = booking.id === assignExpandedId ? null : booking.id;
					render( bookingsCache );
				},
			} ) );
		}

		var remainingBalance = Math.max( 0, Number( booking.customer_total ) - Number( booking.amount_paid ) );
		if ( remainingBalance > 0 && -1 !== BALANCE_COLLECTIBLE_STATUSES.indexOf( booking.status ) ) {
			buttons.push( SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--secondary sc-btn--small',
				text: booking.id === balanceExpandedId ? 'Close' : 'Collect balance',
				onClick: function () {
					balanceExpandedId = booking.id === balanceExpandedId ? null : booking.id;
					render( bookingsCache );
				},
			} ) );
		}

		if ( -1 !== RESCHEDULABLE_STATUSES.indexOf( booking.status ) ) {
			buttons.push( SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--secondary sc-btn--small',
				text: booking.id === rescheduleExpandedId ? 'Close' : 'Reschedule',
				onClick: function () {
					rescheduleExpandedId = booking.id === rescheduleExpandedId ? null : booking.id;
					render( bookingsCache );
				},
			} ) );
		}

		buttons.push( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary sc-btn--small',
			text: booking.id === notesExpandedId ? 'Close' : 'Notes',
			onClick: function () {
				notesExpandedId = booking.id === notesExpandedId ? null : booking.id;
				render( bookingsCache );
			},
		} ) );

		if ( ! buttons.length ) {
			return SCApp.el( 'td', { text: '—' } );
		}

		return SCApp.el( 'td', { class: 'sc-bookings-table__actions' }, buttons );
	}

	function buildRow( booking ) {
		var tr = SCApp.el( 'tr', { class: booking.id === expandedId ? 'sc-bookings-table__row--expanded' : '' } );

		// A quote request has no service_id until an admin sets one (the
		// plan's "quotes outside the system" step) — service_title is only
		// really "Deleted service" for a missing *instant* booking's service.
		var serviceLabel = booking.service_title || ( 'quote' === booking.source ? ( booking.quote_title || 'Quote request' ) : 'Deleted service' );

		// allow_multiple_services (Settings) lets a booking combine several
		// services — service_count comes from a COUNT(*) against
		// sc_booking_services, see Service_Crew_Bookings_Controller::list_bookings().
		if ( Number( booking.service_count ) > 1 ) {
			serviceLabel += ' +' + ( Number( booking.service_count ) - 1 ) + ' more';
		}

		tr.appendChild( SCApp.el( 'td', {}, [
			SCApp.el( 'div', { text: serviceLabel } ),
			SCApp.el( 'div', { class: 'sc-help', style: 'margin: 0;', text: 'Booked ' + formatCreatedAt( booking.created_at ) } ),
		] ) );

		tr.appendChild( SCApp.el( 'td', {}, [
			SCApp.el( 'div', { text: booking.customer_name } ),
			SCApp.el( 'div', { class: 'sc-help', style: 'margin: 0;', text: booking.customer_email + ( booking.customer_phone ? ' · ' + booking.customer_phone : '' ) } ),
		] ) );

		tr.appendChild( SCApp.el( 'td', { text: formatDate( booking.preferred_date ) + ( booking.arrival_window ? ' · ' + booking.arrival_window : '' ) } ) );

		// A quote has no price until the admin sets one outside the system —
		// showing "Deposit $0.00 / Paid $0.00 of $0.00" would read as broken
		// pricing rather than "not priced yet".
		var paymentCell = 'quote' === booking.source && ! Number( booking.customer_total )
			? [ SCApp.el( 'div', { class: 'sc-help', style: 'margin: 0;', text: 'Not priced yet' } ) ]
			: [
				SCApp.el( 'div', { text: 'Deposit ' + formatMoney( booking.deposit_amount ) } ),
				SCApp.el( 'div', { class: 'sc-help', style: 'margin: 0;', text: 'Paid ' + formatMoney( booking.amount_paid ) + ' of ' + formatMoney( booking.customer_total ) } ),
			];

		tr.appendChild( SCApp.el( 'td', {}, paymentCell ) );

		tr.appendChild( SCApp.el( 'td', {}, [ buildFlags( booking ) ] ) );

		tr.appendChild( SCApp.el( 'td', {}, [ buildStatusCell( booking ) ] ) );

		tr.appendChild( buildActionsCell( booking ) );

		return tr;
	}

	/* ---- Quote detail row --------------------------------------------- */

	function buildQuoteDetailRow( booking ) {
		var tr = SCApp.el( 'tr', { class: 'sc-quote-detail-row' } );
		var td = SCApp.el( 'td', { colspan: String( TABLE_COLUMN_COUNT ) } );
		var body = SCApp.el( 'div', { class: 'sc-quote-detail', text: 'Loading…' } );

		td.appendChild( body );
		tr.appendChild( td );

		SCApp.request( { path: '/service-crew/v1/bookings/' + booking.id + '/quote' } ).then( function ( quote ) {
			body.innerHTML = '';
			body.appendChild( renderQuoteDetail( quote ) );
		} );

		return tr;
	}

	function renderQuoteDetail( quote ) {
		var wrap = SCApp.el( 'div', { class: 'sc-quote-detail__grid' } );

		wrap.appendChild( buildQuoteInfoCard( quote ) );

		if ( 'quote_rejected' === quote.status ) {
			var rejected = SCApp.el( 'div', { class: 'sc-quote-detail__card' } );
			rejected.appendChild( SCApp.el( 'h4', { text: 'Rejected' } ) );
			rejected.appendChild( SCApp.el( 'p', { class: 'sc-help', style: 'margin: 0;', text: quote.reject_reason || 'No reason given.' } ) );
			wrap.appendChild( rejected );

			return wrap;
		}

		if ( -1 !== QUOTE_MANAGEABLE_STATUSES.indexOf( quote.status ) ) {
			wrap.appendChild( buildPricingCard( quote ) );
			wrap.appendChild( buildRejectCard( quote ) );
		}

		if ( 'quoted' === quote.status ) {
			wrap.appendChild( buildDepositLinkCard( quote ) );
		} else if ( -1 === QUOTE_MANAGEABLE_STATUSES.indexOf( quote.status ) && 'quote_rejected' !== quote.status ) {
			var paid = SCApp.el( 'div', { class: 'sc-quote-detail__card' } );
			paid.appendChild( SCApp.el( 'h4', { text: 'Deposit' } ) );
			paid.appendChild( SCApp.el( 'p', { class: 'sc-help', style: 'margin: 0;', text: 'Deposit link already sent — status: ' + ( STATUS_LABELS[ quote.status ] || quote.status ) + '.' } ) );
			wrap.appendChild( paid );
		}

		return wrap;
	}

	function buildQuoteInfoCard( quote ) {
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card' } );

		card.appendChild( SCApp.el( 'h4', { text: quote.quote_title || 'Quote request' } ) );
		card.appendChild( SCApp.el( 'p', { style: 'margin: 0 0 10px;', text: quote.description || '(No description given.)' } ) );

		card.appendChild( SCApp.el( 'p', { class: 'sc-help', style: 'margin: 0;', text: 'Address: ' + ( quote.address || '—' ) + ( quote.zip ? ', ' + quote.zip : '' ) } ) );
		card.appendChild( SCApp.el( 'p', { class: 'sc-help', style: 'margin: 0;', text: 'Preferred: ' + formatDate( quote.preferred_date ) + ( quote.arrival_window ? ' · ' + quote.arrival_window : '' ) } ) );

		if ( quote.photos && quote.photos.length ) {
			var photoList = SCApp.el( 'div', { class: 'sc-quote-detail__photos' } );

			quote.photos.forEach( function ( photo ) {
				photoList.appendChild( SCApp.el( 'a', { href: photo.url, target: '_blank', rel: 'noopener noreferrer' }, [
					SCApp.el( 'img', { src: photo.url, alt: '' } ),
				] ) );
			} );

			card.appendChild( photoList );
		}

		return card;
	}

	function buildPricingCard( quote ) {
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card' } );
		card.appendChild( SCApp.el( 'h4', { text: 'quoted' === quote.status ? 'Update price' : 'Set price' } ) );

		var serviceSelect = SCApp.el( 'select', { class: 'sc-input' } );
		serviceSelect.appendChild( SCApp.el( 'option', { value: '0', text: '— No specific service —' } ) );
		serviceSelect.disabled = true;

		loadServices().then( function ( services ) {
			services.forEach( function ( service ) {
				var option = SCApp.el( 'option', { value: String( service.id ), text: service.title } );
				if ( Number( quote.service_id ) === service.id ) {
					option.setAttribute( 'selected', 'selected' );
				}
				serviceSelect.appendChild( option );
			} );
			serviceSelect.disabled = false;
		} );

		var priceInput = SCApp.el( 'input', { type: 'number', min: '0', step: '0.01', class: 'sc-input', value: Number( quote.customer_total ) ? quote.customer_total : '' } );
		var durationInput = SCApp.el( 'input', { type: 'number', min: '1', step: '1', class: 'sc-input', value: quote.duration_days || 1 } );
		var crewInput = SCApp.el( 'input', { type: 'number', min: '1', step: '1', class: 'sc-input', value: quote.crew_needed || 1 } );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Service (optional)' } ), serviceSelect ] ) );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Total price ($)' } ), priceInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Duration (days)' } ), durationInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Crew needed' } ), crewInput ] ),
		] ) );

		var errorEl = SCApp.el( 'p', { class: 'sc-notice', style: 'display: none;' } );
		card.appendChild( errorEl );

		var saveButton = SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--primary',
			text: 'quoted' === quote.status ? 'Update price' : 'Save price & mark quoted',
			onClick: function () {
				errorEl.style.display = 'none';
				saveButton.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + quote.id + '/quote',
					method: 'PUT',
					data: {
						service_id: Number( serviceSelect.value ),
						customer_total: Number( priceInput.value ),
						duration_days: Number( durationInput.value ),
						crew_needed: Number( crewInput.value ),
					},
				} ).then( function () {
					SCApp.toast( 'Quote priced.' );
					load();
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not save this quote.';
					errorEl.style.display = '';
					saveButton.disabled = false;
				} );
			},
		} );

		card.appendChild( saveButton );

		return card;
	}

	function buildRejectCard( quote ) {
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card' } );
		card.appendChild( SCApp.el( 'h4', { text: 'Reject this quote' } ) );

		var reasonInput = SCApp.el( 'textarea', { class: 'sc-input', rows: '2', placeholder: 'Reason (shared internally, not emailed to the customer yet)' } );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ reasonInput ] ) );

		var errorEl = SCApp.el( 'p', { class: 'sc-notice', style: 'display: none;' } );
		card.appendChild( errorEl );

		card.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--danger',
			text: 'Reject quote',
			onClick: function ( event ) {
				if ( ! reasonInput.value.trim() ) {
					errorEl.textContent = 'Please give a reason.';
					errorEl.style.display = '';
					return;
				}

				event.target.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + quote.id + '/quote-reject',
					method: 'PUT',
					data: { reason: reasonInput.value },
				} ).then( function () {
					SCApp.toast( 'Quote rejected.' );
					load();
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not reject this quote.';
					errorEl.style.display = '';
					event.target.disabled = false;
				} );
			},
		} ) );

		return card;
	}

	function buildDepositLinkCard( quote ) {
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card' } );
		card.appendChild( SCApp.el( 'h4', { text: 'Send deposit link' } ) );
		card.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'Deposit due: ' + formatMoney( quote.deposit_amount ) + '. Paying it confirms the booking.' } ) );

		var dateInput = SCApp.el( 'input', { type: 'date', class: 'sc-input', value: quote.preferred_date || '' } );
		var windowSelect = SCApp.el( 'select', { class: 'sc-input' } );
		windowSelect.disabled = true;

		loadSchedulingSettings().then( function ( settings ) {
			( settings.arrival_windows || [] ).forEach( function ( win ) {
				var option = SCApp.el( 'option', { value: win.label, text: win.label + ' (' + win.start + '–' + win.end + ')' } );
				if ( win.label === quote.arrival_window ) {
					option.setAttribute( 'selected', 'selected' );
				}
				windowSelect.appendChild( option );
			} );
			windowSelect.disabled = false;
		} );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Scheduled date' } ), dateInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Arrival window' } ), windowSelect ] ),
		] ) );

		var errorEl = SCApp.el( 'p', { class: 'sc-notice', style: 'display: none;' } );
		card.appendChild( errorEl );

		var linkRow = SCApp.el( 'div', { class: 'sc-quote-detail__link-row', style: 'display: none;' } );
		var linkInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', readonly: 'readonly' } );
		var copyButton = SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary',
			text: 'Copy',
			onClick: function () {
				linkInput.select();
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( linkInput.value ).then( function () {
						SCApp.toast( 'Link copied.' );
					} );
				} else {
					document.execCommand( 'copy' );
					SCApp.toast( 'Link copied.' );
				}
			},
		} );
		linkRow.appendChild( linkInput );
		linkRow.appendChild( copyButton );
		card.appendChild( linkRow );

		var expiresEl = SCApp.el( 'p', { class: 'sc-help', style: 'display: none;' } );
		card.appendChild( expiresEl );

		card.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--primary',
			text: 'Generate deposit link',
			onClick: function ( event ) {
				if ( ! dateInput.value ) {
					errorEl.textContent = 'Please choose a scheduled date.';
					errorEl.style.display = '';
					return;
				}

				errorEl.style.display = 'none';
				event.target.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + quote.id + '/quote-deposit-link',
					method: 'POST',
					data: { date: dateInput.value, arrival_window: windowSelect.value },
				} ).then( function ( result ) {
					linkInput.value = result.url;
					linkRow.style.display = '';
					expiresEl.textContent = 'Expires ' + formatExpiresAt( result.expires_at ) + '.';
					expiresEl.style.display = '';
					event.target.disabled = false;
					SCApp.toast( 'Deposit link generated.' );
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not generate a deposit link.';
					errorEl.style.display = '';
					event.target.disabled = false;
				} );
			},
		} ) );

		return card;
	}

	/* ---- Refund form ---------------------------------------------------- */

	function buildRefundDetailRow( booking ) {
		var tr = SCApp.el( 'tr', { class: 'sc-quote-detail-row' } );
		var td = SCApp.el( 'td', { colspan: String( TABLE_COLUMN_COUNT ) } );
		var wrap = SCApp.el( 'div', { class: 'sc-quote-detail__grid' } );

		wrap.appendChild( renderRefundCard( booking ) );
		td.appendChild( wrap );
		tr.appendChild( td );

		return tr;
	}

	function renderRefundCard( booking ) {
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card' } );
		card.appendChild( SCApp.el( 'h4', { text: 'Refund this booking' } ) );
		card.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			style: 'margin: 0 0 10px;',
			text: 'Paid so far: ' + formatMoney( booking.amount_paid ) + '. Leave the amount blank for a full refund of whatever remains.',
		} ) );

		var amountInput = SCApp.el( 'input', { type: 'number', min: '0', step: '0.01', class: 'sc-input', placeholder: Number( booking.amount_paid ).toFixed( 2 ) } );
		var reasonInput = SCApp.el( 'textarea', { class: 'sc-input', rows: '2', placeholder: 'Reason (required)' } );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Amount to refund ($, optional)' } ), amountInput ] ) );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Reason' } ), reasonInput ] ) );

		var errorEl = SCApp.el( 'p', { class: 'sc-notice', style: 'display: none;' } );
		card.appendChild( errorEl );

		card.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--danger',
			text: 'Process refund',
			onClick: function ( event ) {
				if ( ! reasonInput.value.trim() ) {
					errorEl.textContent = 'Please give a reason.';
					errorEl.style.display = '';
					return;
				}

				errorEl.style.display = 'none';
				event.target.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + booking.id + '/refund',
					method: 'POST',
					data: {
						amount: amountInput.value ? Number( amountInput.value ) : 0,
						reason: reasonInput.value,
					},
				} ).then( function ( result ) {
					booking.amount_paid = result.amount_paid;
					refundExpandedId = null;
					SCApp.toast( 'Refund processed.' );
					render( bookingsCache );
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not process this refund.';
					errorEl.style.display = '';
					event.target.disabled = false;
				} );
			},
		} ) );

		return card;
	}

	/* ---- Balance collection form -------------------------------------- */

	function buildBalanceDetailRow( booking ) {
		var tr = SCApp.el( 'tr', { class: 'sc-quote-detail-row' } );
		var td = SCApp.el( 'td', { colspan: String( TABLE_COLUMN_COUNT ) } );
		var wrap = SCApp.el( 'div', { class: 'sc-quote-detail__grid' } );

		wrap.appendChild( renderBalanceCard( booking ) );
		td.appendChild( wrap );
		tr.appendChild( td );

		return tr;
	}

	function renderBalanceCard( booking ) {
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card' } );
		card.appendChild( SCApp.el( 'h4', { text: 'Collect remaining balance' } ) );

		var remaining = Math.max( 0, Number( booking.customer_total ) - Number( booking.amount_paid ) );
		card.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			style: 'margin: 0 0 10px;',
			text: 'Balance due: ' + formatMoney( remaining ) + ' (' + formatMoney( booking.amount_paid ) + ' of ' + formatMoney( booking.customer_total ) + ' paid so far).',
		} ) );

		var errorEl = SCApp.el( 'p', { class: 'sc-notice', style: 'display: none;' } );

		var noteInput = SCApp.el( 'textarea', { class: 'sc-input', rows: '2', placeholder: 'Note (optional) — e.g. "paid by check #123"' } );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Collected outside the system' } ), noteInput ] ) );

		var collectButton = SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--primary',
			text: 'Mark collected',
			onClick: function ( event ) {
				errorEl.style.display = 'none';
				event.target.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + booking.id + '/balance/collect',
					method: 'POST',
					data: { note: noteInput.value },
				} ).then( function ( result ) {
					booking.amount_paid = result.amount_paid;
					balanceExpandedId = null;
					SCApp.toast( 'Balance marked collected.' );
					render( bookingsCache );
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not mark this balance collected.';
					errorEl.style.display = '';
					event.target.disabled = false;
				} );
			},
		} );
		card.appendChild( collectButton );

		card.appendChild( SCApp.el( 'p', { class: 'sc-help', style: 'margin: 14px 0 6px;', text: 'Or send a payment link instead:' } ) );

		var linkRow = SCApp.el( 'div', { class: 'sc-quote-detail__link-row', style: 'display: none;' } );
		var linkInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', readonly: 'readonly' } );
		var copyButton = SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary',
			text: 'Copy',
			onClick: function () {
				linkInput.select();
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( linkInput.value ).then( function () { SCApp.toast( 'Link copied.' ); } );
				} else {
					document.execCommand( 'copy' );
					SCApp.toast( 'Link copied.' );
				}
			},
		} );
		linkRow.appendChild( linkInput );
		linkRow.appendChild( copyButton );
		card.appendChild( linkRow );

		var expiresEl = SCApp.el( 'p', { class: 'sc-help', style: 'display: none;' } );
		card.appendChild( expiresEl );

		card.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary',
			text: 'Generate payment link',
			onClick: function ( event ) {
				errorEl.style.display = 'none';
				event.target.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + booking.id + '/balance/link',
					method: 'POST',
				} ).then( function ( result ) {
					linkInput.value = result.url;
					linkRow.style.display = '';
					expiresEl.textContent = 'Expires ' + formatExpiresAt( result.expires_at ) + '.';
					expiresEl.style.display = '';
					event.target.disabled = false;
					SCApp.toast( 'Payment link generated.' );
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not generate a payment link.';
					errorEl.style.display = '';
					event.target.disabled = false;
				} );
			},
		} ) );

		card.appendChild( errorEl );

		return card;
	}

	/* ---- Reschedule form ------------------------------------------------ */

	function buildRescheduleDetailRow( booking ) {
		var tr = SCApp.el( 'tr', { class: 'sc-quote-detail-row' } );
		var td = SCApp.el( 'td', { colspan: String( TABLE_COLUMN_COUNT ) } );
		var wrap = SCApp.el( 'div', { class: 'sc-quote-detail__grid' } );
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card', text: 'Loading…' } );

		wrap.appendChild( card );
		td.appendChild( wrap );
		tr.appendChild( td );

		loadSchedulingSettings().then( function ( settings ) {
			card.innerHTML = '';
			card.appendChild( renderRescheduleForm( booking, settings ) );
		} );

		return tr;
	}

	function renderRescheduleForm( booking, settings ) {
		var card = SCApp.el( 'div' );
		card.appendChild( SCApp.el( 'h4', { text: 'Reschedule this booking' } ) );
		card.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			style: 'margin: 0 0 10px;',
			text: 'Conflicts are shown as a warning, not a block — a new date/window is applied either way.',
		} ) );

		var dateInput = SCApp.el( 'input', { type: 'date', class: 'sc-input', value: booking.preferred_date || '' } );
		var windowSelect = SCApp.el( 'select', { class: 'sc-input' } );

		( settings.arrival_windows || [] ).forEach( function ( win ) {
			var option = SCApp.el( 'option', { value: win.label, text: win.label + ' (' + win.start + '–' + win.end + ')' } );
			if ( win.label === booking.arrival_window ) {
				option.setAttribute( 'selected', 'selected' );
			}
			windowSelect.appendChild( option );
		} );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'New date' } ), dateInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'New arrival window' } ), windowSelect ] ),
		] ) );

		var errorEl = SCApp.el( 'p', { class: 'sc-notice', style: 'display: none;' } );
		card.appendChild( errorEl );

		card.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--primary',
			text: 'Save new date & window',
			onClick: function ( event ) {
				if ( ! dateInput.value ) {
					errorEl.textContent = 'Please choose a date.';
					errorEl.style.display = '';
					return;
				}

				errorEl.style.display = 'none';
				event.target.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + booking.id + '/reschedule',
					method: 'PUT',
					data: { date: dateInput.value, arrival_window: windowSelect.value },
				} ).then( function ( result ) {
					booking.preferred_date = result.date;
					booking.arrival_window = result.arrival_window;
					rescheduleExpandedId = null;

					var warnings = [];
					if ( result.slot_taken_warning ) {
						warnings.push( 'the new date/window already has another booking' );
					}
					if ( result.assignee_unavailable ) {
						warnings.push( 'an assigned crew member isn’t marked available on the new date' );
					}

					SCApp.toast( warnings.length ? 'Rescheduled — warning: ' + warnings.join( '; ' ) + '.' : 'Booking rescheduled.' );
					render( bookingsCache );
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not reschedule this booking.';
					errorEl.style.display = '';
					event.target.disabled = false;
				} );
			},
		} ) );

		return card;
	}

	/* ---- Crew assignment form --------------------------------------- */

	function buildAssignmentDetailRow( booking ) {
		var tr = SCApp.el( 'tr', { class: 'sc-quote-detail-row' } );
		var td = SCApp.el( 'td', { colspan: String( TABLE_COLUMN_COUNT ) } );
		var wrap = SCApp.el( 'div', { class: 'sc-quote-detail__grid' } );
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card', text: 'Loading…' } );

		wrap.appendChild( card );
		td.appendChild( wrap );
		tr.appendChild( td );

		Promise.all( [
			loadEmployees(),
			SCApp.request( { path: '/service-crew/v1/bookings/' + booking.id + '/suggestions' } ),
			SCApp.request( { path: '/service-crew/v1/bookings/' + booking.id + '/assignment' } ),
			SCApp.request( { path: '/service-crew/v1/bookings/' + booking.id + '/overtime' } ),
			loadSchedulingSettings(),
		] ).then( function ( results ) {
			card.innerHTML = '';
			card.appendChild( renderAssignmentForm(
				booking,
				results[ 0 ] || [],
				results[ 1 ] || [],
				results[ 2 ] || { crew: [], lead_crew_id: null },
				results[ 3 ] || [],
				results[ 4 ] || { overtime: { allowed: false, max_hours_per_day: 0 } }
			) );
		} );

		return tr;
	}

	function renderAssignmentForm( booking, employees, suggestions, current, overtimeRecords, schedulingSettings ) {
		var wrap = SCApp.el( 'div' );
		wrap.appendChild( SCApp.el( 'h4', { text: 'Assign crew' } ) );
		wrap.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			style: 'margin: 0 0 10px;',
			text: 'Ordered by suggested fit (nearest, available that day). Confirm availability by phone/text before saving — saving is immediately final, there is no accept step.',
		} ) );

		if ( ! employees.length ) {
			wrap.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'No employee crew records yet — add one on the Crew screen first.' } ) );
			return wrap;
		}

		var suggestionRank = {};
		suggestions.forEach( function ( suggestion, index ) {
			suggestionRank[ suggestion.id ] = { rank: index, distance: suggestion.distance_miles };
		} );

		var currentCrewIds = ( current.crew || [] ).map( function ( row ) { return row.crew_id; } );

		var sortedEmployees = employees.slice().sort( function ( a, b ) {
			var rankA = suggestionRank[ a.id ] ? suggestionRank[ a.id ].rank : Infinity;
			var rankB = suggestionRank[ b.id ] ? suggestionRank[ b.id ].rank : Infinity;
			return rankA - rankB;
		} );

		var checkboxes = {};
		var leadRadios = {};
		var list = SCApp.el( 'div', { class: 'sc-assign-list' } );

		sortedEmployees.forEach( function ( employee ) {
			var checkbox = SCApp.el( 'input', { type: 'checkbox' } );
			checkbox.checked = -1 !== currentCrewIds.indexOf( employee.id );
			checkboxes[ employee.id ] = checkbox;

			var leadRadio = SCApp.el( 'input', { type: 'radio', name: 'sc-assign-lead-' + booking.id } );
			leadRadio.checked = employee.id === current.lead_crew_id;
			leadRadio.disabled = ! checkbox.checked;
			leadRadios[ employee.id ] = leadRadio;

			checkbox.addEventListener( 'change', function () {
				leadRadio.disabled = ! checkbox.checked;
				if ( ! checkbox.checked ) {
					leadRadio.checked = false;
				}
			} );

			var suggestion = suggestionRank[ employee.id ];
			var distanceLabel = suggestion && null !== suggestion.distance
				? suggestion.distance + ' mi'
				: ( suggestion ? 'Distance unknown' : 'Not suggested for this date' );

			list.appendChild( SCApp.el( 'label', { class: 'sc-assign-row' }, [
				checkbox,
				SCApp.el( 'span', { class: 'sc-assign-row__name', text: employee.name } ),
				SCApp.el( 'span', { class: 'sc-assign-row__meta', text: distanceLabel } ),
				SCApp.el( 'span', { class: 'sc-assign-row__lead' }, [
					leadRadio,
					SCApp.el( 'span', { text: 'Lead' } ),
				] ),
			] ) );
		} );

		wrap.appendChild( list );

		var errorEl = SCApp.el( 'p', { class: 'sc-notice', style: 'display: none;' } );
		wrap.appendChild( errorEl );

		wrap.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--primary',
			text: 'Save assignment',
			onClick: function ( event ) {
				var crewIds    = [];
				var leadCrewId = null;

				sortedEmployees.forEach( function ( employee ) {
					if ( checkboxes[ employee.id ].checked ) {
						crewIds.push( employee.id );
					}
					if ( leadRadios[ employee.id ].checked ) {
						leadCrewId = employee.id;
					}
				} );

				if ( ! crewIds.length ) {
					errorEl.textContent = 'Please choose at least one crew member.';
					errorEl.style.display = '';
					return;
				}

				if ( ! leadCrewId ) {
					errorEl.textContent = 'Please mark one crew member as lead.';
					errorEl.style.display = '';
					return;
				}

				errorEl.style.display = 'none';
				event.target.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + booking.id + '/assignment',
					method: 'PUT',
					data: { crew_ids: crewIds, lead_crew_id: leadCrewId },
				} ).then( function () {
					booking.status = 'assigned';
					assignExpandedId = null;
					SCApp.toast( 'Crew assigned.' );
					render( bookingsCache );
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not save this assignment.';
					errorEl.style.display = '';
					event.target.disabled = false;
				} );
			},
		} ) );

		wrap.appendChild( renderOvertimeSection( booking, current, overtimeRecords, schedulingSettings ) );

		return wrap;
	}

	/**
	 * Overtime is approved per active assignment, independent of the
	 * roster-save button above — admin-only, no crew-initiated request step
	 * (V1 scope, see Service_Crew_Overtime's own docblock). Hidden entirely
	 * when Settings' "Allow overtime" toggle is off, same gate the server
	 * itself enforces.
	 */
	function renderOvertimeSection( booking, current, overtimeRecords, schedulingSettings ) {
		var section = SCApp.el( 'div', { style: 'margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--sc-border, #e2e8f0);' } );
		section.appendChild( SCApp.el( 'h4', { text: 'Overtime' } ) );

		var overtimeSettings = schedulingSettings.overtime || { allowed: false, max_hours_per_day: 0 };

		if ( ! overtimeSettings.allowed ) {
			section.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'Overtime is turned off — enable it on the Settings screen to approve overtime here.' } ) );
			return section;
		}

		var activeCrew = current.crew || [];

		if ( ! activeCrew.length ) {
			section.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'Assign crew first, then approve overtime per person here.' } ) );
			return section;
		}

		activeCrew.forEach( function ( member ) {
			section.appendChild( renderOvertimeRow( booking, member, overtimeSettings ) );
		} );

		if ( overtimeRecords.length ) {
			section.appendChild( SCApp.el( 'p', { class: 'sc-help', style: 'margin: 12px 0 4px;', text: 'Already recorded:' } ) );

			overtimeRecords.forEach( function ( record ) {
				var surchargeText = 'none' === record.surcharge_decision || 0 === Number( record.surcharge_amount )
					? ( 'waived' === record.surcharge_decision ? 'surcharge waived' : 'no surcharge' )
					: 'surcharge ' + formatMoney( record.surcharge_amount ) + ' (' + record.surcharge_decision + ')';

				section.appendChild( SCApp.el( 'p', {
					class: 'sc-help',
					style: 'margin: 0 0 4px;',
					text: record.crew_name + ': ' + record.hours + 'h, ' + surchargeText + ( record.note ? ' — "' + record.note + '"' : '' ),
				} ) );
			} );
		}

		return section;
	}

	function renderOvertimeRow( booking, member, overtimeSettings ) {
		var hoursInput = SCApp.el( 'input', { type: 'number', min: '0.5', step: '0.5', class: 'sc-input', placeholder: overtimeSettings.max_hours_per_day ? 'max ' + overtimeSettings.max_hours_per_day + 'h' : 'hours' } );

		var surchargeSelect = SCApp.el( 'select', { class: 'sc-input' }, [
			SCApp.el( 'option', { value: 'none', text: 'No surcharge' } ),
			SCApp.el( 'option', { value: 'applied', text: 'Apply surcharge' } ),
			SCApp.el( 'option', { value: 'waived', text: 'Waive surcharge' } ),
		] );

		var amountInput = SCApp.el( 'input', { type: 'number', min: '0', step: '0.01', class: 'sc-input', placeholder: '$ amount', style: 'display: none;' } );

		surchargeSelect.addEventListener( 'change', function () {
			amountInput.style.display = 'applied' === surchargeSelect.value ? '' : 'none';
		} );

		var errorEl = SCApp.el( 'p', { class: 'sc-notice', style: 'display: none; margin: 4px 0 10px;' } );

		var approveButton = SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary sc-btn--small',
			text: 'Approve',
			onClick: function ( event ) {
				var hours = Number( hoursInput.value );
				if ( ! hours || hours <= 0 ) {
					errorEl.textContent = 'Please enter the overtime hours.';
					errorEl.style.display = '';
					return;
				}

				errorEl.style.display = 'none';
				event.target.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + booking.id + '/overtime',
					method: 'POST',
					data: {
						assignment_id: member.assignment_id,
						hours: hours,
						surcharge_decision: surchargeSelect.value,
						surcharge_amount: amountInput.value ? Number( amountInput.value ) : 0,
					},
				} ).then( function () {
					SCApp.toast( 'Overtime approved for ' + member.name + '.' );
					render( bookingsCache );
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not approve overtime.';
					errorEl.style.display = '';
					event.target.disabled = false;
				} );
			},
		} );

		var wrap = SCApp.el( 'div', { style: 'margin-bottom: 8px;' } );
		wrap.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: member.name } ), hoursInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Surcharge' } ), surchargeSelect ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: ' ' } ), amountInput ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: ' ' } ), approveButton ] ),
		] ) );
		wrap.appendChild( errorEl );

		return wrap;
	}

	/* ---- Notes timeline -------------------------------------------- */

	function buildNotesDetailRow( booking ) {
		var tr = SCApp.el( 'tr', { class: 'sc-quote-detail-row' } );
		var td = SCApp.el( 'td', { colspan: String( TABLE_COLUMN_COUNT ) } );
		var wrap = SCApp.el( 'div', { class: 'sc-quote-detail__grid' } );
		var card = SCApp.el( 'div', { class: 'sc-quote-detail__card', text: 'Loading…' } );

		wrap.appendChild( card );
		td.appendChild( wrap );
		tr.appendChild( td );

		SCApp.request( { path: '/service-crew/v1/bookings/' + booking.id + '/notes' } ).then( function ( notes ) {
			card.innerHTML = '';
			card.appendChild( renderNotesCard( booking, notes || [] ) );
		} );

		return tr;
	}

	function renderNotesCard( booking, notes ) {
		var card = SCApp.el( 'div' );
		card.appendChild( SCApp.el( 'h4', { text: 'Notes' } ) );

		var list = SCApp.el( 'div', { class: 'sc-notes-list' } );

		if ( ! notes.length ) {
			list.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'No notes yet.' } ) );
		}

		notes.forEach( function ( note ) {
			var entry = SCApp.el( 'div', { class: 'sc-notes-entry' } );

			var typeLabel = NOTE_TYPE_LABELS[ note.note_type ];
			var who = note.author_name || ( typeLabel ? '' : 'System' );

			entry.appendChild( SCApp.el( 'div', { class: 'sc-notes-entry__meta', text: [ typeLabel, who, formatCreatedAt( note.created_at ) ].filter( Boolean ).join( ' · ' ) } ) );

			if ( note.body ) {
				entry.appendChild( SCApp.el( 'p', { class: 'sc-notes-entry__body', text: note.body } ) );
			}

			if ( note.photo_url ) {
				var link = SCApp.el( 'a', { href: note.photo_url, target: '_blank', rel: 'noopener noreferrer' } );
				link.appendChild( SCApp.el( 'img', { src: note.photo_url, class: 'sc-notes-entry__photo', alt: '' } ) );
				entry.appendChild( link );
			}

			list.appendChild( entry );
		} );

		card.appendChild( list );

		var noteInput = SCApp.el( 'textarea', { class: 'sc-input', rows: '2', placeholder: 'Add a note…' } );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ noteInput ] ) );

		var errorEl = SCApp.el( 'p', { class: 'sc-notice', style: 'display: none;' } );
		card.appendChild( errorEl );

		card.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--primary sc-btn--small',
			text: 'Add note',
			onClick: function ( event ) {
				if ( ! noteInput.value.trim() ) {
					errorEl.textContent = 'Please enter a note.';
					errorEl.style.display = '';
					return;
				}

				errorEl.style.display = 'none';
				event.target.disabled = true;

				SCApp.request( {
					path: '/service-crew/v1/bookings/' + booking.id + '/notes',
					method: 'POST',
					data: { body: noteInput.value },
				} ).then( function () {
					SCApp.toast( 'Note added.' );
					render( bookingsCache );
				} ).catch( function ( error ) {
					errorEl.textContent = ( error && error.message ) || 'Could not add this note.';
					errorEl.style.display = '';
					event.target.disabled = false;
				} );
			},
		} ) );

		return card;
	}

	function render( bookings ) {
		root.innerHTML = '';

		var panel = SCApp.el( 'div', { class: 'sc-app-panel sc-app-panel--bookings' } );

		panel.appendChild( SCApp.el( 'h2', { text: 'Recent bookings' } ) );
		panel.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			text: 'The ' + bookings.length + ' most recent bookings, newest first.',
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
			SCApp.el( 'th', { text: 'Actions' } ),
		] ) );
		table.appendChild( thead );

		var tbody = SCApp.el( 'tbody' );
		bookings.forEach( function ( booking ) {
			tbody.appendChild( buildRow( booking ) );

			if ( booking.id === expandedId ) {
				tbody.appendChild( buildQuoteDetailRow( booking ) );
			}

			if ( booking.id === refundExpandedId ) {
				tbody.appendChild( buildRefundDetailRow( booking ) );
			}

			if ( booking.id === assignExpandedId ) {
				tbody.appendChild( buildAssignmentDetailRow( booking ) );
			}

			if ( booking.id === balanceExpandedId ) {
				tbody.appendChild( buildBalanceDetailRow( booking ) );
			}

			if ( booking.id === rescheduleExpandedId ) {
				tbody.appendChild( buildRescheduleDetailRow( booking ) );
			}

			if ( booking.id === notesExpandedId ) {
				tbody.appendChild( buildNotesDetailRow( booking ) );
			}
		} );
		table.appendChild( tbody );

		panel.appendChild( table );
		root.appendChild( panel );
	}

	load();
} )();
