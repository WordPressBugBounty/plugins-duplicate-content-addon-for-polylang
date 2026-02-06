<?php 

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'dupcapFeedbackNotice' ) ) {
	class dupcapFeedbackNotice {
		/**
		 * The Constructor
		 */
		public function __construct() {
			// register actions

			if ( is_admin() ) {

				add_action( 'admin_notices', array( $this, 'dupcap_admin_notice_for_reviews' ) );
				add_action( 'wp_ajax_dupcap_dismiss_notice', array( $this, 'dupcap_dismiss_review_notice' ) );
			}
		}

		// ajax callback for review notice
		public function dupcap_dismiss_review_notice() {

			if(!current_user_can('manage_options')){
				wp_send_json_error( __( 'Unauthorized', 'duplicate-content-addon-for-polylang' ), 403 );
				wp_die( '0', 403 );
			}

			if ( ! isset( $_POST['private'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['private'] ) ), 'dupcap_review_nonce' ) ) {
				wp_send_json_error( array( 'message' => 'nonce verification failed' ) );
				exit();
			}
				update_option( 'dupcap-ratingDiv', 'yes' );
				wp_send_json_success();
		}
		// admin notice
		public function dupcap_admin_notice_for_reviews() {

			if ( ! current_user_can( 'update_plugins' ) ) {
				return;
			}
			 // get installation dates and rated settings
			 $installation_date = get_option( 'dupcap-installDate' );
			 $alreadyRated      = get_option( 'dupcap-ratingDiv' ) != false ? get_option( 'dupcap-ratingDiv' ) : 'no';

			 // check user already rated
			if ( $alreadyRated == 'yes' ) {
				return;
			}

			// grab plugin installation date and compare it with current date
			$display_date = gmdate( 'Y-m-d h:i:s' );
			$install_date = new DateTime( $installation_date );
			$current_date = new DateTime( $display_date );
			$difference   = $install_date->diff( $current_date );
			$diff_days    = $difference->days;

			// check if installation days is greator then week
			if ( isset( $diff_days ) && $diff_days >= 3 ) {

				wp_enqueue_script( 'dupcap-feedback-notice-script', DUPCAP_URL . 'assets/js/dupcap-admin-feedback-notice.js', array( 'jquery' ), DUPCAP_VERSION, true );
				wp_enqueue_style( 'dupcap-feedback-notice-styles', DUPCAP_URL . 'assets/css/dupcap-admin-feedback-notice.css', array(), DUPCAP_VERSION );
				
				// Output is properly escaped within create_notice_content() method
				echo $this->create_notice_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		// generated review notice HTML
		function create_notice_content() {

			$ajax_url           = admin_url( 'admin-ajax.php' );
			$ajax_callback      = 'dupcap_dismiss_notice';
			$wrap_cls           = 'notice notice-info is-dismissible';
			$p_name             = esc_html__( 'Duplicate Content Addon For Polylang', 'duplicate-content-addon-for-polylang' );
			$like_it_text       = 'Rate Now! ★★★★★';
			$already_rated_text = esc_html__( 'I already rated it', 'duplicate-content-addon-for-polylang' );
			$not_like_it_text   = esc_html__( 'No, not good enough, i do not like to rate it!', 'duplicate-content-addon-for-polylang' );
			$p_link             = esc_url( 'https://wordpress.org/support/plugin/duplicate-content-addon-for-polylang/reviews/#new-post' );
			$not_interested     = esc_html__( 'Not Interested', 'duplicate-content-addon-for-polylang' );
			$nonce              = wp_create_nonce( 'dupcap_review_nonce' );

			$message = sprintf(
				/* translators: %1$s: Plugin name, %2$s: Cool Plugins link */
				__( 'Thanks for using <b>%1$s</b> WordPress plugin. We hope it meets your expectations! <br/>Please give us a quick rating, it works as a boost for us to keep working on more %2$s!<br/>', 'duplicate-content-addon-for-polylang' ),
				esc_html( $p_name ),
				'<a href="https://coolplugins.net" target="_blank"><strong>' . esc_html__( 'Cool Plugins', 'duplicate-content-addon-for-polylang' ) . '</strong></a>'
			);

				$html = '<div data-ajax-url="%7$s" data-nonce="%10$s" data-ajax-callback="%8$s" class="cool-feedback-notice-wrapper %1$s">
				<div class="message_container">%3$s
					<div class="callto_action">
						<ul>
							<li class="love_it">
								<a href="%4$s" class="like_it_btn button button-primary" target="_new" title="%5$s">%5$s</a>
							</li>
							<li class="already_rated">
								<a href="javascript:void(0);" class="already_rated_btn button dupcap_dismiss_notice" title="%6$s">%6$s</a>
							</li>
							<li class="already_rated">
								<a href="javascript:void(0);" class="already_rated_btn button dupcap_dismiss_notice" title="%9$s">%9$s</a>
							</li>
						</ul>
						<div class="clrfix"></div>
					</div>
				</div>
			</div>';
			

			return sprintf(
				$html,
				esc_attr( $wrap_cls ),          
				esc_attr( $p_name ),            
				wp_kses_post( $message ),       
				esc_url( $p_link ),              
				esc_attr( $like_it_text ),      
				esc_attr( $already_rated_text ), 
				esc_url( $ajax_url ),            
				esc_attr( $ajax_callback ),     
				esc_attr( $not_interested ),    
				esc_attr( $nonce )             
			);
			

		}

	} //class end

}



