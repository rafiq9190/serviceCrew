<?php
/**
 * Admin bell/menu-badge notifications. Listens for the two events confirmed
 * with the user — an instant booking being confirmed (paid), and a new quote
 * request being submitted — reusing the same WordPress action hooks
 * `class-service-crew-emails.php` already listens to for the admin emails,
 * rather than adding any new instrumentation to the booking/quote/payment
 * classes themselves. A third source, pending Agent chat escalations, is
 * merged into the bell's count/list by directly querying
 * Service_Crew_Agent_Escalations (see get_unread_count()/get_recent()) —
 * not via a hook, since an escalation has no booking_id to satisfy
 * sc_notifications' own NOT NULL column.
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

	const TYPE_BOOKING_CONFIRMED  = 'booking_confirmed';
	const TYPE_QUOTE_CREATED      = 'quote_created';
	const TYPE_AGENT_ESCALATION   = 'agent_escalation';

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
	 * menu-badge callback). Merges in pending agent escalations by querying
	 * Service_Crew_Agent_Escalations directly rather than inserting a row
	 * into sc_notifications for each one — that table's booking_id column is
	 * NOT NULL, and an escalation has no booking to attach to.
	 *
	 * @return int
	 */
	public static function get_unread_count() {
		global $wpdb;

		$booking_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sc_notifications WHERE is_read = 0" );

		return $booking_count + Service_Crew_Agent_Escalations::count_pending();
	}

	/**
	 * The most recent notifications, newest first, regardless of read state
	 * — the bell dropdown always shows recent activity, not just unread.
	 * Merges in every pending agent escalation (see get_unread_count()'s
	 * docblock for why those live in their own table, not sc_notifications),
	 * re-sorted together by created_at and capped at $limit.
	 *
	 * @param int $limit Row cap.
	 * @return array<int,object>
	 */
	public static function get_recent( $limit = 20 ) {
		global $wpdb;

		$booking_items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, booking_id, message, is_read, created_at
				FROM {$wpdb->prefix}sc_notifications
				ORDER BY created_at DESC
				LIMIT %d",
				$limit
			)
		);

		$escalation_items = array_map(
			function ( $escalation ) {
				return (object) array(
					'id'         => (int) $escalation->id,
					'type'       => self::TYPE_AGENT_ESCALATION,
					'booking_id' => 0,
					'message'    => sprintf(
						/* translators: %s: truncated visitor question. */
						__( 'Unanswered chat question — %s', 'service-crew' ),
						wp_trim_words( $escalation->question, 12, '…' )
					),
					'is_read'    => 0,
					'created_at' => $escalation->created_at,
				);
			},
			Service_Crew_Agent_Escalations::get_pending()
		);

		$combined = array_merge( $booking_items, $escalation_items );

		usort(
			$combined,
			function ( $a, $b ) {
				return strcmp( $b->created_at, $a->created_at );
			}
		);

		return array_slice( $combined, 0, $limit );
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
