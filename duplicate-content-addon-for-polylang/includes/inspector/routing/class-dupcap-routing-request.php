<?php
/**
 * HTTP layer and response classification for the routing scanner.
 *
 * Redirects are never followed: the Location header is the diagnosis. Following
 * them would hide exactly the bug this feature exists to find.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Routing_Request {

	const TIMEOUT = 5;

	/**
	 * Verdicts. These are routing outcomes, mapped to result statuses by the scanner.
	 */
	const VERDICT_OK          = 'ok';
	const VERDICT_NOT_FOUND   = 'not_found';
	const VERDICT_WRONG_LANG  = 'wrong_lang';
	const VERDICT_REDIRECTED  = 'redirected';
	const VERDICT_SERVER_FAIL = 'server_error';
	const VERDICT_UNTESTABLE  = 'untestable';

	/**
	 * Request arguments shared by every probe.
	 *
	 * @since 2.2.0
	 *
	 * @param string $url URL being requested.
	 * @return array
	 */
	public static function args( $url ) {
		return array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ),
			'cookies'     => array(),
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
			'user-agent'  => self::user_agent(),
		);
	}

	/**
	 * User agent used for every routing request. Documented in readme.txt so
	 * site owners can allow-list it in a security plugin.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'DupcapRoutingCheck/1.0 (+' . home_url() . ')';
	}

	/**
	 * Perform one request and return a normalised response array.
	 *
	 * @since 2.2.0
	 *
	 * @param string $url URL.
	 * @return array{code: int, location: string, headers: array, error: string}
	 */
	public static function fetch( $url ) {
		$response = wp_remote_get( $url, self::args( $url ) );

		if ( is_wp_error( $response ) ) {
			return self::normalise( 0, array(), $response->get_error_message() );
		}

		$headers = wp_remote_retrieve_headers( $response );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		return self::normalise(
			(int) wp_remote_retrieve_response_code( $response ),
			is_array( $headers ) ? $headers : array(),
			''
		);
	}

	/**
	 * Perform many requests, in parallel when the transport supports it.
	 *
	 * @since 2.2.0
	 *
	 * @param array $urls Map of key => URL.
	 * @return array Map of key => normalised response array.
	 */
	public static function fetch_many( array $urls ) {
		if ( empty( $urls ) ) {
			return array();
		}

		if ( class_exists( '\WpOrg\Requests\Requests' ) && method_exists( '\WpOrg\Requests\Requests', 'request_multiple' ) ) {
			$parallel = self::fetch_many_parallel( $urls );
			if ( null !== $parallel ) {
				return $parallel;
			}
		}

		$out = array();
		foreach ( $urls as $key => $url ) {
			$out[ $key ] = self::fetch( $url );
		}

		return $out;
	}

	/**
	 * Parallel fetch via the Requests transport. Returns null when the
	 * transport refuses the batch, so the caller can fall back to a loop.
	 *
	 * @since 2.2.0
	 *
	 * @param array $urls Map of key => URL.
	 * @return array|null
	 */
	protected static function fetch_many_parallel( array $urls ) {
		$requests = array();

		foreach ( $urls as $key => $url ) {
			$requests[ $key ] = array(
				'url'     => $url,
				'type'    => 'GET',
				'headers' => array( 'Cache-Control' => 'no-cache' ),
			);
		}

		try {
			$responses = \WpOrg\Requests\Requests::request_multiple(
				$requests,
				array(
					'timeout'          => self::TIMEOUT,
					'follow_redirects' => false,
					'useragent'        => self::user_agent(),
				)
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $responses ) ) {
			return null;
		}

		$out = array();

		foreach ( $urls as $key => $url ) {
			if ( ! isset( $responses[ $key ] ) ) {
				$out[ $key ] = self::normalise( 0, array(), __( 'No response.', 'duplicate-content-addon-for-polylang' ) );
				continue;
			}

			$response = $responses[ $key ];

			if ( $response instanceof \Throwable ) {
				$out[ $key ] = self::normalise( 0, array(), $response->getMessage() );
				continue;
			}

			if ( ! is_object( $response ) || ! isset( $response->status_code ) ) {
				$out[ $key ] = self::normalise( 0, array(), __( 'Unreadable response.', 'duplicate-content-addon-for-polylang' ) );
				continue;
			}

			$headers = array();
			if ( isset( $response->headers ) ) {
				foreach ( $response->headers as $name => $value ) {
					$headers[ strtolower( (string) $name ) ] = $value;
				}
			}

			$out[ $key ] = self::normalise( (int) $response->status_code, $headers, '' );
		}

		return $out;
	}

	/**
	 * Shape a raw response into the array the scanner works with.
	 *
	 * @since 2.2.0
	 *
	 * @param int    $code    HTTP status code, 0 when the request failed.
	 * @param array  $headers Response headers, lower-cased keys preferred.
	 * @param string $error   Error message, empty on success.
	 * @return array
	 */
	protected static function normalise( $code, array $headers, $error ) {
		$lower = array();
		foreach ( $headers as $name => $value ) {
			$lower[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return array(
			'code'     => (int) $code,
			'location' => isset( $lower['location'] ) ? (string) $lower['location'] : '',
			'headers'  => $lower,
			'error'    => (string) $error,
		);
	}

	/**
	 * Whether a response came from a full-page cache.
	 *
	 * @since 2.2.0
	 *
	 * @param array $response Normalised response.
	 * @return bool
	 */
	public static function is_cache_hit( array $response ) {
		$headers = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();

		$candidates = array( 'x-litespeed-cache', 'x-cache', 'cf-cache-status', 'x-wp-rocket', 'x-cache-status' );

		foreach ( $candidates as $header ) {
			if ( ! isset( $headers[ $header ] ) ) {
				continue;
			}

			if ( false !== stripos( (string) $headers[ $header ], 'hit' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify one response for one target.
	 *
	 * @since 2.2.0
	 *
	 * @param DUPCAP_Routing_Target $target   Target that was requested.
	 * @param array                 $response Normalised response.
	 * @param array                 $context  Language context: 'languages', 'force_lang', 'homes'.
	 * @return array{verdict: string, message: string}
	 */
	public static function classify( DUPCAP_Routing_Target $target, array $response, array $context ) {
		$code  = isset( $response['code'] ) ? (int) $response['code'] : 0;
		$error = isset( $response['error'] ) ? (string) $response['error'] : '';

		if ( '' !== $error || 0 === $code ) {
			$is_timeout = '' !== $error && (
				false !== stripos( $error, 'timed out' )
				|| false !== stripos( $error, 'timeout' )
				|| false !== stripos( $error, 'operation timed out' )
			);

			if ( $is_timeout ) {
				return array(
					'verdict' => self::VERDICT_UNTESTABLE,
					'message' => __( 'Exact issue: server timed out checking this URL (common on local sites). What to do: open the page in your browser — if it loads, click Ignore.', 'duplicate-content-addon-for-polylang' ),
				);
			}

			return array(
				'verdict' => self::VERDICT_UNTESTABLE,
				'message' => __( 'Exact issue: the server could not check this URL. What to do: open the page in your browser — if it loads, click Ignore.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'verdict' => self::VERDICT_OK,
				'message' => __( 'Loaded normally.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		if ( 404 === $code ) {
			return array(
				'verdict' => self::VERDICT_NOT_FOUND,
				'message' => __( 'Returned "Page not found". Visitors and search engines cannot reach this page.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		if ( $code >= 500 ) {
			return array(
				'verdict' => self::VERDICT_SERVER_FAIL,
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'The server returned an error (HTTP %d). This is a server problem rather than a translation problem.', 'duplicate-content-addon-for-polylang' ),
					$code
				),
			);
		}

		if ( $code >= 300 && $code < 400 ) {
			return self::classify_redirect( $target, $response, $context );
		}

		return array(
			'verdict' => self::VERDICT_NOT_FOUND,
			'message' => sprintf(
				/* translators: %d: HTTP status code */
				__( 'Returned HTTP %d instead of loading the page.', 'duplicate-content-addon-for-polylang' ),
				$code
			),
		);
	}

	/**
	 * Classify a 3xx response by where it points.
	 *
	 * @since 2.2.0
	 *
	 * @param DUPCAP_Routing_Target $target   Target.
	 * @param array                 $response Normalised response.
	 * @param array                 $context  Language context.
	 * @return array{verdict: string, message: string}
	 */
	protected static function classify_redirect( DUPCAP_Routing_Target $target, array $response, array $context ) {
		$location = isset( $response['location'] ) ? (string) $response['location'] : '';

		if ( '' === $location ) {
			return array(
				'verdict' => self::VERDICT_REDIRECTED,
				'message' => __( 'Redirected, but the destination was not given.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		// Relative Location headers are legal.
		if ( 0 !== strpos( $location, 'http' ) ) {
			$location = home_url( '/' !== substr( $location, 0, 1 ) ? '/' . $location : $location );
		}

		// A trailing-slash or scheme-only difference is not a routing problem.
		if ( DUPCAP_Routing_Target::normalize( $location ) === DUPCAP_Routing_Target::normalize( $target->url ) ) {
			return array(
				'verdict' => self::VERDICT_OK,
				'message' => __( 'Redirected to the same page in a slightly different form, which is normal.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		$target_lang = self::resolve_language( $location, $context );

		if ( '' !== $target_lang && $target_lang !== $target->lang ) {
			return array(
				'verdict' => self::VERDICT_WRONG_LANG,
				'message' => sprintf(
					/* translators: 1: destination URL, 2: language code the destination belongs to */
					__( 'Redirected to %1$s, which is the "%2$s" version. Visitors asking for this language are being sent to another one.', 'duplicate-content-addon-for-polylang' ),
					$location,
					$target_lang
				),
			);
		}

		$homes = isset( $context['homes'] ) && is_array( $context['homes'] ) ? $context['homes'] : array();
		foreach ( $homes as $home ) {
			if ( DUPCAP_Routing_Target::normalize( $home ) === DUPCAP_Routing_Target::normalize( $location ) ) {
				return array(
					'verdict' => self::VERDICT_REDIRECTED,
					'message' => sprintf(
						/* translators: %s: destination URL */
						__( 'Redirected to the home page at %s instead of loading the requested page. This usually means Polylang is set to redirect the front page URL to the language home.', 'duplicate-content-addon-for-polylang' ),
						$location
					),
				);
			}
		}

		return array(
			'verdict' => self::VERDICT_REDIRECTED,
			'message' => sprintf(
				/* translators: %s: destination URL */
				__( 'Redirected to %s.', 'duplicate-content-addon-for-polylang' ),
				$location
			),
		);
	}

	/**
	 * Work out which language a URL belongs to.
	 *
	 * Order matters: host comparison is exact and is the only correct method in
	 * subdomain and multi-domain modes, where every language shares a path shape.
	 *
	 * @since 2.2.0
	 *
	 * @param string $url     URL to resolve.
	 * @param array  $context Language context.
	 * @return string Language slug, or empty string when unresolved.
	 */
	public static function resolve_language( $url, array $context ) {
		$languages  = isset( $context['languages'] ) && is_array( $context['languages'] ) ? $context['languages'] : array();
		$force_lang = isset( $context['force_lang'] ) ? (int) $context['force_lang'] : 1;

		// 1. Host-based, for subdomain (2) and multi-domain (3) modes.
		if ( 2 === $force_lang || 3 === $force_lang ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			if ( '' !== $host ) {
				foreach ( $languages as $language ) {
					if ( ! is_object( $language ) || empty( $language->host ) ) {
						continue;
					}
					if ( strtolower( (string) $language->host ) === $host ) {
						return (string) $language->slug;
					}
				}
			}
		}

		// 2. Resolve to a post and ask Polylang directly.
		if ( function_exists( 'url_to_postid' ) && function_exists( 'pll_get_post_language' ) ) {
			$post_id = (int) url_to_postid( $url );
			if ( $post_id > 0 ) {
				$lang = (string) pll_get_post_language( $post_id );
				if ( '' !== $lang ) {
					return $lang;
				}
			}
		}

		// 3. First path segment against known slugs. Least reliable, so last.
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$segments = array_values( array_filter( explode( '/', $path ) ) );

		if ( ! empty( $segments ) ) {
			foreach ( $languages as $language ) {
				$slug = is_object( $language ) ? (string) $language->slug : (string) $language;
				if ( '' !== $slug && $segments[0] === $slug ) {
					return $slug;
				}
			}
		}

		return '';
	}
}
