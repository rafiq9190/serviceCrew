<?php
/**
 * Private pay page: the destination of a quote's deposit link
 * (Service_Crew_Quotes::create_deposit_link()). Deliberately not a
 * shortcode an admin places on a page — a customer only ever reaches this
 * via the exact single-use token link the admin sends them, so it renders
 * itself on `template_redirect` off a plain `?sc_pay=<token>` query arg
 * (works under any permalink structure, no rewrite rules/flush needed)
 * rather than requiring a dedicated WP Page to exist.
 *
 * The token itself is the access control (Service_Crew_Payments::
 * find_payment_by_token() returns null for anything unknown/expired,
 * indistinguishably) — there is no login here, matching every other
 * customer-facing route in this plugin.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Pay_Page {

	/**
	 * Query var this page renders on. No rewrite endpoint is registered —
	 * this is read as a plain GET param, so it works on any permalink
	 * structure without needing a rewrite-rules flush.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'sc_pay';

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap (service_crew_init() in service-crew.php).
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Builds the customer-facing URL for a plaintext pay token.
	 *
	 * @param string $token Plaintext token from Service_Crew_Payments::generate_pay_token().
	 * @return string
	 */
	public static function build_url( $token ) {
		return add_query_arg( array( self::QUERY_VAR => rawurlencode( $token ) ), home_url( '/' ) );
	}

	/**
	 * Intercepts a request carrying ?sc_pay=<token> and renders the pay page
	 * in place of whatever page WordPress would otherwise have shown at this
	 * URL, then exits — this never falls through to a theme template.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only page render, not a state change; the token itself is the access control.
			return;
		}

		$token   = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$payment = Service_Crew_Payments::find_payment_by_token( $token );

		if ( ! $payment ) {
			$this->render_page( __( 'Payment link not found', 'service-crew' ), '<p>' . esc_html__( 'This payment link is invalid or has expired. Please contact us for a new one.', 'service-crew' ) . '</p>', $token );
			exit;
		}

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'sc_bookings WHERE id = %d', (int) $payment->booking_id ) );

		if ( ! $booking ) {
			$this->render_page( __( 'Payment link not found', 'service-crew' ), '<p>' . esc_html__( 'This payment link is invalid or has expired. Please contact us for a new one.', 'service-crew' ) . '</p>', $token );
			exit;
		}

		$is_balance = Service_Crew_Payments::KIND_BALANCE === $payment->kind;

		if ( Service_Crew_Payments::STATUS_SUCCEEDED === $payment->status ) {
			$this->render_page(
				__( 'Payment received', 'service-crew' ),
				'<div class="sc-pay-page__icon sc-pay-page__icon--success">&#10003;</div><p>' . ( $is_balance
					? esc_html__( 'Thanks — this balance has already been paid.', 'service-crew' )
					: esc_html__( 'Thanks — this deposit has already been paid.', 'service-crew' ) ) . '</p>',
				$token
			);
			exit;
		}

		$title = $booking->quote_title ? $booking->quote_title : __( 'ServiceCrew booking', 'service-crew' );

		ob_start();
		?>
		<p class="sc-pay-page__job"><?php echo esc_html( $title ); ?></p>
		<p class="sc-pay-page__amount"><?php echo esc_html( self::format_money( (float) $payment->amount ) ); ?></p>
		<p class="sc-pay-page__help">
			<?php
			echo $is_balance
				? esc_html__( 'Remaining balance due for your booking.', 'service-crew' )
				: esc_html__( 'Deposit due to confirm your booking.', 'service-crew' );
			?>
		</p>
		<button type="button" id="sc-pay-page-button" class="sc-pay-page__button"><?php echo $is_balance ? esc_html__( 'Pay balance now', 'service-crew' ) : esc_html__( 'Pay deposit now', 'service-crew' ); ?></button>
		<p id="sc-pay-page-error" class="sc-pay-page__error" aria-live="polite"></p>
		<?php
		$body = ob_get_clean();

		$this->render_page( $is_balance ? __( 'Pay your balance', 'service-crew' ) : __( 'Pay your deposit', 'service-crew' ), $body, $token, (int) $booking->id );
		exit;
	}

	/**
	 * @param float $amount Amount in the site's major currency unit.
	 * @return string
	 */
	private static function format_money( $amount ) {
		return '$' . number_format_i18n( $amount, 2 );
	}

	/**
	 * Renders a minimal standalone HTML page — deliberately not wrapped in
	 * the active theme's get_header()/get_footer(), since a token link may
	 * be opened with no theme assumptions guaranteed (e.g. a headless/
	 * caching setup) and this page's only job is showing an amount and a Pay
	 * button.
	 *
	 * @param string   $heading    Page heading.
	 * @param string   $body_html  Pre-escaped inner HTML for the content area.
	 * @param string   $token      Plaintext token, passed to the client script.
	 * @param int|null $booking_id Booking id, for the client script's post-payment poll.
	 * @return void
	 */
	private function render_page( $heading, $body_html, $token, $booking_id = null ) {
		wp_enqueue_style( 'sc-pay-page', SERVICE_CREW_PLUGIN_URL . 'public/css/pay-page.css', array(), SERVICE_CREW_VERSION );
		wp_enqueue_script( 'sc-pay-page', SERVICE_CREW_PLUGIN_URL . 'public/js/pay-page.js', array(), SERVICE_CREW_VERSION, true );
		wp_localize_script(
			'sc-pay-page',
			'SC_PAY_PAGE',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'service-crew/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'token'     => $token,
				'bookingId' => $booking_id,
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
			<?php wp_print_styles( 'sc-pay-page' ); ?>
		</head>
		<body class="sc-pay-page-body">
			<main class="sc-pay-page">
				<h1 class="sc-pay-page__site"><?php bloginfo( 'name' ); ?></h1>
				<h2 class="sc-pay-page__heading"><?php echo esc_html( $heading ); ?></h2>
				<?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_html()'d pieces above. ?>
			</main>
			<?php wp_print_scripts( 'sc-pay-page' ); ?>
		</body>
		</html>
		<?php
	}
}
