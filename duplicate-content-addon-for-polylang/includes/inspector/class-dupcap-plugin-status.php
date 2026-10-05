<?php
/**
 * Where another plugin stands on this site: installed, active, or missing.
 *
 * The dashboard, the checks, the Elementor scanner and the setup wizard all ask
 * this about the same few add-ons. They used to answer it with their own copy
 * of the same get_plugins() loop; this is the one copy.
 *
 * @package DuplicateContentAddonForPolylang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static lookups over the installed plugin list.
 */
class DUPCAP_Plugin_Status {

	/**
	 * Make get_plugins() / is_plugin_active() available outside wp-admin.
	 *
	 * @return void
	 */
	private static function load() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Basename of an installed plugin, or '' when it is not installed.
	 *
	 * Tries `{slug}/{main}.php` first, then any folder holding `{main}.php`:
	 * a plugin installed from a zip can land in a differently named folder.
	 *
	 * @param string $slug Plugin folder, as on WordPress.org.
	 * @param string $main Main file name without `.php`, when it differs from the slug.
	 * @return string
	 */
	public static function find( $slug, $main = '' ) {
		self::load();

		$main     = '' !== $main ? (string) $main : (string) $slug;
		$plugins  = get_plugins();
		$basename = $slug . '/' . $main . '.php';

		if ( array_key_exists( $basename, $plugins ) ) {
			return $basename;
		}

		$suffix = '/' . $main . '.php';
		foreach ( array_keys( $plugins ) as $plugin_file ) {
			if ( substr( $plugin_file, -strlen( $suffix ) ) === $suffix ) {
				return $plugin_file;
			}
		}

		return '';
	}

	/**
	 * First plugin file inside a folder, or '' when the folder holds none.
	 *
	 * For add-ons whose main file name does not follow their folder name.
	 *
	 * @param string $dir Plugin folder name.
	 * @return string
	 */
	public static function in_folder( $dir ) {
		self::load();

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			$parts = explode( '/', $plugin_file );
			if ( count( $parts ) > 1 && $parts[0] === $dir ) {
				return $plugin_file;
			}
		}

		return '';
	}

	/**
	 * Whether a plugin is running, on this site or network-wide.
	 *
	 * @param string $basename Plugin basename.
	 * @return bool
	 */
	public static function is_active( $basename ) {
		self::load();

		// is_plugin_active() already covers network activation.
		return '' !== (string) $basename && is_plugin_active( $basename );
	}

	/**
	 * Installed/active state of one plugin.
	 *
	 * A plugin that defines a constant when it loads is active by definition,
	 * even when its basename cannot be resolved (for example a must-use copy).
	 *
	 * @param string $slug     Plugin folder, as on WordPress.org.
	 * @param string $constant Constant the plugin defines when loaded, or ''.
	 * @param string $main     Main file name without `.php`, when it differs from the slug.
	 * @return array{status: string, basename: string} status is active|inactive|missing.
	 */
	public static function state( $slug, $constant = '', $main = '' ) {
		if ( '' !== $constant && defined( $constant ) ) {
			return array(
				'status'   => 'active',
				'basename' => '',
			);
		}

		$basename = self::find( $slug, $main );

		if ( '' === $basename ) {
			return array(
				'status'   => 'missing',
				'basename' => '',
			);
		}

		return array(
			'status'   => self::is_active( $basename ) ? 'active' : 'inactive',
			'basename' => $basename,
		);
	}

	/**
	 * Nonce-signed URL that activates an installed plugin.
	 *
	 * @param string $basename Plugin basename.
	 * @return string
	 */
	public static function activate_url( $basename ) {
		return wp_nonce_url(
			self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $basename ) ),
			'activate-plugin_' . $basename
		);
	}

	/**
	 * Nonce-signed URL that installs a plugin from WordPress.org.
	 *
	 * @param string $slug WordPress.org slug.
	 * @return string
	 */
	public static function install_url( $slug ) {
		return wp_nonce_url(
			self_admin_url( 'update.php?action=install-plugin&plugin=' . $slug ),
			'install-plugin_' . $slug
		);
	}
}
