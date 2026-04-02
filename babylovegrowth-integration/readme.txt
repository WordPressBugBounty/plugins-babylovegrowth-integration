=== BabyLoveGrowth Integration ===
Contributors: tilensavnik, meetcpatel8850
Tags: rest api, headless, publishing, webhook
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure REST endpoint to publish posts from BabyLoveGrowth.ai backend via API key.

== Description ==

BabyLoveGrowth Integration adds a secure REST API endpoint to your WordPress site so BabyLoveGrowth.ai can publish or update posts remotely. It uses an API key you control in WordPress settings, and supports featured images and HTML/Markdown content.

- Improved Authorization
- Endpoints: `GET /wp-json/babylovegrowth/v1/ping`, `POST /wp-json/babylovegrowth/v1/publish`
- Accepts `title`, `slug`, `content_html` or `content_markdown`, optional `metaDescription`, `heroImageUrl`, `status`
- Sets/updates posts by slug; supports `publish`, `draft`, `pending`

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/babylovegrowth-integration` directory, or upload the ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings → BabyLoveGrowth Integration. Copy the API Key.
4. In BabyLoveGrowth, configure the Webhook Integration with the Bearer token. Endpoint base is your site URL, e.g. `https://example.com/wp-json/babylovegrowth/v1/publish`.

== Frequently Asked Questions ==

= How do I regenerate the API key? =
On the Settings → BabyLoveGrowth Integration page, click "Generate New API Key".

= What is the endpoint URL? =
`/wp-json/babylovegrowth/v1/publish` on your site. Test connectivity with `/wp-json/babylovegrowth/v1/ping`.

= What permissions are required? =
Requests are authorized with the API key only. No user login is required.

== Screenshots ==
1. How babylovegrowth works.
2. Content Plan.
3. Backlinks.

== Changelog ==

= 1.0.14 =
* Added: Alt text for hero image.

= 1.0.13 =
* Added: Default author selection in settings; published posts use the selected author (same as category).

= 1.0.12 =
* Improved: Improved Authorization

= 1.0.11 =
* Added: SEO Meta integration support for Yoast SEO, Rank Math, SEOPress, and All in One SEO
* Improved: Automatically maps meta title, description, and keywords to installed SEO plugins

= 1.0.10 =
* Improved: Redesigned admin interface with clearer two-step setup process
* Improved: Better visual separation between BLG Dashboard settings and WordPress settings
* Improved: Simplified language throughout settings page for non-technical users
* Fixed: Integration Key now properly preserved when saving other WordPress settings
* Enhanced: Added helpful section headers and instructions to guide users through setup
* Enhanced: Improved copy-to-clipboard functionality with clearer labels

= 1.0.9 =
* Fixed: YouTube/Vimeo embeds not rendering when HTML contained wrappers
* Added: Allowlist for <iframe> in post context (KSES) so embeds persist safely
* Changed: Keep KSES enabled for security; removed temporary KSES disable during save
* Note: If you programmatically send embeds, avoid wrapping a block <div> inside a <p>

= 1.0.8 =
* Added: Default Post Status setting - Choose between "Publish" or "Draft" as default status for new posts
* New: Posts can now be automatically created as drafts for review before publishing
* Improved: More flexible post publishing workflow with configurable default status
* Enhanced: Webhook requests can still override default status by providing explicit status parameter

= 1.0.7 =
* Fixed: Critical issue - JSON-LD structured data scripts displaying as plain text on posts
* Improved: JSON-LD scripts now properly injected into HTML head section for SEO
* Enhanced: Automatic extraction and storage of JSON-LD scripts separate from post content
* Performance: Cleaner post content without visible script tags

= 1.0.6 =
* Fixed: Critical issue - Empty content when publishing posts with non-English languages (de, fr, es, etc.)
* Fixed: 404 permalink errors for non-English posts with various permalink structures
* Improved: Direct database language assignment for WPML (more reliable, prevents content loss)
* Improved: Automatic content verification and restoration after language assignment
* Improved: Permalink regeneration to ensure compatibility with all permalink structures (Plain, Post name, Day and name, etc.)
* Performance: Faster language assignment by bypassing unnecessary WordPress hooks

= 1.0.5 =
* Added: WPML and Polylang multilingual plugin support
* Added: Language assignment for posts via lang parameter
* Added: Featured Image setting - Enable/disable featured image with automatic first H1 and image removal
* Fixed: Language context now properly set before post creation for correct language view visibility
* Improved: Posts now correctly appear in language-specific post views

= 1.0.4 =
* Added: Multi-select tags support in settings
* New: Automatically assign selected tags to published posts
* Improved: Enhanced taxonomy management for webhook-published posts

= 1.0.3 =
* Added: Default category selection in settings
* New: Automatically assign published posts to selected category
* Improved: Category management for webhook-published posts

= 1.0.2 =
* Fixed: JSON-LD structured data now renders properly instead of displaying as plain text
* Added: Script tag support for JSON-LD in post content

= 1.0.1 =
* Fixed: Critical error when uploading featured images via webhook
* Added: Required WordPress admin dependencies for media sideload
* Improved: Error logging for failed image uploads

= 1.0.0 =
Initial release.

== Upgrade Notice ==

= 1.0.13 =
New: Choose a default author in settings so all published posts use that author.

= 1.0.12 =
Improved Authorization

= 1.0.11 =
Added support for popular SEO plugins (Yoast, Rank Math, SEOPress, AIOSEO) to automatically sync meta titles and descriptions.

= 1.0.10 =
Improved admin interface with clearer setup instructions and better organization. Integration Key preservation fix ensures settings don't get lost when updating other options.

= 1.0.9 =
Embeds now work reliably with KSES enabled. Security preserved; iframe allowlist added. Update recommended if you use YouTube/Vimeo embeds.

= 1.0.8 =
New feature: Configure default post status (Publish or Draft) in plugin settings for better content workflow control.

= 1.0.7 =
Critical bug fix for JSON-LD structured data display. Highly recommended update for all users.

= 1.0.6 =
Critical bug fix for multi-language content and permalinks. Highly recommended update for all users using non-English languages.

= 1.0.2 =
Fix for JSON-LD structured data display. Recommended update.

= 1.0.1 =
Critical bug fix for featured image uploads. Update recommended.

= 1.0.0 =
Initial release.
