<?php
/**
 * [service_crew_services] shortcode: a read-only, publicly-visible,
 * nested list of published services. A service with sub-services renders
 * as a category (no price of its own, just its children); a leaf service
 * renders with its own price/duration.
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
 * first). This is a smaller, separate read-only catalog shortcode added by
 * request ahead of that: no booking, no components, no pricing math, just
 * the service list so a site can show it publicly today.
 *
 * sc_service has publicly_queryable => false, but that only affects
 * front-end URL routing (query vars, archive/single templates) — a direct
 * WP_Query from PHP, as this class does, is unaffected and is the supported
 * way to read the CPT from the front end.
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
	 * Enqueues the shortcode's stylesheet only on pages/posts whose content
	 * actually contains the shortcode.
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
			'sc-services-shortcode',
			SERVICE_CREW_PLUGIN_URL . 'public/css/services-shortcode.css',
			array(),
			SERVICE_CREW_VERSION
		);
	}

	/**
	 * Renders the shortcode output: top-level services, each recursively
	 * expanded into its sub-services (see render_service_node()).
	 *
	 * @return string HTML markup.
	 */
	public function render() {
		$top_level = $this->get_published_children( 0 );

		if ( empty( $top_level ) ) {
			return '<p class="sc-services-empty">' . esc_html__( 'No services are available right now.', 'service-crew' ) . '</p>';
		}

		ob_start();
		?>
		<ul class="sc-services-list">
			<?php foreach ( $top_level as $service ) : ?>
				<?php echo $this->render_service_node( $service ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped markup built by render_service_node(). ?>
			<?php endforeach; ?>
		</ul>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders one service as a list item: a leaf (no sub-services) shows its
	 * own price/duration, a category (has sub-services) shows no price of
	 * its own and instead nests its children's items — recursively, so a
	 * sub-service with its own sub-services works the same way.
	 *
	 * @param WP_Post $service Service (or sub-service) post.
	 * @return string Already-escaped HTML markup for one <li>.
	 */
	private function render_service_node( $service ) {
		$children = $this->get_published_children( $service->ID );

		ob_start();
		?>
		<li class="sc-services-list__item">
			<span class="sc-services-list__name"><?php echo esc_html( get_the_title( $service ) ); ?></span>
			<?php if ( empty( $children ) ) : ?>
				<?php
				$price            = get_post_meta( $service->ID, Service_Crew_Services::META_PRICE, true );
				$duration_minutes = get_post_meta( $service->ID, Service_Crew_Services::META_DURATION_MINUTES, true );
				?>
				<?php if ( '' !== $price ) : ?>
					<span class="sc-services-list__price"><?php echo esc_html( number_format_i18n( (float) $price, 2 ) ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $duration_minutes ) : ?>
					<span class="sc-services-list__duration">
						<?php
						printf(
							/* translators: %d: duration in minutes */
							esc_html__( '%d min', 'service-crew' ),
							(int) $duration_minutes
						);
						?>
					</span>
				<?php endif; ?>
				<?php echo $this->render_addons( $service->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped markup built by render_addons(). ?>
			<?php else : ?>
				<ul class="sc-services-list sc-services-list--nested">
					<?php foreach ( $children as $child ) : ?>
						<?php echo $this->render_service_node( $child ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped markup built by render_service_node(). ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</li>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders a leaf service's add-ons (Service_Crew_Components) as an
	 * upsell list: name, price, required/optional, and a plain-language
	 * summary of its quantity-discount tiers if it has any. Read-only, same
	 * as the rest of this shortcode — selecting/adding an add-on to a
	 * booking happens in the real booking flow ([service_crew_booking],
	 * Phase 1b-2), not here.
	 *
	 * @param int $service_id Leaf service post ID.
	 * @return string Already-escaped HTML markup, or '' if it has no add-ons.
	 */
	private function render_addons( $service_id ) {
		$components = get_post_meta( $service_id, Service_Crew_Components::META_COMPONENTS, true );

		if ( ! is_array( $components ) || empty( $components ) ) {
			return '';
		}

		ob_start();
		?>
		<ul class="sc-services-list__addons">
			<?php foreach ( $components as $component ) : ?>
				<?php
				$name         = isset( $component['name'] ) ? $component['name'] : '';
				$required     = ! empty( $component['required'] );
				$has_quantity = ! empty( $component['has_quantity'] );
				$unit_price   = isset( $component['unit_price'] ) ? (float) $component['unit_price'] : 0;
				$tiers        = isset( $component['tiers'] ) && is_array( $component['tiers'] ) ? $component['tiers'] : array();
				?>
				<li class="sc-services-list__addon">
					<span class="sc-services-list__addon-name"><?php echo esc_html( $name ); ?></span>
					<span class="sc-services-list__addon-price">
						<?php
						printf(
							/* translators: %s: formatted price */
							esc_html( $has_quantity ? __( '+$%s per unit', 'service-crew' ) : __( '+$%s', 'service-crew' ) ),
							esc_html( number_format_i18n( $unit_price, 2 ) )
						);
						?>
					</span>
					<span class="sc-services-list__addon-flag sc-services-list__addon-flag--<?php echo esc_attr( $required ? 'required' : 'optional' ); ?>">
						<?php echo esc_html( $required ? __( 'Required', 'service-crew' ) : __( 'Optional', 'service-crew' ) ); ?>
					</span>
					<?php if ( ! empty( $tiers ) ) : ?>
						<span class="sc-services-list__addon-tiers">
							<?php
							$tier_strings = array();
							foreach ( $tiers as $tier ) {
								$min_qty = isset( $tier['min_qty'] ) ? (int) $tier['min_qty'] : 0;
								$value   = isset( $tier['discount_value'] ) ? (float) $tier['discount_value'] : 0;
								$is_percent = ! isset( $tier['discount_type'] ) || 'fixed' !== $tier['discount_type'];

								$tier_strings[] = sprintf(
									/* translators: 1: minimum quantity, 2: discount amount or percent */
									__( '%1$d+: %2$s off', 'service-crew' ),
									$min_qty,
									$is_percent ? $value . '%' : '$' . number_format_i18n( $value, 2 )
								);
							}
							echo esc_html( implode( ', ', $tier_strings ) );
							?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return ob_get_clean();
	}

	/**
	 * Gets the published, menu_order/title-sorted direct children of a
	 * service (or the top-level services, for $parent_id 0).
	 *
	 * @param int $parent_id Parent post ID, or 0 for top-level.
	 * @return WP_Post[]
	 */
	private function get_published_children( $parent_id ) {
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
