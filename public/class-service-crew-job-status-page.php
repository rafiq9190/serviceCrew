<?php
/**
 * Private employee job-status page — V1's email-based stand-in for the
 * deferred crew PWA (see ServiceCrew-Plan-v2.md's "V1 launch scope"). The
 * lead on an assignment reaches this via the no-login, single-purpose token
 * link in their assignment email
 * (Service_Crew_Assignments::generate_status_token()) and taps through
 * On the way → Start → Complete (+ note/photos at Complete).
 *
 * Same "render on template_redirect off a plain query arg, no WP Page
 * needed" pattern as public/class-service-crew-pay-page.php — works under
 * any permalink structure, no rewrite-rule flush needed, fully outside the
 * active theme.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Job_Status_Page {

	/**
	 * Query var this page renders on. No rewrite endpoint is registered —
	 * this is read as a plain GET param, so it works on any permalink
	 * structure without needing a rewrite-rules flush.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'sc_job';

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Builds the employee-facing URL for a plaintext status token.
	 *
	 * @param string $token Plaintext token from Service_Crew_Assignments::generate_status_token().
	 * @return string
	 */
	public static function build_url( $token ) {
		return add_query_arg( array( self::QUERY_VAR => rawurlencode( $token ) ), home_url( '/' ) );
	}

	/**
	 * Intercepts a request carrying ?sc_job=<token> and renders the job
	 * status page in place of whatever page WordPress would otherwise have
	 * shown at this URL, then exits — this never falls through to a theme
	 * template.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only page render, not a state change; the token itself is the access control.
			return;
		}

		$token      = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$assignment = Service_Crew_Assignments::find_active_assignment_by_status_token( $token );

		if ( ! $assignment ) {
			$this->render_page( __( 'Link not found', 'service-crew' ), '<p>' . esc_html__( 'This link is invalid or has expired. Please contact the office for a new one.', 'service-crew' ) . '</p>', $token );
			exit;
		}

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', (int) $assignment->booking_id ) );

		if ( ! $booking ) {
			$this->render_page( __( 'Link not found', 'service-crew' ), '<p>' . esc_html__( 'This link is invalid or has expired. Please contact the office for a new one.', 'service-crew' ) . '</p>', $token );
			exit;
		}

		$this->render_page( __( 'Job status', 'service-crew' ), $this->build_body( $booking ), $token, $booking->status );
		exit;
	}

	/**
	 * The page's job-summary markup — the action buttons themselves are
	 * built client-side (public/js/job-status-page.js) based on the
	 * booking's current status, passed through via SC_JOB_PAGE.status.
	 *
	 * @param object $booking sc_bookings row.
	 * @return string
	 */
	private function build_body( $booking ) {
		$title = $booking->quote_title
			? $booking->quote_title
			: ( $booking->service_id ? get_the_title( (int) $booking->service_id ) : __( 'ServiceCrew job', 'service-crew' ) );

		$date_line = $booking->preferred_date
			? mysql2date( get_option( 'date_format' ), $booking->preferred_date ) . ( $booking->arrival_window ? ' · ' . $booking->arrival_window : '' )
			: '';

		ob_start();
		?>
		<p class="sc-job-page__job"><?php echo esc_html( $title ); ?></p>
		<p class="sc-job-page__meta"><?php echo esc_html( $booking->customer_name . ' — ' . $booking->address ); ?></p>
		<?php if ( $date_line ) : ?>
			<p class="sc-job-page__meta"><?php echo esc_html( $date_line ); ?></p>
		<?php endif; ?>

		<div id="sc-job-page-actions"></div>
		<p id="sc-job-page-error" class="sc-job-page__error" aria-live="polite"></p>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders a minimal standalone HTML page — deliberately not wrapped in
	 * the active theme's get_header()/get_footer(), same reasoning as the
	 * pay page: a token link may be opened with no theme assumptions
	 * guaranteed.
	 *
	 * @param string $heading   Page heading.
	 * @param string $body_html Pre-escaped inner HTML for the content area.
	 * @param string $token     Plaintext token, passed to the client script.
	 * @param string $status    Booking's current status, for the client script to pick the right action button(s).
	 * @return void
	 */
	private function render_page( $heading, $body_html, $token, $status = '' ) {
		wp_enqueue_style( 'sc-job-status-page', SERVICE_CREW_PLUGIN_URL . 'public/css/job-status-page.css', array(), SERVICE_CREW_VERSION );
		wp_enqueue_script( 'sc-job-status-page', SERVICE_CREW_PLUGIN_URL . 'public/js/job-status-page.js', array(), SERVICE_CREW_VERSION, true );
		wp_localize_script(
			'sc-job-status-page',
			'SC_JOB_PAGE',
			array(
				'restUrl' => esc_url_raw( rest_url( 'service-crew/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'token'   => $token,
				'status'  => $status,
			)
		);

		status_header( 200 );
		nocache_headers();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $heading . ' — ' . get_bloginfo( 'name' ) ); ?></title>
			<?php wp_print_styles( 'sc-job-status-page' ); ?>
		</head>
		<body class="sc-job-page-body">
			<main class="sc-job-page">
				<h1 class="sc-job-page__site"><?php bloginfo( 'name' ); ?></h1>
				<h2 class="sc-job-page__heading"><?php echo esc_html( $heading ); ?></h2>
				<?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_html()'d pieces above. ?>
			</main>
			<?php wp_print_scripts( 'sc-job-status-page' ); ?>
		</body>
		</html>
		<?php
	}
}
