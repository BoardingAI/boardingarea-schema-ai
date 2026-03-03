<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI;

use BoardingArea\SchemaAI\Admin\Meta_Box;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Conflict_Manager {

	public function init(): void {
		// Run very late so we override any late-registered hooks.
		add_action( 'wp', [ $this, 'manage_frontend_conflicts' ], 9999 );
	}

	public function manage_frontend_conflicts(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id   = (int) get_queried_object_id();
		$our_schema = get_post_meta( $post_id, Meta_Box::META_KEY_LIVE, true );

		if ( empty( $our_schema ) ) {
			return;
		}

		// Yoast JSON-LD.
		add_filter( 'wpseo_json_ld_output', '__return_false', 9999 );

		// RankMath JSON-LD (expects array; empty array suppresses).
		add_filter( 'rank_math/json_ld', static fn( $data ) => [], 9999 );

		// AIOSEO schema disable.
		add_filter( 'aioseo_schema_disable', '__return_true', 9999 );
	}
}
