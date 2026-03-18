<?php
if (!defined('ABSPATH')) exit;

class MW_Audit_PCMapper {
  public static function build_for_post($post_id, $taxonomy){
    $post_id = (int) $post_id;
    $taxonomy = sanitize_key($taxonomy);
    $source = 'inferred';
    $term_id = 0;

    $rank_key = '_rank_math_primary_'.$taxonomy;
    $rm = get_post_meta($post_id, $rank_key, true);
    if ($rm){
      $term_id = (int) $rm;
      $source = 'rank_math';
    } else {
      $yo_key = '_yoast_wpseo_primary_'.$taxonomy;
      $yo = get_post_meta($post_id, $yo_key, true);
      if ($yo){
        $term_id = (int) $yo;
        $source = 'yoast';
      }
    }

    $terms = get_the_terms($post_id, $taxonomy);
    if (!$term_id && $terms && !is_wp_error($terms)){
      $deep = null;
      $depth = -1;
      foreach ($terms as $term){
        $d = self::term_depth($term);
        if ($d > $depth){
          $depth = $d;
          $deep = $term;
        }
      }
      if ($deep){
        $term_id = $deep->term_id;
        $source = 'first_assigned';
      }
    }

    $name = null;
    $slug = null;
    $parent_id = null;
    $path = null;
    if ($term_id){
      $term = get_term($term_id, $taxonomy);
      if ($term && !is_wp_error($term)){
        $name = $term->name;
        $slug = $term->slug;
        $parent_id = (int) $term->parent;
        $path = self::term_path($term);
      }
    }

    return [
      'post_type'    => get_post_type($post_id) ?: 'post',
      'permalink'    => MW_Audit_Inventory_Builder::permalink($post_id),
      'pc_term_id'   => $term_id ?: null,
      'pc_taxonomy'  => $taxonomy,
      'pc_slug'      => $slug,
      'pc_name'      => $name,
      'pc_parent_id' => $parent_id ?: null,
      'pc_path'      => $path,
      'map_source'   => $source,
      'updated_at'   => current_time('mysql'),
    ];
  }

  private static function term_depth($term){
    $depth = 0;
    $parent = $term->parent;
    while ($parent){
      $pt = get_term($parent, $term->taxonomy);
      if (!$pt || is_wp_error($pt)) break;
      $depth++;
      $parent = $pt->parent;
    }
    return $depth;
  }

  private static function term_path($term){
    $parts = [$term->slug];
    $parent = $term->parent;
    while ($parent){
      $pt = get_term($parent, $term->taxonomy);
      if (!$pt || is_wp_error($pt)) break;
      array_unshift($parts, $pt->slug);
      $parent = $pt->parent;
    }
    return implode('/', $parts);
  }
}
