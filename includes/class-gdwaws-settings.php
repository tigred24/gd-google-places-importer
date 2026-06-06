<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GDWAWS_Settings {

    public static function get( $key, $default = '' ) {
        $options = get_option( 'gdwaws_settings', [] );
        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }

    public static function set( $key, $value ) {
        $options = get_option( 'gdwaws_settings', [] );
        $options[ $key ] = $value;
        update_option( 'gdwaws_settings', $options );
    }

    public static function save( $data ) {
        $allowed = [
            'google_api_key',
            'anthropic_api_key',
            'anthropic_model',
            'use_claude',
            'default_region',
            'default_category',
            'search_radius',
            'import_limit',
            'post_status',
            'geodir_post_type',
        ];
        $options = get_option( 'gdwaws_settings', [] );
        foreach ( $allowed as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $options[ $key ] = sanitize_text_field( $data[ $key ] );
            }
        }
        update_option( 'gdwaws_settings', $options );
    }

    public static function google_place_types() {
        return [
            // Food & Drink
            'restaurant'                => 'Restaurants',
            'cafe'                      => 'Cafes / Coffee',
            'bar'                       => 'Bars',
            'bakery'                    => 'Bakeries',
            'meal_takeaway'             => 'Takeaway / Fast Food',
            'night_club'                => 'Night Clubs',
            'liquor_store'              => 'Liquor Stores',

            // Shopping & Retail
            'grocery_or_supermarket'    => 'Grocery Stores',
            'convenience_store'         => 'Convenience Stores',
            'hardware_store'            => 'Hardware Stores',
            'pharmacy'                  => 'Pharmacies',
            'florist'                   => 'Florists',
            'pet_store'                 => 'Pet Stores',
            'store'                     => 'Retail / Stores (General)',

            // Health & Medical
            'hospital'                  => 'Hospitals',
            'doctor'                    => 'Doctors',
            'dentist'                   => 'Dentists',
            'veterinary_care'           => 'Veterinary / Animal Care',

            // Beauty & Fitness
            'hair_care'                 => 'Hair Salons / Barbers',
            'beauty_salon'              => 'Beauty Salons',
            'gym'                       => 'Gyms / Fitness',

            // Automotive
            'gas_station'               => 'Gas Stations',
            'car_repair'                => 'Auto Repair',
            'car_dealer'                => 'Car Dealers',

            // Home Services & Trades
            'electrician'               => 'Electricians',
            'plumber'                   => 'Plumbers',
            'general_contractor'        => 'Contractors',
            'storage'                   => 'Storage Facilities',

            // Professional Services
            'lawyer'                    => 'Lawyers',
            'accounting'                => 'Accountants / CPA',
            'insurance_agency'          => 'Insurance Agencies',
            'real_estate_agency'        => 'Real Estate',
            'bank'                      => 'Banks',

            // Education & Community
            'school'                    => 'Schools',
            'library'                   => 'Libraries',
            'church'                    => 'Churches',
            'community_center'          => 'Community Centers',
            'cemetery'                  => 'Cemeteries',

            // Government & Civic
            'local_government_office'   => 'Government Offices',
            'post_office'               => 'Post Offices',
            'fire_station'              => 'Fire Stations',
            'police'                    => 'Police Stations',

            // Lodging & Travel
            'lodging'                   => 'Hotels / Lodging',
            'campground'                => 'Campgrounds / RV Parks',

            // Arts, Culture & Recreation
            'museum'                    => 'Museums',
            'tourist_attraction'        => 'Tourist Attractions',
            'historical_landmark'       => 'Historical Landmarks',
            'park'                      => 'Parks',
            'golf_course'               => 'Golf Courses',

            // Funeral
            'funeral_home'              => 'Funeral Homes',
        ];
    }
}
