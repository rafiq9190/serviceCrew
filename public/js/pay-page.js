/**
 * Private pay page: "Pay deposit now" opens a fresh Stripe Checkout session
 * for the token this page was loaded with (class-service-crew-pay-page.php,
 * POST /service-crew/v1/pay/{token}/checkout) and redirects to it. On return
 * from Stripe (?sc_pay_status=success on this same URL) it polls
 * GET /pay/{token}/status until the webhook confirms the payment, same
 * "poll while the webhook catches up" pattern as public/js/booking-flow.js's
 * Step 4 — there is no button on this page once that poll starts.
 */
( function () {
	'use strict';

	if ( ! window.SC_PAY_PAGE ) {
		return;
	}

	var button = document.getElementById( 'sc-pay-page-button' );
	var errorEl = document.getElementById( 'sc-pay-page-error' );

	function showError( message ) {
		if ( errorEl ) {
			errorEl.textContent = message;
		}
	}

	function startCheckout() {
		if ( ! button || button.classList.contains( 'is-loading' ) ) {
			return;
		}

		button.classList.add( 'is-loading' );
		button.disabled = true;
		showError( '' );

		window.fetch( SC_PAY_PAGE.restUrl + 'pay/' + encodeURIComponent( SC_PAY_PAGE.token ) + '/checkout', {
			method: 'POST',
			headers: { 'X-WP-Nonce': SC_PAY_PAGE.nonce },
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.data.checkout_url ) {
					throw new Error( ( result.data && result.data.message ) || 'Something went wrong. Please try again.' );
				}

				window.location.href = result.data.checkout_url;
			} )
			.catch( function ( error ) {
				button.classList.remove( 'is-loading' );
				button.disabled = false;
				showError( error.message || 'Something went wrong. Please try again.' );
			} );
	}

	if ( button ) {
		button.addEventListener( 'click', startCheckout );
	}

	/* ---- Return-from-Stripe poll ------------------------------------- */

	var params = new URLSearchParams( window.location.search );
	var status = params.get( 'sc_pay_status' );

	if ( 'success' !== status && 'cancel' !== status ) {
		return;
	}

	if ( window.history && window.history.replaceState ) {
		window.history.replaceState( null, '', window.location.pathname + '?sc_pay=' + encodeURIComponent( SC_PAY_PAGE.token ) );
	}

	if ( 'cancel' === status ) {
		return;
	}

	var main = document.querySelector( '.sc-pay-page' );
	if ( main ) {
		main.innerHTML = '<h2 class="sc-pay-page__heading">Confirming payment…</h2><div class="sc-pay-page__spinner"></div>';
	}

	var attempts = 0;

	function poll() {
		attempts++;

		window.fetch( SC_PAY_PAGE.restUrl + 'pay/' + encodeURIComponent( SC_PAY_PAGE.token ) + '/status' )
			.then( function ( response ) { return response.json(); } )
			.then( function ( data ) {
				if ( data && data.succeeded ) {
					if ( main ) {
						main.innerHTML = '<div class="sc-pay-page__icon sc-pay-page__icon--success">&#10003;</div><h2 class="sc-pay-page__heading">Payment received</h2><p>Thanks — your deposit is confirmed.</p>';
					}
					return;
				}

				if ( attempts < 20 ) {
					window.setTimeout( poll, 1500 );
				} else if ( main ) {
					main.innerHTML = '<h2 class="sc-pay-page__heading">Still confirming…</h2><p>This is taking longer than expected. Refresh this page in a minute, or contact us if it doesn\'t update.</p>';
				}
			} )
			.catch( function () {
				if ( attempts < 20 ) {
					window.setTimeout( poll, 1500 );
				}
			} );
	}

	poll();
} )();
