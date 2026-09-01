<?php
/**
 * Integration Key storage, verification and rotation.
 *
 * The key is kept as a SHA-256 fingerprint, never in readable form, so a stolen
 * copy of the database does not hand anyone a working key. The readable value
 * exists only between the moment it is generated and the first successful
 * publish — long enough for the site owner to copy it into the BabyLoveGrowth
 * dashboard, and no longer.
 *
 * Options used:
 *   babylovegrowth_key_hash    SHA-256 of the current key.
 *   babylovegrowth_key_preview Masked form shown in the admin once the key is hidden.
 *   babylovegrowth_key_plain   Readable key, deleted after the first successful publish.
 *   babylovegrowth_key_issued  When the readable key was generated, for the pickup deadline.
 *   babylovegrowth_api_key     Readable key on installs from before 1.0.22 (legacy).
 */

if (!defined('ABSPATH')) exit;

/**
 * How long a freshly generated key stays readable if it is never used.
 *
 * Without a deadline, a site that is installed and then abandoned would keep a
 * working key sitting in readable form in its database indefinitely — the exact
 * exposure this design exists to remove. A week is comfortably longer than any
 * real setup takes, and if it does lapse the owner just generates another.
 */
if (!defined('BABYLOVEGROWTH_KEY_PICKUP_WINDOW')) {
	define('BABYLOVEGROWTH_KEY_PICKUP_WINDOW', 7 * DAY_IN_SECONDS);
}

/**
 * Build a fresh Integration Key.
 *
 * The blg_ prefix is deliberate: it lets automated secret scanners (GitHub,
 * GitLab) recognise a leaked key on sight, and it makes the value obvious in a
 * support ticket.
 */
function babylovegrowth_generate_api_key() {
	return 'blg_' . wp_generate_password(40, false, false);
}

/**
 * Masked form of a key, e.g. blg_••••••a91f.
 */
function babylovegrowth_build_key_preview($key) {
	// Keep the whole prefix, including its underscore, however long it is.
	$underscore = strpos($key, '_');
	$prefix = $underscore === false ? substr($key, 0, 4) : substr($key, 0, $underscore + 1);

	return $prefix . str_repeat('•', 6) . substr($key, -4);
}

/**
 * Install a newly generated key, replacing whatever came before it.
 *
 * Any previous key stops working immediately. That is intentional — the reason
 * to rotate is usually that the old key is in someone else's hands, and a grace
 * period would keep the door open for exactly the person being locked out.
 */
function babylovegrowth_store_new_api_key($key) {
	update_option('babylovegrowth_key_hash', hash('sha256', $key), false);
	update_option('babylovegrowth_key_preview', babylovegrowth_build_key_preview($key), false);

	// Held only until the first successful publish proves the dashboard has it,
	// or until the pickup window lapses — whichever comes first.
	update_option('babylovegrowth_key_plain', $key, false);
	update_option('babylovegrowth_key_issued', time(), false);

	// A rotated key supersedes the readable pre-1.0.22 one outright.
	delete_option('babylovegrowth_api_key');
}

/**
 * Drop the readable copy once the pickup window has passed.
 *
 * Runs lazily on read rather than on a schedule, so it works the same whether or
 * not the site has reliable cron.
 */
function babylovegrowth_maybe_expire_plain_key() {
	if (get_option('babylovegrowth_key_plain', '') === '') {
		return;
	}

	$issued = (int) get_option('babylovegrowth_key_issued', 0);
	if ($issued && (time() - $issued) > BABYLOVEGROWTH_KEY_PICKUP_WINDOW) {
		babylovegrowth_hide_plain_key();
	}
}

/**
 * Stop showing the readable key and remove it from the database.
 */
function babylovegrowth_hide_plain_key() {
	delete_option('babylovegrowth_key_plain');
	delete_option('babylovegrowth_key_issued');
}

/**
 * Check a key presented by an incoming request.
 *
 * A plain SHA-256 is the right tool here rather than a password hash: the key is
 * 40 random characters, so there is no dictionary to run against it, and the
 * comparison sits on a request path that must stay fast.
 */
function babylovegrowth_verify_api_key($incoming) {
	if (!is_string($incoming) || $incoming === '') {
		return false;
	}

	$hash = get_option('babylovegrowth_key_hash', '');
	if ($hash && hash_equals($hash, hash('sha256', $incoming))) {
		return true;
	}

	// Installs from before 1.0.22 still hold the key in readable form. They keep
	// working until the owner rotates, which moves them to fingerprint storage.
	$legacy = get_option('babylovegrowth_api_key', '');
	if ($legacy && hash_equals($legacy, $incoming)) {
		return true;
	}

	return false;
}

/**
 * Hide the readable key for good.
 *
 * Called after the first successful publish: the dashboard clearly has the key,
 * so there is no longer any reason for this site to keep a readable copy.
 */
function babylovegrowth_confirm_key_delivered() {
	if (get_option('babylovegrowth_key_plain', '') !== '') {
		babylovegrowth_hide_plain_key();
	}
}

/**
 * What the settings page should display for the key, and in which state.
 *
 * Returns [value, state] where state is one of:
 *   'pickup' — readable, copy it now
 *   'legacy' — readable, stored the old way, rotation will upgrade it
 *   'hidden' — masked; only a fingerprint is stored
 *   'missing' — no key at all
 */
function babylovegrowth_key_display() {
	babylovegrowth_maybe_expire_plain_key();

	$plain = get_option('babylovegrowth_key_plain', '');
	if ($plain !== '') {
		return [$plain, 'pickup'];
	}

	$legacy = get_option('babylovegrowth_api_key', '');
	if ($legacy !== '' && get_option('babylovegrowth_key_hash', '') === '') {
		return [$legacy, 'legacy'];
	}

	$preview = get_option('babylovegrowth_key_preview', '');
	if ($preview !== '') {
		return [$preview, 'hidden'];
	}

	return ['', 'missing'];
}
