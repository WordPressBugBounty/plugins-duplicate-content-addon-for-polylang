/**
 * Dupcap Marketing Popup
 * Handles modal interactions, plugin installation/activation, and admin notice dismissal.
 */
class DupcapMarketingPopup {

    /**
     * Initialize the class
     * @param {Object} $ jQuery instance
     */
    constructor($) {
        this.$ = $;
        this.config = (typeof dupcapMarketing !== 'undefined') ? dupcapMarketing : {};

        // Selectors
        this.selectors = {
            modal: '#dupcap-marketing-modal',
            trigger: '.dupcap-marketing-trigger, .dupcap-marketing-header-trigger',
            closeBtn: '.dupcap-modal-close',
            overlay: '.dupcap-modal-overlay',
            warningWrapper: '.atfp-modal-open-warning-wrapper',
            installBtn: '.dupcap-install-plugin',
            copyBtn: '.dupcap-copy-button',
            dismissBtn: '.fdbgp-card-wrapper .notice-dismiss'
        };
    }

    /**
     * Main entry point
     */
    init() {
        this.moveModalToBody();
        this.bindEvents();
        this.checkAutoOpen();
    }

    /**
     * Move modal to body to ensure it sits on top (z-index fix)
     */
    moveModalToBody() {
        const $modal = this.$(this.selectors.modal);
        if ($modal.length > 0) {
            $modal.appendTo('body');
        }
    }

    /**
     * Bind all event listeners
     */
    bindEvents() {
        const self = this;

        // Open Modal Triggers
        this.$(document).on('click', this.selectors.trigger, function (e) {
            e.preventDefault();
            self.openModal();
        });

        // Custom Event for Open
        this.$(document).on('dupcap:open-modal', function () {
            self.openModal();
        });

        // Close Modal
        this.$(document).on('click', this.selectors.closeBtn, function (e) {
            e.preventDefault();
            self.closeModal();
        });

        // Overlay Click Close
        this.$(document).on('click', this.selectors.overlay, function (e) {
            if (self.$(e.target).is(self.selectors.overlay)) {
                self.closeModal();
            }
        });

        // ESC Key Close
        this.$(document).keyup(function (e) {
            if (e.key === "Escape") {
                self.closeModal();
            }
        });

        // Third-party Modal Interop (AutoPoly Warning)
        this.$(document).on('click', this.selectors.warningWrapper, function () {
            // Force other modal processing
            self.$('#atfp-modal-open-warning-wrapper .modal-container').css('display', 'flex');
            setTimeout(() => self.closeModal(), 200);
        });

        // Plugin Installation
        this.$(document).on('click', this.selectors.installBtn, function (e) {
            self.handleInstall(e, self.$(this));
        });

        // Copy Content Button
        this.$(document).on('click', this.selectors.copyBtn, function (e) {
            self.handleCopyContent(e, self.$(this));
        });

        // Notice Dismissal
        this.$(document).on('click', this.selectors.dismissBtn, function (e) {
            self.handleDismissNotice(e, self.$(this));
        });
    }

    /**
     * open Modal Logic
     */
    openModal() {
        this.$(this.selectors.modal).addClass('active');
        this.$('body').addClass('dupcap-modal-open');
    }

    /**
     * Close Modal Logic
     */
    closeModal() {
        this.$(this.selectors.modal).removeClass('active');
        this.$('body').removeClass('dupcap-modal-open');

        // Show tooltip on trigger when modal closes
        const $triggerWrapper = this.$(this.selectors.trigger).closest('.dupcap-marketing-trigger-wrapper, .dupcap-header-wrapper, .dupcap-classic-wrapper');
        $triggerWrapper.addClass('show-tooltip');

        // Hide tooltip after 3 seconds
        setTimeout(() => {
            $triggerWrapper.removeClass('show-tooltip');
        }, 5000);
    }

    /**
     * Check if the modal should automatically open
     */
    checkAutoOpen() {
            if (!this.config.autoOpen) return;

            const $modal = this.$(this.selectors.modal);

            $modal.hide();

            $modal.css('display', 'flex');     
            
            this.openModal();  
    }



    /**
     * Handle Plugin Installation/Activation
     * @param {Event} e 
     * @param {Object} $btn jQuery object
     */
    handleInstall(e, $btn) {
        e.preventDefault();

        const self = this;
        const $wrapper = $btn.closest('.dupcap-marketing-card, .fdbgp-card-wrapper');
        const slug = $btn.data('slug');
        const nonce = $btn.data('nonce');
        let action = $btn.data('action') || 'install';
        const $btnText = $btn.find('.dupcap-btn-text');

        // Validation
        const $msgContainer = $wrapper.find('.dupcap-install-message');
        $msgContainer.empty();

        if (!slug || !nonce || typeof ajaxurl === 'undefined') {
            $msgContainer.text('Missing required data. Please reload the page.');
            return;
        }

        const validSlugs = ['automatic-translations-for-polylang', 'autopoly-ai-translation-for-polylang-pro'];
        if (!validSlugs.includes(slug)) {
            $msgContainer.text('Invalid Plugin Slug');
            return;
        }

        // UI State: Loading
        const originalText = $btnText.text();
        $btnText.text(action === 'activate' ? 'Activating...' : 'Installing...');
        $btn.addClass('disabled').css({ 'pointer-events': 'none', 'opacity': '0.7' });

        // AJAX Request
        this.$.post(ajaxurl, {
            action: 'dupcap_install_plugin',
            slug: slug,
            plugin_action: action,
            _wpnonce: nonce
        }, (response) => {
            self.handleInstallResponse(response, action, $btn, $btnText, $wrapper, originalText);
        }).fail(() => {
            // Failure Handler
            $msgContainer.text('Network error. Try again.');
            $btnText.text(originalText);
            $btn.removeClass('disabled').css({ 'pointer-events': 'auto', 'opacity': '1' });
        });
    }

    /**
     * Process Install/Activate Response
     */
    handleInstallResponse(response, action, $btn, $btnText, $wrapper, originalText) {

        const $msgContainer = $wrapper.find('.dupcap-install-message');
        const self = this;

        // Success Check (Explicit success or HTML redirect)
        const isSuccess = (response && response.success) ||
            (typeof response === 'string' && (response.includes('<!DOCTYPE html') || response.includes('<html')));

        if (isSuccess) {

            // Should activate?
            const shouldActivateNext = (action === 'install' && !(response.data && response.data.activated));

            // Already Activated or Done
            if (!shouldActivateNext) {
                $btnText.text('Activated!');
                $btn.addClass('disabled');

                if (self.shouldReloadPage()) {
                    setTimeout(() => location.reload(), 1000);
                } else if ($wrapper.hasClass('fdbgp-card-wrapper')) {
                    setTimeout(() => $wrapper.closest('.notice').slideUp(), 1000);
                }
                return;
            }

            // Chain Installation -> Activation
            $btnText.text('Activating...');
            $btn.data('action', 'activate');
            $btn.removeClass('disabled').css({ 'pointer-events': 'auto', 'opacity': '1' });
            $btn.trigger('click'); // Trigger activation immediately

        } else {
            // Error Handling
            let errorMessage = 'Action failed. Please try again.';
            if (response && response.data) {
                errorMessage = response.data.message || response.data.errorMessage || response.data || errorMessage;
            }
            $msgContainer.text(errorMessage);
            $btnText.text(originalText);
            $btn.removeClass('disabled').css({ 'pointer-events': 'auto', 'opacity': '1' });
        }
    }

    /**
     * Determine if page reload is needed after activation
     */
    shouldReloadPage() {
        return this.config.postType && ['post', 'page'].includes(this.config.postType);
    }

    /**
     * Handle Copy Content Logic
     */
    handleCopyContent(e, $btn) {

        e.preventDefault();
        $btn.addClass('disabled');

        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('copy_content', '1');
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }

    /**
     * Handle Admin Notice Dismissal
     */
    handleDismissNotice(e, $btn) {
        const $parentWrapper = $btn.closest('.fdbgp-card-wrapper');
        const nonce = $parentWrapper.data('nonce');
        const url = $parentWrapper.data('url');

        if (!nonce || !url) return;

        this.$.ajax({
            type: 'POST',
            url: url,
            data: {
                action: 'dupcap_notice_dismiss',
                dupcap_atp_dismiss: true,
                nonce: nonce,
            },
            error: function(xhr) {
                // Silently fail - user can still use the plugin
                // Avoid logging sensitive information to console
            }
        });
    }
}

// Initialize on Ready
jQuery(document).ready(function ($) {
    new DupcapMarketingPopup($).init();
});
