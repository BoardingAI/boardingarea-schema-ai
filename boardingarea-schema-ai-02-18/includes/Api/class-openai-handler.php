<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI\Api;

use WP_Error;
use BoardingArea\SchemaAI\Admin\Settings;
use BoardingArea\SchemaAI\Builder\Schema_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenAI_Handler {

	private string $api_key;
	private string $model;
	private string $api_url;

	public function __construct() {
		$this->api_key       = Settings::get_effective_api_key();
		$this->model         = (string) get_option( Settings::OPTION_MODEL, 'gpt-4o' );
		$this->api_url       = 'https://api.openai.com/v1/chat/completions';
	}

	/**
	 * Analyze a WP_Post, returning structured classification + extracted details.
	 *
	 * @return array|WP_Error
	 */
	public function analyze_post( \WP_Post $post, string $forced_template_id = 'Auto', string $forced_reviewed_type = '' ): array|WP_Error {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'basai_missing_key', 'OpenAI API Key is missing.' );
		}

		$title_raw   = (string) $post->post_title;
		$content_raw = (string) $post->post_content;

		$title = html_entity_decode( $title_raw, ENT_QUOTES );

		$clean_text = $this->clean_content_text( $content_raw );
		if ( strlen( $clean_text ) > 30000 ) {
			$clean_text = substr( $clean_text, 0, 30000 );
		}

		$clean_html = $this->clean_content_html( $content_raw );
		if ( strlen( $clean_html ) > 30000 ) {
			$clean_html = substr( $clean_html, 0, 30000 );
		}

		$excerpt = has_excerpt( $post )
			? (string) get_the_excerpt( $post )
			: (string) wp_trim_words( (string) $post->post_content, 60 );
		$excerpt = html_entity_decode( $excerpt, ENT_QUOTES );

		$list_hints = $this->extract_list_hints_from_html( $content_raw, 25 );

		$system_prompt = $this->get_system_prompt( $forced_template_id, $forced_reviewed_type );
		$user_message  = "TITLE: {$title}\n\nEXCERPT: {$excerpt}\n\nCONTENT_TEXT:\n{$clean_text}\n\nCONTENT_HTML (SAFE, TRIMMED):\n{$clean_html}";

		if ( ! empty( $list_hints ) ) {
			$user_message .= "\n\nLIST_HINTS (EXTRACTED FROM HTML LISTS):\n" . wp_json_encode( $list_hints, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$strict_payload = $this->build_payload( $system_prompt, $user_message, true );
		$response       = $this->request( $strict_payload );

		if ( is_wp_error( $response ) ) {
			$fallback_payload = $this->build_payload( $system_prompt, $user_message, false );
			$response         = $this->request( $fallback_payload );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return $this->apply_post_ai_fixes( $response, $post, $clean_text, $clean_html, $list_hints );
	}

	private function build_payload( string $system_prompt, string $user_message, bool $strict ): array {
		$payload = [
			'model'       => $this->model,
			'messages'    => [
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user', 'content' => $user_message ],
			],
			'temperature' => 0.0,
		];

		if ( $strict ) {
			$payload['response_format'] = [
				'type'        => 'json_schema',
				'json_schema' => [
					'name'   => 'boardingarea_schema_extraction',
					'strict' => true,
					'schema' => $this->json_schema_definition(),
				],
			];
		} else {
			$payload['response_format'] = [ 'type' => 'json_object' ];
		}

		return $payload;
	}

	/**
	 * Strict Schema Definition using oneOf to enforce structure per Type.
	 */
	private function json_schema_definition(): array {
		$common_props = [
			'justification' => [ 'type' => 'string' ],
			'summary'       => [ 'type' => 'string' ],
			'missing_info'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], // New field
		];

		// --- Helper to build strict object schema ---
		$make_schema = function( string $type_name, array $details_props ) use ( $common_props ) {
			$details_required = array_keys( $details_props );

			return [
				'type' => 'object',
				'properties' => [
					'type'          => [ 'type' => 'string', 'const' => $type_name ],
					'justification' => [ 'type' => 'string' ],
					'summary'       => [ 'type' => 'string' ],
					'missing_info'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'details'       => [
						'type' => 'object',
						'properties' => $details_props,
						'required' => $details_required,
						'additionalProperties' => false
					]
				],
				'required' => [ 'type', 'justification', 'summary', 'missing_info', 'details' ],
				'additionalProperties' => false
			];
		};

		// --- Reusable Property Definitions (Strict: nullable for optional) ---
		$str_null  = [ 'type' => [ 'string', 'null' ] ];
		$num_null  = [ 'type' => [ 'number', 'null' ] ];
		$arr_str   = [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ];
		$arr_null  = [ 'type' => [ 'array', 'null' ], 'items' => [ 'type' => 'string' ] ];

		// Common Thing props
		$thing_props = [
			'url' => $str_null,
			'sameAs' => $arr_null,
			'image' => $str_null,
		];

		// Geo Definition (Corrected to match builder: latitude/longitude)
		$geo_def = [
			'type' => ['object', 'null'],
			'properties' => [
				'latitude' => ['type' => 'number'],
				'longitude' => ['type' => 'number']
			],
			'required' => ['latitude', 'longitude'],
			'additionalProperties' => false
		];

		// Opening Hours Specification Definition
		$oh_spec_def = [
			'type' => ['array', 'null'],
			'items' => [
				'type' => 'object',
				'properties' => [
					'dayOfWeek' => $str_null,
					'opens' => $str_null,
					'closes' => $str_null,
					'validFrom' => $str_null,
					'validThrough' => $str_null,
				],
				'required' => ['dayOfWeek', 'opens', 'closes', 'validFrom', 'validThrough'],
				'additionalProperties' => false
			]
		];

		// Place/Business props
		$place_props = array_merge( $thing_props, [
			'name' => $str_null,
			'address' => $str_null,
			'telephone' => $str_null,
			'priceRange' => $str_null,
			'opening_hours' => $str_null, // Simple string
			'opening_hours_spec' => $oh_spec_def, // Structured array
			'geo' => $geo_def,
		] );

		// 1. Review
		$review_schema = $make_schema( 'Review', [
			'reviewed_type' => [ 'type' => 'string', 'enum' => array_keys( Schema_Builder::get_reviewed_types() ) ],
			'rating'        => [ 'type' => 'number' ], // 1.0 - 5.0
			// Specific entity details
			'flight' => [
				'type' => ['object', 'null'],
				'properties' => [
					'airline_name' => $str_null,
					'iata' => $str_null,
					'flight_number' => $str_null,
					'url' => $str_null
				],
				'required' => ['airline_name', 'iata', 'flight_number', 'url'],
				'additionalProperties' => false
			],
			'hotel' => [
				'type' => ['object', 'null'],
				'properties' => array_merge($place_props, ['star_rating' => $num_null, 'rating' => $num_null]),
				'required' => array_keys(array_merge($place_props, ['star_rating' => $num_null, 'rating' => $num_null])),
				'additionalProperties' => false
			],
			'lounge' => [
				'type' => ['object', 'null'],
				'properties' => array_merge($place_props, ['airport_name' => $str_null, 'iata' => $str_null, 'terminal' => $str_null, 'rating' => $num_null]),
				'required' => array_keys(array_merge($place_props, ['airport_name' => $str_null, 'iata' => $str_null, 'terminal' => $str_null, 'rating' => $num_null])),
				'additionalProperties' => false
			],
			'restaurant' => [
				'type' => ['object', 'null'],
				'properties' => array_merge($place_props, ['cuisine' => $str_null, 'menu' => $str_null, 'rating' => $num_null]),
				'required' => array_keys(array_merge($place_props, ['cuisine' => $str_null, 'menu' => $str_null, 'rating' => $num_null])),
				'additionalProperties' => false
			],
			'product' => [
				'type' => ['object', 'null'],
				'properties' => array_merge($thing_props, ['name' => $str_null, 'brand' => $str_null]),
				'required' => array_keys(array_merge($thing_props, ['name' => $str_null, 'brand' => $str_null])),
				'additionalProperties' => false
			],
			'card' => [
				'type' => ['object', 'null'],
				'properties' => [ 'name' => $str_null, 'provider' => $str_null, 'category' => $str_null, 'rating' => $num_null, 'url' => $str_null ],
				'required' => ['name', 'provider', 'category', 'rating', 'url'],
				'additionalProperties' => false
			],
			'software' => [
				'type' => ['object', 'null'],
				'properties' => [ 'name' => $str_null, 'category' => $str_null, 'os' => $str_null, 'version' => $str_null, 'rating' => $num_null, 'url' => $str_null, 'image' => $str_null ],
				'required' => ['name', 'category', 'os', 'version', 'rating', 'url', 'image'],
				'additionalProperties' => false
			],
			// Added 'airline' and 'financial_product' as requested
			'airline' => [
				'type' => ['object', 'null'],
				'properties' => [ 'name' => $str_null, 'iata' => $str_null, 'url' => $str_null, 'rating' => $num_null ],
				'required' => ['name', 'iata', 'url', 'rating'],
				'additionalProperties' => false
			],
			'financial_product' => [
				'type' => ['object', 'null'],
				'properties' => [ 'name' => $str_null, 'provider' => $str_null, 'category' => $str_null, 'rating' => $num_null, 'url' => $str_null ],
				'required' => ['name', 'provider', 'category', 'rating', 'url'],
				'additionalProperties' => false
			],
		] );

		// 2. Trip
		$trip_schema = $make_schema( 'Trip', [
			'trip_name' => [ 'type' => 'string' ],
			'itinerary' => [
				'type' => 'array',
				'items' => [
					'type' => 'object',
					'properties' => [
						'name' => $str_null,
						'location' => $str_null,
						'url' => $str_null,
						'position' => $num_null,
						'address' => $str_null,
						'startDate' => $str_null, // Added
						'endDate' => $str_null,   // Added
					],
					'required' => ['name', 'location', 'url', 'position', 'address', 'startDate', 'endDate'],
					'additionalProperties' => false
				]
			],
			'image' => $str_null,
			'offers' => [ // Optional pricing info
				'type' => ['object', 'null'],
				'properties' => [ 'price' => $str_null, 'priceCurrency' => $str_null, 'url' => $str_null ],
				'required' => ['price', 'priceCurrency', 'url'],
				'additionalProperties' => false
			]
		] );

		// 3. FAQPage
		$faq_schema = $make_schema( 'FAQPage', [
			'faq' => [
				'type' => 'array',
				'items' => [
					'type' => 'object',
					'properties' => [ 'q' => ['type'=>'string'], 'a' => ['type'=>'string'] ],
					'required' => ['q', 'a'],
					'additionalProperties' => false
				]
			]
		] );

		// 4. HowTo
		$howto_schema = $make_schema( 'HowTo', [
			'howto_steps' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'totalTime' => $str_null,
			'image' => $str_null
		] );

		// 5. ItemList
		$itemlist_schema = $make_schema( 'ItemList', [
			'itemlist' => [
				'type' => 'array',
				'items' => [
					'type' => 'object',
					'properties' => [ 'name' => ['type'=>'string'], 'url' => $str_null ],
					'required' => ['name', 'url'],
					'additionalProperties' => false
				]
			]
		] );

		// 6. VideoObject
		$video_schema = $make_schema( 'VideoObject', [
			'video' => [
				'type' => 'object',
				'properties' => [
					'name' => $str_null,
					'description' => $str_null,
					'thumbnail' => $str_null,
					'upload_date' => $str_null,
					'duration' => $str_null, // ISO8601
					'embed_url' => $str_null,
					'content_url' => $str_null
				],
				'required' => ['name', 'description', 'thumbnail', 'upload_date', 'duration', 'embed_url', 'content_url'],
				'additionalProperties' => false
			]
		] );

		// 7. Product (Simple)
		$product_schema = $make_schema( 'Product', [
			'product' => [
				'type' => 'object',
				'properties' => array_merge($thing_props, ['name' => $str_null, 'brand' => $str_null]),
				'required' => array_keys(array_merge($thing_props, ['name' => $str_null, 'brand' => $str_null])),
				'additionalProperties' => false
			]
		] );

		// 8. Place
		$place_schema = $make_schema( 'Place', [
			'place_name' => $str_null,
			'address' => $str_null,
			'url' => $str_null,
			'telephone' => $str_null,
			'geo' => $place_props['geo'],
			'image' => $str_null,
			'sameAs' => $arr_null,
			'opening_hours' => $str_null,
			'opening_hours_spec' => $oh_spec_def,
		] );

		// 9. Airline
		$airline_schema = $make_schema( 'Airline', [
			'airline_name' => $str_null,
			'iata' => $str_null,
			'url' => $str_null,
			'sameAs' => $arr_null
		] );

		// 10. Generic / BlogPosting / Article / NewsArticle (No extra details needed)
		$generic_schema = $make_schema( 'BlogPosting', [] );
		$article_schema = $make_schema( 'Article', [] );
		$news_schema    = $make_schema( 'NewsArticle', [] );

		return [
			'type' => 'object',
			'properties' => [
				'result' => [
					'anyOf' => [
						$review_schema,
						$trip_schema,
						$faq_schema,
						$howto_schema,
						$itemlist_schema,
						$video_schema,
						$product_schema,
						$place_schema,
						$airline_schema,
						$generic_schema,
						$article_schema,
						$news_schema
					]
				]
			],
			'required' => ['result'],
			'additionalProperties' => false
		];
	}

	private function request( array $body ): array|WP_Error {
		$response = wp_remote_post(
			$this->api_url,
			[
				'body'    => wp_json_encode( $body ),
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				],
				'timeout' => 60,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'basai_openai_bad_response', 'OpenAI response was not valid JSON.' );
		}
		if ( $code < 200 || $code >= 300 ) {
			$err_msg = $data['error']['message'] ?? 'Unknown OpenAI error.';
			return new WP_Error( 'basai_openai_http', "OpenAI HTTP {$code}: {$err_msg}" );
		}

		$json_str = $data['choices'][0]['message']['content'] ?? '';
		if ( '' === $json_str ) {
			return new WP_Error( 'basai_openai_empty', 'OpenAI returned empty content.' );
		}

		$result = json_decode( $json_str, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $result ) ) {
			return new WP_Error( 'basai_openai_invalid_json', 'AI output was not valid JSON.' );
		}

		// Unwrap the 'result' wrapper needed for strict schema root.
		if ( array_key_exists( 'result', $result ) ) {
			if ( ! is_array( $result['result'] ) ) {
				return new WP_Error( 'basai_openai_invalid_shape', 'AI output "result" must be an object.' );
			}
			$result = $result['result'];
		}

		foreach ( [ 'type', 'justification', 'summary' ] as $k ) {
			if ( ! array_key_exists( $k, $result ) ) {
				return new WP_Error( 'basai_openai_missing_keys', 'AI output missing required keys.' );
			}
		}

		if ( ! isset( $result['details'] ) || ! is_array( $result['details'] ) ) {
			$result['details'] = [];
		}

		if ( ! isset( $result['missing_info'] ) || ! is_array( $result['missing_info'] ) ) {
			$result['missing_info'] = [];
		}

		return $result;
	}

	private function get_system_prompt( string $forced_type, string $forced_reviewed_type = '' ): string {
		$supported = Schema_Builder::get_supported_types();
		unset( $supported['Auto'] );
		$keys_list = implode( ', ', array_keys( $supported ) );

		$forced_line = '';
		if ( 'Auto' !== $forced_type && '' !== $forced_type ) {
			$forced_line = "\nFORCED TYPE: You MUST return type='{$forced_type}' exactly.\n";
		}

		$forced_reviewed_line = '';
		if ( '' !== $forced_reviewed_type ) {
			$forced_reviewed_line = "\nFORCED REVIEWED TYPE: If type=Review, you MUST set reviewed_type='{$forced_reviewed_type}' exactly.\n";
		}

		return <<<EOT
You are a schema classification and extraction assistant for BoardingArea.
You MUST output a JSON object where the 'result' field contains the schema extraction.

Select a REAL schema.org type from this list:
[{$keys_list}]

{$forced_line}{$forced_reviewed_line}

GLOBAL RULES:
- ONLY choose from the list above.
- Do NOT invent types.
- Do NOT output HTML entities. Use Unicode.
- Prefer facts stated in the post content.

MISSING INFO (CRITICAL):
- If you select a type (e.g. Review) but cannot find important details (e.g. Rating, Location, ISBN, Brand) in the content, you MUST list them in the 'missing_info' array.
- Example: "missing_info": ["Rating", "Hotel Address"]
- Be helpful to the user so they know what to add to their post.

REVIEW VS TRIP DISTINCTION:
- Choose type="Trip" for journeys, itineraries, guides, trip reports.
- Choose type="Review" ONLY for specific evaluations with a verdict/rating.

DETAILS:
- Fill the 'details' object corresponding to your chosen 'type'.
- Leave unrelated detail fields null.
- For Review, strictly set 'reviewed_type' and 'rating' (1-5).
EOT;
	}

	private function clean_content_text( string $html ): string {
		$html = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $html );

		$text = trim( wp_strip_all_tags( $html ) );
		$text = html_entity_decode( $text, ENT_QUOTES );
		$text = preg_replace( '/\s+/', ' ', $text );

		return (string) $text;
	}

	private function clean_content_html( string $html ): string {
		$html = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $html );
		$html = preg_replace( '/\[[^\]]+\]/', ' ', $html );

		$allowed = [
			'p' => [], 'br' => [], 'ul' => [], 'ol' => [], 'li' => [],
			'strong' => [], 'em' => [], 'b' => [], 'i' => [],
			'a' => [ 'href' => true, 'title' => true ],
			'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
			'blockquote' => [], 'code' => [],
		];

		$clean = wp_kses( $html, $allowed );
		$clean = html_entity_decode( (string) $clean, ENT_QUOTES );
		$clean = preg_replace( "/[ \t]+/", ' ', $clean );
		$clean = preg_replace( "/\n{3,}/", "\n\n", $clean );
		return trim( $clean );
	}

	private function clean_content( string $html ): string {
		return $this->clean_content_text( $html );
	}

	private function apply_post_ai_fixes( array $result, \WP_Post $post, string $clean_text, string $clean_html, array $list_hints ): array {
		if ( empty( $result['type'] ) || ! isset( $result['details'] ) || ! is_array( $result['details'] ) ) {
			return $result;
		}

		$type    = (string) $result['type'];
		$details = (array) $result['details'];

		// ItemList fallback extraction
		if ( $type === 'ItemList' ) {
			$itemlist = $details['itemlist'] ?? null;
			if ( ! is_array( $itemlist ) || empty( $itemlist ) ) {
				if ( ! empty( $list_hints ) ) {
					$details['itemlist'] = $list_hints;
				} else {
					$parsed = $this->extract_list_hints_from_html( (string) $post->post_content, 25 );
					if ( ! empty( $parsed ) ) {
						$details['itemlist'] = $parsed;
					}
				}
			}
		}

		// Trip misclassification guardrail
		if ( $type === 'Review' ) {
			$reviewed_type = (string) ( $details['reviewed_type'] ?? '' );
			if ( in_array( $reviewed_type, [ 'Airline', 'Flight' ], true ) ) {
				$title = html_entity_decode( (string) $post->post_title, ENT_QUOTES );

				$title_lower = strtolower( $title );
				$has_review_in_title = str_contains( $title_lower, 'review' );
				$has_trip_in_title   = str_contains( $title_lower, 'trip report' ) || str_contains( $title_lower, 'itinerary' );

				$is_trip         = $this->content_indicates_trip( $clean_text, $title );
				$is_review_focus = $this->content_indicates_review_focus( $clean_text, $details );

				if ( $has_trip_in_title ) {
					$override = true;
				} elseif ( $has_review_in_title ) {
					$override = false;
				} elseif ( $is_trip && ! $is_review_focus ) {
					$override = true;
				} else {
					$override = false;
				}

				if ( isset( $override ) && $override ) {
					$result['type'] = 'Trip';
					$trip_name = ( ! empty( $details['trip_name'] ) && is_string( $details['trip_name'] ) ) ? $details['trip_name'] : $title;
					$itinerary = ( isset( $details['itinerary'] ) && is_array( $details['itinerary'] ) ) ? $details['itinerary'] : [];
					$image     = ( isset( $details['image'] ) && ( is_string( $details['image'] ) || is_null( $details['image'] ) ) ) ? $details['image'] : null;
					$offers    = ( isset( $details['offers'] ) && ( is_array( $details['offers'] ) || is_null( $details['offers'] ) ) ) ? $details['offers'] : null;

					$details = [
						'trip_name' => $trip_name,
						'itinerary' => $itinerary,
						'image'     => $image,
						'offers'    => $offers,
					];
					$result['justification'] = 'Content appears to describe a trip itinerary/journey (Trip) rather than a review of a single flight/airline.';
				}
			}
		}

		if ( isset( $result['summary'] ) && is_string( $result['summary'] ) ) {
			$result['summary'] = html_entity_decode( $result['summary'], ENT_QUOTES );
		}
		if ( isset( $result['justification'] ) && is_string( $result['justification'] ) ) {
			$result['justification'] = html_entity_decode( $result['justification'], ENT_QUOTES );
		}

		$result['details'] = $details;
		return $result;
	}

	private function content_indicates_trip( string $text, string $title = '' ): bool {
		$hay = strtolower( $title . "\n" . $text );

		if ( preg_match( '/\bday\s*\d+\b/i', $hay ) ) {
			return true;
		}
		if ( strpos( $hay, 'itinerary' ) !== false ) {
			return true;
		}

		$signals = 0;
		$tokens  = [
			'trip report', 'our trip', 'road trip', 'things to do', 'where to stay',
			'where to eat', 'visited', 'stopped at', 'we went', 'guide to',
			'weekend in', '2 days', '3 days', '4 days', '5 days', '7 days',
			'multi-city', 'multi city', 'day trip', 'journey', 'route', 'stopover',
		];

		foreach ( $tokens as $tok ) {
			if ( strpos( $hay, $tok ) !== false ) {
				$signals++;
			}
		}

		return $signals >= 2;
	}

	private function content_indicates_review_focus( string $text, array $details ): bool {
		$hay = strtolower( $text );
		$has_review_words = (bool) preg_match( '/\b(review|rating|score|i give it|stars?|verdict)\b/i', $hay );
		$rating           = $details['rating'] ?? null;
		$has_rating       = is_numeric( $rating ) && (float) $rating >= 1.0 && (float) $rating <= 5.0;

		return $has_review_words && $has_rating;
	}

	private function extract_list_hints_from_html( string $html, int $limit = 25 ): array {
		$html = (string) $html;
		if ( trim( $html ) === '' ) {
			return [];
		}

		if ( ! preg_match_all( '/<(ul|ol)\b[^>]*>(.*?)<\/\1>/is', $html, $lists, PREG_SET_ORDER ) ) {
			return [];
		}

		foreach ( $lists as $list_match ) {
			$list_inner = (string) ( $list_match[2] ?? '' );
			if ( $list_inner === '' ) {
				continue;
			}

			if ( ! preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $list_inner, $lis ) ) {
				continue;
			}

			if ( empty( $lis[1] ) || count( $lis[1] ) < 3 ) {
				continue;
			}

			$out = [];
			foreach ( $lis[1] as $li_html ) {
				$li_html = (string) $li_html;
				$url     = '';
				$name    = '';

				if ( preg_match( '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $li_html, $am ) ) {
					$url  = trim( html_entity_decode( (string) $am[1], ENT_QUOTES ) );
					$name = trim( wp_strip_all_tags( (string) $am[2] ) );
				}

				if ( $name === '' ) {
					$name = trim( wp_strip_all_tags( $li_html ) );
				}

				$name = html_entity_decode( $name, ENT_QUOTES );
				$name = preg_replace( '/\s+/', ' ', (string) $name );
				$name = trim( (string) $name );

				if ( $url !== '' ) {
					$url = html_entity_decode( $url, ENT_QUOTES );
					$url = esc_url_raw( $url );
					if ( $url !== '' && str_starts_with( $url, '/' ) ) {
						$url = (string) home_url( $url );
					}
				}

				if ( $name === '' ) {
					continue;
				}

				$out[] = [ 'name' => $name, 'url'  => $url ];

				if ( count( $out ) >= $limit ) {
					break;
				}
			}

			if ( count( $out ) >= 3 ) {
				return $out;
			}
		}

		return [];
	}
}
