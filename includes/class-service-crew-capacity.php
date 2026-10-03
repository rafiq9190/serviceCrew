<?php
/**
 * Pure-calculation helpers for pooled employee date capacity — the plan's
 * "Scheduling and capacity" rules: employee capacity per date, load per
 * date, the per-job multi-day date check, and overtime's effect on capacity.
 *
 * Every method here is a pure function of its arguments (no $wpdb, no
 * get_post_meta()), same "pure calc, unit-testable" contract as
 * class-service-crew-pricing.php and the per-employee half of
 * class-service-crew-availability.php, so this can be tested without a full
 * WordPress bootstrap. Fetching the actual employee records and bookings
 * that cover a date is left to whatever calls this (the not-yet-built
 * `available-dates` REST controller / class-service-crew-matching.php) —
 * this class only does the arithmetic once that data is in hand.
 *
 * Deliberately not wired into class-service-crew-bookings.php yet: today's
 * create_instant_booking() still only checks "is this exact date+window slot
 * already taken" (see Service_Crew_Bookings::get_booked_windows()), not real
 * pooled capacity — that wiring, plus the `available-dates` endpoint and
 * class-service-crew-matching.php's suggestions, are separate, still
 * not-started Phase 1b-2 rows.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Capacity {

	/**
	 * Safety cap on how many calendar days get_job_date_span() will scan
	 * looking for enough business days — guards against an infinite loop if
	 * an admin's business_hours somehow has every weekday disabled.
	 *
	 * @var int
	 */
	const MAX_SCAN_DAYS = 3650;

	/**
	 * Employee capacity for one date: the plan's "sum of working hours of
	 * each available employee that day (own weekly template minus time
	 * off)", plus any already-approved overtime hours for that date.
	 * Vendors are never counted here — the plan's "vendors are not counted;
	 * they are the overflow" — so $employees must already be employee-only,
	 * filtered by the caller.
	 *
	 * @param array<int,array{availability:array<string,array<string,string>>,time_off:array<int,array<string,string>>}> $employees Each employee's weekly template + time off, as saved by Service_Crew_Crew.
	 * @param string $date                     Date in Y-m-d format.
	 * @param float  $approved_overtime_hours  Total approved overtime hours across all employees for this date (0 if overtime is off or none has been approved yet). Counts toward capacity only once approved, per the plan.
	 * @return float
	 */
	public static function calculate_pooled_hours( array $employees, $date, $approved_overtime_hours = 0.0 ) {
		$hours = 0.0;

		foreach ( $employees as $employee ) {
			$hours += Service_Crew_Availability::get_available_hours(
				is_array( $employee['availability'] ?? null ) ? $employee['availability'] : array(),
				is_array( $employee['time_off'] ?? null ) ? $employee['time_off'] : array(),
				$date
			);
		}

		return round( $hours + max( 0.0, (float) $approved_overtime_hours ), 2 );
	}

	/**
	 * Load for one date: the plan's "sum over bookings covering that date of
	 * (job hours that day × crew needed) plus the travel buffer per
	 * job-person." Bookings need no assigned person to count — the caller
	 * passes every job (instant booking or paid/scheduled quote) whose date
	 * span includes this date, assigned or not.
	 *
	 * @param array<int,array{hours_per_day:float,crew_needed:int}> $jobs Jobs covering this date.
	 * @param int $travel_buffer_minutes Fixed travel buffer between an employee's jobs, from Service_Crew_Settings.
	 * @return float
	 */
	public static function calculate_load( array $jobs, $travel_buffer_minutes ) {
		$travel_buffer_hours = max( 0, (int) $travel_buffer_minutes ) / 60;
		$load                = 0.0;

		foreach ( $jobs as $job ) {
			$crew_needed   = max( 1, (int) ( $job['crew_needed'] ?? 1 ) );
			$hours_per_day = max( 0.0, (float) ( $job['hours_per_day'] ?? 0 ) );

			$load += ( $hours_per_day * $crew_needed ) + ( $travel_buffer_hours * $crew_needed );
		}

		return round( $load, 2 );
	}

	/**
	 * The consecutive business days a multi-day job occupies starting from
	 * $start_date — weekends/days Service_Crew_Settings' business_hours
	 * marks disabled, and admin holidays, are skipped, per the plan's
	 * "multi-day jobs run on consecutive business days from the chosen date
	 * (weekends and holidays skipped), same window each day." $start_date
	 * itself is not re-validated as a business day — the caller (the booking
	 * flow) only ever offers business days to pick from in the first place.
	 *
	 * @param string            $start_date    Date in Y-m-d format.
	 * @param int               $duration_days How many business days the job needs.
	 * @param array<string,array{enabled:bool}> $business_hours Weekly business-hours template, as saved by Service_Crew_Settings.
	 * @param array<int,array{date:string,label:string}> $holidays Holiday rows, as saved by Service_Crew_Settings (its `holidays` key) — only the `date` field is used.
	 * @return string[] Exactly $duration_days dates, in order (fewer only if MAX_SCAN_DAYS is exhausted).
	 */
	public static function get_job_date_span( $start_date, $duration_days, array $business_hours, array $holidays ) {
		$duration_days    = max( 1, (int) $duration_days );
		$holidays_flipped = array_flip( array_filter( array_column( $holidays, 'date' ) ) );
		$dates            = array();
		$cursor           = $start_date;

		for ( $scanned = 0; $scanned < self::MAX_SCAN_DAYS && count( $dates ) < $duration_days; $scanned++ ) {
			if ( self::is_business_day( $cursor, $business_hours, $holidays_flipped ) ) {
				$dates[] = $cursor;
			}

			$cursor = gmdate( 'Y-m-d', strtotime( $cursor . ' +1 day' ) );
		}

		return $dates;
	}

	/**
	 * Whether every day a job would cover has enough capacity left to fit
	 * it — the plan's own per-job date check: "a start date is disabled when
	 * any day the job would cover cannot fit the job's per-day load."
	 *
	 * @param string[]             $date_span            Dates from get_job_date_span().
	 * @param array<string,float>  $pooled_hours_by_date  Pooled employee hours (calculate_pooled_hours()'s result), keyed by date.
	 * @param array<string,float>  $existing_load_by_date Load already on the books (calculate_load()'s result, before this job), keyed by date.
	 * @param float                $hours_per_day         This job's own hours per day.
	 * @param int                  $crew_needed           This job's crew needed.
	 * @param int                  $travel_buffer_minutes Fixed travel buffer, from Service_Crew_Settings.
	 * @return bool
	 */
	public static function can_fit_job( array $date_span, array $pooled_hours_by_date, array $existing_load_by_date, $hours_per_day, $crew_needed, $travel_buffer_minutes ) {
		$travel_buffer_hours = max( 0, (int) $travel_buffer_minutes ) / 60;
		$crew_needed         = max( 1, (int) $crew_needed );
		$this_jobs_load      = ( max( 0.0, (float) $hours_per_day ) * $crew_needed ) + ( $travel_buffer_hours * $crew_needed );

		foreach ( $date_span as $date ) {
			$pooled_hours  = (float) ( $pooled_hours_by_date[ $date ] ?? 0.0 );
			$existing_load = (float) ( $existing_load_by_date[ $date ] ?? 0.0 );

			if ( $pooled_hours < $existing_load + $this_jobs_load ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a date is open per the admin's weekly business_hours template
	 * and not an admin-set holiday. Public (not just used internally by
	 * get_job_date_span()) so a caller checking a whole date range one day
	 * at a time — e.g. the `available-dates` endpoint — can flip the
	 * holidays list once up front and reuse it, rather than re-flipping it
	 * per date.
	 *
	 * @param string                             $date             Date in Y-m-d format.
	 * @param array<string,array{enabled:bool}>  $business_hours   Weekly business-hours template.
	 * @param array<string,int>                  $holidays_flipped Holiday dates as array keys (array_flip() of the holidays list), for O(1) lookup.
	 * @return bool
	 */
	public static function is_business_day( $date, array $business_hours, array $holidays_flipped ) {
		if ( isset( $holidays_flipped[ $date ] ) ) {
			return false;
		}

		$weekday_key = Service_Crew_Availability::get_weekday_key( $date );

		return ! empty( $business_hours[ $weekday_key ]['enabled'] );
	}
}
