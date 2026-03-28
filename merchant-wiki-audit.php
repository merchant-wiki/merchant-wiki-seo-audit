<?php
/**
 * Plugin Name: Merchant.WiKi SEO Audit
 * Plugin URI: https://merchant.wiki/merchant-wiki-site-index-audit-plugin-for-wordpress/
 * Description: SEO index & site signals audit (inventory, sitemaps cache, on-site signals, HTTP/redirect checks, primary category map, internal/outbound link scans).
 * Version: 1.8.2
 * Author: Merchant.WiKi
 * Author URI: https://merchant.wiki/
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: merchant-wiki-audit
 * Domain Path: /languages
 */
if (!defined('ABSPATH')) exit;

define('MW_AUDIT_VER', '1.8.2');
define('MW_AUDIT_DIR', plugin_dir_path(__FILE__));
define('MW_AUDIT_URL', plugin_dir_url(__FILE__));
define('MW_AUDIT_DEBUG', defined('WP_DEBUG') && WP_DEBUG);

require_once MW_AUDIT_DIR.'inc/db.php';
require_once MW_AUDIT_DIR.'inc/class-queue.php';
require_once MW_AUDIT_DIR.'inc/class-robots.php';
require_once MW_AUDIT_DIR.'inc/class-sitemap.php';
require_once MW_AUDIT_DIR.'inc/class-ilinks.php';
require_once MW_AUDIT_DIR.'inc/class-seo-flags.php';
require_once MW_AUDIT_DIR.'inc/class-gsc.php';
require_once MW_AUDIT_DIR.'inc/class-inventory-builder.php';
require_once MW_AUDIT_DIR.'inc/class-url-scanner.php';
require_once MW_AUDIT_DIR.'inc/class-pc-mapper.php';
require_once MW_AUDIT_DIR.'inc/class-health.php';
require_once MW_AUDIT_DIR.'inc/class-inventory.php';
require_once MW_AUDIT_DIR.'inc/admin-page.php';
require_once MW_AUDIT_DIR.'inc/class-next-steps.php';
require_once MW_AUDIT_DIR.'inc/class-cli.php';

// Activation: create tables if missing (no migrations)
register_activation_hook(__FILE__, function(){
  MW_Audit_DB::ensure_tables_if_missing();
});

// NOTE: uninstall logic moved to uninstall.php (no closures here to avoid serialization errors)

// Admin assets
add_action('admin_enqueue_scripts', function($hook){
  if (strpos($hook, 'mw-site-index-') === false) return;
  wp_enqueue_style('mw-audit-admin', MW_AUDIT_URL.'assets/admin.css', [], MW_AUDIT_VER);
  wp_enqueue_script('mw-audit-admin', MW_AUDIT_URL.'assets/admin.js', ['jquery'], MW_AUDIT_VER, true);
  $ajax_actions = [
    'mw_audit_sm_prepare',
    'mw_audit_inventory_start',
    'mw_audit_inventory_step',
    'mw_audit_refresh_start',
    'mw_audit_refresh_step',
    'mw_audit_http_start',
    'mw_audit_http_step',
    'mw_audit_pc_start',
    'mw_audit_pc_step',
    'mw_audit_links_start',
    'mw_audit_links_step',
    'mw_audit_outbound_start',
    'mw_audit_outbound_step',
    'mw_audit_gindex_start',
    'mw_audit_gindex_step',
    'mw_gsc_enqueue_all',
    'mw_gsc_process_batch',
    'mw_gsc_reset_queue',
    'mw_gsc_sync_pi_sheets',
    'mw_gsc_save_ttl',
    'mw_gsc_assemble_pi_sheet',
    'mw_audit_priority_list',
    'mw_audit_similar_seed',
    'mw_audit_similar_query',
  ];

  $ajax_nonces = [];
  foreach ($ajax_actions as $ajax_action) {
    $ajax_nonces[$ajax_action] = wp_create_nonce($ajax_action);
  }

  wp_localize_script('mw-audit-admin', 'MW_AUDIT', [
    'ajax'   => admin_url('admin-ajax.php'),
    'nonces' => $ajax_nonces,
    'settings' => MW_Audit_DB::get_settings(),
    'i18n'   => [
      'error'        => __('Request failed', 'merchant-wiki-audit'),
      'done'         => __('Done', 'merchant-wiki-audit'),
      'ttlSaved'     => __('TTL updated', 'merchant-wiki-audit'),
      'sheetRequired'=> __('Enter Google Sheet URL or ID', 'merchant-wiki-audit'),
      'syncWorking'  => __('Sync in progress...', 'merchant-wiki-audit'),
      /* translators: %imported% and %skipped% will be replaced with counts of processed rows. */
      'syncDone'     => __('Import finished: %imported% imported, %skipped% skipped', 'merchant-wiki-audit'),
      'priorityLoading' => __('Loading priority list...', 'merchant-wiki-audit'),
      'priorityEmpty'   => __('No URLs match this filter yet.', 'merchant-wiki-audit'),
      'priorityError'   => __('Unable to load the priority list.', 'merchant-wiki-audit'),
      'assembleWorking' => __('Building combined sheet…', 'merchant-wiki-audit'),
      /* translators: %link% will be replaced with a URL to the generated sheet. */
      'assembleDone'    => __('Combined sheet ready: %link%', 'merchant-wiki-audit'),
      'assembleError'   => __('Unable to assemble sheets. Check the URLs and try again.', 'merchant-wiki-audit'),
      'geminiCopied'    => __('Prompt copied. Gemini opened in a new tab — paste the current content right away.', 'merchant-wiki-audit'),
      'geminiCopyFailed'=> __('Gemini opened, but the prompt was not copied. Grab it manually from the table.', 'merchant-wiki-audit'),
      'similarNeedUrl'  => __('Enter a URL first.', 'merchant-wiki-audit'),
      'similarReady'    => __('Signals loaded. Adjust filters and click “Show matches.”', 'merchant-wiki-audit'),
      'similarLoadFailed'=> __('Unable to load URL. Make sure it exists in the inventory.', 'merchant-wiki-audit'),
      'similarNeedReference'=> __('Load a reference URL first.', 'merchant-wiki-audit'),
      'similarQueryFailed'=> __('Unable to load matches.', 'merchant-wiki-audit'),
      'similarNoRows'   => __('No matches found for the selected filters.', 'merchant-wiki-audit'),
      /* translators: 1: first row number shown, 2: last row number shown, 3: total number of matches. */
      'similarSummary'  => __('Showing %1$s–%2$s of %3$s matches', 'merchant-wiki-audit'),
      'similarExportError' => __('Run a similar search before exporting.', 'merchant-wiki-audit'),
      /* translators: %expected%: required filename; %actual%: filename chosen by the user. */
      'gscFilenameMismatch' => __('The filename is not %expected% but %actual%. Are you sure you want to use it?', 'merchant-wiki-audit'),
    ]
  ]);
});

// Admin POST actions
add_action('admin_post_mw_audit_rebuild',       ['MW_Audit_Inventory','action_rebuild_inventory']);
add_action('admin_post_mw_audit_export_csv',    ['MW_Audit_Inventory','action_export_csv']);
add_action('admin_post_mw_audit_delete_all',    ['MW_Audit_Inventory','action_delete_all_data']);
add_action('admin_post_mw_audit_selftest',      ['MW_Audit_Inventory','action_selftest']);
add_action('admin_post_mw_audit_toggle_dropdb', ['MW_Audit_Inventory','action_toggle_dropdb']);
add_action('admin_post_mw_audit_save_pc_tax',   ['MW_Audit_Inventory','action_save_pc_tax']);
add_action('admin_post_mw_audit_save_settings', ['MW_Audit_Inventory','action_save_settings']);
add_action('admin_post_mw_audit_save_gsc_credentials', ['MW_Audit_Inventory','action_save_gsc_credentials']);
add_action('admin_post_mw_audit_save_gsc_property',   ['MW_Audit_Inventory','action_save_gsc_property']);
add_action('admin_post_mw_audit_disconnect_gsc',      ['MW_Audit_Inventory','action_disconnect_gsc']);
add_action('admin_post_mw_audit_gsc_callback',        ['MW_Audit_Inventory','action_gsc_callback']);

// AJAX queues
add_action('wp_ajax_mw_audit_sm_prepare',   ['MW_Audit_Inventory','ajax_sitemaps_prepare']);
add_action('wp_ajax_mw_audit_inventory_start',['MW_Audit_Inventory','ajax_inventory_start']);
add_action('wp_ajax_mw_audit_inventory_step', ['MW_Audit_Inventory','ajax_inventory_step']);
add_action('wp_ajax_mw_audit_refresh_start',['MW_Audit_Inventory','ajax_refresh_start']);
add_action('wp_ajax_mw_audit_refresh_step', ['MW_Audit_Inventory','ajax_refresh_step']);
add_action('wp_ajax_mw_audit_http_start',   ['MW_Audit_Inventory','ajax_http_start']);
add_action('wp_ajax_mw_audit_http_step',    ['MW_Audit_Inventory','ajax_http_step']);
add_action('wp_ajax_mw_audit_pc_start',     ['MW_Audit_Inventory','ajax_pc_start']);
add_action('wp_ajax_mw_audit_pc_step',      ['MW_Audit_Inventory','ajax_pc_step']);
add_action('wp_ajax_mw_audit_links_start',  ['MW_Audit_Inventory','ajax_links_start']);
add_action('wp_ajax_mw_audit_links_step',   ['MW_Audit_Inventory','ajax_links_step']);
add_action('wp_ajax_mw_audit_outbound_start',  ['MW_Audit_Inventory','ajax_outbound_start']);
add_action('wp_ajax_mw_audit_outbound_step',   ['MW_Audit_Inventory','ajax_outbound_step']);
add_action('wp_ajax_mw_audit_gindex_start', ['MW_Audit_Inventory','ajax_gindex_start']);
add_action('wp_ajax_mw_audit_gindex_step',  ['MW_Audit_Inventory','ajax_gindex_step']);
add_action('wp_ajax_mw_gsc_enqueue_all',    ['MW_Audit_Inventory','ajax_gsc_enqueue_all']);
add_action('wp_ajax_mw_gsc_process_batch',  ['MW_Audit_Inventory','ajax_gsc_process_batch']);
add_action('wp_ajax_mw_gsc_reset_queue',   ['MW_Audit_Inventory','ajax_gsc_reset_queue']);
add_action('wp_ajax_mw_gsc_sync_pi_sheets', ['MW_Audit_Inventory','ajax_gsc_sync_pi_sheets']);
add_action('wp_ajax_mw_gsc_save_ttl',       ['MW_Audit_Inventory','ajax_gsc_save_ttl']);
add_action('wp_ajax_mw_gsc_assemble_pi_sheet', ['MW_Audit_Inventory','ajax_gsc_assemble_pi_sheet']);
add_action('wp_ajax_mw_audit_priority_list',['MW_Audit_Inventory','ajax_priority_list']);
add_action('wp_ajax_mw_audit_similar_seed', ['MW_Audit_Inventory','ajax_similar_seed']);
add_action('wp_ajax_mw_audit_similar_query',['MW_Audit_Inventory','ajax_similar_query']);
add_action('admin_post_mw_gsc_import_pi_csv', ['MW_Audit_Inventory','action_gsc_import_pi_csv']);
add_action('admin_post_mw_gsc_export_likely_not_indexed_csv', ['MW_Audit_Inventory','action_gsc_export_likely_not_indexed_csv']);
add_action('admin_post_mw_audit_priority_export', ['MW_Audit_Inventory','action_priority_export']);
add_action('admin_post_mw_audit_similar_export', ['MW_Audit_Inventory','action_similar_export']);
add_action('admin_post_mw_audit_next_steps_quick', ['MW_Audit_Next_Steps','action_export_quick']);
add_action('admin_post_mw_audit_next_steps_manual', ['MW_Audit_Next_Steps','action_export_manual']);
add_action('admin_post_mw_audit_next_steps_pruning', ['MW_Audit_Next_Steps','action_export_pruning']);
add_action('admin_post_mw_audit_next_steps_snapshot', ['MW_Audit_Next_Steps','action_create_snapshot']);
add_action('admin_post_mw_audit_next_steps_diff', ['MW_Audit_Next_Steps','action_diff_snapshots']);
