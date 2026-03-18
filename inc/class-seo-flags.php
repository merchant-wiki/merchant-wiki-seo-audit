<?php
if (!defined('ABSPATH')) exit;
class MW_Audit_SEOFlags {
  static function parse_html($html){
    $out = ['canonical'=>null,'robots_meta'=>null,'noindex'=>null,'schema_type'=>null];
    if (preg_match('~<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)~i', $html, $m)) {
      $href = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
      $sanitized = esc_url_raw($href);
      $out['canonical'] = $sanitized !== '' ? $sanitized : sanitize_text_field($href);
    }
    if (preg_match('~<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)~i', $html, $m)){
      $content = sanitize_text_field(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
      $out['robots_meta']=$content; $out['noindex'] = (stripos($content,'noindex')!==false)?1:0;
    }
    if (preg_match('~application/ld\+json~i', $html)) $out['schema_type']='has_jsonld';
    return $out;
  }
}
