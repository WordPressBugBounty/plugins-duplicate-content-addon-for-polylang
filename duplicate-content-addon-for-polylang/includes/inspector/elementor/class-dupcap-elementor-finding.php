<?php
/**
 * A single Elementor scanner finding.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

class DUPCAP_Elementor_Finding {

	const CRITICAL = 'critical';
	const MAJOR    = 'major';
	const WARNING  = 'warning';
	const INFO     = 'info';

	/** @var string */
	public $severity;

	/** @var string */
	public $code;

	/** @var int */
	public $template_id = 0;

	/** @var string */
	public $template_title = '';

	/** @var string */
	public $template_type = '';

	/** @var string */
	public $language = '';

	/** @var string */
	public $widget_type = '';

	/** @var string */
	public $widget_id = '';

	/** @var string */
	public $field = '';

	/** @var string */
	public $what = '';

	/** @var string */
	public $why = '';

	/** @var string */
	public $fix = '';

	/** @var string */
	public $original_value = '';

	/** @var string */
	public $translated_value = '';

	/** @var string */
	public $fix_url = '';

	/** @var string */
	public $fix_label = '';

	/** @var string */
	public $context_label = '';

	/** @var string */
	public $suggested_url = '';

	/** @var string */
	public $nest_type = '';

	/** @var string */
	public $nest_label = '';

	/** @var string */
	public $ignore_key = '';

	/**
	 * @param string $severity Severity.
	 * @param string $code     Machine code.
	 * @param string $what     What is wrong.
	 */
	public function __construct( $severity, $code, $what ) {
		$this->severity = $severity;
		$this->code     = $code;
		$this->what     = $what;
	}

	/**
	 * Attach or compute ignore key.
	 *
	 * @param string $key Ignore key or empty to auto-generate.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_ignore_key( $key = '' ) {
		if ( '' !== (string) $key ) {
			$this->ignore_key = (string) $key;
		} elseif ( class_exists( 'DUPCAP_Ignored_Issues' ) ) {
			$this->ignore_key = DUPCAP_Ignored_Issues::elementor_key(
				$this->code,
				$this->template_id,
				$this->language,
				$this->widget_id,
				$this->field,
				$this->original_value
			);
		}
		return $this;
	}

	/**
	 * Attach template context.
	 *
	 * @param int    $id    Post ID.
	 * @param string $title Title.
	 * @param string $type  Document type.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_template( $id, $title, $type ) {
		$this->template_id    = (int) $id;
		$this->template_title = (string) $title;
		$this->template_type  = (string) $type;
		return $this;
	}

	/**
	 * Attach language.
	 *
	 * @param string $language Language slug.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_language( $language ) {
		$this->language = (string) $language;
		return $this;
	}

	/**
	 * Attach widget context.
	 *
	 * @param string $type Widget type.
	 * @param string $id   Element ID.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_widget( $type, $id ) {
		$this->widget_type = (string) $type;
		$this->widget_id   = (string) $id;
		return $this;
	}

	/**
	 * Attach field and values.
	 *
	 * @param string $field            Field key.
	 * @param string $original_value   Original value.
	 * @param string $translated_value Translated value.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_field( $field, $original_value = '', $translated_value = '' ) {
		$this->field            = (string) $field;
		$this->original_value   = (string) $original_value;
		$this->translated_value = (string) $translated_value;
		return $this;
	}

	/**
	 * Attach explanation and fix.
	 *
	 * @param string $why Why it is wrong.
	 * @param string $fix How to fix.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_fix_text( $why, $fix ) {
		$this->why = (string) $why;
		$this->fix = (string) $fix;
		return $this;
	}

	/**
	 * Attach admin fix link.
	 *
	 * @param string $url   URL.
	 * @param string $label Label.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_fix_link( $url, $label = '' ) {
		$this->fix_url   = (string) $url;
		$this->fix_label = $label ? (string) $label : __( 'Fix', 'duplicate-content-addon-for-polylang' );
		return $this;
	}

	/**
	 * Attach nearby label (menu item text, button label, etc.).
	 *
	 * @param string $label Context label.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_context( $label ) {
		$this->context_label = (string) $label;
		return $this;
	}

	/**
	 * Attach suggested translated URL.
	 *
	 * @param string $url Suggested URL.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_suggested_url( $url ) {
		$this->suggested_url = (string) $url;
		return $this;
	}

	/**
	 * Attach nest location (mega menu dropdown, off-canvas, etc.).
	 *
	 * @param string $type  Nest widget type.
	 * @param string $label Menu item or panel label.
	 * @return DUPCAP_Elementor_Finding
	 */
	public function with_nest( $type, $label = '' ) {
		$this->nest_type  = (string) $type;
		$this->nest_label = (string) $label;
		return $this;
	}

	/**
	 * Export for JSON / rendering.
	 *
	 * @return array
	 */
	public function to_array() {
		if ( empty( $this->ignore_key ) && class_exists( 'DUPCAP_Ignored_Issues' ) ) {
			$this->with_ignore_key();
		}

		return array(
			'severity'         => $this->severity,
			'code'             => $this->code,
			'template_id'      => $this->template_id,
			'template_title'   => $this->template_title,
			'template_type'    => $this->template_type,
			'language'         => $this->language,
			'widget_type'      => $this->widget_type,
			'widget_id'        => $this->widget_id,
			'field'            => $this->field,
			'what'             => $this->what,
			'why'              => $this->why,
			'fix'              => $this->fix,
			'original_value'   => $this->original_value,
			'translated_value' => $this->translated_value,
			'fix_url'          => $this->fix_url,
			'fix_label'        => $this->fix_label,
			'context_label'    => $this->context_label,
			'suggested_url'    => $this->suggested_url,
			'nest_type'        => $this->nest_type,
			'nest_label'       => $this->nest_label,
			'ignore_key'       => $this->ignore_key,
		);
	}
}

