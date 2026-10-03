<?php
/**
 * Email templates, on/off toggles and the `wp_mail()` choke point for every
 * email type in the plan's Emails table (see ServiceCrew-Plan-v2.md's
 * "Emails" section under Confirmed decisions). Deliberately plain-text
 * `{placeholder}` templates via `strtr()` — no template engine, matching
 * this codebase's no-build-step rule.
 *
 * Only wired to the trigger points that actually exist in the codebase today
 * — this class defines every row from the plan's table so an admin can see
 * and edit all of them up front, but listens for a matching WordPress action
 * only where one is fired:
 *  - New booking (admin) / New quote (admin) / Quote received (customer) —
 *    `sc_booking_created` (class-service-crew-bookings.php) and
 *    `sc_quote_created` (class-service-crew-quotes.php), fired right after
 *    each row is inserted.
 *  - Booking confirmation (customer) — the existing `sc_payment_succeeded`
 *    action (class-service-crew-gateway-stripe.php), which already covers
 *    both an instant booking's deposit and a quote's deposit-link payment
 *    identically since both flow through the same webhook handler.
 *  - Cancelled (customer) — a new `sc_booking_status_changed` action fired
 *    from Service_Crew_Bookings::update_status(), filtered to the
 *    transition into `cancelled`.
 *  - Assigned (customer) / job details (employee) — a new `sc_booking_assigned`
 *    action (class-service-crew-assignments.php's assign_crew()). Per
 *    ServiceCrew-Plan-v2.md's "V1 launch scope", this fires immediately once
 *    the admin saves a roster — there is no PWA accept step to wait for in
 *    V1, unlike the plan's original "when everyone has accepted" wording.
 *    `assignment_details_employee` isn't one of the plan's original Emails
 *    table rows at all (that table assumed a PWA, not email, for crew) — it's
 *    V1's stand-in: every assigned employee gets the full job detail by
 *    email instead of a push notification + in-app screen.
 * Reassigned/on the way/started/completed (Phase 1d PWA, now deferred — see
 * "V1 launch scope"), booking-updated/reschedule and refund-issued (Phase 1c
 * dispatch board), and the timer-driven admin reminders (Phase 1c cron) have
 * no trigger point to hook yet — their template rows exist here (so the
 * settings screen is a complete, plan-accurate list) but nothing fires them
 * until those features exist.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Emails {

	/**
	 * Option name storing every template's enabled/subject/body.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'sc_email_settings';

	/**
	 * REST namespace shared with the rest of the custom admin app.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		add_action( 'sc_booking_created', array( $this, 'on_booking_created' ) );
		add_action( 'sc_quote_created', array( $this, 'on_quote_created' ) );
		// Priority 20: after Service_Crew_Bookings::confirm_booking() (10) has
		// already flipped the row to `confirmed` and set amount_paid, so the
		// placeholders below reflect the post-payment row, not the stale
		// pre-payment one.
		add_action( 'sc_payment_succeeded', array( $this, 'on_payment_succeeded' ), 20, 2 );
		add_action( 'sc_booking_status_changed', array( $this, 'on_booking_status_changed' ), 10, 3 );
		add_action( 'sc_booking_assigned', array( $this, 'on_booking_assigned' ), 10, 4 );
		add_action( 'sc_booking_rescheduled', array( $this, 'on_booking_rescheduled' ), 10, 5 );
	}

	/**
	 * Registers GET/PUT /email-settings.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/email-settings',
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
	}

	/**
	 * Permission check shared by every route on this class — same access
	 * level as the rest of the custom admin app.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Every email type from the plan's table, in the same order, each with
	 * whether it's actually wired to a live trigger yet (`wired`, read-only
	 * information for the settings screen — toggling `enabled` on an unwired
	 * one does nothing harmful, it just never fires).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_defaults() {
		$site_name = '{site_name}';

		return array(
			'new_booking_admin'             => array(
				'label'   => __( 'New booking (to admin)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( '[%s] New booking from {customer_name}', 'service-crew' ), $site_name ),
				'body'    => __( "A new booking just came in.\n\nService: {service_name}\nCustomer: {customer_name} ({customer_email})\nDate: {date} {arrival_window}\nBooking total: {amount}\n\nBooking #{booking_id}", 'service-crew' ),
			),
			'new_quote_admin'               => array(
				'label'   => __( 'New quote request (to admin)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( '[%s] New quote request: {quote_title}', 'service-crew' ), $site_name ),
				'body'    => __( "A new quote request just came in.\n\n{quote_title}\nCustomer: {customer_name} ({customer_email})\n\nReview it from the ServiceCrew Bookings screen — Quote #{booking_id}.", 'service-crew' ),
			),
			'quote_received_customer'       => array(
				'label'   => __( 'Quote received (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( 'We received your quote request — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nThanks for your request — we'll review the details and follow up with a price soon.\n\n{quote_title}\n\n— {site_name}", 'service-crew' ),
			),
			'booking_confirmation_customer' => array(
				'label'   => __( 'Booking confirmation (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( 'Your booking is confirmed — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nYour deposit of {deposit_amount} has been received and your booking is confirmed.\n\nDate: {date} {arrival_window}\n\n— {site_name}", 'service-crew' ),
			),
			'booking_cancelled_customer'    => array(
				'label'   => __( 'Cancelled (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( 'Your booking has been cancelled — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nYour booking scheduled for {date} {arrival_window} has been cancelled.\n\n— {site_name}", 'service-crew' ),
			),
			'assigned_customer'             => array(
				'label'   => __( 'Assigned (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( "You're all set — %s", 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nYour crew is confirmed for {date} {arrival_window}.\n\nWho's coming: {crew_names}\n\n— {site_name}", 'service-crew' ),
			),
			'assignment_details_employee'   => array(
				'label'   => __( 'Job details (to assigned employee)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( 'New job assigned — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {employee_name},\n\nYou've been assigned to a job{lead_note}.\n\nCustomer: {customer_name}\nPhone: {customer_phone}\nAddress: {address}\nMap: {map_url}\n\nDate: {date} {arrival_window}\n\nJob details:\n{job_details}{status_link_section}\n\n— {site_name}", 'service-crew' ),
			),
			'reassigned_customer'           => array(
				'label'   => __( 'Reassigned (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => false,
				'subject' => sprintf( __( 'An update on your crew — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nYour crew for {date} {arrival_window} has changed.\n\n— {site_name}", 'service-crew' ),
			),
			'on_the_way_customer'           => array(
				'label'   => __( 'On the way (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( "We're on the way — %s", 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nYour crew is on the way for your {date} appointment.\n\n— {site_name}", 'service-crew' ),
			),
			'started_customer'              => array(
				'label'   => __( 'Started (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( 'Your service has started — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nYour crew has arrived and started work.\n\n— {site_name}", 'service-crew' ),
			),
			'completed_customer'            => array(
				'label'   => __( 'Completed (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( 'Your service is complete — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nYour service is complete. Thanks for booking with us!\n\n— {site_name}", 'service-crew' ),
			),
			'booking_updated_customer'      => array(
				'label'   => __( 'Booking updated (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => true,
				'subject' => sprintf( __( 'Your booking was updated — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nYour booking is now scheduled for {date} {arrival_window}.\n\n— {site_name}", 'service-crew' ),
			),
			'payment_received_customer'     => array(
				'label'   => __( 'Payment received (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => false,
				'subject' => sprintf( __( 'Payment received — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nWe've received a payment of {amount} on your booking.\n\n— {site_name}", 'service-crew' ),
			),
			'refund_issued_customer'        => array(
				'label'   => __( 'Refund issued (to customer)', 'service-crew' ),
				'enabled' => true,
				'wired'   => false,
				'subject' => sprintf( __( 'Refund issued — %s', 'service-crew' ), $site_name ),
				'body'    => __( "Hi {customer_name},\n\nA refund of {amount} has been issued to your original payment method.\n\n— {site_name}", 'service-crew' ),
			),
			'timers_admin'                  => array(
				'label'   => __( 'No response / quote expiring / accepted-not-scheduled (to admin)', 'service-crew' ),
				'enabled' => true,
				'wired'   => false,
				'subject' => sprintf( __( '[%s] Needs attention: {booking_id}', 'service-crew' ), $site_name ),
				'body'    => __( "Booking #{booking_id} needs attention: {reason}.\n\n— {site_name}", 'service-crew' ),
			),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_saved_settings() {
		$saved    = get_option( self::OPTION_NAME, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::get_defaults();

		foreach ( $defaults as $key => $default ) {
			$defaults[ $key ] = array_merge(
				$default,
				array(
					'enabled' => isset( $saved[ $key ]['enabled'] ) ? (bool) $saved[ $key ]['enabled'] : $default['enabled'],
					'subject' => isset( $saved[ $key ]['subject'] ) ? (string) $saved[ $key ]['subject'] : $default['subject'],
					'body'    => isset( $saved[ $key ]['body'] ) ? (string) $saved[ $key ]['body'] : $default['body'],
				)
			);
		}

		return $defaults;
	}

	/**
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( self::get_saved_settings() );
	}

	/**
	 * PUT handler: only `enabled`/`subject`/`body` per key are ever stored —
	 * `label`/`wired` always come from get_defaults(), so a client can't
	 * relabel a template or claim one is wired when it isn't.
	 *
	 * @param WP_REST_Request $request Request with a map of key => {enabled,subject,body} in the JSON body.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$params    = $request->get_json_params();
		$params    = is_array( $params ) ? $params : array();
		$defaults  = self::get_defaults();
		$sanitized = array();

		foreach ( $defaults as $key => $default ) {
			$posted = is_array( $params[ $key ] ?? null ) ? $params[ $key ] : array();

			$sanitized[ $key ] = array(
				'enabled' => ! empty( $posted['enabled'] ),
				'subject' => isset( $posted['subject'] ) ? sanitize_text_field( $posted['subject'] ) : $default['subject'],
				'body'    => isset( $posted['body'] ) ? sanitize_textarea_field( $posted['body'] ) : $default['body'],
			);
		}

		update_option( self::OPTION_NAME, $sanitized, false );

		return rest_ensure_response( self::get_saved_settings() );
	}

	/**
	 * Public entry point for class-service-crew-cron.php's timer sweep — the
	 * plan's "No response, quote expiring, accepted-not-scheduled (to admin)"
	 * row shares one `timers_admin` template for every timer type,
	 * distinguished only by $reason's text (today: quote-expiry reminders
	 * only — see that class's own docblock for which timers aren't wired).
	 *
	 * @param int    $booking_id Booking id the reminder is about.
	 * @param string $reason     Human-readable reason, substituted into {reason}.
	 * @return void
	 */
	public static function send_admin_timer_reminder( $booking_id, $reason ) {
		self::send(
			'timers_admin',
			get_option( 'admin_email' ),
			array(
				'booking_id' => (string) absint( $booking_id ),
				'reason'     => $reason,
			)
		);
	}

	/**
	 * Renders a template's subject+body against a placeholder map and sends
	 * it via wp_mail(), unless the template is turned off. `{site_name}` is
	 * always available on top of whatever the caller passes.
	 *
	 * @param string               $key          One of get_defaults()'s keys.
	 * @param string               $to           Recipient email address.
	 * @param array<string,string> $placeholders Map of `{token}` (without braces) => replacement.
	 * @return void
	 */
	private static function send( $key, $to, array $placeholders ) {
		if ( ! is_email( $to ) ) {
			return;
		}

		$settings = self::get_saved_settings();
		$template = $settings[ $key ] ?? null;

		if ( ! $template || ! $template['enabled'] ) {
			return;
		}

		$placeholders['site_name'] = get_bloginfo( 'name' );

		$search  = array();
		$replace = array();
		foreach ( $placeholders as $token => $value ) {
			$search[]  = '{' . $token . '}';
			$replace[] = (string) $value;
		}

		wp_mail( $to, strtr( $template['subject'], array_combine( $search, $replace ) ), strtr( $template['body'], array_combine( $search, $replace ) ) );
	}

	/**
	 * @param int $booking_id Booking id.
	 * @return object|null
	 */
	private static function get_booking_row( $booking_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', absint( $booking_id ) ) );
	}

	/**
	 * @param object $booking Row from get_booking_row().
	 * @return array<string,string>
	 */
	private static function build_placeholders( $booking ) {
		return array(
			'booking_id'     => (string) $booking->id,
			'customer_name'  => (string) $booking->customer_name,
			'customer_email' => (string) $booking->customer_email,
			'customer_phone' => (string) $booking->customer_phone,
			'address'        => trim( $booking->address . ( $booking->zip ? ', ' . $booking->zip : '' ) ),
			'service_name'   => $booking->service_id ? get_the_title( (int) $booking->service_id ) : '',
			'quote_title'    => (string) $booking->quote_title,
			'date'           => $booking->preferred_date ? mysql2date( get_option( 'date_format' ), $booking->preferred_date ) : '',
			'arrival_window' => (string) $booking->arrival_window,
			'amount'         => self::format_money( $booking->customer_total ),
			'deposit_amount' => self::format_money( $booking->deposit_amount ),
		);
	}

	/**
	 * A plain-text, line-per-item breakdown of a booking's services and
	 * add-ons for the employee job-details email. A quote-sourced booking has
	 * no such rows (it's priced as a flat total, no component pricing — see
	 * class-service-crew-quotes.php), so this falls back to pointing at
	 * {quote_title}/the admin screen instead of an empty list.
	 *
	 * @param int $booking_id Booking id.
	 * @return string
	 */
	private static function build_job_details_text( $booking_id ) {
		global $wpdb;

		$services = $wpdb->get_results(
			$wpdb->prepare( 'SELECT service_name, quantity FROM ' . $wpdb->prefix . 'sc_booking_services WHERE booking_id = %d', absint( $booking_id ) )
		);
		$components = $wpdb->get_results(
			$wpdb->prepare( 'SELECT component_name, quantity FROM ' . $wpdb->prefix . 'sc_booking_components WHERE booking_id = %d', absint( $booking_id ) )
		);

		$lines = array();
		foreach ( $services as $service ) {
			$lines[] = '- ' . $service->service_name . ( $service->quantity > 1 ? ' x' . $service->quantity : '' );
		}
		foreach ( $components as $component ) {
			$lines[] = '  + ' . $component->component_name . ( $component->quantity > 1 ? ' x' . $component->quantity : '' );
		}

		return $lines ? implode( "\n", $lines ) : __( '(No itemized breakdown for this job — see the admin Bookings screen.)', 'service-crew' );
	}

	/**
	 * A plain Google Maps search link from the booking's geocoded lat/lng,
	 * falling back to a text-address search when geocoding failed/was
	 * approximate — never blocks sending the email either way, same spirit
	 * as geocoding failures never blocking a booking.
	 *
	 * @param object $booking Row from get_booking_row().
	 * @return string
	 */
	private static function build_map_url( $booking ) {
		if ( $booking->lat && $booking->lng ) {
			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $booking->lat . ',' . $booking->lng );
		}

		$query = trim( $booking->address . ' ' . $booking->zip );

		return $query ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query ) : '';
	}

	/**
	 * @param float|string $amount Amount in the site's major currency unit.
	 * @return string
	 */
	private static function format_money( $amount ) {
		return '$' . number_format( (float) $amount, 2 );
	}

	/**
	 * Fired from Service_Crew_Bookings::create_instant_booking() right after
	 * the booking row is inserted.
	 *
	 * @param int $booking_id Booking id.
	 * @return void
	 */
	public function on_booking_created( $booking_id ) {
		$booking = self::get_booking_row( $booking_id );
		if ( ! $booking ) {
			return;
		}

		self::send( 'new_booking_admin', get_option( 'admin_email' ), self::build_placeholders( $booking ) );
	}

	/**
	 * Fired from Service_Crew_Quotes::create_quote_request() right after the
	 * quote's booking row is inserted — sends both the admin notice and the
	 * customer's own "quote received" acknowledgement from the one event.
	 *
	 * @param int $booking_id Booking id.
	 * @return void
	 */
	public function on_quote_created( $booking_id ) {
		$booking = self::get_booking_row( $booking_id );
		if ( ! $booking ) {
			return;
		}

		$placeholders = self::build_placeholders( $booking );

		self::send( 'new_quote_admin', get_option( 'admin_email' ), $placeholders );
		self::send( 'quote_received_customer', $booking->customer_email, $placeholders );
	}

	/**
	 * Fired on `sc_payment_succeeded`, after Service_Crew_Bookings has
	 * already confirmed the booking — covers both an instant booking's
	 * deposit and a quote's deposit-link payment identically, since both
	 * reach this same action.
	 *
	 * @param int $payment_id Payment id (unused — the booking row already has amount_paid).
	 * @param int $booking_id Booking id.
	 * @return void
	 */
	public function on_payment_succeeded( $payment_id, $booking_id ) {
		$booking = self::get_booking_row( $booking_id );
		if ( ! $booking ) {
			return;
		}

		self::send( 'booking_confirmation_customer', $booking->customer_email, self::build_placeholders( $booking ) );
	}

	/**
	 * Fired from both Service_Crew_Bookings::update_status() (admin manual
	 * changes) and update_job_status() (the employee job-status link) on
	 * every status change. Maps a new_status straight to its template key
	 * per the plan's table — `on_the_way`/`in_progress`/`completed` are the
	 * employee job-status link's own transitions (V1's email-based stand-in
	 * for the deferred crew PWA), now wired alongside the existing
	 * `cancelled` handling.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $new_status New status.
	 * @param string $old_status Previous status.
	 * @return void
	 */
	public function on_booking_status_changed( $booking_id, $new_status, $old_status ) {
		if ( $old_status === $new_status ) {
			return;
		}

		$template_keys = array(
			'cancelled'  => 'booking_cancelled_customer',
			'on_the_way' => 'on_the_way_customer',
			'in_progress' => 'started_customer',
			'completed'  => 'completed_customer',
		);

		if ( ! isset( $template_keys[ $new_status ] ) ) {
			return;
		}

		$booking = self::get_booking_row( $booking_id );
		if ( ! $booking ) {
			return;
		}

		self::send( $template_keys[ $new_status ], $booking->customer_email, self::build_placeholders( $booking ) );
	}

	/**
	 * Fired from Service_Crew_Assignments::assign_crew() once a booking's
	 * crew roster is saved. Per the "V1 launch scope" note at the top of this
	 * file, this fires immediately (no PWA accept step to wait for): the
	 * customer gets the "Assigned" email with who's coming, and every crew
	 * member on the job — not just the lead — gets the full job-details
	 * email in place of the deferred PWA's job-detail screen. Only the
	 * lead's copy additionally includes the no-login status-link section
	 * built from $lead_status_token — the plaintext is only ever available
	 * here, this one time, so it must be used now or never (same as every
	 * other token in this plugin).
	 *
	 * @param int    $booking_id        Booking id.
	 * @param int[]  $crew_ids          Every crew member now on the job.
	 * @param int    $lead_crew_id      The lead.
	 * @param string $lead_status_token Plaintext status-link token for the lead, or '' if none.
	 * @return void
	 */
	public function on_booking_assigned( $booking_id, $crew_ids, $lead_crew_id, $lead_status_token ) {
		$booking = self::get_booking_row( $booking_id );
		if ( ! $booking ) {
			return;
		}

		$placeholders = self::build_placeholders( $booking );

		$crew_names                 = array_filter( array_map( 'get_the_title', $crew_ids ) );
		$placeholders['crew_names'] = implode( ', ', $crew_names );

		self::send( 'assigned_customer', $booking->customer_email, $placeholders );

		$job_details = self::build_job_details_text( $booking_id );
		$map_url     = self::build_map_url( $booking );

		foreach ( $crew_ids as $crew_id ) {
			$employee_email = (string) get_post_meta( $crew_id, Service_Crew_Crew::META_EMAIL, true );
			if ( '' === $employee_email ) {
				continue;
			}

			$is_lead = (int) $crew_id === (int) $lead_crew_id;

			$employee_placeholders                       = $placeholders;
			$employee_placeholders['employee_name']      = get_the_title( $crew_id );
			$employee_placeholders['map_url']            = $map_url;
			$employee_placeholders['job_details']        = $job_details;
			$employee_placeholders['lead_note']          = $is_lead ? __( ' as the lead', 'service-crew' ) : '';
			$employee_placeholders['status_link_section'] = ( $is_lead && $lead_status_token )
				? "\n\n" . sprintf(
					/* translators: %s: status-link URL. */
					__( "Update the job status here — tap when you're on the way, starting, or finished:\n%s", 'service-crew' ),
					Service_Crew_Job_Status_Page::build_url( $lead_status_token )
				)
				: '';

			self::send( 'assignment_details_employee', $employee_email, $employee_placeholders );
		}
	}

	/**
	 * Fired from Service_Crew_Bookings::reschedule() whenever the date/window
	 * actually changes (not when an admin "reschedules" to the same values).
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $old_date   Previous date (unused — the email shows the new schedule only, per the plan's template).
	 * @param string $old_window Previous arrival window (unused, same reason).
	 * @param string $new_date   New date.
	 * @param string $new_window New arrival window.
	 * @return void
	 */
	public function on_booking_rescheduled( $booking_id, $old_date, $old_window, $new_date, $new_window ) {
		$booking = self::get_booking_row( $booking_id );
		if ( ! $booking ) {
			return;
		}

		self::send( 'booking_updated_customer', $booking->customer_email, self::build_placeholders( $booking ) );
	}
}
