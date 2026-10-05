<?php
/**
 * Builds Elementor Translation Insights for the dashboard.
 *
 * Presentation/meta layer extracted from {@see DUPCAP_Elementor_Scanner} (DUP-017).
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Curates Elementor scan findings into dashboard insight buckets.
 */
class DUPCAP_Elementor_Insights {
	/**
	 * Build curated dashboard insights from a scan report.
	 *
	 * @param array|null $report Scan report or null to read saved report.
	 * @return array
	 */
	public static function get_dashboard_insights( $report = null ) {
		if ( ! DUPCAP_Elementor_Scanner::is_elementor_available() ) {
			return array(
				'visible' => false,
				'status'  => 'hidden',
			);
		}

		if ( null === $report ) {
			$report = DUPCAP_Elementor_Scanner::get_saved_report();
		}

		if ( ! is_array( $report ) ) {
			return array(
				'visible'        => false,
				'status'         => 'hidden',
				'message'        => __( 'Elementor scan has not run yet.', 'duplicate-content-addon-for-polylang' ),
				'summary'        => array(),
				'buckets'        => array(),
				'document_types' => array(),
			);
		}

		$summary  = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		$findings = isset( $report['findings'] ) && is_array( $report['findings'] ) ? $report['findings'] : array();
		$document_types = self::build_document_types_from_report( $report );

		$filtered      = array();
		$badge_counts  = array(
			'crit' => 0,
			'warn' => 0,
		);
		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) || DUPCAP_Elementor_Scanner::is_finding_ignored( $finding ) ) {
				continue;
			}

			$severity = isset( $finding['severity'] ) ? $finding['severity'] : '';

			$visible_severities = array(
				DUPCAP_Elementor_Finding::CRITICAL,
				DUPCAP_Elementor_Finding::MAJOR,
				DUPCAP_Elementor_Finding::WARNING,
			);

			/*
			 * Notices are shown, not dropped. Since 2.3.0 an unlinked layout with
			 * no widgets in it is reported as INFO rather than CRITICAL; dropping
			 * it here would make three findings disappear from the report instead
			 * of moving down a level, and a diagnostic tool that quietly stops
			 * mentioning something is worse than one that ranks it low.
			 */
			if ( DUPCAP_Issue_Normalizer::is_enabled() ) {
				$visible_severities[] = DUPCAP_Elementor_Finding::INFO;
			}

			if ( ! in_array( $severity, $visible_severities, true ) ) {
				continue;
			}

			// Pages, posts, and Elementor library templates missing a translation
			// are already listed by the content and Elementor template checks.
			$code        = isset( $finding['code'] ) ? $finding['code'] : '';
			$type        = isset( $finding['template_type'] ) ? $finding['template_type'] : '';
			$template_id = isset( $finding['template_id'] ) ? (int) $finding['template_id'] : 0;
			$post_type   = $template_id > 0 ? get_post_type( $template_id ) : '';
			$library_already_listed = 'elementor_library' === $post_type
				&& function_exists( 'pll_is_translated_post_type' )
				&& pll_is_translated_post_type( 'elementor_library' );
			if ( 'missing_translation' === $code && ( $library_already_listed || in_array( $type, array( 'wp-page', 'wp-post' ), true ) ) ) {
				continue;
			}

			// With Connect Polylang for Elementor active, Elementor hides a
			// translation's display conditions and CPEL picks the template by
			// language, so leftover ids from duplication change nothing on the
			// site and cannot be cleared from the panel. Do not report them.
			if ( 'wrong_language_condition' === $code && defined( 'CPEL_PLUGIN_VERSION' ) ) {
				continue;
			}

			// Dead links are counted later per unique target URL (not per widget).
			if ( 'dead_link' !== $code ) {
				if ( DUPCAP_Elementor_Finding::CRITICAL === $severity ) {
					$badge_counts['crit']++;
				} else {
					$badge_counts['warn']++;
				}
			}

			// Retain computed ignore_key for downstream rendering.
			$finding['ignore_key'] = DUPCAP_Elementor_Scanner::get_finding_ignore_key( $finding );
			$filtered[]            = $finding;
		}

		if ( empty( $filtered ) ) {
			return array(
				'visible'        => true,
				'status'         => 'pass',
				'message'        => __( 'No Elementor translation issues were found.', 'duplicate-content-addon-for-polylang' ),
				'summary'        => $summary,
				'health_score'   => DUPCAP_Elementor_Scanner::health_score_from_report( $report, $filtered ),
				'buckets'        => array(),
				'more_types'     => 0,
				'badge_counts'   => $badge_counts,
				'document_types' => $document_types,
			);
		}

		$groups = array();
		$max_examples = (int) apply_filters( 'dupcap_elementor_dashboard_example_limit', 250 );
		if ( $max_examples <= 0 ) {
			$max_examples = 250;
		}

		foreach ( $filtered as $finding ) {
			$code        = isset( $finding['code'] ) ? (string) $finding['code'] : '';
			$template_id = isset( $finding['template_id'] ) ? (int) $finding['template_id'] : 0;

			// Homepage empty layouts are surfaced in Fix These First via lang_homepage.
			if ( 'empty_translation' === $code && $template_id > 0 && DUPCAP_Elementor_Document::is_static_front_page_post( $template_id ) ) {
				continue;
			}

			$bucket = self::insight_bucket_for_code( $code );

			if ( ! isset( $groups[ $bucket ] ) ) {
				$groups[ $bucket ] = array(
					'key'      => $bucket,
					'severity' => $finding['severity'],
					'count'    => 0,
					'examples' => array(),
				);
			}

			if ( DUPCAP_Elementor_Finding::CRITICAL === $finding['severity'] ) {
				$groups[ $bucket ]['severity'] = DUPCAP_Elementor_Finding::CRITICAL;
			}

			$groups[ $bucket ]['count']++;

			if ( count( $groups[ $bucket ]['examples'] ) < $max_examples ) {
				$type        = isset( $finding['template_type'] ) ? $finding['template_type'] : '';
				$template_id = isset( $finding['template_id'] ) ? (int) $finding['template_id'] : 0;
				$lang_code   = isset( $finding['language'] ) ? (string) $finding['language'] : '';
				$fix_url     = isset( $finding['fix_url'] ) ? (string) $finding['fix_url'] : '';

				$real_post_type = ( $template_id > 0 && get_post_type( $template_id ) )
					? (string) get_post_type( $template_id )
					: ( isset( $finding['post_type'] ) && '' !== (string) $finding['post_type'] ? (string) $finding['post_type'] : 'elementor_library' );

				$post_type_obj   = get_post_type_object( $real_post_type );
				$post_type_label = $post_type_obj && ! empty( $post_type_obj->labels->singular_name )
					? (string) $post_type_obj->labels->singular_name
					: DUPCAP_Elementor_Document::type_display( $type );

				$example = array(
					'title'            => isset( $finding['template_title'] ) ? $finding['template_title'] : '',
					'template_id'      => $template_id,
					'post_id'          => $template_id,
					'post_type'        => $real_post_type,
					'post_type_label'  => $post_type_label,
					'type'             => $type,
					'type_label'       => DUPCAP_Elementor_Document::type_label_for( $type ),
					'type_display'     => DUPCAP_Elementor_Document::type_display( $type ),
					'language'         => $lang_code,
					'language_name'    => DUPCAP_Elementor_Fields::language_label( $lang_code ),
					'edit_url'         => $template_id > 0 ? ( get_edit_post_link( $template_id, 'raw' ) ? (string) get_edit_post_link( $template_id, 'raw' ) : '' ) : '',
					'translate_url'    => $fix_url,
					'widget'           => isset( $finding['widget_type'] ) ? $finding['widget_type'] : '',
					'field'            => isset( $finding['field'] ) ? $finding['field'] : '',
					'code'             => isset( $finding['code'] ) ? $finding['code'] : '',
					'what'             => isset( $finding['what'] ) ? $finding['what'] : '',
					'why'              => isset( $finding['why'] ) ? $finding['why'] : '',
					'original_value'   => DUPCAP_Elementor_Fields::scalar_text( isset( $finding['original_value'] ) ? $finding['original_value'] : '' ),
					'translated_value' => DUPCAP_Elementor_Fields::scalar_text( isset( $finding['translated_value'] ) ? $finding['translated_value'] : '' ),
					'context_label'    => isset( $finding['context_label'] ) ? $finding['context_label'] : '',
					'suggested_url'    => DUPCAP_Elementor_Fields::scalar_text( isset( $finding['suggested_url'] ) ? $finding['suggested_url'] : '' ),
					'nest_type'        => isset( $finding['nest_type'] ) ? $finding['nest_type'] : '',
					'nest_label'       => isset( $finding['nest_label'] ) ? $finding['nest_label'] : '',
					'fix_url'          => $fix_url,
					'fix_label'        => isset( $finding['fix_label'] ) ? $finding['fix_label'] : '',
					'ignore_key'       => isset( $finding['ignore_key'] ) ? $finding['ignore_key'] : '',
					'template_title'   => isset( $finding['template_title'] ) ? $finding['template_title'] : '',
					'template_type'    => $type,
					'widget_type'      => isset( $finding['widget_type'] ) ? $finding['widget_type'] : '',
				);
				$example['summary'] = DUPCAP_Elementor_Fields::format_example_summary( $example );
				$example['detail']  = DUPCAP_Elementor_Fields::format_example_detail( $example );
				$groups[ $bucket ]['examples'][] = $example;
			}
		}

		if ( isset( $groups['dead_link'] ) ) {
			$groups['dead_link'] = self::collapse_dead_link_bucket( $groups['dead_link'] );
			if ( (int) $groups['dead_link']['count'] <= 0 ) {
				unset( $groups['dead_link'] );
			} else {
				$badge_counts['warn'] += (int) $groups['dead_link']['count'];
			}
		}

		$buckets = array();
		foreach ( $groups as $key => $group ) {
			$meta = self::insight_meta_for_bucket( $key, $group['count'] );
			$buckets[] = array(
				'key'          => $key,
				'severity'     => $group['severity'],
				'title'        => $meta['title'],
				'message'      => $meta['message'],
				'count'        => $group['count'],
				'examples'     => $group['examples'],
				'action_url'   => self::insight_action_url( $key, $group['examples'] ),
				'action_label' => $meta['action_label'],
				'sort'         => $meta['sort'],
			);
		}

		usort(
			$buckets,
			function ( $a, $b ) {
				if ( $a['sort'] === $b['sort'] ) {
					return $b['count'] - $a['count'];
				}
				return $a['sort'] - $b['sort'];
			}
		);

		$max_buckets = (int) apply_filters( 'dupcap_elementor_dashboard_insight_limit', 6 );
		$more_types  = 0;
		if ( $max_buckets > 0 && count( $buckets ) > $max_buckets ) {
			// Critical buckets are always shown; the limit only trims lower-severity types.
			$critical_buckets = array();
			$other_buckets    = array();
			foreach ( $buckets as $bucket ) {
				if ( DUPCAP_Elementor_Finding::CRITICAL === $bucket['severity'] ) {
					$critical_buckets[] = $bucket;
				} else {
					$other_buckets[] = $bucket;
				}
			}

			$other_allowed = max( 0, $max_buckets - count( $critical_buckets ) );
			if ( count( $other_buckets ) > $other_allowed ) {
				$more_types    = count( $other_buckets ) - $other_allowed;
				$other_buckets = array_slice( $other_buckets, 0, $other_allowed );
			}

			$buckets = array_merge( $critical_buckets, $other_buckets );

			usort(
				$buckets,
				function ( $a, $b ) {
					if ( $a['sort'] === $b['sort'] ) {
						return $b['count'] - $a['count'];
					}
					return $a['sort'] - $b['sort'];
				}
			);
		}

		$result = array(
			'visible'        => true,
			'status'         => 'issues',
			'summary'        => $summary,
			'health_score'   => DUPCAP_Elementor_Scanner::health_score_from_report( $report, $filtered ),
			'buckets'        => $buckets,
			'more_types'     => $more_types,
			'badge_counts'   => $badge_counts,
			'document_types' => $document_types,
		);

		// Saved findings carry fix links whose nonce belongs to the scan request;
		// re-sign them for the user viewing the dashboard now.
		$result = DUPCAP_Action_Links::refresh_deep( $result );

		/**
		 * Filter dashboard Elementor insight payload.
		 *
		 * @param array $result  Insights.
		 * @param array $report  Full scan report.
		 */
		return apply_filters( 'dupcap_elementor_dashboard_insights', $result, $report );
	}

	/**
	 * Build sorted document type rows for the dashboard.
	 *
	 * @param array $report Scan report.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function build_document_types_from_report( array $report ) {
		$statistics = isset( $report['statistics'] ) && is_array( $report['statistics'] ) ? $report['statistics'] : array();
		$by_type    = isset( $statistics['by_type'] ) && is_array( $statistics['by_type'] ) ? $statistics['by_type'] : array();

		if ( empty( $by_type ) ) {
			return array();
		}

		$priority = array(
			'header'    => 0,
			'footer'    => 1,
			'single'    => 2,
			'archive'   => 3,
			'popup'     => 4,
			'loop-item' => 5,
			'section'   => 6,
			'page'      => 7,
			'wp-page'   => 8,
			'wp-post'   => 9,
			'template'  => 10,
		);

		$rows = array();
		foreach ( $by_type as $slug => $count ) {
			$count = (int) $count;
			if ( $count <= 0 ) {
				continue;
			}
			$slug = (string) $slug;
			$rows[] = array(
				'slug'    => $slug,
				'label'   => DUPCAP_Elementor_Document::type_label_for( $slug ),
				'display' => DUPCAP_Elementor_Document::type_display( $slug ),
				'count'   => $count,
				'sort'    => isset( $priority[ $slug ] ) ? $priority[ $slug ] : 100,
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['sort'] === $b['sort'] ) {
					return strcmp( $a['label'], $b['label'] );
				}
				return $a['sort'] - $b['sort'];
			}
		);

		return $rows;
	}

	/**
	 * Collapse dead-link findings so one missing page is one issue.
	 *
	 * @since 2.3.0
	 *
	 * @param array{key?: string, severity: string, count: int, examples: array<int, array<string, mixed>>} $group Bucket.
	 * @return array{key?: string, severity: string, count: int, examples: array<int, array<string, mixed>>}
	 */
	protected static function collapse_dead_link_bucket( array $group ) {
		$examples = isset( $group['examples'] ) && is_array( $group['examples'] ) ? $group['examples'] : array();
		$clusters = DUPCAP_Issue_Normalizer::group_by_target_url( $examples );
		$collapsed = array();

		foreach ( $clusters as $cluster ) {
			$places = isset( $cluster['details'] ) && is_array( $cluster['details'] ) ? $cluster['details'] : array();
			if ( empty( $places ) ) {
				continue;
			}

			$primary = $places[0];
			$url     = isset( $cluster['url'] ) ? (string) $cluster['url'] : '';
			$path    = '';
			if ( '' !== $url && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
				$path = DUPCAP_Elementor_Fields::readable_url( $url );
				if ( '' === $path ) {
					$path = DUPCAP_Elementor_Fields::relative_path( $url );
				}
			}
			if ( '' === $path ) {
				$path = $url;
			}

			$kind = 'missing';
			if ( '' !== $url && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
				$target = DUPCAP_Elementor_Fields::describe_dead_link_target( $url );
				$kind   = isset( $target['kind'] ) ? (string) $target['kind'] : 'missing';
				if ( ! empty( $target['fix_url'] ) ) {
					$primary['fix_url'] = (string) $target['fix_url'];
				}
				if ( ! empty( $target['fix_label'] ) ) {
					$primary['fix_label'] = (string) $target['fix_label'];
				}
			}

			$occ = isset( $cluster['occurrence_count'] ) ? (int) $cluster['occurrence_count'] : count( $places );
			$langs = isset( $cluster['languages'] ) && is_array( $cluster['languages'] ) ? $cluster['languages'] : array();

			$link_texts = array();
			foreach ( $places as $place_row ) {
				if ( ! is_array( $place_row ) ) {
					continue;
				}
				$label = isset( $place_row['context_label'] ) ? trim( (string) $place_row['context_label'] ) : '';
				if ( '' !== $label ) {
					$link_texts[] = $label;
				}
			}
			$link_texts = array_values( array_unique( $link_texts ) );

			$primary['code']              = 'dead_link';
			$primary['url']               = $url;
			$primary['translated_value']  = $url;
			$primary['grouped']           = true;
			$primary['occurrence_count']  = $occ;
			$primary['languages']         = $langs;
			$primary['occurrences']       = $places;
			if ( ! empty( $link_texts[0] ) ) {
				$primary['context_label'] = $link_texts[0];
			}
			if ( 1 === count( $link_texts ) ) {
				$primary['summary'] = sprintf(
					/* translators: 1: menu/button link text 2: path or URL of the missing page */
					__( 'Missing page: “%1$s” (%2$s)', 'duplicate-content-addon-for-polylang' ),
					$link_texts[0],
					$path
				);
			} else {
				$primary['summary'] = sprintf(
					/* translators: %s: path or URL of the missing page */
					__( 'Missing page: %s', 'duplicate-content-addon-for-polylang' ),
					$path
				);
			}
			$primary['detail']            = class_exists( 'DUPCAP_Elementor_Fields' )
				? DUPCAP_Elementor_Fields::format_dead_link_detail( $path ? $path : $url, $kind )
				: '';
			if ( empty( $primary['fix_label'] ) ) {
				$primary['fix_label'] = __( 'Create page', 'duplicate-content-addon-for-polylang' );
			}
			if ( empty( $primary['fix_url'] ) ) {
				$primary['fix_url'] = admin_url( 'post-new.php?post_type=page' );
			}

			$collapsed[] = $primary;
		}

		$group['examples'] = $collapsed;
		$group['count']    = count( $collapsed );

		return $group;
	}

	/**
	 * Map finding code to insight bucket key.
	 *
	 * @param string $code Finding code.
	 * @return string
	 */
	protected static function insight_bucket_for_code( $code ) {
		if ( 'missing_language' === $code ) {
			return 'missing_translation';
		}

		if ( in_array( $code, array( 'invalid_json', 'invalid_json_translation' ), true ) ) {
			return 'invalid_json';
		}

		if ( in_array( $code, array( 'untranslated_link', 'wrong_language_link' ), true ) ) {
			return 'broken_link';
		}

		if ( 'dead_link' === $code ) {
			return 'dead_link';
		}

		if ( in_array( $code, array( 'duplicate_id', 'missing_id', 'missing_el_type', 'missing_widget_type', 'invalid_node' ), true ) ) {
			return 'invalid_structure';
		}

		if ( in_array(
			$code,
			array(
				'missing_display_conditions',
				'conflicting_display_conditions',
				'wrong_language_condition',
				'missing_sitewide_header',
				'cpel_recommended_for_headers',
			),
			true
		) ) {
			return 'display_conditions';
		}

		if ( in_array( $code, array( 'wrong_loop_template', 'missing_loop_template' ), true ) ) {
			return 'wrong_loop_template';
		}

		if ( 'missing_loop_item_translation' === $code ) {
			return 'missing_loop_item_translation';
		}

		$known = array(
			'missing_translation',
			'orphan_translation',
			'empty_translation',
			'missing_widget',
			'missing_field',
			'untranslated_field',
			'untranslated_link',
			'wrong_language_link',
			'dead_link',
			'repeater_count_mismatch',
			'wrong_loop_template',
			'missing_loop_item_translation',
		);

		return in_array( $code, $known, true ) ? $code : 'other';
	}

	/**
	 * Titles and messages for insight buckets.
	 *
	 * @param string $key   Bucket key.
	 * @param int    $count Issue count.
	 * @return array
	 */
	protected static function insight_meta_for_bucket( $key, $count ) {
		$titles = array(
			'missing_translation'    => _n( 'Missing Elementor translation', 'Missing Elementor translations', $count, 'duplicate-content-addon-for-polylang' ),
			'orphan_translation'     => _n( 'Translation not linked to an original', 'Translations not linked to an original', $count, 'duplicate-content-addon-for-polylang' ),
			'invalid_json'           => _n( 'Corrupted Elementor JSON', 'Corrupted Elementor JSON documents', $count, 'duplicate-content-addon-for-polylang' ),
			'empty_translation'      => _n( 'Empty Elementor translation', 'Empty Elementor translations', $count, 'duplicate-content-addon-for-polylang' ),
			'missing_widget'         => _n( 'Widget missing in translation', 'Widgets missing in translations', $count, 'duplicate-content-addon-for-polylang' ),
			'missing_field'          => _n( 'Empty translated field', 'Empty translated fields', $count, 'duplicate-content-addon-for-polylang' ),
			'untranslated_field'     => _n( 'Same text as the original language', 'Same texts as the original language', $count, 'duplicate-content-addon-for-polylang' ),
			'broken_link'            => _n( 'Wrong link in translation', 'Wrong links in translations', $count, 'duplicate-content-addon-for-polylang' ),
			'dead_link'              => _n( 'Missing page linked from layout', 'Missing pages linked from layouts', $count, 'duplicate-content-addon-for-polylang' ),
			'untranslated_link'      => _n( 'Header/footer link still in default language', 'Header/footer links still in default language', $count, 'duplicate-content-addon-for-polylang' ),
			'wrong_language_link'    => _n( 'Link points to wrong language page', 'Links point to wrong language pages', $count, 'duplicate-content-addon-for-polylang' ),
			'repeater_count_mismatch'=> _n( 'Repeater row mismatch', 'Repeater row mismatches', $count, 'duplicate-content-addon-for-polylang' ),
			'wrong_loop_template'    => _n( 'Loop Grid uses wrong language template', 'Loop Grid uses wrong language templates', $count, 'duplicate-content-addon-for-polylang' ),
			'missing_loop_item_translation' => _n( 'Loop Item template missing translation', 'Loop Item templates missing translations', $count, 'duplicate-content-addon-for-polylang' ),
			'display_conditions'     => _n( 'Elementor display conditions need fixing', 'Elementor display conditions need fixing', $count, 'duplicate-content-addon-for-polylang' ),
			'invalid_structure'      => _n( 'Invalid Elementor structure', 'Invalid Elementor structures', $count, 'duplicate-content-addon-for-polylang' ),
			'other'                  => _n( 'Other Elementor issue', 'Other Elementor issues', $count, 'duplicate-content-addon-for-polylang' ),
		);

		$messages = array();
		foreach ( array_keys( $titles ) as $message_key ) {
			$messages[ $message_key ] = DUPCAP_Issue_Copy::elementor_message( $message_key );
		}

		$sort = array(
			'missing_translation'     => 0,
			'display_conditions'      => 1,
			'orphan_translation'      => 2,
			'invalid_json'            => 3,
			'empty_translation'       => 4,
			'broken_link'             => 5,
			'dead_link'               => 4,
			'untranslated_link'       => 5,
			'wrong_language_link'     => 6,
			'missing_widget'          => 7,
			'missing_field'           => 8,
			'untranslated_field'      => 9,
			'repeater_count_mismatch' => 10,
			'wrong_loop_template'     => 5,
			'missing_loop_item_translation' => 4,
			'invalid_structure'       => 11,
			'other'                   => 12,
		);

		$labels = array(
			'missing_translation'     => __( 'Add translation', 'duplicate-content-addon-for-polylang' ),
			'orphan_translation'      => __( 'Connect original', 'duplicate-content-addon-for-polylang' ),
			'invalid_json'            => __( 'Edit in Elementor', 'duplicate-content-addon-for-polylang' ),
			'empty_translation'       => __( 'Edit translation', 'duplicate-content-addon-for-polylang' ),
			'missing_widget'          => __( 'Edit translation', 'duplicate-content-addon-for-polylang' ),
			'missing_field'           => __( 'Edit in Elementor', 'duplicate-content-addon-for-polylang' ),
			'untranslated_field'      => __( 'Translate in Elementor', 'duplicate-content-addon-for-polylang' ),
			'broken_link'             => __( 'Fix link in Elementor', 'duplicate-content-addon-for-polylang' ),
			'dead_link'               => __( 'Create missing page', 'duplicate-content-addon-for-polylang' ),
			'untranslated_link'       => __( 'Fix link in Elementor', 'duplicate-content-addon-for-polylang' ),
			'wrong_language_link'     => __( 'Fix link in Elementor', 'duplicate-content-addon-for-polylang' ),
			'repeater_count_mismatch' => __( 'Edit translation', 'duplicate-content-addon-for-polylang' ),
			'wrong_loop_template'     => __( 'Fix loop template', 'duplicate-content-addon-for-polylang' ),
			'missing_loop_item_translation' => __( 'Translate loop template', 'duplicate-content-addon-for-polylang' ),
			'display_conditions'      => __( 'Fix display conditions', 'duplicate-content-addon-for-polylang' ),
			'invalid_structure'       => __( 'Edit in Elementor', 'duplicate-content-addon-for-polylang' ),
			'other'                   => __( 'Fix', 'duplicate-content-addon-for-polylang' ),
		);

		return array(
			'title'        => isset( $titles[ $key ] ) ? $titles[ $key ] : $titles['other'],
			'message'      => isset( $messages[ $key ] ) ? $messages[ $key ] : $messages['other'],
			'action_label' => isset( $labels[ $key ] ) ? $labels[ $key ] : $labels['other'],
			'sort'         => isset( $sort[ $key ] ) ? $sort[ $key ] : 9,
		);
	}

	/**
	 * Primary action URL for an insight bucket.
	 *
	 * @param string $key      Bucket key.
	 * @param array  $examples Example rows.
	 * @return string
	 */
	protected static function insight_action_url( $key, array $examples ) {
		if ( 'dead_link' === $key ) {
			return admin_url( 'post-new.php?post_type=page' );
		}

		if ( ! empty( $examples[0]['fix_url'] ) ) {
			return (string) $examples[0]['fix_url'];
		}

		if ( 'missing_translation' === $key && ! empty( $examples[0] ) ) {
			$url = self::missing_translation_url_from_example( $examples[0] );
			if ( $url ) {
				return $url;
			}
		}

		if ( 'orphan_translation' === $key && ! empty( $examples[0]['template_id'] ) ) {
			$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
			if ( $default ) {
				$doc = new DUPCAP_Elementor_Document( (int) $examples[0]['template_id'] );
				$url = $doc->new_translation_url( $default );
				if ( $url ) {
					return $url;
				}
			}

			return admin_url( 'edit.php?post_type=elementor_library' );
		}

		return admin_url( 'edit.php?post_type=page' );
	}

	/**
	 * Build a Polylang new-translation URL from an insight example row.
	 *
	 * @param array<string, mixed> $example Example row.
	 * @return string
	 */
	protected static function missing_translation_url_from_example( array $example ) {
		$template_id = isset( $example['template_id'] ) ? (int) $example['template_id'] : 0;
		$language    = isset( $example['language'] ) ? (string) $example['language'] : '';

		if ( $template_id > 0 && '' !== $language ) {
			$doc = new DUPCAP_Elementor_Document( $template_id );
			$url = $doc->new_translation_url( $language );
			if ( $url ) {
				return $url;
			}
		}

		if ( $template_id > 0 ) {
			$edit = get_edit_post_link( $template_id, 'url' );
			if ( $edit ) {
				return $edit;
			}
		}

		return '';
	}
}




