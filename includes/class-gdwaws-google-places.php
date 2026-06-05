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
        ];
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
