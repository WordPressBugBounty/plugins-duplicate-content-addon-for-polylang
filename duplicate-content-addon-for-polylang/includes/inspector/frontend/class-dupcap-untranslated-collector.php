<?php
/**
 * Finds text on a translated post that is still identical to the source post.
 *
 * "Source" is the default-language translation of the same Polylang group.
 * Elementor posts are compared widget field by widget field, using the same
 * rule as the Elementor scanner; classic and block posts are compared title
 * and text segment by text segment.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the row list shown by the frontend Untranslated Inspector.
 */
class DUPCAP_Untranslated_Collector {

	const STATUS_OK                 = 'ok';
	const STATUS_NOT_TRANSLATABLE   = 'not_translatable';
	const STATUS_SOURCE_LANGUAGE    = 'source_language';
	const STATUS_NO_SOURCE          = 'no_source';
	const STATUS_SOURCE_UNPUBLISHED = 'source_unpublished';

	/**
	 * Block-level tags a content segment ends at.
	 */
	const SEGMENT_SPLIT_PATTERN = '#</?(?:p|h[1-6]|li|ul|ol|blockquote|figcaption|figure|td|th|tr|dt|dd|div|pre|section|article|header|footer|button)\b[^>]*>|<br\s*/?>#i';

	/**
	 * Untranslated rows for one post.
	 *
	 * "total" is how many strings were compared, so rows / total is the share left untranslated.
	 *
	 * @param int $post_id Post being viewed.
	 * @return array{status: string, current_lang: string, source_lang: string, rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function for_post( $post_id ) {
		$post_id = (int) $post_id;
		$result  = array(
			'status'       => self::STATUS_OK,
			'current_lang' => '',
			'source_lang'  => '',
			'rows'         => array(),
			'total'        => 0,
		);

		if ( $post_id <= 0 || ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_default_language' ) || ! function_exists( 'pll_get_post_translations' ) ) {
			$result['status'] = self::STATUS_NOT_TRANSLATABLE;
			return $result;
		}

		$current_lang = (string) pll_get_post_language( $post_id, 'slug' );
		$source_lang  = (string) pll_default_language( 'slug' );

		$result['current_lang'] = $current_lang;
		$result['source_lang']  = $source_lang;

		if ( '' === $current_lang || '' === $source_lang ) {
			$result['status'] = self::STATUS_NOT_TRANSLATABLE;
			return $result;
		}

		if ( $current_lang === $source_lang ) {
			$result['status'] = self::STATUS_SOURCE_LANGUAGE;
			return $result;
		}

		$translations = pll_get_post_translations( $post_id );
		$source_id    = ( is_array( $translations ) && ! empty( $translations[ $source_lang ] ) ) ? (int) $translations[ $source_lang ] : 0;

		if ( $source_id <= 0 || $source_id === $post_id ) {
			$result['status'] = self::STATUS_NO_SOURCE;
			return $result;
		}

		if ( 'publish' !== get_post_status( $source_id ) ) {
			$result['status'] = self::STATUS_SOURCE_UNPUBLISHED;
			return $result;
		}

		$source      = get_post( $source_id );
		$translation = get_post( $post_id );
		if ( ! $source instanceof WP_Post || ! $translation instanceof WP_Post ) {
			$result['status'] = self::STATUS_NO_SOURCE;
			return $result;
		}

		$cache_key = 'dupcap_untr_' . md5( $post_id . '_' . $source_id . '_' . $translation->post_modified . '_' . $source->post_modified . '_' . ( defined( 'DUPCAP_VERSION' ) ? DUPCAP_VERSION : '1' ) . '_sim' );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['rows'], $cached['total'] ) ) {
			$result['rows']  = $cached['rows'];
			$result['total'] = (int) $cached['total'];
			return $result;
		}

		$total = 0;
		$rows  = self::title_rows( $source, $translation, $total );

		$source_elementor      = self::is_elementor_built( $source_id );
		$translation_elementor = self::is_elementor_built( $post_id );

		if ( $source_elementor && $translation_elementor ) {
			$rows = array_merge( $rows, self::elementor_rows( $source_id, $post_id, $total ) );
		} elseif ( ! $source_elementor && ! $translation_elementor ) {
			$rows = array_merge( $rows, self::content_rows( $source, $translation, $total ) );
		}

		set_transient( $cache_key, array( 'rows' => $rows, 'total' => $total ), DAY_IN_SECONDS );

		$result['rows']  = $rows;
		$result['total'] = $total;
		return $result;
	}

	/**
	 * Untranslated rows for an Elementor template rendered on the page (header, footer...).
	 *
	 * A template still in the source language is reported field by field, with
	 * a link to create its translation.
	 *
	 * @param int    $template_id  Rendered template post ID.
	 * @param string $current_lang Language of the page being viewed.
	 * @param string $source_lang  Default language.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function for_template( $template_id, $current_lang, $source_lang ) {
		$template_id = (int) $template_id;
		$total       = 0;
		$none        = array(
			'rows'  => array(),
			'total' => 0,
		);

		if (
			'elementor_library' !== get_post_type( $template_id )
			|| DUPCAP_Elementor_Document::is_excluded_library_document( $template_id )
			|| ! self::is_elementor_built( $template_id )
		) {
			return $none;
		}

		$template_post = get_post( $template_id );
		$cache_key     = 'dupcap_untr_tpl_' . md5( $template_id . '_' . $current_lang . '_' . $source_lang . '_' . ( $template_post instanceof WP_Post ? $template_post->post_modified : '' ) . '_' . ( defined( 'DUPCAP_VERSION' ) ? DUPCAP_VERSION : '1' ) . '_sim' );
		$cached        = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['rows'], $cached['total'] ) ) {
			return $cached;
		}

		$template_lang = (string) pll_get_post_language( $template_id, 'slug' );

		if ( '' !== $template_lang && $template_lang === $current_lang ) {
			$translations = pll_get_post_translations( $template_id );
			$source_id    = ( is_array( $translations ) && ! empty( $translations[ $source_lang ] ) ) ? (int) $translations[ $source_lang ] : 0;
			if ( $source_id <= 0 || $source_id === $template_id ) {
				return $none;
			}
			$rows = self::elementor_rows( $source_id, $template_id, $total );
		} elseif ( '' !== $template_lang && $template_lang === $source_lang ) {
			$rows = self::elementor_rows( $template_id, $template_id, $total );
			// The link comes back HTML-escaped; the script sets it as a raw href.
			$translate_url = wp_specialchars_decode( ( new DUPCAP_Elementor_Document( $template_id ) )->new_translation_url( $current_lang ), ENT_QUOTES );
			foreach ( $rows as $index => $row ) {
				$rows[ $index ]['edit_url'] = $translate_url;
				$rows[ $index ]['post_id']  = 0; // No translation yet, so no quick edit.
			}
		} else {
			return $none;
		}

		$type       = (string) get_post_meta( $template_id, DUPCAP_Elementor_Document::META_TEMPLATE_TYPE, true );
		$type_label = DUPCAP_Elementor_Document::type_label_for( '' !== $type ? $type : 'template' );
		$title      = DUPCAP_Elementor_Document::display_title_for( $template_id );

		if ( '' !== $template_lang && $template_lang === $current_lang ) {
			$group = sprintf(
				/* translators: 1: template type, 2: title, 3: language code */
				__( '%1$s: %2$s (%3$s)', 'duplicate-content-addon-for-polylang' ),
				$type_label,
				$title,
				strtoupper( $current_lang )
			);
		} else {
			$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $template_id ) : array();
			$target_id    = ( is_array( $translations ) && ! empty( $translations[ $current_lang ] ) ) ? (int) $translations[ $current_lang ] : 0;
			if ( $target_id > 0 ) {
				$group = sprintf(
					/* translators: 1: template type, 2: title, 3: source lang, 4: target lang, 5: translated template ID */
					__( '%1$s: %2$s (Loaded: %3$s — %4$s template #%5$d exists)', 'duplicate-content-addon-for-polylang' ),
					$type_label,
					$title,
					strtoupper( $template_lang ),
					strtoupper( $current_lang ),
					$target_id
				);
			} else {
				$group = sprintf(
					/* translators: 1: template type, 2: title, 3: source lang */
					__( '%1$s: %2$s (Loaded: %3$s — Not translated yet)', 'duplicate-content-addon-for-polylang' ),
					$type_label,
					$title,
					strtoupper( $template_lang )
				);
			}
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['group'] = $group;
		}

		$result = array(
			'rows'  => $rows,
			'total' => $total,
		);
		set_transient( $cache_key, $result, DAY_IN_SECONDS );

		return $result;
	}

	/**
	 * Row for a title left identical to the source title.
	 *
	 * @param WP_Post $source      Source post.
	 * @param WP_Post $translation Translated post.
	 * @param int     $total       Compared-string counter, increased in place.
	 * @return array<int, array<string, string>>
	 */
	protected static function title_rows( WP_Post $source, WP_Post $translation, &$total ) {
		$original   = self::normalize_text( $source->post_title );
		$translated = self::normalize_text( $translation->post_title );

		if ( '' === $original || self::is_trivial( $original ) ) {
			return array();
		}

		++$total;

		$target_lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $translation->ID, 'slug' ) : '';

		if ( ! self::is_text_untranslated( $original, $translated, $target_lang ) ) {
			return array();
		}

		return array(
			array(
				'kind'       => 'title',
				'element_id' => '',
				'widget'     => '',
				'field'      => __( 'Title', 'duplicate-content-addon-for-polylang' ),
				'match'      => $translated,
				'post_id'    => (int) $translation->ID,
				'field_key'  => 'post_title',
				'edit_url'   => self::with_focus_text( self::classic_edit_url( $translation->ID ), $translated ),
			),
		);
	}

	/**
	 * Elementor widget fields whose text matches the source widget.
	 *
	 * Uses the same field rule as DUPCAP_Elementor_Scanner::diff_documents()
	 * (DUPCAP_Elementor_Fields::is_untranslated_text()), matched by element id.
	 * Multi-paragraph fields are reported per paragraph, compared on the raw
	 * HTML because the element map keeps tag-stripped text only.
	 *
	 * @param int $source_id      Source post ID.
	 * @param int $translation_id Translated post ID.
	 * @param int $total          Compared-string counter, increased in place.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function elementor_rows( $source_id, $translation_id, &$total ) {
		$source      = new DUPCAP_Elementor_Document( $source_id );
		$translation = new DUPCAP_Elementor_Document( $translation_id );

		if ( ! $source->is_json_valid() || ! $translation->is_json_valid() ) {
			return array();
		}

		$source_map      = $source->get_element_map();
		$translation_map = $translation->get_element_map();
		$edit_url        = $translation->edit_url();
		$source_settings = self::raw_settings( $source->get_decoded_data() );
		$trans_settings  = self::raw_settings( $translation->get_decoded_data() );
		$target_lang     = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $translation_id, 'slug' ) : '';
		$rows            = array();

		foreach ( $source_map as $element_id => $source_element ) {
			if ( empty( $source_element['widget_type'] ) ) {
				continue;
			}

			if ( ! isset( $translation_map[ $element_id ] ) ) {
				$widget_type = $source_element['widget_type'];
				foreach ( $source_element['fields'] as $field_key => $original_value ) {
					if ( ! DUPCAP_Elementor_Fields::is_meaningful_text( $original_value ) || DUPCAP_Elementor_Fields::should_skip_same_text_warning( $field_key, $original_value ) ) {
						continue;
					}
					$source_segments = self::segments( $original_value );
					$seg_count       = max( 1, count( $source_segments ) );
					$total          += $seg_count;
					if ( count( $source_segments ) < 2 ) {
						$rows[] = array(
							'kind'       => 'elementor',
							'element_id' => (string) $element_id,
							'widget'     => DUPCAP_Elementor_Fields::widget_label( $widget_type ),
							'field'      => sprintf( __( '%s (missing)', 'duplicate-content-addon-for-polylang' ), DUPCAP_Elementor_Fields::field_location_label( $field_key ) ),
							'match'      => self::normalize_text( $original_value ),
							'segment'    => false,
							'post_id'    => (int) $translation_id,
							'field_key'  => (string) $field_key,
							'edit_url'   => '' !== $edit_url ? add_query_arg( DUPCAP_Frontend_Inspector::FOCUS_ARG, rawurlencode( (string) $element_id ), $edit_url ) : '',
							'note'       => __( 'This widget was added to the original page but is missing from this translation layout.', 'duplicate-content-addon-for-polylang' ),
						);
					} else {
						foreach ( $source_segments as $s_seg ) {
							$rows[] = array(
								'kind'       => 'elementor',
								'element_id' => (string) $element_id,
								'widget'     => DUPCAP_Elementor_Fields::widget_label( $widget_type ),
								'field'      => sprintf( __( '%s (missing paragraph)', 'duplicate-content-addon-for-polylang' ), DUPCAP_Elementor_Fields::field_location_label( $field_key ) ),
								'match'      => $s_seg,
								'segment'    => true,
								'post_id'    => (int) $translation_id,
								'field_key'  => (string) $field_key,
								'edit_url'   => '' !== $edit_url ? add_query_arg( DUPCAP_Frontend_Inspector::FOCUS_ARG, rawurlencode( (string) $element_id ), $edit_url ) : '',
								'note'       => __( 'This widget was added to the original page but is missing from this translation layout.', 'duplicate-content-addon-for-polylang' ),
							);
						}
					}
				}
				continue;
			}

			$trans_element = $translation_map[ $element_id ];
			$widget_type   = ! empty( $trans_element['widget_type'] ) ? $trans_element['widget_type'] : $source_element['widget_type'];

			foreach ( $source_element['fields'] as $field_key => $original_value ) {
				$translated_value = isset( $trans_element['fields'][ $field_key ] ) ? $trans_element['fields'][ $field_key ] : '';
				$raw_translated   = self::raw_field_value( $trans_settings, $element_id, $field_key );

				if ( DUPCAP_Elementor_Fields::is_untranslated_text( $field_key, $original_value, $translated_value, $target_lang ) ) {
					$segments = self::segments( $raw_translated );
					if ( count( $segments ) < 2 ) {
						++$total;
						$rows[] = self::elementor_row( $element_id, $widget_type, $field_key, self::normalize_text( $translated_value ), $edit_url, false, $translation_id );
						continue;
					}
					$total += count( $segments );
				} elseif ( self::is_compared_field( $field_key, $original_value, $translated_value ) ) {
					$source_segments = self::segments( self::raw_field_value( $source_settings, $element_id, $field_key ) );
					$total          += max( 1, count( $source_segments ) );
					$trans_segments  = self::segments( $raw_translated );
					$segments        = self::match_untranslated_segments( $trans_segments, $source_segments, $target_lang );
					$missing_segs    = self::find_missing_source_segments( $source_segments, $trans_segments, $target_lang );
					foreach ( $missing_segs as $m_seg ) {
						$m_row          = self::elementor_row( $element_id, $widget_type, $field_key, $m_seg, $edit_url, true, $translation_id );
						$m_row['field'] = sprintf( __( '%s (missing paragraph)', 'duplicate-content-addon-for-polylang' ), DUPCAP_Elementor_Fields::field_location_label( $field_key ) );
						$m_row['note']  = __( 'This paragraph was added to the original page but is missing from this translation.', 'duplicate-content-addon-for-polylang' );
						$rows[]         = $m_row;
					}
				} else {
					continue;
				}

				foreach ( $segments as $segment ) {
					$rows[] = self::elementor_row( $element_id, $widget_type, $field_key, $segment, $edit_url, true, $translation_id );
				}
			}
		}

		return $rows;
	}

	/**
	 * Element id => raw settings for every element in an Elementor tree.
	 *
	 * @param array $elements Elementor elements.
	 * @param array $map      Map built so far.
	 * @return array<string, array>
	 */
	protected static function raw_settings( array $elements, array $map = array() ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ! empty( $element['id'] ) && isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$map[ (string) $element['id'] ] = $element['settings'];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$map = self::raw_settings( $element['elements'], $map );
			}
		}

		return $map;
	}

	/**
	 * Raw setting value for a field key ("key" or "repeater[0].key").
	 *
	 * @param array  $settings_map Element id => settings.
	 * @param string $element_id   Element ID.
	 * @param string $field_key    Field key.
	 * @return string
	 */
	protected static function raw_field_value( array $settings_map, $element_id, $field_key ) {
		$value = isset( $settings_map[ $element_id ] ) ? $settings_map[ $element_id ] : array();
		$path  = preg_match( '/^([^\[]+)\[(\d+)\]\.(.+)$/', (string) $field_key, $parts )
			? array( $parts[1], (int) $parts[2], $parts[3] )
			: array( (string) $field_key );

		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
				return '';
			}
			$value = $value[ $key ];
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * One Elementor row.
	 *
	 * @param string $element_id  Elementor element ID.
	 * @param string $widget_type Widget type.
	 * @param string $field_key   Field key.
	 * @param string $text        Untranslated text.
	 * @param string $edit_url    Elementor editor URL.
	 * @param bool   $is_segment  Whether the text is one paragraph of the field.
	 * @param int    $post_id     Post that holds the translation (used by quick edit).
	 * @return array<string, mixed>
	 */
	protected static function elementor_row( $element_id, $widget_type, $field_key, $text, $edit_url, $is_segment, $post_id = 0 ) {
		$field = DUPCAP_Elementor_Fields::field_location_label( $field_key );

		if ( $is_segment ) {
			/* translators: %s: field name, e.g. Content */
			$field = sprintf( __( '%s (paragraph)', 'duplicate-content-addon-for-polylang' ), $field );
		}

		return array(
			'kind'       => 'elementor',
			'element_id' => (string) $element_id,
			'widget'     => DUPCAP_Elementor_Fields::widget_label( $widget_type ),
			'field'      => $field,
			'match'      => $text,
			'segment'    => (bool) $is_segment,
			'post_id'    => (int) $post_id,
			'field_key'  => (string) $field_key,
			'edit_url'   => '' !== $edit_url ? add_query_arg( DUPCAP_Frontend_Inspector::FOCUS_ARG, rawurlencode( (string) $element_id ), $edit_url ) : '',
		);
	}

	/**
	 * Whether a translated text field counts as compared (links, loop templates
	 * and values that are the same in every language do not).
	 *
	 * @param string $field_key        Field key.
	 * @param string $original_value   Source value.
	 * @param string $translated_value Translated value.
	 * @return bool
	 */
	protected static function is_compared_field( $field_key, $original_value, $translated_value ) {
		return ! DUPCAP_Elementor_Fields::is_link_field( $field_key )
			&& ! DUPCAP_Elementor_Fields::is_loop_template_field( $field_key )
			&& DUPCAP_Elementor_Fields::is_meaningful_text( $translated_value )
			&& ! DUPCAP_Elementor_Fields::should_skip_same_text_warning( $field_key, $original_value );
	}

	/**
	 * Classic / block content segments that also appear in the source content.
	 *
	 * @param WP_Post $source      Source post.
	 * @param WP_Post $translation Translated post.
	 * @param int     $total       Compared-string counter, increased in place.
	 * @return array<int, array<string, string>>
	 */
	protected static function content_rows( WP_Post $source, WP_Post $translation, &$total ) {
		$source_segments = self::segments( $source->post_content );
		if ( empty( $source_segments ) ) {
			return array();
		}

		$total += count( $source_segments );

		$edit_url    = self::classic_edit_url( $translation->ID );
		$label       = __( 'Content', 'duplicate-content-addon-for-polylang' );
		$rows        = array();
		$target_lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $translation->ID, 'slug' ) : '';

		$trans_segments = self::segments( $translation->post_content );
		$segments       = self::match_untranslated_segments( $trans_segments, $source_segments, $target_lang );

		foreach ( $segments as $segment ) {
			$rows[] = array(
				'kind'       => 'text',
				'element_id' => '',
				'widget'     => '',
				'field'      => $label,
				'match'      => $segment,
				'edit_url'   => self::with_focus_text( $edit_url, $segment ),
			);
		}

		$missing_segments = self::find_missing_source_segments( $source_segments, $trans_segments, $target_lang );

		foreach ( $missing_segments as $missing_segment ) {
			$rows[] = array(
				'kind'       => 'text',
				'element_id' => '',
				'widget'     => '',
				'field'      => sprintf( __( '%s (missing)', 'duplicate-content-addon-for-polylang' ), $label ),
				'match'      => $missing_segment,
				'edit_url'   => self::with_focus_text( $edit_url, $missing_segment ),
				'note'       => __( 'This content was added to the original page but is missing from this translation.', 'duplicate-content-addon-for-polylang' ),
			);
		}

		return $rows;
	}

	/**
	 * Find segments from source post that have not been translated or added to the translation.
	 *
	 * When the source post has more segments than the translation post, the extra
	 * source segments are reported as missing untranslated content.
	 *
	 * @param string[] $source_segments     Segments from source post.
	 * @param string[] $translated_segments Segments from translated post.
	 * @param string   $target_lang         Target language code.
	 * @return string[] Missing source segments.
	 */
	protected static function find_missing_source_segments( array $source_segments, array $translated_segments, $target_lang = '' ) {
		$source_count = count( $source_segments );
		$trans_count  = count( $translated_segments );

		if ( $source_count <= $trans_count || 0 === $source_count ) {
			return array();
		}

		$missing_count = $source_count - $trans_count;

		// 1. Identify which source segments are directly matched on the translated page.
		$matched_source_indices = array();
		$matched_trans_indices  = array();

		foreach ( $translated_segments as $t_idx => $t_seg ) {
			foreach ( $source_segments as $s_idx => $s_seg ) {
				if ( ! isset( $matched_source_indices[ $s_idx ] ) && self::is_text_untranslated( $s_seg, $t_seg, $target_lang ) ) {
					$matched_source_indices[ $s_idx ] = true;
					$matched_trans_indices[ $t_idx ]  = true;
					break;
				}
			}
		}

		// 2. Identify remaining unaligned source segments.
		$unmatched_s_indices = array_values( array_diff( array_keys( $source_segments ), array_keys( $matched_source_indices ) ) );
		$unmatched_t_indices = array_values( array_diff( array_keys( $translated_segments ), array_keys( $matched_trans_indices ) ) );

		$u_s_count = count( $unmatched_s_indices );
		$u_t_count = count( $unmatched_t_indices );

		if ( $u_s_count <= $u_t_count ) {
			return array();
		}

		// Calculate similarity between an unmatched source segment and translated segment.
		$calc_sim = function ( $s_text, $t_text, $s_pos_ratio, $t_pos_ratio ) {
			$score = 0.0;

			// Word overlap (words >= 3 letters).
			$s_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s_text ) : strtolower( $s_text );
			$t_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t_text ) : strtolower( $t_text );

			preg_match_all( '/[\p{L}\p{N}]{3,}/u', $s_lower, $s_words );
			preg_match_all( '/[\p{L}\p{N}]{3,}/u', $t_lower, $t_words );
			if ( ! empty( $s_words[0] ) && ! empty( $t_words[0] ) ) {
				$common_words = array_intersect( $s_words[0], $t_words[0] );
				if ( ! empty( $common_words ) ) {
					$score += 10.0 * count( $common_words );
				}
			}

			// Numbers / digits match (e.g. dates, times, amounts).
			preg_match_all( '/\b\d+(?:[\.,]\d+)?\b/u', $s_text, $s_nums );
			preg_match_all( '/\b\d+(?:[\.,]\d+)?\b/u', $t_text, $t_nums );
			if ( ! empty( $s_nums[0] ) && ! empty( $t_nums[0] ) ) {
				$common_nums = array_intersect( $s_nums[0], $t_nums[0] );
				if ( ! empty( $common_nums ) ) {
					$score += 5.0 * count( $common_nums );
				}
			}

			// Positional proximity (0.0 to 2.0).
			$score += max( 0.0, 2.0 - 2.0 * abs( $s_pos_ratio - $t_pos_ratio ) );

			return $score;
		};

		// Dynamic programming to align unmatched T into unmatched S in order.
		// dp[i][j] = best score aligning first i items of T with a subset of first j items of S.
		$dp     = array();
		$choice = array();

		for ( $i = 0; $i <= $u_t_count; $i++ ) {
			$dp[ $i ]     = array_fill( 0, $u_s_count + 1, -1.0 );
			$choice[ $i ] = array_fill( 0, $u_s_count + 1, 0 );
		}

		for ( $j = 0; $j <= $u_s_count; $j++ ) {
			$dp[0][ $j ] = 0.0;
		}

		for ( $i = 1; $i <= $u_t_count; $i++ ) {
			$t_idx       = $unmatched_t_indices[ $i - 1 ];
			$t_text      = $translated_segments[ $t_idx ];
			$t_pos_ratio = $trans_count > 1 ? $t_idx / ( $trans_count - 1 ) : 0.0;

			for ( $j = $i; $j <= $u_s_count; $j++ ) {
				$s_idx       = $unmatched_s_indices[ $j - 1 ];
				$s_text      = $source_segments[ $s_idx ];
				$s_pos_ratio = $source_count > 1 ? $s_idx / ( $source_count - 1 ) : 0.0;

				$sim = $calc_sim( $s_text, $t_text, $s_pos_ratio, $t_pos_ratio );

				// Option 1: match t[i-1] with s[j-1].
				$match_score = ( $dp[ $i - 1 ][ $j - 1 ] >= 0.0 ) ? $dp[ $i - 1 ][ $j - 1 ] + $sim : -1.0;

				// Option 2: skip s[j-1] (leave it unaligned).
				$skip_score = $dp[ $i ][ $j - 1 ];

				if ( $match_score >= $skip_score && $match_score >= 0.0 ) {
					$dp[ $i ][ $j ]     = $match_score;
					$choice[ $i ][ $j ] = 1; // Matched.
				} else {
					$dp[ $i ][ $j ]     = $skip_score;
					$choice[ $i ][ $j ] = 2; // Skipped source segment.
				}
			}
		}

		// Traceback to find which source segments were skipped (missing from translation).
		$aligned_s_indices = array();
		$curr_i            = $u_t_count;
		$curr_j            = $u_s_count;

		while ( $curr_i > 0 && $curr_j > 0 ) {
			if ( 1 === $choice[ $curr_i ][ $curr_j ] ) {
				$aligned_s_indices[ $unmatched_s_indices[ $curr_j - 1 ] ] = true;
				--$curr_i;
				--$curr_j;
			} else {
				--$curr_j;
			}
		}

		$missing = array();
		foreach ( $unmatched_s_indices as $s_idx ) {
			if ( ! isset( $aligned_s_indices[ $s_idx ] ) ) {
				$missing[] = $source_segments[ $s_idx ];
			}
		}

		// If traceback didn't catch enough, take remaining from the end.
		if ( count( $missing ) < $missing_count ) {
			$seen = array_flip( $missing );
			for ( $k = $source_count - 1; $k >= 0 && count( $missing ) < $missing_count; $k-- ) {
				$seg = $source_segments[ $k ];
				if ( ! isset( $seen[ $seg ] ) && ! isset( $matched_source_indices[ $k ] ) ) {
					$missing[]     = $seg;
					$seen[ $seg ] = true;
				}
			}
		}

		return array_slice( $missing, 0, $missing_count );
	}

	/**
	 * Match translated segments that are identical or substantially untranslated compared to source segments.
	 *
	 * @param string[] $translated_segments Segments from translated post.
	 * @param string[] $source_segments     Segments from source post.
	 * @param string   $target_lang         Target language code.
	 * @return string[] Matched translated segments.
	 */
	protected static function match_untranslated_segments( array $translated_segments, array $source_segments, $target_lang = '' ) {
		if ( empty( $translated_segments ) || empty( $source_segments ) ) {
			return array();
		}

		$matched = array();

		foreach ( $translated_segments as $t_seg ) {
			foreach ( $source_segments as $s_seg ) {
				if ( self::is_text_untranslated( $s_seg, $t_seg, $target_lang ) ) {
					$matched[] = $t_seg;
					break;
				}
			}
		}

		return $matched;
	}

	/**
	 * Whether a translated text segment is substantially untranslated compared to source text.
	 *
	 * @param string $original    Source text.
	 * @param string $translated  Translated text.
	 * @param string $target_lang Target language code.
	 * @return bool
	 */
	public static function is_text_untranslated( $original, $translated, $target_lang = '' ) {
		return DUPCAP_Elementor_Fields::is_text_substantially_untranslated( $original, $translated, $target_lang );
	}

	/**
	 * Unique, non-trivial text segments of post content.
	 *
	 * @param string $content Raw post content.
	 * @return string[]
	 */
	protected static function segments( $content ) {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return array();
		}

		$html  = has_blocks( $content ) ? $content : wpautop( $content );
		$html  = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$parts = preg_split( self::SEGMENT_SPLIT_PATTERN, $html );
		$found = array();

		foreach ( (array) $parts as $part ) {
			$text = self::normalize_text( $part );
			if ( '' === $text || self::is_trivial( $text ) ) {
				continue;
			}
			$found[ $text ] = true;
		}

		return array_keys( $found );
	}

	/**
	 * Strip markup and collapse whitespace so equal text compares equal.
	 *
	 * @param string $text Raw text or HTML.
	 * @return string
	 */
	protected static function normalize_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * Text that is identical on purpose: numbers, symbols, shortcodes, URLs, emails, one letter.
	 *
	 * @param string $text Normalized text.
	 * @return bool
	 */
	protected static function is_trivial( $text ) {
		if ( DUPCAP_Elementor_Fields::should_skip_same_text_warning( '', $text ) ) {
			return true;
		}
		if ( preg_match( '/^[\d\s\.\,\%\$€£₹\/\-\+]+$/u', $text ) ) {
			return true;
		}
		if ( preg_match( '/^\[.+\]$/s', $text ) ) {
			return true;
		}
		return ! preg_match( '/\p{L}.*\p{L}/u', $text );
	}

	/**
	 * Whether a post's visible content comes from Elementor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected static function is_elementor_built( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		if ( 'builder' === get_post_meta( (int) $post_id, DUPCAP_Elementor_Document::META_EDIT_MODE, true ) ) {
			return true;
		}
		$data = get_post_meta( (int) $post_id, DUPCAP_Elementor_Document::META_DATA, true );
		return is_string( $data ) && '' !== $data && '[]' !== $data;
	}

	/**
	 * Block / classic editor link for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected static function classic_edit_url( $post_id ) {
		$url = get_edit_post_link( (int) $post_id, 'url' );
		return $url ? (string) $url : '';
	}

	/**
	 * Add the start of the text to an editor link so the block editor can select it.
	 *
	 * @param string $url  Editor URL.
	 * @param string $text Normalized text.
	 * @return string
	 */
	protected static function with_focus_text( $url, $text ) {
		if ( '' === $url || '' === $text ) {
			return $url;
		}
		return add_query_arg( DUPCAP_Frontend_Inspector::FOCUS_TEXT_ARG, rawurlencode( mb_substr( $text, 0, 150 ) ), $url );
	}
}
