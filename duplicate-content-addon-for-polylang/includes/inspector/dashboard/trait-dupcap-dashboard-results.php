<?php


defined( 'ABSPATH' ) || exit;


trait DUPCAP_Dashboard_Results_Trait {

		/**
		 * Get normalized scan results.
		 *
		 * Scanner results are provided via {@see 'dupcap_dashboard_results'}.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function get_scan_results() {
			$raw_results = apply_filters( 'dupcap_dashboard_results', array() );

			return $this->normalize_results( is_array( $raw_results ) ? $raw_results : array() );
		}

		/**
		 * Normalize scan records to the dashboard view model.
		 *
		 * @param array<int, array<string, mixed>> $raw_results Raw scan records.
		 * @return array<int, array<string, mixed>>
		 */
		private function normalize_results( array $raw_results ) {
			$normalized = array();

			foreach ( $raw_results as $index => $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}

				$severity = isset( $result['severity'] ) ? sanitize_key( $result['severity'] ) : 'pass';
				if ( ! in_array( $severity, array( 'crit', 'warn', 'info', 'pass' ), true ) ) {
					$severity = 'pass';
				}

				$group = isset( $result['group'] ) ? (string) $result['group'] : __( 'General', 'duplicate-content-addon-for-polylang' );
				$code  = isset( $result['code'] ) ? sanitize_key( $result['code'] ) : 'check_' . $index;

				$affected_languages = array();
				if ( isset( $result['affected_languages'] ) && is_array( $result['affected_languages'] ) ) {
					$affected_languages = array_values( array_filter( array_map( 'sanitize_text_field', $result['affected_languages'] ) ) );
				}

				$details = array();
				if ( isset( $result['details'] ) && is_array( $result['details'] ) ) {
					$details = $this->sanitize_details( $result['details'] );
				}

				$normalized[] = array(
					'severity'           => $severity,
					'group'              => sanitize_text_field( $group ),
					'code'               => $code,
					'title'              => isset( $result['title'] ) ? sanitize_text_field( (string) $result['title'] ) : __( 'Untitled check', 'duplicate-content-addon-for-polylang' ),
					'message'            => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
					'affected_languages' => $affected_languages,
					'action_url'         => isset( $result['action_url'] ) ? esc_url_raw( (string) $result['action_url'] ) : '',
					'action_label'       => isset( $result['action_label'] ) ? sanitize_text_field( (string) $result['action_label'] ) : '',
					'details'            => $details,
					'details_truncated'  => ! empty( $result['details_truncated'] ),
					'dedupe_key'         => isset( $result['dedupe_key'] ) ? sanitize_key( $result['dedupe_key'] ) : $code,
				);
			}

			return $normalized;
		}

		/**
		 * Sanitize nested detail rows.
		 *
		 * @param array<int, mixed> $details Detail rows.
		 * @return array<int, array<string, mixed>>
		 */
		private function sanitize_details( array $details ) {
			$clean = array();

			foreach ( $details as $detail ) {
				if ( ! is_array( $detail ) ) {
					continue;
				}

				$row = array();
				foreach ( $detail as $key => $value ) {
					$key = sanitize_key( (string) $key );
					if ( is_array( $value ) ) {
						$row[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
					} elseif ( false !== strpos( $key, 'url' ) ) {
						$raw = trim( (string) $value );
						// Relative site paths (e.g. /features/) are valid menu targets;
						// esc_url_raw() alone would drop them to an empty string.
						if ( '' !== $raw && 0 === strpos( $raw, '/' ) && 0 !== strpos( $raw, '//' ) ) {
							$row[ $key ] = esc_url_raw( home_url( $raw ) );
						} else {
							$row[ $key ] = esc_url_raw( $raw );
						}
					} else {
						$row[ $key ] = sanitize_text_field( (string) $value );
					}
				}
				$clean[] = $row;
			}

			return $clean;
		}

		/**
		 * Passed checks grouped by the dashboard section they belong to.
		 *
		 * @param array<int, array<string, mixed>> $passed_checks Passed results.
		 * @return array<string, array<int, array<string, mixed>>> Keyed content|elementor|urls|other.
		 */
		private function split_passed_checks_by_section( array $passed_checks ) {
			$split = array(
				'content'   => array(),
				'elementor' => array(),
				'urls'      => array(),
				'other'     => array(),
			);

			foreach ( $passed_checks as $check ) {
				$split[ $this->get_result_section( isset( $check['code'] ) ? (string) $check['code'] : '' ) ][] = $check;
			}

			return $split;
		}

		/**
		 * Severity pill counts for one tab, in issue types (cards), not rows.
		 *
		 * Critical groups render in Fix These First, so here they are represented
		 * by the tab's pointer. Elementor adds its insight buckets. Info cards
		 * count as warnings.
		 *
		 * @param string $section  Section key (content|elementor|urls|other).
		 * @param array  $sections Non-critical groups by section.
		 * @param array  $critical Critical groups by section.
		 * @param array  $passed   Passed checks by section.
		 * @return array{crit: int, warn: int, pass: int}
		 */
		private function get_section_severity_counts( $section, array $sections, array $critical, array $passed ) {
			/*
			 * Pills count issues the way the cards beneath them do: the rows the
			 * user can act on, not the cards holding them. One page missing six
			 * languages is one row and one issue.
			 */
			$seed = 0;
			foreach ( $critical[ $section ] as $group ) {
				$seed += DUPCAP_Issue_Normalizer::count_for( $group );
			}

			$counts = array(
				'crit'           => $seed,
				'warn'           => 0,
				'notice'         => 0,
				'pass'           => count( $passed[ $section ] ),
				// Of those criticals, how many render in Fix These First instead
				// of here. The panel shows a pointer in their place.
				'crit_elsewhere' => $seed,
			);

			foreach ( $sections[ $section ] as $group ) {
				$severity = isset( $group['severity'] ) ? (string) $group['severity'] : 'warn';
				$rows     = DUPCAP_Issue_Normalizer::count_for( $group );

				if ( DUPCAP_Issue_Types::SEVERITY_CRITICAL === $severity ) {
					$counts['crit'] += $rows;
				} elseif ( DUPCAP_Issue_Normalizer::is_enabled() && DUPCAP_Issue_Types::SEVERITY_NOTICE === $severity ) {
					$counts['notice'] += $rows;
				} else {
					$counts['warn'] += $rows;
				}
			}

			if ( 'elementor' === $section ) {
				$insights = $this->get_elementor_insights();
				if (
					! empty( $insights['visible'] )
					&& isset( $insights['status'] ) && 'pass' !== $insights['status']
					&& ! empty( $insights['buckets'] ) && is_array( $insights['buckets'] )
				) {
					foreach ( $insights['buckets'] as $bucket ) {
						$is_crit = isset( $bucket['severity'] ) && DUPCAP_Elementor_Finding::CRITICAL === $bucket['severity'];
						$found   = isset( $bucket['count'] ) ? (int) $bucket['count'] : 1;

						$counts[ $is_crit ? 'crit' : 'warn' ] += $found;
					}
				}
			}

			return $counts;
		}

		/**
		 * Strip the site's static front page out of missing/draft/term content
		 * issue groups, since those gaps are already covered by the dedicated
		 * "Homepage Translation Missing" critical check in Fix These First.
		 *
		 * Do NOT strip content_source_updated: that warns that homepage
		 * *content* changed, which the missing-translation check does not cover.
		 *
		 * @param array<int, array<string, mixed>> $issue_groups Issue groups keyed by dedupe_key.
		 * @return array<int, array<string, mixed>>
		 */
		private function remove_front_page_from_content_groups( array $issue_groups ) {
			$front_id = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;

			if ( $front_id <= 0 ) {
				return $issue_groups;
			}

			$content_keys = array( 'content_missing', 'content_draft', 'term_missing' );

			foreach ( $issue_groups as $key => $group ) {
				$dedupe_key = isset( $group['dedupe_key'] ) ? $group['dedupe_key'] : '';
				if ( ! in_array( $dedupe_key, $content_keys, true ) || empty( $group['details'] ) || ! is_array( $group['details'] ) ) {
					continue;
				}

				$filtered_details = array_values(
					array_filter(
						$group['details'],
						function ( $detail ) use ( $front_id ) {
							return empty( $detail['post_id'] ) || (int) $detail['post_id'] !== $front_id;
						}
					)
				);

				// Nothing removed — leave the group untouched.
				if ( count( $filtered_details ) === count( $group['details'] ) ) {
					continue;
				}

				// Group became empty after removing the homepage row — drop it entirely.
				if ( empty( $filtered_details ) ) {
					unset( $issue_groups[ $key ] );
					continue;
				}

				$issue_groups[ $key ]['details'] = $filtered_details;
			}

			return array_values( $issue_groups );
		}

		/**
		 * Issue count shown in grouped card title.
		 *
		 * @param array<string, mixed> $group Issue group.
		 * @return int
		 */
		private function get_group_issue_count( array $group ) {
			// One counting rule for the Overview, the tab badge, this header and the
			// Markdown export. Which unit an issue type counts in is a fact about the
			// type, so it lives in the registry rather than being re-decided here.
			if ( DUPCAP_Issue_Normalizer::is_enabled() ) {
				return DUPCAP_Issue_Normalizer::count_for( $group );
			}

			// Cards that group their rows by item (post, template, theme location,
			// string) list the languages inside the row, so the count is the number
			// of rows: 5 templates missing 6 languages is 5 issues, not 30.
			if ( 'menu_assignments' === $group['dedupe_key'] ) {
				return count( $this->get_menu_gap_rows( $group['details'] ) );
			}

			if ( 'menu_dead_links' === $group['dedupe_key'] ) {
				return DUPCAP_Issue_Normalizer::count_target_urls(
					isset( $group['details'] ) && is_array( $group['details'] ) ? $group['details'] : array()
				);
			}

			if ( 'strings_missing' === $group['dedupe_key'] ) {
				$rows = $this->group_strings_by_item( $group['details'] );

				return ! empty( $rows ) ? count( $rows ) : count( $group['details'] );
			}

			if ( 'navigation' === $group['dedupe_key'] ) {
				$issues = 0;
				foreach ( $group['details'] as $detail ) {
					if ( ! empty( $detail['code'] ) && 'wrong_language_link' === $detail['code'] ) {
						$issues++;
						continue;
					}
					if ( isset( $detail['severity'] ) && 'warn' === $detail['severity'] ) {
						$issues++;
					}
				}

				return $issues > 0 ? $issues : 1;
			}

			// Same rule for the tables that group by post, term or Elementor
			// template. Without post ids the card falls back to plain rows, and
			// then the old per-row count is what is on screen.
			if ( in_array( $group['dedupe_key'], array( 'content_missing', 'content_draft', 'term_missing', 'content_source_updated', 'elementor_templates' ), true ) && $this->has_content_pending_posts( $group['details'] ) ) {
				return $this->count_content_pending_posts( $group['details'] );
			}

			if ( in_array( $group['dedupe_key'], array( 'content_missing', 'content_draft', 'term_missing', 'content_source_updated' ), true ) ) {
				return $this->count_content_pending_gaps( $group['details'] );
			}

			return count( $group['details'] );
		}

		/**
		 * Build deduplicated issue groups.
		 *
		 * @param array<int, array<string, mixed>> $results Results.
		 * @return array<int, array<string, mixed>>
		 */
		private function build_issue_groups( array $results ) {
			$groups = array();

			foreach ( $results as $result ) {
				if ( 'pass' === $result['severity'] ) {
					continue;
				}

				$key = $result['dedupe_key'];
				// Retired check: hide from UI even if an older saved report still has it.
				if ( 'content_off_source' === $key ) {
					continue;
				}

				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'severity'           => $result['severity'],
						'group'              => $result['group'],
						'dedupe_key'         => $key,
						'title'              => $this->get_group_title( $key, $result ),
						'message'            => $this->get_group_message( $key, $result ),
						'affected_languages' => array(),
						'action_url'         => $result['action_url'],
						'action_label'       => $result['action_label'],
						'details'            => array(),
						'details_truncated'  => false,
						'count'              => 0,
					);
				}

				if ( 'crit' === $result['severity'] ) {
					$groups[ $key ]['severity'] = 'crit';
				} elseif ( 'warn' === $result['severity'] && 'crit' !== $groups[ $key ]['severity'] ) {
					$groups[ $key ]['severity'] = 'warn';
				} elseif ( 'info' === $result['severity'] && ! in_array( $groups[ $key ]['severity'], array( 'crit', 'warn' ), true ) ) {
					$groups[ $key ]['severity'] = 'info';
				}

				// Prefer a real fix action from a higher-severity row (e.g. routing
				// timeouts are info with no CTA; a later 404 row may have Rebuild URL rules).
				if (
					empty( $groups[ $key ]['action_url'] )
					&& ! empty( $result['action_url'] )
					&& ! empty( $result['action_label'] )
				) {
					$groups[ $key ]['action_url']   = $result['action_url'];
					$groups[ $key ]['action_label'] = $result['action_label'];
				}

				$groups[ $key ]['count']++;
				$groups[ $key ]['affected_languages'] = array_merge( $groups[ $key ]['affected_languages'], $result['affected_languages'] );

				if ( ! empty( $result['details'] ) ) {
					$groups[ $key ]['details'] = array_merge( $groups[ $key ]['details'], $result['details'] );
				} else {
					$detail = array(
						'label'        => $result['title'],
						'message'      => $result['message'],
						'action_url'   => $result['action_url'],
						'action_label' => $result['action_label'],
					);

					// Older routing timeout rows had no details / ignore key.
					if (
						'routing_health' === $key
						&& ! empty( $result['id'] )
						&& 'url_route_untestable' === (string) $result['id']
						&& class_exists( 'DUPCAP_Routing_Scanner' )
					) {
						$detail['url']          = home_url( '/' );
						$detail['verdict']      = 'untestable';
						$detail['ignore_key']   = DUPCAP_Routing_Scanner::untestable_ignore_key();
						$detail['ignore_label'] = __( 'Ignore', 'duplicate-content-addon-for-polylang' );
						if ( empty( $detail['action_url'] ) ) {
							$detail['action_url']   = home_url( '/' );
							$detail['action_label'] = __( 'Open homepage', 'duplicate-content-addon-for-polylang' );
						}
					}

					$groups[ $key ]['details'][] = $detail;
				}

				if ( ! empty( $result['details_truncated'] ) ) {
					$groups[ $key ]['details_truncated'] = true;
				}
			}

			foreach ( $groups as $key => $group ) {
				$groups[ $key ]['affected_languages'] = array_values( array_unique( $group['affected_languages'] ) );

				// No row carried a fix link, so fall back to the one the issue
				// type declares. A reported problem with nowhere to go is not
				// a finished report.
				if ( empty( $groups[ $key ]['action_url'] ) ) {
					$declared_fix = DUPCAP_Issue_Registry::fix_for( $key );
					if ( ! empty( $declared_fix ) ) {
						$groups[ $key ]['action_url']   = $declared_fix['url'];
						$groups[ $key ]['action_label'] = $declared_fix['label'];
					}
				}

				// Severity is a fact about the issue type, not about which row of
				// it happened to arrive first. Types that genuinely vary per row
				// (routing, URL config, translated permalinks) declare nothing and
				// keep the severity their own check computed.
				if ( DUPCAP_Issue_Normalizer::is_enabled() ) {
					$declared = DUPCAP_Issue_Registry::declared_severity( $key );
					if ( '' !== $declared && DUPCAP_Issue_Types::SEVERITY_PASS !== $declared ) {
						$groups[ $key ]['severity'] = $declared;
					}
				}

				if ( 'menu_assignments' === $key ) {
					$groups[ $key ]['details'] = $this->consolidate_menu_details( $group['details'] );
					// Keep only locations that still have missing language assignments.
					// Empty stubs (ignored gaps, dead-link rows, or title-only rows)
					// must not keep a Warning card alive with "(0 Issues)".
					$groups[ $key ]['details'] = array_values(
						array_filter(
							$groups[ $key ]['details'],
							function ( $detail ) {
								return ( ! empty( $detail['missing'] ) && is_array( $detail['missing'] ) )
									|| ( ! empty( $detail['missing_languages'] ) && is_array( $detail['missing_languages'] ) );
							}
						)
					);
					if ( empty( $groups[ $key ]['details'] ) || empty( $this->get_menu_gap_rows( $groups[ $key ]['details'] ) ) ) {
						unset( $groups[ $key ] );
						continue;
					}
					$groups[ $key ]['message'] = $this->get_menu_group_message( $groups[ $key ]['details'] );
				}
				if ( 'content_missing' === $key ) {
					$groups[ $key ]['message'] = $this->get_content_group_message( $group['details'] );
				}
				if ( 'content_draft' === $key ) {
					$groups[ $key ]['message'] = $this->get_content_draft_group_message( $group['details'] );
				}
				if ( 'term_missing' === $key ) {
					$groups[ $key ]['message'] = $this->get_term_group_message( $group['details'] );
				}
				if ( 'elementor_templates' === $key ) {
					$groups[ $key ]['message'] = $this->get_elementor_templates_group_message( $group['details'] );
				}
				if ( 'routing_health' === $key ) {
					$groups[ $key ]['message'] = $this->get_routing_group_message( $groups[ $key ] );
				}
			}

			uasort(
				$groups,
				function ( $a, $b ) {
					$weight = array( 'crit' => 0, 'warn' => 1, 'info' => 2, 'pass' => 3 );
					return $weight[ $a['severity'] ] <=> $weight[ $b['severity'] ];
				}
			);

			return array_values( $groups );
		}

		/**
		 * Get group title.
		 *
		 * @param string               $key Group key.
		 * @param array<string, mixed> $result Result.
		 * @return string
		 */
		private function get_group_title( $key, array $result ) {
			$titles = array(
				'homepage_translations' => __( 'Homepage not translated for all languages', 'duplicate-content-addon-for-polylang' ),
				'content_nolang'        => __( 'Content without a language', 'duplicate-content-addon-for-polylang' ),
				'content_missing'       => __( 'Content missing by language', 'duplicate-content-addon-for-polylang' ),
				'content_identical'      => __( 'Identical content across languages', 'duplicate-content-addon-for-polylang' ),
				'content_acf'            => __( 'Identical ACF and custom fields across languages', 'duplicate-content-addon-for-polylang' ),
				'content_placeholder'    => __( 'Shortcodes or placeholders differ between source and translation', 'duplicate-content-addon-for-polylang' ),
				'content_links'          => __( 'Broken or wrong-language links in content', 'duplicate-content-addon-for-polylang' ),
				'content_draft'          => __( 'Unpublished translations by language', 'duplicate-content-addon-for-polylang' ),
				'content_source_updated' => __( 'Default language content was updated', 'duplicate-content-addon-for-polylang' ),
				'term_missing'           => __( 'Categories and tags missing by language', 'duplicate-content-addon-for-polylang' ),
				'menu_assignments'      => __( 'Menu assignments missing across languages', 'duplicate-content-addon-for-polylang' ),
				'menu_dead_links'       => __( 'Menu links point to missing pages', 'duplicate-content-addon-for-polylang' ),
				'navigation'            => __( 'Block navigation needs review', 'duplicate-content-addon-for-polylang' ),
				'lang_switcher'         => __( 'No language switcher detected', 'duplicate-content-addon-for-polylang' ),
				'elementor_templates'   => __( 'Elementor templates are not set as translatable', 'duplicate-content-addon-for-polylang' ),
				'strings_missing'       => __( 'Site strings untranslated by language', 'duplicate-content-addon-for-polylang' ),
			);

			return isset( $titles[ $key ] ) ? $titles[ $key ] : $result['title'];
		}

		/**
		 * Get group message.
		 *
		 * @param string               $key Group key.
		 * @param array<string, mixed> $result Result.
		 * @return string
		 */
		private function get_group_message( $key, array $result ) {
			if ( 'translated_permalinks' === $key && ! empty( $result['message'] ) ) {
				return (string) $result['message'];
			}

			$message = DUPCAP_Issue_Copy::group_message( $key );
			return '' !== $message ? $message : $result['message'];
		}

		/**
		 * Sort issue groups by recommended repair order.
		 *
		 * @param array<int, array<string, mixed>> $issue_groups Issue groups.
		 * @return array<int, array<string, mixed>>
		 */
		private function sort_issue_groups_by_priority( array $issue_groups ) {
			// Routing outranks everything: a language that does not load at all is
			// worse than one with untranslated content. This matches the health
			// score, where routing costs 30 points and the homepage costs 25.
			$order = array(
				'routing_health'        => 0,
				'homepage_translations' => 1,
				'url_configuration'     => 2,
				'translated_permalinks' => 3,
				'content_nolang'        => 4,
				'elementor_templates'   => 5,
				'menu_assignments'      => 6,
				'menu_dead_links'       => 6,
				'navigation'            => 6,
				'lang_switcher'         => 7,
				'content_missing'        => 8,
				'content_source_updated' => 8,
				'content_identical'      => 9,
				'content_acf'            => 10,
				'content_placeholder'    => 10,
				'content_links'          => 11,
				'content_draft'          => 11,
				'term_missing'           => 12,
				'strings_missing'       => 13,
			);

			usort(
				$issue_groups,
				function ( $a, $b ) use ( $order ) {
					$a_order = isset( $order[ $a['dedupe_key'] ] ) ? $order[ $a['dedupe_key'] ] : 99;
					$b_order = isset( $order[ $b['dedupe_key'] ] ) ? $order[ $b['dedupe_key'] ] : 99;

					return $a_order <=> $b_order;
				}
			);

			return $issue_groups;
		}

		/**
		 * Split issue groups between Fix These First and Grouped Issues.
		 *
		 * @param array<int, array<string, mixed>> $issue_groups Issue groups.
		 * @return array{priority: array<int, array<string, mixed>>, remaining: array<int, array<string, mixed>>}
		 */
		private function split_issue_groups_for_display( array $issue_groups ) {
			$sorted    = $this->sort_issue_groups_by_priority( $issue_groups );
			$priority  = array();
			$remaining = array();

			foreach ( $sorted as $group ) {
				if ( 'crit' === $group['severity'] ) {
					$priority[] = $group;
				} else {
					$remaining[] = $group;
				}
			}

			return array(
				'priority'  => $priority,
				'remaining' => $remaining,
			);
		}

		/**
		 * Whether an issue group belongs in the Content section (pages/posts/CPTs).
		 *
		 * @param array<string, mixed> $group Issue group.
		 * @return bool
		 */
		private function is_content_document_group( array $group ) {
			$dedupe_key   = isset( $group['dedupe_key'] ) ? (string) $group['dedupe_key'] : '';
			$content_keys = array(
				'untranslated_types',
				'content_missing',
				'content_identical',
				'content_acf',
				'content_placeholder',
				'content_links',
				'content_draft',
				'content_source_updated',
				'content_nolang',
				'term_missing',
			);

			/**
			 * Filter which issue groups appear in the Content section.
			 *
			 * @param array  $content_keys Content dedupe keys.
			 * @param array  $group        Issue group.
			 */
			$content_keys = apply_filters( 'dupcap_content_section_keys', $content_keys, $group );

			return in_array( $dedupe_key, (array) $content_keys, true );
		}

		/**
		 * Whether an issue group belongs in the Elementor section.
		 *
		 * @param array<string, mixed> $group Issue group.
		 * @return bool
		 */
		private function is_elementor_issue_group( array $group ) {
			$dedupe_key = isset( $group['dedupe_key'] ) ? (string) $group['dedupe_key'] : '';
			$keys       = array( 'elementor_templates' );

			/**
			 * Filter which issue groups appear in the Elementor section.
			 *
			 * @param array $keys  Elementor dedupe keys.
			 * @param array $group Issue group.
			 */
			$keys = apply_filters( 'dupcap_elementor_section_keys', $keys, $group );

			return in_array( $dedupe_key, (array) $keys, true );
		}

		/**
		 * Whether an issue group belongs in the URLs & Routing section.
		 *
		 * @since 2.2.0
		 *
		 * @param array<string, mixed> $group Issue group.
		 * @return bool
		 */
		private function is_url_routing_group( array $group ) {
			$dedupe_key = isset( $group['dedupe_key'] ) ? (string) $group['dedupe_key'] : '';
			$keys       = array( 'routing_health', 'url_configuration', 'translated_permalinks' );

			// url_dupe_slugs is about two languages sharing one address. It had no
			// entry here, so it fell through to Site setup, sorted last, with no
			// copy of its own.
			if ( DUPCAP_Issue_Normalizer::is_enabled() ) {
				$keys[] = DUPCAP_Issue_Types::URL_DUPE_SLUGS;
			}

			/**
			 * Filter which issue groups appear in the URLs & Routing section.
			 *
			 * @since 2.2.0
			 *
			 * @param array $keys  URL/routing dedupe keys.
			 * @param array $group Issue group.
			 */
			$keys = apply_filters( 'dupcap_url_section_keys', $keys, $group );

			return in_array( $dedupe_key, (array) $keys, true );
		}

		/**
		 * Split remaining (non-priority) groups into Content, Elementor, URLs, and Site & Strings.
		 *
		 * @param array<int, array<string, mixed>> $remaining Remaining issue groups.
		 * @return array{content: array<int, array<string, mixed>>, elementor: array<int, array<string, mixed>>, urls: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>}
		 */
		private function split_remaining_groups_by_section( array $remaining ) {
			$content   = array();
			$elementor = array();
			$urls      = array();
			$other     = array();

			foreach ( $remaining as $group ) {
				if ( $this->is_content_document_group( $group ) ) {
					$content[] = $group;
				} elseif ( $this->is_elementor_issue_group( $group ) ) {
					$elementor[] = $group;
				} elseif ( $this->is_url_routing_group( $group ) ) {
					$urls[] = $group;
				} else {
					$other[] = $group;
				}
			}

			return array(
				'content'   => $content,
				'elementor' => $elementor,
				'urls'      => $urls,
				'other'     => $other,
			);
		}

		/**
		 * Count issues by severity for the Overview tiles.
		 *
		 * @param array<int, array<string, mixed>> $results      Results.
		 * @param array<int, array<string, mixed>> $issue_groups Issue groups, so the tiles
		 *                                                       count the same unit the
		 *                                                       section headers do.
		 * @return array<string, int>
		 */
		private function get_counts( array $results, array $issue_groups = array() ) {
			$counts = array(
				'crit' => 0,
				'warn' => 0,
				'pass' => 0,
			);

			/*
			 * Counting result rows here is what made the Overview disagree with the
			 * blocks below it: one content_missing result per language is four rows
			 * but twenty-two issues. Counting the groups through the same function
			 * the section headers use keeps the two totals identical.
			 *
			 * `info` is excluded from Warnings when the notice level is enabled, so
			 * the Overview total matches the section tabs.
			 */
			if ( DUPCAP_Issue_Normalizer::is_enabled() && ! empty( $issue_groups ) ) {
				foreach ( $issue_groups as $group ) {
					if ( ! is_array( $group ) ) {
						continue;
					}

					$severity = isset( $group['severity'] ) ? (string) $group['severity'] : 'warn';
					if ( DUPCAP_Issue_Types::SEVERITY_NOTICE === $severity ) {
						continue;
					}

					$bucket = ( 'crit' === $severity ) ? 'crit' : 'warn';
					$counts[ $bucket ] += DUPCAP_Issue_Normalizer::count_for( $group );
				}

				foreach ( $results as $result ) {
					if ( isset( $result['severity'] ) && 'pass' === $result['severity'] ) {
						++$counts['pass'];
					}
				}

				return $counts;
			}

			foreach ( $results as $result ) {
				if ( isset( $counts[ $result['severity'] ] ) ) {
					$counts[ $result['severity'] ]++;
				}
			}

			return $counts;
		}

		/**
		 * Distinct post type slugs referenced by a group's detail rows.
		 * Groups without post-type-tagged details (e.g. Menus) return an empty
		 * array — meaning "not applicable," so they stay visible under any
		 * content type filter selection.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return array<int, string>
		 */
		private function get_group_content_types( array $details ) {
			$types = array();

			foreach ( $details as $detail ) {
				if ( is_array( $detail ) && ! empty( $detail['post_type'] ) ) {
					$types[ (string) $detail['post_type'] ] = true;
				}
			}

			return array_keys( $types );
		}

		/**
		 * Elementor document type slugs associated with an issue group (for type filters).
		 *
		 * @param array<string, mixed> $group Issue group.
		 * @return array<int, string>
		 */
		private function get_elementor_group_document_types( array $group ) {
			$types = array();
			$key   = isset( $group['dedupe_key'] ) ? (string) $group['dedupe_key'] : '';

			if ( preg_match( '/^elementor_(.+)$/', $key, $matches ) ) {
				$slug = (string) $matches[1];
				if ( ! in_array( $slug, array( 'templates', 'none', 'not_translated' ), true ) ) {
					$types[ $slug ] = true;
				}
			}

			if ( ! empty( $group['details'] ) && is_array( $group['details'] ) ) {
				foreach ( $group['details'] as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					if ( ! empty( $detail['template_type'] ) ) {
						$types[ (string) $detail['template_type'] ] = true;
					}
					if ( ! empty( $detail['document_type'] ) ) {
						$types[ (string) $detail['document_type'] ] = true;
					}
				}
			}

			return array_keys( $types );
		}

		/**
		 * Filter results by severity.
		 *
		 * @param array<int, array<string, mixed>> $results Results.
		 * @param string                          $severity Severity.
		 * @return array<int, array<string, mixed>>
		 */
		private function filter_results_by_severity( array $results, $severity ) {
			return array_values(
				array_filter(
					$results,
					function ( $result ) use ( $severity ) {
						return $severity === $result['severity'];
					}
				)
			);
		}


}
