<?php
/**
 * Single choke point for the geocoding provider (Nominatim now, Google Maps
 * later per the plan). Every address lookup in the plugin must go through
 * geocode() rather than calling a provider directly, so swapping providers
 * later touches one file.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the Nominatim search API with caching and a fixed fallback chain:
 * full address+ZIP, then ZIP alone, then a failed result. Never throws —
 * network errors, bad responses and empty results all resolve to the
 * 'failed' quality rather than an exception, so callers never need a
 * try/catch around geocode().
 *
 * Nominatim's usage policy caps unauthenticated use at ~1 request/second;
 * throttle() enforces that across all callers via a stored last-request
 * timestamp, and results are cached so a given address is only ever looked
 * up once.
 */
class Service_Crew_Geocoding {

	/**
	 * Full address + ZIP resolved to coordinates.
	 *
	 * @var string
	 */
	const QUALITY_EXACT = 'exact';

	/**
	 * Address lookup failed; ZIP alone resolved to coordinates.
	 *
	 * @var string
	 */
	const QUALITY_ZIP = 'zip';

	/**
	 * Neither lookup resolved to coordinates.
	 *
	 * @var string
	 */
	const QUALITY_FAILED = 'failed';

	/**
	 * Meta key storing the last geocode result's quality, kept alongside the
	 * hash meta so save-triggered callers can skip re-geocoding unchanged
	 * addresses without losing manually-corrected lat/lng.
	 *
	 * @var string
	 */
	const META_QUALITY = '_sc_crew_geocode_quality';

	/**
	 * Meta key storing a hash of the address+ZIP last geocoded, so
	 * geocode_on_save() only calls out when the address actually changed.
	 *
	 * @var string
	 */
	const META_HASH = '_sc_crew_geocode_hash';

	/**
	 * Option name holding the microtime() of the last provider request,
	 * shared across all callers/requests for the rate limit in throttle().
	 *
	 * @var string
	 */
	const RATE_LIMIT_OPTION = 'sc_geocoding_last_request';

	/**
	 * Minimum spacing between provider requests, matching Nominatim's ~1
	 * request/second usage policy.
	 *
	 * @var int
	 */
	const MIN_REQUEST_INTERVAL_MICROSECONDS = 1000000;

	/**
	 * How long a successful lookup is cached. Nominatim results for a given
	 * address don't change, so this is long-lived.
	 *
	 * @var int
	 */
	const CACHE_TTL_SUCCESS = MONTH_IN_SECONDS;

	/**
	 * How long a failed lookup is cached — short-lived, so a transient
	 * network error doesn't get stuck as a permanent failure.
	 *
	 * @var int
	 */
	const CACHE_TTL_FAILURE = HOUR_IN_SECONDS;

	/**
	 * Registers the save_post_sc_crew hook that auto-populates lat/lng.
	 * Runs at priority 20, after Service_Crew_Crew::save_meta_box() (priority
	 * 10) has persisted the address/ZIP fields it reads.
	 */
	public function __construct() {
		add_action( 'save_post_sc_crew', array( $this, 'geocode_on_save' ), 20, 2 );
	}

	/**
	 * Re-geocodes a crew record's address when it changed, filling in
	 * lat/lng. Skipped entirely when the address+ZIP hash matches the last
	 * geocode, so an unrelated re-save doesn't clobber a manually-corrected
	 * lat/lng and doesn't spend a provider request.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post    Post object being saved.
	 * @return void
	 */
	public function geocode_on_save( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$address = get_post_meta( $post_id, Service_Crew_Crew::META_ADDRESS, true );
		$zip     = get_post_meta( $post_id, Service_Crew_Crew::META_ZIP, true );

		if ( '' === $address && '' === $zip ) {
			return;
		}

		$hash = md5( $address . '|' . $zip );

		if ( $hash === get_post_meta( $post_id, self::META_HASH, true ) ) {
			return;
		}

		$result = $this->geocode( $address, $zip );

		if ( null !== $result['lat'] && null !== $result['lng'] ) {
			update_post_meta( $post_id, Service_Crew_Crew::META_LAT, $result['lat'] );
			update_post_meta( $post_id, Service_Crew_Crew::META_LNG, $result['lng'] );
		}

		update_post_meta( $post_id, self::META_QUALITY, $result['quality'] );
		update_post_meta( $post_id, self::META_HASH, $hash );
	}

	/**
	 * Resolves an address to coordinates, falling back from full
	 * address+ZIP to ZIP alone to a failed result. Never throws.
	 *
	 * @param string $address Street address (may be empty).
	 * @param string $zip     ZIP/postal code (may be empty).
	 * @return array{lat: float|null, lng: float|null, quality: string}
	 */
	public function geocode( $address, $zip ) {
		$address = trim( (string) $address );
		$zip     = trim( (string) $zip );

		if ( '' !== $address && '' !== $zip ) {
			$result = $this->lookup( trim( $address . ', ' . $zip ), self::QUALITY_EXACT );
			if ( null !== $result ) {
				return $result;
			}
		}

		if ( '' !== $zip ) {
			$result = $this->lookup( $zip, self::QUALITY_ZIP );
			if ( null !== $result ) {
				return $result;
			}
		}

		return array(
			'lat'     => null,
			'lng'     => null,
			'quality' => self::QUALITY_FAILED,
		);
	}

	/**
	 * Looks up a single query string against the cache, then the provider.
	 *
	 * @param string $query   Text to geocode.
	 * @param string $quality Quality label to attach if this lookup succeeds.
	 * @return array{lat: float, lng: float, quality: string}|null Null if this tier found nothing.
	 */
	private function lookup( $query, $quality ) {
		if ( '' === $query ) {
			return null;
		}

		$cache_key = 'sc_geocode_' . md5( strtolower( $query ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$coords = $cached;
		} else {
			$coords = $this->request_provider( $query );
			set_transient( $cache_key, $coords, null === $coords['lat'] ? self::CACHE_TTL_FAILURE : self::CACHE_TTL_SUCCESS );
		}

		if ( null === $coords['lat'] || null === $coords['lng'] ) {
			return null;
		}

		return array(
			'lat'     => $coords['lat'],
			'lng'     => $coords['lng'],
			'quality' => $quality,
		);
	}

	/**
	 * Performs the actual Nominatim request, rate-limited via throttle().
	 * Any failure — WP_Error, non-200 response, unparseable body, empty
	 * result set — resolves to a null-coordinate result rather than
	 * propagating an exception.
	 *
	 * @param string $query Text to geocode.
	 * @return array{lat: float|null, lng: float|null}
	 */
	private function request_provider( $query ) {
		$this->throttle();

		$url = add_query_arg(
			array(
				'q'      => $query,
				'format' => 'json',
				'limit'  => 1,
			),
			'https://nominatim.openstreetmap.org/search'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'ServiceCrew WordPress Plugin (' . home_url( '/' ) . ')',
			)
		);

		update_option( self::RATE_LIMIT_OPTION, microtime( true ), false );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array(
				'lat' => null,
				'lng' => null,
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body[0]['lat'] ) || empty( $body[0]['lon'] ) ) {
			return array(
				'lat' => null,
				'lng' => null,
			);
		}

		return array(
			'lat' => (float) $body[0]['lat'],
			'lng' => (float) $body[0]['lon'],
		);
	}

	/**
	 * Blocks until at least MIN_REQUEST_INTERVAL_MICROSECONDS has passed
	 * since the last provider request made by any caller.
	 *
	 * @return void
	 */
	private function throttle() {
		$last = (float) get_option( self::RATE_LIMIT_OPTION, 0 );

		if ( ! $last ) {
			return;
		}

		$elapsed_us   = ( microtime( true ) - $last ) * 1000000;
		$remaining_us = self::MIN_REQUEST_INTERVAL_MICROSECONDS - $elapsed_us;

		if ( $remaining_us > 0 ) {
			usleep( (int) $remaining_us );
		}
	}
}
