<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI\Frontend;

use BoardingArea\SchemaAI\Admin\Meta_Box;
use BoardingArea\SchemaAI\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema_Output {

	public function init(): void {
		add_action( 'wp_head', [ $this, 'render' ], 1 );
	}

	public function render(): void {
		if ( ! get_option( Settings::OPTION_ENABLED, false ) ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		$json    = get_post_meta( $post_id, Meta_Box::META_KEY_LIVE, true );

		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return;
		}

		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return;
		}

		// ✅ Re-encode to ensure clean output (no slashes / corruption)
		$safe = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		echo "\n\n";
		echo '<script type="application/ld+json">' . $safe . '</script>';
		echo "\n\n";
	}

}
