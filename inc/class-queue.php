<?php
if (!defined('ABSPATH')) exit;

class MW_Audit_Queue {
  const OPTION_PREFIX = 'mw_audit_queue_';
  const TTL = HOUR_IN_SECONDS;

  private static function option_key($name){
    return self::OPTION_PREFIX . sanitize_key($name);
  }

  private static function wrap_payload(array $payload){
    return ['payload' => $payload, 'ts' => time()];
  }

  private static function is_expired(array $record){
    if (!isset($record['ts'])){
      return false;
    }
    return (time() - (int) $record['ts']) > self::TTL;
  }

  public static function get($name){
    $key = self::option_key($name);
    $record = get_option($key, null);
    if (is_array($record) && array_key_exists('payload', $record)){
      if (self::is_expired($record)){
        self::delete($name);
        return null;
      }
      return $record['payload'];
    }
    $legacy = get_transient($name);
    if ($legacy !== false && $legacy !== null){
      $payload = (array) $legacy;
      self::set($name, $payload);
      delete_transient($name);
      return $payload;
    }
    return null;
  }

  public static function set($name, array $payload){
    update_option(self::option_key($name), self::wrap_payload($payload), false);
    delete_transient($name);
  }

  public static function touch($name){
    $key = self::option_key($name);
    $record = get_option($key, null);
    if (is_array($record)){
      $record['ts'] = time();
      update_option($key, $record, false);
    }
  }

  public static function delete($name){
    delete_option(self::option_key($name));
    delete_transient($name);
  }

  public static function exists($name){
    $record = get_option(self::option_key($name), null);
    if (is_array($record)){
      if (self::is_expired($record)){
        self::delete($name);
        return false;
      }
      return true;
    }
    $legacy = get_transient($name);
    if ($legacy !== false && $legacy !== null){
      return true;
    }
    return false;
  }
}
