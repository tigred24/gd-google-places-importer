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
            'meal_delivery'             => 'Food Delivery',
            'food'                      => 'Food (General)',
            'night_club'                => 'Night Clubs',
            'liquor_store'              => 'Liquor Stores',

            // Shopping & Retail
            'store'                     => 'Retail / Stores',
            'grocery_or_supermarket'    => 'Grocery Stores',
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
            'department_store'          => 'Department Stores',
            'shopping_mall'             => 'Shopping Malls',
            'pharmacy'                  => 'Pharmacies',
            'drugstore'                 => 'Drug Stores',

            // Health & Medical
            'hospital'                  => 'Hospitals',
            'doctor'                    => 'Doctors',
            'dentist'                   => 'Dentists',
            'health'                    => 'Health (General)',
            'physiotherapist'           => 'Physiotherapists',
            'veterinary_care'           => 'Veterinary / Animal Care',
            'gym'                       => 'Gyms / Fitness',
            'spa'                       => 'Spas',

            // Beauty & Personal Care
            'hair_care'                 => 'Hair Salons / Barbers',
            'beauty_salon'              => 'Beauty Salons',
            'nail_salon'                => 'Nail Salons',

            // Automotive
            'car_dealer'                => 'Car Dealers',
            'car_repair'                => 'Auto Repair',
            'car_wash'                  => 'Car Washes',
            'gas_station'               => 'Gas Stations',
            'parking'                   => 'Parking',

            // Home Services & Trades
            'electrician'               => 'Electricians',
            'plumber'                   => 'Plumbers',
            'painter'                   => 'Painters',
            'general_contractor'        => 'General Contractors',
            'roofing_contractor'        => 'Roofing Contractors',
            'moving_company'            => 'Moving Companies',
            'storage'                   => 'Storage Facilities',
            'locksmith'                 => 'Locksmiths',

            // Professional Services
            'lawyer'                    => 'Lawyers',
            'accounting'                => 'Accountants / CPA',
            'insurance_agency'          => 'Insurance Agencies',
            'real_estate_agency'        => 'Real Estate',
            'travel_agency'             => 'Travel Agencies',
            'employment_agency'         => 'Employment Agencies',

            // Financial
            'bank'                      => 'Banks',
            'atm'                       => 'ATMs',
            'finance'                   => 'Financial Services',

            // Education
            'school'                    => 'Schools',
            'university'                => 'Colleges / Universities',
            'library'                   => 'Libraries',
            'primary_school'            => 'Primary Schools',
            'secondary_school'          => 'Secondary Schools',

            // Religion & Community
            'church'                    => 'Churches',
            'mosque'                    => 'Mosques',
            'synagogue'                 => 'Synagogues',
            'hindu_temple'              => 'Hindu Temples',
            'cemetery'                  => 'Cemeteries',
            'community_center'          => 'Community Centers',

            // Government & Civic
            'city_hall'                 => 'City Hall',
            'local_government_office'   => 'Government Offices',
            'courthouse'                => 'Courthouses',
            'post_office'               => 'Post Offices',
            'fire_station'              => 'Fire Stations',
            'police'                    => 'Police Stations',
            'embassy'                   => 'Embassies',

            // Lodging & Travel
            'lodging'                   => 'Hotels / Lodging',
            'campground'                => 'Campgrounds / RV Parks',

            // Arts, Culture & Entertainment
            'museum'                    => 'Museums',
            'art_gallery'               => 'Art Galleries',
            'tourist_attraction'        => 'Tourist Attractions',
            'historical_landmark'       => 'Historical Landmarks',
            'movie_theater'             => 'Movie Theaters',
            'performing_arts_theater'   => 'Performing Arts / Theaters',
            'amusement_park'            => 'Amusement Parks',
            'bowling_alley'             => 'Bowling Alleys',
            'casino'                    => 'Casinos',
            'stadium'                   => 'Stadiums / Arenas',
            'zoo'                       => 'Zoos / Aquariums',

            // Outdoors & Recreation
            'park'                      => 'Parks',
            'campground'                => 'Campgrounds',
            'rv_park'                   => 'RV Parks',
            'natural_feature'           => 'Natural Features',
            'golf_course'               => 'Golf Courses',
            'stadium'                   => 'Sports Venues',

            // Transportation
            'airport'                   => 'Airports',
            'bus_station'               => 'Bus Stations',
            'train_station'             => 'Train Stations',
            'transit_station'           => 'Transit Stations',
            'taxi_stand'                => 'Taxi Stands',

            // Funeral & End of Life
            'funeral_home'              => 'Funeral Homes',

            // Catch-all
            'establishment'             => 'All Businesses (General)',
        ];
    }
}
