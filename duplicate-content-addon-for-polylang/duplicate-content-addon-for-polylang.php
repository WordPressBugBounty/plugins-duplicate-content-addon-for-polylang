<?php
/*
Plugin Name: Duplicate Content Addon For Polylang
Plugin URI: https://wordpress.org/plugins/duplicate-content-addon-for-polylang/
Version: 2.0.0
Author: Khushwant Singh
Author URI: https://profiles.wordpress.org/khushwantsidhu/
Description: Duplicate content addon for Polylang to copy content from one language post to other language post for easy and quick translation.
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: duplicate-content-addon-for-polylang
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DUPCAP_VERSION' ) ) {
	define( 'DUPCAP_VERSION', '2.0.0' );
}

if ( ! defined( 'DUPCAP_DIR_PATH' ) ) {
	define( 'DUPCAP_DIR_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'DUPCAP_URL' ) ) {
	define( 'DUPCAP_URL', plugin_dir_url( __FILE__ ) );
}

define( 'DUPCAP_FILE', __FILE__ );

if ( ! defined( 'DUPCAP_FEEDBACK_API' ) ) {
	define( 'DUPCAP_FEEDBACK_API', "https://feedback.coolplugins.net/" );
}

if ( ! class_exists( 'duplicateContentAddon' ) ) {
		final class duplicateContentAddon {

			/**
			 * Plugin instance.
			 *
			 * @var duplicateContentAddon
			 * @access private
			 */
			private static $instance = null;

			/**
			 * Get plugin instance.
			 *
			 * @return duplicateContentAddon
			 * @static
			 */
			public static function get_instance() {
				if ( ! isset( self::$instance ) ) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			/**
			 * Constructor
			 */
			private function __construct() {

				$this->dupcap_includes();
				add_action( 'plugins_loaded', array( $this, 'dupcap_init' ) );
				add_action( 'admin_init', array( $this, 'admin_notice' ) );
				register_activation_hook( DUPCAP_FILE, array( 'duplicateContentAddon', 'dupcap_activate' ) );
				register_deactivation_hook( DUPCAP_FILE, array( 'duplicateContentAddon', 'dupcap_deactivate' ) );
				
				add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'dupcap_plugin_action_links' ) );
			}

			function dupcap_includes() {

				if ( is_admin() ) {
					require_once __DIR__ . '/admin/feedback/users-feedback.php'; // Feed Back Notice
					require_once __DIR__ . '/includes/dupcap-feedback-notice.php';
					require_once __DIR__ . '/admin/marketing-popup.php';
					new dupcapFeedbackNotice();
				}
			}

			function dupcap_init() {
				// Check Polylang plugin is installed and active
				global $polylang;
               
				if ( isset( $polylang ) ) {
					
					// add_action( 'admin_enqueue_scripts', array( $this, 'dupcap_register_backend_assets' ) ); // registers js and css for frontend
					add_action( 'rest_api_init', array( &$this, 'dupcap_TitleContent' ), 2 ); // copy gutenberg content
					add_action( 'add_meta_boxes', array( &$this, 'dupcap_TitleContent' ), 5 ); // copy classic editor content
					add_filter( 'wp_generate_attachment_metadata', array( &$this, 'dupcap_attachment_metadata_update' ), 10, 2 );
				} else {
					add_action( 'admin_notices', array( self::$instance, 'dupcap_plugin_required_admin_notice' ) );
				}
			}

			function admin_notice() {
				// Check Duplicate Content Addon for Polylang is installed and active
				if ( is_plugin_active( 'duplicate-content-addon-for-polylang/duplicate-content-addon-for-polylang.php' ) ) {
					if ( ! get_option( 'dupcap-atp-notice', false ) ) {
						require_once DUPCAP_DIR_PATH . '/admin/notice/dupcap-notice.php';
					}
				}
			}

			function dupcap_plugin_required_admin_notice() {

				if ( current_user_can( 'activate_plugins' ) ) {
					$url         = 'plugin-install.php?tab=plugin-information&plugin=polylang&TB_iframe=true';
					$title       = 'Polylang';
				$plugin_info = get_plugin_data( __FILE__, true, true );
				echo '<div class="error"><p>' .
				wp_kses_post(
					sprintf(
						/* translators: 1: Current plugin name, 2: Polylang plugin install URL, 3: Polylang plugin title attribute, 4: Polylang plugin name */
						__(
							'In order to use <strong>%1$s</strong> plugin, please install and activate the latest version  of <a href="%2$s" class="thickbox" title="%3$s">%4$s</a>',
							'duplicate-content-addon-for-polylang'
						),
						esc_html( $plugin_info['Name'] ),
						esc_url( $url ),
						esc_attr( $title ),
						esc_attr( $title )
					)
				) . '.</p></div>';

						if ( function_exists( 'deactivate_plugins' ) ) {
							deactivate_plugins( __FILE__ );
						}
					}
			}

			public function dupcap_plugin_action_links($links) {
				
				if ( ! defined( 'ATFPP_V' ) ){
					$links[] = '<a href="https://coolplugins.net/product/autopoly-ai-translation-for-polylang/?utm_source=pdca_plugin&utm_medium=inside&utm_campaign=view_plugin&utm_content=plugins_list" target="_blank" style="font-weight:bold; color:#852636;">' . __( 'AI Translation', 'duplicate-content-addon-for-polylang' ) . '</a>';
					
				}
				return $links;
			}

			function dupcap_register_backend_assets() {
				wp_register_script( 'dupcap-js', DUPCAP_URL . 'assets/js/dupcap-script.js', array( 'jquery' ), DUPCAP_VERSION, true );
				wp_enqueue_script( 'dupcap-js' );
			}


				/**
				 * Copy Post/Page Title and content
				 */
				function dupcap_TitleContent() {

					if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'new-post-translation' ) && $GLOBALS['pagenow'] == 'post-new.php' && isset( $_GET['from_post'], $_GET['new_lang'], $_GET['copy_content'] ) ) {

						$from_post_id = absint( sanitize_text_field( wp_unslash( $_GET['from_post'] ) ) );

						if(!function_exists('current_user_can')){
							return;
						}

						if(!current_user_can('edit_post', $from_post_id)){
							return;
						}

						global $post;

						if ( ! ( $post instanceof WP_Post ) ) {
							return;
						}

						if ( ! PLL()->model->is_translated_post_type( $post->post_type ) ) {
							return;
						}

						if ( ! empty( $post->post_content ) ) {
							return;
						}

						// check polylang pro version and content duplication is active or not
						$duplicate_options                  = get_user_meta( get_current_user_id(), 'pll_duplicate_content', true );
						$is_polylang_pro_duplication_active = ! empty( $duplicate_options ) && ! empty( $duplicate_options[ $post->post_type ] );
						if ( $is_polylang_pro_duplication_active ) {
							// return;
						}

						$from_post_id = isset($_GET['from_post']) ? absint($_GET['from_post']) : 0;
						$get_new_lang = isset( $_GET['new_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['new_lang'] ) ) : '';

						$supported_langs = function_exists( 'pll_languages_list' ) ? pll_languages_list( [ 'fields' => 'slug' ] ) : [];
									
						if ( empty( $get_new_lang ) || ! in_array( $get_new_lang, $supported_langs, true ) ) {
							return; // stop execution if invalid language
						}
						
						$new_lang = PLL()->model->get_language( $get_new_lang );
						if ( ! $new_lang ) {
							return; // safety fallback
						}

						// $new_lang = PLL()->model->get_language(filter_var($_GET['new_lang'], FILTER_SANITIZE_STRING));

						$from_post_obj = get_post( $from_post_id );

						if ( has_blocks( $from_post_obj->ID ) ) {
							$new_post_id     = $post->ID;
							$orginal_post    = get_post( $from_post_obj->ID );
							$new_title       = $orginal_post->post_title;
							$new_lang_slug   = $new_lang->slug;
							$new_title      .= ' (' . $new_lang_slug . ' translation)';
							$current_user    = wp_get_current_user();
							$new_post_author = $current_user->ID;
							$args            = array(
								'ID'             => $new_post_id,
								'comment_status' => $orginal_post->comment_status,
								'ping_status'    => $orginal_post->ping_status,
								'post_author'    => $new_post_author,
								'post_content'   => wp_slash( $orginal_post->post_content ),
								'post_excerpt'   => $orginal_post->post_excerpt,
								'post_parent'    => $orginal_post->post_parent,
								'post_password'  => $orginal_post->post_password,
								'post_status'    => 'draft',
								'post_title'     => $new_title,
								'post_type'      => $post->post_type,
								'to_ping'        => $post->to_ping,
								'menu_order'     => $post->menu_order,
							);

						wp_update_post( $args );
                        
                        // IMPORTANT: Update global object so Classic Editor sees the new content immediately
                        $post->post_content = $orginal_post->post_content;
                        $post->post_title   = $new_title;

						// Get the post meta from the source post

						$this->dupcap_copy_post_meta( $post, $from_post_id );

						// Flush rewrite rules to avoid 404 errors on the new post
						flush_rewrite_rules( false );

						} else {

							// create copy of Post/Page content
							add_filter( 'dupcap_post_content', array( &$this, 'dupcap_post_content' ), 10, 3 );
							$filtered_content   = apply_filters( 'dupcap_post_content', $from_post_obj->post_content, $post, $new_lang->slug );

							// create copy of Post/Page title
							add_filter( 'dupcap_post_title', array( &$this, 'dupcap_filter_title' ), 10, 2 );
							$filtered_title   = apply_filters( 'dupcap_post_title', $from_post_obj->post_title, $new_lang->slug );
							
							// Update global post object for display in editor
							$post->post_content = $filtered_content;
							$post->post_title   = $filtered_title;

							// This function is used to copy all images of Post/Page
							$this->dupcap_post_media( $post, $from_post_id, $new_lang->slug );

							// This function is used to copy featured image
							$this->dupcap_featured_image( $post, $from_post_id, $new_lang->slug );

						// copy post meta data
						$this->dupcap_copy_post_meta( $post, $from_post_id );

						// Flush rewrite rules to avoid 404 errors on the new post
						flush_rewrite_rules( false );

						add_action(
						'admin_notices',
						function() {
								// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified in dupcap_shortcode_metabox() before this callback is added
								$from_post_id = isset($_GET['from_post']) ? absint($_GET['from_post']) : 0;
									?>
						<div class="notice notice-success is-dismissible">
						<p>
						<b><?php echo esc_html__( 'Copied', 'duplicate-content-addon-for-polylang' ); ?>:</b>
						<?php
						printf(
							/* translators: 1: Original post title, 2: Source language name */
							esc_html__( 'The title and content were successfully copied from "%1$s" (in %2$s).', 'duplicate-content-addon-for-polylang' ),
							esc_html( get_post( $from_post_id )->post_title ),
							esc_html( pll_get_post_language( $from_post_id, 'name' ) )
							);
							?>
						</p>
					</div>
									<?php
								}
							);
						}
					}
				}

				/**
				 * Functions used to copy post meta data
				 */
				function dupcap_copy_post_meta( $post, $from_post_id ) {
					$new_post_id = $post->ID;
					$meta_value  = get_post_meta( $from_post_id );
					foreach ( $meta_value as $key => $value ) {
						if ( substr( $key, 0, 1 ) != '_' ) {
							update_post_meta( $new_post_id, $key, $value[0] );
						}
					}
				}
				/**
				 * Functions used to filter content
				 */

				function dupcap_post_content( $content, $post, $new_lang_slug ) {

					if ( PLL()->model->options['media_support'] ) {
						add_filter( 'dupcap_post_content_filter', array( &$this, 'dupcap_rename_content_images' ), 10, 3 );
						add_filter( 'dupcap_post_content_filter', array( &$this, 'dupcap_rename_post_captions' ), 10, 3 );
						add_filter( 'dupcap_post_content_filter', array( &$this, 'dupcap_rename_gallery' ), 10, 3 );
						$content = apply_filters( 'dupcap_post_content_filter', $content, $post, $new_lang_slug );
					}

					return $content;

				}

				/**
				 * rename image's classes, tags...
				 */

				function dupcap_rename_content_images( $content, $post, $new_lang_slug ) {

					preg_match_all( '/<img[^>]+>/i', $content, $img_array );

					if ( empty( $img_array ) ) {
						return $content;
					}

					// create array
					$img_and_meta = array();
					for ( $i = 0; $i < count( $img_array[0] ); $i++ ) {
						$img_and_meta[ $i ] = array( 'tag' => $img_array[0][ $i ] );
					}

					foreach ( $img_and_meta as $i => $arr ) {

						// create array of classes
						preg_match( '/ class="([^"]*)"/i', $img_array[0][ $i ], $class_temp );
						$img_and_meta[ $i ]['class'] = ! empty( $class_temp ) ? $class_temp[1] : '';

						// check image is created by WordPress or not
						if ( ! strstr( $img_and_meta[ $i ]['class'], 'wp-image-' ) ) {
							continue;
						}

						// check attachment id
						preg_match( '/wp-image-(\d+)/i', $img_array[0][ $i ], $id_temp );

						if ( empty( $id_temp ) ) {
							continue;
						}

						$img_and_meta[ $i ]['id'] = (int) $id_temp[1];

						$attachment = get_post( $img_and_meta[ $i ]['id'] );

						if ( empty( $attachment ) || $attachment->post_type !== 'attachment' ) {
							continue;
						}

						$img_and_meta[ $i ]['new_id'] = $this->dupcap_translate_attachment( $img_and_meta[ $i ]['id'], $new_lang_slug, $post->ID );

						// check if already in right language
						if ( $img_and_meta[ $i ]['new_id'] == $img_and_meta[ $i ]['id'] ) {
							continue;
						}

						// create new classes
						$img_and_meta[ $i ]['new_class'] = preg_replace( '/wp-image-(\d+)/i', 'wp-image-' . $img_and_meta[ $i ]['new_id'], $img_and_meta[ $i ]['class'] );

						// create new tags
						$img_and_meta[ $i ]['new_tag'] = preg_replace( '/class="([^"]*)"/i', 'class="' . $img_and_meta[ $i ]['new_class'] . '"', $img_and_meta[ $i ]['tag'] );

						// for Gutenberg block
						$img_and_meta[ $i ]['new_tag'] = preg_replace( '/data-id="([^"]*)"/i', 'data-id="' . $img_and_meta[ $i ]['new_id'] . '"', $img_and_meta[ $i ]['new_tag'] );

						$content = str_replace( $img_and_meta[ $i ]['tag'], $img_and_meta[ $i ]['new_tag'], $content );

						$attachment_permalink = get_permalink( $attachment->ID );
						if ( strpos( $content, $attachment_permalink ) !== false ) {
							$new_attachment_permalink = get_permalink( $img_and_meta[ $i ]['new_id'] );
							$content                  = str_replace( $attachment_permalink, $new_attachment_permalink, $content );

							// replace rel part as well
							$content = str_replace( 'rel="attachment wp-att-' . $attachment->ID . '"', 'rel="attachment wp-att-' . $img_and_meta[ $i ]['new_id'] . '"', $content );

						}

						// find and replace HTML comments
						preg_match_all( '/<!-- wp:image {[^>]+} -->/i', $content, $comment_array );

						if ( empty( $comment_array ) ) {
							continue;
						}

						for ( $j = 0; $j < count( $comment_array[0] ); $j++ ) {

							$comment_tag = $comment_array[0][ $j ];

							preg_match( '/"id":(\d*)/i', $comment_tag, $comment_tag_id );

							if ( isset( $comment_tag_id[0] ) && isset( $comment_tag_id[1] ) && $comment_tag_id[1] == $img_and_meta[ $i ]['id'] ) {

								$new_id_tag      = str_replace( $comment_tag_id[1], $img_and_meta[ $i ]['new_id'], $comment_tag_id[0] );
								$new_comment_tag = str_replace( $comment_tag_id[0], $new_id_tag, $comment_tag );
								$content         = str_replace( $comment_tag, $new_comment_tag, $content );
							}
						}
					}

					return $content;

				}

				/**
				 * Rename caption shortcodes in content
				 */

				function dupcap_rename_post_captions( $content, $post, $new_lang_slug ) {

					preg_match_all( '/\[caption(.*?)\](.*?)\[\/caption\]/i', $content, $caption_array );

					if ( empty( $caption_array ) ) {
						return $content;
					}

					// create array
					$caption_and_meta = array();

					for ( $i = 0; $i < count( $caption_array[0] ); $i++ ) {
						$caption_and_meta[ $i ] = array( 'shortcode' => $caption_array[0][ $i ] );
					}

					foreach ( $caption_and_meta as $i => $arr ) {

						// create array of ids
						preg_match( '/ id="([^"]*)"/i', $caption_and_meta[ $i ]['shortcode'], $ids_temp );
						$caption_and_meta[ $i ]['id'] = ! empty( $ids_temp ) ? $ids_temp[1] : '';

						if ( ! strstr( $caption_and_meta[ $i ]['id'], 'attachment_' ) ) {
							continue;
						}

						// get the attachment id
						preg_match( '/attachment_(\d+)/i', $caption_and_meta[ $i ]['id'], $attachment_id_temp );

						if ( empty( $attachment_id_temp ) ) {
							continue;
						}

						$caption_and_meta[ $i ]['attachment_id'] = (int) $attachment_id_temp[1];

						$attachment = get_post( $caption_and_meta[ $i ]['attachment_id'] );

						if ( empty( $attachment ) || $attachment->post_type !== 'attachment' ) {
							continue;
						}

						$caption_and_meta[ $i ]['new_attachment_id'] = $this->dupcap_translate_attachment( $caption_and_meta[ $i ]['attachment_id'], $new_lang_slug, $post->ID );

						$caption_and_meta[ $i ]['new_id'] = preg_replace( '/attachment_(\d+)/i', 'attachment_' . $caption_and_meta[ $i ]['new_attachment_id'], $caption_and_meta[ $i ]['id'] );

						$caption_and_meta[ $i ]['new_shortcode'] = preg_replace( '/ id="([^"]*)"/i', ' id="' . $caption_and_meta[ $i ]['new_id'] . '"', $caption_and_meta[ $i ]['shortcode'] );

						preg_match( '/ \/>(.*?)\[\/caption\]/i', $caption_and_meta[ $i ]['new_shortcode'], $txt_temp );
						$caption_and_meta[ $i ]['txt'] = ! empty( $txt_temp ) ? $txt_temp[1] : '';

						if ( ! empty( $caption_and_meta[ $i ]['txt'] ) ) {

							$new_attachment = get_post( $caption_and_meta[ $i ]['new_attachment_id'] );

							$new_caption = ! empty( $new_attachment->post_excerpt ) ? $new_attachment->post_excerpt : '';

							$caption_and_meta[ $i ]['new_txt'] = apply_filters( 'dupcap_polylang_addon_filter_caption_txt', $new_caption, $new_attachment, $new_lang_slug );

							if ( ! empty( $caption_and_meta[ $i ]['new_txt'] ) ) {

								// replace the caption
								$caption_and_meta[ $i ]['new_shortcode'] = preg_replace( '/ \/>(.*?)\[\/caption\]/i', '/>' . $caption_and_meta[ $i ]['new_txt'] . '[/caption]', $caption_and_meta[ $i ]['new_shortcode'] );
							}
						}

						// replace image inside content
						$content = str_replace( $caption_and_meta[ $i ]['shortcode'], $caption_and_meta[ $i ]['new_shortcode'], $content );

					}

					return $content;

				}

				/**
				 * Replace gallery shortcodes
				 */

				function dupcap_rename_gallery( $content, $post, $new_lang_slug ) {

					preg_match_all( '/\[gallery (.*?)\]/i', $content, $gallery_array );

					if ( empty( $gallery_array ) ) {
						return $content;
					}

					$gallery_and_meta = array();
					for ( $i = 0; $i < count( $gallery_array[0] ); $i++ ) {
						$gallery_and_meta[ $i ] = array( 'shortcode' => $gallery_array[0][ $i ] );
					}

					foreach ( $gallery_and_meta as $i => $arr ) {

						preg_match( '/ ids="([^"]*)"/i', $gallery_and_meta[ $i ]['shortcode'], $ids_temp );
						$gallery_and_meta[ $i ]['ids'] = ! empty( $ids_temp ) ? $ids_temp[1] : '';

						if ( empty( $gallery_and_meta[ $i ]['ids'] ) ) {
							continue;
						}

						$gallery_ids_array = explode( ',', str_replace( ' ', '', $gallery_and_meta[ $i ]['ids'] ) );

						$gallery_ids_new_array = array();

						foreach ( $gallery_ids_array as $id ) {
							array_push( $gallery_ids_new_array, $this->dupcap_translate_attachment( $id, $new_lang_slug, $post->ID ) );
						}

						$gallery_and_meta[ $i ]['ids_new'] = implode( ',', $gallery_ids_new_array );

						$gallery_and_meta[ $i ]['shortcode_new'] = preg_replace( '/ ids="([^"]*)"/i', ' ids="' . $gallery_and_meta[ $i ]['ids_new'] . '"', $gallery_and_meta[ $i ]['shortcode'] );

						// replace galleries in content
						$content = str_replace( $gallery_and_meta[ $i ]['shortcode'], $gallery_and_meta[ $i ]['shortcode_new'], $content );

					}

					// find and replace HTML comments
					preg_match_all( '/<!-- wp:gallery {[^>]+} -->/i', $content, $comment_array );

					if ( ! empty( $comment_array ) ) {
						for ( $j = 0; $j < count( $comment_array[0] ); $j++ ) {

							$comment_tag = $comment_array[0][ $j ];

							preg_match( '/"ids":\[(.*)\]/i', $comment_tag, $comment_tag_id );

							if ( isset( $comment_tag_id[0] ) && isset( $comment_tag_id[1] ) ) {

								$old_ids = explode( ',', $comment_tag_id[1] );
								$new_ids = array();
								foreach ( $old_ids as $id ) {
									$new_ids[] = $this->dupcap_translate_attachment( $id, $new_lang_slug, $post->ID );
								}

								$new_id_tag      = str_replace( $comment_tag_id[1], implode( ',', $new_ids ), $comment_tag_id[0] );
								$new_comment_tag = str_replace( $comment_tag_id[0], $new_id_tag, $comment_tag );
								$content         = str_replace( $comment_tag, $new_comment_tag, $content );
							}
						}
					}

					return $content;

				}

				/**
				 * this function is used to filter title
				 */

				function dupcap_filter_title( $title, $new_lang_slug ) {
					return $title .= '(' . $new_lang_slug . ' translation)';
				}

				/**
				 * Duplicate featured image
				 */

				function dupcap_featured_image( $post, $from_post_id, $new_lang_slug ) {
					if ( has_post_thumbnail( $from_post_id ) ) {
						$post_thumbnail_id = get_post_thumbnail_id( $from_post_id );
						if ( PLL()->model->options['media_support'] ) {
							$post_thumbnail_id = $this->dupcap_translate_attachment( $post_thumbnail_id, $new_lang_slug, $post->ID );
						}
						set_post_thumbnail( $post, $post_thumbnail_id );
					}
				}

				/**
				 * this function is used to Copy all attached media
				 */

				function dupcap_post_media( $post, $from_post_id, $new_lang_slug ) {

					if ( PLL()->model->options['media_support'] ) {

						$from_lang   = pll_get_post_language( $from_post_id, 'slug' );
						$args        = array(
							'post_type'      => 'attachment',
							'posts_per_page' => -1,
							'no_found_rows'  => true,
							'parent'         => $from_post_id,
							'post_status'    => null,
							'lang'           => $from_lang,
						);
						$attachments = new WP_Query( $args );
					while ( $attachments->have_posts() ) :
						$attachments->the_post();

						$this->dupcap_translate_attachment( get_the_ID(), $new_lang_slug, $post->ID );
					endwhile;
						wp_reset_postdata();
						}

				}

				/**
				 * Translate attachment
				 */
				function dupcap_translate_attachment( $attachment_id, $new_lang, $parent_id ) {

					global $dupcap__attachment_cache;

					if ( empty( $dupcap__attachment_cache ) ) {
						$dupcap__attachment_cache = array();
					}

					if ( isset( $dupcap__attachment_cache[ $attachment_id ] ) ) {
						return $dupcap__attachment_cache[ $attachment_id ];
					}

					$post = get_post( $attachment_id );

					if ( empty( $post ) || is_wp_error( $post ) || ! in_array( $post->post_type, array( 'attachment' ) ) ) {
						return $attachment_id;
					}

					$post_id = $post->ID;

					$existing_translation = pll_get_post( $post_id, $new_lang );
					if ( ! empty( $existing_translation ) ) {
						return $existing_translation;
					}

					$post->ID          = null;
					$post->post_parent = $parent_id ? $parent_id : 0;

					$append_str         = ' (' . $new_lang . ' translation)';
					$post->post_excerpt = empty( $post->post_excerpt ) ? '' : $post->post_excerpt . $append_str;

					$tr_id = wp_insert_attachment( $post );
					add_post_meta( $tr_id, '_wp_attachment_metadata', get_post_meta( $post_id, '_wp_attachment_metadata', true ) );
					add_post_meta( $tr_id, '_wp_attached_file', get_post_meta( $post_id, '_wp_attached_file', true ) );

					if ( $meta = get_post_meta( $post_id, '_wp_attachment_image_alt', true ) ) {
						add_post_meta( $tr_id, '_wp_attachment_image_alt', $meta );
					}

					PLL()->model->post->set_language( $tr_id, $new_lang );

					$translations = PLL()->model->post->get_translations( $post_id );
					if ( ! $translations && $lang = PLL()->model->post->get_language( $post_id ) ) {
						$translations[ $lang->slug ] = $post_id;
					}

					$translations[ $new_lang ] = $tr_id;
					PLL()->model->post->save_translations( $tr_id, $translations );

					$dupcap__attachment_cache[ $attachment_id ] = $tr_id;

					return $tr_id; // newly translated attachment
				}

				/**
				 * This function is used to Generate attachment metadata
				 */
				function dupcap_attachment_metadata_update( $metadata, $attachment_id ) {

					$attachment_lang = PLL()->model->post->get_language( $attachment_id );
					$translations    = PLL()->model->post->get_translations( $attachment_id );

					foreach ( $translations as $lang => $tr_id ) {
						if ( ! $tr_id ) {
							continue;
						}

						if ( $attachment_lang->slug !== $lang ) {
							update_post_meta( $tr_id, '_wp_attachment_metadata', $metadata );
						}
					}

					return $metadata;

				}

				/*
				|------------------------------------------------------------------------
				|  Get user info
				|------------------------------------------------------------------------
				*/

			
				public static function dupcap_get_user_info() {
					global $wpdb;
					$server_info = [
					'server_software'        => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'N/A',
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						'mysql_version'          => $wpdb ? sanitize_text_field($wpdb->get_var("SELECT VERSION()")) : 'N/A',
						'php_version'            => sanitize_text_field(phpversion()),
						'wp_version'             => sanitize_text_field(get_bloginfo('version')),
						'wp_debug'               => sanitize_text_field(defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled'),
						'wp_memory_limit'        => sanitize_text_field(ini_get('memory_limit')),
						'wp_max_upload_size'     => sanitize_text_field(ini_get('upload_max_filesize')),
						'wp_permalink_structure' => sanitize_text_field(get_option('permalink_structure', 'Default')),
						'wp_multisite'           => sanitize_text_field(is_multisite() ? 'Enabled' : 'Disabled'),
						'wp_language'            => sanitize_text_field(get_option('WPLANG', get_locale()) ?: get_locale()),
						'wp_prefix'              => sanitize_key($wpdb->prefix), // Sanitizing database prefix
						];
						$theme_data = [
						'name'      => sanitize_text_field(wp_get_theme()->get('Name')),
						'version'   => sanitize_text_field(wp_get_theme()->get('Version')),
						'theme_uri' => esc_url(wp_get_theme()->get('ThemeURI')),
						];
						if (!function_exists('get_plugins')) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
						}
						$plugin_data = array_map(function ($plugin) {
						$plugin_info = get_plugin_data(WP_PLUGIN_DIR . '/' . sanitize_text_field($plugin));
						$author_url = ( isset( $plugin_info['AuthorURI'] ) && !empty( $plugin_info['AuthorURI'] ) ) ? esc_url( $plugin_info['AuthorURI'] ) : 'N/A';
						$plugin_url = ( isset( $plugin_info['PluginURI'] ) && !empty( $plugin_info['PluginURI'] ) ) ? esc_url( $plugin_info['PluginURI'] ) : '';
						return [
							'name'       => sanitize_text_field($plugin_info['Name']),
							'version'    => sanitize_text_field($plugin_info['Version']),
							'plugin_uri' => !empty($plugin_url) ? $plugin_url : $author_url,
						];
						}, get_option('active_plugins', []));
						return [
							'server_info' => $server_info,
							'extra_details' => [
								'wp_theme' => $theme_data,
								'active_plugins' => $plugin_data,
							]
						];
				}

				/*
				|----------------------------------------------------------------------------
				| Run when activate plugin.
				|----------------------------------------------------------------------------
				*/
				public static function dupcap_activate() {
					update_option( 'dupcap-v', DUPCAP_VERSION );
					update_option( 'dupcap-type', 'FREE' );
					update_option( 'dupcap-installDate', gmdate( 'Y-m-d h:i:s' ) );
					update_option( 'dupcap-ratingDiv', 'no' );

					if (!get_option( 'dupcap_initial_save_version' ) ) {
						add_option( 'dupcap_initial_save_version', DUPCAP_VERSION );
					}
				}

				/*
				|----------------------------------------------------------------------------
				| Run when de-activate plugin.
				|----------------------------------------------------------------------------
				*/
				public static function dupcap_deactivate() {
				}

		}

}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound 
function duplicateContentAddon() {
	return duplicateContentAddon::get_instance();
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$duplicateContentAddon = duplicateContentAddon();
