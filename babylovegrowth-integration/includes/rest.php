<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
	register_rest_route('babylovegrowth/v1', '/ping', [
		'methods'  => 'GET',
		'callback' => function () { return new WP_REST_Response(['ok' => true], 200); },
		'permission_callback' => '__return_true',
	]);

	register_rest_route('babylovegrowth/v1', '/publish', [
		'methods'  => 'POST',
		'callback' => 'babylovegrowth_handle_publish',
		'permission_callback' => 'babylovegrowth_verify_api_key_permission',
	]);
});

/**
 * Authorize the publish endpoint against the site's Integration Key.
 *
 * This runs before the handler, so an unauthorized request never reaches any
 * post-writing code.
 */
function babylovegrowth_verify_api_key_permission(WP_REST_Request $request) {
	$incoming = babylovegrowth_get_api_key($request);

	// The key is checked before the rate limiter, so a correct key always gets
	// through. The limiter is there to slow down wrong keys; it must never lock
	// out a site that has just pasted the right one after rotating, which is
	// precisely the moment publishing needs to resume.
	if ($incoming !== '' && babylovegrowth_verify_api_key($incoming)) {
		babylovegrowth_clear_auth_failures();
		return true;
	}

	// Blocked callers are turned away without touching the database — otherwise a
	// flood of bad requests would cost a write each, which is what the limiter
	// exists to prevent.
	if (babylovegrowth_auth_is_throttled()) {
		return new WP_Error(
			'rest_forbidden',
			__('Too many failed attempts. Try again later.', 'babylovegrowth-integration'),
			['status' => 429]
		);
	}

	$missing = ($incoming === '');

	babylovegrowth_log_event($missing ? 'missing_key' : 'invalid_key');
	babylovegrowth_record_auth_failure();

	return new WP_Error(
		'rest_forbidden',
		$missing
			? __('Missing API key.', 'babylovegrowth-integration')
			: __('Invalid API key.', 'babylovegrowth-integration'),
		['status' => $missing ? 401 : 403]
	);
}

function babylovegrowth_get_api_key(WP_REST_Request $request) {
	$api_key = $request->get_header('X-API-Key');
	if (!empty($api_key)) {
		return sanitize_text_field($api_key);
	}
	
	$auth = $request->get_header('authorization') ?: '';
	if (!empty($auth) && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
		return trim($m[1]);
	}
	
	return '';
}

function babylovegrowth_handle_publish(WP_REST_Request $request) {
	// API key is already validated in permission_callback.

	// Image downloads can outlive the caller's HTTP timeout - finish the post anyway.
	ignore_user_abort(true);
	@set_time_limit(300);

	$body = (array) $request->get_json_params();
	$title = sanitize_text_field($body['title'] ?? '');
	$slug = sanitize_title($body['slug'] ?? '');
	$meta = sanitize_text_field($body['metaDescription'] ?? '');
	$keywords = sanitize_text_field($body['keywords'] ?? '');
	$content_html = $body['content_html'] ?? '';
	$content_md = $body['content_markdown'] ?? '';
	$hero = esc_url_raw($body['heroImageUrl'] ?? '');
	$hero_alt = sanitize_text_field($body['heroImageAlt'] ?? '');
	$video_url = esc_url_raw($body['videoUrl'] ?? '');
	$video_poster = esc_url_raw($body['videoPoster'] ?? '');
	// Determine desired status: request-provided takes precedence; otherwise use plugin default
	$default_status = get_option('babylovegrowth_post_status', 'publish');
	$default_status = in_array($default_status, ['publish', 'draft'], true) ? $default_status : 'publish';
	$status = sanitize_key($body['status'] ?? $default_status);
	$lang = sanitize_text_field($body['lang'] ?? '');

	if (!$title || !$slug || (!$content_html && !$content_md)) {
		babylovegrowth_log_event('invalid_payload', ['slug' => $slug]);
		return new WP_REST_Response(['success' => false, 'error' => 'invalid_payload'], 400);
	}

	// Build content from provided HTML/Markdown (HTML preferred)
	$content = $content_html ?: $content_md;

	// Extract and store JSON-LD scripts separately to prevent them from showing in content
	$jsonld_scripts = babylovegrowth_extract_jsonld_scripts($content);

	// Remove first H1 and first image if plugin setting is enabled AND hero image is provided
	$plugin_feature_enabled = get_option('babylovegrowth_feature_image_enabled', true);
	if ($plugin_feature_enabled && $hero) {
		$content = babylovegrowth_remove_first_h1($content);
		$content = babylovegrowth_remove_first_image($content);
	}

	$post_id = babylovegrowth_find_post_id_by_slug($slug);
	$author_id = (int) get_option('babylovegrowth_author', 0);
	if (!$author_id || !get_userdata($author_id)) {
		$author_id = get_current_user_id() ?: 1;
	}

	// Publish as the chosen author so WordPress applies that user's permissions.
	// The request has no WordPress login of its own, so without this it saves as
	// nobody and WordPress strips out forms, scripts and most styling.
	$full_html = babylovegrowth_full_html_status($author_id);
	if ($full_html === 'on') {
		wp_set_current_user($author_id);
	}

	$post_data = [
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_status'  => in_array($status, ['publish', 'draft', 'pending'], true) ? $status : $default_status,
		'post_type'    => 'post',
		'post_content' => $content,
		'post_excerpt' => $meta,
		'post_author'  => $author_id,
	];

	// Temporarily allow iframe and wrapper styles during post save
	$allow_html = function ($tags, $context) {
		if ($context === 'post') {
			if (!isset($tags['div'])) $tags['div'] = [];
			$tags['div']['style'] = true;
			$tags['iframe'] = [
				'src' => true,
				'width' => true,
				'height' => true,
				'frameborder' => true,
				'allow' => true,
				'allowfullscreen' => true,
				'style' => true,
				'title' => true,
			];
		}
		return $tags;
	};
add_filter('wp_kses_allowed_html', $allow_html, 10, 2);

	$was_existing = (bool) $post_id;

	if ($post_id) {
		$post_data['ID'] = $post_id;
		$result = wp_update_post($post_data, true);
		if (is_wp_error($result)) {
			babylovegrowth_log_event('error', ['post_id' => $post_id, 'slug' => $slug]);
			return new WP_REST_Response(['success' => false, 'error' => $result->get_error_message()], 500);
		}
		$post_id = (int) $result;
	} else {
		$result = wp_insert_post($post_data, true);
		if (is_wp_error($result)) {
			babylovegrowth_log_event('error', ['slug' => $slug]);
			return new WP_REST_Response(['success' => false, 'error' => $result->get_error_message()], 500);
		}
		$post_id = (int) $result;
	}

	// Remove the temporary filter after save
	remove_filter('wp_kses_allowed_html', $allow_html, 10);

// KSES remains enabled; iframe allowlist takes care of embeds

	// Featured image before the content images: it is the most visible part of the post,
	// and a time limit hit during the content imports used to leave it unset.
	if ($hero) {
		// Finishes the job in the background if this request is killed mid-import.
		wp_schedule_single_event(time() + 120, 'babylovegrowth_retry_featured_image', [$post_id, $hero, $hero_alt]);
		babylovegrowth_set_featured_image($post_id, $hero, $hero_alt);
	}

	// Import inline content images into the media library (self-host instead of hotlinking)
	$content_with_local_images = babylovegrowth_sideload_content_images($content, $post_id);
	if ($content_with_local_images !== $content) {
		$content = $content_with_local_images;
		// Second write of the content, so WordPress filters it again. The
		// wp_set_current_user() call above must still apply or this strips it.
		wp_update_post(['ID' => $post_id, 'post_content' => $content]);
	}

	// Set language AFTER post creation but BEFORE other operations
	if ($lang) {
		babylovegrowth_set_post_language($post_id, $lang, $content);
		
		// Verify content is preserved after language assignment
		$saved_after_lang = get_post($post_id);
		if ($saved_after_lang && (trim((string) $saved_after_lang->post_content) === '')) {
			// Content was cleared, restore it
			global $wpdb;
			$wpdb->update(
				$wpdb->posts,
				['post_content' => $content],
				['ID' => $post_id],
				['%s'],
				['%d']
			);
			clean_post_cache($post_id);
		}

		
		// Regenerate permalink to ensure it works with all permalink structures
		wp_update_post([
			'ID' => $post_id,
			'post_name' => $slug, // Re-assert the slug
		]);
	}

	// Assign category if configured (replaces all existing categories)
	$category_id = get_option('babylovegrowth_category', '');
	if ($category_id) {
		$category_id = (int) $category_id;
		if ($category_id > 0 && term_exists($category_id, 'category')) {
			// Remove all existing categories first, then set the new one
			wp_delete_object_term_relationships($post_id, 'category');
			wp_set_post_categories($post_id, [$category_id]);
		}
	}

	// Assign tags if configured (replaces all existing tags)
	$tag_ids = get_option('babylovegrowth_tags', []);
	if (!empty($tag_ids) && is_array($tag_ids)) {
		// Filter valid tag IDs
		$valid_tag_ids = [];
		foreach ($tag_ids as $tag_id) {
			$tag_id = (int) $tag_id;
			if ($tag_id > 0 && term_exists($tag_id, 'post_tag')) {
				$valid_tag_ids[] = $tag_id;
			}
		}
		
		if (!empty($valid_tag_ids)) {
			// Remove all existing tags first, then set the new ones
			wp_delete_object_term_relationships($post_id, 'post_tag');
			wp_set_post_tags($post_id, $valid_tag_ids);
		}
	}

	// Save JSON-LD as post meta. update_post_meta() strips one layer of backslashes,
	// so wp_slash() it first — otherwise the escaped quotes (\") break and the JSON-LD won't parse.
	if (!empty($jsonld_scripts)) {
		update_post_meta($post_id, '_babylovegrowth_jsonld', wp_slash($jsonld_scripts));
	} else {
		delete_post_meta($post_id, '_babylovegrowth_jsonld');
	}

	// Update SEO Meta for 3rd party plugins (Yoast, SEOPress, Rank Math, AIOSEO)
	babylovegrowth_update_seo_meta($post_id, $title, $meta, $keywords);

	// A successful publish proves the dashboard holds the key, so this site no
	// longer needs to keep a readable copy of it on the settings screen.
	babylovegrowth_confirm_key_delivered();

	babylovegrowth_log_event($was_existing ? 'updated' : 'published', [
		'post_id' => $post_id,
		'slug'    => $slug,
	]);

	$link = get_permalink($post_id);
	return new WP_REST_Response([
		'success' => true,
		'post_id' => $post_id,
		'link' => $link ?: null,
		// So the dashboard can warn when a form or script was stripped.
		'full_html' => $full_html,
	], 200);
}

function babylovegrowth_find_post_id_by_slug($slug) {
	$post = get_page_by_path($slug, OBJECT, 'post');
	return $post ? (int) $post->ID : 0;
}

/**
 * Can this publish keep the article's HTML exactly as sent?
 *
 * 'off'         — the setting is turned off.
 * 'unavailable' — turned on, but the chosen author cannot publish custom code.
 * 'on'          — the HTML is stored as sent.
 *
 * 'unavailable' is worth reporting: the setting looks on, yet articles still
 * arrive stripped. user_can() also covers multisite and DISALLOW_UNFILTERED_HTML.
 */
function babylovegrowth_full_html_status($author_id) {
	if (!get_option('babylovegrowth_allow_full_html', false)) {
		return 'off';
	}

	return user_can((int) $author_id, 'unfiltered_html') ? 'on' : 'unavailable';
}

function babylovegrowth_update_seo_meta($post_id, $title, $description, $keywords = '') {
	if (empty($post_id)) return;

	// Yoast SEO
	if (!empty($title)) update_post_meta($post_id, '_yoast_wpseo_title', $title);
	if (!empty($description)) update_post_meta($post_id, '_yoast_wpseo_metadesc', $description);
	if (!empty($keywords)) update_post_meta($post_id, '_yoast_wpseo_focuskw', $keywords);

	// SEOPress
	if (!empty($title)) update_post_meta($post_id, '_seopress_titles_title', $title);
	if (!empty($description)) update_post_meta($post_id, '_seopress_titles_desc', $description);
	if (!empty($keywords)) update_post_meta($post_id, '_seopress_analysis_target_kw', $keywords);

	// Rank Math
	if (!empty($title)) update_post_meta($post_id, 'rank_math_title', $title);
	if (!empty($description)) update_post_meta($post_id, 'rank_math_description', $description);
	if (!empty($keywords)) update_post_meta($post_id, 'rank_math_focus_keyword', $keywords);

	// All in One SEO (AIOSEO)
	if (!empty($title)) update_post_meta($post_id, '_aioseop_title', $title);
	if (!empty($description)) update_post_meta($post_id, '_aioseop_description', $description);
	if (!empty($keywords)) update_post_meta($post_id, '_aioseop_keywords', $keywords);
}

function babylovegrowth_set_post_language($post_id, $lang, $content = '') {
	if (!$post_id || !$lang) {
		return false;
	}

	$post_type = get_post_type($post_id);
	if (!$post_type) {
		return false;
	}

	// WPML - Direct database assignment to avoid hooks interference
	if (defined('ICL_SITEPRESS_VERSION')) {
		global $wpdb;
		
		$element_type = 'post_' . $post_type;
		
		// Check if translation entry exists
		$existing_entry = $wpdb->get_row($wpdb->prepare(
			"SELECT trid, language_code FROM {$wpdb->prefix}icl_translations 
			WHERE element_id = %d AND element_type = %s",
			$post_id,
			$element_type
		));
		
		if ($existing_entry) {
			// Update existing entry
			$wpdb->update(
				$wpdb->prefix . 'icl_translations',
				[
					'language_code' => $lang,
					'source_language_code' => null,
				],
				[
					'element_id' => $post_id,
					'element_type' => $element_type,
				],
				['%s', '%s'],
				['%d', '%s']
			);
		} else {
			// Create new translation entry
			// Get or create a translation group (trid)
			$trid = $wpdb->get_var("SELECT MAX(trid) FROM {$wpdb->prefix}icl_translations") + 1;
			
			$wpdb->insert(
				$wpdb->prefix . 'icl_translations',
				[
					'element_type' => $element_type,
					'element_id' => $post_id,
					'trid' => $trid,
					'language_code' => $lang,
					'source_language_code' => null,
				],
				['%s', '%d', '%d', '%s', '%s']
			);
		}
		
		// Clear WPML cache
		if (function_exists('wpml_clear_cache')) {
			wpml_clear_cache();
		}
		
		return true;
	}

	// Polylang - Use native function (it's reliable)
	if (function_exists('pll_set_post_language')) {
		pll_set_post_language($post_id, $lang);
		return true;
	}

	return false;
}

/**
 * Pull the JSON-LD blocks out of the content.
 *
 * Only the JSON payload is kept, never the surrounding <script> tag, so no markup
 * from an incoming article can survive to be printed back out later.
 */
function babylovegrowth_extract_jsonld_scripts(&$content) {
	$blocks = [];

	// Match all script tags with type="application/ld+json"
	if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $content, $matches)) {
		foreach ($matches[1] as $json) {
			$json = trim($json);
			if ($json !== '') {
				$blocks[] = $json;
			}
		}
		// Remove scripts from content
		$content = preg_replace('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is', '', $content);
	}

	return $blocks;
}

/**
 * Turn one stored JSON-LD block into markup that is safe to print.
 *
 * The block is only ever emitted after a successful json_decode, and it is
 * re-encoded from the decoded value rather than echoed back verbatim. Anything
 * that is not valid structured data returns '' and is dropped — including markup
 * saved by an earlier version of this plugin, which stored whole <script> tags.
 */
function babylovegrowth_render_jsonld($block) {
	if (!is_string($block)) {
		return '';
	}

	// Blocks saved before 1.0.22 are full <script> tags; keep only the JSON inside.
	// Anchored and greedy on purpose: a "<script" sitting inside a JSON string value
	// must not be mistaken for the wrapper and truncate otherwise-valid data.
	if (preg_match('/^\s*<script[^>]*>(.*)<\/script>\s*$/is', $block, $m)) {
		$block = $m[1];
	}

	$data = json_decode(trim($block), true);
	if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
		return '';
	}

	// JSON_HEX_TAG escapes < and > as < / >, so the payload can never close
	// the script tag it sits in. Search engines read the escapes as normal JSON.
	$json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
	if ($json === false) {
		return '';
	}

	return '<script type="application/ld+json">' . $json . '</script>';
}

function babylovegrowth_normalize_html_for_wp($html) {
	// Unwrap block-level <div> mistakenly nested inside <p>
	$html = preg_replace('/<p>\s*(<div[^>]*>.*?<\/div>)\s*<\/p>/is', '$1', $html);

	// Remove stray closing </a> that can follow wrappers (commonly seen in incoming payloads)
	$html = preg_replace('/<\/a>(\s*<\/(p|div)>)/i', '$1', $html);

	// Balance any remaining unclosed/mismatched tags to avoid KSES stripping
	if (function_exists('balanceTags')) {
		$html = balanceTags($html, true);
	}

	return $html;
}

function babylovegrowth_remove_first_h1($content) {
	return preg_replace('/<h1[^>]*>.*?<\/h1>/i', '', $content, 1);
}

function babylovegrowth_remove_first_image($content) {
	return preg_replace('/<img[^>]*>/', '', $content, 1);
}

/** Import the hero image and make it the post's featured image. False if the import failed. */
function babylovegrowth_set_featured_image($post_id, $url, $alt = '') {
	if (!$post_id || empty($url)) {
		return false;
	}

	$attachment_id = babylovegrowth_import_remote_image($url, $post_id);
	if (!$attachment_id) {
		return false;
	}

	set_post_thumbnail($post_id, $attachment_id);
	if ($alt !== '') {
		update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
	}

	return true;
}

add_action('babylovegrowth_retry_featured_image', 'babylovegrowth_ensure_featured_image', 10, 3);

/** Background retry for a publish that was cut short before the featured image was set. */
function babylovegrowth_ensure_featured_image($post_id, $url, $alt = '') {
	if (!get_post($post_id) || has_post_thumbnail($post_id)) {
		return;
	}

	if (!babylovegrowth_set_featured_image($post_id, $url, $alt)) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Production logging for a background retry that cannot surface an error to the caller.
		error_log('BabyLoveGrowth: featured image retry failed for post ' . $post_id . ' (' . $url . ')');
	}
}

/**
 * Download a remote image into the media library and return its attachment ID.
 * If we already imported this URL before, reuse that attachment instead of
 * downloading it again, so re-publishing doesn't create duplicate media.
 * Returns 0 on failure without breaking the publish.
 */
function babylovegrowth_import_remote_image($url, $post_id = 0) {
	if (empty($url)) {
		return 0;
	}

	// Reuse the attachment already imported for this URL (avoids duplicates when re-publishing).
	$existing = get_posts([
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_babylovegrowth_source_url',
		'meta_value'  => $url,
	]);
	if (!empty($existing)) {
		return (int) $existing[0];
	}

	// Load all required WordPress admin dependencies for media_sideload_image.
	if (!function_exists('media_sideload_image')) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$att_id = media_sideload_image($url, $post_id, null, 'id');
	// Log errors but don't break the response.
	if (is_wp_error($att_id) || !$att_id) {
		error_log('BabyLoveGrowth: image import failed - ' . (is_wp_error($att_id) ? $att_id->get_error_message() : 'unknown error') . ' (' . $url . ')');
		return 0;
	}

	update_post_meta($att_id, '_babylovegrowth_source_url', $url);
	return (int) $att_id;
}

/**
 * Copy the post's inline images into the media library and rewrite each <img src>
 * to the local URL, so the article serves images from the user's own domain
 * instead of hotlinking ours. If one image fails, we keep its original URL so
 * the post is never broken.
 */
function babylovegrowth_sideload_content_images($content, $post_id = 0) {
	if (empty($content) || strpos($content, '<img') === false) {
		return $content;
	}

	preg_match_all('/<img[^>]+src=(["\'])(.*?)\1/i', $content, $matches);

	$site_host = wp_parse_url(home_url(), PHP_URL_HOST);
	$replacements = [];

	// Download at most this many images per request. Each is a slow download, so a
	// huge article could hit PHP's time limit; extra images just stay hotlinked.
	$max_imports = (int) apply_filters('babylovegrowth_max_content_images', 12);
	$imported = 0;

	foreach ($matches[2] as $raw_src) {
		$raw_src = trim($raw_src);
		if ($raw_src === '' || isset($replacements[$raw_src])) {
			continue;
		}
		// Decode HTML entities (e.g. &amp; in the URL) so the download gets a valid URL.
		// We keep $raw_src as-is for the text replacement below.
		$url = html_entity_decode($raw_src, ENT_QUOTES);

		// Only import remote http(s) images hosted elsewhere.
		if (!preg_match('#^https?://#i', $url)) {
			continue;
		}
		$src_host = wp_parse_url($url, PHP_URL_HOST);
		if ($src_host && $site_host && strcasecmp($src_host, $site_host) === 0) {
			continue; // Already local.
		}

		if ($imported >= $max_imports) {
			error_log('BabyLoveGrowth: content image import cap (' . $max_imports . ') reached for post ' . $post_id . '; remaining images left hotlinked.');
			break;
		}
		$imported++;

		$att_id = babylovegrowth_import_remote_image($url, $post_id);
		if ($att_id) {
			$local_url = wp_get_attachment_url($att_id);
			if ($local_url) {
				$replacements[$raw_src] = $local_url;
			}
		}
	}

	// Replace longest URLs first, so a short URL that is a prefix of a longer one
	// (e.g. img.png vs img.png?v=2) can't corrupt the longer match.
	uksort($replacements, function ($a, $b) {
		return strlen($b) - strlen($a);
	});
	foreach ($replacements as $old => $new) {
		$content = str_replace($old, $new, $content);
	}

	return $content;
}

function babylovegrowth_build_video_markup($url, $poster = '') {
	// If it's a direct media file, use a core video block
	if (preg_match('/\.(mp4|webm|ogg)(\?.*)?$/i', $url)) {
		$poster_attr = $poster ? ' poster="' . esc_url($poster) . '"' : '';
		return '<!-- wp:video -->\n'
			. '<figure class="wp-block-video"><video controls src="' . esc_url($url) . '"' . $poster_attr . '></video></figure>'
			. '\n<!-- /wp:video -->';
	}

	// If it's a known oEmbed provider, return bare URL in a wrapper (optional)
	if (preg_match('/(youtube\.com|youtu\.be|vimeo\.com)/i', $url)) {
		// Bare URL is enough for oEmbed; WordPress will auto-embed on render
		return $url;
	}

	// Unknown provider: still return URL (oEmbed may handle it if supported)
	return $url;
}

add_action('wp_head', function() {
	if (!is_singular('post')) {
		return;
	}
	
	$post_id = get_the_ID();
	$jsonld_scripts = get_post_meta($post_id, '_babylovegrowth_jsonld', true);

	if (empty($jsonld_scripts) || !is_array($jsonld_scripts)) {
		return;
	}

	$markup = '';
	foreach ($jsonld_scripts as $script) {
		$rendered = babylovegrowth_render_jsonld($script);
		if ($rendered !== '') {
			$markup .= $rendered . "\n";
		}
	}

	if ($markup === '') {
		return;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- babylovegrowth_render_jsonld() only ever returns machine-encoded JSON wrapped in a script tag we build ourselves.
	echo "\n<!-- BabyLoveGrowth JSON-LD -->\n" . $markup . "<!-- /BabyLoveGrowth JSON-LD -->\n";
}, 10);


// Always allow iframes in post context (save and render) so embeds persist
add_filter('wp_kses_allowed_html', function($tags, $context) {
	if ($context === 'post') {
		if (!isset($tags['div'])) $tags['div'] = [];
		$tags['div']['style'] = true;
		$tags['iframe'] = array_merge($tags['iframe'] ?? [], [
			'src' => true,
			'width' => true,
			'height' => true,
			'frameborder' => true,
			'allow' => true,
			'allowfullscreen' => true,
			'style' => true,
			'title' => true,
			'loading' => true,
			'referrerpolicy' => true,
			'sandbox' => true,
		]);
	}
	return $tags;
}, 10, 2);


/**
 * Force SEO plugins to rebuild their cached sitemap whenever a post is published.
 *
 * Posts created programmatically — via the WordPress REST API, this plugin's
 * publish endpoint, or a scheduled publish — do not reliably invalidate Rank
 * Math's sitemap cache, so the sitemap can stay frozen at an old set of URLs
 * until a post is manually re-saved. Invalidating here makes new posts appear
 * in the sitemap automatically.
 */
add_action('transition_post_status', 'babylovegrowth_invalidate_seo_sitemap_cache', 20, 3);

function babylovegrowth_invalidate_seo_sitemap_cache($new_status, $old_status, $post) {
	if ($new_status !== 'publish' || !($post instanceof WP_Post) || $post->post_type !== 'post') {
		return;
	}

	$cache_cleared = false;

	// Rank Math
	if (class_exists('\RankMath\Sitemap\Cache') && method_exists('\RankMath\Sitemap\Cache', 'invalidate_storage')) {
		\RankMath\Sitemap\Cache::invalidate_storage();
		$cache_cleared = true;
	}

	// Yoast SEO
	if (class_exists('WPSEO_Sitemaps_Cache') && method_exists('WPSEO_Sitemaps_Cache', 'clear')) {
		WPSEO_Sitemaps_Cache::clear();
		$cache_cleared = true;
	}

	if (!$cache_cleared) {
		error_log('BabyLoveGrowth: no supported SEO sitemap cache found to invalidate for post ' . $post->ID);
	}
}
