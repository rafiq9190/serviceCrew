<?php
/**
 * Shared `sc_notes` data access — the plan's dispatch-board "editable
 * timeline", also used today for a quote's description/photos and reject
 * reason, and later for system notes on payment, refund, overtime and
 * vendor-payment events (per the plan's data-model notes). Every subsystem
 * that needs a timestamped note against a booking goes through this rather
 * than writing to `sc_notes` directly, so note_type values and the table's
 * shape stay in one place — class-service-crew-quotes.php used to write
 * directly to this table itself (its own docblock called that out as a
 * stand-in "since that class's real job... doesn't exist yet either"); now
 * that this class exists, it's been switched over.
 *
 * Deliberately just the data layer: no REST routes of its own yet beyond
 * what Service_Crew_Bookings_Controller exposes for the admin bookings
 * screen's own simple notes UI. The full dispatch-board timeline display is
 * a separate, not-yet-built Phase 1c/1e task.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Notes {

	/**
	 * An admin's own free-text note on a booking — the dispatch board's
	 * primary use case.
	 *
	 * @var string
	 */
	const TYPE_NOTE = 'note';

	/**
	 * A quote request's customer-submitted description. See
	 * class-service-crew-quotes.php's create_quote_request().
	 *
	 * @var string
	 */
	const TYPE_QUOTE_DESCRIPTION = 'quote_description';

	/**
	 * One row per quote-request photo attachment. See
	 * class-service-crew-quotes.php's create_quote_request().
	 *
	 * @var string
	 */
	const TYPE_QUOTE_PHOTO = 'quote_photo';

	/**
	 * An admin's reason for rejecting a quote. See
	 * class-service-crew-quotes.php's reject().
	 *
	 * @var string
	 */
	const TYPE_QUOTE_REJECTED = 'quote_rejected';

	/**
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_notes';
	}

	/**
	 * Inserts a note row. Callers are responsible for sanitizing $body
	 * before calling (same "sanitize at the edge, trust it past that point"
	 * convention as Service_Crew_Payments::create_payment() and friends) —
	 * this is the shared data layer, not a REST boundary itself.
	 *
	 * @param int         $booking_id    Booking id.
	 * @param string      $note_type     One of this class's TYPE_* constants (or a future one — not hard-validated against the list, same as Service_Crew_Payments::create_payment() doesn't hard-validate every enum either).
	 * @param string      $body          Note text; '' for a pure photo-attachment row.
	 * @param int|null    $attachment_id Optional attachment id (quote photos).
	 * @param int|null    $author_id     Optional wp_users id; null for a system-generated note (no human author).
	 * @return int|WP_Error New note id, or WP_Error on failure.
	 */
	public static function add_note( $booking_id, $note_type, $body = '', $attachment_id = null, $author_id = null ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'booking_id'    => absint( $booking_id ),
				'author_id'     => null === $author_id ? null : absint( $author_id ),
				'note_type'     => sanitize_key( $note_type ),
				'body'          => (string) $body,
				'attachment_id' => null === $attachment_id ? null : absint( $attachment_id ),
			),
			array( '%d', '%d', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'sc_note_insert_failed', __( 'Could not save this note.', 'service-crew' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Every note for a booking, oldest first — the timeline order the
	 * dispatch board and CRM customer-detail screens (Phase 1e) both want.
	 *
	 * @param int $booking_id Booking id.
	 * @return object[]
	 */
	public static function get_notes_for_booking( $booking_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE booking_id = %d ORDER BY created_at ASC, id ASC',
				absint( $booking_id )
			)
		);
	}
}
