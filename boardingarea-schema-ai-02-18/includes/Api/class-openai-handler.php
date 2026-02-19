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

		// Decode entities early to avoid pushing &#8216; etc into AI output.
		$title_raw   = (string) $post->post_title;
		$content_raw = (string) $post->post_content;

		$title = html_entity_decode( $title_raw, ENT_QUOTES );

		$clean_text = $this->clean_content_text( $content_raw );
		if ( strlen( $clean_text ) > 30000 ) {
			$clean_text = substr( $clean_text, 0, 30000 );
		}

		// Provide safe, trimmed HTML to support FAQ answers with markup and list extraction fidelity.
		$clean_html = $this->clean_content_html( $content_raw );
		if ( strlen( $clean_html ) > 30000 ) {
			$clean_html = substr( $clean_html, 0, 30000 );
		}

		$excerpt = has_excerpt( $post )
			? (string) get_the_excerpt( $post )
			: (string) wp_trim_words( (string) $post->post_content, 60 );
		$excerpt = html_entity_decode( $excerpt, ENT_QUOTES );

		// Optional: lightweight list hints for ItemList extraction robustness (does not force ItemList type).
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

		// Apply conservative post-processing fixes (classification/extraction robustness).
		$response = $this->apply_post_ai_fixes( $response, $post, $clean_text, $clean_html, $list_hints );

		return $response;
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

	private function json_schema_definition(): array {
		$supported = Schema_Builder::get_supported_types();
		unset( $supported['Auto'] );

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'type', 'justification', 'summary', 'details' ],
			'properties'           => [
				'type'          => [
					'type' => 'string',
					'enum' => array_keys( $supported ),
				],
				'justification' => [ 'type' => 'string', 'minLength' => 5, 'maxLength' => 280 ],
				'summary'       => [ 'type' => 'string', 'minLength' => 0, 'maxLength' => 200 ],
				'details'       => [ 'type' => 'object', 'additionalProperties' => true ],
			],
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

		foreach ( [ 'type', 'justification', 'summary', 'details' ] as $k ) {
			if ( ! array_key_exists( $k, $result ) ) {
				return new WP_Error( 'basai_openai_missing_keys', 'AI output missing required keys.' );
			}
		}

		return $result;
	}

	private function get_system_prompt( string $forced_type, string $forced_reviewed_type = '' ): string {
		$supported = Schema_Builder::get_supported_types();
		unset( $supported['Auto'] );
		$keys_list = implode( ', ', array_keys( $supported ) );

		$review_types  = Schema_Builder::get_reviewed_types();
		$reviewed_list = implode( ', ', array_keys( $review_types ) );

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
You ONLY output a JSON object (not JSON-LD) selecting a REAL schema.org type from this list:
[{$keys_list}]

{$forced_line}{$forced_reviewed_line}

GLOBAL RULES:
- ONLY choose from the list above.
- Do NOT invent types.
- Do NOT output HTML entities like &#8216; or &amp;. Use the actual character (Unicode) instead.
- Extract details relevant to the chosen schema type, with high precision.
- Prefer facts stated in the post content. Do not guess.

REVIEW VS TRIP DISTINCTION (CRITICAL):
- Choose type="Trip" when the post is primarily about a journey/itinerary/destination sequence (multi-stop, multi-day, guides, trip reports),
  even if flights/hotels are mentioned.
- Choose type="Review" ONLY when the primary purpose is evaluating a specific product/service/entity (flight, hotel, lounge, restaurant, credit card, software, product, place).
- If the post includes an itinerary, "Day 1/Day 2", or multiple stops/attractions, that strongly indicates Trip.

REVIEW RULES:
- If type=Review, you MUST include "reviewed_type".
- reviewed_type MUST be one of: {$reviewed_list}
- For any review: include details.rating as a numeric 1–5 value.
- Do not classify Trip content as "Review - Flight" just because a flight is mentioned.

LOUNGE REVIEW RULE:
If the content is a lounge review, set:
type="Review" and reviewed_type="LocalBusiness"
and include lounge.name + lounge.airport_name + lounge.iata + lounge.terminal.

FAQ HTML RULE:
- For FAQPage extraction: answers MAY contain SAFE HTML markup (e.g. <p>, <br>, <ul>, <ol>, <li>, <strong>, <em>, <a href="...">).
- Preserve meaningful formatting if present in CONTENT_HTML. Do NOT include scripts/styles.

IMPORTANT:
When possible, extract valid schema.org properties for the reviewed entity:
- url
- telephone
- opening_hours (string like "Mo-Fr 09:00-17:00")
- opening_hours_spec (OpeningHoursSpecification array)
- geo (latitude/longitude)
- sameAs (array)
- address (string or structured object)
- priceRange (prefer "$", "$$", "$$$", "$$$$" when a tier is implied; keep short)
- image (URL)

OPENING HOURS QUALITY RULE:
- If you find multiple conflicting schedules (seasonal/special hours), only include multiple rows if you can provide validFrom/validThrough.
- Otherwise, provide ONE consistent schedule per dayOfWeek.

Ensure each schema type has the relevant detail fields:
Review:
- reviewed_type + rating + matching object (flight/hotel/restaurant/etc)
FAQPage:
- details.faq = [{"q":"...","a":"..."}]
HowTo:
- details.howto_steps = ["Step 1", "Step 2", ...]
ItemList:
- details.itemlist = [{"name":"","url":""}] (extract from lists/sections; prefer >= 5 items when available)
VideoObject:
- details.video {name, description, thumbnail, upload_date, duration, embed_url, content_url}
Product:
- details.product {name, brand, url, sameAs}
Trip:
- details.trip_name
- details.itinerary = [{"name":"","location":"","url":"","position":1,"startDate":"","endDate":""}] when possible
- details.provider = {"name":"","url":""} if a tour/company is organizing it; if self-guided, omit provider and focus on itinerary/destination
- details.offers = {"price":"","priceCurrency":"","url":""} if costs/prices are mentioned
Place:
- details.place_name + details.address + url + telephone + opening_hours_spec + geo + sameAs + image
Airline:
- details.airline_name + details.iata + url + sameAs

OUTPUT JSON FORMAT:
{
  "type": "One schema.org type",
  "justification": "1-2 sentences",
  "summary": "short summary",
  "details": {
    "reviewed_type": "{$reviewed_list}",
    "rating": 4.5,
    "flight": {"airline_name":"","iata":"","flight_number":""},
    "hotel": {"name":"","location":"","address":"","star_rating":4.0,"rating":4.2,"url":"","telephone":"","opening_hours":"","opening_hours_spec":[{"dayOfWeek":"Monday","opens":"09:00","closes":"17:00","validFrom":"","validThrough":""}],"geo":{"latitude":0,"longitude":0},"sameAs":[""],"priceRange":"","image":""},
    "lounge": {"name":"","airport_name":"","iata":"","terminal":"","rating":4.0,"url":"","telephone":"","opening_hours":"","opening_hours_spec":[{"dayOfWeek":"Monday","opens":"09:00","closes":"17:00","validFrom":"","validThrough":""}],"geo":{"latitude":0,"longitude":0},"sameAs":[""],"priceRange":"","image":""},
    "card": {"name":"","provider":"","annual_percentage_rate":"","fees":"","interest_rate":"","category":"","rating":4.0},
    "software": {"name":"","category":"","os":"","version":"","rating":4.0},
    "restaurant": {"name":"","location":"","cuisine":"","price_range":"$$","rating":4.0,"url":"","telephone":"","opening_hours":"","opening_hours_spec":[{"dayOfWeek":"Monday","opens":"09:00","closes":"17:00","validFrom":"","validThrough":""}],"geo":{"latitude":0,"longitude":0},"sameAs":[""],"priceRange":"","image":""},
    "product": {"name":"","brand":"","url":"","sameAs":[""]},
    "faq": [{"q":"","a":""}],
    "howto_steps": ["..."],
    "itemlist": [{"name":"","url":""}],
    "video": {"name":"","description":"","thumbnail":"","upload_date":"","duration":"","embed_url":"","content_url":""},
    "trip_name": "",
    "itinerary": [{"name":"","location":"","url":"","position":1,"startDate":"","endDate":""}],
    "provider": {"name":"","url":""},
    "offers": {"price":"","priceCurrency":"","url":""},
    "place_name": "",
    "address": "",
    "airline_name": "",
    "iata": ""
  }
}
EOT;
	}

	/**
	 * Clean content into plain text (for AI classification context).
	 */
	private function clean_content_text( string $html ): string {
		$html = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $html );

		$text = trim( wp_strip_all_tags( $html ) );
		$text = html_entity_decode( $text, ENT_QUOTES );
		$text = preg_replace( '/\s+/', ' ', $text );

		return (string) $text;
	}

	/**
	 * Clean content into SAFE HTML (for FAQ/List fidelity) — strips scripts/styles and allows only a small tag set.
	 */
	private function clean_content_html( string $html ): string {
		$html = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $html );

		// Remove common WP shortcodes (best-effort) to reduce noise.
		$html = preg_replace( '/\[[^\]]+\]/', ' ', $html );

		$allowed = [
			'p'          => [],
			'br'         => [],
			'ul'         => [],
			'ol'         => [],
			'li'         => [],
			'strong'     => [],
			'em'         => [],
			'b'          => [],
			'i'          => [],
			'a'          => [
				'href'  => true,
				'title' => true,
			],
			'h1'         => [],
			'h2'         => [],
			'h3'         => [],
			'h4'         => [],
			'h5'         => [],
			'h6'         => [],
			'blockquote' => [],
			'code'       => [],
		];

		$clean = wp_kses( $html, $allowed );
		$clean = html_entity_decode( (string) $clean, ENT_QUOTES );

		// Normalize whitespace while keeping some structure cues.
		$clean = preg_replace( "/[ \t]+/", ' ', $clean );
		$clean = preg_replace( "/\n{3,}/", "\n\n", $clean );
		$clean = trim( $clean );

		return (string) $clean;
	}

	/**
	 * Backwards compatibility wrapper.
	 */
	private function clean_content( string $html ): string {
		return $this->clean_content_text( $html );
	}



	/**
	 * Conservative post-processing:
	 * - Fix common AI misclassification (Trip vs Review-Flight/Airline) when the post is clearly itinerary/journey content.
	 * - Provide ItemList fallback extraction when AI misses details.itemlist.
	 */
	private function apply_post_ai_fixes( array $result, \WP_Post $post, string $clean_text, string $clean_html, array $list_hints ): array {
		if ( empty( $result['type'] ) || ! isset( $result['details'] ) || ! is_array( $result['details'] ) ) {
			return $result;
		}

		$type    = (string) $result['type'];
		$details = (array) $result['details'];

		// ItemList fallback extraction: if type=ItemList and empty/missing itemlist, use list_hints.
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

		// Trip misclassification guardrail: If AI returns Review (Flight/Airline) but content screams "Trip/Itinerary", override to Trip.
		if ( $type === 'Review' ) {
			$reviewed_type = (string) ( $details['reviewed_type'] ?? '' );
			if ( in_array( $reviewed_type, [ 'Airline', 'Flight' ], true ) ) {
				$is_trip         = $this->content_indicates_trip( $clean_text, html_entity_decode( (string) $post->post_title, ENT_QUOTES ) );
				$is_review_focus = $this->content_indicates_review_focus( $clean_text, $details );

				if ( $is_trip && ! $is_review_focus ) {
					$result['type'] = 'Trip';
					// Ensure trip_name exists.
					if ( empty( $details['trip_name'] ) || ! is_string( $details['trip_name'] ) ) {
						$details['trip_name'] = html_entity_decode( (string) $post->post_title, ENT_QUOTES );
					}
					// Remove review-only constraints that can cascade into wrong downstream schema builds.
					unset( $details['reviewed_type'], $details['rating'] );
					$result['justification'] = 'Content appears to describe a trip itinerary/journey (Trip) rather than a review of a single flight/airline.';
				}
			}
		}

		// Normalize summary/justification unicode entities (AI sometimes mirrors input).
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

		// Strong signals.
		if ( preg_match( '/\bday\s*\d+\b/i', $hay ) ) {
			return true;
		}
		if ( strpos( $hay, 'itinerary' ) !== false ) {
			return true;
		}

		// Moderate signals (require 2+).
		$signals = 0;
		$tokens  = [
			'trip report',
			'our trip',
			'road trip',
			'things to do',
			'where to stay',
			'where to eat',
			'visited',
			'stopped at',
			'we went',
			'guide to',
			'weekend in',
			'2 days',
			'3 days',
			'4 days',
			'5 days',
			'7 days',
			'multi-city',
			'multi city',
			'day trip',
			'journey',
			'route',
			'stopover',
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
		// If the author explicitly frames as a review + has a rating, treat as real review.
		$has_review_words = (bool) preg_match( '/\b(review|rating|score|i give it|stars?|verdict)\b/i', $hay );
		$rating           = $details['rating'] ?? null;
		$has_rating       = is_numeric( $rating ) && (float) $rating >= 1.0 && (float) $rating <= 5.0;

		return $has_review_words && $has_rating;
	}

	/**
	 * Extract list item hints from HTML to improve ItemList extraction reliability.
	 *
	 * @return array<int, array{name:string, url:string}>
	 */
	private function extract_list_hints_from_html( string $html, int $limit = 25 ): array {
		$html = (string) $html;
		if ( trim( $html ) === '' ) {
			return [];
		}

		// Find the first reasonably-sized UL/OL with >= 3 items.
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
					// If it's a relative URL, normalize to absolute.
					if ( $url !== '' && str_starts_with( $url, '/' ) ) {
						$url = (string) home_url( $url );
					}
				}

				if ( $name === '' ) {
					continue;
				}

				$out[] = [
					'name' => $name,
					'url'  => $url,
				];

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
