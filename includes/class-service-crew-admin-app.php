<?php
/**
 * Custom admin app shell for Services, Discounts, Payments, Emails,
 * Appearance, Settings, Bookings, Customers and Agent — replaces the native
 * post-editor screens for sc_service. Each submenu renders a bare container
 * div; all rendering/interaction happens client-side against
 * Service_Crew_Services_Controller / Service_Crew_Discounts /
 * Service_Crew_Payments / Service_Crew_Emails / Service_Crew_Appearance /
 * Service_Crew_Settings / Service_Crew_Bookings_Controller /
 * Service_Crew_Customers_Controller / Service_Crew_Agent_Controller /
 * Service_Crew_Agent_Settings's REST routes via wp-api-fetch (core's own
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
	 * Hook suffix for the Payments submenu page (see $hook_services).
	 *
	 * @var string
	 */
	private $hook_payments;

	/**
	 * Hook suffix for the Emails submenu page (see $hook_services).
	 *
	 * @var string
	 */
	private $hook_emails;

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
	 * Hook suffix for the Customers submenu page (see $hook_services).
	 *
	 * @var string
	 */
	private $hook_customers;

	/**
	 * Hook suffix for the Agent submenu page (see $hook_services).
	 *
	 * @var string
	 */
	private $hook_agent;

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Attaches every custom admin app submenu to the existing 'service-crew'
	 * top-level menu (created by Service_Crew_Services).
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

		$this->hook_payments = add_submenu_page(
			'service-crew',
			__( 'Payments', 'service-crew' ),
			__( 'Payments', 'service-crew' ),
			'manage_options',
			'service-crew-payments',
			array( $this, 'render_payments_page' )
		);

		$this->hook_emails = add_submenu_page(
			'service-crew',
			__( 'Emails', 'service-crew' ),
			__( 'Emails', 'service-crew' ),
			'manage_options',
			'service-crew-emails',
			array( $this, 'render_emails_page' )
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

		$this->hook_customers = add_submenu_page(
			'service-crew',
			__( 'Customers', 'service-crew' ),
			__( 'Customers', 'service-crew' ),
			'manage_options',
			'service-crew-customers',
			array( $this, 'render_customers_page' )
		);

		$this->hook_agent = add_submenu_page(
			'service-crew',
			__( 'Agent', 'service-crew' ),
			__( 'Agent', 'service-crew' ),
			'manage_options',
			'service-crew-agent',
			array( $this, 'render_agent_page' )
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
	 * Renders the Payments app container.
	 *
	 * @return void
	 */
	public function render_payments_page() {
		echo '<div class="wrap">';
		$this->render_header( 'payments' );
		echo '<div id="sc-app-root" data-view="payments"></div></div>';
	}

	/**
	 * Renders the Emails app container.
	 *
	 * @return void
	 */
	public function render_emails_page() {
		echo '<div class="wrap">';
		$this->render_header( 'emails' );
		echo '<div id="sc-app-root" data-view="emails"></div></div>';
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
	 * Renders the Customers app container.
	 *
	 * @return void
	 */
	public function render_customers_page() {
		echo '<div class="wrap">';
		$this->render_header( 'customers' );
		echo '<div id="sc-app-root" data-view="customers"></div></div>';
	}

	/**
	 * Renders the Agent app container.
	 *
	 * @return void
	 */
	public function render_agent_page() {
		echo '<div class="wrap">';
		$this->render_header( 'agent' );
		echo '<div id="sc-app-root" data-view="agent"></div></div>';
	}

	/**
	 * Renders the header shared by every app screen — brand mark, the
	 * notification bell (static markup only; admin/js/app-core.js fills in
	 * the badge count and dropdown list on every screen this renders on),
	 * and a tab per screen. Server-rendered and deliberately outside
	 * #sc-app-root (each screen's own JS wipes and rebuilds that element's
	 * contents on every interaction; a header inside it would flash/
	 * disappear on every re-render). Plain page navigation (real hrefs), not
	 * client-side routing — these are separate wp-admin pages.
	 *
	 * @param string $active_view One of 'services', 'discounts', 'payments', 'emails', 'appearance', 'settings', 'bookings', 'customers', 'agent'.
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
			'payments'   => array(
				'label' => __( 'Payments', 'service-crew' ),
				'page'  => 'service-crew-payments',
			),
			'emails'     => array(
				'label' => __( 'Emails', 'service-crew' ),
				'page'  => 'service-crew-emails',
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
			'customers'  => array(
				'label' => __( 'Customers', 'service-crew' ),
				'page'  => 'service-crew-customers',
			),
			'agent'      => array(
				'label' => __( 'Agent', 'service-crew' ),
				'page'  => 'service-crew-agent',
			),
		);
		?>
		<div class="sc-app-header">
			<div class="sc-app-header__brand">
				<span class="dashicons dashicons-groups sc-app-header__icon" aria-hidden="true"></span>
				<span class="sc-app-header__title"><?php esc_html_e( 'ServiceCrew', 'service-crew' ); ?></span>
			</div>
			<nav class="sc-app-header__nav">
				<div class="sc-bell-wrap">
					<button type="button" class="sc-bell" aria-label="<?php esc_attr_e( 'Notifications', 'service-crew' ); ?>">
						<span class="dashicons dashicons-bell" aria-hidden="true"></span>
						<span class="sc-bell__badge" style="display: none;">0</span>
					</button>
					<div class="sc-bell-dropdown" hidden>
						<div class="sc-bell-dropdown__list"></div>
						<a class="sc-bell-dropdown__viewall" href="<?php echo esc_url( admin_url( 'admin.php?page=service-crew-bookings' ) ); ?>"><?php esc_html_e( 'View all bookings', 'service-crew' ); ?></a>
					</div>
				</div>
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
	 * Enqueues the shared app CSS/JS only on the screens registered above.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$app_hooks = array( $this->hook_services, $this->hook_discounts, $this->hook_payments, $this->hook_emails, $this->hook_appearance, $this->hook_settings, $this->hook_bookings, $this->hook_customers, $this->hook_agent );

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

		if ( $this->hook_payments === $hook_suffix ) {
			wp_enqueue_script(
				'sc-admin-app-payments',
				SERVICE_CREW_PLUGIN_URL . 'admin/js/app-payments.js',
				array( 'sc-admin-app-core' ),
				SERVICE_CREW_VERSION,
				true
			);
		}

		if ( $this->hook_emails === $hook_suffix ) {
			wp_enqueue_script(
				'sc-admin-app-emails',
				SERVICE_CREW_PLUGIN_URL . 'admin/js/app-emails.js',
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

		if ( $this->hook_customers === $hook_suffix ) {
			wp_enqueue_script(
				'sc-admin-app-customers',
				SERVICE_CREW_PLUGIN_URL . 'admin/js/app-customers.js',
				array( 'sc-admin-app-core' ),
				SERVICE_CREW_VERSION,
				true
			);
		}

		if ( $this->hook_agent === $hook_suffix ) {
			wp_enqueue_script(
				'sc-admin-app-agent',
				SERVICE_CREW_PLUGIN_URL . 'admin/js/app-agent.js',
				array( 'sc-admin-app-core' ),
				SERVICE_CREW_VERSION,
				true
			);
		}
	}
}
