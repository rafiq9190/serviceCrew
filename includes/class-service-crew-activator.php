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

		// Consumed (and deleted) by Service_Crew_Wizard::maybe_redirect_to_wizard()
		// on the very next admin request, so a single activation redirects to
		// the setup wizard exactly once.
		set_transient( 'sc_activation_redirect', true, 30 );
	}

	/**
	 * Schema migrations for a site that's already active on an older
	 * SERVICE_CREW_DB_VERSION — activate() only ever runs once, on
	 * activation, so a later version bump needs its own seam to actually
	 * reach an already-installed site. Hooked on admin_init (not
	 * plugins_loaded) in service-crew.php, so this never runs on the public,
	 * unauthenticated booking-checkout endpoint. dbDelta() is additive/
	 * idempotent, so re-running create_tables() here is always safe.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'sc_db_version' ) === SERVICE_CREW_DB_VERSION ) {
			return;
		}

		self::create_tables();
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

		dbDelta( self::customers_sql( $wpdb, $charset_collate ) );
		dbDelta( self::bookings_sql( $wpdb, $charset_collate ) );
		dbDelta( self::booking_components_sql( $wpdb, $charset_collate ) );
		dbDelta( self::booking_services_sql( $wpdb, $charset_collate ) );
		dbDelta( self::booking_assignments_sql( $wpdb, $charset_collate ) );
		dbDelta( self::payments_sql( $wpdb, $charset_collate ) );
		dbDelta( self::refunds_sql( $wpdb, $charset_collate ) );
		dbDelta( self::vendor_payments_sql( $wpdb, $charset_collate ) );
		dbDelta( self::closed_dates_sql( $wpdb, $charset_collate ) );
		dbDelta( self::overtime_sql( $wpdb, $charset_collate ) );
		dbDelta( self::notes_sql( $wpdb, $charset_collate ) );
		dbDelta( self::notifications_sql( $wpdb, $charset_collate ) );
		dbDelta( self::agent_sessions_sql( $wpdb, $charset_collate ) );
		dbDelta( self::agent_messages_sql( $wpdb, $charset_collate ) );
		dbDelta( self::agent_kb_entries_sql( $wpdb, $charset_collate ) );
		dbDelta( self::agent_escalations_sql( $wpdb, $charset_collate ) );
		dbDelta( self::agent_push_subscriptions_sql( $wpdb, $charset_collate ) );
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
	 * sc_customers — one row per unique email address, populated by
	 * class-service-crew-customers.php's find-or-create as each booking/quote
	 * is created. email is the identity key (unique), not id — a customer who
	 * books twice with the same email resolves to the same row regardless of
	 * what name/phone they typed the second time.
	 *
	 * marketing_opt_in is captured for a future promotional-email feature
	 * (not built yet — there is no sender/campaign UI anywhere in this
	 * plugin) so consent is never lost waiting on that feature to exist.
	 * Sticky once true: Service_Crew_Customers::find_or_create() never resets
	 * it back to 0, since there is no unsubscribe flow yet that could
	 * legitimately re-grant it.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function customers_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_customers';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			marketing_opt_in tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY marketing_opt_in (marketing_opt_in)
		) {$charset_collate};";
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
	 * service until the admin sets one, and customer_id is only populated
	 * once class-service-crew-customers.php's find-or-create runs (wired into
	 * both the instant-booking and quote creation paths) — customer_name/
	 * email/phone on this row remain the point-in-time source of truth for
	 * what the job was actually billed/contacted as either way, never
	 * overwritten by the sc_customers row they resolve to.
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
			booking_service_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY component_id (component_id),
			KEY booking_service_id (booking_service_id)
		) {$charset_collate};";
	}

	/**
	 * sc_booking_services — one row per top-level service selected on a
	 * booking, for every instant booking (even a single-service one, so
	 * there is exactly one code path rather than a single-vs-multi branch).
	 * Whether more than one row per booking is ever allowed is an admin
	 * setting (Service_Crew_Settings' allow_multiple_services), enforced in
	 * Service_Crew_Bookings::create_instant_booking(), not by this schema.
	 *
	 * All fields here are snapshots, same "immutable at booking time" rule as
	 * sc_booking_components: unit_price is the raw per-service unit price
	 * (pre-quantity-multiplication); line_subtotal is already multiplied by
	 * quantity (Service_Crew_Pricing::calculate_service_line()'s price) and
	 * does not include this service's own add-ons, which get their own
	 * sc_booking_components rows linked back via booking_service_id.
	 *
	 * sc_bookings.service_id is unchanged by this table's existence — it
	 * still always points at the first selected item, so the admin bookings
	 * list's existing single-service JOIN needs no changes; this table is
	 * the authoritative full breakdown alongside it.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function booking_services_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_booking_services';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			service_id bigint(20) unsigned NOT NULL,
			service_name varchar(191) NOT NULL DEFAULT '',
			quantity smallint(5) unsigned NOT NULL DEFAULT 1,
			unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
			unit_duration_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
			line_subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY service_id (service_id)
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
	 * status_token_hash/status_token_expires_at back the lead's no-login job
	 * status link (class-service-crew-job-status-page.php, V1's email-based
	 * stand-in for the deferred crew PWA — see ServiceCrew-Plan-v2.md's "V1
	 * launch scope") — same "stored hashed, single-purpose, expires" rule as
	 * every other token in this plugin (see Service_Crew_Payments). Only the
	 * lead's row ever gets one; a member's row stays null in both columns.
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
			status_token_hash varchar(64) DEFAULT NULL,
			status_token_expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY crew_id (crew_id),
			KEY status (status),
			UNIQUE KEY status_token_hash (status_token_hash)
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

	/**
	 * sc_notifications — the admin bell/menu-badge feed. One row per event
	 * Service_Crew_Notifications listens for (an instant booking confirmed,
	 * a quote submitted); message is a pre-rendered snapshot at creation
	 * time, same "snapshot" convention as sc_booking_components/
	 * sc_booking_services, so a read never needs to re-join other tables.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function notifications_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_notifications';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(30) NOT NULL DEFAULT '',
			booking_id bigint(20) unsigned NOT NULL,
			message varchar(255) NOT NULL DEFAULT '',
			is_read tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_read (is_read),
			KEY booking_id (booking_id)
		) {$charset_collate};";
	}

	/**
	 * sc_agent_sessions — one row per visitor conversation with the "Agent"
	 * rule-based chat sales agent (Phase A of the agent feature — see
	 * C:\Users\fujitsu\.claude\plans\scalable-wondering-milner.md). The
	 * visitor's plaintext session key lives only in their own browser
	 * (localStorage); only its SHA-256 hash is stored here, same
	 * stored-hashed/single-purpose convention as every other token in this
	 * plugin (Service_Crew_Payments, status tokens). booking_id links to a
	 * real sc_bookings row once a conversation produces a quote/booking — no
	 * parallel "lead" concept. pending_flow/pending_flow_state are unused
	 * until Phase B's slot-filling flows exist; present now so Phase B needs
	 * no further migration.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function agent_sessions_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_agent_sessions';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_token_hash varchar(64) NOT NULL,
			session_token_expires_at datetime DEFAULT NULL,
			visitor_name varchar(191) NOT NULL DEFAULT '',
			visitor_email varchar(191) NOT NULL DEFAULT '',
			visitor_phone varchar(50) NOT NULL DEFAULT '',
			booking_id bigint(20) unsigned DEFAULT NULL,
			pending_flow varchar(30) DEFAULT NULL,
			pending_flow_state longtext,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY session_token_hash (session_token_hash),
			KEY booking_id (booking_id),
			KEY status (status)
		) {$charset_collate};";
	}

	/**
	 * sc_agent_messages — full transcript for an agent session. meta stores
	 * the matched KB id/confidence as JSON (debugging + future KB curation
	 * from real transcripts); attachment_id is a normal WP attachment id,
	 * unused until Phase B's photo-upload flow.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function agent_messages_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_agent_messages';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			sender varchar(10) NOT NULL DEFAULT 'visitor',
			message_type varchar(20) NOT NULL DEFAULT 'text',
			body longtext,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			meta longtext,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
		) {$charset_collate};";
	}

	/**
	 * sc_agent_kb_entries — the admin-curated + self-learned FAQ the rule-
	 * based matcher (class-service-crew-agent-matcher.php) scores a visitor's
	 * message against. source = 'learned' is an admin's escalation answer
	 * (Phase C) saved back in so the same question auto-answers next time —
	 * this is literally the agent's own memory the owner asked for.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function agent_kb_entries_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_agent_kb_entries';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question varchar(255) NOT NULL DEFAULT '',
			answer text,
			keywords varchar(255) NOT NULL DEFAULT '',
			related_service_id bigint(20) unsigned DEFAULT NULL,
			source varchar(10) NOT NULL DEFAULT 'manual',
			is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
			hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_active (is_active),
			KEY source (source)
		) {$charset_collate};";
	}

	/**
	 * sc_agent_escalations — a visitor question the matcher couldn't
	 * confidently (or plausibly) answer, saved for an admin to answer from the
	 * Agent screen's "Pending escalations" card. Answering one both logs the
	 * answer back into the session's own transcript
	 * (Service_Crew_Agent::log_message()) and saves it into
	 * sc_agent_kb_entries as a 'learned' row (Service_Crew_Agent_KB::learn())
	 * so the same question auto-answers next time.
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function agent_escalations_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_agent_escalations';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			question text,
			status varchar(10) NOT NULL DEFAULT 'pending',
			answer text,
			answered_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY session_id (session_id)
		) {$charset_collate};";
	}

	/**
	 * sc_agent_push_subscriptions — one row per admin browser/device that
	 * enabled push alerts for the Agent screen's "Pending escalations" card
	 * (Phase D — class-service-crew-agent-push.php). `endpoint`/`p256dh`/
	 * `auth` are exactly the three fields a browser's
	 * `PushSubscription.toJSON()` exposes — no encryption keys of our own
	 * are stored, since this sends empty-payload pushes only (no RFC8291
	 * payload encryption).
	 *
	 * @param wpdb   $wpdb             WordPress database access object.
	 * @param string $charset_collate  Charset/collation clause from $wpdb->get_charset_collate().
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	private static function agent_push_subscriptions_sql( $wpdb, $charset_collate ) {
		$table_name = $wpdb->prefix . 'sc_agent_push_subscriptions';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			endpoint text NOT NULL,
			p256dh varchar(255) NOT NULL DEFAULT '',
			auth varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) {$charset_collate};";
	}
}
