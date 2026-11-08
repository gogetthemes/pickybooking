(function ($) {
 'use strict';

  function checkTelephone(t){
    t = String(t || '').replace(/[\s().-]/g, '');
    var pattern = new RegExp(/^[0-9\-\+]{7,15}$/);
    return pattern.test(t);
  }
  function checkEmail(m){
    var pattern = new RegExp(/^((\"[\w-\s]+\")|([\w-]+(?:\.[\w-]+)*)|(\"[\w-\s]+\")([\w-]+(?:\.[\w-]+)*))(@((?:[\w-]+\.)*\w[\w-]{0,66})\.([a-z]{2,6}(?:\.[a-z]{2})?)$)|(@\[?((25[0-5]\.|2[0-4][0-9]\.|1[0-9]{2}\.|[0-9]{1,2}\.))((25[0-5]|2[0-4][0-9]|1[0-9]{2}|[0-9]{1,2})\.){2}(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[0-9]{1,2})\]?$)/i);
    return pattern.test(m);
  }

  /**
   * Parse urlencoded JSON on data-time / data-date.
   * Handles a single encode and the legacy double encode (urlencode(json_encode(string))).
   */
  function pickyParseExcludeAttr(raw){
    if(raw == null){ return []; }
    var value = String(raw);
    if(!value.length){ return []; }
    var i;
    for(i = 0; i < 3; i++){
      try {
        value = decodeURIComponent(value);
      } catch (eDecode) {}
      try {
        var parsed = JSON.parse(value);
        if(Array.isArray(parsed)){ return parsed; }
        if(typeof parsed === 'string' && parsed.length){
          value = parsed;
          continue;
        }
        return [];
      } catch (eParse) {}
    }
    return [];
  } 
  
  var coordinates = new Array(); // coordinates of all elemnts on canvas
  var new_coordinates = new Array(); // fake coordinates for delete curent
  var coordinates_with_curent = new Array();// coordinates of all elemnts on canvas and custom curent adding
  var elements = []; // Object of elements on canvas with out curent
  var new_elements = [];
  var draggableElementCoord = new Array(); // live position of dragged element
  var revert = null; // revet whene dragged from start to canvas
  var revert_exist = true; // rever whene dragged element on canvas
  var one_time_is_done = 0; // check for one time element of array delete
  var element_id = 0;
  var applyCanvasScaleToContainer = function(){};
  /** When true, #picky_booking_time_fake change came from initSelect (date pick), not the user — don't reveal mobile table picker yet. */
  var pickySuppressSmartTableReveal = false;

  var pickyI18n = (window.pickyAjax && window.pickyAjax.i18n) ? window.pickyAjax.i18n : {};
  var pickyEmDash = (pickyI18n.em_dash !== undefined && String(pickyI18n.em_dash) !== '') ? String(pickyI18n.em_dash) : '\u2014';
  var pickyTablePrefix = (pickyI18n.table_prefix !== undefined) ? String(pickyI18n.table_prefix) : '';
  function pickyUsesBookableSquareRadiusFe($el){
    if(!$el || !$el.length){ return false; }
    var dt = ($el.attr('data-type') || '').toString().trim();
    var noRadius = { 'type-wall': 1, 'type-text': 1, 'type-shape': 1, 'type-icon': 1, 'type-4': 1, 'type-circle': 1 };
    if(noRadius[dt]){ return false; }
    if($el.hasClass('type-wall') || $el.hasClass('type-text') || $el.hasClass('type-shape') || $el.hasClass('type-icon')){ return false; }
    if($el.hasClass('type-4') || $el.hasClass('type-circle')){ return false; }
    return true;
  }
  function pickyShowPaymentReturnNotice(){
    var params;
    try {
      params = new URLSearchParams(window.location.search || '');
    } catch(e){
      return;
    }
    var payment = (params.get('picky_payment') || '').toLowerCase();
    if(!payment){ return; }
    var msg = '';
    var isSuccess = false;
    if(payment === 'success'){
      msg = 'Payment successful. Your booking is confirmed.';
      isSuccess = true;
    } else if(payment === 'cancel'){
      msg = 'Payment was cancelled. Booking is not confirmed.';
    } else {
      msg = 'Payment verification failed. Please try again.';
    }
    $('.picky-tables-wrap').each(function(){
      var wrap = $(this);
      
      if(isSuccess){
        // Show the success popup and refresh
        wrap.find('.picky-form-booking').hide();
        wrap.find('.picky-selection-tray').hide();
        
        var thx = wrap.find('.picky-form-thx');
        // Update the thank you title or add a message
        var titleElem = thx.find('.picky-form-thx-title');
        if(titleElem.length){
          thx.find('.picky-payment-thx-msg').remove();
          titleElem.after('<p class="picky-payment-thx-msg" style="color:#16a34a;margin-top:10px;font-weight:bold;text-align:center;">' + msg + '</p>');
        }
        
        // Show overlay if needed
        if(wrap.hasClass('picky-smart-booking-overlay')){
          wrap.attr('aria-hidden', 'false');
          if(!wrap.hasClass('picky-tables-wrap-second')){
             wrap.find('.picky-form-back').show();
          }
          pickySetBookingModalOpen(true);
        } else if (wrap.find('.picky-smart-booking-overlay').length) {
          wrap.find('.picky-smart-booking-overlay').attr('aria-hidden', 'false');
          wrap.find('.picky-form-back').show();
          pickySetBookingModalOpen(true);
        }
        
        thx.fadeIn(150);
        
        // Auto refresh after 6 seconds to update calendar
        window.pickyPaymentRefreshTimeout = setTimeout(function() {
          try {
            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.delete('picky_payment');
            currentUrl.searchParams.delete('picky_booking_id');
            window.location.href = currentUrl.toString();
          } catch(e) {
            window.location.href = window.location.pathname;
          }
        }, 6000);
      } else {
        // Show error notice
        wrap.find('.picky-payment-return-notice').remove();
        var cls = 'picky-payment-return-error';
        var notice = $('<div class="picky-payment-return-notice '+cls+'" role="status" aria-live="polite"></div>').text(msg);
        wrap.prepend(notice);
        notice.hide().fadeIn(150);
        
        var firstNotice = $('.picky-payment-return-notice').first();
        if(firstNotice.length){
          pickySmoothScrollTo(firstNotice, 20);
        }
      }
    });
  }

  // Map an HH:MM string to "minutes since midnight" for END-of-window times.
  // "00:00" / "24:00" become 1440 so 23:30 → 00:00 stays a valid one-day slot.
  // For start times keep the regular parser — start can never legitimately be 1440.
  function endTimeToMinutes(t){
    if(typeof t !== 'string' || t === ''){ return null; }
    var trimmed = t.replace(/^\s+|\s+$/g, '');
    if(trimmed === '00:00' || trimmed === '24:00'){ return 1440; }
    var m = trimmed.match(/^(\d{1,2}):(\d{1,2})$/);
    if(!m){ return null; }
    var h = parseInt(m[1], 10);
    var mm = parseInt(m[2], 10);
    if(isNaN(h) || isNaN(mm) || h < 0 || h > 23 || mm < 0 || mm > 59){ return null; }
    return (h * 60) + mm;
  }

  function pickyApplyMobileLayoutFlow(){
    if($(window).width() > 767){
      return;
    }
    $('.picky-tables-wrap.picky-tables-wrap-default, .picky-tables-wrap.picky-tables-wrap-second').each(function(){
      var $w = $(this);
      var hasCanvasMode = $w.hasClass('picky-mobile-canvas') || $w.hasClass('picky-mobile-dropdown');
      if(hasCanvasMode){
        return;
      }
      var $form = $w.find('.picky-form-booking').first();
      if(!$form || $form.length === 0){
        return;
      }
      $w.find('.picky-form-back').hide();
      $form.css({
        display: 'block',
        position: 'relative',
        top: '0',
        left: '0',
        transform: 'none',
        width: '100%',
        maxWidth: '100%',
        maxHeight: 'none',
        marginTop: '0',
        marginBottom: '0'
      });
    });
  }

  // Keep Classic DOM structurally aligned with Smart even on cached HTML.
  function pickyNormalizeClassicMarkup(){
    $('.picky-tables-wrap.picky-tables-wrap-default').each(function(){
      var $wrap = $(this);
      var $form = $wrap.find('form.picky-form-booking').first();
      if(!$form.length){ return; }

      // Unify form structural class so shared CSS targets both layouts.
      if(!$form.hasClass('picky-form-booking-second')){
        $form.addClass('picky-form-booking-second');
      }

      var $dp = $form.find('.picky-datepicker').first();
      if(!$dp.length){ return; }
      var $dateWrap = $dp.closest('.picky-input-wrap');
      if(!$dateWrap.length){ return; }

      if(!$dateWrap.hasClass('picky-input-wrap-datepicker')){
        $dateWrap.addClass('picky-input-wrap-datepicker');
      }

      // Wizard Classic datetime already has a flat structure — do NOT wrap in
      // .picky-tables-datapicker-wrap (that re-adds an outer border/padding).
      // If a previous page load already injected the wrap, unwrap it.
      if ($dateWrap.closest('.picky-classic-datetime, .picky-wizard-datetime').length) {
        var $block = $dateWrap.parent('.picky-tables-datapicker-block, .picky-tables-datapicker');
        if ($block.length) {
          var $outer = $block.parent('.picky-tables-datapicker-wrap');
          $dateWrap.unwrap(); // remove block
          if ($outer.length) {
            // After unwrap, dateWrap's parent is outer; unwrap outer too.
            $dateWrap.unwrap();
          }
        } else if ($dateWrap.parent('.picky-tables-datapicker-wrap').length) {
          $dateWrap.unwrap();
        }
        return;
      }

      // Build Smart-like wrappers only when missing.
      if($dateWrap.closest('.picky-tables-datapicker-wrap').length){
        return;
      }
      $dateWrap.wrap('<div class="picky-tables-datapicker picky-tables-datapicker-block"></div>');
      $dateWrap.parent().wrap('<div class="picky-tables-datapicker-wrap"></div>');
    });
  }

  pickyNormalizeClassicMarkup();
  pickyShowPaymentReturnNotice();

  function pickyPrefersSmoothScroll(){
    try {
      return !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return true;
    }
  }

  /** Toggle body class so theme sticky headers fall behind the booking overlay. */
  function pickySetBookingModalOpen(isOpen) {
    try {
      $('body').toggleClass('picky-booking-modal-open', !!isOpen);
    } catch (e) {}
  }

  /** Top padding for scroll targets when #wpadminbar is fixed/sticky (matches note scroll). */
  function pickyScrollPadWithAdminBar(base){
    var pad = typeof base === 'number' ? base : 20;
    try {
      var $ab = $('#wpadminbar');
      if($ab.length && $ab.is(':visible')){
        var pos = String($ab.css('position') || '').toLowerCase();
        if(pos === 'fixed' || pos === 'sticky'){
          pad += Math.round($ab.outerHeight() || 0);
        }
      }
    } catch (e) {}
    return pad;
  }

  function pickySmoothScrollTo($target, pad){
    if(!$target || !$target.length){ return; }
    var off = $target.offset();
    if(!off){ return; }
    var padPx = typeof pad === 'number' ? pad : 20;
    var top = Math.max(0, off.top - padPx);
    var smooth = pickyPrefersSmoothScroll() ? 'smooth' : 'auto';
    try {
      window.scrollTo({ top: top, left: 0, behavior: smooth });
    } catch (e) {
      $('html, body').animate({ scrollTop: top }, 380);
    }
  }

  /** Prefer scrolling inside the booking popup (.picky-form-booking) when it overflows; otherwise scroll the page. */
  function pickyScrollToTarget($target, pad){
    if(!$target || !$target.length){ return; }
    var padPx = typeof pad === 'number' ? pad : 20;
    var el = $target[0];
    var $panel = $target.closest('.picky-form-booking, .picky-form-booking-second, .picky-form-thx');
    if($panel.length){
      var panel = $panel[0];
      if(panel.scrollHeight > panel.clientHeight + 2){
        var cr = el.getBoundingClientRect();
        var pr = panel.getBoundingClientRect();
        var delta = cr.top - pr.top - padPx;
        var next = panel.scrollTop + delta;
        next = Math.max(0, Math.min(next, panel.scrollHeight - panel.clientHeight));
        var sm = pickyPrefersSmoothScroll() ? 'smooth' : 'auto';
        try {
          if(panel.scrollTo){
            panel.scrollTo({ top: next, left: 0, behavior: sm });
          } else {
            $panel.animate({ scrollTop: next }, 380);
          }
        } catch (e) {
          panel.scrollTop = next;
        }
        return;
      }
    }
    pickySmoothScrollTo($target, pad);
  }

  function pickySmartPickHintsClear(wrap){
    if(!wrap || !wrap.length){ return; }
    wrap.find('.picky-draggable .picky-element').removeClass('picky-smart-pick-hint picky-smart-pick-hint-animate');
  }

  function pickySmartAvailabilityNoteSet(wrap, shouldShow){
    if(!wrap || !wrap.length){ return; }
    var $note = wrap.find('.picky-smart-availability-note');
    if(!$note.length){ return; }
    var canShow = !!shouldShow && wrap.hasClass('picky-smart-table-picker-visible');
    if(canShow){
      $note.stop(true, true).fadeIn(120);
    } else {
      $note.stop(true, true).hide();
    }
  }

  function pickySmartPickHintsApply(wrap){
    if(!wrap || !wrap.length || !wrap.hasClass('picky-tables-wrap-second')){ return; }
    pickySmartPickHintsClear(wrap);
    var dt = wrap.find('#picky_booking_date').val();
    var tm = wrap.find('#picky_booking_time').val();
    if(!dt || !tm){
      pickySmartAvailabilityNoteSet(wrap, false);
      return;
    }
    // Only real bookable tables (same non-bookable set as admin / refreshAvailableTablesSecond).
    // Walls are skipped in refresh so they never get .disabled — must not pulse here.
    var hasAvailable = false;
    wrap.find('.picky-draggable .picky-element').each(function(){
      var $el = $(this);
      var t = ($el.attr('data-type') || '').toString();
      if(t === 'type-wall' || t === 'type-text' || t === 'type-shape' || t === 'type-icon'){ return; }
      if($el.hasClass('disabled')){ return; }
      if(!String($el.attr('data-unique') || '').trim()){ return; }
      hasAvailable = true;
    });
    pickySmartAvailabilityNoteSet(wrap, hasAvailable);
  }

  function pickySmartScrollToTime(wrap){
    if(!wrap || !wrap.length || !wrap.hasClass('picky-tables-wrap-second')){ return; }
    // Smart Map keeps date/time in a sticky bottom bar — no scroll needed.
    if(pickyIsSmartMapLayout(wrap)){ return; }
    var $t = wrap.find('.picky-wizard-datetime-slots, .picky-input-wrap-time').first();
    if(!$t.length || !$t.is(':visible')){ return; }
    var pad = pickyScrollPadWithAdminBar(20);
    try {
      var el = $t[0];
      var r = el.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight || 0;
      var margin = 16;
      if(r.top >= pad - 10 && r.bottom <= vh - margin){
        return;
      }
    } catch (e) {}
    pickyScrollToTarget($t, pad);
  }

  /** Match slot grid height to the calendar card beside it (Smart + Classic wizard). */
  function pickySmartSyncDatetimeHeights(wrap) {
    if (!wrap || !wrap.length) { return; }
    if (pickyIsSmartMapLayout(wrap)) { return; }
    var $dt = wrap.find('.picky-smart-datetime, .picky-classic-datetime').first();
    if (!$dt.length) { $dt = wrap.find('.picky-wizard .picky-wizard-datetime').first(); }
    if (!$dt.length) { return; }
    // Measure the visible calendar card (.picky-datepicker).
    var $cal = $dt.find('.picky-wizard-datetime-cal .picky-datepicker').first();
    if (!$cal.length) {
      $cal = $dt.find('.picky-wizard-datetime-cal > .picky-calendar-left, .picky-wizard-datetime-cal > .picky-input-wrap-datepicker').first();
    }
    var $grid = $dt.find('.picky-slot-grid[data-slot-role="start"]').first();
    if (!$cal.length || !$grid.length || !$grid.is(':visible')) { return; }
    if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches) {
      $grid.css({ height: '', 'max-height': '', 'overflow-y': '', 'overflow-x': '' });
      return;
    }
    var h = Math.round($cal.outerHeight() || 0);
    if (h < 80) { return; }
    // Exact calendar height — scroll inside the grid when slots overflow.
    $grid.css({
      'height': h + 'px',
      'max-height': h + 'px',
      'overflow-y': 'auto',
      'overflow-x': 'hidden'
    });
  }

  function pickySmartFocusStartTime(wrap){
    if(!wrap || !wrap.length){ return; }
    var $time = wrap.find('#picky_booking_time_fake');
    if(!$time.length){ return; }
    if($time.hasClass('picky-smart-time-native')){
      $time.trigger('focus');
      return;
    }
    // Select2 focus target for accessibility + keyboard open.
    var $sel2 = $time.next('.select2');
    if($sel2.length){
      var $focusEl = $sel2.find('.select2-selection').first();
      if($focusEl.length){
        $focusEl.trigger('focus');
        return;
      }
    }
    $time.trigger('focus');
  }

  /** Smart layout: use native <select> on mobile, Select2 on desktop for attached dropdown look. */
  function pickyIsSmartLayoutNativeTimeSelect(wrap, $sel){
    if(!wrap || !wrap.length || !$sel || !$sel.length){ return false; }
    if(!wrap.hasClass('picky-tables-wrap-second')){ return false; }
    var id = $sel.attr('id') || '';
    if(id !== 'picky_booking_time_fake' && id !== 'picky_booking_time_to'){
      return false;
    }
    // Smart Map dock: always native <select> — Select2 caret positioning is unreliable in the fixed bar.
    if(pickyIsSmartMapLayout(wrap)){
      return true;
    }
    var isMobileViewport = false;
    try {
      isMobileViewport = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
    } catch(e){}
    // Important: wrapper can keep "picky-mobile-*" classes from settings even on desktop.
    // Native select is desired only on real mobile viewport; desktop must use Select2.
    return !!isMobileViewport;
  }

  function pickyIsSmartMapLayout(wrap){
    return !!(wrap && wrap.length && wrap.hasClass('picky-tables-wrap-smart-map'));
  }

  function pickyIsOnceEvent(wrap){
    if(!wrap || !wrap.length){ return false; }
    if(String(wrap.attr('data-event-mode') || '') !== 'once'){ return false; }
    var dt = String(wrap.attr('data-event-date') || '').trim();
    var tm = String(wrap.attr('data-event-time') || '').trim();
    return !!(dt && tm);
  }

  /** One-time event: lock date/time from layout meta and skip the calendar. */
  function pickyApplyOnceEvent(wrap){
    if(!pickyIsOnceEvent(wrap)){ return false; }
    var dt = String(wrap.attr('data-event-date') || '').trim();
    var tm = String(wrap.attr('data-event-time') || '').trim();
    wrap.addClass('picky-event-once picky-smart-table-picker-visible');
    wrap.find('#picky_booking_date').val(dt);
    wrap.find('.picky-smart-map-date-input').val(dt);
    wrap.find('input#picky_booking_time').val(tm);
    var $fake = wrap.find('select#picky_booking_time_fake, select#picky_booking_time');
    if($fake.length){
      if(typeof pickyEnsureStartTimeOption === 'function'){
        pickyEnsureStartTimeOption(wrap, $fake.first(), tm);
      }
      $fake.val(tm);
    }
    if(typeof pickyLockSmartBookingTime === 'function'){
      pickyLockSmartBookingTime(wrap, tm);
    }
    wrap.find('.picky-chip-date').text(dt);
    wrap.find('.picky-chip-time').text(tm);
    wrap.find('.picky-booking-datetime-chip').addClass('picky-chip-visible');
    return true;
  }

  /** Smart Map: sticky booking bar that stays in view while scrolling, clamped to the canvas. */
  function pickyInitSmartMapDock(wrap){
    if(!pickyIsSmartMapLayout(wrap)){ return; }
    var $rel = wrap.find('> .picky-relative').first();
    if(!$rel.length){ return; }
    var $bar = $rel.find('.picky-smart-map-bar-wrap').first();
    if(!$bar.length){ return; }

    var $track = $rel.children('.picky-smart-map-sticky-track').first();
    if(!$track.length){
      $track = $rel.find('.picky-smart-map-sticky-track').first();
    }
    if(!$track.length){
      $track = $('<div class="picky-smart-map-sticky-track" aria-hidden="true"></div>');
    }
    if(!$track.parent().is($rel)){
      $rel.append($track);
    }

    var $dock = $track.children('.picky-smart-map-dock').first();
    if(!$dock.length){
      $dock = $rel.find('.picky-smart-map-dock').first();
    }
    if(!$dock.length){
      $dock = $('<div class="picky-smart-map-dock" aria-label="Booking controls"></div>');
    }
    if(!$dock.parent().is($track)){
      $track.append($dock);
    }
    if(!$bar.parent().hasClass('picky-smart-map-dock')){
      $dock.append($bar);
    }
    requestAnimationFrame(function(){
      requestAnimationFrame(function(){
        $dock.addClass('is-entered');
      });
    });
    // Time row is for calendar layouts only; one-time events keep hidden inputs.
    if(pickyIsOnceEvent(wrap)){
      wrap.find('.picky-smart-map-times, .picky-smart-map-field-date').css('display', 'none');
    } else {
      wrap.find('.picky-smart-map-times').css('display', 'flex');
    }
    wrap.find('#picky_booking_time_fake, #picky_booking_time_to').each(function(){
      var $sel = $(this);
      pickyStripSelect2Shell($sel);
      $sel.addClass('picky-smart-time-native picky-smart-map-select');
      pickySyncSmartTimeNativeEmptyClass($sel);
    });
  }

  /** Smart Map: enable Pay & Book only when date + time + ≥1 table are set. */
  function pickySyncSmartMapBookButton(wrap){
    if(!pickyIsSmartMapLayout(wrap)){ return; }
    var $btn = wrap.find('.picky-smart-map-actions .picky-selection-tray-book-btn').first();
    if(!$btn.length){ return; }
    var hasDate = !!(wrap.find('#picky_booking_date').val() || wrap.find('.picky-smart-map-date-input').val());
    var hasTime = !!(pickyGetSmartBookingTime(wrap) || wrap.find('#picky_booking_time_fake').val());
    var items = pickyGetSelectedItems(wrap);
    var ready = hasDate && hasTime && items.length > 0;
    $btn.prop('disabled', !ready).attr('aria-disabled', ready ? 'false' : 'true');
    wrap.find('.picky-smart-map-actions').toggleClass('is-ready', ready).toggleClass('has-selection', items.length > 0);
  }

  function pickyStripSelect2Shell($sel){
    if(!$sel || !$sel.length){ return; }
    try{
      if($sel.data('select2')){
        $sel.select2('destroy');
      }
    } catch (e0) {}
    try{
      $sel.next('.select2').remove();
    } catch (e1) {}
    $sel.removeClass('select2-hidden-accessible').removeAttr('data-select2-id').removeAttr('aria-hidden').removeAttr('tabindex');
  }

  function pickyBuildTimeSelect2Options($sel, placeholder){
    var opts = {
      placeholder: placeholder || '',
      allowClear: !!(placeholder),
      width: '100%',
      minimumResultsForSearch: -1
    };
    if(!$sel || !$sel.length){ return opts; }
    var sid = ($sel.attr('id') || '').toString();
    var isTimeSelect = sid === 'picky_booking_time_fake' || sid === 'picky_booking_time' || sid === 'picky_booking_time_to';
    var $wrap = $sel.closest('.picky-tables-wrap');
    // Smart Map dock is fixed — attach Select2 to body so the menu isn't clipped.
    if($wrap.hasClass('picky-tables-wrap-smart-map') && isTimeSelect){
      opts.dropdownParent = $(document.body);
      opts.allowClear = false;
      return opts;
    }
    var $parent = isTimeSelect
      ? $sel.closest('.picky-input-inner')
      : $sel.closest('.picky-form-booking, .picky-form-booking-second, .picky-form-booking-third, .picky-order-wrap, .picky-tables-wrap');
    if($parent.length){
      opts.dropdownParent = $parent.first();
    }
    return opts;
  }

  // Tag Smart Map time dropdowns (on body) so wizard-like styles apply; open above the dock.
  $(document).on('select2:open', function(e){
    var $sel = $(e.target);
    if(!$sel.closest('.picky-tables-wrap-smart-map').length){ return; }
    var id = ($sel.attr('id') || '').toString();
    if(id !== 'picky_booking_time_fake' && id !== 'picky_booking_time_to'){ return; }
    setTimeout(function(){
      var $container = $('.select2-container--open').last();
      var $dropdown = $container.find('.select2-dropdown').addClass('picky-smart-map-select2-dropdown');
      if(!$dropdown.length){ return; }
      // Force open above the fixed bottom dock.
      $container.removeClass('select2-container--below').addClass('select2-container--above');
      $dropdown.removeClass('select2-dropdown--below').addClass('select2-dropdown--above');
      var $field = $sel.closest('.picky-smart-map-field');
      var anchor = ($field.length ? $field[0] : $sel[0]);
      if(!anchor || !anchor.getBoundingClientRect){ return; }
      var br = anchor.getBoundingClientRect();
      var dh = $dropdown.outerHeight() || 200;
      var dw = Math.max($dropdown.outerWidth() || 0, br.width);
      var top = Math.round(br.top - dh - 8);
      if(top < 12){ top = Math.round(br.bottom + 8); }
      var left = Math.round(br.left);
      left = Math.max(12, Math.min(left, window.innerWidth - dw - 12));
      $dropdown.css({ position: 'fixed', top: top + 'px', left: left + 'px', width: dw + 'px' });
    }, 0);
  });

  function pickySyncSmartTimeNativeEmptyClass($sel){
    if(!$sel || !$sel.length || !$sel.hasClass('picky-smart-time-native')){ return; }
    $sel.toggleClass('picky-time-native-empty', !$sel.val());
  }

  /** After the user picks a time, bring the smart availability hint into view (above the canvas). */
  function pickySmartScrollToAvailabilityNote(wrap){
    if(!wrap || !wrap.length || !wrap.hasClass('picky-tables-wrap-second')){ return; }
    if(!wrap.hasClass('picky-smart-table-picker-visible')){ return; }
    var $note = wrap.find('.picky-smart-availability-note').first();
    if(!$note.length){ return; }
    // ~20px breathing room below the viewport top; add fixed #wpadminbar height when present.
    var pad = 20;
    try {
      var $ab = $('#wpadminbar');
      if($ab.length && $ab.is(':visible')){
        var pos = String($ab.css('position') || '').toLowerCase();
        if(pos === 'fixed' || pos === 'sticky'){
          pad += Math.round($ab.outerHeight() || 0);
        }
      }
    } catch (e) {}
    var padFinal = pad;
    // Wait for note fade (120ms) and AJAX "complete" applyCanvasScale; then 2x rAF
    // so offset() matches final layout. Do not call applyCanvasScale here — that reflows
    // right before scroll and fights native smooth scroll.
    setTimeout(function(){
      requestAnimationFrame(function(){
        requestAnimationFrame(function(){
          pickyScrollToTarget($note, padFinal);
        });
      });
    }, 200);
  }

  /** Smart: compact booking meta (table / datetime / max seats) below services. */
  function pickyClearSmartMobileCanvasTableSummary(wrap){
    if(!wrap || !wrap.length){ return; }
    var $meta = wrap.find('.picky-booking-meta-compact');
    if($meta.length){
      $meta.hide();
      $meta.find('.picky-table-summary-id').text('');
      $meta.find('.picky-table-summary-datetime').text('');
      $meta.find('.picky-table-summary-max').text('');
    }
    pickySetFreePeopleAmount(wrap, null);
  }

  function pickyGetSmartSelectionDateTimeText(wrap){
    if(!wrap || !wrap.length){ return ''; }
    var dt = wrap.find('#picky_booking_date').val() || '';
    var tm = wrap.find('#picky_booking_time').val() || '';
    var tmTo = wrap.find('#picky_booking_time_to').val() || '';
    if(!dt && !tm){ return ''; }
    if(dt && tm){
      if(tmTo){ return dt + ' ' + tm + ' - ' + tmTo; }
      return dt + ' ' + tm;
    }
    return dt || tm;
  }

  function pickySetSmartMobileCanvasTableSummary(wrap, $el){
    if(!wrap || !wrap.length || !$el || !$el.length){ return; }
    var $meta = wrap.find('.picky-booking-meta-compact');
    if(!$meta.length){ return; }
    var tid = $el.attr('data-id');
    if(tid === undefined || tid === null || tid === ''){ return; }
    var prefix = pickyTablePrefix;
    // Prefer the admin-set custom display ID (mirrors canvas badge + booking
    // title); fall back to the immutable data-id when no custom value is set.
    var displayTid = (typeof pickyItemDisplayId === 'function') ? pickyItemDisplayId($el) : tid;
    $meta.find('.picky-table-summary-id').text(prefix + String(displayTid || tid));
    var maxS = $el.attr('data-max-seats');
    var maxStr = '';
    var m = parseInt(maxS, 10);
    if(isNaN(m) || maxS === undefined || maxS === ''){
      maxStr = pickyEmDash;
    } else if(m <= 0){
      maxStr = (pickyI18n.unlimited_seats !== undefined && String(pickyI18n.unlimited_seats) !== '') ? String(pickyI18n.unlimited_seats) : pickyEmDash;
    } else {
      maxStr = String(m);
    }
    var dtText = pickyGetSmartSelectionDateTimeText(wrap);
    $meta.find('.picky-table-summary-datetime').text(dtText || pickyEmDash);
    $meta.find('.picky-table-summary-max').text(maxStr);
    $meta.show();
  }

  function pickySetFreePeopleAmount(wrap, freeSpots){
    if(!wrap || !wrap.length){ return; }
    var $txt = wrap.find('.picky-free-people-amount-text');
    var $val = wrap.find('.picky-free-people-amount');
    if(freeSpots === null || freeSpots === undefined || freeSpots === ''){
      $txt.hide();
      $val.hide().text('');
      return;
    }
    $val.text(String(freeSpots));
    $txt.show();
    $val.show();
  }

  // Show/hide the "table booked from HH:MM, your visit is limited" notice.
  function pickyShowTimeLimitNotice(wrap, bookableUntil){
    if(!wrap || !wrap.length){ return; }
    var $notice = wrap.find('.picky-time-limit-notice');
    if(!$notice.length){
      $notice = $('<div class="picky-time-limit-notice" role="alert"></div>');
      wrap.find('.picky-form-booking').prepend($notice);
    }
    if(!bookableUntil){
      $notice.hide().text('');
      return;
    }
    var msg = (pickyI18n && pickyI18n.time_limit_notice)
      ? pickyI18n.time_limit_notice.replace('%s', bookableUntil)
      : '⚠️ Note: this spot has a reservation starting at ' + bookableUntil + '. Your visit will be limited to that time.';
    $notice.text(msg).show();
  }

  // Look up the bookable_until value for the currently selected table.
  function pickyGetBookableUntilForSelected(wrap){
    if(!wrap || !wrap.length){ return null; }
    var unique = wrap.find('.picky-form-booking').find('#picky_booking_unique').val() || '';
    if(!unique){ return null; }
    var map = wrap.data('pickyBookableUntilByUnique') || {};
    return map[String(unique)] || null;
  }

  function pickyUpdateSelectedCapacityInfo(wrap, capacityByUnique){
    if(!wrap || !wrap.length){ return; }
    var unique = wrap.find('.picky-form-booking').find('#picky_booking_unique').val() || '';
    if(!unique){
      pickySetFreePeopleAmount(wrap, null);
      return;
    }
    var capacityMap = capacityByUnique || wrap.data('pickyCapacityByUnique') || {};
    var info = capacityMap[String(unique)];
    if(info && info.free_spots !== undefined){
      pickySetFreePeopleAmount(wrap, info.free_spots);
    } else {
      pickySetFreePeopleAmount(wrap, null);
    }
  }

  function pickyFetchSelectedCapacityInfo(wrap){
    if(!wrap || !wrap.length){ return; }
    var unique = wrap.find('.picky-form-booking').find('#picky_booking_unique').val() || '';
    var dt = wrap.find('#picky_booking_date').val();
    var tm = wrap.find('#picky_booking_time').val();
    if(!unique || !dt || !tm){
      pickySetFreePeopleAmount(wrap, null);
      return;
    }
    var ppl = parseInt(wrap.find('#picky_booking_people').val(), 10);
    if(isNaN(ppl) || ppl <= 0){ ppl = 1; }
    $.ajax({
      type: 'POST',
      url: (window.pickyAjax && window.pickyAjax.url) ? window.pickyAjax.url : '',
      dataType: 'json',
      data: {
        action: 'picky_get_available_tables',
        _wpnonce: wrap.find('input[name="_wpnonce"]').val(),
        picky_layout_id: wrap.find('input[name="picky_layout_id"]').val() || '',
        date: dt,
        time: tm,
        time_to: wrap.find('#picky_booking_time_to').val() || '',
        people: ppl
      },
      success: function(resp){
        var tables     = (resp && resp.success && resp.data && resp.data.tables) ? resp.data.tables : [];
        var capacityMap = (resp && resp.success && resp.data && resp.data.capacity) ? resp.data.capacity : {};
        wrap.data('pickyCapacityByUnique', capacityMap);
        if(resp && resp.success && resp.data && Array.isArray(resp.data.occupants)){
          wrap.data('pickyOccupants', resp.data.occupants);
        }
        // Build bookable_until map from the availability response.
        var buMap = {};
        for(var i = 0; i < tables.length; i++){
          if(tables[i] && tables[i].unique && tables[i].bookable_until){
            buMap[String(tables[i].unique)] = tables[i].bookable_until;
          }
        }
        wrap.data('pickyBookableUntilByUnique', buMap);
        pickyUpdateSelectedCapacityInfo(wrap, capacityMap);
        pickyShowTimeLimitNotice(wrap, pickyGetBookableUntilForSelected(wrap));
      }
    });
  }

  var datapicker = $('.picky-datepicker');

  function refreshCoordinates(){
    coordinates = new Array();
    elements = [];
    $('.picky-draggable .picky-element').each(function(e){
      elements.push($(this));
    });
    for (var k = 0; k <= elements.length - 1; k++) {
      coordinates[k] = {
        x: $(elements[k]).offset().left,
        y: $(elements[k]).offset().top,
        x1: $(elements[k]).offset().left + $(elements[k])[0].clientWidth,
        y1: $(elements[k]).offset().top + $(elements[k])[0].clientHeight
      }
    }
    return coordinates;
  }

  function addCSStoDraw(q,element, rotate = 1){
    var way_string = "";
    var second_lap = 0;
    var iter = 1;
    var second_lap_start = 0;
    var third_lap_start = 0;
    var el_table = element.find('.picky-element-table');
    var table_w = el_table.outerWidth(true);
    var table_h = el_table.outerHeight(true);
    var seat_size = 30;
    if(table_w > table_h){
      seat_size = Math.round(table_h/6 * 2); 
    } else {
      seat_size = Math.round(table_w/6 * 2); 
    }
  
    
    var $sizeWrap = element.closest('.picky-tables-wrap');
    if($sizeWrap.hasClass('picky-tables-size-small')){
      seat_size = Math.round(seat_size*0.8);
    } else if($sizeWrap.hasClass('picky-tables-size-big')){
      seat_size = Math.round(seat_size*1.2);
    }

    var closest_margin = 2;
    var max = parseInt(element.attr('data-max-seats'));
    var first_seats = 0;
    var el_type = element.attr('data-type');
    first_seats = max - 4;

    if(q > 4){
      second_lap = q - 4;
    } 

    var has_second = 0;
    var has_third = 0;

    var w_first = 0;
    var h_first = 0;
    
    if(element.attr('data-big-side') == 'h'){
      h_first = 1;
    }

    var numbers_array = [];
    var all_seats = element.find('.picky-element-seats-wrap .picky-element-seat').length;
    element.find('.picky-element-seats-wrap .picky-element-seat').each(function(seat){ 
      rotate = parseInt(rotate); // default - 1
      seat = parseInt(seat); // first - 0
      var number = parseInt(seat + rotate ); // because of rotate default = 1, seat != seat+1
      if(h_first == 1){ // if height is bigger then width
        number = number - 1;
      }
        
      // if number is > then 4 or less then 1
      if(number > 4){  
        number = number - 4;
        if(number > 4){
          number = number - 4;
          if(number > 4){
            number = number - 4;
          } 
        } 
      } else if(number <= 0){
        number = 4;
      }

      if(el_type != 'type-4'){// not circle
        // this is to make 1 3 1 3 2 4 and not 1 2 3 4 1 2

        if(all_seats > 8){ // 3 seats on one side

          if(parseInt(seat+1) <= 6){
            var new_number = number + seat;
            if(new_number > 4){
              new_number = new_number - 4;
              if(new_number > 4){
                new_number = new_number - 4;
                if(new_number > 4){
                  new_number = new_number - 4;
                }
              }
            }
          } else {
            var new_number = number + seat + 1;
            if(new_number > 4){
              new_number = new_number - 4;
              if(new_number > 4){
                new_number = new_number - 4;
                if(new_number > 4){
                  new_number = new_number - 4;
                }
              }
            }
          }

        } else if(all_seats <= 8) { // 2 seats on one side

          if(parseInt(seat+1) <= 4){
            var new_number = number + seat;
            if(new_number > 4){
              new_number = new_number - 4;
              if(new_number > 4){
                new_number = new_number - 4;
              }
            }
          } else {
            var new_number = number + seat + 1;
            if(new_number > 4){
              new_number = new_number - 4;
              if(new_number > 4){
                new_number = new_number - 4;
              }
            }
          }

        }

        number = new_number;

      }

      numbers_array.push(number);

    });

    element.find('.picky-element-seats-wrap .picky-element-seat').each(function(seat){
      
      rotate = parseInt(rotate); // default - 1
      seat = parseInt(seat); // first - 0
      var number = parseInt(seat  + rotate ); // because of rotate default = 1, seat != seat+1
      if(h_first == 1){ // if height is bigger then width
        number = number - 1;
      } 

      // if number is > then 4 or less then 1
      if(number > 4){  
        number = number - 4;
        if(number > 4){
          number = number - 4;
          if(number > 4){
            number = number - 4;
          } 
        } 
      } else if(number <= 0){
        number = 4;
      }

      if(el_type != 'type-4'){// not circle
        // this is to make 1 3 1 3 2 4 and not 1 2 3 4 1 2

        if(all_seats > 8){ // 3 seats on one side

          if(parseInt(seat+1) <= 6){
            var new_number = number + seat;
            if(new_number > 4){
              new_number = new_number - 4;
              if(new_number > 4){
                new_number = new_number - 4;
                if(new_number > 4){
                  new_number = new_number - 4;
                }
              }
            }
          } else {
            var new_number = number + seat + 1;
            if(new_number > 4){
              new_number = new_number - 4;
              if(new_number > 4){
                new_number = new_number - 4;
                if(new_number > 4){
                  new_number = new_number - 4;
                }
              }
            }
          }


        } else if(all_seats <= 8) { // 2 seats on one side

          if(parseInt(seat+1) <= 4){
            var new_number = number + seat;
            if(new_number > 4){
              new_number = new_number - 4;
              if(new_number > 4){
                new_number = new_number - 4;
              }
            }
          } else {
            var new_number = number + seat + 1;
            if(new_number > 4){
              new_number = new_number - 4;
              if(new_number > 4){
                new_number = new_number - 4;
              }
            }
          }

        }

        number = new_number;

      }

      var more_two = 0;
      var not_two = 0;
      var is_two = [];
      // if more than two elements in array of all or push two if two or more
      for (var i = numbers_array.length - 1; i >= 0; i--) {
        if(numbers_array[i] == number){
          more_two++;
          not_two = i+1;
          is_two.push(i+1);
        }
      }
      if(more_two < 2){
        
      } else {
        not_two = 0;
      }

      Array.prototype.remove = function() {
          var what, a = arguments, L = a.length, ax;
          while (L && this.length) {
              what = a[--L];
              while ((ax = this.indexOf(what)) !== -1) {
                  this.splice(ax, 1);
              }
          }
          return this;
      };

      second_lap_start = 0;// second lap of draw for "has_second"
      third_lap_start = 0;// third lap of draw for "has_third"
      has_second = 1; // element has second as pair
      has_third = 1; // element has third as pair
      if(not_two > 0){ // ony one elment/no pair
        if(parseInt(seat+1) == not_two){
          has_second = 0;
        } 
      } else { // has pair/check lap
        if(is_two.length == 3){ // if more then 8 elements
          has_third = 1;
          var max = Math.max.apply(null, is_two);
          var new_is_to = is_two;
          new_is_to.remove(max);
          var mid = Math.max.apply(null, new_is_to);
          if(max == seat+1){
            third_lap_start = 1;
          } else if(mid == seat+1){
            second_lap_start = 1;
          }
        } else if(is_two.length == 2){ // // if 8 elements or less
          has_third = 0;
          var max = Math.max.apply(null, is_two);
          if(max == seat+1){
            second_lap_start = 1;
          } else {
            second_lap_start = 0;
          }
        }
        
      }

      if(number == 1){ // FIRST
        way_string = "top";
        $(this).css('width', seat_size);
        $(this).css('height', '');
        if(has_second == 1 && has_third == 0){ // two seats on the way
          if(second_lap_start == 1){
            var left_second = (el_table.outerWidth(true)/2) + (((el_table.outerWidth(true)/2) - $(this).outerWidth(true))/2) - closest_margin;
            $(this).css('left', left_second);
            $(this).css('top', -$(this).outerHeight(true));
          } else {
            var left = (el_table.outerWidth(true)/2) - $(this).outerWidth(true) - (((el_table.outerWidth(true)/2) - $(this).outerWidth(true))/2) + closest_margin;
            $(this).css('left', left);
            $(this).css('top', -$(this).outerHeight(true));
          }
        } else if(has_second == 1 && has_third == 1){ // THIRD HAS
          if(second_lap_start == 1 && third_lap_start == 0){
            var left_second = (el_table.outerWidth(true)/2) - (($(this).outerWidth(true))/2) - closest_margin;
            $(this).css('left', left_second);
            $(this).css('top', -$(this).outerHeight(true));
          } else if(third_lap_start == 1 && second_lap_start == 0){
            var left_third = (el_table.outerWidth(true)/3) + (el_table.outerWidth(true)/3) + ( (el_table.outerWidth(true)/3) - $(this).outerWidth(true) )/2 - closest_margin;
            $(this).css('left', left_third);
            $(this).css('top', -$(this).outerHeight(true));
          } else { 
            var left = (el_table.outerWidth(true)/3) - $(this).outerWidth(true) - (((el_table.outerWidth(true)/3) - $(this).outerWidth(true))/2) + closest_margin;
            $(this).css('left', left);
            $(this).css('top', -$(this).outerHeight(true));
          }
        } else { // one seat
          var left_one_seat = (el_table.outerWidth(true)/2) - ($(this).outerWidth(true)/2);
          $(this).css('left', left_one_seat);
          $(this).css('top', -$(this).outerHeight(true));
        }
      } else if(number == 2){ // 
        way_string = "right";
        $(this).css('height', seat_size);
        $(this).css('width', '');
        if(has_second == 1 && has_third == 0){ // two seats on the way
          if(second_lap_start == 1){
            var top_second = (el_table.outerHeight(true)/2) + (((el_table.outerHeight(true)/2) - $(this).outerHeight(true))/2) - closest_margin;
            $(this).css('left', 'calc(100%)');
            $(this).css('top', top_second);
          } else {
            var top = (el_table.outerHeight(true)/2) - $(this).outerHeight(true) - (((el_table.outerHeight(true)/2) - $(this).outerHeight(true))/2) + closest_margin;
            $(this).css('left', 'calc(100%)');
            $(this).css('top', top);
          }
        } else if(has_second == 1 && has_third == 1){ // THIRD HAS
          if(second_lap_start == 1 && third_lap_start == 0){
            var top_second = (el_table.outerHeight(true)/2) - (($(this).outerHeight(true))/2) ;
            $(this).css('left', 'calc(100%)');
            $(this).css('top', top_second);
          } else if(second_lap_start == 0 && third_lap_start == 1){
            var top_third = (el_table.outerHeight(true)/3) + (el_table.outerHeight(true)/3) + ( (el_table.outerHeight(true)/3) - $(this).outerHeight(true) )/2 - closest_margin;
            $(this).css('left', 'calc(100%)');
            $(this).css('top', top_third);
          } else {
            var top = (el_table.outerHeight(true)/3) - $(this).outerHeight(true) - (((el_table.outerHeight(true)/3) - $(this).outerHeight(true))/2) + closest_margin;
            $(this).css('left', 'calc(100%)');
            $(this).css('top', top);
          }
        } else { // one seat
          var top_one_seat = (el_table.outerHeight(true)/2) - ($(this).outerHeight(true)/2);
          $(this).css('left', 'calc(100%)');
          $(this).css('top', top_one_seat);
        }
      } else if(number == 3){ // 
        way_string = "bottom";
        $(this).css('width', seat_size);
        $(this).css('height', '');
        if(has_second == 1 && has_third == 0){ // two seats on the way
          if(second_lap_start == 1){
            var left_second = (el_table.outerWidth(true)/2) + (((el_table.outerWidth(true)/2) - $(this).outerWidth(true))/2) - closest_margin;
            $(this).css('left', left_second);
            $(this).css('top', 'calc(100%)');
          } else {
            var left = (el_table.outerWidth(true)/2) - $(this).outerWidth(true) - (((el_table.outerWidth(true)/2) - $(this).outerWidth(true))/2) + closest_margin;
            $(this).css('left', left);
            $(this).css('top', 'calc(100%)');
          }
        } else if(has_second == 1 && has_third == 1){ // THIRD HAS
          if(second_lap_start == 1 && third_lap_start == 0){
            var left_second = (el_table.outerWidth(true)/2) - (($(this).outerWidth(true))/2) - closest_margin;
            $(this).css('left', left_second);
            $(this).css('top', 'calc(100%)');
          } else if(third_lap_start == 1 && second_lap_start == 0){
            var left_third = (el_table.outerWidth(true)/3) + (el_table.outerWidth(true)/3) + ( (el_table.outerWidth(true)/3) - $(this).outerWidth(true) )/2 - closest_margin;
            $(this).css('left', left_third);
            $(this).css('top', 'calc(100%)');
          } else { 
            var left = (el_table.outerWidth(true)/3) - $(this).outerWidth(true) - (((el_table.outerWidth(true)/3) - $(this).outerWidth(true))/2) + closest_margin;
            $(this).css('left', left);
            $(this).css('top', 'calc(100%)');
          }
        } else { // one seat
          var left_one_seat = (el_table.outerWidth(true)/2) - ($(this).outerWidth(true)/2);
          $(this).css('left', left_one_seat);
          $(this).css('top', 'calc(100%)');
        }
      } else if(number == 4){
        way_string = "left";
        $(this).css('height', seat_size);
        $(this).css('width', '');
        if(has_second == 1 && has_third == 0){ // two seats on the way
          if(second_lap_start == 1){
            var top_second = (el_table.outerHeight(true)/2) + (((el_table.outerHeight(true)/2) - $(this).outerHeight(true))/2) - closest_margin;
            $(this).css('left', -$(this).outerWidth(true));
            $(this).css('top', top_second);
          } else {
            var top = (el_table.outerHeight(true)/2) - $(this).outerHeight(true) - (((el_table.outerHeight(true)/2) - $(this).outerHeight(true))/2) + closest_margin;
            $(this).css('left', -$(this).outerWidth(true));
            $(this).css('top', top);
          }
        } else if(has_second == 1 && has_third == 1){ // THIRD HAS
          if(second_lap_start == 1 && third_lap_start == 0){
            var top_second = (el_table.outerHeight(true)/2) - (($(this).outerHeight(true))/2) ;
            $(this).css('left', -$(this).outerWidth(true));
            $(this).css('top', top_second);
          } else if(second_lap_start == 0 && third_lap_start == 1){
            var top_third = (el_table.outerHeight(true)/3) + (el_table.outerHeight(true)/3) + ( (el_table.outerHeight(true)/3) - $(this).outerHeight(true) )/2 - closest_margin;
            $(this).css('left', -$(this).outerWidth(true));
            $(this).css('top', top_third);
          } else {
            var top = (el_table.outerHeight(true)/3) - $(this).outerHeight(true) - (((el_table.outerHeight(true)/3) - $(this).outerHeight(true))/2) + closest_margin;
            $(this).css('left', -$(this).outerWidth(true));
            $(this).css('top', top);
          }
        } else { // one seat
          var top_one_seat = (el_table.outerHeight(true)/2) - ($(this).outerHeight(true)/2);
          $(this).css('top', top_one_seat);
          $(this).css('left', -$(this).outerWidth(true));
        }
      }

      iter++;

    }).promise().done(function(){ 

    });
  }
  
  function pickyNormalizeTableRotateDomFrontend($root){
    if(!$root || !$root.length){ return; }
    var t = ($root.attr('data-type') || '').toString();
    if(t === 'type-wall' || t === 'type-text'){ return; }
    var $table = $root.find('.picky-element-table').first();
    if(!$table.length){ return; }
    $root.find('.picky-rotate-handle').each(function(){
      var $h = $(this);
      if(!$h.closest('.picky-element-table').length){
        $h.appendTo($table);
      }
    });
    $root.find('.picky-3d-pad').each(function(){
      var $p = $(this);
      if(!$p.closest('.picky-element-table').length){
        $p.appendTo($table);
      }
    });
  }

  function pickyMountBookableAvatarOnShape($el){
    if(!$el || !$el.length){ return; }
    var elType = ($el.attr('data-type') || '').toString();
    if(elType === 'type-wall' || elType === 'type-text' || elType === 'type-shape' || elType === 'type-icon'){ return; }
    var $avatar = $el.find('.picky-element-avatar').first();
    var $shape = $el.find('.picky-element-shape').first();
    if(!$avatar.length || !$shape.length){ return; }
    if($avatar.parent()[0] !== $shape[0]){
      $avatar.appendTo($shape);
    }
    /* Legacy: overlay-mounted avatars used duplicate transforms. */
    $avatar.css({ transform: '', transformOrigin: '' });
    if($avatar[0] && $avatar[0].style){
      $avatar[0].style.removeProperty('transform-style');
    }
  }

  function applyTransformsFrontend(element){
    var $el = $(element).closest('.picky-element');
    if(!$el.length){ $el = $(element); }
    pickyNormalizeTableRotateDomFrontend($el);
    var z = parseInt($el.attr('data-rotate-z') || '0', 10); if(isNaN(z)) z = 0;
    var rx = parseInt($el.attr('data-rotate-x') || '0', 10); if(isNaN(rx)) rx = 0;
    var ry = parseInt($el.attr('data-rotate-y') || '0', 10); if(isNaN(ry)) ry = 0;

    $el[0].style.removeProperty('transform');
    $el[0].style.removeProperty('transform-origin');
    var $table = $el.find('.picky-element-table').first();
    var $tw = $el.find('.picky-element-table-wrap').first();
    var $overlay = $el.find('.picky-element-overlay').first();

    if($el.hasClass('type-text') && !$el.hasClass('type-wall') && $tw.length){
      $tw.css({
        transform: 'rotateZ('+z+'deg)',
        transformOrigin: 'center center'
      });
      if($table.length){
        $table.css({ transform: 'none', transformOrigin: '' });
      }
      if($overlay.length){
        // Match builder: do not counter-rotate the full overlay for text.
        $overlay.css({ transform: '', transformOrigin: '' });
      }
    } else if($el.hasClass('type-wall') && $tw.length){
      /* Walls: free rotateZ on wrap (same as builder). */
      $tw.css({
        transform: 'rotateZ('+z+'deg)',
        transformOrigin: 'center center'
      });
      if($table.length){
        $table.css({ transform: 'none', transformOrigin: '' });
      }
      if($overlay.length){
        $overlay.css({ transform: '', transformOrigin: '' });
      }
    } else {
      if($tw.length){
        $tw.css({ transform: '', transformOrigin: '' });
      }
      if($overlay.length){
        $overlay.css({ transform: '', transformOrigin: '' });
      }
      if($table.length){
        $table.css({
          transform: 'rotateZ('+z+'deg)',
          transformOrigin: 'center center'
        });
      }
    }
    var $shapeFe = $el.find('.picky-element-shape');
    var shapeCssFe = {
      transform: 'perspective(600px) rotateX('+rx+'deg) rotateY('+ry+'deg)'
    };
    if($el.hasClass('type-shape')){
      var shapeTypeFe = ($el.attr('data-shape-type') || 'rectangle').toString();
      var radRawFe = ($el.attr('data-shape-radius') || '').toString().trim();
      var radFe = parseInt(radRawFe, 10);
      if($shapeFe.length && $shapeFe[0] && $shapeFe[0].style && typeof $shapeFe[0].style.setProperty === 'function'){
        if(shapeTypeFe === 'circle'){
          $shapeFe[0].style.setProperty('border-radius', '50%', 'important');
        } else if(shapeTypeFe === 'triangle'){
          $shapeFe[0].style.setProperty('border-radius', '0', 'important');
        } else if(radRawFe !== '' && !isNaN(radFe) && radFe >= 0){
          if(radFe > 120){ radFe = 120; }
          $shapeFe[0].style.setProperty('border-radius', radFe + 'px', 'important');
        } else if(shapeTypeFe === 'rounded'){
          $shapeFe[0].style.setProperty('border-radius', '15px', 'important');
        } else if(shapeTypeFe === 'rectangle'){
          $shapeFe[0].style.setProperty('border-radius', '0', 'important');
        } else {
          $shapeFe[0].style.removeProperty('border-radius');
        }
        delete shapeCssFe.borderRadius;
      } else if(shapeTypeFe === 'circle'){
        shapeCssFe.borderRadius = '50%';
      } else if(radRawFe !== '' && !isNaN(radFe) && radFe >= 0){
        if(radFe > 120){ radFe = 120; }
        shapeCssFe.borderRadius = radFe + 'px';
      } else {
        shapeCssFe.borderRadius = '';
      }
      var bwFe = parseInt($el.attr('data-shape-border-width') || '0', 10);
      if(isNaN(bwFe) || bwFe < 0){ bwFe = 0; }
      if(bwFe > 0){
        var bcFe = ($el.attr('data-shape-border-color') || '#333333').toString();
        shapeCssFe.border = bwFe + 'px solid ' + bcFe;
        shapeCssFe.boxSizing = 'border-box';
      } else {
        shapeCssFe.border = '';
        shapeCssFe.boxSizing = '';
      }
    } else if($el.hasClass('type-wall')){
      // Empty = CSS default (2px). Explicit 0 = sharp. Beat theme border-radius:0.
      var wrRawFe = ($el.attr('data-wall-radius') || '').toString().trim();
      var wrFe = parseInt(wrRawFe, 10);
      var wrPx = '2px';
      if(wrRawFe !== '' && !isNaN(wrFe) && wrFe >= 0){
        if(wrFe > 120){ wrFe = 120; }
        wrPx = wrFe + 'px';
      }
      if($shapeFe.length && $shapeFe[0] && $shapeFe[0].style && typeof $shapeFe[0].style.setProperty === 'function'){
        $shapeFe[0].style.setProperty('--picky-wall-radius', wrPx);
        $shapeFe[0].style.setProperty('border-radius', wrPx, 'important');
        delete shapeCssFe.borderRadius;
      } else {
        shapeCssFe.borderRadius = wrPx;
      }
    } else if($el.hasClass('type-4') || $el.hasClass('type-circle')){
      if($shapeFe.length && $shapeFe[0] && $shapeFe[0].style && typeof $shapeFe[0].style.setProperty === 'function'){
        $shapeFe[0].style.setProperty('border-radius', '50%', 'important');
        delete shapeCssFe.borderRadius;
      } else {
        shapeCssFe.borderRadius = '50%';
      }
    } else if(pickyUsesBookableSquareRadiusFe($el)){
      var sqRawFe = ($el.attr('data-square-radius') || '').toString().trim();
      var sqFe = parseInt(sqRawFe, 10);
      // Empty or 0 = CSS default (8px). Only positive overrides.
      // Use !important so landing themes that force border-radius:0 don't flatten seats.
      if($shapeFe.length && $shapeFe[0] && $shapeFe[0].style && typeof $shapeFe[0].style.setProperty === 'function'){
        if(sqRawFe !== '' && !isNaN(sqFe) && sqFe > 0){
          if(sqFe > 120){ sqFe = 120; }
          $shapeFe[0].style.setProperty('border-radius', sqFe + 'px', 'important');
        } else {
          $shapeFe[0].style.setProperty('border-radius', '8px', 'important');
        }
        // Skip jQuery .css merge for borderRadius — already applied above.
        delete shapeCssFe.borderRadius;
      } else if(sqRawFe !== '' && !isNaN(sqFe) && sqFe > 0){
        if(sqFe > 120){ sqFe = 120; }
        shapeCssFe.borderRadius = sqFe + 'px';
      } else {
        shapeCssFe.borderRadius = '';
      }
    }
    $shapeFe.css(shapeCssFe);
    var shapeRadiusFe = $shapeFe.css('border-radius');
    if($shapeFe.length && $shapeFe[0] && $shapeFe[0].style){
      if(shapeRadiusFe && shapeRadiusFe !== 'none'){
        $shapeFe[0].style.setProperty('--picky-avatar-radius', shapeRadiusFe);
      } else {
        $shapeFe[0].style.removeProperty('--picky-avatar-radius');
      }
    }
    // Let CSS own badge transforms (hover slide). Inline styles fight that and
    // animate on load because badges have transition: transform.
    $el.find('.picky-element-id, .picky-element-seats').each(function(){
      this.style.removeProperty('transform');
    });
    $el.find('.picky-element-label').css('transform', '');
    $el.find('.picky-rotate-handle, .picky-3d-pad').css('transform', '');
    pickyMountBookableAvatarOnShape($el);
  }
  
  function layoutResize(){
    // NOTE: legacy layoutResize used to rescale font-sizes AND recompute element left/top.
    // In the new builder, coordinates are stored in px and must render 1:1 like admin.
    // Canvas scaling (mobile) is handled separately via transform scale().
    var wrap = $('.picky-tables-wrap-all'); // keep for loading class removal below

    setTimeout(function() {
      $('.picky-element').each(function(){
        var element = $(this);
        var rotate = element.attr('data-rotate');
        var q = element.find('.picky-element-seats-wrap .picky-element-seat').length;
        addCSStoDraw(q,element, rotate);
        applyTransformsFrontend(element);
      });
      // Scale while loading overlay is still on — clearing transform after reveal flashes full size.
      try { applyCanvasScaleToContainer(); } catch(e) {}
      wrap.find('.picky-draggable').removeClass('picky-loading');
      // Enable hover scale; seats are already visible (no opacity FOUC).
      requestAnimationFrame(function(){
        pickyStartBookableEnterAnimations();
      });
    }, 0);
  }

  function pickyBookableItems($wrap){
    return $wrap.find('.picky-draggable .picky-element').filter(function(){
      var $e = $(this);
      return !$e.hasClass('type-text') && !$e.hasClass('type-wall') && !$e.hasClass('type-shape') && !$e.hasClass('type-icon');
    });
  }

  function pickyBookableCanvasTop(el){
    var t = parseFloat(el.style.top);
    if(!isNaN(t)){ return t; }
    try {
      t = parseFloat(window.getComputedStyle(el).top);
      if(!isNaN(t)){ return t; }
    } catch (err) { /* ignore */ }
    return el.offsetTop || 0;
  }

  function pickyBookableCanvasLeft(el){
    var l = parseFloat(el.style.left);
    if(!isNaN(l)){ return l; }
    try {
      l = parseFloat(window.getComputedStyle(el).left);
      if(!isNaN(l)){ return l; }
    } catch (err) { /* ignore */ }
    return el.offsetLeft || 0;
  }

  function pickyStartBookableEnterAnimations(){
    var reduceMotion = false;
    try {
      reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (err) { reduceMotion = false; }

    $('.picky-tables-wrap').each(function(){
      var $wrap = $(this);
      $wrap.addClass('picky-map-ready');
      $wrap.removeClass('picky-items-enter picky-items-live');

      var $items = pickyBookableItems($wrap);
      $items.removeClass('picky-item-pop');
      $items.each(function(){
        this.style.removeProperty('--picky-enter-delay');
      });

      var animOff = $wrap.hasClass('picky-no-bookable-anim')
        || (window.pickyAjax && pickyAjax.animateBookableItems === false);

      if(!$items.length || reduceMotion || animOff){
        if(animOff){ $wrap.addClass('picky-no-bookable-anim'); }
        $wrap.addClass('picky-items-live');
        return;
      }

      var sorted = $items.get().sort(function(a, b){
        var dy = pickyBookableCanvasTop(a) - pickyBookableCanvasTop(b);
        if(Math.abs(dy) > 6){ return dy; }
        return pickyBookableCanvasLeft(a) - pickyBookableCanvasLeft(b);
      });

      var staggerBudgetMs = 1000;
      var animMs = 560;
      var lastIdx = Math.max(0, sorted.length - 1);
      var stepMs = lastIdx > 0 ? (staggerBudgetMs / lastIdx) : 0;

      $(sorted).each(function(i){
        this.style.setProperty('--picky-enter-delay', Math.round(i * stepMs) + 'ms');
        $(this).addClass('picky-item-pop');
      });
      $wrap.addClass('picky-items-enter');

      if($wrap.data('pickyEnterTimer')){
        window.clearTimeout($wrap.data('pickyEnterTimer'));
      }
      var maxDelay = stepMs * lastIdx;
      var timer = window.setTimeout(function(){
        $wrap.removeClass('picky-items-enter').addClass('picky-items-live');
        $items.removeClass('picky-item-pop');
        $items.each(function(){
          this.style.removeProperty('--picky-enter-delay');
        });
        $wrap.removeData('pickyEnterTimer');
      }, Math.round(maxDelay) + animMs + 40);
      $wrap.data('pickyEnterTimer', timer);
    });
  }

  var exclude_data = [];

  $(document).ready(function () {

    var wrap = $('.picky-tables-wrap');

    pickyApplyMobileLayoutFlow();
    
    layoutResize();

    // Scale canvas to fit container width (desktop + mobile).
    // IMPORTANT: element positions/sizes are in px, so we must scale via transform
    // (font-size scaling would NOT scale left/top px).
    applyCanvasScaleToContainer = function(){
      $('.picky-tables-wrap').each(function(){
        var $wrap = $(this);
        var $draggable = $wrap.find('.picky-draggable').first();
        var $wrapInner = $wrap.find('.picky-draggable-wrap').first();
        if($draggable.length === 0 || $wrapInner.length === 0){
          return;
        }

        // Authoritative design size from admin (px). Do NOT measure outerWidth for base —
        // em/font-size or a prior scale can make measured size wrong and desync seats from bg.
        var designW = parseInt($draggable.attr('data-width'), 10);
        var designH = parseInt($draggable.attr('data-height'), 10);
        if(isNaN(designW) || designW <= 0){ designW = 0; }
        if(isNaN(designH) || designH <= 0){ designH = 0; }

        if(designW > 0){
          var sizeCss = { width: designW + 'px' };
          if(designH > 0){ sizeCss.height = designH + 'px'; }
          $draggable.css(sizeCss);
        }

        var baseW = designW > 0 ? designW : $draggable.outerWidth();
        var baseH = designH > 0 ? designH : $draggable.outerHeight();
        if(!baseW || baseW <= 0){ baseW = 0; }
        if(!baseH || baseH <= 0){ baseH = 750; }

        var containerW = $wrapInner.width();
        if(!containerW || containerW <= 0){
          containerW = $wrap.width();
        }
        if(!containerW || containerW <= 0 || !baseW || baseW <= 0){
          $wrapInner.css({'height': '', 'max-width': '', 'margin-left': '', 'margin-right': ''});
          return;
        }

        // Width-fit: scale down whenever the canvas is wider than the container (any viewport).
        // Cap at 1 so we stay 1:1 with admin when there is enough space.
        var widthScale = containerW / baseW;
        var scale = widthScale;
        if(scale > 1){ scale = 1; }
        if(scale <= 0){ scale = 1; }

        // Apply size + transform in one write. Never clear transform in a separate step —
        // that briefly paints the full-size canvas ("loads big, then shrinks").
        var contentBottom = 0;
        $draggable.find('.picky-element').each(function(){
          var $el = $(this);
          var topData = parseFloat($el.attr('data-top'));
          var topPos = 0;
          if(!isNaN(topData)){
            topPos = topData;
          } else {
            var p = $el.position();
            topPos = p ? (parseFloat(p.top) || 0) : 0;
          }
          var boxH = $el.find('.picky-element-table-wrap').outerHeight(true) || $el.outerHeight(true) || 0;
          var bottom = topPos + boxH;
          if(bottom > contentBottom){
            contentBottom = bottom;
          }
        });
        var extentH = baseH;
        if(contentBottom > 0){
          extentH = Math.max(baseH, Math.ceil(contentBottom + 24));
        }

        if(scale >= 0.999999){
          $draggable.css({
            'transform': '',
            'transform-origin': '',
            'margin-left': '',
            'margin-right': ''
          });
          /* Smart Map: leave room for the absolute dock on the canvas bottom. */
          var dockPadFull = $wrap.hasClass('picky-tables-wrap-smart-map') ? 84 : 0;
          if(dockPadFull > 0){
            $wrapInner.css({
              'height': '',
              'max-width': '',
              'margin-left': '',
              'margin-right': '',
              'padding-bottom': dockPadFull + 'px'
            });
          } else {
            $wrapInner.css({'height': '', 'max-width': '', 'margin-left': '', 'margin-right': '', 'padding-bottom': ''});
          }
          return;
        }

        var dockPad = $wrap.hasClass('picky-tables-wrap-smart-map') ? 84 : 0;
        var wrapH = Math.max(1, Math.ceil(extentH * scale) + 2 + dockPad);
        $draggable.css({
          'transform-origin': '0 0',
          'transform': 'scale(' + scale + ')',
          'margin-left': '0',
          'margin-right': '0'
        });
        $wrapInner.css({
          'height': wrapH + 'px',
          'max-width': '',
          'margin-left': 'auto',
          'margin-right': 'auto',
          'padding-bottom': ''
        });
      });
    };

    applyCanvasScaleToContainer();

    if(typeof ResizeObserver !== 'undefined'){
      var pickyCanvasRoTimer = null;
      var pickyCanvasRo = new ResizeObserver(function(){
        if(pickyCanvasRoTimer){ clearTimeout(pickyCanvasRoTimer); }
        pickyCanvasRoTimer = setTimeout(function(){
          pickyCanvasRoTimer = null;
          try { applyCanvasScaleToContainer(); } catch(e) {}
        }, 50);
      });
      $('.picky-draggable-wrap').each(function(){
        pickyCanvasRo.observe(this);
      });
    }

    // Init each layout instance independently so multiple shortcodes on the same page
    // each get their own datepicker and booking flow.
    $('.picky-tables-wrap').each(function(){
      var $wrap = $(this);
      pickyApplyOnceEvent($wrap);
      var $dp   = $wrap.find('.picky-datepicker');
      if($wrap.hasClass('picky-tables-wrap-default')){
        if(pickyIsOnceEvent($wrap)){
          pickyWizardInit($wrap);
          refreshAvailableTablesClassic($wrap);
        }
        if($(window).width() <= 767){
          $wrap.find('#picky_booking_time').closest('.picky-input-wrap').hide();
          $wrap.find('#picky_booking_time').closest('.picky-input-wrap').find('.picky-error').hide();
          $wrap.find('#picky_booking_time_to').closest('.picky-input-wrap').hide();
          $wrap.find('#picky_booking_time_to').closest('.picky-input-wrap').find('.picky-error').hide();
          reInitForm('', $dp, $wrap);
        }
      } else if($wrap.hasClass('picky-tables-wrap-second')){
        initFormSecond($dp, $wrap);
        pickyInitSmartMapDock($wrap);
        if(pickyIsSmartMapLayout($wrap)){
          pickyUpdateSelectionTray($wrap);
        }
      } else if($wrap.hasClass('picky-tables-wrap-third')){
        $wrap.find('#picky_booking_time').closest('.picky-input-wrap').hide();
        $wrap.find('#picky_booking_time').closest('.picky-input-wrap').find('.picky-error').hide();
        $wrap.find('#picky_booking_time_to').closest('.picky-input-wrap').hide();
        $wrap.find('#picky_booking_time_to').closest('.picky-input-wrap').find('.picky-error').hide();
        reInitForm('', $dp, $wrap);
      }
    });

    $('.picky-people-select').each(function(){
      var $peopleSelect = $(this);
      var $dropdownParent = $peopleSelect.closest('.picky-tables-wrap');
      $peopleSelect.select2({
        minimumResultsForSearch: -1,
        width: '100%',
        dropdownParent: $dropdownParent.length ? $dropdownParent : $(document.body)
      });
    });

    $('.picky-tables-wrap').each(function(){
      initMobileTableDropdown($(this));
    });
    
  });


  $(window).on('resize',function(){
    $('.picky-tables-wrap').each(function(){
      try { pickySmartSyncDatetimeHeights($(this)); } catch (eH) {}
    });
    // Avoid heavy re-init on every mobile resize (mobile browsers fire resize frequently),
    // because it resets date/time fields and can make selects disappear.
    if (typeof window.__pickyWasMobile === 'undefined') {
      window.__pickyWasMobile = ($(window).width() <= 767);
    }
    var isMobileNow = ($(window).width() <= 767);
    var mobileStateChanged = (window.__pickyWasMobile !== isMobileNow);
    window.__pickyWasMobile = isMobileNow;

    if (isMobileNow) {
      pickyApplyMobileLayoutFlow();
      $('.picky-tables-wrap').each(function(){
        var wrap = $(this);
        var $dp  = wrap.find('.picky-datepicker');
        if(wrap.hasClass('picky-tables-wrap-default')){
          if(mobileStateChanged){
            wrap.find('.picky-form-back').hide();
            wrap.find('.picky-form-thx').hide();
            wrap.find('#picky_booking_time').closest('.picky-input-wrap').hide();
            wrap.find('#picky_booking_time').closest('.picky-input-wrap').find('.picky-error').hide();
            wrap.find('#picky_booking_time_to').closest('.picky-input-wrap').hide();
            wrap.find('#picky_booking_time_to').closest('.picky-input-wrap').find('.picky-error').hide();
            reInitForm('', $dp, wrap);
          }
        } else if(wrap.hasClass('picky-tables-wrap-second')){
          if(mobileStateChanged){
            initFormSecond($dp, wrap);
          }
        }
      });
    } else {
      layoutResize();
    }
    // Always re-apply scale after resize (container width may change without crossing mobile breakpoint)
    setTimeout(function(){
      try { applyCanvasScaleToContainer(); } catch(e) {}
    }, 0);
  });


  function initSelect(layout, array_exclude_times_settings, dt, wrap) {
    var array_exclude_times = [];
    array_exclude_times = array_exclude_times.concat(array_exclude_times_settings);

    var new_exclude = [];
    var new_exclude_array = [];

    // Scope selects to the current wrap — multiple shortcodes on the same page each have
    // their own copies of these IDs and must be driven independently.
    var $select1 = wrap.find('select#picky_booking_time, select#picky_booking_time_fake, select#picky_booking_time_to');

    if($select1.length > 0){
      $select1.each(function(e){

        var $select = $(this);
        var data = [];
        var select_placeholder = $select.attr('data-placeholder');
        var select_val = $select.val();

        // Restore original options before re-filtering for the newly chosen date.
        // Cache the original HTML per-element (data attribute) so multiple wraps don't share state.
        var cachedHtml = $select.data('picky-orig-html');
        if(typeof cachedHtml !== 'undefined' && cachedHtml !== ''){
          $select.html(cachedHtml);
        } else {
          $select.data('picky-orig-html', $select.html());
        }

        if(layout == "second"){
          for (var i = array_exclude_times.length - 1; i >= 0; i--) {
            wrap.find('#picky_booking_time_fake option').each(function(el){
              if($(this).val() == array_exclude_times[i]['time'] && dt == array_exclude_times[i]['date']){
                new_exclude.push(array_exclude_times[i]['time']);
              }
            });
          }
        } else if(layout == "default"){
          for (var i = array_exclude_times.length - 1; i >= 0; i--) {
            wrap.find('#picky_booking_time option').each(function(el){
              if($(this).val() == array_exclude_times[i]['time'] && dt == array_exclude_times[i]['date']){
                new_exclude.push(array_exclude_times[i]['time']);
              }
            });
          }
        }

        $select.find('option').each(function(option){
          data.push($(this).val());
        });
        
        data = data.filter( function( el ) {
          return !new_exclude.includes( el );
        } );

        var s2opts = pickyBuildTimeSelect2Options($select, select_placeholder || '');

        pickyStripSelect2Shell($select);

        // AJAX slot hints: do not fill the select with raw work-window options
        // (they would flash in the slot grid / Select2 before the server filters them).
        // Smart Map must also wait — otherwise times outside work hours appear selectable.
        var hasSlotGrid = wrap.find('.picky-slot-grid').length > 0;
        var deferSlotsToAjax = pickyWrapUsesSlotHints(wrap)
          && ($select.is('#picky_booking_time') || $select.is('#picky_booking_time_to') || $select.is('#picky_booking_time_fake'))
          && (pickyBookingFormUsesStepFlow(wrap) || hasSlotGrid || pickyIsSmartMapLayout(wrap));
        $select.empty();
        $select.append($('<option>', { value: '', text: select_placeholder || '' }));
        if (!deferSlotsToAjax) {
          for (var j = 0; j < data.length; j++) {
            var slot = data[j];
            if(slot === '' || slot == null || String(slot).length === 0){
              continue;
            }
            $select.append($('<option>', { value: String(slot), text: String(slot) }));
          }
        }

        // Slot grids own the UI — keep fallback <select> native (no Select2 shell).
        var isWizardFallback = $select.closest('.picky-slot-select-fallback, .picky-wizard').length > 0
          && ($select.is('#picky_booking_time') || $select.is('#picky_booking_time_to') || $select.is('#picky_booking_time_fake'));
        if(pickyIsSmartLayoutNativeTimeSelect(wrap, $select) || isWizardFallback || deferSlotsToAjax){
          $select.addClass('picky-smart-time-native');
          pickyStripSelect2Shell($select);
        } else {
          $select.removeClass('picky-smart-time-native');
          $select.select2(s2opts);
        }

        if(layout == "second" && !deferSlotsToAjax){
          var _sid = $select.attr('id');
          var _suppressReveal = (_sid === 'picky_booking_time_fake');
          if(_suppressReveal){ pickySuppressSmartTableReveal = true; }
          try {
            if(_sid === 'picky_booking_time_fake'){
              if(select_val){
                $select.val(select_val).trigger('change');
              }
              if($select.val() == null || $select.val() === ''){
                $select.val('').trigger('change');
              }
            } else {
              // time_to (and any other non-fake select in smart layout): never auto-pick first slot — that
              // creates a default end before the user chose start and fights interval_slot_hints filtering.
              if(select_val){
                $select.val(select_val).trigger('change');
              } else {
                $select.val('').trigger('change');
              }
            }
          } finally {
            if(_suppressReveal){ pickySuppressSmartTableReveal = false; }
          }
        } else {
          $select.val('');
          if (!deferSlotsToAjax) {
            try { $select.trigger('change'); } catch (eCh) {}
          }
        }

        pickySyncSmartTimeNativeEmptyClass($select);

      });//each
    }// if

    if (wrap && wrap.length) {
      // Slot grids + AJAX hints: keep the grid empty/loading until AJAX returns.
      if (pickyWrapUsesSlotHints(wrap) && (pickyBookingFormUsesStepFlow(wrap) || wrap.find('.picky-slot-grid').length)) {
        if (wrap.find('#picky_booking_date').val()) {
          pickySlotGridSetLoading(wrap, true);
        } else {
          pickySlotGridShowIdle(wrap);
        }
      } else {
        pickySyncAllSlotGrids(wrap);
      }
    }

  }

  // init datapicker on load for second type layout
  function initFormSecond(datapicker, wrap){
    var array_exclude_dates = [];
    var array_exclude_times = [];
    var array_exclude_dates_settings = [];
    var array_exclude_times_settings = [];
    var plusDate = 30;
    var isSmartMap = pickyIsSmartMapLayout(wrap);
    
    array_exclude_times_settings = pickyParseExcludeAttr(datapicker.attr('data-time'));

    if(datapicker.attr('data-maxdate') && datapicker.attr('data-maxdate').length > 0){
      plusDate = parseInt(datapicker.attr('data-maxdate'));
      if(typeof plusDate != 'number' || isNaN(plusDate)){
        plusDate = 30;
      }
    }

    datapicker.datepicker({ 
      minDate: 0, 
      maxDate: "+"+plusDate-1,
      dateFormat: "mm/dd/yy",
      setDate: false,
      autoUpdateInput: false,
      firstDay: 1,
      showAnim: '',
      duration: 0,
      beforeShow: function(input, inst) {
        if(!isSmartMap){ return; }
        // Popup calendar is appended to <body>, outside .picky-tables-wrap — tag it for wizard styles.
        var $dp = inst && inst.dpDiv ? $(inst.dpDiv) : $('#ui-datepicker-div');
        $dp.addClass('picky-smart-map-datepicker picky-datepicker');
        $dp.css({ zIndex: 300001, visibility: 'visible', display: 'block', maxWidth: '320px' });
        setTimeout(function(){
          $dp.addClass('picky-smart-map-datepicker picky-datepicker');
          $dp.css({ visibility: 'visible', maxWidth: '320px' });
          // Anchor above the bottom dock so the popup stays on-screen.
          var $bar = wrap.find('.picky-smart-map-bar').first();
          if(!$bar.length){ $bar = wrap.find('.picky-smart-map-dock').first(); }
          if(!$bar.length || !$bar[0].getBoundingClientRect){ return; }
          var br = $bar[0].getBoundingClientRect();
          var dw = $dp.outerWidth() || 300;
          var dh = $dp.outerHeight() || 300;
          var left = Math.round(br.left + (br.width - dw) / 2);
          left = Math.max(12, Math.min(left, window.innerWidth - dw - 12));
          var top = Math.round(br.top - dh - 12);
          if(top < 12){ top = Math.min(window.innerHeight - dh - 12, Math.round(br.bottom + 12)); }
          $dp.css({ position: 'fixed', left: left + 'px', top: top + 'px', right: 'auto' });
        }, 0);
      },
      onClose: function() {
        if(!isSmartMap){ return; }
        // Keep .picky-smart-map-datepicker forever after first open — removing it while
        // jQuery UI still has a large computed width caused a one-frame “giant calendar” flash.
        var $dp = $('#ui-datepicker-div');
        $dp.addClass('picky-smart-map-datepicker picky-datepicker');
        $dp.hide().css({
          display: 'none',
          visibility: 'hidden',
          top: '-9999px',
          left: '-9999px',
          width: '',
          maxWidth: '320px'
        });
      },
      onSelect: function(dt) {
        if(isSmartMap){
          // Close + hide before any layout work so no unstyled redraw can flash.
          try { datapicker.datepicker('hide'); } catch (eHide) {}
          var $dpHide = $('#ui-datepicker-div');
          $dpHide.addClass('picky-smart-map-datepicker picky-datepicker');
          $dpHide.hide().css({ display: 'none', visibility: 'hidden', top: '-9999px', left: '-9999px' });
        }
        pickyClearSmartMobileCanvasTableSummary(wrap);
        pickySmartAvailabilityNoteSet(wrap, false);
        if(!isSmartMap){
          wrap.removeClass('picky-smart-table-picker-visible');
        }
        // Scope selectors to current shortcode wrap (IDs are duplicated across shortcodes)
        wrap.find('#picky_booking_date').removeClass('picky-error-input');
        wrap.find('.picky-datepicker, .picky-smart-map-date-input').removeClass('picky-error-input');
        // The jQuery UI datepicker altField can update the wrong shortcode when multiple instances exist.
        // Always force the chosen date into the current wrap's hidden input.
        wrap.find('#picky_booking_date').val(dt).trigger('change');
        wrap.find('#picky_booking_date').closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeOut(100);
        wrap.find('.picky-smart-map-field-date .picky-error.picky-error-empty').fadeOut(100);
        wrap.find('#picky_booking_time_fake').removeClass('picky-error-input');
        wrap.find('#picky_booking_time_fake').closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeOut(100);
        wrap.find('.picky-smart-map-field-time .picky-error.picky-error-empty').fadeOut(100);
        wrap.find('.picky-slot-error-start').fadeOut(80);

        // wrap.find('#picky_booking_time_fake option').show();

        initSelect('second',array_exclude_times_settings, dt, wrap);
        wrap.removeData('pickyLockedBookingTime');
        if(pickyWrapUsesSlotHints(wrap)){
          pickyFetchIntervalSlotHints(wrap, '');
        } else {
          pickySyncAllSlotGrids(wrap);
        }

        var fake_val = wrap.find('#picky_booking_time_fake').val();
        if(fake_val){
          if(wrap.find('#picky_booking_time').val() === ''){
            wrap.find('#picky_booking_time').val(fake_val).trigger('change');
            wrap.find('#picky_booking_time_fake').val(fake_val);
          }
        } else {
          wrap.find('#picky_booking_time').val('');
        }

        var $slots = wrap.find('.picky-wizard-datetime-slots, .picky-input-wrap-time');
        if(pickyIsOnceEvent(wrap)){
          $slots.css('display', 'none');
          wrap.find('.picky-smart-map-field-date').css('display', 'none');
          pickySyncSmartMapBookButton(wrap);
        } else if(isSmartMap){
          $slots.css('display', 'flex');
          pickySyncSmartMapBookButton(wrap);
        } else {
          $slots.show();
        }
        // wrap.find('#picky_booking_time_to').closest('.picky-input-wrap').show();
        setTimeout(function(){ pickySmartSyncDatetimeHeights(wrap); }, 0);

        refreshAvailableTablesSecond(wrap, false);

        // Floor plan is hidden until we have a time; date pick may sync hidden time without firing the fake select change.
        // Smart Map keeps the canvas visible from the start.
        if(wrap.hasClass('picky-tables-wrap-second')){
          var tAfterPick = wrap.find('#picky_booking_time').val();
          if(tAfterPick || isSmartMap){
            wrap.addClass('picky-smart-table-picker-visible');
            setTimeout(function(){
              try { applyCanvasScaleToContainer(); } catch(e) {}
            }, 100);
          }
        }

        // Scroll after Select2 + time row layout (do not auto-focus time: delayed focus races
        // dropdown open and can look like an instant wrong selection on first click).
        setTimeout(function(){
          requestAnimationFrame(function(){
            requestAnimationFrame(function(){
              pickySmartSyncDatetimeHeights(wrap);
              pickySmartScrollToTime(wrap);
            });
          });
        }, 320);

      },
      beforeShowDay: function(dt) {

        array_exclude_dates_settings = decodeURIComponent(datapicker.attr('data-date'));
        array_exclude_dates_settings = JSON.parse(array_exclude_dates_settings);
        array_exclude_dates = array_exclude_dates.concat(array_exclude_dates_settings);
        
        var dateString = $.datepicker.formatDate('mm/dd/yy', dt);
        return [array_exclude_dates.indexOf(dateString) == -1];
      }, 
    });

    if(pickyIsOnceEvent(wrap)){
      pickyApplyOnceEvent(wrap);
      refreshAvailableTablesSecond(wrap, false);
      wrap.addClass('picky-smart-table-picker-visible');
      pickySyncSmartMapBookButton(wrap);
      return;
    }

    datapicker.datepicker( "setDate", null );
    if(datapicker.is('input')){
      datapicker.val('');
      // setDate/_updateDatepicker fills #ui-datepicker-div; keep the popup hidden until open.
      try { $('#ui-datepicker-div').hide().css({ display: 'none', visibility: 'hidden', top: '-9999px', left: '-9999px' }); } catch (eHideDp) {}
    }
    wrap.find('#picky_booking_date').val('');
    pickyClearSmartMobileCanvasTableSummary(wrap);
    pickySmartAvailabilityNoteSet(wrap, false);
    if(isSmartMap){
      wrap.addClass('picky-smart-table-picker-visible');
      setTimeout(function(){
        try { applyCanvasScaleToContainer(); } catch(e) {}
      }, 80);
    } else {
      wrap.removeClass('picky-smart-table-picker-visible');
    }
  }

  // init datapicker form default type layout/ itit on element click
  function reInitForm(element = null, datapicker, wrap){
    var array_exclude_dates = [];
    var array_exclude_times = [];
    var array_exclude_dates_settings = [];
    var array_exclude_times_settings = [];
    var plusDate = 30;

    datapicker.datepicker( "destroy" );
    
    array_exclude_times_settings = pickyParseExcludeAttr(datapicker.attr('data-time'));

    if(datapicker.attr('data-maxdate').length > 0){
      plusDate = parseInt(datapicker.attr('data-maxdate'));
      if(typeof plusDate != 'number' || isNaN(plusDate)){
        plusDate = 30;
      }
    }
    datapicker.datepicker({ 
      minDate: 0, 
      maxDate: "+"+plusDate-1,
      dateFormat: "mm/dd/yy",
      setDate: false,
      firstDay: 1,
      onSelect: function(dt, ui) {
        wrap.find('#picky_booking_date').val(dt).trigger('change');
        wrap.find('#picky_booking_date').removeClass('picky-error-input');
        wrap.find('#picky_booking_date').closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeOut(100);
        wrap.find('#picky_booking_time').removeClass('picky-error-input');
        wrap.find('#picky_booking_time').closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeOut(100);

        // Build the union of booked times across ALL currently-selected elements
        // (multi-select). Without this, only the most-recently-clicked element's
        // booked windows were excluded, so the user could pick a time that's
        // already taken on another selected item.
        function _pickyReadElementBookedTimes($el){
          if(!$el || !$el.length){ return []; }
          var raw = $el.attr('data-time') || '';
          if(!raw.length){ return []; }
          return pickyParseExcludeAttr(raw);
        }
        function _pickyCollectAllSelectedBookedTimes(){
          var union = [];
          var sel = pickyGetSelectedItems(wrap);
          if(sel && sel.length){
            for(var i = 0; i < sel.length; i++){
              var $el = wrap.find('.picky-draggable .picky-element[data-unique="' + sel[i].unique + '"]');
              union = union.concat(_pickyReadElementBookedTimes($el));
            }
          }
          // Always include the last-clicked element (in case it isn't in selection state yet)
          if(element && element.length){
            union = union.concat(_pickyReadElementBookedTimes(element));
          }
          return union;
        }

        var anySelected = pickyGetSelectedItems(wrap).length > 0;
        if(anySelected || (element && element.length)){
          array_exclude_times = _pickyCollectAllSelectedBookedTimes();
          array_exclude_times = array_exclude_times.concat(array_exclude_times_settings);
          initSelect('default', array_exclude_times, dt, wrap);
        } else { // click on date on mobile, no element
          initSelect('default',array_exclude_times_settings, dt, wrap);
        }
        wrap.find('#picky_booking_time').val('');
        wrap.find('#picky_booking_time_to').val('');
        wrap.removeData('pickyLockedBookingTime');
        wrap.find('.picky-wizard[data-picky-wizard]').removeAttr('data-picky-selected-start').removeAttr('data-picky-selected-end');
        if(pickyWrapUsesSlotHints(wrap)){
          pickyFetchIntervalSlotHints(wrap, '');
        }
        wrap.find('.picky-form-booking').find('#picky_booking_time').closest('.picky-input-wrap').not('.picky-slot-select-fallback').show();
        wrap.find('.picky-form-booking').find('#picky_booking_time_to').closest('.picky-input-wrap').not('.picky-slot-select-fallback').show();

        if (pickyBookingFormUsesStepFlow(wrap)) {
          var $fb = wrap.find('.picky-form-booking');
          $fb.addClass('picky-step-date').removeClass('picky-step-time');
          var $chip = wrap.find('.picky-booking-datetime-chip');
          $chip.find('.picky-chip-date').text(dt || '');
          $chip.find('.picky-chip-time').text('');
          $chip.attr('aria-expanded', 'false').addClass('picky-chip-visible');
          // Do not sync slot grids here when hints are pending — that paints the
          // full work-window list and then AJAX removes most/all of them.
          if (!pickyWrapUsesSlotHints(wrap)) {
            pickySyncAllSlotGrids(wrap);
          }
          setTimeout(function(){ pickySmartSyncDatetimeHeights(wrap); }, 0);
        }

        setTimeout(function(){
          requestAnimationFrame(function(){
            requestAnimationFrame(function(){
              var $t = wrap.find('.picky-slot-grid[data-slot-role="start"]').first();
              if (!$t.length) {
                $t = wrap.find('#picky_booking_time').closest('.picky-input-wrap').not('.picky-slot-select-fallback').first();
              }
              if(!$t.length){ return; }
              var pad = pickyScrollPadWithAdminBar(20);
              try {
                var el = $t[0];
                var r = el.getBoundingClientRect();
                var vh = window.innerHeight || document.documentElement.clientHeight || 0;
                if(r.top >= pad - 10 && r.bottom <= vh - 16){ return; }
              } catch (e) {}
              pickyScrollToTarget($t, pad);
            });
          });
        }, 320);
      },
      beforeShowDay: function(dt) {
        if(element.length > 0){
          if(element.attr('data-date').length > 0){

            array_exclude_dates = decodeURIComponent(element.attr('data-date'));
            array_exclude_dates = JSON.parse(array_exclude_dates);
            array_exclude_dates_settings = decodeURIComponent(datapicker.attr('data-date'));
            array_exclude_dates_settings = JSON.parse(array_exclude_dates_settings);
            array_exclude_dates = array_exclude_dates.concat(array_exclude_dates_settings);
          }
        } else { // on load mobile
          array_exclude_dates_settings = decodeURIComponent(datapicker.attr('data-date'));
          array_exclude_dates_settings = JSON.parse(array_exclude_dates_settings);
          array_exclude_dates = array_exclude_dates.concat(array_exclude_dates_settings);
        }

        var dateString = $.datepicker.formatDate('mm/dd/yy', dt);
        return [array_exclude_dates.indexOf(dateString) == -1];
      },
    });

    wrap.find('#picky_booking_date').val('');
    pickyResetBookingStepFlow(wrap);
    pickyApplyOnceEvent(wrap);
  }

  /** Default / Smart / Simple forms that use the multi-step wizard markup. */
  function pickyBookingFormUsesStepFlow(wrap) {
    return !!(wrap && wrap.length && wrap.find('.picky-wizard[data-picky-wizard]').length);
  }

  function pickyWizardRoot(wrap) {
    return wrap.find('.picky-wizard[data-picky-wizard]').first();
  }

  function pickyWizardCurrentStep(wrap) {
    var $wiz = pickyWizardRoot(wrap);
    var $active = $wiz.find('.picky-wizard-step.is-active').first();
    var step = parseInt($active.attr('data-step'), 10);
    return isNaN(step) ? 1 : step;
  }

  function pickyWizardSyncCta(wrap, step) {
    var $btn = wrap.find('.picky-wizard .picky-wizard-cta').first();
    if (!$btn.length) { return; }
    step = parseInt(step, 10);
    var cont = $btn.attr('data-label-continue') || 'Continue';
    var book = $btn.attr('data-label-book') || 'Pay & Book';
    if (step >= 3) {
      $btn.addClass('picky-send-booking is-book');
      $btn.find('.picky-wizard-cta-text').text(book);
      $btn.find('.picky-wizard-continue-arrow').hide();
    } else {
      $btn.removeClass('picky-send-booking is-book');
      $btn.find('.picky-wizard-cta-text').text(cont);
      $btn.find('.picky-wizard-continue-arrow').show();
    }
  }

  function pickyWizardShowPayment(wrap, url, provider) {
    // Checkout must be a full-page redirect (Woo/Stripe cannot run in an iframe).
    url = String(url || '');
    if (!url) { return false; }
    window.location.href = url;
    return true;
  }

  function pickyGetWizardStartTime(wrap) {
    if (!wrap || !wrap.length) { return ''; }
    var $wiz = pickyWizardRoot(wrap);
    var fromBtn = ($wiz.find('.picky-slot-grid[data-slot-role="start"] .picky-slot-btn.is-selected').attr('data-value') || '').toString();
    var fromData = ($wiz.attr('data-picky-selected-start') || '').toString();
    var fromSel = (wrap.find('select#picky_booking_time').val() || '').toString();
    var fromFake = (wrap.find('select#picky_booking_time_fake').val() || '').toString();
    var fromInput = (wrap.find('input#picky_booking_time').val() || '').toString();
    var fromLock = (wrap.data('pickyLockedBookingTime') || '').toString();
    return fromBtn || fromData || fromSel || fromFake || fromInput || fromLock || '';
  }

  function pickyGetWizardEndTime(wrap) {
    if (!wrap || !wrap.length) { return ''; }
    var $wiz = pickyWizardRoot(wrap);
    var fromBtn = ($wiz.find('.picky-slot-grid[data-slot-role="end"] .picky-slot-btn.is-selected').attr('data-value') || '').toString();
    var fromData = ($wiz.attr('data-picky-selected-end') || '').toString();
    var fromSel = (wrap.find('select#picky_booking_time_to').val() || '').toString();
    return fromBtn || fromData || fromSel || '';
  }

  function pickyWizardEnsureCarousel($wiz) {
    if (!$wiz || !$wiz.length || $wiz.data('pickyCarouselReady')) { return; }
    // Prefer server-rendered viewport/track; otherwise wrap steps once.
    if ($wiz.find('> .picky-wizard-steps-viewport > .picky-wizard-steps-track').length) {
      $wiz.data('pickyCarouselReady', 1);
      return;
    }
    var $steps = $wiz.children('.picky-wizard-step');
    if (!$steps.length) {
      $steps = $wiz.find('.picky-wizard-step');
    }
    if ($steps.length < 2) {
      $wiz.data('pickyCarouselReady', 1);
      return;
    }
    var $viewport = $('<div class="picky-wizard-steps-viewport" aria-live="polite"></div>');
    var $track = $('<div class="picky-wizard-steps-track"></div>');
    $steps.appendTo($track);
    $viewport.append($track);
    var $footer = $wiz.children('.picky-wizard-footer').first();
    if ($footer.length) {
      $viewport.insertBefore($footer);
    } else {
      $wiz.append($viewport);
    }
    $wiz.data('pickyCarouselReady', 1);
  }

  function pickyWizardSyncViewportHeight($wiz, animate) {
    var $viewport = $wiz.find('.picky-wizard-steps-viewport').first();
    var $active = $wiz.find('.picky-wizard-step.is-active').first();
    if (!$viewport.length || !$active.length) { return; }
    var el = $active[0];
    /* Natural content height (inactive siblings are collapsed via CSS). */
    var h = Math.ceil(Math.max(
      el.scrollHeight || 0,
      el.offsetHeight || 0,
      $active.outerHeight() || 0
    ));
    if (h < 1) { return; }
    if (animate === false) {
      $viewport.css('transition', 'none');
      $viewport.css('height', h + 'px');
      void $viewport[0].offsetHeight;
      $viewport.css('transition', '');
    } else {
      $viewport.css('height', h + 'px');
    }
  }

  function pickyWizardGo(wrap, step) {
    var $wiz = pickyWizardRoot(wrap);
    if (!$wiz.length) { return; }
    pickyWizardEnsureCarousel($wiz);

    step = parseInt(step, 10);
    if (isNaN(step) || step < 1) { step = 1; }
    if (step > 3) { step = 3; }
    if (pickyIsOnceEvent(wrap) && step < 2) { step = 2; }

    var instant = !$wiz.data('pickyCarouselPainted');

    var $track = $wiz.find('.picky-wizard-steps-track').first();
    var $steps = $track.length
      ? $track.children('.picky-wizard-step')
      : $wiz.find('.picky-wizard-step');

    $steps.each(function() {
      var $s = $(this);
      var n = parseInt($s.attr('data-step'), 10);
      var on = (n === step);
      $s.removeClass('picky-slide-from-left picky-slide-from-right');
      $s.toggleClass('is-active', on);
      $s.toggleClass('is-prev', n < step);
      $s.toggleClass('is-next', n > step);
      $s.attr('aria-hidden', on ? 'false' : 'true');
      // Keep steps in the flex track (no [hidden]/display:none) so translateX animates.
      $s.removeAttr('hidden').prop('hidden', false);
      if (on) {
        $s.removeAttr('inert');
      } else if ($s[0] && 'inert' in $s[0]) {
        $s.attr('inert', '');
      }
    });

    if ($track.length) {
      var idx = Math.max(0, step - 1);
      if (instant) {
        $track.css('transition', 'none');
        $track.css('transform', 'translate3d(' + (-idx * 100) + '%, 0, 0)');
        void $track[0].offsetHeight;
        $track.css('transition', '');
      } else {
        $track.css('transform', 'translate3d(' + (-idx * 100) + '%, 0, 0)');
      }
    }

    $wiz.toggleClass('is-final-step', step === 3);
    $wiz.removeClass('is-payment-step');
    $wiz.find('.picky-wizard-payment').prop('hidden', true).attr('hidden', 'hidden');
    $wiz.attr('data-current-step', String(step));
    pickyWizardSyncCta(wrap, step);

    /* Paint step-2 lists before measuring, otherwise viewport stays empty/tall. */
    if (step === 2) {
      try { pickyRenderSelectedItemsList(wrap); } catch (eList) {}
      try { pickyRenderBottomServicesPanel(wrap); } catch (eSvc) {}
      wrap.find('.picky-items-summary-wrap').filter(function() {
        return $(this).find('.picky-selected-item-row, .picky-tile').length > 0;
      }).show();
    }

    if (step === 3) {
      try { pickyRefreshContactFieldValidity(wrap, { force: true }); } catch (eVal) {}
    }

    /* Step 3 snaps; other steps may animate height. Always remasure after paint. */
    pickyWizardSyncViewportHeight($wiz, step === 3 ? false : !instant);
    $wiz.data('pickyCarouselPainted', 1);

    window.setTimeout(function() { pickyWizardSyncViewportHeight($wiz, step === 3 ? false : true); }, 40);
    window.setTimeout(function() { pickyWizardSyncViewportHeight($wiz, false); }, 200);

    $wiz.find('.picky-wizard-dot').each(function() {
      var $d = $(this);
      var n = parseInt($d.attr('data-step'), 10);
      $d.toggleClass('is-active', n === step);
      $d.toggleClass('is-done', n < step);
      if (n === step) { $d.attr('aria-current', 'step'); }
      else { $d.removeAttr('aria-current'); }
    });

    // Keep Classic chip in sync when on/after date step.
    var dt = (wrap.find('#picky_booking_date').val() || '').toString();
    var tm = pickyGetWizardStartTime(wrap);
    var $chip = wrap.find('.picky-booking-datetime-chip');
    if ($chip.length && dt) {
      $chip.addClass('picky-chip-visible').find('.picky-chip-date').text(dt);
      if (tm) {
        $chip.find('.picky-chip-time').text(tm);
        $chip.attr('aria-expanded', 'true');
      }
    }

    try {
      var $active = $wiz.find('.picky-wizard-step.is-active').first();
      if ($active.length && !instant) {
        pickyScrollToTarget($active, pickyScrollPadWithAdminBar(12));
      }
    } catch (eScroll) {}
  }

  $(window).on('resize.pickyWizardCarousel', function() {
    $('.picky-wizard[data-picky-wizard]').each(function() {
      pickyWizardSyncViewportHeight($(this), false);
    });
  });

  function pickyWizardInit(wrap) {
    if (!pickyBookingFormUsesStepFlow(wrap)) { return; }
    var $wiz = pickyWizardRoot(wrap);
    var start = parseInt($wiz.attr('data-picky-start-step'), 10);
    if (isNaN(start) || start < 1) { start = 1; }
    // Smart: date/time already set outside → land on services (step 2).
    if (wrap.hasClass('picky-tables-wrap-second') || $wiz.closest('form').attr('data-picky-wizard-smart') === '1') {
      start = 2;
      var dt = (wrap.find('#picky_booking_date').val() || '').toString();
      var tm = pickyGetSmartBookingTime(wrap);
      var $chip = $wiz.find('.picky-booking-datetime-chip');
      if ($chip.length) {
        $chip.addClass('picky-chip-visible');
        $chip.find('.picky-chip-date').text(dt);
        $chip.find('.picky-chip-time').text(tm);
      }
    }
    pickyWizardGo(wrap, start);
    // Prefer server slot hints over painting the full select (avoids flash).
    if (pickyWrapUsesSlotHints(wrap) && pickyBookingFormUsesStepFlow(wrap)) {
      /* Remember map/form start BEFORE clearing wizard selects. */
      var stInit = (typeof pickyGetSmartBookingTime === 'function' ? pickyGetSmartBookingTime(wrap) : '')
        || (typeof pickyGetWizardStartTime === 'function' ? pickyGetWizardStartTime(wrap) : '')
        || (wrap.find('select#picky_booking_time_fake').val() || '').toString()
        || (wrap.data('pickyLockedBookingTime') || '').toString();
      if (stInit) {
        pickyLockSmartBookingTime(wrap, stInit);
        wrap.find('input#picky_booking_time').val(stInit);
      }
      pickyClearWizardTimeSelectOptions(wrap);
      if (wrap.find('#picky_booking_date').val()) {
        /* Always pass the known start so hints refresh does not wipe the clock. */
        pickyFetchIntervalSlotHints(wrap, stInit);
      } else {
        pickySlotGridShowIdle(wrap);
      }
    } else {
      pickySyncAllSlotGrids(wrap);
    }
    setTimeout(function(){ pickySmartSyncDatetimeHeights(wrap); }, 50);
  }

  function pickyResetBookingStepFlow(wrap) {
    if (!pickyBookingFormUsesStepFlow(wrap)) { return; }
    var $wiz = pickyWizardRoot(wrap);
    var start = parseInt($wiz.attr('data-picky-start-step'), 10);
    if (wrap.hasClass('picky-tables-wrap-second') || $wiz.closest('form').attr('data-picky-wizard-smart') === '1') {
      start = 2;
    } else if (isNaN(start) || start < 1) {
      start = 1;
    }
    pickyWizardGo(wrap, start);
    if (pickyIsOnceEvent(wrap)) {
      pickyApplyOnceEvent(wrap);
      return;
    }
    wrap.find('.picky-booking-datetime-chip').removeClass('picky-chip-visible').attr('aria-expanded', 'false');
    wrap.find('.picky-booking-datetime-chip .picky-chip-date').text('');
    wrap.find('.picky-booking-datetime-chip .picky-chip-time').text('');
  }

  function pickyFormatSlotLabel(t) {
    return String(t || '');
  }

  function pickySlotGridStart($wrap) {
    return $wrap.find('.picky-slot-grid[data-slot-role="start"]').first();
  }

  function pickySlotGridShowIdle(wrap) {
    var $grid = pickySlotGridStart(wrap);
    if (!$grid.length) { return; }
    wrap.removeData('pickySlotHintsReady');
    wrap.removeData('pickySlotHintsLoading');
    $grid.removeClass('is-loading').removeAttr('aria-busy').empty()
      .append($('<div class="picky-slot-grid-status picky-slot-grid-idle"></div>')
        .text('Select a date to see available times'));
  }

  function pickySlotGridSetLoading(wrap, on) {
    var $grid = pickySlotGridStart(wrap);
    if (!$grid.length) { return; }
    if (on) {
      wrap.data('pickySlotHintsLoading', 1);
      wrap.removeData('pickySlotHintsReady');
      $grid.addClass('is-loading').attr('aria-busy', 'true').empty()
        .append(
          $('<div class="picky-slot-grid-status picky-slot-grid-loading" role="status"></div>')
            .append('<span class="picky-slot-spinner" aria-hidden="true"></span>')
            .append($('<span class="picky-slot-loading-text"></span>').text('Loading available times…'))
        );
    } else {
      wrap.removeData('pickySlotHintsLoading');
      $grid.removeClass('is-loading').removeAttr('aria-busy');
    }
  }

  function pickyHintsUniqueForRequest(wrap) {
    var items = pickyGetSelectedItems(wrap);
    if (items && items.length) {
      var parts = [];
      var seen = {};
      for (var i = 0; i < items.length; i++) {
        var u = String(items[i].unique || '').trim();
        if (!u || seen[u]) { continue; }
        seen[u] = true;
        parts.push(u);
      }
      if (parts.length) { return parts.join(','); }
    }
    return wrap.find('input[name="picky_booking_unique"]').val() || '';
  }

  function pickyClearWizardTimeSelectOptions(wrap) {
    wrap.find('.picky-wizard select#picky_booking_time, .picky-slot-select-fallback select#picky_booking_time, .picky-wizard select#picky_booking_time_to, .picky-slot-select-fallback select#picky_booking_time_to').each(function() {
      var $sel = $(this);
      var ph = $sel.attr('data-placeholder') || '';
      // Keep orig HTML cached for restore paths, but do not expose raw options in the UI.
      if (typeof $sel.data('picky-orig-html') === 'undefined') {
        $sel.data('picky-orig-html', $sel.html());
      }
      pickyStripSelect2Shell($sel);
      $sel.empty().append($('<option>', { value: '', text: ph || '' }));
      $sel.val('');
    });
  }

  function pickySyncSlotGridFromSelect(wrap, $sel, role) {
    var $grid = wrap.find('.picky-slot-grid[data-slot-role="' + role + '"]').first();
    if (!$grid.length || !$sel || !$sel.length) { return; }
    // Never paint the unfiltered <select> while waiting for AJAX slot hints.
    if (role === 'start' && pickyWrapUsesSlotHints(wrap)
        && (pickyBookingFormUsesStepFlow(wrap) || wrap.find('.picky-slot-grid').length)) {
      if (wrap.data('pickySlotHintsLoading')) { return; }
      if (!wrap.find('#picky_booking_date').val()) {
        pickySlotGridShowIdle(wrap);
        return;
      }
      if (!wrap.data('pickySlotHintsReady')) { return; }
    }
    $grid.removeClass('is-loading').removeAttr('aria-busy');
    var $wiz = pickyWizardRoot(wrap);
    var prev = String($sel.val() || '');
    if (!prev && $wiz.length) {
      prev = role === 'end'
        ? String($wiz.attr('data-picky-selected-end') || '')
        : String($wiz.attr('data-picky-selected-start') || '');
      if (prev) {
        if ($sel.find('option').filter(function() { return String($(this).attr('value')) === prev; }).length) {
          $sel.val(prev);
        }
      }
    }
    $grid.empty();
    var count = 0;
    $sel.find('option').each(function() {
      var v = String($(this).attr('value') || '');
      if (!v) { return; }
      count++;
      var $btn = $('<button type="button" class="picky-slot-btn" role="option"></button>');
      $btn.attr('data-value', v).text(pickyFormatSlotLabel(v));
      if (v === prev) { $btn.addClass('is-selected').attr('aria-selected', 'true'); }
      $grid.append($btn);
    });
    if (role === 'start' && count === 0) {
      $grid.append(
        $('<div class="picky-slot-grid-status picky-slot-grid-empty"></div>')
          .text('No available times for this date')
      );
    }
    var $block = $grid.closest('.picky-slot-picker-block');
    if (role === 'end') {
      if (count > 0) { $block.show(); }
      else { $block.hide(); }
    }
    setTimeout(function(){ pickySmartSyncDatetimeHeights(wrap); }, 0);
  }

  function pickySyncAllSlotGrids(wrap) {
    wrap.find('select#picky_booking_time_fake').each(function() {
      pickySyncSlotGridFromSelect(wrap, $(this), 'start');
    });
    wrap.find('select#picky_booking_time').each(function() {
      var $t = $(this);
      if ($t.attr('id') === 'picky_booking_time_fake') { return; }
      // Prefer fake when present (Smart outside); otherwise classic/simple #picky_booking_time.
      if (wrap.find('select#picky_booking_time_fake').length && !$t.closest('.picky-wizard').length) {
        return;
      }
      pickySyncSlotGridFromSelect(wrap, $t, 'start');
    });
    wrap.find('select#picky_booking_time_to').each(function() {
      pickySyncSlotGridFromSelect(wrap, $(this), 'end');
    });
  }

  function pickySelectSlotValue(wrap, role, value) {
    value = String(value || '');
    var $wiz = pickyWizardRoot(wrap);
    var $sel;
    if (role === 'end') {
      $sel = wrap.find('select#picky_booking_time_to').first();
      if ($wiz.length) { $wiz.attr('data-picky-selected-end', value); }
    } else {
      // Smart outer tiles: drive #picky_booking_time_fake (canvas). Classic wizard: form select.
      if (wrap.hasClass('picky-tables-wrap-second') && wrap.find('select#picky_booking_time_fake').length) {
        $sel = wrap.find('select#picky_booking_time_fake').first();
      } else {
        $sel = wrap.find('.picky-wizard select#picky_booking_time, .picky-slot-select-fallback select#picky_booking_time').first();
        if (!$sel.length) {
          $sel = wrap.find('select#picky_booking_time_fake').first();
        }
        if (!$sel.length) {
          $sel = wrap.find('select#picky_booking_time').first();
        }
      }
      if ($wiz.length) { $wiz.attr('data-picky-selected-start', value); }
    }
    if ($sel.length) {
      pickyStripSelect2Shell($sel);
      if ($sel.find('option').filter(function() { return String($(this).attr('value')) === value; }).length === 0) {
        $sel.append($('<option>', { value: value, text: value }));
      }
      $sel.val(value);
      try { $sel.trigger('change'); } catch (eCh) {}
    }
    if (role !== 'end') {
      wrap.find('input#picky_booking_time').val(value);
      if (wrap.find('select#picky_booking_time_fake').length) {
        wrap.find('select#picky_booking_time_fake').val(value);
        pickySyncSmartHiddenBookingTime(wrap);
      }
    }
    // Update selected UI without wiping options (full sync can race with slot-hint AJAX).
    var $grid = wrap.find('.picky-slot-grid[data-slot-role="' + (role === 'end' ? 'end' : 'start') + '"]').first();
    if ($grid.length) {
      $grid.find('.picky-slot-btn').removeClass('is-selected').attr('aria-selected', 'false');
      $grid.find('.picky-slot-btn').filter(function() {
        return String($(this).attr('data-value')) === value;
      }).addClass('is-selected').attr('aria-selected', 'true');
    } else {
      pickySyncAllSlotGrids(wrap);
    }
  }

  function pickyWizardValidateStep(wrap, step) {
    step = parseInt(step, 10);
    if (step === 1) {
      var dt = (wrap.find('#picky_booking_date').val() || '').toString();
      var tm = pickyGetWizardStartTime(wrap);
      if (!dt) {
        wrap.find('.picky-input-wrap-datepicker .picky-error.picky-error-empty, .picky-calendar-left .picky-error.picky-error-empty').fadeIn(100);
        return false;
      }
      if (!tm) {
        wrap.find('.picky-slot-error-start').css('display', 'block').fadeIn(100);
        wrap.find('#picky_booking_time, #picky_booking_time_fake').addClass('picky-error-input');
        return false;
      }
      // Persist into form fields before leaving the step.
      wrap.find('select#picky_booking_time, input#picky_booking_time').val(tm);
      wrap.find('.picky-slot-error-start').fadeOut(80);
      if (pickyWrapHasIntervalBooking(wrap)) {
        var tmTo = pickyGetWizardEndTime(wrap);
        if (!tmTo) {
          wrap.find('.picky-slot-error-end, .picky-input-wrap-time-to .picky-error').css('display', 'block').fadeIn(100);
          return false;
        }
        wrap.find('select#picky_booking_time_to').val(tmTo);
      }
      return true;
    }
    if (step === 2) {
      // Smart/Classic: need at least one selected item when multi/tray flow exists.
      if (wrap.find('#picky_selected_items_list').length) {
        var items = pickyGetSelectedItems(wrap);
        if (!items.length && wrap.find('.picky-draggable .picky-element').length) {
          wrap.find('.picky-items-selection-notice').text(
            (window.pickyAjax && window.pickyAjax.i18n && window.pickyAjax.i18n.select_item)
              ? window.pickyAjax.i18n.select_item
              : 'Please select at least one item'
          ).show();
          return false;
        }
      }
      return true;
    }
    return true;
  }

  function pickyMarkInputValid($input, ok, opts) {
    if (!$input || !$input.length) { return; }
    opts = opts || {};
    var val = String($input.val() || '').trim();
    var $icon = $input.closest('.picky-input-control, .picky-input-inner').find('> .picky-input-valid-icon, .picky-input-valid-icon').first();
    if (!$icon.length) {
      $icon = $input.siblings('.picky-input-valid-icon').first();
    }
    // Empty + not forced: no icon yet (idle).
    if (!opts.force && val === '' && !$input.hasClass('picky-error-input')) {
      $input.removeClass('is-valid is-invalid');
      $icon.removeClass('is-ok is-warn').attr('data-state', '').css('display', '');
      return;
    }
    if (ok) {
      $input.addClass('is-valid').removeClass('is-invalid picky-error-input');
      $icon.removeClass('is-warn').addClass('is-ok').attr('data-state', 'ok').css('display', '');
      $input.closest('.picky-input-inner').children('.picky-error').hide();
    } else {
      $input.addClass('is-invalid').removeClass('is-valid');
      // Errors use red text under the field — keep icon hidden for invalid.
      $icon.removeClass('is-ok is-warn').attr('data-state', 'warn').css('display', '');
    }
  }

  function pickyRefreshContactFieldValidity(wrap, opts) {
    opts = opts || {};
    var $name = wrap.find('#picky_booking_name');
    if ($name.length) {
      pickyMarkInputValid($name, String($name.val() || '').trim().length > 0, opts);
    }
    var $phone = wrap.find('#picky_booking_phone');
    if ($phone.length) {
      var ph = String($phone.val() || '').trim();
      var phoneOk = ph.length > 0 && (typeof checkTelephone === 'function' ? checkTelephone(ph) === true : /^[+\d\s\-()]{6,}$/.test(ph));
      pickyMarkInputValid($phone, phoneOk, opts);
    }
    var $email = wrap.find('#picky_booking_email');
    if ($email.length) {
      var em = String($email.val() || '').trim();
      var emailOk = em.length > 0 && (typeof checkEmail === 'function' ? checkEmail(em) === true : /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em));
      pickyMarkInputValid($email, emailOk, opts);
    }
  }

  /** Interval booking UI: second end-time select exists. */
  function pickyWrapHasIntervalBooking(wrap){
    return wrap && wrap.length && wrap.find('select#picky_booking_time_to').length > 0;
  }

  /** Fetch server slot hints for this shortcode (start times always; ends when interval UI exists). */
  function pickyWrapUsesSlotHints(wrap){
    if(!wrap || !wrap.length){ return false; }
    if(!wrap.find('input[name="picky_layout_id"]').length){ return false; }
    return wrap.find('select#picky_booking_time_fake').length > 0
      || wrap.find('select#picky_booking_time').length > 0;
  }

  /** Smart layout: read chosen start time (hidden field, then visible select, then lock). */
  function pickyGetSmartBookingTime(wrap){
    if(!wrap || !wrap.length){ return ''; }
    var tm = (wrap.find('input#picky_booking_time').val() || '').toString();
    if(tm === ''){
      tm = (wrap.find('select#picky_booking_time_fake').val() || '').toString();
    }
    if(tm === ''){
      tm = (wrap.data('pickyLockedBookingTime') || '').toString();
    }
    return tm;
  }

  /** Remember the user's chosen start so slot-hint rebuilds cannot wipe it. */
  function pickyLockSmartBookingTime(wrap, tm){
    if(!wrap || !wrap.length){ return; }
    tm = (tm || '').toString();
    if(tm){
      wrap.data('pickyLockedBookingTime', tm);
    } else {
      wrap.removeData('pickyLockedBookingTime');
    }
  }

  /**
   * Ensure a start-time <select> keeps `tm` selected (append option if needed).
   * Used when slot hints refresh ends/duration without intending to change start.
   */
  function pickyEnsureStartTimeOption(wrap, $sel, tm){
    if(!$sel || !$sel.length || !tm){ return; }
    tm = String(tm);
    var has = false;
    $sel.find('option').each(function(){
      if(String(this.value) === tm){ has = true; return false; }
    });
    if(!has){
      $sel.append($('<option>', { value: tm, text: tm }));
    }
    wrap.data('pickyApplyingSlotHints', 1);
    try {
      $sel.val(tm);
      pickySyncSmartTimeNativeEmptyClass($sel);
      wrap.find('input#picky_booking_time').val(tm);
      pickyLockSmartBookingTime(wrap, tm);
      var $wiz = pickyWizardRoot(wrap);
      if($wiz.length){ $wiz.attr('data-picky-selected-start', tm); }
    } finally {
      setTimeout(function(){ wrap.removeData('pickyApplyingSlotHints'); }, 0);
    }
  }

  /** Smart layout: keep hidden #picky_booking_time aligned with the visible time select. */
  function pickySyncSmartHiddenBookingTime(wrap){
    if(!wrap || !wrap.length){ return ''; }
    var $fake = wrap.find('select#picky_booking_time_fake');
    var tm = ($fake.val() || '').toString();
    if(!tm){
      /* Do not blank a still-valid hidden/locked time when the visible select
         was emptied by a slot-options rebuild. */
      tm = (wrap.find('input#picky_booking_time').val()
        || wrap.data('pickyLockedBookingTime')
        || '').toString();
      if(tm && $fake.length){
        pickyEnsureStartTimeOption(wrap, $fake, tm);
      }
    } else {
      wrap.find('input#picky_booking_time').val(tm);
      pickyLockSmartBookingTime(wrap, tm);
    }
    return (wrap.find('input#picky_booking_time').val() || tm || '').toString();
  }

  /**
   * Resolve current booking date + start time for any layout (Smart / Classic / Third).
   */
  function pickyGetCanvasBookingDateTime(wrap){
    var dt = (wrap.find('#picky_booking_date').val() || '').toString();
    var timeVal = '';
    if(wrap.hasClass('picky-tables-wrap-second') && typeof pickyGetSmartBookingTime === 'function'){
      timeVal = (pickyGetSmartBookingTime(wrap) || '').toString();
    }
    if(!timeVal){
      timeVal = (wrap.find('select#picky_booking_time_fake').val() || '').toString();
    }
    if(!timeVal){
      timeVal = (wrap.find('.picky-wizard select#picky_booking_time, .picky-slot-select-fallback select#picky_booking_time, select#picky_booking_time').not('#picky_booking_time_fake').first().val() || '').toString();
    }
    if(!timeVal){
      timeVal = (wrap.find('input#picky_booking_time').val() || '').toString();
    }
    return { date: dt, time: timeVal };
  }

  /**
   * Disable canvas spots for the chosen date/time (Smart + Classic + Third).
   * serverAllowedMap: optional map unique→true from get_available_tables; when omitted, only
   * per-element data-time (page-load bookings) is used.
   */
  function pickyApplyCanvasAvailability(wrap, serverAllowedMap){
    if(!wrap || !wrap.length){ return; }
    var slot = pickyGetCanvasBookingDateTime(wrap);
    var dt = slot.date;
    var val = slot.time;
    if(!dt || !val){
      wrap.find('.picky-draggable .picky-element').removeClass('disabled');
      pickyUpdateBookedAvatars(wrap);
      return;
    }
    var settingsTimes = [];
    var $dp = wrap.find('.picky-datepicker');
    if($dp.length && $dp.attr('data-time')){
      settingsTimes = pickyParseExcludeAttr($dp.attr('data-time'));
    }
    var useServer = serverAllowedMap && typeof serverAllowedMap === 'object';
    wrap.find('.picky-draggable .picky-element').each(function(){
      var $el = $(this);
      var elType = ($el.attr('data-type') || '').toString();
      if(elType === 'type-wall' || elType === 'type-text' || elType === 'type-shape' || elType === 'type-icon'){ return; }
      var uniq = String($el.attr('data-unique') || '');
      if(!uniq){
        $el.addClass('disabled');
        return;
      }
      var uniqKey = uniq.toLowerCase();
      var clientBooked = false;
      var raw = $el.attr('data-time');
      if(raw && String(raw).length > 0){
        var times = pickyParseExcludeAttr(raw).concat(settingsTimes);
        for(var i = 0; i < times.length; i++){
          if(times[i] && times[i].date == dt && times[i].time == val){
            clientBooked = true;
            break;
          }
        }
      }
      var serverOk = !useServer || !!serverAllowedMap[uniq] || !!serverAllowedMap[uniqKey];
      if(clientBooked || !serverOk){
        $el.addClass('disabled');
        if(clientBooked){
          pickyPrimeAvatarLoader($el);
        }
      } else {
        $el.removeClass('disabled');
      }
    });
    pickyUpdateBookedAvatars(wrap);
  }

  /** @deprecated Use pickyApplyCanvasAvailability — kept for existing Smart call sites. */
  function pickyApplySmartCanvasAvailability(wrap, serverAllowedMap){
    pickyApplyCanvasAvailability(wrap, serverAllowedMap);
  }

  function pickyParseOccupants(wrap){
    var live = wrap.data('pickyOccupants');
    if(Array.isArray(live)){ return live; }
    var raw = wrap.attr('data-picky-occupants') || '';
    if(!raw){ return []; }
    try {
      var list = JSON.parse(raw);
      return Array.isArray(list) ? list : [];
    } catch (e) {
      return [];
    }
  }

  function pickyFindGuestForElement($el, wrap, dt, timeVal){
    var guest = null;
    var onceEvent = pickyIsOnceEvent(wrap);
    var raw = $el.attr('data-time');
    if(raw && String(raw).length > 0){
      var times = pickyParseExcludeAttr(raw);
      for(var i = 0; i < times.length; i++){
        if(!times[i] || !times[i].guest){ continue; }
        if(onceEvent){
          if(times[i].date == dt && times[i].time == timeVal){
            guest = times[i].guest;
            break;
          }
          if(!guest){ guest = times[i].guest; }
          continue;
        }
        if(times[i].date == dt && times[i].time == timeVal){
          guest = times[i].guest;
          break;
        }
      }
    }
    if(guest){ return guest; }
    var uniq = String($el.attr('data-unique') || '');
    var id = String($el.attr('data-id') || '');
    var occupants = pickyParseOccupants(wrap);
    var fallback = null;
    for(var j = 0; j < occupants.length; j++){
      var row = occupants[j];
      if(!row || !row.guest){ continue; }
      var sameItem = (uniq && String(row.unique || '') === uniq) || (id && String(row.id || '') === id);
      if(!sameItem){ continue; }
      /* Once-event layouts: any booking on this seat shows the avatar. */
      if(onceEvent){
        return row.guest;
      }
      if(String(row.date || '') !== String(dt)){ continue; }
      if(String(row.time || '') === String(timeVal)){
        return row.guest;
      }
      if(!fallback){ fallback = row.guest; }
    }
    return fallback;
  }

  function pickyAvatarsEnabled(wrap){
    return String((wrap && wrap.attr('data-show-booked-avatars')) || '') === '1';
  }

  /** Show gray tile + spinner as soon as a seat is known booked (before photo resolves). */
  function pickyPrimeAvatarLoader($el){
    if(!$el || !$el.length || !pickyAvatarsEnabled($el.closest('.picky-tables-wrap'))){ return; }
    var elType = ($el.attr('data-type') || '').toString();
    if(elType === 'type-wall' || elType === 'type-text' || elType === 'type-shape' || elType === 'type-icon'){ return; }
    var $av = $el.find('.picky-element-avatar').first();
    if(!$av.length){ return; }
    $el.addClass('picky-has-booked-avatar');
    if($av.hasClass('is-loading') && !$av.prop('hidden') && $av.find('.picky-element-avatar__spin').length && !$av.find('.picky-element-avatar__img').length){
      return;
    }
    $av.html('<span class="picky-element-avatar__spin" aria-hidden="true"></span>')
      .prop('hidden', false)
      .attr('aria-hidden', 'false')
      .addClass('is-loading')
      .removeAttr('aria-label');
    pickyMountBookableAvatarOnShape($el);
  }

  function pickyRenderBookedAvatar($av, guest){
    if(!$av || !$av.length || !guest){ return; }
    var $el = $av.closest('.picky-element');
    var name = (guest.name || '').toString();
    var avatar = (guest.avatar || '').toString();
    var initials = (guest.initials || '?').toString();
    var tip = name !== '' ? name : initials;

    var clearSpin = function(){
      $av.removeClass('is-loading');
      $av.find('.picky-element-avatar__spin').remove();
      $av.find('.picky-element-avatar__img').removeClass('is-loading');
    };
    var showInitials = function(){
      clearSpin();
      $av.find('.picky-element-avatar__img').remove();
      if(!$av.find('.picky-element-avatar__ph').length){
        $av.prepend('<span class="picky-element-avatar__ph" aria-hidden="true">' + $('<div>').text(initials).html() + '</span>');
      }
      if(tip && !$av.find('.picky-element-avatar__tip').length){
        $av.append('<span class="picky-element-avatar__tip">' + $('<div>').text(tip).html() + '</span>');
      }
      if(tip){ $av.attr('aria-label', tip); }
    };

    /* Keep / paint loader first so it is visible before the image paints. */
    if(!$av.find('.picky-element-avatar__spin').length){
      pickyPrimeAvatarLoader($el);
    } else {
      $el.addClass('picky-has-booked-avatar');
      $av.prop('hidden', false).attr('aria-hidden', 'false').addClass('is-loading');
    }
    if(tip){
      $av.attr('aria-label', tip);
    }

    var paint = function(){
      if(!avatar){
        showInitials();
        pickyMountBookableAvatarOnShape($el);
        return;
      }
      $av.find('.picky-element-avatar__ph').remove();
      if(tip && !$av.find('.picky-element-avatar__tip').length){
        $av.append('<span class="picky-element-avatar__tip">' + $('<div>').text(tip).html() + '</span>');
      }
      var $img = $av.find('.picky-element-avatar__img');
      if(!$img.length){
        $img = $('<img class="picky-element-avatar__img is-loading" alt="" decoding="async" />');
        $av.append($img);
      } else {
        $img.addClass('is-loading');
      }
      $img.off('load.pickyAv error.pickyAv');
      $img.one('load.pickyAv', clearSpin);
      $img.one('error.pickyAv', showInitials);
      /* Force load event even for cache: clear src then set. */
      $img.attr('src', '');
      $img.attr('src', avatar);
      if($img[0].complete && $img[0].naturalWidth > 0){
        clearSpin();
      }
      pickyMountBookableAvatarOnShape($el);
    };

    /* Two frames: let the spinner paint on newly-disabled seats first. */
    if(window.requestAnimationFrame){
      window.requestAnimationFrame(function(){
        window.requestAnimationFrame(paint);
      });
    } else {
      window.setTimeout(paint, 32);
    }
  }

  function pickyUpdateBookedAvatars(wrap){
    if(!wrap || !wrap.length){ return; }
    if(!pickyAvatarsEnabled(wrap)){
      wrap.find('.picky-element').removeClass('picky-has-booked-avatar');
      wrap.find('.picky-element-avatar').prop('hidden', true).attr('aria-hidden', 'true').empty().removeClass('is-loading');
      return;
    }
    var slot = pickyGetCanvasBookingDateTime(wrap);
    var dt = slot.date;
    var timeVal = slot.time;
    wrap.find('.picky-draggable .picky-element').each(function(){
      var $el = $(this);
      var elType = ($el.attr('data-type') || '').toString();
      if(elType === 'type-wall' || elType === 'type-text' || elType === 'type-shape' || elType === 'type-icon'){ return; }
      var $av = $el.find('.picky-element-avatar').first();
      if(!$av.length){ return; }
      var guest = null;
      if($el.hasClass('disabled') && dt && timeVal){
        guest = pickyFindGuestForElement($el, wrap, dt, timeVal);
      }
      if(guest && (guest.name || guest.avatar || guest.initials)){
        pickyRenderBookedAvatar($av, guest);
      } else if($el.hasClass('disabled') && $el.hasClass('picky-has-booked-avatar') && $av.hasClass('is-loading')){
        /* Keep primed loader until guest payload arrives (AJAX occupants). */
      } else {
        $el.removeClass('picky-has-booked-avatar');
        $av.prop('hidden', true).attr('aria-hidden', 'true').empty().removeClass('is-loading').removeAttr('aria-label');
      }
    });
  }

  /** Run a deferred canvas refresh after slot-hint DOM updates (see pickySmartAvailRefreshPending). */
  function pickyFlushSmartAvailabilityRefresh(wrap){
    if(!wrap || !wrap.length || !wrap.hasClass('picky-tables-wrap-second')){ return; }
    var pending = wrap.data('pickySmartAvailRefreshPending');
    if(pending === undefined){ return; }
    wrap.removeData('pickySmartAvailRefreshPending');
    pickySyncSmartHiddenBookingTime(wrap);
    refreshAvailableTablesSecond(wrap, pending === true);
  }

  function pickyApplyAllowedSlotOptions(wrap, $sel, allowed){
    if(!$sel || !$sel.length || !Array.isArray(allowed)){ return; }
    wrap.data('pickyApplyingSlotHints', 1);
    try {
      var ph = $sel.attr('data-placeholder') || '';
      var prev = ($sel.val() || '').toString();
      /* Fall back to hidden / remembered / locked start so rebuilds don't wipe the clock. */
      if(!prev && ($sel.is('#picky_booking_time_fake') || $sel.is('#picky_booking_time'))){
        prev = (wrap.find('input#picky_booking_time').val() || '').toString();
        if(!prev && typeof pickyGetSmartBookingTime === 'function'){
          prev = (pickyGetSmartBookingTime(wrap) || '').toString();
        }
        if(!prev){
          prev = (wrap.data('pickyLockedBookingTime') || '').toString();
        }
      }
      var isStartSel = $sel.is('#picky_booking_time_fake') || $sel.is('#picky_booking_time');
      var locked = isStartSel ? (wrap.data('pickyLockedBookingTime') || '').toString() : '';
      if(isStartSel && !prev && locked){ prev = locked; }
      pickyStripSelect2Shell($sel);
      $sel.empty();
      $sel.append($('<option>', { value: '', text: ph || '' }));
      if(allowed.length === 0){
        /* Keep the user's start even when the filtered list is empty
           (e.g. longer service duration temporarily narrows valid_starts). */
        if(isStartSel && prev){
          $sel.append($('<option>', { value: prev, text: prev }));
          $sel.val(prev);
          wrap.find('input#picky_booking_time').val(prev);
          pickyLockSmartBookingTime(wrap, prev);
        }
        if(pickyIsSmartLayoutNativeTimeSelect(wrap, $sel) || $sel.closest('.picky-slot-select-fallback').length){
          $sel.addClass('picky-smart-time-native');
          pickyStripSelect2Shell($sel);
        } else {
          $sel.removeClass('picky-smart-time-native');
          $sel.select2(pickyBuildTimeSelect2Options($sel, ph));
        }
        pickySyncSmartTimeNativeEmptyClass($sel);
        return;
      }
      var i, v;
      for(i = 0; i < allowed.length; i++){
        v = String(allowed[i]);
        $sel.append($('<option>', { value: v, text: v }));
      }
      // Prefer wizard-stored selection when AJAX rebuilds options.
      var $wiz = pickyWizardRoot(wrap);
      var remembered = '';
      if ($sel.is('#picky_booking_time_to')) {
        remembered = ($wiz.attr('data-picky-selected-end') || '').toString();
      } else {
        remembered = ($wiz.attr('data-picky-selected-start') || locked || '').toString();
      }
      if(remembered && allowed.indexOf(remembered) !== -1){
        $sel.val(remembered);
      } else if(prev && allowed.indexOf(prev) !== -1){
        $sel.val(prev);
      } else if(isStartSel && prev){
        /* Preserve chosen start even if not in the new intersection list. */
        $sel.append($('<option>', { value: prev, text: prev }));
        $sel.val(prev);
      } else {
        $sel.val('');
        if ($sel.is('#picky_booking_time_to')) {
          $wiz.removeAttr('data-picky-selected-end');
        } else if (isStartSel) {
          $wiz.removeAttr('data-picky-selected-start');
        }
      }
      if(isStartSel && $sel.val()){
        wrap.find('input#picky_booking_time').val($sel.val());
        pickyLockSmartBookingTime(wrap, $sel.val());
        if($wiz.length){ $wiz.attr('data-picky-selected-start', $sel.val()); }
      }
      if(pickyIsSmartLayoutNativeTimeSelect(wrap, $sel) || $sel.closest('.picky-slot-select-fallback').length){
        $sel.addClass('picky-smart-time-native');
        pickyStripSelect2Shell($sel);
      } else {
        $sel.removeClass('picky-smart-time-native');
        $sel.select2(pickyBuildTimeSelect2Options($sel, ph));
      }
      pickySyncSmartTimeNativeEmptyClass($sel);
    } finally {
      setTimeout(function(){
        wrap.removeData('pickyApplyingSlotHints');
        wrap.data('pickySlotHintsReady', 1);
        wrap.removeData('pickySlotHintsLoading');
        pickyFlushSmartAvailabilityRefresh(wrap);
        pickySyncAllSlotGrids(wrap);
      }, 0);
    }
  }

  function pickyFetchIntervalSlotHints(wrap, timeStartStr){
    if(!pickyWrapUsesSlotHints(wrap)){ return; }
    var dt = wrap.find('#picky_booking_date').val();
    if(!dt){ return; }
    var ppl = parseInt(wrap.find('#picky_booking_people').val(), 10);
    if(isNaN(ppl) || ppl <= 0){ ppl = 1; }
    // All selected tables (comma-separated). Server requires every one free
    // for multi-select; a single last-unique was wrongly optimistic or empty.
    var hintsUnique = pickyHintsUniqueForRequest(wrap);
    var durationMin = (typeof pickyHintsDurationMinutes === 'function') ? pickyHintsDurationMinutes(wrap) : 0;
    var reqGen = (parseInt(wrap.data('pickySlotHintsGen'), 10) || 0) + 1;
    wrap.data('pickySlotHintsGen', reqGen);
    if ((pickyBookingFormUsesStepFlow(wrap) || wrap.find('.picky-slot-grid').length || pickyIsSmartMapLayout(wrap)) && !timeStartStr) {
      pickySlotGridSetLoading(wrap, true);
    }
    $.ajax({
      type: 'POST',
      url: (window.pickyAjax && window.pickyAjax.url) ? window.pickyAjax.url : '',
      dataType: 'json',
      data: {
        action: 'picky_interval_slot_hints',
        _wpnonce: wrap.find('input[name="_wpnonce"]').val(),
        picky_layout_id: wrap.find('input[name="picky_layout_id"]').val() || '',
        date: dt,
        time_start: timeStartStr || '',
        people: ppl,
        hints_unique: hintsUnique,
        duration_min: durationMin > 0 ? durationMin : ''
      },
      success: function(resp){
        if (reqGen !== wrap.data('pickySlotHintsGen')) { return; }
        if(!resp || !resp.success || !resp.data){
          wrap.removeData('pickySlotHintsLoading');
          wrap.removeData('pickySlotHintsSkipCanvas');
          wrap.data('pickySlotHintsReady', 1);
          pickySlotGridSetLoading(wrap, false);
          pickySyncAllSlotGrids(wrap);
          return;
        }
        var vs = resp.data.valid_starts || [];
        var ve = resp.data.valid_ends || [];
        // When a start is already chosen (timeStartStr), only refresh end options /
        // keep that start — rebuilding valid_starts would clear the user's time
        // (services duration / multi-select intersection).
        if (timeStartStr) {
          wrap.find('select#picky_booking_time_fake').each(function(){
            pickyEnsureStartTimeOption(wrap, $(this), timeStartStr);
          });
          wrap.find('select#picky_booking_time').each(function(){
            var $t = $(this);
            if ($t.attr('id') === 'picky_booking_time_fake') { return; }
            pickyEnsureStartTimeOption(wrap, $t, timeStartStr);
          });
          wrap.find('input#picky_booking_time').val(timeStartStr);
          pickyLockSmartBookingTime(wrap, timeStartStr);
          if (pickyWrapHasIntervalBooking(wrap)) {
            wrap.find('select#picky_booking_time_to').each(function(){
              pickyApplyAllowedSlotOptions(wrap, $(this), ve);
            });
          }
          wrap.data('pickySlotHintsReady', 1);
          wrap.removeData('pickySlotHintsLoading');
          pickySlotGridSetLoading(wrap, false);
          pickySyncAllSlotGrids(wrap);
        } else {
          // Server valid_starts is the source of truth (work hours + duration +
          // buffers + selected tables). Do not paint client options first.
          wrap.find('select#picky_booking_time_fake').each(function(){
            pickyApplyAllowedSlotOptions(wrap, $(this), vs);
          });
          wrap.find('select#picky_booking_time').each(function(){
            var $t = $(this);
            if($t.attr('id') === 'picky_booking_time_fake'){ return; }
            pickyApplyAllowedSlotOptions(wrap, $t, vs);
          });
          if(pickyWrapHasIntervalBooking(wrap)){
            wrap.find('select#picky_booking_time_to').each(function(){
              var $t = $(this);
              var cached = $t.data('picky-orig-html');
              if(typeof cached === 'undefined' || cached === ''){ return; }
              wrap.data('pickyApplyingSlotHints', 1);
              try {
                pickyStripSelect2Shell($t);
                $t.html(cached);
                $t.val('');
                if(pickyIsSmartLayoutNativeTimeSelect(wrap, $t)){
                  $t.addClass('picky-smart-time-native');
                  pickySyncSmartTimeNativeEmptyClass($t);
                } else {
                  $t.removeClass('picky-smart-time-native');
                  $t.select2(pickyBuildTimeSelect2Options($t, $t.attr('data-placeholder') || ''));
                }
              } finally {
                setTimeout(function(){ wrap.removeData('pickyApplyingSlotHints'); }, 0);
              }
            });
          }
        }
        if(wrap.hasClass('picky-tables-wrap-second')){
          wrap.removeData('pickySmartAvailRefreshPending');
          pickySyncSmartHiddenBookingTime(wrap);
          /* Service toggles only need slot/end options refreshed — not a
             canvas reload (that flashes the floor plan behind the panel). */
          if (wrap.data('pickySlotHintsSkipCanvas')) {
            wrap.removeData('pickySlotHintsSkipCanvas');
          } else {
            refreshAvailableTablesSecond(wrap, false);
          }
        } else {
          wrap.removeData('pickySlotHintsSkipCanvas');
        }
      },
      error: function(){
        if (reqGen !== wrap.data('pickySlotHintsGen')) { return; }
        wrap.removeData('pickySlotHintsLoading');
        wrap.removeData('pickySlotHintsSkipCanvas');
        wrap.data('pickySlotHintsReady', 1);
        pickySlotGridSetLoading(wrap, false);
        pickySyncAllSlotGrids(wrap);
      }
    });
  }

  // Legacy client-side disable (per-element data-time); used as AJAX fallback.
  function reInitElementsSecond(wrap, datapicker){
    if(!datapicker || !datapicker.length){ datapicker = wrap.find('.picky-datepicker'); }
    pickyApplySmartCanvasAvailability(wrap, null);
    wrap.find('.picky-draggable').removeClass('picky-loading');
  }

  // Smart layout: ask backend for available tables and disable others.
  // This keeps frontend consistent with server rules (duration, before/after buffer, capacity mode).
  function refreshAvailableTablesSecond(wrap, scrollToFloor){
    if(!wrap || wrap.length === 0){ return; }
    if(!wrap.hasClass('picky-tables-wrap-second')){ return; }
    var doScroll = scrollToFloor === true;

    var dt = wrap.find('#picky_booking_date').val();
    var tm = pickyGetSmartBookingTime(wrap);
    if(tm){
      wrap.find('input#picky_booking_time').val(tm);
    }
    var tmTo = wrap.find('#picky_booking_time_to').val();
    var ppl = parseInt(wrap.find('#picky_booking_people').val(), 10);
    if(isNaN(ppl) || ppl <= 0){ ppl = 1; }

    // If date/time not picked yet, don't disable anything.
    if(!dt || !tm){
      wrap.find('.picky-draggable .picky-element').removeClass('disabled');
      pickyUpdateBookedAvatars(wrap);
      pickySmartPickHintsClear(wrap);
      pickySmartAvailabilityNoteSet(wrap, false);
      if(!pickyIsSmartMapLayout(wrap)){
        wrap.removeClass('picky-smart-table-picker-visible');
      }
      return;
    }

    // Immediate disable from embedded data-time (legacy Smart layout UX).
    pickyApplyCanvasAvailability(wrap, null);

    var _prevReq = wrap.data('pickySmartReq');
    if(_prevReq && _prevReq.abort){ _prevReq.abort(); }

    wrap.find('.picky-draggable').addClass('picky-loading');
    wrap.data('pickySmartReq', $.ajax({
      type: "POST",
      url: (window.pickyAjax && window.pickyAjax.url) ? window.pickyAjax.url : '',
      dataType: 'json',
      data: {
        action: 'picky_get_available_tables',
        _wpnonce: wrap.find('input[name="_wpnonce"]').val(),
        picky_layout_id: wrap.find('input[name="picky_layout_id"]').val() || '',
        date: dt,
        time: tm,
        time_to: tmTo || '',
        people: ppl
      },
      success: function(resp){
        var tables = (resp && resp.success && resp.data && resp.data.tables) ? resp.data.tables : [];
        var capacityMap = (resp && resp.success && resp.data && resp.data.capacity) ? resp.data.capacity : {};
        wrap.data('pickyCapacityByUnique', capacityMap);
        if(resp && resp.success && resp.data && Array.isArray(resp.data.occupants)){
          wrap.data('pickyOccupants', resp.data.occupants);
        }
        var allowed = {};
        var buMap   = {};
        for(var i=0;i<tables.length;i++){
          if(tables[i] && tables[i].unique){
            var u = String(tables[i].unique);
            allowed[u] = true;
            allowed[u.toLowerCase()] = true;
            if(tables[i].bookable_until){
              buMap[u] = tables[i].bookable_until;
              buMap[u.toLowerCase()] = tables[i].bookable_until;
            }
          }
        }
        wrap.data('pickyBookableUntilByUnique', buMap);
        pickyApplyCanvasAvailability(wrap, allowed);
        pickyUpdateSelectedCapacityInfo(wrap, capacityMap);
        pickyPruneUnavailableSelectedItems(wrap);
        pickyShowTimeLimitNotice(wrap, pickyGetBookableUntilForSelected(wrap));
        pickySmartPickHintsApply(wrap);
        pickyUpdateBookedAvatars(wrap);
        if(doScroll){
          pickySmartScrollToAvailabilityNote(wrap);
        }
      },
      error: function(){
        // Fallback: legacy client-side disabling if AJAX fails
        try { reInitElementsSecond(wrap, wrap.find('.picky-datepicker')); } catch(e) {}
        pickySmartPickHintsApply(wrap);
      },
      complete: function(){
        wrap.find('.picky-draggable').removeClass('picky-loading');
        try { applyCanvasScaleToContainer(); } catch(e) {}
      }
    }));
  }

  /**
   * Classic / Third: mark booked canvas items for chosen date/time (avatars + .disabled).
   * Uses embedded data-time immediately, then get_available_tables for buffer/overlap rules.
   */
  function refreshAvailableTablesClassic(wrap){
    if(!wrap || !wrap.length){ return; }
    if(wrap.hasClass('picky-tables-wrap-second')){ return; }
    if(!wrap.hasClass('picky-tables-wrap-default') && !wrap.hasClass('picky-tables-wrap-third')){ return; }

    var slot = pickyGetCanvasBookingDateTime(wrap);
    var dt = slot.date;
    var tm = slot.time;
    var tmTo = wrap.find('#picky_booking_time_to').val() || '';
    var ppl = parseInt(wrap.find('#picky_booking_people').val(), 10);
    if(isNaN(ppl) || ppl <= 0){ ppl = 1; }

    if(!dt || !tm){
      wrap.find('.picky-draggable .picky-element').removeClass('disabled');
      pickyUpdateBookedAvatars(wrap);
      return;
    }

    pickyApplyCanvasAvailability(wrap, null);

    var _prevReq = wrap.data('pickyClassicAvailReq');
    if(_prevReq && _prevReq.abort){ _prevReq.abort(); }

    wrap.data('pickyClassicAvailReq', $.ajax({
      type: 'POST',
      url: (window.pickyAjax && window.pickyAjax.url) ? window.pickyAjax.url : '',
      dataType: 'json',
      data: {
        action: 'picky_get_available_tables',
        _wpnonce: wrap.find('input[name="_wpnonce"]').val(),
        picky_layout_id: wrap.find('input[name="picky_layout_id"]').val() || '',
        date: dt,
        time: tm,
        time_to: tmTo || '',
        people: ppl
      },
      success: function(resp){
        var tables = (resp && resp.success && resp.data && resp.data.tables) ? resp.data.tables : [];
        var capacityMap = (resp && resp.success && resp.data && resp.data.capacity) ? resp.data.capacity : {};
        wrap.data('pickyCapacityByUnique', capacityMap);
        if(resp && resp.success && resp.data && Array.isArray(resp.data.occupants)){
          wrap.data('pickyOccupants', resp.data.occupants);
        }
        var allowed = {};
        var buMap = {};
        for(var i = 0; i < tables.length; i++){
          if(tables[i] && tables[i].unique){
            var u = String(tables[i].unique);
            allowed[u] = true;
            allowed[u.toLowerCase()] = true;
            if(tables[i].bookable_until){
              buMap[u] = tables[i].bookable_until;
              buMap[u.toLowerCase()] = tables[i].bookable_until;
            }
          }
        }
        wrap.data('pickyBookableUntilByUnique', buMap);
        pickyApplyCanvasAvailability(wrap, allowed);
        pickyUpdateSelectedCapacityInfo(wrap, capacityMap);
        pickyShowTimeLimitNotice(wrap, pickyGetBookableUntilForSelected(wrap));
      },
      error: function(){
        pickyApplyCanvasAvailability(wrap, null);
      }
    }));
  }

  // pick time(second type layout)
  // Use event delegation so ALL layout instances get this handler (multiple shortcodes = duplicate IDs).
  $(document).on('change', '#picky_booking_time_fake', function(){
    pickySyncSmartTimeNativeEmptyClass($(this));
    var wrap = $(this).closest('.picky-tables-wrap');
    var tm = $(this).val() || '';
    wrap.find('#picky_booking_time').val(tm);
    pickyLockSmartBookingTime(wrap, tm);
    if(pickyIsSmartMapLayout(wrap)){
      pickySyncSmartMapBookButton(wrap);
    }
    var userTimeStep = !pickySuppressSmartTableReveal;
    if(wrap.hasClass('picky-tables-wrap-second')){
      if(tm){
        pickyApplySmartCanvasAvailability(wrap, null);
      }
      if(wrap.data('pickyApplyingSlotHints')){
        wrap.data('pickySmartAvailRefreshPending', userTimeStep);
        return;
      }
      if(userTimeStep){
        wrap.addClass('picky-smart-table-picker-visible');
        setTimeout(function(){
          try { applyCanvasScaleToContainer(); } catch(e) {}
        }, 120);
      }
      wrap.find('#picky_booking_time').trigger('change');
      if(pickyWrapUsesSlotHints(wrap)){
        // Slot hints finish with refreshAvailableTablesSecond — avoid parallel refresh clearing state.
        pickyFetchIntervalSlotHints(wrap, tm);
      } else {
        refreshAvailableTablesSecond(wrap, userTimeStep);
      }
    }
  });

  $(document).on('change', '#picky_booking_time', function(){
    var wrap = $(this).closest('.picky-tables-wrap');
    if(wrap.data('pickyApplyingSlotHints')){ return; }
    if(!pickyWrapUsesSlotHints(wrap)){ return; }
    if(wrap.hasClass('picky-tables-wrap-second')){ return; }
    pickyFetchIntervalSlotHints(wrap, $(this).val() || '');
  });

  // End time affects server-side interval overlap; refresh canvas when user changes it (desktop smart layout had no handler).
  $(document).on('change', '#picky_booking_time_to', function(){
    pickySyncSmartTimeNativeEmptyClass($(this));
    var wrap = $(this).closest('.picky-tables-wrap');
    pickyFetchSelectedCapacityInfo(wrap);
    if(wrap.hasClass('picky-tables-wrap-second')){
      refreshAvailableTablesSecond(wrap, false);
    } else if(wrap.hasClass('picky-tables-wrap-default') || wrap.hasClass('picky-tables-wrap-third')){
      refreshAvailableTablesClassic(wrap);
    }
  });

  $(document).on('change', '#picky_booking_date, #picky_booking_time', function(){
    var wrap = $(this).closest('.picky-tables-wrap');
    pickyFetchSelectedCapacityInfo(wrap);
    if(wrap.hasClass('picky-tables-wrap-default') || wrap.hasClass('picky-tables-wrap-third')){
      refreshAvailableTablesClassic(wrap);
    }
  });


  /* ===== Multi-select state management ===== */

  /**
   * Per-layout selected items state.
   * Key: a jQuery object index (wrap's data key 'pickySelectedItems').
   * Value: array of { unique, id, label, maxSeats, services, defaultService, selectedServiceIds }
   */

  function pickyGetSelectedItems(wrap) {
    return wrap.data('pickySelectedItems') || [];
  }

  function pickySetSelectedItems(wrap, items) {
    wrap.data('pickySelectedItems', items);
  }

  /**
   * Display ID for a canvas element: prefer the admin-set custom ID
   * (data-custom-id) when present, otherwise fall back to data-id.
   * This matches the badge rendered on the canvas in class-public.php
   * (see "picky-element-id" usage) so the booking form and the visible
   * tile stay in sync after the user renames a table in the builder.
   */
  function pickyItemDisplayId($el) {
    if (!$el || !$el.length) { return ''; }
    var custom = ($el.attr('data-custom-id') || '').toString().trim();
    if (custom !== '') { return custom; }
    return ($el.attr('data-id') || '').toString();
  }

  function pickyParseItemServices(attr) {
    if (!attr) { return []; }
    try { var p = JSON.parse(attr); return Array.isArray(p) ? p : []; } catch(e) { return []; }
  }

  function pickyCurrencySymbol(wrap) {
    return wrap.attr('data-picky-currency') || wrap.data('pickyCurrency') || '';
  }

  /** Expected charge when services total is 0 (fixed / services_or_fixed fallback). */
  function pickyExpectedPaymentFallback(wrap, itemCount) {
    var required = String(wrap.attr('data-picky-pay-required') || '') === '1';
    if (!required) { return 0; }
    var src = String(wrap.attr('data-picky-pay-source') || 'fixed');
    var deposit = parseFloat(wrap.attr('data-picky-deposit')) || 0;
    if (deposit <= 0) { return 0; }
    var n = Math.max(1, parseInt(itemCount, 10) || 1);
    if (src === 'fixed' || src === 'services_or_fixed') {
      return deposit * n;
    }
    return 0;
  }

  function pickyFormatMoney(currency, amount) {
    var n = Math.max(0, parseFloat(amount) || 0);
    return (currency || '') + n.toFixed(2);
  }

  /**
   * Split a service array into parents (top-level) and add-ons. Strict 2-level
   * model: a service is an add-on iff it carries non-empty parent_sids[].
   */
  function pickyPartitionServices(services) {
    var parents = [], addons = [];
    for (var i = 0; i < services.length; i++) {
      var svc = services[i];
      var hasParents = Array.isArray(svc.parent_sids) && svc.parent_sids.length > 0;
      if (hasParents) { addons.push(svc); } else { parents.push(svc); }
    }
    return { parents: parents, addons: addons };
  }

  /**
   * Build the per-item row HTML for the selected items list.
   */
  function pickyItemUnitPrice(item, wrap) {
    var total = 0;
    var sids = item.selectedServiceIds || [];
    var services = item.services || [];
    for (var j = 0; j < services.length; j++) {
      if (sids.indexOf(services[j].sid) >= 0) {
        total += parseFloat(services[j].price) || 0;
      }
    }
    if (total > 0) { return total; }
    if (!wrap || !wrap.length) { return 0; }
    var required = String(wrap.attr('data-picky-pay-required') || '') === '1';
    if (!required) { return 0; }
    var src = String(wrap.attr('data-picky-pay-source') || 'fixed');
    var deposit = parseFloat(wrap.attr('data-picky-deposit')) || 0;
    if (deposit > 0 && (src === 'fixed' || src === 'services_or_fixed')) {
      return deposit;
    }
    return 0;
  }

  /** Collect checked service SIDs for an item from row + services panel. */
  function pickyCollectSelectedSids(wrap, unique) {
    var sids = [];
    var seen = {};
    function add(v) {
      v = String(v || '');
      if (!v || seen[v]) { return; }
      seen[v] = true;
      sids.push(v);
    }
    wrap.find('.picky-selected-item-row[data-unique="' + unique + '"] .picky-svc-checkbox:checked').each(function() {
      var $cb = $(this);
      var $addonGrp = $cb.closest('.picky-svc-addons, .picky-svc-card-addons');
      if ($addonGrp.length && ($addonGrp.prop('hidden') || $addonGrp.css('display') === 'none')) { return; }
      add($cb.val());
    });
    wrap.find('.picky-bottom-svc-checkbox[data-unique="' + unique + '"]:checked').each(function() {
      var $cb = $(this);
      var $addonGrp = $cb.closest('.picky-svc-addons, .picky-svc-card-addons');
      if ($addonGrp.length && ($addonGrp.prop('hidden') || $addonGrp.css('display') === 'none')) { return; }
      add($cb.val());
    });
    return sids;
  }

  function pickyBiconHtml(key) {
    var payload = (typeof pickyAjax !== 'undefined' && pickyAjax.bookingIcons) ? pickyAjax.bookingIcons : null;
    key = String(key || '').trim();
    if (!payload || !key) { return ''; }
    var icons = payload.icons || {};
    if (!icons[key]) { return ''; }
    var symbol = icons[key].symbol || ('picky-bicon-' + key);
    var href = '#' + symbol;
    return '<svg class="picky-bicon" aria-hidden="true" focusable="false"><use href="' + href + '" xlink:href="' + href + '"></use></svg>';
  }

  function pickyBuildSvcCardHtml(item, svc, isAddon, currency, selectedIds, cbClass) {
    var checked = selectedIds.indexOf(svc.sid) >= 0 ? ' checked' : '';
    var isOn = checked.indexOf('checked') >= 0;
    var priceLabel = svc.price > 0
      ? (currency || '$') + Number(svc.price).toFixed(2) + (svc.duration ? ' / ' + svc.duration + ' min' : '')
      : (svc.duration ? svc.duration + ' min' : '');
    var addonCls = isAddon ? ' ' + cbClass + '-addon' : '';
    var parentsAttr = isAddon ? ' data-parent-sids="' + pickyEscAttr(JSON.stringify(svc.parent_sids || [])) + '"' : '';
    var iconHtml = pickyBiconHtml(svc.icon || '');

    if (isAddon) {
      var h = '<label class="picky-svc-addon-row' + (isOn ? ' is-selected' : '') + '">';
      h += '<input type="checkbox" class="' + cbClass + addonCls + '" data-unique="' + pickyEscAttr(item.unique) + '" value="' + pickyEscAttr(svc.sid) + '"' + checked + parentsAttr + '>';
      if (iconHtml) { h += '<span class="picky-svc-bicon">' + iconHtml + '</span>'; }
      h += '<span class="picky-svc-addon-name">' + pickyEscHtml(svc.name) + '</span>';
      if (priceLabel) {
        h += '<span class="picky-svc-addon-meta">' + pickyEscHtml(priceLabel) + '</span>';
      }
      h += '</label>';
      return h;
    }

    var card = '<div class="picky-svc-card' + (isOn ? ' is-selected' : '') + '" data-parent-sid="' + pickyEscAttr(svc.sid) + '">';
    card += '<label class="picky-svc-card-main">';
    card += '<input type="checkbox" class="' + cbClass + '" data-unique="' + pickyEscAttr(item.unique) + '" value="' + pickyEscAttr(svc.sid) + '"' + checked + '>';
    if (iconHtml) { card += '<span class="picky-svc-bicon">' + iconHtml + '</span>'; }
    card += '<span class="picky-svc-card-text">';
    card += '<span class="picky-svc-card-name">' + pickyEscHtml(svc.name) + '</span>';
    if (priceLabel) {
      card += '<span class="picky-svc-card-meta">' + pickyEscHtml(priceLabel) + '</span>';
    }
    card += '</span>';
    card += '</label>';
    return card;
  }

  function pickyBuildServicesCardsHtml(item, currency, cbClass) {
    var services = item.services || [];
    if (!services.length) { return ''; }
    var selectedIds = (item.selectedServiceIds || []).slice();
    var split = pickyPartitionServices(services);
    var html = '';
    for (var i = 0; i < split.parents.length; i++) {
      var par = split.parents[i];
      var parChecked = selectedIds.indexOf(par.sid) >= 0 ? ' checked' : '';
      var parOn = parChecked.indexOf('checked') >= 0;
      var parPrice = par.price > 0
        ? (currency || '$') + Number(par.price).toFixed(2) + (par.duration ? ' / ' + par.duration + ' min' : '')
        : (par.duration ? par.duration + ' min' : '');
      var parIcon = pickyBiconHtml(par.icon || '');
      html += '<div class="picky-svc-card' + (parOn ? ' is-selected' : '') + '" data-parent-sid="' + pickyEscAttr(par.sid) + '">';
      html += '<label class="picky-svc-card-main">';
      html += '<input type="checkbox" class="' + cbClass + '" data-unique="' + pickyEscAttr(item.unique) + '" value="' + pickyEscAttr(par.sid) + '"' + parChecked + '>';
      if (parIcon) { html += '<span class="picky-svc-bicon">' + parIcon + '</span>'; }
      html += '<span class="picky-svc-card-text">';
      html += '<span class="picky-svc-card-name">' + pickyEscHtml(par.name) + '</span>';
      if (parPrice) { html += '<span class="picky-svc-card-meta">' + pickyEscHtml(parPrice) + '</span>'; }
      html += '</span></label>';

      var addonHtml = '';
      for (var a = 0; a < split.addons.length; a++) {
        var addon = split.addons[a];
        var pids = addon.parent_sids || [];
        if (pids.indexOf(par.sid) < 0) { continue; }
        addonHtml += pickyBuildSvcCardHtml(item, addon, true, currency, selectedIds, cbClass);
      }
      if (addonHtml) {
        html += '<div class="picky-svc-card-addons"' + (parOn ? '' : ' hidden') + ' data-parent-sid="' + pickyEscAttr(par.sid) + '">';
        html += addonHtml;
        html += '</div>';
      }
      html += '</div>';
    }
    return html;
  }

  function pickyBuildItemRow(item, currency, skipServices, wrap) {
    var services = skipServices ? [] : (item.services || []);
    var rowDisplayId = (item.displayId !== undefined && item.displayId !== null && item.displayId !== '')
      ? item.displayId
      : item.id;
    var itemIconKey = wrap && wrap.length ? String(wrap.attr('data-booking-item-icon') || '') : '';
    var itemIconHtml = pickyBiconHtml(itemIconKey);
    var html = '<div class="picky-selected-item-row picky-tile picky-item-chip is-selected" data-unique="' + pickyEscAttr(item.unique) + '">';
    html += '<button type="button" class="picky-deselect-item-btn" data-unique="' + pickyEscAttr(item.unique) + '" title="' + pickyEscAttr('Remove') + '" aria-label="' + pickyEscAttr('Remove') + '">&times;</button>';
    html += '<div class="picky-selected-item-header">';
    if (itemIconHtml) { html += '<span class="picky-item-bicon">' + itemIconHtml + '</span>'; }
    html += '<span class="picky-selected-item-label picky-tile-label picky-item-chip-id">id: ' + pickyEscHtml(String(rowDisplayId)) + (item.label ? ' ' + pickyEscHtml(item.label) : '') + '</span>';
    html += '</div>';
    if (services.length > 0) {
      html += '<div class="picky-selected-item-services picky-tile-services">';
      html += pickyBuildServicesCardsHtml(item, currency, 'picky-svc-checkbox');
      html += '</div>';
    }
    html += '</div>';
    return html;
  }

  function pickyUpdateWizardTotal(wrap) {
    var items = pickyGetSelectedItems(wrap);
    var currency = pickyCurrencySymbol(wrap);
    var $totalWrap = wrap.find('.picky-order-total-wrap').last();
    if (!$totalWrap.length) { return; }
    if (!items.length) {
      $totalWrap.removeClass('is-visible').hide();
      return;
    }
    var src = String(wrap.attr('data-picky-pay-source') || 'fixed');
    var deposit = parseFloat(wrap.attr('data-picky-deposit')) || 0;
    var required = String(wrap.attr('data-picky-pay-required') || '') === '1';
    var servicesSum = 0;
    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      var sids = pickyCollectSelectedSids(wrap, item.unique);
      if (!sids.length && item.selectedServiceIds && item.selectedServiceIds.length) {
        sids = item.selectedServiceIds.slice();
      }
      var services = item.services || [];
      for (var j = 0; j < services.length; j++) {
        if (sids.indexOf(String(services[j].sid)) >= 0) {
          servicesSum += parseFloat(services[j].price) || 0;
        }
      }
    }
    var total = 0;
    if (src === 'fixed') {
      total = (required && deposit > 0) ? deposit * items.length : servicesSum;
    } else if (src === 'services') {
      total = servicesSum;
    } else {
      // services_or_fixed: selected services win; otherwise deposit per item.
      total = servicesSum > 0
        ? servicesSum
        : ((required && deposit > 0) ? deposit * items.length : 0);
    }
    // Always show a total once items are booked (even $0.00) so the strip is never blank.
    $totalWrap.find('.picky-order-total').text(pickyFormatMoney(currency, total));
    $totalWrap.addClass('is-visible').show();
  }

  /** Rebuild hidden picky_items[*][service_ids] from current checkbox state. */
  function pickySyncItemsHiddenFields(wrap) {
    var items = pickyGetSelectedItems(wrap);
    var $container = wrap.find('#picky_items_container');
    if (!$container.length) { return; }
    $container.empty();
    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      var sids = pickyCollectSelectedSids(wrap, item.unique);
      if (!sids.length && item.selectedServiceIds && item.selectedServiceIds.length) {
        sids = item.selectedServiceIds.slice();
      }
      item.selectedServiceIds = sids;
      $container.append('<input type="hidden" name="picky_items[' + i + '][unique]" value="' + pickyEscAttr(item.unique) + '">');
      $container.append('<input type="hidden" name="picky_items[' + i + '][id]" value="' + pickyEscAttr(String(item.id)) + '">');
      var didPost = (item.displayId !== undefined && item.displayId !== null && item.displayId !== '')
        ? item.displayId
        : item.id;
      $container.append('<input type="hidden" name="picky_items[' + i + '][display_id]" value="' + pickyEscAttr(String(didPost)) + '">');
      $container.append('<input type="hidden" name="picky_items[' + i + '][service_id]" class="picky-items-svc-hidden" data-unique="' + pickyEscAttr(item.unique) + '" value="' + pickyEscAttr(sids[0] || '') + '">');
      for (var k = 0; k < sids.length; k++) {
        $container.append('<input type="hidden" name="picky_items[' + i + '][service_ids][]" value="' + pickyEscAttr(sids[k]) + '">');
      }
    }
    pickySetSelectedItems(wrap, items);
  }

  function pickyEscAttr(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function pickyEscHtml(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /** ===== Services Library helpers ===== */
  function pickyGetLibrary(wrap) {
    var raw = wrap.attr('data-services-library') || '[]';
    try { var p = JSON.parse(raw); return Array.isArray(p) ? p : []; } catch(e) { return []; }
  }
  function pickyIsMultiSelect(wrap) {
    return String(wrap.attr('data-multi-select') || '0') === '1';
  }
  function pickyResolveLibSvc(wrap, sid) {
    var lib = pickyGetLibrary(wrap);
    for (var i = 0; i < lib.length; i++) { if (lib[i].sid === sid) return lib[i]; }
    return null;
  }
  /**
   * Longest selected-services duration (minutes) across chosen items.
   * Used so slot hints reject starts that cannot fit work hours for the booking.
   */
  function pickyHintsDurationMinutes(wrap) {
    if (!wrap || !wrap.length) { return 0; }
    var items = (typeof pickyGetSelectedItems === 'function') ? pickyGetSelectedItems(wrap) : [];
    var maxDur = 0;
    var i, j, sids, dur, svc;
    for (i = 0; i < items.length; i++) {
      sids = items[i].selectedServiceIds || [];
      if ((!sids || !sids.length) && typeof pickyCollectSelectedSids === 'function') {
        sids = pickyCollectSelectedSids(wrap, items[i].unique);
      }
      dur = 0;
      for (j = 0; j < sids.length; j++) {
        svc = pickyResolveLibSvc(wrap, sids[j]);
        if (svc && svc.duration) {
          dur += parseInt(svc.duration, 10) || 0;
        }
      }
      if (dur > maxDur) { maxDur = dur; }
    }
    return maxDur > 0 ? maxDur : 0;
  }
  function pickyItemAllowedSids($el) {
    var raw = $el.attr('data-service-ids') || '[]';
    try { var p = JSON.parse(raw); return Array.isArray(p) ? p : []; } catch(e) { return []; }
  }
  function pickyItemAllowedServices(wrap, $el) {
    var sids = pickyItemAllowedSids($el);
    var lib = pickyGetLibrary(wrap);
    // Empty per-table assignment → offer the whole layout library.
    if ((!sids || sids.length === 0) && lib.length > 0) {
      sids = [];
      for (var li = 0; li < lib.length; li++) {
        if (lib[li] && lib[li].sid) { sids.push(String(lib[li].sid)); }
      }
    }
    var out = [];
    for (var i = 0; i < sids.length; i++) {
      var svc = pickyResolveLibSvc(wrap, sids[i]);
      if (svc) out.push(svc);
    }
    return out;
  }
  /** ===== End library helpers ===== */

  /**
   * Get the currently-selected service ids for an item (row + services panel).
   */
  function pickyGetSelectedServiceIdsForItem(wrap, unique) {
    return pickyCollectSelectedSids(wrap, unique);
  }

  function pickyGetSelectedServiceForItem(wrap, unique) {
    var ids = pickyGetSelectedServiceIdsForItem(wrap, unique);
    return ids[0] || '';
  }

  /**
   * Re-render the selected items summary, update totals, and sync hidden fields.
   */
  function pickyRenderSelectedItemsList(wrap) {
    var items = pickyGetSelectedItems(wrap);
    var currency = pickyCurrencySymbol(wrap);
    var $list = wrap.find('#picky_selected_items_list');
    var $summaryWrap = wrap.find('.picky-items-summary-wrap');
    var $totalWrap = wrap.find('.picky-order-total-wrap');
    var $container = wrap.find('#picky_items_container');

    if (!$list.length) { return; }

    // Capture currently-checked services from DOM BEFORE emptying. Pull from
    // BOTH the per-row form checkboxes AND the bottom services panel so that
    // single-select / wizard mode doesn't lose the user's selection on re-render.
    var domSidsMap = {};
    $list.find('.picky-selected-item-row').each(function(){
      var uq = $(this).data('unique');
      var sids = [];
      $(this).find('.picky-svc-checkbox:checked').each(function(){
        var $cb = $(this);
        var $addonGrp = $cb.closest('.picky-svc-addons, .picky-svc-card-addons');
        if ($addonGrp.length && ($addonGrp.prop('hidden') || $addonGrp.css('display') === 'none')) { return; }
        sids.push(String($cb.val()));
      });
      domSidsMap[uq] = sids;
    });
    wrap.find('.picky-bottom-svc-checkbox').each(function(){
      var uq = String($(this).data('unique'));
      if (!Object.prototype.hasOwnProperty.call(domSidsMap, uq)) {
        domSidsMap[uq] = [];
      }
    });
    wrap.find('.picky-bottom-svc-checkbox:checked').each(function(){
      var $cb = $(this);
      var $addonGrp = $cb.closest('.picky-svc-addons, .picky-svc-card-addons');
      if ($addonGrp.length && ($addonGrp.prop('hidden') || $addonGrp.css('display') === 'none')) { return; }
      var uq = String($cb.data('unique'));
      var v = String($cb.val());
      if (!domSidsMap[uq]) { domSidsMap[uq] = []; }
      if (domSidsMap[uq].indexOf(v) < 0) { domSidsMap[uq].push(v); }
    });

    $list.empty();
    $container.empty();

    if (items.length === 0) {
      $summaryWrap.hide();
      $totalWrap.removeClass('is-visible').hide();
      wrap.find('.picky-services-panel-wrap').hide();
      wrap.find('.picky-booking-meta-compact').hide();
      pickyUpdateBookingFormTitle(wrap);
      return;
    }

    $summaryWrap.show();
    pickyUpdateBookingFormTitle(wrap);

    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      // Merge DOM-captured selections into item state (preserves user checks across re-renders)
      if (Object.prototype.hasOwnProperty.call(domSidsMap, item.unique)) {
        item.selectedServiceIds = domSidsMap[item.unique];
      } else if (!item.selectedServiceIds || item.selectedServiceIds.length === 0) {
        item.selectedServiceIds = item.defaultService ? [item.defaultService] : [];
      }

      // Wizard: services always render in the panel below items (clearer UX).
      var useBottomPanel = pickyBookingFormUsesStepFlow(wrap) || (!pickyIsMultiSelect(wrap) && items.length === 1);
      $list.append(pickyBuildItemRow(item, currency, useBottomPanel, wrap));

      var sids = (item.selectedServiceIds || []).slice();
      $container.append('<input type="hidden" name="picky_items[' + i + '][unique]" value="' + pickyEscAttr(item.unique) + '">');
      $container.append('<input type="hidden" name="picky_items[' + i + '][id]" value="' + pickyEscAttr(String(item.id)) + '">');
      var didPost = (item.displayId !== undefined && item.displayId !== null && item.displayId !== '')
        ? item.displayId
        : item.id;
      $container.append('<input type="hidden" name="picky_items[' + i + '][display_id]" value="' + pickyEscAttr(String(didPost)) + '">');
      var legacySvcId = (sids && sids[0]) ? sids[0] : '';
      $container.append('<input type="hidden" name="picky_items[' + i + '][service_id]" class="picky-items-svc-hidden" data-unique="' + pickyEscAttr(item.unique) + '" value="' + pickyEscAttr(legacySvcId) + '">');
      for (var k = 0; k < sids.length; k++) {
        $container.append('<input type="hidden" name="picky_items[' + i + '][service_ids][]" value="' + pickyEscAttr(sids[k]) + '">');
      }
    }

    pickySetSelectedItems(wrap, items);
    pickyUpdateWizardTotal(wrap);
  }

  /**
   * Render services panel: parent service cards with optional add-on checkboxes inside.
   * Used by the wizard (all items) and single-select non-wizard (1 item).
   */
  function pickyRenderBottomServicesPanel(wrap) {
    var items = pickyGetSelectedItems(wrap);
    var $panel = wrap.find('#picky_services_panel');
    var $wrapPanel = wrap.find('.picky-services-panel-wrap');
    // Legacy "Services total" strip — always hide; one Total at the bottom instead.
    wrap.find('.picky-services-total-wrap').hide();
    if (!$panel.length) {
      pickyUpdateWizardTotal(wrap);
      return;
    }

    var useWizard = pickyBookingFormUsesStepFlow(wrap);
    var allowPanel = useWizard || (!pickyIsMultiSelect(wrap) && items.length === 1);
    if (!allowPanel || !items.length) {
      $wrapPanel.hide();
      $panel.empty();
      pickyUpdateWizardTotal(wrap);
      return;
    }

    var currency = pickyCurrencySymbol(wrap);
    var html = '';
    var anyServices = false;

    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      var services = item.services || [];
      if (!services.length) { continue; }
      anyServices = true;
      if (items.length > 1) {
        var did = (item.displayId !== undefined && item.displayId !== null && item.displayId !== '')
          ? item.displayId
          : item.id;
        var panelItemIcon = pickyBiconHtml(String(wrap.attr('data-booking-item-icon') || ''));
        html += '<div class="picky-services-panel-item" data-unique="' + pickyEscAttr(item.unique) + '">';
        html += '<div class="picky-services-panel-item-title">';
        if (panelItemIcon) { html += '<span class="picky-item-bicon">' + panelItemIcon + '</span>'; }
        html += '#' + pickyEscHtml(String(did)) + '</div>';
      }
      html += '<div class="picky-svc-cards">';
      html += pickyBuildServicesCardsHtml(item, currency, 'picky-bottom-svc-checkbox');
      html += '</div>';
      if (items.length > 1) {
        html += '</div>';
      }
    }

    if (!anyServices) {
      $wrapPanel.hide();
      $panel.empty();
      pickyUpdateWizardTotal(wrap);
      return;
    }

    $panel.html(html);
    $wrapPanel.show();
    pickySyncAddonVisibility($panel, 'picky-bottom-svc-checkbox');
    pickyUpdateWizardTotal(wrap);
  }

  /**
   * Update the visibility of add-on groups within a container based on which
   * parent checkboxes are currently checked. Add-ons that become hidden have
   * their checkboxes unchecked (so they don't leak into submitted SIDs).
   *
   * @param {jQuery} $scope   The container holding both parent + addon checkboxes.
   * @param {string} cbClass  Either 'picky-svc-checkbox' or 'picky-bottom-svc-checkbox'.
   */
  function pickySyncAddonVisibility($scope, cbClass) {
    if (!$scope || !$scope.length) return;
    var checkedParentSids = {};
    $scope.find('.' + cbClass + ':not(.' + cbClass + '-addon):checked').each(function(){
      checkedParentSids[String($(this).val())] = true;
    });
    $scope.find('.picky-svc-addons, .picky-svc-card-addons').each(function(){
      var $grp = $(this);
      var parentSid = String($grp.attr('data-parent-sid') || '');
      var visible = !!checkedParentSids[parentSid];
      if (visible) {
        $grp.removeAttr('hidden');
        if ($grp.hasClass('picky-svc-addons')) {
          $grp.css('display', 'flex');
        } else {
          $grp.css('display', '');
        }
      } else {
        $grp.find('.' + cbClass + '-addon:checked').prop('checked', false);
        $grp.closest('.picky-svc-addon-row').removeClass('is-selected');
        $grp.find('.picky-svc-addon-row').removeClass('is-selected');
        $grp.attr('hidden', 'hidden').css('display', 'none');
      }
    });
    $scope.find('.picky-svc-card').each(function(){
      var $card = $(this);
      var on = $card.children('.picky-svc-card-main').find('input:checked').length > 0;
      $card.toggleClass('is-selected', on);
    });
  }

  function pickyMarkSvcSelectionUI($root) {
    if (!$root || !$root.length) return;
    $root.find('.picky-svc-card').each(function(){
      var $card = $(this);
      var on = $card.children('.picky-svc-card-main').find('input:checked').length > 0;
      $card.toggleClass('is-selected', on);
    });
    $root.find('.picky-svc-addon-row').each(function(){
      $(this).toggleClass('is-selected', $(this).find('input:checked').length > 0);
    });
    $root.find('.picky-svc-tile').each(function(){
      $(this).toggleClass('is-selected', $(this).find('input:checked').length > 0);
    });
  }

  // Bottom panel checkbox change handler
  $(document).on('change', '.picky-bottom-svc-checkbox', function(){
    var wrap = $(this).closest('.picky-tables-wrap');
    var unique = String($(this).data('unique'));
    var $panel = wrap.find('#picky_services_panel');
    pickySyncAddonVisibility($panel, 'picky-bottom-svc-checkbox');
    pickyMarkSvcSelectionUI($panel);
    var sids = pickyCollectSelectedSids(wrap, unique);
    var items = pickyGetSelectedItems(wrap);
    for (var i = 0; i < items.length; i++) {
      if (items[i].unique === unique) { items[i].selectedServiceIds = sids; break; }
    }
    pickySetSelectedItems(wrap, items);
    pickySyncItemsHiddenFields(wrap);
    pickyUpdateWizardTotal(wrap);
    if (pickyBookingFormUsesStepFlow(wrap)) {
      pickyWizardSyncViewportHeight(wrap.find('.picky-wizard').first(), true);
    }
    if (pickyWrapUsesSlotHints(wrap) && wrap.find('#picky_booking_date').val()) {
      /* Recalc ends for new service duration; pass current start so it is kept. */
      wrap.data('pickySlotHintsSkipCanvas', 1);
      pickyFetchIntervalSlotHints(wrap, pickyGetSmartBookingTime(wrap) || '');
    }
  });

  /**
   * Update the hidden service_id fields when a service checkbox changes.
   */
  $(document).on('change', '.picky-svc-checkbox', function() {
    var wrap = $(this).closest('.picky-tables-wrap');
    var unique = $(this).data('unique');
    var $row = wrap.find('.picky-selected-item-row[data-unique="' + unique + '"]');
    pickySyncAddonVisibility($row, 'picky-svc-checkbox');
    pickyMarkSvcSelectionUI($row);
    var sids = pickyCollectSelectedSids(wrap, unique);
    var items = pickyGetSelectedItems(wrap);
    for (var i = 0; i < items.length; i++) {
      if (items[i].unique === unique) {
        items[i].selectedServiceIds = sids;
        break;
      }
    }
    pickySetSelectedItems(wrap, items);
    pickySyncItemsHiddenFields(wrap);
    pickyUpdateWizardTotal(wrap);
    pickyRenderBottomServicesPanel(wrap);
    if (pickyWrapUsesSlotHints(wrap) && wrap.find('#picky_booking_date').val()) {
      wrap.data('pickySlotHintsSkipCanvas', 1);
      pickyFetchIntervalSlotHints(wrap, pickyGetSmartBookingTime(wrap) || '');
    }
  });

    // Same services toggle removed
  /**
   * Deselect an item via the remove button.
   */
  $(document).on('click', '.picky-deselect-item-btn', function() {
    var unique = $(this).data('unique');
    var wrap = $(this).closest('.picky-tables-wrap');
    pickyToggleItemSelection(wrap, unique, null, true /* force deselect */);
    pickyUpdateSelectionTray(wrap);
    // If no items remain, close the form/overlay
    if (pickyGetSelectedItems(wrap).length === 0) {
      wrap.find('.picky-form-booking').fadeOut(100);
      wrap.find('.picky-form-back').fadeOut(100);
      wrap.find('.picky-smart-booking-overlay').attr('aria-hidden', 'true');
      pickySetBookingModalOpen(false);
    }
  });

  /**
   * Toggle selection state for an element.
   * @param {jQuery} wrap
   * @param {string} unique
   * @param {jQuery|null} $el  The canvas element (null if called from remove button)
   * @param {boolean} forceDeselect
   */
  function pickyToggleItemSelection(wrap, unique, $el, forceDeselect) {
    var items = pickyGetSelectedItems(wrap);
    var existingIdx = -1;
    for (var i = 0; i < items.length; i++) {
      if (items[i].unique === unique) { existingIdx = i; break; }
    }

    if (existingIdx >= 0 || forceDeselect) {
      // Deselect
      if (existingIdx >= 0) { items.splice(existingIdx, 1); }
      wrap.find('.picky-draggable .picky-element[data-unique="' + unique + '"]').removeClass('picky-selected');
    } else {
      // Select
      if (!pickyIsMultiSelect(wrap) && items.length > 0) {
        // Single-select layout: only one bookable item may be active. Clear any
        // previous selection before adding the new one (otherwise the admin
        // "Enable multi-select" toggle has no visible effect on the public map).
        for (var si = 0; si < items.length; si++) {
          wrap.find('.picky-draggable .picky-element[data-unique="' + items[si].unique + '"]').removeClass('picky-selected');
        }
        items = [];
      }
      if (!$el || !$el.length) {
        $el = wrap.find('.picky-draggable .picky-element[data-unique="' + unique + '"]');
      }
      var allowedServices = pickyItemAllowedServices(wrap, $el);
      // Default selection: first top-level (parent) service only. Add-ons must
      // be explicitly selected by the user after choosing their parent service.
      var defaultService = '';
      for (var ds = 0; ds < allowedServices.length; ds++) {
        var pss = allowedServices[ds].parent_sids;
        if (!Array.isArray(pss) || pss.length === 0) {
          defaultService = allowedServices[ds].sid;
          break;
        }
      }
      items.push({
        unique: unique,
        // Stable system ID — used as the database identifier on submit. Never
        // replaced with the user-editable display ID.
        id: $el.attr('data-id') || unique,
        // Display ID — prefers the admin-set custom ID (e.g. user renamed
        // table 4 → 6) and falls back to the auto-assigned data-id. Mirrors
        // what the canvas badge .picky-element-id shows.
        displayId: pickyItemDisplayId($el),
        label: $el.attr('data-label') || '',
        maxSeats: parseInt($el.attr('data-max-seats') || '0', 10),
        services: allowedServices,
        defaultService: defaultService,
        selectedServiceIds: defaultService ? [defaultService] : []
      });
      $el.addClass('picky-selected');
    }

    pickySetSelectedItems(wrap, items);
    pickyRenderSelectedItemsList(wrap);
    pickyRenderBottomServicesPanel(wrap);

    // Sync legacy single-item hidden inputs to the LAST selected item (backward compat)
    var form = wrap.find('.picky-form-booking');
    if (items.length > 0) {
      var last = items[items.length - 1];
      form.find('#picky_booking_id').val(last.id);
      form.find('#picky_booking_unique').val(last.unique);
      var $lastEl = wrap.find('.picky-draggable .picky-element[data-unique="' + last.unique + '"]');
      if ($lastEl.length && wrap.find('.picky-booking-meta-compact').length) {
        pickySetSmartMobileCanvasTableSummary(wrap, $lastEl);
      }
    } else {
      form.find('#picky_booking_id').val('');
      form.find('#picky_booking_unique').val('');
      if (wrap.find('.picky-booking-meta-compact').length) {
        pickyClearSmartMobileCanvasTableSummary(wrap);
      }
    }
  }

  /**
   * After availability refresh: auto-remove any selected items that became unavailable.
   */
  function pickyPruneUnavailableSelectedItems(wrap) {
    var items = pickyGetSelectedItems(wrap);
    if (!items.length) { return; }
    var removed = [];
    var remaining = [];
    for (var i = 0; i < items.length; i++) {
      var $el = wrap.find('.picky-draggable .picky-element[data-unique="' + items[i].unique + '"]');
      if ($el.hasClass('disabled')) {
        var pruneDid = (items[i].displayId !== undefined && items[i].displayId !== null && items[i].displayId !== '')
          ? items[i].displayId
          : items[i].id;
        removed.push(items[i].label || ('id: ' + pruneDid));
        $el.removeClass('picky-selected');
      } else {
        remaining.push(items[i]);
      }
    }
    if (removed.length) {
      pickySetSelectedItems(wrap, remaining);
      pickyRenderSelectedItemsList(wrap);
      pickyUpdateSelectionTray(wrap);
      var notice = wrap.find('.picky-items-selection-notice');
      notice.text('Removed (unavailable): ' + removed.join(', ')).show();
      setTimeout(function(){ notice.fadeOut(600); }, 4000);
    }
  }

  /**
   * Refresh ".picky-form-booking-title" to reflect the current selection.
   * Reads "data-text" (singular) and "data-text-multi" (plural) prefixes
   * rendered by class-public.php, then appends "#A, #B, …" using every
   * selected item's displayId (custom-id when set, auto-id otherwise).
   *
   * Called from pickyRenderSelectedItemsList so a single code path keeps
   * the title in sync across selects, deselects, mobile dropdown, prune,
   * and the "Book Now" tray opener.
   */
  function pickyUpdateBookingFormTitle(wrap) {
    var $title = wrap.find('.picky-form-booking').find('.picky-form-booking-title');
    if (!$title.length) { return; }

    var items = pickyGetSelectedItems(wrap);
    if (items.length === 0) {
      $title.text('');
      return;
    }

    var prefixSingle = $title.attr('data-text') || '';
    var prefixMulti  = $title.attr('data-text-multi') || prefixSingle;
    var prefix       = items.length > 1 ? prefixMulti : prefixSingle;
    var label        = prefix.replace(/\s*#\s*$/, '');

    // "Book Table #" already ends with "#", so join the rest with ", #".
    var ids = [];
    for (var i = 0; i < items.length; i++) {
      var did = items[i].displayId;
      if (did === undefined || did === null || did === '') { did = items[i].id; }
      ids.push(String(did));
    }
    $title.empty();
    $('<span class="picky-form-booking-title-label"></span>').text(label).appendTo($title);
    $('<span class="picky-form-booking-title-ids"></span>').text('#' + ids.join(', #')).appendTo($title);
  }

  /* ===== End multi-select state management ===== */

  /**
   * Update the sticky selection tray for the smart layout.
   * Shows item count + cheapest-service-based total and reveals/hides the tray.
   */
  function pickyUpdateSelectionTray(wrap) {
    var items = pickyGetSelectedItems(wrap);
    var $tray = wrap.find('.picky-selection-tray');
    if (!$tray.length) return;

    if (pickyIsSmartMapLayout(wrap)) {
      pickyInitSmartMapDock(wrap);
    }

    var currency = wrap.find('.picky-form-booking').data('currency') || '';
    var $count = $tray.find('.picky-selection-tray-count');
    var $tot = $tray.find('.picky-selection-tray-total');

    if (items.length === 0) {
      if (pickyIsSmartMapLayout(wrap)) {
        $count.text($count.data('empty-label') || 'Select on the map');
        $tot.hide();
        $tray.addClass('is-visible').css('display', 'flex');
        pickySyncSmartMapBookButton(wrap);
        return;
      }
      $tray.removeClass('is-visible').stop(true, true).fadeOut(160);
      return;
    }

    var itemWord = items.length === 1
      ? (wrap.find('.picky-form-booking').data('item-label-singular') || 'item')
      : (wrap.find('.picky-form-booking').data('item-label-plural') || 'items');

    $count.text(items.length + ' ' + itemWord + ' selected');

    // Compute indicative total using the first service of each item (or 0 if none)
    var total = 0;
    var anyPrice = false;
    for (var i = 0; i < items.length; i++) {
      var svcs = items[i].services || [];
      if (svcs.length > 0) {
        total += svcs[0].price || 0;
        if (svcs[0].price > 0) anyPrice = true;
      }
    }
    if (anyPrice) {
      $tot.text('from ' + currency + total.toFixed(2)).show();
    } else {
      $tot.hide();
    }

    $tray.addClass('is-visible');
    if (pickyIsSmartMapLayout(wrap)) {
      $tray.stop(true, true).css('display', 'flex');
      pickySyncSmartMapBookButton(wrap);
    } else {
      $tray.stop(true, true).fadeIn(200);
    }
  }

  /* "Book Now" in the selection tray → open the form popup (or in-flow panel on mobile classic) */
  $(document).on('click', '.picky-selection-tray-book-btn', function() {
    if($(this).prop('disabled')){ return; }
    var wrap = $(this).closest('.picky-tables-wrap');
    var items = pickyGetSelectedItems(wrap);
    if (items.length === 0) return;

    // Always re-render the items list before showing the popup so it is
    // correctly populated on every open (not just the first one after element clicks).
    pickyRenderSelectedItemsList(wrap);

    // Re-sync legacy hidden inputs every time the form is opened.
    // The close handler clears #picky_booking_unique; if the user re-opens via
    // the tray without re-clicking the canvas element the input stays empty,
    // causing pickyFetchIntervalSlotHints to omit the element filter and show
    // already-booked times as available.
    var _form = wrap.find('.picky-form-booking');
    if (items.length > 0) {
      var _last = items[items.length - 1];
      _form.find('#picky_booking_unique').val(_last.unique);
      _form.find('#picky_booking_id').val(_last.id);
    } else {
      _form.find('#picky_booking_unique').val('');
      _form.find('#picky_booking_id').val('');
    }

    if (pickyBookingFormUsesStepFlow(wrap)) {
      /* Ensure map date/time are in the form before the wizard opens. */
      try {
        if (wrap.hasClass('picky-tables-wrap-second')) {
          var openDt = (wrap.find('.picky-smart-map-date-input').val() || wrap.find('#picky_booking_date').val() || '').toString();
          if (openDt) { _form.find('#picky_booking_date').val(openDt); }
          if (typeof pickySyncSmartHiddenBookingTime === 'function') {
            pickySyncSmartHiddenBookingTime(wrap);
          }
        }
      } catch (eOpenSync) {}
      pickyWizardInit(wrap);
    }

    // Hide the tray while the form is open
    wrap.find('.picky-selection-tray').fadeOut(120);

    // All layouts: open the centered overlay. Classic used to drop the form under the canvas on phones.
    var $ov = wrap.find('.picky-smart-booking-overlay');
    if ($ov.length) $ov.attr('aria-hidden', 'false');
    pickySetBookingModalOpen(true);
    wrap.find('.picky-form-back').fadeIn(100, function() {
      wrap.find('.picky-form-booking').fadeIn(100);
      setTimeout(function(){ pickySmartSyncDatetimeHeights(wrap); }, 120);
    });
  });


  $('.picky-draggable .picky-element').on('click',function(){
    if(!$(this).hasClass('disabled')){
      var wrap = $(this).closest('.picky-tables-wrap');

      // Smart layout: require date + time first, then choose an available table.
      if(wrap.hasClass('picky-tables-wrap-second')){
        var dt = (wrap.find('#picky_booking_date').val() || '').toString();
        var tm = pickyGetSmartBookingTime(wrap);
        if(!dt){
          // show date error message (datepicker is outside the form in layout 2)
          wrap.find('.picky-datepicker, .picky-smart-map-date-input').addClass('picky-error-input');
          wrap.find('.picky-input-wrap-datepicker .picky-error.picky-error-empty, .picky-smart-map-field-date .picky-error.picky-error-empty').fadeIn(100);
          return;
        }
        if(!tm){
          var $timeSel = wrap.find('#picky_booking_time_fake, select#picky_booking_time').first();
          $timeSel.addClass('picky-error-input');
          wrap.find('.picky-slot-error-start').stop(true, true).css('display', 'block').fadeIn(100);
          // Smart Map: tip is a sibling of .picky-input-wrap — do not focus the select
          // (focus/click handlers would immediately fade the tip out).
          wrap.find('.picky-smart-map-field-time .picky-error.picky-error-empty').stop(true, true).fadeIn(100);
          $timeSel.closest('.picky-input-wrap').find('.picky-error.picky-error-empty').stop(true, true).fadeIn(100);
          return;
        }
      }

      if(wrap.find('.picky-max-people-amount').length > 0){
        var people = parseInt($(this).attr('data-max-seats'), 10);
        // Universal table (max-seats=0) => unlimited
        if(!isNaN(people) && people > 0){
          wrap.find('.picky-max-people-amount').text(people);
          wrap.find('.picky-max-people-amount').show();
          wrap.find('.picky-max-people-amount-text').show();
        } else {
          wrap.find('.picky-max-people-amount').hide();
          wrap.find('.picky-max-people-amount-text').hide();
        }
      }
      if(wrap.hasClass('picky-tables-wrap-second')){
        pickySmartPickHintsClear(wrap);
      }
      // Multi-select: toggle this element in the selected items list
      var _clickedUnique = $(this).attr('data-unique');
      pickyToggleItemSelection(wrap, _clickedUnique, $(this), false);
      // Sync legacy seats field for backward compat
      var _selItems = pickyGetSelectedItems(wrap);
      if (_selItems.length > 0) {
        wrap.find('.picky-form-booking').find('#picky_booking_seats').val(_selItems[_selItems.length-1].maxSeats || $(this).attr('data-seats'));
      }
      pickyUpdateSelectedCapacityInfo(wrap);
      pickyFetchSelectedCapacityInfo(wrap);
      pickyShowTimeLimitNotice(wrap, pickyGetBookableUntilForSelected(wrap));
      // Title is rebuilt centrally inside pickyRenderSelectedItemsList (called
      // from pickyToggleItemSelection above) so it reflects every selected
      // item's display ID, not just the last-clicked element.

      wrap.find('.picky-form-booking').find('#picky_booking_time').closest('.picky-input-wrap').hide();
      if(!wrap.hasClass('picky-tables-wrap-second')){// quick fix
        wrap.find('.picky-form-booking').find('#picky_booking_time_to').closest('.picky-input-wrap').hide();
      } else {
        wrap.find('.picky-form-booking').find('#picky_booking_time_to').closest('.picky-input-wrap').show();
      }

      wrap.find('.picky-form-booking .picky-error').fadeOut(100);
      wrap.find('.picky-send-respons-error').fadeOut(100);
      wrap.find('.picky-send-booking').removeClass('picky-sended');
      wrap.find('.picky-send-booking').removeClass('picky-sended-error');

      if(wrap.hasClass('picky-tables-wrap-default')){
        reInitForm($(this), wrap.find('.picky-datepicker'), wrap);

        // Classic layout: show tray instead of opening the popup form immediately,
        // so the user can select more items before opening the form.
        pickyUpdateSelectionTray(wrap);

      } else if(wrap.hasClass('picky-tables-wrap-second')){
        var uq = $(this).attr('data-unique');
        var $mob = wrap.find('select.picky-mobile-table-select');
        if($mob.length && uq){
          var uqs = String(uq);
          if($mob.find('option').filter(function(){ return this.value === uqs; }).length){
            if(pickyIsMultiSelect(wrap)){
              /* Update dropdown label only — do not fire change.pickyMobile
                 (single-select handler would clear other selected tables). */
              wrap.data('picky-skip-mobile-table-change', 1);
              $mob.val(uqs);
              try { $mob.trigger('change.select2'); } catch (eMobUi) {}
              wrap.removeData('picky-skip-mobile-table-change');
            } else {
              $mob.val(uqs).trigger('change.select2');
            }
          }
        }
        pickySetSmartMobileCanvasTableSummary(wrap, $(this));
        /*
         * Time-first UX: once date+time are chosen, canvas availability already
         * blurs busy tables. Do NOT refetch slot hints on each table tap —
         * that rebuilt valid_starts for the intersection of selected tables and
         * cleared the user's time (then the canvas flashed / un-blurred).
         */
        var keepTm = (wrap.find('select#picky_booking_time_fake').val()
          || wrap.find('input#picky_booking_time').val()
          || (typeof pickyGetSmartBookingTime === 'function' ? pickyGetSmartBookingTime(wrap) : '')
          || '').toString();
        if (pickyWrapUsesSlotHints(wrap) && !keepTm) {
          pickyFetchIntervalSlotHints(wrap, '');
        } else if (keepTm) {
          /* Keep chosen time; canvas blur for this slot is already applied. */
          pickySyncSmartHiddenBookingTime(wrap);
        }

        // Smart layout: update the tray instead of opening overlay immediately.
        // The overlay opens only when the user clicks "Book Now" in the tray.
        pickyUpdateSelectionTray(wrap);
      }
    }
  });

  $('.picky-close, .picky-form-back').on('click',function(){
    if (window.pickyPaymentRefreshTimeout) {
      clearTimeout(window.pickyPaymentRefreshTimeout);
    }
    var wrap = $(this).closest('.picky-tables-wrap');
    if (pickyBookingFormUsesStepFlow(wrap) && !wrap.hasClass('picky-tables-wrap-second') && !pickyIsOnceEvent(wrap)) {
      wrap.find('#picky_booking_date').val('');
      wrap.find('#picky_booking_time').val('');
      try { wrap.find('#picky_booking_time').trigger('change.select2'); } catch (e) {}
      wrap.find('#picky_booking_time_to').val('');
    }
    pickyResetBookingStepFlow(wrap);
    if (pickyIsOnceEvent(wrap)) {
      pickyApplyOnceEvent(wrap);
    }
    pickyClearSmartMobileCanvasTableSummary(wrap);
    pickyShowTimeLimitNotice(wrap, null);
    wrap.find('.picky-form-booking').find('#picky_booking_id').val('');
    wrap.find('.picky-form-booking').find('#picky_booking_seats').val('');
    wrap.find('.picky-form-booking').find('#picky_booking_unique').val('');
    if(pickyWrapUsesSlotHints(wrap) && wrap.find('#picky_booking_date').val()){
      pickyFetchIntervalSlotHints(wrap, '');
    }
    wrap.find('.picky-form-booking').fadeOut(100);
    wrap.find('.picky-form-thx').fadeOut(100);
    wrap.find('.picky-form-thx').find('.picky-mail-warning').remove();
    wrap.find('.picky-form-thx').find('.picky-time-limit-thx').remove();
    wrap.find('.picky-form-back').fadeOut(100);
    wrap.find('.picky-smart-booking-overlay').attr('aria-hidden', 'true');
    pickySetBookingModalOpen(false);
    wrap.find('.picky-form-booking').find('#picky_booking_time').closest('.picky-input-wrap').hide();
    wrap.find('.picky-form-booking').find('#picky_booking_time_to').closest('.picky-input-wrap').hide();
    // Re-show tray if items are still selected (user closed to adjust selection)
    pickyUpdateSelectionTray(wrap);
    if(wrap.hasClass('picky-tables-wrap-default') || wrap.hasClass('picky-tables-wrap-third')){
      refreshAvailableTablesClassic(wrap);
    }
  });

  /** Step flow: time chosen → show contact details + collapse calendar (default two-column modal only). */
  $(document).on('change', 'select#picky_booking_time', function() {
    var wrap = $(this).closest('.picky-tables-wrap');
    if (!pickyBookingFormUsesStepFlow(wrap)) { return; }
    var val = ($(this).val() || '').toString();
    var $fb = wrap.find('.picky-form-booking');
    var $chip = wrap.find('.picky-booking-datetime-chip');
    if (!val) {
      $fb.removeClass('picky-step-time');
      $chip.find('.picky-chip-time').text('');
      $chip.attr('aria-expanded', 'false');
      return;
    }
    $fb.addClass('picky-step-time');
    $chip.find('.picky-chip-time').text(val);
    $chip.attr('aria-expanded', 'true');
  });

  /** Wizard: chip click → step 1 (date/time). Smart: close overlay so map datepicker stays editable. */
  $(document).on('click', '.picky-booking-datetime-chip', function(e) {
    e.preventDefault();
    var wrap = $(this).closest('.picky-tables-wrap');
    if (!pickyBookingFormUsesStepFlow(wrap)) { return; }
    wrap.find('.picky-booking-datetime-chip').attr('aria-expanded', 'false');
    if (wrap.hasClass('picky-tables-wrap-second')) {
      wrap.find('.picky-form-booking .picky-close').trigger('click');
      return;
    }
    pickyWizardGo(wrap, 1);
  });

  $(document).on('keydown', '.picky-booking-datetime-chip', function(e) {
    if (e.which !== 13 && e.which !== 32) { return; }
    e.preventDefault();
    $(this).trigger('click');
  });

  $('.picky-input, .picky-select, .picky-checkbox').on('focus click change', function(e){
    var $el = $(this);
    var $smartField = $el.closest('.picky-smart-map-field');
    // Smart Map: keep empty-field tips until the user actually picks a value.
    // (Opening the dropdown alone must not hide "Please choose time".)
    if($smartField.length){
      if(e.type !== 'change' && !String($el.val() || '').trim()){
        return;
      }
      $el.removeClass('picky-error-input');
      $smartField.find('.picky-error').stop(true, true).fadeOut(100);
      return;
    }
    $el.removeClass('picky-error-input');
    $el.closest('.picky-input-wrap').find('.picky-error').fadeOut(100);
  });

  // If user changes "People amount", reflect it in center of universal table (type-6)
  $(document).on('change', '#picky_booking_people', function(){
    var wrap = $(this).closest('.picky-tables-wrap');
    if(wrap.length === 0){ return; }
    var form = $(this).closest('form');
    var unique = form.find('#picky_booking_unique').val();
    if(!unique){ return; }
    var people = parseInt($(this).val(), 10);
    if(isNaN(people) || people <= 0){ return; }
    var $el = wrap.find('.picky-draggable .picky-element[data-unique="'+unique+'"]');
    if($el.length === 0){ return; }
    if($el.attr('data-type') === 'type-6'){
      $el.attr('data-seats', people);
      $el.find('.picky-element-seats').text(people);
    }
    pickyFetchSelectedCapacityInfo(wrap);
  });

  // Mobile table select (Smart): list from server + sync with canvas taps (canvas & dropdown modes)
  function initMobileTableDropdown(wrap){
    if(!wrap || wrap.length === 0){ return; }
    if(!wrap.hasClass('picky-tables-wrap-second')){ return; }
    if(!wrap.hasClass('picky-mobile-canvas') && !wrap.hasClass('picky-mobile-dropdown')){ return; }
    var $select = wrap.find('select.picky-mobile-table-select');
    if($select.length === 0){ return; }

    // Ensure select2 is applied (plugin already uses select2)
    if(!$select.data('select2')){
      $select.select2({ placeholder: $select.attr('data-placeholder') || '', width: '100%' });
    }

    // When table selected, set hidden fields like a canvas click would do
    $select.off('change.pickyMobile').on('change.pickyMobile', function(){
      if(wrap.data('picky-skip-mobile-table-change')){ return; }
      pickySmartPickHintsClear(wrap);
      var unique = $(this).val();
      var id = $(this).find('option:selected').attr('data-id') || '';
      if(unique){
        var $el = wrap.find('.picky-draggable .picky-element[data-unique="'+unique+'"]');
        wrap.find('.picky-form-booking').find('#picky_booking_id').val(id);
        wrap.find('.picky-form-booking').find('#picky_booking_unique').val(unique);
        if($el.length){
          wrap.find('.picky-form-booking').find('#picky_booking_seats').val($el.attr('data-seats'));
          pickySetSmartMobileCanvasTableSummary(wrap, $el);
        }
        // Mobile dropdown is single-table only. Mirror canvas-click behaviour
        // through pickyToggleItemSelection so the centralized title updater
        // (and "displayId" preference) handles both flows the same way.
        if($el && $el.length){
          var dropItems = pickyGetSelectedItems(wrap);
          for (var di = 0; di < dropItems.length; di++) {
            wrap.find('.picky-draggable .picky-element[data-unique="' + dropItems[di].unique + '"]').removeClass('picky-selected');
          }
          pickySetSelectedItems(wrap, []);
          pickyToggleItemSelection(wrap, unique, $el, false);
        }
      } else {
        wrap.find('.picky-form-booking').find('#picky_booking_id').val('');
        wrap.find('.picky-form-booking').find('#picky_booking_unique').val('');
        pickyClearSmartMobileCanvasTableSummary(wrap);
        // Drop any selected items so the title clears alongside the dropdown.
        var clearItems = pickyGetSelectedItems(wrap);
        for (var ci = 0; ci < clearItems.length; ci++) {
          wrap.find('.picky-draggable .picky-element[data-unique="' + clearItems[ci].unique + '"]').removeClass('picky-selected');
        }
        pickySetSelectedItems(wrap, []);
        pickyRenderSelectedItemsList(wrap);
      }
      if(pickyWrapUsesSlotHints(wrap)){
        var stMob = (wrap.find('select#picky_booking_time_fake').val()
          || wrap.find('input#picky_booking_time').val()
          || '').toString();
        /* Same as canvas tap: keep chosen time; only refresh table blur. */
        if (!stMob) {
          pickyFetchIntervalSlotHints(wrap, '');
        } else {
          pickySyncSmartHiddenBookingTime(wrap);
        }
      }
    });

    function setLoading(isLoading){
      var $wrap = $select.closest('.picky-input-inner');
      if(isLoading){
        $wrap.addClass('picky-loading');
      } else {
        $wrap.removeClass('picky-loading');
      }
    }

    // Fetch only реально доступные столы с сервера
    var lastReq = null;
    function refreshAvailableTables(){
      var dt = wrap.find('#picky_booking_date').val();
      var tm = wrap.find('#picky_booking_time').val();
      var tmTo = wrap.find('#picky_booking_time_to').val();
      var ppl = parseInt(wrap.find('#picky_booking_people').val(), 10);
      if(isNaN(ppl) || ppl <= 0){ ppl = 1; }
      if(!dt || !tm){
        $select.find('option').not(':first').remove();
        $select.val('').trigger('change');
        pickySmartAvailabilityNoteSet(wrap, false);
        wrap.removeClass('picky-smart-table-picker-visible');
        return;
      }

      setLoading(true);
      if(lastReq && lastReq.abort){
        lastReq.abort();
      }
      lastReq = $.ajax({
        type:"POST",
        url: (window.pickyAjax && window.pickyAjax.url) ? window.pickyAjax.url : '',
        dataType: 'json',
        data: {
          action: 'picky_get_available_tables',
          _wpnonce: wrap.find('input[name="_wpnonce"]').val(),
          picky_layout_id: wrap.find('input[name="picky_layout_id"]').val() || '',
          date: dt,
          time: tm,
          time_to: tmTo || '',
          people: ppl
        },
        success: function(resp){
          var tables = (resp && resp.success && resp.data && resp.data.tables) ? resp.data.tables : [];
          var capacityMap = (resp && resp.success && resp.data && resp.data.capacity) ? resp.data.capacity : {};
          wrap.data('pickyCapacityByUnique', capacityMap);
        if(resp && resp.success && resp.data && Array.isArray(resp.data.occupants)){
          wrap.data('pickyOccupants', resp.data.occupants);
        }
          var buMap = {};
          var current = $select.val();
          $select.find('option').not(':first').remove();
          for(var i=0;i<tables.length;i++){
            var tablePrefix = pickyTablePrefix;
            $select.append('<option value="'+tables[i].unique+'" data-id="'+tables[i].id+'">'+tablePrefix+tables[i].id+'</option>');
            if(tables[i].bookable_until){
              buMap[String(tables[i].unique)] = tables[i].bookable_until;
            }
          }
          wrap.data('pickyBookableUntilByUnique', buMap);
          // reset selection if it is no longer available
          if(current){
            var exists = $select.find('option[value="'+current+'"]').length > 0;
            if(exists){
              $select.val(current);
            } else {
              $select.val('');
              wrap.find('.picky-form-booking').find('#picky_booking_id').val('');
              wrap.find('.picky-form-booking').find('#picky_booking_unique').val('');
            }
          }
          pickyUpdateSelectedCapacityInfo(wrap, capacityMap);
          pickyShowTimeLimitNotice(wrap, pickyGetBookableUntilForSelected(wrap));
          $select.trigger('change.select2');
        },
        error: function(){
          // Fail-safe: empty list
          $select.find('option').not(':first').remove();
          $select.val('').trigger('change');
        },
        complete: function(){
          setLoading(false);
        }
      });
    }

    wrap.find('#picky_booking_date, #picky_booking_time, #picky_booking_time_to')
      .off('change.pickyMobileAvail')
      .on('change.pickyMobileAvail', function(){
        refreshAvailableTables();
        pickyFetchSelectedCapacityInfo(wrap);
      });

    // Initial load (if date/time already selected)
    refreshAvailableTables();
  }

  function hideOption(wrap, time, date, element = null){
    if(!element || !element.length){ return; }
    var array_exclude_times = pickyParseExcludeAttr(element.attr('data-time'));
    array_exclude_times.push({
      date: date && date.val ? date.val() : '',
      time: time && time.val ? time.val() : ''
    });
    element.attr('data-time', encodeURIComponent(JSON.stringify(array_exclude_times)));
  }

  $(document).on('click', '.picky-tables-wrap .picky-form-booking .picky-send-booking, .picky-wizard .picky-wizard-cta.is-book', function(e){
    if (e && typeof e.preventDefault === 'function') { e.preventDefault(); }
    if (e && typeof e.stopPropagation === 'function') { e.stopPropagation(); }
    var error = 0;
    var self = $(this);
    var wrap = $(this).closest('.picky-tables-wrap');
    var form = $(this).closest('form');
    if (!form.length) {
      form = wrap.find('.picky-form-booking').first();
    }

    // Reentrancy guard: ignore additional clicks while a request is in flight.
    if (self.hasClass('picky-sending') || self.prop('disabled')) {
      return false;
    }

    /* Smart Map: date/time live on the canvas bar — sync into form hiddens before validate. */
    try {
      if (wrap.hasClass('picky-tables-wrap-second')) {
        var mapDt = (wrap.find('.picky-smart-map-date-input').val() || wrap.find('#picky_booking_date').val() || '').toString();
        if (mapDt) { form.find('#picky_booking_date').val(mapDt); }
        if (typeof pickySyncSmartHiddenBookingTime === 'function') {
          pickySyncSmartHiddenBookingTime(wrap);
        }
        var mapTm = (typeof pickyGetSmartBookingTime === 'function' ? pickyGetSmartBookingTime(wrap) : '')
          || (wrap.find('select#picky_booking_time_fake').val() || '').toString()
          || (wrap.data('pickyLockedBookingTime') || '').toString();
        if (mapTm) {
          form.find('#picky_booking_time').val(mapTm);
          wrap.find('input#picky_booking_time').val(mapTm);
          var $fakeSend = wrap.find('select#picky_booking_time_fake');
          if ($fakeSend.length && !$fakeSend.val()) {
            pickyEnsureStartTimeOption(wrap, $fakeSend, mapTm);
          }
        }
      }
      /* Wizard: also pull remembered slot attrs if hidden selects are empty. */
      if (typeof pickyGetWizardStartTime === 'function') {
        var wizTm = pickyGetWizardStartTime(wrap);
        if (wizTm && !(form.find('#picky_booking_time').val() || '').toString()) {
          form.find('#picky_booking_time').val(wizTm);
        }
      }
    } catch (eSync) {}

    // Scope all selectors to the current form to avoid issues when multiple shortcodes/forms exist on the page
    var name = form.find('#picky_booking_name');
    var phone = form.find('#picky_booking_phone');
    var email = form.find('#picky_booking_email');
    var date = form.find('#picky_booking_date');
    var time = form.find('#picky_booking_time');
    var time_to = form.find('#picky_booking_time_to');
    var people = form.find('#picky_booking_people');
    var confirm = form.find('#picky_booking_form_confirm');
    var element = wrap.find('.picky-element[data-unique="'+form.find('#picky_booking_unique').val()+'"]');
    var $errBox = form.find('.picky-send-respons-error').first();
    if (!$errBox.length) { $errBox = wrap.find('.picky-send-respons-error').first(); }

    self.removeClass('picky-sended');
    self.removeClass('picky-sended-error');
    wrap.find('.picky-error').fadeOut(100);
    $errBox.fadeOut(100).text('');

    var errorMsgs = [];

    if(name.length > 0){
      if(name.val() == ""){
        error = 1;
        name.addClass('picky-error-input');
        pickyMarkInputValid(name, false, { force: true });
        name.closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeIn(100);
        errorMsgs.push('name');
      } else {
        pickyMarkInputValid(name, true, { force: true });
      }
    }  
    if(phone.length > 0){
      if(phone.val() == ""){
        error = 1;
        phone.addClass('picky-error-input');
        pickyMarkInputValid(phone, false, { force: true });
        phone.closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeIn(100);
        errorMsgs.push('phone');
      } else if(checkTelephone(phone.val()) != true){
        error = 1;
        phone.addClass('picky-error-input');
        pickyMarkInputValid(phone, false, { force: true });
        phone.closest('.picky-input-wrap').find('.picky-error.picky-error-phone').fadeIn(100);
        errorMsgs.push('phone');
      } else {
        pickyMarkInputValid(phone, true, { force: true });
      }
    }

    if(email.length > 0){
      if(email.val() == ""){
        error = 1;
        email.addClass('picky-error-input');
        pickyMarkInputValid(email, false, { force: true });
        email.closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeIn(100);
        errorMsgs.push('email');
      } else if(checkEmail(email.val()) != true){
        error = 1;
        email.addClass('picky-error-input');
        pickyMarkInputValid(email, false, { force: true });
        email.closest('.picky-input-wrap').find('.picky-error.picky-error-email').fadeIn(100);
        errorMsgs.push('email');
      } else {
        pickyMarkInputValid(email, true, { force: true });
      }
    }

    if(date.length > 0){
      if(date.val() == ""){
        error = 1;
        date.addClass('picky-error-input');
        date.closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeIn(100);
        wrap.find('.picky-datepicker').closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeIn(100);
        errorMsgs.push('date');
      } 
    }

    if(confirm.length > 0){
      // Use the live property (not the original HTML attribute) to validate checkbox state
      if(!confirm.is(':checked')){
        error = 1;
        confirm.addClass('picky-error-input');
        confirm.closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeIn(100);
        errorMsgs.push('confirm');
      } 
    }

    if(time.length > 0){
      var timeVal = (time.val() || '').toString();
      if (!timeVal && wrap.hasClass('picky-tables-wrap-second')) {
        timeVal = (typeof pickyGetSmartBookingTime === 'function' ? pickyGetSmartBookingTime(wrap) : '')
          || (wrap.data('pickyLockedBookingTime') || '').toString();
        if (timeVal) {
          time.val(timeVal);
          wrap.find('input#picky_booking_time').val(timeVal);
        }
      }
      if(timeVal == "" || timeVal == null){
        error = 1;
        time.addClass('picky-error-input');
        time.closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeIn(100);
        errorMsgs.push('time');
      }
    }

    if(time_to.length > 0 && time.length > 0){
      if(time_to.val() == "" || time_to.val() == null || time.val() == "" || time.val() == null){

      } else {
        // Compare as minutes-since-midnight; end-time parser maps 00:00/24:00
        // to 1440 so 23:30 → 00:00 is correctly accepted as "ends at midnight".
        var timeReg = /^(\d{1,2})\:(\d{1,2})$/;
        var time_parts = String(time.val()).match(timeReg);
        var startMin = time_parts ? (parseInt(time_parts[1], 10) * 60 + parseInt(time_parts[2], 10)) : null;
        var endMin   = endTimeToMinutes(String(time_to.val()));

        if(startMin !== null && endMin !== null && endMin <= startMin){
          error = 1;
          time_to.addClass('picky-error-input');
          time_to.closest('.picky-input-wrap').find('.picky-error').fadeIn(100);
          if(wrap.hasClass('picky-tables-wrap-second')){
            $errBox.text(time_to.closest('.picky-input-wrap').find('.picky-error').attr('data-error-text'));
            $errBox.fadeIn(100);
          }
          errorMsgs.push('time_to');
        }
      }
    }

    if(people.length > 0){
      if(people.val() == "" || people.val() == null){
        error = 1;
        people.addClass('picky-error-input');
        people.closest('.picky-input-wrap').find('.picky-error.picky-error-empty').fadeIn(100);
        errorMsgs.push('people');
      }
    }

    /* Multi-item: require at least one selected table/item. */
    var itemsNow = [];
    try { itemsNow = pickyGetSelectedItems(wrap) || []; } catch (eItems) { itemsNow = []; }
    if ((!form.find('#picky_booking_unique').val() && (!itemsNow || !itemsNow.length))
        && wrap.find('#picky_items_container input[name^="picky_items"]').length === 0) {
      error = 1;
      errorMsgs.push('item');
    }

    if(error != 0){
      var i18n = (window.pickyAjax && window.pickyAjax.i18n) ? window.pickyAjax.i18n : {};
      var msg = i18n.fill_required || 'Please fill in all required fields.';
      if (errorMsgs.indexOf('date') >= 0 || errorMsgs.indexOf('time') >= 0) {
        msg = i18n.pick_date_time || 'Please select a date and time, then try again.';
      } else if (errorMsgs.indexOf('confirm') >= 0 && errorMsgs.length === 1) {
        msg = i18n.check_confirm || 'Please confirm that we may use your personal data.';
      } else if (errorMsgs.indexOf('item') >= 0) {
        msg = i18n.select_item || 'Please select an item first.';
      }
      $errBox.text(msg).fadeIn(120);
      return false;
    }

    if(error == 0){
      self.addClass('picky-sended');
      self.addClass('picky-sending');
      self.prop('disabled', true).attr('aria-busy', 'true');
      /* Spinner + keep label readable. */
      if (!self.find('.picky-btn-spinner').length) {
        self.prepend('<span class="picky-btn-spinner" aria-hidden="true"></span>');
      }

      // Before serializing: sync each item's selected service_id hidden input
      var _wrap = wrap;
      try {
        _wrap.find('.picky-svc-checkbox:checked').each(function(){
          var _uq = $(this).data('unique');
          var _sid = $(this).val();
          _wrap.find('.picky-items-svc-hidden[data-unique="' + _uq + '"]').val(_sid);
        });
        _wrap.find('.picky-bottom-svc-checkbox:checked').each(function(){
          var _uq = $(this).data('unique');
          var _sid = $(this).val();
          _wrap.find('.picky-items-svc-hidden[data-unique="' + _uq + '"]').val(_sid);
        });
        // Also re-render to refresh hidden inputs fully
        pickyRenderSelectedItemsList(_wrap);
        pickyRenderBottomServicesPanel(_wrap);
      } catch (eRender) {}

      /* Temporarily drop [inert] so hidden date/time in inactive wizard steps serialize. */
      var $inert = form.find('[inert]');
      $inert.removeAttr('inert');
      var postData = form.serialize();
      $inert.each(function(){ this.setAttribute('inert', ''); });

      postData += '&' + $.param({
          action: 'picky_send_book',
      });

      // if second add time to if need
      if(wrap.hasClass('picky-tables-wrap-second')){
        if(time_to.length > 0 && time.length > 0){
          if(time_to.val() == "" || time_to.val() == null || time.val() == "" || time.val() == null){

          } else {
            postData += '&' + $.param({
              picky_booking_time_to: time_to.val(),
            });
          }
        }
      }

      if ($(window).width() <= 767) {
        postData += '&' + $.param({
          picky_booking_mob: '1',
        });
      }

      /* Nonce for CSRF protection (pairs with wp_verify_nonce in send_book).
         Look up by class scoped to this form first; the duplicate id="picky_nonce"
         across multiple shortcodes on the same page made the id-based lookup
         pick the wrong field (or none) on every form past the first. */
      postData += '&' + $.param({
        picky_nonce: form.find('.picky-nonce').first().val()
                || form.find('#picky_nonce').val()
                || $('.picky-nonce').first().val()
                || $('#picky_nonce').first().val()
                || '',
      });
      postData += '&' + $.param({
        picky_return_url: window.location.href || ''
      });

      // Guard window.pickyAjax: if wp_localize_script never ran (e.g. the script
      // was enqueued via a custom path or the handle changed), `pickyAjax` is
      // undefined and `pickyAjax.url` throws a ReferenceError that aborts the
      // whole click handler before $.ajax even runs.
      var ajaxurl = (window.pickyAjax && window.pickyAjax.url) ? window.pickyAjax.url : '';
      if (!ajaxurl) {
        self.removeClass('picky-sended picky-sending');
        self.prop('disabled', false).removeAttr('aria-busy');
        self.find('.picky-btn-spinner').remove();
        self.addClass('picky-sended-error');
        $errBox.text((window.pickyAjax && window.pickyAjax.i18n && window.pickyAjax.i18n.generic_error) ? window.pickyAjax.i18n.generic_error : 'Error').fadeIn(100);
        return false;
      }

      function pickyReleaseSendBtn(){
        self.removeClass('picky-sending');
        self.prop('disabled', false).removeAttr('aria-busy');
        self.find('.picky-btn-spinner').remove();
      }

      $.ajax({
        type:"POST",
        url: ajaxurl,
        // Avoid an indefinite spinner if PHP (e.g. wp_mail/SMTP) blocks longer than Vonage.
        timeout: 90000,
        // dataType: 'json' — server already sends application/json via
        // wp_send_json(). The previous 'html' + manual JSON.parse() blew up
        // with a SyntaxError whenever another plugin echoed anything (notice,
        // BOM, debug print) before the JSON body, swallowing the booking
        // result even though the row was inserted.
        dataType: 'json',
        data: postData,
        success:function(json, status, xhr){
          if(!json){ json = {}; }
          if(json.echo == 0){
            self.addClass('picky-sended-error');
            $errBox.text(json.error || 'Error').fadeIn(100);
          } else { // all ok
            if(json.redirect_url){
              window.location.href = json.redirect_url;
              return;
            }
            pickyShowTimeLimitNotice(wrap, null);
            pickyClearSmartMobileCanvasTableSummary(wrap);
            wrap.find('.picky-form-booking').find('#picky_booking_id').val('');
            wrap.find('.picky-form-booking').find('#picky_booking_unique').val('');
            wrap.find('.picky-form-booking').find('#picky_booking_seats').val('');
            wrap.find('.picky-form-booking').find('#picky_booking_name').val('');
            wrap.find('.picky-form-booking').find('#picky_booking_phone').val('');
            wrap.find('.picky-form-booking').find('#picky_booking_date').val('');
            wrap.find('.picky-form-booking').find('#picky_booking_time').val('');
            wrap.find('.picky-form-booking').find('#picky_booking_time_to').val('');
            pickyApplyOnceEvent(wrap);

            if(element.length > 0){ // not mob
              hideOption(wrap, time, date, element);

              wrap.find('.picky-datepicker').datepicker( "setDate", null );
              wrap.find('.picky-form-booking').fadeOut(100, function(){
                if(json.mail_warning){
                  wrap.find('.picky-form-thx').find('.picky-form-thx-title').after('<p class="picky-mail-warning" style="color:#c00;margin-top:10px;">' + json.mail_warning + '</p>');
                }
                if(json.time_limit_until){
                  var tlMsg = (pickyI18n && pickyI18n.time_limit_notice)
                    ? pickyI18n.time_limit_notice.replace('%s', json.time_limit_until)
                    : '⚠️ Your visit time was adjusted to end at ' + json.time_limit_until + ' due to an upcoming reservation.';
                  wrap.find('.picky-form-thx').find('.picky-form-thx-title').after('<p class="picky-time-limit-thx" style="color:#b45000;margin-top:10px;font-weight:bold;">' + tlMsg + '</p>');
                }
                wrap.find('.picky-selection-tray').hide();
                wrap.find('.picky-form-thx').fadeIn(100);
                wrap.find('.picky-form-booking').find('#picky_booking_time').closest('.picky-input-wrap').hide();
                wrap.find('.picky-form-booking').find('#picky_booking_time_to').closest('.picky-input-wrap').hide();
              });
            } else { // mob
              hideOption(wrap, time, date, '');

              wrap.find('.picky-datepicker').datepicker( "setDate", null );
              if(!wrap.hasClass('picky-tables-wrap-second')){
                wrap.find('.picky-form-back').fadeIn(100);
                pickySetBookingModalOpen(true);
              }
              if(json.mail_warning){
                wrap.find('.picky-form-thx').find('.picky-form-thx-title').after('<p class="picky-mail-warning" style="color:#c00;margin-top:10px;">' + json.mail_warning + '</p>');
              }
              if(json.time_limit_until){
                var tlMsgMob = (pickyI18n && pickyI18n.time_limit_notice)
                  ? pickyI18n.time_limit_notice.replace('%s', json.time_limit_until)
                  : '⚠️ Your visit time was adjusted to end at ' + json.time_limit_until + ' due to an upcoming reservation.';
                wrap.find('.picky-form-thx').find('.picky-form-thx-title').after('<p class="picky-time-limit-thx" style="color:#b45000;margin-top:10px;font-weight:bold;">' + tlMsgMob + '</p>');
              }
              wrap.find('.picky-form-booking').fadeOut(100, function(){
                wrap.find('.picky-selection-tray').hide();
                wrap.find('.picky-form-thx').fadeIn(100);
                wrap.find('.picky-form-booking').find('#picky_booking_time').closest('.picky-input-wrap').hide();
                wrap.find('.picky-form-booking').find('#picky_booking_time_to').closest('.picky-input-wrap').hide();
              });
            }

          }
          self.removeClass('picky-sended');
        },
        error: function(){
          self.removeClass('picky-sended');
          self.addClass('picky-sended-error');
          var genericError = (window.pickyAjax && window.pickyAjax.i18n && window.pickyAjax.i18n.generic_error) ? window.pickyAjax.i18n.generic_error : 'Error';
          $errBox.text(genericError).fadeIn(100);
        },
        // Always release the reentrancy guard, both on success and on
        // network/HTTP failure, so the user can retry.
        complete: function(){
          pickyReleaseSendBtn();
        }
      });
    }

  });

  /* Wizard: slot buttons, single CTA, dots, contact validity */
  $(document).on('click', '.picky-slot-btn', function(e) {
    e.preventDefault();
    var $btn = $(this);
    if ($btn.is(':disabled') || $btn.hasClass('is-disabled')) { return; }
    var wrap = $btn.closest('.picky-tables-wrap');
    var role = $btn.closest('.picky-slot-grid').attr('data-slot-role') || 'start';
    pickySelectSlotValue(wrap, role, $btn.attr('data-value'));
    wrap.find('.picky-slot-error-start, .picky-slot-error-end').fadeOut(80);
  });

  $(document).on('click', '.picky-wizard .picky-wizard-cta:not(.is-book)', function(e) {
    e.preventDefault();
    e.stopPropagation();
    var wrap = $(this).closest('.picky-tables-wrap');
    if (!wrap.length || !pickyBookingFormUsesStepFlow(wrap)) { return; }
    var cur = pickyWizardCurrentStep(wrap);
    if (cur >= 3) { return; }
    if (!pickyWizardValidateStep(wrap, cur)) { return; }
    pickyWizardGo(wrap, cur + 1);
    if (cur + 1 === 3) {
      pickyRefreshContactFieldValidity(wrap);
    }
  });

  $(document).on('click', '.picky-wizard-dot', function(e) {
    e.preventDefault();
    var wrap = $(this).closest('.picky-tables-wrap');
    if (!pickyBookingFormUsesStepFlow(wrap)) { return; }
    var target = parseInt($(this).attr('data-step'), 10);
    var cur = pickyWizardCurrentStep(wrap);
    if (isNaN(target) || target < 1) { return; }
    if (target > cur) {
      for (var s = cur; s < target; s++) {
        if (!pickyWizardValidateStep(wrap, s)) { return; }
      }
    }
    pickyWizardGo(wrap, target);
  });

  $(document).on('input blur change', '.picky-wizard-contacts #picky_booking_name, .picky-wizard-contacts #picky_booking_phone, .picky-wizard-contacts #picky_booking_email', function(e) {
    var $input = $(this);
    var id = $input.attr('id');
    var val = String($input.val() || '').trim();
    var ok = false;
    if (id === 'picky_booking_name') {
      ok = val.length > 0;
    } else if (id === 'picky_booking_phone') {
      ok = val.length > 0 && (typeof checkTelephone === 'function' ? checkTelephone(val) === true : /^[+\d\s\-()]{6,}$/.test(val));
    } else if (id === 'picky_booking_email') {
      ok = val.length > 0 && (typeof checkEmail === 'function' ? checkEmail(val) === true : /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val));
    }
    pickyMarkInputValid($input, ok, { force: e.type === 'blur' || val.length > 0 });
  });

  // Simple layout: init wizard on load (no tray open).
  $(function() {
    $('.picky-tables-wrap-third .picky-wizard[data-picky-wizard]').each(function() {
      var wrap = $(this).closest('.picky-tables-wrap');
      pickyWizardInit(wrap);
    });
  });

  $(window).on('resize.pickyWizardCarousel', function() {
    // no-op: height is content-driven (single active step)
  });

  
	
  

})(jQuery);