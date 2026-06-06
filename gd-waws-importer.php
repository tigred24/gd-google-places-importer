<?php
/**
 * Plugin Name:       GD Google Places Importer
 * Plugin URI:        https://github.com/tigred24/gd-google-places-importer
 * Description:       Import business listings from Google Places API with AI-generated descriptions via Claude.
 * Version:           1.4.2
 * Author:            We Are Web Services
 * Author URI:        https://wearewebservices.com
 * License:           GPL2
 * GitHub Plugin URI: tigred24/gd-google-places-importer
 * GitHub Branch:     main
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GDWAWS_VERSION', '1.4.2' );
define( 'GDWAWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GDWAWS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Auto-update from GitHub
require_once GDWAWS_PLUGIN_DIR . 'vendor/plugin-update-checker/load-v5p7.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/tigred24/gd-google-places-importer/',
    __FILE__,
    'gd-google-places-importer'
);
$update_checker->setBranch( 'main' );

require_once GDWAWS_PLUGIN_DIR . 'includes/class-gdwaws-settings.php';
require_once GDWAWS_PLUGIN_DIR . 'includes/class-gdwaws-google-places.php';
require_once GDWAWS_PLUGIN_DIR . 'includes/class-gdwaws-claude.php';
require_once GDWAWS_PLUGIN_DIR . 'includes/class-gdwaws-importer.php';
require_once GDWAWS_PLUGIN_DIR . 'includes/class-gdwaws-admin.php';

function gdwaws_init() {
    new GDWAWS_Admin();
}
add_action( 'plugins_loaded', 'gdwaws_init' );

register_activation_hook( __FILE__, 'gdwaws_activate' );
function gdwaws_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'gdwaws_import_log';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        place_id varchar(255) NOT NULL,
        business_name varchar(255) NOT NULL,
        post_id bigint(20) DEFAULT NULL,
        status varchar(50) NOT NULL DEFAULT 'pending',
        message text DEFAULT NULL,
        imported_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY place_id (place_id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
