<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GDWAWS_Importer {

    private $places_api;
    private $claude;
    private $log = [];

    public function __construct() {
        $this->places_api = new GDWAWS_Google_Places();
        $this->claude     = new GDWAWS_Claude();
    }

    /**
     * Run a full import for a region + type.
     */
    public function run( $region, $type = 'establishment', $radius = 8000 ) {
        $this->log = [];

        $this->log_entry( 'info', "Starting import for: {$region} / {$type} / radius: " . ($radius/1000) . "km" );

        // 1. Fetch businesses from Google
        $businesses = $this->places_api->nearby_search( $region, $type, $radius );
        if ( is_wp_error( $businesses ) ) {
            $this->log_entry( 'error', 'Google Places error: ' . $businesses->get_error_message() );
            return $this->log;
        }

        $this->log_entry( 'info', count( $businesses ) . ' businesses found.' );

        foreach ( $businesses as $biz ) {
            $this->import_single( $biz );
        }

        $this->log_entry( 'info', 'Import complete.' );
        return $this->log;
    }

    /**
     * Import a single business from a nearby search result.
     */
    private function import_single( $biz ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'gdwaws_import_log';
        $place_id = $biz['place_id'] ?? $biz['id'] ?? '';
        $name     = $biz['name'] ?? $biz['displayName']['text'] ?? 'Unknown';

        // Skip if already imported
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE place_id = %s", $place_id ) );
        if ( $existing ) {
            $this->log_entry( 'skip', "{$name} — already imported, skipping." );
            return;
        }

        // Get full details (normalized to internal format)
        $details = $this->places_api->get_place_details( $place_id );
        if ( is_wp_error( $details ) ) {
            $this->log_entry( 'error', "{$name} — Details error: " . $details->get_error_message() );
            $this->db_log( $place_id, $name, null, 'error', $details->get_error_message() );
            return;
        }

        // Skip permanently closed
        if ( isset( $details['business_status'] ) && $details['business_status'] === 'CLOSED_PERMANENTLY' ) {
            $this->log_entry( 'skip', "{$name} — permanently closed, skipping." );
            return;
        }

        // Generate description — AI or Google fallback
        $use_ai = GDWAWS_Settings::get( 'use_claude', '1' ) === '1' && ! empty( GDWAWS_Settings::get( 'anthropic_api_key' ) );
        $google_summary = isset( $details['editorial_summary']['overview'] ) ? $details['editorial_summary']['overview'] : '';

        if ( $use_ai ) {
            $description = $this->claude->generate_description( $details );
            if ( is_wp_error( $description ) ) {
                $this->log_entry( 'error', "{$name} — Claude error: " . $description->get_error_message() . '. Using Google summary.' );
                $description = $google_summary;
            }
        } else {
            $description = $google_summary;
            if ( empty( $description ) ) {
                $this->log_entry( 'info', "{$name} — No Google summary available, description will be blank." );
            }
        }

        // Parse address
        $address = $this->places_api->parse_address( $details );

        // Map Google types to GeoDirectory category
        $cat_id = $this->map_category( $details['types'] ?? [] );

        // Build post data
        $post_data = [
            'post_title'   => sanitize_text_field( $name ),
            'post_content' => wp_kses_post( $description ),
            'post_status'  => GDWAWS_Settings::get( 'post_status', 'draft' ),
            'post_type'    => GDWAWS_Settings::get( 'geodir_post_type', 'gd_place' ),
        ];

        if ( $cat_id ) {
            $post_data['tax_input'] = [ 'gd_placecategory' => [ $cat_id ] ];
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            $this->log_entry( 'error', "{$name} — Post insert error: " . $post_id->get_error_message() );
            $this->db_log( $place_id, $name, null, 'error', $post_id->get_error_message() );
            return;
        }

        // Save GeoDirectory meta fields
        $lat = $details['geometry']['location']['lat'] ?? '';
        $lng = $details['geometry']['location']['lng'] ?? '';

        $meta = [
            'geodir_location'  => $address['full'],
            'geodir_address'   => $address['street'],
            'geodir_city'      => $address['city'],
            'geodir_region'    => $address['state'],
            'geodir_zip'       => $address['zip'],
            'geodir_country'   => 'US',
            'geodir_latitude'  => $lat,
            'geodir_longitude' => $lng,
            'geodir_phone'     => isset( $details['formatted_phone_number'] ) ? sanitize_text_field( $details['formatted_phone_number'] ) : '',
            'geodir_website'   => isset( $details['website'] ) ? esc_url_raw( $details['website'] ) : '',
            'geodir_timing'    => $this->format_hours( $details['opening_hours']['weekday_text'] ?? [] ),
            'gdwaws_place_id'    => $place_id,
            'gdwaws_rating'      => $details['rating'] ?? '',
        ];

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }

        // Log success
        $this->db_log( $place_id, $name, $post_id, 'imported', 'Successfully imported.' );
        $this->log_entry( 'success', "{$name} — Imported (Post ID: {$post_id})" );
    }

    /**
     * Map Google place types to a GeoDirectory category ID.
     */
    private function map_category( $types ) {
        $map = [
            'restaurant'              => 'Restaurants',
            'food'                    => 'Restaurants',
            'bar'                     => 'Bars & Nightlife',
            'cafe'                    => 'Cafes',
            'lodging'                 => 'Hotels & Lodging',
            'store'                   => 'Shopping',
            'grocery_or_supermarket'  => 'Grocery',
            'gas_station'             => 'Gas Stations',
            'gym'                     => 'Health & Fitness',
            'hair_care'               => 'Beauty & Spas',
            'health'                  => 'Health & Medical',
            'doctor'                  => 'Health & Medical',
            'church'                  => 'Churches',
            'school'                  => 'Education',
            'bank'                    => 'Financial Services',
            'lawyer'                  => 'Legal Services',
            'real_estate_agency'      => 'Real Estate',
            'car_repair'              => 'Auto Services',
            'electrician'             => 'Home Services',
            'plumber'                 => 'Home Services',
            'general_contractor'      => 'Home Services',
        ];

        foreach ( $types as $type ) {
            if ( isset( $map[ $type ] ) ) {
                $cat_name = $map[ $type ];
                $term = get_term_by( 'name', $cat_name, 'gd_placecategory' );
                if ( $term ) return $term->term_id;

                // Create it if it doesn't exist
                $new_term = wp_insert_term( $cat_name, 'gd_placecategory' );
                if ( ! is_wp_error( $new_term ) ) return $new_term['term_id'];
            }
        }
        return null;
    }

    /**
     * Format opening hours array into a readable string.
     */
    private function format_hours( $hours_array ) {
        return implode( "\n", $hours_array );
    }

    /**
     * Add an entry to the in-memory log.
     */
    private function log_entry( $type, $message ) {
        $this->log[] = [ 'type' => $type, 'message' => $message, 'time' => current_time( 'H:i:s' ) ];
    }

    /**
     * Save an entry to the DB log table.
     */
    private function db_log( $place_id, $name, $post_id, $status, $message ) {
        global $wpdb;
        $wpdb->replace( $wpdb->prefix . 'gdwaws_import_log', [
            'place_id'      => $place_id,
            'business_name' => $name,
            'post_id'       => $post_id,
            'status'        => $status,
            'message'       => $message,
        ]);
    }

    /**
     * Get import history from DB.
     */
    public static function get_history( $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwaws_import_log';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY imported_at DESC LIMIT %d", $limit ) );
    }

    /**
     * Get import counts.
     */
    public static function get_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwaws_import_log';
        return [
            'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ),
            'imported' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'imported'" ),
            'errors'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'error'" ),
        ];
    }
}
