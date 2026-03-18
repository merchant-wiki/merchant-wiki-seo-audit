(function($){
  const SETTINGS = (window.MW_AUDIT && MW_AUDIT.settings) ? MW_AUDIT.settings : {};
  const baseHeadTimeout = parseInt(SETTINGS.timeouts ? SETTINGS.timeouts.head : 8, 10) || 8;
  const baseGetTimeout = parseInt(SETTINGS.timeouts ? SETTINGS.timeouts.get : 12, 10) || 12;
  const profiles = {
    fast: {
      batch: 64,
      budget: 45.0,
      tout_head: Math.max(1, baseHeadTimeout + 2),
      tout_get: Math.max(1, baseGetTimeout + 2),
      ewma_alpha: 0.25,
      clampMin: 4,
      clampMax: 96
    },
    standard: {
      batch: 32,
      budget: 35.0,
      tout_head: baseHeadTimeout,
      tout_get: baseGetTimeout,
      ewma_alpha: 0.25,
      clampMin: 4,
      clampMax: 64
    },
    safe: {
      batch: 16,
      budget: 25.0,
      tout_head: Math.max(1, baseHeadTimeout - 2),
      tout_get: Math.max(1, baseGetTimeout - 2),
      ewma_alpha: 0.25,
      clampMin: 4,
      clampMax: 48
    }
  };

  function getNonce(action){
    if (!window.MW_AUDIT || !MW_AUDIT.nonces) return '';
    return MW_AUDIT.nonces[action] || '';
  }

  function adaptiveLoop(boxId, startAction, stepAction, optsOrGetter, hooks){
    const $box=$(boxId); if(!$box.length) return;
    hooks = hooks || {};
    const $fill=$box.find('.bar-fill'), $done=$box.find('.done'),
          $total=$box.find('.total'), $eta=$box.find('.eta'),
          $errors=$box.find('.errors'), $percent=$box.find('.percent'),
          $batchView=$box.find('.batch'), $phase=$box.find('.phase');
    let running=false, total=0, ewma=null;
    const clonePreset = (preset) => Object.assign({}, preset);
    let curr = (typeof optsOrGetter==='function') ? clonePreset(optsOrGetter()) : clonePreset(optsOrGetter);
    let batch=curr.batch, budget=curr.budget, tout_head=curr.tout_head, tout_get=curr.tout_get;
    let prevDone = 0;
    let prevErrorsTotal = 0;
    let consecutiveErrorBatches = 0;
    let windowProcessed = 0;
    let windowErrors = 0;

    function refreshOpts(){
      if (typeof optsOrGetter==='function'){
        curr = clonePreset(optsOrGetter());
        batch = curr.batch;
        budget = curr.budget;
        tout_head = curr.tout_head;
        tout_get = curr.tout_get;
      }
    }

    function paint(state){
      $box.removeClass('mw-running mw-done');
      if (state==='running') $box.addClass('mw-running');
      if (state==='done')    $box.addClass('mw-done');
    }

    function poll(){
      if(!running) return;
      refreshOpts();
      const tStart = performance.now();
      const payload = {
        action: stepAction, nonce: getNonce(stepAction),
        batch: batch, budget: budget,
        tout_head: tout_head, tout_get: tout_get
      };
      if (curr.extra && typeof curr.extra === 'object'){
        Object.keys(curr.extra).forEach(k => {
          payload[k] = curr.extra[k];
        });
      }
      $.post(MW_AUDIT.ajax, payload, function(res){
        const tEnd = performance.now();
        const elapsed = Math.max(1, (tEnd - tStart)/1000); // sec
        if(!res || !res.success){ running=false; alert(MW_AUDIT.i18n.error); return; }
        const d=res.data; total = d.total||total;
        const p = (d.i && total) ? Math.round(d.i/total*100) : 0;
        $fill.css('width', p+'%'); $done.text(d.done||0); $total.text(total);
        $errors.text(d.errors||0); $percent.text(p+'%');
        if ($phase.length) {
          $phase.text(d.phase ? d.phase : '—');
        }

        const clampMin = curr.clampMin != null ? curr.clampMin : 1;
        const clampMax = curr.clampMax != null ? curr.clampMax : 500;
        const progressed = Math.max(1, Math.min(batch, (d.done||0)));
        const perReqAvg = elapsed / progressed;
        ewma = (ewma==null) ? perReqAvg : (curr.ewma_alpha*perReqAvg + (1-curr.ewma_alpha)*ewma);

        const target = Math.max(1, Math.floor((budget / Math.max(ewma, 0.02))));
        batch = Math.max(clampMin, Math.min(clampMax, target));

        const doneTotal = d.done || 0;
        const errorsTotal = d.errors || 0;
        const deltaDone = Math.max(0, doneTotal - prevDone);
        const deltaErrors = Math.max(0, errorsTotal - prevErrorsTotal);
        windowProcessed += deltaDone;
        windowErrors += deltaErrors;
        if (windowProcessed > 50){
          const factor = 50 / windowProcessed;
          windowProcessed *= factor;
          windowErrors *= factor;
        }
        if (deltaErrors > 0){
          consecutiveErrorBatches += 1;
        } else {
          consecutiveErrorBatches = 0;
        }
        if (consecutiveErrorBatches >= 3){
          batch = Math.max(clampMin, Math.floor(batch / 2));
          consecutiveErrorBatches = 0;
        }
        const errorRate = (windowProcessed > 0) ? (windowErrors / windowProcessed) : 0;
        if (errorRate >= 0.10){
          batch = Math.max(clampMin, Math.floor(batch / 2));
          windowProcessed *= 0.7;
          windowErrors *= 0.7;
        }
        batch = Math.max(clampMin, Math.min(clampMax, batch));
        prevDone = doneTotal;
        prevErrorsTotal = errorsTotal;
        $batchView.text(batch);

        if (p>0 && p<100){
          const remain = total - (d.i||0);
          const etaSec = Math.round(remain * Math.max(ewma, 0.02));
          $eta.text(etaSec+'s');
        } else { $eta.text('—'); }

        if (typeof hooks.onProgress === 'function'){
          hooks.onProgress({ box: $box, data: d });
        }

        if(d.finished || (d.i>=total)){ running=false; paint('done'); setTimeout(()=>location.reload(), 800); return; }
        if (Array.isArray(d.messages) && d.messages.length){
          console.warn('MW Audit', d.messages.join('\n'));
        }
        setTimeout(poll, 120);
      });
    }

    $box.find('.start').on('click', function(e){
      e.preventDefault();
      if ($(this).prop('disabled')) return;
      refreshOpts();
      prevDone = 0;
      prevErrorsTotal = 0;
      consecutiveErrorBatches = 0;
      windowProcessed = 0;
      windowErrors = 0;
      const startPayload = { action: startAction, nonce: getNonce(startAction) };
      if (curr.extra && typeof curr.extra === 'object'){
        Object.keys(curr.extra).forEach(k => {
          startPayload[k] = curr.extra[k];
        });
      }
      $.post(MW_AUDIT.ajax, startPayload, function(res){
        if(!res || !res.success){ alert(MW_AUDIT.i18n.error); return; }
        const total = res.data.total||0;
        $box.find('.total').text(total);
        $box.find('.done').text(0);
        $box.find('.errors').text(0);
        ewma=null; $batchView.text(batch);
        if ($phase.length) { $phase.text('—'); }
        running=true; paint('running'); poll();
        if (typeof hooks.onStart === 'function'){
          hooks.onStart(res.data || {}, $box);
        }
      });
    });
    $box.find('.stop').on('click', function(e){ e.preventDefault(); running=false; });
    $box.find('.resume').on('click', function(e){
      e.preventDefault();
      if ($(this).prop('disabled')) return;
      if(!running){ running=true; poll(); }
    });
    const $reset = $box.find('.reset-lock');
    if ($reset.length){
      $reset.on('click', function(e){
        e.preventDefault();
        if ($(this).prop('disabled')) return;
        const $btn = $(this);
        $btn.prop('disabled', true);
        $.post(MW_AUDIT.ajax, {
          action: 'mw_gsc_reset_queue',
          nonce: getNonce('mw_gsc_reset_queue')
        }, function(res){
          $btn.prop('disabled', false);
          if (!res || !res.success){
            alert(MW_AUDIT.i18n.error || 'Error');
            return;
          }
          location.reload();
        }).fail(function(){
          $btn.prop('disabled', false);
          alert(MW_AUDIT.i18n.error || 'Error');
        });
      });
    }
  }

  function updateGindexEstimate($box, payload){
    if (!$box || !$box.length) return;
    const queuedEl = $box.find('#mw-gindex-queued');
    const staleEl = $box.find('#mw-gindex-stale');
    const remainingEl = $box.find('#mw-gindex-remaining');
    if (!queuedEl.length || !staleEl.length || !remainingEl.length) return;
    const toInt = (value, fallback) => {
      if (typeof value === 'number' && !Number.isNaN(value)) return value;
      if (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(parseInt(value, 10))){
        return parseInt(value, 10);
      }
      return fallback;
    };
    const defQueued = toInt(queuedEl.attr('data-default'), 0);
    const defStale = toInt(staleEl.attr('data-default'), 0);
    const defRemaining = toInt(remainingEl.attr('data-default'), 0);
    const queued = toInt(payload.total ?? payload.queue_total ?? payload.queued_total ?? payload.queue_candidates, defQueued);
    const stale = toInt(payload.stale_total ?? (payload.meta ? payload.meta.stale_total : undefined), defStale);
    let remaining = payload.stale_remaining ?? (payload.meta ? payload.meta.stale_remaining : undefined);
    remaining = (remaining === undefined || remaining === null) ? null : toInt(remaining, null);
    if (remaining === null && typeof stale === 'number'){
      const doneVal = toInt(payload.done ?? payload.i, 0);
      remaining = Math.max(0, stale - doneVal);
    }
    if (remaining === null){
      remaining = defRemaining;
    }
    const normalizedRemaining = Math.max(0, remaining);
    queuedEl.text(queued).attr('data-default', queued);
    staleEl.text(stale).attr('data-default', stale);
    remainingEl.text(normalizedRemaining).attr('data-default', normalizedRemaining);
  }

  // On-site signals: profile selector
  (function(){
    const $wrap = $('#mw-audit-progress'); if(!$wrap.length) return;
    function profileOpts(){
      const key = $wrap.find('select.profile').val() || 'standard';
      return profiles[key] || profiles.standard;
    }
    $wrap.find('select.profile').on('change', function(){
      const preset = profiles[$(this).val()] || profiles.standard;
      $wrap.find('.batch').text(preset.batch);
    });
    adaptiveLoop('#mw-audit-progress','mw_audit_refresh_start','mw_audit_refresh_step', profileOpts);
  })();

  // Inventory rebuild
  adaptiveLoop('#mw-inventory-progress','mw_audit_inventory_start','mw_audit_inventory_step', {batch:500,budget:6.0,tout_head:3,tout_get:4,ewma_alpha:0.25,clampMin:50,clampMax:800});

  // HTTP-only
  adaptiveLoop('#mw-http-progress','mw_audit_http_start','mw_audit_http_step', profiles.fast);

  // PC map
  adaptiveLoop('#mw-pc-progress','mw_audit_pc_start','mw_audit_pc_step', {batch:200,budget:6.0,tout_head:3,tout_get:4,ewma_alpha:0.25,clampMin:50,clampMax:400});

  // Internal links
  adaptiveLoop('#mw-links-progress','mw_audit_links_start','mw_audit_links_step', profiles.standard);

  // Outbound links
  adaptiveLoop('#mw-outbound-progress','mw_audit_outbound_start','mw_audit_outbound_step', {batch:60,budget:6.0,tout_head:3,tout_get:4,ewma_alpha:0.25,clampMin:20,clampMax:300});

  // Google index status
  const gindexHooks = {
    onStart: function(data, $box){
      updateGindexEstimate($box, data || {});
    },
    onProgress: function(payload){
      if (!payload || !payload.box) return;
      updateGindexEstimate(payload.box, payload.data || {});
    }
  };
  adaptiveLoop('#mw-gindex-progress','mw_gsc_enqueue_all','mw_gsc_process_batch', function(){
    const $box = $('#mw-gindex-progress');
    if (!$box.length) return {batch:5,budget:8.0,tout_head:3,tout_get:4};
    let batch = parseInt($box.find('.batch-input').val(), 10);
    if (isNaN(batch) || batch < 1) batch = 5;
    batch = Math.min(100, Math.max(1, batch));
    const $staleToggle = $box.find('.only-stale');
    const onlyStale = $staleToggle.length ? $staleToggle.is(':checked') : true;
    const force = onlyStale ? 0 : 1;
    return {
      batch: batch,
      budget: 8.0,
      tout_head: 3,
      tout_get: 4,
      extra: { force: force }
    };
  }, gindexHooks);

  // Sitemaps prepare
  (function(){
    const $box=$('#mw-sitemaps'); if(!$box.length) return;
    $box.find('.sm-prepare').on('click', function(e){
      e.preventDefault();
      const $btn=$(this); $btn.prop('disabled',true).text('Working...');
      $.post(MW_AUDIT.ajax, {action:'mw_audit_sm_prepare', nonce: getNonce('mw_audit_sm_prepare')}, function(res){
        $btn.prop('disabled',false).text('Prepare now');
        if(!res || !res.success){ alert(MW_AUDIT.i18n.error); return; }
        const d=res.data;
        $box.find('.sm-count').text(d.count);
        $box.find('.sm-sources').text(d.sources.length);
        $box.find('.sm-age').text('0s');
        $box.removeClass('mw-running mw-fail').addClass('mw-done');

        const $list = $('#mw-sitemaps-list');
        if ($list.length){
          $list.empty();
          if (Array.isArray(d.sources) && d.sources.length){
            d.sources.forEach((src) => {
              const safeSrc = String(src);
              const $link = $('<a>', { href: safeSrc, target: '_blank', rel: 'noopener' }).text(safeSrc);
              $('<li>').append($link).appendTo($list);
            });
          } else {
            $('<li>').text('—').appendTo($list);
          }
        }

        const $step = $('#mw-step-sm');
        if ($step.length){
          $step.removeClass('mw-running mw-fail').addClass('mw-done');
        }
        const $stepPill = $('#mw-step-pill-sm');
        if ($stepPill.length){
          $stepPill.removeClass('ok warn neutral').addClass('ok').text(MW_AUDIT.i18n.done || 'Done');
        }
      });
    });
  })();

  // Filters autosubmit
  (function(){
    const form = document.getElementById('mw-filters-form');
    if (!form) return;
    form.querySelectorAll('.mw-autosubmit').forEach((input) => {
      input.addEventListener('change', () => form.submit());
    });
  })();

  // TTL selector
  (function(){
    const select = document.getElementById('mw-gsc-ttl');
    if (!select) return;
    const statusEl = document.getElementById('mw-gsc-ttl-status');
    select.addEventListener('change', function(){
      const ttl = this.value;
      const nonce = getNonce('mw_gsc_save_ttl');
      if (!nonce) return;
      select.disabled = true;
      $.post(MW_AUDIT.ajax, { action: 'mw_gsc_save_ttl', nonce: nonce, ttl: ttl }, function(res){
        select.disabled = false;
        if (!res || !res.success){
          alert(MW_AUDIT.i18n.error || 'Error');
          return;
        }
        if (statusEl){
          statusEl.textContent = MW_AUDIT.i18n.ttlSaved || 'Saved';
          statusEl.classList.add('show');
          setTimeout(() => statusEl.classList.remove('show'), 2000);
        }
      });
    });
  })();

  // Sheets sync handler
  (function(){
    const btn = document.getElementById('mw-gsc-sync-button');
    if (!btn) return;
    const input = document.getElementById('mw-gsc-sheet-input');
    const range = document.getElementById('mw-gsc-sheet-range');
    const override = document.getElementById('mw-gsc-sync-override');
    const statusEl = document.getElementById('mw-gsc-sync-status');
    btn.addEventListener('click', function(){
      const sheetVal = input ? input.value.trim() : '';
      const rangeVal = range ? range.value.trim() : '';
      if (!sheetVal){
        if (statusEl){
          statusEl.textContent = MW_AUDIT.i18n.sheetRequired || 'Enter Sheet ID';
          statusEl.classList.add('show');
        }
        return;
      }
      const nonce = getNonce('mw_gsc_sync_pi_sheets');
      btn.disabled = true;
      if (statusEl){
        statusEl.textContent = MW_AUDIT.i18n.syncWorking || 'Syncing…';
        statusEl.classList.add('show');
      }
      $.post(MW_AUDIT.ajax, {
        action: 'mw_gsc_sync_pi_sheets',
        nonce: nonce,
        sheet: sheetVal,
        range: rangeVal,
        override: override && override.checked ? 1 : 0
      }, function(res){
        btn.disabled = false;
        if (!res || !res.success){
          const msg = res && res.data && res.data.msg ? res.data.msg : (MW_AUDIT.i18n.error || 'Error');
          if (statusEl){
            statusEl.textContent = msg;
            statusEl.classList.add('show');
          } else {
            alert(msg);
          }
          return;
        }
        if (statusEl){
          const imported = res.data.imported || 0;
          const skipped = res.data.skipped || 0;
          let message = MW_AUDIT.i18n.syncDone || 'Import finished';
          message = message.replace('%imported%', imported).replace('%skipped%', skipped);
          statusEl.textContent = message;
          statusEl.classList.add('show');
          setTimeout(() => statusEl.classList.remove('show'), 4000);
        }
      });
    });
  })();

  // Page indexing Sheets assembler
  (function(){
    const btn = document.getElementById('mw-gsc-assemble-button');
    if (!btn) return;
    const input = document.getElementById('mw-gsc-assemble-input');
    const target = document.getElementById('mw-gsc-sheet-input');
    const statusEl = document.getElementById('mw-gsc-assemble-status');
    btn.addEventListener('click', function(){
      const sources = input ? input.value.trim() : '';
      if (!sources){
        if (statusEl){
          statusEl.textContent = MW_AUDIT.i18n.sheetRequired || 'Enter Sheet ID';
          statusEl.classList.add('show');
        }
        return;
      }
      const nonce = getNonce('mw_gsc_assemble_pi_sheet');
      btn.disabled = true;
      if (statusEl){
        statusEl.textContent = MW_AUDIT.i18n.assembleWorking || 'Working…';
        statusEl.classList.add('show');
      }
      $.post(MW_AUDIT.ajax, {
        action: 'mw_gsc_assemble_pi_sheet',
        nonce: nonce,
        sources: sources
      }, function(res){
        btn.disabled = false;
        if (!res || !res.success){
          const msg = res && res.data && res.data.msg ? res.data.msg : (MW_AUDIT.i18n.assembleError || 'Error');
          if (statusEl){
            statusEl.textContent = msg;
            statusEl.classList.add('show');
          } else {
            alert(msg);
          }
          return;
        }
        const sheetUrl = res.data.sheet_url || '';
        if (target && sheetUrl){
          target.value = sheetUrl;
        }
        if (statusEl){
          let message = MW_AUDIT.i18n.assembleDone || 'Done: %link%';
          message = message.replace('%link%', sheetUrl);
          statusEl.textContent = message;
          statusEl.classList.add('show');
        }
      }).fail(function(){
        btn.disabled = false;
        if (statusEl){
          statusEl.textContent = MW_AUDIT.i18n.assembleError || 'Error';
          statusEl.classList.add('show');
        }
      });
    });
  })();

  // GSC import mode toggle
  (function(){
    const select = document.getElementById('mw-gsc-import-mode');
    if (!select) return;
    const csvSections = document.querySelectorAll('.mw-gsc-mode-csv');
    const sheetsSections = document.querySelectorAll('.mw-gsc-mode-sheets');
    const warning = document.getElementById('mw-gsc-sheets-warning');
    function applyMode(mode){
      const isSheets = (mode === 'sheets');
      csvSections.forEach((node) => {
        node.classList.toggle('mw-hidden', isSheets);
        node.hidden = !!isSheets;
      });
      sheetsSections.forEach((node) => {
        node.classList.toggle('mw-hidden', !isSheets);
        node.hidden = !isSheets;
      });
      if (warning){
        warning.classList.toggle('mw-hidden', !isSheets);
        warning.hidden = !isSheets;
      }
    }
    applyMode(select.value || 'csv');
    select.addEventListener('change', function(){
      applyMode(this.value || 'csv');
    });
  })();

  // Gemini prompt helper
  (function(){
    function showStatus($status, message, type){
      if (!$status || !$status.length) return;
      $status.text(message || '');
      $status.removeClass('show success error');
      if (message){
        if (type){
          $status.addClass(type);
        }
        $status.addClass('show');
        const prevTimeout = $status.data('mwGeminiTimer');
        if (prevTimeout){
          clearTimeout(prevTimeout);
        }
        const handle = setTimeout(() => {
          $status.removeClass('show success error');
          $status.removeData('mwGeminiTimer');
        }, 4000);
        $status.data('mwGeminiTimer', handle);
      }
    }
    function fallbackCopy(prompt, $status, successMsg, failMsg){
      const textarea = document.createElement('textarea');
      textarea.value = prompt;
      textarea.setAttribute('readonly', '');
      textarea.style.position = 'absolute';
      textarea.style.left = '-9999px';
      document.body.appendChild(textarea);
      textarea.select();
      let copied = false;
      try {
        copied = document.execCommand('copy');
      } catch (err){
        copied = false;
      }
      document.body.removeChild(textarea);
      showStatus($status, copied ? successMsg : failMsg, copied ? 'success' : 'error');
      return copied;
    }
    $(document).on('click', '.mw-gemini-link', function(){
      const prompt = $(this).attr('data-clipboard') || $(this).attr('data-prompt');
      if (!prompt){
        return;
      }
      const $status = $(this).closest('.mw-gemini-cell').find('.mw-gemini-status');
      const successMsg = (MW_AUDIT && MW_AUDIT.i18n && MW_AUDIT.i18n.geminiCopied) || 'Prompt copied. Paste it into Gemini.';
      const failMsg = (MW_AUDIT && MW_AUDIT.i18n && MW_AUDIT.i18n.geminiCopyFailed) || 'Prompt not copied — copy it manually.';
      if (navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(prompt).then(() => {
          showStatus($status, successMsg, 'success');
        }).catch(() => {
          fallbackCopy(prompt, $status, successMsg, failMsg);
        });
      } else {
        fallbackCopy(prompt, $status, successMsg, failMsg);
      }
    });
  })();

  // Priority block
  (function(){
    const $block = $('#mw-priority-block');
    if (!$block.length) return;
    const $loadBtn = $block.find('#mw-priority-load');
    const $threshold = $block.find('#mw-priority-threshold');
    const $status = $block.find('#mw-priority-status');
    const $tableWrap = $block.find('#mw-priority-table-wrap');
    const $tbody = $tableWrap.find('tbody');
    const $exportField = $('#mw-priority-export-threshold');
    if (!$loadBtn.length) return;

    function syncExportField(){
      if ($exportField.length && $threshold.length){
        $exportField.val($threshold.val());
      }
    }
    syncExportField();
    if ($threshold.length){
      $threshold.on('change', syncExportField);
    }

    function setStatus(message, type){
      if (!$status.length) return;
      $status.text(message || '');
      $status.removeClass('show error loading empty');
      if (message){
        $status.addClass('show');
        if (type){
          $status.addClass(type);
        }
      }
    }

    function renderRows(rows){
      if (!$tbody.length) return;
      $tbody.empty();
      if (!rows || !rows.length){
        $tableWrap.hide();
        return;
      }
      rows.forEach((row) => {
        const $tr = $('<tr>');
        const url = row.url || '';
        const inbound = (typeof row.inbound_links !== 'undefined' && row.inbound_links !== null) ? row.inbound_links : '—';
        const published = row.published_at_display || row.published_at || '—';
        const $urlCell = $('<td class="url">');
        if (url){
          $('<a>', { href: url, target: '_blank', rel: 'noopener' }).text(url).appendTo($urlCell);
        } else {
          $urlCell.text('—');
        }
        const $inboundCell = $('<td>').text(inbound);
        const $pcCell = $('<td>');
        if (row.pc_name){
          $('<div class="mw-priority-pc-name">').text(row.pc_name).appendTo($pcCell);
        }
        if (row.pc_path){
          $('<code class="mw-priority-pc-path">').text(row.pc_path).appendTo($pcCell);
        }
        if (!$pcCell.children().length){
          $pcCell.text('—');
        }
        const $gscCell = $('<td>');
        const parts = [];
        if (row.gsc_coverage){
          parts.push(row.gsc_coverage);
        }
        if (row.gsc_reason){
          parts.push(row.gsc_reason);
        }
        if (parts.length){
          $('<div class="mw-priority-gsc-primary">').text(parts.join(' — ')).appendTo($gscCell);
        }
        const metaBits = [];
        const sourceLabel = row.gsc_source_label || row.gsc_source;
        if (sourceLabel){
          metaBits.push(sourceLabel);
        }
        if (row.gsc_inspected_at_display || row.gsc_inspected_at){
          metaBits.push(row.gsc_inspected_at_display || row.gsc_inspected_at);
        }
        if (metaBits.length){
          $('<small class="mw-priority-gsc-meta">').text(metaBits.join(' • ')).appendTo($gscCell);
        }
        if (!$gscCell.children().length){
          $gscCell.text('—');
        }
        const $publishedCell = $('<td>').text(published);
        $tr.append($urlCell, $inboundCell, $pcCell, $gscCell, $publishedCell);
        $tbody.append($tr);
      });
      $tableWrap.show();
    }

    $loadBtn.on('click', function(e){
      e.preventDefault();
      if (!MW_AUDIT || !MW_AUDIT.nonces){
        return;
      }
      const nonce = getNonce('mw_audit_priority_list');
      if (!nonce){
        setStatus(MW_AUDIT.i18n.priorityError || 'Error', 'error');
        return;
      }
      const threshold = $threshold.length ? $threshold.val() : 0;
      $loadBtn.prop('disabled', true);
      setStatus(MW_AUDIT.i18n.priorityLoading || 'Loading...', 'loading');
      $.post(MW_AUDIT.ajax, {
        action: 'mw_audit_priority_list',
        nonce: nonce,
        threshold: threshold,
        orderby: 'inbound_links',
        order: 'ASC',
        paged: 1,
        per_page: 25
      }, function(res){
        $loadBtn.prop('disabled', false);
        if (!res || !res.success || !res.data){
          setStatus(MW_AUDIT.i18n.priorityError || 'Error', 'error');
          return;
        }
        const rows = Array.isArray(res.data.rows) ? res.data.rows : [];
        if (!rows.length){
          renderRows([]);
          setStatus(MW_AUDIT.i18n.priorityEmpty || 'No URLs match this filter yet.', 'empty');
          return;
        }
        setStatus('', '');
        renderRows(rows);
      }).fail(function(){
        $loadBtn.prop('disabled', false);
        setStatus(MW_AUDIT.i18n.priorityError || 'Error', 'error');
      });
    });
  })();

  // Similar URL helper panel
  (function(){
    const overlay = document.getElementById('mw-similar-overlay');
    if (!overlay) return;
    const openBtn = document.getElementById('mw-similar-open');
    const closeBtn = overlay.querySelector('.mw-similar-close');
    const loadBtn = document.getElementById('mw-similar-load');
    const referenceInput = document.getElementById('mw-similar-reference');
    const baselineBox = document.getElementById('mw-similar-baseline');
    const baselineList = document.getElementById('mw-similar-baseline-list');
    const statusEl = document.getElementById('mw-similar-status');
    const limitSelect = document.getElementById('mw-similar-limit');
    const applyBtn = document.getElementById('mw-similar-apply');
    const resultsWrap = document.getElementById('mw-similar-results');
    const resultsBody = overlay.querySelector('.mw-similar-table tbody');
    const summaryEl = document.getElementById('mw-similar-summary');
    const appliedEl = document.getElementById('mw-similar-applied');
    const prevBtn = document.getElementById('mw-similar-prev');
    const nextBtn = document.getElementById('mw-similar-next');
    const exportBtn = document.getElementById('mw-similar-export');
    const exportForm = document.getElementById('mw-similar-export-form');
    const exportInput = document.getElementById('mw-similar-export-criteria');

    const fields = {
      age: {
        toggle: document.getElementById('mw-similar-age-toggle'),
        min: document.getElementById('mw-similar-age-min'),
        max: document.getElementById('mw-similar-age-max')
      },
      inbound: {
        toggle: document.getElementById('mw-similar-inbound-toggle'),
        min: document.getElementById('mw-similar-inbound-min'),
        max: document.getElementById('mw-similar-inbound-max')
      },
      http: {
        toggle: document.getElementById('mw-similar-http-toggle'),
        input: document.getElementById('mw-similar-http-value')
      },
      sitemap: {
        toggle: document.getElementById('mw-similar-sitemap-toggle'),
        input: document.getElementById('mw-similar-sitemap-value')
      },
      noindex: {
        toggle: document.getElementById('mw-similar-noindex-toggle'),
        input: document.getElementById('mw-similar-noindex-value')
      },
      indexed: {
        toggle: document.getElementById('mw-similar-indexed-toggle'),
        input: document.getElementById('mw-similar-indexed-value')
      },
      category: {
        toggle: document.getElementById('mw-similar-category-toggle'),
        input: document.getElementById('mw-similar-category-value')
      }
    };

    let baseline = null;
    let lastCriteria = null;
    let lastMeta = { total: 0, offset: 0, limit: 25 };

    function setStatus(message, type){
      if (!statusEl) return;
      if (!message){
        statusEl.textContent = '';
        statusEl.classList.remove('show','error','loading');
        return;
      }
      statusEl.textContent = message;
      statusEl.classList.add('show');
      statusEl.classList.toggle('error', type === 'error');
      statusEl.classList.toggle('loading', type === 'loading');
      if (!type){
        statusEl.classList.remove('error','loading');
      }
    }

    function openPanel(prefill){
      overlay.classList.add('mw-similar-open');
      overlay.setAttribute('aria-hidden', 'false');
      document.body.classList.add('mw-similar-lock');
      if (prefill){
        referenceInput.value = prefill;
        loadBaseline(prefill);
      }
    }

    function closePanel(){
      overlay.classList.remove('mw-similar-open');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('mw-similar-lock');
      setStatus('');
    }

    function renderBaseline(data){
      if (!baselineList) return;
      baselineList.innerHTML = '';
      const defs = [
        ['HTTP', data.http_status ?? '—'],
        ['Inbound links', data.inbound_links ?? '—'],
        ['Days since update', data.days_since_update ?? '—'],
        ['Indexed in Google', data.indexed_in_google === null ? '—' : (data.indexed_in_google ? 'Yes' : 'No')],
        ['In sitemap', data.in_sitemap === null ? '—' : (data.in_sitemap ? 'Yes' : 'No')],
        ['Noindex', data.noindex === null ? '—' : (data.noindex ? 'Yes' : 'No')],
        ['Primary category', data.pc_name || data.pc_path || '—'],
      ];
      defs.forEach(([label, value]) => {
        const dt = document.createElement('dt');
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.textContent = value;
        baselineList.appendChild(dt);
        baselineList.appendChild(dd);
      });
      baselineBox.hidden = false;
    }

    function applyDefaults(payload){
      const defaults = payload.defaults || {};
      const suggested = payload.suggested || {};
      if (defaults.age){
        fields.age.min.value = defaults.age.min ?? 0;
        fields.age.max.value = defaults.age.max ?? defaults.age.min ?? 0;
      }
      if (defaults.inbound){
        fields.inbound.min.value = defaults.inbound.min ?? 0;
        fields.inbound.max.value = defaults.inbound.max ?? defaults.inbound.min ?? 0;
      }
      if (baseline){
        if (baseline.http_status){
          fields.http.input.value = baseline.http_status;
        }
        if (baseline.in_sitemap !== null && baseline.in_sitemap !== undefined){
          fields.sitemap.input.value = baseline.in_sitemap ? '1' : '0';
        }
        if (baseline.noindex !== null && baseline.noindex !== undefined){
          fields.noindex.input.value = baseline.noindex ? '1' : '0';
        }
        if (baseline.indexed_in_google !== null && baseline.indexed_in_google !== undefined){
          fields.indexed.input.value = baseline.indexed_in_google ? '1' : '0';
        }
        if (baseline.pc_path){
          fields.category.input.value = baseline.pc_path;
        }
      }
      Object.entries(fields).forEach(([key, cfg]) => {
        if (!cfg.toggle) return;
        const suggestionKey = key === 'http' ? 'http_status' : key;
        cfg.toggle.checked = !!suggested[suggestionKey];
      });
    }

    function loadBaseline(url){
      const cleaned = (url || '').trim();
      if (!cleaned){
        setStatus(MW_AUDIT.i18n.similarNeedUrl || 'Enter a URL first.', 'error');
        return;
      }
      setStatus(MW_AUDIT.i18n.loading || 'Loading…', 'loading');
      $.post(MW_AUDIT.ajax, {
        action: 'mw_audit_similar_seed',
        nonce: getNonce('mw_audit_similar_seed'),
        url: cleaned
      }, function(res){
        if (!res || !res.success){
          const msg = res && res.data && res.data.msg ? res.data.msg : (MW_AUDIT.i18n.similarLoadFailed || 'Unable to load URL.');
          setStatus(msg, 'error');
          return;
        }
        baseline = res.data.baseline || null;
        if (baseline && baseline.url){
          referenceInput.value = baseline.url;
        }
        renderBaseline(baseline || {});
        applyDefaults(res.data || {});
        resultsWrap.hidden = true;
        if (exportBtn){
          exportBtn.disabled = true;
        }
        setStatus(MW_AUDIT.i18n.similarReady || 'Signals loaded. Adjust filters and click “Show matches.”');
      }).fail(function(){
        setStatus(MW_AUDIT.i18n.similarLoadFailed || 'Unable to load URL.', 'error');
      });
    }

    function collectCriteria(nextOffset){
      if (!baseline || !baseline.url){
        setStatus(MW_AUDIT.i18n.similarNeedReference || 'Load a reference URL first.', 'error');
        return null;
      }
      const limit = parseInt(limitSelect.value, 10) || 25;
      return {
        base_url: baseline.url,
        limit: limit,
        offset: typeof nextOffset === 'number' ? nextOffset : 0,
        http_status: {
          enabled: fields.http.toggle.checked,
          value: fields.http.input.value
        },
        in_sitemap: {
          enabled: fields.sitemap.toggle.checked,
          value: fields.sitemap.input.value
        },
        noindex: {
          enabled: fields.noindex.toggle.checked,
          value: fields.noindex.input.value
        },
        indexed: {
          enabled: fields.indexed.toggle.checked,
          value: fields.indexed.input.value
        },
        pc_path: {
          enabled: fields.category.toggle.checked,
          value: fields.category.input.value.trim()
        },
        inbound: {
          enabled: fields.inbound.toggle.checked,
          min: fields.inbound.min.value,
          max: fields.inbound.max.value,
          baseline: baseline.inbound_links ?? 0
        },
        age: {
          enabled: fields.age.toggle.checked,
          min: fields.age.min.value,
          max: fields.age.max.value,
          baseline: baseline.days_since_update ?? 0
        }
      };
    }

    function renderApplied(applied){
      appliedEl.innerHTML = '';
      if (!Array.isArray(applied) || !applied.length) return;
      applied.forEach((label) => {
        const chip = document.createElement('span');
        chip.className = 'mw-similar-chip';
        chip.textContent = label;
        appliedEl.appendChild(chip);
      });
    }

    function renderRows(rows){
      resultsBody.innerHTML = '';
      rows.forEach((row) => {
        const tr = document.createElement('tr');
        const urlCell = document.createElement('td');
        const link = document.createElement('a');
        link.href = row.norm_url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = row.norm_url;
        urlCell.appendChild(link);
        const ageCell = document.createElement('td');
        ageCell.textContent = row.days_since_update !== null && row.days_since_update !== undefined ? row.days_since_update : '—';
        const httpCell = document.createElement('td');
        httpCell.textContent = row.http_status ?? '—';
        const inboundCell = document.createElement('td');
        inboundCell.textContent = row.inbound_links !== null && row.inbound_links !== undefined ? row.inbound_links : '—';
        const indexedCell = document.createElement('td');
        indexedCell.textContent = row.indexed_in_google === null || row.indexed_in_google === undefined ? '—' : (row.indexed_in_google ? 'Yes' : 'No');
        const sitemapCell = document.createElement('td');
        sitemapCell.textContent = row.in_sitemap === null || row.in_sitemap === undefined ? '—' : (row.in_sitemap ? 'Yes' : 'No');
        const categoryCell = document.createElement('td');
        categoryCell.textContent = row.pc_name || row.pc_path || '—';
        const gscCell = document.createElement('td');
        const coverage = row.gsc_coverage_inspection || row.gsc_coverage_page || '';
        const reason = row.gsc_reason_inspection || row.gsc_reason_page || row.gsc_verdict || '';
        if (coverage){
          const strong = document.createElement('strong');
          strong.textContent = coverage;
          gscCell.appendChild(strong);
        }
        if (reason){
          const meta = document.createElement('div');
          meta.className = 'mw-similar-gsc-meta';
          meta.textContent = reason;
          gscCell.appendChild(meta);
        }
        if (!coverage && !reason){
          gscCell.textContent = '—';
        }
        const scoreCell = document.createElement('td');
        const score = row.similarity_score !== undefined && row.similarity_score !== null ? parseFloat(row.similarity_score) : 0;
        scoreCell.textContent = score.toFixed(2);
        [urlCell, ageCell, httpCell, inboundCell, indexedCell, sitemapCell, categoryCell, gscCell, scoreCell].forEach((cell) => tr.appendChild(cell));
        resultsBody.appendChild(tr);
      });
    }

    function renderResults(data, rawCriteria){
      const rows = Array.isArray(data.rows) ? data.rows : [];
      renderRows(rows);
      renderApplied(data.applied || []);
      const total = data.total || 0;
      const offset = data.offset || 0;
      const limit = data.limit || (rawCriteria ? rawCriteria.limit : 25);
      if (rows.length){
        const first = offset + 1;
        const last = offset + rows.length;
        const template = MW_AUDIT.i18n.similarSummary || 'Showing %1$s–%2$s of %3$s matches';
        summaryEl.textContent = template.replace('%1$s', first).replace('%2$s', last).replace('%3$s', total);
      } else {
        summaryEl.textContent = MW_AUDIT.i18n.similarNoRows || 'No matches found for the selected filters.';
      }
      resultsWrap.hidden = false;
      prevBtn.disabled = offset <= 0;
      nextBtn.disabled = (offset + rows.length) >= total;
      if (exportBtn){
        exportBtn.disabled = rows.length === 0;
      }
      lastCriteria = rawCriteria ? JSON.parse(JSON.stringify(rawCriteria)) : null;
      lastMeta = { total: total, offset: offset, limit: limit };
      exportInput.value = data.criteria ? JSON.stringify({ criteria: data.criteria }) : '';
    }

    function requestSimilar(criteria){
      setStatus(MW_AUDIT.i18n.loading || 'Loading…', 'loading');
      $.post(MW_AUDIT.ajax, {
        action: 'mw_audit_similar_query',
        nonce: getNonce('mw_audit_similar_query'),
        criteria: JSON.stringify(criteria)
      }, function(res){
        if (!res || !res.success){
          const msg = res && res.data && res.data.msg ? res.data.msg : (MW_AUDIT.i18n.similarQueryFailed || 'Unable to load matches.');
          setStatus(msg, 'error');
          return;
        }
        setStatus('');
        renderResults(res.data, criteria);
      }).fail(function(){
        setStatus(MW_AUDIT.i18n.similarQueryFailed || 'Unable to load matches.', 'error');
      });
    }

    openBtn?.addEventListener('click', function(){
      openPanel();
    });
    closeBtn?.addEventListener('click', closePanel);
    overlay.addEventListener('click', function(evt){
      if (evt.target === overlay){
        closePanel();
      }
    });
    document.addEventListener('keydown', function(evt){
      if (evt.key === 'Escape' && overlay.classList.contains('mw-similar-open')){
        closePanel();
      }
    });
    loadBtn?.addEventListener('click', function(){
      loadBaseline(referenceInput.value);
    });
    applyBtn?.addEventListener('click', function(){
      const criteria = collectCriteria(0);
      if (!criteria){
        return;
      }
      requestSimilar(criteria);
    });
    prevBtn?.addEventListener('click', function(){
      if (!lastCriteria){
        return;
      }
      const newOffset = Math.max(0, (lastMeta.offset || 0) - lastMeta.limit);
      if (newOffset === lastMeta.offset){
        return;
      }
      const criteria = JSON.parse(JSON.stringify(lastCriteria));
      criteria.offset = newOffset;
      requestSimilar(criteria);
    });
    nextBtn?.addEventListener('click', function(){
      if (!lastCriteria){
        return;
      }
      const newOffset = (lastMeta.offset || 0) + lastMeta.limit;
      if (newOffset >= lastMeta.total){
        return;
      }
      const criteria = JSON.parse(JSON.stringify(lastCriteria));
      criteria.offset = newOffset;
      requestSimilar(criteria);
    });
    exportBtn?.addEventListener('click', function(){
      if (exportBtn.disabled || !exportInput.value){
        setStatus(MW_AUDIT.i18n.similarExportError || 'Run a search before exporting.', 'error');
        return;
      }
      exportForm.submit();
    });
    document.addEventListener('click', function(evt){
      const trigger = evt.target.closest('.mw-similar-from-row');
      if (!trigger) return;
      evt.preventDefault();
      openPanel(trigger.getAttribute('data-url'));
    });
  })();
})(jQuery);

;(function($){
  // Respect any existing mwAuditSetProgress definition
  if (typeof window.mwAuditSetProgress !== 'function') {
    window.mwAuditSetProgress = function($box, done, total){
      var pct = 100;
      if (total && total > 0) pct = Math.floor((done/total)*100);
      $box.find('.bar-fill').css('width', pct + '%');
      $box.find('.mw-progress-label').text('Done: '+ (done||0) + '/' + (total||0));
    };
  }
})(jQuery);
