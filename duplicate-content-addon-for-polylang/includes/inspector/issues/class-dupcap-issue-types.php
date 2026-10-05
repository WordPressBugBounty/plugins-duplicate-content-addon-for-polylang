<?php
/**
 * Issue type keys.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stable keys for every issue type the dashboard can render.
 *
 * These are the dedupe keys produced by
 * {@see DUPCAP_Dashboard_Adapter::dedupe_key_for()}. They are declared here as
 * constants so no other file has to type them as loose strings, and so a typo
 * becomes a fatal instead of a card that silently never renders.
 *
 * @since 2.3.0
 */
final class DUPCAP_Issue_Types {

	/* ---------- Content ---------- */

	const UNTRANSLATED_TYPES = 'untranslated_types';
	const CONTENT_NOLANG     = 'content_nolang';
	const CONTENT_MISSING    = 'content_missing';
	const CONTENT_IDENTICAL  = 'content_identical';
	const CONTENT_LINKS      = 'content_links';
	const CONTENT_DRAFT      = 'content_draft';
	const CONTENT_SOURCE_UPDATED = 'content_source_updated';
	const TERM_MISSING       = 'term_missing';

	/* ---------- Elementor ---------- */

	const ELEMENTOR_TEMPLATES = 'elementor_templates';
	const ELEMENTOR_NONE      = 'elementor_none';

	/* ---------- URLs and routing ---------- */

	const URL_CONFIGURATION     = 'url_configuration';
	const TRANSLATED_PERMALINKS = 'translated_permalinks';
	const ROUTING_HEALTH        = 'routing_health';
	const URL_DUPE_SLUGS        = 'url_dupe_slugs';

	/* ---------- Site setup ---------- */

	const HOMEPAGE_TRANSLATIONS = 'homepage_translations';
	const MENU_ASSIGNMENTS      = 'menu_assignments';
	const MENU_DEAD_LINKS       = 'menu_dead_links';
	const MENU_NONE             = 'menu_none';
	const NAVIGATION            = 'navigation';
	const STRINGS_MISSING       = 'strings_missing';
	const LANG_SWITCHER         = 'lang_switcher';
	const LANG_PERMALINKS       = 'lang_permalinks';
	const LANG_DEFAULT          = 'lang_default';
	const LANG_SECONDARY        = 'lang_secondary';

	/* ---------- Dashboard sections ---------- */

	/**
	 * Section keys, matching {@see DUPCAP_Dashboard::split_remaining_groups_by_section()}.
	 */
	const SECTION_CONTENT   = 'content';
	const SECTION_ELEMENTOR = 'elementor';
	const SECTION_URLS      = 'urls';
	const SECTION_SITE      = 'other';

	/* ---------- Counting modes ---------- */

	/**
	 * How one issue is counted for this type.
	 *
	 * These mirror the branches of
	 * {@see DUPCAP_Dashboard::get_group_issue_count()} / {@see DUPCAP_Issue_Normalizer::count_for()}.
	 * The Overview, the tab badge, the section header and the export all count
	 * the same way.
	 */
	const COUNT_ROW              = 'row';
	const COUNT_SOURCE_ITEM      = 'source_item';
	const COUNT_MENU_LOCATION    = 'menu_location';
	const COUNT_STRING_ITEM      = 'string_item';
	const COUNT_NAVIGATION_ISSUE = 'navigation_issue';
	/**
	 * One issue per distinct link target (normalized URL), even when the same
	 * missing page is linked from many menus, widgets or languages.
	 */
	const COUNT_TARGET_URL       = 'target_url';
	/**
	 * One issue for the whole group: every row is the same front page in a
	 * different language, and the card lists it as a single row.
	 */
	const COUNT_FRONT_PAGE       = 'front_page';

	/* ---------- Severity ---------- */

	/**
	 * The three levels, and the one test that decides each.
	 *
	 * CRITICAL — a visitor gets a 404, a blank render, or navigation in the
	 *            wrong language.
	 * WARNING  — the page loads, but some of its words are wrong.
	 * NOTICE   — no visitor impact.
	 *
	 * NOTICE maps onto the dashboard's existing `info` value rather than adding
	 * a fourth one, so severity filters, badges and CSS keep working unchanged.
	 */
	const SEVERITY_CRITICAL = 'crit';
	const SEVERITY_WARNING  = 'warn';
	const SEVERITY_NOTICE   = 'info';
	const SEVERITY_PASS     = 'pass';

	/**
	 * Where a type's severity comes from.
	 *
	 * Most types are one level by nature: a menu with no assignment is always
	 * wrong-language navigation. Three are not — routing, URL configuration and
	 * translated permalinks each emit rows at different levels from the same
	 * check, and flattening them to one declared level would either hide a real
	 * 404 or report a local-only timeout as a site-down emergency.
	 */
	const SEVERITY_DECLARED     = 'declared';
	const SEVERITY_FROM_RESULTS = 'from_results';

	/* ---------- Effort ---------- */

	const EFFORT_ONE_CLICK = 'one_click';
	const EFFORT_GUIDED    = 'guided';
	const EFFORT_MANUAL    = 'manual';

	/* ---------- Owner ---------- */

	const OWNER_ADMIN      = 'admin';
	const OWNER_TRANSLATOR = 'translator';

	/**
	 * Every issue type key.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::UNTRANSLATED_TYPES,
			self::CONTENT_NOLANG,
			self::CONTENT_MISSING,
			self::CONTENT_IDENTICAL,
			self::CONTENT_LINKS,
			self::CONTENT_DRAFT,
			self::CONTENT_SOURCE_UPDATED,
			self::TERM_MISSING,
			self::ELEMENTOR_TEMPLATES,
			self::ELEMENTOR_NONE,
			self::URL_CONFIGURATION,
			self::TRANSLATED_PERMALINKS,
			self::ROUTING_HEALTH,
			self::URL_DUPE_SLUGS,
			self::HOMEPAGE_TRANSLATIONS,
			self::MENU_ASSIGNMENTS,
			self::MENU_DEAD_LINKS,
			self::MENU_NONE,
			self::NAVIGATION,
			self::STRINGS_MISSING,
			self::LANG_SWITCHER,
			self::LANG_PERMALINKS,
			self::LANG_DEFAULT,
			self::LANG_SECONDARY,
		);
	}
}
