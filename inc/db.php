<?php
if (!defined('ABSPATH')) exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class MW_Audit_DB {
  const SETTINGS_OPTION = 'mw_audit_settings';
  const LEGACY_SETTINGS_OPTION = 'mwa_settings';
  private static $column_exists_cache = [];
  private static $table_exists_cache = [];
  static function log($msg){
    if (defined('MW_AUDIT_DEBUG') && MW_AUDIT_DEBUG){
      // error_log('[MW Audit DBG] '.$msg);
    }
  }
  static function t_inventory(){ global $wpdb; return $wpdb->prefix.'mw_url_inventory'; }
  static function t_status(){ global $wpdb; return $wpdb->prefix.'mw_url_status'; }
  static function t_pc(){ global $wpdb; return $wpdb->prefix.'mw_post_primary_category'; }
  static function t_gsc_cache(){ global $wpdb; return $wpdb->prefix.'mw_gsc_cache'; }
  static function t_outbound(){ global $wpdb; return $wpdb->prefix.'mw_outbound_links'; }
  public static function table_prefix(){
    global $wpdb;
    return $wpdb->prefix;
  }
  public static function options_table(){
    global $wpdb;
    return $wpdb->options;
  }
  public static function last_error(){
    global $wpdb;
    return $wpdb->last_error;
  }
  public static function posts_table(){
    global $wpdb;
    return $wpdb->posts;
  }
  public static function terms_table(){
    global $wpdb;
    return $wpdb->terms;
  }
  public static function term_taxonomy_table(){
    global $wpdb;
    return $wpdb->term_taxonomy;
  }
  public static function esc_like($text){
    global $wpdb;
    return $wpdb->esc_like($text);
  }
  public static function replace_row($table, array $data, array $formats = null){
    global $wpdb;
    if ($formats === null){
      return $wpdb->replace($table, $data);
    }
    return $wpdb->replace($table, $data, $formats);
  }

  private static function sanitize_table_name($table){
    $table = (string) $table;
    return preg_replace('/[^A-Za-z0-9_]/', '', $table);
  }

  public static function esc_table($table){
    $sanitized = self::sanitize_table_name($table);
    return $sanitized ? "`{$sanitized}`" : '';
  }

  public static function table_exists($table){
    global $wpdb;
    $sanitized = self::sanitize_table_name($table);
    if ($sanitized === ''){
      return false;
    }
    if (isset(self::$table_exists_cache[$sanitized])){
      return self::$table_exists_cache[$sanitized];
    }
    $like = $wpdb->esc_like($sanitized);
    $result = self::get_var_sql('SHOW TABLES LIKE %s', [$like]);
    $exists = ($result === $sanitized);
    self::$table_exists_cache[$sanitized] = $exists;
    return $exists;
  }

  public static function prepare($sql, array $params = []){
    global $wpdb;
    if (!$params){
      return $sql;
    }
    return call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $params));
  }

  public static function get_results_sql($query, array $params = [], $output_type = ARRAY_A){
    global $wpdb;
    if ($params){
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $query = call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $params));
    }
    return $wpdb->get_results($query, $output_type); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  public static function get_row_sql($query, array $params = [], $output_type = ARRAY_A){
    global $wpdb;
    if ($params){
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $query = call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $params));
    }
    return $wpdb->get_row($query, $output_type); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  public static function get_var_sql($query, array $params = []){
    global $wpdb;
    if ($params){
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $query = call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $params));
    }
    return $wpdb->get_var($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  public static function get_col_sql($query, array $params = [], $column = 0){
    global $wpdb;
    if ($params){
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $query = call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $params));
    }
    return $wpdb->get_col($query, $column); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  public static function query_sql($query, array $params = []){
    global $wpdb;
    if ($params){
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $query = call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $params));
    }
    return $wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
  }

  static function default_settings(){
    return [
      'timeouts' => ['head'=>8, 'get'=>12],
      'profile_defaults' => 'standard',
      'ttl' => ['export_hours'=>48, 'api_hours'=>24],
      'gsc_api_enabled' => false,
      'gdrive_export_enabled' => false,
      'gsc_import_mode' => 'csv',
      'modified_after' => null,
      'modified_before' => null,
    ];
  }

  static function get_settings(){
    $defaults = self::default_settings();
    $sentinel = '__mw_audit_missing__';
    $stored = get_option(self::SETTINGS_OPTION, $sentinel);
    if ($stored === $sentinel){
      $legacy = get_option(self::LEGACY_SETTINGS_OPTION, $sentinel);
      if ($legacy !== $sentinel){
        $stored = $legacy;
        update_option(self::SETTINGS_OPTION, $legacy, false);
        delete_option(self::LEGACY_SETTINGS_OPTION);
      } else {
        $stored = [];
      }
    }
    if (!is_array($stored)){
      $stored = [];
    }
    $settings = array_replace_recursive($defaults, $stored);
    $settings['timeouts']['head'] = max(1, (int) ($settings['timeouts']['head'] ?? $defaults['timeouts']['head']));
    $settings['timeouts']['get']  = max(1, (int) ($settings['timeouts']['get'] ?? $defaults['timeouts']['get']));
    $settings['profile_defaults'] = in_array($settings['profile_defaults'], ['fast','standard','safe'], true) ? $settings['profile_defaults'] : 'standard';
    $settings['ttl']['export_hours'] = max(1, (int) ($settings['ttl']['export_hours'] ?? $defaults['ttl']['export_hours']));
    $settings['ttl']['api_hours']    = max(1, (int) ($settings['ttl']['api_hours'] ?? $defaults['ttl']['api_hours']));
    $settings['gsc_api_enabled'] = !empty($settings['gsc_api_enabled']);
    $settings['gdrive_export_enabled'] = !empty($settings['gdrive_export_enabled']);
    $settings['gsc_import_mode'] = in_array($settings['gsc_import_mode'], ['csv','sheets'], true) ? $settings['gsc_import_mode'] : 'csv';
    return $settings;
  }

  static function update_settings(array $settings){
    $merged = array_replace_recursive(self::default_settings(), $settings);
    $merged['timeouts']['head'] = max(1, (int) ($merged['timeouts']['head'] ?? 8));
    $merged['timeouts']['get']  = max(1, (int) ($merged['timeouts']['get'] ?? 12));
    $merged['profile_defaults'] = in_array($merged['profile_defaults'], ['fast','standard','safe'], true) ? $merged['profile_defaults'] : 'standard';
    $merged['ttl']['export_hours'] = max(1, (int) ($merged['ttl']['export_hours'] ?? 48));
    $merged['ttl']['api_hours']    = max(1, (int) ($merged['ttl']['api_hours'] ?? 24));
    $merged['gsc_api_enabled'] = !empty($merged['gsc_api_enabled']);
    $merged['gdrive_export_enabled'] = !empty($merged['gdrive_export_enabled']);
    $merged['gsc_import_mode'] = in_array($merged['gsc_import_mode'], ['csv','sheets'], true) ? $merged['gsc_import_mode'] : 'csv';
    update_option(self::SETTINGS_OPTION, $merged, false);
    delete_option(self::LEGACY_SETTINGS_OPTION);
    return $merged;
  }

  static function profile_presets(){
    return [
      'fast' => [
        'batch'      => 64,
        'budget'     => 45.0,
        'head_timeout' => 10,
        'get_timeout'  => 14,
        'clamp_min'  => 4,
        'clamp_max'  => 96,
      ],
      'standard' => [
        'batch'      => 32,
        'budget'     => 35.0,
        'head_timeout' => 8,
        'get_timeout'  => 12,
        'clamp_min'  => 4,
        'clamp_max'  => 64,
      ],
      'safe' => [
        'batch'      => 16,
        'budget'     => 25.0,
        'head_timeout' => 6,
        'get_timeout'  => 10,
        'clamp_min'  => 4,
        'clamp_max'  => 48,
      ],
    ];
  }

  // Expectations for health check (soft)
  static function schema_expectations(){
    return [
      'inv' => [
        'table' => self::t_inventory(),
        'columns' => [
          'id'=>'bigint','norm_url'=>'text','obj_type'=>'varchar','obj_id'=>'bigint','slug'=>'varchar','created_at'=>'datetime','published_at'=>'datetime'
        ],
        'indexes' => [
          ['type'=>'PRIMARY','columns'=>['id']],
          ['type'=>'KEY','name'=>'idx_obj','columns'=>['obj_type','obj_id']],
          ['type'=>'KEY','name'=>'idx_slug','columns'=>['slug']],
        ],
      ],
      'st' => [
        'table' => self::t_status(),
        'columns' => [
          'id'=>'bigint','norm_url'=>'text','http_status'=>'smallint','redirect_to'=>'text','canonical'=>'text','robots_meta'=>'varchar',
          'noindex'=>'tinyint','schema_type'=>'varchar','in_sitemap'=>'tinyint','robots_disallow'=>'tinyint','inbound_links'=>'int','indexed_in_google'=>'tinyint','updated_at'=>'datetime'
        ],
        'indexes' => [
          ['type'=>'PRIMARY','columns'=>['id']],
          ['type'=>'KEY','name'=>'idx_url','columns'=>['norm_url']],
        ],
      ],
      'pc' => [
        'table' => self::t_pc(),
        'columns' => [
          'post_id'=>'bigint','post_type'=>'varchar','permalink'=>'text','pc_term_id'=>'bigint','pc_taxonomy'=>'varchar',
          'pc_slug'=>'varchar','pc_name'=>'varchar','pc_parent_id'=>'bigint','pc_path'=>'varchar|text','map_source'=>'varchar','updated_at'=>'datetime'
        ],
        'indexes' => [
          ['type'=>'PRIMARY','columns'=>['post_id']],
          ['type'=>'KEY','name'=>'idx_tax','columns'=>['pc_taxonomy','pc_term_id']],
        ],
      ],
      'gsc' => [
        'table' => self::t_gsc_cache(),
        'columns' => [
          'norm_url'=>'varchar','source'=>'enum','verdict'=>'varchar','coverage_state'=>'varchar','last_crawl_time'=>'datetime','inspected_at'=>'datetime','ttl_until'=>'datetime','pi_reason_raw'=>'text','attempts'=>'int','last_error'=>'text'
        ],
        'indexes' => [
          ['type'=>'PRIMARY','columns'=>['norm_url','source']],
          ['type'=>'KEY','name'=>'idx_ttl','columns'=>['ttl_until']],
          ['type'=>'KEY','name'=>'idx_verdict','columns'=>['verdict']],
          ['type'=>'KEY','name'=>'idx_cov','columns'=>['coverage_state']],
          ['type'=>'KEY','name'=>'idx_inspected','columns'=>['inspected_at']],
        ],
      ],
      'out' => [
        'table' => self::t_outbound(),
        'columns' => [
          'id'=>'bigint','norm_url'=>'text','outbound_internal'=>'int','outbound_external'=>'int','outbound_external_domains'=>'int','last_scanned'=>'datetime'
        ],
        'indexes' => [
          ['type'=>'PRIMARY','columns'=>['id']],
          ['type'=>'UNIQUE','name'=>'uniq_out_url','columns'=>['norm_url']],
        ],
      ],
    ];
  }

  // Create tables if missing (do not alter existing)
  static function ensure_tables_if_missing(){
    global $wpdb;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $collate = $wpdb->get_charset_collate();

    $defs = [
      self::t_inventory() => "CREATE TABLE ".self::t_inventory()."(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        norm_url TEXT NOT NULL,
        obj_type VARCHAR(20) NULL,
        obj_id BIGINT UNSIGNED NULL,
        slug VARCHAR(190) NULL,
        created_at DATETIME NULL,
        published_at DATETIME NULL COMMENT 'Publication timestamp sourced from wp_posts.post_date',
        PRIMARY KEY(id),
        KEY idx_obj (obj_type,obj_id),
        KEY idx_slug (slug(64))
      ) $collate;",
      self::t_status() => "CREATE TABLE ".self::t_status()."(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        norm_url TEXT NOT NULL,
        http_status SMALLINT NULL,
        redirect_to TEXT NULL,
        canonical TEXT NULL,
        robots_meta VARCHAR(255) NULL,
        noindex TINYINT(1) NULL,
        schema_type VARCHAR(100) NULL,
        in_sitemap TINYINT(1) NULL,
        robots_disallow TINYINT(1) NULL,
        inbound_links INT NULL,
        indexed_in_google TINYINT(1) NULL COMMENT '1 = indexed, 0 = not indexed, NULL = unknown/unchecked',
        updated_at DATETIME NULL,
        PRIMARY KEY(id),
        KEY idx_url (norm_url(191))
      ) $collate;",
      self::t_pc() => "CREATE TABLE ".self::t_pc()."(
        post_id BIGINT UNSIGNED NOT NULL,
        post_type VARCHAR(20) NOT NULL,
        permalink TEXT NULL,
        pc_term_id BIGINT UNSIGNED NULL,
        pc_taxonomy VARCHAR(64) NULL,
        pc_slug VARCHAR(200) NULL,
        pc_name VARCHAR(255) NULL,
        pc_parent_id BIGINT UNSIGNED NULL,
        pc_path VARCHAR(500) NULL,
        map_source VARCHAR(64) NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY(post_id),
        KEY idx_tax (pc_taxonomy, pc_term_id)
      ) $collate;",
      self::t_gsc_cache() => "CREATE TABLE ".self::t_gsc_cache()."(
        norm_url VARCHAR(191) NOT NULL,
        source ENUM('inspection','page_indexing') NOT NULL DEFAULT 'inspection',
        verdict VARCHAR(64) NULL,
        coverage_state VARCHAR(128) NULL,
        reason_label VARCHAR(64) NULL,
        http_status VARCHAR(16) NULL,
        robots_txt_state VARCHAR(64) NULL,
        last_crawl_time DATETIME NULL,
        inspected_at DATETIME NULL,
        ttl_until DATETIME NULL,
        pi_reason_raw TEXT NULL,
        payload MEDIUMTEXT NULL,
        notes TEXT NULL,
        attempts INT NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (norm_url, source),
        KEY idx_src_ttl (source, ttl_until),
        KEY idx_ttl (ttl_until),
        KEY idx_verdict (verdict),
        KEY idx_reason (reason_label),
        KEY idx_cov (coverage_state),
        KEY idx_inspected (inspected_at)
      ) $collate;",
      self::t_outbound() => "CREATE TABLE ".self::t_outbound()."(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        norm_url TEXT NOT NULL,
        outbound_internal INT NULL,
        outbound_external INT NULL,
        outbound_external_domains INT NULL,
        last_scanned DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_out_url (norm_url(191))
      ) $collate;",
    ];

    foreach ($defs as $table => $sql){
      $exists = self::table_exists($table);
      if (!$exists) {
        dbDelta($sql);
        self::log('created table: '.$table.' | error='.($wpdb->last_error?:''));
      } else {
        self::maybe_add_columns($table, $sql);
        self::maybe_add_indexes($table, $sql);
      }
    }
  }

  private static function maybe_add_columns($table, $createSql){
    global $wpdb;
    $table_sql = self::esc_table($table);
    if ($table_sql === ''){
      return;
    }
    $expected = [];
    $match = [];
    if (preg_match_all('/\n\s*([a-z_]+) [A-Z]+\b/mi', $createSql, $match)){
      foreach ($match[1] as $col){
        $expected[] = strtolower(trim($col));
      }
    }
    if (!$expected) return;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $existing = self::get_col_sql("SHOW COLUMNS FROM {$table_sql}", [], 0);
    if (!$existing) return;
    $existing = array_map('strtolower', $existing);
    foreach ($expected as $col){
      if (!in_array($col, $existing, true)){
        $columnDef = null;
        if (preg_match('/\n\s*'.preg_quote($col,'/').' ([^,\n]+)/i', $createSql, $m)){
          $columnDef = trim($m[1]);
        }
        if ($columnDef){
          $col_sql = '`'.preg_replace('/[^A-Za-z0-9_]/', '', $col).'`';
          // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
          self::query_sql("ALTER TABLE {$table_sql} ADD COLUMN {$col_sql} {$columnDef}");
          self::log('added column '.$col.' to '.$table.' | error='.($wpdb->last_error?:''));
        }
      }
    }
  }

  private static function maybe_add_indexes($table, $createSql){
    global $wpdb;
    $table_sql = self::esc_table($table);
    if ($table_sql === ''){
      return;
    }
    $indexes = [];
    if (preg_match_all('/KEY\s+`?([a-z0-9_]+)`?\s*\(([^)]+)\)/i', $createSql, $m, PREG_SET_ORDER)){
      foreach ($m as $match){
        $name = strtolower(trim($match[1]));
        if ($name === 'primary') continue;
        $indexes[$name] = trim($match[2]);
      }
    }
    if (!$indexes){
      return;
    }
    $existing = [];
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = self::get_results_sql("SHOW INDEX FROM {$table_sql}", [], ARRAY_A);
    foreach ($rows as $row){
      $name = strtolower($row['Key_name']);
      if ($name){
        $existing[$name] = true;
      }
    }
    foreach ($indexes as $name => $definition){
      if (isset($existing[$name])){
        continue;
      }
      $definition = preg_replace('/\s+/', ' ', $definition);
      $index_sql = '`'.preg_replace('/[^A-Za-z0-9_]/', '', $name).'`';
      $sql = "ALTER TABLE {$table_sql} ADD KEY {$index_sql} ($definition)";
      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      self::query_sql($sql);
      self::log('added index '.$name.' to '.$table.' | error='.($wpdb->last_error?:''));
    }
  }

  // Full schema & privileges check
  static function check_schema_full(){
    global $wpdb;
    $exp = self::schema_expectations();
    $report = ['ok'=>true, 'tables'=>[]];

    foreach ($exp as $key => $spec){
      $table = $spec['table'];
      $t = ['table'=>$table, 'exists'=>false, 'columns_ok'=>true, 'indexes_ok'=>true, 'can_select'=>true, 'can_write'=>true, 'issues'=>[]];

      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
      $t['exists'] = ($exists === $table);
      if (!$t['exists']) { $t['issues'][] = 'Table missing'; $report['ok']=false; $report['tables'][$key]=$t; continue; }

      $cols = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
      $have = [];
      foreach ($cols as $c){ $have[strtolower($c['Field'])] = strtolower($c['Type']); }
      foreach ($spec['columns'] as $name => $type){
        $ln = strtolower($name);
        if (!isset($have[$ln])) { $t['columns_ok']=false; $t['issues'][]="Missing column: $ln"; continue; }
      }

      $idxRows = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
      $byName = [];
      foreach ($idxRows as $r){
        $name = $r['Key_name'];
        if (!isset($byName[$name])) $byName[$name] = [];
        $byName[$name][] = strtolower($r['Column_name']);
      }
      foreach ($spec['indexes'] as $idx){
        if ($idx['type']==='PRIMARY'){
          if (!isset($byName['PRIMARY'])) { $t['indexes_ok']=false; $t['issues'][]='Missing PRIMARY KEY'; }
        } else {
          $name = $idx['name'];
          if (!isset($byName[$name])) { $t['indexes_ok']=false; $t['issues'][]="Missing index: $name"; }
        }
      }

      // privileges
      $wpdb->get_var("SELECT 1 FROM $table LIMIT 1"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
      $t['can_select'] = ($wpdb->last_error==='');
      $t['can_write']  = self::probe_write($table);

      if (!$t['columns_ok'] || !$t['indexes_ok'] || !$t['can_select'] || !$t['can_write']) $report['ok']=false;
      $report['tables'][$key] = $t;
    }
    return $report;
  }

  private static function probe_write($table){
    global $wpdb;
    $ok = true;
    if ($table === self::t_inventory()){
      $ok = $wpdb->insert($table, [
        'norm_url'=>home_url('/mw-audit-probe'),
        'obj_type'=>'probe',
        'obj_id'=>0,
        'slug'=>'mw-audit-probe',
        'created_at'=>current_time('mysql')
      ]) !== false;
      if ($ok) $wpdb->delete($table, ['obj_type'=>'probe','obj_id'=>0]);
    } elseif ($table === self::t_status()){
      $ok = $wpdb->insert($table, [
        'norm_url'=>home_url('/mw-audit-probe'),
        'http_status'=>200,
        'updated_at'=>current_time('mysql')
      ]) !== false;
      if ($ok) $wpdb->delete($table, ['norm_url'=>home_url('/mw-audit-probe')]);
    } elseif ($table === self::t_pc()){
      $ok = $wpdb->replace($table, [
        'post_id'=>0,'post_type'=>'post','permalink'=>home_url('/mw-audit-probe'),
        'pc_term_id'=>0,'pc_taxonomy'=>'category','pc_slug'=>null,'pc_name'=>null,
        'pc_parent_id'=>null,'pc_path'=>null,'map_source'=>'probe','updated_at'=>current_time('mysql')
      ]) !== false;
      if ($ok) $wpdb->delete($table, ['post_id'=>0]);
    } elseif ($table === self::t_gsc_cache()){
      $probe_url = home_url('/mw-audit-probe');
      $ok = $wpdb->replace($table, [
        'norm_url'      => $probe_url,
        'source'        => 'inspection',
        'inspected_at'  => current_time('mysql'),
        'ttl_until'     => current_time('mysql'),
        'attempts'      => 0,
        'last_error'    => null,
      ]) !== false;
      if ($ok) {
        $wpdb->delete($table, ['norm_url'=>$probe_url, 'source'=>'inspection']);
      }
    } else {
      return false;
    }
    return $ok && !$wpdb->last_error;
  }

  // Data helpers
  static function truncate_inventory(){
    self::query_sql("TRUNCATE TABLE ".self::esc_table(self::t_inventory()));
  }
  static function delete_all(){
    global $wpdb;
    self::query_sql("TRUNCATE TABLE ".self::esc_table(self::t_inventory()));
    self::query_sql("TRUNCATE TABLE ".self::esc_table(self::t_status()));
    self::query_sql("TRUNCATE TABLE ".self::esc_table(self::t_pc()));
  }

  static function insert_inventory($rows){
    global $wpdb; $t=self::t_inventory();
    if (!$rows) return;
    foreach ($rows as $r){
      $published = null;
      if (!empty($r['published_at'])){
        $published = sanitize_text_field($r['published_at']);
      }
      $wpdb->insert($t, [
        'norm_url'=>isset($r['norm_url']) ? $r['norm_url'] : '',
        'obj_type'=>isset($r['obj_type']) ? $r['obj_type'] : null,
        'obj_id'=>isset($r['obj_id']) ? (int)$r['obj_id'] : null,
        'slug'=>isset($r['slug']) ? $r['slug'] : null,
        'created_at'=>current_time('mysql'),
        'published_at'=>$published,
      ]);
    }
  }

  private static function sanitize_status_row(array $row){
    $allowed = [
      'norm_url'        => 'url',
      'http_status'     => 'int',
      'redirect_to'     => 'url',
      'canonical'       => 'url',
      'robots_meta'     => 'text',
      'noindex'         => 'bool',
      'schema_type'     => 'text',
      'in_sitemap'      => 'bool_null',
      'robots_disallow' => 'bool_null',
      'inbound_links'   => 'int',
      'indexed_in_google'=> 'bool_null',
      'updated_at'      => 'datetime',
    ];

    $clean = [];
    foreach ($allowed as $field => $type){
      if (!array_key_exists($field, $row)) continue;
      $value = $row[$field];
      switch ($type){
        case 'url':
          if ($value === null || $value === ''){
            $clean[$field] = null;
          } else {
            $sanitized = esc_url_raw($value);
            if ($sanitized === '' && $value !== ''){
              $sanitized = sanitize_text_field($value);
            }
            $clean[$field] = $sanitized ?: null;
          }
          break;
        case 'int':
          $clean[$field] = (is_numeric($value) ? (int)$value : null);
          break;
        case 'bool':
          $clean[$field] = $value ? 1 : 0;
          break;
        case 'bool_null':
          if ($value === null || $value === ''){
            $clean[$field] = null;
          } else {
            $clean[$field] = $value ? 1 : 0;
          }
          break;
        case 'text':
          $clean[$field] = ($value === null) ? null : sanitize_text_field($value);
          break;
        case 'datetime':
          $clean[$field] = ($value === null) ? null : sanitize_text_field($value);
          break;
      }
    }
    if (!isset($clean['norm_url']) || $clean['norm_url'] === null || $clean['norm_url'] === ''){
      $fallback = $row['norm_url'] ?? '';
      if ($fallback === ''){
        $fallback = home_url('/');
      }
      $fallback_url = esc_url_raw($fallback);
      $clean['norm_url'] = $fallback_url !== '' ? $fallback_url : sanitize_text_field($fallback);
    }
    if (isset($row['updated_at']) && (!isset($clean['updated_at']) || $clean['updated_at'] === null)){
      $clean['updated_at'] = sanitize_text_field($row['updated_at']);
    }

    return $clean;
  }

  static function upsert_status($url, $row){
    global $wpdb;
    $t = self::t_status();
    $table_sql = self::esc_table($t);
    $safe_url = esc_url_raw($url);
    if ($safe_url === ''){
      $safe_url = sanitize_text_field($url);
    }
    if ($safe_url === ''){
      $safe_url = home_url('/');
    }
    $id = self::get_var_sql("SELECT id FROM {$table_sql} WHERE norm_url=%s LIMIT 1", [$safe_url]);
    $row = array_merge(['norm_url'=>$safe_url], $row);
    $row = self::sanitize_status_row($row);
    if ($id) $wpdb->update($t, $row, ['id'=>(int)$id]); else $wpdb->insert($t, $row);
  }

  static function upsert_pc($post_id, $data){
    global $wpdb;
    $t = self::t_pc();
    $table_sql = self::esc_table($t);
    $exists = self::get_var_sql("SELECT post_id FROM {$table_sql} WHERE post_id=%d", [(int)$post_id]);
    if ($exists) $wpdb->update($t, $data, ['post_id'=>(int)$post_id]); else $wpdb->insert($t, array_merge(['post_id'=>$post_id], $data));
  }

  static function set_flag($key, $value){
    update_option('mw_audit_flag_'.$key, $value, false);
    update_option('mw_audit_flag_'.$key.'_at', current_time('mysql'), false);
  }
  static function get_flags(){
    return [
      'inv'  => get_option('mw_audit_flag_inv', ''),    // inventory rebuild
      'sm'   => get_option('mw_audit_flag_sm', ''),   // sitemaps
      'os'   => get_option('mw_audit_flag_os', ''),   // on-site signals
      'http' => get_option('mw_audit_flag_http',''),  // http-only
      'pc'   => get_option('mw_audit_flag_pc',''),    // post→cat map
      'link' => get_option('mw_audit_flag_link',''),  // internal links
      'outbound' => get_option('mw_audit_flag_outbound',''), // outbound links
      'gindex' => get_option('mw_audit_flag_gindex',''), // google index
      'pi'   => get_option('mw_audit_flag_pi',''),    // page indexing import
      'inv_at'  => get_option('mw_audit_flag_inv_at',''),
      'sm_at'   => get_option('mw_audit_flag_sm_at',''),
      'os_at'   => get_option('mw_audit_flag_os_at',''),
      'http_at' => get_option('mw_audit_flag_http_at',''),
      'pc_at'   => get_option('mw_audit_flag_pc_at',''),
      'link_at' => get_option('mw_audit_flag_link_at',''),
      'outbound_at' => get_option('mw_audit_flag_outbound_at',''),
      'gindex_at' => get_option('mw_audit_flag_gindex_at',''),
      'pi_at' => get_option('mw_audit_flag_pi_at',''),
    ];
  }

  static function count_inventory(){
    return (int) self::get_var_sql("SELECT COUNT(*) FROM ".self::esc_table(self::t_inventory()));
  }
  static function count_status(){
    return (int) self::get_var_sql("SELECT COUNT(*) FROM ".self::esc_table(self::t_status()));
  }
  static function count_pc(){
    return (int) self::get_var_sql("SELECT COUNT(*) FROM ".self::esc_table(self::t_pc()));
  }
  private static function ensure_outbound_ready(){
    static $ensured = false;
    if ($ensured) return;
    global $wpdb;
    $table = self::t_outbound();
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if ($exists !== $table){
      self::ensure_tables_if_missing();
    }
    $ensured = true;
  }
  static function count_outbound(){
    self::ensure_outbound_ready();
    return (int) self::get_var_sql("SELECT COUNT(*) FROM ".self::esc_table(self::t_outbound()));
  }

  static function upsert_outbound($url, array $data){
    global $wpdb; $table=self::t_outbound();
    self::ensure_outbound_ready();
    $row = [
      'norm_url' => $url,
      'outbound_internal' => isset($data['internal']) ? (int)$data['internal'] : null,
      'outbound_external' => isset($data['external']) ? (int)$data['external'] : null,
      'outbound_external_domains' => isset($data['external_domains']) ? (int)$data['external_domains'] : null,
      'last_scanned' => isset($data['last_scanned']) ? $data['last_scanned'] : current_time('mysql'),
    ];
    $formats = ['%s','%d','%d','%d','%s'];
    return $wpdb->replace($table, $row, $formats); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
  }

  static function get_outbound_rows($limit=50, $offset=0, $order='outbound_external', $dir='DESC'){
    global $wpdb;
    $table_sql = self::esc_table(self::t_outbound());
    if ($table_sql === ''){
      return [];
    }
    self::ensure_outbound_ready();
    $limit = max(1, (int)$limit);
    $offset = max(0, (int)$offset);
    $order_map = [
      'norm_url' => 'norm_url',
      'outbound_internal' => 'outbound_internal',
      'outbound_external' => 'outbound_external',
      'outbound_external_domains' => 'outbound_external_domains',
      'last_scanned' => 'last_scanned',
    ];
    $order_key = isset($order_map[$order]) ? $order : 'outbound_external';
    $dir = strtoupper($dir)==='ASC' ? 'ASC' : 'DESC';
    $sql = "SELECT norm_url, outbound_internal, outbound_external, outbound_external_domains, last_scanned FROM {$table_sql} ORDER BY {$order_map[$order_key]} {$dir} LIMIT %d OFFSET %d";
    return self::get_results_sql($sql, [$limit, $offset], ARRAY_A) ?: [];
  }

  static function get_inventory_chunk($after_id, $limit){
    $inv_sql = self::esc_table(self::t_inventory());
    if ($inv_sql === ''){
      return [];
    }
    $after_id = max(0, (int) $after_id);
    $limit = max(1, (int) $limit);
    $sql = "SELECT id, norm_url FROM {$inv_sql} WHERE id > %d ORDER BY id ASC LIMIT %d";
    return self::get_results_sql($sql, [$after_id, $limit], ARRAY_A) ?: [];
  }

  private static function gsc_candidate_where(array &$params, array $args = []){
    $include = [];
    $filters = [];
    $force = !empty($args['force']);
    if ($force){
      $include[] = '1=1';
    } else {
      $now_mysql = current_time('mysql');
      $include[] = '((c_ins.norm_url IS NULL OR c_ins.ttl_until IS NULL OR c_ins.ttl_until <= %s) AND (c_page.norm_url IS NULL OR c_page.ttl_until IS NULL OR c_page.ttl_until <= %s))';
      $params[] = $now_mysql;
      $params[] = $now_mysql;
    }
    $likely_states = isset($args['likely_states']) ? array_filter((array) $args['likely_states']) : [];
    if ($likely_states){
      $placeholders = implode(',', array_fill(0, count($likely_states), '%s'));
      $include[] = "(c_ins.coverage_state IN ($placeholders) OR c_page.coverage_state IN ($placeholders))";
      foreach ($likely_states as $state){
        $params[] = $state;
      }
      foreach ($likely_states as $state){
        $params[] = $state;
      }
    }
    $new_hours = isset($args['new_hours']) ? (int) $args['new_hours'] : 0;
    if ($new_hours > 0){
      $threshold_ts = current_time('timestamp') - ($new_hours * HOUR_IN_SECONDS);
      if (function_exists('wp_date')){
        $threshold = wp_date('Y-m-d H:i:s', $threshold_ts);
      } else {
        $threshold = date_i18n('Y-m-d H:i:s', $threshold_ts);
      }
      $include[] = '(i.published_at IS NOT NULL AND i.published_at >= %s)';
      $params[] = $threshold;
    }
    if (!empty($args['only_urls']) && is_array($args['only_urls'])){
      $urls = array_filter(array_map('trim', $args['only_urls']));
      if ($urls){
        $placeholders = implode(',', array_fill(0, count($urls), '%s'));
        $filter = "(i.norm_url IN ($placeholders))";
        $filters[] = $filter;
        foreach ($urls as $u){
          $params[] = $u;
        }
      }
    }
    if (!$include){
      $include[] = '1=1';
    }
    $where = '( '.implode(' OR ', $include).' )';
    if ($filters){
      $where .= ' AND '.implode(' AND ', $filters);
    }
    return $where;
  }

  static function count_gsc_candidates(array $args = []){
    global $wpdb;
    $inv_sql = self::esc_table(self::t_inventory());
    $cache_sql = self::esc_table(self::t_gsc_cache());
    if ($inv_sql === '' || $cache_sql === ''){
      return 0;
    }
    $params = [];
    $where = self::gsc_candidate_where($params, $args);
    $sql = "
      SELECT COUNT(*)
      FROM {$inv_sql} i
      LEFT JOIN {$cache_sql} c_ins ON c_ins.norm_url = i.norm_url AND c_ins.source='inspection'
      LEFT JOIN {$cache_sql} c_page ON c_page.norm_url = i.norm_url AND c_page.source='page_indexing'
      WHERE {$where}
    ";
    $count = self::get_var_sql($sql, $params);
    if ($wpdb->last_error){
      MW_Audit_DB::log('GSC candidates count error: '.$wpdb->last_error);
    }
    return $count ? (int) $count : 0;
  }

  static function count_gsc_stale_total(){
    global $wpdb;
    $inv_sql = self::esc_table(self::t_inventory());
    $cache_sql = self::esc_table(self::t_gsc_cache());
    if ($inv_sql === '' || $cache_sql === ''){
      return 0;
    }
    $now = current_time('mysql');
    $sql = "
      SELECT COUNT(*)
      FROM {$inv_sql} i
      LEFT JOIN {$cache_sql} c_ins ON c_ins.norm_url = i.norm_url AND c_ins.source='inspection'
      WHERE c_ins.norm_url IS NULL OR c_ins.ttl_until IS NULL OR c_ins.ttl_until <= %s
    ";
    $count = self::get_var_sql($sql, [$now]);
    if ($wpdb->last_error){
      MW_Audit_DB::log('GSC stale count error: '.$wpdb->last_error);
    }
    return $count ? (int)$count : 0;
  }

  static function get_gsc_candidate_batch($after_id, $limit, array $args = []){
    global $wpdb;
    $inv_sql = self::esc_table(self::t_inventory());
    $cache_sql = self::esc_table(self::t_gsc_cache());
    if ($inv_sql === '' || $cache_sql === ''){
      return [];
    }
    $after_id = max(0, (int) $after_id);
    $limit = max(1, (int) $limit);
    $params = [];
    $where = self::gsc_candidate_where($params, $args);
    $priority_params = [];
    $priority_clauses = [];
    if (!empty($args['likely_states'])){
      $likelies = array_filter((array) $args['likely_states']);
      if ($likelies){
        $placeholders = implode(',', array_fill(0, count($likelies), '%s'));
        $priority_clauses[] = "(c_page.coverage_state IN ($placeholders) OR c_ins.coverage_state IN ($placeholders))";
        $priority_params = array_merge($priority_params, $likelies, $likelies);
      }
    }
    $threshold_new = null;
    if (!empty($args['new_hours'])){
      $hours = (int) $args['new_hours'];
      if ($hours > 0){
        $threshold_ts = current_time('timestamp') - ($hours * HOUR_IN_SECONDS);
        $threshold_new = function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $threshold_ts) : date_i18n('Y-m-d H:i:s', $threshold_ts);
      }
    }
    $priority_case = 'CASE ';
    if ($priority_clauses){
      $priority_case .= 'WHEN '.implode(' OR ', $priority_clauses).' THEN 0 ';
    }
    if ($threshold_new){
      $priority_case .= 'WHEN i.published_at IS NOT NULL AND i.published_at >= %s THEN 1 ';
      $priority_params[] = $threshold_new;
    }
    $priority_case .= 'WHEN (c_ins.ttl_until IS NULL OR c_ins.ttl_until <= %s) THEN 2 ELSE 3 END';
    $priority_params[] = current_time('mysql');

    $sql = "
      SELECT i.id, i.norm_url, i.obj_type, i.obj_id, i.slug, i.published_at,
             c_ins.coverage_state AS inspection_coverage_state,
             c_ins.verdict AS inspection_verdict,
             c_ins.ttl_until AS inspection_ttl_until,
             c_ins.inspected_at AS inspection_inspected_at,
             c_ins.last_error AS inspection_last_error,
             c_page.coverage_state AS page_coverage_state,
             c_page.pi_reason_raw AS page_reason_raw,
             c_page.inspected_at AS page_inspected_at
      FROM {$inv_sql} i
      LEFT JOIN {$cache_sql} c_ins ON c_ins.norm_url = i.norm_url AND c_ins.source='inspection'
      LEFT JOIN {$cache_sql} c_page ON c_page.norm_url = i.norm_url AND c_page.source='page_indexing'
      WHERE i.id > %d AND {$where}
      ORDER BY {$priority_case}, i.id ASC
      LIMIT %d
    ";
    $params_full = array_merge([$after_id], $params, $priority_params, [$limit]);
    $rows = self::get_results_sql($sql, $params_full, ARRAY_A);
    if ($wpdb->last_error){
      MW_Audit_DB::log('GSC candidate batch error: '.$wpdb->last_error);
    }
    return $rows ?: [];
  }

  static function get_likely_not_indexed_urls(array $states){
    global $wpdb;
    $states = array_filter(array_map('trim', $states));
    if (!$states){
      return [];
    }
    $inv_sql = self::esc_table(self::t_inventory());
    $cache_sql = self::esc_table(self::t_gsc_cache());
    if ($inv_sql === '' || $cache_sql === ''){
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($states), '%s'));
    $params = array_merge($states, $states);
    $sql = "
      SELECT i.norm_url,
             COALESCE(c_page.coverage_state, c_ins.coverage_state) AS coverage_state,
             COALESCE(c_page.reason_label, c_ins.reason_label) AS reason_label,
             COALESCE(c_page.pi_reason_raw, c_page.coverage_state, c_ins.coverage_state) AS reason,
             COALESCE(c_page.inspected_at, c_ins.inspected_at) AS last_seen,
             CASE WHEN c_page.coverage_state IS NOT NULL THEN 'page_indexing' ELSE 'inspection' END AS source
      FROM {$inv_sql} i
      LEFT JOIN {$cache_sql} c_ins ON c_ins.norm_url = i.norm_url AND c_ins.source='inspection'
      LEFT JOIN {$cache_sql} c_page ON c_page.norm_url = i.norm_url AND c_page.source='page_indexing'
      WHERE (c_ins.coverage_state IN ({$placeholders}) OR c_page.coverage_state IN ({$placeholders}))
      ORDER BY i.norm_url ASC
    ";
    $rows = self::get_results_sql($sql, $params, ARRAY_A);
    if ($wpdb->last_error){
      MW_Audit_DB::log('GSC likely not indexed export error: '.$wpdb->last_error);
    }
    return $rows ?: [];
  }

  public static function table_has_column($table, $column){
    $key = $table.':'.$column;
    if (array_key_exists($key, self::$column_exists_cache)){
      return self::$column_exists_cache[$key];
    }
    global $wpdb;
    $table_sql = self::esc_table($table);
    if ($table_sql === ''){
      return false;
    }
    $exists = (bool) self::get_var_sql("SHOW COLUMNS FROM {$table_sql} LIKE %s", [$column]);
    self::$column_exists_cache[$key] = $exists;
    return $exists;
  }

  static function get_status_rows($limit=100, $offset=0, $order='norm_url', $dir='ASC', array $filters = []){
    global $wpdb;
    $inv = self::t_inventory();
    $st  = self::t_status();
    $pc  = self::t_pc();
    $cache = self::t_gsc_cache();
    $inv_sql = self::esc_table($inv);
    $st_sql = self::esc_table($st);
    $pc_sql = self::esc_table($pc);
    $cache_sql = self::esc_table($cache);
    if ($inv_sql === '' || $st_sql === '' || $pc_sql === '' || $cache_sql === ''){
      return [];
    }
    $cache_has_reason = self::table_has_column($cache, 'reason_label');
    $cache_has_attempts = self::table_has_column($cache, 'attempts');
    $cache_has_pi_reason = self::table_has_column($cache, 'pi_reason_raw');
    $order_map = [
      'norm_url'      => 'i.norm_url',
      'http_status'   => 's.http_status',
      'in_sitemap'    => 's.in_sitemap',
      'noindex'       => 's.noindex',
      'inbound_links' => 's.inbound_links',
      'updated_at'    => 's.updated_at',
      'published_at'  => 'i.published_at',
      'gsc_updated'   => 'g_ins.inspected_at',
      'gsc_ttl'       => 'g_ins.ttl_until',
    ];
    $order_key = isset($order_map[$order]) ? $order : 'norm_url';
    $order_column = $order_map[$order_key];
    $dir = strtoupper($dir)==='DESC' ? 'DESC':'ASC';

    $where = [];
    $params = [];
    $now_mysql = current_time('mysql');

    if (!empty($filters['only_likely']) && !empty($filters['likely_states']) && is_array($filters['likely_states'])){
      $states = array_filter(array_map('trim', $filters['likely_states']));
      if ($states){
        $placeholders = implode(',', array_fill(0, count($states), '%s'));
        $where[] = "(g_ins.coverage_state IN ($placeholders) OR g_page.coverage_state IN ($placeholders))";
        $params = array_merge($params, $states, $states);
      }
    }
    if (!empty($filters['stale'])){
      $where[] = '(g_ins.ttl_until IS NULL OR g_ins.ttl_until <= %s)';
      $params[] = $now_mysql;
    }
    if (!empty($filters['never'])){
      $where[] = 'g_ins.norm_url IS NULL';
    }
    if (!empty($filters['new_hours'])){
      $hours = (int) $filters['new_hours'];
      if ($hours > 0){
        $threshold_ts = current_time('timestamp') - ($hours * HOUR_IN_SECONDS);
        if (function_exists('wp_date')){
          $threshold = wp_date('Y-m-d H:i:s', $threshold_ts);
        } else {
          $threshold = date_i18n('Y-m-d H:i:s', $threshold_ts);
        }
        $where[] = '(i.published_at IS NOT NULL AND i.published_at >= %s)';
        $params[] = $threshold;
      }
    }
    if (!empty($filters['exact_url'])){
      $where[] = 'i.norm_url = %s';
      $params[] = esc_url_raw($filters['exact_url']);
    }

    $where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
    $sql = "
      SELECT i.norm_url, i.obj_type, i.obj_id, i.slug, i.published_at,
             s.http_status, s.redirect_to, s.canonical, s.robots_meta, s.noindex, s.schema_type, s.in_sitemap, s.robots_disallow, s.inbound_links, s.indexed_in_google, s.updated_at,
             p.pc_name, p.pc_path,
             g_ins.coverage_state AS gsc_coverage_inspection,
             g_ins.verdict AS gsc_verdict,
             ".($cache_has_reason ? 'g_ins.reason_label AS gsc_reason_inspection,' : 'NULL AS gsc_reason_inspection,')."
             g_ins.inspected_at AS gsc_inspected_at,
             g_ins.ttl_until AS gsc_ttl_until,
             g_ins.last_error AS gsc_last_error,
             ".($cache_has_attempts ? 'g_ins.attempts AS gsc_attempts,' : 'NULL AS gsc_attempts,')."
             g_page.coverage_state AS gsc_coverage_page,
             ".($cache_has_reason ? 'g_page.reason_label AS gsc_reason_page,' : 'NULL AS gsc_reason_page,')."
             ".($cache_has_pi_reason ? 'g_page.pi_reason_raw AS gsc_pi_reason,' : 'NULL AS gsc_pi_reason,')."
             g_page.inspected_at AS gsc_pi_inspected_at
      FROM {$inv_sql} i
      LEFT JOIN {$st_sql} s ON s.norm_url = i.norm_url
      LEFT JOIN {$pc_sql} p ON p.post_id = CASE WHEN i.obj_type='post' THEN i.obj_id ELSE 0 END
      LEFT JOIN {$cache_sql} g_ins ON g_ins.norm_url = i.norm_url AND g_ins.source='inspection'
      LEFT JOIN {$cache_sql} g_page ON g_page.norm_url = i.norm_url AND g_page.source='page_indexing'
      {$where_sql}
      ORDER BY {$order_column} {$dir}
      LIMIT %d OFFSET %d";
    $params[] = (int) $limit;
    $params[] = (int) $offset;
    $rows = self::get_results_sql($sql, $params, ARRAY_A);
    if (!$rows && $wpdb->last_error){
      $rows = self::get_results_sql("SELECT norm_url, obj_type, obj_id, slug FROM {$inv_sql} ORDER BY id ASC LIMIT 50", [], ARRAY_A) ?: [];
    }
    return $rows;
  }

  public static function get_status_row_for_url($url){
    $url = esc_url_raw(trim((string) $url));
    if ($url === ''){
      return null;
    }
    $filters = [
      'exact_url'     => $url,
      'likely_states' => class_exists('MW_Audit_GSC') ? MW_Audit_GSC::get_likely_not_indexed_reasons() : [],
    ];
    $rows = self::get_status_rows(1, 0, 'norm_url', 'ASC', $filters);
    if (!$rows){
      return null;
    }
    $row = $rows[0];
    $row['days_since_update'] = self::calculate_days_since_update($row['updated_at'] ?? null, $row['published_at'] ?? null);
    return $row;
  }

  private static function calculate_days_since_update($updated_at, $published_at){
    $reference = null;
    if ($updated_at && $updated_at !== '0000-00-00 00:00:00'){
      $reference = $updated_at;
    } elseif ($published_at && $published_at !== '0000-00-00 00:00:00'){
      $reference = $published_at;
    }
    if (!$reference){
      return null;
    }
    $timestamp = strtotime($reference);
    if (!$timestamp){
      return null;
    }
    $diff = current_time('timestamp') - $timestamp;
    if ($diff < 0){
      return 0;
    }
    return (int) floor($diff / DAY_IN_SECONDS);
  }

  public static function find_similar_rows(array $criteria, $limit = 25, $offset = 0){
    global $wpdb;
    $limit = max(1, (int) $limit);
    $offset = max(0, (int) $offset);
    $parts = self::build_similar_query_components($criteria);
    if (!$parts){
      return [
        'rows' => [],
        'total' => 0,
      ];
    }
    $sql = "
      SELECT {$parts['select']}
      FROM {$parts['from']}
      {$parts['joins']}
      {$parts['where']}
      ORDER BY similarity_score ASC, i.norm_url ASC
      LIMIT %d OFFSET %d
    ";
    $params = array_merge($parts['select_params'], $parts['where_params'], [$limit, $offset]);
    $rows = self::get_results_sql($sql, $params, ARRAY_A) ?: [];

    $count_sql = "
      SELECT COUNT(*)
      FROM {$parts['from']}
      {$parts['joins']}
      {$parts['where']}
    ";
    $count = self::get_var_sql($count_sql, $parts['where_params']);

    return [
      'rows'  => $rows,
      'total' => (int) $count,
    ];
  }

  public static function get_similar_rows_chunk(array $criteria, $limit = 100, $offset = 0){
    global $wpdb;
    $limit = max(1, (int) $limit);
    $offset = max(0, (int) $offset);
    $parts = self::build_similar_query_components($criteria);
    if (!$parts){
      return [];
    }
    $sql = "
      SELECT {$parts['select']}
      FROM {$parts['from']}
      {$parts['joins']}
      {$parts['where']}
      ORDER BY similarity_score ASC, i.norm_url ASC
      LIMIT %d OFFSET %d
    ";
    $params = array_merge($parts['select_params'], $parts['where_params'], [$limit, $offset]);
    return self::get_results_sql($sql, $params, ARRAY_A) ?: [];
  }

  private static function build_similar_query_components(array $criteria){
    global $wpdb;
    $inv = self::t_inventory();
    $st  = self::t_status();
    $pc  = self::t_pc();
    $cache = self::t_gsc_cache();
    $inv_sql = self::esc_table($inv);
    $st_sql = self::esc_table($st);
    $pc_sql = self::esc_table($pc);
    $cache_sql = self::esc_table($cache);
    if ($inv_sql === '' || $st_sql === '' || $pc_sql === '' || $cache_sql === ''){
      return null;
    }
    $cache_has_reason = self::table_has_column($cache, 'reason_label');
    $cache_has_attempts = self::table_has_column($cache, 'attempts');
    $cache_has_pi_reason = self::table_has_column($cache, 'pi_reason_raw');

    $age_expr = "TIMESTAMPDIFF(DAY, CASE WHEN s.updated_at IS NOT NULL AND s.updated_at <> '0000-00-00 00:00:00' THEN s.updated_at WHEN i.published_at IS NOT NULL AND i.published_at <> '0000-00-00 00:00:00' THEN i.published_at ELSE NULL END, NOW())";

    $select_fields = [
      'i.norm_url',
      'i.obj_type',
      'i.obj_id',
      'i.slug',
      'i.published_at',
      's.http_status',
      's.redirect_to',
      's.canonical',
      's.robots_meta',
      's.noindex',
      's.schema_type',
      's.in_sitemap',
      's.robots_disallow',
      's.inbound_links',
      's.indexed_in_google',
      's.updated_at',
      'p.pc_name',
      'p.pc_path',
      'g_ins.coverage_state AS gsc_coverage_inspection',
      'g_ins.verdict AS gsc_verdict',
      ($cache_has_reason ? 'g_ins.reason_label AS gsc_reason_inspection' : 'NULL AS gsc_reason_inspection'),
      'g_ins.inspected_at AS gsc_inspected_at',
      'g_ins.ttl_until AS gsc_ttl_until',
      'g_ins.last_error AS gsc_last_error',
      ($cache_has_attempts ? 'g_ins.attempts AS gsc_attempts' : 'NULL AS gsc_attempts'),
      'g_page.coverage_state AS gsc_coverage_page',
      ($cache_has_reason ? 'g_page.reason_label AS gsc_reason_page' : 'NULL AS gsc_reason_page'),
      ($cache_has_pi_reason ? 'g_page.pi_reason_raw AS gsc_pi_reason' : 'NULL AS gsc_pi_reason'),
      'g_page.inspected_at AS gsc_pi_inspected_at',
      "$age_expr AS days_since_update",
    ];

    $select_params = [];
    $score_components = [];
    if (!empty($criteria['inbound_range']) && array_key_exists('baseline', $criteria['inbound_range']) && $criteria['inbound_range']['baseline'] !== null){
      $score_components[] = 'ABS(COALESCE(s.inbound_links,0) - %d)';
      $select_params[] = (int) $criteria['inbound_range']['baseline'];
    }
    if (!empty($criteria['age_range']) && array_key_exists('baseline', $criteria['age_range']) && $criteria['age_range']['baseline'] !== null){
      $score_components[] = 'ABS(('.$age_expr.') - %d)';
      $select_params[] = (int) $criteria['age_range']['baseline'];
    }
    $select_fields[] = ($score_components ? implode(' + ', $score_components) : '0') . ' AS similarity_score';

    $joins = "
      LEFT JOIN {$st_sql} s ON s.norm_url = i.norm_url
      LEFT JOIN {$pc_sql} p ON p.post_id = CASE WHEN i.obj_type='post' THEN i.obj_id ELSE 0 END
      LEFT JOIN {$cache_sql} g_ins ON g_ins.norm_url = i.norm_url AND g_ins.source='inspection'
      LEFT JOIN {$cache_sql} g_page ON g_page.norm_url = i.norm_url AND g_page.source='page_indexing'
    ";

    $where = [];
    $where_params = [];
    if (!empty($criteria['base_url'])){
      $where[] = 'i.norm_url <> %s';
      $where_params[] = esc_url_raw($criteria['base_url']);
    }
    if (array_key_exists('http_status', $criteria)){
      $where[] = 's.http_status = %d';
      $where_params[] = (int) $criteria['http_status'];
    }
    if (array_key_exists('in_sitemap', $criteria)){
      $where[] = 'COALESCE(s.in_sitemap,0) = %d';
      $where_params[] = (int) $criteria['in_sitemap'];
    }
    if (array_key_exists('noindex', $criteria)){
      $where[] = 'COALESCE(s.noindex,0) = %d';
      $where_params[] = (int) $criteria['noindex'];
    }
    if (array_key_exists('indexed_in_google', $criteria)){
      $where[] = 's.indexed_in_google = %d';
      $where_params[] = (int) $criteria['indexed_in_google'];
    }
    if (!empty($criteria['pc_path'])){
      $where[] = 'p.pc_path LIKE %s';
      $where_params[] = $criteria['pc_path'];
    }
    if (!empty($criteria['inbound_range'])){
      $min = isset($criteria['inbound_range']['min']) ? (int) $criteria['inbound_range']['min'] : 0;
      $max = isset($criteria['inbound_range']['max']) ? (int) $criteria['inbound_range']['max'] : $min;
      $where[] = '(s.inbound_links IS NOT NULL AND s.inbound_links BETWEEN %d AND %d)';
      $where_params[] = $min;
      $where_params[] = $max;
    }
    if (!empty($criteria['age_range'])){
      $min = isset($criteria['age_range']['min']) ? (int) $criteria['age_range']['min'] : 0;
      $max = isset($criteria['age_range']['max']) ? (int) $criteria['age_range']['max'] : $min;
      $expr = '('.$age_expr.')';
      $where[] = "($expr IS NOT NULL AND $expr BETWEEN %d AND %d)";
      $where_params[] = $min;
      $where_params[] = $max;
    }

    $where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

    return [
      'select'        => implode(",\n             ", $select_fields),
      'from'          => "{$inv_sql} i",
      'joins'         => $joins,
      'where'         => $where_sql,
      'select_params' => $select_params,
      'where_params'  => $where_params,
    ];
  }

  public static function priority_thresholds(){
    $defaults = [0, 1, 2];
    $thresholds = apply_filters('mw_audit_priority_thresholds', $defaults);
    if (!is_array($thresholds) || empty($thresholds)){
      $thresholds = $defaults;
    }
    $thresholds = array_map('intval', $thresholds);
    $thresholds = array_values(array_unique($thresholds));
    sort($thresholds, SORT_NUMERIC);
    return $thresholds;
  }

  public static function normalize_priority_threshold($value){
    $allowed = self::priority_thresholds();
    $value = (int) $value;
    if (!in_array($value, $allowed, true)){
      $value = $allowed[0];
    }
    return $value;
  }

  public static function count_priority_ready($threshold = 0){
    global $wpdb;
    $inv = self::t_inventory();
    $st  = self::t_status();
    $inv_sql = self::esc_table($inv);
    $st_sql = self::esc_table($st);
    if ($inv_sql === '' || $st_sql === ''){
      return 0;
    }
    $threshold = self::normalize_priority_threshold($threshold);

    $sql = "
      SELECT COUNT(*)
      FROM {$inv_sql} i
      INNER JOIN {$st_sql} s ON s.norm_url = i.norm_url
      WHERE s.http_status = 200
        AND s.in_sitemap = 1
        AND (s.noindex IS NULL OR s.noindex = 0)
        AND COALESCE(s.inbound_links, 0) <= %d
    ";
    $count = (int) self::get_var_sql($sql, [$threshold]);
    if ($wpdb->last_error){
      self::log('Priority count error: '.$wpdb->last_error);
    }
    return $count;
  }

  public static function get_priority_ready_rows($threshold = 0, $limit = 25, $offset = 0, $order = 'inbound_links', $dir = 'ASC'){
    global $wpdb;
    $inv = self::t_inventory();
    $st  = self::t_status();
    $pc  = self::t_pc();
    $cache = self::t_gsc_cache();
    $inv_sql = self::esc_table($inv);
    $st_sql = self::esc_table($st);
    $pc_sql = self::esc_table($pc);
    $cache_sql = self::esc_table($cache);
    if ($inv_sql === '' || $st_sql === '' || $pc_sql === '' || $cache_sql === ''){
      return [];
    }

    $threshold = self::normalize_priority_threshold($threshold);
    $limit = max(1, (int) $limit);
    $offset = max(0, (int) $offset);

    $order_map = [
      'norm_url'      => 'i.norm_url',
      'published_at'  => 'i.published_at',
      'inbound_links' => 'COALESCE(s.inbound_links, 0)',
      'updated_at'    => 's.updated_at',
      'gsc_inspected' => 'best_inspected_at',
    ];
    $order_key = isset($order_map[$order]) ? $order : 'inbound_links';
    $order_column = $order_map[$order_key];
    $dir = strtoupper($dir)==='DESC' ? 'DESC' : 'ASC';

    $sql = "
      SELECT i.norm_url, i.obj_type, i.obj_id, i.slug, i.published_at,
             s.http_status, s.in_sitemap, s.noindex, s.inbound_links, s.canonical, s.robots_meta, s.schema_type, s.updated_at,
             p.pc_name, p.pc_path,
             g_ins.coverage_state AS inspection_coverage_state,
             g_ins.reason_label AS inspection_reason_label,
             g_ins.inspected_at AS inspection_inspected_at,
             g_page.coverage_state AS page_coverage_state,
             g_page.reason_label AS page_reason_label,
             g_page.inspected_at AS page_inspected_at,
             CASE
               WHEN g_ins.inspected_at IS NOT NULL AND (g_page.inspected_at IS NULL OR g_ins.inspected_at >= g_page.inspected_at)
                 THEN g_ins.inspected_at
               ELSE g_page.inspected_at
             END AS best_inspected_at,
             CASE
               WHEN g_ins.inspected_at IS NOT NULL AND (g_page.inspected_at IS NULL OR g_ins.inspected_at >= g_page.inspected_at)
                 THEN 'inspection'
               WHEN g_page.inspected_at IS NOT NULL THEN 'page_indexing'
               ELSE ''
             END AS best_source,
             CASE
               WHEN g_ins.inspected_at IS NOT NULL AND (g_page.inspected_at IS NULL OR g_ins.inspected_at >= g_page.inspected_at)
                 THEN g_ins.coverage_state
               ELSE g_page.coverage_state
             END AS best_coverage_state,
             CASE
               WHEN g_ins.inspected_at IS NOT NULL AND (g_page.inspected_at IS NULL OR g_ins.inspected_at >= g_page.inspected_at)
                 THEN g_ins.reason_label
               ELSE g_page.reason_label
             END AS best_reason_label
      FROM {$inv_sql} i
      INNER JOIN {$st_sql} s ON s.norm_url = i.norm_url
      LEFT JOIN {$pc_sql} p ON p.post_id = CASE WHEN i.obj_type='post' THEN i.obj_id ELSE 0 END
      LEFT JOIN {$cache_sql} g_ins ON g_ins.norm_url = i.norm_url AND g_ins.source='inspection'
      LEFT JOIN {$cache_sql} g_page ON g_page.norm_url = i.norm_url AND g_page.source='page_indexing'
      WHERE s.http_status = 200
        AND s.in_sitemap = 1
        AND (s.noindex IS NULL OR s.noindex = 0)
        AND COALESCE(s.inbound_links, 0) <= %d
      ORDER BY {$order_column} {$dir}
      LIMIT %d OFFSET %d
    ";
    $rows = self::get_results_sql($sql, [$threshold, $limit, $offset], ARRAY_A) ?: [];
    foreach ($rows as &$row){
      $row['inbound_links'] = isset($row['inbound_links']) ? (int) $row['inbound_links'] : 0;
      $row['gsc_source']    = $row['best_source'] ?? '';
      $row['gsc_coverage']  = $row['best_coverage_state'] ?? '';
      $row['gsc_reason']    = $row['best_reason_label'] ?? '';
      $row['gsc_inspected_at'] = $row['best_inspected_at'] ?? null;
      unset($row['best_source'], $row['best_coverage_state'], $row['best_reason_label'], $row['best_inspected_at']);
    }
    return $rows;
  }

  private static function meta_description_keys(){
    $defaults = [
      '_yoast_wpseo_metadesc',
      '_rank_math_description',
      '_aioseo_description',
      '_aioseop_description',
      '_aioseo_og_description',
      '_aioseo_twitter_description',
      'seopress_titles_desc',
      '_seopress_titles_desc',
      '_meta_description',
      '_metadescription',
    ];
    return apply_filters('mw_audit_meta_description_keys', $defaults);
  }

  private static function normalize_meta_description_text($text){
    $clean = wp_strip_all_tags((string) $text);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    if ($clean === ''){
      return '';
    }
    $limit = 320;
    if (function_exists('mb_strlen') ? mb_strlen($clean) > $limit : strlen($clean) > $limit){
      if (function_exists('wp_html_excerpt')){
        return trim(wp_html_excerpt($clean, $limit, '…'));
      }
      return rtrim(substr($clean, 0, $limit - 1)).'…';
    }
    return $clean;
  }

  public static function get_meta_description_for_post($post_id){
    $post_id = (int) $post_id;
    if ($post_id <= 0){
      return '';
    }
    $keys = self::meta_description_keys();
    foreach ($keys as $key){
      $value = get_post_meta($post_id, $key, true);
      if (is_string($value)){
        $normalized = self::normalize_meta_description_text($value);
        if ($normalized !== ''){
          return apply_filters('mw_audit_meta_description', $normalized, $post_id, $key);
        }
      }
    }
    $excerpt = get_post_field('post_excerpt', $post_id);
    if (is_string($excerpt)){
      $normalized = self::normalize_meta_description_text($excerpt);
      if ($normalized !== ''){
        return apply_filters('mw_audit_meta_description', $normalized, $post_id, 'excerpt');
      }
    }
    $content = get_post_field('post_content', $post_id);
    if (is_string($content)){
      $normalized = self::normalize_meta_description_text($content);
      if ($normalized !== ''){
        return apply_filters('mw_audit_meta_description', $normalized, $post_id, 'content');
      }
    }
    return '';
  }

  private static function get_post_content_plain($post_id){
    $post_id = (int) $post_id;
    if ($post_id <= 0){
      return '';
    }
    $content = get_post_field('post_content', $post_id);
    if (!is_string($content) || $content === ''){
      return '';
    }
    if (function_exists('strip_shortcodes')){
      $content = strip_shortcodes($content);
    }
    $content = wp_strip_all_tags($content);
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
    $content = preg_replace("/\r\n|\r/", "\n", $content);
    $content = preg_replace("/[ \t]+\n/", "\n", $content);
    $content = preg_replace("/\n{3,}/", "\n\n", $content);
    $content = trim($content);
    if ($content === ''){
      return '';
    }
    $limit = 15000;
    if (function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') > $limit : strlen($content) > $limit){
      $content = function_exists('mb_substr')
        ? rtrim(mb_substr($content, 0, $limit, 'UTF-8')).'…'
        : rtrim(substr($content, 0, $limit - 1)).'…';
    }
    return $content;
  }

  public static function get_stale_content_candidates(array $args = []){
    global $wpdb;
    $defaults = [
      'limit' => 12,
      'post_types' => ['post','page'],
      'statuses' => ['publish'],
      'min_days_since_update' => 365,
      'min_days_since_publish' => 90,
      'order' => 'modified',
      'only_zero_inbound' => false,
    ];
    $args = wp_parse_args($args, $defaults);
    $args = apply_filters('mw_audit_stale_content_candidate_args', $args);

    $limit = isset($args['limit']) ? (int) $args['limit'] : $defaults['limit'];
    $limit = max(1, min(100, $limit));
    $post_types = array_filter(array_map('sanitize_key', (array) ($args['post_types'] ?? $defaults['post_types'])));
    if (!$post_types){
      $post_types = ['post','page'];
    }
    $statuses = array_filter(array_map('sanitize_key', (array) ($args['statuses'] ?? $defaults['statuses'])));
    if (!$statuses){
      $statuses = ['publish'];
    }
    $min_update_days = max(0, (int) ($args['min_days_since_update'] ?? $defaults['min_days_since_update']));
    $min_publish_days = max(0, (int) ($args['min_days_since_publish'] ?? $defaults['min_days_since_publish']));

    $where = [];
    $params = [];
    $placeholders_status = implode(',', array_fill(0, count($statuses), '%s'));
    $placeholders_types = implode(',', array_fill(0, count($post_types), '%s'));
    $where[] = "post_status IN ($placeholders_status)";
    $params = array_merge($params, $statuses);
    $where[] = "post_type IN ($placeholders_types)";
    $params = array_merge($params, $post_types);
    $where[] = "post_password = ''";
    if ($min_update_days > 0){
      $threshold_ts = current_time('timestamp') - (DAY_IN_SECONDS * $min_update_days);
      $threshold = function_exists('wp_date')
        ? wp_date('Y-m-d H:i:s', $threshold_ts)
        : date_i18n('Y-m-d H:i:s', $threshold_ts);
      $where[] = 'COALESCE(NULLIF(post_modified,\'0000-00-00 00:00:00\'), post_date) <= %s';
      $params[] = $threshold;
    }
    if ($min_publish_days > 0){
      $pub_threshold_ts = current_time('timestamp') - (DAY_IN_SECONDS * $min_publish_days);
      $pub_threshold = function_exists('wp_date')
        ? wp_date('Y-m-d H:i:s', $pub_threshold_ts)
        : date_i18n('Y-m-d H:i:s', $pub_threshold_ts);
      $where[] = 'post_date <= %s';
      $params[] = $pub_threshold;
    }
    if (!empty($args['modified_after'])){
      $after = sanitize_text_field($args['modified_after']);
      $where[] = "COALESCE(NULLIF(post_modified,'0000-00-00 00:00:00'), post_date) >= %s";
      $params[] = $after;
    }
    if (!empty($args['modified_before'])){
      $before = sanitize_text_field($args['modified_before']);
      $where[] = "COALESCE(NULLIF(post_modified,'0000-00-00 00:00:00'), post_date) <= %s";
      $params[] = $before;
    }

    $posts_table = $wpdb->posts;
    $posts_table_sql = self::esc_table($posts_table);
    $status_table = self::t_status();
    $status_table_sql = self::esc_table($status_table);
    if ($posts_table_sql === '' || $status_table_sql === ''){
      return [];
    }
    $order_column = ($args['order'] ?? '') === 'published'
      ? 'post_date'
      : "COALESCE(NULLIF(post_modified,'0000-00-00 00:00:00'), post_date)";
    $sql = "
      SELECT ID, post_title, post_type, post_status, post_date, post_modified,
             COALESCE(NULLIF(post_modified,'0000-00-00 00:00:00'), post_date) AS last_touch
      FROM {$posts_table_sql}
      WHERE ".implode(' AND ', $where)."
      ORDER BY {$order_column} ASC, post_date ASC
      LIMIT %d
    ";
    $params[] = $limit;
    $rows = self::get_results_sql($sql, $params, ARRAY_A) ?: [];
    if (!$rows){
      return [];
    }
    $now_ts = current_time('timestamp');
    $current_year = (int) (function_exists('wp_date') ? wp_date('Y', $now_ts) : date_i18n('Y', $now_ts));
    $results = [];
    $permalink_map = [];
    foreach ($rows as $row){
      $post_id = (int) $row['ID'];
      $permalink = get_permalink($post_id);
      if (!$permalink){
        $permalink = get_post_permalink($post_id);
      }
      if (!$permalink){
        $permalink = home_url('/?p='.$post_id);
      }
      if ($permalink){
        $safe_permalink = esc_url_raw($permalink);
        if ($safe_permalink !== ''){
          $permalink_map[$safe_permalink] = $post_id;
          $alt_trailing = trailingslashit($safe_permalink);
          $alt_non = untrailingslashit($safe_permalink);
          $permalink_map[$alt_trailing] = $post_id;
          $permalink_map[$alt_non] = $post_id;
        }
      }
      $published = $row['post_date'];
      $last_touch = $row['last_touch'] ?: $published;
      $last_touch_ts = $last_touch ? strtotime($last_touch) : 0;
      $days_since_update = $last_touch_ts ? max(0, (int) floor(($now_ts - $last_touch_ts) / DAY_IN_SECONDS)) : null;
      $results[] = [
        'ID' => $post_id,
        'title' => get_the_title($post_id),
        'permalink' => $permalink,
        'post_type' => $row['post_type'],
        'post_status' => $row['post_status'],
        'published_at' => $published,
        'modified_at' => $last_touch,
        'meta_description' => self::get_meta_description_for_post($post_id),
        'days_since_update' => $days_since_update,
        'needs_current_year_focus' => $last_touch_ts ? ((int) (function_exists('wp_date') ? wp_date('Y', $last_touch_ts) : date_i18n('Y', $last_touch_ts)) < $current_year) : true,
        'inbound_links' => null,
        'content_plain' => self::get_post_content_plain($post_id),
      ];
    }
    if ($results && $permalink_map){
      $unique_urls = array_keys($permalink_map);
      $unique_urls = array_values(array_filter(array_unique($unique_urls)));
      if ($unique_urls){
        $placeholders = implode(',', array_fill(0, count($unique_urls), '%s'));
        $inbound_sql = "SELECT norm_url, inbound_links FROM {$status_table_sql} WHERE norm_url IN ({$placeholders})";
        $inbound_rows = self::get_results_sql($inbound_sql, $unique_urls, ARRAY_A) ?: [];
        $inbound_map = [];
        foreach ($inbound_rows as $row_in){
          $key = esc_url_raw($row_in['norm_url']);
          if ($key !== ''){
            $inbound_map[$key] = isset($row_in['inbound_links']) ? (int) $row_in['inbound_links'] : null;
          }
        }
        foreach ($results as &$result_row){
          $perm = esc_url_raw($result_row['permalink']);
          $candidates = array_unique([$perm, trailingslashit($perm), untrailingslashit($perm)]);
          foreach ($candidates as $candidate_url){
            if ($candidate_url === ''){
              continue;
            }
            if (array_key_exists($candidate_url, $inbound_map)){
              $result_row['inbound_links'] = $inbound_map[$candidate_url];
              break;
            }
          }
        }
        unset($result_row);
      }
    }
    if (!empty($args['only_zero_inbound'])){
      $results = array_values(array_filter($results, function($row){
        return !isset($row['inbound_links']) || (int) $row['inbound_links'] <= 0;
      }));
    }
    return apply_filters('mw_audit_stale_content_candidates', $results, $args, $rows);
  }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
