<?php
/**
 * Customer identity: finds-or-creates a single `sc_customers` row per unique
 * email address — the piece `sc_bookings.customer_id` was always reserved
 * for (see that table's schema comment in class-service-crew-activator.php).
 * A booking/quote row's own customer_name/email/phone columns stay the
 * point-in-time source of truth for what the job was actually billed/
 * contacted as and are never overwritten by this class; sc_customers only
 * accumulates one durable identity a person's bookings can be grouped under,
 * for Phase 1e's CRM screens.
 *
 * Deliberately just the find-or-create lookup — not yet the plan's own
 * "Customers REST controller" (still Phase 1b-2, not-started) or Phase 1e's
 * customer list/search/detail screens. Wired into
 * class-service-crew-bookings.php's create_instant_booking() and
 * class-service-crew-quotes.php's create_quote_request() to populate
 * customer_id as each is created; nothing reads it back yet.
 *
 * Also captures marketing_opt_in (an "email me about offers and updates"
 * checkbox on both the instant-booking and quote forms, unchecked by
 * default) — by request, so consent is on file before any actual
 * promotional-email feature exists to use it. This plugin has no sender or
 * campaign UI anywhere yet; the flag just sits on the row until one is
 * built.
 *
 * Not a GDPR/CAN-SPAM compliance review of a future sending feature — just
 * schema-level consent capture at the only two points a customer's contact
 * info enters the system today.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Customers {

	/**
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_customers';
	}

	/**
	 * Finds the customer row matching this email, updating its name/phone to
	 * the latest values if it already exists, or inserts a new row if not.
	 * Email is the identity key — always looked up/stored lowercased so
	 * "Jane@x.com" and "jane@x.com" resolve to the same row regardless of
	 * what casing a repeat customer happens to type.
	 *
	 * @param string $name             Customer's name.
	 * @param string $email            Customer's email. The caller (booking/quote creation) has
	 *                                 already run is_email()/sanitize_email() and rejected the
	 *                                 request if invalid — this does not re-validate format, only
	 *                                 rejects an empty value.
	 * @param string $phone            Customer's phone, optional.
	 * @param bool   $marketing_opt_in Whether this submission's "email me about offers" box was
	 *                                 checked. Only ever moves the stored flag from 0 to 1 — a
	 *                                 later booking/quote submitted with the box left unchecked
	 *                                 never silently withdraws consent already on file, since
	 *                                 there is no unsubscribe flow yet that could re-grant it.
	 * @return int|WP_Error Customer id, or WP_Error if the email is empty or the insert failed.
	 */
	public static function find_or_create( $name, $email, $phone = '', $marketing_opt_in = false ) {
		$name             = sanitize_text_field( $name );
		$email            = strtolower( sanitize_email( $email ) );
		$phone            = sanitize_text_field( $phone );
		$marketing_opt_in = (bool) $marketing_opt_in;

		if ( '' === $email ) {
			return new WP_Error( 'sc_customer_email_required', __( 'A customer email is required.', 'service-crew' ) );
		}

		global $wpdb;

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE email = %s', $email ) );

		if ( $existing_id ) {
			$update_data    = array(
				'name'  => $name,
				'phone' => $phone,
			);
			$update_formats = array( '%s', '%s' );

			if ( $marketing_opt_in ) {
				$update_data['marketing_opt_in'] = 1;
				$update_formats[]                = '%d';
			}

			$wpdb->update( self::table(), $update_data, array( 'id' => $existing_id ), $update_formats, array( '%d' ) );

			return $existing_id;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'name'             => $name,
				'email'            => $email,
				'phone'            => $phone,
				'marketing_opt_in' => $marketing_opt_in ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'sc_customer_insert_failed', __( 'Could not create a customer record.', 'service-crew' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $customer_id Customer id.
	 * @return object|null
	 */
	public static function get_customer( $customer_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $customer_id ) ) );
	}
}
