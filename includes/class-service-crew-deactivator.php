<?php
/**
 * Fired during plugin deactivation.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin deactivation.
 *
 * Deactivation is reversible, so it must NOT delete data or drop tables —
 * that is uninstall.php's job (plugin root — WordPress calls it
 * automatically by its fixed name/location), and only runs when the user
 * explicitly deletes the plugin. This class only does safe housekeeping.
 */
class Service_Crew_Deactivator {

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Service_Crew_Bookings::CRON_HOOK );
		wp_clear_scheduled_hook( Service_Crew_Cron::CRON_HOOK );

		// A future stale-subscription sweep (Phase 1d's crew PWA, now
		// deferred — see ServiceCrew-Plan-v2.md's "V1 launch scope") will
		// need its own wp_clear_scheduled_hook() call added here too.

		flush_rewrite_rules();
	}
}
