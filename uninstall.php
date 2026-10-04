<?php
/**
 * Fired only when a user explicitly deletes the plugin from wp-admin (never
 * on a plain deactivate — see class-service-crew-deactivator.php's own
 * docblock) — WordPress calls this file automatically by its fixed name/
 * location in the plugin root, no register_uninstall_hook() needed.
 *
 * Drops every ServiceCrew-created table, deletes every sc_service/sc_crew
 * post (and their meta/attachments), every plugin option, and the
 * sc_crew_member role added on activation. This list is the authoritative
 * one — cross-checked directly against class-service-crew-activator.php's
 * own table-creation calls and every `const *OPTION*` in the codebase
 * (grepped, not guessed) at the time this file was written; if a future
 * change adds a new table/option, add it here in the same commit.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly, or if this wasn't triggered by a real uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	'sc_bookings',
	'sc_booking_components',
	'sc_booking_services',
	'sc_booking_assignments',
	'sc_payments',
	'sc_refunds',
	'sc_vendor_payments',
	'sc_closed_dates',
	'sc_overtime',
	'sc_notes',
	'sc_customers',
	'sc_notifications',
	'sc_agent_sessions',
	'sc_agent_messages',
	'sc_agent_kb_entries',
	'sc_agent_escalations',
	'sc_agent_push_subscriptions',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is one of this plugin's own fixed internal constants above, never user input.
}

// sc_crew / sc_service posts, their postmeta, and any attached media
// (crew photos, service images) — wp_delete_post()'s own $force_delete=true
// handles all three plus any taxonomy relationships in one call.
$post_ids = get_posts(
	array(
		'post_type'      => array( 'sc_crew', 'sc_service' ),
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
	)
);

foreach ( $post_ids as $post_id ) {
	wp_delete_post( $post_id, true );
}

$options = array(
	'sc_db_version',
	'sc_scheduling_settings',
	'sc_payment_settings',
	'sc_email_settings',
	'sc_smtp_settings',
	'sc_widget_colors',
	'sc_advance_discount_tiers',
	'sc_agent_settings',
	'sc_agent_vapid_keys',
	'sc_wizard_state',
	'sc_general_settings',
	'sc_geocoding_last_request',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

remove_role( 'sc_crew_member' );
