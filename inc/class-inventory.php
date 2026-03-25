<?php
if (!defined('ABSPATH')) exit;

class MW_Audit_Inventory {
  const Q_STATUS = 'mw_status_queue';
  const Q_PC     = 'mw_pc_queue';
  const Q_HTTP   = 'mw_http_queue';
  const Q_LINKS  = 'mw_links_queue';
  const Q_OUTBOUND = 'mw_outbound_queue';
  const Q_INV    = 'mw_inventory_queue';
  const Q_GSC    = 'mw_gsc_queue';

  const LOCK_TTL_MIN = 30;

  private static function lock_key($process){
    return 'mw_audit_lock_' . sanitize_key($process);
  }

  private static function acquire_lock($process){
    $key = self::lock_key($process);
    if (get_transient($key)){
      return false;
    }
    set_transient($key, time(), self::LOCK_TTL_MIN * MINUTE_IN_SECONDS);
    return true;
  }

  private static function touch_lock($process){
    set_transient(self::lock_key($process), time(), self::LOCK_TTL_MIN * MINUTE_IN_SECONDS);
  }

  private static function release_lock($process){
    delete_transient(self::lock_key($process));
  }

  private static function is_locked($process){
    return (bool) get_transient(self::lock_key($process));
  }

  private static function ensure_manage_capability(){
    if (!current_user_can('manage_options')){
      wp_die(esc_html__('Not allowed','merchant-wiki-audit'));
    }
  }

  private static function safe_redirect($url){
    wp_safe_redirect($url);
    exit;
  }

  private static function delete_file_if_exists($path){
    if (!$path){
      return;
    }
    wp_delete_file($path);
  }

  private static function verify_post_nonce(){
    $post_data = [];
    if (!empty($_POST)){
      $post_data = map_deep(wp_unslash($_POST), 'sanitize_text_field');
    }
    if (empty($post_data['mw_audit_nonce'])){
      return;
    }
    check_admin_referer('mw_audit_action', 'mw_audit_nonce');
    static $checked = false;
    if ($checked){
      return;
    }
    $checked = true;
    $request_nonce = isset($post_data['mw_audit_request_nonce']) ? sanitize_key($post_data['mw_audit_request_nonce']) : '';
    if ($request_nonce !== '' && !wp_verify_nonce($request_nonce, 'mw_audit_request')){
      wp_die(esc_html__('Security check failed. Please refresh the page and try again.','merchant-wiki-audit'));
    }
  }

  private static function verify_request_nonce(){
    $post_data = [];
    if (!empty($_POST)){
      $post_data = map_deep(wp_unslash($_POST), 'sanitize_text_field');
    }
    static $checked = false;
    if ($checked){
      return;
    }
    $checked = true;
    $request_nonce = isset($post_data['mw_audit_request_nonce']) ? sanitize_key($post_data['mw_audit_request_nonce']) : '';
    if ($request_nonce !== '' && !wp_verify_nonce($request_nonce, 'mw_audit_request')){
      wp_die(esc_html__('Security check failed. Please refresh the page and try again.','merchant-wiki-audit'));
    }
  }

  private static function sanitize_input_value($value){
    if (is_array($value)){
      return array_map([self::class, 'sanitize_input_value'], $value);
    }
    return sanitize_text_field((string) $value);
  }

  private static function get_post_value($key, $default = null){
    $post_data = [];
    if (!empty($_POST)){
      $post_data = map_deep(wp_unslash($_POST), 'sanitize_text_field');
    }
    if (empty($post_data['mw_audit_nonce'])){
      return $default;
    }
    check_admin_referer('mw_audit_action', 'mw_audit_nonce');
    $nonce_value = sanitize_key($post_data['mw_audit_nonce']);
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'mw_audit_action')){
      return $default;
    }
    self::verify_post_nonce();
    if (array_key_exists($key, $post_data)){
      return $post_data[$key];
    }
    return $default;
  }

  private static function get_query_value($key, $default = null){
    $nonce_guard = isset($_POST['mw_audit_nonce']) ? sanitize_key(wp_unslash($_POST['mw_audit_nonce'])) : '';
    if (!$nonce_guard || !wp_verify_nonce($nonce_guard, 'mw_audit_action')){
      return $default;
    }
    $query_data = [];
    if (!empty($_GET)){
      $query_data = map_deep(wp_unslash($_GET), 'sanitize_text_field'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }
    if (array_key_exists($key, $query_data)){
      return $query_data[$key];
    }
    return $default;
  }

  private static function get_request_value($key, $default = null){
    $post_data = [];
    if (!empty($_POST)){
      $post_data = map_deep(wp_unslash($_POST), 'sanitize_text_field');
    }
    $query_data = [];
    if (!empty($_GET)){
      $query_data = map_deep(wp_unslash($_GET), 'sanitize_text_field');
    }
    $nonce_value = !empty($post_data['mw_audit_nonce']) ? sanitize_key($post_data['mw_audit_nonce']) : '';
    if ($nonce_value && wp_verify_nonce($nonce_value, 'mw_audit_action')){
      self::verify_request_nonce();
      if (array_key_exists($key, $post_data)){
        return $post_data[$key];
      }
    }
    if (array_key_exists($key, $query_data)){
      return $query_data[$key];
    }
    return $default;
  }

  private static function get_request_flag($key){
    $value = self::get_request_value($key);
    if ($value === null){
      return false;
    }
    if (is_array($value)){
      return !empty($value);
    }
    $value = strtolower(trim((string) $value));
    return in_array($value, ['1','true','yes','on'], true);
  }

  private static function read_int_param($key, $default, $min, $max){
    $options = ['options' => ['min_range' => (int) $min, 'max_range' => (int) $max]];
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT, $options);
    if ($value === false || $value === null){
      $raw = self::get_post_value($key);
      if (is_string($raw) || is_numeric($raw)){
        $raw = is_string($raw) ? trim((string) $raw) : $raw;
        if (is_numeric($raw)){
          $value = (int) $raw;
        }
      }
    }
    if (!is_int($value)){
      $value = (int) $default;
    }
    $value = max($min, $value);
    $value = min($max, $value);
    return $value;
  }

  private static function read_float_param($key, $default, $min, $max){
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_FLOAT);
    if ($value === false || $value === null){
      $raw = self::get_post_value($key);
      if (is_string($raw) || is_numeric($raw)){
        $raw = is_string($raw) ? trim(str_replace(',', '.', (string) $raw)) : $raw;
        if (is_numeric($raw)){
          $value = (float) $raw;
        }
      }
    }
    if (!is_float($value) && !is_int($value)){
      $value = (float) $default;
    }
    $value = (float) $value;
    $value = max($min, $value);
    $value = min($max, $value);
    return $value;
  }

  private static function read_bool_param($key, $default = false){
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($value === null){
      $raw = self::get_post_value($key);
      if (is_string($raw) || is_numeric($raw)){
        $raw = strtolower(trim((string) $raw));
        $value = in_array($raw, ['1','true','yes','on'], true);
      } elseif (is_bool($raw)){
        $value = $raw;
      } elseif (is_array($raw)){
        $value = !empty($raw);
      } else {
        $value = $default;
      }
    }
    return (bool) $value;
  }

  private static function extend_time_limit($seconds = 0){
    // Intentionally no operation in production.
  }

  private static function read_raw_post_string($key){
    $value = self::get_post_value($key, '');
    return is_string($value) ? $value : '';
  }

  private static function read_priority_threshold($key = 'threshold'){
    $raw = self::get_request_value($key, 0);
    if (is_array($raw)){
      $raw = reset($raw);
    }
    if (is_string($raw) || is_numeric($raw)){
      $raw = trim((string) $raw);
    } else {
      $raw = 0;
    }
    return MW_Audit_DB::normalize_priority_threshold($raw);
  }

  public static function get_status_filters_from_request(){
    $filters = [
      'only_likely' => self::get_request_flag('mw_filter_likely'),
      'stale'       => self::get_request_flag('mw_filter_stale'),
      'never'       => self::get_request_flag('mw_filter_never'),
      'new_hours'   => 0,
      'likely_states' => class_exists('MW_Audit_GSC') ? MW_Audit_GSC::get_likely_not_indexed_reasons() : [],
    ];
    $new_hours_raw = self::get_request_value('mw_filter_new');
    if ($new_hours_raw !== null && $new_hours_raw !== ''){
      $filters['new_hours'] = (int) $new_hours_raw;
    }
    if ($filters['new_hours'] < 0){
      $filters['new_hours'] = 0;
    }
    return $filters;
  }

  private static function sanitize_similar_request($raw){
    if (!is_array($raw)){
      return new WP_Error('mw_audit_similar_invalid', __('Invalid request payload. Reload the page and try again.','merchant-wiki-audit'));
    }
    $limit = isset($raw['limit']) ? (int) $raw['limit'] : 25;
    $limit = max(5, min(100, $limit));
    $offset = isset($raw['offset']) ? max(0, (int) $raw['offset']) : 0;
    $summary = [];
    $query = [];

    if (!empty($raw['base_url'])){
      $base_url = esc_url_raw(trim(wp_unslash($raw['base_url'])));
      if ($base_url !== ''){
        $query['base_url'] = $base_url;
      }
    }

    if (!empty($raw['http_status']['enabled'])){
      $value = isset($raw['http_status']['value']) ? (int) $raw['http_status']['value'] : 200;
      if ($value > 0){
        $query['http_status'] = $value;
      $summary[] = sprintf(
        /* translators: %d: HTTP status code value. */
        __('HTTP status = %d','merchant-wiki-audit'),
        $value
      );
      }
    }
    if (!empty($raw['in_sitemap']['enabled'])){
      $value = !empty($raw['in_sitemap']['value']) ? 1 : 0;
      $query['in_sitemap'] = $value ? 1 : 0;
      $summary[] = $value ? __('In sitemap = yes','merchant-wiki-audit') : __('In sitemap = no','merchant-wiki-audit');
    }
    if (!empty($raw['noindex']['enabled'])){
      $value = !empty($raw['noindex']['value']) ? 1 : 0;
      $query['noindex'] = $value ? 1 : 0;
      $summary[] = $value ? __('Noindex flag = yes','merchant-wiki-audit') : __('Noindex flag = no','merchant-wiki-audit');
    }
    if (!empty($raw['indexed']['enabled'])){
      $value = isset($raw['indexed']['value']) ? trim((string) wp_unslash($raw['indexed']['value'])) : '';
      if ($value === '1' || $value === '0'){
        $query['indexed_in_google'] = (int) $value;
        $summary[] = $value === '1'
          ? __('Indexed in Google = yes','merchant-wiki-audit')
          : __('Indexed in Google = no','merchant-wiki-audit');
      }
    }
    if (!empty($raw['pc_path']['enabled'])){
      $path = isset($raw['pc_path']['value']) ? sanitize_text_field(wp_unslash($raw['pc_path']['value'])) : '';
      if ($path !== ''){
        $query['pc_path'] = $path;
      $summary[] = sprintf(
        /* translators: %s: primary category path prefix. */
        __('Primary category starts with “%s”.','merchant-wiki-audit'),
        $path
      );
      }
    }
    if (!empty($raw['inbound']['enabled'])){
      $min = isset($raw['inbound']['min']) ? (int) $raw['inbound']['min'] : 0;
      $max = isset($raw['inbound']['max']) ? (int) $raw['inbound']['max'] : $min;
      if ($max < $min){
        $max = $min;
      }
      $baseline = isset($raw['inbound']['baseline']) && $raw['inbound']['baseline'] !== '' ? (int) $raw['inbound']['baseline'] : null;
      $query['inbound_range'] = ['min'=>$min,'max'=>$max];
      if ($baseline !== null){
        $query['inbound_range']['baseline'] = $baseline;
      }
      $summary[] = sprintf(
        /* translators: 1: minimum inbound links, 2: maximum inbound links. */
        __('Inbound links: %1$d–%2$d','merchant-wiki-audit'),
        $min,
        $max
      );
    }
    if (!empty($raw['age']['enabled'])){
      $min = isset($raw['age']['min']) ? (int) $raw['age']['min'] : 0;
      $max = isset($raw['age']['max']) ? (int) $raw['age']['max'] : $min;
      if ($max < $min){
        $max = $min;
      }
      $baseline = isset($raw['age']['baseline']) && $raw['age']['baseline'] !== '' ? (int) $raw['age']['baseline'] : null;
      $query['age_range'] = ['min'=>$min,'max'=>$max];
      if ($baseline !== null){
        $query['age_range']['baseline'] = $baseline;
      }
      $summary[] = sprintf(
        /* translators: 1: minimum days, 2: maximum days since last update. */
        __('Days since last update: %1$d–%2$d','merchant-wiki-audit'),
        $min,
        $max
      );
    }

    if (empty($summary)){
      return new WP_Error('mw_audit_similar_empty', __('Select at least one filter before searching for similar URLs.','merchant-wiki-audit'));
    }

    $normalized_query = self::normalize_similar_query($query);
    if (is_wp_error($normalized_query)){
      return $normalized_query;
    }

    return [
      'query'   => $normalized_query,
      'limit'   => $limit,
      'offset'  => $offset,
      'applied' => $summary,
    ];
  }

  private static function normalize_similar_query(array $query){
    $normalized = [];
    if (!empty($query['base_url'])){
      $base_url = esc_url_raw(trim((string) $query['base_url']));
      if ($base_url !== ''){
        $normalized['base_url'] = $base_url;
      }
    }
    if (array_key_exists('http_status', $query)){
      $normalized['http_status'] = (int) $query['http_status'];
    }
    if (array_key_exists('in_sitemap', $query)){
      $normalized['in_sitemap'] = !empty($query['in_sitemap']) ? 1 : 0;
    }
    if (array_key_exists('noindex', $query)){
      $normalized['noindex'] = !empty($query['noindex']) ? 1 : 0;
    }
    if (array_key_exists('indexed_in_google', $query)){
      $value = (int) $query['indexed_in_google'];
      if (in_array($value, [0,1], true)){
        $normalized['indexed_in_google'] = $value;
      }
    }
    if (!empty($query['pc_path'])){
      $path = sanitize_text_field($query['pc_path']);
      if ($path !== ''){
        if (function_exists('esc_like')){
          $path = esc_like($path);
        }
        $normalized['pc_path'] = rtrim($path, '%').'%';
      }
    }
    if (!empty($query['inbound_range'])){
      $min = isset($query['inbound_range']['min']) ? max(0, (int) $query['inbound_range']['min']) : 0;
      $max = isset($query['inbound_range']['max']) ? max($min, (int) $query['inbound_range']['max']) : $min;
      $normalized['inbound_range'] = ['min'=>$min,'max'=>$max];
      if (array_key_exists('baseline', $query['inbound_range']) && $query['inbound_range']['baseline'] !== null){
        $normalized['inbound_range']['baseline'] = max(0, (int) $query['inbound_range']['baseline']);
      }
    }
    if (!empty($query['age_range'])){
      $min = isset($query['age_range']['min']) ? max(0, (int) $query['age_range']['min']) : 0;
      $max = isset($query['age_range']['max']) ? max($min, (int) $query['age_range']['max']) : $min;
      $normalized['age_range'] = ['min'=>$min,'max'=>$max];
      if (array_key_exists('baseline', $query['age_range']) && $query['age_range']['baseline'] !== null){
        $normalized['age_range']['baseline'] = max(0, (int) $query['age_range']['baseline']);
      }
    }
    return $normalized;
  }

  // POST actions
  static function action_rebuild_inventory(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_rebuild');
    MW_Audit_DB::ensure_tables_if_missing();
    // reset flags
    MW_Audit_Sitemap::clear_cache();
    MW_Audit_Robots::clear_cache();
    if (class_exists('MW_Audit_GSC')) MW_Audit_GSC::clear_index_cache();
    foreach (['inv','sm','os','http','pc','link','gindex','pi'] as $k){ delete_option('mw_audit_flag_'.$k); delete_option('mw_audit_flag_'.$k.'_at'); }
    self::rebuild_inventory();
    self::safe_redirect( admin_url('admin.php?page=mw-site-index-audit&rebuilt=1') );
  }

  static function action_export_csv(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_export', 'mw_audit_export_nonce');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mw-audit-export.csv"');
    $out=fopen('php://output','w');
    $batch = 2000;
    $offset = 0;
    $wrote_header = false;
    $default_header = [
      'norm_url'=>1,'obj_type'=>1,'obj_id'=>1,'slug'=>1,'published_at'=>1,'http_status'=>1,'redirect_to'=>1,'canonical'=>1,'robots_meta'=>1,'noindex'=>1,'schema_type'=>1,'in_sitemap'=>1,'robots_disallow'=>1,'inbound_links'=>1,'indexed_in_google'=>1,'updated_at'=>1,'pc_name'=>1,'pc_path'=>1,'gsc_coverage_inspection'=>1,'gsc_verdict'=>1,'gsc_inspected_at'=>1,'gsc_ttl_until'=>1,'gsc_last_error'=>1,'gsc_coverage_page'=>1,'gsc_pi_reason'=>1,'gsc_pi_inspected_at'=>1
    ];
    $filters = self::get_status_filters_from_request();
    do {
      $rows = MW_Audit_DB::get_status_rows($batch, $offset, 'norm_url', 'ASC', $filters);
      if (!$wrote_header){
        $header = $rows ? array_keys($rows[0]) : array_keys($default_header);
        fputcsv($out, $header);
        $wrote_header = true;
      }
      foreach ($rows as $row){
        fputcsv($out, $row);
      }
      $offset += $batch;
    } while ($rows && count($rows) === $batch);
    exit;
  }

  static function action_delete_all_data(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_delete_all', 'mw_audit_delete_all_nonce');
    MW_Audit_DB::delete_all();
    foreach (['inv','sm','os','http','pc','link','gindex','pi'] as $k){ delete_option('mw_audit_flag_'.$k); delete_option('mw_audit_flag_'.$k.'_at'); }
    update_option('mw_audit_last_update', current_time('mysql'));
    update_option('mw_audit_last_inv_detected', 0); // reset counter
    MW_Audit_Inventory_Builder::reset_caches();
    MW_Audit_Sitemap::clear_cache();
    MW_Audit_Robots::clear_cache();
    if (class_exists('MW_Audit_GSC')) MW_Audit_GSC::clear_index_cache();
    if (function_exists('wp_cache_flush_group')){
      wp_cache_flush_group('mw_audit');
    }
    foreach ([self::Q_STATUS, self::Q_HTTP, self::Q_LINKS, self::Q_OUTBOUND, self::Q_PC, self::Q_INV] as $queue){
      MW_Audit_Queue::delete($queue);
    }
    foreach (['inventory','refresh','http','pc','links','gindex'] as $lock){
      delete_transient(self::lock_key($lock));
    }
    self::safe_redirect( admin_url('admin.php?page=mw-site-index-audit&deleted=1') );
  }

  static function action_selftest(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_selftest', 'mw_audit_selftest_nonce');
    $inv = MW_Audit_DB::esc_table(MW_Audit_DB::t_inventory());
    if ($inv === ''){
      wp_die(esc_html__('Inventory table missing.','merchant-wiki-audit'));
    }
    $now = current_time('mysql');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $ok = MW_Audit_DB::query_sql(
      "INSERT INTO {$inv} (norm_url, obj_type, obj_id, slug, created_at) VALUES (%s,%s,%d,%s,%s)",
      [home_url('/'), 'selftest', 0, '', $now]
    );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $cnt = (int) MW_Audit_DB::get_var_sql("SELECT COUNT(*) FROM {$inv}");
    $err = MW_Audit_DB::last_error() ?: 'n/a';
    $message = sprintf(
      'SelfTest: insert=%s | count=%d | last_error=%s',
      $ok !== false ? 'OK' : 'FAIL',
      $cnt,
      $err
    );
    wp_die(esc_html($message));
  }

  static function action_toggle_dropdb(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_toggle_dropdb');
    $cur = get_option('mw_audit_drop_on_uninstall')==='yes' ? 'yes' : 'no';
    $new = ($cur==='yes') ? 'no' : 'yes';
    update_option('mw_audit_drop_on_uninstall', $new==='yes'?'yes':'no', false);
    self::safe_redirect( admin_url('admin.php?page=mw-site-index-audit&dropdb='.$new) );
  }

  static function action_save_pc_tax(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_pc_tax', 'mw_audit_pc_tax_nonce');
    $available = self::get_available_pc_taxonomies();
    $tax_raw = self::get_post_value('pc_taxonomy', '');
    $tax = $tax_raw !== '' ? sanitize_key((string) $tax_raw) : '';
    if ($tax && isset($available[$tax]) && taxonomy_exists($tax)){
      update_option('mw_audit_pc_taxonomy', $tax, false);
    } else {
      delete_option('mw_audit_pc_taxonomy');
    }
    self::safe_redirect( admin_url('admin.php?page=mw-site-index-audit&pc_tax='.$tax) );
  }

  static function action_save_settings(){
    self::ensure_manage_capability();
    $nonce_field = self::get_post_value('mw_audit_save_settings_nonce_alt', null) !== null ? 'mw_audit_save_settings_nonce_alt' : 'mw_audit_save_settings_nonce';
    check_admin_referer('mw_audit_save_settings', $nonce_field);
    $settings = MW_Audit_DB::get_settings();
    $timeout_head = absint(self::get_post_value('timeout_head', $settings['timeouts']['head']));
    $timeout_get  = absint(self::get_post_value('timeout_get', $settings['timeouts']['get']));
    $settings['timeouts']['head'] = max(1, $timeout_head);
    $settings['timeouts']['get']  = max(1, $timeout_get);
    $profile_raw = self::get_post_value('profile_defaults', $settings['profile_defaults']);
    $profile = sanitize_key((string) $profile_raw);
    if (!in_array($profile, ['fast','standard','safe'], true)) {
      $profile = 'standard';
    }
    $settings['profile_defaults'] = $profile;
    $export_ttl = absint(self::get_post_value('ttl_export', $settings['ttl']['export_hours']));
    $api_ttl_input = absint(self::get_post_value('ttl_api', $settings['ttl']['api_hours']));
    $settings['ttl']['export_hours'] = max(1, $export_ttl);
    $api_ttl = max(1, $api_ttl_input);
    $settings['ttl']['api_hours'] = $api_ttl;
    $import_mode_raw = self::get_post_value('gsc_import_mode', $settings['gsc_import_mode']);
    $import_mode = sanitize_key((string) $import_mode_raw);
    if (!in_array($import_mode, ['csv','sheets'], true)) {
      $import_mode = 'csv';
    }
    $settings['gsc_import_mode'] = $import_mode;
    $settings['gsc_api_enabled'] = self::read_bool_param('gsc_api_enabled', !empty($settings['gsc_api_enabled']));
    $settings['gdrive_export_enabled'] = self::read_bool_param('gdrive_export_enabled', !empty($settings['gdrive_export_enabled']));
    MW_Audit_DB::update_settings($settings);
    MW_Audit_GSC::save_ttl_hours($api_ttl);
    self::safe_redirect( add_query_arg('settings_saved', 1, admin_url('admin.php?page=mw-site-index-settings')) );
  }

  static function action_save_gsc_credentials(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_gsc_credentials');
    $client_id = sanitize_text_field((string) self::get_post_value('gsc_client_id', ''));
    $client_secret = sanitize_text_field((string) self::get_post_value('gsc_client_secret', ''));
    $prev_id = MW_Audit_GSC::get_client_id();
    $prev_secret = MW_Audit_GSC::get_client_secret();
    MW_Audit_GSC::save_credentials($client_id, $client_secret);
    if ($prev_id !== $client_id || $prev_secret !== $client_secret){
      MW_Audit_GSC::disconnect();
      MW_Audit_GSC::save_property('');
    }
    self::safe_redirect( admin_url('admin.php?page=mw-site-index-audit&gsc_saved=1') );
  }

  static function action_save_gsc_property(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_gsc_property');
    $property = '';
    $manual_property = trim((string) self::get_post_value('gsc_property_manual', ''));
    $select_property = trim((string) self::get_post_value('gsc_property_select', ''));
    if ($manual_property !== ''){
      $property = esc_url_raw($manual_property);
    } elseif ($select_property !== ''){
      $property = esc_url_raw($select_property);
    }
    MW_Audit_GSC::save_property($property);
    self::safe_redirect( admin_url('admin.php?page=mw-site-index-audit&gsc_property=1') );
  }

  static function action_disconnect_gsc(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_gsc_disconnect');
    MW_Audit_GSC::disconnect();
    self::safe_redirect( admin_url('admin.php?page=mw-site-index-audit&gsc_disconnected=1') );
  }

  static function action_gsc_callback(){
    self::ensure_manage_capability();
    MW_Audit_GSC::handle_oauth_callback();
    self::safe_redirect( admin_url('admin.php?page=mw-site-index-audit&gsc_connected=1') );
  }

  // Inventory build
  static function rebuild_inventory(){
    MW_Audit_DB::truncate_inventory();
    MW_Audit_DB::set_flag('inv','running');

    MW_Audit_Inventory_Builder::reset_caches();
    $state = MW_Audit_Inventory_Builder::bootstrap_state();
    while ($state['phase'] !== 'done'){
      $state = MW_Audit_Inventory_Builder::process_state($state, 500);
    }

    update_option('mw_audit_last_update', current_time('mysql'));
    update_option('mw_audit_last_inv_detected', (int)$state['done']);
    MW_Audit_DB::set_flag('inv','done');
  }

  // Helpers
  static function ajax_inventory_start(){
    check_ajax_referer('mw_audit_inventory_start','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    if (MW_Audit_Queue::exists(self::Q_INV) || self::is_locked('inventory')){
      wp_send_json_error(['msg'=>__('Inventory rebuild already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!self::acquire_lock('inventory')){
      wp_send_json_error(['msg'=>__('Inventory rebuild already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }

    MW_Audit_DB::truncate_inventory();
    MW_Audit_Inventory_Builder::reset_caches();
    $state = MW_Audit_Inventory_Builder::bootstrap_state();
    MW_Audit_Queue::set(self::Q_INV, $state);
    MW_Audit_DB::set_flag('inv','running');
    wp_send_json_success(['total'=>$state['total']]);
  }

  static function ajax_inventory_step(){
    check_ajax_referer('mw_audit_inventory_step','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    $state = MW_Audit_Queue::get(self::Q_INV);
    if (!$state){
      self::release_lock('inventory');
      wp_send_json_error(['msg'=>__('Queue not found','merchant-wiki-audit')]);
    }

    self::touch_lock('inventory');
    MW_Audit_Queue::touch(self::Q_INV);
    $batch = self::read_int_param('batch', 500, 1, 1000);

    $state = MW_Audit_Inventory_Builder::process_state($state, $batch);
    $finished = ($state['phase'] === 'done');

    if ($finished){
      MW_Audit_Queue::delete(self::Q_INV);
      MW_Audit_DB::set_flag('inv','done');
      update_option('mw_audit_last_update', current_time('mysql'));
      update_option('mw_audit_last_inv_detected', (int)$state['done']);
      self::release_lock('inventory');
    } else {
      MW_Audit_Queue::set(self::Q_INV, $state);
    }

    wp_send_json_success([
      'i'        => (int)$state['done'],
      'total'    => (int)$state['total'],
      'done'     => (int)$state['done'],
      'errors'   => (int)$state['errors'],
      'phase'    => $state['phase'],
      'finished' => $finished,
    ]);
  }

  private static function process_url_batch($process, $queue_key, $scan_mode, $defaults){
    $q = MW_Audit_Queue::get($queue_key);
    if (!$q){
      return new WP_Error('mw_audit_queue_missing', __('Queue not found','merchant-wiki-audit'));
    }

    if (isset($q['urls']) && is_array($q['urls'])){
      $urls = $q['urls'];
      $index = isset($q['i']) ? (int) $q['i'] : 0;
      $last_url = ($index > 0 && isset($urls[$index - 1])) ? $urls[$index - 1] : null;
      $last_id = 0;
      if ($last_url){
        $inv = MW_Audit_DB::esc_table(MW_Audit_DB::t_inventory());
        if ($inv){
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
          $last_id = (int) MW_Audit_DB::get_var_sql("SELECT id FROM {$inv} WHERE norm_url=%s LIMIT 1", [$last_url]);
        }
      }
      $q = [
        'last_id' => $last_id,
        'i'       => $index,
        'done'    => isset($q['done']) ? max((int)$q['done'], $index) : $index,
        'errors'  => isset($q['errors']) ? (int)$q['errors'] : 0,
        'total'   => count($urls),
        'also_pc' => isset($q['also_pc']) ? (int) $q['also_pc'] : 0,
      ];
      MW_Audit_Queue::set($queue_key, $q);
    }

    MW_Audit_Queue::touch($queue_key);

    $batch  = self::read_int_param('batch', $defaults['batch'], 1, 500);
    $budget = self::read_float_param('budget', $defaults['budget'], 1.0, 15.0);
    $tout_h = self::read_int_param('tout_head', $defaults['tout_head'], 1, 8);
    $tout_g = self::read_int_param('tout_get', $defaults['tout_get'], 1, 12);

    $default_throttle = isset($defaults['throttle']) ? (int) $defaults['throttle'] : (($batch > 120) ? 50000 : 0);
    $throttle = self::read_int_param('throttle', $default_throttle, 0, 1000000);

    $last_id = isset($q['last_id']) ? (int) $q['last_id'] : 0;
    $processed = 0;
    $t0 = microtime(true);

    while ($processed < $batch){
      $remaining = $batch - $processed;
      $rows = MW_Audit_DB::get_inventory_chunk($last_id, $remaining);
      if (!$rows){
        break;
      }
      foreach ($rows as $row){
        if ((microtime(true) - $t0) > $budget){
          $processed = $batch; // force exit
          break 2;
        }
        $url = $row['norm_url'];
        try {
          $scan_row = MW_Audit_UrlScanner::scan($url, $scan_mode, [
            'head'=>$tout_h,
            'get'=>$tout_g,
            'throttle'=>$throttle,
          ]);
          MW_Audit_DB::upsert_status($url, $scan_row);
          $q['done']++;
        } catch (\Throwable $e){
          $q['errors']++;
          MW_Audit_DB::log('Batch error ('.$process.') for '.$url.': '.$e->getMessage());
        }
        $processed++;
        $last_id = (int) $row['id'];
        $q['i'] = $q['done'];
        if ($processed >= $batch){
          break;
        }
      }
    }

    $q['last_id'] = $last_id;
    $q['total'] = max((int) $q['total'], (int) $q['done']);

    $has_more = !empty(MW_Audit_DB::get_inventory_chunk($last_id, 1));
    $finished = !$has_more;

    if ($finished){
      $q['total'] = max($q['total'], $q['done']);
      MW_Audit_Queue::delete($queue_key);
    } else {
      MW_Audit_Queue::set($queue_key, $q);
    }

    if ($finished && $q['done'] < $q['total']){
      $q['total'] = $q['done'];
    }

    return [
      'queue'    => $q,
      'finished' => $finished,
      'batch'    => $batch,
      'budget'   => $budget,
      'tout_head'=> $tout_h,
      'tout_get' => $tout_g,
      'throttle' => $throttle,
    ];
  }

  public static function get_available_pc_taxonomies(){
    $objects = get_taxonomies(['public'=>true], 'objects');
    $out = [];
    foreach ($objects as $slug => $tax){
      $label = $tax->labels->singular_name ?? $tax->labels->name ?? $slug;
      if (!empty($tax->hierarchical) || $slug === 'category'){
        $out[$slug] = $label;
      }
    }
    if (!$out && $objects){
      foreach ($objects as $slug => $tax){
        $label = $tax->labels->singular_name ?? $tax->labels->name ?? $slug;
        $out[$slug] = $label;
      }
    }
    return $out;
  }

  public static function get_primary_category_taxonomy(){
    $stored = sanitize_key(get_option('mw_audit_pc_taxonomy', ''));
    if ($stored && taxonomy_exists($stored)){
      return $stored;
    }
    $available = array_keys(self::get_available_pc_taxonomies());
    if (in_array('category', $available, true)){
      return 'category';
    }
    return $available ? $available[0] : 'category';
  }

  // Sitemaps cache
  static function ajax_sitemaps_prepare(){
    check_ajax_referer('mw_audit_sm_prepare','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    $payload = MW_Audit_Sitemap::prepare_cache();
    wp_send_json_success([
      'sources'=>$payload['sources'],
      'count'=>$payload['count'],
      'age'=>0
    ]);
  }

  // On-site signals (full) — adaptive
  static function ajax_refresh_start(){
    check_ajax_referer('mw_audit_refresh_start','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    if (MW_Audit_Queue::exists(self::Q_STATUS) || self::is_locked('refresh')){
      wp_send_json_error(['msg'=>__('Refresh queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!self::acquire_lock('refresh')){
      wp_send_json_error(['msg'=>__('Refresh queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!MW_Audit_DB::count_inventory()){
      if (MW_Audit_Queue::exists(self::Q_INV) || self::is_locked('inventory')){
        self::release_lock('refresh');
        wp_send_json_error(['msg'=>__('Inventory rebuild in progress. Please finish it before running refresh.','merchant-wiki-audit')]);
      }
      self::rebuild_inventory();
    }

    $also_pc = self::read_bool_param('also_pc') ? 1 : 0;
    $total = MW_Audit_DB::count_inventory();
    $payload = [
      'last_id' => 0,
      'i'       => 0,
      'done'    => 0,
      'errors'  => 0,
      'total'   => $total,
      'also_pc' => $also_pc,
    ];
    MW_Audit_Queue::set(self::Q_STATUS, $payload);
    MW_Audit_DB::set_flag('os','running');
    wp_send_json_success(['total'=>$total]);
  }

  static function ajax_refresh_step(){
    check_ajax_referer('mw_audit_refresh_step','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    self::touch_lock('refresh');
    $settings = MW_Audit_DB::get_settings();
    $presets = MW_Audit_DB::profile_presets();
    $profile_key = $settings['profile_defaults'] ?? 'standard';
    $preset = $presets[$profile_key] ?? $presets['standard'];
    $result = self::process_url_batch('refresh', self::Q_STATUS, 'full', [
      'batch'     => $preset['batch'],
      'budget'    => $preset['budget'],
      'tout_head' => $settings['timeouts']['head'],
      'tout_get'  => $settings['timeouts']['get'],
      'throttle'  => 0,
    ]);
    if (is_wp_error($result)){
      self::release_lock('refresh');
      wp_send_json_error(['msg'=>$result->get_error_message()]);
    }

    $q = $result['queue'];
    $finished = $result['finished'];
    if ($finished){
      update_option('mw_audit_last_update', current_time('mysql'));
      MW_Audit_DB::set_flag('os','done');
      if (!empty($q['also_pc'])) self::queue_pc_start();
      self::release_lock('refresh');
    }
    wp_send_json_success(['i'=>$q['i'],'total'=>$q['total'],'done'=>$q['done'],'errors'=>$q['errors'],'finished'=>$finished]);
  }

  // HTTP-only — adaptive
  static function ajax_http_start(){
    check_ajax_referer('mw_audit_http_start','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    if (MW_Audit_Queue::exists(self::Q_HTTP) || self::is_locked('http')){
      wp_send_json_error(['msg'=>__('HTTP queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!self::acquire_lock('http')){
      wp_send_json_error(['msg'=>__('HTTP queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!MW_Audit_DB::count_inventory()){
      if (MW_Audit_Queue::exists(self::Q_INV) || self::is_locked('inventory')){
        self::release_lock('http');
        wp_send_json_error(['msg'=>__('Inventory rebuild in progress. Please finish it before running HTTP scan.','merchant-wiki-audit')]);
      }
      self::rebuild_inventory();
    }
    $total = MW_Audit_DB::count_inventory();
    $payload = [
      'last_id' => 0,
      'i'       => 0,
      'done'    => 0,
      'errors'  => 0,
      'total'   => $total,
    ];
    MW_Audit_Queue::set(self::Q_HTTP, $payload);
    MW_Audit_DB::set_flag('http','running');
    wp_send_json_success(['total'=>$total]);
  }

  static function ajax_http_step(){
    check_ajax_referer('mw_audit_http_step','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    self::touch_lock('http');
    $settings = MW_Audit_DB::get_settings();
    $presets = MW_Audit_DB::profile_presets();
    $profile_key = $settings['profile_defaults'] ?? 'standard';
    $preset = $presets[$profile_key] ?? $presets['standard'];
    $result = self::process_url_batch('http', self::Q_HTTP, 'http_only', [
      'batch'     => max(4, (int) round($preset['batch'] * 1.25)),
      'budget'    => max(20.0, $preset['budget']),
      'tout_head' => $settings['timeouts']['head'],
      'tout_get'  => $settings['timeouts']['get'],
      'throttle'  => 0,
    ]);
    if (is_wp_error($result)){
      self::release_lock('http');
      wp_send_json_error(['msg'=>$result->get_error_message()]);
    }

    $q = $result['queue'];
    $finished = $result['finished'];
    if ($finished){
      MW_Audit_DB::set_flag('http','done');
      self::release_lock('http');
    }
    wp_send_json_success(['i'=>$q['i'],'total'=>$q['total'],'done'=>$q['done'],'errors'=>$q['errors'],'finished'=>$finished]);
  }

  private static function build_pc_queue_payload(){
    $types = MW_Audit_Inventory_Builder::post_types();
    $taxonomy = self::get_primary_category_taxonomy();
    $total = MW_Audit_Inventory_Builder::count_posts($types);
    return [
      'post_types' => $types,
      'taxonomy'   => $taxonomy,
      'last_id'    => 0,
      'i'          => 0,
      'done'       => 0,
      'errors'     => 0,
      'total'      => $total,
    ];
  }

  private static function init_pc_queue(array $payload){
    MW_Audit_Queue::set(self::Q_PC, $payload);
    MW_Audit_DB::set_flag('pc','running');
    return $payload['total'];
  }

  // PC map queue kick (used when os finished with also_pc=1)
  private static function queue_pc_start(){
    if (MW_Audit_Queue::exists(self::Q_PC) || self::is_locked('pc')){
      return false;
    }
    if (!self::acquire_lock('pc')){
      return false;
    }
    $payload = self::build_pc_queue_payload();
    self::init_pc_queue($payload);
    return true;
  }

  // PC map — adaptive lite
  static function ajax_pc_start(){
    check_ajax_referer('mw_audit_pc_start','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    if (MW_Audit_Queue::exists(self::Q_PC) || self::is_locked('pc')){
      wp_send_json_error(['msg'=>__('Primary category queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!self::acquire_lock('pc')){
      wp_send_json_error(['msg'=>__('Primary category queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    $payload = self::build_pc_queue_payload();
    $total = self::init_pc_queue($payload);
    wp_send_json_success(['total'=>$total]);
  }

  static function ajax_pc_step(){
    check_ajax_referer('mw_audit_pc_step','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    $q = MW_Audit_Queue::get(self::Q_PC);
    if (!$q){
      self::release_lock('pc');
      wp_send_json_error(['msg'=>__('Queue not found','merchant-wiki-audit')]);
    }
    if (isset($q['ids']) && is_array($q['ids'])){
      $ids = $q['ids'];
      $index = isset($q['i']) ? (int) $q['i'] : 0;
      $last_id = ($index > 0 && isset($ids[$index - 1])) ? (int) $ids[$index - 1] : 0;
      $q = [
        'post_types' => MW_Audit_Inventory_Builder::post_types(),
        'taxonomy'   => self::get_primary_category_taxonomy(),
        'last_id'    => $last_id,
        'i'          => $index,
        'done'       => isset($q['done']) ? max((int)$q['done'], $index) : $index,
        'errors'     => isset($q['errors']) ? (int)$q['errors'] : 0,
        'total'      => isset($q['total']) ? (int)$q['total'] : count($ids),
      ];
      MW_Audit_Queue::set(self::Q_PC, $q);
    }
    self::touch_lock('pc');
    MW_Audit_Queue::touch(self::Q_PC);

    $batch  = self::read_int_param('batch', 200, 1, 500);
    $budget = self::read_float_param('budget', 6.0, 1.0, 15.0);
    $types  = isset($q['post_types']) && is_array($q['post_types']) ? $q['post_types'] : MW_Audit_Inventory_Builder::post_types();
    $taxonomy = isset($q['taxonomy']) ? sanitize_key($q['taxonomy']) : self::get_primary_category_taxonomy();
    $q['taxonomy'] = $taxonomy;

    $last_id = isset($q['last_id']) ? (int)$q['last_id'] : 0;
    $processed = 0;
    $t0 = microtime(true);

    while ($processed < $batch){
      $remaining = $batch - $processed;
      $ids = MW_Audit_Inventory_Builder::fetch_post_batch($types, $last_id, $remaining);
      if (!$ids){
        break;
      }
      foreach ($ids as $pid){
        if ((microtime(true) - $t0) > $budget){
          $processed = $batch;
          break 2;
        }
        try {
          $data = MW_Audit_PCMapper::build_for_post($pid, $taxonomy);
          MW_Audit_DB::upsert_pc($pid, $data);
          $q['done']++;
        } catch (\Throwable $e){
          $q['errors']++;
        }
        $processed++;
        $last_id = (int) $pid;
        $q['i'] = $q['done'];
        if ($processed >= $batch){
          break;
        }
      }
    }

    $q['last_id'] = $last_id;
    $q['total'] = max((int) $q['total'], (int) $q['done']);
    $has_more = !empty(MW_Audit_Inventory_Builder::fetch_post_batch($types, $last_id, 1));
    $finished = !$has_more;

    if ($finished){
      if ($q['done'] < $q['total']){
        $q['total'] = $q['done'];
      }
      MW_Audit_Queue::delete(self::Q_PC);
      MW_Audit_DB::set_flag('pc','done');
      self::release_lock('pc');
    } else {
      MW_Audit_Queue::set(self::Q_PC, $q);
    }

    wp_send_json_success(['i'=>$q['i'],'total'=>$q['total'],'done'=>$q['done'],'errors'=>$q['errors'],'finished'=>$finished]);
  }

  // Internal link scan (mass) — adaptive
  static function ajax_links_start(){
    check_ajax_referer('mw_audit_links_start','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    if (MW_Audit_Queue::exists(self::Q_LINKS) || self::is_locked('links')){
      wp_send_json_error(['msg'=>__('Internal links queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!self::acquire_lock('links')){
      wp_send_json_error(['msg'=>__('Internal links queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!MW_Audit_DB::count_inventory()){
      if (MW_Audit_Queue::exists(self::Q_INV) || self::is_locked('inventory')){
        self::release_lock('links');
        wp_send_json_error(['msg'=>__('Inventory rebuild in progress. Please finish it before running link scan.','merchant-wiki-audit')]);
      }
      self::rebuild_inventory();
    }
    $total = MW_Audit_DB::count_inventory();
    $payload = [
      'last_id' => 0,
      'i'       => 0,
      'done'    => 0,
      'errors'  => 0,
      'total'   => $total,
    ];
    MW_Audit_Queue::set(self::Q_LINKS, $payload);
    MW_Audit_DB::set_flag('link','running');
    wp_send_json_success(['total'=>$total]);
  }

  static function ajax_links_step(){
    check_ajax_referer('mw_audit_links_step','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    $q = MW_Audit_Queue::get(self::Q_LINKS);
    if (!$q){
      self::release_lock('links');
      wp_send_json_error(['msg'=>__('Queue not found','merchant-wiki-audit')]);
    }
    if (isset($q['urls']) && is_array($q['urls'])){
      $urls = $q['urls'];
      $index = isset($q['i']) ? (int) $q['i'] : 0;
      $last_url = ($index > 0 && isset($urls[$index - 1])) ? $urls[$index - 1] : null;
      $last_id = 0;
      if ($last_url){
        $inv = MW_Audit_DB::esc_table(MW_Audit_DB::t_inventory());
        if ($inv){
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
          $last_id = (int) MW_Audit_DB::get_var_sql("SELECT id FROM {$inv} WHERE norm_url=%s LIMIT 1", [$last_url]);
        }
      }
      $q = [
        'last_id' => $last_id,
        'i'       => $index,
        'done'    => isset($q['done']) ? max((int)$q['done'], $index) : $index,
        'errors'  => isset($q['errors']) ? (int)$q['errors'] : 0,
        'total'   => count($urls),
      ];
      MW_Audit_Queue::set(self::Q_LINKS, $q);
    }
    self::touch_lock('links');
    MW_Audit_Queue::touch(self::Q_LINKS);

    $batch  = self::read_int_param('batch', 120, 1, 500);
    $budget = self::read_float_param('budget', 6.0, 1.0, 15.0);

    $last_id = isset($q['last_id']) ? (int)$q['last_id'] : 0;
    $processed = 0;
    $t0 = microtime(true);

    while ($processed < $batch){
      $remaining = $batch - $processed;
      $rows = MW_Audit_DB::get_inventory_chunk($last_id, $remaining);
      if (!$rows){
        break;
      }
      foreach ($rows as $row){
        if ((microtime(true) - $t0) > $budget){
          $processed = $batch;
          break 2;
        }
        try {
          $cnt = MW_Audit_ILinks::count_inbound($row['norm_url']);
          MW_Audit_DB::upsert_status($row['norm_url'], ['inbound_links'=>$cnt, 'updated_at'=>current_time('mysql')]);
          $q['done']++;
        } catch (\Throwable $e){
          $q['errors']++;
        }
        $processed++;
        $last_id = (int) $row['id'];
        $q['i'] = $q['done'];
        if ($processed >= $batch){
          break;
        }
      }
    }

    $q['last_id'] = $last_id;
    $q['total'] = max((int) $q['total'], (int) $q['done']);
    $has_more = !empty(MW_Audit_DB::get_inventory_chunk($last_id, 1));
    $finished = !$has_more;

    if ($finished){
      if ($q['done'] < $q['total']){
        $q['total'] = $q['done'];
      }
      MW_Audit_Queue::delete(self::Q_LINKS);
      MW_Audit_DB::set_flag('link','done');
      self::release_lock('links');
    } else {
      MW_Audit_Queue::set(self::Q_LINKS, $q);
    }

    wp_send_json_success(['i'=>$q['i'],'total'=>$q['total'],'done'=>$q['done'],'errors'=>$q['errors'],'finished'=>$finished]);
  }

  // Outbound link scan (new block)
  static function ajax_outbound_start(){
    check_ajax_referer('mw_audit_outbound_start','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    if (MW_Audit_Queue::exists(self::Q_OUTBOUND) || self::is_locked('outbound')){
      wp_send_json_error(['msg'=>__('Outbound links queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!self::acquire_lock('outbound')){
      wp_send_json_error(['msg'=>__('Outbound links queue already running. Please resume instead of starting a new one.','merchant-wiki-audit')]);
    }
    if (!MW_Audit_DB::count_inventory()){
      self::release_lock('outbound');
      wp_send_json_error(['msg'=>__('Run the inventory rebuild before scanning outbound links.','merchant-wiki-audit')]);
    }
    $total = MW_Audit_DB::count_inventory();
    $payload = [
      'last_id' => 0,
      'i'       => 0,
      'done'    => 0,
      'errors'  => 0,
      'total'   => $total,
    ];
    MW_Audit_Queue::set(self::Q_OUTBOUND, $payload);
    MW_Audit_DB::set_flag('outbound','running');
    wp_send_json_success(['total'=>$total]);
  }

  static function ajax_outbound_step(){
    check_ajax_referer('mw_audit_outbound_step','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    $q = MW_Audit_Queue::get(self::Q_OUTBOUND);
    if (!$q){
      self::release_lock('outbound');
      wp_send_json_error(['msg'=>__('Queue not found','merchant-wiki-audit')]);
    }
    self::touch_lock('outbound');
    MW_Audit_Queue::touch(self::Q_OUTBOUND);
    $batch  = self::read_int_param('batch', 60, 1, 400);
    $budget = self::read_float_param('budget', 6.0, 1.0, 15.0);
    $last_id = isset($q['last_id']) ? (int)$q['last_id'] : 0;
    $processed = 0;
    $t0 = microtime(true);
    while ($processed < $batch){
      $remaining = $batch - $processed;
      $rows = MW_Audit_DB::get_inventory_chunk($last_id, $remaining);
      if (!$rows){
        break;
      }
      foreach ($rows as $row){
        if ((microtime(true) - $t0) > $budget){
          $processed = $batch;
          break 2;
        }
        try {
          $stats = MW_Audit_ILinks::scan_outbound($row['norm_url']);
          if ($stats !== null){
            $stats['last_scanned'] = current_time('mysql');
            MW_Audit_DB::upsert_outbound($row['norm_url'], $stats);
          }
          $q['done']++;
        } catch (\Throwable $e){
          $q['errors']++;
        }
        $processed++;
        $last_id = (int)$row['id'];
        $q['i'] = $q['done'];
        if ($processed >= $batch){
          break;
        }
      }
    }
    $q['last_id'] = $last_id;
    $q['total'] = max((int)$q['total'], (int)$q['done']);
    $has_more = !empty(MW_Audit_DB::get_inventory_chunk($last_id, 1));
    $finished = !$has_more;
    if ($finished){
      if ($q['done'] < $q['total']){
        $q['total'] = $q['done'];
      }
      MW_Audit_Queue::delete(self::Q_OUTBOUND);
      MW_Audit_DB::set_flag('outbound','done');
      self::release_lock('outbound');
    } else {
      MW_Audit_Queue::set(self::Q_OUTBOUND, $q);
    }
    wp_send_json_success(['i'=>$q['i'],'total'=>$q['total'],'done'=>$q['done'],'errors'=>$q['errors'],'finished'=>$finished]);
  }

  // Google index status — TTL-aware queue helpers
  private static function start_gsc_queue($force = false, array $only_urls = []){
    if (!MW_Audit_GSC::is_connected()){
      return new WP_Error('mw_audit_gsc_not_connected', __('Connect Google Search Console first.','merchant-wiki-audit'));
    }
    if (!MW_Audit_GSC::get_property()){
      return new WP_Error('mw_audit_gsc_no_property', __('Select a Google Search Console property before running indexing checks.','merchant-wiki-audit'));
    }
    $settings = MW_Audit_DB::get_settings();
    if (empty($settings['gsc_api_enabled'])) {
      return new WP_Error('mw_audit_gsc_api_disabled', __('Google Search Console API is disabled in settings.','merchant-wiki-audit'));
    }
    if (MW_Audit_Queue::exists(self::Q_GSC) || self::is_locked('gindex')){
      return new WP_Error('mw_audit_gsc_queue_running', __('Google index queue already running. Please resume instead of starting a new one.','merchant-wiki-audit'));
    }
    if (!self::acquire_lock('gindex')){
      return new WP_Error('mw_audit_gsc_queue_locked', __('Google index queue already running. Please resume instead of starting a new one.','merchant-wiki-audit'));
    }

    if (!MW_Audit_DB::count_inventory()){
      if (MW_Audit_Queue::exists(self::Q_INV) || self::is_locked('inventory')){
        self::release_lock('gindex');
        return new WP_Error('mw_audit_inventory_running', __('Inventory rebuild in progress. Please finish it before running Google index scan.','merchant-wiki-audit'));
      }
      self::rebuild_inventory();
    }

    $new_hours = (int) apply_filters('mw_audit_gsc_new_page_hours', 72);
    if ($new_hours < 0){
      $new_hours = 0;
    }

    $args = [
      'new_hours' => $new_hours,
    ];
    if ($force){
      $args['force'] = true;
    }
    if ($only_urls){
      $args['only_urls'] = $only_urls;
    }

    $total = MW_Audit_DB::count_gsc_candidates($args);
    $stale_total = MW_Audit_DB::count_gsc_stale_total();
    if ($total <= 0){
      MW_Audit_DB::set_flag('gindex','done');
      MW_Audit_Queue::delete(self::Q_GSC);
      self::release_lock('gindex');
      return ['total'=>0,'state'=>[
        'last_id'=>0,
        'done'=>0,
        'errors'=>0,
        'skipped'=>0,
        'total'=>0,
        'args'=>$args,
      ]];
    }

    $state = [
      'last_id'    => 0,
      'done'       => 0,
      'errors'     => 0,
      'skipped'    => 0,
      'total'      => (int) $total,
      'args'       => $args,
      'stale_total'=> (int)$stale_total,
      'started_at' => current_time('mysql'),
      'last_activity' => current_time('mysql'),
      'last_error' => '',
    ];

    MW_Audit_Queue::set(self::Q_GSC, $state);
    MW_Audit_DB::set_flag('gindex','running');
    return [
      'total'=>$total,
      'state'=>$state,
      'stale_total'=> (int)$stale_total,
      'stale_remaining'=> max(0, (int)$stale_total - min((int)$stale_total, (int)$total)),
    ];
  }

  private static function process_gsc_queue(){
    $state = MW_Audit_Queue::get(self::Q_GSC);
    if (!$state){
      self::release_lock('gindex');
      MW_Audit_DB::set_flag('gindex','fail');
      return new WP_Error('mw_audit_gsc_queue_missing', __('Queue not found','merchant-wiki-audit'));
    }
    if (!MW_Audit_GSC::is_connected() || !MW_Audit_GSC::get_property()){
      MW_Audit_Queue::delete(self::Q_GSC);
      self::release_lock('gindex');
      MW_Audit_DB::set_flag('gindex','fail');
      return new WP_Error('mw_audit_gsc_not_connected', __('Google Search Console is not connected anymore.','merchant-wiki-audit'));
    }

    self::touch_lock('gindex');
    MW_Audit_Queue::touch(self::Q_GSC);

    $batch = self::read_int_param('batch', 100, 1, 200);
    $args = isset($state['args']) && is_array($state['args']) ? $state['args'] : [];
    $force = self::read_bool_param('force', !empty($args['force']));
    $args['force'] = $force ? true : false;
    $state['args'] = $args;

    $rows = MW_Audit_DB::get_gsc_candidate_batch(isset($state['last_id']) ? (int)$state['last_id'] : 0, $batch, $args);
    if (!$rows){
      MW_Audit_DB::set_flag('gindex','done');
      MW_Audit_Queue::delete(self::Q_GSC);
      self::release_lock('gindex');
      $state['total'] = max((int)$state['total'], (int)$state['done']);
      return [
        'done'     => (int)$state['done'],
        'total'    => (int)$state['total'],
        'errors'   => (int)$state['errors'],
        'skipped'  => (int)$state['skipped'],
        'finished' => true,
        'messages' => [],
        'meta'     => ['skipped'=>$state['skipped'], 'api_calls'=>0],
      ];
    }

    $urls = [];
    $last_id = isset($state['last_id']) ? (int)$state['last_id'] : 0;
    foreach ($rows as $row){
      $urls[(int)$row['id']] = $row['norm_url'];
      if ((int)$row['id'] > $last_id){
        $last_id = (int)$row['id'];
      }
    }

    $inspection = MW_Audit_GSC::inspect_urls(array_values($urls), !empty($args['force']));
    $meta = isset($inspection['meta']) && is_array($inspection['meta']) ? $inspection['meta'] : [];
    $messages = isset($inspection['errors']) && is_array($inspection['errors']) ? $inspection['errors'] : [];
    if (method_exists('MW_Audit_GSC','log_quota_usage')){
      MW_Audit_GSC::log_quota_usage($meta['api_calls'] ?? 0, $meta['api_errors'] ?? 0);
    }

    foreach ($urls as $id => $url){
      $res = $inspection['results'][$url] ?? null;
      $indexed = null;
      if (is_array($res) && array_key_exists('indexed', $res)){
        $indexed = $res['indexed'];
      } elseif ($res === 0 || $res === 1){
        $indexed = (int) $res;
      }
      if ($indexed === 0 || $indexed === 1){
        MW_Audit_DB::upsert_status($url, [
          'indexed_in_google' => $indexed ? 1 : 0,
          'updated_at'        => current_time('mysql'),
        ]);
      }
    }

    $done_batch = count($rows);
    $state['done']   += $done_batch;
    $state['errors'] += isset($meta['api_errors']) ? (int)$meta['api_errors'] : 0;
    $state['skipped']+= isset($meta['skipped']) ? (int)$meta['skipped'] : 0;
    $state['last_id'] = $last_id;
    $state['last_activity'] = current_time('mysql');
    if ($messages){
      $state['last_error'] = end($messages);
    }
    $state['total'] = max((int)$state['total'], (int)$state['done'] + (count($rows) >= $batch ? $batch : 0));

    $finished = (count($rows) < $batch);
    if ($finished){
      MW_Audit_DB::set_flag('gindex','done');
      MW_Audit_Queue::delete(self::Q_GSC);
      self::release_lock('gindex');
      $state['total'] = max((int)$state['total'], (int)$state['done']);
    } else {
      MW_Audit_Queue::set(self::Q_GSC, $state);
    }

    $stale_total = isset($state['stale_total']) ? (int)$state['stale_total'] : 0;
    $stale_remaining = max(0, $stale_total - $state['done']);

    return [
      'done'     => (int)$state['done'],
      'total'    => (int)$state['total'],
      'errors'   => (int)$state['errors'],
      'skipped'  => (int)$state['skipped'],
      'finished' => $finished,
      'messages' => $messages,
      'meta'     => $meta,
      'stale_total' => $stale_total,
      'stale_remaining' => $stale_remaining,
    ];
  }

  private static function handle_gsc_start_request($nonce_action){
    check_ajax_referer($nonce_action, 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    $force = self::read_bool_param('force');
    $result = self::start_gsc_queue($force);
    if (is_wp_error($result)){
      wp_send_json_error(['msg'=>$result->get_error_message()]);
    }
    wp_send_json_success([
      'total' => (int) $result['total'],
      'state' => $result['state'],
      'stale_total' => isset($result['stale_total']) ? (int)$result['stale_total'] : 0,
      'stale_remaining' => isset($result['stale_remaining']) ? (int)$result['stale_remaining'] : 0,
    ]);
  }

  private static function handle_gsc_batch_request($nonce_action){
    check_ajax_referer($nonce_action, 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    $result = self::process_gsc_queue();
    if (is_wp_error($result)){
      wp_send_json_error(['msg'=>$result->get_error_message()]);
    }
    $response = [
      'i'        => (int)$result['done'],
      'total'    => (int)$result['total'],
      'done'     => (int)$result['done'],
      'errors'   => (int)$result['errors'],
      'skipped'  => (int)$result['skipped'],
      'finished' => !empty($result['finished']),
      'messages' => $result['messages'],
    ];
    if (isset($result['meta'])){
      $response['meta'] = $result['meta'];
    }
    if (isset($result['stale_total'])){
      $response['stale_total'] = (int)$result['stale_total'];
    }
    if (isset($result['stale_remaining'])){
      $response['stale_remaining'] = (int)$result['stale_remaining'];
    }
    wp_send_json_success($response);
  }

  static function ajax_gindex_start(){
    self::handle_gsc_start_request('mw_audit_gindex_start');
  }

  static function ajax_gindex_step(){
    self::handle_gsc_batch_request('mw_audit_gindex_step');
  }

  static function ajax_gsc_enqueue_all(){
    self::handle_gsc_start_request('mw_gsc_enqueue_all');
  }

  static function ajax_gsc_process_batch(){
    self::handle_gsc_batch_request('mw_gsc_process_batch');
  }

  static function ajax_gsc_reset_queue(){
    check_ajax_referer('mw_gsc_reset_queue','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    MW_Audit_Queue::delete(self::Q_GSC);
    self::release_lock('gindex');
    wp_send_json_success(['msg'=>__('Google index queue lock cleared.','merchant-wiki-audit')]);
  }

  static function ajax_gsc_save_ttl(){
    check_ajax_referer('mw_gsc_save_ttl','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    $ttl = absint(self::get_post_value('ttl', MW_Audit_GSC::get_ttl_hours()));
    MW_Audit_GSC::save_ttl_hours($ttl);
    wp_send_json_success(['ttl'=>MW_Audit_GSC::get_ttl_hours()]);
  }

  static function ajax_gsc_sync_pi_sheets(){
    check_ajax_referer('mw_gsc_sync_pi_sheets','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    if (!MW_Audit_GSC::is_connected()){
      wp_send_json_error(['msg'=>__('Connect Google Search Console first.','merchant-wiki-audit')]);
    }
    if (!MW_Audit_GSC::has_sheets_scope()){
      wp_send_json_error(['msg'=>__('Google Sheets access is not authorized. Use "Connect Sheets" first.','merchant-wiki-audit')]);
    }
    $sheet_input = sanitize_text_field((string) self::get_post_value('sheet', ''));
    $range_input = sanitize_text_field((string) self::get_post_value('range', ''));
    $override = self::read_bool_param('override');
    list($sheet_id, $sheet_range) = MW_Audit_GSC::normalize_sheet_reference($sheet_input, $range_input);
    if ($sheet_id === ''){
      wp_send_json_error(['msg'=>__('Specify a Google Sheet ID or URL.','merchant-wiki-audit')]);
    }
    MW_Audit_DB::set_flag('pi','running');
    $values = MW_Audit_GSC::fetch_sheet_values($sheet_id, $sheet_range);
    if (is_wp_error($values)){
      MW_Audit_DB::set_flag('pi','fail');
      wp_send_json_error(['msg'=>$values->get_error_message()]);
    }
    $import = MW_Audit_GSC::import_page_indexing_from_values($values, $override);
    if (is_wp_error($import)){
      MW_Audit_DB::set_flag('pi','fail');
      wp_send_json_error(['msg'=>$import->get_error_message()]);
    }
    MW_Audit_DB::set_flag('pi','done');
    wp_send_json_success([
      'imported' => (int)($import['imported'] ?? 0),
      'skipped'  => (int)($import['skipped'] ?? 0),
    ]);
  }

  static function ajax_gsc_assemble_pi_sheet(){
    check_ajax_referer('mw_gsc_assemble_pi_sheet','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    if (!MW_Audit_GSC::is_connected()){
      wp_send_json_error(['msg'=>__('Connect Google Search Console first.','merchant-wiki-audit')]);
    }
    if (!MW_Audit_GSC::has_sheets_scope()){
      wp_send_json_error(['msg'=>__('Google Sheets access is not authorized. Use "Connect Sheets" first.','merchant-wiki-audit')]);
    }
    $raw = sanitize_textarea_field((string) self::get_post_value('sources', ''));
    $list = array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw)));
    if (!$list){
      wp_send_json_error(['msg'=>__('Enter Google Sheet URL or ID','merchant-wiki-audit')]);
    }
    $result = MW_Audit_GSC::assemble_page_indexing_sources($list);
    if (is_wp_error($result)){
      wp_send_json_error(['msg'=>$result->get_error_message()]);
    }
    wp_send_json_success([
      'sheet_id'  => $result['sheet_id'],
      'sheet_url' => $result['sheet_url'],
      'rows'      => $result['rows'],
      'sources'   => $result['sources'],
    ]);
  }

  static function action_gsc_import_pi_csv(){
    self::ensure_manage_capability();
    check_admin_referer('mw_gsc_import_pi_csv');

    $table_field = 'mw_gsc_pi_table';
    if (empty($_FILES[$table_field]) || !is_array($_FILES[$table_field])){
      // Backward compatibility with older single-field form
      $table_field = 'mw_gsc_pi_csv';
    }
    if (empty($_FILES[$table_field]) || !is_array($_FILES[$table_field])){
      self::safe_redirect(add_query_arg([
        'page' => 'mw-site-index-operations',
        'gsc_pi_import' => 'error',
        'msg' => rawurlencode(__('No file uploaded.','merchant-wiki-audit')),
      ], admin_url('admin.php')).'#mw-gsc-import');
    }

    MW_Audit_DB::set_flag('pi','running');
    require_once ABSPATH.'wp-admin/includes/file.php';
    $upload = wp_handle_upload($_FILES[$table_field], ['test_form' => false]);
    if (isset($upload['error'])){
      MW_Audit_DB::set_flag('pi','fail');
      self::safe_redirect(add_query_arg([
        'page' => 'mw-site-index-operations',
        'gsc_pi_import' => 'error',
        'msg' => rawurlencode($upload['error']),
      ], admin_url('admin.php')).'#mw-gsc-import');
    }

    $file_path = $upload['file'];
    $meta_hint = [];
    $meta_field = 'mw_gsc_pi_meta';
    $meta_upload = null;
    if (!empty($_FILES[$meta_field]) && is_array($_FILES[$meta_field]) && !empty($_FILES[$meta_field]['name'])){
      $meta_upload = wp_handle_upload($_FILES[$meta_field], ['test_form' => false]);
      if (isset($meta_upload['error'])){
        self::delete_file_if_exists($file_path);
        MW_Audit_DB::set_flag('pi','fail');
        self::safe_redirect(add_query_arg([
          'page' => 'mw-site-index-operations',
          'gsc_pi_import' => 'error',
          'msg' => rawurlencode($meta_upload['error']),
        ], admin_url('admin.php')).'#mw-gsc-import');
      }
      $meta_data = MW_Audit_GSC::parse_page_indexing_metadata_csv($meta_upload['file']);
      if (is_wp_error($meta_data)){
        self::delete_file_if_exists($file_path);
        self::delete_file_if_exists($meta_upload['file']);
        MW_Audit_DB::set_flag('pi','fail');
        self::safe_redirect(add_query_arg([
          'page' => 'mw-site-index-operations',
          'gsc_pi_import' => 'error',
          'msg' => rawurlencode($meta_data->get_error_message()),
        ], admin_url('admin.php')).'#mw-gsc-import');
      }
      $meta_hint = $meta_data;
    }

    $override = self::read_bool_param('override');
    $parsed = MW_Audit_GSC::parse_page_indexing_csv($file_path);
    if (is_wp_error($parsed)){
      self::delete_file_if_exists($file_path);
      if ($meta_upload){
        self::delete_file_if_exists($meta_upload['file']);
      }
      MW_Audit_DB::set_flag('pi','fail');
      self::safe_redirect(add_query_arg([
        'page' => 'mw-site-index-operations',
        'gsc_pi_import' => 'error',
        'msg' => rawurlencode($parsed->get_error_message()),
      ], admin_url('admin.php')).'#mw-gsc-import');
    }

    $import = MW_Audit_GSC::import_page_indexing_records($parsed['rows'], $parsed['mapping'], $override, $meta_hint);
    self::delete_file_if_exists($file_path);
    if ($meta_upload){
      self::delete_file_if_exists($meta_upload['file']);
    }
    if (is_wp_error($import)){
      MW_Audit_DB::set_flag('pi','fail');
      self::safe_redirect(add_query_arg([
        'page' => 'mw-site-index-operations',
        'gsc_pi_import' => 'error',
        'msg' => rawurlencode($import->get_error_message()),
      ], admin_url('admin.php')).'#mw-gsc-import');
    }

    MW_Audit_DB::set_flag('pi','done');

    self::safe_redirect(add_query_arg([
      'page'     => 'mw-site-index-operations',
      'gsc_pi_import' => 'success',
      'imported' => (int)($import['imported'] ?? 0),
      'skipped'  => (int)($import['skipped'] ?? 0),
    ], admin_url('admin.php')).'#mw-gsc-import');
  }

  static function action_gsc_export_likely_not_indexed_csv(){
    self::ensure_manage_capability();
    check_admin_referer('mw_gsc_export_likely_not_indexed_csv');
    $states = MW_Audit_GSC::get_likely_not_indexed_reasons();
    $rows = MW_Audit_DB::get_likely_not_indexed_urls($states);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mw-gsc-likely-not-indexed.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['URL','Reason','Last seen','Source']);
    foreach ($rows as $row){
      $reason = $row['reason'] ?? '';
      if ($reason === '' && !empty($row['coverage_state'])){
        $reason = $row['coverage_state'];
      }
      fputcsv($out, [
        $row['norm_url'] ?? '',
        $reason,
        $row['last_seen'] ?? '',
        $row['source'] ?? '',
      ]);
    }
    exit;
  }

  static function ajax_similar_seed(){
    check_ajax_referer('mw_audit_similar_seed','nonce');
    if (!current_user_can('manage_options')){
      wp_send_json_error(['msg'=>__('Not allowed.','merchant-wiki-audit')], 403);
    }
    $url_raw = self::get_post_value('url', '');
    $url = esc_url_raw(trim((string) $url_raw));
    if ($url === ''){
      wp_send_json_error(['msg'=>__('Enter a URL first.','merchant-wiki-audit')]);
    }
    $candidates = array_unique([$url, trailingslashit($url), untrailingslashit($url)]);
    $row = null;
    $matched_url = $url;
    foreach ($candidates as $candidate){
      if ($candidate === ''){
        continue;
      }
      $data = MW_Audit_DB::get_status_row_for_url($candidate);
      if ($data){
        $row = $data;
        $matched_url = $candidate;
        break;
      }
    }
    if (!$row){
      wp_send_json_error(['msg'=>__('URL not found in the inventory. Run “Rebuild Inventory” and “Refresh On-Site Signals” first.','merchant-wiki-audit')]);
    }
    $baseline = [
      'url'                 => $matched_url ?: ($row['norm_url'] ?? ''),
      'http_status'         => isset($row['http_status']) ? (int) $row['http_status'] : null,
      'in_sitemap'          => array_key_exists('in_sitemap', $row) ? (int) $row['in_sitemap'] : null,
      'noindex'             => array_key_exists('noindex', $row) ? (int) $row['noindex'] : null,
      'indexed_in_google'   => array_key_exists('indexed_in_google', $row) && $row['indexed_in_google'] !== null ? (int) $row['indexed_in_google'] : null,
      'inbound_links'       => array_key_exists('inbound_links', $row) ? (int) $row['inbound_links'] : null,
      'days_since_update'   => array_key_exists('days_since_update', $row) ? (int) $row['days_since_update'] : null,
      'pc_name'             => $row['pc_name'] ?? '',
      'pc_path'             => $row['pc_path'] ?? '',
      'gsc_coverage_page'   => $row['gsc_coverage_page'] ?? '',
      'gsc_coverage_inspection' => $row['gsc_coverage_inspection'] ?? '',
      'gsc_verdict'         => $row['gsc_verdict'] ?? '',
      'gsc_pi_reason'       => $row['gsc_pi_reason'] ?? '',
      'gsc_reason_inspection'=> $row['gsc_reason_inspection'] ?? '',
    ];
    $inbound_value = isset($baseline['inbound_links']) && $baseline['inbound_links'] !== null ? max(0, (int) $baseline['inbound_links']) : 0;
    $default_inbound_min = max(0, $inbound_value - 2);
    $default_inbound_max = $inbound_value + 2;
    $age_value = isset($baseline['days_since_update']) && $baseline['days_since_update'] !== null ? max(0, (int) $baseline['days_since_update']) : 365;
    $age_padding = max(14, (int) round($age_value * 0.25));
    $default_age_min = max(0, $age_value - $age_padding);
    $default_age_max = $age_value + $age_padding;
    $suggested = [
      'http_status' => $baseline['http_status'] !== null,
      'in_sitemap'  => $baseline['in_sitemap'] !== null,
      'noindex'     => true,
      'indexed'     => $baseline['indexed_in_google'] !== null,
      'pc_path'     => ($baseline['pc_path'] ?? '') !== '',
      'inbound'     => true,
      'age'         => $baseline['days_since_update'] !== null,
    ];
    wp_send_json_success([
      'baseline' => $baseline,
      'defaults' => [
        'inbound' => [
          'min' => $default_inbound_min,
          'max' => $default_inbound_max,
          'baseline' => $inbound_value,
        ],
        'age' => [
          'min' => $default_age_min,
          'max' => $default_age_max,
          'baseline' => $age_value,
        ],
      ],
      'suggested' => $suggested,
    ]);
  }

  static function ajax_similar_query(){
    check_ajax_referer('mw_audit_similar_query','nonce');
    if (!current_user_can('manage_options')){
      wp_send_json_error(['msg'=>__('Not allowed.','merchant-wiki-audit')], 403);
    }
    $raw = self::read_raw_post_string('criteria');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)){
      wp_send_json_error(['msg'=>__('Invalid request payload.','merchant-wiki-audit')]);
    }
    $criteria = self::sanitize_similar_request($decoded);
    if (is_wp_error($criteria)){
      wp_send_json_error(['msg'=>$criteria->get_error_message()]);
    }
    $result = MW_Audit_DB::find_similar_rows($criteria['query'], $criteria['limit'], $criteria['offset']);
    wp_send_json_success([
      'rows'    => $result['rows'],
      'total'   => (int) $result['total'],
      'limit'   => $criteria['limit'],
      'offset'  => $criteria['offset'],
      'applied' => $criteria['applied'],
      'criteria'=> $criteria['query'],
    ]);
  }

  static function ajax_priority_list(){
    check_ajax_referer('mw_audit_priority_list','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>__('Not allowed','merchant-wiki-audit')]);
    $threshold = self::read_priority_threshold();
    $page = max(1, absint(self::get_post_value('paged', 1)));
    $per_page = absint(self::get_post_value('per_page', 20));
    $per_page = max(1, min(100, $per_page));
    $offset = ($page - 1) * $per_page;
    $orderby = sanitize_key((string) self::get_post_value('orderby', 'inbound_links'));
    $order_raw = strtoupper(trim((string) self::get_post_value('order', 'ASC')));
    $order = $order_raw === 'DESC' ? 'DESC' : 'ASC';

    $rows = MW_Audit_DB::get_priority_ready_rows($threshold, $per_page, $offset, $orderby, $order);
    $total = MW_Audit_DB::count_priority_ready($threshold);
    $pages = $per_page ? (int) ceil($total / $per_page) : 1;
    $formatted = array_map([__CLASS__, 'format_priority_row_for_response'], $rows);

    wp_send_json_success([
      'rows'      => $formatted,
      'total'     => $total,
      'pages'     => max(1, $pages),
      'page'      => $page,
      'threshold' => $threshold,
    ]);
  }

  static function action_priority_export(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_priority_export');
    $threshold = self::read_priority_threshold();
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mw-priority-ready.csv"');
    $out = fopen('php://output', 'w');
    if (!$out){
      wp_die(esc_html__('Unable to open output stream.','merchant-wiki-audit'));
    }
    self::extend_time_limit(0);
    fputcsv($out, [
      'URL',
      'Inbound links',
      'HTTP status',
      'In sitemap',
      'Noindex',
      'Published at',
      'Primary category',
      'Primary path',
      'Canonical',
      'GSC source',
      'GSC coverage',
      'GSC reason',
      'GSC inspected at',
    ]);
    $chunk = 500;
    $offset = 0;
    do {
      $rows = MW_Audit_DB::get_priority_ready_rows($threshold, $chunk, $offset, 'inbound_links', 'ASC');
      if (!$rows){
        break;
      }
      foreach ($rows as $row){
        fputcsv($out, self::format_priority_row_for_csv($row));
      }
      $offset += $chunk;
    } while (count($rows) === $chunk);
    exit;
  }

  static function action_similar_export(){
    self::ensure_manage_capability();
    check_admin_referer('mw_audit_similar_export', 'mw_audit_similar_export_nonce');
    $raw = self::read_raw_post_string('criteria');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded) || empty($decoded['criteria'])){
      wp_die(esc_html__('Invalid export request. Refresh the page and try again.','merchant-wiki-audit'));
    }
    $query = self::normalize_similar_query((array) $decoded['criteria']);
    if (is_wp_error($query)){
      wp_die(esc_html($query->get_error_message()));
    }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mw-find-similar.csv"');
    $out = fopen('php://output', 'w');
    if (!$out){
      wp_die(esc_html__('Unable to open output stream.','merchant-wiki-audit'));
    }
    fputcsv($out, [
      'URL',
      'HTTP status',
      'In sitemap',
      'Noindex',
      'Indexed in Google',
      'Inbound links',
      'Days since last update',
      'Primary category',
      'Primary path',
      'Page indexing coverage',
      'Page indexing reason',
      'Inspection coverage',
      'Inspection reason',
      'Inspection verdict',
      'Page indexing inspected at',
      'Inspection inspected at',
      'Similarity score',
    ]);
    $chunk = 500;
    $offset = 0;
    do {
      $rows = MW_Audit_DB::get_similar_rows_chunk($query, $chunk, $offset);
      if (!$rows){
        break;
      }
      foreach ($rows as $row){
        fputcsv($out, self::format_similar_row_for_csv($row));
      }
      $offset += count($rows);
    } while (count($rows) === $chunk);
    exit;
  }

  private static function normalize_priority_row(array $row){
    $url = esc_url_raw($row['norm_url'] ?? '');
    $published = isset($row['published_at']) ? $row['published_at'] : '';
    $inspected = isset($row['gsc_inspected_at']) ? $row['gsc_inspected_at'] : '';
    return [
      'url'         => $url,
      'http_status' => (int) ($row['http_status'] ?? 0),
      'in_sitemap'  => (int) ($row['in_sitemap'] ?? 0),
      'noindex'     => (int) ($row['noindex'] ?? 0),
      'inbound_links'=> (int) ($row['inbound_links'] ?? 0),
      'published_at'=> $published,
      'canonical'   => esc_url_raw($row['canonical'] ?? ''),
      'pc_name'     => sanitize_text_field($row['pc_name'] ?? ''),
      'pc_path'     => sanitize_text_field($row['pc_path'] ?? ''),
      'gsc_source'  => sanitize_key($row['gsc_source'] ?? ''),
      'gsc_coverage'=> sanitize_text_field($row['gsc_coverage'] ?? ''),
      'gsc_reason'  => sanitize_text_field($row['gsc_reason'] ?? ''),
      'gsc_inspected_at' => $inspected,
    ];
  }

  private static function format_priority_row_for_response(array $row){
    $normalized = self::normalize_priority_row($row);
    $normalized['published_at_display'] = ($normalized['published_at'] && $normalized['published_at'] !== '0000-00-00 00:00:00')
      ? mysql2date('Y-m-d H:i', $normalized['published_at'])
      : '';
    $normalized['gsc_inspected_at_display'] = ($normalized['gsc_inspected_at'] && $normalized['gsc_inspected_at'] !== '0000-00-00 00:00:00')
      ? mysql2date('Y-m-d H:i', $normalized['gsc_inspected_at'])
      : '';
    if (!empty($normalized['gsc_source'])){
      if ($normalized['gsc_source'] === 'inspection'){
        $normalized['gsc_source_label'] = __('Inspection API','merchant-wiki-audit');
      } elseif ($normalized['gsc_source'] === 'page_indexing'){
        $normalized['gsc_source_label'] = __('Page indexing','merchant-wiki-audit');
      }
    } else {
      $normalized['gsc_source_label'] = '';
    }
    return $normalized;
  }

  private static function format_priority_row_for_csv(array $row){
    $normalized = self::format_priority_row_for_response($row);
    return [
      $normalized['url'],
      $normalized['inbound_links'],
      $normalized['http_status'],
      $normalized['in_sitemap'],
      $normalized['noindex'],
      $normalized['published_at'],
      $normalized['pc_name'],
      $normalized['pc_path'],
      $normalized['canonical'],
      $normalized['gsc_source_label'] ?: $normalized['gsc_source'],
      $normalized['gsc_coverage'],
      $normalized['gsc_reason'],
      $normalized['gsc_inspected_at'],
    ];
  }

  private static function format_similar_row_for_csv(array $row){
    $url = esc_url_raw($row['norm_url'] ?? '');
    $http = isset($row['http_status']) ? (int) $row['http_status'] : '';
    $in_sitemap = array_key_exists('in_sitemap', $row) ? (int) $row['in_sitemap'] : '';
    $noindex = array_key_exists('noindex', $row) ? (int) $row['noindex'] : '';
    $indexed = array_key_exists('indexed_in_google', $row) && $row['indexed_in_google'] !== null ? (int) $row['indexed_in_google'] : '';
    $inbound = array_key_exists('inbound_links', $row) && $row['inbound_links'] !== null ? (int) $row['inbound_links'] : '';
    $days = array_key_exists('days_since_update', $row) && $row['days_since_update'] !== null ? (int) $row['days_since_update'] : '';
    $similarity = array_key_exists('similarity_score', $row) ? (float) $row['similarity_score'] : 0;
    return [
      $url,
      $http,
      $in_sitemap,
      $noindex,
      $indexed,
      $inbound,
      $days,
      $row['pc_name'] ?? '',
      $row['pc_path'] ?? '',
      $row['gsc_coverage_page'] ?? '',
      $row['gsc_reason_page'] ?? '',
      $row['gsc_coverage_inspection'] ?? '',
      $row['gsc_reason_inspection'] ?? '',
      $row['gsc_verdict'] ?? '',
      $row['gsc_pi_inspected_at'] ?? '',
      $row['gsc_inspected_at'] ?? '',
      round($similarity, 2),
    ];
  }

  // Low-level scanner
  // Build PC map helpers
}
