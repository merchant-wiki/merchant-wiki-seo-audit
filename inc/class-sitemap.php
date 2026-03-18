<?php
if (!defined('ABSPATH')) exit;

class MW_Audit_Sitemap {
  private static $lookup_cache = [];
  private static $lookup_version = null;
  static function detect(){
    $candidates = ['/sitemap_index.xml','/sitemap.xml','/wp-sitemap.xml'];
    $found = [];

    // Parse robots.txt for additional sitemap hints
    $robots = wp_remote_get(home_url('/robots.txt'), ['timeout'=>4, 'redirection'=>3]);
    if (!is_wp_error($robots) && (int)wp_remote_retrieve_response_code($robots) === 200){
      $lines = preg_split('/\r?\n/', wp_remote_retrieve_body($robots));
      foreach ($lines as $line){
        if (stripos($line, 'sitemap:') === 0){
          $hint = trim(substr($line, 8));
          if ($hint){ $candidates[] = $hint; }
        }
      }
    }

    $candidates = array_values(array_unique($candidates));
    foreach ($candidates as $p){
      $url = (strpos($p, 'http') === 0) ? $p : home_url($p);
      $r = wp_remote_get($url, [
        'timeout'      => 6,
        'redirection'  => 5,
        'headers'      => ['User-Agent' => 'MW-Audit/1.8.2 (+'.home_url('/').')'],
      ]);
      if (is_wp_error($r)){
        MW_Audit_DB::log('Sitemap detect failed for '.$url.': '.$r->get_error_message());
        continue;
      }
      $code = (int) wp_remote_retrieve_response_code($r);
      if ($code !== 200){
        MW_Audit_DB::log('Sitemap detect returned HTTP '.$code.' for '.$url);
        continue;
      }
      $body = wp_remote_retrieve_body($r);
      if ($body === ''){
        MW_Audit_DB::log('Sitemap detect got empty body for '.$url);
        continue;
      }
      $found[] = ['url'=>$url,'len'=>strlen($body),'ok'=>true,'body'=>$body];
    }
    return $found;
  }

  static function children_from_index($xml){
    $urls=[]; if (preg_match_all('~<loc>([^<]+)</loc>~i', $xml, $m)) $urls=$m[1];
    return array_values(array_unique($urls));
  }

  static function prepare_cache($timeout=4){
    $det = self::detect();
    $bodies = []; $src = [];
    foreach ($det as $d){
      $src[] = $d['url'];
      if (preg_match('~sitemapindex~i', $d['body'])){
        foreach (self::children_from_index($d['body']) as $child){
          $r = wp_remote_get($child, ['timeout'=>$timeout]);
          if (!is_wp_error($r) && (int)wp_remote_retrieve_response_code($r)===200){
            $bodies[] = wp_remote_retrieve_body($r);
            $src[] = $child;
          }
        }
      } else {
        $bodies[] = $d['body'];
      }
    }
    $sources = array_values(array_unique($src));
    $payload = [
      'time'   => time(),
      'sources'=> $sources,
      'count'  => count($bodies),
      'bodies' => $bodies,
      'files'  => $sources,
    ];
    update_option('mw_audit_sitemap_cache', $payload, false);
    $version = (string) time();
    update_option('mw_audit_smap_lookup_v', $version, false);
    self::$lookup_version = $version;
    self::$lookup_cache = [];
    MW_Audit_DB::set_flag('sm','done');
    return $payload;
  }

  static function get_cached(){
    $p = get_option('mw_audit_sitemap_cache');
    if (is_array($p) && !empty($p['bodies'])){
      $p['age'] = isset($p['time']) ? max(0, time() - (int) $p['time']) : 0;
      if (!isset($p['files'])){
        $p['files'] = isset($p['sources']) ? (array) $p['sources'] : [];
      }
      if (!isset($p['sources'])){
        $p['sources'] = $p['files'];
      }
      return $p;
    }
    return ['time'=>0,'sources'=>[],'count'=>0,'bodies'=>[], 'files'=>[], 'age'=>0];
  }

  static function url_in_sitemaps($url){
    $key = md5($url);
    if (isset(self::$lookup_cache[$key])){
      return self::$lookup_cache[$key];
    }
    $version = self::$lookup_version;
    if ($version === null){
      $version = get_option('mw_audit_smap_lookup_v');
      if (!$version){
        $version = (string) time();
        update_option('mw_audit_smap_lookup_v', $version, false);
      }
      self::$lookup_version = $version;
    }
    $cache_key = 'mw_audit_smap_lookup_'.$version.'_'.$key;
    $cached = wp_cache_get($cache_key, 'mw_audit');
    if (is_array($cached) && array_key_exists('value', $cached)){
      self::$lookup_cache[$key] = $cached['value'];
      return $cached['value'];
    }

    $data = self::get_cached();
    if (empty($data['bodies'])){
      self::$lookup_cache[$key] = null;
      wp_cache_set($cache_key, ['value'=>null], 'mw_audit', 5 * MINUTE_IN_SECONDS);
      return null;
    }
    $found = 0;
    foreach ($data['bodies'] as $body){
      if (strpos($body, $url)!==false){
        $found = 1;
        break;
      }
    }
    self::$lookup_cache[$key] = $found;
    wp_cache_set($cache_key, ['value'=>$found], 'mw_audit', 10 * MINUTE_IN_SECONDS);
    return $found;
  }

  static function clear_cache(){
    delete_option('mw_audit_sitemap_cache');
    delete_option('mw_audit_smap_lookup_v');
    self::$lookup_cache = [];
    self::$lookup_version = null;
    if (function_exists('wp_cache_flush_group')){
      wp_cache_flush_group('mw_audit');
    }
  }
}
