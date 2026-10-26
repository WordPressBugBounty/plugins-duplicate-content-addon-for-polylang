<?php


defined( 'ABSPATH' ) || exit;

/**
 * Health methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Health_Trait {

		/**
		 * Render translation health breakdown (pillar progress bars).
		 *
		 * @param array{coverage:int,setup:int,builder:int,routing:int} $pillars Score pillars.
		 * @return void
		 */
		private function render_health_breakdown_card( array $pillars = array() ) {
			$pillars = wp_parse_args(
				$pillars,
				array(
					'coverage' => 100,
					'setup'    => 100,
					'builder'  => 100,
					'routing'  => 100,
				)
			);

			$elementor_active = class_exists( 'DUPCAP_Elementor_Scanner' )
				&& DUPCAP_Elementor_Scanner::is_elementor_available();

			$rows = array(
				'coverage' => array(
					'icon'      => 'media-document',
					'label'     => __( 'Content', 'duplicate-content-addon-for-polylang' ),
					'ok'        => __( 'All important content is translated.', 'duplicate-content-addon-for-polylang' ),
					'needs'     => __( 'Some posts or pages still need translation work.', 'duplicate-content-addon-for-polylang' ),
					'score'     => (int) $pillars['coverage'],
					'tab'       => 'content',
					'tab_label' => __( 'Review content', 'duplicate-content-addon-for-polylang' ),
				),
				'setup'    => array(
					'icon'      => 'admin-site-alt3',
					'label'     => __( 'Site setup', 'duplicate-content-addon-for-polylang' ),
					'ok'        => __( 'Languages, menus, and homepage setup look healthy.', 'duplicate-content-addon-for-polylang' ),
					'needs'     => __( 'Language, menu, or homepage setup needs attention.', 'duplicate-content-addon-for-polylang' ),
					'score'     => (int) $pillars['setup'],
					'tab'       => 'site',
					'tab_label' => __( 'Review setup', 'duplicate-content-addon-for-polylang' ),
				),
			);

			if ( $elementor_active ) {
				$rows['builder'] = array(
					'icon'      => 'layout',
					'label'     => __( 'Elementor Integration', 'duplicate-content-addon-for-polylang' ),
					'ok'        => __( 'Headers, footers, and Elementor templates look healthy.', 'duplicate-content-addon-for-polylang' ),
					'needs'     => __( 'Some Elementor templates or widgets need translation fixes.', 'duplicate-content-addon-for-polylang' ),
					'score'     => (int) $pillars['builder'],
					'tab'       => 'elementor',
					'tab_label' => __( 'Review Elementor', 'duplicate-content-addon-for-polylang' ),
				);
			}

			$rows['routing'] = array(
				'icon'      => 'admin-links',
				'label'     => __( 'URLs & Routing', 'duplicate-content-addon-for-polylang' ),
				'ok'        => __( 'Language URLs and routing look healthy.', 'duplicate-content-addon-for-polylang' ),
				'needs'     => __( 'Some language URLs, slugs, or routing still need attention.', 'duplicate-content-addon-for-polylang' ),
				'score'     => (int) $pillars['routing'],
				'tab'       => 'urls',
				'tab_label' => __( 'Review URLs', 'duplicate-content-addon-for-polylang' ),
			);
			?>
			<section class="dupcap-card dupcap-breakdown-card" aria-labelledby="dupcap-breakdown-title">
				<h2 id="dupcap-breakdown-title">
					<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
					<?php esc_html_e( 'Translation Health Breakdown', 'duplicate-content-addon-for-polylang' ); ?>
				</h2>
				<ul class="dupcap-breakdown-list">
					<?php foreach ( $rows as $key => $row ) : ?>
						<?php
						$score = max( 0, min( 100, (int) $row['score'] ) );
						$tone  = $score >= 90 ? 'ok' : ( $score >= 70 ? 'mid' : 'low' );
						?>
						<li class="dupcap-breakdown-item dupcap-breakdown-<?php echo esc_attr( $key ); ?> dupcap-breakdown-tone-<?php echo esc_attr( $tone ); ?>">
							<span class="dupcap-breakdown-icon dashicons dashicons-<?php echo esc_attr( $row['icon'] ); ?>" aria-hidden="true"></span>
							<div class="dupcap-breakdown-copy">
								<div class="dupcap-breakdown-main">
									<div class="dupcap-breakdown-text">
										<strong class="dupcap-breakdown-title"><?php echo esc_html( $row['label'] ); ?></strong>
										<span class="dupcap-breakdown-desc"><?php echo esc_html( $score >= 90 ? $row['ok'] : $row['needs'] ); ?></span>
									</div>
									<div class="dupcap-breakdown-meta">
										<span class="dupcap-breakdown-score"><?php echo esc_html( number_format_i18n( $score ) ); ?>%</span>
										<?php if ( ! empty( $row['tab'] ) && ! empty( $row['tab_label'] ) ) : ?>
											<button
												type="button"
												class="dupcap-breakdown-goto"
												data-dupcap-goto-tab="<?php echo esc_attr( $row['tab'] ); ?>"
											>
												<?php echo esc_html( $row['tab_label'] ); ?>
											</button>
										<?php endif; ?>
									</div>
								</div>
								<div class="dupcap-breakdown-bar" aria-hidden="true">
									<span style="width: <?php echo esc_attr( (string) $score ); ?>%;"></span>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php
		}

		/**
		 * Render three score rings plus the overall score.
		 *
		 * @param int $health_score Overall score.
		 * @return void
		 */
		private function render_health_score_rings( $health_score ) {
			$health_class  = $this->get_health_class( $health_score );
			$grade_icon    = in_array( $health_class, array( 'excellent', 'good' ), true ) ? 'yes' : ( 'risk' === $health_class ? 'dismiss' : 'warning' );
			$percent       = max( 0, min( 100, (int) $health_score ) );
			$radius        = 54;
			$circumference = 2 * M_PI * $radius;
			$offset        = $circumference * ( 1 - ( $percent / 100 ) );
			?>
			<div class="dupcap-health-rings">

			<div class="dupcap-summary-overall">
					<span class="dupcap-summary-overall-label"><?php esc_html_e( 'Overall Score', 'duplicate-content-addon-for-polylang' ); ?></span>

					<div class="dupcap-summary-overall-content">
						<div class="dupcap-overall-ring">
							<svg class="dupcap-overall-ring-svg" viewBox="0 0 120 120" width="120" height="120" aria-hidden="true">
								<circle class="dupcap-overall-ring-track" cx="60" cy="60" r="<?php echo esc_attr( (string) $radius ); ?>" fill="none" stroke-width="10"></circle>
								<circle
									class="dupcap-overall-ring-progress dupcap-score-<?php echo esc_attr( $health_class ); ?>"
									cx="60" cy="60" r="<?php echo esc_attr( (string) $radius ); ?>"
									fill="none" stroke-width="10" stroke-linecap="round"
									stroke-dasharray="<?php echo esc_attr( (string) $circumference ); ?>"
									stroke-dashoffset="<?php echo esc_attr( (string) $offset ); ?>"
									transform="rotate(-90 60 60)"
								></circle>
							</svg>
							<span class="dupcap-overall-ring-value"><?php echo esc_html( number_format_i18n( $percent ) ); ?>%</span>
						</div>

						<div class="dupcap-overall-grade-block">
							<span class="dupcap-summary-grade dupcap-score-<?php echo esc_attr( $health_class ); ?>">
								<span class="dupcap-summary-grade-icon" aria-hidden="true">
									<span class="dashicons dashicons-<?php echo esc_attr( $grade_icon ); ?>"></span>
								</span>
								<?php echo esc_html( $this->get_health_label( $health_score ) ); ?>
							</span>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render translation coverage table.
		 *
		 * @param array<int, array<string, mixed>> $coverage Coverage rows.
		 * @return void
		 */
		private function render_coverage_card( array $coverage ) {
			if ( empty( $coverage ) ) {
				return;
			}
			?>
			<section class="dupcap-card dupcap-coverage-card" aria-labelledby="dupcap-coverage-title">
				<div class="dupcap-section-heading">
					<div>
						<h2 id="dupcap-coverage-title">
							<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
							<?php esc_html_e( 'Translation Coverage', 'duplicate-content-addon-for-polylang' ); ?>
						</h2>
						<p><?php esc_html_e( 'How complete each language is. Counts posts, pages, custom post types, categories and tags linked in Polylang. Menus and strings are not counted here; they are checked in Site setup.', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
				</div>
				<ul class="dupcap-coverage-list">
					<li class="dupcap-coverage-list-head" aria-hidden="true">
						<span><?php esc_html_e( 'Language', 'duplicate-content-addon-for-polylang' ); ?></span>
						<span><?php esc_html_e( 'Coverage', 'duplicate-content-addon-for-polylang' ); ?></span>
					</li>
					<?php foreach ( $coverage as $row ) : ?>
						<?php
						$percent = isset( $row['coverage'] ) ? (int) $row['coverage'] : 0;
						$percent = max( 0, min( 100, $percent ) );
						$tone    = $percent >= 90 ? 'ok' : ( $percent >= 70 ? 'mid' : 'low' );
						$lang    = isset( $row['language'] ) ? (string) $row['language'] : '';
						$slug    = isset( $row['slug'] ) ? (string) $row['slug'] : '';
						if ( '' === $slug && '' !== $lang ) {
							$slug = $lang;
						}
						$flag_html = $this->get_language_flag_html( $slug );
						?>
						<li class="dupcap-coverage-row dupcap-coverage-tone-<?php echo esc_attr( $tone ); ?>">
							<span class="dupcap-coverage-lang">
								<span class="dupcap-coverage-lang-flag" aria-hidden="true">
									<?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in get_language_flag_html(). ?>
								</span>
								<strong><?php echo esc_html( $lang ); ?></strong>
							</span>
							<span class="dupcap-coverage-meter-wrap">
								<span class="dupcap-coverage-meter" aria-hidden="true">
									<span style="width: <?php echo esc_attr( (string) $percent ); ?>%"></span>
								</span>
								<strong class="dupcap-coverage-score"><?php echo esc_html( number_format_i18n( $percent ) ); ?>%</strong>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php $this->render_optin_card(); ?>
			<?php
		}

		/**
		 * Build Translation Health pillars: coverage, setup, builder, routing.
		 *
		 * @param array<int, array<string, mixed>> $results  Normalized scan results.
		 * @param array<int, array<string, mixed>> $coverage Language coverage rows.
		 * @return array{coverage:int,setup:int,builder:int,routing:int}
		 */
		private function get_health_pillars( array $results, array $coverage ) {
			return array(
				'coverage' => $this->get_coverage_score( $coverage, $results ),
				'setup'    => $this->get_setup_score( $results ),
				'builder'  => $this->get_builder_score( $results ),
				'routing'  => $this->get_routing_score( $results ),
			);
		}

		/**
		 * Weighted Translation Health score.
		 *
		 * With Elementor: Coverage 50% · Setup 30% · Builder 20%.
		 * Without Elementor: Coverage and Setup only, keeping the same relative weights.
		 *
		 * @param array{coverage?:int,setup?:int,builder?:int} $pillars Score pillars.
		 * @return int
		 */
		private function get_health_score( array $pillars ) {
			$pillars = wp_parse_args(
				$pillars,
				array(
					'coverage' => 100,
					'setup'    => 100,
					'builder'  => 100,
				)
			);

			$elementor_active = class_exists( 'DUPCAP_Elementor_Scanner' )
				&& DUPCAP_Elementor_Scanner::is_elementor_available();

			if ( $elementor_active ) {
				$score = ( 0.50 * (int) $pillars['coverage'] )
					+ ( 0.30 * (int) $pillars['setup'] )
					+ ( 0.20 * (int) $pillars['builder'] );
			} else {
				// Redistribute builder weight across Content + Language Setup.
				$score = ( 0.625 * (int) $pillars['coverage'] )
					+ ( 0.375 * (int) $pillars['setup'] );
			}

			return (int) max( 0, min( 100, round( $score ) ) );
		}

		/**
		 * Average secondary-language coverage percentage, penalized for content
		 * that IS linked but is wrong (broken links, identical/untranslated text).
		 *
		 * Skips one default-language 100% row when present so incomplete
		 * secondary languages drive the coverage pillar.
		 *
		 * @param array<int, array<string, mixed>> $coverage Coverage rows.
		 * @param array<int, array<string, mixed>> $results  Normalized scan results.
		 * @return int
		 */
		private function get_coverage_score( array $coverage, array $results = array() ) {
			// Gaps the user dismissed never appear in $results, so a clean report
			// means full score here — even if Polylang still has fewer translations.
			if ( ! $this->results_have_open_content_gaps( $results ) ) {
				return (int) max( 0, min( 100, 100 - $this->get_content_quality_penalty( $results ) ) );
			}

			$scores              = array();
			$skipped_default_row = false;
			$row_count           = count( $coverage );

			foreach ( $coverage as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['coverage'] ) ) {
					continue;
				}

				$percent = max( 0, min( 100, (int) $row['coverage'] ) );

				if ( ! $skipped_default_row && 100 === $percent && $row_count > 1 ) {
					$skipped_default_row = true;
					continue;
				}

				$scores[] = $percent;
			}

			$score = empty( $scores )
				? 100
				: (int) round( array_sum( $scores ) / count( $scores ) );

			$score -= $this->get_content_quality_penalty( $results );

			return (int) max( 0, min( 100, $score ) );
		}

		/**
		 * Whether any open content-gap issue remains in the report.
		 *
		 * @param array<int, array<string, mixed>> $results Normalized scan results.
		 * @return bool
		 */
		private function results_have_open_content_gaps( array $results ) {
			$gap_keys = array(
				'content_missing',
				'content_draft',
				'term_missing',
				'content_nolang',
				'homepage_translations',
				'strings_missing',
			);

			foreach ( $results as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}

				$severity = isset( $result['severity'] ) ? (string) $result['severity'] : 'pass';
				if ( 'pass' === $severity ) {
					continue;
				}

				$dedupe_key = isset( $result['dedupe_key'] ) ? (string) $result['dedupe_key'] : '';
				if ( in_array( $dedupe_key, $gap_keys, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Penalty for linked-but-broken / stale content: wrong-language links,
		 * identical text, and freshness gaps (default updated or off-source
		 * edits). These don't show up in coverage counts (a translation exists),
		 * so without this the Content score can read 100% while Grouped Issues
		 * still lists real problems.
		 *
		 * @param array<int, array<string, mixed>> $results Normalized scan results.
		 * @return int Points to subtract (capped so one pillar can't swing wildly).
		 */
		private function get_content_quality_penalty( array $results ) {
			$issue_count  = 0;
			$quality_keys = array(
				'content_links',
				'content_identical',
				'content_acf',
				'content_placeholder',
				'content_source_updated',

				/*
				 * A post type Polylang is not translating cannot ever reach full
				 * coverage, and it is listed on the Content tab - without it here
				 * that tab could show the issue beside a 100% Content pillar.
				 */
				'untranslated_types',
			);

			foreach ( $results as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}

				$severity = isset( $result['severity'] ) ? (string) $result['severity'] : 'pass';
				if ( 'pass' === $severity ) {
					continue;
				}

				$dedupe_key = isset( $result['dedupe_key'] ) ? (string) $result['dedupe_key'] : '';
				if ( ! in_array( $dedupe_key, $quality_keys, true ) ) {
					continue;
				}

				$details      = isset( $result['details'] ) && is_array( $result['details'] ) ? $result['details'] : array();
				$issue_count += count( $details ) > 0 ? count( $details ) : 1;
			}

			// 2 points per quality issue, capped at 25 so it dents the score
			// without letting these alone floor the whole pillar.
			return min( 25, $issue_count * 2 );
		}

		/**
		 * Setup integrity score from config checks only.
		 *
		 * Content/term/string gap volume belongs in Coverage, not here.
		 *
		 * @param array<int, array<string, mixed>> $results Normalized scan results.
		 * @return int
		 */
		private function get_setup_score( array $results ) {
			$penalty        = 0;
			$other_penalty  = 0;
			$menu_failed    = false;
			$routing_failed = false;
			$config_failed  = false;
			$strings_failed = false;

			foreach ( $results as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}

				$severity = isset( $result['severity'] ) ? (string) $result['severity'] : 'pass';
				if ( 'pass' === $severity ) {
					continue;
				}

				// A Notice has no visitor impact by definition, so it cannot cost
				// points. Without this a row that says "we could not test this"
				// scores the same as a row that says "this is broken".
				if ( DUPCAP_Issue_Normalizer::is_enabled() && DUPCAP_Issue_Types::SEVERITY_NOTICE === $severity ) {
					continue;
				}

				$code       = isset( $result['code'] ) ? (string) $result['code'] : '';
				$dedupe_key = isset( $result['dedupe_key'] ) ? (string) $result['dedupe_key'] : '';
				$bucket     = $this->classify_setup_check( $code, $dedupe_key );

				if ( null === $bucket ) {
					continue;
				}

				if ( 'languages_broken' === $bucket ) {
					$penalty += 40;
				} elseif ( 'homepage' === $bucket ) {
					$penalty += 25;
				} elseif ( 'menus' === $bucket ) {
					if ( 'info' !== $severity ) {
						$menu_failed = true;
					}
				} elseif ( 'routing' === $bucket ) {
					// Server timeouts (info) are not confirmed visitor-facing failures.
					if ( 'info' !== $severity ) {
						$routing_failed = true;
					}
				} elseif ( 'url_config' === $bucket ) {
					$config_failed = true;
				} elseif ( 'strings' === $bucket ) {
					$strings_failed = true;
				} elseif ( 'permalink_review' === $bucket ) {
					if ( 'crit' === $severity ) {
						$other_penalty += 10;
					} elseif ( 'warn' === $severity ) {
						$other_penalty += 2;
					}
				} elseif ( 'permalinks' === $bucket ) {
					$other_penalty += 10;
				} else {
					$other_penalty += ( 'crit' === $severity ) ? 10 : 5;
				}
			}

			if ( $menu_failed ) {
				// Navigation that sends a visitor to the wrong language, or to a
				// page that is not there, is Critical under the 2.3.0 severity
				// rule. It used to cost 15, less than a misconfigured setting.
				$penalty += DUPCAP_Issue_Normalizer::is_enabled() ? 25 : 15;
			}

			// Charged once per bucket, not once per result: a four-language site
			// emits one routing row per language, and per-result penalties would
			// zero the score for a single misconfigured setting.
			if ( $routing_failed ) {
				$penalty += 30;
			}

			if ( $config_failed ) {
				$penalty += 15;
			}

			// Charged once, like menus and routing: a seven-language site emits
			// one strings row per language for the same set of untranslated
			// strings, and per-row charging would floor the pillar.
			if ( $strings_failed ) {
				$penalty += 10;
			}

			$penalty += min( 40, $other_penalty );

			return (int) max( 0, 100 - $penalty );
		}

		/**
		 * Map a check to a setup penalty bucket, or null if not a setup check.
		 *
		 * @param string $code       Check code.
		 * @param string $dedupe_key Group key.
		 * @return string|null
		 */
		private function classify_setup_check( $code, $dedupe_key ) {
			if ( in_array( $code, array( 'lang_default', 'lang_secondary' ), true ) ) {
				return 'languages_broken';
			}

			if ( 'lang_homepage' === $code || 'homepage_translations' === $dedupe_key || 'homepage_translations' === $code ) {
				return 'homepage';
			}

			if ( 'lang_permalinks' === $code ) {
				return 'permalinks';
			}

			if ( 'menu_assignments' === $dedupe_key || ( 0 === strpos( $code, 'menu_' ) && 'menu_none' !== $code ) ) {
				return 'menus';
			}

			if ( 'navigation' === $dedupe_key || 0 === strpos( $code, 'nav_' ) ) {
				return 'menus';
			}

			// Specific url_buckets must come before the generic url_ branch below,
			// or they silently inherit the much lighter 'other' weight.
			if ( 0 === strpos( $code, 'url_route_' ) || 'routing_health' === $dedupe_key ) {
				return 'routing';
			}

			if ( 0 === strpos( $code, 'translated_permalink_' ) || 'translated_permalinks' === $dedupe_key || 'url_config_slug_suffix' === $code ) {
				return 'permalink_review';
			}

			if ( 0 === strpos( $code, 'url_config_' ) || 'url_configuration' === $dedupe_key ) {
				return 'url_config';
			}

			if ( 'lang_switcher' === $code || 'lang_switcher' === $dedupe_key ) {
				return 'other';
			}

			// One row per language, so this is charged once per bucket below.
			if ( 'strings_missing' === $dedupe_key || 0 === strpos( $code, 'string_' ) ) {
				return 'strings';
			}

			if ( 0 === strpos( $code, 'url_' ) || 0 === strpos( $code, 'content_nolang_' ) || 0 === strpos( $code, 'term_nolang_' ) ) {
				return 'other';
			}

			if ( 'elementor_not_translated' === $code ) {
				return 'other';
			}

			return null;
		}

		/**
		 * Builder fidelity score: Elementor widget health, minus untranslated templates.
		 *
		 * @param array<int, array<string, mixed>> $results Normalized scan results.
		 * @return int
		 */
		private function get_builder_score( array $results = array() ) {
			$insights = $this->get_elementor_insights();
			$score    = 100;

			/*
			 * One source only. The scanner also stores a health_score in its saved
			 * summary, but that one is computed over every raw finding while this
			 * one is computed over the findings the dashboard actually shows —
			 * after ignores, INFO rows and CPEL suppression. On the fixture site
			 * they read 43 and 84. Falling back to the stored number would make the
			 * pillar jump by forty points depending on which branch ran.
			 */
			if ( ! empty( $insights['visible'] ) && isset( $insights['health_score'] ) ) {
				$score = (int) max( 0, min( 100, (int) $insights['health_score'] ) );
			}

			$templates = $this->count_templates_missing_translations( $results );

			if ( $templates < 1 ) {
				return $score;
			}

			/*
			 * The deep scan only reads inside widgets, so a template with no
			 * translation at all is invisible to it: this pillar read 100% next
			 * to an Elementor tab listing four of them. Penalty per template
			 * rather than a ratio, matching the other pillars, and capped so a
			 * template-heavy site cannot drive the overall score to nothing on
			 * this one input.
			 */
			return (int) max( 0, $score - min( 60, 15 * $templates ) );
		}

		/**
		 * Elementor templates the main scan found with a missing translation.
		 *
		 * Counted per template, not per language: one template missing four
		 * languages is one template to fix.
		 *
		 * @param array<int, array<string, mixed>> $results Normalized scan results.
		 * @return int
		 */
		private function count_templates_missing_translations( array $results ) {
			$templates = array();

			foreach ( $results as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}

				$dedupe_key = isset( $result['dedupe_key'] ) ? (string) $result['dedupe_key'] : '';
				$severity   = isset( $result['severity'] ) ? (string) $result['severity'] : 'pass';

				if ( 'elementor_templates' !== $dedupe_key || 'pass' === $severity ) {
					continue;
				}

				$details = ( isset( $result['details'] ) && is_array( $result['details'] ) ) ? $result['details'] : array();

				foreach ( $details as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}

					$id = 0;
					if ( ! empty( $detail['template_id'] ) ) {
						$id = (int) $detail['template_id'];
					} elseif ( ! empty( $detail['post_id'] ) ) {
						$id = (int) $detail['post_id'];
					}

					if ( $id > 0 ) {
						$templates[ $id ] = true;
					}
				}
			}

			return count( $templates );
		}

		/**
		 * URL & routing score from routing, URL config, and permalink checks.
		 *
		 * Display pillar only — overall health still weights Coverage / Setup / Builder.
		 * Routing failures remain part of Setup so the overall score stays stable.
		 *
		 * @param array<int, array<string, mixed>> $results Normalized scan results.
		 * @return int
		 */
		private function get_routing_score( array $results ) {
			$penalty        = 0;
			$other_penalty  = 0;
			$routing_failed = false;
			$config_failed  = false;

			foreach ( $results as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}

				$severity = isset( $result['severity'] ) ? (string) $result['severity'] : 'pass';
				if ( 'pass' === $severity ) {
					continue;
				}

				// Notices are weightless here too, for the same reason.
				if ( DUPCAP_Issue_Normalizer::is_enabled() && DUPCAP_Issue_Types::SEVERITY_NOTICE === $severity ) {
					continue;
				}

				$code       = isset( $result['code'] ) ? (string) $result['code'] : '';
				$dedupe_key = isset( $result['dedupe_key'] ) ? (string) $result['dedupe_key'] : '';
				$bucket     = $this->classify_setup_check( $code, $dedupe_key );

				if ( 'routing' === $bucket ) {
					// Server timeouts (info) are not confirmed visitor-facing failures.
					if ( 'info' !== $severity ) {
						$routing_failed = true;
					}
				} elseif ( 'url_config' === $bucket ) {
					$config_failed = true;
				} elseif ( 'permalink_review' === $bucket ) {
					if ( 'crit' === $severity ) {
						$other_penalty += 10;
					} elseif ( 'warn' === $severity ) {
						$other_penalty += 2;
					}
				} elseif ( 'other' === $bucket && ( 'url_dupe_slugs' === $dedupe_key || 0 === strpos( $code, 'url_dupe_slugs' ) ) ) {
					/*
					 * Two pages sharing one address means one of them is not
					 * reachable, which is this pillar's whole subject. It is
					 * listed on the URLs tab, so it has to be felt here too.
					 */
					$other_penalty += ( 'crit' === $severity ) ? 10 : 5;
				}
			}

			// Charged once per bucket: one routing row per language must not zero the score.
			if ( $routing_failed ) {
				$penalty += 40;
			}

			if ( $config_failed ) {
				$penalty += 25;
			}

			$penalty += min( 35, $other_penalty );

			return (int) max( 0, 100 - $penalty );
		}

		/**
		 * Get score class.
		 *
		 * @param int $score Score.
		 * @return string
		 */
		private function get_health_class( $score ) {
			if ( $score >= 90 ) {
				return 'excellent';
			}

			if ( $score >= 75 ) {
				return 'good';
			}

			if ( $score >= 55 ) {
				return 'attention';
			}

			return 'risk';
		}

		/**
		 * Get health label.
		 *
		 * @param int $score Score.
		 * @return string
		 */
		private function get_health_label( $score ) {
			if ( ! DUPCAP_Scanner::has_saved_scan() ) {
				return __( 'Scan required', 'duplicate-content-addon-for-polylang' );
			}

			$labels = array(
				'excellent' => __( 'Excellent', 'duplicate-content-addon-for-polylang' ),
				'good'      => __( 'Good', 'duplicate-content-addon-for-polylang' ),
				'attention' => __( 'Needs Attention', 'duplicate-content-addon-for-polylang' ),
				'risk'      => __( 'Needs Improvement', 'duplicate-content-addon-for-polylang' ),
			);

			return $labels[ $this->get_health_class( $score ) ];
		}

		/**
		 * Get coverage rows.
		 *
		 * @param array<int, array<string, mixed>> $results Results.
		 * @return array<int, array<string, mixed>>
		 */
		private function get_language_coverage( array $results ) {
			$coverage = apply_filters( 'dupcap_dashboard_language_coverage', array(), $results );

			if ( is_array( $coverage ) && ! empty( $coverage ) ) {
				return $this->sanitize_coverage_rows( $coverage );
			}

			return array();
		}

		/**
		 * Sanitize coverage rows.
		 *
		 * @param array<int, mixed> $coverage Coverage rows.
		 * @return array<int, array<string, mixed>>
		 */
		private function sanitize_coverage_rows( array $coverage ) {
			$rows = array();

			foreach ( $coverage as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$rows[] = array(
					'language' => isset( $row['language'] ) ? sanitize_text_field( (string) $row['language'] ) : '',
					'slug'     => isset( $row['slug'] ) ? sanitize_key( (string) $row['slug'] ) : '',
					'coverage' => isset( $row['coverage'] ) ? (int) $row['coverage'] : 0,
				);
			}

			return $rows;
		}


}
