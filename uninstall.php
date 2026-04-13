<?php
// Called by WordPress on plugin uninstall (if user confirms plugin removal).
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
require_once __DIR__.'/inc/db.php';

// Drop tables only if user opted-in inside plugin UI.
$drop = get_option('mw_audit_drop_on_uninstall') === 'yes';
if ($drop) {
  $tables = [
    MW_Audit_DB::esc_table(MW_Audit_DB::t_inventory()),
    MW_Audit_DB::esc_table(MW_Audit_DB::t_status()),
    MW_Audit_DB::esc_table(MW_Audit_DB::t_pc()),
    MW_Audit_DB::esc_table(MW_Audit_DB::t_gsc_cache()),
    MW_Audit_DB::esc_table(MW_Audit_DB::t_outbound()),
  ];
  foreach ($tables as $table_sql){
    if ($table_sql){
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
      MW_Audit_DB::query_sql("DROP TABLE IF EXISTS {$table_sql}");
    }
  }
}
delete_option('mw_audit_drop_on_uninstall');
delete_option('mw_audit_sitemap_cache');
foreach (['inv','sm','os','http','pc','link','outbound','gindex','pi'] as $k){
  delete_option('mw_audit_flag_'.$k);
  delete_option('mw_audit_flag_'.$k.'_at');
}
delete_option('mw_audit_last_update');
delete_option('mw_audit_last_inv_detected');
delete_option('mw_audit_settings');
delete_option('mwa_settings');
delete_option('mw_audit_launch_snapshots');

$prefix = 'mw_audit_%';
$options_table = MW_Audit_DB::esc_table(MW_Audit_DB::options_table());
if ($options_table){
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
  MW_Audit_DB::query_sql("
    DELETE FROM {$options_table}
    WHERE option_name LIKE %s
  ", [$prefix]);
}
