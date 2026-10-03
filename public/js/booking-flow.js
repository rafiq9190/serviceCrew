/**
 * [service_crew_booking]: two top-level tabs — "Instant Booking" (the
 * four-step animated flow: Service -> Date & Time -> Payment -> Thank you)
 * and "Request a Quote" (the plan's out-of-band-quote form: photos, title,
 * description, preferred date/window, address, contact — no service
 * selection, no payment). Reads its data from the
 * <script type="application/json" class="sc-booking-flow-data"> tag
 * class-service-crew-booking-shortcode.php embeds in each .sc-booking-flow
 * instance — the same tree/pricing payload [service_crew_services] uses,
 * plus scheduling settings (business hours, holidays, arrival windows) both
 * tabs draw from.
 *
 * All four step panels of the Instant Booking tab are built and mounted up
 * front; navigating between them only toggles which one is visible (no panel
 * is ever torn down and rebuilt), so nothing a customer has already entered —
 * a date pick, their name/email — is lost by stepping back and forward.
 *
 * The quote form posts multipart/form-data (for its optional photo uploads)
 * to POST /service-crew/v1/quotes (class-service-crew-quotes-controller.php,
 * class-service-crew-quotes.php) — a separate, narrower path from the
 * instant-booking POST below it: no service/pricing, no Stripe redirect, and
 * the server does its own rate-limiting/honeypot/photo-hardening rather than
 * trusting anything from the client.
 *
 * Step 3 posts to the real `POST /bookings` endpoint
 * (class-service-crew-bookings-controller.php) and redirects to a real
 * Stripe Checkout session — see class-service-crew-bookings.php's docblock
 * for exactly what a real booking here does and doesn't do yet (one service
 * per booking, deposit-only, no capacity check). Since Checkout is a real
 * redirect away from this page, Step 4 is only ever reached by the customer
 * coming *back* from Stripe (checkReturnFromStripe()) — the browser never
 * transitions from Step 3 to Step 4 directly.
 *
 * Remaining preview-scope pieces, by explicit agreement (see
 * ServiceCrew-Tasks.md's "Built beyond the plan" entry for this shortcode):
 *  - Step 1 embeds window.SCBookingWidget.init() (booking-widget.js)
 *    directly rather than a second copy of the tree/cart/pricing logic; its
 *    multi-item cart is still browsing-preview-only; a real booking always
 *    books the single item left in it, enforced before Step 2.
 *  - Step 2 only greys out non-business days and admin holidays
 *    (Service_Crew_Settings) — no real pooled-crew-capacity check exists
 *    yet (Service_Crew_Capacity, Phase 1b-2).
 */
( function () {
	'use strict';

	var STEPS = [
		{ label: 'Service' },
		{ label: 'Date & Time' },
		{ label: 'Payment' },
		{ label: 'Done' },
	];

	var MONTH_NAMES = [
		'January', 'February', 'March', 'April', 'May', 'June',
		'July', 'August', 'September', 'October', 'November', 'December',
	];

	var WEEKDAY_KEYS = [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ];

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

	function pad2( n ) {
		return n < 10 ? '0' + n : String( n );
	}

	function toISODate( year, month, day ) {
		return year + '-' + pad2( month + 1 ) + '-' + pad2( day );
	}

	function formatDisplayDate( iso ) {
		if ( ! iso ) {
			return '';
		}

		var date = new Date( iso + 'T00:00:00' );

		if ( 'function' === typeof date.toLocaleDateString ) {
			return date.toLocaleDateString( undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' } );
		}

		return iso;
	}

	function initFlow( root ) {
		var dataEl = root.querySelector( '.sc-booking-flow-data' );
		var payload;

		try {
			payload = JSON.parse( dataEl ? dataEl.textContent : '{}' );
		} catch ( e ) {
			return;
		}

		if ( ! window.SCBookingWidget ) {
			return;
		}

		var W = window.SCBookingWidget;

		var taxRatePercent      = Number( payload.taxRatePercent ) || 0;
		var taxMode             = payload.taxMode || 'exclusive';
		var discountTiers       = payload.discountTiers || [];
		var minimumDepositTiers = payload.minimumDepositTiers || [];
		var scheduling          = payload.schedulingSettings || { business_hours: {}, holidays: [], arrival_windows: [] };

		var state = {
			stepIndex: 0,
			cart: [],
			selectedDate: null,
			selectedWindow: null,
			isEmergency: false,
			bookedWindows: [],
			customerName: '',
			customerEmail: '',
			customerPhone: '',
			customerAddress: '',
			customerZip: '',
			marketingOptIn: false,
		};

		/**
		 * Client-side estimate of Service_Crew_Pricing::calculate_emergency_surcharge()
		 * — 'flat' is a fixed fee, 'percent' is a share of the already
		 * discounted+taxed total. The server recomputes this from scratch
		 * against Service_Crew_Settings when the booking is actually created.
		 *
		 * @param {number} amount Discounted, taxed total to base a percentage surcharge on.
		 * @return {number}
		 */
		function computeEmergencySurcharge( amount ) {
			var surcharge = scheduling.emergency_surcharge || { enabled: false, type: 'percent', amount: 0 };

			if ( ! surcharge.enabled ) {
				return 0;
			}

			return 'flat' === surcharge.type
				? ( Number( surcharge.amount ) || 0 )
				: amount * ( ( Number( surcharge.amount ) || 0 ) / 100 );
		}

		var widgetApi     = null;
		var paymentPanel  = null;
		var thankYouPanel = null;

		var shell           = el( 'div', { class: 'sc-flow-shell' } );
		var stepper         = el( 'div', { class: 'sc-flow-stepper' } );
		// Mobile-only (see booking-flow.css): the stepper's circles shrink and
		// its text labels hide under 600px, replaced by this single line
		// naming the current step — renderStepper() below keeps it in sync.
		var stepperCaption  = el( 'div', { class: 'sc-flow-stepper__caption' } );
		var panelsEl        = el( 'div', { class: 'sc-flow-panels' } );
		var footer          = el( 'div', { class: 'sc-flow-footer' } );

		shell.appendChild( stepper );
		shell.appendChild( stepperCaption );
		shell.appendChild( panelsEl );
		shell.appendChild( footer );

		/* ---- Tabs: Instant Booking / Request a Quote ------------------- */

		var tabsNav        = el( 'div', { class: 'sc-flow-tabs', role: 'tablist' } );
		var tabContentWrap = el( 'div', { class: 'sc-flow-tab-panels' } );
		var instantTabPane = el( 'div', { class: 'sc-flow-tab-panel is-active' } );
		var quoteTabPane   = el( 'div', { class: 'sc-flow-tab-panel' } );

		var instantTabBtn = el( 'button', { type: 'button', class: 'sc-flow-tab is-active', text: 'Instant Booking' } );
		var quoteTabBtn   = el( 'button', { type: 'button', class: 'sc-flow-tab', text: 'Request a Quote' } );

		function activateTab( name ) {
			var isInstant = 'instant' === name;

			instantTabBtn.classList.toggle( 'is-active', isInstant );
			quoteTabBtn.classList.toggle( 'is-active', ! isInstant );
			instantTabPane.classList.toggle( 'is-active', isInstant );
			quoteTabPane.classList.toggle( 'is-active', ! isInstant );
		}

		instantTabBtn.addEventListener( 'click', function () { activateTab( 'instant' ); } );
		quoteTabBtn.addEventListener( 'click', function () { activateTab( 'quote' ); } );

		tabsNav.appendChild( instantTabBtn );
		tabsNav.appendChild( quoteTabBtn );

		instantTabPane.appendChild( shell );
		quoteTabPane.appendChild( buildQuoteForm() );

		tabContentWrap.appendChild( instantTabPane );
		tabContentWrap.appendChild( quoteTabPane );

		root.appendChild( tabsNav );
		root.appendChild( tabContentWrap );

		function windowLabel() {
			var win = scheduling.arrival_windows[ state.selectedWindow ];
			return win ? win.label + ' (' + win.start + '–' + win.end + ')' : '';
		}

		/**
		 * Client-side *estimate* only, mirroring
		 * Service_Crew_Bookings::create_instant_booking()'s formula (subtotal
		 * -> deposit-tier lookup -> whatever advance-discount tier that
		 * percentage happens to qualify for -> tax). The server recomputes
		 * all of this from scratch when the customer actually pays — Stripe's
		 * own Checkout page always shows the real, authoritative amount, so a
		 * small estimate/actual drift here (e.g. from a settings change
		 * between page load and checkout) is cosmetic, not a pricing bug.
		 */
		function computeDepositEstimate() {
			var subtotal  = 0;
			var lineItems = [];

			state.cart.forEach( function ( item ) {
				var computed = W.computeItemTotal( item );
				subtotal += computed.price;
				lineItems.push( { title: item.node.title, price: computed.price } );
			} );

			var depositTier    = W.getMatchingDepositTier( minimumDepositTiers, subtotal );
			var minimumPercent = depositTier ? depositTier.deposit_percent : 100;
			var tier            = W.getMatchingTier( discountTiers, minimumPercent );
			var discountAmount  = W.computeDiscountAmount( subtotal, tier );
			var discountedTotal = Math.max( 0, subtotal - discountAmount );
			var taxAmount        = W.computeTaxAmount( discountedTotal, taxRatePercent, taxMode );
			var totalWithTax     = 'inclusive' === taxMode ? discountedTotal : discountedTotal + taxAmount;
			var surchargeAmount  = state.isEmergency ? computeEmergencySurcharge( totalWithTax ) : 0;
			totalWithTax          = totalWithTax + surchargeAmount;
			var dueNow            = totalWithTax * ( minimumPercent / 100 );

			return {
				lineItems: lineItems,
				subtotal: subtotal,
				minimumPercent: minimumPercent,
				discountAmount: discountAmount,
				taxAmount: taxAmount,
				surchargeAmount: surchargeAmount,
				totalWithTax: totalWithTax,
				dueNow: dueNow,
			};
		}

		/**
		 * Wires an address + ZIP input pair to GET /verify-address
		 * (class-service-crew-geocoding.php's free Nominatim-based check, used
		 * instead of a paid Google Maps key per explicit request): auto-fills
		 * an empty ZIP from the resolved postal code, and shows a non-blocking
		 * warning when a filled-in ZIP doesn't match — the customer can still
		 * continue either way, since a free lookup can be wrong for a rural or
		 * ambiguous address.
		 *
		 * @param {HTMLInputElement} addressInput
		 * @param {HTMLInputElement} zipInput
		 * @param {HTMLElement}      warningEl
		 * @param {function(string):void} onZipAutofilled Called with the autofilled ZIP so the caller's own state stays in sync.
		 */
		function attachAddressVerification( addressInput, zipInput, warningEl, onZipAutofilled ) {
			function runCheck() {
				var address = addressInput.value.trim();

				if ( ! address ) {
					warningEl.style.display = 'none';
					return;
				}

				var url = SC_BOOKING.restUrl + 'verify-address'
					+ '?address=' + window.encodeURIComponent( address )
					+ '&zip=' + window.encodeURIComponent( zipInput.value.trim() );

				window.fetch( url, { headers: { 'X-WP-Nonce': SC_BOOKING.nonce } } )
					.then( function ( response ) { return response.json(); } )
					.then( function ( data ) {
						if ( data && data.resolved_zip && ! zipInput.value.trim() ) {
							zipInput.value = data.resolved_zip;
							onZipAutofilled( data.resolved_zip );
						}

						if ( data && data.checked && ! data.matches ) {
							warningEl.textContent = 'This ZIP code doesn’t seem to match the address you entered — please double-check it.';
							warningEl.style.display = '';
						} else {
							warningEl.style.display = 'none';
						}
					} )
					.catch( function () {
						warningEl.style.display = 'none';
					} );
			}

			addressInput.addEventListener( 'blur', runCheck );
			zipInput.addEventListener( 'blur', runCheck );
		}

		function showStepError( panel, message ) {
			var errorEl = panel.querySelector( '.sc-flow-error' );

			if ( ! errorEl ) {
				return;
			}

			errorEl.textContent = message;
			errorEl.classList.add( 'is-visible' );

			window.clearTimeout( errorEl.scHideTimeout );
			errorEl.scHideTimeout = window.setTimeout( function () {
				errorEl.classList.remove( 'is-visible' );
			}, 4000 );
		}

		/* ---- Step 1: Service ---------------------------------------- */

		function buildServicePanel() {
			var panel = el( 'div', { class: 'sc-flow-panel', 'data-step': '0' }, [
				el( 'h2', { class: 'sc-flow-panel__title', text: 'Choose your service' } ),
				el( 'p', { class: 'sc-flow-panel__subtitle', text: 'Pick a service (and any add-ons), then continue.' } ),
			] );

			var mount = el( 'div', { class: 'sc-booking-widget', id: root.id + '-widget' } );

			var dataScript = document.createElement( 'script' );
			dataScript.type = 'application/json';
			dataScript.className = 'sc-booking-data';
			// DOM textContent, not innerHTML/document.write — no HTML-parsing
			// early-termination risk from a literal "</script>" in the JSON,
			// unlike the server-rendered embed this payload originally came
			// from (see class-service-crew-services-shortcode.php's note).
			dataScript.textContent = JSON.stringify( payload );
			mount.appendChild( dataScript );

			panel.appendChild( mount );
			panel.appendChild( el( 'p', { class: 'sc-flow-error', 'aria-live': 'polite' } ) );

			widgetApi = W.init( mount );

			return panel;
		}

		/* ---- Step 2: Date & Time -------------------------------------- */

		function buildDateTimePanel() {
			var panel = el( 'div', { class: 'sc-flow-panel', 'data-step': '1' }, [
				el( 'h2', { class: 'sc-flow-panel__title', text: 'Pick a date & arrival window' } ),
				el( 'p', { class: 'sc-flow-panel__subtitle', text: 'Greyed-out dates are outside business hours or marked closed.' } ),
			] );

			var calendarEl = el( 'div', { class: 'sc-flow-calendar' } );
			var windowsEl  = el( 'div', { class: 'sc-flow-windows' } );

			panel.appendChild( calendarEl );
			panel.appendChild( windowsEl );
			panel.appendChild( el( 'p', { class: 'sc-flow-error', 'aria-live': 'polite' } ) );

			var today     = new Date();
			var viewYear  = today.getFullYear();
			var viewMonth = today.getMonth();

			function isHoliday( iso ) {
				return ( scheduling.holidays || [] ).some( function ( holiday ) { return holiday.date === iso; } );
			}

			function isBusinessDay( dateObj ) {
				var config = scheduling.business_hours && scheduling.business_hours[ WEEKDAY_KEYS[ dateObj.getDay() ] ];
				return Boolean( config && config.enabled );
			}

			function isPast( dateObj ) {
				var startOfToday = new Date( today.getFullYear(), today.getMonth(), today.getDate() );
				return dateObj < startOfToday;
			}

			function renderCalendar() {
				calendarEl.innerHTML = '';

				calendarEl.appendChild( el( 'div', { class: 'sc-flow-calendar__header' }, [
					el( 'button', {
						type: 'button',
						class: 'sc-flow-calendar__nav',
						text: '‹',
						onClick: function () {
							viewMonth -= 1;
							if ( viewMonth < 0 ) {
								viewMonth = 11;
								viewYear -= 1;
							}
							renderCalendar();
						},
					} ),
					el( 'span', { class: 'sc-flow-calendar__label', text: MONTH_NAMES[ viewMonth ] + ' ' + viewYear } ),
					el( 'button', {
						type: 'button',
						class: 'sc-flow-calendar__nav',
						text: '›',
						onClick: function () {
							viewMonth += 1;
							if ( viewMonth > 11 ) {
								viewMonth = 0;
								viewYear += 1;
							}
							renderCalendar();
						},
					} ),
				] ) );

				var grid = el( 'div', { class: 'sc-flow-calendar__grid' } );

				[ 'S', 'M', 'T', 'W', 'T', 'F', 'S' ].forEach( function ( label ) {
					grid.appendChild( el( 'div', { class: 'sc-flow-calendar__weekday', text: label } ) );
				} );

				var firstOfMonth = new Date( viewYear, viewMonth, 1 );
				var daysInMonth  = new Date( viewYear, viewMonth + 1, 0 ).getDate();

				for ( var pad = 0; pad < firstOfMonth.getDay(); pad++ ) {
					grid.appendChild( el( 'div', { class: 'sc-flow-calendar__cell sc-flow-calendar__cell--empty' } ) );
				}

				var dayNumbers = [];
				for ( var n = 1; n <= daysInMonth; n++ ) {
					dayNumbers.push( n );
				}

				dayNumbers.forEach( function ( day ) {
					var dateObj  = new Date( viewYear, viewMonth, day );
					var iso      = toISODate( viewYear, viewMonth, day );
					var disabled = isPast( dateObj ) || ! isBusinessDay( dateObj ) || isHoliday( iso );

					var cell = el( 'button', {
						type: 'button',
						class: 'sc-flow-calendar__cell'
							+ ( disabled ? ' is-disabled' : '' )
							+ ( state.selectedDate === iso ? ' is-selected' : '' ),
						text: String( day ),
					} );

					if ( disabled ) {
						cell.disabled = true;
					} else {
						cell.addEventListener( 'click', function () {
							state.selectedDate   = iso;
							state.selectedWindow = null;
							state.isEmergency     = false;
							renderCalendar();
							fetchBookedWindows( iso );
						} );
					}

					grid.appendChild( cell );
				} );

				calendarEl.appendChild( grid );
			}

			/**
			 * Refreshes which arrival-window labels are already taken for the
			 * selected date (GET /booked-windows — Service_Crew_Bookings::
			 * get_booked_windows()), so a slot the server would reject with
			 * sc_slot_taken is greyed out here before the customer even tries.
			 * A failed request just leaves nothing greyed out — the server-side
			 * check in create_instant_booking() is still the real enforcement.
			 *
			 * @param {string} iso Selected date, YYYY-MM-DD.
			 */
			function fetchBookedWindows( iso ) {
				if ( ! iso ) {
					state.bookedWindows = [];
					renderWindows();
					return;
				}

				window.fetch( SC_BOOKING.restUrl + 'booked-windows?date=' + window.encodeURIComponent( iso ), {
					headers: { 'X-WP-Nonce': SC_BOOKING.nonce },
				} )
					.then( function ( response ) { return response.json(); } )
					.then( function ( data ) {
						state.bookedWindows = ( data && data.windows ) || [];
						renderWindows();
					} )
					.catch( function () {
						state.bookedWindows = [];
						renderWindows();
					} );
			}

			function renderWindows() {
				windowsEl.innerHTML = '';
				windowsEl.appendChild( el( 'h3', { class: 'sc-flow-windows__title', text: 'Arrival window' } ) );

				var list        = el( 'div', { class: 'sc-flow-windows__list' } );
				var allWindows  = scheduling.arrival_windows || [];

				// Every arrival window on the selected date is already
				// taken — no date-is-today restriction, by explicit request
				// ("if a day all windows booked then show emergency booking
				// option no matter date is upcoming or not"). If even one
				// window is still free, there's nothing to bypass — the
				// customer should just pick that one instead.
				var isDayFullyBooked = allWindows.length > 0 && allWindows.every( function ( win ) {
					return -1 !== ( state.bookedWindows || [] ).indexOf( win.label );
				} );

				allWindows.forEach( function ( win, index ) {
					var isTaken = -1 !== ( state.bookedWindows || [] ).indexOf( win.label );
					var isBlocked = isTaken && ! ( isDayFullyBooked && state.isEmergency );

					var attrs = {
						type: 'button',
						class: 'sc-flow-window-btn'
							+ ( state.selectedWindow === index ? ' is-selected' : '' )
							+ ( isBlocked ? ' is-disabled' : '' ),
					};

					if ( ! isBlocked ) {
						attrs.onClick = function () {
							state.selectedWindow = index;
							renderWindows();
						};
					}

					var btn = el( 'button', attrs, [
						el( 'span', { class: 'sc-flow-window-btn__label', text: win.label } ),
						el( 'span', { class: 'sc-flow-window-btn__time', text: win.start + '–' + win.end + ( isTaken ? ' · Booked' : '' ) } ),
					] );

					if ( isBlocked ) {
						btn.disabled = true;
					}

					list.appendChild( btn );
				} );

				if ( isDayFullyBooked ) {
					var checkbox = el( 'input', { type: 'checkbox', class: 'sc-flow-emergency-checkbox' } );
					checkbox.checked = Boolean( state.isEmergency );
					checkbox.addEventListener( 'change', function () {
						state.isEmergency = checkbox.checked;

						if ( ! state.isEmergency ) {
							state.selectedWindow = null;
						}

						renderWindows();
					} );

					var surcharge = scheduling.emergency_surcharge || {};
					var surchargeNote = surcharge.enabled
						? ' (adds ' + ( 'flat' === surcharge.type ? W.formatMoney( surcharge.amount ) : surcharge.amount + '%' ) + ' emergency fee)'
						: '';

					windowsEl.appendChild( el( 'label', { class: 'sc-flow-emergency' }, [
						checkbox,
						el( 'span', { text: 'This day is fully booked — I need service on this date anyway (Emergency Booking)' + surchargeNote } ),
					] ) );
				}

				windowsEl.appendChild( list );
			}

			renderCalendar();
			renderWindows();

			return panel;
		}

		/* ---- Step 3: Payment ------------------------------------------ */

		function buildPaymentPanel() {
			var panel = el( 'div', { class: 'sc-flow-panel', 'data-step': '2' }, [
				el( 'h2', { class: 'sc-flow-panel__title', text: 'Payment' } ),
				el( 'p', { class: 'sc-flow-panel__subtitle', text: 'You’ll be redirected to Stripe to securely pay your deposit.' } ),
			] );

			var summaryEl = el( 'div', { class: 'sc-flow-summary' } );
			panel.appendChild( summaryEl );

			var nameInput    = el( 'input', { type: 'text', class: 'sc-flow-input', placeholder: 'Jane Doe' } );
			var emailInput   = el( 'input', { type: 'email', class: 'sc-flow-input', placeholder: 'jane@example.com' } );
			var phoneInput   = el( 'input', { type: 'tel', class: 'sc-flow-input', placeholder: '(555) 123-4567' } );
			var addressInput = el( 'input', { type: 'text', class: 'sc-flow-input', placeholder: '123 Main St, Springfield' } );
			var zipInput     = el( 'input', { type: 'text', class: 'sc-flow-input', placeholder: '12345' } );

			nameInput.addEventListener( 'input', function () { state.customerName = nameInput.value; } );
			emailInput.addEventListener( 'input', function () { state.customerEmail = emailInput.value; } );
			phoneInput.addEventListener( 'input', function () { state.customerPhone = phoneInput.value; } );
			addressInput.addEventListener( 'input', function () { state.customerAddress = addressInput.value; } );
			zipInput.addEventListener( 'input', function () { state.customerZip = zipInput.value; } );

			panel.appendChild( el( 'h3', { class: 'sc-flow-windows__title', text: 'Your details' } ) );
			panel.appendChild( el( 'div', { class: 'sc-flow-contact' }, [
				el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'Full name' } ), nameInput ] ),
				el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'Email address' } ), emailInput ] ),
				el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'Phone number' } ), phoneInput ] ),
				// Where the crew needs to go — the plan's door-to-door rule.
				// Geocoded best-effort server-side (never blocks the booking —
				// see class-service-crew-bookings.php).
				el( 'div', { class: 'sc-flow-field sc-flow-field--full' }, [ el( 'label', { text: 'Service address (where the crew should come)' } ), addressInput ] ),
				el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'ZIP / postal code' } ), zipInput ] ),
			] ) );

			var addressWarningEl = el( 'p', { class: 'sc-flow-address-warning', style: 'display: none;' } );
			panel.appendChild( addressWarningEl );
			attachAddressVerification( addressInput, zipInput, addressWarningEl, function ( zip ) { state.customerZip = zip; } );

			var marketingCheckbox = el( 'input', { type: 'checkbox' } );
			marketingCheckbox.addEventListener( 'change', function () { state.marketingOptIn = marketingCheckbox.checked; } );
			panel.appendChild( el( 'label', { class: 'sc-flow-marketing-optin' }, [
				marketingCheckbox,
				el( 'span', { text: 'Email me about offers and updates' } ),
			] ) );

			panel.appendChild( el( 'p', { class: 'sc-flow-error', 'aria-live': 'polite' } ) );

			var payLabel = el( 'span', { class: 'sc-flow-pay-btn__label', text: 'Pay' } );
			var paySpinner = el( 'span', { class: 'sc-flow-pay-btn__spinner' } );
			var payBtn = el( 'button', { type: 'button', class: 'sc-flow-pay-btn', onClick: handlePayClick }, [ payLabel, paySpinner ] );
			panel.appendChild( payBtn );

			function handlePayClick() {
				if ( ! state.customerName.trim() || ! state.customerEmail.trim() ) {
					showStepError( panel, 'Please enter your name and email to continue.' );
					return;
				}

				if ( ! state.customerPhone.trim() ) {
					showStepError( panel, 'Please enter a phone number to continue.' );
					return;
				}

				if ( ! state.customerAddress.trim() || ! state.customerZip.trim() ) {
					showStepError( panel, 'Please enter the service address and ZIP code to continue.' );
					return;
				}

				if ( payBtn.classList.contains( 'is-loading' ) ) {
					return;
				}

				payBtn.classList.add( 'is-loading' );
				payBtn.disabled = true;

				var items = state.cart.map( function ( item ) {
					var addons = {};

					Object.keys( item.addons || {} ).forEach( function ( key ) {
						addons[ key ] = {
							checked: Boolean( item.addons[ key ].checked ),
							qty: item.addons[ key ].qty,
						};
					} );

					return { service_id: item.node.id, qty: item.qty, addons: addons };
				} );

				var body = {
					items: items,
					date: state.selectedDate,
					arrival_window_index: state.selectedWindow,
					is_emergency: Boolean( state.isEmergency ),
					customer_name: state.customerName,
					customer_email: state.customerEmail,
					customer_phone: state.customerPhone,
					address: state.customerAddress,
					zip: state.customerZip,
					marketing_opt_in: Boolean( state.marketingOptIn ),
					return_url: window.location.origin + window.location.pathname,
				};

				window.fetch( SC_BOOKING.restUrl + 'bookings', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SC_BOOKING.nonce },
					body: JSON.stringify( body ),
				} )
					.then( function ( response ) {
						return response.json().then( function ( data ) {
							return { ok: response.ok, data: data };
						} );
					} )
					.then( function ( result ) {
						if ( ! result.ok || ! result.data || ! result.data.checkout_url ) {
							throw new Error( ( result.data && result.data.message ) || 'Could not start checkout. Please try again.' );
						}

						// Navigating away — no need to reset the button state.
						window.location.href = result.data.checkout_url;
					} )
					.catch( function ( error ) {
						payBtn.classList.remove( 'is-loading' );
						payBtn.disabled = false;
						showStepError( panel, error.message || 'Something went wrong. Please try again.' );
					} );
			}

			panel.scSummaryEl = summaryEl;
			panel.scPayLabel  = payLabel;

			return panel;
		}

		function refreshPaymentPanel() {
			var summary = computeDepositEstimate();
			var summaryEl = paymentPanel.scSummaryEl;
			summaryEl.innerHTML = '';

			summary.lineItems.forEach( function ( line ) {
				summaryEl.appendChild( el( 'div', { class: 'sc-flow-summary__row' }, [
					el( 'span', { text: line.title } ),
					el( 'span', { text: W.formatMoney( line.price ) } ),
				] ) );
			} );

			if ( state.selectedDate ) {
				summaryEl.appendChild( el( 'div', { class: 'sc-flow-summary__row sc-flow-summary__row--muted' }, [
					el( 'span', { text: 'Date' } ),
					el( 'span', { text: formatDisplayDate( state.selectedDate ) + ( null !== state.selectedWindow ? ' · ' + windowLabel() : '' ) } ),
				] ) );
			}

			summaryEl.appendChild( el( 'div', { class: 'sc-flow-summary__row sc-flow-summary__row--muted' }, [
				el( 'span', { text: 'Estimated total (with tax)' } ),
				el( 'span', { text: W.formatMoney( summary.totalWithTax ) } ),
			] ) );

			if ( summary.discountAmount > 0 ) {
				summaryEl.appendChild( el( 'div', { class: 'sc-flow-summary__row sc-flow-summary__row--discount' }, [
					el( 'span', { text: 'Discount' } ),
					el( 'span', { text: '-' + W.formatMoney( summary.discountAmount ) } ),
				] ) );
			}

			if ( summary.surchargeAmount > 0 ) {
				summaryEl.appendChild( el( 'div', { class: 'sc-flow-summary__row sc-flow-summary__row--muted' }, [
					el( 'span', { text: 'Emergency booking fee' } ),
					el( 'span', { text: '+' + W.formatMoney( summary.surchargeAmount ) } ),
				] ) );
			}

			summaryEl.appendChild( el( 'div', { class: 'sc-flow-summary__row sc-flow-summary__row--grand' }, [
				el( 'span', { text: 'Deposit due now (' + summary.minimumPercent + '% minimum)' } ),
				el( 'span', { text: W.formatMoney( summary.dueNow ) } ),
			] ) );

			paymentPanel.scPayLabel.textContent = 'Pay ' + W.formatMoney( summary.dueNow ) + ' deposit';
		}

		/* ---- Step 4: Thank you ----------------------------------------- */

		function buildThankYouPanel() {
			var panel = el( 'div', { class: 'sc-flow-panel sc-flow-panel--thankyou', 'data-step': '3' } );

			panel.appendChild( el( 'div', { class: 'sc-flow-thankyou__icon' }, [ el( 'span', { text: '✓' } ) ] ) );
			panel.appendChild( el( 'h2', { class: 'sc-flow-panel__title', text: 'Thank you!' } ) );

			var message = el( 'p', { class: 'sc-flow-thankyou__message' } );
			var details = el( 'div', { class: 'sc-flow-thankyou__details' } );

			panel.appendChild( message );
			panel.appendChild( details );

			panel.scMessage = message;
			panel.scDetails = details;

			return panel;
		}

		/* ---- Quote tab: "Request a Quote" form -------------------------- */

		/**
		 * Builds the quote-request form per the plan's Quotes section: photos,
		 * title, description, preferred date + arrival window, address + ZIP,
		 * phone, name, email — no service selection, no payment. Posts
		 * multipart/form-data (for the optional photo files) to
		 * POST /service-crew/v1/quotes; every field and photo is re-validated
		 * server-side (class-service-crew-quotes.php) regardless of what this
		 * form enforces client-side.
		 *
		 * @return {HTMLElement} The quote tab's panel element.
		 */
		function buildQuoteForm() {
			var panel = el( 'div', { class: 'sc-flow-panel is-active' } );
			var form  = el( 'div', { class: 'sc-quote-form' } );

			form.appendChild( el( 'h2', { class: 'sc-flow-panel__title', text: 'Request a quote' } ) );
			form.appendChild( el( 'p', { class: 'sc-flow-panel__subtitle', text: 'No service selection or payment needed — describe the job and we’ll follow up with a price.' } ) );

			var titleInput       = el( 'input', { type: 'text', class: 'sc-flow-input', placeholder: 'e.g. Fence repair after storm damage' } );
			var descriptionInput = el( 'textarea', { class: 'sc-flow-input sc-quote-textarea', rows: '4', placeholder: 'Describe the work you need done…' } );
			var nameInput        = el( 'input', { type: 'text', class: 'sc-flow-input', placeholder: 'Jane Doe' } );
			var emailInput       = el( 'input', { type: 'email', class: 'sc-flow-input', placeholder: 'jane@example.com' } );
			var phoneInput       = el( 'input', { type: 'tel', class: 'sc-flow-input', placeholder: '(555) 123-4567' } );
			var addressInput     = el( 'input', { type: 'text', class: 'sc-flow-input', placeholder: '123 Main St, Springfield' } );
			var zipInput         = el( 'input', { type: 'text', class: 'sc-flow-input', placeholder: '12345' } );

			var today   = new Date();
			var minDate = toISODate( today.getFullYear(), today.getMonth(), today.getDate() );
			var dateInput = el( 'input', { type: 'date', class: 'sc-flow-input', min: minDate } );

			form.appendChild( el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'What do you need done?' } ), titleInput ] ) );
			form.appendChild( el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'Describe the job' } ), descriptionInput ] ) );

			form.appendChild( el( 'h3', { class: 'sc-flow-windows__title', text: 'Preferred date & window (optional)' } ) );
			form.appendChild( el( 'div', { class: 'sc-flow-contact' }, [
				el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'Preferred date' } ), dateInput ] ),
			] ) );

			var selectedWindowIndex = null;
			var windowsList = el( 'div', { class: 'sc-flow-windows__list' } );

			( scheduling.arrival_windows || [] ).forEach( function ( win, index ) {
				var btn = el( 'button', {
					type: 'button',
					class: 'sc-flow-window-btn',
					onClick: function () {
						selectedWindowIndex = index;
						Array.prototype.forEach.call( windowsList.children, function ( child, i ) {
							child.classList.toggle( 'is-selected', i === index );
						} );
					},
				}, [
					el( 'span', { class: 'sc-flow-window-btn__label', text: win.label } ),
					el( 'span', { class: 'sc-flow-window-btn__time', text: win.start + '–' + win.end } ),
				] );

				windowsList.appendChild( btn );
			} );

			form.appendChild( windowsList );

			/* Photos: client-side is a convenience preview only — count, type
			   and size are all re-checked server-side (Service_Crew_Quotes). */
			var selectedFiles = [];
			var photoList  = el( 'div', { class: 'sc-quote-photo-list' } );
			var fileInputId = root.id + '-quote-photos';
			var fileInput  = el( 'input', {
				type: 'file',
				id: fileInputId,
				class: 'sc-quote-file-input',
				accept: 'image/png,image/jpeg,image/gif,image/webp',
				multiple: 'multiple',
			} );

			function renderPhotoList() {
				photoList.innerHTML = '';

				selectedFiles.forEach( function ( file, index ) {
					var thumb = el( 'div', { class: 'sc-quote-photo-thumb' } );
					thumb.appendChild( el( 'img', { src: URL.createObjectURL( file ), alt: '' } ) );
					thumb.appendChild( el( 'button', {
						type: 'button',
						class: 'sc-quote-photo-remove',
						'aria-label': 'Remove photo',
						text: '×',
						onClick: function () {
							selectedFiles.splice( index, 1 );
							renderPhotoList();
						},
					} ) );
					photoList.appendChild( thumb );
				} );
			}

			fileInput.addEventListener( 'change', function () {
				Array.prototype.slice.call( fileInput.files ).forEach( function ( file ) {
					if ( selectedFiles.length < 6 ) {
						selectedFiles.push( file );
					}
				} );
				fileInput.value = '';
				renderPhotoList();
			} );

			form.appendChild( el( 'h3', { class: 'sc-flow-windows__title', text: 'Photos (optional, up to 6)' } ) );
			form.appendChild( el( 'label', { class: 'sc-quote-dropzone', for: fileInputId, text: '+ Add photos' } ) );
			form.appendChild( fileInput );
			form.appendChild( photoList );

			form.appendChild( el( 'h3', { class: 'sc-flow-windows__title', text: 'Your details' } ) );
			form.appendChild( el( 'div', { class: 'sc-flow-contact' }, [
				el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'Full name' } ), nameInput ] ),
				el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'Email address' } ), emailInput ] ),
				el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'Phone number' } ), phoneInput ] ),
				el( 'div', { class: 'sc-flow-field sc-flow-field--full' }, [ el( 'label', { text: 'Service address (where the crew should come)' } ), addressInput ] ),
				el( 'div', { class: 'sc-flow-field' }, [ el( 'label', { text: 'ZIP / postal code' } ), zipInput ] ),
			] ) );

			var quoteAddressWarningEl = el( 'p', { class: 'sc-flow-address-warning', style: 'display: none;' } );
			form.appendChild( quoteAddressWarningEl );
			attachAddressVerification( addressInput, zipInput, quoteAddressWarningEl, function () {} );

			var quoteMarketingCheckbox = el( 'input', { type: 'checkbox' } );
			form.appendChild( el( 'label', { class: 'sc-flow-marketing-optin' }, [
				quoteMarketingCheckbox,
				el( 'span', { text: 'Email me about offers and updates' } ),
			] ) );

			// Hidden from real visitors (off-screen, unreachable by tab, never
			// announced) — a filled-in value here means a bot, not a customer.
			// See Service_Crew_Quotes::create_quote_request()'s honeypot check.
			var honeypotInput = el( 'input', {
				type: 'text',
				class: 'sc-quote-honeypot',
				name: 'website',
				tabindex: '-1',
				autocomplete: 'off',
				'aria-hidden': 'true',
			} );
			form.appendChild( honeypotInput );

			var errorEl = el( 'p', { class: 'sc-flow-error', 'aria-live': 'polite' } );
			form.appendChild( errorEl );

			var submitLabel   = el( 'span', { class: 'sc-flow-pay-btn__label', text: 'Send quote request' } );
			var submitSpinner = el( 'span', { class: 'sc-flow-pay-btn__spinner' } );
			var submitBtn     = el( 'button', { type: 'button', class: 'sc-flow-pay-btn', onClick: handleQuoteSubmit }, [ submitLabel, submitSpinner ] );
			form.appendChild( submitBtn );

			var successEl = el( 'div', { class: 'sc-flow-panel--thankyou', style: 'display: none;' }, [
				el( 'div', { class: 'sc-flow-thankyou__icon' }, [ el( 'span', { text: '✓' } ) ] ),
				el( 'h2', { class: 'sc-flow-panel__title', text: 'Request received!' } ),
				el( 'p', { class: 'sc-flow-thankyou__message', text: 'Thanks — we’ll review the details and follow up with a price soon.' } ),
			] );

			panel.appendChild( form );
			panel.appendChild( successEl );

			function handleQuoteSubmit() {
				if ( ! titleInput.value.trim() || ! descriptionInput.value.trim() ) {
					showStepError( panel, 'Please describe the work you need done.' );
					return;
				}

				if ( ! nameInput.value.trim() || ! emailInput.value.trim() ) {
					showStepError( panel, 'Please enter your name and email to continue.' );
					return;
				}

				if ( submitBtn.classList.contains( 'is-loading' ) ) {
					return;
				}

				submitBtn.classList.add( 'is-loading' );
				submitBtn.disabled = true;

				var formData = new window.FormData();
				formData.append( 'title', titleInput.value );
				formData.append( 'description', descriptionInput.value );
				formData.append( 'name', nameInput.value );
				formData.append( 'email', emailInput.value );
				formData.append( 'phone', phoneInput.value );
				formData.append( 'address', addressInput.value );
				formData.append( 'zip', zipInput.value );
				formData.append( 'preferred_date', dateInput.value );
				formData.append( 'arrival_window', null !== selectedWindowIndex ? scheduling.arrival_windows[ selectedWindowIndex ].label : '' );
				formData.append( 'marketing_opt_in', quoteMarketingCheckbox.checked ? '1' : '0' );
				formData.append( 'website', honeypotInput.value );

				selectedFiles.forEach( function ( file ) {
					formData.append( 'photos[]', file );
				} );

				// No 'Content-Type' header: the browser sets the multipart
				// boundary itself, which it can only do if it builds the header.
				window.fetch( SC_BOOKING.restUrl + 'quotes', {
					method: 'POST',
					headers: { 'X-WP-Nonce': SC_BOOKING.nonce },
					body: formData,
				} )
					.then( function ( response ) {
						return response.json().then( function ( data ) {
							return { ok: response.ok, data: data };
						} );
					} )
					.then( function ( result ) {
						if ( ! result.ok ) {
							throw new Error( ( result.data && result.data.message ) || 'Something went wrong. Please try again.' );
						}

						form.style.display = 'none';
						successEl.style.display = '';
					} )
					.catch( function ( error ) {
						submitBtn.classList.remove( 'is-loading' );
						submitBtn.disabled = false;
						showStepError( panel, error.message || 'Something went wrong. Please try again.' );
					} );
			}

			return panel;
		}

		/**
		 * Step 4 is only ever populated by the return-from-Stripe flow below
		 * (checkReturnFromStripe()) — there is no other path to it, since
		 * handlePayClick() always navigates away to Stripe Checkout rather
		 * than completing in-page.
		 *
		 * @param {string} message Headline text.
		 * @param {string} detail  Supporting line, or '' for none.
		 */
		function showThankYouMessage( message, detail ) {
			thankYouPanel.scMessage.textContent = message;
			thankYouPanel.scDetails.innerHTML = '';

			if ( detail ) {
				thankYouPanel.scDetails.appendChild( el( 'p', { class: 'sc-flow-thankyou__row', text: detail } ) );
			}
		}

		/**
		 * Reads sc_booking/sc_status/sc_email back from the URL Stripe
		 * redirected to (see the success_url/cancel_url built in
		 * Service_Crew_Bookings::create_instant_booking()) and either shows
		 * the cancel message on the Payment step, or polls
		 * GET /bookings/{id}/payment-status on the Thank-you step until the
		 * webhook has confirmed it (or a handful of tries pass).
		 */
		function checkReturnFromStripe() {
			var params    = new window.URLSearchParams( window.location.search );
			var bookingId = params.get( 'sc_booking' );
			var status    = params.get( 'sc_status' );

			if ( ! bookingId || ! status ) {
				return;
			}

			// The customer only ever left for Stripe from the Instant Booking
			// tab — land back on it even if the quote tab was left active.
			activateTab( 'instant' );

			// Strip the query string so a reload/back doesn't re-trigger this.
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', window.location.pathname );
			}

			if ( 'cancel' === status ) {
				goToStep( 2 );
				showStepError( paymentPanel, 'Payment was cancelled. You can try again below.' );
				return;
			}

			if ( 'success' === status ) {
				goToStep( 3 );

				// The booking already exists server-side regardless of payment
				// status — clear the browsing cart (and its sessionStorage
				// entry) now so it doesn't reappear as "already there" if the
				// customer starts a fresh booking on this same page/tab.
				if ( widgetApi && widgetApi.clearCart ) {
					widgetApi.clearCart();
				}
				state.cart = [];

				showThankYouMessage( 'Confirming your payment…', '' );
				pollPaymentStatus( bookingId, params.get( 'sc_email' ) || '', 0 );
			}
		}

		function pollPaymentStatus( bookingId, email, attempt ) {
			var url = SC_BOOKING.restUrl + 'bookings/' + window.encodeURIComponent( bookingId ) + '/payment-status';
			if ( email ) {
				url += '?email=' + window.encodeURIComponent( email );
			}

			window.fetch( url, { headers: { 'X-WP-Nonce': SC_BOOKING.nonce } } )
				.then( function ( response ) { return response.json(); } )
				.then( function ( data ) {
					if ( data && data.confirmed ) {
						showThankYouMessage( 'Payment received — your booking is confirmed!', 'A confirmation has been sent to your email.' );
						return;
					}

					if ( attempt < 5 ) {
						window.setTimeout( function () {
							pollPaymentStatus( bookingId, email, attempt + 1 );
						}, 2000 );
					} else {
						showThankYouMessage( 'Payment received.', 'We’re still finalizing your booking — you’ll get a confirmation email shortly.' );
					}
				} )
				.catch( function () {
					showThankYouMessage( 'Payment received.', 'We couldn’t confirm the booking status just now — check your email shortly for confirmation.' );
				} );
		}

		/* ---- Shell: stepper header + footer nav ------------------------- */

		function renderStepper() {
			stepper.innerHTML = '';

			STEPS.forEach( function ( step, index ) {
				var status = index < state.stepIndex ? 'is-complete' : ( index === state.stepIndex ? 'is-active' : '' );

				stepper.appendChild( el( 'div', { class: 'sc-flow-step ' + status }, [
					el( 'div', { class: 'sc-flow-step__circle' }, [
						el( 'span', { text: index < state.stepIndex ? '✓' : String( index + 1 ) } ),
					] ),
					el( 'span', { class: 'sc-flow-step__label', text: step.label } ),
				] ) );

				if ( index < STEPS.length - 1 ) {
					stepper.appendChild( el( 'div', { class: 'sc-flow-step__connector' + ( index < state.stepIndex ? ' is-complete' : '' ) } ) );
				}
			} );

			stepperCaption.textContent = 'Step ' + ( state.stepIndex + 1 ) + ' of ' + STEPS.length + ' — ' + STEPS[ state.stepIndex ].label;
		}

		function renderFooter() {
			footer.innerHTML = '';

			// Step 4 gets one centered button, alone in the footer — not the
			// "Back + primary" two-item row the other steps use, so it skips
			// the placeholder span those need to keep their primary button
			// right-aligned against justify-content: space-between.
			if ( 3 === state.stepIndex ) {
				footer.appendChild( el( 'button', {
					type: 'button',
					class: 'sc-flow-btn sc-flow-btn--secondary',
					text: 'Make another booking',
					onClick: function () {
						state.stepIndex      = 0;
						state.selectedDate   = null;
						state.selectedWindow = null;
						state.isEmergency     = false;
						state.bookedWindows   = [];
						render();
					},
				} ) );
				return;
			}

			if ( state.stepIndex > 0 ) {
				footer.appendChild( el( 'button', {
					type: 'button',
					class: 'sc-flow-btn sc-flow-btn--ghost',
					text: 'Back',
					onClick: function () { goToStep( state.stepIndex - 1 ); },
				} ) );
			} else {
				footer.appendChild( el( 'span', {} ) );
			}

			if ( 0 === state.stepIndex ) {
				footer.appendChild( el( 'button', {
					type: 'button',
					class: 'sc-flow-btn sc-flow-btn--primary',
					text: 'Continue',
					onClick: function () {
						var cart = widgetApi.getCart();

						if ( ! cart.length ) {
							showStepError( panelsEl.querySelector( '[data-step="0"]' ), 'Please select at least one service to continue.' );
							return;
						}

						// Single- vs multi-service is an admin setting
						// (Service_Crew_Settings' allow_multiple_services) —
						// see class-service-crew-bookings.php's docblock for
						// how a multi-item booking is priced/stored.
						if ( cart.length > 1 && ! scheduling.allow_multiple_services ) {
							showStepError( panelsEl.querySelector( '[data-step="0"]' ), 'Please select only one service for this booking — remove the extra selections above.' );
							return;
						}

						state.cart = cart;
						goToStep( 1 );
					},
				} ) );
			} else if ( 1 === state.stepIndex ) {
				footer.appendChild( el( 'button', {
					type: 'button',
					class: 'sc-flow-btn sc-flow-btn--primary',
					text: 'Continue to payment',
					onClick: function () {
						if ( ! state.selectedDate || null === state.selectedWindow ) {
							showStepError( panelsEl.querySelector( '[data-step="1"]' ), 'Please pick a date and an arrival window to continue.' );
							return;
						}

						goToStep( 2 );
					},
				} ) );
			}
		}

		function renderPanels() {
			Array.prototype.forEach.call( panelsEl.children, function ( panel, index ) {
				panel.classList.toggle( 'is-active', index === state.stepIndex );
			} );

			if ( 2 === state.stepIndex ) {
				refreshPaymentPanel();
			}
		}

		function render() {
			renderStepper();
			renderPanels();
			renderFooter();
		}

		function goToStep( index ) {
			state.stepIndex = index;
			render();
			shell.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		panelsEl.appendChild( buildServicePanel() );
		panelsEl.appendChild( buildDateTimePanel() );
		paymentPanel = buildPaymentPanel();
		panelsEl.appendChild( paymentPanel );
		thankYouPanel = buildThankYouPanel();
		panelsEl.appendChild( thankYouPanel );

		render();
		checkReturnFromStripe();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice.call( document.querySelectorAll( '.sc-booking-flow' ) ).forEach( initFlow );
	} );
} )();
