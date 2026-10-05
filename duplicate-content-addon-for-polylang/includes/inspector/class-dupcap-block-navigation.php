<?php
/**
 * Block / FSE navigation discovery for Translation Inspector.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Block_Navigation {

	const NAV_BLOCK       = 'core/navigation';
	const LINK_BLOCKS     = array( 'core/navigation-link', 'core/navigation-submenu' );
	const TEMPLATE_AREAS  = array( 'header', 'footer' );

	/**
	 * Whether the active theme is a block / FSE theme.
	 *
	 * @return bool
	 */
	public static function is_block_theme() {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * Scan block navigation for multilingual issues.
	 *
	 * @param array  $languages    Active language objects or slugs.
	 * @param string $default_lang Default language slug.
	 * @return array<string, mixed>
	 */
	public function scan( array $languages, $default_lang ) {
		$lang_slugs = array();
		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? (string) $lang->slug : (string) $lang;
			if ( '' !== $slug ) {
				$lang_slugs[] = $slug;
			}
		}

		$navigation_posts = $this->get_published_posts( 'wp_navigation' );
		$template_parts   = $this->get_relevant_template_parts();

		$navigation_instances = array();
		$parts_with_nav       = array();
		$header_parts         = array();

		foreach ( $navigation_posts as $post ) {
			$blocks = parse_blocks( (string) $post->post_content );
			$links  = $this->collect_links_from_blocks( $blocks );
			$navigation_instances[] = array(
				'source'   => 'wp_navigation',
				'post_id'  => (int) $post->ID,
				'title'    => get_the_title( $post ),
				'language' => $this->post_language( (int) $post->ID ),
				'links'    => $links,
			);
		}

		foreach ( $template_parts as $part ) {
			$blocks = parse_blocks( (string) $part->post_content );
			$nav_blocks = $this->find_navigation_blocks( $blocks );
			if ( empty( $nav_blocks ) ) {
				continue;
			}

			$area = $this->get_template_part_area( (int) $part->ID );
			$parts_with_nav[ (int) $part->ID ] = true;
			$header_parts[] = $part;

			foreach ( $nav_blocks as $nav_block ) {
				$links = $this->collect_links_from_blocks( array( $nav_block ) );
				$navigation_instances[] = array(
					'source'   => 'template_part',
					'post_id'  => (int) $part->ID,
					'title'    => $this->template_part_label( $part, $area ),
					'language' => $this->post_language( (int) $part->ID ),
					'links'    => $links,
				);
			}
		}

		$coverage_issues      = $this->analyze_language_coverage( $lang_slugs, $default_lang, $languages, $header_parts, $parts_with_nav, $navigation_posts );
		$language_status      = $coverage_issues['language_status'];
		$missing_translations = $coverage_issues['missing_translations'];
		$uses_other_language  = $coverage_issues['uses_other_language'];
		$found_any_navigation = ! empty( $navigation_instances );

		$language_instances = $this->build_language_navigation_instances( $lang_slugs, $default_lang, $header_parts, $parts_with_nav, $navigation_posts );
		$wrong_language_links = $this->find_wrong_language_links( $language_instances, $default_lang, $languages );

		return array(
			'navigation_post_count' => count( $navigation_posts ),
			'template_part_count'   => count( $template_parts ),
			'found_any_navigation'  => $found_any_navigation,
			'language_status'       => $language_status,
			'missing_translations'  => $missing_translations,
			'uses_other_language'   => $uses_other_language,
			'wrong_language_links'  => $wrong_language_links,
			'inspected'             => true,
		);
	}

	/**
	 * Verify each language has its own header/navigation instead of reusing another language.
	 *
	 * @param array<string>        $lang_slugs Active language slugs.
	 * @param string             $default_lang Default language slug.
	 * @param array                $languages Language objects.
	 * @param array<int, WP_Post>  $header_parts Header/footer template parts containing navigation.
	 * @param array<int, bool>     $parts_with_nav Template part IDs containing navigation.
	 * @param array<int, WP_Post>  $navigation_posts Published navigation entities.
	 * @return array{language_status: array<string, array<string, string>>, missing_translations: array<int, string>, uses_other_language: array<int, array<string, mixed>>}
	 */
	protected function analyze_language_coverage( array $lang_slugs, $default_lang, array $languages, array $header_parts, array $parts_with_nav, array $navigation_posts ) {
		$language_status      = array();
		$missing_translations = array();
		$uses_other_language  = array();
		$default_headers      = $this->filter_header_parts( $header_parts );

		foreach ( $lang_slugs as $slug ) {
			$lang_name        = $this->language_label( $slug, $languages );
			$effective_parts  = $this->get_effective_header_parts_for_language( $slug, $default_lang, $header_parts, $parts_with_nav );
			$ref_issues       = $this->find_navigation_ref_issues_for_parts( $effective_parts, $slug, $languages );
			$uses_other_language = array_merge( $uses_other_language, $ref_issues );

			if ( ! empty( $ref_issues ) ) {
				$source_lang = isset( $ref_issues[0]['source_language'] ) ? (string) $ref_issues[0]['source_language'] : '';
				$source_name = $this->language_label( $source_lang, $languages );
				$language_status[ $slug ] = array(
					'label'    => sprintf(
						/* translators: %s: language name */
						__( '%s navigation', 'duplicate-content-addon-for-polylang' ),
						$lang_name
					),
					'message'  => sprintf(
						/* translators: %s: language name */
						__( 'Uses %s navigation', 'duplicate-content-addon-for-polylang' ),
						$source_name
					),
					'severity' => 'warn',
				);
				continue;
			}

			if ( empty( $effective_parts ) ) {
				if ( $slug === $default_lang || empty( $default_headers ) ) {
					$language_status[ $slug ] = array(
						'label'    => sprintf(
							/* translators: %s: language name */
							__( '%s navigation', 'duplicate-content-addon-for-polylang' ),
							$lang_name
						),
						'message'  => __( 'No header navigation found for this language', 'duplicate-content-addon-for-polylang' ),
						'severity' => 'info',
					);
					continue;
				}

				$missing_translations[] = $slug;
				$language_status[ $slug ] = array(
					'label'    => sprintf(
						/* translators: %s: language name */
						__( '%s navigation', 'duplicate-content-addon-for-polylang' ),
						$lang_name
					),
					'message'  => __( 'Header or navigation translation missing', 'duplicate-content-addon-for-polylang' ),
					'severity' => 'warn',
				);
				continue;
			}

			$missing_nav_entity = $this->language_missing_navigation_entity_translation( $slug, $default_lang, $navigation_posts );
			if ( $missing_nav_entity ) {
				$missing_translations[] = $slug;
				$language_status[ $slug ] = array(
					'label'    => sprintf(
						/* translators: %s: language name */
						__( '%s navigation', 'duplicate-content-addon-for-polylang' ),
						$lang_name
					),
					'message'  => __( 'Navigation entity translation missing', 'duplicate-content-addon-for-polylang' ),
					'severity' => 'warn',
				);
				continue;
			}

			$language_status[ $slug ] = array(
				'label'    => sprintf(
					/* translators: %s: language name */
					__( '%s navigation', 'duplicate-content-addon-for-polylang' ),
					$lang_name
				),
				'message'  => __( 'Language-specific navigation found', 'duplicate-content-addon-for-polylang' ),
				'severity' => 'ok',
			);
		}

		$missing_translations = array_values( array_unique( $missing_translations ) );

		return array(
			'language_status'      => $language_status,
			'missing_translations' => $missing_translations,
			'uses_other_language'  => $uses_other_language,
		);
	}

	/**
	 * Build per-language navigation instances based on the header each language should use.
	 *
	 * @param array<string>       $lang_slugs Language slugs.
	 * @param string            $default_lang Default language slug.
	 * @param array<int, WP_Post> $header_parts Header template parts with navigation.
	 * @param array<int, bool>    $parts_with_nav Part IDs containing navigation.
	 * @param array<int, WP_Post> $navigation_posts Navigation entities.
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_language_navigation_instances( array $lang_slugs, $default_lang, array $header_parts, array $parts_with_nav, array $navigation_posts ) {
		$instances = array();

		foreach ( $lang_slugs as $slug ) {
			$parts = $this->get_effective_header_parts_for_language( $slug, $default_lang, $header_parts, $parts_with_nav );
			foreach ( $parts as $part ) {
				if ( ! $part instanceof WP_Post ) {
					continue;
				}

				$blocks     = parse_blocks( (string) $part->post_content );
				$nav_blocks = $this->find_navigation_blocks( $blocks );
				$area       = $this->get_template_part_area( (int) $part->ID );

				foreach ( $nav_blocks as $nav_block ) {
					$instances[] = array(
						'source'   => 'template_part',
						'post_id'  => (int) $part->ID,
						'title'    => $this->template_part_label( $part, $area ),
						'language' => $slug,
						'links'    => $this->collect_links_from_blocks( array( $nav_block ) ),
					);
				}
			}

			foreach ( $navigation_posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				if ( $this->post_language( (int) $post->ID ) !== $slug ) {
					continue;
				}

				$instances[] = array(
					'source'   => 'wp_navigation',
					'post_id'  => (int) $post->ID,
					'title'    => get_the_title( $post ),
					'language' => $slug,
					'links'    => $this->collect_links_from_blocks( parse_blocks( (string) $post->post_content ) ),
				);
			}
		}

		return $instances;
	}

	/**
	 * @param array<int, WP_Post> $header_parts Template parts.
	 * @return array<int, WP_Post>
	 */
	protected function filter_header_parts( array $header_parts ) {
		$headers = array();

		foreach ( $header_parts as $part ) {
			if ( ! $part instanceof WP_Post ) {
				continue;
			}

			$area = $this->get_template_part_area( (int) $part->ID );
			$slug = (string) $part->post_name;
			if ( 'header' === $area || false !== strpos( $slug, 'header' ) ) {
				$headers[] = $part;
			}
		}

		return $headers;
	}

	/**
	 * Resolve header template parts that apply to one language.
	 *
	 * @param string              $lang_slug Language slug.
	 * @param string              $default_lang Default language slug.
	 * @param array<int, WP_Post> $header_parts Candidate template parts.
	 * @param array<int, bool>    $parts_with_nav Part IDs containing navigation.
	 * @return array<int, WP_Post>
	 */
	protected function get_effective_header_parts_for_language( $lang_slug, $default_lang, array $header_parts, array $parts_with_nav ) {
		$headers = $this->filter_header_parts( $header_parts );
		$matched = array();

		foreach ( $headers as $part ) {
			if ( ! isset( $parts_with_nav[ (int) $part->ID ] ) ) {
				continue;
			}

			if ( $this->post_language( (int) $part->ID ) === $lang_slug ) {
				$matched[ (int) $part->ID ] = $part;
			}
		}

		if ( ! empty( $matched ) || $lang_slug === $default_lang || ! function_exists( 'pll_get_post' ) ) {
			return array_values( $matched );
		}

		foreach ( $headers as $part ) {
			if ( $this->post_language( (int) $part->ID ) !== $default_lang ) {
				continue;
			}

			$translated_id = (int) pll_get_post( (int) $part->ID, $lang_slug );
			if ( $translated_id <= 0 ) {
				continue;
			}

			$translated = get_post( $translated_id );
			if ( $translated instanceof WP_Post && 'publish' === $translated->post_status ) {
				$matched[ $translated_id ] = $translated;
			}
		}

		return array_values( $matched );
	}

	/**
	 * Detect navigation blocks that reference another language's menu entity.
	 *
	 * @param array<int, WP_Post> $parts Template parts.
	 * @param string            $expected_lang Expected language slug.
	 * @param array             $languages Languages.
	 * @return array<int, array<string, mixed>>
	 */
	protected function find_navigation_ref_issues_for_parts( array $parts, $expected_lang, array $languages ) {
		$issues = array();

		foreach ( $parts as $part ) {
			if ( ! $part instanceof WP_Post ) {
				continue;
			}

			$blocks     = parse_blocks( (string) $part->post_content );
			$nav_blocks = $this->find_navigation_blocks( $blocks );

			foreach ( $nav_blocks as $nav_block ) {
				$ref_id = ! empty( $nav_block['attrs']['ref'] ) ? (int) $nav_block['attrs']['ref'] : 0;
				if ( $ref_id > 0 ) {
					$ref_lang = $this->post_language( $ref_id );
					if ( '' !== $ref_lang && $ref_lang !== $expected_lang ) {
						$issues[] = array(
							'language'        => $expected_lang,
							'source_language' => $ref_lang,
							'ref_id'          => $ref_id,
							'part_id'         => (int) $part->ID,
							'message'         => sprintf(
								/* translators: 1: expected language, 2: source language */
								__( '%1$s header uses navigation from %2$s', 'duplicate-content-addon-for-polylang' ),
								$this->language_label( $expected_lang, $languages ),
								$this->language_label( $ref_lang, $languages )
							),
						);
					}
					continue;
				}

				$part_lang = $this->post_language( (int) $part->ID );
				if ( '' !== $part_lang && $part_lang !== $expected_lang ) {
					$issues[] = array(
						'language'        => $expected_lang,
						'source_language' => $part_lang,
						'ref_id'          => 0,
						'part_id'         => (int) $part->ID,
						'message'         => sprintf(
							/* translators: 1: expected language, 2: source language */
							__( '%1$s header uses navigation from %2$s', 'duplicate-content-addon-for-polylang' ),
							$this->language_label( $expected_lang, $languages ),
							$this->language_label( $part_lang, $languages )
						),
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Whether a language is missing a translated wp_navigation entity linked from the default language.
	 *
	 * @param string              $lang_slug Language slug.
	 * @param string              $default_lang Default language slug.
	 * @param array<int, WP_Post> $navigation_posts Navigation entities.
	 * @return bool
	 */
	protected function language_missing_navigation_entity_translation( $lang_slug, $default_lang, array $navigation_posts ) {
		if ( $lang_slug === $default_lang || ! function_exists( 'pll_get_post' ) ) {
			return false;
		}

		$default_posts = array();
		foreach ( $navigation_posts as $post ) {
			if ( $post instanceof WP_Post && $this->post_language( (int) $post->ID ) === $default_lang ) {
				$default_posts[] = $post;
			}
		}

		if ( empty( $default_posts ) ) {
			return false;
		}

		foreach ( $default_posts as $post ) {
			$translated_id = (int) pll_get_post( (int) $post->ID, $lang_slug );
			if ( $translated_id <= 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $post_type Post type.
	 * @return WP_Post[]
	 */
	protected function get_published_posts( $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => -1, // Every published template part / navigation menu.
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * @return WP_Post[]
	 */
	protected function get_relevant_template_parts() {
		$parts = $this->get_published_posts( 'wp_template_part' );
		if ( empty( $parts ) ) {
			return array();
		}

		$relevant = array();
		foreach ( $parts as $part ) {
			$area = $this->get_template_part_area( (int) $part->ID );
			$slug = (string) $part->post_name;

			if ( in_array( $area, self::TEMPLATE_AREAS, true ) ) {
				$relevant[] = $part;
				continue;
			}

			if ( false !== strpos( $slug, 'header' ) || false !== strpos( $slug, 'footer' ) ) {
				$relevant[] = $part;
			}
		}

		return $relevant;
	}

	/**
	 * @param int $post_id Template part ID.
	 * @return string
	 */
	protected function get_template_part_area( $post_id ) {
		$area = get_post_meta( $post_id, '_wp_template_part_area', true );
		if ( is_string( $area ) && '' !== $area ) {
			return $area;
		}

		$post = get_post( $post_id );
		return ( $post instanceof WP_Post ) ? (string) $post->post_name : '';
	}

	/**
	 * @param WP_Post $part Template part.
	 * @param string  $area Area slug.
	 * @return string
	 */
	protected function template_part_label( WP_Post $part, $area ) {
		$title = get_the_title( $part );
		if ( is_string( $title ) && '' !== trim( $title ) ) {
			return $title;
		}

		if ( '' !== $area ) {
			return ucfirst( $area );
		}

		return (string) $part->post_name;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	protected function find_navigation_blocks( array $blocks ) {
		$found = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( self::NAV_BLOCK === $name ) {
				$found[] = $block;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, $this->find_navigation_blocks( $block['innerBlocks'] ) );
			}
		}

		return $found;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	protected function collect_links_from_blocks( array $blocks ) {
		$links = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			if ( self::NAV_BLOCK === $name ) {
				if ( ! empty( $block['attrs']['ref'] ) ) {
					$ref_post = get_post( (int) $block['attrs']['ref'] );
					if ( $ref_post instanceof WP_Post ) {
						$ref_blocks = parse_blocks( (string) $ref_post->post_content );
						$links      = array_merge( $links, $this->collect_links_from_blocks( $ref_blocks ) );
					}
				}
			}

			if ( in_array( $name, self::LINK_BLOCKS, true ) ) {
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$links[] = array(
					'label' => isset( $attrs['label'] ) ? (string) $attrs['label'] : '',
					'url'   => isset( $attrs['url'] ) ? (string) $attrs['url'] : '',
					'id'    => isset( $attrs['id'] ) ? (int) $attrs['id'] : 0,
					'kind'  => isset( $attrs['kind'] ) ? (string) $attrs['kind'] : '',
				);
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$links = array_merge( $links, $this->collect_links_from_blocks( $block['innerBlocks'] ) );
			}
		}

		return $links;
	}

	/**
	 * @param array<int, array<string, mixed>> $instances Navigation instances.
	 * @param string                           $default_lang Default language slug.
	 * @param array                            $languages Language objects.
	 * @return array<int, array<string, mixed>>
	 */
	protected function find_wrong_language_links( array $instances, $default_lang, array $languages ) {
		$issues = array();

		foreach ( $instances as $instance ) {
			$expected_lang = isset( $instance['language'] ) ? (string) $instance['language'] : '';
			if ( '' === $expected_lang || '_none' === $expected_lang ) {
				continue;
			}

			$context = isset( $instance['title'] ) ? (string) $instance['title'] : __( 'Navigation', 'duplicate-content-addon-for-polylang' );
			$links   = isset( $instance['links'] ) && is_array( $instance['links'] ) ? $instance['links'] : array();

			foreach ( $links as $link ) {
				$issue = $this->classify_link_issue( $link, $expected_lang, $default_lang, $languages );
				if ( empty( $issue ) ) {
					continue;
				}

				$issue['context']  = $context;
				$issue['language'] = $expected_lang;
				$issues[]          = $issue;
			}
		}

		return $issues;
	}

	/**
	 * @param array<string, mixed> $link Link attributes.
	 * @param string             $expected_lang Expected language slug.
	 * @param string             $default_lang Default language slug.
	 * @param array              $languages Languages.
	 * @return array<string, mixed>
	 */
	protected function classify_link_issue( array $link, $expected_lang, $default_lang, array $languages ) {
		$url     = isset( $link['url'] ) ? (string) $link['url'] : '';
		$post_id = isset( $link['id'] ) ? (int) $link['id'] : 0;

		if ( $post_id <= 0 && '' !== $url && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
			if ( ! DUPCAP_Elementor_Fields::is_internal_url( $url ) ) {
				return array();
			}
			if ( DUPCAP_Elementor_Fields::is_media_asset_url( $url ) ) {
				return array();
			}
			if ( ! DUPCAP_Elementor_Fields::url_has_published_target( $url, $default_lang ) ) {
				$current_label = DUPCAP_Elementor_Fields::readable_url( $url );
				$current_path  = DUPCAP_Elementor_Fields::relative_path( $url );
				$target        = DUPCAP_Elementor_Fields::describe_dead_link_target( $url, $default_lang );
				$kind          = isset( $target['kind'] ) ? (string) $target['kind'] : 'missing';

				return array(
					'code'    => 'dead_link',
					'label'   => isset( $link['label'] ) && '' !== $link['label'] ? (string) $link['label'] : ( $current_label ? $current_label : $current_path ),
					'url'     => $current_path,
					'kind'    => $kind,
					'message' => DUPCAP_Elementor_Fields::format_dead_link_detail(
						$current_label ? $current_label : ( $current_path ? $current_path : $url ),
						$kind
					),
				);
			}
			$post_id = DUPCAP_Elementor_Fields::resolve_url_to_post_id( $url, $default_lang );
		}

		if ( $post_id > 0 ) {
			$post           = get_post( $post_id );
			$is_public      = $post instanceof WP_Post
				&& 'publish' === $post->post_status
				&& '' === (string) $post->post_password;
			if ( ! $is_public ) {
				$current_label = class_exists( 'DUPCAP_Elementor_Fields' )
					? DUPCAP_Elementor_Fields::readable_url( $url )
					: $url;
				$current_path = class_exists( 'DUPCAP_Elementor_Fields' )
					? DUPCAP_Elementor_Fields::relative_path( $url )
					: $url;
				$target = class_exists( 'DUPCAP_Elementor_Fields' )
					? DUPCAP_Elementor_Fields::describe_dead_link_target( $url, $default_lang )
					: array( 'kind' => 'missing' );
				$kind   = isset( $target['kind'] ) ? (string) $target['kind'] : 'missing';

				return array(
					'code'    => 'dead_link',
					'label'   => isset( $link['label'] ) && '' !== $link['label'] ? (string) $link['label'] : ( $current_label ? $current_label : $current_path ),
					'url'     => $current_path,
					'kind'    => $kind,
					'message' => class_exists( 'DUPCAP_Elementor_Fields' )
						? DUPCAP_Elementor_Fields::format_dead_link_detail( $current_label ? $current_label : ( $current_path ? $current_path : $url ), $kind )
						: __( 'This link points to a missing page.', 'duplicate-content-addon-for-polylang' ),
				);
			}
		}

		if ( $post_id <= 0 || ! function_exists( 'pll_get_post_language' ) ) {
			return array();
		}

		$post_lang = pll_get_post_language( $post_id, 'slug' );
		if ( ! is_string( $post_lang ) || '' === $post_lang || $post_lang === $expected_lang ) {
			return array();
		}

		$target_name   = $this->language_label( $post_lang, $languages );
		$current_label = class_exists( 'DUPCAP_Elementor_Fields' )
			? DUPCAP_Elementor_Fields::readable_url( $url )
			: $url;
		$current_path  = class_exists( 'DUPCAP_Elementor_Fields' )
			? DUPCAP_Elementor_Fields::relative_path( $url )
			: $url;

		return array(
			'code'    => 'wrong_language_link',
			'label'   => isset( $link['label'] ) && '' !== $link['label'] ? (string) $link['label'] : $current_label,
			'url'     => $current_path,
			'message' => sprintf(
				/* translators: %s: language name */
				__( 'Links to %s content', 'duplicate-content-addon-for-polylang' ),
				$target_name
			),
		);
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function post_language( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			return (string) pll_get_post_language( $post_id, 'slug' );
		}

		return '';
	}

	/**
	 * @param string $slug Language slug.
	 * @param array  $languages Language objects.
	 * @return string
	 */
	protected function language_label( $slug, array $languages ) {
		foreach ( $languages as $lang ) {
			$lang_slug = is_object( $lang ) ? (string) $lang->slug : (string) $lang;
			if ( $lang_slug !== $slug ) {
				continue;
			}

			if ( is_object( $lang ) && ! empty( $lang->name ) ) {
				return (string) $lang->name;
			}
		}

		if ( class_exists( 'DUPCAP_Elementor_Fields' ) ) {
			return DUPCAP_Elementor_Fields::language_label( $slug );
		}

		return strtoupper( $slug );
	}
}
