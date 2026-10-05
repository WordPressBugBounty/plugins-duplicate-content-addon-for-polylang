<?php
/**
 * Loads every check, runs them, and returns a summary + result list.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Scanner {

	const OPTION_KEY = 'dupcap_last_report';

	/**
	 * Run all checks, or all but the named check classes.
	 *
	 * Section scans pass the core checks that cannot produce rows for their
	 * section. Everything else still runs, including third-party checks.
	 *
	 * @param string[] $skip_classes Check class names to leave out.
	 * @return array { summary: array, results: array }
	 */
	public function scan( array $skip_classes = array() ) {
		$checks  = $this->load_checks();
		$results = array();

		foreach ( $checks as $check ) {
			if ( in_array( get_class( $check ), $skip_classes, true ) ) {
				continue;
			}

			/** @var DUPCAP_Check $check */
			$found = $check->run();
			if ( is_array( $found ) ) {
				foreach ( $found as $r ) {
					if ( $r instanceof DUPCAP_Result ) {
						$results[] = $r->to_array();
					}
				}
			}
		}

		/**
		 * Allow other add-ons to inject extra results.
		 *
		 * @param array $results Result arrays.
		 */
		$results = apply_filters( 'dupcap_scan_results', $results );

		$results = $this->reconcile_fixed( $results );

		return array(
			'summary' => self::summarize( $results ),
			'results' => $results,
		);
	}

	/**
	 * Read the last saved report from wp_options.
	 *
	 * @return array|null
	 */
	public static function get_saved_report() {
		$report = get_option( self::OPTION_KEY, null );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * Whether the site has completed at least one saved scan.
	 *
	 * @return bool
	 */
	public static function has_saved_scan() {
		$saved = self::get_saved_report();
		return is_array( $saved ) && ! empty( $saved['time'] );
	}

	/**
	 * Persist a scan report to wp_options.
	 *
	 * @param array $data Report data.
	 * @return array Saved report data with time set.
	 */
	public static function save_report( $data ) {
		$data['time'] = time();
		update_option( self::OPTION_KEY, $data, false );
		return $data;
	}

	/**
	 * Format a scan timestamp for display.
	 *
	 * Uses Polylang’s default language locale when available so month/day
	 * names match the site’s default language, not the admin user’s locale.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function format_scan_time( $timestamp ) {
		if ( ! $timestamp ) {
			return '';
		}

		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$timestamp = (int) $timestamp;

		$default_lang = self::get_default_pll_language();
		$locale       = self::resolve_default_display_locale( $default_lang );

		if ( '' === $locale && function_exists( 'get_locale' ) ) {
			$locale = self::normalize_wp_locale_code( get_locale() );
		}

		global $wp_locale;
		$wp_locale_backup = $wp_locale;
		$previous_pll     = self::pin_pll_language( $default_lang );

		if ( '' !== $locale ) {
			$wp_locale = new WP_Locale();
			self::pin_calendar_strings_for_locale( $wp_locale, $locale );
		}

		$formatted = self::format_timestamp_for_site( $format, $timestamp, null, $locale );

		$wp_locale = $wp_locale_backup;
		self::restore_pll_language( $previous_pll, $default_lang );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Site timezone from WordPress options (handles offset strings like "5:30").
	 *
	 * @return DateTimeZone
	 */
	protected static function get_site_timezone() {
		$timezone_string = get_option( 'timezone_string' );
		if ( is_string( $timezone_string ) && '' !== $timezone_string ) {
			try {
				return new DateTimeZone( $timezone_string );
			} catch ( Exception $e ) {
				// Fall through to offset parsing.
			}
		}

		$offset_tz = self::parse_gmt_offset_to_timezone( get_option( 'gmt_offset' ) );
		if ( null !== $offset_tz ) {
			try {
				return new DateTimeZone( $offset_tz );
			} catch ( Exception $e ) {
				// Fall through.
			}
		}

		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Convert a WordPress gmt_offset option value to ±HH:MM.
	 *
	 * WordPress may store offsets like "5:30" for UTC+5:30; casting to float yields 5.0.
	 *
	 * @param mixed $offset Raw gmt_offset option value.
	 * @return string|null
	 */
	protected static function parse_gmt_offset_to_timezone( $offset ) {
		if ( is_string( $offset ) ) {
			$offset = trim( $offset );
			if ( '' === $offset ) {
				return null;
			}

			if ( preg_match( '/^UTC([+-])(\d+)(?::(\d+))?$/i', $offset, $matches ) ) {
				$minutes = isset( $matches[3] ) ? (int) $matches[3] : 0;
				return sprintf( '%s%02d:%02d', $matches[1], (int) $matches[2], $minutes );
			}

			if ( preg_match( '/^([+-]?)(\d+)(?::(\d+))?$/', $offset, $matches ) ) {
				$sign    = '-' === $matches[1] ? '-' : '+';
				$minutes = isset( $matches[3] ) ? (int) $matches[3] : 0;
				return sprintf( '%s%02d:%02d', $sign, (int) $matches[2], $minutes );
			}
		}

		if ( is_numeric( $offset ) ) {
			$offset  = (float) $offset;
			$hours   = (int) $offset;
			$minutes = (int) abs( ( $offset - $hours ) * 60 );
			$sign    = $offset < 0 ? '-' : '+';

			return sprintf( '%s%02d:%02d', $sign, abs( $hours ), $minutes );
		}

		return null;
	}

	/**
	 * Format a Unix timestamp in the site timezone with localized calendar strings.
	 *
	 * @param string            $format    WordPress date/time format.
	 * @param int               $timestamp Unix timestamp.
	 * @param DateTimeZone|null $timezone  Site timezone.
	 * @param string            $locale    Optional locale for wp_date / meridiem.
	 * @return string
	 */
	protected static function format_timestamp_for_site( $format, $timestamp, $timezone = null, $locale = '' ) {
		if ( null === $timezone ) {
			$timezone = self::get_site_timezone();
		}

		$locale        = self::normalize_wp_locale_code( (string) $locale );
		$locale_filter = null;
		if ( '' !== $locale ) {
			$locale_filter = function () use ( $locale ) {
				return $locale;
			};
			add_filter( 'locale', $locale_filter, 999 );
			add_filter( 'determine_locale', $locale_filter, 999 );
		}

		$formatted = '';
		if ( function_exists( 'get_date_from_gmt' ) && function_exists( 'mysql2date' ) ) {
			$mysql = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'Y-m-d H:i:s' );
			if ( is_string( $mysql ) && '' !== $mysql ) {
				$formatted = mysql2date( $format, $mysql, true );
			}
		}

		if ( $locale_filter ) {
			remove_filter( 'locale', $locale_filter, 999 );
			remove_filter( 'determine_locale', $locale_filter, 999 );
		}

		return is_string( $formatted ) && '' !== $formatted ? $formatted : '';
	}

	/**
	 * Polylang default language object from the model (not current curlang).
	 *
	 * @return object|null
	 */
	protected static function get_default_pll_language() {
		if ( ! function_exists( 'PLL' ) ) {
			return null;
		}

		$pll = PLL();
		// is_callable(), not method_exists(): Polylang 3.8 forwards this through
		// PLL_Model::__call(), which method_exists() cannot see.
		if ( ! $pll || ! isset( $pll->model ) || ! is_callable( array( $pll->model, 'get_default_language' ) ) ) {
			return null;
		}

		try {
			$lang = $pll->model->get_default_language();
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_object( $lang ) ? $lang : null;
	}

	/**
	 * Locale string for formatting dates in the default Polylang language.
	 *
	 * @param object|null $default_lang Polylang default language object.
	 * @return string
	 */
	protected static function resolve_default_display_locale( $default_lang ) {
		if ( is_object( $default_lang ) ) {
			if ( method_exists( $default_lang, 'get_locale' ) ) {
				$locale = $default_lang->get_locale( 'raw' );
				if ( is_string( $locale ) && '' !== $locale ) {
					return self::normalize_wp_locale_code( $locale );
				}
			}

			if ( isset( $default_lang->locale ) && is_string( $default_lang->locale ) && '' !== $default_lang->locale ) {
				return self::normalize_wp_locale_code( (string) $default_lang->locale );
			}

			if ( isset( $default_lang->slug ) && is_string( $default_lang->slug ) && 'en' === $default_lang->slug ) {
				return 'en_US';
			}
		}

		if ( function_exists( 'pll_default_language' ) ) {
			$locale = pll_default_language( 'locale' );
			if ( is_string( $locale ) && '' !== $locale ) {
				return self::normalize_wp_locale_code( $locale );
			}
		}

		return '';
	}

	/**
	 * Pin Polylang curlang so its locale filter matches the default language.
	 *
	 * @param object|null $default_lang Default language object.
	 * @return object|null Previous curlang.
	 */
	protected static function pin_pll_language( $default_lang ) {
		if ( ! is_object( $default_lang ) || ! function_exists( 'PLL' ) ) {
			return null;
		}

		$pll = PLL();
		if ( ! $pll ) {
			return null;
		}

		$previous = isset( $pll->curlang ) ? $pll->curlang : null;
		$pll->curlang = $default_lang;

		return $previous;
	}

	/**
	 * Restore Polylang curlang after temporarily pinning the default language.
	 *
	 * @param object|null $previous     Previous curlang.
	 * @param object|null $default_lang Default language fallback when previous was unset.
	 * @return void
	 */
	protected static function restore_pll_language( $previous, $default_lang ) {
		if ( ! function_exists( 'PLL' ) ) {
			return;
		}

		$pll = PLL();
		if ( ! $pll ) {
			return;
		}

		if ( is_object( $previous ) ) {
			$pll->curlang = $previous;
			return;
		}

		if ( is_object( $default_lang ) ) {
			$pll->curlang = $default_lang;
		}
	}

	/**
	 * Normalize Polylang / W3C locale codes to WordPress format.
	 *
	 * @param string $locale Locale code.
	 * @return string
	 */
	protected static function normalize_wp_locale_code( $locale ) {
		$locale = self::sanitize_locale_string( $locale );
		if ( '' === $locale ) {
			return '';
		}

		return str_replace( '-', '_', $locale );
	}

	/**
	 * Replace translated calendar strings with locale-specific literals.
	 *
	 * Admin user locale (e.g. Italian) can leak into WP_Locale via gettext even when
	 * Polylang's default language is English and intl is unavailable.
	 *
	 * @param WP_Locale $wp_locale Global locale object.
	 * @param string    $locale    Target WordPress locale code.
	 * @return void
	 */
	protected static function pin_calendar_strings_for_locale( WP_Locale $wp_locale, $locale ) {
		$strings = self::get_builtin_calendar_strings( $locale );
		if ( ! is_array( $strings ) ) {
			return;
		}

		if ( isset( $strings['month'] ) ) {
			$wp_locale->month = $strings['month'];
		}

		if ( isset( $strings['month_genitive'] ) ) {
			$wp_locale->month_genitive = $strings['month_genitive'];
		}

		if ( isset( $strings['month_abbrev'] ) ) {
			$wp_locale->month_abbrev = $strings['month_abbrev'];
		}

		if ( isset( $strings['weekday'] ) ) {
			$wp_locale->weekday = $strings['weekday'];
		}

		if ( isset( $strings['weekday_abbrev'] ) ) {
			$wp_locale->weekday_abbrev = $strings['weekday_abbrev'];
		}

		if ( isset( $strings['meridiem'] ) ) {
			$wp_locale->meridiem = $strings['meridiem'];
		}
	}

	/**
	 * Built-in calendar strings keyed by locale (no gettext).
	 *
	 * @param string $locale WordPress locale code.
	 * @return array|null
	 */
	protected static function get_builtin_calendar_strings( $locale ) {
		$locale = self::normalize_wp_locale_code( $locale );
		if ( '' === $locale ) {
			return null;
		}

		$prefix = strtok( $locale, '_' );
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			return null;
		}

		if ( 'en' === $prefix ) {
			return self::english_calendar_strings();
		}

		return null;
	}

	/**
	 * English calendar strings (US).
	 *
	 * @return array
	 */
	protected static function english_calendar_strings() {
		$month = array(
			'01' => 'January',
			'02' => 'February',
			'03' => 'March',
			'04' => 'April',
			'05' => 'May',
			'06' => 'June',
			'07' => 'July',
			'08' => 'August',
			'09' => 'September',
			'10' => 'October',
			'11' => 'November',
			'12' => 'December',
		);

		return array(
			'month'           => $month,
			'month_genitive'  => $month,
			'month_abbrev'    => array(
				'January'   => 'Jan',
				'February'  => 'Feb',
				'March'     => 'Mar',
				'April'     => 'Apr',
				'May'       => 'May',
				'June'      => 'Jun',
				'July'      => 'Jul',
				'August'    => 'Aug',
				'September' => 'Sep',
				'October'   => 'Oct',
				'November'  => 'Nov',
				'December'  => 'Dec',
			),
			'weekday'         => array(
				0 => 'Sunday',
				1 => 'Monday',
				2 => 'Tuesday',
				3 => 'Wednesday',
				4 => 'Thursday',
				5 => 'Friday',
				6 => 'Saturday',
			),
			'weekday_abbrev'  => array(
				'Sunday'    => 'Sun',
				'Monday'    => 'Mon',
				'Tuesday'   => 'Tue',
				'Wednesday' => 'Wed',
				'Thursday'  => 'Thu',
				'Friday'    => 'Fri',
				'Saturday'  => 'Sat',
			),
			'meridiem'        => array(
				'AM' => 'AM',
				'PM' => 'PM',
				'am' => 'am',
				'pm' => 'pm',
			),
		);
	}

	/**
	 * Sanitize a locale code (WordPress 4.2+ helper when available).
	 *
	 * @param string $locale Raw locale.
	 * @return string
	 */
	protected static function sanitize_locale_string( $locale ) {
		if ( function_exists( 'sanitize_locale_name' ) ) {
			return sanitize_locale_name( $locale );
		}

		$locale = preg_replace( '/[^a-zA-Z0-9_-]/', '', $locale );
		return is_string( $locale ) ? $locale : '';
	}

	/**
	 * Mark results that were problems in the saved report but now pass.
	 *
	 * @param array $results Fresh result arrays.
	 * @return array
	 */
	protected function reconcile_fixed( $results ) {
		$saved = self::get_saved_report();
		if ( empty( $saved['results'] ) || ! is_array( $saved['results'] ) ) {
			return $results;
		}

		$was_problem = array();
		foreach ( $saved['results'] as $r ) {
			if ( empty( $r['id'] ) ) {
				continue;
			}
			$status = isset( $r['status'] ) ? $r['status'] : 'pass';
			if ( 'warning' === $status || 'critical' === $status ) {
				$was_problem[ $r['id'] ] = true;
			}
		}

		if ( empty( $was_problem ) ) {
			return $results;
		}

		foreach ( $results as $i => $r ) {
			if (
				isset( $r['id'], $r['status'] )
				&& 'pass' === $r['status']
				&& ! empty( $was_problem[ $r['id'] ] )
			) {
				$results[ $i ]['recently_fixed'] = true;
			}
		}

		return $results;
	}

	/**
	 * Instantiate the check classes.
	 *
	 * @return DUPCAP_Check[]
	 */
	protected function load_checks() {
		$dir = DUPCAP_INSPECTOR_DIR . 'checks/';

		require_once $dir . 'class-dupcap-check-languages.php';
		require_once $dir . 'class-dupcap-check-url-config.php';
		require_once $dir . 'class-dupcap-check-translated-permalinks.php';
		require_once DUPCAP_INSPECTOR_DIR . 'permalinks/class-dupcap-permalink-diagnostics.php';
		require_once $dir . 'class-dupcap-check-content.php';
		require_once $dir . 'class-dupcap-check-urls.php';
		require_once $dir . 'class-dupcap-check-menus.php';
		require_once $dir . 'class-dupcap-check-strings.php';
		require_once $dir . 'class-dupcap-check-elementor.php';
		require_once $dir . 'class-dupcap-check-switcher.php';

		$checks = array(
			new DUPCAP_Check_Languages(),
			new DUPCAP_Check_Url_Config(),
			new DUPCAP_Check_Translated_Permalinks(),
			new DUPCAP_Check_Content(),
			new DUPCAP_Check_Urls(),
			new DUPCAP_Check_Menus(),
			new DUPCAP_Check_Strings(),
			new DUPCAP_Check_Elementor(),
			new DUPCAP_Check_Switcher(),
		);

		/**
		 * Filter the list of check instances.
		 *
		 * @param DUPCAP_Check[] $checks Checks.
		 */
		return apply_filters( 'dupcap_checks', $checks );
	}

	/**
	 * Count results by status.
	 *
	 * @param array $results Result arrays.
	 * @return array
	 */
	public static function summarize( $results ) {
		$summary = array(
			'pass'     => 0,
			'info'     => 0,
			'warning'  => 0,
			'critical' => 0,
			'total'    => count( $results ),
		);
		foreach ( $results as $r ) {
			$status = isset( $r['status'] ) ? $r['status'] : 'pass';
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
		}
		return $summary;
	}
}
