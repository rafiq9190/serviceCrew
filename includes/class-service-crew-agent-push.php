<?php
/**
 * Phase D — Web Push for admin alerts. Hand-rolled VAPID (RFC8292) using
 * only PHP's bundled `openssl` extension, no library: generates/stores an
 * EC P-256 keypair once, signs an ES256 JWT per push, and sends an
 * **empty-payload** push (no RFC8291 payload encryption) to every admin
 * device subscribed — the service worker (public/js/agent-push-sw.js)
 * shows a static "something needs attention" notification regardless of
 * which event triggered it, since no data travels in the push itself.
 *
 * This exact kind of hand-rolled Web Push crypto was explicitly skipped
 * once before for the deferred crew/vendor PWA (see ServiceCrew-Plan-v2.md's
 * "V1 launch scope" / ServiceCrew-Tasks.md's Phase 1d note) — "no library
 * and no way to test it against a real push service in this environment."
 * Reversed back on for this feature specifically (admin alerts only) as one
 * of Phase 2's locked decisions. A real spike confirmed the crypto itself
 * (EC keygen, PEM export, ES256 sign, DER-to-raw signature conversion) works
 * on this project's PHP build — but only once `openssl_pkey_new()`/
 * `openssl_pkey_export()` are given an explicit `config` option pointing at
 * a real `openssl.cnf` (this environment's own OPENSSL_CONF is broken; the
 * bundled `includes/openssl-vapid.cnf` fixes it everywhere, harmlessly, even
 * on a server where it was already fine). End-to-end delivery to a real
 * push service from a real subscribed browser could **not** be verified in
 * this environment (no browser automation here, and Push API requires
 * HTTPS or literally "localhost" — this local dev site is neither) — only
 * the parts testable without a browser were verified; see
 * ServiceCrew-Tasks.md's Phase D note for exactly what that covers.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent_Push {

	/**
	 * Option storing the VAPID keypair — generated once, reused forever
	 * (regenerating would invalidate every existing subscription's implicit
	 * trust of the old public key).
	 *
	 * @var string
	 */
	const OPTION_VAPID_KEYS = 'sc_agent_vapid_keys';

	/**
	 * @var string
	 */
	const API_NAMESPACE = 'service-crew/v1';

	/**
	 * How long a signed VAPID JWT is valid for — generous (RFC8292 allows up
	 * to 24h), each push gets a freshly signed one anyway.
	 *
	 * @var int
	 */
	const JWT_TTL_SECONDS = 12 * HOUR_IN_SECONDS;

	/**
	 * How long the push service should hold an undelivered notification
	 * before giving up — short, since "someone needs attention" is only
	 * useful if seen soon; an admin who was offline for hours will see the
	 * pending escalation in the admin bell/inbox regardless.
	 *
	 * @var int
	 */
	const PUSH_TTL_SECONDS = 300;

	/**
	 * URL path the service worker is served at — root-relative (not under
	 * the plugin's own directory) so its default registration scope covers
	 * the whole site, matching Service_Crew_Pay_Page/Service_Crew_Job_Status_Page's
	 * own "no rewrite rule, template_redirect on an exact path" pattern
	 * rather than the original plan's add_rewrite_rule() (which would need
	 * a flush and is more fragile than just matching the path directly).
	 *
	 * @var string
	 */
	const SW_PATH = '/sc-agent-sw.js';

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_service_worker' ) );
		add_action( 'sc_agent_escalation_created', array( $this, 'on_escalation_created' ) );
	}

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/agent/push-vapid',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_vapid_public_key' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/push-subscribe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'subscribe' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/agent/push-subscribe/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'unsubscribe' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_agent_push_subscriptions';
	}

	/**
	 * @return string Absolute path to the bundled openssl.cnf — see this
	 *                 file's own class docblock for why it's needed.
	 */
	private static function cnf_path() {
		return SERVICE_CREW_PLUGIN_DIR . 'includes/openssl-vapid.cnf';
	}

	/**
	 * Returns the stored VAPID keypair, generating one on first use. Never
	 * regenerated once it exists.
	 *
	 * @return array{public:string,private_pem:string}|WP_Error
	 */
	public static function get_or_create_vapid_keys() {
		$saved = get_option( self::OPTION_VAPID_KEYS );
		if ( is_array( $saved ) && ! empty( $saved['public'] ) && ! empty( $saved['private_pem'] ) ) {
			return $saved;
		}

		$keypair = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'config'           => self::cnf_path(),
			)
		);

		if ( false === $keypair ) {
			return new WP_Error( 'sc_agent_push_keygen_failed', __( 'Could not generate push notification keys on this server.', 'service-crew' ) );
		}

		$details = openssl_pkey_get_details( $keypair );
		if ( ! isset( $details['ec']['x'], $details['ec']['y'] ) ) {
			return new WP_Error( 'sc_agent_push_keygen_failed', __( 'Could not generate push notification keys on this server.', 'service-crew' ) );
		}

		// VAPID's applicationServerKey is the raw uncompressed EC point:
		// 0x04 || X || Y (65 bytes for P-256) — not a PEM/DER structure.
		$public_raw = "\x04" . $details['ec']['x'] . $details['ec']['y'];

		$private_pem = '';
		openssl_pkey_export( $keypair, $private_pem, null, array( 'config' => self::cnf_path() ) );

		if ( '' === $private_pem ) {
			return new WP_Error( 'sc_agent_push_keygen_failed', __( 'Could not generate push notification keys on this server.', 'service-crew' ) );
		}

		$keys = array(
			'public'      => self::base64url_encode( $public_raw ),
			'private_pem' => $private_pem,
		);

		update_option( self::OPTION_VAPID_KEYS, $keys, false );

		return $keys;
	}

	/**
	 * GET /agent/push-vapid — the public key the widget's
	 * `pushManager.subscribe()` call needs as `applicationServerKey`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_vapid_public_key() {
		$keys = self::get_or_create_vapid_keys();
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		return rest_ensure_response( array( 'public_key' => $keys['public'] ) );
	}

	/**
	 * POST /agent/push-subscribe — body is a browser PushSubscription's own
	 * `toJSON()` shape: `{ endpoint, keys: { p256dh, auth } }`. Upserts by
	 * endpoint (a browser reusing the same subscription sends the same
	 * endpoint again) rather than allowing duplicate rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function subscribe( $request ) {
		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : array();
		$endpoint = isset( $params['endpoint'] ) ? sanitize_text_field( $params['endpoint'] ) : '';
		$keys     = isset( $params['keys'] ) && is_array( $params['keys'] ) ? $params['keys'] : array();
		$p256dh   = isset( $keys['p256dh'] ) ? sanitize_text_field( $keys['p256dh'] ) : '';
		$auth     = isset( $keys['auth'] ) ? sanitize_text_field( $keys['auth'] ) : '';

		if ( '' === $endpoint || '' === $p256dh || '' === $auth || ! wp_http_validate_url( $endpoint ) ) {
			return new WP_Error( 'sc_agent_push_invalid_subscription', __( 'Invalid push subscription.', 'service-crew' ), array( 'status' => 400 ) );
		}

		global $wpdb;

		$existing_id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE endpoint = %s', $endpoint ) );

		if ( $existing_id ) {
			$wpdb->update(
				self::table(),
				array(
					'p256dh' => $p256dh,
					'auth'   => $auth,
				),
				array( 'id' => (int) $existing_id )
			);

			return rest_ensure_response( array( 'id' => (int) $existing_id ) );
		}

		$wpdb->insert(
			self::table(),
			array(
				'user_id'  => get_current_user_id(),
				'endpoint' => $endpoint,
				'p256dh'   => $p256dh,
				'auth'     => $auth,
			)
		);

		return rest_ensure_response( array( 'id' => (int) $wpdb->insert_id ) );
	}

	/**
	 * DELETE /agent/push-subscribe/{id} — admin's own "disable notifications" action.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function unsubscribe( $request ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'id' => absint( $request->get_param( 'id' ) ) ) );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Fired from Service_Crew_Agent_Escalations::create() — pushes to every
	 * subscribed device. Deliberately doesn't also check whether the Emails
	 * admin screen's own escalation-email is enabled — push and email are
	 * independent channels an admin can turn on/off separately.
	 *
	 * @param int $escalation_id Escalation id (unused — empty-payload push, see class docblock).
	 * @return void
	 */
	public function on_escalation_created( $escalation_id ) {
		self::send_to_all_subscriptions();
	}

	/**
	 * Sends an empty-payload push to every stored subscription, removing
	 * any that the push service reports as gone (404/410 — the standard Web
	 * Push signal that a subscription is no longer valid).
	 *
	 * @return void
	 */
	public static function send_to_all_subscriptions() {
		global $wpdb;

		$subscriptions = $wpdb->get_results( 'SELECT * FROM ' . self::table() );

		foreach ( $subscriptions as $subscription ) {
			$result = self::send_one( $subscription->endpoint );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $result );

			if ( in_array( $status_code, array( 404, 410 ), true ) ) {
				$wpdb->delete( self::table(), array( 'id' => (int) $subscription->id ) );
			}
		}
	}

	/**
	 * @param string $endpoint Push service endpoint URL.
	 * @return array|WP_Error wp_remote_post()'s own return shape.
	 */
	private static function send_one( $endpoint ) {
		$keys = self::get_or_create_vapid_keys();
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		$parsed = wp_parse_url( $endpoint );
		if ( ! isset( $parsed['scheme'], $parsed['host'] ) ) {
			return new WP_Error( 'sc_agent_push_invalid_endpoint', __( 'Invalid push endpoint.', 'service-crew' ) );
		}

		// VAPID's "aud" claim is the push service's own origin, not the
		// endpoint's full path (every browser's push service validates it
		// this way — e.g. https://fcm.googleapis.com for Chrome).
		$audience = $parsed['scheme'] . '://' . $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' );

		$jwt = self::build_vapid_jwt( $audience, $keys['private_pem'] );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		return wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization'  => 'vapid t=' . $jwt . ', k=' . $keys['public'],
					'TTL'            => (string) self::PUSH_TTL_SECONDS,
					'Content-Length' => '0',
				),
				'body'    => '',
				'timeout' => 10,
			)
		);
	}

	/**
	 * Builds and ES256-signs a VAPID JWT (RFC8292) — header/claims per spec,
	 * signature in JOSE's raw R||S format (openssl_sign() only produces
	 * DER, hence der_to_raw_ecdsa_signature()).
	 *
	 * @param string $audience    Push service origin.
	 * @param string $private_pem VAPID private key, PEM.
	 * @return string|WP_Error
	 */
	private static function build_vapid_jwt( $audience, $private_pem ) {
		$header  = self::base64url_encode( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$payload = self::base64url_encode(
			wp_json_encode(
				array(
					'aud' => $audience,
					'exp' => time() + self::JWT_TTL_SECONDS,
					'sub' => 'mailto:' . get_option( 'admin_email' ),
				)
			)
		);

		$signing_input = $header . '.' . $payload;

		$der_signature = '';
		$signed        = openssl_sign( $signing_input, $der_signature, $private_pem, OPENSSL_ALGO_SHA256 );

		if ( ! $signed ) {
			return new WP_Error( 'sc_agent_push_sign_failed', __( 'Could not sign a push notification.', 'service-crew' ) );
		}

		$raw_signature = self::der_to_raw_ecdsa_signature( $der_signature, 32 );
		if ( false === $raw_signature ) {
			return new WP_Error( 'sc_agent_push_sign_failed', __( 'Could not sign a push notification.', 'service-crew' ) );
		}

		return $signing_input . '.' . self::base64url_encode( $raw_signature );
	}

	/**
	 * openssl_sign() produces an ASN.1 DER-encoded ECDSA signature
	 * (SEQUENCE of two INTEGERs, r and s); JOSE's ES256 requires the raw
	 * concatenation of r and s, each left-padded to $length bytes (32 for
	 * P-256) — this parses the DER structure by hand (no library) and
	 * repacks it. Confirmed against a real signature via a round-trip
	 * openssl_verify() during development.
	 *
	 * @param string $der    DER-encoded ECDSA signature.
	 * @param int    $length Byte length of each of r/s (32 for P-256).
	 * @return string|false Raw r||s signature, or false if the DER couldn't be parsed.
	 */
	private static function der_to_raw_ecdsa_signature( $der, $length ) {
		if ( strlen( $der ) < 8 || 0x30 !== ord( $der[0] ) ) {
			return false;
		}

		$offset         = 1;
		$total_len_byte = ord( $der[ $offset ] );
		$offset++;

		if ( $total_len_byte & 0x80 ) {
			$offset += $total_len_byte & 0x7F;
		}

		$parts = array();

		for ( $i = 0; $i < 2; $i++ ) {
			if ( ! isset( $der[ $offset ] ) || 0x02 !== ord( $der[ $offset ] ) ) {
				return false;
			}
			$offset++;

			if ( ! isset( $der[ $offset ] ) ) {
				return false;
			}
			$int_len = ord( $der[ $offset ] );
			$offset++;

			$int_bytes = substr( $der, $offset, $int_len );
			$offset   += $int_len;

			// DER INTEGERs are signed — strip a leading 0x00 sign byte
			// (added whenever the high bit of a positive number would
			// otherwise look negative), then left-pad back to $length.
			$int_bytes = ltrim( $int_bytes, "\x00" );
			$parts[]   = str_pad( $int_bytes, $length, "\x00", STR_PAD_LEFT );
		}

		return $parts[0] . $parts[1];
	}

	/**
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Serves the service worker at a root-relative URL (needed so its
	 * default registration scope covers the whole site, not just this
	 * plugin's own asset directory) — no rewrite rule/flush needed, same
	 * exact-path template_redirect pattern Service_Crew_Pay_Page/
	 * Service_Crew_Job_Status_Page already use for their own fixed-path pages.
	 *
	 * @return void
	 */
	public function maybe_serve_service_worker() {
		$path = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

		if ( self::SW_PATH !== $path ) {
			return;
		}

		$file = SERVICE_CREW_PLUGIN_DIR . 'public/js/agent-push-sw.js';

		if ( ! file_exists( $file ) ) {
			status_header( 404 );
			exit;
		}

		// WP's own request parsing already marked this unrecognized path
		// 404 before template_redirect ever fires — the Service Worker spec
		// requires a 2xx response for register() to succeed, so that status
		// must be explicitly overridden here, not just the content-type
		// (confirmed via a real HTTP request: the body was already correct,
		// but the status line stayed 404 without this).
		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		// Lets a worker served from a subpath (it isn't here, but this is
		// the standard header for it regardless) control the whole origin.
		header( 'Service-Worker-Allowed: /' );
		readfile( $file );
		exit;
	}
}
