<?php
if (!defined('ABSPATH')) exit;
class MW_Audit_ILinks {
  private static $post_links_cache = [];
  private static $post_types = null;

  private static function normalize_url($url){
    $url = trim((string) $url);
    if ($url === '') return '';
    $parts = wp_parse_url($url);
    if (!$parts || empty($parts['host'])){
      return '';
    }
    $scheme = strtolower($parts['scheme'] ?? 'http');
    $host = strtolower($parts['host']);
    $path = isset($parts['path']) ? '/' . ltrim($parts['path'], '/') : '/';
    $path = ($path === '/') ? '/' : untrailingslashit($path);
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $scheme.'://'.$host.$path.$query;
  }

  private static function url_variants($url){
    $normalized = self::normalize_url($url);
    if ($normalized === '') return [];
    $parts = wp_parse_url($normalized);
    if (!$parts) return [$normalized];
    $scheme = strtolower($parts['scheme'] ?? 'http');
    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $base = $scheme.'://'.$host;
    $path_no_slash = ($path === '/') ? '/' : untrailingslashit($path);
    $path_with_slash = ($path_no_slash === '/' ? '/' : trailingslashit($path_no_slash));

    $variants = [];
    $variants[$base.$path_no_slash.$query] = true;
    $variants[$base.$path_with_slash.$query] = true;

    if ($scheme === 'https' || $scheme === 'http'){
      $alt = $scheme === 'https' ? 'http' : 'https';
      $variants[$alt.'://'.$host.$path_no_slash.$query] = true;
      $variants[$alt.'://'.$host.$path_with_slash.$query] = true;
    }

    return array_values(array_keys($variants));
  }

  private static function normalize_href($href){
    $href = trim(html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5));
    if ($href === '' || stripos($href, 'javascript:') === 0 || stripos($href, 'mailto:') === 0){
      return '';
    }
    if (strpos($href, '//') === 0){
      $scheme = wp_parse_url(home_url('/'), PHP_URL_SCHEME) ?: 'https';
      $href = $scheme.':'.$href;
    } elseif (strpos($href, '/') === 0){
      $href = home_url($href);
    } elseif (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $href)){
      $href = home_url('/'.ltrim($href, '/'));
    }
    return self::normalize_url($href);
  }

  private static function extract_links($content, $post_id = 0){
    $post_id = (int) $post_id;
    if ($post_id && isset(self::$post_links_cache[$post_id])){
      return self::$post_links_cache[$post_id];
    }
    $cache_key = $post_id ? 'mw_audit_post_links_'.$post_id : null;
    if ($cache_key){
      $cached = wp_cache_get($cache_key, 'mw_audit');
      if (is_array($cached)){
        self::$post_links_cache[$post_id] = $cached;
        return $cached;
      }
    }

    $links = [];
    if (is_string($content) && $content !== ''){
      if (preg_match_all('~<a\s[^>]*href=(["\'])(.*?)\1~i', $content, $m)){ // extract href targets
        foreach ($m[2] as $href){
          $normalized = self::normalize_href($href);
          if ($normalized){
            $links[$normalized] = true;
          }
        }
      }
    }
    $list = array_keys($links);
    if ($post_id){
      self::$post_links_cache[$post_id] = $list;
      wp_cache_set($cache_key, $list, 'mw_audit', 10 * MINUTE_IN_SECONDS);
    }
    return $list;
  }

  private static function get_post_types(){
    if (self::$post_types !== null){
      return self::$post_types;
    }
    $types = get_post_types(['public'=>true, 'show_ui'=>true], 'names');
    if (!$types){
      $types = ['post','page'];
    }
    self::$post_types = array_values(array_map('sanitize_key', $types));
    return self::$post_types;
  }

  private static function build_lookup_patterns(array $targets){
    $first = $targets ? $targets[0] : '';
    $host = $first ? wp_parse_url($first, PHP_URL_HOST) : '';
    $path = $first ? wp_parse_url($first, PHP_URL_PATH) : '';
    $patterns = [];
    if ($host){
      $patterns[] = $host;
    }
    if ($path && $path !== '/'){
      $patterns[] = trim($path, '/');
    }
    if (!$patterns){
      $patterns[] = 'href=';
    }
    return $patterns;
  }

  static function count_inbound($url){
    $targets = self::url_variants($url);
    if (!$targets){
      return 0;
    }
    $cache_key = 'mw_audit_inbound_'.md5(implode('|', $targets));
    $cached = wp_cache_get($cache_key, 'mw_audit');
    if ($cached !== false){
      return (int) $cached;
    }

    global $wpdb;
    $post_types = self::get_post_types();
    $type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
    $sql = "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($type_placeholders)";
    $params = $post_types;

    $patterns = self::build_lookup_patterns($targets);
    if ($patterns){
      $clauses = [];
      foreach ($patterns as $p){
        $clauses[] = 'post_content LIKE %s';
        $params[] = '%' . $wpdb->esc_like($p) . '%';
      }
      $sql .= ' AND ('.implode(' OR ', $clauses).')';
    }
    $sql .= ' LIMIT 2000';

    $prepared = $wpdb->prepare($sql, ...$params);
    $posts = $wpdb->get_results($prepared);

    $count = 0;
    if ($posts){
      foreach ($posts as $post){
        $links = self::extract_links($post->post_content, (int) $post->ID);
        if (!$links) continue;
        foreach ($links as $link){
          if (in_array($link, $targets, true)){
            $count++;
            break;
          }
        }
      }
    }

    wp_cache_set($cache_key, $count, 'mw_audit', 10 * MINUTE_IN_SECONDS);
    return $count;
  }

  static function scan_outbound($url){
    $normalized = self::normalize_url($url);
    if ($normalized === ''){
      return null;
    }
    $post_id = url_to_postid($normalized);
    if (!$post_id){
      return null;
    }
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish'){
      return null;
    }
    $links = self::extract_links($post->post_content, (int) $post_id);
    if (!$links){
      return [
        'internal' => 0,
        'external' => 0,
        'external_domains' => 0,
      ];
    }
    $site_host = strtolower(wp_parse_url(home_url('/'), PHP_URL_HOST) ?: '');
    $internal = 0;
    $external = 0;
    $domains = [];
    foreach ($links as $link){
      $host = strtolower(wp_parse_url($link, PHP_URL_HOST) ?: '');
      if (!$host){
        continue;
      }
      if ($site_host && $host === $site_host){
        $internal++;
      } else {
        $external++;
        $domains[$host] = true;
      }
    }
    return [
      'internal' => $internal,
      'external' => $external,
      'external_domains' => count($domains),
    ];
  }
}
