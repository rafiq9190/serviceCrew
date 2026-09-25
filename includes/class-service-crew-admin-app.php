<?php
/**
 * Custom admin app shell for Services, Discounts, Appearance, Settings and
 * Bookings — replaces the native post-editor screens for sc_service. Each
 * submenu renders a bare container div; all rendering/interaction happens
 * client-side against Service_Crew_Services_Controller /
 * Service_Crew_Discounts / Service_Crew_Appearance / Service_Crew_Settings /
 * Service_Crew_Bookings_Controller's REST routes via wp-api-fetch (core's own
 * REST client — it handles the nonce and root URL for us, no hand-rolled
 * AJAX plumbing needed).
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Admin_App {

	/**
	 * Hook suffix for the Services submenu page, captured from
	 * add_submenu_page()'s own return value in register_menu() rather than
	 * guessed — get_plugin_page_hookname() prefixes a custom top-level page's
	 * submenus with sanitize_title() of the top-level menu's *title*, not its
	 * *slug*, which is easy to get wrong hardcoding it by hand.
	 *
	 * @var string
	 */
	private $hook_services;

	/**
	 * Hook suffix for the Discounts submenu page (see $hook_services).
	 *
	 * @var string
	 */
	private $hook_discounts;

	/**
	 * Hook suffix for the Appearance submenu page (see $hook_services).
	 *
	 * @var string
	 */
	private $hook_appearance;

	/**
	 * Hook suffix for the Settings submenu page (see $hook_services).
	 *
	 * @var string
	 */
	private $hook_settings;

	/**
	 * Hook suffix for the Bookings submenu page (see $hook_services).
	 *
	 * @var string
	 */
	private $hook_bookings;

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Attaches the Services and Discounts submenus to the existing
	 * 'service-crew' top-level menu (created by Service_Crew_Services).
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_services = add_submenu_page(
			'service-crew',
			__( 'Services', 'service-crew' ),
			__( 'Services', 'service-crew' ),
			'manage_options',
			'service-crew-services',
			array( $this, 'render_services_page' )
		);

		$this->hook_discounts = add_submenu_page(
			'service-crew',
			__( 'Discounts', 'service-crew' ),
			__( 'Discounts', 'service-crew' ),
			'manage_options',
			'service-crew-discounts',
			array( $this, 'render_discounts_page' )
		);

		$this->hook_appearance = add_submenu_page(
			'service-crew',
			__( 'Appearance', 'service-crew' ),
			__( 'Appearance', 'service-crew' ),
			'manage_options',
			'service-crew-appearance',
			array( $this, 'render_appearance_page' )
		);

		$this->hook_settings = add_submenu_page(
			'service-crew',
			__( 'Settings', 'service-crew' ),
			__( 'Settings', 'service-crew' ),
			'manage_options',
			'service-crew-settings',
			array( $this, 'render_settings_page' )
		);

		$this->hook_bookings = add_submenu_page(
			'service-crew',
			__( 'Bookings', 'service-crew' ),
			__( 'Bookings', 'service-crew' ),
			'manage_options',
			'service-crew-bookings',
			array( $this, 'render_bookings_page' )
		);
	}

	/**
	 * Renders the Services app container. admin/js/app-services.js reads
	 * data-view to decide whether to run.
	 *
	 * @return void
	 */
	public function render_services_page() {
		echo '<div class="wrap">';
		$this->render_header( 'services' );
		echo '<div id="sc-app-root" data-view="services"></div></div>';
	}

	/**
	 * Renders the Discounts app container.
	 *
	 * @return void
	 */
	public function render_discounts_page() {
		echo '<div class="wrap">';
		$this->render_header( 'discounts' );
		echo '<div id="sc-app-root" data-view="discounts"></div></div>';
	}

	/**
	 * Renders the Appearance app container.
	 *
	 * @return void
	 */
	public function render_appearance_page() {
		echo '<div class="wrap">';
		$this->render_header( 'appearance' );
		echo '<div id="sc-app-root" data-view="appearance"></div></div>';
	}

	/**
	 * Renders the Settings app container.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		echo '<div class="wrap">';
		$this->render_header( 'settings' );
		echo '<div id="sc-app-root" data-view="settings"></div></div>';
	}

	/**
	 * Renders the Bookings app container.
	 *
	 * @return void
	 */
	public function render_bookings_page() {
		echo '<div class="wrap">';
		$this->render_header( 'bookings' );
		echo '<div id="sc-app-root" data-view="bookings"></div></div>';
	}

	/**
	 * Renders the header shared by all four app screens — brand mark plus a
	 * tab per screen. Server-rendered and deliberately outside #sc-app-root
	 * (each screen's own JS wipes and rebuilds that element's contents on
	 * every interaction; a header inside it would flash/disappear on every
	 * re-render). Plain page navigation (real hrefs), not client-side
	 * routing — these are four separate wp-admin pages.
	 *
	 * @param string $active_view One of 'services', 'discounts', 'appearance', 'settings', 'bookings'.
	 * @return void
	 */
	private function render_header( $active_view ) {
		$tabs = array(
			'services'   => array(
				'label' => __( 'Services', 'service-crew' ),
				'page'  => 'service-crew-services',
			),
			'discounts'  => array(
				'label' => __( 'Discounts', 'service-crew' ),
				'page'  => 'service-crew-discounts',
			),
			'appearance' => array(
				'label' => __( 'Appearance', 'service-crew' ),
				'page'  => 'service-crew-appearance',
			),
			'settings'   => array(
				'label' => __( 'Settings', 'service-crew' ),
				'page'  => 'service-crew-settings',
			),
			'bookings'   => array(
				'label' => __( 'Bookings', 'service-crew' ),
				'page'  => 'service-crew-bookings',
			),
		);
		?>
		<div class="sc-app-header">
			<div class="sc-app-header__brand">
				<span class="dashicons dashicons-groups sc-app-header__icon" aria-hidden="true"></span>
				<span class="sc-app-header__title"><?php esc_html_e( 'ServiceCrew', 'service-crew' ); ?></span>
			</div>
			<nav class="sc-app-header__nav">
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . $tab['page'] ) ); ?>"
						class="sc-app-header__tab<?php echo $key === $active_view ? ' is-active' : ''; ?>"
					>
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * Enqueues the shared app CSS/JS only on the four screens above.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$app_hooks = array( $this->hook_services, $this->hook_discounts, $this->hook_appearance, $this->hook_settings, $this->hook_bookings );

		if ( ! in_array( $hook_suffix, $app_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'sc-admin-app',
			SERVICE_CREW_PLUGIN_URL . 'admin/css/app.css',
			array(),
			SERVICE_CREW_VERSION
		);

		wp_enqueue_script( 'wp-api-fetch' );

		wp_enqueue_script(
			'sc-admin-app-core',
			SERVICE_CREW_PLUGIN_URL . 'admin/js/app-core.js',
			array( 'wp-api-fetch' ),
			SERVICE_CREW_VERSION,
			true
		);

		/*
		 * Standard WP pattern for admin-side REST calls: register the nonce
		 * and root-URL middleware once, before any app script runs, rather
		 * than hand-rolling fetch headers/URLs ourselves.
		 */
		wp_add_inline_script(
			'sc-admin-app-core',
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) ); wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( %s ) );',
				wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
				wp_json_encode( esc_url_raw( rest_url() ) )
			),
			'before'
		);

		if ( $this->hook_services === $hook_suffix ) {
			wp_enqueue_script(
				'sc-admin-app-services',
				SERVICE_CREW_PLUGIN_URL . 'admin/js/app-services.js',
				array( 'sc-admin-app-core' ),
				SERVICE_CREW_VERSION,
				true
			);
		}

		if ( $this->hook_discounts === $hook_suffix ) {
			wp_enqueue_script(
				'sc-admin-app-discounts',
				SERVICE_CREW_PLUGIN_URL . 'admin/js/app-discounts.js',
				array( 'sc-admin-app-core' ),
				SERVICE_CREW_VERSION,
				true
			);
		}

		if ( $this->hook_appearance === $hook_suffix ) {
			// wp-color-picker (core, ships with every WP install) gives a real
			// picker UI instead of a bare <input type="color"> swatch.
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script(
				'sc-admin-app-appearance',
				SERVICE_CREW_PLUGIN_URL . 'admin/js/app-appearance.js',
				array( 'sc-admin-app-core', 'wp-color-picker' ),
				SERVICE_CREW_VERSION,
				true
			);
		}

		if ( $this->hook_settings === $hook_suffix ) {
			wp_enqueue_script(
				'sc-admin-app-settings',
				SERVICE_CREW_PLUGIN_URL . 'admin/js/app-settings.js',
				array( 'sc-admin-app-core' ),
				SERVICE_CREW_VERSION,
				true
			);
		}

		if ( $this->hook_bookings === $hook_suffix ) {
			wp_enqueue_script(
				'sc-admin-app-bookings',
				SERVICE_CREW_PLUGIN_URL . 'admin/js/app-bookings.js',
				array( 'sc-admin-app-core' ),
				SERVICE_CREW_VERSION,
				true
			);
		}
	}
}
