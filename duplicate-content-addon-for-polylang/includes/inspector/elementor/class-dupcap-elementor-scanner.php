<?php
/**
 * Elementor translation scanner orchestrator.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Elementor_Scanner {

	const OPTION_KEY = 'dupcap_elementor_last_report';

	/** @var string */
	protected $default_lang = '';

	/** @var array */
	protected $secondary_langs = array();

	/** @var DUPCAP_Elementor_Finding[] */
	protected $findings = array();

	/** @var array */
	protected $stats = array();

	/** @var int */
	protected $documents_found = 0;

	/** @var int */
	protected $documents_scanned = 0;

	/** @var int */
	protected $widgets_scanned = 0;

	/** @var bool */
	protected $documents_truncated = false;

	/**
	 * Run the full scan.
	 *
	 * @return array
	 */



	/**
	 * Rescan a single Elementor document (and its translations) without
	 * touching the rest of the site. Accepts either the default-language
	 * source ID or a translation ID.
	 *
	 * @param int $post_id Document ID (source or translation).
	 * @return array<int, array<string, mixed>> Fresh finding rows for this document.
	 */
	public function rescan_document( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! $this->elementor_active() ) {
			return array();
		}

		if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_default_language' ) ) {
			return array();
		}

		$this->reset();
		$this->default_lang = (string) pll_default_language( 'slug' );

		$languages = pll_languages_list( array( 'fields' => 'slug' ) );
		foreach ( (array) $languages as $slug ) {
			if ( $slug && $slug !== $this->default_lang ) {
				$this->secondary_langs[] = $slug;
			}
		}

		if ( empty( $this->secondary_langs ) ) {
			return array();
		}

		$source_id = $this->resolve_to_source_id( $post_id );

		$previous_lang = $this->pin_default_language();
		try {
			// Orphans have no default-language source. scan_document() would
			// treat them as sources and never emit orphan_translation.
			if ( $this->is_orphan_translation( $post_id ) ) {
				$this->report_orphan_translation( $post_id );
			} else {
				$this->scan_document( $source_id );
			}

			// Site-level Theme Builder coverage (Entire Site / CPEL) is not tied to
			// one document pair. Re-run it on single-issue rescan so those findings
			// are not wiped when replace_document_findings_in_report() runs.
			$this->check_theme_builder_header_coverage();
		} finally {
			$this->restore_language( $previous_lang );
		}

		$findings = array();
		foreach ( $this->findings as $finding ) {
			if ( $finding instanceof DUPCAP_Elementor_Finding ) {
				$findings[] = $finding->to_array();
			}
		}

		return $findings;
	}

	/**
	 * Finding codes that are site-wide (not owned by one document pair).
	 *
	 * @return string[]
	 */
	public static function site_level_finding_codes() {
		return array(
			'missing_sitewide_header',
			'cpel_recommended_for_headers',
		);
	}

	/**
	 * Resolve any document ID (source or translation) to its default-language source ID.
	 *
	 * @param int $post_id Document ID.
	 * @return int
	 */
	protected function resolve_to_source_id( $post_id ) {
		$post_id = (int) $post_id;

		if ( '' === $this->default_lang || ! function_exists( 'pll_get_post_language' ) ) {
			return $post_id;
		}

		$lang = pll_get_post_language( $post_id, 'slug' );
		if ( $lang === $this->default_lang ) {
			return $post_id;
		}

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );
			if ( ! empty( $translations[ $this->default_lang ] ) ) {
				return (int) $translations[ $this->default_lang ];
			}
		}

		return $post_id;
	}

	/**
	 * Replace saved findings for a set of document IDs with freshly scanned ones,
	 * and recompute severity counts. Health score is derived at render time by
	 * DUPCAP_Elementor_Scanner::health_score_from_report(), so it stays correct.
	 *
	 * @param array<string, mixed>            $report              Saved Elementor report.
	 * @param array<int, int>                 $affected_template_ids Document IDs whose findings should be replaced.
	 * @param array<int, array<string, mixed>> $fresh_findings      Fresh finding rows for those documents.
	 * @return array<string, mixed>
	 */
	public static function replace_document_findings_in_report( array $report, array $affected_template_ids, array $fresh_findings ) {
		$findings         = isset( $report['findings'] ) && is_array( $report['findings'] ) ? $report['findings'] : array();
		$site_level_codes = self::site_level_finding_codes();

		$findings = array_values(
			array_filter(
				$findings,
				function ( $finding ) use ( $affected_template_ids, $site_level_codes ) {
					$code = isset( $finding['code'] ) ? (string) $finding['code'] : '';
					// Drop previous site-level rows; fresh rescan re-adds them when still valid.
					if ( in_array( $code, $site_level_codes, true ) ) {
						return false;
					}
					$tid = isset( $finding['template_id'] ) ? (int) $finding['template_id'] : 0;
					return ! in_array( $tid, $affected_template_ids, true );
				}
			)
		);

		foreach ( $fresh_findings as $finding ) {
			$findings[] = $finding;
		}

		$report['findings'] = $findings;

		return self::refresh_report_counts( $report );
	}

	/**
	 * Recount severities and the problem total after findings were changed.
	 *
	 * @param array<string, mixed> $report Saved Elementor report.
	 * @return array<string, mixed>
	 */
	public static function refresh_report_counts( array $report ) {
		$findings = isset( $report['findings'] ) && is_array( $report['findings'] ) ? $report['findings'] : array();

		$counts = array(
			'critical' => 0,
			'major'    => 0,
			'warning'  => 0,
			'info'     => 0,
		);
		foreach ( $findings as $finding ) {
			$severity = isset( $finding['severity'] ) ? $finding['severity'] : '';
			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ]++;
			}
		}
		$report['counts'] = $counts;

		if ( isset( $report['summary'] ) && is_array( $report['summary'] ) ) {
			$report['summary']['problems_found'] = $counts['critical'] + $counts['major'] + $counts['warning'];
		}

		return $report;
	}

	public function scan() {

		$this->reset();

		if ( ! $this->elementor_active() ) {
			$finding = new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::WARNING,
				'elementor_inactive',
				__( 'Elementor is not active on this site.', 'duplicate-content-addon-for-polylang' )
			);
			$finding->with_fix_text(
				__( 'The scanner needs Elementor to read _elementor_data.', 'duplicate-content-addon-for-polylang' ),
				__( 'Install and activate Elementor, then run the scan again.', 'duplicate-content-addon-for-polylang' )
			);
			return $this->build_report( array( $finding ) );
		}

		if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_default_language' ) ) {
			$finding = new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::WARNING,
				'polylang_missing',
				__( 'Polylang is required for this scanner.', 'duplicate-content-addon-for-polylang' )
			);
			$finding->with_fix_text(
				__( 'This scanner compares Elementor documents across Polylang languages.', 'duplicate-content-addon-for-polylang' ),
				__( 'Activate Polylang and configure at least two languages.', 'duplicate-content-addon-for-polylang' )
			);
			return $this->build_report( array( $finding ) );
		}

		$this->default_lang = (string) pll_default_language( 'slug' );

		$languages          = pll_languages_list( array( 'fields' => 'slug' ) );

		foreach ( (array) $languages as $slug ) {
			if ( $slug && $slug !== $this->default_lang ) {
				$this->secondary_langs[] = $slug;
			}
		}

		if ( count( $this->secondary_langs ) < 1 ) {
			$finding = new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::INFO,
				'single_language',
				__( 'Only one language is configured.', 'duplicate-content-addon-for-polylang' )
			);
			$finding->with_fix_text(
				__( 'Translation checks need at least one secondary language.', 'duplicate-content-addon-for-polylang' ),
				__( 'Add another language in Polylang settings.', 'duplicate-content-addon-for-polylang' )
			)->with_fix_link( admin_url( 'admin.php?page=mlang' ), __( 'Polylang languages', 'duplicate-content-addon-for-polylang' ) );
			return $this->build_report( array( $finding ) );
		}

		$previous_lang = $this->pin_default_language();

		try {
			$document_ids = $this->collect_document_ids();
			$this->documents_found = count( $document_ids );

			/**
			 * Max Elementor documents the deep scan parses. 0 (the default) scans
			 * every document; a host that times out can set a cap here.
			 *
			 * @param int $limit Document limit, 0 for no limit.
			 */
			$limit = (int) apply_filters( 'dupcap_elementor_scan_limit', 0 );
			if ( $limit > 0 && count( $document_ids ) > $limit ) {
				$document_ids            = array_slice( $document_ids, 0, $limit );
				$this->documents_truncated = true;
			}

			$this->scan_orphan_translations();

			foreach ( $document_ids as $post_id ) {
				$this->scan_document( (int) $post_id );
			}

			$this->check_theme_builder_header_coverage();

			if ( empty( $this->findings ) && $this->documents_scanned > 0 ) {
				$finding = new DUPCAP_Elementor_Finding(
					DUPCAP_Elementor_Finding::INFO,
					'scan_complete',
					__( 'No Elementor translation problems were detected in the scanned documents.', 'duplicate-content-addon-for-polylang' )
				);
				$finding->with_fix_text(
					__( 'All scanned templates and pages passed the Phase 1 checks.', 'duplicate-content-addon-for-polylang' ),
					__( 'Re-scan after adding new templates or translations.', 'duplicate-content-addon-for-polylang' )
				);
				$this->findings[] = $finding;
			}

			return $this->build_report( $this->findings );
		} finally {
			$this->restore_language( $previous_lang );
		}
	}

	/**
	 * Reset scan state.
	 *
	 * @return void
	 */
	protected function reset() {
		$this->findings            = array();
		$this->stats               = array(
			'headers'               => 0,
			'footers'               => 0,
			'archives'              => 0,
			'singles'               => 0,
			'popups'                => 0,
			'loops'                 => 0,
			'pages'                 => 0,
			'posts'                 => 0,
			'templates'             => 0,
			'widgets'               => 0,
			'broken_relationships'  => 0,
			'missing_templates'     => 0,
			'empty_templates'       => 0,
			'outdated_templates'    => 0,
			'invalid_json'          => 0,
			'missing_widgets'       => 0,
			'missing_fields'        => 0,
			'condition_issues'      => 0,
			'by_type'               => array(),
		);
		$this->documents_found     = 0;
		$this->documents_scanned   = 0;
		$this->widgets_scanned     = 0;
		$this->documents_truncated = false;
	}

	/**
	 * Is Elementor active?
	 *
	 * @return bool
	 */
	protected function elementor_active() {
		return did_action( 'elementor/loaded' )
			|| defined( 'ELEMENTOR_VERSION' )
			|| post_type_exists( 'elementor_library' );
	}

	/**
	 * Whether a post type is configured as translatable in Polylang settings.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	protected function is_translated_post_type( $post_type ) {
		if ( function_exists( 'pll_is_translated_post_type' ) ) {
			return (bool) pll_is_translated_post_type( $post_type );
		}
		$pll = function_exists( 'PLL' ) ? PLL() : null;
		if ( $pll && isset( $pll->model ) && is_callable( array( $pll->model, 'is_translated_post_type' ) ) ) {
			return (bool) $pll->model->is_translated_post_type( $post_type );
		}
		return false;
	}

	/**
	 * Collect default-language Elementor document IDs.
	 *
	 * @return int[]
	 */
	protected function collect_document_ids() {
		$post_types = array( 'elementor_library', 'page', 'post' );
		$post_types = array_merge( $post_types, $this->public_cpt_slugs() );
		$post_types = array_values( array_unique( $post_types ) );

		// Filter out post types that are not configured as translatable in Polylang.
		$post_types = array_values(
			array_filter(
				$post_types,
				array( $this, 'is_translated_post_type' )
			)
		);

		if ( empty( $post_types ) ) {
			return array();
		}

		$ids = array();

		foreach ( $post_types as $post_type ) {
			$query_args = array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find Elementor builder documents only.
				'meta_query'     => array(
					array(
						'key'   => DUPCAP_Elementor_Document::META_EDIT_MODE,
						'value' => 'builder',
					),
				),
			);

			if ( $this->default_lang ) {
				$query_args['lang'] = $this->default_lang;
			}

			if ( 'elementor_library' === $post_type ) {
				unset( $query_args['meta_query'] );
			}

			$found = get_posts( $query_args );
			foreach ( (array) $found as $id ) {
				$id = (int) $id;
				if ( $this->is_kit_document( $id ) ) {
					continue;
				}
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );

		/**
		 * Filter Elementor document IDs before scanning.
		 *
		 * @param int[]  $ids          Document IDs.
		 * @param string $default_lang Default language slug.
		 */
		return apply_filters( 'dupcap_elementor_document_ids', $ids, $this->default_lang );
	}

	/**
	 * Public custom post type slugs.
	 *
	 * @return string[]
	 */
	protected function public_cpt_slugs() {
		$types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'names'
		);

		unset( $types['elementor_library'], $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Scan translated documents that have no default-language source.
	 *
	 * @return void
	 */
	protected function scan_orphan_translations() {
		$post_types = array( 'elementor_library', 'page', 'post' );

		// Filter out post types that are not configured as translatable in Polylang.
		$post_types = array_values(
			array_filter(
				$post_types,
				array( $this, 'is_translated_post_type' )
			)
		);

		if ( empty( $post_types ) ) {
			return;
		}

		foreach ( $this->secondary_langs as $lang ) {
			$ids = get_posts(
				array(
					'post_type'      => $post_types,
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => -1, // Every document in this language, IDs only.
					'fields'         => 'ids',
					'lang'           => $lang,
					'no_found_rows'  => true,
				)
			);

			foreach ( (array) $ids as $post_id ) {
				if ( $this->is_orphan_translation( (int) $post_id ) ) {
					$this->report_orphan_translation( (int) $post_id );
				}
			}
		}
	}

	/**
	 * Whether a document is a translation with no default-language source.
	 *
	 * @param int $post_id Document ID.
	 * @return bool
	 */
	protected function is_orphan_translation( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! $this->is_elementor_document( $post_id ) || $this->is_kit_document( $post_id ) ) {
			return false;
		}

		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return false;
		}

		$lang = pll_get_post_language( $post_id, 'slug' );
		if ( ! is_string( $lang ) || '' === $lang || $lang === $this->default_lang ) {
			return false;
		}

		$translations = function_exists( 'pll_get_post_translations' )
			? pll_get_post_translations( $post_id )
			: array();
		$source_id    = isset( $translations[ $this->default_lang ] ) ? (int) $translations[ $this->default_lang ] : 0;

		return ! ( $source_id > 0 && get_post( $source_id ) );
	}

	/**
	 * Record one orphan translation finding.
	 *
	 * @param int $post_id Document ID.
	 * @return void
	 */
	protected function report_orphan_translation( $post_id ) {
		$post_id = (int) $post_id;
		$lang    = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '';
		$doc     = new DUPCAP_Elementor_Document( $post_id );

		$this->stats['broken_relationships']++;
		$this->findings[] = ( new DUPCAP_Elementor_Finding(
			self::orphan_severity( $post_id, $doc ),
			'orphan_translation',
			sprintf(
				/* translators: 1: template title 2: language */
				__( '"%1$s" (%2$s) is not linked to an original', 'duplicate-content-addon-for-polylang' ),
				$doc->title,
				strtoupper( $lang )
			)
		) )
			->with_template( $doc->id, $doc->title, $doc->document_type )
			->with_language( $lang )
			->with_fix_text(
				__( 'This layout is in another language, but it is not connected to an original version in Polylang.', 'duplicate-content-addon-for-polylang' ),
				__( 'Create or choose the original-language version and link this layout to it in Polylang.', 'duplicate-content-addon-for-polylang' )
			)
			->with_fix_link( $doc->new_translation_url( $this->default_lang ), __( 'Connect original', 'duplicate-content-addon-for-polylang' ) );
	}

	/**
	 * How bad an unlinked layout actually is.
	 *
	 * "Not linked to an original" covers three very different things, and calling
	 * all of them Critical buried the two that matter. On the reference site the
	 * twelve orphans are three empty shells, six published layouts and three
	 * drafts.
	 *
	 * Emptiness is the signal, not the title and not the status alone: four of
	 * those layouts are titled "auto draft" while being published, and none of
	 * the twelve has post_status `auto-draft`.
	 *
	 * @since 2.3.0
	 *
	 * @param int                       $post_id  Layout post ID.
	 * @param DUPCAP_Elementor_Document $document Loaded document.
	 * @return string A DUPCAP_Elementor_Finding severity.
	 */
	protected static function orphan_severity( $post_id, DUPCAP_Elementor_Document $document ) {
		// Nothing was ever built here, so nothing can be lost or shown.
		if ( ! $document->has_elements() ) {
			return DUPCAP_Elementor_Finding::INFO;
		}

		// A real layout that is live but connected to nothing: visitors can reach
		// it, and language switching cannot find its counterpart.
		if ( 'publish' === get_post_status( $post_id ) ) {
			return DUPCAP_Elementor_Finding::CRITICAL;
		}

		// Real work, not yet live.
		return DUPCAP_Elementor_Finding::WARNING;
	}

	/**
	 * Whether a post is built with Elementor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_elementor_document( $post_id ) {
		if ( 'elementor_library' === get_post_type( $post_id ) ) {
			return true;
		}
		return 'builder' === get_post_meta( $post_id, DUPCAP_Elementor_Document::META_EDIT_MODE, true );
	}

	/**
	 * Whether a post is an Elementor library type that should not be scanned
	 * (site kits and global widgets).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_kit_document( $post_id ) {
		return DUPCAP_Elementor_Document::is_excluded_library_document( $post_id );
	}

	/**
	 * Scan one default-language document and its translations.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	protected function scan_document( $post_id ) {
		if ( $this->is_kit_document( $post_id ) ) {
			return;
		}

		$source = new DUPCAP_Elementor_Document( $post_id );
		$this->documents_scanned++;
		$this->track_document_type( $source );
		$this->widgets_scanned += $source->widget_count();

		if ( '' === $source->language ) {
			$this->stats['broken_relationships']++;
			$this->findings[] = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::WARNING,
				'missing_language',
				sprintf(
					/* translators: %s: template title */
					__( 'Missing language assignment: "%s"', 'duplicate-content-addon-for-polylang' ),
					$source->title
				)
			) )
				->with_template( $source->id, $source->title, $source->document_type )
				->with_fix_text(
					__( 'Polylang has not assigned a language to this Elementor document.', 'duplicate-content-addon-for-polylang' ),
					__( 'Edit the document and set its language in the Polylang language box.', 'duplicate-content-addon-for-polylang' )
				)
				->with_fix_link( get_edit_post_link( $source->id, 'raw' ) ?: $source->edit_url(), __( 'Edit document', 'duplicate-content-addon-for-polylang' ) );
		}

		$this->check_json_and_structure( $source );
		$this->scan_document_dead_links( $source );

		foreach ( $this->secondary_langs as $lang ) {
			$translation_id = isset( $source->translations[ $lang ] ) ? (int) $source->translations[ $lang ] : 0;

			if ( $translation_id <= 0 || ! get_post( $translation_id ) ) {
				$this->stats['missing_templates']++;
				$this->findings[] = ( new DUPCAP_Elementor_Finding(
					DUPCAP_Elementor_Finding::CRITICAL,
					'missing_translation',
					sprintf(
						/* translators: 1: template title 2: language */
						__( 'Missing %2$s translation for "%1$s"', 'duplicate-content-addon-for-polylang' ),
						$source->title,
						strtoupper( $lang )
					)
				) )
					->with_template( $source->id, $source->title, $source->document_type )
					->with_language( $lang )
					->with_fix_text(
						sprintf(
							/* translators: 1: document type (Popup, Header, etc.) 2: language name */
							__( 'This %1$s has no %2$s translation linked in Polylang.', 'duplicate-content-addon-for-polylang' ),
							$source->type_label(),
							DUPCAP_Elementor_Fields::language_label( $lang )
						),
						sprintf(
							/* translators: %s: language name */
							__( 'Duplicate or translate this document into %s using Polylang, then translate the Elementor content.', 'duplicate-content-addon-for-polylang' ),
							DUPCAP_Elementor_Fields::language_label( $lang )
						)
					)
					->with_fix_link( $source->new_translation_url( $lang ), __( 'Add translation', 'duplicate-content-addon-for-polylang' ) );
				continue;
			}

			$translation = new DUPCAP_Elementor_Document( $translation_id );
			$this->scan_document_dead_links( $translation );
			$this->check_translation_health( $source, $translation, $lang );
			$this->check_display_conditions( $source, $translation, $lang );
			$this->diff_documents( $source, $translation, $lang );
		}
	}

	/**
	 * Whether Connect Polylang for Elementor is managing Theme Builder conditions.
	 *
	 * When active, empty conditions on translations are intentional.
	 *
	 * @return bool
	 */
	protected function is_cpel_managing_conditions() {
		return defined( 'CPEL_PLUGIN_VERSION' );
	}

	/**
	 * Install or activate URL for a plugin that routes templates by language.
	 *
	 * Language Switcher for Polylang carries an Elementor template translation
	 * sync, which is what these findings actually need. Null when there is
	 * nothing to suggest:
	 *
	 * - Connect Polylang for Elementor is already managing conditions, so the
	 *   routing works and suggesting anything else would be noise.
	 * - Language Switcher is already running, so the sync is available.
	 *
	 * @return array{url: string, label: string}|null
	 */
	protected function get_template_sync_action_link() {
		if ( $this->is_cpel_managing_conditions() || defined( 'LSDP' ) ) {
			return null;
		}

		$slug     = 'language-switcher-for-divi-polylang';
		$basename = DUPCAP_Plugin_Status::find( $slug );

		if ( '' !== $basename ) {
			return array(
				'url'   => DUPCAP_Plugin_Status::activate_url( $basename ),
				'label' => __( 'Activate Language Switcher for Polylang', 'duplicate-content-addon-for-polylang' ),
			);
		}

		return array(
			'url'   => DUPCAP_Plugin_Status::install_url( $slug ),
			'label' => __( 'Install Language Switcher for Polylang', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/**
	 * Warn when no published default-language Theme Builder header covers Entire Site.
	 *
	 * Without an include/general condition on a published default-language header,
	 * many pages show no header. Language Switcher for Polylang is suggested when
	 * it is not active, because language-aware condition routing usually fixes the
	 * multilingual Theme Builder setup that leads to this gap.
	 *
	 * @return void
	 */
	protected function check_theme_builder_header_coverage() {
		if ( ! post_type_exists( 'elementor_library' ) ) {
			return;
		}

		$query_args = array(
			'post_type'              => 'elementor_library',
			'post_status'            => 'publish',
			'posts_per_page'         => -1, // Every published header template, IDs only.
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find published Elementor header templates.
			'meta_query'             => array(
				array(
					'key'   => DUPCAP_Elementor_Document::META_TEMPLATE_TYPE,
					'value' => 'header',
				),
			),
			'lang'                   => '',
		);

		$header_ids = get_posts( $query_args );
		if ( empty( $header_ids ) ) {
			return;
		}

		$default_headers      = array();
		$has_entire_site      = false;
		$has_any_include      = false;
		$example_id           = 0;
		$example_title        = '';

		foreach ( $header_ids as $header_id ) {
			$header_id = (int) $header_id;
			$doc       = new DUPCAP_Elementor_Document( $header_id );
			$lang      = (string) $doc->language;

			if ( '' !== $lang && $lang !== $this->default_lang ) {
				continue;
			}

			$default_headers[] = $doc;
			$conditions        = $doc->get_conditions();

			if ( $doc->has_entire_site_condition( $conditions ) ) {
				$has_entire_site = true;
			}

			foreach ( $conditions as $condition ) {
				if ( is_string( $condition ) && 0 === strpos( $condition, 'include/' ) ) {
					$has_any_include = true;
					break;
				}
			}

			if ( $example_id <= 0 ) {
				$example_id    = $doc->id;
				$example_title = $doc->title;
			}
		}

		if ( $has_entire_site ) {
			$this->maybe_suggest_template_sync_for_multilingual_headers( $header_ids );
			return;
		}

		$sync_link   = $this->get_template_sync_action_link();
		$lang_label  = DUPCAP_Elementor_Fields::language_label( $this->default_lang );
		$theme_url   = admin_url( 'edit.php?post_type=elementor_library&elementor_library_type=header' );
		$fix_url    = $sync_link ? $sync_link['url'] : $theme_url;
		$fix_label  = $sync_link
			? $sync_link['label']
			: __( 'Open Theme Builder headers', 'duplicate-content-addon-for-polylang' );

		if ( empty( $default_headers ) ) {
			$this->stats['condition_issues']++;
			$finding = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::CRITICAL,
				'missing_sitewide_header',
				sprintf(
					/* translators: %s: default language name */
					__( 'No published Theme Builder header for %s', 'duplicate-content-addon-for-polylang' ),
					$lang_label
				)
			) )
				->with_language( $this->default_lang )
				->with_fix_text(
					sprintf(
						/* translators: %s: default language name */
						__( 'Published Elementor headers exist in other languages, but none are assigned to %s. Default-language pages may show no header.', 'duplicate-content-addon-for-polylang' ),
						$lang_label
					),
					$sync_link
						? __( 'Publish a header for the default language with “Entire Site” display conditions, then install Language Switcher for Polylang so translated templates are linked and follow the language.', 'duplicate-content-addon-for-polylang' )
						: __( 'Publish a header for the default language and set its display conditions to Entire Site.', 'duplicate-content-addon-for-polylang' )
				)
				->with_fix_link( $fix_url, $fix_label );

			$this->findings[] = $finding;
			return;
		}

		$this->stats['condition_issues']++;

		if ( ! $has_any_include ) {
			$code    = 'missing_sitewide_header';
			$what    = __( 'No published Theme Builder header covers Entire Site / default language', 'duplicate-content-addon-for-polylang' );
			$why     = sprintf(
				/* translators: %s: default language name */
				__( 'Published %s headers exist, but none have Elementor display conditions. Without an “Entire Site” (or other include) rule, those headers never appear on the front end.', 'duplicate-content-addon-for-polylang' ),
				$lang_label
			);
			$how     = $sync_link
				? __( 'Open the default-language header in Theme Builder and set display conditions to Entire Site. Then install Language Switcher for Polylang so translated headers are linked to the original and show per language.', 'duplicate-content-addon-for-polylang' )
				: __( 'Open the default-language header in Theme Builder and set display conditions to Entire Site.', 'duplicate-content-addon-for-polylang' );
			$severity = DUPCAP_Elementor_Finding::CRITICAL;
		} else {
			$code    = 'missing_sitewide_header';
			$what    = __( 'No published Theme Builder header uses Entire Site for the default language', 'duplicate-content-addon-for-polylang' );
			$why     = sprintf(
				/* translators: %s: default language name */
				__( 'Your %s headers only use page- or archive-specific display conditions. Pages that are not listed may show no header. Elementor has no built-in “exclude by language” rule.', 'duplicate-content-addon-for-polylang' ),
				$lang_label
			);
			$how     = $sync_link
				? __( 'Set the default-language header to Entire Site, keep language-specific headers for translations, and install Language Switcher for Polylang so the correct header is shown per language.', 'duplicate-content-addon-for-polylang' )
				: __( 'Set the default-language header’s display conditions to Entire Site, or add include rules for every page that should show it.', 'duplicate-content-addon-for-polylang' );
			$severity = DUPCAP_Elementor_Finding::MAJOR;
		}

		$finding = ( new DUPCAP_Elementor_Finding( $severity, $code, $what ) )
			->with_language( $this->default_lang )
			->with_fix_text( $why, $how )
			->with_fix_link( $fix_url, $fix_label );

		if ( $example_id > 0 ) {
			$finding->with_template( $example_id, $example_title, 'header' );
			if ( ! $sync_link ) {
				$finding->with_fix_link(
					( new DUPCAP_Elementor_Document( $example_id ) )->edit_url(),
					__( 'Set display conditions', 'duplicate-content-addon-for-polylang' )
				);
			}
		}

		$this->findings[] = $finding;
	}

	/**
	 * Suggest a template-sync plugin when multilingual Theme Builder headers exist.
	 *
	 * Elementor has no native “by language” display condition. Without CPEL,
	 * default-language Entire Site headers and translated page-specific headers
	 * often fight or disappear depending on which language Polylang is querying.
	 *
	 * @param int[] $header_ids Published header template IDs (all languages).
	 * @return void
	 */
	protected function maybe_suggest_template_sync_for_multilingual_headers( array $header_ids ) {
		if ( $this->is_cpel_managing_conditions() ) {
			return;
		}

		$sync_link = $this->get_template_sync_action_link();
		if ( ! $sync_link ) {
			return;
		}

		$has_default   = false;
		$has_secondary = false;
		$example_id    = 0;
		$example_title = '';

		foreach ( $header_ids as $header_id ) {
			$header_id = (int) $header_id;
			$doc       = new DUPCAP_Elementor_Document( $header_id );
			$lang      = (string) $doc->language;

			if ( '' === $lang || $lang === $this->default_lang ) {
				$has_default = true;
				if ( $example_id <= 0 ) {
					$example_id    = $doc->id;
					$example_title = $doc->title;
				}
			} elseif ( in_array( $lang, $this->secondary_langs, true ) ) {
				$has_secondary = true;
			}
		}

		if ( ! $has_default || ! $has_secondary ) {
			return;
		}

		$this->stats['condition_issues']++;
		$finding = ( new DUPCAP_Elementor_Finding(
			DUPCAP_Elementor_Finding::WARNING,
			'cpel_recommended_for_headers',
			__( 'Language Switcher for Polylang recommended for multilingual headers', 'duplicate-content-addon-for-polylang' )
		) )
			->with_language( $this->default_lang )
			->with_fix_text(
				__( 'You have published Theme Builder headers in more than one language. Elementor cannot natively show a different header per Polylang language, so default-language “Entire Site” headers and translated page-specific headers often conflict or fail to appear.', 'duplicate-content-addon-for-polylang' ),
				__( 'Install Language Switcher for Polylang and link the translated headers to the original, so the correct one is shown for each language. Keep the default-language header on Entire Site.', 'duplicate-content-addon-for-polylang' )
			)
			->with_fix_link( $sync_link['url'], $sync_link['label'] );

		if ( $example_id > 0 ) {
			$finding->with_template( $example_id, $example_title, 'header' );
		}

		$this->findings[] = $finding;
	}

	/**
	 * Audit Elementor Theme Builder display conditions across a translation pair.
	 *
	 * Detects:
	 * - Translation has no conditions while the original does (template never shows), unless CPEL is active
	 * - Both original and translation use "Entire Site" without CPEL (conflict / wrong header risk)
	 * - Condition rules point at pages/posts in the wrong language (only when CPEL is already active)
	 *
	 * @param DUPCAP_Elementor_Document $source      Default-language document.
	 * @param DUPCAP_Elementor_Document $translation Translated document.
	 * @param string                    $lang        Target language slug.
	 * @return void
	 */
	protected function check_display_conditions( DUPCAP_Elementor_Document $source, DUPCAP_Elementor_Document $translation, $lang ) {
		if ( ! $source->uses_theme_builder_conditions() ) {
			return;
		}

		$source_conditions = $source->get_conditions();
		$trans_conditions  = $translation->get_conditions();
		$cpel_active       = $this->is_cpel_managing_conditions();
		$lang_label        = DUPCAP_Elementor_Fields::language_label( $lang );
		$type_label        = $source->type_label();

		$sync_link = $cpel_active ? null : $this->get_template_sync_action_link();

		// 1. Empty conditions on translation while original is assigned.
		if ( ! $cpel_active && ! empty( $source_conditions ) && empty( $trans_conditions ) ) {
			$this->stats['condition_issues']++;
			$finding = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::CRITICAL,
				'missing_display_conditions',
				sprintf(
					/* translators: 1: template title 2: language */
					__( '%2$s display conditions missing for "%1$s"', 'duplicate-content-addon-for-polylang' ),
					$source->title,
					strtoupper( $lang )
				)
			) )
				->with_template( $translation->id, $translation->title, $translation->document_type )
				->with_language( $lang )
				->with_fix_text(
					sprintf(
						/* translators: 1: document type (Header, Footer, etc.) 2: language name */
						__( 'This %1$s has a %2$s translation, but that translation has no Elementor display conditions. Visitors in %2$s may still see the default-language template.', 'duplicate-content-addon-for-polylang' ),
						$type_label,
						$lang_label
					),
					__( 'Install Language Switcher for Polylang and link this translation to the original so it follows the language — or open the translated template in Theme Builder and set display conditions for that language.', 'duplicate-content-addon-for-polylang' )
				);

			if ( $sync_link ) {
				$finding->with_fix_link( $sync_link['url'], $sync_link['label'] );
			} else {
				$finding->with_fix_link( $translation->edit_url(), __( 'Set display conditions', 'duplicate-content-addon-for-polylang' ) );
			}

			$this->findings[] = $finding;
		}

		// 2. Both templates claim Entire Site without CPEL language routing.
		if (
			! $cpel_active
			&& $source->has_entire_site_condition( $source_conditions )
			&& $translation->has_entire_site_condition( $trans_conditions )
		) {
			$this->stats['condition_issues']++;
			$finding = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::MAJOR,
				'conflicting_display_conditions',
				sprintf(
					/* translators: 1: template title 2: language */
					__( 'Conflicting Entire Site conditions on "%1$s" (%2$s)', 'duplicate-content-addon-for-polylang' ),
					$source->title,
					strtoupper( $lang )
				)
			) )
				->with_template( $translation->id, $translation->title, $translation->document_type )
				->with_language( $lang )
				->with_fix_text(
					sprintf(
						/* translators: 1: document type 2: language name */
						__( 'Both the original and %2$s %1$s use “Entire Site” display conditions. With nothing routing templates by language, Elementor may load the wrong template on translated pages.', 'duplicate-content-addon-for-polylang' ),
						strtolower( $type_label ),
						$lang_label
					),
					__( 'Install Language Switcher for Polylang and link the templates so the correct one is picked per language, or narrow each template’s display conditions so only one applies per language context.', 'duplicate-content-addon-for-polylang' )
				);

			if ( $sync_link ) {
				$finding->with_fix_link( $sync_link['url'], $sync_link['label'] );
			} else {
				$finding->with_fix_link( $translation->edit_url(), __( 'Review conditions', 'duplicate-content-addon-for-polylang' ) );
			}

			$this->findings[] = $finding;
		}

		// 3. Condition object IDs pointing at the wrong language.
		// Only without Connect Polylang for Elementor. With CPEL installed the
		// conditions on a translation are hidden from the Elementor panel and
		// CPEL routes templates by language itself, so the ids copied during
		// duplication are dead data the user cannot even see, let alone clear.
		if ( ! $cpel_active ) {
			$wrong_ids = array();
			foreach ( $translation->condition_object_ids( $trans_conditions ) as $object_id ) {
				if ( ! function_exists( 'pll_get_post_language' ) ) {
					break;
				}
				$object_lang = (string) pll_get_post_language( $object_id, 'slug' );
				if ( '' !== $object_lang && $object_lang !== $lang ) {
					$wrong_ids[] = $object_id;
				}
			}

			if ( ! empty( $wrong_ids ) ) {
				$this->stats['condition_issues']++;
				$examples = array();
				foreach ( array_slice( $wrong_ids, 0, 3 ) as $object_id ) {
					$title = get_the_title( $object_id );
					$examples[] = $title ? $title : ( '#' . $object_id );
				}

				$this->findings[] = ( new DUPCAP_Elementor_Finding(
					DUPCAP_Elementor_Finding::CRITICAL,
					'wrong_language_condition',
					sprintf(
						/* translators: 1: template title 2: language */
						__( 'Display conditions for "%1$s" point to the wrong language (%2$s)', 'duplicate-content-addon-for-polylang' ),
						$source->title,
						strtoupper( $lang )
					)
				) )
					->with_template( $translation->id, $translation->title, $translation->document_type )
					->with_language( $lang )
					->with_fix_text(
						sprintf(
							/* translators: 1: language name 2: comma-separated page titles */
							__( 'This template was duplicated from the original, so its display conditions were copied too and still point at content in another language (for example: %2$s). Elementor decides where a template appears from these conditions, so the %1$s version may show on the wrong pages, or never show at all.', 'duplicate-content-addon-for-polylang' ),
							$lang_label,
							implode( ', ', $examples )
						),
						__( 'Open Elementor → Theme Builder, edit this template’s display conditions, and pick the pages or posts that belong to this language. Then press Rescan issue so the report catches up.', 'duplicate-content-addon-for-polylang' )
					)
					->with_fix_link( $translation->edit_url(), __( 'Fix display conditions', 'duplicate-content-addon-for-polylang' ) );
			}
		}
	}

	/**
	 * Track statistics by document type.
	 *
	 * @param DUPCAP_Elementor_Document $doc Document.
	 * @return void
	 */
	protected function track_document_type( DUPCAP_Elementor_Document $doc ) {
		$type = $doc->document_type;
		if ( ! isset( $this->stats['by_type'][ $type ] ) ) {
			$this->stats['by_type'][ $type ] = 0;
		}
		$this->stats['by_type'][ $type ]++;

		switch ( $doc->document_type ) {
			case 'header':
				$this->stats['headers']++;
				break;
			case 'footer':
				$this->stats['footers']++;
				break;
			case 'archive':
				$this->stats['archives']++;
				break;
			case 'single':
				$this->stats['singles']++;
				break;
			case 'popup':
				$this->stats['popups']++;
				break;
			case 'loop-item':
				$this->stats['loops']++;
				break;
			case 'wp-page':
				$this->stats['pages']++;
				break;
			case 'wp-post':
				$this->stats['posts']++;
				break;
			default:
				$this->stats['templates']++;
		}

		$this->stats['widgets'] += $doc->widget_count();
	}

	/**
	 * JSON and structural validation.
	 *
	 * @param DUPCAP_Elementor_Document $doc Document.
	 * @return void
	 */
	protected function check_json_and_structure( DUPCAP_Elementor_Document $doc ) {
		if ( ! $doc->is_json_valid() ) {
			$this->stats['invalid_json']++;
			$this->findings[] = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::CRITICAL,
				'invalid_json',
				sprintf(
					/* translators: %s: template title */
					__( 'Corrupted Elementor JSON in "%s"', 'duplicate-content-addon-for-polylang' ),
					$doc->title
				)
			) )
				->with_template( $doc->id, $doc->title, $doc->document_type )
				->with_language( $doc->language )
				->with_fix_text(
					$doc->json_error(),
					__( 'Open the document in Elementor and re-save it. If the error persists, restore from a revision.', 'duplicate-content-addon-for-polylang' )
				)
				->with_fix_link( $doc->edit_url(), __( 'Edit in Elementor', 'duplicate-content-addon-for-polylang' ) );
			return;
		}

		$issues = $doc->validate_structure();
		foreach ( $issues as $issue ) {
			$this->findings[] = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::MAJOR,
				$issue['code'],
				sprintf(
					/* translators: 1: template title 2: issue message */
					__( 'Invalid Elementor structure in "%1$s": %2$s', 'duplicate-content-addon-for-polylang' ),
					$doc->title,
					$issue['message']
				)
			) )
				->with_template( $doc->id, $doc->title, $doc->document_type )
				->with_language( $doc->language )
				->with_fix_text(
					$issue['message'],
					__( 'Re-save the document in Elementor or restore a working revision.', 'duplicate-content-addon-for-polylang' )
				)
				->with_fix_link( $doc->edit_url(), __( 'Edit in Elementor', 'duplicate-content-addon-for-polylang' ) );
		}
	}

	/**
	 * Translation health checks.
	 *
	 * @param DUPCAP_Elementor_Document $source      Source document.
	 * @param DUPCAP_Elementor_Document $translation Translation document.
	 * @param string                  $lang        Language slug.
	 * @return void
	 */
	protected function check_translation_health( DUPCAP_Elementor_Document $source, DUPCAP_Elementor_Document $translation, $lang ) {
		if ( ! $translation->is_json_valid() ) {
			$this->stats['invalid_json']++;
			$this->findings[] = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::CRITICAL,
				'invalid_json_translation',
				sprintf(
					/* translators: 1: template title 2: language */
					__( 'Corrupted Elementor JSON in %2$s translation of "%1$s"', 'duplicate-content-addon-for-polylang' ),
					$source->title,
					strtoupper( $lang )
				)
			) )
				->with_template( $translation->id, $translation->title, $translation->document_type )
				->with_language( $lang )
				->with_fix_text(
					$translation->json_error(),
					__( 'Open the translated document in Elementor and re-save it.', 'duplicate-content-addon-for-polylang' )
				)
				->with_fix_link( $translation->edit_url(), __( 'Edit translation', 'duplicate-content-addon-for-polylang' ) );
			return;
		}

		if ( $source->has_widgets() && ! $translation->has_widgets() ) {
			$this->stats['empty_templates']++;
			$this->findings[] = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::MAJOR,
				'empty_translation',
				sprintf(
					/* translators: 1: template title 2: language */
					__( 'Empty %2$s translation for "%1$s"', 'duplicate-content-addon-for-polylang' ),
					$source->title,
					strtoupper( $lang )
				)
			) )
				->with_template( $translation->id, $translation->title, $translation->document_type )
				->with_language( $lang )
				->with_fix_text(
					__( 'This translation has no Elementor widgets. The original page has widgets in its layout, but this language version is blank — visitors may see an empty page.', 'duplicate-content-addon-for-polylang' ),
					__( 'Open this page in Elementor, copy the layout from the original language, then translate the text.', 'duplicate-content-addon-for-polylang' )
				)
				->with_fix_link( $translation->edit_url(), __( 'Edit translation', 'duplicate-content-addon-for-polylang' ) );
		}

		$source_modified = $source->modified_time();
		$trans_modified  = $translation->modified_time();
		if ( $source_modified > $trans_modified ) {
			$days = (int) floor( ( time() - $trans_modified ) / DAY_IN_SECONDS );
			$this->stats['outdated_templates']++;
			$this->findings[] = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::WARNING,
				'outdated_translation',
				sprintf(
					/* translators: 1: template title 2: language */
					__( 'Outdated %2$s translation for "%1$s"', 'duplicate-content-addon-for-polylang' ),
					$source->title,
					strtoupper( $lang )
				)
			) )
				->with_template( $translation->id, $translation->title, $translation->document_type )
				->with_language( $lang )
				->with_fix_text(
					sprintf(
						/* translators: %d: number of days */
						_n(
							'The original was updated after this translation (translation last saved %d day ago).',
							'The original was updated after this translation (translation last saved %d days ago).',
							max( 1, $days ),
							'duplicate-content-addon-for-polylang'
						),
						max( 1, $days )
					),
					__( 'Review the original changes and update the translated Elementor content.', 'duplicate-content-addon-for-polylang' )
				)
				->with_fix_link( $translation->edit_url(), __( 'Update translation', 'duplicate-content-addon-for-polylang' ) );
		}
	}

	/**
	 * Compare source and translation widget trees.
	 *
	 * @param DUPCAP_Elementor_Document $source      Source.
	 * @param DUPCAP_Elementor_Document $translation Translation.
	 * @param string                  $lang        Language.
	 * @return void
	 */
	protected function diff_documents( DUPCAP_Elementor_Document $source, DUPCAP_Elementor_Document $translation, $lang ) {
		$source_map      = $source->get_element_map();
		$translation_map = $translation->get_element_map();

		foreach ( $source_map as $element_id => $source_element ) {
			if ( empty( $source_element['widget_type'] ) ) {
				continue;
			}

			if ( ! isset( $translation_map[ $element_id ] ) ) {
				// missing_widget check is intentionally disabled.
				$this->stats['missing_widgets']++;
				continue;
			}

			$trans_element = $translation_map[ $element_id ];
			$widget_type   = ! empty( $trans_element['widget_type'] ) ? $trans_element['widget_type'] : $source_element['widget_type'];

			if ( DUPCAP_Elementor_Fields::is_loop_widget( $widget_type ) ) {
				$this->maybe_report_loop_template_issues(
					$source,
					$translation,
					$lang,
					$widget_type,
					$element_id,
					$source_element,
					$trans_element
				);
			}

			foreach ( $source_element['repeaters'] as $repeater_key => $source_count ) {
				$trans_count = isset( $trans_element['repeaters'][ $repeater_key ] )
					? (int) $trans_element['repeaters'][ $repeater_key ]
					: 0;

				if ( $source_count !== $trans_count ) {
					$this->stats['missing_fields']++;
					$this->findings[] = ( new DUPCAP_Elementor_Finding(
						DUPCAP_Elementor_Finding::MAJOR,
						'repeater_count_mismatch',
						sprintf(
							/* translators: 1: widget type 2: template title 3: language */
							__( 'Repeater row mismatch in %1$s (%3$s) for "%2$s"', 'duplicate-content-addon-for-polylang' ),
							$source_element['widget_type'],
							$source->title,
							strtoupper( $lang )
						)
					) )
						->with_template( $translation->id, $translation->title, $translation->document_type )
						->with_language( $lang )
						->with_widget( $source_element['widget_type'], $element_id )
						->with_field( $repeater_key, (string) $source_count, (string) $trans_count )
						->with_fix_text(
							sprintf(
								/* translators: 1: repeater key 2: source count 3: translation count */
								__( 'Repeater "%1$s" has %2$d rows in the original but %3$d in the translation.', 'duplicate-content-addon-for-polylang' ),
								$repeater_key,
								$source_count,
								$trans_count
							),
							__( 'Match the repeater row count in the translation or re-copy the widget from the original.', 'duplicate-content-addon-for-polylang' )
						)
						->with_fix_link( $translation->edit_url(), __( 'Edit translation', 'duplicate-content-addon-for-polylang' ) );
				}
			}

			foreach ( $source_element['fields'] as $field_key => $original_value ) {
				if ( DUPCAP_Elementor_Fields::is_loop_template_field( $field_key ) ) {
					continue;
				}

				$translated_value = isset( $trans_element['fields'][ $field_key ] )
					? $trans_element['fields'][ $field_key ]
					: '';
				$field_base  = preg_replace( '/^.*\./', '', $field_key );
				$field_label = DUPCAP_Elementor_Fields::field_label( $field_base );
				$is_link     = DUPCAP_Elementor_Fields::is_link_field( $field_key );

				if ( $is_link ) {
					$context = DUPCAP_Elementor_Fields::context_label_from_fields( $trans_element['fields'], $field_key );
					$this->maybe_report_link_issue(
						$source,
						$translation,
						$lang,
						$widget_type,
						$element_id,
						$field_key,
						$field_label,
						$original_value,
						$translated_value,
						$context,
						isset( $trans_element['nest_type'] ) ? $trans_element['nest_type'] : '',
						isset( $trans_element['nest_label'] ) ? $trans_element['nest_label'] : ''
					);
					continue;
				}

				if ( ! DUPCAP_Elementor_Fields::is_meaningful_text( $translated_value ) ) {
					$this->stats['missing_fields']++;

					$this->findings[] = ( new DUPCAP_Elementor_Finding(
						DUPCAP_Elementor_Finding::MAJOR,
						'missing_field',
						sprintf(
							/* translators: 1: field label 2: widget type 3: template title 4: language */
							__( 'Empty translated %1$s in %2$s (%4$s) for "%3$s"', 'duplicate-content-addon-for-polylang' ),
							$field_label,
							$source_element['widget_type'],
							$source->title,
							strtoupper( $lang )
						)
					) )
						->with_template( $translation->id, $translation->title, $translation->document_type )
						->with_language( $lang )
						->with_widget( $source_element['widget_type'], $element_id )
						->with_field(
							$field_key,
							DUPCAP_Elementor_Fields::snippet( $original_value ),
							DUPCAP_Elementor_Fields::snippet( $translated_value )
						)
						->with_fix_text(
							sprintf(
								/* translators: 1: field label 2: original snippet */
								__( 'The original contains "%2$s" but the translated field "%1$s" is empty.', 'duplicate-content-addon-for-polylang' ),
								$field_label,
								DUPCAP_Elementor_Fields::snippet( $original_value, 80 )
							),
							__( 'Open the translation in Elementor and fill in the missing text.', 'duplicate-content-addon-for-polylang' )
						)
						->with_fix_link( $translation->edit_url(), __( 'Edit translation', 'duplicate-content-addon-for-polylang' ) );
				} elseif (
					$translated_value === $original_value
					&& ! DUPCAP_Elementor_Fields::should_skip_same_text_warning( $field_key, $original_value )
				) {
					$context = DUPCAP_Elementor_Fields::context_label_from_fields( $trans_element['fields'], $field_key );
					$finding = ( new DUPCAP_Elementor_Finding(
						DUPCAP_Elementor_Finding::WARNING,
						'untranslated_field',
						sprintf(
							/* translators: 1: field label 2: widget type 3: template title 4: language */
							__( 'Same original text left in %1$s (%2$s) on "%3$s" (%4$s)', 'duplicate-content-addon-for-polylang' ),
							$field_label,
							$source_element['widget_type'],
							$source->title,
							strtoupper( $lang )
						)
					) )
						->with_template( $translation->id, $translation->title, $translation->document_type )
						->with_language( $lang )
						->with_widget( $source_element['widget_type'], $element_id )
						->with_field(
							$field_key,
							DUPCAP_Elementor_Fields::snippet( $original_value ),
							DUPCAP_Elementor_Fields::snippet( $translated_value )
						)
						->with_fix_text(
							__( 'This field was copied from the original language and was not changed.', 'duplicate-content-addon-for-polylang' ),
							__( 'Translate this text in Elementor, or ignore it if a brand/product name should stay the same.', 'duplicate-content-addon-for-polylang' )
						)
						->with_fix_link( $translation->edit_url(), __( 'Translate in Elementor', 'duplicate-content-addon-for-polylang' ) );

					if ( '' !== $context ) {
						$finding->with_context( $context );
					}

					$this->findings[] = $finding;
				}
			}
		}
	}

	/**
	 * Compare Loop Grid / Loop Carousel template references across languages.
	 *
	 * @param DUPCAP_Elementor_Document $source         Source document.
	 * @param DUPCAP_Elementor_Document $translation    Translation document.
	 * @param string                    $lang           Target language slug.
	 * @param string                    $widget_type    Widget type.
	 * @param string                    $element_id     Element ID.
	 * @param array<string, mixed>      $source_element Source element map row.
	 * @param array<string, mixed>      $trans_element  Translation element map row.
	 * @return void
	 */
	protected function maybe_report_loop_template_issues(
		DUPCAP_Elementor_Document $source,
		DUPCAP_Elementor_Document $translation,
		$lang,
		$widget_type,
		$element_id,
		array $source_element,
		array $trans_element
	) {
		$source_refs = isset( $source_element['loop_templates'] ) && is_array( $source_element['loop_templates'] )
			? $source_element['loop_templates']
			: array();
		$trans_refs  = isset( $trans_element['loop_templates'] ) && is_array( $trans_element['loop_templates'] )
			? $trans_element['loop_templates']
			: array();

		if ( empty( $source_refs ) ) {
			return;
		}

		$widget_label = DUPCAP_Elementor_Fields::loop_widget_label( $widget_type );

		foreach ( $source_refs as $field_key => $source_template_id ) {
			$source_template_id = (int) $source_template_id;
			if ( $source_template_id <= 0 ) {
				continue;
			}

			$expected_id = $this->expected_loop_template_id( $source_template_id, $lang );
			$actual_id   = isset( $trans_refs[ $field_key ] ) ? (int) $trans_refs[ $field_key ] : 0;
			$loop_title  =DUPCAP_Elementor_Document::display_title_for( $source_template_id );
			$lang_label  = DUPCAP_Elementor_Fields::language_label( $lang );

			if ( $expected_id <= 0 ) {
				$this->stats['missing_fields']++;
				$this->findings[] = ( new DUPCAP_Elementor_Finding(
					DUPCAP_Elementor_Finding::MAJOR,
					'missing_loop_item_translation',
					sprintf(
						/* translators: 1: loop template title 2: language 3: page title */
						__( 'Loop template "%1$s" has no %2$s translation (used on "%3$s")', 'duplicate-content-addon-for-polylang' ),
						$loop_title,
						strtoupper( $lang ),
						$source->title
					)
				) )
					->with_template( $translation->id, $translation->title, $translation->document_type )
					->with_language( $lang )
					->with_widget( $widget_type, $element_id )
					->with_field( $field_key, (string) $source_template_id, '' )
					->with_fix_text(
						DUPCAP_Elementor_Fields::loop_template_issue_summary(
							'missing_loop_item_translation',
							$lang_label,
							$widget_label,
							'',
							'',
							$loop_title
						),
						__( 'Translate the Loop Item in Polylang, then select it in Loop Grid → Template.', 'duplicate-content-addon-for-polylang' )
					)
					->with_fix_link( get_edit_post_link( $source_template_id, 'raw' ) ?: $translation->edit_url(), __( 'Translate loop template', 'duplicate-content-addon-for-polylang' ) );
				continue;
			}

			if ( $actual_id === $expected_id ) {
				continue;
			}

			$this->stats['missing_fields']++;

			if ( $actual_id <= 0 ) {
				$code = 'missing_loop_template';
				$what = sprintf(
					/* translators: 1: widget label 2: language 3: page title */
					__( '%1$s on "%3$s" has no %2$s loop template selected', 'duplicate-content-addon-for-polylang' ),
					$widget_label,
					strtoupper( $lang ),
					$source->title
				);
				$why  = DUPCAP_Elementor_Fields::loop_template_issue_summary(
					'missing_loop_template',
					$lang_label,
					$widget_label,
					$loop_title,
					'',
					''
				);
				$fix  = __( 'Loop Grid → Template → select the translated Loop Item.', 'duplicate-content-addon-for-polylang' );
			} else {
				$code = 'wrong_loop_template';
				$what = sprintf(
					/* translators: 1: widget label 2: language 3: page title */
					__( '%1$s on "%3$s" (%2$s) points to the wrong loop template', 'duplicate-content-addon-for-polylang' ),
					$widget_label,
					strtoupper( $lang ),
					$source->title
				);
				$expected_title = DUPCAP_Elementor_Document::display_title_for( $expected_id );
				$actual_title   = DUPCAP_Elementor_Document::display_title_for( $actual_id );
				$why            = DUPCAP_Elementor_Fields::loop_template_issue_summary(
					'wrong_loop_template',
					$lang_label,
					$widget_label,
					$expected_title,
					$actual_title,
					$loop_title
				);
				$fix = __( 'Loop Grid → Template → select this language\'s Loop Item.', 'duplicate-content-addon-for-polylang' );
			}

			$this->findings[] = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::MAJOR,
				$code,
				$what
			) )
				->with_template( $translation->id, $translation->title, $translation->document_type )
				->with_language( $lang )
				->with_widget( $widget_type, $element_id )
				->with_field( $field_key, (string) $source_template_id, (string) $actual_id )
				->with_fix_text( $why, $fix )
				->with_fix_link( $translation->edit_url(), __( 'Edit translation', 'duplicate-content-addon-for-polylang' ) );
		}
	}

	/**
	 * Resolve the translated Loop Item template ID for a language.
	 *
	 * @param int    $source_template_id Default-language loop template post ID.
	 * @param string $lang               Target language slug.
	 * @return int Translated template ID, or 0 when missing.
	 */
	protected function expected_loop_template_id( $source_template_id, $lang ) {
		$source_template_id = (int) $source_template_id;
		$lang               = sanitize_key( (string) $lang );

		if ( $source_template_id <= 0 || '' === $lang ) {
			return 0;
		}

		if ( function_exists( 'pll_get_post' ) ) {
			$translated = (int) pll_get_post( $source_template_id, $lang );
			if ( $translated > 0 ) {
				return $translated;
			}
		}

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $source_template_id );
			if ( is_array( $translations ) && ! empty( $translations[ $lang ] ) ) {
				return (int) $translations[ $lang ];
			}
		}

		return 0;
	}

	/**
	 * Report wrong-language / untranslated Elementor links (navbar/footer issue).
	 *
	 * @param DUPCAP_Elementor_Document $source          Source document.
	 * @param DUPCAP_Elementor_Document $translation     Translation document.
	 * @param string                  $lang            Target language slug.
	 * @param string                  $widget_type     Widget type.
	 * @param string                  $element_id      Element ID.
	 * @param string                  $field_key       Field key.
	 * @param string                  $field_label     Field label.
	 * @param string                  $original_value  Source URL.
	 * @param string                  $translated_value Translation URL.
	 * @param string                  $context_label   Nearby label (menu item text, etc.).
	 * @param string                  $nest_type       Parent nest widget type.
	 * @param string                  $nest_label      Parent nest label (menu item).
	 * @return void
	 */
	protected function maybe_report_link_issue(
		DUPCAP_Elementor_Document $source,
		DUPCAP_Elementor_Document $translation,
		$lang,
		$widget_type,
		$element_id,
		$field_key,
		$field_label,
		$original_value,
		$translated_value,
		$context_label = '',
		$nest_type = '',
		$nest_label = ''
	) {
		$original_value   = trim( (string) $original_value );
		$translated_value = trim( (string) $translated_value );

		if ( '' === $original_value ) {
			return;
		}

		// Image/media asset URLs are not page navigation links.
		if ( DUPCAP_Elementor_Fields::is_media_asset_url( $original_value )
			|| DUPCAP_Elementor_Fields::is_media_asset_url( $translated_value ) ) {
			return;
		}

		if ( '' === $translated_value ) {
			$this->stats['missing_fields']++;
			$this->findings[] = ( new DUPCAP_Elementor_Finding(
				DUPCAP_Elementor_Finding::MAJOR,
				'missing_field',
				sprintf(
					/* translators: 1: field label 2: widget type 3: template title 4: language */
					__( 'Empty translated %1$s in %2$s (%4$s) for "%3$s"', 'duplicate-content-addon-for-polylang' ),
					$field_label,
					$widget_type,
					$source->title,
					strtoupper( $lang )
				)
			) )
				->with_template( $translation->id, $translation->title, $translation->document_type )
				->with_language( $lang )
				->with_widget( $widget_type, $element_id )
				->with_field( $field_key, DUPCAP_Elementor_Fields::snippet( $original_value ), '' )
				->with_fix_text(
					__( 'The translated link is empty while the original points to a URL.', 'duplicate-content-addon-for-polylang' ),
					__( 'Set the link to the translated page/URL for this language.', 'duplicate-content-addon-for-polylang' )
				)
				->with_fix_link( $translation->edit_url(), __( 'Edit translation', 'duplicate-content-addon-for-polylang' ) );
			return;
		}

		/*
		 * Valid when the link already opens content in the expected language,
		 * even if the path omits a language directory (/precio/ vs /es/precio/).
		 */
		if ( $this->link_targets_expected_language( $translated_value, $lang ) ) {
			return;
		}

		$same_link  = DUPCAP_Elementor_Fields::normalize_url_for_compare( $original_value )
			=== DUPCAP_Elementor_Fields::normalize_url_for_compare( $translated_value );
		$wrong_lang = $this->link_targets_wrong_language( $translated_value, $lang );

		if ( ! $same_link && ! $wrong_lang ) {
			return;
		}

		if ( ! DUPCAP_Elementor_Fields::is_internal_url( $translated_value ) && ! $wrong_lang ) {
			return;
		}

		// Prefer mapping from the original URL so /pricing/ → /precio/.
		$suggested_url = $this->suggested_translation_url( $original_value, $lang );
		if ( '' === $suggested_url ) {
			$suggested_url = $this->suggested_translation_url( $translated_value, $lang );
		}

		// Bare language roots are only valid suggestions for homepage links.
		$current_is_front = $this->is_front_path( $this->url_path( $translated_value ) )
			|| $this->is_front_path( $this->url_path( $original_value ) );
		if ( '' !== $suggested_url
			&& ! $current_is_front
			&& DUPCAP_Elementor_Fields::is_bare_language_home_path( $suggested_url, $lang ) ) {
			$suggested_url = '';
		}

		$fragment = DUPCAP_Elementor_Fields::url_fragment( $translated_value );
		if ( '' === $fragment ) {
			$fragment = DUPCAP_Elementor_Fields::url_fragment( $original_value );
		}
		if ( '' !== $suggested_url && '' !== $fragment ) {
			$suggested_url = DUPCAP_Elementor_Fields::with_fragment( $suggested_url, $fragment );
		}

		// Current and suggested already open the same page → not an issue.
		if ( '' !== $suggested_url && $this->urls_resolve_to_same_post( $translated_value, $suggested_url ) ) {
			return;
		}

		$lang_name = DUPCAP_Elementor_Fields::language_label( $lang );
		$current   = DUPCAP_Elementor_Fields::readable_url( $translated_value );

		if ( '' === $suggested_url ) {
			$target_id = $this->resolve_url_to_post_id( $original_value );
			if ( $target_id <= 0 ) {
				$target_id = $this->resolve_url_to_post_id( $translated_value );
			}

			// Linked page exists and already has a published translation — use that URL.
			if ( $this->is_navigable_post_id( $target_id ) && function_exists( 'pll_get_post' ) ) {
				$translated_target = (int) pll_get_post( $target_id, $lang );
				if ( $translated_target > 0 && $translated_target !== $target_id && $this->is_navigable_post_id( $translated_target ) ) {
					$translated_post = get_post( $translated_target );
					if ( $translated_post instanceof WP_Post && 'publish' === $translated_post->post_status ) {
						$permalink = get_permalink( $translated_target );
						if ( is_string( $permalink ) && '' !== $permalink ) {
							$suggested_url = DUPCAP_Elementor_Fields::relative_path( $permalink, false );
							if ( '' !== $fragment ) {
								$suggested_url = DUPCAP_Elementor_Fields::with_fragment( $suggested_url, $fragment );
							}
						}
					}
				}
			}
		}

		// No published translation to link to — skip; missing pages are covered by dead-link scan.
		if ( '' === $suggested_url ) {
			return;
		}

		$suggested_label = DUPCAP_Elementor_Fields::readable_url( $suggested_url, $lang );
		if ( '' === $suggested_label ) {
			$suggested_label = $suggested_url;
		}
		$why = DUPCAP_Elementor_Fields::format_link_issue_detail(
			$current ? $current : '/',
			$lang_name,
			$suggested_label,
			$fragment
		);
		$fix       = __( 'Open this template in Elementor and update the Link field.', 'duplicate-content-addon-for-polylang' );
		$fix_url   = $translation->edit_url();
		$fix_label = __( 'Fix link in Elementor', 'duplicate-content-addon-for-polylang' );

		$code = $wrong_lang ? 'wrong_language_link' : 'untranslated_link';
		$this->stats['missing_fields']++;

		$finding = ( new DUPCAP_Elementor_Finding(
			DUPCAP_Elementor_Finding::MAJOR,
			$code,
			sprintf(
				/* translators: 1: widget type 2: template title 3: language */
				__( 'Menu/button link still uses another language URL in %1$s (%3$s) for "%2$s"', 'duplicate-content-addon-for-polylang' ),
				$widget_type,
				$source->title,
				strtoupper( $lang )
			)
		) )
			->with_template( $translation->id, $translation->title, $translation->document_type )
			->with_language( $lang )
			->with_widget( $widget_type, $element_id )
			->with_field( $field_key, $original_value, $translated_value )
			->with_fix_text( $why, $fix )
			->with_fix_link( $fix_url, $fix_label );

		if ( '' !== $context_label ) {
			$finding->with_context( $context_label );
		}
		if ( '' !== $suggested_url ) {
			$finding->with_suggested_url( $suggested_url );
		}
		if ( '' !== $nest_type ) {
			$finding->with_nest( $nest_type, $nest_label );
		}

		$this->findings[] = $finding;
	}

	/**
	 * Flag internal links in a document that resolve to no published page (would 404).
	 *
	 * Covers Elementor link fields and WordPress menus used by Nav Menu widgets.
	 *
	 * @param DUPCAP_Elementor_Document $doc Document.
	 * @return void
	 */
	protected function scan_document_dead_links( DUPCAP_Elementor_Document $doc ) {
		if ( ! $doc->is_json_valid() ) {
			return;
		}

		$seen = array();

		foreach ( $doc->get_element_map() as $element ) {
			if ( empty( $element['widget_type'] ) || empty( $element['fields'] ) || ! is_array( $element['fields'] ) ) {
				continue;
			}

			foreach ( $element['fields'] as $field_key => $url ) {
				if ( ! DUPCAP_Elementor_Fields::is_link_field( (string) $field_key ) ) {
					continue;
				}

				$context = DUPCAP_Elementor_Fields::context_label_from_fields( $element['fields'], (string) $field_key );
				$this->maybe_report_dead_link(
					$doc,
					isset( $element['widget_type'] ) ? (string) $element['widget_type'] : '',
					isset( $element['id'] ) ? (string) $element['id'] : '',
					(string) $field_key,
					(string) $url,
					$context,
					isset( $element['nest_type'] ) ? (string) $element['nest_type'] : '',
					isset( $element['nest_label'] ) ? (string) $element['nest_label'] : '',
					$seen
				);
			}
		}

		$this->walk_nav_menu_widgets_for_dead_links( $doc->get_decoded_data(), $doc, $seen );
	}

	/**
	 * Walk Elementor tree for Nav Menu widgets and check WP menu item URLs.
	 *
	 * @param array                     $elements Element nodes.
	 * @param DUPCAP_Elementor_Document $doc      Document.
	 * @param array<string, bool>       $seen     Dedupe map (by reference).
	 * @return void
	 */
	protected function walk_nav_menu_widgets_for_dead_links( array $elements, DUPCAP_Elementor_Document $doc, array &$seen ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$widget_type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
			$settings    = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$element_id  = isset( $element['id'] ) ? (string) $element['id'] : '';

			if ( $this->is_nav_menu_widget_type( $widget_type ) ) {
				$menu_id = $this->nav_menu_id_from_settings( $settings );
				if ( $menu_id > 0 ) {
					$this->report_dead_links_from_wp_menu( $doc, $widget_type, $element_id, $menu_id, $seen );
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_nav_menu_widgets_for_dead_links( $element['elements'], $doc, $seen );
			}
		}
	}

	/**
	 * Whether a widget type is Elementor's Nav Menu (WordPress menu) widget.
	 *
	 * @param string $widget_type Widget type.
	 * @return bool
	 */
	protected function is_nav_menu_widget_type( $widget_type ) {
		$widget_type = strtolower( (string) $widget_type );
		return in_array( $widget_type, array( 'nav-menu', 'nav_menu' ), true )
			|| false !== strpos( $widget_type, 'nav-menu' );
	}

	/**
	 * Read the WordPress menu term ID from Elementor Nav Menu settings.
	 *
	 * @param array $settings Widget settings.
	 * @return int
	 */
	protected function nav_menu_id_from_settings( array $settings ) {
		foreach ( array( 'nav_menu', 'menu', 'menu_id' ) as $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}
			$value = $settings[ $key ];
			if ( is_array( $value ) ) {
				if ( isset( $value['id'] ) ) {
					$value = $value['id'];
				} elseif ( isset( $value['value'] ) ) {
					$value = $value['value'];
				} else {
					continue;
				}
			}
			$id = (int) $value;
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Report dead links found in a WordPress nav menu.
	 *
	 * @param DUPCAP_Elementor_Document $doc         Document.
	 * @param string                    $widget_type Widget type.
	 * @param string                    $element_id  Element ID.
	 * @param int                       $menu_id     Menu term ID.
	 * @param array<string, bool>       $seen        Dedupemap.
	 * @return void
	 */
	protected function report_dead_links_from_wp_menu( DUPCAP_Elementor_Document $doc, $widget_type, $element_id, $menu_id, array &$seen ) {
		$items = wp_get_nav_menu_items( $menu_id );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $index => $item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}

			$label = isset( $item->title ) ? trim( (string) $item->title ) : '';
			$url   = '';

			$type = isset( $item->type ) ? (string) $item->type : '';
			if ( 'post_type' === $type && ! empty( $item->object_id ) ) {
				$target = get_post( (int) $item->object_id );
				if ( $target instanceof WP_Post && 'publish' === $target->post_status ) {
					continue;
				}
				$url = isset( $item->url ) ? (string) $item->url : '';
				if ( '' === $url && $target instanceof WP_Post ) {
					$permalink = get_permalink( $target );
					$url       = is_string( $permalink ) ? $permalink : '';
				}
			} elseif ( 'taxonomy' === $type && ! empty( $item->object_id ) ) {
				$term = get_term( (int) $item->object_id );
				if ( $term instanceof WP_Term && ! is_wp_error( $term ) ) {
					continue;
				}
				$url = isset( $item->url ) ? (string) $item->url : '';
			} elseif ( 'post_type_archive' === $type ) {
				continue;
			} else {
				$url = isset( $item->url ) ? (string) $item->url : '';
			}

			$field_key = 'nav_menu_item[' . (int) $index . '].url';
			$this->maybe_report_dead_link(
				$doc,
				$widget_type,
				$element_id,
				$field_key,
				$url,
				$label,
				'',
				'',
				$seen
			);
		}
	}

	/**
	 * Report one dead internal link when it has no published target.
	 *
	 * @param DUPCAP_Elementor_Document $doc           Document.
	 * @param string                    $widget_type   Widget type.
	 * @param string                    $element_id    Element ID.
	 * @param string                    $field_key     Field key.
	 * @param string                    $url           Link URL.
	 * @param string                    $context_label Nearby label.
	 * @param string                    $nest_type     Nest type.
	 * @param string                    $nest_label    Nest label.
	 * @param array<string, bool>       $seen          Dedupemap.
	 * @return void
	 */
	protected function maybe_report_dead_link(
		DUPCAP_Elementor_Document $doc,
		$widget_type,
		$element_id,
		$field_key,
		$url,
		$context_label,
		$nest_type,
		$nest_label,
		array &$seen
	) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return;
		}
		if ( ! DUPCAP_Elementor_Fields::is_internal_url( $url ) || DUPCAP_Elementor_Fields::is_media_asset_url( $url ) ) {
			return;
		}
		if ( DUPCAP_Elementor_Fields::url_has_published_target( $url, $this->default_lang ) ) {
			return;
		}

		$norm   = DUPCAP_Elementor_Fields::normalize_url_for_compare( $url );
		$dedupe = (string) (int) $doc->id . '|' . $norm;
		if ( isset( $seen[ $dedupe ] ) ) {
			return;
		}
		$seen[ $dedupe ] = true;

		$path    = DUPCAP_Elementor_Fields::relative_path( $url );
		$current = DUPCAP_Elementor_Fields::readable_url( $url );
		$target  = DUPCAP_Elementor_Fields::describe_dead_link_target( $url, $this->default_lang );
		$kind    = isset( $target['kind'] ) ? (string) $target['kind'] : 'missing';
		$why     = DUPCAP_Elementor_Fields::format_dead_link_detail( $current ? $current : ( $path ? $path : $url ), $kind );

		$lang = (string) $doc->language;
		$what = '' !== $context_label
			? sprintf(
				/* translators: 1: menu/button label 2: template title */
				__( '“%1$s” links to a missing page in "%2$s"', 'duplicate-content-addon-for-polylang' ),
				$context_label,
				$doc->title
			)
			: sprintf(
				/* translators: 1: widget type 2: template title */
				__( 'Menu/button link points to a missing page in %1$s ("%2$s")', 'duplicate-content-addon-for-polylang' ),
				$widget_type ? $widget_type : __( 'widget', 'duplicate-content-addon-for-polylang' ),
				$doc->title
			);

		$this->stats['missing_fields']++;

		$fix_url   = ! empty( $target['fix_url'] ) ? (string) $target['fix_url'] : admin_url( 'post-new.php?post_type=page' );
		$fix_label = ! empty( $target['fix_label'] ) ? (string) $target['fix_label'] : __( 'Create page', 'duplicate-content-addon-for-polylang' );
		$finding   = ( new DUPCAP_Elementor_Finding(
			DUPCAP_Elementor_Finding::MAJOR,
			'dead_link',
			$what
		) )
			->with_template( $doc->id, $doc->title, $doc->document_type )
			->with_language( $lang )
			->with_widget( $widget_type, $element_id )
			->with_field( $field_key, $url, $url )
			->with_fix_text(
				$why,
				DUPCAP_Elementor_Fields::dead_link_fix_text( $kind )
			)
			->with_fix_link( $fix_url, $fix_label );

		if ( '' !== $context_label ) {
			$finding->with_context( $context_label );
		}
		if ( '' !== $nest_type ) {
			$finding->with_nest( $nest_type, $nest_label );
		}

		$this->findings[] = $finding;
	}

	/**
	 * Whether a post ID is a valid page/post navigation target.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_navigable_post_id( $post_id ) {
		return DUPCAP_Elementor_Fields::is_navigable_post_id( $post_id );
	}

	/**
	 * Resolve an internal URL to a post ID.
	 *
	 * @param string $url URL or path.
	 * @return int
	 */
	protected function resolve_url_to_post_id( $url ) {
		return DUPCAP_Elementor_Fields::resolve_url_to_post_id( $url, $this->default_lang );
	}

	/**
	 * Pin Polylang to the default language for a stable scan.
	 *
	 * @return mixed Previous current language object, if any.
	 */
	protected function pin_default_language() {
		if ( ! function_exists( 'PLL' ) || '' === $this->default_lang ) {
			return null;
		}

		$pll = PLL();
		// is_callable(), not method_exists(): Polylang 3.8 forwards get_language()
		// through PLL_Model::__call(), which method_exists() cannot see.
		if ( ! $pll || ! isset( $pll->model ) || ! is_callable( array( $pll->model, 'get_language' ) ) ) {
			return null;
		}

		$previous = isset( $pll->curlang ) ? $pll->curlang : null;

		try {
			$language = $pll->model->get_language( $this->default_lang );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( $language ) {
			$pll->curlang = $language;
		}

		return $previous;
	}

	/**
	 * Restore Polylang current language after a scan.
	 *
	 * @param mixed $previous Previous language object.
	 * @return void
	 */
	protected function restore_language( $previous ) {
		if ( ! function_exists( 'PLL' ) ) {
			return;
		}

		$pll = PLL();
		if ( ! $pll ) {
			return;
		}

		if ( $previous ) {
			$pll->curlang = $previous;
			return;
		}

		// In admin/AJAX Polylang may not have curlang set. Restoring null breaks
		// locale filters and restore_previous_locale() during dashboard rendering.
		if ( '' !== $this->default_lang && isset( $pll->model ) && is_callable( array( $pll->model, 'get_language' ) ) ) {
			try {
				$language = $pll->model->get_language( $this->default_lang );
				if ( $language ) {
					$pll->curlang = $language;
				}
			} catch ( \Throwable $e ) {
				// Leave curlang as-is rather than breaking dashboard rendering.
				$language = null;
			}
		}
	}

	/**
	 * Path component of a URL or site-relative path.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	protected function url_path( $url ) {
		return DUPCAP_Elementor_Fields::url_path( $url );
	}

	/**
	 * Whether a path is the site root / default homepage.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	protected function is_front_path( $path ) {
		return DUPCAP_Elementor_Fields::is_front_path( $path );
	}

	/**
	 * Suggest a localized path for an internal URL.
	 *
	 * @param string $url  Current URL.
	 * @param string $lang Target language slug.
	 * @return string
	 */
	protected function suggested_translation_url( $url, $lang ) {
		if ( '' === $lang ) {
			return '';
		}

		$path = $this->url_path( $url );
		$is_front = $this->is_front_path( $path );

		if ( $is_front ) {
			if ( function_exists( 'pll_home_url' ) ) {
				$home = pll_home_url( $lang );
				if ( ! empty( $home ) ) {
					return DUPCAP_Elementor_Fields::relative_path( $home, false );
				}
			}
			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id > 0 && function_exists( 'pll_get_post' ) ) {
				$trans_id = (int) pll_get_post( $front_id, $lang );
				if ( $trans_id > 0 ) {
					$permalink = get_permalink( $trans_id );
					if ( is_string( $permalink ) && '' !== $permalink ) {
						return DUPCAP_Elementor_Fields::relative_path( $permalink, false );
					}
				}
			}
			return '/' . $lang . '/';
		}

		if ( ! function_exists( 'pll_get_post' ) || ! function_exists( 'pll_get_post_language' ) ) {
			return '';
		}

		$post_id = $this->resolve_url_to_post_id( $url );
		if ( $post_id <= 0 ) {
			return '';
		}

		$post_lang = pll_get_post_language( $post_id, 'slug' );
		if ( is_string( $post_lang ) && $post_lang === $lang ) {
			return '';
		}

		$translated_id = (int) pll_get_post( $post_id, $lang );
		if ( $translated_id <= 0 && function_exists( 'pll_get_post_translations' ) ) {
			$map           = pll_get_post_translations( $post_id );
			$translated_id = ( is_array( $map ) && ! empty( $map[ $lang ] ) ) ? (int) $map[ $lang ] : 0;
		}
		if ( $translated_id <= 0 || $translated_id === $post_id ) {
			return '';
		}
		if ( ! $this->is_navigable_post_id( $translated_id ) ) {
			return '';
		}

		$translated_post = get_post( $translated_id );
		if ( ! $translated_post instanceof WP_Post || 'publish' !== $translated_post->post_status ) {
			return '';
		}

		$permalink = get_permalink( $translated_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}

		return DUPCAP_Elementor_Fields::relative_path( $permalink, false );
	}

	/**
	 * Whether a URL already opens a post in the expected language.
	 *
	 * @param string $url           URL or path.
	 * @param string $expected_lang Expected language slug.
	 * @return bool
	 */
	protected function link_targets_expected_language( $url, $expected_lang ) {
		if ( ! function_exists( 'pll_get_post_language' ) || ! DUPCAP_Elementor_Fields::is_internal_url( $url ) ) {
			return false;
		}

		$post_id = $this->resolve_url_to_post_id( $url );
		if ( $post_id <= 0 ) {
			return false;
		}

		$post_lang = pll_get_post_language( $post_id, 'slug' );
		return is_string( $post_lang ) && $post_lang === $expected_lang;
	}

	/**
	 * Whether two URLs resolve to the same post/page.
	 *
	 * @param string $url_a First URL.
	 * @param string $url_b Second URL.
	 * @return bool
	 */
	protected function urls_resolve_to_same_post( $url_a, $url_b ) {
		$id_a = $this->resolve_url_to_post_id( $url_a );
		$id_b = $this->resolve_url_to_post_id( $url_b );
		return $id_a > 0 && $id_a === $id_b;
	}

	/**
	 * Whether a URL resolves to a post/page in a different language.
	 *
	 * @param string $url           URL or path.
	 * @param string $expected_lang Expected language slug.
	 * @return bool
	 */
	protected function link_targets_wrong_language( $url, $expected_lang ) {
		if ( ! function_exists( 'pll_get_post_language' ) || ! DUPCAP_Elementor_Fields::is_internal_url( $url ) ) {
			return false;
		}

		$post_id = $this->resolve_url_to_post_id( $url );
		if ( $post_id <= 0 ) {
			return false;
		}

		$post_lang = pll_get_post_language( $post_id, 'slug' );
		return is_string( $post_lang ) && '' !== $post_lang && $post_lang !== $expected_lang;
	}

	/**
	 * Get ignore key for a finding array or object.
	 *
	 * @param array|DUPCAP_Elementor_Finding $finding Finding array or instance.
	 * @return string
	 */
	public static function get_finding_ignore_key( $finding ) {
		if ( $finding instanceof DUPCAP_Elementor_Finding ) {
			$code = (string) $finding->code;
			if ( in_array( $code, self::site_level_finding_codes(), true ) && class_exists( 'DUPCAP_Ignored_Issues' ) ) {
				return DUPCAP_Ignored_Issues::elementor_key( $code, 0, (string) $finding->language );
			}
			return $finding->with_ignore_key()->ignore_key;
		}

		if ( ! is_array( $finding ) ) {
			return '';
		}

		$code = isset( $finding['code'] ) ? (string) $finding['code'] : '';
		if ( in_array( $code, self::site_level_finding_codes(), true ) && class_exists( 'DUPCAP_Ignored_Issues' ) ) {
			return DUPCAP_Ignored_Issues::elementor_key(
				$code,
				0,
				isset( $finding['language'] ) ? $finding['language'] : ''
			);
		}

		$ignore_key = isset( $finding['ignore_key'] ) ? (string) $finding['ignore_key'] : '';
		if ( '' === $ignore_key && class_exists( 'DUPCAP_Ignored_Issues' ) && '' !== $code ) {
			return DUPCAP_Ignored_Issues::elementor_key(
				$code,
				isset( $finding['template_id'] ) ? $finding['template_id'] : 0,
				isset( $finding['language'] ) ? $finding['language'] : '',
				isset( $finding['widget_id'] ) ? $finding['widget_id'] : '',
				isset( $finding['field'] ) ? $finding['field'] : '',
				isset( $finding['original_value'] ) ? $finding['original_value'] : ''
			);
		}

		return $ignore_key;
	}

	/**
	 * Whether a finding is marked as ignored.
	 *
	 * @param array|DUPCAP_Elementor_Finding $finding Finding.
	 * @return bool
	 */
	public static function is_finding_ignored( $finding ) {
		if ( ! class_exists( 'DUPCAP_Ignored_Issues' ) ) {
			return false;
		}
		$key = self::get_finding_ignore_key( $finding );
		return '' !== $key && DUPCAP_Ignored_Issues::is_ignored( $key );
	}

	/**
	 * Collapse duplicate findings that render as identical insight rows.
	 *
	 * Same template + language + widget type + field (e.g. two mega-menu widgets
	 * with the same menu_items[5].item_link issue) become one finding.
	 *
	 * @param DUPCAP_Elementor_Finding[] $findings Findings.
	 * @return DUPCAP_Elementor_Finding[]
	 */
	protected function dedupe_findings( array $findings ) {
		$seen = array();
		$out  = array();

		foreach ( $findings as $finding ) {
			if ( ! $finding instanceof DUPCAP_Elementor_Finding || self::is_finding_ignored( $finding ) ) {
				continue;
			}

			$key = implode(
				'|',
				array(
					in_array( $finding->code, array( 'untranslated_link', 'wrong_language_link', 'dead_link' ), true ) ? 'broken_link' : (string) $finding->code,
					(string) (int) $finding->template_id,
					(string) $finding->language,
					(string) $finding->widget_type,
					(string) $finding->widget_id,
					(string) $finding->field,
					md5( (string) $finding->original_value . '||' . (string) $finding->translated_value ),
				)
			);

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $finding;
		}

		return $out;
	}

	/**
	 * Build final report array.
	 *
	 * @param DUPCAP_Elementor_Finding[] $findings Findings.
	 * @return array
	 */
	protected function build_report( array $findings ) {
		$findings   = $this->dedupe_findings( $findings );
		$serialized = array();
		$counts     = array(
			'critical' => 0,
			'major'    => 0,
			'warning'  => 0,
			'info'     => 0,
		);

		foreach ( $findings as $finding ) {
			if ( ! $finding instanceof DUPCAP_Elementor_Finding ) {
				continue;
			}
			$row = $finding->to_array();
			$serialized[] = $row;
			if ( isset( $counts[ $row['severity'] ] ) ) {
				$counts[ $row['severity'] ]++;
			}
		}

		$problem_count = $counts['critical'] + $counts['major'] + $counts['warning'];
		$health_score  = $this->calculate_health_score( $counts, $problem_count );

		$languages = array_merge(
			$this->default_lang ? array( $this->default_lang ) : array(),
			$this->secondary_langs
		);

		return array(
			'time'      => time(),
			'summary'   => array(
				'templates_found'      => $this->documents_found,
				'templates_scanned'    => $this->documents_scanned,
				'widgets_scanned'      => $this->widgets_scanned,
				'languages'            => $languages,
				'problems_found'       => $problem_count,
				'health_score'         => $health_score,
				'documents_truncated'  => $this->documents_truncated,
			),
			'counts'    => $counts,
			'statistics'=> $this->stats,
			'findings'  => $serialized,
		);
	}

	/**
	 * Overall Elementor builder health 0-100 (standard readiness score).
	 *
	 * Coverage 50% · Integrity 30% · Widget fidelity 20%.
	 * Uses issue rates against scan surface so large sites do not floor at 0.
	 *
	 * @param array $counts        Severity counts.
	 * @param int   $problem_count Total problems.
	 * @return int
	 */
	protected function calculate_health_score( array $counts, $problem_count ) {
		if ( $this->documents_found <= 0 ) {
			return 100;
		}

		if ( 0 === (int) $problem_count ) {
			return 100;
		}

		$coverage  = $this->coverage_score();
		$integrity = $this->integrity_score( $counts );
		$fidelity  = $this->widget_fidelity_score();

		if ( $this->widgets_scanned <= 0 ) {
			$score = ( 0.60 * $coverage ) + ( 0.40 * $integrity );
		} else {
			$score = ( 0.50 * $coverage ) + ( 0.30 * $integrity ) + ( 0.20 * $fidelity );
		}

		$raw = (int) max( 0, min( 100, round( $score ) ) );

		// Ensure any unresolved issues are reflected: warnings cap at 98,
		// critical/major issues cap at 95.
		if ( (int) $counts['critical'] > 0 || (int) $counts['major'] > 0 ) {
			$raw = min( $raw, 95 );
		} elseif ( (int) $counts['warning'] > 0 ) {
			$raw = min( $raw, 98 );
		}

		return $raw;
	}

	/**
	 * Integrity score from weighted findings vs scan surface.
	 *
	 * @param array $counts Severity counts.
	 * @return int
	 */
	protected function integrity_score( array $counts ) {
		$langs   = max( 1, count( $this->secondary_langs ) );
		$surface = max(
			1,
			( $this->documents_scanned * $langs ) + (int) $this->widgets_scanned
		);

		$weighted = ( (int) $counts['critical'] * 3 )
			+ ( (int) $counts['major'] * 2 )
			+ ( (int) $counts['warning'] * 1 );

		$rate = min( 1, $weighted / $surface );

		return (int) max( 0, min( 100, round( ( 1 - $rate ) * 100 ) ) );
	}

	/**
	 * Widget/field fidelity vs widgets scanned.
	 *
	 * @return int
	 */
	protected function widget_fidelity_score() {
		$widgets = (int) $this->widgets_scanned;
		if ( $widgets <= 0 ) {
			return 100;
		}

		$gaps = (int) $this->stats['missing_widgets'] + (int) $this->stats['missing_fields'];
		$rate = min( 1, $gaps / $widgets );

		return (int) max( 0, min( 100, round( ( 1 - $rate ) * 100 ) ) );
	}

	/**
	 * Recompute builder health from a saved report (standard formula).
	 *
	 * @param array      $report          Saved Elementor scan report.
	 * @param array|null $active_findings Optional findings override.
	 * @return int
	 */
	public static function health_score_from_report( array $report, ?array $active_findings = null ) {

		if ( post_type_exists( 'elementor_library' ) && function_exists( 'pll_is_translated_post_type' ) && ! pll_is_translated_post_type( 'elementor_library' ) ) {
			return 0;
		}

		$summary = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		$stats   = isset( $report['statistics'] ) && is_array( $report['statistics'] ) ? $report['statistics'] : array();

		$documents_found   = isset( $summary['templates_found'] ) ? (int) $summary['templates_found'] : 0;
		$documents_scanned = isset( $summary['templates_scanned'] ) ? (int) $summary['templates_scanned'] : 0;
		$widgets_scanned   = isset( $summary['widgets_scanned'] ) ? (int) $summary['widgets_scanned'] : 0;
		if ( $widgets_scanned <= 0 && isset( $stats['widgets'] ) ) {
			$widgets_scanned = (int) $stats['widgets'];
		}

		if ( $documents_found <= 0 && $documents_scanned <= 0 ) {
			return 100;
		}

		$critical          = 0;
		$major             = 0;
		$warning           = 0;
		$missing_templates = 0;
		$missing_widgets   = 0;
		$missing_fields    = 0;

		$findings_list = null !== $active_findings
			? $active_findings
			: ( isset( $report['findings'] ) && is_array( $report['findings'] ) ? $report['findings'] : null );

		if ( null !== $findings_list ) {
			foreach ( $findings_list as $finding ) {
				if ( ! is_array( $finding ) || self::is_finding_ignored( $finding ) ) {
					continue;
				}

				$severity = isset( $finding['severity'] ) ? $finding['severity'] : '';
				if ( DUPCAP_Elementor_Finding::CRITICAL === $severity ) {
					$critical++;
				} elseif ( DUPCAP_Elementor_Finding::MAJOR === $severity ) {
					$major++;
				} elseif ( DUPCAP_Elementor_Finding::WARNING === $severity ) {
					$warning++;
				}

				$code = isset( $finding['code'] ) ? $finding['code'] : '';
				if ( 'missing_translation' === $code ) {
					$missing_templates++;
				} elseif ( 'missing_widget' === $code ) {
					$missing_widgets++;
				} elseif ( 'missing_field' === $code ) {
					$missing_fields++;
				}
			}
		} else {
			$counts            = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
			$critical          = isset( $counts['critical'] ) ? (int) $counts['critical'] : 0;
			$major             = isset( $counts['major'] ) ? (int) $counts['major'] : 0;
			$warning           = isset( $counts['warning'] ) ? (int) $counts['warning'] : 0;
			$missing_templates = isset( $stats['missing_templates'] ) ? (int) $stats['missing_templates'] : 0;
			$missing_widgets   = isset( $stats['missing_widgets'] ) ? (int) $stats['missing_widgets'] : 0;
			$missing_fields    = isset( $stats['missing_fields'] ) ? (int) $stats['missing_fields'] : 0;
		}

		$problems = $critical + $major + $warning;

		if ( 0 === $problems ) {
			return 100;
		}

		$languages  = isset( $summary['languages'] ) && is_array( $summary['languages'] ) ? $summary['languages'] : array();
		$lang_count = count( $languages );
		$secondary  = max( 1, $lang_count > 1 ? $lang_count - 1 : 1 );

		$expected = max( 1, $documents_scanned * $secondary );
		$coverage = (int) max( 0, min( 100, round( ( ( $expected - $missing_templates ) / $expected ) * 100 ) ) );

		$surface   = max( 1, ( $documents_scanned * $secondary ) + $widgets_scanned );
		$weighted  = ( $critical * 3 ) + ( $major * 2 ) + ( $warning * 1 );
		$integrity = (int) max( 0, min( 100, round( ( 1 - min( 1, $weighted / $surface ) ) * 100 ) ) );

		if ( $widgets_scanned <= 0 ) {
			$raw = (int) max( 0, min( 100, round( ( 0.60 * $coverage ) + ( 0.40 * $integrity ) ) ) );
		} else {
			$fidelity = (int) max( 0, min( 100, round( ( 1 - min( 1, ( $missing_widgets + $missing_fields ) / $widgets_scanned ) ) * 100 ) ) );
			$raw      = (int) max( 0, min( 100, round( ( 0.50 * $coverage ) + ( 0.30 * $integrity ) + ( 0.20 * $fidelity ) ) ) );
		}

		// Cap score so active issues are always visible in the percentage.
		if ( $critical > 0 || $major > 0 ) {
			$raw = min( $raw, 95 );
		} elseif ( $warning > 0 ) {
			$raw = min( $raw, 98 );
		}

		return $raw;
	}

	/**
	 * Rough translation coverage score.
	 *
	 * @return int
	 */
	protected function coverage_score() {
		if ( $this->documents_scanned <= 0 || empty( $this->secondary_langs ) ) {
			return 100;
		}

		$expected = $this->documents_scanned * count( $this->secondary_langs );
		$missing  = (int) $this->stats['missing_templates'];
		if ( $expected <= 0 ) {
			return 100;
		}

		return (int) max( 0, min( 100, round( ( ( $expected - $missing ) / $expected ) * 100 ) ) );
	}

	/**
	 * Read saved report.
	 *
	 * @return array|null
	 */
	public static function get_saved_report() {
		$report = get_option( self::OPTION_KEY, null );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * Save report.
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
	 * Whether Elementor is available for scanning.
	 *
	 * @return bool
	 */
	public static function is_elementor_available() {
		return did_action( 'elementor/loaded' )
			|| defined( 'ELEMENTOR_VERSION' )
			|| post_type_exists( 'elementor_library' );
	}
}

