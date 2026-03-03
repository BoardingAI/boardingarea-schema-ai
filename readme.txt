=== SchemaMind AI ===
Contributors: boardingarea
Tags: schema, json-ld, seo, openai, ai
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.5.0
License: GPLv2 or later

Intelligent Schema.org JSON-LD generation for travel bloggers using OpenAI (GPT-4o).

== Description ==

SchemaMind AI automates the creation of complex JSON-LD structured data for travel, aviation, and credit card content. It uses OpenAI's GPT-4o model to analyze post content and determine if it is a Flight Review, Hotel Review, Credit Card Guide, or News Article.

**Features:**
* **AI-Powered Analysis:** Automatically detects content type and generates specific Schema.
* **Editor Integration:** "Generate with AI" button directly in the post editor.
* **Conflict Manager:** Automatically suppresses RankMath or Yoast JSON-LD ONLY on posts where custom AI schema is active (preserves SEO Meta Tags).
* **Batch Processing:** Bulk update thousands of posts with a client-side queue system to prevent timeouts.
* **Safe Mode:** "Test in Google" button and Frontend Output toggle for safe testing.
* **Validation Locking:** Prevents invalid JSON from breaking your site by saving errors as drafts.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/schema-mind-ai` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Settings > SchemaMind AI and enter your OpenAI API Key.

== Changelog ==

= 1.5.0 =
* New: "Test in Google" button in the editor.
* Update: Conflict Manager now uses precision filters to ensure Meta Tags remain active while disabling JSON-LD.
* Update: Verified GPT-4o integration.

= 1.4.0 =
* New: Conflict Manager for Yoast/RankMath.
* Fix: Magic Quotes handling.
* Fix: Cost control for short posts.

= 1.3.0 =
* New: Centralized Safe-Save architecture.
* New: Frontend Master Switch.
* Update: Smart JSON extraction.