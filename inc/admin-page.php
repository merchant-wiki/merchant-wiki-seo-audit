<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin page for Merchant.WiKi SEO Audit
 * Renders a single Health block (no duplicates) and safe fallbacks if helpers are not loaded yet.
 */

add_action('admin_menu', function () {
	add_menu_page(
		__('Merchant.WiKi SEO Audit', 'merchant-wiki-audit'),
		'MW Audit',
		'manage_options',
		'mw-site-index-audit',
		'mw_audit_render_dashboard',
		'dashicons-search',
		58
	);
	add_submenu_page(
		'mw-site-index-audit',
		__('Dashboard', 'merchant-wiki-audit'),
		__('Dashboard', 'merchant-wiki-audit'),
		'manage_options',
		'mw-site-index-audit',
		'mw_audit_render_dashboard'
	);
	add_submenu_page(
		'mw-site-index-audit',
		__('Operations', 'merchant-wiki-audit'),
		__('Operations', 'merchant-wiki-audit'),
		'manage_options',
		'mw-site-index-operations',
		'mw_audit_render_operations'
	);
	add_submenu_page(
		'mw-site-index-audit',
		__('Reports', 'merchant-wiki-audit'),
		__('Reports', 'merchant-wiki-audit'),
		'manage_options',
		'mw-site-index-reports',
		'mw_audit_render_reports'
	);
	add_submenu_page(
		'mw-site-index-audit',
		__('Settings', 'merchant-wiki-audit'),
		__('Settings', 'merchant-wiki-audit'),
		'manage_options',
		'mw-site-index-settings',
		'mw_audit_render_settings'
	);
});

/** Return a safe empty health snapshot to avoid fatals if helper not loaded */
function mw_audit_empty_health() {
	return array(
		'loopback' => 'OK',
		'robots' => 'OK',
		'inventory_rows' => 0,
		'status_rows' => 0,
		'pc_rows' => 0,
		'last_inv_detected' => 0,
		'last_update' => '',
		'sitemap_cache_count' => 0,
		'sitemap_cache_age' => null,
		'sitemaps' => array(),
		'db_prefix' => $GLOBALS['wpdb']->prefix ?? 'wp_',
		'tables' => array(
			'inv' => ($GLOBALS['wpdb']->prefix ?? 'wp_') . 'mw_url_inventory',
			'st'  => ($GLOBALS['wpdb']->prefix ?? 'wp_') . 'mw_url_status',
			'pc'  => ($GLOBALS['wpdb']->prefix ?? 'wp_') . 'mw_post_primary_category',
		),
		'last_error' => '',
		'last_queue' => '',
		'drop_on_uninstall' => get_option('mw_audit_drop_on_uninstall') === 'yes' ? 'YES' : 'NO',
		'schema' => array(
			'ok' => true,
			'tables' => array(
				'inv' => array('table'=>'','exists'=>true,'columns_ok'=>true,'indexes_ok'=>true,'can_select'=>true,'can_write'=>true,'issues'=>array()),
				'st'  => array('table'=>'','exists'=>true,'columns_ok'=>true,'indexes_ok'=>true,'can_select'=>true,'can_write'=>true,'issues'=>array()),
				'pc'  => array('table'=>'','exists'=>true,'columns_ok'=>true,'indexes_ok'=>true,'can_select'=>true,'can_write'=>true,'issues'=>array()),
			),
		),
		'steps' => array(
			'inv'  => array('status' => ''), // rebuild inventory
			'sm'   => array('status' => ''), // sitemaps
			'os'   => array('status' => ''), // on-site signals
			'http' => array('status' => ''), // http-only
			'pc'   => array('status' => ''), // post->primary category
			'link' => array('status' => ''), // internal links
		),
	);
}

function mw_audit_stat_class($flag) {
	if ($flag === 'done')    return 'mw-done';
	if ($flag === 'running') return 'mw-running';
	if ($flag === 'fail')    return 'mw-fail';
	return '';
}

function mw_audit_render_dashboard() { mw_audit_render_page('dashboard'); }
function mw_audit_render_operations() { mw_audit_render_page('operations'); }
function mw_audit_render_reports() { mw_audit_render_page('reports'); }
function mw_audit_render_settings() { mw_audit_render_page('settings'); }
function mw_audit_render_admin() { mw_audit_render_page('dashboard'); }

/**
 * Helpers for sanitized query vars.
 */
function mw_audit_get_query_key($key, $default = '') {
	if (!isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $default;
	}
	return sanitize_key(wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

function mw_audit_get_query_text($key, $default = '') {
	if (!isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $default;
	}
	return sanitize_text_field(wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

function mw_audit_get_query_absint($key, $default = 0) {
	if (!isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $default;
	}
	return absint(wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

function mw_audit_get_query_bool($key) {
	if (!isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false;
	}
	$value = wp_unslash($_GET[$key]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if (is_string($value)) {
		$value = strtolower($value);
		return in_array($value, array('1', 'true', 'yes', 'on'), true);
	}
	return (bool) $value;
}

function mw_audit_render_page($view = 'dashboard') {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('Not allowed', 'merchant-wiki-audit'));
	}
	$page_raw = mw_audit_get_query_key('page');
	$current_page_slug = $page_raw ? $page_raw : 'mw-site-index-audit';

	// Health (safe)
	$h = (class_exists('MW_Audit_Health') && is_callable(array('MW_Audit_Health','get')))
		? MW_Audit_Health::get()
		: mw_audit_empty_health();

	// Sorting
	$order_raw = mw_audit_get_query_key('order');
	$order = $order_raw ? $order_raw : 'norm_url';
	$dir_raw = strtoupper(mw_audit_get_query_key('dir'));
	$dir   = ($dir_raw === 'DESC') ? 'DESC' : 'ASC';
	$toggle_dir = ($dir === 'ASC') ? 'DESC' : 'ASC';

	$status_filters = (class_exists('MW_Audit_Inventory') && method_exists('MW_Audit_Inventory','get_status_filters_from_request'))
		? MW_Audit_Inventory::get_status_filters_from_request()
		: array();
	$filter_likely = !empty($status_filters['only_likely']);
	$filter_stale  = !empty($status_filters['stale']);
	$filter_never  = !empty($status_filters['never']);
	$filter_new_hours = isset($status_filters['new_hours']) ? (int)$status_filters['new_hours'] : 0;
	if (!isset($status_filters['likely_states']) || !is_array($status_filters['likely_states'])) {
		$status_filters['likely_states'] = (class_exists('MW_Audit_GSC') && method_exists('MW_Audit_GSC','get_likely_not_indexed_reasons'))
			? MW_Audit_GSC::get_likely_not_indexed_reasons()
			: array();
	}
	$likely_states = $status_filters['likely_states'];

	// Rows preview (safe)
	if (class_exists('MW_Audit_DB') && is_callable(array('MW_Audit_DB','get_status_rows'))) {
		$rows = MW_Audit_DB::get_status_rows(100, 0, $order, $dir, $status_filters);
	} else {
		$rows = array();
	}

	$steps   = is_array($h['steps'] ?? null) ? $h['steps'] : array();
	$settings = MW_Audit_DB::get_settings();
	$gsc = $h['gsc'] ?? array('configured'=>false,'connected'=>false,'email'=>'','property'=>'');
	$gsc_sites = array();
	$gsc_auth_url = '';
	$gsc_sheets_auth_url = '';
	$gsc_has_sheets = false;
	$gsc_ttl_options = array(24,48,72,168);
	$gsc_ttl_hours = 48;
	if (class_exists('MW_Audit_GSC')) {
		if (method_exists('MW_Audit_GSC','allowed_ttl_hours')) {
			$gsc_ttl_options = MW_Audit_GSC::allowed_ttl_hours();
		}
		if (method_exists('MW_Audit_GSC','get_ttl_hours')) {
			$gsc_ttl_hours = MW_Audit_GSC::get_ttl_hours();
		}
	}
	if (!in_array($gsc_ttl_hours, $gsc_ttl_options, true)) {
		$gsc_ttl_options[] = $gsc_ttl_hours;
		sort($gsc_ttl_options);
	}
	if (class_exists('MW_Audit_GSC')) {
		if (MW_Audit_GSC::is_connected()) {
			$gsc_sites = MW_Audit_GSC::get_sites();
		}
		if (method_exists('MW_Audit_GSC','get_auth_url')) {
			$gsc_auth_url = MW_Audit_GSC::get_auth_url();
		}
		if (method_exists('MW_Audit_GSC','get_sheets_auth_url')) {
			$gsc_sheets_auth_url = MW_Audit_GSC::get_sheets_auth_url();
		}
		if (method_exists('MW_Audit_GSC','has_sheets_scope')) {
			$gsc_has_sheets = MW_Audit_GSC::has_sheets_scope();
		}
	}
	$gsc_metrics = is_array($h['gsc_metrics'] ?? null) ? $h['gsc_metrics'] : array();
	$gsc_export = $gsc_metrics['export'] ?? array();
	$gsc_api_metrics = $gsc_metrics['api'] ?? array();
	$gsc_inventory_total = isset($gsc_metrics['inventory_total']) ? (int) $gsc_metrics['inventory_total'] : (int) ($h['inventory_rows'] ?? 0);
	$gsc_stale_total = isset($gsc_metrics['stale_total']) ? (int) $gsc_metrics['stale_total'] : 0;
	$gsc_queue_candidates = isset($gsc_metrics['queue_candidates']) ? (int) $gsc_metrics['queue_candidates'] : 0;
	$gsc_stale_after_hint = max(0, $gsc_stale_total - min($gsc_stale_total, $gsc_queue_candidates));
	$gsc_export_ratio_pct = isset($gsc_export['ratio']) ? (int) round(min(1, max(0, $gsc_export['ratio'])) * 100) : 0;
	$gsc_api_ratio_pct = isset($gsc_api_metrics['ratio']) ? (int) round(min(1, max(0, $gsc_api_metrics['ratio'])) * 100) : 0;
	$gsc_export_state = $gsc_export['state'] ?? 'neutral';
	$gsc_api_state = $gsc_api_metrics['state'] ?? 'neutral';
	$gsc_show_api_pill = !empty($gsc_metrics['show_api_pill']);
	$pc_taxonomies = array();
	$current_pc_tax = 'category';
	if (class_exists('MW_Audit_Inventory')) {
		if (method_exists('MW_Audit_Inventory', 'get_available_pc_taxonomies')) {
			$pc_taxonomies = MW_Audit_Inventory::get_available_pc_taxonomies();
		}
		if (method_exists('MW_Audit_Inventory', 'get_primary_category_taxonomy')) {
			$current_pc_tax = MW_Audit_Inventory::get_primary_category_taxonomy();
		}
	}
	$priority_thresholds = method_exists('MW_Audit_DB','priority_thresholds') ? MW_Audit_DB::priority_thresholds() : array(0, 1, 2);
	if (!is_array($priority_thresholds) || empty($priority_thresholds)) {
		$priority_thresholds = array(0, 1, 2);
	}
	$priority_threshold_values = array_values($priority_thresholds);
	$priority_threshold = isset($priority_threshold_values[0]) ? (int) $priority_threshold_values[0] : 0;
	?>
	<div class="wrap mw-audit-wrap">
		<div class="notice notice-warning mw-beta-banner">
			<p>
				<?php echo esc_html__('Merchant.WiKi SEO Audit is currently in beta. Please run it on staging or a full backup and ensure files + database are backed up before launching the queues. Use at your own risk.', 'merchant-wiki-audit'); ?>
			</p>
		</div>
	<?php
		if (mw_audit_get_query_bool('settings_saved')) {
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Settings saved.', 'merchant-wiki-audit'));
		}
		$gsc_pi_import = mw_audit_get_query_key('gsc_pi_import', null);
		if ($gsc_pi_import !== null && $gsc_pi_import !== '') {
			$type = $gsc_pi_import;
			$msg_raw = mw_audit_get_query_text('msg', '');
			$import_msg = $msg_raw ? $msg_raw : '';
			if ($type === 'success') {
					$imported = mw_audit_get_query_absint('imported');
					$skipped  = mw_audit_get_query_absint('skipped');
					printf(
						'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
						esc_html(sprintf(
						/* translators: 1: number of imported rows, 2: number of skipped rows */
						__('Page indexing import completed: %1$d imported, %2$d skipped.','merchant-wiki-audit'),
						$imported,
						$skipped
					))
				);
			} elseif ($type === 'error' && $import_msg) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html($import_msg)
				);
			}
		}
		$snapshot_created = mw_audit_get_query_text('mw_snapshot_created', '');
		if ($snapshot_created !== '') {
			$label = $snapshot_created;
			$rows  = mw_audit_get_query_absint('mw_snapshot_rows');
		if ($rows) {
			$message = sprintf(
				/* translators: 1: snapshot label, 2: number of rows */
				__('Snapshot “%1$s” saved (%2$d rows).','merchant-wiki-audit'),
				$label,
				$rows
			);
		} else {
				$message = sprintf(
					/* translators: %s: snapshot label */
					__('Snapshot “%1$s” saved.','merchant-wiki-audit'),
					$label
				);
		}
		printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($message));
	}
		$snapshot_error = mw_audit_get_query_text('mw_snapshot_error', '');
		if ($snapshot_error !== '') {
			$err = $snapshot_error;
		printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($err));
	}
		?>
		<h1><?php echo esc_html__('Merchant.WiKi — Site Index Audit', 'merchant-wiki-audit'); ?></h1>

<?php if ($view === 'dashboard'): ?>
	<div class="mw-grid">

			<!-- HEALTH (single instance) -->
				<div class="mw-card">
					<h3><?php echo esc_html__('Health', 'merchant-wiki-audit'); ?></h3>
					<ul class="mw-kv">
						<li title="<?php echo esc_attr__('Confirms WordPress can reach its own admin-ajax endpoint for queues.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Loopback','merchant-wiki-audit'); ?></span>
							<b class="<?php echo ($h['loopback']==='OK'?'ok pill':'fail pill'); ?>"><?php echo esc_html($h['loopback']); ?></b>
						</li>
						<li title="<?php echo esc_attr__('Checks that robots.txt was fetched and parsed without blocking rules.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('robots.txt','merchant-wiki-audit'); ?></span>
							<b class="<?php echo ($h['robots']==='OK'?'ok pill':'fail pill'); ?>"><?php echo esc_html($h['robots']); ?></b>
						</li>
					<?php
						$gsc_state = 'neutral';
						$gsc_label = __('Not configured','merchant-wiki-audit');
						if (!empty($gsc['configured'])){
							$gsc_state = 'warn';
							$gsc_label = __('Configured, not connected','merchant-wiki-audit');
						}
						if (!empty($gsc['connected'])){
							$gsc_state = 'ok';
							$email = !empty($gsc['email']) ? $gsc['email'] : __('account','merchant-wiki-audit');
							$site  = !empty($gsc['property']) ? $gsc['property'] : '';
							$gsc_label = $site ? sprintf('%s — %s', esc_html($email), esc_html($site)) : esc_html($email);
						}
					?>
						<li title="<?php echo esc_attr__('Shows whether the plugin is authenticated with GSC and which property is selected.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Google Search Console','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo esc_attr($gsc_state); ?>"><?php echo esc_html($gsc_label); ?></b>
						</li>
						<li title="<?php echo esc_attr__('Total URLs discovered during the last inventory rebuild.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Inventory rows','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo esc_attr($h['pills']['inventory'] ?? 'neutral'); ?>"><?php echo esc_html($h['inventory_rows']); ?></b>
						</li>
						<li title="<?php echo esc_attr__('URLs with on-site HTTP/sitemap signals populated.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Status rows','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo esc_attr($h['pills']['status'] ?? 'neutral'); ?>"><?php echo esc_html($h['status_rows']); ?></b>
						</li>
						<li title="<?php echo esc_attr__('Posts that already have a resolved primary category relationship.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Post→Primary Category map','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo esc_attr($h['pills']['pcmap'] ?? 'neutral'); ?>"><?php echo esc_html($h['pc_rows']); ?></b>
						</li>
						<li title="<?php echo esc_attr__('How many URLs the previous rebuild detected (used to spot large delta).','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Inventory detected last run','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo esc_attr($h['pills']['inv_last'] ?? 'neutral'); ?>"><?php echo esc_html($h['last_inv_detected'] ?? 0); ?></b>
						</li>
						<li title="<?php echo esc_attr__('Timestamp of the latest on-site signals refresh job.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Last status update','merchant-wiki-audit'); ?></span>
							<b class="pill neutral"><?php echo $h['last_update'] ? esc_html($h['last_update']) : '—'; ?></b>
						</li>
						<li title="<?php echo esc_attr__('Number of sitemap files cached and how old the cache is.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Sitemap cache','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo ($h['sitemap_cache_count']>0 ? 'ok' : 'neutral'); ?>">
								<?php
								echo ($h['sitemap_cache_count']>0)
									? esc_html($h['sitemap_cache_count'].' files, age '.intval($h['sitemap_cache_age']).'s')
									: '—';
								?>
							</b>
						</li>
						<li title="<?php echo esc_attr__('Share of URLs that have fresh Page Indexing export data.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('GSC coverage (export)','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo esc_attr($gsc_export_state); ?>"><?php echo esc_html(sprintf('%d%%', $gsc_export_ratio_pct)); ?></b>
						</li>
						<?php if ($gsc_show_api_pill): ?>
						<li title="<?php echo esc_attr__('Share of URLs backed by fresh Inspection API responses.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('GSC coverage (API)','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo esc_attr($gsc_api_state); ?>"><?php echo esc_html(sprintf('%d%%', $gsc_api_ratio_pct)); ?></b>
						</li>
						<?php endif; ?>
					</ul>

				<h4 style="margin-top:10px"><?php echo esc_html__('Step statuses','merchant-wiki-audit'); ?></h4>
					<ul class="mw-kv">
						<li id="mw-step-sm" class="<?php echo esc_attr(mw_audit_stat_class($steps['sm']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Downloads sitemap index/files so later steps can iterate URLs quickly.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Prepare/Cache Sitemaps','merchant-wiki-audit'); ?></span>
							<b id="mw-step-pill-sm" class="pill <?php echo (($steps['sm']['status'] ?? '')==='done'?'ok':(($steps['sm']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
								<?php echo esc_html($steps['sm']['status'] ?? '—'); ?>
							</b>
						</li>
						<li class="<?php echo esc_attr(mw_audit_stat_class($steps['os']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Pulls canonical, meta robots, sitemap inclusion, and schema hints from the site.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Refresh On-Site Signals','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo (($steps['os']['status'] ?? '')==='done'?'ok':(($steps['os']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
								<?php echo esc_html($steps['os']['status'] ?? '—'); ?>
							</b>
						</li>
						<li class="<?php echo esc_attr(mw_audit_stat_class($steps['http']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Runs HEAD/GET checks to confirm HTTP status, redirects, and response times.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('HTTP-only Signals','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo (($steps['http']['status'] ?? '')==='done'?'ok':(($steps['http']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
								<?php echo esc_html($steps['http']['status'] ?? '—'); ?>
							</b>
						</li>
						<li class="<?php echo esc_attr(mw_audit_stat_class($steps['pc']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Assigns each post to a primary taxonomy term for prioritization.','merchant-wiki-audit'); ?>">
							<span><?php echo esc_html__('Post → Primary Category Map','merchant-wiki-audit'); ?></span>
							<b class="pill <?php echo (($steps['pc']['status'] ?? '')==='done'?'ok':(($steps['pc']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
								<?php echo esc_html($steps['pc']['status'] ?? '—'); ?>
							</b>
						</li>
					<li class="<?php echo esc_attr(mw_audit_stat_class($steps['link']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Counts inbound internal links so you can spot orphan or weak pages.','merchant-wiki-audit'); ?>">
						<span><?php echo esc_html__('Internal Link Scan','merchant-wiki-audit'); ?></span>
						<b class="pill <?php echo (($steps['link']['status'] ?? '')==='done'?'ok':(($steps['link']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
							<?php echo esc_html($steps['link']['status'] ?? '—'); ?>
						</b>
					</li>
					<li class="<?php echo esc_attr(mw_audit_stat_class($steps['outbound']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Scans your pages and counts outbound internal/external links plus unique external domains.','merchant-wiki-audit'); ?>">
						<span><?php echo esc_html__('Outbound Link Scan','merchant-wiki-audit'); ?></span>
						<b class="pill <?php echo (($steps['outbound']['status'] ?? '')==='done'?'ok':(($steps['outbound']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
							<?php echo esc_html($steps['outbound']['status'] ?? '—'); ?>
						</b>
					</li>
					<li id="mw-step-pi" class="<?php echo esc_attr(mw_audit_stat_class($steps['pi']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Imports the Page Indexing CSV export from Search Console into the cache.','merchant-wiki-audit'); ?>">
						<span><?php echo esc_html__('Page Indexing Import','merchant-wiki-audit'); ?></span>
						<b class="pill <?php echo (($steps['pi']['status'] ?? '')==='done'?'ok':(($steps['pi']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
							<?php echo esc_html($steps['pi']['status'] ?? '—'); ?>
						</b>
					</li>
					<li class="<?php echo esc_attr(mw_audit_stat_class($steps['gindex']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Queues Inspection API calls for stale URLs and caches fresh verdicts.','merchant-wiki-audit'); ?>">
						<span><?php echo esc_html__('Google Index Status','merchant-wiki-audit'); ?></span>
						<b class="pill <?php echo (($steps['gindex']['status'] ?? '')==='done'?'ok':(($steps['gindex']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
							<?php echo esc_html($steps['gindex']['status'] ?? '—'); ?>
						</b>
						</li>
					</ul>
			</div>

	<?php
		$export_fresh = (int) ($gsc_export['fresh'] ?? 0);
		$export_display = $gsc_inventory_total > 0
			? sprintf('%d/%d (%d%%)', min($export_fresh, $gsc_inventory_total), $gsc_inventory_total, $gsc_export_ratio_pct)
			: sprintf('%d', $export_fresh);
		$api_fresh = (int) ($gsc_api_metrics['fresh'] ?? 0);
		$api_display = $gsc_inventory_total > 0
			? sprintf('%d/%d (%d%%)', min($api_fresh, $gsc_inventory_total), $gsc_inventory_total, $gsc_api_ratio_pct)
			: sprintf('%d', $api_fresh);
		$queue_length = isset($gsc_metrics['queue_length']) ? (int) $gsc_metrics['queue_length'] : 0;
		$queue_speed = isset($gsc_metrics['urls_per_min']) ? (float) $gsc_metrics['urls_per_min'] : 0.0;
		$queue_eta = isset($gsc_metrics['eta_seconds']) ? (int) $gsc_metrics['eta_seconds'] : 0;
		$format_eta = function($seconds) {
			$seconds = (int) $seconds;
			if ($seconds <= 0) return __('~0 min','merchant-wiki-audit');
			if ($seconds < 120) {
				return sprintf(
					/* translators: %d: number of seconds */
					__('%d s','merchant-wiki-audit'),
					$seconds
				);
			}
			if ($seconds < 7200) {
				return sprintf(
					/* translators: %d: number of minutes */
					__('%d min','merchant-wiki-audit'),
					round($seconds / 60)
				);
			}
			return sprintf(
				/* translators: %d: number of hours */
				__('%d h','merchant-wiki-audit'),
				round($seconds / 3600)
			);
		};
		$queue_label = $queue_length
			? sprintf(
				/* translators: %d: number of pending URLs */
				__('%d pending','merchant-wiki-audit'),
				$queue_length
			)
			: __('Idle','merchant-wiki-audit');
		if ($queue_length && $queue_speed > 0) {
			$queue_label .= sprintf(' · %.1f/min', $queue_speed);
		}
		if ($queue_length && $queue_eta > 0) {
			$queue_label .= ' · '.$format_eta($queue_eta);
		}
		$queue_class = $queue_length ? 'warn' : 'ok';
		if (empty($settings['gsc_api_enabled'])) {
			$queue_label = __('Disabled','merchant-wiki-audit');
			$queue_class = 'neutral';
		}
		$quota_used = isset($gsc_metrics['quota_used']) ? (int) $gsc_metrics['quota_used'] : 0;
		$quota_limit = isset($gsc_metrics['quota_limit']) ? (int) $gsc_metrics['quota_limit'] : 0;
		$error_percent = isset($gsc_metrics['error_percent']) ? (float) $gsc_metrics['error_percent'] : 0.0;
		$quota_label = $quota_limit > 0
			? sprintf('%d / %d (%.0f%%)', $quota_used, $quota_limit, $quota_limit ? ($quota_used / max(1,$quota_limit))*100 : 0)
			: sprintf('%d', $quota_used);
		if ($error_percent > 0) {
			$quota_label .= sprintf(' · %.0f%% %s', $error_percent, __('errors','merchant-wiki-audit'));
		}
		$quota_class = 'neutral';
		if ($quota_limit > 0) {
			if ($quota_used >= $quota_limit) {
				$quota_class = 'fail';
			} elseif ($quota_used >= 0.75 * $quota_limit || $error_percent > 10) {
				$quota_class = 'warn';
			} else {
				$quota_class = 'ok';
			}
		}
		$last_error = isset($gsc_metrics['last_error']) ? trim((string) $gsc_metrics['last_error']) : '';
	?>
	<div class="mw-card">
		<h3><?php echo esc_html__('Google Search Console API', 'merchant-wiki-audit'); ?></h3>
		<ul class="mw-kv">
			<li title="<?php echo esc_attr__('Fresh Page Indexing export rows compared to total inventory.','merchant-wiki-audit'); ?>">
				<span><?php echo esc_html__('Export coverage','merchant-wiki-audit'); ?></span>
				<b class="pill <?php echo esc_attr($gsc_export_state); ?>"><?php echo esc_html($export_display); ?></b>
			</li>
			<?php if ($gsc_show_api_pill): ?>
			<li title="<?php echo esc_attr__('Fresh Inspection API responses compared to total inventory.','merchant-wiki-audit'); ?>">
				<span><?php echo esc_html__('API coverage','merchant-wiki-audit'); ?></span>
				<b class="pill <?php echo esc_attr($gsc_api_state); ?>"><?php echo esc_html($api_display); ?></b>
			</li>
			<?php endif; ?>
			<li title="<?php echo esc_attr__('URLs whose Inspection API TTL expired or was never fetched.','merchant-wiki-audit'); ?>">
				<span><?php echo esc_html__('Stale URLs (Inspection)','merchant-wiki-audit'); ?></span>
				<b class="pill <?php echo $gsc_stale_total ? 'warn' : 'ok'; ?>"><?php echo esc_html($gsc_stale_total); ?></b>
			</li>
			<li title="<?php echo esc_attr__('Pending URLs in the Inspection queue plus throughput/ETA.','merchant-wiki-audit'); ?>">
				<span><?php echo esc_html__('Queue','merchant-wiki-audit'); ?></span>
				<b class="pill <?php echo esc_attr($queue_class); ?>"><?php echo esc_html($queue_label); ?></b>
			</li>
			<li title="<?php echo esc_attr__('Number of Inspection API calls logged today versus the daily limit.','merchant-wiki-audit'); ?>">
				<span><?php echo esc_html__('Quota today','merchant-wiki-audit'); ?></span>
				<b class="pill <?php echo esc_attr($quota_class); ?>"><?php echo esc_html($quota_label); ?></b>
			</li>
			<li title="<?php echo esc_attr__('Shows whether Google Sheets export credentials are active.','merchant-wiki-audit'); ?>">
				<span><?php echo esc_html__('Sheets API','merchant-wiki-audit'); ?></span>
				<b class="pill <?php echo $gsc_has_sheets ? 'ok' : 'warn'; ?>"><?php echo $gsc_has_sheets ? esc_html__('Enabled','merchant-wiki-audit') : esc_html__('Not connected','merchant-wiki-audit'); ?></b>
			</li>
			<li title="<?php echo esc_attr__('Most recent Inspection API or queue error message.','merchant-wiki-audit'); ?>">
				<span><?php echo esc_html__('Last error','merchant-wiki-audit'); ?></span>
				<b class="pill <?php echo $last_error ? 'fail' : 'neutral'; ?>"><?php echo $last_error ? esc_html($last_error) : '—'; ?></b>
			</li>
		</ul>
		<?php if (empty($settings['gsc_api_enabled'])): ?>
			<p class="description"><?php echo esc_html__('Inspection API checks are disabled in settings. Enable them below to run spot checks.', 'merchant-wiki-audit'); ?></p>
		<?php endif; ?>
	</div>

			<!-- DB Structure -->
	<?php
		$db_prefix = is_string($h['db_prefix'] ?? null) ? $h['db_prefix'] : ($GLOBALS['wpdb']->prefix ?? 'wp_');
		$schema_tables = is_array($h['schema']['tables'] ?? null) ? $h['schema']['tables'] : array();
		$last_queue_info = is_array($h['last_queue'] ?? null) ? $h['last_queue'] : array();
	?>
	<div class="mw-card">
		<h3><?php echo esc_html__('DB Structure Check','merchant-wiki-audit'); ?></h3>
		<?php $s = is_array($h['schema'] ?? null) ? $h['schema'] : array('ok'=>true,'tables'=>array()); ?>
		<div class="mw-kv">
			<div style="margin-bottom:6px">
				<span><?php echo esc_html__('Total','merchant-wiki-audit'); ?></span>
						<b class="pill <?php echo !empty($s['ok']) ? 'ok':'fail'; ?>"><?php echo !empty($s['ok']) ? 'OK' : 'FAIL'; ?></b>
					</div>
					<?php
						$labels = array('inv'=>'Inventory','st'=>'Status','pc'=>'Post→Primary Category');
						if (!empty($s['tables']) && is_array($s['tables'])):
							foreach ($s['tables'] as $key => $t):
							$exists = !empty($t['exists']);
							$columns_ok = !empty($t['columns_ok']);
							$indexes_ok = !empty($t['indexes_ok']);
							$can_select = !empty($t['can_select']);
							$can_write  = !empty($t['can_write']);
							$ok_all = ($exists && $columns_ok && $indexes_ok && $can_select && $can_write);
					?>
					<details style="margin-top:6px" <?php echo $ok_all ? '' : 'open'; ?>>
						<summary>
							<b><?php echo esc_html($labels[$key] ?? $key); ?></b>
							— <?php echo esc_html($t['table'] ?? ''); ?>
							<?php
							$bits = array();
							$bits[] = $exists      ? 'exists'   : 'missing';
							$bits[] = $columns_ok  ? 'columns✓' : 'columns!';
							$bits[] = $indexes_ok  ? 'indexes✓' : 'indexes!';
							$bits[] = $can_select  ? 'SELECT✓'  : 'SELECT!';
							$bits[] = $can_write   ? 'WRITE✓'   : 'WRITE!';
							echo ' <span class="'.($ok_all ? 'ok' : 'fail').'">['.esc_html(implode(', ', $bits)).']</span>';
							?>
						</summary>
						<?php if (!empty($t['issues'])): ?>
							<ul class="mw-list" style="margin-top:6px">
								<?php foreach ((array)$t['issues'] as $iss): ?>
									<li><?php echo esc_html($iss); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else: ?>
							<div><?php echo esc_html__('No issues.', 'merchant-wiki-audit'); ?></div>
						<?php endif; ?>
					</details>
					<?php
						endforeach;
					endif;
					?>
				</div>
			</div>

			<!-- Sitemaps list -->
			<div class="mw-card">
				<h3><?php echo esc_html__('Sitemaps detected','merchant-wiki-audit'); ?></h3>
				<ul id="mw-sitemaps-list" class="mw-list">
					<?php if (!empty($h['sitemaps'])): foreach ($h['sitemaps'] as $sm): ?>
						<li><a href="<?php echo esc_url($sm); ?>" target="_blank" rel="noopener"><?php echo esc_html($sm); ?></a></li>
					<?php endforeach; else: ?>
						<li>—</li>
					<?php endif; ?>
				</ul>
			</div>

			<!-- Debug -->
			<div class="mw-card">
				<h3><?php echo esc_html__('Debug','merchant-wiki-audit'); ?></h3>
				<ul class="mw-kv">
					<li><span><?php echo esc_html__('DB prefix','merchant-wiki-audit'); ?></span><b class="pill neutral"><?php echo esc_html($db_prefix); ?></b></li>
					<li><span><?php echo esc_html__('Inventory table','merchant-wiki-audit'); ?></span><b class="pill neutral"><?php echo esc_html($schema_tables['inv']['table'] ?? ($h['tables']['inv'] ?? '—')); ?></b></li>
					<li><span><?php echo esc_html__('Status table','merchant-wiki-audit'); ?></span><b class="pill neutral"><?php echo esc_html($schema_tables['st']['table'] ?? ($h['tables']['st'] ?? '—')); ?></b></li>
					<li><span><?php echo esc_html__('PC map table','merchant-wiki-audit'); ?></span><b class="pill neutral"><?php echo esc_html($schema_tables['pc']['table'] ?? ($h['tables']['pc'] ?? '—')); ?></b></li>
					<li><span><?php echo esc_html__('WPDB last error','merchant-wiki-audit'); ?></span><b class="pill neutral"><?php echo !empty($h['last_error']) ? esc_html($h['last_error']) : '—'; ?></b></li>
					<li><span><?php echo esc_html__('Queue','merchant-wiki-audit'); ?></span><b class="pill neutral"><?php echo esc_html(is_string($h['last_queue'] ?? null) ? $h['last_queue'] : '—'); ?></b></li>
					<li><span><?php echo esc_html__('Drop tables on uninstall','merchant-wiki-audit'); ?></span><b class="pill neutral"><?php echo esc_html($h['drop_on_uninstall'] ?? 'NO'); ?></b></li>
				</ul>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('mw_audit_selftest', 'mw_audit_selftest_nonce'); ?>
					<input type="hidden" name="action" value="mw_audit_selftest">
					<button class="button"><?php echo esc_html__('Self-Test (INSERT/SELECT)','merchant-wiki-audit'); ?></button>
				</form>
			</div>

		</div><!-- /.mw-grid -->
	<?php endif; ?>

	<?php if ($view === 'operations'): ?>
		<!-- ACTIONS / BLOCKS -->
		<div class="mw-actions">

			<!-- Rebuild Inventory -->
			<div id="mw-inventory-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['inv']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('Rebuild Inventory','merchant-wiki-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Collects all public URLs. Required before any audit.','merchant-wiki-audit'); ?></p>
				<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
				<div class="stats">
					<span><?php echo esc_html__('Done:','merchant-wiki-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
					<span><?php echo esc_html__('Errors:','merchant-wiki-audit'); ?> <b class="errors pill neutral">0</b></span>
					<span><?php echo esc_html__('Progress:','merchant-wiki-audit'); ?> <b class="percent pill neutral">0%</b></span>
					<span><?php echo esc_html__('Phase:','merchant-wiki-audit'); ?> <b class="phase pill neutral">—</b></span>
					<span>ETA: <b class="eta pill neutral">—</b></span>
					<span>Batch: <b class="batch pill neutral">—</b></span>
				</div>
				<p class="actions">
					<button class="button start"><?php echo esc_html__('Start','merchant-wiki-audit'); ?></button>
					<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-audit'); ?></button>
					<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-audit'); ?></button>
				</p>
			</div>

			<!-- Prepare / Cache Sitemaps -->
			<div id="mw-sitemaps" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['sm']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('Prepare / Cache Sitemaps','merchant-wiki-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Downloads sitemap(s) to detect which URLs are declared for indexing.','merchant-wiki-audit'); ?></p>
				<div class="stats">
					<span><?php echo esc_html__('Files:','merchant-wiki-audit'); ?> <b class="sm-count pill neutral"><?php echo intval($h['sitemap_cache_count']); ?></b></span>
					<span><?php echo esc_html__('Sources:','merchant-wiki-audit'); ?> <b class="sm-sources pill neutral"><?php echo !empty($h['sitemaps']) ? count($h['sitemaps']) : 0; ?></b></span>
					<span><?php echo esc_html__('Age:','merchant-wiki-audit'); ?> <b class="sm-age pill neutral"><?php echo $h['sitemap_cache_age']!==null ? intval($h['sitemap_cache_age']).'s' : '—'; ?></b></span>
				</div>
				<p class="actions"><button class="button button-primary sm-prepare"><?php echo esc_html__('Prepare now','merchant-wiki-audit'); ?></button></p>
			</div>

			<!-- Refresh On-Site Signals — AJAX -->
			<div id="mw-audit-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['os']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('Refresh On-Site Signals','merchant-wiki-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Checks HTTP status, robots, canonical, noindex, schema — fix technical SEO issues before indexing.','merchant-wiki-audit'); ?></p>
				<label style="display:block;margin:6px 0 8px">
					<?php echo esc_html__('Profile:','merchant-wiki-audit'); ?>
					<select class="profile">
						<option value="fast" <?php selected($settings['profile_defaults'], 'fast'); ?>><?php echo esc_html__('Fast','merchant-wiki-audit'); ?></option>
						<option value="standard" <?php selected($settings['profile_defaults'], 'standard'); ?>><?php echo esc_html__('Standard','merchant-wiki-audit'); ?></option>
						<option value="safe" <?php selected($settings['profile_defaults'], 'safe'); ?>><?php echo esc_html__('Safe','merchant-wiki-audit'); ?></option>
					</select>
					<small class="muted"><?php echo esc_html__('Auto-tunes batch/budget/timeouts using EWMA per-URL time.','merchant-wiki-audit'); ?></small>
				</label>
				<label><input type="checkbox" class="also-pc" checked> <?php echo esc_html__('Also rebuild Post → Primary Category Map','merchant-wiki-audit'); ?></label>
				<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
				<div class="stats">
					<span><?php echo esc_html__('Done:','merchant-wiki-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
					<span><?php echo esc_html__('Errors:','merchant-wiki-audit'); ?> <b class="errors pill neutral">0</b></span>
					<span><?php echo esc_html__('Progress:','merchant-wiki-audit'); ?> <b class="percent pill neutral">0%</b></span>
					<span>ETA: <b class="eta pill neutral">—</b></span>
					<span>Batch: <b class="batch pill neutral">—</b></span>
				</div>
				<p class="actions">
					<button class="button button-primary start"><?php echo esc_html__('Start','merchant-wiki-audit'); ?></button>
					<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-audit'); ?></button>
					<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-audit'); ?></button>
				</p>
			</div>

			<!-- HTTP-only -->
			<div id="mw-http-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['http']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('HTTP-only Signals','merchant-wiki-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Lightweight HTTP check (no internal links). Use for quick status update.','merchant-wiki-audit'); ?></p>
				<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
				<div class="stats">
					<span><?php echo esc_html__('Done:','merchant-wiki-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
					<span><?php echo esc_html__('Errors:','merchant-wiki-audit'); ?> <b class="errors pill neutral">0</b></span>
					<span><?php echo esc_html__('Progress:','merchant-wiki-audit'); ?> <b class="percent pill neutral">0%</b></span>
					<span>ETA: <b class="eta pill neutral">—</b></span>
					<span>Batch: <b class="batch pill neutral">—</b></span>
				</div>
				<p class="actions">
					<button class="button start"><?php echo esc_html__('Start','merchant-wiki-audit'); ?></button>
					<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-audit'); ?></button>
					<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-audit'); ?></button>
				</p>
			</div>

			<!-- PC map -->
			<div id="mw-pc-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['pc']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('Post → Primary Category Map','merchant-wiki-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Resolves main category per post (for content grouping).','merchant-wiki-audit'); ?></p>
				<?php if ($pc_taxonomies): ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-inline-form" style="margin-bottom:10px;">
						<?php wp_nonce_field('mw_audit_pc_tax', 'mw_audit_pc_tax_nonce'); ?>
						<input type="hidden" name="action" value="mw_audit_save_pc_tax">
						<label>
							<?php echo esc_html__('Primary taxonomy','merchant-wiki-audit'); ?>:
							<select name="pc_taxonomy">
								<?php foreach ($pc_taxonomies as $slug => $label): ?>
									<option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $current_pc_tax); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button class="button button-small"><?php echo esc_html__('Save','merchant-wiki-audit'); ?></button>
					</form>
				<?php endif; ?>
				<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
				<div class="stats">
					<span><?php echo esc_html__('Done:','merchant-wiki-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
					<span><?php echo esc_html__('Errors:','merchant-wiki-audit'); ?> <b class="errors pill neutral">0</b></span>
					<span><?php echo esc_html__('Progress:','merchant-wiki-audit'); ?> <b class="percent pill neutral">0%</b></span>
					<span>ETA: <b class="eta pill neutral">—</b></span>
				</div>
				<p class="actions">
					<button class="button start"><?php echo esc_html__('Build Map','merchant-wiki-audit'); ?></button>
					<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-audit'); ?></button>
					<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-audit'); ?></button>
				</p>
			</div>

				<!-- Internal links -->
				<div id="mw-links-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['link']['status'] ?? '')); ?>">
					<h3><?php echo esc_html__('Internal Link Scan','merchant-wiki-audit'); ?></h3>
					<p class="mw-box-hint"><?php echo esc_html__('Counts inbound internal links — pages with 0 links are hard to index.','merchant-wiki-audit'); ?></p>
					<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
					<div class="stats">
						<span><?php echo esc_html__('Done:','merchant-wiki-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
						<span><?php echo esc_html__('Errors:','merchant-wiki-audit'); ?> <b class="errors pill neutral">0</b></span>
						<span><?php echo esc_html__('Progress:','merchant-wiki-audit'); ?> <b class="percent pill neutral">0%</b></span>
						<span>ETA: <b class="eta pill neutral">—</b></span>
						<span>Batch: <b class="batch pill neutral">—</b></span>
					</div>
					<p class="actions">
						<button class="button start"><?php echo esc_html__('Start','merchant-wiki-audit'); ?></button>
						<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-audit'); ?></button>
						<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-audit'); ?></button>
					</p>
				</div>

				<!-- Outbound links -->
				<div id="mw-outbound-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['outbound']['status'] ?? '')); ?>">
					<h3><?php echo esc_html__('Outbound Link Scan','merchant-wiki-audit'); ?></h3>
					<p class="mw-box-hint"><?php echo esc_html__('Counts outbound links per page (internal vs external) and unique external domains. Helpful for spotting “dead-end” pages or excessive external linking.','merchant-wiki-audit'); ?></p>
					<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
					<div class="stats">
						<span><?php echo esc_html__('Done:','merchant-wiki-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
						<span><?php echo esc_html__('Errors:','merchant-wiki-audit'); ?> <b class="errors pill neutral">0</b></span>
						<span><?php echo esc_html__('Progress:','merchant-wiki-audit'); ?> <b class="percent pill neutral">0%</b></span>
						<span>ETA: <b class="eta pill neutral">—</b></span>
						<span>Batch: <b class="batch pill neutral">—</b></span>
					</div>
					<p class="actions">
						<button class="button start"><?php echo esc_html__('Start','merchant-wiki-audit'); ?></button>
						<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-audit'); ?></button>
						<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-audit'); ?></button>
					</p>
					<p class="description"><?php echo esc_html__('No external quotas involved. Batch size adapts the same way as the internal link scan.','merchant-wiki-audit'); ?></p>
				</div>

				<!-- Google index -->
				<?php $gindex_enabled = (!empty($gsc['connected']) && !empty($gsc['property']) && !empty($settings['gsc_api_enabled'])); ?>
				<div id="mw-gindex-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['gindex']['status'] ?? '')); ?>"
					data-stale-total="<?php echo esc_attr($gsc_stale_total); ?>"
					data-queued-total="<?php echo esc_attr($gsc_queue_candidates); ?>"
					data-stale-remaining="<?php echo esc_attr($gsc_stale_after_hint); ?>">
				<h3><?php echo esc_html__('Check Google Index Status','merchant-wiki-audit'); ?></h3>
					<p class="mw-box-hint"><?php
						$settings_link = esc_url(admin_url('admin.php?page=mw-site-index-settings#mw-gsc-settings'));
						echo wp_kses_post(
							sprintf(
								/* translators: 1: explanatory text, 2: settings URL, 3: link label */
								'%1$s <a href="%2$s">%3$s</a>',
								esc_html__('Connect Google Search Console in Settings → Google Search Console to enable this block.','merchant-wiki-audit'),
								$settings_link,
								esc_html__('Open settings','merchant-wiki-audit')
							)
						);
					?></p>
				<p class="mw-box-hint"><?php echo esc_html__('Fetches indexation status from Google Search Console (cached). Use to find "not indexed" pages.','merchant-wiki-audit'); ?></p>
				<?php if (!$gindex_enabled): ?>
					<p class="description" style="color:#d63638;"><?php
						if (empty($gsc['connected']) || empty($gsc['property'])) {
							echo esc_html__('Connect Google Search Console and select a property before running this step.','merchant-wiki-audit');
						} else {
							echo esc_html__('Enable the Inspection API in settings to run this step.','merchant-wiki-audit');
						}
					?></p>
				<?php endif; ?>
					<label style="display:block; margin:6px 0;">
						<?php echo esc_html__('Batch size','merchant-wiki-audit'); ?>
						<input type="number" class="small-text batch-input" value="5" min="1" max="100">
					</label>
					<label style="display:block; margin:6px 0;">
						<input type="checkbox" class="only-stale" checked> <?php echo esc_html__('Only queue stale/new URLs','merchant-wiki-audit'); ?>
					</label>
					<p class="description" style="margin-top:-4px;"><?php echo esc_html__('Uncheck to force a full refresh (uses more quota).','merchant-wiki-audit'); ?></p>
					<ul class="mw-gindex-estimate">
						<li>
							<span><?php echo esc_html__('Queued this run','merchant-wiki-audit'); ?></span>
							<b id="mw-gindex-queued" data-default="<?php echo esc_attr($gsc_queue_candidates); ?>"><?php echo esc_html($gsc_queue_candidates); ?></b>
						</li>
						<li>
							<span><?php echo esc_html__('Stale overall','merchant-wiki-audit'); ?></span>
							<b id="mw-gindex-stale" data-default="<?php echo esc_attr($gsc_stale_total); ?>"><?php echo esc_html($gsc_stale_total); ?></b>
						</li>
						<li>
							<span><?php echo esc_html__('Likely remaining after this run','merchant-wiki-audit'); ?></span>
							<b id="mw-gindex-remaining" data-default="<?php echo esc_attr($gsc_stale_after_hint); ?>"><?php echo esc_html($gsc_stale_after_hint); ?></b>
						</li>
					</ul>
					<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
					<div class="stats">
						<span><?php echo esc_html__('Done:','merchant-wiki-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
						<span><?php echo esc_html__('Errors:','merchant-wiki-audit'); ?> <b class="errors pill neutral">0</b></span>
						<span><?php echo esc_html__('Progress:','merchant-wiki-audit'); ?> <b class="percent pill neutral">0%</b></span>
						<span>ETA: <b class="eta pill neutral">—</b></span>
						<span>Batch: <b class="batch pill neutral">—</b></span>
					</div>
						<p class="actions">
							<button class="button start" <?php disabled(!$gindex_enabled); ?>><?php echo esc_html__('Start','merchant-wiki-audit'); ?></button>
							<button class="button stop" <?php disabled(!$gindex_enabled); ?>><?php echo esc_html__('Stop','merchant-wiki-audit'); ?></button>
							<button class="button resume" <?php disabled(!$gindex_enabled); ?>><?php echo esc_html__('Resume','merchant-wiki-audit'); ?></button>
							<button class="button reset-lock" <?php disabled(!$gindex_enabled); ?> title="<?php echo esc_attr__('Clear the Inspection queue lock if Start fails with “Request failed”.','merchant-wiki-audit'); ?>"><?php echo esc_html__('Clear lock','merchant-wiki-audit'); ?></button>
						</p>
					<p class="description"><?php echo esc_html__('The inspection API is subject to quota limits. Consider running during off-peak hours.','merchant-wiki-audit'); ?></p>
				</div>

		</div><!-- /.mw-actions -->
	<?php endif; ?>
	<?php if ($view === 'reports'): ?>
		<?php
			$next_steps_snapshots = class_exists('MW_Audit_Next_Steps') ? MW_Audit_Next_Steps::list_snapshots() : array();
			$next_steps_can_diff = count($next_steps_snapshots) >= 2;
			$next_steps_upload = wp_upload_dir();
			$next_steps_snapshot_url = empty($next_steps_upload['error']) ? trailingslashit($next_steps_upload['baseurl']).'mw-audit/' : '';
			$manual_queue_threshold = $priority_threshold;
			$refresh_defaults = array(
				'limit' => 12,
				'min_days_since_update' => 365,
				'min_days_since_publish' => 120,
				'post_types' => array('post','page'),
			);
				$refresh_mode = mw_audit_get_query_key('mw_refresh_mode', 'days365');
			$allowed_modes = array('days365','last_year','top5');
			if (!in_array($refresh_mode, $allowed_modes, true)) {
				$refresh_mode = 'days365';
			}
			$refresh_feed_args = apply_filters('mw_audit_refresh_ui_args', $refresh_defaults);
			if (!is_array($refresh_feed_args)) {
				$refresh_feed_args = $refresh_defaults;
			}
				$refresh_zero_only = mw_audit_get_query_bool('mw_refresh_zero_inbound');
			if ($refresh_zero_only) {
				$refresh_feed_args['only_zero_inbound'] = true;
			}
			$refresh_now_ts = current_time('timestamp');
			$current_year_ui = (int) (function_exists('wp_date') ? wp_date('Y', $refresh_now_ts) : date_i18n('Y', $refresh_now_ts));
			if ($refresh_mode === 'last_year') {
				$last_year = max(1970, $current_year_ui - 1);
				$refresh_feed_args['min_days_since_update'] = 0;
				$refresh_feed_args['min_days_since_publish'] = 0;
				$refresh_feed_args['modified_after'] = sprintf('%04d-01-01 00:00:00', $last_year);
				$refresh_feed_args['modified_before'] = sprintf('%04d-12-31 23:59:59', $last_year);
			} elseif ($refresh_mode === 'top5') {
				$refresh_feed_args['min_days_since_update'] = 0;
				$refresh_feed_args['min_days_since_publish'] = 0;
				$refresh_feed_args['limit'] = 5;
				$refresh_feed_args['modified_after'] = null;
				$refresh_feed_args['modified_before'] = null;
			} else {
				$refresh_feed_args['modified_after'] = null;
				$refresh_feed_args['modified_before'] = null;
			}
			$refresh_candidates = MW_Audit_DB::get_stale_content_candidates($refresh_feed_args);
			$refresh_min_days = isset($refresh_feed_args['min_days_since_update']) ? (int) $refresh_feed_args['min_days_since_update'] : (int) $refresh_defaults['min_days_since_update'];
		?>

		<p class="mw-report-nav">
			<?php echo esc_html__('Jump to:','merchant-wiki-audit'); ?>
			<a href="#mw-refresh-card"><?php echo esc_html__('Stale content refresh','merchant-wiki-audit'); ?></a> ·
			<a href="#mw-priority-block"><?php echo esc_html__('Priority: Ready to Submit','merchant-wiki-audit'); ?></a> ·
			<a href="#mw-report-preview"><?php echo esc_html__('Preview (first 100 rows)','merchant-wiki-audit'); ?></a> ·
			<a href="#" id="mw-similar-open" title="<?php echo esc_attr__('Pick a reference page and surface other URLs with the same age/index/link profile.','merchant-wiki-audit'); ?>"><?php echo esc_html__('Find similar URL','merchant-wiki-audit'); ?></a> ·
			<a href="#mw-outbound-summary"><?php echo esc_html__('Outbound Link Summary','merchant-wiki-audit'); ?></a>
		</p>

			<div class="mw-card mw-next-steps-card">
			<h3><?php echo esc_html__('Case automations (Next steps)','merchant-wiki-audit'); ?></h3>
			<p class="mw-report-hint"><?php echo esc_html__('Generate the artifacts mentioned in the README “Next steps” without leaving the Reports tab.','merchant-wiki-audit'); ?></p>
			<div class="mw-next-grid">
				<section class="mw-next-cell">
					<h4><?php echo esc_html__('Quick technical audit','merchant-wiki-audit'); ?></h4>
					<p><?php echo esc_html__('Download blockers grouped by HTTP errors, sitemap gaps, and noindex flags for ticketing.','merchant-wiki-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('mw_next_steps_quick'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_quick">
						<button class="button button-primary"><?php echo esc_html__('Export blocker CSV','merchant-wiki-audit'); ?></button>
					</form>
					<p class="description"><?php echo esc_html__('After fixes, rerun Refresh On-Site Signals from Operations.','merchant-wiki-audit'); ?></p>
				</section>

				<section class="mw-next-cell">
					<h4><?php echo esc_html__('Manual indexing queue','merchant-wiki-audit'); ?></h4>
					<p><?php echo esc_html__('When Inspection API quota is exhausted, export a ready-made queue with GSC reasons and internal link evidence.','merchant-wiki-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('mw_next_steps_manual'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_manual">
						<label style="display:block;margin-bottom:6px;">
							<?php echo esc_html__('Inbound link threshold','merchant-wiki-audit'); ?>
							<input type="number" class="small-text" name="threshold" min="0" max="10" value="<?php echo esc_attr($manual_queue_threshold); ?>">
						</label>
						<button class="button"><?php echo esc_html__('Export queue CSV','merchant-wiki-audit'); ?></button>
					</form>
					<p class="description"><?php echo esc_html__('Feed the CSV into manual submissions, then run Google Index Status with “Only queue stale/new URLs”.','merchant-wiki-audit'); ?></p>
				</section>

				<section class="mw-next-cell">
					<h4><?php echo esc_html__('Post-launch monitoring snapshots','merchant-wiki-audit'); ?></h4>
					<p><?php echo esc_html__('Capture HTTP/canonical state after each scan and diff two snapshots to prove deltas.','merchant-wiki-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;">
						<?php wp_nonce_field('mw_next_steps_snapshot'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_snapshot">
						<label style="display:block;margin-bottom:4px;"><?php echo esc_html__('Snapshot label','merchant-wiki-audit'); ?></label>
						<input type="text" name="snapshot_label" class="regular-text" placeholder="<?php echo esc_attr__('e.g. Pre-launch crawl','merchant-wiki-audit'); ?>">
						<button class="button" style="margin-top:6px;"><?php echo esc_html__('Save snapshot','merchant-wiki-audit'); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-snapshot-diff">
						<?php wp_nonce_field('mw_next_steps_diff'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_diff">
						<label>
							<?php echo esc_html__('Before','merchant-wiki-audit'); ?>
							<select name="snapshot_old" <?php disabled(!$next_steps_can_diff); ?>>
								<?php if (!empty($next_steps_snapshots)): ?>
									<?php foreach ($next_steps_snapshots as $snap): ?>
										<option value="<?php echo esc_attr($snap['id']); ?>"><?php echo esc_html($snap['label'].' — '.mysql2date('Y-m-d H:i', $snap['created_at'])); ?></option>
									<?php endforeach; ?>
								<?php else: ?>
									<option value=""><?php echo esc_html__('No snapshots yet','merchant-wiki-audit'); ?></option>
								<?php endif; ?>
							</select>
						</label>
						<label>
							<?php echo esc_html__('After','merchant-wiki-audit'); ?>
							<select name="snapshot_new" <?php disabled(!$next_steps_can_diff); ?>>
								<?php if (!empty($next_steps_snapshots)): ?>
									<?php foreach ($next_steps_snapshots as $snap): ?>
										<option value="<?php echo esc_attr($snap['id']); ?>"><?php echo esc_html($snap['label'].' — '.mysql2date('Y-m-d H:i', $snap['created_at'])); ?></option>
									<?php endforeach; ?>
								<?php else: ?>
									<option value=""><?php echo esc_html__('No snapshots yet','merchant-wiki-audit'); ?></option>
								<?php endif; ?>
							</select>
						</label>
						<button class="button" <?php disabled(!$next_steps_can_diff); ?>><?php echo esc_html__('Download diff CSV','merchant-wiki-audit'); ?></button>
					</form>
					<?php if (!$next_steps_can_diff): ?>
						<p class="description"><?php echo esc_html__('Save at least two snapshots to enable diff downloads.','merchant-wiki-audit'); ?></p>
					<?php endif; ?>
				</section>

				<section class="mw-next-cell">
					<h4><?php echo esc_html__('Content pruning (beyond Yoast/Rank Math)','merchant-wiki-audit'); ?></h4>
					<p><?php echo esc_html__('Find zero-link URLs leaking to external domains with GSC reasons so you can redirect, 410, or improve them.','merchant-wiki-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('mw_next_steps_pruning'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_pruning">
						<button class="button"><?php echo esc_html__('Export pruning CSV','merchant-wiki-audit'); ?></button>
					</form>
					<p class="description"><?php echo esc_html__('CSV flags orphaned pages with outbound leaks — something on-page SEO plugins miss.','merchant-wiki-audit'); ?></p>
				</section>
			</div>
			<?php if (!empty($next_steps_snapshots)): ?>
				<div class="mw-snapshot-history">
					<h4><?php echo esc_html__('Saved snapshots','merchant-wiki-audit'); ?></h4>
					<ul class="mw-snapshot-list">
						<?php foreach ($next_steps_snapshots as $snap): ?>
							<li>
								<strong><?php echo esc_html($snap['label']); ?></strong>
								<span><?php echo esc_html(mysql2date('Y-m-d H:i', $snap['created_at'])); ?></span>
								<span>
									<?php printf(
										/* translators: %d: number of rows in the snapshot */
										esc_html__('Rows: %d','merchant-wiki-audit'),
										(int) $snap['rows']
									); ?>
								</span>
								<?php if ($next_steps_snapshot_url && !empty($snap['filename'])): ?>
									<a href="<?php echo esc_url($next_steps_snapshot_url.$snap['filename']); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Download raw','merchant-wiki-audit'); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>

		<div class="mw-card mw-refresh-card" id="mw-refresh-card">
			<h3><?php echo esc_html__('Stale content refresh','merchant-wiki-audit'); ?></h3>
			<p class="mw-report-hint"><?php
				printf(
					/* translators: %d: minimum number of days since last update */
					esc_html__('Shows published posts/pages that have not been updated for ≥%d days so you can refresh them before rankings slip.','merchant-wiki-audit'),
					max(1, $refresh_min_days)
				);
			?></p>
			<p class="mw-report-hint mw-gemini-reminder"><?php echo esc_html__('Use “Open Gemini” to copy the recommended brief, open Gemini in a new tab, and paste the current page content immediately.','merchant-wiki-audit'); ?></p>
				<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="mw-refresh-filter">
					<input type="hidden" name="page" value="<?php echo esc_attr($current_page_slug); ?>">
					<input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
					<input type="hidden" name="dir" value="<?php echo esc_attr($dir); ?>">
					<?php if ($filter_likely): ?><input type="hidden" name="mw_filter_likely" value="1"><?php endif; ?>
					<?php if ($filter_stale): ?><input type="hidden" name="mw_filter_stale" value="1"><?php endif; ?>
					<?php if ($filter_never): ?><input type="hidden" name="mw_filter_never" value="1"><?php endif; ?>
					<input type="hidden" name="mw_filter_new" value="<?php echo esc_attr($filter_new_hours); ?>">
					<label class="mw-refresh-mode-label">
						<span><?php echo esc_html__('Preset','merchant-wiki-audit'); ?></span>
						<select name="mw_refresh_mode" onchange="this.form.submit()">
							<option value="days365" <?php selected($refresh_mode, 'days365'); ?>><?php echo esc_html__('≥365 days since last update','merchant-wiki-audit'); ?></option>
							<option value="last_year" <?php selected($refresh_mode, 'last_year'); ?>><?php echo esc_html__('Last calendar year (stale)','merchant-wiki-audit'); ?></option>
							<option value="top5" <?php selected($refresh_mode, 'top5'); ?>><?php echo esc_html__('Oldest 5 articles','merchant-wiki-audit'); ?></option>
						</select>
					</label>
					<label>
						<input type="checkbox" name="mw_refresh_zero_inbound" value="1" <?php checked($refresh_zero_only); ?> onchange="this.form.submit()">
						<?php echo esc_html__('Only show URLs with zero inbound links','merchant-wiki-audit'); ?>
					</label>
					<noscript><button class="button"><?php echo esc_html__('Apply','merchant-wiki-audit'); ?></button></noscript>
			</form>
				<?php if (!empty($refresh_candidates)): ?>
					<?php
						$refresh_current_year = (int) (function_exists('wp_date') ? wp_date('Y', $refresh_now_ts) : date_i18n('Y', $refresh_now_ts));
					?>
					<p class="mw-report-hint mw-refresh-howto">
						<strong><?php echo esc_html__('Quick recipe:','merchant-wiki-audit'); ?></strong>
						<?php echo esc_html__('Open the URL, click “Open Gemini”, paste the current copy, and ask the model to refresh the text plus provide two supporting external links. Publish the updates so the page drops out automatically.','merchant-wiki-audit'); ?>
					</p>
					<div class="mw-table-wrap">
						<table class="mw-table mw-refresh-table">
						<thead>
							<tr>
								<th><?php echo esc_html__('Page','merchant-wiki-audit'); ?></th>
								<th><?php echo esc_html__('Published','merchant-wiki-audit'); ?></th>
								<th><?php echo esc_html__('Last updated','merchant-wiki-audit'); ?></th>
								<th><?php echo esc_html__('Inbound links','merchant-wiki-audit'); ?></th>
								<th><?php echo esc_html__('Meta description','merchant-wiki-audit'); ?></th>
								<th><?php echo esc_html__('Gemini','merchant-wiki-audit'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($refresh_candidates as $candidate): ?>
								<?php
									$candidate_title = isset($candidate['title']) && $candidate['title'] !== '' ? $candidate['title'] : __('(no title)','merchant-wiki-audit');
									$candidate_title_plain = wp_strip_all_tags($candidate_title);
									$permalink = $candidate['permalink'];
									$published_label = (!empty($candidate['published_at']) && $candidate['published_at'] !== '0000-00-00 00:00:00') ? mysql2date('Y-m-d', $candidate['published_at']) : '—';
									$modified_label = (!empty($candidate['modified_at']) && $candidate['modified_at'] !== '0000-00-00 00:00:00') ? mysql2date('Y-m-d', $candidate['modified_at']) : $published_label;
									$days_since_update = isset($candidate['days_since_update']) ? (int) $candidate['days_since_update'] : null;
									$days_label = $days_since_update !== null
										? sprintf(
											/* translators: %d: number of days since the page was updated */
											_n('%d day ago','%d days ago', $days_since_update, 'merchant-wiki-audit'),
											$days_since_update
										)
										: '—';
									$has_meta_description = isset($candidate['meta_description']) && $candidate['meta_description'] !== '';
									$meta_description = $has_meta_description
										? $candidate['meta_description']
										: __('Meta description not set','merchant-wiki-audit');
									$meta_plain = $has_meta_description ? wp_strip_all_tags($candidate['meta_description']) : '';
									$inbound_total = isset($candidate['inbound_links']) ? (int) $candidate['inbound_links'] : null;
									$gemini_prompt_parts = array();
									$gemini_prompt_parts[] = sprintf(
										/* translators: %s = page URL */
										__('Update the page %s in the language of its original content.', 'merchant-wiki-audit'),
										$permalink
									);
									if ($published_label && $published_label !== '—') {
										$gemini_prompt_parts[] = sprintf(
											/* translators: %s = publication date */
											__('Published on: %s.', 'merchant-wiki-audit'),
											$published_label
										);
									}
									if ($modified_label && $modified_label !== '—') {
										$gemini_prompt_parts[] = sprintf(
											/* translators: 1: last updated date, 2: how many days ago */
											__('Last updated on %1$s (~%2$s).', 'merchant-wiki-audit'),
											$modified_label,
											$days_label
										);
									}
									$gemini_prompt_parts[] = __('Refresh the body copy to reflect changes since publication while keeping the structure easy to read.', 'merchant-wiki-audit');
									if (!empty($candidate['needs_current_year_focus'])) {
										$gemini_prompt_parts[] = sprintf(
											/* translators: %d = current year */
											__('If the page has not been updated in %d yet, highlight the key developments from this year.', 'merchant-wiki-audit'),
											$refresh_current_year
										);
									}
									$gemini_prompt_parts[] = __('I will paste the current text below. Suggest improved title and meta description only if they are truly outdated.', 'merchant-wiki-audit');
									$gemini_prompt_parts[] = sprintf(
										/* translators: %s = current title */
										__('Current title: %s.', 'merchant-wiki-audit'),
										$candidate_title_plain
									);
									if ($has_meta_description) {
										$gemini_prompt_parts[] = sprintf(
											/* translators: %s = current meta description */
											__('Current meta description: %s.', 'merchant-wiki-audit'),
											$meta_plain
										);
									} else {
										$gemini_prompt_parts[] = __('Meta description is missing — propose a concise, unique sentence.', 'merchant-wiki-audit');
									}
									$gemini_prompt_parts[] = __('Recommend two relevant external links that would add current references for this article.', 'merchant-wiki-audit');
									$gemini_prompt = implode(' ', $gemini_prompt_parts);
									$clipboard_prompt = $gemini_prompt;
									$content_plain = isset($candidate['content_plain']) ? trim((string) $candidate['content_plain']) : '';
									if ($content_plain !== '') {
										$clipboard_prompt .= "\n\n".__('Current content:','merchant-wiki-audit')."\n".$content_plain;
									} else {
										$clipboard_prompt .= "\n\n".__('Current content could not be loaded automatically. Paste it manually below.', 'merchant-wiki-audit');
									}
									$gemini_url = 'https://gemini.google.com/app?source=mw-audit&prompt='.rawurlencode($gemini_prompt);
								?>
								<tr>
									<td class="mw-refresh-title">
										<a href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener"><?php echo esc_html($candidate_title); ?></a>
										<div class="mw-refresh-url"><?php echo esc_html($permalink); ?></div>
									</td>
									<td><?php echo esc_html($published_label); ?></td>
									<td>
										<div><?php echo esc_html($modified_label); ?></div>
										<div class="mw-refresh-ago"><?php echo esc_html($days_label); ?></div>
									</td>
									<td class="mw-refresh-inbound">
										<?php
											if ($inbound_total === null) {
												echo '—';
											} else {
												printf(
													'<span class="%s">%s</span>',
													$inbound_total <= 0 ? 'mw-refresh-inbound-zero' : '',
													esc_html(number_format_i18n(max(0, $inbound_total)))
												);
											}
										?>
									</td>
									<td class="mw-refresh-meta"><?php echo esc_html($meta_description); ?></td>
									<td class="mw-gemini-cell">
										<a class="button button-small mw-gemini-link" href="<?php echo esc_url($gemini_url); ?>" target="_blank" rel="noopener" data-prompt="<?php echo esc_attr($gemini_prompt); ?>" data-clipboard="<?php echo esc_attr($clipboard_prompt); ?>" title="<?php echo esc_attr__('Click to copy the full brief (with page content) and open Gemini. Paste it into Gemini immediately.', 'merchant-wiki-audit'); ?>"><?php echo esc_html__('Open Gemini','merchant-wiki-audit'); ?></a>
										<small class="mw-gemini-hint"><?php echo esc_html__('Copies the brief, then opens Gemini in a new tab.', 'merchant-wiki-audit'); ?></small>
										<span class="mw-gemini-status" aria-live="polite"></span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php else: ?>
					<p class="description"><?php echo esc_html__('No stale posts or pages match the current preset. Try switching to “Oldest 5 articles” if you still need quick wins.','merchant-wiki-audit'); ?></p>
				<?php endif; ?>
		</div>

			<div class="mw-card mw-priority-card">
				<div id="mw-priority-block" class="mw-priority">
					<h3><?php echo esc_html__('Priority: Ready to Submit','merchant-wiki-audit'); ?></h3>
					<p class="mw-report-hint"><?php echo esc_html__('To build this report run the following Operations blocks in order:','merchant-wiki-audit'); ?></p>
					<ul class="mw-priority-prereq">
						<li><?php echo esc_html__('Operations → Rebuild Inventory — click Start to refresh the URL list before prioritizing.','merchant-wiki-audit'); ?></li>
						<li><?php echo esc_html__('Operations → Refresh On-Site Signals (keep “Also rebuild Post → Primary Category Map” enabled) — populates HTTP, sitemap, and category data.','merchant-wiki-audit'); ?></li>
						<li><?php echo esc_html__('Operations → Internal Link Scan — updates inbound link counts so the threshold filter works.','merchant-wiki-audit'); ?></li>
						<li><?php echo esc_html__('Operations → Page Indexing Import or Check Google Index Status — refreshes GSC coverage/reasons shown below.','merchant-wiki-audit'); ?></li>
				</ul>
				<p class="mw-box-hint"><?php echo esc_html__('Focus on indexable URLs with HTTP 200, sitemap coverage, and almost zero internal links. Great for manual indexing batches.','merchant-wiki-audit'); ?></p>
				<label style="display:block; margin:6px 0 12px;">
					<?php echo esc_html__('Inbound link threshold','merchant-wiki-audit'); ?>
					<select id="mw-priority-threshold">
						<?php foreach ($priority_thresholds as $pth): ?>
							<?php
								$pth_int = (int) $pth;
								if ($pth_int === 0) {
									$label = __('0 (no links)','merchant-wiki-audit');
								} else {
									$label = sprintf(
										/* translators: %d = inbound link threshold */
										_n('%d link or fewer','%d links or fewer', $pth_int, 'merchant-wiki-audit'),
										$pth_int
									);
								}
							?>
							<option value="<?php echo esc_attr($pth_int); ?>" <?php selected($pth_int, $priority_threshold); ?>>
								<?php echo esc_html($label); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="mw-priority-actions">
					<button type="button" class="button" id="mw-priority-load"><?php echo esc_html__('Show list','merchant-wiki-audit'); ?></button>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-priority-export-form">
						<?php wp_nonce_field('mw_audit_priority_export'); ?>
						<input type="hidden" name="action" value="mw_audit_priority_export">
						<input type="hidden" name="threshold" id="mw-priority-export-threshold" value="<?php echo esc_attr($priority_threshold); ?>">
						<button class="button"><?php echo esc_html__('Export CSV','merchant-wiki-audit'); ?></button>
					</form>
				</div>
				<p id="mw-priority-status" class="mw-inline-status" aria-live="polite"></p>
				<div id="mw-priority-table-wrap" class="mw-table-wrap mw-priority-table-wrap" style="display:none">
					<table class="mw-table mw-priority-table">
						<thead>
							<tr>
								<th><?php echo esc_html__('URL','merchant-wiki-audit'); ?></th>
								<th><?php echo esc_html__('Inbound','merchant-wiki-audit'); ?></th>
								<th><?php echo esc_html__('Primary category','merchant-wiki-audit'); ?></th>
								<th><?php echo esc_html__('GSC status','merchant-wiki-audit'); ?></th>
								<th><?php echo esc_html__('Published','merchant-wiki-audit'); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>

			<h2 id="mw-report-preview"><?php echo esc_html__('Preview (first 100 rows)','merchant-wiki-audit'); ?></h2>
			<p class="mw-report-hint"><?php echo esc_html__('Use Export CSV to dump the entire dataset (not just the 100-row preview).','merchant-wiki-audit'); ?></p>
			<form id="mw-filters-form" class="mw-filters" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr($current_page_slug); ?>">
				<input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
				<input type="hidden" name="dir" value="<?php echo esc_attr($dir); ?>">
				<input type="hidden" name="mw_refresh_mode" value="<?php echo esc_attr($refresh_mode); ?>">
				<?php if ($refresh_zero_only): ?>
					<input type="hidden" name="mw_refresh_zero_inbound" value="1">
				<?php endif; ?>
				<fieldset>
					<legend><?php echo esc_html__('Filters','merchant-wiki-audit'); ?></legend>
					<label class="mw-filter-option">
						<input type="checkbox" name="mw_filter_likely" value="1" class="mw-autosubmit" <?php checked($filter_likely); ?>>
						<?php echo esc_html__('Show likely not indexed','merchant-wiki-audit'); ?>
					</label>
					<label class="mw-filter-option">
						<input type="checkbox" name="mw_filter_stale" value="1" class="mw-autosubmit" <?php checked($filter_stale); ?>>
						<?php echo esc_html__('Show stale (TTL expired)','merchant-wiki-audit'); ?>
					</label>
					<label class="mw-filter-option">
						<input type="checkbox" name="mw_filter_never" value="1" class="mw-autosubmit" <?php checked($filter_never); ?>>
						<?php echo esc_html__('Show never inspected','merchant-wiki-audit'); ?>
					</label>
				</fieldset>
				<fieldset class="mw-filter-new">
					<legend><?php echo esc_html__('Published','merchant-wiki-audit'); ?></legend>
					<label><input type="radio" name="mw_filter_new" value="0" class="mw-autosubmit" <?php checked($filter_new_hours, 0); ?>><?php echo esc_html__('All pages','merchant-wiki-audit'); ?></label>
					<label><input type="radio" name="mw_filter_new" value="24" class="mw-autosubmit" <?php checked($filter_new_hours, 24); ?>><?php echo esc_html__('Last 24h','merchant-wiki-audit'); ?></label>
					<label><input type="radio" name="mw_filter_new" value="48" class="mw-autosubmit" <?php checked($filter_new_hours, 48); ?>><?php echo esc_html__('Last 48h','merchant-wiki-audit'); ?></label>
					<label><input type="radio" name="mw_filter_new" value="168" class="mw-autosubmit" <?php checked($filter_new_hours, 168); ?>><?php echo esc_html__('Last 7 days','merchant-wiki-audit'); ?></label>
				</fieldset>
			</form>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-report-export">
				<?php wp_nonce_field('mw_audit_export', 'mw_audit_export_nonce'); ?>
				<input type="hidden" name="action" value="mw_audit_export_csv">
				<?php if ($filter_likely): ?>
					<input type="hidden" name="mw_filter_likely" value="1">
				<?php endif; ?>
				<?php if ($filter_stale): ?>
					<input type="hidden" name="mw_filter_stale" value="1">
				<?php endif; ?>
				<?php if ($filter_never): ?>
					<input type="hidden" name="mw_filter_never" value="1">
				<?php endif; ?>
				<input type="hidden" name="mw_filter_new" value="<?php echo esc_attr($filter_new_hours); ?>">
				<button class="button"><?php echo esc_html__('Export CSV','merchant-wiki-audit'); ?></button>
			</form>
			<div class="mw-table-wrap">
				<table class="mw-table">
				<thead>
					<tr>
						<?php
							$cols = array(
								'norm_url'        => __('URL','merchant-wiki-audit'),
								'obj_type'        => __('Type','merchant-wiki-audit'),
								'published_at'    => __('Published','merchant-wiki-audit'),
								'http_status'     => 'HTTP',
								'in_sitemap'      => 'Sitemap',
								'noindex'         => 'Noindex',
								'inbound_links'   => __('Inbound','merchant-wiki-audit'),
								'gsc_status'      => __('GSC','merchant-wiki-audit'),
								'indexed_in_google'=> __('Indexed in Google','merchant-wiki-audit'),
								'canonical'       => 'Canonical',
								'robots_meta'     => 'Robots',
								'schema_type'     => 'Schema',
								'pc_name'         => __('Primary Category','merchant-wiki-audit'),
								'pc_path'         => __('Category Path','merchant-wiki-audit'),
								'updated_at'      => __('Updated','merchant-wiki-audit'),
							);
						foreach ($cols as $k => $label) {
							if ($k === 'gsc_status') {
								echo '<th>'.esc_html($label).'</th>';
								continue;
							}
							$link = add_query_arg(array('order'=>$k,'dir'=>$toggle_dir));
							echo '<th><a href="'.esc_url($link).'">'.esc_html($label).'</a></th>';
						}
						?>
					</tr>
				</thead>
				<tbody>
					<?php
						$likely_states_lower = array_map('strtolower', array_map('trim', $likely_states));
						$now_ts = current_time('timestamp');
						$render_gsc_status = function(array $row) use ($likely_states_lower, $now_ts) {
							$inspection_state = isset($row['gsc_coverage_inspection']) ? trim((string) $row['gsc_coverage_inspection']) : '';
							$page_state = isset($row['gsc_coverage_page']) ? trim((string) $row['gsc_coverage_page']) : '';
							$verdict = isset($row['gsc_verdict']) ? trim((string) $row['gsc_verdict']) : '';
							$inspected_at = isset($row['gsc_inspected_at']) ? $row['gsc_inspected_at'] : '';
							$pi_inspected_at = isset($row['gsc_pi_inspected_at']) ? $row['gsc_pi_inspected_at'] : '';
							$ttl_until = isset($row['gsc_ttl_until']) ? $row['gsc_ttl_until'] : '';
							$last_error = isset($row['gsc_last_error']) ? trim((string) $row['gsc_last_error']) : '';
					$reason = isset($row['gsc_pi_reason']) ? trim((string) $row['gsc_pi_reason']) : '';
					$reason_label_page = isset($row['gsc_reason_page']) ? trim((string) $row['gsc_reason_page']) : '';
					$reason_label_inspection = isset($row['gsc_reason_inspection']) ? trim((string) $row['gsc_reason_inspection']) : '';
					$reason_label = $reason_label_page !== '' ? $reason_label_page : $reason_label_inspection;
					$reason_map = [
						'discovered' => __('Discovered – currently not indexed','merchant-wiki-audit'),
						'crawled_not_indexed' => __('Crawled – currently not indexed','merchant-wiki-audit'),
						'duplicate_canonical' => __('Duplicate, Google chose different canonical','merchant-wiki-audit'),
						'alternate_canonical' => __('Alternate page with proper canonical tag','merchant-wiki-audit'),
						'soft_404' => __('Soft 404','merchant-wiki-audit'),
						'noindex' => __('Excluded by noindex','merchant-wiki-audit'),
						'redirect' => __('Page with redirect','merchant-wiki-audit'),
						'blocked_robots' => __('Blocked by robots.txt','merchant-wiki-audit'),
						'server_error' => __('Server error (5xx)','merchant-wiki-audit'),
						'not_found' => __('Not found (404)','merchant-wiki-audit'),
						'valid' => __('Valid / Indexed','merchant-wiki-audit'),
					];
					$reason_label_text = ($reason_label && isset($reason_map[$reason_label])) ? $reason_map[$reason_label] : '';
					$source = $page_state !== '' ? 'page' : ($inspection_state !== '' ? 'inspection' : '');
					$coverage = $page_state !== '' ? $page_state : $inspection_state;
					$coverage_lower = strtolower($coverage);
					$stale = false;
					if ($ttl_until) {
								$ttl_ts = strtotime($ttl_until);
								if ($ttl_ts && $ttl_ts <= $now_ts) {
									$stale = true;
								}
							}
							$label = __('Unknown','merchant-wiki-audit');
							$class = 'mw-gsc-status--unknown';
					$likely_codes = ['discovered','crawled_not_indexed','duplicate_canonical','alternate_canonical','soft_404','noindex','blocked_robots','server_error','redirect','not_found'];
					if ($coverage !== '') {
						if ($reason_label !== '' && in_array($reason_label, $likely_codes, true)) {
							$label = __('Likely not indexed','merchant-wiki-audit');
							$class = 'mw-gsc-status--likely';
						} elseif (in_array($coverage_lower, $likely_states_lower, true)) {
							$label = __('Likely not indexed','merchant-wiki-audit');
							$class = 'mw-gsc-status--likely';
						} elseif (strpos($coverage_lower, 'not indexed') !== false) {
							$label = __('Not indexed','merchant-wiki-audit');
							$class = 'mw-gsc-status--notindexed';
								} elseif (strpos($coverage_lower, 'indexed') !== false) {
									$label = __('Indexed','merchant-wiki-audit');
									$class = 'mw-gsc-status--indexed';
								} elseif (strpos($coverage_lower, 'excluded') !== false || strpos($coverage_lower, 'duplicate') !== false || strpos($coverage_lower, 'noindex') !== false || strpos($coverage_lower, '404') !== false) {
									$label = __('Excluded','merchant-wiki-audit');
									$class = 'mw-gsc-status--excluded';
								} else {
									$label = $coverage;
									$class = 'mw-gsc-status--info';
								}
							} elseif (isset($row['indexed_in_google'])) {
								if ((int) $row['indexed_in_google'] === 1) {
									$label = __('Indexed','merchant-wiki-audit');
									$class = 'mw-gsc-status--indexed';
								} elseif ((int) $row['indexed_in_google'] === 0) {
									$label = __('Not indexed','merchant-wiki-audit');
									$class = 'mw-gsc-status--notindexed';
								}
							}
							$parts = array();
					if ($reason_label_text !== '') {
						$label = $reason_label_text;
					}
					if ($coverage !== '') {
						$parts[] = sprintf(
							/* translators: %s: Google Search Console coverage label */
							__('Coverage: %s','merchant-wiki-audit'),
							$coverage
						);
					}
					if ($reason_label_text !== '') {
						$parts[] = sprintf(
							/* translators: %s: Google Search Console reason code */
							__('Reason code: %s','merchant-wiki-audit'),
							$reason_label_text
						);
					}
					if ($reason !== '') {
						$parts[] = sprintf(
							/* translators: %s: human readable reason */
							__('Reason: %s','merchant-wiki-audit'),
							$reason
						);
					}
							if ($verdict !== '') {
								$parts[] = sprintf(
									/* translators: %s: verdict text from Google Search Console */
									__('Verdict: %s','merchant-wiki-audit'),
									$verdict
								);
							}
							$inspect_time = $source === 'page' ? $pi_inspected_at : $inspected_at;
							if ($inspect_time) {
								$parts[] = sprintf(
									/* translators: %s: formatted timestamp */
									__('Last checked: %s','merchant-wiki-audit'),
									mysql2date('Y-m-d H:i', $inspect_time)
								);
							}
							if ($ttl_until) {
								$parts[] = sprintf(
									/* translators: %s: formatted timestamp */
									__('TTL until: %s','merchant-wiki-audit'),
									mysql2date('Y-m-d H:i', $ttl_until)
								);
							}
							if ($source) {
								$parts[] = ($source === 'page') ? __('Source: Page indexing','merchant-wiki-audit') : __('Source: Inspection API','merchant-wiki-audit');
							}
							if ($last_error !== '') {
								$parts[] = sprintf(
									/* translators: %s: error message text */
									__('Last error: %s','merchant-wiki-audit'),
									$last_error
								);
							}
							$classes = array('mw-gsc-status', $class);
							if ($stale) {
								$classes[] = 'mw-gsc-status--stale';
							}
							$tooltip = implode("\n", $parts);
							return sprintf(
								'<span class="%s" title="%s">%s</span>',
								esc_attr(implode(' ', $classes)),
								esc_attr($tooltip),
								esc_html($label)
							);
						};
					?>
					<?php if (!empty($rows)): foreach ($rows as $r): ?>
						<tr>
							<td class="url">
								<a href="<?php echo esc_url($r['norm_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($r['norm_url']); ?></a>
								<div class="mw-table-url-actions">
									<button type="button" class="button-link mw-similar-from-row" data-url="<?php echo esc_attr($r['norm_url']); ?>">
										<?php echo esc_html__('Find similar','merchant-wiki-audit'); ?>
									</button>
								</div>
							</td>
							<td><?php echo isset($r['obj_type']) ? esc_html($r['obj_type']) : ''; ?></td>
							<td><?php echo (!empty($r['published_at']) && $r['published_at'] !== '0000-00-00 00:00:00') ? esc_html(mysql2date('Y-m-d H:i', $r['published_at'])) : '—'; ?></td>
							<td><?php echo isset($r['http_status']) ? esc_html($r['http_status']) : ''; ?></td>
							<td><?php echo array_key_exists('in_sitemap',$r) ? ( $r['in_sitemap']===null ? '—' : ( $r['in_sitemap'] ? '1' : '0' ) ) : ''; ?></td>
							<td><?php echo isset($r['noindex']) && $r['noindex'] ? '1' : '0'; ?></td>
							<td><?php echo isset($r['inbound_links']) ? intval($r['inbound_links']) : ''; ?></td>
							<td class="mw-gsc-cell"><?php echo wp_kses_post($render_gsc_status($r)); ?></td>
							<td><?php
								if (!array_key_exists('indexed_in_google', $r) || $r['indexed_in_google'] === null) {
									echo '—';
								} else {
									echo $r['indexed_in_google'] ? esc_html__('Yes','merchant-wiki-audit') : esc_html__('No','merchant-wiki-audit');
								}
							?></td>
							<td class="mono"><?php echo isset($r['canonical']) ? esc_html($r['canonical']) : ''; ?></td>
							<td><?php echo isset($r['robots_meta']) ? esc_html($r['robots_meta']) : ''; ?></td>
							<td><?php echo isset($r['schema_type']) ? esc_html($r['schema_type']) : ''; ?></td>
							<td><?php echo isset($r['pc_name']) ? esc_html($r['pc_name']) : ''; ?></td>
							<td class="mono"><?php echo isset($r['pc_path']) ? esc_html($r['pc_path']) : ''; ?></td>
							<td><?php echo isset($r['updated_at']) ? esc_html($r['updated_at']) : ''; ?></td>
						</tr>
					<?php endforeach; else: ?>
						<tr><td colspan="14"><?php echo esc_html__('No rows yet (or SQL error). See Debug section above and debug.log for details.','merchant-wiki-audit'); ?></td></tr>
					<?php endif; ?>
				</tbody>
				</table>
		</div>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-report-likely-export">
			<?php wp_nonce_field('mw_gsc_export_likely_not_indexed_csv'); ?>
			<input type="hidden" name="action" value="mw_gsc_export_likely_not_indexed_csv">
			<button class="button"><?php echo esc_html__('Export Likely Not Indexed','merchant-wiki-audit'); ?></button>
		</form>
		<div class="mw-similar-overlay" id="mw-similar-overlay" aria-hidden="true">
			<div class="mw-similar-panel" role="dialog" aria-modal="true" aria-labelledby="mw-similar-title">
				<div class="mw-similar-panel-header">
					<h3 id="mw-similar-title"><?php echo esc_html__('Find similar URLs','merchant-wiki-audit'); ?></h3>
					<button type="button" class="mw-similar-close" aria-label="<?php echo esc_attr__('Close panel','merchant-wiki-audit'); ?>">&times;</button>
				</div>
				<div class="mw-similar-panel-body">
					<div class="mw-similar-section">
						<label class="mw-similar-label">
							<span><?php echo esc_html__('Reference URL','merchant-wiki-audit'); ?></span>
							<div class="mw-similar-row">
								<input type="url" id="mw-similar-reference" class="regular-text" placeholder="https://example.com/page/">
								<button type="button" class="button" id="mw-similar-load"><?php echo esc_html__('Load signals','merchant-wiki-audit'); ?></button>
							</div>
						</label>
						<p class="description"><?php echo esc_html__('Tip: click the “Find similar” link inside the Preview table to fill this automatically.','merchant-wiki-audit'); ?></p>
					</div>
					<div class="mw-similar-baseline" id="mw-similar-baseline" hidden>
						<h4><?php echo esc_html__('Loaded signals','merchant-wiki-audit'); ?></h4>
						<dl id="mw-similar-baseline-list"></dl>
					</div>
					<div class="mw-similar-grid">
						<div class="mw-similar-field" data-field="age">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-age-toggle">
								<span><?php echo esc_html__('Days since last update','merchant-wiki-audit'); ?></span>
							</label>
							<div class="mw-similar-controls">
								<input type="number" min="0" id="mw-similar-age-min" value="0"> <span>—</span>
								<input type="number" min="0" id="mw-similar-age-max" value="0">
							</div>
						</div>
						<div class="mw-similar-field" data-field="inbound">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-inbound-toggle">
								<span><?php echo esc_html__('Inbound links','merchant-wiki-audit'); ?></span>
							</label>
							<div class="mw-similar-controls">
								<input type="number" min="0" id="mw-similar-inbound-min" value="0"> <span>—</span>
								<input type="number" min="0" id="mw-similar-inbound-max" value="0">
							</div>
						</div>
						<div class="mw-similar-field" data-field="http_status">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-http-toggle">
								<span><?php echo esc_html__('HTTP status','merchant-wiki-audit'); ?></span>
							</label>
							<select id="mw-similar-http-value">
								<option value="200">200</option>
								<option value="204">204</option>
								<option value="301">301</option>
								<option value="302">302</option>
								<option value="404">404</option>
								<option value="410">410</option>
							</select>
						</div>
						<div class="mw-similar-field" data-field="in_sitemap">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-sitemap-toggle">
								<span><?php echo esc_html__('In sitemap','merchant-wiki-audit'); ?></span>
							</label>
							<select id="mw-similar-sitemap-value">
								<option value="1"><?php echo esc_html__('Yes','merchant-wiki-audit'); ?></option>
								<option value="0"><?php echo esc_html__('No','merchant-wiki-audit'); ?></option>
							</select>
						</div>
						<div class="mw-similar-field" data-field="noindex">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-noindex-toggle">
								<span><?php echo esc_html__('Noindex flag','merchant-wiki-audit'); ?></span>
							</label>
							<select id="mw-similar-noindex-value">
								<option value="0"><?php echo esc_html__('No','merchant-wiki-audit'); ?></option>
								<option value="1"><?php echo esc_html__('Yes','merchant-wiki-audit'); ?></option>
							</select>
						</div>
						<div class="mw-similar-field" data-field="indexed">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-indexed-toggle">
								<span><?php echo esc_html__('Indexed in Google','merchant-wiki-audit'); ?></span>
							</label>
							<select id="mw-similar-indexed-value">
								<option value="1"><?php echo esc_html__('Yes','merchant-wiki-audit'); ?></option>
								<option value="0"><?php echo esc_html__('No','merchant-wiki-audit'); ?></option>
							</select>
						</div>
						<div class="mw-similar-field" data-field="pc_path">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-category-toggle">
								<span><?php echo esc_html__('Primary category path','merchant-wiki-audit'); ?></span>
							</label>
							<input type="text" id="mw-similar-category-value" placeholder="human-resources/">
						</div>
					</div>
					<div class="mw-similar-actions">
						<label class="mw-similar-limit">
							<span><?php echo esc_html__('Max results per page','merchant-wiki-audit'); ?></span>
							<select id="mw-similar-limit">
								<option value="10">10</option>
								<option value="25" selected>25</option>
								<option value="50">50</option>
								<option value="100">100</option>
							</select>
						</label>
						<button type="button" class="button button-primary" id="mw-similar-apply"><?php echo esc_html__('Show matches','merchant-wiki-audit'); ?></button>
						<button type="button" class="button" id="mw-similar-export" disabled><?php echo esc_html__('Export CSV','merchant-wiki-audit'); ?></button>
						<span id="mw-similar-status" class="mw-inline-status" aria-live="polite"></span>
					</div>
					<div class="mw-similar-results" id="mw-similar-results" hidden>
						<div class="mw-similar-results-meta">
							<strong id="mw-similar-summary"></strong>
							<div id="mw-similar-applied" class="mw-similar-applied"></div>
						</div>
						<div class="mw-table-wrap mw-similar-table-wrap">
							<table class="mw-table mw-similar-table">
								<thead>
									<tr>
										<th><?php echo esc_html__('URL','merchant-wiki-audit'); ?></th>
										<th><?php echo esc_html__('Days stale','merchant-wiki-audit'); ?></th>
										<th><?php echo esc_html__('HTTP','merchant-wiki-audit'); ?></th>
										<th><?php echo esc_html__('Inbound','merchant-wiki-audit'); ?></th>
										<th><?php echo esc_html__('Indexed','merchant-wiki-audit'); ?></th>
										<th><?php echo esc_html__('In sitemap','merchant-wiki-audit'); ?></th>
										<th><?php echo esc_html__('Primary category','merchant-wiki-audit'); ?></th>
										<th><?php echo esc_html__('GSC status','merchant-wiki-audit'); ?></th>
										<th><?php echo esc_html__('Similarity','merchant-wiki-audit'); ?></th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>
						<div class="mw-similar-pagination">
							<button type="button" class="button" id="mw-similar-prev" disabled><?php echo esc_html__('Previous','merchant-wiki-audit'); ?></button>
							<button type="button" class="button" id="mw-similar-next" disabled><?php echo esc_html__('Next','merchant-wiki-audit'); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="mw-similar-export-form" class="mw-hidden">
			<?php wp_nonce_field('mw_audit_similar_export', 'mw_audit_similar_export_nonce'); ?>
			<input type="hidden" name="action" value="mw_audit_similar_export">
			<input type="hidden" name="criteria" id="mw-similar-export-criteria" value="">
		</form>
		<?php $outbound_rows = MW_Audit_DB::get_outbound_rows(25, 0, 'outbound_external', 'DESC'); ?>
		<div class="mw-card" id="mw-outbound-summary">
			<h3><?php echo esc_html__('Outbound Link Summary','merchant-wiki-audit'); ?></h3>
			<p class="mw-report-hint"><?php echo esc_html__('Run Operations → Outbound Link Scan (Start) after Refresh On-Site Signals to refresh these counts.','merchant-wiki-audit'); ?></p>
			<p class="description"><?php echo esc_html__('Shows the most recently scanned URLs grouped by how many internal/external links they point to. Use it to find “dead-end” pages or ones that leak too much link equity.','merchant-wiki-audit'); ?></p>
			<div class="mw-table-wrap">
				<table class="mw-table">
					<thead>
						<tr>
							<th><?php echo esc_html__('URL','merchant-wiki-audit'); ?></th>
							<th><?php echo esc_html__('Outbound (internal)','merchant-wiki-audit'); ?></th>
							<th><?php echo esc_html__('Outbound (external)','merchant-wiki-audit'); ?></th>
							<th><?php echo esc_html__('External domains','merchant-wiki-audit'); ?></th>
							<th><?php echo esc_html__('Last scanned','merchant-wiki-audit'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if (!empty($outbound_rows)): ?>
							<?php foreach ($outbound_rows as $row): ?>
								<tr>
									<td class="url"><a href="<?php echo esc_url($row['norm_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($row['norm_url']); ?></a></td>
									<td><?php echo isset($row['outbound_internal']) ? (int) $row['outbound_internal'] : '—'; ?></td>
									<td><?php echo isset($row['outbound_external']) ? (int) $row['outbound_external'] : '—'; ?></td>
									<td><?php echo isset($row['outbound_external_domains']) ? (int) $row['outbound_external_domains'] : '—'; ?></td>
									<td><?php echo !empty($row['last_scanned']) ? esc_html(mysql2date('Y-m-d H:i', $row['last_scanned'])) : '—'; ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else: ?>
							<tr><td colspan="5"><?php echo esc_html__('Run the Outbound Link Scan to populate this table.','merchant-wiki-audit'); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<?php if ($view === 'settings'): ?>
		<div class="mw-card">
			<h3><?php echo esc_html__('General Settings','merchant-wiki-audit'); ?></h3>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('mw_audit_save_settings', 'mw_audit_save_settings_nonce'); ?>
				<input type="hidden" name="action" value="mw_audit_save_settings">
				<div class="mw-kv">
					<label>
						<span><?php echo esc_html__('HEAD timeout (s)','merchant-wiki-audit'); ?></span>
						<input type="number" class="small-text" name="timeout_head" min="1" value="<?php echo esc_attr($settings['timeouts']['head']); ?>">
					</label>
					<label>
						<span><?php echo esc_html__('GET timeout (s)','merchant-wiki-audit'); ?></span>
						<input type="number" class="small-text" name="timeout_get" min="1" value="<?php echo esc_attr($settings['timeouts']['get']); ?>">
					</label>
					<label>
						<span><?php echo esc_html__('Default profile','merchant-wiki-audit'); ?></span>
						<select name="profile_defaults">
							<option value="fast" <?php selected($settings['profile_defaults'], 'fast'); ?>><?php echo esc_html__('Fast','merchant-wiki-audit'); ?></option>
							<option value="standard" <?php selected($settings['profile_defaults'], 'standard'); ?>><?php echo esc_html__('Standard','merchant-wiki-audit'); ?></option>
							<option value="safe" <?php selected($settings['profile_defaults'], 'safe'); ?>><?php echo esc_html__('Safe','merchant-wiki-audit'); ?></option>
						</select>
					</label>
					<label>
						<span><?php echo esc_html__('Export TTL (hours)','merchant-wiki-audit'); ?></span>
						<input type="number" class="small-text" name="ttl_export" min="1" value="<?php echo esc_attr($settings['ttl']['export_hours']); ?>">
					</label>
					<label>
						<span><?php echo esc_html__('API TTL (hours)','merchant-wiki-audit'); ?></span>
						<input type="number" class="small-text" name="ttl_api" min="1" value="<?php echo esc_attr($settings['ttl']['api_hours']); ?>">
					</label>
				</div>
				<p class="description" style="margin:8px 0;">
					<label><input type="checkbox" name="gsc_api_enabled" value="1" <?php checked(!empty($settings['gsc_api_enabled'])); ?>> <?php echo esc_html__('Enable GSC Inspection API spot checks','merchant-wiki-audit'); ?></label><br>
					<label><input type="checkbox" name="gdrive_export_enabled" value="1" <?php checked(!empty($settings['gdrive_export_enabled'])); ?>> <?php echo esc_html__('Enable Google Drive export (future feature)','merchant-wiki-audit'); ?></label>
				</p>
				<p><button class="button button-primary" type="submit"><?php echo esc_html__('Save settings','merchant-wiki-audit'); ?></button></p>
			</form>
		</div>

		<div class="mw-card" id="mw-gsc-connection-card">
			<h3><?php echo esc_html__('Google Search Console Connection','merchant-wiki-audit'); ?></h3>
			<p class="mw-box-hint"><?php echo esc_html__('Save OAuth credentials, connect your Google account, and pick a property before using either import mode or the Inspection API.','merchant-wiki-audit'); ?></p>
			<p class="description"><?php
				printf(
					/* translators: %s: link to Google Cloud Console credentials page */
					esc_html__('Create OAuth credentials under Google Cloud Console → Credentials (%s).','merchant-wiki-audit'),
					sprintf(
						'<a href="%s" target="_blank" rel="noreferrer noopener">%s</a>',
						esc_url('https://console.cloud.google.com/apis/credentials'),
						esc_html__('open console','merchant-wiki-audit')
					)
				);
			?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
				<?php wp_nonce_field('mw_audit_gsc_credentials'); ?>
				<input type="hidden" name="action" value="mw_audit_save_gsc_credentials">
				<label style="display:block; margin-bottom:6px;">
					<?php echo esc_html__('OAuth Client ID','merchant-wiki-audit'); ?>
					<input type="text" name="gsc_client_id" class="regular-text mw-card-field" style="width:100%;max-width:100%;" value="<?php echo esc_attr(MW_Audit_GSC::get_client_id()); ?>">
				</label>
				<label style="display:block; margin-bottom:6px;">
					<?php echo esc_html__('OAuth Client Secret','merchant-wiki-audit'); ?>
					<input type="password" name="gsc_client_secret" class="regular-text mw-card-field" style="width:100%;max-width:100%;" value="<?php echo esc_attr(MW_Audit_GSC::get_client_secret()); ?>">
				</label>
				<p class="description"><?php echo esc_html__('Redirect URI','merchant-wiki-audit'); ?>: <code><?php echo esc_html(MW_Audit_GSC::get_redirect_uri()); ?></code></p>
				<button class="button"><?php echo esc_html__('Save credentials','merchant-wiki-audit'); ?></button>
			</form>

			<?php if (!empty($gsc['configured'])): ?>
				<?php if (!$gsc['connected'] && $gsc_auth_url): ?>
					<p><a class="button button-primary" href="<?php echo esc_url($gsc_auth_url); ?>"><?php echo esc_html__('Connect Google Account','merchant-wiki-audit'); ?></a></p>
				<?php elseif ($gsc['connected']): ?>
					<p class="muted">
						<?php
						printf(
							'%s <strong>%s</strong>%s',
							esc_html__('Connected as','merchant-wiki-audit'),
							esc_html($gsc['email'] ?: __('Unknown account','merchant-wiki-audit')),
							empty($gsc['property']) ? '' : ' — '.esc_html($gsc['property'])
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
						<?php wp_nonce_field('mw_audit_gsc_property'); ?>
						<input type="hidden" name="action" value="mw_audit_save_gsc_property">
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Select property','merchant-wiki-audit'); ?>
							<select name="gsc_property_select" class="regular-text mw-card-field" style="width:100%;max-width:100%;">
								<option value=""><?php echo esc_html__('— Choose property —','merchant-wiki-audit'); ?></option>
								<?php foreach ((array)$gsc_sites as $site_url): ?>
									<option value="<?php echo esc_attr($site_url); ?>" <?php selected($site_url, $gsc['property']); ?>><?php echo esc_html($site_url); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Or specify manually','merchant-wiki-audit'); ?>
							<input type="url" class="regular-text mw-card-field" style="width:100%;max-width:100%;" name="gsc_property_manual" placeholder="https://example.com/" value="<?php echo esc_attr($gsc['property'] ?? ''); ?>">
						</label>
						<button class="button"><?php echo esc_html__('Save property','merchant-wiki-audit'); ?></button>
					</form>
					<div class="mw-gsc-inline-controls">
						<label>
							<?php echo esc_html__('Inspection cache TTL','merchant-wiki-audit'); ?>
							<select id="mw-gsc-ttl" class="mw-card-field" style="min-width:180px;">
								<?php foreach ($gsc_ttl_options as $ttl_option): ?>
									<option value="<?php echo esc_attr($ttl_option); ?>" <?php selected((int)$ttl_option, (int)$gsc_ttl_hours); ?>>
										<?php printf(
											/* translators: %d: number of hours */
											esc_html__('%d hours','merchant-wiki-audit'),
											(int) $ttl_option
										); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<span id="mw-gsc-ttl-status" class="mw-inline-status" aria-live="polite"></span>
					</div>
					<?php if ($gsc_sheets_auth_url || $gsc_has_sheets): ?>
						<?php if (!$gsc_has_sheets && $gsc_sheets_auth_url): ?>
							<p><a class="button" href="<?php echo esc_url($gsc_sheets_auth_url); ?>"><?php echo esc_html__('Connect Sheets','merchant-wiki-audit'); ?></a></p>
						<?php else: ?>
							<p class="description"><?php echo esc_html__('Sheets API scope connected. You can sync Page indexing directly from Google Sheets.','merchant-wiki-audit'); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php if (!empty($gsc['connected'])): ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
					<?php wp_nonce_field('mw_audit_gsc_disconnect'); ?>
					<input type="hidden" name="action" value="mw_audit_disconnect_gsc">
					<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js(__('Disconnect Google account?','merchant-wiki-audit')); ?>');"><?php echo esc_html__('Disconnect','merchant-wiki-audit'); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<span id="mw-gsc-import"></span>
		<div class="mw-card">
			<h3><?php echo esc_html__('Google Search Console Imports','merchant-wiki-audit'); ?></h3>
			<?php
				$gsc_import_mode = $settings['gsc_import_mode'] ?? 'csv';
				$gsc_mode_is_sheets = ($gsc_import_mode === 'sheets');
			?>
			<div class="mw-gsc-mode-switch">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-gsc-mode-form">
					<?php wp_nonce_field('mw_audit_save_settings', 'mw_audit_save_settings_nonce_alt'); ?>
					<input type="hidden" name="action" value="mw_audit_save_settings">
					<label>
						<span><?php echo esc_html__('Choose how to import GSC data','merchant-wiki-audit'); ?></span>
						<select id="mw-gsc-import-mode" name="gsc_import_mode">
							<option value="csv" <?php selected($gsc_import_mode, 'csv'); ?>><?php echo esc_html__('Import GSC as CSV','merchant-wiki-audit'); ?></option>
							<option value="sheets" <?php selected($gsc_import_mode, 'sheets'); ?>><?php echo esc_html__('Import via Google Sheet','merchant-wiki-audit'); ?></option>
						</select>
					</label>
					<button class="button" type="submit"><?php echo esc_html__('Save preference','merchant-wiki-audit'); ?></button>
				</form>
				<p id="mw-gsc-sheets-warning" class="description <?php echo $gsc_mode_is_sheets ? '' : 'mw-hidden'; ?>"<?php echo $gsc_mode_is_sheets ? '' : ' hidden'; ?>>
					<?php echo esc_html__('This is a complex integration that requires Google Cloud Console credentials and we do not recommend it unless you need automation.','merchant-wiki-audit'); ?>
				</p>
			</div>

			<div class="mw-gsc-mode-section mw-gsc-mode-sheets <?php echo $gsc_mode_is_sheets ? '' : 'mw-hidden'; ?>"<?php echo $gsc_mode_is_sheets ? '' : ' hidden'; ?>>
				<div class="mw-gsc-import-block">
					<h4><?php echo esc_html__('Page Indexing Import','merchant-wiki-audit'); ?></h4>
					<p class="mw-box-hint"><?php echo esc_html__('Imports "Page indexing" report from GSC to detect why pages are excluded from index.','merchant-wiki-audit'); ?></p>
					<div class="mw-gsc-assemble">
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Google Sheets links (one per line)','merchant-wiki-audit'); ?>
							<textarea id="mw-gsc-assemble-input" class="regular-text mw-card-field" rows="4" style="width:100%;max-width:100%;" placeholder="https://docs.google.com/spreadsheets/d/...\nhttps://docs.google.com/spreadsheets/d/...\n..."></textarea>
						</label>
						<button type="button" class="button" id="mw-gsc-assemble-button"><?php echo esc_html__('Assemble result sheet','merchant-wiki-audit'); ?></button>
						<span id="mw-gsc-assemble-status" class="mw-inline-status" aria-live="polite"></span>
					</div>
					<?php if ($gsc_has_sheets): ?>
						<div class="mw-gsc-sheets">
							<label style="display:block; margin-bottom:6px;">
								<?php echo esc_html__('Sheet URL or ID','merchant-wiki-audit'); ?>
								<input type="text" id="mw-gsc-sheet-input" class="regular-text mw-card-field" style="width:100%;max-width:100%;" placeholder="https://docs.google.com/spreadsheets/d/...">
							</label>
							<label style="display:block; margin-bottom:6px;">
								<?php echo esc_html__('Range (e.g. Page indexing!A:Z)','merchant-wiki-audit'); ?>
								<input type="text" id="mw-gsc-sheet-range" class="regular-text mw-card-field" style="width:100%;max-width:100%;" value="A:Z">
							</label>
							<label style="display:block; margin-bottom:10px;">
								<input type="checkbox" id="mw-gsc-sync-override" value="1">
								<?php echo esc_html__('Allow Page indexing reasons to overwrite inspection coverage','merchant-wiki-audit'); ?>
							</label>
							<button type="button" class="button" id="mw-gsc-sync-button"><?php echo esc_html__('Sync Page indexing (Sheets)','merchant-wiki-audit'); ?></button>
							<span id="mw-gsc-sync-status" class="mw-inline-status" aria-live="polite"></span>
						</div>
					<?php else: ?>
						<p class="description"><?php echo esc_html__('Use the Connect Sheets button in the connection card above to enable this mode.','merchant-wiki-audit'); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="mw-gsc-mode-section mw-gsc-mode-csv <?php echo $gsc_mode_is_sheets ? 'mw-hidden' : ''; ?>"<?php echo $gsc_mode_is_sheets ? ' hidden' : ''; ?>>
				<div class="mw-gsc-import-block">
					<h4><?php echo esc_html__('Page Indexing Import','merchant-wiki-audit'); ?></h4>
					<p class="mw-box-hint"><?php echo esc_html__('Imports "Page indexing" report from GSC to detect why pages are excluded from index.','merchant-wiki-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="mw-gsc-import-csv">
						<?php wp_nonce_field('mw_gsc_import_pi_csv'); ?>
						<input type="hidden" name="action" value="mw_gsc_import_pi_csv">
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Table CSV (Table.csv)','merchant-wiki-audit'); ?>
							<input type="file" name="mw_gsc_pi_table" accept=".csv" required>
						</label>
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Metadata CSV (Metadata.csv, optional)','merchant-wiki-audit'); ?>
							<input type="file" name="mw_gsc_pi_meta" accept=".csv">
						</label>
						<label style="display:block; margin-bottom:6px;">
							<input type="checkbox" name="override" value="1">
							<?php echo esc_html__('Allow Page indexing data to overwrite inspection coverage','merchant-wiki-audit'); ?>
						</label>
						<button class="button"><?php echo esc_html__('Import CSV','merchant-wiki-audit'); ?></button>
					</form>
				</div>
			</div>

			<p class="description" style="margin-top:10px;">
				<?php echo esc_html__('Index coverage data comes from Google Search Console and may lag by a few days. Site ownership in GSC is required.','merchant-wiki-audit'); ?>
			</p>
		</div>

		<div class="mw-card">
			<h3><?php echo esc_html__('Maintenance','merchant-wiki-audit'); ?></h3>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
				onsubmit="return confirm('<?php echo esc_js(__('Be careful: this will delete all data created by this plugin (inventory, status, PC map). Continue?','merchant-wiki-audit')); ?>');">
				<?php wp_nonce_field('mw_audit_delete_all', 'mw_audit_delete_all_nonce'); ?>
				<input type="hidden" name="action" value="mw_audit_delete_all">
				<button class="button button-link-delete" title="<?php echo esc_attr__('Clear plugin data (without dropping tables).','merchant-wiki-audit'); ?>">
					<?php echo esc_html__('Delete All Data','merchant-wiki-audit'); ?>
				</button>
			</form>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
				<?php wp_nonce_field('mw_audit_toggle_dropdb'); ?>
				<input type="hidden" name="action" value="mw_audit_toggle_dropdb">
				<label style="display:block; margin:6px 0 8px;">
					<?php
					$drop = (get_option('mw_audit_drop_on_uninstall') === 'yes');
					printf(
						'%s <strong>%s</strong>',
						esc_html__('Drop tables on uninstall:','merchant-wiki-audit'),
						$drop ? esc_html__('YES','merchant-wiki-audit') : esc_html__('NO','merchant-wiki-audit')
					);
					?>
				</label>
				<button class="button"><?php echo esc_html__('Toggle','merchant-wiki-audit'); ?></button>
			</form>
		</div>
	<?php endif; ?>

	</div><!-- /.wrap -->
<?php
}
