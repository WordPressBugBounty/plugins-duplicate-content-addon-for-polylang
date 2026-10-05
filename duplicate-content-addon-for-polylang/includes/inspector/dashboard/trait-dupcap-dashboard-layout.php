<?php


defined( 'ABSPATH' ) || exit;

/**
 * Layout methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Layout_Trait {

		/**
		 * Section scan control: Scan button with last-checked time underneath.
		 *
		 * Renders inside a section heading (right side). Button first, then the
		 * clock line, matching the Content / Elementor / Site setup headers.
		 *
		 * @param string     $key    Tab key.
		 * @param array|null $report Saved main report.
		 * @return void
		 */
		private function render_section_scan_bar( $key, $report ) {
			$configs = $this->get_section_scan_config();
			if ( ! isset( $configs[ $key ] ) || ! is_array( $report ) || empty( $report['time'] ) ) {
				return;
			}

			$partial = isset( $report['partial_scans'] ) && is_array( $report['partial_scans'] ) ? $report['partial_scans'] : array();
			$when    = DUPCAP_Scanner::format_scan_time( isset( $partial[ $key ] ) ? (int) $partial[ $key ] : (int) $report['time'] );
			?>
			<div class="dupcap-section-scan-bar">
				<button type="button" class="button button-secondary dupcap-section-scan-button" data-dupcap-section-scan="<?php echo esc_attr( $key ); ?>">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<span class="dupcap-section-scan-label"><?php echo esc_html( $configs[ $key ]['button'] ); ?></span>
				</button>
				<p class="dupcap-section-scan-meta">
					<span class="dashicons dashicons-clock" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: formatted date and time this section was last checked. */
						esc_html__( 'Last checked: %s', 'duplicate-content-addon-for-polylang' ),
						esc_html( $when )
					);
					?>
				</p>
				<p class="dupcap-section-scan-error" data-dupcap-section-scan-error="<?php echo esc_attr( $key ); ?>" role="alert" hidden></p>
			</div>
			<?php
		}

		/**
		 * Why every Scan Website button is disabled right now.
		 *
		 * Shown as the button title and beside it, so a disabled button always
		 * says what to do instead.
		 *
		 * @since 2.3.0
		 *
		 * @return string
		 */
		private function scan_blocked_reason() {
			if ( ! $this->has_enough_languages() ) {
				return __( 'Add at least two languages in Polylang first, then scan.', 'duplicate-content-addon-for-polylang' );
			}

			return __( 'Set the missing languages first, then scan.', 'duplicate-content-addon-for-polylang' );
		}

		/**
		 * Whether Polylang has enough languages for there to be anything to check.
		 *
		 * Every check compares one language against another, so below two there
		 * is nothing any of them can find - and the scan would still crawl the
		 * whole site to report that nothing. One language is not a multilingual
		 * site; it is a site nobody has set up yet.
		 *
		 * @since 2.3.0
		 *
		 * @return bool
		 */
		private function has_enough_languages() {
			if ( ! function_exists( 'pll_languages_list' ) ) {
				return false;
			}

			$languages = array_filter( (array) pll_languages_list( array( 'fields' => 'slug' ) ) );

			return count( $languages ) >= 2;
		}

		/**
		 * Why a scan cannot run, for the AJAX handlers to refuse with.
		 *
		 * The button is disabled in the page, but a request can still arrive -
		 * from a tab left open since before the languages were removed, or from
		 * anyone who cares to send one.
		 *
		 * @since 2.3.0
		 *
		 * @return string Empty when a scan may run.
		 */
		private function scan_refusal_message() {
			if ( $this->has_enough_languages() ) {
				return '';
			}

			return __( 'Add at least two languages in Polylang before scanning.', 'duplicate-content-addon-for-polylang' );
		}

		/**
		 * Render header wrapper.
		 *
		 * @param array<int, array<string, mixed>> $results Results.
		 * @return void
		 */
		private function render_header( array $results ) {
			?>
			<div id="dupcap-dashboard-header" class="dupcap-dashboard-header">
				<?php $this->render_header_inner( $results ); ?>
			</div>
			<?php
		}

		/**
		 * Render header contents — summary card with health, counts, and scan meta.
		 *
		 * @param array<int, array<string, mixed>> $results Results.
		 * @return void
		 */
		private function render_header_inner( array $results ) {
			$saved            = DUPCAP_Scanner::get_saved_report();
			$last_scanned     = ! empty( $saved['time'] ) ? DUPCAP_Scanner::format_scan_time( $saved['time'] ) : '';
			$issue_groups     = $this->build_issue_groups( $results );
			$issue_groups     = $this->remove_front_page_from_content_groups( $issue_groups );
			$counts           = $this->get_counts( $results, $issue_groups );
			$elementor_counts = $this->get_elementor_badge_counts();
			$counts['crit']  += $elementor_counts['crit'];
			$counts['warn']  += $elementor_counts['warn'];
			$counts['ignored'] = $this->count_ignored_warnings();
			$coverage         = $this->get_language_coverage( $results );
			$health_pillars   = $this->get_health_pillars( $results, $coverage );
			$health_score     = $this->get_health_score( $health_pillars );

			// Content with no language skews every count, so the scan waits until
			// "Set to default language" has been run. Same condition as the notice.
			// Below two languages there is nothing to compare at all, so that
			// blocks it too, and says so instead.
			$scan_blocked        = ! $this->has_enough_languages() || $this->has_objects_without_language();
			$scan_blocked_reason = $this->scan_blocked_reason();

			if ( ! DUPCAP_Scanner::has_saved_scan() ) {
				$health_pillars = array(
					'coverage' => 0,
					'setup'    => 0,
					'builder'  => 0,
					'routing'  => 0,
				);
				$health_score = 0;
				$counts       = array(
					'pass'    => 0,
					'warn'    => 0,
					'crit'    => 0,
					'ignored' => $counts['ignored'],
				);
			}

			/*
			 * Nothing else is worth saying yet. Untranslatable post types, a
			 * missing switcher, an unsupported builder - all of it is advice
			 * about translating a site that has no second language to translate
			 * into, stacked above the one notice that matters.
			 */
			if ( $this->has_enough_languages() ) {
				$this->render_nolang_notice();
				$this->render_untranslated_types_notice();
				$this->render_elementor_addons_notice( $results );
				$this->render_unsupported_builder_notice();
			} else {
				$this->render_languages_required_notice();
			}
			?>
			<section class="dupcap-summary-card">
				<div class="dupcap-page-header">
					<div class="dupcap-summary-branding">
						<span class="dupcap-brand-mark" aria-hidden="true">
							<svg viewBox="0 0 40 48" width="40" height="48" focusable="false">
								<path fill="#1e293b" d="M20 2.5C14.2 6.4 8.6 8.4 2.5 9.2V24H20V2.5z"/>
								<path fill="#ffffff" stroke="#1e293b" stroke-width="0.8" d="M20 2.5c5.8 3.9 11.4 5.9 17.5 6.7V24H20V2.5z"/>
								<path fill="#ffffff" stroke="#1e293b" stroke-width="0.8" d="M2.5 24C2.5 34.8 9.6 42.3 20 45.7V24H2.5z"/>
								<path fill="#1e293b" d="M37.5 24C37.5 34.8 30.4 42.3 20 45.7V24h17.5z"/>
								<path fill="none" stroke="#1e293b" stroke-width="1.8" d="M20 2.5C14.2 6.4 8.6 8.4 2.5 9.2v14.6c0 10.8 7.1 18.3 17.5 21.7 10.4-3.4 17.5-10.9 17.5-21.7V9.2C31.4 8.4 25.8 6.4 20 2.5z"/>
								<path fill="none" stroke="#1e293b" stroke-width="1.5" d="M20 2.5V45.7M2.5 24h35"/>
							</svg>
						</span>
						<div class="dupcap-summary-branding-text">
							<h1 class="dupcap-title">
								<?php esc_html_e( 'Translation Inspector', 'duplicate-content-addon-for-polylang' ); ?>
							</h1>
							<p class="dupcap-sub"><?php esc_html_e( 'Find and fix translation issues across your multilingual website.', 'duplicate-content-addon-for-polylang' ); ?></p>
						</div>
					</div>

					<div class="dupcap-header-actions">
						<span class="dupcap-scan-cta<?php echo DUPCAP_Scanner::has_saved_scan() ? '' : ' is-prompt'; ?><?php echo $scan_blocked ? ' is-blocked' : ''; ?>">
							<span class="dupcap-scan-cta__ring" aria-hidden="true"></span>
							<span class="dupcap-scan-cta__ring dupcap-scan-cta__ring--delay" aria-hidden="true"></span>
							<button
								type="button"
								class="button button-primary dupcap-rescan-button"
								id="dupcap-scan-btn"
								data-dupcap-rescan
								<?php disabled( $scan_blocked, true ); ?>
								<?php if ( $scan_blocked ) : ?>
									title="<?php echo esc_attr( $scan_blocked_reason ); ?>"
								<?php endif; ?>
							>
								<span class="dupcap-scan-cta__shine" aria-hidden="true"></span>
								<img
									class="dupcap-scan-icon dupcap-scan-icon--button"
									src="<?php echo esc_url( DUPCAP_Admin::scan_icon_url() ); ?>"
									width="16"
									height="16"
									alt=""
									decoding="async"
									aria-hidden="true"
								/>
								<span class="dupcap-rescan-label"><?php esc_html_e( 'Scan Website', 'duplicate-content-addon-for-polylang' ); ?></span>
							</button>
						</span>
						<?php if ( $scan_blocked ) : ?>
							<p class="dupcap-scan-blocked-hint"><?php echo esc_html( $scan_blocked_reason ); ?></p>
						<?php endif; ?>
						<p class="dupcap-last-scan">
							<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
							<?php
							printf(
								/* translators: %s: date and time of the last scan, or "never". */
								esc_html__( 'Last scan: %s', 'duplicate-content-addon-for-polylang' ),
								$last_scanned
									? esc_html( $last_scanned )
									: esc_html__( 'never', 'duplicate-content-addon-for-polylang' )
							);
							?>
						</p>
					</div>
				</div>

				<div class="dupcap-summary-body">
					<div class="dupcap-summary-health">
						<?php $this->render_health_score_rings( $health_score ); ?>
					</div>

					<?php if ( DUPCAP_Scanner::has_saved_scan() ) : ?>
					<div class="dupcap-summary-counts" aria-label="<?php esc_attr_e( 'Scan counts', 'duplicate-content-addon-for-polylang' ); ?>">
						<?php $this->render_summary_count( 'crit', __( 'Critical', 'duplicate-content-addon-for-polylang' ), $counts['crit'], 'dismiss', __( 'Important', 'duplicate-content-addon-for-polylang' ) ); ?>
						<?php $this->render_summary_count( 'warn', __( 'Warnings', 'duplicate-content-addon-for-polylang' ), $counts['warn'], 'warning', __( 'Needs attention', 'duplicate-content-addon-for-polylang' ) ); ?>
						<?php $this->render_summary_count( 'pass', __( 'Passed', 'duplicate-content-addon-for-polylang' ), $counts['pass'], 'yes-alt', __( 'Good', 'duplicate-content-addon-for-polylang' ) ); ?>
						<?php $this->render_summary_count( 'ignored', __( 'Ignored', 'duplicate-content-addon-for-polylang' ), isset( $counts['ignored'] ) ? $counts['ignored'] : 0, 'hidden', __( 'Dismissed', 'duplicate-content-addon-for-polylang' ) ); ?>
					</div>
					<?php endif; ?>

				</div>

			</section>
			<?php
		}

		/**
		 * Render one Passed / Warnings / Critical counter.
		 *
		 * @param string $severity Severity key.
		 * @param string $label    Label.
		 * @param int    $count    Count.
		 * @param string $icon     Dashicon name.
		 * @return void
		 */
		private function render_summary_count( $severity, $label, $count, $icon, $sub_label = '' ) {
			?>
			<div class="dupcap-summary-count dupcap-summary-count-<?php echo esc_attr( $severity ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<span class="dupcap-summary-count-label"><?php echo esc_html( $label ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
				<?php if ( '' !== $sub_label ) : ?>
					<span class="dupcap-summary-count-sub"><?php echo esc_html( $sub_label ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render the report as a sticky section nav plus one panel per area.
		 *
		 * Issues stay grouped by issue type inside each panel, never by language.
		 * Critical groups render once, in Fix These First on Overview; the other
		 * panels point to them instead of repeating the card.
		 *
		 * @param array<int, array<string, mixed>> $results Results.
		 * @return void
		 */
		private function render_dashboard_body( array $results ) {
			$issue_groups   = $this->build_issue_groups( $results );
			$issue_groups   = $this->remove_front_page_from_content_groups( $issue_groups );
			$passed_checks  = $this->filter_results_by_severity( $results, 'pass' );
			$environment    = $this->get_environment_summary();
			$coverage       = $this->get_language_coverage( $results );
			$health_pillars = $this->get_health_pillars( $results, $coverage );
			$split          = $this->split_issue_groups_for_display( $issue_groups );
			$sections       = $this->split_remaining_groups_by_section( $split['remaining'] );
			$critical       = $this->split_remaining_groups_by_section( $split['priority'] );

			$has_scan      = DUPCAP_Scanner::has_saved_scan();
			$saved_report  = DUPCAP_Scanner::get_saved_report();
			$partial_scans = ( $has_scan && ! empty( $saved_report['partial_scans'] ) && is_array( $saved_report['partial_scans'] ) ) ? $saved_report['partial_scans'] : array();
			$tabs          = $this->get_dashboard_tabs( $sections, $critical, $has_scan );

			// Passed checks sit with their own area, like the issue cards. Without
			// an Elementor tab, Elementor passes fall back to Site setup.
			$passed = $this->split_passed_checks_by_section( $passed_checks );
			if ( ! isset( $tabs['elementor'] ) ) {
				$passed['other']     = array_merge( $passed['other'], $passed['elementor'] );
				$passed['elementor'] = array();
			}

			?>
			<div class="dupcap-report-stack">
				<?php $this->render_section_nav( $tabs ); ?>
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<?php
					ob_start();
					switch ( $key ) {
						case 'overview':
							$this->render_overview_panel( $health_pillars, $environment, $split['priority'], $has_scan, $partial_scans );
							break;
						case 'urls':
							$this->render_url_routing_section( $sections['urls'], $passed['urls'], $this->get_section_severity_counts( 'urls', $sections, $critical, $passed ) );
							break;
						case 'content':
							$this->render_content_section( $sections['content'], $passed['content'], $this->get_section_severity_counts( 'content', $sections, $critical, $passed ) );
							break;
						case 'elementor':
							$this->render_elementor_insights_section( $sections['elementor'], $passed['elementor'], $this->get_section_severity_counts( 'elementor', $sections, $critical, $passed ) );
							break;
						case 'site':
							$this->render_site_setup_section( $sections['other'], $passed['other'], $this->get_section_severity_counts( 'other', $sections, $critical, $passed ) );
							break;
						case 'coverage':
							$this->render_coverage_card( $coverage );
							break;
					}
					$panel_html     = trim( (string) ob_get_clean() );
					$critical_count = '' !== $tab['section'] ? count( $critical[ $tab['section'] ] ) : 0;
					?>
					<div
						id="dupcap-panel-<?php echo esc_attr( $key ); ?>"
						class="dupcap-tab-panel"
						role="tabpanel"
						aria-labelledby="dupcap-nav-tab-<?php echo esc_attr( $key ); ?>"
						data-dupcap-tab-panel="<?php echo esc_attr( $key ); ?>"
						tabindex="0"
						<?php echo 'overview' === $key ? '' : 'hidden'; ?>
					>
						<?php
						// Content / Elementor / Site put the scan control in their
						// section heading. Empty panels still need the control here.
						$inline_scan_keys = array( 'content', 'elementor', 'site' );
						if ( ! in_array( $key, $inline_scan_keys, true ) || '' === $panel_html ) {
							$this->render_section_scan_bar( $key, $saved_report );
						}

						if ( '' !== $panel_html ) {
							echo $panel_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by each section renderer above.
						} else {
							$this->render_panel_empty_state( $key, $has_scan, $critical_count > 0 );
						}
						?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/**
		 * Report tabs in display order.
		 *
		 * Count is issue types (cards and Elementor buckets), not rows — used
		 * only for screen-reader text when a section has issues. Tone is the
		 * worst severity in that tab (crit > warn > info). `section` is the
		 * key used by split_remaining_groups_by_section(); empty for tabs
		 * without issue cards.
		 *
		 * @param array<string, array<int, array<string, mixed>>> $sections Non-critical groups by section.
		 * @param array<string, array<int, array<string, mixed>>> $critical Critical groups by section.
		 * @param bool                                             $has_scan Whether a saved scan exists.
		 * @return array<string, array{label: string, icon: string, section: string, count: int, tone: string}>
		 */
		private function get_dashboard_tabs( array $sections, array $critical, $has_scan ) {
			$tabs = array(
				'overview'  => array(
					'label'   => __( 'Overview', 'duplicate-content-addon-for-polylang' ),
					'icon'    => 'dashboard',
					'section' => '',
				),
				'urls'      => array(
					'label'   => __( 'URLs & Routing', 'duplicate-content-addon-for-polylang' ),
					'icon'    => 'admin-links',
					'section' => 'urls',
				),
				'content'   => array(
					'label'   => __( 'Content', 'duplicate-content-addon-for-polylang' ),
					'icon'    => 'admin-page',
					'section' => 'content',
				),
				'elementor' => array(
					'label'   => __( 'Elementor', 'duplicate-content-addon-for-polylang' ),
					'icon'    => 'layout',
					'section' => 'elementor',
				),
				'site'      => array(
					'label'   => __( 'Site Setup', 'duplicate-content-addon-for-polylang' ),
					'icon'    => 'admin-settings',
					'section' => 'other',
				),
				'coverage'  => array(
					'label'   => __( 'Coverage', 'duplicate-content-addon-for-polylang' ),
					'icon'    => 'chart-bar',
					'section' => '',
				),
			);

			$elementor_available = class_exists( 'DUPCAP_Elementor_Scanner' ) && DUPCAP_Elementor_Scanner::is_elementor_available();
			if ( ! $elementor_available && empty( $sections['elementor'] ) && empty( $critical['elementor'] ) ) {
				unset( $tabs['elementor'] );
			}

			$weight = array(
				'info' => 1,
				'warn' => 2,
				'crit' => 3,
			);

			foreach ( $tabs as $key => $tab ) {
				$severities = array();

				if ( 'overview' === $key ) {
					foreach ( $critical as $section_groups ) {
						$severities = array_merge( $severities, wp_list_pluck( $section_groups, 'severity' ) );
					}
				} elseif ( '' !== $tab['section'] ) {
					$severities = array_merge(
						wp_list_pluck( $critical[ $tab['section'] ], 'severity' ),
						wp_list_pluck( $sections[ $tab['section'] ], 'severity' )
					);
				}

				if ( 'elementor' === $key ) {
					$insights = $this->get_elementor_insights();
					if (
						! empty( $insights['visible'] )
						&& isset( $insights['status'] ) && 'pass' !== $insights['status']
						&& ! empty( $insights['buckets'] ) && is_array( $insights['buckets'] )
					) {
						foreach ( $insights['buckets'] as $bucket ) {
							$is_crit      = isset( $bucket['severity'] ) && DUPCAP_Elementor_Finding::CRITICAL === $bucket['severity'];
							$severities[] = $is_crit ? 'crit' : 'warn';
						}
					}
				}

				$tone = 'warn';
				$top  = 0;
				foreach ( $severities as $severity ) {
					if ( isset( $weight[ $severity ] ) && $weight[ $severity ] > $top ) {
						$top  = $weight[ $severity ];
						$tone = $severity;
					}
				}

				$tabs[ $key ]['count'] = $has_scan ? count( $severities ) : 0;
				$tabs[ $key ]['tone']  = $tone;
			}

			return $tabs;
		}

		/**
		 * Render the sticky report section nav (ARIA tabs).
		 *
		 * @param array<string, array<string, mixed>> $tabs Tabs from get_dashboard_tabs().
		 * @return void
		 */
		private function render_section_nav( array $tabs ) {
			?>
			<nav class="dupcap-section-nav" aria-label="<?php esc_attr_e( 'Report sections', 'duplicate-content-addon-for-polylang' ); ?>">
				<div class="dupcap-section-nav-list" role="tablist" aria-label="<?php esc_attr_e( 'Report sections', 'duplicate-content-addon-for-polylang' ); ?>">
					<?php foreach ( $tabs as $key => $tab ) : ?>
						<?php $is_active = 'overview' === $key; ?>
						<button
							type="button"
							id="dupcap-nav-tab-<?php echo esc_attr( $key ); ?>"
							class="dupcap-nav-tab<?php echo $is_active ? ' is-active' : ''; ?>"
							role="tab"
							aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
							aria-controls="dupcap-panel-<?php echo esc_attr( $key ); ?>"
							tabindex="<?php echo $is_active ? '0' : '-1'; ?>"
							data-dupcap-nav-tab="<?php echo esc_attr( $key ); ?>"
						>
							<span class="dashicons dashicons-<?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
							<span class="dupcap-nav-tab-label"><?php echo esc_html( $tab['label'] ); ?></span>
							<?php if ( $tab['count'] > 0 ) : ?>
								<span class="screen-reader-text">
									<?php
									printf(
										/* translators: %d: number of issue types in this report section. */
										esc_html( _n( '%d issue type needs attention', '%d issue types need attention', $tab['count'], 'duplicate-content-addon-for-polylang' ) ),
										(int) $tab['count']
									);
									?>
								</span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</div>
			</nav>
			<?php
		}

		/**
		 * Overview panel: health pillars, environment, nolang notice, Fix These First.
		 *
		 * @param array<string, int>               $pillars         Health pillars.
		 * @param array<string, mixed>             $environment     Environment rows.
		 * @param array<int, array<string, mixed>> $priority_groups Critical issue groups.
		 * @param bool                             $has_scan        Whether a saved scan exists.
		 * @param array<string, int>               $partial_scans   Tab key => time of its last section scan.
		 * @return void
		 */
		private function render_overview_panel( array $pillars, array $environment, array $priority_groups, $has_scan, array $partial_scans = array() ) {
			// Elementor deep findings count toward the header's Critical total but
			// render on the Elementor tab, not in Fix These First.
			$elementor_crit = $has_scan ? $this->get_elementor_badge_counts()['crit'] : 0;

			$this->render_partial_scan_notice( $partial_scans );
			?>
			<div class="dupcap-top-grid">
				<?php
				// Before the first scan the pillars would all read 0%, which looks
				// like a broken site rather than an unscanned one.
				if ( $has_scan ) {
					$this->render_health_breakdown_card( $pillars );
				} else {
					$this->render_first_scan_card();
				}
				$this->render_environment_card( $environment );
				?>
			</div>

			<?php if ( ! empty( $priority_groups ) ) : ?>
				<?php $this->render_fix_order( $priority_groups ); ?>
			<?php elseif ( $has_scan && 0 === $elementor_crit ) : ?>
				<section class="dupcap-card dupcap-overview-clear" aria-labelledby="dupcap-overview-clear-title">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<div>
						<h2 id="dupcap-overview-clear-title"><?php esc_html_e( 'No critical issues', 'duplicate-content-addon-for-polylang' ); ?></h2>
						<p><?php esc_html_e( 'Nothing needs urgent attention. Open the other tabs to review warnings.', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $elementor_crit > 0 ) : ?>
				<div class="dupcap-critical-pointer">
					<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
					<p>
						<?php
						printf(
							/* translators: %d: number of critical Elementor findings. */
							esc_html( _n( '%d critical Elementor finding is listed on the Elementor tab.', '%d critical Elementor findings are listed on the Elementor tab.', $elementor_crit, 'duplicate-content-addon-for-polylang' ) ),
							(int) $elementor_crit
						);
						?>
					</p>
					<button type="button" class="button button-secondary" data-dupcap-goto-tab="elementor">
						<?php esc_html_e( 'Open Elementor', 'duplicate-content-addon-for-polylang' ); ?>
					</button>
				</div>
			<?php endif; ?>
			<?php
		}

		/**
		 * First-run card shown on Overview instead of 0% health pillars.
		 *
		 * @return void
		 */
		private function render_first_scan_card() {
			?>
			<section class="dupcap-card dupcap-first-scan" aria-labelledby="dupcap-first-scan-title">
				<div class="dupcap-section-heading">
					<div>
						<h2 id="dupcap-first-scan-title">
							<span class="dashicons dashicons-search" aria-hidden="true"></span>
							<?php esc_html_e( 'Run your first scan', 'duplicate-content-addon-for-polylang' ); ?>
						</h2>
						<p><?php esc_html_e( 'Scan your site to check languages, URLs, content, menus, translation settings, and Elementor templates. Your health score and any issues found will appear when the scan is complete.', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
				</div>
				<p class="dupcap-first-scan-note"><?php esc_html_e( 'It only scans your site, no changes are made to any content or settings.', 'duplicate-content-addon-for-polylang' ); ?></p>
				<div class="dupcap-card-actions">
					<?php $scan_blocked = ! $this->has_enough_languages() || $this->has_objects_without_language(); ?>
					<button
						type="button"
						class="button button-primary"
						data-dupcap-rescan
						<?php disabled( $scan_blocked, true ); ?>
						<?php if ( $scan_blocked ) : ?>
							title="<?php echo esc_attr( $this->scan_blocked_reason() ); ?>"
						<?php endif; ?>
					>
						<span class="dupcap-rescan-label"><?php esc_html_e( 'Scan Website', 'duplicate-content-addon-for-polylang' ); ?></span>
					</button>
				</div>
			</section>
			<?php
		}

		/**
		 * Render the recommended fix order.
		 *
		 * @param array<int, array<string, mixed>> $priority_groups Top-priority issue groups.
		 * @return void
		 */
		private function render_fix_order( array $priority_groups ) {
			if ( empty( $priority_groups ) ) {
				return;
			}

			?>
			<section class="dupcap-card dupcap-fix-order" id="dupcap-fix-order" aria-labelledby="dupcap-fix-order-title">
				<div class="dupcap-section-heading">
					<div>
						<h2 id="dupcap-fix-order-title"><?php esc_html_e( 'Fix These First', 'duplicate-content-addon-for-polylang' ); ?></h2>
						<p><?php esc_html_e( 'Critical issues that are most likely to break the visitor experience.', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
				</div>
				<div class="dupcap-card-stack">
					<?php
					foreach ( $priority_groups as $index => $group ) {
						// Why / how to fix is what Fix These First adds over the tab
						// card; the card itself is the same one every tab renders, so
						// it opens and closes like the others.
						ob_start();
						?>
						<div class="dupcap-fix-why">
							<p>
								<strong><?php esc_html_e( 'Why this matters', 'duplicate-content-addon-for-polylang' ); ?></strong><br>
								<?php echo esc_html( $this->get_fix_reason( $group ) ); ?>
							</p>
							<?php $howto = DUPCAP_Issue_Copy::group_howto( $group['dedupe_key'] ); ?>
							<?php if ( '' !== $howto ) : ?>
								<p>
									<strong><?php esc_html_e( 'How to fix', 'duplicate-content-addon-for-polylang' ); ?></strong><br>
									<?php echo esc_html( $howto ); ?>
								</p>
							<?php endif; ?>
						</div>
						<?php
						$this->render_issue_group( $group, 0 === $index, (string) ob_get_clean() );
					}
					?>
				</div>
			</section>
			<?php
		}


}
