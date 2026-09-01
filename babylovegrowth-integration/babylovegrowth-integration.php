<?php
/**
 * Plugin Name: BabyLoveGrowth Integration
 * Description: Secure REST endpoint to publish posts from BabyLoveGrowth.ai backend via API key.
 * Version: 1.0.24
 * Author: BabyLoveGrowth.ai
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: babylovegrowth-integration
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * Tested up to: 7.0
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/key.php';
require_once __DIR__ . '/includes/log.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/rest.php';

register_activation_hook(__FILE__, function () {
	// Migrate old option key if present
	$old = get_option('blg_api_key', '');
	$new = get_option('babylovegrowth_api_key', '');
	if ($old && !$new) {
		update_option('babylovegrowth_api_key', $old);
		delete_option('blg_api_key');
	}

	// Only issue a key on a genuinely fresh install. An existing site keeps the
	// key it already handed to the dashboard, whether that is a readable
	// pre-1.0.21 key or a fingerprint from a later one.
	$has_key = get_option('babylovegrowth_api_key', '') !== ''
		|| get_option('babylovegrowth_key_hash', '') !== '';

	if (!$has_key) {
		babylovegrowth_store_new_api_key(babylovegrowth_generate_api_key());
	}
});

register_deactivation_hook(__FILE__, function () {
	// Keep key for reconnection.
});


