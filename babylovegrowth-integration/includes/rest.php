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
		'permission_callback' => '__return_true',
	]);
});


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
	$incoming = babylovegrowth_get_api_key($request);
	if (empty($incoming)) {
		return new WP_REST_Response(['success' => false, 'error' => 'missing_api_key'], 401);
	}
	$stored = get_option('babylovegrowth_api_key', '');
	if (!$stored || !hash_equals($stored, $incoming)) {
		return new WP_REST_Response(['success' => false, 'error' => 'invalid_token'], 403);
	}

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

	if ($post_id) {
		$post_data['ID'] = $post_id;
		$result = wp_update_post($post_data, true);
		if (is_wp_error($result)) {
			return new WP_REST_Response(['success' => false, 'error' => $result->get_error_message()], 500);
		}
		$post_id = (int) $result;
	} else {
		$result = wp_insert_post($post_data, true);
		if (is_wp_error($result)) {
			return new WP_REST_Response(['success' => false, 'error' => $result->get_error_message()], 500);
		}
		$post_id = (int) $result;
	}

	// Remove the temporary filter after save
	remove_filter('wp_kses_allowed_html', $allow_html, 10);

// KSES remains enabled; iframe allowlist takes care of embeds

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

	if ($hero) {
		$attachment_id = babylovegrowth_sideload_featured_image($hero, $post_id);
		if (!is_wp_error($attachment_id) && $attachment_id) {
			set_post_thumbnail($post_id, $attachment_id);
			if ($hero_alt !== '') {
				update_post_meta($attachment_id, '_wp_attachment_image_alt', $hero_alt);
			}
		}
	}

	// Save JSON-LD scripts as post meta
	if (!empty($jsonld_scripts)) {
		update_post_meta($post_id, '_babylovegrowth_jsonld', $jsonld_scripts);
	} else {
		delete_post_meta($post_id, '_babylovegrowth_jsonld');
	}

	// Update SEO Meta for 3rd party plugins (Yoast, SEOPress, Rank Math, AIOSEO)
	babylovegrowth_update_seo_meta($post_id, $title, $meta, $keywords);

	$link = get_permalink($post_id);
	return new WP_REST_Response([
		'success' => true,
		'post_id' => $post_id,
		'link' => $link ?: null,
	], 200);
}

function babylovegrowth_find_post_id_by_slug($slug) {
	$post = get_page_by_path($slug, OBJECT, 'post');
	return $post ? (int) $post->ID : 0;
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

function babylovegrowth_extract_jsonld_scripts(&$content) {
	$scripts = [];
	
	// Match all script tags with type="application/ld+json"
	if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $content, $matches)) {
		$scripts = $matches[0]; // Store full script tags
		// Remove scripts from content
		$content = preg_replace('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is', '', $content);
	}
	
	return $scripts;
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

function babylovegrowth_sideload_featured_image($url, $post_id) {
	// Load all required WordPress admin dependencies for media_sideload_image
	if (!function_exists('media_sideload_image')) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
	
	$att_id = media_sideload_image($url, $post_id, null, 'id');
	// Log errors but don't break the response
	if (is_wp_error($att_id)) {
		error_log('BabyLoveGrowth: Featured image upload failed - ' . $att_id->get_error_message());
		return 0;
	}
	
	return $att_id ?: 0;
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
	
	if (!empty($jsonld_scripts) && is_array($jsonld_scripts)) {
		echo "\n<!-- BabyLoveGrowth JSON-LD -->\n";
		foreach ($jsonld_scripts as $script) {
			echo $script . "\n";
		}
		echo "<!-- /BabyLoveGrowth JSON-LD -->\n";
	}
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
