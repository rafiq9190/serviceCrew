<?php
/**
 * WP-Cron sweep for the plan's quote-expiry timer: an admin reminder before
 * a priced quote's `quote_expires_at` passes, then flipping it to
 * `quote_expired` once it does.
 *
 * Deliberately narrower than the plan's full "No response, quote expiring,
 * accepted-not-scheduled" timer trio (see the Emails table in
 * ServiceCrew-Plan-v2.md):
 *  - The crew **no-response timer doesn't apply under V1's launch scope**
 *    (see that doc's "V1 launch scope" section): there is no PWA accept step
 *    and no `proposed` assignment state to time out —
 *    Service_Crew_Assignments::assign_crew() writes every row as `accepted`
 *    immediately, final.
 *  - The **"accepted but unscheduled" reminder has no trigger point yet**:
 *    the built quote flow has no "customer accepted the price" signal
 *    anywhere — a quote is agreed to outside the system (phone/text) and the
 *    admin generates the deposit link whenever they're ready, with no
 *    separate "accepted" step recorded in between. Service_Crew_Settings'
 *    `timers.quote_reminder_days` and `sc_bookings.accepted_unscheduled_at`
 *    both stay unused until a future feature actually captures that moment.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Cron {

	/**
	 * WP-Cron hook name for the quote-timer sweep.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'sc_quote_timers_sweep';

	/**
	 * How long before `quote_expires_at` the admin reminder fires — fixed,
	 * per the plan's own "Assumptions and open items" ("quote-expiry
	 * reminder one day before"), unlike quote_validity_days (admin-set).
	 *
	 * @var int
	 */
	const REMINDER_WINDOW_HOURS = 24;

	/**
	 * Registers the cron hook and self-schedules it if missing — same
	 * "runs on every plugins_loaded, cheap wp_next_scheduled() check" pattern
	 * as Service_Crew_Bookings' awaiting_payment expiry sweep, for the same
	 * reason: it reaches an already-active install immediately, no
	 * deactivate/reactivate required. Service_Crew_Deactivator clears it.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * The sweep itself: reminders before expiry, so a quote that happens to
	 * cross REMINDER_WINDOW_HOURS and quote_expires_at in the same run still
	 * gets its reminder rather than jumping straight to expired.
	 *
	 * @return void
	 */
	public function run() {
		$this->send_expiry_reminders();
		$this->expire_quotes();
	}

	/**
	 * Admin reminder (the shared `timers_admin` template) for every `quoted`
	 * booking within REMINDER_WINDOW_HOURS of its quote_expires_at that
	 * hasn't had one sent yet.
	 *
	 * @return void
	 */
	private function send_expiry_reminders() {
		global $wpdb;

		$reminder_cutoff = gmdate( 'Y-m-d H:i:s', time() + ( self::REMINDER_WINDOW_HOURS * HOUR_IN_SECONDS ) );
		$now             = current_time( 'mysql', true );

		$quotes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, quote_title FROM {$wpdb->prefix}sc_bookings
				WHERE status = %s
				AND quote_expires_at IS NOT NULL
				AND quote_expires_at <= %s
				AND quote_expires_at > %s
				AND quote_reminder_sent_at IS NULL",
				Service_Crew_Quotes::STATUS_QUOTED,
				$reminder_cutoff,
				$now
			)
		);

		foreach ( $quotes as $quote ) {
			Service_Crew_Emails::send_admin_timer_reminder(
				$quote->id,
				sprintf(
					/* translators: %s: quote title. */
					__( 'Quote "%s" expires within a day — follow up, or it will expire automatically.', 'service-crew' ),
					$quote->quote_title ? $quote->quote_title : __( 'Untitled quote', 'service-crew' )
				)
			);

			$wpdb->update(
				$wpdb->prefix . 'sc_bookings',
				array( 'quote_reminder_sent_at' => $now ),
				array( 'id' => $quote->id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Flips every `quoted` booking past its quote_expires_at to
	 * `quote_expired` — the plan's "then it shows expired and can be
	 * reopened with a new date." Reopening itself is the admin re-pricing it
	 * (Service_Crew_Quotes::set_price() doesn't block that status, only
	 * quote_rejected — see admin/js/app-bookings.js's QUOTE_MANAGEABLE_STATUSES),
	 * not a separate action this sweep performs.
	 *
	 * @return void
	 */
	private function expire_quotes() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}sc_bookings SET status = %s
				WHERE status = %s AND quote_expires_at IS NOT NULL AND quote_expires_at <= %s",
				Service_Crew_Quotes::STATUS_EXPIRED,
				Service_Crew_Quotes::STATUS_QUOTED,
				current_time( 'mysql', true )
			)
		);
	}
}
