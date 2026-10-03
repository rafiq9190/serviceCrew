<?php
/**
 * Payment provider gateway interface, Stripe/PayPal key storage, and
 * sc_payments record CRUD — the plan's "single choke point for payment
 * providers" (PayPal later, through the same interface).
 *
 * Deliberately provider-agnostic: everything Stripe-specific (Checkout API
 * calls, webhook route + signature verification) lives in
 * class-service-crew-gateway-stripe.php, which implements
 * Service_Crew_Gateway_Interface defined below. This file only knows how to
 * store/retrieve keys, resolve which gateway is active, and read/write
 * sc_payments rows — it never talks to Stripe's API directly.
 *
 * No booking/capacity code exists yet (Phase 1b-2), so payment success is
 * surfaced as the `sc_payment_succeeded` action rather than this class or
 * the Stripe gateway reaching into a booking table directly. The future
 * class-service-crew-bookings.php hooks that action to flip
 * awaiting_payment -> confirmed.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every payment gateway (Stripe now, PayPal later) implements.
 * Declared here rather than its own file so it's always loaded before any
 * gateway class that references it — this file is required first in the
 * bootstrap (see service-crew.php).
 */
interface Service_Crew_Gateway_Interface {

	/**
	 * Creates a hosted checkout session for a payment record.
	 *
	 * @param array<string,mixed>                          $payment    Payment row as an array (id, booking_id, amount, kind, ...).
	 * @param array<int,array{name:string,amount:float,quantity:int}> $line_items Checkout line items.
	 * @param string                                        $success_url Redirect URL on success.
	 * @param string                                        $cancel_url  Redirect URL on cancel.
	 * @return array{id:string,url:string}|WP_Error
	 */
	public function create_checkout_session( array $payment, array $line_items, $success_url, $cancel_url );

	/**
	 * Refunds a previously captured payment, in part or in full.
	 *
	 * @param array<string,mixed> $payment Payment row as an array; must include provider_reference.
	 * @param float                $amount  Amount to refund.
	 * @param string               $reason  Admin-supplied reason.
	 * @return array{provider_reference:string,status:string}|WP_Error
	 */
	public function refund( array $payment, $amount, $reason );

	/**
	 * Verifies a webhook payload's signature against the configured secret.
	 *
	 * @param string $payload          Raw request body.
	 * @param string $signature_header Raw signature header value.
	 * @param string $webhook_secret   Configured webhook signing secret.
	 * @return bool
	 */
	public function verify_webhook_signature( $payload, $signature_header, $webhook_secret );
}

class Service_Crew_Payments {

	/**
	 * Option name storing the sanitized Stripe key/mode settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'sc_payment_settings';

	/**
	 * REST namespace shared with the rest of the custom admin app.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	const STATUS_PENDING             = 'pending';
	const STATUS_SUCCEEDED           = 'succeeded';
	const STATUS_FAILED              = 'failed';
	const STATUS_REFUNDED            = 'refunded';
	const STATUS_PARTIALLY_REFUNDED  = 'partially_refunded';

	const KIND_DEPOSIT   = 'deposit';
	const KIND_BALANCE   = 'balance';
	const KIND_EXTRA     = 'extra';
	const KIND_SURCHARGE = 'surcharge';

	/**
	 * Default lifetime for a generated pay-page token.
	 *
	 * @var int
	 */
	const TOKEN_TTL_SECONDS = 7 * DAY_IN_SECONDS;

	/**
	 * Registers the REST routes. Called once from the plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers GET/PUT /payment-settings and POST /payment-settings/test-connection.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/payment-settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/payment-settings/test-connection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/pay/(?P<token>[^/]+)/checkout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_pay_page_checkout' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/pay/(?P<token>[^/]+)/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pay_page_status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST handler for the private pay page's "Pay now" button: opens a
	 * fresh Stripe Checkout session for the payment a token resolves to.
	 * Public — the token itself is the access control, same as every other
	 * pay-page route (see class-service-crew-pay-page.php) — and no amount or
	 * booking data is trusted from the request, only the token.
	 *
	 * @param WP_REST_Request $request Request; 'token' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_pay_page_checkout( $request ) {
		$payment = self::find_payment_by_token( (string) $request['token'] );
		if ( ! $payment ) {
			return new WP_Error( 'sc_pay_token_invalid', __( 'This payment link is invalid or has expired.', 'service-crew' ), array( 'status' => 404 ) );
		}

		if ( self::STATUS_SUCCEEDED === $payment->status ) {
			return new WP_Error(
				'sc_pay_already_paid',
				self::KIND_BALANCE === $payment->kind
					? __( 'This balance has already been paid.', 'service-crew' )
					: __( 'This deposit has already been paid.', 'service-crew' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', (int) $payment->booking_id ) );
		if ( ! $booking ) {
			return new WP_Error( 'sc_pay_booking_missing', __( 'The booking for this payment could not be found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$gateway = self::get_gateway( $payment->provider );
		if ( is_wp_error( $gateway ) ) {
			return $gateway;
		}

		$pay_url = Service_Crew_Pay_Page::build_url( (string) $request['token'] );

		$line_item_label = self::KIND_BALANCE === $payment->kind
			/* translators: %s: quote/job title. */
			? sprintf( __( 'Balance — %s', 'service-crew' ), $booking->quote_title ? $booking->quote_title : __( 'ServiceCrew booking', 'service-crew' ) )
			/* translators: %s: quote/job title. */
			: sprintf( __( 'Deposit — %s', 'service-crew' ), $booking->quote_title ? $booking->quote_title : __( 'ServiceCrew booking', 'service-crew' ) );

		$line_items = array(
			array(
				'name'     => $line_item_label,
				'amount'   => (float) $payment->amount,
				'quantity' => 1,
			),
		);

		$session = $gateway->create_checkout_session(
			array(
				'id'             => $payment->id,
				'customer_email' => $booking->customer_email,
			),
			$line_items,
			add_query_arg( 'sc_pay_status', 'success', $pay_url ),
			add_query_arg( 'sc_pay_status', 'cancel', $pay_url )
		);

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return rest_ensure_response( array( 'checkout_url' => $session['url'] ?? '' ) );
	}

	/**
	 * GET handler the pay page polls after returning from Stripe, while the
	 * webhook catches up — same pattern as
	 * Service_Crew_Bookings_Controller::get_payment_status(), keyed by token
	 * instead of booking id + email since the pay page never asks the
	 * customer to type anything.
	 *
	 * @param WP_REST_Request $request Request; 'token' from the route.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pay_page_status( $request ) {
		$payment = self::find_payment_by_token( (string) $request['token'] );
		if ( ! $payment ) {
			return new WP_Error( 'sc_pay_token_invalid', __( 'This payment link is invalid or has expired.', 'service-crew' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'succeeded' => self::STATUS_SUCCEEDED === $payment->status,
			)
		);
	}

	/**
	 * Permission check shared by every route on this class — same access
	 * level as the ServiceCrew admin menu itself.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET handler: settings with every secret masked, never returned in full.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( self::build_response_settings( self::get_saved_settings() ) );
	}

	/**
	 * Reads the saved settings option, merged over defaults. Returns raw
	 * (unmasked) values — callers that expose this over REST must run it
	 * through build_response_settings() first.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_saved_settings() {
		$saved = get_option( self::OPTION_NAME, array() );

		return array_merge( self::get_defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_defaults() {
		return array(
			'mode'                  => 'test',
			'test_publishable_key'  => '',
			'test_secret_key'       => '',
			'test_webhook_secret'   => '',
			'live_publishable_key'  => '',
			'live_secret_key'       => '',
			'live_webhook_secret'   => '',
			// 0 = no page chosen; Service_Crew_Bookings falls back to
			// home_url('/') for either one until an admin picks a page here.
			'success_page_id'       => 0,
			'cancel_page_id'        => 0,
		);
	}

	/**
	 * Masks every secret field and flags which ones are overridden by a
	 * wp-config.php constant, for a future settings screen (wizard step 4) to
	 * grey those fields out. Never returns a secret in full, per the plan.
	 * Also adds a few read-only, never-stored fields the settings screen
	 * needs to render itself: the two page-picker dropdowns (built by core's
	 * own wp_dropdown_pages() rather than hand-rolling an equivalent select
	 * client-side), the webhook URL to paste into Stripe, and the fixed list
	 * of event types Stripe should be configured to send.
	 *
	 * @param array<string,mixed> $settings Raw settings (from get_saved_settings()).
	 * @return array<string,mixed>
	 */
	private static function build_response_settings( array $settings ) {
		$settings['test_secret_key']     = self::mask_secret( $settings['test_secret_key'] );
		$settings['live_secret_key']     = self::mask_secret( $settings['live_secret_key'] );
		$settings['test_webhook_secret'] = self::mask_secret( $settings['test_webhook_secret'] );
		$settings['live_webhook_secret'] = self::mask_secret( $settings['live_webhook_secret'] );

		$settings['test_secret_key_source']     = defined( 'SERVICE_CREW_STRIPE_TEST_SECRET_KEY' ) ? 'wp-config' : 'database';
		$settings['live_secret_key_source']     = defined( 'SERVICE_CREW_STRIPE_LIVE_SECRET_KEY' ) ? 'wp-config' : 'database';
		$settings['test_webhook_secret_source'] = defined( 'SERVICE_CREW_STRIPE_TEST_WEBHOOK_SECRET' ) ? 'wp-config' : 'database';
		$settings['live_webhook_secret_source'] = defined( 'SERVICE_CREW_STRIPE_LIVE_WEBHOOK_SECRET' ) ? 'wp-config' : 'database';

		$settings['success_page_dropdown'] = wp_dropdown_pages(
			array(
				'name'              => 'sc_success_page_id',
				'id'                => 'sc-success-page-select',
				'class'             => 'sc-input',
				'selected'          => $settings['success_page_id'],
				'show_option_none'  => __( '— Use the page the booking widget is on —', 'service-crew' ),
				'option_none_value' => '0',
				'echo'              => 0,
			)
		);

		$settings['cancel_page_dropdown'] = wp_dropdown_pages(
			array(
				'name'              => 'sc_cancel_page_id',
				'id'                => 'sc-cancel-page-select',
				'class'             => 'sc-input',
				'selected'          => $settings['cancel_page_id'],
				'show_option_none'  => __( '— Use the page the booking widget is on —', 'service-crew' ),
				'option_none_value' => '0',
				'echo'              => 0,
			)
		);

		$settings['webhook_url']    = rest_url( self::API_NAMESPACE . Service_Crew_Gateway_Stripe::WEBHOOK_ROUTE );
		$settings['webhook_events'] = Service_Crew_Gateway_Stripe::HANDLED_EVENTS;

		return $settings;
	}

	/**
	 * @param string $value Raw secret value.
	 * @return string Masked value (empty stays empty), never the full secret.
	 */
	private static function mask_secret( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) <= 8 ) {
			return str_repeat( '*', strlen( $value ) );
		}

		return substr( $value, 0, 6 ) . str_repeat( '*', 6 ) . substr( $value, -4 );
	}

	/**
	 * PUT handler: replaces the whole settings object. Secret-like fields
	 * (secret keys, webhook secrets) are the one exception to the usual
	 * full-replace rule used elsewhere in this codebase (Settings/Discounts):
	 * since get_settings() never echoes them back in full, a caller can't
	 * "resubmit what it has" for those fields, so an empty or masked-looking
	 * value here means "leave it alone" rather than "clear it".
	 *
	 * @param WP_REST_Request $request Request with the settings shape in the JSON body.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$current = self::get_saved_settings();

		$sanitized = array(
			'mode'                 => 'live' === ( $params['mode'] ?? $current['mode'] ) ? 'live' : 'test',
			'test_publishable_key' => sanitize_text_field( $params['test_publishable_key'] ?? $current['test_publishable_key'] ),
			'live_publishable_key' => sanitize_text_field( $params['live_publishable_key'] ?? $current['live_publishable_key'] ),
			'test_secret_key'      => $this->resolve_secret_field( $params['test_secret_key'] ?? '', $current['test_secret_key'] ),
			'live_secret_key'      => $this->resolve_secret_field( $params['live_secret_key'] ?? '', $current['live_secret_key'] ),
			'test_webhook_secret'  => $this->resolve_secret_field( $params['test_webhook_secret'] ?? '', $current['test_webhook_secret'] ),
			'live_webhook_secret'  => $this->resolve_secret_field( $params['live_webhook_secret'] ?? '', $current['live_webhook_secret'] ),
			'success_page_id'      => $this->resolve_page_field( $params['success_page_id'] ?? null, $current['success_page_id'] ),
			'cancel_page_id'       => $this->resolve_page_field( $params['cancel_page_id'] ?? null, $current['cancel_page_id'] ),
		);

		update_option( self::OPTION_NAME, $sanitized, false );

		return rest_ensure_response( self::build_response_settings( $sanitized ) );
	}

	/**
	 * A submitted secret field is only treated as a real new value when it's
	 * non-empty and doesn't contain the masking character — otherwise it's
	 * almost certainly the masked display value being echoed back unchanged.
	 *
	 * @param mixed  $submitted Raw posted value for this field.
	 * @param string $existing  Currently stored raw value.
	 * @return string
	 */
	private function resolve_secret_field( $submitted, $existing ) {
		$submitted = is_string( $submitted ) ? trim( $submitted ) : '';

		if ( '' === $submitted || false !== strpos( $submitted, '*' ) ) {
			return $existing;
		}

		return sanitize_text_field( $submitted );
	}

	/**
	 * Validates a posted page id: 0 (no page chosen, falls back to
	 * home_url('/') wherever it's consumed) or an existing 'page' post.
	 * Anything else — a missing key, a non-numeric value, a deleted page id —
	 * falls back to whatever was already saved rather than clearing it, same
	 * "submitting a field you didn't touch shouldn't erase it" rule the
	 * secret fields above follow.
	 *
	 * @param mixed $submitted Posted page id.
	 * @param int   $existing  Currently stored page id.
	 * @return int
	 */
	private function resolve_page_field( $submitted, $existing ) {
		if ( null === $submitted ) {
			return absint( $existing );
		}

		$page_id = absint( $submitted );

		if ( 0 === $page_id ) {
			return 0;
		}

		return 'page' === get_post_type( $page_id ) ? $page_id : absint( $existing );
	}

	/**
	 * The admin-chosen success page's URL, or '' if none is set/valid —
	 * Service_Crew_Bookings falls back to home_url('/') itself so this stays
	 * a pure lookup with no opinion about the ultimate default.
	 *
	 * @return string
	 */
	public static function get_success_page_url() {
		return self::get_configured_page_url( 'success_page_id' );
	}

	/**
	 * @return string
	 */
	public static function get_cancel_page_url() {
		return self::get_configured_page_url( 'cancel_page_id' );
	}

	/**
	 * @param string $option_key 'success_page_id' or 'cancel_page_id'.
	 * @return string
	 */
	private static function get_configured_page_url( $option_key ) {
		$page_id = absint( self::get_saved_settings()[ $option_key ] ?? 0 );
		if ( ! $page_id ) {
			return '';
		}

		$url = get_permalink( $page_id );

		return $url ? $url : '';
	}

	/**
	 * POST handler: verifies the configured secret key actually works by
	 * calling Stripe's /v1/balance endpoint, without needing a real checkout.
	 *
	 * @param WP_REST_Request $request Request, optionally with a 'mode' override in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$mode   = in_array( $params['mode'] ?? '', array( 'test', 'live' ), true ) ? $params['mode'] : self::get_mode();

		$secret_key = self::get_secret_key( $mode );
		if ( '' === $secret_key ) {
			return new WP_Error( 'sc_missing_key', __( 'No Stripe secret key is configured for this mode.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_get(
			Service_Crew_Gateway_Stripe::API_BASE . '/balance',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $secret_key ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sc_stripe_unreachable', $response->get_error_message(), array( 'status' => 502 ) );
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = is_array( $body ) ? ( $body['error']['message'] ?? '' ) : '';

			return new WP_Error( 'sc_stripe_connection_failed', $message ? $message : __( 'Stripe rejected these keys.', 'service-crew' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'mode'    => $mode,
			)
		);
	}

	/**
	 * @return string 'test' or 'live'.
	 */
	public static function get_mode() {
		return self::get_saved_settings()['mode'];
	}

	/**
	 * @param string|null $mode 'test'/'live', or null to use the configured mode.
	 * @return string Publishable key for that mode (never secret, safe to expose to the browser).
	 */
	public static function get_publishable_key( $mode = null ) {
		$settings = self::get_saved_settings();
		$mode     = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : $settings['mode'];

		return 'live' === $mode ? $settings['live_publishable_key'] : $settings['test_publishable_key'];
	}

	/**
	 * Resolves the secret key for a mode, preferring a wp-config.php constant
	 * over the stored option — the plan's "a wp-config.php constant is
	 * supported as an alternative" for careful secret storage.
	 *
	 * @param string|null $mode 'test'/'live', or null to use the configured mode.
	 * @return string
	 */
	public static function get_secret_key( $mode = null ) {
		return self::resolve_secret( $mode, 'SERVICE_CREW_STRIPE_TEST_SECRET_KEY', 'SERVICE_CREW_STRIPE_LIVE_SECRET_KEY', 'test_secret_key', 'live_secret_key' );
	}

	/**
	 * Resolves the webhook signing secret for a mode, same constant-override
	 * rule as get_secret_key().
	 *
	 * @param string|null $mode 'test'/'live', or null to use the configured mode.
	 * @return string
	 */
	public static function get_webhook_secret( $mode = null ) {
		return self::resolve_secret( $mode, 'SERVICE_CREW_STRIPE_TEST_WEBHOOK_SECRET', 'SERVICE_CREW_STRIPE_LIVE_WEBHOOK_SECRET', 'test_webhook_secret', 'live_webhook_secret' );
	}

	/**
	 * @param string|null $mode          'test'/'live', or null to use the configured mode.
	 * @param string      $test_constant wp-config.php constant name for test mode.
	 * @param string      $live_constant wp-config.php constant name for live mode.
	 * @param string      $test_option_key Settings array key for test mode.
	 * @param string      $live_option_key Settings array key for live mode.
	 * @return string
	 */
	private static function resolve_secret( $mode, $test_constant, $live_constant, $test_option_key, $live_option_key ) {
		$settings = self::get_saved_settings();
		$mode     = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : $settings['mode'];
		$constant = 'live' === $mode ? $live_constant : $test_constant;

		if ( defined( $constant ) && '' !== constant( $constant ) ) {
			return (string) constant( $constant );
		}

		return 'live' === $mode ? $settings[ $live_option_key ] : $settings[ $test_option_key ];
	}

	/**
	 * Resolves which gateway implementation handles a provider. Hardcoded to
	 * Stripe for now (the only implementation that exists) rather than a
	 * registry — a second branch is all PayPal will need later.
	 *
	 * @param string $provider Provider slug, e.g. 'stripe'.
	 * @return Service_Crew_Gateway_Interface|WP_Error
	 */
	public static function get_gateway( $provider = 'stripe' ) {
		if ( 'stripe' === $provider ) {
			return new Service_Crew_Gateway_Stripe();
		}

		return new WP_Error( 'sc_unknown_gateway', __( 'Unknown payment provider.', 'service-crew' ) );
	}

	/**
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_payments';
	}

	/**
	 * Inserts a new payment record in 'pending' status. Callers (the future
	 * booking/checkout flow) create this row before asking the gateway for a
	 * checkout session, then pass its id in as session metadata so the
	 * webhook can find it again.
	 *
	 * @param array{booking_id:int,kind:string,amount:float,provider?:string} $data Payment fields.
	 * @return int|WP_Error New payment id, or WP_Error on failure.
	 */
	public static function create_payment( array $data ) {
		global $wpdb;

		$kind = in_array( $data['kind'] ?? '', array( self::KIND_DEPOSIT, self::KIND_BALANCE, self::KIND_EXTRA, self::KIND_SURCHARGE ), true )
			? $data['kind']
			: self::KIND_DEPOSIT;

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'booking_id' => absint( $data['booking_id'] ?? 0 ),
				'kind'       => $kind,
				'amount'     => round( max( 0.0, (float) ( $data['amount'] ?? 0 ) ), 2 ),
				'status'     => self::STATUS_PENDING,
				'provider'   => sanitize_key( $data['provider'] ?? 'stripe' ),
			),
			array( '%d', '%s', '%f', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'sc_payment_insert_failed', __( 'Could not create the payment record.', 'service-crew' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Whitelisted update of an existing payment row — the single write path
	 * used by webhook handling, token generation and refund processing so no
	 * caller can write an arbitrary column.
	 *
	 * @param int                  $payment_id Payment id.
	 * @param array<string,mixed>  $fields     Subset of: status, provider_reference, paid_at, token_hash, token_expires_at, amount.
	 * @return bool
	 */
	public static function update_payment( $payment_id, array $fields ) {
		global $wpdb;

		$allowed = array( 'status', 'provider_reference', 'paid_at', 'token_hash', 'token_expires_at', 'amount' );
		$data    = array();
		$formats = array();

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			$data[ $key ] = $value;
			$formats[]    = 'amount' === $key ? '%f' : '%s';
		}

		if ( empty( $data ) ) {
			return false;
		}

		return false !== $wpdb->update( self::table(), $data, array( 'id' => absint( $payment_id ) ), $formats, array( '%d' ) );
	}

	/**
	 * @param int $payment_id Payment id.
	 * @return object|null
	 */
	public static function get_payment( $payment_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $payment_id ) ) );
	}

	/**
	 * @param int $booking_id Booking id.
	 * @return object[]
	 */
	public static function get_payments_for_booking( $booking_id ) {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_id = %d ORDER BY created_at ASC', absint( $booking_id ) ) );
	}

	/**
	 * Most recent payment for a booking still eligible for a refund
	 * (succeeded, or already partially refunded) — the admin refund action's
	 * starting point. The deposit-only system today means a booking normally
	 * has at most one of these; "most recent" future-proofs this for Phase
	 * 1c's balance/extra payments without the caller needing to change.
	 *
	 * @param int $booking_id Booking id.
	 * @return object|null
	 */
	public static function get_latest_refundable_payment( $booking_id ) {
		$eligible = array_values(
			array_filter(
				self::get_payments_for_booking( $booking_id ),
				function ( $payment ) {
					return in_array( $payment->status, array( self::STATUS_SUCCEEDED, self::STATUS_PARTIALLY_REFUNDED ), true );
				}
			)
		);

		return empty( $eligible ) ? null : end( $eligible );
	}

	/**
	 * Sum of successful refunds already issued against a payment — used to
	 * work out how much of it still remains refundable.
	 *
	 * @param int $payment_id Payment id.
	 * @return float
	 */
	public static function get_refunded_amount( $payment_id ) {
		global $wpdb;

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount),0) FROM ' . $wpdb->prefix . 'sc_refunds WHERE payment_id = %d AND status = %s',
				absint( $payment_id ),
				'succeeded'
			)
		);
	}

	/**
	 * Generates a single-use pay-page token for a payment: the plaintext is
	 * returned once (to build the link), only its SHA-256 hash is stored, per
	 * the plan's "tokens are stored hashed... and are single-purpose".
	 *
	 * @param int      $payment_id  Payment id.
	 * @param int|null $ttl_seconds Token lifetime; defaults to TOKEN_TTL_SECONDS.
	 * @return string|WP_Error Plaintext token, or WP_Error on failure.
	 */
	public static function generate_pay_token( $payment_id, $ttl_seconds = null ) {
		$ttl_seconds = null === $ttl_seconds ? self::TOKEN_TTL_SECONDS : max( 60, (int) $ttl_seconds );
		$token       = wp_generate_password( 32, false, false );

		$updated = self::update_payment(
			$payment_id,
			array(
				'token_hash'       => hash( 'sha256', $token ),
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds ),
			)
		);

		if ( ! $updated ) {
			return new WP_Error( 'sc_token_generation_failed', __( 'Could not create a pay link for this payment.', 'service-crew' ) );
		}

		return $token;
	}

	/**
	 * Looks up a payment by its plaintext pay-page token. Returns null for an
	 * unknown or expired token — callers must not distinguish the two in any
	 * response, so an attacker can't use this to enumerate valid tokens.
	 *
	 * @param string $token Plaintext token from the pay-page URL.
	 * @return object|null
	 */
	public static function find_payment_by_token( $token ) {
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return null;
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s', hash( 'sha256', $token ) ) );

		if ( ! $row || ! $row->token_expires_at || strtotime( $row->token_expires_at ) < time() ) {
			return null;
		}

		return $row;
	}

	/**
	 * Refunds a payment through its provider and logs the sc_refunds row.
	 * This is the reusable core the not-yet-built "Admin refund action" task
	 * (REST route + board button + capability check) will call — it does not
	 * itself enforce who may call it, callers must gate that separately.
	 *
	 * @param int    $payment_id Payment id.
	 * @param float  $amount     Amount to refund.
	 * @param string $reason     Admin-supplied reason, logged with the refund.
	 * @return array{refund_id:int,status:string}|WP_Error
	 */
	public static function process_refund( $payment_id, $amount, $reason ) {
		$payment = self::get_payment( $payment_id );
		if ( ! $payment ) {
			return new WP_Error( 'sc_payment_not_found', __( 'Payment not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$gateway = self::get_gateway( $payment->provider );
		if ( is_wp_error( $gateway ) ) {
			return $gateway;
		}

		$result = $gateway->refund( (array) $payment, $amount, $reason );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'sc_refunds',
			array(
				'payment_id'         => (int) $payment_id,
				'booking_id'         => (int) $payment->booking_id,
				'amount'             => round( (float) $amount, 2 ),
				'reason'             => sanitize_textarea_field( $reason ),
				'status'             => 'succeeded',
				'provider_reference' => sanitize_text_field( $result['provider_reference'] ?? '' ),
				'refunded_by'        => get_current_user_id(),
			),
			array( '%d', '%d', '%f', '%s', '%s', '%s', '%d' )
		);

		$new_status = ( (float) $amount >= (float) $payment->amount ) ? self::STATUS_REFUNDED : self::STATUS_PARTIALLY_REFUNDED;
		self::update_payment( $payment_id, array( 'status' => $new_status ) );

		return array(
			'refund_id' => (int) $wpdb->insert_id,
			'status'    => $new_status,
		);
	}
}
