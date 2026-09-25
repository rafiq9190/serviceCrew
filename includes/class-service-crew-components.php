<?php
/**
 * Add-ons ("components" in the plan) attached to a leaf sc_service post:
 * optional or required extras with an optional quantity counter. Discounts
 * are never modeled here — per the plan, a discount only ever applies to the
 * whole booking (see class-service-crew-discounts.php), never to an
 * individual service or add-on — so there is no tiers concept in this class.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure data/sanitization helper — no WordPress hooks of its own. Add-ons are
 * only ever read/written through Service_Crew_Services_Controller (the
 * custom admin app's REST data layer); this class exists so the sanitize
 * rules live in exactly one place.
 */
class Service_Crew_Components {

	/**
	 * Meta key for a service's add-ons array. Never set on a service that
	 * has sub-services — see apply().
	 *
	 * @var string
	 */
	const META_COMPONENTS = '_sc_components';

	/**
	 * Sanitizes one posted add-on row.
	 *
	 * @param mixed $row Raw row (already decoded from JSON by the caller).
	 * @return array<string,mixed>|null Sanitized row, or null if it has no name (dropped).
	 */
	public static function sanitize_component( $row ) {
		if ( ! is_array( $row ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
			return null;
		}

		return array(
			'name'                  => sanitize_text_field( $row['name'] ),
			'required'              => ! empty( $row['required'] ),
			'has_quantity'          => ! empty( $row['has_quantity'] ),
			// No max/default, by request: the picker starts at, and never
			// goes below, this minimum — there is no upper cap.
			'qty_min'               => max( 1, absint( $row['qty_min'] ?? 1 ) ),
			'unit_price'            => max( 0, (float) ( $row['unit_price'] ?? 0 ) ),
			'unit_duration_minutes' => max( 0, absint( $row['unit_duration_minutes'] ?? 0 ) ),
		);
	}

	/**
	 * Sanitizes a whole posted add-ons array, dropping invalid rows.
	 *
	 * @param mixed $rows Raw posted value, expected to be an array of rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_component_list( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $rows as $row ) {
			$component = self::sanitize_component( $row );

			if ( null !== $component ) {
				$sanitized[] = $component;
			}
		}

		return $sanitized;
	}

	/**
	 * Persists (or clears) a service's add-ons. A service with sub-services
	 * has no add-ons of its own — the customer buys the sub-service, not the
	 * category — so $has_children always wins over whatever was posted.
	 *
	 * @param int   $post_id      Service post ID.
	 * @param array $components   Sanitized add-ons (see sanitize_component_list()).
	 * @param bool  $has_children Whether the service currently has sub-services.
	 * @return void
	 */
	public static function apply( $post_id, array $components, $has_children ) {
		if ( $has_children || empty( $components ) ) {
			delete_post_meta( $post_id, self::META_COMPONENTS );
			return;
		}

		update_post_meta( $post_id, self::META_COMPONENTS, $components );
	}

	/**
	 * Reads a service's add-ons for a REST response. Always empty for a
	 * category, even if stale meta somehow still exists.
	 *
	 * @param int  $post_id      Service post ID.
	 * @param bool $has_children Whether the service currently has sub-services.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_components( $post_id, $has_children ) {
		if ( $has_children ) {
			return array();
		}

		$components = get_post_meta( $post_id, self::META_COMPONENTS, true );

		return is_array( $components ) ? $components : array();
	}
}
