<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GDWAWS_Claude {

    private $api_key;
    private $model;

    public function __construct() {
        $this->api_key = GDWAWS_Settings::get( 'anthropic_api_key' );
        $this->model   = GDWAWS_Settings::get( 'anthropic_model', 'claude-sonnet-4-6' );
    }

    /**
     * Generate a business description from place data.
     */
    public function generate_description( $place ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', 'Anthropic API key not set.' );
        }

        $name     = isset( $place['name'] ) ? $place['name'] : 'This business';
        $address  = isset( $place['formatted_address'] ) ? $place['formatted_address'] : '';
        $types    = isset( $place['types'] ) ? implode( ', ', array_slice( $place['types'], 0, 5 ) ) : '';
        $rating   = isset( $place['rating'] ) ? $place['rating'] . '/5 stars (' . ( $place['user_ratings_total'] ?? 0 ) . ' reviews)' : 'No rating data';
        $phone    = isset( $place['formatted_phone_number'] ) ? $place['formatted_phone_number'] : '';
        $website  = isset( $place['website'] ) ? $place['website'] : '';
        $summary  = isset( $place['editorial_summary']['overview'] ) ? $place['editorial_summary']['overview'] : '';

        $hours_text = '';
        if ( ! empty( $place['opening_hours']['weekday_text'] ) ) {
            $hours_text = implode( '; ', $place['opening_hours']['weekday_text'] );
        }

        $prompt = "You are writing a friendly, engaging business listing description for a local business directory in a small Texas community.

Business details:
- Name: {$name}
- Address: {$address}
- Type: {$types}
- Rating: {$rating}
- Phone: {$phone}
- Website: {$website}
- Hours: {$hours_text}
- Google summary: {$summary}

Write a 2-3 paragraph description (150-250 words) that:
1. Introduces the business warmly and what they offer
2. Highlights any notable features or what makes them worth visiting
3. Ends with a friendly call to action (visit, call, check their website)

Do NOT include the address, phone number, or website in the description text (those go in separate fields).
Do NOT use phrases like 'I' or 'we' — write in third person.
Do NOT make up specific details not supported by the data above.
Keep the tone warm, local, and community-focused.";

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => json_encode([
                'model'      => $this->model,
                'max_tokens' => 500,
                'messages'   => [
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
            ]),
        ]);

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'claude_error', $body['error']['message'] );
        }

        $text = '';
        if ( ! empty( $body['content'] ) ) {
            foreach ( $body['content'] as $block ) {
                if ( $block['type'] === 'text' ) {
                    $text .= $block['text'];
                }
            }
        }

        return trim( $text ) ?: new WP_Error( 'empty_response', 'Claude returned an empty description.' );
    }
}
