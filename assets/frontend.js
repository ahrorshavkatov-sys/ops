jQuery(function ($) {

  function msg(text, type) {
    var $m = $('#gttom-msg');
    if (!$m.length) return;
    $m.removeClass('gttom-msg-ok gttom-msg-warn gttom-msg-error')
      .addClass(type ? ('gttom-msg-' + type) : '')
      .text(text || '');
  }

  // Global non-blocking error banner (Ops/Catalog UX)
  function showBanner(text, level) {
    level = level || 'error';
    var $b = $('#gttom-banner');
    if (!$b.length) {
      // Insert at top of app/wrap if possible
      var $host = $('#gttom-app');
      if (!$host.length) $host = $('.gttom-wrap').first();
      if (!$host.length) $host = $('body');
      $host.prepend('<div id="gttom-banner" class="gttom-banner"></div>');
      $b = $('#gttom-banner');
    }
    $b.removeClass('ok warn error').addClass(level).text(text || '').toggle(!!text);
  }

  function serviceId() {
    return parseInt($('#gttom-service-id').val(), 10) || 0;
  }

  function renderRows(tiers) {
    var $tbody = $('#gttom-tier-rows');
    if (!$tbody.length) return;

    if (!tiers || !tiers.length) {
      $tbody.html('<tr><td colspan="4">No tiers yet.</td></tr>');
      return;
    }

    var html = tiers.map(function (t) {
      return '<tr data-tier-id="' + t.id + '">' +
        '<td>' + t.min_pax + '</td>' +
        '<td>' + t.max_pax + '</td>' +
        '<td>' + t.price + '</td>' +
        '<td><button class="gttom-btn gttom-btn-small gttom-del-tier" type="button">Cancel</button></td>' +
      '</tr>';
    }).join('');

    $tbody.html(html);
  }

  function refresh() {
    var sid = serviceId();
    if (!sid) { msg('Enter Service ID first.', 'warn'); return; }

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_list_service_tiers',
      nonce: GTTOM.nonce,
      service_id: sid
    }).done(function (res) {
      if (!res || !res.success) {
        msg((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
        return;
      }
      renderRows(res.data.tiers || []);
      msg('Loaded tiers.', 'ok');
    }).fail(function () {
      msg('Request failed.', 'error');
    });
  }

  $(document).on('click', '#gttom-refresh-tiers', function (e) {
    e.preventDefault();
    refresh();
  });

  $(document).on('click', '#gttom-add-tier', function (e) {
    e.preventDefault();

    var sid = serviceId();
    if (!sid) { msg('Enter Service ID first.', 'warn'); return; }

    var minPax = parseInt($('#gttom-min-pax').val(), 10) || 0;
    var maxPax = parseInt($('#gttom-max-pax').val(), 10) || 0;
    var price  = parseFloat($('#gttom-price').val()) || 0;

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_add_service_tier',
      nonce: GTTOM.nonce,
      service_id: sid,
      min_pax: minPax,
      max_pax: maxPax,
      price: price
    }).done(function (res) {
      if (!res || !res.success) {
        msg((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
        return;
      }
      msg('Tier added (no reload).', 'ok');
      refresh();
    }).fail(function () {
      msg('Request failed.', 'error');
    });
  });

  $(document).on('click', '.gttom-del-tier', function (e) {
    e.preventDefault();
    var $tr = $(this).closest('tr');
    var tierId = parseInt($tr.data('tier-id'), 10) || 0;
    if (!tierId) return;

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_delete_service_tier',
      nonce: GTTOM.nonce,
      tier_id: tierId
    }).done(function (res) {
      if (!res || !res.success) {
        msg((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
        return;
      }
      msg('Tier deleted.', 'ok');
      refresh();
    }).fail(function () {
      msg('Request failed.', 'error');
    });
  });

  /* ------------------------------------------------------------
   * Phase 2: Operator Build Tour (General + Itinerary Builder)
   * ------------------------------------------------------------ */

  // Phase 2.3: Compact catalog cache (cities + suppliers)
  var COMPACT = null;

  function loadCompact(done) {
    if (COMPACT) { if (done) done(); return; }
    $.post(GTTOM.ajaxUrl, { action: 'gttom_catalog_compact', nonce: GTTOM.nonce })
      .done(function (res) {
        if (res && res.success && res.data && res.data.catalog) {
          COMPACT = res.data.catalog;
          // Normalize legacy keys
          if (COMPACT) {
            if (COMPACT.guides && !COMPACT.activities) COMPACT.activities = COMPACT.guides;
            if (COMPACT.activities && !COMPACT.guides) COMPACT.guides = COMPACT.activities;
          }
        } else {
          COMPACT = { cities: [] };
        }
        if (done) done();
      })
      .fail(function () {
        COMPACT = { cities: [] };
        if (done) done();
      });
  }

  function cities() {
    return (COMPACT && COMPACT.cities) ? COMPACT.cities : [];
  }

  function cityNameById(id) {
    id = parseInt(id || 0, 10);
    if (!id) return '';
    var found = (cities() || []).find(function (c) { return parseInt(c.id, 10) === id; });
    return found ? (found.name || '') : '';
  }

  function renderCitySelect(cls, selectedId) {
    var html = '<select class="' + cls + '"><option value="0">— Select —</option>';
    (cities() || []).forEach(function (c) {
      var sel = (parseInt(selectedId || 0, 10) === parseInt(c.id, 10)) ? ' selected' : '';
      html += '<option value="' + c.id + '"' + sel + '>' + escapeHtml(c.name) + '</option>';
    });
    html += '</select>';
    return html;
  }

  function supplierEntityForStepType(stepType) {
    // Phase 5.1: Supplier assignment is done via the Suppliers catalog (multi-supplier).
    // Filtering is applied by supplier_type based on step type.
    var supported = ['hotel','pickup','transfer','meal','activity','fee','full_day_car','tour_package','custom'];
    return supported.indexOf(stepType) !== -1 ? 'suppliers' : '';
  }

  function allowedSupplierTypesForStepType(stepType) {
    // Guide/Driver/Global filtering (Global is always allowed).
    var map = {
      transfer: ['driver','global'],
      pickup: ['driver','global'],
      full_day_car: ['driver','global'],
      activity: ['guide','global'],
      hotel: ['global'],
      meal: ['global'],
      fee: ['global'],
      tour_package: ['global'],
      custom: ['guide','driver','global']
    };
    return map[stepType] || ['guide','driver','global'];
  }


  function suppliersFor(entity, ctx) {
    var list = (COMPACT && COMPACT[entity]) ? COMPACT[entity] : [];
    if (!ctx) return list;

    // Normalize allowed city ids
    var cityIds = [];
    if (Array.isArray(ctx.city_ids)) {
      ctx.city_ids.forEach(function(v){
        var n = parseInt(v || 0, 10) || 0;
        if (n) cityIds.push(n);
      });
    } else if (ctx.city_id) {
      var one = parseInt(ctx.city_id || 0, 10) || 0;
      if (one) cityIds = [one];
    }

    // Filter by city for city-scoped entities
    if (
      entity === 'hotels' ||
      entity === 'guides' ||
      entity === 'activities' ||
      entity === 'pickups' ||
      entity === 'meals' ||
      entity === 'fees' ||
      entity === 'full_day_cars' ||
      entity === 'tour_packages'
    ) {
      if (cityIds.length) {
        return list.filter(function (x) {
          var cid = parseInt(x.city_id || 0, 10) || 0;
          return cityIds.indexOf(cid) !== -1;
        });
      }
    }

    // Route-scoped entity
    if (entity === 'transfers') {
      if (ctx.from_city_id && ctx.to_city_id) {
        var f = parseInt(ctx.from_city_id, 10);
        var t = parseInt(ctx.to_city_id, 10);
        return list.filter(function (x) {
          var xf = parseInt(x.from_city_id || 0, 10);
          var xt = parseInt(x.to_city_id || 0, 10);
          return (xf === f && xt === t) || (xf === t && xt === f);
        });
      }
    }

    return list;
  }

  function renderSupplierChips(stepSuppliers) {
    var sups = stepSuppliers || [];
    if (!sups.length) {
      return '<div class="gttom-muted">No suppliers assigned.</div>';
    }
    var html = '<div class="gttom-supchips">';
    sups.forEach(function(sp){
      var label = sp.name || ('Supplier #' + (sp.id||''));
      if (sp.phone) label += ' · ' + sp.phone;
      html += '<span class="gttom-chip">' + escapeHtml(label) +
              ' <button type="button" class="gttom-chip-x gttom-sup-remove" data-supplier-id="' + (parseInt(sp.id,10)||0) + '" aria-label="Remove">×</button></span>';
    });
    html += '</div>';
    return html;
  }

  function suppliersForStepType(stepType) {
    var entity = supplierEntityForStepType(stepType);
    var list = (COMPACT && COMPACT[entity]) ? COMPACT[entity] : [];
    var allowed = allowedSupplierTypesForStepType(stepType);
    return (list || []).filter(function(x){
      var st = String(x.supplier_type || 'other');
      return allowed.indexOf(st) !== -1;
    });
  }

  // Phase 6.2.3 — make supplier selection truly disabled until the step is saved.
  function renderSupplierAddSelect(stepType, stepSuppliers, stepId) {
    var entity = supplierEntityForStepType(stepType);
    if (!entity) {
      return '<select class="gttom-step-supplier-add" disabled><option value="0">— Not applicable —</option></select>';
    }
    var sid = parseInt(stepId || 0, 10) || 0;
    var isDisabled = sid < 1;
    var existing = (stepSuppliers || []).map(function(s){ return parseInt(s.id,10)||0; });
    var list = suppliersForStepType(stepType).filter(function(x){
      return existing.indexOf(parseInt(x.id,10)||0) === -1;
    });
    var html = '<select class="gttom-step-supplier-add" data-entity="' + entity + '"' + (isDisabled ? ' disabled title="Save step first"' : '') + '>';
    html += '<option value="0">+ Add supplier…</option>';
    (list || []).forEach(function(x){
      html += '<option value="' + x.id + '">' + escapeHtml(x.name) + (x.phone ? (' · ' + escapeHtml(x.phone)) : '') + '</option>';
    });
    html += '</select>';
    return html;
  }


    var GTTOM_SAVE_ALL = false;

function uiMsg($el, text, type) {
    if (!$el || !$el.length) return;
    $el.removeClass('gttom-msg-ok gttom-msg-warn gttom-msg-error')
      .addClass(type ? ('gttom-msg-' + type) : '')
      .text(text || '');
  }

  function currentTourId() {
    var v = parseInt($('#gttom-tour-id').text(), 10);
    return v || 0;
  }

  // Day type for creating a NEW day. The global selector was removed from the UI
  // (day type is controlled per-day), so default to 'city'.
  function newDayTypeDefault() {
    var $s = $('#gttom-new-day-type');
    if ($s && $s.length) {
      return String($s.val() || 'city');
    }
    return 'city';
  }

  function setTourId(id) {
    $('#gttom-tour-id').text(id ? String(id) : '—');
    updateBuilderLock();
  }

  function updateBuilderLock() {
    var hasTour = currentTourId() > 0;
    // Lock navigation to other builder subtabs until General is saved (tour exists).
    var $subtabs = $('#gttom-build-tabs .gttom-tab');
    if ($subtabs.length) {
      $subtabs.each(function(){
        var $t = $(this);
        var key = String($t.data('subtab') || '');
        if (key && key !== 'general') {
          $t.prop('disabled', !hasTour);
          if (!hasTour) { $t.addClass('is-disabled'); } else { $t.removeClass('is-disabled'); }
        }
      });
      // If user is currently on a locked subtab, bounce back to General.
      if (!hasTour) {
        var $active = $subtabs.filter('.is-active');
        if ($active.length) {
          var k = String($active.data('subtab') || '');
          if (k && k !== 'general') {
            $subtabs.removeClass('is-active');
            $subtabs.filter('[data-subtab="general"]').addClass('is-active');
            $('.gttom-subpanel').removeClass('is-active');
            $('.gttom-subpanel[data-subpanel="general"]').addClass('is-active');
          }
        }
      }
    }

    // Lock itinerary controls until a tour exists.
    var $btnAdd = $('#gttom-add-day');
    var $selType = $('#gttom-new-day-type');
    var $btnAssign = $('#gttom-open-assign');
    var $btnRefresh = $('#gttom-refresh-tour');
    var $days = $('#gttom-days');
    var $assign = $('#gttom-assign');

    if (!$btnAdd.length) return;

    [$btnAdd, $selType, $btnAssign, $btnRefresh].forEach(function ($x) {
      if (!$x || !$x.length) return;
      $x.prop('disabled', !hasTour);
      if (!hasTour) {
        $x.addClass('is-disabled');
      } else {
        $x.removeClass('is-disabled');
      }
    });

    if (!hasTour) {
      updateItineraryActions(0);
      if ($days && $days.length) {
        $days.html('<div class="gttom-muted">Load a tour first.<br><span class="gttom-note">Save General first.</span></div>');
      }
      if ($assign && $assign.length) {
        $assign.hide().empty();
      }
    }
  }

  function switchTab($container, tabSel, panelSel, activeClass) {
    $container.find(tabSel).removeClass(activeClass);
    $container.find(panelSel).removeClass(activeClass);
    var $btn = $(event.currentTarget);
    $btn.addClass(activeClass);
    var key = $btn.data('tab') || $btn.data('subtab');
    if ($btn.data('tab')) {
      $container.find('[data-panel="' + key + '"]').addClass(activeClass);
    } else {
      $container.find('[data-subpanel="' + key + '"]').addClass(activeClass);
    }
  }

  // Main section tabs
  $(document).on('click', '#gttom-main-tabs .gttom-tab', function (e) {
    e.preventDefault();
    var $app = $('#gttom-operator-app');
    if (!$app.length) return;
    $app.find('#gttom-main-tabs .gttom-tab').removeClass('is-active');
    $app.find('.gttom-panel').removeClass('is-active');
    $(this).addClass('is-active');
    $app.find('.gttom-panel[data-panel="' + $(this).data('tab') + '"]').addClass('is-active');
  });

  // Build Tour subtabs
  $(document).on('click', '#gttom-build-tabs .gttom-tab:not([disabled])', function (e) {
    e.preventDefault();
    var $app = $('#gttom-operator-app');
    if (!$app.length) return;
    $app.find('#gttom-build-tabs .gttom-tab').removeClass('is-active');
    $app.find('.gttom-subpanel').removeClass('is-active');
    $(this).addClass('is-active');
    $app.find('.gttom-subpanel[data-subpanel="' + $(this).data('subtab') + '"]').addClass('is-active');
  });

  // Save general
  $(document).on('click', '#gttom-tour-save', function (e) {
    e.preventDefault();
    var $msg = $('#gttom-tour-msg');
    uiMsg($msg, '', '');

    var payload = {
      action: 'gttom_tour_save_general',
      nonce: GTTOM.nonce,
      tour_id: currentTourId(),
      name: $('#gttom-tour-name').val() || '',
      start_date: $('#gttom-tour-start').val() || '',
      pax: parseInt($('#gttom-tour-pax').val(), 10) || 1,
      currency: ($('#gttom-tour-currency').val() || 'USD'),
      vat_rate: parseFloat($('#gttom-tour-vat').val()) || 0,
      status: $('#gttom-tour-status').val() || 'draft'
    };

    $.post(GTTOM.ajaxUrl, payload).done(function (res) {
      if (!res || !res.success) {
        uiMsg($msg, (res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
        return;
      }
      setTourId(res.data.tour_id);
      uiMsg($msg, 'Saved. Tour ID #' + res.data.tour_id, 'ok');
    }).fail(function (xhr) {
      var msg = 'Request failed';
      if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
        msg = xhr.responseJSON.data.message;
      } else if (xhr && xhr.status) {
        msg = 'Request failed (' + xhr.status + ')';
      }
      uiMsg($msg, msg, 'error');
    });
  });

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#039;'})[c];
    });
  }


function labelizeKey(key) {
  key = String(key || '');
  if (!key) return '';
  // full_day_car -> Full day car
  key = key.replace(/_/g, ' ');
  return key.charAt(0).toUpperCase() + key.slice(1);
}

function prettyStepType(t) {
  var map = {
    hotel: 'Hotel',
    pickup: 'Pick-up / Drop-off',
    transfer: 'Transfer',
    meal: 'Meal',
    activity: 'Activity',
    fee: 'Fee',
    full_day_car: 'Full-day Car',
    tour_package: 'Tour Package',
    custom: 'Custom'
  };
  return map[t] || labelizeKey(t);
}

function prettyStepStatus(s) {
  var map = {
    not_booked: 'Not booked',
    pending: 'Pending',
    booked: 'Booked',
    paid: 'Paid'
  };
  return map[s] || labelizeKey(s);
}
  
  

// Map step type -> catalog entity (used in Builder for selecting a catalog item).
function catalogEntityForStepType(stepType){
  var map = {
    hotel: 'hotels',
    pickup: 'pickups',
    transfer: 'transfers',
    meal: 'meals',
    activity: 'activities',
    fee: 'fees',
    full_day_car: 'full_day_cars',
    tour_package: 'tour_packages'
  };
  return map[stepType] || '';
}

function catalogListForStep(stepType, ctx){
  var entity = catalogEntityForStepType(stepType);
  if (!entity) return [];
  return suppliersFor(entity, ctx) || [];
}

function catalogSelectedLabel(step){
  // Legacy fields: supplier_type/supplier_id/supplier_snapshot are used for the selected catalog item.
  var t = step && step.supplier_type ? String(step.supplier_type) : '';
  var sid = step && step.supplier_id ? parseInt(step.supplier_id,10) : 0;
  if (!t || !sid) return '';
  // Prefer snapshot name if present
  if (step && step.supplier_snapshot) {
    try {
      var snap = JSON.parse(step.supplier_snapshot);
      if (snap && snap.name) return String(snap.name);
    } catch(e) {}
  }
  return '';
}

function renderCatalogItemSelect(step, day){
  var stepType = step && step.step_type ? String(step.step_type) : 'custom';
  var entity = catalogEntityForStepType(stepType);
  if (!entity) {
    return '<select class="gttom-step-item" disabled><option value="0">— Not applicable —</option></select>';
  }

  var ctx = {
    // For intercity days, allow city-scoped catalog items from BOTH cities.
    city_ids: (day && day.day_type==='intercity') ? [day.from_city_id, day.to_city_id] : [day ? day.city_id : 0],
    from_city_id: day ? day.from_city_id : 0,
    to_city_id: day ? day.to_city_id : 0
  };

  var list = catalogListForStep(stepType, ctx);
  var selectedId = parseInt(step && step.supplier_id ? step.supplier_id : 0, 10) || 0;

  // If the step already has a selected catalog item but it is not active anymore,
  // keep it visible (with a warning) while hiding it from new selections.
  var selectedLabel = catalogSelectedLabel(step);
  var hasSelectedInActiveList = false;
  (list || []).forEach(function(x){
    var id = parseInt(x.id,10) || 0;
    if (id && selectedId && id === selectedId) hasSelectedInActiveList = true;
  });

  var html = '<select class="gttom-step-item" data-entity="'+entity+'">';
  html += '<option value="0">— Select —</option>';

  if (selectedId && !hasSelectedInActiveList) {
    var warnName = selectedLabel ? selectedLabel : ('Item #' + selectedId);
    html += '<option value="' + selectedId + '" selected>⚠ ' + escapeHtml(warnName) + ' (inactive or removed)</option>';
  }
  (list || []).forEach(function(x){
    var id = parseInt(x.id,10) || 0;
    var sel = (id && id === selectedId) ? ' selected' : '';
    html += '<option value="'+id+'"'+sel+'>'+escapeHtml(x.name||'')+'</option>';
  });
  html += '</select>';
  return html;
}
function updateItineraryActions(daysCount) {
    var hasTour = currentTourId() > 0;
    var $btnAdd = $('#gttom-add-day');
    var $selType = $('#gttom-new-day-type');
    var $btnRefresh = $('#gttom-refresh-tour');

    if (!$btnAdd.length) return;

	    // If no tour yet, keep everything disabled and visible state minimal.
    if (!hasTour) {
      if ($selType.length) $selType.hide();
      if ($btnRefresh.length) $btnRefresh.hide();
      return;
    }

    // Tour exists:
    // Empty itinerary should show ONLY "Add Day" (per locked UX rule).
    if (!daysCount) {
      if ($selType.length) $selType.hide();
      if ($btnRefresh.length) $btnRefresh.hide();
    } else {
      if ($selType.length) $selType.show();
      if ($btnRefresh.length) $btnRefresh.show();
    }
  }

	  // In Build Tour workspace, supplier assignment and operational status changes are managed in Ops Manager.
	  function isBuildWorkspace() {
	    var $p = $('.gttom-pro').first();
	    return $p.length && String($p.data('active') || '') === 'build';
	  }
function renderTour(tour, days, stepsByDay) {
	    var buildOnly = isBuildWorkspace();
    var $days = $('#gttom-days');
    if (!$days.length) return;

    if (!days || !days.length) {
      updateItineraryActions(0);
      $days.html('<div class="gttom-muted">No days yet.</div><div class="gttom-note">Click <strong>Add Day</strong> to start building the itinerary.</div>');
      return;
    }

    updateItineraryActions(days.length);

    var html = '';
    days.forEach(function (d) {
      var did = parseInt(d.id, 10);
      var steps = (stepsByDay && stepsByDay[did]) ? stepsByDay[did] : [];
      var dayTitle = d.title ? escapeHtml(d.title) : ('Day ' + d.day_index);
      var dayDate = d.day_date ? escapeHtml(d.day_date) : '';
      html += '<div class="gttom-day" data-day-id="' + did + '">' +
        '<div class="gttom-day-head">' +
          '<div class="gttom-day-left">' +
            '<div class="gttom-day-title">' +
              '<strong>' + dayTitle + '</strong>' +
              '<span class="gttom-badge">' + escapeHtml(d.day_type) + '</span>' +
            '</div>' +
            '<div class="gttom-day-meta">' +
              (dayDate ? ('<span class="gttom-day-datePill">' + dayDate + '</span>') : '<span class="gttom-day-datePill is-empty">Date not set</span>') +
              '<span class="gttom-muted">Day #' + escapeHtml(String(d.day_index || '')) + '</span>' +
            '</div>' +
          '</div>' +
          '<div class="gttom-day-actions">' +
            '<button type="button" class="gttom-btn gttom-btn-small gttom-btn-danger gttom-day-del">Delete day</button>' +
          '</div>' +
        '</div>' +

        '<div class="gttom-form-grid gttom-day-form">' +
          '<label>Day type' +
            '<select class="gttom-day-type">' +
              '<option value="city"' + (d.day_type === 'city' ? ' selected' : '') + '>city</option>' +
              '<option value="intercity"' + (d.day_type === 'intercity' ? ' selected' : '') + '>intercity</option>' +
            '</select>' +
          '</label>' +
          '<label>Title <input type="text" class="gttom-day-title-input" value="' + escapeHtml(d.title || '') + '" /></label>' +
          '<label>Date <input type="date" class="gttom-day-date" value="' + escapeHtml(d.day_date || '') + '" />' +
            '<span class="gttom-inline" style="margin-left:8px;"><input type="checkbox" class="gttom-day-date-override"' + ((parseInt(d.date_override||0,10)===1)?' checked':'') + ' /> Manual</span>' +
          '</label>' +
          '<label>Start time <input type="text" class="gttom-day-start" placeholder="08:00" value="' + escapeHtml(d.start_time || '') + '" /></label>' +
          '<label class="gttom-day-city-wrap">City ' + renderCitySelect('gttom-day-city-id', d.city_id) + '</label>' +
          '<label class="gttom-day-from-wrap">From ' + renderCitySelect('gttom-day-from-id', d.from_city_id) + '</label>' +
          '<label class="gttom-day-to-wrap">To ' + renderCitySelect('gttom-day-to-id', d.to_city_id) + '</label>' +
          '<label>Notes <textarea class="gttom-day-notes" rows="2">' + escapeHtml(d.notes || '') + '</textarea></label>' +
        '</div>' +

        '<div class="gttom-steps">' +
          '<div class="gttom-steps-head">' +
            '<div class="gttom-steps-title">Steps</div>' +
            (function(){
              // Gate step creation until required city context exists.
              var canAdd = true;
              if (String(d.day_type||'') === 'city') {
                canAdd = (parseInt(d.city_id||0,10) > 0);
              } else {
                canAdd = (parseInt(d.from_city_id||0,10) > 0) && (parseInt(d.to_city_id||0,10) > 0);
              }
              var dis = canAdd ? '' : ' disabled';
              var tt = canAdd ? '' : ' title="Select a city first"';
              return '<button type="button" class="gttom-btn gttom-btn-small gttom-add-step"'+dis+tt+'>Add Step</button>';
            })() +
          '</div>' +
          '<div class="gttom-steps-list">';

      if (!steps.length) {
        html += '<div class="gttom-muted">No steps yet.</div>';
      } else {
        steps.forEach(function (s) {
          var stTitle = s.title ? escapeHtml(s.title) : 'Untitled step';
          html += '<div class="gttom-step" data-step-id="' + s.id + '">' +
            '<div class="gttom-step-head">' +
              '<div class="gttom-step-left">' +
                '<span class="gttom-badge">' + escapeHtml(s.step_type) + '</span>' +
                '<span class="gttom-step-titleText">' + stTitle + '</span>' +
              '</div>' +
	              '<label class="gttom-inline gttom-step-status-wrap">Status ' +
	                '<select class="gttom-step-status"' + (buildOnly ? ' disabled title="Manage status in Ops Manager"' : '') + '>' +
                  ['not_booked','pending','booked','paid'].map(function(st){
                    return '<option value="' + st + '"' + (s.status===st?' selected':'') + '>' + prettyStepStatus(st) + '</option>';
                  }).join('') +
                '</select>' +
              '</label>' +
              '<div class="gttom-step-actions">' +
                '<button type="button" class="gttom-btn gttom-btn-small gttom-step-save">Save Step</button>' +
                '<button type="button" class="gttom-btn gttom-btn-small gttom-btn-danger gttom-step-del">Delete</button>' +
              '</div>' +
            '</div>' +

            '<div class="gttom-step-paid" style="display:' + (s.status==='paid' ? 'block' : 'none') + ';">⚠️ Paid step: editing is allowed, but double-check with finance.</div>' +

            '<div class="gttom-form-grid gttom-step-form">' +
              '<label>Type' +
                '<select class="gttom-step-type">' +
                  ['hotel','pickup','transfer','meal','activity','fee','full_day_car','tour_package','custom'].map(function(t){
                    return '<option value="' + t + '"' + (s.step_type===t?' selected':'') + '>' + prettyStepType(t) + '</option>';
                  }).join('') +
                '</select>' +
              '</label>' +
              '<label>Title <input type="text" class="gttom-step-title" value="' + escapeHtml(s.title || '') + '" /></label>' +
              '<label>Time <input type="text" class="gttom-step-time" placeholder="10:00" value="' + escapeHtml(s.time || '') + '" /></label>' +
              '<label>Qty <input type="number" class="gttom-step-qty" min="1" value="' + escapeHtml(s.qty || 1) + '" \/></label>' +
	              '<label>Catalog item ' + renderCatalogItemSelect(s, d) + '</label>' +
	              (buildOnly ? '' : ('<label>Suppliers <div class="gttom-sup-wrap">' + renderSupplierChips(s.suppliers || []) + renderSupplierAddSelect(s.step_type, s.suppliers || [], s.id) + '</div></label>')) +
              '<label>Description <textarea class="gttom-step-desc" rows="2">' + escapeHtml(s.description || '') + '</textarea></label>' +
              '<label>Notes <textarea class="gttom-step-notes" rows="2">' + escapeHtml(s.notes || '') + '</textarea></label>' +
            '</div>' +
          '</div>';
        });
      }

      html += '</div></div></div></div>';
    });

    $days.html(html);

    // Toggle city/intercity fields
    $days.find('.gttom-day').each(function () {
      var $day = $(this);
      var type = $day.find('.gttom-day-type').val();
      $day.find('.gttom-day-city-wrap').toggle(type === 'city');
      $day.find('.gttom-day-from-wrap, .gttom-day-to-wrap').toggle(type === 'intercity');

      var isOverride = $day.find('.gttom-day-date-override').is(':checked');
      $day.find('.gttom-day-date').prop('disabled', !isOverride);
    });
  }

	// Supplier assignment is managed in Ops Manager only.

  // Reload current tour payload (days + steps). If fillGeneral is true,
  // also populate the General form fields from the loaded tour.
  function refreshTour(fillGeneral) {
    var $msg = $('#gttom-itin-msg');
    uiMsg($msg, '', '');
    var tid = currentTourId();
    if (!tid) { uiMsg($msg, 'Save General first to create a Tour.', 'warn'); return; }
    loadCompact(function () {
      $.post(GTTOM.ajaxUrl, { action:'gttom_tour_get', nonce:GTTOM.nonce, tour_id:tid })
        .done(function(res){
          if (!res || !res.success) {
            uiMsg($msg, (res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
            return;
          }
          // Populate General tab when requested (deep links / first load).
          if (fillGeneral && res.data && res.data.tour) {
            var t = res.data.tour;
            $('#gttom-tour-name').val(t.name || '');
            $('#gttom-tour-start').val(t.start_date || '');
            $('#gttom-tour-pax').val(parseInt(t.pax,10) || 1);
            $('#gttom-tour-currency').val(t.currency || 'USD');
            $('#gttom-tour-vat').val((t.vat_rate !== undefined && t.vat_rate !== null) ? t.vat_rate : 0);
            $('#gttom-tour-status').val(t.status || 'draft');
            // Ensure lock state reflects tour existence.
            updateBuilderLock();
          }
          renderTour(res.data.tour, res.data.days, res.data.steps_by_day);
          uiMsg($msg, 'Loaded tour.', 'ok');
        })
        .fail(function(){ uiMsg($msg,'Request failed','error'); });
    });
  }

  $(document).on('click', '#gttom-refresh-tour', function(e){ e.preventDefault(); refreshTour(); });

	// Supplier assignment is managed in Ops Manager only.

  // Save all steps (one refresh at end)
  $(document).on('click', '#gttom-save-all', function(e){
    e.preventDefault();
    var $msg = $('#gttom-itin-msg');
    uiMsg($msg,'','');
    var $steps = $('.gttom-step').filter(function(){
      return (parseInt($(this).data('step-id'),10)||0) > 0;
    });
    if (!$steps.length) {
      uiMsg($msg,'Nothing to save yet.','warn');
      return;
    }
    uiMsg($msg,'Saving all steps...','warn');
    GTTOM_SAVE_ALL = true;

    var chain = $.Deferred().resolve();
    $steps.each(function(){
      var $s = $(this);
      chain = chain.then(function(){
        return $.post(GTTOM.ajaxUrl,{
          action:'gttom_step_update',
          nonce:GTTOM.nonce,
          step_id: parseInt($s.data('step-id'),10)||0,
          step_type: $s.find('.gttom-step-type').val(),
          title: $s.find('.gttom-step-title').val(),
          time: $s.find('.gttom-step-time').val(),
          qty: parseInt($s.find('.gttom-step-qty').val(),10)||1,
          description: $s.find('.gttom-step-desc').val(),
          notes: $s.find('.gttom-step-notes').val()
        }).then(function(res){
          if (!res || !res.success) return $.Deferred().reject(res).promise();
          return res;
        });
      });
    });

    chain.then(function(){
      GTTOM_SAVE_ALL = false;
      uiMsg($msg,'All steps saved.','ok');
      refreshTour();
    }).fail(function(res){
      GTTOM_SAVE_ALL = false;
      uiMsg($msg,(res && res.data && res.data.message)?res.data.message:'Error saving steps','error');
    });
  });

  // Add day
  $(document).on('click', '#gttom-add-day', function (e) {
    e.preventDefault();
    var $msg = $('#gttom-itin-msg');
    uiMsg($msg, '', '');
    var tid = currentTourId();
    if (!tid) { uiMsg($msg, 'Save General first.', 'warn'); return; }
    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_day_add',
      nonce: GTTOM.nonce,
      tour_id: tid,
      day_type: newDayTypeDefault()
    }).done(function (res) {
      if (!res || !res.success) {
        uiMsg($msg, (res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
        return;
      }
      uiMsg($msg, 'Day added.', 'ok');
      if (!GTTOM_SAVE_ALL) refreshTour();
    }).fail(function () {
      uiMsg($msg, 'Request failed', 'error');
    });
  });

  // Day type change (toggle fields)
  $(document).on('change', '.gttom-day-type', function(){
    var $day = $(this).closest('.gttom-day');
    var type = $(this).val();
    $day.find('.gttom-day-city-wrap').toggle(type === 'city');
    $day.find('.gttom-day-from-wrap, .gttom-day-to-wrap').toggle(type === 'intercity');
  });

  // Day date override toggle
  $(document).on('change', '.gttom-day-date-override', function(){
    var $day = $(this).closest('.gttom-day');
    var on = $(this).is(':checked');
    $day.find('.gttom-day-date').prop('disabled', !on);
  });

  // --- Day auto-save (Phase A)
  // City / date / title / notes / type changes are saved automatically.
  // This removes the need for a manual "Save Day" button.
  var __daySaveTimers = {};
  function queueDayAutosave($day, opts) {
    opts = opts || {};
    var dayId = parseInt($day.data('day-id'),10)||0;
    if (!dayId) return;
    var key = String(dayId);
    if (__daySaveTimers[key]) { clearTimeout(__daySaveTimers[key]); }
    var delay = (typeof opts.delay === 'number') ? opts.delay : 450;
    __daySaveTimers[key] = setTimeout(function(){
      __daySaveTimers[key] = null;
      var type = $day.find('.gttom-day-type').val();
      var cityId = parseInt($day.find('.gttom-day-city-id').val(),10)||0;
      var fromId = parseInt($day.find('.gttom-day-from-id').val(),10)||0;
      var toId   = parseInt($day.find('.gttom-day-to-id').val(),10)||0;
      var isOverride = $day.find('.gttom-day-date-override').is(':checked');
      var dateVal = isOverride ? ($day.find('.gttom-day-date').val() || '') : '';

      // Keep Add Step button gated live as city changes.
      var canAdd = true;
      if (String(type||'') === 'city') {
        canAdd = (cityId > 0);
      } else {
        canAdd = (fromId > 0) && (toId > 0);
      }
      $day.find('.gttom-add-step').prop('disabled', !canAdd)
        .attr('title', canAdd ? '' : 'Select city first');

      $.post(GTTOM.ajaxUrl, {
        action:'gttom_day_update',
        nonce:GTTOM.nonce,
        day_id: dayId,
        day_type: type,
        title: $day.find('.gttom-day-title-input').val(),
        day_date: dateVal,
        start_time: $day.find('.gttom-day-start').val(),
        city_id: cityId,
        from_city_id: fromId,
        to_city_id: toId,
        city: cityNameById(cityId),
        from_city: cityNameById(fromId),
        to_city: cityNameById(toId),
        notes: $day.find('.gttom-day-notes').val()
      }).done(function(res){
        if (!res || !res.success) {
          // Auto-save should stay quiet unless there is an error.
          toast('Day not saved: ' + ((res && res.data && res.data.message) ? res.data.message : 'Error'), 'error');
          return;
        }
        // Refresh to apply derived changes (e.g., auto day dates) without user clicking save.
        if (opts.refresh === true && !GTTOM_SAVE_ALL) refreshTour(false);
      }).fail(function(){
        toast('Day auto-save failed', 'error');
      });
    }, delay);
  }

  // Auto-save triggers for day fields
  $(document).on('input', '.gttom-day-title-input, .gttom-day-start, .gttom-day-notes', function(){
    queueDayAutosave($(this).closest('.gttom-day'));
  });
  $(document).on('change', '.gttom-day-type, .gttom-day-city-id, .gttom-day-from-id, .gttom-day-to-id, .gttom-day-date, .gttom-day-date-override', function(){
    // If date override toggled off, keep the UI consistent and allow server to recompute.
    var $day = $(this).closest('.gttom-day');
    var refresh = $(this).hasClass('gttom-day-date-override') || $(this).hasClass('gttom-day-date');
    queueDayAutosave($day, { refresh: refresh, delay: 200 });
  });

  // Delete day
  $(document).on('click', '.gttom-day-del', function(e){
    e.preventDefault();
    if (!confirm('Delete this day and all its steps?')) return;
    var $day = $(this).closest('.gttom-day');
    var dayId = parseInt($day.data('day-id'),10)||0;
    var $msg = $('#gttom-itin-msg');
    uiMsg($msg,'','');
    $.post(GTTOM.ajaxUrl,{ action:'gttom_day_delete', nonce:GTTOM.nonce, day_id: dayId })
      .done(function(res){
        if (!res || !res.success) {
          uiMsg($msg,(res && res.data && res.data.message)?res.data.message:'Error','error');
          return;
        }
        uiMsg($msg,'Day deleted.','ok');
        if (!GTTOM_SAVE_ALL) refreshTour();
      })
      .fail(function(xhr){
        var m = 'Request failed';
        try {
          if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            m = xhr.responseJSON.data.message;
          }
        } catch(e){}
        uiMsg($msg, m, 'error');
      });
  });

  // Add step
  $(document).on('click', '.gttom-add-step', function(e){
    e.preventDefault();
    var $day = $(this).closest('.gttom-day');
    var dayId = parseInt($day.data('day-id'),10)||0;
    var $msg = $('#gttom-itin-msg');
    uiMsg($msg,'','');
    // Phase A gate: require city context before adding steps
    var dayType = String($day.find('.gttom-day-type').val() || 'city');
    var cityId = parseInt($day.find('.gttom-day-city-id').val(),10)||0;
    var fromId = parseInt($day.find('.gttom-day-from-id').val(),10)||0;
    var toId   = parseInt($day.find('.gttom-day-to-id').val(),10)||0;
    if (dayType === 'city' && !cityId) {
      toast('Select a city for this day before adding steps.', 'warn');
      $day.find('.gttom-day-city-id').focus();
      return;
    }
    if (dayType === 'intercity' && (!fromId || !toId)) {
      toast('Select both From and To cities before adding steps.', 'warn');
      (!fromId ? $day.find('.gttom-day-from-id') : $day.find('.gttom-day-to-id')).focus();
      return;
    }
    $.post(GTTOM.ajaxUrl,{ action:'gttom_step_add', nonce:GTTOM.nonce, day_id: dayId, step_type:'custom', title:'Custom' })
      .done(function(res){
        if (!res || !res.success) {
          uiMsg($msg,(res && res.data && res.data.message)?res.data.message:'Error','error');
          return;
        }
        uiMsg($msg,'Step added.','ok');
        if (!GTTOM_SAVE_ALL) refreshTour();
      })
      .fail(function(xhr){
        var m = 'Request failed';
        try {
          if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            m = xhr.responseJSON.data.message;
          }
        } catch(e){}
        uiMsg($msg, m, 'error');
      });
  });

  // Save step
  $(document).on('click', '.gttom-step-save', function(e){
    e.preventDefault();
    var $step = $(this).closest('.gttom-step');
    var stepId = parseInt($step.data('step-id'),10)||0;
    var $msg = $('#gttom-itin-msg');
    uiMsg($msg,'','');
    var stepType = $step.find('.gttom-step-type').val();

    $.post(GTTOM.ajaxUrl,{
      action:'gttom_step_update',
      nonce:GTTOM.nonce,
      step_id: stepId,
      step_type: stepType,
      title: $step.find('.gttom-step-title').val(),
      time: $step.find('.gttom-step-time').val(),
      qty: parseInt($step.find('.gttom-step-qty').val(),10)||1,
      description: $step.find('.gttom-step-desc').val(),
      notes: $step.find('.gttom-step-notes').val()
    }).done(function(res){
      if (!res || !res.success) {
        uiMsg($msg,(res && res.data && res.data.message)?res.data.message:'Error','error');
        return;
      }
      uiMsg($msg,'Step saved.','ok');
      if (!GTTOM_SAVE_ALL) refreshTour();
    }).fail(function(){ uiMsg($msg,'Request failed','error'); });
  });



  // Select catalog item for a step (stores into supplier_type/supplier_id/supplier_snapshot)
  $(document).on('change', '.gttom-step-item', function(e){
    var $sel = $(this);
    var itemId = parseInt($sel.val() || '0', 10) || 0;
    var entity = String($sel.data('entity') || '');
    var $step = $sel.closest('.gttom-step');
    var stepId = parseInt($step.data('step-id'),10)||0;
    var $msg = $('#gttom-itin-msg');
    if (!stepId || !entity) return;
    uiMsg($msg,'','');

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_step_set_supplier',
      nonce: GTTOM.nonce,
      step_id: stepId,
      entity: entity,
      supplier_id: itemId
    }).done(function(res){
      if (!res || !res.success) {
        uiMsg($msg,(res && res.data && res.data.message)?res.data.message:'Error','error');
        return;
      }
      uiMsg($msg,'Catalog item saved.','ok');
      if (!GTTOM_SAVE_ALL) refreshTour();
    }).fail(function(){ uiMsg($msg,'Request failed','error'); });
  });

  // When step type changes, clear the selected catalog item and re-render select.
  $(document).on('change', '.gttom-step-type', function(e){
    var $type = $(this);
    var $step = $type.closest('.gttom-step');
    var stepId = parseInt($step.data('step-id'),10)||0;
    if (!stepId) return;

    var $msg = $('#gttom-itin-msg');
    uiMsg($msg,'','');

    // Persist the new step type immediately.
    // IMPORTANT: we must NOT refresh the tour before saving, otherwise the
    // server payload still has the old type (usually 'custom') and the UI snaps back.
    var newType = String($type.val() || 'custom');

    // First: update the step (uses existing locked endpoint).
    $.post(GTTOM.ajaxUrl,{
      action:'gttom_step_update',
      nonce:GTTOM.nonce,
      step_id: stepId,
      step_type: newType,
      title: $step.find('.gttom-step-title').val(),
      time: $step.find('.gttom-step-time').val(),
      qty: parseInt($step.find('.gttom-step-qty').val(),10)||1,
      description: $step.find('.gttom-step-desc').val(),
      notes: $step.find('.gttom-step-notes').val()
    }).done(function(res){
      if (!res || !res.success) {
        uiMsg($msg,(res && res.data && res.data.message)?res.data.message:'Error','error');
        // Revert UI select to the last known type to avoid confusion.
        if (!GTTOM_SAVE_ALL) refreshTour();
        return;
      }

      // Second: clear previously selected catalog item (supplier_type/supplier_id snapshot)
      // because it may not be valid for the new step type.
      $.post(GTTOM.ajaxUrl,{
        action:'gttom_step_set_supplier',
        nonce:GTTOM.nonce,
        step_id: stepId,
        entity:'',
        supplier_id: 0
      }).always(function(){
        uiMsg($msg,'Step type updated.','ok');
        // Now safely refresh to rebuild the catalog dropdown filtered by the day city.
        if (!GTTOM_SAVE_ALL) refreshTour();
      });
    }).fail(function(){
      uiMsg($msg,'Request failed','error');
      if (!GTTOM_SAVE_ALL) refreshTour();
    });
  });
  // Add supplier to step (multi)
  // Phase 6.2.3 — prevent parent click handlers from interfering with selects.
  $(document).on('mousedown click', '.gttom-step-supplier-add, .gttom-step-item, .gttom-step-status, .gttom-day-type', function(e){
    e.stopPropagation();
  });

  $(document).on('change', '.gttom-step-supplier-add', function(e){
    var $sel = $(this);
    var supplierId = parseInt($sel.val() || '0', 10) || 0;
    if (!supplierId) return;
    var $step = $sel.closest('.gttom-step');
    var stepId = parseInt($step.data('step-id'),10)||0;
    var $msg = $('#gttom-itin-msg');
    if (!stepId) {
      uiMsg($msg, 'Please save this step first, then assign a supplier.', 'warn');
      $sel.val('0');
      return;
    }
    uiMsg($msg,'','');
    $.post(GTTOM.ajaxUrl, { action:'gttom_step_add_supplier', nonce:GTTOM.nonce, step_id: stepId, supplier_id: supplierId })
      .done(function(res){
        if (!res || !res.success) {
          uiMsg($msg,(res && res.data && res.data.message)?res.data.message:'Error','error');
          return;
        }
        uiMsg($msg,'Supplier added.','ok');
        if (!GTTOM_SAVE_ALL) refreshTour();
      })
      .fail(function(xhr){
        var m = 'Request failed';
        try {
          if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            m = xhr.responseJSON.data.message;
          }
        } catch(e){}
        uiMsg($msg, m, 'error');
      });

    // reset select
    $sel.val('0');
  });

  // Remove supplier from step (requires inline reason; no prompts/alerts)
  $(document).on('click', '.gttom-sup-remove', function(e){
    e.preventDefault();
    var $btn = $(this);
    var supplierId = parseInt($btn.data('supplier-id')||'0',10)||0;
    var $step = $btn.closest('.gttom-step');
    var stepId = parseInt($step.data('step-id'),10)||0;
    if (!supplierId) return;
    if (!stepId) {
      uiMsg($('#gttom-itin-msg'), 'Please save this step first, then assign a supplier.', 'warn');
      return;
    }

    // Build/Show inline panel anchored near chips
    var $chips = $step.find('.gttom-supplierChips').first();
    if (!$chips.length) $chips = $btn.closest('.gttom-supplierChips');
    var key = 'remove_' + supplierId;
    var $panel = $chips.find('.gttom-inlinepanel[data-key="'+key+'"]');
    if (!$panel.length) {
      $panel = $('<div class="gttom-inlinepanel" data-key="'+key+'">' +
        '<div class="gttom-muted">Removal requires a reason.</div>' +
        '<textarea class="gttom-sup-remove-reason" rows="2" placeholder="Why removed?"></textarea>' +
        '<div class="gttom-actionGroup" style="margin-top:8px;">' +
          '<button type="button" class="gttom-btn gttom-btn-small gttom-sup-remove-confirm-chip">Confirm remove</button>' +
          '<button type="button" class="gttom-btn gttom-btn-small gttom-btn--ghost gttom-sup-remove-cancel-chip">Cancel</button>' +
        '</div>' +
        '<div class="gttom-inline-msg" style="display:none;"></div>' +
      '</div>');
      $chips.append($panel);
    }
    $panel.find('.gttom-sup-remove-reason').val('');
    $panel.find('.gttom-inline-msg').hide().text('');
    $panel.show().data('step-id', stepId).data('supplier-id', supplierId);
  });

  $(document).on('click', '.gttom-sup-remove-cancel-chip', function(e){
    e.preventDefault();
    $(this).closest('.gttom-inlinepanel').hide();
  });

  $(document).on('click', '.gttom-sup-remove-confirm-chip', function(e){
    e.preventDefault();
    var $panel = $(this).closest('.gttom-inlinepanel');
    var stepId = parseInt($panel.data('step-id')||'0',10)||0;
    var supplierId = parseInt($panel.data('supplier-id')||'0',10)||0;
    var reason = $.trim($panel.find('.gttom-sup-remove-reason').val()||'');
    var $msg = $('#gttom-itin-msg');
    if (!reason) {
      $panel.find('.gttom-inline-msg').text('Reason is required.').show();
      return;
    }
    uiMsg($msg,'','');
    $.post(GTTOM.ajaxUrl, { action:'gttom_step_remove_supplier', nonce:GTTOM.nonce, step_id: stepId, supplier_id: supplierId, reason: reason })
      .done(function(res){
        if (!res || !res.success) {
          $panel.find('.gttom-inline-msg').text((res && res.data && res.data.message)?res.data.message:'Error').show();
          return;
        }
        uiMsg($msg,'Supplier removed.','ok');
        $panel.hide();
        if (!GTTOM_SAVE_ALL) refreshTour();
      })
      .fail(function(){ $panel.find('.gttom-inline-msg').text('Request failed').show(); });
  });

  // Change step status (Operator view)
  $(document).on('change', '.gttom-step-status', function(e){
    e.preventDefault();
    var $step = $(this).closest('.gttom-step');
    var stepId = parseInt($step.data('step-id'),10)||0;
    var status = $(this).val();
    var $msg = $('#gttom-itin-msg');
    uiMsg($msg,'','');
    if (!stepId) return;
    $.post(GTTOM.ajaxUrl,{ action:'gttom_step_set_status', nonce:GTTOM.nonce, step_id: stepId, status: status })
      .done(function(res){
        if (!res || !res.success) {
          uiMsg($msg,(res && res.data && res.data.message)?res.data.message:'Error','error');
          if (!GTTOM_SAVE_ALL) refreshTour();
          return;
        }
        uiMsg($msg,'Status updated.','ok');
        if (!GTTOM_SAVE_ALL) refreshTour();
      })
      .fail(function(){ uiMsg($msg,'Request failed','error'); refreshTour(); });
  });

  // Delete step
  
  // Cancel step editing (UI only)
  $(document).on('click', '.gttom-step-cancel', function (e) {
    e.preventDefault();
    // Simply close the editor panel/modal if present
    $('.gttom-stepEditor').removeClass('is-open');
    $('#gttom-step-editor').hide();
  });

$(document).on('click', '.gttom-step-del', function(e){
    e.preventDefault();
    if (!confirm('Delete this step?')) return;
    var $step = $(this).closest('.gttom-step');
    var stepId = parseInt($step.data('step-id'),10)||0;
    var $msg = $('#gttom-itin-msg');
    uiMsg($msg,'','');
    $.post(GTTOM.ajaxUrl,{ action:'gttom_step_delete', nonce:GTTOM.nonce, step_id: stepId })
      .done(function(res){
        if (!res || !res.success) {
          uiMsg($msg,(res && res.data && res.data.message)?res.data.message:'Error','error');
          return;
        }
        uiMsg($msg,'Step deleted.','ok');
        if (!GTTOM_SAVE_ALL) refreshTour();
      })
      .fail(function(){ uiMsg($msg,'Request failed','error'); });
  });

  // Auto-refresh itinerary when entering tab if tour exists
  $(document).on('click', '#gttom-build-tabs .gttom-tab[data-subtab="itinerary"]', function(){
    if (currentTourId()) refreshTour();
  });


  /* ============================================================
   * Phase 2.2 – Operator Admin Panel (Catalog)
   * ============================================================ */

  var CATALOG_ENTITY = 'cities';
  var CATALOG_CITIES = [];

  function catMsg(text, type) {
    var $m = $('#gttom-catalog-msg');
    if (!$m.length) return;
    $m.removeClass('gttom-msg-ok gttom-msg-warn gttom-msg-error');
    if (type) $m.addClass('gttom-msg-' + type);
    $m.text(text || '');
  }

  function catTitle(entity) {
    var map = {
      cities: 'Cities',
      hotels: 'Hotels',
      guides: 'Activities',
      activities: 'Activities',
      transfers: 'Transfers',
      pickups: 'Pick-ups / Drop-offs',
      full_day_cars: 'Full-day Cars',
      meals: 'Meals',
      fees: 'Fees',
      tour_packages: 'Tour Packages',
      suppliers: 'Suppliers'
    };
    return map[entity] || entity;
  }

  function catNeedsCountry(entity){
    return entity === 'cities';
  }

  function catNeedsActivityFields(entity){
    return entity === 'guides' || entity === 'activities';
  }

  function catNeedsCarFields(entity){
    return entity === 'transfers' || entity === 'pickups' || entity === 'full_day_cars';
  }

  function catNeedsMealFields(entity){
    return entity === 'meals';
  }

  function catNeedsFeeFields(entity){
    return entity === 'fees';
  }

  function catNeedsHotelFields(entity){
    return entity === 'hotels';
  }

  function catNeedsCity(entity) {
    return ['hotels','guides','activities','meals','fees','pickups','suppliers'].indexOf(entity) !== -1;
  }

  function catNeedsRoute(entity) {
    return entity === 'transfers';
  }

  function catNeedsCapacity(entity) {
    return entity === 'full_day_cars' || entity === 'transfers' || entity === 'pickups';
  }


  function catNeedsSuppliers(entity){
    return entity === 'suppliers';
  }

  function renderCityOptions($sel, cities, selectedId) {
    if (!$sel || !$sel.length) return;
    var html = '<option value="0">— Select —</option>';
    (cities || []).forEach(function(c){
      var sel = (parseInt(selectedId||0,10) === parseInt(c.id,10)) ? ' selected' : '';
      html += '<option value="'+c.id+'"'+sel+'>'+escapeHtml(c.name)+'</option>';
    });
    $sel.html(html);
  }

  function escapeHtml(s) {
    return String(s||'')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function catShowFields(entity) {
    // Table columns/headers are rendered per-entity (to match the HTML mock).
    // Keep only the form field toggles below.

    // Toggle form fields (entity-specific)
    $('.gttom-cat-country').toggle(catNeedsCountry(entity));
    $('.gttom-cat-itinerary, .gttom-cat-duration, .gttom-cat-pricing-mode, .gttom-cat-price-group').toggle(catNeedsActivityFields(entity));
    $('.gttom-cat-price-pp').toggle(catNeedsActivityFields(entity) || catNeedsMealFields(entity) || catNeedsFeeFields(entity));
    $('.gttom-cat-price-group').toggle(catNeedsActivityFields(entity));
    $('.gttom-cat-car-type, .gttom-cat-price-car').toggle(catNeedsCarFields(entity));
    $('.gttom-cat-meal-type').toggle(catNeedsMealFields(entity));
    $('.gttom-cat-pricing-policy, .gttom-cat-rooms').toggle(catNeedsHotelFields(entity));
    $('.gttom-cat-city').toggle(catNeedsCity(entity));
    $('.gttom-cat-from, .gttom-cat-to').toggle(catNeedsRoute(entity));
    $('.gttom-cat-capacity').toggle(catNeedsCapacity(entity));
    $('.gttom-cat-supplier-type, .gttom-cat-phone, .gttom-cat-email').toggle(catNeedsSuppliers(entity));
    $('.gttom-cat-telegram').toggle(entity === 'suppliers');

    // Fees: show only price per person.
    if (catNeedsFeeFields(entity)) {
      $('.gttom-cat-price-pp').show();
      $('.gttom-cat-pricing-mode, .gttom-cat-price-group, .gttom-cat-duration, .gttom-cat-itinerary').hide();
    }

    $('#gttom-catalog-title').text(catTitle(entity));
  }
  // ------------------------------
  // Catalog → Nav badges (counts)
  // ------------------------------
  function setBadge(entity, n){
    var $b = $('.gttom-cat-badge[data-badge="'+entity+'"]');
    if (!$b.length) return;
    $b.text(String(n||0));
  }

  function refreshCatalogBadges(){
    if (!$('#gttom-catalog-ui').length) return;
    var entities = ['cities','hotels','guides','transfers','pickups','full_day_cars','meals','fees','suppliers'];
    entities.forEach(function(ent){
      $.post(GTTOM.ajaxUrl, { action:'gttom_catalog_list', nonce:GTTOM.nonce, entity:ent })
        .done(function(resp){
          if (resp && resp.success && resp.data && resp.data.items) {
            setBadge(ent, (resp.data.items||[]).length);
          } else if (resp && resp.data && resp.data.message) {
            // Surface common causes (missing company context / auth) instead of silent emptiness.
            showBanner(resp.data.message, 'warn');
          }
        })
        .fail(function(){
          showBanner('Network error while loading catalog.', 'warn');
        });
    });
  }

  // ------------------------------
  // Catalog → Table schema (per entity)
  // ------------------------------
  function catSetHead(entity){
    var $h = $('#gttom-catalog-head');
    if (!$h.length) return;
    var head = '';

    if (entity === 'cities') {
      head = '<tr>'
           + '<th style="min-width:240px">City</th>'
           + '<th style="min-width:200px">Country</th>'
           + '<th style="min-width:140px">Status</th>'
           + '<th style="min-width:220px">Actions</th>'
           + '</tr>';
    } else if (entity === 'hotels') {
      head = '<tr>'
           + '<th style="min-width:260px">Hotel</th>'
           + '<th style="min-width:180px">City</th>'
           + '<th style="min-width:140px">Rooms</th>'
           + '<th style="min-width:140px">Status</th>'
           + '<th style="min-width:260px">Actions</th>'
           + '</tr>';
    } else if (entity === 'guides' || entity === 'activities') {
      head = '<tr>'
           + '<th style="min-width:260px">Name</th>'
           + '<th style="min-width:180px">City</th>'
           + '<th style="min-width:200px">Pricing</th>'
           + '<th style="min-width:160px">Duration</th>'
           + '<th style="min-width:140px">Status</th>'
           + '<th style="min-width:240px">Actions</th>'
           + '</tr>';
    } else if (entity === 'transfers') {
      head = '<tr>'
           + '<th style="min-width:160px">From</th>'
           + '<th style="min-width:160px">To</th>'
           + '<th style="min-width:220px">Car</th>'
           + '<th style="min-width:120px">Capacity</th>'
           + '<th style="min-width:140px">Price / car</th>'
           + '<th style="min-width:140px">Status</th>'
           + '<th style="min-width:220px">Actions</th>'
           + '</tr>';
    } else if (entity === 'pickups') {
      head = '<tr>'
           + '<th style="min-width:260px">Name</th>'
           + '<th style="min-width:180px">City</th>'
           + '<th style="min-width:220px">Car</th>'
           + '<th style="min-width:120px">Capacity</th>'
           + '<th style="min-width:140px">Price / car</th>'
           + '<th style="min-width:140px">Status</th>'
           + '<th style="min-width:220px">Actions</th>'
           + '</tr>';
    } else if (entity === 'full_day_cars') {
      head = '<tr>'
           + '<th style="min-width:220px">Car type</th>'
           + '<th style="min-width:120px">Capacity</th>'
           + '<th style="min-width:160px">Price / day</th>'
           + '<th style="min-width:140px">Status</th>'
           + '<th style="min-width:220px">Actions</th>'
           + '</tr>';
    } else if (entity === 'meals') {
      head = '<tr>'
           + '<th style="min-width:220px">Meal</th>'
           + '<th style="min-width:180px">City</th>'
           + '<th style="min-width:160px">Price / person</th>'
           + '<th style="min-width:140px">Status</th>'
           + '<th style="min-width:220px">Actions</th>'
           + '</tr>';
    } else if (entity === 'fees') {
      head = '<tr>'
           + '<th style="min-width:260px">Fee</th>'
           + '<th style="min-width:180px">City</th>'
           + '<th style="min-width:160px">Price / person</th>'
           + '<th style="min-width:140px">Status</th>'
           + '<th style="min-width:220px">Actions</th>'
           + '</tr>';
    } else if (entity === 'suppliers') {
      head = '<tr>'
           + '<th style="min-width:240px">Name</th>'
           + '<th style="min-width:140px">Type</th>'
           + '<th style="min-width:180px">City</th>'
           + '<th style="min-width:180px">Phone</th>'
           + '<th style="min-width:220px">Email</th>'
           + '<th style="min-width:140px">Status</th>'
           + '<th style="min-width:220px">Actions</th>'
           + '</tr>';
    } else {
      head = '<tr>'
           + '<th>Name</th><th>Status</th><th>Actions</th>'
           + '</tr>';
    }

    $h.html(head);
  }


  function catResetForm() {
    $('#gttom-cat-id').val('0');
    $('#gttom-cat-name').val('');
    $('#gttom-cat-meta').val('');
    $('#gttom-cat-country').val('');
    $('#gttom-cat-itinerary').val('');
    $('#gttom-cat-duration').val('');
    $('#gttom-cat-pricing-mode').val('per_person');
    $('#gttom-cat-price-pp').val('');
    $('#gttom-cat-price-group').val('');
    $('#gttom-cat-car-type').val('');
    $('#gttom-cat-price-car').val('');
    $('#gttom-cat-meal-type').val('');
    $('#gttom-cat-pricing-policy').val('');
    // Hotel rooms editor (structured rows)
    $('#gttom-cat-rooms').val(''); // legacy hidden
    setHotelRooms([]);
    $('#gttom-cat-supplier-type').val('global');
    $('#gttom-cat-phone').val('');
    $('#gttom-cat-email').val('');
    $('#gttom-sup-tg-status').text('');
    $('#gttom-sup-tg-instructions').hide();
    $('#gttom-sup-tg-command').text('/start');
    $('#gttom-sup-tg-deeplink').attr('href', '#');
    $('#gttom-sup-tg-link').val('');
    $('#gttom-sup-tg-disconnect').hide();
    $('#gttom-cat-active').prop('checked', true);
    $('#gttom-cat-city-id').val('0');
    $('#gttom-cat-from-city-id').val('0');
    $('#gttom-cat-to-city-id').val('0');
    $('#gttom-cat-capacity').val('');
  }

  // ------------------------------
  // Catalog → Hotels: structured rooms editor
  // ------------------------------
  function parseLegacyRooms(str){
    var rows = [];
    String(str||'').split(/\r?\n/).forEach(function(line){
      line = $.trim(line);
      if (!line) return;
      var parts = line.split('|').map(function(p){ return $.trim(p); });
      var type = parts[0] || '';
      var cap = (parts[1] || '').replace(/[^0-9]/g,'');
      var price = (parts[2] || '').replace(/[^0-9.]/g,'');
      if (!type) return;
      rows.push({ type:type, capacity: cap ? parseInt(cap,10): '', price: price ? String(price): '' });
    });
    return rows;
  }

  function setHotelRooms(rows){
    var $rows = $('#gttom-hotel-rooms-rows');
    if (!$rows.length) return;
    rows = rows || [];
    var html = '';
    rows.forEach(function(r, idx){
      html += '<div class="gttom-hotel-rooms__row" data-idx="'+idx+'">'
            +   '<input type="text" class="gttom-room-type" placeholder="e.g. Double" value="'+escapeHtml(r.type||'')+'" />'
            +   '<input type="number" min="1" class="gttom-room-capacity" placeholder="2" value="'+escapeHtml(r.capacity||'')+'" />'
            +   '<input type="number" min="0" step="0.01" class="gttom-room-price" placeholder="90" value="'+escapeHtml(r.price||'')+'" />'
            +   '<button type="button" class="gttom-btn gttom-btn-small gttom-btn-danger gttom-room-remove">Remove</button>'
            + '</div>';
    });
    $rows.html(html);
  }

  function addHotelRoomRow(){
    var $rows = $('#gttom-hotel-rooms-rows');
    if (!$rows.length) return;
    var idx = $rows.children().length;
    var html = '<div class="gttom-hotel-rooms__row" data-idx="'+idx+'">'
             +   '<input type="text" class="gttom-room-type" placeholder="e.g. Double" value="" />'
             +   '<input type="number" min="1" class="gttom-room-capacity" placeholder="2" value="" />'
             +   '<input type="number" min="0" step="0.01" class="gttom-room-price" placeholder="90" value="" />'
             +   '<button type="button" class="gttom-btn gttom-btn-small gttom-btn-danger gttom-room-remove">Remove</button>'
             + '</div>';
    $rows.append(html);
  }

  function getHotelRooms(){
    var out = [];
    $('#gttom-hotel-rooms-rows .gttom-hotel-rooms__row').each(function(){
      var $r = $(this);
      var type = $.trim($r.find('.gttom-room-type').val()||'');
      var cap = $.trim($r.find('.gttom-room-capacity').val()||'');
      var price = $.trim($r.find('.gttom-room-price').val()||'');
      if (!type) return;
      out.push({ type:type, capacity: cap ? parseInt(cap,10) : 0, price: price });
    });
    return out;
  }

  $(document).on('click', '#gttom-hotel-rooms-add', function(e){
    e.preventDefault();
    addHotelRoomRow();
  });
  $(document).on('click', '.gttom-room-remove', function(e){
    e.preventDefault();
    $(this).closest('.gttom-hotel-rooms__row').remove();
  });

  function catOpenForm(entity, row) {
    catShowFields(entity);

    var id = row ? row.id : 0;
    $('#gttom-cat-id').val(id ? String(id) : '0');
    $('#gttom-cat-name').val(row && row.name ? row.name : '');
    $('#gttom-cat-active').prop('checked', row ? (parseInt(row.is_active,10) === 1) : true);

    // meta_json is stored as json; support both legacy {text:""} and structured objects
    var metaObj = {};
    if (row && row.meta_json) {
      try {
        var o = JSON.parse(row.meta_json);
        if (o && typeof o === 'object') metaObj = o;
      } catch(e) {
        metaObj = { text: String(row.meta_json || '') };
      }
    }
    $('#gttom-cat-meta').val(metaObj.text ? String(metaObj.text) : '');

    // City country column
    $('#gttom-cat-country').val(row && row.country ? row.country : (metaObj.country || ''));

    // Activity fields
    $('#gttom-cat-itinerary').val(metaObj.itinerary || '');
    $('#gttom-cat-duration').val(metaObj.duration || '');
    $('#gttom-cat-pricing-mode').val(metaObj.pricing_mode || 'per_person');
    $('#gttom-cat-price-pp').val(metaObj.price_pp || metaObj.price || '');
    $('#gttom-cat-price-group').val(metaObj.price_group || '');

    // Car / transport fields
    $('#gttom-cat-car-type').val(metaObj.car_type || '');
    $('#gttom-cat-price-car').val(metaObj.price_car || '');
    if (entity === 'transfers' || entity === 'pickups') {
      // capacity stored in meta for these entities
      $('#gttom-cat-capacity').val(metaObj.capacity || '');
    }

    // Meals / Fees
    $('#gttom-cat-meal-type').val(metaObj.meal_type || '');
    if (entity === 'meals' || entity === 'fees') {
      $('#gttom-cat-price-pp').val(metaObj.price_pp || metaObj.price || '');
    }

    // Hotels
    $('#gttom-cat-pricing-policy').val(metaObj.pricing_policy || metaObj.policy || '');
    // rooms can be either an array (preferred) or a legacy string
    if (entity === 'hotels') {
      var rooms = [];
      if (Array.isArray(metaObj.rooms)) rooms = metaObj.rooms;
      else if (typeof metaObj.rooms === 'string') rooms = parseLegacyRooms(metaObj.rooms);
      else if (typeof metaObj.room_types === 'string') rooms = parseLegacyRooms(metaObj.room_types);
      else {
        // fallback: legacy hidden textarea content
        rooms = parseLegacyRooms($('#gttom-cat-rooms').val() || '');
      }
      setHotelRooms(rooms);
    }

    if (catNeedsCity(entity)) {
      renderCityOptions($('#gttom-cat-city-id'), CATALOG_CITIES, row ? row.city_id : 0);
    }
    if (catNeedsRoute(entity)) {
      renderCityOptions($('#gttom-cat-from-city-id'), CATALOG_CITIES, row ? row.from_city_id : 0);
      renderCityOptions($('#gttom-cat-to-city-id'), CATALOG_CITIES, row ? row.to_city_id : 0);
    }
    if (catNeedsCapacity(entity)) {
      $('#gttom-cat-capacity').val(row && row.capacity ? row.capacity : '');
    }

    if (catNeedsSuppliers(entity)) {
      $('#gttom-cat-supplier-type').val(row && row.supplier_type ? row.supplier_type : 'global');
      $('#gttom-cat-phone').val(row && row.phone ? row.phone : '');
      $('#gttom-cat-email').val(row && row.email ? row.email : '');
    }

    // Suppliers: Telegram connection status (stored in meta_json, server-side)
    if (entity === 'suppliers') {
      var chatId = String(metaObj.telegram_chat_id || '');
      if (chatId) {
        $('#gttom-sup-tg-status').html('Status: <strong>Connected</strong> (Chat ID: '+escapeHtml(chatId)+')');
        $('#gttom-sup-tg-disconnect').show();
      } else {
        $('#gttom-sup-tg-status').html('Status: <strong>Not connected</strong>');
        $('#gttom-sup-tg-disconnect').hide();
      }
      $('#gttom-sup-tg-instructions').hide();
      $('#gttom-sup-tg-command').text('/start');
      $('#gttom-sup-tg-deeplink').attr('href', '#');
      $('#gttom-sup-tg-link').val('');
      $('#gttom-sup-tg-generate').prop('disabled', false);
    }

    // Modal UX (Phase 0.4.3 UI polish): show the editor in an overlay.
    var $modal = $('#gttom-cat-modal');
    if ($modal.length) {
      $modal.show();
      $('body').addClass('gttom-modal-open');
    }

    $('#gttom-catalog-form').show();
  }

  function catCloseForm() {
    $('#gttom-catalog-form').hide();
    var $modal = $('#gttom-cat-modal');
    if ($modal.length) {
      $modal.hide();
      $('body').removeClass('gttom-modal-open');
    }
    catResetForm();
  }

  // Modal close helpers (safe if modal doesn't exist)
  $(document).on('click', '#gttom-cat-modal .gttom-modal__backdrop, #gttom-cat-modal-close', function(){
    catCloseForm();
  });

  function catRenderRows(entity, items) {
    var $tbody = $('#gttom-catalog-rows');
    if (!$tbody.length) return;

    catSetHead(entity);

    var emptyColspan = 3;
    if (entity === 'cities') emptyColspan = 4;
    else if (entity === 'hotels') emptyColspan = 5;
    else if (entity === 'guides' || entity === 'activities') emptyColspan = 6;
    else if (entity === 'transfers') emptyColspan = 7;
    else if (entity === 'pickups') emptyColspan = 7;
    else if (entity === 'full_day_cars') emptyColspan = 5;
    else if (entity === 'meals') emptyColspan = 5;
    else if (entity === 'fees') emptyColspan = 5;
    else if (entity === 'suppliers') emptyColspan = 7;

    if (!items || !items.length) {
      $tbody.html('<tr><td colspan="'+emptyColspan+'" class="gttom-note">No items yet.</td></tr>');
      return;
    }

    var cityNameById = {};
    (CATALOG_CITIES || []).forEach(function(c){ cityNameById[String(c.id)] = c.name; });

    function actions(btnToggleLabel){
      return '<div class="gttom-actionGroup">'
           +   '<button class="gttom-btn gttom-btn-small gttom-cat-edit">Edit</button>'
           +   '<button class="gttom-btn gttom-btn-small gttom-cat-toggle">'+btnToggleLabel+'</button>'
           +   '<button class="gttom-btn gttom-btn-small gttom-btn-danger gttom-cat-del">Delete</button>'
           + '</div>';
    }

    // Phase 10.1 polish: Suppliers get a one-click portal link generator.
    function actionsSupplier(btnToggleLabel){
      return '<div class="gttom-actionGroup">'
           +   '<button class="gttom-btn gttom-btn-small gttom-cat-edit">Edit</button>'
           +   '<button class="gttom-btn gttom-btn-small gttom-btn-ghost gttom-sup-portal">Portal link</button>'
           +   '<button class="gttom-btn gttom-btn-small gttom-cat-toggle">'+btnToggleLabel+'</button>'
           +   '<button class="gttom-btn gttom-btn-small gttom-btn-danger gttom-cat-del">Delete</button>'
           + '</div>';
    }

    var html = items.map(function(r){
      var activeVal = parseInt(r.is_active,10) === 1 ? 1 : 0;
      var status = activeVal === 1 ? 'Active' : 'Disabled';
      var btnToggle = activeVal === 1 ? 'Disable' : 'Enable';

      var m = {};
      if (r && r.meta_json) {
        try {
          var mm = JSON.parse(r.meta_json);
          if (mm && typeof mm === 'object') m = mm;
        } catch(e) { m = {}; }
      }

      if (entity === 'cities') {
        return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
             +   '<td>'+escapeHtml(r.name||'')+'</td>'
             +   '<td>'+escapeHtml(r.country||'')+'</td>'
             +   '<td>'+status+'</td>'
             +   '<td>'+actions(btnToggle)+'</td>'
             + '</tr>';
      }

      if (entity === 'hotels') {
        var roomsCount = (m && m.rooms && m.rooms.length) ? m.rooms.length : 0;
        return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
             +   '<td>'+escapeHtml(r.name||'')+'</td>'
             +   '<td>'+escapeHtml(cityNameById[String(r.city_id||'')] || '')+'</td>'
             +   '<td>'+escapeHtml(String(roomsCount))+'</td>'
             +   '<td>'+status+'</td>'
             +   '<td>'+actions(btnToggle)+'</td>'
             + '</tr>';
      }

      if (entity === 'guides' || entity === 'activities') {
        var pricing = '';
        if (m.pricing_mode === 'per_group') pricing = 'Per group — ' + (m.price_group||'');
        else pricing = 'Per person — ' + (m.price_pp||'');
        return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
             +   '<td>'+escapeHtml(r.name||'')+'</td>'
             +   '<td>'+escapeHtml(cityNameById[String(r.city_id||'')] || '')+'</td>'
             +   '<td>'+escapeHtml(pricing)+'</td>'
             +   '<td>'+escapeHtml(m.duration||'')+'</td>'
             +   '<td>'+status+'</td>'
             +   '<td>'+actions(btnToggle)+'</td>'
             + '</tr>';
      }

      if (entity === 'transfers') {
        return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
             +   '<td>'+escapeHtml(cityNameById[String(r.from_city_id||'')] || '')+'</td>'
             +   '<td>'+escapeHtml(cityNameById[String(r.to_city_id||'')] || '')+'</td>'
             +   '<td>'+escapeHtml(m.car_type||'')+'</td>'
             +   '<td>'+escapeHtml(m.capacity||'')+'</td>'
             +   '<td>'+escapeHtml(m.price_car||'')+'</td>'
             +   '<td>'+status+'</td>'
             +   '<td>'+actions(btnToggle)+'</td>'
             + '</tr>';
      }

      if (entity === 'pickups') {
        return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
             +   '<td>'+escapeHtml(r.name||'')+'</td>'
             +   '<td>'+escapeHtml(cityNameById[String(r.city_id||'')] || '')+'</td>'
             +   '<td>'+escapeHtml(m.car_type||'')+'</td>'
             +   '<td>'+escapeHtml(m.capacity||'')+'</td>'
             +   '<td>'+escapeHtml(m.price_car||'')+'</td>'
             +   '<td>'+status+'</td>'
             +   '<td>'+actions(btnToggle)+'</td>'
             + '</tr>';
      }

      if (entity === 'full_day_cars') {
        return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
             +   '<td>'+escapeHtml(m.car_type||'')+'</td>'
             +   '<td>'+escapeHtml(r.capacity||'')+'</td>'
             +   '<td>'+escapeHtml(m.price_car||'')+'</td>'
             +   '<td>'+status+'</td>'
             +   '<td>'+actions(btnToggle)+'</td>'
             + '</tr>';
      }

      if (entity === 'meals') {
        return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
             +   '<td>'+escapeHtml(m.meal_type || r.name || '')+'</td>'
             +   '<td>'+escapeHtml(cityNameById[String(r.city_id||'')] || '')+'</td>'
             +   '<td>'+escapeHtml(m.price_pp||'')+'</td>'
             +   '<td>'+status+'</td>'
             +   '<td>'+actions(btnToggle)+'</td>'
             + '</tr>';
      }

      if (entity === 'fees') {
        return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
             +   '<td>'+escapeHtml(r.name||'')+'</td>'
             +   '<td>'+escapeHtml(cityNameById[String(r.city_id||'')] || '')+'</td>'
             +   '<td>'+escapeHtml(m.price_pp||'')+'</td>'
             +   '<td>'+status+'</td>'
             +   '<td>'+actions(btnToggle)+'</td>'
             + '</tr>';
      }

      if (entity === 'suppliers') {
        var st = String(r.supplier_type || 'global');
        var stLabel = (st === 'guide') ? 'Guide' : (st === 'driver') ? 'Driver' : 'Global';
        return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
             +   '<td>'+escapeHtml(r.name||'')+'</td>'
             +   '<td>'+escapeHtml(stLabel)+'</td>'
             +   '<td>'+escapeHtml(cityNameById[String(r.city_id||'')] || '')+'</td>'
             +   '<td>'+escapeHtml(String(r.phone||''))+'</td>'
             +   '<td>'+escapeHtml(String(r.email||''))+'</td>'
             +   '<td>'+status+'</td>'
             +   '<td>'+actionsSupplier(btnToggle)+'</td>'
             + '</tr>';
      }

      return '<tr data-id="'+r.id+'" data-active="'+activeVal+'">'
           +   '<td>'+escapeHtml(r.name||'')+'</td>'
           +   '<td>'+status+'</td>'
           +   '<td>'+actions(btnToggle)+'</td>'
           + '</tr>';
    }).join('');

    $tbody.html(html);
  }

  function catLoad(entity) {
    if (!$('#gttom-catalog-rows').length) return;

    CATALOG_ENTITY = entity || 'cities';
    catCloseForm();
    catMsg('', '');

    $('#gttom-catalog-title').text(catTitle(CATALOG_ENTITY));
    $('.gttom-cnav').removeClass('is-active');
    $('.gttom-cnav[data-entity="'+CATALOG_ENTITY+'"]').addClass('is-active');

    $('#gttom-catalog-rows').html('<tr><td colspan="7" class="gttom-note">Loading…</td></tr>');

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_catalog_list',
      nonce: GTTOM.nonce,
      entity: CATALOG_ENTITY
    }).done(function(resp){
      if (!resp || !resp.success) {
        var m = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to load';
        catMsg(m, 'error');
        showBanner(m, 'warn');
        return;
      }
      CATALOG_CITIES = resp.data.cities || CATALOG_CITIES || [];
      // If entity is cities, refresh cities cache too
      if (CATALOG_ENTITY === 'cities') {
        CATALOG_CITIES = (resp.data.items || []).filter(function(r){ return parseInt(r.is_active,10) === 1; }).map(function(r){
          return {id: r.id, name: r.name};
        });
      }
      catRenderRows(CATALOG_ENTITY, resp.data.items || []);
      refreshCatalogBadges();
    }).fail(function(){
      catMsg('Network error', 'error');
    });
  }

  function catSave() {
    var id = parseInt($('#gttom-cat-id').val(), 10) || 0;
    var name = $('#gttom-cat-name').val() || '';
    name = $.trim(name);
    if (!name) { catMsg('Name is required', 'warn'); return; }

    // Validate required relationships
    if (catNeedsCity(CATALOG_ENTITY) && CATALOG_ENTITY !== 'suppliers') {
      var cidReq = parseInt($('#gttom-cat-city-id').val(),10) || 0;
      if (!cidReq) { catMsg('Please select a City.', 'warn'); return; }
    }
    if (catNeedsRoute(CATALOG_ENTITY)) {
      var fReq = parseInt($('#gttom-cat-from-city-id').val(),10) || 0;
      var tReq = parseInt($('#gttom-cat-to-city-id').val(),10) || 0;
      if (!fReq || !tReq) { catMsg('Please select From and To cities.', 'warn'); return; }
    }

    // Build meta object (kept lightweight; stores optional per-entity fields)
    var meta = {};
    var t = $.trim($('#gttom-cat-meta').val() || '');
    if (t) meta.text = t;
    if (catNeedsActivityFields(CATALOG_ENTITY)) {
      meta.itinerary = $.trim($('#gttom-cat-itinerary').val() || '');
      meta.duration = $.trim($('#gttom-cat-duration').val() || '');
      meta.pricing_mode = String($('#gttom-cat-pricing-mode').val() || 'per_person');
      meta.price_pp = $.trim($('#gttom-cat-price-pp').val() || '');
      meta.price_group = $.trim($('#gttom-cat-price-group').val() || '');
    }
    if (catNeedsCarFields(CATALOG_ENTITY)) {
      meta.car_type = $.trim($('#gttom-cat-car-type').val() || '');
      meta.price_car = $.trim($('#gttom-cat-price-car').val() || '');
      if (CATALOG_ENTITY === 'transfers' || CATALOG_ENTITY === 'pickups') {
        meta.capacity = $.trim($('#gttom-cat-capacity').val() || '');
      }
    }
    if (catNeedsMealFields(CATALOG_ENTITY)) {
      meta.meal_type = $.trim($('#gttom-cat-meal-type').val() || '');
      meta.price_pp = $.trim($('#gttom-cat-price-pp').val() || '');
    }
    if (catNeedsFeeFields(CATALOG_ENTITY)) {
      meta.price_pp = $.trim($('#gttom-cat-price-pp').val() || '');
    }
    if (catNeedsHotelFields(CATALOG_ENTITY)) {
      meta.pricing_policy = $.trim($('#gttom-cat-pricing-policy').val() || '');
      meta.rooms = getHotelRooms();
      // keep legacy hidden text in sync (for debugging only)
      try {
        $('#gttom-cat-rooms').val(JSON.stringify(meta.rooms));
      } catch(e){
        $('#gttom-cat-rooms').val('');
      }
    }

    var meta_json = '';
    if (Object.keys(meta).length) {
      try { meta_json = JSON.stringify(meta); } catch(e){ meta_json = ''; }
    }

    var payload = {
      action: 'gttom_catalog_save',
      nonce: GTTOM.nonce,
      entity: CATALOG_ENTITY,
      id: id,
      name: name,
      is_active: $('#gttom-cat-active').is(':checked') ? 1 : 0,
      meta_json: meta_json
    };

    if (catNeedsCountry(CATALOG_ENTITY)) {
      payload.country = $.trim($('#gttom-cat-country').val() || '');
    }

    if (catNeedsCity(CATALOG_ENTITY)) {
      payload.city_id = parseInt($('#gttom-cat-city-id').val(),10) || 0;
    }
    if (catNeedsRoute(CATALOG_ENTITY)) {
      payload.from_city_id = parseInt($('#gttom-cat-from-city-id').val(),10) || 0;
      payload.to_city_id   = parseInt($('#gttom-cat-to-city-id').val(),10) || 0;
    }
    // Only full_day_cars has a real capacity column; for transfers/pickups we store capacity in meta.
    if (CATALOG_ENTITY === 'full_day_cars') {
      payload.capacity = parseInt($('#gttom-cat-capacity').val(),10) || 0;
    }

    if (catNeedsSuppliers(CATALOG_ENTITY)) {
      payload.supplier_type = String($('#gttom-cat-supplier-type').val() || 'global');
      payload.phone = String($('#gttom-cat-phone').val() || '');
      payload.email = String($('#gttom-cat-email').val() || '');
    }

    $.post(GTTOM.ajaxUrl, payload).done(function(resp){
      if (!resp || !resp.success) {
        catMsg((resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed', 'error');
        return;
      }
      catMsg('Saved', 'ok');
      catCloseForm();
      catLoad(CATALOG_ENTITY);
      refreshCatalogBadges();
    }).fail(function(){
      catMsg('Network error', 'error');
    });
  }

  function catToggle(id) {
    var $tr = $('tr[data-id="'+id+'"]');
    var isActive = parseInt($tr.data('active'),10) === 1 ? 1 : 0;
    var newVal = isActive ? 0 : 1;

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_catalog_toggle',
      nonce: GTTOM.nonce,
      entity: CATALOG_ENTITY,
      id: id,
      is_active: newVal
    }).done(function(resp){
      if (!resp || !resp.success) {
        catMsg((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed', 'error');
        return;
      }
      catLoad(CATALOG_ENTITY);
      refreshCatalogBadges();
    });
  }

  function catDelete(id) {
    if (!confirm('Delete this item? If it is used in any tour, it will be disabled instead.')) return;

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_catalog_delete',
      nonce: GTTOM.nonce,
      entity: CATALOG_ENTITY,
      id: id
    }).done(function(resp){
      if (!resp || !resp.success) {
        catMsg((resp && resp.data && resp.data.message) ? resp.data.message : 'Delete failed', 'error');
        return;
      }
      catLoad(CATALOG_ENTITY);
      refreshCatalogBadges();
    }).fail(function(){ catMsg('Network error', 'error'); });
  }

  // Catalog nav events
  $(document).on('click', '.gttom-cnav', function(){
    var entity = $(this).data('entity');
    catLoad(entity);
  });

  $(document).on('click', '#gttom-catalog-add', function(){
    catResetForm();
    catOpenForm(CATALOG_ENTITY, null);
  });

  $(document).on('click', '#gttom-cat-cancel', function(){
    catCloseForm();
  });

  $(document).on('click', '#gttom-cat-save', function(){
    catSave();
  });

  $(document).on('click', '.gttom-cat-edit', function(){
    var id = parseInt($(this).closest('tr').data('id'), 10) || 0;
    if (!id) return;
    // read row from current table by scanning DOM and reloading list to find row data is harder;
    // simplest: reload list and open by fetching from cached rendered list isn't stored, so we re-fetch list and locate.
    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_catalog_list',
      nonce: GTTOM.nonce,
      entity: CATALOG_ENTITY
    }).done(function(resp){
      if (!resp || !resp.success) { catMsg('Failed to load item', 'error'); return; }
      CATALOG_CITIES = resp.data.cities || CATALOG_CITIES || [];
      var items = resp.data.items || [];
      var row = null;
      items.forEach(function(r){ if (parseInt(r.id,10) === id) row = r; });
      if (!row) { catMsg('Item not found', 'error'); return; }
      catOpenForm(CATALOG_ENTITY, row);
    });
  });

  $(document).on('click', '.gttom-cat-toggle', function(){
    var id = parseInt($(this).closest('tr').data('id'), 10) || 0;
    if (id) catToggle(id);
  });

  $(document).on('click', '.gttom-cat-del', function(){
    var id = parseInt($(this).closest('tr').data('id'), 10) || 0;
    if (id) catDelete(id);
  });

  // Phase 10.1 polish: Suppliers list → generate a read-only portal link (token) and copy it.
  $(document).on('click', '.gttom-sup-portal', function(e){
    e.preventDefault();
    var $btn = $(this);
    var supplierId = parseInt($btn.closest('tr').data('id'), 10) || 0;
    if (!supplierId) return;
    $btn.prop('disabled', true);
    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_p10_generate_supplier_link',
      nonce: GTTOM.nonce,
      supplier_id: supplierId
    }).done(function(res){
      $btn.prop('disabled', false);
      if (!res || !res.success || !res.data || !res.data.url) {
        if (window.GTTOMToast) window.GTTOMToast('Failed to generate link', 'error');
        return;
      }
      var url = String(res.data.url);

      // Copy helper
      function copied(){
        if (window.GTTOMToast) window.GTTOMToast('Portal link copied', 'success');
        else alert('Portal link copied:\n' + url);
      }
      function fallbackCopy(){
        try {
          var $tmp = $('<input>').val(url).appendTo('body').select();
          document.execCommand('copy');
          $tmp.remove();
          copied();
        } catch(err){
          alert(url);
        }
      }
      try {
        if (navigator && navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(copied).catch(fallbackCopy);
        } else {
          fallbackCopy();
        }
      } catch(e){
        fallbackCopy();
      }
    }).fail(function(){
      $btn.prop('disabled', false);
      if (window.GTTOMToast) window.GTTOMToast('Failed to generate link', 'error');
    });
  });

  // Load default catalog when entering Admin Panel tab
  $(document).on('click', '.gttom-tab[data-tab="catalog"]', function(){
    catLoad(CATALOG_ENTITY || 'cities');
    refreshCatalogBadges();
  });

  // Catalog standalone page (no main tabs): load + refresh badges on first paint.
  // Fixes: badges/counts not updating until user clicks around.
  $(function(){
    if (!$('#gttom-catalog-ui').length) return;
    // If the catalog table hasn't been loaded yet, load default entity.
    // (catLoad() also refreshes all badges.)
    if ($('#gttom-catalog-rows').length && !$('#gttom-catalog-rows').data('gttom-initialized')) {
      $('#gttom-catalog-rows').data('gttom-initialized', 1);
      catLoad(CATALOG_ENTITY || 'cities');
      refreshCatalogBadges();
    }
  });

  // Suppliers → Telegram connect/disconnect
  $(document).on('click', '#gttom-sup-tg-generate', function(e){
    e.preventDefault();
    var supplierId = parseInt($('#gttom-cat-id').val(),10) || 0;
    if (!supplierId) { catMsg('Please save supplier first.', 'warn'); return; }

    $('#gttom-sup-tg-generate').prop('disabled', true);
    $('#gttom-sup-tg-instructions').hide();

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_supplier_tg_generate',
      nonce: GTTOM.nonce,
      supplier_id: supplierId
    }).done(function(res){
      if (!res || !res.success) {
        catMsg((res && res.data && res.data.message) ? res.data.message : 'Failed', 'error');
        $('#gttom-sup-tg-generate').prop('disabled', false);
        return;
      }
      var cmd = String(res.data.command || '/start');
      var link = String(res.data.deeplink || '#');
      $('#gttom-sup-tg-command').text(cmd);
      $('#gttom-sup-tg-deeplink').attr('href', link);
      $('#gttom-sup-tg-link').val(link);
      $('#gttom-sup-tg-instructions').show();
      catMsg('Telegram connection link generated. Copy and send to supplier.', 'ok');
      $('#gttom-sup-tg-generate').prop('disabled', false);
    }).fail(function(){
      catMsg('Network error', 'error');
      $('#gttom-sup-tg-generate').prop('disabled', false);
    });
  });

  // Copy Telegram link
  $(document).on('click', '#gttom-sup-tg-copy', function(e){
    e.preventDefault();
    var val = String($('#gttom-sup-tg-link').val() || '');
    if (!val) { catMsg('Generate a link first.', 'warn'); return; }
    try {
      if (navigator && navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(val).then(function(){
          catMsg('Link copied.', 'ok');
        }).catch(function(){
          // fallback
          var $i = $('#gttom-sup-tg-link');
          $i.trigger('focus');
          $i[0].select();
          document.execCommand('copy');
          catMsg('Link copied.', 'ok');
        });
      } else {
        var $i2 = $('#gttom-sup-tg-link');
        $i2.trigger('focus');
        $i2[0].select();
        document.execCommand('copy');
        catMsg('Link copied.', 'ok');
      }
    } catch(err) {
      catMsg('Copy failed. You can copy manually.', 'warn');
    }
  });

  $(document).on('click', '#gttom-sup-tg-disconnect', function(e){
    e.preventDefault();
    var supplierId = parseInt($('#gttom-cat-id').val(),10) || 0;
    if (!supplierId) return;
    if (!confirm('Disconnect Telegram for this supplier?')) return;

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_supplier_tg_disconnect',
      nonce: GTTOM.nonce,
      supplier_id: supplierId
    }).done(function(res){
      if (!res || !res.success) {
        catMsg((res && res.data && res.data.message) ? res.data.message : 'Failed', 'error');
        return;
      }
      $('#gttom-sup-tg-status').html('Status: <strong>Not connected</strong>');
      $('#gttom-sup-tg-disconnect').hide();
      $('#gttom-sup-tg-instructions').hide();
      catMsg('Disconnected.', 'ok');
    }).fail(function(){ catMsg('Network error', 'error'); });
  });

  // Settings → Telegram
  function tgMsg(text, type){
    var $m = $('#gttom-tg-msg');
    if (!$m.length) return;
    $m.removeClass('gttom-msg-ok gttom-msg-warn gttom-msg-error');
    if (type) $m.addClass('gttom-msg-' + type);
    $m.text(text || '');
  }

  $(document).on('click', '#gttom-tg-save', function(e){
    e.preventDefault();
    var token = String($('#gttom-tg-token').val() || '');
    var link  = String($('#gttom-tg-link').val() || '');
    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_telegram_save_settings',
      nonce: GTTOM.nonce,
      token: token,
      link: link
    }).done(function(res){
      if (!res || !res.success) {
        tgMsg((res && res.data && res.data.message) ? res.data.message : 'Save failed', 'error');
        return;
      }
      // Clear token field after save.
      $('#gttom-tg-token').val('');
      tgMsg('Saved.', 'ok');
    }).fail(function(){ tgMsg('Network error', 'error'); });
  });

  $(document).on('click', '#gttom-tg-set-webhook', function(e){
    e.preventDefault();
    $.post(GTTOM.ajaxUrl, { action:'gttom_telegram_set_webhook', nonce:GTTOM.nonce })
      .done(function(res){
        if (!res || !res.success) {
          tgMsg((res && res.data && res.data.message) ? res.data.message : 'Failed', 'error');
          return;
        }
        tgMsg(res.data.message || 'Webhook set.', 'ok');
      })
      .fail(function(){ tgMsg('Network error', 'error'); });
  });

  $(document).on('click', '#gttom-tg-webhook-info', function(e){
    e.preventDefault();
    $.post(GTTOM.ajaxUrl, { action:'gttom_telegram_webhook_info', nonce:GTTOM.nonce })
      .done(function(res){
        if (!res || !res.success) {
          tgMsg((res && res.data && res.data.message) ? res.data.message : 'Failed', 'error');
          return;
        }
        tgMsg(res.data.message || 'OK', 'ok');
      })
      .fail(function(){ tgMsg('Network error', 'error'); });
  });

  /* ============================================================
   * Phase 3 – Operator: Agents (minimal add + assign)
   * ============================================================ */

  function agentMsg(text, type) {
    var $m = $('#gttom-agent-msg');
    if (!$m.length) return;
    $m.removeClass('gttom-msg-ok gttom-msg-warn gttom-msg-error');
    if (type) $m.addClass('gttom-msg-' + type);
    $m.text(text || '');
  }

  function loadOperatorAgents() {
    var $rows = $('#gttom-agents-rows');
    if (!$rows.length) return;
    $rows.html('<tr><td colspan="4" class="gttom-note">Loading…</td></tr>');
    $.post(GTTOM.ajaxUrl, { action:'gttom_operator_agents_list', nonce:GTTOM.nonce })
      .done(function(res){
        if (!res || !res.success) {
          $rows.html('<tr><td colspan="4" class="gttom-note">Failed to load agents</td></tr>');
          return;
        }
        var agents = (res.data && res.data.agents) ? res.data.agents : [];
        if (!agents.length) {
          $rows.html('<tr><td colspan="4" class="gttom-note">No agents yet.</td></tr>');
          return;
        }
        var html = agents.map(function(a){
          return '<tr data-agent-id="' + a.id + '">' +
            '<td>' + escapeHtml(a.display_name || '') + '</td>' +
            '<td>' + escapeHtml(a.email || '') + '</td>' +
            '<td>' + (parseInt(a.is_active,10)===1 ? 'active' : 'disabled') + '</td>' +
            '<td>' +
              '<div class="gttom-form-row" style="gap:8px;flex-wrap:wrap;">' +
                '<label style="min-width:120px;">Tour ID <input type="number" class="gttom-assign-tour" min="1" placeholder="e.g. 12" /></label>' +
                '<button type="button" class="gttom-btn gttom-btn-small gttom-assign-agent">Assign</button>' +
              '</div>' +
            '</td>' +
          '</tr>';
        }).join('');
        $rows.html(html);
      })
      .fail(function(){
        $rows.html('<tr><td colspan="4" class="gttom-note">Request failed</td></tr>');
      });
  }

  $(document).on('click', '.gttom-tab[data-tab="agents"]', function(){
    loadOperatorAgents();
  });

  $(document).on('click', '#gttom-agent-add', function(e){
    e.preventDefault();
    var email = ($('#gttom-agent-email').val() || '').trim();
    agentMsg('', '');
    if (!email) { agentMsg('Enter agent email.', 'warn'); return; }
    $.post(GTTOM.ajaxUrl, { action:'gttom_operator_agents_add', nonce:GTTOM.nonce, email: email })
      .done(function(res){
        if (!res || !res.success) {
          agentMsg((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
          return;
        }
        agentMsg('Agent added.', 'ok');
        $('#gttom-agent-email').val('');
        loadOperatorAgents();
      })
      .fail(function(){ agentMsg('Request failed', 'error'); });
  });

  $(document).on('click', '.gttom-assign-agent', function(e){
    e.preventDefault();
    var $tr = $(this).closest('tr');
    var agentId = parseInt($tr.data('agent-id'),10)||0;
    var tourId = parseInt($tr.find('.gttom-assign-tour').val(),10)||0;
    agentMsg('', '');
    if (!agentId || !tourId) { agentMsg('Enter Tour ID to assign.', 'warn'); return; }
    $.post(GTTOM.ajaxUrl, { action:'gttom_operator_assign_agent', nonce:GTTOM.nonce, agent_id: agentId, tour_id: tourId })
      .done(function(res){
        if (!res || !res.success) {
          agentMsg((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
          return;
        }
        agentMsg('Assigned.', 'ok');
      })
      .fail(function(){ agentMsg('Request failed', 'error'); });
  });

  /* ============================================================
   * Phase 3 – Agent Execution dashboard
   * ============================================================ */

  function agentExecMsg(text, type) {
    var $m = $('#gttom-agent-msg');
    if (!$m.length) return;
    $m.removeClass('gttom-msg-ok gttom-msg-warn gttom-msg-error');
    if (type) $m.addClass('gttom-msg-' + type);
    $m.text(text || '');
  }

  function renderAgentTour(tour, days, stepsByDay) {
    var $box = $('#gttom-agent-tour');
    if (!$box.length) return;
    if (!tour) { $box.html('<div class="gttom-muted">Select a tour.</div>'); return; }

    var html = '<div class="gttom-note">Tour: <strong>' + escapeHtml(tour.name || '') + '</strong> · Status: ' + escapeHtml(tour.status || '') + '</div>';
    if (!days || !days.length) {
      html += '<div class="gttom-muted">No days yet.</div>';
      $box.html(html);
      return;
    }
    days.forEach(function(d){
      var did = parseInt(d.id,10);
      var steps = (stepsByDay && stepsByDay[did]) ? stepsByDay[did] : [];
      html += '<div class="gttom-day" data-day-id="' + did + '">' +
        '<div class="gttom-day-head">' +
          '<div class="gttom-day-title"><strong>Day ' + escapeHtml(d.day_index) + '</strong> <span class="gttom-badge">' + escapeHtml(d.day_type) + '</span></div>' +
        '</div>';
      if (!steps.length) {
        html += '<div class="gttom-muted">No steps.</div>';
      } else {
        steps.forEach(function(s){
          html += '<div class="gttom-step" data-step-id="' + s.id + '">' +
            '<div class="gttom-step-head">' +
              '<span class="gttom-badge">' + escapeHtml(s.step_type) + '</span>' +
              '<label class="gttom-inline">Status ' +
                '<select class="gttom-agent-step-status">' +
                  ['not_booked','pending','booked'].map(function(st){
                    return '<option value="' + st + '"' + (s.status===st?' selected':'') + '>' + prettyStepStatus(st) + '</option>';
                  }).join('') +
                '</select>' +
              '</label>' +
              '<button type="button" class="gttom-btn gttom-btn-small gttom-agent-notes-save">Save Notes</button>' +
            '</div>' +
            '<div class="gttom-form-grid" style="margin-top:8px;">' +
              '<label>Title <input type="text" value="' + escapeHtml(s.title || '') + '" disabled /></label>' +
              '<label>Time <input type="text" value="' + escapeHtml(s.time || '') + '" disabled /></label>' +
              '<label>Notes <textarea class="gttom-agent-step-notes" rows="2" placeholder="Execution notes…">' + escapeHtml(s.notes || '') + '</textarea></label>' +
            '</div>' +
          '</div>';
        });
      }
      html += '</div>';
    });
    $box.html(html);
  }

  function agentLoadTours() {
    var $sel = $('#gttom-agent-tour-select');
    if (!$sel.length) return;
    agentExecMsg('', '');
    $sel.html('<option value="0">Loading…</option>');
    $.post(GTTOM.ajaxUrl, { action:'gttom_agent_my_tours', nonce:GTTOM.nonce })
      .done(function(res){
        if (!res || !res.success) {
          agentExecMsg((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
          $sel.html('<option value="0">—</option>');
          return;
        }
        var tours = (res.data && res.data.tours) ? res.data.tours : [];
        if (!tours.length) {
          $sel.html('<option value="0">No tours assigned</option>');
          return;
        }
        var html = '<option value="0">— Select —</option>' + tours.map(function(t){
          return '<option value="' + t.id + '">' + escapeHtml(t.name || ('Tour ' + t.id)) + '</option>';
        }).join('');
        $sel.html(html);
      })
      .fail(function(){ agentExecMsg('Request failed', 'error'); $sel.html('<option value="0">—</option>'); });
  }

  function agentLoadTour(tourId) {
    tourId = parseInt(tourId,10)||0;
    if (!tourId) { renderAgentTour(null, [], {}); return; }
    agentExecMsg('', '');
    $.post(GTTOM.ajaxUrl, { action:'gttom_agent_tour_get', nonce:GTTOM.nonce, tour_id: tourId })
      .done(function(res){
        if (!res || !res.success) {
          agentExecMsg((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
          return;
        }
        renderAgentTour(res.data.tour, res.data.days, res.data.steps_by_day);
      })
      .fail(function(){ agentExecMsg('Request failed', 'error'); });
  }

  $(document).on('click', '#gttom-agent-refresh', function(e){
    e.preventDefault();
    agentLoadTours();
    var tid = parseInt($('#gttom-agent-tour-select').val(),10)||0;
    if (tid) agentLoadTour(tid);
  });

  $(document).on('change', '#gttom-agent-tour-select', function(){
    agentLoadTour($(this).val());
  });

  // Agent: change step status
  $(document).on('change', '.gttom-agent-step-status', function(e){
    e.preventDefault();
    var $step = $(this).closest('.gttom-step');
    var stepId = parseInt($step.data('step-id'),10)||0;
    var status = $(this).val();
    if (!stepId) return;
    $.post(GTTOM.ajaxUrl, { action:'gttom_step_set_status', nonce:GTTOM.nonce, step_id: stepId, status: status })
      .done(function(res){
        if (!res || !res.success) {
          agentExecMsg((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
          return;
        }
        agentExecMsg('Status updated.', 'ok');
      })
      .fail(function(){ agentExecMsg('Request failed', 'error'); });
  });

  // Agent: save notes
  $(document).on('click', '.gttom-agent-notes-save', function(e){
    e.preventDefault();
    var $step = $(this).closest('.gttom-step');
    var stepId = parseInt($step.data('step-id'),10)||0;
    var notes = $step.find('.gttom-agent-step-notes').val();
    if (!stepId) return;
    $.post(GTTOM.ajaxUrl, { action:'gttom_step_set_notes', nonce:GTTOM.nonce, step_id: stepId, notes: notes })
      .done(function(res){
        if (!res || !res.success) {
          agentExecMsg((res && res.data && res.data.message) ? res.data.message : 'Error', 'error');
          return;
        }
        agentExecMsg('Notes saved.', 'ok');
      })
      .fail(function(){ agentExecMsg('Request failed', 'error'); });
  });

  // Auto-init agent page
  if ($('#gttom-agent-app').length) {
    agentLoadTours();
  }

  // Initial lock state for itinerary UI
  updateBuilderLock();




  // ----------------------------------
  // Phase 4: Operator Tours List & Health Radar
  // ----------------------------------
  function toursMsg(text, type) {
    var $m = $('#gttom-tours-msg');
    if (!$m.length) return;
    $m.removeClass('gttom-msg-ok gttom-msg-warn gttom-msg-error')
      .addClass(type ? ('gttom-msg-' + type) : '')
      .text(text || '');
  }

  function statusLabel(k){
    if(k==='not_booked') return 'Not booked';
    if(k==='pending') return 'Pending';
    if(k==='booked') return 'Booked';
    if(k==='paid') return 'Paid';
    return k ? (k.charAt(0).toUpperCase()+k.slice(1)) : '';
  }

  function healthLabel(h) {
    if (h === 'critical') return 'CRITICAL';
    if (h === 'warning') return 'WARNING';
    return 'HEALTHY';
  }

  function fmtDate(d) {
    if (!d) return '—';
    return d;
  }

  function renderTourCard(t) {
    var id = parseInt(t.id, 10) || 0;
    var health = t.health || 'healthy';
    var cls = 'gttom-health--' + health;
    var statusKey = String(t.status || 'draft');
    var buildUrl = String(window.GTTOM_OP_BUILD_URL || '');

    var title = escapeHtml(t.name || ('Tour #' + id));
    var top = ''
      + '<div class="gttom-tourCard__top">'
      +   '<div class="gttom-tourCard__topLeft">'
      +     '<a href="#" class="gttom-tourCard__title gttom-tourOpen" data-tour="' + id + '">' + title + '</a>'
      +     '<div class="gttom-tourCard__meta">'
      +       '<span>Start: <strong>' + escapeHtml(fmtDate(t.start_date)) + '</strong></span>'
      +       '<span>Pax: <strong>' + (parseInt(t.pax,10)||1) + '</strong></span>'
      +       '<span class="gttom-hide-sm">Tour ID: <strong>' + id + '</strong></span>'
      +       (t.agent_name ? '<span class="gttom-hide-sm">Agent: <strong>' + escapeHtml(t.agent_name) + '</strong></span>' : '')
      +       '<span class="gttom-statusBadge gttom-status--' + escapeHtml(statusKey) + '">' + escapeHtml(statusKey.replace('_',' ')) + '</span>'
      +     '</div>'
      +   '</div>'
      +   '<div class="gttom-tourCard__actions">'
      +     '<button type="button" class="gttom-btn gttom-btn-ghost gttom-tourAction" data-action="edit" data-tour="' + id + '">Edit</button>'
      +     '<button type="button" class="gttom-btn gttom-btn-ghost gttom-tourAction" data-action="ops" data-tour="' + id + '">Ops</button>'
      +     '<button type="button" class="gttom-btn gttom-btn-ghost gttom-tourAction" data-action="timeline" data-tour="' + id + '">Timeline</button>'
      +     '<button type="button" class="gttom-btn gttom-btn-ghost gttom-tourAction" data-action="cancel" data-tour="' + id + '">Cancel</button>'
      +     '<button type="button" class="gttom-btn gttom-btn-danger gttom-tourAction" data-action="purge" data-tour="' + id + '">Delete Permanently</button>'
      +   '</div>'
      + '</div>';

    var lower = ''
      + '<div class="gttom-tourCard__bottom ' + cls + '">'
      +   '<div class="gttom-healthPill"><strong>' + (health.toUpperCase()) + '</strong></div>'
      +   '<div class="gttom-healthMeta">'
      +     '<span><strong>' + (parseInt(t.unbooked,10)||0) + '</strong> unbooked</span>'
      +     '<span><strong>' + (parseInt(t.pending,10)||0) + '</strong> pending</span>'
      +     '<span><strong>' + (parseInt(t.overdue,10)||0) + '</strong> overdue</span>'
      +   '</div>'
      +   '<a href="#" class="gttom-tourDetailsToggle" data-tour="' + id + '">Operational Details ▾</a>'
      + '</div>';

    var details = '<div class="gttom-tourCard__details" id="gttom-tour-details-' + id + '" style="display:none;"></div>';

    return '<div class="gttom-tourCard" data-tour="' + id + '">' + top + lower + details + '</div>';
  }

  function loadTourHealthDetails(tourId) {
    var $box = $('#gttom-tour-details-' + tourId);
    if (!$box.length) return;
    $box.html('<div class="gttom-note">Loading…</div>');

    $.post(GTTOM.ajaxUrl, { action:'gttom_operator_tour_health_details', nonce:GTTOM.nonce, tour_id: tourId })
      .done(function(res){
        if (!res || !res.success) {
          $box.html('<div class="gttom-note">Could not load details.</div>');
          return;
        }
        var d = (res.data && res.data.details) ? res.data.details : {};
        function renderList(items) {
          if (!items || !items.length) return '<div class="gttom-note">None.</div>';
          var html = '<ul class="gttom-ul">';
          items.forEach(function(it){
            var line = 'Day ' + (it.day_index||'') + ': ' + (it.title || '');
            if (it.time) line += ' (' + it.time + ')';
            if (it.expected_date) line += ' — ' + it.expected_date;
            html += '<li>' + escapeHtml(line) + '</li>';
          });
          html += '</ul>';
          return html;
        }

        var html = ''
          + '<div class="gttom-healthGrid">'
          +   '<div class="gttom-healthBox"><div class="gttom-healthBox__h">Unbooked</div>' + renderList(d.unbooked) + '</div>'
          +   '<div class="gttom-healthBox"><div class="gttom-healthBox__h">Pending</div>' + renderList(d.pending) + '</div>'
          +   '<div class="gttom-healthBox"><div class="gttom-healthBox__h">Overdue</div>' + renderList(d.overdue) + '</div>'
          + '</div>';

        $box.html(html);
      })
      .fail(function(){
        $box.html('<div class="gttom-note">Could not load details.</div>');
      });
  }

  function operatorToursLoad() {
    var $wrap = $('#gttom-operator-tours');
    window.GTTOM_OP_BUILD_URL = String($wrap.data('buildUrl') || '');
    if (!$wrap.length) return;

    var payload = {
      action: 'gttom_operator_tours_list',
      nonce: GTTOM.nonce,
      date_from: $('#gttom-tours-from').val() || '',
      date_to: $('#gttom-tours-to').val() || '',
      health_sort: $('#gttom-tours-health-sort').val() || 'critical_first',
      status: $('#gttom-tours-status').val() || '',
      agent_id: parseInt($('#gttom-tours-agent').val(), 10) || 0,
      q: $('#gttom-tours-q').val() || ''
    };

    toursMsg('', '');
    $('#gttom-tours-list').html('<div class="gttom-note">Loading…</div>');

    $.post(GTTOM.ajaxUrl, payload)
      .done(function(res){
        if (!res || !res.success) {
          toursMsg((res && res.data && res.data.message) ? res.data.message : 'Error loading tours', 'error');
          $('#gttom-tours-list').html('<div class="gttom-note">No data.</div>');
          return;
        }
        var tours = (res.data && res.data.tours) ? res.data.tours : [];
        if (!tours.length) {
          $('#gttom-tours-list').html('<div class="gttom-note">No tours found.</div>');
          return;
        }
        var html = '';
        tours.forEach(function(t){ html += renderTourCard(t); });
        $('#gttom-tours-list').html(html);
      })
      .fail(function(){
        toursMsg('Network error', 'error');
        $('#gttom-tours-list').html('<div class="gttom-note">No data.</div>');
      });
  }

  function operatorToursInit() {
    var $wrap = $('#gttom-operator-tours');
    window.GTTOM_OP_BUILD_URL = String($wrap.data('buildUrl') || '');
    window.GTTOM_OP_TIMELINE_URL = String($wrap.data('timelineUrl') || '');
    if (!$wrap.length) return;

    // Populate agent filter (existing endpoint)
    $.post(GTTOM.ajaxUrl, { action:'gttom_operator_agents_list', nonce:GTTOM.nonce })
      .done(function(res){
        if (!res || !res.success) return;
        var agents = (res.data && res.data.agents) ? res.data.agents : [];
        if (!agents.length) return;
        var html = '<option value="">All</option>';
        agents.forEach(function(a){
          var label = a.display_name || a.email || ('Agent #' + a.id);
          html += '<option value="' + (parseInt(a.id,10)||0) + '">' + escapeHtml(label) + '</option>';
        });
        $('#gttom-tours-agent').html(html);
      });

    // Actions
    $(document).on('click', '#gttom-tours-apply', function(e){
      e.preventDefault();
      operatorToursLoad();
    });
    $(document).on('click', '#gttom-tours-reset', function(e){
      e.preventDefault();
      $('#gttom-tours-from').val('');
      $('#gttom-tours-to').val('');
      $('#gttom-tours-health-sort').val('critical_first');
      $('#gttom-tours-status').val('');
      $('#gttom-tours-agent').val('');
      $('#gttom-tours-q').val('');
      operatorToursLoad();
    });

    // Toggle details per tour (lazy-load)
    $(document).on('click', '.gttom-tourDetailsToggle', function(e){
      e.preventDefault();
      var tourId = parseInt($(this).data('tour'), 10) || 0;
      var $box = $('#gttom-tour-details-' + tourId);
      if (!$box.length) return;

      var isOpen = $box.is(':visible');
      $('.gttom-tourCard__details').hide(); // keep UI clean: one open at a time
      if (isOpen) {
        $box.hide();
        return;
      }
      $box.show();
      if (!$box.data('loaded')) {
        loadTourHealthDetails(tourId);
        $box.data('loaded', 1);
      }
    });

    // initial load
    operatorToursLoad();
  }

  // ----------------------------------
  // Phase 4: Builder Back/Next navigation (auto-save draft)
  // ----------------------------------
  function builderNavInit() {
    var $app = $('#gttom-operator-app');
    if (!$app.length) return;
    if (!$('#gttom-build-tabs').length) return;

    // Add nav buttons if not present (in case template didn't include them)
    if (!$('#gttom-build-nav').length) {
      $('.gttom-subpanel').each(function(){
        if ($(this).find('.gttom-buildNav').length) return;
        $(this).append(
          '<div class="gttom-buildNav" id="gttom-build-nav">' +
            '<button class="gttom-btn gttom-btn-ghost" id="gttom-build-prev">Back</button>' +
            '<button class="gttom-btn" id="gttom-build-next">Next</button>' +
          '</div>'
        );
      });

    }

    function currentSubtabKey() {
      var $active = $('#gttom-build-tabs .gttom-tab.is-active');
      return $active.data('subtab') || '';
    }
    function move(dir) {
      var $tabs = $('#gttom-build-tabs .gttom-tab:not([disabled])');
      var idx = $tabs.index($('#gttom-build-tabs .gttom-tab.is-active'));
      if (idx < 0) return;
      var nextIdx = idx + (dir === 'next' ? 1 : -1);
      if (nextIdx < 0 || nextIdx >= $tabs.length) return;
      $tabs.eq(nextIdx).trigger('click');
    }

    function autoSaveIfNeeded(cb) {
      var key = currentSubtabKey();
      if (key !== 'general') { if (cb) cb(); return; }

      var $msg = $('#gttom-tour-msg');
      uiMsg($msg, '', '');

      // Save General first (required gate). If save fails, do NOT navigate further.
      var payload = {
        action: 'gttom_tour_save_general',
        nonce: GTTOM.nonce,
        tour_id: currentTourId(),
        name: $('#gttom-tour-name').val() || '',
        start_date: $('#gttom-tour-start').val() || '',
        pax: parseInt($('#gttom-tour-pax').val(), 10) || 1,
        currency: ($('#gttom-tour-currency').val() || 'USD'),
        vat_rate: parseFloat($('#gttom-tour-vat').val()) || 0,
        status: 'draft'
      };

      $.post(GTTOM.ajaxUrl, payload, function(res){
          if (res && res.success && res.data && res.data.tour_id) {
            setTourId(parseInt(res.data.tour_id, 10) || 0);
            if (cb) cb();
          } else {
            uiMsg($msg, 'Please save General information first.', 'warn');
          }
        })
        .fail(function(){
          uiMsg($msg, 'Could not save General information. Please try again.', 'error');
        });
    }

$(document).on('click', '#gttom-build-next', function(e){
      e.preventDefault();
      autoSaveIfNeeded(function(){ move('next'); });
    });
    $(document).on('click', '#gttom-build-prev', function(e){
      e.preventDefault();
      autoSaveIfNeeded(function(){ move('prev'); });
    });
  }


  function builderDeepLinkInit() {
    var $tourIdEl = $('#gttom-tour-id');
    if (!$tourIdEl.length) return;
    // If builder page is opened with ?tour_id=123, auto-load it for editing.
    try {
      var params = new URLSearchParams(window.location.search || '');
      var tid = parseInt(params.get('tour_id') || '0', 10) || 0;
      if (tid > 0) {
        setTourId(tid);
        // Ensure General tab is active
        $('#gttom-build-tabs .gttom-tab').removeClass('is-active');
        $('#gttom-build-tabs .gttom-tab[data-subtab="general"]').addClass('is-active');
        $('.gttom-subpanel').removeClass('is-active');
        $('.gttom-subpanel[data-subpanel="general"]').addClass('is-active');
        // Load existing tour
        refreshTour(true);
      }
    } catch (e) {}
  }


// -------------------------------------------------
// Phase 5 — Ops Console (default view)
// -------------------------------------------------

function opsConsoleInit() {
  var $root = $('#gttom-ops-root');
  if (!$root.length) return;

  var tourId = parseInt($root.data('tour-id') || '0', 10) || 0;
  if (!tourId) return;

  var backUrl    = String($root.data('back-url') || '');
  var builderUrl = String($root.data('builder-url') || '');
  var timelineUrl = String($root.data('timeline-url') || '');

  // -----------------------------
  // UI skeleton (matches preview)
  // -----------------------------
  $root.html(
    '<div class="gttom-ops-ui-scope">' +
      '<div class="topbar">' +
        '<div class="wrap">' +
          '<div class="brand">' +
            '<div class="brand-left">' +
              '<div class="logo" aria-hidden="true"></div>' +
              '<div class="brand-text" style="min-width:0">' +
                '<h1 id="gttom-ops-h1">GT TourOps Manager</h1>' +
                '<div class="sub" id="gttom-ops-hsub">Ops Console</div>' +
              '</div>' +
            '</div>' +
            '<div class="pillrow" id="gttom-ops-pills"></div>' +
          '</div>' +
        '</div>' +
      '</div>' +

      '<main class="wrap">' +
        '<section class="tour-meta">' +
          '<div class="card pad">' +
            '<div class="meta-grid" id="gttom-ops-metaGrid">' +
              '<div class="meta"><div class="k">Company</div><div class="v" id="gttom-ops-operator">—</div></div>' +
              '<div class="meta"><div class="k">Assigned agent</div><div class="v" id="gttom-ops-agent">—</div></div>' +
              '<div class="meta"><div class="k">Tour dates</div><div class="v" id="gttom-ops-dates">—</div></div>' +
              '<div class="meta"><div class="k">Quick actions</div><div class="v"><small>Open a step to operate. Status and suppliers require inline confirmation.</small></div></div>' +
            '</div>' +
            '<div class="legend" aria-label="Status legend">' +
              '<div class="pill"><span class="dot red"></span> Not booked</div>' +
              '<div class="pill"><span class="dot yellow"></span> Pending</div>' +
              '<div class="pill"><span class="dot green"></span> Booked</div>' +
              '<div class="pill"><span class="dot paid"></span> Booked & paid</div>' +
            '</div>' +
          '</div>' +

          '<div class="card pad">' +
            '<div class="meta">' +
              '<div class="k">Today’s focus</div>' +
              '<div class="v">Unbooked items across tour: <small id="globalUnbookedCount">—</small></div>' +
            '</div>' +
            '<div class="unbooked" id="globalUnbooked"></div>' +
            '<div class="hint" style="margin-top:10px">' +
              (backUrl ? ('<a class="btn" href="'+escapeHtml(backUrl)+'">Back to Tours</a> ') : '') +
              (builderUrl ? ('<a class="btn primary" href="'+escapeHtml(builderUrl)+'">Open Builder</a> ') : '') +
              (timelineUrl ? ('<a class="btn" href="'+escapeHtml(timelineUrl)+'">Timeline</a>') : '') +
            '</div>' +
          '</div>' +
        '</section>' +

        '<section class="days" id="days">' +
          '<div class="card pad"><div class="hint">Loading…</div></div>' +
        '</section>' +
      '</main>' +

      '<div class="toast" id="toast"></div>' +
    '</div>'
  );

  var toastTimer = null;
  function showToast(msg, kind){
    var el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    // Reset variants
    el.classList.remove('toast-success','toast-warn','toast-error','toast-info');
    if (kind) {
      el.classList.add('toast-' + kind);
    }
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function(){ el.classList.remove('show'); }, 1600);
  }

  function statusLabel(s){
    if(s==='not_booked' || s==='red') return 'Not booked';
    if(s==='pending' || s==='yellow') return 'Pending';
    if(s==='booked' || s==='green') return 'Booked';
    if(s==='paid') return 'Booked & paid';
    return '—';
  }
  function statusColor(s){
    if(s==='not_booked' || s==='red') return 'red';
    if(s==='pending' || s==='yellow') return 'yellow';
    if(s==='booked' || s==='green') return 'green';
    if(s==='paid') return 'paid';
    return 'red';
  }
  function normalizeStatus(s){
    s = String(s||'').trim();
    if (!s) return 'not_booked';
    if (s==='red') return 'not_booked';
    if (s==='yellow') return 'pending';
    if (s==='green') return 'booked';
    return s;
  }

  // -------------------------------------------------
  // Phase 9.5 — Ops readiness (UI-only, derived)
  // 0 = Not ready, 1 = In progress, 2 = Ready
  // Rules:
  // - ⚪ Not ready: no supplier OR status == not_booked
  // - 🟡 In progress: supplier assigned + status == pending
  // - 🟢 Ready: supplier assigned + status in {booked, paid}
  // -------------------------------------------------
  function readinessLevel(normalizedStatus, supplierCount){
    var st = normalizeStatus(normalizedStatus);
    var hasSup = (parseInt(supplierCount||0,10)||0) > 0;
    if (!hasSup || st === 'not_booked') return 0;
    if (st === 'pending') return 1;
    if (st === 'booked' || st === 'paid') return 2;
    return 0;
  }
  function readinessLabel(level){
    level = parseInt(level||0,10) || 0;
    if (level === 2) return 'Ready';
    if (level === 1) return 'In progress';
    return 'Not ready';
  }
  function readinessDotHtml(level){
    var lbl = readinessLabel(level);
    return '<span class="gttom-ready-dot gttom-ready-'+escapeHtml(String(level))+'" title="'+escapeHtml(lbl)+'" aria-label="'+escapeHtml(lbl)+'"></span>';
  }
  function applyReadinessDom($step, normalizedStatus){
    if (!$step || !$step.length) return;
    var supCount = parseInt($step.attr('data-sup-count') || '0', 10) || 0;
    var lvl = readinessLevel(normalizedStatus, supCount);
    $step.attr('data-ready', String(lvl));
    var $dot = $step.find('.gttom-ready-dot').first();
    if ($dot.length) {
      $dot.removeClass('gttom-ready-0 gttom-ready-1 gttom-ready-2').addClass('gttom-ready-'+String(lvl));
      $dot.attr('title', readinessLabel(lvl)).attr('aria-label', readinessLabel(lvl));
    }
  }

  // Suppliers cache (active only)
  var ALL_SUPPLIERS = null;

  // Keep last loaded tour payload for guardrails (Phase 7)
  var CURRENT_TOUR = null;
  function loadAllSuppliers(cb){
    if (Array.isArray(ALL_SUPPLIERS)) { if (cb) cb(ALL_SUPPLIERS); return; }
    $.post(GTTOM.ajaxUrl, { action:'gttom_catalog_list', nonce:GTTOM.nonce, entity:'suppliers' })
      .done(function(res){
        // catalog_list returns {data:{items:[...]}}. Support both legacy array response and the modern shape.
        var items = [];
        if (res && res.success) {
          if (res.data && Array.isArray(res.data.items)) items = res.data.items;
          else if (Array.isArray(res.data)) items = res.data;
        }
        if (Array.isArray(items) && items.length) {
          ALL_SUPPLIERS = items.filter(function(r){
            return String(r.is_active||'1') === '1';
          });
        } else {
          ALL_SUPPLIERS = [];
        }
        if (cb) cb(ALL_SUPPLIERS);
      })
      .fail(function(){ ALL_SUPPLIERS = []; if (cb) cb(ALL_SUPPLIERS); });
  }

  function safe(v){ return (v===null || v===undefined) ? '' : String(v); }
  function tourName(tour){
    return tour.name || tour.title || tour.tour_name || ('Tour #' + tourId);
  }

  function dayTitle(day){
    // Prefer stored title; fallback to "Day X"
    var idx = parseInt(day.day_index || day.day || day.index || '0', 10) || 0;
    var t = safe(day.title).trim();
    if (t) return t;
    return 'Day ' + (idx || '—');
  }

  // Calculate day date = tour start date + (dayIndex-1)
  // Returns ISO string (YYYY-MM-DD). If parsing fails, returns empty string.
  function calcDayDateISO(tourStartISO, dayIndex){
    if (!tourStartISO) return '';
    dayIndex = parseInt(dayIndex || '0', 10) || 0;
    if (dayIndex <= 0) return '';

    var m = String(tourStartISO).trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return '';
    var y = parseInt(m[1],10), mo = parseInt(m[2],10)-1, d = parseInt(m[3],10);
    var dt = new Date(Date.UTC(y, mo, d));
    if (isNaN(dt.getTime())) return '';
    dt.setUTCDate(dt.getUTCDate() + (dayIndex - 1));
    var yy = dt.getUTCFullYear();
    var mm = String(dt.getUTCMonth()+1).padStart(2,'0');
    var dd = String(dt.getUTCDate()).padStart(2,'0');
    return yy + '-' + mm + '-' + dd;
  }

  function stepTypeLabel(step){
    var t = safe(step.step_type || step.type || step.catalog_type || step.service_type).trim();
    if (!t) return 'Step';
    // Make human readable: "full_day_car" => "Full day car"
    t = t.replace(/_/g,' ');
    return t.charAt(0).toUpperCase() + t.slice(1);
  }

  // -------------------------------------------------
  // Catalog selection resolution (Builder -> Ops)
  // -------------------------------------------------
  // In this plugin, Builder stores the selected catalog item in legacy columns:
  // tour_steps.supplier_type + tour_steps.supplier_id + tour_steps.supplier_snapshot.
  // Ops Console must display these as the catalog item name/details.
  function parseCatalogSnapshot(step){
    var raw = step && step.supplier_snapshot ? String(step.supplier_snapshot) : '';
    if (!raw) return null;
    try {
      var obj = JSON.parse(raw);
      if (obj && typeof obj === 'object') return obj;
    } catch(e) {}
    return null;
  }

  function catalogSelection(step){
    var entity = step && step.supplier_type ? String(step.supplier_type) : '';
    var id = step && step.supplier_id ? (parseInt(step.supplier_id,10)||0) : 0;
    var snap = parseCatalogSnapshot(step);
    var name = '';
    if (snap && snap.name) name = String(snap.name);
    // Some snapshots may store text under meta_json/text
    if (!name && snap && snap.title) name = String(snap.title);
    return {
      entity: entity,
      id: id,
      name: name,
      snapshot: snap
    };
  }

  function catalogDetailsText(sel){
    if (!sel || !sel.snapshot) return '';
    var s = sel.snapshot;
    // Common keys used across catalog meta_json
    var t = '';
    if (s.itinerary) t = String(s.itinerary);
    if (!t && s.itinerary_text) t = String(s.itinerary_text);
    if (!t && s.description) t = String(s.description);
    if (!t && s.text) t = String(s.text);
    return t;
  }

  function render(tour){
    var tourStartISO = safe(tour.start_date||'').trim();
    var tourEndISO   = safe(tour.end_date||'').trim();
    // Header pills
    var pills = '';
    pills += '<div class="pill"><strong>Tour:</strong> '+escapeHtml(tourName(tour))+'</div>';
    if (tour.pax || tour.pax_count) {
      pills += '<div class="pill"><strong>Pax:</strong> '+escapeHtml(safe(tour.pax || tour.pax_count))+'</div>';
    }
    if (tour.rooming || tour.rooming_text) {
      pills += '<div class="pill"><strong>Rooming:</strong> '+escapeHtml(safe(tour.rooming || tour.rooming_text))+'</div>';
    }
    $('#gttom-ops-pills').html(pills);

    $('#gttom-ops-hsub').text('Ops Console — operate steps safely');

    // Meta grid
    $('#gttom-ops-operator').html(escapeHtml(safe((GTTOM && GTTOM.companyName) ? GTTOM.companyName : '—')));
    var agentText = safe(tour.agent_name || '').trim();
    $('#gttom-ops-agent').html(agentText ? ('Agent: <small>'+escapeHtml(agentText)+'</small>') : '<small>—</small>');

    var dates = '';
    if (tourStartISO || tourEndISO) dates = tourStartISO + (tourEndISO ? (' → ' + tourEndISO) : '');
    $('#gttom-ops-dates').html(dates ? escapeHtml(dates) : '—');

    // Build days
    var days = Array.isArray(tour.days) ? tour.days : [];
    var stepsByDay = tour.steps_by_day || {};
    var daysHtml = '';

    if (!days.length) {
      daysHtml = '<div class="card pad"><div class="hint">No days found for this tour.</div></div>';
      $('#days').html(daysHtml);
      refreshSummaries();
      return;
    }

    days.forEach(function(day){
      var did = parseInt(day.id||0,10)||0;
      var steps = (stepsByDay && stepsByDay[did]) ? stepsByDay[did] : [];

      var dayIdx = parseInt(day.day_index || day.day || day.index || '0', 10) || 0;
      var dayDate = calcDayDateISO(tourStartISO, dayIdx);

      var tTitle = safe(day.title).trim();
      var dayHeadTitle = 'Day ' + (dayIdx || '—') + (tTitle ? (' — ' + tTitle) : '');
      // Use day_title if includes type/cities already; otherwise show simple
      var chips = '';
      if (day.city_name) chips += '<span class="chip"><strong>City:</strong> '+escapeHtml(day.city_name)+'</span>';
      if (day.from_city_name && day.to_city_name) chips += '<span class="chip"><strong>Route:</strong> '+escapeHtml(day.from_city_name+' → '+day.to_city_name)+'</span>';
      chips += '<span class="chip"><strong>Steps:</strong> '+escapeHtml(String(steps.length))+'</span>';

      daysHtml += '<article class="card" data-day="'+escapeHtml(String(did||''))+'">';
      daysHtml +=   '<header class="day-head">';
      daysHtml +=     '<div class="day-title">';
      daysHtml +=       '<h2>'+escapeHtml(dayHeadTitle)+'</h2>';
      if (dayDate) {
        daysHtml +=     '<div class="sub" style="margin-top:2px">' + escapeHtml(dayDate) + '</div>';
      }
      daysHtml +=       '<div class="chips">'+chips+'</div>';
      daysHtml +=     '</div>';
      daysHtml +=     '<div class="day-actions">';
      daysHtml +=       '<button class="btn primary" type="button" data-action="expandDay">Expand all</button>';
      daysHtml +=       '<button class="btn" type="button" data-action="collapseDay">Collapse all</button>';
      daysHtml +=     '</div>';
      daysHtml +=   '</header>';

      daysHtml +=   '<div class="steps">';

      if (!steps.length) {
        daysHtml += '<div class="field"><div class="k">No steps</div><div class="v">This day has no operational steps.</div></div>';
      } else {
        steps.forEach(function(step){
          var sid = parseInt(step.id||0,10)||0;
          var st = normalizeStatus(step.status || step.step_status || step.booking_status || 'not_booked');
          var sc = statusColor(st);

          // Resolve selected catalog item (stored in supplier_* legacy fields).
          var sel = catalogSelection(step);
          var hasCatalog = !!(sel && sel.entity && sel.id);
          var catName = (sel && sel.name) ? String(sel.name) : '';
          if (!catName && hasCatalog) catName = 'Item #' + sel.id;

          var main = '';
          // Prefer catalog item name (hotel/activity/transfer/etc.) if selected.
          if (hasCatalog && catName) {
            main = catName;
          } else {
            main = safe(step.title || step.step_title || '').trim() || (stepTypeLabel(step) + ' #' + sid);
          }

          var sub  = safe(step.sub_title || step.subtitle || '').trim();

          // If step has a custom title in addition to catalog selection, show it as subtitle.
          var stepTitle = safe(step.title || step.step_title || '').trim();
          if (!sub && hasCatalog && stepTitle && catName && stepTitle !== catName) {
            sub = stepTitle;
          }

          // Suppliers (multi)
          var sups = Array.isArray(step.suppliers) ? step.suppliers : (Array.isArray(step.suppliers_list) ? step.suppliers_list : []);
          var supLine = '';
          if (sups.length) {
            supLine = sups.map(function(s){
              var n = s.supplier_name || s.name || ('Supplier #' + (s.supplier_id || s.id || ''));
              var ph = s.phone ? (' • ' + s.phone) : '';
              return n + ph;
            }).join(' | ');
          }
          if (!sub && supLine) sub = supLine;

          var supCount = (sups && sups.length) ? sups.length : 0;
          var readyLvl = readinessLevel(st, supCount);

          daysHtml += '<section class="step" data-step-id="'+escapeHtml(String(sid))+'" data-status="'+escapeHtml(sc)+'" data-sup-count="'+escapeHtml(String(supCount))+'" data-ready="'+escapeHtml(String(readyLvl))+'">';
          daysHtml +=   '<div class="step-top" role="button" tabindex="0" aria-expanded="false">';
          daysHtml +=     '<div class="tag">'+readinessDotHtml(readyLvl)+'<strong>'+escapeHtml(stepTypeLabel(step))+'</strong></div>';
          daysHtml +=     '<div class="step-line">';
          daysHtml +=       '<div class="main">'+escapeHtml(main)+'</div>';
          daysHtml +=       '<div class="sub">'+escapeHtml(sub || '—')+'</div>';
          daysHtml +=     '</div>';
          daysHtml +=     '<div class="status '+escapeHtml(sc)+'" data-action="openStatus"><span class="badge"></span> '+escapeHtml(statusLabel(st))+(st==='paid'?' <span class="tick">✓</span>':'')+'</div>';
          daysHtml +=   '</div>';

          // Body (hidden until open)
          daysHtml +=   '<div class="step-body">';
          daysHtml +=     '<div class="body-grid">';
          // Details/description
          // Builder stores operator-entered text in step.notes (and sometimes legacy fields).
          // Ops Console should show a rich description that combines itinerary text + key step info.
          var itineraryTxt = safe(step.notes || step.note || step.description || step.text || '').trim();
          if (!itineraryTxt && hasCatalog) {
            itineraryTxt = safe(catalogDetailsText(sel)).trim();
          }

          // Description is text-only (Phase 8.1 cleanup): never include status/suppliers/system summaries here.
var details = (itineraryTxt || '').trim();
daysHtml +=       '<div class="field"><div class="k">Description</div><div class="v">'+escapeHtml(details||'—').replace(/\n/g,'<br/>')+'</div></div>';

// If snapshot is missing, show a separate muted warning (not inside Description text)
if (!itineraryTxt && hasCatalog && (!sel || !sel.snapshot)) {
  daysHtml += '<div class="gttom-muted" style="margin-top:-6px;margin-bottom:10px;">⚠ Selected item snapshot is missing. Open Builder and re-save this step.</div>';
}

// Supplier field + actions
          daysHtml +=       '<div class="field">';
          daysHtml +=         '<div class="k">Suppliers</div>';
          // Build chips with delivery indicators (Phase 8)
          var reqMap = (tour && tour.requests_by_step && tour.requests_by_step[sid]) ? tour.requests_by_step[sid] : {};
          if (sups.length) {
            var chipHtml = '';
            sups.forEach(function(s){
              var supId = parseInt(s.id || s.supplier_id || 0,10) || 0;
              var name = s.supplier_name || s.name || ('Supplier #' + supId);
              var ph = s.phone ? String(s.phone) : '';
              var r = (reqMap && supId && reqMap[supId]) ? reqMap[supId] : null;
              var ch = r && r.channel ? String(r.channel).toLowerCase() : '';
              var resp = r && r.response ? String(r.response).toLowerCase() : '';
              var dot = 'neutral';
              var tip = 'No request sent yet';
              if (resp === 'accepted') { dot = 'ok'; tip = 'Accepted (' + (ch||'channel') + ')'; }
              else if (resp === 'declined' || resp === 'cancelled') { dot = 'bad'; tip = (resp === 'declined' ? 'Declined' : 'Cancelled') + ' (' + (ch||'channel') + ')'; }
              else if (r && r.created_at) { dot = 'warn'; tip = 'Request sent (' + (ch||'channel') + '), awaiting response'; }
              var chLabel = ch ? (ch === 'telegram' ? 'T' : (ch === 'email' ? 'E' : ch.substring(0,1).toUpperCase())) : '';
              chipHtml += '<span class="gttom-supchip" data-supplier-id="'+supId+'">'
                + '<span class="gttom-supdot '+dot+'" title="'+escapeHtml(tip)+'">'+escapeHtml(chLabel||'')+'</span>'
                + '<span class="gttom-supname">'+escapeHtml(name)+'</span>'
                + (ph ? ('<span class="gttom-supphone">'+escapeHtml(ph)+'</span>') : '')
                + '<button type="button" class="gttom-supchip-x" data-action="removeSupplier" data-supplier-id="'+supId+'" title="Remove supplier">×</button>'
                + '</span>';
            });
            daysHtml +=       '<div class="v gttom-supplierChips">'+chipHtml+'</div>';
          } else {
            daysHtml +=       '<div class="v gttom-supplierChips"><small>— none assigned —</small></div>';
          }

          daysHtml +=         '<div class="agent-actions">';
          daysHtml +=           '<button class="btn mini" type="button" data-action="toggleStatusPanel">Change status</button>';
          daysHtml +=           '<button class="btn mini" type="button" data-action="toggleSupplierPanel">Change suppliers</button>';
          daysHtml +=           '<button class="btn mini" type="button" data-action="toggleActivityPanel">Audit trail</button>';
          daysHtml +=         '</div>';

          // Inline status confirm panel (hidden)
          daysHtml +=         '<div class="gttom-inlinePanel" data-panel="status" style="display:none;">';
          daysHtml +=           '<div class="gttom-inlinePanel__row">';
          daysHtml +=             '<label class="gttom-inlinePanel__label">New status</label>';
          daysHtml +=             '<select class="gttom-inlinePanel__select" data-field="newStatus">';
          daysHtml +=               '<option value="not_booked">Not booked</option>';
          daysHtml +=               '<option value="pending">Pending</option>';
          daysHtml +=               '<option value="booked">Booked</option>';
          daysHtml +=               '<option value="paid">Booked & paid</option>';
          daysHtml +=             '</select>';
          daysHtml +=           '</div>';
          daysHtml +=           '<div class="gttom-inlinePanel__row">';
          daysHtml +=             '<label class="gttom-inlinePanel__label">Note (optional)</label>';
          daysHtml +=             '<input class="gttom-inlinePanel__input" data-field="statusNote" type="text" placeholder="Short internal note…"/>';
          daysHtml +=           '</div>';
          daysHtml +=           '<div class="gttom-inlinePanel__actions">';
          daysHtml +=             '<button type="button" class="gttom-btn gttom-btn-small gttom-btn" data-action="confirmStatus">Confirm</button>';
          daysHtml +=             '<button type="button" class="gttom-btn gttom-btn-small gttom-btn-ghost" data-action="cancelPanel">Cancel</button>';
          daysHtml +=           '</div>';
          daysHtml +=         '</div>';

          // Inline supplier panel (hidden)
          daysHtml +=         '<div class="gttom-inlinePanel" data-panel="supplier" style="display:none;">';
          daysHtml +=           '<div class="gttom-inlinePanel__row">';
          daysHtml +=             '<label class="gttom-inlinePanel__label">Supplier</label>';
          daysHtml +=             '<select class="gttom-inlinePanel__select" data-field="supplierId"><option value="0">Loading…</option></select>';
          daysHtml +=           '</div>';
          daysHtml +=           '<div class="gttom-inlinePanel__row">';
          daysHtml +=             '<label class="gttom-inlinePanel__label">Why changed (required)</label>';
          daysHtml +=             '<input class="gttom-inlinePanel__input" data-field="supplierReason" type="text" placeholder="Reason… (required)"/>';
          daysHtml +=           '</div>';
          daysHtml +=           '<div class="gttom-inlinePanel__actions">';
          daysHtml +=             '<button type="button" class="gttom-btn gttom-btn-small gttom-btn" data-action="confirmSupplierAdd">Assign supplier</button>';
          daysHtml +=             '<button type="button" class="gttom-btn gttom-btn-small gttom-btn-ghost" data-action="cancelPanel">Close</button>';
          daysHtml +=           '</div>';
          daysHtml +=         '</div>';

          // Inline activity panel (hidden)
          daysHtml +=         '<div class="gttom-inlinePanel" data-panel="activity" style="display:none;">';
          daysHtml +=           '<div class="gttom-muted" style="margin-bottom:6px;">Audit trail (status + supplier changes)</div>';
          daysHtml +=           '<div class="gttom-activity" data-activity="list">Loading…</div>';
          daysHtml +=           '<div class="gttom-inlinePanel__actions">';
          daysHtml +=             '<button type="button" class="gttom-btn gttom-btn-small gttom-btn-ghost" data-action="cancelPanel">Close</button>';
          daysHtml +=           '</div>';
          daysHtml +=         '</div>';

          daysHtml +=       '</div>'; // field
          daysHtml +=     '</div>'; // body-grid

                    // Notes field (Phase 8.1 cleanup): show only human-written notes.
          var notesTxt = safe(step.notes || '').trim();
          if (notesTxt) {
            var filtered = notesTxt.split(/\r?\n/).filter(function(line){
              var l = String(line||'').trim();
              if (!l) return false;
              // Hide legacy auto-appended system summaries (supplier/status changes).
              if (/^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[^\]]*\]\s*Supplier\s+(assigned|removed):/i.test(l)) return false;
              if (/^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[^\]]*\]\s*Status\s+changed:/i.test(l)) return false;
              if (/^Supplier\s+(assigned|removed):/i.test(l)) return false;
              if (/^Status\s+changed:/i.test(l)) return false;
              return true;
            }).join('\n').trim();

            if (filtered) {
              daysHtml += '<div class="field"><div class="k">Internal notes</div><div class="v desc">'+escapeHtml(filtered).replace(/\n/g,'<br/>')+'</div></div>';
            }
          }
          daysHtml +=   '</div>'; // step-body

          daysHtml += '</section>';
        });
      }

      daysHtml +=   '</div>'; // steps

      daysHtml +=   '<footer class="summary">';
      daysHtml +=     '<h3>Unbooked items</h3>';
      daysHtml +=     '<div class="unbooked" data-summary="day"></div>';
      daysHtml +=   '</footer>';

      daysHtml += '</article>';
    });

    $('#days').html(daysHtml);

    // Populate supplier dropdowns
    loadAllSuppliers(function(list){
      var optHtml = '<option value="0">Choose supplier…</option>';
      list.forEach(function(r){
        var id = parseInt(r.id,10)||0;
        var name = r.supplier_name || r.name || ('Supplier #' + id);
        var type = r.supplier_type ? (' — ' + r.supplier_type) : '';
        optHtml += '<option value="'+id+'">'+escapeHtml(name + type)+'</option>';
      });
      $('#days').find('[data-panel="supplier"] select[data-field="supplierId"]').each(function(){
        $(this).html(optHtml);
      });
    });

    refreshSummaries();
  }

  // -----------------------------
  // Interaction handlers
  // -----------------------------
  function toggleStep(stepEl, open){
    var $step = $(stepEl);
    var isOpen = (open === undefined) ? !$step.hasClass('open') : !!open;
    $step.toggleClass('open', isOpen);
    var top = $step.find('.step-top').get(0);
    if (top) top.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }

  // Step open/close
  $(document).on('click', '.gttom-ops-ui-scope .step .step-top', function(e){
    // If user clicked on status pill, don't toggle twice
    if ($(e.target).closest('[data-action="openStatus"]').length) return;
    toggleStep($(this).closest('.step'));
  });
  $(document).on('keydown', '.gttom-ops-ui-scope .step .step-top', function(e){
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      toggleStep($(this).closest('.step'));
    }
  });

  // Day expand/collapse
  $(document).on('click', '.gttom-ops-ui-scope [data-action="expandDay"]', function(){
    var $day = $(this).closest('article');
    $day.find('.step').each(function(){ toggleStep(this, true); });
  });
  $(document).on('click', '.gttom-ops-ui-scope [data-action="collapseDay"]', function(){
    var $day = $(this).closest('article');
    $day.find('.step').each(function(){ toggleStep(this, false); });
  });

  function openPanel($step, which){
    // Ensure body visible
    toggleStep($step, true);
    $step.find('.gttom-inlinePanel').hide();
    $step.find('.gttom-inlinePanel[data-panel="'+which+'"]').show();
  }

  $(document).on('click', '.gttom-ops-ui-scope [data-action="toggleStatusPanel"], .gttom-ops-ui-scope [data-action="openStatus"]', function(e){
    e.stopPropagation();
    var $step = $(this).closest('.step');
    openPanel($step, 'status');
  });

  $(document).on('click', '.gttom-ops-ui-scope [data-action="toggleSupplierPanel"]', function(e){
    e.stopPropagation();
    var $step = $(this).closest('.step');
    openPanel($step, 'supplier');
  });

  // Phase 8: Activity timeline (lazy load)
  $(document).on('click', '.gttom-ops-ui-scope [data-action="toggleActivityPanel"]', function(e){
    e.stopPropagation();
    var $step = $(this).closest('.step');
    openPanel($step, 'activity');

    var stepId = parseInt($step.attr('data-step-id') || '0', 10) || 0;
    if (!stepId) return;

    var $list = $step.find('[data-activity="list"]').first();
    if (!$list.length) return;

    // Avoid refetch if already loaded and step unchanged
    if ($list.data('loaded') && $list.data('loaded') === '1') return;

    $list.text('Loading…');
    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_step_audit',
      nonce: GTTOM.nonce,
      step_id: stepId
    }).done(function(res){
      if (!res || !res.success || !res.data || !Array.isArray(res.data.events)) {
        $list.text('No activity found.');
        return;
      }
      var ev = res.data.events;
      if (!ev.length) {
        $list.text('No activity found.');
        return;
      }
      var html = '<ul class="gttom-activityList">';
      ev.forEach(function(item){
        var ts = String(item.ts || '');
        var msg = String(item.message || '');
        html += '<li><span class="t">' + escapeHtml(ts) + '</span><span class="m">' + escapeHtml(msg) + '</span></li>';
      });
      html += '</ul>';
      $list.html(html);
      $list.data('loaded','1');
    }).fail(function(){
      $list.text('Network error');
    });
  });

  // Phase 8: Remove supplier from step (chips) — inline reason panel
  $(document).on('click', '.gttom-ops-ui-scope [data-action="removeSupplier"]', function(e){
    e.stopPropagation();
    e.preventDefault();
    var $btn = $(this);
    var supplierId = parseInt($btn.attr('data-supplier-id') || '0', 10) || 0;
    var $step = $btn.closest('.step');
    var stepId = parseInt($step.attr('data-step-id') || '0', 10) || 0;
    if (!supplierId || !stepId) return;

    var $chips = $step.find('.gttom-supplierChips').first();
    if (!$chips.length) return;

    // one panel per supplier
    var key = 'rm_' + supplierId;
    var $panel = $chips.find('.gttom-supRemovePanel[data-key="'+key+'"]').first();
    $chips.find('.gttom-supRemovePanel').hide();
    if (!$panel.length) {
      $panel = $('<div class="gttom-supRemovePanel" data-key="'+key+'">'
        + '<div class="gttom-muted">Removal requires a reason.</div>'
        + '<textarea class="gttom-supRemoveReason" rows="2" placeholder="Why removed?"></textarea>'
        + '<div class="gttom-inlinePanel__actions" style="margin-top:6px;">'
        +   '<button type="button" class="gttom-btn gttom-btn-small" data-action="confirmRemoveSupplier" data-supplier-id="'+supplierId+'">Confirm remove</button>'
        +   '<button type="button" class="gttom-btn gttom-btn-small gttom-btn-ghost" data-action="cancelRemoveSupplier">Cancel</button>'
        + '</div>'
        + '<div class="gttom-inline-msg" style="display:none;"></div>'
        + '</div>');
      $chips.append($panel);
    }
    $panel.data('step-id', stepId).data('supplier-id', supplierId);
    $panel.find('.gttom-supRemoveReason').val('');
    $panel.find('.gttom-inline-msg').hide().text('');
    $panel.show();
  });

  $(document).on('click', '.gttom-ops-ui-scope [data-action="cancelRemoveSupplier"]', function(e){
    e.stopPropagation();
    e.preventDefault();
    $(this).closest('.gttom-supRemovePanel').hide();
  });

  $(document).on('click', '.gttom-ops-ui-scope [data-action="confirmRemoveSupplier"]', function(e){
    e.stopPropagation();
    e.preventDefault();
    var $panel = $(this).closest('.gttom-supRemovePanel');
    var stepId = parseInt($panel.data('step-id') || '0', 10) || 0;
    var supplierId = parseInt($panel.data('supplier-id') || '0', 10) || 0;
    var reason = ($panel.find('.gttom-supRemoveReason').val() || '').trim();
    if (!reason) {
      $panel.find('.gttom-inline-msg').text('Reason is required.').show();
      return;
    }
    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_step_remove_supplier',
      nonce: GTTOM.nonce,
      step_id: stepId,
      supplier_id: supplierId,
      reason: reason
    }).done(function(res){
      if (!res || !res.success) {
        $panel.find('.gttom-inline-msg').text('Failed to remove supplier').show();
        return;
      }
      $panel.hide();
      fetchTour();
      showToast('Supplier removed','success');
    }).fail(function(){
      $panel.find('.gttom-inline-msg').text('Network error').show();
    });
  });

  $(document).on('click', '.gttom-ops-ui-scope [data-action="cancelPanel"]', function(e){
    e.stopPropagation();
    var $step = $(this).closest('.step');
    $step.find('.gttom-inlinePanel').hide();
  });

  function applyStatusDom($step, newStatus){
    var ns = normalizeStatus(newStatus);
    var sc = statusColor(ns);
    $step.attr('data-status', sc);
    var $status = $step.find('.status').first();
    $status.removeClass('red yellow green paid').addClass(sc);
    $status.html('<span class="badge"></span> ' + escapeHtml(statusLabel(ns)) + (ns==='paid' ? ' <span class="tick">✓</span>' : ''));
    // Phase 9.5 readiness dot updates with status changes (suppliers unchanged)
    applyReadinessDom($step, ns);
  }

  // Confirm status (AJAX)
  $(document).on('click', '.gttom-ops-ui-scope [data-action="confirmStatus"]', function(e){
    e.stopPropagation();
    var $step = $(this).closest('.step');
    var stepId = parseInt($step.attr('data-step-id') || '0', 10) || 0;
    var $panel = $(this).closest('.gttom-inlinePanel');
    var newStatus = $panel.find('[data-field="newStatus"]').val() || 'not_booked';
    var note = ($panel.find('[data-field="statusNote"]').val() || '').trim();

    if (!stepId) return;

    // Phase 7 guardrails: when setting to Pending we are about to notify suppliers.
    // Require at least one supplier AND warn if supplier has no email/telegram.
    var ns = normalizeStatus(newStatus);
    if (ns === 'pending') {
      // Find suppliers assigned to this step from last loaded tour payload
      var assigned = [];
      try {
        if (CURRENT_TOUR && CURRENT_TOUR.steps_by_day) {
          Object.keys(CURRENT_TOUR.steps_by_day).forEach(function(did){
            var arr = CURRENT_TOUR.steps_by_day[did] || [];
            arr.forEach(function(s){
              if (parseInt(s.id||0,10) === stepId) {
                var sups = Array.isArray(s.suppliers) ? s.suppliers : (Array.isArray(s.suppliers_list) ? s.suppliers_list : []);
                assigned = sups || [];
              }
            });
          });
        }
      } catch(err) { assigned = assigned || []; }

      if (!assigned || !assigned.length) {
        showToast('Assign a supplier before setting Pending','warn');
        return;
      }

      // Check channels (email / telegram) using suppliers catalog list
      loadAllSuppliers(function(list){
        var missing = [];
        (assigned||[]).forEach(function(a){
          var sid = parseInt(a.supplier_id || a.id || 0, 10) || 0;
          var sup = (list||[]).find(function(x){ return (parseInt(x.id||0,10)===sid); });
          var email = sup && sup.email ? String(sup.email).trim() : '';
          var tgId = '';
          if (sup && sup.meta_json) {
            try { var mo = JSON.parse(String(sup.meta_json)); tgId = mo && mo.telegram_chat_id ? String(mo.telegram_chat_id) : ''; } catch(e) {}
          }
          if (!email && !tgId) {
            missing.push(sup && sup.name ? sup.name : ('Supplier #' + sid));
          }
        });

        var msg1 = 'Set status to Pending? This will send a request notification to assigned supplier(s).';
        if (missing.length) {
          msg1 += '\n\nWarning: these suppliers have no email and no Telegram connected:\n- ' + missing.join('\n- ') + '\n\nThey may not receive the request.';
        }
        if (!confirm(msg1)) return;

        // proceed with original AJAX after confirmation
        $.post(GTTOM.ajaxUrl, {
          action: 'gttom_step_set_status',
          nonce: GTTOM.nonce,
          step_id: stepId,
          status: newStatus,
          note: note
        }).done(function(res){
          if (!res || !res.success) {
            showToast('Failed to update status','error');
            return;
          }
          applyStatusDom($step, newStatus);
          $panel.hide();
          refreshSummaries();
          showToast('Status updated: ' + statusLabel(newStatus),'success');
        }).fail(function(){
          showToast('Network error','error');
        });
      });
      return; // prevent fallthrough
    }

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_step_set_status',
      nonce: GTTOM.nonce,
      step_id: stepId,
      status: newStatus,
      note: note
    }).done(function(res){
      if (!res || !res.success) {
        showToast('Failed to update status','error');
        return;
      }
      applyStatusDom($step, newStatus);
      $panel.hide();
      refreshSummaries();
      showToast('Status updated: ' + statusLabel(newStatus),'success');
    }).fail(function(){
      showToast('Network error','error');
    });
  });

  // Confirm supplier assign (AJAX)
  $(document).on('click', '.gttom-ops-ui-scope [data-action="confirmSupplierAdd"]', function(e){
    e.stopPropagation();
    var $step = $(this).closest('.step');
    var stepId = parseInt($step.attr('data-step-id') || '0', 10) || 0;
    var $panel = $(this).closest('.gttom-inlinePanel');
    var supplierId = parseInt($panel.find('[data-field="supplierId"]').val() || '0', 10) || 0;
    var reason = ($panel.find('[data-field="supplierReason"]').val() || '').trim();

    if (!stepId) return;
    if (!supplierId) { showToast('Choose supplier','warn'); return; }
    if (!reason) { showToast('Reason is required','warn'); return; }

    $.post(GTTOM.ajaxUrl, {
      action: 'gttom_step_add_supplier',
      nonce: GTTOM.nonce,
      step_id: stepId,
      supplier_id: supplierId,
      reason: reason
    }).done(function(res){
      if (!res || !res.success) {
        showToast('Failed to assign supplier','error');
        return;
      }
      // Refresh tour to show updated supplier line and notes
      fetchTour();
      showToast('Supplier assigned','success');
    }).fail(function(){
      showToast('Network error','error');
    });
  });

  // -----------------------------
  // Summaries (global + per day)
  // -----------------------------
  function refreshSummaries(){
    document.querySelectorAll('.gttom-ops-ui-scope article.card[data-day]').forEach(function(day){
      buildDaySummary(day);
    });
    buildGlobalSummary();
  }

  function buildDaySummary(dayEl){
    var holder = dayEl.querySelector('[data-summary="day"]');
    if (!holder) return;
    holder.innerHTML = '';
    var unbooked = [].slice.call(dayEl.querySelectorAll('.step')).filter(function(s){
      return (s.dataset.status === 'red');
    });

    if (unbooked.length === 0){
      var ok = document.createElement('div');
      ok.className = 'pill';
      ok.innerHTML = '<span class="dot green"></span> All booked';
      holder.appendChild(ok);
      return;
    }

    unbooked.forEach(function(step){
      var tagText = (step.querySelector('.tag strong') || {}).textContent || 'Item';
      var chip = document.createElement('div');
      chip.className = 'ub-item';
      chip.innerHTML = '<span class="badge"></span>' + escapeHtml(tagText);
      chip.addEventListener('click', function(){
        toggleStep(step, true);
        step.scrollIntoView({behavior:'smooth', block:'center'});
        step.classList.add('flash');
        setTimeout(function(){ step.classList.remove('flash'); }, 900);
      });
      holder.appendChild(chip);
    });
  }

  function buildGlobalSummary(){
    var holder = document.getElementById('globalUnbooked');
    var countEl = document.getElementById('globalUnbookedCount');
    if (!holder || !countEl) return;
    holder.innerHTML = '';
    var allRed = [].slice.call(document.querySelectorAll('.gttom-ops-ui-scope .step')).filter(function(s){
      return (s.dataset.status === 'red');
    });
    countEl.textContent = String(allRed.length);

    if (allRed.length === 0){
      var ok = document.createElement('div');
      ok.className = 'pill';
      ok.innerHTML = '<span class="dot green"></span> Everything booked';
      holder.appendChild(ok);
      return;
    }

    allRed.slice(0,8).forEach(function(step){
      var tag = (step.querySelector('.tag strong') || {}).textContent || 'Item';
      var chip = document.createElement('div');
      chip.className = 'ub-item';
      chip.innerHTML = '<span class="badge"></span>' + escapeHtml(tag);
      chip.addEventListener('click', function(){
        toggleStep(step, true);
        step.scrollIntoView({behavior:'smooth', block:'center'});
        step.classList.add('flash');
        setTimeout(function(){ step.classList.remove('flash'); }, 900);
      });
      holder.appendChild(chip);
    });

    if (allRed.length > 8){
      var more = document.createElement('div');
      more.className = 'pill';
      more.innerHTML = '<span class="dot red"></span> +' + (allRed.length - 8) + ' more';
      holder.appendChild(more);
    }
  }

  function fetchTour(){
    $.post(GTTOM.ajaxUrl, { action:'gttom_tour_get', nonce:GTTOM.nonce, tour_id: tourId })
      .done(function(res){
        if (!res || !res.success) {
          $('#days').html('<div class="card pad"><div class="hint">Failed to load tour.</div></div>');
          return;
        }
        var tour = res.data && res.data.tour ? res.data.tour : (res.data || {});
        tour.days = (res.data && res.data.days) ? res.data.days : (tour.days || []);
        tour.steps_by_day = (res.data && res.data.steps_by_day) ? res.data.steps_by_day : (tour.steps_by_day || {});
        tour.requests_by_step = (res.data && res.data.requests_by_step) ? res.data.requests_by_step : (tour.requests_by_step || {});
        CURRENT_TOUR = tour;
        render(tour);
      })
      .fail(function(){
        $('#days').html('<div class="card pad"><div class="hint">Network error.</div></div>');
      });
  }

  // Initial load
  fetchTour();
}




  
  // Tour card actions (Edit / Cancel / Purge) — must work on Tours page (not only Builder)
  function bindTourActions() {
    // avoid double-binding if scripts are enqueued multiple times
    $(document).off('click.gttomTour', '.gttom-tourAction');
    $(document).off('click.gttomTour', '.gttom-tourOpen');

    // Buttons on each tour card
    $(document).on('click.gttomTour', '.gttom-tourAction', function(e){
      e.preventDefault();
      e.stopPropagation();

      var action = String($(this).data('action') || '');
      var tourId = parseInt($(this).data('tour'), 10) || 0;
      if (!tourId) return;

      if (action === 'edit') {
        var url = String(window.GTTOM_OP_BUILD_URL || '');
        if (!url) { url = window.location.href; }
        var sep = (url.indexOf('?') >= 0) ? '&' : '?';
        window.location.href = url + sep + 'tour_id=' + encodeURIComponent(String(tourId)) + '&view=builder';
        return;
      }

      
      if (action === 'ops') {
        var url2 = String(window.GTTOM_OP_BUILD_URL || '');
        if (!url2) { url2 = window.location.href; }
        var sep2 = (url2.indexOf('?') >= 0) ? '&' : '?';
        window.location.href = url2 + sep2 + 'tour_id=' + encodeURIComponent(String(tourId)) + '&view=ops';
        return;
      }

      if (action === 'timeline') {
        var turl = String(window.GTTOM_OP_TIMELINE_URL || '');
        if (!turl) { turl = window.location.href; }
        var sepT = (turl.indexOf('?') >= 0) ? '&' : '?';
        window.location.href = turl + sepT + 'tour_id=' + encodeURIComponent(String(tourId));
        return;
      }

if (action === 'cancel') {
        if (!confirm('Cancel this tour?\n\nThis will mark it as Cancelled and hide it from the default list.')) return;
        $.post(GTTOM.ajaxUrl, { action:'gttom_tour_soft_delete', nonce:GTTOM.nonce, tour_id: tourId })
          .done(function(res){
            if (!res || !res.success) {
              alert((res && res.data && res.data.message) ? res.data.message : 'Could not cancel the tour.');
              return;
            }
            if (typeof operatorToursLoad === 'function') operatorToursLoad();
          })
          .fail(function(xhr){
            var m = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Could not cancel the tour.';
            alert(m);
          });
        return;
      }

      if (action === 'purge') {
        if (!confirm('Permanently delete this tour and all its data?\n\nThis cannot be undone.')) return;
        $.post(GTTOM.ajaxUrl, { action:'gttom_tour_hard_delete', nonce:GTTOM.nonce, tour_id: tourId })
          .done(function(res){
            if (!res || !res.success) {
              alert((res && res.data && res.data.message) ? res.data.message : 'Could not permanently delete the tour.');
              return;
            }
            if (typeof operatorToursLoad === 'function') operatorToursLoad();
          })
          .fail(function(xhr){
            var m = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Could not permanently delete the tour.';
            alert(m);
          });
        return;
      }
    });

    // Open Ops Console only when clicking the tour title
    $(document).on('click.gttomTour', '.gttom-tourOpen', function(e){
      e.preventDefault();
      var tourId = parseInt($(this).data('tour'), 10) || 0;
      if (!tourId) return;
      var url = String(window.GTTOM_OP_BUILD_URL || '');
      if (!url) { url = window.location.href; }
      var sep = (url.indexOf('?') >= 0) ? '&' : '?';
      window.location.href = url + sep + 'tour_id=' + encodeURIComponent(String(tourId)) + '&view=builder';
    });
  }


// Init Phase 4 + Phase 5 hooks
  bindTourActions();
  operatorToursInit();
  opsConsoleInit();
  builderNavInit();

  // -----------------------------
  // Phase 7: Onboarding checklist
  // -----------------------------
  (function initOnboarding(){
    var $o = $('.gttom-onboard');
    if (!$o.length) return;
    var companyId = String($o.data('company-id') || '0');
    var key = 'gttom_onboard_dismissed_' + companyId;
    if (window.localStorage && localStorage.getItem(key) === '1') {
      $o.hide();
      return;
    }

    var cities = parseInt($o.data('cities') || '0', 10) || 0;
    var suppliers = parseInt($o.data('suppliers') || '0', 10) || 0;
    var tg = parseInt($o.data('tg') || '0', 10) || 0;
    var tours = parseInt($o.data('tours') || '0', 10) || 0;

    var done = {
      cities: cities > 0,
      suppliers: suppliers > 0,
      tg: tg > 0,
      tours: tours > 0
    };

    $o.find('.gttom-onboard__item').each(function(){
      var check = String($(this).data('check') || '');
      if (check && done[check]) $(this).addClass('is-done');
    });

    // Auto-hide once everything is complete
    if (done.cities && done.suppliers && done.tg && done.tours) {
      $o.addClass('is-complete');
      setTimeout(function(){ $o.slideUp(180); }, 250);
    }

    $(document).on('click', '.gttom-onboard [data-action="dismissOnboarding"]', function(e){
      e.preventDefault();
      $o.slideUp(180);
      try { if (window.localStorage) localStorage.setItem(key, '1'); } catch(err) {}
    });
  })();
  // If builder is opened via deep link (?tour_id=123&view=builder), load that tour for editing.
  // This must run even if other initializers early-return.
  try { builderDeepLinkInit(); } catch (e) {}

  // ------------------------------------------------------------
  // Phase 10 — Automation settings (operator)
  // ------------------------------------------------------------
  (function initPhase10Automation(){
    var $root = $('#gttom-automation-root');
    if ($root.length) {
      // Phase 10.2.1: Tabs are rendered in PHP (stable). We only handle switching.
      $(document).off('click.gttomP10Tabs', '#gttom-p10-tabs .gttom-tab');
      $(document).on('click.gttomP10Tabs', '#gttom-p10-tabs .gttom-tab', function(e){
        e.preventDefault();
        var k = String($(this).data('tab') || '');
        if (!k) return;
        $('#gttom-p10-tabs .gttom-tab').removeClass('is-active');
        $(this).addClass('is-active');
        $root.find('.gttom-p10-panel').removeClass('is-active');
        $root.find('.gttom-p10-panel[data-panel="' + k + '"]').addClass('is-active');
      });
    }

    var $btn = $('#gttom-p10-save');
    if (!$btn.length) return;

    function collectTemplates(){
      return {
        booked_email_subject: $('#gttom-p10-booked-subj').val() || '',
        booked_email_body: $('#gttom-p10-booked-body').val() || '',
        booked_telegram_body: $('#gttom-p10-booked-tg').val() || '',
        paid_email_subject: $('#gttom-p10-paid-subj').val() || '',
        paid_email_body: $('#gttom-p10-paid-body').val() || '',
        paid_telegram_body: $('#gttom-p10-paid-tg').val() || ''
      };
    }

    $btn.on('click', function(e){
      e.preventDefault();
      var $msg = $('#gttom-p10-save-msg');
      $msg.text('Saving...');
      $.post((window.GTTOM && window.GTTOM.ajaxUrl) ? window.GTTOM.ajaxUrl : (window.ajaxurl || ''), {
        action: 'gttom_p10_save_automation',
        nonce: (window.GTTOM && window.GTTOM.nonce) ? window.GTTOM.nonce : '',
        pending_hours: $('#gttom-p10-pending-hours').val() || '48',
        auto_enabled: $('#gttom-p10-auto-enabled').is(':checked') ? 1 : 0,
        notify_booked: $('#gttom-p10-notify-booked').is(':checked') ? 1 : 0,
        notify_paid: $('#gttom-p10-notify-paid').is(':checked') ? 1 : 0,
        templates: collectTemplates()
      }).done(function(res){
        if (res && res.success) {
          $msg.text('Saved');
          if (window.GTTOMToast) window.GTTOMToast('Saved', 'success');
        } else {
          $msg.text((res && res.data && res.data.message) ? res.data.message : 'Save failed');
          if (window.GTTOMToast) window.GTTOMToast('Save failed', 'error');
        }
      }).fail(function(){
        $msg.text('Save failed');
        if (window.GTTOMToast) window.GTTOMToast('Save failed', 'error');
      });
    });

    $(document).on('click', '#gttom-p10-gen-supplier-link', function(e){
      e.preventDefault();
      var sid = parseInt($('#gttom-p10-supplier-id').val() || '0', 10) || 0;
      var $msg = $('#gttom-p10-gen-supplier-msg');
      var $out = $('#gttom-p10-supplier-link');
      $out.empty();
      if (!sid) {
        $msg.text('Enter Supplier ID');
        return;
      }
      $msg.text('Generating...');
      $.post((window.GTTOM && window.GTTOM.ajaxUrl) ? window.GTTOM.ajaxUrl : (window.ajaxurl || ''), {
        action: 'gttom_p10_generate_supplier_link',
        nonce: (window.GTTOM && window.GTTOM.nonce) ? window.GTTOM.nonce : '',
        supplier_id: sid
      }).done(function(res){
        if (res && res.success && res.data && res.data.url) {
          $msg.text('Done');
          var u = String(res.data.url);
          $out.html('<a href="' + u.replace(/"/g,'&quot;') + '" target="_blank" rel="noopener">' + u + '</a>');
          if (window.GTTOMToast) window.GTTOMToast('Link generated', 'success');
        } else {
          $msg.text('Failed');
          if (window.GTTOMToast) window.GTTOMToast('Failed', 'error');
        }
      }).fail(function(){
        $msg.text('Failed');
        if (window.GTTOMToast) window.GTTOMToast('Failed', 'error');
      });
    });
  })();


});
