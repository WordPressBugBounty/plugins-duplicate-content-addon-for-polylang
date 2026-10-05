<?php
/**
 * Elementor Templates check.
 *
 * Elementor stores all templates in the `elementor_library` post type and keeps
 * the template kind (header, footer, popup, single, archive, ...) in the
 * `_elementor_template_type` post meta. Theme Builder display conditions live in
 * `_elementor_conditions` (Elementor Pro).
 *
 * This check verifies that every Elementor template has a translation in each
 * secondary language, grouped by template type, so a user can immediately see
 * e.g. "French Header missing".
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Check_Elementor extends DUPCAP_Check {

	protected $group = 'Theme Templates';

	const POST_TYPE = 'elementor_library';
	const META_TYPE = '_elementor_template_type';

	/**
	 * Human labels for the template types we care about.
	 *
	 * @var array
	 */
	protected $type_labels = array(
		'header'  => 'Header',
		'footer'  => 'Footer',
		'popup'   => 'Popup',
		'single'  => 'Single',
		'archive' => 'Archive',
		'page'    => 'Page',
		'section' => 'Section',
		'loop-item' => 'Loop Item',
	);

	public function run() {
		$results = array();

		// Skip silently if Elementor is not installed.
		if ( ! $this->elementor_active() ) {
			return $results;
		}

		$languages = $this->active_languages();
		$default   = $this->default_lang();

		if ( count( $languages ) < 2 ) {
			return $results;
		}

		// Make sure Polylang actually translates the Elementor library type.
		if ( ! $this->is_translated_post_type( self::POST_TYPE ) ) {
			return $results;
		}

		// Only consider templates in the default language; their translations are what we test.
		$templates = $this->get_default_lang_templates( $default );

		if ( empty( $templates ) ) {
			$results[] = $this->result(
				'elementor_none',
				DUPCAP_Result::PASS,
				__( 'No Elementor templates to check', 'duplicate-content-addon-for-polylang' ),
				__( 'No Elementor templates were found in the default language.', 'duplicate-content-addon-for-polylang' )
			);
			return $results;
		}

		// Group templates by their Elementor type.
		$by_type = array();
		foreach ( $templates as $tpl ) {
			$type            = $tpl['type'] ? $tpl['type'] : 'other';
			$by_type[ $type ][] = $tpl;
		}

		// Secondary languages only.
		$secondary = array();
		foreach ( $languages as $lang ) {
			$slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( $slug !== $default ) {
				$secondary[] = $slug;
			}
		}

		foreach ( $by_type as $type => $items ) {
			$label = $this->type_label( $type );

			// Collect, per language, which templates are missing a translation.
			$missing_by_lang = array();

			foreach ( $items as $tpl ) {
				$translations = function_exists( 'pll_get_post_translations' )
					? pll_get_post_translations( $tpl['id'] )
					: array();

				foreach ( $secondary as $slug ) {
					if ( empty( $translations[ $slug ] ) ) {
						$missing_by_lang[ $slug ][] = $tpl;
					}
				}
			}

			$total = count( $items );

			if ( empty( $missing_by_lang ) ) {
				$results[] = $this->result(
					'elementor_' . $type,
					DUPCAP_Result::PASS,
					/* translators: %s: template type label */
					sprintf( __( '%s templates translated', 'duplicate-content-addon-for-polylang' ), $label ),
					sprintf(
						/* translators: 1: count 2: type */
						_n(
							'%1$d %2$s template is translated in every language.',
							'All %1$d %2$s templates are translated in every language.',
							$total,
							'duplicate-content-addon-for-polylang'
						),
						$total,
						strtolower( $label )
					)
				);
				continue;
			}

			foreach ( $missing_by_lang as $slug => $missing_items ) {
				$lang_name = $this->language_name( $slug );

				foreach ( $missing_items as $tpl ) {
					$template_id = ! empty( $tpl['id'] ) ? (int) $tpl['id'] : 0;
					$ignore_key  = class_exists( 'DUPCAP_Ignored_Issues' )
						? DUPCAP_Ignored_Issues::elementor_key( 'library_missing', $template_id, $slug, '', $type )
						: '';

					if ( '' !== $ignore_key && DUPCAP_Ignored_Issues::is_ignored( $ignore_key ) ) {
						continue;
					}

					$doc       = new DUPCAP_Elementor_Document( $template_id );
					$trans_url = $doc->new_translation_url( $slug );
					if ( '' === $trans_url ) {
						$trans_url = admin_url( 'edit.php?post_type=' . self::POST_TYPE );
					}

					$title = ! empty( $tpl['title'] ) ? $tpl['title'] : get_the_title( $template_id );
					if ( ! is_string( $title ) || '' === trim( $title ) ) {
						$title = __( '(no title)', 'duplicate-content-addon-for-polylang' );
					}

					$results[] = $this->result(
						'elementor_' . $type . '_' . $template_id . '_' . $slug,
						DUPCAP_Result::WARNING,
						/* translators: 1: template type label 2: language name */
						sprintf( __( '%1$s template missing %2$s translation', 'duplicate-content-addon-for-polylang' ), $label, $lang_name ),
						/* translators: 1: template title 2: template type 3: language name */
						sprintf( __( 'Template "%1$s" (%2$s) is missing %3$s translation.', 'duplicate-content-addon-for-polylang' ), $title, $label, $lang_name )
					)->with_fix( $trans_url, 
					/* translators: %s: language name */
					sprintf( __( 'Translate %s', 'duplicate-content-addon-for-polylang' ), $lang_name ) 
					)
					->with_details(
						array(
							array(
								/* translators: 1: template title 2: language name */
								'label'           => sprintf( __( '%1$s — %2$s', 'duplicate-content-addon-for-polylang' ), $title, $lang_name ),
								'title'           => $title,
								'post_id'         => $template_id,
								'template_id'     => $template_id,
								'post_type'       => ( $template_id > 0 && get_post_type( $template_id ) ) ? (string) get_post_type( $template_id ) : self::POST_TYPE,
								'post_type_label' => $label,
								'template_type'   => $type,
								'language'        => $slug,
								'language_name'   => $lang_name,
								'edit_url'        => get_edit_post_link( $template_id, 'raw' ),
								'translate_url'   => $trans_url,
								'action_url'      => $trans_url,
								/* translators: %s: language name */
								'action_label'    => sprintf( __( 'Translate %s', 'duplicate-content-addon-for-polylang' ), $lang_name ),
								'ignore_key'      => $ignore_key,
							),
						)
					);
				}
			}
		}

		return $results;
	}

	/**
	 * Human language name for a Polylang slug.
	 *
	 * @param string $slug Language slug.
	 * @return string
	 */
	protected function language_name( $slug ) {
		$slug = (string) $slug;
		if ( class_exists( 'DUPCAP_Elementor_Fields' ) ) {
			$label = DUPCAP_Elementor_Fields::language_label( $slug );
			if ( is_string( $label ) && '' !== $label ) {
				return $label;
			}
		}

		foreach ( $this->active_languages() as $lang ) {
			$lang_slug = is_object( $lang ) ? $lang->slug : $lang;
			if ( $lang_slug === $slug && is_object( $lang ) && ! empty( $lang->name ) ) {
				return (string) $lang->name;
			}
		}

		return strtoupper( $slug );
	}

	/**
	 * Is Elementor (free or pro) active?
	 *
	 * @return bool
	 */
	protected function elementor_active() {
		return did_action( 'elementor/loaded' ) || defined( 'ELEMENTOR_VERSION' ) || post_type_exists( self::POST_TYPE );
	}

	/**
	 * Fetch published/draft Elementor templates in the default language.
	 *
	 * @param string $default Default language slug.
	 * @return array[] Each: id, title, type.
	 */
	protected function get_default_lang_templates( $default ) {
		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1, // Every template. IDs only, so this stays cheap.
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		// Limit to the default language when Polylang query var is available.
		if ( $default ) {
			$query_args['lang'] = $default;
		}

		$ids = array_map( 'intval', (array) get_posts( $query_args ) );

		// Load template-type meta 200 posts per query instead of one query per post.
		foreach ( array_chunk( $ids, 200 ) as $batch ) {
			update_meta_cache( 'post', $batch );
		}

		$templates = array();
		foreach ( $ids as $id ) {
			$type = get_post_meta( $id, self::META_TYPE, true );
			$type = is_string( $type ) ? $type : '';

			// Site kits and global widgets are not typical translation targets.
			if ( in_array( $type, DUPCAP_Elementor_Document::EXCLUDED_TEMPLATE_TYPES, true ) ) {
				continue;
			}

			$templates[] = array(
				'id'    => (int) $id,
				'title' => get_the_title( $id ),
				'type'  => $type,
			);
		}
		return $templates;
	}

	/**
	 * Human label for an Elementor template type.
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	protected function type_label( $type ) {
		if ( isset( $this->type_labels[ $type ] ) ) {
			return $this->type_labels[ $type ];
		}
		// Fallback: turn "my-type" into "My Type".
		return ucwords( str_replace( array( '-', '_' ), ' ', $type ) );
	}
}
