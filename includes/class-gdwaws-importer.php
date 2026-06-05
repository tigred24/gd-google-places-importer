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

    // ─────────────────────────────────────────────────────────────
    // PREVIEW — fetch & enrich without saving anything
    // ─────────────────────────────────────────────────────────────

    /**
     * Preview import across multiple categories. Returns enriched business data.
     */
    public function preview_multi( $region, $categories, $city_filter = '', $post_type = 'gd_place' ) {
        $city_name = '';
        if ( $city_filter ) {
            $parts     = explode( ',', $region );
            $city_name = trim( $parts[0] );
        }

        $seen     = [];
        $previews = [];

        foreach ( $categories as $category ) {
            $businesses = $this->places_api->text_search( $region, $category );
            if ( is_wp_error( $businesses ) ) continue;

            // City filter
            if ( $city_name ) {
                $businesses = array_filter( $businesses, function( $biz ) use ( $city_name ) {
                    return $this->address_matches_city( $biz, $city_name );
                });
                $businesses = array_values( $businesses );
            }

            foreach ( $businesses as $biz ) {
                $place_id = $biz['place_id'] ?? $biz['id'] ?? '';
                if ( empty( $place_id ) || isset( $seen[ $place_id ] ) ) continue;
                $seen[ $place_id ] = true;

                $preview = $this->build_preview( $place_id, $post_type );
                if ( $preview ) $previews[] = $preview;
            }
        }

        return $previews;
    }

    /**
     * Fetch full details for one place and build a preview record.
     */
    private function build_preview( $place_id, $post_type ) {
        global $wpdb;

        $details = $this->places_api->get_place_details( $place_id );
        if ( is_wp_error( $details ) ) return null;

        // Skip permanently closed
        if ( ( $details['business_status'] ?? '' ) === 'CLOSED_PERMANENTLY' ) return null;

        $name    = $details['name'] ?? 'Unknown';
        $address = $details['formatted_address'] ?? '';

        // Check plugin import log
        $log_table   = $wpdb->prefix . 'gdwaws_import_log';
        $already_logged = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $log_table WHERE place_id = %s", $place_id ) );

        // Check for existing GeoDirectory post with same title
        $existing_post = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status != 'trash'",
            $name, $post_type
        ) );

        $duplicate_reason = '';
        if ( $already_logged ) {
            $duplicate_reason = 'Already in import history';
        } elseif ( $existing_post ) {
            $duplicate_reason = 'Listing with this name already exists (Post #' . $existing_post . ')';
        }

        // Description — use Google summary as placeholder only, AI generated at confirm time
        $google_summary = $details['editorial_summary']['overview'] ?? '';
        $description    = $google_summary; // Editable in preview; AI runs at confirm if enabled

        // Map category
        $cat_taxonomy = $post_type . 'category';
        $cat_id       = $this->map_category( $details['types'] ?? [], $cat_taxonomy );
        $cat_name     = '';
        if ( $cat_id ) {
            $term     = get_term( $cat_id, $cat_taxonomy );
            $cat_name = $term && ! is_wp_error( $term ) ? $term->name : '';
        }

        $address_parsed = $this->places_api->parse_address( $details );

        return [
            'place_id'           => $place_id,
            'name'               => $name,
            'address'            => $address,
            'address_parsed'     => $address_parsed,
            'phone'              => $details['formatted_phone_number'] ?? '',
            'website'            => $details['website'] ?? '',
            'rating'             => $details['rating'] ?? '',
            'category'           => $cat_name,
            'description'        => (string) $description,
            'description_source' => empty( $description ) ? 'none' : 'google',
            'hours'              => $details['opening_hours']['weekday_text'] ?? [],
            'lat'                => $details['geometry']['location']['lat'] ?? '',
            'lng'                => $details['geometry']['location']['lng'] ?? '',
            'types'              => $details['types'] ?? [],
            'photos'             => $details['photos'] ?? [],
            'is_duplicate'       => ! empty( $duplicate_reason ),
            'duplicate_reason'   => $duplicate_reason,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // CONFIRMED IMPORT — save selected businesses by place_id
    // ─────────────────────────────────────────────────────────────

    /**
     * Import listings by re-fetching from Google using place_ids.
     * $selections = [ place_id => [ 'description' => '...', 'description_source' => '...' ] ]
     */
    public function import_by_place_ids( $place_ids, $selections, $post_type = 'gd_place' ) {
        $this->log = [];

        if ( empty( $place_ids ) ) {
            $this->log_entry( 'error', 'No listings selected.' );
            return $this->log;
        }

        $this->log_entry( 'info', 'Importing ' . count( $place_ids ) . ' listings into ' . $post_type );

        foreach ( $place_ids as $place_id ) {
            $override = $selections[ $place_id ] ?? [];
            $this->import_by_place_id( $place_id, $override, $post_type );
        }

        $this->log_entry( 'info', '✅ Import complete.' );
        return $this->log;
    }

    /**
     * Fetch full details from Google and save a single listing.
     */
    private function import_by_place_id( $place_id, $override = [], $post_type = 'gd_place' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwaws_import_log';

        // Final duplicate check
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE place_id = %s", $place_id ) );
        if ( $existing ) {
            $this->log_entry( 'skip', "Place ID {$place_id} — already in import log, skipping." );
            return;
        }

        // Re-fetch full details from Google
        $details = $this->places_api->get_place_details( $place_id );
        if ( is_wp_error( $details ) ) {
            $this->log_entry( 'error', "Place ID {$place_id} — Details error: " . $details->get_error_message() );
            return;
        }

        if ( ( $details['business_status'] ?? '' ) === 'CLOSED_PERMANENTLY' ) {
            $this->log_entry( 'skip', ( $details['name'] ?? $place_id ) . ' — permanently closed, skipping.' );
            return;
        }

        $name = $details['name'] ?? 'Unknown';

        // Determine description
        $user_desc   = $override['description'] ?? '';
        $desc_source = $override['description_source'] ?? 'google';
        $google_sum  = $details['editorial_summary']['overview'] ?? '';
        $use_ai      = GDWAWS_Settings::get( 'use_claude', '1' ) === '1' && ! empty( GDWAWS_Settings::get( 'anthropic_api_key' ) );

        if ( $desc_source === 'edited' && ! empty( $user_desc ) ) {
            // User manually edited — use their text
            $description = $user_desc;
        } elseif ( $use_ai ) {
            // Generate AI description
            $ai_desc = $this->claude->generate_description( $details );
            $description = is_wp_error( $ai_desc ) ? $google_sum : (string) $ai_desc;
            if ( ! is_wp_error( $ai_desc ) ) {
                $this->log_entry( 'info', "{$name} — AI description generated." );
            }
        } else {
            $description = $google_sum;
        }

        // Parse address and map category
        $address      = $this->places_api->parse_address( $details );
        $cat_taxonomy = $post_type . 'category';
        $cat_id       = $this->map_category( $details['types'] ?? [], $cat_taxonomy );

        $post_data = [
            'post_title'   => sanitize_text_field( $name ),
            'post_content' => wp_kses_post( $description ),
            'post_status'  => GDWAWS_Settings::get( 'post_status', 'draft' ),
            'post_type'    => $post_type,
        ];

        if ( $cat_id ) {
            $post_data['tax_input'] = [ $cat_taxonomy => [ $cat_id ] ];
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            $this->log_entry( 'error', "{$name} — Post insert error: " . $post_id->get_error_message() );
            $this->db_log( $place_id, $name, null, 'error', $post_id->get_error_message() );
            return;
        }

        // Save GeoDirectory meta
        $lat = $details['geometry']['location']['lat'] ?? '';
        $lng = $details['geometry']['location']['lng'] ?? '';

        $meta = [
            'geodir_location'  => $details['formatted_address'] ?? '',
            'geodir_address'   => $address['street'] ?? '',
            'geodir_city'      => $address['city'] ?? '',
            'geodir_region'    => $address['state'] ?? '',
            'geodir_zip'       => $address['zip'] ?? '',
            'geodir_country'   => 'US',
            'geodir_latitude'  => $lat,
            'geodir_longitude' => $lng,
            'geodir_phone'     => sanitize_text_field( $details['formatted_phone_number'] ?? '' ),
            'geodir_website'   => esc_url_raw( $details['website'] ?? '' ),
            'geodir_timing'    => $this->format_hours( $details['opening_hours']['weekday_text'] ?? [] ),
            'gdwaws_place_id'  => $place_id,
            'gdwaws_rating'    => sanitize_text_field( (string) ( $details['rating'] ?? '' ) ),
        ];

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }

        // Featured image
        $photos = $details['photos'] ?? [];
        if ( ! empty( $photos ) ) {
            $attachment_id = $this->places_api->fetch_featured_image( $photos, $post_id, $name );
            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
                $this->log_entry( 'info', "{$name} — Featured image set." );
            }
        }

        $this->db_log( $place_id, $name, $post_id, 'imported', 'Imported successfully.' );
        $this->log_entry( 'success', "{$name} — Saved (Post ID: {$post_id})" );
    }

    /**
     * Import a list of confirmed preview items (legacy, kept for compat).
     */
    public function import_confirmed( $items, $post_type = 'gd_place' ) {
        $this->log = [];
        $this->log_entry( 'info', 'Importing ' . count( $items ) . ' listings into ' . $post_type );
        foreach ( $items as $item ) {
            $this->save_single( $item, $post_type );
        }
        $this->log_entry( 'info', '✅ Import complete.' );
        return $this->log;
    }

    /**
     * Save a single confirmed item to GeoDirectory.
     */
    private function save_single( $item, $post_type ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'gdwaws_import_log';
        $place_id = sanitize_text_field( $item['place_id'] ?? '' );
        $name     = sanitize_text_field( $item['name'] ?? 'Unknown' );

        if ( empty( $place_id ) ) {
            $this->log_entry( 'error', 'Missing place_id, skipping.' );
            return;
        }

        // Final duplicate check before saving
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE place_id = %s", $place_id ) );
        if ( $existing ) {
            $this->log_entry( 'skip', "{$name} — already in import log, skipping." );
            return;
        }

        $description  = wp_kses_post( $item['description'] ?? '' );

        // If description is blank or still just a Google placeholder and AI is enabled, generate now
        $use_ai = GDWAWS_Settings::get( 'use_claude', '1' ) === '1' && ! empty( GDWAWS_Settings::get( 'anthropic_api_key' ) );
        $desc_source = $item['description_source'] ?? 'edited';

        if ( $use_ai && ( empty( $description ) || $desc_source === 'google' || $desc_source === 'none' ) ) {
            // Rebuild a minimal details array for Claude from the item data
            $details_for_claude = [
                'name'                   => $name,
                'formatted_address'      => $item['address'] ?? '',
                'formatted_phone_number' => $item['phone'] ?? '',
                'website'                => $item['website'] ?? '',
                'rating'                 => $item['rating'] ?? '',
                'types'                  => $item['types'] ?? [],
                'opening_hours'          => [ 'weekday_text' => $item['hours'] ?? [] ],
                'editorial_summary'      => [ 'overview' => $description ],
            ];
            $ai_desc = $this->claude->generate_description( $details_for_claude );
            if ( ! is_wp_error( $ai_desc ) && ! empty( $ai_desc ) ) {
                $description = wp_kses_post( $ai_desc );
                $this->log_entry( 'info', "{$name} — AI description generated." );
            }
        }
        $address      = $item['address_parsed'] ?? [];
        $cat_taxonomy = $post_type . 'category';
        $types        = $item['types'] ?? [];
        $cat_id       = $this->map_category( $types, $cat_taxonomy );

        $post_data = [
            'post_title'   => $name,
            'post_content' => $description,
            'post_status'  => GDWAWS_Settings::get( 'post_status', 'draft' ),
            'post_type'    => $post_type,
        ];

        if ( $cat_id ) {
            $post_data['tax_input'] = [ $cat_taxonomy => [ $cat_id ] ];
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            $this->log_entry( 'error', "{$name} — Post insert error: " . $post_id->get_error_message() );
            $this->db_log( $place_id, $name, null, 'error', $post_id->get_error_message() );
            return;
        }

        // Save GeoDirectory meta
        $meta = [
            'geodir_location'  => $item['address'] ?? '',
            'geodir_address'   => $address['street'] ?? '',
            'geodir_city'      => $address['city'] ?? '',
            'geodir_region'    => $address['state'] ?? '',
            'geodir_zip'       => $address['zip'] ?? '',
            'geodir_country'   => 'US',
            'geodir_latitude'  => $item['lat'] ?? '',
            'geodir_longitude' => $item['lng'] ?? '',
            'geodir_phone'     => sanitize_text_field( $item['phone'] ?? '' ),
            'geodir_website'   => esc_url_raw( $item['website'] ?? '' ),
            'geodir_timing'    => $this->format_hours( $item['hours'] ?? [] ),
            'gdwaws_place_id'  => $place_id,
            'gdwaws_rating'    => sanitize_text_field( $item['rating'] ?? '' ),
        ];

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }

        // Featured image
        $photos = $item['photos'] ?? [];
        if ( ! empty( $photos ) ) {
            $attachment_id = $this->places_api->fetch_featured_image( $photos, $post_id, $name );
            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $post_id, $attachment_id );
                $this->log_entry( 'info', "{$name} — Featured image set." );
            }
        }

        $this->db_log( $place_id, $name, $post_id, 'imported', 'Imported via preview.' );
        $this->log_entry( 'success', "{$name} — Saved (Post ID: {$post_id})" );
    }

    // ─────────────────────────────────────────────────────────────
    // BULK PUBLISH
    // ─────────────────────────────────────────────────────────────

    /**
     * Publish all draft posts of the given post type that were imported by this plugin.
     */
    public static function bulk_publish( $post_type = 'gd_place' ) {
        global $wpdb;
        $log_table = $wpdb->prefix . 'gdwaws_import_log';

        // Get post IDs from our log that are still drafts
        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT l.post_id FROM $log_table l
             INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id
             WHERE l.status = 'imported'
             AND p.post_type = %s
             AND p.post_status = 'draft'
             AND l.post_id IS NOT NULL",
            $post_type
        ) );

        $published = 0;
        foreach ( $post_ids as $post_id ) {
            $result = wp_update_post( [
                'ID'          => intval( $post_id ),
                'post_status' => 'publish',
            ] );
            if ( $result && ! is_wp_error( $result ) ) $published++;
        }

        return [ 'published' => $published, 'total' => count( $post_ids ) ];
    }

    // ─────────────────────────────────────────────────────────────
    // LEGACY run_multi (kept for backwards compat)
    // ─────────────────────────────────────────────────────────────

    public function run_multi( $region, $categories, $radius = 8000, $city_filter = '', $post_type = 'gd_place' ) {
        $this->log = [];
        foreach ( $categories as $category ) {
            $this->log_entry( 'info', "─── Category: {$category} ───" );
            $this->run( $region, $category, $radius, $city_filter, $post_type );
        }
        $this->log_entry( 'info', '✅ All categories complete.' );
        return $this->log;
    }

    public function run( $region, $type = 'establishment', $radius = 8000, $city_filter = '', $post_type = '' ) {
        if ( empty( $post_type ) ) {
            $post_type = GDWAWS_Settings::get( 'geodir_post_type', 'gd_place' );
        }

        $city_name = '';
        if ( $city_filter ) {
            $parts     = explode( ',', $region );
            $city_name = trim( $parts[0] );
        }

        $businesses = $this->places_api->nearby_search( $region, $type, $radius );
        if ( is_wp_error( $businesses ) ) {
            $this->log_entry( 'error', 'Google Places error: ' . $businesses->get_error_message() );
            return $this->log;
        }

        $this->log_entry( 'info', count( $businesses ) . ' businesses found.' );

        if ( $city_name ) {
            $before = count( $businesses );
            $businesses = array_filter( $businesses, function( $biz ) use ( $city_name ) {
                return $this->address_matches_city( $biz, $city_name );
            });
            $businesses = array_values( $businesses );
            $skipped    = $before - count( $businesses );
            if ( $skipped > 0 ) {
                $this->log_entry( 'info', "{$skipped} businesses filtered out." );
            }
        }

        foreach ( $businesses as $biz ) {
            $place_id = $biz['place_id'] ?? $biz['id'] ?? '';
            $preview  = $this->build_preview( $place_id, $post_type );
            if ( $preview && ! $preview['is_duplicate'] ) {
                $this->save_single( $preview, $post_type );
            }
        }

        $this->log_entry( 'info', 'Import complete.' );
        return $this->log;
    }

    // ─────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────

    private function map_category( $types, $taxonomy = 'gd_placecategory' ) {
        $map = [
            'restaurant'                => 'Restaurants',
            'cafe'                      => 'Cafes / Coffee',
            'bar'                       => 'Bars',
            'bakery'                    => 'Bakeries',
            'meal_takeaway'             => 'Takeaway / Fast Food',
            'meal_delivery'             => 'Food Delivery',
            'food'                      => 'Restaurants',
            'night_club'                => 'Night Clubs',
            'liquor_store'              => 'Liquor Stores',
            'store'                     => 'Shopping',
            'grocery_or_supermarket'    => 'Grocery',
            'convenience_store'         => 'Convenience Stores',
            'clothing_store'            => 'Clothing Stores',
            'shoe_store'                => 'Shoe Stores',
            'furniture_store'           => 'Furniture Stores',
            'home_goods_store'          => 'Home Goods',
            'hardware_store'            => 'Hardware Stores',
            'electronics_store'         => 'Electronics',
            'book_store'                => 'Book Stores',
            'florist'                   => 'Florists',
            'jewelry_store'             => 'Jewelry Stores',
            'pet_store'                 => 'Pet Stores',
            'bicycle_store'             => 'Bicycle Shops',
            'department_store'          => 'Shopping',
            'shopping_mall'             => 'Shopping',
            'pharmacy'                  => 'Pharmacies',
            'drugstore'                 => 'Pharmacies',
            'hospital'                  => 'Health & Medical',
            'doctor'                    => 'Health & Medical',
            'dentist'                   => 'Dentists',
            'health'                    => 'Health & Medical',
            'physiotherapist'           => 'Health & Medical',
            'veterinary_care'           => 'Veterinary',
            'gym'                       => 'Health & Fitness',
            'spa'                       => 'Beauty & Spas',
            'hair_care'                 => 'Beauty & Spas',
            'beauty_salon'              => 'Beauty & Spas',
            'nail_salon'                => 'Beauty & Spas',
            'car_dealer'                => 'Auto Services',
            'car_repair'                => 'Auto Services',
            'car_wash'                  => 'Auto Services',
            'gas_station'               => 'Gas Stations',
            'parking'                   => 'Parking',
            'electrician'               => 'Home Services',
            'plumber'                   => 'Home Services',
            'painter'                   => 'Home Services',
            'general_contractor'        => 'Home Services',
            'roofing_contractor'        => 'Home Services',
            'moving_company'            => 'Home Services',
            'storage'                   => 'Storage',
            'locksmith'                 => 'Home Services',
            'lawyer'                    => 'Legal Services',
            'accounting'                => 'Financial Services',
            'insurance_agency'          => 'Insurance',
            'real_estate_agency'        => 'Real Estate',
            'travel_agency'             => 'Travel',
            'employment_agency'         => 'Employment',
            'bank'                      => 'Financial Services',
            'atm'                       => 'Financial Services',
            'finance'                   => 'Financial Services',
            'school'                    => 'Education',
            'university'                => 'Education',
            'library'                   => 'Libraries',
            'primary_school'            => 'Education',
            'secondary_school'          => 'Education',
            'church'                    => 'Churches',
            'mosque'                    => 'Places of Worship',
            'synagogue'                 => 'Places of Worship',
            'hindu_temple'              => 'Places of Worship',
            'cemetery'                  => 'Cemeteries',
            'community_center'          => 'Community Centers',
            'city_hall'                 => 'Government',
            'local_government_office'   => 'Government',
            'courthouse'                => 'Government',
            'post_office'               => 'Government',
            'fire_station'              => 'Emergency Services',
            'police'                    => 'Emergency Services',
            'embassy'                   => 'Government',
            'lodging'                   => 'Hotels & Lodging',
            'campground'                => 'Campgrounds & RV Parks',
            'rv_park'                   => 'Campgrounds & RV Parks',
            'museum'                    => 'Museums',
            'art_gallery'               => 'Arts & Culture',
            'tourist_attraction'        => 'Tourist Attractions',
            'historical_landmark'       => 'Historical Landmarks',
            'movie_theater'             => 'Entertainment',
            'performing_arts_theater'   => 'Entertainment',
            'amusement_park'            => 'Entertainment',
            'bowling_alley'             => 'Entertainment',
            'casino'                    => 'Entertainment',
            'stadium'                   => 'Sports & Recreation',
            'zoo'                       => 'Tourist Attractions',
            'park'                      => 'Parks & Recreation',
            'natural_feature'           => 'Parks & Recreation',
            'golf_course'               => 'Sports & Recreation',
            'airport'                   => 'Transportation',
            'bus_station'               => 'Transportation',
            'train_station'             => 'Transportation',
            'transit_station'           => 'Transportation',
            'taxi_stand'                => 'Transportation',
            'funeral_home'              => 'Funeral Homes',
        ];

        foreach ( $types as $type ) {
            if ( isset( $map[ $type ] ) ) {
                $cat_name = $map[ $type ];
                $term     = get_term_by( 'name', $cat_name, $taxonomy );
                if ( $term ) return $term->term_id;
                $new_term = wp_insert_term( $cat_name, $taxonomy );
                if ( ! is_wp_error( $new_term ) ) return $new_term['term_id'];
            }
        }
        return null;
    }

    private function address_matches_city( $biz, $city_name ) {
        if ( empty( $city_name ) ) return true;

        $city_lower = strtolower( trim( $city_name ) );

        // Prefer the structured city field from addressComponents (exact match)
        if ( ! empty( $biz['city'] ) ) {
            return strtolower( trim( $biz['city'] ) ) === $city_lower;
        }

        // Fallback: parse city from formatted address
        // Format: "123 Main St, Goliad, TX 77963, USA"
        $address = $biz['formatted_address'] ?? $biz['formattedAddress'] ?? '';
        if ( empty( $address ) ) return true;

        $parts = explode( ',', $address );
        // City is typically the second comma-separated part
        if ( isset( $parts[1] ) ) {
            return strtolower( trim( $parts[1] ) ) === $city_lower;
        }

        return false;
    }

    private function format_hours( $hours_array ) {
        return implode( "\n", $hours_array );
    }

    private function log_entry( $type, $message ) {
        $this->log[] = [ 'type' => $type, 'message' => $message, 'time' => current_time( 'H:i:s' ) ];
    }

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

    public static function get_history( $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwaws_import_log';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY imported_at DESC LIMIT %d", $limit ) );
    }

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
