<?php
/**
 * Plugin Name:       BoardingArea Schema AI 02/18
 * Plugin URI:        https://boardingarea.com
 * Description:       Enterprise-grade Schema.org generator for travel publishers. Hybrid AI/Manual templates, async queue, conflict silencing, and BoardingArea-specific builders.
 * Version:           1.0.0
 * Author:            BoardingArea Dev Team
 * Text Domain:       boardingarea-schema-ai
 * Requires PHP:      8.1
 */

declare(strict_types=1);

namespace BoardingArea\SchemaAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BASAI_VERSION', '1.0.0' );
define( 'BASAI_PATH', plugin_dir_path( __FILE__ ) );
define( 'BASAI_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix   = 'BoardingArea\\SchemaAI\\';
		$base_dir = BASAI_PATH . 'includes/';

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}

		$relative_class = substr( $class_name, $len );
		$parts          = explode( '\\', $relative_class );

		$file_name = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$dir_path  = implode( '/', $parts );

		$file = $base_dir . ( $dir_path ? $dir_path . '/' : '' ) . $file_name;

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Load Core class immediately (required for activation hooks)
require_once BASAI_PATH . 'includes/class-core.php';

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( Core::class ) ) {
			Core::get_instance();
		}
	}
);

register_activation_hook( __FILE__, [ Core::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Core::class, 'deactivate' ] );