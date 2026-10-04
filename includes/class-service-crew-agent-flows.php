<?php
/**
 * Phase B conversational slot-filling flows for the Agent chat sales agent —
 * FLOW_QUOTE/FLOW_BOOK/FLOW_PAY, each a simple ordered sequence of questions
 * stored in `sc_agent_sessions`' pending_flow/pending_flow_state columns
 * (JSON: `{step, data}`). Started by Service_Crew_Agent::handle_message()
 * when Service_Crew_Agent_Matcher::detect_intent() recognizes an explicit
 * "book/quote/pay" request; advanced one step per visitor message via
 * advance(), generalizing the same "return a reply to end the turn, or null
 * to abandon/finish and fall through" contract the escalation-email capture
 * (class-service-crew-agent.php's handle_pending_escalation_email()) already
 * established as a one-turn special case.
 *
 * Each flow's terminal step calls straight into the real backend
 * (Service_Crew_Quotes::create_quote_request_from_attachments(),
 * Service_Crew_Bookings::create_instant_booking(), or
 * Service_Crew_Payments::create_payment()+generate_pay_token()) — no
 * duplicated business logic, same validation/pricing/slot-availability
 * rules a visitor using the normal booking widget would hit.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent_Flows {

	/**
	 * pending_flow values this class owns (distinct from the one-turn
	 * 'awaiting_escalation_email' value class-service-crew-agent.php handles
	 * itself).
	 *
	 * @var string
	 */
	const FLOW_QUOTE = 'flow_quote';
	const FLOW_BOOK  = 'flow_book';
	const FLOW_PAY   = 'flow_pay';

	/**
	 * Words that mean "I don't have one" for an optional slot — distinct
	 * from Service_Crew_Agent::is_cancel_text(), which abandons the whole
	 * flow rather than just skipping one optional field.
	 *
	 * @var string[]
	 */
	const SKIP_WORDS = array( 'no', 'skip', 'none', 'n/a', 'na' );

	/**
	 * @param string $flow One of the FLOW_* constants.
	 * @return string|null
	 */
	public static function first_step( $flow ) {
		$first = array(
			self::FLOW_QUOTE => 'title',
			self::FLOW_BOOK  => 'service',
			self::FLOW_PAY   => 'email',
		);

		return $first[ $flow ] ?? null;
	}

	/**
	 * Starts a flow fresh — called from Service_Crew_Agent::handle_message()
	 * once an intent is recognized with no flow currently active.
	 *
	 * @param int    $session_id Session id.
	 * @param string $flow       One of the FLOW_* constants.
	 * @return string The first step's prompt.
	 */
	public static function start( $session_id, $flow ) {
		$step = self::first_step( $flow );

		Service_Crew_Agent::update_session(
			$session_id,
			array(
				'pending_flow'       => $flow,
				'pending_flow_state' => wp_json_encode( array( 'step' => $step, 'data' => array() ) ),
			)
		);

		return self::prompt_for_step( $flow, $step );
	}

	/**
	 * Advances whichever flow is active on $session by one step, given the
	 * visitor's latest message.
	 *
	 * @param object $session Session row (pending_flow is one of the FLOW_* constants).
	 * @param string $text    Visitor's raw message text.
	 * @return array{reply:string,extra:array<string,mixed>}|null Null means the flow was
	 *         abandoned (corrupted state) — same "fall through to normal matching" contract
	 *         as the rest of Service_Crew_Agent::handle_message()'s pending_flow handling.
	 */
	public static function advance( $session, $text ) {
		$state = json_decode( (string) $session->pending_flow_state, true );
		$state = is_array( $state ) ? $state : array();
		$step  = $state['step'] ?? null;
		$data  = is_array( $state['data'] ?? null ) ? $state['data'] : array();

		if ( null === $step ) {
			Service_Crew_Agent::update_session( $session->id, array( 'pending_flow' => null, 'pending_flow_state' => null ) );
			return null;
		}

		$trimmed = trim( (string) $text );

		switch ( $session->pending_flow ) {
			case self::FLOW_QUOTE:
				return self::advance_quote( $session, $step, $data, $trimmed );
			case self::FLOW_BOOK:
				return self::advance_book( $session, $step, $data, $trimmed );
			case self::FLOW_PAY:
				return self::advance_pay( $session, $step, $data, $trimmed );
		}

		Service_Crew_Agent::update_session( $session->id, array( 'pending_flow' => null, 'pending_flow_state' => null ) );
		return null;
	}

	/**
	 * Called by the /agent/upload route when a photo is attached mid-flow —
	 * appends the new attachment id to the already-open FLOW_QUOTE's
	 * 'photos' step, capped at Service_Crew_Quotes::MAX_PHOTOS.
	 *
	 * @param object $session   Session row.
	 * @param int    $photo_id  New attachment id.
	 * @return int Total photo count now attached.
	 */
	public static function attach_photo( $session, $photo_id ) {
		$state = json_decode( (string) $session->pending_flow_state, true );
		$state = is_array( $state ) ? $state : array( 'step' => 'photos', 'data' => array() );
		$data  = is_array( $state['data'] ?? null ) ? $state['data'] : array();

		$photo_ids   = is_array( $data['photo_ids'] ?? null ) ? $data['photo_ids'] : array();
		$photo_ids[] = absint( $photo_id );
		$data['photo_ids'] = array_slice( array_unique( $photo_ids ), 0, Service_Crew_Quotes::MAX_PHOTOS );

		$state['data'] = $data;

		Service_Crew_Agent::update_session( $session->id, array( 'pending_flow_state' => wp_json_encode( $state ) ) );

		return count( $data['photo_ids'] );
	}

	/**
	 * @param string $text Lowercased-insensitive check against SKIP_WORDS.
	 * @return bool
	 */
	private static function is_skip( $text ) {
		return in_array( strtolower( $text ), self::SKIP_WORDS, true );
	}

	/**
	 * Persists the next step and returns its prompt — the "advance" half of
	 * every non-terminal step.
	 *
	 * @param object $session   Session row.
	 * @param string $flow      One of the FLOW_* constants.
	 * @param string $next_step Next step key.
	 * @param array  $data      Accumulated flow data so far.
	 * @return array{reply:string,extra:array<string,mixed>}
	 */
	private static function advance_to( $session, $flow, $next_step, array $data ) {
		Service_Crew_Agent::update_session(
			$session->id,
			array(
				'pending_flow'       => $flow,
				'pending_flow_state' => wp_json_encode( array( 'step' => $next_step, 'data' => $data ) ),
			)
		);

		$extra = ( self::FLOW_QUOTE === $flow && 'photos' === $next_step ) ? array( 'awaiting_photos' => true ) : array();

		return array(
			'reply' => self::prompt_for_step( $flow, $next_step, $data ),
			'extra' => $extra,
		);
	}

	/**
	 * Re-asks the same step with a clarifying message — state is left
	 * untouched (the caller never persists on this path), so the visitor's
	 * next reply is checked against the same step again.
	 *
	 * @param string $message Clarifying prompt.
	 * @return array{reply:string,extra:array<string,mixed>}
	 */
	private static function reask( $message ) {
		return array( 'reply' => $message, 'extra' => array() );
	}

	/**
	 * Ends a flow (success or failure) by clearing pending_flow.
	 *
	 * @param object               $session Session row.
	 * @param string               $reply   Final reply text.
	 * @param array<string,mixed>  $extra   Extra REST-response fields (e.g. booking_id).
	 * @param array<string,mixed>  $session_fields Additional session columns to set alongside clearing the flow (e.g. booking_id).
	 * @return array{reply:string,extra:array<string,mixed>}
	 */
	private static function finish( $session, $reply, array $extra = array(), array $session_fields = array() ) {
		Service_Crew_Agent::update_session(
			$session->id,
			array_merge(
				array(
					'pending_flow'       => null,
					'pending_flow_state' => null,
				),
				$session_fields
			)
		);

		return array( 'reply' => $reply, 'extra' => $extra );
	}

	/**
	 * Every step's prompt text, keyed by flow then step. 'window' is built
	 * dynamically (needs the admin's configured arrival windows), not a
	 * static string.
	 *
	 * @param string $flow One of the FLOW_* constants.
	 * @param string $step Step key.
	 * @param array  $data Accumulated flow data (only 'window' uses this, for context — unused by the rest).
	 * @return string
	 */
	public static function prompt_for_step( $flow, $step, array $data = array() ) {
		if ( self::FLOW_BOOK === $flow && 'window' === $step ) {
			return self::build_window_prompt();
		}

		$prompts = array(
			self::FLOW_QUOTE => array(
				'title'          => __( 'What would you like a quote for? Give me a short title, like "Fence repair."', 'service-crew' ),
				'description'    => __( 'Got it. Can you describe the job in a bit more detail?', 'service-crew' ),
				'photos'         => __( 'Want to attach any photos? Use the 📎 button below to add up to 6, then say "done" — or just say "no" to skip.', 'service-crew' ),
				'preferred_date' => __( "Do you have a preferred date? Use YYYY-MM-DD, or say \"no\" if you're flexible.", 'service-crew' ),
				'address'        => __( "What's the service address?", 'service-crew' ),
				'zip'            => __( 'And the ZIP code?', 'service-crew' ),
				'name'           => __( 'What name should we use?', 'service-crew' ),
				'email'          => __( 'Best email to reach you at?', 'service-crew' ),
				'phone'          => __( 'Phone number? (optional — say "skip".)', 'service-crew' ),
			),
			self::FLOW_BOOK  => array(
				'service' => __( 'Which service would you like to book? (e.g. "Standard Cleaning")', 'service-crew' ),
				'date'    => __( 'What date works for you? Use YYYY-MM-DD.', 'service-crew' ),
				'address' => __( "What's the service address?", 'service-crew' ),
				'zip'     => __( 'ZIP code?', 'service-crew' ),
				'name'    => __( 'What name should we book this under?', 'service-crew' ),
				'email'   => __( 'Best email for your confirmation?', 'service-crew' ),
				'phone'   => __( 'Phone number? (optional — say "skip".)', 'service-crew' ),
			),
			self::FLOW_PAY   => array(
				'email' => __( "Sure — what's the email on the booking or quote you'd like to pay for?", 'service-crew' ),
			),
		);

		return $prompts[ $flow ][ $step ] ?? __( 'Okay.', 'service-crew' );
	}

	/**
	 * @return string
	 */
	private static function build_window_prompt() {
		$windows = Service_Crew_Settings::get_saved_settings()['arrival_windows'];
		$lines   = array();

		foreach ( $windows as $index => $window ) {
			$lines[] = ( $index + 1 ) . ') ' . $window['label'] . ' (' . $window['start'] . '–' . $window['end'] . ')';
		}

		return __( 'Pick an arrival window:', 'service-crew' ) . "\n" . implode( "\n", $lines ) . "\n" . __( 'Reply with just the number.', 'service-crew' );
	}

	// -----------------------------------------------------------------
	// FLOW_QUOTE
	// -----------------------------------------------------------------

	/**
	 * @param object $session Session row.
	 * @param string $step    Current step key.
	 * @param array  $data    Accumulated data.
	 * @param string $text    Trimmed visitor text.
	 * @return array{reply:string,extra:array<string,mixed>}
	 */
	private static function advance_quote( $session, $step, array $data, $text ) {
		switch ( $step ) {
			case 'title':
				if ( '' === $text ) {
					return self::reask( __( 'I just need a short title to continue — what should we call this job?', 'service-crew' ) );
				}
				$data['title'] = sanitize_text_field( $text );
				return self::advance_to( $session, self::FLOW_QUOTE, 'description', $data );

			case 'description':
				if ( '' === $text ) {
					return self::reask( __( 'Can you tell me a bit more about the job?', 'service-crew' ) );
				}
				$data['description'] = sanitize_textarea_field( $text );
				return self::advance_to( $session, self::FLOW_QUOTE, 'photos', $data );

			case 'photos':
				// Photos themselves arrive via /agent/upload, not this text
				// step — any reply here (done/no/whatever) just moves on.
				return self::advance_to( $session, self::FLOW_QUOTE, 'preferred_date', $data );

			case 'preferred_date':
				if ( self::is_skip( $text ) ) {
					$data['preferred_date'] = '';
				} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ) {
					$data['preferred_date'] = $text;
				} else {
					return self::reask( __( 'Please use YYYY-MM-DD format, or say "no" if any date works.', 'service-crew' ) );
				}
				return self::advance_to( $session, self::FLOW_QUOTE, 'address', $data );

			case 'address':
				if ( '' === $text ) {
					return self::reask( __( "What's the service address?", 'service-crew' ) );
				}
				$data['address'] = sanitize_text_field( $text );
				return self::advance_to( $session, self::FLOW_QUOTE, 'zip', $data );

			case 'zip':
				$data['zip'] = self::is_skip( $text ) ? '' : sanitize_text_field( $text );
				return self::advance_to( $session, self::FLOW_QUOTE, 'name', $data );

			case 'name':
				if ( '' === $text ) {
					return self::reask( __( 'What name should we use?', 'service-crew' ) );
				}
				$data['name'] = sanitize_text_field( $text );
				return self::advance_to( $session, self::FLOW_QUOTE, 'email', $data );

			case 'email':
				if ( ! is_email( $text ) ) {
					return self::reask( __( "That doesn't look like a valid email — can you try again?", 'service-crew' ) );
				}
				$data['email'] = sanitize_email( $text );
				return self::advance_to( $session, self::FLOW_QUOTE, 'phone', $data );

			case 'phone':
				$data['phone'] = self::is_skip( $text ) ? '' : sanitize_text_field( $text );
				return self::finish_quote( $session, $data );
		}

		return self::finish( $session, __( 'Something went wrong with that request — let\'s start over if you still need a quote.', 'service-crew' ) );
	}

	/**
	 * @param object $session Session row.
	 * @param array  $data    Fully collected flow data.
	 * @return array{reply:string,extra:array<string,mixed>}
	 */
	private static function finish_quote( $session, array $data ) {
		$fields = array(
			'title'            => $data['title'] ?? '',
			'description'      => $data['description'] ?? '',
			'name'             => $data['name'] ?? '',
			'email'            => $data['email'] ?? '',
			'phone'            => $data['phone'] ?? '',
			'address'          => $data['address'] ?? '',
			'zip'              => $data['zip'] ?? '',
			'preferred_date'   => $data['preferred_date'] ?? '',
			'arrival_window'   => '',
			'marketing_opt_in' => false,
		);

		$photo_ids = is_array( $data['photo_ids'] ?? null ) ? $data['photo_ids'] : array();

		$result = Service_Crew_Quotes::create_quote_request_from_attachments( $fields, $photo_ids );

		if ( is_wp_error( $result ) ) {
			return self::finish( $session, $result->get_error_message() );
		}

		return self::finish(
			$session,
			__( "All set — I've submitted your quote request. We'll review it and follow up by email soon!", 'service-crew' ),
			array( 'booking_id' => $result['booking_id'] ),
			array( 'booking_id' => $result['booking_id'] )
		);
	}

	// -----------------------------------------------------------------
	// FLOW_BOOK
	// -----------------------------------------------------------------

	/**
	 * @param object $session Session row.
	 * @param string $step    Current step key.
	 * @param array  $data    Accumulated data.
	 * @param string $text    Trimmed visitor text.
	 * @return array{reply:string,extra:array<string,mixed>}
	 */
	private static function advance_book( $session, $step, array $data, $text ) {
		switch ( $step ) {
			case 'service':
				$leaf = self::match_service( $text );
				if ( ! $leaf ) {
					$titles = self::leaf_service_titles( 8 );
					return self::reask(
						$titles
							? sprintf(
								/* translators: %s: comma-separated list of service names. */
								__( "I couldn't find that service. A few options: %s. Which one would you like?", 'service-crew' ),
								implode( ', ', $titles )
							)
							: __( "I couldn't find that service — could you try a different name?", 'service-crew' )
					);
				}
				$data['service_id']    = $leaf['id'];
				$data['service_title'] = $leaf['title'];
				return self::advance_to( $session, self::FLOW_BOOK, 'date', $data );

			case 'date':
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ) {
					return self::reask( __( 'Please use YYYY-MM-DD format.', 'service-crew' ) );
				}
				$data['date'] = $text;
				return self::advance_to( $session, self::FLOW_BOOK, 'window', $data );

			case 'window':
				$windows = Service_Crew_Settings::get_saved_settings()['arrival_windows'];
				$index   = absint( $text ) - 1;
				if ( ! isset( $windows[ $index ] ) ) {
					return self::reask( __( 'Please reply with just the number of your preferred window.', 'service-crew' ) );
				}
				$data['window_index'] = $index;
				return self::advance_to( $session, self::FLOW_BOOK, 'address', $data );

			case 'address':
				if ( '' === $text ) {
					return self::reask( __( "What's the service address?", 'service-crew' ) );
				}
				$data['address'] = sanitize_text_field( $text );
				return self::advance_to( $session, self::FLOW_BOOK, 'zip', $data );

			case 'zip':
				$data['zip'] = self::is_skip( $text ) ? '' : sanitize_text_field( $text );
				return self::advance_to( $session, self::FLOW_BOOK, 'name', $data );

			case 'name':
				if ( '' === $text ) {
					return self::reask( __( 'What name should we book this under?', 'service-crew' ) );
				}
				$data['name'] = sanitize_text_field( $text );
				return self::advance_to( $session, self::FLOW_BOOK, 'email', $data );

			case 'email':
				if ( ! is_email( $text ) ) {
					return self::reask( __( "That doesn't look like a valid email — can you try again?", 'service-crew' ) );
				}
				$data['email'] = sanitize_email( $text );
				return self::advance_to( $session, self::FLOW_BOOK, 'phone', $data );

			case 'phone':
				$data['phone'] = self::is_skip( $text ) ? '' : sanitize_text_field( $text );
				return self::finish_book( $session, $data );
		}

		return self::finish( $session, __( "Something went wrong with that booking — let's start over if you'd still like to book.", 'service-crew' ) );
	}

	/**
	 * @param object $session Session row.
	 * @param array  $data    Fully collected flow data.
	 * @return array{reply:string,extra:array<string,mixed>}
	 */
	private static function finish_book( $session, array $data ) {
		$input = array(
			'items'                 => array(
				array(
					'service_id' => (int) $data['service_id'],
					'qty'        => 1,
				),
			),
			'date'                  => $data['date'],
			'arrival_window_index'  => (int) $data['window_index'],
			'is_emergency'          => false,
			'customer_name'         => $data['name'] ?? '',
			'customer_email'        => $data['email'] ?? '',
			'customer_phone'        => $data['phone'] ?? '',
			'address'               => $data['address'] ?? '',
			'zip'                   => $data['zip'] ?? '',
			'return_url'            => '',
		);

		$result = Service_Crew_Bookings::create_instant_booking( $input );

		if ( is_wp_error( $result ) ) {
			return self::finish(
				$session,
				$result->get_error_message() . ' ' . __( 'Want to try a different date or time? Just say "book" to start again.', 'service-crew' )
			);
		}

		return self::finish(
			$session,
			sprintf(
				/* translators: 1: service name, 2: Stripe Checkout URL. */
				__( "Great — I've started your booking for %1\$s. To confirm it, please pay the deposit here: %2\$s", 'service-crew' ),
				$data['service_title'] ?? '',
				$result['checkout_url']
			),
			array( 'booking_id' => $result['booking_id'] ),
			array( 'booking_id' => $result['booking_id'] )
		);
	}

	/**
	 * Matches free text against every published leaf service's title by
	 * simple token overlap — reuses Service_Crew_Agent_Matcher::tokenize()
	 * for consistency with the rest of the agent, not real fuzzy search.
	 *
	 * @param string $text Visitor's free-text service name.
	 * @return array<string,mixed>|null Leaf tree node (see Service_Crew_Agent_KB::get_leaf_services()), or null.
	 */
	private static function match_service( $text ) {
		$input_tokens = Service_Crew_Agent_Matcher::tokenize( $text );

		if ( empty( $input_tokens ) ) {
			return null;
		}

		$best       = null;
		$best_score = 0;

		foreach ( Service_Crew_Agent_KB::get_leaf_services() as $leaf ) {
			$title_tokens = Service_Crew_Agent_Matcher::tokenize( $leaf['title'] );
			$overlap      = count( array_intersect( $input_tokens, $title_tokens ) );

			if ( $overlap > $best_score ) {
				$best_score = $overlap;
				$best       = $leaf;
			}
		}

		return $best;
	}

	/**
	 * @param int $limit Max titles to list.
	 * @return string[]
	 */
	private static function leaf_service_titles( $limit ) {
		$titles = wp_list_pluck( Service_Crew_Agent_KB::get_leaf_services(), 'title' );

		return array_slice( $titles, 0, $limit );
	}

	// -----------------------------------------------------------------
	// FLOW_PAY
	// -----------------------------------------------------------------

	/**
	 * @param object $session Session row.
	 * @param string $step    Current step key.
	 * @param array  $data    Accumulated data.
	 * @param string $text    Trimmed visitor text.
	 * @return array{reply:string,extra:array<string,mixed>}
	 */
	private static function advance_pay( $session, $step, array $data, $text ) {
		if ( 'email' === $step ) {
			if ( ! is_email( $text ) ) {
				return self::reask( __( "That doesn't look like a valid email — can you try again?", 'service-crew' ) );
			}
			return self::finish_pay( $session, sanitize_email( $text ) );
		}

		return self::finish( $session, __( 'Something went wrong with that request.', 'service-crew' ) );
	}

	/**
	 * Looks up the single most recent not-yet-paid booking/quote for the
	 * given email and issues a fresh deposit pay-link — deliberately scoped
	 * to only `awaiting_payment`/`quoted` (never-paid-at-all) bookings, not
	 * a remaining-balance lookup on an already-confirmed booking, to keep
	 * this chat-exposed lookup's blast radius small. Requiring the exact
	 * email already on the booking (rather than, say, just a booking
	 * number) is the security bar here — a non-match gets the same generic
	 * reply as a real email with nothing pending, so this never confirms or
	 * denies whether a given email exists as a customer.
	 *
	 * @param object $session Session row.
	 * @param string $email   Validated email.
	 * @return array{reply:string,extra:array<string,mixed>}
	 */
	private static function finish_pay( $session, $email ) {
		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, deposit_amount FROM {$wpdb->prefix}sc_bookings
				WHERE customer_email = %s AND status IN ( 'awaiting_payment', 'quoted' ) AND deposit_amount > 0
				ORDER BY created_at DESC LIMIT 1",
				$email
			)
		);

		if ( ! $booking ) {
			return self::finish(
				$session,
				__( "I couldn't find an unpaid booking or quote for that email. If you think this is a mistake, just ask to talk to someone and our team can help.", 'service-crew' )
			);
		}

		$payment_id = Service_Crew_Payments::create_payment(
			array(
				'booking_id' => (int) $booking->id,
				'kind'       => Service_Crew_Payments::KIND_DEPOSIT,
				'amount'     => (float) $booking->deposit_amount,
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			return self::finish( $session, __( 'Something went wrong generating your payment link — please contact us directly.', 'service-crew' ) );
		}

		$token = Service_Crew_Payments::generate_pay_token( $payment_id );

		if ( is_wp_error( $token ) ) {
			return self::finish( $session, __( 'Something went wrong generating your payment link — please contact us directly.', 'service-crew' ) );
		}

		return self::finish(
			$session,
			sprintf(
				/* translators: %s: pay-page URL. */
				__( "Here's your payment link: %s", 'service-crew' ),
				Service_Crew_Pay_Page::build_url( $token )
			)
		);
	}
}
