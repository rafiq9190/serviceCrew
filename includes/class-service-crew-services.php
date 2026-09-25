<?php
/**
 * Registers the sc_service custom post type and the ServiceCrew top-level
 * admin menu. Services are managed entirely through the custom admin app
 * (Service_Crew_Admin_App + Service_Crew_Services_Controller) — this class
 * owns the post type/hierarchy plumbing and the pricing-field sanitizers the
 * REST controller calls, not any admin screen of its own.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * sc_service is not publicly queryable, and — since the custom admin app
 * replaced the native post editor for it — not shown in the native admin UI
 * either (`show_ui => false` below). The only way in is
 * Service_Crew_Services_Controller's REST routes. This class also creates
 * the "ServiceCrew" top-level admin menu since, as of this task, no other
 * class has created it yet; Service_Crew_Admin_App attaches the Services
 * and Discounts submenus to the same slug.
 *
 * A service with sub-services is a category only: it has no price of its
 * own (see sanitize_pricing_fields()) — the customer buys a specific
 * sub-service, never the category.
 */
class Service_Crew_Services {

	/**
	 * Meta key for the service's own price (decimal). Flat price when
	 * META_PRICE_MODE is 'flat', price-per-unit when 'per_unit'.
	 *
	 * @var string
	 */
	const META_PRICE = '_sc_price';

	/**
	 * Meta key for the service's own duration in minutes (int). Flat
	 * duration or duration-per-unit, same split as META_PRICE.
	 *
	 * @var string
	 */
	const META_DURATION_MINUTES = '_sc_duration_minutes';

	/**
	 * Meta key for the price mode: PRICE_MODE_FLAT or PRICE_MODE_PER_UNIT.
	 *
	 * @var string
	 */
	const META_PRICE_MODE = '_sc_price_mode';

	/**
	 * Meta key for the per-unit label shown to the customer (e.g. "room",
	 * "window"). Only meaningful in per-unit mode.
	 *
	 * @var string
	 */
	const META_UNIT_LABEL = '_sc_unit_label';

	/**
	 * Meta key for the minimum quantity a customer can pick in per-unit
	 * mode — also where their picker starts; there is no maximum, by
	 * request, so no separate "default"/"starts at" concept is needed.
	 *
	 * @var string
	 */
	const META_UNIT_QTY_MIN = '_sc_unit_qty_min';

	/**
	 * Price mode: a single flat price regardless of quantity.
	 *
	 * @var string
	 */
	const PRICE_MODE_FLAT = 'flat';

	/**
	 * Price mode: total = unit price × a quantity the customer chooses (e.g.
	 * "$30 per room"). Same total = unit × quantity formula Add-ons use.
	 *
	 * @var string
	 */
	const PRICE_MODE_PER_UNIT = 'per_unit';

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap (service_crew_init() in service-crew.php).
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
	}

	/**
	 * Registers the sc_service custom post type.
	 *
	 * Not publicly queryable and not shown in the native admin UI
	 * (`show_ui => false`) — Service_Crew_Admin_App's custom screens and
	 * Service_Crew_Services_Controller's REST routes are the only way
	 * services are read or written. Still hierarchical (a service can have
	 * sub-services) and capability-gated the same as before, since REST
	 * permission checks reuse these same capabilities.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Services', 'post type general name', 'service-crew' ),
			'singular_name'      => _x( 'Service', 'post type singular name', 'service-crew' ),
			'menu_name'          => _x( 'Services', 'admin menu', 'service-crew' ),
			'name_admin_bar'     => _x( 'Service', 'add new on admin bar', 'service-crew' ),
			'add_new'            => _x( 'Add New', 'service', 'service-crew' ),
			'add_new_item'       => __( 'Add New Service', 'service-crew' ),
			'new_item'           => __( 'New Service', 'service-crew' ),
			'edit_item'          => __( 'Edit Service', 'service-crew' ),
			'view_item'          => __( 'View Service', 'service-crew' ),
			'all_items'          => __( 'Services', 'service-crew' ),
			'search_items'       => __( 'Search Services', 'service-crew' ),
			'not_found'          => __( 'No services found.', 'service-crew' ),
			'not_found_in_trash' => __( 'No services found in Trash.', 'service-crew' ),
		);

		/*
		 * Admin-only by capability, not just by menu placement. Every
		 * primitive capability is mapped to manage_options so REST
		 * permission checks (current_user_can('edit_post'/'delete_post', id)
		 * and current_user_can('manage_options') for list/create) require
		 * exactly the same access level regardless of show_ui.
		 *
		 * edit_post/read_post/delete_post use unique capability names, not
		 * 'manage_options' directly — see the identical note in
		 * Service_Crew_Crew::register_post_type(). Reusing a core capability
		 * string there hijacks it site-wide via WordPress's
		 * $post_type_meta_caps map. The administrator role is granted these
		 * three custom caps in Service_Crew_Activator::register_roles().
		 */
		$capabilities = array(
			'edit_post'              => 'edit_sc_service',
			'read_post'              => 'read_sc_service',
			'delete_post'            => 'delete_sc_service',
			'edit_posts'             => 'manage_options',
			'edit_others_posts'      => 'manage_options',
			'publish_posts'          => 'manage_options',
			'read_private_posts'     => 'manage_options',
			'delete_posts'           => 'manage_options',
			'delete_private_posts'   => 'manage_options',
			'delete_published_posts' => 'manage_options',
			'delete_others_posts'    => 'manage_options',
			'edit_private_posts'     => 'manage_options',
			'edit_published_posts'   => 'manage_options',
			'create_posts'           => 'manage_options',
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'capabilities'        => $capabilities,
			'map_meta_cap'        => true,
			'has_archive'         => false,
			// Hierarchical so a service can have sub-services (child
			// sc_service posts) — each an independently priced post, not a
			// meta field on the parent.
			'hierarchical'        => true,
			'supports'            => array( 'title' ),
		);

		register_post_type( 'sc_service', $args );
	}

	/**
	 * Creates the "ServiceCrew" top-level admin menu.
	 *
	 * Nothing else has created this menu yet, so this class owns it.
	 * Service_Crew_Admin_App (Services/Discounts) and Service_Crew_Crew
	 * (its own CPT menu entry) attach their own submenus to the same
	 * 'service-crew' slug instead of each creating a separate top-level
	 * entry.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'ServiceCrew', 'service-crew' ),
			__( 'ServiceCrew', 'service-crew' ),
			'manage_options',
			'service-crew',
			array( $this, 'render_admin_landing_page' ),
			'dashicons-groups',
			26
		);

		// add_menu_page() also inserts a duplicate first submenu labelled
		// "ServiceCrew"; drop it so the menu only shows real submenus.
		remove_submenu_page( 'service-crew', 'service-crew' );
	}

	/**
	 * Renders the ServiceCrew top-level landing page.
	 *
	 * Placeholder content only — a real dashboard/settings screen is a
	 * separate, not-yet-built Phase 1a task.
	 *
	 * @return void
	 */
	public function render_admin_landing_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'ServiceCrew', 'service-crew' ) . '</h1>';
		echo '<p>' . esc_html__( 'Manage services, crew, bookings and settings from this menu.', 'service-crew' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Whether a service has any sub-services (child sc_service posts, any
	 * status except trash). A category (has_children === true) has no price
	 * or add-ons of its own — see sanitize_pricing_fields() and
	 * Service_Crew_Components::apply().
	 *
	 * @param int $post_id Parent post ID.
	 * @return bool
	 */
	public static function has_child_services( $post_id ) {
		$children = get_posts(
			array(
				'post_type'      => 'sc_service',
				'post_parent'    => $post_id,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $children );
	}

	/**
	 * Collects a post's own ID plus every descendant's ID, so a parent
	 * picker never offers a choice that would create a cycle (a service
	 * can't become a sub-service of its own sub-service).
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function get_self_and_descendant_ids( $post_id ) {
		$ids = array( $post_id );

		$children = get_posts(
			array(
				'post_type'      => 'sc_service',
				'post_parent'    => $post_id,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $children as $child_id ) {
			$ids = array_merge( $ids, self::get_self_and_descendant_ids( $child_id ) );
		}

		return $ids;
	}

	/**
	 * Builds a "Grandparent → Parent → This" breadcrumb string, so a
	 * deeply-nested sub-service is still identifiable in a parent picker
	 * without the admin having to guess from the title alone.
	 *
	 * @param WP_Post $post Post to build a breadcrumb for.
	 * @return string Plain text (not yet escaped for HTML output).
	 */
	public static function build_breadcrumb( $post ) {
		$names = array();

		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
			$names[] = self::get_plain_title( $ancestor_id );
		}

		$names[] = self::get_plain_title( $post );

		return implode( ' → ', $names );
	}

	/**
	 * A service's title as plain text, safe to hand to JS's
	 * `textContent`/`.value` (which never decode HTML entities themselves).
	 *
	 * get_the_title() runs the `the_title` filter, which includes core's
	 * convert_chars() — it HTML-entity-encodes a bare "&" into "&#038;" so
	 * the string is safe to drop straight into markup. Every consumer of a
	 * service title in this plugin is a JSON API or a Stripe line-item name,
	 * never raw HTML output, so that encoding is never undone downstream —
	 * without this, any title containing "&" (or one saved with a literal
	 * "&amp;" already typed into it) renders on screen exactly as broken as
	 * "Lawn Mowing &amp; Yard Care". html_entity_decode() reverses whatever
	 * entity form the title ended up in, encoded or not.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string
	 */
	public static function get_plain_title( $post ) {
		return html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Sanitizes posted pricing fields for a service. A category
	 * ($has_children === true) always resolves to an all-null result — its
	 * pricing meta must be entirely absent, not just hidden in the UI, so a
	 * category can never carry a stale price. See apply_pricing_meta().
	 *
	 * @param array $input        Raw posted pricing fields (already decoded from JSON by the caller).
	 * @param bool  $has_children Whether the service currently has sub-services.
	 * @return array<string,mixed> Meta key => value; a null value means "delete this meta key".
	 */
	public static function sanitize_pricing_fields( array $input, $has_children ) {
		if ( $has_children ) {
			return array(
				self::META_PRICE            => null,
				self::META_DURATION_MINUTES => null,
				self::META_PRICE_MODE       => null,
				self::META_UNIT_LABEL       => null,
				self::META_UNIT_QTY_MIN     => null,
			);
		}

		$price_mode = isset( $input['price_mode'] ) && self::PRICE_MODE_PER_UNIT === $input['price_mode']
			? self::PRICE_MODE_PER_UNIT
			: self::PRICE_MODE_FLAT;

		$sanitized = array(
			self::META_PRICE            => max( 0, (float) ( $input['price'] ?? 0 ) ),
			self::META_DURATION_MINUTES => max( 0, absint( $input['duration_minutes'] ?? 0 ) ),
			self::META_PRICE_MODE       => $price_mode,
		);

		if ( self::PRICE_MODE_PER_UNIT === $price_mode ) {
			$sanitized[ self::META_UNIT_LABEL ]   = sanitize_text_field( $input['unit_label'] ?? '' );
			$sanitized[ self::META_UNIT_QTY_MIN ] = max( 1, absint( $input['unit_qty_min'] ?? 1 ) );
		} else {
			$sanitized[ self::META_UNIT_LABEL ]   = null;
			$sanitized[ self::META_UNIT_QTY_MIN ] = null;
		}

		return $sanitized;
	}

	/**
	 * Writes (or clears) a sanitize_pricing_fields() result to postmeta.
	 *
	 * @param int   $post_id   Service post ID.
	 * @param array $sanitized Result of sanitize_pricing_fields().
	 * @return void
	 */
	public static function apply_pricing_meta( $post_id, array $sanitized ) {
		foreach ( $sanitized as $meta_key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Reads a service's pricing fields for a REST response. Always resolves
	 * to all-null for a category, even if stale meta somehow still exists
	 * (e.g. a direct DB edit), so callers never have to re-check has_children
	 * themselves before trusting these values.
	 *
	 * @param int  $post_id      Service post ID.
	 * @param bool $has_children Whether the service currently has sub-services.
	 * @return array<string,mixed>
	 */
	public static function get_pricing_fields( $post_id, $has_children ) {
		if ( $has_children ) {
			return array(
				'price_mode'       => null,
				'price'            => null,
				'duration_minutes' => null,
				'unit_label'       => null,
				'unit_qty_min'     => null,
			);
		}

		$price_mode = get_post_meta( $post_id, self::META_PRICE_MODE, true );
		if ( self::PRICE_MODE_PER_UNIT !== $price_mode ) {
			$price_mode = self::PRICE_MODE_FLAT;
		}

		$fields = array(
			'price_mode'       => $price_mode,
			'price'            => (float) get_post_meta( $post_id, self::META_PRICE, true ),
			'duration_minutes' => (int) get_post_meta( $post_id, self::META_DURATION_MINUTES, true ),
			'unit_label'       => null,
			'unit_qty_min'     => null,
		);

		if ( self::PRICE_MODE_PER_UNIT === $price_mode ) {
			$fields['unit_label']   = get_post_meta( $post_id, self::META_UNIT_LABEL, true );
			$fields['unit_qty_min'] = (int) get_post_meta( $post_id, self::META_UNIT_QTY_MIN, true );
		}

		return $fields;
	}
}
