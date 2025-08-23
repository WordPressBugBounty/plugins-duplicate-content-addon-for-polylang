<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'dupcap_notices' ) ) :

	final class dupcap_notices {


		private static $instance = null;

		private function __construct() {
			add_action( 'admin_notices', array( $this, 'automatic_translations_for_polylang_notice' ) );
			add_action( 'wp_ajax_dupcap_notice_dismiss', array( $this, 'dupcap_notice_dismiss' ) );

		}

		// Singleton pattern to ensure only one instance of the class
		public static function get_instance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

        public function dupcap_admin_script(){
            wp_enqueue_script( 'dupcap-admin', DUPCAP_URL . 'Admin/notice/js/dupcap-notice.js', array( 'jquery' ), DUPCAP_VERSION, true );
        }

		public function automatic_translations_for_polylang_notice() {

			$plugin_path = WP_PLUGIN_DIR . '/automatic-translations-for-polylang/automatic-translation-for-polylang.php';

			if ( ! file_exists( $plugin_path ) ) {
                $this->dupcap_admin_script();
				if ( current_user_can( 'activate_plugins' ) ) {
					$review_nonce = wp_create_nonce( 'dupcap_atp_notice' );
					$ajax_url     = admin_url( 'admin-ajax.php' );
					$url          = 'plugin-install.php?tab=plugin-information&plugin=automatic-translations-for-polylang&TB_iframe=true';
					$title        = 'Automatic Translations For Polylang';
					$plugin_info  = get_plugin_data( DUPCAP_FILE, true, true );
					$logo_url     = DUPCAP_URL . 'assets/images/auto-translate-logo.png';

					$notice_content = sprintf(
						__(
							'🎉 Great News! We see you are using <strong>%1$s</strong> plugin. Why not supercharge your workflow with the <a href="%2$s" class="thickbox"title="%3$s">%4$s</a>.</br> Enjoy effortless translations with just one click—saving you valuable time and energy!.</br><a class="button button-primarythickbox" href="%2$s" target="_blank" style="margin-top: 6px;">Try it now!</a>',
							'duplicate-content-addon-for-polylang'
						),
						esc_html( $plugin_info['Name'] ),
						esc_url( $url ),
						esc_attr( $title ),
						esc_attr( $title )
					);

					$notice_content = wp_kses(
						$notice_content,
						array(
							'a'      => array(
								'href'  => array(),
								'class' => array(),
								'title' => array(),
								'style' => array(),
							),
							'strong' => array(),
							'br'     => array(),
							'button' => array(
								'class' => array(),
								'style' => array(),
							),
							'img'    => array(
								'src'   => array(),
								'alt'   => array(),
								'style' => array(),
							),
							'p'      => array(
								'style' => array(),
							),
						)
					);

					echo '<div class="notice notice-success is-dismissible" style="padding: 10px 0px 10px 10px; border-left-width: 1px;" data-url="' . esc_url( $ajax_url ) . '"data-nonce="' . esc_attr( $review_nonce ) . '">';
					echo '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $title ) . '" style="width: 76px; height: auto; vertical-align: middle; margin-right: 10px;margin-top: 6px;"><p style="display: inline-block; width: calc(100% - 95px); vertical-align: top;">';
					echo $notice_content;
					echo '</p></div>';
				}
			}
		}


		public function dupcap_notice_dismiss() {
			if ( ! check_ajax_referer( 'dupcap_atp_notice', 'nonce', false ) ) {
				wp_send_json_error( __( 'Invalid security token sent.', 'duplicate-content-addon-for-polylang' ) );
				wp_die( '0', 400 );
			}

			$dupcap_atp_dismiss = isset( $_POST['dupcap_atp_dismiss'] ) ? sanitize_text_field( $_POST['dupcap_atp_dismiss'] ) : false;

			if ( $dupcap_atp_dismiss ) {
				update_option( 'dupcap-atp-notice', 'yes' );
			}
			exit;
		}
	}

endif;

// Instantiate the class to trigger the constructor
dupcap_notices::get_instance();
