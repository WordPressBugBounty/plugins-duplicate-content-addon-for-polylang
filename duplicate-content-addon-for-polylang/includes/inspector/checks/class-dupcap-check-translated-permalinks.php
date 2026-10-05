<?php
/**
 * Translated permalink checks: numbered slugs and routing-aware review items.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Check_Translated_Permalinks extends DUPCAP_Check {

	protected $group = 'Translated Permalinks';

	/**
	 * Maximum "-2" style slug rows reported.
	 */
	const MAX_SLUG_ROWS = 25;

	/**
	 * Run the check (fast pass: DB slug detection only, no HTTP).
	 *
	 * @return DUPCAP_Result[]
	 */
	public function run() {
		return $this->build_results( false );
	}

	/**
	 * Rebuild results with live HTTP diagnostics (manual Test URLs only).
	 *
	 * @since 2.3.0
	 *
	 * @return DUPCAP_Result[]
	 */
	public function run_with_http() {
		return $this->build_results( true );
	}

	/**
	 * Collect numbered-slug rows and optionally enrich them over HTTP.
	 *
	 * @since 2.3.0
	 *
	 * @param bool $with_http Whether to call DUPCAP_Permalink_Diagnostics::enrich_rows().
	 * @return DUPCAP_Result[]
	 */
	protected function build_results( $with_http ) {
		$languages = $this->active_languages();

		if ( count( $languages ) < 2 ) {
			return array();
		}

		$rows = $this->suffixed_slug_rows();
		if ( empty( $rows ) ) {
			return array();
		}

		if ( $with_http && class_exists( 'DUPCAP_Permalink_Diagnostics' ) ) {
			$rows = DUPCAP_Permalink_Diagnostics::enrich_rows( $rows, $languages );
		}

		$details = array();

		foreach ( $rows as $row ) {
			$ignore_key = DUPCAP_Ignored_Issues::url_key( 'slug_suffix', $row['lang'], (string) $row['id'] );
			if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
				continue;
			}

			$lang_label = $this->lang_label_from_slug( $row['lang'] );

			if ( $with_http ) {
				$details[] = array(
					'label'                  => sprintf(
						/* translators: 1: page title, 2: language name */
						__( '%1$s · %2$s', 'duplicate-content-addon-for-polylang' ),
						$row['title'],
						$lang_label
					),
					'title'                  => $row['title'],
					'language'               => $row['lang'],
					'language_label'         => $lang_label,
					'post_type'              => $row['post_type'],
					'content_id'             => $row['id'],
					'source_language'        => $row['source_lang'],
					'current_slug'           => $row['slug'],
					'base_slug'              => $row['base'],
					'url'                    => $row['permalink'],
					'source_url'             => $row['source_permalink'],
					'http_status'            => isset( $row['http_status'] ) ? (int) $row['http_status'] : 0,
					'redirect_target'        => isset( $row['redirect_target'] ) ? (string) $row['redirect_target'] : '',
					'routing_result'         => isset( $row['routing_result'] ) ? (string) $row['routing_result'] : '',
					'routing_message'        => isset( $row['routing_message'] ) ? (string) $row['routing_message'] : '',
					'severity'               => isset( $row['severity'] ) ? (string) $row['severity'] : 'info',
					'status_label'           => isset( $row['status_label'] ) ? (string) $row['status_label'] : '',
					'message'                => isset( $row['message'] ) ? (string) $row['message'] : '',
					'action_url'             => $row['edit'],
					'action_label'           => __( 'Review permalink', 'duplicate-content-addon-for-polylang' ),
					'secondary_action_url'   => isset( $row['secondary_action_url'] ) ? (string) $row['secondary_action_url'] : '',
					'secondary_action_label' => isset( $row['secondary_action_label'] ) ? (string) $row['secondary_action_label'] : '',
					'ignore_key'             => $ignore_key,
					'ignore_label'           => __( 'Ignore', 'duplicate-content-addon-for-polylang' ),
				);
			} else {
				$details[] = array(
					'label'          => sprintf(
						/* translators: 1: page title, 2: language name */
						__( '%1$s · %2$s', 'duplicate-content-addon-for-polylang' ),
						$row['title'],
						$lang_label
					),
					'title'          => $row['title'],
					'language'       => $row['lang'],
					'language_label' => $lang_label,
					'post_type'      => $row['post_type'],
					'content_id'     => $row['id'],
					'source_language'=> $row['source_lang'],
					'current_slug'   => $row['slug'],
					'base_slug'      => $row['base'],
					'url'            => $row['permalink'],
					'source_url'     => $row['source_permalink'],
					'http_status'    => 0,
					'redirect_target'=> '',
					'routing_result' => 'untested',
					'routing_message'=> '',
					'severity'       => 'info',
					'status_label'   => __( 'Not tested yet', 'duplicate-content-addon-for-polylang' ),
					'message'        => __( 'Run Test URLs to check whether this address loads.', 'duplicate-content-addon-for-polylang' ),
					'action_url'     => $row['edit'],
					'action_label'   => __( 'Review permalink', 'duplicate-content-addon-for-polylang' ),
					'ignore_key'     => $ignore_key,
					'ignore_label'   => __( 'Ignore', 'duplicate-content-addon-for-polylang' ),
				);
			}
		}

		if ( empty( $details ) ) {
			return array();
		}

		$status = class_exists( 'DUPCAP_Permalink_Diagnostics' )
			? DUPCAP_Permalink_Diagnostics::worst_result_status( $details )
			: DUPCAP_Result::INFO;

		$counts = self::count_by_severity( $details );

		$result = $this->result(
			'translated_permalink_review',
			$status,
			$this->group_title( $counts ),
			$this->group_message( $counts, $with_http )
		)->with_details( $details );

		if ( count( $rows ) >= self::MAX_SLUG_ROWS ) {
			$result->details_truncated = true;
		}

		return array( $result );
	}

	/**
	 * Count detail rows by severity bucket.
	 *
	 * @param array<int, array<string, mixed>> $details Detail rows.
	 * @return array{problems: int, review: int}
	 */
	protected static function count_by_severity( array $details ) {
		$problems = 0;
		$review   = 0;

		foreach ( $details as $detail ) {
			$severity = isset( $detail['severity'] ) ? (string) $detail['severity'] : 'info';
			if ( in_array( $severity, array( 'crit', 'critical', 'warn', 'warning' ), true ) ) {
				$problems++;
			} else {
				$review++;
			}
		}

		return array(
			'problems' => $problems,
			'review'   => $review,
		);
	}

	/**
	 * Group card title from severity counts.
	 *
	 * @param array{problems: int, review: int} $counts Counts.
	 * @return string
	 */
	protected function group_title( array $counts ) {
		if ( $counts['problems'] > 0 && $counts['review'] > 0 ) {
			return sprintf(
				/* translators: 1: problem count, 2: review count */
				__( '%1$d problems · %2$d to review', 'duplicate-content-addon-for-polylang' ),
				$counts['problems'],
				$counts['review']
			);
		}

		if ( $counts['problems'] > 0 ) {
			return sprintf(
				/* translators: %d: problem count */
				_n( '%d translated URL problem', '%d translated URL problems', $counts['problems'], 'duplicate-content-addon-for-polylang' ),
				$counts['problems']
			);
		}

		return sprintf(
			/* translators: %d: review count */
			_n( '%d URL to review', '%d URLs to review', $counts['review'], 'duplicate-content-addon-for-polylang' ),
			$counts['review']
		);
	}

	/**
	 * Group card message from severity counts.
	 *
	 * @param array{problems: int, review: int} $counts    Counts.
	 * @param bool                              $with_http Whether live HTTP ran.
	 * @return string
	 */
	protected function group_message( array $counts, $with_http = true ) {
		if ( ! $with_http ) {
			return sprintf(
				/* translators: %d: review count */
				_n(
					'%d translated page uses a numbered or different slug. Run Test URLs to check whether it loads.',
					'%d translated pages use numbered or different slugs. Run Test URLs to check whether they load.',
					$counts['review'],
					'duplicate-content-addon-for-polylang'
				),
				$counts['review']
			);
		}

		if ( $counts['problems'] > 0 && $counts['review'] > 0 ) {
			return sprintf(
				/* translators: 1: problem count, 2: review count */
				__( '%1$d translated URL is broken or redirects incorrectly. %2$d others work but use numbered or different slugs.', 'duplicate-content-addon-for-polylang' ),
				$counts['problems'],
				$counts['review']
			);
		}

		if ( $counts['problems'] > 0 ) {
			return sprintf(
				/* translators: %d: problem count */
				_n(
					'%d translated URL is broken or redirects incorrectly.',
					'%d translated URLs are broken or redirect incorrectly.',
					$counts['problems'],
					'duplicate-content-addon-for-polylang'
				),
				$counts['problems']
			);
		}

		return sprintf(
			/* translators: %d: review count */
			_n(
				'%d translated page uses a numbered or different slug. All tested URLs currently load correctly.',
				'%d translated pages use numbered or different slugs. All tested URLs currently load correctly.',
				$counts['review'],
				'duplicate-content-addon-for-polylang'
			),
			$counts['review']
		);
	}

	/**
	 * Published posts whose slug ends in "-<number>" where the base slug belongs
	 * to a post in a different language.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function suffixed_slug_rows() {
		global $wpdb;

		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return array();
		}

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}

		$post_types = $this->translated_post_types();
		if ( empty( $post_types ) ) {
			return array();
		}

		$type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$limit             = (int) self::MAX_SLUG_ROWS;
		$params            = array_merge( $post_types, array( $limit ) );

		/*
		* IN list uses only "%s" tokens; values are bound below.
		* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
		*/
		$sql = $wpdb->prepare(
			"SELECT ID, post_name, post_title, post_type
			FROM {$wpdb->posts}
			WHERE post_status = 'publish'
			AND post_type IN ({$type_placeholders})
			AND post_name REGEXP '-[0-9]+$'
			ORDER BY ID DESC
			LIMIT %d",
			$params
		);

		$candidates = $wpdb->get_results( $sql );
		// phpcs:enable

		if ( empty( $candidates ) ) {
			return array();
		}

		$rows = array();

		foreach ( $candidates as $candidate ) {
			$base = preg_replace( '/-\d+$/', '', (string) $candidate->post_name );
			if ( '' === $base || $base === $candidate->post_name ) {
				continue;
			}

			$lang = (string) pll_get_post_language( (int) $candidate->ID );
			if ( '' === $lang ) {
				continue;
			}

			$source = get_posts(
				array(
					'name'                   => $base,
					'post_type'              => $candidate->post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'lang'                   => '',
				)
			);

			if ( empty( $source ) ) {
				continue;
			}

			$source_id   = (int) $source[0];
			$source_lang = (string) pll_get_post_language( $source_id );
			if ( '' === $source_lang || $source_lang === $lang ) {
				continue;
			}

			$rows[] = array(
				'id'                => (int) $candidate->ID,
				'lang'              => $lang,
				'lang_label'        => $this->lang_label_from_slug( $lang ),
				'slug'              => (string) $candidate->post_name,
				'base'              => (string) $base,
				'title'             => (string) $candidate->post_title,
				'post_type'         => (string) $candidate->post_type,
				'source_id'         => $source_id,
				'source_lang'       => $source_lang,
				'permalink'         => (string) get_permalink( (int) $candidate->ID ),
				'source_permalink'  => (string) get_permalink( $source_id ),
				'edit'              => (string) get_edit_post_link( (int) $candidate->ID, 'url' ),
			);
		}

		return $rows;
	}

	/**
	 * Human-readable language label.
	 *
	 * @param string $slug Language slug.
	 * @return string
	 */
	protected function lang_label_from_slug( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( class_exists( 'DUPCAP_Elementor_Fields' ) ) {
			return DUPCAP_Elementor_Fields::language_label( $slug );
		}

		return $this->lang_label(
			(object) array(
				'slug' => $slug,
				'name' => strtoupper( $slug ),
			)
		);
	}
}
