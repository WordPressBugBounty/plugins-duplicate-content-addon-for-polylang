<?php


defined( 'ABSPATH' ) || exit;

/**
 * Notices methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Notices_Trait {

		/**
		 * Overview banner shown after one or more section scans.
		 *
		 * @param array<string, int> $partial_scans Tab key => time of its last section scan.
		 * @return void
		 */
		private function render_partial_scan_notice( array $partial_scans ) {
			$configs = $this->get_section_scan_config();
			$parts   = array();

			foreach ( $partial_scans as $key => $time ) {
				if ( isset( $configs[ $key ] ) ) {
					$parts[] = sprintf(
						/* translators: 1: section name, 2: formatted date and time. */
						__( '%1$s (%2$s)', 'duplicate-content-addon-for-polylang' ),
						$configs[ $key ]['label'],
						DUPCAP_Scanner::format_scan_time( (int) $time )
					);
				}
			}

			if ( empty( $parts ) ) {
				return;
			}
			?>
			<div class="dupcap-partial-scan-notice">
				<span class="dashicons dashicons-info" aria-hidden="true"></span>
				<div>
					<p><strong><?php esc_html_e( 'Some sections were updated separately. Run Scan Website to refresh the overall score.', 'duplicate-content-addon-for-polylang' ); ?></strong></p>
					<p>
						<?php
						printf(
							/* translators: %s: comma-separated list of sections with their scan times. */
							esc_html__( 'Updated separately: %s', 'duplicate-content-addon-for-polylang' ),
							esc_html( implode( ', ', $parts ) )
						);
						?>
					</p>
				</div>
				<button
					type="button"
					class="button button-secondary"
					data-dupcap-rescan
					<?php disabled( $this->has_objects_without_language(), true ); ?>
				>
					<span class="dupcap-rescan-label"><?php esc_html_e( 'Scan Website', 'duplicate-content-addon-for-polylang' ); ?></span>
				</button>
			</div>
			<?php
		}

		/**
		 * Render dashboard notices wrapper.
		 *
		 * @return void
		 */
		private function render_dashboard_notices() {
			?>
			<div id="dupcap-dashboard-notices" class="dupcap-dashboard-notices"><?php $this->render_dashboard_notices_inner(); ?></div>
			<?php
		}

		/**
		 * Print notices registered on {@see 'dupcap_dashboard_notices'}.
		 *
		 * Example:
		 * ```
		 * add_action( 'dupcap_dashboard_notices', function () {
		 *     echo '<div class="notice notice-success is-dismissible"><p>Done.</p></div>';
		 * } );
		 * ```
		 *
		 * @return void
		 */
		private function render_dashboard_notices_inner() {
			/**
			 * Fires on the Translation Inspector dashboard notice area.
			 *
			 * Use this hook for plugin-specific notices. Global `admin_notices`
			 * still run normally above the page.
			 */
			do_action( 'dupcap_dashboard_notices' );
		}

		/**
		 * Offer Polylang's assign-to-default-language action above Fix These First.
		 *
		 * @return void
		 */
		private function render_nolang_notice() {
			if ( ! current_user_can( 'manage_options' ) || ! $this->has_objects_without_language() ) {
				return;
			}
			?>
			<div class="dupcap-nolang-header-notice dupcap-nolang-notice">
				<span class="dupcap-nolang-notice-text">
					<?php esc_html_e( 'There are posts, pages, categories or tags without language. Set them all to the default language so they can be translated.', 'duplicate-content-addon-for-polylang' ); ?>
				</span>
				<button type="button" class="button button-primary dupcap-nolang-header-notice-btn" data-dupcap-assign-default-lang>
					<?php esc_html_e( 'Set to default language', 'duplicate-content-addon-for-polylang' ); ?>
				</button>
				<p class="dupcap-nolang-notice-msg" hidden></p>
			</div>
			<?php
		}

		/**
		 * Untranslated post types and taxonomies names list.
		 *
		 * Keyed by slug so a caller can build a per-slug ignore key; callers that
		 * only need the text still implode the values.
		 *
		 * @return array<string, string> Slug => `"Name" (slug)`.
		 */
		private function get_untranslated_post_types_and_taxonomies() {
			$untranslated = array();
			$model        = $this->get_polylang_model();

			// Post types
			$public_post_types = get_post_types( array( 'public' => true, '_builtin' => false ) );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Polylang host filter.
			$candidate_cpts = array_unique( (array) apply_filters( 'pll_get_post_types', $public_post_types, true ) );

			foreach ( $candidate_cpts as $slug ) {
				if ( 'attachment' === $slug ) {
					continue;
				}
				$is_trans = function_exists( 'pll_is_translated_post_type' )
					? pll_is_translated_post_type( $slug )
					: ( $model && method_exists( $model, 'is_translated_post_type' ) ? $model->is_translated_post_type( $slug ) : false );

				if ( ! $is_trans ) {
					$obj                   = get_post_type_object( $slug );
					$name                  = ( $obj && isset( $obj->labels->name ) && $obj->labels->name ) ? (string) $obj->labels->name : (string) $slug;
					$untranslated[ $slug ] = sprintf( '"%1$s" (%2$s)', $name, $slug );
				}
			}

			// Taxonomies
			$public_taxonomies = get_taxonomies( array( 'public' => true, '_builtin' => false ) );
			$internal_pll_tax  = get_taxonomies( array( '_pll' => true ) );
			$public_taxonomies = array_diff( $public_taxonomies, $internal_pll_tax );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Polylang host filter.
			$candidate_tax = array_unique( (array) apply_filters( 'pll_get_taxonomies', $public_taxonomies, true ) );

			foreach ( $candidate_tax as $slug ) {
				if ( 'nav_menu' === $slug || 'link_category' === $slug || 'post_format' === $slug ) {
					continue;
				}
				$is_trans = function_exists( 'pll_is_translated_taxonomy' )
					? pll_is_translated_taxonomy( $slug )
					: ( $model && method_exists( $model, 'is_translated_taxonomy' ) ? $model->is_translated_taxonomy( $slug ) : false );

				if ( ! $is_trans ) {
					$obj                   = get_taxonomy( $slug );
					$name                  = ( $obj && isset( $obj->labels->name ) && $obj->labels->name ) ? (string) $obj->labels->name : (string) $slug;
					$untranslated[ $slug ] = sprintf( '"%1$s" (%2$s)', $name, $slug );
				}
			}

			return $untranslated;
		}

		/**
		 * The only notice a site with fewer than two languages should see.
		 *
		 * Not ignorable, unlike the others: this one is not advice, it is the
		 * reason the whole dashboard has nothing to show.
		 *
		 * @since 2.3.0
		 *
		 * @return void
		 */
		private function render_languages_required_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			?>
			<div class="dupcap-nolang-header-notice dupcap-languages-required-notice">
				<span class="dupcap-nolang-notice-text">
					<?php esc_html_e( 'Add at least two languages in Polylang to start using the Translation Inspector. With fewer than two there is nothing to compare, so a scan would find nothing to report.', 'duplicate-content-addon-for-polylang' ); ?>
				</span>
				<a
					class="button button-primary dupcap-nolang-header-notice-btn"
					href="<?php echo esc_url( admin_url( 'admin.php?page=mlang' ) ); ?>"
				>
					<?php esc_html_e( 'Add languages', 'duplicate-content-addon-for-polylang' ); ?>
				</a>
			</div>
			<?php
		}

		/**
		 * Offer link to Polylang settings when custom post types or taxonomies are not translatable.
		 *
		 * @return void
		 */
		private function render_untranslated_types_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$untranslated = $this->get_untranslated_post_types_and_taxonomies();

			// One ignore key per slug: a type the user dismissed stays hidden, but a
			// type that stops being translatable later still raises the notice.
			$ignore_keys = array();
			if ( class_exists( 'DUPCAP_Ignored_Issues' ) ) {
				foreach ( array_keys( $untranslated ) as $slug ) {
					$key = DUPCAP_Ignored_Issues::untranslated_type_key( $slug );
					if ( DUPCAP_Ignored_Issues::is_ignored( $key ) ) {
						unset( $untranslated[ $slug ] );
						continue;
					}
					$ignore_keys[] = $key;
				}
			}

			if ( empty( $untranslated ) ) {
				return;
			}

			$items_list = implode( ', ', $untranslated );
			?>
			<div class="dupcap-nolang-header-notice dupcap-untranslated-types-notice">
				<span class="dupcap-nolang-notice-text">
					<?php
					printf(
						/* translators: %s: comma-separated list of post types or taxonomies */
						esc_html__( 'The following content types/taxonomies are not set as translatable: %s. Enable them in Polylang settings so translations can be managed.', 'duplicate-content-addon-for-polylang' ),
						'<strong>' . esc_html( $items_list ) . '</strong>'
					);
					?>
				</span>
				<a class="button button-primary dupcap-nolang-header-notice-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=mlang_settings' ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Polylang settings', 'duplicate-content-addon-for-polylang' ); ?>
				</a>
				<?php if ( ! empty( $ignore_keys ) ) : ?>
					<button
						type="button"
						class="button dupcap-notice-ignore-btn"
						data-dupcap-ignore="<?php echo esc_attr( implode( ',', $ignore_keys ) ); ?>"
						title="<?php esc_attr_e( 'Hide this notice. Restore it later from the Ignored list.', 'duplicate-content-addon-for-polylang' ); ?>"
					>
						<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
					</button>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Offer Language Switcher for Polylang when the site has no switcher.
		 *
		 * Qualified on the scan, not on the plugin being absent: Polylang's own
		 * menu and block switchers are perfectly valid, so a site that already has
		 * one is never told to install anything. Only third-party-free ground is
		 * covered here — no other vendor's plugin is recommended.
		 *
		 * @since 2.3.0
		 *
		 * @param array<int, array<string, mixed>> $results Dashboard results.
		 * @return void
		 */
		private function render_elementor_addons_notice( array $results ) {
			if ( ! $this->should_offer_switcher_plugin( $results ) ) {
				return;
			}

			$ignore_key = class_exists( 'DUPCAP_Ignored_Issues' )
				? DUPCAP_Ignored_Issues::setup_notice_key( 'language_switcher' )
				: '';

			if ( '' !== $ignore_key && DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
				return;
			}
			?>
			<div class="dupcap-nolang-header-notice dupcap-elementor-addons-notice">
				<span class="dupcap-nolang-notice-text">
					<?php esc_html_e( 'No language switcher was found on this site, so visitors cannot change language. Language Switcher for Polylang adds one in Elementor, Gutenberg or Divi, and keeps Elementor template translations in sync.', 'duplicate-content-addon-for-polylang' ); ?>
				</span>
				<span class="dupcap-elementor-addons-action">
					<?php $this->render_lsdp_plugin_action_button( 'button button-primary dupcap-nolang-header-notice-btn' ); ?>
					<?php if ( '' !== $ignore_key ) : ?>
						<button
							type="button"
							class="button dupcap-notice-ignore-btn"
							data-dupcap-ignore="<?php echo esc_attr( $ignore_key ); ?>"
							title="<?php esc_attr_e( 'Hide this notice. Restore it later from the Ignored list.', 'duplicate-content-addon-for-polylang' ); ?>"
						>
							<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
						</button>
					<?php endif; ?>
				</span>
			</div>
			<?php
		}

		/**
		 * Language Switcher for Polylang, when it is worth offering.
		 *
		 * Empty unless Polylang is running, the scan found no switcher anywhere,
		 * the plugin is not already running, and this user could act on it.
		 *
		 * @since 2.3.0
		 *
		 * @param array<int, array<string, mixed>> $results Dashboard results.
		 * @return bool
		 */
		private function should_offer_switcher_plugin( array $results ) {
			if ( ! class_exists( 'DUPCAP_Plugin_Status' ) ) {
				return false;
			}

			$polylang_active = defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_languages_list' );
			if ( ! $polylang_active || ! $this->scan_found_no_switcher( $results ) ) {
				return false;
			}

			$slug   = 'language-switcher-for-divi-polylang';
			$state  = DUPCAP_Plugin_Status::state( $slug, 'LSDP' );
			$status = (string) $state['status'];

			if ( ! in_array( $status, array( 'inactive', 'missing' ), true ) ) {
				return false;
			}

			$basename     = (string) $state['basename'];
			$can_activate = ( 'inactive' === $status && '' !== $basename && current_user_can( 'activate_plugins' ) );
			$can_install  = ( 'missing' === $status && current_user_can( 'install_plugins' ) );

			// Nothing this user can do about it, so saying it helps nobody.
			if ( ! $can_activate && ! $can_install ) {
				return false;
			}

			return true;
		}

		/**
		 * Whether the last scan looked for a language switcher and found none.
		 *
		 * False when no scan has run or the check did not report: an unverified
		 * gap is not a gap, and must never drive a "install this" message.
		 *
		 * @since 2.3.0
		 *
		 * @param array<int, array<string, mixed>> $results Dashboard results.
		 * @return bool
		 */
		private function scan_found_no_switcher( array $results ) {
			foreach ( $results as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['code'] ) || 'lang_switcher' !== $row['code'] ) {
					continue;
				}

				return isset( $row['severity'] ) && in_array( (string) $row['severity'], array( 'warn', 'crit' ), true );
			}

			return false;
		}

		/**
		 * Notice when an unsupported page builder is active.
		 *
		 * Product copy: deep scanning is framed as Elementor + Gutenberg/classic.
		 * Other builders still get page-level Content / menu / URL checks only.
		 *
		 * @since 2.3.0
		 *
		 * @return void
		 */
		private function render_unsupported_builder_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$builders = $this->get_unsupported_page_builders();

			// One key per builder: dismissing Divi today should not silence the
			// notice about a builder activated next month.
			$ignore_keys = array();
			if ( class_exists( 'DUPCAP_Ignored_Issues' ) ) {
				foreach ( array_keys( $builders ) as $slug ) {
					$key = DUPCAP_Ignored_Issues::setup_notice_key( 'builder:' . $slug );
					if ( DUPCAP_Ignored_Issues::is_ignored( $key ) ) {
						unset( $builders[ $slug ] );
						continue;
					}
					$ignore_keys[] = $key;
				}
			}

			if ( empty( $builders ) ) {
				return;
			}

			$names = implode( ', ', array_values( $builders ) );
			?>
			<div class="dupcap-nolang-header-notice dupcap-unsupported-builder-notice">
				<span class="dupcap-nolang-notice-text">
					<?php
					printf(
						/* translators: %s: comma-separated page builder names (e.g. Divi, Beaver Builder) */
						esc_html__( 'Deep scanning works with Elementor and Gutenberg/classic. Not available yet for: %s — those pages still appear under Content for translations, links, and menus.', 'duplicate-content-addon-for-polylang' ),
						esc_html( $names )
					);
					?>
				</span>
				<?php if ( ! empty( $ignore_keys ) ) : ?>
					<button
						type="button"
						class="button dupcap-notice-ignore-btn"
						data-dupcap-ignore="<?php echo esc_attr( implode( ',', $ignore_keys ) ); ?>"
						title="<?php esc_attr_e( 'Hide this notice. Restore it later from the Ignored list.', 'duplicate-content-addon-for-polylang' ); ?>"
					>
						<?php esc_html_e( 'Ignore', 'duplicate-content-addon-for-polylang' ); ?>
					</button>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Active page builders that do not have a deep Insights-style scanner.
		 *
		 * @since 2.3.0
		 *
		 * @return array<string, string> Slug => display name, sorted by name. The
		 *                               slug is what the notice's ignore key uses.
		 */
		private function get_unsupported_page_builders() {
			$found = array();

			if ( $this->is_divi_active() ) {
				$found['divi'] = 'Divi';
			}
			if ( defined( 'FL_BUILDER_VERSION' ) || class_exists( 'FLBuilder', false ) || class_exists( 'FLBuilderModel', false ) ) {
				$found['beaver'] = 'Beaver Builder';
			}
			if ( defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Theme', false ) ) {
				$found['bricks'] = 'Bricks';
			}
			if ( defined( 'CT_VERSION' ) || class_exists( 'CT_Component', false ) || defined( 'OXYGEN_IFRAME' ) ) {
				$found['oxygen'] = 'Oxygen';
			}
			if ( defined( 'WPB_VC_VERSION' ) || class_exists( 'Vc_Manager', false ) ) {
				$found['wpbakery'] = 'WPBakery Page Builder';
			}
			if ( defined( 'TVE_IN_ARCHITECT' ) || defined( 'TVE_VERSION' ) || class_exists( 'TCB_Plugin', false ) ) {
				$found['thrive'] = 'Thrive Architect';
			}
			if ( defined( '__BREAKDANCE_VERSION' ) || class_exists( '\Breakdance\Plugin', false ) ) {
				$found['breakdance'] = 'Breakdance';
			}
			if ( defined( 'SEEDPROD_VERSION' ) || class_exists( 'SeedProd_Enterprise', false ) || class_exists( 'SeedProd_Lite', false ) ) {
				$found['seedprod'] = 'SeedProd';
			}

			asort( $found, SORT_STRING );

			return $found;
		}

		/**
		 * Whether Divi theme or Divi Builder plugin is active.
		 *
		 * @since 2.3.0
		 *
		 * @return bool
		 */
		private function is_divi_active() {
			if ( defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' ) ) {
				return true;
			}
			if ( class_exists( 'ET_Builder_Plugin', false ) || class_exists( 'ET_Core', false ) ) {
				return true;
			}
			if ( function_exists( 'et_divi_load_scripts_styles' ) || function_exists( 'et_setup_theme' ) ) {
				return true;
			}

			$theme = wp_get_theme();
			if ( $theme instanceof WP_Theme ) {
				$name = strtolower( (string) $theme->get( 'Name' ) );
				$tmpl = strtolower( (string) $theme->get_template() );
				if ( false !== strpos( $name, 'divi' ) || 'divi' === $tmpl ) {
					return true;
				}
				$parent = $theme->parent();
				if ( $parent instanceof WP_Theme ) {
					$parent_name = strtolower( (string) $parent->get( 'Name' ) );
					$parent_tmpl = strtolower( (string) $parent->get_template() );
					if ( false !== strpos( $parent_name, 'divi' ) || 'divi' === $parent_tmpl ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Usage data sharing setting, at the end of the Overview tab.
		 *
		 * The only saveable setting this plugin has, so it carries its own Save
		 * button rather than sitting on a settings screen. The button stays
		 * disabled until the checkbox differs from what is stored.
		 *
		 * Hidden while the CPFM panel is on screen asking the same question, so
		 * the reader is never offered two places to answer it - and never shown
		 * this box ticked by default next to a question they have not answered.
		 *
		 * @return void
		 */
		private function render_optin_card() {
			if ( ! class_exists( 'DUPCAP_Optin' ) || DUPCAP_Optin::popup_is_asking() ) {
				return;
			}

			$enabled = DUPCAP_Optin::is_enabled();
			?>
			<section class="dupcap-card dupcap-optin-card" aria-labelledby="dupcap-optin-title" data-dupcap-optin-card>
				<div class="dupcap-section-heading">
					<div>
						<h2 id="dupcap-optin-title">
							<span class="dashicons dashicons-share" aria-hidden="true"></span>
							<?php esc_html_e( 'Usage Data Sharing', 'duplicate-content-addon-for-polylang' ); ?>
						</h2>
						<p><?php esc_html_e( 'Help us make this plugin more compatible with your site by sharing non-sensitive site data.', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
					<div class="dupcap-card-actions">
						<button type="button" class="button button-primary" data-dupcap-optin-save disabled>
							<?php esc_html_e( 'Save changes', 'duplicate-content-addon-for-polylang' ); ?>
						</button>
					</div>
				</div>

				<div class="dupcap-optin-row">
					<label class="dupcap-optin-label" for="dupcap-optin-input">
						<input
							type="checkbox"
							id="dupcap-optin-input"
							data-dupcap-optin-input
							data-dupcap-optin-saved="<?php echo $enabled ? '1' : '0'; ?>"
							<?php checked( $enabled ); ?>
						/>
						<span><?php esc_html_e( 'Share non-sensitive site data', 'duplicate-content-addon-for-polylang' ); ?></span>
					</label>
					<button
						type="button"
						class="dupcap-optin-terms-toggle"
						data-dupcap-optin-terms
						aria-expanded="false"
						aria-controls="dupcap-optin-terms"
					>
						<?php esc_html_e( 'See terms', 'duplicate-content-addon-for-polylang' ); ?>
					</button>
				</div>

				<div class="dupcap-optin-terms" id="dupcap-optin-terms" data-dupcap-optin-details hidden>
					<p>
						<?php esc_html_e( "Opt in to receive email updates about security improvements, new features, helpful tutorials, and occasional special offers. We'll collect:", 'duplicate-content-addon-for-polylang' ); ?>
						<a href="https://my.coolplugins.net/terms/usage-tracking/" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Click here', 'duplicate-content-addon-for-polylang' ); ?>
						</a>
					</p>
					<ul>
						<li><?php esc_html_e( 'Your website home URL and WordPress admin email.', 'duplicate-content-addon-for-polylang' ); ?></li>
						<li><?php esc_html_e( 'To check plugin compatibility: your list of active plugins and themes, server type, MySQL version, WordPress version, memory limit, site language, database prefix, and whether you set up Translation Inspector and Duplicate Content.', 'duplicate-content-addon-for-polylang' ); ?></li>
					</ul>
				</div>
			</section>
			<?php
		}

		/**
		 * CPFM review ask notice rendered at the top of the admin page.
		 *
		 * Notice screens are limited to plugins.php so admin_notices never prints
		 * this on the dashboard. We render the private notice markup here instead.
		 *
		 * @return void
		 */
		private function render_cpfm_review_notice() {
			if ( ! class_exists( 'CPFM_Review' ) || ! class_exists( 'CPFM_Review_Notice' ) ) {
				return;
			}

			if ( ! CPFM_Review::cpfm_is_due( 'dupcap' ) ) {
				return;
			}

			$config = CPFM_Review::cpfm_config( 'dupcap' );
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! is_array( $config ) || ! $screen ) {
				return;
			}

			// Keep wp-admin from relocating this block.
			$config['notice']['position'] = 'inline';

			try {
				$method = new ReflectionMethod( 'CPFM_Review_Notice', 'cpfm_render' );
				$method->setAccessible( true );
			} catch ( ReflectionException $e ) {
				return;
			}

			ob_start();
			$method->invoke( null, $config, $screen );
			$html = ob_get_clean();

			if ( ! is_string( $html ) || '' === trim( $html ) ) {
				return;
			}

			$html = str_replace(
				array(
					'class="notice notice-info cpfm-rv inline"',
					'class="notice notice-info cpfm-rv"',
				),
				'class="dupcap-cpfm-notice cpfm-rv"',
				$html
			);

			CPFM_Review::cpfm_record_impression( 'dupcap', false );
			?>
			<div class="dupcap-cpfm-review">
				<?php
				// CPFM notice markup is built with escaping inside CPFM_Review_Notice.
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
			<?php
		}


}
