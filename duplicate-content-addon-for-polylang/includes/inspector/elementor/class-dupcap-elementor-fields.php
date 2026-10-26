<?php
/**
 * Registry of translatable Elementor widget fields.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Elementor_Fields {

	/**
	 * Simple text fields keyed by widget type.
	 *
	 * @return array<string, string[]>
	 */
	public static function simple_fields() {
		$fields = array(
			'heading'         => array( 'title', 'sub_title' ),
			'text-editor'     => array( 'editor' ),
			'button'          => array( 'text' ),
			'icon-box'        => array( 'title_text', 'description_text' ),
			'image-box'       => array( 'title_text', 'description_text' ),
			'icon-list'       => array(),
			'alert'           => array( 'alert_title', 'alert_description' ),
			'call-to-action'  => array( 'title', 'description', 'button' ),
			'testimonial'     => array( 'testimonial_content', 'testimonial_name', 'testimonial_job' ),
			'counter'         => array( 'title', 'prefix', 'suffix' ),
			'progress'        => array( 'title' ),
			'star-rating'     => array( 'title' ),
			'html'            => array( 'html' ),
			'shortcode'       => array( 'shortcode' ),
			'divider'         => array( 'text' ),
			'read-more'       => array( 'text' ),
			'price-list'      => array(),
			'price-table'     => array( 'heading', 'sub_heading', 'button_text', 'footer_additional_info' ),
			'flip-box'        => array( 'title_text_a', 'description_text_a', 'title_text_b', 'description_text_b', 'button_text' ),
			'animated-headline' => array( 'before_text', 'highlighted_text', 'rotating_text', 'after_text' ),
			'loop-grid'       => array( 'pagination_prev_label', 'pagination_next_label', 'text', 'load_more_no_posts_custom_message' ),
			'loop-carousel'   => array( 'pagination_prev_label', 'pagination_next_label', 'text', 'load_more_no_posts_custom_message' ),
			// Elementor v4 atomic widgets. Their text is wrapped in a typed value, see get_setting_value().
			'e-heading'       => array( 'title' ),
			'e-paragraph'     => array( 'paragraph' ),
			'e-button'        => array( 'text' ),
			'e-form-submit-button' => array( 'text' ),
			'e-form-input'    => array( 'placeholder' ),
			'e-form-textarea' => array( 'placeholder' ),
			'e-form-label'    => array( 'text' ),
			'e-image'         => array( 'image.src.alt' ),
		);

		/**
		 * Filter simple translatable Elementor widget fields.
		 *
		 * @param array $fields Widget type => field keys.
		 */
		return apply_filters( 'dupcap_elementor_simple_fields', $fields );
	}

	/**
	 * Link/URL setting keys keyed by widget type.
	 *
	 * @return array<string, string[]>
	 */
	public static function simple_link_fields() {
		$fields = array(
			'button'         => array( 'link' ),
			'heading'        => array( 'link' ),
			'icon'           => array( 'link' ),
			'icon-box'       => array( 'link' ),
			'image-box'      => array( 'link' ),
			'image'          => array( 'link' ),
			'call-to-action' => array( 'link' ),
			'read-more'      => array( 'link' ),
			'flip-box'       => array( 'link' ),
			'price-table'    => array( 'link' ),
		);

		/**
		 * Filter Elementor widget link settings.
		 *
		 * @param array $fields Widget type => link setting keys.
		 */
		return apply_filters( 'dupcap_elementor_simple_link_fields', $fields );
	}

	/**
	 * Repeater field definitions keyed by widget type.
	 *
	 * Each entry: repeater_key => [ sub_field_keys ].
	 *
	 * @return array<string, array<string, string[]>>
	 */
	public static function repeater_fields() {
		$fields = array(
			'icon-list' => array(
				'icon_list' => array( 'text', 'link' ),
			),
			'tabs' => array(
				'tabs' => array( 'tab_title', 'tab_content' ),
			),
			'accordion' => array(
				'tabs' => array( 'tab_title', 'tab_content' ),
			),
			'toggle' => array(
				'tabs' => array( 'tab_title', 'tab_content' ),
			),
			'slides' => array(
				'slides' => array( 'heading', 'description', 'button_text', 'link' ),
			),
			'loop-grid' => array(
				'alternate_templates' => array( 'template_id' ),
			),
			'loop-carousel' => array(
				'alternate_templates' => array( 'template_id' ),
			),
			'price-list' => array(
				'price_list' => array( 'title', 'item_description', 'price', 'link' ),
			),
			'price-table' => array(
				'features_list' => array( 'item_text' ),
			),
			'social-icons' => array(
				'social_icon_list' => array( 'link' ),
			),
		);

		/**
		 * Filter repeater translatable Elementor widget fields.
		 *
		 * @param array $fields Widget type => repeater key => sub field keys.
		 */
		return apply_filters( 'dupcap_elementor_repeater_fields', $fields );
	}

	/**
	 * Human label for a field key.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	public static function field_label( $field ) {
		$labels = array(
			'title'                  => __( 'Title', 'duplicate-content-addon-for-polylang' ),
			'sub_title'              => __( 'Subtitle', 'duplicate-content-addon-for-polylang' ),
			'editor'                 => __( 'Content', 'duplicate-content-addon-for-polylang' ),
			'text'                   => __( 'Text', 'duplicate-content-addon-for-polylang' ),
			'title_text'             => __( 'Title', 'duplicate-content-addon-for-polylang' ),
			'description_text'       => __( 'Description', 'duplicate-content-addon-for-polylang' ),
			'alert_title'            => __( 'Alert title', 'duplicate-content-addon-for-polylang' ),
			'alert_description'      => __( 'Alert description', 'duplicate-content-addon-for-polylang' ),
			'description'            => __( 'Description', 'duplicate-content-addon-for-polylang' ),
			'button'                 => __( 'Button text', 'duplicate-content-addon-for-polylang' ),
			'button_text'            => __( 'Button text', 'duplicate-content-addon-for-polylang' ),
			'testimonial_content'    => __( 'Testimonial content', 'duplicate-content-addon-for-polylang' ),
			'testimonial_name'       => __( 'Testimonial name', 'duplicate-content-addon-for-polylang' ),
			'testimonial_job'        => __( 'Testimonial job', 'duplicate-content-addon-for-polylang' ),
			'html'                   => __( 'HTML', 'duplicate-content-addon-for-polylang' ),
			'shortcode'              => __( 'Shortcode', 'duplicate-content-addon-for-polylang' ),
			'tab_title'              => __( 'Tab title', 'duplicate-content-addon-for-polylang' ),
			'tab_content'            => __( 'Tab content', 'duplicate-content-addon-for-polylang' ),
			'heading'                => __( 'Heading', 'duplicate-content-addon-for-polylang' ),
			'sub_heading'            => __( 'Subheading', 'duplicate-content-addon-for-polylang' ),
			'item_text'              => __( 'Feature text', 'duplicate-content-addon-for-polylang' ),
			'item_description'       => __( 'Item description', 'duplicate-content-addon-for-polylang' ),
			'price'                  => __( 'Price', 'duplicate-content-addon-for-polylang' ),
			'link'                   => __( 'Link', 'duplicate-content-addon-for-polylang' ),
			'item_link'              => __( 'Link', 'duplicate-content-addon-for-polylang' ),
			'url'                    => __( 'URL', 'duplicate-content-addon-for-polylang' ),
			'template_id'            => __( 'Loop template', 'duplicate-content-addon-for-polylang' ),
			'pagination_prev_label'  => __( 'Previous label', 'duplicate-content-addon-for-polylang' ),
			'pagination_next_label'  => __( 'Next label', 'duplicate-content-addon-for-polylang' ),
			'load_more_no_posts_custom_message' => __( 'No posts message', 'duplicate-content-addon-for-polylang' ),
			'alt'                    => __( 'Image alt text', 'duplicate-content-addon-for-polylang' ),
			'key'                    => __( 'Option label', 'duplicate-content-addon-for-polylang' ),
			'paragraph'              => __( 'Paragraph', 'duplicate-content-addon-for-polylang' ),
			'placeholder'            => __( 'Placeholder', 'duplicate-content-addon-for-polylang' ),
		);

		if ( isset( $labels[ $field ] ) ) {
			return $labels[ $field ];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $field ) );
	}

	/**
	 * Elementor Pro loop builder widget types.
	 *
	 * @return string[]
	 */
	public static function loop_widget_types() {
		$types = array( 'loop-grid', 'loop-carousel' );

		/**
		 * Filter Elementor loop widget types scanned for template references.
		 *
		 * @param string[] $types Widget type slugs.
		 */
		return apply_filters( 'dupcap_elementor_loop_widget_types', $types );
	}

	/**
	 * Whether a widget type is a Loop Grid / Loop Carousel widget.
	 *
	 * @param string $widget_type Widget type slug.
	 * @return bool
	 */
	public static function is_loop_widget( $widget_type ) {
		return in_array( (string) $widget_type, self::loop_widget_types(), true );
	}

	/**
	 * Loop template post IDs referenced in loop widget settings.
	 *
	 * @param array $settings Widget settings.
	 * @return array<string, int> field_key => template post ID.
	 */
	public static function loop_template_refs_from_settings( array $settings ) {
		$refs = array();

		if ( isset( $settings['template_id'] ) && is_numeric( $settings['template_id'] ) ) {
			$id = (int) $settings['template_id'];
			if ( $id > 0 ) {
				$refs['template_id'] = $id;
			}
		}

		if ( isset( $settings['alternate_templates'] ) && is_array( $settings['alternate_templates'] ) ) {
			foreach ( $settings['alternate_templates'] as $index => $row ) {
				if ( ! is_array( $row ) || ! isset( $row['template_id'] ) || ! is_numeric( $row['template_id'] ) ) {
					continue;
				}
				$id = (int) $row['template_id'];
				if ( $id > 0 ) {
					$refs[ 'alternate_templates[' . (int) $index . '].template_id' ] = $id;
				}
			}
		}

		return $refs;
	}

	/**
	 * Human label for a loop widget type.
	 *
	 * @param string $widget_type Widget type slug.
	 * @return string
	 */
	public static function loop_widget_label( $widget_type ) {
		$labels = array(
			'loop-grid'     => __( 'Loop Grid', 'duplicate-content-addon-for-polylang' ),
			'loop-carousel' => __( 'Loop Carousel', 'duplicate-content-addon-for-polylang' ),
		);

		$widget_type = (string) $widget_type;
		if ( isset( $labels[ $widget_type ] ) ) {
			return $labels[ $widget_type ];
		}

		return self::widget_label( $widget_type );
	}

	/**
	 * Plain-language explanation for Loop Grid / Loop Carousel template mismatches.
	 *
	 * @param string $code              Finding code.
	 * @param string $lang_label        Target language name.
	 * @param string $widget_label      Widget label (Loop Grid, etc.).
	 * @param string $expected_title    Correct Loop Item title for this language.
	 * @param string $actual_title      Currently selected Loop Item title.
	 * @param string $source_loop_title Default-language Loop Item title.
	 * @return string
	 */
	public static function loop_template_issue_summary(
		$code,
		$lang_label,
		$widget_label,
		$expected_title,
		$actual_title,
		$source_loop_title
	) {
		$lang_label      = trim( (string) $lang_label );
		$widget_label    = trim( (string) $widget_label );
		$expected_title  = trim( (string) $expected_title );
		$actual_title    = trim( (string) $actual_title );
		$source_loop_title = trim( (string) $source_loop_title );

		if ( 'wrong_loop_template' === $code ) {
			if ( '' !== $expected_title ) {
				return sprintf(
					/* translators: 1: current template 2: language name 3: correct template */
					__( 'This Loop Grid uses template "%1$s". Change it to the %2$s template: %3$s', 'duplicate-content-addon-for-polylang' ),
					$actual_title ? $actual_title : $source_loop_title,
					$lang_label ? $lang_label : __( 'translated', 'duplicate-content-addon-for-polylang' ),
					$expected_title
				);
			}
			return sprintf(
				/* translators: 1: current template 2: language name */
				__( 'This Loop Grid uses template "%1$s". Change it to the %2$s loop template.', 'duplicate-content-addon-for-polylang' ),
				$actual_title ? $actual_title : $source_loop_title,
				$lang_label ? $lang_label : __( 'translated', 'duplicate-content-addon-for-polylang' )
			);
		}

		if ( 'missing_loop_template' === $code ) {
			if ( '' !== $source_loop_title ) {
				return sprintf(
					/* translators: 1: language name 2: correct template */
					__( 'This Loop Grid has no %1$s template selected. Assign the %1$s loop template: %2$s', 'duplicate-content-addon-for-polylang' ),
					$lang_label ? $lang_label : __( 'translated', 'duplicate-content-addon-for-polylang' ),
					$source_loop_title
				);
			}
			return sprintf(
				/* translators: 1: language name */
				__( 'This Loop Grid has no %1$s template selected. Select a %1$s loop template in Loop Grid settings.', 'duplicate-content-addon-for-polylang' ),
				$lang_label ? $lang_label : __( 'translated', 'duplicate-content-addon-for-polylang' )
			);
		}

		if ( 'missing_loop_item_translation' === $code ) {
			return sprintf(
				/* translators: 1: loop template title 2: language name */
				__( 'This Loop Grid uses template "%1$s". There is no %2$s translation for this template yet.', 'duplicate-content-addon-for-polylang' ),
				$source_loop_title ? $source_loop_title : $actual_title,
				$lang_label ? $lang_label : __( 'translated', 'duplicate-content-addon-for-polylang' )
			);
		}

		return '';
	}

	/**
	 * Whether a field key refers to a Loop Grid / Carousel template selection.
	 *
	 * @param string $field_key Field key (may include repeater index).
	 * @return bool
	 */
	public static function is_loop_template_field( $field_key ) {
		$base = preg_replace( '/^.*\./', '', (string) $field_key );
		return 'template_id' === $base;
	}

	/**
	 * Extract translatable values from a widget element.
	 *
	 * @param array $element Element node.
	 * @return array<string, string> field_key => value.
	 */
	public static function extract_from_element( array $element ) {
		$widget_type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
		$settings    = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$values      = array();

		if ( ! $widget_type ) {
			return $values;
		}

		$simple = self::simple_fields();
		if ( isset( $simple[ $widget_type ] ) ) {
			foreach ( $simple[ $widget_type ] as $key ) {
				$value = self::get_setting_value( $settings, $key );
				if ( self::is_meaningful_text( $value ) ) {
					$values[ $key ] = $value;
				}
			}
		}

		// Atomic select: each option is a { key, value } pair and the key is the visible label.
		if ( 'e-form-select' === $widget_type && isset( $settings['options']['value'] ) && is_array( $settings['options']['value'] ) ) {
			foreach ( $settings['options']['value'] as $index => $option ) {
				$pair  = ( is_array( $option ) && isset( $option['value'] ) && is_array( $option['value'] ) ) ? $option['value'] : array();
				$label = self::get_setting_value( $pair, 'key' );
				if ( self::is_meaningful_text( $label ) ) {
					$values[ 'options[' . (int) $index . '].key' ] = $label;
				}
			}
		}

		$simple_links = self::simple_link_fields();
		if ( isset( $simple_links[ $widget_type ] ) ) {
			foreach ( $simple_links[ $widget_type ] as $key ) {
				$url = self::get_link_url( $settings, $key );
				if ( '' !== $url ) {
					$values[ $key ] = $url;
				}
			}
		}

		$repeaters = self::repeater_fields();
		if ( isset( $repeaters[ $widget_type ] ) ) {
			foreach ( $repeaters[ $widget_type ] as $repeater_key => $sub_keys ) {
				$rows = isset( $settings[ $repeater_key ] ) && is_array( $settings[ $repeater_key ] )
					? $settings[ $repeater_key ]
					: array();

				foreach ( $rows as $index => $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					foreach ( $sub_keys as $sub_key ) {
						if ( 'template_id' === $sub_key ) {
							continue;
						}
						$field_key = $repeater_key . '[' . $index . '].' . $sub_key;
						if ( self::is_link_setting_key( $sub_key ) ) {
							$url = self::get_link_url( $row, $sub_key );
							if ( '' !== $url ) {
								$values[ $field_key ] = $url;
							}
							continue;
						}

						$value = self::get_setting_value( $row, $sub_key );
						if ( self::is_meaningful_text( $value ) ) {
							$values[ $field_key ] = $value;
						}
					}
				}
			}
		}

		// Catch common menu / mega-menu style repeaters not in the registry.
		foreach ( self::discover_link_values( $settings ) as $field_key => $url ) {
			if ( ! isset( $values[ $field_key ] ) ) {
				$values[ $field_key ] = $url;
			}
		}

		return $values;
	}

	/**
	 * Discover link-like settings in unknown menu/list widgets.
	 *
	 * @param array $settings Widget settings.
	 * @return array<string, string>
	 */
	protected static function discover_link_values( array $settings ) {
		$found = array();

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			if ( self::is_link_setting_key( $key ) ) {
				$url = self::normalize_link_value( $value );
				if ( '' !== $url ) {
					$found[ $key ] = $url;
				}
				continue;
			}

			if ( ! is_array( $value ) || empty( $value ) || ! isset( $value[0] ) || ! is_array( $value[0] ) ) {
				continue;
			}

			// Looks like a repeater.
			foreach ( $value as $index => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( $row as $sub_key => $sub_value ) {
					if ( ! is_string( $sub_key ) || ! self::is_link_setting_key( $sub_key ) ) {
						continue;
					}
					$url = self::normalize_link_value( $sub_value );
					if ( '' !== $url ) {
						$found[ $key . '[' . $index . '].' . $sub_key ] = $url;
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Whether a setting key looks like a navigation link field.
	 *
	 * Bare "url" keys are excluded - Elementor uses those for media (carousel images, etc.).
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_link_setting_key( $key ) {
		$key = strtolower( (string) $key );
		if ( in_array( $key, array( 'link', 'item_link', 'menu_link', 'button_link', 'cta_link' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/(^|_)link$/', $key );
	}

	/**
	 * Whether a URL points at a media/asset file rather than a page.
	 *
	 * @param string $url URL or path.
	 * @return bool
	 */
	public static function is_media_asset_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		if ( false !== strpos( $url, '/wp-content/uploads/' ) ) {
			return true;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : $url;
		return (bool) preg_match( '/\.(jpe?g|png|gif|webp|svg|avif|bmp|ico|mp4|webm|mov|pdf|zip|woff2?|ttf|eot)(\?|#|$)/i', $path );
	}

	/**
	 * Whether an extracted field key is a link/URL value.
	 *
	 * @param string $field_key Field key (may include repeater index).
	 * @return bool
	 */
	public static function is_link_field( $field_key ) {
		$base = preg_replace( '/^.*\./', '', (string) $field_key );
		return self::is_link_setting_key( $base );
	}

	/**
	 * Read a link URL from settings.
	 *
	 * @param array  $settings Settings array.
	 * @param string $key      Setting key.
	 * @return string
	 */
	public static function get_link_url( array $settings, $key ) {
		if ( ! isset( $settings[ $key ] ) ) {
			return '';
		}
		return self::normalize_link_value( $settings[ $key ] );
	}

	/**
	 * Normalize Elementor link setting to a URL string.
	 *
	 * @param mixed $value Raw setting.
	 * @return string
	 */
	public static function normalize_link_value( $value ) {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) && isset( $value['url'] ) && is_scalar( $value['url'] ) ) {
			return trim( (string) $value['url'] );
		}
		// Atomic link: { "$$type": "link", "value": { "destination": { "$$type": "url", "value": "..." } } }.
		// A dynamic destination has no fixed URL to check.
		if ( is_array( $value ) && isset( $value['$$type'], $value['value']['destination'] ) && is_array( $value['value']['destination'] ) ) {
			$destination = $value['value']['destination'];
			if ( isset( $destination['$$type'], $destination['value'] ) && 'url' === $destination['$$type'] && is_string( $destination['value'] ) ) {
				return trim( $destination['value'] );
			}
		}
		return '';
	}

	/**
	 * Whether a translated widget field still holds the original text unchanged.
	 *
	 * Shared by the dashboard Elementor scan and the frontend Untranslated
	 * Inspector so both report the same fields.
	 *
	 * @param string $field_key        Field key.
	 * @param mixed  $original_value   Value in the source-language document.
	 * @param mixed  $translated_value Value in the translated document.
	 * @return bool
	 */
	public static function is_untranslated_text( $field_key, $original_value, $translated_value, $target_lang = '' ) {
		return ! self::is_loop_template_field( $field_key )
			&& ! self::is_link_field( $field_key )
			&& self::is_meaningful_text( $translated_value )
			&& ! self::should_skip_same_text_warning( $field_key, $original_value )
			&& self::is_text_substantially_untranslated( $original_value, $translated_value, $target_lang );
	}

	/**
	 * Whether translated text is identical or substantially untranslated compared to source text.
	 *
	 * Checks exact equality, high similarity (>= 80% by default), and target script mismatch.
	 *
	 * @param mixed  $original_value   Source text.
	 * @param mixed  $translated_value Translated text.
	 * @param string $target_lang      Target language code.
	 * @return bool
	 */
	public static function is_text_substantially_untranslated( $original_value, $translated_value, $target_lang = '' ) {
		$orig  = self::normalize_text_for_compare( (string) $original_value );
		$trans = self::normalize_text_for_compare( (string) $translated_value );

		if ( '' === $orig || '' === $trans ) {
			return false;
		}

		// Exact match
		if ( $orig === $trans ) {
			return true;
		}

		// Exact match ignoring punctuation and case
		$orig_clean  = preg_replace( '/[^\p{L}\p{N}]+/u', '', self::lower( $orig ) );
		$trans_clean = preg_replace( '/[^\p{L}\p{N}]+/u', '', self::lower( $trans ) );
		if ( '' !== $orig_clean && $orig_clean === $trans_clean ) {
			return true;
		}

		// Target Script Mismatch (e.g. Hindi target expecting Devanagari, but translated has Latin text)
		if ( '' !== $target_lang && self::is_target_script_mismatch( $orig, $trans, $target_lang ) ) {
			return true;
		}

		// For short strings (< 20 chars or < 3 words), require exact/cleaned match or script mismatch to avoid cognate false positives
		$word_count = count( preg_split( '/\s+/u', $orig, -1, PREG_SPLIT_NO_EMPTY ) );
		if ( self::length( $orig ) < 20 || $word_count < 3 ) {
			return false;
		}

		// Similarity Threshold Check for sentences/paragraphs
		$threshold  = (float) apply_filters( 'dupcap_untranslated_similarity_threshold', 0.80 );
		$similarity = self::calculate_similarity( $orig, $trans );

		return $similarity >= $threshold;
	}

	/**
	 * Lowercase a string safely with or without mbstring.
	 *
	 * @param string $text Text to lowercase.
	 * @return string
	 */
	public static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * String character length safely with or without mbstring.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * Normalize text for string comparison (strip tags, decode entities, collapse whitespace).
	 *
	 * @param string $text Raw text.
	 * @return string Normalized text.
	 */
	public static function normalize_text_for_compare( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * Calculate string similarity as a float between 0.0 and 1.0.
	 *
	 * @param string $str1 First string.
	 * @param string $str2 Second string.
	 * @return float
	 */
	public static function calculate_similarity( $str1, $str2 ) {
		$str1 = self::lower( trim( (string) $str1 ) );
		$str2 = self::lower( trim( (string) $str2 ) );

		if ( $str1 === $str2 ) {
			return 1.0;
		}

		$len1 = self::length( $str1 );
		$len2 = self::length( $str2 );

		if ( 0 === $len1 || 0 === $len2 ) {
			return 0.0;
		}

		// If length ratio is below 60%, similarity cannot be >= 80%.
		$min_len = min( $len1, $len2 );
		$max_len = max( $len1, $len2 );
		if ( ( $min_len / $max_len ) < 0.60 ) {
			return 0.0;
		}

		// For long texts (> 1500 chars), use fast token/word overlap to avoid O(N^3)
		if ( $max_len > 1500 ) {
			$words1 = preg_split( '/\s+/u', $str1, -1, PREG_SPLIT_NO_EMPTY );
			$words2 = preg_split( '/\s+/u', $str2, -1, PREG_SPLIT_NO_EMPTY );
			if ( empty( $words1 ) || empty( $words2 ) ) {
				return 0.0;
			}
			$intersect = array_intersect( $words1, $words2 );
			return (float) ( count( $intersect ) / max( count( $words1 ), count( $words2 ) ) );
		}

		$percent = 0.0;
		similar_text( $str1, $str2, $percent );
		return (float) ( $percent / 100.0 );
	}

	/**
	 * Check if translated text is missing expected non-Latin script while source has Latin characters.
	 *
	 * E.g. target is Hindi ('hi') but translated text has zero Devanagari and consists of Latin letters.
	 *
	 * @param string $orig        Source text.
	 * @param string $trans       Translated text.
	 * @param string $target_lang Target language code.
	 * @return bool
	 */
	public static function is_target_script_mismatch( $orig, $trans, $target_lang ) {
		$target_lang = strtolower( trim( (string) $target_lang ) );
		if ( '' === $target_lang ) {
			return false;
		}

		$prefix = preg_replace( '/[-_].*$/', '', $target_lang );

		$script_patterns = array(
			'hi' => '/\p{Devanagari}/u',
			'mr' => '/\p{Devanagari}/u',
			'ne' => '/\p{Devanagari}/u',
			'ar' => '/\p{Arabic}/u',
			'ur' => '/\p{Arabic}/u',
			'fa' => '/\p{Arabic}/u',
			'ru' => '/\p{Cyrillic}/u',
			'uk' => '/\p{Cyrillic}/u',
			'bg' => '/\p{Cyrillic}/u',
			'el' => '/\p{Greek}/u',
			'he' => '/\p{Hebrew}/u',
			'zh' => '/\p{Han}/u',
			'ja' => '/[\p{Hiragana}\p{Katakana}\p{Han}]/u',
			'ko' => '/\p{Hangul}/u',
			'th' => '/\p{Thai}/u',
			'bn' => '/\p{Bengali}/u',
			'ta' => '/\p{Tamil}/u',
			'te' => '/\p{Telugu}/u',
			'gu' => '/\p{Gujarati}/u',
			'pa' => '/\p{Gurmukhi}/u',
		);

		if ( ! isset( $script_patterns[ $prefix ] ) ) {
			return false;
		}

		$pattern = $script_patterns[ $prefix ];

		// If translated text already has characters of the target script, not a script mismatch.
		if ( preg_match( $pattern, $trans ) ) {
			return false;
		}

		// If translated text has no letters (e.g. only numbers/symbols), skip.
		if ( ! preg_match( '/\p{L}/u', $trans ) ) {
			return false;
		}

		// If translated text has Latin letters and source text also had Latin letters:
		if ( preg_match( '/\p{Latin}/u', $orig ) && preg_match( '/\p{Latin}/u', $trans ) ) {
			$orig_words  = preg_split( '/[\s\p{P}]+/u', self::lower( $orig ), -1, PREG_SPLIT_NO_EMPTY );
			$trans_words = preg_split( '/[\s\p{P}]+/u', self::lower( $trans ), -1, PREG_SPLIT_NO_EMPTY );

			if ( ! empty( $orig_words ) && ! empty( $trans_words ) ) {
				$shared = array_intersect( $trans_words, $orig_words );
				if ( ( count( $shared ) / max( 1, count( $orig_words ) ) ) >= 0.30 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a field should skip "same text = untranslated" warnings.
	 *
	 * Shortcodes, counters, and pure symbols are often identical on purpose.
	 *
	 * @param string $field_key Field key.
	 * @param string $value     Field value.
	 * @return bool
	 */
	public static function should_skip_same_text_warning( $field_key, $value ) {
		$base = strtolower( (string) preg_replace( '/^.*\./', '', (string) $field_key ) );
		if ( in_array( $base, array( 'shortcode', 'html', 'prefix', 'suffix', 'price' ), true ) ) {
			return true;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return true;
		}

		// [shortcode ...] or {{dynamic}}
		if ( preg_match( '/^\[.+\]$/s', $value ) || preg_match( '/^\{\{.+\}\}$/s', $value ) ) {
			return true;
		}

		// Numbers / currency / units only (ASCII + euro/pound/yen via unicode escapes).
		if ( preg_match( '/^[\d\s\.\,\%\$\x{20AC}\x{00A3}\x{00A5}\/\-\+]+$/u', $value ) ) {
			return true;
		}

		// A bare URL or email address is the same in every language.
		if ( preg_match( '#^(?:https?:)?//\S+$#i', $value ) || preg_match( '#^www\.\S+$#i', $value ) || is_email( $value ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a URL looks internal to this site.
	 *
	 * @param string $url URL or path.
	 * @return bool
	 */
	public static function is_internal_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || '#' === $url || 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) ) {
			return false;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return true;
		}

		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $home && $host && strtolower( (string) $home ) === strtolower( (string) $host );
	}

	/**
	 * Normalize URL/path for equality checks.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_url_for_compare( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url ) {
			return '';
		}

		$home = untrailingslashit( home_url() );
		if ( 0 === strpos( $url, $home ) ) {
			$url = substr( $url, strlen( $home ) );
			$url = '' === $url ? '/' : $url;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $path ) && '' !== $path ) {
			$query = wp_parse_url( $url, PHP_URL_QUERY );
			$url   = rawurldecode( $path ) . ( $query ? '?' . $query : '' );
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return untrailingslashit( mb_strtolower( $url, 'UTF-8' ) );
		}

		return untrailingslashit( strtolower( $url ) );
	}

	/**
	 * Get repeater row counts for a widget.
	 *
	 * @param array $element Element node.
	 * @return array<string, int> repeater_key => count.
	 */
	public static function repeater_row_counts( array $element ) {
		$widget_type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
		$settings    = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$counts      = array();

		$repeaters = self::repeater_fields();
		if ( ! isset( $repeaters[ $widget_type ] ) ) {
			return $counts;
		}

		foreach ( array_keys( $repeaters[ $widget_type ] ) as $repeater_key ) {
			$rows = isset( $settings[ $repeater_key ] ) && is_array( $settings[ $repeater_key ] )
				? $settings[ $repeater_key ]
				: array();
			$counts[ $repeater_key ] = count( $rows );
		}

		return $counts;
	}

	/**
	 * Read a setting value, stripping tags for comparison.
	 *
	 * @param array  $settings Settings array.
	 * @param string $key      Setting key.
	 * @return string
	 */
	public static function get_setting_value( array $settings, $key ) {
		if ( false !== strpos( (string) $key, '.' ) ) {
			// A path into atomic typed values, e.g. image.src.alt.
			$value = $settings;
			foreach ( explode( '.', (string) $key ) as $segment ) {
				if ( is_array( $value ) && isset( $value['$$type'], $value['value'] ) && is_array( $value['value'] ) ) {
					$value = $value['value'];
				}
				if ( ! is_array( $value ) || ! isset( $value[ $segment ] ) ) {
					return '';
				}
				$value = $value[ $segment ];
			}
		} elseif ( isset( $settings[ $key ] ) ) {
			$value = $settings[ $key ];
		} else {
			return '';
		}

		// Elementor v4 atomic widgets store text as { "$$type": "string|escaped-html", "value": "text" },
		// or, in older v4 releases, as html-v3: { "value": { "content": { "value": "text" }, "children": [] } }.
		// Dynamic tags and other typed values carry no plain text and fall through as empty.
		if ( is_array( $value ) && isset( $value['$$type'], $value['value'] ) ) {
			$inner = $value['value'];
			if ( is_array( $inner ) && ( array_key_exists( 'content', $inner ) || array_key_exists( 'children', $inner ) ) ) {
				$text = ( isset( $inner['content']['value'] ) && is_string( $inner['content']['value'] ) ) ? $inner['content']['value'] : '';
				if ( ! empty( $inner['children'] ) && is_array( $inner['children'] ) ) {
					$text .= ' ' . self::html_v3_children_text( $inner['children'] );
				}
				$inner = trim( $text );
			}
			if ( is_string( $inner ) ) {
				// Line breaks become spaces so "Ski<br>Resort" does not read as "SkiResort".
				$value = preg_replace( '#<br\s*/?>#i', ' ', $inner );
			}
		}

		if ( is_array( $value ) ) {
			return '';
		}

		$value = (string) $value;
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' );
		return trim( $value );
	}

	/**
	 * Setting names that never hold visible text, even when they look like it.
	 *
	 * Only used to guess at widgets that are not in the field registry.
	 */
	const SIGNATURE_STYLE_PATTERN = '/(typography|colou?r|size|align|margin|padding|border|shadow|css|_class|_id$|width|height|position|spacing|gap|radius|animation|font|weight|transform|hover|icon|image|background|opacity|z_index|layout|view|skin|html_tag|header_size)/i';

	/**
	 * Setting names that look like visible text.
	 */
	const SIGNATURE_TEXT_PATTERN = '/(^|_)(title|text|content|description|heading|label|caption|editor|placeholder|subtitle|message)(_|$)/i';

	/**
	 * What a translator would have to react to in an Elementor document: its text and its links.
	 *
	 * Styling (typography, colours, spacing, classes, sizes) is left out, so a design
	 * tweak on the source does not make its translations look outdated. Widgets in the
	 * field registry use their listed fields; a widget from another plugin falls back to
	 * settings whose names look like text.
	 *
	 * @since 2.1.2
	 *
	 * @param array $elements Decoded `_elementor_data` element tree.
	 * @return string One line per field, in document order.
	 */
	public static function content_signature( array $elements ) {
		$lines = array();
		self::collect_signature( $elements, $lines );
		return implode( "\n", $lines );
	}

	/**
	 * Walk the element tree and add one signature line per text or link field.
	 *
	 * @param array $elements Elements.
	 * @param array $lines    Lines, by reference.
	 * @return void
	 */
	protected static function collect_signature( array $elements, array &$lines ) {
		$known = null;

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$widget = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
			if ( '' !== $widget && isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				if ( null === $known ) {
					$known = array_merge( array_keys( self::simple_fields() ), array_keys( self::repeater_fields() ), array_keys( self::simple_link_fields() ) );
				}

				$fields = self::extract_from_element( $element );
				if ( ! in_array( $widget, $known, true ) ) {
					$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
					$fields  += self::guess_text_fields( $settings, '' );
				}

				foreach ( $fields as $key => $value ) {
					$value = trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
					if ( '' !== $value ) {
						$lines[] = $widget . '|' . $key . '=' . $value;
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_signature( $element['elements'], $lines );
			}
		}
	}

	/**
	 * Text-looking settings of a widget that is not in the field registry.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $prefix   Key prefix for nested repeater rows.
	 * @return array<string, string> field_key => text.
	 */
	protected static function guess_text_fields( array $settings, $prefix ) {
		$found = array();

		foreach ( $settings as $name => $value ) {
			$name = (string) $name;
			if ( '' === $name || 0 === strpos( $name, '_' ) || preg_match( self::SIGNATURE_STYLE_PATTERN, $name ) ) {
				continue;
			}

			// A repeater: a list of rows, each row a settings array.
			if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) && ! isset( $value['$$type'] ) ) {
				foreach ( $value as $index => $row ) {
					if ( is_array( $row ) ) {
						$found += self::guess_text_fields( $row, $prefix . $name . '[' . (int) $index . '].' );
					}
				}
				continue;
			}

			if ( ! preg_match( self::SIGNATURE_TEXT_PATTERN, $name ) ) {
				continue;
			}

			$text = self::get_setting_value( array( $name => $value ), $name );
			if ( self::is_meaningful_text( $text ) ) {
				$found[ $prefix . $name ] = $text;
			}
		}

		return $found;
	}

	/**
	 * Text of the inline children of an html-v3 value (bold, italic, links...), nested to any depth.
	 *
	 * @param array $children Child element objects, each with a plain-string content and children.
	 * @return string
	 */
	protected static function html_v3_children_text( array $children ) {
		$parts = array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			if ( isset( $child['content'] ) && is_string( $child['content'] ) && '' !== trim( $child['content'] ) ) {
				$parts[] = trim( $child['content'] );
			}
			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$nested = self::html_v3_children_text( $child['children'] );
				if ( '' !== $nested ) {
					$parts[] = $nested;
				}
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * Whether a string contains meaningful translatable text.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_meaningful_text( $value ) {
		$value = trim( (string) $value );
		return '' !== $value && '&nbsp;' !== $value;
	}

	/**
	 * Truncate a value for display without splitting multi-byte characters.
	 *
	 * @param string $value Value.
	 * @param int    $limit Character limit.
	 * @return string
	 */
	public static function snippet( $value, $limit = 120 ) {
		$value = trim( wp_strip_all_tags( self::sanitize_display_text( $value ) ) );
		$limit = max( 1, (int) $limit );

		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $value, 'UTF-8' ) <= $limit ) {
				return $value;
			}
			return mb_substr( $value, 0, $limit - 1, 'UTF-8' ) . '...';
		}

		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		$cut = substr( $value, 0, $limit - 1 );
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$cut = (string) wp_check_invalid_utf8( $cut, true );
		}

		return $cut . '...';
	}

	/**
	 * Coerce a stored finding value to a displayable string.
	 *
	 * Elementor link settings are often arrays with a `url` key. Casting those
	 * with (string) yields "Array" or a TypeError, which then blanks the row.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function scalar_text( $value ) {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) ) {
			return self::normalize_link_value( $value );
		}
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}
		return '';
	}

	/**
	 * Keep text printable: invalid UTF-8 is stripped, not discarded.
	 *
	 * WordPress esc_html() drops an entire string when any byte is invalid UTF-8.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_display_text( $value ) {
		$text = self::scalar_text( $value );
		if ( '' === $text || ! function_exists( 'wp_check_invalid_utf8' ) ) {
			return $text;
		}

		$clean = (string) wp_check_invalid_utf8( $text, true );
		return '' !== $clean ? $clean : $text;
	}

	/**
	 * Site-relative path for display or Elementor link suggestions.
	 *
	 * @param string $url        Absolute or relative URL.
	 * @param bool   $strip_lang Whether to remove a leading Polylang language directory.
	 * @param int    $limit      Character limit (0 = no limit).
	 * @return string
	 */
	public static function relative_path( $url, $strip_lang = false, $limit = 0 ) {
		$url = self::scalar_text( $url );
		if ( '' === $url ) {
			return '';
		}

		$home = untrailingslashit( home_url() );
		if ( 0 === strpos( $url, $home ) ) {
			$url = substr( $url, strlen( $home ) );
			$url = '' === $url ? '/' : $url;
		}

		if ( preg_match( '#^https?://[^/]+(/.*)?$#i', $url, $matches ) ) {
			$url = ( isset( $matches[1] ) && '' !== $matches[1] ) ? $matches[1] : '/';
		}

		$fragment = '';
		$hash_pos = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos );
			$url      = substr( $url, 0, $hash_pos );
		}

		$query = '';
		$qpos  = strpos( $url, '?' );
		if ( false !== $qpos ) {
			$query = substr( $url, $qpos );
			$url   = substr( $url, 0, $qpos );
		}

		$path = self::safe_urldecode( $url );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}

		if ( $strip_lang ) {
			$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
			$langs    = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'slug' ) ) : array();
			if ( is_array( $langs ) && ! empty( $segments ) && in_array( $segments[0], $langs, true ) ) {
				array_shift( $segments );
			}
			$path = empty( $segments ) ? '/' : '/' . implode( '/', $segments ) . '/';
		}

		$out = $path . $query . $fragment;
		return $limit > 0 ? self::snippet( $out, $limit ) : $out;
	}

	/**
	 * Hash/fragment from a URL, including the leading #.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function url_fragment( $url ) {
		$url = self::scalar_text( $url );
		$pos = strpos( $url, '#' );
		if ( false === $pos ) {
			return '';
		}
		$fragment = substr( $url, $pos );
		return '#' === $fragment ? '' : $fragment;
	}

	/**
	 * Whether a URL is only a language homepage root (e.g. /de/ or /de).
	 *
	 * @param string $url  URL or path.
	 * @param string $lang Language slug.
	 * @return bool
	 */
	public static function is_bare_language_home_path( $url, $lang = '' ) {
		$path = untrailingslashit( self::relative_path( $url ) );
		if ( '' === $path || '/' === $path ) {
			return true;
		}

		$lang = strtolower( trim( (string) $lang ) );
		if ( '' === $lang ) {
			return false;
		}

		return $path === '/' . $lang || $path === $lang;
	}

	/**
	 * Put a fragment on a URL, e.g. /hi/home/ + #features -> /hi/home/#features.
	 *
	 * @param string $url      URL or path.
	 * @param string $fragment Fragment including #.
	 * @return string
	 */
	public static function with_fragment( $url, $fragment ) {
		$url      = self::scalar_text( $url );
		$fragment = self::url_fragment( $fragment );
		$hash_pos = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$url = substr( $url, 0, $hash_pos );
		}
		if ( '' === $fragment ) {
			return $url;
		}
		$path = self::relative_path( $url );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== substr( $path, -1 ) ) {
			$path .= '/';
		}
		return $path . $fragment;
	}

	/**
	 * Decode a URL path without producing invalid UTF-8.
	 *
	 * Percent-encoded Hindi/other slugs become readable characters.
	 *
	 * @param string $path Encoded or plain path.
	 * @return string
	 */
	protected static function safe_urldecode( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return '';
		}

		$parts = explode( '/', $path );
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			$decoded = rawurldecode( $part );
			if ( $decoded === $part ) {
				continue;
			}
			$valid = function_exists( 'mb_check_encoding' )
				? mb_check_encoding( $decoded, 'UTF-8' )
				: ( ! function_exists( 'wp_check_invalid_utf8' ) || '' !== wp_check_invalid_utf8( $decoded ) );
			if ( $valid ) {
				$parts[ $i ] = $decoded;
			}
		}

		return implode( '/', $parts );
	}

	/**
	 * Readable link label: page title plus decoded path.
	 *
	 * @param string $url  Absolute or relative URL.
	 * @param string $lang Optional language slug for the expected page.
	 * @return string
	 */
	public static function readable_url( $url, $lang = '' ) {
		$url = self::scalar_text( $url );
		if ( '' === $url ) {
			return '';
		}

		$title = self::page_title_from_url( $url, $lang );
		$path  = self::display_path( $url );

		if ( '' !== $title && '' !== $path ) {
			return $title . ' (' . $path . ')';
		}
		if ( '' !== $title ) {
			return $title;
		}

		return $path;
	}

	/**
	 * Path that is safe to show in the dashboard.
	 *
	 * Broken slugs (replacement characters) are omitted so users see the page title instead.
	 *
	 * @param string $url Absolute or relative URL.
	 * @return string
	 */
	protected static function display_path( $url ) {
		$path = self::relative_path( $url );
		if ( self::path_is_readable( $path ) ) {
			return $path;
		}

		return '';
	}

	/**
	 * Whether a path can be shown without broken characters.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	protected static function path_is_readable( $path ) {
		if ( '' === $path || '/' === $path ) {
			return true;
		}
		if ( false !== strpos( $path, "\xEF\xBF\xBD" ) ) {
			return false;
		}
		if ( function_exists( 'mb_check_encoding' ) ) {
			return mb_check_encoding( $path, 'UTF-8' );
		}
		return ! function_exists( 'wp_check_invalid_utf8' ) || '' !== wp_check_invalid_utf8( $path );
	}

	/**
	 * Page title for a URL, when it points at a post or page.
	 *
	 * @param string $url  Absolute or relative URL.
	 * @param string $lang Optional language slug.
	 * @return string
	 */
	protected static function page_title_from_url( $url, $lang = '' ) {
		$url  = self::scalar_text( $url );
		if ( '' === $url ) {
		// Empty URL is not the homepage - callers must not invent a title.
			return '';
		}

		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}
		$path = self::relative_path( $url );
		$key  = $lang . '|' . ( '' !== $path ? $path : $url );

		static $cache = array();
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$post_id = 0;
		if ( '' === $path || '/' === $path ) {
			$front = (int) get_option( 'page_on_front' );
			if ( $front > 0 && '' !== $lang && function_exists( 'pll_get_post' ) ) {
				$translated = (int) pll_get_post( $front, $lang );
				$post_id    = $translated > 0 ? $translated : $front;
			} else {
				$post_id = $front;
			}
		} else {
			$absolute = ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) )
				? $url
				: home_url( $path );
			$post_id = function_exists( 'url_to_postid' ) ? (int) url_to_postid( $absolute ) : 0;

			if ( $post_id <= 0 ) {
				$slug = basename( untrailingslashit( $path ) );
				if ( '' !== $slug ) {
					$query = array(
						'name'           => $slug,
						'post_type'      => array_merge( array( 'page', 'post' ), array_values( get_post_types( array( 'public' => true ), 'names' ) ) ),
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					);
					$query['post_type'] = array_values( array_unique( (array) $query['post_type'] ) );
					if ( function_exists( 'pll_languages_list' ) ) {
						$query['lang'] = '' !== $lang ? $lang : '';
					}
					$found   = get_posts( $query );
					$post_id = ! empty( $found[0] ) ? (int) $found[0] : 0;
				}
			}
		}

		$title = '';
		if ( $post_id > 0 ) {
			$post  = get_post( $post_id );
			$title = ( $post && isset( $post->post_title ) ) ? trim( (string) $post->post_title ) : '';
		}
		$cache[ $key ] = $title;

		return $cache[ $key ];
	}

	/**
	 * Human label for an Elementor widget type slug.
	 *
	 * @param string $widget_type Widget type slug.
	 * @return string
	 */
	public static function widget_label( $widget_type ) {
		$labels = array(
			'mega-menu'     => __( 'Mega Menu', 'duplicate-content-addon-for-polylang' ),
			'nav-menu'      => __( 'Nav Menu', 'duplicate-content-addon-for-polylang' ),
			'icon'          => __( 'Icon', 'duplicate-content-addon-for-polylang' ),
			'icon-list'     => __( 'Icon List', 'duplicate-content-addon-for-polylang' ),
			'image-carousel'=> __( 'Image Carousel', 'duplicate-content-addon-for-polylang' ),
			'text-editor'   => __( 'Text Editor', 'duplicate-content-addon-for-polylang' ),
			'icon-box'      => __( 'Icon Box', 'duplicate-content-addon-for-polylang' ),
			'image-box'     => __( 'Image Box', 'duplicate-content-addon-for-polylang' ),
			'call-to-action'=> __( 'Call to Action', 'duplicate-content-addon-for-polylang' ),
			'price-table'   => __( 'Price Table', 'duplicate-content-addon-for-polylang' ),
			'price-list'    => __( 'Price List', 'duplicate-content-addon-for-polylang' ),
			'social-icons'  => __( 'Social Icons', 'duplicate-content-addon-for-polylang' ),
			'star-rating'   => __( 'Star Rating', 'duplicate-content-addon-for-polylang' ),
			'e-heading'     => __( 'Heading', 'duplicate-content-addon-for-polylang' ),
			'e-paragraph'   => __( 'Paragraph', 'duplicate-content-addon-for-polylang' ),
			'e-button'      => __( 'Button', 'duplicate-content-addon-for-polylang' ),
			'e-form-submit-button' => __( 'Form Button', 'duplicate-content-addon-for-polylang' ),
			'e-form-input'  => __( 'Form Field', 'duplicate-content-addon-for-polylang' ),
			'e-form-textarea' => __( 'Form Field', 'duplicate-content-addon-for-polylang' ),
			'e-form-label'  => __( 'Form Label', 'duplicate-content-addon-for-polylang' ),
			'e-form-select' => __( 'Form Select', 'duplicate-content-addon-for-polylang' ),
			'e-image'       => __( 'Image', 'duplicate-content-addon-for-polylang' ),
		);

		$widget_type = (string) $widget_type;
		if ( isset( $labels[ $widget_type ] ) ) {
			return $labels[ $widget_type ];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $widget_type ) );
	}

	/**
	 * Human-readable language label.
	 *
	 * @param string $slug Language slug.
	 * @return string
	 */
	public static function language_label( $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return '';
		}

		if ( function_exists( 'pll_languages_list' ) && function_exists( 'PLL' ) ) {
			$languages = PLL()->model->get_languages_list();
			foreach ( (array) $languages as $language ) {
				if ( isset( $language->slug ) && $language->slug === $slug ) {
					return isset( $language->name ) ? (string) $language->name : strtoupper( $slug );
				}
			}
		}

		return strtoupper( $slug );
	}

	/**
	 * Human location inside a widget (repeater row + field).
	 *
	 * @param string $field_key Field key.
	 * @return string
	 */
	public static function field_location_label( $field_key ) {
		$field_key = (string) $field_key;

		if ( preg_match( '/^([^[]+)\[(\d+)\]\.(.+)$/', $field_key, $matches ) ) {
			$repeater = $matches[1];
			$index    = (int) $matches[2] + 1;
			$sub_key  = $matches[3];

			$repeater_labels = array(
				'menu_items' => __( 'Menu item', 'duplicate-content-addon-for-polylang' ),
				'icon_list'  => __( 'List item', 'duplicate-content-addon-for-polylang' ),
				'carousel'   => __( 'Slide', 'duplicate-content-addon-for-polylang' ),
				'slides'     => __( 'Slide', 'duplicate-content-addon-for-polylang' ),
				'tabs'       => __( 'Tab', 'duplicate-content-addon-for-polylang' ),
			);

			$repeater_label = isset( $repeater_labels[ $repeater ] )
				? $repeater_labels[ $repeater ]
				: ucwords( str_replace( array( '-', '_' ), ' ', $repeater ) );

			return sprintf(
				/* translators: 1: repeater label 2: item number 3: field label */
				__( '%1$s %2$d - %3$s', 'duplicate-content-addon-for-polylang' ),
				$repeater_label,
				$index,
				self::field_label( $sub_key )
			);
		}

		return self::field_label( preg_replace( '/^.*\./', '', $field_key ) );
	}

	/**
	 * Try to read a nearby text label for a repeater link field.
	 *
	 * @param array<string, string> $fields    Extracted field values.
	 * @param string                $field_key Link field key.
	 * @return string
	 */
	public static function context_label_from_fields( array $fields, $field_key ) {
		$field_key = (string) $field_key;

		if ( preg_match( '/^([^[]+)\[(\d+)\]\./', $field_key, $matches ) ) {
			$repeater = $matches[1];
			$index    = $matches[2];
			$text_keys = array( 'item_title', 'text', 'title', 'tab_title', 'button_text', 'label', 'heading' );

			foreach ( $text_keys as $text_key ) {
				$candidate = $repeater . '[' . $index . '].' . $text_key;
				if ( isset( $fields[ $candidate ] ) && self::is_meaningful_text( $fields[ $candidate ] ) ) {
					return self::snippet( $fields[ $candidate ], 60 );
				}
			}

			return '';
		}

		if ( self::is_link_field( $field_key ) ) {
			foreach ( array( 'text', 'title', 'button_text', 'heading' ) as $text_key ) {
				if ( isset( $fields[ $text_key ] ) && self::is_meaningful_text( $fields[ $text_key ] ) ) {
					return self::snippet( $fields[ $text_key ], 60 );
				}
			}
		}

		return '';
	}

	/**
	 * Path component of a URL or site-relative path.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function url_path( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '/' ) ) {
			$path = wp_parse_url( 'http://site.invalid' . $url, PHP_URL_PATH );
			return is_string( $path ) ? $path : '/';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Whether a path is the site root / default homepage.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	public static function is_front_path( $path ) {
		$path = untrailingslashit( (string) $path );
		return '' === $path;
	}

	/**
	 * Absolute URL using the unfiltered site home (not Polylang's current language).
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function absolute_site_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || 0 !== strpos( $url, '/' ) ) {
			return $url;
		}

		return untrailingslashit( (string) get_option( 'home' ) ) . $url;
	}

	/**
	 * Default-language static front page ID.
	 *
	 * @param string $default_lang Optional default language slug.
	 * @return int
	 */
	public static function default_front_page_id( $default_lang = '' ) {
		$front = (int) get_option( 'page_on_front' );
		if ( $front <= 0 ) {
			return 0;
		}

		$default_lang = is_string( $default_lang ) ? $default_lang : '';
		if ( '' === $default_lang && function_exists( 'pll_default_language' ) ) {
			$default_lang = (string) pll_default_language( 'slug' );
		}

		if ( function_exists( 'pll_get_post' ) && '' !== $default_lang ) {
			$mapped = (int) pll_get_post( $front, $default_lang );
			if ( $mapped > 0 ) {
				return $mapped;
			}
		}

		return $front;
	}

	/**
	 * Post types that can be valid navigation link targets.
	 *
	 * @return array<int, string>
	 */
	public static function navigable_post_types() {
		$types   = array( 'page', 'post' );
		$public  = get_post_types( array( 'public' => true ), 'names' );
		$exclude = array(
			'attachment',
			'elementor_library',
			'e-landing-page',
			'elementor_snippet',
			'elementor_font',
			'elementor_icons',
		);

		if ( is_array( $public ) ) {
			foreach ( $public as $type ) {
				$type = (string) $type;
				if ( '' === $type || in_array( $type, $exclude, true ) || in_array( $type, $types, true ) ) {
					continue;
				}
				$types[] = $type;
			}
		}

		/**
		 * Filter post types treated as valid internal link targets.
		 *
		 * @param array<int, string> $types Post type slugs.
		 */
		$filtered = apply_filters( 'dupcap_navigable_post_types', $types );
		return is_array( $filtered ) ? array_values( array_filter( array_map( 'strval', $filtered ) ) ) : $types;
	}

	/**
	 * Whether a post ID is a valid page/post navigation target.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_navigable_post_id( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		return in_array( $post->post_type, self::navigable_post_types(), true );
	}

	/**
	 * Whether an internal URL opens a published page/post or public archive.
	 *
	 * Used to flag menu/button links that would 404 for visitors.
	 *
	 * @param string $url          URL or path.
	 * @param string $default_lang Optional default language slug.
	 * @return bool True when the URL should not be reported as a dead link.
	 */
	public static function url_has_published_target( $url, $default_lang = '' ) {
		$url = trim( (string) $url );
		if ( '' === $url || '#' === $url || 0 === strpos( $url, '#' ) ) {
			return true;
		}
		if ( ! self::is_internal_url( $url ) || self::is_media_asset_url( $url ) ) {
			return true;
		}

		$path = self::url_path( $url );
		if ( is_string( $path ) && '' !== $path ) {
			$admin_path = wp_parse_url( admin_url(), PHP_URL_PATH );
			$admin_path = is_string( $admin_path ) ? untrailingslashit( $admin_path ) : '/wp-admin';
			// Real admin path (incl. subdirectory installs like /blog/wp-admin).
			if ( '' !== $admin_path && 0 === strpos( $path, $admin_path ) ) {
				return true;
			}
			if ( preg_match( '#^/(wp-json|wp-login\.php|xmlrpc\.php|feed)(/|$)#i', $path ) ) {
				return true;
			}
		}

		$post_id = self::resolve_url_to_post_id( $url, $default_lang );
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			return $post instanceof WP_Post
				&& 'publish' === $post->post_status
				&& '' === (string) $post->post_password;
		}

		if ( self::resolve_url_to_term_id( $url ) > 0 ) {
			return true;
		}

		if ( self::is_post_type_archive_url( $url ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Describe why a URL is not a public page target (missing vs unpublished).
	 *
	 * Used for dead-link copy and Create page vs Edit page CTAs.
	 *
	 * @param string $url          Internal URL or path.
	 * @param string $default_lang Optional default language slug.
	 * @return array{
	 *     found: bool,
	 *     post_id: int,
	 *     status: string,
	 *     kind: string,
	 *     edit_url: string,
	 *     create_url: string,
	 *     fix_url: string,
	 *     fix_label: string
	 * }
	 */
	public static function describe_dead_link_target( $url, $default_lang = '' ) {
		$create_url = admin_url( 'post-new.php?post_type=page' );
		$missing    = array(
			'found'      => false,
			'post_id'    => 0,
			'status'     => '',
			'kind'       => 'missing',
			'edit_url'   => '',
			'create_url' => $create_url,
			'fix_url'     => $create_url,
			'fix_label'   => __( 'Create page', 'duplicate-content-addon-for-polylang' ),
		);

		$url = trim( (string) $url );
		if ( '' === $url ) {
			return $missing;
		}

		$post_id = self::resolve_url_to_post_id( $url, $default_lang );
		if ( $post_id <= 0 ) {
			return $missing;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $missing;
		}

		$status     = (string) $post->post_status;
		$protected  = ( 'publish' === $status && '' !== (string) $post->post_password );
		$kind       = 'missing';
		$needs_edit = false;

		if ( $protected ) {
			$kind       = 'protected';
			$needs_edit = true;
		} elseif ( 'draft' === $status ) {
			$kind       = 'draft';
			$needs_edit = true;
		} elseif ( 'private' === $status ) {
			$kind       = 'private';
			$needs_edit = true;
		} elseif ( in_array( $status, array( 'pending', 'future' ), true ) ) {
			$kind       = $status;
			$needs_edit = true;
		} elseif ( 'publish' !== $status ) {
			$kind       = 'other';
			$needs_edit = true;
		}

		if ( ! $needs_edit ) {
			return $missing;
		}

		$edit_url = get_edit_post_link( $post_id, 'raw' );
		$edit_url = is_string( $edit_url ) ? $edit_url : '';
		if ( '' === $edit_url ) {
			return $missing;
		}

		return array(
			'found'      => true,
			'post_id'    => $post_id,
			'status'     => $status,
			'kind'       => $kind,
			'edit_url'   => $edit_url,
			'create_url' => $create_url,
			'fix_url'     => $edit_url,
			'fix_label'   => __( 'Edit page', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/**
	 * Resolve an internal URL to a public taxonomy term ID.
	 *
	 * @param string $url URL or path.
	 * @return int
	 */
	public static function resolve_url_to_term_id( $url ) {
		$path = trim( (string) self::url_path( $url ), '/' );
		if ( '' === $path ) {
			return 0;
		}

		$langs = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'slug' ) ) : array();
		if ( is_array( $langs ) && ! empty( $langs ) ) {
			$segments = explode( '/', $path );
			if ( ! empty( $segments[0] ) && in_array( $segments[0], $langs, true ) ) {
				array_shift( $segments );
				$path = implode( '/', $segments );
			}
		}

		if ( '' === $path ) {
			return 0;
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		if ( ! is_array( $taxonomies ) ) {
			return 0;
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy instanceof WP_Taxonomy ) {
				continue;
			}
			$rewrite_slug = '';
			if ( ! empty( $taxonomy->rewrite ) && is_array( $taxonomy->rewrite ) && ! empty( $taxonomy->rewrite['slug'] ) ) {
				$rewrite_slug = trim( (string) $taxonomy->rewrite['slug'], '/' );
			}
			if ( '' === $rewrite_slug ) {
				$rewrite_slug = (string) $taxonomy->name;
			}

			$prefix = $rewrite_slug . '/';
			if ( 0 !== strpos( $path, $prefix ) && $path !== $rewrite_slug ) {
				continue;
			}

			$term_path = ( $path === $rewrite_slug ) ? '' : substr( $path, strlen( $prefix ) );
			$term_slug = $term_path ? basename( $term_path ) : '';
			if ( '' === $term_slug ) {
				continue;
			}

			$term = get_term_by( 'slug', $term_slug, $taxonomy->name );
			if ( $term instanceof WP_Term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}

		return 0;
	}

	/**
	 * Whether a URL looks like a public post type archive.
	 *
	 * @param string $url URL or path.
	 * @return bool
	 */
	public static function is_post_type_archive_url( $url ) {
		$path = trim( (string) self::url_path( $url ), '/' );
		if ( '' === $path ) {
			return false;
		}

		$langs = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'slug' ) ) : array();
		if ( is_array( $langs ) && ! empty( $langs ) ) {
			$segments = explode( '/', $path );
			if ( ! empty( $segments[0] ) && in_array( $segments[0], $langs, true ) ) {
				array_shift( $segments );
				$path = implode( '/', $segments );
			}
		}

		if ( '' === $path || false !== strpos( $path, '/' ) ) {
			return false;
		}

		$types = get_post_types( array( 'public' => true ), 'objects' );
		if ( ! is_array( $types ) ) {
			return false;
		}

		foreach ( $types as $type ) {
			if ( ! $type instanceof WP_Post_Type || 'post' === $type->name ) {
				continue;
			}
			$slug = '';
			if ( ! empty( $type->rewrite ) && is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ) {
				$slug = trim( (string) $type->rewrite['slug'], '/' );
			}
			if ( '' === $slug ) {
				$slug = (string) $type->name;
			}
			if ( $slug === $path && ! empty( $type->has_archive ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Human-readable detail for a dead (missing / unpublished) link.
	 *
	 * @param string $current_label Readable current path or page label.
	 * @param string $kind          missing|draft|private|protected|pending|future|other.
	 * @return string
	 */
	public static function format_dead_link_detail( $current_label, $kind = 'missing' ) {
		$current_label = (string) $current_label;
		if ( '' === $current_label ) {
			$current_label = '/';
		}

		$problem = self::dead_link_problem_text( $kind );
		$fix     = self::dead_link_fix_text( $kind );

		return sprintf(
			/* translators: 1: URL or page label 2: problem sentence 3: fix sentence */
			__( 'This link goes to %1$s. %2$s %3$s', 'duplicate-content-addon-for-polylang' ),
			$current_label,
			$problem,
			$fix
		);
	}

	/**
	 * Problem sentence for a dead-link kind.
	 *
	 * @param string $kind missing|draft|private|protected|pending|future|other.
	 * @return string
	 */
	public static function dead_link_problem_text( $kind ) {
		switch ( (string) $kind ) {
			case 'draft':
				return __( 'A Draft page exists at this URL, but Draft pages are not public, so visitors still get a “page not found” error.', 'duplicate-content-addon-for-polylang' );
			case 'private':
				return __( 'A Private page exists at this URL, but Private pages are not public, so visitors still get a “page not found” error.', 'duplicate-content-addon-for-polylang' );
			case 'protected':
				return __( 'A password-protected page exists at this URL. Visitors without the password cannot open it like a normal public page.', 'duplicate-content-addon-for-polylang' );
			case 'pending':
				return __( 'A Pending Review page exists at this URL, but it is not published, so visitors still get a “page not found” error.', 'duplicate-content-addon-for-polylang' );
			case 'future':
				return __( 'A Scheduled page exists at this URL, but it is not published yet, so visitors still get a “page not found” error.', 'duplicate-content-addon-for-polylang' );
			case 'other':
				return __( 'A page exists at this URL but is not published, so visitors still get a “page not found” error.', 'duplicate-content-addon-for-polylang' );
			case 'missing':
			default:
				return __( 'No page was found at this URL (including Draft, Private, or password-protected). Visitors who click it will see a “page not found” error.', 'duplicate-content-addon-for-polylang' );
		}
	}

	/**
	 * How-to-fix sentence for a dead-link kind.
	 *
	 * @param string $kind missing|draft|private|protected|pending|future|other.
	 * @return string
	 */
	public static function dead_link_fix_text( $kind ) {
		switch ( (string) $kind ) {
			case 'draft':
			case 'private':
			case 'pending':
			case 'future':
			case 'other':
				return __( 'Open Edit page, publish it if that is intended (then translate it for every language), or change the menu/button link to an existing published page.', 'duplicate-content-addon-for-polylang' );
			case 'protected':
				return __( 'Open Edit page and remove the password if the page should be public, or change the menu/button link to a public page.', 'duplicate-content-addon-for-polylang' );
			case 'missing':
			default:
				return __( 'Create and publish that page (then translate it for every language), or change the menu/button to an existing published page.', 'duplicate-content-addon-for-polylang' );
		}
	}

	/**
	 * Resolve an internal URL to a post ID.
	 *
	 * Shared by content-link checks and Elementor link scanning.
	 *
	 * @param string $url          URL or path.
	 * @param string $default_lang Optional default language slug for front-page mapping.
	 * @return int
	 */
	public static function resolve_url_to_post_id( $url, $default_lang = '' ) {
		if ( ! self::is_internal_url( $url ) ) {
			return 0;
		}

		$path = self::url_path( $url );
		if ( self::is_front_path( $path ) ) {
			return self::default_front_page_id( $default_lang );
		}

		$absolute = self::absolute_site_url( $url );

		$candidates = array( $absolute );
		if ( '' !== $path ) {
			$decoded = rawurldecode( $path );
			if ( $decoded !== $path ) {
				$candidates[] = self::absolute_site_url( $decoded );
			}
			$encoded = implode(
				'/',
				array_map(
					static function ( $segment ) {
						if ( '' === $segment || rawurldecode( $segment ) !== $segment ) {
							return $segment;
						}
						return rawurlencode( $segment );
					},
					explode( '/', $path )
				)
			);
			if ( $encoded !== $path ) {
				$candidates[] = self::absolute_site_url( $encoded );
			}
		}

		foreach ( array_unique( $candidates ) as $candidate ) {
			$post_id = (int) url_to_postid( $candidate );
			if ( self::is_navigable_post_id( $post_id ) ) {
				return $post_id;
			}
		}

		$slug = $path ? basename( untrailingslashit( rawurldecode( $path ) ) ) : '';
		if ( '' === $slug ) {
			return 0;
		}

		$path_for_lookup = trim( rawurldecode( $path ), '/' );
		$langs           = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'slug' ) ) : array();
		if ( is_array( $langs ) && ! empty( $path_for_lookup ) ) {
			$segments = explode( '/', $path_for_lookup );
			if ( ! empty( $segments[0] ) && in_array( $segments[0], $langs, true ) ) {
				array_shift( $segments );
				$path_for_lookup = implode( '/', $segments );
			}
		}

		$public_types = self::navigable_post_types();

		if ( '' !== $path_for_lookup && function_exists( 'get_page_by_path' ) ) {
			foreach ( $public_types as $post_type ) {
				$page = get_page_by_path( $path_for_lookup, OBJECT, $post_type );
				if ( $page && ! empty( $page->ID ) && self::is_navigable_post_id( (int) $page->ID ) ) {
					return (int) $page->ID;
				}
			}
		}

		foreach ( array_unique( array_filter( array( $slug, rawurlencode( $slug ) ) ) ) as $name ) {
			foreach ( $public_types as $post_type ) {
				$query = array(
					'name'           => $name,
					'post_type'      => $post_type,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				);
				if ( function_exists( 'pll_languages_list' ) ) {
					$query['lang'] = '';
				}

				$found = get_posts( $query );
				if ( ! empty( $found[0] ) && self::is_navigable_post_id( (int) $found[0] ) ) {
					return (int) $found[0];
				}
			}
		}

		global $wpdb;
		if ( isset( $wpdb ) && $slug ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded diagnostic read for old slug lookup.
			$old_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 10",
					$slug
				)
			);
			if ( is_array( $old_ids ) ) {
				foreach ( $old_ids as $old_id ) {
					if ( self::is_navigable_post_id( (int) $old_id ) ) {
						return (int) $old_id;
					}
				}
			}
		}

		$aliases = array(
			'docs' => array( 'documentation', 'document', 'doc' ),
			'doc'  => array( 'documentation', 'docs' ),
		);
		/**
		 * Filter alternate slugs tried when resolving an internal link URL.
		 *
		 * @param array  $aliases Map of slug => alternate slugs.
		 * @param string $slug    Current path basename.
		 * @param string $url     Original URL.
		 */
		$aliases = apply_filters( 'dupcap_resolve_url_slug_aliases', $aliases, $slug, $url );
		if ( is_array( $aliases ) && ! empty( $aliases[ $slug ] ) && function_exists( 'get_page_by_path' ) ) {
			foreach ( (array) $aliases[ $slug ] as $alias ) {
				$alias = sanitize_title( (string) $alias );
				if ( '' === $alias || $alias === $slug ) {
					continue;
				}
				foreach ( $public_types as $post_type ) {
					$page = get_page_by_path( $alias, OBJECT, $post_type );
					if ( $page && ! empty( $page->ID ) && self::is_navigable_post_id( (int) $page->ID ) ) {
						return (int) $page->ID;
					}
				}
			}
		}

		return 0;
	}

	/**
	 * Human-readable detail for a wrong / untranslated internal link.
	 *
	 * @param string $current_label   Readable current page label.
	 * @param string $language        Language display name.
	 * @param string $suggested_label Readable suggested page label (empty if none).
	 * @param string $fragment        URL fragment including #, or empty.
	 * @return string
	 */
	public static function format_link_issue_detail( $current_label, $language, $suggested_label = '', $fragment = '' ) {
		$current_label   = (string) $current_label;
		$language        = (string) $language;
		$suggested_label = (string) $suggested_label;
		$fragment        = (string) $fragment;

		if ( '' === $current_label ) {
			$current_label = '/';
		}
		if ( '' === $language ) {
			$language = __( 'translated', 'duplicate-content-addon-for-polylang' );
		}

		if ( '' === $suggested_label ) {
			return sprintf(
				/* translators: 1: current page 2: language name */
				__( 'This link goes to %1$s. There is no %2$s page for it yet.', 'duplicate-content-addon-for-polylang' ),
				$current_label,
				$language
			);
		}

		if ( '' !== $fragment ) {
			return sprintf(
				/* translators: 1: current page 2: fragment 3: language name 4: suggested page */
				__( 'This link goes to %1$s. Keep %2$s and put the %3$s page in front: %4$s', 'duplicate-content-addon-for-polylang' ),
				$current_label,
				$fragment,
				$language,
				$suggested_label
			);
		}

		return sprintf(
			/* translators: 1: current page 2: language name 3: suggested page */
			__( 'This link goes to %1$s. Change it to the %2$s page: %3$s', 'duplicate-content-addon-for-polylang' ),
			$current_label,
			$language,
			$suggested_label
		);
	}

	/**
	 * Build a short heading for dashboard issue rows.
	 *
	 * Keeps the title + language (+ widget/field when useful). Avoids long
	 * sentences and technical type names in the bold line.
	 *
	 * @param array<string, mixed> $finding Finding row.
	 * @return string
	 */
	public static function format_example_summary( array $finding ) {
		$code       = isset( $finding['code'] ) ? (string) $finding['code'] : '';
		$lang       = isset( $finding['language'] ) ? (string) $finding['language'] : '';
		$lang_label = '' !== $lang ? self::language_label( $lang ) : '';
		$template   = isset( $finding['template_title'] ) ? trim( (string) $finding['template_title'] ) : '';
		if ( '' === $template && ! empty( $finding['title'] ) ) {
			$template = trim( (string) $finding['title'] );
		}
		if ( '' === $template && ! empty( $finding['template_id'] ) && class_exists( 'DUPCAP_Elementor_Document' ) ) {
			$template = DUPCAP_Elementor_Document::display_title_for( (int) $finding['template_id'] );
		}

		$widget       = isset( $finding['widget_type'] ) ? (string) $finding['widget_type'] : '';
		$widget_label = '' !== $widget ? self::widget_label( $widget ) : '';

		$context = isset( $finding['context_label'] ) ? trim( (string) $finding['context_label'] ) : '';
		if ( '' === $context && ! empty( $finding['field'] ) ) {
			$context = self::field_location_label( (string) $finding['field'] );
		}

		$nest_type  = isset( $finding['nest_type'] ) ? (string) $finding['nest_type'] : '';
		$nest_label = isset( $finding['nest_label'] ) ? trim( (string) $finding['nest_label'] ) : '';
		if ( 'mega-menu' === $nest_type && '' !== $nest_label && '' === $context ) {
			$context = $nest_label;
		}

		// Document-level issues: title + missing/target language only.
		if ( in_array( $code, array( 'missing_translation', 'orphan_translation', 'empty_translation', 'invalid_json', 'invalid_structure', 'missing_display_conditions', 'conflicting_display_conditions', 'wrong_language_condition', 'missing_sitewide_header', 'cpel_recommended_for_headers' ), true ) ) {
			if ( '' !== $template && '' !== $lang_label && 'missing_translation' === $code ) {
				return sprintf(
					/* translators: 1: document title 2: language name */
					__( '"%1$s" needs %2$s', 'duplicate-content-addon-for-polylang' ),
					$template,
					$lang_label
				);
			}
			if ( '' !== $template && '' !== $lang_label && 'orphan_translation' === $code ) {
				return sprintf(
					/* translators: 1: document title 2: language name */
					__( '"%1$s" (%2$s) is not linked to an original', 'duplicate-content-addon-for-polylang' ),
					$template,
					$lang_label
				);
			}
			if ( '' !== $template && '' !== $lang_label && 'empty_translation' === $code ) {
				return sprintf(
					/* translators: 1: document title 2: language name */
					__( '"%1$s" is empty in %2$s', 'duplicate-content-addon-for-polylang' ),
					$template,
					$lang_label
				);
			}
			if ( '' !== $template && '' !== $lang_label && 'missing_display_conditions' === $code ) {
				return sprintf(
					/* translators: 1: document title 2: language name */
					__( '"%1$s" (%2$s) has no display conditions', 'duplicate-content-addon-for-polylang' ),
					$template,
					$lang_label
				);
			}
			if ( '' !== $template && '' !== $lang_label && 'conflicting_display_conditions' === $code ) {
				return sprintf(
					/* translators: 1: document title 2: language name */
					__( '"%1$s" conflicts on Entire Site (%2$s)', 'duplicate-content-addon-for-polylang' ),
					$template,
					$lang_label
				);
			}
			if ( '' !== $template && '' !== $lang_label && 'wrong_language_condition' === $code ) {
				return sprintf(
					/* translators: 1: document title 2: language name */
					__( '"%1$s" still uses the original language\'s display conditions (%2$s)', 'duplicate-content-addon-for-polylang' ),
					$template,
					$lang_label
				);
			}
			if ( 'missing_sitewide_header' === $code ) {
				if ( '' !== $lang_label ) {
					return sprintf(
						/* translators: %s: language name */
						__( 'No Entire Site header coverage (%s)', 'duplicate-content-addon-for-polylang' ),
						$lang_label
					);
				}
				return __( 'No Entire Site header coverage for default language', 'duplicate-content-addon-for-polylang' );
			}
			if ( 'cpel_recommended_for_headers' === $code ) {
				return __( 'Link multilingual headers with Language Switcher for Polylang', 'duplicate-content-addon-for-polylang' );
			}
			if ( '' !== $template && '' !== $lang_label ) {
				return sprintf(
					/* translators: 1: document title 2: language name */
					__( '"%1$s" - %2$s', 'duplicate-content-addon-for-polylang' ),
					$template,
					$lang_label
				);
			}
			if ( '' !== $template ) {
				return '"' . $template . '"';
			}
			return $lang_label;
		}

		// Widget / field / link issues. These read as a short sentence - what,
		// where, and in which language - instead of dash-joined fragments.
		// Field names stay in the detail line, not the bold heading.
		$is_link = in_array( $code, array( 'untranslated_link', 'wrong_language_link', 'broken_link', 'dead_link' ), true );
		$what    = '' !== $widget_label ? $widget_label : $context;

		if ( '' === $what && $is_link ) {
			$what = __( 'Link', 'duplicate-content-addon-for-polylang' );
		}

		if ( '' === $what ) {
			// Nothing widget-specific to name, so fall back to document wording.
			if ( '' !== $template && '' !== $lang_label ) {
				return sprintf(
					/* translators: 1: document title 2: language name */
					__( '"%1$s" (%2$s)', 'duplicate-content-addon-for-polylang' ),
					$template,
					$lang_label
				);
			}

			return '' !== $template ? '"' . $template . '"' : $lang_label;
		}

		// Two widgets of the same type in one document look identical without
		// their text, so name the text that identifies this one. For an empty
		// translation the text quoted is the original, hence the "for" wording.
		$snippet   = '';
		$is_source = false;

		if ( $is_link && '' !== $context && $context !== $what ) {
			$snippet = $context;
		} elseif ( 'untranslated_field' === $code ) {
			$value   = self::scalar_text( isset( $finding['translated_value'] ) ? $finding['translated_value'] : '' );
			$snippet = '' !== $value ? self::snippet( $value, 40 ) : '';
		} elseif ( 'missing_field' === $code ) {
			$value     = self::scalar_text( isset( $finding['original_value'] ) ? $finding['original_value'] : '' );
			$snippet   = '' !== $value ? self::snippet( $value, 40 ) : '';
			$is_source = '' !== $snippet;
		}

		if ( '' !== $snippet ) {
			$what = $is_source
				? sprintf(
					/* translators: 1: widget name 2: text in the original language */
					__( '%1$s for "%2$s"', 'duplicate-content-addon-for-polylang' ),
					$what,
					$snippet
				)
				: sprintf(
					/* translators: 1: widget name 2: the text this widget shows */
					__( '%1$s "%2$s"', 'duplicate-content-addon-for-polylang' ),
					$what,
					$snippet
				);
		}

		if ( '' !== $template && '' !== $lang_label ) {
			return sprintf(
				/* translators: 1: widget phrase, e.g. Heading "Shop now" 2: document title 3: language name */
				__( '%1$s in "%2$s" (%3$s)', 'duplicate-content-addon-for-polylang' ),
				$what,
				$template,
				$lang_label
			);
		}

		if ( '' !== $template ) {
			return sprintf(
				/* translators: 1: widget phrase, e.g. Heading "Shop now" 2: document title */
				__( '%1$s in "%2$s"', 'duplicate-content-addon-for-polylang' ),
				$what,
				$template
			);
		}

		if ( '' !== $lang_label ) {
			return sprintf(
				/* translators: 1: widget phrase, e.g. Heading "Shop now" 2: language name */
				__( '%1$s (%2$s)', 'duplicate-content-addon-for-polylang' ),
				$what,
				$lang_label
			);
		}

		return $what;
	}

	/**
	 * Build a detail line explaining what is wrong.
	 *
	 * @param array<string, mixed> $finding Finding row.
	 * @return string
	 */
	public static function format_example_detail( array $finding ) {
		$code = isset( $finding['code'] ) ? (string) $finding['code'] : '';
		$why  = isset( $finding['why'] ) ? self::sanitize_display_text( $finding['why'] ) : '';

		if ( 'missing_translation' === $code ) {
			$lang = isset( $finding['language'] ) ? self::language_label( (string) $finding['language'] ) : '';
			if ( '' !== $lang ) {
				return sprintf(
					/* translators: %s: language name */
					__( 'No %s version is linked in Polylang yet. Add a translation so visitors can open this layout in that language.', 'duplicate-content-addon-for-polylang' ),
					$lang
				);
			}
		}

		if ( 'orphan_translation' === $code ) {
			$lang = isset( $finding['language'] ) ? self::language_label( (string) $finding['language'] ) : '';
			if ( '' !== $lang ) {
				return sprintf(
					/* translators: %s: language name */
					__( 'This %s layout is not connected to an original-language version in Polylang. Link it to the original, or create one.', 'duplicate-content-addon-for-polylang' ),
					$lang
				);
			}
			return __( 'This layout is not connected to an original-language version in Polylang. Link it to the original, or create one.', 'duplicate-content-addon-for-polylang' );
		}

		if ( in_array( $code, array( 'untranslated_link', 'wrong_language_link', 'broken_link', 'dead_link' ), true ) ) {
			$current   = self::scalar_text( isset( $finding['translated_value'] ) ? $finding['translated_value'] : '' );
			if ( '' === $current ) {
				$current = self::scalar_text( isset( $finding['original_value'] ) ? $finding['original_value'] : '' );
			}
			$suggested = self::scalar_text( isset( $finding['suggested_url'] ) ? $finding['suggested_url'] : '' );
			$language  = isset( $finding['language'] ) ? self::language_label( (string) $finding['language'] ) : '';

			if ( '' === $current ) {
				return '' !== $why
					? $why
					: __( 'The link field is empty in this translation.', 'duplicate-content-addon-for-polylang' );
			}

			$lang_slug     = isset( $finding['language'] ) ? (string) $finding['language'] : '';
			$fragment      = self::url_fragment( $current );
			$current_label = self::readable_url( $current );

			if ( 'dead_link' === $code ) {
				if ( '' !== $why ) {
					return $why;
				}
				$target = self::describe_dead_link_target( $current );
				$kind   = isset( $target['kind'] ) ? (string) $target['kind'] : 'missing';
				return self::format_dead_link_detail( $current_label ? $current_label : $current, $kind );
			}

			// Never call readable_url() on an empty suggestion - that used to invent
			// the homepage title (Heim / Casa / Hogar) with no real URL.
			if ( '' === $suggested ) {
				return self::format_link_issue_detail( $current_label, $language );
			}

			$suggested       = '' !== $fragment ? self::with_fragment( $suggested, $fragment ) : $suggested;
			$suggested_label = self::readable_url( $suggested, $lang_slug );

			return self::format_link_issue_detail(
				$current_label,
				$language,
				$suggested_label,
				( '' !== $suggested_label && '' !== $fragment ) ? $fragment : ''
			);
		}

		if ( 'untranslated_field' === $code ) {
			$value    = self::scalar_text( isset( $finding['translated_value'] ) ? $finding['translated_value'] : '' );
			$language = isset( $finding['language'] ) ? self::language_label( (string) $finding['language'] ) : '';
			if ( '' !== $value ) {
				if ( '' !== $language ) {
					return sprintf(
						/* translators: 1: text snippet 2: language name */
						__( 'This widget text shows "%1$s". It still matches the default language and has not been translated into %2$s yet.', 'duplicate-content-addon-for-polylang' ),
						self::snippet( $value, 80 ),
						$language
					);
				}
				return sprintf(
					/* translators: %s: text snippet */
					__( 'This widget text shows "%s". It still matches the default language and has not been translated yet.', 'duplicate-content-addon-for-polylang' ),
					self::snippet( $value, 80 )
				);
			}
		}

		if ( in_array( $code, array( 'wrong_loop_template', 'missing_loop_template', 'missing_loop_item_translation' ), true ) ) {
			$lang_label   = isset( $finding['language'] ) ? self::language_label( (string) $finding['language'] ) : '';
			$widget_label = ! empty( $finding['widget_type'] )
				? self::loop_widget_label( (string) $finding['widget_type'] )
				: __( 'Loop Grid', 'duplicate-content-addon-for-polylang' );
			$source_id    = isset( $finding['original_value'] ) ? (int) $finding['original_value'] : 0;
			$actual_id    = isset( $finding['translated_value'] ) ? (int) $finding['translated_value'] : 0;
			$source_title = $source_id > 0 && class_exists( 'DUPCAP_Elementor_Document' )
				? DUPCAP_Elementor_Document::display_title_for( $source_id )
				: '';
			$expected_title = '';
			$actual_title   = $actual_id > 0 && class_exists( 'DUPCAP_Elementor_Document' )
				? DUPCAP_Elementor_Document::display_title_for( $actual_id )
				: '';
			if ( 'wrong_loop_template' === $code && $source_id > 0 && function_exists( 'pll_get_post' ) && ! empty( $finding['language'] ) ) {
				$expected_id = (int) pll_get_post( $source_id, (string) $finding['language'] );
				if ( $expected_id > 0 ) {
					$expected_title = DUPCAP_Elementor_Document::display_title_for( $expected_id );
				}
			}

			$summary = self::loop_template_issue_summary(
				$code,
				$lang_label,
				$widget_label,
				$expected_title,
				$actual_title,
				$source_title
			);

			if ( '' !== $summary ) {
				return $summary;
			}
		}

		if ( '' !== $why ) {
			return $why;
		}

		return isset( $finding['what'] ) ? self::sanitize_display_text( $finding['what'] ) : '';
	}
}
