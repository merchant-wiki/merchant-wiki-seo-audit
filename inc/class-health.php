<?php
if (!defined('ABSPATH')) exit;

class MW_Audit_Health {
  /**
   * Compute Health snapshot + per-metric pill states bound to blocks.
   * Pure read-only. No schema changes here.
   */
  public static function get(){
    global $wpdb;

    // Table names via helpers, or fall back to prefix if helpers missing
    $inv_tbl = method_exists('MW_Audit_DB','table_inventory_name') ? MW_Audit_DB::table_inventory_name() : (method_exists('MW_Audit_DB','t_inventory') ? MW_Audit_DB::t_inventory() : $wpdb->prefix.'mw_url_inventory');
    $st_tbl  = method_exists('MW_Audit_DB','table_status_name') ? MW_Audit_DB::table_status_name() : (method_exists('MW_Audit_DB','t_status') ? MW_Audit_DB::t_status() : $wpdb->prefix.'mw_url_status');
    $pc_tbl  = method_exists('MW_Audit_DB','table_pc_name') ? MW_Audit_DB::table_pc_name() : (method_exists('MW_Audit_DB','t_pc') ? MW_Audit_DB::t_pc() : $wpdb->prefix.'mw_post_primary_category');

    // Loopback (best effort)
    $loop_ok = true;
    $resp = wp_remote_get( admin_url('admin-ajax.php'), array('timeout'=>3) );
    if (is_wp_error($resp)) $loop_ok = false;

    // Row counts (best effort if tables exist)
    $inventory_rows = 0; $status_rows = 0; $pc_rows = 0;
    if ($inv_tbl && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $inv_tbl))) {
      $inventory_rows = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$inv_tbl}");
    }
    if ($st_tbl && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $st_tbl))) {
      $status_rows = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$st_tbl}");
    }
    if ($pc_tbl && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pc_tbl))) {
      $pc_rows = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$pc_tbl}");
    }

    // Options
    $last_update       = get_option('mw_audit_last_status_update', '');
    $last_inv_detected = (int)get_option('mw_audit_last_inv_detected', 0);

    // Sitemap cache snapshot (if helper available)
    $sm_files = 0; $sm_age = 0; $sm_sources = [];
    if (class_exists('MW_Audit_Sitemap') && method_exists('MW_Audit_Sitemap','get_cached')) {
      $sm = MW_Audit_Sitemap::get_cached();
      if (is_array($sm)) {
        $sm_sources = !empty($sm['files']) ? (array)$sm['files'] : (array)($sm['sources'] ?? []);
        $sm_files = count($sm_sources);
        if (!$sm_files && !empty($sm['count'])){
          $sm_files = (int) $sm['count'];
        }
        $sm_age   = isset($sm['age']) ? (int)$sm['age'] : (isset($sm['time']) ? max(0, time() - (int)$sm['time']) : 0);
      }
    }

    // Flags/queues (soft read)
    $flags   = method_exists('MW_Audit_DB','get_flags') ? MW_Audit_DB::get_flags() : array();
    $q_inv    = (bool) get_transient('mw_inventory_queue');
    $q_status = (bool) get_transient('mw_status_queue');
    $q_http   = (bool) get_transient('mw_http_queue');
    $q_pc     = (bool) get_transient('mw_pc_queue');
    $q_gindex = (bool) get_transient('mw_gsc_queue');

    $gsc_info = class_exists('MW_Audit_GSC') ? MW_Audit_GSC::connection_info() : ['configured'=>false,'connected'=>false,'email'=>'','property'=>''];

    // Pill states
    $k = 0.90;

    // Inventory → Rebuild Inventory
    $inv_state = 'neutral';
    $inv_started = $q_inv || (!empty($flags['inv']) && $flags['inv'] !== 'done');
    if ($inventory_rows > 0) $inv_state = 'ok';
    elseif ($inv_started || !empty($last_inv_detected)) $inv_state = 'warn';
    if (!empty($wpdb->last_error)) $inv_state = 'fail';

    // Status → sitemaps + on-site signals
    $queues_running = $q_status || $q_http || (!empty($flags['os']) && $flags['os']==='running') || (!empty($flags['http']) && $flags['http']==='running');
    $status_state = 'neutral';
    if ($status_rows > 0 && ($inventory_rows==0 || $status_rows >= max(1, floor($inventory_rows*$k)))) $status_state = 'ok';
    elseif ($queues_running) $status_state = 'warn';
    elseif ($inventory_rows>0 && $status_rows==0) $status_state = 'fail';

    // Post→Primary Category map
    $pc_state = 'neutral';
    $pc_running = $q_pc || (!empty($flags['pc']) && $flags['pc']==='running');
    if ($pc_rows > 0) $pc_state = 'ok';
    elseif ($pc_running) $pc_state = 'warn';
    elseif (!empty($flags['pc']) && $flags['pc']==='done' && $pc_rows===0) $pc_state = 'fail';

    $pi_flag = $flags['pi'] ?? '';
    $pi_state = 'neutral';
    if ($pi_flag === 'done') {
      $pi_state = 'ok';
    } elseif ($pi_flag === 'running') {
      $pi_state = 'warn';
    } elseif ($pi_flag === 'fail') {
      $pi_state = 'fail';
    }

    // Inventory detected last run
    $inv_last_state = 'neutral';
    if ($last_inv_detected > 0 && $last_inv_detected === $inventory_rows) $inv_last_state = 'ok';
    elseif ($last_inv_detected > 0 && $inventory_rows > 0) {
      $ratio = $last_inv_detected / max(1,$inventory_rows);
      $inv_last_state = (abs(1-$ratio) > 0.10) ? 'warn' : 'ok';
    } elseif (!empty($flags['inv']) && $flags['inv']==='done' && $last_inv_detected===0) $inv_last_state = 'fail';

    // robots status (if helper exists)
    $robots_ok = true;
    if (class_exists('MW_Audit_Robots') && method_exists('MW_Audit_Robots','fetch_rules')) {
      $r = MW_Audit_Robots::fetch_rules();
      $robots_ok = !empty($r['ok']);
    }

    // Schema validation (if helper exists)
    $schema = array('total'=>'OK');
    if (class_exists('MW_Audit_DB') && method_exists('MW_Audit_DB','check_schema_full')) {
      $schema = MW_Audit_DB::check_schema_full();
    }

    $settings = MW_Audit_DB::get_settings();

    $gsc_metrics = [];
    if (class_exists('MW_Audit_GSC') && method_exists('MW_Audit_DB','table_gsc_cache_name') && method_exists('MW_Audit_DB','table_inventory_name')) {
      $cache_tbl = MW_Audit_DB::table_gsc_cache_name();
      $inv_tbl = MW_Audit_DB::table_inventory_name();
      if ($cache_tbl && $inv_tbl) {
        $now_mysql = current_time('mysql');
        $export_total = (int) $wpdb->get_var(
          $wpdb->prepare("SELECT COUNT(*) FROM {$cache_tbl} WHERE source=%s", 'page_indexing')
        );
        $export_fresh = (int) $wpdb->get_var(
          $wpdb->prepare("SELECT COUNT(*) FROM {$cache_tbl} WHERE source='page_indexing' AND ttl_until IS NOT NULL AND ttl_until > %s", $now_mysql)
        );
        $api_total = (int) $wpdb->get_var(
          $wpdb->prepare("SELECT COUNT(*) FROM {$cache_tbl} WHERE source=%s", 'inspection')
        );
        $api_fresh = (int) $wpdb->get_var(
          $wpdb->prepare("SELECT COUNT(*) FROM {$cache_tbl} WHERE source='inspection' AND ttl_until IS NOT NULL AND ttl_until > %s", $now_mysql)
        );
      $queue_state = MW_Audit_Queue::get('mw_gsc_queue');
      $likely_states = method_exists('MW_Audit_GSC','get_likely_not_indexed_reasons') ? MW_Audit_GSC::get_likely_not_indexed_reasons() : [];
      $new_hours = (int) apply_filters('mw_audit_gsc_new_page_hours', 72);
      $pending_args = ['likely_states' => $likely_states, 'new_hours' => $new_hours];
      $queue_candidates = MW_Audit_DB::count_gsc_candidates($pending_args);
      $queue_remaining = $queue_candidates;
      $stale_total = MW_Audit_DB::count_gsc_stale_total();
      $urls_per_min = 0.0;
      $eta_seconds = 0;
      $skipped = 0;
      $last_queue_error = '';
      if (is_array($queue_state)) {
        $done = isset($queue_state['done']) ? (int) $queue_state['done'] : 0;
        $total = isset($queue_state['total']) ? (int) $queue_state['total'] : 0;
        $queue_remaining = max(0, $total - $done);
        $skipped = isset($queue_state['skipped']) ? (int) $queue_state['skipped'] : 0;
        $started_at = isset($queue_state['started_at']) ? strtotime($queue_state['started_at']) : 0;
        $last_activity = isset($queue_state['last_activity']) ? strtotime($queue_state['last_activity']) : 0;
        if ($started_at && $last_activity && $last_activity > $started_at && $done > 0) {
          $elapsed_minutes = max(0.5, ($last_activity - $started_at) / 60);
          $urls_per_min = $done / $elapsed_minutes;
          if ($urls_per_min > 0 && $queue_remaining > 0) {
            $eta_seconds = (int) round(($queue_remaining / $urls_per_min) * 60);
          }
        }
        if (!empty($queue_state['last_error'])) {
          $last_queue_error = (string) $queue_state['last_error'];
        }
      }
      $quota_log = method_exists('MW_Audit_GSC','get_quota_log') ? MW_Audit_GSC::get_quota_log() : [];
      $today_key = function_exists('wp_date') ? wp_date('Y-m-d') : date_i18n('Y-m-d');
      $today_stat = isset($quota_log[$today_key]) && is_array($quota_log[$today_key]) ? $quota_log[$today_key] : ['calls'=>0,'errors'=>0];
      $quota_used = (int) ($today_stat['calls'] ?? 0);
      $quota_errors = (int) ($today_stat['errors'] ?? 0);
      $quota_limit = (int) apply_filters('mw_audit_gsc_daily_quota', 2000);
      $error_percent = ($quota_used > 0) ? ($quota_errors / max(1, $quota_used)) * 100 : 0;
      $last_error = '';
        if ($last_queue_error) {
          $last_error = $last_queue_error;
        } else {
          $last_error = $wpdb->get_var(
            $wpdb->prepare(
              "SELECT last_error FROM {$cache_tbl} WHERE last_error IS NOT NULL AND last_error <> %s ORDER BY inspected_at DESC LIMIT 1",
              ''
            )
          );
        }
      $export_ratio = ($inventory_rows > 0) ? min(1, $export_fresh / max(1, $inventory_rows)) : 0;
      $api_ratio = ($inventory_rows > 0) ? min(1, $api_fresh / max(1, $inventory_rows)) : 0;
      $export_state = 'neutral';
      if ($inventory_rows > 0) {
        if ($export_fresh >= $inventory_rows) {
          $export_state = 'ok';
        } elseif ($export_total > 0) {
          $export_state = 'warn';
        } else {
          $export_state = 'fail';
        }
      } elseif ($export_total > 0) {
        $export_state = 'warn';
      }
      $show_api_pill = !empty($settings['gsc_api_enabled']) || $api_total > 0;
      $api_state = 'neutral';
      if ($show_api_pill) {
        if ($inventory_rows > 0) {
          if ($api_fresh >= $inventory_rows) {
            $api_state = 'ok';
          } elseif ($api_total > 0) {
            $api_state = 'warn';
          } else {
            $api_state = 'fail';
          }
        } elseif ($api_total > 0) {
          $api_state = 'warn';
        } elseif (!empty($settings['gsc_api_enabled'])) {
          $api_state = 'fail';
        }
      }
        $gsc_metrics = [
          'export' => [
            'total'  => $export_total,
            'fresh'  => $export_fresh,
            'ratio'  => $export_ratio,
            'state'  => $export_state,
          ],
          'api' => [
            'total'  => $api_total,
            'fresh'  => $api_fresh,
            'ratio'  => $api_ratio,
            'state'  => $api_state,
            'enabled'=> !empty($settings['gsc_api_enabled']),
          ],
          'inventory_total' => $inventory_rows,
          'queue_length'    => $queue_remaining,
          'queue_candidates'=> $queue_candidates,
          'urls_per_min'    => $urls_per_min,
          'eta_seconds'     => $eta_seconds,
          'skipped'         => $skipped,
          'quota_used'      => $quota_used,
          'quota_limit'     => $quota_limit,
          'error_percent'   => $error_percent,
          'last_error'      => $last_error ?: '',
          'show_api_pill'   => $show_api_pill,
          'stale_total'     => $stale_total,
        ];
      }
    }
    }

    return array(
      'loopback' => $loop_ok ? 'OK' : 'FAIL',
      'robots'   => $robots_ok ? 'OK' : 'FAIL',
      'inventory_rows' => $inventory_rows,
      'status_rows'    => $status_rows,
      'pc_rows'        => $pc_rows,
      'last_update'    => $last_update,
      'last_inv_detected' => $last_inv_detected,
      'sitemap_cache_count' => $sm_files,
      'sitemap_cache_age'   => $sm_age,
      'sitemaps' => $sm_sources,
      'schema'  => $schema,
      'drop_on_uninstall' => get_option('mw_audit_drop_on_uninstall')==='yes' ? 'YES' : 'NO',
      'steps' => array(
        'sm'   => !empty($flags['sm'])   ? array('status'=>$flags['sm'],   'at'=>(!empty($flags['sm_at'])?$flags['sm_at']:''))   : null,
        'os'   => !empty($flags['os'])   ? array('status'=>$flags['os'],   'at'=>(!empty($flags['os_at'])?$flags['os_at']:''))   : null,
        'http' => !empty($flags['http']) ? array('status'=>$flags['http'], 'at'=>(!empty($flags['http_at'])?$flags['http_at']:'')) : null,
        'pc'   => !empty($flags['pc'])   ? array('status'=>$flags['pc'],   'at'=>(!empty($flags['pc_at'])?$flags['pc_at']:''))   : null,
        'link' => !empty($flags['link']) ? array('status'=>$flags['link'], 'at'=>(!empty($flags['link_at'])?$flags['link_at']:'')) : null,
        'outbound' => !empty($flags['outbound']) ? array('status'=>$flags['outbound'], 'at'=>(!empty($flags['outbound_at'])?$flags['outbound_at']:'')) : null,
        'inv'  => !empty($flags['inv'])  ? array('status'=>$flags['inv'],  'at'=>(!empty($flags['inv_at'])?$flags['inv_at']:''))  : null,
        'pi'   => !empty($flags['pi'])   ? array('status'=>$flags['pi'],   'at'=>(!empty($flags['pi_at'])?$flags['pi_at']:''))   : null,
        'gindex' => !empty($flags['gindex']) ? array('status'=>$flags['gindex'], 'at'=>(!empty($flags['gindex_at'])?$flags['gindex_at']:'')) : null,
      ),
      'pills' => array(
        'inventory' => $inv_state,
        'status'    => $status_state,
        'pcmap'     => $pc_state,
        'inv_last'  => $inv_last_state,
      ),
      'queues' => array(
        'inventory'=> $q_inv ? 1 : 0,
        'status' => $q_status ? 1 : 0,
        'http'   => $q_http ? 1 : 0,
        'pc'     => $q_pc ? 1 : 0,
        'gindex' => $q_gindex ? 1 : 0,
      ),
      'gsc' => $gsc_info,
      'pi_state' => $pi_state,
      'gsc_metrics' => $gsc_metrics,
    );
  }
}
