<?php
/**
 * Bridges DUPCAP_Scanner results to the dashboard view model.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Dashboard_Adapter {

	/**
	 * Register filter hooks.
	 */
	public static function init() {
		add_filter( 'dupcap_dashboard_results', array( __CLASS__, 'provide_scan_results' ), 10 );
		add_filter( 'dupcap_dashboard_environment', array( __CLASS__, 'provide_environment' ), 10 );
		add_filter( 'dupcap_dashboard_language_coverage', array( __CLASS__, 'provide_language_coverage' ), 10, 2 );
	}

	/**
	 * Run scanner and map results for the dashboard.
	 *
	 * @param array $results Existing filter value.
	 * @return array
	 */
	public static function provide_scan_results( $results ) {
		if ( ! empty( $results ) ) {
			return $results;
		}

		$force = self::should_force_rescan();

		if ( ! $force ) {
			$saved = DUPCAP_Scanner::get_saved_report();
			if ( is_array( $saved ) && ! empty( $saved['results'] ) && is_array( $saved['results'] ) ) {
				return self::map_report_results( $saved['results'] );
			}

			if ( ! self::should_run_automatic_initial_scan() ) {
				return array();
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Full site scan may exceed default PHP timeout.
			@set_time_limit( 300 );
		}

		// Elementor first so a main-scan timeout cannot leave a stale Elementor report.
		if ( class_exists( 'DUPCAP_Elementor_Scanner' ) && DUPCAP_Elementor_Scanner::is_elementor_available() ) {
			try {
				DUPCAP_Elementor_Scanner::save_report( ( new DUPCAP_Elementor_Scanner() )->scan() );
			} catch ( Throwable $e ) {
				// Keep the main scan running if Elementor scanning fails.
			}
		}

		$report = DUPCAP_Scanner::save_report( ( new DUPCAP_Scanner() )->scan() );

		return self::map_report_results( isset( $report['results'] ) ? $report['results'] : array() );
	}

	/**
	 * Whether this request should run a fresh scan.
	 *
	 * @return bool
	 */
	protected static function should_force_rescan() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// dupcap_scan_routing is deliberately absent: it merges its rows into the
		// saved report itself. Forcing a rescan here would also re-run the
		// Elementor deep scan on every URL sweep.
		// dupcap_ignore_issue is omitted too: ignored rows are dropped when the
		// saved report is mapped, so Ignore stays instant and does not re-inject
		// unfiltered routing results.
		if ( in_array( $action, array( 'dupcap_rescan_dashboard' ), true ) ) {
			return true;
		}

		/**
		 * Force a fresh Translation Inspector scan.
		 *
		 * @param bool $force Whether to rescan now.
		 */
		return (bool) apply_filters( 'dupcap_force_rescan', false );
	}

	/**
	 * Whether to run a scan when no saved report exists (initial page load).
	 *
	 * The first scan must wait for Scan Website. After that, page loads
	 * reuse the saved report until the user scans again.
	 *
	 * @return bool
	 */
	protected static function should_run_automatic_initial_scan() {
		return false;
	}

	/**
	 * Map saved/fresh scanner result rows to dashboard records.
	 *
	 * @param array $rows Scanner result arrays.
	 * @return array
	 */
	protected static function map_report_results( array $rows ) {
		if ( class_exists( 'DUPCAP_Routing_Scanner' ) ) {
			$rows = DUPCAP_Routing_Scanner::apply_ignores_to_results( $rows );
		}

		$mapped = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$mapped[] = self::map_result( self::drop_ignored_details( self::drop_ignored_menu_languages( $row ) ) );
		}

		// Fix links are stored without a usable nonce: sign them for the current
		// user in this request so a report saved yesterday still opens today.
		return DUPCAP_Action_Links::refresh_deep( $mapped );
	}

	/**
	 * Remove detail rows the user has ignored.
	 *
	 * When every row in a grouped result is ignored, the result is treated as a
	 * pass so it no longer appears in the warning list.
	 *
	 * @param array $row Scanner result.
	 * @return array
	 */
	protected static function drop_ignored_details( array $row ) {
		if ( ! class_exists( 'DUPCAP_Ignored_Issues' ) || empty( $row['details'] ) || ! is_array( $row['details'] ) ) {
			return $row;
		}

		$id   = isset( $row['id'] ) ? (string) $row['id'] : '';
		$kept = array();
		foreach ( $row['details'] as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}
			$key = isset( $detail['ignore_key'] ) ? (string) $detail['ignore_key'] : self::fallback_ignore_key( $id, $detail );
			if ( '' !== $key && DUPCAP_Ignored_Issues::is_ignored( $key ) ) {
				continue;
			}
			$kept[] = $detail;
		}

		if ( count( $kept ) === count( $row['details'] ) ) {
			return $row;
		}

		$row['details'] = array_values( $kept );
		if ( empty( $row['details'] ) ) {
			$row['status'] = 'pass';
		}

		return $row;
	}

	/**
	 * Ignore key for a saved detail row that does not carry one.
	 *
	 * Reports written before every check stored an ignore key still have to
	 * honour a dismissal, so the key is rebuilt from the row shape.
	 *
	 * @param string $id     Scanner result id.
	 * @param array  $detail Detail row.
	 * @return string
	 */
	protected static function fallback_ignore_key( $id, array $detail ) {
		$lang      = isset( $detail['language'] ) ? (string) $detail['language'] : '';
		$object_id = isset( $detail['post_id'] ) ? (int) $detail['post_id'] : 0;

		if ( '' === $lang ) {
			return '';
		}

		if ( 'lang_homepage' === $id ) {
			return DUPCAP_Ignored_Issues::homepage_key( $lang, isset( $detail['issue'] ) ? (string) $detail['issue'] : 'missing' );
		}

		if ( $object_id <= 0 ) {
			return '';
		}

		if ( 0 === strpos( $id, 'term_missing_' ) ) {
			return DUPCAP_Ignored_Issues::term_gap_key( $object_id, isset( $detail['post_type'] ) ? (string) $detail['post_type'] : '', $lang );
		}

		if ( 0 === strpos( $id, 'content_missing_' ) ) {
			return DUPCAP_Ignored_Issues::content_gap_key( $object_id, $lang, 'missing' );
		}

		if ( 0 === strpos( $id, 'content_draft_' ) ) {
			return DUPCAP_Ignored_Issues::content_gap_key( $object_id, $lang, 'draft' );
		}

		return '';
	}

	/**
	 * Drop ignored languages from menu assignment rows.
	 *
	 * One saved row can list several languages for the same theme location, so
	 * the ignored ones are pruned from the row instead of dropping it whole.
	 *
	 * @param array $row Scanner result.
	 * @return array
	 */
	protected static function drop_ignored_menu_languages( array $row ) {
		$id = isset( $row['id'] ) ? (string) $row['id'] : '';
		if ( ! class_exists( 'DUPCAP_Ignored_Issues' ) || 0 !== strpos( $id, 'menu_' ) ) {
			return $row;
		}
		if ( empty( $row['details'] ) || ! is_array( $row['details'] ) ) {
			return $row;
		}

		$kept    = array();
		$changed = false;

		foreach ( $row['details'] as $detail ) {
			if ( ! is_array( $detail ) || empty( $detail['location'] ) || empty( $detail['missing'] ) || ! is_array( $detail['missing'] ) ) {
				$kept[] = $detail;
				continue;
			}

			$location = (string) $detail['location'];
			$missing  = array();
			$slugs    = array();

			foreach ( $detail['missing'] as $language ) {
				if ( ! is_array( $language ) || empty( $language['slug'] ) ) {
					continue;
				}
				$slug = (string) $language['slug'];
				if ( DUPCAP_Ignored_Issues::is_ignored( DUPCAP_Ignored_Issues::menu_key( $location, $slug ) ) ) {
					$changed = true;
					continue;
				}
				$missing[] = $language;
				$slugs[]   = $slug;
			}

			if ( empty( $missing ) ) {
				$changed = true;
				continue;
			}

			$detail['missing']           = $missing;
			$detail['missing_languages'] = $slugs;
			$kept[]                      = $detail;
		}

		if ( ! $changed ) {
			return $row;
		}

		$row['details'] = array_values( $kept );
		if ( empty( $row['details'] ) ) {
			$row['status'] = 'pass';
		}

		return $row;
	}

	/**
	 * Map one scanner result to dashboard record shape.
	 *
	 * @param array $row Scanner result.
	 * @return array
	 */
	protected static function map_result( array $row ) {
		$id     = isset( $row['id'] ) ? (string) $row['id'] : '';
		$status = isset( $row['status'] ) ? (string) $row['status'] : 'pass';

		$severity = 'pass';
		if ( 'critical' === $status ) {
			$severity = 'crit';
		} elseif ( 'warning' === $status ) {
			$severity = 'warn';
		} elseif ( 'info' === $status ) {
			$severity = 'info';
		}

		$message = isset( $row['message'] ) ? (string) $row['message'] : '';
		if ( ! empty( $row['recently_fixed'] ) ) {
			$message .= ' ' . __( '(Recently fixed)', 'duplicate-content-addon-for-polylang' );
		}

		$dedupe_key         = self::dedupe_key_for( $id );
		$affected_languages = self::affected_languages_for( $id, $message, $row );
		$details            = ! empty( $row['details'] ) && is_array( $row['details'] )
			? $row['details']
			: self::details_for( $row, $dedupe_key, $affected_languages );

		return array(
			'severity'           => $severity,
			'group'              => isset( $row['group'] ) ? (string) $row['group'] : '',
			'code'               => $id,
			'dedupe_key'         => $dedupe_key,
			'title'              => isset( $row['label'] ) ? (string) $row['label'] : '',
			'message'            => trim( $message ),
			'affected_languages' => $affected_languages,
			'action_url'         => isset( $row['fix_url'] ) ? (string) $row['fix_url'] : '',
			'action_label'       => isset( $row['fix_label'] ) ? (string) $row['fix_label'] : '',
			'details'            => $details,
			'details_truncated'  => ! empty( $row['details_truncated'] ),
		);
	}

	/**
	 * Resolve stable grouping key from check id.
	 *
	 * Public so section scans can route raw report rows to the same dashboard
	 * section the card renders in.
	 *
	 * @param string $id Check id.
	 * @return string
	 */
	public static function dedupe_key_for( $id ) {
		if ( 'lang_homepage' === $id ) {
			return 'homepage_translations';
		}
		if ( 'lang_untranslated_types' === $id ) {
			return 'untranslated_types';
		}
		if ( 0 === strpos( $id, 'content_nolang_' ) || 0 === strpos( $id, 'term_nolang_' ) ) {
			return 'content_nolang';
		}
		if ( 0 === strpos( $id, 'content_missing_' ) ) {
			return 'content_missing';
		}
		if ( 0 === strpos( $id, 'content_identical_' ) ) {
			return 'content_identical';
		}
		if ( 0 === strpos( $id, 'content_link_' ) ) {
			return 'content_links';
		}
		if ( 0 === strpos( $id, 'content_draft_' ) ) {
			return 'content_draft';
		}
		if ( 'content_source_updated' === $id ) {
			return 'content_source_updated';
		}
		if ( 0 === strpos( $id, 'term_missing_' ) ) {
			return 'term_missing';
		}
		if ( 0 === strpos( $id, 'nav_' ) ) {
			return 'navigation';
		}
		if ( 'menu_dead_links' === $id ) {
			return 'menu_dead_links';
		}
		if ( 0 === strpos( $id, 'menu_' ) && 'menu_none' !== $id ) {
			return 'menu_assignments';
		}
		if ( 'elementor_not_translated' === $id || 0 === strpos( $id, 'elementor_' ) ) {
			if ( 'elementor_none' === $id ) {
				return $id;
			}
			return 'elementor_templates';
		}
		if ( 0 === strpos( $id, 'string_' ) ) {
			return 'strings_missing';
		}
		if ( 0 === strpos( $id, 'translated_permalink_' ) || 'url_config_slug_suffix' === $id ) {
			return 'translated_permalinks';
		}
		if ( 0 === strpos( $id, 'url_config_' ) ) {
			return 'url_configuration';
		}
		if ( 0 === strpos( $id, 'url_route_' ) ) {
			return 'routing_health';
		}

		return sanitize_key( $id );
	}

	/**
	 * Extract language slugs from check id or message.
	 *
	 * @param string $id      Check id.
	 * @param string $message Message text.
	 * @param array  $row     Scanner row.
	 * @return array
	 */
	protected static function affected_languages_for( $id, $message, array $row = array() ) {
		if ( 'lang_homepage' === $id ) {
			$languages = array();
			if ( ! empty( $row['details'] ) && is_array( $row['details'] ) ) {
				foreach ( $row['details'] as $detail ) {
					if ( ! empty( $detail['language'] ) ) {
						$languages[] = sanitize_key( (string) $detail['language'] );
					}
				}
			}
			if ( ! empty( $languages ) ) {
				return array_values( array_unique( array_filter( $languages ) ) );
			}
		}
		if ( preg_match( '/^content_missing_(.+)$/', $id, $matches ) ) {
			return array( $matches[1] );
		}
		if ( preg_match( '/^content_identical_(.+)$/', $id, $matches ) ) {
			return array( $matches[1] );
		}
		if ( preg_match( '/^content_link_(.+)$/', $id, $matches ) ) {
			return array( $matches[1] );
		}
		if ( preg_match( '/^content_draft_(.+)$/', $id, $matches ) ) {
			return array( $matches[1] );
		}
		if ( 'content_source_updated' === $id ) {
			$languages = array();
			if ( ! empty( $row['details'] ) && is_array( $row['details'] ) ) {
				foreach ( $row['details'] as $detail ) {
					if ( ! empty( $detail['language'] ) ) {
						$languages[] = sanitize_key( (string) $detail['language'] );
					}
				}
			}
			return array_values( array_unique( array_filter( $languages ) ) );
		}
		if ( preg_match( '/^term_missing_(.+)$/', $id, $matches ) ) {
			return array( $matches[1] );
		}
		if ( preg_match( '/^nav_(missing|links)_(.+)$/', $id, $matches ) ) {
			return array( $matches[2] );
		}
		if ( 'nav_missing_translation' === $id || 'nav_wrong_language_links' === $id || 'nav_uses_other_language' === $id ) {
			$languages = array();
			if ( ! empty( $row['details'] ) && is_array( $row['details'] ) ) {
				foreach ( $row['details'] as $detail ) {
					if ( ! empty( $detail['language'] ) ) {
						$languages[] = sanitize_key( (string) $detail['language'] );
					}
				}
			}
			return array_values( array_unique( array_filter( $languages ) ) );
		}
		if ( preg_match( '/^string_(.+)$/', $id, $matches ) ) {
			return array( $matches[1] );
		}
		// elementor_{type}_{lang} e.g. elementor_page_de, elementor_loop-item_hi
		if ( preg_match( '/^elementor_(.+)_([a-z]{2}(?:-[a-z]{2})?)$/i', $id, $matches ) ) {
			return array( sanitize_key( $matches[2] ) );
		}
		if ( 0 === strpos( $id, 'elementor_' ) && ! empty( $row['details'] ) && is_array( $row['details'] ) ) {
			$languages = array();
			foreach ( $row['details'] as $detail ) {
				if ( ! empty( $detail['language'] ) ) {
					$languages[] = sanitize_key( (string) $detail['language'] );
				}
			}
			if ( ! empty( $languages ) ) {
				return array_values( array_unique( array_filter( $languages ) ) );
			}
		}
		if ( preg_match( '/^url_route_(.+)$/', $id, $matches ) ) {
			// The two guard results are site-wide, not per-language. Without this
			// they would be reported as languages named "untestable"/"site_gated".
			if ( in_array( $matches[1], array( 'untestable', 'site_gated' ), true ) ) {
				return array();
			}
			return array( $matches[1] );
		}
		if ( 0 === strpos( $id, 'translated_permalink_' ) || 'url_config_slug_suffix' === $id ) {
			$languages = array();
			if ( ! empty( $row['details'] ) && is_array( $row['details'] ) ) {
				foreach ( $row['details'] as $detail ) {
					if ( ! empty( $detail['language'] ) ) {
						$languages[] = sanitize_key( (string) $detail['language'] );
					}
				}
			}
			return array_values( array_unique( array_filter( $languages ) ) );
		}
		if ( 0 === strpos( $id, 'url_config_' ) ) {
			// URL configuration findings are site-wide, not per-language.
			return array();
		}
		if ( preg_match( '/for:\s*([a-z0-9_,\s-]+)\.?$/i', $message, $matches ) ) {
			$parts = array_map( 'trim', explode( ',', $matches[1] ) );
			return array_values( array_filter( $parts ) );
		}
		if ( preg_match_all( '/in:\s*([a-z0-9_,\s-]+?)(?:\.|\s*$)/i', $message, $matches ) ) {
			$langs = array();
			foreach ( $matches[1] as $segment ) {
				$parts = array_map( 'trim', explode( ',', (string) $segment ) );
				$langs   = array_merge( $langs, array_filter( $parts ) );
			}
			return array_values( array_unique( $langs ) );
		}

		return array();
	}

	/**
	 * Build optional detail rows for grouped cards.
	 *
	 * @param array  $row                Scanner row.
	 * @param string $dedupe_key         Group key.
	 * @param array  $affected_languages Languages.
	 * @return array
	 */
	protected static function details_for( array $row, $dedupe_key, array $affected_languages ) {
		$label = isset( $row['label'] ) ? (string) $row['label'] : '';
		$message = isset( $row['message'] ) ? (string) $row['message'] : '';

		if ( 'menu_assignments' === $dedupe_key ) {
			if ( ! empty( $row['details'] ) && is_array( $row['details'] ) ) {
				return $row['details'];
			}

			$location_label = $label;
			if ( preg_match( '/^"(.+)" menu not set for all languages$/', $label, $matches ) ) {
				$location_label = $matches[1];
			}

			return array(
				array(
					'location_label'    => $location_label,
					'missing_languages' => $affected_languages,
					'languages'         => array(),
					'assign_url'        => isset( $row['fix_url'] ) ? (string) $row['fix_url'] : admin_url( 'nav-menus.php?action=locations' ),
				),
			);
		}

		if ( 'content_missing' === $dedupe_key && ! empty( $affected_languages ) ) {
			return array(
				array(
					'label'        => $label,
					'message'      => $message,
					'action_url'   => isset( $row['fix_url'] ) ? (string) $row['fix_url'] : '',
					'action_label' => isset( $row['fix_label'] ) ? (string) $row['fix_label'] : '',
				),
			);
		}

		if ( 'content_draft' === $dedupe_key && ! empty( $affected_languages ) ) {
			return array(
				array(
					'label'        => $label,
					'message'      => $message,
					'action_url'   => isset( $row['fix_url'] ) ? (string) $row['fix_url'] : '',
					'action_label' => isset( $row['fix_label'] ) ? (string) $row['fix_label'] : '',
				),
			);
		}

		if ( 'term_missing' === $dedupe_key && ! empty( $affected_languages ) ) {
			return array(
				array(
					'label'        => $label,
					'message'      => $message,
					'action_url'   => isset( $row['fix_url'] ) ? (string) $row['fix_url'] : '',
					'action_label' => isset( $row['fix_label'] ) ? (string) $row['fix_label'] : '',
				),
			);
		}

		if ( 'strings_missing' === $dedupe_key && ! empty( $affected_languages ) ) {
			$lang       = $affected_languages[0];
			$lang_name  = class_exists( 'DUPCAP_Elementor_Fields' )
				? DUPCAP_Elementor_Fields::language_label( $lang )
				: strtoupper( $lang );
			$lang_label = ( $lang_name && strtolower( $lang_name ) !== strtolower( $lang ) )
				? sprintf(
					/* translators: 1: language name, 2: language code */
					__( '%1$s (%2$s)', 'duplicate-content-addon-for-polylang' ),
					$lang_name,
					$lang
				)
				: $lang;

			return array(
				array(
					'label'        => sprintf(
						/* translators: 1: language label with code */
						__( '%s strings', 'duplicate-content-addon-for-polylang' ),
						$lang_label
					),
					'message'      => $message,
					'action_url'   => isset( $row['fix_url'] ) ? (string) $row['fix_url'] : '',
					'action_label' => isset( $row['fix_label'] ) ? (string) $row['fix_label'] : '',
				),
			);
		}

		return array();
	}

	/**
	 * Environment summary for the dashboard card.
	 *
	 * @param array $environment Existing value.
	 * @return array
	 */
	public static function provide_environment( $environment ) {
		if ( ! empty( $environment ) && is_array( $environment ) ) {
			return $environment;
		}

		$builder = __( 'Gutenberg (Block Editor)', 'duplicate-content-addon-for-polylang' );
		if (
			class_exists( '\Elementor\Plugin' )
			|| defined( 'ELEMENTOR_VERSION' )
			|| ( function_exists( 'did_action' ) && did_action( 'elementor/loaded' ) )
		) {
			$builder = __( 'Elementor', 'duplicate-content-addon-for-polylang' );
		} elseif ( defined( 'ET_BUILDER_VERSION' ) || class_exists( 'ET_Builder_Plugin' ) || function_exists( 'et_setup_theme' ) ) {
			$builder = __( 'Divi', 'duplicate-content-addon-for-polylang' );
		}

		$theme = __( 'Current theme', 'duplicate-content-addon-for-polylang' );
		if ( function_exists( 'wp_get_theme' ) ) {
			$wp_theme = wp_get_theme();
			$theme    = $wp_theme && method_exists( $wp_theme, 'get' ) ? $wp_theme->get( 'Name' ) : $theme;
		}

		$languages = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$pll_languages = pll_languages_list( array( 'fields' => 'slug' ) );
			if ( is_array( $pll_languages ) ) {
				$languages = array_map( 'strtoupper', $pll_languages );
			}
		}

		$default_lang = '';
		if ( function_exists( 'pll_default_language' ) ) {
			$default_lang = strtoupper( (string) pll_default_language( 'slug' ) );
		}

		$rows = array();

		$addons = self::get_active_polylang_addons();
		if ( ! empty( $addons ) ) {
			$rows[ __( 'Polylang Addons', 'duplicate-content-addon-for-polylang' ) ] = $addons;
		}

		$rows[ __( 'Page Builder', 'duplicate-content-addon-for-polylang' ) ]      = $builder;
		$rows[ __( 'Theme', 'duplicate-content-addon-for-polylang' ) ]             = $theme;
		$rows[ __( 'Active Languages', 'duplicate-content-addon-for-polylang' ) ]  = $languages ? implode( ', ', $languages ) : __( 'None', 'duplicate-content-addon-for-polylang' );
		$rows[ __( 'Default Language', 'duplicate-content-addon-for-polylang' ) ]  = $default_lang ? $default_lang : __( 'None', 'duplicate-content-addon-for-polylang' );
		$rows[ __( 'PHP Version', 'duplicate-content-addon-for-polylang' ) ]       = PHP_VERSION;
		$rows[ __( 'WordPress Version', 'duplicate-content-addon-for-polylang' ) ] = get_bloginfo( 'version' );

		return $rows;
	}

	/**
	 * Active Polylang-related add-ons (excludes core Polylang and this plugin).
	 *
	 * @return string[] Friendly plugin names.
	 */
	public static function get_active_polylang_addons() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$known = array(
			'polylang-wc/polylang-wc.php'                                                 => __( 'Polylang for WooCommerce', 'duplicate-content-addon-for-polylang' ),
			'polylang-woocommerce/polylang-wc.php'                                        => __( 'Polylang for WooCommerce', 'duplicate-content-addon-for-polylang' ),
			'lingotek-translation/lingotek.php'                                           => __( 'Lingotek Translation', 'duplicate-content-addon-for-polylang' ),
			'connect-polylang-elementor/connect-polylang-elementor.php'                   => __( 'Connect Polylang for Elementor', 'duplicate-content-addon-for-polylang' ),
			'autopoly-ai-translation-for-polylang/autopoly-ai-translation-for-polylang.php' => __( 'Autopoly AI Translation', 'duplicate-content-addon-for-polylang' ),
			'autopoly-ai-translation-for-polylang-pro/autopoly-ai-translation-for-polylang-pro.php' => __( 'Autopoly AI Translation Pro', 'duplicate-content-addon-for-polylang' ),
			'language-switcher-for-polylang/language-switcher-for-polylang.php'           => __( 'Language Switcher for Polylang', 'duplicate-content-addon-for-polylang' ),
			'language-switcher-for-divi-polylang/language-switcher-for-divi-polylang.php' => __( 'Language Switcher for Polylang', 'duplicate-content-addon-for-polylang' ),
		);

		/**
		 * Map of plugin basename => label for Polylang add-ons shown in Environment.
		 *
		 * @param array<string, string> $known Known add-ons.
		 */
		$known = apply_filters( 'dupcap_polylang_addon_plugins', $known );

		$self_basename = defined( 'DUPCAP_FILE' ) ? plugin_basename( DUPCAP_FILE ) : 'duplicate-content-addon-for-polylang/duplicate-content-addon-for-polylang.php';
		$core_basenames = array(
			'polylang/polylang.php',
			$self_basename,
		);

		$all_plugins = get_plugins();
		$names       = array();
		$seen        = array();

		foreach ( $all_plugins as $basename => $data ) {
			if ( in_array( $basename, $core_basenames, true ) ) {
				continue;
			}
			if ( ! self::is_plugin_currently_active( $basename ) ) {
				continue;
			}

			$slug = strtolower( (string) dirname( $basename ) );
			$is_known = isset( $known[ $basename ] );
			$looks_like_addon = ( false !== strpos( $slug, 'polylang' ) || false !== strpos( $slug, 'pll-' ) );

			if ( ! $is_known && ! $looks_like_addon ) {
				continue;
			}

			$label = $is_known
				? $known[ $basename ]
				: ( ! empty( $data['Name'] ) ? (string) $data['Name'] : $slug );

			$key = strtolower( $label );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$names[]      = $label;
		}

		// Constants when Pro is bundled without a matching basename edge case.
		if ( defined( 'POLYLANG_PRO' ) && POLYLANG_PRO && ! isset( $seen[ strtolower( __( 'Polylang Pro', 'duplicate-content-addon-for-polylang' ) ) ] ) ) {
			array_unshift( $names, __( 'Polylang Pro', 'duplicate-content-addon-for-polylang' ) );
		}

		/**
		 * Filter detected active Polylang add-on labels.
		 *
		 * @param string[] $names Add-on display names.
		 */
		return array_values( apply_filters( 'dupcap_active_polylang_addons', $names ) );
	}

	/**
	 * Whether a plugin is active on this site (or network-activated).
	 *
	 * @param string $basename Plugin basename.
	 * @return bool
	 */
	protected static function is_plugin_currently_active( $basename ) {
		return DUPCAP_Plugin_Status::is_active( $basename );
	}

	/**
	 * Per-language translation coverage percentages.
	 *
	 * Includes all Polylang-translatable post types and taxonomies using
	 * translation links from the default-language catalog. Strings and menus
	 * are tracked by separate checks and are excluded here so percentages
	 * stay aligned with the content-missing table.
	 *
	 * @param array $coverage Existing rows.
	 * @param array $results  Dashboard results (unused; scan runs in provide_scan_results).
	 * @return array
	 */
	public static function provide_language_coverage( $coverage, $results ) {
		unset( $results );

		if ( ! empty( $coverage ) && is_array( $coverage ) ) {
			return $coverage;
		}

		if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_count_posts' ) || ! function_exists( 'pll_default_language' ) ) {
			return array();
		}

		$default    = pll_default_language( 'slug' );
		$languages  = pll_languages_list( array( 'fields' => '' ) );
		$post_types = self::get_translated_post_types();
		$taxonomies = DUPCAP_Check::polylang_translated_taxonomies();

		/**
		 * Filter post types included in translation coverage.
		 *
		 * @param string[] $post_types Post type slugs.
		 */
		$post_types = apply_filters( 'dupcap_coverage_post_types', $post_types );

		/**
		 * Filter taxonomies included in translation coverage.
		 *
		 * @param string[] $taxonomies Taxonomy slugs.
		 */
		$taxonomies = apply_filters( 'dupcap_coverage_taxonomies', $taxonomies );

		$catalog  = self::build_coverage_catalog( $default, $post_types, $taxonomies );
		$expected = (int) $catalog['expected'];

		$rows = array();
		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			$name = is_object( $lang ) ? $lang->name : strtoupper( $slug );

			if ( $slug === $default ) {
				$rows[] = array(
					'language' => $name,
					'slug'     => $slug,
					'coverage' => 100,
				);
				continue;
			}

			$fulfilled = self::count_coverage_fulfilled_for_language( $catalog, $slug );
			$percent   = 0;
			if ( $expected > 0 ) {
				$percent = (int) min( 100, round( ( $fulfilled / $expected ) * 100 ) );
			}

			$rows[] = array(
				'language' => $name,
				'slug'     => $slug,
				'coverage' => $percent,
			);
		}

		/**
		 * Filter per-language coverage rows.
		 *
		 * @param array  $rows       Coverage rows.
		 * @param string $default    Default language slug.
		 * @param array  $languages  Polylang language objects.
		 */
		return apply_filters( 'dupcap_dashboard_language_coverage_rows', $rows, $default, $languages );
	}

	/**
	 * Polylang model instance.
	 *
	 * @return object|null
	 */
	protected static function get_polylang_model() {
		$pll = function_exists( 'PLL' ) ? PLL() : null;
		return ( $pll && isset( $pll->model ) ) ? $pll->model : null;
	}

	/**
	 * Post types Polylang marks as translatable.
	 *
	 * @return string[]
	 */
	protected static function get_translated_post_types() {
		$model = self::get_polylang_model();
		if ( $model && is_callable( array( $model, 'get_translated_post_types' ) ) ) {
			$types = $model->get_translated_post_types();
			if ( is_array( $types ) ) {
				$filtered = array();
				foreach ( $types as $type ) {
					$is_trans = function_exists( 'pll_is_translated_post_type' )
						? pll_is_translated_post_type( $type )
						: ( $model && method_exists( $model, 'is_translated_post_type' ) ? $model->is_translated_post_type( $type ) : true );
					if ( $is_trans ) {
						$filtered[] = $type;
					}
				}
				return array_values( $filtered );
			}
		}

		$fallback = array();
		foreach ( array( 'post', 'page' ) as $type ) {
			if ( function_exists( 'pll_is_translated_post_type' ) ? pll_is_translated_post_type( $type ) : false ) {
				$fallback[] = $type;
			}
		}
		return $fallback;
	}

	/**
	 * Build the default-language catalog used to measure translation coverage.
	 *
	 * @param string   $default    Default language slug.
	 * @param string[] $post_types Translated post types.
	 * @param string[] $taxonomies Translated taxonomies.
	 * @return array{post_ids:int[],term_ids:int[],expected:int}
	 */
	protected static function build_coverage_catalog( $default, array $post_types, array $taxonomies ) {
		$post_types = self::get_coverage_post_types( $post_types );
		$post_ids   = array();

		foreach ( $post_types as $type ) {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'lang'           => $default,
					'posts_per_page' => -1,
					'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
					'fields'         => 'ids',
				)
			);

			if ( is_array( $ids ) ) {
				foreach ( $ids as $id ) {
					$id = (int) $id;
					if ( DUPCAP_Elementor_Document::is_excluded_library_document( $id ) ) {
						continue;
					}
					$post_ids[] = $id;
				}
			}
		}

		$term_ids = array();
		foreach ( $taxonomies as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'lang'       => $default,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				$term_ids = array_merge( $term_ids, array_map( 'intval', $terms ) );
			}
		}

		$post_ids = array_values( array_unique( $post_ids ) );
		$term_ids = array_values( array_unique( $term_ids ) );

		$expected = count( $post_ids ) + count( $term_ids );

		return array(
			'post_ids' => $post_ids,
			'term_ids' => $term_ids,
			'expected' => $expected,
		);
	}

	/**
	 * Post types included in coverage, with optional Elementor library fallback.
	 *
	 * @param string[] $post_types Base translated post types.
	 * @return string[]
	 */
	protected static function get_coverage_post_types( array $post_types ) {
		if ( in_array( 'elementor_library', $post_types, true ) || ! self::is_elementor_library_translatable() ) {
			return $post_types;
		}

		$post_types[] = 'elementor_library';

		return array_values( array_unique( $post_types ) );
	}

	/**
	 * Whether Elementor library documents are translatable in Polylang.
	 *
	 * @return bool
	 */
	protected static function is_elementor_library_translatable() {
		if ( ! class_exists( 'DUPCAP_Elementor_Scanner' ) || ! DUPCAP_Elementor_Scanner::is_elementor_available() || ! post_type_exists( 'elementor_library' ) ) {
			return false;
		}

		$model = self::get_polylang_model();

		return $model && is_callable( array( $model, 'is_translated_post_type' ) ) && $model->is_translated_post_type( 'elementor_library' );
	}

	/**
	 * Count how many default-language catalog items are fulfilled in a language.
	 *
	 * Uses Polylang translation links for posts and terms instead of raw per-language counts.
	 *
	 * @param array  $catalog   Coverage catalog from build_coverage_catalog().
	 * @param string $lang_slug Target language slug.
	 * @return int
	 */
	protected static function count_coverage_fulfilled_for_language( array $catalog, $lang_slug ) {
		$fulfilled = 0;

		if ( function_exists( 'pll_get_post_translations' ) ) {
			foreach ( $catalog['post_ids'] as $post_id ) {
				$translations = pll_get_post_translations( $post_id );
				if ( ! empty( $translations[ $lang_slug ] ) ) {
					$fulfilled++;
				}
			}
		}

		if ( function_exists( 'pll_get_term_translations' ) ) {
			foreach ( $catalog['term_ids'] as $term_id ) {
				$translations = pll_get_term_translations( $term_id );
				if ( ! empty( $translations[ $lang_slug ] ) ) {
					$fulfilled++;
				}
			}
		}

		return $fulfilled;
	}
}
