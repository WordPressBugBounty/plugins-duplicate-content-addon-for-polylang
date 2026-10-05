<?php
/**
 * One counting rule for every surface, plus exact-repeat dedupe.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes dashboard results before anything renders them.
 *
 * Hooks `dupcap_dashboard_results` at priority 20. The adapter provides the
 * mapped rows at priority 10, and this runs on every render path — page load,
 * AJAX fragments and the Markdown export — unlike `dupcap_scan_results`, which
 * only fires when a scan is actually re-run.
 *
 * Exact-repeat fingerprint dedupe runs here. Semantic grouping (one missing
 * page linked from many places) is counted via count_for() and rendered by the
 * dashboard / Elementor insights helpers that call group_by_target_url().
 *
 * @since 2.3.0
 */
final class DUPCAP_Issue_Normalizer {

	/**
	 * Register the filter.
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'dupcap_dashboard_results', array( __CLASS__, 'filter_results' ), 20, 1 );
	}

	/**
	 * Whether the new issue layer is switched on.
	 *
	 * On since 2.3.0. The filter stays so a site hitting an unforeseen problem
	 * can fall back to the old counting and severity path without downgrading.
	 *
	 * @since 2.3.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Enable the normalized issue layer.
		 *
		 * @since 2.3.0
		 *
		 * @param bool $enabled Whether to normalize results and count through the registry.
		 */
		return (bool) apply_filters( 'dupcap_use_issue_normalizer', true );
	}

	/**
	 * Drop exact-repeat detail rows.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $results Mapped dashboard results.
	 * @return mixed
	 */
	public static function filter_results( $results ) {
		if ( ! is_array( $results ) || ! self::is_enabled() ) {
			return $results;
		}

		foreach ( $results as $index => $result ) {
			if ( ! is_array( $result ) || empty( $result['details'] ) || ! is_array( $result['details'] ) ) {
				continue;
			}

			$results[ $index ]['details'] = self::dedupe_details( $result['details'] );
		}

		return $results;
	}

	/**
	 * Remove detail rows that repeat an earlier row exactly.
	 *
	 * Duplicates survive today because the same finding can reach the dashboard
	 * through more than one path. The surviving row records how many times it
	 * was seen, and which languages it was seen for, so nothing is lost.
	 *
	 * Both added fields are flat: `sanitize_details()` silently empties a nested
	 * array, and `issue_languages` avoids colliding with the `languages` key
	 * menu rows already use for their assigned/unassigned map.
	 *
	 * @since 2.3.0
	 *
	 * @param array<int, mixed> $details Detail rows.
	 * @return array<int, mixed>
	 */
	public static function dedupe_details( array $details ) {
		$kept = array();
		$seen = array();

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				$kept[] = $detail;
				continue;
			}

			$print = self::fingerprint( $detail );

			if ( isset( $seen[ $print ] ) ) {
				$at = $seen[ $print ];

				$kept[ $at ]['occurrences'] = isset( $kept[ $at ]['occurrences'] ) ? (int) $kept[ $at ]['occurrences'] + 1 : 2;

				$language = self::language_of( $detail );
				if ( '' !== $language ) {
					$languages = isset( $kept[ $at ]['issue_languages'] ) ? (array) $kept[ $at ]['issue_languages'] : array();
					if ( ! in_array( $language, $languages, true ) ) {
						$languages[]                    = $language;
						$kept[ $at ]['issue_languages'] = array_values( $languages );
					}
				}

				continue;
			}

			$language = self::language_of( $detail );
			if ( '' !== $language ) {
				$detail['issue_languages'] = array( $language );
			}

			$seen[ $print ] = count( $kept );
			$kept[]         = $detail;
		}

		return $kept;
	}

	/**
	 * Stable fingerprint for one detail row.
	 *
	 * Built from what identifies the thing a user has to fix: the object, the
	 * language, the field and the target. Two rows with the same fingerprint are
	 * the same piece of work reported twice.
	 *
	 * @since 2.3.0
	 *
	 * @param array<string, mixed> $detail Detail row.
	 * @return string
	 */
	public static function fingerprint( array $detail ) {
		$object = 0;
		foreach ( array( 'post_id', 'template_id', 'translation_id', 'menu_id', 'content_id', 'term_id' ) as $field ) {
			if ( ! empty( $detail[ $field ] ) ) {
				$object = (int) $detail[ $field ];
				break;
			}
		}

		$target = '';
		foreach ( array( 'url', 'action_url', 'location', 'string_key' ) as $field ) {
			if ( ! empty( $detail[ $field ] ) && is_scalar( $detail[ $field ] ) ) {
				$target = (string) $detail[ $field ];
				break;
			}
		}

		$field_name = '';
		foreach ( array( 'field', 'code', 'issue', 'gap_type' ) as $field ) {
			if ( ! empty( $detail[ $field ] ) && is_scalar( $detail[ $field ] ) ) {
				$field_name = (string) $detail[ $field ];
				break;
			}
		}

		// An ignore key already identifies one dismissible row uniquely, so when
		// the check supplied one it is the most reliable fingerprint available.
		if ( ! empty( $detail['ignore_key'] ) && is_scalar( $detail['ignore_key'] ) ) {
			return md5( 'ignore:' . $detail['ignore_key'] );
		}

		$label = isset( $detail['label'] ) && is_scalar( $detail['label'] ) ? (string) $detail['label'] : '';

		return md5(
			implode(
				'|',
				array(
					(string) $object,
					self::language_of( $detail ),
					$field_name,
					$target,
					$label,
				)
			)
		);
	}

	/**
	 * Language slug a detail row refers to.
	 *
	 * @since 2.3.0
	 *
	 * @param array<string, mixed> $detail Detail row.
	 * @return string
	 */
	private static function language_of( array $detail ) {
		foreach ( array( 'language', 'lang_slug', 'slug' ) as $field ) {
			if ( ! empty( $detail[ $field ] ) && is_scalar( $detail[ $field ] ) ) {
				return (string) $detail[ $field ];
			}
		}

		return '';
	}

	/**
	 * How many issues one group represents.
	 *
	 * The single source for the Overview total, the tab badge, the section
	 * header and the Markdown export. Which unit to count is a fact about the
	 * issue type, so it is read from the registry rather than re-decided here.
	 *
	 * @since 2.3.0
	 *
	 * @param array<string, mixed> $group Issue group.
	 * @return int
	 */
	public static function count_for( array $group ) {
		$details = ( isset( $group['details'] ) && is_array( $group['details'] ) ) ? $group['details'] : array();
		$key     = isset( $group['dedupe_key'] ) ? (string) $group['dedupe_key'] : '';

		switch ( DUPCAP_Issue_Registry::count_mode( $key ) ) {
			case DUPCAP_Issue_Types::COUNT_MENU_LOCATION:
				return self::count_menu_locations( $details );

			case DUPCAP_Issue_Types::COUNT_STRING_ITEM:
				return self::count_string_items( $details );

			case DUPCAP_Issue_Types::COUNT_NAVIGATION_ISSUE:
				return self::count_navigation_issues( $details );

			case DUPCAP_Issue_Types::COUNT_SOURCE_ITEM:
				return self::count_source_items( $details, $key );

			case DUPCAP_Issue_Types::COUNT_TARGET_URL:
				return self::count_target_urls( $details );

			case DUPCAP_Issue_Types::COUNT_FRONT_PAGE:
				return self::count_front_pages( $details );

			case DUPCAP_Issue_Types::COUNT_ROW:
			default:
				return count( $details );
		}
	}

	/**
	 * One issue for the site's front page, however many languages it misses.
	 *
	 * The rows in this group are languages of the same page, so the card
	 * renders one row and the totals must agree with it.
	 *
	 * @since 2.3.0
	 *
	 * @param array<int, mixed> $details Detail rows.
	 * @return int
	 */
	public static function count_front_pages( array $details ) {
		return empty( $details ) ? 0 : 1;
	}

	/**
	 * One issue per distinct link target URL.
	 *
	 * Ten menu items pointing at the same missing /pricing/ page are one
	 * problem (create or publish that page), not ten.
	 *
	 * @since 2.3.0
	 *
	 * @param array<int, mixed> $details Detail rows.
	 * @return int
	 */
	public static function count_target_urls( array $details ) {
		return count( self::group_by_target_url( $details ) );
	}

	/**
	 * Group detail rows that share the same normalized link target.
	 *
	 * @since 2.3.0
	 *
	 * @param array<int, mixed> $details Detail or example rows.
	 * @return array<int, array{
	 *     norm: string,
	 *     url: string,
	 *     details: array<int, array<string, mixed>>,
	 *     languages: string[],
	 *     occurrence_count: int
	 * }>
	 */
	public static function group_by_target_url( array $details ) {
		$groups = array();

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}

			$url = self::target_url_from_detail( $detail );
			$key = self::normalize_target_key( $url );
			if ( '' === $key ) {
				$key = 'row:' . md5( (string) wp_json_encode( $detail ) );
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'norm'              => $key,
					'url'               => $url,
					'details'           => array(),
					'languages'         => array(),
					'occurrence_count'  => 0,
				);
			}

			$groups[ $key ]['details'][] = $detail;
			if ( '' === $groups[ $key ]['url'] && '' !== $url ) {
				$groups[ $key ]['url'] = $url;
			}

			$lang = self::language_of( $detail );
			if ( '' !== $lang ) {
				$groups[ $key ]['languages'][] = $lang;
			}
		}

		foreach ( $groups as $key => $group ) {
			$groups[ $key ]['languages']        = array_values( array_unique( array_filter( $group['languages'] ) ) );
			$groups[ $key ]['occurrence_count'] = count( $group['details'] );
		}

		return array_values( $groups );
	}

	/**
	 * Best-effort URL from a detail or Elementor example row.
	 *
	 * @since 2.3.0
	 *
	 * @param array<string, mixed> $detail Row.
	 * @return string
	 */
	public static function target_url_from_detail( array $detail ) {
		if ( ! empty( $detail['url'] ) ) {
			return trim( (string) $detail['url'] );
		}
		if ( ! empty( $detail['translated_value'] ) ) {
			return trim( (string) $detail['translated_value'] );
		}
		if ( ! empty( $detail['original_value'] ) ) {
			return trim( (string) $detail['original_value'] );
		}
		if ( ! empty( $detail['path'] ) ) {
			return home_url( (string) $detail['path'] );
		}

		return '';
	}

	/**
	 * Normalized compare key for a URL.
	 *
	 * @since 2.3.0
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function normalize_target_key( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		if ( class_exists( 'DUPCAP_Elementor_Fields' ) ) {
			return DUPCAP_Elementor_Fields::normalize_url_for_compare( $url );
		}

		return untrailingslashit( strtolower( $url ) );
	}

	/**
	 * One issue per theme location that is still missing a menu.
	 *
	 * Rows reach this point already consolidated per location by
	 * DUPCAP_Dashboard::consolidate_menu_details().
	 *
	 * @since 2.3.0
	 *
	 * @param array<int, mixed> $details Detail rows.
	 * @return int
	 */
	private static function count_menu_locations( array $details ) {
		$locations = array();

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}

			$missing = array();
			if ( ! empty( $detail['missing'] ) && is_array( $detail['missing'] ) ) {
				$missing = $detail['missing'];
			} elseif ( ! empty( $detail['missing_languages'] ) && is_array( $detail['missing_languages'] ) ) {
				$missing = $detail['missing_languages'];
			}

			if ( empty( $missing ) ) {
				continue;
			}

			$location = ! empty( $detail['location'] )
				? (string) $detail['location']
				: ( ! empty( $detail['location_label'] ) ? (string) $detail['location_label'] : '' );

			$locations[ '' !== $location ? $location : count( $locations ) ] = true;
		}

		return count( $locations );
	}

	/**
	 * One issue per registered string, however many languages it is missing in.
	 *
	 * @since 2.3.0
	 *
	 * @param array<int, mixed> $details Detail rows.
	 * @return int
	 */
	private static function count_string_items( array $details ) {
		$items = array();

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}

			if ( ! empty( $detail['string_key'] ) && is_scalar( $detail['string_key'] ) ) {
				$items[ (string) $detail['string_key'] ] = true;
				continue;
			}

			$title = ! empty( $detail['title'] ) ? (string) $detail['title'] : '';
			if ( '' === $title && ! empty( $detail['label'] ) ) {
				$title = (string) $detail['label'];
			}

			if ( '' !== $title ) {
				$items[ sanitize_title( $title ) ] = true;
			}
		}

		return $items ? count( $items ) : count( $details );
	}

	/**
	 * Navigation rows that describe a problem rather than a language status.
	 *
	 * @since 2.3.0
	 *
	 * @param array<int, mixed> $details Detail rows.
	 * @return int
	 */
	private static function count_navigation_issues( array $details ) {
		$issues = 0;

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}

			if ( ! empty( $detail['code'] ) && 'wrong_language_link' === $detail['code'] ) {
				++$issues;
				continue;
			}

			if ( isset( $detail['severity'] ) && 'warn' === $detail['severity'] ) {
				++$issues;
			}
		}

		// A navigation group with no per-row problems is still one thing to review.
		return $issues > 0 ? $issues : 1;
	}

	/**
	 * One issue per source post, page, term or template.
	 *
	 * A table that lists languages inside the row counts rows, not gaps: five
	 * templates missing six languages is five issues, not thirty.
	 *
	 * @since 2.3.0
	 *
	 * @param array<int, mixed> $details Detail rows.
	 * @param string            $key     Issue type key.
	 * @return int
	 */
	private static function count_source_items( array $details, $key ) {
		$items = array();
		$gaps  = 0;

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}

			$id = 0;
			if ( ! empty( $detail['post_id'] ) ) {
				$id = (int) $detail['post_id'];
			} elseif ( ! empty( $detail['template_id'] ) ) {
				$id = (int) $detail['template_id'];
			}

			if ( $id > 0 ) {
				$items[ $id ] = true;
				++$gaps;
			}
		}

		if ( ! empty( $items ) ) {
			return count( $items );
		}

		/*
		 * Reproduces DUPCAP_Dashboard::get_group_issue_count() exactly, including
		 * its edge case: when no row carries an id, the content families report
		 * the gap count, which is zero. Phase 3 revisits that; changing it here
		 * would move a number this phase is supposed to hold still.
		 */
		if ( in_array( $key, array( DUPCAP_Issue_Types::CONTENT_MISSING, DUPCAP_Issue_Types::CONTENT_DRAFT, DUPCAP_Issue_Types::TERM_MISSING ), true ) ) {
			return $gaps;
		}

		return count( $details );
	}
}
