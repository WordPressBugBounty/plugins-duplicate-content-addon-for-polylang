<?php


defined( 'ABSPATH' ) || exit;

/**
 * Content Pending methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Content_Pending_Trait {

		/**
		 * Render pending content translation table grouped by post.
		 *
		 * @param array<int, array<string, mixed>> $details    Detail rows.
		 * @param string                           $dedupe_key Issue group key.
		 * @return bool True when the grouped table was rendered.
		 */
		private function render_content_pending_table( array $details, $dedupe_key = 'content_missing' ) {
			$rows = array_filter(
				$details,
				function ( $detail ) {
					return ! empty( $detail['post_id'] );
				}
			);

			if ( empty( $rows ) ) {
				return false;
			}

			$is_draft         = ( 'content_draft' === $dedupe_key );
			$is_source_updated = ( 'content_source_updated' === $dedupe_key );
			$row_gap_type      = $is_draft ? 'draft' : ( $is_source_updated ? 'source_updated' : 'missing' );
			$grouped           = $this->group_content_pending_by_post( $rows, $dedupe_key );

			$has_media     = false;
			$has_non_media = false;
			foreach ( $grouped as $post_row ) {
				$type = isset( $post_row['post_type'] ) ? (string) $post_row['post_type'] : '';
				if ( 'attachment' === $type ) {
					$has_media = true;
				} else {
					$has_non_media = true;
				}
			}

			$media_settings_url = admin_url( 'admin.php?page=mlang_settings' );

			?>
			<div class="dupcap-content-pending-intro">
				<p class="description">
					<?php
					if ( $is_draft ) {
						esc_html_e( 'These translations are linked in Polylang but still in draft or another unpublished status. Publish them to make them visible on the site.', 'duplicate-content-addon-for-polylang' );
					} elseif ( $has_media && ! $has_non_media ) {
						esc_html_e( 'These media files are set as translatable in Polylang but are missing some languages. Open Edit media, then use the Polylang language box (+) to add each missing language. If one file should be shared in every language, turn off Media translation in Polylang settings.', 'duplicate-content-addon-for-polylang' );
					} elseif ( $has_media && $has_non_media ) {
						esc_html_e( 'Use Edit / Translate for posts and pages. For media files, open Edit media and add languages in the Polylang box — do not use the old “new attachment” flow.', 'duplicate-content-addon-for-polylang' );
					} else {
						esc_html_e( 'Use Edit to update the source item, then Translate for each missing language listed in the row.', 'duplicate-content-addon-for-polylang' );
					}
					?>
				</p>
				<?php if ( ! $is_draft && $has_media ) : ?>
					<p class="description dupcap-content-pending-media-hint">
						<a href="<?php echo esc_url( $media_settings_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Open Polylang settings (Media translation)', 'duplicate-content-addon-for-polylang' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
			<div class="dupcap-table-wrap">
				<table class="widefat striped dupcap-content-pending-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Title', 'duplicate-content-addon-for-polylang' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'duplicate-content-addon-for-polylang' ); ?></th>
							<th scope="col">
								<?php
								echo $is_draft
									? esc_html__( 'Unpublished languages', 'duplicate-content-addon-for-polylang' )
									: esc_html__( 'Missing languages', 'duplicate-content-addon-for-polylang' );
								?>
							</th>
							<th scope="col"><?php esc_html_e( 'Actions', 'duplicate-content-addon-for-polylang' ); ?></th>
						</tr>
					</thead>
					<tbody data-dupcap-paginate>
						<?php foreach ( $grouped as $post_row ) : ?>
							<?php
							$is_media_row = ( isset( $post_row['post_type'] ) && 'attachment' === $post_row['post_type'] );
							?>
							<tr
								data-dupcap-content-type="<?php echo esc_attr( isset( $post_row['post_type'] ) ? $post_row['post_type'] : '' ); ?>"
								data-dupcap-title="<?php echo esc_attr( isset( $post_row['title'] ) ? $post_row['title'] : '' ); ?>"
								<?php if ( ! empty( $post_row['template_type'] ) ) : ?>data-dupcap-document-type="<?php echo esc_attr( (string) $post_row['template_type'] ); ?>"<?php endif; ?>
								<?php if ( ! empty( $post_row['post_id'] ) ) : ?>data-dupcap-object-id="<?php echo esc_attr( (string) (int) $post_row['post_id'] ); ?>"<?php endif; ?>
							>
								<td><?php echo esc_html( isset( $post_row['title'] ) ? $post_row['title'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $post_row['post_type_label'] ) ? $post_row['post_type_label'] : '' ); ?></td>
								<td>
									<div class="dupcap-content-lang-chips">
										<?php foreach ( $post_row['missing'] as $missing ) : ?>
											<?php
											$slug      = ! empty( $missing['slug'] ) ? (string) $missing['slug'] : '';
											$flag_html = $this->get_language_flag_html( $slug );
											$code_text = strtoupper( $slug );
											?>
											<?php if ( ! empty( $missing['translate_url'] ) ) : ?>
												<a
													class="dupcap-content-lang-chip dupcap-content-lang-chip-link"
													href="<?php echo esc_url( $missing['translate_url'] ); ?>"
													target="_blank"
													rel="noopener noreferrer"
													title="<?php echo esc_attr( sprintf( 
														/* translators: %s: language name */
														__( 'Translate into %s', 'duplicate-content-addon-for-polylang' ), $missing['name'] ) ); ?>"
													data-dupcap-track-fix="content"
													data-post-id="<?php echo esc_attr( (string) $post_row['post_id'] ); ?>"
													data-language="<?php echo esc_attr( $slug ); ?>"
													data-gap-type="<?php echo esc_attr( $row_gap_type ); ?>"
												>
													<?php if ( '' !== $flag_html ) : ?>
														<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
													<?php endif; ?>
													<span class="dupcap-content-lang-chip-code"><?php echo esc_html( $code_text ); ?></span>
													<?php if ( $is_draft && ! empty( $missing['status_label'] ) ) : ?>
														<span class="dupcap-content-lang-chip-status"><?php echo esc_html( $missing['status_label'] ); ?></span>
													<?php endif; ?>
												</a>
											<?php else : ?>
												<span class="dupcap-content-lang-chip">
													<?php if ( '' !== $flag_html ) : ?>
														<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
													<?php endif; ?>
													<span class="dupcap-content-lang-chip-code"><?php echo esc_html( $code_text ); ?></span>
													<?php if ( $is_draft && ! empty( $missing['status_label'] ) ) : ?>
														<span class="dupcap-content-lang-chip-status"><?php echo esc_html( $missing['status_label'] ); ?></span>
													<?php endif; ?>
												</span>
											<?php endif; ?>
										<?php endforeach; ?>
									</div>
								</td>
								<td>
									<div class="dupcap-content-actions">
										<?php if ( ! empty( $post_row['edit_url'] ) ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $post_row['edit_url'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php
												echo $is_media_row && ! $is_draft
													? esc_html__( 'Edit media (add languages)', 'duplicate-content-addon-for-polylang' )
													: esc_html__( 'Edit', 'duplicate-content-addon-for-polylang' );
												?>
											</a>
										<?php endif; ?>
										<?php
											$row_post_type = isset( $post_row['post_type'] ) ? (string) $post_row['post_type'] : 'post';
											$row_post_id   = isset( $post_row['post_id'] ) ? absint( $post_row['post_id'] ) : 0;
											$rescan_langs  = array();
											$ignore_keys   = array();
											foreach ( $post_row['missing'] as $missing_lang ) {
												if ( ! empty( $missing_lang['slug'] ) ) {
													$rescan_langs[] = sanitize_key( (string) $missing_lang['slug'] );
												}
												if ( ! empty( $missing_lang['ignore_key'] ) ) {
													$ignore_keys[] = (string) $missing_lang['ignore_key'];
												}
											}
											$rescan_langs    = array_values( array_unique( array_filter( $rescan_langs ) ) );
											$ignore_keys_str = implode( ',', array_values( array_unique( array_filter( $ignore_keys ) ) ) );
											?>
										<?php if ( ! $is_draft ) : ?>
											<?php $ai_trans_url = $this->get_post_type_ai_translation_url( $row_post_type, $row_post_id ); ?>
											<a
												class="button button-small button-primary dupcap-ai-translate-btn"
												href="<?php echo esc_url( $ai_trans_url ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												title="<?php esc_attr_e( 'Go to list view for AI Translation', 'duplicate-content-addon-for-polylang' ); ?>"
												data-dupcap-track-fix="content"
												data-post-id="<?php echo esc_attr( (string) $row_post_id ); ?>"
												data-gap-type="<?php echo esc_attr( $row_gap_type ); ?>"
												data-languages="<?php echo esc_attr( implode( ',', $rescan_langs ) ); ?>"
											>
												<?php esc_html_e( 'AI Translation', 'duplicate-content-addon-for-polylang' ); ?>
											</a>
										<?php endif; ?>
										<?php if ( ! empty( $rescan_langs ) && ! empty( $post_row['post_id'] ) ) : ?>
											<button
												type="button"
												class="button button-small dupcap-rescan-issue-button"
												data-dupcap-rescan-content-issue
												data-kind="content"
												data-post-id="<?php echo esc_attr( (string) $post_row['post_id'] ); ?>"
												data-gap-type="<?php echo esc_attr( $row_gap_type ); ?>"
												data-languages="<?php echo esc_attr( implode( ',', $rescan_langs ) ); ?>"
											>
												<span class="dashicons dashicons-update" aria-hidden="true"></span>
												<?php esc_html_e( 'Rescan issue', 'duplicate-content-addon-for-polylang' ); ?>
											</button>
										<?php endif; ?>
										<?php if ( ! empty( $ignore_keys_str ) ) : ?>
											<button
												type="button"
												class="button button-small dupcap-rescan-issue-button dupcap-ignore-button"
												data-dupcap-ignore="<?php echo esc_attr( $ignore_keys_str ); ?>"
												title="<?php esc_attr_e( 'Dismiss missing translation warnings for this item', 'duplicate-content-addon-for-polylang' ); ?>"
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

			return true;
		}

		/**
		 * Whether content details include structured post rows.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return bool
		 */
		private function has_content_pending_posts( array $details ) {
			foreach ( $details as $detail ) {
				if ( ! empty( $detail['post_id'] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Count missing content translations across detail rows.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return int
		 */
		private function count_content_pending_gaps( array $details ) {
			$gaps = 0;

			foreach ( $details as $detail ) {
				$pid = ! empty( $detail['post_id'] ) ? (int) $detail['post_id'] : ( ! empty( $detail['template_id'] ) ? (int) $detail['template_id'] : 0 );
				if ( $pid > 0 ) {
					$gaps++;
				}
			}

			return $gaps;
		}

		/**
		 * Count unique posts/pages with pending translations.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return int
		 */
		private function count_content_pending_posts( array $details ) {
			$posts = array();

			foreach ( $details as $detail ) {
				$pid = ! empty( $detail['post_id'] ) ? (int) $detail['post_id'] : ( ! empty( $detail['template_id'] ) ? (int) $detail['template_id'] : 0 );
				if ( $pid > 0 ) {
					$posts[ $pid ] = true;
				}
			}

			return count( $posts );
		}

		/**
		 * Count the distinct languages named by detail rows.
		 *
		 * @param array<int, array<string, mixed>> $details Detail rows.
		 * @return int
		 */
		private function count_detail_languages( array $details ) {
			$languages = array();

			foreach ( $details as $detail ) {
				if ( is_array( $detail ) && ! empty( $detail['language'] ) ) {
					$languages[ (string) $detail['language'] ] = true;
				}
			}

			return count( $languages );
		}

		/**
		 * "5 posts and pages are missing translations. 6 languages are affected."
		 *
		 * Two complete sentences, so a translator can inflect and reorder each one
		 * for their own grammar. The item count matches the card heading.
		 *
		 * @param string $items_sentence Whole sentence naming the items and their count.
		 * @param int    $languages      Language count; 0 leaves the second sentence out.
		 * @return string
		 */
		private function format_missing_summary( $items_sentence, $languages ) {
			$items_sentence = trim( (string) $items_sentence );

			if ( $languages < 1 || '' === $items_sentence ) {
				return $items_sentence;
			}

			/*
			 * Two whole sentences, joined. The old version built one sentence out
			 * of three fragments ("Missing translations for %1$s in %2$s"), which
			 * left a translator with noun phrases they could not inflect or
			 * reorder for their own grammar.
			 */
			return $items_sentence . ' ' . sprintf(
				/* translators: %d: number of languages affected. */
				_n( '%d language is affected.', '%d languages are affected.', $languages, 'duplicate-content-addon-for-polylang' ),
				$languages
			);
		}

		/**
		 * Group flat pending rows by post.
		 *
		 * @param array<int, array<string, mixed>> $details    Detail rows.
		 * @param string                           $dedupe_key Group key the rows belong to.
		 * @return array<int, array<string, mixed>>
		 */
		private function group_content_pending_by_post( array $details, $dedupe_key = 'content_missing' ) {
			$grouped = array();

			foreach ( $details as $detail ) {
				$post_id = ! empty( $detail['post_id'] ) ? (int) $detail['post_id'] : ( ! empty( $detail['template_id'] ) ? (int) $detail['template_id'] : 0 );
				if ( $post_id <= 0 ) {
					continue;
				}

				/*
				 * A term row keeps its taxonomy in post_type and a term ID in
				 * post_id (see the ignore key below). Asking get_post_type() about
				 * that number returns whatever post happens to share it: term 32
				 * came back as elementor_library, so the row was labelled "My
				 * Templates", added that chip to the Content tab, and offered an
				 * edit.php link nowhere near the term.
				 */
				$is_term   = ( 'term_missing' === $dedupe_key );
				$post_type = '';

				if ( ! $is_term && $post_id > 0 && get_post_type( $post_id ) ) {
					$post_type = (string) get_post_type( $post_id );
				} elseif ( isset( $detail['post_type'] ) && '' !== $detail['post_type'] ) {
					$post_type = (string) $detail['post_type'];
				}

				if ( '' === $post_type && ! empty( $detail['template_type'] ) ) {
					$post_type = 'elementor_library';
				}

				$type_label = ! empty( $detail['post_type_label'] )
					? (string) $detail['post_type_label']
					: ( ! empty( $detail['template_type'] )
						? ucwords( str_replace( array( '-', '_' ), ' ', (string) $detail['template_type'] ) )
						: $this->english_type_label( $post_type ) );

				// english_type_label() only knows post types, so a custom taxonomy
				// would otherwise show its raw slug.
				if ( $is_term && '' !== $post_type ) {
					$taxonomy = get_taxonomy( $post_type );
					if ( $taxonomy && ! empty( $taxonomy->labels->singular_name ) ) {
						$type_label = (string) $taxonomy->labels->singular_name;
					}
				}

				$title = ! empty( $detail['title'] )
					? (string) $detail['title']
					: ( ! empty( $detail['label'] ) ? (string) $detail['label'] : __( '(no title)', 'duplicate-content-addon-for-polylang' ) );

				// Same trap: get_edit_post_link() on a term ID edits an unrelated post.
				$edit_url = '';
				if ( ! empty( $detail['edit_url'] ) ) {
					$edit_url = (string) $detail['edit_url'];
				} elseif ( $is_term ) {
					$term_link = get_edit_term_link( $post_id, $post_type );
					$edit_url  = $term_link ? (string) $term_link : '';
				} elseif ( get_edit_post_link( $post_id, 'raw' ) ) {
					$edit_url = (string) get_edit_post_link( $post_id, 'raw' );
				}

				if ( ! isset( $grouped[ $post_id ] ) ) {
					$grouped[ $post_id ] = array(
						'post_id'         => $post_id,
						'title'           => $title,
						'post_type'       => $post_type,
						'post_type_label' => $type_label,
						'template_type'   => ! empty( $detail['template_type'] ) ? (string) $detail['template_type'] : '',
						'edit_url'        => $edit_url,
						'missing'         => array(),
					);
				}

				$slug          = isset( $detail['language'] ) ? (string) $detail['language'] : '';
				$status        = isset( $detail['post_status'] ) ? (string) $detail['post_status'] : '';
				$translate_url = ! empty( $detail['translate_url'] )
					? (string) $detail['translate_url']
					: ( ! empty( $detail['action_url'] ) ? (string) $detail['action_url'] : '' );

				if ( ! empty( $detail['ignore_key'] ) ) {
					$ignore_key = (string) $detail['ignore_key'];
				} elseif ( ! empty( $detail['template_type'] ) ) {
					$ignore_key = DUPCAP_Ignored_Issues::elementor_key( 'missing_translation', $post_id, $slug );
				} elseif ( 'term_missing' === $dedupe_key ) {
					// Terms store their taxonomy in post_type; post_id here is a term ID.
					$ignore_key = DUPCAP_Ignored_Issues::term_gap_key(
						$post_id,
						isset( $detail['post_type'] ) ? (string) $detail['post_type'] : '',
						$slug
					);
				} elseif ( 'content_source_updated' === $dedupe_key ) {
					$ignore_key = DUPCAP_Ignored_Issues::content_source_updated_key( $post_id, $slug );
				} else {
					$ignore_key = DUPCAP_Ignored_Issues::content_gap_key(
						$post_id,
						$slug,
						'content_draft' === $dedupe_key ? 'draft' : 'missing'
					);
				}

				$grouped[ $post_id ]['missing'][] = array(
					'slug'          => $slug,
					'name'          => ! empty( $detail['language_name'] ) ? (string) $detail['language_name'] : strtoupper( $slug ),
					'translate_url' => $translate_url,
					'ignore_key'    => $ignore_key,
					'status_label'  => $this->english_status_label(
						$status,
						isset( $detail['post_status_label'] ) ? (string) $detail['post_status_label'] : ''
					),
				);
			}

			return array_values( $grouped );
		}


}
