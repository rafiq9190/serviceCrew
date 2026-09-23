<?php
/**
 * Fired during plugin activation.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation: creates every ServiceCrew custom table via
 * dbDelta() and registers the sc_crew_member role.
 *
 * Deliberately no sc_location table and no location-based capacity — date
 * capacity is pooled across employees (see class-service-crew-capacity.php,
 * built in Phase 1b-2).
 */
class Service_Crew_Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::register_roles();

		update_option( 'sc_db_version', SERVICE_CREW_DB_VERSION );
	}

	/**
	 * Creates (or upgrades) every ServiceCrew custom table via dbDelta().
	 *
	 * @return void
	 */
	private static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta( self::bookings_sql( $wpdb, $charset_collate ) );
		dbDelta( self::booking_components_sql( $wpdb, $charset_collate ) );
		dbDelta( self::booking_assignments_sql( $wpdb, $charset_collate ) );
		dbDelta( self::payments_sql( $wpdb, $charset_collate ) );
		dbDelta( self::refunds_sql( $wpdb, $charset_collate ) );
		dbDelta( self::vendor_payments_sql( $wpdb, $charset_collate ) );
		dbDelta( self::closed_dates_sql( $wpdb, $charset_collate ) );
		dbDelta( self::overtime_sql( $wpdb, $charset_collate ) );
		dbDelta( self::notes_sql( $wpdb, $charset_collate ) );
	}

	/**
	 * Registers the sc_crew_member role and grants administrators the
	 * sc_service CPT's meta capabilities.
	 *
	 * sc_crew_member gets no wp-admin capabilities at all — it exists only so
	 * the future PWA (Phase 1d) can authenticate crew/vendor users via
	 * Application Passwords against custom REST routes. REST permission
	 * callbacks check the role/identity directly rather than relying on
	 * wp-admin caps.
	 *
	 * edit_sc_service/read_sc_service/delete_sc_service are custom capability
	 * strings (see Service_Crew_Services::register_post_type()), not core
	 * ones, so the administrator role needs them added explicitly — unlike
	 * 'manage_options', administrators don't have them by default.
	 *
	 * @return void
	 */
	private static function register_roles() {
		add_role( 'sc_crew_member', __( 'Crew Member', 'service-crew' ), array() );

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( 'edit_sc_service' );
			$administrator->add_cap( 'read_sc_service' );
			$administrator->add_cap( 'delete_sc_service' );
			$administrator->add_cap( 'edit_sc_crew' );
			$administrator->add_cap( 'read_sc_crew' );
			$administrator->add_cap( 'delete_sc_crew' );
		}
	}

	/**
	 * sc_bookings — one row per instant booking or quote request.
	 *
	 * Shares one table across both booking sources ("source" column) because
	 * the plan's status list mixes instant-booking statuses
	 * (awaiting_payment, confirmed, assigned, on_the_way, in_progress,
	 * completed) with quote statuses (requested, quoted, quote_expired,
	 * quote_rejected) plus the shared pending_approval/cancelled states.
	 *
	 * Flags are deliberately separate tinyint columns rather than a packed
	 * bitmask or CSV column: they are independent, queryable booleans
	 * (a booking can be both "overload" and "address_approximate" at once)
	 * and the board/board filters need to query on them directly.
	 *
	 * service_id and customer_id are nullable: a quote request has no
	 * service until the admin sets one, and there is no sc_customers table
	 * yet (arrives in Phase 1b-2's class-service-crew-customers.php) — until
	 * then customer_name/email/phone on this row are the source of truth,
	 * and customer_id is reserved for that class to populate later via
	 * find-or-create by email.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function bookings_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_bookings';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned DEFAULT NULL,
			customer_id bigint(20) unsigned DEFAULT NULL,
			customer_name varchar(191) NOT NULL DEFAULT '',
			customer_email varchar(191) NOT NULL DEFAULT '',
			customer_phone varchar(50) NOT NULL DEFAULT '',
			address varchar(255) NOT NULL DEFAULT '',
			zip varchar(20) NOT NULL DEFAULT '',
			lat decimal(10,7) DEFAULT NULL,
			lng decimal(10,7) DEFAULT NULL,
			geocode_quality varchar(20) NOT NULL DEFAULT 'failed',
			preferred_date date DEFAULT NULL,
			arrival_window varchar(20) DEFAULT NULL,
			duration_days smallint(5) unsigned NOT NULL DEFAULT 1,
			is_emergency tinyint(1) unsigned NOT NULL DEFAULT 0,
			crew_needed smallint(5) unsigned NOT NULL DEFAULT 1,
			status varchar(30) NOT NULL DEFAULT 'requested',
			source varchar(20) NOT NULL DEFAULT 'instant',
			quote_title varchar(191) DEFAULT NULL,
			quote_expires_at datetime DEFAULT NULL,
			quote_reminder_sent_at datetime DEFAULT NULL,
			accepted_unscheduled_at datetime DEFAULT NULL,
			subtotal_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			quantity_discount_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			advance_discount_percent decimal(5,2) NOT NULL DEFAULT 0.00,
			advance_discount_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			customer_total decimal(10,2) NOT NULL DEFAULT 0.00,
			deposit_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			amount_paid decimal(10,2) NOT NULL DEFAULT 0.00,
			flag_outside_coverage tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_address_approximate tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_address_review tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_overload tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_declined tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_cancelled_by_crew tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_no_response tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_unassignable tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_assignee_unavailable tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_needs_more_time tinyint(1) unsigned NOT NULL DEFAULT 0,
			flag_paid_needs_action tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY preferred_date (preferred_date),
			KEY service_id (service_id),
			KEY customer_email (customer_email),
			KEY source (source)
		) {$charset_collate};";
	}

	/**
	 * sc_booking_components — the priced/quantity-snapshotted line items for
	 * a booking, one row per selected service component.
	 *
	 * All pricing fields here are snapshots taken at booking time (per the
	 * plan's snapshot-immutable rule): if the admin later edits the
	 * service's components or prices, past bookings must not change.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function booking_components_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_booking_components';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			component_id bigint(20) unsigned NOT NULL,
			component_name varchar(191) NOT NULL DEFAULT '',
			is_required tinyint(1) unsigned NOT NULL DEFAULT 0,
			quantity smallint(5) unsigned NOT NULL DEFAULT 1,
			unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
			unit_duration_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
			line_subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			quantity_discount_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			line_total decimal(10,2) NOT NULL DEFAULT 0.00,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY component_id (component_id)
		) {$charset_collate};";
	}

	/**
	 * sc_booking_assignments — the crew (employee or vendor) proposed or
	 * confirmed for a booking. A booking has a list of these, not one crew
	 * id, so team jobs ("2 of 3 assigned") work.
	 *
	 * Decline history is kept by never deleting or overwriting a row when a
	 * person declines/cancels: a new assignment row is inserted for the next
	 * candidate, and the old row stays with status = declined /
	 * cancelled_by_crew so the matching class can exclude that person from
	 * future suggestions for the same booking.
	 *
	 * agreed_amount is nullable at the schema level (employees never have
	 * one) but the plan requires it be set before a vendor can be assigned —
	 * that rule belongs in the assignments class (Phase 1c), not here.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function booking_assignments_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_booking_assignments';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			crew_id bigint(20) unsigned NOT NULL,
			role varchar(10) NOT NULL DEFAULT 'member',
			status varchar(20) NOT NULL DEFAULT 'proposed',
			decline_reason text,
			agreed_amount decimal(10,2) DEFAULT NULL,
			assigned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			responded_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY crew_id (crew_id),
			KEY status (status)
		) {$charset_collate};";
	}

	/**
	 * sc_payments — deposit / balance / extra / surcharge payment records,
	 * one row per attempted or completed payment (Stripe first, PayPal
	 * later through the same gateway interface).
	 *
	 * token_hash/token_expires_at back the private pay-page links (Phase
	 * 1b-1): tokens are stored hashed and single-purpose per the plan, never
	 * in plaintext.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function payments_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_payments';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			kind varchar(20) NOT NULL DEFAULT 'deposit',
			amount decimal(10,2) NOT NULL DEFAULT 0.00,
			status varchar(20) NOT NULL DEFAULT 'pending',
			provider varchar(20) NOT NULL DEFAULT 'stripe',
			provider_reference varchar(191) DEFAULT NULL,
			token_hash varchar(64) DEFAULT NULL,
			token_expires_at datetime DEFAULT NULL,
			paid_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY status (status),
			UNIQUE KEY token_hash (token_hash)
		) {$charset_collate};";
	}

	/**
	 * sc_refunds — admin-only, full or partial refunds against a payment,
	 * always with a reason (logged as a system note by the caller).
	 *
	 * booking_id is denormalized alongside payment_id so the board can list
	 * a booking's refunds without joining through sc_payments.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function refunds_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_refunds';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			payment_id bigint(20) unsigned NOT NULL,
			booking_id bigint(20) unsigned NOT NULL,
			amount decimal(10,2) NOT NULL DEFAULT 0.00,
			reason text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			provider_reference varchar(191) DEFAULT NULL,
			refunded_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY payment_id (payment_id),
			KEY booking_id (booking_id)
		) {$charset_collate};";
	}

	/**
	 * sc_vendor_payments — what the admin has paid a vendor for an
	 * assignment, in part or in full, outside the system (the plugin only
	 * tracks it, it never moves money to a vendor itself).
	 *
	 * Deliberately no stored "status" column (pending/partially paid/paid):
	 * that is derived at read time by comparing
	 * SUM(amount) for the assignment against
	 * sc_booking_assignments.agreed_amount, so it can never drift out of
	 * sync with the underlying rows.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function vendor_payments_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_vendor_payments';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			assignment_id bigint(20) unsigned NOT NULL,
			amount decimal(10,2) NOT NULL DEFAULT 0.00,
			paid_date date NOT NULL,
			method varchar(50) NOT NULL DEFAULT '',
			note text,
			recorded_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY assignment_id (assignment_id)
		) {$charset_collate};";
	}

	/**
	 * sc_closed_dates — admin-managed holidays / manual closures used by the
	 * availability and capacity classes to skip non-business days.
	 *
	 * is_recurring_yearly lets a holiday (e.g. 25 December) repeat every
	 * year by month/day without the admin re-entering it; a one-off closure
	 * (e.g. a single day off) leaves it 0 and matches only that exact date.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function closed_dates_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_closed_dates';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			closed_date date NOT NULL,
			reason varchar(191) DEFAULT NULL,
			is_recurring_yearly tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY closed_date (closed_date)
		) {$charset_collate};";
	}

	/**
	 * sc_overtime — per-assignment overtime hours, case-by-case admin
	 * approval, and the (admin-only, never customer-facing) surcharge
	 * decision for that overtime.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function overtime_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_overtime';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			assignment_id bigint(20) unsigned NOT NULL,
			hours decimal(5,2) NOT NULL DEFAULT 0.00,
			status varchar(20) NOT NULL DEFAULT 'pending',
			approved_by bigint(20) unsigned DEFAULT NULL,
			approved_at datetime DEFAULT NULL,
			surcharge_decision varchar(20) NOT NULL DEFAULT 'none',
			surcharge_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			note text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY assignment_id (assignment_id)
		) {$charset_collate};";
	}

	/**
	 * sc_notes — the shared notes/timeline table for a booking: dispatcher
	 * notes, the quote description (photos attach via attachment_id, one row
	 * per photo), and system notes for payment, refund, overtime and
	 * vendor-payment events.
	 *
	 * author_id is nullable because system-generated notes (e.g. "refund of
	 * $20 issued") have no human author.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function notes_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_notes';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			author_id bigint(20) unsigned DEFAULT NULL,
			note_type varchar(20) NOT NULL DEFAULT 'note',
			body text,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY author_id (author_id)
		) {$charset_collate};";
	}
}
