<?php
/**
 * Handles dashboard AJAX requests.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Ajax {

	/** @var DUPCAP_Ajax|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_dupcap_rescan_dashboard', array( $this, 'handle_rescan' ) );
		add_action( 'wp_ajax_dupcap_ignore_issue', array( $this, 'handle_ignore' ) );
		add_action( 'wp_ajax_dupcap_unignore_issue', array( $this, 'handle_unignore' ) );
		add_action( 'wp_ajax_dupcap_rescan_issue', array( $this, 'handle_rescan_issue' ) );
		add_action( 'wp_ajax_dupcap_rescan_content_issue', array( $this, 'handle_rescan_content_issue' ) );
		add_action( 'wp_ajax_dupcap_rescan_elementor_library_issue', array( $this, 'handle_rescan_elementor_library_issue' ) );
		add_action( 'wp_ajax_dupcap_assign_default_lang', array( $this, 'handle_assign_default_lang' ) );
		add_action( 'wp_ajax_dupcap_scan_routing', array( $this, 'handle_scan_routing' ) );
		add_action( 'wp_ajax_dupcap_scan_section', array( $this, 'handle_scan_section' ) );
	}

	/**
	 * Rescan a single Elementor issue and report whether it's still present.
	 */
	public function handle_rescan_issue() {
		DUPCAP_Dashboard::instance()->handle_rescan_issue();
	}

	/**
	 * Rescan a single content / homepage gap.
	 */
	public function handle_rescan_content_issue() {
		DUPCAP_Dashboard::instance()->handle_rescan_content_issue();
	}

	/**
	 * Rescan one Elementor library type+language gap.
	 */
	public function handle_rescan_elementor_library_issue() {
		DUPCAP_Dashboard::instance()->handle_rescan_elementor_library_issue();
	}

	/**
	 * Re-scan and return refreshed dashboard HTML fragments.
	 */
	public function handle_rescan() {
		DUPCAP_Dashboard::instance()->handle_rescan();
	}

	/**
	 * Ignore a warning and return refreshed dashboard HTML fragments.
	 */
	public function handle_ignore() {
		DUPCAP_Dashboard::instance()->handle_ignore();
	}

	/**
	 * Restore a previously ignored warning and refresh the dashboard.
	 */
	public function handle_unignore() {
		DUPCAP_Dashboard::instance()->handle_unignore();
	}

	/**
	 * Assign the default language to content that has none.
	 */
	public function handle_assign_default_lang() {
		DUPCAP_Dashboard::instance()->handle_assign_default_lang();
	}

	/**
	 * Run the routing / 404 sweep and return refreshed dashboard fragments.
	 */
	public function handle_scan_routing() {
		DUPCAP_Dashboard::instance()->handle_scan_routing();
	}

	/**
	 * Re-scan one dashboard section (content, elementor or site) and return
	 * refreshed dashboard fragments.
	 */
	public function handle_scan_section() {
		DUPCAP_Dashboard::instance()->handle_scan_section();
	}
}
