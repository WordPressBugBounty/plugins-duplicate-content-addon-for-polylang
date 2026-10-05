<?php
/**
 * Renders the Translation Inspector admin dashboard.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered v3 dashboard for scan results.
 */
final class DUPCAP_Dashboard {

	use DUPCAP_Dashboard_Copy_Trait;
	use DUPCAP_Dashboard_Health_Trait;
	use DUPCAP_Dashboard_Environment_Trait;
	use DUPCAP_Dashboard_Ignored_Trait;
	use DUPCAP_Dashboard_Menus_Trait;
	use DUPCAP_Dashboard_Content_Pending_Trait;
	use DUPCAP_Dashboard_Detail_Tables_Trait;
	use DUPCAP_Dashboard_Elementor_Trait;
	use DUPCAP_Dashboard_Issue_Card_Trait;
	use DUPCAP_Dashboard_Actions_Trait;
	use DUPCAP_Dashboard_Sections_Trait;
	use DUPCAP_Dashboard_Notices_Trait;
	use DUPCAP_Dashboard_Layout_Trait;
	use DUPCAP_Dashboard_Results_Trait;
	use DUPCAP_Dashboard_Saved_Report_Trait;
	use DUPCAP_Dashboard_Rescan_Issue_Trait;
	use DUPCAP_Dashboard_Ajax_Trait;

	/** @var DUPCAP_Dashboard|null */
	private static $instance = null;

	/**
	 * @return DUPCAP_Dashboard
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Verify dashboard AJAX nonce.
	 *
	 * @return void
	 */
	private function verify_ajax_nonce() {
		check_ajax_referer( 'dupcap_rescan_dashboard', 'nonce' );
	}

		/**
		 * Render the full admin page.
		 *
		 * @return void
		 */
		public function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to view this page.', 'duplicate-content-addon-for-polylang' ) );
			}

			// Switched off in setup. The page stays reachable so it can be switched
			// back on; the scan and its AJAX handlers are not even registered.
			if ( function_exists( 'dupcap_is_tool_enabled' ) && ! dupcap_is_tool_enabled( 'inspector' ) ) {
				$this->render_inspector_disabled();
				return;
			}

			$results = $this->get_scan_results();

			?>
			<div class="dupcap-admin-shell">
				<?php $this->render_plugin_topbar(); ?>
				<div class="wrap dupcap-wrap">
					<?php $this->render_cpfm_review_notice(); ?>
					<?php $this->render_header( $results ); ?>
					<?php $this->render_dashboard_notices(); ?>
					<div id="dupcap-dashboard-container" class="dupcap-dashboard-container">
						<div id="dupcap-dashboard-body" class="dupcap-dashboard-body" aria-live="polite">
							<?php $this->render_dashboard_body( $results ); ?>
						</div>
					</div>
				</div>
				<div id="dupcap-scan-overlay" class="dupcap-scan-overlay" data-dupcap-scan-overlay hidden aria-hidden="true"></div>
				<div class="dupcap-scan-overlay-anchor" data-dupcap-scan-panel hidden aria-hidden="true">
					<div class="dupcap-scan-overlay-panel" role="status" aria-live="polite">
						<div class="dupcap-scan-animation" aria-hidden="true">
							<span class="dupcap-scan-ring"></span>
							<span class="dupcap-scan-ring dupcap-scan-ring-delay"></span>
							<img
								class="dupcap-scan-icon"
								src="<?php echo esc_url( DUPCAP_Admin::scan_icon_url() ); ?>"
								width="28"
								height="28"
								alt=""
								decoding="async"
								aria-hidden="true"
							/>
						</div>
						<p class="dupcap-scan-overlay-text">
							<?php esc_html_e( 'Scanning your website…', 'duplicate-content-addon-for-polylang' ); ?>
						</p>
					</div>
				</div>
				<?php $this->render_autopoly_install_modal(); ?>
			</div>
			<?php
		}

		/**
		 * Modal shown when AI Translation is clicked and AutoPoly is not active.
		 *
		 * @return void
		 */
		private function render_autopoly_install_modal() {
			$autopoly = DUPCAP_Admin::get_autopoly_status();
			$is_pro   = ! empty( $autopoly['is_pro'] );
			$is_free  = ! empty( $autopoly['is_free'] );

			// Pro users never need an upsell / install modal here.
			if ( $is_pro ) {
				return;
			}

			$learn = 'https://coolplugins.net/product/autopoly-ai-translation-for-polylang/?utm_source=pdca_plugin&utm_medium=inside&utm_campaign=view_plugin&utm_content=inspector_ai_popup';

			// Free AutoPoly active: upsell Pro for re-translation.
			if ( $is_free ) {
				?>
				<div
					id="dupcap-autopoly-pro-modal"
					class="dupcap-modal-overlay"
					data-dupcap-autopoly-pro-modal
					aria-hidden="true"
				>
					<div
						class="dupcap-modal-content"
						role="dialog"
						aria-modal="true"
						aria-labelledby="dupcap-autopoly-pro-modal-title"
					>
						<div class="dupcap-modal-header">
							<div class="dupcap-header-left">
								<span class="dashicons dashicons-translation" aria-hidden="true"></span>
								<h3 id="dupcap-autopoly-pro-modal-title"><?php esc_html_e( 'AI Translation', 'duplicate-content-addon-for-polylang' ); ?></h3>
							</div>
							<button
								type="button"
								class="dupcap-modal-close"
								data-dupcap-autopoly-pro-close
								aria-label="<?php esc_attr_e( 'Close', 'duplicate-content-addon-for-polylang' ); ?>"
							>
								<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
							</button>
						</div>
						<div class="dupcap-modal-body">
							<div class="dupcap-marketing-card">
								<h4><?php esc_html_e( 'Re-translate with AutoPoly Pro', 'duplicate-content-addon-for-polylang' ); ?></h4>
								<p><?php esc_html_e( 'You have AutoPoly Free installed. To re-translate pages after the default language changes, upgrade to AutoPoly Pro.', 'duplicate-content-addon-for-polylang' ); ?></p>
								<div class="dupcap-marketing-buttons">
									<a
										href="<?php echo esc_url( $learn ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="dupcap-marketing-btn dupcap-primary-btn"
									>
										<?php esc_html_e( 'Get AutoPoly Pro', 'duplicate-content-addon-for-polylang' ); ?>
									</a>
									<a
										href="<?php echo esc_url( $learn ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="dupcap-marketing-btn dupcap-secondary-btn"
									>
										<?php esc_html_e( 'Learn More', 'duplicate-content-addon-for-polylang' ); ?>
									</a>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php
				return;
			}

			// Neither Free nor Pro active: offer install.
			$action = isset( $autopoly['action'] ) ? (string) $autopoly['action'] : 'install';
			$slug   = isset( $autopoly['slug'] ) ? (string) $autopoly['slug'] : 'automatic-translations-for-polylang';
			$init   = isset( $autopoly['init'] ) ? (string) $autopoly['init'] : '';
			$label  = isset( $autopoly['button_label'] ) ? (string) $autopoly['button_label'] : __( 'Install AutoPoly', 'duplicate-content-addon-for-polylang' );
			$can    = ( 'activate' === $action )
				? ! empty( $autopoly['can_activate'] )
				: ! empty( $autopoly['can_install'] );
			?>
			<div
				id="dupcap-autopoly-modal"
				class="dupcap-modal-overlay"
				data-dupcap-autopoly-modal
				aria-hidden="true"
			>
				<div
					class="dupcap-modal-content"
					role="dialog"
					aria-modal="true"
					aria-labelledby="dupcap-autopoly-modal-title"
				>
					<div class="dupcap-modal-header">
						<div class="dupcap-header-left">
							<span class="dashicons dashicons-translation" aria-hidden="true"></span>
							<h3 id="dupcap-autopoly-modal-title"><?php esc_html_e( 'AI Translation', 'duplicate-content-addon-for-polylang' ); ?></h3>
						</div>
						<button
							type="button"
							class="dupcap-modal-close"
							data-dupcap-autopoly-close
							aria-label="<?php esc_attr_e( 'Close', 'duplicate-content-addon-for-polylang' ); ?>"
						>
							<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
						</button>
					</div>
					<div class="dupcap-modal-body">
						<div class="dupcap-marketing-card">
							<h4><?php esc_html_e( 'Install AutoPoly for AI Translation', 'duplicate-content-addon-for-polylang' ); ?></h4>
							<p><?php esc_html_e( 'AutoPoly translates your pages and posts with AI. Install and activate it here, then continue to the translation screen.', 'duplicate-content-addon-for-polylang' ); ?></p>
							<div class="dupcap-marketing-buttons">
								<?php if ( $can ) : ?>
									<button
										type="button"
										class="dupcap-marketing-btn dupcap-primary-btn"
										data-dupcap-autopoly-action
										data-action="<?php echo esc_attr( $action ); ?>"
										data-slug="<?php echo esc_attr( $slug ); ?>"
										data-init="<?php echo esc_attr( $init ); ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'dupcap_install_nonce' ) ); ?>"
									>
										<span class="dupcap-btn-text"><?php echo esc_html( $label ); ?></span>
									</button>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'You do not have permission to install or activate plugins.', 'duplicate-content-addon-for-polylang' ); ?></p>
								<?php endif; ?>
								<a
									href="<?php echo esc_url( $learn ); ?>"
									target="_blank"
									rel="noopener noreferrer"
									class="dupcap-marketing-btn dupcap-secondary-btn"
								>
									<?php esc_html_e( 'Learn More', 'duplicate-content-addon-for-polylang' ); ?>
								</a>
							</div>
							<div class="dupcap-install-message" data-dupcap-autopoly-msg hidden></div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Panel shown when Translation Inspector is switched off in setup.
		 *
		 * @since 2.0.7
		 *
		 * @return void
		 */
		private function render_inspector_disabled() {
			$setup_url = add_query_arg(
				array(
					'page'              => DUPCAP_Admin::PAGE,
					'dupcap_onboarding' => '1',
				),
				admin_url( 'admin.php' )
			);
			?>
			<div class="dupcap-admin-shell">
				<?php $this->render_plugin_topbar(); ?>
				<div class="wrap dupcap-wrap">
					<div class="dupcap-card dupcap-disabled-card">
						<h2><?php esc_html_e( 'Translation Inspector is switched off', 'duplicate-content-addon-for-polylang' ); ?></h2>
						<p>
							<?php esc_html_e( 'You turned this tool off during setup, so no scanning runs. Switch it back on to inspect your multilingual site again.', 'duplicate-content-addon-for-polylang' ); ?>
						</p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( $setup_url ); ?>">
								<?php esc_html_e( 'Open setup', 'duplicate-content-addon-for-polylang' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Plugin branding bar: name on the left, Support / Docs on the right.
		 *
		 * Own markup and CSS classes (dupcap-plugin-topbar*), styled to match
		 * AutoPoly's dashboard header (admin/inspector/css/admin.css) so both
		 * read as the same "Toolkit for Polylang" brand — the title text
		 * below is the shared brand name, not this plugin's own product name.
		 *
		 * @return void
		 */
		private function render_plugin_topbar() {
			$plugin_name = __( 'Toolkit for Polylang', 'duplicate-content-addon-for-polylang' );
			$logo_url    = defined( 'DUPCAP_URL' ) ? DUPCAP_URL . 'assets/images/toolkit-for-polylang-logo.svg' : '';
			$support_url = 'https://wordpress.org/support/plugin/duplicate-content-addon-for-polylang/';
			$docs_url    = 'https://wordpress.org/plugins/duplicate-content-addon-for-polylang/';

			/**
			 * Filter Translation Inspector topbar link URLs.
			 *
			 * @param array{support:string,docs:string} $urls Topbar URLs.
			 */
			$urls = apply_filters(
				'dupcap_inspector_topbar_urls',
				array(
					'support' => $support_url,
					'docs'    => $docs_url,
				)
			);

			$support_url = ! empty( $urls['support'] ) ? (string) $urls['support'] : $support_url;
			$docs_url    = ! empty( $urls['docs'] ) ? (string) $urls['docs'] : $docs_url;
			?>
			<header class="dupcap-plugin-topbar" role="banner">
				<div class="dupcap-plugin-topbar-inner">
					<div class="dupcap-plugin-topbar-brand">
						<?php
						$dupcap_hub_url = class_exists( 'TFP_Toolkit_Hub' )
							? admin_url( 'admin.php?page=' . TFP_Toolkit_Hub::PAGE )
							: admin_url( 'admin.php?page=toolkit-for-polylang' );
					?>
						<a href="<?php echo esc_url( $dupcap_hub_url ); ?>" class="dupcap-plugin-topbar-link">
							<?php if ( $logo_url ) : ?>
								<img
									class="dupcap-plugin-topbar-logo"
									src="<?php echo esc_url( $logo_url ); ?>"
									alt=""
									width="28"
									height="28"
									decoding="async"
								/>
							<?php endif; ?>
							<span class="dupcap-plugin-topbar-name"><?php echo esc_html( $plugin_name ); ?></span>
						</a>
					</div>
					<?php if ( class_exists( 'TFP_Toolkit_Hub' ) ) : ?>
						<?php TFP_Toolkit_Hub::render_nav( 'inspector' ); ?>
					<?php endif; ?>
					<div class="dupcap-plugin-topbar-actions">
						<a
							class="tfp-header-btn tfp-header-btn-support"
							href="<?php echo esc_url( $support_url ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php esc_html_e( 'Get Support', 'duplicate-content-addon-for-polylang' ); ?>
						</a>
						<a
							class="tfp-header-btn tfp-header-btn-docs"
							href="<?php echo esc_url( $docs_url ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						>
							<span class="dashicons dashicons-media-document tfp-header-btn-icon" aria-hidden="true"></span>
							<?php esc_html_e( 'Check Docs', 'duplicate-content-addon-for-polylang' ); ?>
						</a>
					</div>
				</div>
			</header>
			<?php
		}

		private function send_dashboard_fragments( array $extra = array() ) {
			$results = $this->get_scan_results();

			ob_start();
			$this->render_header_inner( $results );
			$header = ob_get_clean();

			ob_start();
			$this->render_dashboard_notices_inner();
			$notices = ob_get_clean();

			ob_start();
			$this->render_dashboard_body( $results );
			$body = ob_get_clean();

			wp_send_json_success(
				array_merge(
					array(
						'header'  => $header,
						'notices' => $notices,
						'body'    => $body,
					),
					$extra
				)
			);
		}

	}

