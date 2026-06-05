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

    // ── Confirm Import (batch processing) ───────────────────────
    function doConfirmImport() {
        var post_type  = $('#gdwaws-preview-wrap').data('post-type') || 'gd_place';
        var BATCH_SIZE = 5;
        var selections = [];

        $('.gdwaws-preview-check:checked').each(function () {
            var place_id   = $(this).data('place-id');
            var $textarea  = $('textarea.gdwaws-desc-edit[data-place-id="' + place_id + '"]');
            var desc       = $textarea.val();
            var origSource = $textarea.data('source') || 'google';
            var p          = previewData[place_id] || {};
            var descSource = ( desc !== (p.description || '') ) ? 'edited' : origSource;
            selections.push({ place_id: place_id, description: desc, description_source: descSource });
        });

        if (selections.length === 0) { alert('No items selected.'); return; }

        $('#gdwaws-confirm-import, #gdwaws-confirm-import-bottom').prop('disabled', true);
        $('#gdwaws-import-spinner').show();
        $('#gdwaws-log-wrap').show();
        $('#gdwaws-log').html(
            '<div class="gdwaws-log-line"><span class="gdwaws-log-info">ℹ️ Importing ' +
            selections.length + ' listings in batches of ' + BATCH_SIZE + '...</span></div>'
        );

        // Split into batches
        var batches = [];
        for (var i = 0; i < selections.length; i += BATCH_SIZE) {
            batches.push(selections.slice(i, i + BATCH_SIZE));
        }

        var batchIndex = 0;
        var totalDone  = 0;

        function runNextBatch() {
            if (batchIndex >= batches.length) {
                $('#gdwaws-import-spinner').hide();
                updateConfirmButton();
                appendLog('info', '✅ All batches complete. ' + totalDone + ' listings processed.');
                return;
            }

            var batch    = batches[batchIndex];
            var batchNum = batchIndex + 1;
            batchIndex++;

            appendLog('info', '📦 Batch ' + batchNum + ' of ' + batches.length + ' (' + batch.length + ' listings)...');

            $.ajax({
                url:     GDWAWS.ajax_url,
                type:    'POST',
                timeout: 120000,
                data: {
                    action:     'gdwaws_confirm_import',
                    nonce:      GDWAWS.nonce,
                    post_type:  post_type,
                    selections: batch,
                },
                success: function (res) {
                    if (!res.success) {
                        appendLog('error', 'Batch ' + batchNum + ' failed: ' + (res.data.message || 'Unknown error'));
                    } else {
                        (res.data.log || []).forEach(function (entry) {
                            appendLog(entry.type, entry.message, entry.time);
                        });
                        totalDone += batch.length;
                    }
                    runNextBatch();
                },
                error: function (xhr, status) {
                    var msg = status === 'timeout'
                        ? 'Batch ' + batchNum + ' timed out — skipping to next batch.'
                        : 'Batch ' + batchNum + ' request failed (status: ' + status + ').';
                    appendLog('error', msg);
                    runNextBatch();
                }
            });
        }

        runNextBatch();
    }

    function appendLog(type, message, time) {
        var $log = $('#gdwaws-log');
        var cls  = 'gdwaws-log-' + type;
        var icon = type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'skip' ? '⏭' : 'ℹ️';
        $log.append(
            '<div class="gdwaws-log-line">' +
            ( time ? '<span class="gdwaws-log-time">[' + escHtml(time) + ']</span> ' : '' ) +
            '<span class="' + cls + '">' + icon + ' ' + escHtml(message) + '</span>' +
            '</div>'
        );
        $log[0].scrollTop = $log[0].scrollHeight;
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
