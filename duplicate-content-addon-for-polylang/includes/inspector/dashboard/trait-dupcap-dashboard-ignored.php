<?php


defined( 'ABSPATH' ) || exit;

/**
 * Ignored methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Ignored_Trait {

		/**
		 * Render collapsed passed checks.
		 *
		 * @param array<int, array<string, mixed>> $passed_checks Passed checks.
		 * @return void
		 */
		private function render_passed_checks( array $passed_checks ) {
			if ( empty( $passed_checks ) ) {
				return;
			}

			?>
			<details
				class="dupcap-passed-panel"
				data-dupcap-card
				data-dupcap-card-id="passed"
				name="dupcap-passed-accordion"
				data-dupcap-severity="pass"
				data-dupcap-group="passed"
			>
				<summary class="dupcap-passed-summary">
					<span class="dupcap-passed-icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<span class="dupcap-passed-main">
						<strong>
							<?php
							printf(
								/* translators: %d: passed check count. */
								esc_html__( 'Passed Checks (%d)', 'duplicate-content-addon-for-polylang' ),
								count( $passed_checks )
							);
							?>
						</strong>
						<span><?php esc_html_e( 'Checks in this area that passed. Collapsed by default.', 'duplicate-content-addon-for-polylang' ); ?></span>
					</span>
					<span class="dupcap-issue-meta">
						<span class="dupcap-badge dupcap-badge-pass">
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php esc_html_e( 'Passed', 'duplicate-content-addon-for-polylang' ); ?>
						</span>
						<span class="dashicons dashicons-arrow-down-alt2 dupcap-accordion-arrow" aria-hidden="true"></span>
					</span>
				</summary>
				<div class="dupcap-passed-body">
					<div class="dupcap-passed-tools">
						<label class="dupcap-passed-search">
							<span class="dashicons dashicons-search" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Search passed checks', 'duplicate-content-addon-for-polylang' ); ?></span>
							<input type="search" data-dupcap-passed-search placeholder="<?php esc_attr_e( 'Search passed checks...', 'duplicate-content-addon-for-polylang' ); ?>">
						</label>
					</div>
					<div class="dupcap-passed-list">
						<?php foreach ( $passed_checks as $check ) : ?>
							<?php
							$pass_type = '';
							$pass_code = isset( $check['code'] ) ? (string) $check['code'] : '';
							if ( preg_match( '/^elementor_([a-z0-9_-]+)$/', $pass_code, $pass_match ) && ! in_array( $pass_match[1], array( 'templates', 'none', 'not_translated' ), true ) ) {
								$pass_type = (string) $pass_match[1];
							}
							?>
							<article class="dupcap-pass-card" data-dupcap-pass-text="<?php echo esc_attr( strtolower( $check['group'] . ' ' . $check['title'] . ' ' . $check['message'] ) ); ?>"<?php echo '' !== $pass_type ? ' data-dupcap-document-type="' . esc_attr( $pass_type ) . '"' : ''; ?>>
								<span class="dupcap-group-tag"><?php echo esc_html( $check['group'] ); ?></span>
								<strong><?php echo $this->format_check_title_with_flag( $check['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
								<p><?php echo esc_html( $check['message'] ); ?></p>
							</article>
						<?php endforeach; ?>
					</div>
				</div>
			</details>
			<?php
		}

		/**
		 * Count ignored warnings the same way section panels do (grouped rows).
		 *
		 * Raw ignore keys are often one per language; Overview must not inflate
		 * the Ignored tile by that — match Content / URLs "Ignored Issues (N)".
		 *
		 * @since 2.3.0
		 *
		 * @return int
		 */
		private function count_ignored_warnings() {
			if ( ! class_exists( 'DUPCAP_Ignored_Issues' ) ) {
				return 0;
			}

			$total = 0;
			foreach ( array( 'content', 'urls', 'elementor', 'site' ) as $section ) {
				$total += count( $this->get_ignored_items_for_section( $section ) );
			}

			return $total;
		}

		/**
		 * Collect ignored issues for one dashboard section.
		 *
		 * @param string $section content|urls|elementor|site.
		 * @return array<int, array<string, mixed>>
		 */
		private function get_ignored_items_for_section( $section ) {
			if ( ! class_exists( 'DUPCAP_Ignored_Issues' ) ) {
				return array();
			}

			$section = sanitize_key( (string) $section );
			$items   = array();

			foreach ( DUPCAP_Ignored_Issues::get_all() as $key => $meta ) {
				$key = DUPCAP_Ignored_Issues::sanitize_key( (string) $key );
				if ( $section !== DUPCAP_Ignored_Issues::get_section_for_key( $key ) ) {
					continue;
				}
				$row = $this->describe_ignored_key( $key, is_array( $meta ) ? $meta : array() );
				if ( ! empty( $row ) ) {
					$items[] = $row;
				}
			}

			$items = $this->group_ignored_items( $items );

			usort(
				$items,
				static function ( $a, $b ) {
					return (int) ( isset( $b['ignored_at'] ) ? $b['ignored_at'] : 0 ) - (int) ( isset( $a['ignored_at'] ) ? $a['ignored_at'] : 0 );
				}
			);

			return $items;
		}

		/**
		 * Collapse per-language ignore keys into one row per source item (like Warnings).
		 *
		 * @since 2.3.0
		 *
		 * @param array<int, array<string, mixed>> $items Flat ignored rows.
		 * @return array<int, array<string, mixed>>
		 */
		private function group_ignored_items( array $items ) {
			$grouped = array();

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$ignore_key = isset( $item['ignore_key'] ) ? (string) $item['ignore_key'] : '';
				$group_key  = $this->ignored_item_group_key( $ignore_key );
				if ( '' === $group_key ) {
					$group_key = $ignore_key;
				}

				if ( ! isset( $grouped[ $group_key ] ) ) {
					$item['languages']   = array();
					$item['ignore_keys'] = array();
					$grouped[ $group_key ] = $item;
				}

				$lang = isset( $item['language'] ) ? sanitize_key( (string) $item['language'] ) : '';
				if ( '' !== $lang ) {
					$grouped[ $group_key ]['languages'][] = $lang;
				}
				if ( '' !== $ignore_key ) {
					$grouped[ $group_key ]['ignore_keys'][] = $ignore_key;
				}

				$at = isset( $item['ignored_at'] ) ? (int) $item['ignored_at'] : 0;
				if ( $at > (int) $grouped[ $group_key ]['ignored_at'] ) {
					$grouped[ $group_key ]['ignored_at'] = $at;
				}
			}

			foreach ( $grouped as $group_key => $row ) {
				$grouped[ $group_key ]['languages']   = array_values( array_unique( array_filter( $row['languages'] ) ) );
				$grouped[ $group_key ]['ignore_keys'] = array_values( array_unique( array_filter( $row['ignore_keys'] ) ) );
				$grouped[ $group_key ]['ignore_key']  = implode( ',', $grouped[ $group_key ]['ignore_keys'] );
				$grouped[ $group_key ]['language']    = ! empty( $grouped[ $group_key ]['languages'] )
					? implode( ',', $grouped[ $group_key ]['languages'] )
					: ( isset( $row['language'] ) ? (string) $row['language'] : '' );
			}

			return array_values( $grouped );
		}

		/**
		 * Stable group key for an ignore key with the language segment removed.
		 *
		 * @since 2.3.0
		 *
		 * @param string $ignore_key Stored ignore key.
		 * @return string
		 */
		private function ignored_item_group_key( $ignore_key ) {
			$key   = (string) $ignore_key;
			$parts = explode( ':', $key );

			if ( count( $parts ) >= 3 && in_array( $parts[0], array( 'content_missing', 'content_draft' ), true ) ) {
				return $parts[0] . ':' . $parts[1];
			}

			if ( count( $parts ) >= 4 && 'term_missing' === $parts[0] ) {
				return $parts[0] . ':' . $parts[1] . ':' . $parts[2];
			}

			if ( count( $parts ) >= 4 && 'content' === $parts[0] && 'source_updated' === $parts[1] ) {
				return 'content:source_updated:' . $parts[2];
			}

			if ( count( $parts ) >= 4 && 'content' === $parts[0] && 'placeholder' === $parts[1] ) {
				return 'content:placeholder:' . $parts[2];
			}

			if ( count( $parts ) >= 5 && 'content' === $parts[0] && 'acf' === $parts[1] ) {
				return 'content:acf:' . $parts[2] . ':' . implode( ':', array_slice( $parts, 4 ) );
			}

			if ( count( $parts ) >= 5 && 'content' === $parts[0] && 'identical' === $parts[1] ) {
				return 'content:identical:' . $parts[2] . ':' . implode( ':', array_slice( $parts, 4 ) );
			}

			if ( count( $parts ) >= 5 && 'content' === $parts[0] && 'link' === $parts[1] ) {
				return 'content:link:' . $parts[2] . ':' . implode( ':', array_slice( $parts, 4 ) );
			}

			if ( count( $parts ) >= 4 && 'el' === $parts[0] ) {
				return 'el:' . $parts[1] . ':' . $parts[2] . ( count( $parts ) > 4 ? ':' . implode( ':', array_slice( $parts, 4 ) ) : '' );
			}

			if ( count( $parts ) >= 3 && 'menu' === $parts[0] && 'dead' !== $parts[1] ) {
				return 'menu:' . $parts[1];
			}

			if ( count( $parts ) >= 3 && 'url' === $parts[0] ) {
				return implode( ':', array_slice( $parts, 0, -1 ) );
			}

			return $key;
		}

		/**
		 * Turn a stored ignore key into a display row.
		 *
		 * @param string               $key  Ignore key.
		 * @param array<string, mixed> $meta Stored meta.
		 * @return array<string, mixed>|null
		 */
		private function describe_ignored_key( $key, array $meta ) {
			$ignored_at = isset( $meta['ignored_at'] ) ? (int) $meta['ignored_at'] : 0;
			$parts      = explode( ':', $key );

			$content_row = $this->describe_ignored_content_key( $key, $meta );
			if ( ! empty( $content_row ) ) {
				return $content_row;
			}

			if ( count( $parts ) >= 3 && 0 === strpos( $key, 'url:front_page_' ) ) {
				$code = isset( $parts[1] ) ? (string) $parts[1] : '';
				$lang = isset( $parts[2] ) ? sanitize_key( $parts[2] ) : '';
				$kind = ( false !== strpos( $code, 'empty_layout' ) )
					? __( 'Homepage empty layout', 'duplicate-content-addon-for-polylang' )
					: __( 'Homepage missing translation', 'duplicate-content-addon-for-polylang' );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => __( 'Homepage', 'duplicate-content-addon-for-polylang' ),
					'kind'            => $kind,
					'post_type'       => '',
					'post_type_label' => __( 'Site setup', 'duplicate-content-addon-for-polylang' ),
					'language'        => $lang,
					'edit_url'        => admin_url( 'options-reading.php' ),
				);
			}

			if ( count( $parts ) >= 2 && 'url' === $parts[0] ) {
				$code = sanitize_key( $parts[1] );
				$lang = isset( $parts[2] ) ? sanitize_key( $parts[2] ) : '';
				$labels = array(
					'slug_suffix'      => __( 'Translated permalink suffix', 'duplicate-content-addon-for-polylang' ),
					'dupe_slug'        => __( 'Duplicate slug', 'duplicate-content-addon-for-polylang' ),
					'route_untestable' => __( 'URL could not be tested', 'duplicate-content-addon-for-polylang' ),
				);
				$kind = isset( $labels[ $code ] ) ? $labels[ $code ] : (
					0 === strpos( $code, 'route_' )
						? __( 'Routing issue', 'duplicate-content-addon-for-polylang' )
						: __( 'URL issue', 'duplicate-content-addon-for-polylang' )
				);
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $kind,
					'kind'            => $kind,
					'post_type'       => '',
					'post_type_label' => __( 'URLs & Routing', 'duplicate-content-addon-for-polylang' ),
					'language'        => $lang,
					'edit_url'        => '',
				);
			}

			if ( count( $parts ) >= 3 && 'el' === $parts[0] ) {
				$code        = sanitize_key( $parts[1] );
				$template_id = absint( $parts[2] );
				$lang        = isset( $parts[3] ) ? sanitize_key( $parts[3] ) : '';
				$post        = $template_id ? get_post( $template_id ) : null;
				$title       = ( $post && $post->post_title )
					? $post->post_title
					: ( $template_id > 0 ? sprintf( /* translators: %d: template ID */ __( 'Template #%d', 'duplicate-content-addon-for-polylang' ), $template_id ) : __( 'Elementor issue', 'duplicate-content-addon-for-polylang' ) );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $title,
					'kind'            => '' !== $code ? str_replace( '_', ' ', $code ) : __( 'Elementor issue', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => $post ? $post->post_type : 'elementor_library',
					'post_type_label' => __( 'Elementor', 'duplicate-content-addon-for-polylang' ),
					'language'        => $lang,
					'edit_url'        => $template_id ? get_edit_post_link( $template_id, 'raw' ) : '',
				);
			}

			if ( count( $parts ) >= 3 && 'menu' === $parts[0] && 'dead' === $parts[1] ) {
				$menu_id = absint( $parts[2] );
				$menu    = $menu_id ? get_term( $menu_id, 'nav_menu' ) : null;
				$title   = ( $menu && ! is_wp_error( $menu ) && $menu->name )
					? $menu->name
					: __( 'Menu link', 'duplicate-content-addon-for-polylang' );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $title,
					'kind'            => __( 'Dead menu link', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => '',
					'post_type_label' => __( 'Menu', 'duplicate-content-addon-for-polylang' ),
					'language'        => '',
					'edit_url'        => $menu_id ? admin_url( 'nav-menus.php?action=edit&menu=' . $menu_id ) : admin_url( 'nav-menus.php' ),
				);
			}

			if ( count( $parts ) >= 3 && 'menu' === $parts[0] ) {
				$location = sanitize_key( $parts[1] );
				$lang     = sanitize_key( $parts[2] );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $location,
					'kind'            => __( 'Menu not assigned', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => '',
					'post_type_label' => __( 'Menu', 'duplicate-content-addon-for-polylang' ),
					'language'        => $lang,
					'edit_url'        => admin_url( 'nav-menus.php' ),
				);
			}

			if ( count( $parts ) >= 3 && 'setup' === $parts[0] && 'untranslated_type' === $parts[1] ) {
				$slug   = sanitize_key( implode( ':', array_slice( $parts, 2 ) ) );
				$object = get_post_type_object( $slug );
				if ( ! $object ) {
					$object = get_taxonomy( $slug );
				}
				$label = ( $object && ! empty( $object->labels->name ) ) ? (string) $object->labels->name : $slug;
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $label,
					'kind'            => __( 'Not set as translatable', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => '',
					'post_type_label' => __( 'Site setup', 'duplicate-content-addon-for-polylang' ),
					'language'        => '',
					'edit_url'        => admin_url( 'admin.php?page=mlang_settings' ),
				);
			}

			if ( count( $parts ) >= 2 && 'string' === $parts[0] ) {
				$string_key = implode( ':', array_slice( $parts, 1 ) );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $string_key,
					'kind'            => __( 'Missing string translation', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => '',
					'post_type_label' => __( 'Strings', 'duplicate-content-addon-for-polylang' ),
					'language'        => '',
					'edit_url'        => admin_url( 'admin.php?page=mlang_strings' ),
				);
			}

			return array(
				'ignore_key'      => $key,
				'ignored_at'      => $ignored_at,
				'title'           => $key,
				'kind'            => __( 'Ignored warning', 'duplicate-content-addon-for-polylang' ),
				'post_type'       => '',
				'post_type_label' => '',
				'language'        => '',
				'edit_url'        => '',
			);
		}

		/**
		 * Turn a stored Content ignore key into a display row.
		 *
		 * @param string               $key  Ignore key.
		 * @param array<string, mixed> $meta Stored meta.
		 * @return array<string, mixed>|null
		 */
		private function describe_ignored_content_key( $key, array $meta ) {
			$ignored_at = isset( $meta['ignored_at'] ) ? (int) $meta['ignored_at'] : 0;
			$parts      = explode( ':', $key );

			if ( count( $parts ) >= 3 && in_array( $parts[0], array( 'content_missing', 'content_draft' ), true ) ) {
				$post_id = absint( $parts[1] );
				$lang    = sanitize_key( $parts[2] );
				$post    = $post_id ? get_post( $post_id ) : null;
				if ( ! $post ) {
					return null;
				}
				$obj = get_post_type_object( $post->post_type );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
					'kind'            => ( 'content_draft' === $parts[0] )
						? __( 'Unpublished translation', 'duplicate-content-addon-for-polylang' )
						: __( 'Missing translation', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => $post->post_type,
					'post_type_label' => $obj && ! empty( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $post->post_type,
					'language'        => $lang,
					'edit_url'        => get_edit_post_link( $post_id, 'raw' ),
				);
			}

			if ( count( $parts ) >= 4 && 'term_missing' === $parts[0] ) {
				$taxonomy = sanitize_key( $parts[1] );
				$term_id  = absint( $parts[2] );
				$lang     = sanitize_key( $parts[3] );
				$term     = $term_id ? get_term( $term_id, $taxonomy ) : null;
				if ( ! $term || is_wp_error( $term ) ) {
					return null;
				}
				$tax = get_taxonomy( $taxonomy );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $term->name ? $term->name : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
					'kind'            => __( 'Missing term translation', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => $taxonomy,
					'post_type_label' => $tax && ! empty( $tax->labels->singular_name ) ? (string) $tax->labels->singular_name : $taxonomy,
					'language'        => $lang,
					'edit_url'        => get_edit_term_link( $term_id, $taxonomy ),
				);
			}

			if ( count( $parts ) >= 5 && 'content' === $parts[0] && 'identical' === $parts[1] ) {
				$post_id = absint( $parts[2] );
				$lang    = sanitize_key( $parts[3] );
				$field   = sanitize_key( $parts[4] );
				$post    = $post_id ? get_post( $post_id ) : null;
				if ( ! $post ) {
					return null;
				}
				$obj = get_post_type_object( $post->post_type );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
					'kind'            => ( 'title' === $field )
						? __( 'Identical title', 'duplicate-content-addon-for-polylang' )
						: __( 'Identical content', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => $post->post_type,
					'post_type_label' => $obj && ! empty( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $post->post_type,
					'language'        => $lang,
					'edit_url'        => get_edit_post_link( $post_id, 'raw' ),
				);
			}

			if ( count( $parts ) >= 5 && 'content' === $parts[0] && 'link' === $parts[1] ) {
				$post_id = absint( $parts[2] );
				$lang    = sanitize_key( $parts[3] );
				$code    = sanitize_key( $parts[4] );
				$post    = $post_id ? get_post( $post_id ) : null;
				if ( ! $post ) {
					return null;
				}
				$obj = get_post_type_object( $post->post_type );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
					'kind'            => __( 'Link issue', 'duplicate-content-addon-for-polylang' ) . ( '' !== $code ? ' (' . $code . ')' : '' ),
					'post_type'       => $post->post_type,
					'post_type_label' => $obj && ! empty( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $post->post_type,
					'language'        => $lang,
					'edit_url'        => get_edit_post_link( $post_id, 'raw' ),
				);
			}

			if ( count( $parts ) >= 4 && 'content' === $parts[0] && 'source_updated' === $parts[1] ) {
				$post_id = absint( $parts[2] );
				$lang    = sanitize_key( $parts[3] );
				$post    = $post_id ? get_post( $post_id ) : null;
				if ( ! $post ) {
					return null;
				}
				$obj = get_post_type_object( $post->post_type );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
					'kind'            => __( 'Default language updated', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => $post->post_type,
					'post_type_label' => $obj && ! empty( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $post->post_type,
					'language'        => $lang,
					'edit_url'        => get_edit_post_link( $post_id, 'raw' ),
				);
			}

			if ( count( $parts ) >= 4 && 'content' === $parts[0] && 'placeholder' === $parts[1] ) {
				$post_id = absint( $parts[2] );
				$lang    = sanitize_key( $parts[3] );
				$post    = $post_id ? get_post( $post_id ) : null;
				if ( ! $post ) {
					return null;
				}
				$obj = get_post_type_object( $post->post_type );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
					'kind'            => __( 'Shortcode or placeholder mismatch', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => $post->post_type,
					'post_type_label' => $obj && ! empty( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $post->post_type,
					'language'        => $lang,
					'edit_url'        => get_edit_post_link( $post_id, 'raw' ),
				);
			}

			if ( count( $parts ) >= 5 && 'content' === $parts[0] && 'acf' === $parts[1] ) {
				$post_id = absint( $parts[2] );
				$lang    = sanitize_key( $parts[3] );
				$field   = sanitize_key( $parts[4] );
				$post    = $post_id ? get_post( $post_id ) : null;
				if ( ! $post ) {
					return null;
				}
				$obj = get_post_type_object( $post->post_type );
				return array(
					'ignore_key'      => $key,
					'ignored_at'      => $ignored_at,
					'title'           => $post->post_title ? $post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
					'kind'            => __( 'ACF or custom field mismatch', 'duplicate-content-addon-for-polylang' ),
					'post_type'       => $post->post_type,
					'post_type_label' => $obj && ! empty( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $post->post_type,
					'language'        => $lang,
					'edit_url'        => get_edit_post_link( $post_id, 'raw' ),
				);
			}

			return null;
		}

		/**
		 * Render ignored issues with a Restore action.
		 *
		 * @param array<int, array<string, mixed>> $ignored Ignored rows.
		 * @param string                           $section Section slug for accordion isolation.
		 * @return void
		 */
		private function render_ignored_issues( array $ignored, $section = 'content' ) {
			if ( empty( $ignored ) ) {
				return;
			}

			$section = sanitize_key( (string) $section );
			?>
			<details
				class="dupcap-passed-panel dupcap-ignored-panel"
				data-dupcap-card
				data-dupcap-card-id="ignored-<?php echo esc_attr( $section ); ?>"
				data-dupcap-ignored-panel
				name="dupcap-ignored-accordion-<?php echo esc_attr( $section ); ?>"
				data-dupcap-severity="ignored"
				data-dupcap-group="ignored"
			>
				<summary class="dupcap-passed-summary">
					<span class="dupcap-passed-icon dashicons dashicons-hidden" aria-hidden="true"></span>
					<span class="dupcap-passed-main">
						<strong>
							<?php
							printf(
								/* translators: %d: ignored issue count. */
								esc_html__( 'Ignored Issues (%d)', 'duplicate-content-addon-for-polylang' ),
								count( $ignored )
							);
							?>
						</strong>
						<span><?php esc_html_e( 'Warnings you dismissed. Restore one to show it again after the next scan refresh.', 'duplicate-content-addon-for-polylang' ); ?></span>
					</span>
					<span class="dupcap-issue-meta">
						<span class="dupcap-badge dupcap-badge-ignored">
							<span class="dashicons dashicons-hidden" aria-hidden="true"></span>
							<?php esc_html_e( 'Ignored', 'duplicate-content-addon-for-polylang' ); ?>
						</span>
						<span class="dashicons dashicons-arrow-down-alt2 dupcap-accordion-arrow" aria-hidden="true"></span>
					</span>
				</summary>
				<div class="dupcap-passed-body">
					<div class="dupcap-table-wrap">
						<table class="widefat striped dupcap-ignored-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Title', 'duplicate-content-addon-for-polylang' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Issue', 'duplicate-content-addon-for-polylang' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Type', 'duplicate-content-addon-for-polylang' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Language', 'duplicate-content-addon-for-polylang' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'duplicate-content-addon-for-polylang' ); ?></th>
								</tr>
							</thead>
							<tbody data-dupcap-paginate>
								<?php foreach ( $ignored as $row ) : ?>
									<?php
									$title      = isset( $row['title'] ) ? (string) $row['title'] : '';
									$kind       = isset( $row['kind'] ) ? (string) $row['kind'] : '';
									$type_label = isset( $row['post_type_label'] ) ? (string) $row['post_type_label'] : '';
									$languages  = ! empty( $row['languages'] ) && is_array( $row['languages'] )
										? $row['languages']
										: ( ! empty( $row['language'] ) ? array_filter( explode( ',', (string) $row['language'] ) ) : array() );
									$ignore_key = ! empty( $row['ignore_keys'] ) && is_array( $row['ignore_keys'] )
										? implode( ',', array_unique( array_filter( $row['ignore_keys'] ) ) )
										: ( isset( $row['ignore_key'] ) ? (string) $row['ignore_key'] : '' );
									$edit_url   = ! empty( $row['edit_url'] ) ? (string) $row['edit_url'] : '';
									$post_type  = isset( $row['post_type'] ) ? (string) $row['post_type'] : '';
									?>
									<tr
										data-dupcap-content-type="<?php echo esc_attr( $post_type ); ?>"
										data-dupcap-title="<?php echo esc_attr( strtolower( $title . ' ' . $kind ) ); ?>"
									>
										<td>
											<strong><?php echo esc_html( $title ); ?></strong>
										</td>
										<td><?php echo esc_html( $kind ); ?></td>
										<td><?php echo esc_html( $type_label ); ?></td>
										<td>
											<?php if ( ! empty( $languages ) ) : ?>
												<div class="dupcap-content-lang-chips">
													<?php foreach ( $languages as $lang_slug ) : ?>
														<?php
														$lang_slug = sanitize_key( (string) $lang_slug );
														if ( '' === $lang_slug ) {
															continue;
														}
														$flag_html = $this->get_language_flag_html( $lang_slug );
														$code_text = strtoupper( $lang_slug );
														$lang_name = DUPCAP_Elementor_Fields::language_label( $lang_slug );
														?>
														<span
															class="dupcap-content-lang-chip"
															title="<?php echo esc_attr( $lang_name ); ?>"
														>
															<?php if ( '' !== $flag_html ) : ?>
																<span class="dupcap-lang-flag-wrap" aria-hidden="true"><?php echo $flag_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
															<?php endif; ?>
															<span class="dupcap-content-lang-chip-code"><?php echo esc_html( $code_text ); ?></span>
														</span>
													<?php endforeach; ?>
												</div>
											<?php else : ?>
												—
											<?php endif; ?>
										</td>
										<td>
											<div class="dupcap-content-actions">
												<?php if ( $edit_url ) : ?>
													<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer">
														<?php esc_html_e( 'Edit', 'duplicate-content-addon-for-polylang' ); ?>
													</a>
												<?php endif; ?>
												<?php if ( $ignore_key ) : ?>
													<button
														type="button"
														class="button button-small button-primary"
														data-dupcap-unignore="<?php echo esc_attr( $ignore_key ); ?>"
														title="<?php esc_attr_e( 'Show this warning again on the dashboard', 'duplicate-content-addon-for-polylang' ); ?>"
													>
														<?php esc_html_e( 'Restore', 'duplicate-content-addon-for-polylang' ); ?>
													</button>
												<?php endif; ?>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</details>
			<?php
		}


}
