<?php
/**
 * Language Setup checks.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Check_Languages extends DUPCAP_Check {

	protected $group = 'Language Setup';

	public function run() {
		$results   = array();
		$languages = $this->active_languages();
		$default   = $this->default_lang();

		// 1. Default language exists.
		if ( $default ) {
			$results[] = $this->result(
				'lang_default',
				DUPCAP_Result::PASS,
				__( 'Default language set', 'duplicate-content-addon-for-polylang' ),
				/* translators: %s: language slug */
				sprintf( __( 'Default language is "%s".', 'duplicate-content-addon-for-polylang' ), $default )
			);
		} else {
			$results[] = $this->result(
				'lang_default',
				DUPCAP_Result::CRITICAL,
				__( 'No default language', 'duplicate-content-addon-for-polylang' ),
				__( 'Polylang has no default language. Nothing will be translated until you set one.', 'duplicate-content-addon-for-polylang' )
			)->with_fix( admin_url( 'admin.php?page=mlang' ), __( 'Set language', 'duplicate-content-addon-for-polylang' ) );
		}

		// 2. At least one secondary language.
		$count = count( $languages );
		if ( $count >= 2 ) {
			$results[] = $this->result(
				'lang_secondary',
				DUPCAP_Result::PASS,
				__( 'Secondary language present', 'duplicate-content-addon-for-polylang' ),
				/* translators: %d: number of languages */
				sprintf( _n( '%d language configured.', '%d languages configured.', $count, 'duplicate-content-addon-for-polylang' ), $count )
			);
		} else {
			$results[] = $this->result(
				'lang_secondary',
				DUPCAP_Result::CRITICAL,
				__( 'Only one language', 'duplicate-content-addon-for-polylang' ),
				__( 'You need at least two languages for translations to appear. Add a second language.', 'duplicate-content-addon-for-polylang' )
			)->with_fix( admin_url( 'admin.php?page=mlang' ), __( 'Add language', 'duplicate-content-addon-for-polylang' ) );
		}

		// 3. Language URLs / permalink structure.
		$permalinks = get_option( 'permalink_structure' );
		$force_lang = (int) $this->option( 'force_lang', 1 );
		if ( ! $permalinks && $force_lang > 0 ) {
			$results[] = $this->result(
				'lang_permalinks',
				DUPCAP_Result::WARNING,
				__( 'Plain permalinks in use', 'duplicate-content-addon-for-polylang' ),
				__( 'Pretty permalinks are off. Language URLs like /fr/ will not work correctly. Switch to a post-name permalink.', 'duplicate-content-addon-for-polylang' )
			)->with_fix( admin_url( 'options-permalink.php' ), __( 'Permalinks', 'duplicate-content-addon-for-polylang' ) );
		} else {
			$results[] = $this->result(
				'lang_permalinks',
				DUPCAP_Result::PASS,
				__( 'Language URLs configured', 'duplicate-content-addon-for-polylang' ),
				__( 'URL language settings look fine.', 'duplicate-content-addon-for-polylang' )
			);
		}

		// 4. Homepage assigned for every language (static front page case).
		$front_id = (int) get_option( 'page_on_front' );
		$show_on  = get_option( 'show_on_front' );
		if ( 'page' === $show_on && $front_id && function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $front_id );
			$missing      = array();
			$empty_layout = array();
			$source_layout = $this->front_page_has_elementor_layout( $front_id );

			foreach ( $languages as $lang ) {
				$slug = is_object( $lang ) ? $lang->slug : $lang;
				$trans_id = isset( $translations[ $slug ] ) ? (int) $translations[ $slug ] : 0;

				if ( $trans_id <= 0 ) {
					$missing[] = $slug;
					continue;
				}

				if ( $source_layout && ! $this->front_page_has_elementor_layout( $trans_id ) ) {
					$empty_layout[] = $slug;
				}
			}

			$kept_missing = array();
			foreach ( $missing as $missing_slug ) {
				if ( ! DUPCAP_Ignored_Issues::is_ignored( DUPCAP_Ignored_Issues::homepage_key( $missing_slug, 'missing' ) ) ) {
					$kept_missing[] = $missing_slug;
				}
			}
			$missing = $kept_missing;

			$kept_empty = array();
			foreach ( $empty_layout as $empty_slug ) {
				if ( ! DUPCAP_Ignored_Issues::is_ignored( DUPCAP_Ignored_Issues::homepage_key( $empty_slug, 'empty_layout' ) ) ) {
					$kept_empty[] = $empty_slug;
				}
			}
			$empty_layout = $kept_empty;

			if ( $missing || $empty_layout ) {
				$message_parts = array();
				$details       = array();
				$missing_names = array();
				$empty_names   = array();

				foreach ( $languages as $lang ) {
					$slug = is_object( $lang ) ? (string) $lang->slug : (string) $lang;
					$name = is_object( $lang ) && ! empty( $lang->name ) ? (string) $lang->name : strtoupper( $slug );

					if ( in_array( $slug, $missing, true ) ) {
						$missing_names[] = $name;
						$details[]       = array(
							'language'     => $slug,
							'slug'         => $slug,
							'name'         => $name,
							'label'        => sprintf(
								/* translators: %s: language name */
								__( 'Homepage missing in %s', 'duplicate-content-addon-for-polylang' ),
								$name
							),
							'message'      => __( 'No homepage translation is linked in Polylang yet.', 'duplicate-content-addon-for-polylang' ),
							'issue'        => 'missing',
							'ignore_key'   => DUPCAP_Ignored_Issues::homepage_key( $slug, 'missing' ),
							'action_url'   => $this->homepage_translation_link( $front_id, $slug ),
							'action_label' => sprintf(
								/* translators: %s: language name */
								__( 'Translate %s', 'duplicate-content-addon-for-polylang' ),
								$name
							),
						);
					} elseif ( in_array( $slug, $empty_layout, true ) ) {
						$empty_names[] = $name;
						$trans_id      = isset( $translations[ $slug ] ) ? (int) $translations[ $slug ] : 0;
						$edit_url      = $trans_id > 0 ? get_edit_post_link( $trans_id, 'url' ) : '';
						$details[]     = array(
							'language'     => $slug,
							'slug'         => $slug,
							'name'         => $name,
							'label'        => sprintf(
								/* translators: %s: language name */
								__( '%s homepage has no Elementor layout', 'duplicate-content-addon-for-polylang' ),
								$name
							),
							'message'      => __( 'A translation exists, but its Elementor layout is empty.', 'duplicate-content-addon-for-polylang' ),
							'issue'        => 'empty_layout',
							'ignore_key'   => DUPCAP_Ignored_Issues::homepage_key( $slug, 'empty_layout' ),
							'action_url'   => $edit_url ? $edit_url : get_edit_post_link( $front_id, 'url' ),
							'action_label' => sprintf(
								/* translators: %s: language name */
								__( 'Translate %s', 'duplicate-content-addon-for-polylang' ),
								$name
							),
						);
					}
				}

				if ( $missing_names ) {
					$message_parts[] = sprintf(
						/* translators: %s: comma separated language names */
						__( 'Front page is missing a translation in: %s.', 'duplicate-content-addon-for-polylang' ),
						implode( ', ', $missing_names )
					);
				}

				if ( $empty_names ) {
					$message_parts[] = sprintf(
						/* translators: %s: comma separated language names */
						__( 'Front page translation has no Elementor layout in: %s.', 'duplicate-content-addon-for-polylang' ),
						implode( ', ', $empty_names )
					);
				}

				$primary_action = array(
					'url'   => get_edit_post_link( $front_id, 'url' ),
					'label' => __( 'Edit homepage', 'duplicate-content-addon-for-polylang' ),
				);

				$results[] = $this->result(
					'lang_homepage',
					DUPCAP_Result::CRITICAL,
					__( 'Homepage not translated for all languages', 'duplicate-content-addon-for-polylang' ),
					implode( ' ', $message_parts )
				)->with_fix( $primary_action['url'], $primary_action['label'] )
				->with_details( $details );
			} else {
				$results[] = $this->result(
					'lang_homepage',
					DUPCAP_Result::PASS,
					__( 'Homepage translated for all languages', 'duplicate-content-addon-for-polylang' ),
					__( 'Every language has a front page.', 'duplicate-content-addon-for-polylang' )
				);
			}
		}

		return $results;
	}

	/**
	 * Polylang "add translation" URL for the static front page.
	 *
	 * @param int    $front_id  Default-language front page ID.
	 * @param string $lang_slug Target language slug.
	 * @return string
	 */
	protected function homepage_translation_link( $front_id, $lang_slug ) {
		$front_id  = (int) $front_id;
		$lang_slug = sanitize_key( (string) $lang_slug );
		$post      = get_post( $front_id );
		$pll       = function_exists( 'PLL' ) ? PLL() : null;

		if ( ! ( $post instanceof WP_Post ) || ! $pll || ! isset( $pll->model ) || '' === $lang_slug ) {
			$edit = get_edit_post_link( $front_id, 'url' );
			return $edit ? $edit : '';
		}

		$language = $pll->model->get_language( $lang_slug );
		if ( ! $language ) {
			$edit = get_edit_post_link( $front_id, 'url' );
			return $edit ? $edit : '';
		}

		if ( isset( $pll->links ) && method_exists( $pll->links, 'get_new_post_translation_link' ) ) {
			$link = $pll->links->get_new_post_translation_link( $post, $language, 'display' );
			if ( $link ) {
				return (string) $link;
			}
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'post_type' => $post->post_type,
					'from_post' => $front_id,
					'new_lang'  => $lang_slug,
				),
				admin_url( 'post-new.php' )
			),
			'new-post-translation'
		);
	}

	/**
	 * Whether a page is built with Elementor and has widget/section content.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function front_page_has_elementor_layout( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( ! class_exists( 'DUPCAP_Elementor_Document' ) ) {
			return false;
		}

		if ( 'builder' !== get_post_meta( $post_id, DUPCAP_Elementor_Document::META_EDIT_MODE, true ) ) {
			return false;
		}

		$document = new DUPCAP_Elementor_Document( $post_id );
		return $document->has_elements();
	}
}
