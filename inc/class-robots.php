<?php
if (!defined('ABSPATH')) exit;
class MW_Audit_Robots {
  const CACHE_KEY = 'mw_audit_robots_rules';

  static function fetch_rules($timeout=3, $force=false){
    $cache_key = self::CACHE_KEY;
    if (!$force){
      $cached = get_transient($cache_key);
      if (is_array($cached)){
        return $cached;
      }
    }

    $res = wp_remote_get(home_url('/robots.txt'), ['timeout'=>$timeout,'redirection'=>3]);
    if (is_wp_error($res)) {
      MW_Audit_DB::log('robots fetch WP_Error: '.$res->get_error_message());
      $payload = ['ok'=>false,'status'=>0,'body'=>'','checked_at'=>time()];
      set_transient($cache_key, $payload, 5 * MINUTE_IN_SECONDS);
      return $payload;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $payload = ['ok'=>$code===200,'status'=>$code,'body'=>$body,'checked_at'=>time()];
    $ttl = ($code===200 && $body!=='') ? 10 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
    set_transient($cache_key, $payload, $ttl);
    return $payload;
  }
  static function clear_cache(){ delete_transient(self::CACHE_KEY); }
  static function disallowed($robots_body, $url){
    if (!$robots_body) return 0;
    $path = wp_parse_url($url, PHP_URL_PATH) ?: '/';
    $lines = preg_split('/\r\n|\r|\n/',$robots_body);
    foreach ($lines as $ln){
      if (stripos($ln,'Disallow:')===0){
        $p = trim(substr($ln,9));
        if ($p==='/'){ return 1; }
        if ($p && strpos($path, rtrim($p,'*'))===0){ return 1; }
      }
    }
    return 0;
  }
}
