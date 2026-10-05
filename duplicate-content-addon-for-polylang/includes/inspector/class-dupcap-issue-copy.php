<?php
/**
 * Shared issue copy for dashboard UI, Markdown export, and Elementor insights.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Back-compatible reader for {@see DUPCAP_Issue_Registry}.
 *
 * The copy itself moved into the registry in 2.3.0, alongside the
 * classification facts that decide where a card lands and how its rows are
 * counted. This class stays as the call-site-facing API so the dashboard, the
 * Markdown export and the Elementor insights did not all have to change at
 * once. Prefer DUPCAP_Issue_Registry in new code.
 */
class DUPCAP_Issue_Copy {

	/**
	 * Dashboard summary message for a grouped issue key.
	 *
	 * @param string $key Group dedupe key.
	 * @return string Empty when unknown.
	 */
	public static function group_message( $key ) {
		return (string) DUPCAP_Issue_Registry::field( $key, 'message', '' );
	}

	/**
	 * Why-this-matters copy for a grouped issue key.
	 *
	 * @param string $key Group dedupe key.
	 * @return string Empty when unknown.
	 */
	public static function group_fix_reason( $key ) {
		return (string) DUPCAP_Issue_Registry::field( $key, 'why', '' );
	}

	/**
	 * Plain-language how-to for a dashboard issue group (export).
	 *
	 * @param string $key Group dedupe key.
	 * @return string Empty when unknown.
	 */
	public static function group_howto( $key ) {
		return (string) DUPCAP_Issue_Registry::field( $key, 'howto', '' );
	}

	/**
	 * Elementor insight bucket summary message.
	 *
	 * @param string $key Bucket key.
	 * @return string Empty when unknown.
	 */
	public static function elementor_message( $key ) {
		return DUPCAP_Issue_Registry::elementor_field( $key, 'message' );
	}
}
