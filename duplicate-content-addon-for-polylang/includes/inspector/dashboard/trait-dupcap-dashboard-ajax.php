<?php


defined( 'ABSPATH' ) || exit;

/**
 * Ajax methods for {@see DUPCAP_Dashboard}.
 */
trait DUPCAP_Dashboard_Ajax_Trait {

		/**
		 * Handle AJAX re-scan requests.
		 *
		 * @return void
		 */
		public function handle_rescan() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to run this scan.', 'duplicate-content-addon-for-polylang' ) ),
					403
				);
			}

			$this->verify_ajax_nonce();

			$refusal = $this->scan_refusal_message();
			if ( '' !== $refusal ) {
				wp_send_json_error( array( 'message' => $refusal ), 400 );
			}

			$this->send_dashboard_fragments();
		}

		/**
		 * Handle AJAX routing sweep requests.
		 *
		 * Runs the slow pass, saves its own report, then re-renders. The refresh
		 * forces a main rescan (see DUPCAP_Dashboard_Adapter::should_force_rescan),
		 * which re-applies dupcap_scan_results so the freshly saved routing rows
		 * are merged into the dashboard.
		 *
		 * @since 2.2.0
		 *
		 * @return void
		 */
		public function handle_scan_routing() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to run this scan.', 'duplicate-content-addon-for-polylang' ) ),
					403
				);
			}

			$this->verify_ajax_nonce();

			$refusal = $this->scan_refusal_message();
			if ( '' !== $refusal ) {
				wp_send_json_error( array( 'message' => $refusal ), 400 );
			}

			if ( ! class_exists( 'DUPCAP_Routing_Scanner' ) || ! DUPCAP_Routing_Scanner::is_available() ) {
				wp_send_json_error(
					array( 'message' => __( 'URLs cannot be tested because Polylang is not available.', 'duplicate-content-addon-for-polylang' ) ),
					400
				);
			}

			// Nonce verified above.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$force = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
			// phpcs:enable
			$outcome = DUPCAP_Routing_Scanner::run( $force );

			if ( ! empty( $outcome['report'] ) && is_array( $outcome['report'] ) ) {
				DUPCAP_Routing_Scanner::merge_into_main_report( $outcome['report'] );
			}

			// Numbered-slug HTTP checks live here (manual Test URLs), not in the
			// fast Scan Website pass — same timeout risk as the routing sweep.
			if ( ! empty( $outcome['ran'] ) && class_exists( 'DUPCAP_Permalink_Diagnostics' ) ) {
				DUPCAP_Permalink_Diagnostics::merge_http_into_main_report();
			}

			$this->send_dashboard_fragments(
				array(
					'routing_ran'     => ! empty( $outcome['ran'] ),
					'routing_message' => isset( $outcome['message'] ) ? (string) $outcome['message'] : '',
				)
			);
		}

		/**
		 * Handle AJAX section scans (Scan content / Scan Elementor / Scan site setup).
		 *
		 * Runs only the checks that can feed one dashboard section, then swaps that
		 * section's rows in the saved report. Other sections, routing rows and the
		 * main scan time are left as they were.
		 *
		 * @return void
		 */
		public function handle_scan_section() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to run this scan.', 'duplicate-content-addon-for-polylang' ) ),
					403
				);
			}

			$this->verify_ajax_nonce();

			$refusal = $this->scan_refusal_message();
			if ( '' !== $refusal ) {
				wp_send_json_error( array( 'message' => $refusal ), 400 );
			}

			// Nonce verified above.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$key = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
			// phpcs:enable
			$configs = $this->get_section_scan_config();

			if ( ! isset( $configs[ $key ] ) ) {
				wp_send_json_error(
					array( 'message' => __( 'This section cannot be scanned on its own.', 'duplicate-content-addon-for-polylang' ) ),
					400
				);
			}

			if ( ! DUPCAP_Scanner::has_saved_scan() ) {
				wp_send_json_error(
					array( 'message' => __( 'Run Scan Website once first. Section scans update an existing report.', 'duplicate-content-addon-for-polylang' ) ),
					400
				);
			}

			$config = $configs[ $key ];

			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Set time limit is allowed.
				@set_time_limit( 300 );
			}

			try {
				// Deep pass first, as in a full scan, so the main-pass rows and the
				// Elementor insights describe the same moment.
				if ( 'elementor' === $key && class_exists( 'DUPCAP_Elementor_Scanner' ) && DUPCAP_Elementor_Scanner::is_elementor_available() ) {
					DUPCAP_Elementor_Scanner::save_report( ( new DUPCAP_Elementor_Scanner() )->scan() );
				}

				$fresh = ( new DUPCAP_Scanner() )->scan( $config['skip'] );
			} catch ( \Throwable $e ) {
				if ( function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Error logging is allowed.
					error_log( 'DUPCAP section scan "' . $key . '" failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
				}
				wp_send_json_error(
					array( 'message' => __( 'The scan could not finish. Please try again, or run Scan Website.', 'duplicate-content-addon-for-polylang' ) ),
					500
				);
			}

			$rows = isset( $fresh['results'] ) && is_array( $fresh['results'] ) ? $fresh['results'] : array();
			if ( ! $this->save_section_results( $key, $config['section'], $rows ) ) {
				wp_send_json_error(
					array( 'message' => __( 'The scan finished, but the saved report could not be updated. Please run Scan Website.', 'duplicate-content-addon-for-polylang' ) ),
					500
				);
			}

			$this->send_dashboard_fragments(
				array(
					'section' => $key,
					'message' => $config['done'],
				)
			);
		}

		/**
		 * Section scans keyed by tab.
		 *
		 * `section` is the split_remaining_groups_by_section() key whose rows the
		 * scan replaces. `skip` lists core checks known to produce no rows for that
		 * section. It is a skip list, not an allow list, on purpose: a check that is
		 * not listed (a new core check, a third-party check) always runs, so its
		 * rows are refreshed instead of silently wiped.
		 *
		 * Site setup needs the URL check (url_dupe_slugs) and the Elementor check
		 * (elementor_none), so it runs most fast checks. It skips the content check
		 * (its rows all render in Content) and the Elementor deep scan.
		 *
		 * @return array<string, array{section: string, skip: string[], label: string, button: string, done: string}>
		 */
		private function get_section_scan_config() {
			return array(
				'content'   => array(
					'section' => 'content',
					'skip'    => array(
						'DUPCAP_Check_Languages',
						'DUPCAP_Check_Url_Config',
						'DUPCAP_Check_Translated_Permalinks',
						'DUPCAP_Check_Urls',
						'DUPCAP_Check_Menus',
						'DUPCAP_Check_Strings',
						'DUPCAP_Check_Elementor',
						'DUPCAP_Check_Switcher',
					),
					'label'   => __( 'Content', 'duplicate-content-addon-for-polylang' ),
					'button'  => __( 'Scan content', 'duplicate-content-addon-for-polylang' ),
					'done'    => __( 'Content scan finished. Results below are up to date.', 'duplicate-content-addon-for-polylang' ),
				),
				'elementor' => array(
					'section' => 'elementor',
					'skip'    => array(
						'DUPCAP_Check_Languages',
						'DUPCAP_Check_Url_Config',
						'DUPCAP_Check_Translated_Permalinks',
						'DUPCAP_Check_Content',
						'DUPCAP_Check_Urls',
						'DUPCAP_Check_Menus',
						'DUPCAP_Check_Strings',
						'DUPCAP_Check_Switcher',
					),
					'label'   => __( 'Elementor', 'duplicate-content-addon-for-polylang' ),
					'button'  => __( 'Scan Elementor', 'duplicate-content-addon-for-polylang' ),
					'done'    => __( 'Elementor scan finished. Results below are up to date.', 'duplicate-content-addon-for-polylang' ),
				),
				'site'      => array(
					'section' => 'other',
					'skip'    => array(
						'DUPCAP_Check_Content',
						'DUPCAP_Check_Url_Config',
						'DUPCAP_Check_Translated_Permalinks',
					),
					'label'   => __( 'Site Setup', 'duplicate-content-addon-for-polylang' ),
					'button'  => __( 'Scan site setup', 'duplicate-content-addon-for-polylang' ),
					'done'    => __( 'Site setup scan finished. Results below are up to date.', 'duplicate-content-addon-for-polylang' ),
				),
			);
		}

		/**
		 * Dashboard section a raw report row renders in.
		 *
		 * Reuses the display routing so a section scan replaces exactly the rows
		 * that section shows.
		 *
		 * @param string $id Result id.
		 * @return string content|elementor|urls|other
		 */
		private function get_result_section( $id ) {
			$group = array( 'dedupe_key' => DUPCAP_Dashboard_Adapter::dedupe_key_for( (string) $id ) );

			foreach ( $this->split_remaining_groups_by_section( array( $group ) ) as $section => $groups ) {
				if ( ! empty( $groups ) ) {
					return $section;
				}
			}

			return 'other';
		}

		/**
		 * Handle AJAX ignore requests for dismissible warnings.
		 *
		 * @return void
		 */
		public function handle_ignore() {
			$this->change_ignored_state(
				'ignore',
				array(
					'forbidden' => __( 'You do not have permission to ignore this warning.', 'duplicate-content-addon-for-polylang' ),
					'empty'     => __( 'No issue was selected to ignore.', 'duplicate-content-addon-for-polylang' ),
					'failed'    => __( 'This warning could not be ignored.', 'duplicate-content-addon-for-polylang' ),
				)
			);
		}

		/**
		 * Handle AJAX restore requests for previously ignored warnings.
		 *
		 * @return void
		 */
		public function handle_unignore() {
			$this->change_ignored_state(
				'unignore',
				array(
					'forbidden' => __( 'You do not have permission to restore this warning.', 'duplicate-content-addon-for-polylang' ),
					'empty'     => __( 'No ignored issue was selected to restore.', 'duplicate-content-addon-for-polylang' ),
					'failed'    => __( 'This ignored warning could not be restored.', 'duplicate-content-addon-for-polylang' ),
				)
			);
		}

		/**
		 * Shared body of the ignore and restore AJAX handlers.
		 *
		 * Capability first, then the nonce, then the input, as in every handler.
		 *
		 * @param string               $action   ignore|unignore: the DUPCAP_Ignored_Issues call to make.
		 * @param array<string,string> $messages Error texts keyed forbidden / empty / failed.
		 * @return void
		 */
		private function change_ignored_state( $action, array $messages ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => $messages['forbidden'] ), 403 );
			}

			$this->verify_ajax_nonce();

			// Nonce verified above.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$raw_ignore_key = isset( $_POST['ignore_key'] ) ? sanitize_text_field( wp_unslash( $_POST['ignore_key'] ) ) : '';
			// phpcs:enable
			if ( '' === trim( (string) $raw_ignore_key ) ) {
				wp_send_json_error( array( 'message' => $messages['empty'] ), 400 );
			}

			$restore = ( 'unignore' === $action );
			$success = false;
			foreach ( explode( ',', (string) $raw_ignore_key ) as $key ) {
				$clean_key = DUPCAP_Ignored_Issues::sanitize_key( trim( $key ) );
				if ( '' === $clean_key ) {
					continue;
				}
				$changed = $restore ? DUPCAP_Ignored_Issues::unignore( $clean_key ) : DUPCAP_Ignored_Issues::ignore( $clean_key );
				if ( $changed ) {
					$success = true;
				}
			}

			if ( ! $success ) {
				wp_send_json_error( array( 'message' => $messages['failed'] ), 400 );
			}

			$this->send_dashboard_fragments();
		}

		/**
		 * Assign the Polylang default language to posts, pages, and terms that have none.
		 *
		 * @return void
		 */
		public function handle_assign_default_lang() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to assign languages.', 'duplicate-content-addon-for-polylang' ) ),
					403
				);
			}

			$this->verify_ajax_nonce();

			$default_lang = function_exists( 'pll_default_language' ) ? pll_default_language() : '';
			if ( empty( $default_lang ) ) {
				wp_send_json_error(
					array( 'message' => __( 'No default language is configured in Polylang. Please set a default language first.', 'duplicate-content-addon-for-polylang' ) ),
					400
				);
			}

			$model = $this->get_polylang_model();
			if ( ! $model || ! method_exists( $model, 'set_language_in_mass' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Polylang is not available.', 'duplicate-content-addon-for-polylang' ) ),
					400
				);
			}

			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Set time limit is allowed.
				@set_time_limit( 300 );
			}

			$model->set_language_in_mass();

			// Rescan only Content and Elementor checks (skipping static site setup checks)
			// to update dashboard results quickly after assigning default languages.
			if ( class_exists( 'DUPCAP_Elementor_Scanner' ) && DUPCAP_Elementor_Scanner::is_elementor_available() ) {
				try {
					DUPCAP_Elementor_Scanner::save_report( ( new DUPCAP_Elementor_Scanner() )->scan() );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCATCH
					// Continue even if Elementor scan fails.
				}
			}

			$skip_checks = array(
				'DUPCAP_Check_Languages',
				'DUPCAP_Check_Url_Config',
				'DUPCAP_Check_Translated_Permalinks',
				'DUPCAP_Check_Urls',
				'DUPCAP_Check_Menus',
				'DUPCAP_Check_Strings',
				'DUPCAP_Check_Switcher',
			);

			$fresh      = ( new DUPCAP_Scanner() )->scan( $skip_checks );
			$fresh_rows = isset( $fresh['results'] ) && is_array( $fresh['results'] ) ? $fresh['results'] : array();

			$report = DUPCAP_Scanner::get_saved_report();
			if ( is_array( $report ) && isset( $report['results'] ) && is_array( $report['results'] ) ) {
				$report['results'] = $this->merge_section_rows( 'content', $report['results'], $fresh_rows );
				$report['results'] = $this->merge_section_rows( 'elementor', $report['results'], $fresh_rows );
				$report['summary'] = DUPCAP_Scanner::summarize( $report['results'] );

				$partial_scans              = isset( $report['partial_scans'] ) && is_array( $report['partial_scans'] ) ? $report['partial_scans'] : array();
				$now                        = time();
				$partial_scans['content']   = $now;
				$partial_scans['elementor'] = $now;
				$report['partial_scans']    = $partial_scans;

				update_option( DUPCAP_Scanner::OPTION_KEY, $report, false );
			} else {
				DUPCAP_Scanner::save_report( $fresh );
			}

			$this->send_dashboard_fragments();
		}

		/**
		 * Polylang model when available.
		 *
		 * @return object|null
		 */
		private function get_polylang_model() {
			$pll = function_exists( 'PLL' ) ? PLL() : null;
			return ( $pll && isset( $pll->model ) ) ? $pll->model : null;
		}

		/**
		 * Whether any translated posts or terms still have no language.
		 *
		 * @return bool
		 */
		private function has_objects_without_language() {
			if ( function_exists( 'pll_default_language' ) && ! pll_default_language() ) {
				return false;
			}

			$model = $this->get_polylang_model();
			if ( ! $model ) {
				return false;
			}

			if ( method_exists( $model, 'get_objects_with_no_lang' ) ) {
				$objects = $model->get_objects_with_no_lang( 1 );
				if ( ! empty( $objects ) ) {
					return true;
				}
			}

			if ( method_exists( $model, 'get_translated_post_types' ) && method_exists( $model, 'get_posts_with_no_lang' ) ) {
				$types = $model->get_translated_post_types();
				if ( ! empty( $types ) && ! empty( $model->get_posts_with_no_lang( $types, 1 ) ) ) {
					return true;
				}
			}

			if ( method_exists( $model, 'get_translated_taxonomies' ) && method_exists( $model, 'get_terms_with_no_lang' ) ) {
				$taxonomies = $model->get_translated_taxonomies();
				if ( ! empty( $taxonomies ) && ! empty( $model->get_terms_with_no_lang( $taxonomies, 1 ) ) ) {
					return true;
				}
			}

			return false;
		}


}
