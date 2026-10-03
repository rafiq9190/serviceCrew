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
 *  - **Service count is admin-configurable.** By default a booking is
 *    exactly one leaf service plus its own selected add-ons; when
 *    Service_Crew_Settings::get_saved_settings()['allow_multiple_services']
 *    is on, a customer may combine several services into one booking, each
 *    with its own add-ons (see sc_booking_services, one row per service, and
 *    sc_booking_components.booking_service_id linking an add-on back to
 *    which service it belongs to) — still charged as a single combined
 *    deposit, not itemized per service in Stripe. MAX_ITEMS_PER_BOOKING caps
 *    item count regardless of the setting, since this endpoint is public and
 *    unauthenticated. This method re-validates every item and the setting
 *    itself — the REST controller passes $input straight through untouched.
 *  - **customer_name/email/phone stay on the booking row regardless.**
 *    customer_id is now populated via Service_Crew_Customers::find_or_create(),
 *    but the booking's own columns remain the point-in-time source of truth
 *    for what the job was actually billed/contacted as — no sc_customers
 *    read path exists yet (Phase 1e CRM) to make them redundant.
 *  - **Slot-level check only, not real capacity.** A date+arrival-window
 *    combo is blocked once *any* instant booking already holds it (see
 *    get_booked_windows()) — but there is still no Service_Crew_Capacity, so
 *    this ignores crew count/pooled hours entirely; it is a "one job per
 *    window" rule, not a load calculation. Emergency Booking (is_emergency)
 *    only bypasses this once *every* arrival window on that date is already
 *    taken (any date, not just today) and adds Service_Crew_Settings'
 *    emergency_surcharge on top of the total.
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
	const STATUS_ASSIGNED         = 'assigned';
	const STATUS_ON_THE_WAY       = 'on_the_way';
	const STATUS_IN_PROGRESS      = 'in_progress';
	const STATUS_COMPLETED        = 'completed';
	const STATUS_CANCELLED        = 'cancelled';

	/**
	 * WP-Cron hook name for the awaiting_payment expiry sweep.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'sc_expire_awaiting_payment';

	/**
	 * How long an instant booking may sit in awaiting_payment before the cron
	 * sweep cancels it — matches Stripe Checkout's own default session
	 * expiry (24h), since the customer's payment link is already dead by
	 * then regardless. Not admin-configurable; the plan gives this no
	 * settings-screen timer the way quote validity/no-response do.
	 *
	 * @var int
	 */
	const AWAITING_PAYMENT_EXPIRY_HOURS = 24;

	/**
	 * Max completion photos accepted at job-status Complete — same cap as
	 * the quote form's own photo upload (class-service-crew-quotes.php).
	 *
	 * @var int
	 */
	const JOB_COMPLETION_MAX_PHOTOS = 6;

	/**
	 * Max size per completion photo, in bytes (5 MB) — same cap as the quote
	 * form's own photo upload.
	 *
	 * @var int
	 */
	const JOB_COMPLETION_MAX_PHOTO_BYTES = 5242880;

	/**
	 * Hard cap on how many calendar days a single `available-dates` request
	 * may check — a calendar view never realistically needs more than this
	 * in one call, and it bounds the work one HTTP request can trigger
	 * (an employee-capacity calc + a DB query per date).
	 *
	 * @var int
	 */
	const AVAILABLE_DATES_MAX_DAYS = 90;

	/**
	 * How far before an `available-dates` request's own range to also fetch
	 * bookings — a multi-day job (duration_days > 1, only reachable today via
	 * an admin-priced quote; see get_available_dates()'s own note) that
	 * started before the range can still cover a date inside it. Generous for
	 * a door-to-door service job; not a hard guarantee for a job someone
	 * deliberately scheduled longer than this.
	 *
	 * @var int
	 */
	const AVAILABLE_DATES_LOOKBACK_DAYS = 60;

	/**
	 * Hard cap on items per booking, regardless of the allow_multiple_services
	 * setting — create_instant_booking() is public/unauthenticated, so an
	 * arbitrary-length $input['items'] needs its own ceiling independent of
	 * whatever the admin allows, to bound the work one HTTP request can
	 * trigger (a get_post()/pricing/insert pass per item).
	 *
	 * @var int
	 */
	const MAX_ITEMS_PER_BOOKING = 20;

	/**
	 * Statuses an admin can manually set from the bookings list right now.
	 * Deliberately a subset of the plan's full status list: the quote-only
	 * statuses (requested/quoted/quote_expired/quote_rejected) don't apply to
	 * an instant booking, and `assigned`/`on_the_way`/`in_progress` are all
	 * set programmatically instead — by Service_Crew_Assignments::assign_crew()
	 * and the employee job-status link's update_job_status() respectively
	 * (V1's email-based stand-in for the deferred crew PWA) — not picked
	 * from this dropdown. pending_approval is unreachable since is_emergency
	 * is always 0 today (see the class docblock). This list is a
	 * manual-override convenience, not the plan's real status machine.
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
	 * Hooks the payment-succeeded event fired by the gateway and the
	 * awaiting_payment expiry sweep. Called once from the plugin bootstrap.
	 *
	 * The schedule-if-missing check runs here (on every `plugins_loaded`)
	 * rather than only in Service_Crew_Activator::activate() — cheap (one
	 * option read via wp_next_scheduled()) and it's what actually gets this
	 * cron job running on a site that was already active before this task
	 * existed, with no deactivate/reactivate required. Service_Crew_Deactivator
	 * clears it on deactivation.
	 */
	public function __construct() {
		add_action( 'sc_payment_succeeded', array( $this, 'confirm_booking' ), 10, 2 );
		add_action( self::CRON_HOOK, array( $this, 'expire_awaiting_payment' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Every arrival-window label already taken on a given date — an instant
	 * booking or a paid/scheduled quote in `awaiting_payment`/`confirmed`/
	 * `completed`. Used both for the booking flow's client-side grey-out
	 * (Service_Crew_Bookings_Controller::get_booked_windows()) and by
	 * create_instant_booking() itself to decide whether a slot is taken and
	 * whether the whole date is fully booked (see the class docblock's
	 * "slot-level check only" note).
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return string[]
	 */
	public static function get_booked_windows( $date ) {
		global $wpdb;

		$windows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT arrival_window FROM {$wpdb->prefix}sc_bookings
				WHERE preferred_date = %s
				AND status IN ('awaiting_payment','confirmed','completed')
				AND arrival_window IS NOT NULL AND arrival_window != ''",
				$date
			)
		);

		return array_values( $windows );
	}

	/**
	 * Validates every requested item and builds each one's priced service +
	 * component lines, returning the aggregate subtotal and duration. This is
	 * the one chunk of pricing logic create_instant_booking() and the public
	 * `calculate-price` preview endpoint
	 * (Service_Crew_Bookings_Controller::calculate_price()) share verbatim,
	 * so the two can never drift apart — no date, slot, deposit-tier, tax or
	 * emergency-surcharge logic lives here; callers add that themselves on
	 * top of the subtotal this returns.
	 *
	 * @param array<int,array{service_id:int,qty?:int,addons?:array}> $items Raw item selections — see create_instant_booking()'s own $input docblock for the shape.
	 * @param array<string,mixed> $scheduling_settings Service_Crew_Settings::get_saved_settings()'s result — only 'allow_multiple_services' is read here.
	 * @return array{subtotal:float,duration_minutes:float,service_rows:array}|WP_Error
	 */
	private static function build_items_pricing( array $items, array $scheduling_settings ) {
		if ( empty( $items ) ) {
			return new WP_Error( 'sc_invalid_service', __( 'Please choose a service.', 'service-crew' ), array( 'status' => 400 ) );
		}

		if ( count( $items ) > self::MAX_ITEMS_PER_BOOKING ) {
			return new WP_Error( 'sc_too_many_items', __( 'Too many services selected for one booking.', 'service-crew' ), array( 'status' => 400 ) );
		}

		if ( count( $items ) > 1 && empty( $scheduling_settings['allow_multiple_services'] ) ) {
			return new WP_Error( 'sc_multiple_services_not_allowed', __( 'Please select only one service for this booking — remove the extra selections above.', 'service-crew' ), array( 'status' => 400 ) );
		}

		// Flattened purely so calculate_subtotal() — the one place that owns
		// how a subtotal is rounded — can be called once over everything,
		// rather than re-summing every item's own price by hand: every
		// item's component lines, plus every item's own service line except
		// the first (which becomes calculate_subtotal()'s $service_line arg).
		$lines_for_subtotal = array();
		$first_service_line = null;
		$service_rows       = array();

		foreach ( $items as $item_input ) {
			$service_id = absint( $item_input['service_id'] ?? 0 );
			$service    = $service_id ? get_post( $service_id ) : null;

			if ( ! $service || 'sc_service' !== $service->post_type || 'publish' !== $service->post_status ) {
				return new WP_Error( 'sc_invalid_service', __( 'That service is not available.', 'service-crew' ), array( 'status' => 400 ) );
			}

			if ( Service_Crew_Services::has_child_services( $service_id ) ) {
				return new WP_Error( 'sc_invalid_service', __( 'Please choose a specific service, not a category.', 'service-crew' ), array( 'status' => 400 ) );
			}

			$pricing_fields = Service_Crew_Services::get_pricing_fields( $service_id, false );
			$components     = Service_Crew_Components::get_components( $service_id, false );

			$requested_qty = isset( $item_input['qty'] ) ? absint( $item_input['qty'] ) : null;
			$service_line  = Service_Crew_Pricing::calculate_service_line( $pricing_fields, $requested_qty );

			$addons              = is_array( $item_input['addons'] ?? null ) ? $item_input['addons'] : array();
			$component_snapshots = array();

			foreach ( $components as $index => $component ) {
				$selection = $addons[ $index ] ?? $addons[ (string) $index ] ?? null;
				$checked   = ! empty( $component['required'] ) || ! empty( $selection['checked'] );

				if ( ! $checked ) {
					continue;
				}

				$requested_component_qty = isset( $selection['qty'] ) ? absint( $selection['qty'] ) : null;
				$line                    = Service_Crew_Pricing::calculate_component_line( $component, $requested_component_qty );

				$lines_for_subtotal[]  = $line;
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

			if ( null === $first_service_line ) {
				$first_service_line = $service_line;
			} else {
				$lines_for_subtotal[] = $service_line;
			}

			$service_rows[] = array(
				'service'             => $service,
				'service_id'          => $service_id,
				'pricing_fields'      => $pricing_fields,
				'service_line'        => $service_line,
				'component_snapshots' => $component_snapshots,
			);
		}

		$subtotal_result = Service_Crew_Pricing::calculate_subtotal( $first_service_line, $lines_for_subtotal );
		$subtotal        = (float) $subtotal_result['price'];

		if ( $subtotal <= 0 ) {
			return new WP_Error( 'sc_invalid_total', __( 'This booking has no charge to collect.', 'service-crew' ), array( 'status' => 400 ) );
		}

		return array(
			'subtotal'         => $subtotal,
			'duration_minutes' => (float) $subtotal_result['duration_minutes'],
			'service_rows'     => $service_rows,
		);
	}

	/**
	 * Server-side price preview for the booking widget's service-selection
	 * step — the plan's "live price, time and quantity discount lines" from
	 * `POST /calculate-price`, the one place price math should run per
	 * "the browser never duplicates the formula". Resolves subtotal ->
	 * matching deposit-tier -> whichever advance-discount tier that
	 * percentage qualifies for -> tax, via the exact same
	 * build_items_pricing() create_instant_booking() itself uses, so a
	 * preview can never drift from what a real booking would actually
	 * charge.
	 *
	 * Deliberately has no date/slot/emergency-surcharge input: those aren't
	 * known until the customer reaches the Date & Time step, and the
	 * emergency surcharge specifically is layered on after this by whatever
	 * already knows is_emergency (today, that's still
	 * public/js/booking-flow.js's own computeEmergencySurcharge() estimate —
	 * this endpoint doesn't change that).
	 *
	 * @param array<int,array{service_id:int,qty?:int,addons?:array}> $items Raw item selections — see create_instant_booking()'s own $input docblock for the shape.
	 * @return array{subtotal:float,duration_minutes:float,minimum_percent:float,discount_amount:float,tax_amount:float,total:float,deposit_amount:float}|WP_Error
	 */
	public static function calculate_price_preview( array $items ) {
		$scheduling_settings = Service_Crew_Settings::get_saved_settings();
		$discount_tiers      = Service_Crew_Discounts::get_saved_tiers();

		$pricing = self::build_items_pricing( $items, $scheduling_settings );
		if ( is_wp_error( $pricing ) ) {
			return $pricing;
		}

		$subtotal = $pricing['subtotal'];

		// Same "deposit bracket looked up against the pre-tax, pre-discount
		// subtotal" rule create_instant_booking() uses — see that method's
		// own comment on this line.
		$deposit_tier    = Service_Crew_Pricing::get_matching_deposit_tier( $scheduling_settings['minimum_deposit_tiers'], $subtotal );
		$minimum_percent = $deposit_tier ? (float) $deposit_tier['deposit_percent'] : 100.0;

		$payment_options = Service_Crew_Pricing::build_payment_options( $subtotal, $discount_tiers, $minimum_percent );
		$chosen          = $payment_options[0];

		$tax_rate   = (float) $scheduling_settings['tax_rate_percent'];
		$tax_mode   = $scheduling_settings['tax_mode'];
		$tax_amount = Service_Crew_Pricing::calculate_tax_amount( $chosen['total'], $tax_rate, $tax_mode );
		$total      = 'inclusive' === $tax_mode ? $chosen['total'] : round( $chosen['total'] + $tax_amount, 2 );

		return array(
			'subtotal'         => $subtotal,
			'duration_minutes' => $pricing['duration_minutes'],
			'minimum_percent'  => $minimum_percent,
			'discount_amount'  => (float) $chosen['discount_amount'],
			'tax_amount'       => $tax_amount,
			'total'            => $total,
			'deposit_amount'   => round( $total * ( $chosen['percent_paid_now'] / 100 ), 2 ),
		);
	}

	/**
	 * The plan's "available-dates" check: for each date in a range, whether
	 * there's enough pooled employee capacity left to fit this cart — real
	 * pooled capacity via Service_Crew_Capacity, not the simpler "is this
	 * exact slot already taken" check create_instant_booking() itself still
	 * uses (see that method's class docblock).
	 *
	 * **Scope note:** mirrors create_instant_booking()'s own current fixed
	 * values — duration_days and crew_needed are always 1 for an instant
	 * booking today (no multi-day/multi-crew selector exists on the public
	 * booking flow yet), so this checks single-day, single-crew capacity
	 * only. A date this reports available is genuinely bookable as-is; this
	 * is not a general preview of a future multi-day/multi-crew feature.
	 *
	 * **Known gap:** existing load only counts real hours for
	 * instant-booking-sourced rows (derived from their sc_booking_services/
	 * sc_booking_components rows). An admin-priced quote has no such
	 * breakdown — it's priced as a flat total with no component pricing (see
	 * class-service-crew-quotes.php) — so a scheduled quote contributes 0
	 * hours to load here even though it really does occupy crew. Same
	 * simplification the quote-pricing flow already makes elsewhere; not
	 * something this method can fix without quotes gaining their own hours
	 * field.
	 *
	 * @param array<int,array{service_id:int,qty?:int,addons?:array}> $items      Cart, same shape as create_instant_booking()'s $input['items'].
	 * @param string                                                   $start_date First candidate date, Y-m-d.
	 * @param int                                                      $days       How many calendar days to check, starting from $start_date (capped at AVAILABLE_DATES_MAX_DAYS).
	 * @return array<int,array{date:string,available:bool}>|WP_Error
	 */
	public static function get_available_dates( array $items, $start_date, $days ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $start_date ) ) {
			return new WP_Error( 'sc_invalid_date', __( 'Please provide a valid start date.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$days = max( 1, min( self::AVAILABLE_DATES_MAX_DAYS, (int) $days ) );

		$scheduling_settings = Service_Crew_Settings::get_saved_settings();

		$pricing = self::build_items_pricing( $items, $scheduling_settings );
		if ( is_wp_error( $pricing ) ) {
			return $pricing;
		}

		$hours_per_day = round( $pricing['duration_minutes'] / 60, 2 );
		$crew_needed   = 1; // Fixed today — see this method's own scope note.
		$travel_buffer = (int) $scheduling_settings['travel_buffer_minutes'];

		$date_range = array();
		$cursor     = $start_date;
		for ( $i = 0; $i < $days; $i++ ) {
			$date_range[] = $cursor;
			$cursor       = gmdate( 'Y-m-d', strtotime( $cursor . ' +1 day' ) );
		}

		$employees         = self::get_employee_availability_rows();
		$holidays_flipped  = array_flip( array_filter( array_column( $scheduling_settings['holidays'], 'date' ) ) );
		$load_by_date      = self::build_existing_load_map( $date_range, $scheduling_settings );

		$results = array();

		foreach ( $date_range as $date ) {
			if ( ! Service_Crew_Capacity::is_business_day( $date, $scheduling_settings['business_hours'], $holidays_flipped ) ) {
				$results[] = array(
					'date'      => $date,
					'available' => false,
				);
				continue;
			}

			$pooled_hours = Service_Crew_Capacity::calculate_pooled_hours( $employees, $date );

			$available = Service_Crew_Capacity::can_fit_job(
				array( $date ),
				array( $date => $pooled_hours ),
				$load_by_date,
				$hours_per_day,
				$crew_needed,
				$travel_buffer
			);

			$results[] = array(
				'date'      => $date,
				'available' => $available,
			);
		}

		return $results;
	}

	/**
	 * Every `employee`-type (vendors excluded per the plan's capacity rule —
	 * see Service_Crew_Capacity::calculate_pooled_hours()'s own docblock),
	 * published crew record's weekly availability + time off — the pure
	 * shape Service_Crew_Capacity's pooled-hours calculation expects.
	 *
	 * @return array<int,array{availability:array,time_off:array}>
	 */
	private static function get_employee_availability_rows() {
		$posts = get_posts(
			array(
				'post_type'      => 'sc_crew',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Service_Crew_Crew::META_TYPE,
						'value' => 'employee',
					),
				),
			)
		);

		return array_map(
			function ( $crew_id ) {
				$availability = get_post_meta( $crew_id, Service_Crew_Crew::META_AVAILABILITY, true );
				$time_off     = get_post_meta( $crew_id, Service_Crew_Crew::META_TIME_OFF, true );

				return array(
					'availability' => is_array( $availability ) ? $availability : array(),
					'time_off'     => is_array( $time_off ) ? $time_off : array(),
				);
			},
			$posts
		);
	}

	/**
	 * Existing load per date across $date_range, from every currently
	 * active (awaiting_payment/confirmed/completed) booking that could
	 * plausibly cover any date in it — including one that started up to
	 * AVAILABLE_DATES_LOOKBACK_DAYS before the range (see that constant).
	 * Each booking's own hours/day is derived from its sc_booking_services +
	 * sc_booking_components rows (see this class's calling method's "known
	 * gap" note on what that means for quotes).
	 *
	 * @param string[]             $date_range          Dates this is being computed for, Y-m-d.
	 * @param array<string,mixed>  $scheduling_settings Service_Crew_Settings::get_saved_settings()'s result.
	 * @return array<string,float> Load, keyed by date.
	 */
	private static function build_existing_load_map( array $date_range, array $scheduling_settings ) {
		global $wpdb;

		$load_by_date = array_fill_keys( $date_range, 0.0 );

		if ( empty( $date_range ) ) {
			return $load_by_date;
		}

		$lookback_start = gmdate( 'Y-m-d', strtotime( $date_range[0] . ' -' . self::AVAILABLE_DATES_LOOKBACK_DAYS . ' days' ) );
		$range_end      = $date_range[ count( $date_range ) - 1 ];

		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.preferred_date, b.duration_days, b.crew_needed,
					( SELECT COALESCE(SUM(quantity * unit_duration_minutes), 0) FROM {$wpdb->prefix}sc_booking_services WHERE booking_id = b.id ) AS services_minutes,
					( SELECT COALESCE(SUM(quantity * unit_duration_minutes), 0) FROM {$wpdb->prefix}sc_booking_components WHERE booking_id = b.id ) AS components_minutes
				FROM {$wpdb->prefix}sc_bookings b
				WHERE b.status IN ('awaiting_payment','confirmed','completed')
				AND b.preferred_date IS NOT NULL
				AND b.preferred_date BETWEEN %s AND %s",
				$lookback_start,
				$range_end
			)
		);

		$travel_buffer = (int) $scheduling_settings['travel_buffer_minutes'];
		$dates_in_range = array_flip( $date_range );

		foreach ( $bookings as $booking ) {
			$duration_days = max( 1, (int) $booking->duration_days );
			$total_minutes = (float) $booking->services_minutes + (float) $booking->components_minutes;
			$hours_per_day = round( $total_minutes / 60 / $duration_days, 2 );

			if ( $hours_per_day <= 0 ) {
				continue;
			}

			$span = Service_Crew_Capacity::get_job_date_span(
				$booking->preferred_date,
				$duration_days,
				$scheduling_settings['business_hours'],
				$scheduling_settings['holidays']
			);

			foreach ( $span as $date ) {
				if ( ! isset( $dates_in_range[ $date ] ) ) {
					continue;
				}

				$load_by_date[ $date ] += Service_Crew_Capacity::calculate_load(
					array(
						array(
							'hours_per_day' => $hours_per_day,
							'crew_needed'   => (int) $booking->crew_needed,
						),
					),
					$travel_buffer
				);
			}
		}

		return $load_by_date;
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
	 *     @type array  $items {
	 *         One or more selected services (more than one only accepted
	 *         when allow_multiple_services is on — see the class docblock).
	 *
	 *         @type int   $service_id Leaf sc_service post ID.
	 *         @type int   $qty        Customer-chosen quantity (per-unit services only).
	 *         @type array $addons     Map of component index => {checked:bool, qty?:int}.
	 *     }
	 *     @type string $date                 YYYY-MM-DD.
	 *     @type int    $arrival_window_index Index into Service_Crew_Settings's arrival_windows.
	 *     @type bool   $is_emergency         Only honored when every arrival window on $date is already taken.
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
		$items = is_array( $input['items'] ?? null ) ? $input['items'] : array();

		$scheduling_settings = Service_Crew_Settings::get_saved_settings();
		$discount_tiers      = Service_Crew_Discounts::get_saved_tiers();

		$pricing = self::build_items_pricing( $items, $scheduling_settings );
		if ( is_wp_error( $pricing ) ) {
			return $pricing;
		}

		$subtotal     = $pricing['subtotal'];
		$service_rows = $pricing['service_rows'];

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

		// Emergency is only honored when every arrival window on the
		// requested date is already taken (not restricted to today — by
		// explicit request) — if even one window on that date is still free,
		// there's nothing for it to bypass; the customer should just pick
		// that one instead. A client-sent is_emergency flag on a date that
		// isn't actually fully booked is silently ignored rather than
		// rejected.
		$taken_windows_for_date = self::get_booked_windows( $date );
		$is_day_fully_booked    = ! empty( $arrival_windows )
			&& empty( array_diff( wp_list_pluck( $arrival_windows, 'label' ), $taken_windows_for_date ) );

		$is_emergency = ! empty( $input['is_emergency'] ) && $is_day_fully_booked;

		if ( ! $is_emergency && in_array( $arrival_window, $taken_windows_for_date, true ) ) {
			return new WP_Error(
				'sc_slot_taken',
				__( 'That date and arrival window is already booked. Please choose another time, or select Emergency Booking if the whole day is full.', 'service-crew' ),
				array( 'status' => 409 )
			);
		}

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

		// Added on top of the discounted, taxed total — a flat fee or
		// percentage for jumping a same-day slot that's already booked, never
		// itself discounted or a base for tax. Zero whenever is_emergency is
		// false or the admin hasn't turned the surcharge on.
		$surcharge_amount = $is_emergency
			? Service_Crew_Pricing::calculate_emergency_surcharge( $total_with_tax, $scheduling_settings['emergency_surcharge'] )
			: 0.0;
		$total_with_tax   = round( $total_with_tax + $surcharge_amount, 2 );
		$amount_due_now   = round( $total_with_tax * ( $chosen['percent_paid_now'] / 100 ), 2 );

		$customer_name  = sanitize_text_field( $input['customer_name'] ?? '' );
		$customer_email = sanitize_email( $input['customer_email'] ?? '' );

		if ( '' === $customer_name || ! is_email( $customer_email ) ) {
			return new WP_Error( 'sc_invalid_contact', __( 'Please provide your name and a valid email address.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$customer_phone = sanitize_text_field( $input['customer_phone'] ?? '' );
		$address        = sanitize_text_field( $input['address'] ?? '' );
		$zip            = sanitize_text_field( $input['zip'] ?? '' );

		$customer_id = Service_Crew_Customers::find_or_create(
			$customer_name,
			$customer_email,
			$customer_phone,
			! empty( $input['marketing_opt_in'] )
		);
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		$geocoding = new Service_Crew_Geocoding();
		$geocode   = $geocoding->geocode( $address, $zip );

		global $wpdb;

		$first_service_id = $service_rows[0]['service_id'];

		$fields = array(
			'service_id'              => array( $first_service_id, '%d' ),
			'customer_id'             => array( $customer_id, '%d' ),
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
			'is_emergency'            => array( $is_emergency ? 1 : 0, '%d' ),
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

		foreach ( $service_rows as $row ) {
			$wpdb->insert(
				$wpdb->prefix . 'sc_booking_services',
				array(
					'booking_id'            => $booking_id,
					'service_id'            => $row['service_id'],
					'service_name'          => Service_Crew_Services::get_plain_title( $row['service'] ),
					'quantity'              => $row['service_line']['quantity'],
					'unit_price'            => (float) ( $row['pricing_fields']['price'] ?? 0 ),
					'unit_duration_minutes' => (int) ( $row['pricing_fields']['duration_minutes'] ?? 0 ),
					'line_subtotal'         => $row['service_line']['price'],
				),
				array( '%d', '%d', '%s', '%d', '%f', '%d', '%f' )
			);

			$booking_service_id = (int) $wpdb->insert_id;

			foreach ( $row['component_snapshots'] as $snapshot ) {
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
						'booking_service_id'        => $booking_service_id,
					),
					array( '%d', '%d', '%s', '%d', '%d', '%f', '%d', '%f', '%f', '%f', '%d' )
				);
			}
		}

		/**
		 * Fires once a new instant booking's rows are written — regardless of
		 * whether the Stripe checkout session below is created successfully,
		 * since the booking itself already exists either way.
		 *
		 * @param int $booking_id Booking id.
		 */
		do_action( 'sc_booking_created', $booking_id );

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

		// The current page is always preferred — it's the only URL guaranteed
		// to have the [service_crew_booking] shortcode's own Step 4 on it, so
		// stepping back from Stripe lands the customer on the "Thank you"
		// state that shortcode already renders. The admin-configured success/
		// cancel pages (Payments settings screen) are only a fallback for the
		// rare case return_url is missing, not a general override.
		$success_base = ! empty( $input['return_url'] ) ? esc_url_raw( $input['return_url'] ) : Service_Crew_Payments::get_success_page_url();
		$cancel_base  = ! empty( $input['return_url'] ) ? esc_url_raw( $input['return_url'] ) : Service_Crew_Payments::get_cancel_page_url();

		$success_url = add_query_arg(
			array(
				'sc_booking' => $booking_id,
				'sc_status'  => 'success',
				'sc_email'   => rawurlencode( $customer_email ),
			),
			$success_base ? $success_base : home_url( '/' )
		);

		$cancel_url = add_query_arg(
			array(
				'sc_booking' => $booking_id,
				'sc_status'  => 'cancel',
			),
			$cancel_base ? $cancel_base : home_url( '/' )
		);

		// Still exactly one Stripe line item regardless of item count — the
		// deposit is one combined charge, not itemized per service.
		$first_title = Service_Crew_Services::get_plain_title( $service_rows[0]['service'] );
		$extra_count = count( $service_rows ) - 1;

		$line_item_name = $extra_count > 0
			? sprintf(
				/* translators: 1: first service name, 2: number of additional services. */
				__( 'Booking deposit — %1$s + %2$d more', 'service-crew' ),
				$first_title,
				$extra_count
			)
			/* translators: %s: service name. */
			: sprintf( __( 'Booking deposit — %s', 'service-crew' ), $first_title );

		$line_items = array(
			array(
				'name'     => $line_item_name,
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
	 * Confirms a booking once a payment succeeds, and always adds the new
	 * payment's amount onto amount_paid. Hooked to `sc_payment_succeeded`,
	 * which the Stripe gateway fires idempotently — see
	 * class-service-crew-gateway-stripe.php's process_event() — for *every*
	 * payment kind that flows through the gateway/pay-page, not just a
	 * deposit: a balance payment (Service_Crew_Bookings::create_balance_payment_link())
	 * reaches this exact same handler.
	 *
	 * amount_paid is incremented, never overwritten, precisely because of
	 * that: overwriting would erase a deposit already on the books the
	 * moment a later balance payment came in. Status only ever moves
	 * *forward* to `confirmed` from a not-yet-confirmed state
	 * (`awaiting_payment` for an instant booking, `quoted` for a quote, or
	 * any other pre-confirmation status) — a balance payment arriving after
	 * the booking has already progressed to `assigned`/`completed` must not
	 * regress it back to `confirmed`.
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

		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT status, amount_paid FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', $booking_id ) );
		if ( ! $booking ) {
			return;
		}

		$fields  = array( 'amount_paid' => round( (float) $booking->amount_paid + (float) $payment->amount, 2 ) );
		$formats = array( '%f' );

		$post_confirmation_statuses = array( self::STATUS_ASSIGNED, self::STATUS_COMPLETED, self::STATUS_CANCELLED );
		if ( ! in_array( $booking->status, $post_confirmation_statuses, true ) ) {
			$fields['status'] = self::STATUS_CONFIRMED;
			$formats[]        = '%s';
		}

		$wpdb->update(
			$wpdb->prefix . 'sc_bookings',
			$fields,
			array( 'id' => $booking_id ),
			$formats,
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
		$booking_id  = absint( $booking_id );
		$old_status  = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', $booking_id ) );

		$updated = $wpdb->update(
			$wpdb->prefix . 'sc_bookings',
			array( 'status' => $status ),
			array( 'id' => $booking_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'sc_status_update_failed', __( 'Could not update the booking status.', 'service-crew' ) );
		}

		// Cancelling frees the crew the same way it already frees the date+
		// window slot (get_booked_windows()/build_existing_load_map() already
		// only count awaiting_payment/confirmed/completed, so a cancelled
		// booking stops holding capacity automatically) — the plan's "job
		// leaves the crew list" for the "Customer cancels" case.
		if ( self::STATUS_CANCELLED === $status ) {
			Service_Crew_Assignments::release_all( $booking_id, __( 'Booking cancelled', 'service-crew' ) );
		}

		/**
		 * Fires whenever an admin manually changes a booking's status from
		 * the bookings list — e.g. so class-service-crew-emails.php can send
		 * the plan's "Cancelled" email on the transition into `cancelled`.
		 *
		 * @param int    $booking_id Booking id.
		 * @param string $status     New status.
		 * @param string $old_status Previous status.
		 */
		do_action( 'sc_booking_status_changed', $booking_id, $status, (string) $old_status );

		return true;
	}

	/**
	 * The employee job-status link's own status transition (V1's email-based
	 * stand-in for the deferred crew PWA — see ServiceCrew-Plan-v2.md's "V1
	 * launch scope"). Shares the same `sc_booking_status_changed` action
	 * update_status() fires, so class-service-crew-emails.php's existing
	 * status-change dispatcher sends the right customer email regardless of
	 * whether an admin or the job-status page caused the change.
	 *
	 * Deliberately lenient about $allowed_from rather than enforcing one
	 * strict prior status per transition: an employee who forgets to tap
	 * "On the way" before tapping "Complete" shouldn't get stuck unable to
	 * close out the job. See class-service-crew-job-status-page.php's calls
	 * to this for each transition's actual allowed-from list.
	 *
	 * @param int      $booking_id    Booking id.
	 * @param string   $new_status    One of STATUS_ON_THE_WAY/STATUS_IN_PROGRESS/STATUS_COMPLETED.
	 * @param string[] $allowed_from  Statuses the booking may currently be in for this transition to apply.
	 * @return true|WP_Error
	 */
	public static function update_job_status( $booking_id, $new_status, array $allowed_from ) {
		$booking_id = absint( $booking_id );

		global $wpdb;
		$old_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', $booking_id ) );

		if ( ! $old_status ) {
			return new WP_Error( 'sc_booking_not_found', __( 'Booking not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $old_status, $allowed_from, true ) ) {
			return new WP_Error( 'sc_invalid_job_status_transition', __( 'This job cannot be updated from its current status.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'sc_bookings',
			array( 'status' => $new_status ),
			array( 'id' => $booking_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'sc_status_update_failed', __( 'Could not update the job status.', 'service-crew' ) );
		}

		do_action( 'sc_booking_status_changed', $booking_id, $new_status, (string) $old_status );

		return true;
	}

	/**
	 * Completes a job from the employee job-status link: transitions status
	 * (via update_job_status()) and, if a note and/or photos were submitted,
	 * logs them via Service_Crew_Notes so they show up on the future
	 * dispatch-board timeline — the plan's "lead adds note and photos" at
	 * Complete.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param string               $note       Optional completion note.
	 * @param array<string,mixed>  $files      Raw $_FILES['photos'] shape, or empty.
	 * @return true|WP_Error
	 */
	public static function complete_job( $booking_id, $note, array $files ) {
		$booking_id = absint( $booking_id );

		$photo_ids = Service_Crew_Uploads::handle_photo_uploads( $files['photos'] ?? array(), self::JOB_COMPLETION_MAX_PHOTOS, self::JOB_COMPLETION_MAX_PHOTO_BYTES );
		if ( is_wp_error( $photo_ids ) ) {
			return $photo_ids;
		}

		$result = self::update_job_status(
			$booking_id,
			self::STATUS_COMPLETED,
			array( self::STATUS_ASSIGNED, self::STATUS_ON_THE_WAY, self::STATUS_IN_PROGRESS )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Sanitized here, not left to the caller: unlike every other
		// add_note() call in this codebase (always admin-authored, already
		// sanitized at that REST boundary), this note comes straight from a
		// public, token-gated endpoint.
		$note = sanitize_textarea_field( trim( (string) $note ) );
		if ( '' !== $note ) {
			Service_Crew_Notes::add_note( $booking_id, Service_Crew_Notes::TYPE_NOTE, $note );
		}

		foreach ( $photo_ids as $photo_id ) {
			Service_Crew_Notes::add_note( $booking_id, Service_Crew_Notes::TYPE_NOTE, '', $photo_id );
		}

		return true;
	}

	/**
	 * Admin reschedule action: changes a booking's date/arrival window.
	 * Per the plan, conflicts are warnings, never blocks — this always
	 * applies the change, and reports back whether the new slot already
	 * looks taken and/or an assigned employee is unavailable on the new
	 * date, for the admin UI to surface as a warning rather than to prevent.
	 *
	 * @param int    $booking_id     Booking id.
	 * @param string $date           New date, Y-m-d.
	 * @param string $arrival_window New arrival-window label.
	 * @return array{date:string,arrival_window:string,slot_taken_warning:bool,assignee_unavailable:bool}|WP_Error
	 */
	public static function reschedule( $booking_id, $date, $arrival_window ) {
		$booking_id = absint( $booking_id );

		$date = sanitize_text_field( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'sc_invalid_date', __( 'Please choose a valid date.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$arrival_window = sanitize_text_field( (string) $arrival_window );
		if ( '' === $arrival_window ) {
			return new WP_Error( 'sc_invalid_window', __( 'Please choose an arrival window.', 'service-crew' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', $booking_id ) );
		if ( ! $booking ) {
			return new WP_Error( 'sc_booking_not_found', __( 'Booking not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		if ( in_array( $booking->status, array( self::STATUS_CANCELLED, self::STATUS_COMPLETED ), true ) ) {
			return new WP_Error( 'sc_booking_not_reschedulable', __( 'This booking can no longer be rescheduled.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$old_date     = $booking->preferred_date;
		$old_window   = $booking->arrival_window;
		$is_unchanged = $old_date === $date && $old_window === $arrival_window;

		// Same simple "is this slot already taken" rule create_instant_booking()
		// itself enforces (see that method's "slot-level check only" note) —
		// skipped entirely when the date/window aren't actually changing, since
		// this booking's own existing row would otherwise always match itself.
		$slot_taken_warning = ! $is_unchanged && in_array( $arrival_window, self::get_booked_windows( $date ), true );

		$assignee_unavailable = false;
		foreach ( Service_Crew_Assignments::get_active_assignments( $booking_id ) as $crew_id => $assignment ) {
			$availability = get_post_meta( $crew_id, Service_Crew_Crew::META_AVAILABILITY, true );
			$time_off     = get_post_meta( $crew_id, Service_Crew_Crew::META_TIME_OFF, true );

			if ( ! Service_Crew_Availability::is_available_on(
				is_array( $availability ) ? $availability : array(),
				is_array( $time_off ) ? $time_off : array(),
				$date
			) ) {
				$assignee_unavailable = true;
				break;
			}
		}

		$wpdb->update(
			$wpdb->prefix . 'sc_bookings',
			array(
				'preferred_date'            => $date,
				'arrival_window'            => $arrival_window,
				'flag_assignee_unavailable' => $assignee_unavailable ? 1 : 0,
			),
			array( 'id' => $booking_id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		if ( ! $is_unchanged ) {
			/**
			 * Fires when an admin reschedules a booking's date/window — e.g.
			 * so class-service-crew-emails.php can send the plan's "Booking
			 * updated" email to the customer.
			 *
			 * @param int    $booking_id Booking id.
			 * @param string $old_date   Previous date.
			 * @param string $old_window Previous arrival window.
			 * @param string $new_date   New date.
			 * @param string $new_window New arrival window.
			 */
			do_action( 'sc_booking_rescheduled', $booking_id, $old_date, $old_window, $date, $arrival_window );
		}

		return array(
			'date'                 => $date,
			'arrival_window'       => $arrival_window,
			'slot_taken_warning'   => $slot_taken_warning,
			'assignee_unavailable' => $assignee_unavailable,
		);
	}

	/**
	 * WP-Cron callback (hourly, see CRON_HOOK): cancels any instant booking
	 * still `awaiting_payment` past AWAITING_PAYMENT_EXPIRY_HOURS, so a
	 * customer who abandoned Stripe Checkout stops holding a date+window
	 * slot forever. Cancelling (rather than a separate "expired" status) is
	 * enough to drop it out of get_booked_windows()'s
	 * awaiting_payment/confirmed/completed filter — no other query needed
	 * changing for this to "never count toward capacity" per the plan.
	 *
	 * Also marks any still-pending sc_payments row for an expired booking as
	 * failed, so a stale, now-unusable Stripe Checkout session doesn't sit
	 * forever looking like it might still complete.
	 *
	 * @return void
	 */
	public function expire_awaiting_payment() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::AWAITING_PAYMENT_EXPIRY_HOURS * HOUR_IN_SECONDS ) );

		$expiring_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}sc_bookings WHERE status = %s AND created_at < %s",
				self::STATUS_AWAITING_PAYMENT,
				$cutoff
			)
		);

		if ( empty( $expiring_ids ) ) {
			return;
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $expiring_ids ), '%d' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}sc_bookings SET status = %s WHERE id IN ({$id_placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( self::STATUS_CANCELLED ), $expiring_ids )
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}sc_payments SET status = %s WHERE status = %s AND booking_id IN ({$id_placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( Service_Crew_Payments::STATUS_FAILED, Service_Crew_Payments::STATUS_PENDING ), $expiring_ids )
			)
		);
	}

	/**
	 * Admin refund action: refunds part or all of a booking's most recent
	 * refundable payment through its gateway, then reflects the reduced
	 * amount_paid on the booking row. Already generic across payment kinds —
	 * a booking with both a deposit and a later balance payment (see
	 * create_balance_payment_link()/mark_balance_collected()) refunds
	 * whichever one is most recent first; refunding an older payment ahead
	 * of a newer one isn't supported, same as it never has been for a single
	 * payment either.
	 *
	 * @param int    $booking_id Booking id.
	 * @param float  $amount     Amount to refund; 0 (or omitted) means "refund whatever remains".
	 * @param string $reason     Admin-supplied reason, required — logged on the sc_refunds row.
	 * @return array{refund_id:int,status:string,amount:float,amount_paid:float}|WP_Error
	 */
	public static function refund_booking( $booking_id, $amount, $reason ) {
		$booking_id = absint( $booking_id );
		$reason     = trim( (string) $reason );

		if ( '' === $reason ) {
			return new WP_Error( 'sc_refund_reason_required', __( 'Please give a reason for this refund.', 'service-crew' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', $booking_id ) );
		if ( ! $booking ) {
			return new WP_Error( 'sc_booking_not_found', __( 'Booking not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$payment = Service_Crew_Payments::get_latest_refundable_payment( $booking_id );
		if ( ! $payment ) {
			return new WP_Error( 'sc_no_refundable_payment', __( 'This booking has no paid payment to refund.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$remaining = round( (float) $payment->amount - Service_Crew_Payments::get_refunded_amount( $payment->id ), 2 );
		if ( $remaining <= 0 ) {
			return new WP_Error( 'sc_already_refunded', __( 'This payment has already been fully refunded.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$amount = (float) $amount > 0 ? round( (float) $amount, 2 ) : $remaining;
		if ( $amount > $remaining ) {
			return new WP_Error( 'sc_refund_too_large', __( 'That amount is more than what remains to refund.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$result = Service_Crew_Payments::process_refund( $payment->id, $amount, $reason );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_amount_paid = max( 0.0, round( (float) $booking->amount_paid - $amount, 2 ) );
		$wpdb->update(
			$wpdb->prefix . 'sc_bookings',
			array( 'amount_paid' => $new_amount_paid ),
			array( 'id' => $booking_id ),
			array( '%f' ),
			array( '%d' )
		);

		return array(
			'refund_id'   => $result['refund_id'],
			'status'      => $result['status'],
			'amount'      => $amount,
			'amount_paid' => $new_amount_paid,
		);
	}

	/**
	 * Whatever's left to collect on a booking — customer_total minus
	 * amount_paid, never negative. The one shared calculation both balance
	 * actions below start from.
	 *
	 * @param object $booking Row with at least customer_total/amount_paid (a full `SELECT *` sc_bookings row works).
	 * @return float
	 */
	private static function get_remaining_balance( $booking ) {
		return max( 0.0, round( (float) $booking->customer_total - (float) $booking->amount_paid, 2 ) );
	}

	/**
	 * Admin balance-collection action: records the remaining balance as
	 * collected outside the system (cash, check, etc) — the plugin only
	 * tracks it, same "we never move the money ourselves" spirit as the
	 * plan's vendor-payment tracking. Logged as a payment row immediately
	 * `succeeded` (no gateway/Stripe involved) so it shows up in the
	 * booking's payment history exactly like a real one.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $note       Optional note, e.g. "paid by check #123" — logged via Service_Crew_Notes.
	 * @return array{amount:float,amount_paid:float}|WP_Error
	 */
	public static function mark_balance_collected( $booking_id, $note = '' ) {
		$booking_id = absint( $booking_id );

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', $booking_id ) );
		if ( ! $booking ) {
			return new WP_Error( 'sc_booking_not_found', __( 'Booking not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$remaining = self::get_remaining_balance( $booking );
		if ( $remaining <= 0 ) {
			return new WP_Error( 'sc_no_balance_due', __( 'There is no remaining balance on this booking.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$payment_id = Service_Crew_Payments::create_payment(
			array(
				'booking_id' => $booking_id,
				'kind'       => Service_Crew_Payments::KIND_BALANCE,
				'amount'     => $remaining,
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		Service_Crew_Payments::update_payment(
			$payment_id,
			array(
				'status'  => Service_Crew_Payments::STATUS_SUCCEEDED,
				'paid_at' => current_time( 'mysql', true ),
			)
		);

		$new_amount_paid = round( (float) $booking->amount_paid + $remaining, 2 );
		$wpdb->update(
			$wpdb->prefix . 'sc_bookings',
			array( 'amount_paid' => $new_amount_paid ),
			array( 'id' => $booking_id ),
			array( '%f' ),
			array( '%d' )
		);

		$note = trim( (string) $note );
		if ( '' !== $note ) {
			Service_Crew_Notes::add_note( $booking_id, Service_Crew_Notes::TYPE_NOTE, sprintf(
				/* translators: 1: amount, 2: admin's note. */
				__( 'Balance of $%1$s marked collected outside the system: %2$s', 'service-crew' ),
				number_format( $remaining, 2 ),
				$note
			), null, get_current_user_id() );
		}

		return array(
			'amount'      => $remaining,
			'amount_paid' => $new_amount_paid,
		);
	}

	/**
	 * Admin balance-collection action: generates a single-use pay-page link
	 * for the remaining balance, same token mechanism as
	 * Service_Crew_Quotes::create_deposit_link() — the admin copies and
	 * sends it by hand, there's no automatic "balance due" email yet.
	 *
	 * @param int $booking_id Booking id.
	 * @return array{url:string,expires_at:string,amount:float}|WP_Error
	 */
	public static function create_balance_payment_link( $booking_id ) {
		$booking_id = absint( $booking_id );

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', $booking_id ) );
		if ( ! $booking ) {
			return new WP_Error( 'sc_booking_not_found', __( 'Booking not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$remaining = self::get_remaining_balance( $booking );
		if ( $remaining <= 0 ) {
			return new WP_Error( 'sc_no_balance_due', __( 'There is no remaining balance on this booking.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$payment_id = Service_Crew_Payments::create_payment(
			array(
				'booking_id' => $booking_id,
				'kind'       => Service_Crew_Payments::KIND_BALANCE,
				'amount'     => $remaining,
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		$token = Service_Crew_Payments::generate_pay_token( $payment_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$payment = Service_Crew_Payments::get_payment( $payment_id );

		return array(
			'url'        => Service_Crew_Pay_Page::build_url( $token ),
			'expires_at' => $payment ? $payment->token_expires_at : '',
			'amount'     => $remaining,
		);
	}
}
