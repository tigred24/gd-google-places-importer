<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GDWAWS_Google_Places {

    private $api_key;
    private $base = 'https://places.googleapis.com/v1';

    public function __construct() {
        $this->api_key = GDWAWS_Settings::get( 'google_api_key' );
    }

    /**
     * Common headers for New Places API requests.
     */
    private function headers( $field_mask ) {
        return [
            'Content-Type'     => 'application/json',
            'X-Goog-Api-Key'   => $this->api_key,
            'X-Goog-FieldMask' => $field_mask,
        ];
    }

    /**
     * Search for businesses near a location by type using New Places API.
     */
    public function nearby_search( $location, $type = 'establishment', $radius = 8000 ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', 'Google API key not set.' );
        }

        $coords = $this->geocode( $location );
        if ( is_wp_error( $coords ) ) return $coords;

        $limit     = intval( GDWAWS_Settings::get( 'import_limit', 20 ) );
        $results   = [];
        $page_token = null;
        $pages     = 0;

        do {
            $body = [
                'locationRestriction' => [
                    'circle' => [
                        'center' => [ 'latitude' => $coords['lat'], 'longitude' => $coords['lng'] ],
                        'radius' => (float) $radius,
                    ],
                ],
                'maxResultCount' => min( 20, $limit - count( $results ) ),
            ];

            // Map old-style type to new includedTypes
            if ( $type && $type !== 'establishment' ) {
                $body['includedTypes'] = [ $type ];
            }

            if ( $page_token ) {
                $body['pageToken'] = $page_token;
            }

            $field_mask = 'places.id,places.displayName,places.formattedAddress,places.location,places.types,places.businessStatus,places.rating,places.userRatingCount,places.regularOpeningHours,places.nationalPhoneNumber,places.websiteUri,places.editorialSummary,places.addressComponents';

            $response = wp_remote_post( $this->base . '/places:searchNearby', [
                'timeout' => 15,
                'headers' => $this->headers( $field_mask ),
                'body'    => json_encode( $body ),
            ]);

            if ( is_wp_error( $response ) ) return $response;

            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( isset( $data['error'] ) ) {
                return new WP_Error( 'google_error', $data['error']['message'] );
            }

            if ( ! empty( $data['places'] ) ) {
                $results = array_merge( $results, $data['places'] );
            }

            $page_token = $data['nextPageToken'] ?? null;
            $pages++;

        } while ( $page_token && count( $results ) < $limit && $pages < 3 );

        return array_slice( $results, 0, $limit );
    }

    /**
     * Get full details for a single place using New Places API.
     */
    public function get_place_details( $place_id ) {
        $field_mask = 'id,displayName,formattedAddress,location,types,businessStatus,rating,userRatingCount,regularOpeningHours,nationalPhoneNumber,websiteUri,editorialSummary,addressComponents,photos';

        $response = wp_remote_get( $this->base . '/places/' . $place_id, [
            'timeout' => 15,
            'headers' => $this->headers( $field_mask ),
        ]);

        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['error'] ) ) {
            return new WP_Error( 'google_error', $data['error']['message'] );
        }

        // Normalize to a consistent internal format
        return $this->normalize( $data );
    }

    /**
     * Normalize New Places API response to our internal format.
     * This keeps the importer and Claude class working without changes.
     */
    public function normalize( $place ) {
        $name    = $place['displayName']['text'] ?? '';
        $address = $place['formattedAddress'] ?? '';
        $summary = $place['editorialSummary']['text'] ?? '';

        // Build weekday text from regularOpeningHours
        $weekday_text = [];
        if ( ! empty( $place['regularOpeningHours']['weekdayDescriptions'] ) ) {
            $weekday_text = $place['regularOpeningHours']['weekdayDescriptions'];
        }

        return [
            'place_id'              => $place['id'] ?? '',
            'name'                  => $name,
            'formatted_address'     => $address,
            'formatted_phone_number'=> $place['nationalPhoneNumber'] ?? '',
            'website'               => $place['websiteUri'] ?? '',
            'rating'                => $place['rating'] ?? null,
            'user_ratings_total'    => $place['userRatingCount'] ?? 0,
            'types'                 => $place['types'] ?? [],
            'business_status'       => $place['businessStatus'] ?? 'OPERATIONAL',
            'geometry'              => [
                'location' => [
                    'lat' => $place['location']['latitude'] ?? 0,
                    'lng' => $place['location']['longitude'] ?? 0,
                ],
            ],
            'opening_hours'         => [
                'weekday_text' => $weekday_text,
            ],
            'editorial_summary'     => [
                'overview' => $summary,
            ],
            'photos'                => $place['photos'] ?? [],
        ];
    }

    /**
     * Fetch a photo URL from the New Places API and sideload it into WordPress media library.
     * Returns attachment ID or WP_Error.
     */
    public function fetch_featured_image( $photos, $post_id, $business_name ) {
        if ( empty( $photos ) ) {
            return new WP_Error( 'no_photos', 'No photos available.' );
        }

        // Get the first photo reference name
        $photo_name = $photos[0]['name'] ?? '';
        if ( empty( $photo_name ) ) {
            return new WP_Error( 'no_photo_name', 'No photo reference found.' );
        }

        // Build the photo media URL
        $photo_url = $this->base . '/' . $photo_name . '/media?' . http_build_query([
            'maxHeightPx' => 800,
            'maxWidthPx'  => 1200,
            'key'         => $this->api_key,
            'skipHttpRedirect' => 'true',
        ]);

        $response = wp_remote_get( $photo_url, [ 'timeout' => 20 ] );
        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $image_uri = $body['photoUri'] ?? '';

        if ( empty( $image_uri ) ) {
            return new WP_Error( 'no_photo_uri', 'Could not get photo URI from Google.' );
        }

        // Get attribution if available
        $attribution = '';
        if ( ! empty( $photos[0]['authorAttributions'] ) ) {
            $author = $photos[0]['authorAttributions'][0]['displayName'] ?? '';
            if ( $author ) {
                $attribution = 'Photo via Google Maps' . ( $author ? ' / ' . $author : '' );
            }
        }
        if ( empty( $attribution ) ) {
            $attribution = 'Photo via Google Maps';
        }

        // Sideload image into WordPress media library
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $filename  = sanitize_title( $business_name ) . '-' . time() . '.jpg';
        $attachment_id = media_sideload_image( $image_uri, $post_id, $attribution, 'id' );

        if ( is_wp_error( $attachment_id ) ) return $attachment_id;

        // Add attribution as caption
        wp_update_post([
            'ID'           => $attachment_id,
            'post_excerpt' => $attribution,
            'post_title'   => sanitize_text_field( $business_name ),
        ]);

        return $attachment_id;
    }

    /**
     * Geocode a location string using Geocoding API (unchanged).
     */
    public function geocode( $location ) {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $location,
            'key'     => $this->api_key,
        ]);

        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['results'] ) ) {
            return new WP_Error( 'geocode_failed', 'Could not geocode location: ' . $location );
        }

        $loc = $body['results'][0]['geometry']['location'];
        return [ 'lat' => $loc['lat'], 'lng' => $loc['lng'] ];
    }

    /**
     * Parse address from formatted_address string.
     */
    public function parse_address( $place ) {
        $address = $place['formatted_address'] ?? '';
        $parts   = explode( ',', $address );
        $state_zip = isset( $parts[2] ) ? explode( ' ', trim( $parts[2] ) ) : [];

        return [
            'street'  => isset( $parts[0] ) ? trim( $parts[0] ) : '',
            'city'    => isset( $parts[1] ) ? trim( $parts[1] ) : '',
            'state'   => $state_zip[0] ?? '',
            'zip'     => $state_zip[1] ?? '',
            'country' => 'US',
            'full'    => $address,
        ];
    }
}
