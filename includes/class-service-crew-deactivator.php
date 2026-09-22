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
 * that is uninstall.php's job (out of scope for this task), and only runs
 * when the user explicitly deletes the plugin. This class only does safe
 * housekeeping.
 */
class Service_Crew_Deactivator {

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		/*
		 * No ServiceCrew cron hooks exist yet as of Phase 1a. Future phases
		 * introduce them (e.g. the awaiting_payment expiry job in Phase
		 * 1b-2, quote-expiry / no-response timers in Phase 1c's
		 * class-service-crew-cron.php, the stale-subscription sweep in
		 * Phase 1d). When those land, clear each one here with
		 * wp_clear_scheduled_hook( 'hook_name' ) so a deactivated plugin
		 * never leaves a dangling scheduled event behind.
		 */

		flush_rewrite_rules();
	}
}
