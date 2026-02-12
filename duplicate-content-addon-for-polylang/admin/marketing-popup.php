<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dupcap_Marketing_Popup {

	/**
	 * Instance of the class
	 *
	 * @var Dupcap_Marketing_Popup
	 */
	private static $instance = null;

	/**
	 * Get the instance of the class
	 *
	 * @return Dupcap_Marketing_Popup
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {

		add_action( 'admin_footer', array( $this, 'dupcap_output_modal_in_footer' ) );
		add_action( 'wp_ajax_dupcap_install_plugin', array( $this, 'dupcap_install_plugin' ) );
        add_action( 'manage_posts_extra_tablenav', array( $this, 'dupcap_add_autotranslate_button' ) );
	}

    /**
     * Add Autotranslate button to the post list table.
     *
     * @param string $which The position of the table nav (top or bottom).
     */
    public function dupcap_add_autotranslate_button( $which ) {
        
        if ( $which !== 'top' ) {
            return;
        }
    
        $screen = get_current_screen();
        if ( ! $screen || 'edit' !== $screen->base ) {
            return;
        }
    
        // Only if Autopoly is NOT active (neither Free nor Pro)
        $is_pro_active   = defined( 'ATFPP_V' );
        $is_free_active  = defined( 'ATFP_V' );
        
        if ( get_option( 'dupcap-atp-notice' ) !== 'yes' || $is_pro_active || $is_free_active ) {
            return;
        }
    
        ?>
        <div class="alignleft actions">
            <a href="https://coolplugins.net/product/autopoly-ai-translation-for-polylang/?utm_source=pdca_plugin&utm_medium=inside&utm_campaign=view_plugin&utm_content=all_pages" target="_blank" class="button button-primary" style="display:flex; align-items:center; gap:5px; margin-top: 1px;">
                <?php esc_html_e( 'AI Translation', 'duplicate-content-addon-for-polylang' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Handle AJAX request to install or activate a plugin.
     */
    public function dupcap_install_plugin() {

        if (! current_user_can('install_plugins')) {
            wp_send_json_error([
                'errorMessage' => esc_html__('Sorry, you are not allowed to install plugins on this site.', 'duplicate-content-addon-for-polylang'),
            ]);
        }

		check_ajax_referer('dupcap_install_nonce', '_wpnonce');
					
		if (empty($_POST['slug'])) {
            wp_send_json_error([
                'slug'         => '',
                'errorCode'    => 'no_plugin_specified',
                'errorMessage' => esc_html__('No plugin specified.', 'duplicate-content-addon-for-polylang'),
            ]);
        }

        $plugin_slug 	  = sanitize_key(wp_unslash($_POST['slug']));
					
		$allowed_slugs = [
			'autopoly-ai-translation-for-polylang-pro',
			'automatic-translations-for-polylang',
		];

		if ( ! in_array( $plugin_slug, $allowed_slugs, true ) ) {
            wp_send_json_error([
				'slug'         => $plugin_slug,
				'errorCode'    => 'invalid_plugin_slug',
				'errorMessage' => esc_html__('Invalid plugin slug specified.', 'duplicate-content-addon-for-polylang'),
		    ]);
		}

        $plugin_action = isset($_POST['plugin_action']) ? sanitize_key($_POST['plugin_action']) : 'install';
        
        $status      = [
            'action' => $plugin_action,
            'slug'   => $plugin_slug,
        ];
		
        // If user is installing/activating Autopoly, consider the persistent notice dismissed
        update_option( 'cpel_autopoly_installed', 'dupcap' );

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                    
        // Handle Activation Request
        if ( $plugin_action === 'activate' ) {
			if (! current_user_can('activate_plugins')) {
				wp_send_json_error(['message' => 'Permission denied']);
			}

			$plugin_file = $this->find_main_plugin_file( $plugin_slug );
                                
			if ($plugin_file) {
				$network_wide = is_multisite();
				$result       = activate_plugin($plugin_file, '', $network_wide, true);
				if (is_wp_error($result)) {
					wp_send_json_error(['message' => $result->get_error_message()]);
				}
				wp_send_json_success(['message' => 'Plugin activated successfully']);
			} else {
				wp_send_json_error(['message' => 'Plugin file not found.']);
			}
        }

        // Handle Installation Request
        $api = plugins_api('plugin_information', [
            'slug'   => $plugin_slug,
            'fields' => [
                'sections' => false,
            ],
        ]);

        if (is_wp_error($api)) {
            $status['errorMessage'] = $api->get_error_message();
            wp_send_json_error($status);
        }

        $status['pluginName'] = $api->name;
        $skin                 = new WP_Ajax_Upgrader_Skin();
        $upgrader             = new Plugin_Upgrader($skin);
        $result               = $upgrader->install($api->download_link);

        if (is_wp_error($result)) {
            $status['errorCode']    = $result->get_error_code();
            $status['errorMessage'] = $result->get_error_message();
            wp_send_json_error($status);

        } elseif (is_wp_error($skin->result)) {
            if ($skin->result->get_error_message() === 'Destination folder already exists.') {
                // Already installed, ready to activate
                $status['activated'] = false; 
                wp_send_json_success($status);
            } else {
                $status['errorCode']    = $skin->result->get_error_code();
                $status['errorMessage'] = $skin->result->get_error_message();
                wp_send_json_error($status);
            }
        } elseif ($skin->get_errors()->has_errors()) {
            $status['errorMessage'] = $skin->get_error_messages();
            wp_send_json_error($status);
        } elseif (is_null($result)) {
            global $wp_filesystem;
            $status['errorCode'] = 'unable_to_connect_to_filesystem';
            $status['errorMessage'] = esc_html__('Unable to connect to the filesystem. Please confirm your credentials.', 'duplicate-content-addon-for-polylang');
            if ($wp_filesystem instanceof WP_Filesystem_Base && is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->has_errors()) {
                $status['errorMessage'] = esc_html($wp_filesystem->errors->get_error_message());
            }
            wp_send_json_error($status);
        } 
                  
        // Attempt Activation after Install
        $install_status = install_plugin_install_status($api);
        $plugin_file_to_activate = '';
        
        if ( isset( $install_status['file'] ) && ! empty( $install_status['file'] ) && file_exists( WP_PLUGIN_DIR . '/' . $install_status['file'] ) ) {
            $plugin_file_to_activate = $install_status['file'];
        }

        if ( empty( $plugin_file_to_activate ) ) {
            $plugin_file_to_activate = $this->find_main_plugin_file( $plugin_slug );
        }

        if ( ! empty( $plugin_file_to_activate ) ) {
            $network_wide = is_multisite();
            $activation_result = activate_plugin($plugin_file_to_activate, '', $network_wide);      
            if ( is_wp_error( $activation_result ) ) {
                    $status['errorMessage'] = 'Activation Error: ' . $activation_result->get_error_message();
                    $status['activated'] = false;
            } else {
                $status['activated'] = true;
                $status['message']   = 'Plugin installed and activated successfully.';
            }
        } else {
                $status['activated'] = false; 
                $status['errorMessage'] = 'Plugin installed, but could not detect main file for activation.';
        }           

        wp_send_json_success($status);
    }

    /**
     * Helper to find the main plugin file for a slug.
     * 
     * @param string $slug
     * @return string|false Relative path to plugin file or false.
     */
    private function find_main_plugin_file( $slug ) {
        // 1. Direct match
        $path = $slug . '/' . $slug . '.php';
        if ( file_exists( WP_PLUGIN_DIR . '/' . $path ) ) {
            return $path;
        }

        // 2. Scan directory
        $slug_dir = WP_PLUGIN_DIR . '/' . $slug;
        if ( is_dir( $slug_dir ) ) {
            $files = glob( $slug_dir . '/*.php' );
            if ( $files && isset($files[0]) ) {
                return $slug . '/' . basename($files[0]);
            }
        }
        return false;
    }


    /**
     * Output the marketing modal in the admin footer.
     */
    public function dupcap_output_modal_in_footer() {

		$screen = get_current_screen();
        
        // Allow on post edit screens OR list screens (edit.php)
		if ( ! $screen || ! in_array( $screen->base, ['post'] ) ) {
			return;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $from_post_id = isset($_GET['from_post']) ? absint($_GET['from_post']) : 0;
        
        // Is this a duplication task?
        $is_duplicate_task = ($from_post_id > 0);

        // Get the current post
        $post = get_post();
        
        // If explicitly a duplication task, do checks. If generic list page, skip content checks.
        if ($is_duplicate_task) {
            // Check if the original post is an Elementor page
            if ( $from_post_id > 0 ) {
                $is_elementor = get_post_meta( $from_post_id, '_elementor_edit_mode', true );
                if ( $is_elementor === 'builder' ) {
                    return;
                }
            }
            
            // Hide if content is already present (e.g. after duplication)
            if ( $post && ! empty( $post->post_content ) ) {
                return;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset($_GET['copy_content']) && $_GET['copy_content'] === 'true' ) {
                return;
            }
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_post_updated = ! empty( $_GET['message'] );
        
        // If post was just updated and has content, don't show the modal
        if ( $is_post_updated && $post && ! empty( $post->post_content ) ) {
            return;
        }
        
        $original_lang = false;
        if ( $from_post_id > 0 && function_exists('pll_get_post_language') ) {
             $original_lang = pll_get_post_language( $from_post_id, 'name' );
        }
        if ( ! $original_lang ) {
             $original_lang = get_bloginfo( 'language' );
        }

        // Enqueue Assets Here (Footer)
        wp_enqueue_style( 'dupcap-marketing-css', DUPCAP_URL . 'assets/css/marketing-popup.css', array(), DUPCAP_VERSION );
	    wp_enqueue_script( 'dupcap-marketing-js', DUPCAP_URL . 'assets/js/dupcap-marketing-popup.js', array( 'jquery' ), DUPCAP_VERSION, true );
        wp_enqueue_script( 'dupcap-gutenberg-js', DUPCAP_URL . 'assets/js/dupcap-marketing-trigger.js', array( 'jquery' ), DUPCAP_VERSION, true );
        
        // Logic for localized script vars
        if ( ! isset($post) ) { 
             $post = get_post(); 
        }
        $has_content = $post && ! empty( $post->post_content );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_copy_action = isset($_GET['copy_content']) && ( $_GET['copy_content'] === 'true' || $_GET['copy_content'] === '1' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $has_from_post = ! empty( $_GET['from_post'] );

        $should_show_trigger = $has_from_post && ! $has_content && ! $is_copy_action;

        $is_plugin_installed = defined( 'ATFP_V' ) || defined( 'ATFPP_V' );

        // Auto Open ONLY if: Trigger is shown AND Plugin NOT installed AND Post NOT just updated AND is post-new.php
        global $pagenow;
        $should_auto_open = $should_show_trigger && ! $is_plugin_installed && ! $is_post_updated && ( $pagenow === 'post-new.php' );
		$logo_url = DUPCAP_URL . 'assets/images/dupcap-icon.svg';
        wp_localize_script( 'dupcap-marketing-js', 'dupcapMarketing', array(
			'autoOpen' => $should_auto_open,
            'showTrigger' => $should_show_trigger,
            'logoUrl'  => $logo_url,
            'tooltipText' => __('Duplicate Content', 'duplicate-content-addon-for-polylang'),
            'postType' => isset( $screen->post_type ) ? $screen->post_type : ''
		) );


        $button_value = sprintf( 
            '%s %s', 
            __( 'Duplicate content from ', 'duplicate-content-addon-for-polylang' ), 
            $original_lang 
        );
        

        
        $modal_title = $is_duplicate_task ? esc_html__('DUPLICATE CONTENT', 'duplicate-content-addon-for-polylang') : esc_html__('AI TRANSLATION', 'duplicate-content-addon-for-polylang');
		?>
		<div id="dupcap-marketing-modal" class="dupcap-modal-overlay">
			<div class="dupcap-modal-content">
				<div class="dupcap-modal-header">
					<div class="dupcap-header-left">
						<span class="dashicons dashicons-images-alt2"></span>
						<h3><?php echo esc_html( $modal_title ); ?></h3>
					</div>
					<span class="dupcap-modal-close dashicons dashicons-no-alt"></span>
				</div>
				<div class="dupcap-modal-body">
                    <?php if ( $is_duplicate_task ) : ?>
					<div class="dupcap-main-section">
                        <?php /* translators: %s: Copy Content from */ ?>
                        <h2><?php printf( esc_html__( 'Copy content from %s?', 'duplicate-content-addon-for-polylang' ), esc_html( $original_lang ) ); ?></h2>
                        <p><?php esc_html_e('Copy all blocks and media from the original post to keep the layout consistent and start translating quickly.', 'duplicate-content-addon-for-polylang'); ?></p>
						
                        <button type="button" class="dupcap-copy-button button" 
                               data-from_lang="" 
                               id="dupcap-copy-button">
                            <span class="dashicons dashicons-images-alt2"></span>
                            <?php /* translators: %s: Copy from */ ?>
                            <?php printf( esc_html__( 'Copy from %s', 'duplicate-content-addon-for-polylang' ), esc_html(   $original_lang ) ); ?>
                        </button>
					</div>
                    <?php endif; ?>
					
					<?php 
                        $is_pro_active   = defined( 'ATFPP_V' );
                        $is_free_active  = defined( 'ATFP_V' );
                        $is_plugin_active = $is_pro_active || $is_free_active;
                        
                        // Match text from image for title/desc
                        $marketing_title = __('Want auto-translation too?', 'duplicate-content-addon-for-polylang');
                        $marketing_desc  = __('Save hours of manual work by using AutoPoly to translate your content instantly using AI.', 'duplicate-content-addon-for-polylang');
                        
                        $btn_text        = $is_plugin_active ? __('Start Translation', 'duplicate-content-addon-for-polylang') : __('Install AutoPoly', 'duplicate-content-addon-for-polylang');
                        
                        // Magic Wand SVG
                        $magic_wand_svg = '<img src="' . DUPCAP_URL . 'assets/images/magic-wand.svg" style="width: 20px; height: 20px; margin-right: 5px; filter: brightness(0) invert(1);" alt="" />';
                        
                        if ( $is_plugin_active ) {
                             $btn_link = '#';
                             $btn_attrs = '';
                             $btn_class = 'dupcap-marketing-btn dupcap-primary-btn atfp-modal-open-warning-wrapper';
                             $btn_icon_html = $magic_wand_svg;
                        } else {
                             // Check if Pro or Free is installed but inactive
                             $btn_link  = '#';
                             $btn_class = 'dupcap-marketing-btn dupcap-primary-btn dupcap-install-plugin';
                             $btn_text_action = __('Install AutoPoly', 'duplicate-content-addon-for-polylang'); 
                             $btn_slug = 'automatic-translations-for-polylang';
                             $btn_action = 'install';

                             $pro_path = WP_PLUGIN_DIR . '/autopoly-ai-translation-for-polylang-pro/autopoly-ai-translation-for-polylang-pro.php';
                             $free_path = WP_PLUGIN_DIR . '/automatic-translations-for-polylang/automatic-translation-for-polylang.php';
                          
                             if ( file_exists( $pro_path ) ) {
                                  $btn_text_action =    __('Activate AutoPoly Pro', 'duplicate-content-addon-for-polylang');
                                  $btn_slug = 'autopoly-ai-translation-for-polylang-pro';
                                  $btn_action = 'activate';
                             } elseif ( file_exists( $free_path ) ) {
                                  $btn_text_action = __('Activate AutoPoly', 'duplicate-content-addon-for-polylang');
                                  if ( file_exists( $free_path ) ) {
                                      $btn_slug = 'automatic-translations-for-polylang';
                                  } 
                                  $btn_action = 'activate';
                             }

                             $btn_text = $btn_text_action;
                             $btn_attrs = 'data-slug="' . esc_attr($btn_slug) . '" data-action="' . esc_attr($btn_action) . '" data-nonce="' . wp_create_nonce( 'dupcap_install_nonce' ) . '"';
                             $btn_icon_html = $magic_wand_svg;
                        }
                    ?>
                    
					<div class="dupcap-marketing-card">
                        <h4><?php echo esc_html( $marketing_title ); ?></h4>
                        <p><?php echo esc_html( $marketing_desc ); ?></p>
                        
                        <div class="dupcap-marketing-buttons">
                            <a href="<?php echo esc_url( $btn_link ); ?>" <?php echo $btn_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="<?php echo esc_attr( $btn_class ); ?>">
                                <?php echo $btn_icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span class="dupcap-btn-text"><?php echo esc_html( $btn_text ); ?></span>
                            </a>
                            
                            <a href="https://coolplugins.net/product/autopoly-ai-translation-for-polylang/?utm_source=pdca_plugin&utm_medium=inside&utm_campaign=view_plugin&utm_content=popup" target="_blank" class="dupcap-marketing-btn dupcap-secondary-btn">
                                <?php esc_html_e('Learn More', 'duplicate-content-addon-for-polylang'); ?>
                            </a>
                        </div>
                        <div class="dupcap-install-message" style="margin-top: 10px; font-size: 13px; color: #dc2626;"></div>
                    </div>
				</div>
                
                <?php if ( $is_duplicate_task ) : ?>
                <div class="dupcap-modal-footer-note">
                     <span class="dashicons dashicons-warning"></span>
                     <p><em><?php esc_html_e( 'Note: Copying will replace existing content.', 'duplicate-content-addon-for-polylang' ); ?></em></p>
                </div>
                <?php endif; ?>
			</div>
		</div>
        <?php
    }

}

Dupcap_Marketing_Popup::get_instance();
