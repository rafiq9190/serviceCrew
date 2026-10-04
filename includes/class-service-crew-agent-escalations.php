<?php
/**
 * `sc_agent_escalations` data access — a visitor question
 * Service_Crew_Agent_Matcher couldn't confidently (or even plausibly)
 * answer, saved for an admin to answer from the Agent screen's "Pending
 * escalations" card. Created from Service_Crew_Agent::handle_message()'s own
 * DECISION_ESCALATE branch via a direct call, not a hook — so, unlike
 * class-service-crew-agent-kb.php (which now registers save_post hooks of
 * its own), this class has no WordPress hooks and is never instantiated by
 * the bootstrap.
 *
 * Answering one does three things in one call: logs the answer into the
 * originating session's own transcript (so it shows up the next time
 * GET /agent/history is read — including the widget's resume-on-return
 * sync), learns it into the KB via Service_Crew_Agent_KB::learn() (already
 * built, forces source = 'learned') so the same question auto-answers next
 * time, and — only if the visitor left an email during the escalation's
 * optional "email me the answer" prompt — fires
 * sc_agent_escalation_answered for class-service-crew-emails.php to send a
 * follow-up.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent_Escalations {

	/**
	 * Status values.
	 *
	 * @var string
	 */
	const STATUS_PENDING  = 'pending';
	const STATUS_ANSWERED = 'answered';

	/**
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_agent_escalations';
	}

	/**
	 * Creates a pending escalation and fires sc_agent_escalation_created —
	 * Service_Crew_Emails sends the admin alert and
	 * Service_Crew_Notifications' bell count picks it up by querying
	 * count_pending() directly, not by listening to this action (see that
	 * class's own docblock for why).
	 *
	 * @param int    $session_id Session id.
	 * @param string $question   Visitor's original message text.
	 * @return int New escalation id.
	 */
	public static function create( $session_id, $question ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'session_id' => absint( $session_id ),
				'question'   => $question,
				'status'     => self::STATUS_PENDING,
			),
			array( '%d', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		do_action( 'sc_agent_escalation_created', $id );

		return $id;
	}

	/**
	 * @param int $id Escalation id.
	 * @return object|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ) );
	}

	/**
	 * Every pending escalation, oldest first — the admin inbox's queue order
	 * (oldest unanswered question surfaces first).
	 *
	 * @return object[]
	 */
	public static function get_pending() {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY created_at ASC', self::STATUS_PENDING )
		);
	}

	/**
	 * For Service_Crew_Notifications' bell-count merge.
	 *
	 * @return int
	 */
	public static function count_pending() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', self::STATUS_PENDING )
		);
	}

	/**
	 * Resolves a token's session, verifying it actually owns the given
	 * escalation — used by the public /agent/escalation-status route so one
	 * visitor's session token can never be used to read another session's
	 * answer.
	 *
	 * @param int $id         Escalation id.
	 * @param int $session_id Session id the caller's token resolved to.
	 * @return object|null The escalation row, only if it belongs to $session_id.
	 */
	public static function get_for_session( $id, $session_id ) {
		$escalation = self::get_by_id( $id );

		if ( ! $escalation || (int) $escalation->session_id !== (int) $session_id ) {
			return null;
		}

		return $escalation;
	}

	/**
	 * Answers a pending escalation.
	 *
	 * @param int    $id          Escalation id.
	 * @param string $answer_text Admin's answer.
	 * @return bool
	 */
	public static function answer( $id, $answer_text ) {
		global $wpdb;

		$escalation = self::get_by_id( $id );

		if ( ! $escalation || self::STATUS_ANSWERED === $escalation->status ) {
			return false;
		}

		$updated = $wpdb->update(
			self::table(),
			array(
				'status'      => self::STATUS_ANSWERED,
				'answer'      => $answer_text,
				'answered_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		Service_Crew_Agent::log_message( (int) $escalation->session_id, Service_Crew_Agent::SENDER_ADMIN, $answer_text );
		Service_Crew_Agent_KB::learn( $escalation->question, $answer_text );

		$session = Service_Crew_Agent::find_session_by_id( (int) $escalation->session_id );
		if ( $session && ! empty( $session->visitor_email ) && is_email( $session->visitor_email ) ) {
			do_action( 'sc_agent_escalation_answered', (int) $id );
		}

		return true;
	}
}
