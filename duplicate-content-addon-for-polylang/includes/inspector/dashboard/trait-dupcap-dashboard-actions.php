<?php


defined( 'ABSPATH' ) || exit;

/**
 * Actions methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Actions_Trait {

		/**
		 * Whether an issue action should assign the default language in place.
		 *
		 * @param string $url Action URL.
		 * @return bool
		 */
		private function is_assign_default_lang_action( $url ) {
			return '#dupcap-assign-default-lang' === $url;
		}

		/**
		 * Render a card/notice action as an in-page button or outbound link.
		 *
		 * @param string $url   Action URL.
		 * @param string $label Button label.
		 * @param string $class CSS classes.
		 * @return void
		 */
		private function render_action_control( $url, $label, $class = 'button button-primary' ) {
			if ( $this->is_assign_default_lang_action( $url ) ) {
				?>
				<button type="button" class="<?php echo esc_attr( $class ); ?>" data-dupcap-assign-default-lang>
					<?php echo esc_html( $label ); ?>
				</button>
				<?php
				return;
			}

			if ( $this->is_lsdp_plugin_fix_action( $url, $label ) ) {
				$this->render_lsdp_plugin_action_button( $class );
				return;
			}
			?>
			<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?></a>
			<?php
		}

		/**
		 * Whether an action should install/activate Language Switcher for Polylang.
		 *
		 * @param string $url   Action URL.
		 * @param string $label Action label.
		 * @return bool
		 */
		private function is_lsdp_plugin_fix_action( $url, $label = '' ) {
			$url   = (string) $url;
			$label = (string) $label;

			if ( '' !== $label && false !== stripos( $label, 'Language Switcher for Polylang' ) ) {
				return true;
			}

			return ( '' !== $url && false !== strpos( $url, 'language-switcher-for-divi-polylang' ) );
		}

		/**
		 * Plugin status for Language Switcher for Polylang.
		 *
		 * @return array{status: string, basename: string}
		 */
		private function get_lsdp_plugin_status() {
			return DUPCAP_Plugin_Status::state( 'language-switcher-for-divi-polylang', 'LSDP' );
		}

		/**
		 * Render Install / Activate / Set up button for Language Switcher for Polylang.
		 *
		 * @param string $button_class Button CSS classes.
		 * @return void
		 */
		private function render_lsdp_plugin_action_button( $button_class = 'button button-primary' ) {
			$status_info = $this->get_lsdp_plugin_status();
			$status      = isset( $status_info['status'] ) ? $status_info['status'] : 'missing';

			if ( 'active' === $status ) {
				?>
				<a class="<?php echo esc_attr( $button_class ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=lsdp-get-started' ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Set up Language Switcher', 'duplicate-content-addon-for-polylang' ); ?>
				</a>
				<?php
				return;
			}

			$basename      = isset( $status_info['basename'] ) ? $status_info['basename'] : '';
			$slug          = 'language-switcher-for-divi-polylang';
			$init          = $basename ? $basename : $slug . '/' . $slug . '.php';
			$show_activate = ( 'inactive' === $status && $basename && current_user_can( 'activate_plugins' ) );
			$show_install  = ( 'missing' === $status && current_user_can( 'install_plugins' ) );

			if ( ! $show_activate && ! $show_install ) {
				return;
			}

			$label = $show_activate
				? __( 'Activate Language Switcher for Polylang', 'duplicate-content-addon-for-polylang' )
				: __( 'Install Language Switcher for Polylang', 'duplicate-content-addon-for-polylang' );
			?>
			<button
				type="button"
				class="<?php echo esc_attr( $button_class ); ?> dupcap-environment-plugin-btn"
				data-dupcap-plugin-action="<?php echo esc_attr( $show_activate ? 'activate' : 'install' ); ?>"
				data-slug="<?php echo esc_attr( $slug ); ?>"
				data-init="<?php echo esc_attr( $init ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'dupcap_install_nonce' ) ); ?>"
			>
				<span class="dashicons dashicons-<?php echo esc_attr( $show_activate ? 'yes' : 'download' ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( $label ); ?>
			</button>
			<span class="dupcap-environment-plugin-msg" hidden></span>
			<?php
		}

		/**
		 * Whether an Elementor insight example should offer the template-sync plugin.
		 *
		 * Matches the fix labels the Elementor scanner writes for findings that a
		 * template-sync plugin resolves.
		 *
		 * @param array<string, mixed> $example Example row.
		 * @return bool
		 */
		private function is_template_sync_fix_example( array $example ) {
			$label = isset( $example['fix_label'] ) ? (string) $example['fix_label'] : '';
			$url   = isset( $example['fix_url'] ) ? (string) $example['fix_url'] : '';

			if ( '' !== $label && false !== stripos( $label, 'Language Switcher for Polylang' ) ) {
				return true;
			}

			return ( '' !== $url && false !== strpos( $url, 'language-switcher-for-divi-polylang' ) );
		}

		/**
		 * Offer the template-sync plugin beside an Elementor insight example.
		 *
		 * Silent when Connect Polylang for Elementor is already routing templates
		 * by language: the Elementor side is handled, so there is nothing to offer
		 * here. A missing switcher is a separate problem, reported on its own.
		 *
		 * @param string $button_class Button CSS classes.
		 * @return void
		 */
		private function render_template_sync_plugin_button( $button_class = 'button button-secondary' ) {
			if ( defined( 'CPEL_PLUGIN_VERSION' ) ) {
				return;
			}

			$this->render_lsdp_plugin_action_button( $button_class );
		}

		/**
		 * Get the admin list table URL for AI Translation redirection based on post type or taxonomy.
		 *
		 * @param string $type_or_tax Post type slug or taxonomy slug.
		 * @param int    $object_id   Optional post ID to pre-select on the list screen.
		 * @return string Admin URL.
		 */
		private function get_post_type_ai_translation_url( $type_or_tax, $object_id = 0 ) {
			$type_or_tax = sanitize_key( (string) $type_or_tax );

			if ( '' === $type_or_tax || 'post' === $type_or_tax ) {
				$url = admin_url( 'edit.php' );
			} elseif ( 'attachment' === $type_or_tax ) {
				$url = admin_url( 'upload.php' );
			} elseif ( taxonomy_exists( $type_or_tax ) ) {
				$tax_obj = get_taxonomy( $type_or_tax );
				$args    = array( 'taxonomy' => $type_or_tax );

				if ( $tax_obj && ! empty( $tax_obj->object_type ) && is_array( $tax_obj->object_type ) ) {
					$associated_post_type = (string) $tax_obj->object_type[0];
					if ( 'post' !== $associated_post_type && ! empty( $associated_post_type ) ) {
						$args['post_type'] = $associated_post_type;
					}
				}

				$url = add_query_arg( $args, admin_url( 'edit-tags.php' ) );
			} elseif ( DUPCAP_Check::post_type_is_reachable( $type_or_tax ) ) {
				$url = admin_url( 'edit.php?post_type=' . $type_or_tax );
			} else {
				/*
				 * No list screen to send anyone to - wp_template and friends are
				 * edited in the Site Editor. An edit.php link for them lands on a
				 * "Sorry, you are not allowed" page, so offer no button at all.
				 */
				return '';
			}

			$object_id = absint( $object_id );
			if ( $object_id > 0 ) {
				$url = add_query_arg( 'dupcap_select', $object_id, $url );
			}

			return $url;
		}


}
