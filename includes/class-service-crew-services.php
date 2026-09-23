<?php
/**
 * Registers the sc_service custom post type, the ServiceCrew top-level admin
 * menu, and the base pricing/crew meta box for a service.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * sc_service is not publicly queryable — the front end fetches services via
 * a REST controller (a later Phase 1a task), not a public archive/single
 * template. This class also creates the "ServiceCrew" top-level admin menu
 * since, as of this task, no other class has created it yet; later admin
 * screens (sc_crew, settings, setup wizard, bookings board, etc.) attach
 * their own submenus to the same 'service-crew' slug rather than each
 * creating their own top-level entry.
 */
class Service_Crew_Services {

	/**
	 * Meta key for the service's base price (decimal).
	 *
	 * @var string
	 */
	const META_PRICE = '_sc_price';

	/**
	 * Meta key for the service's base duration in minutes (int).
	 *
	 * @var string
	 */
	const META_DURATION_MINUTES = '_sc_duration_minutes';

	/**
	 * Meta key for how many crew a booking of this service needs by default
	 * (int, default 1; adjustable per booking per the plan).
	 *
	 * @var string
	 */
	const META_CREW_NEEDED = '_sc_crew_needed';

	/**
	 * Nonce action/field name for the meta box save.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'sc_service_save_meta';
	const NONCE_NAME   = 'sc_service_meta_nonce';

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap (service_crew_init() in service-crew.php); everything
	 * past construction — post type args, menu registration, meta box
	 * markup, save handling — lives in this class, not the bootstrap.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_sc_service', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'post_row_actions', array( $this, 'add_sub_service_row_action' ), 10, 2 );
	}

	/**
	 * Adds an "Add sub-service" row action next to Edit/Trash on the
	 * Services list table, so starting a sub-service doesn't require
	 * opening the parent first — same destination as the "+ Add a
	 * sub-service" button in render_children_meta_box().
	 *
	 * @param string[] $actions Existing row actions, keyed by action name.
	 * @param WP_Post  $post    The row's post.
	 * @return string[] Filtered row actions.
	 */
	public function add_sub_service_row_action( $actions, $post ) {
		if ( 'sc_service' !== $post->post_type ) {
			return $actions;
		}

		$actions['sc_add_sub_service'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'post-new.php?post_type=sc_service&sc_parent=' . $post->ID ) ),
			esc_html__( 'Add sub-service', 'service-crew' )
		);

		return $actions;
	}

	/**
	 * Enqueues the shared admin stylesheet on the sc_service add/edit
	 * screen only. Service_Crew_Components enqueues the same handle for its
	 * own meta box on the same screen; WordPress dedupes by handle so it
	 * only loads once.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'sc_service' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'sc-admin',
			SERVICE_CREW_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			SERVICE_CREW_VERSION
		);
	}

	/**
	 * Registers the sc_service custom post type.
	 *
	 * Not publicly queryable: services are read by the admin UI and, later,
	 * by an admin-gated REST controller — never by a public archive/single
	 * template. Only 'title' is supported (post_title is the service name);
	 * no editor/thumbnail support without a concrete need for one.
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
		 * Admin-only by capability, not just by menu placement. Without this,
		 * sc_service defaults to sharing core Post capabilities, so any
		 * Author/Editor could create/edit/delete services and see or change
		 * pricing (_sc_price) via wp-admin — including by direct URL to
		 * post.php — even though the top-level ServiceCrew menu above is
		 * gated to manage_options. Every primitive capability is mapped to
		 * manage_options so the CPT edit/list screens require exactly the
		 * same access level as that menu, and map_meta_cap is enabled so
		 * WordPress runs these through map_meta_cap() instead of the default
		 * "post" capability_type behavior.
		 *
		 * edit_post/read_post/delete_post use unique capability names
		 * (edit_sc_service etc.), not 'manage_options' directly: WordPress
		 * registers these three under a global $post_type_meta_caps map
		 * keyed by whatever string they're set to (_post_type_meta_capabilities()
		 * in wp-includes/post.php), so reusing 'manage_options' there hijacks
		 * every current_user_can( 'manage_options' ) check site-wide — including
		 * unrelated ones like this plugin's own admin menu — and routes it
		 * through post-specific meta-cap logic with no post in context, which
		 * always resolves to do_not_allow. The administrator role is granted
		 * these three custom caps in Service_Crew_Activator::register_roles().
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
			'show_ui'             => true,
			'show_in_menu'        => 'service-crew',
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'capabilities'        => $capabilities,
			'map_meta_cap'        => true,
			'has_archive'         => false,
			/*
			 * Hierarchical so a service can have sub-services (child
			 * sc_service posts) — each an independently priced post, not a
			 * meta field on the parent. A parent with children has no price
			 * of its own; see the notice in render_meta_box(). No
			 * 'page-attributes' support: core's Page Attributes box (parent
			 * dropdown + menu order number) is confusing for a non-technical
			 * admin, so render_parent_meta_box() replaces it with a single
			 * plain-language dropdown instead.
			 */
			'hierarchical'        => true,
			'supports'            => array( 'title' ),
		);

		register_post_type( 'sc_service', $args );
	}

	/**
	 * Creates the "ServiceCrew" top-level admin menu.
	 *
	 * Nothing else has created this menu yet, so this class owns it. Future
	 * admin screens (sc_crew CPT, settings, setup wizard, bookings board)
	 * add their own submenus under the same 'service-crew' slug instead of
	 * each creating a separate top-level entry.
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
		// "ServiceCrew"; drop it so the menu only shows real submenus
		// (Services now, sc_crew / settings / setup wizard later).
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
	 * Registers the "Sub-service of", "Price, Duration & Crew" and
	 * "Sub-services" meta boxes on the sc_service edit screen.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'sc_service_parent',
			__( 'Sub-service of', 'service-crew' ),
			array( $this, 'render_parent_meta_box' ),
			'sc_service',
			'side',
			'high'
		);

		add_meta_box(
			'sc_service_pricing',
			__( 'Price, Duration & Crew', 'service-crew' ),
			array( $this, 'render_meta_box' ),
			'sc_service',
			'normal',
			'high'
		);

		add_meta_box(
			'sc_service_children',
			__( 'Sub-services', 'service-crew' ),
			array( $this, 'render_children_meta_box' ),
			'sc_service',
			'normal',
			'default'
		);
	}

	/**
	 * Renders a single plain-language "which service is this part of?"
	 * dropdown, replacing core's Page Attributes box (parent dropdown +
	 * menu order number, written for developers/editors, not a
	 * non-technical admin).
	 *
	 * Posts to `parent_id` — the same field name core's own Page Attributes
	 * box would use — so WordPress's normal save handling
	 * (wp-admin/includes/post.php) picks it up and sets post_parent
	 * automatically; this class needs no save code of its own for it.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_parent_meta_box( $post ) {
		$excluded_ids = $post->ID ? $this->get_self_and_descendant_ids( $post->ID ) : array();

		$candidates = get_posts(
			array(
				'post_type'      => 'sc_service',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'exclude'        => $excluded_ids,
			)
		);

		/*
		 * A brand-new post is always post_parent = 0 (WordPress creates the
		 * auto-draft before this box ever renders, so there's no earlier
		 * point to set it). If it got here via the "+ Add a sub-service"
		 * button/row action (render_children_meta_box() /
		 * add_sub_service_row_action()), ?sc_parent=<id> says which service
		 * to preselect instead — read-only prefill for display, not a
		 * state-changing action, so no nonce needed.
		 */
		$selected_parent_id = (int) $post->post_parent;
		if ( 'auto-draft' === $post->post_status && isset( $_GET['sc_parent'] ) ) {
			$selected_parent_id = absint( wp_unslash( $_GET['sc_parent'] ) );
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Is this one option within a bigger service (e.g. "Deep Clean" is part of "House Cleaning")? Choose it below. Otherwise leave this as "No parent".', 'service-crew' ); ?>
		</p>
		<select name="parent_id" id="sc_service_parent" class="sc-full-width-select">
			<option value="0" <?php selected( $selected_parent_id, 0 ); ?>><?php esc_html_e( '— No parent (standalone service) —', 'service-crew' ); ?></option>
			<?php foreach ( $candidates as $candidate ) : ?>
				<option value="<?php echo esc_attr( $candidate->ID ); ?>" <?php selected( $selected_parent_id, $candidate->ID ); ?>>
					<?php echo esc_html( $this->build_breadcrumb( $candidate ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Collects a post's own ID plus every descendant's ID, so the parent
	 * dropdown never offers a choice that would create a cycle (a service
	 * can't become a sub-service of its own sub-service).
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private function get_self_and_descendant_ids( $post_id ) {
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
			$ids = array_merge( $ids, $this->get_self_and_descendant_ids( $child_id ) );
		}

		return $ids;
	}

	/**
	 * Builds a "Grandparent → Parent → This" breadcrumb string for a
	 * dropdown option, so a deeply-nested sub-service is still identifiable
	 * without the admin having to guess from the title alone.
	 *
	 * @param WP_Post $post Post to build a breadcrumb for.
	 * @return string Plain text (not yet escaped for HTML output).
	 */
	private function build_breadcrumb( $post ) {
		$names = array();

		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
			$names[] = get_the_title( $ancestor_id );
		}

		$names[] = get_the_title( $post );

		return implode( ' → ', $names );
	}

	/**
	 * Renders the price / duration / crew-needed fields.
	 *
	 * Add-ons (quantity counters + quantity-discount tiers) attach to this
	 * same post via their own meta box (Service_Crew_Components), and apply
	 * equally to a top-level service or a sub-service — nothing here stubs
	 * that data model. A service with sub-services (children) has no price
	 * of its own; see has_child_services()/the notice below.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		if ( $this->has_child_services( $post->ID ) ) {
			?>
			<p class="sc-notice">
				<?php esc_html_e( 'This service has sub-services, so it works as a category only. It has no price of its own — set a price on each sub-service instead.', 'service-crew' ); ?>
			</p>
			<?php
		}

		$price            = get_post_meta( $post->ID, self::META_PRICE, true );
		$duration_minutes = get_post_meta( $post->ID, self::META_DURATION_MINUTES, true );
		$crew_needed      = get_post_meta( $post->ID, self::META_CREW_NEEDED, true );

		if ( '' === $crew_needed ) {
			$crew_needed = 1;
		}
		?>
		<div class="sc-field-group">
			<label for="sc_service_price"><?php esc_html_e( 'Price ($)', 'service-crew' ); ?></label>
			<input
				type="number"
				step="0.01"
				min="0"
				id="sc_service_price"
				name="sc_service_price"
				value="<?php echo esc_attr( $price ); ?>"
				class="regular-text"
			/>
			<p class="sc-field-help"><?php esc_html_e( 'What the customer pays for this service.', 'service-crew' ); ?></p>
		</div>
		<div class="sc-field-group">
			<label for="sc_service_duration_minutes"><?php esc_html_e( 'Duration (minutes)', 'service-crew' ); ?></label>
			<input
				type="number"
				step="1"
				min="0"
				id="sc_service_duration_minutes"
				name="sc_service_duration_minutes"
				value="<?php echo esc_attr( $duration_minutes ); ?>"
				class="regular-text"
			/>
			<p class="sc-field-help"><?php esc_html_e( 'How long the job usually takes.', 'service-crew' ); ?></p>
		</div>
		<div class="sc-field-group">
			<label for="sc_service_crew_needed"><?php esc_html_e( 'Crew needed', 'service-crew' ); ?></label>
			<input
				type="number"
				step="1"
				min="1"
				id="sc_service_crew_needed"
				name="sc_service_crew_needed"
				value="<?php echo esc_attr( $crew_needed ); ?>"
				class="small-text"
			/>
			<p class="sc-field-help"><?php esc_html_e( 'How many crew members this job normally needs.', 'service-crew' ); ?></p>
		</div>
		<?php
		/*
		 * Add-ons (Service_Crew_Components) read/write their own meta key
		 * against this same post — they never touch _sc_price /
		 * _sc_duration_minutes / _sc_crew_needed.
		 */
	}

	/**
	 * Renders the list of this service's existing sub-services plus a
	 * prominent "+ Add a sub-service" button — the same pattern as the
	 * Add-ons box's "+ Add an add-on" button, so starting a sub-service
	 * doesn't require first finding the "Sub-service of" dropdown from the
	 * child's side. The button pre-selects this service as the parent via
	 * ?sc_parent=<id> (see render_parent_meta_box()).
	 *
	 * A sub-service is still its own full post (own price, own Add-ons), so
	 * this box only lists + links to them — actually editing one happens on
	 * that sub-service's own edit screen, not inline here.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_children_meta_box( $post ) {
		$children = get_posts(
			array(
				'post_type'      => 'sc_service',
				'post_parent'    => $post->ID,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
		?>
		<?php if ( empty( $children ) ) : ?>
			<p class="sc-field-help"><?php esc_html_e( 'No sub-services yet. Use this if this service is really a group of options a customer picks from (e.g. "House Cleaning" → "Standard Clean" / "Deep Clean"), each with its own price.', 'service-crew' ); ?></p>
		<?php else : ?>
			<ul class="sc-subservices-list">
				<?php foreach ( $children as $child ) : ?>
					<?php
					$price    = get_post_meta( $child->ID, self::META_PRICE, true );
					$duration = get_post_meta( $child->ID, self::META_DURATION_MINUTES, true );
					$status   = get_post_status_object( $child->post_status );
					?>
					<li class="sc-subservices-list__item">
						<a href="<?php echo esc_url( get_edit_post_link( $child->ID ) ); ?>" class="sc-subservices-list__name">
							<?php echo esc_html( get_the_title( $child ) ); ?>
						</a>
						<?php if ( '' !== $price ) : ?>
							<span class="sc-subservices-list__meta">$<?php echo esc_html( number_format_i18n( (float) $price, 2 ) ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $duration ) : ?>
							<span class="sc-subservices-list__meta">
								<?php
								printf(
									/* translators: %d: duration in minutes */
									esc_html__( '%d min', 'service-crew' ),
									(int) $duration
								);
								?>
							</span>
						<?php endif; ?>
						<?php if ( $status && 'publish' !== $child->post_status ) : ?>
							<span class="sc-subservices-list__status"><?php echo esc_html( $status->label ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=sc_service&sc_parent=' . $post->ID ) ); ?>" class="button button-primary">
				<?php esc_html_e( '+ Add a sub-service', 'service-crew' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Whether a service has any sub-services (child sc_service posts, any
	 * status except trash). Used to hide the "this service has no price"
	 * ambiguity: a category-only parent still has price/duration meta box
	 * fields rendered (so nothing is lost if children are later removed),
	 * but render_meta_box() shows a notice above them when this is true.
	 *
	 * @param int $post_id Parent post ID.
	 * @return bool
	 */
	private function has_child_services( $post_id ) {
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
	 * Saves the meta box fields, with nonce + capability check and
	 * sanitize-on-save for every field. Hooked on save_post_sc_service, so
	 * this only ever runs for the sc_service post type.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post    Post object being saved.
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['sc_service_price'] ) ) {
			$price = floatval( wp_unslash( $_POST['sc_service_price'] ) );
			update_post_meta( $post_id, self::META_PRICE, max( 0, $price ) );
		}

		if ( isset( $_POST['sc_service_duration_minutes'] ) ) {
			$duration_minutes = absint( wp_unslash( $_POST['sc_service_duration_minutes'] ) );
			update_post_meta( $post_id, self::META_DURATION_MINUTES, $duration_minutes );
		}

		if ( isset( $_POST['sc_service_crew_needed'] ) ) {
			$crew_needed = absint( wp_unslash( $_POST['sc_service_crew_needed'] ) );
			update_post_meta( $post_id, self::META_CREW_NEEDED, max( 1, $crew_needed ) );
		}
	}
}
