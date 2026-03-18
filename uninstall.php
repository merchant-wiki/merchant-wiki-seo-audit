<?php
// Called by WordPress on plugin uninstall (if user confirms plugin removal).
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// Drop tables only if user opted-in inside plugin UI.
$drop = get_option('mw_audit_drop_on_uninstall') === 'yes';
if ($drop) {
  global $wpdb;
  $inv = $wpdb->prefix.'mw_url_inventory';
  $st  = $wpdb->prefix.'mw_url_status';
  $pc  = $wpdb->prefix.'mw_post_primary_category';
  $gsc = $wpdb->prefix.'mw_gsc_cache';
  $out = $wpdb->prefix.'mw_outbound_links';
  @$wpdb->query("DROP TABLE IF EXISTS $inv");
  @$wpdb->query("DROP TABLE IF EXISTS $st");
  @$wpdb->query("DROP TABLE IF EXISTS $pc");
  @$wpdb->query("DROP TABLE IF EXISTS $gsc");
  @$wpdb->query("DROP TABLE IF EXISTS $out");
}
delete_option('mw_audit_drop_on_uninstall');
delete_option('mw_audit_sitemap_cache');
foreach (['inv','sm','os','http','pc','link','outbound','gindex','pi'] as $k){
  delete_option('mw_audit_flag_'.$k);
  delete_option('mw_audit_flag_'.$k.'_at');
}
delete_option('mw_audit_last_update');
delete_option('mw_audit_last_inv_detected');
delete_option('mwa_settings');
delete_option('mw_audit_launch_snapshots');

global $wpdb;
$prefix = 'mw_audit_%';
$wpdb->query($wpdb->prepare("
  DELETE FROM {$wpdb->options}
  WHERE option_name LIKE %s
", $prefix));
