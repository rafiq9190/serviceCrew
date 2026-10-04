<?php
/**
 * Plugin Name:       ServiceCrew
 * Description:       Door-to-door service booking, dispatch, teams, vendors, and payments.
 * Version:           1.0.38
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ServiceCrew
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       service-crew
 * Domain Path:       /languages
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Plugin constants.
 *
 * SERVICE_CREW_DB_VERSION is separate from SERVICE_CREW_VERSION so a future
 * activator revision can bump it independently and run incremental
 * dbDelta() migrations without requiring a full plugin version bump.
 */
define( 'SERVICE_CREW_VERSION', '1.0.38' );
define( 'SERVICE_CREW_DB_VERSION', '1.8.0' );
define( 'SERVICE_CREW_PLUGIN_FILE', __FILE__ );
define( 'SERVICE_CREW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SERVICE_CREW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SERVICE_CREW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4-ish autoloader for Service_Crew_* classes living under includes/,
 * admin/, public/, blocks/ or api/ — whichever of those the class's feature
 * belongs to (see CLAUDE.md's directory convention).
 *
 * Service_Crew_Foo_Bar => <dir>/class-service-crew-foo-bar.php
 *
 * @param string $class_name Fully qualified class name being requested.
 * @return void
 */
spl_autoload_register(
	function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Service_Crew_' ) ) {
			return;
		}

		$file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

		foreach ( array( 'includes', 'admin', 'public', 'blocks', 'api' ) as $dir ) {
			$file_path = SERVICE_CREW_PLUGIN_DIR . $dir . '/' . $file_name;

			if ( file_exists( $file_path ) ) {
				require_once $file_path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'Service_Crew_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Service_Crew_Deactivator', 'deactivate' ) );

/*
 * Schema migrations for an already-active install: activate() only ever runs
 * once (on activation), so a later SERVICE_CREW_DB_VERSION bump needs its own
 * seam to actually reach a site that installed an earlier version. admin_init
 * (not plugins_loaded) so this never runs on the public, unauthenticated
 * booking-checkout endpoint — see Service_Crew_Activator::maybe_upgrade().
 */
add_action( 'admin_init', array( 'Service_Crew_Activator', 'maybe_upgrade' ) );

/**
 * Bootstraps the plugin once all plugins are loaded.
 *
 * Feature classes (CPTs, REST controllers, pricing/capacity/matching, etc.)
 * register their own WordPress hooks (init, admin_menu, save_post, etc.)
 * from their own constructors — this function's only feature-adjacent job
 * is to instantiate each one exactly once so the autoloader picks it up;
 * it never contains CPT args, meta box markup, or any other feature logic
 * itself. Cross-cutting bootstrap concerns such as translations live here
 * too.
 *
 * @return void
 */
function service_crew_init() {
	load_plugin_textdomain( 'service-crew', false, dirname( SERVICE_CREW_PLUGIN_BASENAME ) . '/languages' );

	new Service_Crew_Services();
	new Service_Crew_Crew();
	new Service_Crew_Crew_Controller();
	new Service_Crew_Services_Controller();
	new Service_Crew_Discounts();
	new Service_Crew_Settings();
	new Service_Crew_Appearance();
	new Service_Crew_Admin_App();
	new Service_Crew_Geocoding();
	new Service_Crew_Services_Shortcode();
	new Service_Crew_Booking_Shortcode();
	new Service_Crew_Wizard();
	new Service_Crew_Payments();
	new Service_Crew_Gateway_Stripe();
	new Service_Crew_Bookings();
	new Service_Crew_Bookings_Controller();
	new Service_Crew_Customers_Controller();
	new Service_Crew_Quotes_Controller();
	new Service_Crew_Pay_Page();
	new Service_Crew_Job_Status_Page();
	new Service_Crew_Job_Status_Controller();
	new Service_Crew_Emails();
	new Service_Crew_Smtp();
	new Service_Crew_Notifications();
	new Service_Crew_Notifications_Controller();
	new Service_Crew_Cron();
	new Service_Crew_Agent_KB();
	new Service_Crew_Agent_Settings();
	new Service_Crew_Agent();
	new Service_Crew_Agent_Controller();
	new Service_Crew_Agent_Widget();
	new Service_Crew_Agent_Push();
}
add_action( 'plugins_loaded', 'service_crew_init' );
