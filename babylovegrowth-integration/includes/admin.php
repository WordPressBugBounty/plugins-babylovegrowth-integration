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
	register_setting('babylovegrowth_integration', 'babylovegrowth_api_key', [
		'sanitize_callback' => function ($val) {
			$val = sanitize_text_field($val);
			// Preserve existing key if an empty value (or no value) is submitted
			if ($val === '' || $val === null) {
				$existing = get_option('babylovegrowth_api_key', '');
				return $existing;
			}
			return $val;
		}
	]);
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

function babylovegrowth_integration_settings_page() {
	if (!current_user_can('manage_options')) return;

	$key = get_option('babylovegrowth_api_key', '');
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
			.blg-wrap .blg-hero{background:var(--blg-primary);border:0;border-radius:12px;color:#fff;padding:32px 24px;margin:18px 0 24px;display:flex;align-items:center;justify-content:center;text-align:center}
			.blg-wrap .blg-hero h1{margin:0;font-size:36px;line-height:1.2;color:#fff}
			.blg-wrap .blg-hero p{margin:8px 0 0;opacity:.95;font-size:14px;color:#fff}
			.blg-card{background:var(--blg-surface);border:1px solid var(--blg-border);border-radius:12px;box-shadow:var(--blg-shadow);padding:24px;max-width:860px;margin:0 auto}
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
			.blg-card-readonly{background:var(--blg-surface-alt);border:1px solid var(--blg-border);border-radius:12px;box-shadow:var(--blg-shadow);padding:24px;max-width:860px;margin:0 auto 24px}
		</style>

		<div class="blg-hero">
			<div>
				<h1><?php echo esc_html__('BabyLoveGrowth Integration', 'babylovegrowth-integration'); ?></h1>
				<p><?php echo esc_html__('Configure BabyLoveGrowth plugin to publish articles to your website', 'babylovegrowth-integration'); ?></p>
			</div>
		</div>

		<!-- Step 1: Copy to BLG Dashboard -->
		<div class="blg-card-readonly">
			<h2 class="blg-section-header"><?php echo esc_html__('Step 1: Copy These to Your BLG Dashboard', 'babylovegrowth-integration'); ?></h2>
			<p class="blg-section-note"><?php echo esc_html__('Copy the information below and paste it into your BabyLoveGrowth dashboard integration settings.', 'babylovegrowth-integration'); ?></p>
			
			<div class="blg-field">
				<label for="babylovegrowth_api_key" class="blg-label"><?php echo esc_html__('Integration Key', 'babylovegrowth-integration'); ?></label>
				<div class="blg-input-group">
					<input type="text" id="babylovegrowth_api_key" name="babylovegrowth_api_key" value="<?php echo esc_attr($key); ?>" class="blg-input" readonly />
					<button type="button" class="button blg-copy" data-copy-target="#babylovegrowth_api_key"><?php echo esc_html__('Copy', 'babylovegrowth-integration'); ?></button>
				</div>
				<p class="blg-desc"><?php echo esc_html__('Copy this key and paste it into the "Integration Key" field in your BLG dashboard.', 'babylovegrowth-integration'); ?></p>
			</div>
		</div>

		<!-- Step 2: Configure in WordPress -->
		<form method="post" action="options.php">
			<?php settings_fields('babylovegrowth_integration'); ?>
			<!-- Preserve Integration Key on save -->
			<input type="hidden" name="babylovegrowth_api_key" value="<?php echo esc_attr($key); ?>" />
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
					<p class="blg-desc"><?php echo esc_html__('All articles from BLG will be assigned to this category.', 'babylovegrowth-integration'); ?></p>
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
					<p class="blg-desc"><?php echo esc_html__('All articles from BLG will be assigned to this author.', 'babylovegrowth-integration'); ?></p>
				</div>

				<div class="blg-field">
					<label for="babylovegrowth_tags" class="blg-label"><?php echo esc_html__('Default Tags', 'babylovegrowth-integration'); ?></label>
					<select id="babylovegrowth_tags" name="babylovegrowth_tags[]" multiple size="10" style="width: 25em; height: 150px;">
						<?php if (empty($tags)) : ?>
							<option value="" disabled><?php echo esc_html__('No tags available', 'babylovegrowth-integration'); ?></option>
						<?php else : ?>
							<?php foreach ($tags as $tag) : ?>
								<option value="<?php echo esc_attr($tag->term_id); ?>" <?php selected(in_array($tag->term_id, $selected_tags)); ?>>
									<?php echo esc_html($tag->name); ?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
					<p class="blg-desc"><?php echo esc_html__('Hold Ctrl (or Cmd on Mac) to select multiple tags. All articles will be assigned these tags.', 'babylovegrowth-integration'); ?></p>
				</div>

				<div class="blg-field">
					<label for="babylovegrowth_feature_image_enabled" class="blg-label"><?php echo esc_html__('Remove double title and image', 'babylovegrowth-integration'); ?></label>
					<label>
						<input type="checkbox" id="babylovegrowth_feature_image_enabled" name="babylovegrowth_feature_image_enabled" value="1" <?php checked($feature_image_enabled, true); ?> />
						<?php echo esc_html__('Automatically remove double content (first title and image)', 'babylovegrowth-integration'); ?>
					</label>
					<p class="blg-desc"><?php echo esc_html__('This will automatically remove the first main heading and first image from the article text to prevent showing the same content twice.', 'babylovegrowth-integration'); ?></p>
				</div>

				<div class="blg-actions">
					<?php submit_button(__('Save WordPress Settings', 'babylovegrowth-integration')); ?>
				</div>
			</div>

			<div class="blg-card blg-video-card" style="margin-top:16px">
				<p class="blg-card-title"><?php echo esc_html__('Integration Tutorial', 'babylovegrowth-integration'); ?></p>
				<div class="blg-video">
					<iframe src="https://www.youtube.com/embed/5qIdz1L6FO8" title="<?php echo esc_attr__('BabyLoveGrowth Integration Tutorial', 'babylovegrowth-integration'); ?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
				</div>
			</div>
		</form>

		
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


