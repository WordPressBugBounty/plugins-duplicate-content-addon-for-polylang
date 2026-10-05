<?php
/**
 * String translation checks: every string registered in Polylang's
 * Languages → Translations screen that has no translation in a secondary language.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Check_Strings extends DUPCAP_Check {

	protected $group = 'String Translation';

	/**
	 * Registered strings that are the same in every language by design.
	 * Keys are "context|name" as registered by Polylang.
	 *
	 * @var string[]
	 */
	const SKIPPED_STRINGS = array(
		'WordPress|date_format',
		'WordPress|time_format',
	);

	public function run() {
		$results   = array();
		$languages = $this->active_languages();
		$default   = $this->default_lang();

		if ( count( $languages ) < 2 ) {
			return $results;
		}

		$strings = $this->registered_strings();
		if ( empty( $strings ) ) {
			return $results;
		}

		foreach ( $languages as $lang ) {
			$slug = $this->lang_slug( $lang );
			if ( $slug === $default ) {
				continue;
			}

			$lang_name  = is_object( $lang ) && ! empty( $lang->name )
				? (string) $lang->name
				: DUPCAP_Elementor_Fields::language_label( $slug );
			$lang_label = $this->lang_label( $lang );
			$translator = $this->translator( $lang );

			if ( null === $translator ) {
				continue;
			}

			$details = array();
			foreach ( $strings as $item ) {
				if ( $this->has_translation( $translator, $item['string'] ) ) {
					continue;
				}

				$ignore_key = DUPCAP_Ignored_Issues::string_key_for( $item['key'] );
				if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
					continue;
				}

				$details[] = array(
					'label'        => sprintf(
						/* translators: 1: language label with code, 2: string name */
						__( '%1$s — %2$s', 'duplicate-content-addon-for-polylang' ),
						$lang_label,
						$item['title']
					),
					'message'      => sprintf(
						/* translators: 1: string label, 2: language name with code */
						__( '"%1$s" has no translation in "%2$s".', 'duplicate-content-addon-for-polylang' ),
						$item['title'],
						$lang_label
					),
					'ignore_key'   => $ignore_key,
					'string_key'   => $item['key'],
					'string_name'  => $item['name'],
					'string_group' => $item['context'],
					'lang_slug'    => $slug,
					'lang_name'    => $lang_name,
					'title'        => $item['title'],
				);
			}

			if ( ! empty( $details ) ) {
				$results[] = $this->result(
					'string_' . $slug,
					DUPCAP_Result::WARNING,
					/* translators: %s: language name with code */
					sprintf( __( 'Strings not translated in "%s"', 'duplicate-content-addon-for-polylang' ), $lang_label ),
					sprintf(
						/* translators: 1: number of strings, 2: language name with code */
						_n(
							'%1$d registered string has no translation in "%2$s".',
							'%1$d registered strings have no translation in "%2$s".',
							count( $details ),
							'duplicate-content-addon-for-polylang'
						),
						count( $details ),
						$lang_label
					)
				)
					->with_details( $details );
			} else {
				$results[] = $this->result(
					'string_' . $slug,
					DUPCAP_Result::PASS,
					/* translators: %s: language name with code */
					sprintf( __( 'Site strings translated in "%s"', 'duplicate-content-addon-for-polylang' ), $lang_label ),
					__( 'All registered strings are translated.', 'duplicate-content-addon-for-polylang' )
				);
			}
		}

		return $results;
	}

	/**
	 * Strings listed on Polylang's Languages → Translations screen.
	 *
	 * Polylang builds this list on every request from PLL_Translate_Option
	 * (site title, tagline…), active widgets and pll_register_string() calls.
	 * It is not stored anywhere, so we read the same in-memory list its table uses.
	 *
	 * @return array<int, array<string, string>>
	 */
	protected function registered_strings() {
		$raw = array();
		if ( class_exists( 'PLL_Admin_Strings' ) && is_callable( array( 'PLL_Admin_Strings', 'get_strings' ) ) ) {
			try {
				$raw = PLL_Admin_Strings::get_strings();
			} catch ( \Throwable $e ) {
				$raw = array();
			}
		}

		// Older or unexpected setups: fall back to the two core options.
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			$raw = array(
				array( 'name' => 'blogname', 'string' => get_option( 'blogname' ), 'context' => 'WordPress' ),
				array( 'name' => 'blogdescription', 'string' => get_option( 'blogdescription' ), 'context' => 'WordPress' ),
			);
		}

		$core_titles = array(
			'blogname'        => __( 'Site Title', 'duplicate-content-addon-for-polylang' ),
			'blogdescription' => __( 'Tagline', 'duplicate-content-addon-for-polylang' ),
		);

		$strings = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['string'] ) || ! is_scalar( $entry['string'] ) ) {
				continue;
			}

			$string  = (string) $entry['string'];
			$name    = isset( $entry['name'] ) ? (string) $entry['name'] : '';
			$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';

			// Strings with no letters ("#", "0", "|") have nothing to translate.
			if ( ! preg_match( '/\p{L}/u', $string ) || in_array( $context . '|' . $name, self::SKIPPED_STRINGS, true ) ) {
				continue;
			}

			$is_core = 'WordPress' === $context && isset( $core_titles[ $name ] );
			$title   = $is_core ? $core_titles[ $name ] : wp_html_excerpt( wp_strip_all_tags( $string ), 80, '…' );
			if ( '' === trim( $title ) ) {
				$title = '' !== $name ? $name : __( 'Site String', 'duplicate-content-addon-for-polylang' );
			}

			$strings[] = array(
				// Core keys stay "blogname"/"blogdescription" so existing ignores keep working.
				'key'     => $is_core ? $name : md5( $context . '|' . $string ),
				'title'   => $title,
				'name'    => $name,
				'context' => $context,
				'string'  => $string,
			);
		}

		return $strings;
	}

	/**
	 * Load a language's saved string translations the way Polylang's table does.
	 *
	 * @param mixed $lang Language object.
	 * @return PLL_MO|null
	 */
	protected function translator( $lang ) {
		if ( ! is_object( $lang ) || empty( $lang->term_id ) || ! class_exists( 'PLL_MO' ) ) {
			return null;
		}

		try {
			$mo = new PLL_MO();
			$mo->import_from_db( $lang );
		} catch ( \Throwable $e ) {
			return null;
		}

		return $mo;
	}

	/**
	 * Whether a saved translation exists. A translation equal to the original
	 * (e.g. a brand name) counts as translated, as on Polylang's own screen.
	 *
	 * @param PLL_MO $mo     Loaded translations.
	 * @param string $string Original string.
	 * @return bool
	 */
	protected function has_translation( $mo, $string ) {
		if ( method_exists( $mo, 'translate_if_any' ) ) {
			return '' !== $mo->translate_if_any( $string );
		}

		$entry = $mo->translate_entry( new Translation_Entry( array( 'singular' => $string ) ) );
		return $entry instanceof Translation_Entry && ! empty( $entry->translations ) && '' !== (string) $entry->translations[0];
	}
}
