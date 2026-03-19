/* WP Zabbix Monitor — Admin JS
 * Handles: tab switching, manual push, token regeneration, connection test,
 *          copy-to-clipboard, and live metric refresh.
 */
(function ($) {
    'use strict';

    var data = window.wpzmData || {};

    // ── Tabs ──────────────────────────────────────────────────────────────────
    $(document).on('click', '.wpzm-tab', function () {
        var target = $(this).data('tab');
        $('.wpzm-tab').removeClass('active');
        $('.wpzm-tab-content').removeClass('active');
        $(this).addClass('active');
        $('#wpzm-tab-' + target).addClass('active');
    });

    // Activate first tab on load.
    $('.wpzm-tab:first').trigger('click');

    // ── Manual push ───────────────────────────────────────────────────────────
    $('#wpzm-push-btn').on('click', function () {
        var $btn = $(this);
        var $result = $('#wpzm-push-result');

        $btn.prop('disabled', true).text(data.i18n.pushing);
        $result.removeClass('success error').hide();

        $.post(data.ajaxUrl, {
            action: 'wpzm_manual_push',
            nonce:  data.nonce
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').text(
                    data.i18n.pushSuccess + ' — Processed: ' + res.data.processed +
                    ', Failed: ' + res.data.failed
                ).show();
            } else {
                $result.addClass('error').text(
                    data.i18n.pushFailed + ' ' + (res.data.message || '')
                ).show();
            }
        })
        .fail(function () {
            $result.addClass('error').text(data.i18n.pushFailed).show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('Push Now');
        });
    });

    // ── Token regeneration ────────────────────────────────────────────────────
    $('#wpzm-regen-token').on('click', function () {
        if (!confirm('Regenerate the API token? All existing Zabbix HTTP agent items will need to be updated.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(data.ajaxUrl, {
            action: 'wpzm_regen_token',
            nonce:  data.nonce
        })
        .done(function (res) {
            if (res.success) {
                $('#wpzm-api-token').val(res.data.token);
            }
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Connection test ───────────────────────────────────────────────────────
    $('#wpzm-test-connection').on('click', function () {
        var $btn = $(this);
        var $result = $('#wpzm-connection-result');

        $btn.prop('disabled', true).text(data.i18n.testing);
        $result.removeClass('success error').hide();

        $.post(data.ajaxUrl, {
            action: 'wpzm_test_connection',
            nonce:  data.nonce
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').text('Connection OK — ' + res.data.message).show();
            } else {
                $result.addClass('error').text('Connection failed: ' + (res.data.message || 'Unknown error')).show();
            }
        })
        .fail(function () {
            $result.addClass('error').text('Request failed.').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('Test Connection');
        });
    });

    // ── Copy to clipboard ─────────────────────────────────────────────────────
    $(document).on('click', '.wpzm-copy-btn', function () {
        var $btn = $(this);
        var text = $btn.closest('.wpzm-endpoint').find('.wpzm-endpoint-text').text().trim() ||
                   $btn.siblings('input').val();

        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                showCopied($btn);
            });
        } else {
            var $tmp = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
            showCopied($btn);
        }
    });

    function showCopied($btn) {
        var orig = $btn.text();
        $btn.text(data.i18n.copied);
        setTimeout(function () { $btn.text(orig); }, 1500);
    }

    // ── Live metrics refresh ──────────────────────────────────────────────────
    $('#wpzm-refresh-metrics').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).prepend('<span class="wpzm-spinner"></span>');

        $.post(data.ajaxUrl, {
            action: 'wpzm_get_metrics',
            nonce:  data.nonce
        })
        .done(function (res) {
            if (res.success) {
                updateMetricDisplay(res.data);
            }
        })
        .always(function () {
            $btn.prop('disabled', false).find('.wpzm-spinner').remove();
        });
    });

    function updateMetricDisplay(metrics) {
        $('[data-metric]').each(function () {
            var path = $(this).data('metric').split('.');
            var val = metrics;
            for (var i = 0; i < path.length; i++) {
                if (val && typeof val === 'object') {
                    val = val[path[i]];
                } else {
                    val = null;
                    break;
                }
            }
            if (val !== null && val !== undefined) {
                $(this).text(val);
            }
        });
    }

    // ── Toggle push settings visibility ──────────────────────────────────────
    function togglePushSettings() {
        var enabled = $('#wpzm-push-enabled').is(':checked');
        $('.wpzm-push-settings').toggle(enabled);
    }

    $('#wpzm-push-enabled').on('change', togglePushSettings);
    togglePushSettings();

}(jQuery));
