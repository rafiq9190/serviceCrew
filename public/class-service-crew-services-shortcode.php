<?php
/**
 * [service_crew_services] shortcode: an interactive, publicly-visible
 * column browser for published services — drill down Service → Sub-service
 * → Add-ons, with a live running-total column. Read-only: this is a live
 * price *estimate* for browsing, not the plan's authoritative
 * `POST /calculate-price` (Phase 1b-2, not built yet — no booking/payment
 * backend exists, so there is no submit action here).
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Not part of ServiceCrew-Plan-v2.md — the plan's only front-end shortcode
 * is [service_crew_booking] (Phase 1b-2, needs pricing/availability/REST
 * first). This is the same "built ahead of the plan" catalog shortcode as
 * before, now upgraded from a static nested list into the interactive
 * column browser, by request.
 *
 * sc_service has publicly_queryable => false, but that only affects
 * front-end URL routing (query vars, archive/single templates) — a direct
 * WP_Query from PHP, as this class does, is unaffected and is the supported
 * way to read the CPT from the front end.
 *
 * Pricing/add-on data reuses Service_Crew_Services::get_pricing_fields()
 * and Service_Crew_Components::get_components() — the exact same statics
 * the admin app's REST controller uses — so there is exactly one place
 * that data model logic lives. This class only adds the "published
 * children only" tree-building on top, since (unlike the admin app) the
 * public widget must never reveal a draft/pending service.
 *
 * get_tree() and build_pricing_payload() are public statics (not just this
 * class's own render()) because Service_Crew_Booking_Shortcode's stepper
 * reuses this exact same tree/pricing data for its own Step 1 — one tree-
 * building implementation, read by two front-end entry points.
 */
class Service_Crew_Services_Shortcode {

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap (service_crew_init() in service-crew.php).
	 */
	public function __construct() {
		add_shortcode( 'service_crew_services', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues the widget's CSS/JS, and the admin's saved color palette as
	 * inline custom properties, only on pages/posts whose content actually
	 * contains the shortcode.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! is_singular() ) {
			return;
		}

		if ( ! has_shortcode( get_post()->post_content, 'service_crew_services' ) ) {
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

		wp_add_inline_style( 'sc-booking-widget', Service_Crew_Appearance::build_color_css( '.sc-booking-widget' ) );
	}

	/**
	 * Renders the shortcode output: a container div holding the published
	 * service tree plus the tax rate and discount tiers, all as one embedded
	 * JSON payload, and a unique root id (multiple instances of the
	 * shortcode on one page are supported).
	 *
	 * @return string HTML markup.
	 */
	public function render() {
		static $instance = 0;
		++$instance;

		$tree = self::get_tree();

		if ( empty( $tree ) ) {
			return '<p class="sc-booking-widget-empty">' . esc_html__( 'No services are available right now.', 'service-crew' ) . '</p>';
		}

		$root_id = 'sc-booking-widget-' . $instance;
		$payload = self::build_pricing_payload( $tree );

		/*
		 * A service title containing a literal "</script>" would otherwise
		 * close this tag early regardless of its type="application/json" —
		 * browsers scan for that byte sequence during HTML parsing before any
		 * MIME-type-aware handling happens. Escaping the forward slash is the
		 * standard mitigation and keeps the JSON itself valid (JSON allows an
		 * escaped solidus).
		 */
		$json = str_replace( '</', '<\/', wp_json_encode( $payload ) );

		ob_start();
		?>
		<div class="sc-booking-widget" id="<?php echo esc_attr( $root_id ); ?>">
			<script type="application/json" class="sc-booking-data">
				<?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON inside a non-executing script tag, forward-slash-escaped above. ?>
			</script>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Builds the full published-service tree from the top level down.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_tree() {
		return array_map( array( __CLASS__, 'build_node' ), self::get_published_children( 0 ) );
	}

	/**
	 * Bundles a tree with the site-wide pricing settings (tax rate/mode,
	 * advance-payment discount tiers, minimum-deposit brackets) into the one
	 * JSON payload the front end needs for a live price preview — no
	 * separate REST round-trip for any of it, same reasoning as embedding
	 * the tree itself.
	 *
	 * @param array<int,array<string,mixed>> $tree Result of get_tree().
	 * @return array<string,mixed>
	 */
	public static function build_pricing_payload( array $tree ) {
		$scheduling_settings = Service_Crew_Settings::get_saved_settings();

		return array(
			'tree'                => $tree,
			'taxRatePercent'      => (float) $scheduling_settings['tax_rate_percent'],
			'taxMode'             => $scheduling_settings['tax_mode'],
			'discountTiers'       => Service_Crew_Discounts::get_saved_tiers(),
			'minimumDepositTiers' => $scheduling_settings['minimum_deposit_tiers'],
		);
	}

	/**
	 * Recursively builds one tree node. A leaf (no published children)
	 * carries its pricing/add-ons; a category carries only its children —
	 * exactly the same "category has no price of its own" rule the admin
	 * app enforces server-side (Service_Crew_Services::sanitize_pricing_fields()),
	 * just read here rather than re-derived.
	 *
	 * @param WP_Post $service Service (or sub-service) post.
	 * @return array<string,mixed>
	 */
	private static function build_node( $service ) {
		$children     = self::get_published_children( $service->ID );
		$has_children = ! empty( $children );

		return array(
			'id'           => $service->ID,
			'title'        => Service_Crew_Services::get_plain_title( $service ),
			'has_children' => $has_children,
			'children'     => array_map( array( __CLASS__, 'build_node' ), $children ),
			'pricing'      => $has_children ? null : Service_Crew_Services::get_pricing_fields( $service->ID, false ),
			'components'   => $has_children ? array() : Service_Crew_Components::get_components( $service->ID, false ),
		);
	}

	/**
	 * Gets the published, menu_order/title-sorted direct children of a
	 * service (or the top-level services, for $parent_id 0). "Published"
	 * only — unlike the admin app's has_child_services(), a draft/pending
	 * sub-service must never make a service look like (or actually behave
	 * as) a category on the public widget.
	 *
	 * @param int $parent_id Parent post ID, or 0 for top-level.
	 * @return WP_Post[]
	 */
	private static function get_published_children( $parent_id ) {
		return get_posts(
			array(
				'post_type'      => 'sc_service',
				'post_status'    => 'publish',
				'post_parent'    => $parent_id,
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
	}
}
