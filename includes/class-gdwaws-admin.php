<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GDWAWS_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_gdwaws_run_import', [ $this, 'ajax_run_import' ] );
        add_action( 'wp_ajax_gdwaws_save_settings', [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_gdwaws_test_google', [ $this, 'ajax_test_google' ] );
        add_action( 'wp_ajax_gdwaws_test_claude', [ $this, 'ajax_test_claude' ] );
        add_action( 'wp_ajax_gdwaws_clear_history', [ $this, 'ajax_clear_history' ] );
    }

    public function register_menu() {
        add_menu_page(
            'GD Google Places Importer',
            'GD Google Places Importer',
            'manage_options',
            'gdwaws-importer',
            [ $this, 'render_dashboard' ],
            'dashicons-search',
            30
        );
        add_submenu_page( 'gdwaws-importer', 'Import', 'Import', 'manage_options', 'gdwaws-importer', [ $this, 'render_dashboard' ] );
        add_submenu_page( 'gdwaws-importer', 'History', 'History', 'manage_options', 'gdwaws-history', [ $this, 'render_history' ] );
        add_submenu_page( 'gdwaws-importer', 'Settings', 'Settings', 'manage_options', 'gdwaws-settings', [ $this, 'render_settings' ] );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'gdwaws' ) === false ) return;
        wp_enqueue_style( 'gdwaws-admin', GDWAWS_PLUGIN_URL . 'assets/admin.css', [], GDWAWS_VERSION );
        wp_enqueue_script( 'gdwaws-admin', GDWAWS_PLUGIN_URL . 'assets/admin.js', [ 'jquery' ], GDWAWS_VERSION, true );
        wp_localize_script( 'gdwaws-admin', 'GDWAWS', [
            'nonce'    => wp_create_nonce( 'gdwaws_nonce' ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ]);
    }

    public function render_dashboard() {
        $counts       = GDWAWS_Importer::get_counts();
        $place_types  = GDWAWS_Settings::google_place_types();
        $default_region = GDWAWS_Settings::get( 'default_region', 'Goliad, TX' );
        $default_type   = GDWAWS_Settings::get( 'default_category', 'establishment' );
        $import_limit   = GDWAWS_Settings::get( 'import_limit', 20 );
        $default_radius = GDWAWS_Settings::get( 'search_radius', 8000 );
        ?>
        <div class="wrap gdwaws-wrap">
            <h1>🗺️ GeoDirectory WAWS Business Importer</h1>
            <p class="gdwaws-subtitle">Import business listings from Google Places with AI-generated descriptions via Claude.</p>

            <div class="gdwaws-stats">
                <div class="gdwaws-stat">
                    <span class="gdwaws-stat-number"><?php echo $counts['total']; ?></span>
                    <span class="gdwaws-stat-label">Total Processed</span>
                </div>
                <div class="gdwaws-stat gdwaws-stat-success">
                    <span class="gdwaws-stat-number"><?php echo $counts['imported']; ?></span>
                    <span class="gdwaws-stat-label">Imported</span>
                </div>
                <div class="gdwaws-stat gdwaws-stat-error">
                    <span class="gdwaws-stat-number"><?php echo $counts['errors']; ?></span>
                    <span class="gdwaws-stat-label">Errors</span>
                </div>
            </div>

            <div class="gdwaws-card">
                <h2>Run Import</h2>
                <table class="form-table gdwaws-form-table">
                    <tr>
                        <th><label for="gdwaws_region">Region / Location</label></th>
                        <td>
                            <input type="text" id="gdwaws_region" class="regular-text" value="<?php echo esc_attr( $default_region ); ?>" placeholder="e.g. Goliad, TX" />
                            <p class="description">City, address, or area to search within (~5 mile radius)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gdwaws_type">Business Type</label></th>
                        <td>
                            <select id="gdwaws_type">
                                <?php foreach ( $place_types as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $default_type ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gdwaws_limit">Max Listings</label></th>
                        <td>
                            <input type="number" id="gdwaws_limit" class="small-text" value="<?php echo esc_attr( $import_limit ); ?>" min="1" max="60" />
                            <p class="description">Max 60 per run (Google Places limit per search)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gdwaws_radius">Search Radius</label></th>
                        <td>
                            <select id="gdwaws_radius">
                                <?php
                                $radii = [
                                    1000  => '1 km (~0.6 miles) — Single neighborhood',
                                    2000  => '2 km (~1.2 miles) — Small area',
                                    5000  => '5 km (~3 miles) — Small town',
                                    8000  => '8 km (~5 miles) — Default',
                                    15000 => '15 km (~9 miles) — Large town',
                                    25000 => '25 km (~15 miles) — County area',
                                    50000 => '50 km (~31 miles) — Wide region',
                                ];
                                foreach ( $radii as $meters => $label ) :
                                ?>
                                    <option value="<?php echo $meters; ?>" <?php selected( $meters, $default_radius ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Larger radius = more results but may include businesses outside your target area.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>City Filter</th>
                        <td>
                            <label>
                                <input type="checkbox" id="gdwaws_city_filter" value="1" checked />
                                Only import businesses located in the city entered above
                            </label>
                            <p class="description">When checked, results from surrounding areas outside your city will be skipped. Uncheck to import everything within the radius regardless of city.</p>
                        </td>
                    </tr>
                </table>

                <div class="gdwaws-actions">
                    <button id="gdwaws-run-import" class="button button-primary button-hero">
                        ▶ Start Import
                    </button>
                    <span id="gdwaws-spinner" class="gdwaws-spinner" style="display:none;">⏳ Importing...</span>
                </div>
            </div>

            <div id="gdwaws-log-wrap" class="gdwaws-card" style="display:none;">
                <h2>Import Log</h2>
                <div id="gdwaws-log"></div>
            </div>
        </div>
        <?php
    }

    public function render_history() {
        $history = GDWAWS_Importer::get_history( 100 );
        $counts  = GDWAWS_Importer::get_counts();
        ?>
        <div class="wrap gdwaws-wrap">
            <h1>📋 Import History</h1>
            <div class="gdwaws-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <p style="margin:0; color:var(--color-text-secondary, #666);">
                        <?php echo $counts['total']; ?> total records
                        (<?php echo $counts['imported']; ?> imported,
                        <?php echo $counts['errors']; ?> errors)
                    </p>
                    <?php if ( ! empty( $history ) ) : ?>
                    <div>
                        <button id="gdwaws-clear-history" class="button button-link-delete">
                            🗑 Clear All History
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="gdwaws-clear-confirm" style="display:none; background:#fff3cd; border:1px solid #ffc107; border-radius:4px; padding:12px 16px; margin-bottom:16px;">
                    <strong>Are you sure?</strong> This will clear all import history records.
                    Existing GeoDirectory listings will <strong>not</strong> be deleted, but the plugin will
                    forget which businesses have already been imported — re-running an import could create duplicates.
                    <div style="margin-top:10px; display:flex; gap:10px;">
                        <button id="gdwaws-clear-confirm-yes" class="button button-primary">Yes, clear history</button>
                        <button id="gdwaws-clear-confirm-no" class="button">Cancel</button>
                    </div>
                </div>

                <div id="gdwaws-clear-msg" style="display:none; margin-bottom:12px;"></div>

                <table class="widefat striped" id="gdwaws-history-table">
                    <thead>
                        <tr>
                            <th>Business</th>
                            <th>Status</th>
                            <th>Post ID</th>
                            <th>Place ID</th>
                            <th>Message</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $history ) ) : ?>
                            <tr><td colspan="6">No imports yet.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $history as $row ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $row->business_name ); ?></strong></td>
                                    <td><span class="gdwaws-badge gdwaws-badge-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status ); ?></span></td>
                                    <td><?php echo $row->post_id ? '<a href="' . get_edit_post_link( $row->post_id ) . '">#' . $row->post_id . '</a>' : '—'; ?></td>
                                    <td><small><?php echo esc_html( substr( $row->place_id, 0, 20 ) . '...' ); ?></small></td>
                                    <td><?php echo esc_html( $row->message ); ?></td>
                                    <td><?php echo esc_html( $row->imported_at ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function render_settings() {
        $settings = [
            'google_api_key'    => GDWAWS_Settings::get( 'google_api_key' ),
            'anthropic_api_key' => GDWAWS_Settings::get( 'anthropic_api_key' ),
            'anthropic_model'   => GDWAWS_Settings::get( 'anthropic_model', 'claude-sonnet-4-6' ),
            'use_claude'        => GDWAWS_Settings::get( 'use_claude', '0' ),
            'default_region'    => GDWAWS_Settings::get( 'default_region', 'Goliad, TX' ),
            'default_category'  => GDWAWS_Settings::get( 'default_category', 'establishment' ),
            'import_limit'      => GDWAWS_Settings::get( 'import_limit', 20 ),
            'post_status'       => GDWAWS_Settings::get( 'post_status', 'draft' ),
            'geodir_post_type'  => GDWAWS_Settings::get( 'geodir_post_type', 'gd_place' ),
        ];
        ?>
        <div class="wrap gdwaws-wrap">
            <h1>⚙️ Settings</h1>
            <div class="gdwaws-card">
                <table class="form-table gdwaws-form-table">
                    <tr>
                        <th><label for="google_api_key">Google Places API Key</label></th>
                        <td>
                            <input type="password" id="google_api_key" name="google_api_key" class="regular-text" value="<?php echo esc_attr( $settings['google_api_key'] ); ?>" />
                            <button type="button" class="button" id="gdwaws-test-google">Test Connection</button>
                            <p class="description">From Google Cloud Console → APIs & Services → Credentials</p>
                        </td>
                    </tr>
                    <tr>
                        <th>AI Descriptions</th>
                        <td>
                            <label>
                                <input type="checkbox" id="use_claude" name="use_claude" value="1" <?php checked( $settings['use_claude'], '1' ); ?> />
                                Use Claude AI to write business descriptions
                            </label>
                            <p class="description">When unchecked, the plugin uses Google's description instead. No Anthropic account needed.</p>
                        </td>
                    </tr>
                    <tr id="gdwaws-claude-row" style="<?php echo $settings['use_claude'] === '1' ? '' : 'display:none;'; ?>">
                        <th><label for="anthropic_api_key">Anthropic (Claude) API Key</label></th>
                        <td>
                            <input type="password" id="anthropic_api_key" name="anthropic_api_key" class="regular-text" value="<?php echo esc_attr( $settings['anthropic_api_key'] ); ?>" />
                            <button type="button" class="button" id="gdwaws-test-claude">Test Connection</button>
                            <p class="description">From <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a> → API Keys. ~$0.001 per listing.</p>
                        </td>
                    </tr>
                    <tr id="gdwaws-model-row" style="<?php echo $settings['use_claude'] === '1' ? '' : 'display:none;'; ?>">
                        <th><label for="anthropic_model">Claude Model</label></th>
                        <td>
                            <input type="text" id="anthropic_model" name="anthropic_model" class="regular-text" value="<?php echo esc_attr( $settings['anthropic_model'] ); ?>" />
                            <p class="description">Current default: <code>claude-sonnet-4-6</code>. If the test connection fails with a model error, check the latest model names at <a href="https://docs.anthropic.com/en/docs/about-claude/models" target="_blank">Anthropic's model documentation ↗</a> and update this field.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="default_region">Default Region</label></th>
                        <td><input type="text" id="default_region" name="default_region" class="regular-text" value="<?php echo esc_attr( $settings['default_region'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="import_limit">Default Import Limit</label></th>
                        <td><input type="number" id="import_limit" name="import_limit" class="small-text" value="<?php echo esc_attr( $settings['import_limit'] ); ?>" min="1" max="60" /></td>
                    </tr>
                    <tr>
                        <th><label for="post_status">Import as</label></th>
                        <td>
                            <select id="post_status" name="post_status">
                                <option value="draft" <?php selected( $settings['post_status'], 'draft' ); ?>>Draft (review before publishing)</option>
                                <option value="publish" <?php selected( $settings['post_status'], 'publish' ); ?>>Published (go live immediately)</option>
                                <option value="pending" <?php selected( $settings['post_status'], 'pending' ); ?>>Pending Review</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geodir_post_type">GeoDirectory Post Type</label></th>
                        <td>
                            <input type="text" id="geodir_post_type" name="geodir_post_type" class="regular-text" value="<?php echo esc_attr( $settings['geodir_post_type'] ); ?>" />
                            <p class="description">Usually <code>gd_place</code></p>
                        </td>
                    </tr>
                </table>

                <div id="gdwaws-settings-msg" style="display:none;margin:10px 0;"></div>

                <p class="submit">
                    <button type="button" id="gdwaws-save-settings" class="button button-primary">Save Settings</button>
                </p>
            </div>
        </div>
        <?php
    }

    // ─── AJAX Handlers ───────────────────────────────────────────

    public function ajax_run_import() {
        check_ajax_referer( 'gdwaws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $region      = sanitize_text_field( $_POST['region'] ?? 'Goliad, TX' );
        $type        = sanitize_text_field( $_POST['type'] ?? 'establishment' );
        $limit       = intval( $_POST['limit'] ?? 20 );
        $radius      = intval( $_POST['radius'] ?? 8000 );
        $city_filter = ! empty( $_POST['city_filter'] ) ? sanitize_text_field( $_POST['city_filter'] ) : '';

        GDWAWS_Settings::set( 'import_limit', $limit );
        GDWAWS_Settings::set( 'search_radius', $radius );

        $importer = new GDWAWS_Importer();
        $log      = $importer->run( $region, $type, $radius, $city_filter );

        wp_send_json_success( [ 'log' => $log ] );
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'gdwaws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        GDWAWS_Settings::save( $_POST );
        wp_send_json_success( [ 'message' => 'Settings saved.' ] );
    }

    public function ajax_test_google() {
        check_ajax_referer( 'gdwaws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $key = sanitize_text_field( $_POST['key'] ?? '' );
        GDWAWS_Settings::set( 'google_api_key', $key );

        // Test geocoding API
        $geo_url  = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => 'Goliad, TX',
            'key'     => $key,
        ]);
        $geo_resp = wp_remote_get( $geo_url, [ 'timeout' => 10 ] );

        if ( is_wp_error( $geo_resp ) ) {
            wp_send_json_error( [ 'message' => $geo_resp->get_error_message() ] );
        }

        $geo_body = json_decode( wp_remote_retrieve_body( $geo_resp ), true );

        if ( isset( $geo_body['error_message'] ) ) {
            wp_send_json_error( [ 'message' => $geo_body['error_message'] ] );
        }

        if ( empty( $geo_body['results'] ) ) {
            wp_send_json_error( [ 'message' => 'Could not geocode test location.' ] );
        }

        $loc = $geo_body['results'][0]['geometry']['location'];

        // Test new Places API nearby search
        $places_resp = wp_remote_post( 'https://places.googleapis.com/v1/places:searchNearby', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Goog-Api-Key'   => $key,
                'X-Goog-FieldMask' => 'places.id,places.displayName',
            ],
            'body' => json_encode([
                'locationRestriction' => [
                    'circle' => [
                        'center' => [ 'latitude' => $loc['lat'], 'longitude' => $loc['lng'] ],
                        'radius' => 5000.0,
                    ],
                ],
                'maxResultCount' => 1,
            ]),
        ]);

        if ( is_wp_error( $places_resp ) ) {
            wp_send_json_error( [ 'message' => $places_resp->get_error_message() ] );
        }

        $places_body = json_decode( wp_remote_retrieve_body( $places_resp ), true );

        if ( isset( $places_body['error'] ) ) {
            wp_send_json_error( [ 'message' => 'New Places API: ' . $places_body['error']['message'] ] );
        }

        $count = count( $places_body['places'] ?? [] );
        wp_send_json_success( [ 'message' => '✅ Google APIs connected! Geocoded Goliad, TX → ' . $loc['lat'] . ', ' . $loc['lng'] . '. Found ' . $count . ' nearby place(s).' ] );
    }

    public function ajax_test_claude() {
        check_ajax_referer( 'gdwaws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $key = sanitize_text_field( $_POST['key'] ?? '' );
        GDWAWS_Settings::set( 'anthropic_api_key', $key );

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 15,
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => json_encode([
                'model'      => GDWAWS_Settings::get( 'anthropic_model', 'claude-sonnet-4-6' ),
                'max_tokens' => 20,
                'messages'   => [ [ 'role' => 'user', 'content' => 'Say "connected" and nothing else.' ] ],
            ]),
        ]);

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['error'] ) ) {
            wp_send_json_error( [ 'message' => $body['error']['message'] ] );
        }

        wp_send_json_success( [ 'message' => '✅ Claude API connected successfully!' ] );
    }

    public function ajax_clear_history() {
        check_ajax_referer( 'gdwaws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        global $wpdb;
        $table   = $wpdb->prefix . 'gdwaws_import_log';
        $deleted = $wpdb->query( "TRUNCATE TABLE $table" );

        if ( $deleted === false ) {
            wp_send_json_error( [ 'message' => 'Could not clear history. Please try again.' ] );
        }

        wp_send_json_success( [ 'message' => '✅ Import history cleared successfully.' ] );
    }
}
