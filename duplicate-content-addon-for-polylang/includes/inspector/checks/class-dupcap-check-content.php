<?php
/**
 * Content checks: posts, pages, categories, tags missing translations.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Check_Content extends DUPCAP_Check {

	/**
	 * Posts loaded per query. Not a limit: every post is scanned, this many at
	 * a time, so memory stays flat however many posts a site has.
	 */
	const BATCH_SIZE = 200;

	/**
	 * MD5 fingerprint of title + body (Gutenberg / classic only).
	 *
	 * @var string
	 */
	const META_CONTENT_FP = '_dupcap_content_fp';

	/**
	 * Unix GMT timestamp of the last time {@see self::META_CONTENT_FP} changed.
	 *
	 * @var string
	 */
	const META_CONTENT_CHANGED_AT = '_dupcap_content_changed_at';

	/**
	 * Per-language sync baselines on the default-language post.
	 * Shape: array<string, int> language slug => Unix GMT of last known sync.
	 *
	 * Used so a catch-up re-translation (secondary newer after default was
	 * updated) is not flagged as an off-source edit.
	 *
	 * @var string
	 */
	const META_CONTENT_PAIR_SYNC = '_dupcap_content_pair_sync';

	protected $group = 'Content';

	/**
	 * Register save hooks so fingerprints update when content is edited,
	 * not only during a Scan Website run.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		// Gutenberg saves via the REST API; wp_after_insert_post runs after the
		// final post_content is written. save_post alone can fire too early.
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_after_insert_post' ), 20, 3 );
		add_action( 'elementor/editor/after_save', array( __CLASS__, 'on_elementor_save' ), 25, 2 );
	}

	/**
	 * Sync freshness markers after a post is fully written (classic + Gutenberg).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public static function on_after_insert_post( $post_id, $post, $update ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! $update || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return;
		}

		$checker = new self();
		if ( DUPCAP_Elementor_Document::is_excluded_library_document( $post_id ) ) {
			return;
		}
		$checker->sync_content_change_marker_fresh( $post_id );
	}

	/**
	 * Sync freshness markers after an Elementor editor save.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Elementor document data (unused).
	 * @return void
	 */
	public static function on_elementor_save( $post_id, $data = array() ) {
		unset( $data );
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		$checker = new self();
		if ( DUPCAP_Elementor_Document::is_excluded_library_document( $post_id ) ) {
			return;
		}
		$checker->sync_content_change_marker_fresh( $post_id );
	}

	public function run() {
		$results   = array();
		$languages = $this->active_languages();
		$default   = $this->default_lang();

		if ( count( $languages ) < 2 ) {
			return $results; // Nothing useful to report yet.
		}

		// Posts & Pages: report objects with no language at all.
		$model = $this->model();
		if ( $model && method_exists( $model, 'get_posts_with_no_lang' ) ) {
			$post_types = $this->translated_post_types();

			foreach ( $post_types as $type ) {
				$no_lang = $model->get_posts_with_no_lang( $type, -1 );
				$no_lang = array_values(
					array_filter(
						(array) $no_lang,
						array( $this, 'is_not_elementor_kit' )
					)
				);
				$obj     = get_post_type_object( $type );
				$name    = $obj ? $obj->labels->name : $type;

				if ( ! empty( $no_lang ) ) {
					$n         = count( $no_lang );
					$results[] = $this->result(
						'content_nolang_' . $type,
						DUPCAP_Result::WARNING,
						/* translators: %s: post type name */
						sprintf( __( '%s without a language', 'duplicate-content-addon-for-polylang' ), $name ),
						/* translators: 1: count 2: post type name */
						sprintf( _n( '%1$d %2$s item has no language set and will never show as translated.', '%1$d %2$s items have no language set and will never show as translated.', $n, 'duplicate-content-addon-for-polylang' ), $n, strtolower( $name ) )
					)->with_fix( '#dupcap-assign-default-lang', __( 'Set to default language', 'duplicate-content-addon-for-polylang' ) );
				} else {
					$results[] = $this->result(
						'content_nolang_' . $type,
						DUPCAP_Result::PASS,
						/* translators: %s: post type name */
						sprintf( __( 'All %s have a language', 'duplicate-content-addon-for-polylang' ), strtolower( $name ) ),
						__( 'No orphaned content found.', 'duplicate-content-addon-for-polylang' )
					);
				}
			}

			// Missing and unpublished translations per secondary language.
			$gap_data = $this->get_content_gaps_by_language( $post_types, $languages, $default );
			$this->add_missing_translation_counts( $results, $languages, $default, $gap_data );
			$this->add_draft_translation_counts( $results, $languages, $default, $gap_data );

			// Gutenberg / classic: translation exists but title or body still matches the original.
			$this->add_identical_content_counts( $results, $post_types, $languages, $default );

			// Default language updated → translations lag; or secondary edited off-source.
			$this->add_content_freshness_counts( $results, $post_types, $languages, $default );

			// Gutenberg / classic: links still pointing at the default / wrong language.
			$this->add_content_link_counts( $results, $post_types, $languages, $default );
		}

		// Terms: categories & tags with no language.
		if ( $model && method_exists( $model, 'get_terms_with_no_lang' ) ) {
			$taxonomies = self::polylang_translated_taxonomies();
			foreach ( $taxonomies as $tax ) {
				$no_lang = $model->get_terms_with_no_lang( $tax, -1 );
				$obj     = get_taxonomy( $tax );
				$name    = $obj ? $obj->labels->name : $tax;
				if ( ! empty( $no_lang ) ) {
					$n         = count( $no_lang );
					$results[] = $this->result(
						'term_nolang_' . $tax,
						DUPCAP_Result::WARNING,
						/* translators: %s: taxonomy name */
						sprintf( __( '%s without a language', 'duplicate-content-addon-for-polylang' ), $name ),
						/* translators: 1: count 2: taxonomy name */
						sprintf( __( '%1$d %2$s have no language assigned.', 'duplicate-content-addon-for-polylang' ), $n, strtolower( $name ) )
					)->with_fix( '#dupcap-assign-default-lang', __( 'Set to default language', 'duplicate-content-addon-for-polylang' ) );
				} else {
					$results[] = $this->result(
						'term_nolang_' . $tax,
						DUPCAP_Result::PASS,
						/* translators: %s: taxonomy name */
						sprintf( __( 'All %s have a language', 'duplicate-content-addon-for-polylang' ), strtolower( $name ) ),
						__( 'No orphaned terms found.', 'duplicate-content-addon-for-polylang' )
					);
				}
			}
		}

		// Categories & tags: missing translations per secondary language.
		if ( function_exists( 'pll_get_term_translations' ) ) {
			$taxonomies = self::polylang_translated_taxonomies();
			$this->add_missing_term_translation_counts( $results, $taxonomies, $languages, $default );
		}

		// Post types & taxonomies configured/enabled in Polylang settings.
		$this->add_untranslated_post_types_and_taxonomies_notices( $results );

		return $results;
	}

	/**
	 * Check for candidate custom post types and taxonomies that are not set as translatable in Polylang settings.
	 *
	 * @param DUPCAP_Result[] $results Results array passed by reference.
	 * @return void
	 */
	protected function add_untranslated_post_types_and_taxonomies_notices( &$results ) {
		$untranslated_details = array();

		// Match Polylang's exact settings screen post types: PLL_Settings_CPT constructor.
		$public_post_types = get_post_types( array( 'public' => true, '_builtin' => false ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Polylang host filter.
        $candidate_cpts = array_unique( (array) apply_filters( 'pll_get_post_types', $public_post_types, true ) );

		foreach ( $candidate_cpts as $slug ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			if ( ! $this->is_translated_post_type( $slug ) ) {
				$obj                    = get_post_type_object( $slug );
				$name                   = ( $obj && isset( $obj->labels->name ) && $obj->labels->name ) ? (string) $obj->labels->name : (string) $slug;
				$untranslated_details[] = array(
					'label'        => sprintf(
						/* translators: 1: post type label, 2: slug */
						__( 'Post type "%1$s" (%2$s) is not set as translatable', 'duplicate-content-addon-for-polylang' ),
						$name,
						$slug
					),
					'type'         => 'post_type',
					'slug'         => $slug,
					'name'         => $name,
					'action_url'   => admin_url( 'admin.php?page=mlang_settings' ),
					'action_label' => __( 'Polylang settings', 'duplicate-content-addon-for-polylang' ),
				);
			}
		}

		// Match Polylang's exact settings screen taxonomies: PLL_Settings_CPT constructor.
		$public_taxonomies = get_taxonomies( array( 'public' => true, '_builtin' => false ) );
		$internal_pll_tax  = get_taxonomies( array( '_pll' => true ) );
		$public_taxonomies = array_diff( $public_taxonomies, $internal_pll_tax );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Polylang host filter.
		$candidate_tax = array_unique( (array) apply_filters( 'pll_get_taxonomies', $public_taxonomies, true ) );

		$model = $this->model();
		foreach ( $candidate_tax as $slug ) {
			if ( 'nav_menu' === $slug || 'link_category' === $slug || 'post_format' === $slug ) {
				continue;
			}
			$is_trans = function_exists( 'pll_is_translated_taxonomy' )
				? pll_is_translated_taxonomy( $slug )
				: ( $model && method_exists( $model, 'is_translated_taxonomy' ) ? $model->is_translated_taxonomy( $slug ) : false );

			if ( ! $is_trans ) {
				$obj                    = get_taxonomy( $slug );
				$name                   = ( $obj && isset( $obj->labels->name ) && $obj->labels->name ) ? (string) $obj->labels->name : (string) $slug;
				$untranslated_details[] = array(
					'label'        => sprintf(
						/* translators: 1: taxonomy label, 2: slug */
						__( 'Taxonomy "%1$s" (%2$s) is not set as translatable', 'duplicate-content-addon-for-polylang' ),
						$name,
						$slug
					),
					'type'         => 'taxonomy',
					'slug'         => $slug,
					'name'         => $name,
					'action_url'   => admin_url( 'admin.php?page=mlang_settings' ),
					'action_label' => __( 'Polylang settings', 'duplicate-content-addon-for-polylang' ),
				);
			}
		}

		if ( ! empty( $untranslated_details ) ) {
			$names = array();
			foreach ( $untranslated_details as $item ) {
				$names[] = sprintf( '"%1$s" (%2$s)', $item['name'], $item['slug'] );
			}
			$names_str = implode( ', ', $names );

			$results[] = $this->result(
				'lang_untranslated_types',
				DUPCAP_Result::WARNING,
				__( 'Post types or taxonomies not set as translatable', 'duplicate-content-addon-for-polylang' ),
				sprintf(
					/* translators: %s: comma separated names */
					__( 'The following content types/taxonomies are not set as translatable: %s. Enable them in Polylang settings so translations can be managed.', 'duplicate-content-addon-for-polylang' ),
					$names_str
				)
			)->with_fix( admin_url( 'admin.php?page=mlang_settings' ), __( 'Polylang settings', 'duplicate-content-addon-for-polylang' ) )
			->with_details( array(
				array(
					'label'        => sprintf(
						/* translators: %s: comma separated names */
						__( 'Not set as translatable: %s', 'duplicate-content-addon-for-polylang' ),
						$names_str
					),
					// Without this the row renders with an empty "What is wrong"
					// cell, which reads as though nothing is wrong with it.
					'message'      => __( 'Polylang cannot store translations for these until they are switched on in its settings. Anything already saved in them stays in one language.', 'duplicate-content-addon-for-polylang' ),
					'type'         => 'untranslated_types_summary',
					'action_url'   => admin_url( 'admin.php?page=mlang_settings' ),
					'action_label' => __( 'Polylang settings', 'duplicate-content-addon-for-polylang' ),
				),
			) );
		} else {
			$results[] = $this->result(
				'lang_untranslated_types',
				DUPCAP_Result::PASS,
				__( 'All post types & taxonomies are translatable', 'duplicate-content-addon-for-polylang' ),
				__( 'All public post types and taxonomies are enabled for translation in Polylang.', 'duplicate-content-addon-for-polylang' )
			);
		}
	}

	/**
	 * Whether a post is not an excluded Elementor library type (kit / global widget).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_not_elementor_kit( $post_id ) {
		return ! DUPCAP_Elementor_Document::is_excluded_library_document( (int) $post_id );
	}

	/**
	 * Whether a post is edited with Elementor (content lives in _elementor_data).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_elementor_built( $post_id ) {
		if ( 'elementor_library' === get_post_type( $post_id ) ) {
			return true;
		}
		return 'builder' === get_post_meta( (int) $post_id, DUPCAP_Elementor_Document::META_EDIT_MODE, true );
	}

	/**
	 * Strip Gutenberg markup / HTML and normalize whitespace for comparison.
	 *
	 * @param string $text Raw post title or content.
	 * @return string
	 */
	protected function normalize_comparable_text( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/<!--\s*\/?wp:.*?-->/s', '', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Content fingerprint for freshness checks (title + body + Elementor JSON).
	 *
	 * Prefixed with v3: so an algorithm change can migrate stored hashes without
	 * treating every post as freshly edited.
	 *
	 * @param string $title          Post title.
	 * @param string $content        Post content.
	 * @param string $elementor_data Normalized Elementor JSON, or empty.
	 * @return string
	 */
	protected function content_fingerprint_from_parts( $title, $content, $elementor_data = '' ) {
		// Normalize Gutenberg/HTML so block-attribute churn without real text
		// changes does not look like an edit; real copy changes still hash differently.
		$title   = $this->normalize_comparable_text( (string) $title );
		$content = $this->normalize_comparable_text( (string) $content );
		return 'v3:' . md5( $title . "\0" . $content . "\0" . (string) $elementor_data );
	}

	/**
	 * Stable string form of Elementor document JSON for fingerprinting.
	 *
	 * @param mixed $raw Raw `_elementor_data` meta.
	 * @return string
	 */
	protected function normalize_elementor_payload( $raw ) {
		if ( null === $raw || false === $raw || '' === $raw ) {
			return '';
		}
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() && null !== $decoded ) {
				$raw = $decoded;
			} else {
				return (string) $raw;
			}
		}
		if ( ! is_array( $raw ) ) {
			return '';
		}
		$json = wp_json_encode( $raw );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * Seconds a language must lag before it counts as outdated.
	 *
	 * @return int
	 */
	protected function content_freshness_threshold_seconds() {
		return max( 0, (int) apply_filters( 'dupcap_content_freshness_threshold_seconds', 60 ) );
	}

	/**
	 * Last known sync timestamp for default ↔ translation pair.
	 *
	 * @param int    $root_id  Default-language post ID.
	 * @param string $language Translation language slug.
	 * @return int Unix GMT, or 0.
	 */
	protected function get_pair_sync_at( $root_id, $language ) {
		$root_id  = (int) $root_id;
		$language = sanitize_key( (string) $language );
		if ( $root_id <= 0 || '' === $language ) {
			return 0;
		}

		$map = get_post_meta( $root_id, self::META_CONTENT_PAIR_SYNC, true );
		if ( ! is_array( $map ) || empty( $map[ $language ] ) ) {
			return 0;
		}

		return (int) $map[ $language ];
	}

	/**
	 * Store sync baseline for default ↔ translation pair (no-op if unchanged).
	 *
	 * @param int    $root_id  Default-language post ID.
	 * @param string $language Translation language slug.
	 * @param int    $ts       Unix GMT sync point.
	 * @return void
	 */
	protected function set_pair_sync_at( $root_id, $language, $ts ) {
		$root_id  = (int) $root_id;
		$language = sanitize_key( (string) $language );
		$ts       = (int) $ts;
		if ( $root_id <= 0 || '' === $language || $ts <= 0 ) {
			return;
		}

		if ( $this->get_pair_sync_at( $root_id, $language ) === $ts ) {
			return;
		}

		$map = get_post_meta( $root_id, self::META_CONTENT_PAIR_SYNC, true );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$map[ $language ] = $ts;
		update_post_meta( $root_id, self::META_CONTENT_PAIR_SYNC, $map );
	}

	/**
	 * Classify freshness for one default/secondary pair.
	 *
	 * Updates the pair sync baseline when languages are aligned or when a
	 * secondary catch-up follows a default-language edit (re-translate).
	 *
	 * @param int    $root_id      Default-language post ID.
	 * @param string $language     Secondary language slug.
	 * @param int    $default_ts   Default content changed-at (Unix GMT).
	 * @param int    $secondary_ts Secondary content changed-at (Unix GMT).
	 * @return string One of: ok, source_updated.
	 */
	protected function classify_content_freshness_pair( $root_id, $language, $default_ts, $secondary_ts ) {
		$root_id      = (int) $root_id;
		$language     = sanitize_key( (string) $language );
		$default_ts   = (int) $default_ts;
		$secondary_ts = (int) $secondary_ts;
		$threshold    = $this->content_freshness_threshold_seconds();

		if ( $root_id <= 0 || '' === $language || $default_ts <= 0 || $secondary_ts <= 0 ) {
			return 'ok';
		}

		// Within threshold → treat as synced.
		if ( abs( $default_ts - $secondary_ts ) <= $threshold ) {
			$this->set_pair_sync_at( $root_id, $language, max( $default_ts, $secondary_ts ) );
			return 'ok';
		}

		// Case B: default/source is newer → translation needs review.
		if ( ( $secondary_ts + $threshold ) < $default_ts ) {
			// No sync baseline yet: anchor it at the lagging secondary stamp so
			// a later re-translate (secondary catching up past default) is not
			// mistaken for Case A.
			if ( $this->get_pair_sync_at( $root_id, $language ) <= 0 ) {
				$this->set_pair_sync_at( $root_id, $language, $secondary_ts );
			}
			return 'source_updated';
		}

		// Secondary is ahead of default — normal for AutoPoly / manual translate.
		// Treat as synced so this does not surface as a warning.
		if ( ( $default_ts + $threshold ) < $secondary_ts ) {
			$this->set_pair_sync_at( $root_id, $language, max( $default_ts, $secondary_ts ) );
			return 'ok';
		}

		return 'ok';
	}

	/**
	 * Last time this post's content actually changed (not merely re-saved).
	 *
	 * @param int         $post_id         Post ID.
	 * @param string|null $title           Title, or null to load.
	 * @param string|null $content         Content, or null to load.
	 * @param string|null $elementor_data  Elementor JSON, null to auto-load when built, '' to skip.
	 * @param int         $modified_gmt_ts post_modified_gmt as Unix GMT, or 0 to load.
	 * @return int Unix GMT of last real content change, or 0.
	 */
	protected function sync_content_change_marker( $post_id, $title = null, $content = null, $elementor_data = null, $modified_gmt_ts = 0 ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return 0;
		}

		if ( null === $title || null === $content ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return 0;
			}
			if ( null === $title ) {
				$title = $post->post_title;
			}
			if ( null === $content ) {
				$content = $post->post_content;
			}
		}

		if ( null === $elementor_data ) {
			$elementor_data = $this->is_elementor_built( $post_id )
				? $this->normalize_elementor_payload( get_post_meta( $post_id, DUPCAP_Elementor_Document::META_DATA, true ) )
				: '';
		} else {
			$elementor_data = $this->normalize_elementor_payload( $elementor_data );
		}

		$fingerprint = $this->content_fingerprint_from_parts( (string) $title, (string) $content, (string) $elementor_data );

		if ( $modified_gmt_ts <= 0 ) {
			$modified_gmt_ts = (int) get_post_modified_time( 'U', true, $post_id );
		}

		$stored_fp = (string) get_post_meta( $post_id, self::META_CONTENT_FP, true );
		$stored_at = (int) get_post_meta( $post_id, self::META_CONTENT_CHANGED_AT, true );

		if ( '' === $stored_fp ) {
			update_post_meta( $post_id, self::META_CONTENT_FP, $fingerprint );
			$seed = $modified_gmt_ts > 0 ? $modified_gmt_ts : time();
			update_post_meta( $post_id, self::META_CONTENT_CHANGED_AT, $seed );
			return $seed;
		}

		if ( hash_equals( $stored_fp, $fingerprint ) ) {
			return $stored_at > 0 ? $stored_at : 0;
		}

		// Content differs from the stored fingerprint. Stamp the change with the
		// post's own post_modified, never with the current time: a scan that
		// only now notices an older edit must not make that post look edited
		// "just now", or a default and a translation compared in the same run
		// both land inside the freshness threshold and no gap is reported.
		$changed_at = $modified_gmt_ts > 0 ? $modified_gmt_ts : time();
		update_post_meta( $post_id, self::META_CONTENT_FP, $fingerprint );
		update_post_meta( $post_id, self::META_CONTENT_CHANGED_AT, $changed_at );
		return $changed_at;
	}

	/**
	 * Sync the content-change marker using a direct DB read (no stale object cache).
	 *
	 * @param int $post_id Post ID.
	 * @return int Unix GMT of last real content change, or 0.
	 */
	protected function sync_content_change_marker_fresh( $post_id ) {
		global $wpdb;

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_title, post_content, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
				$post_id
			)
		);
		if ( ! $row ) {
			return 0;
		}

		$modified_ts = 0;
		if ( ! empty( $row->post_modified_gmt ) && '0000-00-00 00:00:00' !== $row->post_modified_gmt ) {
			$modified_ts = (int) strtotime( $row->post_modified_gmt . ' UTC' );
		}

		$elementor = '';
		if ( $this->is_elementor_built( $post_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
					$post_id,
					DUPCAP_Elementor_Document::META_DATA
				)
			);
			$elementor = $this->normalize_elementor_payload( maybe_unserialize( $raw ) );
		}

		wp_cache_delete( $post_id, 'post_meta' );
		clean_post_cache( $post_id );

		return $this->sync_content_change_marker(
			$post_id,
			(string) $row->post_title,
			(string) $row->post_content,
			$elementor,
			$modified_ts
		);
	}

	/**
	 * Flag when the default language is ahead of translations (Case B) or a
	 * secondary language was edited without updating the default (Case A).
	 *
	 * Includes Elementor-built posts (fingerprints cover `_elementor_data`).
	 *
	 * @param array  $results    Results array (by reference).
	 * @param array  $post_types Post types.
	 * @param array  $languages  Languages.
	 * @param string $default    Default language slug.
	 * @return void
	 */
	protected function add_content_freshness_counts( &$results, $post_types, $languages, $default ) {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return;
		}

		$default    = sanitize_key( (string) $default );
		$lang_names = array();
		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$lang_names[ $slug ] = is_object( $lang ) && ! empty( $lang->name )
				? (string) $lang->name
				: DUPCAP_Elementor_Fields::language_label( $slug );
		}

		if ( count( $lang_names ) < 2 || '' === $default || empty( $lang_names[ $default ] ) ) {
			return;
		}

		$source_updated = array();

		foreach ( $post_types as $type ) {
			$posts = $this->each_default_language_post( $type, $default );
			$obj   = get_post_type_object( $type );

			foreach ( $posts as $post ) {
				if ( DUPCAP_Elementor_Document::is_excluded_library_document( $post->ID ) ) {
					continue;
				}
				if ( 'publish' !== get_post_status( $post->ID ) ) {
					continue;
				}

				$translations = pll_get_post_translations( $post->ID );
				if ( ! is_array( $translations ) || empty( $translations ) ) {
					continue;
				}

				$pending = array();
				foreach ( $translations as $slug => $tid ) {
					$slug = sanitize_key( (string) $slug );
					$tid  = (int) $tid;
					if ( '' === $slug || $tid <= 0 || 'publish' !== get_post_status( $tid ) ) {
						continue;
					}
					$pending[ $slug ] = $tid;
				}

				if ( count( $pending ) < 2 || empty( $pending[ $default ] ) ) {
					continue;
				}

				$unseeded = 0;
				foreach ( $pending as $tid ) {
					if ( '' === (string) get_post_meta( $tid, self::META_CONTENT_FP, true ) ) {
						++$unseeded;
					}
				}

				// First sighting: store fingerprints. Use each post's own
				// post_modified as changed-at (not a shared baseline) so a
				// Gutenberg edit that already happened before this scan is
				// still visible as Case A/B. Empty Saves later are ignored
				// because the fingerprint stays the same.
				if ( $unseeded === count( $pending ) ) {
					foreach ( $pending as $tid ) {
						$p = get_post( $tid );
						if ( ! $p ) {
							continue;
						}
						$el = $this->is_elementor_built( $tid )
							? $this->normalize_elementor_payload( get_post_meta( $tid, DUPCAP_Elementor_Document::META_DATA, true ) )
							: '';
						$fp = $this->content_fingerprint_from_parts( $p->post_title, $p->post_content, $el );
						$ts = (int) get_post_modified_time( 'U', true, $tid );
						if ( $ts <= 0 ) {
							$ts = time();
						}
						update_post_meta( $tid, self::META_CONTENT_FP, $fp );
						update_post_meta( $tid, self::META_CONTENT_CHANGED_AT, $ts );
					}
				}

				$members = array();
				foreach ( $pending as $slug => $tid ) {
					$changed_at = $this->sync_content_change_marker_fresh( $tid );
					if ( $changed_at <= 0 ) {
						continue;
					}
					$members[ $slug ] = array(
						'id'       => $tid,
						'modified' => $changed_at,
					);
				}

				if ( count( $members ) < 2 || empty( $members[ $default ] ) ) {
					continue;
				}

				$default_ts  = (int) $members[ $default ]['modified'];
				$group_title = $post->post_title
					? $post->post_title
					: __( '(no title)', 'duplicate-content-addon-for-polylang' );
				$type_label  = ( $obj && ! empty( $obj->labels->singular_name ) )
					? $obj->labels->singular_name
					: __( 'Page', 'duplicate-content-addon-for-polylang' );

				foreach ( $members as $slug => $row ) {
					if ( $slug === $default ) {
						continue;
					}

					$secondary_ts = (int) $row['modified'];
					$lang_name    = isset( $lang_names[ $slug ] )
						? $lang_names[ $slug ]
						: DUPCAP_Elementor_Fields::language_label( $slug );

					$verdict = $this->classify_content_freshness_pair(
						(int) $post->ID,
						$slug,
						$default_ts,
						$secondary_ts
					);

					if ( 'source_updated' === $verdict ) {
						$ignore_key = DUPCAP_Ignored_Issues::content_source_updated_key( $post->ID, $slug );
						if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
							continue;
						}

						$source_updated[] = array(
							'post_id'         => (int) $post->ID,
							'translation_id'  => (int) $row['id'],
							'title'           => $group_title,
							'post_type'       => $post->post_type,
							'post_type_label' => $type_label,
							'language'        => $slug,
							'language_name'   => $lang_name,
							'edit_url'        => get_edit_post_link( $post->ID, 'raw' ),
							'translate_url'   => get_edit_post_link( (int) $row['id'], 'raw' ),
							'gap_type'        => 'source_updated',
							'ignore_key'      => $ignore_key,
							'code'            => 'source_updated',
						);
						continue;
					}
				}
			}
		}

		if ( empty( $source_updated ) ) {
			$results[] = $this->result(
				'content_source_updated',
				DUPCAP_Result::PASS,
				__( 'Translations look up to date with the default language', 'duplicate-content-addon-for-polylang' ),
				__( 'No linked translation looks older than the default-language content.', 'duplicate-content-addon-for-polylang' )
			);
		} else {
			$results[] = $this->result(
				'content_source_updated',
				DUPCAP_Result::WARNING,
				__( 'Default language content was updated', 'duplicate-content-addon-for-polylang' ),
				sprintf(
					/* translators: %d: number of outdated translation rows */
					_n(
						'%d translation may need updating after the default language changed.',
						'%d translations may need updating after the default language changed.',
						count( $source_updated ),
						'duplicate-content-addon-for-polylang'
					),
					count( $source_updated )
				)
			)->with_details( $source_updated );
		}
	}

	/**
	 * Whether a Case B (default newer than translation) row is still present.
	 *
	 * @param int    $root_id  Default-language post ID.
	 * @param string $language Translation language slug.
	 * @return bool
	 */
	public function source_updated_still_present( $root_id, $language ) {
		$root_id  = (int) $root_id;
		$language = sanitize_key( (string) $language );
		$default  = function_exists( 'pll_default_language' ) ? sanitize_key( (string) pll_default_language( 'slug' ) ) : '';

		if ( $root_id <= 0 || '' === $language || '' === $default || $language === $default ) {
			return false;
		}
		if ( ! function_exists( 'pll_get_post' ) ) {
			return false;
		}

		$translation_id = (int) pll_get_post( $root_id, $language );
		if ( $translation_id <= 0 || 'publish' !== get_post_status( $translation_id ) ) {
			return false;
		}

		$default_ts = $this->sync_content_change_marker_fresh( $root_id );
		$other_ts   = $this->sync_content_change_marker_fresh( $translation_id );
		if ( $default_ts <= 0 || $other_ts <= 0 ) {
			return false;
		}

		return 'source_updated' === $this->classify_content_freshness_pair( $root_id, $language, $default_ts, $other_ts );
	}

	/**
	 * Flag linked translations whose title or body still matches the default language.
	 *
	 * Skips Elementor-built posts (handled by the Elementor scanner).
	 *
	 * @param array  $results    Results array (by reference).
	 * @param array  $post_types Post types.
	 * @param array  $languages  Languages.
	 * @param string $default    Default language slug.
	 * @return void
	 */
	protected function add_identical_content_counts( &$results, $post_types, $languages, $default ) {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return;
		}

		$by_language    = array();
		$language_names = array();
		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( $slug !== $default ) {
				$by_language[ $slug ] = array();
			}
			$language_names[ $slug ] = is_object( $lang ) && ! empty( $lang->name )
				? (string) $lang->name
				: DUPCAP_Elementor_Fields::language_label( $slug );
		}

		if ( empty( $by_language ) ) {
			return;
		}

		$field_meta = array(
			'title'   => __( 'Title', 'duplicate-content-addon-for-polylang' ),
			'content' => __( 'Content', 'duplicate-content-addon-for-polylang' ),
		);

		foreach ( $post_types as $type ) {
			$posts = $this->each_default_language_post( $type, $default );

			foreach ( $posts as $post ) {
				if ( DUPCAP_Elementor_Document::is_excluded_library_document( $post->ID ) || $this->is_elementor_built( $post->ID ) ) {
					continue;
				}

				$translations = pll_get_post_translations( $post->ID );
				$source_title = $this->normalize_comparable_text( $post->post_title );
				$source_body  = $this->normalize_comparable_text( $post->post_content );
				$display_title = $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' );

				foreach ( array_keys( $by_language ) as $target_slug ) {
					$translation_id = ! empty( $translations[ $target_slug ] ) ? (int) $translations[ $target_slug ] : 0;
					if ( ! $translation_id || 'publish' !== get_post_status( $translation_id ) ) {
						continue;
					}

					if ( $this->is_elementor_built( $translation_id ) ) {
						continue;
					}

					$translation = get_post( $translation_id );
					if ( ! $translation ) {
						continue;
					}

					$candidates = array(
						'title'   => array( $source_title, $this->normalize_comparable_text( $translation->post_title ) ),
						'content' => array( $source_body, $this->normalize_comparable_text( $translation->post_content ) ),
					);

					foreach ( $candidates as $field => $pair ) {
						list( $original, $translated ) = $pair;
						if ( '' === $original || $original !== $translated ) {
							continue;
						}
						// Skip trivial snippets (counters, symbols) similar to Elementor.
						if ( preg_match( '/^[\d\s\.\,\%\$€£₹\/\-\+]+$/u', $original ) ) {
							continue;
						}

						$ignore_key = DUPCAP_Ignored_Issues::content_identical_key( $post->ID, $target_slug, $field, $original );
						if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
							continue;
						}

						$field_label = isset( $field_meta[ $field ] ) ? $field_meta[ $field ] : $field;
						$snippet     = function_exists( 'mb_substr' ) ? mb_substr( $original, 0, 80 ) : substr( $original, 0, 80 );
						$lang_name   = isset( $language_names[ $target_slug ] )
							? $language_names[ $target_slug ]
							: DUPCAP_Elementor_Fields::language_label( $target_slug );

						$by_language[ $target_slug ][] = array(
							'label'          => sprintf(
								/* translators: 1: field name, e.g. Title 2: post title 3: language name */
								__( '%1$s of "%2$s" (%3$s)', 'duplicate-content-addon-for-polylang' ),
								$field_label,
								$display_title,
								$lang_name ? $lang_name : $target_slug
							),
							'message'        => sprintf(
								/* translators: %s: text that still matches the original language */
								__( 'Not translated yet — still shows: "%s"', 'duplicate-content-addon-for-polylang' ),
								$snippet
							),
							'action_url'     => get_edit_post_link( $translation_id, 'raw' ),
							'action_label'   => __( 'Edit translation', 'duplicate-content-addon-for-polylang' ),
							'ignore_key'     => $ignore_key,
							'post_id'        => (int) $post->ID,
							'translation_id' => (int) $translation_id,
							'language'       => $target_slug,
							'field'          => $field,
							'post_type'      => $post->post_type,
						);
					}
				}
			}
		}

		foreach ( $by_language as $slug => $details ) {
			if ( ! empty( $details ) ) {
				$lang_name  = isset( $language_names[ $slug ] ) ? $language_names[ $slug ] : DUPCAP_Elementor_Fields::language_label( $slug );
				$lang_label = ( $lang_name && strtolower( $lang_name ) !== strtolower( $slug ) )
					? sprintf(
						/* translators: 1: language name, 2: language code */
						__( '%1$s (%2$s)', 'duplicate-content-addon-for-polylang' ),
						$lang_name,
						$slug
					)
					: $slug;

				$result = $this->result(
					'content_identical_' . $slug,
					DUPCAP_Result::WARNING,
					/* translators: %s: language name with code */
					sprintf( __( 'Identical content in "%s"', 'duplicate-content-addon-for-polylang' ), $lang_label ),
					sprintf(
						/* translators: 1: count 2: language name with code */
						_n(
							'%1$d translated item still matches the original text in "%2$s".',
							'%1$d translated items still match the original text in "%2$s".',
							count( $details ),
							'duplicate-content-addon-for-polylang'
						),
						count( $details ),
						$lang_label
					)
				)->with_details( $details );

				$results[] = $result;
			} else {
				$results[] = $this->result(
					'content_identical_' . $slug,
					DUPCAP_Result::PASS,
					/* translators: %s: language slug */
					sprintf( __( 'Translated content differs in "%s"', 'duplicate-content-addon-for-polylang' ), $slug ),
					__( 'Linked translations use different title and body text.', 'duplicate-content-addon-for-polylang' )
				);
			}
		}
	}

	/**
	 * Flag Gutenberg/classic translations whose internal links still use another language.
	 *
	 * @param array  $results    Results array (by reference).
	 * @param array  $post_types Post types.
	 * @param array  $languages  Languages.
	 * @param string $default    Default language slug.
	 * @return void
	 */
	protected function add_content_link_counts( &$results, $post_types, $languages, $default ) {
		if ( ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_get_post_language' ) ) {
			return;
		}

		$by_language = array();
		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			$by_language[ $slug ] = array();
		}

		$seen = array();

		foreach ( $post_types as $type ) {
			$posts = $this->each_default_language_post( $type, $default );

			foreach ( $posts as $post ) {
				if ( DUPCAP_Elementor_Document::is_excluded_library_document( $post->ID ) || $this->is_elementor_built( $post->ID ) ) {
					continue;
				}

				$translations  = pll_get_post_translations( $post->ID );
				$display_title = $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' );

				// Default-language Gutenberg/classic content (e.g. Home → /about missing).
				if ( 'publish' === get_post_status( $post->ID ) ) {
					$this->collect_content_link_issues_for_post(
						$by_language,
						$seen,
						$post,
						$post,
						$default,
						$display_title
					);
				}

				foreach ( array_keys( $by_language ) as $target_slug ) {
					if ( $target_slug === $default ) {
						continue;
					}

					$translation_id = ! empty( $translations[ $target_slug ] ) ? (int) $translations[ $target_slug ] : 0;
					if ( ! $translation_id || 'publish' !== get_post_status( $translation_id ) || $this->is_elementor_built( $translation_id ) ) {
						continue;
					}

					$translation = get_post( $translation_id );
					if ( ! $translation ) {
						continue;
					}

					$this->collect_content_link_issues_for_post(
						$by_language,
						$seen,
						$post,
						$translation,
						$target_slug,
						$display_title
					);
				}
			}
		}

		foreach ( $by_language as $slug => $details ) {
			if ( empty( $details ) ) {
				continue;
			}

			$results[] = $this->result(
				'content_link_' . $slug,
				DUPCAP_Result::WARNING,
				/* translators: %s: language slug */
				sprintf( __( 'Link issues in "%s"', 'duplicate-content-addon-for-polylang' ), $slug ),
				sprintf(
					/* translators: 1: count 2: language slug */
					_n(
						'%1$d link in "%2$s" content points to a missing page or the wrong language.',
						'%1$d links in "%2$s" content point to a missing page or the wrong language.',
						count( $details ),
						'duplicate-content-addon-for-polylang'
					),
					count( $details ),
					$slug
				)
			)->with_details( $details );
		}
	}

	/**
	 * Collect content-link issues from one published post's body.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $by_language Details by language (by ref).
	 * @param array<string, bool>                             $seen        Dedupe map (by ref).
	 * @param WP_Post                                         $source      Default-language source post (ignore-key root).
	 * @param WP_Post                                         $content_post Post whose content is scanned.
	 * @param string                                          $lang_slug   Language of $content_post.
	 * @param string                                          $fallback_title Title fallback.
	 * @return void
	 */
	protected function collect_content_link_issues_for_post( &$by_language, &$seen, $source, $content_post, $lang_slug, $fallback_title ) {
		if ( ! ( $source instanceof WP_Post ) || ! ( $content_post instanceof WP_Post ) ) {
			return;
		}

		$lang_slug = sanitize_key( (string) $lang_slug );
		if ( '' === $lang_slug || ! isset( $by_language[ $lang_slug ] ) ) {
			return;
		}

		foreach ( $this->extract_content_links( $content_post->post_content ) as $link_data ) {
			$url         = isset( $link_data['url'] ) ? (string) $link_data['url'] : '';
			$anchor_text = isset( $link_data['anchor_text'] ) ? $link_data['anchor_text'] : '';
			$context     = isset( $link_data['context'] ) ? $link_data['context'] : '';

			$issue = $this->classify_content_link_issue( $url, $lang_slug );
			if ( empty( $issue ) ) {
				continue;
			}

			$norm   = DUPCAP_Elementor_Fields::normalize_url_for_compare( $url );
			$dedupe = (int) $content_post->ID . '|' . $lang_slug . '|' . $issue['code'] . '|' . $norm;
			if ( isset( $seen[ $dedupe ] ) ) {
				continue;
			}
			$seen[ $dedupe ] = true;

			$ignore_key = DUPCAP_Ignored_Issues::content_link_key( $source->ID, $lang_slug, $issue['code'], $url );
			if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
				continue;
			}

			$type_obj   = get_post_type_object( $content_post->post_type );
			$type_label = ( $type_obj && ! empty( $type_obj->labels->singular_name ) )
				? $type_obj->labels->singular_name
				: __( 'Page', 'duplicate-content-addon-for-polylang' );
			$lang_label = DUPCAP_Elementor_Fields::language_label( $lang_slug );
			$row_title  = $content_post->post_title
				? $content_post->post_title
				: ( $fallback_title ? $fallback_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ) );

			$snippet_source = '' !== $anchor_text ? $anchor_text : $context;
			$link_text      = '' !== $snippet_source
				? ( ( function_exists( 'mb_strlen' ) && mb_strlen( $snippet_source, 'UTF-8' ) > 40 )
					? mb_substr( $snippet_source, 0, 39, 'UTF-8' ) . '…'
					: $snippet_source )
				: '';

			$is_dead = ( isset( $issue['code'] ) && 'dead_link' === $issue['code'] );

			$by_language[ $lang_slug ][] = array(
				'label'          => '' !== $link_text
					? sprintf(
						/* translators: 1: link text 2: post type label, e.g. Post 3: post title 4: language name */
						__( 'Link "%1$s" in the %2$s "%3$s" (%4$s)', 'duplicate-content-addon-for-polylang' ),
						$link_text,
						$type_label,
						$row_title,
						$lang_label
					)
					: sprintf(
						/* translators: 1: post type label, e.g. Post 2: post title 3: language name */
						__( 'Link in the %1$s "%2$s" (%3$s)', 'duplicate-content-addon-for-polylang' ),
						$type_label,
						$row_title,
						$lang_label
					),
				'message'        => $issue['message'],
				'action_url'     => get_edit_post_link( $content_post->ID, 'raw' ),
				'action_label'   => $is_dead
					? __( 'Edit page with link', 'duplicate-content-addon-for-polylang' )
					: __( 'Fix link', 'duplicate-content-addon-for-polylang' ),
				'ignore_key'     => $ignore_key,
				'code'           => $issue['code'],
				'kind'           => isset( $issue['kind'] ) ? (string) $issue['kind'] : '',
				'language'       => $lang_slug,
				'current_url'    => isset( $issue['current_url'] ) ? $issue['current_url'] : '',
				'suggested_url'  => isset( $issue['suggested_url'] ) ? $issue['suggested_url'] : '',
				'page_fix_url'   => isset( $issue['page_fix_url'] ) ? (string) $issue['page_fix_url'] : '',
				'page_fix_label' => isset( $issue['page_fix_label'] ) ? (string) $issue['page_fix_label'] : '',
				'url'            => $url,
				'post_id'        => (int) $source->ID,
				'translation_id' => (int) $content_post->ID,
				'post_type'      => $content_post->post_type,
				'anchor_text'    => $anchor_text,
				'context'        => $context,
			);
		}
	}

	/**
	 * Public entry points for the dashboard's single-row rescan.
	 *
	 * The scan's own helpers are protected because nothing outside the check
	 * should drive a scan. Rescanning one row is the one exception: it has to
	 * reach the exact same detection the full scan uses, or a fixed row is
	 * judged by different rules than the row that reported it.
	 *
	 * @since 2.0.7
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_elementor_built_for_rescan( $post_id ) {
		return $this->is_elementor_built( $post_id );
	}

	/**
	 * Links in a post's content, for the single-row rescan.
	 *
	 * @since 2.0.7
	 *
	 * @param string $content Post content.
	 * @return array<int, array<string, string>>
	 */
	public function extract_content_links_for_rescan( $content ) {
		return $this->extract_content_links( $content );
	}

	/**
	 * Verdict on one link, for the single-row rescan.
	 *
	 * @since 2.0.7
	 *
	 * @param string $url  Link URL.
	 * @param string $lang Target language slug.
	 * @return array<string, string>
	 */
	public function classify_content_link_issue_for_rescan( $url, $lang ) {
		return $this->classify_content_link_issue( $url, $lang );
	}

	/**
	 * Whether one title/content field still matches the source text.
	 *
	 * Mirrors the identical-text test in add_identical_translation_rows(),
	 * including the trivial-snippet skip, so the rescan and the full scan agree.
	 * Reads both posts straight from the database: the user has just saved an
	 * edit in another tab and the object cache can still hold the old row.
	 *
	 * @since 2.0.7
	 *
	 * @param int    $post_id        Source post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $field          title|content.
	 * @return bool
	 */
	public function identical_field_still_present( $post_id, $translation_id, $field ) {
		$post_id        = (int) $post_id;
		$translation_id = (int) $translation_id;
		$field          = sanitize_key( (string) $field );

		if ( $post_id <= 0 || $translation_id <= 0 || ! in_array( $field, array( 'title', 'content' ), true ) ) {
			return true;
		}

		// The scan skips Elementor-built translations, so there is nothing to report.
		if ( $this->is_elementor_built( $translation_id ) ) {
			return false;
		}

		global $wpdb;
		clean_post_cache( $post_id );
		clean_post_cache( $translation_id );

		// Both columns in one prepared query, so the field never reaches the SQL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh read after cache bust; the user just saved this post.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE ID IN ( %d, %d )",
				$post_id,
				$translation_id
			),
			OBJECT_K
		);

		if ( empty( $rows[ $post_id ] ) || empty( $rows[ $translation_id ] ) ) {
			return false;
		}

		$source = ( 'title' === $field ) ? $rows[ $post_id ]->post_title : $rows[ $post_id ]->post_content;
		$target = ( 'title' === $field ) ? $rows[ $translation_id ]->post_title : $rows[ $translation_id ]->post_content;

		$original   = $this->normalize_comparable_text( (string) $source );
		$translated = $this->normalize_comparable_text( (string) $target );

		if ( '' === $original || $original !== $translated ) {
			return false;
		}

		// Counters and symbol-only strings are never reported by the scan.
		if ( preg_match( '/^[\d\s\.\,\%\$€£₹\/\-\+]+$/u', $original ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Extract internal href links from post content.
	 *
	 * @param string $content Post content.
	 * @return array<int, array<string, string>> Rows of url, anchor_text, context.
	 */
	protected function extract_content_links( $content ) {
			$content = (string) $content;
			if ( '' === $content || false === stripos( $content, 'href' ) ) {
				return array();
			}

			if ( ! class_exists( 'DOMDocument' ) ) {
				return $this->extract_content_links_fallback( $content );
			}

			$links = array();
			$doc   = new DOMDocument();
			$prev  = libxml_use_internal_errors( true );
			$doc->loadHTML(
				'<?xml encoding="UTF-8"><div id="dupcap-root">' . $content . '</div>',
				LIBXML_NOERROR | LIBXML_NOWARNING
			);
			libxml_use_internal_errors( $prev );

			$anchors = $doc->getElementsByTagName( 'a' );

			foreach ( $anchors as $anchor ) {
				$href = trim( (string) $anchor->getAttribute( 'href' ) );
				if ( '' === $href || '#' === $href[0] ) {
					continue;
				}
				if ( DUPCAP_Elementor_Fields::is_media_asset_url( $href ) || ! DUPCAP_Elementor_Fields::is_internal_url( $href ) ) {
					continue;
				}

				$anchor_text = trim( preg_replace( '/\s+/u', ' ', $anchor->textContent ) );
				$context     = $this->find_link_context_text( $anchor );
				$norm        = DUPCAP_Elementor_Fields::normalize_url_for_compare( $href );

				if ( ! isset( $links[ $norm ] ) ) {
					$links[ $norm ] = array(
						'url'         => $href,
						'anchor_text' => $anchor_text,
						'context'     => $context,
					);
				}
			}

			return array_values( $links );
		}

		/**
		 * Fallback anchor extraction without DOMDocument (href only, no context).
		 *
		 * @param string $content Post content.
		 * @return array<int, array{url:string, anchor_text:string, context:string}>
		 */
		protected function extract_content_links_fallback( $content ) {
			if ( ! preg_match_all( '/\bhref\s*=\s*([\'"])(.*?)\1/i', $content, $matches ) ) {
				return array();
			}

			$links = array();
			foreach ( $matches[2] as $url ) {
				$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
				if ( '' === $url || '#' === $url[0] ) {
					continue;
				}
				if ( DUPCAP_Elementor_Fields::is_media_asset_url( $url ) || ! DUPCAP_Elementor_Fields::is_internal_url( $url ) ) {
					continue;
				}
				$norm = DUPCAP_Elementor_Fields::normalize_url_for_compare( $url );
				if ( ! isset( $links[ $norm ] ) ) {
					$links[ $norm ] = array(
						'url'         => $url,
						'anchor_text' => '',
						'context'     => '',
					);
				}
			}

			return array_values( $links );
		}

		/**
		 * Short readable snippet of the block containing a link (paragraph, list
		 * item, heading, etc.) so the user knows where to look in the editor.
		 *
		 * @param DOMElement $anchor Anchor element.
		 * @return string
		 */
		protected function find_link_context_text( $anchor ) {
			$block_tags = array( 'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'blockquote', 'figcaption' );
			$node       = $anchor->parentNode;
			$depth      = 0;

			while ( $node && $depth < 6 ) {
				if ( XML_ELEMENT_NODE === $node->nodeType && in_array( strtolower( $node->nodeName ), $block_tags, true ) ) {
					$text = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );
					if ( '' !== $text ) {
						return ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > 140 )
							? mb_substr( $text, 0, 140, 'UTF-8' ) . '…'
							: $text;
					}
				}
				$node = $node->parentNode;
				$depth++;
			}

			return '';
		}

	/**
	 * Classify one content link (wrong language or missing / unpublished target).
	 *
	 * @param string $url  Link URL.
	 * @param string $lang Expected language slug of the page that contains the link.
	 * @return array{code:string,message:string,current_url:string,suggested_url?:string,kind?:string,page_fix_url?:string,page_fix_label?:string}|array
	 */
	protected function classify_content_link_issue( $url, $lang ) {
		$url     = trim( (string) $url );
		$lang    = sanitize_key( (string) $lang );
		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

		if ( '' === $url ) {
			return array();
		}

		// Missing page, Draft/Private/password-protected, etc. — same rules as menus/Elementor.
		if ( ! DUPCAP_Elementor_Fields::url_has_published_target( $url, $default ) ) {
			$current_label = DUPCAP_Elementor_Fields::readable_url( $url );
			$current_path  = DUPCAP_Elementor_Fields::relative_path( $url );
			$target        = DUPCAP_Elementor_Fields::describe_dead_link_target( $url, $default );
			$kind          = isset( $target['kind'] ) ? (string) $target['kind'] : 'missing';

			return array(
				'code'           => 'dead_link',
				'kind'           => $kind,
				'current_url'    => $current_path ? $current_path : $url,
				'suggested_url'  => '',
				'page_fix_url'   => isset( $target['fix_url'] ) ? (string) $target['fix_url'] : '',
				'page_fix_label' => isset( $target['fix_label'] ) ? (string) $target['fix_label'] : '',
				'message'        => DUPCAP_Elementor_Fields::format_dead_link_detail(
					$current_label ? $current_label : ( $current_path ? $current_path : $url ),
					$kind
				),
			);
		}

		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return array();
		}

		$post_id = $this->resolve_url_to_post_id( $url );
		if ( $post_id <= 0 ) {
			return array();
		}

		$post_lang = pll_get_post_language( $post_id, 'slug' );
		if ( ! is_string( $post_lang ) || '' === $post_lang || $post_lang === $lang ) {
			return array();
		}

		$lang_name       = DUPCAP_Elementor_Fields::language_label( $lang );
		$current_label   = DUPCAP_Elementor_Fields::readable_url( $url );
		$current_path    = DUPCAP_Elementor_Fields::relative_path( $url );
		$suggested_url   = '';
		$suggested_label = '';

		if ( function_exists( 'pll_get_post' ) ) {
			$translated_id = (int) pll_get_post( $post_id, $lang );
			if ( $translated_id > 0 ) {
				$translated_post = get_post( $translated_id );
				if ( $translated_post instanceof WP_Post && 'publish' === $translated_post->post_status ) {
					$permalink = get_permalink( $translated_id );
					if ( is_string( $permalink ) && '' !== $permalink ) {
						$suggested_url   = DUPCAP_Elementor_Fields::relative_path( $permalink );
						$suggested_label = DUPCAP_Elementor_Fields::readable_url( $permalink, $lang );
					}
				}
			}
		}

		if ( '' === $suggested_url ) {
			// Target exists in another language but has no published translation for this page's language.
			return array();
		}

		return array(
			'code'          => 'untranslated_link',
			'current_url'   => $current_path,
			'suggested_url' => $suggested_url,
			'message'       => DUPCAP_Elementor_Fields::format_link_issue_detail(
				$current_label ? $current_label : '/',
				$lang_name,
				$suggested_label ? $suggested_label : $suggested_url
			),
		);
	}

	/**
	 * Resolve an internal URL to a post ID.
	 *
	 * @param string $url URL or path.
	 * @return int
	 */
	protected function resolve_url_to_post_id( $url ) {
		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
		return DUPCAP_Elementor_Fields::resolve_url_to_post_id( $url, $default );
	}

	/**
	 * For each secondary language, count default-language posts that lack a translation.
	 *
	 * @param array  $results   Results array (by reference).
	 * @param array  $languages Languages.
	 * @param string $default   Default slug.
	 * @param array  $gap_data  Output from get_content_gaps_by_language().
	 */
	protected function add_missing_translation_counts( &$results, $languages, $default, array $gap_data ) {
		$pending_by_lang = isset( $gap_data['missing_by_language'] ) ? $gap_data['missing_by_language'] : array();

		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( $slug === $default ) {
				continue;
			}

			$pending_for_lang = isset( $pending_by_lang[ $slug ] ) ? $pending_by_lang[ $slug ] : array();
			$missing          = count( $pending_for_lang );

			if ( $missing > 0 ) {
				$result = $this->result(
					'content_missing_' . $slug,
					DUPCAP_Result::WARNING,
					/* translators: %s: language slug */
					sprintf( __( 'Content missing in "%s"', 'duplicate-content-addon-for-polylang' ), $slug ),
					/* translators: 1: count 2: language slug */
					sprintf( _n( '%1$d item is not yet translated into "%2$s".', '%1$d items are not yet translated into "%2$s".', $missing, 'duplicate-content-addon-for-polylang' ), $missing, $slug )
				)->with_fix( admin_url( 'edit.php?lang=' . $slug ), __( 'Translate', 'duplicate-content-addon-for-polylang' ) );

				if ( ! empty( $pending_for_lang ) ) {
					$result->with_details( $pending_for_lang );
				}

				$results[] = $result;
			} else {
				$results[] = $this->result(
					'content_missing_' . $slug,
					DUPCAP_Result::PASS,
					/* translators: %s: language slug */
					sprintf( __( 'Content complete in "%s"', 'duplicate-content-addon-for-polylang' ), $slug ),
					__( 'All content has a translation.', 'duplicate-content-addon-for-polylang' )
				);
			}
		}
	}

	/**
	 * For each secondary language, report translations that exist but are not published.
	 *
	 * @param array  $results   Results array (by reference).
	 * @param array  $languages Languages.
	 * @param string $default   Default slug.
	 * @param array  $gap_data  Output from get_content_gaps_by_language().
	 */
	protected function add_draft_translation_counts( &$results, $languages, $default, array $gap_data ) {
		$draft_by_lang = isset( $gap_data['draft_by_language'] ) ? $gap_data['draft_by_language'] : array();

		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( $slug === $default ) {
				continue;
			}

			$draft_for_lang = isset( $draft_by_lang[ $slug ] ) ? $draft_by_lang[ $slug ] : array();
			$draft_count    = count( $draft_for_lang );

			if ( $draft_count > 0 ) {
				$result = $this->result(
					'content_draft_' . $slug,
					DUPCAP_Result::WARNING,
					/* translators: %s: language slug */
					sprintf( __( 'Unpublished translations in "%s"', 'duplicate-content-addon-for-polylang' ), $slug ),
					sprintf(
						/* translators: 1: count 2: language slug */
						_n(
							'%1$d translation exists in "%2$s" but is not published yet (for example, still in draft).',
							'%1$d translations exist in "%2$s" but are not published yet (for example, still in draft).',
							$draft_count,
							'duplicate-content-addon-for-polylang'
						),
						$draft_count,
						$slug
					)
				)->with_fix( admin_url( 'edit.php?lang=' . $slug ), __( 'Review', 'duplicate-content-addon-for-polylang' ) );

				if ( ! empty( $draft_for_lang ) ) {
					$result->with_details( $draft_for_lang );
				}

				$results[] = $result;
			} else {
				$results[] = $this->result(
					'content_draft_' . $slug,
					DUPCAP_Result::PASS,
					/* translators: %s: language slug */
					sprintf( __( 'Published translations in "%s"', 'duplicate-content-addon-for-polylang' ), $slug ),
					__( 'All linked translations are published.', 'duplicate-content-addon-for-polylang' )
				);
			}
		}
	}

	/**
	 * For each secondary language, count default-language terms that lack a translation.
	 *
	 * @param array  $results    Results array (by reference).
	 * @param array  $taxonomies Translated taxonomies.
	 * @param array  $languages  Languages.
	 * @param string $default    Default slug.
	 */
	protected function add_missing_term_translation_counts( &$results, $taxonomies, $languages, $default ) {
		if ( empty( $taxonomies ) ) {
			return;
		}

		$pending_data    = $this->get_pending_terms_by_language( $taxonomies, $languages, $default );
		$pending_by_lang = $pending_data['by_language'];
		$ignored_by_lang = isset( $pending_data['ignored_by_language'] ) ? (array) $pending_data['ignored_by_language'] : array();
		$fix_taxonomy    = in_array( 'category', $taxonomies, true ) ? 'category' : $taxonomies[0];

		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( $slug === $default ) {
				continue;
			}

			$pending_for_lang = isset( $pending_by_lang[ $slug ] ) ? $pending_by_lang[ $slug ] : array();
			$ignored_count    = isset( $ignored_by_lang[ $slug ] ) ? (int) $ignored_by_lang[ $slug ] : 0;
			$default_total    = $this->count_terms_in_language( $taxonomies, $default );
			$lang_total       = $this->count_terms_in_language( $taxonomies, $slug );
			$estimated        = max( 0, $default_total - $lang_total - $ignored_count );
			$missing          = max( $estimated, count( $pending_for_lang ) );

			if ( $missing > 0 ) {
				$result = $this->result(
					'term_missing_' . $slug,
					DUPCAP_Result::WARNING,
					/* translators: %s: language slug */
					sprintf( __( 'Terms missing in "%s"', 'duplicate-content-addon-for-polylang' ), $slug ),
					/* translators: 1: count 2: language slug */
					sprintf( _n( '%1$d term is not yet translated into "%2$s".', '%1$d terms are not yet translated into "%2$s".', $missing, 'duplicate-content-addon-for-polylang' ), $missing, $slug )
				)->with_fix(
					admin_url( 'edit-tags.php?taxonomy=' . $fix_taxonomy . '&lang=' . $slug ),
					__( 'Translate', 'duplicate-content-addon-for-polylang' )
				);

				if ( ! empty( $pending_for_lang ) ) {
					$result->with_details( $pending_for_lang );
				}

				$results[] = $result;
			} else {
				$results[] = $this->result(
					'term_missing_' . $slug,
					DUPCAP_Result::PASS,
					/* translators: %s: language slug */
					sprintf( __( 'Terms complete in "%s"', 'duplicate-content-addon-for-polylang' ), $slug ),
					__( 'All categories and tags have a translation.', 'duplicate-content-addon-for-polylang' )
				);
			}
		}
	}

	/**
	 * Every default-language post of one type, newest change first, loaded in
	 * batches of BATCH_SIZE. One cheap ID query, then one query per batch, so a
	 * site with thousands of posts never holds them all in memory at once.
	 *
	 * @param string $type    Post type.
	 * @param string $default Default language slug.
	 * @return Generator<int, WP_Post>
	 */
	protected function each_default_language_post( $type, $default ) {
		$statuses = array( 'publish', 'private', 'draft', 'pending', 'future' );
		$ids      = get_posts(
			array(
				'post_type'      => $type,
				'lang'           => $default,
				'posts_per_page' => -1,
				'post_status'    => $statuses,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( array_chunk( array_map( 'intval', (array) $ids ), self::BATCH_SIZE ) as $batch ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post__in'       => $batch,
					'orderby'        => 'post__in',
					'posts_per_page' => count( $batch ),
					'post_status'    => $statuses,
					'lang'           => '',
					'no_found_rows'  => true,
				)
			);

			foreach ( $posts as $post ) {
				yield $post;
			}
		}
	}

	/**
	 * Collect every post/page in the default language that is missing or
	 * unpublished in a target language.
	 *
	 * @param array  $post_types Post types.
	 * @param array  $languages  Languages.
	 * @param string $default    Default language slug.
	 * @return array { missing_by_language: array, draft_by_language: array }
	 */
	protected function get_content_gaps_by_language( $post_types, $languages, $default ) {
		$missing_by_language = array();
		$draft_by_language   = array();

		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return array(
				'missing_by_language' => $missing_by_language,
				'draft_by_language'   => $draft_by_language,
			);
		}

		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( $slug !== $default ) {
				$missing_by_language[ $slug ] = array();
				$draft_by_language[ $slug ]   = array();
			}
		}

		$language_names = array();
		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( is_object( $lang ) && ! empty( $lang->name ) ) {
				$language_names[ $slug ] = $lang->name;
			} else {
				$language_names[ $slug ] = strtoupper( $slug );
			}
		}

		foreach ( $post_types as $type ) {
			$posts = $this->each_default_language_post( $type, $default );

			$obj = get_post_type_object( $type );

			foreach ( $posts as $post ) {
				// Elementor site kits and global widgets are not content to translate.
				if ( DUPCAP_Elementor_Document::is_excluded_library_document( $post->ID ) ) {
					continue;
				}

				$translations = pll_get_post_translations( $post->ID );
				$missing      = array();
				$drafts       = array();

				foreach ( array_keys( $missing_by_language ) as $target_slug ) {
					$translation_id = ! empty( $translations[ $target_slug ] ) ? (int) $translations[ $target_slug ] : 0;

					if ( ! $translation_id ) {
						$missing[] = $target_slug;
						continue;
					}

					$status = get_post_status( $translation_id );
					if ( 'publish' !== $status ) {
						$drafts[] = array(
							'target_slug'    => $target_slug,
							'translation_id' => $translation_id,
							'post_status'    => $status ? $status : 'draft',
						);
					}
				}

				if ( empty( $missing ) && empty( $drafts ) ) {
					continue;
				}

				foreach ( $missing as $target_slug ) {
					$ignore_key = DUPCAP_Ignored_Issues::content_gap_key( $post->ID, $target_slug, 'missing' );
					if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
						continue;
					}

					$missing_by_language[ $target_slug ][] = array(
						'post_id'         => (int) $post->ID,
						'title'           => $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
						'post_type'       => $post->post_type,
						'post_type_label' => $obj ? $obj->labels->singular_name : $post->post_type,
						'language'        => $target_slug,
						'language_name'   => isset( $language_names[ $target_slug ] ) ? $language_names[ $target_slug ] : strtoupper( $target_slug ),
						'edit_url'        => get_edit_post_link( $post->ID, 'raw' ),
						'translate_url'   => $this->translation_link( $post, $target_slug ),
						'gap_type'        => 'missing',
						'ignore_key'      => $ignore_key,
					);
				}

				foreach ( $drafts as $draft ) {
					$target_slug = $draft['target_slug'];
					$ignore_key  = DUPCAP_Ignored_Issues::content_gap_key( $post->ID, $target_slug, 'draft' );
					if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
						continue;
					}

					$draft_by_language[ $target_slug ][] = array(
						'post_id'             => (int) $post->ID,
						'translation_id'      => (int) $draft['translation_id'],
						'title'               => $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
						'post_type'           => $post->post_type,
						'post_type_label'     => $obj ? $obj->labels->singular_name : $post->post_type,
						'language'            => $target_slug,
						'language_name'       => isset( $language_names[ $target_slug ] ) ? $language_names[ $target_slug ] : strtoupper( $target_slug ),
						'post_status'         => $draft['post_status'],
						'post_status_label'   => $this->get_post_status_label( $draft['post_status'] ),
						'edit_url'            => get_edit_post_link( $post->ID, 'raw' ),
						'translate_url'       => get_edit_post_link( $draft['translation_id'], 'raw' ),
						'gap_type'            => 'draft',
						'ignore_key'          => $ignore_key,
					);
				}
			}
		}

		return array(
			'missing_by_language' => $missing_by_language,
			'draft_by_language'   => $draft_by_language,
		);
	}

	/**
	 * Collect every term in the default language missing a target translation.
	 *
	 * @param array  $taxonomies Taxonomies.
	 * @param array  $languages  Languages.
	 * @param string $default    Default language slug.
	 * @return array { by_language: array, ignored_by_language: array }
	 */
	protected function get_pending_terms_by_language( $taxonomies, $languages, $default ) {
		$by_language = array();
		$ignored     = array();

		if ( ! function_exists( 'pll_get_term_translations' ) ) {
			return array(
				'by_language'         => $by_language,
				'ignored_by_language' => $ignored,
			);
		}

		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( $slug !== $default ) {
				$by_language[ $slug ] = array();
				$ignored[ $slug ]     = 0;
			}
		}

		$language_names = array();
		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( is_object( $lang ) && ! empty( $lang->name ) ) {
				$language_names[ $slug ] = $lang->name;
			} else {
				$language_names[ $slug ] = strtoupper( $slug );
			}
		}

		foreach ( $taxonomies as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'lang'       => $default,
					'hide_empty' => false,
					'number'     => 0, // All terms. Term rows are small, unlike post bodies.
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			$obj = get_taxonomy( $tax );

			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				$translations = pll_get_term_translations( $term->term_id );
				$missing      = array();

				foreach ( array_keys( $by_language ) as $target_slug ) {
					if ( ! empty( $translations[ $target_slug ] ) ) {
						continue;
					}

					$missing[] = $target_slug;
				}

				if ( empty( $missing ) ) {
					continue;
				}

				foreach ( $missing as $target_slug ) {
					$ignore_key = DUPCAP_Ignored_Issues::term_gap_key( $term->term_id, $tax, $target_slug );
					if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
						$ignored[ $target_slug ] = isset( $ignored[ $target_slug ] ) ? $ignored[ $target_slug ] + 1 : 1;
						continue;
					}

					$by_language[ $target_slug ][] = array(
						'post_id'         => (int) $term->term_id,
						'title'           => $term->name ? $term->name : __( '(no name)', 'duplicate-content-addon-for-polylang' ),
						'post_type'       => $tax,
						'post_type_label' => $obj ? $obj->labels->singular_name : $tax,
						'language'        => $target_slug,
						'language_name'   => isset( $language_names[ $target_slug ] ) ? $language_names[ $target_slug ] : strtoupper( $target_slug ),
						'edit_url'        => get_edit_term_link( $term->term_id, $tax, '', 'raw' ),
						'translate_url'   => $this->term_translation_link( $term, $tax, $target_slug ),
						'ignore_key'      => $ignore_key,
					);
				}
			}
		}

		return array(
			'by_language'         => $by_language,
			'ignored_by_language' => $ignored,
		);
	}

	/**
	 * Count terms across translated taxonomies for one language.
	 *
	 * @param array  $taxonomies Taxonomies.
	 * @param string $lang       Language slug.
	 * @return int
	 */
	protected function count_terms_in_language( $taxonomies, $lang ) {
		$total = 0;

		foreach ( $taxonomies as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'lang'       => $lang,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				$total += count( $terms );
			}
		}

		return $total;
	}

	/**
	 * Human-readable post status label.
	 *
	 * @param string $status Post status slug.
	 * @return string
	 */
	protected function get_post_status_label( $status ) {
		$object = get_post_status_object( $status );
		if ( $object && ! empty( $object->label ) ) {
			return (string) $object->label;
		}

		return ucfirst( (string) $status );
	}

	/**
	 * Build Polylang "new translation" URL for a post.
	 *
	 * @param WP_Post $post      Source post.
	 * @param string  $lang_slug Target language slug.
	 * @return string
	 */
	protected function translation_link( $post, $lang_slug ) {
		$pll = function_exists( 'PLL' ) ? PLL() : null;
		if ( ! $pll || ! isset( $pll->model ) ) {
			return '';
		}

		$lang = $pll->model->get_language( $lang_slug );
		if ( ! $lang ) {
			return '';
		}

		// Media is not created like posts. Polylang's "new translation" URL
		// (post-new.php?post_type=attachment) confuses users — send them to Edit
		// so they can add languages in the Polylang box (or upload a new file).
		if ( $post instanceof WP_Post && 'attachment' === $post->post_type ) {
			$edit = get_edit_post_link( $post->ID, 'raw' );
			return $edit ? (string) $edit : '';
		}

		if ( isset( $pll->links ) && method_exists( $pll->links, 'get_new_post_translation_link' ) ) {
			return (string) $pll->links->get_new_post_translation_link( $post, $lang, 'display' );
		}

		$args = array(
			'post_type' => $post->post_type,
			'from_post' => $post->ID,
			'new_lang'  => $lang_slug,
		);

		return wp_nonce_url( add_query_arg( $args, admin_url( 'post-new.php' ) ), 'new-post-translation' );
	}

	/**
	 * Build Polylang "new translation" URL for a term.
	 *
	 * @param WP_Term $term      Source term.
	 * @param string  $tax       Taxonomy slug.
	 * @param string  $lang_slug Target language slug.
	 * @return string
	 */
	protected function term_translation_link( $term, $tax, $lang_slug ) {
		$pll = function_exists( 'PLL' ) ? PLL() : null;
		if ( ! $pll || ! isset( $pll->model ) ) {
			return '';
		}

		$lang = $pll->model->get_language( $lang_slug );
		if ( ! $lang ) {
			return '';
		}

		$taxonomy  = get_taxonomy( $tax );
		$post_type = ( $taxonomy && ! empty( $taxonomy->object_type ) ) ? (string) $taxonomy->object_type[0] : 'post';

		if ( isset( $pll->links ) && method_exists( $pll->links, 'get_new_term_translation_link' ) ) {
			return (string) $pll->links->get_new_term_translation_link( $term, $post_type, $lang );
		}

		$args = array(
			'taxonomy'  => $tax,
			'post_type' => $post_type,
			'from_tag'  => $term->term_id,
			'new_lang'  => $lang_slug,
		);

		return add_query_arg( $args, admin_url( 'edit-tags.php' ) );
	}

	/**
	 * Translated post types configured in Polylang settings.
	 *
	 * @return array
	 */
	protected function translated_post_types() {
		return parent::translated_post_types();
	}

}
