<?php
if (!defined('ABSPATH')) exit;

class MW_Audit_UrlScanner {
  const MAX_RETRIES    = 2;
  const MAX_REDIRECTS  = 5;

  private static function maybe_throttle($throttle){
    $delay = max(0, (int) $throttle);
    if ($delay > 0){
      usleep($delay);
    }
  }

  private static function is_fatal_error($error){
    if (!is_wp_error($error)){
      return false;
    }
    $code = $error->get_error_code();
    $message = strtolower($error->get_error_message());
    $fatal_codes = [
      'could_not_resolve_host',
      'ssl_connect_error',
      'could_not_connect',
      'http_request_failed',
      'dns_not_resolved',
      'name_lookup_timeout',
    ];
    if (in_array($code, $fatal_codes, true)){
      if ($code === 'http_request_failed'){
        if (strpos($message, 'resolve host') === false && strpos($message, 'could not resolve') === false){
          return false;
        }
      }
      return true;
    }
    return false;
  }

  private static function remote_with_retry(callable $callback, $retries = self::MAX_RETRIES, $throttle = 0){
    $attempt = 0;
    $response = null;
    do {
      if ($attempt > 0){
        self::maybe_throttle($throttle);
      }
      $response = $callback($attempt);
      if (!is_wp_error($response)){
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 0){
          return $response;
        }
      } else {
        if (self::is_fatal_error($response)){
          return $response;
        }
      }
      $attempt++;
    } while ($attempt <= $retries);
    return $response;
  }

  private static function resolve_redirect($location, $base_url){
    if (is_array($location)){
      $location = reset($location);
    }
    if ($location === '' || $location === null){
      return false;
    }
    if (!preg_match('~^https?://~i', $location)){
      if (!class_exists('WP_Http')){
        require_once ABSPATH . WPINC . '/class-wp-http.php';
      }
      $location = WP_Http::make_absolute_url($location, $base_url);
    }
    return wp_http_validate_url($location) ? $location : false;
  }

  private static function head_with_redirects($url, $timeout, $throttle){
    $current = $url;
    $visited = [];
    $redirects = 0;
    $last_location = null;

    while (true){
      self::maybe_throttle($throttle);
      $response = self::remote_with_retry(function() use ($current, $timeout){
        return wp_remote_head($current, ['timeout'=>$timeout,'redirection'=>0]);
      }, self::MAX_RETRIES, $throttle);

      if (is_wp_error($response)){
        return [$response, $current, $last_location];
      }

      $code = (int) wp_remote_retrieve_response_code($response);
      if (!in_array($code, [301,302,303,307,308], true)){
        return [$response, $current, $last_location];
      }

      $location = self::resolve_redirect(wp_remote_retrieve_header($response, 'location'), $current);
      if (!$location){
        return [$response, $current, $last_location];
      }

      if (isset($visited[$location]) || $redirects >= self::MAX_REDIRECTS){
        return [new WP_Error('mw_audit_redirect_loop', __('Redirect loop detected','merchant-wiki-audit'), ['url'=>$current,'location'=>$location]), $current, $location];
      }

      $visited[$current] = true;
      $last_location = $location;
      $current = $location;
      $redirects++;
    }
  }

  public static function scan($url, $mode = 'full', array $timeouts = []){
    $tout_head = isset($timeouts['head']) ? max(1, (int) $timeouts['head']) : 3;
    $tout_get  = isset($timeouts['get'])  ? max(1, (int) $timeouts['get'])  : 4;
    $throttle  = isset($timeouts['throttle']) ? max(0, (int) $timeouts['throttle']) : 0;

    $result = [
      'http_status'     => null,
      'redirect_to'     => null,
      'canonical'       => null,
      'robots_meta'     => null,
      'noindex'         => null,
      'schema_type'     => null,
      'in_sitemap'      => null,
      'robots_disallow' => null,
      'updated_at'      => current_time('mysql'),
    ];

    [$head, $final_url, $redirect_target] = self::head_with_redirects($url, $tout_head, $throttle);

    if (is_wp_error($head)){
      MW_Audit_DB::log('HTTP error (HEAD) for '.$url.': '.$head->get_error_message());
      $result['http_status'] = self::is_fatal_error($head) ? -1 : 0;
      return $result;
    }

    $code = (int) wp_remote_retrieve_response_code($head);
    $result['http_status'] = $code;
    if ($redirect_target){
      $safe_redirect = esc_url_raw($redirect_target);
      $result['redirect_to'] = $safe_redirect !== '' ? $safe_redirect : $redirect_target;
    }

    $robots = MW_Audit_Robots::fetch_rules($tout_get);
    $result['robots_disallow'] = MW_Audit_Robots::disallowed($robots['body'] ?? '', $final_url);
    $result['in_sitemap'] = MW_Audit_Sitemap::url_in_sitemaps($final_url);

    if ($code !== 200){
      return $result;
    }

    $accept_headers = ['Accept' => 'text/html'];
    $get_timeout = ($mode === 'http_only') ? min($tout_get, 3) : $tout_get;

    self::maybe_throttle($throttle);
    $get_response = self::remote_with_retry(function() use ($final_url, $get_timeout, $accept_headers){
      return wp_remote_get($final_url, ['timeout'=>$get_timeout,'headers'=>$accept_headers]);
    }, self::MAX_RETRIES, $throttle);

    if (is_wp_error($get_response)){
      MW_Audit_DB::log('HTTP error (GET) for '.$final_url.': '.$get_response->get_error_message());
      $result['http_status'] = self::is_fatal_error($get_response) ? -1 : $result['http_status'];
      return $result;
    }

    if ((int) wp_remote_retrieve_response_code($get_response) !== 200){
      return $result;
    }

    $html = wp_remote_retrieve_body($get_response);
    if ($html === ''){
      return $result;
    }

    $flags = MW_Audit_SEOFlags::parse_html($html);
    return array_merge($result, $flags);
  }
}
