<?php


defined( 'ABSPATH' ) || exit;

/**
 * Issue Card methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Issue_Card_Trait {

		/**
		 * Per-language homepage actions inside Fix These First.
		 *
		 * Matches the Content missing-translations table: language chips +
		 * separate Translate buttons for each language.
		 *
		 * @param array<string, mixed> $group Homepage issue group.
		 * @return void
		 */
		private function render_homepage_fix_details( array $group ) {
			$details = isset( $group['details'] ) && is_array( $group['details'] ) ? $group['details'] : array();
			if ( empty( $details ) ) {
				return;
			}

			$missing  = array();
			$edit_url = '';

			foreach ( $details as $detail ) {
				if ( ! is_array( $detail ) || empty( $detail['language'] ) ) {
					continue;
				}

				$slug = sanitize_key( (string) $detail['language'] );
				$name = ! empty( $detail['name'] )
					? (string) $detail['name']
					: ( class_exists( 'DUPCAP_Elementor_Fields' )
						? DUPCAP_Elementor_Fields::language_label( $slug )
						: strtoupper( $slug ) );

				$translate_url = ! empty( $detail['action_url'] ) ? (string) $detail['action_url'] : '';

				$missing[] = array(
					'slug'          => $slug,
					'name'          => $name,
					'translate_url' => $translate_url,
					'issue'         => ! empty( $detail['issue'] ) ? sanitize_key( (string) $detail['issue'] ) : 'missing',
				);
			}

			if ( empty( $missing ) ) {
				return;
			}

			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id > 0 ) {
				$source_edit = get_edit_post_link( $front_id, 'url' );
				$edit_url    = $source_edit ? $source_edit : '';
			}

			$title = __( 'Homepage', 'duplicate-content-addon-for-polylang' );
			if ( $front_id > 0 ) {
				$front_title = get_the_title( $front_id );
				if ( is_string( $front_title ) && '' !== trim( $front_title ) ) {
					$title = $front_title;
				}
			}
			?>
			<div class="dupcap-content-pending-intro">
				<p class="description">
					<?php esc_html_e( 'Use Edit to open the default homepage, then Translate for each missing language listed in the row.', 'duplicate-content-addon-for-polylang' ); ?>
				</p>
			</div>
			<div class="dupcap-table-wrap">
				<table class="widefat striped dupcap-content-pending-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Title', 'duplicate-content-addon-for-polylang' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'duplicate-content-addon-for-polylang' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Missing languages', 'duplicate-content-addon-for-polylang' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'duplicate-content-addon-for-polylang' ); ?></th>
						</tr>
					</thead>
					<tbody data-dupcap-paginate>
						<tr data-dupcap-content-type="page">
							<td><?php echo esc_html( $title ); ?></td>
							<td><?php echo esc_html( 'Page' ); ?></td>
							<td>
								<div class="dupcap-content-lang-chips">
									<?php foreach ( $missing as $lang_row ) : ?>
										<?php
										$slug      = ! empty( $lang_row['slug'] ) ? (string) $lang_row['slug'] : '';
										$flag_html = $this->get_language_flag_html( $slug );
										$code_text = strtoupper( $slug );
										?>
										<?php if ( ! empty( $lang_row['translate_url'] ) ) : ?>
											<a
												class="dupcap-content-lang-chip dupcap-content-lang-chip-link"
												href="<?php echo esc_url( $lang_row['translate_url'] ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												title="<?php echo esc_attr( sprintf( 
													/* translators: %s: language name */
													__( 'Translate into %s', 'duplicate-content-addon-for-polylang' ), $lang_row['name'] ) ); ?>"
												data-dupcap-track-fix="homepage"
												data-language="<?php echo esc_attr( $slug ); ?>"
												data-gap-type="<?php echo esc_attr( ! empty( $lang_row['issue'] ) ? $lang_row['issue'] : 'missing' ); ?>"
											>
												<?php if ( '' !== $flag_html ) : ?>
													<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
												<?php endif; ?>
												<span class="dupcap-content-lang-chip-code"><?php echo esc_html( $code_text ); ?></span>
											</a>
										<?php else : ?>
											<span class="dupcap-content-lang-chip">
												<?php if ( '' !== $flag_html ) : ?>
													<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
												<?php endif; ?>
												<span class="dupcap-content-lang-chip-code"><?php echo esc_html( $code_text ); ?></span>
											</span>
										<?php endif; ?>
									<?php endforeach; ?>
								</div>
							</td>
							<td>
								<div class="dupcap-content-actions">
									<?php if ( '' !== $edit_url ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Edit', 'duplicate-content-addon-for-polylang' ); ?>
										</a>
									<?php endif; ?>
									<?php
									$homepage_rescan_langs = array();
									$homepage_ignore_keys  = array();
									foreach ( $details as $detail ) {
										if ( ! empty( $detail['ignore_key'] ) ) {
											$homepage_ignore_keys[] = DUPCAP_Ignored_Issues::sanitize_key( (string) $detail['ignore_key'] );
										} elseif ( ! empty( $detail['language'] ) ) {
											$homepage_ignore_keys[] = DUPCAP_Ignored_Issues::homepage_key(
												sanitize_key( (string) $detail['language'] ),
												isset( $detail['issue'] ) ? (string) $detail['issue'] : 'missing'
											);
										}
									}
									foreach ( $missing as $lang_row ) {
										if ( ! empty( $lang_row['slug'] ) ) {
											$homepage_rescan_langs[] = sanitize_key( (string) $lang_row['slug'] );
										}
									}
									$homepage_rescan_langs = array_values( array_unique( array_filter( $homepage_rescan_langs ) ) );
									$homepage_ignore_str   = implode( ',', array_values( array_unique( array_filter( $homepage_ignore_keys ) ) ) );
									?>
									<a
										class="button button-small button-primary dupcap-ai-translate-btn"
										href="<?php echo esc_url( $this->get_post_type_ai_translation_url( 'page', $front_id ) ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										title="<?php esc_attr_e( 'Go to Page list view for AI Translation', 'duplicate-content-addon-for-polylang' ); ?>"
										data-dupcap-track-fix="homepage"
										data-post-id="<?php echo esc_attr( (string) (int) $front_id ); ?>"
										data-gap-type="missing"
										data-languages="<?php echo esc_attr( implode( ',', $homepage_rescan_langs ) ); ?>"
									>
										<?php esc_html_e( 'AI Translation', 'duplicate-content-addon-for-polylang' ); ?>
									</a>
									<?php if ( ! empty( $homepage_rescan_langs ) ) : ?>
										<button
											type="button"
											class="button button-small dupcap-rescan-issue-button"
											data-dupcap-rescan-content-issue
											data-kind="homepage"
											data-gap-type="missing"
											data-languages="<?php echo esc_attr( implode( ',', $homepage_rescan_langs ) ); ?>"
										>
											<span class="dashicons dashicons-update" aria-hidden="true"></span>
											<?php esc_html_e( 'Rescan issue', 'duplicate-content-addon-for-polylang' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( ! empty( $homepage_ignore_str ) ) : ?>
										<button
											type="button"
											class="button button-small dupcap-rescan-issue-button dupcap-ignore-button"
											data-dupcap-ignore="<?php echo esc_attr( $homepage_ignore_str ); ?>"
											title="<?php esc_attr_e( 'Dismiss homepage translation warnings', 'duplicate-content-addon-for-polylang' ); ?>"
										>
											<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
										</button>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<?php
		}

		/**
		 * Render one grouped issue.
		 *
		 * @param array<string, mixed> $group      Issue group.
		 * @param bool                 $is_first   Whether the card opens on first paint.
		 * @param string               $intro_html Extra markup for the top of the card body, already escaped.
		 * @return void
		 */
		private function render_issue_group( array $group, $is_first = false, $intro_html = '' ) {
			$group_slug    = sanitize_title( $group['group'] );
			$severity      = $group['severity'];
			$is_open       = (bool) $is_first;
			$details_count = $this->get_group_issue_count( $group );
			$show_card_actions = ! empty( $group['action_url'] ) && ! empty( $group['action_label'] );
			if ( in_array( $group['dedupe_key'], array( 'content_missing', 'content_draft', 'term_missing', 'content_source_updated' ), true ) && $this->has_content_pending_posts( $group['details'] ) ) {
				$show_card_actions = false;
			}
			if ( 'homepage_translations' === $group['dedupe_key'] && ! empty( $group['details'] ) ) {
				$show_card_actions = false;
			}
			if ( 'menu_assignments' === $group['dedupe_key'] ) {
				$show_card_actions = false;
			}
			if ( 'menu_dead_links' === $group['dedupe_key'] ) {
				$show_card_actions = false;
			}
			// Routing draws its fix above the rows, in render_group_details().
			if ( 'routing_health' === $group['dedupe_key'] ) {
				$show_card_actions = false;
			}
			if ( 'elementor_templates' === $group['dedupe_key'] && ! empty( $group['details'] ) ) {
				$show_card_actions = false;
			}
			if ( 'strings_missing' === $group['dedupe_key'] || $this->group_has_common_row_actions( $group ) ) {
				$show_card_actions = false;
			}

			$content_types = $this->get_group_content_types( $group['details'] );
			$elementor_types = $this->get_elementor_group_document_types( $group );

			$issue_icon = 'warning';
			if ( 'crit' === $severity ) {
				$issue_icon = 'dismiss';
			} elseif ( 'info' === $severity ) {
				$issue_icon = 'info';
			}

			?>
			<details
				class="dupcap-issue-card dupcap-issue-<?php echo esc_attr( $severity ); ?>"
				data-dupcap-card
				data-dupcap-card-id="<?php echo esc_attr( isset( $group['dedupe_key'] ) ? (string) $group['dedupe_key'] : $group_slug ); ?>"
				name="dupcap-accordion-<?php echo esc_attr( $group_slug ); ?>"
				data-dupcap-severity="<?php echo esc_attr( $severity ); ?>"
				data-dupcap-group="<?php echo esc_attr( $group_slug ); ?>"				<?php if ( ! empty( $content_types ) ) : ?>data-dupcap-content-types="<?php echo esc_attr( implode( ' ', $content_types ) ); ?>"<?php endif; ?>
				<?php if ( ! empty( $elementor_types ) ) : ?>data-dupcap-elementor-document-types="<?php echo esc_attr( implode( ' ', $elementor_types ) ); ?>"<?php endif; ?>
				<?php echo $is_open ? 'open' : ''; ?>
			>
				<?php
				// admin.js rewrites the count while a filter is active, so it needs
				// the heading wording from here instead of hardcoding English.
				$count_templates = array();
				if ( 'translated_permalinks' !== $group['dedupe_key'] ) {
					$count_templates = ( 'routing_health' === $group['dedupe_key'] && 'info' === $severity )
						? array(
							/* translators: 1: group title, 2: count of URLs to verify. */
							'one'  => __( '%1$s (%2$s to check)', 'duplicate-content-addon-for-polylang' ),
							/* translators: 1: group title, 2: count of URLs to verify. */
							'many' => __( '%1$s (%2$s to check)', 'duplicate-content-addon-for-polylang' ),
						)
						: array(
							/* translators: 1: group title, 2: issue count. */
							'one'  => __( '%1$s (%2$s Issue)', 'duplicate-content-addon-for-polylang' ),
							/* translators: 1: group title, 2: issue count. */
							'many' => __( '%1$s (%2$s Issues)', 'duplicate-content-addon-for-polylang' ),
						);
				}
				?>
				<summary class="dupcap-issue-summary">
					<span class="dupcap-issue-icon dashicons dashicons-<?php echo esc_attr( $issue_icon ); ?>" aria-hidden="true"></span>
					<span class="dupcap-issue-main">
						<strong
							data-dupcap-card-title="<?php echo esc_attr( wp_strip_all_tags( $this->get_group_display_name( $group ) ) ); ?>"
							data-dupcap-card-count="<?php echo esc_attr( (string) $details_count ); ?>"
							<?php if ( ! empty( $count_templates ) ) : ?>
								data-dupcap-count-one="<?php echo esc_attr( $count_templates['one'] ); ?>"
								data-dupcap-count-many="<?php echo esc_attr( $count_templates['many'] ); ?>"
							<?php endif; ?>
						>
							<?php
							$display_title = $this->get_group_display_name( $group );
							$formatted_title = $this->format_check_title_with_flag( $display_title );
							if ( 'translated_permalinks' === $group['dedupe_key'] ) {
								$summary = isset( $group['message'] ) ? trim( (string) $group['message'] ) : '';
								if ( '' !== $summary && $summary !== $display_title ) {
									printf(
										/* translators: 1: section name, 2: summary such as "5 URLs to review". */
										esc_html__( '%1$s — %2$s', 'duplicate-content-addon-for-polylang' ),
										$formatted_title, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										esc_html( $summary )
									);
								} else {
									echo $formatted_title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
							} elseif ( 'routing_health' === $group['dedupe_key'] && 'info' === $severity ) {
								printf(
									/* translators: 1: group title, 2: count of URLs to verify. */
									esc_html__( '%1$s (%2$d to check)', 'duplicate-content-addon-for-polylang' ),
									$formatted_title, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									absint( $details_count )
								);
							} else {
								printf(
									/* translators: 1: group title, 2: issue count. */
									esc_html( _n( '%1$s (%2$d Issue)', '%1$s (%2$d Issues)', (int) $details_count, 'duplicate-content-addon-for-polylang' ) ),
									$formatted_title, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									absint( $details_count )
								);
							}
							?>
						</strong>
						<span
							data-dupcap-card-summary
							data-dupcap-summary-filtered="<?php /* translators: 1: rows shown, 2: rows in the card. */ echo esc_attr__( 'Showing %1$s of %2$s items.', 'duplicate-content-addon-for-polylang' ); ?>"
						><?php
						if ( 'translated_permalinks' === $group['dedupe_key'] ) {
							$permalink_copy = DUPCAP_Issue_Copy::group_message( 'translated_permalinks' );
							echo esc_html( '' !== $permalink_copy ? $permalink_copy : $group['message'] );
						} else {
							echo esc_html( $group['message'] );
						}
						?></span>
					</span>
					<span class="dupcap-issue-meta">
						<span class="dupcap-badge dupcap-badge-<?php echo esc_attr( $severity ); ?>">
							<span class="dashicons dashicons-shield" aria-hidden="true"></span>
							<?php echo esc_html( $this->get_severity_label( $severity ) ); ?>
						</span>
						<span class="dashicons dashicons-arrow-down-alt2 dupcap-accordion-arrow" aria-hidden="true"></span>
					</span>
				</summary>

				<div class="dupcap-issue-body">
					<?php if ( '' !== $intro_html ) : ?>
						<?php echo $intro_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?>
					<?php endif; ?>
					<?php $this->render_group_details( $group ); ?>
					<?php if ( $show_card_actions ) : ?>
						<div class="dupcap-card-actions">
							<?php $this->render_action_control( $group['action_url'], $group['action_label'] ); ?>
						</div>
					<?php endif; ?>
				</div>
			</details>
			<?php
		}

		/**
		 * Whether all detail rows in a group share the exact same action button.
		 *
		 * @param array<string, mixed> $group Issue group.
		 * @return bool
		 */
		private function group_has_common_row_actions( array $group ) {
			if ( empty( $group['details'] ) || ! is_array( $group['details'] ) ) {
				return ! empty( $group['action_url'] ) && ! empty( $group['action_label'] );
			}

			$first_url   = null;
			$first_label = null;

			foreach ( $group['details'] as $detail ) {
				if ( ! is_array( $detail ) ) {
					continue;
				}
				$url   = ! empty( $detail['action_url'] ) ? trim( (string) $detail['action_url'] ) : '';
				$label = ! empty( $detail['action_label'] ) ? trim( (string) $detail['action_label'] ) : '';

				if ( '' === $url || '' === $label ) {
					continue;
				}

				if ( null === $first_url ) {
					$first_url   = $url;
					$first_label = $label;
				} else {
					if ( $first_url !== $url || $first_label !== $label ) {
						return false;
					}
				}
			}

			if ( null === $first_url && ! empty( $group['action_url'] ) && ! empty( $group['action_label'] ) ) {
				return true;
			}

			return null !== $first_url;
		}

		/**
		 * Retrieve the single common action URL and label for a group.
		 *
		 * @param array<string, mixed> $group Issue group.
		 * @return array{url: string, label: string}
		 */
		private function get_common_group_action( array $group ) {
			$url   = ! empty( $group['action_url'] ) ? trim( (string) $group['action_url'] ) : '';
			$label = ! empty( $group['action_label'] ) ? trim( (string) $group['action_label'] ) : '';

			if ( ( '' === $url || '' === $label ) && ! empty( $group['details'] ) && is_array( $group['details'] ) ) {
				foreach ( $group['details'] as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$d_url   = ! empty( $detail['action_url'] ) ? trim( (string) $detail['action_url'] ) : '';
					$d_label = ! empty( $detail['action_label'] ) ? trim( (string) $detail['action_label'] ) : '';
					if ( '' !== $d_url && '' !== $d_label ) {
						$url   = $d_url;
						$label = $d_label;
						break;
					}
				}
			}

			if ( '' === $url && 'url_configuration' === ( isset( $group['dedupe_key'] ) ? $group['dedupe_key'] : '' ) ) {
				$url   = admin_url( 'admin.php?page=mlang_settings' );
				$label = __( 'Polylang URL settings', 'duplicate-content-addon-for-polylang' );
			}

			return array(
				'url'   => $url,
				'label' => $label,
			);
		}

		/**
		 * Look up posts sharing a conflicting slug for dashboard display fallback.
		 *
		 * @param string $slug Slug name.
		 * @return array<int, array<string, mixed>>
		 */
		private function get_posts_for_conflicting_slug( $slug ) {
			$slug = sanitize_title( (string) $slug );
			if ( '' === $slug || ! function_exists( 'pll_get_post_language' ) ) {
				return array();
			}

			$ids = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => 10,
					'fields'         => 'ids',
					'lang'           => '',
				)
			);

			if ( empty( $ids ) ) {
				return array();
			}

			$lang_names = array();
			if ( function_exists( 'PLL' ) && isset( PLL()->model ) && method_exists( PLL()->model, 'get_languages_list' ) ) {
				foreach ( PLL()->model->get_languages_list() as $l_obj ) {
					if ( is_object( $l_obj ) && ! empty( $l_obj->slug ) && ! empty( $l_obj->name ) ) {
						$lang_names[ $l_obj->slug ] = (string) $l_obj->name;
					}
				}
			}

			$posts = array();
			foreach ( $ids as $id ) {
				$title     = get_the_title( $id );
				$post_type = get_post_type( $id );
				$lang_code = (string) pll_get_post_language( $id );
				$lang_name = isset( $lang_names[ $lang_code ] ) ? $lang_names[ $lang_code ] : strtoupper( $lang_code ? $lang_code : 'default' );

				$posts[] = array(
					'id'           => $id,
					'title'        => $title ? $title : sprintf( 
						/* translators: %d: post ID */
					__( 'Post #%d', 'duplicate-content-addon-for-polylang' ), $id ),
					'post_type'    => $post_type,
					'lang_slug'    => $lang_code,
					'lang_name'    => $lang_name,
					'action_url'   => (string) get_edit_post_link( $id, 'url' ),
					'action_label' => 'page' === $post_type ? __( 'Edit page', 'duplicate-content-addon-for-polylang' ) : __( 'Edit post', 'duplicate-content-addon-for-polylang' ),
				);
			}

			return $posts;
		}

		/**
		 * Render details for a group.
		 *
		 * @param array<string, mixed> $group Issue group.
		 * @return void
		 */
		private function render_group_details( array $group ) {
			if ( empty( $group['details'] ) ) {
				return;
			}

			$is_routing = 'routing_health' === ( isset( $group['dedupe_key'] ) ? $group['dedupe_key'] : '' );

			// Same table Fix These First shows, so the homepage card reads the
			// same wherever it appears.
			if ( 'homepage_translations' === $group['dedupe_key'] ) {
				$this->render_homepage_fix_details( $group );
				return;
			}

			if ( 'strings_missing' === $group['dedupe_key'] ) {
				$this->render_strings_details( $group );
				return;
			}

			if ( 'menu_assignments' === $group['dedupe_key'] ) {
				$this->render_menu_gaps_table( $group['details'] );
				return;
			}

			if ( 'menu_dead_links' === $group['dedupe_key'] ) {
				$this->render_menu_dead_link_details( $group['details'] );
				return;
			}

			if ( 'elementor_templates' === $group['dedupe_key'] ) {
				if ( $this->render_content_pending_table( $group['details'], $group['dedupe_key'] ) ) {
					return;
				}
				$this->render_elementor_library_details( $group['details'] );
				return;
			}

			if ( in_array( $group['dedupe_key'], array( 'content_missing', 'content_draft', 'term_missing', 'content_source_updated' ), true ) ) {
				if ( $this->render_content_pending_table( $group['details'], $group['dedupe_key'] ) ) {
					return;
				}
			}

			if ( 'navigation' === $group['dedupe_key'] ) {
				$this->render_navigation_details( $group['details'] );
				return;
			}

			if ( 'content_links' === $group['dedupe_key'] ) {
				$this->render_content_link_details( $group['details'] );
				return;
			}

			if ( in_array( $group['dedupe_key'], array( 'content_identical', 'content_acf', 'content_placeholder' ), true ) ) {
				$this->render_content_identical_details(
					'content_placeholder' === $group['dedupe_key'] ? $this->merge_placeholder_rows( $group['details'] ) : $group['details']
				);
				return;
			}

			if ( 'translated_permalinks' === $group['dedupe_key'] ) {
				$this->render_translated_permalink_details( $group['details'] );
				return;
			}

			if ( $is_routing ) {
				/*
				 * Rebuild URL rules is what the reader does first, so it sits above
				 * the rows the way Manage Menu Locations does. render_issue_group()
				 * keeps the card footer empty for this type so it is drawn once.
				 */
				if ( ! empty( $group['action_url'] ) && ! empty( $group['action_label'] ) ) {
					?>
					<div class="dupcap-card-actions dupcap-url-config-top-actions">
						<?php $this->render_action_control( $group['action_url'], $group['action_label'] ); ?>
					</div>
					<?php
				}

				$this->render_routing_details( $group['details'] );
				return;
			}

			$has_common_actions = $this->group_has_common_row_actions( $group );
			?>
			<?php if ( $has_common_actions ) : ?>
				<?php $common_action = $this->get_common_group_action( $group ); ?>
				<?php if ( ! empty( $common_action['url'] ) && ! empty( $common_action['label'] ) ) : ?>
					<div class="dupcap-card-actions dupcap-url-config-top-actions">
						<?php $this->render_action_control( $common_action['url'], $common_action['label'] ); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			<div class="dupcap-detail-list" data-dupcap-paginate>
				<?php foreach ( $group['details'] as $detail ) : ?>
					<?php
					$show_detail_action = ! empty( $detail['action_url'] ) && ! empty( $detail['action_label'] );
					// Card footer already shows the Language Switcher install/activate CTA.
					if ( $has_common_actions || 'lang_switcher' === $group['dedupe_key'] ) {
						$show_detail_action = false;
					}
					?>
					<div class="dupcap-detail-row"<?php echo ! empty( $detail['post_type'] ) ? ' data-dupcap-content-type="' . esc_attr( $detail['post_type'] ) . '"' : ''; ?> data-dupcap-title="<?php echo esc_attr( isset( $detail['label'] ) ? (string) $detail['label'] : '' ); ?>">
						<strong><?php echo esc_html( isset( $detail['label'] ) ? $detail['label'] : $group['title'] ); ?></strong>
						<div>
							<span><?php echo esc_html( isset( $detail['message'] ) ? $detail['message'] : $group['message'] ); ?></span>
							<?php
							$posts_list = ! empty( $detail['posts'] ) && is_array( $detail['posts'] ) ? $detail['posts'] : array();
							if ( empty( $posts_list ) && isset( $detail['label'] ) && preg_match( '/Conflicting slug:\s*"([^"]+)"/', (string) $detail['label'], $m ) ) {
								$posts_list = $this->get_posts_for_conflicting_slug( $m[1] );
							}
							?>
							<?php if ( ! empty( $posts_list ) ) : ?>
								<div class="dupcap-content-lang-chips" style="margin-top: 6px;">
									<?php foreach ( $posts_list as $p_info ) : ?>
										<?php
										$slug       = ! empty( $p_info['lang_slug'] ) ? (string) $p_info['lang_slug'] : '';
										$lang_name  = ! empty( $p_info['lang_name'] ) ? (string) $p_info['lang_name'] : strtoupper( $slug );
										$flag_html  = $this->get_language_flag_html( $slug );
										$post_title = ! empty( $p_info['title'] ) ? (string) $p_info['title'] : '';
										$edit_url   = ! empty( $p_info['action_url'] ) ? (string) $p_info['action_url'] : ( ! empty( $p_info['id'] ) ? (string) get_edit_post_link( (int) $p_info['id'], 'url' ) : '' );
										$chip_text  = $lang_name . ( $post_title ? ' — ' . $post_title : '' );
										?>
										<?php if ( '' !== $edit_url ) : ?>
											<a
												class="dupcap-content-lang-chip dupcap-content-lang-chip-link"
												href="<?php echo esc_url( $edit_url ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												title="<?php echo esc_attr( sprintf( 
													/* translators: 1: post title 2: language name */
													__( 'Edit "%1$s" (%2$s)', 'duplicate-content-addon-for-polylang' ), $post_title, $lang_name ) ); ?>"
											>
												<?php if ( '' !== $flag_html ) : ?>
													<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
												<?php endif; ?>
												<span class="dupcap-content-lang-chip-code"><?php echo esc_html( $chip_text ); ?></span>
											</a>
										<?php else : ?>
											<span class="dupcap-content-lang-chip">
												<?php if ( '' !== $flag_html ) : ?>
													<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
												<?php endif; ?>
												<span class="dupcap-content-lang-chip-code"><?php echo esc_html( $chip_text ); ?></span>
											</span>
										<?php endif; ?>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
						<div class="dupcap-detail-row-actions">
							<?php if ( $show_detail_action ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $detail['action_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $detail['action_label'] ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $detail['ignore_key'] ) ) : ?>
								<button
									type="button"
									class="button button-small dupcap-ignore-button"
									data-dupcap-ignore="<?php echo esc_attr( $detail['ignore_key'] ); ?>"
									title="<?php esc_attr_e( 'Dismiss when the original text is correct for every language, such as a brand name.', 'duplicate-content-addon-for-polylang' ); ?>"
								><?php echo esc_html( ! empty( $detail['ignore_label'] ) ? $detail['ignore_label'] : __( 'Ignore', 'duplicate-content-addon-for-polylang' ) ); ?></button>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/**
		 * Format a check title or card heading so language names/slugs in quotes include a flag icon and full language name.
		 *
		 * @param string $title Check title text.
		 * @return string HTML formatted title.
		 */
		private function format_check_title_with_flag( $title ) {
			$raw_title = (string) $title;

			if ( preg_match( '/"([^"]+)"/', $raw_title, $matches ) ) {
				$inside = trim( $matches[1] );
				$slug   = '';

				if ( strlen( $inside ) <= 5 ) {
					$slug = sanitize_key( $inside );
				}

				if ( function_exists( 'pll_languages_list' ) ) {
					$langs = pll_languages_list( array( 'fields' => false ) );
					if ( is_array( $langs ) ) {
						foreach ( $langs as $l ) {
							if ( is_object( $l ) && ! empty( $l->slug ) ) {
								if ( strtolower( $l->slug ) === strtolower( $inside ) || ( ! empty( $l->name ) && strtolower( $l->name ) === strtolower( $inside ) ) ) {
									$slug = (string) $l->slug;
									break;
								}
							}
						}
					}
				}

				if ( '' !== $slug && function_exists( 'pll_languages_list' ) ) {
					$known = pll_languages_list( array( 'fields' => 'slug' ) );
					if ( ! is_array( $known ) || ! in_array( $slug, $known, true ) ) {
						$slug = '';
					}
				}

				if ( '' !== $slug ) {
					$name      = class_exists( 'DUPCAP_Elementor_Fields' ) ? DUPCAP_Elementor_Fields::language_label( $slug ) : ( '' !== $inside ? $inside : strtoupper( $slug ) );
					$flag_html = $this->get_language_flag_html( $slug );

					$chip_html = '';
					if ( '' !== $flag_html ) {
						$chip_html .= '<span class="dupcap-lang-flag-wrap" aria-hidden="true">' . $flag_html . '</span> ';
					}
					$chip_html .= esc_html( $name );

					$title_escaped = esc_html( $raw_title );
					$target_quote  = '&quot;' . esc_html( $inside ) . '&quot;';

					if ( false !== strpos( $title_escaped, $target_quote ) ) {
						return str_replace( $target_quote, $chip_html, $title_escaped );
					}
					return str_replace( '"' . $inside . '"', $chip_html, $raw_title );
				}
			}

			return esc_html( $raw_title );
		}


}
