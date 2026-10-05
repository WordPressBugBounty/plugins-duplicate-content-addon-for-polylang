<?php
/**
 * Onboarding overlay for Toolkit for Polylang.
 *
 * Opens once after first install (or until dismissed) on the
 * Translation Inspector dashboard, with the dashboard blurred behind it.
 * Force replay: admin.php?page=translation-inspector-polylang&dupcap_onboarding=1
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Onboarding {

	/** @var DUPCAP_Onboarding|null */
	private static $instance = null;

	const PAGE_SLUG       = 'dupcap-onboarding';
	const OPTION_KEY      = 'dupcap_onboarding_completed';
	const TOOLS_OPTION_KEY = 'dupcap_onboarding_tools';
	const REDIRECT_FLAG   = 'dupcap_onboarding_do_redirect';
	const NONCE_ACTION    = 'dupcap_onboarding_dismiss';

	/**
	 * @return DUPCAP_Onboarding
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'maybe_render_overlay' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'wp_ajax_dupcap_onboarding_dismiss', array( $this, 'ajax_dismiss' ) );
		add_action( 'wp_ajax_dupcap_onboarding_optin', array( $this, 'ajax_optin' ) );
	}

	/**
	 * Mark that the next admin load should open onboarding (set on activation).
	 *
	 * @return void
	 */
	public static function flag_redirect() {
		update_option( self::REDIRECT_FLAG, '1', false );
		if ( ! get_option( self::OPTION_KEY, '' ) ) {
			delete_option( self::OPTION_KEY );
		}
	}

	/**
	 * Whether onboarding has been finished / skipped.
	 *
	 * @return bool
	 */
	public function is_completed() {
		return '1' === get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Whether to force-show (query arg).
	 *
	 * @return bool
	 */
	public function is_forced() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['dupcap_onboarding'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['dupcap_onboarding'] ) );
	}

	/**
	 * Current admin `page` query arg.
	 *
	 * @return string
	 */
	private function current_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Translation Inspector dashboard slug.
	 *
	 * @return string
	 */
	public function inspector_slug() {
		return class_exists( 'DUPCAP_Admin' ) ? DUPCAP_Admin::PAGE : 'translation-inspector-polylang';
	}

	/**
	 * Whether the Inspector dashboard is available on this request.
	 *
	 * @return bool
	 */
	private function dashboard_available() {
		return class_exists( 'DUPCAP_Admin' );
	}

	/**
	 * Whether this request is the Inspector dashboard.
	 *
	 * @return bool
	 */
	public function is_inspector_page() {
		return $this->inspector_slug() === $this->current_page();
	}

	/**
	 * Whether the overlay should appear on the Inspector dashboard.
	 *
	 * @return bool
	 */
	public function should_show_overlay() {
		return current_user_can( 'manage_options' )
			&& $this->is_inspector_page()
			&& ( $this->is_forced() || ! $this->is_completed() );
	}

	/**
	 * Hidden fallback page when the Inspector dashboard is unavailable.
	 *
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			'',
			__( 'Setup — Toolkit for Polylang', 'duplicate-content-addon-for-polylang' ),
			__( 'Setup', 'duplicate-content-addon-for-polylang' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Send first-run / replay traffic to the Inspector dashboard overlay.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! current_user_can( 'manage_options' ) || ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( class_exists( 'duplicateContentAddon' ) && ! duplicateContentAddon::is_polylang_available() ) {
			return;
		}
		
		$page      = $this->current_page();
		$inspector = $this->inspector_slug();

		if ( $inspector === $page ) {
			if ( '1' === get_option( self::REDIRECT_FLAG, '' ) ) {
				delete_option( self::REDIRECT_FLAG );
			}
			return;
		}

		$do_redirect = ( '1' === get_option( self::REDIRECT_FLAG, '' ) );
		$send_to     = $this->dashboard_available() ? $this->overlay_url() : $this->page_url();

		if ( self::PAGE_SLUG === $page ) {
			if ( $this->dashboard_available() ) {
				wp_safe_redirect( $this->overlay_url( array( 'dupcap_onboarding' => '1' ) ) );
				exit;
			}
			return;
		}

		if ( $this->is_forced() ) {
			wp_safe_redirect( $this->dashboard_available() ? $this->overlay_url( array( 'dupcap_onboarding' => '1' ) ) : $this->page_url() );
			exit;
		}

		if ( $do_redirect ) {
			delete_option( self::REDIRECT_FLAG );
			if ( ! $this->is_completed() ) {
				wp_safe_redirect( $send_to );
				exit;
			}
		}
	}

	/**
	 * Legacy standalone onboarding URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public function page_url( $args = array() ) {
		$args = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Inspector dashboard URL (onboarding overlay host).
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public function overlay_url( $args = array() ) {
		return class_exists( 'DUPCAP_Admin' )
			? DUPCAP_Admin::page_url( $args )
			: add_query_arg( array_merge( array( 'page' => $this->inspector_slug() ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Inspector dashboard URL after finish.
	 *
	 * @return string
	 */
	public function finish_url() {
		return $this->overlay_url();
	}

	/**
	 * Body class for overlay or fallback full-page chrome.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$page = $this->current_page();
		if ( self::PAGE_SLUG === $page && ! $this->dashboard_available() ) {
			$classes .= ' dupcap-onboarding-screen';
		}
		if ( $this->should_show_overlay() ) {
			$classes .= ' dupcap-onboarding-overlay';
		}
		return $classes;
	}

	/**
	 * Enqueue assets on the overlay (dashboard) or fallback page.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		unset( $hook );

		$is_fallback = self::PAGE_SLUG === $this->current_page() && ! $this->dashboard_available();
		$is_overlay  = $this->should_show_overlay();
		if ( ! $is_fallback && ! $is_overlay ) {
			return;
		}

		$css_path = DUPCAP_DIR_PATH . 'admin/inspector/css/onboarding.css';
		$js_path  = DUPCAP_DIR_PATH . 'admin/inspector/js/onboarding.js';

		wp_enqueue_style(
			'dupcap-onboarding',
			DUPCAP_INSPECTOR_URL . 'css/onboarding.css',
			array( 'dashicons' ),
			(string) filemtime( $css_path )
		);

		wp_enqueue_script(
			'dupcap-onboarding',
			DUPCAP_INSPECTOR_URL . 'js/onboarding.js',
			array(),
			(string) filemtime( $js_path ),
			true
		);

		wp_localize_script(
			'dupcap-onboarding',
			'dupcapOnboarding',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( self::NONCE_ACTION ),
				'isOverlay'        => $is_overlay,
				'finishUrl'        => $this->finish_url(),
				'finishUrls'       => array(
					'both'      => $this->finish_url(),
					'inspector' => $this->finish_url(),
					'duplicate' => admin_url( 'admin.php?page=toolkit-for-polylang' ),
				),
				'finishText'            => __( 'Go to Dashboard', 'duplicate-content-addon-for-polylang' ),
				'continueText'          => __( 'Continue', 'duplicate-content-addon-for-polylang' ),
				'requiredToolText'      => __( 'At least one included tool is required.', 'duplicate-content-addon-for-polylang' ),
				'activeAddonText'       => self::active_addon_text(),
				'installNonce'          => wp_create_nonce( 'dupcap_install_nonce' ),
				'installingText'        => __( 'Installing…', 'duplicate-content-addon-for-polylang' ),
				'activatingText'        => __( 'Activating…', 'duplicate-content-addon-for-polylang' ),
				'installingPluginsText' => __( 'Please Wait....', 'duplicate-content-addon-for-polylang' ),
				'activatingPluginsText' => __( 'Please Wait....', 'duplicate-content-addon-for-polylang' ),
				'activatedText'         => __( 'Active', 'duplicate-content-addon-for-polylang' ),
				'installErrorText'      => __( 'Could not be installed. You can add it later from Plugins → Add New.', 'duplicate-content-addon-for-polylang' ),
				'seeTermsText'          => __( 'See terms', 'duplicate-content-addon-for-polylang' ),
				'hideTermsText'         => __( 'Hide terms', 'duplicate-content-addon-for-polylang' ),
				'doneCopy'              => $this->get_done_copy_variants(),
			)
		);
	}

	/**
	 * Print the overlay on the Inspector dashboard footer.
	 *
	 * @return void
	 */
	public function maybe_render_overlay() {
		if ( ! $this->should_show_overlay() ) {
			return;
		}
		$this->render_wizard( 'overlay' );
	}

	/**
	 * Done-step copy variants keyed by tool selection.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_done_copy_variants() {
		return array(
			'both'      => array(
				'title'       => __( 'Toolkit Is Ready', 'duplicate-content-addon-for-polylang' ),
				'description' => __( 'Your tools are ready to use. Open the dashboard to scan your multilingual site for translation issues.', 'duplicate-content-addon-for-polylang' ),
				'bullets'     => array(),
				'finish'      => __( 'Go to Dashboard', 'duplicate-content-addon-for-polylang' ),
				'tags'        => array(
					array(
						'dot'   => 'green',
						'label' => __( 'Polylang Synced', 'duplicate-content-addon-for-polylang' ),
					),
					array(
						'dot'   => 'green',
						'label' => __( 'Inspector Ready', 'duplicate-content-addon-for-polylang' ),
					),
					array(
						'dot'   => 'blue',
						'label' => __( 'Ready to Scan', 'duplicate-content-addon-for-polylang' ),
					),
				),
			),
			// Translation Inspector switched off: nothing to scan, so this variant
			// closes setup rather than offering a scan that cannot run.
			'duplicate' => array(
				'title'       => __( 'Translation Toolkit Is Ready', 'duplicate-content-addon-for-polylang' ),
				'description' => __( 'Start creating a copy of any page or post and translate it with Polylang.', 'duplicate-content-addon-for-polylang' ),
				'bullets'     => array(),
				'finish'      => __( 'Finish', 'duplicate-content-addon-for-polylang' ),
				'tags'        => array(
					array(
						'dot'   => 'green',
						'label' => __( 'Polylang Synced', 'duplicate-content-addon-for-polylang' ),
					),
					array(
						'dot'   => 'green',
						'label' => __( 'Duplicate Content', 'duplicate-content-addon-for-polylang' ),
					),
					array(
						'dot'   => 'blue',
						'label' => __( 'Ready to Use', 'duplicate-content-addon-for-polylang' ),
					),
				),
			),
			'inspector' => array(
				'title'       => __( 'Translation Toolkit Is Ready', 'duplicate-content-addon-for-polylang' ),
				'description' => __( 'Your tools are ready to use. Open the dashboard to scan your multilingual site for translation issues.', 'duplicate-content-addon-for-polylang' ),
				'bullets'     => array(),
				'finish'      => __( 'Go to Dashboard', 'duplicate-content-addon-for-polylang' ),
				'tags'        => array(
					array(
						'dot'   => 'green',
						'label' => __( 'Polylang Synced', 'duplicate-content-addon-for-polylang' ),
					),
					array(
						'dot'   => 'green',
						'label' => __( 'Inspector Ready', 'duplicate-content-addon-for-polylang' ),
					),
					array(
						'dot'   => 'blue',
						'label' => __( 'Ready to Scan', 'duplicate-content-addon-for-polylang' ),
					),
				),
			),
		);
	}

	/**
	 * Optional add-on plugins offered on the features step.
	 *
	 * `dirs` lists every plugin folder that satisfies the row, best first: with
	 * both a Pro and a free build sitting on disk, the Pro one is the one to
	 * activate. `install_slug` is what gets downloaded when none is present.
	 * Every folder here, and the install slug, must stay in the allow-list that
	 * `dupcap_install_plugin` checks, or the request is rejected.
	 *
	 * @since 2.0.7
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function addon_plugins() {
		$addons = array(
			'ai_translation'    => array(
				// Highest priority first. A site with both builds on disk gets the
				// Pro one activated; the free build is only a fallback.
				'dirs'         => array(
					'autopoly-ai-translation-for-polylang-pro',
					'autopoly-ai-translation-for-polylang',
					'automatic-translations-for-polylang',
				),
				// Pro is not on WordPress.org, so a fresh install is the free build.
				'install_slug' => 'automatic-translations-for-polylang',
				'constant'     => '',
				'icon'         => 'translation',
				'title'        => __( 'AI Translation', 'duplicate-content-addon-for-polylang' ),
				'text'         => __( 'Translate posts, pages and strings into 110+ languages with AI, without leaving the Polylang editor.', 'duplicate-content-addon-for-polylang' ),
			),
			'language_switcher' => array(
				'dirs'         => array( 'language-switcher-for-divi-polylang' ),
				'install_slug' => 'language-switcher-for-divi-polylang',
				'constant'     => 'LSDP',
				'icon'         => 'admin-site-alt3',
				'title'        => __( 'Language Switcher', 'duplicate-content-addon-for-polylang' ),
				'text'         => __( 'Add a customizable language switcher in Elementor, Gutenberg, or Divi pages, including a floating language switcher for Polylang.', 'duplicate-content-addon-for-polylang' ),
			),
		);

		/**
		 * Filter the optional add-ons offered during onboarding.
		 *
		 * A slug added here must also be allowed by `dupcap_install_plugin`,
		 * or the install request is rejected.
		 *
		 * @since 2.0.7
		 *
		 * @param array<string, array<string, mixed>> $addons Add-on rows.
		 */
		return apply_filters( 'dupcap_onboarding_addons', $addons );
	}

	/**
	 * Whether an add-on is active, installed but off, or not present, and which
	 * plugin folder the row should act on.
	 *
	 * Any build already running settles it — there is nothing to do. Otherwise
	 * the first folder present in `dirs` order wins, which is how a Pro build
	 * gets activated ahead of the free one when a site has both.
	 *
	 * @since 2.0.7
	 *
	 * @param array<string, mixed> $addon Row from {@see addon_plugins()}.
	 * @return array{status: string, slug: string} status is active|inactive|missing.
	 */
	private function plugin_status( array $addon ) {
		$dirs         = isset( $addon['dirs'] ) ? (array) $addon['dirs'] : array();
		$install_slug = isset( $addon['install_slug'] ) ? (string) $addon['install_slug'] : '';

		if ( ! empty( $addon['constant'] ) && defined( (string) $addon['constant'] ) ) {
			return array(
				'status' => 'active',
				'slug'   => '',
			);
		}

		// Folder => its main file, for the folders this row cares about.
		$present = array();
		foreach ( $dirs as $dir ) {
			$file = DUPCAP_Plugin_Status::in_folder( $dir );
			if ( '' !== $file ) {
				$present[ $dir ] = $file;
			}
		}

		foreach ( $present as $dir => $file ) {
			if ( DUPCAP_Plugin_Status::is_active( $file ) ) {
				return array(
					'status' => 'active',
					'slug'   => $dir,
				);
			}
		}

		// Nothing running: activate the best build that is already on disk.
		// $present keeps $dirs order, so the first key is the preferred build.
		foreach ( $present as $dir => $file ) {
			return array(
				'status' => 'inactive',
				'slug'   => $dir,
			);
		}

		return array(
			'status' => 'missing',
			'slug'   => $install_slug,
		);
	}

	/**
	 * Whether one of the toolkit's own tools is switched on.
	 *
	 * Thin wrapper over the bootstrap helper so the wizard and the gate read
	 * one value. The guard keeps this class usable if it is ever loaded on its
	 * own, in which case nothing has been switched off.
	 *
	 * @since 2.0.7
	 *
	 * @param string $tool inspector|duplicate.
	 * @return bool
	 */
	public static function is_tool_on( $tool ) {
		return ! function_exists( 'dupcap_is_tool_enabled' ) || dupcap_is_tool_enabled( $tool );
	}

	/**
	 * Rows for the "which features do you want to enable?" step.
	 *
	 * The two tools this plugin ships are always on and cannot be unchecked —
	 * they are the plugin. Add-ons are separate plugins, checked by default and
	 * installed on Continue, except one that is already running.
	 *
	 * @since 2.0.7
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_feature_rows() {
		// Ticked state comes from the same helper the plugin gates on, so the
		// wizard can never show a tool as on while the site treats it as off.
		$rows = array(
			array(
				'key'     => 'inspector',
				'tool'    => 'inspector',
				'icon'    => 'scan',
				'title'   => __( 'Translation Inspector', 'duplicate-content-addon-for-polylang' ),
				'text'    => __( 'Scan your site for missing translations, wrong-language links, unassigned menus, and blank Elementor layouts.', 'duplicate-content-addon-for-polylang' ),
				'note'    => __( 'Included in Toolkit', 'duplicate-content-addon-for-polylang' ),
				'tone'    => 'core',
				'locked'  => false,
				'checked' => self::is_tool_on( 'inspector' ),
			),
			array(
				'key'     => 'duplicate',
				'tool'    => 'duplicate',
				'icon'    => 'duplicate-content',
				'title'   => __( 'Duplicate Content', 'duplicate-content-addon-for-polylang' ),
				'text'    => __( 'Duplicate your pages/posts and make them available for translation via Polylang.', 'duplicate-content-addon-for-polylang' ),
				'note'    => __( 'Included in Toolkit', 'duplicate-content-addon-for-polylang' ),
				'tone'    => 'core',
				'locked'  => false,
				'checked' => self::is_tool_on( 'duplicate' ),
			),
		);

		foreach ( $this->addon_plugins() as $key => $addon ) {
			$resolved = $this->plugin_status( $addon );
			$status   = $resolved['status'];
			$slug     = $resolved['slug'];
			$is_pro   = ( '-pro' === substr( $slug, -4 ) );

			if ( 'active' === $status ) {
				$note   = $is_pro
					? __( 'Pro version available to use', 'duplicate-content-addon-for-polylang' )
					: __( 'Available to use', 'duplicate-content-addon-for-polylang' );
				$action = '';
			} elseif ( 'inactive' === $status ) {
				$note   = $is_pro
					? __( 'Pro version installed — will be activated', 'duplicate-content-addon-for-polylang' )
					: __( 'Installed — will be activated', 'duplicate-content-addon-for-polylang' );
				$action = 'activate';
			} else {
				$note   = __( 'Install Add-on', 'duplicate-content-addon-for-polylang' );
				$action = 'install';
			}

			$rows[] = array(
				'key'       => sanitize_key( (string) $key ),
				'icon'      => isset( $addon['icon'] ) ? (string) $addon['icon'] : 'admin-plugins',
				'title'     => isset( $addon['title'] ) ? (string) $addon['title'] : '',
				'text'      => isset( $addon['text'] ) ? (string) $addon['text'] : '',
				'note'      => $note,
				'tone'      => 'addon',
				// Nothing to do for an add-on that is already running, so the row
				// is shown ticked and locked rather than offering to reinstall it.
				'locked'    => ( 'active' === $status ),
				// A ticked box nobody can untick reads as broken without a reason.
				'lock_note' => ( 'active' === $status ) ? self::active_addon_text() : '',
				'checked'   => true,
				'slug'      => $slug,
				'action'    => $action,
			);
		}

		return $rows;
	}

	/**
	 * Why a ticked add-on row cannot be unticked.
	 *
	 * @return string
	 */
	public static function active_addon_text() {
		return __( 'Already active, cannot be deselected.', 'duplicate-content-addon-for-polylang' );
	}

	/**
	 * Steps for the wizard.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_steps() {
		$steps = array(
			array(
				'id'           => 'features',
				'label'        => __( 'Setup', 'duplicate-content-addon-for-polylang' ),
				'icon'         => 'magic-wand',
				'title'        => __( 'Set Up Your Translation Toolkit', 'duplicate-content-addon-for-polylang' ),
				'description'  => __( 'Choose the tools you\'d like to use with your multilingual site.', 'duplicate-content-addon-for-polylang' ),
				// Shown in the footer beside Continue, so the list starts directly
				// under the heading.
				'footer_note'  => __( 'Untick anything you do not need — a tool you turn off stops running. Add-ons you leave ticked are installed and activated for you.', 'duplicate-content-addon-for-polylang' ),
				'feature_rows' => $this->get_feature_rows(),
			),
			array(
				'id'          => 'done',
				'label'       => __( 'Done', 'duplicate-content-addon-for-polylang' ),
				'icon'        => 'yes-alt',
				'title'       => __( 'Translation Toolkit Is Ready', 'duplicate-content-addon-for-polylang' ),
				'description' => __( 'Your tools are ready to use. Open the dashboard to scan your multilingual site for translation issues.', 'duplicate-content-addon-for-polylang' ),
			),
		);

		/**
		 * Filter onboarding steps.
		 *
		 * @param array $steps Steps.
		 */
		return apply_filters( 'dupcap_onboarding_steps', $steps );
	}

	/**
	 * Render fallback full-page setup UI (Polylang / dashboard unavailable).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'duplicate-content-addon-for-polylang' ) );
		}
		$this->render_wizard( 'page' );
	}

	/**
	 * Render the wizard markup.
	 *
	 * @param string $mode overlay|page.
	 * @return void
	 */
	private function render_wizard( $mode = 'overlay' ) {
		$steps       = $this->get_steps();
		$total       = count( $steps );
		$logo_url    = defined( 'DUPCAP_URL' ) ? DUPCAP_URL . 'assets/images/toolkit-for-polylang-logo.svg' : '';
		$wand_url    = defined( 'DUPCAP_URL' ) ? DUPCAP_URL . 'assets/images/magic-wand.svg' : '';
		$duplicate_icon_url = defined( 'DUPCAP_URL' ) ? DUPCAP_URL . 'assets/images/duplicate-content.svg' : '';
		$scan_icon_url      = defined( 'DUPCAP_URL' ) ? DUPCAP_URL . 'assets/images/scan.svg' : '';
		$custom_icons       = array(
			'magic-wand'        => $wand_url,
			'duplicate-content' => $duplicate_icon_url,
			'scan'              => $scan_icon_url,
		);
		$custom_feature_icons = array(
			'scan'              => $scan_icon_url,
			'duplicate-content' => $duplicate_icon_url,
		);
		$is_overlay  = 'overlay' === $mode;
		$root_class  = $is_overlay ? 'dupcap-onboarding is-overlay' : 'dupcap-onboarding is-page';
		$root_role   = $is_overlay ? 'dialog' : 'main';
		?>
		<div
			id="dupcap-onboarding"
			class="<?php echo esc_attr( $root_class ); ?>"
			data-total="<?php echo esc_attr( (string) $total ); ?>"
			data-done-variant="both"
			data-mode="<?php echo esc_attr( $is_overlay ? 'overlay' : 'page' ); ?>"
			role="<?php echo esc_attr( $root_role ); ?>"
			<?php if ( $is_overlay ) : ?>
				aria-modal="true"
			<?php endif; ?>
			aria-labelledby="dupcap-onboarding-title"
		>
			<div class="dupcap-onboarding__shell">
				<header class="dupcap-onboarding__topbar">
					<div class="dupcap-onboarding__brand">
						<?php if ( $logo_url ) : ?>
							<img
								class="dupcap-onboarding__brand-icon"
								src="<?php echo esc_url( $logo_url ); ?>"
								alt=""
								width="32"
								height="32"
								decoding="async"
							/>
						<?php endif; ?>
						<h1 class="dupcap-onboarding__brand-name"><?php esc_html_e( 'Toolkit for Polylang', 'duplicate-content-addon-for-polylang' ); ?></h1>
					</div>

					<button
						type="button"
						class="dupcap-onboarding__exit"
						data-dupcap-onboarding-skip
						aria-label="<?php esc_attr_e( 'Close setup', 'duplicate-content-addon-for-polylang' ); ?>"
					>
						<span class="dupcap-onboarding__exit-icon dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</header>

				<div class="dupcap-onboarding__content">
					<div class="dupcap-onboarding__slides">
						<?php foreach ( $steps as $index => $step ) : ?>
							<?php
							$requires = isset( $step['requires'] ) ? (string) $step['requires'] : '';
							?>
							<section
								class="dupcap-onboarding__slide<?php echo 0 === $index ? ' is-active' : ''; ?><?php echo ! empty( $step['demo'] ) ? ' dupcap-onboarding__slide--guided' : ''; ?>"
								data-step="<?php echo esc_attr( (string) $index ); ?>"
								data-step-id="<?php echo esc_attr( $step['id'] ); ?>"
								<?php echo '' !== $requires ? 'data-requires="' . esc_attr( $requires ) . '"' : ''; ?>
								<?php echo 0 === $index ? '' : 'hidden'; ?>
							>

								<?php if ( 'done' === $step['id'] ) : ?>
									<?php $this->render_done_step( $step ); ?>
								<?php else : ?>
									<?php if ( empty( $step['demo'] ) && ! empty( $step['description'] ) ) : ?>
										<p class="dupcap-onboarding__desc"><?php echo wp_kses( $step['description'], array( 'strong' => array() ) ); ?></p>
									<?php endif; ?>

									<?php if ( ! empty( $step['feature_rows'] ) && is_array( $step['feature_rows'] ) ) : ?>
										<?php $this->render_feature_rows( $step['feature_rows'] ); ?>
									<?php endif; ?>
								<?php endif; ?>

								<?php if ( ! empty( $step['bullets'] ) && is_array( $step['bullets'] ) ) : ?>
									<ul class="dupcap-onboarding__list<?php echo ! empty( $step['demo'] ) ? ' dupcap-onboarding__list--cards' : ''; ?>">
										<?php foreach ( $step['bullets'] as $bullet_index => $bullet ) : ?>
											<li>
												<?php if ( ! empty( $step['demo'] ) ) : ?>
													<span class="dupcap-onboarding__list-step" aria-hidden="true"><?php echo esc_html( (string) ( $bullet_index + 1 ) ); ?></span>
												<?php endif; ?>
												<span class="dupcap-onboarding__list-text"><?php echo esc_html( $bullet ); ?></span>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>

								<?php if ( ! empty( $step['checklist'] ) && is_array( $step['checklist'] ) ) : ?>
									<ul class="dupcap-onboarding__checklist">
										<?php foreach ( $step['checklist'] as $item ) : ?>
											<li class="<?php echo ! empty( $item['ok'] ) ? 'is-ok' : 'is-fail'; ?>">
												<span class="dashicons dashicons-<?php echo ! empty( $item['ok'] ) ? 'yes' : 'warning'; ?>" aria-hidden="true"></span>
												<?php echo esc_html( $item['label'] ); ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>

								<?php if ( ! empty( $step['demo'] ) ) : ?>
									<?php $this->render_demo( (string) $step['demo'] ); ?>
								<?php endif; ?>

								<?php if ( ! empty( $step['action']['url'] ) && ! empty( $step['action']['label'] ) ) : ?>
									<p class="dupcap-onboarding__link-wrap">
										<a class="button button-secondary" href="<?php echo esc_url( $step['action']['url'] ); ?>" target="_blank" rel="noopener noreferrer">
											<?php echo esc_html( $step['action']['label'] ); ?>
										</a>
									</p>
								<?php endif; ?>
							</section>
						<?php endforeach; ?>
					</div>
				</div>

				<footer class="dupcap-onboarding__footer">
					<?php foreach ( $steps as $footer_step ) : ?>
						<?php if ( ! empty( $footer_step['footer_note'] ) ) : ?>
							<p
								class="dupcap-onboarding__footer-note"
								data-dupcap-footer-note="<?php echo esc_attr( $footer_step['id'] ); ?>"
								hidden
							><?php echo wp_kses( $footer_step['footer_note'], array( 'strong' => array() ) ); ?></p>
						<?php endif; ?>
					<?php endforeach; ?>
					<button type="button" class="button dupcap-onboarding__prev" data-dupcap-onboarding-prev hidden>
						<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Back', 'duplicate-content-addon-for-polylang' ); ?>
					</button>
					<div class="dupcap-onboarding__nav">
						<button type="button" class="button button-primary dupcap-onboarding__next" data-dupcap-onboarding-next>
							<?php esc_html_e( 'Continue', 'duplicate-content-addon-for-polylang' ); ?>
						</button>
						<button type="button" class="button button-primary dupcap-onboarding__finish" data-dupcap-onboarding-finish hidden>
							<span><?php esc_html_e( 'Go to Dashboard', 'duplicate-content-addon-for-polylang' ); ?></span>
							<span class="dupcap-btn-arrow" aria-hidden="true">&rarr;</span>
						</button>
					</div>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the completed / ready step layout (Step 2).
	 *
	 * @param array<string, mixed> $step Step data.
	 * @return void
	 */
	private function render_done_step( array $step ) {
		$variants = $this->get_done_copy_variants();
		$is_ins   = self::is_tool_on( 'inspector' );
		$is_dup   = self::is_tool_on( 'duplicate' );
		$key      = ( $is_ins && $is_dup ) ? 'both' : ( $is_ins ? 'inspector' : 'duplicate' );
		$tags     = isset( $variants[ $key ]['tags'] ) ? $variants[ $key ]['tags'] : array();
		?>
		<div class="dupcap-onboarding__done-wrapper">
			<div class="dupcap-onboarding__done-hero">
				<div class="dupcap-onboarding__done-badge-glow">
					<div class="dupcap-onboarding__done-badge-circle">
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M4.16669 10L8.33335 14.1667L15.8334 5.83334" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</div>
				</div>
				<h1 class="dupcap-onboarding__title"><?php echo esc_html( $step['title'] ); ?></h1>
				<p class="dupcap-onboarding__desc"><?php echo wp_kses( $step['description'], array( 'strong' => array() ) ); ?></p>
			</div>

			<div class="dupcap-onboarding__done-tags">
				<?php foreach ( $tags as $tag ) : ?>
					<span class="dupcap-onboarding__done-tag">
						<span class="dupcap-onboarding__done-dot <?php echo 'blue' === $tag['dot'] ? 'is-blue' : 'is-green'; ?>"></span>
						<?php echo esc_html( $tag['label'] ); ?>
					</span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the feature list on the first step.
	 *
	 * Only the tick toggles a row — clicking the title or the description does
	 * nothing, so reading a row can never change what it promises.
	 *
	 * A locked row is a disabled checkbox: it stays ticked, cannot be cleared,
	 * and still reports as checked to the script that reads the selection.
	 *
	 * @since 2.0.7
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from {@see get_feature_rows()}.
	 * @return void
	 */
	private function render_feature_rows( array $rows ) {
		$scan_icon_url      = defined( 'DUPCAP_URL' ) ? DUPCAP_URL . 'assets/images/scan.svg' : '';
		$duplicate_icon_url = defined( 'DUPCAP_URL' ) ? DUPCAP_URL . 'assets/images/duplicate-content.svg' : '';
		$custom_icons       = array(
			'scan'              => $scan_icon_url,
			'duplicate-content' => $duplicate_icon_url,
		);
		?>
		<div
			class="dupcap-onboarding__features dupcap-onboarding__features--rows"
			role="group"
			aria-label="<?php esc_attr_e( 'Features to enable', 'duplicate-content-addon-for-polylang' ); ?>"
		>
			<?php
			foreach ( $rows as $row ) :
				$key = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';

				if ( '' === $key ) {
					continue;
				}

				$locked   = ! empty( $row['locked'] );
				$checked  = ! isset( $row['checked'] ) || ! empty( $row['checked'] );
				$tone     = ( isset( $row['tone'] ) && 'core' === $row['tone'] ) ? 'core' : 'addon';
				$tool     = isset( $row['tool'] ) ? sanitize_key( (string) $row['tool'] ) : '';
				$slug     = isset( $row['slug'] ) ? sanitize_key( (string) $row['slug'] ) : '';
				$action   = isset( $row['action'] ) ? sanitize_key( (string) $row['action'] ) : '';
				$icon     = isset( $row['icon'] ) ? (string) $row['icon'] : 'admin-generic';
				$icon_url = isset( $custom_icons[ $icon ] ) ? $custom_icons[ $icon ] : '';
				$input_id = 'dupcap-onboarding-feature-' . $key;

				$classes = 'dupcap-onboarding__feature dupcap-onboarding__feature--row is-' . $tone;
				if ( $checked ) {
					$classes .= ' is-checked';
				}
				if ( $locked ) {
					$classes .= ' is-locked';
				}
				?>
				<div class="<?php echo esc_attr( $classes ); ?>">
					<input
						type="checkbox"
						class="dupcap-onboarding__feature-check"
						id="<?php echo esc_attr( $input_id ); ?>"
						value="<?php echo esc_attr( '' !== $tool ? $tool : $key ); ?>"
						<?php echo '' !== $tool ? 'data-dupcap-tool="' . esc_attr( $tool ) . '"' : ''; ?>
						<?php // Every add-on row is identifiable, even one detected by its constant, which resolves no folder. ?>
						<?php echo 'addon' === $tone ? 'data-dupcap-addon="' . esc_attr( $key ) . '"' : ''; ?>
						<?php echo '' !== $slug ? 'data-plugin-slug="' . esc_attr( $slug ) . '"' : ''; ?>
						<?php echo '' !== $action ? 'data-plugin-action="' . esc_attr( $action ) . '"' : ''; ?>
						<?php checked( $checked ); ?>
						<?php disabled( $locked ); ?>
					/>
					<span class="dupcap-onboarding__feature-main">
						<?php if ( $icon_url ) : ?>
							<span class="dupcap-onboarding__feature-icon dupcap-onboarding__feature-icon--img" aria-hidden="true">
								<img src="<?php echo esc_url( $icon_url ); ?>" width="28" height="28" alt="" decoding="async" />
							</span>
						<?php else : ?>
							<span class="dupcap-onboarding__feature-icon dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="dupcap-onboarding__feature-body">
							<span class="dupcap-onboarding__feature-header">
								<strong class="dupcap-onboarding__feature-title"><?php echo esc_html( isset( $row['title'] ) ? $row['title'] : '' ); ?></strong>
								<span
									class="dupcap-onboarding__feature-note"
									data-dupcap-feature-note="<?php echo esc_attr( $key ); ?>"
								><?php echo esc_html( isset( $row['note'] ) ? $row['note'] : '' ); ?></span>
							</span>
							<span class="dupcap-onboarding__feature-text"><?php echo esc_html( isset( $row['text'] ) ? $row['text'] : '' ); ?></span>
						</span>
					</span>
					<?php $lock_note = isset( $row['lock_note'] ) ? (string) $row['lock_note'] : ''; ?>
					<label
						class="dupcap-onboarding__feature-mark"
						for="<?php echo esc_attr( $input_id ); ?>"
						<?php echo '' !== $lock_note ? 'data-dupcap-tooltip="' . esc_attr( $lock_note ) . '"' : ''; ?>
					>
						<span class="dashicons dashicons-yes" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html( isset( $row['title'] ) ? $row['title'] : '' ); ?></span>
					</label>
				</div>
			<?php endforeach; ?>
			<?php $this->render_optin_row(); ?>
		</div>
		<?php
	}

	/**
	 * Usage data sharing opt-in, rendered as the last row of the feature list.
	 *
	 * Checked by default. An answer already recorded — by the CPFM popup, or by
	 * a replay of this wizard — wins over that default, so the wizard never
	 * silently flips a "no" back to "yes".
	 *
	 * @return void
	 */
	private function render_optin_row() {
		$checked = ! class_exists( 'DUPCAP_Optin' ) || ! DUPCAP_Optin::has_choice() || DUPCAP_Optin::is_enabled();
		$classes = 'dupcap-onboarding__feature dupcap-onboarding__feature--row dupcap-onboarding__feature--optin is-addon';
		if ( $checked ) {
			$classes .= ' is-checked';
		}
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<input
				type="checkbox"
				class="dupcap-onboarding__feature-check"
				id="dupcap-onboarding-optin"
				data-dupcap-optin
				<?php checked( $checked ); ?>
			/>
			<span class="dupcap-onboarding__feature-main">
				<span class="dupcap-onboarding__feature-icon dashicons dashicons-chart-bar" aria-hidden="true"></span>
				<span class="dupcap-onboarding__feature-body">
					<span class="dupcap-onboarding__feature-title">
						<?php esc_html_e( 'Help improve this plugin', 'duplicate-content-addon-for-polylang' ); ?>
					</span>
					<span class="dupcap-onboarding__feature-text">
						<?php esc_html_e( 'Share non-sensitive site data so we can keep the plugin compatible with your setup.', 'duplicate-content-addon-for-polylang' ); ?>
					</span>
					<?php // Outside the note, which is hidden when a row is cleared: the terms stay readable either way. ?>
					<span class="dupcap-onboarding__feature-terms">
						<button
							type="button"
							class="dupcap-onboarding__optin-terms-toggle"
							data-dupcap-optin-terms
							aria-expanded="false"
							aria-controls="dupcap-onboarding-optin-terms"
						>
							<?php esc_html_e( 'See terms', 'duplicate-content-addon-for-polylang' ); ?>
						</button>
					</span>
					<span class="dupcap-onboarding__optin-details" id="dupcap-onboarding-optin-terms" data-dupcap-optin-details hidden>
						<span class="dupcap-onboarding__optin-details-text">
							<?php esc_html_e( "Opt in to receive email updates about security improvements, new features, helpful tutorials, and occasional special offers. We'll collect:", 'duplicate-content-addon-for-polylang' ); ?>
							<a href="https://my.coolplugins.net/terms/usage-tracking/" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Click here', 'duplicate-content-addon-for-polylang' ); ?>
							</a>
						</span>
						<span class="dupcap-onboarding__optin-details-item">
							<?php esc_html_e( 'Your website home URL and WordPress admin email.', 'duplicate-content-addon-for-polylang' ); ?>
						</span>
						<span class="dupcap-onboarding__optin-details-item">
							<?php esc_html_e( 'To check plugin compatibility: your list of active plugins and themes, server type, MySQL version, WordPress version, memory limit, site language, database prefix, and which of the two tools above you chose to set up.', 'duplicate-content-addon-for-polylang' ); ?>
						</span>
					</span>
				</span>
			</span>
			<label class="dupcap-onboarding__feature-mark" for="dupcap-onboarding-optin">
				<span class="dashicons dashicons-yes" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Help improve this plugin', 'duplicate-content-addon-for-polylang' ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Demo panel for a step.
	 *
	 * @param string $type Demo type: duplicate|inspector.
	 * @return void
	 */
	private function render_demo( $type ) {
		if ( 'duplicate' === $type ) {
			?>
			<div class="dupcap-onboarding__demo" aria-hidden="true">
				<div class="dupcap-onboarding__demo-label"><?php esc_html_e( 'Demo', 'duplicate-content-addon-for-polylang' ); ?></div>
				<div class="dupcap-onboarding__demo-frame">
					<div class="dupcap-onboarding__demo-card">
						<div class="dupcap-onboarding__demo-row">
							<span class="dupcap-onboarding__demo-flag">EN</span>
							<span class="dupcap-onboarding__demo-title"><?php esc_html_e( 'About us', 'duplicate-content-addon-for-polylang' ); ?></span>
							<span class="dupcap-onboarding__demo-badge"><?php esc_html_e( 'Source', 'duplicate-content-addon-for-polylang' ); ?></span>
						</div>
						<div class="dupcap-onboarding__demo-arrow" aria-hidden="true">↓</div>
						<div class="dupcap-onboarding__demo-row is-target">
							<span class="dupcap-onboarding__demo-flag">DE</span>
							<span class="dupcap-onboarding__demo-title"><?php esc_html_e( 'About us', 'duplicate-content-addon-for-polylang' ); ?></span>
							<span class="dupcap-onboarding__demo-badge is-copy"><?php esc_html_e( 'Content copied', 'duplicate-content-addon-for-polylang' ); ?></span>
						</div>
						<p class="dupcap-onboarding__demo-hint"><?php esc_html_e( 'Polylang + → new draft with copied title & content', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		if ( 'inspector' === $type ) {
			?>
			<div class="dupcap-onboarding__demo" aria-hidden="true">
				<div class="dupcap-onboarding__demo-label"><?php esc_html_e( 'Demo', 'duplicate-content-addon-for-polylang' ); ?></div>
				<div class="dupcap-onboarding__demo-frame">
					<div class="dupcap-onboarding__demo-card">
						<div class="dupcap-onboarding__demo-score">
							<span class="dupcap-onboarding__demo-score-num">72</span>
							<span class="dupcap-onboarding__demo-score-label"><?php esc_html_e( 'Health score', 'duplicate-content-addon-for-polylang' ); ?></span>
						</div>
						<ul class="dupcap-onboarding__demo-issues">
							<li><span class="is-crit"></span><?php esc_html_e( 'Fix These First', 'duplicate-content-addon-for-polylang' ); ?></li>
							<li><span class="is-warn"></span><?php esc_html_e( 'Content — pages & posts', 'duplicate-content-addon-for-polylang' ); ?></li>
							<li><span class="is-info"></span><?php esc_html_e( 'Site Setup', 'duplicate-content-addon-for-polylang' ); ?></li>
						</ul>
						<p class="dupcap-onboarding__demo-hint"><?php esc_html_e( 'Scan → fix issues → Rescan', 'duplicate-content-addon-for-polylang' ); ?></p>
					</div>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Persist dismiss / finish and return redirect URL.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		if ( '1' !== get_option( self::OPTION_KEY, '' ) ) {
			update_option( self::OPTION_KEY, '1', false );
		}

		// Saved whenever the request carried the field. Empty list is rejected —
		// both tools off is not allowed (same rule as the hub toggles).
		if ( $this->request_has_tools() ) {
			$tools = $this->sanitize_tools_from_request();
			if ( array() === $tools ) {
				wp_send_json_error(
					array(
						'message' => __( 'Both features cannot be disabled at the same time.', 'duplicate-content-addon-for-polylang' ),
					),
					400
				);
			}
			update_option( self::TOOLS_OPTION_KEY, $tools, false );

			// Keep the shared usage snapshot on the final tool choice, which the
			// user can still change after the welcome step recorded consent.
			if ( class_exists( 'DUPCAP_Optin' ) ) {
				DUPCAP_Optin::sync_onboarding_data();
			}
		}

		delete_option( self::REDIRECT_FLAG );

		wp_send_json_success(
			array(
				'redirect' => $this->finish_url(),
			)
		);
	}

	/**
	 * Save the welcome step's opt-in answer (and the tools it applies to).
	 *
	 * Fired from the Continue button, not from Finish, so consent is recorded
	 * — yes or no — as soon as the user answers it. That retires the CPFM
	 * opt-in popup for this family.
	 *
	 * @return void
	 */
	public function ajax_optin() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		if ( $this->request_has_tools() ) {
			$tools = $this->sanitize_tools_from_request();
			if ( array() === $tools ) {
				wp_send_json_error(
					array(
						'message' => __( 'Both features cannot be disabled at the same time.', 'duplicate-content-addon-for-polylang' ),
					),
					400
				);
			}
			update_option( self::TOOLS_OPTION_KEY, $tools, false );
		}

		if ( ! class_exists( 'DUPCAP_Optin' ) ) {
			wp_send_json_success( array( 'enabled' => false ) );
		}

		$raw     = isset( $_POST['opt_in'] ) ? sanitize_key( wp_unslash( $_POST['opt_in'] ) ) : '';
		$enabled = in_array( $raw, array( 'yes', '1', 'true' ), true );

		// Always record the answer (yes or no) so the CPFM opt-in popup does
		// not ask again after the wizard already collected it.
		DUPCAP_Optin::save_choice( $enabled );

		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	/**
	 * Whether the request carried a tool selection at all.
	 *
	 * Distinguishes "the user unticked everything" from "this request was not
	 * about tools", which matters now that the value gates features.
	 *
	 * @since 2.0.7
	 *
	 * @return bool
	 */
	private function request_has_tools() {
		// Nonce verified by the calling handler.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST['tools'] );
	}

	/**
	 * Sanitize selected onboarding tools from the AJAX request.
	 *
	 * @return array<int, string>
	 */
	private function sanitize_tools_from_request() {
		if ( ! isset( $_POST['tools'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}

		$raw = wp_unslash( $_POST['tools'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$allowed = array( 'inspector', 'duplicate' );
		$clean   = array();
		foreach ( $raw as $tool ) {
			$tool = sanitize_key( (string) $tool );
			if ( in_array( $tool, $allowed, true ) && ! in_array( $tool, $clean, true ) ) {
				$clean[] = $tool;
			}
		}

		return $clean;
	}
}
