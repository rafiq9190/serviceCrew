<?php
/**
 * Pure-calculation helpers for a single crew member's weekly availability
 * template and time off. No side effects, no hooks — everything here takes
 * the meta arrays already shaped by Service_Crew_Crew (weekly shifts,
 * time-off ranges) and answers "how many hours / is this person available
 * on date X", so Phase 1b-2's capacity class can sum this per employee per
 * date without re-deriving shift math itself.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static-only by design: every method is a pure function of its arguments
 * (weekly template, time-off list, a date string), matching the plan's
 * "pure calc" description for this task so the math is testable without a
 * full WordPress bootstrap. get_available_hours_for_crew() is the only
 * method that touches post meta, kept separate so callers who already have
 * the arrays (e.g. a batch capacity calculation) never pay for repeated
 * get_post_meta() calls.
 */
class Service_Crew_Availability {

	/**
	 * Resolves the Service_Crew_Crew::WEEKDAYS key for a given date.
	 *
	 * PHP's date('N') returns 1 (Monday) through 7 (Sunday), which lines up
	 * directly with WEEKDAYS' Monday-first order once shifted down by one.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return string Weekday key, e.g. 'monday'.
	 */
	public static function get_weekday_key( $date ) {
		$timestamp     = strtotime( $date );
		$weekday_index = (int) gmdate( 'N', $timestamp ) - 1;

		return Service_Crew_Crew::WEEKDAYS[ $weekday_index ];
	}

	/**
	 * Whether a date falls inside any time-off range.
	 *
	 * @param array<int,array<string,string>> $time_off Time-off rows (start/end/reason), as saved by Service_Crew_Crew.
	 * @param string                           $date     Date in Y-m-d format.
	 * @return bool
	 */
	public static function is_on_time_off( array $time_off, $date ) {
		foreach ( $time_off as $entry ) {
			if ( ! isset( $entry['start'], $entry['end'] ) ) {
				continue;
			}

			if ( $date >= $entry['start'] && $date <= $entry['end'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the non-empty shifts configured for a weekday, dropping any
	 * shift whose start/end is blank or whose end doesn't come after its
	 * start.
	 *
	 * @param array<string,array<string,string>> $availability Weekly template, as saved by Service_Crew_Crew.
	 * @param string                              $weekday_key  A Service_Crew_Crew::WEEKDAYS key.
	 * @return array<int,array{start:string,end:string}>
	 */
	public static function get_shifts_for_weekday( array $availability, $weekday_key ) {
		if ( ! isset( $availability[ $weekday_key ] ) || ! is_array( $availability[ $weekday_key ] ) ) {
			return array();
		}

		$day    = $availability[ $weekday_key ];
		$shifts = array();

		foreach ( array( 1, 2 ) as $shift_number ) {
			$start = $day[ "shift{$shift_number}_start" ] ?? '';
			$end   = $day[ "shift{$shift_number}_end" ] ?? '';

			if ( '' === $start || '' === $end ) {
				continue;
			}

			if ( self::minutes_since_midnight( $end ) <= self::minutes_since_midnight( $start ) ) {
				continue;
			}

			$shifts[] = array(
				'start' => $start,
				'end'   => $end,
			);
		}

		return $shifts;
	}

	/**
	 * Total available hours for a specific date: 0 if the date falls in
	 * time off, otherwise the sum of that weekday's shift durations.
	 *
	 * @param array<string,array<string,string>> $availability Weekly template, as saved by Service_Crew_Crew.
	 * @param array<int,array<string,string>>    $time_off     Time-off rows, as saved by Service_Crew_Crew.
	 * @param string                              $date         Date in Y-m-d format.
	 * @return float
	 */
	public static function get_available_hours( array $availability, array $time_off, $date ) {
		if ( self::is_on_time_off( $time_off, $date ) ) {
			return 0.0;
		}

		$weekday_key = self::get_weekday_key( $date );
		$shifts      = self::get_shifts_for_weekday( $availability, $weekday_key );
		$hours       = 0.0;

		foreach ( $shifts as $shift ) {
			$hours += ( self::minutes_since_midnight( $shift['end'] ) - self::minutes_since_midnight( $shift['start'] ) ) / 60;
		}

		return $hours;
	}

	/**
	 * Whether the crew member has any working hours at all on a date.
	 *
	 * @param array<string,array<string,string>> $availability Weekly template, as saved by Service_Crew_Crew.
	 * @param array<int,array<string,string>>    $time_off     Time-off rows, as saved by Service_Crew_Crew.
	 * @param string                              $date         Date in Y-m-d format.
	 * @return bool
	 */
	public static function is_available_on( array $availability, array $time_off, $date ) {
		return self::get_available_hours( $availability, $time_off, $date ) > 0;
	}

	/**
	 * Convenience wrapper that loads a crew member's weekly template and
	 * time off from post meta and returns their available hours for a date.
	 * Kept separate from get_available_hours() so batch callers (e.g. a
	 * future capacity calculation summing many crew members for one date)
	 * can fetch meta once per crew member instead of once per call.
	 *
	 * @param int    $crew_id Crew post ID.
	 * @param string $date    Date in Y-m-d format.
	 * @return float
	 */
	public static function get_available_hours_for_crew( $crew_id, $date ) {
		$availability = get_post_meta( $crew_id, Service_Crew_Crew::META_AVAILABILITY, true );
		$time_off     = get_post_meta( $crew_id, Service_Crew_Crew::META_TIME_OFF, true );

		return self::get_available_hours(
			is_array( $availability ) ? $availability : array(),
			is_array( $time_off ) ? $time_off : array(),
			$date
		);
	}

	/**
	 * Converts an HH:MM string to minutes since midnight.
	 *
	 * @param string $time HH:MM value, as saved by Service_Crew_Crew::sanitize_time().
	 * @return int
	 */
	private static function minutes_since_midnight( $time ) {
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) );

		return ( $hours * 60 ) + $minutes;
	}
}
