<?php
/**
 * Menu / navigation checks: classic menu locations or block / FSE navigation.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Check_Menus extends DUPCAP_Check {

	protected $group = 'Menus';

	public function run() {
		$results   = array();
		$languages = $this->active_languages();

		if ( count( $languages ) < 2 ) {
			return $results;
		}

		require_once dirname( __DIR__ ) . '/class-dupcap-block-navigation.php';

		if ( DUPCAP_Block_Navigation::is_block_theme() ) {
			return $this->run_block_navigation( $languages );
		}

		$locations = get_registered_nav_menus();
		if ( empty( $locations ) ) {
			$results[] = $this->result(
				'menu_none',
				DUPCAP_Result::PASS,
				__( 'No menu locations to check', 'duplicate-content-addon-for-polylang' ),
				__( 'Your theme registers no nav menu locations.', 'duplicate-content-addon-for-polylang' )
			);
			return $results;
		}

		// Polylang stores per-language menu assignments under the 'nav_menus' option,
		// keyed by current theme then location then language slug.
		$nav_menus = (array) $this->option( 'nav_menus', array() );
		$theme     = get_option( 'stylesheet' );
		$theme_map = isset( $nav_menus[ $theme ] ) ? $nav_menus[ $theme ] : array();

		foreach ( $locations as $location => $label ) {
			// Skip Polylang temporary per-language locations (e.g. header___hi).
			// Assignments are stored under the base location only.
			if ( false !== strpos( $location, '___' ) ) {
				continue;
			}

			$english_label = self::english_menu_location_label( $location, $label );
			$missing       = array();
			$details       = array();
			$assign_url    = admin_url( 'nav-menus.php?action=locations' );

			foreach ( $languages as $lang ) {
				$slug     = is_object( $lang ) ? $lang->slug : $lang;
				$name     = is_object( $lang ) && ! empty( $lang->name ) ? $lang->name : strtoupper( $slug );
				$assigned = isset( $theme_map[ $location ][ $slug ] ) ? (int) $theme_map[ $location ][ $slug ] : 0;

				if ( $assigned > 0 ) {
					continue;
				}

				// One detail row per location + language so a dismissed row can be
				// matched again on the next scan and never comes back.
				$ignore_key = DUPCAP_Ignored_Issues::menu_key( $location, $slug );
				if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
					continue;
				}

				$missing[] = $slug;
				$details[] = array(
					'location'          => $location,
					'location_label'    => $english_label,
					'language'          => $slug,
					'language_name'     => $name,
					'missing_languages' => array( $slug ),
					'missing'           => array(
						array(
							'slug' => $slug,
							'name' => $name,
						),
					),
					'assign_url'        => $assign_url,
					'ignore_key'        => $ignore_key,
				);
			}

			if ( ! empty( $missing ) ) {
				$results[] = $this->result(
					'menu_' . $location,
					DUPCAP_Result::WARNING,
					/* translators: %s: menu location label */
					sprintf( __( '"%s" menu not set for all languages', 'duplicate-content-addon-for-polylang' ), $english_label ),
					/* translators: 1: location 2: language slugs */
					sprintf( __( 'No menu is assigned to "%1$s" for: %2$s.', 'duplicate-content-addon-for-polylang' ), $english_label, implode( ', ', $missing ) )
				)->with_fix( $assign_url, __( 'Manage Menu Locations', 'duplicate-content-addon-for-polylang' ) )
				->with_details( $details );
			} else {
				$results[] = $this->result(
					'menu_' . $location,
					DUPCAP_Result::PASS,
					/* translators: %s: menu location label */
					sprintf( __( '"%s" menu set for all languages', 'duplicate-content-addon-for-polylang' ), $english_label ),
					__( 'A menu is assigned in every language.', 'duplicate-content-addon-for-polylang' )
				);
			}
		}

		$dead_menu_details = $this->collect_dead_menu_item_details( $theme_map );
		if ( ! empty( $dead_menu_details ) ) {
			$results[] = $this->result(
				'menu_dead_links',
				DUPCAP_Result::WARNING,
				count( $dead_menu_details ) === 1
					? __( '1 menu link points to a missing page', 'duplicate-content-addon-for-polylang' )
					: sprintf(
						/* translators: %d: link count */
						__( '%d menu links point to missing pages', 'duplicate-content-addon-for-polylang' ),
						count( $dead_menu_details )
					),
				__( 'Some menu items link to URLs with no published page. Visitors who click them will see a page-not-found error.', 'duplicate-content-addon-for-polylang' )
			)->with_fix( admin_url( 'nav-menus.php' ), __( 'Edit menus', 'duplicate-content-addon-for-polylang' ) )
			->with_details( $dead_menu_details );
		}

		return $results;
	}

	/**
	 * Collect dead custom/post menu item URLs from assigned menus.
	 *
	 * @param array $theme_map Polylang nav_menus map for the current theme.
	 * @param array $languages Active languages.
	 * @return array<int, array<string, mixed>>
	 */
	protected function collect_dead_menu_item_details( array $theme_map ) {
		if ( ! class_exists( 'DUPCAP_Elementor_Fields' ) ) {
			return array();
		}

		$default = $this->default_lang();
		$seen    = array();
		$details = array();
		$limit   = 30;

		foreach ( $theme_map as $location => $by_lang ) {
			if ( ! is_array( $by_lang ) ) {
				continue;
			}
			foreach ( $by_lang as $slug => $menu_id ) {
				$menu_id = (int) $menu_id;
				if ( $menu_id <= 0 || isset( $seen[ 'menu:' . $menu_id ] ) ) {
					continue;
				}
				$seen[ 'menu:' . $menu_id ] = true;

				$items = wp_get_nav_menu_items( $menu_id );
				if ( ! is_array( $items ) ) {
					continue;
				}

				$lang_label = DUPCAP_Elementor_Fields::language_label( (string) $slug );
				foreach ( $items as $item ) {
					if ( count( $details ) >= $limit || ! ( $item instanceof WP_Post ) ) {
						break 3;
					}

					$url   = isset( $item->url ) ? trim( (string) $item->url ) : '';
					$label = isset( $item->title ) ? trim( (string) $item->title ) : '';
					$type  = isset( $item->type ) ? (string) $item->type : '';

					if ( '' !== $url && 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
						$url = home_url( $url );
					}

					if ( 'post_type' === $type && ! empty( $item->object_id ) ) {
						$target = get_post( (int) $item->object_id );
						if ( $target instanceof WP_Post
							&& 'publish' === $target->post_status
							&& '' === (string) $target->post_password ) {
							continue;
						}
					} elseif ( 'taxonomy' === $type && ! empty( $item->object_id ) ) {
						$term = get_term( (int) $item->object_id );
						if ( $term instanceof WP_Term && ! is_wp_error( $term ) ) {
							continue;
						}
					} elseif ( 'post_type_archive' === $type ) {
						continue;
					}

					if ( '' === $url || ! DUPCAP_Elementor_Fields::is_internal_url( $url ) ) {
						continue;
					}
					if ( DUPCAP_Elementor_Fields::url_has_published_target( $url, $default ) ) {
						continue;
					}

					$norm = DUPCAP_Elementor_Fields::normalize_url_for_compare( $url );
					$key  = $menu_id . '|' . $norm;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;

					$ignore_key = DUPCAP_Ignored_Issues::menu_dead_key( $menu_id, $norm );
					if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
						continue;
					}

					$path   = DUPCAP_Elementor_Fields::relative_path( $url );
					$label_url = DUPCAP_Elementor_Fields::readable_url( $url ) ?: ( $path ? $path : $url );
					$target = DUPCAP_Elementor_Fields::describe_dead_link_target( $url, $default );
					$kind   = isset( $target['kind'] ) ? (string) $target['kind'] : 'missing';
					$details[] = array(
						'label'        => implode(
							' → ',
							array_filter(
								array(
									$lang_label,
									$label ? $label : __( 'Menu item', 'duplicate-content-addon-for-polylang' ),
								)
							)
						),
						'message'      => DUPCAP_Elementor_Fields::format_dead_link_detail( $label_url, $kind ),
						'code'         => 'dead_link',
						'language'     => (string) $slug,
						'location'     => (string) $location,
						'url'            => $url,
						'menu_id'        => $menu_id,
						'post_id'        => isset( $target['post_id'] ) ? (int) $target['post_id'] : 0,
						'post_status'    => isset( $target['status'] ) ? (string) $target['status'] : '',
						'kind'           => $kind,
						'ignore_key'     => $ignore_key,
						'action_url'     => admin_url( 'nav-menus.php?action=edit&menu=' . $menu_id ),
						'action_label'   => __( 'Edit menu', 'duplicate-content-addon-for-polylang' ),
						'page_fix_url'   => isset( $target['fix_url'] ) ? (string) $target['fix_url'] : '',
						'page_fix_label' => isset( $target['fix_label'] ) ? (string) $target['fix_label'] : '',
					);
				}
			}
		}

		return $details;
	}

	/**
	 * Run block / FSE navigation checks for block themes.
	 *
	 * @param array $languages Active languages.
	 * @return DUPCAP_Result[]
	 */
	protected function run_block_navigation( array $languages ) {
		$scanner      = new DUPCAP_Block_Navigation();
		$default_lang = $this->default_lang();
		$data         = $scanner->scan( $languages, $default_lang );
		$site_editor  = admin_url( 'site-editor.php' );
		$results      = array();

		$this->group = 'Navigation';

		$language_details = array();
		foreach ( $data['language_status'] as $slug => $status ) {
			$language_details[] = array(
				'label'        => $status['label'],
				'message'      => $status['message'],
				'severity'     => $status['severity'],
				'language'     => $slug,
				'action_url'   => $site_editor,
				'action_label' => __( 'Open Site Editor', 'duplicate-content-addon-for-polylang' ),
			);
		}

		$link_details = array();
		foreach ( $data['wrong_language_links'] as $issue ) {
			$code = isset( $issue['code'] ) ? (string) $issue['code'] : 'wrong_language_link';
			$link_details[] = array(
				'label'        => isset( $issue['label'] ) ? (string) $issue['label'] : '',
				'message'      => isset( $issue['message'] ) ? (string) $issue['message'] : '',
				'context'      => isset( $issue['context'] ) ? (string) $issue['context'] : '',
				'code'         => $code,
				'language'     => isset( $issue['language'] ) ? (string) $issue['language'] : '',
				'action_url'   => $site_editor,
				'action_label' => __( 'Review Navigation', 'duplicate-content-addon-for-polylang' ),
			);
		}

		$missing_translations = isset( $data['missing_translations'] ) ? (array) $data['missing_translations'] : array();
		$uses_other_language  = isset( $data['uses_other_language'] ) ? (array) $data['uses_other_language'] : array();
		$wrong_link_count     = count( $link_details );
		$found_navigation     = ! empty( $data['found_any_navigation'] );
		$nav_post_count       = isset( $data['navigation_post_count'] ) ? (int) $data['navigation_post_count'] : 0;

		if ( $wrong_link_count > 0 ) {
			$has_dead = false;
			foreach ( $link_details as $row ) {
				if ( isset( $row['code'] ) && 'dead_link' === $row['code'] ) {
					$has_dead = true;
					break;
				}
			}

			$results[] = $this->result(
				'nav_wrong_language_links',
				DUPCAP_Result::WARNING,
				$wrong_link_count === 1
					? __( '1 navigation link needs review', 'duplicate-content-addon-for-polylang' )
					: sprintf(
						/* translators: %d: link count */
						__( '%d navigation links need review', 'duplicate-content-addon-for-polylang' ),
						$wrong_link_count
					),
				$has_dead
					? __( 'Some navigation links point to missing pages or content in another language.', 'duplicate-content-addon-for-polylang' )
					: __( 'Some navigation links point to content in another language.', 'duplicate-content-addon-for-polylang' )
			)->with_fix( $site_editor, __( 'Review Navigation', 'duplicate-content-addon-for-polylang' ) )
			->with_details( array_merge( $language_details, $link_details ) );
			return $results;
		}

		if ( ! empty( $uses_other_language ) ) {
			$issue_count = count( $uses_other_language );
			$results[] = $this->result(
				'nav_uses_other_language',
				DUPCAP_Result::WARNING,
				$issue_count === 1
					? __( '1 language uses another language\'s navigation', 'duplicate-content-addon-for-polylang' )
					: sprintf(
						/* translators: %d: language count */
						__( '%d languages use another language\'s navigation', 'duplicate-content-addon-for-polylang' ),
						$issue_count
					),
				__( 'Some language versions of the header still point at the default-language navigation menu. Visitors can see English menu labels on translated pages.', 'duplicate-content-addon-for-polylang' )
			)->with_fix( $site_editor, __( 'Review Site Editor', 'duplicate-content-addon-for-polylang' ) )
			->with_details( $language_details );
			return $results;
		}

		if ( ! empty( $missing_translations ) ) {
			$missing_count = count( $missing_translations );
			$results[] = $this->result(
				'nav_missing_translation',
				DUPCAP_Result::WARNING,
				$missing_count === 1
					? __( '1 navigation translation needs review', 'duplicate-content-addon-for-polylang' )
					: sprintf(
						/* translators: %d: language count */
						__( '%d navigation translations need review', 'duplicate-content-addon-for-polylang' ),
						$missing_count
					),
				__( 'A translated header or navigation was not found for every language. Visitors may see navigation from another language if no language-specific version is configured.', 'duplicate-content-addon-for-polylang' )
			)->with_fix( $site_editor, __( 'Review Site Editor', 'duplicate-content-addon-for-polylang' ) )
			->with_details( $language_details );
			return $results;
		}

		if ( $found_navigation ) {
			$has_warnings = false;
			foreach ( $data['language_status'] as $status ) {
				if ( isset( $status['severity'] ) && 'warn' === $status['severity'] ) {
					$has_warnings = true;
					break;
				}
			}

			if ( $has_warnings ) {
				$results[] = $this->result(
					'nav_fse_review',
					DUPCAP_Result::WARNING,
					__( 'Navigation needs review', 'duplicate-content-addon-for-polylang' ),
					__( 'Block navigation was found, but not every language has its own translated header or navigation menu.', 'duplicate-content-addon-for-polylang' )
				)->with_fix( $site_editor, __( 'Open Site Editor', 'duplicate-content-addon-for-polylang' ) )
				->with_details( $language_details );
				return $results;
			}

			$message = __( 'Block theme detected. Language-specific header navigation was found and checked for active languages.', 'duplicate-content-addon-for-polylang' );
			if ( $nav_post_count > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: navigation entity count */
					_n( '%d navigation entity found.', '%d navigation entities found.', $nav_post_count, 'duplicate-content-addon-for-polylang' ),
					$nav_post_count
				);
			}

			$results[] = $this->result(
				'nav_fse_ok',
				DUPCAP_Result::PASS,
				__( 'Navigation looks good', 'duplicate-content-addon-for-polylang' ),
				$message
			)->with_details( $language_details );
			return $results;
		}

		$results[] = $this->result(
			'nav_fse_review',
			DUPCAP_Result::INFO,
			__( 'Block navigation needs review', 'duplicate-content-addon-for-polylang' ),
			__( 'Block theme detected. This site uses block-based navigation instead of traditional menu locations. Classic menu checks do not apply here. Review the site Header and Navigation in the Site Editor.', 'duplicate-content-addon-for-polylang' )
		)->with_fix( $site_editor, __( 'Open Site Editor', 'duplicate-content-addon-for-polylang' ) )
		->with_details( $language_details );

		return $results;
	}

}