<?php
/**
 * Activity log for the publish endpoint.
 *
 * Every request that reaches the endpoint is recorded — accepted or rejected —
 * so the site owner can answer "did this plugin create that post?" from the
 * WordPress admin instead of digging through server logs. Rejected attempts
 * matter most: they show whether anyone is trying keys that do not work.
 *
 * The log is capped and stored without autoloading, so it never grows into a
 * performance problem.
 */

if (!defined('ABSPATH')) exit;

if (!defined('BABYLOVEGROWTH_LOG_OPTION')) {
	define('BABYLOVEGROWTH_LOG_OPTION', 'babylovegrowth_activity_log');
}
if (!defined('BABYLOVEGROWTH_LOG_MAX')) {
	define('BABYLOVEGROWTH_LOG_MAX', 200);
}
if (!defined('BABYLOVEGROWTH_AUTH_FAILURE_LIMIT')) {
	define('BABYLOVEGROWTH_AUTH_FAILURE_LIMIT', 20);
}

/**
 * Record one request against the publish endpoint.
 *
 * No originating address is kept. The log exists to say what this plugin did,
 * and storing visitors' IP addresses to answer that would mean holding personal
 * data for no purpose — the server's own access log already has origin detail
 * for the rare occasion anyone needs it.
 *
 * @param string $result  Short outcome code, e.g. 'published', 'invalid_key'.
 * @param array  $context Optional extra fields (post_id, slug).
 */
function babylovegrowth_log_event($result, $context = []) {
	$log = babylovegrowth_get_log();

	// A run of identical rejections collapses into one counted row. Without this,
	// a few hundred failed attempts would push every record of real publishes out
	// of the log — destroying the history at the exact moment it is needed.
	if (
		babylovegrowth_log_result_is_rejection($result)
		&& isset($log[0]['result'])
		&& $log[0]['result'] === $result
	) {
		$log[0]['count'] = (isset($log[0]['count']) ? (int) $log[0]['count'] : 1) + 1;
		$log[0]['time']  = gmdate('Y-m-d H:i:s');
		update_option(BABYLOVEGROWTH_LOG_OPTION, $log, false);
		return;
	}

	array_unshift($log, [
		'time'    => gmdate('Y-m-d H:i:s'),
		'result'  => (string) $result,
		'post_id' => isset($context['post_id']) ? (int) $context['post_id'] : 0,
		'slug'    => isset($context['slug']) ? (string) $context['slug'] : '',
	]);

	if (count($log) > BABYLOVEGROWTH_LOG_MAX) {
		$log = array_slice($log, 0, BABYLOVEGROWTH_LOG_MAX);
	}

	update_option(BABYLOVEGROWTH_LOG_OPTION, $log, false);
}

/**
 * Most recent entries, newest first.
 */
function babylovegrowth_get_log() {
	$log = get_option(BABYLOVEGROWTH_LOG_OPTION, []);

	return is_array($log) ? $log : [];
}

/**
 * Erase origin addresses recorded by a pre-release build that kept them.
 *
 * Runs once from the admin rather than on every read, so the publish path stays
 * a plain append and the check is not repeated forever after it is satisfied.
 */
add_action('admin_init', 'babylovegrowth_purge_stored_ips');

function babylovegrowth_purge_stored_ips() {
	if (get_option('babylovegrowth_ip_purged')) {
		return;
	}

	$log = get_option(BABYLOVEGROWTH_LOG_OPTION, []);
	if (is_array($log) && !empty($log)) {
		foreach ($log as $i => $entry) {
			if (is_array($entry)) {
				unset($log[$i]['ip']);
			}
		}
		update_option(BABYLOVEGROWTH_LOG_OPTION, $log, false);
	}

	update_option('babylovegrowth_ip_purged', 1, false);
}

/**
 * Human-readable label for a result code.
 */
function babylovegrowth_log_result_label($result) {
	$labels = [
		'published'      => __('Published', 'babylovegrowth-integration'),
		'updated'        => __('Updated existing post', 'babylovegrowth-integration'),
		'missing_key'    => __('Rejected — no key supplied', 'babylovegrowth-integration'),
		'invalid_key'    => __('Rejected — wrong key', 'babylovegrowth-integration'),
		'throttled'      => __('Rejected — too many failed attempts', 'babylovegrowth-integration'),
		'invalid_payload'=> __('Rejected — incomplete article', 'babylovegrowth-integration'),
		'error'          => __('Failed — WordPress error', 'babylovegrowth-integration'),
		'key_rotated'    => __('Integration Key regenerated', 'babylovegrowth-integration'),
	];

	return $labels[$result] ?? $result;
}

/**
 * Was this result a rejection? Used to highlight the row in the admin table.
 */
function babylovegrowth_log_result_is_rejection($result) {
	return in_array($result, ['missing_key', 'invalid_key', 'throttled'], true);
}

/**
 * Plain-language note on what a rejection means, or '' when none applies.
 *
 * A result code names the problem; this says what to do about it.
 */
function babylovegrowth_log_result_hint($result) {
	$hints = [
		'missing_key'     => __('The request arrived without a key.', 'babylovegrowth-integration'),
		'invalid_key'     => __('Your dashboard is using an old key.', 'babylovegrowth-integration'),
		'throttled'       => __('Paused after repeated failed attempts.', 'babylovegrowth-integration'),
		'invalid_payload' => __('The article arrived incomplete.', 'babylovegrowth-integration'),
	];

	return $hints[$result] ?? '';
}

/**
 * Epoch seconds for a log entry, or 0 if it cannot be read.
 *
 * Entries store UTC in 'Y-m-d H:i:s', so this parses rather than requiring a
 * stored timestamp — existing logs keep working untouched.
 */
function babylovegrowth_log_entry_timestamp($entry) {
	if (empty($entry['time'])) {
		return 0;
	}

	$ts = strtotime($entry['time'] . ' UTC');

	return $ts ? $ts : 0;
}

/**
 * Relative wording for a timestamp, e.g. "4 mins ago".
 */
function babylovegrowth_relative_time($ts) {
	if (!$ts) {
		return '';
	}

	$now = time();
	if ($ts > $now) {
		$ts = $now;
	}

	/* translators: %s: human-readable time difference, e.g. "4 mins". */
	return sprintf(__('%s ago', 'babylovegrowth-integration'), human_time_diff($ts, $now));
}

/**
 * Whether this site is currently publishing, being rejected, or still waiting.
 *
 * Read entirely from the activity log — this reports what has actually happened
 * rather than testing anything, so it costs nothing to display.
 *
 * @return array{state:string,title:string,detail:string}
 */
function babylovegrowth_connection_status() {
	$last_ok = 0;
	$last_rejected = 0;

	foreach (babylovegrowth_get_log() as $entry) {
		$ts = babylovegrowth_log_entry_timestamp($entry);
		if (!$ts) {
			continue;
		}

		if (in_array($entry['result'], ['published', 'updated'], true)) {
			$last_ok = max($last_ok, $ts);
		} elseif ($entry['result'] === 'invalid_key') {
			// Only a wrong key means the dashboard is misconfigured. A request that
			// carried no key at all never came from BabyLoveGrowth — we always send
			// one — so it is a passing scanner, and reporting it as a broken
			// integration would alarm sites where nothing is actually wrong.
			$last_rejected = max($last_rejected, $ts);
		}
	}

	// Only call it broken if the rejection is more recent than the last success;
	// rejections that predate a successful publish have already been resolved.
	if ($last_rejected > $last_ok) {
		return [
			'state'  => 'bad',
			'title'  => __('Requests are being rejected.', 'babylovegrowth-integration'),
			'detail' => __('The key in your BabyLoveGrowth dashboard does not match this site. Generate a new key below and paste it into your dashboard.', 'babylovegrowth-integration'),
		];
	}

	if ($last_ok) {
		return [
			'state'  => 'ok',
			'title'  => __('Connected.', 'babylovegrowth-integration'),
			/* translators: %s: relative time, e.g. "4 mins ago". */
			'detail' => sprintf(__('Last article received %s.', 'babylovegrowth-integration'), babylovegrowth_relative_time($last_ok)),
		];
	}

	return [
		'state'  => 'wait',
		'title'  => __('Waiting for your first article.', 'babylovegrowth-integration'),
		'detail' => __('Add the Integration Key below to your BabyLoveGrowth dashboard to connect this site.', 'babylovegrowth-integration'),
	];
}

/**
 * Rate limit repeated failures from one address.
 *
 * The key is far too long to guess, so this is not really brute-force defence —
 * it stops a flood of bad requests from filling the log and hammering the DB.
 * Throttling keys off REMOTE_ADDR because, unlike the forwarded headers, it
 * cannot be spoofed by the caller.
 */
function babylovegrowth_auth_failure_transient() {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Hashed immediately, never output.
	$addr = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : 'unknown';
	return 'blg_authfail_' . md5($addr);
}

function babylovegrowth_auth_is_throttled() {
	return (int) get_transient(babylovegrowth_auth_failure_transient()) >= BABYLOVEGROWTH_AUTH_FAILURE_LIMIT;
}

function babylovegrowth_record_auth_failure() {
	$transient = babylovegrowth_auth_failure_transient();
	$count = (int) get_transient($transient) + 1;
	set_transient($transient, $count, 15 * MINUTE_IN_SECONDS);

	// Recorded once, when throttling begins. Logging every blocked request after
	// that would turn a flood into one database write per request.
	if ($count === BABYLOVEGROWTH_AUTH_FAILURE_LIMIT) {
		babylovegrowth_log_event('throttled');
	}
}

function babylovegrowth_clear_auth_failures() {
	delete_transient(babylovegrowth_auth_failure_transient());
}
