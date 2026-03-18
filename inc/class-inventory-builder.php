<?php
if (!defined('ABSPATH')) exit;

class MW_Audit_Inventory_Builder {
  private static $permalink_cache = [];
  private static $term_link_cache = [];

  public static function post_types(){
    $types = get_post_types(['public'=>true,'publicly_queryable'=>true], 'names');
    $types = array_unique(array_merge(['post','page'], array_values($types)));
    return array_values(array_diff($types, ['attachment']));
  }

  public static function taxonomies(){
    return get_taxonomies(['public'=>true,'show_ui'=>true], 'names');
  }

  public static function bootstrap_state(){
    $types = self::post_types();
    $taxes = self::taxonomies();

    $posts_total = 0;
    foreach ($types as $type){
      $count = wp_count_posts($type);
      if ($count && !is_wp_error($count)){
        $posts_total += isset($count->publish) ? (int) $count->publish : 0;
      }
    }

    $terms_total = 0;
    foreach ($taxes as $tax){
      $c = wp_count_terms($tax, ['hide_empty'=>false]);
      if (!is_wp_error($c)) $terms_total += (int) $c;
    }

    return [
      'phase'          => 'posts',
      'post_types'     => $types,
      'posts_last_id'  => 0,
      'term_taxonomies'=> array_values($taxes),
      'term_index'     => 0,
      'term_last_id'   => 0,
      'done'           => 0,
      'errors'         => 0,
      'total'          => $posts_total + $terms_total + 1,
      'posts_total'    => $posts_total,
      'terms_total'    => $terms_total,
      'home_added'     => 0,
    ];
  }

  public static function process_state(array $state, $batch){
    $batch = max(1, (int) $batch);
    $processed = 0;

    if (!isset($state['term_last_id'])){
      $state['term_last_id'] = 0;
    }
    if (isset($state['term_offset'])){
      unset($state['term_offset']);
    }

    while ($processed < $batch && $state['phase'] !== 'done'){
      if ($state['phase'] === 'posts'){
        $limit = $batch - $processed;
        $ids = self::fetch_post_batch($state['post_types'], $state['posts_last_id'], $limit);
        if (!$ids){
          $state['phase'] = 'terms';
          continue;
        }
        $rows = [];
        foreach ($ids as $pid){
          $perma = self::get_cached_permalink($pid);
          if (!$perma) {
            continue;
          }
          $path = wp_parse_url($perma, PHP_URL_PATH);
          $slug = $path ? basename(untrailingslashit($path)) : '';
          $post = get_post($pid);
          $published_at = ($post && !empty($post->post_date)) ? $post->post_date : null;
          $rows[] = [
            'norm_url' => $perma,
            'obj_type' => 'post',
            'obj_id'   => $pid,
            'slug'     => $slug,
            'published_at' => $published_at,
          ];
        }
        if ($rows){
          MW_Audit_DB::insert_inventory($rows);
          $state['done'] += count($rows);
        }
        $processed += count($ids);
        $state['posts_last_id'] = (int) end($ids);
        if (count($ids) < $limit){
          $state['phase'] = 'terms';
        }
      } elseif ($state['phase'] === 'terms'){
        $taxCount = count($state['term_taxonomies']);
        if ($state['term_index'] >= $taxCount){
          $state['phase'] = 'home';
          continue;
        }
        $tax = $state['term_taxonomies'][$state['term_index']];
        $limit = max(1, min($batch - $processed, 200));
        $terms = self::fetch_term_batch($tax, $state['term_last_id'], $limit);
        if (!$terms){
          $state['term_index']++;
          $state['term_last_id'] = 0;
          continue;
        }
        $rows = [];
        $highest_id = $state['term_last_id'];
        foreach ($terms as $term_row){
          $term_id = (int) $term_row['term_id'];
          if ($term_id > $highest_id){
            $highest_id = $term_id;
          }
          $url = self::get_cached_term_link($term_id, $tax);
          if (!$url){
            continue;
          }
          $rows[] = [
            'norm_url' => $url,
            'obj_type' => 'term',
            'obj_id'   => $term_id,
            'slug'     => $term_row['slug'],
          ];
        }
        if ($rows){
          MW_Audit_DB::insert_inventory($rows);
          $state['done'] += count($rows);
        }
        $processed += count($terms);
        $state['term_last_id'] = $highest_id;
        if (count($terms) < $limit){
          $state['term_index']++;
          $state['term_last_id'] = 0;
        }
      } elseif ($state['phase'] === 'home'){
        if (empty($state['home_added'])){
          MW_Audit_DB::insert_inventory([
            ['norm_url'=>home_url('/'),'obj_type'=>'home','obj_id'=>0,'slug'=>'']
          ]);
          $state['done']++;
          $state['home_added'] = 1;
        }
        $state['phase'] = 'done';
      }
    }

    return $state;
  }

  public static function reset_caches(){
    self::$permalink_cache = [];
    self::$term_link_cache = [];
  }

  public static function permalink($post_id){
    return self::get_cached_permalink($post_id);
  }

  public static function term_link($term_id, $taxonomy){
    return self::get_cached_term_link($term_id, $taxonomy);
  }

  public static function fetch_post_batch($types, $last_id, $limit){
    global $wpdb;
    if (empty($types)) return [];
    $limit = max(1, (int)$limit);
    $placeholders = implode(',', array_fill(0, count($types), '%s'));
    $sql = "SELECT ID FROM {$wpdb->posts}
            WHERE post_status = 'publish'
              AND post_type IN ($placeholders)
              AND ID > %d
            ORDER BY ID ASC
            LIMIT %d";
    $params = array_merge($types, [(int)$last_id, $limit]);
    $query = $wpdb->prepare($sql, ...$params);
    $ids = $wpdb->get_col($query);
    if (!$ids) return [];
    return array_map('intval', $ids);
  }

  public static function count_posts(array $types){
    $total = 0;
    foreach ($types as $type){
      $counts = wp_count_posts($type);
      if ($counts && !is_wp_error($counts)){
        $total += isset($counts->publish) ? (int) $counts->publish : 0;
      }
    }
    return $total;
  }

  private static function get_cached_permalink($post_id){
    $post_id = (int) $post_id;
    if ($post_id <= 0) return '';
    if (isset(self::$permalink_cache[$post_id])){
      return self::$permalink_cache[$post_id];
    }
    $cache_key = 'mw_audit_permalink_'.$post_id;
    $cached = wp_cache_get($cache_key, 'mw_audit');
    if ($cached !== false){
      self::$permalink_cache[$post_id] = (string) $cached;
      return self::$permalink_cache[$post_id];
    }
    $permalink = get_permalink($post_id);
    if (!is_string($permalink)){
      $permalink = '';
    }
    self::$permalink_cache[$post_id] = $permalink;
    wp_cache_set($cache_key, $permalink, 'mw_audit', 10 * MINUTE_IN_SECONDS);
    return $permalink;
  }

  private static function get_cached_term_link($term_id, $taxonomy){
    $term_id = (int) $term_id;
    if ($term_id <= 0 || !$taxonomy) return '';
    $key = $taxonomy.'|'.$term_id;
    if (isset(self::$term_link_cache[$key])){
      return self::$term_link_cache[$key];
    }
    $cache_key = 'mw_audit_term_link_'.md5($key);
    $cached = wp_cache_get($cache_key, 'mw_audit');
    if ($cached !== false){
      self::$term_link_cache[$key] = (string) $cached;
      return self::$term_link_cache[$key];
    }
    $link = get_term_link($term_id, $taxonomy);
    if (is_wp_error($link)){
      $link = '';
    }
    self::$term_link_cache[$key] = $link;
    wp_cache_set($cache_key, $link, 'mw_audit', 10 * MINUTE_IN_SECONDS);
    return $link;
  }

  private static function fetch_term_batch($taxonomy, $last_term_id, $limit){
    global $wpdb;
    $limit = max(1, (int) $limit);
    $last_term_id = max(0, (int) $last_term_id);
    $taxonomy = sanitize_key($taxonomy);
    $sql = $wpdb->prepare(
      "SELECT t.term_id, t.slug
       FROM {$wpdb->terms} t
       INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
       WHERE tt.taxonomy = %s AND t.term_id > %d
       ORDER BY t.term_id ASC
       LIMIT %d",
      $taxonomy,
      $last_term_id,
      $limit
    );
    return $wpdb->get_results($sql, ARRAY_A) ?: [];
  }
}
