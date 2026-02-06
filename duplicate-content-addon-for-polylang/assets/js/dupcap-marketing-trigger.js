/**
 * Marketing Trigger Handler
 * Handles the injection of the marketing button into Gutenberg and Classic editors.
 */
class DupcapMarketingTrigger {

    /**
     * Initialize the class
     * @param {Object} $ jQuery instance
     */
    constructor($) {

        this.$ = $;
        this.config = (typeof dupcapMarketing !== 'undefined') ? dupcapMarketing : null;

        // Gutenberg constants
        this.maxRetries = 60;
        this.gutenbergSelectors = [
            '.editor-post-save-draft',
            '.components-button.is-tertiary',
            '.editor-post-preview',
            '.editor-post-publish-button__container',
            '.editor-post-publish-panel__toggle',
            '.edit-post-header-toolbar__left > *:last-child'
        ];
    }

    /**
     * Main entry point
     */
    init() {
        if (!this.isValidConfig()) {
            return;
        }

        if (this.isGutenberg()) {
            this.initGutenberg();
        } else {
            this.initClassic();
        }
    }

    /**
     * Validate configuration availability
     * @returns {boolean}
     */
    isValidConfig() {
        return (this.config && this.config.showTrigger && this.config.logoUrl);
    }

    /**
     * Check if current page is Gutenberg editor
     * @returns {boolean}
     */
    isGutenberg() {
        return document.body.classList.contains('block-editor-page');
    }

    /**
     * ------------------------------------------------------------------------
     * GUTENBERG LOGIC
     * ------------------------------------------------------------------------
     */

    initGutenberg() {
        const { logoUrl, tooltipText = 'Marketing Autopoly' } = this.config;
        const self = this;
        let retryCount = 0;

        // Create Trigger Element
        const $trigger = this.$('<div class="dupcap-marketing-header-trigger dupcap-header-wrapper">' +
            '<img src="' + logoUrl + '" style="height: 24px; width: auto; transition: transform 0.2s;" />' +
            '<div class="dupcap-marketing-tooltip">' + tooltipText + '</div>' +
            '</div>');

        // Bind Events
        $trigger.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            self.$(document).trigger('dupcap:open-modal');
        });

        $trigger.hover(
            function () { self.$(this).find('img').css('transform', 'scale(1.1)'); },
            function () { self.$(this).find('img').css('transform', 'scale(1)'); }
        );

        // Polling loop to wait for Gutenberg UI
        const checkInterval = setInterval(() => {

            // Exit if max retries reached or already inserted
            if (retryCount >= this.maxRetries || this.$('.dupcap-marketing-header-trigger').length > 0) {
                clearInterval(checkInterval);
                return;
            }

            retryCount++;

            // Find Settings Container
            const $settingsContainer = this.$('.edit-post-header__settings, .editor-header__settings, .interface-interface-skeleton__header .components-toolbar').first();

            if ($settingsContainer.length > 0) {
                if (this.insertGutenbergButton($settingsContainer, $trigger)) {
                    clearInterval(checkInterval);
                }
            }
        }, 500);
    }

    /**
     * Attempt to insert trigger into Gutenberg toolbar
     * @param {Object} $container jQuery object of settings container
     * @param {Object} $trigger jQuery object of trigger element
     * @returns {boolean} True if inserted
     */
    insertGutenbergButton($container, $trigger) {
        // Try specific insertion points
        for (let i = 0; i < this.gutenbergSelectors.length; i++) {
            let $target = $container.find(this.gutenbergSelectors[i]).first();
            if ($target.length > 0 && $target.is(':visible')) {
                $target.before($trigger);
                return true;
            }
        }

        // Fallback: Prepend
        $container.prepend($trigger);
        return true;
    }

    /**
     * ------------------------------------------------------------------------
     * CLASSIC EDITOR LOGIC
     * ------------------------------------------------------------------------
     */

    initClassic() {
        const { logoUrl, tooltipText = 'Marketing Autopoly' } = this.config;
        const self = this;

        // Prepare DOM Template
        this.classicButtonTemplate = document.createElement('div');
        this.classicButtonTemplate.className = 'mce-container mce-flow-layout-item mce-btn-group dupcap-classic-wrapper';
        this.classicButtonTemplate.setAttribute('role', 'group');
        this.classicButtonTemplate.style.marginLeft = '5px';
        this.classicButtonTemplate.style.position = 'relative'; // For tooltip positioning
        this.classicButtonTemplate.innerHTML = `
            <div class="mce-widget mce-btn dupcap-marketing-toolbar-button dupcap-marketing-trigger" tabindex="-1" role="button" aria-label="${tooltipText}">
                <button role="presentation" type="button" tabindex="-1" style="display:flex; align-items:center; justify-content:center; padding: 2px 5px;">
                    <img src="${logoUrl}" style="height: 18px; width: auto;" alt="" />
                </button>
            </div>
            <div class="dupcap-marketing-tooltip">${tooltipText}</div>
        `;

        // 1. Hook into future editors (WP Event)
        this.$(document).on('tinymce-editor-init', (event, editor) => {
            this.injectClassicButton(editor);
        });

        // 2. Hook into existing editors
        if (typeof tinymce !== 'undefined' && tinymce.editors) {
            for (let i = 0; i < tinymce.editors.length; i++) {
                const editor = tinymce.editors[i];
                if (editor.initialized) {
                    this.injectClassicButton(editor);
                } else {
                    editor.on('init', () => this.injectClassicButton(editor));
                }
            }
        }
    }

    /**
     * Inject button into a specific TinyMCE instance
     * @param {Object} editor TinyMCE editor instance
     */
    injectClassicButton(editor) {
        if (!editor || !editor.editorContainer) return;

        const container = editor.editorContainer;

        // Avoid duplicates using native DOM query
        if (container.querySelector('.dupcap-marketing-toolbar-button')) return;

        // Find Toolbar Body
        const toolbarBody = container.querySelector('.mce-toolbar-grp .mce-toolbar .mce-container-body') ||
            container.querySelector('.mce-toolbar-grp .mce-toolbar');

        if (!toolbarBody) return;

        // Clone and Insert
        const newButton = this.classicButtonTemplate.cloneNode(true);
        const self = this;

        newButton.addEventListener('click', (e) => {
            e.preventDefault();
            self.$(document).trigger('dupcap:open-modal');
        });

        toolbarBody.appendChild(newButton);
    }
}

// Initialize on DOM Ready
jQuery(document).ready(function ($) {
    new DupcapMarketingTrigger($).init();
});
