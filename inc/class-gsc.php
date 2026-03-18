<?php
if (!defined('ABSPATH')) exit;

class MW_Audit_GSC {
  const OPTION_PREFIX = 'mw_audit_gsc_';
  const OPTION_TTL_HOURS = 'ttl_hours';
  const OPTION_SCOPES = 'granted_scopes';
  const OPTION_QUOTA_LOG = 'quota_log';
  const CACHE_PREFIX  = 'mw_audit_gsc_idx_';
  const CACHE_TTL     = DAY_IN_SECONDS;
  const DEFAULT_TTL_HOURS = 48;
  const TTL_CHOICES = [24, 48, 72, 168];
  const SCOPE_GSC   = 'https://www.googleapis.com/auth/webmasters.readonly';
  const SCOPE_SHEETS = 'https://www.googleapis.com/auth/spreadsheets';
  const DEFAULT_SCOPES = [self::SCOPE_GSC];
  const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
  const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';
  const SITES_ENDPOINT = 'https://www.googleapis.com/webmasters/v3/sites';
  const INSPECT_ENDPOINT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
  const SHEETS_BASE_ENDPOINT = 'https://sheets.googleapis.com/v4/spreadsheets';

  const LIKELY_NOT_INDEXED_DEFAULT = [
    'Discovered - currently not indexed',
    'Crawled - currently not indexed',
    'Duplicate, Google chose different canonical',
    'Soft 404',
    'Excluded by "noindex"',
    'Alternate page with proper canonical tag',
  ];

  private static function option_key($key){
    return self::OPTION_PREFIX . sanitize_key($key);
  }

  private static function get_option($key, $default = ''){
    $value = get_option(self::option_key($key), $default);
    return $value;
  }

  private static function update_option($key, $value){
    update_option(self::option_key($key), $value, false);
  }

  private static function delete_option($key){
    delete_option(self::option_key($key));
  }

  public static function allowed_ttl_hours(){
    return self::TTL_CHOICES;
  }

  public static function get_ttl_hours(){
    return self::get_api_ttl_hours();
  }

  public static function save_ttl_hours($hours){
    $hours = (int) $hours;
    if (!in_array($hours, self::TTL_CHOICES, true)){
      $hours = self::DEFAULT_TTL_HOURS;
    }
    $settings = MW_Audit_DB::get_settings();
    $settings['ttl']['api_hours'] = $hours;
    MW_Audit_DB::update_settings($settings);
    // retain compatibility flag
    self::update_option(self::OPTION_TTL_HOURS, $hours);
  }

  public static function get_api_ttl_hours(){
    $settings = MW_Audit_DB::get_settings();
    return max(1, (int) ($settings['ttl']['api_hours'] ?? self::DEFAULT_TTL_HOURS));
  }

  public static function get_export_ttl_hours(){
    $settings = MW_Audit_DB::get_settings();
    return max(1, (int) ($settings['ttl']['export_hours'] ?? 48));
  }

  public static function get_likely_not_indexed_reasons(){
    $list = apply_filters('mw_audit_gsc_likely_not_indexed', self::LIKELY_NOT_INDEXED_DEFAULT);
    if (!is_array($list)){
      $list = self::LIKELY_NOT_INDEXED_DEFAULT;
    }
    $list = array_filter(array_map('trim', array_map('strval', $list)));
    $list = array_values(array_unique($list));
    return $list;
  }

  public static function is_likely_not_indexed($coverage_state){
    if (!$coverage_state){
      return false;
    }
    return in_array($coverage_state, self::get_likely_not_indexed_reasons(), true);
  }

  private static function normalize_scope_list($scopes){
    if (is_string($scopes)){
      $scopes = preg_split('/\s+/', trim($scopes));
    }
    if (!is_array($scopes)){
      $scopes = [];
    }
    $scopes = array_filter(array_map('trim', array_map('strval', $scopes)));
    return array_values(array_unique($scopes));
  }

  private static function save_scopes(array $scopes){
    $scopes = self::normalize_scope_list($scopes);
    self::update_option(self::OPTION_SCOPES, $scopes);
  }

  public static function get_granted_scopes(){
    $raw = self::get_option(self::OPTION_SCOPES, []);
    return self::normalize_scope_list($raw);
  }

  public static function has_scope($scope){
    $scope = trim((string) $scope);
    if ($scope === ''){
      return false;
    }
    return in_array($scope, self::get_granted_scopes(), true);
  }

  public static function has_sheets_scope(){
    return self::has_scope(self::SCOPE_SHEETS);
  }

  public static function get_scopes_for_auth($include_sheets = false){
    $scopes = self::DEFAULT_SCOPES;
    if ($include_sheets){
      $scopes[] = self::SCOPE_SHEETS;
    }
    return array_values(array_unique($scopes));
  }

  private static function mysql_now(){
    return current_time('mysql');
  }

  private static function mysql_future_hours($hours){
    $hours = max(0, (int) $hours);
    $timestamp = current_time('timestamp') + ($hours * HOUR_IN_SECONDS);
    if (function_exists('wp_date')){
      return wp_date('Y-m-d H:i:s', $timestamp);
    }
    return date_i18n('Y-m-d H:i:s', $timestamp);
  }

  private static function normalize_cache_url($url){
    if (!is_string($url)){
      $url = (string) $url;
    }
    $url = trim($url);
    if ($url === ''){
      return '';
    }
    $sanitized = esc_url_raw($url);
    if ($sanitized === ''){
      $sanitized = sanitize_text_field($url);
    }
    if (strlen($sanitized) > 191){
      if (function_exists('mb_substr')){
        $sanitized = mb_substr($sanitized, 0, 191);
      } else {
        $sanitized = substr($sanitized, 0, 191);
      }
    }
    return $sanitized;
  }

  private static function sanitize_cache_payload(array $data){
    $clean = [];
    $map = [
      'verdict'         => 'text',
      'coverage_state'  => 'text',
      'reason_label'    => 'text',
      'http_status'     => 'text',
      'robots_txt_state'=> 'text',
      'last_crawl_time' => 'datetime',
      'inspected_at'    => 'datetime',
      'ttl_until'       => 'datetime',
      'pi_reason_raw'   => 'textarea',
      'payload'         => 'textarea',
      'notes'           => 'textarea',
      'attempts'        => 'int',
      'last_error'      => 'textarea',
      'updated_at'      => 'datetime',
    ];
    foreach ($map as $field => $type){
      if (!array_key_exists($field, $data)){
        continue;
      }
      $value = $data[$field];
      switch ($type){
        case 'text':
          $clean[$field] = ($value === null || $value === '') ? null : sanitize_text_field($value);
          break;
        case 'datetime':
          $clean[$field] = ($value === null || $value === '') ? null : sanitize_text_field($value);
          break;
        case 'textarea':
          $clean[$field] = ($value === null || $value === '') ? null : sanitize_textarea_field($value);
          break;
        case 'int':
          $clean[$field] = (int) $value;
          break;
      }
    }
    return $clean;
  }

  private static function format_cache_row($row){
    if (!is_array($row)){
      return null;
    }
    $row['attempts'] = isset($row['attempts']) ? (int) $row['attempts'] : 0;
    return $row;
  }

  public static function get_cache_row($url, $source = 'inspection'){
    global $wpdb;
    $table = MW_Audit_DB::t_gsc_cache();
    $norm = self::normalize_cache_url($url);
    if ($norm === ''){
      return null;
    }
    $source = self::normalize_source_key($source);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE norm_url=%s AND source=%s LIMIT 1", $norm, $source), ARRAY_A);
    if ($wpdb->last_error){
      MW_Audit_DB::log('GSC cache fetch error: '.$wpdb->last_error);
    }
    return self::format_cache_row($row);
  }

  public static function get_cache_rows(array $urls, $source = 'inspection'){
    global $wpdb;
    $table = MW_Audit_DB::t_gsc_cache();
    $norms = [];
    foreach ($urls as $url){
      $norm = self::normalize_cache_url($url);
      if ($norm !== ''){
        $norms[$norm] = true;
      }
    }
    if (!$norms){
      return [];
    }
    $source = self::normalize_source_key($source);
    $placeholders = implode(',', array_fill(0, count($norms), '%s'));
    $query = $wpdb->prepare(
      "SELECT * FROM $table WHERE source=%s AND norm_url IN ($placeholders)",
      array_merge([$source], array_keys($norms))
    );
    $rows = $wpdb->get_results($query, ARRAY_A);
    if ($wpdb->last_error){
      MW_Audit_DB::log('GSC cache multi fetch error: '.$wpdb->last_error);
    }
    $result = [];
    foreach ($rows as $row){
      $result[$row['norm_url']] = self::format_cache_row($row);
    }
    return $result;
  }

  private static function normalize_source_key($source){
    $value = strtolower(trim((string) $source));
    if ($value === 'sheet'){
      return 'page_indexing';
    }
    if ($value === 'api'){
      return 'inspection';
    }
    if ($value === 'page_indexing' || $value === 'inspection'){
      return $value;
    }
    return 'inspection';
  }

  public static function upsert_cache_row($url, array $data, $source = 'inspection'){
    global $wpdb;
    $table = MW_Audit_DB::t_gsc_cache();
    $norm = self::normalize_cache_url($url);
    if ($norm === ''){
      return false;
    }
    if (isset($data['payload']) && is_array($data['payload'])){
      $data['payload'] = wp_json_encode($data['payload']);
    }
    $clean = self::sanitize_cache_payload($data);
    $payload = array_merge([
      'norm_url' => $norm,
      'source'   => self::normalize_source_key($source),
    ], $clean);
    $result = $wpdb->replace($table, $payload);
    if ($result === false && $wpdb->last_error){
      MW_Audit_DB::log('GSC cache upsert error: '.$wpdb->last_error);
    }
    return $result !== false;
  }

  public static function mark_inspection_success($url, array $result){
    $ttl_hours = self::get_api_ttl_hours();
    $inspected_at = $result['inspected_at'] ?? self::mysql_now();
    $ttl_until = $result['ttl_until'] ?? self::mysql_future_hours($ttl_hours);
    $reason_hint = $result['pi_reason_raw'] ?? $result['coverage_state'] ?? '';
    $payload = [
      'verdict'         => $result['verdict'] ?? null,
      'coverage_state'  => $result['coverage_state'] ?? null,
      'reason_label'    => $result['reason_label'] ?? self::determine_reason_label($result['coverage_state'] ?? '', $reason_hint),
      'last_crawl_time' => $result['last_crawl_time'] ?? null,
      'inspected_at'    => $inspected_at,
      'ttl_until'       => $ttl_until,
      'pi_reason_raw'   => $result['pi_reason_raw'] ?? null,
      'attempts'        => isset($result['attempts']) ? (int)$result['attempts'] : 1,
      'last_error'      => null,
      'payload'         => [
        'verdict' => $result['verdict'] ?? null,
        'coverage'=> $result['coverage_state'] ?? null,
        'indexed' => $result['indexed'] ?? null,
      ],
    ];
    self::upsert_cache_row($url, $payload, 'inspection');
  }

  public static function mark_inspection_error($url, $message, $backoff_seconds = 3600){
    $norm_message = ($message === null || $message === '') ? null : sanitize_text_field($message);
    $ttl_at = null;
    if ($backoff_seconds > 0){
      $hours = max(1, (int) ceil($backoff_seconds / HOUR_IN_SECONDS));
      $ttl_at = self::mysql_future_hours($hours);
    }
    $current = self::get_cache_row($url, 'inspection');
    $attempts = $current ? ((int)$current['attempts'] + 1) : 1;
    $payload = [
      'attempts'   => $attempts,
      'last_error' => $norm_message,
      'ttl_until'  => $ttl_at,
      'inspected_at' => self::mysql_now(),
    ];
    self::upsert_cache_row($url, $payload, 'inspection');
  }

  public static function update_inspection_fields($url, array $fields){
    $current = self::get_cache_row($url, 'inspection');
    if (!$current){
      return false;
    }
    $payload = array_merge($current, $fields);
    if (!isset($payload['ttl_until']) || !$payload['ttl_until']){
      $payload['ttl_until'] = $current['ttl_until'];
    }
    if (!isset($payload['inspected_at']) || !$payload['inspected_at']){
      $payload['inspected_at'] = $current['inspected_at'];
    }
    if (!isset($payload['attempts'])){
      $payload['attempts'] = isset($current['attempts']) ? (int)$current['attempts'] : 0;
    }
    return self::upsert_cache_row($url, $payload, 'inspection');
  }

  public static function log_quota_usage($calls, $errors = 0){
    $calls = max(0, (int) $calls);
    $errors = max(0, (int) $errors);
    if ($calls === 0 && $errors === 0){
      return;
    }
    $log = self::get_option(self::OPTION_QUOTA_LOG, []);
    if (!is_array($log)){
      $log = [];
    }
    $today = function_exists('wp_date') ? wp_date('Y-m-d') : date_i18n('Y-m-d');
    if (!isset($log[$today]) || !is_array($log[$today])){
      $log[$today] = ['calls'=>0,'errors'=>0];
    }
    $log[$today]['calls'] += $calls;
    $log[$today]['errors'] += $errors;
    if (count($log) > 10){
      $log = array_slice($log, -10, null, true);
    }
    self::update_option(self::OPTION_QUOTA_LOG, $log);
  }

  public static function get_quota_log(){
    $log = self::get_option(self::OPTION_QUOTA_LOG, []);
    return is_array($log) ? $log : [];
  }

  private static function normalize_google_timestamp($value){
    if (!$value){
      return null;
    }
    $value = is_string($value) ? trim($value) : '';
    if ($value === ''){
      return null;
    }
    $ts = strtotime($value);
    if (!$ts){
      return null;
    }
    if (function_exists('wp_date')){
      return wp_date('Y-m-d H:i:s', $ts);
    }
    return date_i18n('Y-m-d H:i:s', $ts);
  }

  private static function determine_indexed_from_states($coverage_state, $verdict){
    $coverage_state = is_string($coverage_state) ? trim(strtolower($coverage_state)) : '';
    if ($coverage_state !== ''){
      if (strpos($coverage_state, 'not indexed') !== false){
        return 0;
      }
      if (strpos($coverage_state, 'indexed') !== false){
        return 1;
      }
      if (strpos($coverage_state, 'noindex') !== false || strpos($coverage_state, '404') !== false || strpos($coverage_state, 'blocked') !== false){
        return 0;
      }
    }
    $verdict = is_string($verdict) ? trim(strtolower($verdict)) : '';
    if ($verdict !== ''){
      if (strpos($verdict, 'pass') !== false){
        return 1;
      }
      if (strpos($verdict, 'fail') !== false){
        return 0;
      }
    }
    return null;
  }

  private static function determine_reason_label($status, $reason){
    $text = $reason ?: $status;
    $text = is_string($text) ? strtolower(trim($text)) : '';
    if ($text === ''){
      return null;
    }
    $normalize = function($value){
      return str_replace(
        ['“','”','’','—','–'],
        ['"','"',"'",'-','-'],
        $value
      );
    };
    $text = $normalize($text);
    if (strpos($text, 'discovered') !== false && strpos($text, 'not indexed') !== false){
      return 'discovered';
    }
    if (strpos($text, 'crawled') !== false && strpos($text, 'not indexed') !== false){
      return 'crawled_not_indexed';
    }
    if (strpos($text, 'duplicate') !== false && strpos($text, 'canonical') !== false){
      return 'duplicate_canonical';
    }
    if (strpos($text, 'alternate') !== false && strpos($text, 'canonical') !== false){
      return 'alternate_canonical';
    }
    if (strpos($text, 'soft 404') !== false){
      return 'soft_404';
    }
    if (strpos($text, 'noindex') !== false){
      return 'noindex';
    }
    if (strpos($text, 'redirect') !== false){
      return 'redirect';
    }
    if (strpos($text, 'blocked') !== false && strpos($text, 'robots') !== false){
      return 'blocked_robots';
    }
    if (strpos($text, 'server error') !== false || strpos($text, '5xx') !== false){
      return 'server_error';
    }
    if (strpos($text, 'not found') !== false || strpos($text, '404') !== false){
      return 'not_found';
    }
    if (strpos($text, 'valid') !== false){
      return 'valid';
    }
    if (strpos($text, 'indexed') !== false && strpos($text, 'not indexed') === false){
      return 'valid';
    }
    return null;
  }

  private static function parse_inspection_payload($data){
    if (!is_array($data)){
      return [];
    }
    $inspection = isset($data['inspectionResult']) && is_array($data['inspectionResult']) ? $data['inspectionResult'] : [];
    $index = isset($inspection['indexStatusResult']) && is_array($inspection['indexStatusResult']) ? $inspection['indexStatusResult'] : [];
    $verdict = $inspection['verdict'] ?? ($index['verdict'] ?? null);
    $coverage = $index['coverageState'] ?? null;
    $last_crawl = $index['lastCrawlTime'] ?? ($index['lastCrawlDate'] ?? null);
    $payload = [
      'verdict'         => $verdict ? (string) $verdict : null,
      'coverage_state'  => $coverage ? (string) $coverage : null,
      'last_crawl_time' => self::normalize_google_timestamp($last_crawl),
      'indexed'         => null,
    ];
    $payload['indexed'] = self::determine_indexed_from_states($payload['coverage_state'], $payload['verdict']);
    $payload['pi_reason_raw'] = $payload['coverage_state'];
    $payload['reason_label'] = self::determine_reason_label($payload['coverage_state'], $payload['coverage_state']);
    return $payload;
  }

  private static function normalize_header_key_from_label($label, $index, array &$used){
    $label = is_string($label) ? $label : '';
    if ($index === 0){
      $label = preg_replace('/^\xEF\xBB\xBF/', '', $label);
    }
    $key = strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim($key, '_');
    if ($key === ''){
      $key = 'col_'.$index;
    }
    $base = $key;
    $suffix = 2;
    while (isset($used[$key])){
      $key = $base.'_'.$suffix;
      $suffix++;
    }
    $used[$key] = true;
    return $key;
  }

  private static function build_header_meta(array $headers){
    $meta = [];
    $used = [];
    foreach ($headers as $idx => $header){
      $label = is_string($header) ? trim($header) : '';
      if ($idx === 0){
        $label = preg_replace('/^\xEF\xBB\xBF/', '', $label);
      }
      $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $label)));
      $key = self::normalize_header_key_from_label($label, $idx, $used);
      $meta[] = [
        'index' => $idx,
        'key'   => $key,
        'label' => $label,
        'normalized_label' => $normalized,
      ];
    }
    return $meta;
  }

  private static function detect_page_indexing_mapping(array $header_meta){
    $mapping = [
      'url'          => null,
      'status'       => null,
      'reason'       => null,
      'last_updated' => null,
    ];
    foreach ($header_meta as $info){
      $norm = $info['normalized_label'];
      if ($mapping['url'] === null && preg_match('/url|address|page|link/', $norm)){
        $mapping['url'] = $info['key'];
        continue;
      }
      if ($mapping['status'] === null && preg_match('/status|state|coverage/', $norm)){
        $mapping['status'] = $info['key'];
      }
      if ($mapping['reason'] === null && preg_match('/reason|issue|detail|problem|type|why/', $norm)){
        $mapping['reason'] = $info['key'];
      }
      if ($mapping['last_updated'] === null && preg_match('/last|crawl|updated|detected|date|seen|timestamp/', $norm)){
        $mapping['last_updated'] = $info['key'];
      }
    }
    if (!$mapping['url']){
      return new WP_Error('mw_audit_pi_missing_url', __('Could not detect the URL column in the Page Indexing export.','merchant-wiki-audit'));
    }
    return $mapping;
  }

  private static function values_to_assoc_rows(array $values){
    if (empty($values) || !is_array($values)){
      return new WP_Error('mw_audit_pi_empty', __('The Page Indexing export appears to be empty.','merchant-wiki-audit'));
    }
    $header_row = array_shift($values);
    if (!$header_row || !is_array($header_row)){
      return new WP_Error('mw_audit_pi_header', __('The Page Indexing export is missing a header row.','merchant-wiki-audit'));
    }
    $header_meta = self::build_header_meta($header_row);
    $mapping = self::detect_page_indexing_mapping($header_meta);
    if (is_wp_error($mapping)){
      return $mapping;
    }
    $rows = [];
    foreach ($values as $row){
      if (!is_array($row)){
        continue;
      }
      $assoc = [];
      foreach ($header_meta as $info){
        $value = isset($row[$info['index']]) ? $row[$info['index']] : '';
        if (is_array($value)){
          $value = implode(' ', array_map('trim', $value));
        }
        $assoc[$info['key']] = is_string($value) ? trim($value) : trim((string) $value);
      }
      $rows[] = $assoc;
    }
    return [
      'rows'    => $rows,
      'mapping' => $mapping,
      'headers' => $header_meta,
    ];
  }

  private static function read_csv_rows($file_path, $delimiter = ','){
    $rows = [];
    $handle = fopen($file_path, 'r');
    if (!$handle){
      return $rows;
    }
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false){
      $rows[] = $data;
    }
    fclose($handle);
    return $rows;
  }

  private static function csv_is_single_column(array $rows){
    foreach ($rows as $row){
      if (is_array($row) && count($row) > 1){
        return false;
      }
    }
    return true;
  }

  public static function parse_page_indexing_csv($file_path){
    if (!file_exists($file_path) || !is_readable($file_path)){
      return new WP_Error('mw_audit_pi_file', __('Unable to read the uploaded CSV file.','merchant-wiki-audit'));
    }
    $rows = self::read_csv_rows($file_path, ',');
    if (!$rows){
      return new WP_Error('mw_audit_pi_empty', __('The CSV file appears to be empty.','merchant-wiki-audit'));
    }
    if (self::csv_is_single_column($rows)){
      $alt = self::read_csv_rows($file_path, ';');
      if ($alt && !self::csv_is_single_column($alt)){
        $rows = $alt;
      }
    }
    return self::values_to_assoc_rows($rows);
  }

  public static function parse_page_indexing_metadata_csv($file_path){
    if (!file_exists($file_path) || !is_readable($file_path)){
      return new WP_Error('mw_audit_pi_file', __('Unable to read the uploaded CSV file.','merchant-wiki-audit'));
    }
    $rows = self::read_csv_rows($file_path, ',');
    if (!$rows){
      return new WP_Error('mw_audit_pi_empty', __('The Metadata CSV appears to be empty.','merchant-wiki-audit'));
    }
    $meta = self::metadata_rows_to_assoc($rows);
    if (!$meta){
      return new WP_Error('mw_audit_pi_empty', __('Unable to detect metadata entries in the CSV.','merchant-wiki-audit'));
    }
    return [
      'meta' => $meta,
      'reason' => self::find_metadata_value($meta, ['reason','issue','type','why']),
      'exported_at' => self::determine_exported_at($meta),
      'report' => $meta['report'] ?? '',
    ];
  }

  public static function parse_page_indexing_values(array $values){
    return self::values_to_assoc_rows($values);
  }

  public static function import_page_indexing_records(array $rows, array $mapping, $allow_override = false, array $meta = []){
    $imported = 0;
    $skipped = 0;
    $now = self::mysql_now();
    $ttl_hours = self::get_export_ttl_hours();
    $meta_reason = '';
    $meta_exported_at = '';
    $meta_report = '';
    if (!empty($meta['reason'])){
      $meta_reason = trim((string) $meta['reason']);
    }
    if (!empty($meta['exported_at'])){
      $meta_exported_at = trim((string) $meta['exported_at']);
    }
    if (!empty($meta['report'])){
      $meta_report = trim((string) $meta['report']);
    }
    foreach ($rows as $row){
      $url_key = $mapping['url'];
      $url = isset($row[$url_key]) ? trim($row[$url_key]) : '';
      if ($url === ''){
        $skipped++;
        continue;
      }
      $status_key = $mapping['status'] ?? null;
      $reason_key = $mapping['reason'] ?? null;
      $updated_key = $mapping['last_updated'] ?? null;

      $status_val = $status_key ? (isset($row[$status_key]) ? trim($row[$status_key]) : '') : '';
      if ($status_val === '' && $meta_report !== ''){
        $status_val = $meta_report;
      }
      $reason_val = $reason_key ? (isset($row[$reason_key]) ? trim($row[$reason_key]) : '') : '';
      if ($reason_val === '' && $meta_reason !== ''){
        $reason_val = $meta_reason;
      }
      $coverage_state = $reason_val ?: $status_val;
      if ($status_val && $reason_val && stripos($status_val, $reason_val) === false){
        $coverage_state = trim($status_val.' — '.$reason_val);
      } elseif (!$coverage_state && $meta_reason){
        $coverage_state = $meta_reason;
      }

      $last_updated = $updated_key ? (isset($row[$updated_key]) ? trim($row[$updated_key]) : '') : '';
      $inspected_at = $last_updated ? self::normalize_google_timestamp($last_updated) : null;
      if (!$inspected_at){
        $inspected_at = $now;
      }
      $reason_label = self::determine_reason_label($status_val, $reason_val);
      $payload = [
        'coverage_state' => $coverage_state ?: null,
        'reason_label'   => $reason_label ?: null,
        'pi_reason_raw'  => $reason_val ?: ($coverage_state ?: null),
        'inspected_at'   => $inspected_at,
        'ttl_until'      => self::mysql_future_hours($ttl_hours),
        'attempts'       => 0,
        'last_error'     => null,
        'payload'        => [
          'status'       => $status_val,
          'reason'       => $reason_val,
          'last_crawled' => $last_updated,
          'exported_at'  => $meta_exported_at,
          'report'       => $meta_report,
        ],
      ];
      self::upsert_cache_row($url, $payload, 'page_indexing');
      $imported++;

      if ($coverage_state){
        $inspection = self::get_cache_row($url, 'inspection');
        if ($inspection){
          if (empty($inspection['coverage_state']) || $allow_override){
            $fields = ['coverage_state' => $coverage_state];
            if ($reason_label){
              $fields['reason_label'] = $reason_label;
            }
            self::update_inspection_fields($url, $fields);
          }
        }
      }
    }
    return ['imported'=>$imported,'skipped'=>$skipped];
  }

  public static function normalize_sheet_reference($input, $range = ''){
    $input = is_string($input) ? trim($input) : '';
    $range = is_string($range) ? trim($range) : '';
    $sheet_id = '';
    if (preg_match('~spreadsheets/d/([a-zA-Z0-9-_]+)~', $input, $m)){
      $sheet_id = $m[1];
    } else {
      $sheet_id = $input;
    }
    $sheet_id = trim($sheet_id);
    if ($sheet_id === ''){
      return ['', ''];
    }
    if ($range === ''){
      $range = 'A:Z';
    }
    return [$sheet_id, $range];
  }

  private static function fetch_sheet_values_with_fallback($sheet_id, array $ranges){
    $last_error = null;
    foreach ($ranges as $range){
      $values = self::fetch_sheet_values($sheet_id, $range);
      if (!is_wp_error($values)){
        return $values;
      }
      $last_error = $values;
    }
    return $last_error ?: new WP_Error('mw_audit_gsc_sheet_range', __('Unable to read the requested Google Sheet range.','merchant-wiki-audit'));
  }

  private static function metadata_rows_to_assoc(array $values){
    $meta = [];
    foreach ($values as $row){
      if (!is_array($row) || empty($row)) continue;
      $key = isset($row[0]) ? sanitize_text_field($row[0]) : '';
      $val = isset($row[1]) ? sanitize_text_field($row[1]) : '';
      $key = strtolower(trim($key));
      if ($key === '') continue;
      $meta[$key] = $val;
    }
    return $meta;
  }

  private static function find_metadata_value(array $meta, array $needles){
    foreach ($meta as $key => $value){
      foreach ($needles as $needle){
        if (strpos($key, $needle) !== false){
          return $value;
        }
      }
    }
    return '';
  }

  private static function slugify_source_label($label){
    if (!function_exists('sanitize_title')){
      return 'gsc_export_'.md5($label);
    }
    $slug = sanitize_title($label ? $label : 'unknown');
    if ($slug === ''){
      $slug = 'unknown';
    }
    return 'gsc_export_'.$slug;
  }

  private static function determine_exported_at(array $meta){
    $value = self::find_metadata_value($meta, ['last updated', 'generated', 'exported']);
    if ($value){
      $ts = strtotime($value);
      if ($ts){
        if (function_exists('wp_date')){
          return wp_date('Y-m-d H:i:s', $ts);
        }
        return date_i18n('Y-m-d H:i:s', $ts);
      }
    }
    return self::mysql_now();
  }

  private static function create_result_sheet(array $rows){
    if (!$rows){
      return new WP_Error('mw_audit_pi_empty', __('No rows detected in the provided Google Sheets.','merchant-wiki-audit'));
    }
    $token = self::get_access_token();
    if (!$token){
      return new WP_Error('mw_audit_gsc_sheet_token', __('Unable to obtain Google access token for Sheets API.','merchant-wiki-audit'));
    }
    $title = sprintf('MW Audit Page Indexing (%s)', function_exists('wp_date') ? wp_date('Y-m-d H:i') : date_i18n('Y-m-d H:i'));
    $create = wp_remote_post(self::SHEETS_BASE_ENDPOINT, [
      'timeout' => 30,
      'headers' => [
        'Authorization' => 'Bearer '.$token,
        'Content-Type'  => 'application/json',
      ],
      'body'    => wp_json_encode(['properties'=>['title'=>$title]]),
    ]);
    if (is_wp_error($create)){
      return $create;
    }
    $code = (int) wp_remote_retrieve_response_code($create);
    $body = json_decode(wp_remote_retrieve_body($create), true);
    if ($code !== 200 || empty($body['spreadsheetId'])){
      return new WP_Error('mw_audit_sheet_create', __('Failed to create a Google Sheet for the combined export.','merchant-wiki-audit'));
    }
    $sheet_id = $body['spreadsheetId'];
    $sheet_name = isset($body['sheets'][0]['properties']['title']) ? $body['sheets'][0]['properties']['title'] : 'Sheet1';
    $update = wp_remote_post(sprintf('%s/%s/values:batchUpdate', self::SHEETS_BASE_ENDPOINT, rawurlencode($sheet_id)), [
      'timeout' => 30,
      'headers' => [
        'Authorization' => 'Bearer '.$token,
        'Content-Type'  => 'application/json',
      ],
      'body' => wp_json_encode([
        'valueInputOption' => 'RAW',
        'data' => [[
          'range' => sprintf('%s!A1', $sheet_name),
          'majorDimension' => 'ROWS',
          'values' => $rows,
        ]],
      ]),
    ]);
    if (is_wp_error($update)){
      return $update;
    }
    if ((int) wp_remote_retrieve_response_code($update) !== 200){
      return new WP_Error('mw_audit_sheet_write', __('Failed to populate the combined Google Sheet.','merchant-wiki-audit'));
    }
    return [
      'sheet_id'  => $sheet_id,
      'sheet_url' => sprintf('https://docs.google.com/spreadsheets/d/%s/edit', $sheet_id),
    ];
  }

  public static function assemble_page_indexing_sources(array $sources){
    $header = ['url','verdict','coverage_state','last_crawled','exported_at','source'];
    $rows = [$header];
    $total_rows = 0;
    foreach ($sources as $source){
      list($sheet_id,) = self::normalize_sheet_reference($source, '');
      if ($sheet_id === ''){
        return new WP_Error('mw_audit_sheet_id', __('One of the provided Google Sheet links is empty or invalid.','merchant-wiki-audit'));
      }
      $meta_values = self::fetch_sheet_values_with_fallback($sheet_id, ['Metadata!A:B','metadata!A:B','METADATA!A:B']);
      if (is_wp_error($meta_values)){
        return $meta_values;
      }
      $meta = self::metadata_rows_to_assoc($meta_values);
      $reason = self::find_metadata_value($meta, ['reason','why','issue','type']);
      $exported_at = self::determine_exported_at($meta);
      $source_tag = self::slugify_source_label($reason ?: ($meta['report'] ?? 'page_indexing'));

      $table_values = self::fetch_sheet_values_with_fallback($sheet_id, ['Table!A:Z','table!A:Z','TABLE!A:Z']);
      if (is_wp_error($table_values)){
        return $table_values;
      }
      $parsed = self::parse_page_indexing_values($table_values);
      if (is_wp_error($parsed)){
        return $parsed;
      }
      foreach ($parsed['rows'] as $row){
        $url = $row[$parsed['mapping']['url']] ?? '';
        if ($url === ''){
          continue;
        }
        $verdict = '';
        if (!empty($parsed['mapping']['status'])){
          $verdict = $row[$parsed['mapping']['status']] ?? '';
        }
        if ($verdict === ''){
          $verdict = $reason ? __('Not indexed','merchant-wiki-audit') : __('Indexed','merchant-wiki-audit');
        }
        $coverage_state = '';
        if (!empty($parsed['mapping']['reason'])){
          $coverage_state = $row[$parsed['mapping']['reason']] ?? '';
        }
        if ($coverage_state === ''){
          $coverage_state = $reason ?: '';
        }
        $last_crawled = '';
        if (!empty($parsed['mapping']['last_updated'])){
          $last_crawled = $row[$parsed['mapping']['last_updated']] ?? '';
        }
        $rows[] = [$url, $verdict, $coverage_state, $last_crawled, $exported_at, $source_tag];
        $total_rows++;
      }
    }
    if ($total_rows === 0){
      return new WP_Error('mw_audit_pi_empty', __('No rows detected in the provided Google Sheets.','merchant-wiki-audit'));
    }
    $sheet = self::create_result_sheet($rows);
    if (is_wp_error($sheet)){
      return $sheet;
    }
    $sheet['rows'] = $total_rows;
    $sheet['sources'] = count($sources);
    return $sheet;
  }

  public static function fetch_sheet_values($spreadsheet_id, $range = null){
    if (!self::is_connected() || !self::has_sheets_scope()){
      return new WP_Error('mw_audit_gsc_sheets_scope', __('Google Sheets access is not connected.','merchant-wiki-audit'));
    }
    $token = self::get_access_token();
    if (!$token){
      return new WP_Error('mw_audit_gsc_sheet_token', __('Unable to obtain Google access token for Sheets API.','merchant-wiki-audit'));
    }
    $range = $range ?: 'A:Z';
    $url = sprintf('%s/%s/values/%s', self::SHEETS_BASE_ENDPOINT, rawurlencode($spreadsheet_id), rawurlencode($range));
    $response = wp_remote_get($url, [
      'timeout' => 30,
      'headers' => [
        'Authorization' => 'Bearer '.$token,
      ],
    ]);
    if (is_wp_error($response)){
      return $response;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code !== 200){
      $message = isset($data['error']['message']) ? $data['error']['message'] : __('Failed to fetch Google Sheet data.','merchant-wiki-audit');
      return new WP_Error('mw_audit_gsc_sheet_http', $message);
    }
    if (empty($data['values']) || !is_array($data['values'])){
      return new WP_Error('mw_audit_gsc_sheet_empty', __('The Google Sheet did not return any values.','merchant-wiki-audit'));
    }
    return $data['values'];
  }

  public static function import_page_indexing_from_values(array $values, $allow_override = false, array $meta = []){
    $parsed = self::parse_page_indexing_values($values);
    if (is_wp_error($parsed)){
      return $parsed;
    }
    return self::import_page_indexing_records($parsed['rows'], $parsed['mapping'], $allow_override, $meta);
  }

  public static function get_client_id(){
    return trim((string) self::get_option('client_id', ''));
  }

  public static function get_client_secret(){
    return trim((string) self::get_option('client_secret', ''));
  }

  public static function save_credentials($client_id, $client_secret){
    self::update_option('client_id', sanitize_text_field($client_id));
    self::update_option('client_secret', sanitize_text_field($client_secret));
  }

  public static function get_property(){
    return trim((string) self::get_option('property', ''));
  }

  public static function save_property($property){
    self::update_option('property', esc_url_raw($property));
    self::clear_index_cache();
  }

  public static function get_redirect_uri(){
    return admin_url('admin-post.php?action=mw_audit_gsc_callback');
  }

  public static function is_configured(){
    return (self::get_client_id() !== '' && self::get_client_secret() !== '');
  }

  public static function is_connected(){
    return (self::is_configured() && self::get_refresh_token() !== '');
  }

  public static function get_refresh_token(){
    return trim((string) self::get_option('refresh_token', ''));
  }

  private static function save_tokens($access_token, $expires_in, $refresh_token = ''){
    if ($access_token){
      self::update_option('access_token', $access_token);
      $expiry = time() + max(0, (int)$expires_in) - 60;
      self::update_option('access_token_expires', $expiry);
    }
    if ($refresh_token){
      self::update_option('refresh_token', $refresh_token);
    }
  }

  private static function get_stored_access_token(){
    $token = self::get_option('access_token', '');
    $expires = (int) self::get_option('access_token_expires', 0);
    if ($token && $expires > time()){
      return $token;
    }
    return '';
  }

  public static function get_access_token($force_refresh = false){
    if (!$force_refresh){
      $token = self::get_stored_access_token();
      if ($token){
        return $token;
      }
    }
    $refresh = self::get_refresh_token();
    if (!$refresh){
      return '';
    }
    $body = [
      'client_id'     => self::get_client_id(),
      'client_secret' => self::get_client_secret(),
      'refresh_token' => $refresh,
      'grant_type'    => 'refresh_token',
    ];
    $response = wp_remote_post(self::TOKEN_ENDPOINT, [
      'timeout' => 20,
      'body'    => $body,
    ]);
    if (is_wp_error($response)){
      MW_Audit_DB::log('GSC refresh error: '.$response->get_error_message());
      return '';
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code !== 200 || !is_array($data) || empty($data['access_token'])){
      MW_Audit_DB::log('GSC refresh error: '.wp_remote_retrieve_body($response));
      return '';
    }
    self::save_tokens($data['access_token'], $data['expires_in'] ?? 3600);
    return $data['access_token'];
  }

  public static function get_auth_url($mode = 'default'){
    if (!self::is_configured()) return '';
    $mode = $mode ? sanitize_key($mode) : 'default';
    $include_sheets = ($mode === 'sheets');
    $scopes = self::get_scopes_for_auth($include_sheets);
    $params = [
      'response_type' => 'code',
      'client_id'     => self::get_client_id(),
      'redirect_uri'  => self::get_redirect_uri(),
      'scope'         => implode(' ', $scopes),
      'access_type'   => 'offline',
      'prompt'        => 'consent',
      'state'         => $mode,
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params, '', '&');
  }

  public static function get_sheets_auth_url(){
    return self::get_auth_url('sheets');
  }

  public static function handle_oauth_callback(){
    if (!self::is_configured()){
      wp_die(__('Google Search Console credentials are not configured.','merchant-wiki-audit'));
    }
    $state = isset($_GET['state']) ? sanitize_key(wp_unslash($_GET['state'])) : 'default';
    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
    if (!$code){
      wp_die(__('Missing authorization code.','merchant-wiki-audit'));
    }
    $body = [
      'code'          => $code,
      'client_id'     => self::get_client_id(),
      'client_secret' => self::get_client_secret(),
      'redirect_uri'  => self::get_redirect_uri(),
      'grant_type'    => 'authorization_code',
    ];
    $response = wp_remote_post(self::TOKEN_ENDPOINT, [
      'timeout' => 20,
      'body'    => $body,
    ]);
    if (is_wp_error($response)){
      wp_die(__('Failed to exchange authorization code: ','merchant-wiki-audit').$response->get_error_message());
    }
    $code_http = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code_http !== 200 || empty($data['access_token'])){
      wp_die(__('Failed to obtain access token.','merchant-wiki-audit'));
    }
    $refresh = isset($data['refresh_token']) ? $data['refresh_token'] : '';
    if (!$refresh){
      $refresh = self::get_refresh_token();
      if (!$refresh){
        wp_die(__('Google did not return a refresh token. Ensure you allow offline access.','merchant-wiki-audit'));
      }
    }
    self::save_tokens($data['access_token'], $data['expires_in'] ?? 3600, $refresh);
    if (!empty($data['scope'])){
      self::save_scopes(self::normalize_scope_list($data['scope']));
    } elseif ($state === 'sheets'){
      self::save_scopes(self::get_scopes_for_auth(true));
    }
    self::fetch_and_store_userinfo($data['access_token']);
  }

  private static function fetch_and_store_userinfo($access_token){
    $response = wp_remote_get(self::USERINFO_ENDPOINT, [
      'timeout' => 15,
      'headers' => [
        'Authorization' => 'Bearer '.$access_token,
      ],
    ]);
    if (is_wp_error($response)){
      return;
    }
    if ((int) wp_remote_retrieve_response_code($response) !== 200){
      return;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (is_array($data) && !empty($data['email'])){
      self::update_option('account_email', sanitize_email($data['email']));
    }
  }

  public static function disconnect(){
    foreach (['refresh_token','access_token','access_token_expires','account_email','sites_cache', self::OPTION_SCOPES] as $key){
      self::delete_option($key);
    }
    self::update_option('property', '');
    self::clear_index_cache();
  }

  public static function get_account_email(){
    return sanitize_email(self::get_option('account_email', ''));
  }

  public static function get_sites($force = false){
    if (!self::is_connected()) return [];
    $cache = self::get_option('sites_cache', []);
    if (!$force && is_array($cache) && isset($cache['time'], $cache['sites']) && (time() - (int)$cache['time']) < DAY_IN_SECONDS){
      return (array) $cache['sites'];
    }
    $token = self::get_access_token();
    if (!$token){
      return [];
    }
    $response = wp_remote_get(self::SITES_ENDPOINT, [
      'timeout' => 20,
      'headers' => ['Authorization' => 'Bearer '.$token],
    ]);
    if (is_wp_error($response)){
      MW_Audit_DB::log('GSC sites error: '.$response->get_error_message());
      return [];
    }
    if ((int) wp_remote_retrieve_response_code($response) !== 200){
      MW_Audit_DB::log('GSC sites error: '.wp_remote_retrieve_body($response));
      return [];
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $sites = [];
    if (isset($data['siteEntry']) && is_array($data['siteEntry'])){
      foreach ($data['siteEntry'] as $entry){
        if (!empty($entry['siteUrl'])){
          $sites[] = $entry['siteUrl'];
        }
      }
    }
    self::update_option('sites_cache', ['time'=>time(),'sites'=>$sites]);
    return $sites;
  }

  public static function inspect_urls(array $urls, $force = false){
    $results = [];
    $errors = [];
    $meta = [
      'skipped'    => 0,
      'api_calls'  => 0,
      'cached'     => 0,
      'api_errors' => 0,
    ];
    if (!self::is_connected()){
      foreach ($urls as $url){
        $results[$url] = ['indexed'=>null,'error'=>__('Google Search Console is not connected.','merchant-wiki-audit')];
      }
      $errors[] = __('Google Search Console is not connected.','merchant-wiki-audit');
      return ['results'=>$results,'errors'=>$errors,'meta'=>$meta];
    }
    $property = self::get_property();
    if (!$property){
      foreach ($urls as $url){
        $results[$url] = ['indexed'=>null,'error'=>__('Google property is not configured.','merchant-wiki-audit')];
      }
      $errors[] = __('Google property is not configured.','merchant-wiki-audit');
      return ['results'=>$results,'errors'=>$errors,'meta'=>$meta];
    }
    $token = self::get_access_token();
    if (!$token){
      foreach ($urls as $url){
        $results[$url] = ['indexed'=>null,'error'=>__('Unable to obtain Google access token.','merchant-wiki-audit')];
      }
      $errors[] = __('Unable to obtain Google access token.','merchant-wiki-audit');
      return ['results'=>$results,'errors'=>$errors,'meta'=>$meta];
    }
    $cache_map = self::get_cache_rows($urls, 'inspection');
    $now = time();
    $ttl_hours = self::get_api_ttl_hours();

    foreach ($urls as $url){
      $norm = self::normalize_cache_url($url);
      if ($norm === ''){
        $message = sprintf(__('Invalid URL provided: %s','merchant-wiki-audit'), esc_html($url));
        $results[$url] = ['indexed'=>null,'error'=>$message];
        $errors[] = $message;
        $meta['api_errors']++;
        continue;
      }
      $cache = isset($cache_map[$norm]) ? $cache_map[$norm] : null;
      $fresh = false;
      if (!$force && $cache && !empty($cache['ttl_until'])){
        $ttl_ts = strtotime($cache['ttl_until']);
        if ($ttl_ts && $ttl_ts > $now){
          $fresh = true;
        }
      }
      if ($fresh){
        $results[$url] = [
          'indexed'        => self::determine_indexed_from_states($cache['coverage_state'] ?? '', $cache['verdict'] ?? ''),
          'verdict'        => $cache['verdict'] ?? null,
          'coverage_state' => $cache['coverage_state'] ?? null,
          'last_crawl_time'=> $cache['last_crawl_time'] ?? null,
          'inspected_at'   => $cache['inspected_at'] ?? null,
          'ttl_until'      => $cache['ttl_until'] ?? null,
          'attempts'       => isset($cache['attempts']) ? (int)$cache['attempts'] : 0,
          'last_error'     => $cache['last_error'] ?? null,
          'from_cache'     => true,
          'skipped'        => true,
          'source'         => 'inspection',
        ];
        $meta['skipped']++;
        $meta['cached']++;
        continue;
      }

      $payload = [
        'inspectionUrl' => $url,
        'siteUrl'       => $property,
      ];
      $response = wp_remote_post(self::INSPECT_ENDPOINT, [
        'timeout' => 30,
        'headers' => [
          'Authorization' => 'Bearer '.$token,
          'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($payload),
      ]);
      $meta['api_calls']++;
      if (is_wp_error($response)){
        $message = sprintf(__('Error inspecting %s: %s','merchant-wiki-audit'), $url, $response->get_error_message());
        $results[$url] = ['indexed'=>null,'error'=>$message,'source'=>'inspection'];
        $errors[] = $message;
        self::mark_inspection_error($url, $response->get_error_message());
        $meta['api_errors']++;
        continue;
      }
      $code = (int) wp_remote_retrieve_response_code($response);
      $data = json_decode(wp_remote_retrieve_body($response), true);
      if ($code !== 200){
        $message = isset($data['error']['message']) ? $data['error']['message'] : wp_remote_retrieve_body($response);
        $results[$url] = ['indexed'=>null,'error'=>$message,'source'=>'inspection'];
        $errors[] = sprintf(__('Error inspecting %s: %s','merchant-wiki-audit'), $url, $message);
        if ($code === 401 || $code === 403){
          self::delete_option('access_token');
        }
        self::mark_inspection_error($url, $message);
        $meta['api_errors']++;
        continue;
      }
      $parsed = self::parse_inspection_payload($data);
      $attempts = isset($cache['attempts']) ? (int)$cache['attempts'] + 1 : 1;
      $inspected_at = self::mysql_now();
      $ttl_until = self::mysql_future_hours($ttl_hours);
      $parsed['attempts'] = $attempts;
      $parsed['inspected_at'] = $inspected_at;
      $parsed['ttl_until'] = $ttl_until;
      self::mark_inspection_success($url, $parsed);
      $results[$url] = array_merge($parsed, [
        'from_cache' => false,
        'skipped'    => false,
        'source'     => 'inspection',
        'last_error' => null,
      ]);
    }

    return ['results'=>$results,'errors'=>$errors,'meta'=>$meta];
  }

  public static function connection_info(){
    return [
      'configured' => self::is_configured(),
      'connected'  => self::is_connected(),
      'email'      => self::get_account_email(),
      'property'   => self::get_property(),
    ];
  }

  public static function clear_index_cache(){
    global $wpdb;
    $like = '_transient_'.self::CACHE_PREFIX.'%';
    $timeout_like = '_transient_timeout_'.self::CACHE_PREFIX.'%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like));
    if (method_exists('MW_Audit_DB','t_gsc_cache')){
      $cache_table = MW_Audit_DB::t_gsc_cache();
      $wpdb->query("TRUNCATE TABLE $cache_table");
    }
  }
}
