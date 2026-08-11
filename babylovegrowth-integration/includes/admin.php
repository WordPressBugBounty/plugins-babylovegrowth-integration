<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
	$icon_url = plugins_url('babylovegrowth-logo.png', dirname(__DIR__) . '/babylovegrowth-integration.php');
	add_menu_page(
		__('BabyLoveGrowth Integration', 'babylovegrowth-integration'),
		__('Babylovegrowth', 'babylovegrowth-integration'),
		'manage_options',
		'babylovegrowth-integration',
		'babylovegrowth_integration_settings_page',
		$icon_url,
		56
	);
	add_submenu_page(
		'babylovegrowth-integration',
		__('Manage', 'babylovegrowth-integration'),
		__('Manage', 'babylovegrowth-integration'),
		'manage_options',
		'babylovegrowth-integration',
		'babylovegrowth_integration_settings_page'
	);
});

// Ensure custom menu icon scales to standard size
add_action('admin_head', function () {
	echo '<style>
	#toplevel_page_babylovegrowth-integration .wp-menu-image img{
		width:20px;height:20px;max-width:20px;max-height:20px;object-fit:contain;
	}
	</style>';
});

add_action('admin_init', function () {
	// The Integration Key is deliberately not registered as a setting. It is not
	// user-editable text any more: it is issued by "Generate New Key" and stored
	// as a fingerprint, so there is nothing for the settings form to save.
	register_setting('babylovegrowth_integration', 'babylovegrowth_category', [
		'sanitize_callback' => function ($val) { return absint($val); }
	]);
	register_setting('babylovegrowth_integration', 'babylovegrowth_author', [
		'sanitize_callback' => function ($val) { return absint($val); }
	]);
	register_setting('babylovegrowth_integration', 'babylovegrowth_tags', [
		'sanitize_callback' => function ($val) {
			if (!is_array($val)) return [];
			return array_map('absint', $val);
		}
	]);
	register_setting('babylovegrowth_integration', 'babylovegrowth_feature_image_enabled', [
		'type' => 'boolean',
		'default' => true,
		'sanitize_callback' => function ($val) { return (bool) $val; }
	]);
	register_setting('babylovegrowth_integration', 'babylovegrowth_post_status', [
		'type' => 'string',
		'default' => 'publish',
		'sanitize_callback' => function ($val) {
			$val = sanitize_key($val);
			return in_array($val, ['publish', 'draft'], true) ? $val : 'publish';
		}
	]);
});

/**
 * Replace the Integration Key with a freshly generated one.
 *
 * The old key stops working the moment this runs, which is the point: it is the
 * recovery path for a key that has leaked. Publishing stays broken until the new
 * key is pasted into the BabyLoveGrowth dashboard, so the redirect flags the
 * settings page to say so.
 */
add_action('admin_post_babylovegrowth_rotate_key', function () {
	if (!current_user_can('manage_options')) {
		wp_die(
			esc_html__('You are not allowed to change the Integration Key.', 'babylovegrowth-integration'),
			'',
			['response' => 403]
		);
	}
	check_admin_referer('babylovegrowth_rotate_key');

	babylovegrowth_store_new_api_key(babylovegrowth_generate_api_key());
	babylovegrowth_log_event('key_rotated');

	wp_safe_redirect(add_query_arg(
		['page' => 'babylovegrowth-integration', 'blg_key_rotated' => '1'],
		admin_url('admin.php')
	));
	exit;
});

/**
 * Hide the readable key early, once the owner confirms they have copied it.
 */
add_action('admin_post_babylovegrowth_hide_key', function () {
	if (!current_user_can('manage_options')) {
		wp_die(
			esc_html__('You are not allowed to change the Integration Key.', 'babylovegrowth-integration'),
			'',
			['response' => 403]
		);
	}
	check_admin_referer('babylovegrowth_hide_key');

	babylovegrowth_hide_plain_key();

	wp_safe_redirect(add_query_arg(
		['page' => 'babylovegrowth-integration'],
		admin_url('admin.php')
	));
	exit;
});

function babylovegrowth_integration_settings_page() {
	if (!current_user_can('manage_options')) return;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own post-rotation redirect.
	$just_rotated = isset($_GET['blg_key_rotated']);

	list($key, $key_state) = babylovegrowth_key_display();
	$selected_category = get_option('babylovegrowth_category', '');
	$selected_author = get_option('babylovegrowth_author', '');
	$selected_tags = get_option('babylovegrowth_tags', []);
	$feature_image_enabled = get_option('babylovegrowth_feature_image_enabled', true);
	$post_status = get_option('babylovegrowth_post_status', 'publish');
	$categories = get_categories(['hide_empty' => false]);
	$authors = get_users(['role__in' => ['administrator', 'editor', 'author'], 'orderby' => 'display_name', 'order' => 'ASC']);
	$tags = get_tags(['hide_empty' => false]);
	?>
	<div class="wrap blg-wrap">
		<style>
			/* Theme variables */
			.blg-wrap{
				/* Based on blg-frontend (Tailwind) palette */
				--blg-primary:#F25533; /* secondary.DEFAULT */
				--blg-primary-600:#983000; /* secondary.dark */
				--blg-text:#221F1F; /* ink */
				--blg-muted:#45505E; /* text.muted */
				--blg-surface:#FFFFFF; /* neutral.white */
				--blg-surface-alt:#f9f9f9; /* neutral.slate.DEFAULT */
				--blg-border:#E5E5E5; /* neutral.light */
				--blg-shadow:0 1px 2px rgba(0,0,0,.04);
			}
			.blg-wrap .blg-hero{background:var(--blg-primary);border:0;border-radius:12px;color:#fff;padding:18px 24px;margin:18px 0 14px;display:flex;align-items:center;justify-content:center;text-align:center}
			.blg-wrap .blg-hero h1{margin:0;font-size:24px;line-height:1.2;color:#fff}
			.blg-wrap .blg-hero p{margin:4px 0 0;opacity:.95;font-size:13px;color:#fff}
			.blg-card{background:var(--blg-surface);border:1px solid var(--blg-border);border-radius:12px;box-shadow:var(--blg-shadow);padding:24px}
			/* Two columns: setup on the left, monitoring on the right.
			   Collapses to the original single column on narrower screens. */
			.blg-cols{display:flex;gap:20px;align-items:flex-start}
			.blg-cols>.blg-col-main{flex:1.5 1 0;min-width:0;display:flex;flex-direction:column;gap:20px}
			.blg-cols>.blg-col-side{flex:1 1 0;min-width:0;display:flex;flex-direction:column;gap:20px}
			.blg-cols form{margin:0}
			@media (max-width:1100px){
				.blg-cols{display:block}
				.blg-cols>.blg-col-side{margin-top:20px}
				.blg-cols>.blg-col-main>*+*,.blg-cols>.blg-col-side>*+*{margin-top:20px}
			}
			/* Connection status */
			.blg-status{display:flex;align-items:flex-start;gap:10px;border:1px solid;border-radius:10px;padding:12px 16px;margin:0 0 14px;font-size:13px;line-height:1.6}
			.blg-status .blg-status-dot{width:9px;height:9px;border-radius:50%;flex:none;margin-top:6px}
			.blg-status-ok{color:#0f6b4f;background:#e8f5ef;border-color:#b9e0cf}
			.blg-status-ok .blg-status-dot{background:#0f6b4f}
			.blg-status-bad{color:#a8231a;background:#fdeceb;border-color:#f3c6c2}
			.blg-status-bad .blg-status-dot{background:#a8231a}
			.blg-status-wait{color:#45505E;background:#f2f4f6;border-color:#dde2e7}
			.blg-status-wait .blg-status-dot{background:#45505E}
			.blg-field{margin:0 0 18px}
			.blg-label{font-weight:600;margin-bottom:6px;display:block}
			.blg-input{width:100%;max-width:none;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px}
			.blg-desc{margin-top:6px;color:var(--blg-muted);font-size:12px}
			.blg-input-group{display:flex;gap:8px;align-items:center}
			.blg-input-group .blg-input{flex:1}
			.blg-copy.button{border-radius:8px;background:var(--blg-surface-alt);border:1px solid var(--blg-border);color:var(--blg-text);cursor:pointer}
			.blg-copy.button:hover{border-color:#cbd5e1}
			.blg-actions{margin-top:18px}
			.blg-actions .button-primary{border-radius:24px;padding:6px 18px;height:auto;background:var(--blg-primary);border-color:var(--blg-primary-600)}
			.blg-actions .button-primary:hover{background:var(--blg-primary-600);border-color:var(--blg-primary-600)}
			.blg-advanced{max-width:860px;margin:16px auto}
			.blg-advanced details{background:var(--blg-surface-alt);border:1px solid var(--blg-border);border-radius:10px;padding:12px 16px}
			.blg-advanced summary{cursor:pointer;font-weight:600}
			.blg-advanced table.form-table th{width:220px}
			/* Tutorial embed */
			.blg-video-card .blg-card-title{margin:0 0 12px;font-size:16px;font-weight:600}
			.blg-video{width:100%;aspect-ratio:16/9;border:1px solid var(--blg-border);border-radius:10px;overflow:hidden}
			.blg-video iframe{width:100%;height:100%;display:block;border:0}
			/* Section headers */
			.blg-section-header{margin:32px 0 20px;padding-bottom:12px;border-bottom:2px solid var(--blg-border);font-size:20px;font-weight:700;color:var(--blg-text)}
			.blg-section-header:first-of-type{margin-top:0}
			.blg-section-note{background:var(--blg-surface-alt);border-left:4px solid var(--blg-primary);padding:12px 16px;border-radius:6px;margin-bottom:20px;font-size:13px;color:var(--blg-muted);line-height:1.6}
			.blg-card-readonly{background:var(--blg-surface-alt);border:1px solid var(--blg-border);border-radius:12px;box-shadow:var(--blg-shadow);padding:24px}
			.blg-warn{margin-top:6px;font-size:12px;line-height:1.6;color:#7a3a00;background:#fff6e5;border-left:4px solid var(--blg-primary);padding:10px 12px;border-radius:6px}
			/* Tag checkboxes */
			.blg-checks{display:flex;flex-wrap:wrap;gap:8px 20px;margin-top:4px}
			.blg-checks label{display:flex;align-items:center;gap:6px;font-size:13px}
			/* Activity log — compact two-line entries so it fits the side column.
			   Scrolls internally so a busy log never stretches the page. */
			.blg-log{display:flex;flex-direction:column;max-height:420px;overflow-y:auto;overscroll-behavior:contain;padding-right:6px}
			.blg-log-item{padding:9px 0 9px 10px;border-bottom:1px solid var(--blg-border);border-left:2px solid transparent}
			.blg-log-item:last-child{border-bottom:0}
			.blg-log-item.blg-log-bad{border-left-color:#a8231a;background:#fff5f5}
			.blg-log-top{display:flex;justify-content:space-between;gap:10px;font-size:13px}
			.blg-log-what{font-weight:600;color:var(--blg-text)}
			.blg-log-item.blg-log-bad .blg-log-what{color:#a8231a}
			.blg-log-when{color:var(--blg-muted);white-space:nowrap;font-variant-numeric:tabular-nums}
			.blg-log-sub{font-size:12px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
			.blg-log-hint{color:var(--blg-muted)}
			.blg-log-empty{color:var(--blg-muted);font-size:13px;margin:0}
		</style>

		<div class="blg-hero">
			<div>
				<h1><?php echo esc_html__('BabyLoveGrowth Integration', 'babylovegrowth-integration'); ?></h1>
				<p><?php echo esc_html__('Publish articles from BabyLoveGrowth to your website', 'babylovegrowth-integration'); ?></p>
			</div>
		</div>

		<?php $status = babylovegrowth_connection_status(); ?>
		<div class="blg-status blg-status-<?php echo esc_attr($status['state']); ?>">
			<span class="blg-status-dot"></span>
			<span><strong><?php echo esc_html($status['title']); ?></strong> <?php echo esc_html($status['detail']); ?></span>
		</div>

		<?php if ($just_rotated) : ?>
			<div class="notice notice-success">
				<p><?php echo esc_html__('A new Integration Key has been generated. The previous key stopped working immediately — copy the new key below into your BabyLoveGrowth dashboard to resume publishing.', 'babylovegrowth-integration'); ?></p>
			</div>
		<?php endif; ?>

		<div class="blg-cols">
		<div class="blg-col-main">

		<!-- Step 1: Copy to BabyLoveGrowth Dashboard -->
		<div class="blg-card-readonly">
			<h2 class="blg-section-header"><?php echo esc_html__('Step 1: Copy This to Your BabyLoveGrowth Dashboard', 'babylovegrowth-integration'); ?></h2>
			<p class="blg-section-note">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Format string is escaped; the only interpolation is a link built here.
				printf(
					/* translators: %s: link reading "BabyLoveGrowth dashboard". */
					esc_html__('Copy the key below and paste it into your %s integration settings.', 'babylovegrowth-integration'),
					'<a href="' . esc_url('https://www.babylovegrowth.ai/dashboard') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('BabyLoveGrowth dashboard', 'babylovegrowth-integration') . '</a>'
				);
				?>
			</p>

			<div class="blg-field">
				<label for="babylovegrowth_api_key" class="blg-label"><?php echo esc_html__('Integration Key', 'babylovegrowth-integration'); ?></label>
				<div class="blg-input-group">
					<input type="text" id="babylovegrowth_api_key" value="<?php echo esc_attr($key); ?>" class="blg-input" readonly />
					<?php if ($key_state !== 'hidden' && $key_state !== 'missing') : ?>
						<button type="button" class="button blg-copy" data-copy-target="#babylovegrowth_api_key"><?php echo esc_html__('Copy', 'babylovegrowth-integration'); ?></button>
					<?php endif; ?>
				</div>

				<?php if ($key_state === 'pickup') : ?>
					<p class="blg-warn">
						<strong><?php echo esc_html__('Copy this key now.', 'babylovegrowth-integration'); ?></strong>
						<?php echo esc_html__('For your security it is hidden once your first article arrives, and it cannot be shown again. If you lose it, generate a new one.', 'babylovegrowth-integration'); ?>
					</p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
						<input type="hidden" name="action" value="babylovegrowth_hide_key" />
						<?php wp_nonce_field('babylovegrowth_hide_key'); ?>
						<button type="submit" class="button button-small"><?php echo esc_html__('I have saved it — hide it now', 'babylovegrowth-integration'); ?></button>
					</form>
				<?php elseif ($key_state === 'hidden') : ?>
					<p class="blg-desc"><?php echo esc_html__('Your key is stored securely and cannot be displayed again. If you no longer have it, or you think someone else does, generate a new one below.', 'babylovegrowth-integration'); ?></p>
				<?php elseif ($key_state === 'legacy') : ?>
					<p class="blg-warn"><?php echo esc_html__('This key was created by an older version of the plugin and is still stored in readable form. Generating a new key upgrades this site to secure storage, so a copy of your database no longer reveals a working key.', 'babylovegrowth-integration'); ?></p>
				<?php else : ?>
					<p class="blg-desc"><?php echo esc_html__('No key yet. Generate one below to connect this site.', 'babylovegrowth-integration'); ?></p>
				<?php endif; ?>
			</div>

			<div class="blg-field">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Generate a new Integration Key? The current key stops working right away, and publishing stays paused until you paste the new key into your BabyLoveGrowth dashboard.', 'babylovegrowth-integration'))); ?>);">
					<input type="hidden" name="action" value="babylovegrowth_rotate_key" />
					<?php wp_nonce_field('babylovegrowth_rotate_key'); ?>
					<button type="submit" class="button"><?php echo esc_html__('Generate New Key', 'babylovegrowth-integration'); ?></button>
					<p class="blg-desc"><?php echo esc_html__('Use this if your key may have been exposed — for example after a security incident, a database leak, or when someone who had access leaves. The old key is revoked instantly, so remember to paste the new key into your BabyLoveGrowth dashboard afterwards.', 'babylovegrowth-integration'); ?></p>
				</form>
			</div>

		</div>

		<!-- Step 2: Configure in WordPress -->
		<form method="post" action="options.php">
			<?php settings_fields('babylovegrowth_integration'); ?>
			<div class="blg-card">
				<h2 class="blg-section-header"><?php echo esc_html__('Step 2: Configure Your WordPress Settings', 'babylovegrowth-integration'); ?></h2>
				<p class="blg-section-note"><?php echo esc_html__('These settings control how articles are published on your WordPress site. Make your selections below and click Save.', 'babylovegrowth-integration'); ?></p>

				<div class="blg-field">
					<label for="babylovegrowth_post_status" class="blg-label"><?php echo esc_html__('How should articles be published?', 'babylovegrowth-integration'); ?></label>
					<select id="babylovegrowth_post_status" name="babylovegrowth_post_status" class="blg-input">
						<option value="publish" <?php selected($post_status, 'publish'); ?>><?php echo esc_html__('Publish immediately', 'babylovegrowth-integration'); ?></option>
						<option value="draft" <?php selected($post_status, 'draft'); ?>><?php echo esc_html__('Save as draft (review before publishing)', 'babylovegrowth-integration'); ?></option>
					</select>
					<p class="blg-desc"><?php echo esc_html__('Choose whether articles appear on your site right away or are saved for you to review first.', 'babylovegrowth-integration'); ?></p>
				</div>

				<div class="blg-field">
					<label for="babylovegrowth_category" class="blg-label"><?php echo esc_html__('Default Category', 'babylovegrowth-integration'); ?></label>
					<select id="babylovegrowth_category" name="babylovegrowth_category" class="blg-input" style="max-width:25em">
						<option value=""><?php echo esc_html__('— Select Category —', 'babylovegrowth-integration'); ?></option>
						<?php foreach ($categories as $category) : ?>
							<option value="<?php echo esc_attr($category->term_id); ?>" <?php selected($selected_category, $category->term_id); ?>>
								<?php echo esc_html($category->name); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="blg-desc"><?php echo esc_html__('All articles from BabyLoveGrowth will be assigned to this category.', 'babylovegrowth-integration'); ?></p>
				</div>

				<div class="blg-field">
					<label for="babylovegrowth_author" class="blg-label"><?php echo esc_html__('Default Author', 'babylovegrowth-integration'); ?></label>
					<select id="babylovegrowth_author" name="babylovegrowth_author" class="blg-input" style="max-width:25em">
						<option value=""><?php echo esc_html__('— Select Author —', 'babylovegrowth-integration'); ?></option>
						<?php foreach ($authors as $author) : ?>
							<option value="<?php echo esc_attr($author->ID); ?>" <?php selected($selected_author, $author->ID); ?>>
								<?php echo esc_html($author->display_name); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="blg-desc"><?php echo esc_html__('If you do not choose one, articles are assigned to your site\'s first administrator.', 'babylovegrowth-integration'); ?></p>
				</div>

				<div class="blg-field">
					<span class="blg-label"><?php echo esc_html__('Default Tags', 'babylovegrowth-integration'); ?></span>
					<?php if (empty($tags)) : ?>
						<p class="blg-desc" style="margin-top:0"><?php echo esc_html__('No tags on this site yet.', 'babylovegrowth-integration'); ?></p>
					<?php else : ?>
						<div class="blg-checks">
							<?php foreach ($tags as $tag) : ?>
								<label>
									<input type="checkbox" name="babylovegrowth_tags[]" value="<?php echo esc_attr($tag->term_id); ?>" <?php checked(in_array($tag->term_id, (array) $selected_tags)); ?> />
									<?php echo esc_html($tag->name); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="blg-desc"><?php echo esc_html__('Every article from BabyLoveGrowth gets these tags.', 'babylovegrowth-integration'); ?></p>
					<?php endif; ?>
				</div>

				<div class="blg-field">
					<label>
						<input type="checkbox" id="babylovegrowth_feature_image_enabled" name="babylovegrowth_feature_image_enabled" value="1" <?php checked($feature_image_enabled, true); ?> />
						<?php echo esc_html__('Do not repeat the title and image inside the article', 'babylovegrowth-integration'); ?>
					</label>
					<p class="blg-desc"><?php echo esc_html__('Your article already shows its title and featured image at the top, so we remove the duplicate pair from the text.', 'babylovegrowth-integration'); ?></p>
				</div>

				<div class="blg-actions">
					<?php submit_button(__('Save WordPress Settings', 'babylovegrowth-integration')); ?>
				</div>
			</div>

		</form>

		</div><!-- /.blg-col-main -->

		<div class="blg-col-side">

		<!-- Activity log -->
		<div class="blg-card">
			<h2 class="blg-section-header"><?php echo esc_html__('Recent Activity', 'babylovegrowth-integration'); ?></h2>
			<p class="blg-section-note"><?php echo esc_html__('Every request this plugin receives is listed here, accepted or rejected. This is the record of whether a post came from BabyLoveGrowth.', 'babylovegrowth-integration'); ?></p>

			<?php $log_entries = babylovegrowth_get_log(); ?>
			<?php if (empty($log_entries)) : ?>
				<p class="blg-log-empty"><?php echo esc_html__('Nothing recorded yet.', 'babylovegrowth-integration'); ?></p>
			<?php else : ?>
				<p class="blg-desc" style="margin:0 0 8px">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Format string and both numeric arguments are escaped individually.
					printf(
						/* translators: 1: number of entries shown, 2: maximum number kept. */
						esc_html__('Showing %1$s of the last %2$s requests. Older entries are discarded automatically.', 'babylovegrowth-integration'),
						esc_html(number_format_i18n(count($log_entries))),
						esc_html(number_format_i18n(BABYLOVEGROWTH_LOG_MAX))
					);
					?>
				</p>
				<div class="blg-log">
					<?php foreach ($log_entries as $entry) : ?>
						<?php
						$is_bad = babylovegrowth_log_result_is_rejection($entry['result']);
						$hint = babylovegrowth_log_result_hint($entry['result']);
						$ts = babylovegrowth_log_entry_timestamp($entry);
						// The post may have been deleted since; fall back to plain text.
						$edit_link = !empty($entry['post_id']) ? get_edit_post_link($entry['post_id']) : '';
						$label = $entry['slug'] ?: ($entry['post_id'] ? '#' . $entry['post_id'] : '');
						// The exact time stays available on hover; the relative time is what reads.
						$exact = $entry['time'] . ' UTC';
						?>
						<div class="blg-log-item <?php echo $is_bad ? 'blg-log-bad' : ''; ?>" title="<?php echo esc_attr($exact); ?>">
							<div class="blg-log-top">
								<span class="blg-log-what">
									<?php
									$result_label = babylovegrowth_log_result_label($entry['result']);
									if (!empty($entry['count']) && (int) $entry['count'] > 1) {
										/* translators: 1: what happened, 2: how many times in a row. */
										$result_label = sprintf(
											__('%1$s × %2$s', 'babylovegrowth-integration'),
											$result_label,
											number_format_i18n((int) $entry['count'])
										);
									}
									echo esc_html($result_label);
									?>
								</span>
								<span class="blg-log-when"><?php echo esc_html($ts ? babylovegrowth_relative_time($ts) : $entry['time']); ?></span>
							</div>
							<?php if ($label) : ?>
								<div class="blg-log-sub">
									<?php if ($edit_link) : ?>
										<a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($label); ?></a>
									<?php else : ?>
										<?php echo esc_html($label); ?>
									<?php endif; ?>
								</div>
							<?php elseif ($hint) : ?>
								<div class="blg-log-sub blg-log-hint"><?php echo esc_html($hint); ?></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="blg-desc"><?php echo esc_html__('Hover any entry for the exact time.', 'babylovegrowth-integration'); ?></p>
			<?php endif; ?>
		</div>

		<div class="blg-card blg-video-card">
			<p class="blg-card-title"><?php echo esc_html__('Integration Tutorial', 'babylovegrowth-integration'); ?></p>
			<div class="blg-video">
				<iframe src="https://www.youtube.com/embed/wJvd3bEg3JI" title="<?php echo esc_attr__('BabyLoveGrowth Integration Tutorial', 'babylovegrowth-integration'); ?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
			</div>
		</div>

		</div><!-- /.blg-col-side -->
		</div><!-- /.blg-cols -->

		
		<script>
			(function(){
				function copyText(selector){
					try{
						var el = document.querySelector(selector);
						if(!el){return false;}
						var val = (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') ? el.value : (el.textContent || '');
						if(navigator.clipboard && navigator.clipboard.writeText){
							navigator.clipboard.writeText(val);
							return true;
						}
						// Fallback
						var t = document.createElement('textarea');
						t.value = val;
						t.setAttribute('readonly','');
						t.style.position='absolute';
						t.style.left='-9999px';
						document.body.appendChild(t);
						t.select();
						var ok = document.execCommand('copy');
						document.body.removeChild(t);
						return ok;
					}catch(e){ return false; }
				}
				function onCopyButtonClick(btn){
					var sel = btn.getAttribute('data-copy-target');
					var ok = copyText(sel);
					var old = btn.textContent;
					if(ok){
						btn.textContent = <?php echo wp_json_encode(esc_html__('Copied!', 'babylovegrowth-integration')); ?>;
						setTimeout(function(){ btn.textContent = old; }, 1200);
					}
				}
				document.addEventListener('click', function(e){
					var btn = e.target && e.target.closest ? e.target.closest('.blg-copy') : null;
					if(btn){ e.preventDefault(); onCopyButtonClick(btn); }
				}, false);
			})();
		</script>
	</div>
	<?php
}


