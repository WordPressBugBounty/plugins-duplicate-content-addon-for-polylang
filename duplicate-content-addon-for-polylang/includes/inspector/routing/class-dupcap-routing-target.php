<?php
/**
 * A single URL the routing scanner will request, and the builder that decides
 * which URLs are worth testing.
 *
 * Target data comes from PLL_Language properties (host, page_on_front,
 * page_for_posts, get_home_url), all of which exist since Polylang 3.6, so no
 * translation lookups are needed.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Routing_Target {

	const TYPE_HOME  = 'home';
	const TYPE_FRONT = 'front';
	const TYPE_BLOG  = 'blog';
	const TYPE_POST  = 'post';

	/**
	 * Absolute URL to request.
	 *
	 * @var string
	 */
	public $url = '';

	/**
	 * Language slug this URL belongs to.
	 *
	 * @var string
	 */
	public $lang = '';

	/**
	 * Human language label, e.g. "French (fr)".
	 *
	 * @var string
	 */
	public $lang_label = '';

	/**
	 * One of the TYPE_* constants.
	 *
	 * @var string
	 */
	public $type = '';

	/**
	 * Human label for this target, e.g. "Blog page".
	 *
	 * @var string
	 */
	public $label = '';

	/**
	 * Related post ID, when the target is a specific post or page.
	 *
	 * @var int
	 */
	public $object_id = 0;

	/**
	 * Constructor.
	 *
	 * @since 2.2.0
	 *
	 * @param array $args Target fields.
	 */
	public function __construct( array $args ) {
		foreach ( array( 'url', 'lang', 'lang_label', 'type', 'label' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$this->$key = (string) $args[ $key ];
			}
		}

		if ( isset( $args['object_id'] ) ) {
			$this->object_id = (int) $args['object_id'];
		}
	}

	/**
	 * Admin URL that most likely fixes this target, given a failure.
	 *
	 * @since 2.2.0
	 *
	 * @return array{url: string, label: string}
	 */
	public function fix() {
		if ( $this->object_id > 0 ) {
			$edit = get_edit_post_link( $this->object_id, 'url' );
			if ( $edit ) {
				return array(
					'url'   => $edit,
					'label' => __( 'Edit this page', 'duplicate-content-addon-for-polylang' ),
				);
			}
		}

		if ( self::TYPE_BLOG === $this->type ) {
			return array(
				'url'   => admin_url( 'options-reading.php' ),
				'label' => __( 'Reading settings', 'duplicate-content-addon-for-polylang' ),
			);
		}

		return array(
			'url'   => admin_url( 'options-permalink.php' ),
			'label' => __( 'Rebuild URL rules', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/* ---------- Builder ---------- */

	/**
	 * Build the target list for a set of active languages.
	 *
	 * Phase 2 covers targets 1-4 (home, front page, blog page, one post).
	 * Category and CPT archives are Phase 3.
	 *
	 * @since 2.2.0
	 *
	 * @param array $languages Active PLL_Language objects.
	 * @param int   $max       Hard cap on total targets.
	 * @return DUPCAP_Routing_Target[]
	 */
	public static function for_languages( array $languages, $max ) {
		$targets = array();
		$max     = max( 1, (int) $max );

		foreach ( $languages as $language ) {
			if ( ! is_object( $language ) || empty( $language->slug ) ) {
				continue;
			}

			foreach ( self::for_language( $language ) as $target ) {
				if ( count( $targets ) >= $max ) {
					return $targets;
				}
				$targets[] = $target;
			}
		}

		return $targets;
	}

	/**
	 * Targets for a single language.
	 *
	 * @since 2.2.0
	 *
	 * @param object $language PLL_Language object.
	 * @return DUPCAP_Routing_Target[]
	 */
	protected static function for_language( $language ) {
		$slug    = (string) $language->slug;
		$name    = ! empty( $language->name ) ? (string) $language->name : $slug;
		$label   = sprintf(
			/* translators: 1: language name, 2: language code */
			__( '%1$s (%2$s)', 'duplicate-content-addon-for-polylang' ),
			$name,
			$slug
		);
		$targets = array();
		$seen    = array();

		// 1. Language home.
		$home = method_exists( $language, 'get_home_url' ) ? (string) $language->get_home_url() : '';
		if ( '' === $home && function_exists( 'pll_home_url' ) ) {
			$home = (string) pll_home_url( $slug );
		}
		if ( '' !== $home ) {
			$seen[ self::normalize( $home ) ] = true;
			$targets[]                        = new self(
				array(
					'url'        => $home,
					'lang'       => $slug,
					'lang_label' => $label,
					'type'       => self::TYPE_HOME,
					'label'      => __( 'Home page', 'duplicate-content-addon-for-polylang' ),
				)
			);
		}

		// 2. Static front page, when it differs from the language home.
		$front_id = isset( $language->page_on_front ) ? (int) $language->page_on_front : 0;
		if ( $front_id > 0 ) {
			$url = (string) get_permalink( $front_id );
			if ( '' !== $url && ! isset( $seen[ self::normalize( $url ) ] ) ) {
				$seen[ self::normalize( $url ) ] = true;
				$targets[]                       = new self(
					array(
						'url'        => $url,
						'lang'       => $slug,
						'lang_label' => $label,
						'type'       => self::TYPE_FRONT,
						'label'      => __( 'Front page', 'duplicate-content-addon-for-polylang' ),
						'object_id'  => $front_id,
					)
				);
			}
		}

		// 3. Posts page.
		$blog_id = isset( $language->page_for_posts ) ? (int) $language->page_for_posts : 0;
		if ( $blog_id > 0 ) {
			$url = (string) get_permalink( $blog_id );
			if ( '' !== $url && ! isset( $seen[ self::normalize( $url ) ] ) ) {
				$seen[ self::normalize( $url ) ] = true;
				$targets[]                       = new self(
					array(
						'url'        => $url,
						'lang'       => $slug,
						'lang_label' => $label,
						'type'       => self::TYPE_BLOG,
						'label'      => __( 'Blog page', 'duplicate-content-addon-for-polylang' ),
						'object_id'  => $blog_id,
					)
				);
			}
		}

		// 4. Newest published post in this language.
		$post_id = self::newest_post_id( $slug );
		if ( $post_id > 0 ) {
			$url = (string) get_permalink( $post_id );
			if ( '' !== $url && ! isset( $seen[ self::normalize( $url ) ] ) ) {
				$targets[] = new self(
					array(
						'url'        => $url,
						'lang'       => $slug,
						'lang_label' => $label,
						'type'       => self::TYPE_POST,
						'label'      => __( 'A published post', 'duplicate-content-addon-for-polylang' ),
						'object_id'  => $post_id,
					)
				);
			}
		}

		return $targets;
	}

	/**
	 * Newest published post ID in a language.
	 *
	 * @since 2.2.0
	 *
	 * @param string $slug Language slug.
	 * @return int
	 */
	protected static function newest_post_id( $slug ) {
		$ids = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'lang'                   => $slug,
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Normalised form of a URL for duplicate detection: scheme-insensitive,
	 * trailing slash removed.
	 *
	 * @since 2.2.0
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( empty( $parts['host'] ) ) {
			return untrailingslashit( (string) $url );
		}

		$path  = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return strtolower( $parts['host'] ) . $path . $query;
	}
}
