<?php
declare(strict_types=1);

namespace BoardingArea\SchemaAI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	public const OPTION_GROUP        = 'basai_settings';
	public const OPTION_KEY          = 'basai_api_key';
	public const OPTION_MODEL        = 'basai_model';
	public const OPTION_ENABLED      = 'basai_enabled';
	public const OPTION_AUTO_ON_SAVE = 'basai_auto_on_save';

	// New: WebSite node emission toggle
	public const OPTION_WEBSITE_ALL_PAGES = 'basai_website_all_pages';

	public static function get_effective_api_key(): string {
		if ( \defined( 'BASAI_OPENAI_API_KEY' ) && is_string( \constant( 'BASAI_OPENAI_API_KEY' ) ) && '' !== \constant( 'BASAI_OPENAI_API_KEY' ) ) {
			return \constant( 'BASAI_OPENAI_API_KEY' );
		}
		return (string) get_option( self::OPTION_KEY, '' );
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
	}

	public function menu(): void {
		add_options_page(
			'BoardingArea Schema AI',
			'BoardingArea Schema AI',
			'manage_options',
			'boardingarea-schema-ai',
			[ $this, 'render' ]
		);
	}

	public function register(): void {
		register_setting(self::OPTION_GROUP, self::OPTION_KEY, [
			'type' => 'string',
			'sanitize_callback' => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
			'default' => '',
		]);

		register_setting(self::OPTION_GROUP, self::OPTION_MODEL, [
			'type' => 'string',
			'sanitize_callback' => static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : 'gpt-4o',
			'default' => 'gpt-4o',
		]);

		register_setting(self::OPTION_GROUP, self::OPTION_ENABLED, [
			'type' => 'boolean',
			'sanitize_callback' => static fn( $v ) => (int) (bool) $v,
			'default' => 1,
		]);

		register_setting(self::OPTION_GROUP, self::OPTION_AUTO_ON_SAVE, [
			'type' => 'boolean',
			'sanitize_callback' => static fn( $v ) => (int) (bool) $v,
			'default' => 0,
		]);

		register_setting(self::OPTION_GROUP, self::OPTION_WEBSITE_ALL_PAGES, [
			'type' => 'boolean',
			'sanitize_callback' => static fn( $v ) => (int) (bool) $v,
			'default' => 1,
		]);

		add_settings_section( 'basai_main', 'General Settings', null, 'boardingarea-schema-ai' );

		add_settings_field( 'basai_key', 'OpenAI API Key', [ $this, 'field_key' ], 'boardingarea-schema-ai', 'basai_main' );
		add_settings_field( 'basai_model', 'Model', [ $this, 'field_model' ], 'boardingarea-schema-ai', 'basai_main' );
		add_settings_field( 'basai_enable', 'Enable Frontend Output', [ $this, 'field_enable' ], 'boardingarea-schema-ai', 'basai_main' );
		add_settings_field( 'basai_auto', 'Auto-Generate on Publish/Update', [ $this, 'field_auto' ], 'boardingarea-schema-ai', 'basai_main' );
		add_settings_field( 'basai_website_all_pages', 'Emit WebSite on all pages', [ $this, 'field_website_all_pages' ], 'boardingarea-schema-ai', 'basai_main' );

	}

	public function render(): void {
		?>
		<div class="wrap">
		<h1>BoardingArea Schema AI</h1>
		<p>Hybrid AI + manual Schema.org builder with async queue.</p>
		<?php if ( \defined( 'BASAI_OPENAI_API_KEY' ) && \constant( 'BASAI_OPENAI_API_KEY' ) ) : ?>
		<div class="notice notice-success inline">
			<p><strong>API Key source:</strong> BASAI_OPENAI_API_KEY constant (wp-config.php). The option field below is ignored.</p>
		</div>
		<?php endif; ?>
		<form action="options.php" method="post">
			<?php
			settings_fields( self::OPTION_GROUP );
			do_settings_sections( 'boardingarea-schema-ai' );
			submit_button();
			?>
		</form>
		</div>
		<?php
	}

	public function field_key(): void {
		$disabled = ( \defined( 'BASAI_OPENAI_API_KEY' ) && \constant( 'BASAI_OPENAI_API_KEY' ) ) ? 'disabled' : '';
		$val      = (string) get_option( self::OPTION_KEY, '' );
		echo '<input type="password" name="' . esc_attr( self::OPTION_KEY ) . '" value="' . esc_attr( $val ) . '" class="regular-text" ' . esc_attr( $disabled ) . ' />';
		echo '<p class="description">Tip: define <code>BASAI_OPENAI_API_KEY</code> in wp-config.php to avoid storing keys in DB.</p>';
	}

	public function field_model(): void {
		$val = (string) get_option( self::OPTION_MODEL, 'gpt-4o' );
		?>
		<select name="<?php echo esc_attr( self::OPTION_MODEL ); ?>">
			<option value="gpt-4o" <?php selected( $val, 'gpt-4o' ); ?>>gpt-4o (Recommended)</option>
			<option value="gpt-4o-mini" <?php selected( $val, 'gpt-4o-mini' ); ?>>gpt-4o-mini (Cheaper)</option>
		</select>
		<?php
	}

	public function field_enable(): void {
		$val = (int) get_option( self::OPTION_ENABLED, 1 );
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_ENABLED ) . '" value="1" ' . checked( 1, $val, false ) . ' /> Output JSON-LD on the frontend</label>';
	}

	public function field_auto(): void {
		$val = (int) get_option( self::OPTION_AUTO_ON_SAVE, 0 );
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_AUTO_ON_SAVE ) . '" value="1" ' . checked( 1, $val, false ) . ' /> Auto-generate on publish/update</label>';
	}

	public function field_website_all_pages(): void {
		$val = (int) get_option( self::OPTION_WEBSITE_ALL_PAGES, 1 );
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_WEBSITE_ALL_PAGES ) . '" value="1" ' . checked( 1, $val, false ) . ' /> Emit WebSite node on all URLs (otherwise homepage only)</label>';
	}
}
