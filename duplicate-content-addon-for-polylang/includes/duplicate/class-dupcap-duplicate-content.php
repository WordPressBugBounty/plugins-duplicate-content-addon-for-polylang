<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DUPCAP_Duplicate_Content' ) ) {
	class DUPCAP_Duplicate_Content {

		private static $instance = null;

		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {}

		public function init() {
			add_action( 'rest_api_init', array( $this, 'dupcap_TitleContent' ), 2 ); // copy gutenberg content
			add_action( 'add_meta_boxes', array( $this, 'dupcap_TitleContent' ), 5 ); // copy classic editor content
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'dupcap_attachment_metadata_update' ), 10, 2 );
		}

			/**
			 * Copy Post/Page Title and content
			 */
			function dupcap_TitleContent() {

					$is_new_post_copy         = ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'new-post-translation' ) && $GLOBALS['pagenow'] === 'post-new.php' && isset( $_GET['from_post'], $_GET['new_lang'], $_GET['copy_content'] ) );
					$is_existing_post_replace = ( isset( $_GET['_dupcap_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_dupcap_nonce'] ) ), 'dupcap_replace_content' ) && $GLOBALS['pagenow'] === 'post.php' && isset( $_GET['from_post'], $_GET['new_lang'], $_GET['copy_content'], $_GET['replace_content'] ) );

					// A failed nonce is never re-signed here. Inspector fix links are signed
					// when the dashboard renders (see DUPCAP_Action_Links), so a stale link is
					// no longer possible, and minting a nonce for an unverified request would
					// remove the CSRF protection from the replace_content path.

					if ( $is_new_post_copy || $is_existing_post_replace ) {

						$from_post_id = absint( sanitize_text_field( wp_unslash( $_GET['from_post'] ) ) );
						$replace_content = isset( $_GET['replace_content'] ) ? sanitize_text_field( wp_unslash( $_GET['replace_content'] ) ) : '';

						if(!function_exists('current_user_can')){
							return;
						}

						global $post;

						if ( ! ( $post instanceof WP_Post ) ) {
							return;
						}

						// Defensive: require permissions on both source and target posts.
						if ( ! current_user_can( 'edit_post', $from_post_id ) || ! current_user_can( 'edit_post', $post->ID ) ) {
							return;
						}

						if ( ! PLL()->model->is_translated_post_type( $post->post_type ) ) {
							return;
						}

						if ( ! empty( $post->post_content ) && empty( $replace_content ) ) {
							return;
						}

						// check polylang pro version and content duplication is active or not
						$duplicate_options                  = get_user_meta( get_current_user_id(), 'pll_duplicate_content', true );
						$is_polylang_pro_duplication_active = ! empty( $duplicate_options ) && ! empty( $duplicate_options[ $post->post_type ] );
						if ( $is_polylang_pro_duplication_active ) {
							return;
						}
						$get_new_lang = isset( $_GET['new_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['new_lang'] ) ) : '';

						$supported_langs = function_exists( 'pll_languages_list' ) ? pll_languages_list( [ 'fields' => 'slug' ] ) : [];
									
						if ( empty( $get_new_lang ) || ! in_array( $get_new_lang, $supported_langs, true ) ) {
							return; // stop execution if invalid language
						}
						
						$new_lang = PLL()->model->get_language( $get_new_lang );
						if ( ! $new_lang ) {
							return; // safety fallback
						}

						$from_post_obj = get_post( $from_post_id );

						if ( ! ( $from_post_obj instanceof WP_Post ) ) {
							return;
						}

						if ( has_blocks( $from_post_obj->ID ) ) {
							$new_post_id     = $post->ID;
							$orginal_post    = $from_post_obj;
							$new_title       = $orginal_post->post_title;
							$new_lang_slug   = $new_lang->slug;
							$new_title .= ' (' . $new_lang_slug . ' translation)';
							$post_status     = $replace_content ? $post->post_status : 'draft';

							$args = array(
								'ID'             => $new_post_id,
								'comment_status' => $orginal_post->comment_status,
								'ping_status'    => $orginal_post->ping_status,
								'post_content'   => wp_slash( $orginal_post->post_content ),
								'post_excerpt'   => $orginal_post->post_excerpt,
								'post_parent'    => $orginal_post->post_parent,
								'post_password'  => $orginal_post->post_password,
								'post_status'    => $post_status,
								'post_title'     => $new_title,
								'post_type'      => $post->post_type,
								'to_ping'        => $post->to_ping,
								'menu_order'     => $post->menu_order,
							);

							// New auto-draft copy: current user becomes author.
							// Replace must keep the existing owner (matches classic branch).
							if ( empty( $replace_content ) ) {
								$args['post_author'] = get_current_user_id();
							}

							wp_update_post( $args );

								// IMPORTANT
								PLL()->model->post->set_language( $new_post_id, $new_lang_slug );

								$translations = PLL()->model->post->get_translations( $from_post_id );

								$translations[ $new_lang_slug ] = $new_post_id;

								PLL()->model->post->save_translations( $new_post_id, $translations );

								// Copy meta/media
								$this->dupcap_copy_post_meta( $post, $from_post_id );


								clean_post_cache( $new_post_id );
								
								wp_safe_redirect(
									admin_url(
										'post.php?post=' . $new_post_id . '&action=edit&message=1'
									)
								);
								
								exit;

						} else {

								// create copy of Post/Page content
								add_filter( 'dupcap_post_content', array( $this, 'dupcap_post_content' ), 10, 3 );
								$filtered_content   = apply_filters( 'dupcap_post_content', $from_post_obj->post_content, $post, $new_lang->slug );

								// create copy of Post/Page title
								add_filter( 'dupcap_post_title', array( $this, 'dupcap_filter_title' ), 10, 2 );
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
			 * Copy non-underscore post meta from the source post onto the translation.
			 *
			 * get_post_meta( $id ) with no key returns raw DB strings (still serialized).
			 * Unserialize + wp_slash before update_post_meta so maybe_serialize() does not
			 * double-serialize and update_metadata()'s wp_unslash() does not strip backslashes.
			 *
			 * @param WP_Post $post         Destination post (translation).
			 * @param int     $from_post_id Source post ID.
			 * @return void
			 */
			function dupcap_copy_post_meta( $post, $from_post_id ) {
				$new_post_id = $post->ID;
				$meta_value  = get_post_meta( $from_post_id );
				foreach ( $meta_value as $key => $values ) {
					if ( '_' !== substr( $key, 0, 1 ) ) {
						update_post_meta( $new_post_id, $key, wp_slash( maybe_unserialize( $values[0] ) ) );
					}
				}
			}
			/**
			 * Functions used to filter content
			 */
			function dupcap_post_content( $content, $post, $new_lang_slug ) {

				if ( PLL()->model->options['media_support'] ) {
					add_filter( 'dupcap_post_content_filter', array( $this, 'dupcap_rename_content_images' ), 10, 3 );
					add_filter( 'dupcap_post_content_filter', array( $this, 'dupcap_rename_post_captions' ), 10, 3 );
					add_filter( 'dupcap_post_content_filter', array( $this, 'dupcap_rename_gallery' ), 10, 3 );
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

	}
}
