<?php
/**
 * Elementor document value object.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Elementor_Document {

	const META_DATA         = '_elementor_data';
	const META_EDIT_MODE    = '_elementor_edit_mode';
	const META_TEMPLATE_TYPE = '_elementor_template_type';
	const META_CONDITIONS    = '_elementor_conditions';

	/**
	 * Library template types that are not meant for translation scanning
	 * (site kits and global widgets).
	 *
	 * @var string[]
	 */
	const EXCLUDED_TEMPLATE_TYPES = array( 'kit', 'widget' );

	/**
	 * Theme Builder types that rely on Elementor Pro display conditions.
	 *
	 * @var string[]
	 */
	const THEME_BUILDER_CONDITION_TYPES = array(
		'header',
		'footer',
		'single',
		'single-page',
		'single-post',
		'archive',
		'popup',
		'error-404',
		'search-results',
		'product',
		'product-archive',
	);

	/** @var int */
	public $id;

	/** @var string */
	public $title = '';

	/** @var string */
	public $post_type = '';

	/** @var string */
	public $document_type = '';

	/** @var string */
	public $language = '';

	/** @var array<string, int> */
	public $translations = array();

	/** @var array|null */
	protected $decoded_data = null;

	/** @var bool|null */
	protected $json_valid = null;

	/** @var string */
	protected $json_error = '';

	/** @var array<string, array>|null */
	protected $element_map = null;

	/** @var int */
	protected $widget_count = 0;

	/**
	 * @param int $post_id Post ID.
	 */
	public function __construct( $post_id ) {
		$this->id        = (int) $post_id;
		$this->post_type = get_post_type( $post_id );

		if ( function_exists( 'pll_get_post_language' ) ) {
			$this->language = (string) pll_get_post_language( $post_id, 'slug' );
		}

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );
			$this->translations = is_array( $translations ) ? $translations : array();
		}

		$this->document_type = $this->resolve_document_type();
		$this->title         = self::display_title_for( $this->id );
	}

	/**
	 * Human-readable title for dashboard labels (matches Elementor when untitled).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function display_title_for( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return '';
		}

		$title = trim( (string) get_the_title( $post_id ) );
		if ( '' !== $title ) {
			return $title;
		}

		$post_type = get_post_type( $post_id );
		if ( 'elementor_library' === $post_type ) {
			return sprintf(
				/* translators: %d: Elementor template post ID */
				__( 'Elementor #%d', 'duplicate-content-addon-for-polylang' ),
				$post_id
			);
		}

		if ( 'page' === $post_type ) {
			return sprintf(
				/* translators: %d: page ID */
				__( 'Page #%d', 'duplicate-content-addon-for-polylang' ),
				$post_id
			);
		}

		if ( 'post' === $post_type ) {
			return sprintf(
				/* translators: %d: post ID */
				__( 'Post #%d', 'duplicate-content-addon-for-polylang' ),
				$post_id
			);
		}

		$type = get_post_meta( $post_id, self::META_TEMPLATE_TYPE, true );
		if ( is_string( $type ) && '' !== $type ) {
			return sprintf( '%1$s #%2$d', self::type_label_for( $type ), $post_id );
		}

		if ( is_string( $post_type ) && '' !== $post_type ) {
			$label = ucwords( str_replace( array( '-', '_' ), ' ', $post_type ) );
			return sprintf( '%1$s #%2$d', $label, $post_id );
		}

		return sprintf(
			/* translators: %d: document ID */
			__( 'Document #%d', 'duplicate-content-addon-for-polylang' ),
			$post_id
		);
	}

	/**
	 * Whether a post is the static front page or one of its Polylang translations.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_static_front_page_post( $post_id ) {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}

		$front_id = (int) get_option( 'page_on_front' );
		$post_id  = (int) $post_id;
		if ( $front_id <= 0 || $post_id <= 0 ) {
			return false;
		}

		if ( $post_id === $front_id ) {
			return true;
		}

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $front_id );
			if ( is_array( $translations ) ) {
				foreach ( $translations as $trans_id ) {
					if ( (int) $trans_id === $post_id ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Whether an Elementor library post should be skipped from translation scans
	 * (site kits and global widgets are not typical translation targets).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded_library_document( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || 'elementor_library' !== get_post_type( $post_id ) ) {
			return false;
		}

		$type = get_post_meta( $post_id, self::META_TEMPLATE_TYPE, true );
		return is_string( $type ) && in_array( $type, self::EXCLUDED_TEMPLATE_TYPES, true );
	}

	/**
	 * Resolve human-readable document type.
	 *
	 * @return string
	 */
	protected function resolve_document_type() {
		if ( 'elementor_library' === $this->post_type ) {
			$type = get_post_meta( $this->id, self::META_TEMPLATE_TYPE, true );
			if ( is_string( $type ) && '' !== $type ) {
				return $type;
			}
			return 'template';
		}

		if ( 'page' === $this->post_type ) {
			return 'wp-page';
		}

		if ( 'post' === $this->post_type ) {
			return 'wp-post';
		}

		return $this->post_type ? $this->post_type : 'document';
	}

	/**
	 * Human label for document type slug.
	 *
	 * @param string $slug Document type slug.
	 * @return string
	 */
	public static function type_label_for( $slug ) {
		$labels = array(
			'header'    => 'Header',
			'footer'    => 'Footer',
			'popup'     => 'Popup',
			'single'    => 'Single',
			'archive'   => 'Archive',
			'page'      => 'Page Template',
			'section'   => 'Section',
			'loop-item' => 'Loop Item',
			'wp-page'   => 'Page',
			'wp-post'   => 'Post',
			'template'  => 'Template',
		);

		$slug = (string) $slug;
		if ( isset( $labels[ $slug ] ) ) {
			return $labels[ $slug ];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * Display string: Human Label (slug).
	 *
	 * @param string $slug Document type slug.
	 * @return string
	 */
	public static function type_display( $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return '';
		}

		return self::type_label_for( $slug ) . ' (' . $slug . ')';
	}

	/**
	 * Human label for document type.
	 *
	 * @return string
	 */
	public function type_label() {
		return self::type_label_for( $this->document_type );
	}

	/**
	 * Raw _elementor_data meta.
	 *
	 * @return string
	 */
	public function raw_data() {
		$raw = get_post_meta( $this->id, self::META_DATA, true );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Whether JSON decodes successfully.
	 *
	 * @return bool
	 */
	public function is_json_valid() {
		if ( null !== $this->json_valid ) {
			return $this->json_valid;
		}

		$this->get_decoded_data();
		return $this->json_valid;
	}

	/**
	 * JSON decode error message.
	 *
	 * @return string
	 */
	public function json_error() {
		$this->get_decoded_data();
		return $this->json_error;
	}

	/**
	 * Decoded Elementor data tree.
	 *
	 * @return array
	 */
	public function get_decoded_data() {
		if ( null !== $this->decoded_data ) {
			return $this->decoded_data;
		}

		$raw = $this->raw_data();
		if ( '' === $raw ) {
			$this->decoded_data = array();
			$this->json_valid   = true;
			return $this->decoded_data;
		}

		$data = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			$this->decoded_data = array();
			$this->json_valid   = false;
			$this->json_error   = json_last_error_msg();
			return $this->decoded_data;
		}

		$this->decoded_data = $data;
		$this->json_valid   = true;
		return $this->decoded_data;
	}

	/**
	 * Whether the document has any elements.
	 *
	 * @return bool
	 */
	public function has_elements() {
		$data = $this->get_decoded_data();
		return ! empty( $data );
	}

	/**
	 * Modified timestamp.
	 *
	 * @return int
	 */
	public function modified_time() {
		return (int) get_post_modified_time( 'U', true, $this->id );
	}

	/**
	 * Whether this document uses Elementor Theme Builder display conditions.
	 *
	 * @return bool
	 */
	public function uses_theme_builder_conditions() {
		$type = (string) $this->document_type;
		if ( in_array( $type, self::THEME_BUILDER_CONDITION_TYPES, true ) ) {
			return true;
		}

		return ( 0 === strpos( $type, 'single-' ) || 0 === strpos( $type, 'archive-' ) );
	}

	/**
	 * Theme Builder display conditions from `_elementor_conditions`.
	 *
	 * Reads the raw postmeta row so Connect Polylang for Elementor's
	 * get_post_metadata filter (which empties conditions on translations)
	 * does not hide stored values during audits.
	 *
	 * @return string[] Condition strings like "include/general".
	 */
	public function get_conditions() {
		global $wpdb;

		if ( $this->id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypass get_post_metadata filters that hide stored conditions on translations.
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				$this->id,
				self::META_CONDITIONS
			)
		);

		if ( null === $raw || '' === $raw ) {
			return array();
		}

		$conditions = maybe_unserialize( $raw );
		if ( ! is_array( $conditions ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) && '' !== $condition ) {
				$normalized[] = $condition;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Whether conditions include an "Entire Site" include rule.
	 *
	 * @param string[]|null $conditions Optional conditions; defaults to stored meta.
	 * @return bool
	 */
	public function has_entire_site_condition( $conditions = null ) {
		$conditions = is_array( $conditions ) ? $conditions : $this->get_conditions();
		return in_array( 'include/general', $conditions, true );
	}

	/**
	 * Object IDs referenced by singular/archive display conditions.
	 *
	 * @param string[]|null $conditions Optional conditions; defaults to stored meta.
	 * @return int[]
	 */
	public function condition_object_ids( $conditions = null ) {
		$conditions = is_array( $conditions ) ? $conditions : $this->get_conditions();
		$ids        = array();

		foreach ( $conditions as $condition ) {
			if ( ! is_string( $condition ) ) {
				continue;
			}
			// e.g. include/singular/page/123 or exclude/singular/post/45
			if ( preg_match( '#^(?:include|exclude)/[^/]+/[^/]+/(\d+)$#', $condition, $matches ) ) {
				$id = (int) $matches[1];
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Elementor edit URL.
	 *
	 * The active-document argument makes Elementor switch to this document on
	 * load, so it opens editable instead of as a greyed-out preview of another
	 * template.
	 *
	 * @return string
	 */
	public function edit_url() {
		$url = '';

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$plugin = \Elementor\Plugin::$instance;

			if ( isset( $plugin->documents ) && get_post( $this->id ) ) {
				$document = $plugin->documents->get( $this->id );

				if ( $document && method_exists( $document, 'get_edit_url' ) ) {
					$url = (string) $document->get_edit_url();
				}
			}
		}

		if ( '' === $url ) {
			$url = admin_url( 'post.php?post=' . $this->id . '&action=elementor' );
		}

		return add_query_arg( 'active-document', $this->id, $url );
	}

	/**
	 * Polylang "add translation" URL for this document.
	 *
	 * @param string $lang Target language slug.
	 * @return string
	 */
	public function new_translation_url( $lang ) {
		$lang_slug = sanitize_key( (string) $lang );
		$post      = get_post( $this->id );
		$pll       = function_exists( 'PLL' ) ? PLL() : null;

		// Never build a Polylang "new translation" link for media/attachments.
		if ( $post instanceof WP_Post && 'attachment' === $post->post_type ) {
			$edit_link = get_edit_post_link( $this->id, 'url' );
			return $edit_link ? $edit_link : $this->edit_url();
		}

		if ( $post instanceof WP_Post && $pll && isset( $pll->model ) && '' !== $lang_slug ) {
			$language = $pll->model->get_language( $lang_slug );

			if ( $language && isset( $pll->links ) && method_exists( $pll->links, 'get_new_post_translation_link' ) ) {
				$link = $pll->links->get_new_post_translation_link( $post, $language, 'display' );
				if ( $link ) {
					return (string) $link;
				}
			}

			if ( $language ) {
				return wp_nonce_url(
					add_query_arg(
						array(
							'post_type' => $post->post_type,
							'from_post' => $this->id,
							'new_lang'  => $lang_slug,
						),
						admin_url( 'post-new.php' )
					),
					'new-post-translation'
				);
			}
		}

		$edit_link = get_edit_post_link( $this->id, 'url' );
		return $edit_link ? $edit_link : $this->edit_url();
	}

	/**
	 * Map of element id => metadata.
	 *
	 * @return array<string, array>
	 */
	public function get_element_map() {
		if ( null !== $this->element_map ) {
			return $this->element_map;
		}

		$this->element_map = array();
		$this->widget_count = 0;
		$this->walk_elements( $this->get_decoded_data() );

		return $this->element_map;
	}

	/**
	 * Total widget count in document.
	 *
	 * @return int
	 */
	public function widget_count() {
		$this->get_element_map();
		return $this->widget_count;
	}

	/**
	 * Whether the document has at least one Elementor widget.
	 *
	 * Empty section/container shells without widgets are not treated as layout.
	 *
	 * @return bool
	 */
	public function has_widgets() {
		return $this->widget_count() > 0;
	}

	/**
	 * Recursively walk element tree.
	 *
	 * @param array $elements Elements.
	 * @param array $nest     Parent nest context (mega menu, off-canvas, etc.).
	 * @return void
	 */
	protected function walk_elements( array $elements, array $nest = array() ) {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$id          = isset( $element['id'] ) ? (string) $element['id'] : '';
			$el_type     = isset( $element['elType'] ) ? (string) $element['elType'] : '';
			$widget_type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
			$item_nest   = $nest;

			if ( 'container' === $el_type && isset( $nest['menu_items'][ $index ]['item_title'] ) ) {
				$item_nest['label'] = (string) $nest['menu_items'][ $index ]['item_title'];
			}

			if ( 'widget' === $el_type ) {
				$this->widget_count++;

				if ( $id ) {
					$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
					$loop_templates = DUPCAP_Elementor_Fields::is_loop_widget( $widget_type )
						? DUPCAP_Elementor_Fields::loop_template_refs_from_settings( $settings )
						: array();

					$this->element_map[ $id ] = array(
						'id'             => $id,
						'el_type'        => $el_type,
						'widget_type'    => $widget_type ? $widget_type : 'unknown',
						'fields'         => DUPCAP_Elementor_Fields::extract_from_element( $element ),
						'repeaters'      => DUPCAP_Elementor_Fields::repeater_row_counts( $element ),
						'loop_templates' => $loop_templates,
						'nest_type'      => isset( $item_nest['type'] ) ? (string) $item_nest['type'] : '',
						'nest_label'     => isset( $item_nest['label'] ) ? (string) $item_nest['label'] : '',
					);
				}
			} elseif ( $id ) {
				$this->element_map[ $id ] = array(
					'id'          => $id,
					'el_type'     => $el_type,
					'widget_type' => '',
					'fields'      => array(),
					'repeaters'   => array(),
					'nest_type'   => isset( $item_nest['type'] ) ? (string) $item_nest['type'] : '',
					'nest_label'  => isset( $item_nest['label'] ) ? (string) $item_nest['label'] : '',
				);
			}

			$child_nest = $item_nest;
			if ( 'mega-menu' === $widget_type ) {
				$settings   = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				$child_nest = array(
					'type'       => 'mega-menu',
					'label'      => '',
					'menu_items' => isset( $settings['menu_items'] ) && is_array( $settings['menu_items'] ) ? $settings['menu_items'] : array(),
				);
			} elseif ( in_array( $widget_type, array( 'off-canvas', 'off_canvas' ), true ) ) {
				$child_nest = array(
					'type'  => 'off-canvas',
					'label' => '',
				);
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_elements( $element['elements'], $child_nest );
			}
		}
	}

	/**
	 * Validate JSON structure and return issue codes.
	 *
	 * @return array<int, array{code:string, message:string}>
	 */
	public function validate_structure() {
		$issues = array();

		if ( ! $this->is_json_valid() ) {
			$issues[] = array(
				'code'    => 'invalid_json',
				'message' => $this->json_error(),
			);
			return $issues;
		}

		$seen_ids = array();
		$this->collect_structure_issues( $this->get_decoded_data(), $issues, $seen_ids );

		return $issues;
	}

	/**
	 * Collect structural issues while walking tree.
	 *
	 * @param array $elements Elements.
	 * @param array $issues   Issues (by ref).
	 * @param array $seen_ids Seen IDs (by ref).
	 * @return void
	 */
	protected function collect_structure_issues( array $elements, array &$issues, array &$seen_ids ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				$issues[] = array(
					'code'    => 'invalid_node',
					'message' => __( 'Elementor tree contains a non-array node.', 'duplicate-content-addon-for-polylang' ),
				);
				continue;
			}

			$id      = isset( $element['id'] ) ? (string) $element['id'] : '';
			$el_type = isset( $element['elType'] ) ? (string) $element['elType'] : '';

			if ( '' === $id ) {
				$issues[] = array(
					'code'    => 'missing_id',
					'message' => __( 'An element is missing its Elementor ID.', 'duplicate-content-addon-for-polylang' ),
				);
			} elseif ( isset( $seen_ids[ $id ] ) ) {
				$issues[] = array(
					'code'    => 'duplicate_id',
					'message' => sprintf(
						/* translators: %s: element id */
						__( 'Duplicate Elementor element ID: %s', 'duplicate-content-addon-for-polylang' ),
						$id
					),
				);
			} else {
				$seen_ids[ $id ] = true;
			}

			if ( '' === $el_type ) {
				$issues[] = array(
					'code'    => 'missing_el_type',
					'message' => __( 'An element is missing elType.', 'duplicate-content-addon-for-polylang' ),
				);
			}

			if ( 'widget' === $el_type && empty( $element['widgetType'] ) ) {
				$issues[] = array(
					'code'    => 'missing_widget_type',
					'message' => __( 'A widget node is missing widgetType.', 'duplicate-content-addon-for-polylang' ),
				);
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collect_structure_issues( $element['elements'], $issues, $seen_ids );
			}
		}
	}
}
