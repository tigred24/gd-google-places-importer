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
            'restaurant'        => 'Restaurants',
            'store'             => 'Retail / Stores',
            'lodging'           => 'Hotels / Lodging',
            'bar'               => 'Bars',
            'cafe'              => 'Cafes / Coffee',
            'gas_station'       => 'Gas Stations',
            'grocery_or_supermarket' => 'Grocery Stores',
            'gym'               => 'Gyms / Fitness',
            'hair_care'         => 'Hair / Beauty',
            'health'            => 'Health / Medical',
            'church'            => 'Churches',
            'school'            => 'Schools',
            'bank'              => 'Banks',
            'lawyer'            => 'Lawyers',
            'real_estate_agency'=> 'Real Estate',
            'car_repair'        => 'Auto Repair',
            'electrician'       => 'Electricians',
            'plumber'           => 'Plumbers',
            'painter'           => 'Painters',
            'general_contractor'=> 'Contractors',
            'establishment'     => 'All Businesses',
        ];
    }
}
