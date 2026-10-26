<?php
/**
 * Frontend Untranslated Inspector: admin bar toggle and side panel.
 *
 * The panel opens on a reload with ?dupcap-untranslated=1. The rows are built
 * on that request and printed into the page for administrators only; there is
 * no AJAX endpoint.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Untranslated Inspector into the front end.
 */
class DUPCAP_Frontend_Inspector {

	const QUERY_ARG = 'dupcap-untranslated';

	const HANDLE = 'dupcap-frontend-inspector';

	/**
	 * Elementor edit-link argument naming the widget to select in the editor.
	 */
	const FOCUS_ARG = 'dupcap_element';

	const FOCUS_HANDLE = 'dupcap-elementor-focus';

	/**
	 * Block editor link argument holding the start of the text to select.
	 */
	const FOCUS_TEXT_ARG = 'dupcap_text';

	const BLOCK_FOCUS_HANDLE = 'dupcap-block-editor-focus';

	/**
	 * Collector result for the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $result = null;

	/**
	 * Elementor documents rendered on this request (header, footer, templates).
	 *
	 * @var int[]
	 */
	private static $documents = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_item' ), 100 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_boot' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( __CLASS__, 'enqueue_editor_focus' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_focus' ) );
		add_action( 'wp_ajax_dupcap_quick_edit', array( __CLASS__, 'ajax_quick_edit' ) );
	}

	/**
	 * Save one translated string from the inspector's quick edit form.
	 *
	 * @return void
	 */
	public static function ajax_quick_edit() {
		check_ajax_referer( 'dupcap_quick_edit', 'nonce' );

		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$field_key  = isset( $_POST['field_key'] ) ? sanitize_text_field( wp_unslash( $_POST['field_key'] ) ) : '';
		$element_id = isset( $_POST['element_id'] ) ? sanitize_key( wp_unslash( $_POST['element_id'] ) ) : '';
		$value      = isset( $_POST['value'] ) ? wp_kses_post( wp_unslash( $_POST['value'] ) ) : '';
		$original   = isset( $_POST['original'] ) ? wp_unslash( $_POST['original'] ) : '';
		$is_segment = ! empty( $_POST['is_segment'] );

		if ( ! current_user_can( 'manage_options' ) || ! $post_id || '' === $field_key || '' === trim( $value ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( null, 403 );
		}

		if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_default_language' ) ) {
			$post_lang    = (string) pll_get_post_language( $post_id, 'slug' );
			$default_lang = (string) pll_default_language( 'slug' );
			if ( '' !== $post_lang && '' !== $default_lang && $post_lang === $default_lang && 'elementor_library' === get_post_type( $post_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Cannot edit source language template directly.', 'duplicate-content-addon-for-polylang' ) ), 403 );
			}
		}

		if ( 'post_title' === $field_key ) {
			$saved = wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $value ) ), true );
			is_wp_error( $saved ) ? wp_send_json_error() : wp_send_json_success();
		}

		$data = json_decode( (string) get_post_meta( $post_id, DUPCAP_Elementor_Document::META_DATA, true ), true );
		$path = preg_match( '/^([^\[]+)\[(\d+)\]\.(.+)$/', $field_key, $parts ) ? array( $parts[1], (int) $parts[2], $parts[3] ) : array( $field_key );

		if ( '' === $element_id || ! is_array( $data ) || ! self::set_element_field( $data, $element_id, $path, $value, $original, $is_segment ) ) {
			wp_send_json_error( null, 404 );
		}

		update_post_meta( $post_id, DUPCAP_Elementor_Document::META_DATA, wp_slash( wp_json_encode( $data ) ) );
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' ); // Elementor's cached widget HTML, otherwise the old text keeps showing.
		wp_update_post( array( 'ID' => $post_id ) ); // Bumps the modified date so cached rows refresh.
		wp_send_json_success();
	}

	/**
	 * Set a text setting on one element in an Elementor tree.
	 *
	 * @param array  $elements   Elementor elements, changed in place.
	 * @param string $element_id Element ID.
	 * @param array  $path       Setting path, e.g. array( 'title' ) or array( 'list', 1, 'text' ).
	 * @param string $value      New value.
	 * @param string $original   Original untranslated segment text (when segment is edited).
	 * @param bool   $is_segment Whether a single segment (paragraph/list-item) is being edited.
	 * @return bool Whether the setting was found and changed.
	 */
	protected static function set_element_field( array &$elements, $element_id, array $path, $value, $original = '', $is_segment = false ) {
		foreach ( $elements as &$element ) {
			if ( isset( $element['id'] ) && (string) $element['id'] === $element_id ) {
				$target = &$element['settings'];
				foreach ( $path as $key ) {
					if ( ! is_array( $target ) || ! isset( $target[ $key ] ) ) {
						return false;
					}
					$target = &$target[ $key ];
				}
				if ( ! is_string( $target ) ) {
					return false;
				}
				if ( $is_segment && '' !== $original ) {
					$target = self::replace_segment_in_html( $target, $original, $value );
				} else {
					$target = $value;
				}
				return true;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && self::set_element_field( $element['elements'], $element_id, $path, $value, $original, $is_segment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace a single paragraph or list segment inside a multi-paragraph HTML string.
	 *
	 * @param string $html     Existing HTML content.
	 * @param string $original Original segment text.
	 * @param string $value    New translated value.
	 * @return string Updated HTML.
	 */
	protected static function replace_segment_in_html( $html, $original, $value ) {
		// 1. Direct substring match.
		$pos = strpos( $html, $original );
		if ( false !== $pos ) {
			return substr_replace( $html, $value, $pos, strlen( $original ) );
		}

		// 2. Block tag match (<p>, <li>, etc.).
		if ( preg_match_all( '#<([a-z0-9]+)\b([^>]*)>(.*?)</\1>#is', $html, $matches, PREG_SET_ORDER ) ) {
			$norm_orig = class_exists( 'DUPCAP_Untranslated_Collector' ) ? DUPCAP_Untranslated_Collector::normalize_text( $original ) : trim( strip_tags( $original ) );
			foreach ( $matches as $match ) {
				$block_full = $match[0];
				$tag        = $match[1];
				$attrs      = $match[2];
				$inner_html = $match[3];
				$norm_block = class_exists( 'DUPCAP_Untranslated_Collector' ) ? DUPCAP_Untranslated_Collector::normalize_text( $inner_html ) : trim( strip_tags( $inner_html ) );

				if ( $norm_block === $norm_orig || ( '' !== $norm_orig && class_exists( 'DUPCAP_Elementor_Fields' ) && DUPCAP_Elementor_Fields::is_text_substantially_untranslated( $norm_orig, $norm_block ) ) ) {
					$replacement = '<' . $tag . $attrs . '>' . $value . '</' . $tag . '>';
					$bpos        = strpos( $html, $block_full );
					if ( false !== $bpos ) {
						return substr_replace( $html, $replacement, $bpos, strlen( $block_full ) );
					}
				}
			}
		}

		// 3. Fallback: decoded entity match.
		$decoded_orig = html_entity_decode( $original, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$pos          = function_exists( 'mb_stripos' ) ? mb_stripos( $html, $decoded_orig ) : stripos( $html, $decoded_orig );
		if ( false !== $pos ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $decoded_orig ) : strlen( $decoded_orig );
			return function_exists( 'mb_substr' )
				? mb_substr( $html, 0, $pos ) . $value . mb_substr( $html, $pos + $len )
				: substr( $html, 0, $pos ) . $value . substr( $html, $pos + $len );
		}

		return $html;
	}

	/**
	 * In the block editor, select the text named by the edit link.
	 *
	 * @return void
	 */
	public static function enqueue_block_editor_focus() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI hint; only loaded for administrators.
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET[ self::FOCUS_TEXT_ARG ] ) ) {
			return;
		}

		$js_file = DUPCAP_DIR_PATH . 'assets/js/dupcap-block-editor-focus.js';

		wp_enqueue_script(
			self::BLOCK_FOCUS_HANDLE,
			DUPCAP_URL . 'assets/js/dupcap-block-editor-focus.js',
			array( 'wp-data', 'wp-dom-ready', 'wp-block-editor' ),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : DUPCAP_VERSION,
			true
		);
	}

	/**
	 * In the Elementor editor, select the widget named by the edit link.
	 *
	 * @return void
	 */
	public static function enqueue_editor_focus() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI hint; only loaded for administrators.
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET[ self::FOCUS_ARG ] ) ) {
			return;
		}

		$js_file = DUPCAP_DIR_PATH . 'assets/js/dupcap-elementor-focus.js';

		wp_enqueue_script(
			self::FOCUS_HANDLE,
			DUPCAP_URL . 'assets/js/dupcap-elementor-focus.js',
			array( 'elementor-editor' ),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : DUPCAP_VERSION,
			true
		);
	}

	/**
	 * Whether the current user may use the inspector on this request.
	 *
	 * @return bool
	 */
	private static function can_inspect() {
		return ! is_admin()
			&& is_singular()
			&& current_user_can( 'manage_options' )
			&& function_exists( 'pll_get_post_language' );
	}

	/**
	 * Whether the panel was asked for on this request.
	 *
	 * @return bool
	 */
	private static function is_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view toggle, gated by manage_options.
		return isset( $_GET[ self::QUERY_ARG ] ) && '1' === sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) );
	}

	/**
	 * Admin bar item that opens or closes the panel.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar.
	 * @return void
	 */
	public static function add_admin_bar_item( $admin_bar ) {
		if ( ! $admin_bar instanceof WP_Admin_Bar || ! self::can_inspect() ) {
			return;
		}

		$open = self::is_requested();

		$admin_bar->add_node(
			array(
				'id'    => self::HANDLE,
				'title' => esc_html__( 'Untranslated Inspector', 'duplicate-content-addon-for-polylang' ),
				'href'  => $open ? remove_query_arg( self::QUERY_ARG ) : add_query_arg( self::QUERY_ARG, '1', remove_query_arg( 'frontend-inspector' ) ),
				'meta'  => array(
					'title' => $open
						? esc_attr__( 'Close the Untranslated Inspector', 'duplicate-content-addon-for-polylang' )
						: esc_attr__( 'Show text on this page that is still in the original language', 'duplicate-content-addon-for-polylang' ),
				),
			)
		);
	}

	/**
	 * Build the rows and queue the panel when it was asked for.
	 *
	 * @return void
	 */
	public static function maybe_boot() {
		if ( ! self::is_requested() || ! self::can_inspect() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		self::$result = DUPCAP_Untranslated_Collector::for_post( get_queried_object_id() );

		add_action( 'elementor/frontend/get_builder_content', array( __CLASS__, 'record_document' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// Before wp_print_footer_scripts (20), after the header and footer have rendered.
		add_action( 'wp_footer', array( __CLASS__, 'localize_result' ), 5 );
	}

	/**
	 * Remember an Elementor document rendered on this page.
	 *
	 * @param object $document Elementor document.
	 * @return void
	 */
	public static function record_document( $document ) {
		if ( ! self::can_inspect() ) {
			return;
		}
		if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
			self::$documents[] = (int) $document->get_main_id();
		}
	}

	/**
	 * Add rows for rendered templates and hand everything to the script.
	 *
	 * @return void
	 */
	public static function localize_result() {
		if ( null === self::$result || ! self::can_inspect() ) {
			return;
		}

		if ( DUPCAP_Untranslated_Collector::STATUS_OK === self::$result['status'] ) {
			$page_id = get_queried_object_id();
			foreach ( array_unique( self::$documents ) as $document_id ) {
				if ( $document_id === $page_id ) {
					continue;
				}
				$template               = DUPCAP_Untranslated_Collector::for_template( $document_id, self::$result['current_lang'], self::$result['source_lang'] );
				self::$result['rows']   = array_merge( self::$result['rows'], $template['rows'] );
				self::$result['total'] += $template['total'];
			}
		}

		wp_localize_script(
			self::HANDLE,
			'dupcapFrontendInspector',
			array(
				'result'   => self::$result,
				'closeUrl' => esc_url_raw( remove_query_arg( self::QUERY_ARG ) ),
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'dupcap_quick_edit' ),
				'i18n'     => self::strings(),
			)
		);
	}

	/**
	 * Enqueue panel assets.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( null === self::$result || ! self::can_inspect() ) {
			return;
		}

		$css_file = DUPCAP_DIR_PATH . 'assets/css/dupcap-frontend-inspector.css';
		$js_file  = DUPCAP_DIR_PATH . 'assets/js/dupcap-frontend-inspector.js';

		wp_enqueue_style(
			self::HANDLE,
			DUPCAP_URL . 'assets/css/dupcap-frontend-inspector.css',
			array(),
			file_exists( $css_file ) ? (string) filemtime( $css_file ) : DUPCAP_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			DUPCAP_URL . 'assets/js/dupcap-frontend-inspector.js',
			array(),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : DUPCAP_VERSION,
			true
		);
	}

	/**
	 * Panel copy.
	 *
	 * @return array<string, string>
	 */
	private static function strings() {
		return array(
			'title'             => __( 'Untranslated Inspector', 'duplicate-content-addon-for-polylang' ),
			'brand'             => __( 'Toolkit for Polylang', 'duplicate-content-addon-for-polylang' ),
			'close'             => __( 'Close', 'duplicate-content-addon-for-polylang' ),
			'themeDark'         => __( 'Switch to dark mode', 'duplicate-content-addon-for-polylang' ),
			'themeLight'        => __( 'Switch to light mode', 'duplicate-content-addon-for-polylang' ),
			/* translators: 1: current language code 2: source language code */
			'comparing'         => __( 'Comparing %1$s with the original (%2$s).', 'duplicate-content-addon-for-polylang' ),
			'progressTitle'     => __( 'Translation progress', 'duplicate-content-addon-for-polylang' ),
			/* translators: %d: number of untranslated strings */
			'remaining'         => __( '%d remaining', 'duplicate-content-addon-for-polylang' ),
			/* translators: 1: translated strings 2: compared strings 3: percentage, e.g. 25% */
			'progressOf'        => __( '%1$d of %2$d translated (%3$s)', 'duplicate-content-addon-for-polylang' ),
			'none'              => __( 'No untranslated text found on this page.', 'duplicate-content-addon-for-polylang' ),
			'search'            => __( 'Search untranslated strings…', 'duplicate-content-addon-for-polylang' ),
			'noMatches'         => __( 'No strings match your search.', 'duplicate-content-addon-for-polylang' ),
			'groupElementor'    => __( 'Elementor widgets', 'duplicate-content-addon-for-polylang' ),
			'groupTitle'        => __( 'Title', 'duplicate-content-addon-for-polylang' ),
			'groupText'         => __( 'Content', 'duplicate-content-addon-for-polylang' ),
			'edit'              => __( 'Edit translation', 'duplicate-content-addon-for-polylang' ),
			'copy'              => __( 'Copy', 'duplicate-content-addon-for-polylang' ),
			'copied'            => __( 'Copied', 'duplicate-content-addon-for-polylang' ),
			'showHighlights'    => __( 'Show highlights on page', 'duplicate-content-addon-for-polylang' ),
			'notFound'          => __( 'Not on this page right now. It may be in a popup that has not loaded, content shown only under certain conditions, or a turned-off widget. Use Edit translation to see it in Elementor.', 'duplicate-content-addon-for-polylang' ),
			'hiddenPopup'       => __( 'Inside a popup that is closed right now.', 'duplicate-content-addon-for-polylang' ),
			'hiddenSlider'      => __( 'On a slide that is not showing right now.', 'duplicate-content-addon-for-polylang' ),
			'hiddenTab'         => __( 'Inside a tab or accordion that is closed right now.', 'duplicate-content-addon-for-polylang' ),
			'hiddenDevice'      => __( 'Hidden on this screen size. Use responsive mode or resize the window to see it.', 'duplicate-content-addon-for-polylang' ),
			'hiddenOther'       => __( 'Hidden on the page right now, for example by CSS or a display condition.', 'duplicate-content-addon-for-polylang' ),
			'reveal'            => __( 'Show it on the page', 'duplicate-content-addon-for-polylang' ),
			'quickEdit'         => __( 'Quick edit', 'duplicate-content-addon-for-polylang' ),
			'save'              => __( 'Save', 'duplicate-content-addon-for-polylang' ),
			'saving'            => __( 'Saving...', 'duplicate-content-addon-for-polylang' ),
			'cancel'            => __( 'Cancel', 'duplicate-content-addon-for-polylang' ),
			'saveFailed'        => __( 'Could not save. Try Edit translation instead.', 'duplicate-content-addon-for-polylang' ),
			'sourceLanguage'    => __( 'This page is in the original language, so there is nothing to compare.', 'duplicate-content-addon-for-polylang' ),
			'noSource'          => __( 'This page has no original-language version to compare with.', 'duplicate-content-addon-for-polylang' ),
			'sourceUnpublished' => __( 'The original-language version of this page is not published.', 'duplicate-content-addon-for-polylang' ),
			'notTranslatable'   => __( 'This page is not managed by Polylang.', 'duplicate-content-addon-for-polylang' ),
		);
	}
}
