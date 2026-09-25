<?php
/**
 * Stripe implementation of Service_Crew_Gateway_Interface: Checkout Session
 * creation, refunds, and the webhook endpoint (signature verification is
 * this route's permission check, per the plan).
 *
 * Talks to Stripe's REST API directly via wp_remote_post()/wp_remote_get()
 * rather than the stripe-php SDK — this repo has no Composer/build step
 * (see CLAUDE.md), and Stripe's API is a plain form-encoded HTTP API, so a
 * dependency isn't needed for it.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Gateway_Stripe implements Service_Crew_Gateway_Interface {

	/**
	 * @var string
	 */
	const API_BASE = 'https://api.stripe.com/v1';

	/**
	 * REST route (under Service_Crew_Payments::API_NAMESPACE) Stripe posts
	 * webhook events to.
	 *
	 * @var string
	 */
	const WEBHOOK_ROUTE = '/stripe-webhook';

	/**
	 * Maximum age of a webhook's timestamp before it's rejected as a replay,
	 * matching Stripe's own recommended default.
	 *
	 * @var int
	 */
	const SIGNATURE_TOLERANCE_SECONDS = 300;

	/**
	 * Registers the webhook route. Called once from the plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers POST /service-crew/v1/stripe-webhook. No nonce/auth
	 * permission callback — Stripe can't supply a WordPress nonce, so the
	 * signature check inside handle_webhook_request() is this route's actual
	 * access control, per the plan's "webhook's permission check is
	 * signature verification".
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			Service_Crew_Payments::API_NAMESPACE,
			self::WEBHOOK_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_checkout_session( array $payment, array $line_items, $success_url, $cancel_url ) {
		$secret_key = Service_Crew_Payments::get_secret_key();
		if ( '' === $secret_key ) {
			return new WP_Error( 'sc_stripe_not_configured', __( 'Stripe is not configured.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$body = array(
			'mode'        => 'payment',
			'success_url' => esc_url_raw( $success_url ),
			'cancel_url'  => esc_url_raw( $cancel_url ),
			'metadata'    => array( 'sc_payment_id' => (string) ( $payment['id'] ?? '' ) ),
			'line_items'  => $this->format_line_items( $line_items ),
		);

		$customer_email = isset( $payment['customer_email'] ) ? sanitize_email( $payment['customer_email'] ) : '';
		if ( $customer_email ) {
			$body['customer_email'] = $customer_email;
		}

		$response = wp_remote_post(
			self::API_BASE . '/checkout/sessions',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $secret_key ),
				'body'    => $body,
				'timeout' => 20,
			)
		);

		return $this->parse_response( $response, 200 );
	}

	/**
	 * Converts this plugin's line-item shape into Stripe's nested
	 * price_data params. Amounts are converted to the smallest currency unit
	 * (cents) — Stripe's unit_amount is always an integer.
	 *
	 * @param array<int,array{name?:string,amount?:float,quantity?:int}> $line_items Plugin-shaped line items.
	 * @return array<int,array<string,mixed>>
	 */
	private function format_line_items( array $line_items ) {
		$currency  = strtolower( Service_Crew_Wizard::get_business_basics()['currency'] ?? 'usd' );
		$formatted = array();

		foreach ( $line_items as $item ) {
			$formatted[] = array(
				'quantity'   => max( 1, absint( $item['quantity'] ?? 1 ) ),
				'price_data' => array(
					'currency'     => $currency,
					'unit_amount'  => (int) round( ( (float) ( $item['amount'] ?? 0 ) ) * 100 ),
					'product_data' => array(
						'name' => sanitize_text_field( $item['name'] ?? __( 'ServiceCrew booking', 'service-crew' ) ),
					),
				),
			);
		}

		return $formatted;
	}

	/**
	 * {@inheritDoc}
	 */
	public function refund( array $payment, $amount, $reason ) {
		$secret_key = Service_Crew_Payments::get_secret_key();
		if ( '' === $secret_key ) {
			return new WP_Error( 'sc_stripe_not_configured', __( 'Stripe is not configured.', 'service-crew' ), array( 'status' => 400 ) );
		}

		if ( empty( $payment['provider_reference'] ) ) {
			return new WP_Error( 'sc_no_charge_reference', __( 'This payment has no Stripe charge to refund.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_post(
			self::API_BASE . '/refunds',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $secret_key ),
				'body'    => array(
					'payment_intent' => $payment['provider_reference'],
					'amount'         => (int) round( ( (float) $amount ) * 100 ),
					'metadata'       => array( 'sc_refund_reason' => sanitize_text_field( $reason ) ),
				),
				'timeout' => 20,
			)
		);

		$result = $this->parse_response( $response, 200 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'provider_reference' => (string) ( $result['id'] ?? '' ),
			'status'             => (string) ( $result['status'] ?? 'succeeded' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Replicates Stripe's own signature scheme: the header is a comma-
	 * separated list of `t=<timestamp>` and one or more `v1=<signature>`
	 * pairs; the expected signature is HMAC-SHA256 of "<timestamp>.<payload>"
	 * keyed by the webhook secret. A stale timestamp is rejected to block
	 * replay of a captured payload.
	 */
	public function verify_webhook_signature( $payload, $signature_header, $webhook_secret ) {
		if ( '' === $webhook_secret || '' === (string) $signature_header ) {
			return false;
		}

		$parts = array();
		foreach ( explode( ',', (string) $signature_header ) as $pair ) {
			$pair = explode( '=', trim( $pair ), 2 );
			if ( 2 === count( $pair ) ) {
				$parts[ $pair[0] ][] = $pair[1];
			}
		}

		$timestamp  = isset( $parts['t'][0] ) ? (int) $parts['t'][0] : 0;
		$signatures = $parts['v1'] ?? array();

		if ( ! $timestamp || empty( $signatures ) ) {
			return false;
		}

		if ( abs( time() - $timestamp ) > self::SIGNATURE_TOLERANCE_SECONDS ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $webhook_secret );

		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * REST callback for the webhook route. Reads the raw body directly
	 * (rather than get_json_params()) because signature verification must
	 * run over the exact bytes Stripe signed.
	 *
	 * @param WP_REST_Request $request Incoming webhook request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook_request( $request ) {
		$payload        = $request->get_body();
		$signature      = $request->get_header( 'stripe-signature' );
		$webhook_secret = Service_Crew_Payments::get_webhook_secret();

		if ( ! $this->verify_webhook_signature( $payload, $signature, $webhook_secret ) ) {
			return new WP_Error( 'sc_invalid_signature', __( 'Invalid webhook signature.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_Error( 'sc_invalid_payload', __( 'Invalid webhook payload.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$this->process_event( $event );

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * Applies a verified event. Only checkout.session.completed is handled
	 * today; every other event type is acknowledged with 200 and ignored
	 * (Stripe retries on non-2xx, so unrecognized types must not error).
	 *
	 * Idempotent by construction: a payment already marked succeeded is left
	 * alone, so a duplicate delivery of the same event never double-fires
	 * `sc_payment_succeeded`.
	 *
	 * @param array<string,mixed> $event Decoded Stripe event.
	 * @return void
	 */
	private function process_event( array $event ) {
		if ( 'checkout.session.completed' !== $event['type'] ) {
			return;
		}

		$session = $event['data']['object'] ?? array();
		$payment_id = absint( $session['metadata']['sc_payment_id'] ?? 0 );
		if ( ! $payment_id ) {
			return;
		}

		$payment = Service_Crew_Payments::get_payment( $payment_id );
		if ( ! $payment || Service_Crew_Payments::STATUS_SUCCEEDED === $payment->status ) {
			return;
		}

		$provider_reference = is_string( $session['payment_intent'] ?? null ) ? $session['payment_intent'] : (string) ( $session['id'] ?? '' );

		Service_Crew_Payments::update_payment(
			$payment_id,
			array(
				'status'             => Service_Crew_Payments::STATUS_SUCCEEDED,
				'provider_reference' => sanitize_text_field( $provider_reference ),
				'paid_at'            => current_time( 'mysql', true ),
			)
		);

		/**
		 * Fires once a payment succeeds. class-service-crew-bookings.php
		 * (Phase 1b-2) will hook this to move a booking from
		 * awaiting_payment to confirmed/pending_approval.
		 *
		 * @param int $payment_id Payment id.
		 * @param int $booking_id Booking id the payment belongs to.
		 */
		do_action( 'sc_payment_succeeded', $payment_id, (int) $payment->booking_id );
	}

	/**
	 * Shared response handling for every Stripe API call this class makes.
	 *
	 * @param array|WP_Error $response      Result of wp_remote_post()/wp_remote_get().
	 * @param int             $expected_code HTTP status Stripe returns on success.
	 * @return array<string,mixed>|WP_Error Decoded JSON body on success.
	 */
	private function parse_response( $response, $expected_code ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sc_stripe_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();

		if ( $expected_code !== $code ) {
			$message = $body['error']['message'] ?? __( 'Stripe request failed.', 'service-crew' );

			return new WP_Error( 'sc_stripe_error', $message, array( 'status' => 400 ) );
		}

		return $body;
	}
}
