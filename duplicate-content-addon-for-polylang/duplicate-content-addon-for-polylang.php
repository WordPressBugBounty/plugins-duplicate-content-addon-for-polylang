<?php
/*
Plugin Name: Toolkit for Polylang - Translation Inspector & Duplicate Content
Plugin URI: https://wordpress.org/plugins/duplicate-content-addon-for-polylang/
Version: 2.1.1
Author: Khushwant Singh
Author URI: https://profiles.wordpress.org/khushwantsidhu/
Description: Duplicate Polylang content across languages and scan your site with Translation Inspector for missing or broken translations.
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: duplicate-content-addon-for-polylang
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DUPCAP_VERSION' ) ) {
	define( 'DUPCAP_VERSION', '2.1.1' );
}

/**
 * User-facing plugin name. Keep separate from folder/slug for upgrade compatibility.
 * WordPress identifies the plugin by path: duplicate-content-addon-for-polylang/*.php
 */
if ( ! defined( 'DUPCAP_PLUGIN_NAME' ) ) {
	define( 'DUPCAP_PLUGIN_NAME', 'Toolkit for Polylang' );
}

if ( ! defined( 'DUPCAP_DIR_PATH' ) ) {
	define( 'DUPCAP_DIR_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'DUPCAP_URL' ) ) {
	define( 'DUPCAP_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'DUPCAP_INSPECTOR_DIR' ) ) {
	// PHP classes live under includes/inspector/ (checks/, elementor/, etc.).
	define( 'DUPCAP_INSPECTOR_DIR', plugin_dir_path( __FILE__ ) . 'includes/inspector/' );
}

if ( ! defined( 'DUPCAP_INSPECTOR_URL' ) ) {
	// Admin CSS/JS live under admin/inspector/.
	define( 'DUPCAP_INSPECTOR_URL', plugin_dir_url( __FILE__ ) . 'admin/inspector/' );
}

define( 'DUPCAP_FILE', __FILE__ );

if ( ! defined( 'DUPCAP_FEEDBACK_API' ) ) {
	define( 'DUPCAP_FEEDBACK_API', 'https://feedback.coolplugins.net/' );
}

/**
 * Whether one of the toolkit's own tools is switched on.
 *
 * The setup wizard stores the choice in `dupcap_onboarding_tools`. A missing
 * option means the question was never answered, which counts as on — an update
 * must never silently switch off a feature a site is already using.
 *
 * Read directly from the option rather than through DUPCAP_Onboarding: this
 * runs on `plugins_loaded` at priority 10, before the inspector files load.
 *
 * @since 2.0.7
 *
 * @param string $tool inspector|duplicate.
 * @return bool
 */
function dupcap_is_tool_enabled( $tool ) {
	$tools = get_option( 'dupcap_onboarding_tools', null );

	// Missing or empty = wizard unanswered / corrupt empty write → both on
	// (same default as the hub toggles and DUPCAP_Optin::sync_onboarding_data).
	if ( ! is_array( $tools ) || array() === $tools ) {
		return true;
	}

	return in_array( (string) $tool, $tools, true );
}

if ( ! class_exists( 'duplicateContentAddon' ) ) {
		final class duplicateContentAddon {

			/**
			 * Plugin instance.
			 *
			 * @var duplicateContentAddon
			 * @access private
			 */
			private static $instance = null;

			/**
			 * Get plugin instance.
			 *
			 * @return duplicateContentAddon
			 * @static
			 */
			public static function get_instance() {
				if ( ! isset( self::$instance ) ) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			/**
			 * Constructor
			 */
			private function __construct() {

				$this->dupcap_includes();
				add_action( 'plugins_loaded', array( $this, 'dupcap_init' ) );
				add_action( 'plugins_loaded', array( $this, 'dupcap_init_inspector' ), 20 );
				register_activation_hook( DUPCAP_FILE, array( 'duplicateContentAddon', 'dupcap_activate' ) );
				register_deactivation_hook( DUPCAP_FILE, array( 'duplicateContentAddon', 'dupcap_deactivate' ) );
				add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'dupcap_plugin_action_links' ) );
				add_action( 'init', array( $this, 'register_cpfm_notices' ), 999 );
				add_action( 'plugins_loaded', array( $this, 'boot_optin' ), 5 );
			}

			/**
			 * Boot the usage data sharing opt-in (consent, cron, tool snapshot).
			 *
			 * @return void
			 */
			public function boot_optin() {
				DUPCAP_Optin::instance();
			}

			/**
			 * Register CPFM review ask and usage-feedback notice. The usage cron
			 * itself lives in DUPCAP_Optin, which must also load outside wp-admin.
			 *
			 * @return void
			 */
			public function register_cpfm_notices() {
				if ( ! is_admin() || ! self::is_polylang_available() ) {
					return;
				}

				static $registered = false;
				if ( $registered ) {
					return;
				}
				$registered = true;

				$loader = DUPCAP_DIR_PATH . 'admin/cpfm-feedback/class-cpfm-loader.php';
				if ( ! file_exists( $loader ) ) {
					return;
				}

				require_once $loader;
				if ( class_exists( 'CPFM_Loader' ) ) {
					CPFM_Loader::load();
				}

				$name = defined( 'DUPCAP_PLUGIN_NAME' ) ? DUPCAP_PLUGIN_NAME : 'Toolkit for Polylang';
				$page = class_exists( 'DUPCAP_Admin' ) ? DUPCAP_Admin::PAGE : 'translation-inspector-polylang';

				$dashboard_screens = array(
					'mlang_page_' . $page,
					'tools_page_' . $page,
					'languages_page_' . $page,
				);

				if ( class_exists( 'CPFM_Review' ) ) {
					CPFM_Review::cpfm_register(
						array(
							'id'          => 'dupcap',
							'plugin_file' => DUPCAP_FILE,
							'plugin_name' => $name,
							'review_url'  => 'https://wordpress.org/support/plugin/duplicate-content-addon-for-polylang/reviews/#new-post',
							'capability'  => 'activate_plugins',
							'quiet_days'  => 0,
							'own_screens' => $dashboard_screens,
							'trigger'     => array(
								'type'  => 'install_age',
								'hours' => 24,
							),
							'notice'      => array(
								'enabled'        => true,
								'template'       => 'two_step',
								'screens'        => array( 'plugins' ),
								'inline_screens' => array(),
								'defer_screens'  => array(),
							),
							'row'         => array( 'enabled' => true ),
							'legacy'      => array(
								'done_options'  => array(
									'dupcap-ratingDiv' => array( 'yes', 'done', 'dismissed' ),
								),
								'install_dates' => array( 'dupcap-installDate' ),
								'mirror_write'  => array( 'dupcap-ratingDiv' => 'yes' ),
							),
							'i18n'        => array(
								'like_question' => sprintf(
									/* translators: %s: plugin name. */
									__( 'Do you like the %s plugin?', 'duplicate-content-addon-for-polylang' ),
									$name
								),
								'yes_button'    => __( 'Yes, I like it', 'duplicate-content-addon-for-polylang' ),
								'dismiss_link'  => __( 'Not good, dismiss', 'duplicate-content-addon-for-polylang' ),
								'later_link'    => __( 'Ask me later', 'duplicate-content-addon-for-polylang' ),
								'thanks_line'   => __( 'That is great to hear! A quick review on WordPress.org would really help us.', 'duplicate-content-addon-for-polylang' ),
								'submit_button' => __( 'Submit review', 'duplicate-content-addon-for-polylang' ),
								'no_link'       => __( 'I do not like it, dismiss', 'duplicate-content-addon-for-polylang' ),
								'row_question'  => __( 'Do you like this plugin?', 'duplicate-content-addon-for-polylang' ),
								'inline_title'  => sprintf(
									/* translators: %s: plugin name. */
									__( 'Enjoying %s?', 'duplicate-content-addon-for-polylang' ),
									$name
								),
								'inline_text'   => __( 'A short review helps other site owners find it.', 'duplicate-content-addon-for-polylang' ),
								'close_label'   => __( 'Close', 'duplicate-content-addon-for-polylang' ),
							),
						)
					);
				}

				/*
				 * The usage-data question, as the rest of the family asks it. The
				 * panel hides itself for good once cpfm_opt_in_choice_cool_translations
				 * exists, so the setup wizard answering "yes" is what stops it
				 * appearing; leaving that box unticked records nothing and the
				 * question is asked here instead.
				 *
				 * Never while that wizard is still up, though: its last row asks
				 * this exact question, so the panel would put a second copy of it
				 * on screen, over an overlay it cannot be dismissed behind. Not
				 * registering keeps this page out of the category's page list for
				 * this request alone; the question is asked on the next visit,
				 * once the wizard has had its turn.
				 *
				 * DUPCAP_Onboarding loads on plugins_loaded 20 and this runs on
				 * init, so "not loaded" here means the Inspector is switched off
				 * and there is no wizard to clash with.
				 */
				$wizard_is_open = class_exists( 'DUPCAP_Onboarding' )
					&& DUPCAP_Onboarding::instance()->should_show_overlay();

				if ( class_exists( 'CPFM_Feedback_Notice' ) && ! $wizard_is_open ) {
					CPFM_Feedback_Notice::cpfm_register_notice(
						'cool_translations',
						array(
							'title'          => __( 'Translation Plugins by Cool Plugins', 'duplicate-content-addon-for-polylang' ),
							'message'        => __( 'Help us make this plugin more compatible with your site by sharing non-sensitive site data.', 'duplicate-content-addon-for-polylang' ),
							'plugin_name'    => 'dupcap',
							'pages'          => array( $page ),
							'always_show_on' => array( $page ),
							'i18n'           => array(
								'panel_title' => __( 'Help Improve Plugins', 'duplicate-content-addon-for-polylang' ),
								'yes_label'   => __( "Yes, it's OK", 'duplicate-content-addon-for-polylang' ),
								'no_label'    => __( 'No, Thanks', 'duplicate-content-addon-for-polylang' ),
								'more_info'   => __( 'More info', 'duplicate-content-addon-for-polylang' ),
							),
						)
					);

					// The dashboard setting reads this and stands down.
					if ( class_exists( 'DUPCAP_Optin' ) ) {
						DUPCAP_Optin::mark_notice_registered();
					}
				}

				if ( class_exists( 'CPFM_Deactivation_Feedback' ) ) {
					CPFM_Deactivation_Feedback::cpfm_register(
						array(
							'id'                     => 'dupcap',
							'slug'                   => 'duplicate-content-addon-for-polylang',
							'plugin_name'            => $name,
							'version'                => defined( 'DUPCAP_VERSION' ) ? DUPCAP_VERSION : '',
							'api'                    => defined( 'DUPCAP_FEEDBACK_API' ) ? DUPCAP_FEEDBACK_API : 'https://feedback.coolplugins.net/',
							'site_key'               => 'dupcap',
							'install_date_option'    => 'dupcap-installDate',
							'initial_version_option' => 'dupcap_initial_save_version',
							'reasons'                => array(
								'not_working'  => array(
									'title'       => __( "The plugin isn't working", 'duplicate-content-addon-for-polylang' ),
									'placeholder' => __( 'Which problem did you run into? We read every reply.', 'duplicate-content-addon-for-polylang' ),
								),
								'not_expected' => array(
									'title'       => __( "It didn't do what I expected", 'duplicate-content-addon-for-polylang' ),
									'placeholder' => __( 'What were you hoping it would do?', 'duplicate-content-addon-for-polylang' ),
								),
								'found_better' => array(
									'title'       => __( 'I found a better plugin', 'duplicate-content-addon-for-polylang' ),
									'placeholder' => __( 'Mind sharing which one?', 'duplicate-content-addon-for-polylang' ),
								),
								'temporary'    => array(
									'title'       => __( "It's a temporary deactivation", 'duplicate-content-addon-for-polylang' ),
									'placeholder' => '',
								),
								'other'        => array(
									'title'       => __( 'Another reason', 'duplicate-content-addon-for-polylang' ),
									'placeholder' => __( 'Please tell us more', 'duplicate-content-addon-for-polylang' ),
								),
							),
							'i18n'                   => array(
								'title'        => __( 'Before you go…', 'duplicate-content-addon-for-polylang' ),
								/* translators: %s: plugin name (bold). */
								'intro'        => __( 'What made you deactivate %s? Your answer helps us fix it.', 'duplicate-content-addon-for-polylang' ),
								'submit'       => __( 'Submit & Deactivate', 'duplicate-content-addon-for-polylang' ),
								'skip'         => __( 'Skip & Deactivate', 'duplicate-content-addon-for-polylang' ),
								'deactivating' => __( 'Deactivating…', 'duplicate-content-addon-for-polylang' ),
								'pick_reason'  => __( 'Please choose a reason.', 'duplicate-content-addon-for-polylang' ),
								'close_label'  => __( 'Close', 'duplicate-content-addon-for-polylang' ),
								/* translators: %s: company name. */
								'byline'       => __( 'A plugin by %s', 'duplicate-content-addon-for-polylang' ),
								'consent'      => __( 'Submitting shares your reason plus your site URL, admin email and basic environment details (PHP, WordPress, active plugins). Skip & Deactivate sends nothing.', 'duplicate-content-addon-for-polylang' ),
							),
						)
					);

				}

				add_action(
					'admin_notices',
					static function () {
						$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
						if ( ! $screen || empty( $screen->id ) ) {
							return;
						}
						$slug = class_exists( 'DUPCAP_Admin' ) ? DUPCAP_Admin::PAGE : 'translation-inspector-polylang';
						if ( false === strpos( (string) $screen->id, $slug ) ) {
							return;
						}
						if ( class_exists( 'CPFM_Review_Notice' ) ) {
							remove_action( 'admin_notices', array( 'CPFM_Review_Notice', 'cpfm_maybe_render' ), 10 );
						}
					},
					0
				);
			}

			/**
			 * Whether Polylang free or Pro is available.
			 *
			 * @return bool
			 */
			public static function is_polylang_available() {
				if ( function_exists( 'PLL' ) || function_exists( 'pll_languages_list' ) || defined( 'POLYLANG_VERSION' ) ) {
					return true;
				}

				global $polylang;
				return isset( $polylang );
			}
			

			function dupcap_includes() {

				// Loaded unconditionally: a real WP-Cron run is not an admin request.
				require_once __DIR__ . '/includes/class-dupcap-optin.php';

				if ( is_admin() ) {
					require_once __DIR__ . '/admin/marketing-popup.php';
				}
			}

			function dupcap_init_inspector() {
				if ( ! self::is_polylang_available() ) {
					return;
				}

				// Shared plugin lookups, used by the wizard and the Inspector alike.
				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-plugin-status.php';

				// Onboarding overlay on the Translation Inspector dashboard.
				require_once DUPCAP_DIR_PATH . 'admin/inspector/class-dupcap-onboarding.php';
				DUPCAP_Onboarding::instance();

				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-result.php';
				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-action-links.php';
				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-check.php';
				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-ignored-issues.php';
				// Issue definitions load before the copy reader that delegates to them.
				require_once DUPCAP_INSPECTOR_DIR . 'issues/class-dupcap-issue-types.php';
				require_once DUPCAP_INSPECTOR_DIR . 'issues/class-dupcap-issue-registry.php';
				require_once DUPCAP_INSPECTOR_DIR . 'issues/class-dupcap-issue-normalizer.php';
				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-issue-copy.php';
				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-scanner.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-copy.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-health.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-environment.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-ignored.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-menus.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-content-pending.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-detail-tables.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-elementor.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-issue-card.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-actions.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-sections.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-notices.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-layout.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-results.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-saved-report.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-rescan-issue.php';
				require_once DUPCAP_INSPECTOR_DIR . 'dashboard/trait-dupcap-dashboard-ajax.php';
				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-dashboard.php';
				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-dashboard-adapter.php';
				require_once DUPCAP_INSPECTOR_DIR . 'class-dupcap-ajax.php';
				require_once DUPCAP_INSPECTOR_DIR . 'routing/class-dupcap-routing-target.php';
				require_once DUPCAP_INSPECTOR_DIR . 'routing/class-dupcap-routing-request.php';
				require_once DUPCAP_INSPECTOR_DIR . 'routing/class-dupcap-routing-scanner.php';
				require_once DUPCAP_INSPECTOR_DIR . 'permalinks/class-dupcap-permalink-diagnostics.php';
				DUPCAP_Routing_Scanner::init();
				require_once DUPCAP_INSPECTOR_DIR . 'elementor/class-dupcap-elementor-finding.php';
				require_once DUPCAP_INSPECTOR_DIR . 'elementor/class-dupcap-elementor-fields.php';
				require_once DUPCAP_INSPECTOR_DIR . 'elementor/class-dupcap-elementor-document.php';
				require_once DUPCAP_INSPECTOR_DIR . 'elementor/class-dupcap-elementor-scanner.php';
				require_once DUPCAP_INSPECTOR_DIR . 'elementor/class-dupcap-elementor-insights.php';
				require_once DUPCAP_INSPECTOR_DIR . 'checks/class-dupcap-check-content.php';
				DUPCAP_Check_Content::register_hooks();
				require_once DUPCAP_DIR_PATH . 'admin/inspector/class-dupcap-admin.php';

				// The admin page stays registered even when the Inspector is off:
				// it is where setup is re-run, so removing it would lock the user
				// out of switching it back on.
				DUPCAP_Admin::instance();

				if ( dupcap_is_tool_enabled( 'inspector' ) ) {
					DUPCAP_Dashboard_Adapter::init();
					// Priority 20 on dupcap_dashboard_results, after the adapter's 10.
					DUPCAP_Issue_Normalizer::init();
					DUPCAP_Ajax::instance();
				}
			}

			function dupcap_init() {
				// Check Polylang plugin is installed and active
				global $polylang;

				if ( isset( $polylang ) ) {
					// Switched off in setup: none of the duplication code runs.
					if ( dupcap_is_tool_enabled( 'duplicate' ) ) {
						require_once DUPCAP_DIR_PATH . 'includes/duplicate/class-dupcap-duplicate-content.php';
						DUPCAP_Duplicate_Content::get_instance()->init();
					}
				} else {
					add_action( 'admin_notices', array( self::$instance, 'dupcap_plugin_required_admin_notice' ) );
				}
			}

			function dupcap_plugin_required_admin_notice() {

				if ( current_user_can( 'activate_plugins' ) ) {
						$url         = 'plugin-install.php?tab=plugin-information&plugin=polylang&TB_iframe=true';
						$title       = 'Polylang';
						$plugin_info = get_plugin_data( __FILE__, true, true );
						echo '<div class="error"><p>' .
						wp_kses_post(
							sprintf(
							/* translators: 1: Current plugin name, 2: Polylang plugin install URL, 3: Polylang plugin title attribute, 4: Polylang plugin name */
							__(
								'In order to use <strong>%1$s</strong> plugin, please install and activate the latest version  of <a href="%2$s" class="thickbox" title="%3$s">%4$s</a>',
								'duplicate-content-addon-for-polylang'
							),
							esc_html( $plugin_info['Name'] ),
							esc_url( $url ),
							esc_attr( $title ),
							esc_attr( $title )
							)
						) . '.</p></div>';

							if ( function_exists( 'deactivate_plugins' ) ) {
								deactivate_plugins( __FILE__ );
							}
				}
			}

			public function dupcap_plugin_action_links( $links ) {
				if ( ! self::is_polylang_available() ) {
					return $links;
				}

				$dashboard_url = class_exists( 'DUPCAP_Admin' )
					? DUPCAP_Admin::page_url()
					: admin_url( 'admin.php?page=translation-inspector-polylang' );

				$custom_links = array(
					'<a href="' . esc_url( $dashboard_url ) . '">' . esc_html__( 'Dashboard', 'duplicate-content-addon-for-polylang' ) . '</a>',
				);

				$links = array_merge( $custom_links, $links );

				if ( ! defined( 'ATFPP_V' ) ) {
					$links[] = '<a href="https://coolplugins.net/product/autopoly-ai-translation-for-polylang/?utm_source=pdca_plugin&utm_medium=inside&utm_campaign=view_plugin&utm_content=plugins_list" target="_blank" style="font-weight:bold; color:#852636;">' . __( 'AI Translation', 'duplicate-content-addon-for-polylang' ) . '</a>';
				}

				return $links;
			}


			/*
			|----------------------------------------------------------------------------
			| Run when activate plugin.
			|----------------------------------------------------------------------------
			*/
			public static function dupcap_activate() {
				update_option( 'dupcap-v', DUPCAP_VERSION );
				update_option( 'dupcap-type', 'FREE' );

				if ( ! get_option( 'dupcap-installDate' ) ) {
					add_option( 'dupcap-installDate', gmdate( 'Y-m-d h:i:s' ), '', false );
				}

				if ( false === get_option( 'dupcap-ratingDiv', false ) ) {
					add_option( 'dupcap-ratingDiv', 'no', '', false );
				}

				if ( ! get_option( 'dupcap_initial_save_version' ) ) {
					add_option( 'dupcap_initial_save_version', DUPCAP_VERSION, '', false );
				}

				if ( class_exists( 'DUPCAP_Optin' ) ) {
					DUPCAP_Optin::maybe_schedule_on_activation();
				}

				// Onboarding only after Polylang is available — first install included.
				if ( ! self::is_polylang_available() ) {
					return;
				}

				require_once DUPCAP_DIR_PATH . 'admin/inspector/class-dupcap-onboarding.php';
				if ( '1' !== get_option( DUPCAP_Onboarding::OPTION_KEY, '' ) ) {
					DUPCAP_Onboarding::flag_redirect();
				}
			}

			/*
			|----------------------------------------------------------------------------
			| Run when de-activate plugin.
			|----------------------------------------------------------------------------
			*/
			public static function dupcap_deactivate() {
				// Stop the usage snapshot; consent itself is left untouched so
				// reactivating does not silently re-ask a question already answered.
				if ( class_exists( 'DUPCAP_Optin' ) ) {
					wp_clear_scheduled_hook( DUPCAP_Optin::CRON_HOOK );
				}
			}

		}

}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound 
function duplicateContentAddon() {
	return duplicateContentAddon::get_instance();
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$duplicateContentAddon = duplicateContentAddon();

// Register Toolkit Hub as early as file load so a newer copy boots before
 // older siblings that still require the class on plugins_loaded 10/20.
if ( is_admin() && defined( 'DUPCAP_DIR_PATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Shared hub loader path; $tfp_ matches hub identity.
	$tfp_hub_load = DUPCAP_DIR_PATH . 'admin/toolkit-hub/load-tfp-toolkit-hub.php';
	if ( file_exists( $tfp_hub_load ) ) {
		require_once $tfp_hub_load;
		tfp_toolkit_hub_register(
			'1.0.3',
			DUPCAP_DIR_PATH . 'admin/toolkit-hub/class-tfp-toolkit-hub.php',
			array(
				'text_domain' => 'duplicate-content-addon-for-polylang',
				'support_url' => 'https://wordpress.org/support/plugin/duplicate-content-addon-for-polylang/',
				'docs_url'    => 'https://wordpress.org/plugins/duplicate-content-addon-for-polylang/',
			)
		);
	}
}


