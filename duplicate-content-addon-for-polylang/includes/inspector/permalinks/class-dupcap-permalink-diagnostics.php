<?php
/**
 * HTTP diagnostics for translated permalink rows.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Permalink_Diagnostics {

	/**
	 * Test a batch of permalink rows and attach routing metadata.
	 *
	 * @param array<int, array<string, mixed>> $rows       Rows from slug detection.
	 * @param array                            $languages  Active PLL_Language objects.
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrich_rows( array $rows, array $languages ) {
		if ( empty( $rows ) || ! class_exists( 'DUPCAP_Routing_Request' ) || ! class_exists( 'DUPCAP_Routing_Target' ) ) {
			return $rows;
		}

		$urls = array();

		foreach ( $rows as $index => $row ) {
			$url = isset( $row['permalink'] ) ? (string) $row['permalink'] : '';
			if ( '' === $url ) {
				continue;
			}
			$urls[ $index ] = $url;
		}

		if ( empty( $urls ) ) {
			return $rows;
		}

		$context   = self::routing_context( $languages );
		$responses = DUPCAP_Routing_Request::fetch_many( $urls );

		foreach ( $rows as $index => $row ) {
			if ( ! isset( $responses[ $index ] ) ) {
				$rows[ $index ] = self::apply_verdict(
					$row,
					array(
						'verdict' => DUPCAP_Routing_Request::VERDICT_UNTESTABLE,
						'message' => __( 'This URL could not be tested.', 'duplicate-content-addon-for-polylang' ),
					),
					array( 'code' => 0, 'error' => '', 'location' => '' )
				);
				continue;
			}

			$response = $responses[ $index ];
			$target   = new DUPCAP_Routing_Target(
				array(
					'url'        => (string) $row['permalink'],
					'lang'       => (string) $row['lang'],
					'lang_label' => isset( $row['lang_label'] ) ? (string) $row['lang_label'] : '',
					'type'       => DUPCAP_Routing_Target::TYPE_POST,
					'label'      => (string) $row['title'],
					'object_id'  => isset( $row['id'] ) ? (int) $row['id'] : 0,
				)
			);

			$verdict = DUPCAP_Routing_Request::classify( $target, $response, $context );
			$rows[ $index ] = self::apply_verdict( $row, $verdict, $response );
		}

		return $rows;
	}

	/**
	 * Map routing verdict to dashboard severity and user-facing copy.
	 *
	 * @param array<string, mixed> $row      Slug row.
	 * @param array<string, string> $verdict Classify output.
	 * @param array<string, mixed>  $response HTTP response.
	 * @return array<string, mixed>
	 */
	protected static function apply_verdict( array $row, array $verdict, array $response ) {
		$verdict_key = isset( $verdict['verdict'] ) ? (string) $verdict['verdict'] : '';
		$code        = isset( $response['code'] ) ? (int) $response['code'] : 0;
		$location    = isset( $response['location'] ) ? (string) $response['location'] : '';
		$base        = isset( $row['base'] ) ? (string) $row['base'] : '';
		$slug        = isset( $row['slug'] ) ? (string) $row['slug'] : '';
		$lang_label  = isset( $row['lang_label'] ) ? (string) $row['lang_label'] : '';

		$severity         = 'info';
		$routing_result   = 'working';
		$status_label     = __( 'Page loads correctly', 'duplicate-content-addon-for-polylang' );
		$summary          = '';
		$secondary_url    = '';
		$secondary_label  = '';

		switch ( $verdict_key ) {
			case DUPCAP_Routing_Request::VERDICT_OK:
				$severity       = 'info';
				$routing_result = 'working';
				$status_label   = __( 'Page loads correctly', 'duplicate-content-addon-for-polylang' );
				$summary        = sprintf(
					/* translators: 1: numbered slug, 2: base slug, 3: language name */
					__( 'This translation uses "%1$s" instead of "%2$s". The page is working, so no immediate action is required. Review this only if you want matching slugs across languages.', 'duplicate-content-addon-for-polylang' ),
					$slug,
					$base,
					$lang_label
				);
				break;

			case DUPCAP_Routing_Request::VERDICT_UNTESTABLE:
				$severity       = 'warn';
				$routing_result = 'untestable';
				$status_label   = __( 'Could not test URL', 'duplicate-content-addon-for-polylang' );
				$summary        = sprintf(
					/* translators: 1: numbered slug, 2: base slug */
					__( 'This translation uses "%1$s" instead of "%2$s". The automated test could not reach the URL — that does not necessarily mean it is broken.', 'duplicate-content-addon-for-polylang' ),
					$slug,
					$base
				);
				if ( ! empty( $verdict['message'] ) ) {
					$summary .= ' ' . $verdict['message'];
				}
				break;

			case DUPCAP_Routing_Request::VERDICT_NOT_FOUND:
				$severity         = 'crit';
				$routing_result   = 'not_found';
				$status_label     = __( 'Broken translated URL', 'duplicate-content-addon-for-polylang' );
				$summary          = __( 'This page is published, but the public URL returns "Page not found".', 'duplicate-content-addon-for-polylang' );
				$secondary_url    = admin_url( 'options-permalink.php' );
				$secondary_label  = __( 'Rebuild URL rules', 'duplicate-content-addon-for-polylang' );
				break;

			case DUPCAP_Routing_Request::VERDICT_WRONG_LANG:
				$severity         = 'crit';
				$routing_result   = 'wrong_language';
				$status_label     = __( 'Redirects to wrong language', 'duplicate-content-addon-for-polylang' );
				$summary          = ! empty( $verdict['message'] ) ? $verdict['message'] : __( 'Visitors opening this URL are being sent to another language.', 'duplicate-content-addon-for-polylang' );
				$secondary_url    = admin_url( 'admin.php?page=mlang_settings' );
				$secondary_label  = __( 'Review URL settings', 'duplicate-content-addon-for-polylang' );
				break;

			case DUPCAP_Routing_Request::VERDICT_SERVER_FAIL:
				$severity       = 'crit';
				$routing_result = 'server_error';
				$status_label   = __( 'Server error', 'duplicate-content-addon-for-polylang' );
				$summary        = ! empty( $verdict['message'] ) ? $verdict['message'] : __( 'The server returned an error for this URL.', 'duplicate-content-addon-for-polylang' );
				break;

			case DUPCAP_Routing_Request::VERDICT_REDIRECTED:
				$severity       = 'warn';
				$routing_result = 'redirected';
				$status_label   = __( 'Redirects elsewhere', 'duplicate-content-addon-for-polylang' );
				$summary        = ! empty( $verdict['message'] ) ? $verdict['message'] : __( 'This URL redirects to a different address.', 'duplicate-content-addon-for-polylang' );
				break;

			default:
				$severity       = 'warn';
				$routing_result = 'unknown';
				$status_label   = __( 'Needs review', 'duplicate-content-addon-for-polylang' );
				$summary        = ! empty( $verdict['message'] ) ? $verdict['message'] : __( 'Review this translated permalink.', 'duplicate-content-addon-for-polylang' );
				break;
		}

		$row['http_status']        = $code;
		$row['redirect_target']    = $location;
		$row['routing_result']     = $routing_result;
		$row['routing_message']    = isset( $verdict['message'] ) ? (string) $verdict['message'] : '';
		$row['severity']           = $severity;
		$row['status_label']       = $status_label;
		$row['message']            = $summary;
		$row['secondary_action_url']   = $secondary_url;
		$row['secondary_action_label'] = $secondary_label;

		return $row;
	}

	/**
	 * Build routing classifier context.
	 *
	 * @param array $languages Active PLL_Language objects.
	 * @return array<string, mixed>
	 */
	public static function routing_context( array $languages ) {
		$force_lang = 1;
		if ( function_exists( 'PLL' ) && PLL() && isset( PLL()->options ) && is_array( PLL()->options ) && isset( PLL()->options['force_lang'] ) ) {
			$force_lang = (int) PLL()->options['force_lang'];
		}

		$homes = array();
		foreach ( $languages as $language ) {
			if ( is_object( $language ) && method_exists( $language, 'get_home_url' ) ) {
				$homes[] = (string) $language->get_home_url();
			}
		}
		$homes[] = home_url( '/' );

		return array(
			'languages'  => $languages,
			'force_lang' => $force_lang,
			'homes'      => array_values( array_unique( array_filter( $homes ) ) ),
		);
	}

	/**
	 * Display path for a URL (path + query).
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	public static function display_path( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' === $path ) {
			$path = '/';
		}

		return '' !== $query ? $path . '?' . $query : $path;
	}

	/**
	 * Worst severity from a list of detail rows.
	 *
	 * @param array<int, array<string, mixed>> $details Detail rows.
	 * @return string info|warning|critical
	 */
	public static function worst_result_status( array $details ) {
		$worst = 'info';

		foreach ( $details as $detail ) {
			$item = isset( $detail['severity'] ) ? (string) $detail['severity'] : 'info';
			if ( 'crit' === $item || 'critical' === $item ) {
				return DUPCAP_Result::CRITICAL;
			}
			if ( 'warn' === $item || 'warning' === $item ) {
				$worst = DUPCAP_Result::WARNING;
			}
		}

		if ( DUPCAP_Result::WARNING === $worst ) {
			return DUPCAP_Result::WARNING;
		}

		return DUPCAP_Result::INFO;
	}

	/**
	 * Re-run numbered-slug detection with live HTTP and replace that card in the
	 * saved main report. Called from the manual Test URLs path only.
	 *
	 * @since 2.3.0
	 *
	 * @return bool Whether the main report was updated.
	 */
	public static function merge_http_into_main_report() {
		if ( ! class_exists( 'DUPCAP_Scanner' ) ) {
			return false;
		}

		$check_file = DUPCAP_INSPECTOR_DIR . 'checks/class-dupcap-check-translated-permalinks.php';
		if ( ! class_exists( 'DUPCAP_Check_Translated_Permalinks' ) && file_exists( $check_file ) ) {
			require_once $check_file;
		}
		if ( ! class_exists( 'DUPCAP_Check_Translated_Permalinks' ) ) {
			return false;
		}

		$main = DUPCAP_Scanner::get_saved_report();
		if ( ! is_array( $main ) || ! isset( $main['results'] ) || ! is_array( $main['results'] ) ) {
			return false;
		}

		$check   = new DUPCAP_Check_Translated_Permalinks();
		$fresh   = $check->run_with_http();
		$fresh_rows = array();
		foreach ( $fresh as $result ) {
			if ( $result instanceof DUPCAP_Result ) {
				$fresh_rows[] = $result->to_array();
			}
		}

		$kept = array();
		foreach ( $main['results'] as $row ) {
			$id = ( is_array( $row ) && isset( $row['id'] ) ) ? (string) $row['id'] : '';
			if ( 'translated_permalink_review' === $id || 0 === strpos( $id, 'translated_permalink_' ) ) {
				continue;
			}
			$kept[] = $row;
		}

		foreach ( $fresh_rows as $row ) {
			$kept[] = $row;
		}

		$main['results'] = $kept;

		$summary = array(
			'pass'     => 0,
			'warning'  => 0,
			'critical' => 0,
			'total'    => count( $kept ),
		);
		foreach ( $kept as $row ) {
			$status = ( is_array( $row ) && isset( $row['status'] ) ) ? (string) $row['status'] : 'pass';
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
		}
		$main['summary'] = $summary;

		update_option( DUPCAP_Scanner::OPTION_KEY, $main, false );

		return true;
	}
}
