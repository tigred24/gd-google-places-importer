/* GD Google Places Importer - Admin JS */
jQuery(function ($) {

    var previewData = []; // Stores full preview objects keyed by place_id

    // ── Select / Deselect All Categories ────────────────────────
    $('#gdwaws-select-all-cats').on('click', function () {
        $('.gdwaws-cat-check').prop('checked', true);
    });
    $('#gdwaws-deselect-all-cats').on('click', function () {
        $('.gdwaws-cat-check').prop('checked', false);
    });

    // ── Preview Import ───────────────────────────────────────────
    $('#gdwaws-preview-import').on('click', function () {
        var region      = $('#gdwaws_region').val().trim();
        var post_type   = $('#gdwaws_post_type').val();
        var city_filter = $('#gdwaws_city_filter').is(':checked') ? '1' : '';
        var categories  = [];
        $('.gdwaws-cat-check:checked').each(function () { categories.push($(this).val()); });

        if (!region) { alert('Please enter a region/location.'); return; }
        if (categories.length === 0) { alert('Please select at least one category.'); return; }

        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#gdwaws-spinner').show();
        $('#gdwaws-preview-wrap').hide();
        $('#gdwaws-log-wrap').hide();
        previewData = [];

        $.ajax({
            url: GDWAWS.ajax_url,
            type: 'POST',
            timeout: 300000, // 5 minutes
            data: {
                action: 'gdwaws_preview_import',
                nonce: GDWAWS.nonce,
                region: region, post_type: post_type,
                categories: categories,
                city_filter: city_filter,
            },
            success: function (res) {
            $btn.prop('disabled', false);
            $('#gdwaws-spinner').hide();

            if (!res.success) {
                alert('Preview failed: ' + (res.data.message || 'Unknown error'));
                return;
            }

            var previews = res.data.previews || [];
            if (previews.length === 0) {
                alert('No businesses found. Try adjusting your region, categories, or radius.');
                return;
            }

            renderPreview(previews, post_type);

            },
            error: function (xhr, status) {
                $btn.prop('disabled', false);
                $('#gdwaws-spinner').hide();
                var msg = status === 'timeout'
                    ? 'Request timed out. Try selecting fewer categories at once.'
                    : 'AJAX request failed (status: ' + status + '). Check your server error logs.';
                alert('❌ ' + msg);
            }
        });
    });

    // ── Render Preview Table ─────────────────────────────────────
    function renderPreview(previews, post_type) {
        previewData = {};
        var tbody = $('#gdwaws-preview-body').empty();
        var total = previews.length;
        var dupCount = 0;

        previews.forEach(function (p) {
            previewData[p.place_id] = p;
            if (p.is_duplicate) dupCount++;

            var isDup    = p.is_duplicate;
            var checked  = isDup ? '' : 'checked';
            var rowClass = isDup ? 'gdwaws-duplicate' : '';

            var website = p.website ? '<a href="' + escHtml(p.website) + '" target="_blank" class="gdwaws-biz-site">' + escHtml(p.website.replace(/^https?:\/\//, '').substring(0,30)) + '</a>' : '';
            var phone   = p.phone ? '<div class="gdwaws-biz-phone">' + escHtml(p.phone) + '</div>' : '';

            var statusHtml = isDup
                ? '<span class="gdwaws-status-dup">⚠ Duplicate</span><div class="gdwaws-status-info">' + escHtml(p.duplicate_reason) + '</div>'
                : '<span class="gdwaws-status-ok">✓ New</span>';

            var useClaude = false; // We'll set this from a data attr below
            var descNote  = p.description_source === 'none'
                ? '<div style="font-size:11px;color:#888;margin-top:4px;">⚡ AI description will be generated on import</div>'
                : p.description_source === 'google'
                ? '<div style="font-size:11px;color:#888;margin-top:4px;">📝 Google summary shown — AI will enhance on import</div>'
                : '';

            var row = $('<tr class="' + rowClass + '">').html(
                '<td><input type="checkbox" class="gdwaws-preview-check" data-place-id="' + escHtml(p.place_id) + '" ' + checked + '></td>' +
                '<td><div class="gdwaws-biz-name">' + escHtml(p.name) + '</div>' + phone + website + '</td>' +
                '<td style="font-size:12px;">' + escHtml(p.address) + '</td>' +
                '<td style="font-size:12px;">' + escHtml(p.category) + '</td>' +
                '<td><textarea class="gdwaws-desc-edit" data-place-id="' + escHtml(p.place_id) + '" data-source="' + escHtml(p.description_source || 'edited') + '">' + escHtml(p.description) + '</textarea>' + descNote + '</td>' +
                '<td>' + statusHtml + '</td>'
            );
            tbody.append(row);
        });

        var summary = total + ' businesses found';
        if (dupCount > 0) summary += ' (' + dupCount + ' duplicates unchecked)';
        $('#gdwaws-preview-count').text(summary);

        $('#gdwaws-preview-wrap').data('post-type', post_type).show();
        updateConfirmButton();
        $('html, body').animate({ scrollTop: $('#gdwaws-preview-wrap').offset().top - 40 }, 300);
    }

    // ── Check / Uncheck All Preview ──────────────────────────────
    $('#gdwaws-check-all').on('click', function () {
        $('.gdwaws-preview-check').prop('checked', true);
        updateConfirmButton();
    });
    $('#gdwaws-uncheck-all').on('click', function () {
        $('.gdwaws-preview-check').prop('checked', false);
        updateConfirmButton();
    });

    $(document).on('change', '.gdwaws-preview-check', function () {
        updateConfirmButton();
    });

    function updateConfirmButton() {
        var count = $('.gdwaws-preview-check:checked').length;
        var label = count > 0 ? '✅ Import ' + count + ' Selected' : '✅ Import Selected';
        $('#gdwaws-confirm-import, #gdwaws-confirm-import-bottom').prop('disabled', count === 0).text(label);
    }

    // ── Confirm Import ───────────────────────────────────────────
    function doConfirmImport() {
        var post_type = $('#gdwaws-preview-wrap').data('post-type') || 'gd_place';
        var selections = [];

        // Collect only place_id + description — re-fetch everything else server-side
        $('.gdwaws-preview-check:checked').each(function () {
            var place_id   = $(this).data('place-id');
            var $textarea  = $('textarea.gdwaws-desc-edit[data-place-id="' + place_id + '"]');
            var desc       = $textarea.val();
            var origSource = $textarea.data('source') || 'google';
            var p          = previewData[place_id] || {};
            // Mark as edited only if user changed the text from what was shown
            var descSource = ( desc !== (p.description || '') ) ? 'edited' : origSource;

            selections.push({ place_id: place_id, description: desc, description_source: descSource });
        });

        if (selections.length === 0) { alert('No items selected.'); return; }

        $('#gdwaws-confirm-import, #gdwaws-confirm-import-bottom').prop('disabled', true);
        $('#gdwaws-import-spinner').show();
        $('#gdwaws-log-wrap').show();
        $('#gdwaws-log').html('<div class="gdwaws-log-line"><span class="gdwaws-log-info">ℹ️ Importing ' + selections.length + ' listings...</span></div>');

        $.ajax({
            url: GDWAWS.ajax_url,
            type: 'POST',
            timeout: 300000,
            data: {
                action:     'gdwaws_confirm_import',
                nonce:      GDWAWS.nonce,
                post_type:  post_type,
                selections: selections,
            },
            success: function (res) {
            $('#gdwaws-import-spinner').hide();
            updateConfirmButton();

            if (!res.success) {
                $('#gdwaws-log').append('<div class="gdwaws-log-line gdwaws-log-error">❌ ' + escHtml(res.data.message || 'Error') + '</div>');
                return;
            }

            (res.data.log || []).forEach(function (entry) {
                var cls  = 'gdwaws-log-' + entry.type;
                var icon = entry.type === 'success' ? '✅' : entry.type === 'error' ? '❌' : entry.type === 'skip' ? '⏭' : 'ℹ️';
                $('#gdwaws-log').append(
                    '<div class="gdwaws-log-line">' +
                    '<span class="gdwaws-log-time">[' + escHtml(entry.time) + ']</span> ' +
                    '<span class="' + cls + '">' + icon + ' ' + escHtml(entry.message) + '</span>' +
                    '</div>'
                );
            });

            $('#gdwaws-log')[0].scrollTop = $('#gdwaws-log')[0].scrollHeight;

            },
            error: function (xhr, status) {
                $('#gdwaws-import-spinner').hide();
                updateConfirmButton();
                var msg = status === 'timeout'
                    ? 'Import timed out. Try importing fewer listings at once.'
                    : 'AJAX request failed (status: ' + status + '). Check your server error logs.';
                $('#gdwaws-log').append('<div class="gdwaws-log-line gdwaws-log-error">❌ ' + escHtml(msg) + '</div>');
            }
        });
    }

    $('#gdwaws-confirm-import, #gdwaws-confirm-import-bottom').on('click', doConfirmImport);

    // ── Bulk Publish ─────────────────────────────────────────────
    $('#gdwaws-bulk-publish').on('click', function () {
        var post_type = $('#gdwaws-preview-wrap').data('post-type') || $('#gdwaws_post_type').val() || 'gd_place';
        if (!confirm('Publish all draft listings imported by this plugin for post type "' + post_type + '"?')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Publishing...');

        $.post(GDWAWS.ajax_url, {
            action:    'gdwaws_bulk_publish',
            nonce:     GDWAWS.nonce,
            post_type: post_type,
        }, function (res) {
            $btn.prop('disabled', false).text('🚀 Bulk Publish All Drafts');
            if (res.success) {
                alert('✅ Published ' + res.data.published + ' of ' + res.data.total + ' draft listings.');
            } else {
                alert('❌ Bulk publish failed.');
            }
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
            action: 'gdwaws_save_settings', nonce: GDWAWS.nonce,
            google_api_key: $('#google_api_key').val(),
            anthropic_api_key: $('#anthropic_api_key').val(),
            use_claude: $('#use_claude').is(':checked') ? '1' : '0',
            anthropic_model: $('#anthropic_model').val(),
            default_region: $('#default_region').val(),
            import_limit: $('#import_limit').val(),
            post_status: $('#post_status').val(),
            geodir_post_type: $('#geodir_post_type').val(),
        };
        $btn.prop('disabled', true).text('Saving...');
        $.post(GDWAWS.ajax_url, data, function (res) {
            $btn.prop('disabled', false).text('Save Settings');
            $msg.removeClass('success error').show();
            $msg.addClass(res.success ? 'success' : 'error').text(res.success ? '✅ ' + res.data.message : '❌ ' + (res.data.message || 'Error'));
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

    // ── Clear History ────────────────────────────────────────────
    $('#gdwaws-clear-history').on('click', function () {
        $('#gdwaws-clear-confirm').show();
        $(this).hide();
    });
    $('#gdwaws-clear-confirm-no').on('click', function () {
        $('#gdwaws-clear-confirm').hide();
        $('#gdwaws-clear-history').show();
    });
    $('#gdwaws-clear-confirm-yes').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Clearing...');
        $.post(GDWAWS.ajax_url, { action: 'gdwaws_clear_history', nonce: GDWAWS.nonce }, function (res) {
            $('#gdwaws-clear-confirm').hide();
            var $msg = $('#gdwaws-clear-msg');
            $msg.show();
            if (res.success) {
                $msg.css({ background: '#dff0d8', border: '1px solid #d6e9c6', color: '#3c763d', padding: '10px 14px', borderRadius: '4px' });
                $msg.text(res.data.message);
                $('#gdwaws-history-table tbody').html('<tr><td colspan="6">No imports yet.</td></tr>');
                $('#gdwaws-clear-history').hide();
            } else {
                $msg.css({ background: '#f2dede', border: '1px solid #ebccd1', color: '#a94442', padding: '10px 14px', borderRadius: '4px' });
                $msg.text(res.data.message);
                $btn.prop('disabled', false).text('Yes, clear history');
                $('#gdwaws-clear-history').show();
            }
        });
    });

    // ── Utility ─────────────────────────────────────────────────
    function escHtml(str) {
        return $('<div>').text(str || '').html();
    }
});
