<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI\Builder;

use BoardingArea\SchemaAI\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema_Builder {

	public static function get_supported_types(): array {
		return [
			'Auto'        => '✨ Auto-Detect (AI)',
			'BlogPosting' => 'BlogPosting (Article)',
			'Article'     => 'Article (Generic)',
			'NewsArticle' => 'NewsArticle (Breaking/Report)',
			'Review'      => 'Review',
			'FAQPage'     => 'FAQPage',
			'HowTo'       => 'HowTo',
			'ItemList'    => 'ItemList (Roundups)',
			'VideoObject' => 'VideoObject',
			'Product'     => 'Product',
			'Trip'        => 'Trip',
			'Place'       => 'Place',
			'Airline'     => 'Airline',
		];
	}

	public static function get_reviewed_types(): array {
		return [
			'Flight'              => 'Flight',
			'Airline'             => 'Airline',
			'Hotel'               => 'Hotel',
			'Restaurant'          => 'Restaurant',
			'SoftwareApplication' => 'SoftwareApplication',
			'CreditCard'          => 'CreditCard',
			'FinancialProduct'    => 'FinancialProduct',
			'LocalBusiness'       => 'LocalBusiness (Lounge)',
			'Place'               => 'Place',
			'Product'             => 'Product',
		];
	}

	/**
	 * Build a full @graph for a post.
	 * Primary CreativeWork ALWAYS exists (BlogPosting/Article/NewsArticle).
	 * Secondary entities are top-level nodes linked via @id.
	 */
	public function build_complete_schema( \WP_Post $post, array $ai_data = [], string $manual_type = 'Auto' ): array {
		$supported = array_keys( self::get_supported_types() );

		$template_id = ( 'Auto' !== $manual_type && '' !== $manual_type )
			? (string) $manual_type
			: (string) ( $ai_data['type'] ?? 'BlogPosting' );

		if ( ! in_array( $template_id, $supported, true ) ) {
			$template_id = 'BlogPosting';
		}

		$details = is_array( $ai_data['details'] ?? null ) ? (array) $ai_data['details'] : [];
		$summary = (string) ( $ai_data['summary'] ?? '' );

		$site_url  = home_url( '/' );
		$site_name = (string) get_bloginfo( 'name' );
		$post_url  = (string) get_permalink( $post );

		$title_raw = (string) get_the_title( $post );
		$title     = html_entity_decode( $title_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$excerpt = has_excerpt( $post )
			? (string) get_the_excerpt( $post )
			: (string) wp_trim_words( (string) $post->post_content, 35 );

		$excerpt = html_entity_decode( $excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$summary = html_entity_decode( $summary, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$published = (string) get_the_date( 'c', $post );
		$modified  = (string) get_the_modified_date( 'c', $post );

		$author_id  = (int) $post->post_author;
		$author_url = (string) get_author_posts_url( $author_id );

		$primary_type = in_array( $template_id, [ 'BlogPosting', 'Article', 'NewsArticle' ], true )
			? $template_id
			: 'BlogPosting';

		$secondary_type = in_array( $template_id, [ 'BlogPosting', 'Article', 'NewsArticle' ], true )
			? ''
			: $template_id;

		$image_url = $this->get_primary_image_url( $post, $details );
		$primary_image_id = ( $image_url !== '' ) ? ( $post_url . '#primaryimage' ) : '';

		$graph = [];

		// --- Organization + Logo (top-level) ---
		$org_id  = trailingslashit( $site_url ) . '#organization';
		$logo_url = (string) ( get_site_icon_url( 512 ) ?: '' );
		$logo_id  = $logo_url ? trailingslashit( $site_url ) . '#logo' : '';

		if ( $logo_url ) {
			$graph[] = [
				'@type' => 'ImageObject',
				'@id'   => $logo_id,
				'url'   => $logo_url,
			];
		}

		$graph[] = [
			'@type' => 'Organization',
			'@id'   => $org_id,
			'name'  => $site_name,
			'url'   => $site_url,
			'logo'  => $logo_id ? [ '@id' => $logo_id ] : null,
		];

		// --- WebSite (optional on all pages) ---
		$emit_website = (bool) get_option( Settings::OPTION_WEBSITE_ALL_PAGES, 1 );
		$website_id   = trailingslashit( $site_url ) . '#website';

		if ( ! is_front_page() && ! $emit_website ) {
			$website_id = '';
		}

		if ( $website_id ) {
			$graph[] = [
				'@type'     => 'WebSite',
				'@id'       => $website_id,
				'url'       => $site_url,
				'name'      => $site_name,
				'publisher' => [ '@id' => $org_id ],
			];
		}

		// --- ImageObject ---
		if ( $image_url !== '' ) {
			$graph[] = [
				'@type' => 'ImageObject',
				'@id'   => $primary_image_id,
				'url'   => $image_url,
			];
		}

		// --- WebPage ---
		$webpage = [
			'@type'              => 'WebPage',
			'@id'                => $post_url . '#webpage',
			'url'                => $post_url,
			'name'               => $title,
			'isPartOf'           => $website_id ? [ '@id' => $website_id ] : null,
			'primaryImageOfPage' => ( $primary_image_id !== '' ) ? [ '@id' => $primary_image_id ] : null,
			'datePublished'      => $published,
			'dateModified'       => $modified,
			'description'        => $excerpt,
			'breadcrumb'         => [ '@id' => $post_url . '#breadcrumb' ],
			'inLanguage'         => $this->normalize_bcp47( (string) get_locale() ),
		];
		$graph[] = $webpage;
		$webpage_index = count( $graph ) - 1;

		// --- BreadcrumbList ---
		$graph[] = [
			'@type'           => 'BreadcrumbList',
			'@id'             => $post_url . '#breadcrumb',
			'itemListElement' => [
				[
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => 'Home',
					'item'     => $site_url,
				],
				[
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => $title,
					'item'     => $post_url,
				],
			],
		];

		// --- Author ---
		$graph[] = [
			'@type' => 'Person',
			'@id'   => $author_url . '#author',
			'name'  => (string) get_the_author_meta( 'display_name', $author_id ),
			'url'   => $author_url,
			'image' => $this->maybe_image_object_inline( (string) get_avatar_url( $author_id ) ),
		];

		// --- Primary CreativeWork ---
		$primary_id = $post_url . '#primary';
		$body = wp_strip_all_tags( (string) $post->post_content );
		$body = wp_trim_words( $body, 300 );

		$primary = [
			'@type'            => $primary_type,
			'@id'              => $primary_id,
			'isPartOf'         => $website_id ? [ '@id' => $website_id ] : null,
			'mainEntityOfPage' => [ '@id' => $post_url . '#webpage' ],
			'author'           => [ '@id' => $author_url . '#author' ],
			'headline'         => $title,
			'datePublished'    => $published,
			'dateModified'     => $modified,
			'publisher'        => [ '@id' => $org_id ],
			'image'            => ( $primary_image_id !== '' ) ? [ '@id' => $primary_image_id ] : null,
			'description'      => $excerpt,
			'articleBody'      => $body,
		];
		$graph[] = $primary;
		$primary_index = count( $graph ) - 1;

		// --- Secondary Entity Handling ---
		if ( $secondary_type !== '' ) {
			$secondary_node = null;
			$secondary_id   = $post_url . '#' . strtolower( $secondary_type );

			switch ( $secondary_type ) {
				case 'FAQPage':
					$questions = $this->build_faq_questions( $details );
					if ( ! empty( $questions ) ) {
						$secondary_node = [
							'@type'       => 'FAQPage',
							'@id'         => $secondary_id,
							'url'         => $post_url,
							'name'        => $title,
							'isPartOf'    => $website_id ? [ '@id' => $website_id ] : null,
							'mainEntity'  => $questions,
							'inLanguage'  => $this->normalize_bcp47( (string) get_locale() ),
						];
					}
					break;

				case 'HowTo':
					$secondary_node = $this->build_howto(
						[
							'@id'      => $secondary_id,
							'headline' => $title,
						],
						$details,
						$excerpt,
						$post_url,
						$image_url
					);
					break;

				case 'ItemList':
					$secondary_node = $this->build_itemlist( $details, $title, $excerpt, $post_url );
					$secondary_node['@id'] = $secondary_id;
					break;

				case 'Review':
					$pack = $this->build_review_with_item(
						[
							'@id'           => $secondary_id,
							'name'          => $title,
							'headline'      => $title,
							'author'        => [ '@id' => $author_url . '#author' ],
							'datePublished' => $published,
							'dateModified'  => $modified,
							'publisher'     => [ '@id' => $org_id ],
						],
						$details,
						$summary,
						$image_url,
						$post_url
					);
					$secondary_node = $pack['review'];
					if ( ! empty( $pack['item'] ) ) {
						$graph[] = $pack['item'];
					}
					if ( ! empty( $pack['extra_graph'] ) && is_array( $pack['extra_graph'] ) ) {
						foreach ( $pack['extra_graph'] as $extra_node ) {
							if ( is_array( $extra_node ) && ! empty( $extra_node ) ) {
								$graph[] = $extra_node;
							}
						}
					}
					break;

				case 'VideoObject':
					$secondary_node = $this->build_videoobject(
						[
							'@id'      => $secondary_id,
							'headline' => $title,
						],
						$details
					);
					break;

				case 'Trip':
					$secondary_node = $this->build_trip_entity( $details, $summary, $primary_image_id, $post_url, $title );
					break;

				case 'Place':
					$secondary_node = $this->build_place_entity( $details, $summary, $primary_image_id, $post_url, $title );
					break;

				case 'Airline':
					$secondary_node = $this->build_airline_entity( $details, $summary, $primary_image_id, $post_url, $title );
					break;

				case 'Product':
					$secondary_node = $this->build_product_entity( $details, $summary, $primary_image_id, $title, $post_url );
					break;

				default:
					break;
			}

			if ( $secondary_node ) {
				$graph[] = $secondary_node;
				$link = [ '@id' => $secondary_node['@id'] ?? $secondary_id ];

				if ( $secondary_type === 'VideoObject' ) {
					$graph[ $primary_index ]['video'] = $link;
				} elseif ( $secondary_type === 'Airline' ) {
					$graph[ $primary_index ] = $this->add_linked_entity( $graph[ $primary_index ], 'mentions', $link );
				} else {
					$graph[ $primary_index ] = $this->add_linked_entity( $graph[ $primary_index ], 'about', $link );
				}

				$graph[ $webpage_index ] = $this->add_linked_entity( $graph[ $webpage_index ], 'about', $link );
			}
		}

		// Connect WebPage -> Primary ONLY if mainEntity not already used
		if ( empty( $graph[ $webpage_index ]['mainEntity'] ) ) {
			$graph[ $webpage_index ]['mainEntity'] = [ '@id' => $primary_id ];
		}

		return $this->prune_nulls( [
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		] );
	}

	// ======================== NEW REVIEW PACKING ========================
	private function build_review_with_item( array $base, array $details, string $summary, string $image_url, string $post_url ): array {
		$reviewed_type = (string) ( $details['reviewed_type'] ?? '' );
		$allowed       = array_keys( self::get_reviewed_types() );

		if ( ! in_array( $reviewed_type, $allowed, true ) ) {
			$reviewed_type = 'Product';
		}

		$item_id = $post_url . '#reviewed-' . strtolower( $reviewed_type );

		$itemReviewed = $this->build_reviewed_item_node( $reviewed_type, $details, $image_url, $base['headline'] ?? '', $item_id );
		if ( isset( $itemReviewed['@id'] ) && is_string( $itemReviewed['@id'] ) && '' !== $itemReviewed['@id'] ) {
			$item_id = $itemReviewed['@id'];
		}

		$rating_val = $details['rating']
			?? ( $details['lounge']['rating'] ?? null )
			?? ( $details['hotel']['rating'] ?? null )
			?? ( $details['restaurant']['rating'] ?? null )
			?? ( $details['software']['rating'] ?? null )
			?? ( $details['card']['rating'] ?? null );

		$review = array_merge(
			$base,
			[
				'@type'        => 'Review',
				'reviewBody'   => ( '' !== $summary ) ? $summary : null,
				'reviewRating' => $this->rating_node( $rating_val ),
				'itemReviewed' => [ '@id' => $item_id ],
			]
		);

		$extra_graph = [];
		if ( $reviewed_type === 'LocalBusiness' ) {
			$airport = $this->build_airport_node_from_lounge( $details );
			if ( $airport ) {
				$extra_graph[] = $airport;
			}
		}

		return [ 'review' => $review, 'item' => $itemReviewed, 'extra_graph' => $extra_graph ];
	}

	private function build_reviewed_item_node( string $type, array $details, string $image_url, string $fallback, string $id ): array {
		switch ( $type ) {
			case 'Hotel':
				return array_merge( [ '@id' => $id ], $this->reviewed_hotel( $details, $image_url, $fallback ) );
			case 'Restaurant':
				return array_merge( [ '@id' => $id ], $this->reviewed_restaurant( $details, $image_url, $fallback ) );
			case 'LocalBusiness':
				return array_merge( [ '@id' => $id ], $this->reviewed_lounge( $details, $image_url, $fallback ) );
			case 'Airline':
				return array_merge( [ '@id' => $id ], $this->reviewed_airline( $details, $fallback ) );
			case 'Place':
				return array_merge( [ '@id' => $id ], $this->reviewed_place( $details, $image_url, $fallback ) );
			case 'Product':
			default:
				return array_merge( [ '@id' => $id ], $this->reviewed_product( $details, $image_url, $fallback ) );
		}
	}

	// ======================== ORIGINAL METHODS (UNCHANGED) ========================
	private function normalize_bcp47( string $locale ): string {
		$locale = trim( $locale );
		if ( $locale === '' ) {
			return 'en-US';
		}
		$locale = str_replace( '_', '-', $locale );
		$parts = explode( '-', $locale );
		if ( isset( $parts[0] ) ) {
			$parts[0] = strtolower( $parts[0] );
		}
		if ( isset( $parts[1] ) && strlen( $parts[1] ) === 2 ) {
			$parts[1] = strtoupper( $parts[1] );
		}
		return implode( '-', $parts );
	}

	private function add_linked_entity( array $node, string $prop, array $link ): array {
		if ( empty( $node[ $prop ] ) ) {
			$node[ $prop ] = [ $link ];
			return $node;
		}
		$existing = $node[ $prop ];
		if ( is_array( $existing ) && isset( $existing['@id'] ) ) {
			$existing = [ $existing ];
		}
		if ( is_array( $existing ) ) {
			$existing[] = $link;
			$unique = [];
			$seen = [];
			foreach ( $existing as $item ) {
				if ( isset( $item['@id'] ) ) {
					if ( isset( $seen[ $item['@id'] ] ) ) {
						continue;
					}
					$seen[ $item['@id'] ] = true;
				}
				$unique[] = $item;
			}
			$node[ $prop ] = $unique;
		}
		return $node;
	}

	private function get_primary_image_url( \WP_Post $post, array $details ): string {
		$site_url  = home_url( '/' );
		$site_host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		$image_url = '';
		$image_id  = (int) get_post_thumbnail_id( $post );
		$image_src = $image_id ? wp_get_attachment_image_src( $image_id, 'full' ) : false;
		if ( is_array( $image_src ) && ! empty( $image_src[0] ) ) {
			$image_url = (string) $image_src[0];
		}
		if ( $image_url === '' ) {
			$image_url = $this->first_local_content_image( (string) $post->post_content, $site_host );
		}
		if ( $image_url === '' ) {
			$image_url = $this->pick_details_image( $details, $site_host );
		}
		return $image_url;
	}

	private function is_allowed_image_url( string $url, string $site_host ): bool {
		$url = trim( $url );
		if ( $url === '' ) {
			return false;
		}
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parsed['host'] ?? '' ) );
		$path   = strtolower( (string) ( $parsed['path'] ?? '' ) );
		if ( $scheme !== 'http' && $scheme !== 'https' ) {
			return false;
		}
		if ( $host === '' ) {
			return false;
		}
		if ( ! preg_match( '/\.(jpe?g|png|webp)$/', $path ) ) {
			return false;
		}
		if ( $site_host !== '' && $host === $site_host ) {
			return true;
		}
		if ( in_array( $host, [ 'upload.wikimedia.org', 'commons.wikimedia.org' ], true ) ) {
			return true;
		}
		return false;
	}

	private function first_local_content_image( string $html, string $site_host ): string {
		$html = (string) $html;
		if ( trim( $html ) === '' ) {
			return '';
		}
		if ( ! preg_match_all( '/<img[^>]+src=[\"\']([^\"\']+)[\"\']/i', $html, $m ) ) {
			return '';
		}
		foreach ( (array) $m[1] as $src ) {
			$src = html_entity_decode( (string) $src, ENT_QUOTES );
			$src = trim( $src );
			if ( $src === '' ) {
				continue;
			}
			if ( 0 === strpos( $src, '//' ) ) {
				$src = 'https:' . $src;
			} elseif ( 0 === strpos( $src, '/' ) ) {
				$src = home_url( $src );
			}
			$src  = esc_url_raw( $src );
			$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
			if ( $host !== '' && $site_host !== '' && $host === $site_host && $this->is_allowed_image_url( $src, $site_host ) ) {
				return $src;
			}
		}
		return '';
	}

	private function pick_details_image( array $details, string $site_host ): string {
		$candidates = [];
		foreach ( [ 'lounge', 'hotel', 'restaurant', 'product', 'software' ] as $k ) {
			if ( isset( $details[ $k ] ) && is_array( $details[ $k ] ) ) {
				$img = (string) ( $details[ $k ]['image'] ?? '' );
				if ( $img !== '' ) {
					$candidates[] = $img;
				}
			}
		}
		$root_img = (string) ( $details['image'] ?? '' );
		if ( $root_img !== '' ) {
			$candidates[] = $root_img;
		}
		foreach ( $candidates as $c ) {
			$c = trim( (string) $c );
			if ( $c !== '' && $this->is_allowed_image_url( $c, $site_host ) ) {
				return $c;
			}
		}
		return '';
	}

	private function build_trip_entity( array $details, string $summary, string $primary_image_id, string $post_url, string $title ): array {
		$trip = [
			'@type'       => 'Trip',
			'@id'         => $post_url . '#trip',
			'name'        => (string) ( $details['trip_name'] ?? $title ),
			'description' => ( '' !== $summary ) ? $summary : null,
			'image'       => ( '' !== $primary_image_id ) ? [ '@id' => $primary_image_id ] : null,
		];
		$itinerary = [];
		if ( isset( $details['itinerary'] ) && is_array( $details['itinerary'] ) ) {
			foreach ( $details['itinerary'] as $i => $stop ) {
				if ( ! is_array( $stop ) ) {
					continue;
				}
				$itinerary[] = [
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => (string) ( $stop['name'] ?? $stop['title'] ?? '' ),
					'url'      => (string) ( $stop['url'] ?? '' ),
					'item'     => [
						'@type'   => 'Place',
						'name'    => (string) ( $stop['location'] ?? $stop['name'] ?? '' ),
						'address' => $this->address_node( $stop['address'] ?? '' ),
					],
				];
			}
		}
		if ( ! empty( $itinerary ) ) {
			$trip['itinerary'] = [
				'@type'           => 'ItemList',
				'itemListElement' => $itinerary,
			];
		}
		return $trip;
	}

	private function build_place_entity( array $details, string $summary, string $primary_image_id, string $post_url, string $title ): array {
		$place = [
			'@type'       => 'Place',
			'@id'         => $post_url . '#place',
			'name'        => (string) ( $details['place_name'] ?? $title ),
			'description' => ( '' !== $summary ) ? $summary : null,
			'address'     => $this->address_node( $details['address'] ?? '' ),
			'image'       => ( '' !== $primary_image_id ) ? [ '@id' => $primary_image_id ] : null,
		];
		return $this->apply_place_like_props( $place, $details, true, false );
	}

	private function build_airline_entity( array $details, string $summary, string $primary_image_id, string $post_url, string $title ): array {
		$airline = [
			'@type'       => 'Airline',
			'@id'         => $post_url . '#airline',
			'name'        => (string) ( $details['airline_name'] ?? $title ),
			'description' => ( '' !== $summary ) ? $summary : null,
			'iataCode'    => (string) ( $details['iata'] ?? '' ),
			'image'       => ( '' !== $primary_image_id ) ? [ '@id' => $primary_image_id ] : null,
		];
		return $this->apply_common_thing_props( $airline, $details );
	}

	private function build_product_entity( array $details, string $summary, string $primary_image_id, string $title, string $post_url ): array {
		$p = is_array( $details['product'] ?? null ) ? (array) $details['product'] : [];
		$product = [
			'@type'       => 'Product',
			'@id'         => $post_url . '#product',
			'name'        => (string) ( $p['name'] ?? $title ),
			'description' => ( '' !== $summary ) ? $summary : null,
			'image'       => ( '' !== $primary_image_id ) ? [ '@id' => $primary_image_id ] : null,
			'brand'       => (string) ( $p['brand'] ?? '' ),
		];
		return $this->apply_common_thing_props( $product, $p );
	}

	private function build_review( array $base, array $details, string $summary, string $image_url ): array {
		$reviewed_type = (string) ( $details['reviewed_type'] ?? '' );
		$allowed       = array_keys( self::get_reviewed_types() );
		if ( ! in_array( $reviewed_type, $allowed, true ) ) {
			$reviewed_type = '';
		}
		if ( '' === $reviewed_type ) {
			if ( ! empty( $details['lounge'] ) ) {
				$reviewed_type = 'LocalBusiness';
			} elseif ( ! empty( $details['hotel'] ) ) {
				$reviewed_type = 'Hotel';
			} elseif ( ! empty( $details['restaurant'] ) ) {
				$reviewed_type = 'Restaurant';
			} elseif ( ! empty( $details['software'] ) ) {
				$reviewed_type = 'SoftwareApplication';
			} elseif ( ! empty( $details['flight'] ) ) {
				$reviewed_type = 'Flight';
			} elseif ( ! empty( $details['card'] ) ) {
				$reviewed_type = 'CreditCard';
			} else {
				$reviewed_type = 'Product';
			}
		}
		$headline_fallback = (string) ( $base['headline'] ?? '' );
		switch ( $reviewed_type ) {
			case 'Flight':
				$itemReviewed = $this->reviewed_flight( $details );
				break;
			case 'Airline':
				$itemReviewed = $this->reviewed_airline( $details, $headline_fallback );
				break;
			case 'Hotel':
				$itemReviewed = $this->reviewed_hotel( $details, $image_url, $headline_fallback );
				break;
			case 'Restaurant':
				$itemReviewed = $this->reviewed_restaurant( $details, $image_url, $headline_fallback );
				break;
			case 'SoftwareApplication':
				$itemReviewed = $this->reviewed_software( $details, $image_url, $headline_fallback );
				break;
			case 'CreditCard':
				$itemReviewed = $this->reviewed_credit_card( $details, $headline_fallback );
				break;
			case 'FinancialProduct':
				$itemReviewed = $this->reviewed_financial_product( $details, $headline_fallback );
				break;
			case 'LocalBusiness':
				$itemReviewed = $this->reviewed_lounge( $details, $image_url, $headline_fallback );
				break;
			case 'Place':
				$itemReviewed = $this->reviewed_place( $details, $image_url, $headline_fallback );
				break;
			default:
				$itemReviewed = $this->reviewed_product( $details, $image_url, $headline_fallback );
				break;
		}
		$rating_val = $details['rating']
			?? ( $details['lounge']['rating'] ?? null )
			?? ( $details['hotel']['rating'] ?? null )
			?? ( $details['restaurant']['rating'] ?? null )
			?? ( $details['software']['rating'] ?? null )
			?? ( $details['card']['rating'] ?? null );
		return array_merge(
			$base,
			[
				'@type'        => 'Review',
				'reviewBody'   => ( '' !== $summary ) ? $summary : null,
				'reviewRating' => $this->rating_node( $rating_val ),
				'itemReviewed' => $itemReviewed,
			]
		);
	}

	private function reviewed_product( array $details, string $image_url, string $fallback ): array {
		$p = is_array( $details['product'] ?? null ) ? (array) $details['product'] : [];
		$name = trim( (string) ( $p['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $fallback;
		}
		$product = [
			'@type' => 'Product',
			'name'  => $name,
			'brand' => (string) ( $p['brand'] ?? '' ),
			'image' => ( '' !== $image_url ) ? $image_url : null,
		];
		return $this->apply_common_thing_props( $product, $p );
	}

	private function reviewed_flight( array $details ): array {
		$f = is_array( $details['flight'] ?? null ) ? (array) $details['flight'] : [];
		return [
			'@type'        => 'Flight',
			'flightNumber' => (string) ( $f['flight_number'] ?? '' ),
			'airline'      => [
				'@type'    => 'Airline',
				'name'     => (string) ( $f['airline_name'] ?? '' ),
				'iataCode' => (string) ( $f['iata'] ?? '' ),
			],
		];
	}

	private function reviewed_airline( array $details, string $fallback ): array {
		$name = trim( (string) ( $details['airline_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $fallback;
		}
		$airline = [
			'@type'    => 'Airline',
			'name'     => $name,
			'iataCode' => (string) ( $details['iata'] ?? '' ),
		];
		return $this->apply_common_thing_props( $airline, $details );
	}

	private function reviewed_hotel( array $details, string $image_url, string $fallback ): array {
		$h = is_array( $details['hotel'] ?? null ) ? (array) $details['hotel'] : [];
		$name = trim( (string) ( $h['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $fallback;
		}
		$hotel = [
			'@type'      => 'Hotel',
			'name'       => $name,
			'address'    => $this->address_node( $h['address'] ?? $h['location'] ?? '' ),
			'image'      => ( '' !== $image_url ) ? $image_url : null,
			'starRating' => $this->star_rating_node( $h['star_rating'] ?? null ),
		];
		return $this->apply_place_like_props( $hotel, $h, true, true );
	}

	private function reviewed_restaurant( array $details, string $image_url, string $fallback ): array {
		$r = is_array( $details['restaurant'] ?? null ) ? (array) $details['restaurant'] : [];
		$name = trim( (string) ( $r['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $fallback;
		}
		$restaurant = [
			'@type'         => 'Restaurant',
			'name'          => $name,
			'address'       => $this->address_node( $r['address'] ?? $r['location'] ?? '' ),
			'servesCuisine' => (string) ( $r['cuisine'] ?? '' ),
			'priceRange'    => (string) ( $r['price_range'] ?? '$$' ),
			'image'         => ( '' !== $image_url ) ? $image_url : null,
			'menu'          => (string) ( $r['menu'] ?? '' ),
		];
		return $this->apply_place_like_props( $restaurant, $r, true, true );
	}

	private function reviewed_software( array $details, string $image_url, string $fallback ): array {
		$s = is_array( $details['software'] ?? null ) ? (array) $details['software'] : [];
		$name = trim( (string) ( $s['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $fallback;
		}
		$software = [
			'@type'               => 'SoftwareApplication',
			'name'                => $name,
			'applicationCategory' => (string) ( $s['category'] ?? '' ),
			'operatingSystem'     => (string) ( $s['os'] ?? '' ),
			'softwareVersion'     => (string) ( $s['version'] ?? '' ),
			'image'               => ( '' !== $image_url ) ? $image_url : null,
		];
		return $this->apply_common_thing_props( $software, $s );
	}

	private function reviewed_credit_card( array $details, string $fallback ): array {
		$c = is_array( $details['card'] ?? null ) ? (array) $details['card'] : [];
		$name = trim( (string) ( $c['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $fallback;
		}
		$card = [
			'@type'                           => 'CreditCard',
			'name'                            => $name,
			'category'                        => (string) ( $c['category'] ?? '' ),
			'annualPercentageRate'            => (string) ( $c['annual_percentage_rate'] ?? '' ),
			'feesAndCommissionsSpecification' => (string) ( $c['fees'] ?? $c['annual_fee'] ?? '' ),
			'interestRate'                    => (string) ( $c['interest_rate'] ?? '' ),
			'provider'                        => ( ! empty( $c['provider'] ) ) ? [
				'@type' => 'Organization',
				'name'  => (string) $c['provider'],
			] : null,
		];
		return $this->apply_common_thing_props( $card, $c );
	}

	private function reviewed_financial_product( array $details, string $fallback ): array {
		$c = is_array( $details['card'] ?? null ) ? (array) $details['card'] : [];
		$name = trim( (string) ( $c['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $fallback;
		}
		$prod = [
			'@type'                           => 'FinancialProduct',
			'name'                            => $name,
			'feesAndCommissionsSpecification' => (string) ( $c['fees'] ?? $c['annual_fee'] ?? '' ),
			'interestRate'                    => (string) ( $c['interest_rate'] ?? '' ),
			'annualPercentageRate'            => (string) ( $c['annual_percentage_rate'] ?? '' ),
			'provider'                        => ( ! empty( $c['provider'] ) ) ? [
				'@type' => 'Organization',
				'name'  => (string) $c['provider'],
			] : null,
		];
		return $this->apply_common_thing_props( $prod, $c );
	}

	private function reviewed_lounge( array $details, string $image_url, string $fallback ): array {
		$l = is_array( $details['lounge'] ?? null ) ? (array) $details['lounge'] : [];
		$name = trim( (string) ( $l['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $fallback ?: 'Airport Lounge';
		}
		$airport_name = trim( (string) ( $l['airport_name'] ?? '' ) );
		$iata         = strtoupper( trim( (string) ( $l['iata'] ?? '' ) ) );
		$terminal     = trim( (string) ( $l['terminal'] ?? '' ) );

		$site_url = home_url( '/' );
		$slug_lounge  = sanitize_title( $name );
		$slug_airport = sanitize_title( $iata ?: $airport_name ?: 'airport' );

		$lounge_id    = trailingslashit( $site_url ) . '#airportlounge-' . $slug_lounge . '-' . $slug_airport;
		$has_airport  = ( $airport_name !== '' || $iata !== '' );
		$airport_id   = $has_airport ? ( trailingslashit( $site_url ) . '#airport-' . $slug_airport ) : '';

		$addr_val = $l['address'] ?? null;
		$address_node = null;
		if ( is_array( $addr_val ) || is_string( $addr_val ) ) {
			$address_node = $this->address_node( $addr_val );
		} elseif ( '' !== $terminal ) {
			$address_node = $this->address_node( [ 'streetAddress' => $terminal ] );
		}

		$lounge = [
			'@type'            => 'LocalBusiness',
			'@id'              => $lounge_id,
			'name'             => $name,
			'additionalType'   => 'https://en.wikipedia.org/wiki/Airport_lounge',
			'keywords'         => 'Airport lounge, airport lounge review',
			'containedInPlace' => $has_airport ? [
				'@type'    => 'Airport',
				'@id'      => $airport_id,
				'name'     => $airport_name,
				'iataCode' => $iata,
			] : null,
			'address'          => $address_node,
			'image'            => ( '' !== $image_url ) ? $image_url : null,
		];
		return $this->apply_place_like_props( $lounge, $l, true, true );
	}

	private function build_airport_node_from_lounge( array $details ): ?array {
		$l = is_array( $details['lounge'] ?? null ) ? (array) $details['lounge'] : [];
		$airport_name = trim( (string) ( $l['airport_name'] ?? '' ) );
		$iata         = strtoupper( trim( (string) ( $l['iata'] ?? '' ) ) );
		if ( $airport_name === '' && $iata === '' ) {
			return null;
		}

		$site_url    = home_url( '/' );
		$slug_airport = sanitize_title( $iata ?: $airport_name ?: 'airport' );
		$airport_id  = trailingslashit( $site_url ) . '#airport-' . $slug_airport;

		$name = $airport_name;
		if ( $name === '' && $iata !== '' ) {
			$name = $iata . ' Airport';
		}

		return [
			'@type'    => 'Airport',
			'@id'      => $airport_id,
			'name'     => $name,
			'iataCode' => $iata ?: null,
		];
	}

	private function reviewed_place( array $details, string $image_url, string $fallback ): array {
		$name = trim( (string) ( $details['place_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $fallback;
		}
		$place = [
			'@type'   => 'Place',
			'name'    => $name,
			'address' => $this->address_node( $details['address'] ?? '' ),
			'image'   => ( '' !== $image_url ) ? $image_url : null,
		];
		return $this->apply_place_like_props( $place, $details, true, false );
	}

	private function build_itemlist( array $details, string $headline, string $excerpt, string $post_url ): array {
		$items = is_array( $details['itemlist'] ?? null ) ? (array) $details['itemlist'] : [];
		$out = [];
		$pos = 1;
		foreach ( $items as $i ) {
			if ( ! is_array( $i ) ) {
				continue;
			}
			$out[] = [
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => (string) ( $i['name'] ?? '' ),
				'url'      => (string) ( $i['url'] ?? '' ),
			];
			if ( $pos > 25 ) {
				break;
			}
		}
		return [
			'@type'           => 'ItemList',
			'@id'             => $post_url . '#itemlist',
			'name'            => $headline,
			'description'     => $excerpt,
			'url'             => $post_url,
			'itemListElement' => $out ?: null,
		];
	}

	private function build_videoobject( array $base, array $details ): array {
		$v = is_array( $details['video'] ?? null ) ? (array) $details['video'] : [];
		return array_merge(
			$base,
			[
				'@type'        => 'VideoObject',
				'name'         => (string) ( $v['name'] ?? $base['headline'] ?? '' ),
				'description'  => (string) ( $v['description'] ?? '' ),
				'thumbnailUrl' => (string) ( $v['thumbnail'] ?? '' ),
				'uploadDate'   => (string) ( $v['upload_date'] ?? '' ),
				'duration'     => (string) ( $v['duration'] ?? '' ),
				'embedUrl'     => (string) ( $v['embed_url'] ?? '' ),
				'contentUrl'   => (string) ( $v['content_url'] ?? '' ),
			]
		);
	}

	private function build_howto( array $base, array $details, string $excerpt, string $post_url, string $image_url ): array {
		$steps = is_array( $details['howto_steps'] ?? null ) ? (array) $details['howto_steps'] : [];
		$out = [];
		$pos = 1;
		foreach ( $steps as $s ) {
			$s = trim( (string) $s );
			if ( '' === $s ) {
				continue;
			}
			$position = $pos++;
			$out[] = [
				'@type'    => 'HowToStep',
				'position' => $position,
				'text'     => $s,
				'url'      => $post_url . '#step-' . $position,
			];
		}
		return array_merge(
			$base,
			[
				'@type'       => 'HowTo',
				'name'        => $base['headline'] ?? '',
				'description' => $excerpt,
				'step'        => $out ?: null,
				'image'       => ( '' !== $image_url ) ? $image_url : null,
			]
		);
	}

	private function build_faq_questions( array $details ): array {
		$faq = is_array( $details['faq'] ?? null ) ? (array) $details['faq'] : [];
		$main = [];
		foreach ( $faq as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$q = wp_strip_all_tags( (string) ( $f['q'] ?? '' ) );
			$a = wp_kses_post( (string) ( $f['a'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$main[] = [
				'@type' => 'Question',
				'name'  => $q,
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => $a,
				],
			];
		}
		return $main;
	}

	private function rating_node( $val ): ?array {
		if ( null === $val || '' === $val ) {
			return null;
		}
		$num = is_numeric( $val ) ? (float) $val : null;
		if ( null === $num ) {
			return null;
		}
		$num = max( 1.0, min( 5.0, $num ) );
		return [
			'@type'       => 'Rating',
			'ratingValue' => $num,
			'bestRating'  => 5,
			'worstRating' => 1,
		];
	}

	private function star_rating_node( $val ): ?array {
		if ( null === $val || '' === $val ) {
			return null;
		}
		$num = is_numeric( $val ) ? (float) $val : null;
		if ( null === $num ) {
			return null;
		}
		$num = max( 1.0, min( 5.0, $num ) );
		return [
			'@type'       => 'Rating',
			'ratingValue' => $num,
			'bestRating'  => 5,
			'worstRating' => 1,
		];
	}

	private function maybe_image_object_inline( string $url ): ?array {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		return [ '@type' => 'ImageObject', 'url' => $url ];
	}

	private function address_node( $val ) {
		if ( is_array( $val ) ) {
			return [
				'@type'           => 'PostalAddress',
				'streetAddress'   => (string) ( $val['streetAddress'] ?? $val['street'] ?? '' ),
				'addressLocality' => (string) ( $val['addressLocality'] ?? $val['city'] ?? '' ),
				'addressRegion'   => (string) ( $val['addressRegion'] ?? $val['region'] ?? '' ),
				'postalCode'      => (string) ( $val['postalCode'] ?? $val['zip'] ?? '' ),
				'addressCountry'  => (string) ( $val['addressCountry'] ?? $val['country'] ?? '' ),
			];
		}
		$str = trim( (string) $val );
		if ( '' === $str ) {
			return null;
		}
		return [
			'@type'         => 'PostalAddress',
			'streetAddress' => $str,
		];
	}

	private function geo_node( $val ): ?array {
		if ( ! is_array( $val ) ) {
			return null;
		}
		$lat = $val['lat'] ?? $val['latitude'] ?? null;
		$lng = $val['lng'] ?? $val['longitude'] ?? null;
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( 0.0 === $lat && 0.0 === $lng ) {
			return null;
		}
		return [
			'@type'     => 'GeoCoordinates',
			'latitude'  => $lat,
			'longitude' => $lng,
		];
	}

	private function opening_hours_node( $val ) {
		if ( is_array( $val ) ) {
			$clean = array_values( array_filter( array_map( 'trim', $val ) ) );
			return $clean ?: null;
		}
		$str = trim( (string) $val );
		return ( '' !== $str ) ? $str : null;
	}

	private function opening_hours_spec_node( $val ) {
		if ( ! is_array( $val ) ) {
			return null;
		}
		$out = [];
		foreach ( $val as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$spec = [
				'@type'        => 'OpeningHoursSpecification',
				'dayOfWeek'    => $row['dayOfWeek'] ?? $row['day'] ?? null,
				'opens'        => $row['opens'] ?? null,
				'closes'       => $row['closes'] ?? null,
				'validFrom'    => $row['validFrom'] ?? null,
				'validThrough' => $row['validThrough'] ?? null,
			];
			$out[] = array_filter( $spec );
		}
		return $out ?: null;
	}

	private function apply_common_thing_props( array $node, array $details ): array {
		$url = trim( (string) ( $details['url'] ?? '' ) );
		$sameAs = $details['sameAs'] ?? $details['same_as'] ?? null;
		if ( '' !== $url ) {
			$node['url'] = $url;
		}
		if ( is_array( $sameAs ) && ! empty( $sameAs ) ) {
			$node['sameAs'] = $sameAs;
		}
		return $node;
	}

	public static function normalize_price_range( $raw ): ?string {
		if ( is_array( $raw ) || is_object( $raw ) ) {
			return null;
		}
		$raw = preg_replace( '/\s+/', ' ', trim( (string) $raw ) );
		if ( $raw === '' ) {
			return null;
		}
		if ( preg_match( '/^\${1,4}(\s*[\-–]\s*\${1,4})?$/', $raw ) ) {
			return $raw;
		}
		$len = function_exists( 'mb_strlen' ) ? (int) mb_strlen( $raw ) : (int) strlen( $raw );
		if ( $len <= 30 ) {
			return $raw;
		}
		if ( preg_match_all( '/\$\s*\d[\d,]*(?:\.\d+)?/', $raw, $m ) && ! empty( $m[0] ) ) {
			$amounts = [];
			foreach ( $m[0] as $tok ) {
				$num = preg_replace( '/[^\d.]/', '', str_replace( ',', '', $tok ) );
				if ( $num === '' ) {
					continue;
				}
				$val = (float) $num;
				if ( $val > 0 && $val <= 5000 ) {
					$amounts[] = $val;
				}
			}
			if ( ! empty( $amounts ) ) {
				$min = min( $amounts );
				$max = max( $amounts );
				$fmt = static function( float $v ): string {
					return ( (float) (int) $v === $v ) ? (string) (int) $v : rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
				};
				$out = ( $min === $max )
					? '$' . $fmt( $min )
					: '$' . $fmt( $min ) . '–$' . $fmt( $max );
				$olen = function_exists( 'mb_strlen' ) ? (int) mb_strlen( $out ) : (int) strlen( $out );
				if ( $olen <= 30 ) {
					return $out;
				}
				$out2 = '$' . $fmt( $max );
				$olen2 = function_exists( 'mb_strlen' ) ? (int) mb_strlen( $out2 ) : (int) strlen( $out2 );
				return ( $olen2 <= 30 ) ? $out2 : null;
			}
		}
		return null;
	}

	private function apply_place_like_props( array $node, array $details, bool $allow_hours = true, bool $allow_price = true ): array {
		$node = $this->apply_common_thing_props( $node, $details );
		$telephone = trim( (string) ( $details['telephone'] ?? $details['phone'] ?? '' ) );
		if ( $telephone !== '' ) {
			$node['telephone'] = $telephone;
		}
		$spec_raw = $details['opening_hours_spec'] ?? $details['openingHoursSpecification'] ?? null;
		$spec = ( $allow_hours ) ? $this->opening_hours_spec_node( $spec_raw ) : null;
		if ( $allow_hours && null !== $spec ) {
			$node['openingHoursSpecification'] = $spec;
		}
		$opening_raw = $details['opening_hours'] ?? $details['openingHours'] ?? null;
		if ( $allow_hours && null === $spec ) {
			$opening = $this->opening_hours_node( $opening_raw );
			if ( null !== $opening ) {
				$node['openingHours'] = $opening;
			}
		}
		$geo = $this->geo_node( $details['geo'] ?? null );
		if ( null !== $geo ) {
			$node['geo'] = $geo;
		}
		if ( isset( $details['sameAs'] ) ) {
			if ( is_string( $details['sameAs'] ) ) {
				$details['sameAs'] = array_values(
					array_unique(
						array_filter(
							array_map( 'trim', preg_split( '/\s*,\s*/', $details['sameAs'] ) )
						)
					)
				);
			}
			if ( is_array( $details['sameAs'] ) && ! empty( $details['sameAs'] ) ) {
				$node['sameAs'] = array_values( array_unique( array_filter( $details['sameAs'] ) ) );
			}
		}
		if ( $allow_price ) {
			$price_raw  = (string) ( $details['priceRange'] ?? $details['price_range'] ?? '' );
			$price_norm = self::normalize_price_range( $price_raw );
			if ( $price_norm ) {
				$node['priceRange'] = $price_norm;
			} else {
				unset( $node['priceRange'] );
			}
		} else {
			unset( $node['priceRange'] );
		}
		if ( empty( $node['image'] ) && ! empty( $details['image'] ) ) {
			$node['image'] = $details['image'];
		}
		if ( ! empty( $details['hasMap'] ) ) {
			$node['hasMap'] = $details['hasMap'];
		}
		if ( ! empty( $details['amenityFeature'] ) ) {
			$node['amenityFeature'] = $details['amenityFeature'];
		}
		return $node;
	}

	private function prune_nulls( $value ) {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$vv = $this->prune_nulls( $v );
				if ( null === $vv || ( is_string( $vv ) && '' === trim( $vv ) ) || ( is_array( $vv ) && [] === $vv ) ) {
					continue;
				}
				$out[ $k ] = $vv;
			}
			return $out;
		}
		return $value;
	}
}
