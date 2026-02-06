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

		public function automatic_translations_for_polylang_notice() {

			$pro_plugin_file      = 'autopoly-ai-translation-for-polylang-pro/autopoly-ai-translation-for-polylang-pro.php';
			$old_free_plugin_file = 'automatic-translations-for-polylang/automatic-translation-for-polylang.php';
			
			$active_plugins       = get_option( 'active_plugins', [] );
			
			if ( get_option( 'dupcap-atp-notice' ) === 'yes' || 	// Check if notice is dismissed, user cannot manage plugins, or plugin is already active
			( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'activate_plugins' ) ) ){
				return;
			}	
			
			if(in_array( $pro_plugin_file, $active_plugins, true ) ||
			in_array( $old_free_plugin_file, $active_plugins, true )){
				return;
			}

			if ( is_multisite() ) {

				$active_sitewide_plugins = get_site_option( 'active_sitewide_plugins', [] );
				if ( isset( $active_sitewide_plugins[ $pro_plugin_file ] ) || isset( $active_sitewide_plugins[ $old_free_plugin_file ] ) || isset( $active_sitewide_plugins[ $old_free_plugin_file ] ) ) {
					return;
				}
			}

	        wp_enqueue_script( 'dupcap-marketing-js', DUPCAP_URL . 'assets/js/dupcap-marketing-popup.js', array( 'jquery' ), DUPCAP_VERSION, true );
		
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all_plugins = get_plugins();

			// Check if pro plugin exists on site, prioritize pro over free
			$is_pro_installed = isset( $all_plugins[ $pro_plugin_file ] );
			$is_free_installed = isset( $all_plugins[ $old_free_plugin_file ] ) || isset( $all_plugins[ $old_free_plugin_file ] );

			// Determine which plugin to act on
			if ( $is_pro_installed ) {
				$plugin_file = $pro_plugin_file;
				$plugin_slug = 'autopoly-ai-translation-for-polylang-pro';
				$action      = 'activate';
				$button_text = __( 'Activate Pro', 'duplicate-content-addon-for-polylang' );
			} else {
				$is_singular_installed = isset( $all_plugins[ $old_free_plugin_file ] );
				$plugin_file = $is_singular_installed ? $old_free_plugin_file : $old_free_plugin_file;
				$plugin_slug = 'automatic-translations-for-polylang';
				$action      = $is_free_installed ? 'activate' : 'install';
				$button_text = $is_free_installed ? __( 'Activate Now', 'duplicate-content-addon-for-polylang' ) : __( 'Install Now', 'duplicate-content-addon-for-polylang' );
			}

			// Ensure standard class name is used for the AJAX handler binding
			$button_class  = 'button button-primary dupcap-install-plugin';
			$nonce         = wp_create_nonce( 'dupcap_install_nonce' );
			$dismiss_nonce = wp_create_nonce( 'dupcap_atp_notice' );

			echo '<div class="notice notice-info is-dismissible fdbgp-card-wrapper" data-nonce="' . esc_attr( $dismiss_nonce ) . '" data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">
						<p><span class="dashicons dashicons-editor-help" style="margin-top: 5px;"></span><strong>' . esc_html__( 'Did you know?', 'duplicate-content-addon-for-polylang' ) . '</strong> ' . esc_html__( 'Autopoly can automatically translate your Page/Post content using AI.', 'duplicate-content-addon-for-polylang' ) . ' <button type="button" class="' . esc_attr( $button_class ) . '" style="margin-left: 10px;"
								data-action="' . esc_attr( $action ) . '" 
								data-slug="' . esc_attr( $plugin_slug ) . '" 
								data-nonce="' . esc_attr( $nonce ) . '">
								<span class="dupcap-btn-text">' . esc_html( $button_text ) . '</span>
							</button></p>
							<div class="dupcap-install-message" style="margin-top: 5px; color: #d63638;"></div>
						</div>';
						return;
		}
		
		public function dupcap_notice_dismiss() {

			// Check capability first to prevent timing attacks
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Unauthorized access.', 'duplicate-content-addon-for-polylang' ) );
				wp_die( '0', 403 );
			}

            if ( ! check_ajax_referer( 'dupcap_atp_notice', 'nonce', false ) ) {
                wp_send_json_error( __( 'Invalid security token sent.', 'duplicate-content-addon-for-polylang' ) );
                wp_die( '0', 400 );
            }

		   	$dupcap_atp_dismiss = isset( $_POST['dupcap_atp_dismiss'] ) ? sanitize_text_field( wp_unslash( $_POST['dupcap_atp_dismiss'] ) ) : false;

			if ( $dupcap_atp_dismiss ) {
				update_option( 'dupcap-atp-notice', 'yes' );
			}
			die();
		}
	}

endif;

// Instantiate the class to trigger the constructor
dupcap_notices::get_instance();
