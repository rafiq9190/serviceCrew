<?php
/**
 * Instant-booking creation: turns a customer's service/add-on pick, date and
 * contact details into a real `sc_bookings` row, a `sc_payments` deposit
 * record, and a live Stripe Checkout session — then confirms the booking
 * once `sc_payment_succeeded` fires (see class-service-crew-gateway-stripe.php).
 *
 * This is a deliberately narrower slice than the plan's full Phase 1b-2
 * "instant booking" (which also needs class-service-crew-customers.php,
 * class-service-crew-capacity.php and class-service-crew-matching.php) —
 * built just far enough that a real customer can actually pay a real
 * deposit against a real booking, per explicit request:
 *  - **One service per booking.** sc_bookings.service_id is a single
 *    nullable column and sc_booking_components has no way to attribute a
 *    line back to more than one parent service, so a booking here is always
 *    exactly one leaf service plus its own selected add-ons — not the
 *    multi-item cart [service_crew_services]/[service_crew_booking]'s Step 1
 *    browser supports for live pricing preview. The REST controller enforces
 *    this before calling in here.
 *  - **No sc_customers table yet.** customer_name/email/phone are stored
 *    directly on the booking row, matching the activator's own note that
 *    they're "the source of truth" until Service_Crew_Customers exists;
 *    customer_id is left null.
 *  - **No capacity check.** Any open business day (per
 *    Service_Crew_Settings) is bookable regardless of load — there is no
 *    Service_Crew_Capacity yet, so a date is never disabled server-side and
 *    is_emergency is always 0.
 *  - **Deposit only, not a tier picker.** The customer always pays exactly
 *    the admin's resolved minimum-deposit amount now (build_payment_options()'s
 *    first, is_minimum row) — the plan's full "minimum, plus every tier
 *    above it" checkout selector is not built. "No payment, no booking" and
 *    any discount the minimum percentage happens to qualify for both still
 *    apply correctly; picking to pay *more* than the minimum does not yet.
 *  - **Geocoding is best-effort.** A failed/partial lookup flags the
 *    booking (per the plan, this never blocks it) rather than rejecting it.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Bookings {

	const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
	const STATUS_CONFIRMED        = 'confirmed';
	const STATUS_COMPLETED        = 'completed';
	const STATUS_CANCELLED        = 'cancelled';

	/**
	 * Statuses an admin can manually set from the bookings list right now.
	 * Deliberately a subset of the plan's full status list: the quote-only
	 * statuses (requested/quoted/quote_expired/quote_rejected) don't apply to
	 * an instant booking, the crew-workflow statuses (assigned/on_the_way/
	 * in_progress) are meaningless before class-service-crew-assignments.php
	 * exists (Phase 1c), and pending_approval is unreachable since
	 * is_emergency is always 0 today (see the class docblock). This list is
	 * a manual-override convenience, not the plan's real status machine.
	 *
	 * @var string[]
	 */
	const ADMIN_SETTABLE_STATUSES = array(
		self::STATUS_AWAITING_PAYMENT,
		self::STATUS_CONFIRMED,
		self::STATUS_COMPLETED,
		self::STATUS_CANCELLED,
	);

	/**
	 * Hooks the payment-succeeded event fired by the gateway. Called once
	 * from the plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'sc_payment_succeeded', array( $this, 'confirm_booking' ), 10, 2 );
	}

	/**
	 * Validates and recomputes a booking entirely from trusted server-side
	 * data (service/add-on prices, tax, discount and deposit-tier settings
	 * are all re-read here; nothing from the request is trusted as a price),
	 * inserts the booking and its add-on line snapshots, opens a Stripe
	 * Checkout session for the deposit, and returns the URL to redirect the
	 * customer to.
	 *
	 * @param array<string,mixed> $input {
	 *     @type int    $service_id           Leaf sc_service post ID.
	 *     @type int    $qty                  Customer-chosen quantity (per-unit services only).
	 *     @type array  $addons               Map of component index => {checked:bool, qty?:int}.
	 *     @type string $date                 YYYY-MM-DD.
	 *     @type int    $arrival_window_index Index into Service_Crew_Settings's arrival_windows.
	 *     @type string $customer_name
	 *     @type string $customer_email
	 *     @type string $customer_phone
	 *     @type string $address
	 *     @type string $zip
	 *     @type string $return_url           Page to send the customer back to after Stripe Checkout.
	 * }
	 * @return array{booking_id:int,payment_id:int,checkout_url:string}|WP_Error
	 */
	public static function create_instant_booking( array $input ) {
		$service_id = absint( $input['service_id'] ?? 0 );
		$service    = $service_id ? get_post( $service_id ) : null;

		if ( ! $service || 'sc_service' !== $service->post_type || 'publish' !== $service->post_status ) {
			return new WP_Error( 'sc_invalid_service', __( 'That service is not available.', 'service-crew' ), array( 'status' => 400 ) );
		}

		if ( Service_Crew_Services::has_child_services( $service_id ) ) {
			return new WP_Error( 'sc_invalid_service', __( 'Please choose a specific service, not a category.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$pricing_fields = Service_Crew_Services::get_pricing_fields( $service_id, false );
		$components     = Service_Crew_Components::get_components( $service_id, false );

		$requested_qty = isset( $input['qty'] ) ? absint( $input['qty'] ) : null;
		$service_line  = Service_Crew_Pricing::calculate_service_line( $pricing_fields, $requested_qty );

		$addons               = is_array( $input['addons'] ?? null ) ? $input['addons'] : array();
		$component_lines      = array();
		$component_snapshots  = array();

		foreach ( $components as $index => $component ) {
			$selection = $addons[ $index ] ?? $addons[ (string) $index ] ?? null;
			$checked   = ! empty( $component['required'] ) || ! empty( $selection['checked'] );

			if ( ! $checked ) {
				continue;
			}

			$requested_component_qty = isset( $selection['qty'] ) ? absint( $selection['qty'] ) : null;
			$line                    = Service_Crew_Pricing::calculate_component_line( $component, $requested_component_qty );

			$component_lines[]     = $line;
			$component_snapshots[] = array(
				'component_id'          => (int) $index,
				'component_name'        => $line['name'],
				'is_required'           => ! empty( $component['required'] ),
				'quantity'              => $line['quantity'],
				'unit_price'            => (float) ( $component['unit_price'] ?? 0 ),
				'unit_duration_minutes' => (int) ( $component['unit_duration_minutes'] ?? 0 ),
				'line_total'            => $line['price'],
			);
		}

		$subtotal = (float) Service_Crew_Pricing::calculate_subtotal( $service_line, $component_lines )['price'];

		if ( $subtotal <= 0 ) {
			return new WP_Error( 'sc_invalid_total', __( 'This booking has no charge to collect.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$scheduling_settings = Service_Crew_Settings::get_saved_settings();
		$discount_tiers       = Service_Crew_Discounts::get_saved_tiers();

		// Deposit bracket is looked up against the pre-tax, pre-discount
		// subtotal ("booking amount") so it doesn't depend on the discount it
		// then feeds into — see the class docblock's "deposit only" note.
		$deposit_tier    = Service_Crew_Pricing::get_matching_deposit_tier( $scheduling_settings['minimum_deposit_tiers'], $subtotal );
		$minimum_percent = $deposit_tier ? (float) $deposit_tier['deposit_percent'] : 100.0;

		$payment_options = Service_Crew_Pricing::build_payment_options( $subtotal, $discount_tiers, $minimum_percent );
		$chosen          = $payment_options[0];

		$tax_rate       = (float) $scheduling_settings['tax_rate_percent'];
		$tax_mode       = $scheduling_settings['tax_mode'];
		$tax_amount     = Service_Crew_Pricing::calculate_tax_amount( $chosen['total'], $tax_rate, $tax_mode );
		$total_with_tax = 'inclusive' === $tax_mode ? $chosen['total'] : round( $chosen['total'] + $tax_amount, 2 );
		$amount_due_now = round( $total_with_tax * ( $chosen['percent_paid_now'] / 100 ), 2 );

		$date = isset( $input['date'] ) ? sanitize_text_field( $input['date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'sc_invalid_date', __( 'Please choose a valid date.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$arrival_windows = $scheduling_settings['arrival_windows'];
		$window_index    = isset( $input['arrival_window_index'] ) ? absint( $input['arrival_window_index'] ) : null;
		$arrival_window  = isset( $arrival_windows[ $window_index ] ) ? $arrival_windows[ $window_index ]['label'] : '';

		if ( '' === $arrival_window ) {
			return new WP_Error( 'sc_invalid_window', __( 'Please choose an arrival window.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$customer_name  = sanitize_text_field( $input['customer_name'] ?? '' );
		$customer_email = sanitize_email( $input['customer_email'] ?? '' );

		if ( '' === $customer_name || ! is_email( $customer_email ) ) {
			return new WP_Error( 'sc_invalid_contact', __( 'Please provide your name and a valid email address.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$customer_phone = sanitize_text_field( $input['customer_phone'] ?? '' );
		$address        = sanitize_text_field( $input['address'] ?? '' );
		$zip            = sanitize_text_field( $input['zip'] ?? '' );

		$geocoding = new Service_Crew_Geocoding();
		$geocode   = $geocoding->geocode( $address, $zip );

		global $wpdb;

		$fields = array(
			'service_id'              => array( $service_id, '%d' ),
			'customer_name'           => array( $customer_name, '%s' ),
			'customer_email'          => array( $customer_email, '%s' ),
			'customer_phone'          => array( $customer_phone, '%s' ),
			'address'                 => array( $address, '%s' ),
			'zip'                     => array( $zip, '%s' ),
			'lat'                     => array( $geocode['lat'], '%f' ),
			'lng'                     => array( $geocode['lng'], '%f' ),
			'geocode_quality'         => array( $geocode['quality'], '%s' ),
			'preferred_date'          => array( $date, '%s' ),
			'arrival_window'          => array( $arrival_window, '%s' ),
			'duration_days'           => array( 1, '%d' ),
			'is_emergency'            => array( 0, '%d' ),
			'crew_needed'             => array( 1, '%d' ),
			'status'                  => array( self::STATUS_AWAITING_PAYMENT, '%s' ),
			'source'                  => array( 'instant', '%s' ),
			'subtotal_amount'         => array( $subtotal, '%f' ),
			'advance_discount_percent' => array( $chosen['percent_paid_now'], '%f' ),
			'advance_discount_amount' => array( $chosen['discount_amount'], '%f' ),
			'customer_total'          => array( $total_with_tax, '%f' ),
			'deposit_amount'          => array( $amount_due_now, '%f' ),
			'amount_paid'             => array( 0, '%f' ),
			'flag_address_review'     => array( ( '' === $address && '' === $zip ) ? 1 : 0, '%d' ),
			'flag_address_approximate' => array( Service_Crew_Geocoding::QUALITY_ZIP === $geocode['quality'] ? 1 : 0, '%d' ),
		);

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'sc_bookings',
			array_map( function ( $pair ) { return $pair[0]; }, $fields ),
			array_values( array_map( function ( $pair ) { return $pair[1]; }, $fields ) )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'sc_booking_insert_failed', __( 'Could not create the booking.', 'service-crew' ) );
		}

		$booking_id = (int) $wpdb->insert_id;

		foreach ( $component_snapshots as $snapshot ) {
			$wpdb->insert(
				$wpdb->prefix . 'sc_booking_components',
				array(
					'booking_id'               => $booking_id,
					'component_id'              => $snapshot['component_id'],
					'component_name'            => $snapshot['component_name'],
					'is_required'               => $snapshot['is_required'] ? 1 : 0,
					'quantity'                  => $snapshot['quantity'],
					'unit_price'                => $snapshot['unit_price'],
					'unit_duration_minutes'     => $snapshot['unit_duration_minutes'],
					'line_subtotal'             => $snapshot['line_total'],
					'quantity_discount_amount'  => 0,
					'line_total'                => $snapshot['line_total'],
				),
				array( '%d', '%d', '%s', '%d', '%d', '%f', '%d', '%f', '%f', '%f' )
			);
		}

		$payment_id = Service_Crew_Payments::create_payment(
			array(
				'booking_id' => $booking_id,
				'kind'       => Service_Crew_Payments::KIND_DEPOSIT,
				'amount'     => $amount_due_now,
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		$gateway = Service_Crew_Payments::get_gateway( 'stripe' );
		if ( is_wp_error( $gateway ) ) {
			return $gateway;
		}

		$return_base = isset( $input['return_url'] ) ? esc_url_raw( $input['return_url'] ) : home_url( '/' );

		$success_url = add_query_arg(
			array(
				'sc_booking' => $booking_id,
				'sc_status'  => 'success',
				'sc_email'   => rawurlencode( $customer_email ),
			),
			$return_base
		);

		$cancel_url = add_query_arg(
			array(
				'sc_booking' => $booking_id,
				'sc_status'  => 'cancel',
			),
			$return_base
		);

		$line_items = array(
			array(
				/* translators: %s: service name. */
				'name'     => sprintf( __( 'Booking deposit — %s', 'service-crew' ), Service_Crew_Services::get_plain_title( $service ) ),
				'amount'   => $amount_due_now,
				'quantity' => 1,
			),
		);

		$session = $gateway->create_checkout_session(
			array(
				'id'             => $payment_id,
				'customer_email' => $customer_email,
			),
			$line_items,
			$success_url,
			$cancel_url
		);

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return array(
			'booking_id'   => $booking_id,
			'payment_id'   => $payment_id,
			'checkout_url' => $session['url'] ?? '',
		);
	}

	/**
	 * Confirms a booking once its deposit succeeds. Hooked to
	 * `sc_payment_succeeded`, which the Stripe gateway fires idempotently —
	 * see class-service-crew-gateway-stripe.php's process_event().
	 *
	 * Deposit-only for now: this overwrites amount_paid rather than adding to
	 * it, which is correct as long as the deposit is the only payment a
	 * booking ever receives. Phase 1c's balance/extra payments will need to
	 * change this to an increment.
	 *
	 * @param int $payment_id Payment id.
	 * @param int $booking_id Booking id.
	 * @return void
	 */
	public function confirm_booking( $payment_id, $booking_id ) {
		if ( ! $booking_id ) {
			return;
		}

		$payment = Service_Crew_Payments::get_payment( $payment_id );
		if ( ! $payment ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'sc_bookings',
			array(
				'status'      => self::STATUS_CONFIRMED,
				'amount_paid' => (float) $payment->amount,
			),
			array( 'id' => $booking_id ),
			array( '%s', '%f' ),
			array( '%d' )
		);
	}

	/**
	 * Manually sets a booking's status from the admin bookings list — see
	 * ADMIN_SETTABLE_STATUSES for why this is a small fixed set rather than
	 * the plan's full status machine.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $status     One of ADMIN_SETTABLE_STATUSES.
	 * @return true|WP_Error
	 */
	public static function update_status( $booking_id, $status ) {
		if ( ! in_array( $status, self::ADMIN_SETTABLE_STATUSES, true ) ) {
			return new WP_Error( 'sc_invalid_status', __( 'That status is not valid.', 'service-crew' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'sc_bookings',
			array( 'status' => $status ),
			array( 'id' => absint( $booking_id ) ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'sc_status_update_failed', __( 'Could not update the booking status.', 'service-crew' ) );
		}

		return true;
	}
}
