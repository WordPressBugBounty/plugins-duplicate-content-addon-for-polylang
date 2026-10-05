<?php

defined( 'ABSPATH' ) || exit;

/**
 * Elementor methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Elementor_Trait {

		/**
		 * Get curated Elementor insights for the dashboard.
		 *
		 * @return array
		 */
		private function get_elementor_insights() {
			return DUPCAP_Elementor_Insights::get_dashboard_insights();
		}

		/**
		 * Elementor issue counts folded into the Translation Health badges.
		 *
		 * Critical Elementor findings count toward the critical badge and major
		 * findings toward the warnings badge, matching the Elementor insights display.
		 *
		 * @return array{crit: int, warn: int}
		 */
		private function get_elementor_badge_counts() {
			$counts = array(
				'crit' => 0,
				'warn' => 0,
			);

			$insights = $this->get_elementor_insights();
			if ( ! empty( $insights['badge_counts'] ) && is_array( $insights['badge_counts'] ) ) {
				$counts['crit'] = isset( $insights['badge_counts']['crit'] ) ? (int) $insights['badge_counts']['crit'] : 0;
				$counts['warn'] = isset( $insights['badge_counts']['warn'] ) ? (int) $insights['badge_counts']['warn'] : 0;
			}

			return $counts;
		}

		/**
		 * Render Elementor translation insights section.
		 *
		 * @param array<int, array<string, mixed>> $template_groups Elementor issue groups (templates / library).
		 * @param array<int, array<string, mixed>> $passed          Passed Elementor checks.
		 * @param array<string, int>               $counts          Severity pill counts.
		 * @return void
		 */
		private function render_elementor_insights_section( array $template_groups = array(), array $passed = array(), array $counts = array() ) {
			$insights = $this->get_elementor_insights();
			$ignored  = $this->get_ignored_items_for_section( 'elementor' );
			$counts   = wp_parse_args(
				$counts,
				array(
					'crit'    => 0,
					'warn'    => 0,
					'pass'    => 0,
					'ignored' => 0,
				)
			);
			$counts['ignored'] = count( $ignored );

			$has_insights = ! empty( $insights['visible'] );
			$has_groups   = ! empty( $template_groups );

			if ( ! $has_insights && ! $has_groups && empty( $passed ) && empty( $ignored ) ) {
				return;
			}

			$summary        = isset( $insights['summary'] ) && is_array( $insights['summary'] ) ? $insights['summary'] : array();
			$health_score   = isset( $insights['health_score'] ) ? (int) $insights['health_score'] : 100;
			$templates      = isset( $summary['templates_scanned'] ) ? (int) $summary['templates_scanned'] : 0;
			$widgets        = isset( $summary['widgets_scanned'] ) ? (int) $summary['widgets_scanned'] : 0;
			$status         = isset( $insights['status'] ) ? $insights['status'] : 'pass';
			$buckets        = isset( $insights['buckets'] ) && is_array( $insights['buckets'] ) ? $insights['buckets'] : array();
			$document_types = isset( $insights['document_types'] ) && is_array( $insights['document_types'] ) ? $insights['document_types'] : array();
			$buckets        = $this->remove_duplicate_elementor_missing_translations( $buckets, $template_groups );

			// Polylang can only translate templates when "My Templates" is
			// translatable. Without it the type chips would all read 0, so the
			// tab explains that instead of showing an empty filter row.
			$templates_translatable = ! function_exists( 'pll_is_translated_post_type' ) || (bool) pll_is_translated_post_type( 'elementor_library' );
			$type_issue_counts      = $this->count_elementor_issues_by_type( $template_groups, $buckets );
			$has_type_chips         = array_sum( array_map( 'intval', $type_issue_counts ) ) > 0;
			$has_severity_pills     = array_sum( array_map( 'intval', $counts ) ) > 0;

			?>
			<section class="dupcap-elementor-insights" aria-labelledby="dupcap-elementor-insights-title">
				<header class="dupcap-elementor-insights-header">
					<div class="dupcap-elementor-insights-heading">
						<div>
							<h2 id="dupcap-elementor-insights-title"><?php esc_html_e( 'Elementor Insights', 'duplicate-content-addon-for-polylang' ); ?></h2>
							<?php if ( $templates > 0 || $widgets > 0 ) : ?>
								<ul class="dupcap-elementor-stats">
									<li>
										<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
										<?php
										printf(
											/* translators: %d: documents scanned */
											esc_html( _n( 'Scanned %d document', 'Scanned %d documents', $templates, 'duplicate-content-addon-for-polylang' ) ),
											absint( $templates )
										);
										?>
									</li>
									<li>
										<span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
										<?php
										printf(
											/* translators: %d: widgets scanned */
											esc_html( _n( '%d widget', '%d widgets', $widgets, 'duplicate-content-addon-for-polylang' ) ),
											absint( $widgets )
										);
										?>
									</li>
									<li class="dupcap-elementor-stat-health">
										<span class="dashicons dashicons-heart" aria-hidden="true"></span>
										<?php
										printf(
											/* translators: %d: health score */
											esc_html__( 'Health %d%%', 'duplicate-content-addon-for-polylang' ),
											absint( $health_score )
										);
										?>
									</li>
								</ul>
							<?php else : ?>
								<p class="dupcap-elementor-insights-sub"><?php esc_html_e( 'Deep Elementor widget and JSON checks across Polylang translations.', 'duplicate-content-addon-for-polylang' ); ?></p>
							<?php endif; ?>
						</div>
						<?php $this->render_section_scan_bar( 'elementor', DUPCAP_Scanner::get_saved_report() ); ?>
					</div>
				</header>

				<?php if ( ! $templates_translatable ) : ?>
					<?php $this->render_elementor_untranslatable_notice(); ?>
				<?php endif; ?>

				<?php if ( $has_type_chips || $has_severity_pills ) : ?>
					<div class="dupcap-section-filters">
						<?php $this->render_severity_pills( $counts ); ?>
						<?php $this->render_elementor_document_types( $document_types, $template_groups, $buckets ); ?>
					</div>
					<?php $this->render_critical_pointer( isset( $counts['crit_elsewhere'] ) ? (int) $counts['crit_elsewhere'] : 0 ); ?>
					<?php $this->render_filter_empty(); ?>
				<?php endif; ?>

				<?php if ( $has_groups ) : ?>
					<div class="dupcap-card-stack dupcap-elementor-template-groups">
						<?php
						foreach ( $template_groups as $group ) {
							$this->render_issue_group( $group );
						}
						?>
					</div>
				<?php endif; ?>

				<?php if ( $has_insights ) : ?>
					<?php if ( 'pass' === $status && ! $has_groups ) : ?>
						<div class="dupcap-elementor-pass">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<p><?php echo esc_html( isset( $insights['message'] ) ? $insights['message'] : '' ); ?></p>
						</div>
					<?php elseif ( 'pass' !== $status ) : ?>
						<div class="dupcap-elementor-buckets">
							<?php foreach ( $buckets as $bucket ) : ?>
								<?php $this->render_elementor_insight_bucket( $bucket ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
				<?php $this->render_passed_checks( $passed ); ?>
				<?php $this->render_ignored_issues( $ignored, 'elementor' ); ?>
			</section>
			<?php
		}

		/**
		 * Count warning and critical rows for each Elementor document type.
		 *
		 * Chip numbers must match what the type filter shows, not how many
		 * documents of that type were scanned.
		 *
		 * @param array<int, array<string, mixed>> $template_groups Template issue groups.
		 * @param array<int, array<string, mixed>> $buckets         Insight buckets.
		 * @return array<string, int>
		 */
		private function count_elementor_issues_by_type( array $template_groups, array $buckets ) {
			$counts = array();

			$add = function ( $type ) use ( &$counts ) {
				$type = sanitize_key( str_replace( '_', '-', (string) $type ) );
				if ( '' === $type ) {
					return;
				}
				if ( ! isset( $counts[ $type ] ) ) {
					$counts[ $type ] = 0;
				}
				$counts[ $type ]++;
			};

			foreach ( $template_groups as $group ) {
				if ( empty( $group['details'] ) || ! is_array( $group['details'] ) ) {
					continue;
				}
				$seen = array();
				foreach ( $group['details'] as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$type = ! empty( $detail['template_type'] ) ? $detail['template_type'] : '';
					if ( '' === $type && ! empty( $detail['document_type'] ) ) {
						$type = $detail['document_type'];
					}
					$id = ! empty( $detail['template_id'] ) ? (int) $detail['template_id'] : ( ! empty( $detail['post_id'] ) ? (int) $detail['post_id'] : 0 );
					$key = $type . ':' . $id;
					if ( $id > 0 && isset( $seen[ $key ] ) ) {
						continue;
					}
					if ( $id > 0 ) {
						$seen[ $key ] = true;
					}
					$add( $type );
				}
			}

			foreach ( $buckets as $bucket ) {
				if ( empty( $bucket['examples'] ) || ! is_array( $bucket['examples'] ) ) {
					continue;
				}
				$seen = array();
				foreach ( $bucket['examples'] as $example ) {
					if ( ! is_array( $example ) ) {
						continue;
					}
					$type = ! empty( $example['type'] ) ? $example['type'] : '';
					if ( '' === $type && ! empty( $example['template_type'] ) ) {
						$type = $example['template_type'];
					}
					$id = ! empty( $example['template_id'] ) ? (int) $example['template_id'] : ( ! empty( $example['post_id'] ) ? (int) $example['post_id'] : 0 );
					$key = $type . ':' . $id;
					if ( $id > 0 && isset( $seen[ $key ] ) ) {
						continue;
					}
					if ( $id > 0 ) {
						$seen[ $key ] = true;
					}
					$add( $type );
				}
			}

			return $counts;
		}

		/**
		 * Notice for sites where "My Templates" is not translatable in Polylang.
		 *
		 * @return void
		 */
		private function render_elementor_untranslatable_notice() {
			?>
			<div class="dupcap-elementor-untranslatable">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<div class="dupcap-elementor-untranslatable-copy">
					<p><strong><?php esc_html_e( 'Elementor templates are not set as translatable.', 'duplicate-content-addon-for-polylang' ); ?></strong></p>
					<p><?php esc_html_e( 'Polylang cannot keep template translations until "My Templates" is enabled, so there is nothing to list here. Turn it on in Polylang settings, then run Scan Elementor.', 'duplicate-content-addon-for-polylang' ); ?></p>
				</div>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mlang_settings' ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Polylang settings', 'duplicate-content-addon-for-polylang' ); ?>
				</a>
			</div>
			<?php
		}

		/**
		 * Render Elementor document type chips.
		 *
		 * @param array<int, array<string, mixed>> $document_types  Scanned type rows.
		 * @param array<int, array<string, mixed>> $template_groups Template issue groups.
		 * @param array<int, array<string, mixed>> $buckets         Insight buckets.
		 * @return void
		 */
		private function render_elementor_document_types( array $document_types, array $template_groups = array(), array $buckets = array() ) {
			$counts = $this->count_elementor_issues_by_type( $template_groups, $buckets );

			// With nothing to filter, a row of chips all reading 0 is just noise.
			if ( array_sum( array_map( 'intval', $counts ) ) < 1 ) {
				return;
			}

			// The five common Theme Builder types always show (disabled at 0) so the
			// row reads the same on every site. One chip may stand for several slugs:
			// the JS matches any slug in the space-separated filter value.
			$chips = array(
				array(
					'slugs' => array( 'header' ),
					'label' => __( 'Headers', 'duplicate-content-addon-for-polylang' ),
				),
				array(
					'slugs' => array( 'footer' ),
					'label' => __( 'Footers', 'duplicate-content-addon-for-polylang' ),
				),
				array(
					'slugs' => array( 'single', 'single-post', 'single-page' ),
					'label' => __( 'Single Templates', 'duplicate-content-addon-for-polylang' ),
				),
				array(
					'slugs' => array( 'popup' ),
					'label' => __( 'Popups', 'duplicate-content-addon-for-polylang' ),
				),
				array(
					'slugs' => array( 'loop-item' ),
					'label' => __( 'Loop Items', 'duplicate-content-addon-for-polylang' ),
				),
			);

			// Other types only appear when they have issues to filter.
			$covered = call_user_func_array( 'array_merge', wp_list_pluck( $chips, 'slugs' ) );
			foreach ( $document_types as $type_row ) {
				$slug = isset( $type_row['slug'] ) ? (string) $type_row['slug'] : '';
				if ( '' !== $slug && ! in_array( $slug, $covered, true ) && ! empty( $counts[ $slug ] ) ) {
					$chips[] = array(
						'slugs' => array( $slug ),
						'label' => isset( $type_row['display'] ) ? (string) $type_row['display'] : $slug,
					);
				}
			}

			?>
			<div class="dupcap-filter-pills dupcap-filter-pills--types" data-dupcap-elementor-type-bar role="group" aria-label="<?php esc_attr_e( 'Filter Elementor issues by document type', 'duplicate-content-addon-for-polylang' ); ?>">
				<button type="button" class="dupcap-filter-pill is-active" data-dupcap-elementor-type-filter="all" aria-pressed="true">
					<?php esc_html_e( 'All types', 'duplicate-content-addon-for-polylang' ); ?>
				</button>
				<?php foreach ( $chips as $chip ) : ?>
					<?php
					$count = 0;
					foreach ( $chip['slugs'] as $slug ) {
						$count += isset( $counts[ $slug ] ) ? $counts[ $slug ] : 0;
					}
					?>
					<button
						type="button"
						class="dupcap-filter-pill"
						data-dupcap-elementor-type-filter="<?php echo esc_attr( implode( ' ', $chip['slugs'] ) ); ?>"
						aria-pressed="false"
						title="<?php echo esc_attr( sprintf( 
							/* translators: %d: number of issues */
							_n( '%d issue', '%d issues', $count, 'duplicate-content-addon-for-polylang' ), $count ) ); ?>"
						<?php disabled( 0 === $count ); ?>
					>
						<?php echo esc_html( $chip['label'] ); ?>
						<span class="dupcap-filter-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/**
		 * Drop Missing Elementor translation rows already listed under Elementor Templates.
		 *
		 * @param array<int, array<string, mixed>> $buckets         Insight buckets.
		 * @param array<int, array<string, mixed>> $template_groups Template issue groups.
		 * @return array<int, array<string, mixed>>
		 */
		private function remove_duplicate_elementor_missing_translations( array $buckets, array $template_groups ) {
			$listed = array();
			foreach ( $template_groups as $group ) {
				if ( empty( $group['dedupe_key'] ) || 'elementor_templates' !== $group['dedupe_key'] || empty( $group['details'] ) || ! is_array( $group['details'] ) ) {
					continue;
				}
				foreach ( $group['details'] as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$id = ! empty( $detail['template_id'] ) ? (int) $detail['template_id'] : ( ! empty( $detail['post_id'] ) ? (int) $detail['post_id'] : 0 );
					if ( $id > 0 ) {
						$listed[ $id ] = true;
					}
				}
			}

			if ( empty( $listed ) ) {
				return $buckets;
			}

			$filtered = array();
			foreach ( $buckets as $bucket ) {
				$key = isset( $bucket['key'] ) ? (string) $bucket['key'] : '';
				if ( 'missing_translation' !== $key || empty( $bucket['examples'] ) || ! is_array( $bucket['examples'] ) ) {
					$filtered[] = $bucket;
					continue;
				}

				$examples = array();
				foreach ( $bucket['examples'] as $example ) {
					if ( ! is_array( $example ) ) {
						continue;
					}
					$id = ! empty( $example['template_id'] ) ? (int) $example['template_id'] : ( ! empty( $example['post_id'] ) ? (int) $example['post_id'] : 0 );
					if ( $id > 0 && isset( $listed[ $id ] ) ) {
						continue;
					}
					$examples[] = $example;
				}

				if ( empty( $examples ) ) {
					continue;
				}

				$bucket['count']    = count( $examples );
				$bucket['examples'] = $examples;
				$filtered[]         = $bucket;
			}

			return $filtered;
		}

		/**
		 * Render one Elementor insight bucket.
		 *
		 * @param array<string, mixed> $bucket Insight bucket.
		 * @return void
		 */
		private function render_elementor_insight_bucket( array $bucket ) {
			$severity = DUPCAP_Elementor_Finding::CRITICAL === $bucket['severity'] ? 'crit' : 'warn';
			$count    = isset( $bucket['count'] ) ? (int) $bucket['count'] : 0;
			$examples = isset( $bucket['examples'] ) && is_array( $bucket['examples'] ) ? $bucket['examples'] : array();
			$icon      = 'crit' === $severity ? 'dismiss' : 'warning';
			$bucket_key = isset( $bucket['key'] ) ? (string) $bucket['key'] : '';
			if ( '' === $bucket_key && isset( $bucket['code'] ) ) {
				$bucket_key = (string) $bucket['code'];
			}
			if ( '' === $bucket_key ) {
				$bucket_key = sanitize_title( isset( $bucket['title'] ) ? (string) $bucket['title'] : 'bucket' );
			}

			?>
			<details
				class="dupcap-elementor-bucket dupcap-elementor-bucket-<?php echo esc_attr( $severity ); ?>"
				data-dupcap-card
				data-dupcap-card-id="<?php echo esc_attr( 'bucket:' . $bucket_key ); ?>"
				name="dupcap-elementor-accordion"
				data-dupcap-bucket-severity="<?php echo esc_attr( $severity ); ?>"
				data-dupcap-bucket-total="<?php echo esc_attr( (string) $count ); ?>"
			>
				<summary class="dupcap-elementor-bucket-summary">
					<span class="dupcap-elementor-bucket-icon dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
					<span class="dupcap-elementor-bucket-main">
						<strong data-dupcap-bucket-title="<?php echo esc_attr( isset( $bucket['title'] ) ? $bucket['title'] : '' ); ?>">
							<?php
							printf(
								/* translators: 1: issue title 2: count */
								esc_html__( '%1$s (%2$d)', 'duplicate-content-addon-for-polylang' ),
								esc_html( isset( $bucket['title'] ) ? $bucket['title'] : '' ),
								absint( $count )
							);
							?>
						</strong>
						<span><?php echo esc_html( isset( $bucket['message'] ) ? $bucket['message'] : '' ); ?></span>
					</span>
					<span class="dupcap-elementor-severity-badge dupcap-elementor-severity-<?php echo esc_attr( $severity ); ?>">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<?php echo esc_html( $this->get_severity_label( $severity ) ); ?>
					</span>
				</summary>
				<div class="dupcap-elementor-bucket-body">
					<?php if ( ! empty( $examples ) ) : ?>
						<?php if ( 'missing_translation' === $bucket_key ) : ?>
							<?php $this->render_content_pending_table( $examples, 'missing_translation' ); ?>
						<?php else : ?>
							<ul class="dupcap-elementor-examples" data-dupcap-examples-list data-dupcap-paginate>
								<?php foreach ( $examples as $example ) : ?>
									<?php $this->render_elementor_example_item( $example ); ?>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</details>
			<?php
		}

		/**
		 * Render one Elementor insight example row.
		 *
		 * @param array<string, mixed> $example Example row.
		 * @param bool                 $hidden  Whether the row starts collapsed.
		 * @return void
		 */
		private function render_elementor_example_item( array $example, $hidden = false ) {
			// Prebuilt summaries from format_example_summary() are plain text (titles/context).
			$summary_html = ! empty( $example['summary'] )
				? esc_html( DUPCAP_Elementor_Fields::sanitize_display_text( $example['summary'] ) )
				: '';
			$detail       = '';
			if ( ! empty( $example['detail'] ) ) {
				$detail = $example['detail'];
			} elseif ( ! empty( $example['why'] ) ) {
				$detail = $example['why'];
			} elseif ( ! empty( $example['what'] ) ) {
				$detail = $example['what'];
			}
			$detail = DUPCAP_Elementor_Fields::sanitize_display_text( $detail );

			if ( '' === $summary_html ) {
				$parts = array();
				if ( ! empty( $example['title'] ) ) {
					if ( ! empty( $example['fix_url'] ) ) {
						$parts[] = '<a href="' . esc_url( $example['fix_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $example['title'] ) . '</a>';
					} else {
						$parts[] = esc_html( $example['title'] );
					}
				}
				$type_display = ! empty( $example['type_display'] )
					? $example['type_display']
					: ( ! empty( $example['type'] ) ? DUPCAP_Elementor_Document::type_display( $example['type'] ) : '' );
				if ( $type_display ) {
					$parts[] = esc_html( $type_display );
				}
				if ( ! empty( $example['language'] ) ) {
					$parts[] = esc_html( DUPCAP_Elementor_Fields::language_label( $example['language'] ) );
				}
				if ( ! empty( $example['widget'] ) ) {
					$parts[] = esc_html( DUPCAP_Elementor_Fields::widget_label( $example['widget'] ) );
				} elseif ( ! empty( $example['field'] ) ) {
					$parts[] = esc_html( DUPCAP_Elementor_Fields::field_location_label( $example['field'] ) );
				}
				// Parts are already escaped; allow only the title link markup.
				$summary_html = wp_kses(
					implode( ' · ', $parts ),
					array(
						'a' => array(
							'href'   => true,
							'target' => true,
							'rel'    => true,
						),
					)
				);
			}

			$fix_label = ! empty( $example['fix_label'] )
				? $example['fix_label']
				: __( 'Edit translation', 'duplicate-content-addon-for-polylang' );
			$doc_type  = ! empty( $example['type'] )
				? (string) $example['type']
				: ( isset( $example['template_type'] ) ? (string) $example['template_type'] : '' );
			$code      = isset( $example['code'] ) ? (string) $example['code'] : '';
			$is_dead   = ( 'dead_link' === $code );
			$dead_edit = false;
			if ( $is_dead ) {
				$dead_url = '';
				if ( ! empty( $example['translated_value'] ) ) {
					$dead_url = (string) $example['translated_value'];
				} elseif ( ! empty( $example['original_value'] ) ) {
					$dead_url = (string) $example['original_value'];
				}
				$target = class_exists( 'DUPCAP_Elementor_Fields' )
					? DUPCAP_Elementor_Fields::describe_dead_link_target( $dead_url )
					: array();
				if ( ! empty( $target['found'] ) && ! empty( $target['fix_url'] ) ) {
					$fix_label             = ! empty( $target['fix_label'] ) ? (string) $target['fix_label'] : __( 'Edit page', 'duplicate-content-addon-for-polylang' );
					$example['fix_url']     = (string) $target['fix_url'];
					$dead_edit             = true;
				} else {
					$fix_label = __( 'Create page', 'duplicate-content-addon-for-polylang' );
					if ( empty( $example['fix_url'] ) || false === strpos( (string) $example['fix_url'], 'post-new.php' ) ) {
						$example['fix_url'] = admin_url( 'post-new.php?post_type=page' );
					}
				}
			}
			$elementor_edit_url = '';
			$is_grouped_dead    = $is_dead && ! empty( $example['grouped'] );
			if ( $is_dead && ! $is_grouped_dead && ! empty( $example['template_id'] ) && class_exists( 'DUPCAP_Elementor_Document' ) ) {
				$elementor_edit_url = ( new DUPCAP_Elementor_Document( (int) $example['template_id'] ) )->edit_url();
			}
			$occ_count   = $is_grouped_dead && ! empty( $example['occurrence_count'] ) ? (int) $example['occurrence_count'] : 0;
			$occ_langs   = $is_grouped_dead && ! empty( $example['languages'] ) && is_array( $example['languages'] ) ? $example['languages'] : array();
			$occurrences = $is_grouped_dead && ! empty( $example['occurrences'] ) && is_array( $example['occurrences'] ) ? $example['occurrences'] : array();
			?>
			<li<?php echo '' !== $doc_type ? ' data-dupcap-document-type="' . esc_attr( $doc_type ) . '"' : ''; ?><?php echo ! empty( $example['template_id'] ) && ! $is_grouped_dead ? ' data-dupcap-object-id="' . esc_attr( (string) (int) $example['template_id'] ) . '"' : ''; ?>>
				<div class="dupcap-elementor-example-card">
					<span class="dupcap-elementor-example-icon dashicons dashicons-media-document" aria-hidden="true"></span>
					<div class="dupcap-elementor-example-copy">
						<strong class="dupcap-elementor-example-summary"><?php echo $summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above via esc_html() or wp_kses(). ?></strong>
						<?php if ( ! empty( $occ_langs ) ) : ?>
							<p class="dupcap-content-link-context">
								<?php foreach ( $occ_langs as $lang_slug ) : ?>
									<span class="dupcap-lang-chip"><?php echo esc_html( DUPCAP_Elementor_Fields::language_label( (string) $lang_slug ) ); ?></span>
								<?php endforeach; ?>
							</p>
						<?php endif; ?>
						<?php $this->render_elementor_example_detail( $example, $detail ); ?>
						<?php if ( $occ_count > 0 ) : ?>
							<p class="dupcap-content-link-context">
								<?php
								printf(
									/* translators: %d: how many widgets/layouts link to this page */
									esc_html( _n( 'Found in %d place:', 'Found in %d places:', $occ_count, 'duplicate-content-addon-for-polylang' ) ),
									(int) $occ_count
								);
								?>
							</p>
							<ul class="dupcap-dead-link-occurrences">
								<?php foreach ( $occurrences as $place ) : ?>
									<?php
									if ( ! is_array( $place ) ) {
										continue;
									}
									$place_title = ! empty( $place['title'] ) ? (string) $place['title'] : __( 'Layout', 'duplicate-content-addon-for-polylang' );
									$place_lang  = ! empty( $place['language'] ) ? DUPCAP_Elementor_Fields::language_label( (string) $place['language'] ) : '';
									$place_where = $place_lang ? $place_title . ' · ' . $place_lang : $place_title;
									$place_link  = ! empty( $place['context_label'] ) ? trim( (string) $place['context_label'] ) : '';
									if ( '' !== $place_link ) {
										$place_label = sprintf(
											/* translators: 1: link/button text in the layout 2: layout title and language */
											__( '“%1$s” in %2$s', 'duplicate-content-addon-for-polylang' ),
											$place_link,
											$place_where
										);
									} else {
										$place_label = $place_where;
									}
									$place_edit  = '';
									if ( ! empty( $place['template_id'] ) && class_exists( 'DUPCAP_Elementor_Document' ) ) {
										$place_edit = ( new DUPCAP_Elementor_Document( (int) $place['template_id'] ) )->edit_url();
									}
									$place_ignore = ! empty( $place['ignore_key'] ) ? (string) $place['ignore_key'] : '';
									?>
									<li class="dupcap-dead-link-occurrence">
										<span><?php echo esc_html( $place_label ); ?></span>
										<span class="dupcap-detail-row-actions">
											<?php if ( '' !== $place_edit ) : ?>
												<a
													class="button button-small"
													href="<?php echo esc_url( $place_edit ); ?>"
													target="_blank"
													rel="noopener noreferrer"
												>
													<?php esc_html_e( 'Edit link in Elementor', 'duplicate-content-addon-for-polylang' ); ?>
												</a>
											<?php endif; ?>
											<?php if ( '' !== $place_ignore ) : ?>
												<button
													type="button"
													class="button button-small dupcap-rescan-issue-button"
													data-dupcap-rescan-issue
													data-template-id="<?php echo esc_attr( isset( $place['template_id'] ) ? (int) $place['template_id'] : 0 ); ?>"
													data-ignore-key="<?php echo esc_attr( $place_ignore ); ?>"
												>
													<span class="dashicons dashicons-update" aria-hidden="true"></span>
													<?php esc_html_e( 'Rescan issue', 'duplicate-content-addon-for-polylang' ); ?>
												</button>
												<button
													type="button"
													class="button button-small dupcap-rescan-issue-button dupcap-ignore-button"
													data-dupcap-ignore="<?php echo esc_attr( $place_ignore ); ?>"
													title="<?php esc_attr_e( 'Dismiss when this link should stay as it is.', 'duplicate-content-addon-for-polylang' ); ?>"
												>
													<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
												</button>
											<?php endif; ?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
					<div class="dupcap-elementor-example-actions">
						<?php if ( $this->is_template_sync_fix_example( $example ) ) : ?>
							<?php $this->render_template_sync_plugin_button( 'button button-small' ); ?>
						<?php elseif ( ! empty( $example['fix_url'] ) ) : ?>
							<a
								class="button button-small dupcap-elementor-example-action"
								href="<?php echo esc_url( $example['fix_url'] ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								data-dupcap-track-fix="elementor"
								data-template-id="<?php echo esc_attr( isset( $example['template_id'] ) ? (int) $example['template_id'] : 0 ); ?>"
								data-ignore-key="<?php echo esc_attr( isset( $example['ignore_key'] ) ? $example['ignore_key'] : '' ); ?>"
							>
								<span class="dashicons dashicons-<?php echo ( $is_dead && ! $dead_edit ) ? 'plus-alt' : 'edit'; ?>" aria-hidden="true"></span>
								<?php echo esc_html( $fix_label ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $is_dead && '' !== $elementor_edit_url ) : ?>
							<a
								class="button button-small"
								href="<?php echo esc_url( $elementor_edit_url ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								data-dupcap-track-fix="elementor"
								data-template-id="<?php echo esc_attr( isset( $example['template_id'] ) ? (int) $example['template_id'] : 0 ); ?>"
								data-ignore-key="<?php echo esc_attr( isset( $example['ignore_key'] ) ? $example['ignore_key'] : '' ); ?>"
							>
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
								<?php esc_html_e( 'Edit link in Elementor', 'duplicate-content-addon-for-polylang' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( ! $is_grouped_dead ) : ?>
							<button
								type="button"
								class="button button-small dupcap-rescan-issue-button"
								data-dupcap-rescan-issue
								data-template-id="<?php echo esc_attr( isset( $example['template_id'] ) ? (int) $example['template_id'] : 0 ); ?>"
								data-ignore-key="<?php echo esc_attr( isset( $example['ignore_key'] ) ? $example['ignore_key'] : '' ); ?>"
							>
								<span class="dashicons dashicons-update" aria-hidden="true"></span>
								<?php esc_html_e( 'Rescan issue', 'duplicate-content-addon-for-polylang' ); ?>
							</button>
							<?php if ( ! empty( $example['ignore_key'] ) ) : ?>
								<button
									type="button"
									class="button button-small dupcap-rescan-issue-button dupcap-ignore-button"
									data-dupcap-ignore="<?php echo esc_attr( $example['ignore_key'] ); ?>"
									title="<?php esc_attr_e( 'Dismiss when the original text is correct for every language, such as a brand name.', 'duplicate-content-addon-for-polylang' ); ?>"
								>
									<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
								</button>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>
			</li>
			<?php
		}

		/**
		 * Render formatted example detail or step-by-step link instructions with copy link button.
		 *
		 * @param array<string, mixed> $example Example data.
		 * @param string               $detail  Raw detail text.
		 * @return void
		 */
		private function render_elementor_example_detail( array $example, $detail ) {
			$code = isset( $example['code'] ) ? (string) $example['code'] : '';
			$is_link_issue = in_array( $code, array( 'untranslated_link', 'wrong_language_link', 'broken_link', 'dead_link' ), true )
				|| ( is_string( $detail ) && 0 === strpos( $detail, 'This link goes to' ) );

			if ( ! $is_link_issue || empty( $detail ) ) {
				if ( '' !== $detail ) {
					echo '<p class="dupcap-elementor-example-detail">' . esc_html( $detail ) . '</p>';
				}
				return;
			}

			if ( 'dead_link' === $code ) {
				$current_label = '';
				$dead_url      = '';
				$link_text     = ! empty( $example['context_label'] ) ? trim( (string) $example['context_label'] ) : '';
				if ( ! empty( $example['translated_value'] ) ) {
					$dead_url      = (string) $example['translated_value'];
					$current_label = DUPCAP_Elementor_Fields::relative_path( $dead_url );
					if ( '' === $current_label ) {
						$current_label = DUPCAP_Elementor_Fields::readable_url( $dead_url );
					}
				} elseif ( ! empty( $example['original_value'] ) ) {
					$dead_url      = (string) $example['original_value'];
					$current_label = DUPCAP_Elementor_Fields::relative_path( $dead_url );
					if ( '' === $current_label ) {
						$current_label = DUPCAP_Elementor_Fields::readable_url( $dead_url );
					}
				}
				if ( '' === $current_label && preg_match( '/This link goes to ([^\.]+)\./', $detail, $m ) ) {
					$current_label = trim( $m[1] );
				}
				// Grouped rows list each label under Found in; only show one shared
				// link text here when every occurrence uses the same label.
				if ( ! empty( $example['grouped'] ) && ! empty( $example['occurrences'] ) && is_array( $example['occurrences'] ) ) {
					$occ_texts = array();
					foreach ( $example['occurrences'] as $occ_place ) {
						if ( ! is_array( $occ_place ) || empty( $occ_place['context_label'] ) ) {
							continue;
						}
						$occ_texts[] = trim( (string) $occ_place['context_label'] );
					}
					$occ_texts = array_values( array_unique( array_filter( $occ_texts ) ) );
					$link_text = ( 1 === count( $occ_texts ) ) ? $occ_texts[0] : '';
				}
				$this->render_dead_link_steps( $current_label, $dead_url, '', $link_text );
				return;
			}

			$lang = ! empty( $example['language'] ) ? strtolower( trim( (string) $example['language'] ) ) : '';
			$copy_url = $this->get_localized_target_url( $example, $detail, $lang );

			$current_context = '';
			if ( preg_match( '/This link goes to ([^\.]+)\./', $detail, $m ) ) {
				$current_context = $m[1];
			}

			$action_text = '';
			if ( preg_match( '/\.\s*(.+)$/', $detail, $m ) ) {
				$action_text = $m[1];
			} else {
				$action_text = $detail;
			}

			if ( ! empty( $copy_url ) && false !== strpos( $action_text, ':' ) ) {
				// Strip trailing page name and URL from text since it's displayed in the badge
				$action_text = substr( $action_text, 0, strpos( $action_text, ':' ) + 1 );
			}

			?>
			<div class="dupcap-step-instructions">
				<div class="dupcap-step-item">
					<span class="dupcap-step-num" aria-hidden="true">1</span>
					<span class="dupcap-step-label"><?php esc_html_e( 'Current link:', 'duplicate-content-addon-for-polylang' ); ?></span>
					<span class="dupcap-step-desc"><?php echo esc_html( $current_context ? $current_context : $detail ); ?></span>
				</div>
				<div class="dupcap-step-item">
					<span class="dupcap-step-num" aria-hidden="true">2</span>
					<span class="dupcap-step-label"><?php esc_html_e( 'Action:', 'duplicate-content-addon-for-polylang' ); ?></span>
					<span class="dupcap-step-desc"><?php echo esc_html( $action_text ); ?></span>
					<?php if ( ! empty( $copy_url ) ) : ?>
						<span class="dupcap-copy-url-box">
							<code class="dupcap-url-badge dupcap-url-target"><?php echo esc_html( $copy_url ); ?></code>
							<button type="button" class="button button-small dupcap-copy-btn" data-dupcap-copy="<?php echo esc_attr( $copy_url ); ?>" title="<?php esc_attr_e( 'Copy link to clipboard', 'duplicate-content-addon-for-polylang' ); ?>">
								<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
								<span class="dupcap-copy-text"><?php esc_html_e( 'Copy link', 'duplicate-content-addon-for-polylang' ); ?></span>
							</button>
						</span>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Numbered how-to steps for a dead / missing-page menu or button link.
		 *
		 * @param string $current_label Readable current path or page label.
		 * @param string $url           Optional raw URL used to resolve Draft/Private/protected.
		 * @param string $kind_hint     Optional kind from a saved report (missing|draft|...).
		 * @param string $link_text     Optional menu/button label that uses this URL.
		 * @return void
		 */
		private function render_dead_link_steps( $current_label, $url = '', $kind_hint = '', $link_text = '' ) {
			$current_label = trim( (string) $current_label );
			if ( '' === $current_label ) {
				$current_label = '/';
			}
			$link_text = trim( (string) $link_text );

			$kind = (string) $kind_hint;
			// Prefer a live resolve so Draft/Private/protected stays accurate without a rescan.
			if ( '' !== trim( (string) $url ) && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
				$target = DUPCAP_Elementor_Fields::describe_dead_link_target( $url );
				$kind   = isset( $target['kind'] ) ? (string) $target['kind'] : 'missing';
			}
			if ( '' === $kind ) {
				$kind = 'missing';
			}

			$problem = class_exists( 'DUPCAP_Elementor_Fields' )
				? DUPCAP_Elementor_Fields::dead_link_problem_text( $kind )
				: __( 'No published page was found at this URL.', 'duplicate-content-addon-for-polylang' );
			$fix = class_exists( 'DUPCAP_Elementor_Fields' )
				? DUPCAP_Elementor_Fields::dead_link_fix_text( $kind )
				: __( 'Create and publish the page, or change the link to an existing page.', 'duplicate-content-addon-for-polylang' );

			$then = in_array( $kind, array( 'draft', 'private', 'protected', 'pending', 'future', 'other' ), true )
				? __( 'Publish or update the page as needed, update the menu/button if you changed the link, then click Rescan issue.', 'duplicate-content-addon-for-polylang' )
				: __( 'Open Appearance → Menus (or Edit menu), update the item, save, then click Rescan issue.', 'duplicate-content-addon-for-polylang' );

			$step = 1;
			?>
			<div class="dupcap-step-instructions">
				<?php if ( '' !== $link_text ) : ?>
					<div class="dupcap-step-item">
						<span class="dupcap-step-num" aria-hidden="true"><?php echo esc_html( (string) $step ); ?></span>
						<span class="dupcap-step-label"><?php esc_html_e( 'Link text:', 'duplicate-content-addon-for-polylang' ); ?></span>
						<span class="dupcap-step-desc"><?php echo esc_html( $link_text ); ?></span>
					</div>
					<?php ++$step; ?>
				<?php endif; ?>
				<div class="dupcap-step-item">
					<span class="dupcap-step-num" aria-hidden="true"><?php echo esc_html( (string) $step ); ?></span>
					<span class="dupcap-step-label"><?php esc_html_e( 'Current link:', 'duplicate-content-addon-for-polylang' ); ?></span>
					<span class="dupcap-step-desc"><?php echo esc_html( $current_label ); ?></span>
				</div>
				<?php ++$step; ?>
				<div class="dupcap-step-item">
					<span class="dupcap-step-num" aria-hidden="true"><?php echo esc_html( (string) $step ); ?></span>
					<span class="dupcap-step-label"><?php esc_html_e( 'Problem:', 'duplicate-content-addon-for-polylang' ); ?></span>
					<span class="dupcap-step-desc"><?php echo esc_html( $problem ); ?></span>
				</div>
				<?php ++$step; ?>
				<div class="dupcap-step-item">
					<span class="dupcap-step-num" aria-hidden="true"><?php echo esc_html( (string) $step ); ?></span>
					<span class="dupcap-step-label"><?php esc_html_e( 'How to fix:', 'duplicate-content-addon-for-polylang' ); ?></span>
					<span class="dupcap-step-desc"><?php echo esc_html( $fix ); ?></span>
				</div>
				<?php ++$step; ?>
				<div class="dupcap-step-item">
					<span class="dupcap-step-num" aria-hidden="true"><?php echo esc_html( (string) $step ); ?></span>
					<span class="dupcap-step-label"><?php esc_html_e( 'Then:', 'duplicate-content-addon-for-polylang' ); ?></span>
					<span class="dupcap-step-desc"><?php echo esc_html( $then ); ?></span>
				</div>
			</div>
			<?php
		}

		/**
		 * Resolve the correct localized target URL for a link issue.
		 *
		 * @param array<string, mixed> $example Example data.
		 * @param string               $detail  Detail description text.
		 * @param string               $lang    Target language slug.
		 * @return string
		 */
		private function get_localized_target_url( array $example, $detail, $lang = '' ) {
			// If there is no translation for the page yet, there is no translated URL to copy.
			if ( false !== stripos( $detail, 'no ' . $lang . ' page' ) || false !== stripos( $detail, 'no translated page' ) || false !== stripos( $detail, 'There is no' ) ) {
				return '';
			}

			$suggested = ! empty( $example['suggested_url'] ) ? trim( (string) $example['suggested_url'] ) : '';

			// If suggested URL is valid, localized, and is not a bare language root (e.g. /de/ means no real translated page was found)
			if ( ! empty( $suggested ) && '' !== $lang ) {
				$path = (string) wp_parse_url( $suggested, PHP_URL_PATH );
				$bare_root_sl = '/' . $lang . '/';
				$bare_root    = '/' . $lang;
				$is_localized = ( 0 === strpos( $path, $bare_root_sl ) || untrailingslashit( $path ) === $bare_root );
				$is_bare_root = ( untrailingslashit( $path ) === $bare_root );
				if ( $is_localized && ! $is_bare_root ) {
					return $suggested;
				}
			}

			// Extract fragment/anchor from detail or values
			$fragment = '';
			if ( preg_match( '/(#[\w\-]+)/', $detail, $fm ) ) {
				$fragment = $fm[1];
			} elseif ( ! empty( $example['translated_value'] ) && false !== strpos( $example['translated_value'], '#' ) ) {
				$fragment = substr( $example['translated_value'], strpos( $example['translated_value'], '#' ) );
			}

			// Check if target is specifically the front page / home
			$is_home_target = false !== stripos( $detail, 'page: Home' )
				|| ( false !== stripos( $detail, 'put the' ) && false !== stripos( $detail, 'page in front' ) )
				|| preg_match( '/goes to (?:Home|\/)\s*[\(\.]/i', $detail );

			if ( $is_home_target && '' !== $lang ) {
				$home_url = '';
				if ( function_exists( 'pll_home_url' ) ) {
					$home_url = pll_home_url( $lang );
				}
				if ( ! empty( $home_url ) ) {
					$rel_home = untrailingslashit( DUPCAP_Elementor_Fields::relative_path( $home_url, false ) );
					if ( '' === $rel_home || '/' === $rel_home ) {
						$rel_home = '/' . $lang;
					}
					return $rel_home . '/' . $fragment;
				}
				return '/' . $lang . '/' . $fragment;
			}

			// If suggested URL is present
			if ( ! empty( $suggested ) ) {
				if ( '' !== $fragment && false === strpos( $suggested, '#' ) ) {
					return untrailingslashit( $suggested ) . '/' . $fragment;
				}
				return $suggested;
			}

			// Extract parenthetical suggested URL from detail (e.g. "Change it to the Deutsch page: Blog (/de/blog-2/)")
			if ( preg_match( '/Change it to [^:]+:\s*[^\(]*\((https?:\/\/[^\s\)]+|\/[^\s\)]*)\)/i', $detail, $matches ) ) {
				return $matches[1];
			}

			if ( preg_match_all( '/\((https?:\/\/[^\s\)]+|\/[^\s\)]*)\)/', $detail, $matches ) ) {
				$urls = $matches[1];
				if ( count( $urls ) >= 2 ) {
					return end( $urls );
				}
			}

			return '';
		}


}
