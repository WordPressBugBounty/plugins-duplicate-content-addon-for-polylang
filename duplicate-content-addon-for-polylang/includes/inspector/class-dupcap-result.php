<?php
/**
 * A single check result.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Result {

	const PASS     = 'pass';
	const INFO     = 'info';
	const WARNING  = 'warning';
	const CRITICAL = 'critical';

	/** @var string */
	public $id;

	/** @var string One of pass|warning|critical. */
	public $status;

	/** @var string Short category label, e.g. "Language Setup". */
	public $group;

	/** @var string Human title, e.g. "French homepage missing". */
	public $label;

	/** @var string One-line plain-English explanation. */
	public $message = '';

	/** @var string Optional admin URL the user can click to fix it. */
	public $fix_url = '';

	/** @var string Optional label for the fix link. */
	public $fix_label = '';

	/** @var array Optional structured detail rows for grouped dashboard views. */
	public $details = array();

	/** @var bool Whether detail rows were capped during collection. */
	public $details_truncated = false;

	/**
	 * @param string $id      Unique id.
	 * @param string $status  pass|warning|critical.
	 * @param string $group   Category.
	 * @param string $label   Title.
	 * @param string $message Explanation.
	 */
	public function __construct( $id, $status, $group, $label, $message = '' ) {
		$this->id      = $id;
		$this->status  = $status;
		$this->group   = $group;
		$this->label   = $label;
		$this->message = $message;
	}

	/**
	 * Add a fix link.
	 *
	 * @param string $url   Admin URL.
	 * @param string $label Link text.
	 * @return DUPCAP_Result
	 */
	public function with_fix( $url, $label = '' ) {
		$this->fix_url   = $url;
		$this->fix_label = $label ? $label : __( 'Fix', 'duplicate-content-addon-for-polylang' );
		return $this;
	}

	/**
	 * Attach structured detail rows (e.g. pending posts table).
	 *
	 * @param array $details Detail rows.
	 * @return DUPCAP_Result
	 */
	public function with_details( array $details ) {
		$this->details = $details;
		return $this;
	}

	/**
	 * Export for JSON.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'        => $this->id,
			'status'    => $this->status,
			'group'     => $this->group,
			'label'     => $this->label,
			'message'   => $this->message,
			'fix_url'   => $this->fix_url,
			'fix_label' => $this->fix_label,
			'details'           => $this->details,
			'details_truncated' => $this->details_truncated,
		);
	}
}
