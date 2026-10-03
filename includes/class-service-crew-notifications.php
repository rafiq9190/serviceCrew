<?php
/**
 * Admin bell/menu-badge notifications. Listens for exactly the two events
 * confirmed with the user — an instant booking being confirmed (paid), and a
 * new quote request being submitted — reusing the same WordPress action
 * hooks `class-service-crew-emails.php` already listens to for the admin
 * emails, rather than adding any new instrumentation to the booking/quote/
 * payment classes themselves.
 *
 * Mirrors that class's own hook-registration pattern, including its
 * documented priority-20 reasoning for `sc_payment_succeeded` (must run
 * after `Service_Crew_Bookings::confirm_booking()`'s priority-10 handler has
 * already flipped the row to `confirmed`).
 *
 * `sc_payment_succeeded` fires identically for both an instant booking's
 * deposit and a quote's deposit-link payment (see `Service_Crew_Emails`'s own
 * docblock) — filtered here to `source === 'instant'` only, since a quote
 * already gets its own notification at `sc_quote_created` time and a second
 * one when its deposit is later paid would just be redundant noise.
 *
 * Read-tracking model is deliberately coarse: opening the bell marks every
 * notification read at once (`mark_all_read()`) — no per-item read state or
 * dismiss UI.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Notifications {

	const TYPE_BOOKING_CONFIRMED = 'booking_confirmed';
	const TYPE_QUOTE_CREATED     = 'quote_created';

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap.
	 */
	public function __construct() {
		// Priority 20: after Service_Crew_Bookings::confirm_booking() (10) has
		// already flipped the row to `confirmed` — same reasoning as
		// Service_Crew_Emails::on_payment_succeeded()'s identical priority.
		add_action( 'sc_payment_succeeded', array( $this, 'on_payment_succeeded' ), 20, 2 );
		add_action( 'sc_quote_created', array( $this, 'on_quote_created' ) );
	}

	/**
	 * Notifies on an instant booking's deposit succeeding — not a quote's
	 * deposit-link payment, which this same action also fires for (see class
	 * docblock).
	 *
	 * @param int $payment_id Payment id (unused; kept to match the action's signature).
	 * @param int $booking_id Booking id the payment belongs to.
	 * @return void
	 */
	public function on_payment_succeeded( $payment_id, $booking_id ) {
		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source, service_id, customer_total FROM {$wpdb->prefix}sc_bookings WHERE id = %d",
				$booking_id
			)
		);

		if ( ! $booking || 'instant' !== $booking->source ) {
			return;
		}

		$service_name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT service_name FROM {$wpdb->prefix}sc_booking_services WHERE booking_id = %d ORDER BY id ASC LIMIT 1",
				$booking_id
			)
		);

		if ( ! $service_name ) {
			$post         = $booking->service_id ? get_post( $booking->service_id ) : null;
			$service_name = $post ? $post->post_title : __( 'Deleted service', 'service-crew' );
		}

		$message = sprintf(
			/* translators: 1: service name, 2: formatted total amount. */
			__( 'New booking confirmed — %1$s ($%2$s)', 'service-crew' ),
			$service_name,
			number_format( (float) $booking->customer_total, 2 )
		);

		self::create( self::TYPE_BOOKING_CONFIRMED, $booking_id, $message );
	}

	/**
	 * Notifies on a new quote request being submitted.
	 *
	 * @param int $booking_id Booking id (source=quote row just inserted).
	 * @return void
	 */
	public function on_quote_created( $booking_id ) {
		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT quote_title, customer_name FROM {$wpdb->prefix}sc_bookings WHERE id = %d",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: quote title, 2: customer name. */
			__( 'New quote request — %1$s (%2$s)', 'service-crew' ),
			$booking->quote_title ? $booking->quote_title : __( 'Untitled', 'service-crew' ),
			$booking->customer_name
		);

		self::create( self::TYPE_QUOTE_CREATED, $booking_id, $message );
	}

	/**
	 * Inserts one notification row.
	 *
	 * @param string $type       One of the TYPE_* constants.
	 * @param int    $booking_id Booking the notification is about.
	 * @param string $message    Pre-rendered display text.
	 * @return void
	 */
	private static function create( $type, $booking_id, $message ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'sc_notifications',
			array(
				'type'       => $type,
				'booking_id' => $booking_id,
				'message'    => $message,
			),
			array( '%s', '%d', '%s' )
		);
	}

	/**
	 * Count of unread notifications — read on every wp-admin page load (the
	 * menu-badge callback), so this stays a single indexed COUNT(*).
	 *
	 * @return int
	 */
	public static function get_unread_count() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sc_notifications WHERE is_read = 0" );
	}

	/**
	 * The most recent notifications, newest first, regardless of read state
	 * — the bell dropdown always shows recent activity, not just unread.
	 *
	 * @param int $limit Row cap.
	 * @return array<int,object>
	 */
	public static function get_recent( $limit = 20 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, booking_id, message, is_read, created_at
				FROM {$wpdb->prefix}sc_notifications
				ORDER BY created_at DESC
				LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Marks every notification read at once — see class docblock for why
	 * there's no per-item read state.
	 *
	 * @return void
	 */
	public static function mark_all_read() {
		global $wpdb;

		$wpdb->query( "UPDATE {$wpdb->prefix}sc_notifications SET is_read = 1 WHERE is_read = 0" );
	}
}
