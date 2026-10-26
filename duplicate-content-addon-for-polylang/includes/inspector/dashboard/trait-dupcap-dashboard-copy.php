<?php


defined( 'ABSPATH' ) || exit;

/**
 * Copy methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Copy_Trait {

		/**
		 * Always-English type label for inspector tables (ignores admin user locale).
		 *
		 * @param string $type Post type or taxonomy slug.
		 * @return string
		 */
		private function english_type_label( $type ) {
			$type  = (string) $type;
			$known = array(
				'page'              => 'Page',
				'post'              => 'Post',
				'attachment'        => 'Media',
				'product'           => 'Product',
				'product_variation' => 'Product variation',
				'category'          => 'Category',
				'post_tag'          => 'Tag',
				'product_cat'       => 'Product category',
				'product_tag'       => 'Product tag',
			);

			if ( isset( $known[ $type ] ) ) {
				return $known[ $type ];
			}

			if ( '' === $type ) {
				return '';
			}

			return ucwords( str_replace( array( '-', '_' ), ' ', $type ) );
		}

		/**
		 * Always-English status label for inspector chips (ignores admin user locale).
		 *
		 * @param string $status Stored status slug when available.
		 * @param string $fallback Stored (possibly localized) label.
		 * @return string
		 */
		private function english_status_label( $status, $fallback = '' ) {
			$status = (string) $status;
			$known  = array(
				'draft'      => 'Draft',
				'pending'    => 'Pending Review',
				'private'    => 'Private',
				'future'     => 'Scheduled',
				'trash'      => 'Trash',
				'auto-draft' => 'Auto Draft',
				'inherit'    => 'Inherit',
			);

			if ( isset( $known[ $status ] ) ) {
				return $known[ $status ];
			}

			$fallback = (string) $fallback;
			$localized = array(
				'ड्राफ्ट'       => 'Draft',
				'कचरा पेटी'     => 'Trash',
				'निजी'          => 'Private',
				'लंबित'         => 'Pending Review',
				'समीक्षा लंबित'  => 'Pending Review',
				'Brouillon'     => 'Draft',
				'Corbeille'     => 'Trash',
				'Entwurf'       => 'Draft',
				'Papierkorb'    => 'Trash',
				'Borrador'      => 'Draft',
				'Papelera'      => 'Trash',
			);

			if ( isset( $localized[ $fallback ] ) ) {
				return $localized[ $fallback ];
			}

			$normalized = strtolower( trim( $fallback ) );
			foreach ( $known as $slug => $english ) {
				if ( $normalized === $slug || $normalized === strtolower( $english ) ) {
					return $english;
				}
			}

			return '' !== $fallback ? $fallback : ( '' !== $status ? ucfirst( $status ) : '' );
		}

		/**
		 * Build a user-friendly summary for content missing groups.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return string
		 */
		private function get_content_group_message( array $details ) {
			$posts = $this->count_content_pending_posts( $details );

			if ( $posts <= 0 ) {
				return __( 'Some posts or pages are not yet translated. Review the table below to complete coverage.', 'duplicate-content-addon-for-polylang' );
			}

			return $this->format_missing_summary(
				sprintf(
					/* translators: %d: number of posts and pages. */
					_n(
						'%d post or page is missing a translation.',
						'%d posts and pages are missing translations.',
						$posts,
						'duplicate-content-addon-for-polylang'
					),
					$posts
				),
				$this->count_detail_languages( $details )
			);
		}

		/**
		 * Build a user-friendly summary for unpublished translation groups.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return string
		 */
		private function get_content_draft_group_message( array $details ) {
			$posts     = $this->count_content_pending_posts( $details );
			$languages = $this->count_detail_languages( $details );

			if ( $posts <= 0 ) {
				return __( 'Some translations are linked but not published yet. Review the table below.', 'duplicate-content-addon-for-polylang' );
			}

			return $this->format_missing_summary(
				sprintf(
					/* translators: %d: number of posts and pages. */
					_n(
						'%d post or page has a translation that is not published yet.',
						'%d posts and pages have translations that are not published yet.',
						$posts,
						'duplicate-content-addon-for-polylang'
					),
					$posts
				),
				$languages
			);
		}

		/**
		 * Build a user-friendly summary for term missing groups.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return string
		 */
		private function get_term_group_message( array $details ) {
			$terms = $this->count_content_pending_posts( $details );

			if ( $terms <= 0 ) {
				return __( 'Some categories or tags are not yet translated. Review the table below to complete coverage.', 'duplicate-content-addon-for-polylang' );
			}

			return $this->format_missing_summary(
				sprintf(
					/* translators: %d: number of categories and tags. */
					_n(
						'%d category or tag is missing a translation.',
						'%d categories and tags are missing translations.',
						$terms,
						'duplicate-content-addon-for-polylang'
					),
					$terms
				),
				$this->count_detail_languages( $details )
			);
		}

		/**
		 * Summary for Elementor template translation gaps (per-language rows).
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return string
		 */
		private function get_elementor_templates_group_message( array $details ) {
			$templates = $this->count_content_pending_posts( $details );

			if ( $templates <= 0 ) {
				return __( 'Some Elementor templates are missing translations. Add a language version for each gap below.', 'duplicate-content-addon-for-polylang' );
			}

			return $this->format_missing_summary(
				sprintf(
					/* translators: %d: number of Elementor templates. */
					_n(
						'%d Elementor template is missing a translation.',
						'%d Elementor templates are missing translations.',
						$templates,
						'duplicate-content-addon-for-polylang'
					),
					$templates
				),
				$this->count_detail_languages( $details )
			);
		}

		/**
		 * Plain-language routing card summary based on remaining detail verdicts.
		 *
		 * @param array<string, mixed> $group Grouped routing issue.
		 * @return string
		 */
		private function get_routing_group_message( array $group ) {
			$details = isset( $group['details'] ) && is_array( $group['details'] ) ? $group['details'] : array();
			$failed  = 0;
			$blocked = 0;

			foreach ( $details as $detail ) {
				if ( ! is_array( $detail ) ) {
					continue;
				}
				$verdict = isset( $detail['verdict'] ) ? (string) $detail['verdict'] : '';
				if ( 'untestable' === $verdict ) {
					$blocked++;
				} elseif ( '' !== $verdict && 'ok' !== $verdict ) {
					$failed++;
				} elseif ( '' === $verdict && ! empty( $detail['http_status'] ) ) {
					$code = (int) $detail['http_status'];
					if ( ! in_array( $code, array( 200, 304 ), true ) ) {
						$failed++;
					}
				}
			}

			if ( $failed > 0 ) {
				return __( 'Some language URLs returned an error visitors would see (404, wrong language, or server error). Use Rebuild URL rules, then Open page to confirm.', 'duplicate-content-addon-for-polylang' );
			}

			if ( $blocked > 0 || 'info' === ( isset( $group['severity'] ) ? $group['severity'] : '' ) ) {
				if ( class_exists( 'DUPCAP_Routing_Scanner' ) && DUPCAP_Routing_Scanner::is_likely_local_site() ) {
					return __( 'Local site note: automatic URL testing often times out here. That is usually not a broken page. Open each language in your browser — if it loads, click Ignore. Re-test after you go live.', 'duplicate-content-addon-for-polylang' );
				}

				return __( 'Automatic URL testing could not finish from the server. Open each page in your browser — if it loads, click Ignore. If you see a 404, rebuild permalinks, then Test URLs again.', 'duplicate-content-addon-for-polylang' );
			}

			$fallback = DUPCAP_Issue_Copy::group_message( 'routing_health' );
			return '' !== $fallback ? $fallback : ( isset( $group['message'] ) ? (string) $group['message'] : '' );
		}

		/**
		 * Get group display name for details summaries.
		 *
		 * @param array<string, mixed> $group Group.
		 * @return string
		 */
		private function get_group_display_name( array $group ) {
			$names = array(
				'untranslated_types'    => __( 'Untranslated Content Types', 'duplicate-content-addon-for-polylang' ),
				'homepage_translations' => __( 'Homepage', 'duplicate-content-addon-for-polylang' ),
				'content_nolang'         => __( 'Content without a language', 'duplicate-content-addon-for-polylang' ),
				'content_missing'        => __( 'Missing Translations', 'duplicate-content-addon-for-polylang' ),
				'content_identical'      => __( 'Identical Content', 'duplicate-content-addon-for-polylang' ),
				'content_acf'            => __( 'ACF & Custom Fields', 'duplicate-content-addon-for-polylang' ),
				'content_placeholder'    => __( 'Shortcode & Placeholder Mismatch', 'duplicate-content-addon-for-polylang' ),
				'content_source_updated' => __( 'Default Language Updated', 'duplicate-content-addon-for-polylang' ),
				'content_links'          => __( 'Content Links', 'duplicate-content-addon-for-polylang' ),
				'content_draft'          => __( 'Unpublished Page/Post', 'duplicate-content-addon-for-polylang' ),
				'term_missing'          => __( 'Categories & Tags', 'duplicate-content-addon-for-polylang' ),
				'menu_assignments'      => __( 'Navigation Menus', 'duplicate-content-addon-for-polylang' ),
				'menu_dead_links'       => __( 'Menu Links', 'duplicate-content-addon-for-polylang' ),
				'navigation'            => __( 'Navigation', 'duplicate-content-addon-for-polylang' ),
				'lang_switcher'         => __( 'Language Switcher', 'duplicate-content-addon-for-polylang' ),
				'elementor_templates'   => __( 'Elementor Templates', 'duplicate-content-addon-for-polylang' ),
				'strings_missing'       => __( 'Text String Translation', 'duplicate-content-addon-for-polylang' ),
				'routing_health'        => __( 'Language URLs', 'duplicate-content-addon-for-polylang' ),
				'url_configuration'     => __( 'URL Settings', 'duplicate-content-addon-for-polylang' ),
				'translated_permalinks' => __( 'Translated URL Slugs', 'duplicate-content-addon-for-polylang' ),
			);

			return isset( $names[ $group['dedupe_key'] ] ) ? $names[ $group['dedupe_key'] ] : $group['group'];
		}

		/**
		 * Get why-this-matters copy.
		 *
		 * @param array<string, mixed> $group Group.
		 * @return string
		 */
		private function get_fix_reason( array $group ) {
			$reason = DUPCAP_Issue_Copy::group_fix_reason( $group['dedupe_key'] );
			return '' !== $reason ? $reason : $group['message'];
		}

		/**
		 * Get severity label.
		 *
		 * @param string $severity Severity.
		 * @return string
		 */
		private function get_severity_label( $severity ) {
			$labels = array(
				'crit' => __( 'Critical', 'duplicate-content-addon-for-polylang' ),
				'warn' => __( 'Warning', 'duplicate-content-addon-for-polylang' ),
				'info' => __( 'Review', 'duplicate-content-addon-for-polylang' ),
				'pass' => __( 'Passed', 'duplicate-content-addon-for-polylang' ),
			);

			return isset( $labels[ $severity ] ) ? $labels[ $severity ] : $severity;
		}


}
