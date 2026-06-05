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
     * Run imports for multiple categories, combining results.
     */
    public function run_multi( $region, $categories, $radius = 8000, $city_filter = '', $post_type = 'gd_place' ) {
        $this->log = [];

        if ( empty( $categories ) ) {
            $this->log_entry( 'error', 'No categories selected.' );
            return $this->log;
        }

        $this->log_entry( 'info', "Importing into post type: {$post_type}" );
        $this->log_entry( 'info', count( $categories ) . ' categories selected: ' . implode( ', ', $categories ) );

        foreach ( $categories as $category ) {
            $this->log_entry( 'info', "─── Starting category: {$category} ───" );
            $this->run( $region, $category, $radius, $city_filter, $post_type );
        }

        $this->log_entry( 'info', '✅ All categories complete.' );
        return $this->log;
    }

    /**
     * Run a full import for a region + type.
     */
    public function run( $region, $type = 'establishment', $radius = 8000, $city_filter = '', $post_type = '' ) {
        // Use saved post type if not passed
        if ( empty( $post_type ) ) {
            $post_type = GDWAWS_Settings::get( 'geodir_post_type', 'gd_place' );
        }
        $this->log = [];

        // Extract city name from region string for filtering (e.g. "Goliad, TX" → "Goliad")
        $city_name = '';
        if ( $city_filter ) {
            $parts     = explode( ',', $region );
            $city_name = trim( $parts[0] );
        }

        $filter_msg = $city_name ? " / filtering to city: {$city_name}" : '';
        $this->log_entry( 'info', "Starting import for: {$region} / {$type} / radius: " . ( $radius / 1000 ) . "km{$filter_msg}" );

        // 1. Fetch businesses from Google
        $businesses = $this->places_api->nearby_search( $region, $type, $radius );
        if ( is_wp_error( $businesses ) ) {
            $this->log_entry( 'error', 'Google Places error: ' . $businesses->get_error_message() );
            return $this->log;
        }

        $this->log_entry( 'info', count( $businesses ) . ' businesses found.' );

        // 2. Filter by city if enabled
        if ( $city_name ) {
            $before = count( $businesses );
            $businesses = array_filter( $businesses, function( $biz ) use ( $city_name ) {
                $address = $biz['formatted_address'] ?? $biz['formattedAddress'] ?? '';
                return $this->address_matches_city( $address, $city_name );
            });
            $businesses = array_values( $businesses );
            $skipped = $before - count( $businesses );
            if ( $skipped > 0 ) {
                $this->log_entry( 'info', "{$skipped} businesses outside {$city_name} filtered out." );
            }
            $this->log_entry( 'info', count( $businesses ) . ' businesses match city filter.' );
        }

        foreach ( $businesses as $biz ) {
            $this->import_single( $biz, $post_type );
        }

        $this->log_entry( 'info', 'Import complete.' );
        return $this->log;
    }

    /**
     * Import a single business from a nearby search result.
     */
    private function import_single( $biz, $post_type = '' ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'gdwaws_import_log';
        $place_id = $biz['place_id'] ?? $biz['id'] ?? '';
        $name     = $biz['name'] ?? $biz['displayName']['text'] ?? 'Unknown';
        if ( empty( $post_type ) ) {
            $post_type = GDWAWS_Settings::get( 'geodir_post_type', 'gd_place' );
        }

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

        // Build post data
        $post_data = [
            'post_title'   => sanitize_text_field( $name ),
            'post_content' => wp_kses_post( $description ),
            'post_status'  => GDWAWS_Settings::get( 'post_status', 'draft' ),
            'post_type'    => $post_type,
        ];

        // GeoDirectory category taxonomy is based on post type name
        $cat_taxonomy = $post_type . 'category';
        $cat_id = $this->map_category( $details['types'] ?? [], $cat_taxonomy );

        if ( $cat_id ) {
            $post_data['tax_input'] = [ $cat_taxonomy => [ $cat_id ] ];
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

        // Import featured image from Google Photos
        $photos = $details['photos'] ?? [];
        if ( ! empty( $photos ) ) {
            $attachment_id = $this->places_api->fetch_featured_image( $photos, $post_id, $name );
            if ( is_wp_error( $attachment_id ) ) {
                $this->log_entry( 'info', "{$name} — No featured image: " . $attachment_id->get_error_message() );
            } else {
                set_post_thumbnail( $post_id, $attachment_id );
                $this->log_entry( 'info', "{$name} — Featured image set (Attachment ID: {$attachment_id})" );
            }
        } else {
            $this->log_entry( 'info', "{$name} — No photos available from Google." );
        }

        // Log success
        $this->db_log( $place_id, $name, $post_id, 'imported', 'Successfully imported.' );
        $this->log_entry( 'success', "{$name} — Imported (Post ID: {$post_id})" );
    }

    /**
     * Map Google place types to a GeoDirectory category ID.
     */
    private function map_category( $types, $taxonomy = 'gd_placecategory' ) {
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
                $term = get_term_by( 'name', $cat_name, $taxonomy );
                if ( $term ) return $term->term_id;

                // Create it if it doesn't exist
                $new_term = wp_insert_term( $cat_name, $taxonomy );
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
     * Check if a formatted address contains the target city name.
     * Uses loose matching to handle variations like "Goliad" vs "Goliad County".
     */
    private function address_matches_city( $address, $city_name ) {
        if ( empty( $address ) || empty( $city_name ) ) return true;

        // Normalize both strings — lowercase, remove punctuation
        $address_lower   = strtolower( $address );
        $city_lower      = strtolower( trim( $city_name ) );

        // Direct match — city name appears in address
        if ( strpos( $address_lower, $city_lower ) !== false ) {
            return true;
        }

        // Try matching just the first word of the city (e.g. "Fort" from "Fort Worth")
        $city_parts = explode( ' ', $city_lower );
        if ( count( $city_parts ) > 1 ) {
            $full_city = implode( ' ', $city_parts );
            if ( strpos( $address_lower, $full_city ) !== false ) {
                return true;
            }
        }

        return false;
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
