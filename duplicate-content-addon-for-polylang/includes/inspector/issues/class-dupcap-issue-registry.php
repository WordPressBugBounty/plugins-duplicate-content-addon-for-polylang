<?php
/**
 * Single catalog of issue definitions: classification facts and user-facing copy.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Definitions for every issue type the dashboard can render.
 *
 * One definition per {@see DUPCAP_Issue_Types} key. Each carries the facts that
 * decide where a card lands, how its rows are counted and who fixes it, plus
 * the three copy fields the card and the Markdown export both read.
 *
 * Definitions are built on first read, never in a constructor: calling __() as
 * a side effect of loading a file runs before `init` and trips the WordPress
 * 6.7 just-in-time translation notice.
 *
 * @since 2.3.0
 */
final class DUPCAP_Issue_Registry {

	/**
	 * Built definitions, keyed by issue type.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $definitions = null;

	/**
	 * Built Elementor bucket copy, keyed by bucket code.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private static $elementor = null;

	/**
	 * Keys already reported as unmapped, so one missing key logs once.
	 *
	 * @var array<string, bool>
	 */
	private static $reported = array();

	/**
	 * All issue definitions.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all() {
		if ( null === self::$definitions ) {
			self::$definitions = self::build();

			/**
			 * Filter the issue type definitions.
			 *
			 * @since 2.3.0
			 *
			 * @param array<string, array<string, mixed>> $definitions Definitions keyed by issue type.
			 */
			self::$definitions = (array) apply_filters( 'dupcap_issue_types', self::$definitions );
		}

		return self::$definitions;
	}

	/**
	 * One definition, or null when the key is unknown.
	 *
	 * An unknown key is logged under WP_DEBUG rather than swallowed: a card that
	 * renders with no copy and no section is the failure mode this registry
	 * exists to make visible.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key Issue type key.
	 * @return array<string, mixed>|null
	 */
	public static function get( $key ) {
		$key         = (string) $key;
		$definitions = self::all();

		if ( isset( $definitions[ $key ] ) ) {
			return $definitions[ $key ];
		}

		self::report_unmapped( $key );

		return null;
	}

	/**
	 * A single field from a definition.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key      Issue type key.
	 * @param string $field    Field name.
	 * @param mixed  $fallback Value returned when the key or field is unknown.
	 * @return mixed
	 */
	public static function field( $key, $field, $fallback = '' ) {
		$definition = self::get( $key );

		if ( ! is_array( $definition ) || ! isset( $definition[ $field ] ) ) {
			return $fallback;
		}

		return $definition[ $field ];
	}

	/**
	 * Dashboard section an issue type belongs to.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key Issue type key.
	 * @return string One of the DUPCAP_Issue_Types::SECTION_* values.
	 */
	public static function section( $key ) {
		return (string) self::field( $key, 'section', DUPCAP_Issue_Types::SECTION_SITE );
	}

	/**
	 * How rows of this issue type are counted.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key Issue type key.
	 * @return string One of the DUPCAP_Issue_Types::COUNT_* values.
	 */
	public static function count_mode( $key ) {
		return (string) self::field( $key, 'count_by', DUPCAP_Issue_Types::COUNT_ROW );
	}

	/**
	 * Where an issue type is fixed, when no result row supplied a link.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key Issue type key.
	 * @return array{url: string, label: string}|array{} Empty when the type declares none.
	 */
	public static function fix_for( $key ) {
		$fix = self::field( $key, 'fix', array() );

		if ( ! is_array( $fix ) || empty( $fix['admin_page'] ) || empty( $fix['label'] ) ) {
			return array();
		}

		return array(
			'url'   => admin_url( (string) $fix['admin_page'] ),
			'label' => (string) $fix['label'],
		);
	}

	/**
	 * The severity an issue type always reports at.
	 *
	 * Returns an empty string for the types that compute severity per row, so
	 * the caller keeps whatever the checks produced. Never guess for those:
	 * reporting a loopback timeout on a local site as a site-down emergency is
	 * the fastest way to make the whole report untrustworthy.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key Issue type key.
	 * @return string One of the DUPCAP_Issue_Types::SEVERITY_* values, or ''.
	 */
	public static function declared_severity( $key ) {
		if ( DUPCAP_Issue_Types::SEVERITY_FROM_RESULTS === self::field( $key, 'severity_from', DUPCAP_Issue_Types::SEVERITY_FROM_RESULTS ) ) {
			return '';
		}

		return (string) self::field( $key, 'severity', '' );
	}

	/**
	 * Issue type key an old scanner result id maps to.
	 *
	 * Mirrors {@see DUPCAP_Dashboard_Adapter::dedupe_key_for()} through each
	 * definition's legacy_keys list, so the registry can be audited against the
	 * live adapter instead of drifting from it silently.
	 *
	 * @internal Dev/audit helper (tests/replay.php). Not part of the public API.
	 *
	 * @param string $id Scanner result id.
	 * @return string Issue type key, or an empty string when nothing matches.
	 */
	public static function key_for_result_id( $id ) {
		$id    = (string) $id;
		$match = '';
		$len   = 0;

		foreach ( self::all() as $key => $definition ) {
			if ( empty( $definition['legacy_keys'] ) || ! is_array( $definition['legacy_keys'] ) ) {
				continue;
			}

			foreach ( $definition['legacy_keys'] as $legacy ) {
				$legacy = (string) $legacy;

				if ( $id !== $legacy && 0 !== strpos( $id, $legacy ) ) {
					continue;
				}

				// Longest prefix wins: menu_dead_links must beat menu_, and
				// elementor_none must beat elementor_.
				if ( strlen( $legacy ) > $len ) {
					$match = $key;
					$len   = strlen( $legacy );
				}
			}
		}

		return $match;
	}

	/**
	 * Elementor insight bucket copy.
	 *
	 * Elementor findings use their own four-level scale and never become
	 * DUPCAP_Result objects, so their copy is kept in a separate map rather than
	 * mixed into the issue definitions.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function elementor_buckets() {
		if ( null === self::$elementor ) {
			self::$elementor = self::build_elementor();

			/**
			 * Filter the Elementor insight bucket copy.
			 *
			 * @since 2.3.0
			 *
			 * @param array<string, array<string, string>> $buckets Copy keyed by bucket code.
			 */
			self::$elementor = (array) apply_filters( 'dupcap_issue_groups', self::$elementor );
		}

		return self::$elementor;
	}

	/**
	 * One Elementor bucket copy field.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key   Bucket code.
	 * @param string $field message|howto.
	 * @return string
	 */
	public static function elementor_field( $key, $field ) {
		$buckets = self::elementor_buckets();
		$key     = (string) $key;

		if ( ! isset( $buckets[ $key ][ $field ] ) ) {
			return '';
		}

		return (string) $buckets[ $key ][ $field ];
	}

	/**
	 * Log an unmapped key once per request when debugging.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key Unmapped issue type key.
	 * @return void
	 */
	private static function report_unmapped( $key ) {
		if ( '' === $key || isset( self::$reported[ $key ] ) ) {
			return;
		}

		self::$reported[ $key ] = true;

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! function_exists( 'error_log' ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only notice for an unmapped issue type.
		error_log( 'DUPCAP: no issue definition for "' . $key . '". Add it to DUPCAP_Issue_Registry or its card renders without copy.' );
	}

	/**
	 * Build the definitions.
	 *
	 * `severity` is the level this type always reports at, under one test:
	 * Critical is a 404, a blank render or wrong-language navigation; Warning is
	 * a page that loads with the wrong words in it; Notice has no visitor
	 * impact. Types whose checks emit rows at different levels carry
	 * `severity_from => SEVERITY_FROM_RESULTS` and keep computing.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function build() {
		$definitions = array();

		/* ---------- Content ---------- */

		$definitions[ DUPCAP_Issue_Types::UNTRANSLATED_TYPES ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'lang_untranslated_types' ),
			'message'       => __( 'Some custom post types or taxonomies (categories/tags) are not enabled for translation in Polylang settings.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Polylang hides untranslatable post types and taxonomies from translation lists, so their pages cannot be translated until enabled.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Go to Languages → Settings → Custom post types & Taxonomies and check the items you wish to translate.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::CONTENT_NOLANG ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_ONE_CLICK,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'content_nolang_', 'term_nolang_' ),
			'message'       => __( 'Some posts, pages, categories, or tags have no language assigned.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Content without a language never appears in translation lists and cannot be seen by visitors in secondary languages.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Click "Set to default language" above to automatically assign your primary language to all unassigned content.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::CONTENT_MISSING ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			'count_by'      => DUPCAP_Issue_Types::COUNT_SOURCE_ITEM,
			'legacy_keys'   => array( 'content_missing_' ),
			'message'       => __( 'Some pages or posts are missing translations in one or more languages.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Visitors switching languages on these pages will miss out on content or see empty sections.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Click the "+" (Translate) button in the table below to add the missing language version for each page.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::CONTENT_IDENTICAL ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'content_identical_' ),
			'message'       => __( 'Some translated pages still contain the exact same title or body text as the original language.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Visitors on your translated pages will see untranslated text from the original language.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Open the translated page in WordPress and replace the copied original text with your translated copy.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::CONTENT_ACF ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'content_acf_' ),
			'message'       => __( 'Some translated pages contain ACF or custom fields that still match the original language.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Visitors on your translated pages will see untranslated text in ACF and custom fields from the original language.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Open the translated page in WordPress and translate the custom fields into that language.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::CONTENT_PLACEHOLDER ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'content_placeholder_' ),
			'message'       => __( 'Some translated pages or posts have shortcodes or placeholders that do not match the original: one is missing, extra, or used a different number of times.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'A missing shortcode usually means a form, button or widget disappeared from the translated page. A changed placeholder, such as a merge tag or a number code, can show raw codes to visitors.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Open the translated page, compare it with the original, and restore the shortcode or placeholder. If the difference is intended, use Ignore.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::CONTENT_LINKS ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'content_link_' ),
			'message'       => __( 'Some links inside translated pages still point to original default-language URLs.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Clicking links on a translated page can accidentally send visitors back to your default language.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Edit the translated page and update each link so it opens the matching page in that language.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::CONTENT_DRAFT ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_ONE_CLICK,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_SOURCE_ITEM,
			'legacy_keys'   => array( 'content_draft_' ),
			'message'       => __( 'Some translations exist but are saved as Draft or Pending Review instead of Published.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Unpublished page/post translations are hidden from live site visitors.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Open each draft translation in the WordPress editor and click Publish.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::CONTENT_SOURCE_UPDATED ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			'count_by'      => DUPCAP_Issue_Types::COUNT_SOURCE_ITEM,
			'legacy_keys'   => array( 'content_source_updated' ),
			'message'       => __( 'The default language was updated. Linked translations may need a matching update.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Visitors can see newer content in the default language while other languages still show the older version.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Open each translation below and update it (or use AI Translation), then rescan the issue.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::TERM_MISSING ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_CONTENT,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			'count_by'      => DUPCAP_Issue_Types::COUNT_SOURCE_ITEM,
			'legacy_keys'   => array( 'term_missing_' ),
			'message'       => __( 'Some categories or tags are missing translations.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Category and tag archive pages will show default language names when translations are missing.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Go to Posts → Categories (or Tags) and click "+" to add translations for each category.', 'duplicate-content-addon-for-polylang' ),
		);

		/* ---------- Elementor ---------- */

		$definitions[ DUPCAP_Issue_Types::ELEMENTOR_TEMPLATES ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_ELEMENTOR,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			'count_by'      => DUPCAP_Issue_Types::COUNT_SOURCE_ITEM,
			'legacy_keys'   => array( 'elementor_' ),
			'message'       => __( 'Elementor Library templates (Headers, Footers, Popups) are not set as translatable.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Your translated pages will continue displaying the default-language header and footer layout until templates are enabled for translation.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'In Polylang settings, turn on translations for Elementor Library (templates), then add translated versions of your headers and footers.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::ELEMENTOR_NONE ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_ELEMENTOR,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_PASS,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'elementor_none' ),
			'message'       => '',
			'why'           => '',
			'howto'         => '',
		);

		/* ---------- URLs and routing ---------- */

		$definitions[ DUPCAP_Issue_Types::URL_CONFIGURATION ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_URLS,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_CRITICAL,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_FROM_RESULTS,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'url_config_' ),
			'message'       => __( 'Polylang or WordPress URL settings need attention.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Incorrect URL settings prevent Polylang from routing visitors to the right language, making whole language sections unreachable.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Follow the fix link for each item below to update the specific Polylang or WordPress permalink setting.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::TRANSLATED_PERMALINKS ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_URLS,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_CRITICAL,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_FROM_RESULTS,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			// url_config_slug_suffix is longer than the url_config_ prefix above,
			// so longest-prefix matching routes it here and not to url_configuration.
			'legacy_keys'   => array( 'translated_permalink_', 'url_config_slug_suffix' ),
			'message'       => __( 'Some translated pages use automatic numbered URLs (like /about-2/).', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Numbered URLs work fine, but custom translated address slugs (like /a-propos/) look cleaner and perform better for SEO.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Click Review permalink to edit the page slug in WordPress, or click Ignore if the current address is acceptable.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::ROUTING_HEALTH ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_URLS,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_CRITICAL,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_FROM_RESULTS,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'url_route_' ),
			'fix'           => array(
				'admin_page' => 'options-permalink.php',
				'label'      => __( 'Rebuild URL rules', 'duplicate-content-addon-for-polylang' ),
			),
			'message'       => __( 'Some language URLs returned a broken 404 page error or timeout.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Broken language URLs hide content from visitors. (Note: Timeouts on local testing sites are usually temporary testing limits — verify in browser first).', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Go to Settings → Permalinks and click Save Changes to rebuild URL rules. If testing on localhost and the page works in your browser, click Ignore.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::URL_DUPE_SLUGS ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_URLS,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'url_dupe_slugs' ),
			'message'       => __( 'Pages in two different languages are sharing the exact same web address.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'When two languages share an address, browsers and caching plugins can serve the wrong language version to visitors.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Edit each page and give it a unique address slug in its own language (e.g. /tarifs/ instead of a duplicate /pricing/).', 'duplicate-content-addon-for-polylang' ),
		);

		/* ---------- Site setup ---------- */

		$definitions[ DUPCAP_Issue_Types::HOMEPAGE_TRANSLATIONS ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_CRITICAL,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			// One front page missing six languages is one page to fix, not six.
			// The card lists it as a single row, so the totals count it as one.
			'count_by'      => DUPCAP_Issue_Types::COUNT_FRONT_PAGE,
			'legacy_keys'   => array( 'lang_homepage' ),
			'message'       => __( 'The front page is missing a translation or has a blank layout in one or more languages.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Visitors switching languages on your homepage will see a blank page or get redirected back to the default homepage.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Open the homepage, click Translate for each missing language, and copy the page layout before translating.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::MENU_ASSIGNMENTS ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_CRITICAL,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_MENU_LOCATION,
			'legacy_keys'   => array( 'menu_' ),
			'message'       => __( 'Navigation menus are not assigned to menu locations for all languages.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Visitors browsing in secondary languages will see the default language menu or an empty header.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Go to Appearance → Menus → Manage Locations, and assign a menu for each active language.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::MENU_DEAD_LINKS ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_CRITICAL,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_TARGET_URL,
			'legacy_keys'   => array( 'menu_dead_links' ),
			'message'       => __( 'Some menu items link to pages that are missing or kept as Draft/Private.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Visitors who click these menu items will land on a 404 Page Not Found error.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Go to Appearance → Menus, and edit or remove links pointing to missing or unpublished pages.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::MENU_NONE ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_PASS,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'menu_none' ),
			'message'       => '',
			'why'           => '',
			'howto'         => '',
		);

		$definitions[ DUPCAP_Issue_Types::NAVIGATION ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_CRITICAL,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_NAVIGATION_ISSUE,
			'legacy_keys'   => array( 'nav_' ),
			'message'       => __( 'Block theme detected. Header and Navigation links in the Site Editor need review.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Block themes use Site Editor navigation. Missing translations or wrong-language links can confuse visitors after switching languages.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Open Appearance → Editor (Site Editor), select your Header template, and update navigation links for each language.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::STRINGS_MISSING ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_MANUAL,
			'owner'         => DUPCAP_Issue_Types::OWNER_TRANSLATOR,
			'count_by'      => DUPCAP_Issue_Types::COUNT_STRING_ITEM,
			'legacy_keys'   => array( 'string_' ),
			'message'       => __( 'Some site-wide text labels registered in Polylang are missing translations.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Global labels (like site taglines, button text, or widget headers) remain in the original language on translated pages.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Go to Languages → String translation in Polylang and fill in the translated text for each string.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::LANG_SWITCHER ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_ONE_CLICK,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'lang_switcher' ),
			'message'       => __( 'No language switcher was detected on your live website.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Without a language switcher button or dropdown, visitors cannot switch languages on your website.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Add the Polylang Language Switcher block/widget to your header/footer, or turn on a floating language switcher.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::LANG_PERMALINKS ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_WARNING,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_ONE_CLICK,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'lang_permalinks' ),
			'message'       => __( 'WordPress is set to Plain permalinks, so language address prefixes (like /fr/) cannot work.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Polylang adds language codes to URLs. With Plain permalinks enabled, language codes cannot be used.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Go to Settings → Permalinks, select "Post name", and click Save Changes.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::LANG_DEFAULT ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_CRITICAL,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'lang_default' ),
			'message'       => __( 'No default main language is set in Polylang.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Every translation links back to a primary default language. Without a main language set, translations cannot be linked.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Go to Languages → Languages in Polylang, and star/set your primary default language.', 'duplicate-content-addon-for-polylang' ),
		);

		$definitions[ DUPCAP_Issue_Types::LANG_SECONDARY ] = array(
			'section'       => DUPCAP_Issue_Types::SECTION_SITE,
			'severity'      => DUPCAP_Issue_Types::SEVERITY_CRITICAL,
			'severity_from' => DUPCAP_Issue_Types::SEVERITY_DECLARED,
			'effort'        => DUPCAP_Issue_Types::EFFORT_GUIDED,
			'owner'         => DUPCAP_Issue_Types::OWNER_ADMIN,
			'count_by'      => DUPCAP_Issue_Types::COUNT_ROW,
			'legacy_keys'   => array( 'lang_secondary' ),
			'message'       => __( 'Only one language is currently set up in Polylang.', 'duplicate-content-addon-for-polylang' ),
			'why'           => __( 'Translations only work when at least two languages exist on your site.', 'duplicate-content-addon-for-polylang' ),
			'howto'         => __( 'Go to Languages → Languages in Polylang and add a second language.', 'duplicate-content-addon-for-polylang' ),
		);

		return $definitions;
	}

	/**
	 * Build the Elementor bucket copy.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function build_elementor() {
		$definitions = self::all();
		$links       = isset( $definitions[ DUPCAP_Issue_Types::CONTENT_LINKS ]['message'] )
			? (string) $definitions[ DUPCAP_Issue_Types::CONTENT_LINKS ]['message']
			: '';

		return array(
			'missing_translation'           => array(
				'message' => __( 'Some documents are missing a translation in one or more languages. Visitors may see the wrong layout or language.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open the original Elementor template or page and add a translation for each missing language.', 'duplicate-content-addon-for-polylang' ),
			),
			'orphan_translation'            => array(
				'message' => __( 'These layouts exist in another language, but Polylang has not connected them to an original (default-language) version. Visitors may not get the right layout when switching languages.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open each layout in Polylang and link it to its original-language version, or create that original if it is missing. Delete the layout only if it is leftover and not needed.', 'duplicate-content-addon-for-polylang' ),
			),
			'invalid_json'                  => array(
				'message' => __( 'Elementor data could not be decoded. Re-save the document or restore a revision.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open the page in Elementor and save it again. If it still fails, restore a revision.', 'duplicate-content-addon-for-polylang' ),
			),
			'empty_translation'             => array(
				'message' => __( 'These translated pages have no Elementor widgets. The original has widgets in its page layout, but the translation is blank — visitors may see an empty page. Open each one in Elementor and copy the layout from the original, then translate the text. (Header and footer from Theme Builder are checked separately.)', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open the translation in Elementor, copy the layout from the original language, then translate the text.', 'duplicate-content-addon-for-polylang' ),
			),
			'missing_widget'                => array(
				'message' => __( 'Widgets from the original are missing in the translation layout.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open the translation in Elementor and add the widgets that exist on the original page.', 'duplicate-content-addon-for-polylang' ),
			),
			'missing_field'                 => array(
				'message' => __( 'Translatable text fields are empty in translations while the original has content.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open the translation in Elementor and fill in the empty text fields.', 'duplicate-content-addon-for-polylang' ),
			),
			'untranslated_field'            => array(
				'message' => __( 'These widgets still show the exact same words as the original language. Open each one in Elementor and translate the text — or ignore the warning if it should stay the same (for example a brand or product name).', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open the widget in Elementor and replace the copied original text with a translation. If the text is a brand name that should stay the same in every language, you can ignore that item.', 'duplicate-content-addon-for-polylang' ),
			),
			'broken_link'                   => array(
				'message' => $links,
				'howto'   => __( 'In Elementor, change each link so it points to the matching translated page instead of the default-language URL.', 'duplicate-content-addon-for-polylang' ),
			),
			'dead_link'                     => array(
				'message' => __( 'A menu or button links to a URL where no public page exists (missing, Draft, Private, or password-protected). Create or publish that page, or change the link.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'If the page already exists as Draft, Private, or password-protected, open Edit page and publish (or remove the password). If it is missing, create and publish it, translate it, then point the menu/button at that page — or change the link to a page that already exists.', 'duplicate-content-addon-for-polylang' ),
			),
			'untranslated_link'             => array(
				'message' => __( 'Navbar/footer labels may be translated, but their Link fields still use the default-language slug (for example /pricing/ instead of /precio/). Clicks open the wrong language page.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'In Elementor, edit the link field (not only the label) and choose the translated page URL.', 'duplicate-content-addon-for-polylang' ),
			),
			'wrong_language_link'           => array(
				'message' => __( 'A link in the translated layout resolves to a page in another language.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'In Elementor, change the link so it opens a page in the same language as the layout.', 'duplicate-content-addon-for-polylang' ),
			),
			'repeater_count_mismatch'       => array(
				'message' => __( 'Repeater rows in translations do not match the original document.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open the translation in Elementor and match the number of repeater rows to the original.', 'duplicate-content-addon-for-polylang' ),
			),
			'wrong_loop_template'           => array(
				'message' => __( 'The Loop Grid is using a template from another language, so titles and buttons inside the grid may not match the page language.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'In Elementor: Loop Grid → Template → select the Loop Item for this language.', 'duplicate-content-addon-for-polylang' ),
			),
			'missing_loop_item_translation' => array(
				'message' => __( 'A Loop Item used here has not been translated to the target language yet.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Translate the Loop Item in Polylang, then select it in Loop Grid → Template.', 'duplicate-content-addon-for-polylang' ),
			),
			'display_conditions'            => array(
				'message' => __( 'Theme Builder display conditions are incomplete: a translated header/footer may be missing conditions or conflict with the original, or no published default-language header covers Entire Site. Visitors may see the wrong template — or no header at all.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Set the default-language header to Entire Site in Theme Builder, fix language-specific conditions on translations, or install Language Switcher for Polylang and link the templates so each language shows its own.', 'duplicate-content-addon-for-polylang' ),
			),
			'invalid_structure'             => array(
				'message' => __( 'The Elementor JSON tree has structural problems such as duplicate or missing element IDs.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open the document in Elementor and re-save it. Recreate the layout if the structure is still broken.', 'duplicate-content-addon-for-polylang' ),
			),
			'other'                         => array(
				'message' => __( 'Additional Elementor translation problems were detected.', 'duplicate-content-addon-for-polylang' ),
				'howto'   => __( 'Open each listed document in Elementor and review the translation.', 'duplicate-content-addon-for-polylang' ),
			),
		);
	}
}
