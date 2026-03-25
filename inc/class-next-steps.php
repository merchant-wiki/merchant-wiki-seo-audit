<?php
if (!defined('ABSPATH')) exit;

class MW_Audit_Next_Steps {
  const SNAPSHOT_OPTION = 'mw_audit_launch_snapshots';
  const SNAPSHOT_LIMIT  = 8;
  const CACHE_GROUP     = 'mw_audit';
  const CACHE_TTL       = 300;

  /**
   * Quick technical audit dataset (group blockers by issue_code)
   */
  public static function quick_audit_rows(){
    $inv = MW_Audit_DB::esc_table(MW_Audit_DB::t_inventory());
    $st  = MW_Audit_DB::esc_table(MW_Audit_DB::t_status());
    $sql = "
      SELECT i.norm_url,
             s.id AS status_id,
             s.http_status,
             s.in_sitemap,
             s.noindex,
             s.canonical,
             s.robots_meta,
             s.redirect_to,
             s.updated_at
      FROM {$inv} i
      LEFT JOIN {$st} s ON s.norm_url = i.norm_url
      WHERE (
         s.id IS NULL
         OR s.http_status IS NULL
         OR s.http_status <> 200
         OR COALESCE(s.in_sitemap, 0) = 0
         OR s.noindex = 1
      )
        AND %d = %d
      ORDER BY i.norm_url ASC
    ";
    $rows = self::query_cached('quick_audit', $sql, [1, 1]);
    $issues = [];
    foreach ($rows as $row){
      foreach (self::detect_quick_issues($row) as $issue_row){
        $issues[] = $issue_row;
      }
    }
    return $issues;
  }

  /**
   * Manual indexing queue dataset (after hitting Inspection API quota)
   */
  public static function manual_index_rows($threshold = 0){
    $threshold = max(0, (int) $threshold);
    $inv = MW_Audit_DB::esc_table(MW_Audit_DB::t_inventory());
    $st  = MW_Audit_DB::esc_table(MW_Audit_DB::t_status());
    $cache_table = MW_Audit_DB::t_gsc_cache();
    $cache = MW_Audit_DB::esc_table($cache_table);
    $cache_has_reason   = MW_Audit_DB::table_has_column($cache_table, 'reason_label');
    $cache_has_pi       = MW_Audit_DB::table_has_column($cache_table, 'pi_reason_raw');
    $likely_states = [];
    if (class_exists('MW_Audit_GSC') && method_exists('MW_Audit_GSC','get_likely_not_indexed_reasons')){
      $likely_states = array_filter((array) MW_Audit_GSC::get_likely_not_indexed_reasons());
    }
    $state_clause = '';
    $params = [$threshold];
    if ($likely_states){
      $placeholders = implode(',', array_fill(0, count($likely_states), '%s'));
      $state_clause = "OR g_ins.coverage_state IN ($placeholders) OR g_page.coverage_state IN ($placeholders)";
      $params = array_merge($params, $likely_states, $likely_states);
    }
    $sql = "
      SELECT i.norm_url,
             s.http_status,
             s.in_sitemap,
             s.noindex,
             s.inbound_links,
             s.indexed_in_google,
             s.updated_at,
             g_ins.coverage_state AS inspection_coverage,
             g_ins.verdict AS inspection_verdict,
             ".($cache_has_reason ? 'g_ins.reason_label AS inspection_reason,' : 'NULL AS inspection_reason,')."
             g_ins.inspected_at AS inspection_checked,
             g_page.coverage_state AS page_coverage,
             ".($cache_has_reason ? 'g_page.reason_label AS page_reason,' : 'NULL AS page_reason,')."
             ".($cache_has_pi ? 'g_page.pi_reason_raw AS page_reason_raw,' : 'NULL AS page_reason_raw,')."
             g_page.inspected_at AS page_checked
      FROM {$inv} i
      INNER JOIN {$st} s ON s.norm_url = i.norm_url
      LEFT JOIN {$cache} g_ins ON g_ins.norm_url = i.norm_url AND g_ins.source='inspection'
      LEFT JOIN {$cache} g_page ON g_page.norm_url = i.norm_url AND g_page.source='page_indexing'
      WHERE s.http_status = 200
        AND (s.noindex IS NULL OR s.noindex = 0)
        AND COALESCE(s.inbound_links, 0) <= %d
        AND (
          s.indexed_in_google IS NULL
          OR s.indexed_in_google = 0
          $state_clause
        )
      ORDER BY COALESCE(s.inbound_links, 0) ASC, i.norm_url ASC
    ";
    $cache_key = 'manual_index_'.md5($threshold.'|'.implode('|', $likely_states));
    $rows = self::query_cached($cache_key, $sql, $params);
    $output = [];
    foreach ($rows as $row){
      $output[] = self::build_manual_row($row);
    }
    return $output;
  }

  /**
   * Content pruning dataset (find crawl budget drains)
   */
  public static function content_pruning_rows(){
    $inv = MW_Audit_DB::esc_table(MW_Audit_DB::t_inventory());
    $st  = MW_Audit_DB::esc_table(MW_Audit_DB::t_status());
    $out = MW_Audit_DB::esc_table(MW_Audit_DB::t_outbound());
    $cache_table = MW_Audit_DB::t_gsc_cache();
    $cache = MW_Audit_DB::esc_table($cache_table);
    $cache_has_reason = MW_Audit_DB::table_has_column($cache_table, 'reason_label');
    $sql = "
      SELECT i.norm_url,
             s.http_status,
             s.indexed_in_google,
             s.inbound_links,
             s.noindex,
             s.schema_type,
             s.robots_meta,
             s.updated_at,
             ob.outbound_internal,
             ob.outbound_external,
             ob.outbound_external_domains,
             g_ins.coverage_state AS inspection_coverage,
             ".($cache_has_reason ? 'g_ins.reason_label AS inspection_reason,' : 'NULL AS inspection_reason,')."
             g_page.coverage_state AS page_coverage,
             ".($cache_has_reason ? 'g_page.reason_label AS page_reason,' : 'NULL AS page_reason,')."
             g_page.inspected_at AS page_checked
      FROM {$inv} i
      INNER JOIN {$st} s ON s.norm_url = i.norm_url
      LEFT JOIN {$out} ob ON ob.norm_url = i.norm_url
      LEFT JOIN {$cache} g_ins ON g_ins.norm_url = i.norm_url AND g_ins.source='inspection'
      LEFT JOIN {$cache} g_page ON g_page.norm_url = i.norm_url AND g_page.source='page_indexing'
      WHERE COALESCE(s.inbound_links, 0) = 0
        AND COALESCE(ob.outbound_external_domains, 0) > 0
        AND (
          s.indexed_in_google = 0
          OR g_ins.coverage_state IS NOT NULL
          OR g_page.coverage_state IS NOT NULL
        )
        AND %d = %d
      ORDER BY COALESCE(ob.outbound_external_domains,0) DESC, i.norm_url ASC
    ";
    $rows = self::query_cached('pruning', $sql, [1, 1]);
    $output = [];
    foreach ($rows as $row){
      $output[] = self::build_pruning_row($row);
    }
    return $output;
  }

  /**
   * Snapshot rows covering HTTP/canonical state
   */
  public static function snapshot_rows(){
    $inv = MW_Audit_DB::esc_table(MW_Audit_DB::t_inventory());
    $st  = MW_Audit_DB::esc_table(MW_Audit_DB::t_status());
    $sql = "
      SELECT i.norm_url,
             s.http_status,
             s.redirect_to,
             s.canonical,
             s.in_sitemap,
             s.noindex,
             s.updated_at
      FROM {$inv} i
      LEFT JOIN {$st} s ON s.norm_url = i.norm_url
      WHERE %d = %d
      ORDER BY i.norm_url ASC
    ";
    return self::query_cached('snapshots', $sql, [1, 1]);
  }

  public static function create_snapshot($label = ''){
    $rows = self::snapshot_rows();
    if (!$rows){
      return new WP_Error('mw_audit_snapshot_empty', __('No inventory/status rows are available yet. Run Refresh On-Site Signals first.','merchant-wiki-audit'));
    }
    $env = self::ensure_upload_dir();
    if (is_wp_error($env)){
      return $env;
    }
    $label = trim((string) $label);
    if ($label === ''){
      /* translators: %s is replaced with the localized snapshot timestamp. */
      $label = sprintf(__('Snapshot %s','merchant-wiki-audit'), current_time('Y-m-d H:i'));
    }
    $id = 'launch-'.gmdate('Ymd-Hi');
    $filename = $id.'.json.gz';
    $path = trailingslashit($env['dir']).$filename;
    $payload = wp_json_encode($rows);
    if ($payload === false){
      return new WP_Error('mw_audit_snapshot_encode', __('Unable to encode snapshot JSON.','merchant-wiki-audit'));
    }
    $gz = function_exists('gzencode') ? gzencode($payload, 6) : $payload;
    $filesystem = self::get_filesystem();
    if (!$filesystem){
      return new WP_Error('mw_audit_snapshot_write', __('Unable to initialize the filesystem API.','merchant-wiki-audit'));
    }
    $chmod = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : null;
    if (!$filesystem->put_contents($path, $gz, $chmod)){
      return new WP_Error('mw_audit_snapshot_write', __('Unable to write snapshot file.','merchant-wiki-audit'));
    }
    $meta = [
      'id'         => $id,
      'label'      => $label,
      'filename'   => $filename,
      'rows'       => count($rows),
      'created_at' => current_time('mysql'),
    ];
    $list = self::list_snapshots();
    array_unshift($list, $meta);
    if (count($list) > self::SNAPSHOT_LIMIT){
      $excess = array_splice($list, self::SNAPSHOT_LIMIT);
      foreach ($excess as $old){
        self::delete_snapshot_file($old);
      }
    }
    update_option(self::SNAPSHOT_OPTION, $list, false);
    return $meta;
  }

  public static function list_snapshots(){
    $list = get_option(self::SNAPSHOT_OPTION, []);
    if (!is_array($list)){
      $list = [];
    }
    return $list;
  }

  public static function diff_snapshots_rows($older_id, $newer_id){
    $older = self::load_snapshot($older_id);
    if (is_wp_error($older)){
      return $older;
    }
    $newer = self::load_snapshot($newer_id);
    if (is_wp_error($newer)){
      return $newer;
    }
    $old_map = [];
    foreach ($older['rows'] as $row){
      $old_map[$row['norm_url']] = $row;
    }
    $diffs = [];
    foreach ($newer['rows'] as $row){
      $url = $row['norm_url'];
      if (!isset($old_map[$url])){
        $diffs[] = [
          'change_type' => 'new_url',
          'norm_url'    => $url,
          'before'      => '—',
          'after'       => self::format_snapshot_summary($row),
          'before_checked' => '',
          'after_checked'  => $row['updated_at'] ?? '',
        ];
        continue;
      }
      $prev = $old_map[$url];
      unset($old_map[$url]);
      if ((int) ($prev['http_status'] ?? 0) !== (int) ($row['http_status'] ?? 0)){
        $diffs[] = [
          'change_type' => 'http_status',
          'norm_url'    => $url,
          'before'      => (string) ($prev['http_status'] ?? ''),
          'after'       => (string) ($row['http_status'] ?? ''),
          'before_checked' => $prev['updated_at'] ?? '',
          'after_checked'  => $row['updated_at'] ?? '',
        ];
      }
      if (self::normalize_str($prev['canonical'] ?? '') !== self::normalize_str($row['canonical'] ?? '')){
        $diffs[] = [
          'change_type' => 'canonical',
          'norm_url'    => $url,
          'before'      => $prev['canonical'] ?? '',
          'after'       => $row['canonical'] ?? '',
          'before_checked' => $prev['updated_at'] ?? '',
          'after_checked'  => $row['updated_at'] ?? '',
        ];
      }
      if (self::normalize_str($prev['redirect_to'] ?? '') !== self::normalize_str($row['redirect_to'] ?? '')){
        $diffs[] = [
          'change_type' => 'redirect_to',
          'norm_url'    => $url,
          'before'      => $prev['redirect_to'] ?? '',
          'after'       => $row['redirect_to'] ?? '',
          'before_checked' => $prev['updated_at'] ?? '',
          'after_checked'  => $row['updated_at'] ?? '',
        ];
      }
    }
    if ($old_map){
      foreach ($old_map as $url => $row){
        $diffs[] = [
          'change_type' => 'removed_url',
          'norm_url'    => $url,
          'before'      => self::format_snapshot_summary($row),
          'after'       => '—',
          'before_checked' => $row['updated_at'] ?? '',
          'after_checked'  => '',
        ];
      }
    }
    return $diffs;
  }

  /** Admin POST: export quick blockers */
  public static function action_export_quick(){
    self::ensure_capability();
    check_admin_referer('mw_next_steps_quick');
    $rows = self::quick_audit_rows();
    self::stream_csv('mw-quick-audit-blockers.csv', self::quick_header(), $rows);
  }

  /** Admin POST: export manual indexing queue */
  public static function action_export_manual(){
    self::ensure_capability();
    check_admin_referer('mw_next_steps_manual');
    $threshold = isset($_POST['threshold']) ? absint(wp_unslash($_POST['threshold'])) : 0;
    $rows = self::manual_index_rows($threshold);
    $filename = sprintf('mw-manual-indexing-%dlinks.csv', $threshold);
    self::stream_csv($filename, self::manual_header(), $rows);
  }

  /** Admin POST: export content pruning sheet */
  public static function action_export_pruning(){
    self::ensure_capability();
    check_admin_referer('mw_next_steps_pruning');
    $rows = self::content_pruning_rows();
    self::stream_csv('mw-content-pruning.csv', self::pruning_header(), $rows);
  }

  /** Admin POST: create launch snapshot */
  public static function action_create_snapshot(){
    self::ensure_capability();
    check_admin_referer('mw_next_steps_snapshot');
    $label = isset($_POST['snapshot_label']) ? sanitize_text_field(wp_unslash($_POST['snapshot_label'])) : '';
    $result = self::create_snapshot($label);
    $redirect = menu_page_url('mw-site-index-reports', false);
    if (!$redirect){
      $redirect = admin_url('admin.php?page=mw-site-index-reports');
    }
    if (is_wp_error($result)){
      wp_safe_redirect(add_query_arg('mw_snapshot_error', $result->get_error_message(), $redirect));
      exit;
    }
    wp_safe_redirect(add_query_arg([
      'mw_snapshot_created' => $result['label'],
      'mw_snapshot_rows'    => (int) $result['rows'],
    ], $redirect));
    exit;
  }

  /** Admin POST: diff snapshots */
  public static function action_diff_snapshots(){
    self::ensure_capability();
    check_admin_referer('mw_next_steps_diff');
    $older = isset($_POST['snapshot_old']) ? sanitize_text_field(wp_unslash($_POST['snapshot_old'])) : '';
    $newer = isset($_POST['snapshot_new']) ? sanitize_text_field(wp_unslash($_POST['snapshot_new'])) : '';
    $redirect = menu_page_url('mw-site-index-reports', false);
    if (!$redirect){
      $redirect = admin_url('admin.php?page=mw-site-index-reports');
    }
    if (!$older || !$newer || $older === $newer){
      wp_safe_redirect(add_query_arg('mw_snapshot_error', __('Choose two different snapshots to compare.','merchant-wiki-audit'), $redirect));
      exit;
    }
    $rows = self::diff_snapshots_rows($older, $newer);
    if (is_wp_error($rows)){
      wp_safe_redirect(add_query_arg('mw_snapshot_error', $rows->get_error_message(), $redirect));
      exit;
    }
    $filename = sprintf('mw-launch-diff-%s-vs-%s.csv', $older, $newer);
    self::stream_csv($filename, self::snapshot_diff_header(), $rows);
  }

  /**
   * CLI helpers
   */
  public static function write_csv_to_path($path, array $header_map, array $rows){
    $dir = dirname($path);
    if (!is_dir($dir)){
      wp_mkdir_p($dir);
    }
    $filesystem = self::get_filesystem();
    if (!$filesystem){
      return new WP_Error('mw_next_steps_csv', __('Unable to initialize the filesystem API.','merchant-wiki-audit'));
    }
    $csv = self::format_csv($header_map, $rows);
    $chmod = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : null;
    if (!$filesystem->put_contents($path, $csv, $chmod)){
      /* translators: %s is a filesystem path on the current server. */
      return new WP_Error('mw_next_steps_csv', sprintf(__('Unable to open %s for writing.','merchant-wiki-audit'), $path));
    }
    return $path;
  }

  // ----- internal helpers -----

  private static function detect_quick_issues(array $row){
    $issues = [];
    $status = isset($row['http_status']) ? (int) $row['http_status'] : null;
    if ($status === null){
      $issues[] = self::quick_row($row, 'missing_http', 1, __('HTTP status missing — rerun Refresh On-Site Signals before exporting.','merchant-wiki-audit'));
    } elseif ($status < 200 || $status >= 300){
      $msg = ($status >= 500)
        ? __('Server error — fix template/server configuration then rerun Refresh.','merchant-wiki-audit')
        : (($status >= 400)
          ? __('Client error — confirm URL should exist or add redirect.','merchant-wiki-audit')
          : __('Unexpected redirect — verify target/canonical before launch.','merchant-wiki-audit'));
      $issues[] = self::quick_row($row, 'http_status', 1, $msg);
    }
    $in_sitemap = $row['in_sitemap'];
    if ((int) $in_sitemap === 0){
      $issues[] = self::quick_row($row, 'missing_sitemap', 2, __('Submit URL in XML sitemap or include via sitemap index.','merchant-wiki-audit'));
    }
    if ((int) ($row['noindex'] ?? 0) === 1){
      $issues[] = self::quick_row($row, 'noindex', 2, __('Remove unintended noindex directives or confirm gating.','merchant-wiki-audit'));
    }
    return $issues;
  }

  private static function quick_row(array $row, $code, $priority, $next_step){
    return [
      'ticket_group'   => $code,
      'priority'       => (int) $priority,
      'norm_url'       => $row['norm_url'] ?? '',
      'http_status'    => $row['http_status'] !== null ? (string) $row['http_status'] : '—',
      'in_sitemap'     => self::flag($row['in_sitemap'] ?? null),
      'noindex'        => self::flag($row['noindex'] ?? null),
      'canonical'      => $row['canonical'] ?? '',
      'robots_meta'    => $row['robots_meta'] ?? '',
      'redirect_to'    => $row['redirect_to'] ?? '',
      'last_signal_at' => $row['updated_at'] ?? '',
      'next_step'      => $next_step,
    ];
  }

  private static function build_manual_row(array $row){
    $gsc_reason = $row['page_reason'] ?: $row['inspection_reason'] ?: '';
    if (!$gsc_reason && !empty($row['page_reason_raw'])){
      $gsc_reason = $row['page_reason_raw'];
    }
    $evidence = [];
    $links = isset($row['inbound_links']) ? (int) $row['inbound_links'] : null;
    if ($links !== null){
      $evidence[] = sprintf('inbound_links=%d', $links);
    }
    if ($row['inspection_coverage']){
      $evidence[] = 'inspection: '.$row['inspection_coverage'];
    } elseif ($row['page_coverage']){
      $evidence[] = 'page: '.$row['page_coverage'];
    }
    if ($gsc_reason){
      $evidence[] = 'reason: '.$gsc_reason;
    } elseif ((int) ($row['indexed_in_google'] ?? 0) === 0){
      $evidence[] = 'local indexed_in_google=0';
    }
    $next_step = __('Push to manual indexing rotation, then run Google Index Status with “Only queue stale/new URLs”.','merchant-wiki-audit');
    return [
      'queue_reason'   => $gsc_reason ?: __('Likely not indexed','merchant-wiki-audit'),
      'norm_url'       => $row['norm_url'],
      'http_status'    => (string) $row['http_status'],
      'inbound_links'  => $links !== null ? (string) $links : '—',
      'in_sitemap'     => self::flag($row['in_sitemap']),
      'indexed_in_google' => self::flag($row['indexed_in_google']),
      'gsc_reason'     => $gsc_reason,
      'gsc_pi_reason'  => $row['page_reason_raw'] ?? '',
      'gsc_checked'    => $row['inspection_checked'] ?: $row['page_checked'] ?: '',
      'evidence'       => implode(' | ', $evidence),
      'next_step'      => $next_step,
    ];
  }

  private static function build_pruning_row(array $row){
    $action = 'improve';
    $next = __('Improve content, add schema, and wire internal links.','merchant-wiki-audit');
    $external_domains = (int) ($row['outbound_external_domains'] ?? 0);
    $external_links   = (int) ($row['outbound_external'] ?? 0);
    if ((int) ($row['noindex'] ?? 0) === 1){
      $action = '410';
      $next = __('Confirm retirement and serve 410 or remove from sitemap.','merchant-wiki-audit');
    } elseif ($external_domains >= 4 || $external_links >= 15){
      $action = 'redirect';
      $next = __('Redirect to stronger canonical page to preserve equity.','merchant-wiki-audit');
    } elseif (!$row['schema_type'] && !$row['robots_meta']){
      $action = 'improve';
      $next = __('Add schema/metadata and internal hubs before re-indexing.','merchant-wiki-audit');
    }
    $reason = $row['page_reason'] ?: $row['inspection_reason'] ?: '';
    return [
      'action'          => $action,
      'norm_url'        => $row['norm_url'],
      'http_status'     => (string) ($row['http_status'] ?? ''),
      'indexed_in_google' => self::flag($row['indexed_in_google']),
      'inbound_links'   => (string) ($row['inbound_links'] ?? '0'),
      'outbound_internal' => (string) ($row['outbound_internal'] ?? '0'),
      'outbound_external' => (string) ($row['outbound_external'] ?? '0'),
      'external_domains'  => (string) $external_domains,
      'schema_type'     => $row['schema_type'] ?? '',
      'robots_meta'     => $row['robots_meta'] ?? '',
      'gsc_reason'      => $reason,
      'updated_at'      => $row['updated_at'] ?? '',
      'next_step'       => $next,
    ];
  }

  private static function stream_csv($filename, array $header_map, array $rows){
    $safe_name = sanitize_file_name($filename);
    if ($safe_name === ''){
      $safe_name = 'mw-export.csv';
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$safe_name.'"');
    self::output_csv($header_map, $rows);
    exit;
  }

  private static function ensure_capability(){
    if (!current_user_can('manage_options')){
      wp_die(esc_html__('Not allowed','merchant-wiki-audit'));
    }
  }

  private static function ensure_upload_dir(){
    $upload = wp_upload_dir();
    if (!empty($upload['error'])){
      return new WP_Error('mw_audit_snapshot_dir', $upload['error']);
    }
    $dir = trailingslashit($upload['basedir']).'mw-audit';
    if (!wp_mkdir_p($dir)){
      return new WP_Error('mw_audit_snapshot_dir', __('Unable to create uploads directory.','merchant-wiki-audit'));
    }
    $url = trailingslashit($upload['baseurl']).'mw-audit';
    return ['dir'=>$dir,'url'=>$url];
  }

  private static function delete_snapshot_file(array $meta){
    $env = self::ensure_upload_dir();
    if (is_wp_error($env)){
      return;
    }
    if (empty($meta['filename'])){
      return;
    }
    $path = trailingslashit($env['dir']).$meta['filename'];
    if (file_exists($path)){
      wp_delete_file($path);
    }
  }

  private static function load_snapshot($id){
    $env = self::ensure_upload_dir();
    if (is_wp_error($env)){
      return $env;
    }
    $list = self::list_snapshots();
    foreach ($list as $meta){
      if ($meta['id'] === $id){
        $path = trailingslashit($env['dir']).$meta['filename'];
        if (!file_exists($path)){
          return new WP_Error('mw_audit_snapshot_missing', __('Snapshot file is missing.','merchant-wiki-audit'));
        }
        $filesystem = self::get_filesystem();
        if (!$filesystem){
          return new WP_Error('mw_audit_snapshot_read', __('Unable to initialize the filesystem API.','merchant-wiki-audit'));
        }
        $contents = $filesystem->get_contents($path);
        if ($contents === false){
          return new WP_Error('mw_audit_snapshot_read', __('Unable to read snapshot file.','merchant-wiki-audit'));
        }
        if (function_exists('gzdecode')){
          $decoded = gzdecode($contents);
          if ($decoded !== false){
            $contents = $decoded;
          }
        }
        $rows = json_decode($contents, true);
        if (!is_array($rows)){
          return new WP_Error('mw_audit_snapshot_json', __('Snapshot file is corrupted.','merchant-wiki-audit'));
        }
        return ['meta'=>$meta,'rows'=>$rows];
      }
    }
    return new WP_Error('mw_audit_snapshot_not_found', __('Snapshot not found.','merchant-wiki-audit'));
  }

  private static function format_snapshot_summary(array $row){
    $parts = [];
    if (isset($row['http_status'])){
      $parts[] = 'HTTP='.$row['http_status'];
    }
    if (!empty($row['canonical'])){
      $parts[] = 'canonical='.$row['canonical'];
    }
    if (!empty($row['redirect_to'])){
      $parts[] = 'redirect='.$row['redirect_to'];
    }
    return implode(' | ', $parts);
  }

  private static function normalize_str($value){
    return trim((string) $value);
  }

  private static function flag($value){
    if ($value === null || $value === ''){
      return '—';
    }
    return ((int) $value) ? '1' : '0';
  }

  private static function output_csv(array $header_map, array $rows){
    self::each_csv_row($header_map, $rows, static function($line){
      echo $line, "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- streaming raw CSV, already sanitized in csv_line()
    });
  }

  private static function format_csv(array $header_map, array $rows){
    $lines = [];
    self::each_csv_row($header_map, $rows, static function($line) use (&$lines){
      $lines[] = $line;
    });
    return implode("\r\n", $lines)."\r\n";
  }

  private static function each_csv_row(array $header_map, array $rows, callable $handler){
    $keys = array_keys($header_map);
    $handler(self::csv_line(array_values($header_map)));
    foreach ($rows as $row){
      $line = [];
      foreach ($keys as $key){
        $line[] = isset($row[$key]) ? $row[$key] : '';
      }
      $handler(self::csv_line($line));
    }
  }

  private static function csv_line(array $values){
    $escaped = [];
    foreach ($values as $value){
      if (!is_scalar($value)){
        $value = wp_json_encode($value);
      }
      $value = str_replace(["\r","\n"], ' ', (string) $value);
      if (strpbrk($value, ',"') !== false){
        $escaped[] = '"'.str_replace('"', '""', $value).'"';
      } else {
        $escaped[] = $value;
      }
    }
    return implode(',', $escaped);
  }

  private static function get_filesystem(){
    if (!function_exists('WP_Filesystem')){
      require_once ABSPATH.'wp-admin/includes/file.php';
    }
    global $wp_filesystem;
    if (!$wp_filesystem instanceof WP_Filesystem_Base){
      WP_Filesystem();
    }
    if ($wp_filesystem instanceof WP_Filesystem_Base){
      return $wp_filesystem;
    }
    if (!class_exists('WP_Filesystem_Direct', false)){
      require_once ABSPATH.'wp-admin/includes/class-wp-filesystem-base.php';
      require_once ABSPATH.'wp-admin/includes/class-wp-filesystem-direct.php';
    }
    return new WP_Filesystem_Direct(false);
  }

  private static function query_cached($cache_key, $sql, array $params = [], $output_type = ARRAY_A, $ttl = self::CACHE_TTL){
    $cache_key = 'next_steps_'.$cache_key;
    $found = false;
    $cached = wp_cache_get($cache_key, self::CACHE_GROUP, false, $found);
    if ($found){
      return $cached;
    }
    $results = MW_Audit_DB::get_results_sql($sql, $params, $output_type) ?: [];
    wp_cache_set($cache_key, $results, self::CACHE_GROUP, $ttl);
    return $results;
  }

  public static function quick_header(){
    return [
      'ticket_group'   => esc_html__('Issue','merchant-wiki-audit'),
      'priority'       => esc_html__('Priority','merchant-wiki-audit'),
      'norm_url'       => esc_html__('URL','merchant-wiki-audit'),
      'http_status'    => esc_html__('HTTP','merchant-wiki-audit'),
      'in_sitemap'     => esc_html__('Sitemap','merchant-wiki-audit'),
      'noindex'        => esc_html__('Noindex','merchant-wiki-audit'),
      'canonical'      => esc_html__('Canonical','merchant-wiki-audit'),
      'robots_meta'    => esc_html__('Robots','merchant-wiki-audit'),
      'redirect_to'    => esc_html__('Redirect','merchant-wiki-audit'),
      'last_signal_at' => esc_html__('Last signal','merchant-wiki-audit'),
      'next_step'      => esc_html__('Next step','merchant-wiki-audit'),
    ];
  }

  public static function manual_header(){
    return [
      'queue_reason'      => esc_html__('Reason','merchant-wiki-audit'),
      'norm_url'          => esc_html__('URL','merchant-wiki-audit'),
      'http_status'       => esc_html__('HTTP','merchant-wiki-audit'),
      'inbound_links'     => esc_html__('Inbound links','merchant-wiki-audit'),
      'in_sitemap'        => esc_html__('Sitemap','merchant-wiki-audit'),
      'indexed_in_google' => esc_html__('Indexed (local)','merchant-wiki-audit'),
      'gsc_reason'        => esc_html__('GSC reason','merchant-wiki-audit'),
      'gsc_pi_reason'     => esc_html__('Page indexing detail','merchant-wiki-audit'),
      'gsc_checked'       => esc_html__('Last checked','merchant-wiki-audit'),
      'evidence'          => esc_html__('Evidence','merchant-wiki-audit'),
      'next_step'         => esc_html__('Next step','merchant-wiki-audit'),
    ];
  }

  public static function pruning_header(){
    return [
      'action'            => esc_html__('Action','merchant-wiki-audit'),
      'norm_url'          => esc_html__('URL','merchant-wiki-audit'),
      'http_status'       => esc_html__('HTTP','merchant-wiki-audit'),
      'indexed_in_google' => esc_html__('Indexed (local)','merchant-wiki-audit'),
      'inbound_links'     => esc_html__('Inbound links','merchant-wiki-audit'),
      'outbound_internal' => esc_html__('Outbound internal','merchant-wiki-audit'),
      'outbound_external' => esc_html__('Outbound external','merchant-wiki-audit'),
      'external_domains'  => esc_html__('External domains','merchant-wiki-audit'),
      'schema_type'       => esc_html__('Schema','merchant-wiki-audit'),
      'robots_meta'       => esc_html__('Robots','merchant-wiki-audit'),
      'gsc_reason'        => esc_html__('GSC signal','merchant-wiki-audit'),
      'updated_at'        => esc_html__('Last refreshed','merchant-wiki-audit'),
      'next_step'         => esc_html__('Next step','merchant-wiki-audit'),
    ];
  }

  public static function snapshot_diff_header(){
    return [
      'change_type'    => esc_html__('Change','merchant-wiki-audit'),
      'norm_url'       => esc_html__('URL','merchant-wiki-audit'),
      'before'         => esc_html__('Before','merchant-wiki-audit'),
      'after'          => esc_html__('After','merchant-wiki-audit'),
      'before_checked' => esc_html__('Before timestamp','merchant-wiki-audit'),
      'after_checked'  => esc_html__('After timestamp','merchant-wiki-audit'),
    ];
  }
}
