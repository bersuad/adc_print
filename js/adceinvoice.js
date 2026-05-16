/* global jQuery, dolibarr_main_url */
'use strict';

(function ($) {
    var AdcEinvoice = {
        init: function () {
            this.bindSyncButtons();
            this.bindQueueActions();
            this.enhanceQrDisplay();
            console.log('[ADC eInvoicing] Frontend initialized');
        },

        bindSyncButtons: function () {
            $(document).on('click', '.adceinvoice-sync-trigger', function (e) {
                e.preventDefault();
                var $btn = $(this);
                if ($btn.hasClass('loading')) return;

                AdcEinvoice.showLoading($btn);
                // Let the form submit normally, JS just handles UX state
                $btn.closest('form').submit();
            });
        },

        bindQueueActions: function () {
            $(document).on('click', '.adceinvoice-queue-retry', function (e) {
                if (!confirm('Retry syncing this transaction?')) {
                    e.preventDefault();
                }
            });

            $(document).on('submit', '.adceinvoice-queue-form', function () {
                var $btn = $(this).find('button[type="submit"]');
                AdcEinvoice.showLoading($btn);
            });
        },

        enhanceQrDisplay: function () {
            $('.adceinvoice-qr-img').on('error', function () {
                $(this).replaceWith('<span class="opacitymedium">QR preview unavailable</span>');
            });
        },

        showLoading: function ($el) {
            $el.addClass('loading').prop('disabled', true);
            var originalText = $el.html();
            $el.data('original-text', originalText);
            $el.html('<span class="fa fa-spinner fa-spin"></span> Processing...');
        },

        hideLoading: function ($el) {
            $el.removeClass('loading').prop('disabled', false);
            var original = $el.data('original-text');
            if (original) $el.html(original);
        }
    };

    // Auto-init when DOM ready
    $(function () {
        AdcEinvoice.init();
    });
})(jQuery);