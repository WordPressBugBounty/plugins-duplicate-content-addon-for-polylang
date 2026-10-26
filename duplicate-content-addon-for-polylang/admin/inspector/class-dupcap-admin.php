<?php
/**
 * Admin: registers the menu page and enqueues assets.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Admin {

	/** @var DUPCAP_Admin|null */
	private static $instance = null;

	/**
	 * Tools submenu slug (query arg `page=`).
	 *
	 * Prefer {@see self::PAGE} in new code for clarity.
	 */
	const SLUG = 'translation-inspector-polylang';

	/**
	 * Readable alias for {@see self::SLUG}.
	 */
	const PAGE = self::SLUG;

	/**
	 * Polylang Languages admin menu slug.
	 */
	const POLYLANG_MENU = 'mlang';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_menu', array( $this, 'reorder_polylang_submenu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'load-edit.php', array( $this, 'select_ai_translation_post' ) );
		add_action( 'load-edit-tags.php', array( $this, 'select_ai_translation_term' ) );
	}

	/**
	 * Whether the Inspector lives under Polylang → Languages.
	 *
	 * @return bool
	 */
	public static function uses_polylang_menu() {
		return defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_languages_list' );
	}

	/**
	 * Parent slug for {@see add_submenu_page()}.
	 *
	 * @return string
	 */
	public static function parent_slug() {
		return self::uses_polylang_menu() ? self::POLYLANG_MENU : 'tools.php';
	}

	/**
	 * Admin PHP file for the Inspector screen URL.
	 *
	 * @return string
	 */
	public static function parent_file() {
		return self::uses_polylang_menu() ? 'admin.php' : 'tools.php';
	}

	/**
	 * Inspector dashboard admin URL.
	 *
	 * @param array<string, string> $args Extra query args.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		$args = array_merge( array( 'page' => self::PAGE ), $args );
		return add_query_arg( $args, admin_url( self::parent_file() ) );
	}

	/**
	 * Scan icon SVG used on Scan Website and loading overlay.
	 *
	 * @return string
	 */
	public static function scan_icon_url() {
		return defined( 'DUPCAP_URL' ) ? DUPCAP_URL . 'assets/images/scan.svg' : '';
	}

	/**
	 * Add Translation Inspector under Languages (Polylang) or Tools as fallback.
	 */
	public function add_menu() {
		$hook = add_submenu_page(
			self::parent_slug(),
			__( 'Translation Inspector', 'duplicate-content-addon-for-polylang' ),
			__( 'Translation Inspector', 'duplicate-content-addon-for-polylang' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'maybe_redirect_disabled_to_hub' ) );
			add_action( 'load-' . $hook, array( $this, 'suppress_foreign_admin_notices' ) );
			// Late pass: other plugins often re-hook admin_notices after load-{$hook}.
			add_action( 'in_admin_header', array( $this, 'suppress_foreign_admin_notices' ), 1000 );
		}
	}


	/**
	 * When Translation Inspector is switched off in Toolkit controls, send
	 * Languages → Translation Inspector to the hub and focus the toggle
	 * instead of the "switched off" interstitial. Setup replay
	 * (?dupcap_onboarding=1) still reaches the wizard.
	 *
	 * @return void
	 */
	public function maybe_redirect_disabled_to_hub() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen gate; no state change.
		$onboarding = ( isset( $_GET['dupcap_onboarding'] ) && is_string( $_GET['dupcap_onboarding'] ) ) ? sanitize_text_field( wp_unslash( $_GET['dupcap_onboarding'] ) ) : '';
		if ( '1' === $onboarding ) {
			return;
		}

		if ( ! function_exists( 'dupcap_is_tool_enabled' ) || dupcap_is_tool_enabled( 'inspector' ) ) {
			return;
		}

		$hub_page = class_exists( 'TFP_Toolkit_Hub' ) ? TFP_Toolkit_Hub::PAGE : 'toolkit-for-polylang';
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => $hub_page,
					'tfp_focus' => 'inspector',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Place Translation Inspector after AutoPoly in the Languages submenu.
	 *
	 * @return void
	 */
	public function reorder_polylang_submenu() {
		if ( ! self::uses_polylang_menu() ) {
			return;
		}

		global $submenu;

		$parent = self::POLYLANG_MENU;
		if ( ! isset( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$our_slug   = self::PAGE;
		$our_item   = null;
		$our_index  = null;
		$after_slug = array(
			'autopoly-ai-translation-for-polylang-pro',
			'autopoly-ai-translation-for-polylang',
			'autopoly',
			'atfp',
		);

		foreach ( $submenu[ $parent ] as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
				continue;
			}
			if ( (string) $item[2] === $our_slug ) {
				$our_item  = $item;
				$our_index = $index;
				break;
			}
		}

		if ( null === $our_index || null === $our_item ) {
			return;
		}

		array_splice( $submenu[ $parent ], $our_index, 1 );

		$insert_after = -1;
		foreach ( $submenu[ $parent ] as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug  = isset( $item[2] ) ? (string) $item[2] : '';
			$title = isset( $item[0] ) ? wp_strip_all_tags( (string) $item[0] ) : '';

			foreach ( $after_slug as $needle ) {
				if ( '' !== $needle && false !== stripos( $slug, $needle ) ) {
					$insert_after = $index;
					break;
				}
			}

			if ( $insert_after < 0 && false !== stripos( $title, 'autopoly' ) ) {
				$insert_after = $index;
			}
		}

		$insert_at = $insert_after >= 0 ? $insert_after + 1 : count( $submenu[ $parent ] );
		array_splice( $submenu[ $parent ], $insert_at, 0, array( $our_item ) );
	}

	/**
	 * Hide global admin notices on this screen.
	 *
	 * Dashboard notices must be printed via {@see 'dupcap_dashboard_notices'}.
	 * Runs on load-{$hook} and again on in_admin_header (priority 1000) because
	 * other plugins often re-register admin_notices after the early pass.
	 *
	 * @return void
	 */
	public function suppress_foreign_admin_notices() {
		if ( ! $this->is_inspector_screen() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Whether the current admin request is the Translation Inspector screen.
	 *
	 * @return bool
	 */
	private function is_inspector_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen gate.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE === $page ) {
			return true;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, self::PAGE ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * AutoPoly install/activate state for Inspector AI Translation buttons.
	 *
	 * @return array{
	 *     active: bool,
	 *     action: string,
	 *     slug: string,
	 *     init: string,
	 *     button_label: string,
	 *     can_install: bool,
	 *     can_activate: bool
	 * }
	 */
	public static function get_autopoly_status() {
		$is_pro  = defined( 'ATFPP_V' );
		$is_free = defined( 'ATFP_V' ) && ! $is_pro;
		$active  = $is_pro || defined( 'ATFP_V' );

		$candidates = array(
			array(
				'file' => 'autopoly-ai-translation-for-polylang-pro/autopoly-ai-translation-for-polylang-pro.php',
				'slug' => 'autopoly-ai-translation-for-polylang-pro',
			),
			array(
				'file' => 'automatic-translations-for-polylang/automatic-translation-for-polylang.php',
				'slug' => 'automatic-translations-for-polylang',
			),
			array(
				'file' => 'autopoly-ai-translation-for-polylang/autopoly-ai-translation-for-polylang.php',
				'slug' => 'autopoly-ai-translation-for-polylang',
			),
		);

		$slug   = 'automatic-translations-for-polylang';
		$init   = '';
		$action = 'install';
		$label  = __( 'Install AutoPoly', 'duplicate-content-addon-for-polylang' );

		if ( $active ) {
			$action = 'none';
			$label  = __( 'Start Translation', 'duplicate-content-addon-for-polylang' );
		} else {
			foreach ( $candidates as $candidate ) {
				$path = WP_PLUGIN_DIR . '/' . $candidate['file'];
				if ( file_exists( $path ) ) {
					$slug   = $candidate['slug'];
					$init   = $candidate['file'];
					$action = 'activate';
					$label  = ( false !== strpos( $candidate['slug'], '-pro' ) )
						? __( 'Activate', 'duplicate-content-addon-for-polylang' )
						: __( 'Activate', 'duplicate-content-addon-for-polylang' );
					break;
				}
			}
		}

		return array(
			'active'       => $active,
			'is_pro'       => $is_pro,
			'is_free'      => $is_free,
			'action'       => $action,
			'slug'         => $slug,
			'init'         => $init,
			'button_label' => $label,
			'can_install'  => current_user_can( 'install_plugins' ),
			'can_activate' => current_user_can( 'activate_plugins' ),
		);
	}

	/**
	 * Enqueue CSS/JS only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		$css_file = plugin_dir_path( __FILE__ ) . 'css/admin.css';
		$js_file  = plugin_dir_path( __FILE__ ) . 'js/admin.js';
		$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : DUPCAP_VERSION;
		$js_ver   = file_exists( $js_file ) ? (string) filemtime( $js_file ) : DUPCAP_VERSION;
		$autopoly = self::get_autopoly_status();

		wp_enqueue_style(
			'dupcap-admin',
			DUPCAP_INSPECTOR_URL . 'css/admin.css',
			array( 'dashicons' ),
			$css_ver
		);

		wp_enqueue_style( 'revisions' );
		wp_enqueue_style(
			'dupcap-editor-panel',
			DUPCAP_INSPECTOR_URL . 'css/editor-panel.css',
			array( 'revisions', 'dupcap-admin' ),
			defined( 'DUPCAP_VERSION' ) ? DUPCAP_VERSION : '2.1.2'
		);

		$marketing_css = defined( 'DUPCAP_URL' ) ? DUPCAP_URL . 'assets/css/marketing-popup.css' : '';
		if ( $marketing_css ) {
			wp_enqueue_style(
				'dupcap-marketing-popup',
				$marketing_css,
				array( 'dashicons' ),
				DUPCAP_VERSION
			);
		}

		wp_enqueue_script(
			'dupcap-admin',
			DUPCAP_INSPECTOR_URL . 'js/admin.js',
			array(),
			$js_ver,
			true
		);

		if ( class_exists( 'DUPCAP_Editor_Panel' ) ) {
			wp_enqueue_script(
				'dupcap-classic-editor',
				DUPCAP_INSPECTOR_URL . 'js/classic-editor.js',
				array( 'jquery', 'wp-a11y' ),
				defined( 'DUPCAP_VERSION' ) ? DUPCAP_VERSION : '2.1.2',
				true
			);

			wp_localize_script( 'dupcap-classic-editor', 'dupcapClassicData', DUPCAP_Editor_Panel::get_classic_script_data() );
		}

		wp_localize_script(
			'dupcap-admin',
			'dupcapDashboard',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				// Notice is a filterable level of its own only while the issue
				// layer is on; before that an info row answered to Warning.
				'noticeLevel'      => class_exists( 'DUPCAP_Issue_Normalizer' ) && DUPCAP_Issue_Normalizer::is_enabled(),
				'nonce'            => wp_create_nonce( 'dupcap_rescan_dashboard' ),
				'promptScan'       => ! DUPCAP_Scanner::has_saved_scan(),
				'loadingText'      => __( 'Scanning...', 'duplicate-content-addon-for-polylang' ),
				'completeText'     => __( 'Scan Complete', 'duplicate-content-addon-for-polylang' ),
				'defaultText'      => __( 'Scan Website', 'duplicate-content-addon-for-polylang' ),
				'ignoringText'     => __( 'Ignoring...', 'duplicate-content-addon-for-polylang' ),
				'restoringText'    => __( 'Restoring...', 'duplicate-content-addon-for-polylang' ),
				'defaultError'     => __( 'The scan could not finish. Please refresh the page and try again.', 'duplicate-content-addon-for-polylang' ),
				'installNonce'      => wp_create_nonce( 'dupcap_install_nonce' ),
				'installingText'    => __( 'Please Wait...', 'duplicate-content-addon-for-polylang' ),
				'activatingText'    => __( 'Please Wait...', 'duplicate-content-addon-for-polylang' ),
				'activatedText'     => __( 'Active', 'duplicate-content-addon-for-polylang' ),
				'installErrorText'  => __( 'Could not install the plugin. Please try again.', 'duplicate-content-addon-for-polylang' ),
				'assigningText'     => __( 'Assigning…', 'duplicate-content-addon-for-polylang' ),
				'assignErrorText'   => __( 'Could not assign the default language. Please try again.', 'duplicate-content-addon-for-polylang' ),
				'routingTestText'   => __( 'Testing your site&#8217;s URLs…', 'duplicate-content-addon-for-polylang' ),
				'routingErrorText'  => __( 'Your URLs could not be tested. They are shown as "Not tested" rather than as working.', 'duplicate-content-addon-for-polylang' ),
				/* translators: %s: document type label, such as Loop Items. */
				'elementorTypeEmptyText' => __( 'No issues found for %s.', 'duplicate-content-addon-for-polylang' ),
				'severityEmptyText'      => array(
					'crit'    => __( 'No critical issues in this area.', 'duplicate-content-addon-for-polylang' ),
					'warn'    => __( 'No warnings in this area.', 'duplicate-content-addon-for-polylang' ),
					'info'    => __( 'No notices in this area.', 'duplicate-content-addon-for-polylang' ),
					'pass'    => __( 'No passed checks in this area.', 'duplicate-content-addon-for-polylang' ),
					'ignored' => __( 'Nothing has been ignored in this area.', 'duplicate-content-addon-for-polylang' ),
				),
				'checkingIssueText' => __( 'Checking…', 'duplicate-content-addon-for-polylang' ),
				'checkFailedText'   => __( 'Could not check', 'duplicate-content-addon-for-polylang' ),
				'sectionScanningText'  => __( 'Scanning…', 'duplicate-content-addon-for-polylang' ),
				'sectionScanErrorText' => __( 'The scan could not finish. Please try again, or run Scan Website.', 'duplicate-content-addon-for-polylang' ),
				/* translators: 1: first item number on this page, 2: last item number on this page, 3: total items. */
				'pagerSummary'         => __( 'Showing %1$s – %2$s of %3$s items', 'duplicate-content-addon-for-polylang' ),
				/* translators: 1: current page number, 2: total pages. */
				'pagerIndicator'       => __( 'Page %1$s of %2$s', 'duplicate-content-addon-for-polylang' ),
				'pagerPrev'            => __( 'Previous', 'duplicate-content-addon-for-polylang' ),
				'pagerNext'            => __( 'Next', 'duplicate-content-addon-for-polylang' ),
				'pagerPrevLabel'       => __( 'Previous page', 'duplicate-content-addon-for-polylang' ),
				'pagerNextLabel'       => __( 'Next page', 'duplicate-content-addon-for-polylang' ),
				/* translators: %s: page number. */
				'pagerPageLabel'       => __( 'Page %s', 'duplicate-content-addon-for-polylang' ),
				'pagerNavLabel'        => __( 'Pagination', 'duplicate-content-addon-for-polylang' ),
				'optinNonce'             => wp_create_nonce( DUPCAP_Optin::NONCE_ACTION ),
				'savingText'             => __( 'Saving…', 'duplicate-content-addon-for-polylang' ),
				'seeTermsText'           => __( 'See terms', 'duplicate-content-addon-for-polylang' ),
				'hideTermsText'          => __( 'Hide terms', 'duplicate-content-addon-for-polylang' ),
				'autopoly'               => $autopoly,
				'autopolyPermissionText' => __( 'You do not have permission to install or activate plugins.', 'duplicate-content-addon-for-polylang' ),
			)
		);
	}

	/**
	 * Pre-select the post opened from the AI Translation button.
	 *
	 * @return void
	 */
	public function select_ai_translation_post() {
		$id = isset( $_GET['dupcap_select'] ) ? absint( wp_unslash( $_GET['dupcap_select'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id < 1 || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}

		add_action(
			'pre_get_posts',
			static function ( $query ) use ( $id ) {
				if ( ! $query instanceof WP_Query || ! $query->is_main_query() ) {
					return;
				}

				$tax_query = $query->get( 'tax_query' );
				if ( is_array( $tax_query ) ) {
					foreach ( $tax_query as $key => $clause ) {
						if ( is_array( $clause ) && isset( $clause['taxonomy'] ) && 'language' === $clause['taxonomy'] ) {
							unset( $tax_query[ $key ] );
						}
					}
					$query->set( 'tax_query', $tax_query );
				}

				$post_type = get_post_type( $id );
				if ( is_string( $post_type ) && '' !== $post_type ) {
					$query->set( 'post_type', $post_type );
				}

				$query->set( 'lang', '' );
				$query->set( 'post__in', array( $id ) );
				$query->set( 'post_status', array( 'publish', 'pending', 'draft', 'future', 'private' ) );
			}
		);

		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-info"><p>';
				echo esc_html__( 'This list shows the item you opened from Translation Inspector. Its checkbox is already selected.', 'duplicate-content-addon-for-polylang' );
				echo ' <a href="' . esc_url( remove_query_arg( 'dupcap_select' ) ) . '">';
				echo esc_html__( 'Show all items', 'duplicate-content-addon-for-polylang' );
				echo '</a></p></div>';
			}
		);

		add_action(
			'admin_footer',
			function () use ( $id ) {
				$this->print_ai_translation_select_script( $id );
			}
		);
	}

	/**
	 * Pre-select the term opened from the AI Translation button.
	 *
	 * @return void
	 */
	public function select_ai_translation_term() {
		$id = isset( $_GET['dupcap_select'] ) ? absint( wp_unslash( $_GET['dupcap_select'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id < 1 || ! current_user_can( 'edit_term', $id ) ) {
			return;
		}

		add_filter(
			'get_terms_args',
			static function ( $args ) use ( $id ) {
				if ( ! is_array( $args ) ) {
					return $args;
				}
				$args['include']    = array( $id );
				$args['number']     = 1;
				$args['hide_empty'] = false;
				$args['lang']       = '';
				return $args;
			}
		);

		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-info"><p>';
				echo esc_html__( 'This list shows the item you opened from Translation Inspector. Its checkbox is already selected.', 'duplicate-content-addon-for-polylang' );
				echo ' <a href="' . esc_url( remove_query_arg( 'dupcap_select' ) ) . '">';
				echo esc_html__( 'Show all items', 'duplicate-content-addon-for-polylang' );
				echo '</a></p></div>';
			}
		);

		add_action(
			'admin_footer',
			function () use ( $id ) {
				$this->print_ai_translation_select_script( $id );
			}
		);
	}

	/**
	 * Select the list row, open AutoPoly bulk translate once, then drop dupcap_select from the URL.
	 *
	 * @param int $id Post or term ID.
	 * @return void
	 */
	private function print_ai_translation_select_script( $id ) {
		$id = (int) $id;
		if ( $id < 1 ) {
			return;
		}

		echo '<script>
		(function () {
			var selectId = "' . esc_js( (string) $id ) . '";
			var storageKey = "dupcap_select_done_" + selectId;

			function cleanSelectArg() {
				try {
					var url = new URL(window.location.href);
					if (!url.searchParams.has("dupcap_select")) {
						return;
					}
					url.searchParams.delete("dupcap_select");
					window.history.replaceState({}, "", url.pathname + url.search + url.hash);
				} catch (err) {}
			}

			try {
				if (window.sessionStorage && sessionStorage.getItem(storageKey)) {
					cleanSelectArg();
					return;
				}
			} catch (err) {}

			var box = document.getElementById("cb-select-" + selectId);
			if (box) {
				box.checked = true;
				box.dispatchEvent(new Event("change", { bubbles: true }));
			}

			function markDone() {
				try {
					if (window.sessionStorage) {
						sessionStorage.setItem(storageKey, "1");
					}
				} catch (err) {}
				cleanSelectArg();
			}

			function clickBulk() {
				var btn = document.querySelector(".atfpp-bulk-translate-btn, .atfp-bulk-translate-btn");
				if (!btn) {
					return false;
				}
				btn.click();
				return true;
			}

			function afterHostReady() {
				var tries = 0;
				function attempt() {
					tries += 1;
					if (clickBulk()) {
						markDone();
						return;
					}
					if (tries >= 10) {
						cleanSelectArg();
						return;
					}
					window.setTimeout(attempt, 400);
				}
				window.setTimeout(attempt, 800);
			}

			if (document.readyState === "complete") {
				afterHostReady();
			} else {
				window.addEventListener("load", afterHostReady);
			}
		}());
		</script>';
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_page() {
		DUPCAP_Dashboard::instance()->render_admin_page();
	}
}
