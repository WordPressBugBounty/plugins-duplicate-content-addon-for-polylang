<?php

defined( 'ABSPATH' ) || exit;

/**
 * Rescan Issue methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Rescan_Issue_Trait {

		/**
		 * Rescan a single Elementor issue and report whether it's still present.
		 *
		 * @return void
		 */
		public function handle_rescan_issue() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to run this scan.', 'duplicate-content-addon-for-polylang' ) ),
					403
				);
			}

			$this->verify_ajax_nonce();
			// Nonce verified above.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$template_id   = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
			$template_type = isset( $_POST['template_type'] ) ? sanitize_key( wp_unslash( $_POST['template_type'] ) ) : '';
			$ignore_key    = isset( $_POST['ignore_key'] ) ? DUPCAP_Ignored_Issues::sanitize_key( sanitize_text_field( wp_unslash( $_POST['ignore_key'] ) ) ) : '';
			$language      = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
			// phpcs:enable

			$outcome = $this->recheck_elementor_issue( $template_id, $template_type, $ignore_key, $language );

			if ( is_wp_error( $outcome ) ) {
				$this->send_wp_error( $outcome );
			}

			// A library gap that is still present leaves the dashboard as it is.
			$is_library = ! empty( $outcome['library'] );
			unset( $outcome['library'] );

			if ( $is_library && empty( $outcome['resolved'] ) ) {
				wp_send_json_success( $outcome );
			}

			$this->send_dashboard_fragments( $outcome );
		}

		/**
		 * Re-check one Elementor issue and update the saved Elementor report.
		 *
		 * A library-wide gap (no template ID) is routed to
		 * recheck_elementor_library_issue(); the result then carries library: true.
		 *
		 * @param int    $template_id   Elementor document ID.
		 * @param string $template_type Template type from the issue row.
		 * @param string $ignore_key    Ignore key of the finding.
		 * @param string $language      Target language slug, used for library-wide gaps.
		 * @return array{resolved: bool, message: string, library?: bool}|WP_Error
		 */
		public function recheck_elementor_issue( $template_id, $template_type = '', $ignore_key = '', $language = '' ) {
			$template_id   = (int) $template_id;
			$template_type = sanitize_key( (string) $template_type );
			$ignore_key    = DUPCAP_Ignored_Issues::sanitize_key( (string) $ignore_key );

			if ( $template_id <= 0 && ( 'elementor_not_translated' === $template_type || 'elementor_library' === $template_type ) ) {
				$outcome = $this->recheck_elementor_library_issue( 'elementor_not_translated', $language );
				if ( is_array( $outcome ) ) {
					$outcome['library'] = true;
				}
				return $outcome;
			}

			if ( $template_id <= 0 || '' === $ignore_key ) {
				return new WP_Error(
					'dupcap_issue_unidentified',
					__( 'This issue could not be identified.', 'duplicate-content-addon-for-polylang' ),
					array( 'status' => 400 )
				);
			}

			if ( ! class_exists( 'DUPCAP_Elementor_Scanner' ) || ! DUPCAP_Elementor_Scanner::is_elementor_available() ) {
				return new WP_Error(
					'dupcap_elementor_unavailable',
					__( 'Elementor is not available.', 'duplicate-content-addon-for-polylang' ),
					array( 'status' => 400 )
				);
			}

			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Elementor rescan may exceed default PHP timeout.
				@set_time_limit( 60 );
			}

			// Fresh request after Publish can still see stale post/translation caches.
			clean_post_cache( $template_id );
			if ( function_exists( 'pll_get_post_translations' ) ) {
				$linked = pll_get_post_translations( $template_id );
				if ( is_array( $linked ) ) {
					foreach ( $linked as $linked_id ) {
						clean_post_cache( (int) $linked_id );
					}
				}
			}

			try {
				$fresh_findings = ( new DUPCAP_Elementor_Scanner() )->rescan_document( $template_id );
			} catch ( \Throwable $e ) {
				if ( function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Error logging is allowed.
					error_log( 'DUPCAP rescan_issue failed for template ' . $template_id . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
				}
				return new WP_Error(
					'dupcap_rescan_failed',
					__( 'The rescan could not finish for this document. Please try a full site scan instead.', 'duplicate-content-addon-for-polylang' ),
					array( 'status' => 500 )
				);
			}

			$still_present = false;
			$site_codes    = DUPCAP_Elementor_Scanner::site_level_finding_codes();
			foreach ( $fresh_findings as $finding ) {
				if ( $ignore_key === DUPCAP_Elementor_Scanner::get_finding_ignore_key( $finding ) ) {
					$still_present = true;
					break;
				}

				// Site-level warnings may have been rendered with an older ignore key
				// that included an example template ID; match by finding code instead.
				$code = isset( $finding['code'] ) ? (string) $finding['code'] : '';
				if (
					'' !== $code
					&& in_array( $code, $site_codes, true )
					&& false !== strpos( $ignore_key, 'el:' . $code . ':' )
				) {
					$still_present = true;
					break;
				}
			}

			// If a translation was just published, missing_translation is resolved even
			// when title-only drafts have not been scanned for content issues yet.
			if ( $still_present && false !== strpos( $ignore_key, 'el:missing_translation:' ) && function_exists( 'pll_get_post' ) ) {
				$parts = explode( ':', $ignore_key );
				// el : missing_translation : {template_id} : {lang} : ...
				$lang = isset( $parts[3] ) ? sanitize_key( $parts[3] ) : '';
				if ( '' !== $lang && (int) pll_get_post( $template_id, $lang ) > 0 ) {
					$still_present = false;
					$fresh_findings = array_values(
						array_filter(
							$fresh_findings,
							function ( $finding ) use ( $ignore_key ) {
								return $ignore_key !== DUPCAP_Elementor_Scanner::get_finding_ignore_key( $finding );
							}
						)
					);
				}
			}

			$affected_ids = array( $template_id );
			foreach ( $fresh_findings as $finding ) {
				if ( ! empty( $finding['template_id'] ) ) {
					$affected_ids[] = (int) $finding['template_id'];
				}
			}
			$affected_ids = array_values( array_unique( $affected_ids ) );

			$saved_report = DUPCAP_Elementor_Scanner::get_saved_report();
			if ( is_array( $saved_report ) ) {
				$saved_report = DUPCAP_Elementor_Scanner::replace_document_findings_in_report( $saved_report, $affected_ids, $fresh_findings );
				DUPCAP_Elementor_Scanner::save_report( $saved_report );
			}

			return self::recheck_outcome( ! $still_present );
		}

		/**
		 * Result of a single-issue recheck.
		 *
		 * @param bool $resolved Whether the issue is gone.
		 * @return array{resolved: bool, message: string}
		 */
		private static function recheck_outcome( $resolved ) {
			return array(
				'resolved' => (bool) $resolved,
				'message'  => $resolved
					? __( 'This issue is now resolved.', 'duplicate-content-addon-for-polylang' )
					: __( 'This issue is still present.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		/**
		 * Rescan one content or homepage gap the user tried to fix.
		 *
		 * @return void
		 */
		public function handle_rescan_content_issue() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to run this scan.', 'duplicate-content-addon-for-polylang' ) ),
					403
				);
			}

			$this->verify_ajax_nonce();

			// Nonce verified above.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$outcome = $this->recheck_content_issue(
				array(
					'kind'           => isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'content',
					'post_id'        => isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0,
					'translation_id' => isset( $_POST['translation_id'] ) ? (int) $_POST['translation_id'] : 0,
					'language'       => isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '',
					'gap_type'       => isset( $_POST['gap_type'] ) ? sanitize_key( wp_unslash( $_POST['gap_type'] ) ) : 'missing',
					'field'          => isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '',
					'ignore_key'     => isset( $_POST['ignore_key'] ) ? sanitize_text_field( wp_unslash( $_POST['ignore_key'] ) ) : '',
					'url'            => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
				)
			);
			// phpcs:enable

			$this->send_recheck_response( $outcome );
		}

		/**
		 * Re-check one content, menu or homepage issue and update the saved report.
		 *
		 * @param array<string, mixed> $args {
		 *     @type string $kind           content|homepage|identical|link|menu_dead.
		 *     @type int    $post_id        Source post (content gaps).
		 *     @type int    $translation_id Translation hint (identical / link rows).
		 *     @type string $language       Target language slug (content and homepage gaps).
		 *     @type string $gap_type       missing|draft|source_updated (content), missing|empty_layout (homepage).
		 *     @type string $field          Field hint (identical rows).
		 *     @type string $ignore_key     Row ignore key (identical, link, menu_dead).
		 *     @type string $url            Dead link URL (menu_dead).
		 * }
		 * @return array{resolved: bool, message: string}|WP_Error
		 */
		public function recheck_content_issue( array $args ) {
			$kind           = isset( $args['kind'] ) ? sanitize_key( (string) $args['kind'] ) : 'content';
			$post_id        = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
			$translation_id = isset( $args['translation_id'] ) ? (int) $args['translation_id'] : 0;
			$language       = isset( $args['language'] ) ? sanitize_key( (string) $args['language'] ) : '';
			$gap_type       = ! empty( $args['gap_type'] ) ? sanitize_key( (string) $args['gap_type'] ) : 'missing';
			$field          = isset( $args['field'] ) ? sanitize_key( (string) $args['field'] ) : '';
			$ignore_key     = isset( $args['ignore_key'] ) ? DUPCAP_Ignored_Issues::sanitize_key( (string) $args['ignore_key'] ) : '';
			$url            = isset( $args['url'] ) ? esc_url_raw( (string) $args['url'] ) : '';

			$unidentified = new WP_Error(
				'dupcap_issue_unidentified',
				__( 'This issue could not be identified.', 'duplicate-content-addon-for-polylang' ),
				array( 'status' => 400 )
			);

			if ( in_array( $kind, array( 'identical', 'link', 'menu_dead' ), true ) ) {
				if ( '' === $ignore_key ) {
					return $unidentified;
				}

				if ( 'menu_dead' === $kind ) {
					$still_present = $this->menu_dead_link_still_present( $ignore_key, $url );
					if ( ! $still_present ) {
						$this->remove_detail_by_ignore_key_from_report( $ignore_key );
						if ( '' !== $url ) {
							$this->remove_menu_dead_detail_by_url_from_report( $url );
						}
					}
				} else {
					// A merged row (one page, several languages) sends all its keys, comma separated.
					$keys          = array_filter( explode( ',', $ignore_key ) );
					$multi         = count( $keys ) > 1;
					$still_present = true;
					$any_fixed     = false;
					foreach ( $keys as $one_key ) {
						if ( $this->content_detail_still_present( $one_key, $multi ? 0 : $translation_id, $field ) ) {
							continue;
						}
						$any_fixed = true;
						$this->remove_detail_by_ignore_key_from_report( $one_key );
					}
					// Redraw as soon as one language is fixed; the row keeps the ones still broken.
					$still_present = ! $any_fixed;
				}

				return self::recheck_outcome( ! $still_present );
			}

			if ( '' === $language || ! in_array( $kind, array( 'content', 'homepage' ), true ) ) {
				return $unidentified;
			}

			if ( 'homepage' === $kind ) {
				$still_present = $this->homepage_gap_still_present( $language, $gap_type );
				if ( ! $still_present ) {
					$this->remove_gap_detail_from_report( 'lang_homepage', 'language', $language );
				}
			} else {
				if ( $post_id <= 0 ) {
					return $unidentified;
				}
				if ( 'source_updated' === $gap_type ) {
					$still_present = $this->source_updated_detail_still_present( $post_id, $language );
					if ( ! $still_present ) {
						$this->remove_detail_by_ignore_key_from_report(
							DUPCAP_Ignored_Issues::content_source_updated_key( $post_id, $language )
						);
					}
				} else {
					$still_present = $this->content_gap_still_present( $post_id, $language, $gap_type );
					if ( ! $still_present ) {
						$this->remove_translation_gap_from_report( $post_id, $language, $gap_type );
					}
				}
			}

			return self::recheck_outcome( ! $still_present );
		}

		/**
		 * AJAX answer for a content or library recheck. Never returns.
		 *
		 * A resolved issue re-renders the dashboard; one still present leaves it
		 * as it is.
		 *
		 * @param array<string, mixed>|WP_Error $outcome Recheck result.
		 * @return void
		 */
		private function send_recheck_response( $outcome ) {
			if ( is_wp_error( $outcome ) ) {
				$this->send_wp_error( $outcome );
			}

			if ( empty( $outcome['resolved'] ) ) {
				wp_send_json_success( $outcome );
			}

			$this->send_dashboard_fragments( $outcome );
		}

		/**
		 * Rescan one Elementor library type+language translation gap.
		 *
		 * @return void
		 */
		public function handle_rescan_elementor_library_issue() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to run this scan.', 'duplicate-content-addon-for-polylang' ) ),
					403
				);
			}

			$this->verify_ajax_nonce();

			// Nonce verified above.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$template_type = isset( $_POST['template_type'] ) ? sanitize_key( wp_unslash( $_POST['template_type'] ) ) : '';
			$language      = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
			// phpcs:enable

			$this->send_recheck_response( $this->recheck_elementor_library_issue( $template_type, $language ) );
		}

		/**
		 * Re-check one Elementor library type + language translation gap.
		 *
		 * @param string $template_type Elementor template type, or elementor_not_translated.
		 * @param string $language      Target language slug.
		 * @return array{resolved: bool, message: string}|WP_Error
		 */
		public function recheck_elementor_library_issue( $template_type, $language = '' ) {
			$template_type = sanitize_key( (string) $template_type );
			$language      = sanitize_key( (string) $language );

			if ( '' === $template_type ) {
				return new WP_Error(
					'dupcap_issue_unidentified',
					__( 'This issue could not be identified.', 'duplicate-content-addon-for-polylang' ),
					array( 'status' => 400 )
				);
			}

			$still_present = $this->elementor_library_gap_still_present( $template_type, $language );
			if ( ! $still_present ) {
				$this->remove_elementor_library_result_from_report( $template_type, $language );
				$this->refresh_untranslated_types_in_saved_report();
			}

			return self::recheck_outcome( ! $still_present );
		}

		/**
		 * Whether default-language Elementor templates of a type still miss a language.
		 *
		 * @param string $template_type Elementor template type slug.
		 * @param string $language      Target language slug.
		 * @return bool
		 */
		private function elementor_library_gap_still_present( $template_type, $language ) {
			if ( ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_default_language' ) ) {
				return true;
			}

			$is_elementor_translatable = function_exists( 'pll_is_translated_post_type' )
				? pll_is_translated_post_type( 'elementor_library' )
				: false;

			if ( 'elementor_not_translated' === $template_type || 'elementor_library' === $template_type ) {
				return ! $is_elementor_translatable;
			}

			if ( ! $is_elementor_translatable ) {
				return true;
			}

			$default = (string) pll_default_language();
			if ( '' === $default || $language === $default || '' === $language ) {
				return false;
			}

			$ids = get_posts(
				array(
					'post_type'      => 'elementor_library',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => -1, // Every template of this type, IDs only.
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'lang'           => $default,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One template type, user-triggered rescan of a single issue.
					'meta_query'     => array(
						array(
							'key'   => '_elementor_template_type',
							'value' => $template_type,
						),
					),
				)
			);

			if ( empty( $ids ) ) {
				return false;
			}

			foreach ( $ids as $id ) {
				if ( class_exists( 'DUPCAP_Elementor_Document' )
					&& DUPCAP_Elementor_Document::is_excluded_library_document( (int) $id ) ) {
					continue;
				}
				$translations = pll_get_post_translations( (int) $id );
				if ( empty( $translations[ $language ] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * @param int    $post_id  Source post ID or term ID (terms reuse this field).
		 * @param string $language Target language slug.
		 * @param string $gap_type missing|draft.
		 * @return bool
		 */
		private function content_gap_still_present( $post_id, $language, $gap_type ) {
			$post_id  = (int) $post_id;
			$language = sanitize_key( (string) $language );
			if ( $post_id < 1 || '' === $language ) {
				return true;
			}

			// Term rows store term_id in post_id. Posts and terms can share numeric IDs,
			// so prefer a real post when both exist.
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				if ( ! function_exists( 'pll_get_post' ) ) {
					return true;
				}
				$translation_id = (int) pll_get_post( $post_id, $language );
				if ( 'draft' === $gap_type ) {
					return $translation_id > 0 && 'publish' !== get_post_status( $translation_id );
				}
				return $translation_id <= 0;
			}

			$term = get_term( $post_id );
			if ( $term instanceof WP_Term && ! is_wp_error( $term ) ) {
				if ( ! function_exists( 'pll_get_term' ) ) {
					return true;
				}
				$translation_id = (int) pll_get_term( $post_id, $language );
				return $translation_id <= 0;
			}

			return true;
		}

		/**
		 * Whether a Case B (default newer) detail is still present.
		 *
		 * @param int    $post_id  Default-language post ID.
		 * @param string $language Translation language slug.
		 * @return bool
		 */
		private function source_updated_detail_still_present( $post_id, $language ) {
			if ( ! class_exists( 'DUPCAP_Check_Content' ) ) {
				return true;
			}
			$checker = new DUPCAP_Check_Content();
			return $checker->source_updated_still_present( (int) $post_id, sanitize_key( (string) $language ) );
		}

		/**
		 * Whether a classic menu dead-link detail is still present.
		 *
		 * @param string $ignore_key Ignore key.
		 * @param string $url_hint   Optional URL from the button.
		 * @return bool
		 */
		private function menu_dead_link_still_present( $ignore_key, $url_hint = '' ) {
			$ignore_key = DUPCAP_Ignored_Issues::sanitize_key( $ignore_key );
			$url        = esc_url_raw( (string) $url_hint );

			if ( '' === $url && '' !== $ignore_key ) {
				$report = DUPCAP_Scanner::get_saved_report();
				if ( is_array( $report ) && ! empty( $report['results'] ) && is_array( $report['results'] ) ) {
					foreach ( $report['results'] as $row ) {
						if ( ! is_array( $row ) || empty( $row['details'] ) || ! is_array( $row['details'] ) ) {
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
							if ( ! empty( $detail['url'] ) ) {
								$url = esc_url_raw( (string) $detail['url'] );
							}
							break 2;
						}
					}
				}
			}

			if ( '' === $url || ! class_exists( 'DUPCAP_Elementor_Fields' ) ) {
				return true;
			}

			$default = '';
			if ( function_exists( 'pll_default_language' ) ) {
				$default = (string) pll_default_language();
			}

			return ! DUPCAP_Elementor_Fields::url_has_published_target( $url, $default );
		}

		/**
		 * Whether a content identical / link detail (by ignore key) is still present.
		 *
		 * @param string $ignore_key          Ignore key.
		 * @param int    $translation_id_hint Translation post ID from the UI when available.
		 * @param string $field_hint          title|content from the UI when available.
		 * @return bool
		 */
		private function content_detail_still_present( $ignore_key, $translation_id_hint = 0, $field_hint = '' ) {
			$ignore_key = DUPCAP_Ignored_Issues::sanitize_key( $ignore_key );
			if ( '' === $ignore_key || ! function_exists( 'pll_get_post' ) ) {
				return true;
			}

			// content:identical:{post_id}:{lang}:{field}:{md5}
			// content:acf:{post_id}:{lang}:{field}:{md5}
			// content:placeholder:{post_id}:{lang}:placeholder:{md5}
			// content:link:{post_id}:{lang}:{code}:{md5}
			if ( ! preg_match( '/^content:(identical|acf|placeholder|link):(\d+):([a-z0-9_-]+):(.+)$/', $ignore_key, $matches ) ) {
				return true;
			}

			$type    = $matches[1];
			$post_id = (int) $matches[2];
			$lang    = sanitize_key( $matches[3] );
			$rest    = $matches[4];
			if ( $post_id <= 0 || '' === $lang ) {
				return true;
			}

			$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

			$translation_id = (int) $translation_id_hint;
			if ( $translation_id <= 0 ) {
				$translation_id = $this->translation_id_from_saved_detail( $ignore_key );
			}
			if ( $translation_id <= 0 ) {
				$translation_id = (int) pll_get_post( $post_id, $lang );
			}

			if ( ! class_exists( 'DUPCAP_Check_Content' ) ) {
				return true;
			}

			$checker = new DUPCAP_Check_Content();

			if ( 'placeholder' === $type ) {
				// Same post on both sides cannot be a real translation gap.
				if ( $translation_id <= 0 || $translation_id === $post_id ) {
					return false;
				}
				return $checker->placeholder_mismatch_still_present( $post_id, $translation_id );
			}

			if ( 'identical' === $type || 'acf' === $type ) {
				// Same post on both sides cannot be a real translation gap.
				if ( $translation_id <= 0 || $translation_id === $post_id ) {
					return false;
				}

				// The key is authoritative: it is what the reported row was built
				// from. The UI hint is only a fallback for a key without a field.
				$field          = sanitize_key( strtok( $rest, ':' ) );
				$is_valid_field = in_array( $field, array( 'title', 'content' ), true )
					|| 0 === strpos( $field, 'acf_' )
					|| 0 === strpos( $field, 'meta_' )
					|| 0 === strpos( $field, 'el_' );
				if ( ! $is_valid_field ) {
					$field = sanitize_key( (string) $field_hint );
				}
				return $checker->identical_field_still_present( $post_id, $translation_id, $field );
			}

			if ( 'link' === $type ) {
				$content_post_id = $translation_id;
				// Default-language pages store the page that contains the link as both IDs.
				if ( $content_post_id <= 0 || ( $content_post_id === $post_id && $lang === $default ) ) {
					$content_post_id = $post_id;
				}
				if ( $content_post_id <= 0 ) {
					return false;
				}

				if ( $checker->is_elementor_built_for_rescan( $content_post_id ) ) {
					return false;
				}

				global $wpdb;
				clean_post_cache( $content_post_id );
				wp_cache_delete( $content_post_id, 'posts' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh post_content read after cache bust for rescan comparison.
				$content = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_content FROM {$wpdb->posts} WHERE ID = %d",
						$content_post_id
					)
				);
				$links = $checker->extract_content_links_for_rescan( (string) $content );
				foreach ( $links as $link_data ) {
					$url   = isset( $link_data['url'] ) ? (string) $link_data['url'] : '';
					$issue = $checker->classify_content_link_issue_for_rescan( $url, $lang );
					if ( empty( $issue ) || empty( $issue['code'] ) ) {
						continue;
					}
					$key = DUPCAP_Ignored_Issues::content_link_key( $post_id, $lang, $issue['code'], $url );
					if ( $key === $ignore_key ) {
						return true;
					}
				}
				return false;
			}

			return true;
		}

		/**
		 * @param string $language Target language slug.
		 * @param string $gap_type missing|empty_layout.
		 * @return bool
		 */
		private function homepage_gap_still_present( $language, $gap_type ) {
			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id <= 0 || ! function_exists( 'pll_get_post' ) ) {
				return true;
			}

			$translation_id = (int) pll_get_post( $front_id, $language );
			if ( $translation_id <= 0 ) {
				return true;
			}

			if ( 'empty_layout' !== $gap_type || ! class_exists( 'DUPCAP_Elementor_Document' ) ) {
				return false;
			}

			$meta = DUPCAP_Elementor_Document::META_EDIT_MODE;
			if ( 'builder' !== get_post_meta( $front_id, $meta, true ) ) {
				return false;
			}

			return 'builder' !== get_post_meta( $translation_id, $meta, true );
		}


}
