<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI;

use BoardingArea\SchemaAI\Admin\Settings;
use BoardingArea\SchemaAI\Admin\Meta_Box;
use BoardingArea\SchemaAI\Admin\Batch_Processor;
use BoardingArea\SchemaAI\Frontend\Schema_Output;
use BoardingArea\SchemaAI\Validation\Schema_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Core {

	private static ?Core $instance = null;

	public static function get_instance(): Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Always: queue + conflict management.
		( new Scheduler() )->init();
		( new Conflict_Manager() )->init();

		if ( is_admin() ) {
			( new Settings() )->init();
			( new Meta_Box() )->init();
			( new Batch_Processor() )->init();
		} else {
			( new Schema_Output() )->init();
		}
	}

	/**
	 * Save schema JSON-LD. If invalid JSON, store in draft meta and keep LIVE untouched.
	 * Non-destructive by design.
	 */
	public static function save_schema(
		int $post_id,
		string $json_content,
		string $template_id = '',
		string $ai_justification = '',
		string $ai_summary = '',
		array $missing_info = []
	): bool {
		$json_content = trim( $json_content );

		if ( '' === $json_content ) {
			// Explicitly clearing schema.
			delete_post_meta( $post_id, Meta_Box::META_KEY_LIVE );
			delete_post_meta( $post_id, Meta_Box::META_KEY_DRAFT );
			delete_post_meta( $post_id, Meta_Box::META_KEY_JUSTIFICATION );
			delete_post_meta( $post_id, Meta_Box::META_KEY_TEMPLATE_ID );
			delete_post_meta( $post_id, Meta_Box::META_KEY_SUMMARY );
			delete_post_meta( $post_id, Meta_Box::META_KEY_LAST_ERROR );
			delete_post_meta( $post_id, Meta_Box::META_KEY_GENERATED_AT );
			delete_post_meta( $post_id, Meta_Box::META_KEY_VALIDATION );
			delete_post_meta( $post_id, Meta_Box::META_KEY_MISSING_INFO );
			return true;
		}

		$decoded = json_decode( $json_content, true );
		$is_ok   = ( JSON_ERROR_NONE === json_last_error() ) && is_array( $decoded );

		if ( $is_ok ) {
			$validation = Schema_Validator::validate_schema_array(
				$decoded,
				[
					'site_url' => home_url( '/' ),
				]
			);
			$validation_json = wp_json_encode( $validation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			update_post_meta( $post_id, Meta_Box::META_KEY_VALIDATION, wp_slash( (string) $validation_json ) );

			if ( ! empty( $validation['errors'] ) ) {
				update_post_meta( $post_id, Meta_Box::META_KEY_DRAFT, wp_slash( $json_content ) );
				update_post_meta(
					$post_id,
					Meta_Box::META_KEY_LAST_ERROR,
					'Schema validation failed: ' . sanitize_text_field( (string) ( $validation['summary'] ?? 'Unknown errors.' ) )
				);
				return false;
			}

			update_post_meta( $post_id, Meta_Box::META_KEY_LIVE, wp_slash( $json_content ) );
			delete_post_meta( $post_id, Meta_Box::META_KEY_DRAFT );
			delete_post_meta( $post_id, Meta_Box::META_KEY_LAST_ERROR );
			update_post_meta( $post_id, Meta_Box::META_KEY_GENERATED_AT, time() );

			if ( '' !== $template_id ) {
				update_post_meta( $post_id, Meta_Box::META_KEY_TEMPLATE_ID, sanitize_text_field( $template_id ) );
			}
			if ( '' !== $ai_justification ) {
				update_post_meta( $post_id, Meta_Box::META_KEY_JUSTIFICATION, sanitize_text_field( $ai_justification ) );
			}
			if ( '' !== $ai_summary ) {
				update_post_meta( $post_id, Meta_Box::META_KEY_SUMMARY, sanitize_text_field( $ai_summary ) );
			}
			if ( ! empty( $missing_info ) ) {
				$clean_missing = array_map( 'sanitize_text_field', $missing_info );
				$encoded = wp_json_encode( $clean_missing );
				if ( false !== $encoded ) {
					update_post_meta( $post_id, Meta_Box::META_KEY_MISSING_INFO, $encoded );
				}
			} else {
				delete_post_meta( $post_id, Meta_Box::META_KEY_MISSING_INFO );
			}

			return true;
		}

		// Draft mode: store what user/AI produced for debugging, but DO NOT output.
		update_post_meta( $post_id, Meta_Box::META_KEY_DRAFT, wp_slash( $json_content ) );
		delete_post_meta( $post_id, Meta_Box::META_KEY_VALIDATION );
		delete_post_meta( $post_id, Meta_Box::META_KEY_MISSING_INFO );
		update_post_meta( $post_id, Meta_Box::META_KEY_LAST_ERROR, 'Invalid JSON: ' . sanitize_text_field( json_last_error_msg() ) );

		return false;
	}

	public static function activate(): void {
		( new Scheduler() )->activate();
	}

	public static function deactivate(): void {
		( new Scheduler() )->deactivate();
	}
}
