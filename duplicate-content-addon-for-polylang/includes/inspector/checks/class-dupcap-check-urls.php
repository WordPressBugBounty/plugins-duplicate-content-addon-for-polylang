<?php
/**
 * URL checks: duplicate slugs across languages.
 *
 * The former url_home_* checks were removed in 2.1.0. They resolved same-site
 * URLs without an HTTP request, so they returned 200 for any same-host URL and
 * could only ever restate what lang_homepage already reported. Real reachability
 * testing lives in the routing scanner; URL settings live in
 * DUPCAP_Check_Url_Config.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Check_Urls extends DUPCAP_Check {

	protected $group = 'URLs';

	/**
	 * Run the check.
	 *
	 * @return DUPCAP_Result[]
	 */
	public function run() {
		$results   = array();
		$languages = $this->active_languages();

		if ( count( $languages ) < 2 ) {
			return $results;
		}

		// Duplicate slugs across languages (same slug, different language => can confuse routing).
		$dupe_data = $this->duplicate_slugs_data();
		if ( ! empty( $dupe_data['slugs'] ) ) {
			$count     = count( $dupe_data['slugs'] );
			$results[] = $this->result(
				'url_dupe_slugs',
				DUPCAP_Result::WARNING,
				__( 'Duplicate slugs found', 'duplicate-content-addon-for-polylang' ),
				/* translators: %d: count */
				sprintf( _n( '%d slug is shared by posts in different languages, which can break URLs.', '%d slugs are shared by posts in different languages, which can break URLs.', $count, 'duplicate-content-addon-for-polylang' ), $count )
			)->with_details( $dupe_data['details'] );
		} else {
			$results[] = $this->result(
				'url_dupe_slugs',
				DUPCAP_Result::PASS,
				__( 'No conflicting slugs', 'duplicate-content-addon-for-polylang' ),
				__( 'No duplicate slug conflicts detected.', 'duplicate-content-addon-for-polylang' )
			);
		}

		return $results;
	}

	/**
	 * Find published post slugs that appear in more than one language,
	 * returning conflicting slugs and detailed rows for editing.
	 *
	 * @return array{slugs: array, details: array}
	 */
	protected function duplicate_slugs_data() {
		global $wpdb;

		$post_types = $this->translated_post_types();
		if ( empty( $post_types ) || ! function_exists( 'pll_get_post_language' ) ) {
			return array(
				'slugs'   => array(),
				'details' => array(),
			);
		}

		$type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		/*
		* Diagnostic read during site scan, every duplicate slug; IN list uses only "%s" tokens.
		* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
		*/
		$sql = $wpdb->prepare(
			"SELECT post_name FROM {$wpdb->posts}
			WHERE post_status = 'publish'
			AND post_type IN ({$type_placeholders})
			AND post_name <> ''
			GROUP BY post_name
			HAVING COUNT(*) > 1",
			$post_types
		);

		$rows = $wpdb->get_col( $sql );
		// phpcs:enable

		if ( empty( $rows ) ) {
			return array(
				'slugs'   => array(),
				'details' => array(),
			);
		}

		$lang_names = array();
		if ( function_exists( 'PLL' ) && isset( PLL()->model ) && method_exists( PLL()->model, 'get_languages_list' ) ) {
			foreach ( PLL()->model->get_languages_list() as $l_obj ) {
				if ( is_object( $l_obj ) && ! empty( $l_obj->slug ) && ! empty( $l_obj->name ) ) {
					$lang_names[ $l_obj->slug ] = (string) $l_obj->name;
				}
			}
		}

		$conflicts = array();
		$details   = array();

		foreach ( $rows as $slug ) {
			$ids = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => -1, // Every post sharing this slug.
					'fields'         => 'ids',
					'lang'           => '', // all languages
				)
			);

			if ( count( $ids ) > 1 ) {
				$ignore_key = '';
				if ( class_exists( 'DUPCAP_Ignored_Issues' ) ) {
					$ignore_key = DUPCAP_Ignored_Issues::url_key( 'dupe_slug', '', (string) $slug );
					if ( '' !== $ignore_key && DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
						continue;
					}
				}

				$conflicts[] = $slug;
				$posts_info  = array();
				foreach ( $ids as $id ) {
					$post_title = get_the_title( $id );
					if ( '' === $post_title ) {
						/* translators: %d: post ID */
						$post_title = sprintf( __( 'Post #%d', 'duplicate-content-addon-for-polylang' ), $id );
					}
					$post_type = get_post_type( $id );
					$lang_code = (string) pll_get_post_language( $id );
					$lang_name = isset( $lang_names[ $lang_code ] ) ? $lang_names[ $lang_code ] : strtoupper( $lang_code ? $lang_code : 'default' );

					$posts_info[] = array(
						'id'           => $id,
						'title'        => $post_title,
						'post_type'    => $post_type,
						'lang_slug'    => $lang_code,
						'lang_name'    => $lang_name,
						'action_url'   => (string) get_edit_post_link( $id, 'url' ),
						'action_label' => 'page' === $post_type ? __( 'Edit page', 'duplicate-content-addon-for-polylang' ) : __( 'Edit post', 'duplicate-content-addon-for-polylang' ),
					);
				}

				$primary_type = ! empty( $posts_info[0]['post_type'] ) ? $posts_info[0]['post_type'] : 'post';
				$type_obj     = get_post_type_object( $primary_type );
				$type_label   = 'post';
				if ( $type_obj ) {
					if ( 'page' === $primary_type ) {
						$type_label = _n( 'page', 'pages', count( $posts_info ), 'duplicate-content-addon-for-polylang' );
					} elseif ( 'post' === $primary_type ) {
						$type_label = _n( 'post', 'posts', count( $posts_info ), 'duplicate-content-addon-for-polylang' );
					} else {
						$type_label = strtolower( $type_obj->labels->name );
					}
				} else {
					$type_label = _n( 'item', 'items', count( $posts_info ), 'duplicate-content-addon-for-polylang' );
				}

				$details[] = array(
					'label'      => sprintf(
						/* translators: %s: slug name */
						__( 'Conflicting slug: "%s"', 'duplicate-content-addon-for-polylang' ),
						$slug
					),
					'message'    => sprintf(
						/* translators: 1: count, 2: post type plural label (e.g. pages, posts) */
						__( 'Shared by %1$d %2$s across languages.', 'duplicate-content-addon-for-polylang' ),
						count( $posts_info ),
						$type_label
					),
					'posts'      => $posts_info,
					'post_type'  => $primary_type,
					'ignore_key' => $ignore_key,
				);
			}
		}

		return array(
			'slugs'   => $conflicts,
			'details' => $details,
		);
	}
}
