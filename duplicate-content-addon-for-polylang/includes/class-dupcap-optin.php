<?php
/**
 * Usage data sharing opt-in.
 *
 * Owns three things and nothing else:
 *
 * 1. Consent. The shared Cool Translations family flag
 *    `cpfm_opt_in_choice_cool_translations` is the single source of truth, so
 *    this plugin, Linguator and the CPFM opt-in popup can never disagree on
 *    one site. No per-plugin override option.
 * 2. The 30-day usage cron, registered with the shared CPFM_Usage_Cron module.
 * 3. `dupcap_onboarding_data` — the true/false snapshot of which tools the user
 *    set up, sent inside the payload's `extra_details.onboarding_data`.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns consent, the usage cron and the tool snapshot.
 */
class DUPCAP_Optin {

	/** @var DUPCAP_Optin|null */
	private static $instance = null;

	/**
	 * Whether the cron state has already been reconciled this request.
	 *
	 * Consent changes at most once per request, but several paths react to that
	 * one change - save_choice(), the add_option_/update_option_ listeners and
	 * the CPFM opt-in hook can all fire for a single click. Without this the
	 * snapshot would be POSTed two or three times over.
	 *
	 * @var bool
	 */
	private static $cron_state_applied = false;

	/**
	 * Consent flag.
	 *
	 * Shared across the Cool Translations plugin family. Written by
	 * the CPFM opt-in popup, the setup wizard and the dashboard setting alike.
	 */
	const CONSENT_OPTION = 'cpfm_opt_in_choice_cool_translations';

	/** CPFM consent category this plugin belongs to. */
	const CONSENT_CATEGORY = 'cool_translations';

	/**
	 * This plugin's own copy of the answer.
	 *
	 * The family flag is shared, so a sibling plugin's popup can turn it on for
	 * everyone. CPFM_Usage_Cron reads this per-plugin flag as an override, and
	 * AutoPoly keeps the same one under atfp_feedback_opt_in. It always mirrors
	 * the family flag; it is written wherever consent changes, never on its own.
	 */
	const OVERRIDE_OPTION = 'dupcap_feedback_opt_in';

	/** Derived tool snapshot: translation_inspector / duplicate_content => bool. */
	const ONBOARDING_DATA_OPTION = 'dupcap_onboarding_data';

	/** This plugin's own usage cron hook. */
	const CRON_HOOK = 'dupcap_extra_data_update';

	/** Nonce action for the dashboard setting. */
	const NONCE_ACTION = 'dupcap_save_optin';

	/**
	 * Singleton accessor.
	 *
	 * @return DUPCAP_Optin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the AJAX handler, the CPFM popup follow-ups and the usage cron.
	 */
	private function __construct() {
		add_action( 'wp_ajax_dupcap_save_optin', array( $this, 'ajax_save' ) );

		// The CPFM popup writes the same flag. Follow it so the cron state and
		// the snapshot stay correct however the user answered.
		add_action( 'cpfm_after_opt_in_dupcap', array( $this, 'after_cpfm_choice' ) );
		add_action( 'cpfm_after_opt_out_dupcap', array( $this, 'after_cpfm_choice' ) );

		// The flag is family-wide: a sibling plugin's settings screen can change it
		// without firing OUR cpfm_after_opt_*_dupcap hook. Watch the option itself so
		// our cron follows the answer whoever recorded it.
		add_action( 'add_option_' . self::CONSENT_OPTION, array( $this, 'on_consent_added' ), 10, 2 );
		add_action( 'update_option_' . self::CONSENT_OPTION, array( $this, 'on_consent_updated' ), 10, 2 );

		$this->register_cron();

		// Sites that answered before this flag existed still need it, or the
		// cron's override would read as "no answer" next to a family flag that
		// says yes. Writes at most once: the value is compared first.
		self::sync_override_option();
	}

	/**
	 * Whether the CPFM panel was put on this screen for this request.
	 *
	 * Set by the plugin file when it registers the notice. It does not register
	 * on every screen - the setup wizard holds it back - so this is the only
	 * honest way to know whether the panel is actually up.
	 *
	 * @var bool
	 */
	private static $notice_registered = false;

	/**
	 * Record that the CPFM panel was registered for this screen.
	 *
	 * @return void
	 */
	public static function mark_notice_registered() {
		self::$notice_registered = true;
	}

	/**
	 * Is the CPFM panel asking the question right now?
	 *
	 * True only when it is both on this screen and still unanswered - the panel
	 * retires itself the moment an answer exists. The dashboard setting reads
	 * this so the two never ask at once.
	 *
	 * @return bool
	 */
	public static function popup_is_asking() {
		return self::$notice_registered && ! self::has_choice();
	}

	/**
	 * Has the user answered the opt-in question at all?
	 *
	 * @return bool
	 */
	public static function has_choice() {
		return false !== get_option( self::CONSENT_OPTION, false );
	}

	/**
	 * Is usage data sharing on right now?
	 *
	 * Strict: anything other than a recorded "yes" means no data leaves the
	 * site. An unanswered question is not consent.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::CONSENT_OPTION );
	}

	/**
	 * Record the user's answer, refresh the snapshot, and start or stop the cron.
	 *
	 * @param bool $enabled True to share data.
	 * @return void
	 */
	public static function save_choice( $enabled ) {
		$enabled = (bool) $enabled;

		update_option( self::CONSENT_OPTION, $enabled ? 'yes' : 'no' );

		self::sync_onboarding_data();
		self::apply_cron_state();
		self::broadcast_choice( $enabled );
	}

	/**
	 * Tell the rest of the family, the way the popup does.
	 *
	 * The flag is shared, but each sibling keeps its own mirror of it and its
	 * own cron, and both are set from cpfm_after_opt_in_{id}. The popup fires
	 * that for every plugin registered in the category; writing the option
	 * ourselves skipped it, so an AutoPoly installed by the setup wizard was
	 * left with no atfp_feedback_opt_in and no scheduled event even though the
	 * user had just said yes.
	 *
	 * @param bool $enabled True when the user consented.
	 * @return void
	 */
	private static function broadcast_choice( $enabled ) {
		foreach ( self::consent_sibling_ids() as $id ) {
			if ( $enabled ) {
				// The CPFM module owns these hook names; siblings listen on them.
				do_action( 'cpfm_after_opt_in_' . $id, self::CONSENT_CATEGORY ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			} else {
				do_action( 'cpfm_after_opt_out_' . $id, self::CONSENT_CATEGORY ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			}
		}
	}

	/**
	 * Ids of the plugins that share this consent flag.
	 *
	 * Read from CPFM_Usage_Cron's own register, because the id it is keyed by
	 * is the same id the cpfm_after_opt_*_ hooks are named after, and each
	 * entry records which family master option it follows - so a plugin from a
	 * different Cool Plugins family is never signalled by our answer.
	 *
	 * The register is private to a module this plugin only vendors a copy of,
	 * and a sibling's older copy may win the class name. Every failure here is
	 * silent: no broadcast is the behaviour we had before.
	 *
	 * @return array<int, string>
	 */
	private static function consent_sibling_ids() {
		// property_exists() rather than a try/catch: an older copy of the module
		// from a sibling plugin may win the class name and not have this at all.
		if ( ! class_exists( 'CPFM_Usage_Cron' ) || ! property_exists( 'CPFM_Usage_Cron', 'plugins' ) ) {
			return array();
		}

		$property = new ReflectionProperty( 'CPFM_Usage_Cron', 'plugins' );
		$property->setAccessible( true );
		$registered = (array) $property->getValue();
		$ids        = array();

		foreach ( $registered as $id => $config ) {
			$id     = sanitize_key( (string) $id );
			$master = ( is_array( $config ) && isset( $config['consent_master_option'] ) )
				? (string) $config['consent_master_option']
				: '';

			if ( '' !== $id && self::CONSENT_OPTION === $master ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The shared flag was added for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 * @return void
	 */
	public function on_consent_added( $option, $value ) {
		unset( $option, $value );
		self::sync_onboarding_data();
		self::apply_cron_state();
	}

	/**
	 * The shared flag changed.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public function on_consent_updated( $old_value, $value ) {
		unset( $old_value, $value );
		self::sync_onboarding_data();
		self::apply_cron_state();
	}

	/**
	 * Re-sync after the shared CPFM popup recorded an answer.
	 *
	 * @param string $category CPFM consent category.
	 * @return void
	 */
	public function after_cpfm_choice( $category = '' ) {
		if ( self::CONSENT_CATEGORY !== $category ) {
			return;
		}

		self::sync_onboarding_data();
		self::apply_cron_state();
	}

	/**
	 * Schedule and fire the usage cron when consent is present, clear it when
	 * it is not. Firing immediately is what makes an opt-in on the setup
	 * wizard's Continue button send that first snapshot straight away.
	 *
	 * @return void
	 */
	public static function apply_cron_state() {
		// Written before the CPFM_Usage_Cron guard below: the flag has to follow
		// the answer even on a request where the cron module is not loaded.
		self::sync_override_option();

		if ( self::$cron_state_applied || ! class_exists( 'CPFM_Usage_Cron' ) ) {
			return;
		}

		self::$cron_state_applied = true;

		if ( ! self::is_enabled() ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		CPFM_Usage_Cron::cpfm_schedule_event( self::CRON_HOOK );
		// Hook name is the dupcap_-prefixed CRON_HOOK constant above.
		do_action( self::CRON_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Keep this plugin's flag equal to the family answer.
	 *
	 * A mirror, never a second opinion. CPFM_Usage_Cron lets the per-plugin
	 * override beat the family flag, so an override left behind after the
	 * family answer went away would keep sending data for consent nobody had
	 * given - it is removed with the answer it copied.
	 *
	 * @return void
	 */
	public static function sync_override_option() {
		if ( ! self::has_choice() ) {
			if ( false !== get_option( self::OVERRIDE_OPTION, false ) ) {
				delete_option( self::OVERRIDE_OPTION );
			}
			return;
		}

		$value = self::is_enabled() ? 'yes' : 'no';

		if ( get_option( self::OVERRIDE_OPTION ) !== $value ) {
			update_option( self::OVERRIDE_OPTION, $value );
		}
	}

	/**
	 * Restore the schedule on activation when consent is already on file.
	 *
	 * Deactivation clears the event, so without this a deactivate/reactivate
	 * cycle would silently stop the snapshot until the user touched the setting
	 * again. Schedules only - unlike an opt-in, reactivating is not a fresh
	 * answer, so it does not send anything on the spot.
	 *
	 * @return void
	 */
	public static function maybe_schedule_on_activation() {
		if ( ! self::is_enabled() ) {
			return;
		}

		// register_activation_hook() runs before our plugins_loaded boot, so the
		// cron module (and its every_30_days schedule) may not be wired yet.
		self::instance();

		if ( class_exists( 'CPFM_Usage_Cron' ) ) {
			CPFM_Usage_Cron::cpfm_schedule_event( self::CRON_HOOK );
		}
	}

	/**
	 * Write the true/false snapshot the cron sends as
	 * `extra_details.onboarding_data`.
	 *
	 * Derived from the wizard's tool checkboxes. No recorded choice means the
	 * wizard's own default — both tools on — so the snapshot matches what the
	 * user was shown.
	 *
	 * @return void
	 */
	public static function sync_onboarding_data() {
		$tools    = self::saved_tools();
		$answered = ! empty( $tools );

		update_option(
			self::ONBOARDING_DATA_OPTION,
			array(
				'translation_inspector' => $answered ? in_array( 'inspector', $tools, true ) : true,
				'duplicate_content'     => $answered ? in_array( 'duplicate', $tools, true ) : true,
			),
			false
		);
	}

	/**
	 * Tools the user picked in the setup wizard.
	 *
	 * @return array<int, string>
	 */
	private static function saved_tools() {
		if ( ! class_exists( 'DUPCAP_Onboarding' ) ) {
			$file = DUPCAP_DIR_PATH . 'admin/inspector/class-dupcap-onboarding.php';
			if ( ! file_exists( $file ) ) {
				return array();
			}
			require_once $file;
		}

		$tools = get_option( DUPCAP_Onboarding::TOOLS_OPTION_KEY, array() );

		return is_array( $tools ) ? $tools : array();
	}

	/**
	 * Register this plugin's usage cron with the shared CPFM module.
	 *
	 * The CPFM loader is admin-only by design, but a real WP-Cron run is not an
	 * admin request — so the cron module is required directly here, as its own
	 * loader docblock instructs, or the scheduled event would fire with no
	 * handler attached.
	 *
	 * @return void
	 */
	private function register_cron() {
		if ( ! class_exists( 'CPFM_Usage_Cron' ) ) {
			$loader = DUPCAP_DIR_PATH . 'admin/cpfm-feedback/class-cpfm-loader.php';
			$cron   = DUPCAP_DIR_PATH . 'admin/cpfm-feedback/cron/class-cron.php';

			// The loader file only defines CPFM_DIR / CPFM_URL and the loader
			// class; it wires no hooks until CPFM_Loader::load() is called.
			if ( ! file_exists( $loader ) || ! file_exists( $cron ) ) {
				return;
			}

			require_once $loader;
			require_once $cron;
		}

		if ( ! class_exists( 'CPFM_Usage_Cron' ) ) {
			return;
		}

		CPFM_Usage_Cron::cpfm_register(
			array(
				'id'                      => 'dupcap',
				'plugin_name'             => defined( 'DUPCAP_PLUGIN_NAME' ) ? DUPCAP_PLUGIN_NAME : 'Toolkit for Polylang',
				'version'                 => defined( 'DUPCAP_VERSION' ) ? DUPCAP_VERSION : '',
				'api'                     => defined( 'DUPCAP_FEEDBACK_API' ) ? DUPCAP_FEEDBACK_API : 'https://feedback.coolplugins.net/',
				'cron_hook'               => self::CRON_HOOK,
				'consent_override_option' => self::OVERRIDE_OPTION,
				'consent_master_option'   => self::CONSENT_OPTION,
				'install_date_option'     => 'dupcap-installDate',
				'initial_version_option'  => 'dupcap_initial_save_version',
				'onboarding_data'         => self::ONBOARDING_DATA_OPTION,
				'site_key'                => 'dupcap',
			)
		);
	}

	/**
	 * Save the dashboard setting.
	 *
	 * @return void
	 */
	public function ajax_save() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to change this setting.', 'duplicate-content-addon-for-polylang' ) ),
				403
			);
		}

		$raw     = isset( $_POST['opt_in'] ) ? sanitize_key( wp_unslash( $_POST['opt_in'] ) ) : '';
		$enabled = in_array( $raw, array( 'yes', '1', 'true' ), true );

		self::save_choice( $enabled );

		wp_send_json_success(
			array(
				'enabled' => $enabled,
				'message' => $enabled
					? __( 'Saved. Thanks for helping us improve the plugin.', 'duplicate-content-addon-for-polylang' )
					: __( 'Saved. No site data will be shared.', 'duplicate-content-addon-for-polylang' ),
			)
		);
	}
}
