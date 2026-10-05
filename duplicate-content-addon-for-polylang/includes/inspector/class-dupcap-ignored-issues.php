<?php
/**
 * Persists user-ignored translation warnings (e.g. brand names left unchanged).
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Ignored_Issues {

	const OPTION_KEY = 'dupcap_ignored_issues';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all() {
		$items = get_option( self::OPTION_KEY, array() );
		return is_array( $items ) ? $items : array();
	}

	/**
	 * @param string $key Stable ignore key.
	 * @return bool
	 */
	public static function is_ignored( $key ) {
		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}
		return isset( self::get_all()[ $key ] );
	}

	/**
	 * @param string $string_key String option key (e.g. blogname).
	 * @return string
	 */
	public static function string_key_for( $string_key ) {
		return self::sanitize_key( 'string:' . $string_key );
	}

	/**
	 * Ignore key for identical Gutenberg / classic post title or body.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $lang    Target language slug.
	 * @param string $field   Field key (title|content).
	 * @param string $text    Original text.
	 * @return string
	 */
	public static function content_identical_key( $post_id, $lang = '', $field = '', $text = '' ) {
		$parts = array(
			'content',
			'identical',
			(string) (int) $post_id,
			(string) $lang,
			sanitize_key( (string) $field ),
		);
		if ( '' !== (string) $text ) {
			$parts[] = md5( (string) $text );
		}
		return self::sanitize_key( implode( ':', $parts ) );
	}

	/**
	 * Ignore key for a Gutenberg/classic content link issue.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $lang    Target language slug.
	 * @param string $code    Issue code.
	 * @param string $url     Link URL.
	 * @return string
	 */
	public static function content_link_key( $post_id, $lang = '', $code = '', $url = '' ) {
		return self::sanitize_key( 'content:link:' . (int) $post_id . ':' . $lang . ':' . $code . ':' . md5( (string) $url ) );
	}

	/**
	 * Ignore key when the default language is newer than a translation.
	 *
	 * @param int    $root_id Default-language post ID.
	 * @param string $lang    Translation language slug.
	 * @return string
	 */
	public static function content_source_updated_key( $root_id, $lang = '' ) {
		return self::sanitize_key( 'content:source_updated:' . (int) $root_id . ':' . sanitize_key( (string) $lang ) );
	}

	/**
	 * Generate a unique ignore key for an Elementor finding.
	 *
	 * @param string $code        Finding code.
	 * @param int    $template_id Template / Document post ID.
	 * @param string $lang        Target language slug.
	 * @param string $widget_id   Widget element ID.
	 * @param string $field       Field key.
	 * @param string $text        Original text / value.
	 * @return string
	 */
	public static function elementor_key( $code, $template_id = 0, $lang = '', $widget_id = '', $field = '', $text = '' ) {
		$parts = array(
			'el',
			(string) $code,
			(string) (int) $template_id,
			(string) $lang,
			(string) $widget_id,
			(string) $field,
		);
		if ( '' !== (string) $text ) {
			$parts[] = md5( (string) $text );
		}
		return self::sanitize_key( implode( ':', $parts ) );
	}

	/**
	 * Ignore key for a URL configuration or routing row.
	 *
	 * @since 2.1.0
	 *
	 * @param string $code Issue code, e.g. slug_suffix.
	 * @param string $lang Language slug.
	 * @param string $url  URL or post ID the row refers to.
	 * @return string
	 */
	public static function url_key( $code, $lang = '', $url = '' ) {
		return self::sanitize_key( 'url:' . $code . ':' . $lang . ':' . md5( (string) $url ) );
	}

	/**
	 * Ignore key for a menu location with no menu assigned in one language.
	 *
	 * @since 2.1.0
	 *
	 * @param string $location Theme menu location slug.
	 * @param string $lang     Language slug.
	 * @return string
	 */
	public static function menu_key( $location, $lang = '' ) {
		return self::sanitize_key( 'menu:' . $location . ':' . $lang );
	}

	/**
	 * Ignore key for a classic menu item that points at a missing page.
	 *
	 * @param int    $menu_id        Nav menu term ID.
	 * @param string $normalized_url Normalized internal URL.
	 * @return string
	 */
	public static function menu_dead_key( $menu_id, $normalized_url = '' ) {
		return self::sanitize_key( 'menu:dead:' . (int) $menu_id . ':' . md5( (string) $normalized_url ) );
	}

	/**
	 * Ignore key for a post/page that is missing or unpublished in one language.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $post_id  Source post ID.
	 * @param string $lang     Target language slug.
	 * @param string $gap_type Gap type: missing or draft.
	 * @return string
	 */
	public static function content_gap_key( $post_id, $lang = '', $gap_type = 'missing' ) {
		$prefix = ( 'draft' === $gap_type ) ? 'content_draft' : 'content_missing';
		return self::sanitize_key( $prefix . ':' . (int) $post_id . ':' . $lang );
	}

	/**
	 * Ignore key for a term that is missing a translation in one language.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $term_id  Source term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $lang     Target language slug.
	 * @return string
	 */
	public static function term_gap_key( $term_id, $taxonomy = '', $lang = '' ) {
		return self::sanitize_key( 'term_missing:' . $taxonomy . ':' . (int) $term_id . ':' . $lang );
	}

	/**
	 * Ignore key for a homepage translation warning in one language.
	 *
	 * @since 2.1.0
	 *
	 * @param string $lang  Language slug.
	 * @param string $issue Issue code: missing or empty_layout.
	 * @return string
	 */
	public static function homepage_key( $lang, $issue = 'missing' ) {
		$issue = ( 'empty_layout' === $issue ) ? 'empty_layout' : 'missing';
		return self::url_key( 'front_page_' . $issue, $lang );
	}

	/**
	 * Ignore key for one post type or taxonomy that Polylang does not translate.
	 *
	 * One key per slug, so a type added later still raises the notice.
	 *
	 * @since 2.3.0
	 *
	 * @param string $slug Post type or taxonomy slug.
	 * @return string
	 */
	public static function untranslated_type_key( $slug ) {
		return self::sanitize_key( 'setup:untranslated_type:' . (string) $slug );
	}

	/**
	 * Ignore key for a dismissible setup notice.
	 *
	 * One key per subject rather than per notice, so a builder activated later
	 * - or a switcher removed again - still raises it after an earlier dismiss.
	 *
	 * @since 2.3.0
	 *
	 * @param string $slug What the notice is about.
	 * @return string
	 */
	public static function setup_notice_key( $slug ) {
		return self::sanitize_key( 'setup:notice:' . (string) $slug );
	}

	/**
	 * @param string $key    Stable ignore key.
	 * @param string $reason Optional reason slug.
	 * @return bool
	 */
	public static function ignore( $key, $reason = 'user' ) {
		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		$items         = self::get_all();
		$items[ $key ] = array(
			'ignored_at' => time(),
			'by'         => get_current_user_id(),
			'reason'     => sanitize_key( $reason ),
		);

		update_option( self::OPTION_KEY, $items, false );
		return true;
	}

	/**
	 * Stop ignoring a warning so it can show again after the next dashboard refresh.
	 *
	 * @param string $key Stable ignore key.
	 * @return bool
	 */
	public static function unignore( $key ) {
		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		$items = self::get_all();
		if ( ! isset( $items[ $key ] ) ) {
			return false;
		}

		unset( $items[ $key ] );
		update_option( self::OPTION_KEY, $items, false );
		return true;
	}

	/**
	 * Map an ignore key to a dashboard tab section.
	 *
	 * @param string $key Stable ignore key.
	 * @return string content|urls|elementor|site|empty.
	 */
	public static function get_section_for_key( $key ) {
		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return '';
		}

		if ( preg_match( '/^(content_missing|content_draft|term_missing|content:identical|content:link|content:source_updated|content:off_source):/', $key ) ) {
			return 'content';
		}

		if ( 0 === strpos( $key, 'el:' ) ) {
			return 'elementor';
		}

		if ( 0 === strpos( $key, 'menu:' ) || 0 === strpos( $key, 'string:' ) || 0 === strpos( $key, 'setup:' ) || 0 === strpos( $key, 'url:front_page_' ) ) {
			return 'site';
		}

		if ( 0 === strpos( $key, 'url:' ) ) {
			return 'urls';
		}

		return '';
	}

	/**
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function sanitize_key( $key ) {
		return (string) preg_replace( '/[^a-z0-9:_-]/', '', strtolower( (string) $key ) );
	}
}
