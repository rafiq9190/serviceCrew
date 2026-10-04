<?php
/**
 * Pure-calc rule-based matcher for the "Agent" chat sales agent (Phase A —
 * see C:\Users\fujitsu\.claude\plans\scalable-wondering-milner.md). No LLM,
 * no paid API, no DB access — tokenizes a visitor's message and scores it
 * against a corpus of KB entries/service facts built by the caller
 * (class-service-crew-agent.php), same "pure calc, unit-testable" shape as
 * class-service-crew-pricing.php/class-service-crew-capacity.php.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent_Matcher {

	/**
	 * Decision-bucket constants returned by decide().
	 *
	 * @var string
	 */
	const DECISION_CONFIDENT = 'confident';
	const DECISION_AMBIGUOUS = 'ambiguous';
	const DECISION_ESCALATE  = 'escalate';

	/**
	 * Deliberate "sales agent" bias: added to a `service:`-prefixed
	 * candidate's score before ranking, so a genuine near-tie between a
	 * service/price fact and a generic FAQ/KB answer resolves toward the
	 * sales-relevant one. Set just above decide()'s own ambiguous-gap
	 * constant (0.08, below) so it can flip a real tie without being large
	 * enough to override a message that clearly matches something else
	 * better.
	 *
	 * @var float
	 */
	const SERVICE_MATCH_BONUS = 0.09;

	/**
	 * Intent constants returned by detect_intent() — Phase B's lead-capture/
	 * payment-link flows. INTENT_PRICE is detected for completeness but never
	 * starts a flow: a price lookup is already answered inline by
	 * build_corpus()'s own `service:` price facts, so routing it into a flow
	 * would just be a worse version of what already works.
	 *
	 * @var string
	 */
	const INTENT_BOOK  = 'book';
	const INTENT_QUOTE = 'quote';
	const INTENT_PAY   = 'pay';
	const INTENT_HUMAN = 'human';
	const INTENT_PRICE = 'price';

	/**
	 * Deliberately simple phrase-containment lexicon, not a scoring model —
	 * checked against the raw (lowercased, unstemmed) message, before any
	 * corpus matching, so an explicit action request like "book a" is
	 * recognized precisely rather than diluted into bag-of-words scoring.
	 * Order matters: a human-handoff request is checked first in case a
	 * phrase could plausibly overlap with another intent.
	 *
	 * @var array<string,string[]>
	 */
	const INTENT_PHRASES = array(
		self::INTENT_HUMAN => array(
			'talk to a human', 'speak to a human', 'talk to someone', 'speak to someone',
			'talk to a person', 'speak to a person', 'real person', 'customer service',
			'representative', 'speak with a human', 'speak with someone',
		),
		self::INTENT_BOOK  => array(
			'book a', 'book an', 'book my', 'schedule a', 'schedule an',
			'make an appointment', 'i want to book', 'i would like to book',
			'can i book', 'set up an appointment',
		),
		self::INTENT_QUOTE => array(
			'get a quote', 'request a quote', 'need a quote', 'want a quote',
			'quote for', 'estimate for', 'custom quote', 'free quote',
		),
		self::INTENT_PAY   => array(
			'pay my deposit', 'pay the deposit', 'pay my balance', 'pay the balance',
			'pay for my booking', 'make a payment', 'i want to pay',
		),
		self::INTENT_PRICE => array(
			'how much', 'what does it cost', 'price of', 'cost of',
		),
	);

	/**
	 * Short, generic words that carry no matching signal on their own.
	 * Deliberately small — this is keyword overlap, not real NLP, so an
	 * overgrown stopword list would just throw away genuine signal from
	 * short visitor messages.
	 *
	 * @var string[]
	 */
	const STOPWORDS = array(
		'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'am',
		'i', 'you', 'he', 'she', 'it', 'we', 'they', 'my', 'your', 'our',
		'to', 'of', 'in', 'on', 'for', 'and', 'or', 'but', 'do', 'does',
		'did', 'can', 'could', 'will', 'would', 'should', 'with', 'about',
		'what', 'how', 'me', 'please', 'hi', 'hello', 'hey', 'this', 'that',
	);

	/**
	 * Lowercases, strips punctuation, splits on whitespace, drops stopwords
	 * and single-character leftovers, and lightly stems trailing
	 * s/es/ing/ed so "cleaning"/"cleaned"/"cleans" all collapse to "clean".
	 * Deliberately crude (no real stemmer) — good enough for keyword
	 * overlap, not meant to be linguistically correct.
	 *
	 * @param string $text Raw text.
	 * @return string[] Token list (may contain duplicates — scoring counts them).
	 */
	public static function tokenize( $text ) {
		$text = strtolower( (string) $text );
		$text = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$words = preg_split( '/\s+/', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );

		$tokens = array();
		foreach ( $words as $word ) {
			if ( strlen( $word ) < 2 || in_array( $word, self::STOPWORDS, true ) ) {
				continue;
			}
			$tokens[] = self::stem( $word );
		}

		return $tokens;
	}

	/**
	 * @param string $word Already-lowercased single word.
	 * @return string
	 */
	private static function stem( $word ) {
		foreach ( array( 'ing', 'es', 'ed', 's' ) as $suffix ) {
			$len = strlen( $suffix );
			if ( strlen( $word ) > $len + 2 && substr( $word, -$len ) === $suffix ) {
				return substr( $word, 0, -$len );
			}
		}

		return $word;
	}

	/**
	 * Checks a raw message against INTENT_PHRASES, returning the first
	 * matching intent or null. Called before any corpus matching — see
	 * class-service-crew-agent.php's handle_message().
	 *
	 * @param string $text Raw visitor message.
	 * @return string|null One of the INTENT_* constants, or null if nothing matched.
	 */
	public static function detect_intent( $text ) {
		$lower = strtolower( trim( (string) $text ) );

		if ( '' === $lower ) {
			return null;
		}

		foreach ( self::INTENT_PHRASES as $intent => $phrases ) {
			foreach ( $phrases as $phrase ) {
				if ( false !== strpos( $lower, $phrase ) ) {
					return $intent;
				}
			}
		}

		return null;
	}

	/**
	 * Builds an IDF (inverse document frequency) weight per token across the
	 * whole corpus, so a word that appears in nearly every entry (low
	 * signal) counts for less than a rare, distinctive one.
	 *
	 * @param array<int,string[]> $corpus_tokens One token list per corpus entry.
	 * @return array<string,float> Token => IDF weight.
	 */
	public static function build_idf( array $corpus_tokens ) {
		$doc_count          = max( 1, count( $corpus_tokens ) );
		$doc_frequency      = array();

		foreach ( $corpus_tokens as $tokens ) {
			foreach ( array_unique( $tokens ) as $token ) {
				$doc_frequency[ $token ] = ( $doc_frequency[ $token ] ?? 0 ) + 1;
			}
		}

		$idf = array();
		foreach ( $doc_frequency as $token => $frequency ) {
			$idf[ $token ] = log( ( $doc_count + 1) / ( $frequency + 1 ) ) + 1;
		}

		return $idf;
	}

	/**
	 * Cosine-similarity-style overlap score between a message's tokens and
	 * one corpus entry's tokens, IDF-weighted and length-normalized, with a
	 * flat bonus for an exact phrase containment either direction (catches
	 * short, exact-wording questions that token overlap alone might
	 * under-score).
	 *
	 * @param string[]             $message_tokens Visitor message tokens.
	 * @param string[]             $entry_tokens   One corpus entry's tokens.
	 * @param array<string,float>  $idf            From build_idf().
	 * @return float Score, roughly 0..1.5 (the phrase bonus can push slightly over 1).
	 */
	public static function score( array $message_tokens, array $entry_tokens, array $idf ) {
		if ( empty( $message_tokens ) || empty( $entry_tokens ) ) {
			return 0.0;
		}

		$message_counts = array_count_values( $message_tokens );
		$entry_counts   = array_count_values( $entry_tokens );

		$dot          = 0.0;
		$message_norm = 0.0;
		$entry_norm   = 0.0;

		$all_tokens = array_unique( array_merge( array_keys( $message_counts ), array_keys( $entry_counts ) ) );

		foreach ( $all_tokens as $token ) {
			$weight = $idf[ $token ] ?? 1.0;
			$m      = ( $message_counts[ $token ] ?? 0 ) * $weight;
			$e      = ( $entry_counts[ $token ] ?? 0 ) * $weight;

			$dot          += $m * $e;
			$message_norm += $m * $m;
			$entry_norm   += $e * $e;
		}

		if ( 0.0 === $message_norm || 0.0 === $entry_norm ) {
			return 0.0;
		}

		$cosine = $dot / ( sqrt( $message_norm ) * sqrt( $entry_norm ) );

		$message_phrase = implode( ' ', $message_tokens );
		$entry_phrase   = implode( ' ', $entry_tokens );
		if ( '' !== $message_phrase && ( false !== strpos( $entry_phrase, $message_phrase ) || false !== strpos( $message_phrase, $entry_phrase ) ) ) {
			$cosine += 0.2;
		}

		return $cosine;
	}

	/**
	 * Scores a message against every corpus entry and returns the ranked
	 * results (highest score first).
	 *
	 * @param string                                          $message Raw visitor message.
	 * @param array<int,array{id:mixed,tokens:string[]}>      $corpus  Each entry's id (opaque to this class) + pre-tokenized text.
	 * @return array<int,array{id:mixed,score:float}> Ranked, highest score first.
	 */
	public static function rank( $message, array $corpus ) {
		$message_tokens = self::tokenize( $message );

		if ( empty( $corpus ) ) {
			return array();
		}

		$idf = self::build_idf( wp_list_pluck( $corpus, 'tokens' ) );

		$results = array();
		foreach ( $corpus as $entry ) {
			$score = self::score( $message_tokens, $entry['tokens'], $idf );

			if ( 0 === strpos( $entry['id'], 'service:' ) ) {
				$score += self::SERVICE_MATCH_BONUS;
			}

			$results[] = array(
				'id'    => $entry['id'],
				'score' => $score,
			);
		}

		usort(
			$results,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return $results;
	}

	/**
	 * Three-way decision over a ranked result list: confident auto-answer,
	 * ambiguous/plausible "did you mean?", or escalate. Thresholds are
	 * admin-configurable (Service_Crew_Agent_Settings) rather than hardcoded
	 * here, so the caller passes them in.
	 *
	 * @param array<int,array{id:mixed,score:float}> $ranked              From rank(), already sorted.
	 * @param float                                  $confident_threshold Score at/above which the top result is trusted outright.
	 * @param float                                  $plausible_threshold Score below which there's no plausible match at all.
	 * @return array{decision:string,candidates:array<int,array{id:mixed,score:float}>}
	 */
	public static function decide( array $ranked, $confident_threshold, $plausible_threshold ) {
		if ( empty( $ranked ) || $ranked[0]['score'] < $plausible_threshold ) {
			return array(
				'decision'   => self::DECISION_ESCALATE,
				'candidates' => array(),
			);
		}

		$top        = $ranked[0];
		$runner_up  = $ranked[1] ?? null;
		$is_ambiguous = $runner_up && ( $top['score'] - $runner_up['score'] ) < 0.08;

		if ( $top['score'] >= $confident_threshold && ! $is_ambiguous ) {
			return array(
				'decision'   => self::DECISION_CONFIDENT,
				'candidates' => array( $top ),
			);
		}

		return array(
			'decision'   => self::DECISION_AMBIGUOUS,
			'candidates' => array_slice( $ranked, 0, 2 ),
		);
	}
}
