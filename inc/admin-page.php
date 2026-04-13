<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin page for Merchant.WiKi SEO Audit
 * Renders a single Health block (no duplicates) and safe fallbacks if helpers are not loaded yet.
 */

add_action('admin_menu', function () {
	add_menu_page(
		__('Merchant.WiKi SEO Audit', 'merchant-wiki-seo-audit'),
		'MW Audit',
		'manage_options',
		'mw-site-index-audit',
		'mw_audit_render_dashboard',
		'dashicons-search',
		58
	);
	add_submenu_page(
		'mw-site-index-audit',
		__('Dashboard', 'merchant-wiki-seo-audit'),
		__('Dashboard', 'merchant-wiki-seo-audit'),
		'manage_options',
		'mw-site-index-audit',
		'mw_audit_render_dashboard'
	);
	add_submenu_page(
		'mw-site-index-audit',
		__('Operations', 'merchant-wiki-seo-audit'),
		__('Operations', 'merchant-wiki-seo-audit'),
		'manage_options',
		'mw-site-index-operations',
		'mw_audit_render_operations'
	);
	add_submenu_page(
		'mw-site-index-audit',
		__('Reports', 'merchant-wiki-seo-audit'),
		__('Reports', 'merchant-wiki-seo-audit'),
		'manage_options',
		'mw-site-index-reports',
		'mw_audit_render_reports'
	);
	add_submenu_page(
		'mw-site-index-audit',
		__('Settings', 'merchant-wiki-seo-audit'),
		__('Settings', 'merchant-wiki-seo-audit'),
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

/**
 * Safely fetches a query argument without direct access to $_GET.
 *
 * @param string $key     Query arg key.
 * @param mixed  $default Default value when key missing.
 * @return mixed
 */
function mw_audit_get_query_arg($key, $default = '') {
	$value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
	if ($value === null || is_array($value)) {
		return $default;
	}
	return $value;
}

/**
 * True when a query arg is set (even if empty string).
 *
 * @param string $key Query arg key.
 * @return bool
 */
function mw_audit_has_query_arg($key) {
	$value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
	return $value !== null;
}

/**
 * Returns a sanitized integer query arg.
 *
 * @param string $key     Query arg key.
 * @param int    $default Default integer value.
 * @return int
 */
function mw_audit_get_query_int($key, $default = 0) {
	$value = filter_input(INPUT_GET, $key, FILTER_SANITIZE_NUMBER_INT, FILTER_NULL_ON_FAILURE);
	if ($value === null || $value === '') {
		return (int) $default;
	}
	return (int) $value;
}

/**
 * Returns a boolean interpretation of query arg presence/value.
 *
 * @param string $key Query arg key.
 * @return bool
 */
function mw_audit_get_query_bool($key) {
	$value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
	if ($value === null) {
		return mw_audit_has_query_arg($key);
	}
	return (bool) $value;
}

function mw_audit_render_dashboard() { mw_audit_render_page('dashboard'); }
function mw_audit_render_operations() { mw_audit_render_page('operations'); }
function mw_audit_render_reports() { mw_audit_render_page('reports'); }
function mw_audit_render_settings() { mw_audit_render_page('settings'); }
function mw_audit_render_admin() { mw_audit_render_page('dashboard'); }

function mw_audit_render_page($view = 'dashboard') {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('Not allowed', 'merchant-wiki-seo-audit'));
	}
	$current_page_slug = sanitize_key(mw_audit_get_query_arg('page', 'mw-site-index-audit'));

	// Health (safe)
	$h = (class_exists('MW_Audit_Health') && is_callable(array('MW_Audit_Health','get')))
		? MW_Audit_Health::get()
		: mw_audit_empty_health();

	// Sorting
	$order = sanitize_key(mw_audit_get_query_arg('order', 'norm_url'));
	$dir_raw = strtoupper(sanitize_text_field(mw_audit_get_query_arg('dir', 'ASC')));
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
				<?php echo esc_html__('Merchant.WiKi SEO Audit is currently in beta. Please run it on staging or a full backup and ensure files + database are backed up before launching the queues. Use at your own risk.', 'merchant-wiki-seo-audit'); ?>
			</p>
		</div>
	<?php
		if (mw_audit_has_query_arg('settings_saved')) {
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Settings saved.', 'merchant-wiki-seo-audit'));
		}
		if (mw_audit_has_query_arg('gsc_pi_import')) {
			$type = sanitize_key(mw_audit_get_query_arg('gsc_pi_import'));
			$import_msg = sanitize_text_field(mw_audit_get_query_arg('msg', ''));
			if ($type === 'success') {
				$imported = absint(mw_audit_get_query_int('imported', 0));
				$skipped  = absint(mw_audit_get_query_int('skipped', 0));
					$message = sprintf(
						/* translators: 1: number of imported rows, 2: number of skipped rows. */
						esc_html__('Page indexing import completed: %1$d imported, %2$d skipped.', 'merchant-wiki-seo-audit'),
						$imported,
						$skipped
					);
					printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($message));
			} elseif ($type === 'error' && $import_msg) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html($import_msg)
				);
			}
		}
		if (mw_audit_has_query_arg('mw_snapshot_created')) {
			$label = sanitize_text_field(mw_audit_get_query_arg('mw_snapshot_created', ''));
			$snapshot_rows  = absint(mw_audit_get_query_int('mw_snapshot_rows', 0));
			$snapshot_message = $snapshot_rows
				? sprintf(
					/* translators: 1: snapshot label, 2: number of exported rows. */
					esc_html__('Snapshot “%1$s” saved (%2$d rows).', 'merchant-wiki-seo-audit'),
					$label,
					$snapshot_rows
				)
				: sprintf(
					/* translators: %s: snapshot label. */
					esc_html__('Snapshot “%s” saved.', 'merchant-wiki-seo-audit'),
					$label
				);
			$_snapshot_hint = __('Snapshots are CSV exports stored locally in wp-content/uploads/mw-audit. No screenshots are ever taken.', 'merchant-wiki-seo-audit');
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s %s</p></div>',
				esc_html($snapshot_message),
				wp_kses_post($_snapshot_hint)
			);
		}
		if (mw_audit_has_query_arg('mw_snapshot_error')) {
			$err = sanitize_text_field(mw_audit_get_query_arg('mw_snapshot_error', ''));
			printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($err));
		}
		?>
		<h1><?php echo esc_html__('Merchant.WiKi — Site Index Audit', 'merchant-wiki-seo-audit'); ?></h1>
		<?php
			$mw_audit_views = array(
				'dashboard'  => array(
					'label' => __('Dashboard', 'merchant-wiki-seo-audit'),
					'page'  => 'mw-site-index-audit',
				),
				'operations' => array(
					'label' => __('Operations', 'merchant-wiki-seo-audit'),
					'page'  => 'mw-site-index-operations',
				),
				'reports'    => array(
					'label' => __('Reports', 'merchant-wiki-seo-audit'),
					'page'  => 'mw-site-index-reports',
				),
				'settings'   => array(
					'label' => __('Settings', 'merchant-wiki-seo-audit'),
					'page'  => 'mw-site-index-settings',
				),
			);
			$current_view_label = $mw_audit_views[$view]['label'] ?? ucfirst($view);
		?>
		<div class="mw-section-header">
			<h2 class="mw-section-title"><?php echo esc_html($current_view_label); ?></h2>
			<div class="mw-section-nav" role="navigation" aria-label="<?php esc_attr_e('Sections', 'merchant-wiki-seo-audit'); ?>">
				<?php foreach ($mw_audit_views as $view_key => $view_meta): ?>
					<?php
						$page_slug = $view_meta['page'];
						$url = admin_url('admin.php?page=' . $page_slug);
						$is_current = ($view_key === $view);
						$button_class = $is_current ? 'button button-primary' : 'button button-secondary';
					?>
					<a class="<?php echo esc_attr($button_class); ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($view_meta['label']); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

<?php if ($view === 'dashboard'): ?>
	<div class="mw-grid">

			<!-- HEALTH (single instance) -->
				<div class="mw-card">
					<h3><?php echo esc_html__('Health', 'merchant-wiki-seo-audit'); ?></h3>
					<ul class="mw-kv">
						<li title="<?php echo esc_attr__('Confirms WordPress can reach its own admin-ajax endpoint for queues.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Loopback','merchant-wiki-seo-audit'); ?></span>
							<b class="<?php echo ($h['loopback']==='OK'?'ok pill':'fail pill'); ?>"><?php echo esc_html($h['loopback']); ?></b>
						</li>
						<li title="<?php echo esc_attr__('Checks that robots.txt was fetched and parsed without blocking rules.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('robots.txt','merchant-wiki-seo-audit'); ?></span>
							<b class="<?php echo ($h['robots']==='OK'?'ok pill':'fail pill'); ?>"><?php echo esc_html($h['robots']); ?></b>
						</li>
					<?php
						$gsc_state = 'neutral';
						$gsc_label = __('Not configured','merchant-wiki-seo-audit');
						if (!empty($gsc['configured'])){
							$gsc_state = 'warn';
							$gsc_label = __('Configured, not connected','merchant-wiki-seo-audit');
						}
						if (!empty($gsc['connected'])){
							$gsc_state = 'ok';
							$email = !empty($gsc['email']) ? $gsc['email'] : __('account','merchant-wiki-seo-audit');
							$site  = !empty($gsc['property']) ? $gsc['property'] : '';
							$gsc_label = $site ? sprintf('%s — %s', esc_html($email), esc_html($site)) : esc_html($email);
						}
					?>
						<li title="<?php echo esc_attr__('Shows whether the plugin is authenticated with GSC and which property is selected.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Google Search Console','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo esc_attr($gsc_state); ?>"><?php echo esc_html($gsc_label); ?></b>
						</li>
						<li title="<?php echo esc_attr__('Total URLs discovered during the last inventory rebuild.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Inventory rows','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo esc_attr($h['pills']['inventory'] ?? 'neutral'); ?>"><?php echo esc_html($h['inventory_rows']); ?></b>
						</li>
						<li title="<?php echo esc_attr__('URLs with on-site HTTP/sitemap signals populated.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Status rows','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo esc_attr($h['pills']['status'] ?? 'neutral'); ?>"><?php echo esc_html($h['status_rows']); ?></b>
						</li>
						<li title="<?php echo esc_attr__('Posts that already have a resolved primary category relationship.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Post→Primary Category map','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo esc_attr($h['pills']['pcmap'] ?? 'neutral'); ?>"><?php echo esc_html($h['pc_rows']); ?></b>
						</li>
						<li title="<?php echo esc_attr__('How many URLs the previous rebuild detected (used to spot large delta).','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Inventory detected last run','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo esc_attr($h['pills']['inv_last'] ?? 'neutral'); ?>"><?php echo esc_html($h['last_inv_detected'] ?? 0); ?></b>
						</li>
						<li title="<?php echo esc_attr__('Timestamp of the latest on-site signals refresh job.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Last status update','merchant-wiki-seo-audit'); ?></span>
							<b class="pill neutral"><?php echo $h['last_update'] ? esc_html($h['last_update']) : '—'; ?></b>
						</li>
						<li title="<?php echo esc_attr__('Number of sitemap files cached and how old the cache is.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Sitemap cache','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo ($h['sitemap_cache_count']>0 ? 'ok' : 'neutral'); ?>">
								<?php
								echo ($h['sitemap_cache_count']>0)
									? esc_html($h['sitemap_cache_count'].' files, age '.intval($h['sitemap_cache_age']).'s')
									: '—';
								?>
							</b>
						</li>
						<li title="<?php echo esc_attr__('Share of URLs that have fresh Page Indexing export data.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('GSC coverage (export)','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo esc_attr($gsc_export_state); ?>"><?php echo esc_html(sprintf('%d%%', $gsc_export_ratio_pct)); ?></b>
						</li>
						<?php if ($gsc_show_api_pill): ?>
						<li title="<?php echo esc_attr__('Share of URLs backed by fresh Inspection API responses.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('GSC coverage (API)','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo esc_attr($gsc_api_state); ?>"><?php echo esc_html(sprintf('%d%%', $gsc_api_ratio_pct)); ?></b>
						</li>
						<?php endif; ?>
					</ul>

				<h4 style="margin-top:10px"><?php echo esc_html__('Step statuses','merchant-wiki-seo-audit'); ?></h4>
					<ul class="mw-kv">
						<li id="mw-step-sm" class="<?php echo esc_attr(mw_audit_stat_class($steps['sm']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Downloads sitemap index/files so later steps can iterate URLs quickly.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Prepare/Cache Sitemaps','merchant-wiki-seo-audit'); ?></span>
							<b id="mw-step-pill-sm" class="pill <?php echo (($steps['sm']['status'] ?? '')==='done'?'ok':(($steps['sm']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
								<?php echo esc_html($steps['sm']['status'] ?? '—'); ?>
							</b>
						</li>
						<li class="<?php echo esc_attr(mw_audit_stat_class($steps['os']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Pulls canonical, meta robots, sitemap inclusion, and schema hints from the site.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Refresh On-Site Signals','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo (($steps['os']['status'] ?? '')==='done'?'ok':(($steps['os']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
								<?php echo esc_html($steps['os']['status'] ?? '—'); ?>
							</b>
						</li>
						<li class="<?php echo esc_attr(mw_audit_stat_class($steps['http']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Runs HEAD/GET checks to confirm HTTP status, redirects, and response times.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('HTTP-only Signals','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo (($steps['http']['status'] ?? '')==='done'?'ok':(($steps['http']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
								<?php echo esc_html($steps['http']['status'] ?? '—'); ?>
							</b>
						</li>
						<li class="<?php echo esc_attr(mw_audit_stat_class($steps['pc']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Assigns each post to a primary taxonomy term for prioritization.','merchant-wiki-seo-audit'); ?>">
							<span><?php echo esc_html__('Post → Primary Category Map','merchant-wiki-seo-audit'); ?></span>
							<b class="pill <?php echo (($steps['pc']['status'] ?? '')==='done'?'ok':(($steps['pc']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
								<?php echo esc_html($steps['pc']['status'] ?? '—'); ?>
							</b>
						</li>
					<li class="<?php echo esc_attr(mw_audit_stat_class($steps['link']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Counts inbound internal links so you can spot orphan or weak pages.','merchant-wiki-seo-audit'); ?>">
						<span><?php echo esc_html__('Internal Link Scan','merchant-wiki-seo-audit'); ?></span>
						<b class="pill <?php echo (($steps['link']['status'] ?? '')==='done'?'ok':(($steps['link']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
							<?php echo esc_html($steps['link']['status'] ?? '—'); ?>
						</b>
					</li>
					<li class="<?php echo esc_attr(mw_audit_stat_class($steps['outbound']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Scans your pages and counts outbound internal/external links plus unique external domains.','merchant-wiki-seo-audit'); ?>">
						<span><?php echo esc_html__('Outbound Link Scan','merchant-wiki-seo-audit'); ?></span>
						<b class="pill <?php echo (($steps['outbound']['status'] ?? '')==='done'?'ok':(($steps['outbound']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
							<?php echo esc_html($steps['outbound']['status'] ?? '—'); ?>
						</b>
					</li>
					<li id="mw-step-pi" class="<?php echo esc_attr(mw_audit_stat_class($steps['pi']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Imports the Page Indexing CSV export from Search Console into the cache.','merchant-wiki-seo-audit'); ?>">
						<span><?php echo esc_html__('Page Indexing Import','merchant-wiki-seo-audit'); ?></span>
						<b class="pill <?php echo (($steps['pi']['status'] ?? '')==='done'?'ok':(($steps['pi']['status'] ?? '')==='running'?'warn':'neutral')); ?>">
							<?php echo esc_html($steps['pi']['status'] ?? '—'); ?>
						</b>
					</li>
					<li class="<?php echo esc_attr(mw_audit_stat_class($steps['gindex']['status'] ?? '')); ?>" title="<?php echo esc_attr__('Queues Inspection API calls for stale URLs and caches fresh verdicts.','merchant-wiki-seo-audit'); ?>">
						<span><?php echo esc_html__('Google Index Status','merchant-wiki-seo-audit'); ?></span>
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
			if ($seconds <= 0) {
				return __('~0 min','merchant-wiki-seo-audit');
			}
			if ($seconds < 120) {
				/* translators: %d: number of seconds remaining. */
				return sprintf(__('%d s','merchant-wiki-seo-audit'), $seconds);
			}
			if ($seconds < 7200) {
				/* translators: %d: number of minutes remaining. */
				return sprintf(__('%d min','merchant-wiki-seo-audit'), round($seconds / 60));
			}
			/* translators: %d: number of hours remaining. */
			return sprintf(__('%d h','merchant-wiki-seo-audit'), round($seconds / 3600));
		};
		/* translators: %d: number of URLs queued for Google Inspection. */
		$queue_label = $queue_length ? sprintf(__('%d pending','merchant-wiki-seo-audit'), $queue_length) : __('Idle','merchant-wiki-seo-audit');
		if ($queue_length && $queue_speed > 0) {
			$queue_label .= sprintf(' · %.1f/min', $queue_speed);
		}
		if ($queue_length && $queue_eta > 0) {
			$queue_label .= ' · '.$format_eta($queue_eta);
		}
		$queue_class = $queue_length ? 'warn' : 'ok';
		if (empty($settings['gsc_api_enabled'])) {
			$queue_label = __('Disabled','merchant-wiki-seo-audit');
			$queue_class = 'neutral';
		}
		$quota_used = isset($gsc_metrics['quota_used']) ? (int) $gsc_metrics['quota_used'] : 0;
		$quota_limit = isset($gsc_metrics['quota_limit']) ? (int) $gsc_metrics['quota_limit'] : 0;
		$error_percent = isset($gsc_metrics['error_percent']) ? (float) $gsc_metrics['error_percent'] : 0.0;
		$quota_label = $quota_limit > 0
			? sprintf('%d / %d (%.0f%%)', $quota_used, $quota_limit, $quota_limit ? ($quota_used / max(1,$quota_limit))*100 : 0)
			: sprintf('%d', $quota_used);
		if ($error_percent > 0) {
			$quota_label .= sprintf(' · %.0f%% %s', $error_percent, __('errors','merchant-wiki-seo-audit'));
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
		<h3><?php echo esc_html__('Google Search Console API', 'merchant-wiki-seo-audit'); ?></h3>
		<ul class="mw-kv">
			<li title="<?php echo esc_attr__('Fresh Page Indexing export rows compared to total inventory.','merchant-wiki-seo-audit'); ?>">
				<span><?php echo esc_html__('Export coverage','merchant-wiki-seo-audit'); ?></span>
				<b class="pill <?php echo esc_attr($gsc_export_state); ?>"><?php echo esc_html($export_display); ?></b>
			</li>
			<?php if ($gsc_show_api_pill): ?>
			<li title="<?php echo esc_attr__('Fresh Inspection API responses compared to total inventory.','merchant-wiki-seo-audit'); ?>">
				<span><?php echo esc_html__('API coverage','merchant-wiki-seo-audit'); ?></span>
				<b class="pill <?php echo esc_attr($gsc_api_state); ?>"><?php echo esc_html($api_display); ?></b>
			</li>
			<?php endif; ?>
			<li title="<?php echo esc_attr__('URLs whose Inspection API TTL expired or was never fetched.','merchant-wiki-seo-audit'); ?>">
				<span><?php echo esc_html__('Stale URLs (Inspection)','merchant-wiki-seo-audit'); ?></span>
				<b class="pill <?php echo esc_attr($gsc_stale_total ? 'warn' : 'ok'); ?>"><?php echo esc_html($gsc_stale_total); ?></b>
			</li>
			<li title="<?php echo esc_attr__('Pending URLs in the Inspection queue plus throughput/ETA.','merchant-wiki-seo-audit'); ?>">
				<span><?php echo esc_html__('Queue','merchant-wiki-seo-audit'); ?></span>
				<b class="pill <?php echo esc_attr($queue_class); ?>"><?php echo esc_html($queue_label); ?></b>
			</li>
			<li title="<?php echo esc_attr__('Number of Inspection API calls logged today versus the daily limit.','merchant-wiki-seo-audit'); ?>">
				<span><?php echo esc_html__('Quota today','merchant-wiki-seo-audit'); ?></span>
				<b class="pill <?php echo esc_attr($quota_class); ?>"><?php echo esc_html($quota_label); ?></b>
			</li>
			<li title="<?php echo esc_attr__('Shows whether Google Sheets export credentials are active.','merchant-wiki-seo-audit'); ?>">
				<span><?php echo esc_html__('Sheets API','merchant-wiki-seo-audit'); ?></span>
				<b class="pill <?php echo esc_attr($gsc_has_sheets ? 'ok' : 'warn'); ?>"><?php echo $gsc_has_sheets ? esc_html__('Enabled','merchant-wiki-seo-audit') : esc_html__('Not connected','merchant-wiki-seo-audit'); ?></b>
			</li>
			<li title="<?php echo esc_attr__('Most recent Inspection API or queue error message.','merchant-wiki-seo-audit'); ?>">
				<span><?php echo esc_html__('Last error','merchant-wiki-seo-audit'); ?></span>
				<b class="pill <?php echo esc_attr($last_error ? 'fail' : 'neutral'); ?>"><?php echo $last_error ? esc_html($last_error) : esc_html__('—','merchant-wiki-seo-audit'); ?></b>
			</li>
		</ul>
		<?php if (empty($settings['gsc_api_enabled'])): ?>
			<p class="description"><?php echo esc_html__('Inspection API checks are disabled in settings. Enable them below to run spot checks.', 'merchant-wiki-seo-audit'); ?></p>
		<?php endif; ?>
	</div>

			<!-- DB Structure -->
	<?php
		$db_prefix = is_string($h['db_prefix'] ?? null) ? $h['db_prefix'] : ($GLOBALS['wpdb']->prefix ?? 'wp_');
		$schema_tables = is_array($h['schema']['tables'] ?? null) ? $h['schema']['tables'] : array();
		$last_queue_info = is_array($h['last_queue'] ?? null) ? $h['last_queue'] : array();
	?>
	<div class="mw-card">
		<h3><?php echo esc_html__('DB Structure Check','merchant-wiki-seo-audit'); ?></h3>
		<?php $s = is_array($h['schema'] ?? null) ? $h['schema'] : array('ok'=>true,'tables'=>array()); ?>
		<div class="mw-kv">
			<div style="margin-bottom:6px">
				<span><?php echo esc_html__('Total','merchant-wiki-seo-audit'); ?></span>
						<b class="pill <?php echo esc_attr(!empty($s['ok']) ? 'ok' : 'fail'); ?>"><?php echo esc_html(!empty($s['ok']) ? __('OK','merchant-wiki-seo-audit') : __('FAIL','merchant-wiki-seo-audit')); ?></b>
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
					<details style="margin-top:6px"<?php if (!$ok_all) { echo ' open'; } ?>>
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
							printf(
								' <span class="%s">[%s]</span>',
								esc_attr($ok_all ? 'ok' : 'fail'),
								esc_html(implode(', ', $bits))
							);
							?>
						</summary>
						<?php if (!empty($t['issues'])): ?>
							<ul class="mw-list" style="margin-top:6px">
								<?php foreach ((array)$t['issues'] as $iss): ?>
									<li><?php echo esc_html($iss); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else: ?>
							<div><?php echo esc_html__('No issues.', 'merchant-wiki-seo-audit'); ?></div>
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
				<h3><?php echo esc_html__('Sitemaps detected','merchant-wiki-seo-audit'); ?></h3>
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
				<h3><?php echo esc_html__('Debug','merchant-wiki-seo-audit'); ?></h3>
				<ul class="mw-kv">
					<li><span><?php echo esc_html__('DB prefix','merchant-wiki-seo-audit'); ?></span><b class="pill neutral"><?php echo esc_html($db_prefix); ?></b></li>
					<li><span><?php echo esc_html__('Inventory table','merchant-wiki-seo-audit'); ?></span><b class="pill neutral"><?php echo esc_html($schema_tables['inv']['table'] ?? ($h['tables']['inv'] ?? '—')); ?></b></li>
					<li><span><?php echo esc_html__('Status table','merchant-wiki-seo-audit'); ?></span><b class="pill neutral"><?php echo esc_html($schema_tables['st']['table'] ?? ($h['tables']['st'] ?? '—')); ?></b></li>
					<li><span><?php echo esc_html__('PC map table','merchant-wiki-seo-audit'); ?></span><b class="pill neutral"><?php echo esc_html($schema_tables['pc']['table'] ?? ($h['tables']['pc'] ?? '—')); ?></b></li>
					<li><span><?php echo esc_html__('WPDB last error','merchant-wiki-seo-audit'); ?></span><b class="pill neutral"><?php echo !empty($h['last_error']) ? esc_html($h['last_error']) : '—'; ?></b></li>
					<li><span><?php echo esc_html__('Queue','merchant-wiki-seo-audit'); ?></span><b class="pill neutral"><?php echo esc_html(is_string($h['last_queue'] ?? null) ? $h['last_queue'] : '—'); ?></b></li>
					<li><span><?php echo esc_html__('Drop tables on uninstall','merchant-wiki-seo-audit'); ?></span><b class="pill neutral"><?php echo esc_html($h['drop_on_uninstall'] ?? 'NO'); ?></b></li>
				</ul>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('mw_audit_selftest', 'mw_audit_selftest_nonce'); ?>
					<input type="hidden" name="action" value="mw_audit_selftest">
					<button class="button"><?php echo esc_html__('Self-Test (INSERT/SELECT)','merchant-wiki-seo-audit'); ?></button>
				</form>
			</div>

		</div><!-- /.mw-grid -->
	<?php endif; ?>

	<?php if ($view === 'operations'): ?>
		<!-- ACTIONS / BLOCKS -->
		<div class="mw-actions">

			<!-- Rebuild Inventory -->
			<div id="mw-inventory-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['inv']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('Rebuild Inventory','merchant-wiki-seo-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Collects all public URLs. Required before any audit.','merchant-wiki-seo-audit'); ?></p>
				<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
				<div class="stats">
					<span><?php echo esc_html__('Done:','merchant-wiki-seo-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
					<span><?php echo esc_html__('Errors:','merchant-wiki-seo-audit'); ?> <b class="errors pill neutral">0</b></span>
					<span><?php echo esc_html__('Progress:','merchant-wiki-seo-audit'); ?> <b class="percent pill neutral">0%</b></span>
					<span><?php echo esc_html__('Phase:','merchant-wiki-seo-audit'); ?> <b class="phase pill neutral">—</b></span>
					<span>ETA: <b class="eta pill neutral">—</b></span>
					<span>Batch: <b class="batch pill neutral">—</b></span>
				</div>
				<p class="actions">
					<button class="button start"><?php echo esc_html__('Start','merchant-wiki-seo-audit'); ?></button>
					<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-seo-audit'); ?></button>
					<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-seo-audit'); ?></button>
				</p>
			</div>

			<!-- Prepare / Cache Sitemaps -->
			<div id="mw-sitemaps" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['sm']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('Prepare / Cache Sitemaps','merchant-wiki-seo-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Downloads sitemap(s) to detect which URLs are declared for indexing.','merchant-wiki-seo-audit'); ?></p>
				<div class="stats">
					<span><?php echo esc_html__('Files:','merchant-wiki-seo-audit'); ?> <b class="sm-count pill neutral"><?php echo intval($h['sitemap_cache_count']); ?></b></span>
					<span><?php echo esc_html__('Sources:','merchant-wiki-seo-audit'); ?> <b class="sm-sources pill neutral"><?php echo !empty($h['sitemaps']) ? count($h['sitemaps']) : 0; ?></b></span>
					<span><?php echo esc_html__('Age:','merchant-wiki-seo-audit'); ?> <b class="sm-age pill neutral"><?php echo $h['sitemap_cache_age']!==null ? intval($h['sitemap_cache_age']).'s' : '—'; ?></b></span>
				</div>
				<p class="actions"><button class="button button-primary sm-prepare"><?php echo esc_html__('Prepare now','merchant-wiki-seo-audit'); ?></button></p>
			</div>

			<!-- Refresh On-Site Signals — AJAX -->
			<div id="mw-audit-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['os']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('Refresh On-Site Signals','merchant-wiki-seo-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Checks HTTP status, robots, canonical, noindex, schema — fix technical SEO issues before indexing.','merchant-wiki-seo-audit'); ?></p>
				<label style="display:block;margin:6px 0 8px">
					<?php echo esc_html__('Profile:','merchant-wiki-seo-audit'); ?>
					<select class="profile">
						<option value="fast" <?php selected($settings['profile_defaults'], 'fast'); ?>><?php echo esc_html__('Fast','merchant-wiki-seo-audit'); ?></option>
						<option value="standard" <?php selected($settings['profile_defaults'], 'standard'); ?>><?php echo esc_html__('Standard','merchant-wiki-seo-audit'); ?></option>
						<option value="safe" <?php selected($settings['profile_defaults'], 'safe'); ?>><?php echo esc_html__('Safe','merchant-wiki-seo-audit'); ?></option>
					</select>
					<small class="muted"><?php echo esc_html__('Auto-tunes batch/budget/timeouts using EWMA per-URL time.','merchant-wiki-seo-audit'); ?></small>
				</label>
				<label><input type="checkbox" class="also-pc" checked> <?php echo esc_html__('Also rebuild Post → Primary Category Map','merchant-wiki-seo-audit'); ?></label>
				<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
				<div class="stats">
					<span><?php echo esc_html__('Done:','merchant-wiki-seo-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
					<span><?php echo esc_html__('Errors:','merchant-wiki-seo-audit'); ?> <b class="errors pill neutral">0</b></span>
					<span><?php echo esc_html__('Progress:','merchant-wiki-seo-audit'); ?> <b class="percent pill neutral">0%</b></span>
					<span>ETA: <b class="eta pill neutral">—</b></span>
					<span>Batch: <b class="batch pill neutral">—</b></span>
				</div>
				<p class="actions">
					<button class="button button-primary start"><?php echo esc_html__('Start','merchant-wiki-seo-audit'); ?></button>
					<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-seo-audit'); ?></button>
					<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-seo-audit'); ?></button>
				</p>
			</div>

			<!-- HTTP-only -->
			<div id="mw-http-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['http']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('HTTP-only Signals','merchant-wiki-seo-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Lightweight HTTP check (no internal links). Use for quick status update.','merchant-wiki-seo-audit'); ?></p>
				<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
				<div class="stats">
					<span><?php echo esc_html__('Done:','merchant-wiki-seo-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
					<span><?php echo esc_html__('Errors:','merchant-wiki-seo-audit'); ?> <b class="errors pill neutral">0</b></span>
					<span><?php echo esc_html__('Progress:','merchant-wiki-seo-audit'); ?> <b class="percent pill neutral">0%</b></span>
					<span>ETA: <b class="eta pill neutral">—</b></span>
					<span>Batch: <b class="batch pill neutral">—</b></span>
				</div>
				<p class="actions">
					<button class="button start"><?php echo esc_html__('Start','merchant-wiki-seo-audit'); ?></button>
					<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-seo-audit'); ?></button>
					<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-seo-audit'); ?></button>
				</p>
			</div>

			<!-- PC map -->
			<div id="mw-pc-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['pc']['status'] ?? '')); ?>">
				<h3><?php echo esc_html__('Post → Primary Category Map','merchant-wiki-seo-audit'); ?></h3>
				<p class="mw-box-hint"><?php echo esc_html__('Resolves main category per post (for content grouping).','merchant-wiki-seo-audit'); ?></p>
				<?php if ($pc_taxonomies): ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-inline-form" style="margin-bottom:10px;">
						<?php wp_nonce_field('mw_audit_pc_tax', 'mw_audit_pc_tax_nonce'); ?>
						<input type="hidden" name="action" value="mw_audit_save_pc_tax">
						<label>
							<?php echo esc_html__('Primary taxonomy','merchant-wiki-seo-audit'); ?>:
							<select name="pc_taxonomy">
								<?php foreach ($pc_taxonomies as $slug => $label): ?>
									<option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $current_pc_tax); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button class="button button-small"><?php echo esc_html__('Save','merchant-wiki-seo-audit'); ?></button>
					</form>
				<?php endif; ?>
				<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
				<div class="stats">
					<span><?php echo esc_html__('Done:','merchant-wiki-seo-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
					<span><?php echo esc_html__('Errors:','merchant-wiki-seo-audit'); ?> <b class="errors pill neutral">0</b></span>
					<span><?php echo esc_html__('Progress:','merchant-wiki-seo-audit'); ?> <b class="percent pill neutral">0%</b></span>
					<span>ETA: <b class="eta pill neutral">—</b></span>
				</div>
				<p class="actions">
					<button class="button start"><?php echo esc_html__('Build Map','merchant-wiki-seo-audit'); ?></button>
					<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-seo-audit'); ?></button>
					<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-seo-audit'); ?></button>
				</p>
			</div>

				<!-- Internal links -->
				<div id="mw-links-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['link']['status'] ?? '')); ?>">
					<h3><?php echo esc_html__('Internal Link Scan','merchant-wiki-seo-audit'); ?></h3>
					<p class="mw-box-hint"><?php echo esc_html__('Counts inbound internal links — pages with 0 links are hard to index.','merchant-wiki-seo-audit'); ?></p>
					<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
					<div class="stats">
						<span><?php echo esc_html__('Done:','merchant-wiki-seo-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
						<span><?php echo esc_html__('Errors:','merchant-wiki-seo-audit'); ?> <b class="errors pill neutral">0</b></span>
						<span><?php echo esc_html__('Progress:','merchant-wiki-seo-audit'); ?> <b class="percent pill neutral">0%</b></span>
						<span>ETA: <b class="eta pill neutral">—</b></span>
						<span>Batch: <b class="batch pill neutral">—</b></span>
					</div>
					<p class="actions">
						<button class="button start"><?php echo esc_html__('Start','merchant-wiki-seo-audit'); ?></button>
						<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-seo-audit'); ?></button>
						<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-seo-audit'); ?></button>
					</p>
				</div>

				<!-- Outbound links -->
				<div id="mw-outbound-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['outbound']['status'] ?? '')); ?>">
					<h3><?php echo esc_html__('Outbound Link Scan','merchant-wiki-seo-audit'); ?></h3>
					<p class="mw-box-hint"><?php echo esc_html__('Counts outbound links per page (internal vs external) and unique external domains. Helpful for spotting “dead-end” pages or excessive external linking.','merchant-wiki-seo-audit'); ?></p>
					<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
					<div class="stats">
						<span><?php echo esc_html__('Done:','merchant-wiki-seo-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
						<span><?php echo esc_html__('Errors:','merchant-wiki-seo-audit'); ?> <b class="errors pill neutral">0</b></span>
						<span><?php echo esc_html__('Progress:','merchant-wiki-seo-audit'); ?> <b class="percent pill neutral">0%</b></span>
						<span>ETA: <b class="eta pill neutral">—</b></span>
						<span>Batch: <b class="batch pill neutral">—</b></span>
					</div>
					<p class="actions">
						<button class="button start"><?php echo esc_html__('Start','merchant-wiki-seo-audit'); ?></button>
						<button class="button stop"><?php echo esc_html__('Stop','merchant-wiki-seo-audit'); ?></button>
						<button class="button resume"><?php echo esc_html__('Resume','merchant-wiki-seo-audit'); ?></button>
					</p>
					<p class="description"><?php echo esc_html__('No external quotas involved. Batch size adapts the same way as the internal link scan.','merchant-wiki-seo-audit'); ?></p>
				</div>

				<!-- Google index -->
			<?php $gindex_enabled = (!empty($gsc['connected']) && !empty($gsc['property']) && !empty($settings['gsc_api_enabled'])); ?>
			<div id="mw-gindex-progress" class="mw-box <?php echo esc_attr(mw_audit_stat_class($steps['gindex']['status'] ?? '')); ?>"
				data-stale-total="<?php echo esc_attr($gsc_stale_total); ?>"
				data-queued-total="<?php echo esc_attr($gsc_queue_candidates); ?>"
				data-stale-remaining="<?php echo esc_attr($gsc_stale_after_hint); ?>">
				<h3><?php echo esc_html__('Check Google Index Status','merchant-wiki-seo-audit'); ?></h3>
				<p class="mw-box-hint"><?php
					$settings_link = admin_url('admin.php?page=mw-site-index-settings#mw-gsc-settings');
					printf(
						'%s <a href="%s">%s</a>',
						esc_html__('Connect Google Search Console in Settings → Google Search Console to enable this block.','merchant-wiki-seo-audit'),
						esc_url($settings_link),
						esc_html__('Open settings','merchant-wiki-seo-audit')
					);
				?></p>
				<p class="mw-box-hint"><?php echo esc_html__('Fetches indexation status from Google Search Console (cached). Use to find "not indexed" pages.','merchant-wiki-seo-audit'); ?></p>
				<?php if (!$gindex_enabled): ?>
					<p class="description" style="color:#d63638;"><?php
						if (empty($gsc['connected']) || empty($gsc['property'])) {
							echo esc_html__('Connect Google Search Console and select a property before running this step.','merchant-wiki-seo-audit');
						} else {
							echo esc_html__('Enable the Inspection API in settings to run this step.','merchant-wiki-seo-audit');
						}
					?></p>
				<?php endif; ?>
					<label style="display:block; margin:6px 0;">
						<?php echo esc_html__('Batch size','merchant-wiki-seo-audit'); ?>
						<input type="number" class="small-text batch-input" value="5" min="1" max="100">
					</label>
					<label style="display:block; margin:6px 0;">
						<input type="checkbox" class="only-stale" checked> <?php echo esc_html__('Only queue stale/new URLs','merchant-wiki-seo-audit'); ?>
					</label>
					<p class="description" style="margin-top:-4px;"><?php echo esc_html__('Uncheck to force a full refresh (uses more quota).','merchant-wiki-seo-audit'); ?></p>
					<ul class="mw-gindex-estimate">
						<li>
							<span><?php echo esc_html__('Queued this run','merchant-wiki-seo-audit'); ?></span>
							<b id="mw-gindex-queued" data-default="<?php echo esc_attr($gsc_queue_candidates); ?>"><?php echo esc_html($gsc_queue_candidates); ?></b>
						</li>
						<li>
							<span><?php echo esc_html__('Stale overall','merchant-wiki-seo-audit'); ?></span>
							<b id="mw-gindex-stale" data-default="<?php echo esc_attr($gsc_stale_total); ?>"><?php echo esc_html($gsc_stale_total); ?></b>
						</li>
						<li>
							<span><?php echo esc_html__('Likely remaining after this run','merchant-wiki-seo-audit'); ?></span>
							<b id="mw-gindex-remaining" data-default="<?php echo esc_attr($gsc_stale_after_hint); ?>"><?php echo esc_html($gsc_stale_after_hint); ?></b>
						</li>
					</ul>
					<div class="bar"><div class="bar-fill" style="width:0%"></div></div>
					<div class="stats">
						<span><?php echo esc_html__('Done:','merchant-wiki-seo-audit'); ?> <b class="done pill neutral">0</b>/<b class="total pill neutral">0</b></span>
						<span><?php echo esc_html__('Errors:','merchant-wiki-seo-audit'); ?> <b class="errors pill neutral">0</b></span>
						<span><?php echo esc_html__('Progress:','merchant-wiki-seo-audit'); ?> <b class="percent pill neutral">0%</b></span>
						<span>ETA: <b class="eta pill neutral">—</b></span>
						<span>Batch: <b class="batch pill neutral">—</b></span>
					</div>
						<p class="actions">
							<button class="button start" <?php disabled(!$gindex_enabled); ?>><?php echo esc_html__('Start','merchant-wiki-seo-audit'); ?></button>
							<button class="button stop" <?php disabled(!$gindex_enabled); ?>><?php echo esc_html__('Stop','merchant-wiki-seo-audit'); ?></button>
							<button class="button resume" <?php disabled(!$gindex_enabled); ?>><?php echo esc_html__('Resume','merchant-wiki-seo-audit'); ?></button>
							<button class="button reset-lock" <?php disabled(!$gindex_enabled); ?> title="<?php echo esc_attr__('Clear the Inspection queue lock if Start fails with “Request failed”.','merchant-wiki-seo-audit'); ?>"><?php echo esc_html__('Clear lock','merchant-wiki-seo-audit'); ?></button>
						</p>
					<p class="description"><?php echo esc_html__('The inspection API is subject to quota limits. Consider running during off-peak hours.','merchant-wiki-seo-audit'); ?></p>
				</div>

		</div><!-- /.mw-actions -->
		<span id="mw-gsc-import"></span>
		<div class="mw-card mw-gsc-import-card">
			<h3><?php echo esc_html__('Google Search Console Imports','merchant-wiki-seo-audit'); ?></h3>
			<p class="mw-box-hint">
				<?php
				printf(
					/* translators: %s: Google Search Console Page Indexing link. */
					esc_html__('Download the Page Indexing export directly from %s and upload Table.csv (plus Metadata.csv).','merchant-wiki-seo-audit'),
					sprintf(
						'<a href="%s" target="_blank" rel="noreferrer noopener">%s</a>',
						esc_url('https://search.google.com/search-console/index'),
						esc_html__('Google Search Console → Page Indexing','merchant-wiki-seo-audit')
					)
				);
				?>
			</p>
			<?php
				$gsc_import_mode = $settings['gsc_import_mode'] ?? 'csv';
				$gsc_mode_is_sheets = ($gsc_import_mode === 'sheets');
			?>
			<div class="mw-gsc-mode-switch">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-gsc-mode-form">
					<?php wp_nonce_field('mw_audit_save_settings', 'mw_audit_save_settings_nonce_alt'); ?>
					<input type="hidden" name="action" value="mw_audit_save_settings">
					<label>
						<span><?php echo esc_html__('Choose how to import GSC data','merchant-wiki-seo-audit'); ?></span>
						<select id="mw-gsc-import-mode" name="gsc_import_mode">
							<option value="csv" <?php selected($gsc_import_mode, 'csv'); ?>><?php echo esc_html__('Import GSC as CSV','merchant-wiki-seo-audit'); ?></option>
							<option value="sheets" <?php selected($gsc_import_mode, 'sheets'); ?>><?php echo esc_html__('Import via Google Sheet','merchant-wiki-seo-audit'); ?></option>
						</select>
					</label>
					<button class="button" type="submit"><?php echo esc_html__('Save preference','merchant-wiki-seo-audit'); ?></button>
				</form>
				<p id="mw-gsc-sheets-warning" class="description <?php echo $gsc_mode_is_sheets ? '' : 'mw-hidden'; ?>"<?php echo $gsc_mode_is_sheets ? '' : ' hidden'; ?>>
					<?php echo esc_html__('This is a complex integration that requires Google Cloud Console credentials and we do not recommend it unless you need automation.','merchant-wiki-seo-audit'); ?>
				</p>
			</div>

			<div class="mw-gsc-mode-section mw-gsc-mode-sheets <?php echo $gsc_mode_is_sheets ? '' : 'mw-hidden'; ?>"<?php echo $gsc_mode_is_sheets ? '' : ' hidden'; ?>>
				<div class="mw-gsc-import-block">
					<h4><?php echo esc_html__('Page Indexing Import','merchant-wiki-seo-audit'); ?></h4>
					<p class="mw-box-hint"><?php echo esc_html__('Imports "Page indexing" report from GSC to detect why pages are excluded from index.','merchant-wiki-seo-audit'); ?></p>
					<div class="mw-gsc-assemble">
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Google Sheets links (one per line)','merchant-wiki-seo-audit'); ?>
							<textarea id="mw-gsc-assemble-input" class="regular-text mw-card-field" rows="4" style="width:100%;max-width:100%;" placeholder="https://docs.google.com/spreadsheets/d/...\nhttps://docs.google.com/spreadsheets/d/...\n..."></textarea>
						</label>
						<button type="button" class="button" id="mw-gsc-assemble-button"><?php echo esc_html__('Assemble result sheet','merchant-wiki-seo-audit'); ?></button>
						<span id="mw-gsc-assemble-status" class="mw-inline-status" aria-live="polite"></span>
					</div>
					<?php if ($gsc_has_sheets): ?>
						<div class="mw-gsc-sheets">
							<label style="display:block; margin-bottom:6px;">
								<?php echo esc_html__('Sheet URL or ID','merchant-wiki-seo-audit'); ?>
								<input type="text" id="mw-gsc-sheet-input" class="regular-text mw-card-field" style="width:100%;max-width:100%;" placeholder="https://docs.google.com/spreadsheets/d/...">
							</label>
							<label style="display:block; margin-bottom:6px;">
								<?php echo esc_html__('Range (e.g. Page indexing!A:Z)','merchant-wiki-seo-audit'); ?>
								<input type="text" id="mw-gsc-sheet-range" class="regular-text mw-card-field" style="width:100%;max-width:100%;" value="A:Z">
							</label>
							<label style="display:block; margin-bottom:10px;">
								<input type="checkbox" id="mw-gsc-sync-override" value="1">
								<?php echo esc_html__('Allow Page indexing reasons to overwrite inspection coverage','merchant-wiki-seo-audit'); ?>
							</label>
							<button type="button" class="button" id="mw-gsc-sync-button"><?php echo esc_html__('Sync Page indexing (Sheets)','merchant-wiki-seo-audit'); ?></button>
							<span id="mw-gsc-sync-status" class="mw-inline-status" aria-live="polite"></span>
						</div>
					<?php else: ?>
						<p class="description"><?php echo esc_html__('Use the Connect Sheets button in the settings card to enable this mode.','merchant-wiki-seo-audit'); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="mw-gsc-mode-section mw-gsc-mode-csv <?php echo $gsc_mode_is_sheets ? 'mw-hidden' : ''; ?>"<?php echo $gsc_mode_is_sheets ? ' hidden' : ''; ?>>
				<div class="mw-gsc-import-block">
					<h4><?php echo esc_html__('Page Indexing Import','merchant-wiki-seo-audit'); ?></h4>
					<p class="mw-box-hint"><?php echo esc_html__('Imports "Page indexing" report from GSC to detect why pages are excluded from index.','merchant-wiki-seo-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="mw-gsc-import-csv">
						<?php wp_nonce_field('mw_gsc_import_pi_csv'); ?>
						<input type="hidden" name="action" value="mw_gsc_import_pi_csv">
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Table CSV (Table.csv)','merchant-wiki-seo-audit'); ?>
							<input type="file" name="mw_gsc_pi_table" accept=".csv" required>
						</label>
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Metadata CSV (Metadata.csv, optional)','merchant-wiki-seo-audit'); ?>
							<input type="file" name="mw_gsc_pi_meta" accept=".csv">
						</label>
						<label style="display:block; margin-bottom:6px;">
							<input type="checkbox" name="override" value="1">
							<?php echo esc_html__('Allow Page indexing data to overwrite inspection coverage','merchant-wiki-seo-audit'); ?>
						</label>
						<button class="button"><?php echo esc_html__('Import CSV','merchant-wiki-seo-audit'); ?></button>
					</form>
				</div>
			</div>

			<p class="description" style="margin-top:10px;">
				<?php echo esc_html__('Index coverage data comes from Google Search Console and may lag by a few days. Site ownership in GSC is required.','merchant-wiki-seo-audit'); ?>
			</p>
		</div>
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
			$refresh_mode = sanitize_key(mw_audit_get_query_arg('mw_refresh_mode', 'days365'));
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
			<?php echo esc_html__('Jump to:','merchant-wiki-seo-audit'); ?>
			<a href="#mw-refresh-card"><?php echo esc_html__('Stale content refresh','merchant-wiki-seo-audit'); ?></a> ·
			<a href="#mw-priority-block"><?php echo esc_html__('Priority: Ready to Submit','merchant-wiki-seo-audit'); ?></a> ·
			<a href="#mw-report-preview"><?php echo esc_html__('Preview (first 100 rows)','merchant-wiki-seo-audit'); ?></a> ·
			<a href="#" id="mw-similar-open" title="<?php echo esc_attr__('Pick a reference page and surface other URLs with the same age/index/link profile.','merchant-wiki-seo-audit'); ?>"><?php echo esc_html__('Find similar URL','merchant-wiki-seo-audit'); ?></a> ·
			<a href="#mw-outbound-summary"><?php echo esc_html__('Outbound Link Summary','merchant-wiki-seo-audit'); ?></a>
		</p>

			<div class="mw-card mw-next-steps-card">
			<h3><?php echo esc_html__('Case automations (Next steps)','merchant-wiki-seo-audit'); ?></h3>
			<p class="mw-report-hint"><?php echo esc_html__('Generate the artifacts mentioned in the README “Next steps” without leaving the Reports tab.','merchant-wiki-seo-audit'); ?></p>
			<div class="mw-next-grid">
				<section class="mw-next-cell">
					<h4><?php echo esc_html__('Quick technical audit','merchant-wiki-seo-audit'); ?></h4>
					<p><?php echo esc_html__('Download blockers grouped by HTTP errors, sitemap gaps, and noindex flags for ticketing.','merchant-wiki-seo-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('mw_next_steps_quick'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_quick">
						<button class="button button-primary"><?php echo esc_html__('Export blocker CSV','merchant-wiki-seo-audit'); ?></button>
					</form>
					<p class="description"><?php echo esc_html__('After fixes, rerun Refresh On-Site Signals from Operations.','merchant-wiki-seo-audit'); ?></p>
				</section>

				<section class="mw-next-cell">
					<h4><?php echo esc_html__('Manual indexing queue','merchant-wiki-seo-audit'); ?></h4>
					<p><?php echo esc_html__('When Inspection API quota is exhausted, export a ready-made queue with GSC reasons and internal link evidence.','merchant-wiki-seo-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('mw_next_steps_manual'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_manual">
						<label style="display:block;margin-bottom:6px;">
							<?php echo esc_html__('Inbound link threshold','merchant-wiki-seo-audit'); ?>
							<input type="number" class="small-text" name="threshold" min="0" max="10" value="<?php echo esc_attr($manual_queue_threshold); ?>">
						</label>
						<button class="button"><?php echo esc_html__('Export queue CSV','merchant-wiki-seo-audit'); ?></button>
					</form>
					<p class="description"><?php echo esc_html__('Feed the CSV into manual submissions, then run Google Index Status with “Only queue stale/new URLs”.','merchant-wiki-seo-audit'); ?></p>
				</section>

				<section class="mw-next-cell">
					<h4><?php echo esc_html__('Post-launch monitoring snapshots','merchant-wiki-seo-audit'); ?></h4>
					<p><?php echo esc_html__('Capture HTTP/canonical state after each scan and diff two snapshots to prove deltas.','merchant-wiki-seo-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;">
						<?php wp_nonce_field('mw_next_steps_snapshot'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_snapshot">
						<label style="display:block;margin-bottom:4px;"><?php echo esc_html__('Snapshot label','merchant-wiki-seo-audit'); ?></label>
						<input type="text" name="snapshot_label" class="regular-text" placeholder="<?php echo esc_attr__('e.g. Pre-launch crawl','merchant-wiki-seo-audit'); ?>">
						<button class="button" style="margin-top:6px;"><?php echo esc_html__('Save snapshot','merchant-wiki-seo-audit'); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-snapshot-diff">
						<?php wp_nonce_field('mw_next_steps_diff'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_diff">
						<label>
							<?php echo esc_html__('Before','merchant-wiki-seo-audit'); ?>
							<select name="snapshot_old" <?php disabled(!$next_steps_can_diff); ?>>
								<?php if (!empty($next_steps_snapshots)): ?>
									<?php foreach ($next_steps_snapshots as $snap): ?>
										<option value="<?php echo esc_attr($snap['id']); ?>"><?php echo esc_html($snap['label'].' — '.mysql2date('Y-m-d H:i', $snap['created_at'])); ?></option>
									<?php endforeach; ?>
								<?php else: ?>
									<option value=""><?php echo esc_html__('No snapshots yet','merchant-wiki-seo-audit'); ?></option>
								<?php endif; ?>
							</select>
						</label>
						<label>
							<?php echo esc_html__('After','merchant-wiki-seo-audit'); ?>
							<select name="snapshot_new" <?php disabled(!$next_steps_can_diff); ?>>
								<?php if (!empty($next_steps_snapshots)): ?>
									<?php foreach ($next_steps_snapshots as $snap): ?>
										<option value="<?php echo esc_attr($snap['id']); ?>"><?php echo esc_html($snap['label'].' — '.mysql2date('Y-m-d H:i', $snap['created_at'])); ?></option>
									<?php endforeach; ?>
								<?php else: ?>
									<option value=""><?php echo esc_html__('No snapshots yet','merchant-wiki-seo-audit'); ?></option>
								<?php endif; ?>
							</select>
						</label>
						<button class="button" <?php disabled(!$next_steps_can_diff); ?>><?php echo esc_html__('Download diff CSV','merchant-wiki-seo-audit'); ?></button>
					</form>
					<?php if (!$next_steps_can_diff): ?>
						<p class="description"><?php echo esc_html__('Save at least two snapshots to enable diff downloads.','merchant-wiki-seo-audit'); ?></p>
					<?php endif; ?>
				</section>

				<section class="mw-next-cell">
					<h4><?php echo esc_html__('Content pruning (beyond Yoast/Rank Math)','merchant-wiki-seo-audit'); ?></h4>
					<p><?php echo esc_html__('Find zero-link URLs leaking to external domains with GSC reasons so you can redirect, 410, or improve them.','merchant-wiki-seo-audit'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('mw_next_steps_pruning'); ?>
						<input type="hidden" name="action" value="mw_audit_next_steps_pruning">
						<button class="button"><?php echo esc_html__('Export pruning CSV','merchant-wiki-seo-audit'); ?></button>
					</form>
					<p class="description"><?php echo esc_html__('CSV flags orphaned pages with outbound leaks — something on-page SEO plugins miss.','merchant-wiki-seo-audit'); ?></p>
				</section>
			</div>
			<?php if (!empty($next_steps_snapshots)): ?>
				<div class="mw-snapshot-history">
					<h4><?php echo esc_html__('Saved snapshots','merchant-wiki-seo-audit'); ?></h4>
					<ul class="mw-snapshot-list">
						<?php foreach ($next_steps_snapshots as $snap): ?>
							<li>
								<strong><?php echo esc_html($snap['label']); ?></strong>
								<span><?php echo esc_html(mysql2date('Y-m-d H:i', $snap['created_at'])); ?></span>
								<span>
									<?php
									printf(
										/* translators: %d: number of rows inside a snapshot export. */
										esc_html__('Rows: %d','merchant-wiki-seo-audit'),
										(int) $snap['rows']
									);
									?>
								</span>
								<?php if ($next_steps_snapshot_url && !empty($snap['filename'])): ?>
									<a href="<?php echo esc_url($next_steps_snapshot_url.$snap['filename']); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Download raw','merchant-wiki-seo-audit'); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>

		<div class="mw-card mw-refresh-card" id="mw-refresh-card">
			<h3><?php echo esc_html__('Stale content refresh','merchant-wiki-seo-audit'); ?></h3>
			<p class="mw-report-hint">
				<?php
					$refresh_stale_days = max(1, (int) $refresh_min_days);
					$refresh_message = sprintf(
						/* translators: %d: minimum number of days without updates before a page is considered stale. */
						__('Shows published posts/pages that have not been updated for ≥%d days so you can refresh them before rankings slip.','merchant-wiki-seo-audit'),
						$refresh_stale_days
					);
					echo esc_html($refresh_message);
				?>
			</p>
			<p class="mw-report-hint mw-gemini-reminder"><?php echo esc_html__('Use “Open Gemini” to copy the recommended brief, open Gemini in a new tab, and paste the current page content immediately.','merchant-wiki-seo-audit'); ?></p>
				<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="mw-refresh-filter">
					<input type="hidden" name="page" value="<?php echo esc_attr($current_page_slug); ?>">
					<input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
					<input type="hidden" name="dir" value="<?php echo esc_attr($dir); ?>">
					<?php if ($filter_likely): ?><input type="hidden" name="mw_filter_likely" value="1"><?php endif; ?>
					<?php if ($filter_stale): ?><input type="hidden" name="mw_filter_stale" value="1"><?php endif; ?>
					<?php if ($filter_never): ?><input type="hidden" name="mw_filter_never" value="1"><?php endif; ?>
					<input type="hidden" name="mw_filter_new" value="<?php echo esc_attr($filter_new_hours); ?>">
					<label class="mw-refresh-mode-label">
						<span><?php echo esc_html__('Preset','merchant-wiki-seo-audit'); ?></span>
						<select name="mw_refresh_mode" onchange="this.form.submit()">
							<option value="days365" <?php selected($refresh_mode, 'days365'); ?>><?php echo esc_html__('≥365 days since last update','merchant-wiki-seo-audit'); ?></option>
							<option value="last_year" <?php selected($refresh_mode, 'last_year'); ?>><?php echo esc_html__('Last calendar year (stale)','merchant-wiki-seo-audit'); ?></option>
							<option value="top5" <?php selected($refresh_mode, 'top5'); ?>><?php echo esc_html__('Oldest 5 articles','merchant-wiki-seo-audit'); ?></option>
						</select>
					</label>
					<label>
						<input type="checkbox" name="mw_refresh_zero_inbound" value="1" <?php checked($refresh_zero_only); ?> onchange="this.form.submit()">
						<?php echo esc_html__('Only show URLs with zero inbound links','merchant-wiki-seo-audit'); ?>
					</label>
					<noscript><button class="button"><?php echo esc_html__('Apply','merchant-wiki-seo-audit'); ?></button></noscript>
			</form>
				<?php if (!empty($refresh_candidates)): ?>
					<?php
						$refresh_current_year = (int) (function_exists('wp_date') ? wp_date('Y', $refresh_now_ts) : date_i18n('Y', $refresh_now_ts));
					?>
					<p class="mw-report-hint mw-refresh-howto">
						<strong><?php echo esc_html__('Quick recipe:','merchant-wiki-seo-audit'); ?></strong>
						<?php echo esc_html__('Open the URL, click “Open Gemini”, paste the current copy, and ask the model to refresh the text plus provide two supporting external links. Publish the updates so the page drops out automatically.','merchant-wiki-seo-audit'); ?>
					</p>
					<div class="mw-table-wrap">
						<table class="mw-table mw-refresh-table">
						<thead>
							<tr>
								<th><?php echo esc_html__('Page','merchant-wiki-seo-audit'); ?></th>
								<th><?php echo esc_html__('Published','merchant-wiki-seo-audit'); ?></th>
								<th><?php echo esc_html__('Last updated','merchant-wiki-seo-audit'); ?></th>
								<th><?php echo esc_html__('Inbound links','merchant-wiki-seo-audit'); ?></th>
								<th><?php echo esc_html__('Meta description','merchant-wiki-seo-audit'); ?></th>
								<th><?php echo esc_html__('Gemini','merchant-wiki-seo-audit'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($refresh_candidates as $candidate): ?>
								<?php
									$candidate_title = isset($candidate['title']) && $candidate['title'] !== '' ? $candidate['title'] : __('(no title)','merchant-wiki-seo-audit');
									$candidate_title_plain = wp_strip_all_tags($candidate_title);
									$permalink = $candidate['permalink'];
									$published_label = (!empty($candidate['published_at']) && $candidate['published_at'] !== '0000-00-00 00:00:00') ? mysql2date('Y-m-d', $candidate['published_at']) : '—';
									$modified_label = (!empty($candidate['modified_at']) && $candidate['modified_at'] !== '0000-00-00 00:00:00') ? mysql2date('Y-m-d', $candidate['modified_at']) : $published_label;
									$days_since_update = isset($candidate['days_since_update']) ? (int) $candidate['days_since_update'] : null;
									$days_label = $days_since_update !== null
										? sprintf(
											/* translators: %d: number of days since the content was last updated. */
											_n('%d day ago','%d days ago', $days_since_update, 'merchant-wiki-seo-audit'),
											$days_since_update
										)
										: '—';
									$has_meta_description = isset($candidate['meta_description']) && $candidate['meta_description'] !== '';
									$meta_description = $has_meta_description
										? $candidate['meta_description']
										: __('Meta description not set','merchant-wiki-seo-audit');
									$meta_plain = $has_meta_description ? wp_strip_all_tags($candidate['meta_description']) : '';
									$inbound_total = isset($candidate['inbound_links']) ? (int) $candidate['inbound_links'] : null;
									$gemini_prompt_parts = array();
									$gemini_prompt_parts[] = sprintf(
										/* translators: %s = page URL */
										__('Update the page %s in the language of its original content.', 'merchant-wiki-seo-audit'),
										$permalink
									);
									if ($published_label && $published_label !== '—') {
										$gemini_prompt_parts[] = sprintf(
											/* translators: %s = publication date */
											__('Published on: %s.', 'merchant-wiki-seo-audit'),
											$published_label
										);
									}
									if ($modified_label && $modified_label !== '—') {
										$gemini_prompt_parts[] = sprintf(
											/* translators: 1: last updated date, 2: how many days ago */
											__('Last updated on %1$s (~%2$s).', 'merchant-wiki-seo-audit'),
											$modified_label,
											$days_label
										);
									}
									$gemini_prompt_parts[] = __('Refresh the body copy to reflect changes since publication while keeping the structure easy to read.', 'merchant-wiki-seo-audit');
									if (!empty($candidate['needs_current_year_focus'])) {
										$gemini_prompt_parts[] = sprintf(
											/* translators: %d = current year */
											__('If the page has not been updated in %d yet, highlight the key developments from this year.', 'merchant-wiki-seo-audit'),
											$refresh_current_year
										);
									}
									$gemini_prompt_parts[] = __('I will paste the current text below. Suggest improved title and meta description only if they are truly outdated.', 'merchant-wiki-seo-audit');
									$gemini_prompt_parts[] = sprintf(
										/* translators: %s = current title */
										__('Current title: %s.', 'merchant-wiki-seo-audit'),
										$candidate_title_plain
									);
									if ($has_meta_description) {
										$gemini_prompt_parts[] = sprintf(
											/* translators: %s = current meta description */
											__('Current meta description: %s.', 'merchant-wiki-seo-audit'),
											$meta_plain
										);
									} else {
										$gemini_prompt_parts[] = __('Meta description is missing — propose a concise, unique sentence.', 'merchant-wiki-seo-audit');
									}
									$gemini_prompt_parts[] = __('Recommend two relevant external links that would add current references for this article.', 'merchant-wiki-seo-audit');
									$gemini_prompt = implode(' ', $gemini_prompt_parts);
									$clipboard_prompt = $gemini_prompt;
									$content_plain = isset($candidate['content_plain']) ? trim((string) $candidate['content_plain']) : '';
									if ($content_plain !== '') {
										$clipboard_prompt .= "\n\n".__('Current content:','merchant-wiki-seo-audit')."\n".$content_plain;
									} else {
										$clipboard_prompt .= "\n\n".__('Current content could not be loaded automatically. Paste it manually below.', 'merchant-wiki-seo-audit');
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
										<a class="button button-small mw-gemini-link" href="<?php echo esc_url($gemini_url); ?>" target="_blank" rel="noopener" data-prompt="<?php echo esc_attr($gemini_prompt); ?>" data-clipboard="<?php echo esc_attr($clipboard_prompt); ?>" title="<?php echo esc_attr__('Click to copy the full brief (with page content) and open Gemini. Paste it into Gemini immediately.', 'merchant-wiki-seo-audit'); ?>"><?php echo esc_html__('Open Gemini','merchant-wiki-seo-audit'); ?></a>
										<small class="mw-gemini-hint"><?php echo esc_html__('Copies the brief, then opens Gemini in a new tab.', 'merchant-wiki-seo-audit'); ?></small>
										<span class="mw-gemini-status" aria-live="polite"></span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php else: ?>
					<p class="description"><?php echo esc_html__('No stale posts or pages match the current preset. Try switching to “Oldest 5 articles” if you still need quick wins.','merchant-wiki-seo-audit'); ?></p>
				<?php endif; ?>
		</div>

			<div class="mw-card mw-priority-card">
				<div id="mw-priority-block" class="mw-priority">
					<h3><?php echo esc_html__('Priority: Ready to Submit','merchant-wiki-seo-audit'); ?></h3>
					<p class="mw-report-hint"><?php echo esc_html__('To build this report run the following Operations blocks in order:','merchant-wiki-seo-audit'); ?></p>
					<ul class="mw-priority-prereq">
						<li><?php echo esc_html__('Operations → Rebuild Inventory — click Start to refresh the URL list before prioritizing.','merchant-wiki-seo-audit'); ?></li>
						<li><?php echo esc_html__('Operations → Refresh On-Site Signals (keep “Also rebuild Post → Primary Category Map” enabled) — populates HTTP, sitemap, and category data.','merchant-wiki-seo-audit'); ?></li>
						<li><?php echo esc_html__('Operations → Internal Link Scan — updates inbound link counts so the threshold filter works.','merchant-wiki-seo-audit'); ?></li>
						<li><?php echo esc_html__('Operations → Page Indexing Import or Check Google Index Status — refreshes GSC coverage/reasons shown below.','merchant-wiki-seo-audit'); ?></li>
				</ul>
				<p class="mw-box-hint"><?php echo esc_html__('Focus on indexable URLs with HTTP 200, sitemap coverage, and almost zero internal links. Great for manual indexing batches.','merchant-wiki-seo-audit'); ?></p>
				<label style="display:block; margin:6px 0 12px;">
					<?php echo esc_html__('Inbound link threshold','merchant-wiki-seo-audit'); ?>
					<select id="mw-priority-threshold">
						<?php foreach ($priority_thresholds as $pth): ?>
							<?php
								$pth_int = (int) $pth;
								if ($pth_int === 0) {
									$label = __('0 (no links)','merchant-wiki-seo-audit');
								} else {
									$label = sprintf(
										/* translators: %d = inbound link threshold */
										_n('%d link or fewer','%d links or fewer', $pth_int, 'merchant-wiki-seo-audit'),
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
					<button type="button" class="button" id="mw-priority-load"><?php echo esc_html__('Show list','merchant-wiki-seo-audit'); ?></button>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-priority-export-form">
						<?php wp_nonce_field('mw_audit_priority_export'); ?>
						<input type="hidden" name="action" value="mw_audit_priority_export">
						<input type="hidden" name="threshold" id="mw-priority-export-threshold" value="<?php echo esc_attr($priority_threshold); ?>">
						<button class="button"><?php echo esc_html__('Export CSV','merchant-wiki-seo-audit'); ?></button>
					</form>
				</div>
				<p id="mw-priority-status" class="mw-inline-status" aria-live="polite"></p>
				<div id="mw-priority-table-wrap" class="mw-table-wrap mw-priority-table-wrap" style="display:none">
					<table class="mw-table mw-priority-table">
						<thead>
							<tr>
								<th><?php echo esc_html__('URL','merchant-wiki-seo-audit'); ?></th>
								<th><?php echo esc_html__('Inbound','merchant-wiki-seo-audit'); ?></th>
								<th><?php echo esc_html__('Primary category','merchant-wiki-seo-audit'); ?></th>
								<th><?php echo esc_html__('GSC status','merchant-wiki-seo-audit'); ?></th>
								<th><?php echo esc_html__('Published','merchant-wiki-seo-audit'); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>

			<h2 id="mw-report-preview"><?php echo esc_html__('Preview (first 100 rows)','merchant-wiki-seo-audit'); ?></h2>
			<p class="mw-report-hint"><?php echo esc_html__('Use Export CSV to dump the entire dataset (not just the 100-row preview).','merchant-wiki-seo-audit'); ?></p>
			<form id="mw-filters-form" class="mw-filters" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr($current_page_slug); ?>">
				<input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
				<input type="hidden" name="dir" value="<?php echo esc_attr($dir); ?>">
				<input type="hidden" name="mw_refresh_mode" value="<?php echo esc_attr($refresh_mode); ?>">
				<?php if ($refresh_zero_only): ?>
					<input type="hidden" name="mw_refresh_zero_inbound" value="1">
				<?php endif; ?>
				<fieldset>
					<legend><?php echo esc_html__('Filters','merchant-wiki-seo-audit'); ?></legend>
					<label class="mw-filter-option">
						<input type="checkbox" name="mw_filter_likely" value="1" class="mw-autosubmit" <?php checked($filter_likely); ?>>
						<?php echo esc_html__('Show likely not indexed','merchant-wiki-seo-audit'); ?>
					</label>
					<label class="mw-filter-option">
						<input type="checkbox" name="mw_filter_stale" value="1" class="mw-autosubmit" <?php checked($filter_stale); ?>>
						<?php echo esc_html__('Show stale (TTL expired)','merchant-wiki-seo-audit'); ?>
					</label>
					<label class="mw-filter-option">
						<input type="checkbox" name="mw_filter_never" value="1" class="mw-autosubmit" <?php checked($filter_never); ?>>
						<?php echo esc_html__('Show never inspected','merchant-wiki-seo-audit'); ?>
					</label>
				</fieldset>
				<fieldset class="mw-filter-new">
					<legend><?php echo esc_html__('Published','merchant-wiki-seo-audit'); ?></legend>
					<label><input type="radio" name="mw_filter_new" value="0" class="mw-autosubmit" <?php checked($filter_new_hours, 0); ?>><?php echo esc_html__('All pages','merchant-wiki-seo-audit'); ?></label>
					<label><input type="radio" name="mw_filter_new" value="24" class="mw-autosubmit" <?php checked($filter_new_hours, 24); ?>><?php echo esc_html__('Last 24h','merchant-wiki-seo-audit'); ?></label>
					<label><input type="radio" name="mw_filter_new" value="48" class="mw-autosubmit" <?php checked($filter_new_hours, 48); ?>><?php echo esc_html__('Last 48h','merchant-wiki-seo-audit'); ?></label>
					<label><input type="radio" name="mw_filter_new" value="168" class="mw-autosubmit" <?php checked($filter_new_hours, 168); ?>><?php echo esc_html__('Last 7 days','merchant-wiki-seo-audit'); ?></label>
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
				<button class="button"><?php echo esc_html__('Export CSV','merchant-wiki-seo-audit'); ?></button>
			</form>
			<div class="mw-table-wrap">
				<table class="mw-table">
				<thead>
					<tr>
						<?php
							$cols = array(
								'norm_url'        => __('URL','merchant-wiki-seo-audit'),
								'obj_type'        => __('Type','merchant-wiki-seo-audit'),
								'published_at'    => __('Published','merchant-wiki-seo-audit'),
								'http_status'     => 'HTTP',
								'in_sitemap'      => 'Sitemap',
								'noindex'         => 'Noindex',
								'inbound_links'   => __('Inbound','merchant-wiki-seo-audit'),
								'gsc_status'      => __('GSC','merchant-wiki-seo-audit'),
								'indexed_in_google'=> __('Indexed in Google','merchant-wiki-seo-audit'),
								'canonical'       => 'Canonical',
								'robots_meta'     => 'Robots',
								'schema_type'     => 'Schema',
								'pc_name'         => __('Primary Category','merchant-wiki-seo-audit'),
								'pc_path'         => __('Category Path','merchant-wiki-seo-audit'),
								'updated_at'      => __('Updated','merchant-wiki-seo-audit'),
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
						'discovered' => __('Discovered – currently not indexed','merchant-wiki-seo-audit'),
						'crawled_not_indexed' => __('Crawled – currently not indexed','merchant-wiki-seo-audit'),
						'duplicate_canonical' => __('Duplicate, Google chose different canonical','merchant-wiki-seo-audit'),
						'alternate_canonical' => __('Alternate page with proper canonical tag','merchant-wiki-seo-audit'),
						'soft_404' => __('Soft 404','merchant-wiki-seo-audit'),
						'noindex' => __('Excluded by noindex','merchant-wiki-seo-audit'),
						'redirect' => __('Page with redirect','merchant-wiki-seo-audit'),
						'blocked_robots' => __('Blocked by robots.txt','merchant-wiki-seo-audit'),
						'server_error' => __('Server error (5xx)','merchant-wiki-seo-audit'),
						'not_found' => __('Not found (404)','merchant-wiki-seo-audit'),
						'valid' => __('Valid / Indexed','merchant-wiki-seo-audit'),
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
							$label = __('Unknown','merchant-wiki-seo-audit');
							$class = 'mw-gsc-status--unknown';
					$likely_codes = ['discovered','crawled_not_indexed','duplicate_canonical','alternate_canonical','soft_404','noindex','blocked_robots','server_error','redirect','not_found'];
					if ($coverage !== '') {
						if ($reason_label !== '' && in_array($reason_label, $likely_codes, true)) {
							$label = __('Likely not indexed','merchant-wiki-seo-audit');
							$class = 'mw-gsc-status--likely';
						} elseif (in_array($coverage_lower, $likely_states_lower, true)) {
							$label = __('Likely not indexed','merchant-wiki-seo-audit');
							$class = 'mw-gsc-status--likely';
						} elseif (strpos($coverage_lower, 'not indexed') !== false) {
							$label = __('Not indexed','merchant-wiki-seo-audit');
							$class = 'mw-gsc-status--notindexed';
								} elseif (strpos($coverage_lower, 'indexed') !== false) {
									$label = __('Indexed','merchant-wiki-seo-audit');
									$class = 'mw-gsc-status--indexed';
								} elseif (strpos($coverage_lower, 'excluded') !== false || strpos($coverage_lower, 'duplicate') !== false || strpos($coverage_lower, 'noindex') !== false || strpos($coverage_lower, '404') !== false) {
									$label = __('Excluded','merchant-wiki-seo-audit');
									$class = 'mw-gsc-status--excluded';
								} else {
									$label = $coverage;
									$class = 'mw-gsc-status--info';
								}
							} elseif (isset($row['indexed_in_google'])) {
								if ((int) $row['indexed_in_google'] === 1) {
									$label = __('Indexed','merchant-wiki-seo-audit');
									$class = 'mw-gsc-status--indexed';
								} elseif ((int) $row['indexed_in_google'] === 0) {
									$label = __('Not indexed','merchant-wiki-seo-audit');
									$class = 'mw-gsc-status--notindexed';
								}
							}
							$parts = array();
					if ($reason_label_text !== '') {
						$label = $reason_label_text;
					}
					if ($coverage !== '') {
						/* translators: %s: Google Search Console coverage message. */
						$parts[] = sprintf(__('Coverage: %s','merchant-wiki-seo-audit'), $coverage);
					}
					if ($reason_label_text !== '') {
						/* translators: %s: Google reason code. */
						$parts[] = sprintf(__('Reason code: %s','merchant-wiki-seo-audit'), $reason_label_text);
					}
					if ($reason !== '') {
						/* translators: %s: Google reason description. */
						$parts[] = sprintf(__('Reason: %s','merchant-wiki-seo-audit'), $reason);
					}
							if ($verdict !== '') {
								/* translators: %s: Inspection API verdict. */
								$parts[] = sprintf(__('Verdict: %s','merchant-wiki-seo-audit'), $verdict);
							}
							$inspect_time = $source === 'page' ? $pi_inspected_at : $inspected_at;
							if ($inspect_time) {
								/* translators: %s: last inspection timestamp. */
								$parts[] = sprintf(__('Last checked: %s','merchant-wiki-seo-audit'), mysql2date('Y-m-d H:i', $inspect_time));
							}
							if ($ttl_until) {
								/* translators: %s: TTL expiry timestamp. */
								$parts[] = sprintf(__('TTL until: %s','merchant-wiki-seo-audit'), mysql2date('Y-m-d H:i', $ttl_until));
							}
							if ($source) {
								$parts[] = ($source === 'page') ? __('Source: Page indexing','merchant-wiki-seo-audit') : __('Source: Inspection API','merchant-wiki-seo-audit');
							}
								if ($last_error !== '') {
									/* translators: %s: last Inspection API error string. */
									$parts[] = sprintf(__('Last error: %s','merchant-wiki-seo-audit'), $last_error);
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
										<?php echo esc_html__('Find similar','merchant-wiki-seo-audit'); ?>
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
									echo $r['indexed_in_google'] ? esc_html__('Yes','merchant-wiki-seo-audit') : esc_html__('No','merchant-wiki-seo-audit');
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
						<tr><td colspan="14"><?php echo esc_html__('No rows yet (or SQL error). See Debug section above and debug.log for details.','merchant-wiki-seo-audit'); ?></td></tr>
					<?php endif; ?>
				</tbody>
				</table>
		</div>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mw-report-likely-export">
			<?php wp_nonce_field('mw_gsc_export_likely_not_indexed_csv'); ?>
			<input type="hidden" name="action" value="mw_gsc_export_likely_not_indexed_csv">
			<button class="button"><?php echo esc_html__('Export Likely Not Indexed','merchant-wiki-seo-audit'); ?></button>
		</form>
		<div class="mw-similar-overlay" id="mw-similar-overlay" aria-hidden="true">
			<div class="mw-similar-panel" role="dialog" aria-modal="true" aria-labelledby="mw-similar-title">
				<div class="mw-similar-panel-header">
					<h3 id="mw-similar-title"><?php echo esc_html__('Find similar URLs','merchant-wiki-seo-audit'); ?></h3>
					<button type="button" class="mw-similar-close" aria-label="<?php echo esc_attr__('Close panel','merchant-wiki-seo-audit'); ?>">&times;</button>
				</div>
				<div class="mw-similar-panel-body">
					<div class="mw-similar-section">
						<label class="mw-similar-label">
							<span><?php echo esc_html__('Reference URL','merchant-wiki-seo-audit'); ?></span>
							<div class="mw-similar-row">
								<input type="url" id="mw-similar-reference" class="regular-text" placeholder="https://example.com/page/">
								<button type="button" class="button" id="mw-similar-load"><?php echo esc_html__('Load signals','merchant-wiki-seo-audit'); ?></button>
							</div>
						</label>
						<p class="description"><?php echo esc_html__('Tip: click the “Find similar” link inside the Preview table to fill this automatically.','merchant-wiki-seo-audit'); ?></p>
					</div>
					<div class="mw-similar-baseline" id="mw-similar-baseline" hidden>
						<h4><?php echo esc_html__('Loaded signals','merchant-wiki-seo-audit'); ?></h4>
						<dl id="mw-similar-baseline-list"></dl>
					</div>
					<div class="mw-similar-grid">
						<div class="mw-similar-field" data-field="age">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-age-toggle">
								<span><?php echo esc_html__('Days since last update','merchant-wiki-seo-audit'); ?></span>
							</label>
							<div class="mw-similar-controls">
								<input type="number" min="0" id="mw-similar-age-min" value="0"> <span>—</span>
								<input type="number" min="0" id="mw-similar-age-max" value="0">
							</div>
						</div>
						<div class="mw-similar-field" data-field="inbound">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-inbound-toggle">
								<span><?php echo esc_html__('Inbound links','merchant-wiki-seo-audit'); ?></span>
							</label>
							<div class="mw-similar-controls">
								<input type="number" min="0" id="mw-similar-inbound-min" value="0"> <span>—</span>
								<input type="number" min="0" id="mw-similar-inbound-max" value="0">
							</div>
						</div>
						<div class="mw-similar-field" data-field="http_status">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-http-toggle">
								<span><?php echo esc_html__('HTTP status','merchant-wiki-seo-audit'); ?></span>
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
								<span><?php echo esc_html__('In sitemap','merchant-wiki-seo-audit'); ?></span>
							</label>
							<select id="mw-similar-sitemap-value">
								<option value="1"><?php echo esc_html__('Yes','merchant-wiki-seo-audit'); ?></option>
								<option value="0"><?php echo esc_html__('No','merchant-wiki-seo-audit'); ?></option>
							</select>
						</div>
						<div class="mw-similar-field" data-field="noindex">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-noindex-toggle">
								<span><?php echo esc_html__('Noindex flag','merchant-wiki-seo-audit'); ?></span>
							</label>
							<select id="mw-similar-noindex-value">
								<option value="0"><?php echo esc_html__('No','merchant-wiki-seo-audit'); ?></option>
								<option value="1"><?php echo esc_html__('Yes','merchant-wiki-seo-audit'); ?></option>
							</select>
						</div>
						<div class="mw-similar-field" data-field="indexed">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-indexed-toggle">
								<span><?php echo esc_html__('Indexed in Google','merchant-wiki-seo-audit'); ?></span>
							</label>
							<select id="mw-similar-indexed-value">
								<option value="1"><?php echo esc_html__('Yes','merchant-wiki-seo-audit'); ?></option>
								<option value="0"><?php echo esc_html__('No','merchant-wiki-seo-audit'); ?></option>
							</select>
						</div>
						<div class="mw-similar-field" data-field="pc_path">
							<label class="mw-similar-toggle">
								<input type="checkbox" id="mw-similar-category-toggle">
								<span><?php echo esc_html__('Primary category path','merchant-wiki-seo-audit'); ?></span>
							</label>
							<input type="text" id="mw-similar-category-value" placeholder="human-resources/">
						</div>
					</div>
					<div class="mw-similar-actions">
						<label class="mw-similar-limit">
							<span><?php echo esc_html__('Max results per page','merchant-wiki-seo-audit'); ?></span>
							<select id="mw-similar-limit">
								<option value="10">10</option>
								<option value="25" selected>25</option>
								<option value="50">50</option>
								<option value="100">100</option>
							</select>
						</label>
						<button type="button" class="button button-primary" id="mw-similar-apply"><?php echo esc_html__('Show matches','merchant-wiki-seo-audit'); ?></button>
						<button type="button" class="button" id="mw-similar-export" disabled><?php echo esc_html__('Export CSV','merchant-wiki-seo-audit'); ?></button>
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
										<th><?php echo esc_html__('URL','merchant-wiki-seo-audit'); ?></th>
										<th><?php echo esc_html__('Days stale','merchant-wiki-seo-audit'); ?></th>
										<th><?php echo esc_html__('HTTP','merchant-wiki-seo-audit'); ?></th>
										<th><?php echo esc_html__('Inbound','merchant-wiki-seo-audit'); ?></th>
										<th><?php echo esc_html__('Indexed','merchant-wiki-seo-audit'); ?></th>
										<th><?php echo esc_html__('In sitemap','merchant-wiki-seo-audit'); ?></th>
										<th><?php echo esc_html__('Primary category','merchant-wiki-seo-audit'); ?></th>
										<th><?php echo esc_html__('GSC status','merchant-wiki-seo-audit'); ?></th>
										<th><?php echo esc_html__('Similarity','merchant-wiki-seo-audit'); ?></th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>
						<div class="mw-similar-pagination">
							<button type="button" class="button" id="mw-similar-prev" disabled><?php echo esc_html__('Previous','merchant-wiki-seo-audit'); ?></button>
							<button type="button" class="button" id="mw-similar-next" disabled><?php echo esc_html__('Next','merchant-wiki-seo-audit'); ?></button>
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
			<h3><?php echo esc_html__('Outbound Link Summary','merchant-wiki-seo-audit'); ?></h3>
			<p class="mw-report-hint"><?php echo esc_html__('Run Operations → Outbound Link Scan (Start) after Refresh On-Site Signals to refresh these counts.','merchant-wiki-seo-audit'); ?></p>
			<p class="description"><?php echo esc_html__('Shows the most recently scanned URLs grouped by how many internal/external links they point to. Use it to find “dead-end” pages or ones that leak too much link equity.','merchant-wiki-seo-audit'); ?></p>
			<div class="mw-table-wrap">
				<table class="mw-table">
					<thead>
						<tr>
							<th><?php echo esc_html__('URL','merchant-wiki-seo-audit'); ?></th>
							<th><?php echo esc_html__('Outbound (internal)','merchant-wiki-seo-audit'); ?></th>
							<th><?php echo esc_html__('Outbound (external)','merchant-wiki-seo-audit'); ?></th>
							<th><?php echo esc_html__('External domains','merchant-wiki-seo-audit'); ?></th>
							<th><?php echo esc_html__('Last scanned','merchant-wiki-seo-audit'); ?></th>
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
							<tr><td colspan="5"><?php echo esc_html__('Run the Outbound Link Scan to populate this table.','merchant-wiki-seo-audit'); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<?php if ($view === 'settings'): ?>
		<div class="mw-card">
			<h3><?php echo esc_html__('General Settings','merchant-wiki-seo-audit'); ?></h3>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('mw_audit_save_settings', 'mw_audit_save_settings_nonce'); ?>
				<input type="hidden" name="action" value="mw_audit_save_settings">
				<div class="mw-kv">
					<label>
						<span><?php echo esc_html__('HEAD timeout (s)','merchant-wiki-seo-audit'); ?></span>
						<input type="number" class="small-text" name="timeout_head" min="1" value="<?php echo esc_attr($settings['timeouts']['head']); ?>">
					</label>
					<label>
						<span><?php echo esc_html__('GET timeout (s)','merchant-wiki-seo-audit'); ?></span>
						<input type="number" class="small-text" name="timeout_get" min="1" value="<?php echo esc_attr($settings['timeouts']['get']); ?>">
					</label>
					<label>
						<span><?php echo esc_html__('Default profile','merchant-wiki-seo-audit'); ?></span>
						<select name="profile_defaults">
							<option value="fast" <?php selected($settings['profile_defaults'], 'fast'); ?>><?php echo esc_html__('Fast','merchant-wiki-seo-audit'); ?></option>
							<option value="standard" <?php selected($settings['profile_defaults'], 'standard'); ?>><?php echo esc_html__('Standard','merchant-wiki-seo-audit'); ?></option>
							<option value="safe" <?php selected($settings['profile_defaults'], 'safe'); ?>><?php echo esc_html__('Safe','merchant-wiki-seo-audit'); ?></option>
						</select>
					</label>
					<label>
						<span><?php echo esc_html__('Export TTL (hours)','merchant-wiki-seo-audit'); ?></span>
						<input type="number" class="small-text" name="ttl_export" min="1" value="<?php echo esc_attr($settings['ttl']['export_hours']); ?>">
					</label>
					<label>
						<span><?php echo esc_html__('API TTL (hours)','merchant-wiki-seo-audit'); ?></span>
						<input type="number" class="small-text" name="ttl_api" min="1" value="<?php echo esc_attr($settings['ttl']['api_hours']); ?>">
					</label>
				</div>
				<p class="description" style="margin:8px 0;">
					<label><input type="checkbox" name="gsc_api_enabled" value="1" <?php checked(!empty($settings['gsc_api_enabled'])); ?>> <?php echo esc_html__('Enable GSC Inspection API spot checks','merchant-wiki-seo-audit'); ?></label><br>
					<label><input type="checkbox" name="gdrive_export_enabled" value="1" <?php checked(!empty($settings['gdrive_export_enabled'])); ?>> <?php echo esc_html__('Enable Google Drive export (future feature)','merchant-wiki-seo-audit'); ?></label>
				</p>
				<p><button class="button button-primary" type="submit"><?php echo esc_html__('Save settings','merchant-wiki-seo-audit'); ?></button></p>
			</form>
		</div>

		<div class="mw-card" id="mw-gsc-connection-card">
			<h3><?php echo esc_html__('Google Search Console Connection','merchant-wiki-seo-audit'); ?></h3>
			<p class="mw-box-hint"><?php echo esc_html__('Save OAuth credentials, connect your Google account, and pick a property before using either import mode or the Inspection API.','merchant-wiki-seo-audit'); ?></p>
			<p class="description"><?php
				printf(
					/* translators: %s: Google Cloud Console link */
					esc_html__('Create OAuth credentials under Google Cloud Console → Credentials (%s).','merchant-wiki-seo-audit'),
					sprintf(
						'<a href="%s" target="_blank" rel="noreferrer noopener">%s</a>',
						esc_url('https://console.cloud.google.com/apis/credentials'),
						esc_html__('open console','merchant-wiki-seo-audit')
					)
				);
			?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
				<?php wp_nonce_field('mw_audit_gsc_credentials'); ?>
				<input type="hidden" name="action" value="mw_audit_save_gsc_credentials">
				<label style="display:block; margin-bottom:6px;">
					<?php echo esc_html__('OAuth Client ID','merchant-wiki-seo-audit'); ?>
					<input type="text" name="gsc_client_id" class="regular-text mw-card-field" style="width:100%;max-width:100%;" value="<?php echo esc_attr(MW_Audit_GSC::get_client_id()); ?>">
				</label>
				<label style="display:block; margin-bottom:6px;">
					<?php echo esc_html__('OAuth Client Secret','merchant-wiki-seo-audit'); ?>
					<input type="password" name="gsc_client_secret" class="regular-text mw-card-field" style="width:100%;max-width:100%;" value="<?php echo esc_attr(MW_Audit_GSC::get_client_secret()); ?>">
				</label>
				<p class="description"><?php echo esc_html__('Redirect URI','merchant-wiki-seo-audit'); ?>: <code><?php echo esc_html(MW_Audit_GSC::get_redirect_uri()); ?></code></p>
				<button class="button"><?php echo esc_html__('Save credentials','merchant-wiki-seo-audit'); ?></button>
			</form>

			<?php if (!empty($gsc['configured'])): ?>
				<?php if (!$gsc['connected'] && $gsc_auth_url): ?>
					<p><a class="button button-primary" href="<?php echo esc_url($gsc_auth_url); ?>"><?php echo esc_html__('Connect Google Account','merchant-wiki-seo-audit'); ?></a></p>
				<?php elseif ($gsc['connected']): ?>
					<p class="muted">
						<?php
						printf(
							'%s <strong>%s</strong>%s',
							esc_html__('Connected as','merchant-wiki-seo-audit'),
							esc_html($gsc['email'] ?: __('Unknown account','merchant-wiki-seo-audit')),
							empty($gsc['property']) ? '' : ' — '.esc_html($gsc['property'])
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
						<?php wp_nonce_field('mw_audit_gsc_property'); ?>
						<input type="hidden" name="action" value="mw_audit_save_gsc_property">
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Select property','merchant-wiki-seo-audit'); ?>
							<select name="gsc_property_select" class="regular-text mw-card-field" style="width:100%;max-width:100%;">
								<option value=""><?php echo esc_html__('— Choose property —','merchant-wiki-seo-audit'); ?></option>
								<?php foreach ((array)$gsc_sites as $site_url): ?>
									<option value="<?php echo esc_attr($site_url); ?>" <?php selected($site_url, $gsc['property']); ?>><?php echo esc_html($site_url); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label style="display:block; margin-bottom:6px;">
							<?php echo esc_html__('Or specify manually','merchant-wiki-seo-audit'); ?>
							<input type="url" class="regular-text mw-card-field" style="width:100%;max-width:100%;" name="gsc_property_manual" placeholder="https://example.com/" value="<?php echo esc_attr($gsc['property'] ?? ''); ?>">
						</label>
						<button class="button"><?php echo esc_html__('Save property','merchant-wiki-seo-audit'); ?></button>
					</form>
					<div class="mw-gsc-inline-controls">
						<label>
							<?php echo esc_html__('Inspection cache TTL','merchant-wiki-seo-audit'); ?>
							<select id="mw-gsc-ttl" class="mw-card-field" style="min-width:180px;">
									<?php foreach ($gsc_ttl_options as $ttl_option): ?>
										<option value="<?php echo esc_attr($ttl_option); ?>" <?php selected((int)$ttl_option, (int)$gsc_ttl_hours); ?>>
											<?php
											printf(
												/* translators: %d: number of hours before cached data expires. */
												esc_html__('%d hours','merchant-wiki-seo-audit'),
												(int) $ttl_option
											);
											?>
										</option>
								<?php endforeach; ?>
							</select>
						</label>
						<span id="mw-gsc-ttl-status" class="mw-inline-status" aria-live="polite"></span>
					</div>
					<?php if ($gsc_sheets_auth_url || $gsc_has_sheets): ?>
						<?php if (!$gsc_has_sheets && $gsc_sheets_auth_url): ?>
							<p><a class="button" href="<?php echo esc_url($gsc_sheets_auth_url); ?>"><?php echo esc_html__('Connect Sheets','merchant-wiki-seo-audit'); ?></a></p>
						<?php else: ?>
							<p class="description"><?php echo esc_html__('Sheets API scope connected. You can sync Page indexing directly from Google Sheets.','merchant-wiki-seo-audit'); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php if (!empty($gsc['connected'])): ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
					<?php wp_nonce_field('mw_audit_gsc_disconnect'); ?>
					<input type="hidden" name="action" value="mw_audit_disconnect_gsc">
					<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js(__('Disconnect Google account?','merchant-wiki-seo-audit')); ?>');"><?php echo esc_html__('Disconnect','merchant-wiki-seo-audit'); ?></button>
				</form>
			<?php endif; ?>
		</div>


		<div class="mw-card">
			<h3><?php echo esc_html__('Maintenance','merchant-wiki-seo-audit'); ?></h3>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
				onsubmit="return confirm('<?php echo esc_js(__('Be careful: this will delete all data created by this plugin (inventory, status, PC map). Continue?','merchant-wiki-seo-audit')); ?>');">
				<?php wp_nonce_field('mw_audit_delete_all', 'mw_audit_delete_all_nonce'); ?>
				<input type="hidden" name="action" value="mw_audit_delete_all">
				<button class="button button-link-delete" title="<?php echo esc_attr__('Clear plugin data (without dropping tables).','merchant-wiki-seo-audit'); ?>">
					<?php echo esc_html__('Delete All Data','merchant-wiki-seo-audit'); ?>
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
						esc_html__('Drop tables on uninstall:','merchant-wiki-seo-audit'),
						$drop ? esc_html__('YES','merchant-wiki-seo-audit') : esc_html__('NO','merchant-wiki-seo-audit')
					);
					?>
				</label>
				<button class="button"><?php echo esc_html__('Toggle','merchant-wiki-seo-audit'); ?></button>
			</form>
		</div>
	<?php endif; ?>

	</div><!-- /.wrap -->
<?php
}
