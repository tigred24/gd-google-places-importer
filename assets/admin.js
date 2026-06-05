/* GD WAWS Importer - Admin JS */
jQuery(function ($) {

    // ── Run Import ──────────────────────────────────────────────
    $('#gdwaws-run-import').on('click', function () {
        var region      = $('#gdwaws_region').val().trim();
        var type        = $('#gdwaws_type').val();
        var limit       = $('#gdwaws_limit').val();
        var radius      = $('#gdwaws_radius').val();
        var city_filter = $('#gdwaws_city_filter').is(':checked') ? '1' : '';

        if (!region) {
            alert('Please enter a region/location.');
            return;
        }

        var $btn     = $(this);
        var $spinner = $('#gdwaws-spinner');
        var $logWrap = $('#gdwaws-log-wrap');
        var $log     = $('#gdwaws-log');

        $btn.prop('disabled', true);
        $spinner.show();
        $logWrap.show();
        $log.html('<div class="gdwaws-log-line"><span class="gdwaws-log-info">Starting import for: ' + escHtml(region) + ' / ' + escHtml(type) + '...</span></div>');

        $.post(GDWAWS.ajax_url, {
            action:      'gdwaws_run_import',
            nonce:       GDWAWS.nonce,
            region:      region,
            type:        type,
            limit:       limit,
            radius:      radius,
            city_filter: city_filter,
        }, function (res) {
            $btn.prop('disabled', false);
            $spinner.hide();

            if (!res.success) {
                $log.append('<div class="gdwaws-log-line gdwaws-log-error">❌ ' + escHtml(res.data.message || 'Unknown error.') + '</div>');
                return;
            }

            var entries = res.data.log || [];
            entries.forEach(function (entry) {
                var cls = 'gdwaws-log-' + entry.type;
                var icon = entry.type === 'success' ? '✅' : entry.type === 'error' ? '❌' : entry.type === 'skip' ? '⏭' : 'ℹ️';
                $log.append(
                    '<div class="gdwaws-log-line">' +
                    '<span class="gdwaws-log-time">[' + escHtml(entry.time) + ']</span>' +
                    '<span class="' + cls + '">' + icon + ' ' + escHtml(entry.message) + '</span>' +
                    '</div>'
                );
            });

            // Scroll to bottom
            $log.scrollTop($log[0].scrollHeight);

        }).fail(function () {
            $btn.prop('disabled', false);
            $spinner.hide();
            $log.append('<div class="gdwaws-log-line gdwaws-log-error">❌ AJAX request failed. Check your server logs.</div>');
        });
    });

    // ── Claude toggle ────────────────────────────────────────────
    $('#use_claude').on('change', function () {
        if ($(this).is(':checked')) {
            $('#gdwaws-claude-row, #gdwaws-model-row').show();
        } else {
            $('#gdwaws-claude-row, #gdwaws-model-row').hide();
        }
    });

    // ── Save Settings ───────────────────────────────────────────
    $('#gdwaws-save-settings').on('click', function () {
        var $btn = $(this);
        var $msg = $('#gdwaws-settings-msg');

        var data = {
            action:             'gdwaws_save_settings',
            nonce:              GDWAWS.nonce,
            google_api_key:     $('#google_api_key').val(),
            anthropic_api_key:  $('#anthropic_api_key').val(),
            use_claude:         $('#use_claude').is(':checked') ? '1' : '0',
            anthropic_model:    $('#anthropic_model').val(),
            default_region:     $('#default_region').val(),
            import_limit:       $('#import_limit').val(),
            post_status:        $('#post_status').val(),
            geodir_post_type:   $('#geodir_post_type').val(),
        };

        $btn.prop('disabled', true).text('Saving...');

        $.post(GDWAWS.ajax_url, data, function (res) {
            $btn.prop('disabled', false).text('Save Settings');
            $msg.removeClass('success error').show();
            if (res.success) {
                $msg.addClass('success').text('✅ ' + res.data.message);
            } else {
                $msg.addClass('error').text('❌ ' + (res.data.message || 'Error saving.'));
            }
        });
    });

    // ── Test Google API ─────────────────────────────────────────
    $('#gdwaws-test-google').on('click', function () {
        var key = $('#google_api_key').val();
        if (!key) { alert('Enter your Google API key first.'); return; }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Testing...');
        $.post(GDWAWS.ajax_url, { action: 'gdwaws_test_google', nonce: GDWAWS.nonce, key: key }, function (res) {
            $btn.prop('disabled', false).text('Test Connection');
            alert(res.success ? res.data.message : '❌ ' + res.data.message);
        });
    });

    // ── Test Claude API ─────────────────────────────────────────
    $('#gdwaws-test-claude').on('click', function () {
        var key = $('#anthropic_api_key').val();
        if (!key) { alert('Enter your Anthropic API key first.'); return; }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Testing...');
        $.post(GDWAWS.ajax_url, { action: 'gdwaws_test_claude', nonce: GDWAWS.nonce, key: key }, function (res) {
            $btn.prop('disabled', false).text('Test Connection');
            alert(res.success ? res.data.message : '❌ ' + res.data.message);
        });
    });

    // ── Utility ─────────────────────────────────────────────────
    function escHtml(str) {
        return $('<div>').text(str || '').html();
    }
});
