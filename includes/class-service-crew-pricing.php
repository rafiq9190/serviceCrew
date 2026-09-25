<?php
/**
 * Pure-calculation pricing math: service and add-on line totals, whole-
 * booking advance-payment discount tiers, minimum-deposit amounts (looked up
 * from booking-amount brackets, not a single flat percentage), and tax. No
 * side effects and no option/meta lookups of its own — callers (the future
 * `POST /calculate-price` endpoint, Phase 1b-2) pass in the service/
 * component fields already read from post meta and the tiers already read
 * from Service_Crew_Discounts::get_saved_tiers(), so this class stays a
 * plain function of its inputs and is testable without a WordPress
 * bootstrap. Per the plan's "all price maths runs on the server" rule, the
 * browser only ever displays what this class returns.
 *
 * The plan's original Phase 1a wording lists "quantity discounts" among
 * this class's jobs, left over from an early draft where add-ons carried
 * their own discount tiers. That was dropped before Components shipped (see
 * its "no per-add-on discount tiers" row in ServiceCrew-Tasks.md) — the only
 * discount concept left is the whole-booking advance-payment tiers below.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Pricing {

	/**
	 * Prices and times a leaf service line. Flat mode always resolves to a
	 * quantity of 1; per-unit mode clamps the requested quantity to the
	 * service's minimum (there is no maximum, per
	 * Service_Crew_Services::META_UNIT_QTY_MIN's doc comment).
	 *
	 * @param array<string,mixed> $service_fields Result of Service_Crew_Services::get_pricing_fields().
	 * @param int|null            $requested_qty  Customer-chosen quantity (per-unit mode only); null uses the minimum.
	 * @return array{price:float,duration_minutes:float,quantity:int}
	 */
	public static function calculate_service_line( array $service_fields, $requested_qty = null ) {
		$unit_price    = (float) ( $service_fields['price'] ?? 0 );
		$unit_duration = (float) ( $service_fields['duration_minutes'] ?? 0 );

		if ( Service_Crew_Services::PRICE_MODE_PER_UNIT !== ( $service_fields['price_mode'] ?? '' ) ) {
			return array(
				'price'            => round( $unit_price, 2 ),
				'duration_minutes' => round( $unit_duration, 2 ),
				'quantity'         => 1,
			);
		}

		$qty_min  = max( 1, (int) ( $service_fields['unit_qty_min'] ?? 1 ) );
		$quantity = max( $qty_min, null === $requested_qty ? $qty_min : (int) $requested_qty );

		return array(
			'price'            => round( $unit_price * $quantity, 2 ),
			'duration_minutes' => round( $unit_duration * $quantity, 2 ),
			'quantity'         => $quantity,
		);
	}

	/**
	 * Prices and times one selected add-on. A non-quantity add-on is always
	 * quantity 1 (picked or not picked, no counter); a quantity add-on
	 * clamps to its minimum, same "no maximum" rule as the service above.
	 *
	 * @param array<string,mixed> $component     One row from Service_Crew_Components::get_components().
	 * @param int|null            $requested_qty Customer-chosen quantity (quantity add-ons only); null uses the minimum.
	 * @return array{name:string,price:float,duration_minutes:float,quantity:int}
	 */
	public static function calculate_component_line( array $component, $requested_qty = null ) {
		$unit_price    = (float) ( $component['unit_price'] ?? 0 );
		$unit_duration = (float) ( $component['unit_duration_minutes'] ?? 0 );

		if ( empty( $component['has_quantity'] ) ) {
			$quantity = 1;
		} else {
			$qty_min  = max( 1, (int) ( $component['qty_min'] ?? 1 ) );
			$quantity = max( $qty_min, null === $requested_qty ? $qty_min : (int) $requested_qty );
		}

		return array(
			'name'             => (string) ( $component['name'] ?? '' ),
			'price'            => round( $unit_price * $quantity, 2 ),
			'duration_minutes' => round( $unit_duration * $quantity, 2 ),
			'quantity'         => $quantity,
		);
	}

	/**
	 * Sums a service line and its selected add-on lines into a booking
	 * subtotal, before any advance-payment discount.
	 *
	 * @param array{price:float,duration_minutes:float} $service_line     Result of calculate_service_line().
	 * @param array<int,array{price:float,duration_minutes:float}> $component_lines  Results of calculate_component_line(), selected add-ons only.
	 * @return array{price:float,duration_minutes:float}
	 */
	public static function calculate_subtotal( array $service_line, array $component_lines ) {
		$price    = (float) $service_line['price'];
		$duration = (float) $service_line['duration_minutes'];

		foreach ( $component_lines as $line ) {
			$price    += (float) $line['price'];
			$duration += (float) $line['duration_minutes'];
		}

		return array(
			'price'            => round( $price, 2 ),
			'duration_minutes' => round( $duration, 2 ),
		);
	}

	/**
	 * Finds the best tier that applies at a given percent-paid-now, i.e. the
	 * highest min_percent_paid that doesn't exceed it. Tiers are expected
	 * pre-sorted ascending by Service_Crew_Discounts::update_tiers(), but
	 * this doesn't rely on that — it scans all of them.
	 *
	 * @param array<int,array{min_percent_paid:int,discount_type:string,discount_value:float}> $tiers Saved advance-payment tiers.
	 * @param int $percent_paid_now Percent of the total the customer is paying now.
	 * @return array{min_percent_paid:int,discount_type:string,discount_value:float}|null
	 */
	public static function get_matching_tier( array $tiers, $percent_paid_now ) {
		$best = null;

		foreach ( $tiers as $tier ) {
			if ( (int) $tier['min_percent_paid'] > (int) $percent_paid_now ) {
				continue;
			}

			if ( null === $best || (int) $tier['min_percent_paid'] > (int) $best['min_percent_paid'] ) {
				$best = $tier;
			}
		}

		return $best;
	}

	/**
	 * Discount amount a tier removes from a subtotal. Fixed-amount discounts
	 * never exceed the subtotal (no negative totals).
	 *
	 * @param float                                                        $subtotal Pre-discount subtotal.
	 * @param array{discount_type:string,discount_value:float}|null $tier     Result of get_matching_tier(), or null for no discount.
	 * @return float
	 */
	public static function calculate_discount_amount( $subtotal, $tier ) {
		if ( null === $tier ) {
			return 0.0;
		}

		if ( 'fixed' === $tier['discount_type'] ) {
			return round( min( (float) $subtotal, (float) $tier['discount_value'] ), 2 );
		}

		return round( (float) $subtotal * ( (float) $tier['discount_value'] / 100 ), 2 );
	}

	/**
	 * The floor amount the customer must pay to book at all — a resolved
	 * deposit percentage (see get_matching_deposit_tier()) of the (already-
	 * discounted) total.
	 *
	 * @param float $total                    Booking total after any advance-payment discount.
	 * @param float $minimum_deposit_percent  Resolved minimum deposit percentage (0-100).
	 * @return float
	 */
	public static function calculate_minimum_deposit( $total, $minimum_deposit_percent ) {
		$percent = max( 0.0, min( 100.0, (float) $minimum_deposit_percent ) );

		return round( (float) $total * ( $percent / 100 ), 2 );
	}

	/**
	 * Finds the deposit-percentage bracket that applies to a booking amount,
	 * i.e. the highest min_booking_amount that doesn't exceed it — same
	 * "highest threshold met" shape as get_matching_tier(), just keyed by
	 * amount instead of percent-paid. Replaces the plan's single flat
	 * minimum-deposit percentage, by request
	 * (Service_Crew_Settings::get_saved_settings()['minimum_deposit_tiers']
	 * always has at least one bracket, so this only returns null if $tiers
	 * itself is empty or malformed).
	 *
	 * @param array<int,array{min_booking_amount:float,deposit_percent:float}> $tiers  Saved deposit brackets.
	 * @param float                                                            $amount Booking amount (subtotal, after any advance-payment discount).
	 * @return array{min_booking_amount:float,deposit_percent:float}|null
	 */
	public static function get_matching_deposit_tier( array $tiers, $amount ) {
		$best = null;

		foreach ( $tiers as $tier ) {
			if ( (float) $tier['min_booking_amount'] > (float) $amount ) {
				continue;
			}

			if ( null === $best || (float) $tier['min_booking_amount'] > (float) $best['min_booking_amount'] ) {
				$best = $tier;
			}
		}

		return $best;
	}

	/**
	 * Tax owed on an amount at the admin's site-wide rate
	 * (Service_Crew_Settings::get_saved_settings()['tax_rate_percent']/
	 * ['tax_mode']). Added by request, not part of the original plan's
	 * pricing model — kept as its own pure function so callers decide for
	 * themselves whether tax applies before or after the advance-payment
	 * discount.
	 *
	 * 'exclusive' (the default) treats $amount as pre-tax and adds the rate
	 * on top. 'inclusive' — the "tax deduction" mode — treats $amount as
	 * already containing tax and backs the tax portion out of it instead
	 * (amount stays the same either way; only how much of it is reported as
	 * tax differs), for admins whose displayed prices are tax-inclusive.
	 *
	 * @param float  $amount          Amount to tax (subtotal, or a discounted total).
	 * @param float  $tax_rate_percent Admin-set tax rate percentage (0-100).
	 * @param string $tax_mode        'exclusive' or 'inclusive'.
	 * @return float
	 */
	public static function calculate_tax_amount( $amount, $tax_rate_percent, $tax_mode = 'exclusive' ) {
		$rate   = max( 0.0, min( 100.0, (float) $tax_rate_percent ) );
		$amount = (float) $amount;

		if ( 'inclusive' === $tax_mode ) {
			return round( $amount - ( $amount / ( 1 + ( $rate / 100 ) ) ), 2 );
		}

		return round( $amount * ( $rate / 100 ), 2 );
	}

	/**
	 * Builds the checkout payment options: the minimum required payment plus
	 * every tier above it, each with its discounted total and the amount due
	 * now at that percentage — everything the plan's "checkout shows the
	 * minimum, each tier option, and the resulting price for each" needs, in
	 * one call.
	 *
	 * @param float                                                                          $subtotal                Pre-discount booking subtotal.
	 * @param array<int,array{min_percent_paid:int,discount_type:string,discount_value:float}> $tiers                   Saved advance-payment tiers.
	 * @param float                                                                          $minimum_deposit_percent Admin-set minimum deposit percentage (0-100).
	 * @return array<int,array{percent_paid_now:int,is_minimum:bool,discount_amount:float,total:float,amount_due_now:float}>
	 */
	public static function build_payment_options( $subtotal, array $tiers, $minimum_deposit_percent ) {
		$minimum_percent = max( 0.0, min( 100.0, (float) $minimum_deposit_percent ) );

		$options   = array();
		$options[] = self::build_payment_option( $subtotal, $minimum_percent, $tiers, true );

		foreach ( $tiers as $tier ) {
			if ( (int) $tier['min_percent_paid'] <= $minimum_percent ) {
				continue;
			}

			$options[] = self::build_payment_option( $subtotal, (int) $tier['min_percent_paid'], $tiers, false );
		}

		return $options;
	}

	/**
	 * Builds one build_payment_options() row for a specific percent-paid-now.
	 *
	 * @param float                                                                          $subtotal         Pre-discount booking subtotal.
	 * @param float                                                                          $percent_paid_now Percent of the discounted total due at this option.
	 * @param array<int,array{min_percent_paid:int,discount_type:string,discount_value:float}> $tiers            Saved advance-payment tiers.
	 * @param bool                                                                           $is_minimum       Whether this row is the admin-set minimum-deposit option.
	 * @return array{percent_paid_now:int,is_minimum:bool,discount_amount:float,total:float,amount_due_now:float}
	 */
	private static function build_payment_option( $subtotal, $percent_paid_now, array $tiers, $is_minimum ) {
		$tier            = self::get_matching_tier( $tiers, $percent_paid_now );
		$discount_amount = self::calculate_discount_amount( $subtotal, $tier );
		$total           = round( max( 0.0, (float) $subtotal - $discount_amount ), 2 );

		return array(
			'percent_paid_now' => (int) $percent_paid_now,
			'is_minimum'       => (bool) $is_minimum,
			'discount_amount'  => $discount_amount,
			'total'            => $total,
			'amount_due_now'   => round( $total * ( $percent_paid_now / 100 ), 2 ),
		);
	}
}
