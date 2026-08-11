<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('babylovegrowth_api_key');
delete_option('babylovegrowth_key_hash');
delete_option('babylovegrowth_key_preview');
delete_option('babylovegrowth_key_plain');
delete_option('babylovegrowth_key_issued');
delete_option('babylovegrowth_activity_log');
delete_option('babylovegrowth_ip_purged');
delete_option('babylovegrowth_category');
delete_option('babylovegrowth_author');
delete_option('babylovegrowth_tags');
delete_option('babylovegrowth_feature_image_enabled');

// Clean up post meta
delete_post_meta_by_key('_babylovegrowth_jsonld');


