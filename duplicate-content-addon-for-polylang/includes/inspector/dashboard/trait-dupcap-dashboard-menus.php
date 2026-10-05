<?php


defined( 'ABSPATH' ) || exit;

/**
 * Menus methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Menus_Trait {

		/**
		 * Render menu dead-link details grouped by missing page target.
		 *
		 * One row = one page the user must create/publish (or repoint away from).
		 * Repeated menu locations for the same URL appear under "Found in".
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return void
		 */
		private function render_menu_dead_link_details( array $details ) {
			if ( empty( $details ) ) {
				return;
			}

			$grouped   = DUPCAP_Issue_Normalizer::group_by_target_url( $details );
			$top_url   = admin_url( 'nav-menus.php' );
			$top_label = __( 'Edit menus', 'duplicate-content-addon-for-polylang' );
			?>
			<div class="dupcap-strings-top-actions">
				<?php $this->render_action_control( $top_url, $top_label ); ?>
			</div>
			<div class="dupcap-detail-list" data-dupcap-paginate>
				<?php foreach ( $grouped as $problem ) : ?>
					<?php
					$detail_url = isset( $problem['url'] ) ? (string) $problem['url'] : '';
					$places     = isset( $problem['details'] ) && is_array( $problem['details'] ) ? $problem['details'] : array();
					$languages  = isset( $problem['languages'] ) && is_array( $problem['languages'] ) ? $problem['languages'] : array();
					$occ_count  = isset( $problem['occurrence_count'] ) ? (int) $problem['occurrence_count'] : count( $places );

					$kind_hint     = '';
					$page_fix_url   = admin_url( 'post-new.php?post_type=page' );
					$page_fix_label = __( 'Create page', 'duplicate-content-addon-for-polylang' );
					$current_label = '';

					if ( '' !== $detail_url && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
						$target          = DUPCAP_Elementor_Fields::describe_dead_link_target( $detail_url );
						$kind_hint       = isset( $target['kind'] ) ? (string) $target['kind'] : 'missing';
						$page_fix_url     = ! empty( $target['fix_url'] ) ? (string) $target['fix_url'] : $page_fix_url;
						$page_fix_label   = ! empty( $target['fix_label'] ) ? (string) $target['fix_label'] : $page_fix_label;
						$current_label   = DUPCAP_Elementor_Fields::readable_url( $detail_url );
						if ( '' === $current_label ) {
							$current_label = DUPCAP_Elementor_Fields::relative_path( $detail_url );
						}
					}
					if ( '' === $current_label ) {
						$current_label = $detail_url;
					}

					$title = sprintf(
						/* translators: %s: path or URL of the missing page */
						__( 'Missing page: %s', 'duplicate-content-addon-for-polylang' ),
						$current_label
					);
					?>
					<div class="dupcap-detail-row dupcap-dead-link-problem" data-dupcap-title="<?php echo esc_attr( $title ); ?>">
						<strong><?php echo esc_html( $title ); ?></strong>
						<?php if ( ! empty( $languages ) ) : ?>
							<p class="dupcap-content-link-context">
								<?php foreach ( $languages as $lang_slug ) : ?>
									<span class="dupcap-lang-chip"><?php echo esc_html( class_exists( 'DUPCAP_Elementor_Fields' ) ? DUPCAP_Elementor_Fields::language_label( (string) $lang_slug ) : strtoupper( (string) $lang_slug ) ); ?></span>
								<?php endforeach; ?>
							</p>
						<?php endif; ?>
						<div>
							<?php $this->render_dead_link_steps( $current_label, $detail_url, $kind_hint ); ?>
						</div>
						<?php if ( $occ_count > 0 ) : ?>
							<p class="dupcap-content-link-context">
								<?php
								printf(
									/* translators: %d: how many menus/items link to this page */
									esc_html( _n( 'Found in %d place:', 'Found in %d places:', $occ_count, 'duplicate-content-addon-for-polylang' ) ),
									(int) $occ_count
								);
								?>
							</p>
							<ul class="dupcap-dead-link-occurrences">
								<?php foreach ( $places as $detail ) : ?>
									<?php
									if ( ! is_array( $detail ) ) {
										continue;
									}
									$label      = isset( $detail['label'] ) ? (string) $detail['label'] : __( 'Menu item', 'duplicate-content-addon-for-polylang' );
									$row_url    = ! empty( $detail['action_url'] ) ? (string) $detail['action_url'] : $top_url;
									$row_label  = ! empty( $detail['action_label'] ) ? (string) $detail['action_label'] : __( 'Edit menu', 'duplicate-content-addon-for-polylang' );
									$ignore_key = ! empty( $detail['ignore_key'] ) ? (string) $detail['ignore_key'] : '';
									$menu_id    = ! empty( $detail['menu_id'] ) ? (int) $detail['menu_id'] : 0;
									$place_url  = ! empty( $detail['url'] ) ? (string) $detail['url'] : $detail_url;

									if ( $menu_id <= 0 && preg_match( '/[?&]menu=(\d+)/', $row_url, $menu_match ) ) {
										$menu_id = (int) $menu_match[1];
									}
									if ( '' === $ignore_key && '' !== $place_url && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
										$ignore_key = DUPCAP_Ignored_Issues::menu_dead_key(
											$menu_id,
											DUPCAP_Elementor_Fields::normalize_url_for_compare( $place_url )
										);
									}
									?>
									<li class="dupcap-dead-link-occurrence">
										<span><?php echo esc_html( $label ); ?></span>
										<span class="dupcap-detail-row-actions">
											<?php $this->render_action_control( $row_url, $row_label ); ?>
											<?php if ( '' !== $ignore_key ) : ?>
												<button
													type="button"
													class="button button-small dupcap-rescan-issue-button"
													data-dupcap-rescan-content-detail
													data-kind="menu_dead"
													data-ignore-key="<?php echo esc_attr( $ignore_key ); ?>"
													data-url="<?php echo esc_attr( $place_url ); ?>"
												>
													<span class="dashicons dashicons-update" aria-hidden="true"></span>
													<?php esc_html_e( 'Rescan issue', 'duplicate-content-addon-for-polylang' ); ?>
												</button>
												<button
													type="button"
													class="button button-small dupcap-rescan-issue-button dupcap-ignore-button"
													data-dupcap-ignore="<?php echo esc_attr( $ignore_key ); ?>"
													title="<?php esc_attr_e( 'Dismiss when this menu link should stay as it is.', 'duplicate-content-addon-for-polylang' ); ?>"
												>
													<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
												</button>
											<?php endif; ?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<div class="dupcap-detail-row-actions">
							<?php $this->render_action_control( $page_fix_url, $page_fix_label ); ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/**
		 * Render compact menu gap table (location + missing languages only).
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return void
		 */
		private function render_menu_gaps_table( array $details ) {
			$rows = $this->get_menu_gap_rows( $details );
			if ( empty( $rows ) ) {
				return;
			}

			$top_url   = admin_url( 'nav-menus.php?action=locations' );
			$top_label = __( 'Manage Menu Locations', 'duplicate-content-addon-for-polylang' );
			?>
			<div class="dupcap-strings-top-actions">
				<?php $this->render_action_control( $top_url, $top_label ); ?>
			</div>
			<div class="dupcap-table-wrap">
				<table class="widefat striped dupcap-menu-gaps-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Theme location', 'duplicate-content-addon-for-polylang' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Missing languages', 'duplicate-content-addon-for-polylang' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'duplicate-content-addon-for-polylang' ); ?></th>
						</tr>
					</thead>
					<tbody data-dupcap-paginate>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['location_label'] ); ?></th>
								<td>
									<div class="dupcap-content-lang-chips">
										<?php foreach ( $row['missing'] as $missing ) : ?>
											<?php
											$slug      = ! empty( $missing['slug'] ) ? (string) $missing['slug'] : '';
											$flag_html = $this->get_language_flag_html( $slug );
											$code_text = strtoupper( $slug );
											$lang_name = ! empty( $missing['name'] ) ? (string) $missing['name'] : $code_text;
											?>
											<a
												class="dupcap-content-lang-chip dupcap-content-lang-chip-link"
												href="<?php echo esc_url( admin_url( 'nav-menus.php?action=locations' ) ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												title="<?php echo esc_attr( sprintf( 
													/* translators: %s: language name */
													__( 'Assign menu location for %s', 'duplicate-content-addon-for-polylang' ), $lang_name ) ); ?>"
											>
												<?php if ( '' !== $flag_html ) : ?>
													<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
												<?php endif; ?>
												<span class="dupcap-content-lang-chip-code"><?php echo esc_html( $code_text ); ?></span>
											</a>
										<?php endforeach; ?>
									</div>
								</td>
								<td>
									<div class="dupcap-content-actions">
										<?php
										$menu_ignore_keys = $row['ignore_keys'];
										if ( ! empty( $row['ignore_key'] ) ) {
											$menu_ignore_keys[] = (string) $row['ignore_key'];
										}
										// Reports saved before checks stored ignore keys still need a usable key.
										if ( empty( $menu_ignore_keys ) && ! empty( $row['location'] ) && ! empty( $row['missing'] ) ) {
											foreach ( $row['missing'] as $m_item ) {
												$m_slug = ! empty( $m_item['slug'] ) ? (string) $m_item['slug'] : '';
												if ( '' !== $m_slug ) {
													$menu_ignore_keys[] = DUPCAP_Ignored_Issues::menu_key( (string) $row['location'], $m_slug );
												}
											}
										}
										$menu_ignore_str = implode( ',', array_values( array_unique( array_filter( $menu_ignore_keys ) ) ) );
										?>
										<?php if ( ! empty( $menu_ignore_str ) ) : ?>
											<button
												type="button"
												class="button button-small dupcap-rescan-issue-button dupcap-ignore-button"
												data-dupcap-ignore="<?php echo esc_attr( $menu_ignore_str ); ?>"
												title="<?php esc_attr_e( 'Dismiss menu warnings for this location', 'duplicate-content-addon-for-polylang' ); ?>"
											>
												<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
											</button>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		}

		/**
		 * Build gap-only rows for menu assignment display.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return array<int, array{location: string, location_label: string, missing: array<int, array{slug: string, name: string}>, ignore_key: string, ignore_keys: array<int, string>, assign_url: string, action_label: string}>
		 */
		private function get_menu_gap_rows( array $details ) {
			$details = $this->consolidate_menu_details( $details );

			$language_names = array();
			if ( function_exists( 'pll_languages_list' ) ) {
				$languages = pll_languages_list( array( 'fields' => false ) );
				if ( is_array( $languages ) ) {
					foreach ( $languages as $language ) {
						if ( is_object( $language ) && ! empty( $language->slug ) ) {
							$language_names[ $language->slug ] = ! empty( $language->name ) ? $language->name : strtoupper( $language->slug );
						}
					}
				}
			}

			$rows = array();

			foreach ( $details as $detail ) {
				$missing = array();

				if ( ! empty( $detail['missing'] ) && is_array( $detail['missing'] ) ) {
					foreach ( $detail['missing'] as $language ) {
						if ( empty( $language['slug'] ) ) {
							continue;
						}
						$missing[] = array(
							'slug' => $language['slug'],
							'name' => ! empty( $language['name'] ) ? $language['name'] : strtoupper( $language['slug'] ),
						);
					}
				} elseif ( ! empty( $detail['languages'] ) && is_array( $detail['languages'] ) ) {
					$first = reset( $detail['languages'] );
					if ( is_array( $first ) && array_key_exists( 'assigned', $first ) ) {
						foreach ( $detail['languages'] as $language ) {
							if ( empty( $language['assigned'] ) && ! empty( $language['slug'] ) ) {
								$missing[] = array(
									'slug' => $language['slug'],
									'name' => ! empty( $language['name'] ) ? $language['name'] : strtoupper( $language['slug'] ),
								);
							}
						}
					}
				}

				if ( empty( $missing ) && ! empty( $detail['missing_languages'] ) && is_array( $detail['missing_languages'] ) ) {
					foreach ( $detail['missing_languages'] as $slug ) {
						if ( ! is_string( $slug ) || '' === $slug ) {
							continue;
						}
						$missing[] = array(
							'slug' => $slug,
							'name' => isset( $language_names[ $slug ] ) ? $language_names[ $slug ] : strtoupper( $slug ),
						);
					}
				}

				if ( empty( $missing ) ) {
					continue;
				}

				$rows[] = array(
					'location'       => ! empty( $detail['location'] ) ? (string) $detail['location'] : '',
					'location_label' => $this->get_menu_location_label( $detail ),
					'missing'        => $missing,
					'ignore_key'     => ! empty( $detail['ignore_key'] ) ? (string) $detail['ignore_key'] : '',
					'ignore_keys'    => ! empty( $detail['ignore_keys'] ) && is_array( $detail['ignore_keys'] ) ? $detail['ignore_keys'] : array(),
					'assign_url'     => ! empty( $detail['assign_url'] ) ? (string) $detail['assign_url'] : admin_url( 'nav-menus.php?action=locations' ),
					'action_label'   => __( 'Manage menu locations', 'duplicate-content-addon-for-polylang' ),
				);
			}

			return $rows;
		}

		/**
		 * Merge menu detail rows that share the same theme location.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return array<int, array<string, mixed>>
		 */
		private function consolidate_menu_details( array $details ) {
			if ( empty( $details ) ) {
				return $details;
			}

			$language_names = array();
			if ( function_exists( 'pll_languages_list' ) ) {
				$languages = pll_languages_list( array( 'fields' => false ) );
				if ( is_array( $languages ) ) {
					foreach ( $languages as $language ) {
						if ( is_object( $language ) && ! empty( $language->slug ) ) {
							$language_names[ $language->slug ] = ! empty( $language->name ) ? $language->name : strtoupper( $language->slug );
						}
					}
				}
			}

			$grouped = array();

			foreach ( $details as $detail ) {
				$key = $this->get_menu_detail_location_key( $detail );
				if ( '' === $key ) {
					continue;
				}

				if ( ! isset( $grouped[ $key ] ) ) {
					$grouped[ $key ] = array(
						'location'          => ! empty( $detail['location'] ) ? (string) $detail['location'] : '',
						'location_label'    => $this->get_menu_location_label( $detail ),
						'missing'           => array(),
						'missing_languages' => array(),
						'ignore_keys'       => array(),
						'assign_url'        => '',
					);
				}

				if ( ! empty( $detail['ignore_key'] ) ) {
					$grouped[ $key ]['ignore_keys'][] = (string) $detail['ignore_key'];
				}

				if ( empty( $grouped[ $key ]['assign_url'] ) && ! empty( $detail['assign_url'] ) ) {
					$grouped[ $key ]['assign_url'] = (string) $detail['assign_url'];
				}

				if ( ! empty( $detail['missing'] ) && is_array( $detail['missing'] ) ) {
					foreach ( $detail['missing'] as $language ) {
						if ( ! is_array( $language ) || empty( $language['slug'] ) ) {
							continue;
						}
						$slug = (string) $language['slug'];
						$grouped[ $key ]['missing'][ $slug ] = array(
							'slug' => $slug,
							'name' => ! empty( $language['name'] ) ? (string) $language['name'] : ( isset( $language_names[ $slug ] ) ? $language_names[ $slug ] : strtoupper( $slug ) ),
						);
					}
				}

				if ( ! empty( $detail['languages'] ) && is_array( $detail['languages'] ) ) {
					$first = reset( $detail['languages'] );
					if ( is_array( $first ) && array_key_exists( 'assigned', $first ) ) {
						foreach ( $detail['languages'] as $language ) {
							if ( empty( $language['assigned'] ) && ! empty( $language['slug'] ) ) {
								$slug = (string) $language['slug'];
								$grouped[ $key ]['missing'][ $slug ] = array(
									'slug' => $slug,
									'name' => ! empty( $language['name'] ) ? (string) $language['name'] : strtoupper( $slug ),
								);
							}
						}
					}
				}

				if ( ! empty( $detail['missing_languages'] ) && is_array( $detail['missing_languages'] ) ) {
					foreach ( $detail['missing_languages'] as $slug ) {
						if ( ! is_string( $slug ) || '' === $slug ) {
							continue;
						}
						if ( ! isset( $grouped[ $key ]['missing'][ $slug ] ) ) {
							$grouped[ $key ]['missing'][ $slug ] = array(
								'slug' => $slug,
								'name' => isset( $language_names[ $slug ] ) ? $language_names[ $slug ] : strtoupper( $slug ),
							);
						}
					}
				}
			}

			$consolidated = array();
			foreach ( $grouped as $group ) {
				$group['ignore_keys']       = array_values( array_unique( array_filter( $group['ignore_keys'] ) ) );
				$group['missing']           = array_values( $group['missing'] );
				$group['missing_languages'] = array_map(
					function ( $language ) {
						return $language['slug'];
					},
					$group['missing']
				);
				$consolidated[]             = $group;
			}

			return $consolidated;
		}

		/**
		 * Stable grouping key for a menu detail row.
		 *
		 * @param array<string, mixed> $detail Detail row.
		 * @return string
		 */
		private function get_menu_detail_location_key( array $detail ) {
			if ( ! empty( $detail['location'] ) ) {
				return 'loc:' . sanitize_key( (string) $detail['location'] );
			}

			$label = $this->get_menu_location_label( $detail );
			if ( '' !== $label ) {
				return 'label:' . $label;
			}

			return '';
		}

		/**
		 * Resolve a readable menu location label from a detail row.
		 *
		 * Always returns English for common locations so a non-English admin
		 * profile (e.g. Hindi) does not change the Theme location column.
		 *
		 * @param array<string, mixed> $detail Detail row.
		 * @return string
		 */
		private function get_menu_location_label( array $detail ) {
			$location = ! empty( $detail['location'] ) ? (string) $detail['location'] : '';
			$fallback = '';

			if ( ! empty( $detail['location_label'] ) ) {
				$fallback = (string) $detail['location_label'];
			} elseif ( ! empty( $detail['label'] ) ) {
				$label = (string) $detail['label'];
				if ( preg_match( '/^"(.+)" menu not set for all languages$/', $label, $matches ) ) {
					$fallback = $matches[1];
				} else {
					$fallback = $label;
				}
			} elseif ( ! empty( $detail['menu'] ) ) {
				$label = (string) $detail['menu'];
				if ( preg_match( '/^"(.+)" menu not set for all languages$/', $label, $matches ) ) {
					$fallback = $matches[1];
				} else {
					$fallback = $label;
				}
			}

			// Theme location slugs are often menu-1 / menu-2 while the registered
			// label is Header / Footer. Prefer the live theme label when present.
			if ( '' !== $location && function_exists( 'get_registered_nav_menus' ) ) {
				$registered = get_registered_nav_menus();
				if ( is_array( $registered ) && ! empty( $registered[ $location ] ) ) {
					$fallback = (string) $registered[ $location ];
				}
			}

			return DUPCAP_Check::english_menu_location_label( $location, $fallback );
		}

		/**
		 * Build a user-friendly summary for menu assignment groups.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return string
		 */
		private function get_menu_group_message( array $details ) {
			$locations = count( $this->get_menu_gap_rows( $details ) );

			if ( $locations < 1 ) {
				return '';
			}

			return sprintf(
				/* translators: %d: theme location count. */
				_n(
					'%d theme location is missing a menu in one or more languages.',
					'%d theme locations are missing a menu in one or more languages.',
					$locations,
					'duplicate-content-addon-for-polylang'
				),
				$locations
			);
		}


}
