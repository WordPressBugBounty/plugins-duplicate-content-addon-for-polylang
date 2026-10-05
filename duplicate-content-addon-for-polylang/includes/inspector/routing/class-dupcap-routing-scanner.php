<?php
/**
 * Multilingual routing / 404 sweep.
 *
 * Slow pass: this makes real HTTP requests and must never run inside
 * DUPCAP_Scanner::load_checks(). It has its own AJAX endpoint and its own saved
 * option, and merges into the main results through the dupcap_scan_results
 * filter.
 *
 * The governing rule for this whole class: never report PASS for something that
 * was not actually tested.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Routing_Scanner {

	const OPTION_KEY = 'dupcap_routing_last_report';

	/**
	 * Transient guarding against repeated rescans.
	 */
	const RATE_LIMIT_KEY = 'dupcap_routing_rate_limit';

	/**
	 * Seconds between user-triggered sweeps.
	 *
	 * The sweep is behind a dedicated button that a user can press repeatedly, and
	 * each press costs up to 24 HTTP requests against their own host. When the
	 * window is active, run() returns the saved report plus a "try again in N
	 * seconds" message, which the dashboard shows rather than failing silently.
	 */
	const RATE_LIMIT_SECONDS = 300;

	/**
	 * Seconds a saved report stays fresh.
	 */
	const CACHE_SECONDS = 43200;

	/**
	 * Hard cap on HTTP requests per scan, regardless of language count.
	 */
	const MAX_TARGETS = 24;

	/**
	 * Group label used on every routing result.
	 */
	const GROUP = 'Routing';

	/**
	 * Register the merge filter.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'dupcap_scan_results', array( __CLASS__, 'inject_saved_results' ), 10, 1 );
	}

	/* ---------- Persistence ---------- */

	/**
	 * Read the saved report.
	 *
	 * @since 2.2.0
	 *
	 * @return array|null
	 */
	public static function get_saved_report() {
		$report = get_option( self::OPTION_KEY, null );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * Save a report.
	 *
	 * @since 2.2.0
	 *
	 * @param array $report Report.
	 * @return array
	 */
	public static function save_report( array $report ) {
		$report['time'] = time();
		update_option( self::OPTION_KEY, $report, false );
		return $report;
	}

	/**
	 * Whether the saved report is still within its cache window.
	 *
	 * @since 2.2.0
	 *
	 * @return bool
	 */
	public static function has_fresh_report() {
		$report = self::get_saved_report();

		if ( ! is_array( $report ) || empty( $report['time'] ) ) {
			return false;
		}

		return ( time() - (int) $report['time'] ) < self::CACHE_SECONDS;
	}

	/**
	 * Seconds remaining before another rescan is allowed. Zero when allowed now.
	 *
	 * @since 2.2.0
	 *
	 * @return int
	 */
	public static function rate_limit_remaining() {
		$until = (int) get_transient( self::RATE_LIMIT_KEY );

		if ( $until <= 0 ) {
			return 0;
		}

		return max( 0, $until - time() );
	}

	/**
	 * Start the rate-limit window.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	protected static function start_rate_limit() {
		set_transient( self::RATE_LIMIT_KEY, time() + self::RATE_LIMIT_SECONDS, self::RATE_LIMIT_SECONDS );
	}

	/* ---------- Merge into the main scan ---------- */

	/**
	 * Merge the saved routing results into the main scan results.
	 *
	 * This is the only internal consumer of dupcap_scan_results. Results are
	 * already stored as arrays in the saved report, matching what
	 * DUPCAP_Scanner::scan() produces.
	 *
	 * @since 2.2.0
	 *
	 * @param array $results Main scan results.
	 * @return array
	 */
	public static function inject_saved_results( $results ) {
		if ( ! is_array( $results ) ) {
			return $results;
		}

		$report = self::get_saved_report();

		if ( ! is_array( $report ) || empty( $report['results'] ) || ! is_array( $report['results'] ) ) {
			return $results;
		}

		$existing = array();
		foreach ( $results as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$existing[ (string) $row['id'] ] = true;
			}
		}

		foreach ( $report['results'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}

			// Defensive: merge_into_main_report() may already have placed these rows.
			if ( isset( $existing[ (string) $row['id'] ] ) ) {
				continue;
			}

			$results[] = $row;
		}

		return self::apply_ignores_to_results( $results );
	}

	/**
	 * Replace the routing rows inside the saved main report.
	 *
	 * The dashboard normally renders from 'dupcap_last_report' without re-running
	 * checks, and dupcap_scan_results only fires during a real scan. Merging here
	 * lets a routing sweep update the dashboard without forcing a full rescan
	 * (which would drag the Elementor deep scan along with it).
	 *
	 * @since 2.2.0
	 *
	 * @param array $report Routing report.
	 * @return bool Whether the main report was updated.
	 */
	public static function merge_into_main_report( array $report ) {
		if ( ! class_exists( 'DUPCAP_Scanner' ) ) {
			return false;
		}

		$main = DUPCAP_Scanner::get_saved_report();

		if ( ! is_array( $main ) || ! isset( $main['results'] ) || ! is_array( $main['results'] ) ) {
			return false;
		}

		$kept = array();

		foreach ( $main['results'] as $row ) {
			$id = ( is_array( $row ) && isset( $row['id'] ) ) ? (string) $row['id'] : '';
			if ( '' !== $id && 0 === strpos( $id, 'url_route_' ) ) {
				continue;
			}
			$kept[] = $row;
		}

		$rows = ( isset( $report['results'] ) && is_array( $report['results'] ) ) ? $report['results'] : array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$kept[] = $row;
			}
		}

		$main['results'] = $kept;

		// Recompute the summary: DUPCAP_Scanner::summarize() is protected and
		// non-static, and stale counts would desync the header from the cards.
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

		// Written directly rather than through save_report(), which would stamp a
		// fresh 'time' and claim the main checks had just re-run. Only the routing
		// rows changed, so the main scan timestamp must stand.
		update_option( DUPCAP_Scanner::OPTION_KEY, $main, false );

		return true;
	}

	/* ---------- Scanning ---------- */

	/**
	 * Whether a routing scan can be attempted at all.
	 *
	 * @since 2.2.0
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'PLL' ) && function_exists( 'pll_languages_list' );
	}

	/**
	 * Run the sweep and return a report array.
	 *
	 * @since 2.2.0
	 *
	 * @return array
	 */
	public function scan() {
		$languages = $this->active_languages();

		if ( count( $languages ) < 2 ) {
			return array(
				'results'           => array(),
				'targets_truncated' => false,
				'skipped'           => 'single_language',
			);
		}

		// Guard 1: can this server reach itself at all?
		$probe = DUPCAP_Routing_Request::fetch( home_url( '/' ) );

		if ( 0 === (int) $probe['code'] ) {
			return array(
				'results'           => array( $this->untestable_result( (string) $probe['error'] ) ),
				'targets_truncated' => false,
				'skipped'           => 'loopback_blocked',
			);
		}

		$targets = DUPCAP_Routing_Target::for_languages( $languages, self::MAX_TARGETS );

		if ( empty( $targets ) ) {
			return array(
				'results'           => array(),
				'targets_truncated' => false,
				'skipped'           => 'no_targets',
			);
		}

		$urls = array();
		foreach ( $targets as $index => $target ) {
			$urls[ $index ] = $target->url;
		}

		$responses = DUPCAP_Routing_Request::fetch_many( $urls );

		$context = array(
			'languages'  => $languages,
			'force_lang' => (int) $this->force_lang(),
			'homes'      => $this->home_urls( $languages ),
		);

		$by_lang     = array();
		$all_codes   = array();
		$cache_hits  = 0;
		$default_ok  = false;
		$default_lang = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

		foreach ( $targets as $index => $target ) {
			$response = isset( $responses[ $index ] ) ? $responses[ $index ] : array( 'code' => 0, 'error' => '', 'headers' => array(), 'location' => '' );
			$verdict  = DUPCAP_Routing_Request::classify( $target, $response, $context );

			if ( class_exists( 'DUPCAP_Ignored_Issues' ) ) {
				$ignore_key = DUPCAP_Ignored_Issues::url_key( 'route_' . $target->type, $target->lang, $target->url );
				if ( DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
					continue;
				}
			}

			$all_codes[] = (int) $response['code'];

			if ( DUPCAP_Routing_Request::is_cache_hit( $response ) ) {
				$cache_hits++;
			}

			if ( $target->lang === $default_lang
				&& DUPCAP_Routing_Target::TYPE_HOME === $target->type
				&& DUPCAP_Routing_Request::VERDICT_OK === $verdict['verdict'] ) {
				$default_ok = true;
			}

			if ( ! isset( $by_lang[ $target->lang ] ) ) {
				$by_lang[ $target->lang ] = array(
					'label'   => $target->lang_label,
					'rows'    => array(),
					'failed'  => 0,
					'blocked' => 0,
				);
			}

			$by_lang[ $target->lang ]['rows'][] = array(
				'target'   => $target,
				'response' => $response,
				'verdict'  => $verdict,
			);

			if ( in_array(
				$verdict['verdict'],
				array(
					DUPCAP_Routing_Request::VERDICT_NOT_FOUND,
					DUPCAP_Routing_Request::VERDICT_WRONG_LANG,
					DUPCAP_Routing_Request::VERDICT_SERVER_FAIL,
					DUPCAP_Routing_Request::VERDICT_REDIRECTED,
				),
				true
			) ) {
				$by_lang[ $target->lang ]['failed']++;
			}

			if ( DUPCAP_Routing_Request::VERDICT_UNTESTABLE === $verdict['verdict'] ) {
				$by_lang[ $target->lang ]['blocked']++;
			}
		}

		// Guard 2: whole site gated (coming-soon, maintenance, HTTP auth).
		if ( ! $default_ok && $this->all_same_failure( $all_codes ) ) {
			return array(
				'results'           => array( $this->site_gated_result( $all_codes[0] ) ),
				'targets_truncated' => false,
				'skipped'           => 'site_gated',
			);
		}

		$results = array();

		foreach ( $by_lang as $lang => $data ) {
			if ( empty( $data['rows'] ) ) {
				continue;
			}
			$results[] = $this->language_result( $lang, $data );
		}

		return array(
			'results'           => $results,
			'targets_truncated' => count( $targets ) >= self::MAX_TARGETS,
			'cache_hits'        => $cache_hits,
			'tested'            => count( $targets ),
		);
	}

	/**
	 * Run a scan, honouring the rate limit, and save the report.
	 *
	 * @since 2.2.0
	 *
	 * @param bool $force Ignore the freshness window (still honours the rate limit).
	 * @return array{report: array, ran: bool, message: string}
	 */
	public static function run( $force = false ) {
		if ( ! self::is_available() ) {
			return array(
				'report'  => array(),
				'ran'     => false,
				'message' => __( 'Polylang is not active, so URLs cannot be tested.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		$wait = self::rate_limit_remaining();

		if ( $wait > 0 ) {
			return array(
				'report'  => (array) self::get_saved_report(),
				'ran'     => false,
				'message' => sprintf(
					/* translators: %d: number of seconds */
					_n(
						'URL testing was run recently. Try again in %d second.',
						'URL testing was run recently. Try again in %d seconds.',
						$wait,
						'duplicate-content-addon-for-polylang'
					),
					$wait
				),
			);
		}

		if ( ! $force && self::has_fresh_report() ) {
			return array(
				'report'  => (array) self::get_saved_report(),
				'ran'     => false,
				'message' => __( 'Showing the most recent URL test results.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		self::start_rate_limit();

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- URL test may exceed default PHP timeout.
			@set_time_limit( 300 );
		}

		try {
			$report = ( new self() )->scan();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Error logging is allowed.
				error_log( 'DUPCAP routing scan failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
			}

			return array(
				'report'  => (array) self::get_saved_report(),
				'ran'     => false,
				'message' => __( 'The URL test could not finish. Your previous results are still shown.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		return array(
			'report'  => self::save_report( $report ),
			'ran'     => true,
			'message' => __( 'URL testing finished.', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/* ---------- Result builders ---------- */

	/**
	 * One card per language, one detail row per tested URL.
	 *
	 * @since 2.2.0
	 *
	 * @param string $lang Language slug.
	 * @param array  $data Collected rows for this language.
	 * @return array
	 */
	protected function language_result( $lang, array $data ) {
		$details = array();

		foreach ( $data['rows'] as $row ) {
			$target   = $row['target'];
			$verdict  = $row['verdict'];
			$response = $row['response'];
			$code     = (int) $response['code'];
			$outcome  = isset( $verdict['verdict'] ) ? (string) $verdict['verdict'] : '';

			// Healthy URLs stay out of the issue list — showing "Loaded normally"
			// next to Rebuild / Ignore confuses users.
			if ( DUPCAP_Routing_Request::VERDICT_OK === $outcome ) {
				continue;
			}

			$message = $verdict['message'];

			if ( DUPCAP_Routing_Request::is_cache_hit( $response ) ) {
				$message .= ' ' . __( 'This response came from a cache, so it may not reflect the current settings. Clear your cache and test again.', 'duplicate-content-addon-for-polylang' );
			}

			$action_url   = '';
			$action_label = '';

			// Only attach fix actions for confirmed routing failures — not for
			// timeouts the user must verify in a browser.
			if ( DUPCAP_Routing_Request::VERDICT_UNTESTABLE !== $outcome ) {
				$fix          = $target->fix();
				$action_url   = isset( $fix['url'] ) ? (string) $fix['url'] : '';
				$action_label = isset( $fix['label'] ) ? (string) $fix['label'] : '';
			}

			$lang_name = '' !== (string) $target->lang_label
				? (string) $target->lang_label
				: (string) $data['label'];
			$row_label = '' !== $lang_name
				? sprintf(
					/* translators: 1: target type such as Home page, 2: language name with code */
					__( '%1$s — %2$s', 'duplicate-content-addon-for-polylang' ),
					$target->label,
					$lang_name
				)
				: $target->label;

			$details[] = array(
				'label'        => $row_label,
				'message'      => $message,
				'url'          => $target->url,
				'http_status'  => $code,
				'verdict'      => $outcome,
				'language'     => $lang,
				'action_url'   => $action_url,
				'action_label' => $action_label,
				'ignore_key'   => DUPCAP_Ignored_Issues::url_key( 'route_' . $target->type, $lang, $target->url ),
			);
		}

		$tested  = count( $data['rows'] );
		$failed  = (int) $data['failed'];
		$blocked = (int) $data['blocked'];

		if ( $failed > 0 ) {
			$status  = DUPCAP_Result::CRITICAL;
			$label   = sprintf(
				/* translators: %s: language name with code */
				__( '%s pages are not loading correctly', 'duplicate-content-addon-for-polylang' ),
				$data['label']
			);
			$message = sprintf(
				/* translators: 1: number of failing URLs, 2: number tested, 3: language name with code */
				__( '%1$d of %2$d tested %3$s URLs returned an error visitors would see (404, wrong language, or server error).', 'duplicate-content-addon-for-polylang' ),
				$failed,
				$tested,
				$data['label']
			);
			$fix_url   = admin_url( 'options-permalink.php' );
			$fix_label = __( 'Rebuild URL rules', 'duplicate-content-addon-for-polylang' );
		} elseif ( $blocked > 0 ) {
			// Could not reach the URL from the server — not a confirmed visitor bug.
			$status  = DUPCAP_Result::INFO;
			$label   = sprintf(
				/* translators: %s: language name with code */
				__( '%s URLs need a quick browser check', 'duplicate-content-addon-for-polylang' ),
				$data['label']
			);
			$message = sprintf(
				/* translators: 1: number of untested URLs, 2: language name with code */
				__( '%1$d %2$s URL(s) could not be auto-tested from the server (often a timeout on local sites). Exact issue: the plugin could not confirm these pages load. What to do: open each page in your browser — if it loads, click Ignore. On live hosting, click Test URLs again later.', 'duplicate-content-addon-for-polylang' ),
				$blocked,
				$data['label']
			);
			$fix_url   = '';
			$fix_label = '';
		} else {
			$status  = DUPCAP_Result::PASS;
			$label   = sprintf(
				/* translators: %s: language name with code */
				__( '%s pages load correctly', 'duplicate-content-addon-for-polylang' ),
				$data['label']
			);
			$message = sprintf(
				/* translators: 1: number tested, 2: language name with code */
				__( 'All %1$d tested %2$s URLs loaded normally.', 'duplicate-content-addon-for-polylang' ),
				$tested,
				$data['label']
			);
			$fix_url   = '';
			$fix_label = '';
		}

		$result = new DUPCAP_Result( 'url_route_' . $lang, $status, self::GROUP, $label, $message );
		if ( '' !== $fix_url ) {
			$result->with_fix( $fix_url, $fix_label );
		}
		$result->with_details( $details );

		return $result->to_array();
	}

	/**
	 * Stable ignore key for the site-wide "URL auto-test could not run" guard.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public static function untestable_ignore_key() {
		if ( ! class_exists( 'DUPCAP_Ignored_Issues' ) ) {
			return '';
		}

		return DUPCAP_Ignored_Issues::url_key( 'route_untestable', '', home_url( '/' ) );
	}

	/**
	 * Whether this install looks like a local development site.
	 *
	 * Loopback URL tests often time out on Local WP / localhost / .local hosts.
	 *
	 * @since 2.2.0
	 *
	 * @return bool
	 */
	public static function is_likely_local_site() {
		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			return true;
		}

		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';
		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		return (bool) preg_match( '/\.(local|test|localhost|invalid|example)$/', $host );
	}

	/**
	 * Guard 1 result: the server cannot reach itself.
	 *
	 * @since 2.2.0
	 *
	 * @param string $error Error message from the probe.
	 * @return array
	 */
	protected function untestable_result( $error ) {
		$is_timeout = '' !== $error && (
			false !== stripos( $error, 'timed out' )
			|| false !== stripos( $error, 'timeout' )
			|| false !== stripos( $error, 'operation timed out' )
		);
		$is_local = self::is_likely_local_site();
		$home_url = home_url( '/' );

		if ( $is_timeout && $is_local ) {
			$label   = __( 'Local site: automatic URL test timed out', 'duplicate-content-addon-for-polylang' );
			$message = __( 'Exact issue: WordPress tried to open this site from the server to check language URLs, and that request timed out. On Local WP, localhost, or .local domains this is normal — it does not mean your language pages are broken. What to do: 1) Open your homepage in the browser. 2) Open each language (for example /es/ or via the language switcher). 3) If the pages load, click Ignore. 4) After you move to live hosting, click Test URLs again.', 'duplicate-content-addon-for-polylang' );
			$fix_url   = $home_url;
			$fix_label = __( 'Open homepage', 'duplicate-content-addon-for-polylang' );
		} elseif ( $is_timeout ) {
			$label   = __( 'Automatic URL test timed out', 'duplicate-content-addon-for-polylang' );
			$message = __( 'Exact issue: the server timed out while requesting its own language URLs, so this plugin could not confirm whether they load. What to do: 1) Open your homepage and each language URL in the browser. 2) If a page loads normally, click Ignore. 3) If you see a 404 or wrong language, open Settings → Permalinks, click Save Changes, then click Test URLs again.', 'duplicate-content-addon-for-polylang' );
			$fix_url   = $home_url;
			$fix_label = __( 'Open homepage', 'duplicate-content-addon-for-polylang' );
		} else {
			$label   = __( 'Automatic URL test is blocked', 'duplicate-content-addon-for-polylang' );
			$message = __( 'Exact issue: this site could not open its own URLs from the server (loopback request failed), so routing problems could not be detected automatically. What to do: 1) Open language pages in your browser to confirm they load. 2) If you use a security plugin, allow loopback / self-requests. 3) Check Tools → Site Health for “Your site could not complete a loopback request”.', 'duplicate-content-addon-for-polylang' );

			$message .= ' ' . sprintf(
				/* translators: %s: the user agent string used for testing */
				__( 'Allow this user agent if needed: %s.', 'duplicate-content-addon-for-polylang' ),
				DUPCAP_Routing_Request::user_agent()
			);

			$fix_url   = admin_url( 'site-health.php' );
			$fix_label = __( 'Open Site Health', 'duplicate-content-addon-for-polylang' );
		}

		$result = new DUPCAP_Result(
			'url_route_untestable',
			DUPCAP_Result::INFO,
			self::GROUP,
			$label,
			$message
		);

		$result->with_fix( $fix_url, $fix_label );
		$result->with_details(
			array(
				array(
					'label'        => $label,
					'message'      => $message,
					'url'          => $home_url,
					'verdict'      => 'untestable',
					'action_url'   => $fix_url,
					'action_label' => $fix_label,
					'ignore_key'   => self::untestable_ignore_key(),
					'ignore_label' => __( 'Ignore', 'duplicate-content-addon-for-polylang' ),
				),
			)
		);

		return $result->to_array();
	}

	/**
	 * Guard 2 result: every URL returned the same non-200, so the whole site is
	 * gated rather than any one language being broken.
	 *
	 * @since 2.2.0
	 *
	 * @param int $code The shared status code.
	 * @return array
	 */
	protected function site_gated_result( $code ) {
		$result = new DUPCAP_Result(
			'url_route_site_gated',
			DUPCAP_Result::WARNING,
			self::GROUP,
			__( 'Your whole site is closed to visitors', 'duplicate-content-addon-for-polylang' ),
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'Every tested URL returned the same response (HTTP %d), including your main language. That points at a coming-soon or maintenance mode, a password prompt, or a firewall — not at a translation problem. Turn that off and test again to get real results.', 'duplicate-content-addon-for-polylang' ),
				(int) $code
			)
		);

		$result->with_fix(
			admin_url( 'plugins.php' ),
			__( 'Review active plugins', 'duplicate-content-addon-for-polylang' )
		);

		return $result->to_array();
	}

	/**
	 * Drop ignored routing rows and pass a language when no problems remain.
	 *
	 * Used when rendering a saved report so Ignore takes effect without
	 * re-testing URLs.
	 *
	 * @since 2.2.0
	 *
	 * @param array $results Scanner result arrays.
	 * @return array
	 */
	public static function apply_ignores_to_results( array $results ) {
		if ( ! class_exists( 'DUPCAP_Ignored_Issues' ) ) {
			return $results;
		}

		$filtered          = array();
		$untestable_ignore = self::untestable_ignore_key();

		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( 0 !== strpos( $id, 'url_route_' ) ) {
				$filtered[] = $row;
				continue;
			}

			// Site-wide timeout / loopback guard (including older reports with no details).
			if ( 'url_route_untestable' === $id ) {
				if ( '' !== $untestable_ignore && DUPCAP_Ignored_Issues::is_ignored( $untestable_ignore ) ) {
					continue;
				}

				$row = self::ensure_untestable_detail_row( $row, $untestable_ignore );
			}

			if ( empty( $row['details'] ) || ! is_array( $row['details'] ) ) {
				$filtered[] = $row;
				continue;
			}

			$kept    = array();
			$problem = 0;

			foreach ( $row['details'] as $detail ) {
				if ( ! is_array( $detail ) ) {
					continue;
				}

				$key = isset( $detail['ignore_key'] ) ? (string) $detail['ignore_key'] : '';
				if ( '' !== $key && DUPCAP_Ignored_Issues::is_ignored( $key ) ) {
					continue;
				}

				$kept[] = $detail;
				if ( self::detail_is_problem( $detail ) ) {
					$problem++;
				}
			}

			$row['details'] = array_values( $kept );

			if ( 0 === $problem ) {
				$row['status'] = DUPCAP_Result::PASS;
				unset( $row['fix_url'], $row['fix_label'] );
			} else {
				$has_failure = false;
				foreach ( $kept as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$verdict = isset( $detail['verdict'] ) ? (string) $detail['verdict'] : '';
					if (
						'' !== $verdict
						&& DUPCAP_Routing_Request::VERDICT_OK !== $verdict
						&& DUPCAP_Routing_Request::VERDICT_UNTESTABLE !== $verdict
					) {
						$has_failure = true;
						break;
					}
				}
				if ( $has_failure ) {
					$row['status'] = DUPCAP_Result::CRITICAL;
				} else {
					$row['status'] = DUPCAP_Result::INFO;
					unset( $row['fix_url'], $row['fix_label'] );
				}
			}

			if ( empty( $row['details'] ) && DUPCAP_Result::PASS === $row['status'] ) {
				continue;
			}

			$filtered[] = $row;
		}

		return $filtered;
	}

	/**
	 * Ensure the untestable guard has a dismissible detail row (for older reports).
	 *
	 * @since 2.2.0
	 *
	 * @param array  $row                Result row.
	 * @param string $untestable_ignore  Ignore key.
	 * @return array
	 */
	protected static function ensure_untestable_detail_row( array $row, $untestable_ignore ) {
		$home_url = home_url( '/' );

		if ( empty( $row['details'] ) || ! is_array( $row['details'] ) ) {
			$row['details'] = array(
				array(
					'label'        => isset( $row['label'] ) ? (string) $row['label'] : __( 'URL checks could not finish', 'duplicate-content-addon-for-polylang' ),
					'message'      => isset( $row['message'] ) ? (string) $row['message'] : '',
					'url'          => $home_url,
					'verdict'      => 'untestable',
					'action_url'   => ! empty( $row['fix_url'] ) ? (string) $row['fix_url'] : $home_url,
					'action_label' => ! empty( $row['fix_label'] ) ? (string) $row['fix_label'] : __( 'Open homepage', 'duplicate-content-addon-for-polylang' ),
					'ignore_key'   => $untestable_ignore,
					'ignore_label' => __( 'Ignore', 'duplicate-content-addon-for-polylang' ),
				),
			);
			return $row;
		}

		foreach ( $row['details'] as $i => $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}
			if ( empty( $detail['ignore_key'] ) && '' !== $untestable_ignore ) {
				$row['details'][ $i ]['ignore_key']   = $untestable_ignore;
				$row['details'][ $i ]['ignore_label'] = __( 'Ignore', 'duplicate-content-addon-for-polylang' );
			}
			if ( empty( $detail['verdict'] ) ) {
				$row['details'][ $i ]['verdict'] = 'untestable';
			}
			if ( empty( $detail['url'] ) ) {
				$row['details'][ $i ]['url'] = $home_url;
			}
		}

		return $row;
	}

	/**
	 * Whether a routing detail row is a real failure (not "loaded normally").
	 *
	 * @since 2.2.0
	 *
	 * @param array $detail Detail row.
	 * @return bool
	 */
	public static function detail_is_problem( array $detail ) {
		$verdict = isset( $detail['verdict'] ) ? (string) $detail['verdict'] : '';
		if ( '' !== $verdict ) {
			return DUPCAP_Routing_Request::VERDICT_OK !== $verdict;
		}

		$code = isset( $detail['http_status'] ) ? (int) $detail['http_status'] : 0;
		return ! in_array( $code, array( 200, 304 ), true );
	}

	/* ---------- Helpers ---------- */

	/**
	 * Active language objects.
	 *
	 * @since 2.2.0
	 *
	 * @return array
	 */
	protected function active_languages() {
		$pll   = function_exists( 'PLL' ) ? PLL() : null;
		$model = ( $pll && isset( $pll->model ) ) ? $pll->model : null;
		$list  = array();

		/*
		 * Polylang 3.8 moved these onto Model\Languages and forwards the old names
		 * through PLL_Model::__call(). method_exists() returns false for a magic
		 * method, so guarding with it skips the model entirely on 3.8 and the whole
		 * sweep reports "single language". is_callable() sees __call; the public-API
		 * fallback below covers anything it gets wrong.
		 */
		if ( $model && is_callable( array( $model, 'get_languages_list' ) ) ) {
			try {
				$found = $model->get_languages_list();
				if ( is_array( $found ) ) {
					$list = $found;
				}
			} catch ( \Throwable $e ) {
				$list = array();
			}
		}

		if ( empty( $list ) && function_exists( 'pll_languages_list' ) ) {
			$list = (array) pll_languages_list( array( 'fields' => '' ) );
		}

		$active = array();

		foreach ( $list as $language ) {
			if ( is_object( $language ) && isset( $language->active ) && ! $language->active ) {
				continue;
			}
			$active[] = $language;
		}

		return $active;
	}

	/**
	 * Polylang force_lang, read through both the 3.6 array and 3.7+ object APIs.
	 *
	 * @since 2.2.0
	 *
	 * @return int
	 */
	protected function force_lang() {
		$pll = function_exists( 'PLL' ) ? PLL() : null;

		if ( ! $pll || ! isset( $pll->options ) ) {
			return 1;
		}

		if ( is_object( $pll->options ) && method_exists( $pll->options, 'get' ) ) {
			$value = $pll->options->get( 'force_lang' );
			return null === $value ? 1 : (int) $value;
		}

		if ( is_array( $pll->options ) && isset( $pll->options['force_lang'] ) ) {
			return (int) $pll->options['force_lang'];
		}

		return 1;
	}

	/**
	 * Every language home URL, used to spot redirect-to-home behaviour.
	 *
	 * @since 2.2.0
	 *
	 * @param array $languages Active language objects.
	 * @return array
	 */
	protected function home_urls( array $languages ) {
		$homes = array();

		foreach ( $languages as $language ) {
			if ( is_object( $language ) && method_exists( $language, 'get_home_url' ) ) {
				$homes[] = (string) $language->get_home_url();
			}
		}

		$homes[] = home_url( '/' );

		return array_values( array_unique( array_filter( $homes ) ) );
	}

	/**
	 * Whether every response shared one non-200 status code.
	 *
	 * @since 2.2.0
	 *
	 * @param array $codes Status codes.
	 * @return bool
	 */
	protected function all_same_failure( array $codes ) {
		if ( count( $codes ) < 2 ) {
			return false;
		}

		$unique = array_unique( $codes );

		if ( 1 !== count( $unique ) ) {
			return false;
		}

		$code = (int) reset( $unique );

		return 0 !== $code && ( $code < 200 || $code >= 300 );
	}
}
