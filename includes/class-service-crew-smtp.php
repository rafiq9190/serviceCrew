<?php
/**
 * Native SMTP delivery for every email class-service-crew-emails.php sends —
 * no third-party SMTP plugin. WordPress's `wp_mail()` already builds on
 * PHPMailer (bundled with WP core) and fires `phpmailer_init` right before
 * sending, which is the same first-party extension point every SMTP plugin
 * uses under the hood; this class just configures that hook itself from an
 * admin-entered host/port/credentials instead of adding a dependency.
 *
 * When disabled (or no host configured), phpmailer_init() is a no-op and
 * wp_mail() falls back to PHP's mail(), same as an unconfigured site today.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Smtp {

	/**
	 * Option name storing the SMTP configuration.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'sc_smtp_settings';

	/**
	 * REST namespace shared with the rest of the custom admin app.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
	}

	/**
	 * Registers GET/PUT /smtp-settings and POST /smtp-settings/test-email.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/smtp-settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/smtp-settings/test-email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_test_email' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission check shared by every route on this class — same access
	 * level as the rest of the custom admin app.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_saved_settings() {
		$saved = get_option( self::OPTION_NAME, array() );

		return array_merge( self::get_defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_defaults() {
		return array(
			'enabled'    => false,
			'from_email' => '',
			'from_name'  => '',
			'host'       => '',
			'port'       => 587,
			// 'tls' (STARTTLS, typically port 587), 'ssl' (implicit TLS,
			// typically port 465), or 'none'.
			'encryption' => 'tls',
			'username'   => '',
			'password'   => '',
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( self::build_response_settings( self::get_saved_settings() ) );
	}

	/**
	 * Masks the password, same "never return a secret in full" rule
	 * Service_Crew_Payments follows for its Stripe keys.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	private static function build_response_settings( array $settings ) {
		$settings['password'] = self::mask_secret( $settings['password'] );

		return $settings;
	}

	/**
	 * @param string $value Raw secret value.
	 * @return string
	 */
	private static function mask_secret( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) <= 4 ) {
			return str_repeat( '*', strlen( $value ) );
		}

		return str_repeat( '*', strlen( $value ) - 2 ) . substr( $value, -2 );
	}

	/**
	 * PUT handler: replaces the whole settings object. Password follows the
	 * same "submitting the masked value back means leave it alone" rule as
	 * Service_Crew_Payments's secret keys.
	 *
	 * @param WP_REST_Request $request Request with the settings shape in the JSON body.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$current = self::get_saved_settings();

		$encryption = in_array( $params['encryption'] ?? '', array( 'tls', 'ssl', 'none' ), true ) ? $params['encryption'] : $current['encryption'];

		$sanitized = array(
			'enabled'    => ! empty( $params['enabled'] ),
			'from_email' => sanitize_email( $params['from_email'] ?? $current['from_email'] ),
			'from_name'  => sanitize_text_field( $params['from_name'] ?? $current['from_name'] ),
			'host'       => sanitize_text_field( $params['host'] ?? $current['host'] ),
			'port'       => isset( $params['port'] ) ? absint( $params['port'] ) : $current['port'],
			'encryption' => $encryption,
			'username'   => sanitize_text_field( $params['username'] ?? $current['username'] ),
			'password'   => $this->resolve_secret_field( $params['password'] ?? '', $current['password'] ),
		);

		if ( ! $sanitized['port'] ) {
			$sanitized['port'] = $current['port'];
		}

		update_option( self::OPTION_NAME, $sanitized, false );

		return rest_ensure_response( self::build_response_settings( $sanitized ) );
	}

	/**
	 * A submitted password is only treated as a real new value when it's
	 * non-empty and doesn't contain the masking character — otherwise it's
	 * almost certainly the masked display value being echoed back unchanged.
	 *
	 * @param mixed  $submitted Raw posted value.
	 * @param string $existing  Currently stored raw value.
	 * @return string
	 */
	private function resolve_secret_field( $submitted, $existing ) {
		$submitted = is_string( $submitted ) ? trim( $submitted ) : '';

		if ( '' === $submitted || false !== strpos( $submitted, '*' ) ) {
			return $existing;
		}

		return $submitted;
	}

	/**
	 * Configures PHPMailer for SMTP from the saved settings, right before
	 * wp_mail() sends. A no-op whenever SMTP isn't enabled or has no host
	 * configured, so an incomplete setup never breaks core's own mail
	 * (password-reset emails, etc.) — it just falls back to PHP's mail(),
	 * exactly like an unconfigured site.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance, by reference per the hook's contract.
	 * @return void
	 */
	public function configure_phpmailer( $phpmailer ) {
		$settings = self::get_saved_settings();

		if ( empty( $settings['enabled'] ) || '' === $settings['host'] ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host = $settings['host'];
		$phpmailer->Port = $settings['port'];

		if ( 'none' === $settings['encryption'] ) {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $settings['encryption'];
		}

		$phpmailer->SMTPAuth = '' !== $settings['username'];
		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = $settings['username'];
			$phpmailer->Password = $settings['password'];
		}

		if ( '' !== $settings['from_email'] ) {
			$phpmailer->From = $settings['from_email'];
		}

		if ( '' !== $settings['from_name'] ) {
			$phpmailer->FromName = $settings['from_name'];
		}
	}

	/**
	 * POST handler: sends a real test email through the currently-saved
	 * configuration (not whatever's unsaved in the browser — save first),
	 * surfacing PHPMailer's actual failure reason via `wp_mail_failed`
	 * rather than wp_mail()'s bare true/false, same "verify before trusting"
	 * shape as Service_Crew_Payments::test_connection().
	 *
	 * @param WP_REST_Request $request Request with a 'to' email address in the JSON body.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test_email( $request ) {
		$params = $request->get_json_params();
		$to     = sanitize_email( is_array( $params ) ? ( $params['to'] ?? '' ) : '' );

		if ( ! is_email( $to ) ) {
			return new WP_Error( 'sc_smtp_invalid_email', __( 'Please provide a valid email address to send the test to.', 'service-crew' ), array( 'status' => 400 ) );
		}

		$error_message = '';
		$capture       = function ( $wp_error ) use ( &$error_message ) {
			$error_message = $wp_error->get_error_message();
		};

		add_action( 'wp_mail_failed', $capture );

		$sent = wp_mail(
			$to,
			__( 'ServiceCrew SMTP test', 'service-crew' ),
			__( "This is a test email from your ServiceCrew SMTP configuration.\n\nIf you received this, SMTP is working correctly.", 'service-crew' )
		);

		remove_action( 'wp_mail_failed', $capture );

		if ( ! $sent ) {
			return new WP_Error( 'sc_smtp_test_failed', $error_message ? $error_message : __( 'Could not send the test email.', 'service-crew' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}
}
