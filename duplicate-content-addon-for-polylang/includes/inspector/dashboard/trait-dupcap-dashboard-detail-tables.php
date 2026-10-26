<?php


defined( 'ABSPATH' ) || exit;

/**
 * Detail Tables methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Detail_Tables_Trait {

		/**
		 * Render string translation details with top action button and missing languages table.
		 *
		 * @param array<string, mixed> $group Issue group.
		 * @return void
		 */
		private function render_strings_details( array $group ) {
			if ( empty( $group['details'] ) ) {
				return;
			}

			$grouped = $this->group_strings_by_item( $group['details'] );

			$str_top_url   = ! empty( $group['action_url'] ) ? $group['action_url'] : admin_url( 'admin.php?page=mlang_strings' );
			$str_top_label = ! empty( $group['action_label'] ) ? $group['action_label'] : __( 'Translate Strings', 'duplicate-content-addon-for-polylang' );
			?>
			<div class="dupcap-strings-top-actions">
				<?php $this->render_action_control( $str_top_url, $str_top_label ); ?>
			</div>
			<?php
			$this->render_strings_table( $grouped );
		}

		/**
		 * Group string rows by the string itself, with its missing languages.
		 *
		 * One entry per string, exactly as the table renders it, so a card count
		 * taken from this never multiplies strings by languages.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return array<string, array<string, mixed>> Rows keyed by string key.
		 */
		private function group_strings_by_item( array $details ) {
			$grouped = array();

			foreach ( $details as $detail ) {
				$raw_label = isset( $detail['label'] ) ? (string) $detail['label'] : ( isset( $detail['message'] ) ? (string) $detail['message'] : '' );
				$s_title   = ! empty( $detail['title'] ) ? (string) $detail['title'] : '';
				$lang_slug = ! empty( $detail['lang_slug'] ) ? (string) $detail['lang_slug'] : '';
				$lang_name = ! empty( $detail['lang_name'] ) ? (string) $detail['lang_name'] : '';

				if ( '' === $s_title || '' === $lang_slug ) {
					if ( false !== strpos( $raw_label, ' — ' ) ) {
						$parts = explode( ' — ', $raw_label, 2 );
						if ( '' === $s_title && ! empty( $parts[1] ) ) {
							$s_title = trim( $parts[1] );
						}
						if ( '' === $lang_slug && ! empty( $parts[0] ) ) {
							if ( preg_match( '/\(([\w\-]+)\)/', $parts[0], $m ) ) {
								$lang_slug = strtolower( $m[1] );
							}
						}
					}
				}

				if ( '' === $lang_slug && ! empty( $raw_label ) ) {
					if ( preg_match( '/\(([\w\-]+)\)/', $raw_label, $m ) ) {
						$lang_slug = strtolower( $m[1] );
					}
				}

				if ( '' === $s_title ) {
					$s_title = $raw_label ? $raw_label : __( 'Site String', 'duplicate-content-addon-for-polylang' );
				}

				$s_key = ! empty( $detail['string_key'] ) ? (string) $detail['string_key'] : sanitize_title( $s_title );

				if ( ! isset( $grouped[ $s_key ] ) ) {
					$s_meta = array_filter(
						array(
							! empty( $detail['string_name'] ) ? (string) $detail['string_name'] : '',
							! empty( $detail['string_group'] ) ? (string) $detail['string_group'] : '',
						)
					);

					$grouped[ $s_key ] = array(
						'title'       => $s_title,
						'meta'        => implode( ' · ', array_unique( $s_meta ) ),
						'string_key'  => $s_key,
						'missing'     => array(),
						'ignore_keys' => array(),
					);
				}

				if ( '' !== $lang_slug ) {
					$grouped[ $s_key ]['missing'][ $lang_slug ] = array(
						'slug' => $lang_slug,
						'name' => $lang_name ? $lang_name : strtoupper( $lang_slug ),
					);
				}

				if ( ! empty( $detail['ignore_key'] ) ) {
					$grouped[ $s_key ]['ignore_keys'][] = $detail['ignore_key'];
				}
			}

			return $grouped;
		}

		/**
		 * Render grouped string rows as a table.
		 *
		 * @param array<string, array<string, mixed>> $grouped Rows from group_strings_by_item().
		 * @return void
		 */
		private function render_strings_table( array $grouped ) {
			?>
			<div class="dupcap-table-wrap">
				<table class="widefat striped dupcap-strings-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'String', 'duplicate-content-addon-for-polylang' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Missing languages', 'duplicate-content-addon-for-polylang' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'duplicate-content-addon-for-polylang' ); ?></th>
						</tr>
					</thead>
					<tbody data-dupcap-paginate>
						<?php foreach ( $grouped as $item ) : ?>
							<tr data-dupcap-title="<?php echo esc_attr( $item['title'] ); ?>">
								<th scope="row">
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<?php if ( '' !== $item['meta'] ) : ?>
										<br><span class="description"><?php echo esc_html( $item['meta'] ); ?></span>
									<?php endif; ?>
								</th>
								<td>
									<div class="dupcap-content-lang-chips">
										<?php foreach ( $item['missing'] as $missing ) : ?>
											<?php
											$slug      = ! empty( $missing['slug'] ) ? (string) $missing['slug'] : '';
											$flag_html = $this->get_language_flag_html( $slug );
											$code_text = strtoupper( $slug );
											?>
											<a
												class="dupcap-content-lang-chip dupcap-content-lang-chip-link"
												href="<?php echo esc_url( admin_url( 'admin.php?page=mlang_strings' ) ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												title="<?php echo esc_attr( sprintf( 
													/* translators: %s: language name */
													__( 'Translate string into %s', 'duplicate-content-addon-for-polylang' ), ! empty( $missing['name'] ) ? $missing['name'] : strtoupper( $slug ) ) ); ?>"
											>
												<?php if ( '' !== $flag_html ) : ?>
													<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
												<?php endif; ?>
												<span class="dupcap-content-lang-chip-code"><?php echo esc_html( ! empty( $missing['name'] ) ? $missing['name'] : $code_text ); ?></span>
											</a>
										<?php endforeach; ?>
									</div>
								</td>
								<td>
									<div class="dupcap-detail-row-actions">
										<?php if ( ! empty( $item['ignore_keys'] ) ) : ?>
											<button
												type="button"
												class="button button-small dupcap-ignore-button"
												data-dupcap-ignore="<?php echo esc_attr( implode( ',', array_unique( $item['ignore_keys'] ) ) ); ?>"
												title="<?php esc_attr_e( 'Dismiss when the original text is correct for every language, such as a brand name.', 'duplicate-content-addon-for-polylang' ); ?>"
											><?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?></button>
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
		 * Render Elementor library missing-translation rows with fix / rescan / ignore.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return void
		 */
		private function render_elementor_library_details( array $details ) {
			if ( empty( $details ) ) {
				return;
			}
			?>
			<div class="dupcap-detail-list" data-dupcap-paginate>
				<?php foreach ( $details as $detail ) : ?>
					<?php
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$label         = isset( $detail['label'] ) ? (string) $detail['label'] : '';
					$message       = isset( $detail['message'] ) ? (string) $detail['message'] : '';
					$action_url    = isset( $detail['action_url'] ) ? (string) $detail['action_url'] : '';
					$action_label  = isset( $detail['action_label'] ) ? (string) $detail['action_label'] : __( 'Add translation', 'duplicate-content-addon-for-polylang' );
					$ignore_key    = isset( $detail['ignore_key'] ) ? (string) $detail['ignore_key'] : '';
					$template_type = isset( $detail['template_type'] ) ? (string) $detail['template_type'] : '';
					$language      = isset( $detail['language'] ) ? (string) $detail['language'] : '';
					$template_id   = isset( $detail['template_id'] ) ? (int) $detail['template_id'] : 0;
					?>
					<div class="dupcap-detail-row" data-dupcap-library-issue<?php echo '' !== $template_type ? ' data-dupcap-document-type="' . esc_attr( $template_type ) . '"' : ''; ?><?php echo $template_id > 0 ? ' data-dupcap-object-id="' . esc_attr( (string) $template_id ) . '"' : ''; ?>>
						<strong><?php echo esc_html( $label ); ?></strong>
						<span><?php echo esc_html( $message ); ?></span>
						<div class="dupcap-detail-row-actions">
							<?php if ( '' !== $action_url ) : ?>
								<a
									class="button button-small"
									href="<?php echo esc_url( $action_url ); ?>"
									target="_blank"
									rel="noopener noreferrer"
									data-dupcap-track-fix="elementor_library"
									data-template-type="<?php echo esc_attr( $template_type ); ?>"
									data-language="<?php echo esc_attr( $language ); ?>"
									data-ignore-key="<?php echo esc_attr( $ignore_key ); ?>"
								><?php echo esc_html( $action_label ); ?></a>
							<?php endif; ?>
							<?php
							/*
							 * Same offer the Elementor Templates table makes when its
							 * rows carry post ids. Without it this fallback was the
							 * one place an Elementor row had no AI Translation button
							 * at all, and the class is what the install/activate
							 * modal listens for.
							 */
							$ai_trans_url = $template_id > 0
								? $this->get_post_type_ai_translation_url( get_post_type( $template_id ), $template_id )
								: '';
							?>
							<?php if ( '' !== $ai_trans_url ) : ?>
								<a
									class="button button-small button-primary dupcap-ai-translate-btn"
									href="<?php echo esc_url( $ai_trans_url ); ?>"
									target="_blank"
									rel="noopener noreferrer"
									title="<?php esc_attr_e( 'Go to list view for AI Translation', 'duplicate-content-addon-for-polylang' ); ?>"
									data-dupcap-track-fix="elementor_library"
									data-post-id="<?php echo esc_attr( (string) $template_id ); ?>"
									data-gap-type="missing"
									data-languages="<?php echo esc_attr( $language ); ?>"
								>
									<?php esc_html_e( 'AI Translation', 'duplicate-content-addon-for-polylang' ); ?>
								</a>
							<?php endif; ?>
							<button
								type="button"
								class="button button-small dupcap-rescan-issue-button"
								data-dupcap-rescan-library-issue
								data-template-type="<?php echo esc_attr( $template_type ); ?>"
								data-language="<?php echo esc_attr( $language ); ?>"
								data-template-id="<?php echo esc_attr( (string) $template_id ); ?>"
								data-ignore-key="<?php echo esc_attr( $ignore_key ); ?>"
							>
								<span class="dashicons dashicons-update" aria-hidden="true"></span>
								<?php esc_html_e( 'Rescan issue', 'duplicate-content-addon-for-polylang' ); ?>
							</button>
							<?php if ( '' !== $ignore_key ) : ?>
								<button
									type="button"
									class="button button-small dupcap-rescan-issue-button dupcap-ignore-button"
									data-dupcap-ignore="<?php echo esc_attr( $ignore_key ); ?>"
									title="<?php esc_attr_e( 'Dismiss this template translation warning.', 'duplicate-content-addon-for-polylang' ); ?>"
								>
									<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
								</button>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/**
		 * Render routing URL detail rows with the language next to the page type.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return void
		 */
		private function render_routing_details( array $details ) {
			if ( empty( $details ) ) {
				return;
			}

			?>
			<div class="dupcap-detail-list" data-dupcap-paginate>
				<?php foreach ( $details as $detail ) : ?>
					<?php
					if ( ! is_array( $detail ) ) {
						continue;
					}

					$raw_label = isset( $detail['label'] ) ? trim( (string) $detail['label'] ) : __( 'Page', 'duplicate-content-addon-for-polylang' );
					$lang_slug = $this->routing_detail_language_slug( $detail );
					$lang_chip = '';

					if ( '' !== $lang_slug ) {
						$name = class_exists( 'DUPCAP_Elementor_Fields' )
							? DUPCAP_Elementor_Fields::language_label( $lang_slug )
							: strtoupper( $lang_slug );
						$lang_chip = ( $name && strtolower( $name ) !== $lang_slug )
							? sprintf(
								/* translators: 1: language name, 2: language code */
								__( '%1$s (%2$s)', 'duplicate-content-addon-for-polylang' ),
								$name,
								$lang_slug
							)
							: $lang_slug;

						// Prefer a clean target label when language was already baked into the string.
						if ( preg_match( '/^(.+?)\s+[—–-]\s+.+$/u', $raw_label, $matches ) ) {
							$raw_label = trim( $matches[1] );
						}
					}

					$show_detail_action = ! empty( $detail['action_url'] ) && ! empty( $detail['action_label'] );
					$verdict            = isset( $detail['verdict'] ) ? (string) $detail['verdict'] : '';
					$detail_url         = ! empty( $detail['url'] ) ? (string) $detail['url'] : '';
					$message            = isset( $detail['message'] ) ? (string) $detail['message'] : '';
					$is_untestable      = ( 'untestable' === $verdict )
						|| ( 0 === strpos( $message, 'Exact issue:' ) )
						|| false !== stripos( $raw_label, 'could not finish' )
						|| false !== stripos( $raw_label, 'timed out' )
						|| false !== stripos( $message, 'timed out' );

					if ( $is_untestable && empty( $detail['ignore_key'] ) && class_exists( 'DUPCAP_Routing_Scanner' ) ) {
						$detail['ignore_key']   = DUPCAP_Routing_Scanner::untestable_ignore_key();
						$detail['ignore_label'] = __( 'Ignore', 'duplicate-content-addon-for-polylang' );
					}
					if ( $is_untestable && '' === $detail_url ) {
						$detail_url = home_url( '/' );
					}

					// Avoid two identical homepage buttons for the untestable guard row.
					if (
						$show_detail_action
						&& '' !== $detail_url
						&& ! empty( $detail['action_url'] )
						&& untrailingslashit( $detail_url ) === untrailingslashit( (string) $detail['action_url'] )
					) {
						$show_detail_action = false;
					}
					?>
					<div class="dupcap-detail-row">
						<span class="dupcap-detail-row-label">
							<strong><?php echo esc_html( $raw_label ); ?></strong>
							<?php if ( '' !== $lang_chip ) : ?>
								<span class="dupcap-lang-chip"><?php echo esc_html( $lang_chip ); ?></span>
							<?php endif; ?>
						</span>
						<?php if ( $is_untestable || 0 === strpos( $message, 'Exact issue:' ) ) : ?>
							<?php $this->render_routing_untestable_message( $message ); ?>
						<?php else : ?>
							<span><?php echo esc_html( $message ); ?></span>
						<?php endif; ?>
						<div class="dupcap-detail-row-actions">
							<?php if ( '' !== $detail_url ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open page', 'duplicate-content-addon-for-polylang' ); ?></a>
							<?php endif; ?>
							<?php if ( $show_detail_action ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $detail['action_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $detail['action_label'] ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $detail['ignore_key'] ) ) : ?>
								<button
									type="button"
									class="button button-small dupcap-ignore-button"
									data-dupcap-ignore="<?php echo esc_attr( $detail['ignore_key'] ); ?>"
									title="<?php esc_attr_e( 'Dismiss when the page loads correctly in your browser.', 'duplicate-content-addon-for-polylang' ); ?>"
								><?php echo esc_html( ! empty( $detail['ignore_label'] ) ? $detail['ignore_label'] : __( 'Ignore', 'duplicate-content-addon-for-polylang' ) ); ?></button>
							<?php endif; ?>
						</div>
					</div>
					<?php
				endforeach;
				?>
			</div>
			<?php
		}

		/**
		 * Split untestable routing copy into Exact issue / What to do for easier reading.
		 *
		 * @param string $message Full message.
		 * @return void
		 */
		private function render_routing_untestable_message( $message ) {
			$message = (string) $message;
			$issue   = '';
			$action  = '';

			if ( preg_match( '/Exact issue:\s*(.+?)(?:\s+What to do:\s*(.+))?$/us', $message, $matches ) ) {
				$issue  = trim( $matches[1] );
				$action = isset( $matches[2] ) ? trim( $matches[2] ) : '';
			}

			if ( '' === $issue ) {
				echo '<span>' . esc_html( $message ) . '</span>';
				return;
			}
			?>
			<div class="dupcap-step-instructions dupcap-routing-untestable-steps">
				<div class="dupcap-step-item">
					<span class="dupcap-step-num" aria-hidden="true">1</span>
					<span class="dupcap-step-label"><?php esc_html_e( 'Exact issue:', 'duplicate-content-addon-for-polylang' ); ?></span>
					<span class="dupcap-step-desc"><?php echo esc_html( $issue ); ?></span>
				</div>
				<?php if ( '' !== $action ) : ?>
					<div class="dupcap-step-item">
						<span class="dupcap-step-num" aria-hidden="true">2</span>
						<span class="dupcap-step-label"><?php esc_html_e( 'What to do:', 'duplicate-content-addon-for-polylang' ); ?></span>
						<span class="dupcap-step-desc"><?php echo esc_html( $action ); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Language slug for a routing detail row (including older cached rows).
		 *
		 * @param array<string, mixed> $detail Detail row.
		 * @return string
		 */
		private function routing_detail_language_slug( array $detail ) {
			if ( ! empty( $detail['language'] ) ) {
				return sanitize_key( (string) $detail['language'] );
			}

			$key = isset( $detail['ignore_key'] ) ? (string) $detail['ignore_key'] : '';
			// Format: url:route_home:en:<hash>
			if ( preg_match( '/^url:route_[a-z0-9_]+:([a-z0-9_-]+):/', $key, $matches ) ) {
				return sanitize_key( $matches[1] );
			}

			return '';
		}

		/**
		 * Render block navigation detail rows.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return void
		 */
		private function render_navigation_details( array $details ) {
			if ( empty( $details ) ) {
				return;
			}
			?>
			<div class="dupcap-detail-list" data-dupcap-paginate>
				<?php foreach ( $details as $detail ) : ?>
					<div class="dupcap-detail-row">
						<strong><?php echo esc_html( isset( $detail['label'] ) ? (string) $detail['label'] : '' ); ?></strong>
						<?php if ( ! empty( $detail['context'] ) ) : ?>
							<p class="dupcap-content-link-context">
								<?php esc_html_e( 'Found in:', 'duplicate-content-addon-for-polylang' ); ?>
								<em>"<?php echo esc_html( (string) $detail['context'] ); ?>"</em>
							</p>
						<?php endif; ?>
						<span><?php echo esc_html( isset( $detail['message'] ) ? (string) $detail['message'] : '' ); ?></span>
						<?php if ( ! empty( $detail['action_url'] ) && ! empty( $detail['action_label'] ) ) : ?>
							<div class="dupcap-detail-row-actions">
								<a class="button button-small" href="<?php echo esc_url( (string) $detail['action_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( (string) $detail['action_label'] ); ?>
								</a>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/**
		 * Join shortcode / placeholder rows of one source page into a single row.
		 *
		 * The check reports one row per translation. A page translated into three
		 * languages would show three near-identical rows, so the card shows one row
		 * per source page with a link chip for each language. Ignore and Rescan
		 * take the keys of all its languages at once.
		 *
		 * @param array<int, array<string, mixed>> $details Per-translation rows.
		 * @return array<int, array<string, mixed>>
		 */
		private function merge_placeholder_rows( array $details ) {
			$names = array();
			if ( function_exists( 'pll_languages_list' ) ) {
				$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
				$list  = (array) pll_languages_list( array( 'fields' => 'name' ) );
				foreach ( $slugs as $i => $slug ) {
					$names[ (string) $slug ] = isset( $list[ $i ] ) ? (string) $list[ $i ] : (string) $slug;
				}
			}

			$rows = array();
			foreach ( $details as $detail ) {
				if ( ! is_array( $detail ) ) {
					continue;
				}
				$post_id = isset( $detail['post_id'] ) ? (int) $detail['post_id'] : 0;
				$slug    = isset( $detail['language'] ) ? (string) $detail['language'] : '';
				if ( $post_id <= 0 || '' === $slug ) {
					$rows[] = $detail;
					continue;
				}

				if ( ! isset( $rows[ 'p' . $post_id ] ) ) {
					$type_obj   = get_post_type_object( (string) get_post_type( $post_id ) );
					$type_label = ( $type_obj && ! empty( $type_obj->labels->singular_name ) ) ? (string) $type_obj->labels->singular_name : __( 'Item', 'duplicate-content-addon-for-polylang' );
					$title      = (string) get_the_title( $post_id );
					$detail['label'] = '' !== $title
						? sprintf(
							/* translators: 1: content type, e.g. Page 2: its title */
							__( '%1$s "%2$s"', 'duplicate-content-addon-for-polylang' ),
							$type_label,
							$title
						)
						: sprintf(
							/* translators: 1: content type, e.g. Page 2: its ID number */
							__( '%1$s #%2$d', 'duplicate-content-addon-for-polylang' ),
							$type_label,
							$post_id
						);
					$detail['parts']       = array();
					$detail['ignore_keys'] = array();
					$detail['languages']   = array();
					$rows[ 'p' . $post_id ] = $detail;
				}

				$message = isset( $detail['message'] ) ? (string) $detail['message'] : '';
				$rows[ 'p' . $post_id ]['parts'][ $message ][] = array(
					'slug' => $slug,
					'name' => isset( $names[ $slug ] ) ? $names[ $slug ] : strtoupper( $slug ),
					'url'  => isset( $detail['action_url'] ) ? (string) $detail['action_url'] : '',
				);
				if ( ! empty( $detail['ignore_key'] ) ) {
					$rows[ 'p' . $post_id ]['ignore_keys'][] = (string) $detail['ignore_key'];
				}
				$rows[ 'p' . $post_id ]['languages'][] = $slug;
			}

			foreach ( $rows as $key => $row ) {
				if ( empty( $row['parts'] ) ) {
					continue;
				}
				$rows[ $key ]['ignore_key']     = implode( ',', $row['ignore_keys'] );
				$rows[ $key ]['language']       = implode( ',', $row['languages'] );
				$rows[ $key ]['translation_id'] = 0;
				$rows[ $key ]['action_url']     = '';
				$rows[ $key ]['message']        = implode( ' ', array_keys( $row['parts'] ) );
			}

			return array_values( $rows );
		}

		/**
		 * Render identical-content detail rows with fix / rescan / ignore.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return void
		 */
		private function render_content_identical_details( array $details ) {
			if ( empty( $details ) ) {
				return;
			}
			?>
			<div class="dupcap-detail-list" data-dupcap-paginate>
				<?php foreach ( $details as $detail ) : ?>
					<?php
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$ignore_key      = isset( $detail['ignore_key'] ) ? (string) $detail['ignore_key'] : '';
					$post_id         = isset( $detail['post_id'] ) ? (int) $detail['post_id'] : 0;
					$translation_id  = isset( $detail['translation_id'] ) ? (int) $detail['translation_id'] : 0;
					$language        = isset( $detail['language'] ) ? (string) $detail['language'] : '';
					$field           = isset( $detail['field'] ) ? (string) $detail['field'] : '';
					$post_type       = isset( $detail['post_type'] ) ? sanitize_key( (string) $detail['post_type'] ) : '';
					if ( $translation_id <= 0 && ! empty( $detail['action_url'] ) && preg_match( '/[?&]post=(\d+)/', (string) $detail['action_url'], $m ) ) {
						$translation_id = (int) $m[1];
					}
					// Older saved reports may lack IDs; recover them from the ignore key.
					if ( ( $post_id <= 0 || '' === $language ) && '' !== $ignore_key
						&& preg_match( '/^content:(identical|acf|placeholder):(\d+):([^:]+):/', $ignore_key, $id_m ) ) {
						if ( $post_id <= 0 ) {
							$post_id = (int) $id_m[2];
						}
						if ( '' === $language ) {
							$language = sanitize_key( $id_m[3] );
						}
					}
					if ( '' === $post_type && $post_id > 0 ) {
						$post_type = (string) get_post_type( $post_id );
					}
					$ai_trans_url = ( $post_id > 0 )
						? $this->get_post_type_ai_translation_url( $post_type ? $post_type : 'page', $post_id )
						: '';
					?>
					<div class="dupcap-detail-row"<?php echo ! empty( $detail['post_type'] ) ? ' data-dupcap-content-type="' . esc_attr( $detail['post_type'] ) . '"' : ''; ?> data-dupcap-title="<?php echo esc_attr( isset( $detail['label'] ) ? (string) $detail['label'] : '' ); ?>">
						<strong><?php echo esc_html( isset( $detail['label'] ) ? $detail['label'] : '' ); ?></strong>
						<?php if ( ! empty( $detail['parts'] ) ) : ?>
							<div>
								<?php foreach ( $detail['parts'] as $part_message => $part_langs ) : ?>
									<span><?php echo esc_html( (string) $part_message ); ?></span>
									<div class="dupcap-content-lang-chips" style="margin-top: 6px;">
										<?php foreach ( $part_langs as $chip ) : ?>
											<?php $flag_html = $this->get_language_flag_html( $chip['slug'] ); ?>
											<a
												class="dupcap-content-lang-chip dupcap-content-lang-chip-link"
												href="<?php echo esc_url( $chip['url'] ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												title="<?php echo esc_attr( sprintf( /* translators: %s: language name */ __( 'Edit translation (%s)', 'duplicate-content-addon-for-polylang' ), $chip['name'] ) ); ?>"
											>
												<?php if ( '' !== $flag_html ) : ?>
													<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
												<?php endif; ?>
												<span class="dupcap-content-lang-chip-code"><?php echo esc_html( strtoupper( $chip['slug'] ) ); ?></span>
											</a>
										<?php endforeach; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<span><?php echo esc_html( isset( $detail['message'] ) ? $detail['message'] : '' ); ?></span>
						<?php endif; ?>
						<div class="dupcap-detail-row-actions">
							<?php if ( ! empty( $detail['action_url'] ) ) : ?>
								<a
									class="button button-small"
									href="<?php echo esc_url( $detail['action_url'] ); ?>"
									target="_blank"
									rel="noopener noreferrer"
									data-dupcap-track-fix="identical"
									data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
									data-translation-id="<?php echo esc_attr( (string) $translation_id ); ?>"
									data-language="<?php echo esc_attr( $language ); ?>"
									data-field="<?php echo esc_attr( $field ); ?>"
									data-ignore-key="<?php echo esc_attr( $ignore_key ); ?>"
								><?php echo esc_html( ! empty( $detail['action_label'] ) ? $detail['action_label'] : __( 'Edit translation', 'duplicate-content-addon-for-polylang' ) ); ?></a>
							<?php endif; ?>
							<?php if ( '' !== $ai_trans_url ) : ?>
								<a
									class="button button-small button-primary dupcap-ai-translate-btn"
									href="<?php echo esc_url( $ai_trans_url ); ?>"
									target="_blank"
									rel="noopener noreferrer"
									title="<?php esc_attr_e( 'Go to list view for AI Translation', 'duplicate-content-addon-for-polylang' ); ?>"
									data-dupcap-track-fix="identical"
									data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
									data-translation-id="<?php echo esc_attr( (string) $translation_id ); ?>"
									data-language="<?php echo esc_attr( $language ); ?>"
									data-field="<?php echo esc_attr( $field ); ?>"
									data-ignore-key="<?php echo esc_attr( $ignore_key ); ?>"
								>
									<?php esc_html_e( 'AI Translation', 'duplicate-content-addon-for-polylang' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( '' !== $ignore_key ) : ?>
								<button
									type="button"
									class="button button-small dupcap-rescan-issue-button"
									data-dupcap-rescan-content-detail
									data-kind="identical"
									data-ignore-key="<?php echo esc_attr( $ignore_key ); ?>"
									data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
									data-translation-id="<?php echo esc_attr( (string) $translation_id ); ?>"
									data-language="<?php echo esc_attr( $language ); ?>"
									data-field="<?php echo esc_attr( $field ); ?>"
								>
									<span class="dashicons dashicons-update" aria-hidden="true"></span>
									<?php esc_html_e( 'Rescan issue', 'duplicate-content-addon-for-polylang' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( '' !== $ignore_key ) : ?>
								<button
									type="button"
									class="button button-small dupcap-rescan-issue-button dupcap-ignore-button"
									data-dupcap-ignore="<?php echo esc_attr( $ignore_key ); ?>"
									title="<?php esc_attr_e( 'Dismiss when the original text is correct for every language, such as a brand name.', 'duplicate-content-addon-for-polylang' ); ?>"
								>
									<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
								</button>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/**
		 * Render content-link issues in the Elementor-style card layout.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return void
		 */
		private function render_content_link_details( array $details ) {
			?>
			<ul class="dupcap-elementor-examples dupcap-content-link-examples" data-dupcap-paginate>
				<?php foreach ( $details as $detail ) : ?>
					<?php $this->render_content_link_item( $detail ); ?>
				<?php endforeach; ?>
			</ul>
			<?php
		}

		/**
		 * Render one content-link issue card.
		 *
		 * @param array<string, mixed> $detail Detail row.
		 * @return void
		 */
		private function render_content_link_item( array $detail ) {
			$summary   = isset( $detail['label'] ) ? (string) $detail['label'] : '';
			$message   = isset( $detail['message'] ) ? (string) $detail['message'] : '';
			$context   = isset( $detail['context'] ) ? (string) $detail['context'] : '';
			$code      = isset( $detail['code'] ) ? (string) $detail['code'] : '';
			$is_dead   = ( 'dead_link' === $code );
			$fix_label = ! empty( $detail['action_label'] )
				? (string) $detail['action_label']
				: ( $is_dead ? __( 'Edit page with link', 'duplicate-content-addon-for-polylang' ) : __( 'Fix link', 'duplicate-content-addon-for-polylang' ) );

			$translation_id = isset( $detail['translation_id'] ) ? (int) $detail['translation_id'] : 0;
			if ( $translation_id <= 0 && ! empty( $detail['action_url'] ) && preg_match( '/[?&]post=(\d+)/', (string) $detail['action_url'], $m ) ) {
				$translation_id = (int) $m[1];
			}

			$dead_url = '';
			if ( $is_dead ) {
				if ( ! empty( $detail['url'] ) ) {
					$dead_url = (string) $detail['url'];
				} elseif ( ! empty( $detail['current_url'] ) ) {
					$dead_url = (string) $detail['current_url'];
				}
			}

			$page_fix_url   = ! empty( $detail['page_fix_url'] ) ? (string) $detail['page_fix_url'] : '';
			$page_fix_label = ! empty( $detail['page_fix_label'] ) ? (string) $detail['page_fix_label'] : '';
			if ( $is_dead && ( '' === $page_fix_url ) && '' !== $dead_url && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
				$target         = DUPCAP_Elementor_Fields::describe_dead_link_target( $dead_url );
				$page_fix_url   = ! empty( $target['fix_url'] ) ? (string) $target['fix_url'] : '';
				$page_fix_label = ! empty( $target['fix_label'] ) ? (string) $target['fix_label'] : '';
			}

			$has_step_detail = ( 0 === strpos( $message, 'This link goes to' ) ) || $is_dead;
			$example         = array(
				'code'             => $has_step_detail ? $code : '',
				'language'         => isset( $detail['language'] ) ? (string) $detail['language'] : '',
				'suggested_url'    => isset( $detail['suggested_url'] ) ? (string) $detail['suggested_url'] : '',
				'translated_value' => $is_dead ? $dead_url : '',
				'original_value'   => $is_dead ? $dead_url : '',
			);
			?>
			<li<?php echo ! empty( $detail['post_type'] ) ? ' data-dupcap-content-type="' . esc_attr( $detail['post_type'] ) . '"' : ''; ?> data-dupcap-title="<?php echo esc_attr( $summary ); ?>">
				<div class="dupcap-elementor-example-card">
					<span class="dupcap-elementor-example-icon dashicons dashicons-media-document" aria-hidden="true"></span>
					<div class="dupcap-elementor-example-copy">
						<strong class="dupcap-elementor-example-summary"><?php echo esc_html( $summary ); ?></strong>
						<?php if ( '' !== $context ) : ?>
							<p class="dupcap-content-link-context">
								<?php esc_html_e( 'Found in:', 'duplicate-content-addon-for-polylang' ); ?>
								<em>"<?php echo esc_html( $context ); ?>"</em>
							</p>
						<?php endif; ?>
						<?php
						if ( $is_dead ) {
							$current_label = ! empty( $detail['current_url'] ) ? (string) $detail['current_url'] : '';
							if ( '' === $current_label && '' !== $dead_url && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
								$current_label = DUPCAP_Elementor_Fields::relative_path( $dead_url );
								if ( '' === $current_label ) {
									$current_label = DUPCAP_Elementor_Fields::readable_url( $dead_url );
								}
							}
							$kind_hint = ! empty( $detail['kind'] ) ? (string) $detail['kind'] : '';
							$link_text = '' !== $context ? $context : ( '' !== $summary ? $summary : '' );
							$this->render_dead_link_steps( $current_label, $dead_url, $kind_hint, $link_text );
						} else {
							$this->render_elementor_example_detail( $example, $message );
						}
						?>
					</div>
					<div class="dupcap-elementor-example-actions">
						<?php if ( $is_dead && '' !== $page_fix_url ) : ?>
							<a
								class="button button-small dupcap-elementor-example-action"
								href="<?php echo esc_url( $page_fix_url ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								data-dupcap-track-fix="link"
								data-ignore-key="<?php echo esc_attr( isset( $detail['ignore_key'] ) ? (string) $detail['ignore_key'] : '' ); ?>"
							>
								<span class="dashicons dashicons-<?php echo ( ! empty( $detail['kind'] ) && 'missing' !== $detail['kind'] ) ? 'edit' : 'plus-alt'; ?>" aria-hidden="true"></span>
								<?php echo esc_html( $page_fix_label ? $page_fix_label : __( 'Create page', 'duplicate-content-addon-for-polylang' ) ); ?>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $detail['action_url'] ) ) : ?>
							<a
								class="button button-small dupcap-elementor-example-action"
								href="<?php echo esc_url( $detail['action_url'] ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								data-dupcap-track-fix="link"
								data-post-id="<?php echo esc_attr( isset( $detail['post_id'] ) ? (string) (int) $detail['post_id'] : '' ); ?>"
								data-translation-id="<?php echo esc_attr( (string) $translation_id ); ?>"
								data-language="<?php echo esc_attr( isset( $detail['language'] ) ? (string) $detail['language'] : '' ); ?>"
								data-ignore-key="<?php echo esc_attr( isset( $detail['ignore_key'] ) ? (string) $detail['ignore_key'] : '' ); ?>"
							>
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
								<?php echo esc_html( $fix_label ); ?>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $detail['ignore_key'] ) ) : ?>
							<button
								type="button"
								class="button button-small dupcap-rescan-issue-button"
								data-dupcap-rescan-content-detail
								data-kind="link"
								data-ignore-key="<?php echo esc_attr( $detail['ignore_key'] ); ?>"
								data-post-id="<?php echo esc_attr( isset( $detail['post_id'] ) ? (string) (int) $detail['post_id'] : '' ); ?>"
								data-translation-id="<?php echo esc_attr( (string) $translation_id ); ?>"
								data-language="<?php echo esc_attr( isset( $detail['language'] ) ? (string) $detail['language'] : '' ); ?>"
								data-url="<?php echo esc_attr( $dead_url ); ?>"
							>
								<span class="dashicons dashicons-update" aria-hidden="true"></span>
								<?php esc_html_e( 'Rescan issue', 'duplicate-content-addon-for-polylang' ); ?>
							</button>
							<button
								type="button"
								class="button button-small dupcap-rescan-issue-button dupcap-ignore-button"
								data-dupcap-ignore="<?php echo esc_attr( $detail['ignore_key'] ); ?>"
								title="<?php esc_attr_e( 'Dismiss when the original text is correct for every language, such as a brand name.', 'duplicate-content-addon-for-polylang' ); ?>"
							>
								<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
			</li>
			<?php
		}

		/**
		 * Render translated permalink review cards.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return void
		 */
		private function render_translated_permalink_details( array $details ) {
			?>
			<div class="dupcap-permalink-detail-list" data-dupcap-paginate>
				<?php foreach ( $details as $detail ) : ?>
					<?php
					$item_severity = isset( $detail['severity'] ) ? (string) $detail['severity'] : 'info';
					if ( in_array( $item_severity, array( 'critical' ), true ) ) {
						$item_severity = 'crit';
					} elseif ( in_array( $item_severity, array( 'warning' ), true ) ) {
						$item_severity = 'warn';
					}

					$current_path = '';
					$source_path  = '';
					if ( class_exists( 'DUPCAP_Permalink_Diagnostics' ) ) {
						$current_path = DUPCAP_Permalink_Diagnostics::display_path( isset( $detail['url'] ) ? (string) $detail['url'] : '' );
						$source_path  = DUPCAP_Permalink_Diagnostics::display_path( isset( $detail['source_url'] ) ? (string) $detail['source_url'] : '' );
					}
					?>
					<div class="dupcap-permalink-detail-row dupcap-permalink-detail-<?php echo esc_attr( $item_severity ); ?>"<?php echo ! empty( $detail['post_type'] ) ? ' data-dupcap-content-type="' . esc_attr( $detail['post_type'] ) . '"' : ''; ?>>
						<div class="dupcap-permalink-detail-head">
							<strong><?php echo esc_html( isset( $detail['label'] ) ? $detail['label'] : '' ); ?></strong>
							<span class="dupcap-badge dupcap-badge-<?php echo esc_attr( $item_severity ); ?>">
								<?php echo esc_html( $this->get_severity_label( $item_severity ) ); ?>
							</span>
						</div>
						<div class="dupcap-permalink-detail-grid">
							<?php if ( '' !== $current_path ) : ?>
								<div>
									<span class="dupcap-permalink-detail-label"><?php esc_html_e( 'Current URL', 'duplicate-content-addon-for-polylang' ); ?></span>
									<code><?php echo esc_html( $current_path ); ?></code>
								</div>
							<?php endif; ?>
							<?php if ( '' !== $source_path ) : ?>
								<div>
									<span class="dupcap-permalink-detail-label"><?php esc_html_e( 'Matching URL in another language', 'duplicate-content-addon-for-polylang' ); ?></span>
									<code><?php echo esc_html( $source_path ); ?></code>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $detail['status_label'] ) ) : ?>
								<div>
									<span class="dupcap-permalink-detail-label"><?php esc_html_e( 'Page status', 'duplicate-content-addon-for-polylang' ); ?></span>
									<span><?php echo esc_html( $detail['status_label'] ); ?></span>
								</div>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $detail['message'] ) ) : ?>
							<p class="dupcap-permalink-detail-message"><?php echo esc_html( $detail['message'] ); ?></p>
						<?php endif; ?>
						<div class="dupcap-permalink-detail-actions">
							<?php if ( ! empty( $detail['url'] ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $detail['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open page', 'duplicate-content-addon-for-polylang' ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $detail['action_url'] ) && ! empty( $detail['action_label'] ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $detail['action_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $detail['action_label'] ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $detail['secondary_action_url'] ) && ! empty( $detail['secondary_action_label'] ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $detail['secondary_action_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $detail['secondary_action_label'] ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $detail['ignore_key'] ) ) : ?>
								<button
									type="button"
									class="button button-small dupcap-ignore-button"
									data-dupcap-ignore="<?php echo esc_attr( $detail['ignore_key'] ); ?>"
								><?php echo esc_html( ! empty( $detail['ignore_label'] ) ? $detail['ignore_label'] : __( 'Ignore', 'duplicate-content-addon-for-polylang' ) ); ?></button>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}


}
