<?php
/**
 * Quote-request creation and the admin's own side of that flow: the plan's
 * "out-of-band quotes" path — no service, no payment, just enough detail
 * (photos, title, description, preferred date/window, address, contact) for
 * the customer to submit one, and for the admin to price it outside the
 * system, mark it `quoted`, then send a deposit payment link once the
 * customer agrees (or reject it with a reason).
 *
 * Deliberately narrower than the full Phase 1c "Quotes, dispatch board,
 * teams, vendor pricing" (see ServiceCrew-Tasks.md):
 *  - The description, each photo, and the reject reason all go through
 *    `class-service-crew-notes.php`'s shared Service_Crew_Notes::add_note()/
 *    get_notes_for_booking() now, rather than writing to `sc_notes` directly
 *    (an earlier stand-in, from before that class existed).
 *  - No quote-expiry/no-response cron sweep — `quote_expires_at` is stored
 *    (from the admin-set "Quote validity (days)" timer) so a future
 *    `class-service-crew-cron.php` can act on it, but nothing here enforces
 *    it yet; an expired-but-unactioned quote just stays `quoted`.
 *  - No team assignment, crew suggestion, overtime approval, or emergency
 *    approval — that's the dispatch board itself, still not-started.
 *  - No "New quote"/"quoted"/"rejected" email to either party —
 *    `class-service-crew-emails.php` isn't built yet; the admin copies the
 *    deposit link by hand from the bookings screen and sends it themselves.
 *  - Geocoding is best-effort, same as instant bookings — never blocks
 *    submission.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Quotes {

	const STATUS_REQUESTED = 'requested';
	const STATUS_QUOTED    = 'quoted';
	const STATUS_REJECTED  = 'quote_rejected';
	const STATUS_EXPIRED   = 'quote_expired';

	/**
	 * Max photos accepted per quote submission.
	 *
	 * @var int
	 */
	const MAX_PHOTOS = 6;

	/**
	 * Max size per photo, in bytes (5 MB).
	 *
	 * @var int
	 */
	const MAX_PHOTO_BYTES = 5242880;

	/**
	 * Max submissions a single IP may make within RATE_LIMIT_WINDOW.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX = 3;

	/**
	 * Rate-limit window, in seconds.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 600;

	/**
	 * Validates and inserts a quote request: an `sc_bookings` row
	 * (source=quote, status=requested, no service/payment fields), a
	 * `sc_notes` row for the description, and one `sc_notes` row per
	 * successfully validated/uploaded photo.
	 *
	 * @param array<string,mixed>  $input Sanitizable form fields — see sanitize_and_validate().
	 * @param array<string,mixed>  $files Raw $_FILES-shaped array under the 'photos' key (may be absent).
	 * @param string               $ip    Client IP for rate limiting (already extracted by the caller).
	 * @return array{booking_id:int}|WP_Error
	 */
	public static function create_quote_request( array $input, array $files, $ip ) {
		$rate_limit_error = self::check_rate_limit( $ip );
		if ( is_wp_error( $rate_limit_error ) ) {
			return $rate_limit_error;
		}

		// Honeypot: a real visitor never fills this hidden field. Report the
		// same shape a real success would, so an automated submitter has no
		// signal it was caught, but skip every bit of real work.
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) ) {
			return array( 'booking_id' => 0 );
		}

		$fields = self::sanitize_and_validate( $input );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$photo_ids = self::process_photos( $files );
		if ( is_wp_error( $photo_ids ) ) {
			return $photo_ids;
		}

		return self::insert_quote_row( $fields, $photo_ids );
	}

	/**
	 * Sibling to create_quote_request() for the Agent chat's FLOW_QUOTE
	 * conversational flow (class-service-crew-agent-flows.php) — the widget
	 * can't do a normal multipart form submission mid-conversation, so a
	 * photo attached during the flow's own 'photos' step already went
	 * through a separate `POST /agent/upload` route
	 * (class-service-crew-agent-controller.php, reusing
	 * Service_Crew_Uploads::handle_photo_uploads() exactly as that route's
	 * own upload does) and is passed here as an already-validated
	 * attachment id, not a raw $_FILES entry. No rate limit or honeypot
	 * here — the chat's own per-session/per-IP rate limiting
	 * (class-service-crew-agent-controller.php's RATE_LIMIT_*) already
	 * covers this path.
	 *
	 * @param array<string,mixed> $fields    Same shape as sanitize_and_validate()'s return — the
	 *                                        flow handler is responsible for its own step-by-step
	 *                                        validation before calling this.
	 * @param int[]                $photo_ids Already-uploaded attachment ids (0-6).
	 * @return array{booking_id:int}|WP_Error
	 */
	public static function create_quote_request_from_attachments( array $fields, array $photo_ids = array() ) {
		$fields = array_merge(
			array(
				'title'            => '',
				'description'      => '',
				'name'             => '',
				'email'            => '',
				'phone'            => '',
				'address'          => '',
				'zip'              => '',
				'preferred_date'   => '',
				'arrival_window'   => '',
				'marketing_opt_in' => false,
			),
			$fields
		);

		if ( '' === $fields['title'] || '' === $fields['description'] || '' === $fields['name'] || ! is_email( $fields['email'] ) ) {
			return new WP_Error( 'sc_quote_missing_details', __( 'Please provide a title, description, name, and a valid email.', 'service-crew' ), array( 'status' => 400 ) );
		}

		return self::insert_quote_row( $fields, array_slice( array_map( 'absint', $photo_ids ), 0, self::MAX_PHOTOS ) );
	}

	/**
	 * Shared by create_quote_request() and create_quote_request_from_attachments():
	 * resolves the customer + geocode, inserts the `sc_bookings` row and its
	 * description/photo notes, and fires `sc_quote_created`.
	 *
	 * @param array<string,mixed> $fields    Validated fields (see sanitize_and_validate()'s return shape).
	 * @param int[]                $photo_ids Validated attachment ids.
	 * @return array{booking_id:int}|WP_Error
	 */
	private static function insert_quote_row( array $fields, array $photo_ids ) {
		$customer_id = Service_Crew_Customers::find_or_create( $fields['name'], $fields['email'], $fields['phone'], $fields['marketing_opt_in'] );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		$geocoding = new Service_Crew_Geocoding();
		$geocode   = $geocoding->geocode( $fields['address'], $fields['zip'] );

		global $wpdb;

		$columns = array(
			'customer_id'              => array( $customer_id, '%d' ),
			'customer_name'            => array( $fields['name'], '%s' ),
			'customer_email'           => array( $fields['email'], '%s' ),
			'customer_phone'           => array( $fields['phone'], '%s' ),
			'address'                  => array( $fields['address'], '%s' ),
			'zip'                      => array( $fields['zip'], '%s' ),
			'lat'                      => array( $geocode['lat'], '%f' ),
			'lng'                      => array( $geocode['lng'], '%f' ),
			'geocode_quality'          => array( $geocode['quality'], '%s' ),
			'preferred_date'           => array( '' !== $fields['preferred_date'] ? $fields['preferred_date'] : null, '%s' ),
			'arrival_window'           => array( '' !== $fields['arrival_window'] ? $fields['arrival_window'] : null, '%s' ),
			'status'                   => array( 'requested', '%s' ),
			'source'                   => array( 'quote', '%s' ),
			'quote_title'              => array( $fields['title'], '%s' ),
			'flag_address_review'      => array( ( '' === $fields['address'] && '' === $fields['zip'] ) ? 1 : 0, '%d' ),
			'flag_address_approximate' => array( Service_Crew_Geocoding::QUALITY_ZIP === $geocode['quality'] ? 1 : 0, '%d' ),
		);

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'sc_bookings',
			array_map( function ( $pair ) { return $pair[0]; }, $columns ),
			array_values( array_map( function ( $pair ) { return $pair[1]; }, $columns ) )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'sc_quote_insert_failed', __( 'Could not submit your quote request. Please try again.', 'service-crew' ) );
		}

		$booking_id = (int) $wpdb->insert_id;

		Service_Crew_Notes::add_note( $booking_id, Service_Crew_Notes::TYPE_QUOTE_DESCRIPTION, $fields['description'] );

		foreach ( $photo_ids as $photo_id ) {
			Service_Crew_Notes::add_note( $booking_id, Service_Crew_Notes::TYPE_QUOTE_PHOTO, '', $photo_id );
		}

		/**
		 * Fires once a quote request's rows are written — see
		 * class-service-crew-emails.php, which sends both the admin's "new
		 * quote" notice and the customer's "quote received" acknowledgement
		 * from this one event.
		 *
		 * @param int $booking_id Booking id.
		 */
		do_action( 'sc_quote_created', $booking_id );

		return array( 'booking_id' => $booking_id );
	}

	/**
	 * Sanitizes every posted field and enforces the plan's required set
	 * (title, description, name, a valid email) — the rest (date, window,
	 * address, zip, phone) are recorded as given, same as an instant
	 * booking's address/zip: missing ones just flag the row for admin
	 * review rather than blocking submission.
	 *
	 * @param array<string,mixed> $input Raw posted fields.
	 * @return array{title:string,description:string,name:string,email:string,phone:string,address:string,zip:string,preferred_date:string,arrival_window:string,marketing_opt_in:bool}|WP_Error
	 */
	private static function sanitize_and_validate( array $input ) {
		$fields = array(
			'title'            => sanitize_text_field( $input['title'] ?? '' ),
			'description'      => sanitize_textarea_field( $input['description'] ?? '' ),
			'name'             => sanitize_text_field( $input['name'] ?? '' ),
			'email'            => sanitize_email( $input['email'] ?? '' ),
			'phone'            => sanitize_text_field( $input['phone'] ?? '' ),
			'address'          => sanitize_text_field( $input['address'] ?? '' ),
			'zip'              => sanitize_text_field( $input['zip'] ?? '' ),
			'preferred_date'   => sanitize_text_field( $input['preferred_date'] ?? '' ),
			'arrival_window'   => sanitize_text_field( $input['arrival_window'] ?? '' ),
			'marketing_opt_in' => ! empty( $input['marketing_opt_in'] ),
		);

		if ( '' === $fields['title'] || '' === $fields['description'] ) {
			return new WP_Error( 'sc_quote_missing_details', __( 'Please describe the work you need done.', 'service-crew' ), array( 'status' => 400 ) );
		}

		if ( '' === $fields['name'] || ! is_email( $fields['email'] ) ) {
			return new WP_Error( 'sc_quote_invalid_contact', __( 'Please provide your name and a valid email address.', 'service-crew' ), array( 'status' => 400 ) );
		}

		if ( '' !== $fields['preferred_date'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fields['preferred_date'] ) ) {
			return new WP_Error( 'sc_quote_invalid_date', __( 'Please choose a valid preferred date.', 'service-crew' ), array( 'status' => 400 ) );
		}

		return $fields;
	}

	/**
	 * Validates and uploads every quote photo — now a thin wrapper around
	 * the shared Service_Crew_Uploads choke point (extracted from this
	 * method's original, verbatim implementation) so the employee job-status
	 * page's completion photos reuse the exact same hardened validation.
	 *
	 * @param array<string,mixed> $files Raw $_FILES['photos'] shape, or empty.
	 * @return int[]|WP_Error Attachment IDs.
	 */
	private static function process_photos( array $files ) {
		return Service_Crew_Uploads::handle_photo_uploads( $files['photos'] ?? array(), self::MAX_PHOTOS, self::MAX_PHOTO_BYTES );
	}

	/**
	 * Simple fixed-window rate limit shared by every quote submission, keyed
	 * by IP: at most RATE_LIMIT_MAX submissions per RATE_LIMIT_WINDOW
	 * seconds. Deliberately coarse (no proxy-aware IP resolution) — the goal
	 * is slowing down a naive scripted flood of this specific public form,
	 * not a robust anti-abuse system.
	 *
	 * @param string $ip Client IP.
	 * @return true|WP_Error
	 */
	private static function check_rate_limit( $ip ) {
		$ip = trim( (string) $ip );

		if ( '' === $ip ) {
			return true;
		}

		$key   = 'sc_quote_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return new WP_Error( 'sc_quote_rate_limited', __( 'Too many quote requests from this connection. Please try again later.', 'service-crew' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * @return string
	 */
	private static function bookings_table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_bookings';
	}

	/**
	 * Fetches a quote booking row plus its description and photos from
	 * `sc_notes`, for the admin bookings screen's expanded quote view.
	 * Returns null for a missing booking or one that isn't source=quote, so
	 * the admin can never (re-)price an instant booking through this path.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string,mixed>|null
	 */
	public static function get_quote_detail( $booking_id ) {
		global $wpdb;

		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::bookings_table() . ' WHERE id = %d AND source = %s', absint( $booking_id ), 'quote' ), ARRAY_A );

		if ( ! $booking ) {
			return null;
		}

		$notes = Service_Crew_Notes::get_notes_for_booking( $booking_id );

		$description = '';
		$photos      = array();
		$reject_reason = '';

		foreach ( $notes as $note ) {
			if ( Service_Crew_Notes::TYPE_QUOTE_DESCRIPTION === $note->note_type ) {
				$description = (string) $note->body;
			} elseif ( Service_Crew_Notes::TYPE_QUOTE_PHOTO === $note->note_type && $note->attachment_id ) {
				$url = wp_get_attachment_url( (int) $note->attachment_id );
				if ( $url ) {
					$photos[] = array(
						'id'  => (int) $note->attachment_id,
						'url' => $url,
					);
				}
			} elseif ( Service_Crew_Notes::TYPE_QUOTE_REJECTED === $note->note_type ) {
				$reject_reason = (string) $note->body;
			}
		}

		$booking['description']   = $description;
		$booking['photos']        = $photos;
		$booking['reject_reason'] = $reject_reason;

		return $booking;
	}

	/**
	 * Admin sets a price (and optionally a service, duration, crew size) on a
	 * quote request and marks it `quoted`. The customer_total the admin
	 * enters here is treated as final/all-in — unlike an instant booking's
	 * server-computed price, there is no service/component pricing or
	 * site-wide tax to layer on top of a manually agreed quote. Unlike an
	 * instant booking, a quote's deposit is always the full agreed price —
	 * Service_Crew_Settings' minimum-deposit brackets are an instant-booking-
	 * only concept, by explicit request, since a quote is already a
	 * hand-negotiated one-off price rather than a catalog total a bracket
	 * makes sense against.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param array<string,mixed>  $input {
	 *     @type int   $service_id      Optional leaf sc_service id, or 0 for none.
	 *     @type float $customer_total  Final agreed price, > 0.
	 *     @type int   $duration_days   Estimated job length in days, >= 1.
	 *     @type int   $crew_needed     Crew members needed, >= 1.
	 * }
	 * @return array<string,mixed>|WP_Error
	 */
	public static function set_price( $booking_id, array $input ) {
		$booking = self::get_quote_detail( $booking_id );
		if ( ! $booking ) {
			return new WP_Error( 'sc_quote_not_found', __( 'Quote request not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		if ( self::STATUS_REJECTED === $booking['status'] ) {
			return new WP_Error( 'sc_quote_rejected', __( 'This quote was rejected and can no longer be priced.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$customer_total = round( (float) ( $input['customer_total'] ?? 0 ), 2 );
		if ( $customer_total <= 0 ) {
			return new WP_Error( 'sc_quote_invalid_price', __( 'Please enter a price greater than zero.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$service_id = absint( $input['service_id'] ?? 0 );
		if ( $service_id ) {
			$service = get_post( $service_id );
			if ( ! $service || 'sc_service' !== $service->post_type || Service_Crew_Services::has_child_services( $service_id ) ) {
				return new WP_Error( 'sc_quote_invalid_service', __( 'Please choose a specific service, not a category.', 'service-crew' ), array( 'status' => 400 ) );
			}
		}

		$duration_days = max( 1, absint( $input['duration_days'] ?? 1 ) );
		$crew_needed   = max( 1, absint( $input['crew_needed'] ?? 1 ) );

		$settings        = Service_Crew_Settings::get_saved_settings();
		// Always 100% — the minimum-deposit brackets only apply to instant
		// bookings (Service_Crew_Bookings::create_instant_booking()); a quote's
		// price is already a manually agreed final amount.
		$deposit_percent = 100.0;
		$deposit_amount  = $customer_total;
		$expires_at      = gmdate( 'Y-m-d H:i:s', time() + ( max( 1, absint( $settings['timers']['quote_validity_days'] ) ) * DAY_IN_SECONDS ) );

		global $wpdb;
		$updated = $wpdb->update(
			self::bookings_table(),
			array(
				'service_id'               => $service_id ? $service_id : null,
				'duration_days'            => $duration_days,
				'crew_needed'              => $crew_needed,
				'subtotal_amount'          => $customer_total,
				'customer_total'           => $customer_total,
				'advance_discount_percent' => $deposit_percent,
				'deposit_amount'           => $deposit_amount,
				'status'                   => self::STATUS_QUOTED,
				'quote_expires_at'         => $expires_at,
				// Cleared on every (re-)pricing, not just the first: a quote
				// reopened after expiring (see QUOTE_MANAGEABLE_STATUSES'
				// own comment in admin/js/app-bookings.js) gets a fresh
				// quote_expires_at here, so it needs a fresh chance at the
				// expiry reminder too — see class-service-crew-cron.php.
				'quote_reminder_sent_at'   => null,
			),
			array( 'id' => absint( $booking_id ) ),
			array( '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'sc_quote_price_failed', __( 'Could not save this quote.', 'service-crew' ) );
		}

		return self::get_quote_detail( $booking_id );
	}

	/**
	 * Admin rejects a quote request with a reason, closing it. Logged as an
	 * `sc_notes` row rather than a plain column so it shows up alongside the
	 * description/photos in get_quote_detail() and, later, the dispatch
	 * board's notes timeline.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $reason     Admin-supplied reason, required.
	 * @return true|WP_Error
	 */
	public static function reject( $booking_id, $reason ) {
		$booking = self::get_quote_detail( $booking_id );
		if ( ! $booking ) {
			return new WP_Error( 'sc_quote_not_found', __( 'Quote request not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$reason = sanitize_textarea_field( (string) $reason );
		if ( '' === $reason ) {
			return new WP_Error( 'sc_quote_reject_reason_required', __( 'Please give a reason for rejecting this quote.', 'service-crew' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			self::bookings_table(),
			array( 'status' => self::STATUS_REJECTED ),
			array( 'id' => absint( $booking_id ) ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'sc_quote_reject_failed', __( 'Could not reject this quote.', 'service-crew' ) );
		}

		Service_Crew_Notes::add_note( absint( $booking_id ), Service_Crew_Notes::TYPE_QUOTE_REJECTED, $reason, null, get_current_user_id() );

		return true;
	}

	/**
	 * Once the customer has agreed to a `quoted` price, the admin sets the
	 * scheduled date + arrival window and generates a single-use deposit
	 * pay-page link (Service_Crew_Payments::generate_pay_token()) to send
	 * them by hand — no `class-service-crew-emails.php` yet to send it
	 * automatically. Paying it (public/class-service-crew-pay-page.php) fires
	 * the same `sc_payment_succeeded` action an instant booking's Stripe
	 * webhook does, which Service_Crew_Bookings::confirm_booking() already
	 * handles generically by booking id — nothing quote-specific is needed
	 * there.
	 *
	 * @param int    $booking_id     Booking id.
	 * @param string $date           YYYY-MM-DD the crew is scheduled for.
	 * @param string $arrival_window Arrival-window label (free text, matching an admin-configured window).
	 * @return array{url:string,expires_at:string,deposit_amount:float}|WP_Error
	 */
	public static function create_deposit_link( $booking_id, $date, $arrival_window ) {
		$booking = self::get_quote_detail( $booking_id );
		if ( ! $booking ) {
			return new WP_Error( 'sc_quote_not_found', __( 'Quote request not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		if ( self::STATUS_QUOTED !== $booking['status'] ) {
			return new WP_Error( 'sc_quote_not_priced', __( 'Set a price for this quote before sending a deposit link.', 'service-crew' ), array( 'status' => 400 ) );
		}

		if ( (float) $booking['deposit_amount'] <= 0 ) {
			return new WP_Error( 'sc_quote_no_deposit', __( 'This quote has no deposit amount to collect.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$date = sanitize_text_field( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'sc_quote_invalid_date', __( 'Please choose a valid scheduled date.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$arrival_window = sanitize_text_field( (string) $arrival_window );
		if ( '' === $arrival_window ) {
			return new WP_Error( 'sc_quote_invalid_window', __( 'Please choose an arrival window.', 'service-crew' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$wpdb->update(
			self::bookings_table(),
			array(
				'preferred_date' => $date,
				'arrival_window' => $arrival_window,
			),
			array( 'id' => absint( $booking_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$payment_id = Service_Crew_Payments::create_payment(
			array(
				'booking_id' => absint( $booking_id ),
				'kind'       => Service_Crew_Payments::KIND_DEPOSIT,
				'amount'     => (float) $booking['deposit_amount'],
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
			'url'             => Service_Crew_Pay_Page::build_url( $token ),
			'expires_at'      => $payment ? $payment->token_expires_at : '',
			'deposit_amount'  => (float) $booking['deposit_amount'],
		);
	}
}
