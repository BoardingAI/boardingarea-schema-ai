<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI\Validation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema_Validator {

	public static function validate_json( string $json, array $context = [] ): array {
		$json = trim( $json );
		if ( '' === $json ) {
			return self::finalize_report( [
				'errors'   => [ [ 'message' => 'Empty JSON provided.' ] ],
				'warnings' => [],
			] );
		}

		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return self::finalize_report( [
				'errors'   => [ [ 'message' => 'Invalid JSON: ' . json_last_error_msg() ] ],
				'warnings' => [],
			] );
		}

		return self::validate_schema_array( $decoded, $context );
	}

	public static function validate_schema_array( array $schema, array $context = [] ): array {
		$report = [
			'errors'   => [],
			'warnings' => [],
		];

		$site_url  = (string) ( $context['site_url'] ?? home_url( '/' ) );
		$site_host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );

		$context_val = $schema['@context'] ?? '';
		if ( ! self::context_is_schema( $context_val ) ) {
			self::add_issue(
				$report,
				'warning',
				'@context should reference schema.org.'
			);
		}

		$nodes = self::extract_nodes( $schema );
		if ( empty( $nodes ) ) {
			self::add_issue( $report, 'error', 'No schema nodes found.' );
			return self::finalize_report( $report );
		}

		$id_map    = [];
		$id_counts = [];
		foreach ( $nodes as $entry ) {
			$node = $entry['node'];
			$id   = is_array( $node ) ? (string) ( $node['@id'] ?? '' ) : '';
			if ( '' === $id ) {
				continue;
			}
			$id_counts[ $id ] = ( $id_counts[ $id ] ?? 0 ) + 1;
			if ( ! isset( $id_map[ $id ] ) ) {
				$id_map[ $id ] = $node;
			}
		}

		foreach ( $id_counts as $id => $count ) {
			if ( $count > 1 ) {
				self::add_issue(
					$report,
					'warning',
					'Duplicate @id detected: ' . $id,
					[
						'code' => 'duplicate_id',
						'id'   => $id,
					]
				);
			}
		}

		$refs = [];
		foreach ( $nodes as $entry ) {
			self::collect_id_refs( $entry['node'], $entry['path'], $refs );
		}

		foreach ( $refs as $ref ) {
			$id = (string) ( $ref['id'] ?? '' );
			if ( '' === $id || isset( $id_map[ $id ] ) ) {
				continue;
			}
			if ( self::is_internal_id( $id, $site_url, $site_host ) ) {
				self::add_issue(
					$report,
					'error',
					'Unresolved @id reference: ' . $id,
					[
						'code' => 'unresolved_id',
						'id'   => $id,
						'path' => $ref['path'] ?? '',
					]
				);
			}
		}

		$rules = self::get_type_rules();
		foreach ( $nodes as $entry ) {
			$node = $entry['node'];
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type_val = $node['@type'] ?? null;
			$types    = is_array( $type_val ) ? $type_val : ( null !== $type_val ? [ $type_val ] : [] );
			$path     = (string) $entry['path'];
			foreach ( $types as $type ) {
				$type = (string) $type;
				if ( '' === $type ) {
					continue;
				}
				if ( isset( $rules[ $type ] ) ) {
					self::validate_node_with_rules( $node, $type, $path, $rules[ $type ], $report );
				}

				switch ( $type ) {
					case 'FAQPage':
						self::validate_faq_page( $node, $path, $report );
						break;
					case 'HowTo':
						self::validate_howto( $node, $path, $report );
						break;
					case 'ItemList':
						self::validate_item_list( $node, $path, $report );
						break;
					case 'Review':
						self::validate_review( $node, $path, $report );
						break;
					default:
						break;
				}
			}
		}

		return self::finalize_report( $report );
	}

	public static function get_type_rules(): array {
		return [
			'BlogPosting' => [
				'required'    => [ 'headline', 'datePublished', 'author', 'publisher' ],
				'recommended' => [ 'image', 'description', 'mainEntityOfPage' ],
			],
			'Article' => [
				'required'    => [ 'headline', 'datePublished', 'author', 'publisher' ],
				'recommended' => [ 'image', 'description', 'mainEntityOfPage' ],
			],
			'NewsArticle' => [
				'required'    => [ 'headline', 'datePublished', 'author', 'publisher' ],
				'recommended' => [ 'image', 'description', 'mainEntityOfPage' ],
			],
			'Review' => [
				'required'    => [ 'itemReviewed' ],
				'recommended' => [ 'reviewBody', 'reviewRating', 'author', 'datePublished' ],
			],
			'FAQPage' => [
				'required'    => [ 'mainEntity' ],
				'recommended' => [ 'inLanguage' ],
			],
			'HowTo' => [
				'required'    => [ 'step' ],
				'recommended' => [ 'image', 'totalTime' ],
			],
			'ItemList' => [
				'required'    => [ 'itemListElement' ],
				'recommended' => [ 'name', 'description' ],
			],
			'VideoObject' => [
				'required'    => [ 'name', 'thumbnailUrl', 'uploadDate' ],
				'one_of'      => [ 'contentUrl', 'embedUrl' ],
				'recommended' => [ 'description', 'duration' ],
			],
			'Product' => [
				'required'    => [ 'name' ],
				'recommended' => [ 'brand', 'image', 'description' ],
			],
			'Trip' => [
				'required'    => [ 'name' ],
				'recommended' => [ 'itinerary', 'image' ],
			],
			'Place' => [
				'required'    => [ 'name' ],
				'recommended' => [ 'address', 'geo', 'image' ],
			],
			'Airline' => [
				'required'    => [ 'name' ],
				'recommended' => [ 'iataCode', 'url' ],
			],
			'WebPage' => [
				'required' => [ 'url', 'name' ],
				'severity' => 'warning',
			],
			'WebSite' => [
				'required' => [ 'url', 'name' ],
				'severity' => 'warning',
			],
			'Organization' => [
				'required' => [ 'name', 'url' ],
				'severity' => 'warning',
			],
			'BreadcrumbList' => [
				'required' => [ 'itemListElement' ],
				'severity' => 'warning',
			],
		];
	}

	private static function validate_node_with_rules(
		array $node,
		string $type,
		string $path,
		array $rules,
		array &$report
	): void {
		$severity = (string) ( $rules['severity'] ?? 'error' );
		$required = (array) ( $rules['required'] ?? [] );
		$recommended = (array) ( $rules['recommended'] ?? [] );
		$one_of = (array) ( $rules['one_of'] ?? [] );

		foreach ( $required as $prop ) {
			$val = self::get_value_by_path( $node, (string) $prop );
			if ( self::value_is_empty( $val ) ) {
				self::add_issue(
					$report,
					$severity,
					$type . ' missing required property: ' . $prop,
					[
						'code' => 'missing_required',
						'type' => $type,
						'path' => $path . '.' . $prop,
					]
				);
			}
		}

		foreach ( $recommended as $prop ) {
			$val = self::get_value_by_path( $node, (string) $prop );
			if ( self::value_is_empty( $val ) ) {
				self::add_issue(
					$report,
					'warning',
					$type . ' missing recommended property: ' . $prop,
					[
						'code' => 'missing_recommended',
						'type' => $type,
						'path' => $path . '.' . $prop,
					]
				);
			}
		}

		if ( ! empty( $one_of ) ) {
			$found = false;
			foreach ( $one_of as $prop ) {
				$val = self::get_value_by_path( $node, (string) $prop );
				if ( ! self::value_is_empty( $val ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				self::add_issue(
					$report,
					$severity,
					$type . ' requires at least one of: ' . implode( ', ', $one_of ),
					[
						'code' => 'missing_one_of',
						'type' => $type,
						'path' => $path,
					]
				);
			}
		}
	}

	private static function validate_faq_page( array $node, string $path, array &$report ): void {
		$main = $node['mainEntity'] ?? null;
		if ( ! is_array( $main ) || empty( $main ) ) {
			self::add_issue(
				$report,
				'error',
				'FAQPage requires at least one Question in mainEntity.',
				[
					'code' => 'faq_empty',
					'type' => 'FAQPage',
					'path' => $path . '.mainEntity',
				]
			);
			return;
		}

		foreach ( $main as $idx => $question ) {
			if ( ! is_array( $question ) ) {
				continue;
			}
			$q_name = $question['name'] ?? '';
			$ans    = $question['acceptedAnswer'] ?? null;
			$ans_text = is_array( $ans ) ? ( $ans['text'] ?? '' ) : '';
			if ( self::value_is_empty( $q_name ) || self::value_is_empty( $ans_text ) ) {
				self::add_issue(
					$report,
					'error',
					'FAQPage Question must include name and acceptedAnswer.text.',
					[
						'code' => 'faq_question_invalid',
						'type' => 'FAQPage',
						'path' => $path . '.mainEntity[' . (int) $idx . ']',
					]
				);
			}
		}
	}

	private static function validate_howto( array $node, string $path, array &$report ): void {
		$steps = $node['step'] ?? null;
		if ( ! is_array( $steps ) || empty( $steps ) ) {
			self::add_issue(
				$report,
				'error',
				'HowTo requires at least one step.',
				[
					'code' => 'howto_empty',
					'type' => 'HowTo',
					'path' => $path . '.step',
				]
			);
			return;
		}
		foreach ( $steps as $idx => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$text = $step['text'] ?? '';
			if ( self::value_is_empty( $text ) ) {
				self::add_issue(
					$report,
					'warning',
					'HowTo step is missing text.',
					[
						'code' => 'howto_step_missing_text',
						'type' => 'HowTo',
						'path' => $path . '.step[' . (int) $idx . '].text',
					]
				);
			}
		}
	}

	private static function validate_item_list( array $node, string $path, array &$report ): void {
		$items = $node['itemListElement'] ?? null;
		if ( ! is_array( $items ) || empty( $items ) ) {
			self::add_issue(
				$report,
				'error',
				'ItemList requires itemListElement entries.',
				[
					'code' => 'itemlist_empty',
					'type' => 'ItemList',
					'path' => $path . '.itemListElement',
				]
			);
			return;
		}

		foreach ( $items as $idx => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$position = $item['position'] ?? null;
			$name = $item['name'] ?? '';
			$ref  = $item['item'] ?? null;
			if ( self::value_is_empty( $position ) ) {
				self::add_issue(
					$report,
					'warning',
					'ItemList entry is missing position.',
					[
						'code' => 'itemlist_missing_position',
						'type' => 'ItemList',
						'path' => $path . '.itemListElement[' . (int) $idx . '].position',
					]
				);
			}
			if ( self::value_is_empty( $name ) && self::value_is_empty( $ref ) ) {
				self::add_issue(
					$report,
					'warning',
					'ItemList entry is missing name or item reference.',
					[
						'code' => 'itemlist_missing_name',
						'type' => 'ItemList',
						'path' => $path . '.itemListElement[' . (int) $idx . ']',
					]
				);
			}
		}
	}

	private static function validate_review( array $node, string $path, array &$report ): void {
		$rating = $node['reviewRating'] ?? null;
		if ( is_array( $rating ) ) {
			$val = $rating['ratingValue'] ?? null;
			if ( self::value_is_empty( $val ) ) {
				self::add_issue(
					$report,
					'warning',
					'Review rating is missing ratingValue.',
					[
						'code' => 'review_missing_rating_value',
						'type' => 'Review',
						'path' => $path . '.reviewRating.ratingValue',
					]
				);
			}
		}
	}

	private static function extract_nodes( array $schema ): array {
		$nodes = [];
		if ( isset( $schema['@graph'] ) && is_array( $schema['@graph'] ) ) {
			foreach ( $schema['@graph'] as $idx => $node ) {
				if ( is_array( $node ) ) {
					$nodes[] = [
						'node' => $node,
						'path' => '@graph[' . (int) $idx . ']',
					];
				}
			}
			return $nodes;
		}

		if ( isset( $schema['@type'] ) ) {
			$nodes[] = [
				'node' => $schema,
				'path' => '@root',
			];
		}

		return $nodes;
	}

	private static function collect_id_refs( $value, string $path, array &$refs ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			foreach ( $value as $idx => $item ) {
				self::collect_id_refs( $item, $path . '[' . (int) $idx . ']', $refs );
			}
			return;
		}

		if ( isset( $value['@id'] ) && is_string( $value['@id'] ) ) {
			$keys = array_keys( $value );
			$other_keys = array_diff( $keys, [ '@id', '@type' ] );
			if ( empty( $other_keys ) ) {
				$refs[] = [
					'id'   => $value['@id'],
					'path' => $path . '.@id',
				];
				return;
			}
		}

		foreach ( $value as $key => $child ) {
			if ( $key === '@context' || $key === '@graph' ) {
				continue;
			}
			self::collect_id_refs( $child, $path . '.' . (string) $key, $refs );
		}
	}

	private static function context_is_schema( $context ): bool {
		if ( is_string( $context ) ) {
			return ( false !== stripos( $context, 'schema.org' ) );
		}
		if ( is_array( $context ) ) {
			foreach ( $context as $ctx ) {
				if ( is_string( $ctx ) && false !== stripos( $ctx, 'schema.org' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function is_internal_id( string $id, string $site_url, string $site_host ): bool {
		$id = trim( $id );
		if ( '' === $id ) {
			return false;
		}
		if ( str_starts_with( $id, '#' ) ) {
			return true;
		}
		$parsed = wp_parse_url( $id );
		if ( ! is_array( $parsed ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parsed['host'] ?? '' ) );
		if ( '' === $host ) {
			return false;
		}
		if ( '' !== $site_host && $host === $site_host ) {
			return true;
		}
		return ( '' !== $site_url && str_starts_with( $id, $site_url ) );
	}

	private static function get_value_by_path( array $node, string $path ) {
		$parts = explode( '.', $path );
		$cur = $node;
		foreach ( $parts as $part ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $part, $cur ) ) {
				return null;
			}
			$cur = $cur[ $part ];
		}
		return $cur;
	}

	private static function value_is_empty( $val ): bool {
		if ( null === $val ) {
			return true;
		}
		if ( is_string( $val ) ) {
			return trim( $val ) === '';
		}
		if ( is_array( $val ) ) {
			return empty( $val );
		}
		return false;
	}

	private static function add_issue( array &$report, string $severity, string $message, array $meta = [] ): void {
		$entry = array_merge( [ 'message' => $message ], $meta );
		if ( 'error' === $severity ) {
			$report['errors'][] = $entry;
		} else {
			$report['warnings'][] = $entry;
		}
	}

	private static function finalize_report( array $report ): array {
		$errors = isset( $report['errors'] ) ? (array) $report['errors'] : [];
		$warnings = isset( $report['warnings'] ) ? (array) $report['warnings'] : [];
		$report['errors'] = $errors;
		$report['warnings'] = $warnings;
		$report['counts'] = [
			'errors'   => count( $errors ),
			'warnings' => count( $warnings ),
		];
		$report['summary'] = count( $errors ) . ' errors, ' . count( $warnings ) . ' warnings';
		return $report;
	}
}
