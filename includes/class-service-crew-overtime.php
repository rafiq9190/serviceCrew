<?php
/**
 * Per-assignment overtime: case-by-case admin approval — the plan's
 * "Overtime is approved case by case on the board... tracked in hours per
 * employee and per job, and counts toward capacity only once approved" —
 * plus the admin-only, never-customer-facing surcharge decision for it
 * ("applied, custom, or waived per job... never shown to the customer and
 * no email is sent automatically").
 *
 * No crew-initiated request step exists here, same "admin acts directly, no
 * accept/decline" model as class-service-crew-assignments.php under V1's
 * launch scope — there is no PWA for an employee to request overtime
 * through, so the admin enters and approves it in one action.
 *
 * **Known gap:** approved hours aren't fed back into
 * Service_Crew_Capacity::calculate_pooled_hours()'s $approved_overtime_hours
 * parameter yet — that class is still a pure-calc layer nothing wires to
 * live crew/booking data for a real dispatch-board capacity view (see its
 * own docblock). The plan's "collect the surcharge by payment link or mark
 * it collected" isn't wired to a payment record here either — the admin
 * records the decision/amount for their own bookkeeping; actually collecting
 * it is a separate follow-up (the existing balance-collection tools work off
 * customer_total/amount_paid, not an ad-hoc extra charge).
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Overtime {

	/**
	 * The only status this class writes — there's no pending/rejected state
	 * since there's no request step to approve or decline (see class docblock).
	 *
	 * @var string
	 */
	const STATUS_APPROVED = 'approved';

	const SURCHARGE_NONE    = 'none';
	const SURCHARGE_APPLIED = 'applied';
	const SURCHARGE_CUSTOM  = 'custom';
	const SURCHARGE_WAIVED  = 'waived';

	/**
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_overtime';
	}

	/**
	 * Admin approves overtime hours for a specific assignment, with an
	 * admin-only surcharge decision. Requires
	 * Service_Crew_Settings' `overtime.allowed` setting to be on, and caps
	 * hours at `overtime.max_hours_per_day` (0 on that setting means no cap).
	 *
	 * @param int    $assignment_id      sc_booking_assignments row id.
	 * @param float  $hours              Approved overtime hours, > 0.
	 * @param string $surcharge_decision One of SURCHARGE_NONE/APPLIED/CUSTOM/WAIVED; anything else falls back to SURCHARGE_NONE.
	 * @param float  $surcharge_amount   Only kept for APPLIED/CUSTOM; forced to 0 otherwise.
	 * @param string $note               Optional admin note.
	 * @return array{id:int,surcharge_amount:float}|WP_Error
	 */
	public static function approve( $assignment_id, $hours, $surcharge_decision, $surcharge_amount, $note = '' ) {
		$assignment_id = absint( $assignment_id );
		$hours         = round( (float) $hours, 2 );

		if ( $hours <= 0 ) {
			return new WP_Error( 'sc_overtime_invalid_hours', __( 'Please enter a positive number of hours.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$overtime_settings = Service_Crew_Settings::get_saved_settings()['overtime'];

		if ( empty( $overtime_settings['allowed'] ) ) {
			return new WP_Error( 'sc_overtime_not_allowed', __( 'Overtime is turned off in Settings.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$max_hours = (float) ( $overtime_settings['max_hours_per_day'] ?? 0 );
		if ( $max_hours > 0 && $hours > $max_hours ) {
			return new WP_Error(
				'sc_overtime_exceeds_max',
				sprintf(
					/* translators: %s: max overtime hours per day. */
					__( 'Overtime can\'t exceed %s hours/day per the Settings screen.', 'service-crew' ),
					$max_hours
				),
				array( 'status' => 400 )
			);
		}

		$valid_decisions    = array( self::SURCHARGE_NONE, self::SURCHARGE_APPLIED, self::SURCHARGE_CUSTOM, self::SURCHARGE_WAIVED );
		$surcharge_decision = in_array( $surcharge_decision, $valid_decisions, true ) ? $surcharge_decision : self::SURCHARGE_NONE;
		$surcharge_amount   = in_array( $surcharge_decision, array( self::SURCHARGE_APPLIED, self::SURCHARGE_CUSTOM ), true )
			? round( max( 0.0, (float) $surcharge_amount ), 2 )
			: 0.0;

		global $wpdb;

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'assignment_id'      => $assignment_id,
				'hours'              => $hours,
				'status'             => self::STATUS_APPROVED,
				'approved_by'        => get_current_user_id(),
				'approved_at'        => current_time( 'mysql', true ),
				'surcharge_decision' => $surcharge_decision,
				'surcharge_amount'   => $surcharge_amount,
				'note'               => sanitize_textarea_field( $note ),
			),
			array( '%d', '%f', '%s', '%d', '%s', '%s', '%f', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'sc_overtime_insert_failed', __( 'Could not save overtime for this assignment.', 'service-crew' ) );
		}

		return array(
			'id'               => (int) $wpdb->insert_id,
			'surcharge_amount' => $surcharge_amount,
		);
	}

	/**
	 * Every overtime row for a booking (joined through its assignments),
	 * newest first — for the admin UI's "overtime already recorded" list.
	 *
	 * @param int $booking_id Booking id.
	 * @return object[]
	 */
	public static function get_for_booking( $booking_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT o.*, a.crew_id FROM ' . self::table() . ' o
				INNER JOIN ' . $wpdb->prefix . 'sc_booking_assignments a ON a.id = o.assignment_id
				WHERE a.booking_id = %d
				ORDER BY o.created_at DESC',
				absint( $booking_id )
			)
		);
	}
}
