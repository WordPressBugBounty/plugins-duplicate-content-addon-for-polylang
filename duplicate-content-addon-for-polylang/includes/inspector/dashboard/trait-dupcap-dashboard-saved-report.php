<?php


defined( 'ABSPATH' ) || exit;

/**
 * Saved Report methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Saved_Report_Trait {

		/**
		 * Remove Elementor library scanner result from the saved report by template type / language.
		 *
		 * @param string $template_type Elementor template type.
		 * @param string $language      Target language.
		 * @return void
		 */
		private function remove_elementor_library_result_from_report( $template_type, $language ) {
			if ( ! class_exists( 'DUPCAP_Scanner' ) ) {
				return;
			}

			$report = DUPCAP_Scanner::get_saved_report();
			if ( ! is_array( $report ) || empty( $report['results'] ) || ! is_array( $report['results'] ) ) {
				return;
			}

			$is_translatable = function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( 'elementor_library' );

			$kept = array();
			foreach ( $report['results'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id = isset( $row['id'] ) ? (string) $row['id'] : '';

				if ( 'elementor_not_translated' === $id && ( $is_translatable || 'elementor_not_translated' === $template_type ) ) {
					continue;
				}

				if ( '' !== $template_type ) {
					if ( $id === 'elementor_' . $template_type . '_' . $language ) {
						continue;
					}
					if ( 0 === strpos( $id, 'elementor_' . $template_type . '_' ) && ( '' === $language || substr( $id, -strlen( '_' . $language ) ) === '_' . $language ) ) {
						continue;
					}
				}

				if ( ! empty( $row['details'] ) && is_array( $row['details'] ) ) {
					$kept_details = array();
					foreach ( $row['details'] as $detail ) {
						if ( ! is_array( $detail ) ) {
							continue;
						}
						$d_type = isset( $detail['template_type'] ) ? (string) $detail['template_type'] : '';
						$d_lang = isset( $detail['language'] ) ? (string) $detail['language'] : '';

						if ( 'elementor_not_translated' === $d_type && ( $is_translatable || 'elementor_not_translated' === $template_type ) ) {
							continue;
						}
						if ( '' !== $template_type && $d_type === $template_type && ( '' === $language || $d_lang === $language ) ) {
							continue;
						}
						$kept_details[] = $detail;
					}
					$row['details'] = array_values( $kept_details );
					if ( empty( $row['details'] ) && 0 === strpos( $id, 'elementor_' ) && 'elementor_none' !== $id ) {
						continue;
					}
				}

				$kept[] = $row;
			}

			$report['results'] = array_values( $kept );
			DUPCAP_Scanner::save_report( $report );
		}

		/**
		 * Refresh untranslated post types/taxonomies notice in saved report.
		 *
		 * @return void
		 */
		private function refresh_untranslated_types_in_saved_report() {
			if ( ! class_exists( 'DUPCAP_Scanner' ) ) {
				return;
			}

			$report = DUPCAP_Scanner::get_saved_report();
			if ( ! is_array( $report ) || empty( $report['results'] ) || ! is_array( $report['results'] ) ) {
				return;
			}

			$untranslated = $this->get_untranslated_post_types_and_taxonomies();

			$kept = array();
			foreach ( $report['results'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id = isset( $row['id'] ) ? (string) $row['id'] : '';
				if ( 'lang_untranslated_types' === $id ) {
					if ( empty( $untranslated ) ) {
						continue;
					}
					$items_str      = implode( ', ', $untranslated );
					$row['status']  = 'warning';
					$row['message'] = sprintf(
						/* translators: %s: comma separated names */
						__( 'The following content types/taxonomies are not set as translatable: %s. Enable them in Polylang settings so translations can be managed.', 'duplicate-content-addon-for-polylang' ),
						$items_str
					);
					if ( isset( $row['details'][0] ) && is_array( $row['details'][0] ) ) {
						$row['details'][0]['label'] = sprintf(
							/* translators: %s: comma separated names */
							__( 'Not set as translatable: %s', 'duplicate-content-addon-for-polylang' ),
							$items_str
						);
					}
				}
				$kept[] = $row;
			}

			$report['results'] = array_values( $kept );
			DUPCAP_Scanner::save_report( $report );
		}

		/**
		 * Remove menu dead-link rows that match a URL (for older reports without ignore keys).
		 *
		 * @param string $url Absolute or site URL.
		 * @return void
		 */
		private function remove_menu_dead_detail_by_url_from_report( $url ) {
			$url    = esc_url_raw( (string) $url );
			$report = DUPCAP_Scanner::get_saved_report();
			if ( '' === $url || ! is_array( $report ) || empty( $report['results'] ) || ! is_array( $report['results'] ) || ! class_exists( 'DUPCAP_Elementor_Fields' ) ) {
				return;
			}

			$norm = DUPCAP_Elementor_Fields::normalize_url_for_compare( $url );
			$path = DUPCAP_Elementor_Fields::relative_path( $url );
			if ( '' === $norm && '' === $path ) {
				return;
			}

			$changed_any = false;
			foreach ( $report['results'] as $index => $row ) {
				if ( ! is_array( $row ) || empty( $row['details'] ) || ! is_array( $row['details'] ) ) {
					continue;
				}
				$id = isset( $row['id'] ) ? (string) $row['id'] : '';
				if ( 'menu_dead_links' !== $id && 0 !== strpos( $id, 'menu_' ) ) {
					continue;
				}

				$kept    = array();
				$changed = false;
				foreach ( $row['details'] as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$detail_url = ! empty( $detail['url'] ) ? (string) $detail['url'] : '';
					$match      = false;
					if ( '' !== $detail_url && '' !== $norm ) {
						$match = DUPCAP_Elementor_Fields::normalize_url_for_compare( $detail_url ) === $norm;
					}
					if ( ! $match && '' !== $path && ! empty( $detail['message'] ) && false !== strpos( (string) $detail['message'], $path ) ) {
						$match = true;
					}
					if ( $match ) {
						$changed = true;
						continue;
					}
					$kept[] = $detail;
				}

				if ( ! $changed ) {
					continue;
				}

				$changed_any            = true;
				$row['details']         = array_values( $kept );
				if ( empty( $row['details'] ) ) {
					$row['status'] = DUPCAP_Result::PASS;
				}
				$report['results'][ $index ] = $row;
			}

			if ( $changed_any ) {
				update_option( DUPCAP_Scanner::OPTION_KEY, $report, false );
			}
		}

		/**
		 * Read translation_id / action_url post ID for a saved detail row.
		 *
		 * @param string $ignore_key Ignore key.
		 * @return int
		 */
		private function translation_id_from_saved_detail( $ignore_key ) {
			$ignore_key = DUPCAP_Ignored_Issues::sanitize_key( $ignore_key );
			$report     = DUPCAP_Scanner::get_saved_report();
			if ( '' === $ignore_key || ! is_array( $report ) || empty( $report['results'] ) || ! is_array( $report['results'] ) ) {
				return 0;
			}

			foreach ( $report['results'] as $row ) {
				if ( empty( $row['details'] ) || ! is_array( $row['details'] ) ) {
					continue;
				}
				foreach ( $row['details'] as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$key = isset( $detail['ignore_key'] ) ? DUPCAP_Ignored_Issues::sanitize_key( (string) $detail['ignore_key'] ) : '';
					if ( $key !== $ignore_key ) {
						continue;
					}
					if ( ! empty( $detail['translation_id'] ) ) {
						return (int) $detail['translation_id'];
					}
					if ( ! empty( $detail['action_url'] ) && preg_match( '/[?&]post=(\d+)/', (string) $detail['action_url'], $m ) ) {
						return (int) $m[1];
					}
				}
			}

			return 0;
		}

		/**
		 * Remove one detail row from the saved report by ignore key.
		 *
		 * @param string $ignore_key Ignore key.
		 * @return void
		 */
		private function remove_detail_by_ignore_key_from_report( $ignore_key ) {
			$ignore_key = DUPCAP_Ignored_Issues::sanitize_key( $ignore_key );
			$report     = DUPCAP_Scanner::get_saved_report();
			if ( '' === $ignore_key || ! is_array( $report ) || empty( $report['results'] ) || ! is_array( $report['results'] ) ) {
				return;
			}

			foreach ( $report['results'] as $index => $row ) {
				if ( ! is_array( $row ) || empty( $row['details'] ) || ! is_array( $row['details'] ) ) {
					continue;
				}

				$kept = array();
				$changed = false;
				foreach ( $row['details'] as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$key = isset( $detail['ignore_key'] ) ? DUPCAP_Ignored_Issues::sanitize_key( (string) $detail['ignore_key'] ) : '';
					if ( $key === $ignore_key ) {
						$changed = true;
						continue;
					}
					$kept[] = $detail;
				}

				if ( ! $changed ) {
					continue;
				}

				$row['details'] = array_values( $kept );
				if ( empty( $row['details'] ) ) {
					$row['status'] = DUPCAP_Result::PASS;
				}
				$report['results'][ $index ] = $row;
			}

			update_option( DUPCAP_Scanner::OPTION_KEY, $report, false );
		}

		/**
		 * Drop a resolved missing-translation row from the saved report.
		 *
		 * The same gap lives under different result ids depending on what it is:
		 * posts use content_missing_ / content_draft_, terms use term_missing_,
		 * and every Elementor template gets its own result per language. A rescan
		 * only knows the object and the language, so each family that can hold the
		 * row is swept.
		 *
		 * @param int    $object_id Post, term or Elementor template ID.
		 * @param string $language  Target language slug.
		 * @param string $gap_type  missing|draft.
		 * @return void
		 */
		private function remove_translation_gap_from_report( $object_id, $language, $gap_type ) {
			$object_id = (int) $object_id;
			$language  = sanitize_key( (string) $language );
			if ( $object_id < 1 || '' === $language ) {
				return;
			}

			// The Missing Elementor translation list renders from the deep-scan
			// report, not from the main one, so its finding is cleared separately.
			if ( 'draft' !== $gap_type ) {
				$this->remove_elementor_missing_translation_finding( $object_id, $language );
			}

			$report = DUPCAP_Scanner::get_saved_report();
			if ( ! is_array( $report ) || empty( $report['results'] ) || ! is_array( $report['results'] ) ) {
				return;
			}

			$prefixes = ( 'draft' === $gap_type )
				? array( 'content_draft_' )
				: array( 'content_missing_', 'term_missing_', 'elementor_' );

			$changed = false;

			foreach ( $report['results'] as $index => $row ) {
				if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['details'] ) || ! is_array( $row['details'] ) ) {
					continue;
				}

				$id      = (string) $row['id'];
				$in_famy = false;
				foreach ( $prefixes as $prefix ) {
					if ( 0 === strpos( $id, $prefix ) ) {
						$in_famy = true;
						break;
					}
				}
				if ( ! $in_famy ) {
					continue;
				}

				$kept = array();
				foreach ( $row['details'] as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}

					$detail_id   = ! empty( $detail['post_id'] )
						? (int) $detail['post_id']
						: ( ! empty( $detail['template_id'] ) ? (int) $detail['template_id'] : 0 );
					$detail_lang = isset( $detail['language'] ) ? sanitize_key( (string) $detail['language'] ) : '';

					if ( $detail_id === $object_id && $detail_lang === $language ) {
						continue;
					}

					$kept[] = $detail;
				}

				if ( count( $kept ) === count( $row['details'] ) ) {
					continue;
				}

				$changed        = true;
				$row['details'] = array_values( $kept );
				if ( empty( $row['details'] ) ) {
					$row['status'] = DUPCAP_Result::PASS;
				}
				$report['results'][ $index ] = $row;
			}

			if ( ! $changed ) {
				return;
			}

			update_option( DUPCAP_Scanner::OPTION_KEY, $report, false );
		}

		/**
		 * Drop a resolved missing_translation finding from the Elementor report.
		 *
		 * @param int    $template_id Elementor template ID.
		 * @param string $language     Target language slug.
		 * @return void
		 */
		private function remove_elementor_missing_translation_finding( $template_id, $language ) {
			if ( ! class_exists( 'DUPCAP_Elementor_Scanner' ) ) {
				return;
			}

			$report = DUPCAP_Elementor_Scanner::get_saved_report();
			if ( ! is_array( $report ) || empty( $report['findings'] ) || ! is_array( $report['findings'] ) ) {
				return;
			}

			$template_id = (int) $template_id;
			$language    = sanitize_key( (string) $language );
			$kept        = array();

			foreach ( $report['findings'] as $finding ) {
				if ( ! is_array( $finding ) ) {
					continue;
				}

				$code = isset( $finding['code'] ) ? (string) $finding['code'] : '';
				$tid  = isset( $finding['template_id'] ) ? (int) $finding['template_id'] : 0;
				$lang = isset( $finding['language'] ) ? sanitize_key( (string) $finding['language'] ) : '';

				if ( 'missing_translation' === $code && $tid === $template_id && $lang === $language ) {
					continue;
				}

				$kept[] = $finding;
			}

			if ( count( $kept ) === count( $report['findings'] ) ) {
				return;
			}

			$report['findings'] = array_values( $kept );
			$report             = DUPCAP_Elementor_Scanner::refresh_report_counts( $report );
			DUPCAP_Elementor_Scanner::save_report( $report );
		}

		/**
		 * @param string     $result_id Result id.
		 * @param string     $field     Detail field to match.
		 * @param int|string $value     Match value.
		 * @return void
		 */
		private function remove_gap_detail_from_report( $result_id, $field, $value ) {
			$report = DUPCAP_Scanner::get_saved_report();
			if ( ! is_array( $report ) || empty( $report['results'] ) || ! is_array( $report['results'] ) ) {
				return;
			}

			$result_id = (string) $result_id;
			$field     = (string) $field;

			foreach ( $report['results'] as $index => $row ) {
				if ( ! is_array( $row ) || empty( $row['id'] ) || (string) $row['id'] !== $result_id ) {
					continue;
				}

				$details = isset( $row['details'] ) && is_array( $row['details'] ) ? $row['details'] : array();
				$kept    = array();
				foreach ( $details as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					if ( isset( $detail[ $field ] ) ) {
						$current = $detail[ $field ];
						$matches = ( 'post_id' === $field )
							? ( (int) $current === (int) $value )
							: ( sanitize_key( (string) $current ) === sanitize_key( (string) $value ) );
						if ( $matches ) {
							continue;
						}
					}
					$kept[] = $detail;
				}

				$row['details'] = array_values( $kept );
				if ( empty( $row['details'] ) ) {
					$row['status'] = DUPCAP_Result::PASS;
				}
				$report['results'][ $index ] = $row;
			}

			update_option( DUPCAP_Scanner::OPTION_KEY, $report, false );
		}

		/**
		 * Replace one section's rows in a result list, keeping every other row.
		 *
		 * Fresh rows for the section take the position of the first old row of that
		 * section, so the report order stays stable.
		 *
		 * @param string $section    Section key from split_remaining_groups_by_section().
		 * @param array  $saved_rows Rows from the saved report.
		 * @param array  $fresh_rows Rows from the section scan (any section).
		 * @return array
		 */
		private function merge_section_rows( $section, array $saved_rows, array $fresh_rows ) {
			$fresh = array();
			foreach ( $fresh_rows as $row ) {
				if ( is_array( $row ) && ! empty( $row['id'] ) && $section === $this->get_result_section( $row['id'] ) ) {
					$fresh[] = $row;
				}
			}

			$merged   = array();
			$inserted = false;
			foreach ( $saved_rows as $row ) {
				$id = ( is_array( $row ) && isset( $row['id'] ) ) ? (string) $row['id'] : '';
				if ( '' !== $id && $section === $this->get_result_section( $id ) ) {
					if ( ! $inserted ) {
						$merged   = array_merge( $merged, $fresh );
						$inserted = true;
					}
					continue;
				}
				$merged[] = $row;
			}

			if ( ! $inserted ) {
				$merged = array_merge( $merged, $fresh );
			}

			return $merged;
		}

		/**
		 * Write a section scan into the saved report.
		 *
		 * @param string $key        Tab key (content|elementor|site).
		 * @param string $section    Section key the rows belong to.
		 * @param array  $fresh_rows Rows from the section scan.
		 * @return bool
		 */
		private function save_section_results( $key, $section, array $fresh_rows ) {
			$report = DUPCAP_Scanner::get_saved_report();
			if ( ! is_array( $report ) || ! isset( $report['results'] ) || ! is_array( $report['results'] ) ) {
				return false;
			}

			$report['results'] = $this->merge_section_rows( $section, $report['results'], $fresh_rows );
			$report['summary'] = DUPCAP_Scanner::summarize( $report['results'] );

			// Read by the Overview banner. A full scan writes a fresh report
			// without this key, which clears the banner.
			$partial_scans           = isset( $report['partial_scans'] ) && is_array( $report['partial_scans'] ) ? $report['partial_scans'] : array();
			$partial_scans[ $key ]   = time();
			$report['partial_scans'] = $partial_scans;

			// Not save_report(): that stamps 'time', which means "every check just ran".
			update_option( DUPCAP_Scanner::OPTION_KEY, $report, false );

			return true;
		}


}
