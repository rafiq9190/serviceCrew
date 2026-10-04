<?php
/**
 * `sc_agent_sessions` / `sc_agent_messages` data access plus the Agent chat
 * sales agent's message-handling orchestration (Phase A — see
 * C:\Users\fujitsu\.claude\plans\scalable-wondering-milner.md). Ties
 * class-service-crew-agent-kb.php's corpus to class-service-crew-agent-
 * matcher.php's pure scoring to produce a reply for one visitor message.
 *
 * An unmatched question creates a real Service_Crew_Agent_Escalations row
 * (admin notification + inbox to answer from — see that class and
 * class-service-crew-agent-controller.php's /agent/escalations routes) and
 * puts the session into a one-message 'awaiting_escalation_email' pending
 * flow so the very next message can optionally be the visitor's email for a
 * follow-up when nobody's watching the chat anymore.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent {

	/**
	 * How long a visitor's session token stays valid — generous enough for a
	 * returning visitor days later to resume the same conversation.
	 *
	 * @var int
	 */
	const SESSION_TTL_SECONDS = 30 * DAY_IN_SECONDS;

	/**
	 * Sender values on sc_agent_messages.
	 *
	 * @var string
	 */
	const SENDER_VISITOR = 'visitor';
	const SENDER_AGENT   = 'agent';
	const SENDER_ADMIN   = 'admin';

	/**
	 * message_type values on sc_agent_messages.
	 *
	 * @var string
	 */
	const TYPE_TEXT = 'text';

	/**
	 * Abandons whichever pending_flow is active (Phase B's FLOW_QUOTE/
	 * FLOW_BOOK/FLOW_PAY, or the one-turn escalation-email capture) when a
	 * visitor types one of these, exact match after trim+lowercase —
	 * deliberately a short, explicit list rather than a fuzzy "sounds like
	 * giving up" classifier.
	 *
	 * @var string[]
	 */
	const CANCEL_PHRASES = array( 'cancel', 'nevermind', 'never mind', 'stop', 'quit', 'forget it' );

	/**
	 * No WordPress hooks of its own — constructed once from service-crew.php
	 * purely so the autoloader/bootstrap pattern stays uniform with every
	 * other feature class, even though this one is pure data/orchestration
	 * consumed by class-service-crew-agent-controller.php.
	 */
	public function __construct() {}

	/**
	 * @return string
	 */
	private static function sessions_table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_agent_sessions';
	}

	/**
	 * @return string
	 */
	private static function messages_table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_agent_messages';
	}

	/**
	 * Creates a new visitor session. The plaintext token is returned once,
	 * for the widget to keep in localStorage — only its hash is stored, same
	 * convention as Service_Crew_Payments::generate_pay_token().
	 *
	 * @return array{session_id:int,token:string}
	 */
	public static function create_session() {
		global $wpdb;

		$token = wp_generate_password( 32, false, false );

		$wpdb->insert(
			self::sessions_table(),
			array(
				'session_token_hash'       => hash( 'sha256', $token ),
				'session_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL_SECONDS ),
				'status'                   => 'active',
			),
			array( '%s', '%s', '%s' )
		);

		return array(
			'session_id' => (int) $wpdb->insert_id,
			'token'      => $token,
		);
	}

	/**
	 * Resolves a plaintext session token to its row, or null if unknown/
	 * expired — same indistinguishable-failure shape as
	 * Service_Crew_Payments::find_payment_by_token().
	 *
	 * @param string $token Plaintext session token.
	 * @return object|null
	 */
	public static function find_session_by_token( $token ) {
		global $wpdb;

		if ( '' === trim( (string) $token ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::sessions_table() . ' WHERE session_token_hash = %s',
				hash( 'sha256', (string) $token )
			)
		);

		if ( ! $row ) {
			return null;
		}

		if ( $row->session_token_expires_at && strtotime( $row->session_token_expires_at ) < time() ) {
			return null;
		}

		return $row;
	}

	/**
	 * Loads a session by its own id — used by Service_Crew_Agent_Escalations
	 * (which only has session_id, not the plaintext token) and by
	 * handle_message()'s own pending-flow check.
	 *
	 * @param int $session_id Session id.
	 * @return object|null
	 */
	public static function find_session_by_id( $session_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sessions_table() . ' WHERE id = %d', absint( $session_id ) ) );
	}

	/**
	 * Thin update helper for the session row — used to set/clear
	 * pending_flow(_state) around the escalation "email me the answer"
	 * capture and to save visitor_email once given. Public since Phase B's
	 * class-service-crew-agent-flows.php and the /agent/upload route on
	 * class-service-crew-agent-controller.php also need to write
	 * pending_flow_state mid-flow. Formats are left to wpdb's own type
	 * inference (fields here are always either a string or null, never
	 * ambiguous).
	 *
	 * @param int                  $session_id Session id.
	 * @param array<string,mixed>  $fields     Column => value.
	 * @return void
	 */
	public static function update_session( $session_id, array $fields ) {
		global $wpdb;

		$wpdb->update( self::sessions_table(), $fields, array( 'id' => absint( $session_id ) ) );
	}

	/**
	 * @param int        $session_id    Session id.
	 * @param string     $sender        One of the SENDER_* constants.
	 * @param string     $body          Message text.
	 * @param string     $message_type  One of the TYPE_* constants (TYPE_TEXT for now; TYPE_IMAGE arrives in Phase B).
	 * @param int|null   $attachment_id Optional WP attachment id.
	 * @param array|null $meta          Optional matched-intent/KB debugging info, stored as JSON.
	 * @return int New message id.
	 */
	public static function log_message( $session_id, $sender, $body, $message_type = self::TYPE_TEXT, $attachment_id = null, $meta = null ) {
		global $wpdb;

		$wpdb->insert(
			self::messages_table(),
			array(
				'session_id'    => absint( $session_id ),
				'sender'        => $sender,
				'message_type'  => $message_type,
				'body'          => (string) $body,
				'attachment_id' => null === $attachment_id ? null : absint( $attachment_id ),
				'meta'          => null === $meta ? null : wp_json_encode( $meta ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Full transcript for a session, oldest first.
	 *
	 * @param int $session_id Session id.
	 * @return object[]
	 */
	public static function get_history( $session_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::messages_table() . ' WHERE session_id = %d ORDER BY created_at ASC, id ASC',
				absint( $session_id )
			)
		);
	}

	/**
	 * Every session, newest-active first, with a message count and the
	 * latest message's text — for the Agent admin screen's "Conversations"
	 * log (Phase E). The admin never has a session's plaintext token (only
	 * its hash is stored), so this reads by id directly rather than reusing
	 * find_session_by_token()/get_history()'s token-gated public path.
	 *
	 * @param int $limit Row cap.
	 * @return object[]
	 */
	public static function get_recent_sessions( $limit = 50 ) {
		global $wpdb;

		$messages_table = self::messages_table();
		$sessions_table = self::sessions_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.visitor_name, s.visitor_email, s.visitor_phone, s.booking_id, s.status, s.created_at, s.updated_at,
					( SELECT COUNT(*) FROM {$messages_table} m WHERE m.session_id = s.id ) AS message_count,
					( SELECT body FROM {$messages_table} m WHERE m.session_id = s.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1 ) AS last_message
				FROM {$sessions_table} s
				ORDER BY s.updated_at DESC
				LIMIT %d",
				absint( $limit )
			)
		);
	}

	/**
	 * Logs the visitor's message, matches it against the KB/service corpus,
	 * logs and returns the agent's reply. The one orchestration seam Phase B
	 * (intents/flows) and Phase C (escalation) both extend without any
	 * caller (the REST controller) needing to change.
	 *
	 * @param int    $session_id Session id.
	 * @param string $text       Visitor's raw message text.
	 * @return array{reply:string,decision:string,escalation_id?:int}
	 */
	public static function handle_message( $session_id, $text ) {
		$text = sanitize_textarea_field( $text );

		self::log_message( $session_id, self::SENDER_VISITOR, $text );

		$session = self::find_session_by_id( $session_id );

		if ( $session && $session->pending_flow ) {
			if ( self::is_cancel_text( $text ) ) {
				self::update_session( $session_id, array( 'pending_flow' => null, 'pending_flow_state' => null ) );

				return self::finalize_reply( $session_id, __( "No problem — let me know if there's anything else I can help with.", 'service-crew' ), Service_Crew_Agent_Matcher::DECISION_CONFIDENT );
			}

			// One message's worth of exception to "every message gets
			// matched": right after an escalation, the very next message is
			// interpreted as an answer to "want us to email you?" instead;
			// Phase B's FLOW_QUOTE/FLOW_BOOK/FLOW_PAY are real multi-step
			// flows advanced by class-service-crew-agent-flows.php instead.
			// Both share the same contract: return a reply to end the turn,
			// or null once the flow is cleared/finished to fall through to
			// normal matching for this same message.
			$flow_reply = 'awaiting_escalation_email' === $session->pending_flow
				? self::handle_pending_escalation_email( $session, $text )
				: Service_Crew_Agent_Flows::advance( $session, $text );

			if ( null !== $flow_reply ) {
				$reply = is_array( $flow_reply ) ? $flow_reply['reply'] : $flow_reply;
				$extra = is_array( $flow_reply ) ? ( $flow_reply['extra'] ?? array() ) : array();

				return self::finalize_reply( $session_id, $reply, Service_Crew_Agent_Matcher::DECISION_CONFIDENT, $extra );
			}
			// Flow was cleared/finished inside the handler above — fall
			// through and match this same message normally.
		}

		$settings      = Service_Crew_Agent_Settings::get_saved_settings();
		$tokens        = Service_Crew_Agent_Matcher::tokenize( $text );
		$escalation_id = null;

		if ( empty( $tokens ) ) {
			$reply    = $settings['greeting'];
			$decision = Service_Crew_Agent_Matcher::DECISION_CONFIDENT;
		} else {
			$intent = Service_Crew_Agent_Matcher::detect_intent( $text );
			$flow   = self::flow_for_intent( $intent );

			if ( Service_Crew_Agent_Matcher::INTENT_HUMAN === $intent ) {
				$escalation    = self::start_escalation( $session_id, $text, __( "Sure — I'll get our team to reach out to you directly.", 'service-crew' ) );
				$reply         = $escalation['reply'];
				$escalation_id = $escalation['escalation_id'];
				$decision      = Service_Crew_Agent_Matcher::DECISION_ESCALATE;
			} elseif ( $flow ) {
				$reply    = Service_Crew_Agent_Flows::start( $session_id, $flow );
				$decision = Service_Crew_Agent_Matcher::DECISION_CONFIDENT;
			} else {
				$corpus = Service_Crew_Agent_KB::build_corpus();
				$ranked = Service_Crew_Agent_Matcher::rank( $text, $corpus );
				$result = Service_Crew_Agent_Matcher::decide( $ranked, $settings['confident_threshold'], $settings['plausible_threshold'] );

				$decision = $result['decision'];

				if ( Service_Crew_Agent_Matcher::DECISION_CONFIDENT === $decision ) {
					$reply = self::resolve_answer( $result['candidates'][0]['id'], $corpus );

					if ( ! empty( $settings['sales_nudge_enabled'] ) && self::unresolved_reply_text() !== $reply ) {
						$reply .= "\n\n" . $settings['sales_nudge_text'];
					}
				} elseif ( Service_Crew_Agent_Matcher::DECISION_AMBIGUOUS === $decision ) {
					$reply = self::build_ambiguous_reply( $result['candidates'], $corpus );
				} else {
					$escalation    = self::start_escalation( $session_id, $text );
					$reply         = $escalation['reply'];
					$escalation_id = $escalation['escalation_id'];
				}
			}
		}

		return self::finalize_reply( $session_id, $reply, $decision, $escalation_id ? array( 'escalation_id' => $escalation_id ) : array() );
	}

	/**
	 * Logs the agent's reply and builds the REST response — the one place
	 * every handle_message() exit path converges, so escalation_id/
	 * booking_id/awaiting_photos (Phase B's flow extras) are merged in
	 * consistently regardless of which branch produced the reply.
	 *
	 * @param int                  $session_id Session id.
	 * @param string               $reply      Reply text.
	 * @param string               $decision   One of Service_Crew_Agent_Matcher::DECISION_*.
	 * @param array<string,mixed>  $extra      Extra fields to merge into the response.
	 * @return array{reply:string,decision:string}
	 */
	private static function finalize_reply( $session_id, $reply, $decision, array $extra = array() ) {
		self::log_message( $session_id, self::SENDER_AGENT, $reply, self::TYPE_TEXT, null, array( 'decision' => $decision ) );

		return array_merge(
			array(
				'reply'    => $reply,
				'decision' => $decision,
			),
			$extra
		);
	}

	/**
	 * @param string $text Raw visitor message.
	 * @return bool
	 */
	private static function is_cancel_text( $text ) {
		return in_array( strtolower( trim( (string) $text ) ), self::CANCEL_PHRASES, true );
	}

	/**
	 * @param string|null $intent One of Service_Crew_Agent_Matcher::INTENT_*, or null.
	 * @return string|null One of Service_Crew_Agent_Flows::FLOW_*, or null if $intent doesn't start a flow.
	 */
	private static function flow_for_intent( $intent ) {
		$map = array(
			Service_Crew_Agent_Matcher::INTENT_BOOK  => Service_Crew_Agent_Flows::FLOW_BOOK,
			Service_Crew_Agent_Matcher::INTENT_QUOTE => Service_Crew_Agent_Flows::FLOW_QUOTE,
			Service_Crew_Agent_Matcher::INTENT_PAY   => Service_Crew_Agent_Flows::FLOW_PAY,
		);

		return $map[ $intent ] ?? null;
	}

	/**
	 * Creates an escalation and puts the session into the one-turn
	 * "email me the answer?" capture — shared by the low-confidence
	 * DECISION_ESCALATE path and an explicit INTENT_HUMAN request, so both
	 * reach the exact same admin-notification/learn-into-KB/offline-
	 * follow-up machinery (class-service-crew-agent-escalations.php).
	 *
	 * @param int         $session_id Session id.
	 * @param string      $text       Visitor's message (stored as the escalation's question).
	 * @param string|null $intro      Optional custom lead-in sentence; defaults to the generic "I don't have an answer" line.
	 * @return array{reply:string,escalation_id:int}
	 */
	private static function start_escalation( $session_id, $text, $intro = null ) {
		$escalation_id = Service_Crew_Agent_Escalations::create( $session_id, $text );

		self::update_session(
			$session_id,
			array(
				'pending_flow'       => 'awaiting_escalation_email',
				'pending_flow_state' => wp_json_encode( array( 'escalation_id' => $escalation_id ) ),
			)
		);

		$intro = $intro ?? self::unresolved_reply_text();

		return array(
			'reply'         => $intro . ' ' . __( 'Want us to email you the answer? Reply with your email, or just keep chatting.', 'service-crew' ),
			'escalation_id' => $escalation_id,
		);
	}

	/**
	 * Interprets a message arriving while pending_flow is
	 * 'awaiting_escalation_email'. If it looks like an email, saves it to the
	 * session and confirms; otherwise clears the flow and defers to normal
	 * matching for this same message (a real follow-up question right after
	 * declining shouldn't be swallowed).
	 *
	 * @param object $session Session row.
	 * @param string $text    Visitor's raw message text.
	 * @return string|null Reply text if consumed here, or null to fall through.
	 */
	private static function handle_pending_escalation_email( $session, $text ) {
		$trimmed = trim( $text );

		if ( is_email( $trimmed ) ) {
			self::update_session(
				$session->id,
				array(
					'visitor_email'      => $trimmed,
					'pending_flow'       => null,
					'pending_flow_state' => null,
				)
			);

			return __( "Got it — we'll email you as soon as we have an answer. Anything else I can help with in the meantime?", 'service-crew' );
		}

		self::update_session(
			$session->id,
			array(
				'pending_flow'       => null,
				'pending_flow_state' => null,
			)
		);

		return null;
	}

	/**
	 * @return string
	 */
	private static function unresolved_reply_text() {
		return __( "I don't have an answer for that yet — I'll flag it so someone on our team can get back to you shortly.", 'service-crew' );
	}

	/**
	 * @param string $id     Corpus entry id to find.
	 * @param array  $corpus From Service_Crew_Agent_KB::build_corpus().
	 * @return array|null
	 */
	private static function find_corpus_entry( $id, array $corpus ) {
		foreach ( $corpus as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Resolves a corpus entry id ("kb:<id>" or "service:<id>") to its actual
	 * answer text, bumping the KB hit counter when applicable.
	 *
	 * @param string               $corpus_id Opaque id from Service_Crew_Agent_KB::build_corpus().
	 * @param array<int,array>     $corpus    The same corpus the id came from — needed to resolve a
	 *                                        `post_faq:` entry's answer, which (unlike `kb:`/`service:`)
	 *                                        has no DB row of its own to re-fetch.
	 * @return string
	 */
	private static function resolve_answer( $corpus_id, array $corpus ) {
		if ( 0 === strpos( $corpus_id, 'kb:' ) ) {
			$kb_id = (int) substr( $corpus_id, 3 );
			$entry = Service_Crew_Agent_KB::get_entry( $kb_id );

			if ( $entry ) {
				Service_Crew_Agent_KB::record_hit( $kb_id );
				return $entry->answer;
			}
		}

		if ( 0 === strpos( $corpus_id, 'service:' ) ) {
			$service_id = (int) substr( $corpus_id, 8 );
			$service    = get_post( $service_id );
			$pricing    = $service ? Service_Crew_Services::get_pricing_fields( $service_id, false ) : null;

			if ( $service && $pricing && null !== $pricing['price'] ) {
				$unit = $pricing['unit_label'] ? ' per ' . $pricing['unit_label'] : '';
				return sprintf(
					/* translators: 1: service name, 2: price, 3: unit (may be empty) */
					__( '%1$s costs $%2$s%3$s.', 'service-crew' ),
					Service_Crew_Services::get_plain_title( $service ),
					number_format( (float) $pricing['price'], 2 ),
					$unit
				);
			}
		}

		if ( 0 === strpos( $corpus_id, 'post_faq:' ) ) {
			$entry = self::find_corpus_entry( $corpus_id, $corpus );

			if ( $entry && isset( $entry['answer'] ) ) {
				return $entry['answer'];
			}
		}

		return self::unresolved_reply_text();
	}

	/**
	 * Builds a "did you mean?" style reply out of the top ambiguous/
	 * plausible candidates — plain text for Phase A; the widget renders real
	 * quick-reply buttons starting Phase B once intents/flows exist to tap
	 * into.
	 *
	 * @param array<int,array{id:string,score:float}> $candidates Top 1-2 candidates from Service_Crew_Agent_Matcher::decide().
	 * @param array<int,array>                        $corpus     The same corpus the candidates came from (see resolve_answer()).
	 * @return string
	 */
	private static function build_ambiguous_reply( array $candidates, array $corpus ) {
		$labels = array();

		foreach ( $candidates as $candidate ) {
			if ( 0 === strpos( $candidate['id'], 'kb:' ) ) {
				$entry = Service_Crew_Agent_KB::get_entry( (int) substr( $candidate['id'], 3 ) );
				if ( $entry ) {
					$labels[] = $entry->question;
				}
			} elseif ( 0 === strpos( $candidate['id'], 'service:' ) ) {
				$service = get_post( (int) substr( $candidate['id'], 8 ) );
				if ( $service ) {
					$labels[] = Service_Crew_Services::get_plain_title( $service );
				}
			} elseif ( 0 === strpos( $candidate['id'], 'post_faq:' ) ) {
				$entry = self::find_corpus_entry( $candidate['id'], $corpus );
				if ( $entry && isset( $entry['label'] ) ) {
					$labels[] = $entry['label'];
				}
			}
		}

		if ( empty( $labels ) ) {
			return __( "I'm not quite sure what you mean — could you rephrase that?", 'service-crew' );
		}

		return sprintf(
			/* translators: %s: comma-separated list of candidate question/service labels */
			__( "I'm not 100%% sure — did you mean one of these: %s?", 'service-crew' ),
			implode( ', ', $labels )
		);
	}
}
