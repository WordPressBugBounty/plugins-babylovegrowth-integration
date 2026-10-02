<?php
if (!defined('ABSPATH')) exit;

/**
 * Lets the dashboard edit a post's content in place: read the raw content, then save
 * only that field. Title, status, author, categories, tags and dates stay as they are.
 */
add_action('rest_api_init', function () {
	register_rest_route('babylovegrowth/v1', '/post-content', [
		'methods'  => 'GET',
		'callback' => 'babylovegrowth_get_post_content',
		'permission_callback' => 'babylovegrowth_verify_api_key_permission',
	]);

	register_rest_route('babylovegrowth/v1', '/post-content/(?P<id>\d+)', [
		'methods'  => 'POST',
		'callback' => 'babylovegrowth_update_post_content',
		'permission_callback' => 'babylovegrowth_verify_api_key_permission',
	]);
});

// Every post with this slug, so the caller can refuse to guess when there are several
function babylovegrowth_get_post_content(WP_REST_Request $request) {
	$slug = sanitize_title((string) $request->get_param('slug'));
	if ($slug === '') {
		return new WP_REST_Response(['success' => false, 'error' => 'missing_slug'], 400);
	}

	$posts = get_posts([
		'name'        => $slug,
		'post_type'   => 'post',
		'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
		'numberposts' => -1,
	]);

	$response = new WP_REST_Response(array_map(function ($post) {
		return ['id' => $post->ID, 'content' => ['raw' => $post->post_content]];
	}, $posts), 200);
	// A cached copy would make the caller write stale content over the owner's edits
	$response->header('Cache-Control', 'no-store');

	return $response;
}

function babylovegrowth_update_post_content(WP_REST_Request $request) {
	$post = get_post((int) $request['id']);
	if (!$post || $post->post_type !== 'post' || $post->post_status === 'trash') {
		return new WP_REST_Response(['success' => false, 'error' => 'not_found'], 404);
	}

	$body = (array) $request->get_json_params();
	$content = $body['content'] ?? null;
	if (!is_string($content)) {
		return new WP_REST_Response(['success' => false, 'error' => 'invalid_payload'], 400);
	}

	// Save as the post's author, so WordPress applies the same HTML rules as when they saved it
	wp_set_current_user((int) $post->post_author);
	// Refuse rather than let WordPress strip embeds or scripts the owner added
	if (!current_user_can('unfiltered_html') && wp_kses_post($content) !== $content) {
		return new WP_REST_Response(['success' => false, 'error' => 'content_would_be_filtered'], 409);
	}

	$result = wp_update_post(['ID' => $post->ID, 'post_content' => wp_slash($content)], true);
	if (is_wp_error($result)) {
		babylovegrowth_log_event('error', ['post_id' => $post->ID, 'slug' => $post->post_name]);
		return new WP_REST_Response(['success' => false, 'error' => $result->get_error_message()], 500);
	}

	babylovegrowth_log_event('updated', ['post_id' => $post->ID, 'slug' => $post->post_name]);

	return new WP_REST_Response(['success' => true, 'id' => $post->ID], 200);
}
