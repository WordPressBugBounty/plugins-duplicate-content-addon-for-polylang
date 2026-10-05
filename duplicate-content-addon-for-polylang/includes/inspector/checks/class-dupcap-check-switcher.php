<?php
/**
 * Detect whether the site exposes a Polylang language switcher.
 *
 * Checks common placement locations: classic menus, widgets, Gutenberg/FSE
 * blocks, Elementor widgets, Divi modules, and known floating switchers.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Check_Switcher extends DUPCAP_Check {

	protected $group = 'Language Setup';

	/**
	 * Block names that render a language switcher.
	 *
	 * @var string[]
	 */
	const BLOCK_MARKERS = array(
		'wp:polylang/language-switcher',
		'wp:polylang/navigation-language-switcher',
		'wp:lsdp/language-switcher',
	);

	/**
	 * Elementor widget types that render a language switcher.
	 *
	 * @var string[]
	 */
	const ELEMENTOR_WIDGETS = array(
		'polylang-language-switcher', // Connect Polylang for Elementor.
		'lsep_widget',                // Language Switcher for Polylang (Elementor).
	);

	/**
	 * @return DUPCAP_Result[]
	 */
	public function run() {
		$results   = array();
		$languages = $this->languages();

		if ( count( $languages ) < 2 ) {
			return $results;
		}

		$locations = $this->detect_locations();

		/**
		 * Filter detected language switcher locations (label strings).
		 *
		 * Return a non-empty array to treat a switcher as present.
		 *
		 * @param string[] $locations Detected location labels.
		 */
		$locations = apply_filters( 'dupcap_language_switcher_locations', $locations );
		if ( ! is_array( $locations ) ) {
			$locations = array();
		}
		$locations = array_values( array_unique( array_filter( array_map( 'strval', $locations ) ) ) );

		if ( ! empty( $locations ) ) {
			$results[] = $this->result(
				'lang_switcher',
				DUPCAP_Result::PASS,
				__( 'Language switcher detected', 'duplicate-content-addon-for-polylang' ),
				sprintf(
					/* translators: %s: comma-separated list of locations */
					__( 'Found in: %s.', 'duplicate-content-addon-for-polylang' ),
					implode( ', ', $locations )
				)
			);
			return $results;
		}

		$results[] = $this->result(
			'lang_switcher',
			DUPCAP_Result::WARNING,
			__( 'No language switcher detected', 'duplicate-content-addon-for-polylang' ),
			__( 'Visitors may have no way to change languages. Install Language Switcher for Polylang to add a customizable switcher in Elementor, Gutenberg, Divi, or as a floating button.', 'duplicate-content-addon-for-polylang' )
		);

		$fix = $this->get_lsdp_fix_action();
		if ( $fix ) {
			$results[ count( $results ) - 1 ]->with_fix( $fix['url'], $fix['label'] );
		}

		return $results;
	}

	/**
	 * Fix action promoting Language Switcher for Polylang (CoolPlugins).
	 *
	 * @return array{url: string, label: string}|null
	 */
	protected function get_lsdp_fix_action() {
		$slug = 'language-switcher-for-divi-polylang';

		if ( defined( 'LSDP' ) ) {
			return array(
				'url'   => admin_url( 'admin.php?page=lsdp-get-started' ),
				'label' => __( 'Set up Language Switcher', 'duplicate-content-addon-for-polylang' ),
			);
		}

		$basename = DUPCAP_Plugin_Status::find( $slug );

		if ( '' !== $basename ) {
			return array(
				'url'   => DUPCAP_Plugin_Status::activate_url( $basename ),
				'label' => __( 'Activate Language Switcher for Polylang', 'duplicate-content-addon-for-polylang' ),
			);
		}

		return array(
			'url'   => DUPCAP_Plugin_Status::install_url( $slug ),
			'label' => __( 'Install Language Switcher for Polylang', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/**
	 * Collect human-readable locations where a switcher was found.
	 *
	 * @return string[]
	 */
	protected function detect_locations() {
		$found = array();

		if ( $this->has_menu_switcher() ) {
			$found[] = __( 'Menus', 'duplicate-content-addon-for-polylang' );
		}

		if ( $this->has_widget_switcher() ) {
			$found[] = __( 'Widgets', 'duplicate-content-addon-for-polylang' );
		}

		if ( $this->has_block_switcher() ) {
			$found[] = __( 'Blocks / templates', 'duplicate-content-addon-for-polylang' );
		}

		if ( $this->has_elementor_switcher() ) {
			$found[] = __( 'Elementor', 'duplicate-content-addon-for-polylang' );
		}

		if ( $this->has_divi_switcher() ) {
			$found[] = __( 'Divi', 'duplicate-content-addon-for-polylang' );
		}

		if ( $this->has_floating_switcher() ) {
			$found[] = __( 'Floating switcher', 'duplicate-content-addon-for-polylang' );
		}

		return $found;
	}

	/**
	 * Polylang classic menu items use URL #pll_switcher and/or _pll_menu_item meta.
	 *
	 * @return bool
	 */
	protected function has_menu_switcher() {
		global $wpdb;

		// Bounded diagnostic reads during site scan; results are not cached.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_meta = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = 'nav_menu_item'
					AND p.post_status IN ('publish', 'draft')
				LIMIT 1",
				'_pll_menu_item'
			)
		);

		if ( $by_meta > 0 ) {
			return true;
		}

		$by_url = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s
				WHERE p.post_type = 'nav_menu_item'
					AND p.post_status IN ('publish', 'draft')
				LIMIT 1",
				'_menu_item_url',
				'#pll_switcher'
			)
		);
		// phpcs:enable

		return $by_url > 0;
	}

	/**
	 * Active classic widgets using Polylang's language switcher widget (id_base: polylang).
	 *
	 * @return bool
	 */
	protected function has_widget_switcher() {
		$sidebars = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $sidebars ) ) {
			return false;
		}

		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || 'array_version' === $sidebar_id || ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget_id ) {
				if ( is_string( $widget_id ) && 0 === strpos( $widget_id, 'polylang-' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Gutenberg / FSE / reusable blocks and navigation menus containing switcher blocks.
	 *
	 * @return bool
	 */
	protected function has_block_switcher() {
		global $wpdb;

		$post_types = array( 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'page', 'post' );
		$post_types = array_values( array_filter( $post_types, 'post_type_exists' ) );
		if ( empty( $post_types ) ) {
			return false;
		}

		$like_params = array();
		foreach ( self::BLOCK_MARKERS as $marker ) {
			$like_params[] = '%' . $wpdb->esc_like( $marker ) . '%';
		}

		$type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$like_clause       = implode( ' OR ', array_fill( 0, count( $like_params ), 'post_content LIKE %s' ) );
		$params            = array_merge( $post_types, $like_params );

		/*
		* IN and OR lists use only hardcoded "%s" tokens; values are bound via prepare().
		* phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		*/
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_status = 'publish'
				AND post_type IN ({$type_placeholders})
				AND ( {$like_clause} )
			LIMIT 1",
			$params
		);

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $like_clause uses hardcoded %s tokens; values bound via prepare().
		$found = $wpdb->get_var( $sql );
		// phpcs:enable

		return (int) $found > 0;
	}

	/**
	 * Elementor documents that include a known language switcher widget.
	 *
	 * Requires the widget in published `_elementor_data` (not only stale
	 * `_elementor_controls_usage`, which can linger after a widget is removed).
	 *
	 * @return bool
	 */
	protected function has_elementor_switcher() {
		global $wpdb;

		if ( ! post_type_exists( 'elementor_library' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			if ( ! $this->site_has_elementor_meta() ) {
				return false;
			}
		}

		// Bounded diagnostic reads during site scan; results are not cached.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( self::ELEMENTOR_WIDGETS as $widget ) {
			$needle = '"widgetType":"' . $widget . '"';
			$found  = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.post_id
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = '_elementor_data'
						AND pm.meta_value LIKE %s
						AND p.post_status = 'publish'
					LIMIT 1",
					'%' . $wpdb->esc_like( $needle ) . '%'
				)
			);
			if ( $found > 0 ) {
				// phpcs:enable
				return true;
			}
		}
		// phpcs:enable

		return false;
	}

	/**
	 * @return bool
	 */
	protected function site_has_elementor_meta() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded diagnostic read for Elementor meta.
		return (int) $wpdb->get_var(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_edit_mode' LIMIT 1"
		) > 0;
	}

	/**
	 * Divi Language Switcher module in published post content.
	 *
	 * Avoids matching the plugin slug in unrelated Theme Builder / conditions meta.
	 *
	 * @return bool
	 */
	protected function has_divi_switcher() {
		global $wpdb;

		$markers = array(
			'et_pb_lsdp_module',
			'"slug":"language-switcher-for-divi-polylang"',
			'[et_pb_lsdp',
			'language-switcher-for-divi-polylang',
		);

		$like_params = array();
		foreach ( $markers as $marker ) {
			$like_params[] = '%' . $wpdb->esc_like( $marker ) . '%';
		}

		$like_clause = implode( ' OR ', array_fill( 0, count( $like_params ), 'post_content LIKE %s' ) );

		/*
		* OR list uses only hardcoded "%s" tokens; values are bound via prepare().
		* phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		*/
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_status = 'publish'
				AND ( {$like_clause} )
			LIMIT 1",
			$like_params
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded diagnostic read for Divi Language Switcher module.
		$found = (int) $wpdb->get_var( $sql );
		// phpcs:enable

		return $found > 0;
	}

	/**
	 * Floating switcher from Language Switcher for Polylang addon.
	 *
	 * Requires the addon to be active. An orphaned enabled option after
	 * deactivate must not count as a live switcher on the front end.
	 *
	 * @return bool
	 */
	protected function has_floating_switcher() {
		$state = DUPCAP_Plugin_Status::state( 'language-switcher-for-divi-polylang', 'LSDP' );
		if ( 'active' !== $state['status'] ) {
			return false;
		}

		$config = get_option( 'lsdp_floating_switcher_config', null );
		if ( ! is_array( $config ) ) {
			return false;
		}

		// Accept only an explicit enabled flag (true / 1 / '1' / 'true').
		if ( ! array_key_exists( 'enabled', $config ) ) {
			return false;
		}

		$enabled = $config['enabled'];
		return ( true === $enabled || 1 === $enabled || '1' === $enabled || 'true' === $enabled );
	}
}
