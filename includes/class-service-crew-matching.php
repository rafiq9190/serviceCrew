<?php
/**
 * Pure-calculation crew-suggestion logic for the dispatch board: the plan's
 * "the system suggests the best fit (nearest, least-loaded qualifying
 * employee...). It never assigns automatically." Every method here is a
 * pure function of its arguments (no $wpdb, no get_post_meta()), same
 * contract as class-service-crew-pricing.php, -availability.php and
 * -capacity.php — fetching the actual crew records, their current load, and
 * the job's geocoded location is left to whatever calls this (the
 * not-yet-built dispatch board / Phase 1c `class-service-crew-assignments.php`).
 *
 * **V1 launch scope** (see ServiceCrew-Plan-v2.md): suggestions are
 * employee-only — the caller is responsible for only passing `employee`-type
 * crew in $crew_members; this class has no opinion on crew type and will
 * happily rank vendors too if ever asked to, since that's a future caller's
 * decision, not this pure-calc layer's.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Matching {

	/**
	 * Earth radius in miles, for the haversine distance calculation —
	 * matches the unit Service_Crew_Crew's coverage-radius field is entered
	 * in (see its meta box help text), so distances and radii compare
	 * directly with no unit conversion.
	 *
	 * @var float
	 */
	const EARTH_RADIUS_MILES = 3958.8;

	/**
	 * Great-circle distance between two lat/lng points, in miles.
	 *
	 * @param float $lat1 First point's latitude.
	 * @param float $lng1 First point's longitude.
	 * @param float $lat2 Second point's latitude.
	 * @param float $lng2 Second point's longitude.
	 * @return float
	 */
	public static function calculate_distance_miles( $lat1, $lng1, $lat2, $lng2 ) {
		$lat1_rad = deg2rad( (float) $lat1 );
		$lng1_rad = deg2rad( (float) $lng1 );
		$lat2_rad = deg2rad( (float) $lat2 );
		$lng2_rad = deg2rad( (float) $lng2 );

		$delta_lat = $lat2_rad - $lat1_rad;
		$delta_lng = $lng2_rad - $lng1_rad;

		$a = sin( $delta_lat / 2 ) ** 2 + cos( $lat1_rad ) * cos( $lat2_rad ) * sin( $delta_lng / 2 ) ** 2;
		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return round( self::EARTH_RADIUS_MILES * $c, 2 );
	}

	/**
	 * Whether a crew member qualifies for a job at a given location on a
	 * given date: available that day (own weekly template minus time off,
	 * via Service_Crew_Availability) and within their coverage radius.
	 *
	 * Permissive, never a hard "no", when location data is missing — a
	 * failed/partial geocode never blocks a booking per the plan, and the
	 * same spirit applies here: if the job's or the crew member's lat/lng
	 * is unknown, radius can't be checked, so this doesn't disqualify them
	 * on that basis alone (the caller/dispatcher sees an unknown distance
	 * and decides).
	 *
	 * @param array{lat:float|null,lng:float|null,radius:int|null,availability:array,time_off:array} $crew Crew member's location/radius/availability, shaped like Service_Crew_Crew_Controller::build_response().
	 * @param float|null $job_lat Job's geocoded latitude, or null if unknown.
	 * @param float|null $job_lng Job's geocoded longitude, or null if unknown.
	 * @param string     $date    Date in Y-m-d format.
	 * @return bool
	 */
	public static function qualifies( array $crew, $job_lat, $job_lng, $date ) {
		if ( ! Service_Crew_Availability::is_available_on(
			is_array( $crew['availability'] ?? null ) ? $crew['availability'] : array(),
			is_array( $crew['time_off'] ?? null ) ? $crew['time_off'] : array(),
			$date
		) ) {
			return false;
		}

		$radius = $crew['radius'] ?? null;
		if ( null === $radius ) {
			return true; // Unlimited coverage — the employee default.
		}

		if ( null === $job_lat || null === $job_lng || null === ( $crew['lat'] ?? null ) || null === ( $crew['lng'] ?? null ) ) {
			return true; // Distance unknown — can't rule them out, don't.
		}

		return self::calculate_distance_miles( $crew['lat'], $crew['lng'], $job_lat, $job_lng ) <= (float) $radius;
	}

	/**
	 * Ranked suggestions for a job: every qualifying crew member (available
	 * on the date, within radius), nearest first, with current load as the
	 * tiebreaker — the plan's "nearest, least-loaded qualifying employee".
	 * A crew member whose distance can't be determined (missing lat/lng on
	 * either side) sorts after every crew member with a known distance,
	 * then by load among themselves, rather than being excluded.
	 *
	 * @param array<int,array{id:int,lat:float|null,lng:float|null,radius:int|null,availability:array,time_off:array,load_hours?:float}> $crew_members Candidate crew (V1: employee-type only — see this class's docblock).
	 * @param float|null $job_lat    Job's geocoded latitude, or null if unknown.
	 * @param float|null $job_lng    Job's geocoded longitude, or null if unknown.
	 * @param string     $date       Date in Y-m-d format.
	 * @return array<int,array{id:int,distance_miles:float|null,load_hours:float}> Ranked, nearest/least-loaded first.
	 */
	public static function get_suggestions( array $crew_members, $job_lat, $job_lng, $date ) {
		$suggestions = array();

		foreach ( $crew_members as $crew ) {
			if ( ! self::qualifies( $crew, $job_lat, $job_lng, $date ) ) {
				continue;
			}

			$has_known_distance = null !== $job_lat && null !== $job_lng && null !== ( $crew['lat'] ?? null ) && null !== ( $crew['lng'] ?? null );

			$suggestions[] = array(
				'id'             => (int) $crew['id'],
				'distance_miles' => $has_known_distance ? self::calculate_distance_miles( $crew['lat'], $crew['lng'], $job_lat, $job_lng ) : null,
				'load_hours'     => max( 0.0, (float) ( $crew['load_hours'] ?? 0 ) ),
			);
		}

		usort(
			$suggestions,
			function ( $a, $b ) {
				$distance_comparison = self::compare_nullable_distance( $a['distance_miles'], $b['distance_miles'] );

				return 0 !== $distance_comparison ? $distance_comparison : ( $a['load_hours'] <=> $b['load_hours'] );
			}
		);

		return $suggestions;
	}

	/**
	 * Comparator for a sort key that may be null (unknown distance) — a
	 * known distance always sorts before an unknown one; two unknowns are
	 * equal (load_hours breaks the tie in get_suggestions()).
	 *
	 * @param float|null $a
	 * @param float|null $b
	 * @return int Negative if $a sorts first, positive if $b does, 0 if equal.
	 */
	private static function compare_nullable_distance( $a, $b ) {
		if ( null === $a && null === $b ) {
			return 0;
		}

		if ( null === $a ) {
			return 1;
		}

		if ( null === $b ) {
			return -1;
		}

		return $a <=> $b;
	}
}
