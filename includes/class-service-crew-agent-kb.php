<?php
/**
 * `sc_agent_kb_entries` data access — the Agent chat sales agent's
 * admin-curated + self-learned FAQ (Phase A — see
 * C:\Users\fujitsu\.claude\plans\scalable-wondering-milner.md). Deliberately
 * just the data layer, same shape as class-service-crew-notes.php — REST
 * routes live on class-service-crew-agent-controller.php.
 *
 * build_corpus() additionally folds in published leaf services (price/unit
 * facts only — sc_service has no free-text description field) so the
 * matcher can answer "how much is X" without a dedicated FAQ entry for every
 * service, plus — opt-in via 'learn_from_content' — FAQ-shaped headings
 * scraped from published Pages/Posts (class-service-crew-agent-content-
 * extractor.php). The combined corpus is cached in a transient, invalidated
 * on any KB write or (now that this class has its own constructor) on any
 * post/page save, same "transient cache invalidated on write" rule the rest
 * of this plugin follows (e.g. Service_Crew_Geocoding's cache).
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent_KB {

	/**
	 * Source values.
	 *
	 * @var string
	 */
	const SOURCE_MANUAL  = 'manual';
	const SOURCE_LEARNED = 'learned';

	/**
	 * Transient key for the cached matcher corpus.
	 *
	 * @var string
	 */
	const CORPUS_TRANSIENT = 'sc_agent_corpus';

	/**
	 * Registers the save_post hooks that keep build_corpus()'s cache fresh
	 * when the 'learn_from_content' pages/posts source (see
	 * class-service-crew-agent-content-extractor.php) is on — nothing else
	 * on this class needed a constructor before, since it was otherwise
	 * pure static data access with no hooks of its own.
	 */
	public function __construct() {
		add_action( 'save_post_post', array( $this, 'maybe_invalidate_for_post' ), 10, 3 );
		add_action( 'save_post_page', array( $this, 'maybe_invalidate_for_post' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'maybe_invalidate_on_status_change' ), 10, 3 );
	}

	/**
	 * save_post_post/save_post_page callback — type-scoped hooks, so this
	 * never fires for sc_service/sc_crew/sc_booking or any other plugin's
	 * CPT. Only invalidates the cache (no eager re-extraction here) — the
	 * next chat message's build_corpus() cache-miss redoes the (capped)
	 * extraction instead.
	 *
	 * @param int     $post_id Post id (unused).
	 * @param WP_Post $post    Post object (unused).
	 * @param bool    $update  Whether this is an existing post being updated (unused).
	 * @return void
	 */
	public function maybe_invalidate_for_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::invalidate_corpus_cache();
	}

	/**
	 * transition_post_status callback — a safety net for trash/untrash and
	 * other status-only flips that might not otherwise fire save_post_*.
	 *
	 * @param string  $new_status New status (unused).
	 * @param string  $old_status Old status (unused).
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function maybe_invalidate_on_status_change( $new_status, $old_status, $post ) {
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		self::invalidate_corpus_cache();
	}

	/**
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'sc_agent_kb_entries';
	}

	/**
	 * Every KB entry, newest first.
	 *
	 * @param bool $active_only Only return is_active rows (the matcher's own use).
	 * @return object[]
	 */
	public static function get_entries( $active_only = false ) {
		global $wpdb;

		$where = $active_only ? ' WHERE is_active = 1' : '';

		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . $where . ' ORDER BY id DESC' );
	}

	/**
	 * @param int $id Entry id.
	 * @return object|null
	 */
	public static function get_entry( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ) );
	}

	/**
	 * Creates a KB entry. Callers are responsible for sanitizing fields
	 * before calling (same convention as Service_Crew_Notes::add_note()).
	 *
	 * @param array<string,mixed> $fields question/answer/keywords/related_service_id/source/is_active.
	 * @return int|WP_Error New entry id, or WP_Error on failure.
	 */
	public static function create_entry( array $fields ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'question'            => $fields['question'],
				'answer'              => $fields['answer'],
				'keywords'            => $fields['keywords'],
				'related_service_id'  => $fields['related_service_id'],
				'source'              => $fields['source'],
				'is_active'           => $fields['is_active'] ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'sc_agent_kb_insert_failed', __( 'Could not save this knowledge-base entry.', 'service-crew' ) );
		}

		self::invalidate_corpus_cache();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a KB entry.
	 *
	 * @param int                  $id     Entry id.
	 * @param array<string,mixed>  $fields Same shape as create_entry().
	 * @return bool
	 */
	public static function update_entry( $id, array $fields ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			array(
				'question'            => $fields['question'],
				'answer'              => $fields['answer'],
				'keywords'            => $fields['keywords'],
				'related_service_id'  => $fields['related_service_id'],
				'source'              => $fields['source'],
				'is_active'           => $fields['is_active'] ? 1 : 0,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s', '%d', '%s', '%d' ),
			array( '%d' )
		);

		self::invalidate_corpus_cache();

		return false !== $updated;
	}

	/**
	 * @param int $id Entry id.
	 * @return bool
	 */
	public static function delete_entry( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );

		self::invalidate_corpus_cache();

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Bumps hit_count on an entry the matcher just used to answer a
	 * question — for the admin KB screen to show which entries actually get
	 * used (no invalidation needed, hit_count isn't part of the matcher's
	 * scoring corpus).
	 *
	 * @param int $id Entry id.
	 * @return void
	 */
	public static function record_hit( $id ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hit_count = hit_count + 1 WHERE id = %d', absint( $id ) ) );
	}

	/**
	 * Saves an admin's escalation answer back into the KB as a 'learned'
	 * entry — this is the agent's own memory the owner asked for (Phase C
	 * wires this to the escalation-answer REST route; built now so the data
	 * layer needs no later change).
	 *
	 * @param string $question Visitor's original question.
	 * @param string $answer   Admin's answer.
	 * @return int|WP_Error
	 */
	public static function learn( $question, $answer ) {
		return self::create_entry(
			array(
				'question'           => $question,
				'answer'             => $answer,
				'keywords'           => '',
				'related_service_id' => null,
				'source'             => self::SOURCE_LEARNED,
				'is_active'          => true,
			)
		);
	}

	/**
	 * Builds (or returns the cached) matcher corpus: one entry per active KB
	 * row (question+answer+keywords tokenized together), one entry per
	 * published leaf service (name + a price/unit sentence, since services
	 * have no free-text description), and — only when the admin has opted
	 * into 'learn_from_content' — one entry per FAQ-shaped heading found on
	 * a published Page/Post (see class-service-crew-agent-content-extractor.php).
	 * Each corpus entry's `id` is an opaque string the caller
	 * (class-service-crew-agent.php) decodes to resolve the actual answer —
	 * "kb:<id>" / "service:<id>" re-fetch from the DB; "post_faq:<id>:<n>"
	 * resolves straight from this same array's `answer`/`label` fields,
	 * since there's no DB row to re-fetch for a computed-on-the-fly entry.
	 *
	 * @return array<int,array{id:string,tokens:string[],answer?:string,label?:string}>
	 */
	public static function build_corpus() {
		$cached = get_transient( self::CORPUS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$corpus = array();

		foreach ( self::get_entries( true ) as $entry ) {
			$text = $entry->question . ' ' . $entry->answer . ' ' . $entry->keywords;
			$corpus[] = array(
				'id'     => 'kb:' . $entry->id,
				'tokens' => Service_Crew_Agent_Matcher::tokenize( $text ),
			);
		}

		foreach ( self::get_leaf_services() as $leaf ) {
			$pricing = $leaf['pricing'] ?? array();
			$price   = isset( $pricing['price'] ) ? (float) $pricing['price'] : 0.0;
			$unit    = isset( $pricing['unit_label'] ) && $pricing['unit_label'] ? $pricing['unit_label'] : 'job';

			$text = sprintf(
				'%1$s price cost how much $%2$s per %3$s',
				$leaf['title'],
				number_format( $price, 2 ),
				$unit
			);

			$corpus[] = array(
				'id'     => 'service:' . $leaf['id'],
				'tokens' => Service_Crew_Agent_Matcher::tokenize( $text ),
			);
		}

		$settings = Service_Crew_Agent_Settings::get_saved_settings();
		if ( ! empty( $settings['learn_from_content'] ) ) {
			$corpus = array_merge( $corpus, Service_Crew_Agent_Content_Extractor::get_corpus_entries() );
		}

		set_transient( self::CORPUS_TRANSIENT, $corpus, HOUR_IN_SECONDS );

		return $corpus;
	}

	/**
	 * Flattens Service_Crew_Services_Shortcode::get_tree() (the same
	 * published-service tree the public browsing widget uses) down to only
	 * its priced leaf nodes — reuses that class's own "a category carries no
	 * price of its own" resolution rather than re-deriving it here.
	 *
	 * Public since Phase B's class-service-crew-agent-flows.php also needs
	 * this list, to match a visitor's free-text service name against a
	 * real leaf service during the FLOW_BOOK conversational flow.
	 *
	 * @return array<int,array<string,mixed>> Flat list of leaf tree nodes.
	 */
	public static function get_leaf_services() {
		$leaves = array();

		$walk = function ( array $nodes ) use ( &$walk, &$leaves ) {
			foreach ( $nodes as $node ) {
				if ( ! empty( $node['has_children'] ) ) {
					$walk( $node['children'] );
				} else {
					$leaves[] = $node;
				}
			}
		};

		$walk( Service_Crew_Services_Shortcode::get_tree() );

		return $leaves;
	}

	/**
	 * @return void
	 */
	public static function invalidate_corpus_cache() {
		delete_transient( self::CORPUS_TRANSIENT );
	}
}
