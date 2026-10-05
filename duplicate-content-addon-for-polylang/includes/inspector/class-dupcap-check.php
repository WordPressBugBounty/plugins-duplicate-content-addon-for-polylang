<?php
/**
 * Base class for all checks. Provides safe access to Polylang internals
 * so individual checks stay short and readable.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

abstract class DUPCAP_Check {

	/**
	 * Category label shown in the UI (e.g. "Language Setup").
	 *
	 * @var string
	 */
	protected $group = '';

	/**
	 * Run the check.
	 *
	 * @return DUPCAP_Result[]
	 */
	abstract public function run();

	/* ---------- Shared Polylang helpers ---------- */

	/**
	 * Polylang model. Returns null if not available.
	 *
	 * @return object|null
	 */
	protected function model() {
		$pll = function_exists( 'PLL' ) ? PLL() : null;
		return ( $pll && isset( $pll->model ) ) ? $pll->model : null;
	}

	/**
	 * Read a Polylang option safely.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected function option( $key, $default = null ) {
		$pll = function_exists( 'PLL' ) ? PLL() : null;
		if ( ! $pll || ! isset( $pll->options ) ) {
			return $default;
		}
		// Newer Polylang uses an Options object with ->get(); older used array access.
		if ( is_object( $pll->options ) && method_exists( $pll->options, 'get' ) ) {
			$val = $pll->options->get( $key );
			return ( null === $val ) ? $default : $val;
		}
		if ( is_array( $pll->options ) && isset( $pll->options[ $key ] ) ) {
			return $pll->options[ $key ];
		}
		return $default;
	}

	/**
	 * All language objects.
	 *
	 * @return array
	 */
	protected function languages() {
		$model = $this->model();

		// is_callable(), not method_exists(): Polylang 3.8 forwards this through
		// PLL_Model::__call(), which method_exists() cannot see.
		if ( $model && is_callable( array( $model, 'get_languages_list' ) ) ) {
			try {
				$list = $model->get_languages_list();
				if ( is_array( $list ) && ! empty( $list ) ) {
					return $list;
				}
			} catch ( \Throwable $e ) {
				// Fall through to the public API below.
				$list = array();
			}
		}
		// Fallback to public API.
		if ( function_exists( 'pll_languages_list' ) ) {
			return (array) pll_languages_list( array( 'fields' => '' ) );
		}
		return array();
	}

	/**
	 * Language objects, excluding languages the site owner deactivated.
	 *
	 * Reporting a missing translation or a 404 for a deliberately deactivated
	 * language is a false positive. Every check that loops over languages
	 * should use this instead of languages().
	 *
	 * PLL_Language::$active exists since Polylang 3.6, so no fallback is needed.
	 *
	 * @since 2.1.0
	 *
	 * @return array
	 */
	protected function active_languages() {
		$active = array();

		foreach ( $this->languages() as $lang ) {
			if ( is_object( $lang ) && isset( $lang->active ) && ! $lang->active ) {
				continue;
			}
			$active[] = $lang;
		}

		return $active;
	}

	/**
	 * Slug for a language, whether it arrived as an object or a bare slug.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $lang Language object or slug.
	 * @return string
	 */
	protected function lang_slug( $lang ) {
		return is_object( $lang ) ? (string) $lang->slug : (string) $lang;
	}

	/**
	 * Human label for a language, e.g. "French (fr)".
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $lang Language object or slug.
	 * @return string
	 */
	protected function lang_label( $lang ) {
		$slug = $this->lang_slug( $lang );
		$name = ( is_object( $lang ) && ! empty( $lang->name ) ) ? (string) $lang->name : $slug;

		return sprintf(
			/* translators: 1: language name, 2: language code */
			__( '%1$s (%2$s)', 'duplicate-content-addon-for-polylang' ),
			$name,
			$slug
		);
	}

	/**
	 * Default language slug.
	 *
	 * @return string
	 */
	protected function default_lang() {
		if ( function_exists( 'pll_default_language' ) ) {
			return (string) pll_default_language( 'slug' );
		}
		return (string) $this->option( 'default_lang', '' );
	}

	/**
	 * Whether a post type is configured as translatable in Polylang settings.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	protected function is_translated_post_type( $post_type ) {
		if ( function_exists( 'pll_is_translated_post_type' ) ) {
			return (bool) pll_is_translated_post_type( $post_type );
		}
		$model = $this->model();
		if ( $model && is_callable( array( $model, 'is_translated_post_type' ) ) ) {
			return (bool) $model->is_translated_post_type( $post_type );
		}
		return false;
	}

	/**
	 * Whether a post type is one Polylang's own settings screen governs.
	 *
	 * PLL_Settings_CPT lists public post types, so those are the ones a site
	 * owner ever ticked. get_translated_post_types() is wider: it also returns
	 * types another plugin or core switched on in code - Patterns, Templates,
	 * Global Styles, the font types - which are not public, are not on that
	 * screen, and are not content anyone translates by hand. Reporting them
	 * filled the Content tab with post type chips nobody asked for.
	 *
	 * @param string $type Post type slug.
	 * @return bool
	 */
	public static function post_type_is_configurable( $type ) {
		$object = get_post_type_object( (string) $type );

		return ( $object && ! empty( $object->public ) );
	}

	/**
	 * Whether the Inspector can link to a list screen for this post type.
	 *
	 * Separate question from the one above: a type can be public and still have
	 * no edit.php screen, and an AI Translation button pointing at one that does
	 * not exist lands the reader on a permissions error.
	 *
	 * @param string $type Post type slug.
	 * @return bool
	 */
	public static function post_type_is_reachable( $type ) {
		$object = get_post_type_object( (string) $type );

		return ( $object && ! empty( $object->show_ui ) );
	}

	/**
	 * Post types configured for translation in Polylang.
	 *
	 * @return string[]
	 */
	protected function translated_post_types() {
		$model = $this->model();
		if ( $model && is_callable( array( $model, 'get_translated_post_types' ) ) ) {
			$types = $model->get_translated_post_types();
			if ( is_array( $types ) ) {
				$types    = array_diff( $types, array( 'elementor_library' ) );
				$filtered = array();
				foreach ( $types as $type ) {
					if ( $this->is_translated_post_type( $type ) && self::post_type_is_configurable( $type ) ) {
						$filtered[] = $type;
					}
				}
				return array_values( $filtered );
			}
		}

		$fallback = array();
		foreach ( array( 'post', 'page' ) as $type ) {
			if ( $this->is_translated_post_type( $type ) ) {
				$fallback[] = $type;
			}
		}
		return $fallback;
	}

	/**
	 * Build a DUPCAP_Result quickly.
	 *
	 * @param string $id      Id.
	 * @param string $status  Status.
	 * @param string $label   Title.
	 * @param string $message Message.
	 * @return DUPCAP_Result
	 */
	protected function result( $id, $status, $label, $message = '' ) {
		return new DUPCAP_Result( $id, $status, $this->group, $label, $message );
	}

	/**
	 * Get human-readable theme menu location label.
	 *
	 * @param string $location Location slug.
	 * @param string $fallback Theme-provided label.
	 * @return string
	 */
	public static function english_menu_location_label( $location, $fallback = '' ) {
		$location = (string) $location;
		$fallback = self::normalize_menu_location_fallback( (string) $fallback );

		if ( '' !== $fallback ) {
			return $fallback;
		}

		if ( '' !== $location ) {
			return ucwords( str_replace( array( '-', '_' ), ' ', $location ) );
		}

		return '';
	}

	/**
	 * Strip the trailing language name Polylang adds to theme location labels.
	 *
	 * @param string $label Raw theme label.
	 * @return string
	 */
	public static function normalize_menu_location_fallback( $label ) {
		$label = trim( (string) $label );

		if ( '' === $label ) {
			return '';
		}

		// Polylang appends the language name to every menu location label, so
		// "Primary Menu" reaches us as "Primary Menu English". Strip the names
		// this site actually uses, plus common spellings for the case where the
		// admin language differs from the site languages.
		$names = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$names = (array) pll_languages_list( array( 'fields' => 'name' ) );
		}

		$names = array_merge(
			$names,
			array( 'English', 'Anglais', 'Englisch', 'Inglés', 'Inglese', 'Hindi', 'Deutsch', 'German', 'Español', 'Spanish', 'Italiano', 'Italian', 'Français', 'French' )
		);

		$quoted = array();
		foreach ( $names as $name ) {
			$name = trim( (string) $name );
			if ( '' !== $name ) {
				$quoted[ $name ] = preg_quote( $name, '/' );
			}
		}

		if ( empty( $quoted ) ) {
			return $label;
		}

		// Longest first, so "English (US)" wins over "English".
		uasort(
			$quoted,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		$cleaned = preg_replace( '/\s+(' . implode( '|', $quoted ) . ')$/iu', '', $label );

		return ( is_string( $cleaned ) && '' !== trim( $cleaned ) ) ? trim( $cleaned ) : $label;
	}

	/**
	 * Taxonomies Polylang translates.
	 *
	 * Static because the dashboard adapter needs the same list outside a check.
	 * Falls back to category and post_tag when Polylang cannot list them, and
	 * counts a taxonomy only when Polylang confirms it: reporting "missing
	 * translation" for a taxonomy that is not translatable is a false positive.
	 *
	 * @return string[]
	 */
	public static function polylang_translated_taxonomies() {
		$pll   = function_exists( 'PLL' ) ? PLL() : null;
		$model = ( $pll && isset( $pll->model ) ) ? $pll->model : null;

		$candidates = array( 'category', 'post_tag' );
		// is_callable(), not method_exists(): Polylang 3.8 forwards these
		// through PLL_Model::__call().
		if ( $model && is_callable( array( $model, 'get_translated_taxonomies' ) ) ) {
			$listed = $model->get_translated_taxonomies();
			if ( is_array( $listed ) ) {
				$candidates = $listed;
			}
		}

		$translated = array();
		foreach ( $candidates as $taxonomy ) {
			if ( function_exists( 'pll_is_translated_taxonomy' ) ) {
				$is_translated = pll_is_translated_taxonomy( $taxonomy );
			} else {
				$is_translated = $model && is_callable( array( $model, 'is_translated_taxonomy' ) ) && $model->is_translated_taxonomy( $taxonomy );
			}

			if ( $is_translated ) {
				$translated[] = $taxonomy;
			}
		}

		return array_values( $translated );
	}
}
