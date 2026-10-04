<?php
/**
 * Extracts FAQ-style question/answer pairs from published Pages and Posts
 * for the Agent chat sales agent's corpus (see
 * class-service-crew-agent-kb.php's build_corpus(), which merges this in
 * when the admin-opt-in 'learn_from_content' setting is on). Deliberately
 * strict FAQ-only, by explicit product decision — a heading only produces a
 * chunk when it reads like a question (is_question_heading()); any other
 * page content (plain prose, non-question headings) is never surfaced to a
 * chat visitor, no generic fallback.
 *
 * Computed fresh on every Service_Crew_Agent_KB::build_corpus() cache miss,
 * never written to sc_agent_kb_entries — same "recompute from the live
 * source, no parallel row to go stale" precedent that class's own leaf
 * `sc_service` price facts already follow. Pure read: never calls
 * the_content()/do_shortcode() on a post, so no third-party shortcode/embed
 * handler runs during a chat-triggered rebuild.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Agent_Content_Extractor {

	/**
	 * Safety rails — hardcoded, not admin-tunable (same spirit as the
	 * matcher's own hardcoded ambiguous-gap constant). MAX_TOTAL_CHUNKS is
	 * the real ceiling: Service_Crew_Agent_Matcher::rank() scores every
	 * corpus entry on every chat message with no indexing, so this bounds
	 * how much a large site can grow that per-message cost.
	 *
	 * @var int
	 */
	const MAX_POSTS_SCANNED   = 300;
	const MAX_CHUNKS_PER_POST = 6;
	const MAX_TOTAL_CHUNKS    = 400;
	const MAX_ANSWER_CHARS    = 600;
	const MIN_ANSWER_CHARS    = 15;

	/**
	 * A heading counts as FAQ-shaped if it ends in '?' or starts with one of
	 * these — deliberately simple keyword heuristic, consistent with the
	 * matcher's own "crude, not real NLP" design.
	 *
	 * @var string[]
	 */
	const QUESTION_LEAD_WORDS = array(
		'what', 'how', 'why', 'when', 'where', 'who', 'which',
		'can', 'could', 'do', 'does', 'did', 'is', 'are',
		'will', 'would', 'should',
	);

	/**
	 * Block types treated as "transparent" layout wrappers — recursed into
	 * rather than skipped, so a heading/paragraph nested inside a columns or
	 * group layout still gets found.
	 *
	 * @var string[]
	 */
	const CONTAINER_BLOCKS = array( 'core/group', 'core/columns', 'core/column', 'core/cover' );

	/**
	 * Block types whose rendered text can become part of an answer. Nested
	 * list items are handled by render_block() reconstructing core/list's
	 * full inner markup, not by listing core/list-item here separately.
	 *
	 * @var string[]
	 */
	const ANSWER_BLOCKS = array( 'core/paragraph', 'core/list', 'core/quote' );

	/**
	 * Main entry point consumed by Service_Crew_Agent_KB::build_corpus().
	 *
	 * @return array<int,array{id:string,tokens:string[],answer:string,label:string}>
	 */
	public static function get_corpus_entries() {
		$entries = array();
		$total   = 0;

		foreach ( self::get_eligible_posts() as $post ) {
			$chunks = array_slice( self::extract_faq_chunks_from_post( $post ), 0, self::MAX_CHUNKS_PER_POST );

			foreach ( $chunks as $index => $chunk ) {
				$entries[] = array(
					'id'     => 'post_faq:' . $post->ID . ':' . $index,
					'tokens' => Service_Crew_Agent_Matcher::tokenize( $chunk['heading'] . ' ' . $chunk['answer'] ),
					'answer' => $chunk['answer'],
					'label'  => $chunk['heading'],
				);

				++$total;

				if ( $total >= self::MAX_TOTAL_CHUNKS ) {
					return $entries;
				}
			}
		}

		return $entries;
	}

	/**
	 * Published Pages/Posts only, password-protected ones excluded (publish
	 * status alone doesn't exclude those), newest-modified first so the
	 * global chunk cap (when hit) favors the freshest content.
	 *
	 * @return WP_Post[]
	 */
	private static function get_eligible_posts() {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_POSTS_SCANNED,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		return array_values(
			array_filter(
				$posts,
				function ( $post ) {
					return '' === (string) $post->post_password;
				}
			)
		);
	}

	/**
	 * @param WP_Post $post Post to scan.
	 * @return array<int,array{heading:string,answer:string}>
	 */
	private static function extract_faq_chunks_from_post( $post ) {
		if ( has_blocks( $post->post_content ) ) {
			return self::chunks_from_flat_blocks( self::flatten_blocks( parse_blocks( $post->post_content ) ) );
		}

		return self::extract_faq_chunks_from_raw_html( $post->post_content );
	}

	/**
	 * Recursively inlines CONTAINER_BLOCKS' innerBlocks into one flat,
	 * document-order sequence, so a heading/paragraph nested inside a
	 * columns/group layout is walked the same as one at the top level.
	 *
	 * @param array[] $blocks parse_blocks() output (or a block's innerBlocks).
	 * @return array[]
	 */
	private static function flatten_blocks( array $blocks ) {
		$flat = array();

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			if ( in_array( $name, self::CONTAINER_BLOCKS, true ) && ! empty( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, self::flatten_blocks( $block['innerBlocks'] ) );
				continue;
			}

			$flat[] = $block;
		}

		return $flat;
	}

	/**
	 * Walks a flat block sequence, opening a chunk at each question-shaped
	 * heading and accumulating ANSWER_BLOCKS' text until the next heading
	 * (question-shaped or not) closes it. Everything under a non-question
	 * heading is skipped entirely — strict FAQ-only, no generic fallback.
	 *
	 * @param array[] $blocks Flattened blocks, document order.
	 * @return array<int,array{heading:string,answer:string}>
	 */
	private static function chunks_from_flat_blocks( array $blocks ) {
		$chunks          = array();
		$current_heading = null;
		$current_parts   = array();

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			if ( 'core/heading' === $name ) {
				if ( null !== $current_heading && self::is_question_heading( $current_heading ) ) {
					$chunk = self::finalize_chunk( $current_heading, $current_parts );
					if ( $chunk ) {
						$chunks[] = $chunk;
					}
				}

				$current_heading = self::html_to_plain_text( render_block( $block ) );
				$current_parts   = array();
				continue;
			}

			if ( null === $current_heading || ! self::is_question_heading( $current_heading ) ) {
				continue;
			}

			if ( null === $name ) {
				// Classic/freeform text sitting between real blocks.
				$text = self::html_to_plain_text( $block['innerHTML'] ?? '' );
			} elseif ( in_array( $name, self::ANSWER_BLOCKS, true ) ) {
				$text = self::html_to_plain_text( render_block( $block ) );
			} else {
				// Media/embed/gallery/html/button/spacer/etc. — contributes
				// nothing, and deliberately doesn't close the open heading.
				continue;
			}

			if ( '' !== $text ) {
				$current_parts[] = $text;
			}
		}

		if ( null !== $current_heading && self::is_question_heading( $current_heading ) ) {
			$chunk = self::finalize_chunk( $current_heading, $current_parts );
			if ( $chunk ) {
				$chunks[] = $chunk;
			}
		}

		return $chunks;
	}

	/**
	 * Classic-editor fallback: walks real DOM headings/paragraphs/lists/
	 * quotes in document order via DOMXPath, same question-heading-scoped
	 * accumulation logic as chunks_from_flat_blocks().
	 *
	 * @param string $html Raw post_content (no block comments).
	 * @return array<int,array{heading:string,answer:string}>
	 */
	private static function extract_faq_chunks_from_raw_html( $html ) {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return array();
		}

		$dom = new DOMDocument();
		$previous_setting = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6|//p|//ul|//ol|//blockquote' );

		if ( ! $nodes ) {
			return array();
		}

		$chunks          = array();
		$current_heading = null;
		$current_parts   = array();

		foreach ( $nodes as $node ) {
			$is_heading = (bool) preg_match( '/^h[1-6]$/i', $node->nodeName );
			$node_html  = $dom->saveHTML( $node );

			if ( $is_heading ) {
				if ( null !== $current_heading && self::is_question_heading( $current_heading ) ) {
					$chunk = self::finalize_chunk( $current_heading, $current_parts );
					if ( $chunk ) {
						$chunks[] = $chunk;
					}
				}

				$current_heading = self::html_to_plain_text( $node_html );
				$current_parts   = array();
				continue;
			}

			if ( null === $current_heading || ! self::is_question_heading( $current_heading ) ) {
				continue;
			}

			$text = self::html_to_plain_text( $node_html );
			if ( '' !== $text ) {
				$current_parts[] = $text;
			}
		}

		if ( null !== $current_heading && self::is_question_heading( $current_heading ) ) {
			$chunk = self::finalize_chunk( $current_heading, $current_parts );
			if ( $chunk ) {
				$chunks[] = $chunk;
			}
		}

		return $chunks;
	}

	/**
	 * @param string $heading_text Plain-text heading.
	 * @return bool
	 */
	public static function is_question_heading( $heading_text ) {
		$heading_text = trim( (string) $heading_text );

		if ( '' === $heading_text ) {
			return false;
		}

		if ( '?' === substr( $heading_text, -1 ) ) {
			return true;
		}

		$first_word = strtolower( (string) strtok( $heading_text, " \t\n\r" ) );
		$first_word = preg_replace( '/[^a-z0-9]/', '', $first_word );

		return in_array( $first_word, self::QUESTION_LEAD_WORDS, true );
	}

	/**
	 * Strips an HTML fragment down to clean plain text — comments, scripts/
	 * styles, and unresolved shortcodes removed; tags stripped with
	 * whitespace inserted so words don't smash together; entities decoded.
	 * Does not truncate — that's finalize_chunk()'s job, applied once to the
	 * whole accumulated answer rather than per-block.
	 *
	 * @param string $html Raw HTML fragment.
	 * @return string
	 */
	public static function html_to_plain_text( $html ) {
		$html = (string) $html;
		$html = preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html );
		$html = strip_shortcodes( $html );

		$text = wp_strip_all_tags( $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Joins accumulated answer parts, drops too-short/bare-URL-only results,
	 * and truncates to MAX_ANSWER_CHARS on a word boundary.
	 *
	 * @param string   $heading Question-shaped heading text.
	 * @param string[] $parts   Plain-text pieces collected under it.
	 * @return array{heading:string,answer:string}|null
	 */
	private static function finalize_chunk( $heading, array $parts ) {
		$answer = trim( preg_replace( '/\s+/', ' ', implode( ' ', $parts ) ) );

		if ( strlen( $answer ) < self::MIN_ANSWER_CHARS ) {
			return null;
		}

		if ( preg_match( '#^https?://\S+$#', $answer ) ) {
			return null;
		}

		return array(
			'heading' => $heading,
			'answer'  => self::truncate( $answer, self::MAX_ANSWER_CHARS ),
		);
	}

	/**
	 * @param string $text     Text to truncate.
	 * @param int    $max_chars Hard cap.
	 * @return string
	 */
	private static function truncate( $text, $max_chars ) {
		if ( strlen( $text ) <= $max_chars ) {
			return $text;
		}

		$truncated  = substr( $text, 0, $max_chars );
		$last_space = strrpos( $truncated, ' ' );

		if ( false !== $last_space ) {
			$truncated = substr( $truncated, 0, $last_space );
		}

		return rtrim( $truncated ) . '…';
	}
}
