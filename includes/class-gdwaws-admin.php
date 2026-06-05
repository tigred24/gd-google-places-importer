<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GDWAWS_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_gdwaws_run_import', [ $this, 'ajax_run_import' ] );
        add_action( 'wp_ajax_gdwaws_preview_import', [ $this, 'ajax_preview_import' ] );
        add_action( 'wp_ajax_gdwaws_confirm_import', [ $this, 'ajax_confirm_import' ] );
        add_action( 'wp_ajax_gdwaws_bulk_publish', [ $this, 'ajax_bulk_publish' ] );
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
        $counts         = GDWAWS_Importer::get_counts();
        $place_types    = GDWAWS_Settings::google_place_types();
        $default_region = GDWAWS_Settings::get( 'default_region', 'Goliad, TX' );
        $default_pt     = GDWAWS_Settings::get( 'geodir_post_type', 'gd_place' );

        // Get all GeoDirectory custom post types — try multiple methods
        $gd_post_types = [];

        // Method 1: geodir_get_posttypes() — returns array of post type name strings
        if ( function_exists( 'geodir_get_posttypes' ) ) {
            $pt_names = geodir_get_posttypes();
            if ( is_array( $pt_names ) ) {
                foreach ( $pt_names as $pt_name ) {
                    $pt_name = is_object( $pt_name ) ? $pt_name->post_type : (string) $pt_name;
                    $pt_obj  = get_post_type_object( $pt_name );
                    if ( $pt_obj ) {
                        $gd_post_types[ $pt_name ] = $pt_obj->label ?: $pt_name;
                    }
                }
            }
        }

        // Method 2: Scan all registered post types for GeoDirectory ones
        if ( empty( $gd_post_types ) ) {
            $all_pts = get_post_types( [ '_builtin' => false ], 'objects' );
            foreach ( $all_pts as $pt ) {
                if ( strpos( $pt->name, 'gd_' ) === 0 ) {
                    $gd_post_types[ $pt->name ] = $pt->label ?: $pt->name;
                }
            }
        }

        // Method 3: GeoDirectory stores post types in its options
        if ( empty( $gd_post_types ) ) {
            $gd_cpts = get_option( 'geodir_post_types', [] );
            if ( is_array( $gd_cpts ) ) {
                foreach ( $gd_cpts as $pt_name => $pt_data ) {
                    $label = is_array( $pt_data ) ? ( $pt_data['labels']['name'] ?? $pt_name ) : $pt_name;
                    $gd_post_types[ $pt_name ] = $label;
                }
            }
        }

        // Absolute fallback
        if ( empty( $gd_post_types ) ) {
            $gd_post_types['gd_place'] = 'Places (gd_place)';
        }
        ?>
        <div class="wrap gdwaws-wrap">
            <h1>🗺️ GD Google Places Importer</h1>
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
                            <p class="description">Enter a city and state (e.g. <code>Goliad, TX</code>). Google will search for matching businesses in that city.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gdwaws_post_type">Post Type</label></th>
                        <td>
                            <select id="gdwaws_post_type">
                                <?php foreach ( $gd_post_types as $pt_name => $pt_label ) : ?>
                                    <option value="<?php echo esc_attr( $pt_name ); ?>" <?php selected( $pt_name, $default_pt ); ?>><?php echo esc_html( $pt_label ); ?> (<?php echo esc_html( $pt_name ); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select which GeoDirectory post type to import listings into.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Google Place Categories</th>
                        <td>
                            <div id="gdwaws-category-wrap">
                                <div style="display:flex; gap:10px; margin-bottom:8px;">
                                    <button type="button" class="button" id="gdwaws-select-all-cats">Select All</button>
                                    <button type="button" class="button" id="gdwaws-deselect-all-cats">Deselect All</button>
                                </div>
                                <div id="gdwaws-category-list" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap:6px; max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:12px; border-radius:4px; background:#fafafa;">
                                    <?php foreach ( $place_types as $value => $label ) :
                                        if ( $value === 'establishment' ) continue; ?>
                                        <label style="display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer;">
                                            <input type="checkbox" class="gdwaws-cat-check" value="<?php echo esc_attr( $value ); ?>" checked />
                                            <?php echo esc_html( $label ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description" style="margin-top:6px;">Select one or more Google place categories to import. Each selected category will be searched separately.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>City Filter</th>
                        <td>
                            <label>
                                <input type="checkbox" id="gdwaws_city_filter" value="1" checked />
                                Only import businesses with the city name in their address
                            </label>
                            <p class="description">Filters out any results Google returns that are outside your target city.</p>
                        </td>
                    </tr>
                </table>

                <div class="gdwaws-actions">
                    <button id="gdwaws-preview-import" class="button button-primary button-hero">
                        🔍 Preview Import
                    </button>
                    <span id="gdwaws-spinner" class="gdwaws-spinner" style="display:none;">⏳ Fetching from Google...</span>
                </div>
            </div>

            <div id="gdwaws-preview-wrap" class="gdwaws-card" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h2 style="margin:0;">Preview Results</h2>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <span id="gdwaws-preview-count" style="font-size:13px; color:#666;"></span>
                        <button type="button" class="button" id="gdwaws-check-all">Check All</button>
                        <button type="button" class="button" id="gdwaws-uncheck-all">Uncheck All</button>
                        <button type="button" class="button button-primary" id="gdwaws-confirm-import" disabled>
                            ✅ Import Selected
                        </button>
                    </div>
                </div>
                <div id="gdwaws-preview-table-wrap" style="overflow-x:auto;">
                    <table class="widefat" id="gdwaws-preview-table">
                        <thead>
                            <tr>
                                <th style="width:30px;"></th>
                                <th>Business</th>
                                <th>Address</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="gdwaws-preview-body"></tbody>
                    </table>
                </div>
                <div style="margin-top:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <button type="button" class="button button-primary button-hero" id="gdwaws-confirm-import-bottom" disabled>
                        ✅ Import Selected
                    </button>
                    <button type="button" class="button button-hero" id="gdwaws-bulk-publish">
                        🚀 Bulk Publish All Drafts
                    </button>
                    <span id="gdwaws-import-spinner" class="gdwaws-spinner" style="display:none;">⏳ Importing...</span>
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

    public function ajax_preview_import() {
        check_ajax_referer( 'gdwaws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        // Extend limits for long-running import operations
        @set_time_limit( 300 );
        @ini_set( 'memory_limit', '256M' );

        $region      = sanitize_text_field( $_POST['region'] ?? 'Goliad, TX' );
        $post_type   = sanitize_text_field( $_POST['post_type'] ?? 'gd_place' );
        $categories  = isset( $_POST['categories'] ) ? array_map( 'sanitize_text_field', (array) $_POST['categories'] ) : [ 'establishment' ];
        $city_filter = sanitize_text_field( $_POST['city_filter'] ?? '' );

        GDWAWS_Settings::set( 'geodir_post_type', $post_type );

        $importer = new GDWAWS_Importer();
        $previews = $importer->preview_multi( $region, $categories, $city_filter, $post_type );

        wp_send_json_success( [ 'previews' => $previews ] );
    }

    public function ajax_confirm_import() {
        check_ajax_referer( 'gdwaws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        // Extend limits for long-running import operations
        @set_time_limit( 300 );
        @ini_set( 'memory_limit', '256M' );

        $post_type = sanitize_text_field( $_POST['post_type'] ?? 'gd_place' );
        $raw_items = isset( $_POST['items'] ) ? (array) $_POST['items'] : [];

        // Sanitize each item
        $items = [];
        foreach ( $raw_items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $items[] = [
                'place_id'       => sanitize_text_field( $item['place_id'] ?? '' ),
                'name'           => sanitize_text_field( $item['name'] ?? '' ),
                'description'    => wp_kses_post( $item['description'] ?? '' ),
                'address'        => sanitize_text_field( $item['address'] ?? '' ),
                'address_parsed' => array_map( 'sanitize_text_field', (array) ( $item['address_parsed'] ?? [] ) ),
                'phone'          => sanitize_text_field( $item['phone'] ?? '' ),
                'website'        => esc_url_raw( $item['website'] ?? '' ),
                'rating'         => sanitize_text_field( $item['rating'] ?? '' ),
                'lat'            => sanitize_text_field( $item['lat'] ?? '' ),
                'lng'            => sanitize_text_field( $item['lng'] ?? '' ),
                'hours'          => array_map( 'sanitize_text_field', (array) ( $item['hours'] ?? [] ) ),
                'types'          => array_map( 'sanitize_text_field', (array) ( $item['types'] ?? [] ) ),
                'photos'         => (array) ( $item['photos'] ?? [] ),
            ];
        }

        $importer = new GDWAWS_Importer();
        $log      = $importer->import_confirmed( $items, $post_type );

        wp_send_json_success( [ 'log' => $log ] );
    }

    public function ajax_bulk_publish() {
        check_ajax_referer( 'gdwaws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $post_type = sanitize_text_field( $_POST['post_type'] ?? 'gd_place' );
        $result    = GDWAWS_Importer::bulk_publish( $post_type );

        wp_send_json_success( $result );
    }

    public function ajax_run_import() {
        check_ajax_referer( 'gdwaws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $region      = sanitize_text_field( $_POST['region'] ?? 'Goliad, TX' );
        $post_type   = sanitize_text_field( $_POST['post_type'] ?? 'gd_place' );
        $categories  = isset( $_POST['categories'] ) ? array_map( 'sanitize_text_field', (array) $_POST['categories'] ) : [ 'establishment' ];
        $limit       = intval( $_POST['limit'] ?? 20 );
        $radius      = intval( $_POST['radius'] ?? 8000 );
        $city_filter = ! empty( $_POST['city_filter'] ) ? sanitize_text_field( $_POST['city_filter'] ) : '';

        GDWAWS_Settings::set( 'import_limit', $limit );
        GDWAWS_Settings::set( 'search_radius', $radius );
        GDWAWS_Settings::set( 'geodir_post_type', $post_type );

        $importer = new GDWAWS_Importer();
        $log      = $importer->run_multi( $region, $categories, $radius, $city_filter, $post_type );

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

        // Test new Places API text search
        $places_resp = wp_remote_post( 'https://places.googleapis.com/v1/places:searchText', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Goog-Api-Key'   => $key,
                'X-Goog-FieldMask' => 'places.id,places.displayName',
            ],
            'body' => json_encode([
                'textQuery'      => 'businesses in Goliad, TX',
                'maxResultCount' => 1,
            ]),
        ]);

        if ( is_wp_error( $places_resp ) ) {
            wp_send_json_error( [ 'message' => $places_resp->get_error_message() ] );
        }

        $places_body = json_decode( wp_remote_retrieve_body( $places_resp ), true );

        if ( isset( $places_body['error'] ) ) {
            wp_send_json_error( [ 'message' => 'Places API: ' . $places_body['error']['message'] ] );
        }

        $count = count( $places_body['places'] ?? [] );
        wp_send_json_success( [ 'message' => '✅ Google APIs connected! Text Search found ' . $count . ' result(s) for Goliad, TX.' ] );
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
