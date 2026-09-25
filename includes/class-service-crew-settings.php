<?php
/**
 * Scheduling settings: weekly business hours, holidays/closed dates, arrival
 * windows, the fixed travel buffer, overtime allowance, the three admin
 * timers (no-response wait, quote validity, quote-accepted-not-scheduled
 * reminder), a site-wide tax rate + mode, and the minimum-deposit-by-
 * booking-amount brackets — the plan's setup-wizard step 2 fields plus tax
 * and a tiered minimum deposit (both added later by request, replacing the
 * plan's single flat minimum-deposit percentage), all exposed here as one
 * admin screen (also reopenable outside the wizard). Capacity math (Phase
 * 1b-2), the quote/no-response cron (Phase 1c) and the public services
 * widget's price preview all read these options directly; this class only
 * owns storage, defaults and sanitization.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Settings {

	/**
	 * Option name storing the sanitized settings array.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'sc_scheduling_settings';

	/**
	 * REST namespace shared with the rest of the custom admin app.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * Cap on holiday rows — generous enough for several years of closures
	 * without the repeater growing unbounded.
	 *
	 * @var int
	 */
	const MAX_HOLIDAYS = 100;

	/**
	 * Cap on arrival-window rows. The plan's own example ("morning /
	 * afternoon / evening") only needs three; this leaves room to split
	 * further without being unbounded.
	 *
	 * @var int
	 */
	const MAX_ARRIVAL_WINDOWS = 8;

	/**
	 * Cap on minimum-deposit tier rows — plenty for a handful of booking-
	 * amount brackets without being unbounded.
	 *
	 * @var int
	 */
	const MAX_DEPOSIT_TIERS = 10;

	/**
	 * Registers the REST routes. Called once from the plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers GET/PUT /service-crew/v1/scheduling-settings.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/scheduling-settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission check shared by both routes — same access level as the
	 * ServiceCrew admin menu itself.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET handler: returns the saved settings, defaults filled in for
	 * anything never saved.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( self::get_saved_settings() );
	}

	/**
	 * Reads the saved settings option, merged over defaults so a caller
	 * never has to null-check a field that predates a later default. Used by
	 * REST and (later) capacity/cron.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_saved_settings() {
		$saved = get_option( self::OPTION_NAME, array() );

		return self::merge_with_defaults( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * The plan's own defaults: Mon-Fri 9-6, no holidays, three arrival
	 * windows, a 30-minute travel buffer, overtime off, and the three timer
	 * defaults called out in the wizard step (12h / 7 days / 3 days). The
	 * tax rate defaults to 0% — it isn't part of the plan's wizard step 2,
	 * but lives on this same options row since it's a single site-wide rate
	 * applied the same way at every point a total is shown (this class is
	 * the only place all of scheduling/checkout-adjacent settings live).
	 *
	 * @return array<string,mixed>
	 */
	private static function get_defaults() {
		$business_hours = array();
		foreach ( Service_Crew_Crew::WEEKDAYS as $day ) {
			$is_weekday                 = ! in_array( $day, array( 'saturday', 'sunday' ), true );
			$business_hours[ $day ] = array(
				'enabled' => $is_weekday,
				'start'   => '09:00',
				'end'     => '18:00',
			);
		}

		return array(
			'business_hours'       => $business_hours,
			'holidays'             => array(),
			'arrival_windows'      => array(
				array(
					'label' => __( 'Morning', 'service-crew' ),
					'start' => '09:00',
					'end'   => '12:00',
				),
				array(
					'label' => __( 'Afternoon', 'service-crew' ),
					'start' => '12:00',
					'end'   => '15:00',
				),
				array(
					'label' => __( 'Evening', 'service-crew' ),
					'start' => '15:00',
					'end'   => '18:00',
				),
			),
			'travel_buffer_minutes'  => 30,
			'overtime'               => array(
				'allowed'          => false,
				'max_hours_per_day' => 2,
			),
			'timers'                 => array(
				'no_response_hours'   => 12,
				'quote_validity_days' => 7,
				'quote_reminder_days' => 3,
			),
			'tax_rate_percent'       => 0.0,
			// 'exclusive' adds tax on top of the shown price; 'inclusive'
			// treats the shown price as already containing tax and reports
			// the tax portion as a deduction out of it instead of an add-on
			// — the "tax deduction" mode, added by request alongside the
			// rate itself.
			'tax_mode'               => 'exclusive',
			// Minimum deposit ("no payment, no booking" floor) as brackets
			// keyed by booking amount rather than one flat percentage, by
			// request — e.g. a smaller job might require 30% up front while
			// a large one only requires 10%. The lookup (highest bracket
			// whose min_booking_amount doesn't exceed the total) lives in
			// Service_Crew_Pricing::get_matching_deposit_tier().
			'minimum_deposit_tiers'  => array(
				array(
					'min_booking_amount' => 0,
					'deposit_percent'    => 20,
				),
			),
		);
	}

	/**
	 * Layers a saved (possibly partial, possibly stale) settings array over
	 * the current defaults, one top-level key at a time.
	 *
	 * @param array<string,mixed> $saved Raw saved option value.
	 * @return array<string,mixed>
	 */
	private static function merge_with_defaults( array $saved ) {
		$defaults = self::get_defaults();

		foreach ( $defaults as $key => $default_value ) {
			if ( ! isset( $saved[ $key ] ) ) {
				$saved[ $key ] = $default_value;
			}
		}

		return $saved;
	}

	/**
	 * PUT handler: replaces the whole settings object, sanitized field by
	 * field. Unlike Discounts' single flat list, this option has several
	 * independent sections, so each is sanitized (and defaulted if missing
	 * or malformed) on its own rather than rejecting the whole request.
	 *
	 * @param WP_REST_Request $request Request with the settings shape in the JSON body.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : array();
		$defaults = self::get_defaults();

		$sanitized = array(
			'business_hours'       => $this->sanitize_business_hours( $params['business_hours'] ?? array() ),
			'holidays'             => $this->sanitize_holidays( $params['holidays'] ?? array() ),
			'arrival_windows'      => $this->sanitize_arrival_windows( $params['arrival_windows'] ?? array() ),
			'travel_buffer_minutes' => max( 0, absint( $params['travel_buffer_minutes'] ?? $defaults['travel_buffer_minutes'] ) ),
			'overtime'             => $this->sanitize_overtime( $params['overtime'] ?? array() ),
			'timers'               => $this->sanitize_timers( $params['timers'] ?? array() ),
			'tax_rate_percent'     => max( 0.0, min( 100.0, (float) ( $params['tax_rate_percent'] ?? $defaults['tax_rate_percent'] ) ) ),
			'tax_mode'             => 'inclusive' === ( $params['tax_mode'] ?? '' ) ? 'inclusive' : 'exclusive',
			'minimum_deposit_tiers' => $this->sanitize_deposit_tiers( $params['minimum_deposit_tiers'] ?? array() ),
		);

		update_option( self::OPTION_NAME, $sanitized, false );

		return rest_ensure_response( $sanitized );
	}

	/**
	 * Sanitizes the weekly business-hours grid: one enabled flag + start/end
	 * per weekday. A day missing from the payload, or with an invalid
	 * time/order, falls back to that day's default rather than being
	 * dropped — this is a fixed 7-row grid, not a repeater.
	 *
	 * @param mixed $rows Raw posted value, expected keyed by weekday.
	 * @return array<string,array{enabled:bool,start:string,end:string}>
	 */
	private function sanitize_business_hours( $rows ) {
		$rows     = is_array( $rows ) ? $rows : array();
		$defaults = self::get_defaults()['business_hours'];
		$result   = array();

		foreach ( Service_Crew_Crew::WEEKDAYS as $day ) {
			$row     = is_array( $rows[ $day ] ?? null ) ? $rows[ $day ] : array();
			$start   = $this->sanitize_time( $row['start'] ?? '' );
			$end     = $this->sanitize_time( $row['end'] ?? '' );
			$enabled = ! empty( $row['enabled'] );

			if ( '' === $start || '' === $end || $this->minutes_since_midnight( $end ) <= $this->minutes_since_midnight( $start ) ) {
				$result[ $day ] = $defaults[ $day ];
				continue;
			}

			$result[ $day ] = array(
				'enabled' => $enabled,
				'start'   => $start,
				'end'     => $end,
			);
		}

		return $result;
	}

	/**
	 * Sanitizes the holidays/closed-dates repeater. Rows with no valid date
	 * are dropped; duplicate dates collapse to the first occurrence.
	 *
	 * @param mixed $rows Raw posted value, expected to be an array of rows.
	 * @return array<int,array{date:string,label:string}>
	 */
	private function sanitize_holidays( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$seen_dates = array();
		$sanitized  = array();

		foreach ( array_slice( $rows, 0, self::MAX_HOLIDAYS ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$date = $this->sanitize_date( $row['date'] ?? '' );
			if ( '' === $date || isset( $seen_dates[ $date ] ) ) {
				continue;
			}

			$seen_dates[ $date ] = true;
			$sanitized[]         = array(
				'date'  => $date,
				'label' => sanitize_text_field( $row['label'] ?? '' ),
			);
		}

		usort(
			$sanitized,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		return $sanitized;
	}

	/**
	 * Sanitizes the arrival-windows repeater. A row needs a label and a
	 * valid start-before-end pair to survive; an empty result falls back to
	 * the three default windows so the customer-facing picker (Phase 1b-2)
	 * is never left with zero options.
	 *
	 * @param mixed $rows Raw posted value, expected to be an array of rows.
	 * @return array<int,array{label:string,start:string,end:string}>
	 */
	private function sanitize_arrival_windows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return self::get_defaults()['arrival_windows'];
		}

		$sanitized = array();

		foreach ( array_slice( $rows, 0, self::MAX_ARRIVAL_WINDOWS ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = sanitize_text_field( $row['label'] ?? '' );
			$start = $this->sanitize_time( $row['start'] ?? '' );
			$end   = $this->sanitize_time( $row['end'] ?? '' );

			if ( '' === $label || '' === $start || '' === $end || $this->minutes_since_midnight( $end ) <= $this->minutes_since_midnight( $start ) ) {
				continue;
			}

			$sanitized[] = array(
				'label' => $label,
				'start' => $start,
				'end'   => $end,
			);
		}

		return empty( $sanitized ) ? self::get_defaults()['arrival_windows'] : $sanitized;
	}

	/**
	 * Sanitizes the minimum-deposit-by-booking-amount repeater. A row needs
	 * a non-negative amount and a 1-100 percent to survive; duplicate
	 * amounts collapse to the first occurrence. An empty result falls back
	 * to the single default bracket so there's always a minimum deposit to
	 * look up, same "never leave zero options" rule as arrival windows.
	 *
	 * @param mixed $rows Raw posted value, expected to be an array of rows.
	 * @return array<int,array{min_booking_amount:float,deposit_percent:float}>
	 */
	private function sanitize_deposit_tiers( $rows ) {
		if ( ! is_array( $rows ) ) {
			return self::get_defaults()['minimum_deposit_tiers'];
		}

		$seen_amounts = array();
		$sanitized    = array();

		foreach ( array_slice( $rows, 0, self::MAX_DEPOSIT_TIERS ) as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['min_booking_amount'] ?? '' ) ) ) {
				continue;
			}

			$amount = max( 0.0, (float) $row['min_booking_amount'] );
			if ( isset( $seen_amounts[ $amount ] ) ) {
				continue;
			}

			$seen_amounts[ $amount ] = true;
			$sanitized[]             = array(
				'min_booking_amount' => $amount,
				'deposit_percent'    => max( 1.0, min( 100.0, (float) ( $row['deposit_percent'] ?? 0 ) ) ),
			);
		}

		usort(
			$sanitized,
			function ( $a, $b ) {
				return $a['min_booking_amount'] <=> $b['min_booking_amount'];
			}
		);

		return empty( $sanitized ) ? self::get_defaults()['minimum_deposit_tiers'] : $sanitized;
	}

	/**
	 * Sanitizes the overtime allowance: on/off plus a max-hours cap, applied
	 * per employee per day per the plan (approval itself happens per job on
	 * the Phase 1c dispatch board — this is just the site-wide ceiling).
	 *
	 * @param mixed $input Raw posted value.
	 * @return array{allowed:bool,max_hours_per_day:float}
	 */
	private function sanitize_overtime( $input ) {
		$input = is_array( $input ) ? $input : array();

		return array(
			'allowed'           => ! empty( $input['allowed'] ),
			'max_hours_per_day' => max( 0.0, (float) ( $input['max_hours_per_day'] ?? 0 ) ),
		);
	}

	/**
	 * Sanitizes the three admin timers. Falls back per-field to the wizard's
	 * own defaults (12h / 7 days / 3 days) rather than allowing 0, which
	 * would fire a timer immediately.
	 *
	 * @param mixed $input Raw posted value.
	 * @return array{no_response_hours:int,quote_validity_days:int,quote_reminder_days:int}
	 */
	private function sanitize_timers( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::get_defaults()['timers'];

		return array(
			'no_response_hours'   => max( 1, absint( $input['no_response_hours'] ?? $defaults['no_response_hours'] ) ),
			'quote_validity_days' => max( 1, absint( $input['quote_validity_days'] ?? $defaults['quote_validity_days'] ) ),
			'quote_reminder_days' => max( 1, absint( $input['quote_reminder_days'] ?? $defaults['quote_reminder_days'] ) ),
		);
	}

	/**
	 * Sanitizes a posted HH:MM time value, discarding anything that doesn't
	 * match the expected shape rather than trying to coerce it. Same rule as
	 * Service_Crew_Crew's identically-named private method.
	 *
	 * @param string $value Raw posted value.
	 * @return string Sanitized HH:MM value, or '' if invalid/empty.
	 */
	private function sanitize_time( $value ) {
		$value = sanitize_text_field( $value );

		if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitizes a posted YYYY-MM-DD date value, discarding anything that
	 * doesn't match the expected shape rather than trying to coerce it. Same
	 * rule as Service_Crew_Crew's identically-named private method.
	 *
	 * @param string $value Raw posted value.
	 * @return string Sanitized YYYY-MM-DD value, or '' if invalid/empty.
	 */
	private function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Converts an HH:MM string to minutes since midnight, for start-before-
	 * end comparisons.
	 *
	 * @param string $time HH:MM value.
	 * @return int
	 */
	private function minutes_since_midnight( $time ) {
		if ( '' === $time ) {
			return -1;
		}

		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) );

		return ( $hours * 60 ) + $minutes;
	}
}
