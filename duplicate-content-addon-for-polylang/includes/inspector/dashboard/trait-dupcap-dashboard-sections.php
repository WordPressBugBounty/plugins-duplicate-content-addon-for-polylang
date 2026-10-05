<?php


defined( 'ABSPATH' ) || exit;

/**
 * Sections methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Sections_Trait {

		/**
		 * Content panel: post, page and CPT issue cards with the post-type filter.
		 *
		 * @param array<int, array<string, mixed>> $groups Content issue groups.
		 * @param array<int, array<string, mixed>> $passed Passed content checks.
		 * @param array<string, int>               $counts Severity pill counts.
		 * @return void
		 */
		private function render_content_section( array $groups, array $passed = array(), array $counts = array() ) {
			$ignored = $this->get_ignored_items_for_section( 'content' );
			$counts  = wp_parse_args(
				$counts,
				array(
					'crit'    => 0,
					'warn'    => 0,
					'pass'    => 0,
					'ignored' => 0,
				)
			);
			$counts['ignored'] = count( $ignored );

			if ( empty( $groups ) && empty( $passed ) && empty( $ignored ) ) {
				return;
			}

			?>
			<section class="dupcap-card dupcap-content-section" aria-labelledby="dupcap-content-title" data-dupcap-content-section>
				<div class="dupcap-section-heading">
					<div>
						<h2 id="dupcap-content-title">
							<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
							<?php esc_html_e( 'Content', 'duplicate-content-addon-for-polylang' ); ?>
						</h2>
						<p><?php esc_html_e( 'Pages, posts, custom post types, categories, and tags with translation issues.', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
					<?php $this->render_section_scan_bar( 'content', DUPCAP_Scanner::get_saved_report() ); ?>
				</div>
				<?php $this->render_content_filters( $groups, $counts ); ?>
				<div class="dupcap-card-stack" id="dupcap-content-cards">
					<?php
					foreach ( $groups as $index => $group ) {
						$this->render_issue_group( $group, 0 === $index );
					}
					?>
				</div>
				<?php $this->render_passed_checks( $passed ); ?>
				<?php $this->render_ignored_issues( $ignored, 'content' ); ?>
			</section>
			<?php
		}

		/**
		 * Content tab filter bar: title search and post type.
		 *
		 * Filtering is client-side (admin.js applyContentFilters). Rows carry
		 * data-dupcap-content-type and data-dupcap-title. Every change sends the
		 * paginated lists back to page 1.
		 *
		 * @param array<int, array<string, mixed>> $groups Content issue groups.
		 * @param array<string, int>               $counts Severity pill counts.
		 * @return void
		 */
		private function render_content_filters( array $groups, array $counts = array() ) {
			// Count the rows each card actually shows: the Missing, Draft and Terms
			// tables collapse every language of an item into one row. Ignored rows
			// stay out of these totals the same way passed checks do — they have
			// their own severity pill and panel.
			$type_counts = array();
			$total       = 0;
			foreach ( $groups as $group ) {
				$details = isset( $group['details'] ) && is_array( $group['details'] ) ? $group['details'] : array();
				if ( in_array( $group['dedupe_key'], array( 'content_missing', 'content_draft', 'term_missing', 'content_source_updated' ), true ) && $this->has_content_pending_posts( $details ) ) {
					// Without the key every group is grouped as posts, so the term
					// rows here came back with a post's type and put that post
					// type's chip on the filter bar.
					$details = $this->group_content_pending_by_post( $details, (string) $group['dedupe_key'] );
				}
				foreach ( $details as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					++$total;
					if ( ! empty( $detail['post_type'] ) ) {
						$slug                 = (string) $detail['post_type'];
						$type_counts[ $slug ] = ( isset( $type_counts[ $slug ] ) ? $type_counts[ $slug ] : 0 ) + 1;
					}
				}
			}

			// Pages and Posts first, then custom post types. Types with no rows are
			// left out rather than shown disabled.
			$fixed = array(
				'page' => __( 'Pages', 'duplicate-content-addon-for-polylang' ),
				'post' => __( 'Posts', 'duplicate-content-addon-for-polylang' ),
			);
			$types = array();
			foreach ( array_merge( array_keys( $fixed ), array_keys( $type_counts ) ) as $slug ) {
				if ( isset( $types[ $slug ] ) || empty( $type_counts[ $slug ] ) ) {
					continue;
				}
				if ( isset( $fixed[ $slug ] ) ) {
					$types[ $slug ] = $fixed[ $slug ];
				} else {
					$object         = get_post_type_object( $slug );
					$types[ $slug ] = ( $object && ! empty( $object->labels->name ) ) ? (string) $object->labels->name : $this->english_type_label( $slug );
				}
			}
			?>
			<div class="dupcap-section-filters" data-dupcap-content-filters>
				<div class="dupcap-filter-search">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<label class="screen-reader-text" for="dupcap-content-search"><?php esc_html_e( 'Search by title', 'duplicate-content-addon-for-polylang' ); ?></label>
					<input type="search" id="dupcap-content-search" class="dupcap-filter-search-input" data-dupcap-content-search placeholder="<?php esc_attr_e( 'Search by title...', 'duplicate-content-addon-for-polylang' ); ?>" autocomplete="off">
				</div>

				<?php $this->render_severity_pills( $counts ); ?>

				<?php // One type means nothing to filter, so the pills only appear from two. ?>
				<?php if ( count( $types ) > 1 ) : ?>
					<div class="dupcap-filter-pills dupcap-filter-pills--types" role="group" aria-label="<?php esc_attr_e( 'Filter by content type', 'duplicate-content-addon-for-polylang' ); ?>">
						<?php
						$this->render_filter_pill( 'type', 'all', __( 'All types', 'duplicate-content-addon-for-polylang' ), true, $total );
						foreach ( $types as $slug => $label ) {
							$this->render_filter_pill( 'type', $slug, $label, false, $type_counts[ $slug ] );
						}
						?>
					</div>
				<?php endif; ?>

				<p
					class="screen-reader-text"
					aria-live="polite"
					data-dupcap-content-filter-status
					data-template="<?php /* translators: %s: number of items that match the filters. */ echo esc_attr__( 'Matching items: %s', 'duplicate-content-addon-for-polylang' ); ?>"
				></p>
			</div>
			<?php
			$this->render_critical_pointer( isset( $counts['crit_elsewhere'] ) ? (int) $counts['crit_elsewhere'] : 0 );
			$this->render_filter_empty();
		}

		/**
		 * One toggle button in a filter group (aria-pressed).
		 *
		 * @param string   $group      Filter group (the JS reads data-dupcap-filter-group).
		 * @param string   $value      Filter value ("all" resets the group).
		 * @param string   $label      Visible label.
		 * @param bool     $pressed    Whether this is the active value.
		 * @param int|null $count      Optional number of items with this value.
		 * @param bool     $disabled   Disabled when nothing has this value.
		 * @param bool     $show_count Whether to draw the count badge. The number is
		 *                             still written to data-dupcap-filter-count either way.
		 * @return void
		 */
		private function render_filter_pill( $group, $value, $label, $pressed = false, $count = null, $disabled = false, $show_count = true ) {
			?>
			<button
				type="button"
				class="dupcap-filter-pill<?php echo $pressed ? ' is-active' : ''; ?>"
				data-dupcap-filter-group="<?php echo esc_attr( $group ); ?>"
				data-dupcap-filter-value="<?php echo esc_attr( $value ); ?>"
				<?php // Kept even when the badge is hidden, so the number stays checkable. ?>
				<?php echo null !== $count ? 'data-dupcap-filter-count="' . esc_attr( (string) (int) $count ) . '"' : ''; ?>
				aria-pressed="<?php echo $pressed ? 'true' : 'false'; ?>"
				<?php disabled( $disabled ); ?>
			>
				<?php echo esc_html( $label ); ?>
				<?php if ( null !== $count && $show_count ) : ?>
					<span class="dupcap-filter-count"><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></span>
				<?php endif; ?>
			</button>
			<?php
		}

		/**
		 * Severity pills shared by every tab: Issues / Critical / Warning / Passed.
		 *
		 * @param array<string, int> $counts Counts from get_section_severity_counts().
		 * @return void
		 */
		private function render_severity_pills( array $counts ) {
			$show_ignored = array_key_exists( 'ignored', $counts );
			$counts       = wp_parse_args(
				$counts,
				array(
					'crit'    => 0,
					'warn'    => 0,
					'notice'  => 0,
					'pass'    => 0,
					'ignored' => 0,
				)
			);

			/*
			 * Two totals, because they answer different questions. The first pill
			 * reports problems only: counting passed checks and dismissed rows
			 * into it produced the largest number on the row for an area with
			 * nothing wrong with it. Whether to draw the row at all still depends
			 * on there being anything to show, passed checks included.
			 */
			$issues  = (int) $counts['crit'] + (int) $counts['warn'] + (int) $counts['notice'];
			$ignored = (int) $counts['ignored'];
			$total   = $issues + (int) $counts['pass'] + ( $show_ignored ? $ignored : 0 );

			if ( 0 === $total && ( ! $show_ignored || 0 === $ignored ) ) {
				return;
			}

			$options = array(
				'all'  => __( 'All Issues', 'duplicate-content-addon-for-polylang' ),
				'crit' => __( 'Critical', 'duplicate-content-addon-for-polylang' ),
				'warn' => __( 'Warning', 'duplicate-content-addon-for-polylang' ),
				'info' => __( 'Notice', 'duplicate-content-addon-for-polylang' ),
				'pass' => __( 'Passed', 'duplicate-content-addon-for-polylang' ),
			);
			if ( $show_ignored ) {
				$options['ignored'] = __( 'Ignored', 'duplicate-content-addon-for-polylang' );
			}

			// The pill reads 'info' because that is the value on the card, but a
			// Notice is counted under its own key.
			$counts['info'] = (int) $counts['notice'];
			?>
			<div class="dupcap-filter-pills dupcap-filter-pills--severity" role="group" aria-label="<?php esc_attr_e( 'Filter by severity', 'duplicate-content-addon-for-polylang' ); ?>" data-dupcap-severity-filters>
				<?php
				foreach ( $options as $value => $label ) {
					if ( 'all' === $value ) {
						$count = $issues;
					} elseif ( 'ignored' === $value ) {
						$count = $ignored;
					} else {
						$count = (int) $counts[ $value ];
					}

					/*
					 * Labels only: the numbers moved to the cards, where the rows
					 * are. Nothing is disabled either - a pill at zero still opens,
					 * and the panel says what is not there, which reads better than
					 * a button that refuses to be pressed.
					 */
					$this->render_filter_pill( 'severity', $value, $label, 'all' === $value, $count, false, false );
				}
				?>
			</div>
			<?php
		}

		/**
		 * Filter bar for tabs whose only filter is severity (URLs, Site setup).
		 *
		 * @param array<string, int> $counts Counts from get_section_severity_counts().
		 * @return void
		 */
		private function render_section_filters( array $counts ) {
			if ( 0 === array_sum( array_map( 'intval', $counts ) ) ) {
				return;
			}
			?>
			<div class="dupcap-section-filters">
				<?php $this->render_severity_pills( $counts ); ?>
			</div>
			<?php
			$this->render_critical_pointer( isset( $counts['crit_elsewhere'] ) ? (int) $counts['crit_elsewhere'] : 0 );
			$this->render_filter_empty();
		}

		/**
		 * Message shown by admin.js when the active filters hide everything.
		 *
		 * @return void
		 */
		private function render_filter_empty() {
			?>
			<p class="dupcap-empty-state dupcap-hidden" data-dupcap-filter-empty><?php esc_html_e( 'Nothing matches these filters.', 'duplicate-content-addon-for-polylang' ); ?></p>
			<?php
		}

		/**
		 * Site setup panel: homepage, menus, navigation, terms, strings, switcher.
		 *
		 * @param array<int, array<string, mixed>> $groups        Site setup issue groups.
		 * @param array<int, array<string, mixed>> $passed_checks Passed Site setup checks.
		 * @param array<string, int>               $counts        Severity pill counts.
		 * @return void
		 */
		private function render_site_setup_section( array $groups, array $passed_checks, array $counts = array() ) {
			$ignored = $this->get_ignored_items_for_section( 'site' );
			$counts  = wp_parse_args(
				$counts,
				array(
					'crit'    => 0,
					'warn'    => 0,
					'pass'    => 0,
					'ignored' => 0,
				)
			);
			$counts['ignored'] = count( $ignored );

			if ( empty( $groups ) && empty( $passed_checks ) && empty( $ignored ) ) {
				return;
			}

			?>
			<section class="dupcap-card dupcap-grouped-section" aria-labelledby="dupcap-problems-title">
				<div class="dupcap-section-heading">
					<div>
						<h2 id="dupcap-problems-title">
							<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
							<?php esc_html_e( 'Site setup', 'duplicate-content-addon-for-polylang' ); ?>
						</h2>
						<p><?php esc_html_e( 'Homepage, menus, block navigation, site strings, and the language switcher.', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
					<?php $this->render_section_scan_bar( 'site', DUPCAP_Scanner::get_saved_report() ); ?>
				</div>
				<?php $this->render_section_filters( $counts ); ?>
				<?php if ( ! empty( $groups ) ) : ?>
				<div class="dupcap-card-stack" id="dupcap-problem-cards">
					<?php
					foreach ( $groups as $index => $group ) {
						$this->render_issue_group( $group, 0 === $index );
					}
					?>
				</div>
				<?php endif; ?>
				<?php $this->render_passed_checks( $passed_checks ); ?>
				<?php $this->render_ignored_issues( $ignored, 'site' ); ?>
			</section>
			<?php
		}

		/**
		 * Pointer from a domain panel to its critical groups in Fix These First.
		 *
		 * @param int $count Critical groups in this area.
		 * @return void
		 */
		private function render_critical_pointer( $count ) {
			if ( $count < 1 ) {
				return;
			}
			?>
			<div class="dupcap-critical-pointer" data-dupcap-severity="crit">
				<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
				<p>
					<?php
					printf(
						/* translators: %d: number of critical issue types in this report section. */
						esc_html( _n( '%d critical issue in this area is listed in Fix These First.', '%d critical issues in this area are listed in Fix These First.', $count, 'duplicate-content-addon-for-polylang' ) ),
						(int) $count
					);
					?>
				</p>
				<button type="button" class="button button-secondary" data-dupcap-goto-tab="overview" data-dupcap-goto-target="dupcap-fix-order">
					<?php esc_html_e( 'Open Overview', 'duplicate-content-addon-for-polylang' ); ?>
				</button>
			</div>
			<?php
		}

		/**
		 * Empty state for a panel whose section rendered nothing.
		 *
		 * @param string $key          Tab key.
		 * @param bool   $has_scan     Whether a saved scan exists.
		 * @param bool   $has_critical Whether this area has critical groups (shown on Overview).
		 * @return void
		 */
		private function render_panel_empty_state( $key, $has_scan, $has_critical ) {
			$scanned_areas = array( 'content', 'elementor', 'site' );

			if ( ! $has_scan && in_array( $key, $scanned_areas, true ) ) {
				$message = __( 'This area has not been scanned yet. Press Scan Website to check it.', 'duplicate-content-addon-for-polylang' );
			} elseif ( $has_critical ) {
				$message = __( 'Nothing else to review in this area.', 'duplicate-content-addon-for-polylang' );
			} else {
				$messages = array(
					'urls'      => __( 'URL and routing checks need at least two languages.', 'duplicate-content-addon-for-polylang' ),
					'content'   => __( 'No content problems were found in the last scan.', 'duplicate-content-addon-for-polylang' ),
					'elementor' => __( 'No Elementor problems were found in the last scan.', 'duplicate-content-addon-for-polylang' ),
					'site'      => __( 'No site setup problems were found in the last scan.', 'duplicate-content-addon-for-polylang' ),
					'coverage'  => __( 'Coverage is not available because Polylang languages could not be read.', 'duplicate-content-addon-for-polylang' ),
				);
				$message  = isset( $messages[ $key ] ) ? $messages[ $key ] : '';
			}

			if ( '' === $message ) {
				return;
			}
			?>
			<div class="dupcap-empty-state dupcap-panel-empty"><?php echo esc_html( $message ); ?></div>
			<?php
		}

		/**
		 * Render the URLs & Routing section.
		 *
		 * Kept above Content on purpose: a language whose pages do not load at all
		 * outranks a language whose pages merely lack translations.
		 *
		 * @since 2.2.0
		 *
		 * @param array<int, array<string, mixed>> $groups URL and routing issue groups.
		 * @param array<int, array<string, mixed>> $passed Passed URL and routing checks.
		 * @param array<string, int>               $counts Severity pill counts.
		 * @return void
		 */
		private function render_url_routing_section( array $groups, array $passed = array(), array $counts = array() ) {
			$ignored = $this->get_ignored_items_for_section( 'urls' );
			$counts  = wp_parse_args(
				$counts,
				array(
					'crit'    => 0,
					'warn'    => 0,
					'pass'    => 0,
					'ignored' => 0,
				)
			);
			$counts['ignored'] = count( $ignored );

			$has_routing_report = class_exists( 'DUPCAP_Routing_Scanner' )
				&& null !== DUPCAP_Routing_Scanner::get_saved_report();

			// Show the section before the first sweep too, so the feature is
			// discoverable — but not on single-language sites, where it never applies.
			$multilingual = function_exists( 'pll_languages_list' )
				&& count( (array) pll_languages_list() ) >= 2;

			if ( empty( $groups ) && empty( $passed ) && empty( $ignored ) && ! $has_routing_report && ! $multilingual ) {
				return;
			}

			?>
			<section class="dupcap-card dupcap-url-section" aria-labelledby="dupcap-url-title" data-dupcap-url-section>
				<div class="dupcap-section-heading">
					<div>
						<h2 id="dupcap-url-title">
							<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
							<?php esc_html_e( 'URLs & Routing', 'duplicate-content-addon-for-polylang' ); ?>
						</h2>
						<p><?php esc_html_e( 'How each language is reached, and whether those addresses actually load.', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
					<div class="dupcap-section-scan-bar">
						<button type="button" class="button button-secondary dupcap-url-test-button" data-dupcap-routing-rescan>
							<?php
							echo $has_routing_report
								? esc_html__( 'Test URLs again', 'duplicate-content-addon-for-polylang' )
								: esc_html__( 'Test URLs', 'duplicate-content-addon-for-polylang' );
							?>
						</button>
						<?php $this->render_routing_timestamp(); ?>
					</div>
				</div>
				<div class="dupcap-routing-status-slot" data-dupcap-routing-status-slot aria-live="polite"></div>
				<?php $this->render_section_filters( $counts ); ?>

				<?php
				// Criticals in this area render in Fix These First, not here, so an
				// empty card list does not mean a clean result. render_section_filters()
				// has already printed the pointer to them; claiming "no problems" on
				// top of it would contradict the count in the tab badge.
				$crit_elsewhere = isset( $counts['crit_elsewhere'] ) ? (int) $counts['crit_elsewhere'] : 0;
				?>
				<?php if ( empty( $groups ) ) : ?>
					<?php if ( $crit_elsewhere < 1 ) : ?>
						<p class="dupcap-url-empty">
							<?php
							if ( $has_routing_report ) {
								esc_html_e( 'No URL or routing problems were found in the last test.', 'duplicate-content-addon-for-polylang' );
							} else {
								esc_html_e( 'Your URLs have not been tested yet. This test loads a few pages in each language to check they work. It is not part of the main scan because it makes real requests to your site — press Test URLs when you want to run it.', 'duplicate-content-addon-for-polylang' );
							}
							?>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<div class="dupcap-card-stack" id="dupcap-url-cards">
						<?php
						foreach ( $groups as $index => $group ) {
							$this->render_issue_group( $group, 0 === $index );
						}
						?>
					</div>
				<?php endif; ?>

				<?php $this->render_passed_checks( $passed ); ?>
				<?php $this->render_ignored_issues( $ignored, 'urls' ); ?>
			</section>
			<?php
		}

		/**
		 * Render when the routing sweep last ran, so a cached result is never
		 * mistaken for a live one. Sits under the Test URLs button, where the
		 * other sections put their "last checked" line.
		 *
		 * @since 2.2.0
		 *
		 * @return void
		 */
		private function render_routing_timestamp() {
			if ( ! class_exists( 'DUPCAP_Routing_Scanner' ) ) {
				return;
			}

			$report = DUPCAP_Routing_Scanner::get_saved_report();

			if ( ! is_array( $report ) || empty( $report['time'] ) ) {
				return;
			}

			$when = DUPCAP_Scanner::format_scan_time( (int) $report['time'] );

			if ( '' === $when ) {
				return;
			}

			?>
			<p class="dupcap-section-scan-meta dupcap-url-meta">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<?php
				printf(
					/* translators: %s: formatted date and time of the last URL test. */
					esc_html__( 'URLs last tested: %s', 'duplicate-content-addon-for-polylang' ),
					esc_html( $when )
				);

				if ( ! empty( $report['targets_truncated'] ) ) {
					echo ' ';
					esc_html_e( 'Only the first 24 URLs were tested.', 'duplicate-content-addon-for-polylang' );
				}
				?>
			</p>
			<?php
		}


}
