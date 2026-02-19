# BoardingArea Schema AI 02/18

**Enterprise-grade Schema.org generator for travel publishers.**

This plugin provides a hybrid AI/Manual schema generation system tailored for travel blogs. It features an asynchronous queue for processing, conflict silencing for other SEO plugins, and strict server-side validation against Schema.org standards.

## Key Features

- **Hybrid AI/Manual Generation**: Utilizes OpenAI (GPT-4o or GPT-4o-mini) to generate high-quality schema markup, with manual overrides available via meta boxes.
- **Async Queue System**: Uses a custom database table (`wp_basai_jobs`) and WP-Cron to process schema generation in the background, ensuring site performance is not impacted. Includes locking and content hashing to avoid redundant processing.
- **Conflict Silencing**: Automatically disables schema output from Yoast SEO, RankMath, and All in One SEO (AIOSEO) when custom schema is present, preventing duplicate or conflicting markup.
- **Strict Validation**: Enforces rigorous validation rules (see `docs/validation-criteria.md`) to ensure all generated schema meets required and recommended fields for types like `BlogPosting`, `Review`, `Trip`, `Airline`, and more.
- **Batch Processing**: Includes an admin tool to queue schema generation for missing posts or bulk-regenerate for all published content.
- **BoardingArea Specifics**: Tailored builders and logic for the BoardingArea network.

## Requirements

- **PHP**: 8.1 or higher
- **WordPress**: 6.0 or higher
- **OpenAI API Key**: A valid API key is required for AI generation features.

## Installation

1. Upload the `boardingarea-schema-ai-02-18` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

## Configuration

Navigate to **Settings > BoardingArea Schema AI** to configure the plugin:

### 1. OpenAI API Key
You can enter your API key directly in the settings field. However, it is **highly recommended** to define it in your `wp-config.php` file for better security:

```php
define( 'BASAI_OPENAI_API_KEY', 'sk-...' );
```

If the constant is defined, the settings field will be disabled and the constant value will be used.

### 2. Model Selection
Choose between:
- **gpt-4o (Recommended)**: Best quality and reasoning capabilities.
- **gpt-4o-mini**: Lower cost, faster, but slightly less capable.

### 3. General Settings
- **Enable Frontend Output**: Toggles the injection of JSON-LD schema into the frontend.
- **Auto-Generate on Publish/Update**: Automatically queues a generation job when a post is published or updated.
- **Emit WebSite on all pages**: Controls whether the `WebSite` node is output on all pages or just the homepage.

## Usage

### Post Editor (Meta Box)
On any Post or Page, look for the "BoardingArea Schema AI" meta box.
- **Status**: Shows if a schema is Live, Draft, or if there was an Error.
- **Generate**: Manually trigger a generation job.
- **View/Edit**: View the generated JSON-LD and make manual adjustments if necessary.
- **Validate**: Check the current schema against the validation rules.

### Batch Processing
Navigate to **Settings > Schema AI Batch**.
- **Queue Missing Only**: Queues generation jobs for all published posts that do not currently have a Live schema.
- **Queue ALL Published Posts**: Queues generation jobs for every published post (useful for regenerating after a plugin update or prompt change).
- **Run Worker Now**: Manually triggers the queue worker to process pending jobs immediately (bypassing the cron schedule).

## Developer Notes

### Architecture
- **Core (`class-core.php`)**: Initializes the plugin, handles activation/deactivation, and manages the main dependency injection container.
- **Scheduler (`class-scheduler.php`)**: Manages the async job queue.
    - **Table**: `wp_basai_jobs`
    - **Cron Hook**: `basai_run_queue` (runs every ~2 minutes)
    - **Locking**: Uses transients to prevent concurrent queue execution.
- **Conflict Manager (`class-conflict-manager.php`)**: Hooks late (`9999` priority) to unhook or filter out schema from other SEO plugins.
- **Validation (`Validation/Schema_Validator.php`)**: Centralized validation logic. See `docs/validation-criteria.md` for specific rules per schema type.

### Extending
The plugin uses a namespace of `BoardingArea\SchemaAI`. Autoloading is handled in the main plugin file.
