<?php
/**
 * Sidebar editor panel and "what changed" diff for Block and Classic editors.
 *
 * @package DuplicateContentAddonForPolylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows, inside the Block and Classic editors, whether a translation still matches its
 * source, what changed in the source since the translation was last up to date, and
 * lets the translator mark the translation as up to date.
 *
 * The comparison needs a copy of the source as it was when the translation was last up
 * to date. That copy is a plain-text snapshot kept on the translation post
 * ({@see self::META_SNAPSHOT}). It is taken when a pair is first tracked, when the
 * translation is in sync and the source text moves on, right before a source post is
 * edited, and when "Mark as up to date" is used.
 */
class DUPCAP_Editor_Panel {

	/**
	 * Post meta on the translation: plain-text snapshot of its source at the last sync.
	 *
	 * Shape: v, taken_at, source_id, hash, fields (key => text), labels (key => label), truncated (keys).
	 *
	 * @var string
	 */
	const META_SNAPSHOT = '_dupcap_sync_snapshot';

	/**
	 * Singleton instance.
	 *
	 * @var DUPCAP_Editor_Panel|null
	 */
	private static $instance = null;

	/**
	 * Source IDs already captured in this request.
	 *
	 * @var array<int, bool>
	 */
	private $captured = array();

	/**
	 * Allowed HTML tags for wp_text_diff output.
	 *
	 * @var array
	 */
	private static $diff_allowed_html = array(
		'table'    => array( 'class' => true ),
		'colgroup' => array(),
		'col'      => array( 'class' => true ),
		'thead'    => array(),
		'tbody'    => array(),
		'tr'       => array(),
		'th'       => array(
			'class' => true,
			'scope' => true,
		),
		'td'       => array(
			'class'   => true,
			'colspan' => true,
		),
		'del'      => array(),
		'ins'      => array(),
		'span'     => array(
			'class'       => true,
			'aria-hidden' => true,
		),
	);

	/**
	 * Get singleton instance.
	 *
	 * @return DUPCAP_Editor_Panel
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ), 20, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_classic_assets' ) );

		// Keep a copy of the source as it is right before it changes, so the diff can show the change.
		add_action( 'pre_post_update', array( $this, 'capture_before_source_update' ), 10, 1 );
		add_action( 'elementor/document/before_save', array( $this, 'capture_before_elementor_save' ), 10, 1 );

		add_action( 'wp_ajax_dupcap_editor_get_status', array( $this, 'ajax_get_status' ) );
		add_action( 'wp_ajax_dupcap_editor_get_diff', array( $this, 'ajax_get_diff' ) );
		add_action( 'wp_ajax_dupcap_editor_mark_synced', array( $this, 'ajax_mark_synced' ) );
	}

	/**
	 * Check if a post type is translatable in Polylang.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public function is_tracked_post_type( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( '' === $post_type || in_array( $post_type, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset' ), true ) ) {
			return false;
		}

		if ( function_exists( 'pll_is_translated_post_type' ) ) {
			return (bool) pll_is_translated_post_type( $post_type );
		}

		global $polylang;
		if ( isset( $polylang->model ) && is_callable( array( $polylang->model, 'is_translated_post_type' ) ) ) {
			return (bool) $polylang->model->is_translated_post_type( $post_type );
		}

		return false;
	}

	/**
	 * Check if a post is built with Elementor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_elementor_built( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( 'elementor_library' === get_post_type( $post_id ) ) {
			return true;
		}
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Strings shared by the block editor panel, the classic metabox and the dashboard "View changes" button.
	 *
	 * @return array<string, string>
	 */
	public static function get_i18n() {
		return array(
			'panelTitle'        => __( 'Translation Status', 'duplicate-content-addon-for-polylang' ),
			'loading'           => __( 'Checking translation status…', 'duplicate-content-addon-for-polylang' ),
			'sourceRole'        => __( 'Source (default language)', 'duplicate-content-addon-for-polylang' ),
			'sourceRoleDesc'    => __( 'When you change this post, its translations are marked as outdated.', 'duplicate-content-addon-for-polylang' ),
			'translationsLabel' => __( 'Translations:', 'duplicate-content-addon-for-polylang' ),
			'editTranslation'   => __( 'Edit', 'duplicate-content-addon-for-polylang' ),
			'addTranslation'    => __( '+ Add', 'duplicate-content-addon-for-polylang' ),
			'sourceLabel'       => __( 'Source:', 'duplicate-content-addon-for-polylang' ),
			'changedLabel'      => __( 'What changed in the source:', 'duplicate-content-addon-for-polylang' ),
			'stillSameLabel'    => __( 'Still the same as the source:', 'duplicate-content-addon-for-polylang' ),
			'seeChanges'        => __( 'See what changed', 'duplicate-content-addon-for-polylang' ),
			'markSynced'        => __( 'Mark as up to date', 'duplicate-content-addon-for-polylang' ),
			'markSyncedHint'    => __( 'Use this after you have reviewed the changes and updated the translation.', 'duplicate-content-addon-for-polylang' ),
			'markingSynced'     => __( 'Updating…', 'duplicate-content-addon-for-polylang' ),
			'markedSynced'      => __( 'Translation marked as up to date.', 'duplicate-content-addon-for-polylang' ),
			'markError'         => __( 'The translation could not be updated.', 'duplicate-content-addon-for-polylang' ),
			'loadError'         => __( 'Translation status could not be loaded.', 'duplicate-content-addon-for-polylang' ),
			'diffModalTitle'    => __( 'What changed in the source', 'duplicate-content-addon-for-polylang' ),
			'diffLoading'       => __( 'Loading changes…', 'duplicate-content-addon-for-polylang' ),
			'diffError'         => __( 'The changes could not be loaded.', 'duplicate-content-addon-for-polylang' ),
			'diffEmpty'         => __( 'No text differences were found.', 'duplicate-content-addon-for-polylang' ),
			'partialNote'       => __( 'Only the title and body can be compared. There is no saved earlier copy of the custom fields or page-builder text.', 'duplicate-content-addon-for-polylang' ),
			'truncatedNote'     => __( 'The saved earlier version was long and has been cut short.', 'duplicate-content-addon-for-polylang' ),
			'retry'             => __( 'Try again', 'duplicate-content-addon-for-polylang' ),
			'sideBySide'        => __( 'Side by side', 'duplicate-content-addon-for-polylang' ),
			'inline'            => __( 'Inline', 'duplicate-content-addon-for-polylang' ),
			'openSource'        => __( 'Open source', 'duplicate-content-addon-for-polylang' ),
			'close'             => __( 'Close', 'duplicate-content-addon-for-polylang' ),
			'typeChanged'       => __( 'Changed', 'duplicate-content-addon-for-polylang' ),
			'typeAdded'         => __( 'New', 'duplicate-content-addon-for-polylang' ),
			'typeRemoved'       => __( 'Removed', 'duplicate-content-addon-for-polylang' ),
			/* translators: %s: how long ago, e.g. "2 hours". */
			'sourceChanged'     => __( 'Source changed %s ago', 'duplicate-content-addon-for-polylang' ),
			/* translators: %s: how long ago, e.g. "2 hours". */
			'comparedWith'      => __( 'Compared with the source as it was %s ago.', 'duplicate-content-addon-for-polylang' ),
			/* translators: %d: number of further items that are not listed. */
			'moreItems'         => __( '+ %d more', 'duplicate-content-addon-for-polylang' ),
		);
	}

	/**
	 * Script data for the classic editor script, which the dashboard "View changes" button also uses.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_classic_script_data() {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'dupcap_editor_nonce' ),
			'i18n'    => self::get_i18n(),
		);
	}

	/**
	 * Register Classic Editor sidebar meta box.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post object.
	 * @return void
	 */
	public function register_metabox( $post_type, $post = null ) {
		if ( ! $this->is_tracked_post_type( $post_type ) ) {
			return;
		}

		if ( ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_default_language' ) ) {
			return;
		}

		// Don't show metabox in Gutenberg block editor (block editor uses sidebar panel).
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		add_meta_box(
			'dupcap_translation_status',
			__( 'Translation Status', 'duplicate-content-addon-for-polylang' ),
			array( $this, 'render_metabox' ),
			$post_type,
			'side',
			'high',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Enqueue assets for the Block Editor (Gutenberg).
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || empty( $screen->post_type ) ) {
			return;
		}

		if ( ! $this->is_tracked_post_type( $screen->post_type ) ) {
			return;
		}

		global $post;
		$post_id = $post instanceof WP_Post ? (int) $post->ID : 0;
		if ( $post_id <= 0 ) {
			return;
		}

		$version = defined( 'DUPCAP_VERSION' ) ? DUPCAP_VERSION : '2.1.2';

		// Core revisions CSS provides standard red/green text diff table styling.
		wp_enqueue_style( 'revisions' );

		wp_enqueue_style(
			'dupcap-editor-panel',
			DUPCAP_INSPECTOR_URL . 'css/editor-panel.css',
			array( 'revisions' ),
			$version
		);

		wp_enqueue_script(
			'dupcap-editor-panel',
			DUPCAP_INSPECTOR_URL . 'js/editor-panel.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-a11y' ),
			$version,
			true
		);

		wp_localize_script(
			'dupcap-editor-panel',
			'dupcapEditorData',
			array(
				'postId'   => $post_id,
				'postType' => $screen->post_type,
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'dupcap_editor_nonce' ),
				'i18n'     => self::get_i18n(),
			)
		);
	}

	/**
	 * Enqueue assets for the Classic Editor.
	 *
	 * @param string $hook Admin screen hook.
	 * @return void
	 */
	public function enqueue_classic_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! $this->is_tracked_post_type( $screen->post_type ) ) {
			return;
		}

		if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		$version = defined( 'DUPCAP_VERSION' ) ? DUPCAP_VERSION : '2.1.2';

		wp_enqueue_style( 'revisions' );

		wp_enqueue_style(
			'dupcap-editor-panel',
			DUPCAP_INSPECTOR_URL . 'css/editor-panel.css',
			array( 'revisions' ),
			$version
		);

		wp_enqueue_script(
			'dupcap-classic-editor',
			DUPCAP_INSPECTOR_URL . 'js/classic-editor.js',
			array( 'jquery', 'wp-a11y' ),
			$version,
			true
		);

		wp_localize_script( 'dupcap-classic-editor', 'dupcapClassicData', self::get_classic_script_data() );
	}

	/**
	 * Render Classic Editor sidebar metabox.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render_metabox( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		echo '<div class="dupcap-editor-metabox" data-post-id="' . esc_attr( (string) $post->ID ) . '">';
		$this->render_metabox_body( $post->ID );
		echo '</div>';
	}

	/**
	 * Inner markup of the classic metabox. Also returned by the AJAX handlers so the box can refresh in place.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function render_metabox_body( $post_id ) {
		$post_id = (int) $post_id;
		$status  = $this->get_post_translation_status( $post_id );
		$i18n    = self::get_i18n();

		if ( 'source' === $status['role'] ) {
			?>
			<div class="dupcap-editor-source-header">
				<span class="dupcap-badge dupcap-badge--source">
					<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
					<?php echo esc_html( $i18n['sourceRole'] ); ?>
				</span>
				<p class="description"><?php echo esc_html( $i18n['sourceRoleDesc'] ); ?></p>
			</div>

			<?php if ( ! empty( $status['translations'] ) ) : ?>
				<div class="dupcap-editor-trans-list">
					<strong><?php echo esc_html( $i18n['translationsLabel'] ); ?></strong>
					<ul class="dupcap-editor-trans-items">
						<?php foreach ( $status['translations'] as $t ) : ?>
							<li class="dupcap-editor-trans-item">
								<div class="dupcap-editor-trans-info">
									<?php if ( ! empty( $t['flag_url'] ) ) : ?>
										<img class="dupcap-lang-flag" src="<?php echo esc_url( $t['flag_url'] ); ?>" alt="" width="16" height="11" />
									<?php endif; ?>
									<span class="dupcap-editor-lang-name"><?php echo esc_html( $t['language_name'] ); ?></span>
									<span class="dupcap-pill dupcap-pill--<?php echo esc_attr( $t['status_badge'] ); ?>"><?php echo esc_html( $t['status_label'] ); ?></span>
								</div>
								<div class="dupcap-editor-trans-action">
									<?php if ( ! empty( $t['edit_url'] ) ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $t['edit_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $i18n['editTranslation'] ); ?></a>
									<?php elseif ( ! empty( $t['add_url'] ) ) : ?>
										<a class="button button-small button-primary" href="<?php echo esc_url( $t['add_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $i18n['addTranslation'] ); ?></a>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<?php
			return;
		}

		if ( 'translation' !== $status['role'] ) {
			?>
			<p class="description">
				<?php esc_html_e( 'Set a language in the Polylang box to enable translation status tracking.', 'duplicate-content-addon-for-polylang' ); ?>
			</p>
			<?php
			return;
		}

		$list_limit = 5;
		?>
		<div class="dupcap-editor-trans-header">
			<div class="dupcap-editor-status-row">
				<span class="dupcap-editor-status-badge dupcap-editor-status-badge--<?php echo esc_attr( $status['status_badge'] ); ?>">
					<span class="dashicons dashicons-<?php echo esc_attr( $status['status_icon'] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $status['status_label'] ); ?>
				</span>
			</div>

			<?php if ( ! empty( $status['notice'] ) ) : ?>
				<div class="dupcap-editor-notice dupcap-editor-notice--<?php echo esc_attr( $status['status_badge'] ); ?>">
					<p><?php echo esc_html( $status['notice'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $status['source'] ) ) : ?>
				<p class="dupcap-editor-meta-line">
					<strong><?php echo esc_html( $i18n['sourceLabel'] ); ?></strong>
					<?php if ( ! empty( $status['source']['flag_url'] ) ) : ?>
						<img class="dupcap-lang-flag" src="<?php echo esc_url( $status['source']['flag_url'] ); ?>" alt="" width="16" height="11" />
					<?php endif; ?>
					<a href="<?php echo esc_url( $status['source']['edit_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $status['source']['title'] ); ?></a>
				</p>
				<?php if ( ! empty( $status['source']['modified_human'] ) ) : ?>
					<p class="description"><?php echo esc_html( sprintf( $i18n['sourceChanged'], $status['source']['modified_human'] ) ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! empty( $status['changes'] ) ) : ?>
				<div class="dupcap-editor-changed-fields">
					<strong><?php echo esc_html( $i18n['changedLabel'] ); ?></strong>
					<ul class="dupcap-editor-list">
						<?php foreach ( array_slice( $status['changes'], 0, $list_limit ) as $change ) : ?>
							<li>
								<?php echo esc_html( $change['label'] ); ?>
								<span class="dupcap-change-tag dupcap-change-tag--<?php echo esc_attr( $change['type'] ); ?>"><?php echo esc_html( $i18n[ 'type' . ucfirst( $change['type'] ) ] ); ?></span>
							</li>
						<?php endforeach; ?>
						<?php if ( count( $status['changes'] ) > $list_limit ) : ?>
							<li class="description"><?php echo esc_html( sprintf( $i18n['moreItems'], count( $status['changes'] ) - $list_limit ) ); ?></li>
						<?php endif; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $status['still_same'] ) ) : ?>
				<div class="dupcap-editor-changed-fields">
					<strong><?php echo esc_html( $i18n['stillSameLabel'] ); ?></strong>
					<ul class="dupcap-editor-list">
						<?php foreach ( array_slice( $status['still_same'], 0, $list_limit ) as $label ) : ?>
							<li><?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
						<?php if ( count( $status['still_same'] ) > $list_limit ) : ?>
							<li class="description"><?php echo esc_html( sprintf( $i18n['moreItems'], count( $status['still_same'] ) - $list_limit ) ); ?></li>
						<?php endif; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>

		<div class="dupcap-editor-actions">
			<?php if ( ! empty( $status['has_diff'] ) ) : ?>
				<button type="button" class="button button-primary dupcap-btn-view-diff" data-translation-id="<?php echo esc_attr( (string) $post_id ); ?>">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<?php echo esc_html( $i18n['seeChanges'] ); ?>
				</button>
			<?php endif; ?>

			<?php if ( $status['can_sync'] && 'outdated' === $status['status'] ) : ?>
				<button type="button" class="button button-secondary dupcap-btn-mark-synced" data-translation-id="<?php echo esc_attr( (string) $post_id ); ?>">
					<span class="dashicons dashicons-yes" aria-hidden="true"></span>
					<?php echo esc_html( $i18n['markSynced'] ); ?>
				</button>
				<p class="description"><?php echo esc_html( $i18n['markSyncedHint'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Calculate full translation status for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_post_translation_status( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		$default_data = array(
			'post_id'        => $post_id,
			'post_type'      => $post ? $post->post_type : '',
			'role'           => 'none',
			'lang'           => '',
			'language_name'  => '',
			'flag_url'       => '',
			'status'         => 'unknown',
			'status_label'   => '',
			'status_badge'   => 'info',
			'status_icon'    => 'info',
			'notice'         => '',
			'source'         => null,
			'translations'   => array(),
			'changes'        => array(),
			'changes_known'  => false,
			'partial'        => false,
			'still_same'     => array(),
			'baseline_human' => '',
			'has_diff'       => false,
			'can_sync'       => current_user_can( 'edit_post', $post_id ),
		);

		if ( ! $post || ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_default_language' ) ) {
			return $default_data;
		}

		$lang         = (string) pll_get_post_language( $post_id, 'slug' );
		$default_lang = (string) pll_default_language( 'slug' );

		if ( '' === $lang ) {
			return $default_data;
		}

		$lang_obj                      = function_exists( 'PLL' ) ? PLL()->model->get_language( $lang ) : null;
		$default_data['lang']          = $lang;
		$default_data['language_name'] = ( $lang_obj && ! empty( $lang_obj->name ) ) ? (string) $lang_obj->name : strtoupper( $lang );
		$default_data['flag_url']      = ( $lang_obj && ! empty( $lang_obj->flag_url ) ) ? (string) $lang_obj->flag_url : '';

		$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post_id ) : array();

		// Case 1: Post is the Default Language Source post.
		if ( $lang === $default_lang ) {
			$default_data['role']         = 'source';
			$default_data['status']       = 'source';
			$default_data['status_label'] = __( 'Source', 'duplicate-content-addon-for-polylang' );
			$default_data['status_badge'] = 'pass';

			$all_languages = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => '' ) ) : array();
			$trans_list    = array();

			foreach ( $all_languages as $l ) {
				$slug = is_object( $l ) ? (string) $l->slug : (string) $l;
				if ( $slug === $default_lang ) {
					continue;
				}

				$trans_id = ! empty( $translations[ $slug ] ) ? (int) $translations[ $slug ] : 0;
				$t_obj    = is_object( $l ) ? $l : ( function_exists( 'PLL' ) ? PLL()->model->get_language( $slug ) : null );
				$t_name   = ( $t_obj && ! empty( $t_obj->name ) ) ? (string) $t_obj->name : strtoupper( $slug );
				$t_flag   = ( $t_obj && ! empty( $t_obj->flag_url ) ) ? (string) $t_obj->flag_url : '';

				if ( $trans_id > 0 ) {
					$trans_post          = get_post( $trans_id );
					$t_status            = $this->classify_translation_freshness( $post_id, $trans_id, $slug );
					$trans_list[ $slug ] = array(
						'id'            => $trans_id,
						'title'         => ( $trans_post && $trans_post->post_title ) ? $trans_post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
						'lang'          => $slug,
						'language_name' => $t_name,
						'flag_url'      => $t_flag,
						'status'        => $t_status['status'],
						'status_label'  => $t_status['label'],
						'status_badge'  => $t_status['badge'],
						'edit_url'      => get_edit_post_link( $trans_id, 'raw' ),
					);
				} else {
					$add_url             = wp_specialchars_decode( ( new DUPCAP_Check_Content() )->translation_link( $post, $slug ) );
					$trans_list[ $slug ] = array(
						'id'            => 0,
						'title'         => '',
						'lang'          => $slug,
						'language_name' => $t_name,
						'flag_url'      => $t_flag,
						'status'        => 'missing',
						'status_label'  => __( 'Missing', 'duplicate-content-addon-for-polylang' ),
						'status_badge'  => 'crit',
						'add_url'       => $add_url,
					);
				}
			}

			$default_data['translations'] = $trans_list;
			return $default_data;
		}

		// Case 2: Post is a Secondary Language Translation post.
		$source_id = ! empty( $translations[ $default_lang ] ) ? (int) $translations[ $default_lang ] : 0;
		if ( $source_id <= 0 ) {
			$default_data['role']   = 'translation';
			$default_data['status'] = 'no_source';
			$default_data['notice'] = __( 'This post is not linked to a post in the default language yet, so there is nothing to compare with.', 'duplicate-content-addon-for-polylang' );
			return $default_data;
		}

		$source_post  = get_post( $source_id );
		$src_obj      = function_exists( 'PLL' ) ? PLL()->model->get_language( $default_lang ) : null;
		$src_name     = ( $src_obj && ! empty( $src_obj->name ) ) ? (string) $src_obj->name : strtoupper( $default_lang );
		$src_flag     = ( $src_obj && ! empty( $src_obj->flag_url ) ) ? (string) $src_obj->flag_url : '';
		$src_modified = (int) get_post_modified_time( 'U', true, $source_id );

		$freshness = $this->classify_translation_freshness( $source_id, $post_id, $lang );

		$default_data['role']         = 'translation';
		$default_data['status']       = $freshness['status'];
		$default_data['status_label'] = $freshness['label'];
		$default_data['status_badge'] = $freshness['badge'];
		$default_data['status_icon']  = $freshness['icon'];
		$default_data['notice']       = $freshness['notice'];
		$default_data['still_same']   = $freshness['still_same'];

		if ( 'outdated' === $freshness['status'] ) {
			$info = $this->compute_changes( $source_id, $post_id, $lang );

			$default_data['changes']        = $info['changes'];
			$default_data['changes_known']  = $info['known'];
			$default_data['partial']        = $info['partial'];
			$default_data['has_diff']       = $info['known'] && ! empty( $info['changes'] );
			$default_data['baseline_human'] = $info['baseline_ts'] > 0 ? human_time_diff( $info['baseline_ts'] ) : '';

			if ( ! $info['known'] ) {
				$default_data['notice'] = __( 'The source changed after this translation was last up to date. No saved copy of the earlier source exists, so the exact changes cannot be shown. After you mark it as up to date, future changes will be shown.', 'duplicate-content-addon-for-polylang' );
			} elseif ( empty( $info['changes'] ) ) {
				$default_data['notice'] = __( 'The source was saved after this translation was last up to date, but no text difference was found. If nothing needs translating, mark it as up to date.', 'duplicate-content-addon-for-polylang' );
			} else {
				$default_data['notice'] = __( 'The source changed after this translation was last up to date. Review what changed, update the translation, then mark it as up to date.', 'duplicate-content-addon-for-polylang' );
			}
		} else {
			// In sync (or only untranslated text left): keep the saved copy of the source current.
			$this->save_snapshot( $source_id, $post_id, $lang );
		}

		$default_data['source'] = array(
			'id'             => $source_id,
			'title'          => ( $source_post && $source_post->post_title ) ? $source_post->post_title : __( '(no title)', 'duplicate-content-addon-for-polylang' ),
			'lang'           => $default_lang,
			'language_name'  => $src_name,
			'flag_url'       => $src_flag,
			'edit_url'       => get_edit_post_link( $source_id, 'raw' ),
			'modified'       => $src_modified,
			'modified_human' => $src_modified > 0 ? human_time_diff( $src_modified ) : '',
		);

		return $default_data;
	}

	/**
	 * Classify freshness and untranslated text for a source ↔ translation pair.
	 *
	 * @param int    $source_id      Default-language post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $target_slug    Translation language slug.
	 * @return array{status:string,label:string,badge:string,icon:string,notice:string,still_same:string[]}
	 */
	protected function classify_translation_freshness( $source_id, $translation_id, $target_slug ) {
		$check = new DUPCAP_Check_Content();

		$ref_sync = new ReflectionMethod( 'DUPCAP_Check_Content', 'sync_content_change_marker_fresh' );
		$ref_sync->setAccessible( true );

		$default_ts = (int) $ref_sync->invoke( $check, $source_id );
		$target_ts  = (int) $ref_sync->invoke( $check, $translation_id );

		$ref_fresh = new ReflectionMethod( 'DUPCAP_Check_Content', 'classify_content_freshness_pair' );
		$ref_fresh->setAccessible( true );

		$verdict    = (string) $ref_fresh->invoke( $check, $source_id, $target_slug, $default_ts, $target_ts );
		$still_same = $this->find_still_same( $check, $source_id, $translation_id, $target_slug );

		if ( 'source_updated' === $verdict ) {
			return array(
				'status'     => 'outdated',
				'label'      => __( 'Outdated', 'duplicate-content-addon-for-polylang' ),
				'badge'      => 'warn',
				'icon'       => 'warning',
				'notice'     => __( 'The source changed after this translation was last up to date.', 'duplicate-content-addon-for-polylang' ),
				'still_same' => $still_same,
			);
		}

		if ( ! empty( $still_same ) ) {
			return array(
				'status'     => 'identical',
				'label'      => __( 'Untranslated text', 'duplicate-content-addon-for-polylang' ),
				'badge'      => 'warn',
				'icon'       => 'warning',
				'notice'     => __( 'This translation is up to date, but some text is still the same as the source language.', 'duplicate-content-addon-for-polylang' ),
				'still_same' => $still_same,
			);
		}

		return array(
			'status'     => 'in_sync',
			'label'      => __( 'Up to date', 'duplicate-content-addon-for-polylang' ),
			'badge'      => 'pass',
			'icon'       => 'yes-alt',
			'notice'     => __( 'This translation matches the source.', 'duplicate-content-addon-for-polylang' ),
			'still_same' => array(),
		);
	}

	/**
	 * Labels of the title, body, Elementor widget texts and custom fields that still equal the source.
	 *
	 * @param DUPCAP_Check_Content $check          Content check.
	 * @param int                  $source_id      Source post ID.
	 * @param int                  $translation_id Translation post ID.
	 * @param string               $target_slug    Target language slug.
	 * @return string[]
	 */
	protected function find_still_same( $check, $source_id, $translation_id, $target_slug ) {
		$labels      = array();
		$source_post = get_post( $source_id );
		$trans_post  = get_post( $translation_id );

		if ( ! $source_post || ! $trans_post ) {
			return $labels;
		}

		$ref_norm = new ReflectionMethod( 'DUPCAP_Check_Content', 'normalize_comparable_text' );
		$ref_norm->setAccessible( true );

		$src_title = $ref_norm->invoke( $check, (string) $source_post->post_title );
		if ( '' !== $src_title && $src_title === $ref_norm->invoke( $check, (string) $trans_post->post_title ) ) {
			$labels[] = __( 'Title', 'duplicate-content-addon-for-polylang' );
		}

		$source_builder = $this->is_elementor_built( $source_id ) && 'elementor_library' !== $source_post->post_type;

		if ( ! $this->is_elementor_built( $source_id ) && ! $this->is_elementor_built( $translation_id ) ) {
			$src_body = $ref_norm->invoke( $check, (string) $source_post->post_content );
			if ( '' !== $src_body && $src_body === $ref_norm->invoke( $check, (string) $trans_post->post_content ) ) {
				$labels[] = __( 'Content', 'duplicate-content-addon-for-polylang' );
			}
		} elseif ( $source_builder && class_exists( 'DUPCAP_Elementor_Document' ) ) {
			$ref_same = new ReflectionMethod( 'DUPCAP_Check_Content', 'elementor_same_text_fields' );
			$ref_same->setAccessible( true );
			$items = $ref_same->invoke( $check, new DUPCAP_Elementor_Document( $source_id ), new DUPCAP_Elementor_Document( $translation_id ) );
			foreach ( (array) $items as $item ) {
				$snippet  = function_exists( 'mb_substr' ) ? mb_substr( $item['original'], 0, 40 ) : substr( $item['original'], 0, 40 );
				$labels[] = sprintf(
					/* translators: 1: widget name, e.g. Heading 2: the text that was not translated. */
					__( '%1$s: “%2$s”', 'duplicate-content-addon-for-polylang' ),
					DUPCAP_Elementor_Fields::widget_label( $item['widget_type'] ),
					$snippet
				);
			}
		}

		foreach ( $check->get_translatable_custom_fields( $source_id ) as $fkey => $fdata ) {
			if ( $check->is_post_meta_synced_in_polylang( $fdata['meta_key'], $fdata['field_object'], $source_id, $translation_id, $target_slug ) ) {
				continue;
			}
			$tr_val = $check->get_custom_field_text_value( $translation_id, $fkey, $fdata['meta_key'] );
			if ( $ref_norm->invoke( $check, (string) $tr_val ) === $fdata['value'] ) {
				$labels[] = $fdata['label'];
			}
		}

		return $labels;
	}

	/**
	 * Turn post or widget HTML into plain text, one block per line, without block comments or tags.
	 *
	 * @param string $html Title, post content or widget HTML.
	 * @return string
	 */
	public function readable_text( $html ) {
		$html = preg_replace( '/<!--.*?-->/s', '', (string) $html );
		$html = preg_replace( '#<\s*br\s*/?>#i', "\n", (string) $html );
		$html = preg_replace( '#</\s*(p|div|h[1-6]|li|ul|ol|blockquote|tr|table|figure|figcaption|pre|section|article)\s*>#i', "\n", (string) $html );
		$html = wp_strip_all_tags( (string) $html );
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines = array();
		foreach ( (array) preg_split( '/\R/u', $html ) as $line ) {
			$line = trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * What a translator has to look at in the source right now, as plain text by field.
	 *
	 * Title and body (or Elementor widget text) plus the custom fields that Polylang does not synchronize.
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $lang           Target language slug.
	 * @return array{fields: array<string,string>, labels: array<string,string>, truncated: string[]}
	 */
	public function collect_source_fields( $source_id, $translation_id, $lang ) {
		$result = array(
			'fields'    => array(),
			'labels'    => array(),
			'truncated' => array(),
		);

		$source = get_post( (int) $source_id );
		if ( ! $source ) {
			return $result;
		}

		$result['fields']['title'] = $this->readable_text( $source->post_title );
		$result['labels']['title'] = __( 'Title', 'duplicate-content-addon-for-polylang' );

		if ( $this->is_elementor_built( $source_id ) ) {
			if ( class_exists( 'DUPCAP_Elementor_Document' ) && class_exists( 'DUPCAP_Elementor_Fields' ) ) {
				$doc = new DUPCAP_Elementor_Document( (int) $source_id );
				foreach ( $doc->get_element_map() as $el_id => $element ) {
					if ( empty( $element['fields'] ) ) {
						continue;
					}
					foreach ( $element['fields'] as $fkey => $value ) {
						$text = $this->readable_text( (string) $value );
						if ( '' === $text ) {
							continue;
						}
						$key                      = 'el:' . $el_id . ':' . $fkey;
						$result['fields'][ $key ] = $text;
						$result['labels'][ $key ] = sprintf(
							'%s — %s',
							DUPCAP_Elementor_Fields::widget_label( (string) $element['widget_type'] ),
							DUPCAP_Elementor_Fields::field_label( (string) preg_replace( '/^.*\./', '', (string) $fkey ) )
						);
					}
				}
			}
		} else {
			$result['fields']['content'] = $this->readable_text( $source->post_content );
			$result['labels']['content'] = __( 'Content', 'duplicate-content-addon-for-polylang' );
		}

		$check = new DUPCAP_Check_Content();
		foreach ( $check->get_translatable_custom_fields( (int) $source_id ) as $fkey => $fdata ) {
			if ( $check->is_post_meta_synced_in_polylang( $fdata['meta_key'], $fdata['field_object'], (int) $source_id, (int) $translation_id, (string) $lang ) ) {
				continue;
			}
			$result['fields'][ $fkey ] = $this->readable_text( (string) $fdata['value'] );
			$result['labels'][ $fkey ] = (string) $fdata['label'];
		}

		/**
		 * Max characters of one field kept in a snapshot.
		 *
		 * @param int $limit Character limit.
		 */
		$limit = max( 200, (int) apply_filters( 'dupcap_snapshot_field_limit', 20000 ) );

		/**
		 * Max fields kept in a snapshot.
		 *
		 * @param int $max Field limit.
		 */
		$max_fields = max( 10, (int) apply_filters( 'dupcap_snapshot_max_fields', 400 ) );

		if ( count( $result['fields'] ) > $max_fields ) {
			$result['fields'] = array_slice( $result['fields'], 0, $max_fields, true );
		}

		foreach ( $result['fields'] as $key => $text ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
			if ( $length > $limit ) {
				$result['fields'][ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
				$result['truncated'][]    = $key;
			}
		}

		return $result;
	}

	/**
	 * Saved copy of the source as it was when the translation was last up to date.
	 *
	 * @param int $translation_id Translation post ID.
	 * @return array|null
	 */
	public function get_snapshot( $translation_id ) {
		$snapshot = get_post_meta( (int) $translation_id, self::META_SNAPSHOT, true );
		return ( is_array( $snapshot ) && isset( $snapshot['fields'] ) && is_array( $snapshot['fields'] ) ) ? $snapshot : null;
	}

	/**
	 * Save the source's current text as the translation's "last up to date" copy.
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $lang           Target language slug.
	 * @param bool   $force          Write even when the text did not change (sets the time to now).
	 * @return void
	 */
	public function save_snapshot( $source_id, $translation_id, $lang, $force = false ) {
		$source_id      = (int) $source_id;
		$translation_id = (int) $translation_id;
		if ( $source_id <= 0 || $translation_id <= 0 ) {
			return;
		}

		$data = $this->collect_source_fields( $source_id, $translation_id, $lang );
		$hash = md5( (string) wp_json_encode( $data['fields'] ) );

		$current = $this->get_snapshot( $translation_id );
		if ( ! $force && $current && isset( $current['hash'] ) && $current['hash'] === $hash ) {
			return;
		}

		update_post_meta(
			$translation_id,
			self::META_SNAPSHOT,
			array(
				'v'         => 1,
				'taken_at'  => time(),
				'source_id' => $source_id,
				'hash'      => $hash,
				'fields'    => $data['fields'],
				'labels'    => $data['labels'],
				'truncated' => $data['truncated'],
			)
		);
	}

	/**
	 * Before a default-language post is edited, keep a copy for every translation that has none yet.
	 *
	 * @param int $post_id Post being updated.
	 * @return void
	 */
	public function capture_before_source_update( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || isset( $this->captured[ $post_id ] ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_default_language' ) || ! function_exists( 'pll_get_post_translations' ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! $this->is_tracked_post_type( $post->post_type ) ) {
			return;
		}

		$default_lang = (string) pll_default_language( 'slug' );
		if ( '' === $default_lang || (string) pll_get_post_language( $post_id, 'slug' ) !== $default_lang ) {
			return;
		}

		$this->captured[ $post_id ] = true;

		foreach ( (array) pll_get_post_translations( $post_id ) as $slug => $translation_id ) {
			$translation_id = (int) $translation_id;
			if ( $slug === $default_lang || $translation_id <= 0 || $translation_id === $post_id || $this->get_snapshot( $translation_id ) ) {
				continue;
			}
			$this->save_snapshot( $post_id, $translation_id, (string) $slug );
		}
	}

	/**
	 * Same as {@see self::capture_before_source_update()} for an Elementor editor save.
	 *
	 * @param object $document Elementor document about to be saved.
	 * @return void
	 */
	public function capture_before_elementor_save( $document ) {
		if ( is_object( $document ) && is_callable( array( $document, 'get_main_id' ) ) ) {
			$this->capture_before_source_update( (int) $document->get_main_id() );
		}
	}

	/**
	 * Fields of the source that differ from the saved "last up to date" copy.
	 *
	 * Uses the saved snapshot. Without one, the newest source revision from before the last sync is
	 * used for the title and body only. Without either, the earlier text is unknown.
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $lang           Target language slug.
	 * @return array{known:bool,partial:bool,baseline_ts:int,changes:array<int,array<string,string>>,old:array<string,string>,new:array<string,string>,truncated:string[]}
	 */
	protected function compute_changes( $source_id, $translation_id, $lang ) {
		$current = $this->collect_source_fields( $source_id, $translation_id, $lang );
		$result  = array(
			'known'       => false,
			'partial'     => false,
			'baseline_ts' => 0,
			'changes'     => array(),
			'old'         => array(),
			'new'         => $current['fields'],
			'truncated'   => array(),
		);

		$snapshot = $this->get_snapshot( $translation_id );
		$old      = array();
		$labels   = $current['labels'];

		if ( $snapshot ) {
			$old                   = $snapshot['fields'];
			$labels                = array_merge( isset( $snapshot['labels'] ) && is_array( $snapshot['labels'] ) ? $snapshot['labels'] : array(), $labels );
			$result['known']       = true;
			$result['baseline_ts'] = isset( $snapshot['taken_at'] ) ? (int) $snapshot['taken_at'] : 0;
			$result['truncated']   = isset( $snapshot['truncated'] ) && is_array( $snapshot['truncated'] ) ? $snapshot['truncated'] : array();
		} else {
			$revision = $this->find_baseline_revision( $source_id, $this->get_pair_sync_ts( $source_id, $lang ) );
			if ( $revision ) {
				$old['title'] = $this->readable_text( $revision->post_title );
				if ( ! $this->is_elementor_built( $source_id ) ) {
					$old['content'] = $this->readable_text( $revision->post_content );
				}
				$result['known']       = true;
				$result['partial']     = true;
				$result['baseline_ts'] = (int) get_post_modified_time( 'U', true, $revision->ID );
			}
		}

		if ( ! $result['known'] ) {
			return $result;
		}

		$result['old'] = $old;

		foreach ( array_unique( array_merge( array_keys( $current['fields'] ), array_keys( $old ) ) ) as $key ) {
			if ( $result['partial'] && ! in_array( $key, array( 'title', 'content' ), true ) ) {
				continue;
			}

			$in_old = array_key_exists( $key, $old );
			$in_new = array_key_exists( $key, $current['fields'] );

			if ( $in_old && $in_new ) {
				if ( $old[ $key ] === $current['fields'][ $key ] ) {
					continue;
				}
				$type = 'changed';
			} elseif ( $in_new ) {
				if ( '' === $current['fields'][ $key ] ) {
					continue;
				}
				$type = 'added';
			} else {
				if ( '' === $old[ $key ] ) {
					continue;
				}
				$type = 'removed';
			}

			$result['changes'][] = array(
				'key'   => (string) $key,
				'label' => isset( $labels[ $key ] ) ? (string) $labels[ $key ] : (string) $key,
				'type'  => $type,
			);
		}

		return $result;
	}

	/**
	 * Last sync time of a source ↔ translation pair, as saved by the content check.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Target language slug.
	 * @return int Unix GMT, or 0.
	 */
	protected function get_pair_sync_ts( $source_id, $lang ) {
		$map = get_post_meta( (int) $source_id, DUPCAP_Check_Content::META_CONTENT_PAIR_SYNC, true );
		return ( is_array( $map ) && ! empty( $map[ $lang ] ) ) ? (int) $map[ $lang ] : 0;
	}

	/**
	 * Newest revision of the source saved at or before the sync time. WordPress revisions hold the content
	 * as it was after each save, so that revision is the source as it was at the sync.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $sync_ts   Sync time, Unix GMT.
	 * @return WP_Post|null
	 */
	protected function find_baseline_revision( $source_id, $sync_ts ) {
		if ( $sync_ts <= 0 ) {
			return null;
		}

		foreach ( wp_get_post_revisions( (int) $source_id ) as $revision ) {
			if ( (int) get_post_modified_time( 'U', true, $revision->ID ) <= $sync_ts ) {
				return $revision;
			}
		}

		return null;
	}

	/**
	 * Compute the "what changed" diff for a translation.
	 *
	 * @param int  $translation_id Translation post ID.
	 * @param bool $split          Split view (two columns) or inline.
	 * @return array
	 */
	public function get_translation_diff( $translation_id, $split = true ) {
		$translation_id = (int) $translation_id;
		$trans_post     = get_post( $translation_id );
		if ( ! $trans_post ) {
			return array( 'error' => __( 'Translation post not found.', 'duplicate-content-addon-for-polylang' ) );
		}

		$default_lang = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
		$target_lang  = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $translation_id, 'slug' ) : '';
		$source_id    = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $translation_id, $default_lang ) : 0;
		$source_post  = $source_id > 0 ? get_post( $source_id ) : null;

		if ( ! $source_post ) {
			return array( 'error' => __( 'Source post not found.', 'duplicate-content-addon-for-polylang' ) );
		}

		$info   = $this->compute_changes( $source_id, $translation_id, $target_lang );
		$fields = array();

		foreach ( $info['changes'] as $change ) {
			$key = $change['key'];
			$old = isset( $info['old'][ $key ] ) ? (string) $info['old'][ $key ] : '';
			$new = isset( $info['new'][ $key ] ) ? (string) $info['new'][ $key ] : '';

			$diff_html = wp_text_diff(
				$old,
				$new,
				array(
					'title_left'      => __( 'At last sync', 'duplicate-content-addon-for-polylang' ),
					'title_right'     => __( 'Now', 'duplicate-content-addon-for-polylang' ),
					'show_split_view' => (bool) $split,
				)
			);

			$fields[] = array(
				'key'       => $key,
				'label'     => $change['label'],
				'type'      => $change['type'],
				'diff'      => '' !== $diff_html ? wp_kses( $diff_html, self::$diff_allowed_html ) : '',
				'truncated' => in_array( $key, $info['truncated'], true ),
			);
		}

		$baseline_ts   = (int) $info['baseline_ts'];
		$baseline_date = '';
		if ( $baseline_ts > 0 ) {
			$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

			// wp_date() needs WordPress 5.3 and this plugin supports 5.0, so fall back to date_i18n() on older versions.
			$baseline_date = function_exists( 'wp_date' )
				? wp_date( $date_format, $baseline_ts )
				: date_i18n( $date_format, $baseline_ts + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		}

		return array(
			'translation_id'  => $translation_id,
			'source_id'       => $source_id,
			'source_title'    => (string) $source_post->post_title,
			'target_title'    => (string) $trans_post->post_title,
			'source_lang'     => $default_lang,
			'target_lang'     => $target_lang,
			'source_edit_url' => (string) get_edit_post_link( $source_id, 'raw' ),
			'has_baseline'    => (bool) $info['known'],
			'partial'         => (bool) $info['partial'],
			'baseline_human'  => $baseline_ts > 0 ? human_time_diff( $baseline_ts ) : '',
			'baseline_date'   => $baseline_date,
			'fields'          => $fields,
			'can_sync'        => current_user_can( 'edit_post', $translation_id ),
		);
	}

	/**
	 * Mark a translation as up to date with its source.
	 *
	 * The status comes from the content-change times of the source and the translation, so the
	 * translation's time is moved to now (never before the source's), and the source is saved
	 * as the new "last up to date" copy.
	 *
	 * @param int $translation_id Translation post ID.
	 * @return bool
	 */
	public function mark_translation_synced( $translation_id ) {
		$translation_id = (int) $translation_id;
		if ( $translation_id <= 0 ) {
			return false;
		}

		$default_lang = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
		$target_lang  = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $translation_id, 'slug' ) : '';

		if ( '' === $default_lang || '' === $target_lang || $default_lang === $target_lang ) {
			return false;
		}

		$source_id = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $translation_id, $default_lang ) : 0;
		if ( $source_id <= 0 ) {
			return false;
		}

		$check = new DUPCAP_Check_Content();

		$ref_sync = new ReflectionMethod( 'DUPCAP_Check_Content', 'sync_content_change_marker_fresh' );
		$ref_sync->setAccessible( true );
		$source_ts = (int) $ref_sync->invoke( $check, $source_id );
		$ref_sync->invoke( $check, $translation_id );

		$now = max( time(), $source_ts + 1 );

		$ref_set = new ReflectionMethod( 'DUPCAP_Check_Content', 'set_pair_sync_at' );
		$ref_set->setAccessible( true );
		$ref_set->invoke( $check, $source_id, $target_lang, $now );

		update_post_meta( $translation_id, DUPCAP_Check_Content::META_CONTENT_CHANGED_AT, $now );

		$this->save_snapshot( $source_id, $translation_id, $target_lang, true );

		clean_post_cache( $source_id );
		clean_post_cache( $translation_id );

		return true;
	}

	/**
	 * Check the AJAX request: nonce, post ID and capability.
	 *
	 * @param string $param Request parameter holding the post ID.
	 * @return int Post ID.
	 */
	private function authorize_ajax( $param ) {
		check_ajax_referer( 'dupcap_editor_nonce', 'nonce' );

		$post_id = isset( $_REQUEST[ $param ] ) ? absint( wp_unslash( $_REQUEST[ $param ] ) ) : 0;
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied or invalid post ID.', 'duplicate-content-addon-for-polylang' ) ), 403 );
		}

		return $post_id;
	}

	/**
	 * Status payload for AJAX, with the classic metabox markup when asked for.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function status_response( $post_id ) {
		$status = $this->get_post_translation_status( $post_id );

		// Nonce and capability were checked by authorize_ajax() earlier in this request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['with_html'] ) && '1' === sanitize_key( wp_unslash( $_REQUEST['with_html'] ) ) ) {
			ob_start();
			$this->render_metabox_body( $post_id );
			$status['html'] = (string) ob_get_clean();
		}

		return $status;
	}

	/**
	 * AJAX handler: Get translation status for a post.
	 *
	 * @return void
	 */
	public function ajax_get_status() {
		$post_id = $this->authorize_ajax( 'post_id' );
		wp_send_json_success( $this->status_response( $post_id ) );
	}

	/**
	 * AJAX handler: Get the "what changed" diff for a translation.
	 *
	 * @return void
	 */
	public function ajax_get_diff() {
		$translation_id = $this->authorize_ajax( 'translation_id' );
		// Nonce and capability were checked by authorize_ajax() above.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$split = ! isset( $_REQUEST['split'] ) || 'false' !== sanitize_key( wp_unslash( $_REQUEST['split'] ) );

		$diff = $this->get_translation_diff( $translation_id, $split );
		if ( isset( $diff['error'] ) ) {
			wp_send_json_error( array( 'message' => $diff['error'] ) );
		}

		wp_send_json_success( $diff );
	}

	/**
	 * AJAX handler: Mark translation as up to date.
	 *
	 * @return void
	 */
	public function ajax_mark_synced() {
		$translation_id = $this->authorize_ajax( 'translation_id' );

		if ( ! $this->mark_translation_synced( $translation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not update the translation. Check that it is linked to a source post.', 'duplicate-content-addon-for-polylang' ) ) );
		}

		wp_send_json_success( $this->status_response( $translation_id ) );
	}
}
