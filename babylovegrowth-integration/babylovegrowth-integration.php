<?php
/**
 * Plugin Name: BabyLoveGrowth Integration
 * Description: Secure REST endpoint to publish posts from BabyLoveGrowth.ai backend via API key.
 * Version: 1.0.15
 * Author: BabyLoveGrowth.ai
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: babylovegrowth-integration
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * Tested up to: 6.9
 */

if (!defined('ABSPATH')) exit;

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
	// Ensure key exists
	$key = get_option('babylovegrowth_api_key', '');
	if (!$key) {
		update_option('babylovegrowth_api_key', wp_generate_password(32, false, false));
	}
});

register_deactivation_hook(__FILE__, function () {
	// Keep key for reconnection.
});


