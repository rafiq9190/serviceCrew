<?php
/**
 * [service_crew_booking] shortcode: an animated four-step booking flow —
 * Service, Date & Time, Payment, Thank you (public/js/booking-flow.js).
 *
 * Step 3 (Payment) now creates a real `sc_bookings` row and redirects to a
 * real Stripe Checkout session (class-service-crew-bookings.php,
 * class-service-crew-bookings-controller.php) — no longer a simulated
 * "wait ~1s and succeed". Two parts of the original preview scope remain,
 * by explicit agreement — see class-service-crew-bookings.php's docblock
 * for the full list of what a real booking here does and doesn't do yet:
 *  - Step 1 embeds the exact same tree/pricing data and browser as
 *    [service_crew_services] (window.SCBookingWidget.init(), see
 *    class-service-crew-services-shortcode.php), but a real booking only
 *    ever books the single service currently selected — this step's
 *    multi-item cart is still useful for the live pricing preview, but
 *    "Continue" enforces exactly one item before paying.
 *  - Step 2 (Date & Time) only greys out non-business days and admin
 *    holidays from Service_Crew_Settings — there is no real pooled-crew-
 *    capacity check yet (Service_Crew_Capacity, Phase 1b-2, not built), so
 *    every open business day is bookable regardless of crew load.
 * See ServiceCrew-Tasks.md's "Built beyond the plan" entry for this
 * shortcode for the current, complete list of gaps.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Booking_Shortcode {

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap (service_crew_init() in service-crew.php).
	 */
	public function __construct() {
		add_shortcode( 'service_crew_booking', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues booking-widget's assets (Step 1 reuses that browser directly)
	 * plus this shortcode's own stepper shell, only on pages/posts whose
	 * content actually contains the shortcode.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! is_singular() ) {
			return;
		}

		if ( ! has_shortcode( get_post()->post_content, 'service_crew_booking' ) ) {
			return;
		}

		wp_enqueue_style(
			'sc-booking-widget',
			SERVICE_CREW_PLUGIN_URL . 'public/css/booking-widget.css',
			array(),
			SERVICE_CREW_VERSION
		);

		wp_enqueue_script(
			'sc-booking-widget',
			SERVICE_CREW_PLUGIN_URL . 'public/js/booking-widget.js',
			array(),
			SERVICE_CREW_VERSION,
			true
		);

		wp_enqueue_style(
			'sc-booking-flow',
			SERVICE_CREW_PLUGIN_URL . 'public/css/booking-flow.css',
			array( 'sc-booking-widget' ),
			SERVICE_CREW_VERSION
		);

		wp_enqueue_script(
			'sc-booking-flow',
			SERVICE_CREW_PLUGIN_URL . 'public/js/booking-flow.js',
			// Depends on window.SCBookingWidget existing at run time.
			array( 'sc-booking-widget' ),
			SERVICE_CREW_VERSION,
			true
		);

		/*
		 * A nonce, not just the REST root, so this still works for a visitor
		 * who happens to be logged into wp-admin in the same browser —
		 * without one, WordPress's cookie-auth check rejects the POST before
		 * the route's own (public) permission_callback ever runs. A fully
		 * logged-out customer ignores this header entirely.
		 */
		wp_localize_script(
			'sc-booking-flow',
			'SC_BOOKING',
			array(
				'restUrl' => esc_url_raw( rest_url( 'service-crew/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Scopes the admin's saved palette to both the embedded Step 1
		// browser and the stepper shell around it, same custom properties
		// either way (see Service_Crew_Appearance::build_color_css()).
		wp_add_inline_style(
			'sc-booking-widget',
			Service_Crew_Appearance::build_color_css( '.sc-booking-widget, .sc-booking-flow' )
		);
	}

	/**
	 * Renders the shortcode output: a container div holding the same
	 * tree/pricing payload as [service_crew_services] plus the scheduling
	 * settings the Date & Time step needs, as one embedded JSON payload.
	 *
	 * @return string HTML markup.
	 */
	public function render() {
		static $instance = 0;
		++$instance;

		$tree = Service_Crew_Services_Shortcode::get_tree();

		if ( empty( $tree ) ) {
			return '<p class="sc-booking-widget-empty">' . esc_html__( 'No services are available right now.', 'service-crew' ) . '</p>';
		}

		$scheduling_settings = Service_Crew_Settings::get_saved_settings();

		$payload = Service_Crew_Services_Shortcode::build_pricing_payload( $tree );

		/*
		 * Only the fields the Date & Time step actually reads — travel
		 * buffer/overtime/timers are unrelated to picking a date and window,
		 * so they're left out rather than shipping every setting to the
		 * browser.
		 */
		$payload['schedulingSettings'] = array(
			'business_hours'  => $scheduling_settings['business_hours'],
			'holidays'        => $scheduling_settings['holidays'],
			'arrival_windows' => $scheduling_settings['arrival_windows'],
		);

		$root_id = 'sc-booking-flow-' . $instance;

		/*
		 * Same "</script>" mitigation as class-service-crew-services-shortcode.php's
		 * identical embed — see its render() for the full explanation.
		 */
		$json = str_replace( '</', '<\/', wp_json_encode( $payload ) );

		ob_start();
		?>
		<div class="sc-booking-flow" id="<?php echo esc_attr( $root_id ); ?>">
			<script type="application/json" class="sc-booking-flow-data">
				<?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON inside a non-executing script tag, forward-slash-escaped above. ?>
			</script>
		</div>
		<?php
		return ob_get_clean();
	}
}
