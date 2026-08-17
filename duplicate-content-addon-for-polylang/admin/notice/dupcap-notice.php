<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'dupcap_notices' ) ) :

	final class dupcap_notices {

		private static $instance = null;

		/**
		 * Cached notice data keyed by notice_option.
		 *
		 * @var array
		 */
		private $notice_data_cache = array();

		private function __construct() {
			add_action( 'admin_notices', array( $this, 'automatic_translations_for_polylang_notice' ) );
			add_action( 'admin_notices', array( $this, 'language_switcher_notice' ) );
			add_action( 'admin_footer', array( $this, 'language_switcher_sidebar_notice' ) );
			add_action( 'wp_ajax_dupcap_notice_dismiss', array( $this, 'dupcap_notice_dismiss' ) );
		}

		public static function get_instance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Build shared notice data for a promo plugin notice.
		 *
		 * Expected $config keys:
		 * - notice_option (string)
		 * - icon (string) dashicon class
		 * - title (string)
		 * - description (string)
		 * - description_sidebar (string) optional shorter copy for sidebar
		 * - active_constant (string) optional PHP constant that means already active
		 * - plugins (array) list of candidates, each with file, slug, optional activate_text
		 *
		 * @param array $config Notice configuration.
		 * @return array|false
		 */
		private function get_plugin_notice_data( $config ) {
			$notice_option = isset( $config['notice_option'] ) ? $config['notice_option'] : '';

			if ( '' !== $notice_option && isset( $this->notice_data_cache[ $notice_option ] ) ) {
				return $this->notice_data_cache[ $notice_option ];
			}

			if ( get_option( $notice_option ) === 'yes' ||
				( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'activate_plugins' ) ) ) {
				return $this->cache_notice_data( $notice_option, false );
			}

			if ( ! empty( $config['active_constant'] ) && defined( $config['active_constant'] ) ) {
				return $this->cache_notice_data( $notice_option, false );
			}

			$plugins        = isset( $config['plugins'] ) && is_array( $config['plugins'] ) ? $config['plugins'] : array();
			$active_plugins = get_option( 'active_plugins', array() );

			foreach ( $plugins as $plugin ) {
				$plugin_file = isset( $plugin['file'] ) ? $plugin['file'] : '';
				if ( '' !== $plugin_file && in_array( $plugin_file, $active_plugins, true ) ) {
					return $this->cache_notice_data( $notice_option, false );
				}
			}

			if ( is_multisite() ) {
				$active_sitewide_plugins = get_site_option( 'active_sitewide_plugins', array() );
				foreach ( $plugins as $plugin ) {
					$plugin_file = isset( $plugin['file'] ) ? $plugin['file'] : '';
					if ( '' !== $plugin_file && isset( $active_sitewide_plugins[ $plugin_file ] ) ) {
						return $this->cache_notice_data( $notice_option, false );
					}
				}
			}

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all_plugins = get_plugins();

			// Prefer first installed candidate (e.g. Pro). If none installed, install the last (free) candidate.
			$selected = null;
			foreach ( $plugins as $plugin ) {
				$plugin_file = isset( $plugin['file'] ) ? $plugin['file'] : '';
				if ( '' !== $plugin_file && isset( $all_plugins[ $plugin_file ] ) ) {
					$selected = $plugin;
					break;
				}
			}

			if ( null === $selected ) {
				$selected = ! empty( $plugins ) ? $plugins[ count( $plugins ) - 1 ] : null;
			}

			if ( null === $selected || empty( $selected['file'] ) || empty( $selected['slug'] ) ) {
				return $this->cache_notice_data( $notice_option, false );
			}

			$is_installed  = isset( $all_plugins[ $selected['file'] ] );
			$activate_text = ! empty( $selected['activate_text'] )
				? $selected['activate_text']
				: __( 'Activate Now', 'duplicate-content-addon-for-polylang' );

			$data = array(
				'plugin_file'          => $selected['file'],
				'plugin_slug'          => $selected['slug'],
				'action'               => $is_installed ? 'activate' : 'install',
				'button_text'          => $is_installed ? $activate_text : __( 'Install Now', 'duplicate-content-addon-for-polylang' ),
				'nonce'                => wp_create_nonce( 'dupcap_install_nonce' ),
				'dismiss_nonce'        => wp_create_nonce( 'dupcap_atp_notice' ),
				'notice_option'        => $notice_option,
				'icon'                 => isset( $config['icon'] ) ? $config['icon'] : 'dashicons-admin-plugins',
				'title'                => isset( $config['title'] ) ? $config['title'] : '',
				'description'          => isset( $config['description'] ) ? $config['description'] : '',
				'description_sidebar'  => isset( $config['description_sidebar'] ) ? $config['description_sidebar'] : ( isset( $config['description'] ) ? $config['description'] : '' ),
			);

			return $this->cache_notice_data( $notice_option, $data );
		}

		/**
		 * @param string     $notice_option Option key.
		 * @param array|false $data         Notice data.
		 * @return array|false
		 */
		private function cache_notice_data( $notice_option, $data ) {
			if ( '' !== $notice_option ) {
				$this->notice_data_cache[ $notice_option ] = $data;
			}
			return $data;
		}

		/**
		 * Render admin or sidebar notice HTML from shared data.
		 *
		 * @param array  $data   From get_plugin_notice_data().
		 * @param string $layout admin|sidebar.
		 */
		private function render_notice( $data, $layout = 'admin' ) {
			wp_enqueue_script( 'dupcap-marketing-js', DUPCAP_URL . 'assets/js/dupcap-marketing-popup.js', array( 'jquery' ), DUPCAP_VERSION, true );

			if ( 'sidebar' === $layout ) {
				?>
				<div id="dupcap-lsdp-ml-box-notice" class="notice notice-info inline lsdp-card-wrapper" style="display:none;" data-notice="<?php echo esc_attr( $data['notice_option'] ); ?>" data-nonce="<?php echo esc_attr( $data['dismiss_nonce'] ); ?>" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<div class="dupcap-icon"><span class="dashicons <?php echo esc_attr( $data['icon'] ); ?>"></span></div>
					<p class="dupcap-title"><strong><?php echo esc_html( $data['title'] ); ?></strong></p>
					<p class="dupcap-description"><?php echo wp_kses( $data['description_sidebar'], array( 'br' => array() ) ); ?></p>
					<button type="button" class="button button-primary dupcap-install-plugin"
						data-action="<?php echo esc_attr( $data['action'] ); ?>"
						data-slug="<?php echo esc_attr( $data['plugin_slug'] ); ?>"
						data-nonce="<?php echo esc_attr( $data['nonce'] ); ?>">
						<span class="dupcap-btn-text"><?php echo esc_html( $data['button_text'] ); ?></span>
					</button>
					<div class="dupcap-install-message" style="margin-top: 5px; color: #d63638;"></div>
					<button type="button" class="notice-dismiss dupcap-dismiss-btn"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'duplicate-content-addon-for-polylang' ); ?></span></button>
				</div>

				<?php
				return;
			}

			echo '<div class="notice notice-info is-dismissible lsdp-card-wrapper" data-notice="' . esc_attr( $data['notice_option'] ) . '" data-nonce="' . esc_attr( $data['dismiss_nonce'] ) . '" data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">
					<p class="lsdp-admin-container"><span class="dashicons ' . esc_attr( $data['icon'] ) . '" ></span><strong>' . esc_html( $data['title'] ) . '</strong> ' . esc_html( $data['description'] ) . ' <button type="button" class="button button-primary dupcap-install-plugin" style="margin-left: 10px;"
							data-action="' . esc_attr( $data['action'] ) . '"
							data-slug="' . esc_attr( $data['plugin_slug'] ) . '"
							data-nonce="' . esc_attr( $data['nonce'] ) . '">
							<span class="dupcap-btn-text">' . esc_html( $data['button_text'] ) . '</span>
						</button></p>
						<div class="dupcap-install-message" style="margin-top: 5px; color: #d63638;"></div>
					</div>';
		}

		/**
		 * Autopoly promo notice config.
		 *
		 * @return array
		 */
		private function get_autopoly_notice_config() {
			return array(
				'notice_option' => 'dupcap-atp-notice',
				'icon'          => 'dashicons-editor-help',
				'title'         => __( 'Did you know?', 'duplicate-content-addon-for-polylang' ),
				'description'   => __( 'Autopoly can automatically translate your Page/Post content using AI.', 'duplicate-content-addon-for-polylang' ),
				'plugins'       => array(
					array(
						'file'          => 'autopoly-ai-translation-for-polylang-pro/autopoly-ai-translation-for-polylang-pro.php',
						'slug'          => 'autopoly-ai-translation-for-polylang-pro',
						'activate_text' => __( 'Activate Pro', 'duplicate-content-addon-for-polylang' ),
					),
					array(
						'file' => 'automatic-translations-for-polylang/automatic-translation-for-polylang.php',
						'slug' => 'automatic-translations-for-polylang',
					),
				),
			);
		}

		/**
		 * Language Switcher promo notice config.
		 *
		 * @return array
		 */
		private function get_language_switcher_notice_config() {
			return array(
				'notice_option'       => 'dupcap-lsdp-notice',
				'icon'                => 'dashicons-translation',
				'title'               => __( 'Add a Language Switcher', 'duplicate-content-addon-for-polylang' ),
				'description'         => __( 'Did you know? You can add a customizable language switcher to help visitors easily browse your website in their preferred language.', 'duplicate-content-addon-for-polylang' ),
				'description_sidebar' => __( 'Let visitors browse in their preferred language with Language Switcher for Polylang for Elementor, Gutenberg, and Divi.', 'duplicate-content-addon-for-polylang' ),
				'active_constant'     => 'LSDP',
				'plugins'             => array(
					array(
						'file' => 'language-switcher-for-divi-polylang/language-switcher-for-divi-polylang.php',
						'slug' => 'language-switcher-for-divi-polylang',
					),
				),
			);
		}

		public function automatic_translations_for_polylang_notice() {
			$data = $this->get_plugin_notice_data( $this->get_autopoly_notice_config() );
			if ( ! $data ) {
				return;
			}
			$this->render_notice( $data, 'admin' );
		}

		public function language_switcher_notice() {
			$screen = get_current_screen();

			if ( ! $screen ) {
				return;
			}

			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

			$polylang_pages = array(
				'mlang',
				'mlang_strings',
				'mlang_settings',
			);

			if ( ! in_array( $page, $polylang_pages, true ) && 'edit-page' !== $screen->id && 'plugins' !== $screen->id ) {
				return;
			}

			$data = $this->get_plugin_notice_data( $this->get_language_switcher_notice_config() );

			if ( ! $data ) {
				return;
			}

			$this->render_notice( $data, 'admin' );
		}

		/**
		 * Same LSDP notice for Polylang Languages sidebar (#ml_box). Hidden until JS injects it.
		 */
		public function language_switcher_sidebar_notice() {
			$screen = get_current_screen();
			if ( ! $screen || 'post' !== $screen->base ) {
				return;
			}

			$config                   = $this->get_language_switcher_notice_config();
			$config['notice_option'] = 'dupcap-lsdp-sidebar-notice';

			$data = $this->get_plugin_notice_data( $config );
			if ( ! $data ) {
				return;
			}
			$this->render_notice( $data, 'sidebar' );
		}

		public function dupcap_notice_dismiss() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Unauthorized access.', 'duplicate-content-addon-for-polylang' ) );
				wp_die( '0', 403 );
			}

			if ( ! check_ajax_referer( 'dupcap_atp_notice', 'nonce', false ) ) {
				wp_send_json_error( __( 'Invalid security token sent.', 'duplicate-content-addon-for-polylang' ) );
				wp_die( '0', 400 );
			}

			$dupcap_atp_dismiss = isset( $_POST['dupcap_atp_dismiss'] ) ? sanitize_text_field( wp_unslash( $_POST['dupcap_atp_dismiss'] ) ) : false;
			$notice_option      = isset( $_POST['notice_option'] ) ? sanitize_key( wp_unslash( $_POST['notice_option'] ) ) : 'dupcap-atp-notice';
			$allowed_options    = array( 'dupcap-atp-notice', 'dupcap-lsdp-notice', 'dupcap-lsdp-sidebar-notice' );

			if ( $dupcap_atp_dismiss && in_array( $notice_option, $allowed_options, true ) ) {
				update_option( $notice_option, 'yes' );
			}
			wp_send_json_success();
		}
	}

endif;

dupcap_notices::get_instance();
