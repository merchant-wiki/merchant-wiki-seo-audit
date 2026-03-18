<?php
if (!defined('ABSPATH')) exit;

if (defined('WP_CLI') && WP_CLI) {
  class MW_Audit_CLI {
    public function inventory($args, $assoc_args){
      WP_CLI::log('Rebuilding audit inventory...');
      MW_Audit_Inventory::rebuild_inventory();
      WP_CLI::success('Inventory rebuild complete.');
    }

    public function refresh($args, $assoc_args){
      $mode = isset($assoc_args['mode']) ? strtolower($assoc_args['mode']) : 'full';
      $mode = $mode === 'http' ? 'http_only' : 'full';
      $batch = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 100;
      $throttle = isset($assoc_args['throttle']) ? max(0, (int) $assoc_args['throttle']) : 0;
      $total = MW_Audit_DB::count_inventory();
      if ($total === 0){
        WP_CLI::error('Inventory is empty. Run wp mw-audit inventory first.');
      }
      WP_CLI::log(sprintf('Scanning %d URLs (%s mode)...', $total, $mode));
      $processed = 0;
      $this->walk_inventory($batch, function($url) use ($mode, $throttle, &$processed, $total){
        $row = MW_Audit_UrlScanner::scan($url, $mode, ['head'=>3,'get'=>4,'throttle'=>$throttle]);
        MW_Audit_DB::upsert_status($url, $row);
        $processed++;
        if ($processed % 25 === 0){
          WP_CLI::log(sprintf('Processed %d/%d', $processed, $total));
        }
      });
      if ($processed % 25 !== 0){
        WP_CLI::log(sprintf('Processed %d/%d', $processed, $total));
      }
      MW_Audit_DB::set_flag($mode === 'http_only' ? 'http' : 'os', 'done');
      update_option('mw_audit_last_update', current_time('mysql'));
      WP_CLI::success('Scan completed.');
    }

    public function links($args, $assoc_args){
      $batch = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 100;
      $total = MW_Audit_DB::count_inventory();
      if ($total === 0){
        WP_CLI::error('Inventory is empty. Run wp mw-audit inventory first.');
      }
      WP_CLI::log(sprintf('Counting inbound links for %d URLs...', $total));
      $processed = 0;
      $this->walk_inventory($batch, function($url) use (&$processed, $total){
        $cnt = MW_Audit_ILinks::count_inbound($url);
        MW_Audit_DB::upsert_status($url, ['inbound_links'=>$cnt,'updated_at'=>current_time('mysql')]);
        $processed++;
        if ($processed % 25 === 0){
          WP_CLI::log(sprintf('Processed %d/%d', $processed, $total));
        }
      });
      if ($processed % 25 !== 0){
        WP_CLI::log(sprintf('Processed %d/%d', $processed, $total));
      }
      MW_Audit_DB::set_flag('link', 'done');
      WP_CLI::success('Inbound link scan completed.');
    }

    public function sitemaps($args, $assoc_args){
      WP_CLI::log('Preparing sitemap cache...');
      $payload = MW_Audit_Sitemap::prepare_cache();
      WP_CLI::success(sprintf('Cached %d sitemap bodies from %d sources.', $payload['count'], count($payload['sources'])));
    }

    public function google_index($args, $assoc_args){
      if (!MW_Audit_GSC::is_connected()){
        WP_CLI::error('Google Search Console is not connected. Configure credentials and connect first.');
      }
      $property = MW_Audit_GSC::get_property();
      if (!$property){
        WP_CLI::error('Select a Google Search Console property before running this command.');
      }
      $force = isset($assoc_args['force']);
      $batch = isset($assoc_args['batch']) ? max(1, min(100, (int)$assoc_args['batch'])) : 100;
      $total = MW_Audit_DB::count_inventory();
      if ($total === 0){
        WP_CLI::error('Inventory is empty. Run wp mw-audit inventory first.');
      }
      WP_CLI::log(sprintf('Inspecting Google index status for %d URLs (property: %s)...', $total, $property));
      MW_Audit_DB::set_flag('gindex','running');
      $processed = 0;
      $errors = [];
      $after_id = 0;
      do {
        $rows = MW_Audit_DB::get_inventory_chunk($after_id, $batch);
        if (!$rows){
          break;
        }
        $map = [];
        foreach ($rows as $row){
          $map[(int)$row['id']] = $row['norm_url'];
        }
        $inspect = MW_Audit_GSC::inspect_urls(array_values($map), $force);
        if (!empty($inspect['errors'])){
          $errors = array_merge($errors, $inspect['errors']);
        }
        foreach ($map as $id => $url){
          $result = $inspect['results'][$url] ?? null;
          if ($result !== null){
            MW_Audit_DB::upsert_status($url, [
              'indexed_in_google' => $result ? 1 : 0,
              'updated_at'        => current_time('mysql'),
            ]);
          }
          $processed++;
          if ($processed % 25 === 0){
            WP_CLI::log(sprintf('Processed %d/%d', $processed, $total));
          }
          $after_id = $id;
        }
      } while ($rows && count($rows) === $batch);
      if ($processed % 25 !== 0){
        WP_CLI::log(sprintf('Processed %d/%d', $processed, $total));
      }
      if ($errors){
        foreach (array_slice($errors, 0, 10) as $err){
          WP_CLI::warning($err);
        }
        if (count($errors) > 10){
          WP_CLI::warning(sprintf('%d additional errors suppressed.', count($errors) - 10));
        }
      }
      MW_Audit_DB::set_flag('gindex','done');
      WP_CLI::success('Google index inspection completed.');
    }

    public function next_steps($args, $assoc_args){
      if (empty($args)){
        WP_CLI::error('Specify Next Steps action: quick-audit, manual-indexing, content-pruning, snapshot-save, snapshot-diff, or snapshot-list.');
      }
      $action = array_shift($args);
      switch ($action){
        case 'quick-audit':
          $path = \WP_CLI\Utils\get_flag_value($assoc_args, 'out', 'mw-quick-audit.csv');
          $rows = MW_Audit_Next_Steps::quick_audit_rows();
          $this->write_next_steps_file($path, $rows, MW_Audit_Next_Steps::quick_header());
          break;
        case 'manual-indexing':
          $threshold = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'threshold', 0);
          $path = \WP_CLI\Utils\get_flag_value($assoc_args, 'out', sprintf('mw-manual-indexing-%dlinks.csv', $threshold));
          $rows = MW_Audit_Next_Steps::manual_index_rows($threshold);
          $this->write_next_steps_file($path, $rows, MW_Audit_Next_Steps::manual_header());
          break;
        case 'content-pruning':
          $path = \WP_CLI\Utils\get_flag_value($assoc_args, 'out', 'mw-content-pruning.csv');
          $rows = MW_Audit_Next_Steps::content_pruning_rows();
          $this->write_next_steps_file($path, $rows, MW_Audit_Next_Steps::pruning_header());
          break;
        case 'snapshot-save':
          $label = \WP_CLI\Utils\get_flag_value($assoc_args, 'label', '');
          $result = MW_Audit_Next_Steps::create_snapshot($label);
          if (is_wp_error($result)){
            WP_CLI::error($result->get_error_message());
          }
          WP_CLI::success(sprintf('Snapshot "%s" saved (%d rows).', $result['label'], $result['rows']));
          break;
        case 'snapshot-diff':
          $before = \WP_CLI\Utils\get_flag_value($assoc_args, 'before', '');
          $after  = \WP_CLI\Utils\get_flag_value($assoc_args, 'after', '');
          if (!$before || !$after){
            WP_CLI::error('Provide --before=<snapshot-id> and --after=<snapshot-id>.');
          }
          if ($before === $after){
            WP_CLI::error('Before/after snapshots must be different.');
          }
          $rows = MW_Audit_Next_Steps::diff_snapshots_rows($before, $after);
          if (is_wp_error($rows)){
            WP_CLI::error($rows->get_error_message());
          }
          $filename = sprintf('mw-launch-diff-%s-vs-%s.csv', $before, $after);
          $path = \WP_CLI\Utils\get_flag_value($assoc_args, 'out', $filename);
          $this->write_next_steps_file($path, $rows, MW_Audit_Next_Steps::snapshot_diff_header());
          break;
        case 'snapshot-list':
          $list = MW_Audit_Next_Steps::list_snapshots();
          if (empty($list)){
            WP_CLI::log('No snapshots saved yet.');
            break;
          }
          $items = [];
          foreach ($list as $snap){
            $items[] = [
              'id'     => $snap['id'],
              'label'  => $snap['label'],
              'created'=> $snap['created_at'],
              'rows'   => $snap['rows'],
            ];
          }
          \WP_CLI\Utils\format_items('table', $items, ['id','label','created','rows']);
          break;
        default:
          WP_CLI::error(sprintf('Unknown Next Steps action: %s', $action));
      }
    }

    private function walk_inventory($batch, callable $callback){
      $after_id = 0;
      do {
        $rows = MW_Audit_DB::get_inventory_chunk($after_id, $batch);
        if (!$rows){
          break;
        }
        foreach ($rows as $row){
          $callback($row['norm_url']);
          $after_id = (int) $row['id'];
        }
      } while (count($rows) === $batch);
    }

    private function write_next_steps_file($path, array $rows, array $header_map){
      $result = MW_Audit_Next_Steps::write_csv_to_path($path, $header_map, $rows);
      if (is_wp_error($result)){
        WP_CLI::error($result->get_error_message());
      }
      WP_CLI::success(sprintf('Saved %d rows to %s', count($rows), $path));
    }
  }

  WP_CLI::add_command('mw-audit inventory', [MW_Audit_CLI::class, 'inventory']);
  WP_CLI::add_command('mw-audit refresh',   [MW_Audit_CLI::class, 'refresh']);
  WP_CLI::add_command('mw-audit links',     [MW_Audit_CLI::class, 'links']);
  WP_CLI::add_command('mw-audit sitemaps',  [MW_Audit_CLI::class, 'sitemaps']);
  WP_CLI::add_command('mw-audit google-index', [MW_Audit_CLI::class, 'google_index']);
  WP_CLI::add_command('mw-audit next-steps', [MW_Audit_CLI::class, 'next_steps']);
}
