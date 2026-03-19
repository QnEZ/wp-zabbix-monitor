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

    // ── Auto-Provision: Test API connection ─────────────────────────────────
    $('#wpzm-test-api-conn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#wpzm-provision-result');
        var payload = getProvisionPayload();

        if (!payload) { return; }

        $btn.prop('disabled', true).text('Testing…');
        $result.removeClass('success error').html('');

        $.post(data.ajaxUrl, $.extend({ action: 'wpzm_test_api_conn', nonce: data.nonce }, payload))
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').html(
                    '<span class="dashicons dashicons-yes-alt"></span> ' +
                    'Connection successful. Zabbix API version: <strong>' + (res.data.version || 'unknown') + '</strong>'
                ).show();
            } else {
                $result.addClass('error').html(
                    '<span class="dashicons dashicons-warning"></span> ' +
                    'Connection failed: ' + (res.data.message || 'Unknown error')
                ).show();
            }
        })
        .fail(function () {
            $result.addClass('error').text('Request failed.').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('Test API Connection');
        });
    });

    // ── Auto-Provision: Run provisioning ────────────────────────────────────────
    $('#wpzm-run-provision').on('click', function () {
        var $btn    = $(this);
        var $result = $('#wpzm-provision-result');
        var payload = getProvisionPayload();

        if (!payload) { return; }

        if (!confirm('This will create or update a Zabbix host for this site. Continue?')) {
            return;
        }

        $btn.prop('disabled', true).text('Provisioning…');
        $result.removeClass('success error').html('');

        $.post(data.ajaxUrl, $.extend({ action: 'wpzm_provision', nonce: data.nonce }, payload))
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').html(
                    '<span class="dashicons dashicons-yes-alt"></span> ' +
                    '<strong>' + res.data.message + '</strong><br>' +
                    'Host ID: ' + res.data.host_id + '<br>' +
                    'Macros set: <code>{$WP_URL}</code> and <code>{$WP_API_TOKEN}</code>'
                ).show();
            } else {
                $result.addClass('error').html(
                    '<span class="dashicons dashicons-warning"></span> ' +
                    'Provisioning failed: ' + (res.data.message || 'Unknown error')
                ).show();
            }
        })
        .fail(function () {
            $result.addClass('error').text('Request failed.').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('Provision Host in Zabbix');
        });
    });

    function getProvisionPayload() {
        var apiUrl   = $('#wpzm-prov-api-url').val().trim();
        var username = $('#wpzm-prov-username').val().trim();
        var password = $('#wpzm-prov-password').val();
        var hostName = $('#wpzm-prov-host-name').val().trim();
        var group    = $('#wpzm-prov-host-group').val().trim();
        var template = $('#wpzm-prov-template').val().trim();
        var ssl      = $('#wpzm-prov-ssl').is(':checked') ? '1' : '';

        if (!apiUrl || !username || !password) {
            alert('Please fill in the Zabbix API URL, username, and password.');
            return null;
        }
        if (!hostName) {
            alert('Please enter a host display name.');
            return null;
        }

        return {
            api_url:       apiUrl,
            username:      username,
            password:      password,
            host_name:     hostName,
            host_group:    group || 'WordPress Sites',
            template_name: template || 'WordPress by WP Zabbix Monitor',
            ssl_verify:    ssl
        };
    }

    // ── Toggle push settings visibility ──────────────────────────────────────────────
    function togglePushSettings() {
        var enabled = $('#wpzm-push-enabled').is(':checked');
        $('.wpzm-push-settings').toggle(enabled);
    }

    $('#wpzm-push-enabled').on('change', togglePushSettings);
    togglePushSettings();

}(jQuery));
