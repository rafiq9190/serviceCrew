<?php
/**
 * Crew assignment for a booking: who's on the job and who leads it.
 *
 * **V1 launch scope** (see ServiceCrew-Plan-v2.md's "V1 launch scope"
 * section): employee-only, final immediately, no accept/decline. The
 * original plan's `sc_booking_assignments.status` enum (proposed / accepted
 * / declined / cancelled_by_crew) assumed a PWA accept step that V1 doesn't
 * have — admin confirms availability off-system (phone/text) before
 * assigning, so every row this class writes is born already
 * `STATUS_ACCEPTED`, never `proposed`. `STATUS_REMOVED` is a new value for
 * this scope (not in the original enum): it's the admin taking someone off
 * a job, not the crew member's own decline/cancel action, which doesn't
 * exist here. Vendor assignment (agreed_amount, PWA accept) is deferred
 * entirely — assign_crew() hard-rejects a non-employee crew id.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Assignments {

	const ROLE_LEAD   = 'lead';
	const ROLE_MEMBER = 'member';

	/**
	 * V1's only "active" status — see this class's docblock for why there's
	 * no `proposed` step.
	 *
	 * @var string
	 */
	const STATUS_ACCEPTED = 'accepted';

	/**
	 * An admin took this person off the job (swapped out, job cancelled,
	 * etc.) — V1's stand-in for the original plan's crew-initiated decline/
	 * cancel statuses, which don't apply without a PWA. The reason (if any)
	 * is stored in the existing `decline_reason` column, reused for its
	 * "why this assignment isn't active" meaning regardless of who ended it.
	 *
	 * @var string
	 */
	const STATUS_REMOVED = 'removed';

	/**
	 * How long past a job's scheduled date the lead's status-link token stays
	 * valid — tied to the job's own date rather than a fixed duration from
	 * when the assignment email was sent (see generate_status_token()),
	 * since the link needs to survive until the job is actually done, which
	 * could be booked weeks out.
	 *
	 * @var int
	 */
	const STATUS_TOKEN_EXPIRY_BUFFER_DAYS = 14;

	/**
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_booking_assignments';
	}

	/**
	 * Sets a booking's full crew roster in one call: whoever is currently
	 * active but not in $crew_ids is marked STATUS_REMOVED, everyone in
	 * $crew_ids is (re-)written as STATUS_ACCEPTED with the right role, and
	 * the booking itself moves to `assigned` — immediately, per this V1
	 * scope's "no accept/decline" rule. Safe to call again later to change
	 * the team (e.g. swapping out someone who turned out unavailable); the
	 * dispatch board is expected to always submit the full desired roster,
	 * not an incremental diff.
	 *
	 * @param int      $booking_id   Booking id.
	 * @param int[]    $crew_ids     Every crew member on the job (employee-type only).
	 * @param int      $lead_crew_id Must be one of $crew_ids.
	 * @param string   $reason       Optional note for anyone this call removes from the roster.
	 * @return true|WP_Error
	 */
	public static function assign_crew( $booking_id, array $crew_ids, $lead_crew_id, $reason = '' ) {
		$booking_id   = absint( $booking_id );
		$crew_ids     = array_values( array_unique( array_map( 'absint', $crew_ids ) ) );
		$lead_crew_id = absint( $lead_crew_id );

		if ( empty( $crew_ids ) ) {
			return new WP_Error( 'sc_assignment_crew_required', __( 'Please choose at least one crew member.', 'service-crew' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $lead_crew_id, $crew_ids, true ) ) {
			return new WP_Error( 'sc_assignment_lead_required', __( 'The lead must be one of the assigned crew members.', 'service-crew' ), array( 'status' => 400 ) );
		}

		foreach ( $crew_ids as $crew_id ) {
			$type = get_post_meta( $crew_id, Service_Crew_Crew::META_TYPE, true );
			if ( 'employee' !== $type ) {
				return new WP_Error( 'sc_assignment_employee_only', __( 'Only employees can be assigned — vendor assignment isn’t available in this version.', 'service-crew' ), array( 'status' => 400 ) );
			}
		}

		global $wpdb;

		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT status, preferred_date FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', $booking_id ) );
		if ( ! $booking ) {
			return new WP_Error( 'sc_booking_not_found', __( 'Booking not found.', 'service-crew' ), array( 'status' => 404 ) );
		}

		$booking_status = $booking->status;

		if ( in_array( $booking_status, array( Service_Crew_Bookings::STATUS_CANCELLED, Service_Crew_Bookings::STATUS_COMPLETED ), true ) ) {
			return new WP_Error( 'sc_booking_not_assignable', __( 'This booking can no longer be assigned.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$table    = self::table();
		$existing = self::get_active_assignments( $booking_id );

		foreach ( $existing as $crew_id => $row ) {
			if ( in_array( $crew_id, $crew_ids, true ) ) {
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'status'         => self::STATUS_REMOVED,
					'decline_reason' => sanitize_text_field( $reason ),
					'responded_at'   => current_time( 'mysql', true ),
				),
				array( 'id' => $row->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		foreach ( $crew_ids as $crew_id ) {
			$role = $crew_id === $lead_crew_id ? self::ROLE_LEAD : self::ROLE_MEMBER;

			if ( isset( $existing[ $crew_id ] ) ) {
				$wpdb->update(
					$table,
					array(
						'role'   => $role,
						'status' => self::STATUS_ACCEPTED,
					),
					array( 'id' => $existing[ $crew_id ]->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'booking_id' => $booking_id,
					'crew_id'    => $crew_id,
					'role'       => $role,
					'status'     => self::STATUS_ACCEPTED,
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}

		$wpdb->update(
			$wpdb->prefix . 'sc_bookings',
			array( 'status' => Service_Crew_Bookings::STATUS_ASSIGNED ),
			array( 'id' => $booking_id ),
			array( '%s' ),
			array( '%d' )
		);

		// The lead's own row id (not the crew_id) is what the status token is
		// keyed to — re-fetched post-upsert rather than tracked through the
		// loop above, since an existing lead's row id came from $existing and
		// a newly-promoted one came from $wpdb->insert_id, two different
		// sources it's simpler to just look up fresh.
		$lead_assignment    = self::get_active_assignments( $booking_id )[ $lead_crew_id ] ?? null;
		$lead_status_token  = $lead_assignment ? self::generate_status_token( $lead_assignment->id, $booking->preferred_date ) : '';
		$lead_status_token  = is_wp_error( $lead_status_token ) ? '' : $lead_status_token;

		/**
		 * Fires once a booking's crew roster is finalized — immediately, per
		 * this V1 scope's "no accept/decline" rule. Service_Crew_Emails
		 * listens to send the customer's "Assigned" email and every crew
		 * member's job-details email (the lead's additionally including the
		 * no-login status link this plaintext token builds — it can't be
		 * recovered again after this one firing, same as every other token
		 * in this plugin).
		 *
		 * @param int    $booking_id        Booking id.
		 * @param int[]  $crew_ids          Every crew member now on the job.
		 * @param int    $lead_crew_id      The lead.
		 * @param string $lead_status_token Plaintext status-link token for the lead, or '' if it couldn't be generated.
		 */
		do_action( 'sc_booking_assigned', $booking_id, $crew_ids, $lead_crew_id, $lead_status_token );

		return true;
	}

	/**
	 * Every currently-active (STATUS_ACCEPTED) assignment for a booking,
	 * keyed by crew_id for O(1) lookup during assign_crew()'s diff.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<int,object>
	 */
	public static function get_active_assignments( $booking_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE booking_id = %d AND status = %s',
				absint( $booking_id ),
				self::STATUS_ACCEPTED
			)
		);

		$by_crew_id = array();
		foreach ( $rows as $row ) {
			$by_crew_id[ (int) $row->crew_id ] = $row;
		}

		return $by_crew_id;
	}

	/**
	 * The active lead's crew_id for a booking, or null if unassigned.
	 *
	 * @param int $booking_id Booking id.
	 * @return int|null
	 */
	public static function get_lead_crew_id( $booking_id ) {
		foreach ( self::get_active_assignments( $booking_id ) as $crew_id => $row ) {
			if ( self::ROLE_LEAD === $row->role ) {
				return $crew_id;
			}
		}

		return null;
	}

	/**
	 * Marks every active assignment on a booking as STATUS_REMOVED in one
	 * query — used when a booking is cancelled (the plan's "job leaves the
	 * crew list" for the "Customer cancels" failure-table row). Doesn't
	 * touch the booking's own status; callers decide that separately.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $reason     Reason logged on each removed row's decline_reason.
	 * @return void
	 */
	public static function release_all( $booking_id, $reason = '' ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'         => self::STATUS_REMOVED,
				'decline_reason' => sanitize_text_field( $reason ),
				'responded_at'   => current_time( 'mysql', true ),
			),
			array(
				'booking_id' => absint( $booking_id ),
				'status'     => self::STATUS_ACCEPTED,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Generates the lead's no-login job-status token — single-use in the
	 * sense that the plaintext is returned once and only its SHA-256 hash is
	 * stored, same "tokens are stored hashed... and are single-purpose" rule
	 * as Service_Crew_Payments' pay tokens. Called from assign_crew() every
	 * time the lead is (re-)set, so the link in the most recently sent
	 * assignment email is always the one that still works.
	 *
	 * @param int    $assignment_id  sc_booking_assignments row id.
	 * @param string $preferred_date Booking's scheduled date (Y-m-d), or '' if unknown.
	 * @return string|WP_Error Plaintext token, or WP_Error on failure.
	 */
	public static function generate_status_token( $assignment_id, $preferred_date ) {
		$assignment_id = absint( $assignment_id );
		$token         = wp_generate_password( 32, false, false );

		$expires_at = $preferred_date
			? gmdate( 'Y-m-d H:i:s', strtotime( $preferred_date . ' +' . self::STATUS_TOKEN_EXPIRY_BUFFER_DAYS . ' days' ) )
			: gmdate( 'Y-m-d H:i:s', time() + ( self::STATUS_TOKEN_EXPIRY_BUFFER_DAYS * DAY_IN_SECONDS ) );

		global $wpdb;
		$updated = $wpdb->update(
			self::table(),
			array(
				'status_token_hash'       => hash( 'sha256', $token ),
				'status_token_expires_at' => $expires_at,
			),
			array( 'id' => $assignment_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'sc_status_token_failed', __( 'Could not create a status link for this assignment.', 'service-crew' ) );
		}

		return $token;
	}

	/**
	 * Looks up the active assignment a plaintext job-status token resolves
	 * to. Returns null for an unknown/expired token or one whose assignment
	 * is no longer STATUS_ACCEPTED (e.g. the lead was swapped out) — callers
	 * must not distinguish these cases in any response, same "don't help
	 * enumerate valid tokens" rule as Service_Crew_Payments::find_payment_by_token().
	 *
	 * @param string $token Plaintext token from the status-link URL.
	 * @return object|null
	 */
	public static function find_active_assignment_by_status_token( $token ) {
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return null;
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE status_token_hash = %s AND status = %s',
				hash( 'sha256', $token ),
				self::STATUS_ACCEPTED
			)
		);

		if ( ! $row || ! $row->status_token_expires_at || strtotime( $row->status_token_expires_at ) < time() ) {
			return null;
		}

		return $row;
	}
}
