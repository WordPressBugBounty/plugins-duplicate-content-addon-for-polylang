<?php
/**
 * URL configuration checks: how Polylang puts the language into the URL, and
 * the WordPress settings that interact with it.
 *
 * Read-only. This check never writes a Polylang option.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Check_Url_Config extends DUPCAP_Check {

	protected $group = 'URL Configuration';

	/**
	 * Language is taken from the content, not the URL.
	 */
	const FORCE_LANG_CONTENT = 0;

	/**
	 * Language is a directory, e.g. /fr/.
	 */
	const FORCE_LANG_DIRECTORY = 1;

	/**
	 * Language is a subdomain, e.g. fr.example.com.
	 */
	const FORCE_LANG_SUBDOMAIN = 2;

	/**
	 * Each language has its own domain.
	 */
	const FORCE_LANG_DOMAIN = 3;

	/**
	 * Run the check.
	 *
	 * @since 2.1.0
	 *
	 * @return DUPCAP_Result[]
	 */
	public function run() {
		$results   = array();
		$languages = $this->active_languages();

		if ( count( $languages ) < 2 ) {
			return $results;
		}

		$force_lang = (int) $this->option( 'force_lang', self::FORCE_LANG_DIRECTORY );

		$results[] = $this->summary_result( $force_lang );

		$results = array_merge(
			$results,
			array_filter(
				array(
					$this->check_no_lang_in_url( $force_lang ),
					$this->check_default_prefix( $languages ),
					$this->check_stale_rewrites(),
					$this->check_domains( $force_lang, $languages ),
					$this->check_home_mismatch(),
					$this->check_browser_cache_conflict(),
					$this->check_blog_page( $languages ),
					$this->check_front_page_posts_mode(),
				)
			)
		);

		return array_values( $results );
	}

	/* ---------- Individual checks ---------- */

	/**
	 * Always-present plain-English summary of the URL settings.
	 *
	 * Polylang's own Site Health prints these as raw values ("force_lang: 1").
	 * The interpretation is the whole value of this row.
	 *
	 * @since 2.1.0
	 *
	 * @param int $force_lang Polylang force_lang option.
	 * @return DUPCAP_Result
	 */
	protected function summary_result( $force_lang ) {
		$lines = array(
			sprintf(
				/* translators: %s: description of where the language appears in the URL */
				__( 'Language in URL: %s.', 'duplicate-content-addon-for-polylang' ),
				$this->force_lang_label( $force_lang )
			),
			sprintf(
				/* translators: %s: Yes or No */
				__( 'Default language hidden from URLs: %s.', 'duplicate-content-addon-for-polylang' ),
				$this->yes_no( $this->hides_default_lang() )
			),
			sprintf(
				/* translators: %s: Yes or No */
				__( 'Pretty permalinks: %s.', 'duplicate-content-addon-for-polylang' ),
				$this->yes_no( (bool) get_option( 'permalink_structure' ) )
			),
			sprintf(
				/* translators: %s: Yes or No */
				__( 'Browser language detection: %s.', 'duplicate-content-addon-for-polylang' ),
				$this->yes_no( $this->option( 'browser', false ) )
			),
		);

		return $this->result(
			'url_config_summary',
			DUPCAP_Result::PASS,
			__( 'URL settings', 'duplicate-content-addon-for-polylang' ),
			implode( ' ', $lines )
		)->with_fix(
			admin_url( 'admin.php?page=mlang_settings' ),
			__( 'Polylang URL settings', 'duplicate-content-addon-for-polylang' )
		);
	}

	/**
	 * Language is not in the URL at all.
	 *
	 * Rare: Polylang only offers this mode when the site has opted in via the
	 * pll_language_from_content_available option, so no UI is built around it.
	 *
	 * @since 2.1.0
	 *
	 * @param int $force_lang Polylang force_lang option.
	 * @return DUPCAP_Result|null
	 */
	protected function check_no_lang_in_url( $force_lang ) {
		if ( self::FORCE_LANG_CONTENT !== $force_lang ) {
			return null;
		}

		return $this->result(
			'url_config_no_lang_in_url',
			DUPCAP_Result::WARNING,
			__( 'Language is not shown in your URLs', 'duplicate-content-addon-for-polylang' ),
			__( 'Polylang is set to detect the language from the content instead of the URL. Every language shares one address, so search engines and visitors cannot link to a specific language, and caching plugins will serve whichever language was cached first.', 'duplicate-content-addon-for-polylang' )
		)->with_fix(
			admin_url( 'admin.php?page=mlang_settings' ),
			__( 'Change URL modifications', 'duplicate-content-addon-for-polylang' )
		);
	}

	/**
	 * The default language still carries its own prefix, e.g. /it/ on an
	 * Italian-default site. This is the single most common URL complaint.
	 *
	 * @since 2.1.0
	 *
	 * @param array $languages Active language objects.
	 * @return DUPCAP_Result|null
	 */
	protected function check_default_prefix( $languages ) {
		if ( $this->hides_default_lang() ) {
			return null;
		}

		$default = $this->default_lang();
		if ( '' === $default ) {
			return null;
		}

		$default_home = $this->home_url_for( $languages, $default );
		if ( '' === $default_home ) {
			return null;
		}

		$default_path = (string) wp_parse_url( $default_home, PHP_URL_PATH );
		$site_path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( untrailingslashit( $default_path ) === untrailingslashit( $site_path ) ) {
			return null;
		}

		return $this->result(
			'url_config_default_prefix',
			DUPCAP_Result::WARNING,
			__( 'Your main language has an extra code in its URLs', 'duplicate-content-addon-for-polylang' ),
			sprintf(
				/* translators: 1: default language home URL, 2: site home URL */
				__( 'Pages in your default language are served from %1$s instead of %2$s. Turn on "Hide URL language information for default language" in Polylang settings to remove the code.', 'duplicate-content-addon-for-polylang' ),
				$default_home,
				home_url( '/' )
			)
		)->with_fix(
			admin_url( 'admin.php?page=mlang_settings' ),
			__( 'Hide the default language code', 'duplicate-content-addon-for-polylang' )
		);
	}

	/**
	 * Rewrite rules were never regenerated after a Polylang or permalink change.
	 * This is the cause of the "404 on everything after I changed a setting" class.
	 *
	 * @since 2.1.0
	 *
	 * @return DUPCAP_Result|null
	 */
	protected function check_stale_rewrites() {
		if ( ! get_option( 'permalink_structure' ) ) {
			// Plain permalinks have no rewrite rules by design. lang_permalinks covers this.
			return null;
		}

		$rules = get_option( 'rewrite_rules' );

		if ( ! empty( $rules ) && is_array( $rules ) ) {
			return null;
		}

		return $this->result(
			'url_config_stale_rewrites',
			DUPCAP_Result::WARNING,
			__( 'Your URL rules need to be refreshed', 'duplicate-content-addon-for-polylang' ),
			__( 'WordPress has no saved rewrite rules, so language URLs may return "Page not found" until they are rebuilt. Opening Settings → Permalinks and pressing Save Changes rebuilds them. You do not need to change anything on that screen.', 'duplicate-content-addon-for-polylang' )
		)->with_fix(
			admin_url( 'options-permalink.php' ),
			__( 'Open Permalinks and save', 'duplicate-content-addon-for-polylang' )
		);
	}

	/**
	 * In multi-domain mode every language needs its own unique domain.
	 *
	 * Completeness and uniqueness only. Polylang 3.7+ already HTTP-checks
	 * reachability itself and raises pll_invalid_domains.
	 *
	 * @since 2.1.0
	 *
	 * @param int   $force_lang Polylang force_lang option.
	 * @param array $languages  Active language objects.
	 * @return DUPCAP_Result|null
	 */
	protected function check_domains( $force_lang, $languages ) {
		if ( self::FORCE_LANG_DOMAIN !== $force_lang ) {
			return null;
		}

		$domains = $this->option( 'domains', array() );
		$domains = is_array( $domains ) ? $domains : array();

		$missing = array();
		$hosts   = array();
		$shared  = array();

		foreach ( $languages as $lang ) {
			$slug   = $this->lang_slug( $lang );
			$domain = isset( $domains[ $slug ] ) ? trim( (string) $domains[ $slug ] ) : '';

			if ( '' === $domain ) {
				$missing[] = $this->lang_label( $lang );
				continue;
			}

			$host = strtolower( (string) wp_parse_url( $domain, PHP_URL_HOST ) );
			if ( '' === $host ) {
				$missing[] = $this->lang_label( $lang );
				continue;
			}

			if ( isset( $hosts[ $host ] ) ) {
				$shared[] = $host;
			}
			$hosts[ $host ] = true;
		}

		if ( empty( $missing ) && empty( $shared ) ) {
			return null;
		}

		$parts = array();

		if ( ! empty( $missing ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma separated language names */
				__( 'No domain is configured for: %s.', 'duplicate-content-addon-for-polylang' ),
				implode( ', ', $missing )
			);
		}

		if ( ! empty( $shared ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma separated host names */
				__( 'These domains are used by more than one language: %s.', 'duplicate-content-addon-for-polylang' ),
				implode( ', ', array_unique( $shared ) )
			);
		}

		$parts[] = __( 'Languages without their own working domain will not load.', 'duplicate-content-addon-for-polylang' );

		return $this->result(
			'url_config_domains_incomplete',
			DUPCAP_Result::CRITICAL,
			__( 'Language domains are incomplete', 'duplicate-content-addon-for-polylang' ),
			implode( ' ', $parts )
		)->with_fix(
			admin_url( 'admin.php?page=mlang_settings' ),
			__( 'Set language domains', 'duplicate-content-addon-for-polylang' )
		);
	}

	/**
	 * WordPress Address and Site Address disagree on scheme or host, which
	 * commonly produces a redirect loop when switching language.
	 *
	 * @since 2.1.0
	 *
	 * @return DUPCAP_Result|null
	 */
	protected function check_home_mismatch() {
		$home = wp_parse_url( (string) get_option( 'home' ) );
		$site = wp_parse_url( (string) get_option( 'siteurl' ) );

		if ( empty( $home['host'] ) || empty( $site['host'] ) ) {
			return null;
		}

		$home_scheme = isset( $home['scheme'] ) ? strtolower( $home['scheme'] ) : '';
		$site_scheme = isset( $site['scheme'] ) ? strtolower( $site['scheme'] ) : '';

		$host_differs   = strtolower( $home['host'] ) !== strtolower( $site['host'] );
		$scheme_differs = $home_scheme !== $site_scheme;

		if ( ! $host_differs && ! $scheme_differs ) {
			return null;
		}

		return $this->result(
			'url_config_home_mismatch',
			DUPCAP_Result::WARNING,
			__( 'Your two site addresses do not match', 'duplicate-content-addon-for-polylang' ),
			sprintf(
				/* translators: 1: home option value, 2: siteurl option value */
				__( 'WordPress Address is %2$s but Site Address is %1$s. When these differ, switching language can bounce visitors between addresses or fail to load. Make both use the same address unless you set this up deliberately.', 'duplicate-content-addon-for-polylang' ),
				(string) get_option( 'home' ),
				(string) get_option( 'siteurl' )
			)
		)->with_fix(
			admin_url( 'options-general.php' ),
			__( 'Check site addresses', 'duplicate-content-addon-for-polylang' )
		);
	}

	/**
	 * Browser language detection behind a full-page cache serves whichever
	 * language was cached first to everybody.
	 *
	 * @since 2.1.0
	 *
	 * @return DUPCAP_Result|null
	 */
	protected function check_browser_cache_conflict() {
		if ( ! $this->option( 'browser', false ) ) {
			return null;
		}

		$cache = $this->detect_page_cache();
		if ( '' === $cache ) {
			return null;
		}

		return $this->result(
			'url_config_browser_cache_conflict',
			DUPCAP_Result::WARNING,
			__( 'Browser language detection conflicts with your cache', 'duplicate-content-addon-for-polylang' ),
			sprintf(
				/* translators: %s: caching plugin name */
				__( 'Polylang is set to detect the visitor language from the browser, but %s is caching full pages. The first visitor decides which language gets stored, and everyone after them is served that same cached language regardless of their browser. Either turn off browser detection, or exclude the home page from caching.', 'duplicate-content-addon-for-polylang' ),
				$cache
			)
		)->with_fix(
			admin_url( 'admin.php?page=mlang_settings' ),
			__( 'Review browser detection', 'duplicate-content-addon-for-polylang' )
		);
	}

	/**
	 * A language has no posts page while the default language does.
	 *
	 * PLL_Language::$page_for_posts is already resolved per language, so no
	 * pll_get_post() lookup is needed.
	 *
	 * @since 2.1.0
	 *
	 * @param array $languages Active language objects.
	 * @return DUPCAP_Result|null
	 */
	protected function check_blog_page( $languages ) {
		if ( ! (int) get_option( 'page_for_posts' ) ) {
			return null;
		}

		$default = $this->default_lang();
		$missing = array();
		$known   = false;

		foreach ( $languages as $lang ) {
			if ( ! is_object( $lang ) || ! isset( $lang->page_for_posts ) ) {
				continue;
			}

			$known = true;

			if ( $this->lang_slug( $lang ) === $default ) {
				continue;
			}

			if ( ! (int) $lang->page_for_posts ) {
				$missing[] = $this->lang_label( $lang );
			}
		}

		if ( ! $known || empty( $missing ) ) {
			return null;
		}

		return $this->result(
			'url_config_blog_page',
			DUPCAP_Result::WARNING,
			__( 'Your blog page is missing in some languages', 'duplicate-content-addon-for-polylang' ),
			sprintf(
				/* translators: %s: comma separated language names */
				__( 'No posts page is set for: %s. Visitors in those languages may get "Page not found" where your blog should be. Translate the page you use as the posts page, and Polylang will use the translation automatically.', 'duplicate-content-addon-for-polylang' ),
				implode( ', ', $missing )
			)
		)->with_fix(
			admin_url( 'options-reading.php' ),
			__( 'Reading settings', 'duplicate-content-addon-for-polylang' )
		);
	}

	/**
	 * The site shows latest posts on the front page. The existing lang_homepage
	 * check only runs for static front pages, so these sites otherwise get no
	 * homepage row at all.
	 *
	 * @since 2.1.0
	 *
	 * @return DUPCAP_Result|null
	 */
	protected function check_front_page_posts_mode() {
		if ( 'posts' !== get_option( 'show_on_front' ) ) {
			return null;
		}

		return $this->result(
			'url_config_front_page_posts_mode',
			DUPCAP_Result::WARNING,
			__( 'Your homepage shows latest posts, so it cannot be translated directly', 'duplicate-content-addon-for-polylang' ),
			__( 'Your front page is set to show your latest posts rather than a page. There is no homepage to translate, so each language shows whatever posts exist in it — and a language with no posts shows an empty homepage. Create a page, translate it, and set it as your front page in Settings → Reading.', 'duplicate-content-addon-for-polylang' )
		)->with_fix(
			admin_url( 'options-reading.php' ),
			__( 'Reading settings', 'duplicate-content-addon-for-polylang' )
		);
	}

	/* ---------- Helpers ---------- */

	/**
	 * Per-language home URL, preferring the language object's own resolver.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $languages Active language objects.
	 * @param string $slug      Language slug to find.
	 * @return string Empty string when unresolvable.
	 */
	protected function home_url_for( $languages, $slug ) {
		foreach ( $languages as $lang ) {
			if ( $this->lang_slug( $lang ) !== $slug ) {
				continue;
			}

			if ( is_object( $lang ) && method_exists( $lang, 'get_home_url' ) ) {
				return (string) $lang->get_home_url();
			}
		}

		if ( function_exists( 'pll_home_url' ) ) {
			return (string) pll_home_url( $slug );
		}

		return '';
	}

	/**
	 * Name of an active full-page caching plugin, or an empty string.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	protected function detect_page_cache() {
		$signatures = array(
			'LSCWP_V'            => 'LiteSpeed Cache',
			'WP_ROCKET_VERSION'  => 'WP Rocket',
			'W3TC'               => 'W3 Total Cache',
			'WPCACHEHOME'        => 'WP Super Cache',
			'WPFC_MAIN_PATH'     => 'WP Fastest Cache',
			'SiteGround_Optimizer\VERSION' => 'SiteGround Optimizer',
		);

		foreach ( $signatures as $constant => $label ) {
			if ( defined( $constant ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Human label for a force_lang value.
	 *
	 * @since 2.1.0
	 *
	 * @param int $force_lang Polylang force_lang option.
	 * @return string
	 */
	protected function force_lang_label( $force_lang ) {
		switch ( (int) $force_lang ) {
			case self::FORCE_LANG_CONTENT:
				return __( 'not shown in the URL (detected from the content)', 'duplicate-content-addon-for-polylang' );
			case self::FORCE_LANG_SUBDOMAIN:
				return __( 'a subdomain, such as fr.example.com', 'duplicate-content-addon-for-polylang' );
			case self::FORCE_LANG_DOMAIN:
				return __( 'a separate domain for each language', 'duplicate-content-addon-for-polylang' );
			case self::FORCE_LANG_DIRECTORY:
			default:
				return __( 'a directory, such as /fr/', 'duplicate-content-addon-for-polylang' );
		}
	}

	/**
	 * Whether Polylang hides the language code for the default language.
	 *
	 * Polylang renamed this option at 3.7: `hide_default_lang` became
	 * `hide_default` (see Options\Business\Hide_Default, @since 3.7). Reading only
	 * the old name returns null on 3.7+, which silently reports "not hidden" on
	 * every modern install. Both names are read here.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	protected function hides_default_lang() {
		$value = $this->option( 'hide_default', null );

		if ( null === $value ) {
			$value = $this->option( 'hide_default_lang', null );
		}

		return (bool) $value;
	}

	/**
	 * Translated Yes / No.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $value Truthy value.
	 * @return string
	 */
	protected function yes_no( $value ) {
		return $value
			? __( 'Yes', 'duplicate-content-addon-for-polylang' )
			: __( 'No', 'duplicate-content-addon-for-polylang' );
	}
}
