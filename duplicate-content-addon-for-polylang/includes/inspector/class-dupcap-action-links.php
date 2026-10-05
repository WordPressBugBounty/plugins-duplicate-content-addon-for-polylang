<?php
/**
 * Re-signs stored fix links with a fresh nonce at render time.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * A WordPress nonce is a hash of user id + action + session token, so a nonce
 * baked into a saved report dies for three separate reasons: the nonce tick
 * rolls over (roughly 12-24 hours), the user logs out and gets a new session
 * token, or a different administrator opens the dashboard. Storing one and then
 * trying to predict when it stopped working is guesswork.
 *
 * Instead the saved report keeps the link and the nonce is minted again while
 * the page renders, so it always belongs to the current user in the current
 * request. Nothing expires and no rescan is needed.
 *
 * Every nonced link the Inspector stores is a WordPress or Polylang admin URL
 * whose nonce action is derivable from its own query arguments, so no extra
 * field has to be saved alongside it.
 *
 * Only known action-link keys are re-signed. Scanning every string leaf would
 * mint nonces for Contributor-controlled post/Elementor text that happens to
 * contain a plugins.php / update.php URL with _wpnonce.
 *
 * @since 2.2.1
 */
class DUPCAP_Action_Links {

	/**
	 * Query argument WordPress and Polylang sign their admin links with.
	 */
	const NONCE_PARAM = '_wpnonce';

	/**
	 * Maximum nesting walked by refresh_deep().
	 *
	 * Report rows nest at most three levels (row => details => actions), so this
	 * only guards against a malformed saved option.
	 */
	const MAX_DEPTH = 8;

	/**
	 * Array keys whose string values are href targets and may carry a nonce.
	 *
	 * @var string[]
	 */
	const RESIGN_KEYS = array(
		'fix_url',
		'action_url',
		'translate_url',
		'edit_url',
		'page_fix_url',
		'secondary_action_url',
		'assign_url',
	);

	/**
	 * Re-sign nonced action URLs inside a report structure.
	 *
	 * Recurses arrays; only string values under {@see self::RESIGN_KEYS} are
	 * passed to refresh(). Other strings (content link urls, Elementor snippets)
	 * are left untouched.
	 *
	 * @since 2.2.1
	 *
	 * @param mixed $value Scalar, or an array of report rows.
	 * @param int   $depth Current recursion depth. Internal.
	 * @return mixed Value of the same shape, with fresh nonces on action links.
	 */
	public static function refresh_deep( $value, $depth = 0 ) {
		if ( ! is_array( $value ) || $depth >= self::MAX_DEPTH ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $item ) && in_array( (string) $key, self::RESIGN_KEYS, true ) ) {
				$value[ $key ] = self::refresh( $item );
				continue;
			}

			if ( is_array( $item ) ) {
				$value[ $key ] = self::refresh_deep( $item, $depth + 1 );
			}
		}

		return $value;
	}

	/**
	 * Replace the nonce on a single stored admin URL with a fresh one.
	 *
	 * Returns the URL untouched when it carries no nonce, when it is not under
	 * this site's admin URL, or when its shape is not one we can resolve an
	 * action for, so an unrecognised link is never silently broken.
	 *
	 * @since 2.2.1
	 *
	 * @param string $url Stored admin URL.
	 * @return string
	 */
	public static function refresh( $url ) {
		$url = (string) $url;

		if ( '' === $url || false === strpos( $url, self::NONCE_PARAM ) ) {
			return $url;
		}

		// wp_nonce_url() escapes for display, so a saved URL separates arguments
		// with &#038; rather than &. Decode before parsing.
		$decoded = wp_specialchars_decode( $url, ENT_QUOTES );

		$parsed = wp_parse_url( $decoded );
		if ( ! is_array( $parsed ) || empty( $parsed['query'] ) ) {
			return $url;
		}

		if ( ! self::is_local_admin_url( $parsed ) ) {
			return $url;
		}

		$args = array();
		wp_parse_str( $parsed['query'], $args );

		if ( empty( $args[ self::NONCE_PARAM ] ) ) {
			return $url;
		}

		$action = self::nonce_action_for( $parsed, $args );
		if ( '' === $action ) {
			return $url;
		}

		/*
		 * wp_nonce_url() escapes its result for printing inside HTML, so it hands
		 * back a URL whose arguments are separated by &#038; rather than &. That
		 * is correct in an href and wrong everywhere else: the Markdown export
		 * prints these links as plain text, where the entity shows through as
		 * "&amp;from_post=4296". Store the raw URL and let each renderer escape
		 * for its own output context.
		 */
		return html_entity_decode(
			wp_nonce_url( remove_query_arg( self::NONCE_PARAM, $decoded ), $action ),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Whether a parsed URL points at this site's wp-admin.
	 *
	 * Host must be absent (relative) or match admin_url(); path must sit under
	 * the admin path so //evil.example/wp-admin/... cannot be re-signed.
	 *
	 * @param array<string, mixed> $parsed Result of wp_parse_url().
	 * @return bool
	 */
	protected static function is_local_admin_url( array $parsed ) {
		$admin = wp_parse_url( admin_url() );
		if ( ! is_array( $admin ) ) {
			return false;
		}

		$admin_host = isset( $admin['host'] ) ? (string) $admin['host'] : '';
		$admin_path = isset( $admin['path'] ) ? untrailingslashit( (string) $admin['path'] ) : '';

		$host = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		if ( '' !== $host && $host !== $admin_host ) {
			return false;
		}

		$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
		if ( '' === $admin_path ) {
			return '' !== $path;
		}

		return '' !== $path && 0 === strpos( $path, $admin_path );
	}

	/**
	 * Resolve the nonce action a stored admin URL was signed with.
	 *
	 * The four shapes below are the only nonced links the Inspector stores. Each
	 * action name is rebuilt from the URL itself, exactly as WordPress core and
	 * PLL_Admin_Links build it.
	 *
	 * @since 2.2.1
	 *
	 * @param array<string, mixed> $parsed Result of wp_parse_url().
	 * @param array<string, mixed> $args   Parsed query arguments.
	 * @return string Nonce action, or '' when the shape is unknown.
	 */
	protected static function nonce_action_for( array $parsed, array $args ) {
		$file   = isset( $parsed['path'] ) ? basename( (string) $parsed['path'] ) : '';
		$action = isset( $args['action'] ) ? (string) $args['action'] : '';
		$plugin = isset( $args['plugin'] ) ? (string) $args['plugin'] : '';

		// Polylang "add a translation" link for a post, page or CPT.
		if ( 'post-new.php' === $file && isset( $args['from_post'], $args['new_lang'] ) ) {
			return 'new-post-translation';
		}

		// Polylang "add a translation" link for an attachment.
		if ( 'admin.php' === $file && 'translate_media' === $action && isset( $args['from_media'] ) ) {
			return 'translate_media';
		}

		// Core plugin activation link offered by the switcher and Elementor checks.
		if ( 'plugins.php' === $file && 'activate' === $action && '' !== $plugin ) {
			return 'activate-plugin_' . $plugin;
		}

		// Core plugin install link offered by the same checks.
		if ( 'update.php' === $file && 'install-plugin' === $action && '' !== $plugin ) {
			return 'install-plugin_' . $plugin;
		}

		return '';
	}
}
