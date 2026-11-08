(function ($) {
 'use strict';

  /**
   * Canonical base URL for the plugin settings page, captured ONCE at JS-load time from the PHP-rendered
   * data attribute.  Using window.location as the base is fragile: after several replaceState calls the
   * URL can accumulate garbage or lose `page=picky_settings`.  The data attribute is always set by PHP and
   * is never modified by JS, so it remains trustworthy regardless of how many replaceState calls happen.
   */
  var Picky_SETTINGS_BASE_URL = (function () {
    try {
      var el = document.getElementById('picky-wrap-content');
      var attr = el ? el.getAttribute('data-picky-settings-url') : '';
      if (attr) {
        return attr; // absolute URL from PHP (admin_url)
      }
      // Fallback: derive from window.location (strip picky-tab, keep page=picky_settings)
      var u = new URL(window.location.href);
      u.searchParams.delete('picky-tab');
      return u.origin + u.pathname + u.search;
    } catch (e) {
      return '';
    }
  }());

  var showSpinner = function () {
    $('picky-form').submit(function () {
      $(this).find('.picky-spinner').addClass('is-active');
    });
  };

  var clipboard = new ClipboardJS('.picky-instructions-shortcode-btn, .picky-shortcode-badge');
  
  // State variables for dragging and collision detection
  var coordinates = {};
  var elements = [];
  var revert = true;
  var isRestoring = false;

  clipboard.on('success', function(e) {
      e.clearSelection();
      var $badge = $(e.trigger);
      if (!$badge.hasClass('picky-shortcode-badge')) { return; }
      $badge.addClass('copied');
      clearTimeout($badge.data('pickyCopyTimer'));
      $badge.data('pickyCopyTimer', setTimeout(function(){
        $badge.removeClass('copied');
      }, 2000));
  });

  // colorpicker (must not abort the rest of this file if iris is missing)
  try {
    if(typeof $.fn.wpColorPicker === 'function'){
      $('input.picky-settings-color').not('.picky-layout-bg-color').each(function(){
        var $inp = $(this);
        if($inp.hasClass('wp-color-picker') || $inp.closest('.wp-picker-input-wrap').length){
          return;
        }
        $inp.wpColorPicker({ hide: true, palettes: true });
      });
    }
  } catch (exColor) {}

  $(document).on('mousedown.pickySettingsIrisClose', function(e){
    var $t = $(e.target);
    if($t.closest('.wp-picker-container, .iris-picker').length){
      return;
    }
    $('#picky-wrap-content.picky-settings-surface .wp-picker-container.wp-picker-active .wp-color-result').each(function(){
      $(this).trigger('click');
    });
  });

  function pickyApplyLayoutCanvasColor(hex){
    hex = (hex || '').toString();
    if(!hex){ return; }
    var $wrap = $('.picky-draggable-wrap').not('.picky-canvas-transparent-bg');
    var $drag = $('.picky-draggable').not('.picky-canvas-transparent-bg');
    $wrap.css('background-color', hex);
    $drag.css('background-color', hex);
    var $style = $('#picky-layout-appearance-style');
    if($style.length){
      var css = $style.html() || '';
      css = css.replace(
        /\.picky-draggable-wrap:not\(\.picky-canvas-transparent-bg\)\s*\{[^}]*\}/,
        '.picky-draggable-wrap:not(.picky-canvas-transparent-bg){background-color:' + hex + ' !important;}'
      );
      $style.html(css);
    }
  }

  function pickyApplyLayoutItemColor(hex){
    hex = (hex || '').toString();
    if(!hex){ return; }
    var $style = $('#picky-layout-appearance-style');
    if(!$style.length){
      var pickyAppearanceNode = document.createElement('style');
      pickyAppearanceNode.id = 'picky-layout-appearance-style';
      document.head.appendChild(pickyAppearanceNode);
      $style = $(pickyAppearanceNode);
    }
    var css = $style.html() || '';
    var rule = '.picky-draggable .picky-element:not(.type-text):not(.type-wall):not(.type-shape):not(.type-icon):not(.no-bg-table) .picky-element-shape:not([style*="background-image"]):not([style*="background-color"]){background-color:' + hex + ' !important;}';
    if(/\.picky-draggable \.picky-element:not\(\.type-text\):not\(\.type-wall\):not\(\.type-shape\):not\(\.type-icon\)/.test(css)){
      css = css.replace(
        /\.picky-draggable \.picky-element:not\(\.type-text\):not\(\.type-wall\):not\(\.type-shape\):not\(\.type-icon\):not\(\.no-bg-table\) \.picky-element-shape:not\(\[style\*="background-image"\]\):not\(\[style\*="background-color"\]\)\s*\{[^}]*\}/,
        rule
      );
    } else {
      css += '\n' + rule;
    }
    $style.html(css);
  }

  if(typeof $.fn.wpColorPicker === 'function'){
    $('input.picky-layout-bg-color').each(function(){
      var $inp = $(this);
      var def = ($inp.attr('data-default-color') || '').toString();
      $inp.wpColorPicker({
        defaultColor: def || false,
        change: function(event, ui){
          var hex = (ui && ui.color && typeof ui.color.toString === 'function') ? ui.color.toString() : $(this).val();
          if($(this).attr('name') === 'picky_layout_bg_canvas_color'){
            pickyApplyLayoutCanvasColor(hex);
          } else if($(this).attr('name') === 'picky_layout_bg_table_color'){
            pickyApplyLayoutItemColor(hex);
          }
        },
        clear: function(){
          var $el = $(this);
          var fallback = ($el.attr('data-default-color') || '').toString();
          if(!fallback){ return; }
          $el.val(fallback);
          if($el.attr('name') === 'picky_layout_bg_canvas_color'){
            pickyApplyLayoutCanvasColor(fallback);
          } else if($el.attr('name') === 'picky_layout_bg_table_color'){
            pickyApplyLayoutItemColor(fallback);
          }
        }
      });
    });
  }

  function restoreSettingsControls(){
    var $root = $('#picky-wrap-content, #picky-builder-surface');
    if($root.length === 0){ return; }

    // Force core controls to remain usable even if theme/admin CSS overrides them.
    $root.find('input, select, textarea, button').each(function(){
      var $el = $(this);
      $el.prop('disabled', false);
      if($el.is('input, textarea')){
        $el.prop('readonly', false);
      }
      /* Submit / push-button controls: do not inline pointer-events/opacity — lets CSS (.button, calendar, list bar) win. */
      if($el.is('button') || ($el.is('input') && $el.is('[type="submit"], [type="button"], [type="reset"], [type="image"]'))){
        return;
      }
      $el.css({
        'pointer-events': 'auto',
        'visibility': 'visible',
        'opacity': '1'
      });
    });

    $root.find('input[type="text"], input[type="number"], textarea, select').each(function(){
      var $el = $(this);
      if($el.hasClass('wp-color-picker') || $el.hasClass('picky-settings-color') || $el.closest('.wp-picker-container').length){
        return;
      }
      $el.css({
        'display': 'inline-block',
        'min-height': '40px',
        'box-sizing': 'border-box'
      });
    });

    $root.find('input[type="checkbox"], input[type="radio"]').css({
      'display': 'inline-block',
      'width': '18px',
      'height': '18px',
      'position': 'static'
    });
  }

  function pickyNormalizeLabelPosition(raw) {
    var v = (raw == null ? '' : String(raw)).toLowerCase();
    if (v === 'top' || v === 'bottom') {
      return v;
    }
    return 'bottom';
  }

  function pickyCurrentSettingsTab(){
    // 1) Most reliable: currently visible panel.
    var $visiblePanel = $('.picky-settings-tab[data-tab]:visible').first();
    var tabFromPanel = ($visiblePanel.attr('data-tab') || '').toString();
    if(tabFromPanel){
      return tabFromPanel;
    }

    // 2) URL state (last picky-tab wins when duplicated).
    try {
      var sp = new URLSearchParams(window.location.search);
      var tabAll = sp.getAll('picky-tab');
      var tabFromUrl = tabAll.length ? String(tabAll[tabAll.length - 1] || '') : '';
      if(tabFromUrl){
        return tabFromUrl;
      }
    } catch(e){}

    // 3) Active top-level header button (ignore layout submenu links).
    var $active = $('.picky-settings-tabs-header-btn.active').not('.picky-settings-tabs-submenu__link').first();
    if(!$active.length){
      $active = $('.picky-settings-tabs-header-btn').not('.picky-settings-tabs-submenu__link').first();
    }
    return ($active.attr('data-tab') || '1').toString();
  }

  /**
   * Remove every picky-tab entry (browsers may keep duplicates; delete() only removes one per call).
   */
  function pickyUrlSearchParamsDeleteAll(u, name){
    if(!u || !u.searchParams || !name){ return; }
    while(u.searchParams.has(name)){
      u.searchParams.delete(name);
    }
  }

  /**
   * Sync picky-tab in the address bar and in the WordPress options form referer field.
   *
   * @param {string} tab Tab id (1–11).
   * @param {{ fromHeaderClick?: boolean }} [opts] fromHeaderClick: true when the user used the left rail
   *   (no full reload) — build a clean URL for that tab. Omit or false on first paint and after list
   *   pagination: merge from the current location so paged, post_type, and filters stay in the bar and
   *   survive refresh (the old “frozen base + picky-tab only” pattern stripped those and broke embedded lists).
   */
  function pickyPersistSettingsTab(tab, opts){
    if(!tab){ return; }
    opts = opts || {};
    try {
      var u;
      if(opts.fromHeaderClick && Picky_SETTINGS_BASE_URL){
        u = new URL(Picky_SETTINGS_BASE_URL, window.location.href);
        pickyUrlSearchParamsDeleteAll(u, 'picky-tab');
        u.searchParams.set('picky-tab', String(tab));
        u.searchParams.set('page', 'picky_settings');
        var tn = parseInt(String(tab), 10);
        u.searchParams.delete('post_type');
        u.searchParams.delete('paged');
        u.searchParams.delete('s');
        u.searchParams.delete('orderby');
        u.searchParams.delete('order');
        u.searchParams.delete('picky_layout_tab');
        u.searchParams.delete('picky_payment_status');
        u.searchParams.delete('picky_payment_provider');
        u.searchParams.delete('m');
        u.searchParams.delete('cat');
        u.searchParams.delete('post_status');
        if(tn === 7){
          u.searchParams.set('post_type', 'picky_booking');
        } else if(tn === 9){
          u.searchParams.set('post_type', 'picky_layout');
        }
        if(String(tab) !== '9'){
          u.searchParams.delete('layout_id');
        }
      } else {
        u = new URL(window.location.href);
        u.searchParams.set('page', 'picky_settings');
        pickyUrlSearchParamsDeleteAll(u, 'picky-tab');
        u.searchParams.set('picky-tab', String(tab));
        if(String(tab) !== '9'){
          u.searchParams.delete('layout_id');
        }
      }
      var next = u.pathname + u.search;
      if(window.history && window.history.replaceState){
        if((window.location.pathname + window.location.search) !== next){
          window.history.replaceState(null, '', next);
        }
      }
      $('#options-save, #picky-options-save').find('input[name="_wp_http_referer"]').val(next);
    } catch(err){}
  }

  function pickyToggleSettingsSubmitVisibility(tab){
    var n = parseInt(tab, 10);
    var showSubmit = !isNaN(n) && ((n >= 1 && n <= 6) || n === 12);
    $('.picky-settings-submit-wrap').toggle(showSubmit);
  }

  /**
   * True when the embedded layout builder is shown (URL layout_id or builder markup present).
   */
  function pickyLayoutBuilderIsOpen(){
    try {
      var sp = new URLSearchParams(window.location.search);
      var lid = parseInt(String(sp.get('layout_id') || ''), 10);
      if(!isNaN(lid) && lid > 0){
        return true;
      }
    } catch(e){}
    return $('.picky-settings-tab-9').find('[data-picky-context="builder-embedded"]').length > 0;
  }

  function pickyTabStateClass(tab){
    var map = {
      '1': 'is-tab-main',
      '2': 'is-tab-work-days',
      '3': 'is-tab-sms',
      '4': 'is-tab-layout-colors',
      '5': 'is-tab-payments',
      '6': 'is-tab-form-emails',
      '7': 'is-tab-reservations',
      '8': 'is-tab-calendar',
      '9': 'is-tab-layouts',
      '10': 'is-tab-import-export',
      '11': 'is-tab-documentation',
      '12': 'is-tab-integrations'
    };
    var t = String(tab || '');
    return map[t] || 'is-tab-unknown';
  }

  function pickySyncTabStateClass(tab){
    var cls = pickyTabStateClass(tab);
    var builderOpened = (cls === 'is-tab-layouts') && pickyLayoutBuilderIsOpen();
    try {
      document.body.classList.toggle('picky-layout-builder-open', !!builderOpened);
    } catch (err) {}
    var $targets = $('.picky-settings-tabs-wrap-inner.picky-settings-app-panels, .picky-settings-tabs-wrap-inner.picky-settings-option-panels');
    if(!$targets.length){ return; }
    $targets.each(function(){
      var $el = $(this);
      var current = ($el.attr('class') || '').split(/\s+/);
      for(var i = 0; i < current.length; i++){
        if(/^is-tab-/.test(current[i])){
          $el.removeClass(current[i]);
        }
      }
      $el.addClass(cls);
    });
    $targets.toggleClass('builder-opened', !!builderOpened);
  }

  /**
   * Option-style tabs (1–6 and Integrations 12): hide the app-panels wrapper so it does not reserve grid/padding above the form.
   * App tabs (7–11 except 12): show app-panels; form stays on grid row 2.
   */
  function syncPickySettingsShellLayoutMode(tab){
    var $root = $('#picky-wrap-content.picky-settings-surface');
    if(!$root.length){ return; }
    var t = tab !== undefined && tab !== null ? String(tab) : '';
    if(t === ''){
      t = String($('.picky-settings-tabs-header-btn.active').first().attr('data-tab') || '13');
    }
    var n = parseInt(t, 10);
    var isOption = !isNaN(n) && ((n >= 1 && n <= 6) || n === 12);
    $root.toggleClass('picky-settings-shell--option-tabs', isOption);
    $root.toggleClass('picky-settings-shell--app-tabs', !isOption);
  }

  /* Delegated: header nav may be re-rendered or not present on first script pass. */
  $(document).on('click', '.picky-settings-tabs-header-btn', function(e){
    var tab = $(this).attr('data-tab');

    // Always open the main layouts page (the list) if "Layouts" (Tab 9) is clicked while 
    // a specific layout builder is currently rendered/active in the DOM.
    if (tab === '9' && $('.picky-settings-tab-9').find('[data-picky-context="builder-embedded"]').length > 0) {
      var u = new URL(window.location.href);
      u.searchParams.delete('layout_id');
      u.searchParams.set('picky-tab', '9');
      window.location.href = u.pathname + u.search;
      return;
    }

    e.preventDefault();

    $('.picky-settings-tabs-header-btn').removeClass('active');
    $(this).addClass('active');
    $('.picky-settings-tab').hide();
    $('.picky-settings-tab[data-tab="'+tab+'"]').show();
    restoreSettingsControls();
    initSettingsHelpDetailsAccordion();
    // fromHeaderClick: true always builds from the clean base URL, never from window.location,
    // so layout_id and other builder params are stripped when switching away from tab 9.
    pickyPersistSettingsTab(tab, { fromHeaderClick: true });
    pickyToggleSettingsSubmitVisibility(tab);
    syncPickySettingsShellLayoutMode(tab);
    pickySyncTabStateClass(tab);
    if(String(tab) === '4'){
      window.setTimeout(function(){
        try { $(window).trigger('resize'); } catch(err){}
      }, 80);
    }
    if(tab === '9'){
      window.setTimeout(function(){
        try { syncPickySettingsSurfaceRailPadding(); } catch (err) {}
        try { syncBuilderShellWidth(); } catch (err) {}
        try { syncBuilderToolbarWidth(); } catch (err) {}
        try { pickyReinitBuilderDragDrop(); } catch (err) {}
      }, 50);
    }
  });

  function initSettingsHelpDetailsAccordion(){
    var $root = $('#picky-wrap-content');
    if($root.length === 0){ return; }
    $root.find('details.picky-settings-help').each(function(){
      var el = this;
      if(el.dataset.pickyHelpBound){ return; }
      el.dataset.pickyHelpBound = '1';
      el.addEventListener('toggle', function(){
        if(!el.open){ return; }
        $root.find('details.picky-settings-help').each(function(){
          if(this !== el && this.open){
            this.open = false;
          }
        });
      });
    });
  }

  function pickyNormalizePath(u){
    return (u.pathname || '').replace(/\/+/g, '/');
  }

  /**
   * True if this URL is list navigation for the embedded Reservations (tab 7) or Layouts (tab 9) shell.
   * WP often omits page=picky_settings on pagination; the old guard treated that as "fine" and let broken URLs through.
   */
  function pickyIsEmbeddedListLinkForPatch(u, tab, postTypeSlug){
    var p = pickyNormalizePath(u);
    if(/\/wp-admin\/post\.php$/i.test(p) || /\/post\.php$/i.test(p)){ return false; }
    if(/\/edit\.php$/i.test(p)){
      return u.searchParams.get('post_type') === postTypeSlug;
    }
    if(!/\/admin\.php$/i.test(p)){ return false; }
    if(u.searchParams.get('post_type') === postTypeSlug){ return true; }
    if(u.searchParams.get('page') !== 'picky_settings'){ return false; }
    if(tab === '7' && u.searchParams.get('post_type') === 'picky_layout'){ return false; }
    if(tab === '9' && u.searchParams.get('post_type') === 'picky_booking'){ return false; }
    return true;
  }

  function pickyApplyEmbeddedListQuery(u, tab, postTypeSlug){
    while(u.searchParams.has('picky-tab')){ u.searchParams.delete('picky-tab'); }
    u.searchParams.set('picky-tab', tab);
    u.searchParams.set('page', 'picky_settings');
    u.searchParams.delete('post_type');
    u.searchParams.set('post_type', postTypeSlug);
  }

  /**
   * Embedded list tables build pagination from REQUEST_URI; normalize hrefs per panel (tab + post_type).
   */
  function fixEmbeddedPostsListAdminLinks(){
    var $root = $('#picky-wrap-content.picky-settings-surface');
    if(!$root.length){ return; }

    var patches = [
      { $scope: $root.find('#picky-settings-tab-7 .picky-reservations-custom'), tab: '7', postType: 'picky_booking' },
      { $scope: $root.find('#picky-settings-tab-9 .picky-reservations-custom'), tab: '9', postType: 'picky_layout' }
    ];
    patches.forEach(function(patch){
      if(!patch.$scope.length){ return; }
      patch.$scope.find('a[href]').each(function(){
        var href = this.getAttribute('href');
        if(!href || href.charAt(0) === '#'){ return; }
        try {
          var u = new URL(href, window.location.href);
          if(!pickyIsEmbeddedListLinkForPatch(u, patch.tab, patch.postType)){ return; }
          pickyApplyEmbeddedListQuery(u, patch.tab, patch.postType);
          this.setAttribute('href', u.pathname + u.search + u.hash);
        } catch(err){}
      });
    });
  }

  /** Intercept clicks only when the destination is still wrong (fixes pagination missing page=picky_settings, wrong tab). */
  function bindEmbeddedPostsListNavigationGuard(){
    $(document).on('click', '#picky-settings-tab-7 .picky-embedded-posts-list a[href], #picky-settings-tab-7 .picky-reservations-custom a[href]', function(e){
      try {
        var u = new URL(this.href, window.location.href);
        if(!pickyIsEmbeddedListLinkForPatch(u, '7', 'picky_booking')){ return; }
        var allTabs = u.searchParams.getAll('picky-tab');
        var lastTab = allTabs.length ? allTabs[allTabs.length - 1] : '';
        if(lastTab === '7' && u.searchParams.get('post_type') === 'picky_booking' && u.searchParams.get('page') === 'picky_settings'){
          return;
        }
        pickyApplyEmbeddedListQuery(u, '7', 'picky_booking');
        e.preventDefault();
        window.location.assign(u.pathname + u.search + u.hash);
      } catch(err){}
    });
    $(document).on('click', '#picky-settings-tab-9 .picky-embedded-posts-list a[href], #picky-settings-tab-9 .picky-reservations-custom a[href]', function(e){
      try {
        var u = new URL(this.href, window.location.href);
        if(!pickyIsEmbeddedListLinkForPatch(u, '9', 'picky_layout')){ return; }
        var allTabs9 = u.searchParams.getAll('picky-tab');
        var lastTab9 = allTabs9.length ? allTabs9[allTabs9.length - 1] : '';
        if(lastTab9 === '9' && u.searchParams.get('post_type') === 'picky_layout' && u.searchParams.get('page') === 'picky_settings'){
          return;
        }
        pickyApplyEmbeddedListQuery(u, '9', 'picky_layout');
        e.preventDefault();
        window.location.assign(u.pathname + u.search + u.hash);
      } catch(err){}
    });
  }

  /** Reservations custom UI: navigate when the consolidated "View" dropdown changes. */
  $(document).on('change', '.picky-reservations-custom__view-select', function(){
    var v = ($(this).val() || '').toString();
    if(v){
      window.location.href = v;
    }
  });

  function initSettingsTabsState(){
    var $headers = $('.picky-settings-tabs-header-btn');
    var $tabs = $('.picky-settings-tab');
    if($headers.length === 0 || $tabs.length === 0){ return; }

    var tab = null;
    try {
      var sp = new URLSearchParams(window.location.search);
      var vtAll = sp.getAll('picky-tab');
      var vt = vtAll.length ? vtAll[vtAll.length - 1] : '';
      if(vt !== null && vt !== ''){
        tab = String(vt);
      }
    } catch(e){}

    var allowedTabs = { '1':1,'2':1,'3':1,'4':1,'5':1,'6':1,'7':1,'8':1,'9':1,'10':1,'11':1,'12':1 };
    if(!tab || !allowedTabs[tab]){
      tab = null;
    }

    if(!tab){
      var $active = $headers.filter('.active').first();
      if($active.length === 0){
        $active = $headers.first().addClass('active');
      }
      tab = $active.attr('data-tab');
    } else {
      $headers.removeClass('active');
      var $matchHeader = $headers.filter('[data-tab="'+tab+'"]').first();
      if($matchHeader.length){
        $matchHeader.addClass('active');
      } else {
        var $fallback = $headers.filter('.active').first();
        if($fallback.length === 0){
          $headers.first().addClass('active');
        }
        tab = ($headers.filter('.active').first().attr('data-tab')) || tab;
      }
    }

    $tabs.hide();
    if(tab){
      $tabs.filter('[data-tab="'+tab+'"]').show();
    } else {
      $tabs.first().show();
    }
    pickyPersistSettingsTab(tab || '1');
    pickyToggleSettingsSubmitVisibility(tab || '1');
    syncPickySettingsShellLayoutMode(tab || '1');
    pickySyncTabStateClass(tab || '1');
  }

  /**
   * After the correct settings tab is shown, scroll to #picky-section-* from the URL hash
   * (e.g. deep links from the Integrations overview on the same screen).
   */
  function pickyScrollSettingsSectionFromHash(){
    var h = '';
    try { h = (window.location && window.location.hash) ? String(window.location.hash) : ''; } catch(e){}
    if(!h || h.indexOf('#picky-section-') !== 0){ return; }
    var el = null;
    try { el = document.querySelector(h); } catch(err){}
    if(!el){ return; }
    var run = function(){
      try {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch(e2){
        try { el.scrollIntoView(true); } catch(e3){}
      }
    };
    if(typeof window.requestAnimationFrame === 'function'){
      window.requestAnimationFrame(function(){
        window.requestAnimationFrame(run);
      });
    } else {
      window.setTimeout(run, 0);
    }
  }

  var Picky_NAV_COLLAPSE_KEY = 'picky_v2_settings_nav_collapsed';
  var Picky_FILTERS_OPEN_PREFIX = 'picky_v2_filters_open_';

  /**
   * Collapse bulky filter/search strips on Layouts, Calendar, and Bookings.
   * Toggle lives in the page head (or next to views); state in localStorage.
   * Auto-opens when the URL already carries an active search/filter.
   */
  function initPickyCollapsibleFilters(){
    var $root = $('#picky-wrap-content.picky-settings-surface');
    if(!$root.length){ return; }

    function storageKey(id){
      return Picky_FILTERS_OPEN_PREFIX + String(id || 'default');
    }

    function makeToggle(opts){
      var label = opts.label || 'Filters';
      var icon = opts.icon || 'dashicons-filter';
      var $btn = $('<button type="button" class="button picky-filters-toggle" />');
      $btn.attr({
        'aria-expanded': 'false',
        'aria-controls': opts.panelId || '',
        title: label,
        'aria-label': label
      });
      $btn.append($('<span class="dashicons ' + icon + '" aria-hidden="true"></span>'));
      $btn.append($('<span class="picky-filters-toggle__text"></span>').text(label));
      return $btn;
    }

    function setOpen($panel, $btn, open, id){
      $panel.toggleClass('is-open', !!open);
      if($btn && $btn.length){
        $btn.attr('aria-expanded', open ? 'true' : 'false');
        $btn.toggleClass('is-active', !!open);
      }
      try{
        if(id){ localStorage.setItem(storageKey(id), open ? '1' : '0'); }
      } catch(e){}
    }

    function wire($panel, $btn, id, forceOpen){
      if(!$panel.length || !$btn.length){ return; }
      var open = !!forceOpen;
      /* Calendar: never restore open state from localStorage — always start collapsed. */
      var isCal = String(id || '').indexOf('cal-') === 0;
      if(!forceOpen && !isCal){
        try{ open = localStorage.getItem(storageKey(id)) === '1'; } catch(e){ open = false; }
      }
      setOpen($panel, $btn, open, id);
      $btn.off('click.pickyFilters').on('click.pickyFilters', function(e){
        e.preventDefault();
        setOpen($panel, $btn, !$panel.hasClass('is-open'), id);
      });
    }

    function paramsActive(keys){
      try{
        var q = new URLSearchParams(window.location.search);
        for(var i = 0; i < keys.length; i++){
          var v = q.get(keys[i]);
          if(v != null && String(v).replace(/^\s+|\s+$/g, '') !== '' && String(v) !== '0' && String(v) !== 'all'){
            return true;
          }
        }
      } catch(e){}
      return false;
    }

    /* ——— Layouts (and any facade list with top bar) ——— */
    $root.find('.picky-reservations-top-bar').each(function(idx){
      var $panel = $(this);
      if($panel.data('pickyFiltersReady')){ return; }
      $panel.data('pickyFiltersReady', 1);
      $panel.addClass('picky-filters-panel');
      var panelId = 'picky-list-filters-' + idx;
      $panel.attr('id', panelId);

      var $head = $panel.closest('form').prevAll('.picky-cal-head').first();
      if(!$head.length){
        $head = $panel.closest('.picky-reservations-custom, .picky-embedded-posts-list').find('.picky-cal-head').first();
      }
      var $titleRow = $head.find('.picky-cal-head__title-row').first();
      if(!$titleRow.length){ $titleRow = $head.find('.picky-cal-stats').first(); }
      if(!$titleRow.length){ return; }

      var $btn = makeToggle({
        label: (typeof picky_admin_i18n !== 'undefined' && picky_admin_i18n.filtersLabel) ? picky_admin_i18n.filtersLabel : 'Filters',
        icon: 'dashicons-filter',
        panelId: panelId
      });
      var $cta = $titleRow.find('.picky-cal-head__cta, .picky-layouts-list__add').first();
      if($cta.length){ $btn.insertBefore($cta); }
      else { $titleRow.append($btn); }

      var force = paramsActive(['s', 'm']);
      wire($panel, $btn, 'list-' + idx, force);
    });

    /* ——— Calendar: Filters toggle next to Jump to ——— */
    $root.find('form.picky-cal-filters').each(function(idx){
      var $panel = $(this);
      if($panel.data('pickyFiltersReady')){ return; }
      $panel.data('pickyFiltersReady', 1);
      $panel.addClass('picky-filters-panel');
      var panelId = 'picky-cal-filters-' + idx;
      $panel.attr('id', panelId);

      var $jump = $panel.closest('.picky-cal-wrap').find('form.picky-cal-jump').first();
      if(!$jump.length){ $jump = $root.find('form.picky-cal-jump').first(); }

      var $btn = makeToggle({
        label: (typeof picky_admin_i18n !== 'undefined' && picky_admin_i18n.filtersLabel) ? picky_admin_i18n.filtersLabel : 'Filters',
        icon: 'dashicons-filter',
        panelId: panelId
      });
      if($jump.length){
        $btn.insertBefore($jump);
      } else {
        var $stats = $panel.prevAll('.picky-cal-head').first().find('.picky-cal-head__title-row').first();
        if($stats.length){ $stats.append($btn); }
        else { return; }
      }

      var force = false;
      $panel.find('select, input[type="text"]').each(function(){
        var $el = $(this);
        var name = ($el.attr('name') || '');
        if(!name || name === 'page' || name === 'picky-tab' || name === 'view' || name === 'date'){ return; }
        var v = String($el.val() || '');
        var def = 'all';
        if(name === 'saved_view'){ def = 'custom'; }
        if(name === 'mode'){ def = 'board'; }
        if(name === 'sort_by'){ def = 'time'; }
        if(name === 'density'){ def = 'cozy'; }
        if(name === 'layout_id'){ def = '0'; }
        if(name === 'table_id'){ def = ''; }
        if(v !== def && v !== ''){ force = true; }
      });
      wire($panel, $btn, 'cal-' + idx, force);
    });

    /* ——— Bookings / Orders: Search toggle next to Filter in tablenav ——— */
    $root.find('.picky-embedded-posts-list--picky-order form#posts-filter').each(function(idx){
      var $form = $(this);
      if($form.data('pickyFiltersReady')){ return; }
      $form.data('pickyFiltersReady', 1);

      var $search = $form.children('p.search-box').first();
      if(!$search.length){ $search = $form.find('> p.search-box').first(); }
      if(!$search.length){ return; }

      $search.addClass('picky-filters-panel picky-orders-search-panel');
      var panelId = 'picky-orders-search-' + idx;
      $search.attr('id', panelId);

      var $btn = makeToggle({
        label: (typeof picky_admin_i18n !== 'undefined' && picky_admin_i18n.searchLabel) ? picky_admin_i18n.searchLabel : 'Search',
        icon: 'dashicons-search',
        panelId: panelId
      });

      var $actions = $form.find('.tablenav.top .alignleft.actions').first();
      if($actions.length){
        var $filterBtn = $actions.find('#post-query-submit, .picky-list-toolbar-btn--filter, input[name="filter_action"]').first();
        if($filterBtn.length){ $btn.insertAfter($filterBtn); }
        else { $actions.append($btn); }
        /* Keep search panel after tablenav so it expands under the actions row. */
        $form.find('.tablenav.top').first().after($search);
      } else {
        var $views = $form.children('ul.subsubsub').first();
        if($views.length){
          var $row = $('<div class="picky-orders-views-row" />');
          $views.before($row);
          $row.append($views);
          $row.append($btn);
          $row.append($search);
        } else {
          $search.before($btn);
        }
      }

      var force = paramsActive(['s']);
      wire($search, $btn, 'orders-search-' + idx, force);
      if(force){
        window.setTimeout(function(){
          $search.find('input[type="search"], input[name="s"]').first().trigger('focus');
        }, 0);
      }
    });
  }

  /**
   * Measure WP admin bar (+ footer for content padding). Rail height uses admin bar only
   * so the last nav icons are not clipped by a reserved footer gap.
   */
  function syncPickySettingsAdminChromeInsets(){
    var $root = $('#picky-wrap-content.picky-settings-surface');
    if(!$root.length){ return; }
    var rootEl = $root.get(0);

    var footerH = 40;
    var $footer = $('#wpfooter');
    if($footer.length){
      footerH = Math.ceil($footer.outerHeight() || 0);
    }
    if(footerH < 0){ footerH = 0; }
    if(footerH === 0){ footerH = 40; }

    var adminBarH = 0;
    if(document.body.classList.contains('admin-bar')){
      var ab = document.getElementById('wpadminbar');
      if(ab){
        adminBarH = Math.ceil(ab.getBoundingClientRect().height || ab.offsetHeight || 0);
      }
      if(adminBarH <= 0){
        adminBarH = 32;
      }
    }

    rootEl.style.setProperty('--picky-wp-footer-height', footerH + 'px');
    rootEl.style.setProperty('--picky-wp-admin-bar-height', adminBarH + 'px');
    rootEl.style.setProperty(
      '--picky-settings-viewport-height',
      'calc(100vh - ' + adminBarH + 'px)'
    );
  }

  /**
   * Pin fixed rail to the WP admin menu edge and sync --picky-rail-pad-offset.
   * Offset must be scroll-invariant: measuring getBoundingClientRect() while window.scrollX > 0
   * used to inflate padding and make the rail/content look like they "drift" until a later sync.
   */
  function syncPickySettingsSurfaceRailPadding(){
    syncPickySettingsAdminChromeInsets();
    var $root = $('#picky-wrap-content.picky-settings-surface');
    if(!$root.length){ return; }
    var surfaceEl = $root.find('.picky-settings-tabs-wrap--surface').get(0);
    var headerEl = $root.find('.picky-settings-tabs-header').get(0);
    if(!surfaceEl || !headerEl){ return; }
    var rootEl = $root.get(0);

    var scrollX = window.scrollX || window.pageXOffset || 0;
    var left = 160;
    var menu = document.getElementById('adminmenuwrap');
    if(menu){
      left = Math.round(menu.getBoundingClientRect().right);
    } else {
      var wpcontent = document.getElementById('wpcontent');
      if(wpcontent){
        left = Math.round(wpcontent.getBoundingClientRect().left);
      }
    }
    if(left < 0){ left = 0; }
    headerEl.style.left = left + 'px';

    var sr = surfaceEl.getBoundingClientRect();
    /* Document X of surface left ≈ viewport left + scrollX; rail left is viewport-fixed. */
    var offset = Math.round(left - (sr.left + scrollX));
    if(offset < 0){ offset = 0; }

    rootEl.style.setProperty('--picky-rail-pad-offset', offset + 'px');
    surfaceEl.style.removeProperty('padding-left');

    /* Window-level horizontal scroll fights position:fixed — snap back; content scrolls inside builder. */
    if(scrollX > 0){
      window.scrollTo(0, window.scrollY || window.pageYOffset || 0);
    }
  }

  /**
   * Sync rail + builder widths (immediate + rAF + delayed passes for late layout/fonts).
   */
  function schedulePickySettingsRailPaddingAndBuilderSync(){
    var runAll = function(){
      try { syncPickySettingsSurfaceRailPadding(); } catch(e){}
      try { syncBuilderShellWidth(); } catch(err){}
      try { syncBuilderToolbarWidth(); } catch(err2){}
      try { syncPickySettingsSurfaceRailPadding(); } catch(e2){}
      try { pickyScheduleInspectorDockSync(); } catch(e3){}
      try { pickyScheduleLeftColDockSync(); } catch(e4){}
    };
    runAll();
    if(typeof window.requestAnimationFrame === 'function'){
      window.requestAnimationFrame(function(){
        window.requestAnimationFrame(runAll);
      });
    }
    window.setTimeout(runAll, 0);
    window.setTimeout(runAll, 120);
    window.setTimeout(runAll, 360);
  }

  function initPickySettingsNavToggle(){
    var $root = $('#picky-wrap-content.picky-settings-surface');
    if(!$root.length){ return; }

    function setCollapsed(on){
      $root.toggleClass('picky-settings-nav-is-collapsed', on);
      var $collapse = $root.find('.picky-settings-nav-toggle--collapse');
      if($collapse.length){
        $collapse.attr('aria-expanded', on ? 'false' : 'true');
        var tExp = $collapse.attr('data-title-expanded');
        var tCol = $collapse.attr('data-title-collapsed');
        if(tExp && tCol){
          var t = on ? tCol : tExp;
          $collapse.attr('title', t);
          $collapse.attr('aria-label', t);
        }
      }
      try{ localStorage.setItem(Picky_NAV_COLLAPSE_KEY, on ? '1' : '0'); } catch(e){}
      schedulePickySettingsRailPaddingAndBuilderSync();
      window.setTimeout(function(){
        try { syncPickySettingsSurfaceRailPadding(); } catch(e){}
        try { syncBuilderShellWidth(); } catch(e2){}
        try { syncBuilderToolbarWidth(); } catch(e3){}
      }, 320);
    }

    try{
      if(localStorage.getItem(Picky_NAV_COLLAPSE_KEY) === '1'){
        setCollapsed(true);
      } else {
        schedulePickySettingsRailPaddingAndBuilderSync();
      }
    } catch(e){
      schedulePickySettingsRailPaddingAndBuilderSync();
    }

    $(document).on('click', '#picky-wrap-content.picky-settings-surface .picky-settings-nav-toggle--collapse', function(e){
      e.preventDefault();
      e.stopPropagation();
      setCollapsed(!$root.hasClass('picky-settings-nav-is-collapsed'));
    });

    $(document).on('click', '#picky-wrap-content.picky-settings-surface .picky-settings-nav-toggle--expand-hit', function(e){
      e.preventDefault();
      e.stopPropagation();
      setCollapsed(false);
    });

    /* Collapsed: click empty rail chrome (not menu items) expands — matches resize-cursor hint. */
    $(document).on('click', '#picky-wrap-content.picky-settings-surface.picky-settings-nav-is-collapsed .picky-settings-tabs-header', function(e){
      if($(e.target).closest('a, button, .picky-settings-tabs-header-btn, .picky-settings-tabs-submenu, input, select, textarea').length){
        return;
      }
      e.preventDefault();
      setCollapsed(false);
    });

    /* Prefer custom CSS tooltips: stash native title while hovering collapsed nav items. */
    $(document).on('mouseenter', '#picky-wrap-content.picky-settings-surface.picky-settings-nav-is-collapsed .picky-settings-tabs-header-btn[title], #picky-wrap-content.picky-settings-surface.picky-settings-nav-is-collapsed .picky-settings-nav-toggle--expand-hit[title], #picky-wrap-content.picky-settings-surface:not(.picky-settings-nav-is-collapsed) .picky-settings-nav-toggle--collapse[title]', function(){
      var el = this;
      if(!el.getAttribute('title')){ return; }
      el.setAttribute('data-picky-title', el.getAttribute('title'));
      el.removeAttribute('title');
    });
    $(document).on('mouseleave', '#picky-wrap-content.picky-settings-surface .picky-settings-tabs-header-btn, #picky-wrap-content.picky-settings-surface .picky-settings-nav-toggle--expand-hit, #picky-wrap-content.picky-settings-surface .picky-settings-nav-toggle--collapse', function(){
      var el = this;
      var t = el.getAttribute('data-picky-title');
      if(t){
        el.setAttribute('title', t);
        el.removeAttribute('data-picky-title');
      }
    });

    /* Seed data-picky-tip once so ::after tooltips work even after title is cleared. */
    function seedNavTips(){
      $root.find('.picky-settings-tabs-header-btn').each(function(){
        var el = this;
        if(el.getAttribute('data-picky-tip')){ return; }
        var tip = (el.getAttribute('title') || el.getAttribute('aria-label') || '').toString().trim();
        if(!tip){
          tip = ($(el).children('span').first().text() || '').toString().trim();
        }
        if(tip){
          el.setAttribute('data-picky-tip', tip);
        }
      });
    }
    seedNavTips();
  }

  function syncBuilderToolbarWidth(){
    var $inline = $('#picky-element-inspector');
    if(!$inline.length){ return; }
    /* Width comes from CSS (fit-content + max-width: 100%); clear legacy inline px from older builds. */
    $inline.css({ width: '', maxWidth: '' });
  }

  function syncBuilderShellWidth(){
    /* Do not set inline widths on the layout form while another tab is visible — breaks hit-testing / layout. */
    var $tab9 = $('#picky-settings-tab-9');
    if($tab9.length && !$tab9.is(':visible')){
      return;
    }
    var $container = $('.picky-builder-main-container').first();
    var $layout = $container.find('.picky-layout-wrap').first();
    var $tables = $container.find('.picky-tables-wrap').first();
    if(!$container.length || !$layout.length || !$tables.length){ return; }

    /* Fixed formula only: scrollWidth / outerWidth of nested nodes ratchet wider on each
       layout pass (transforms, abs toolbars, flex). Use saved canvas width + viewport padding. */
    var $sidebar = $tables.find('.picky-builder-left-col-slot').first();
    if(!$sidebar.length){
      $sidebar = $tables.find('.picky-builder-left-col').first();
    }
    var $canvasViewport = $tables.find('.picky-draggable-wrap').first();
    var $canvas = $tables.find('.picky-draggable').first();
    if(!$canvasViewport.length || !$canvas.length){ return; }

    var sidebarW = Math.ceil($sidebar.outerWidth(true) || 0);
    var $cwInput = $('input[name="picky_layout_settings[canvas_width]"]').first();
    var dragW = parseInt($cwInput.val(), 10);
    if(!(dragW >= 100 && dragW <= 6000)){
      dragW = Math.ceil(parseFloat($canvas.css('width')) || 0);
    }
    if(!(dragW >= 100)){
      dragW = Math.ceil($canvas.outerWidth() || 0);
    }
    var padL = parseFloat($canvasViewport.css('padding-left')) || 0;
    var padR = parseFloat($canvasViewport.css('padding-right')) || 0;
    var canvasColW = dragW + padL + padR;
    var w = Math.max(200, sidebarW + canvasColW);
    var cssW = { width: w + 'px', maxWidth: w + 'px' };
    $container.css(cssW);
    $layout.css(cssW);
    $tables.css(cssW);
    /* Keep canvas shell width on the builder only — not on the form (services library / backgrounds sit below). */
    $container.closest('.picky-form-options-save').css({ width: '', maxWidth: '' });
    /* Width changes can create transient window scrollX; re-pin rail after layout. */
    try { syncPickySettingsSurfaceRailPadding(); } catch(e){}
    pickyScheduleLeftColDockSync();
  }

  function pickyMountInspectorInline(){
    var $insp = $('#picky-element-inspector');
    /* Keep under settings surface so fixed overlay can sit beside the main rail
       (rail z-index:10; inspector:9) without being trapped in builder z-index:0. */
    var $host = $('#picky-wrap-content.picky-settings-surface').first();
    if(!$host.length){
      $host = $('.picky-builder-left-col').first();
    }
    if(!$host.length){
      $host = $('.picky-tables-wrap').first();
    }
    if(!$insp.length || !$host.length){ return; }
    if(!$insp.parent().is($host)){
      $insp.appendTo($host);
    }
  }

  /**
   * Pin the DnD / tools column like the element inspector: fixed to the viewport
   * while the canvas is on screen, then release at the canvas bottom. No inner scroll.
   */
  function pickySyncLeftColDockRect(){
    var $slot = $('.picky-tables-wrap > .picky-builder-left-col-slot').first();
    var $col = $slot.length
      ? $slot.children('.picky-builder-left-col').first()
      : $('.picky-tables-wrap > .picky-builder-left-col').first();
    if(!$col.length){ return; }
    var el = $col[0];
    var $bound = $('.picky-tables-wrap').first();
    if(!$bound.length){ return; }

    var adminBarH = 0;
    var $ab = $('#wpadminbar');
    if($ab.length && $ab.css('position') === 'fixed' && $ab.is(':visible')){
      adminBarH = Math.round($ab.outerHeight()) || 0;
    }

    var boundRect = $bound[0].getBoundingClientRect();
    var anchorEl = $slot.length ? $slot[0] : el;
    var anchor = anchorEl.getBoundingClientRect();
    var width = Math.max(160, Math.round(anchor.width) || 220);
    var left = Math.round(anchor.left);
    var colH = Math.max(1, Math.round(el.offsetHeight) || 1);

    /* Stick under WP admin bar with a little air — boundRect.top can sit flush on the toolbar. */
    var dockOffset = adminBarH + 10;
    var top = Math.max(dockOffset, Math.round(boundRect.top));
    if(top + colH > boundRect.bottom){
      top = Math.round(boundRect.bottom) - colH;
    }

    el.style.setProperty('--picky-dnd-top', Math.round(top) + 'px');
    el.style.setProperty('--picky-dnd-left', left + 'px');
    el.style.setProperty('--picky-dnd-w', width + 'px');
    $col.addClass('picky-dnd-docked');
  }

  var pickyLeftColDockRaf = null;
  function pickyScheduleLeftColDockSync(){
    if(pickyLeftColDockRaf){ return; }
    pickyLeftColDockRaf = window.requestAnimationFrame(function(){
      pickyLeftColDockRaf = null;
      pickySyncLeftColDockRect();
    });
  }

  /**
   * Pin the open inspector flush to the main settings rail, same vertical span
   * as the builder workspace. Width is fixed so horizontal page scroll cannot
   * squeeze or stretch the panel (rail is fixed; DnD column scrolls).
   */
  function pickySyncInspectorDockRect(){
    var $insp = $('#picky-element-inspector');
    if(!$insp.length){ return; }
    var el = $insp[0];
    if($insp.hasClass('is-empty')){
      el.style.removeProperty('--picky-insp-top');
      el.style.removeProperty('--picky-insp-left');
      el.style.removeProperty('--picky-insp-w');
      el.style.removeProperty('--picky-insp-h');
      return;
    }
    var $rail = $('#picky-wrap-content.picky-settings-surface .picky-settings-tabs-header').first();
    var $slot = $('.picky-builder-left-col-slot').first();
    var $col = $('.picky-builder-left-col').first();
    /* Stop at the canvas shell — not Services Library / Layout appearance below. */
    var $bound = $('.picky-builder-main-container').first();
    if(!$bound.length){
      $bound = $('.picky-tables-wrap').first();
    }
    if(!$bound.length){ return; }

    var railRight;
    if($rail.length){
      railRight = Math.round($rail[0].getBoundingClientRect().right);
    } else if($slot.length){
      railRight = Math.round($slot[0].getBoundingClientRect().left);
    } else if($col.length){
      railRight = Math.round($col[0].getBoundingClientRect().left);
    } else {
      railRight = 0;
    }
    var boundRect = $bound[0].getBoundingClientRect();
    var canvasTop = Math.round(boundRect.top);
    var canvasBottom = Math.round(boundRect.bottom);

    var adminBarH = 0;
    var $ab = $('#wpadminbar');
    if($ab.length && $ab.css('position') === 'fixed' && $ab.is(':visible')){
      adminBarH = Math.round($ab.outerHeight()) || 0;
    }
    var vh = window.innerHeight || document.documentElement.clientHeight || 0;

    /* Sticky inside the canvas only:
       - stick under the WP admin bar while the canvas is on screen
       - unstick at canvas bottom so the panel scrolls away before Services */
    var top = Math.max(adminBarH, canvasTop);
    var bottom = Math.min(vh, canvasBottom);
    if(bottom <= top){
      /* No visible canvas overlap — park against canvas bottom (leaves with it). */
      var parkH = Math.min(220, Math.max(80, canvasBottom - canvasTop));
      bottom = canvasBottom;
      top = bottom - parkH;
    }

    var width = 280;
    try {
      var cs = window.getComputedStyle(el);
      var fromCss = parseFloat(cs.getPropertyValue('--picky-insp-fixed-w'));
      if(isFinite(fromCss) && fromCss >= 200){ width = Math.round(fromCss); }
    } catch(eW){}
    var height = Math.max(0, bottom - top);

    el.style.setProperty('--picky-insp-top', top + 'px');
    el.style.setProperty('--picky-insp-left', railRight + 'px');
    el.style.setProperty('--picky-insp-w', width + 'px');
    el.style.setProperty('--picky-insp-h', height + 'px');
  }

  var pickyInspectorDockRaf = null;
  function pickyScheduleInspectorDockSync(){
    if(pickyInspectorDockRaf){ return; }
    pickyInspectorDockRaf = window.requestAnimationFrame(function(){
      pickyInspectorDockRaf = null;
      pickySyncInspectorDockRect();
    });
  }

  function pickyDestroyInspectorDraggable(){
    var $insp = $('#picky-element-inspector');
    if(!$insp.length){ return; }
    try{
      if($insp.hasClass('ui-draggable')){
        $insp.draggable('destroy');
      }
    } catch(ex){}
    $insp.removeData('picky-inspector-draggable');
  }

  function pickyResetInlineTextField(){
    var $slot = $('#picky-inline-editing-text-slot');
    if(!$slot.length){ return; }
    var $field = $slot.children('.picky-element-edit-field').first();
    if($field.length){
      var $editor = $field.data('pickyInlineOriginEditor');
      if(!$editor || !$editor.length){
        $editor = $('#picky-builder-toolbar .picky-element-edit-wrap').first();
      }
      var $fields = $editor.find('.picky-element-edit-fields').first();
      if($fields.length){
        $field.removeClass('picky-inline-text-hosted').prependTo($fields);
      } else {
        $field.removeClass('picky-inline-text-hosted');
      }
      $field.removeData('pickyInlineOriginEditor');
    }
    // Put the slot back to its original inspector row location.
    var $slotOriginParent = $slot.data('pickySlotOriginParent');
    var $slotOriginAfter = $slot.data('pickySlotOriginAfter');
    if($slotOriginParent && $slotOriginParent.length){
      if($slotOriginAfter && $slotOriginAfter.length && $.contains($slotOriginParent[0], $slotOriginAfter[0])){
        $slot.insertAfter($slotOriginAfter);
      } else {
        $slot.appendTo($slotOriginParent);
      }
    }
    $slot.removeData('pickySlotOriginParent');
    $slot.removeData('pickySlotOriginAfter');
    $slot.hide().empty();
  }

  function pickyMountInlineTextField($editor, type){
    pickyResetInlineTextField();
    if(type !== 'type-text' || !$editor || !$editor.length){ return; }
    var $slot = $('#picky-inline-editing-text-slot');
    if(!$slot.length){ return; }
    var $fields = $editor.find('.picky-element-edit-fields').first();
    if($fields.length){
      // Re-home the slot itself into the active editor (requested UX).
      if(!$slot.data('pickySlotOriginParent')){
        $slot.data('pickySlotOriginParent', $slot.parent());
        $slot.data('pickySlotOriginAfter', $slot.prev());
      }
      $slot.prependTo($fields);
    }
    var $textField = $editor.find('.picky-element-edit-field:has(.picky-element-edit-text-input)').first();
    if(!$textField.length){ return; }
    $textField.data('pickyInlineOriginEditor', $editor);
    $textField.addClass('picky-inline-text-hosted').show().appendTo($slot);
    $slot.show();
  }

  function pickyOrderTextFields($editor){
    if(!$editor || !$editor.length){ return; }
    var $fields = $editor.find('.picky-element-edit-fields').first();
    if(!$fields.length){ return; }

    /* Keep text controls in a predictable compact order:
       Font Family, Font Weight, Font Size, Padding, Opacity. */
    var ordered = [
      '.picky-element-edit-field:has(.picky-element-edit-font-family-input)',
      '.picky-element-edit-field:has(.picky-element-edit-font-weight-input)',
      '.picky-element-edit-field:has(.picky-element-edit-font-size-input)',
      '.picky-element-edit-field:has(.picky-element-edit-line-height-input)',
      '.picky-element-edit-field:has(.picky-element-edit-padding-input)',
      '.picky-field-opacity'
    ];
    $.each(ordered, function(_, selector){
      var $f = $fields.children(selector).first();
      if($f.length){ $f.appendTo($fields); }
    });
  }

  function pickyEnsureInspectorDraggable(){
    // Docked left rail: inspector overlays Drag & Drop column (not a floating popup).
    pickyDestroyInspectorDraggable();
  }

  function pickyPlaceInspectorNearCanvasOnce(){
    var $insp = $('#picky-element-inspector');
    if(!$insp.length || !$insp.is(':visible')){ return; }
    pickyMountInspectorInline();
    /* Position comes from CSS (sticky on layout builder); do not set top/left here. */
  }


  $('.picky-more-time-wrap input[type="checkbox"]').on('change', function(){
    if($(this).is(":checked")){
      $(this).closest('.picky-more-time-wrap-all').find('.picky-more-time-selects-wrap').fadeIn(100);
    } else {
      $(this).closest('.picky-more-time-wrap-all').find('.picky-more-time-selects-wrap').hide();
    }
  });

  // Settings radios: keep selection reliable even when admin CSS/HTML affects native label->input behavior.
  $(document).on('click', '#picky-wrap-content .picky-settings-radio label', function(){
    var $wrap = $(this).closest('.picky-settings-radio');
    var $radio = $wrap.find('input[type="radio"]').first();
    if($radio.length){
      $radio.prop('checked', true).trigger('change');
    }
  });

  // Live canvas resize when the user changes canvas width/height inputs in the builder.
  // This ensures the visual canvas matches what will be saved so the user can re-position
  // elements accurately before saving.
  $(document).on('input change', 'input[name="picky_layout_settings[canvas_width]"]', function(){
    var v = parseInt($(this).val(), 10);
    if (v >= 500 && v <= 2500) {
      $('.picky-draggable').css('width', v + 'px');
      syncBuilderToolbarWidth();
      syncBuilderShellWidth();
    }
  });
  $(document).on('input change', 'input[name="picky_layout_settings[canvas_height]"]', function(){
    var v = parseInt($(this).val(), 10);
    if (v >= 500 && v <= 2500) {
      $('.picky-draggable').css('height', v + 'px');
      syncBuilderToolbarWidth();
      syncBuilderShellWidth();
    }
  });
  $(window).on('resize', function(){
    try { schedulePickySettingsRailPaddingAndBuilderSync(); } catch(e){}
    pickyScheduleLeftColDockSync();
    pickyScheduleInspectorDockSync();
  });
  /* Horizontal window scroll desyncs fixed rail vs content — correct immediately. */
  $(window).on('scroll', function(){
    pickyScheduleLeftColDockSync();
    if((window.scrollX || window.pageXOffset || 0) > 0){
      try { syncPickySettingsSurfaceRailPadding(); } catch(e){}
    }
  });

  function getCanvasBackgroundModeCss(mode){
    var m = (mode || 'cover').toString();
    if(m === 'cover'){
      return { 'background-size': 'cover', 'background-repeat': 'no-repeat', 'background-position': 'center center' };
    }
    if(m === 'contain'){
      return { 'background-size': 'contain', 'background-repeat': 'no-repeat', 'background-position': 'center center' };
    }
    if(m === 'stretch'){
      return { 'background-size': '100% 100%', 'background-repeat': 'no-repeat', 'background-position': 'center center' };
    }
    if(m === 'repeat-x'){
      return { 'background-size': 'auto', 'background-repeat': 'repeat-x', 'background-position': 'top left' };
    }
    if(m === 'repeat-y'){
      return { 'background-size': 'auto', 'background-repeat': 'repeat-y', 'background-position': 'top left' };
    }
    if(m === 'center'){
      return { 'background-size': 'auto', 'background-repeat': 'no-repeat', 'background-position': 'center center' };
    }
    return { 'background-size': 'auto', 'background-repeat': 'repeat', 'background-position': 'top left' };
  }

  function applyCanvasBackgroundMode(mode){
    var cssMap = getCanvasBackgroundModeCss(mode);
    $('.picky-draggable').css(cssMap);
    $('.picky-background-field[data-background="background-canvas"] .picky-background-image-holder-wrap').css(cssMap);
  }

  $(document).on('change', 'select[name="picky_layout_settings[background-canvas-mode]"], select[name="picky_layout_bg_canvas_mode"]', function(){
    applyCanvasBackgroundMode($(this).val());
  });
  $(document).on('picky:canvas-bg-mode', function(_e, mode){
    applyCanvasBackgroundMode(mode);
  });
  $(document).on('change', 'input[name="picky_layout_transparent_canvas"]', function(){
    var on = $(this).is(':checked');
    $('.picky-draggable-wrap').toggleClass('picky-canvas-transparent-bg', on);
    $('.picky-draggable').toggleClass('picky-canvas-transparent-bg', on);
  });
  function makeid(long) {
    var text = "";
    var possible = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    for (var i = 0; i < long; i++) {
      text += possible.charAt(Math.floor(Math.random() * possible.length));
    }
    return text;
  }

  /** True for palette items that become bookable tables on the canvas. */
  function pickySidebarWrapIsBookable($wrap){
    if(!$wrap || !$wrap.length){ return false; }
    var t = ($wrap.attr('data-type') || '').toString();
    return (
      t === 'type-1' || t === 'type-4' || t === 'type-6' || t === 'type-table' ||
      t === 'type-square' || t === 'type-circle'
    );
  }

  /**
   * Elements whose fill lives in .picky-element-shape and should offer bookable "corner radius"
   * (legacy layouts often use type-table / type-2 / type-3 / type-5, not only type-1).
   * Excludes decorative types and circles (they use other styling).
   */
  function pickyUsesBookableSquareRadius($el){
    if(!$el || !$el.length){ return false; }
    var dt = ($el.attr('data-type') || '').toString().trim();
    var noRadius = {
      'type-wall': 1,
      'type-text': 1,
      'type-shape': 1,
      'type-icon': 1,
      'type-4': 1,
      'type-circle': 1
    };
    if(noRadius[dt]){ return false; }
    if($el.hasClass('type-wall') || $el.hasClass('type-text') || $el.hasClass('type-shape') || $el.hasClass('type-icon')){ return false; }
    if($el.hasClass('type-4') || $el.hasClass('type-circle')){ return false; }
    return true;
  }

  /** Sidebar rows that drag a full `.picky-element` clone (not the icon chip). */
  function pickySidebarWrapUsesFullElementDragHelper($wrap){
    if(!$wrap || !$wrap.length){ return false; }
    if(pickySidebarWrapIsBookable($wrap)){ return true; }
    var t = ($wrap.attr('data-type') || '').toString();
    return t === 'type-wall' || t === 'type-text' || t === 'type-shape' || t === 'type-icon';
  }

  /** Measure a sidebar template .picky-element when it is not laid out (e.g. hidden for icon-only rows). */
  function getElementBoxSizeForSidebarTemplate($el){
    if(!$el || !$el.length){
      return { width: 100, height: 100 };
    }
    var $ghost = $el.clone().css({
      position: 'absolute',
      left: '-24000px',
      top: '0',
      visibility: 'hidden',
      display: 'block',
      opacity: '0',
      pointerEvents: 'none'
    }).appendTo('body');
    var $box = $ghost.find('.picky-element-table-wrap').first();
    var w = $box.length ? $box.outerWidth(true) : $ghost.outerWidth(true);
    var h = $box.length ? $box.outerHeight(true) : $ghost.outerHeight(true);
    $ghost.remove();
    return {
      width: Math.round(parseFloat(w)) || 100,
      height: Math.round(parseFloat(h)) || 100
    };
  }

  function getElementBoxSize($element){
    if($element.hasClass('picky-element-sidebar-wrap')){
      return getElementBoxSizeForSidebarTemplate($element.find('.picky-element').first());
    }
    var $box = $element.find('.picky-element-table-wrap').first();
    var w = $box.length ? $box.outerWidth(true) : $element.outerWidth(true);
    var h = $box.length ? $box.outerHeight(true) : $element.outerHeight(true);
    return {
      width: Math.round(parseFloat(w)) || 0,
      height: Math.round(parseFloat(h)) || 0
    };
  }

  /** Padding used in layout/clamp; only default to 10 when CSS is missing (NaN). */
  function pickyElementLayoutPadLeft($el){
    if(!$el || !$el.length){ return 10; }
    var p = parseFloat($el.css('padding-left'));
    return isNaN(p) ? 10 : p;
  }

  function pickyElementLayoutPadTop($el){
    if(!$el || !$el.length){ return 10; }
    var p = parseFloat($el.css('padding-top'));
    return isNaN(p) ? 10 : p;
  }

  /**
   * Bookable collision rect when the element wrapper is at the given layout left/top
   * (must match ui.position after clamp — do not read css('left') in the same drag tick).
   */
  function pickyBookableCollisionRectAt($el, curL, curT){
    if(!$el || !$el.length){ return { x: 0, y: 0, x1: 0, y1: 0 }; }
    if(isNaN(curL)){ curL = 0; }
    if(isNaN(curT)){ curT = 0; }
    var $tw = $el.find('.picky-element-table-wrap').first();
    if(!$tw.length){
      var box = getElementBoxSize($el);
      return { x: curL, y: curT, x1: curL + box.width, y1: curT + box.height };
    }
    var pad = pickyElementLayoutPadLeft($el);
    var twW = parseFloat($tw.outerWidth());
    var twH = parseFloat($tw.outerHeight());
    if(isNaN(twW)){ twW = 0; }
    if(isNaN(twH)){ twH = 0; }
    var vBB = getVisualBoundingBox($el, twW, twH);
    var bbW = vBB.width;
    var bbH = vBB.height;
    var vOffsetL = (twW - bbW) / 2;
    var vOffsetT = (twH - bbH) / 2;
    var x = curL + pad + vOffsetL;
    var y = curT + pad + vOffsetT;
    return { x: x, y: y, x1: x + bbW, y1: y + bbH };
  }

  /**
   * Collision box for bookable canvas elements in .picky-draggable layout space (same as CSS left/top
   * and pickyApplyCanvasDragFromMouse), so pan/zoom does not desync rects from drag position.
   */
  function pickyBookableCollisionRect($el){
    if(!$el || !$el.length){ return { x: 0, y: 0, x1: 0, y1: 0 }; }
    var curL = parseFloat($el.css('left'));
    var curT = parseFloat($el.css('top'));
    if(isNaN(curL)){ curL = 0; }
    if(isNaN(curT)){ curT = 0; }
    return pickyBookableCollisionRectAt($el, curL, curT);
  }

  function refreshCoordinates(){
    coordinates = new Array();
    elements = [];
    $('.picky-draggable .picky-element').each(function(e){
      // Walls and Text are decorative — exclude from collision detection
      if($(this).attr('data-type') === 'type-wall' || $(this).attr('data-type') === 'type-text' || $(this).attr('data-type') === 'type-shape' || $(this).attr('data-type') === 'type-icon'){ return; }
      elements.push($(this));
    });
    for (var k = 0; k <= elements.length - 1; k++) {
      coordinates[k] = pickyBookableCollisionRect($(elements[k]));
    }
    return coordinates;
  }

  /* ===== Shift+multi-select (canvas) ===== */
  var Picky_MS_CLASS = 'picky-ms-selected';

  function pickyGetMultiSelected(){
    return $('.picky-draggable .picky-element.' + Picky_MS_CLASS);
  }

  function pickyClearMultiSelect(){
    if(typeof pickyDestroyMsTransformBox === 'function'){
      pickyDestroyMsTransformBox();
    }
    pickyGetMultiSelected().removeClass(Picky_MS_CLASS);
  }

  function pickyMultiSelectCount(){
    return pickyGetMultiSelected().length;
  }

  /**
   * Elements that should share resize mode / scale with $primary.
   * Multi-select (Shift): every selected item (+ primary); otherwise just the primary.
   */
  function pickyResizeTargetGroup($primary){
    if(!$primary || !$primary.length){ return $(); }
    var $ms = pickyGetMultiSelected();
    if($ms.length > 1){
      return $ms.add($primary);
    }
    if($ms.length === 1 && !$ms.is($primary)){
      return $ms.add($primary);
    }
    return $primary;
  }

  /** Snapshot of one member for Photoshop-style group transform. */
  function pickyMsMemberSnapshot($el){
    var $tw = $el.find('.picky-element-table-wrap').first();
    if(!$tw.length){ $tw = $el; }
    var w = parseFloat($tw.outerWidth()) || 20;
    var h = parseFloat($tw.outerHeight()) || 20;
    var l = parseFloat($el.css('left')) || 0;
    var t = parseFloat($el.css('top')) || 0;
    var pad = typeof pickyElementLayoutPadLeft === 'function' ? pickyElementLayoutPadLeft($el) : 0;
    var padT = typeof pickyElementLayoutPadTop === 'function' ? pickyElementLayoutPadTop($el) : 0;
    var twPos = $tw.position();
    var originL = pad + (twPos && !isNaN(twPos.left) ? twPos.left : 0);
    var originT = padT + (twPos && !isNaN(twPos.top) ? twPos.top : 0);
    return {
      el: $el,
      tw: $tw,
      w: w,
      h: h,
      originL: originL,
      originT: originT,
      wrapL: l + originL,
      wrapT: t + originT,
      startFont: $el.hasClass('type-text') ? (parseFloat($el.attr('data-font-size')) || 16) : 0
    };
  }

  /** Axis-aligned canvas AABB of the selection (wrap boxes). */
  function pickyMsGroupBounds($group){
    var minL = Infinity;
    var minT = Infinity;
    var maxR = -Infinity;
    var maxB = -Infinity;
    var members = [];
    if(!$group || !$group.length){ return null; }
    $group.each(function(){
      var m = pickyMsMemberSnapshot($(this));
      members.push(m);
      minL = Math.min(minL, m.wrapL);
      minT = Math.min(minT, m.wrapT);
      maxR = Math.max(maxR, m.wrapL + m.w);
      maxB = Math.max(maxB, m.wrapT + m.h);
    });
    if(!isFinite(minL) || !members.length){ return null; }
    return {
      members: members,
      l: minL,
      t: minT,
      w: Math.max(20, maxR - minL),
      h: Math.max(20, maxB - minT)
    };
  }

  function pickyPositionMsTransformBox($box, bounds){
    if(!$box || !$box.length || !bounds){ return; }
    $box.css({
      left: bounds.l + 'px',
      top: bounds.t + 'px',
      width: bounds.w + 'px',
      height: bounds.h + 'px'
    });
  }

  function pickyDestroyMsTransformBox(){
    $(document).off('.pickyMsTransform');
    $('.picky-ms-transform-box').off('.pickyMsTransform').remove();
    $('.picky-draggable').removeClass('picky-ms-group-resizing');
    $('.picky-element.picky-ms-group-member').removeClass('picky-ms-group-member picky-resize-enabled');
  }

  /**
   * Photoshop-style Free Transform for a multi-selection:
   * one bounding box; scale member positions + sizes from the group anchor.
   */
  function pickyEnableMsGroupResize($group, $primary){
    pickyDestroyMsTransformBox();
    if(!$group || $group.length < 2){ return; }
    var $canvas = $group.first().closest('.picky-draggable');
    if(!$canvas.length){ return; }

    $canvas.addClass('picky-ms-group-resizing');
    $group.addClass('picky-ms-group-member picky-resize-enabled');

    var handleList = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
    var $box = $('<div class="picky-ms-transform-box" aria-hidden="true"></div>');
    var hi;
    for(hi = 0; hi < handleList.length; hi++){
      $box.append(
        '<div class="picky-custom-resizer picky-resizer-' + handleList[hi] +
        '" data-dir="' + handleList[hi] + '"></div>'
      );
    }
    $canvas.append($box);

    var bounds0 = pickyMsGroupBounds($group);
    if(!bounds0){
      pickyDestroyMsTransformBox();
      return;
    }
    pickyPositionMsTransformBox($box, bounds0);
    $box.data('picky-ms-primary', $primary);
    $box.data('picky-ms-group', $group);

    $box.on('mousedown.pickyMsTransform', '.picky-custom-resizer', function(e){
      e.preventDefault();
      e.stopPropagation();

      var dir = ($(this).attr('data-dir') || '').toString();
      var start = pickyMsGroupBounds($group);
      if(!start){ return; }
      var members = start.members;
      var gL = start.l;
      var gT = start.t;
      var gW = start.w;
      var gH = start.h;
      var canvasW = parseFloat($canvas.outerWidth()) || 0;
      var canvasH = parseFloat($canvas.outerHeight()) || 0;

      var anchorX = gL + gW / 2;
      var anchorY = gT + gH / 2;
      if(dir.indexOf('w') !== -1){ anchorX = gL + gW; }
      else if(dir.indexOf('e') !== -1){ anchorX = gL; }
      if(dir.indexOf('n') !== -1){ anchorY = gT + gH; }
      else if(dir.indexOf('s') !== -1){ anchorY = gT; }

      var i;
      for(i = 0; i < members.length; i++){
        if(members[i].el.hasClass('ui-draggable')){
          try{ members[i].el.draggable('disable'); } catch(exDis){}
        }
      }
      if($primary && $primary.length && typeof pickySetInlineCanvasToolsVisibleForElement === 'function'){
        pickySetInlineCanvasToolsVisibleForElement($primary[0], false);
      }

      $(document).on('mousemove.pickyMsTransform', function(ev){
        var cur = pickyPageXYToCanvasLocal($canvas, ev.pageX, ev.pageY);
        var newL = gL;
        var newT = gT;
        var newW = gW;
        var newH = gH;

        if(dir.indexOf('e') !== -1){
          newW = Math.max(20, cur.x - gL);
        }
        if(dir.indexOf('w') !== -1){
          newW = Math.max(20, (gL + gW) - cur.x);
          newL = (gL + gW) - newW;
        }
        if(dir.indexOf('s') !== -1){
          newH = Math.max(20, cur.y - gT);
        }
        if(dir.indexOf('n') !== -1){
          newH = Math.max(20, (gT + gH) - cur.y);
          newT = (gT + gH) - newH;
        }

        /* Shift = lock aspect ratio (Photoshop constrain). */
        if(ev.shiftKey){
          var sxLock = newW / gW;
          var syLock = newH / gH;
          var sLock;
          if(dir.length === 1){
            sLock = (dir === 'e' || dir === 'w') ? sxLock : syLock;
          } else {
            sLock = Math.max(sxLock, syLock);
          }
          sLock = Math.max(20 / gW, 20 / gH, sLock);
          newW = gW * sLock;
          newH = gH * sLock;
          if(dir.indexOf('w') !== -1){ newL = anchorX - newW; }
          else if(dir.indexOf('e') !== -1){ newL = anchorX; }
          else { newL = anchorX - newW / 2; }
          if(dir.indexOf('n') !== -1){ newT = anchorY - newH; }
          else if(dir.indexOf('s') !== -1){ newT = anchorY; }
          else { newT = anchorY - newH / 2; }
        }

        if(newL < 0){ newW = Math.max(20, newW + newL); newL = 0; }
        if(newT < 0){ newH = Math.max(20, newH + newT); newT = 0; }
        if(canvasW > 0 && newL + newW > canvasW){ newW = Math.max(20, canvasW - newL); }
        if(canvasH > 0 && newT + newH > canvasH){ newH = Math.max(20, canvasH - newT); }

        var sx = newW / gW;
        var sy = newH / gH;
        if(!isFinite(sx) || sx <= 0){ sx = 1; }
        if(!isFinite(sy) || sy <= 0){ sy = 1; }

        for(i = 0; i < members.length; i++){
          var m = members[i];
          var nw = Math.max(4, m.w * sx);
          var nh = Math.max(4, m.h * sy);
          var nWrapL = newL + (m.wrapL - gL) * sx;
          var nWrapT = newT + (m.wrapT - gT) * sy;
          var nElL = nWrapL - m.originL;
          var nElT = nWrapT - m.originT;

          if(m.startFont > 0){
            var rFont = Math.sqrt(sx * sy);
            if(!isFinite(rFont) || rFont <= 0){ rFont = 1; }
            var nfs = Math.max(8, Math.min(200, Math.round(m.startFont * rFont)));
            m.el.attr('data-font-size', String(nfs));
            if(typeof pickySyncTextFontSizeInputFromElement === 'function'){
              pickySyncTextFontSizeInputFromElement(m.el);
            }
            m.tw.css({ width: '', height: '', left: '', top: '' });
            if(typeof updateTextPreview === 'function'){ updateTextPreview(m.el); }
            m.el.css({ left: nElL + 'px', top: nElT + 'px' });
          } else {
            m.tw.css({ width: nw + 'px', height: nh + 'px', left: 0, top: 0 });
            m.el.css({ left: nElL + 'px', top: nElT + 'px' });
            if(typeof updateTextPreview === 'function'){ updateTextPreview(m.el); }
          }
          if(typeof updateInteractiveHandlesPosition === 'function'){
            updateInteractiveHandlesPosition(m.el);
          }
        }

        pickyPositionMsTransformBox($box, { l: newL, t: newT, w: newW, h: newH });
      });

      $(document).on('mouseup.pickyMsTransform', function(){
        $(document).off('mousemove.pickyMsTransform mouseup.pickyMsTransform');
        for(i = 0; i < members.length; i++){
          if(members[i].el.hasClass('ui-draggable')){
            try{ members[i].el.draggable('enable'); } catch(exEn){}
          }
          if(typeof pushIntoCanvas === 'function'){
            pushIntoCanvas(members[i].el, true);
          }
        }
        var nb = pickyMsGroupBounds($group);
        if(nb){ pickyPositionMsTransformBox($box, nb); }
        if($primary && $primary.length && typeof pickySetInlineCanvasToolsVisibleForElement === 'function'){
          pickySetInlineCanvasToolsVisibleForElement($primary[0], true);
        }
        if($primary && $primary.length && typeof pickyPositionInlineCanvasTools === 'function'){
          pickyPositionInlineCanvasTools($primary);
        }
        if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
      });
    });
  }

  function pickyIsMsGroupResizeActive(){
    return $('.picky-ms-transform-box').length > 0;
  }

  /** Visual AABB clamp limits for an element on the canvas (layout CSS coords). */
  function pickyElementClampLimits($el, $canvas){
    var $tw = $el.find('.picky-element-table-wrap');
    var pad = pickyElementLayoutPadLeft($el);
    var canvasW = parseFloat($canvas.outerWidth());
    var canvasH = parseFloat($canvas.outerHeight());
    var twW = parseFloat($tw.outerWidth());
    var twH = parseFloat($tw.outerHeight());
    var vBB = getVisualBoundingBox($el, twW, twH);
    var vOffsetL = (twW - vBB.width) / 2;
    var vOffsetT = (twH - vBB.height) / 2;
    return {
      minL: -vOffsetL - pad,
      maxL: canvasW - vBB.width - vOffsetL - pad,
      minT: -vOffsetT - pad,
      maxT: canvasH - vBB.height - vOffsetT - pad
    };
  }

  function pickyMultiDragCapture($primary){
    if(!$primary || !$primary.length){ return false; }
    var $group = pickyGetMultiSelected();
    if(!$primary.hasClass(Picky_MS_CLASS) || $group.length < 2){
      $primary.removeData('picky-multi-drag');
      return false;
    }
    var starts = [];
    $group.each(function(){
      var $el = $(this);
      starts.push({
        el: $el,
        left: parseFloat($el.css('left')) || 0,
        top: parseFloat($el.css('top')) || 0
      });
    });
    $primary.data('picky-multi-drag', {
      starts: starts,
      primaryLeft: parseFloat($primary.css('left')) || 0,
      primaryTop: parseFloat($primary.css('top')) || 0
    });
    return true;
  }

  function pickyMultiDragFollow($primary, ui){
    var state = $primary.data('picky-multi-drag');
    if(!state || !state.starts || !state.starts.length){ return; }
    var $canvas = $primary.closest('.picky-draggable');
    if(!$canvas.length){ return; }

    var dL = ui.position.left - state.primaryLeft;
    var dT = ui.position.top - state.primaryTop;
    var i, row, lim, nl, nt;

    /* Shrink delta so every selected element stays inside the canvas. */
    for(i = 0; i < state.starts.length; i++){
      row = state.starts[i];
      lim = pickyElementClampLimits(row.el, $canvas);
      nl = row.left + dL;
      nt = row.top + dT;
      if(nl < lim.minL){ dL = lim.minL - row.left; }
      if(nl > lim.maxL){ dL = lim.maxL - row.left; }
      if(nt < lim.minT){ dT = lim.minT - row.top; }
      if(nt > lim.maxT){ dT = lim.maxT - row.top; }
    }
    /* Second pass after clamping one axis (corners). */
    for(i = 0; i < state.starts.length; i++){
      row = state.starts[i];
      lim = pickyElementClampLimits(row.el, $canvas);
      nl = row.left + dL;
      nt = row.top + dT;
      if(nl < lim.minL){ dL = lim.minL - row.left; }
      if(nl > lim.maxL){ dL = lim.maxL - row.left; }
      if(nt < lim.minT){ dT = lim.minT - row.top; }
      if(nt > lim.maxT){ dT = lim.maxT - row.top; }
    }

    ui.position.left = state.primaryLeft + dL;
    ui.position.top = state.primaryTop + dT;

    for(i = 0; i < state.starts.length; i++){
      row = state.starts[i];
      if(row.el.is($primary)){ continue; }
      row.el.css({
        left: (row.left + dL) + 'px',
        top: (row.top + dT) + 'px'
      });
    }
  }

  function pickyMultiDragClear($primary){
    if($primary && $primary.length){
      $primary.removeData('picky-multi-drag');
    }
  }

  /**
   * Shift+click: toggle element in the multi-select set.
   * Seeds the currently opened element into the set on first add.
   */
  function pickyMultiSelectToggle($el){
    if(!$el || !$el.length){ return; }
    var $opened = $('.picky-draggable .picky-element.opened');
    if($opened.length && pickyMultiSelectCount() === 0 && !$opened.is($el)){
      $opened.addClass(Picky_MS_CLASS);
    }

    $el.toggleClass(Picky_MS_CLASS);

    if($el.hasClass(Picky_MS_CLASS)){
      if(typeof pickyOpenElementEditor === 'function'){
        pickyOpenElementEditor($el, { keepMulti: true });
      }
      $el.addClass(Picky_MS_CLASS);
    } else if($el.hasClass('opened')){
      var $next = pickyGetMultiSelected().first();
      if($next.length && typeof pickyOpenElementEditor === 'function'){
        pickyOpenElementEditor($next, { keepMulti: true });
      } else if(typeof pickyCloseElementEditor === 'function'){
        pickyCloseElementEditor();
      }
    }

    if(pickyMultiSelectCount() > 0){
      $('.picky-draggable .picky-element.opened').addClass(Picky_MS_CLASS);
    }
  }

  /* ===== Smart alignment guides (SVG overlay in client coords) ===== */
  var PICKY_ALIGN_TOL = 8;
  var PICKY_ALIGN_SHOW_TOL = 72;
  window.__PICKY_ALIGN_V = 'align14';
  window.__PICKY_ALIGN_OPTION = false;

  function pickyIsDecorativeAlignType(type){
    type = (type || '').toString();
    return type === 'type-wall' || type === 'type-text' || type === 'type-shape' || type === 'type-icon';
  }

  function pickyAlignResolveCanvas($el, $canvas){
    if($canvas && $canvas.length){ return $canvas; }
    if($el && $el.length){
      var $c = $el.closest('.picky-draggable');
      if($c.length){ return $c; }
    }
    return $('.picky-draggable').first();
  }

  function pickyGetElementAlignBox($el, curL, curT){
    if(!$el || !$el.length){
      return { l: 0, r: 0, t: 0, b: 0, cX: 0, cY: 0, bookable: false };
    }
    if(typeof curL !== 'number' || isNaN(curL)){
      curL = parseFloat($el.css('left'));
      if(isNaN(curL)){ curL = 0; }
    }
    if(typeof curT !== 'number' || isNaN(curT)){
      curT = parseFloat($el.css('top'));
      if(isNaN(curT)){ curT = 0; }
    }
    var rect = pickyBookableCollisionRectAt($el, curL, curT);
    var type = ($el.attr('data-type') || '').toString();
    if(!type){
      if($el.hasClass('type-wall')) type = 'type-wall';
      else if($el.hasClass('type-text')) type = 'type-text';
      else if($el.hasClass('type-shape')) type = 'type-shape';
      else if($el.hasClass('type-icon')) type = 'type-icon';
    }
    return {
      l: rect.x, r: rect.x1, t: rect.y, b: rect.y1,
      cX: (rect.x + rect.x1) / 2,
      cY: (rect.y + rect.y1) / 2,
      bookable: !pickyIsDecorativeAlignType(type)
    };
  }

  function pickyEnsureAlignGuidesOverlay(){
    var $ov = $('#picky-align-guides-overlay');
    if(!$ov.length){
      $ov = $('<div id="picky-align-guides-overlay" class="picky-align-guides-overlay" data-picky-align="' + (window.__PICKY_ALIGN_V || 'align5') + '" aria-hidden="true"></div>');
      $ov.html('<svg class="picky-align-guides-svg" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%"></svg>');
      $('body').append($ov);
    } else if(!$ov.find('svg.picky-align-guides-svg').length){
      $ov.html('<svg class="picky-align-guides-svg" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%"></svg>');
    }
    return $ov;
  }

  function pickyClearAlignGuides(){
    var $ov = $('#picky-align-guides-overlay');
    if($ov.length){
      $ov.removeClass('is-active').css('display', 'none');
      $ov.find('svg.picky-align-guides-svg').empty();
    }
    $('.picky-align-gap-label').remove();
  }

  function pickyAlignSvgLine(svg, x1, y1, x2, y2, opts){
    if(!isFinite(x1) || !isFinite(y1) || !isFinite(x2) || !isFinite(y2)){ return; }
    opts = opts || {};
    var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    line.setAttribute('x1', String(x1));
    line.setAttribute('y1', String(y1));
    line.setAttribute('x2', String(x2));
    line.setAttribute('y2', String(y2));
    line.setAttribute('stroke', opts.stroke || 'rgba(168, 85, 247, 0.45)');
    line.setAttribute('stroke-width', String(opts.width || 1));
    if(opts.solid){
      line.setAttribute('stroke-dasharray', 'none');
    } else {
      line.setAttribute('stroke-dasharray', '4 3');
    }
    line.setAttribute('stroke-linecap', 'butt');
    svg.appendChild(line);
  }

  /** Short end-caps so a gap line reads as “between A and B”, not a through-line. */
  function pickyAlignSvgGapCaps(svg, x1, y1, x2, y2, opts){
    var horiz = Math.abs(y2 - y1) < Math.abs(x2 - x1);
    var cap = 7;
    opts = opts || {};
    if(horiz){
      pickyAlignSvgLine(svg, x1, y1 - cap, x1, y1 + cap, opts);
      pickyAlignSvgLine(svg, x2, y2 - cap, x2, y2 + cap, opts);
    } else {
      pickyAlignSvgLine(svg, x1 - cap, y1, x1 + cap, y1, opts);
      pickyAlignSvgLine(svg, x2 - cap, y2, x2 + cap, y2, opts);
    }
  }

  function pickyAlignHtmlLabel($ov, x, y, label){
    if(label == null || label === ''){ return; }
    var $lab = $('<div class="picky-align-gap-label"></div>').text(String(label));
    $lab.css({
      position: 'fixed',
      left: Math.round(x) + 'px',
      top: Math.round(y) + 'px',
      transform: 'translate(-50%, -50%)',
      background: '#a855f7',
      color: '#fff',
      fontSize: '11px',
      fontWeight: '700',
      lineHeight: '1',
      padding: '3px 6px',
      borderRadius: '4px',
      whiteSpace: 'nowrap',
      pointerEvents: 'none',
      zIndex: '2147483646',
      boxShadow: '0 2px 8px rgba(15,23,42,0.35)',
      fontFamily: 'ui-sans-serif, system-ui, sans-serif'
    });
    $('body').append($lab);
  }

  /** guides: [{x1,y1,x2,y2,label?,gap?}] in viewport/client pixels */
  function pickyShowAlignGuidesClient(guides){
    var $ov = pickyEnsureAlignGuidesOverlay();
    var svg = $ov.find('svg.picky-align-guides-svg')[0];
    if(!svg){ return; }
    var vw = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
    var vh = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
    svg.setAttribute('width', String(vw));
    svg.setAttribute('height', String(vh));
    svg.setAttribute('viewBox', '0 0 ' + vw + ' ' + vh);
    svg.setAttribute('preserveAspectRatio', 'none');
    while(svg.firstChild){ svg.removeChild(svg.firstChild); }
    $('.picky-align-gap-label').remove();
    if(!guides || !guides.length){
      $ov.removeClass('is-active').css('display', 'none');
      return;
    }
    var i, g, gapCount = 0;
    var gapStroke = { stroke: 'rgba(168, 85, 247, 0.95)', width: 2, solid: true };
    for(i = 0; i < guides.length; i++){
      g = guides[i];
      if(g.label != null && g.label !== ''){
        gapCount++;
        pickyAlignSvgLine(svg, g.x1, g.y1, g.x2, g.y2, gapStroke);
        pickyAlignSvgGapCaps(svg, g.x1, g.y1, g.x2, g.y2, gapStroke);
        pickyAlignHtmlLabel($ov, (g.x1 + g.x2) / 2, (g.y1 + g.y2) / 2, g.label);
      } else {
        pickyAlignSvgLine(svg, g.x1, g.y1, g.x2, g.y2, null);
      }
    }
    $ov.attr('data-picky-align', window.__PICKY_ALIGN_V || 'align13');
    $ov.addClass('is-active').css({ display: 'block', width: vw + 'px', height: vh + 'px' });
  }

  function pickyAlignCollectPeers($el, $canvas){
    var exclude = {};
    if($el && $el[0]){ exclude[$el[0]] = true; }
    try { pickyGetMultiSelected().each(function(){ exclude[this] = true; }); } catch(ex){}
    var peers = [];
    var $root = ($canvas && $canvas.length) ? $canvas : $('.picky-draggable').first();
    $root.children('.picky-element').each(function(){
      if(exclude[this]){ return; }
      peers.push({ $el: $(this), box: pickyGetElementAlignBox($(this)) });
    });
    if(!peers.length){
      $root.find('.picky-element').each(function(){
        if(exclude[this]){ return; }
        if($(this).closest('.picky-element-sidebar').length){ return; }
        peers.push({ $el: $(this), box: pickyGetElementAlignBox($(this)) });
      });
    }
    return peers;
  }

  function pickyAlignBestDelta(movingEdges, targetEdges, tol){
    var best = null, bestAbs = tol + 1, i, j, d, ad;
    for(i = 0; i < movingEdges.length; i++){
      for(j = 0; j < targetEdges.length; j++){
        d = targetEdges[j] - movingEdges[i];
        ad = Math.abs(d);
        if(ad <= tol && ad < bestAbs){ bestAbs = ad; best = d; }
      }
    }
    return best;
  }

  function pickyAlignClientBox($el, layoutBox, $canvas){
    var visual = pickyAlignVisualClientBox($el, null);
    if(visual){ return visual; }
    return pickyAlignLayoutToClientBox($canvas, layoutBox);
  }

  /**
   * Visible station body in viewport pixels (.picky-element-table-wrap / shape).
   * Outer .picky-element often collapses during jQuery UI drag (padding shell),
   * so guides must never use the wrapper's getBoundingClientRect.
   */
  function pickyAlignVisualClientBox($el, uiPos){
    if(!$el || !$el.length){ return null; }
    if(uiPos && typeof uiPos.left === 'number' && typeof uiPos.top === 'number'){
      $el.css({ left: uiPos.left + 'px', top: uiPos.top + 'px' });
    }
    var node = null;
    var $tw = $el.children('.picky-element-table-wrap').first();
    if(!$tw.length){ $tw = $el.find('.picky-element-table-wrap').first(); }
    if($tw.length){ node = $tw[0]; }
    if(!node){
      var $shape = $el.find('.picky-element-shape').first();
      if($shape.length){ node = $shape[0]; }
    }
    if(!node){ node = $el[0]; }
    if(!node || !node.getBoundingClientRect){ return null; }
    var r = node.getBoundingClientRect();
    if(!(r.width + r.height)){ return null; }
    return {
      l: r.left,
      r: r.right,
      t: r.top,
      b: r.bottom,
      cX: (r.left + r.right) / 2,
      cY: (r.top + r.bottom) / 2,
      w: r.width,
      h: r.height
    };
  }

  function pickyAlignLayoutToClientBox($canvas, layoutBox){
    if(!$canvas || !$canvas.length || !layoutBox){ return null; }
    var cr = $canvas[0].getBoundingClientRect();
    var W = $canvas[0].offsetWidth || 1;
    var H = $canvas[0].offsetHeight || 1;
    function mapX(x){ return cr.left + (x / W) * cr.width; }
    function mapY(y){ return cr.top + (y / H) * cr.height; }
    return {
      l: mapX(layoutBox.l), r: mapX(layoutBox.r),
      t: mapY(layoutBox.t), b: mapY(layoutBox.b),
      cX: mapX(layoutBox.cX), cY: mapY(layoutBox.cY)
    };
  }

  function pickyAlignPushEdgeGuides(guides, mClient, $canvas){
    if(!mClient || !$canvas || !$canvas.length){ return; }
    var canvasR = $canvas[0].getBoundingClientRect();
    var xL = Math.min(canvasR.left, mClient.l - 24);
    var xR = Math.max(canvasR.right, mClient.r + 24);
    var yT = Math.min(canvasR.top, mClient.t - 24);
    var yB = Math.max(canvasR.bottom, mClient.b + 24);
    guides.push({ x1: mClient.l, y1: yT, x2: mClient.l, y2: yB });
    guides.push({ x1: mClient.r, y1: yT, x2: mClient.r, y2: yB });
    guides.push({ x1: xL, y1: mClient.t, x2: xR, y2: mClient.t });
    guides.push({ x1: xL, y1: mClient.b, x2: xR, y2: mClient.b });
  }

  /**
   * Simple element box in layout coords (CSS left/top + outer size).
   * Avoids collision-pad skew that can zero-out gaps.
   */
  /**
   * Gap labels = clear space (px) between the moving station and the nearest
   * similar-sized neighbor on each side. Skips huge room/zone blocks.
   * Uses the visible table-wrap body, not the outer drag shell.
   */
  function pickyAlignPushGapGuides(guides, $movingEl, uiPos, targetsIgnored, $canvas, maxGap){
    if(!$movingEl || !$movingEl.length || !$canvas || !$canvas.length){ return; }
    maxGap = (typeof maxGap === 'number') ? maxGap : 160;
    var minGap = 3;

    var m = pickyAlignVisualClientBox($movingEl, uiPos);
    if(!m){ return; }
    var mEl = $movingEl[0];
    var minSideOverlap = Math.max(12, Math.min(m.w, m.h) * 0.35);

    var nearestL = null, nearestR = null, nearestT = null, nearestB = null;

    $canvas.children('.picky-element').each(function(){
      if(this === mEl){ return; }
      var $peer = $(this);
      var type = ($peer.attr('data-type') || '').toString();
      if(!type){
        if($peer.hasClass('type-wall')) type = 'type-wall';
        else if($peer.hasClass('type-text')) type = 'type-text';
        else if($peer.hasClass('type-shape')) type = 'type-shape';
        else if($peer.hasClass('type-icon')) type = 'type-icon';
      }
      if(pickyIsDecorativeAlignType(type)){ return; }

      var p = pickyAlignVisualClientBox($peer, null);
      if(!p || !(p.w + p.h)){ return; }
      /* Skip room-sized zones (both sides much larger than the station). */
      if(p.w > m.w * 2.2 && p.h > m.h * 2.2){ return; }
      if(m.w > p.w * 2.2 && m.h > p.h * 2.2){ return; }

      var yOverlap = Math.min(m.b, p.b) - Math.max(m.t, p.t);
      var xOverlap = Math.min(m.r, p.r) - Math.max(m.l, p.l);
      var gap;

      gap = m.l - p.r;
      if(gap >= minGap && gap <= maxGap && yOverlap >= minSideOverlap && (nearestL === null || gap < nearestL.gap)){
        nearestL = { gap: gap, p: p, y1: Math.max(m.t, p.t), y2: Math.min(m.b, p.b) };
      }
      gap = p.l - m.r;
      if(gap >= minGap && gap <= maxGap && yOverlap >= minSideOverlap && (nearestR === null || gap < nearestR.gap)){
        nearestR = { gap: gap, p: p, y1: Math.max(m.t, p.t), y2: Math.min(m.b, p.b) };
      }
      gap = m.t - p.b;
      if(gap >= minGap && gap <= maxGap && xOverlap >= minSideOverlap && (nearestT === null || gap < nearestT.gap)){
        nearestT = { gap: gap, p: p, x1: Math.max(m.l, p.l), x2: Math.min(m.r, p.r) };
      }
      gap = p.t - m.b;
      if(gap >= minGap && gap <= maxGap && xOverlap >= minSideOverlap && (nearestB === null || gap < nearestB.gap)){
        nearestB = { gap: gap, p: p, x1: Math.max(m.l, p.l), x2: Math.min(m.r, p.r) };
      }
    });

    function pxLabel(n){ return String(Math.round(n)) + ' px'; }

    if(nearestL){
      var yL = (nearestL.y1 + nearestL.y2) / 2;
      guides.push({ x1: nearestL.p.r, y1: yL, x2: m.l, y2: yL, label: pxLabel(nearestL.gap), gap: true });
    }
    if(nearestR){
      var yR = (nearestR.y1 + nearestR.y2) / 2;
      guides.push({ x1: m.r, y1: yR, x2: nearestR.p.l, y2: yR, label: pxLabel(nearestR.gap), gap: true });
    }
    if(nearestT){
      var xT = (nearestT.x1 + nearestT.x2) / 2;
      guides.push({ x1: xT, y1: nearestT.p.b, x2: xT, y2: m.t, label: pxLabel(nearestT.gap), gap: true });
    }
    if(nearestB){
      var xB = (nearestB.x1 + nearestB.x2) / 2;
      guides.push({ x1: xB, y1: m.b, x2: xB, y2: nearestB.p.t, label: pxLabel(nearestB.gap), gap: true });
    }
  }

  function pickyAlignIsOptionDown(event){
    if(event && event.altKey){ return true; }
    return !!window.__PICKY_ALIGN_OPTION;
  }

  function pickyAlignGetMeasureTarget(){
    var $el = $('.picky-draggable .picky-element[data-picky-align-drag="1"]').first();
    if(!$el.length){
      $el = $('.picky-draggable .picky-element.ui-draggable-dragging').first();
    }
    if(!$el.length){
      $el = $('.picky-draggable .picky-element.opened').first();
    }
    return $el;
  }

  function pickyAlignCollectTargets($el, $canvas){
    var peers = pickyAlignCollectPeers($el, $canvas);
    var targets = [];
    var i;
    for(i = 0; i < peers.length; i++){
      if(peers[i].box.bookable){ targets.push(peers[i]); }
    }
    if(!targets.length){ targets = peers; }
    return targets;
  }

  /** Option/Alt: edge guides + distance labels. */
  function pickyAlignShowOptionGaps(event){
    var $el = pickyAlignGetMeasureTarget();
    if(!$el.length){ return; }
    var $canvas = pickyAlignResolveCanvas($el, $el.closest('.picky-draggable'));
    if(!$canvas.length){ return; }
    var targets = pickyAlignCollectTargets($el, $canvas);
    var mClient = pickyAlignVisualClientBox($el, null);
    if(!mClient){
      mClient = pickyAlignLayoutToClientBox($canvas, pickyGetElementAlignBox($el));
    }
    var guides = [];
    pickyAlignPushEdgeGuides(guides, mClient, $canvas);
    pickyAlignPushGapGuides(guides, $el, null, targets, $canvas, 280);
    pickyShowAlignGuidesClient(guides);
  }

  function pickyApplyAlignSnap($el, ui, $canvas, event){
    try {
      if(!$el || !$el.length || !ui || !ui.position){ return; }
      $canvas = pickyAlignResolveCanvas($el, $canvas);
      if(!$canvas.length){ return; }

      var optionMode = pickyAlignIsOptionDown(event);
      var targets = pickyAlignCollectTargets($el, $canvas);
      var moving = pickyGetElementAlignBox($el, ui.position.left, ui.position.top);
      var guides = [];
      var mx = [moving.l, moving.cX, moving.r];
      var my = [moving.t, moving.cY, moving.b];
      var bestDX = null, bestDY = null, box, dx, dy, tx, ty, pClient, i;
      var mClient;

      for(i = 0; i < targets.length; i++){
        box = targets[i].box;
        dx = pickyAlignBestDelta(mx, [box.l, box.cX, box.r], PICKY_ALIGN_TOL);
        dy = pickyAlignBestDelta(my, [box.t, box.cY, box.b], PICKY_ALIGN_TOL);
        if(dx !== null && (bestDX === null || Math.abs(dx) < Math.abs(bestDX))){ bestDX = dx; }
        if(dy !== null && (bestDY === null || Math.abs(dy) < Math.abs(bestDY))){ bestDY = dy; }
      }
      if(bestDX !== null){ ui.position.left = Math.round(ui.position.left + bestDX); }
      if(bestDY !== null){ ui.position.top = Math.round(ui.position.top + bestDY); }

      moving = pickyGetElementAlignBox($el, ui.position.left, ui.position.top);
      mx = [moving.l, moving.cX, moving.r];
      my = [moving.t, moving.cY, moving.b];
      /* Paint from the visible body (table-wrap), not the outer drag shell. */
      mClient = pickyAlignVisualClientBox($el, ui.position);
      if(!mClient){ mClient = pickyAlignLayoutToClientBox($canvas, moving); }

      pickyAlignPushEdgeGuides(guides, mClient, $canvas);

      var seenV = {}, seenH = {}, j, k, ad, key;
      for(i = 0; i < targets.length; i++){
        box = targets[i].box;
        pClient = pickyAlignVisualClientBox(targets[i].$el, null) || pickyAlignClientBox(targets[i].$el, box, $canvas);
        if(!mClient || !pClient){ continue; }
        tx = [box.l, box.cX, box.r];
        ty = [box.t, box.cY, box.b];
        for(j = 0; j < 3; j++){
          for(k = 0; k < 3; k++){
            ad = Math.abs(mx[j] - tx[k]);
            if(ad <= PICKY_ALIGN_SHOW_TOL){
              key = 'v:' + Math.round(tx[k]);
              if(!seenV[key]){
                seenV[key] = true;
                var vx = (ad <= PICKY_ALIGN_TOL)
                  ? [mClient.l, mClient.cX, mClient.r][j]
                  : [pClient.l, pClient.cX, pClient.r][k];
                guides.push({
                  x1: vx,
                  y1: Math.min(mClient.t, pClient.t) - 16,
                  x2: vx,
                  y2: Math.max(mClient.b, pClient.b) + 16
                });
              }
            }
            ad = Math.abs(my[j] - ty[k]);
            if(ad <= PICKY_ALIGN_SHOW_TOL){
              key = 'h:' + Math.round(ty[k]);
              if(!seenH[key]){
                seenH[key] = true;
                var hy = (ad <= PICKY_ALIGN_TOL)
                  ? [mClient.t, mClient.cY, mClient.b][j]
                  : [pClient.t, pClient.cY, pClient.b][k];
                guides.push({
                  x1: Math.min(mClient.l, pClient.l) - 16,
                  y1: hy,
                  x2: Math.max(mClient.r, pClient.r) + 16,
                  y2: hy
                });
              }
            }
          }
        }
      }

      /* Distances: close neighbors while dragging; Option (⌥) reaches farther. */
      pickyAlignPushGapGuides(guides, $el, ui.position, targets, $canvas, optionMode ? 280 : 120);

      pickyShowAlignGuidesClient(guides);
      if($el && $el.length){ $el.data('picky-align-painted', true); }
    } catch (err) {
      if(window.console && console.warn){
        console.warn('[Picky align guides]', err);
      }
    }
  }

  if(window.console && console.warn){
    console.warn('[Picky] alignment guides ' + window.__PICKY_ALIGN_V + ' loaded');
  }

  function pickyAlignUiFromElement($el, ui){
    if(ui && ui.position && typeof ui.position.left === 'number' && typeof ui.position.top === 'number'){
      return ui;
    }
    var inst = $el.data('ui-draggable');
    var left = (inst && inst.position) ? inst.position.left : parseFloat($el.css('left'));
    var top = (inst && inst.position) ? inst.position.top : parseFloat($el.css('top'));
    if(isNaN(left)){ left = 0; }
    if(isNaN(top)){ top = 0; }
    return { position: { left: left, top: top } };
  }

  function pickyAlignMarkDragStart($el){
    if($el && $el.length){ $el.attr('data-picky-align-drag', '1'); }
    pickyEnsureAlignGuidesOverlay();
  }

  function pickyAlignMarkDragStop($el){
    if($el && $el.length){ $el.removeAttr('data-picky-align-drag'); }
    if(window.__PICKY_ALIGN_OPTION){
      pickyAlignShowOptionGaps(null);
    } else {
      pickyClearAlignGuides();
    }
  }

  function pickyAlignFromDraggingElement(event, ui){
    var $el = $('.picky-draggable .picky-element[data-picky-align-drag="1"]').first();
    if(!$el.length){
      $el = $('.picky-draggable .picky-element.ui-draggable-dragging').first();
    }
    if(!$el.length){ return; }
    var $canvas = $el.closest('.picky-draggable');
    if(!$canvas.length){ return; }
    var uiObj = pickyAlignUiFromElement($el, ui);
    pickyApplyAlignSnap($el, uiObj, $canvas, event);
  }

  /* Backup only: if drag callback somehow omitted apply, still paint while flagged. */
  $(document).on('mousemove.pickyAlignLive', function(event){
    if(event.altKey){ window.__PICKY_ALIGN_OPTION = true; }
    var $dragging = $('.picky-draggable .picky-element[data-picky-align-drag="1"]').first();
    if($dragging.length){
      if($dragging.data('picky-align-painted')){
        $dragging.data('picky-align-painted', false);
        return;
      }
      pickyAlignFromDraggingElement(event, null);
      return;
    }
    /* Static Option measure: selected element, not dragging */
    if(window.__PICKY_ALIGN_OPTION || event.altKey){
      pickyAlignShowOptionGaps(event);
    }
  });

  $(document).on('keydown.pickyAlignOption', function(e){
    if(e.key !== 'Alt' && e.code !== 'AltLeft' && e.code !== 'AltRight'){ return; }
    window.__PICKY_ALIGN_OPTION = true;
    /* Repaint immediately (don't wait for next mousemove). */
    var $dragging = $('.picky-draggable .picky-element[data-picky-align-drag="1"]').first();
    if($dragging.length){
      pickyAlignFromDraggingElement(e, null);
    } else {
      pickyAlignShowOptionGaps(e);
    }
  });
  $(document).on('keyup.pickyAlignOption', function(e){
    if(e.key !== 'Alt' && e.code !== 'AltLeft' && e.code !== 'AltRight'){ return; }
    window.__PICKY_ALIGN_OPTION = false;
    var $dragging = $('.picky-draggable .picky-element[data-picky-align-drag="1"]').first();
    if($dragging.length){
      pickyAlignFromDraggingElement(e, null);
    } else {
      pickyClearAlignGuides();
    }
  });
  $(window).on('blur.pickyAlignOption', function(){
    window.__PICKY_ALIGN_OPTION = false;
  });

  function elementIdRefresh(){
    if($('.picky-draggable .picky-element').length > 0){
      var id = 0;
      $('.picky-draggable .picky-element').each(function(e){
        if($(this).attr('data-type') === 'type-wall' || $(this).attr('data-type') === 'type-text' || $(this).attr('data-type') === 'type-shape' || $(this).attr('data-type') === 'type-icon'){ return; }
        var tem_id = parseInt($(this).attr('data-id'));
        if(tem_id > id){
          id = tem_id;
        }
      });
      element_id = id;
    }
  }

  var coordinates = new Array(); // coordinates of all elemnts on canvas
  var coordinates_with_curent = new Array();// coordinates of all elemnts on canvas and custom curent adding
  var elements = []; // Object of elements on canvas with out curent
  var revert = null; // revet whene dragged from start to canvas
  var pickyHistory = [];
  var isRestoring = false;

  function takeSnapshot(){
    if(isRestoring) return;
    var current = [];
    $('.picky-draggable .picky-element').each(function(){
      var $el = $(this);
      current.push({
        html: $el[0].outerHTML,
        id: $el.attr('data-id'),
        type: $el.attr('data-type')
      });
    });
    /* Several code paths can call takeSnapshot() twice for one user action (e.g.
       chained handlers). Identical consecutive entries made undo require two clicks
       to step back once — skip redundant pushes. */
    var serialized = JSON.stringify(current);
    if(pickyHistory.length && pickyHistory[pickyHistory.length - 1] === serialized){
      return;
    }
    pickyHistory.push(serialized);
    if(pickyHistory.length > 50) pickyHistory.shift();
  }

  $(document).on('picky:snapshot', function(){
    takeSnapshot();
  });

  $(document).on('click', '#picky-undo', function(e){
    e.preventDefault();
    if(pickyHistory.length < 2) return;
    /* Snapshots only serialize nodes still under .picky-draggable. When the inspector
       is open, .picky-element-edit-wrap is detached to #picky-builder-toolbar — closing
       first avoids orphan toolbar UI; ensureElementEditor() repairs missing markup. */
    try { pickyCloseElementEditor(); } catch(ex){}
    pickyHistory.pop(); // Remove current state
    var prevState = JSON.parse(pickyHistory[pickyHistory.length - 1]);
    isRestoring = true;

    $('.picky-draggable .picky-element').remove();
    var $canvas = $('.picky-draggable');

    prevState.forEach(function(item){
      $canvas.append($(item.html));
    });

    $('.picky-draggable .picky-element').each(function(){
      var $el = $(this);
      var ty = ($el.attr('data-type') || '').toString();
      if(ty !== 'type-wall' && ty !== 'type-text' && ty !== 'type-shape' && ty !== 'type-icon'){
        if($el.attr('data-show-id') === undefined || $el.attr('data-show-id') === ''){
          $el.attr('data-show-id', '1');
        }
      }
      ensureElementEditor($el);
      pickyNormalizeTableRotateDom($el);
      updateTextPreview($el);
      applyTransforms($el);
      applyElementOpacity($el);
      pickyApplyElementFillFromData($el);
      if(ty === 'type-wall'){
        pickyInitWallHandles($el);
      }
      $el.find('.picky-custom-resizer').remove();
      if($el.hasClass('picky-resize-enabled')){
        pickyEnableResizable($el);
      }
    });

    coordinates = refreshCoordinates();
    elementIdRefresh();

    try {
      if(typeof pickyBuilderInteractionsAllowed === 'function' && pickyBuilderInteractionsAllowed()){
        pickyReinitBuilderDragDrop();
      }
    } catch(err){}

    try { syncBuilderToolbarWidth(); } catch(ex){}
    try { pickyRenderServicesLibrary(); } catch(ex){}

    isRestoring = false;
  });
  var revert_exist = true; // rever whene dragged element on canvas
  var element_id = 0;

  function initDraggble(){
    coordinates = new Array();
    elements = [];

    $('.picky-draggable .picky-element').each(function(e){
      if($(this).attr('data-type') !== 'type-wall' && $(this).attr('data-type') !== 'type-text' && $(this).attr('data-type') !== 'type-shape' && $(this).attr('data-type') !== 'type-icon'){
        elements.push($(this));
      }
    });

    $(".picky-element-sidebar-wrap").draggable({
      helper: function() {
        var $wrap = $(this);
        if(pickySidebarWrapUsesFullElementDragHelper($wrap)){
          var $el = $wrap.find('.picky-element').first().clone();
          $el.removeClass('ui-draggable ui-draggable-handle');
          $el.css({
            transform: 'scale(1)',
            /* Raised in start(); must stay above .picky-draggable .picky-element (z-index ~1000) while dragging */
            'z-index': 100050
          });
          return $el;
        }
        var type = ($wrap.attr('data-type') || '').toString();
        var $icon = $wrap.find('.picky-sidebar-icon').first().clone();
        return $('<div class="picky-sidebar-drag-helper" />').attr('data-type', type).append($icon);
      },
      /* Keep helper under admin mouse but above canvas + inspector; body parent avoids overflow clipping */
      appendTo: 'body',
      zIndex: 100050,
      opacity: 0.9,
      cursor: "grabbing",
      scope: "this",
      cancel: ".picky-element-delete, .picky-element-duplicate, .picky-element-layer-front, .picky-element-layer-back, .picky-element-inline-close, .picky-element-edit-wrap, .picky-element-edit, .ui-resizable-handle, .picky-rotate-handle, .picky-3d-handle, .picky-3d-pad, .picky-wall-hdl, .picky-wall-ctrl-rotate",
      revertDuration: 200,
      drag: function( event, ui ) {
        $('.picky-draggable .picky-element').removeClass('dragover');
        revert = true;
      }, 
      revert : function(event) {
        if(revert === true){
          return false;
        } else if(revert === false) {
          $('.picky-draggable .picky-element').removeClass('dragover');
          return true;
        } else if(revert === null){
          $('.picky-draggable .picky-element').removeClass('dragover');
          return true;
        }
      },// revert
      start: function(e, ui){
        pickySetInlineCanvasToolsVisibleForElement(this, false);
        /* Sidebar → canvas: never use low z-index (was 1 for shapes, 150 for walls) or helper paints under
           .picky-draggable .picky-element (z-index 1000) and under .picky-draggable-wrap overflow. */
        ui.helper.css('z-index', '100050');
        $(this).closest('.picky-element-sidebar-wrap-all').find('.picky-element-sidebar-wrap').css('z-index', '1');
        $(this).closest('.picky-element-sidebar-wrap').css('z-index', '2');
      },
    }); 

    $('.picky-tables-wrap').removeClass('picky-loading');

  } // initDraggble



  function ititDroppable(){
    $(".picky-draggable").droppable({
      scope: "this",
      tolerance: "fit",
      drop: function(e, ui) {
          var $draggable;
          if($(ui.helper).hasClass('picky-sidebar-drag-helper')){
            $draggable = $(ui.draggable).find('.picky-element').first().clone();
          } else {
            $draggable = ui.helper.clone();
          }
          $draggable.removeClass('ui-draggable ui-draggable-handle ui-draggable-dragging');
          $draggable.css('opacity', 1);
          $draggable.css('position', 'absolute');
          $draggable.removeClass('ui-draggable-dragging');

          if(revert === null){ // if first element on canvas
            ui.helper.remove();
          }

          var droppedBox = getElementBoxSize($(ui.draggable));
          var $dropCanvas = $(this);
          coordinates_with_curent[0] = pickyPageRectToCanvasLocalAABB($dropCanvas, ui.offset.left, ui.offset.top, droppedBox.width, droppedBox.height);

          elements = [];
          $('.picky-draggable .picky-element').each(function(e){
            elements.push($(this));
          });

          var localDropTL = pickyPageXYToCanvasLocal($dropCanvas, ui.offset.left, ui.offset.top);
          var offsetLeft = localDropTL.x;
          var offsetTop = localDropTL.y;

          $draggable.draggable({
            helper: 'original',
            distance: 2,
            opacity: 0.9,
            cancel: ".picky-element-delete, .picky-element-duplicate, .picky-element-layer-front, .picky-element-layer-back, .picky-element-inline-close, .picky-element-edit-wrap, .picky-element-edit, .ui-resizable-handle, .picky-rotate-handle, .picky-3d-handle, .picky-3d-pad, .picky-wall-hdl, .picky-wall-ctrl-rotate",
            // containment: '.picky-draggable',
            reverDuration: 200,
            snapMode: "outside",
            snapTolerance: 5,
            create: function(event, ui){
              elements = [];
              $('.picky-draggable .picky-element').each(function(e){
                // Walls are decorative — don't block element placement
                if($(this).attr('data-type') !== 'type-wall' && $(this).attr('data-type') !== 'type-text' && $(this).attr('data-type') !== 'type-shape' && $(this).attr('data-type') !== 'type-icon'){
                  elements.push($(this));
                }
              });
              if(revert === true || revert === null){
                coordinates = new Array();
                var i = 0;
                if(elements.length > 0){
                  for (var k = 0; k <= elements.length - 1; k++) {
                    coordinates[k] = pickyBookableCollisionRect($(elements[k]));
                    i = k;
                  }
                  coordinates[parseInt(i)+1] = coordinates_with_curent[0];
                } else {
                  coordinates[0] = coordinates_with_curent[0];
                }

                var unique = makeid(10);
                $(this).find('.picky-element-overlay').prepend('<div class="picky-element-edit"></div>');
                if($(this).attr('data-type') !== 'type-wall' && $(this).attr('data-type') !== 'type-text' && $(this).attr('data-type') !== 'type-shape' && $(this).attr('data-type') !== 'type-icon'){
                  element_id++;
                  $(this).find('.picky-element-overlay').prepend('<div class="picky-element-id" data-default="'+element_id+'">'+element_id+'</div>');
                  $(this).attr('data-id', element_id);
                  $(this).find('.picky-element-id').text(element_id);
                  $(this).find('.picky-element-id').attr('data-default', element_id);
                  $(this).attr('data-show-id', '1');
                } else {
                  $(this).attr('data-id', 0);
                }
                $(this).attr('data-unique', unique);

                // Safety: make sure editor UI exists for newly created elements
                ensureElementEditor($(this));
              }
            },
            start: function(e, ui){
              pickySetInlineCanvasToolsVisibleForElement(this, false);
              pickyFixDraggableStartOffset($(this));
              var $dragEl = $(this);
              var isMultiDrag = $dragEl.hasClass(Picky_MS_CLASS) && pickyMultiSelectCount() > 1;
              if(isMultiDrag){
                pickyMultiDragCapture($dragEl);
                if(!$dragEl.hasClass('opened')){
                  pickyOpenElementEditor($dragEl, { keepMulti: true });
                }
              } else {
                if(!$dragEl.hasClass(Picky_MS_CLASS)){ pickyClearMultiSelect(); }
                if(!$dragEl.hasClass('opened')){
                  pickyOpenElementEditor($dragEl);
                }
              }
              pickySetInlineCanvasToolsVisibleForElement(this, false);
              var isText = ui.helper.hasClass('type-text');
              var isShape = ui.helper.hasClass('type-shape') || ui.helper.hasClass('type-icon');
              var isBack = ui.helper.hasClass('type-wall');
              ui.helper.css("z-index", isShape ? "1" : (isText ? "2000" : (isBack ? "150" : "1000")));
              coordinates = refreshCoordinates();
              pickyAlignMarkDragStart($(this));
            },
            drag: function( event, ui ) {
              var $el = $(this);
              if($el.hasClass('opened')){
                pickyPositionInlineCanvasTools($el);
                pickySetInlineCanvasToolsVisibleForElement($el[0], false);
              }
              var $canvas = (typeof pickyAlignResolveCanvas === 'function')
                ? pickyAlignResolveCanvas($el, $el.closest('.picky-draggable'))
                : $el.closest('.picky-draggable');
              if(!$canvas.length){ $canvas = $('.picky-draggable').first(); }
              pickyApplyCanvasDragFromMouse($el, $canvas, event.pageX, event.pageY, ui);
              var $shape = $el.find('.picky-element-shape');
              var $tw = $el.find('.picky-element-table-wrap');
              var pad = pickyElementLayoutPadLeft($el);
              
              // Canvas inner dimensions
              var canvasW = parseFloat($canvas.outerWidth());
              var canvasH = parseFloat($canvas.outerHeight());

              // High-precision visual boundary clamping
              var twW = parseFloat($tw.outerWidth());
              var twH = parseFloat($tw.outerHeight());
              
              var vBB = getVisualBoundingBox($el, twW, twH);
              var bbW = vBB.width;
              var bbH = vBB.height;
              
              // Visual offset from wrapper edge due to rotation centering
              var vOffsetL = (twW - bbW) / 2;
              var vOffsetT = (twH - bbH) / 2;
              
              // Precise clamp
              ui.position.left = Math.max(-vOffsetL - pad, Math.min(ui.position.left, canvasW - bbW - vOffsetL - pad));
              ui.position.top  = Math.max(-vOffsetT - pad, Math.min(ui.position.top,  canvasH - bbH - vOffsetT - pad));

              pickyApplyAlignSnap($el, ui, $canvas, event);
              pickyMultiDragFollow($el, ui);

              if($el.attr('data-type') === 'type-wall' || $el.attr('data-type') === 'type-shape' || $el.attr('data-type') === 'type-text' || $el.attr('data-type') === 'type-icon'){
                revert_exist = true;
                return;
              }
              revert_exist = true;
            },
            stop: function( event, ui ) {
              var $stopEl = $(this);
              pickyAlignMarkDragStop($stopEl);
              pickyMultiDragClear($stopEl);
              pickySetInlineCanvasToolsVisibleForElement(this, true);
              if($stopEl.hasClass(Picky_MS_CLASS) && pickyMultiSelectCount() > 1){
                if(!$stopEl.hasClass('opened')){
                  pickyOpenElementEditor($stopEl, { keepMulti: true });
                }
              } else {
                pickyOpenElementEditor($stopEl);
              }
              $('.picky-draggable .picky-element').removeClass('dragover');
              $stopEl.removeData('picky-drag-mousedown-page').removeData('picky-canvas-drag-grab');
              if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
            },
            revert : function(event) {
              if(revert_exist === true){
                return false;
              } else if(revert_exist === false) {
                $('.picky-draggable .picky-element').removeClass('dragover');
                return true;
              } else if(revert_exist === null){
                $('.picky-draggable .picky-element').removeClass('dragover');
                return true;
              }
            },
          });
          
          if(revert === true || revert === null){
            $draggable.css({
              "top": offsetTop,
              "left": offsetLeft
            }).appendTo('.picky-draggable');
          }

          if($draggable.attr('data-type') === 'type-wall'){
            var $wallEl = $draggable;
            setTimeout(function(){ pickyInitWallHandles($wallEl); }, 50);
          }
          // Force initialization of draggable on the newly dropped element
          if(!$draggable.hasClass('ui-draggable')){
              pickyInitDraggable($draggable);
          }
          /* After placing a preset from the sidebar: close the previous inspector
             (pickyOpenElementEditor does this) and open options for the new item.
             Re-find the node by data-unique on the canvas so the inspector always
             attaches to the preset that actually landed (not a stale jQuery ref). */
          if(revert === true || revert === null){
            $draggable.removeClass('opened');
            ensureElementEditor($draggable);
            var dropUnique = ($draggable.attr('data-unique') || '').toString().trim();
            if(!dropUnique){
              dropUnique = makeid(10);
              $draggable.attr('data-unique', dropUnique);
            }
            pickyNormalizeTableRotateDom($draggable);
            applyTransforms($draggable);
            applyElementOpacity($draggable);
            if($draggable.attr('data-type') === 'type-text'){
              updateTextPreview($draggable);
            }
            if($draggable.attr('data-type') === 'type-icon' || $draggable.hasClass('type-icon')){
              pickyApplyCanvasIcon($draggable);
            }
            var $openTarget = $('.picky-draggable .picky-element').filter(function(){
              return (($(this).attr('data-unique') || '')).toString() === dropUnique;
            }).first();
            if(!$openTarget.length){
              $openTarget = $draggable;
            }
            pickyOpenElementEditor($openTarget);
            if(typeof pickyRefreshCanvasLayerOrder === 'function'){
              pickyRefreshCanvasLayerOrder($openTarget.closest('.picky-draggable'));
            }
            takeSnapshot();
          } else {
            takeSnapshot();
          }

          elements = [];
          $('.picky-draggable .picky-element').each(function(e){
            elements.push($(this));
          });
        
      } // drop: function(){
    }); // $(".draggable").droppable({
  } // ititDroppable

  function pickyInitDraggable(element){
    element.draggable({
      helper: 'original',
      distance: 2,
      opacity: 0.9,
      cancel: ".picky-element-delete, .picky-element-duplicate, .picky-element-layer-front, .picky-element-layer-back, .picky-element-inline-close, .picky-element-edit-wrap, .picky-element-edit, .ui-resizable-handle, .picky-rotate-handle, .picky-3d-handle, .picky-3d-pad, .picky-wall-hdl, .picky-wall-ctrl-rotate, .picky-custom-resizer",
      revertDuration: 200,
      snapMode: "outside",
      snapTolerance: 5,
      start: function(e, ui){
        pickySetInlineCanvasToolsVisibleForElement(this, false);
        pickyFixDraggableStartOffset($(this));
        var $dragEl = $(this);
        var isMultiDrag = $dragEl.hasClass(Picky_MS_CLASS) && pickyMultiSelectCount() > 1;
        if(isMultiDrag){
          pickyMultiDragCapture($dragEl);
          if(!$dragEl.hasClass('opened')){
            pickyOpenElementEditor($dragEl, { keepMulti: true });
          }
        } else {
          if(!$dragEl.hasClass(Picky_MS_CLASS)){ pickyClearMultiSelect(); }
          if(!$dragEl.hasClass('opened')){
            pickyOpenElementEditor($dragEl);
          }
        }
        /* pickyOpenElementEditor mounts a fresh tool strip without the hidden
           class — hide again so the strip stays off until drag stop. */
        pickySetInlineCanvasToolsVisibleForElement(this, false);
        var isText = ui.helper.hasClass('type-text');
        var isShape = ui.helper.hasClass('type-shape') || ui.helper.hasClass('type-icon');
        var isBack = ui.helper.hasClass('type-wall');
        ui.helper.css("z-index", isShape ? "200" : (isText ? "3000" : (isBack ? "500" : "2200")));
        
        coordinates = refreshCoordinates();
        pickyAlignMarkDragStart($(this));
      },
      drag: function( event, ui ) {
        var $el = $(this);
        if($el.hasClass('opened')){
          pickyPositionInlineCanvasTools($el);
          pickySetInlineCanvasToolsVisibleForElement($el[0], false);
        }
        var $canvas = (typeof pickyAlignResolveCanvas === 'function')
          ? pickyAlignResolveCanvas($el, $el.closest('.picky-draggable'))
          : $el.closest('.picky-draggable');
        if(!$canvas.length){ $canvas = $('.picky-draggable').first(); }
        pickyApplyCanvasDragFromMouse($el, $canvas, event.pageX, event.pageY, ui);
        var $tw = $el.find('.picky-element-table-wrap');
        var pad = pickyElementLayoutPadLeft($el);
        
        var canvasW = parseFloat($canvas.outerWidth());
        var canvasH = parseFloat($canvas.outerHeight());
        var twW = parseFloat($tw.outerWidth());
        var twH = parseFloat($tw.outerHeight());
        
        var vBB = getVisualBoundingBox($el, twW, twH);
        var bbW = vBB.width;
        var bbH = vBB.height;
        
        var vOffsetL = (twW - bbW) / 2;
        var vOffsetT = (twH - bbH) / 2;
        
        ui.position.left = Math.max(-vOffsetL - pad, Math.min(ui.position.left, canvasW - bbW - vOffsetL - pad));
        ui.position.top  = Math.max(-vOffsetT - pad, Math.min(ui.position.top,  canvasH - bbH - vOffsetT - pad));

        pickyApplyAlignSnap($el, ui, $canvas, event);
        pickyMultiDragFollow($el, ui);

        if($el.attr('data-type') === 'type-wall' || $el.attr('data-type') === 'type-shape' || $el.attr('data-type') === 'type-text' || $el.attr('data-type') === 'type-icon'){
          revert_exist = true;
          return;
        }
        revert_exist = true;
      },
      stop: function( event, ui ) {
        var $stopEl = $(this);
        pickyAlignMarkDragStop($stopEl);
        pickyMultiDragClear($stopEl);
        pickySetInlineCanvasToolsVisibleForElement(this, true);
        if($stopEl.hasClass(Picky_MS_CLASS) && pickyMultiSelectCount() > 1){
          if(!$stopEl.hasClass('opened')){
            pickyOpenElementEditor($stopEl, { keepMulti: true });
          }
        } else {
          pickyOpenElementEditor($stopEl);
        }
        /* Drop temporary drag z-index; re-apply band order so layer tools stay correct. */
        var $stopCanvas = $stopEl.closest('.picky-draggable');
        if(typeof pickyRefreshCanvasLayerOrder === 'function' && $stopCanvas.length){
          pickyRefreshCanvasLayerOrder($stopCanvas);
        } else {
          $stopEl.css('z-index', '');
        }
        $('.picky-draggable .picky-element').removeClass('dragover');
        $stopEl.removeData('picky-drag-mousedown-page').removeData('picky-canvas-drag-grab');
        if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
      },
      revert : function(event) {
        if(revert_exist === true){
          return false;
        } else {
          $('.picky-draggable .picky-element').removeClass('dragover');
          return true;
        }
      }
    });
  }

  function initDraggbleLoad(){
    $('.picky-draggable .picky-element').draggable({
      helper: 'original',
      distance: 2,
      opacity: 0.9,
      cancel: ".picky-element-delete, .picky-element-duplicate, .picky-element-layer-front, .picky-element-layer-back, .picky-element-inline-close, .picky-element-edit-wrap, .picky-element-edit, .ui-resizable-handle, .picky-rotate-handle, .picky-3d-handle, .picky-3d-pad, .picky-wall-hdl, .picky-wall-ctrl-rotate",
      // containment: '.picky-draggable', // Removed for rotation-aware visual containment
      reverDuration: 200,
      snapMode: "inside",
      snapTolerance: 5,
      create: function(event, ui){
        if(revert === true || revert === null){
          coordinates = new Array();
          var i = 0;
          for (var k = 0; k <= elements.length - 1; k++) {
            coordinates[k] = pickyBookableCollisionRect($(elements[k]));
            i = k;
          }
        }
      },
      start: function(e, ui){
        pickySetInlineCanvasToolsVisibleForElement(this, false);
        pickyFixDraggableStartOffset($(this));
        var $dragEl = $(this);
        var isMultiDrag = $dragEl.hasClass(Picky_MS_CLASS) && pickyMultiSelectCount() > 1;
        if(isMultiDrag){
          pickyMultiDragCapture($dragEl);
          if(!$dragEl.hasClass('opened')){
            pickyOpenElementEditor($dragEl, { keepMulti: true });
          }
        } else {
          if(!$dragEl.hasClass(Picky_MS_CLASS)){ pickyClearMultiSelect(); }
          if(!$dragEl.hasClass('opened')){
            pickyOpenElementEditor($dragEl);
          }
        }
        pickySetInlineCanvasToolsVisibleForElement(this, false);
        // Keep stacked hierarchy while dragging (shape stays below tables).
        if($(this).hasClass('type-shape') || $(this).hasClass('type-icon')){
          ui.helper.css('z-index', '200');
        } else if($(this).hasClass('type-text')){
          ui.helper.css('z-index', '3000');
        } else if($(this).hasClass('type-wall')){
          ui.helper.css('z-index', '500');
        } else {
          ui.helper.css('z-index', '2200');
        }
        coordinates = refreshCoordinates();
        pickyAlignMarkDragStart($(this));
      },
      drag: function( event, ui ) {
        // --- Rotation-aware visual containment (same logic as drop handler) ---
        var $el = $(this);
        if($el.hasClass('opened')){
          pickyPositionInlineCanvasTools($el);
          pickySetInlineCanvasToolsVisibleForElement($el[0], false);
        }
        var $canvas = (typeof pickyAlignResolveCanvas === 'function')
          ? pickyAlignResolveCanvas($el, $el.closest('.picky-draggable'))
          : $el.closest('.picky-draggable');
        if(!$canvas.length){ $canvas = $('.picky-draggable').first(); }
        pickyApplyCanvasDragFromMouse($el, $canvas, event.pageX, event.pageY, ui);
        var $tw = $el.find('.picky-element-table-wrap');
        var pad = pickyElementLayoutPadLeft($el);
        var canvasW = parseFloat($canvas.outerWidth());
        var canvasH = parseFloat($canvas.outerHeight());

        // High-precision visual boundary clamping
        var twW = parseFloat($tw.outerWidth());
        var twH = parseFloat($tw.outerHeight());

        var vBB = getVisualBoundingBox($el, twW, twH);
        var bbW = vBB.width;
        var bbH = vBB.height;

        var vOffsetL = (twW - bbW) / 2;
        var vOffsetT = (twH - bbH) / 2;

        ui.position.left = Math.max(-vOffsetL - pad, Math.min(ui.position.left, canvasW - bbW - vOffsetL - pad));
        ui.position.top  = Math.max(-vOffsetT - pad, Math.min(ui.position.top,  canvasH - bbH - vOffsetT - pad));

        pickyApplyAlignSnap($el, ui, $canvas, event);
        pickyMultiDragFollow($el, ui);

        // Bookable tables: no overlap revert — allow stacking.
        if($el.attr('data-type') === 'type-wall' || $el.attr('data-type') === 'type-shape' || $el.attr('data-type') === 'type-text' || $el.attr('data-type') === 'type-icon'){
          revert_exist = true;
          return;
        }
        revert_exist = true;
      },
      stop: function( event, ui ) {
        var $stopEl = $(this);
        pickyAlignMarkDragStop($stopEl);
        pickyMultiDragClear($stopEl);
        pickySetInlineCanvasToolsVisibleForElement(this, true);
        if($stopEl.hasClass(Picky_MS_CLASS) && pickyMultiSelectCount() > 1){
          if(!$stopEl.hasClass('opened')){
            pickyOpenElementEditor($stopEl, { keepMulti: true });
          }
        } else {
          pickyOpenElementEditor($stopEl);
        }
        /* Drop temporary drag z-index; re-apply band order so layer tools stay correct. */
        var $stopCanvas = $stopEl.closest('.picky-draggable');
        if(typeof pickyRefreshCanvasLayerOrder === 'function' && $stopCanvas.length){
          pickyRefreshCanvasLayerOrder($stopCanvas);
        } else {
          $stopEl.css('z-index', '');
        }
        $('.picky-draggable .picky-element').removeClass('dragover');
        $stopEl.removeData('picky-drag-mousedown-page').removeData('picky-canvas-drag-grab');
        coordinates = refreshCoordinates();
        /* Reposition rotate/3D handles (left↔right) + canvas tool strip now
           that the element sits in a new spot. */
        updateInteractiveHandlesPosition($stopEl);
        pickyPositionInlineCanvasTools($stopEl);
        takeSnapshot();
      },
      revert : function(event) {
        if(revert_exist === true){
          return false;
        } else if(revert_exist === false) {
          $('.picky-draggable .picky-element').removeClass('dragover');
          return true;
        } else if(revert_exist === null){
          $('.picky-draggable .picky-element').removeClass('dragover');
          return true;
        }
      },
    });
    // Wire wall handles for elements already on canvas
    $('.picky-draggable .picky-element.type-wall').each(function(){
      pickyInitWallHandles($(this));
    });
  }

  /** Layout builder lives in tab 9 (often hidden on load). Draggable/droppable init on display:none breaks helpers & drops. */
  function pickyBuilderInteractionsAllowed(){
    if(!$('.picky-builder-main-container').length){ return false; }
    var $tab = $('#picky-settings-tab-9');
    if(!$tab.length){ return true; }
    return $tab.is(':visible');
  }

  function pickyDestroyBuilderDragDrop(){
    $('.picky-element-sidebar-wrap').each(function(){
      var $w = $(this);
      if($w.data('ui-draggable')){
        try { $w.draggable('destroy'); } catch(e){}
      }
    });
    $('.picky-draggable .picky-element').each(function(){
      var $el = $(this);
      if($el.data('ui-draggable')){
        try { $el.draggable('destroy'); } catch(e){}
      }
    });
    $('.picky-draggable').each(function(){
      var $c = $(this);
      if($c.data('ui-droppable')){
        try { $c.droppable('destroy'); } catch(e){}
      }
    });
  }

  function pickyReinitBuilderDragDrop(){
    if(!pickyBuilderInteractionsAllowed()){ return; }
    pickyDestroyBuilderDragDrop();
    initDraggble();
    ititDroppable();
    initDraggbleLoad();
    /* Tab 9 can be display:none on first ready; repair editor rows once the builder is interactable. */
    $('.picky-draggable .picky-element').each(function(){
      ensureElementEditor($(this));
    });
    /* Normalize leftover drag z-index into explicit per-band stacking. */
    if(typeof pickyRefreshCanvasLayerOrder === 'function'){
      $('.picky-draggable').each(function(){
        pickyRefreshCanvasLayerOrder($(this));
      });
    }
  }

  function drawSeats(q,element, rotate = 0){
    var current_seats = element.find('.picky-element-seats-wrap .picky-element-seat').length;
    var max = element.attr('data-max-seats');
    var start = element.attr('data-seats');
    var total = 0;
    if(current_seats != q){
      total = q - current_seats;
      if(total != 0){
        if(total > 0){  
          $('<div class="picky-element-seat" style="display: none;"></div>').appendTo(element.find('.picky-element-seats-wrap')).hide(function(){

            addCSStoDraw(q,element, rotate);

          }).fadeIn(200);
        } else if(total < 0){
          element.find('.picky-element-seats-wrap .picky-element-seat:last-child').fadeOut(0,function(){
            $.when($(this).remove()).then(function(){

              addCSStoDraw(q,element, rotate);

            });
          });
        }
      }
    }
  }

  function addCSStoDraw(q,element, rotate = 1){
    var way_string = "";
    var second_lap = 0;
    var iter = 1 ;
    var second_lap_start = 0;
    var third_lap_start = 0;
    var el_table = element.find('.picky-element-table-wrap');
    var table_w = el_table.outerWidth(true);
    var table_h = el_table.outerHeight(true);
    var seat_size = 30;
    if($('.picky-tables-wrap').hasClass('picky-tables-size-small')){
      seat_size = 26;
    } else if($('.picky-tables-wrap').hasClass('picky-tables-size-medium')){
      seat_size = 30;
    } else if($('.picky-tables-wrap').hasClass('picky-tables-size-big')){
      seat_size = 34;
    } 

    var closest_margin = 4;
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

  /**
   * Legacy layouts (and undo snapshots) can omit the bookable Square / Universal corner-radius row.
   * Prefer cloning from the sidebar; otherwise build the same markup so old items upgrade in-place.
   */
  function ensureSquareRadiusEditField($editor, $host){
    if(!$editor || !$editor.length){ return; }
    var $existing = $editor.find('.picky-element-edit-square-radius-input').first();
    if($existing.length){
      $existing.closest('.picky-element-edit-field').addClass('picky-type-1-only-field picky-field-square-radius');
      return;
    }
    var $srTpl = $('.picky-element-sidebar .picky-element-edit-field:has(.picky-element-edit-square-radius-input)').first();
    if(!$srTpl.length){
      $srTpl = $('.picky-element-sidebar .picky-field-square-radius').first();
    }
    if((!$srTpl.length) && $host && $host.length){
      $('.picky-draggable .picky-element').each(function(){
        if($host[0] === this){ return; }
        var $cand = $(this).find('.picky-field-square-radius').first();
        if($cand.length){
          $srTpl = $cand;
          return false;
        }
      });
    }
    var $row;
    if($srTpl.length){
      $row = $srTpl.clone(true, true);
    } else {
      var title = (typeof window.pickyLayoutData !== 'undefined' && window.pickyLayoutData.square_radius_field_title)
        ? String(window.pickyLayoutData.square_radius_field_title)
        : 'Corner radius (px)';
      $row = $(
        '<div class="picky-element-edit-field picky-type-1-only-field picky-field-square-radius">' +
          '<div class="picky-element-edit-field-title"></div>' +
          '<input type="number" min="0" max="120" step="1" value="" class="picky-element-edit-square-radius-input picky-input" placeholder="Default" title="Leave empty for default rounding (8px). Enter 1–120 to override." />' +
        '</div>'
      );
      $row.find('.picky-element-edit-field-title').text(title);
    }
    var $afterShowId = $editor.find('.picky-field-show-id').first();
    if(!$afterShowId.length){
      $afterShowId = $editor.find('.picky-element-edit-field:has(.picky-element-edit-show-id-input)').first();
    }
    if($afterShowId.length){
      $row.insertAfter($afterShowId);
    } else {
      $editor.find('.picky-element-edit-fields').first().append($row);
    }
  }

  // Ensure settings/edit markup exists on an element (some clones can miss it)
  function ensureElementEditor(element){
    if(element.find('.picky-element-edit').length === 0){
      element.find('.picky-element-overlay').prepend('<div class="picky-element-edit"></div>');
    }
    if(element.find('.picky-element-edit-wrap').length === 0){
      // Clone template from sidebar (it always has edit UI) or from any existing element
      var $tpl = $('.picky-element-sidebar .picky-element-edit-wrap').first();
      if($tpl.length === 0){
        $tpl = $('.picky-element .picky-element-edit-wrap').first();
      }
      if($tpl.length){
        element.find('.picky-element-table-wrap').append($tpl.clone(true, true).hide());
      }
    }
    var $editor = element.find('.picky-element-edit-wrap').first();
    var $tw = element.find('.picky-element-table-wrap').first();
    if($editor.length){
      // Normalize legacy/sidebar editor markup to match type-based visibility selectors.
      $editor.find('.picky-element-edit-field:has(.picky-element-edit-max-persons-input)').addClass('picky-field-max-seats');
      /* Legacy sidebar templates used to render an ID control; strip it —
         ID is now shown only in the heading chip, not as an editable field. */
      $editor.find('.picky-element-edit-field:has(.picky-element-edit-id-input)').remove();
      $editor.find('.picky-element-edit-field:has(.picky-element-edit-label-input)').addClass('picky-field-label');
      $editor.find('.picky-element-edit-field:has(.picky-element-edit-label-position-input)').addClass('picky-field-label-position');
      $editor.find('.picky-element-edit-field:has(.picky-element-edit-label-color-input)').addClass('picky-field-label-color');
      $editor.find('.picky-element-edit-field:has(.picky-element-edit-opacity-input)').addClass('picky-field-opacity');
      $editor.find('.picky-element-edit-field:has(.picky-field-service-ids-chips)').addClass('picky-field-service-ids');
      ensureSquareRadiusEditField($editor, element);

      // Walls need thickness + corner radius even when dropped from sidebar templates.
      if(element.attr('data-type') === 'type-wall'){
        if($editor.find('.picky-element-edit-thickness-input').length === 0){
          $editor.find('.picky-element-edit-fields').append(
            '<div class="picky-element-edit-field picky-wall-only-field">' +
              '<div class="picky-element-edit-field-title">Thickness (px)</div>' +
              '<input type="number" min="4" max="500" step="1" value="20" class="picky-element-edit-thickness-input picky-input">' +
            '</div>'
          );
        }
        if($editor.find('.picky-element-edit-wall-radius-input').length === 0){
          $editor.find('.picky-element-edit-fields').append(
            '<div class="picky-element-edit-field picky-wall-only-field picky-field-wall-radius">' +
              '<div class="picky-element-edit-field-title">Corner radius (px)</div>' +
              '<input type="number" min="0" max="120" step="1" value="" class="picky-element-edit-wall-radius-input picky-input" placeholder="Default (2)" title="Leave empty for default rounding (2px). Enter 0 for sharp ends, or 1–120 to override.">' +
            '</div>'
          );
        }
      }

      // Keep the 3D pad on the canvas (not inside movable toolbar editor).
      var $table = element.find('.picky-element-table').first();
      var $embeddedPad = $editor.find('.picky-3d-pad').first();
      if($embeddedPad.length){
        $embeddedPad.detach();
        if($table.length && !$table.find('.picky-3d-pad').length){
          $table.append($embeddedPad);
        }
      }
      if($table.length && !$table.find('.picky-3d-pad').length){
        $table.append('<div class="picky-3d-pad" title="Drag to tilt (3D)"><div class="picky-3d-handle"></div></div>');
      }
    }
    // For wall elements: hide non-applicable fields (ID, Name, Max persons)
    if(element.attr('data-type') === 'type-wall'){
      element.find('.picky-element-edit-field:has(.picky-element-edit-max-persons-input)').hide();
      element.find('.picky-element-edit-field:has(.picky-element-edit-id-input)').hide();
      element.find('.picky-element-edit-field:has(.picky-element-edit-label-input)').hide();
      element.find('.picky-element-edit-field:has(.picky-element-edit-label-position-input)').hide();
      element.find('.picky-element-edit-field:has(.picky-element-edit-label-color-input)').hide();
      element.find('.picky-field-show-id').hide();
      element.find('.picky-wall-only-field').show();
    }
    return $editor;
  }
   function getEditorTarget(child){
     // Priority 1: Target bound to detached canvas tool strip
     var $toolStrip = $(child).closest('.picky-element-edit-tools');
     var stripTarget = $toolStrip.data('picky-target');
     if(stripTarget && $(stripTarget).length) return $(stripTarget);

     // Priority 1.25: Resolve via data-unique when jQuery data was cleared
     // (e.g. outside-click closed the editor between mousedown and click).
     if($toolStrip.length){
       var uid = ($toolStrip.attr('data-picky-target-unique') || '').toString().trim();
       if(uid){
         var $byUid = $('.picky-draggable .picky-element').filter(function(){
           return (($(this).attr('data-unique') || '').toString().trim() === uid);
         }).first();
         if($byUid.length) return $byUid;
       }
     }

     // Priority 1.5: The element explicitly marked as 'opened' on the canvas
     var $opened = $('.picky-draggable .picky-element.opened');
     if($opened.length === 1) return $opened;
     if($opened.length > 1){
       // Multi-select: prefer the strip target unique if present, else primary opened
       return $opened.first();
     }

     // Priority 2: Data attribute on the editor wrap
     var $editor = $(child).closest('.picky-element-edit-wrap');
     var target = $editor.data('picky-target');
     if(target && $(target).length) return $(target);

     // Fallback: Closest element (legacy)
     return $(child).closest('.picky-element');
   }

  function normalizeHexColor(raw, fallback){
    var val = (raw || '').toString().trim();
    // Accept #rrggbb or #rrggbbaa (alpha) — hex8 is a valid CSS color.
    if(/^#[0-9a-f]{6}([0-9a-f]{2})?$/i.test(val)){ return val.toLowerCase(); }
    if(/^#[0-9a-f]{3}$/i.test(val)){
      return ('#' + val.charAt(1) + val.charAt(1) + val.charAt(2) + val.charAt(2) + val.charAt(3) + val.charAt(3)).toLowerCase();
    }
    return fallback || '#ffffff';
  }

  /** Return the 0..1 alpha encoded in a hex string. Defaults to 1 when the
   *  value is a 6-char hex (fully opaque). */
  function pickyExtractAlphaFromHex(hex){
    var v = (hex || '').toString().trim();
    var m = v.match(/^#([0-9a-f]{6})([0-9a-f]{2})$/i);
    if(m){ return parseInt(m[2], 16) / 255; }
    return 1.0;
  }

  /** Return the 6-char hex part (drops alpha when present). */
  function pickyStripAlpha(hex){
    var v = (hex || '').toString().trim();
    var m = v.match(/^#([0-9a-f]{6})/i);
    return m ? ('#' + m[1].toLowerCase()) : '#ffffff';
  }

  /** Combine a 6-char hex with a 0..1 alpha. Returns 6-char hex when fully
   *  opaque (keeps storage tidy) and 8-char hex otherwise. */
  function pickyHexWithAlpha(hex6, alpha){
    var base = pickyStripAlpha(hex6);
    var a = parseFloat(alpha);
    if(isNaN(a)){ a = 1; }
    a = Math.max(0, Math.min(1, a));
    if(a >= 0.999){ return base; }
    var n = Math.round(a * 255);
    var ah = n.toString(16);
    if(ah.length === 1){ ah = '0' + ah; }
    return base + ah.toLowerCase();
  }

  function pickyWpFieldPickerFallback($inp){
    if(!$inp || !$inp.length){ return '#ffffff'; }
    if($inp.hasClass('picky-element-edit-bg-color-input')){ return '#ffffff'; }
    return '#333333';
  }

  /** Replace WP default “Select color” label with the current hex (matches other toolbar fields). */
  function pickySyncWpColorResultLabel($inp){
    if(!$inp || !$inp.length){ return; }
    var $c = $inp.closest('.wp-picker-container');
    if(!$c.length){ return; }
    var fb = pickyWpFieldPickerFallback($inp);
    var hex = normalizeHexColor($inp.val(), fb);
    var $text = $c.find('.wp-color-result-text');
    if($text.length){
      $text.text(hex.toUpperCase());
    }
    pickySyncSwatchVariable($inp, hex);
  }

  /** Paint the swatch via a CSS variable on the picker container, so the
   *  button shell can stay white (legible hex label) while the small
   *  swatch tile picks up the chosen color. Pairs with the .wp-color-result
   *  styling in picky-admin.css. */
  function pickySyncSwatchVariable($inp, hex){
    if(!$inp || !$inp.length){ return; }
    var $c = $inp.closest('.wp-picker-container');
    if(!$c.length){ return; }
    var color = (hex || '').toString().trim();
    if(!color){
      var fb = pickyWpFieldPickerFallback($inp);
      color = normalizeHexColor($inp.val(), fb);
    }
    $c[0].style.setProperty('--picky-swatch', color);
  }

  /** Bookable items: style .picky-element-label from data-label-font-size/color/font-weight. */
  function applyElementLabelStyles(element){
    if(!element || !element.length){ return; }
    var t = (element.attr('data-type') || '').toString();
    if(t === 'type-wall' || t === 'type-text' || t === 'type-shape' || t === 'type-icon'){ return; }
    var $l = element.find('.picky-element-label').first();
    if(!$l.length){ return; }
    var fs = parseFloat(element.attr('data-label-font-size'), 10);
    if(isNaN(fs) || fs < 8){ fs = 12; }
    if(fs > 200){ fs = 200; }
    var color = normalizeHexColor(element.attr('data-label-color'), '#333333');
    var fw = (element.attr('data-label-font-weight') || 'normal').toString();
    var allowed = { normal:1, bold:1, '300':1, '400':1, '500':1, '600':1, '700':1, '800':1, '900':1 };
    if(!allowed[fw]){ fw = 'normal'; }
    $l.css({
      fontSize: fs + 'px',
      color: color,
      fontWeight: fw
    });
  }

  function pickyRgbStringToHex(rgb, fallback){
    var val = (rgb || '').toString().trim();
    var m = val.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);
    if(!m){ return fallback || '#ffffff'; }
    var r = Math.max(0, Math.min(255, parseInt(m[1], 10) || 0));
    var g = Math.max(0, Math.min(255, parseInt(m[2], 10) || 0));
    var b = Math.max(0, Math.min(255, parseInt(m[3], 10) || 0));
    var toHex = function(n){
      var s = n.toString(16);
      return s.length === 1 ? ('0' + s) : s;
    };
    return '#' + toHex(r) + toHex(g) + toHex(b);
  }

  function pickyGetElementCurrentBgColor($element){
    if(!$element || !$element.length){ return '#ffffff'; }
    var dataColor = normalizeHexColor($element.attr('data-color') || '', '');
    if(dataColor){ return dataColor; }
    var $shape = $element.find('.picky-element-shape').first();
    var cssColor = ($shape.css('background-color') || '').toString().trim();
    if(cssColor && cssColor !== 'transparent' && cssColor !== 'rgba(0, 0, 0, 0)'){
      return pickyRgbStringToHex(cssColor, '#ffffff');
    }
    return '#ffffff';
  }

  function pickyGetElementCurrentTextColor($element){
    if(!$element || !$element.length){ return '#333333'; }
    var fromData = normalizeHexColor($element.attr('data-text-color') || '', '');
    if(fromData){ return fromData; }
    var $txt = $element.find('.picky-element-text-content').first();
    var cssColor = ($txt.css('color') || '').toString().trim();
    if(cssColor && cssColor !== 'transparent' && cssColor !== 'rgba(0, 0, 0, 0)'){
      return pickyRgbStringToHex(cssColor, '#333333');
    }
    return '#333333';
  }

  function pickySetEditorIdField($editor, rawId){
    if(!$editor || !$editor.length){ return; }
    var val = String(rawId == null ? '' : rawId);
    var $idField = $editor.find('.picky-element-edit-id-input');
    if(!$idField.length){ return; }
    if($idField.is('input,textarea,select')){
      $idField.val(val);
    } else {
      $idField.text(val);
    }
  }

  function pickyDestroyWpColorPickerInput($inp){
    if(!$inp || !$inp.length || typeof $.fn.wpColorPicker !== 'function'){ return; }
    try {
      $inp.wpColorPicker('destroy');
    } catch (err) { /* not initialised */ }
    $inp.removeData('pickyWpPickerReady pickyToolPickerInit');
  }

  function pickyTeardownEditorColorUi($editor){
    if(!$editor || !$editor.length){ return; }
    $editor.find('.picky-element-color-input, .picky-element-edit-bg-color-input, .picky-element-edit-text-color-input, .picky-element-edit-shape-border-color-input, .picky-element-edit-label-color-input').each(function(){
      pickyDestroyWpColorPickerInput($(this));
    });
    $editor.find('.picky-element-color-picker-wrap').each(function(){
      pickyCloseToolStripColorPickerPopup($(this));
    });
  }

  /** Persist the chosen color (with current alpha) into the right element
   *  data attribute and re-render any dependent UI. Shared between the
   *  iris change callback and the alpha slider so the two stay in lock-step. */
  function pickyApplyEditorColorChange($inp, hex){
    var isTextField   = $inp.hasClass('picky-element-edit-text-color-input');
    var isShapeBorder = $inp.hasClass('picky-element-edit-shape-border-color-input');
    var isLabelColor  = $inp.hasClass('picky-element-edit-label-color-input');
    var $el = getEditorTarget($inp[0]);
    if(!$el.length){ return; }
    if(isTextField){
      $el.attr('data-text-color', hex);
      $el.attr('data-text-color-mode', 'custom');
      var $modeSelect = $inp.closest('.picky-element-edit-wrap').find('.picky-element-edit-text-color-mode-input').first();
      if($modeSelect.length){ $modeSelect.val('custom'); }
      $inp.prop('disabled', false);
    } else if(isShapeBorder){
      $el.attr('data-shape-border-color', hex);
      applyTransforms($el);
    } else if(isLabelColor){
      $el.attr('data-label-color', hex);
      applyElementLabelStyles($el);
    } else {
      $el.attr('data-color', hex);
    }
    updateTextPreview($el);
    pickySyncWpColorResultLabel($inp);
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  }

  /** Inject a custom "Opacity" slider at the bottom of a wpColorPicker
   *  dropdown. The slider drives the alpha channel of the field's color
   *  (stored as hex8 — e.g. #ff000080 for 50% red). */
  function pickyInjectAlphaSlider($inp){
    if(!$inp || !$inp.length){ return; }
    var $container = $inp.closest('.wp-picker-container');
    if(!$container.length){ return; }
    var $holder = $container.find('.wp-picker-holder').first();
    if(!$holder.length){ return; }
    if($holder.find('.picky-alpha-slider').length){ return; }
    var defHex = pickyWpFieldPickerFallback($inp);
    var initAlpha = pickyExtractAlphaFromHex(normalizeHexColor($inp.val(), defHex));
    var initPct = Math.round(initAlpha * 100);
    var $slider = $(
      '<div class="picky-alpha-slider" role="group" aria-label="Opacity">' +
        '<div class="picky-alpha-slider-row">' +
          '<span class="picky-alpha-slider-label">Opacity</span>' +
          '<input type="range" min="0" max="100" step="1" class="picky-alpha-slider-range">' +
          '<span class="picky-alpha-slider-value">' + initPct + '%</span>' +
        '</div>' +
      '</div>'
    );
    $holder.append($slider);
    var $range = $slider.find('.picky-alpha-slider-range');
    var $value = $slider.find('.picky-alpha-slider-value');
    $range.val(initPct);
    $range.on('input change', function(){
      var pct = parseInt($range.val(), 10);
      if(isNaN(pct)){ pct = 100; }
      $value.text(pct + '%');
      var base = pickyStripAlpha(normalizeHexColor($inp.val(), defHex));
      var combined = pickyHexWithAlpha(base, pct / 100);
      /* Iris writes 6-char hex back to the input on every iris move, so we
         also force the alpha-aware value back in so re-opens read alpha. */
      $inp.val(combined);
      pickyApplyEditorColorChange($inp, combined);
    });
  }

  function pickySetupEditorFieldColorPickers($editor){
    if(typeof $.fn.wpColorPicker !== 'function' || !$editor.length){ return; }
    $editor.find('.picky-element-edit-bg-color-input, .picky-element-edit-text-color-input, .picky-element-edit-shape-border-color-input, .picky-element-edit-label-color-input').each(function(){
      var $inp = $(this);
      if(($inp.attr('type') || '').toLowerCase() !== 'text'){ return; }
      if(!$inp.closest('.picky-element-edit-field').is(':visible')){ return; }
      if($inp.data('pickyWpPickerReady')){ return; }
      $inp.wpColorPicker({
        hide: true,
        change: function(e, ui){
          if(!ui || !ui.color){ return; }
          var defHex = pickyWpFieldPickerFallback($inp);
          // Iris emits a 6-char hex; combine it with the alpha that the
          // user has dialed in on the popup's Opacity slider so picks
          // through the wheel don't drop transparency.
          var picked = normalizeHexColor(ui.color.toString(), defHex);
          var $container = $inp.closest('.wp-picker-container');
          var alphaPct = 100;
          var $range = $container.find('.picky-alpha-slider-range');
          if($range.length){
            var rv = parseInt($range.val(), 10);
            if(!isNaN(rv)){ alphaPct = rv; }
          } else {
            alphaPct = Math.round(pickyExtractAlphaFromHex(normalizeHexColor($inp.val(), defHex)) * 100);
          }
          var combined = pickyHexWithAlpha(picked, alphaPct / 100);
          // Persist hex8 to the input so the next popup-open knows the alpha.
          if(combined.length === 9){ $inp.val(combined); }
          pickyApplyEditorColorChange($inp, combined);
        }
      });
      $inp.on('wpcolorpickeropen', function(){
        var $c = $inp.closest('.wp-picker-container');
        if(!$c.closest('#picky-element-inspector').length){ return; }
        window.requestAnimationFrame(function(){
          $c.find('.wp-picker-holder').first().css({
            left: '',
            top: '',
            transform: '',
            visibility: '',
            maxHeight: '',
            overflow: ''
          });
          pickyInjectAlphaSlider($inp);
        });
      });
      window.requestAnimationFrame(function(){
        pickySyncWpColorResultLabel($inp);
      });
      $inp.data('pickyWpPickerReady', true);
    });
  }

  function pickyEnsureToolStripColorPicker($editor, $input){
    if(typeof $.fn.wpColorPicker !== 'function' || !$input.length){ return; }
    var $wrap = $input.closest('.picky-element-color-picker-wrap');
    if($wrap.length){
      var $staleContainers = $wrap.find('.wp-picker-container');
      if($staleContainers.length > 1){
        $staleContainers.slice(1).remove();
      }
      /* Never remove the sole .wp-picker-container here: the text input lives inside it.
         Orphan UI after a bad destroy is cleared in pickyCloseToolStripColorPickerPopup. */
    }
    if(!$input.data('pickyToolPickerInit')){
      $input.wpColorPicker({
        hide: true,
        change: function(e, ui){
          if(!ui || !ui.color){ return; }
          var hex = normalizeHexColor(ui.color.toString(), '#ffffff');
          var $target = $editor.data('picky-target');
          var element = ($target && $target.length) ? $target : $('.picky-draggable .picky-element.opened').first();
          if(!element.length){ return; }
          if(element.hasClass('type-text')){
            element.attr('data-text-color', hex);
            element.attr('data-text-color-mode', 'custom');
            var $modeField = $editor.find('.picky-element-edit-text-color-mode-input').first();
            if($modeField.length){ $modeField.val('custom'); }
            var $textColorField = $editor.find('.picky-element-edit-text-color-input').first();
            if($textColorField.length){ $textColorField.prop('disabled', false); }
            updateTextPreview(element);
          } else if(element.hasClass('type-icon')){
            element.attr('data-color', hex);
            element.find('.picky-element-shape').css({
              color: hex,
              'background-color': 'transparent',
              background: 'transparent'
            });
          } else {
            element.attr('data-color', hex);
            var $bi = $editor.find('.picky-element-edit-bg-color-input');
            if($bi.length){
              $bi.val(hex);
              try {
                if($bi.data('pickyWpPickerReady')){ $bi.wpColorPicker('color', hex); }
              } catch (x) { /* noop */ }
            }
            // Custom PNG/icon: keep plate transparent so color does not show through.
            if(!(element.attr('data-custom-url') || '').toString().trim()){
              element.find('.picky-element-shape').css('background-color', hex);
            }
            updateTextPreview(element);
          }
          if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
        }
      });
      $input.data('pickyToolPickerInit', true);
    }
  }

  function pickyResetToolStripColorPickerWrap($wrap){
    if(!$wrap || !$wrap.length){ return; }
    $wrap.removeData('pickyAnchor');
    $wrap.removeClass('picky-color-picker-portal');
    $wrap.css({
      position: '',
      left: '',
      top: '',
      right: '',
      width: '',
      maxWidth: '',
      maxHeight: '',
      overflowY: '',
      visibility: '',
      zIndex: ''
    });
  }

  /** Move the strip picker onto body so inspector transform/overflow cannot clip it. */
  function pickyPortalToolStripColorPickerWrap($pickerWrap){
    if(!$pickerWrap || !$pickerWrap.length){ return; }
    if($pickerWrap.parent().is('body')){ return; }
    $pickerWrap.data('pickyHome', $pickerWrap.parent());
    $pickerWrap.addClass('picky-color-picker-portal');
    $pickerWrap.appendTo(document.body);
  }

  function pickyUnportalToolStripColorPickerWrap($pickerWrap){
    if(!$pickerWrap || !$pickerWrap.length){ return; }
    var $home = $pickerWrap.data('pickyHome');
    $pickerWrap.removeClass('picky-color-picker-portal');
    if($home && $home.length && $home.closest('body').length){
      $pickerWrap.appendTo($home);
    }
    $pickerWrap.removeData('pickyHome');
  }

  /**
   * Tear down the tool-strip color popup. WP wraps the input inside .wp-picker-container;
   * removing that node deletes the input — so we reset to a bare input (same as PHP markup).
   */
  function pickyCloseToolStripColorPickerPopup($pickerWrap){
    if(!$pickerWrap || !$pickerWrap.length){ return; }
    var $inp = $pickerWrap.find('.picky-element-color-input').first();
    var savedVal = $inp.length ? String($inp.val() || '').trim() : '';
    if($inp.length){
      try {
        if($inp.data('pickyToolPickerInit')){ $inp.wpColorPicker('close'); }
      } catch (eClose) { /* noop */ }
      pickyDestroyWpColorPickerInput($inp);
    }
    $pickerWrap.empty();
    $('<input type="text" class="picky-element-color-input" />').val(savedVal).appendTo($pickerWrap);
    pickyResetToolStripColorPickerWrap($pickerWrap);
    pickyUnportalToolStripColorPickerWrap($pickerWrap);
    $pickerWrap.hide();
  }

  /**
   * Keep the integrated strip color popup fully inside the viewport.
   * Anchored to the color tool button; flips above when needed.
   */
  function pickyPositionToolStripColorPickerWrap($wrap, $anchorBtn){
    if(!$wrap || !$wrap.length || !$wrap.is(':visible')){ return; }
    var $anchor = ($anchorBtn && $anchorBtn.length) ? $anchorBtn : $wrap.data('pickyAnchor');
    if(!$anchor || !$anchor.length){ return; }
    var anchorEl = $anchor[0];
    if(!anchorEl || !anchorEl.getBoundingClientRect){ return; }

    var rect = anchorEl.getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var gap = 6;
    var margin = 8;

    $wrap.css({
      position: 'fixed',
      right: 'auto',
      width: 'min(320px, calc(100vw - ' + (margin * 2) + 'px))',
      maxWidth: 'min(320px, calc(100vw - ' + (margin * 2) + 'px))',
      visibility: 'hidden'
    });

    var w = Math.ceil($wrap.outerWidth());
    var h = Math.ceil($wrap.outerHeight());
    if(!w || w < 120){ w = 280; }
    if(!h || h < 80){ h = 220; }

    /* Center under the color tool (narrow anchor). */
    var left = rect.left + rect.width / 2 - w / 2;

    /* Keep inside the floating inspector when anchor lives there (stops drifting past the panel). */
    var $insp = $('#picky-element-inspector');
    if($insp.length && $insp.is(':visible') && $.contains($insp[0], anchorEl)){
      var ir = $insp[0].getBoundingClientRect();
      var innerPad = 6;
      var maxL = ir.right - margin - w;
      var minL = ir.left + innerPad;
      if(left > maxL){ left = maxL; }
      if(left < minL){ left = minL; }
    }

    if(left + w > vw - margin){ left = vw - w - margin; }
    if(left < margin){ left = margin; }

    var spaceBelow = vh - rect.bottom - margin;
    var spaceAbove = rect.top - margin;
    var top;
    if(spaceBelow >= h + gap || spaceBelow >= spaceAbove){
      top = rect.bottom + gap;
    } else {
      top = rect.top - h - gap;
    }

    top = Math.max(margin, Math.min(top, vh - h - margin));
    left = Math.max(margin, Math.min(left, vw - w - margin));

    var maxH = Math.max(160, vh - margin * 2);
    if(h > maxH){
      $wrap.css({ maxHeight: maxH + 'px', overflowY: 'auto' });
      h = Math.ceil($wrap.outerHeight());
      top = Math.max(margin, Math.min(top, vh - h - margin));
    } else {
      $wrap.css({ maxHeight: '', overflowY: '' });
    }

    $wrap.css({
      left: Math.round(left) + 'px',
      top: Math.round(top) + 'px',
      visibility: ''
    });
  }

  /**
   * Position an Iris color picker dropdown as a viewport-fixed overlay.
   * Keeps the holder out of any ancestor's flow (no section expansion)
   * and auto-flips above the swatch when there is no room below.
   */
  function pickyPositionColorPickerHolder($container){
    if(!$container || !$container.length) return;
    if(!$container.hasClass('wp-picker-active')) return;
    var $holder = $container.find('> .wp-picker-holder, .wp-picker-holder').first();
    var $btn = $container.find('.wp-color-result').first();
    if(!$holder.length || !$btn.length) return;
    if($holder.is(':hidden')) return;
    /* Floating inspector: sticky + backdrop-filter makes `position:fixed` on the holder attach to
       the wrong box; viewport top/left from this function then land at the panel top. CSS anchors
       the holder under the swatch instead (#picky-element-inspector .wp-picker-holder). */
    if($container.closest('#picky-element-inspector').length){
      $holder.css({ left: '', top: '', visibility: '', maxHeight: '', overflow: '' });
      return;
    }
    if($holder.closest('.picky-element-color-picker-wrap').length){
      // Strip picker stays as one integrated popup block; no detached holder positioning.
      $holder.css({ left: '', top: '', maxHeight: '', overflow: '', visibility: '' });
      return;
    }

    $holder.css({ left: '0px', top: '0px', visibility: 'hidden', maxHeight: '' });
    var hw = $holder.outerWidth();
    var hh = $holder.outerHeight();
    if(!hh || hh < 120){ hh = 340; }
    if(!hw || hw < 120){ hw = 255; }
    var rect = $btn[0].getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var gap = 6;
    var margin = 8;

    var left = rect.left;
    if(left + hw > vw - margin){ left = vw - hw - margin; }
    if(left < margin){ left = margin; }

    var spaceBelow = vh - rect.bottom - margin;
    var spaceAbove = rect.top - margin;
    var top;
    if(spaceBelow >= hh + gap || spaceBelow >= spaceAbove){
      top = rect.bottom + gap;
      if(top + hh > vh - margin){ top = Math.max(margin, vh - hh - margin); }
    } else {
      top = rect.top - hh - gap;
      if(top < margin){ top = margin; }
    }

    var maxHeight = Math.max(200, vh - (margin * 2));
    $holder.css({
      left: Math.round(left) + 'px',
      top: Math.round(top) + 'px',
      maxHeight: maxHeight + 'px',
      overflow: 'auto',
      visibility: ''
    });
  }

  function pickyRepositionAllOpenColorPickers(){
    $('.picky-element-color-picker-wrap:visible, .picky-builder-toolbar .picky-element-color-picker-wrap:visible').each(function(){
      var $w = $(this);
      var $a = $w.data('pickyAnchor');
      pickyPositionToolStripColorPickerWrap($w, $a);
    });
    $('.picky-builder-toolbar .wp-picker-container.wp-picker-active, body > .picky-element-color-picker-wrap .wp-picker-container.wp-picker-active').each(function(){
      pickyPositionColorPickerHolder($(this));
    });
    pickyScheduleInspectorSpotlightUpdate();
  }

  $(document).on('click', '.picky-builder-toolbar .wp-color-result', function(){
    var $container = $(this).closest('.wp-picker-container');
    var $stripWrap = $(this).closest('.picky-element-color-picker-wrap');
    window.requestAnimationFrame(function(){
      if($stripWrap.length){
        var $a = $stripWrap.data('pickyAnchor');
        pickyPositionToolStripColorPickerWrap($stripWrap, $a);
        window.requestAnimationFrame(function(){
          pickyPositionToolStripColorPickerWrap($stripWrap, $a);
        });
      } else {
        pickyPositionColorPickerHolder($container);
        window.requestAnimationFrame(function(){
          pickyPositionColorPickerHolder($container);
        });
      }
    });
  });

  $(window).on('scroll resize', function(){
    if($('.picky-builder-toolbar .wp-picker-container.wp-picker-active').length ||
      $('.picky-element-color-picker-wrap:visible').length){
      pickyRepositionAllOpenColorPickers();
    }
    pickyScheduleInspectorSpotlightUpdate();
    pickyScheduleInspectorDockSync();
  });

  $(document).on('scroll', '.picky-builder-toolbar, .picky-element-inspector, .picky-element-inspector-chrome', function(){
    pickyRepositionAllOpenColorPickers();
  });

  $(document).on('scroll', '#wpwrap, #wpbody-content', function(){
    pickyScheduleLeftColDockSync();
    pickyScheduleInspectorSpotlightUpdate();
    pickyScheduleInspectorDockSync();
  });

  /* Nested scroll parents don't bubble — capture so tall-canvas docks stay put. */
  if(!window._pickyInspectorScrollCapture){
    window._pickyInspectorScrollCapture = true;
    document.addEventListener('scroll', function(){
      pickyScheduleLeftColDockSync();
      if($('#picky-element-inspector').hasClass('is-empty')){ return; }
      pickyScheduleInspectorDockSync();
    }, true);
  }

  function updateInteractiveHandlesPosition(element){
    var $el = $(element).closest('.picky-element');
    var $canvas = $el.closest('.picky-draggable');
    if(!$canvas.length || !$el.length){ return; }

    /* Rotate + 3D controls default to left-center. Compute free space using
       visual bounding rects (rotation-aware), then flip to right only when
       left side cannot fit the control hit area. */
    $el.removeClass('picky-pad-top picky-pad-bottom picky-pad-left picky-pad-right');

    var er = $el[0].getBoundingClientRect();
    var cr = $canvas[0].getBoundingClientRect();
    var zoom = (typeof pickyZoom !== 'undefined' && pickyZoom) ? pickyZoom : 1;
    var need = 60 * zoom; // control footprint + breathing room

    var spaceLeft = er.left - cr.left;
    var spaceRight = cr.right - er.right;

    if(spaceLeft < need && spaceRight >= need){
      $el.addClass('picky-ctrl-right');
    } else {
      $el.removeClass('picky-ctrl-right');
    }
  }

  function positionEditor(){
    // Element options live in the floating #picky-element-inspector panel.
  }

  $(document).on('click', '.picky-element-inspector-close', function(e){
    e.preventDefault();
    e.stopPropagation();
    pickyCloseElementEditor();
  });

  $(document).on('click', '.picky-element-inline-close', function(e){
    e.preventDefault();
    e.stopPropagation();
    pickyCloseElementEditor();
  });

  /* Canva-like behavior: hide inline tool strip while transforming; restore on release. */
  $(document).on('mousedown', '.picky-rotate-handle, .picky-3d-handle, .picky-custom-resizer, .ui-resizable-handle, .picky-wall-hdl, .picky-wall-ctrl-rotate', function(){
    pickySetInlineCanvasToolsVisibleForElement(this, false);
  });
  $(document).on('mouseup.pickyInlineTools touchend.pickyInlineTools', function(){
    /* Do not un-hide strips while a canvas element is mid-drag; otherwise the
       global show runs before draggable stop and tools look "stuck" in place. */
    if($('.picky-draggable .picky-element.ui-draggable-dragging').length){
      return;
    }
    pickySetInlineCanvasToolsVisible(true);
  });

  /* Keep options open while interacting with canvas/admin chrome;
     close only on explicit close action or selecting a different element. */

  /** Canvas hit-test: all layout elements stacked at viewport point (top → bottom). */
  function pickyCanvasElementsAtPoint(x, y){
    var allAtPoint = document.elementsFromPoint(x, y);
    var pickyElements = [];
    for(var i = 0; i < allAtPoint.length; i++){
      var $el = $(allAtPoint[i]).closest('.picky-draggable .picky-element');
      if($el.length && pickyElements.indexOf($el[0]) === -1 && !$el.hasClass('picky-element-sidebar-wrap')){
        pickyElements.push($el[0]);
      }
    }
    return pickyElements;
  }

  function pickyElementPlateRect($el){
    if(!$el || !$el.length){ return null; }
    var $plate = $el.find('> .picky-element-table-wrap > .picky-element-table > .picky-element-shape').first();
    if(!$plate.length){
      $plate = $el.find('> .picky-element-table-wrap > .picky-element-shape').first();
    }
    if(!$plate.length){
      $plate = $el.find('.picky-element-shape').first();
    }
    if($plate.length){
      return $plate[0].getBoundingClientRect();
    }
    var $wrap = $el.find('> .picky-element-table-wrap').first();
    if($wrap.length){
      return $wrap[0].getBoundingClientRect();
    }
    return $el[0].getBoundingClientRect();
  }

  function pickyPointInRect(x, y, rect){
    if(!rect){ return false; }
    return x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;
  }

  /** Prefer the smallest plate at the click point when layers overlap (chair vs table). */
  function pickyPickCanvasElementAtPoint(x, y, opts){
    opts = opts || {};
    var stack = pickyCanvasElementsAtPoint(x, y);
    if(!stack.length){ return $(); }
    if(opts.cycleFrom && opts.cycleFrom.length && stack.length > 1){
      var idx = stack.indexOf(opts.cycleFrom[0]);
      if(idx === -1){ return $(stack[0]); }
      return $(stack[(idx + 1) % stack.length]);
    }
    if(stack.length === 1){ return $(stack[0]); }
    var candidates = [];
    for(var j = 0; j < stack.length; j++){
      var rect = pickyElementPlateRect($(stack[j]));
      if(pickyPointInRect(x, y, rect)){
        candidates.push({ el: stack[j], area: rect.width * rect.height });
      }
    }
    if(candidates.length){
      candidates.sort(function(a, b){ return a.area - b.area; });
      return $(candidates[0].el);
    }
    return $(stack[0]);
  }

  // Edit element (on icon click)
  $(document).on('click', '.picky-draggable .picky-element .picky-element-edit', function(e){
    e.stopPropagation();
    pickyOpenElementEditor($(this).closest('.picky-element'));
  });

  // Select element (on element click). Shift+click toggles multi-select.
  $(document).on('click', '.picky-draggable .picky-element', function(e){
    if($(e.target).closest('.picky-element-edit-wrap, .ui-resizable-handle, .picky-rotate-handle, .picky-3d-handle, .picky-element-inline-close, .picky-custom-resizer, .picky-wall-hdl, .picky-wall-ctrl-rotate').length > 0) return;
    var $pick = pickyPickCanvasElementAtPoint(e.clientX, e.clientY, e.altKey ? { cycleFrom: $(this) } : {});
    if(!$pick.length){ return; }
    if(e.shiftKey){
      e.preventDefault();
      e.stopPropagation();
      pickyMultiSelectToggle($pick);
      return;
    }
    pickyClearMultiSelect();
    pickyOpenElementEditor($pick);
  });

  // Selection cycling: double-click (or Alt+click) walks the stack when layers overlap.
  $(document).on('dblclick', '.picky-draggable .picky-element', function(e){
    e.preventDefault();
    e.stopPropagation();
    if(e.shiftKey){ return; }

    var stack = pickyCanvasElementsAtPoint(e.clientX, e.clientY);
    if(stack.length <= 1){ return; }

    var $pick = pickyPickCanvasElementAtPoint(e.clientX, e.clientY, { cycleFrom: $(this) });
    if(!$pick.length){ return; }
    pickyClearMultiSelect();
    pickyOpenElementEditor($pick);
  });

  function pickyFormatEditingHeading(type){
    var data = typeof window.pickyLayoutData !== 'undefined' ? window.pickyLayoutData : {};
    var labels = data.elementTypeLabels || {};
    var tmpl = (data.editingElementTemplate || 'Editing: %s').toString();
    var t = (type || '').toString();
    var label = labels[t];
    var usedFallback = false;
    if(!label){
      usedFallback = true;
      if(t === 'type-table' || t.indexOf('table') !== -1){
        label = labels['type-table'] || 'Table';
      } else if(t){
        label = t.replace(/^type-/, '').replace(/[-_]/g, ' ');
        label = label.charAt(0).toUpperCase() + label.slice(1);
      } else {
        label = 'Element';
      }
    }
    return tmpl.replace('%s', label);
  }

  function pickyRestoreEditorToolsBesideFields(editor){
    if(!editor || !editor.length){ return; }
    var $tools = editor.find('.picky-element-edit-tools').first();
    if(!$tools.length){
      $tools = $('.picky-draggable-wrap-all > .picky-element-edit-tools.picky-inline-tools-on-canvas').first();
    }
    var $fields = editor.find('.picky-element-edit-fields').first();
    if(!$tools.length || !$fields.length){ return; }
    $tools.removeClass('picky-inline-tools-on-canvas');
    $tools.removeData('picky-target');
    $tools.removeAttr('data-picky-tools-type');
    $tools.removeAttr('data-picky-target-unique');
    $tools.detach().insertAfter($fields);
  }

  /** Canvas-level tool strip / close button bound to a specific .picky-element. */
  function pickyGetHostedInlineToolStrip($host, $el){
    if(!$host || !$host.length || !$el || !$el.length){ return $(); }
    var uid = ($el.attr('data-unique') || '').toString().trim();
    var $candidates = $host.children('.picky-element-edit-tools.picky-inline-tools-on-canvas');
    if(uid){
      var $byUid = $candidates.filter(function(){
        return (($(this).attr('data-picky-target-unique') || '').toString().trim() === uid);
      });
      if($byUid.length){ return $byUid.first(); }
    }
    return $candidates.filter(function(){
      var t = $(this).data('picky-target');
      return t && $(t).is($el);
    }).first();
  }

  function pickyGetHostedInlineCloseBtn($host, $el){
    if(!$host || !$host.length || !$el || !$el.length){ return $(); }
    var uid = ($el.attr('data-unique') || '').toString().trim();
    var $candidates = $host.children('.picky-element-inline-close.picky-inline-close-on-canvas');
    if(uid){
      var $byUid = $candidates.filter(function(){
        return (($(this).attr('data-picky-target-unique') || '').toString().trim() === uid);
      });
      if($byUid.length){ return $byUid.first(); }
    }
    return $candidates.filter(function(){
      var t = $(this).data('picky-target');
      return t && $(t).is($el);
    }).first();
  }

  function pickyRenderEditorToolsOnCanvas(editor, element){
    if(!editor || !editor.length || !element || !element.length){ return; }
    var $tools = editor.find('.picky-element-edit-tools').first();
    if(!$tools.length){ return; }
    var $canvas = element.closest('.picky-draggable');
    var $host = element.closest('.picky-draggable-wrap-all');
    if(!$canvas.length || !$host.length){ return; }
    $tools.addClass('picky-inline-tools-on-canvas');
    $tools.data('picky-target', element);
    $tools.attr('data-picky-tools-type', (element.attr('data-type') || '').toString());
    $tools.attr('data-picky-target-unique', (element.attr('data-unique') || '').toString().trim());
    $tools.detach().appendTo($host);
    pickyPositionInlineCanvasTools(element);
  }

  function pickyPositionInlineCanvasTools(element){
    var $el = $(element);
    if(!$el.length){ return; }
    var $canvas = $el.closest('.picky-draggable');
    var $host = $el.closest('.picky-draggable-wrap-all');
    if(!$canvas.length || !$host.length){ return; }
    var $tools = pickyGetHostedInlineToolStrip($host, $el);
    if(!$tools.length){ return; }

    /* Prefer the shape box (table-wrap) — outer .picky-element padding made the
       strip sit on top of the visible circle/rect. */
    var $tw = $el.find('.picky-element-table-wrap').first();
    var boxEl = ($tw.length ? $tw[0] : $el[0]);
    var er = boxEl.getBoundingClientRect();
    var hr = $host[0].getBoundingClientRect();

    var top = er.top;
    var bottom = er.bottom;
    var left = er.left;
    var right = er.right;

    $el.find('.picky-element-label:visible').each(function(){
      var r = this.getBoundingClientRect();
      if(!r || (r.width < 1 && r.height < 1)){ return; }
      top = Math.min(top, r.top);
      bottom = Math.max(bottom, r.bottom);
      left = Math.min(left, r.left);
      right = Math.max(right, r.right);
    });

    /* Include resize / rotate / 3D chrome so the strip clears those hit targets. */
    $el.find('.picky-custom-resizer:visible, > .ui-resizable-handle:visible, .picky-rotate-handle:visible, .picky-3d-pad:visible').each(function(){
      var r = this.getBoundingClientRect();
      if(!r || (r.width < 1 && r.height < 1)){ return; }
      top = Math.min(top, r.top);
      bottom = Math.max(bottom, r.bottom);
      left = Math.min(left, r.left);
      right = Math.max(right, r.right);
    });

    var cx = ((left + right) / 2) - hr.left;
    var toolsH = $tools.outerHeight() || 40;
    var gap = 16;
    /* Resize / rotate / 3D need free access to bottom & side handles — park the
       strip above the item for those modes. */
    var placeAbove = $el.hasClass('picky-resize-enabled')
      || $el.hasClass('picky-rotate-mode')
      || $el.hasClass('picky-3d-mode');

    var yBelow = (bottom - hr.top) + gap;
    var yAbove = (top - hr.top) - toolsH - gap;
    var hostH = hr.height || ($host.outerHeight() || 0);
    var y;

    if(placeAbove){
      y = yAbove;
      if(y < 4){ y = yBelow; }
    } else {
      y = yBelow;
      if((y + toolsH) > (hostH - 4) && yAbove >= 4){ y = yAbove; }
    }

    $tools.toggleClass('picky-inline-tools--above', placeAbove && y === yAbove);
    $tools.css({
      left: Math.round(cx) + 'px',
      top: Math.round(y) + 'px',
      transform: 'translateX(-50%)'
    });

    /* Close button lives at canvas level so it never rotates with the element.
       Anchor it just above the right edge of the tool strip (Canva-style). */
    var $close = pickyGetHostedInlineCloseBtn($host, $el);
    if($close.length){
      var toolsW = $tools.outerWidth() || 0;
      var closeW = $close.outerWidth() || 22;
      var closeH = $close.outerHeight() || 22;
      var rightEdge = cx + (toolsW / 2);
      var closeLeft = Math.round(rightEdge - closeW + 2);
      var closeTop = placeAbove && y === yAbove
        ? Math.round(y - closeH - 6)
        : Math.round(y - closeH - 6);
      $close.css({ left: closeLeft + 'px', top: closeTop + 'px' });
    }
  }

  function pickyEnsureInlineCloseOnCanvas(element){
    if(!element || !element.length){ return; }
    var $host = element.closest('.picky-draggable-wrap-all');
    if(!$host.length){ return; }

    /* Purge any legacy inline close button that lived inside the element so it
       doesn't get caught by transforms (rotation / 3D tilt). */
    element.children('.picky-element-inline-close').remove();

    var $btn = $host.children('.picky-element-inline-close.picky-inline-close-on-canvas').first();
    if(!$btn.length){
      $btn = $('<button type="button" class="picky-element-inline-close picky-inline-close-on-canvas" aria-label="Close options" title="Close options">&times;</button>');
      $host.append($btn);
    }
    $btn.data('picky-target', element);
    $btn.attr('data-picky-target-unique', (element.attr('data-unique') || '').toString().trim());
    $btn.show();
  }

  function pickySetInlineCanvasToolsVisible(visible){
    var $tools = $('.picky-draggable-wrap-all > .picky-element-edit-tools.picky-inline-tools-on-canvas');
    if($tools.length){
      $tools.toggleClass('picky-inline-tools-hidden', !visible);
    }
    $('.picky-draggable-wrap-all > .picky-element-inline-close.picky-inline-close-on-canvas').toggleClass('picky-inline-tools-hidden', !visible);
  }

  function pickySetInlineCanvasToolsVisibleForElement(element, visible){
    /* Tools are detached from the element and appended to .picky-draggable while
       editing, so they are siblings (not descendants) of the element. Match
       via the `picky-target` binding stored on the tool strip. */
    var $el = $(element).closest('.picky-element');
    if(!$el.length){
      pickySetInlineCanvasToolsVisible(visible);
      return;
    }
    var $host = $el.closest('.picky-draggable-wrap-all');
    var $tools = pickyGetHostedInlineToolStrip($host, $el);
    if(!$tools.length){
      $tools = $host.children('.picky-element-edit-tools.picky-inline-tools-on-canvas');
    }
    $tools.toggleClass('picky-inline-tools-hidden', !visible);

    /* Close button lives at canvas level now — match the one targeting this element. */
    var $close = pickyGetHostedInlineCloseBtn($host, $el);
    if(!$close.length){
      $close = $host.children('.picky-element-inline-close.picky-inline-close-on-canvas');
    }
    $close.toggleClass('picky-inline-tools-hidden', !visible);
  }

  function pickyLayoutTextToolsBesideOpacity(editor){
    if(!editor || !editor.length){ return; }
    var $fields = editor.find('.picky-element-edit-fields').first();
    var $opIn = editor.find('.picky-element-edit-opacity-input').first();
    if(!$opIn.length){ return; }
    var $opacityRow = $opIn.closest('.picky-element-edit-field');
    var $tools = editor.find('.picky-element-edit-tools').first();
    if(!$fields.length || !$opacityRow.length || !$tools.length){ return; }
    if($opacityRow.next('.picky-element-edit-tools').length){ return; }
    $tools.detach().insertAfter($opacityRow);
  }

  /** Re-measure inspector + shell and re-anchor canvas tool strip (sync; after editor DOM changes). */
  function pickyFlushInspectorLayout($canvasElement){
    if($canvasElement && $canvasElement.length && $canvasElement[0]){
      void $canvasElement[0].offsetHeight;
    }
    syncBuilderToolbarWidth();
    pickyPlaceInspectorNearCanvasOnce();
    pickySyncLeftColDockRect();
    pickySyncInspectorDockRect();
    if($canvasElement && $canvasElement.length){
      pickyPositionInlineCanvasTools($canvasElement);
    }
  }

  /** Full-screen dim with a clear rectangle over the item being edited (fixed; tracks pan/zoom/scroll). */
  var pickyInspectorSpotlightRaf = null;
  function pickyEnsureInspectorSpotlight(){
    // Inline mode: no dimming spotlight around selected element.
    return;
  }
  function pickyHideInspectorSpotlight(){
    if(pickyInspectorSpotlightRaf){
      window.cancelAnimationFrame(pickyInspectorSpotlightRaf);
      pickyInspectorSpotlightRaf = null;
    }
    $('#picky-inspector-spotlight').removeClass('is-active').css({ display: 'none', boxShadow: 'none' });
  }
  function pickyUpdateInspectorSpotlight(){
    var $spot = $('#picky-inspector-spotlight');
    var $insp = $('#picky-element-inspector');
    if(!$spot.length || !$insp.length || !$insp.is(':visible')){
      pickyHideInspectorSpotlight();
      return;
    }
    var $el = $('.picky-draggable .picky-element.opened').first();
    if(!$el.length){
      pickyHideInspectorSpotlight();
      return;
    }
    var r = $el[0].getBoundingClientRect();
    var pad = 8;
    var spread = Math.ceil(Math.max(window.innerWidth, window.innerHeight) * 1.5) + 400;
    $spot
      .addClass('is-active')
      .css({
        display: 'block',
        top: Math.round(r.top - pad),
        left: Math.round(r.left - pad),
        width: Math.max(1, Math.round(r.width + pad * 2)),
        height: Math.max(1, Math.round(r.height + pad * 2)),
        boxShadow: '0 0 0 ' + spread + 'px rgba(10, 14, 26, 0.55)'
      });
  }
  function pickyScheduleInspectorSpotlightUpdate(){
    if(pickyInspectorSpotlightRaf){ return; }
    pickyInspectorSpotlightRaf = window.requestAnimationFrame(function(){
      pickyInspectorSpotlightRaf = null;
      pickyUpdateInspectorSpotlight();
    });
  }

  function pickyCloseElementEditor(options){
    options = options || {};
    var keepMulti = !!options.keepMulti;
    if(typeof pickyCloseAllOpacityPopups === 'function'){ pickyCloseAllOpacityPopups(); }
    pickyResetInlineTextField();
    /* Portaled strip color picker may live on body — close before editor teardown. */
    $('body > .picky-element-color-picker-wrap').each(function(){
      if(typeof pickyCloseToolStripColorPickerPopup === 'function'){
        pickyCloseToolStripColorPickerPopup($(this));
      }
    });
    /* Closing options must also leave transform interaction modes; otherwise
       the 3D joystick/rotate handle can remain visible after editor closes. */
    $('.picky-element.opened').each(function(){
      var $el = $(this);
      $el.removeClass('picky-3d-mode picky-rotate-mode');
      pickyDestroyResizable($el);
      applyTransforms($el);
    });
    if(typeof pickyDestroyMsTransformBox === 'function' && !keepMulti){
      pickyDestroyMsTransformBox();
    }
    var $activeEditor = $('#picky-builder-toolbar .picky-element-edit-wrap');
    if($activeEditor.length){
      pickyRestoreEditorToolsBesideFields($activeEditor);
      pickyTeardownEditorColorUi($activeEditor);
      var $target = $activeEditor.data('picky-target');
      if($target && $target.length){
        // Move back to parent element
        $activeEditor.appendTo($target).hide().removeClass('opened');
      } else {
        $activeEditor.remove();
      }
    }
    $('#picky-builder-toolbar').empty();
    $('#picky-inline-editing-text-slot').hide().empty();
    var $inspClose = $('#picky-element-inspector');
    $inspClose.addClass('is-empty').attr('aria-hidden', 'true');
    if($inspClose.length){
      var elClose = $inspClose[0];
      elClose.style.removeProperty('--picky-insp-top');
      elClose.style.removeProperty('--picky-insp-left');
      elClose.style.removeProperty('--picky-insp-w');
      elClose.style.removeProperty('--picky-insp-h');
    }
    $('.picky-draggable-wrap-all, .picky-tables-wrap, .picky-builder-left-col').removeClass('picky-inspector-is-open');
    pickySetInlineCanvasToolsVisible(true);
    /* Remove the canvas-level close button — it's re-created per open. Also
       strip any legacy in-element close buttons from older markup. */
    $('.picky-draggable-wrap-all > .picky-element-inline-close.picky-inline-close-on-canvas').remove();
    $('.picky-element > .picky-element-inline-close').remove();
    pickyDestroyInspectorDraggable();
    $('.picky-element').removeClass('opened');
    if(!keepMulti){
      pickyClearMultiSelect();
    }
    pickyHideInspectorSpotlight();
    /* Portaled services popups live on body — remove so they don't outlive the inspector */
    $('.picky-services-popup.is-open, body > .picky-services-popup').each(function() {
      var $popup = $(this);
      var $host = $popup.data('picky-chips-host');
      $popup.removeClass('is-open picky-services-popup--portaled')
        .css({ left: '', top: '', width: '', maxHeight: '', position: '' });
      if ($host && $host.length) {
        $popup.appendTo($host);
      } else {
        $popup.remove();
      }
    });
    $('.picky-services-popup-trigger.is-active')
      .removeClass('is-active')
      .find('.picky-services-popup-trigger-arrow').text('▾');
  }

  /* ===== Inspector tab system ===== */
  /**
   * Tab definitions — each field listed by its wrapper CSS class.
   * Fields not listed here are always shown (they're shared / type-specific
   * and already controlled by the whitelist logic above).
   */
  /* Display order matches array order: Booking → Content → Styles. */
  var Picky_EDITOR_TABS = [
    {
      id:    'booking',
      label: 'Booking',
      selectors: [
        '.picky-field-max-seats',
        '.picky-field-service-ids',
      ]
    },
    {
      id:    'content',
      label: 'Content',
      selectors: [
        '.picky-field-label',
        '.picky-field-label-position',
        '.picky-field-label-color',
        '.picky-field-show-id',
        '.picky-field-custom-id',
      ]
    },
    {
      id:    'style',
      label: 'Styles',
      selectors: [
        '.picky-field-opacity',
        '.picky-field-square-radius',
        '.picky-shape-only-field',     // shape type, radius, border-width, border-color
        '.picky-icon-only-field',      // canvas icon picker
        '.picky-text-only-field',      // text color, color mode, font-*, padding
        '.picky-element-edit-field:has(.picky-element-edit-thickness-input)', // wall thickness
        '.picky-element-edit-field:has(.picky-element-edit-wall-radius-input)', // wall corner radius
      ]
    }
  ];

  /** Remembered active tab id per element type key. */
  var pickyActiveTabByType = {};

  /**
   * Build (or rebuild) the tab strip inside the editor after field visibility
   * has already been resolved. Tabs with no visible fields are omitted.
   * When ≤1 tab would appear the strip is removed and all visible fields show.
   */
  function pickyBuildEditorTabs(editor, typeKey) {
    var $fields = editor.find('.picky-element-edit-fields');

    // Remove any previous tab strip and restore raw display on all fields
    editor.find('.picky-editor-tab-strip').remove();
    // Undo per-tab hiding from a previous render — let the existing
    // whitelist-driven show/hide state stand.
    $fields.find('.picky-element-edit-field[data-tab]').each(function(){
      // Only touch fields that were hidden BY the tab system (marked with data-tab-hidden)
      if($(this).data('tab-hidden')){
        $(this).show().removeData('tab-hidden');
      }
    });

    // Assign data-tab to each field based on Picky_EDITOR_TABS membership
    $fields.find('.picky-element-edit-field').removeAttr('data-tab');
    for(var ti = 0; ti < Picky_EDITOR_TABS.length; ti++){
      var tabDef = Picky_EDITOR_TABS[ti];
      for(var si = 0; si < tabDef.selectors.length; si++){
        $fields.find(tabDef.selectors[si]).attr('data-tab', tabDef.id);
      }
    }
    // Fields without data-tab are unassigned — put them in 'style' as catch-all
    $fields.find('.picky-element-edit-field:visible').not('[data-tab]').attr('data-tab', 'style');

    // Which tabs have ≥1 visible field?
    var visibleTabs = [];
    for(var ti2 = 0; ti2 < Picky_EDITOR_TABS.length; ti2++){
      var td = Picky_EDITOR_TABS[ti2];
      var hasVisible = false;
      for(var si2 = 0; si2 < td.selectors.length; si2++){
        if($fields.find(td.selectors[si2] + ':visible').length){ hasVisible = true; break; }
      }
      if(!hasVisible && td.id === 'style'){
        // 'style' also owns unassigned fields
        if($fields.find('.picky-element-edit-field:visible:not([data-tab]), .picky-element-edit-field:visible[data-tab="style"]').length){ hasVisible = true; }
      }
      if(hasVisible){ visibleTabs.push(td); }
    }

    // Build the tab strip unconditionally so the right-side "Editing …"
    // label has a consistent home across all element types (Shape, Text,
    // Wall, bookable). Tab buttons render only when there is real
    // navigation to do (≥2 visible tab groups).
    var $strip = $('<div class="picky-editor-tab-strip" role="tablist"></div>');

    if(visibleTabs.length >= 2){
      // Pick the remembered tab for this type (or first available)
      var preferred = pickyActiveTabByType[typeKey] || visibleTabs[0].id;
      var activeId  = preferred;
      var found = false;
      for(var vi = 0; vi < visibleTabs.length; vi++){
        if(visibleTabs[vi].id === preferred){ found = true; break; }
      }
      if(!found){ activeId = visibleTabs[0].id; }

      for(var bi = 0; bi < visibleTabs.length; bi++){
        var vt = visibleTabs[bi];
        var $btn = $('<button type="button" class="picky-editor-tab-btn" role="tab"></button>')
          .text(vt.label)
          .attr('data-tab-id', vt.id)
          .attr('aria-selected', vt.id === activeId ? 'true' : 'false')
          .toggleClass('picky-editor-tab-btn--active', vt.id === activeId);
        $strip.append($btn);
      }

      // Apply tab: hide fields not in active tab, show those in it
      pickyApplyEditorTab(editor, $fields, activeId);
    }

    // Right-side editing label (always present)
    var $target = editor.data('picky-target');
    var $element = $target && $target.length ? $($target) : null;
    $strip.append(pickyBuildEditingLabel($element, typeKey));

    editor.find('.picky-element-edit-heading').after($strip);
  }

  /**
   * Build the right-side "Editing …" label that lives inside the editor's
   * tab strip.
   *
   * For non-bookable elements (shape/text/wall) it surfaces the element
   * type ("Editing Shape" / "Editing Text" / "Editing Wall"). For bookable
   * elements it surfaces the user-facing item name plus a compact `#ID`
   * chip so the panel always answers "what am I editing?" at a glance.
   */
  function pickyBuildEditingLabel($element, typeKey){
    var typeStr = (typeKey || '').toString();
    var isBookable = typeStr !== 'type-wall' && typeStr !== 'type-text' && typeStr !== 'type-shape' && typeStr !== 'type-icon';
    var $wrap = $('<div class="picky-editor-tab-strip-label" aria-live="polite"></div>');
    var $text = $('<span class="picky-editor-tab-strip-label-text"></span>');

    if(isBookable){
      if($element && $element.length){
        var labelVal = ($element.attr('data-label') || '').toString().trim();
        $text.text(labelVal ? 'Editing ' + labelVal : 'Editing');
        $wrap.append($text);
        var displayId = pickyGetDisplayId($element);
        if(displayId !== '' && displayId !== '0'){
          $wrap.append($('<span class="picky-editor-tab-strip-label-id" />').text('id: ' + displayId));
        }
      } else {
        $text.text('Editing');
        $wrap.append($text);
      }
    } else {
      // Non-bookable: show the type name (Shape / Text / Wall) so users
      // can tell at a glance what they're editing. Reuse the localized
      // labels from pickyLayoutData when available so translations work.
      var data = typeof window.pickyLayoutData !== 'undefined' ? window.pickyLayoutData : {};
      var typeLabels = data.elementTypeLabels || {};
      var typeLabel = typeLabels[typeStr];
      if(!typeLabel){
        if(typeStr === 'type-wall')       typeLabel = 'Wall';
        else if(typeStr === 'type-text')  typeLabel = 'Text';
        else if(typeStr === 'type-shape') typeLabel = 'Shape';
        else if(typeStr === 'type-icon')  typeLabel = 'Icon';
        else                              typeLabel = '';
      }
      $text.text(typeLabel ? 'Editing ' + typeLabel : 'Editing');
      $wrap.append($text);
    }

    $wrap.append(
      $('<button type="button" class="picky-element-inspector-close" title="Close" aria-label="Close">×</button>')
    );

    return $wrap;
  }

  /**
   * Replace the existing "Editing …" label inside an open editor so live
   * changes (label text, custom ID) show without requiring a full
   * re-render of the tab strip / fields.
   */
  function pickyRefreshEditingLabel(editor){
    if(!editor || !editor.length){ return; }
    var $strip = editor.find('.picky-editor-tab-strip').first();
    if(!$strip.length){ return; }
    var typeKey = (editor.attr('data-picky-type') || '').toString();
    var $target = editor.data('picky-target');
    var $element = $target && $target.length ? $($target) : null;
    var $newLabel = pickyBuildEditingLabel($element, typeKey);
    var $oldLabel = $strip.find('.picky-editor-tab-strip-label').first();
    if($oldLabel.length){
      $oldLabel.replaceWith($newLabel);
    } else {
      $strip.append($newLabel);
    }
  }

  function pickyApplyEditorTab(editor, $fields, tabId){
    $fields.find('.picky-element-edit-field[data-tab]').each(function(){
      var $f = $(this);
      // Only touch fields that are already visible (respect whitelist hiding)
      if($f.attr('data-tab') === tabId){
        if($f.data('tab-hidden')){
          $f.show().removeData('tab-hidden');
        }
      } else {
        // Only hide if the field is currently visible
        if($f.is(':visible')){
          $f.hide().data('tab-hidden', true);
        }
      }
    });
    editor.find('.picky-editor-tab-btn').each(function(){
      var active = $(this).attr('data-tab-id') === tabId;
      $(this).toggleClass('picky-editor-tab-btn--active', active)
             .attr('aria-selected', active ? 'true' : 'false');
    });
  }

  // Tab click
  $(document).on('click', '.picky-editor-tab-btn', function(){
    var $btn   = $(this);
    var tabId  = $btn.attr('data-tab-id');
    var editor = $btn.closest('.picky-element-edit-wrap');
    var $fields = editor.find('.picky-element-edit-fields');
    var typeKey = editor.attr('data-picky-type') || 'default';
    pickyActiveTabByType[typeKey] = tabId;
    pickyApplyEditorTab(editor, $fields, tabId);
    /* Safety net: if any color input in the now-visible tab was hidden at
       initial setup and missed wpColorPicker init, upgrade it now. */
    if(typeof pickySetupEditorFieldColorPickers === 'function'){
      pickySetupEditorFieldColorPickers(editor);
    }
  });
  /* ===== End inspector tab system ===== */

  function pickyOpenElementEditor(element, options){
    options = options || {};
    var keepMulti = !!options.keepMulti;
    ensureElementEditor(element);
    var editor = element.find('.picky-element-edit-wrap').first();
    if(!editor.length){ return; }

    if(element.hasClass('opened')){ 
      // Keep current options open when interacting with the same element
      // (e.g. rotate/drag release should not collapse the panel).
      if(keepMulti){ element.addClass(Picky_MS_CLASS); }
      return;
    } else {
      pickyCloseElementEditor({ keepMulti: keepMulti }); // Close any other opened first
      if(!keepMulti){
        pickyClearMultiSelect();
      }
      
      element.addClass('opened');
      if(keepMulti){ element.addClass(Picky_MS_CLASS); }
      editor.addClass('opened');
      updateInteractiveHandlesPosition(element);
      
      // Move into floating inspector panel and store target
      editor.appendTo('#picky-builder-toolbar').show();
      editor.data('picky-target', element);
      $('#picky-element-inspector').removeClass('is-empty').attr('aria-hidden', 'false');
      $('.picky-draggable-wrap-all, .picky-tables-wrap, .picky-builder-left-col').addClass('picky-inspector-is-open');
      syncBuilderToolbarWidth();

      if(editor.find('.picky-element-edit-heading').length === 0){
        editor.prepend('<div class="picky-element-edit-heading" aria-live="polite"></div>');
      }
      
      // Sync 3D mode state
      if(element.hasClass('picky-3d-mode')){
        editor.addClass('picky-3d-mode-active');
      } else {
        editor.removeClass('picky-3d-mode-active');
      }
      
      // Populate fields & Manage visibility
      var type = (element.attr('data-type') || '').toString().trim();
      if(!type){
        if(element.hasClass('type-wall')) type = 'type-wall';
        else if(element.hasClass('type-text')) type = 'type-text';
        else if(element.hasClass('type-shape')) type = 'type-shape';
        else if(element.hasClass('type-icon')) type = 'type-icon';
        else if(element.hasClass('type-1')) type = 'type-1';
        else if(element.hasClass('type-4')) type = 'type-4';
        else if(element.hasClass('type-6')) type = 'type-6';
        else if(element.hasClass('type-square')) type = 'type-square';
        else if(element.hasClass('type-circle')) type = 'type-circle';
        else type = 'type-table';
      } else {
        /* Saved / cloned markup can desync class vs data-type. */
        if(element.hasClass('type-1') && type !== 'type-1') type = 'type-1';
        else if(element.hasClass('type-6') && type !== 'type-6') type = 'type-6';
        else if(element.hasClass('type-square') && type !== 'type-square') type = 'type-square';
      }
      editor.attr('data-picky-type', type);
      var heading = pickyFormatEditingHeading(type);
      var rawId = (element.attr('data-id') || '').toString().trim();
      var $editHeading = editor.find('.picky-element-edit-heading');
      $editHeading.find('.picky-inline-editing-id').remove();
      $editHeading.text(heading);
      var displayIdForChip = pickyGetDisplayId(element);
      if(displayIdForChip){
        /* ID chip: shows custom ID (if set) or the auto numeric ID. */
        $editHeading.append(
          $('<span class="picky-inline-editing-id" />').text('id: ' + displayIdForChip)
        );
      }
      
      // 1. Reset everything
      editor.find('.picky-element-edit-field').hide();
      editor.find('.picky-element-edit-btn').hide();
      
      // 2. Setup by Type (Explicit Whitelist) — ID lives in the heading chip,
      // so it is intentionally NOT part of any field whitelist below.
      if(type === 'type-wall'){
        // WALLS: Thickness + corner radius + Opacity + free rotate (like bookables).
        editor.find('.picky-element-edit-field:has(.picky-element-edit-thickness-input), .picky-element-edit-field:has(.picky-element-edit-wall-radius-input), .picky-field-opacity').show();
        editor.find('.picky-element-edit-color, .picky-element-edit-rotate, .picky-element-duplicate, .picky-element-layer-front, .picky-element-layer-back, .picky-element-delete').show();
      } 
      else if(type === 'type-text'){
        // TEXT: color is controlled from inline tools strip (like other types).
        // Keep top row without Text Color field.
        editor.find('.picky-field-opacity').show();
        editor.find('.picky-element-edit-field:has(.picky-element-edit-text-input), .picky-element-edit-field:has(.picky-element-edit-font-size-input), .picky-element-edit-field:has(.picky-element-edit-line-height-input), .picky-element-edit-field:has(.picky-element-edit-font-family-input), .picky-element-edit-field:has(.picky-element-edit-font-weight-input), .picky-element-edit-field:has(.picky-element-edit-padding-input)').show();
        editor.find('.picky-element-edit-color, .picky-element-edit-3d, .picky-element-edit-rotate, .picky-element-duplicate, .picky-element-layer-front, .picky-element-layer-back, .picky-element-delete').show();
      } 
      else if(type === 'type-shape'){
        // SHAPES: type, fill, corner radius, outline, opacity + same tools as tables (resize / image).
        editor.find('.picky-shape-only-field, .picky-field-opacity').show();
        editor.find('.picky-element-edit-resize, .picky-element-custom-upload, .picky-element-edit-color, .picky-element-edit-3d, .picky-element-edit-rotate, .picky-element-duplicate, .picky-element-layer-front, .picky-element-layer-back, .picky-element-delete').show();
      }
      else if(type === 'type-icon'){
        // ICONS: glyph picker + opacity + color (no upload / shape geometry).
        editor.find('.picky-icon-only-field, .picky-field-opacity').show();
        editor.find('.picky-element-edit-resize, .picky-element-edit-color, .picky-element-edit-3d, .picky-element-edit-rotate, .picky-element-duplicate, .picky-element-layer-front, .picky-element-layer-back, .picky-element-delete').show();
      }
      else {
        // TABLES (bookable): no ID field — that info lives in the heading chip.
        editor.find('.picky-field-max-seats, .picky-field-show-id, .picky-field-custom-id, .picky-field-label, .picky-field-label-position, .picky-field-label-color, .picky-field-opacity, .picky-field-service-ids').show();
        editor.find('.picky-element-edit-btn').show(); // All tools including image upload
        if(pickyUsesBookableSquareRadius(element)){
          editor.find('.picky-type-1-only-field, .picky-field-square-radius').show().css('display', 'flex');
        }
        // Render available service chips for this element
        pickyRenderServiceIdChips(editor, element);
      }

      pickyRestoreEditorToolsBesideFields(editor);
      pickyMountInlineTextField(editor, type);
      if(type === 'type-text'){
        pickyOrderTextFields(editor);
      }
      /* Create the canvas-level close button first, then render the tool
         strip — pickyRenderEditorToolsOnCanvas ends by calling the positioning
         routine which anchors BOTH the tool strip and the close button. */
      pickyEnsureInlineCloseOnCanvas(element);
      pickyRenderEditorToolsOnCanvas(editor, element);


      // Populate values accurately (ID is intentionally not a field — shown in heading chip).
      editor.find('.picky-element-edit-label-input').val(element.attr('data-label') || '');
      editor.find('.picky-element-edit-label-position-input').val(pickyNormalizeLabelPosition(element.attr('data-label-position')));
      editor.find('.picky-element-edit-label-color-input').val(normalizeHexColor(element.attr('data-label-color'), '#333333'));
      var maxPersons = parseInt(element.attr('data-max-seats') || '0', 10);
      editor.find('.picky-element-edit-max-persons-input').val(maxPersons > 0 ? maxPersons : '');
      editor.find('.picky-element-edit-show-id-input').prop('checked', (element.attr('data-show-id') || '1') !== '0');
      editor.find('.picky-element-edit-custom-id-input').val((element.attr('data-custom-id') || '').toString().trim());
      
      var opacity = normalizeOpacity(element.attr('data-opacity'));
      var opacityPercent = Math.round(opacity * 100);
      editor.find('.picky-element-edit-opacity-input').val(opacityPercent);
      editor.find('.picky-element-edit-opacity-value').text(opacityPercent + '%');
      
      if(type === 'type-wall'){
        var $tw = element.find('.picky-element-table-wrap');
        editor.find('.picky-element-edit-thickness-input').val(Math.round(Math.min($tw.outerWidth(), $tw.outerHeight())));
        editor.find('.picky-element-edit-bg-color-input').val(element.attr('data-color') || '#ffffff');
        var wr = (element.attr('data-wall-radius') || '').toString().trim();
        editor.find('.picky-element-edit-wall-radius-input').val(wr === '' ? '' : wr);
      }
      
      if(type === 'type-text'){
        var textMode = (element.attr('data-text-color-mode') === 'theme') ? 'theme' : 'custom';
        editor.find('.picky-element-edit-text-input').val(element.attr('data-text') || '');
        editor.find('.picky-element-edit-font-size-input').val(element.attr('data-font-size') || '16');
        var lhRaw = (element.attr('data-line-height') || '').toString().trim();
        editor.find('.picky-element-edit-line-height-input').val(lhRaw === '' ? '' : lhRaw);
        editor.find('.picky-element-edit-font-family-input').val(element.attr('data-font-family') || 'inherit');
        editor.find('.picky-element-edit-font-weight-input').val(element.attr('data-font-weight') || 'normal');
        editor.find('.picky-element-edit-padding-input').val(element.attr('data-padding') || '10');
        editor.find('.picky-element-edit-bg-color-input').val(element.attr('data-color') || '#ffffff');
        editor.find('.picky-element-edit-text-color-input').val(element.attr('data-text-color') || '#333333');
        editor.find('.picky-element-edit-text-color-mode-input').val(textMode);
        editor.find('.picky-element-edit-text-color-input').prop('disabled', textMode === 'theme');
      }
      
      if(type === 'type-shape'){
        editor.find('.picky-element-edit-shape-type-input').val(element.attr('data-shape-type') || 'rectangle');
        var sr = (element.attr('data-shape-radius') || '').toString().trim();
        editor.find('.picky-element-edit-shape-radius-input').val(sr === '' ? '' : sr);
        editor.find('.picky-element-edit-shape-border-width-input').val(element.attr('data-shape-border-width') || '0');
        editor.find('.picky-element-edit-shape-border-color-input').val(
          normalizeHexColor(element.attr('data-shape-border-color') || '', '#333333')
        );
      }
      if(type === 'type-icon'){
        var iconKey = (element.attr('data-icon') || '').toString().trim() || 'star';
        pickyBiconSetPickerValue(editor.find('[data-picky-bicon-picker]').first(), iconKey);
        pickyApplyCanvasIcon(element);
      }
      if(pickyUsesBookableSquareRadius(element)){
        var sqr = (element.attr('data-square-radius') || '').toString().trim();
        // Legacy "0" means default, not sharp — show empty field.
        if(sqr === '' || sqr === '0'){
          element.removeAttr('data-square-radius');
          editor.find('.picky-element-edit-square-radius-input').val('');
        } else {
          editor.find('.picky-element-edit-square-radius-input').val(sqr);
        }
      }

      if(type === 'type-table' || type.indexOf('table') !== -1){
        editor.find('.picky-element-edit-bg-color-input').val(element.attr('data-color') || '#ffffff');
      }
      
      editor.show().addClass('opened');
      element.addClass('opened');
      /* Init color pickers BEFORE the tab system hides non-active tab fields.
         wpColorPicker silently skips hidden inputs, so initialising after the
         tab strip would leave the Label-color picker (in the "Content" tab)
         with an un-upgraded text input the user sees as a plain field. */
      pickySetupEditorFieldColorPickers(editor);
      pickyBuildEditorTabs(editor, type);
      window.requestAnimationFrame(function(){
        editor.find('.picky-element-edit-bg-color-input, .picky-element-edit-text-color-input, .picky-element-edit-shape-border-color-input, .picky-element-edit-label-color-input').each(function(){
          var $i = $(this);
          if(!$i.closest('.picky-element-edit-field').is(':visible')){ return; }
          if($i.data('pickyWpPickerReady')){
            try {
              var fb = pickyWpFieldPickerFallback($i);
              /* Iris only understands 6-char hex — pass the stripped value so
                 it can repaint the wheel, then restore the alpha-carrying
                 hex8 to the input so the popup's Opacity slider re-reads it. */
              var fullHex = normalizeHexColor($i.val(), fb);
              $i.wpColorPicker('color', pickyStripAlpha(fullHex));
              if(fullHex.length === 9){ $i.val(fullHex); }
            } catch(e){ /* noop */ }
          }
          pickySyncWpColorResultLabel($i);
        });
      });
      pickyEnsureInspectorSpotlight();
      pickyScheduleInspectorSpotlightUpdate();

      /* Inspector + shell width/position and tool strip: sync now (sidebar drop
         must not wait a frame for #picky-element-inspector to look updated). */
      pickyDestroyInspectorDraggable();
      pickyFlushInspectorLayout(element);
      window.requestAnimationFrame(function(){
        pickyFlushInspectorLayout(element);
        pickyEnsureInspectorDraggable();
      });
    }
  }

  // Change label
  $(document).on('keyup change input', '.picky-element-edit-label-input', function(){
    var element = getEditorTarget(this);
    var val = $(this).val();
    element.attr('data-label', val);
    element.find('.picky-element-label').text(val);
    applyElementLabelStyles(element);
    pickyRefreshEditingLabel($(this).closest('.picky-element-edit-wrap'));
  });

  $(document).on('input change', '.picky-element-edit-label-color-input', function(){
    var element = getEditorTarget(this);
    if(!element.length){ return; }
    var $i = $(this);
    var hex = normalizeHexColor($i.val(), '#333333');
    $i.val(hex);
    element.attr('data-label-color', hex);
    applyElementLabelStyles(element);
    pickySyncWpColorResultLabel($i);
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  });

  $(document).on('change', '.picky-element-edit-show-id-input', function(){
    var element = getEditorTarget(this);
    if(!element || !element.length){ return; }
    element.attr('data-show-id', $(this).is(':checked') ? '1' : '0');
    takeSnapshot();
  });

  /** Return the text that should appear in the ID badge for a given element.
   *  Uses data-custom-id when set (non-empty), otherwise falls back to data-id. */
  function pickyGetDisplayId(element){
    var custom = (element.attr('data-custom-id') || '').toString().trim();
    return custom !== '' ? custom : (element.attr('data-id') || '').toString();
  }

  /** Collect all display IDs currently on the canvas, indexed by data-unique,
   *  optionally skipping one element (the one being edited). */
  function pickyGetAllDisplayIds(excludeElement){
    var map = {};
    var excludeUnique = excludeElement ? (excludeElement.attr('data-unique') || '').toString() : '';
    $('.picky-draggable .picky-element').not('.picky-element-deleting').each(function(){
      var $el = $(this);
      var elType = ($el.attr('data-type') || '').toString();
      // Only bookable elements carry meaningful IDs (walls/text/shapes have data-id 0)
      if(elType === 'type-wall' || elType === 'type-text' || elType === 'type-shape' || elType === 'type-icon'){ return; }
      var elUnique = ($el.attr('data-unique') || '').toString();
      if(excludeUnique !== '' && elUnique === excludeUnique){ return; }
      var did = pickyGetDisplayId($el);
      if(did !== ''){ map[elUnique] = did; }
    });
    return map;
  }

  /** Return the duplicate display ID string if val conflicts with any other
   *  canvas element, or null if the value is free. */
  function pickyCheckDuplicateDisplayId(currentElement, val){
    val = (val || '').toString().trim();
    if(val === ''){ return null; }
    var others = pickyGetAllDisplayIds(currentElement);
    for(var u in others){
      if(Object.prototype.hasOwnProperty.call(others, u) && others[u].toString() === val){
        return val;
      }
    }
    return null;
  }

  function pickySetCustomIdError($input, msg){
    var $field = $input.closest('.picky-element-edit-field');
    $input.css({ borderColor: msg ? '#c0392b' : '', boxShadow: msg ? '0 0 0 2px rgba(192,57,43,.25)' : '' });
    $field.find('.picky-custom-id-error').remove();
    if(msg){
      $field.append($('<p class="picky-custom-id-error" style="margin:3px 0 0;font-size:11px;color:#c0392b;">' + msg + '</p>'));
    }
  }

  $(document).on('input change', '.picky-element-edit-custom-id-input', function(){
    var element = getEditorTarget(this);
    if(!element || !element.length){ return; }
    var val = $(this).val().toString().trim();
    if(val === ''){
      element.removeAttr('data-custom-id');
    } else {
      element.attr('data-custom-id', val);
    }
    // Live duplicate validation
    var dupId = pickyCheckDuplicateDisplayId(element, val);
    if(dupId !== null){
      pickySetCustomIdError($(this), 'ID \u201c' + dupId + '\u201d is already used by another element.');
    } else {
      pickySetCustomIdError($(this), null);
    }
    // Live-update the badge
    var displayId = pickyGetDisplayId(element);
    element.find('.picky-element-id').text(displayId);
    // Also update the heading chip
    var $wrap = element.find('.picky-element-edit-wrap.opened').first();
    if(!$wrap.length){ $wrap = $('#picky-builder-toolbar .picky-element-edit-wrap'); }
    if($wrap.length){
      var rawId = (element.attr('data-id') || '').toString().trim();
      var $heading = $wrap.find('.picky-element-edit-heading');
      $heading.find('.picky-inline-editing-id').remove();
      if(displayId){
        $heading.append($('<span class="picky-inline-editing-id" />').text('id: ' + displayId));
      } else if(rawId){
        $heading.append($('<span class="picky-inline-editing-id" />').text('id: ' + rawId));
      }
      pickyRefreshEditingLabel($wrap);
    }
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  });

  $(document).on('change', '.picky-element-edit-label-position-input', function(){
    var element = getEditorTarget(this);
    if(!element.length) return;
    var v = pickyNormalizeLabelPosition($(this).val());
    element.attr('data-label-position', v);
    $(this).val(v);
    applyTransforms(element);
  });

  $(document).on('keyup change input', '.picky-element-edit-max-persons-input', function(){
    var element = getEditorTarget(this);
    if(!element.length) return;
    var val = $(this).val();
    element.attr('data-max-seats', val);
    element.attr('data-seats', val);
    element.find('.picky-element-seats').text(val);
  });

  $(document).on('input change', '.picky-element-edit-opacity-input', function(){
    var element = getEditorTarget(this);
    var percent = parseInt($(this).val(), 10);
    if(isNaN(percent)) percent = 100;
    var opacity = normalizeOpacity(percent / 100);
    element.attr('data-opacity', opacity);
    var $field = pickyOpacityPopupField($(this).closest('.picky-element-edit-opacity-popup'));
    if(!$field.length){
      $field = $(this).closest('.picky-element-edit-field');
    }
    $field.find('.picky-element-edit-opacity-value').text(Math.round(opacity * 100) + '%');
    applyElementOpacity(element);
  });

  /* Opacity popup: click the compact button to reveal the range slider in a
     floating popup (similar to the color picker). Click outside to close.
     Portaled to body so #picky-element-inspector overflow/transform cannot clip it. */
  function pickyPortalOpacityPopup($popup, $field){
    if(!$popup || !$popup.length){ return; }
    if($popup.parent().is('body')){ return; }
    $popup.data('pickyHome', $field && $field.length ? $field : $popup.parent());
    $popup.addClass('picky-opacity-popup-portal');
    $popup.appendTo(document.body);
  }

  function pickyUnportalOpacityPopup($popup){
    if(!$popup || !$popup.length){ return; }
    var $home = $popup.data('pickyHome');
    $popup.removeClass('picky-opacity-popup-portal');
    $popup.css({ left: '', top: '', visibility: '', position: '', zIndex: '' });
    if($home && $home.length && $home.closest('body').length){
      $popup.appendTo($home);
    }
    $popup.removeData('pickyHome');
    $popup.removeData('pickyAnchor');
  }

  function pickyOpacityPopupField($popup){
    if(!$popup || !$popup.length){ return $(); }
    var $home = $popup.data('pickyHome');
    if($home && $home.length){ return $home; }
    return $popup.closest('.picky-element-edit-field');
  }

  function pickyPositionOpacityPopup($popup, $btn){
    if(!$popup || !$popup.length || !$btn || !$btn.length) return;
    var rect = $btn[0].getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var margin = 8;
    var gap = 6;

    $popup.css({ position: 'fixed', visibility: 'hidden', left: '0px', top: '0px' });
    var w = Math.ceil($popup.outerWidth()) || 200;
    var h = Math.ceil($popup.outerHeight()) || 48;

    var left = rect.left + rect.width / 2 - w / 2;
    if(left + w > vw - margin){ left = vw - w - margin; }
    if(left < margin){ left = margin; }

    var top;
    var spaceBelow = vh - rect.bottom - margin;
    if(spaceBelow >= h + gap){
      top = rect.bottom + gap;
    } else {
      top = rect.top - h - gap;
    }
    top = Math.max(margin, Math.min(top, vh - h - margin));

    $popup.css({
      left: Math.round(left) + 'px',
      top: Math.round(top) + 'px',
      visibility: ''
    });
  }

  function pickyCloseAllOpacityPopups(){
    $('.picky-element-edit-opacity-popup').each(function(){
      var $popup = $(this);
      $popup.hide();
      pickyUnportalOpacityPopup($popup);
    });
    $('.picky-element-edit-opacity-btn').attr('aria-expanded', 'false');
  }

  $(document).on('click', '.picky-element-edit-opacity-btn', function(e){
    e.preventDefault();
    e.stopPropagation();
    var $opEl = getEditorTarget(this);
    if($opEl && $opEl.length){ pickyDestroyResizable($opEl); }
    var $btn = $(this);
    var $field = $btn.closest('.picky-element-edit-field');
    var $popup = $field.find('.picky-element-edit-opacity-popup').first();
    if(!$popup.length){
      /* Already portaled to body from a previous open of this field. */
      $popup = $('body > .picky-element-edit-opacity-popup.picky-opacity-popup-portal').filter(function(){
        var $h = $(this).data('pickyHome');
        return $h && $h[0] === $field[0];
      }).first();
    }
    if(!$popup.length) return;

    var wasOpen = $popup.is(':visible');
    pickyCloseAllOpacityPopups();
    if(wasOpen) return;

    pickyPortalOpacityPopup($popup, $field);
    $popup.data('pickyAnchor', $btn);
    $popup.show();
    $btn.attr('aria-expanded', 'true');
    pickyPositionOpacityPopup($popup, $btn);
    window.requestAnimationFrame(function(){
      pickyPositionOpacityPopup($popup, $btn);
    });
  });

  $(document).on('click', function(e){
    if($(e.target).closest('.picky-element-edit-opacity-popup, .picky-element-edit-opacity-btn').length === 0){
      pickyCloseAllOpacityPopups();
    }
  });

  $(window).on('resize scroll', function(){
    $('.picky-element-edit-opacity-popup:visible').each(function(){
      var $popup = $(this);
      var $btn = $popup.data('pickyAnchor');
      if(!$btn || !$btn.length){
        $btn = pickyOpacityPopupField($popup).find('.picky-element-edit-opacity-btn').first();
      }
      if($btn.length){ pickyPositionOpacityPopup($popup, $btn); }
    });
  });

  $(document).on('input change', '.picky-element-edit-thickness-input', function(){
    var element = getEditorTarget(this);
    if(!element.length) return;
    var $tw = element.find('.picky-element-table-wrap');
    var val = Math.max(4, Math.min(500, parseInt($(this).val(), 10) || 10));
    var w = $tw.outerWidth();
    var h = $tw.outerHeight();
    if(w >= h) $tw.css('height', val + 'px');
    else $tw.css('width', val + 'px');
    pickyInitWallHandles(element);
    $(this).val(val);
  });

  $(document).on('input change', '.picky-element-edit-wall-radius-input', function(){
    var element = getEditorTarget(this);
    if(!element.length) return;
    var raw = ($(this).val() || '').toString().trim();
    if(raw === ''){
      element.removeAttr('data-wall-radius');
      $(this).val('');
    } else {
      var n = parseInt(raw, 10);
      if(isNaN(n) || n < 0){ n = 0; }
      if(n > 120){ n = 120; }
      element.attr('data-wall-radius', String(n));
      $(this).val(String(n));
    }
    applyTransforms(element);
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  });

  $(document).on('click', '.picky-wall-rotate-90', function(e){
    e.preventDefault();
    e.stopPropagation();
    var $wEl = getEditorTarget(this);
    if($wEl && $wEl.length){ pickyDestroyResizable($wEl); }
    pickyRotateWall($wEl);
  });

  // Text: Typography controls
  function updateTextPreview(element){
    var $shape = element.find('.picky-element-shape');
    var text = element.attr('data-text') || '';
    var fontSize = element.attr('data-font-size') || '16';
    var fontFamily = element.attr('data-font-family') || 'inherit';
    var fontWeight = element.attr('data-font-weight') || 'normal';
    var textColor = element.attr('data-text-color') || '#333';
    var textColorMode = (element.attr('data-text-color-mode') === 'theme') ? 'theme' : 'custom';
    var padding = element.attr('data-padding') || '10';

    // Only modify shape HTML for type-text. Other types use separate divs for ID/Label.
    if(element.hasClass('type-text')){
        // Text elements should never render image backgrounds.
        $shape.css('background-image', 'none');
        var $txt = $shape.find('.picky-element-text-content');
        if($txt.length === 0){
            $shape.html('<div class="picky-element-text-content"></div>');
            $txt = $shape.find('.picky-element-text-content');
        }
        if($txt.text() !== text){
            $txt.text(text);
        }
        var fsPx = parseFloat(fontSize, 10);
        if(isNaN(fsPx) || fsPx <= 0){ fsPx = 16; }
        var lhRaw = (element.attr('data-line-height') || '').toString().trim();
        var lhPx = parseFloat(lhRaw, 10);
        if(lhRaw === '' || isNaN(lhPx) || lhPx <= 0){ lhPx = fsPx; }
        if(lhPx < 8){ lhPx = 8; }
        if(lhPx > 400){ lhPx = 400; }
        /* Same stack as public when inherit — glyph metrics must match front. */
        var fontFamilyCss = (!fontFamily || fontFamily === 'inherit')
          ? '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'
          : fontFamily;
        $txt.css({
            'font-size': fsPx + 'px',
            '--picky-text-line-height': lhPx + 'px',
            'line-height': lhPx + 'px',
            'font-family': fontFamilyCss,
            'font-weight': fontWeight,
            'color': (textColorMode === 'theme') ? 'inherit' : textColor,
            'padding': padding + 'px',
            'background-color': 'transparent',
            'display': 'block',
            'visibility': 'visible',
            'opacity': '1'
        });
        // Plate fill on the shape (inside the rotating wrap) — not on the outer .picky-element.
        var textBg = element.attr('data-color') || '';
        if(textBg){
          $shape.css('background-color', textBg);
        } else {
          $shape.css('background-color', 'transparent');
        }
    } else if(element.hasClass('type-icon')){
        pickyApplyCanvasIcon(element);
        element.find('.picky-element-label').text(element.attr('data-label') || '');
        applyElementLabelStyles(element);
        element.find('.picky-element-id').text(pickyGetDisplayId(element));
    } else {
        var bgColor = element.attr('data-color');
        var customUrl = (element.attr('data-custom-url') || '').toString().trim();
        // Custom image: never re-apply solid plate color (shows through transparent PNGs).
        if(customUrl){
            $shape.css({ 'background-color': 'transparent', 'padding': '0' });
        } else if(bgColor) {
            $shape.css('background-color', bgColor);
        } else {
            $shape.css('background-color', '');
        }
        element.find('.picky-element-label').text(element.attr('data-label') || '');
        applyElementLabelStyles(element);
        element.find('.picky-element-id').text(pickyGetDisplayId(element));
    }
    
    // Auto-clamp to canvas if size changed (text corner-resize sets position after measure; skip mid-drag).
    if(!element.data('picky-text-resize-skip-push')){
      pushIntoCanvas(element);
    }
}

  $(document).on('keyup change input', '.picky-element-edit-text-input', function(){
    var element = getEditorTarget(this);
    element.attr('data-text', $(this).val());
    updateTextPreview(element);
  });

  $(document).on('input change', '.picky-element-edit-font-size-input', function(){
    var element = getEditorTarget(this);
    element.attr('data-font-size', $(this).val());
    updateTextPreview(element);
  });

  $(document).on('input change', '.picky-element-edit-line-height-input', function(){
    var element = getEditorTarget(this);
    if(!element || !element.length){ return; }
    var raw = String($(this).val() || '').trim();
    if(raw === ''){
      element.removeAttr('data-line-height');
      $(this).val('');
    } else {
      var n = parseInt(raw, 10);
      if(isNaN(n) || n < 8){ n = 8; }
      if(n > 400){ n = 400; }
      element.attr('data-line-height', String(n));
      $(this).val(String(n));
    }
    updateTextPreview(element);
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  });

  $(document).on('change', '.picky-element-edit-font-family-input', function(){
    var element = getEditorTarget(this);
    element.attr('data-font-family', $(this).val());
    updateTextPreview(element);
  });

  $(document).on('change', '.picky-element-edit-font-weight-input', function(){
    var element = getEditorTarget(this);
    element.attr('data-font-weight', $(this).val());
    updateTextPreview(element);
  });

  $(document).on('change', '.picky-element-edit-text-color-mode-input', function(){
    var element = getEditorTarget(this);
    if(!element || !element.length){ return; }
    var mode = ($(this).val() === 'theme') ? 'theme' : 'custom';
    element.attr('data-text-color-mode', mode);
    var $colorInput = $(this).closest('.picky-element-edit-wrap').find('.picky-element-edit-text-color-input').first();
    if($colorInput.length){
      $colorInput.prop('disabled', mode === 'theme');
    }
    updateTextPreview(element);
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  });

  $(document).on('input change', '.picky-element-edit-padding-input', function(){
    var element = getEditorTarget(this);
    element.attr('data-padding', $(this).val());
    updateTextPreview(element);
  });

  $(document).on('change', '.picky-element-edit-shape-type-input', function(){
    var element = getEditorTarget(this);
    element.attr('data-shape-type', $(this).val());
    applyTransforms(element);
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  });

  $(document).on('input change', '.picky-element-edit-shape-radius-input', function(){
    var element = getEditorTarget(this);
    if(!element || !element.length){ return; }
    var raw = ($(this).val() || '').toString().trim();
    if(raw === ''){
      element.removeAttr('data-shape-radius');
    } else {
      var n = parseInt(raw, 10);
      if(isNaN(n) || n < 0){ n = 0; }
      if(n > 120){ n = 120; }
      element.attr('data-shape-radius', String(n));
      $(this).val(String(n));
    }
    applyTransforms(element);
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  });

  $(document).on('input change', '.picky-element-edit-square-radius-input', function(){
    var element = getEditorTarget(this);
    if(!element || !element.length){ return; }
    var raw = ($(this).val() || '').toString().trim();
    if(raw === '' || raw === '0'){
      // Empty / 0 → CSS default rounding; do not persist sharp corners.
      element.removeAttr('data-square-radius');
      $(this).val('');
    } else {
      var n = parseInt(raw, 10);
      if(isNaN(n) || n < 0){ n = 0; }
      if(n > 120){ n = 120; }
      if(n <= 0){
        element.removeAttr('data-square-radius');
        $(this).val('');
      } else {
        element.attr('data-square-radius', String(n));
        $(this).val(String(n));
      }
    }
    applyTransforms(element);
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  });

  /* Number inputs change on mousewheel even when not focused — easy to
     accidentally set Corner radius to 0 while scrolling the inspector
     during resize. Only allow wheel when the field is focused. */
  $(document).on('wheel', '.picky-element-edit-wrap input[type="number"]', function(e){
    if(document.activeElement !== this){
      e.preventDefault();
    }
  });

  $(document).on('input change', '.picky-element-edit-shape-border-width-input', function(){
    var element = getEditorTarget(this);
    if(!element || !element.length){ return; }
    var n = parseInt($(this).val(), 10);
    if(isNaN(n) || n < 0){ n = 0; }
    if(n > 32){ n = 32; }
    $(this).val(String(n));
    element.attr('data-shape-border-width', String(n));
    applyTransforms(element);
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  });

  /* Turn off canvas resize handles when another tool-strip action is used. */
  $(document).on('click', '.picky-element-edit-tools .picky-element-edit-btn:not(.picky-element-edit-resize)', function(){
    var $t = getEditorTarget(this);
    if(typeof pickyDestroyMsTransformBox === 'function'){ pickyDestroyMsTransformBox(); }
    if($t && $t.length){
      pickyResizeTargetGroup($t).each(function(){ pickyDestroyResizable($(this)); });
    }
  });

  // Toggle resize: single item = local handles; multi-select = one group Free Transform box
  $(document).on('click', '.picky-element-edit-resize', function(){
    var element = getEditorTarget(this);
    if(typeof jQuery.fn.resizable !== 'function') return;
    if(!element || !element.length) return;

    var $targets = pickyResizeTargetGroup(element);
    var isGroup = $targets.length > 1;
    var turningOff = isGroup
      ? (pickyIsMsGroupResizeActive() || $targets.filter('.picky-resize-enabled').length > 0)
      : element.hasClass('picky-resize-enabled');

    if(isGroup){
      pickyDestroyMsTransformBox();
      $targets.each(function(){ pickyDestroyResizable($(this)); });
      if(!turningOff){
        pickyEnableMsGroupResize($targets, element);
      }
    } else {
      pickyDestroyMsTransformBox();
      if(turningOff){
        pickyDestroyResizable(element);
      } else {
        var h = element.hasClass('type-text')
          ? 'nw,ne,se,sw'
          : 'n,e,s,w,ne,se,sw,nw';
        pickyEnableResizable(element, {
          handles: h,
          stop: function(){ pushIntoCanvas(element); }
        });
      }
    }
    /* Park the tool strip above the item while handles are active so bottom
       / side grips stay clickable (strip z-index sits above the element). */
    if(typeof pickyPositionInlineCanvasTools === 'function'){
      pickyPositionInlineCanvasTools(element);
    }
  });  function getVisualBoundingBox($el, twW, twH){
      var w = twW || $el.outerWidth();
      var h = twH || $el.outerHeight();
      
      var rz = (parseFloat($el.attr('data-rotate-z')) || 0) * Math.PI / 180;
      var rx = (parseFloat($el.attr('data-rotate-x')) || 0) * Math.PI / 180;
      var ry = (parseFloat($el.attr('data-rotate-y')) || 0) * Math.PI / 180;
      
      // Visual projection factors for 3D
      var cosX = Math.abs(Math.cos(rx));
      var cosY = Math.abs(Math.cos(ry));
      
      // Effective width and height after 3D tilt
      var effW = w * cosY;
      var effH = h * cosX;
      
      // Apply 2D rotation on top of 3D projection
      var sinZ = Math.abs(Math.sin(rz));
      var cosZ = Math.abs(Math.cos(rz));
      
      var bbW = effW * cosZ + effH * sinZ;
      var bbH = effW * sinZ + effH * cosZ;
      
      return { width: bbW, height: bbH };
  }

  /**
   * Point (lx,ly) in .picky-element coordinates: unrotated wrap rect with top-left (originL, originT),
   * then rotateZ around wrap center (same as CSS transform-origin:center on .picky-element-table-wrap).
   */
  function pickyResizeRotatedLocalPointInElement(W, H, originL, originT, zDeg, lx, ly){
    var z = parseInt(zDeg, 10);
    if(isNaN(z)) z = 0;
    var rad = z * Math.PI / 180;
    var c = Math.cos(rad);
    var s = Math.sin(rad);
    var cx = originL + W / 2;
    var cy = originT + H / 2;
    var dx = lx - cx;
    var dy = ly - cy;
    return {
      x: cx + dx * c - dy * s,
      y: cy + dx * s + dy * c
    };
  }

  /** ix,iy ∈ {0,1} select nw,ne,se,sw corners of the wrap box. */
  function pickyResizeRotatedCornerInElement(W, H, originL, originT, zDeg, ix, iy){
    return pickyResizeRotatedLocalPointInElement(W, H, originL, originT, zDeg, originL + ix * W, originT + iy * H);
  }

  /** Opposite edge midpoint (fixed in canvas while resizing) for n,e,s,w handles. */
  function pickyResizeOppositeEdgeAnchorLocal(W, H, originL, originT, zDeg, dir){
    var lx, ly;
    if(dir === 'n'){ lx = originL + W / 2; ly = originT + H; }
    else if(dir === 's'){ lx = originL + W / 2; ly = originT; }
    else if(dir === 'e'){ lx = originL; ly = originT + H / 2; }
    else if(dir === 'w'){ lx = originL + W; ly = originT + H / 2; }
    else { lx = originL + W / 2; ly = originT + H / 2; }
    return pickyResizeRotatedLocalPointInElement(W, H, originL, originT, zDeg, lx, ly);
  }

  /** Keep inspector / inline toolbar font-size field in sync while resizing text. */
  function pickySyncTextFontSizeInputFromElement($el){
    if(!$el || !$el.length || !$el.hasClass('type-text')){ return; }
    var fs = String($el.attr('data-font-size') || '16');
    $('.picky-element-edit-wrap').each(function(){
      var $w = $(this);
      var $t = $w.data('picky-target');
      if($t && $t.length && $t[0] === $el[0]){
        $w.find('.picky-element-edit-font-size-input').first().val(fs);
      }
    });
  }

  function pickyDestroyResizable(element){
    try { element.resizable('destroy'); } catch(e) {}
    try { element.find('.picky-element-table-wrap').resizable('destroy'); } catch(e) {}
    element.off('mousedown.pickyCustomResize', '.picky-custom-resizer');
    element.removeData('picky-custom-resize-init');
    element.find('.picky-custom-resizer').remove();
    element.removeClass('picky-resize-enabled');
  }

  function pickyEnableResizable(element, opts) {
    opts = opts || {};
    pickyDestroyResizable(element);
    element.addClass('picky-resize-enabled');
    
    // Handles live on .picky-element-table-wrap so they rotate with the shape
    // (rotation is applied to the wrap in applyTransforms for standard elements).
    var $twHost = element.find('.picky-element-table-wrap').first();
    if(!$twHost.length){
      $twHost = element;
    }
    
    var defaultHandles = element.hasClass('type-text')
      ? 'nw,ne,se,sw'
      : 'nw,n,ne,e,se,s,sw,w';
    var handleStr = (opts.handles != null && String(opts.handles).trim() !== '')
      ? String(opts.handles)
      : defaultHandles;
    var handles = handleStr.split(/[, ]+/).map(function(s){ return s.trim(); }).filter(Boolean);
    handles.forEach(function(h){
      $twHost.append('<div class="picky-custom-resizer picky-resizer-'+h+'" data-dir="'+h+'"></div>');
    });

    if(!element.data('picky-custom-resize-init')){
      element.data('picky-custom-resize-init', true);
      element.on('mousedown.pickyCustomResize', '.picky-custom-resizer', function(e){
        e.stopPropagation();
        e.preventDefault();

        var $handle = $(this);
        var dir = $handle.attr('data-dir');
        var $el = $handle.closest('.picky-element');
        var $canvas = $el.closest('.picky-draggable');
        var $tw = $el.find('.picky-element-table-wrap');

        /* Hide inline canvas tool strip + inline close during custom resize
           (event.stopPropagation above prevents the document-level handler
           from firing). */
        pickySetInlineCanvasToolsVisibleForElement($el[0], false);
        
        var startLayoutResize = pickyPageXYToCanvasLocal($canvas, e.pageX, e.pageY);
        var startW = parseFloat($tw.outerWidth());
        var startH = parseFloat($tw.outerHeight());
        var startL = parseFloat($el.css('left')) || 0;
        var startT = parseFloat($el.css('top')) || 0;
        
        var angle = parseInt($el.attr('data-rotate-z') || '0', 10);
        if(isNaN(angle)) angle = 0;
        var rad = angle * Math.PI / 180;
        var cos = Math.cos(rad);
        var sin = Math.sin(rad);
        
        var canvasW = parseFloat($canvas.outerWidth());
        var canvasH = parseFloat($canvas.outerHeight());
        var pad = pickyElementLayoutPadLeft($el);
        var padT = pickyElementLayoutPadTop($el);
        /* Wrap top-left in element border coords: padding places the content box, .position() is *inside* it (often 0,0). */
        var twPos0 = $tw.position();
        var twPL = twPos0 && !isNaN(twPos0.left) ? twPos0.left : 0;
        var twPT = twPos0 && !isNaN(twPos0.top) ? twPos0.top : 0;
        var originL = pad + twPL;
        var originT = padT + twPT;
        var cornerResizeMeta = {
          nw: { anchor: [1, 1], moving: [0, 0] },
          ne: { anchor: [0, 1], moving: [1, 0] },
          se: { anchor: [0, 0], moving: [1, 1] },
          sw: { anchor: [1, 0], moving: [0, 1] }
        };
        var cr = cornerResizeMeta[dir];
        var anchorCanvas = null;
        var d0Corner = 0;
        if(cr){
          var oA0 = pickyResizeRotatedCornerInElement(startW, startH, originL, originT, angle, cr.anchor[0], cr.anchor[1]);
          var oM0 = pickyResizeRotatedCornerInElement(startW, startH, originL, originT, angle, cr.moving[0], cr.moving[1]);
          anchorCanvas = { x: startL + oA0.x, y: startT + oA0.y };
          d0Corner = Math.hypot(oM0.x - oA0.x, oM0.y - oA0.y);
        } else if(dir.length === 1 && 'nesw'.indexOf(dir) !== -1){
          var oE0 = pickyResizeOppositeEdgeAnchorLocal(startW, startH, originL, originT, angle, dir);
          anchorCanvas = { x: startL + oE0.x, y: startT + oE0.y };
        }
        
        // Disable drag temporarily
        if($el.hasClass('ui-draggable')) {
            try{ $el.draggable('disable'); } catch(ex){}
        }

        var startFontForTextResize = $el.hasClass('type-text') ? (parseFloat($el.attr('data-font-size')) || 16) : 0;
        if($el.hasClass('type-text')){
          $el.data('picky-text-resize-skip-push', 1);
        }

        $(document).on('mousemove.pickyCustomResize', function(ev){
            var curLayoutResize = pickyPageXYToCanvasLocal($canvas, ev.pageX, ev.pageY);
            var newW = startW;
            var newH = startH;
            var newL = startL;
            var newT = startT;
            /* Corners: default = free width/height (local axes). Shift = uniform scale.
               Text: Figma/Canva-style — corners only, always uniform (diagonal) scale. */
            var useProportionalCorner = !!(cr && d0Corner > 1e-3 && ($el.hasClass('type-text') || ev.shiftKey));

            if(useProportionalCorner){
              /* Uniform scale, opposite corner fixed; pointer distance vs initial along drag. */
              var m0x = startL + oM0.x;
              var m0y = startT + oM0.y;
              var ux = m0x - anchorCanvas.x;
              var uy = m0y - anchorCanvas.y;
              var vx = curLayoutResize.x - anchorCanvas.x;
              var vy = curLayoutResize.y - anchorCanvas.y;
              var denom = ux * ux + uy * uy;
              var lenU = Math.sqrt(denom);
              var lenV = Math.hypot(vx, vy);
              var dotvu = vx * ux + vy * uy;
              var sMin = Math.max(20 / startW, 20 / startH);
              var s;
              if(lenU < 1e-6){
                s = 1;
              } else if(dotvu < -1e-8 * denom){
                /* Pointer past the anchor on the wrong side of the diagonal — avoid runaway scale. */
                s = sMin;
              } else {
                s = Math.max(sMin, lenV / lenU);
              }
              newW = startW * s;
              newH = startH * s;
              var oA2 = pickyResizeRotatedCornerInElement(newW, newH, originL, originT, angle, cr.anchor[0], cr.anchor[1]);
              newL = anchorCanvas.x - oA2.x;
              newT = anchorCanvas.y - oA2.y;
              /* Keep visual AABB inside canvas without breaking anchor: shrink scale only (no nudge). */
              var sCap = s;
              for(var capIter = 0; capIter < 55; capIter++){
                var vBBc = getVisualBoundingBox($el, newW, newH);
                var vOffLc = (newW - vBBc.width) / 2;
                var vOffTc = (newH - vBBc.height) / 2;
                var bL = newL + vOffLc + originL;
                var bT = newT + vOffTc + originT;
                var bR = bL + vBBc.width;
                var bB = bT + vBBc.height;
                if(bL >= -1.5 && bT >= -1.5 && bR <= canvasW + 1.5 && bB <= canvasH + 1.5){
                  break;
                }
                sCap *= 0.9;
                if(sCap < sMin){
                  sCap = sMin;
                  newW = startW * sCap;
                  newH = startH * sCap;
                  oA2 = pickyResizeRotatedCornerInElement(newW, newH, originL, originT, angle, cr.anchor[0], cr.anchor[1]);
                  newL = anchorCanvas.x - oA2.x;
                  newT = anchorCanvas.y - oA2.y;
                  break;
                }
                newW = startW * sCap;
                newH = startH * sCap;
                oA2 = pickyResizeRotatedCornerInElement(newW, newH, originL, originT, angle, cr.anchor[0], cr.anchor[1]);
                newL = anchorCanvas.x - oA2.x;
                newT = anchorCanvas.y - oA2.y;
              }
            } else {
            var dx = curLayoutResize.x - startLayoutResize.x;
            var dy = curLayoutResize.y - startLayoutResize.y;
            
            // Rotate the mouse delta to local coordinate space (edges follow rotated sides)
            var ldx = dx * cos + dy * sin;
            var ldy = -dx * sin + dy * cos;
            
            /* Size only here; left/top come from fixed opposite corner (corners) or opposite edge
             * midpoint (n/e/s/w) so rotated resize matches Canva (no spurious slide). */
            if(dir.indexOf('e') !== -1) newW += ldx;
            if(dir.indexOf('w') !== -1) newW -= ldx;
            if(dir.indexOf('s') !== -1) newH += ldy;
            if(dir.indexOf('n') !== -1) newH -= ldy;
            
            newW = Math.max(20, newW);
            newH = Math.max(20, newH);

            if(cr){
              var oA2s = pickyResizeRotatedCornerInElement(newW, newH, originL, originT, angle, cr.anchor[0], cr.anchor[1]);
              newL = anchorCanvas.x - oA2s.x;
              newT = anchorCanvas.y - oA2s.y;
              for(var si = 0; si < 55; si++){
                var vBBs = getVisualBoundingBox($el, newW, newH);
                var vOffLs = (newW - vBBs.width) / 2;
                var vOffTs = (newH - vBBs.height) / 2;
                var sL = newL + vOffLs + originL;
                var sT = newT + vOffTs + originT;
                var sR = sL + vBBs.width;
                var sB = sT + vBBs.height;
                if(sL >= -1.5 && sT >= -1.5 && sR <= canvasW + 1.5 && sB <= canvasH + 1.5){ break; }
                newW = Math.max(20, newW * 0.9);
                newH = Math.max(20, newH * 0.9);
                oA2s = pickyResizeRotatedCornerInElement(newW, newH, originL, originT, angle, cr.anchor[0], cr.anchor[1]);
                newL = anchorCanvas.x - oA2s.x;
                newT = anchorCanvas.y - oA2s.y;
              }
            } else if(anchorCanvas){
              var oE = pickyResizeOppositeEdgeAnchorLocal(newW, newH, originL, originT, angle, dir);
              newL = anchorCanvas.x - oE.x;
              newT = anchorCanvas.y - oE.y;
              for(var ei = 0; ei < 55; ei++){
                var vBBc = getVisualBoundingBox($el, newW, newH);
                var vOffLc = (newW - vBBc.width) / 2;
                var vOffTc = (newH - vBBc.height) / 2;
                var bL = newL + vOffLc + originL;
                var bT = newT + vOffTc + originT;
                var bR = bL + vBBc.width;
                var bB = bT + vBBc.height;
                if(bL >= -1.5 && bT >= -1.5 && bR <= canvasW + 1.5 && bB <= canvasH + 1.5){ break; }
                if(dir === 'n' || dir === 's'){
                  newH = Math.max(20, newH * 0.9);
                } else if(dir === 'e' || dir === 'w'){
                  newW = Math.max(20, newW * 0.9);
                } else { break; }
                oE = pickyResizeOppositeEdgeAnchorLocal(newW, newH, originL, originT, angle, dir);
                newL = anchorCanvas.x - oE.x;
                newT = anchorCanvas.y - oE.y;
              }
            }
            }

            // Final sync
            newW = Math.max(20, newW);
            newH = Math.max(20, newH);

            if(startFontForTextResize > 0 && startW > 0 && startH > 0){
              var areaRatio = (newW / startW) * (newH / startH);
              var rFont = areaRatio > 0 ? Math.sqrt(areaRatio) : 1;
              if(!isFinite(rFont) || rFont <= 0){ rFont = 1; }
              var nfs = Math.max(8, Math.min(200, Math.round(startFontForTextResize * rFont)));
              $el.attr('data-font-size', String(nfs));
              pickySyncTextFontSizeInputFromElement($el);
            }

            /* Text: font-size only on the wrap, but same anchor as shapes — opposite corner
             * fixed in canvas; position from measured content box after layout. */
            if($el.hasClass('type-text')){
              $tw.css({ width: '', height: '', left: '', top: '' });
              updateTextPreview($el);
              if(cr && anchorCanvas){
                var w2 = parseFloat($tw.outerWidth()) || 20;
                var h2 = parseFloat($tw.outerHeight()) || 20;
                var oA2t = pickyResizeRotatedCornerInElement(w2, h2, originL, originT, angle, cr.anchor[0], cr.anchor[1]);
                newL = anchorCanvas.x - oA2t.x;
                newT = anchorCanvas.y - oA2t.y;
              }
              $el.css({ left: newL + 'px', top: newT + 'px' });
              updateInteractiveHandlesPosition($el);
              pushIntoCanvas($el, true);
            } else {
              $tw.css({ width: newW + 'px', height: newH + 'px', left: 0, top: 0 });
              $el.css({ left: newL + 'px', top: newT + 'px' });
              updateInteractiveHandlesPosition($el);
              updateTextPreview($el);
            }
        });
        
        $(document).on('mouseup.pickyCustomResize', function(){
            $(document).off('mousemove.pickyCustomResize mouseup.pickyCustomResize');
            $el.removeData('picky-text-resize-skip-push');
            if($el.hasClass('ui-draggable')) {
                try{ $el.draggable('enable'); } catch(ex){}
            }
            pickySetInlineCanvasToolsVisibleForElement($el[0], true);
            pushIntoCanvas($el, true);
            if(typeof takeSnapshot === 'function') takeSnapshot();
        });
      });
    }
  }


  // Wall: rotate +90° via free angle (same as bookable click-to-flip).
  function pickyRotateWall(element){
    if(!element || !element.length){ return; }
    var startAngle = parseInt(element.attr('data-rotate-z') || '0', 10);
    if(isNaN(startAngle)){ startAngle = 0; }
    var next = (startAngle + 90) % 360;
    if(next < 0){ next += 360; }
    element.attr('data-rotate-z', String(next));
    applyTransforms(element);
    pushIntoCanvas(element, true);
    pickyInitWallHandles(element);
    updateInteractiveHandlesPosition(element);
    if(typeof pickyPositionInlineCanvasTools === 'function'){
      pickyPositionInlineCanvasTools(element);
    }
    if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
  }

  // Wall handles — inject two grip bars at both ends + centered control bar
  function pickyInitWallHandles(element){
    element.find('.picky-wall-hdl').remove();
    element.find('.picky-wall-ctrl').remove();
    var $tw = element.find('.picky-element-table-wrap');
    var w = $tw.outerWidth() || parseInt($tw.css('width')) || 120;
    var h = $tw.outerHeight() || parseInt($tw.css('height')) || 10;
    var isH = w >= h;
    element.toggleClass('picky-wall-h', isH).toggleClass('picky-wall-v', !isH);

    // Add classes if near canvas edges so CSS can prevent controls from clipping outside
    var $canvas = element.closest('.picky-draggable');
    if ($canvas.length) {
      var er = element[0].getBoundingClientRect();
      var cr = $canvas[0].getBoundingClientRect();
      var relTop = er.top - cr.top;
      var relRight = cr.right - er.right;
      
      // If within 40px of top edge, flip horizontal controls below the wall
      element.toggleClass('picky-wall-near-top', relTop < 40);
      
      // If within 40px of right edge, flip vertical controls to the left of the wall
      element.toggleClass('picky-wall-near-right', relRight < 40);
    }

    $tw.append('<div class="picky-wall-hdl picky-wall-hdl-a"></div><div class="picky-wall-hdl picky-wall-hdl-b"></div>');
    // Centered hover control bar injected into table-wrap so it centers on the visible wall shape
    $tw.append('<div class="picky-wall-ctrl"><div class="picky-wall-ctrl-rotate" title="Drag to rotate"></div></div>');
  }

  // Delegated resize handler for wall handles
  $(document).on('mousedown', '.picky-draggable .picky-element.type-wall .picky-wall-hdl', function(e){
    e.stopPropagation();
    e.preventDefault();
    var isA = $(this).hasClass('picky-wall-hdl-a');
    var $el = $(this).closest('.picky-element');
    var $canvas = $el.closest('.picky-draggable');
    var $tw = $el.find('.picky-element-table-wrap');
    var startLayout = pickyPageXYToCanvasLocal($canvas, e.pageX, e.pageY);
    var startW = parseFloat($tw.outerWidth()) || 120;
    var startH = parseFloat($tw.outerHeight()) || 10;
    var startLeft = parseFloat($el.css('left')) || 0;
    var startTop = parseFloat($el.css('top')) || 0;
    var isHoriz = $el.hasClass('picky-wall-h');
    var zDeg = parseInt($el.attr('data-rotate-z') || '0', 10);
    if(isNaN(zDeg)){ zDeg = 0; }
    var zRad = zDeg * Math.PI / 180;
    var zCos = Math.cos(zRad);
    var zSin = Math.sin(zRad);

    /* Wrap origin in element coords (padding + position) — same as bookable resize. */
    var pad = pickyElementLayoutPadLeft($el);
    var padT = pickyElementLayoutPadTop($el);
    var twPos0 = $tw.position();
    var twPL = twPos0 && !isNaN(twPos0.left) ? twPos0.left : 0;
    var twPT = twPos0 && !isNaN(twPos0.top) ? twPos0.top : 0;
    var originL = pad + twPL;
    var originT = padT + twPT;

    /*
     * Free rotateZ grows the wrap around its center. Keep the OPPOSITE end fixed
     * in canvas space (same idea as corner resize for bookables), otherwise the
     * whole wall appears to slide while you drag a length handle.
     */
    var fixedLocal0 = isHoriz
      ? (isA
          ? pickyResizeRotatedLocalPointInElement(startW, startH, originL, originT, zDeg, originL + startW, originT + startH / 2)
          : pickyResizeRotatedLocalPointInElement(startW, startH, originL, originT, zDeg, originL, originT + startH / 2))
      : (isA
          ? pickyResizeRotatedLocalPointInElement(startW, startH, originL, originT, zDeg, originL + startW / 2, originT + startH)
          : pickyResizeRotatedLocalPointInElement(startW, startH, originL, originT, zDeg, originL + startW / 2, originT));
    var anchorCanvas = { x: startLeft + fixedLocal0.x, y: startTop + fixedLocal0.y };

    var canvasW = parseFloat($canvas.outerWidth()) || 0;
    var canvasH = parseFloat($canvas.outerHeight()) || 0;

    $el.addClass('picky-wall-resizing');
    pickySetInlineCanvasToolsVisibleForElement($el[0], false);
    if($el.hasClass('ui-draggable')){
      try{ $el.draggable('disable'); } catch(exDis){}
    }

    function onMove(ev){
      var curLayout = pickyPageXYToCanvasLocal($canvas, ev.pageX, ev.pageY);
      var dx = curLayout.x - startLayout.x;
      var dy = curLayout.y - startLayout.y;
      /* Project screen delta into wall-local axes. */
      var localDx = dx * zCos + dy * zSin;
      var localDy = -dx * zSin + dy * zCos;

      var newW = startW;
      var newH = startH;
      if(isHoriz){
        newW = Math.max(10, isA ? (startW - localDx) : (startW + localDx));
        newH = startH;
      } else {
        newW = startW;
        newH = Math.max(10, isA ? (startH - localDy) : (startH + localDy));
      }

      var fixedLocalN = isHoriz
        ? (isA
            ? pickyResizeRotatedLocalPointInElement(newW, newH, originL, originT, zDeg, originL + newW, originT + newH / 2)
            : pickyResizeRotatedLocalPointInElement(newW, newH, originL, originT, zDeg, originL, originT + newH / 2))
        : (isA
            ? pickyResizeRotatedLocalPointInElement(newW, newH, originL, originT, zDeg, originL + newW / 2, originT + newH)
            : pickyResizeRotatedLocalPointInElement(newW, newH, originL, originT, zDeg, originL + newW / 2, originT));

      var newLeft = anchorCanvas.x - fixedLocalN.x;
      var newTop = anchorCanvas.y - fixedLocalN.y;

      /* Soft canvas clamp via visual AABB (do not break opposite-end anchor hard). */
      var vBB = getVisualBoundingBox($el, newW, newH);
      var vOffL = (newW - vBB.width) / 2;
      var vOffT = (newH - vBB.height) / 2;
      var bL = newLeft + originL + vOffL;
      var bT = newTop + originT + vOffT;
      var bR = bL + vBB.width;
      var bB = bT + vBB.height;
      if(bL < 0){ newLeft -= bL; }
      if(bT < 0){ newTop -= bT; }
      if(bR > canvasW){ newLeft -= (bR - canvasW); }
      if(bB > canvasH){ newTop -= (bB - canvasH); }

      $tw.css({ width: newW + 'px', height: newH + 'px' });
      $el.css({ left: newLeft + 'px', top: newTop + 'px' });
    }
    function onUp(){
      $el.removeClass('picky-wall-resizing');
      if($el.hasClass('ui-draggable')){
        try{ $el.draggable('enable'); } catch(exEn){}
      }
      pickySetInlineCanvasToolsVisibleForElement($el[0], true);
      applyTransforms($el);
      pickyInitWallHandles($el);
      $el.find('.picky-element-edit-thickness-input').val(
        Math.round(Math.min($tw.outerWidth() || 0, $tw.outerHeight() || 0))
      );
      $(document).off('mousemove.wallresize mouseup.wallresize');
      if(typeof takeSnapshot === 'function'){ takeSnapshot(); }
    }
    $(document).on('mousemove.wallresize', onMove).on('mouseup.wallresize', onUp);
  });

  function normalizeOpacity(raw){
    var val = parseFloat(raw);
    if(isNaN(val)){ val = 1; }
    if(val < 0){ val = 0; }
    if(val > 1){ val = 1; }
    return Math.round(val * 100) / 100;
  }

  function applyElementOpacity(element){
    var opacity = normalizeOpacity(element.attr('data-opacity'));
    element.attr('data-opacity', opacity);
    element.find('.picky-element-shape').css('opacity', opacity);
  }

  // (Scale / Stretch buttons removed)

  /* Close element options on click outside the inspector toolbar. */
  $(document).on('mousedown', function(e){
    var $target = $(e.target);
    var $opened = $('.picky-draggable .picky-element.opened').first();
    if(!$opened.length){ return; }

    /* Keep open inside the docked inspector + floating UI + on-canvas tool strip.
       (Delete/duplicate live on the canvas strip — closing on mousedown here
       used to clear the target before the click handler ran.) */
    var isInsideToolbar = $target.closest(
      '#picky-builder-toolbar, #picky-element-inspector, ' +
      '.picky-element-edit-tools.picky-inline-tools-on-canvas, ' +
      '.picky-element-inline-close.picky-inline-close-on-canvas, ' +
      '.picky-services-popup, ' +
      '.picky-element-edit-opacity-popup, ' +
      '.wp-picker-holder, .iris-picker, .picky-element-color-picker-wrap, ' +
      'body > .picky-element-color-picker-wrap'
    ).length > 0;
    if(isInsideToolbar){ return; }

    /* Any canvas element click (incl. Shift+multi-select) is handled by the
       element click handler — do not close on mousedown or multi-select resets. */
    if($target.closest('.picky-draggable .picky-element, .picky-custom-resizer, .ui-resizable-handle, .picky-rotate-handle, .picky-3d-handle, .picky-3d-pad, .picky-wall-hdl, .picky-wall-ctrl-rotate, .picky-ms-transform-box').length){ return; }

    /* Zoom / undo must not be swallowed by editor close on mousedown. */
    if(
      $target.closest('.picky-canvas-controls, .picky-canvas-controls--overlay, .picky-zoom-controls').length ||
      $target.closest('button').filter('#picky-undo, #picky-zoom-in, #picky-zoom-out, #picky-zoom-reset').length
    ){
      return;
    }

    pickyCloseElementEditor();
  });

  // Plus seats
  $(document).on('click', '.picky-draggable .picky-element .picky-element-edit-seats-plus', function(){
    var element = $(this).closest('.picky-element');
    var editor = element.find('.picky-element-edit-wrap');
    var current = $(this).closest('.picky-element-edit-seats').find('.picky-element-edit-seats-input').text();
    var max = element.attr('data-max-seats');
    var rotate = element.attr('data-rotate');
    current = parseInt(current);
    var type = element.attr('data-type');
    // Universal table (max=0 / type-6): allow changing guests count (no seats rendering)
    if(type == 'type-6' || parseInt(max,10) === 0){
      if(current+1 <= 99){
        element.attr('data-seats', current+1);
        $(this).closest('.picky-element-edit-seats').find('.picky-element-edit-seats-input').text(current+1);
        element.find('.picky-element-seats').text(current+1);
      }
    } else {
      // Elements are universal now: allow changing center number regardless of max persons
      if(current+1 <= 99){
        element.attr('data-seats', current+1);
        $(this).closest('.picky-element-edit-seats').find('.picky-element-edit-seats-input').text(current+1);
        element.find('.picky-element-seats').text(current+1);
        // Seats rendering removed: keep elements universal, only update center number
      }
    }
  });

  // Minus seats
  $(document).on('click', '.picky-draggable .picky-element .picky-element-edit-seats-minus', function(){
    var element = $(this).closest('.picky-element');
    var editor = element.find('.picky-element-edit-wrap');
    var current = $(this).closest('.picky-element-edit-seats').find('.picky-element-edit-seats-input').text();
    var max = element.attr('data-max-seats');
    var rotate = element.attr('data-rotate');
    current = parseInt(current);
    var type = element.attr('data-type');
    if(type == 'type-6' || parseInt(max,10) === 0){
      if(current-1 >= 1){
        element.attr('data-seats', current-1);
        $(this).closest('.picky-element-edit-seats').find('.picky-element-edit-seats-input').text(current-1);
        element.find('.picky-element-seats').text(current-1);
      }
    } else {
      if(current-1 >= 1){
        element.attr('data-seats', current-1);
        $(this).closest('.picky-element-edit-seats').find('.picky-element-edit-seats-input').text(current-1);
        element.find('.picky-element-seats').text(current-1);
        // Seats rendering removed: keep elements universal, only update center number
      }
    }
  });

  // (Numeric-only ID handler removed)

  // Rotate / 3D mode toggles (drag handles, not presets)
  $(document).on('click', '.picky-element-edit-rotate', function(e){
    e.preventDefault();
    e.stopPropagation();
    var element = getEditorTarget(this);
    element.toggleClass('picky-rotate-mode');
    if(element.hasClass('picky-rotate-mode')){
      element.removeClass('picky-3d-mode');
    }
    /* Always recompute anchor side when rotate UI is opened/closed so the
       handle never reuses stale placement from an older interaction. */
    updateInteractiveHandlesPosition(element);
    applyTransforms(element);
  });

  $(document).on('click', '.picky-element-edit-3d', function(e){
    e.preventDefault();
    e.stopPropagation();
    var element = getEditorTarget(this);
    var $editorWrap = $(this).closest('.picky-element-edit-wrap');
    
    element.toggleClass('picky-3d-mode');
    $editorWrap.toggleClass('picky-3d-mode-active');
    
    if(element.hasClass('picky-3d-mode')){
      element.removeClass('picky-rotate-mode');
    }
    updateInteractiveHandlesPosition(element);
    if(typeof pickyPositionInlineCanvasTools === 'function'){
      pickyPositionInlineCanvasTools(element);
    }
  });

  /** Legacy markup had rotate handle / 3D pad outside .picky-element-table; move
   *  them inside so they share 2D rotation with the shape while overlay stays flat. */
  function pickyNormalizeTableRotateDom($root){
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

  function applyTransforms(element){
    var $el = $(element).closest('.picky-element');
    if(!$el.length){ $el = $(element); }
    updateInteractiveHandlesPosition($el);
    var z = parseInt($el.attr('data-rotate-z') || '0', 10); if(isNaN(z)) z = 0;
    var rx = parseInt($el.attr('data-rotate-x') || '0', 10); if(isNaN(rx)) rx = 0;
    var ry = parseInt($el.attr('data-rotate-y') || '0', 10); if(isNaN(ry)) ry = 0;
    
    // 1. Outer element stays unrotated in Z (layout left/top stay axis-aligned).
    $el.css({
      'transform': 'none',
      'transform-origin': ''
    });
    var $tw = $el.find('.picky-element-table-wrap').first();
    var $table = $el.find('.picky-element-table').first();
    var $overlay = $el.find('.picky-element-overlay').first();
    var useWrapRotation = !!$tw.length;
    if(useWrapRotation){
      // Rotate the whole wrap so resize handles (children of wrap) track the shape.
      $tw.css({
        transform: 'rotateZ(' + z + 'deg)',
        transformOrigin: 'center center'
      });
      if($table.length){
        $table.css({ transform: 'none', transformOrigin: '' });
      }
      if($overlay.length){
        if($el.hasClass('type-shape') || $el.hasClass('type-icon') || $el.hasClass('type-text') || $el.hasClass('type-wall')){
          /* Do NOT counter-rotate the full overlay — a full-size counter-rotated
             layer reads as a horizontal “ghost” plate behind a rotated shape/text.
             Keep only the edit control upright. */
          $overlay.css({ transform: '', transformOrigin: '' });
          $overlay.find('.picky-element-edit').css({
            transform: z ? ('rotateZ(' + (-z) + 'deg)') : ''
          });
        } else {
          // Bookables: counter-rotate overlay so ID / label / seats stay upright.
          $overlay.css({
            transform: 'rotateZ(' + (-z) + 'deg)',
            transformOrigin: 'center center'
          });
          $overlay.find('.picky-element-edit').css({ transform: '' });
        }
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
          transform: 'rotateZ(' + z + 'deg)',
          transformOrigin: 'center center'
        });
      }
    }
    
    // 3. Apply the 3D tilt ONLY to the visual shape.
    var $shape = $el.find('.picky-element-shape');
    var shapeCss = {
      transform: 'perspective(1000px) rotateX('+rx+'deg) rotateY('+ry+'deg)'
    };
    if($el.hasClass('type-shape')){
      var shapeType = ($el.attr('data-shape-type') || 'rectangle').toString();
      var radRaw = ($el.attr('data-shape-radius') || '').toString().trim();
      var rad = parseInt(radRaw, 10);
      if(shapeType === 'circle'){
        shapeCss.borderRadius = '50%';
      } else if(shapeType === 'triangle'){
        shapeCss.borderRadius = '0';
      } else if(radRaw !== '' && !isNaN(rad) && rad >= 0){
        if(rad > 120){ rad = 120; }
        shapeCss.borderRadius = rad + 'px';
      } else if(shapeType === 'rounded'){
        shapeCss.borderRadius = '15px';
      } else if(shapeType === 'rectangle'){
        shapeCss.borderRadius = '0';
      } else {
        shapeCss.borderRadius = '';
      }
      var bw = parseInt($el.attr('data-shape-border-width') || '0', 10);
      if(isNaN(bw) || bw < 0){ bw = 0; }
      if(bw > 0){
        var bc = normalizeHexColor($el.attr('data-shape-border-color') || '', '#333333');
        shapeCss.border = bw + 'px solid ' + bc;
        shapeCss.boxSizing = 'border-box';
      } else {
        shapeCss.border = '';
        shapeCss.boxSizing = '';
      }
    } else if($el.hasClass('type-wall')){
      var wrRaw = ($el.attr('data-wall-radius') || '').toString().trim();
      var wr = parseInt(wrRaw, 10);
      // Empty = CSS default (2px). Explicit 0 = sharp. 1–120 = override.
      if(wrRaw !== '' && !isNaN(wr) && wr >= 0){
        if(wr > 120){ wr = 120; }
        shapeCss.borderRadius = wr + 'px';
        $shape.css('--picky-wall-radius', wr + 'px');
      } else {
        shapeCss.borderRadius = '';
        if($shape.length && $shape[0] && $shape[0].style){
          $shape[0].style.removeProperty('--picky-wall-radius');
        }
      }
    } else if(pickyUsesBookableSquareRadius($el)){
      var sqRaw = ($el.attr('data-square-radius') || '').toString().trim();
      var sq = parseInt(sqRaw, 10);
      // Empty or 0 = CSS default (8px). Only positive values override.
      if(sqRaw !== '' && !isNaN(sq) && sq > 0){
        if(sq > 120){ sq = 120; }
        shapeCss.borderRadius = sq + 'px';
      } else {
        shapeCss.borderRadius = '';
        if(sqRaw === '0'){ $el.removeAttr('data-square-radius'); }
      }
    }
    $shape.css(shapeCss);
    
    /* Strip legacy counter-rotates from overlay + resizers (no longer needed). */
    $el.find('.picky-element-id, .picky-element-seats').css('transform', 'translate(-50%, -50%)');
    $el.find('.picky-element-label, .picky-custom-resizer').css('transform', '');
    if(!$el.hasClass('type-shape') && !$el.hasClass('type-icon') && !$el.hasClass('type-text') && !$el.hasClass('type-wall')){
      $el.find('.picky-element-edit').css('transform', '');
    }
    $el.find('.picky-rotate-handle, .picky-3d-pad').css('transform', '');
    /* Do not clear the canvas tool-strip transform — it needs translateX(-50%)
       for centering; wiping it left the bar misaligned over the item. */
    pickyPositionInlineCanvasTools($el);
  }

  function pushIntoCanvas(element, noAnim){
    var $canvas = element.closest('.picky-draggable');
    if(!$canvas.length) return;
    var $tw = element.find('.picky-element-table-wrap');
    var pad = pickyElementLayoutPadLeft(element);
    var canvasW = parseFloat($canvas.outerWidth());
    var canvasH = parseFloat($canvas.outerHeight());
    var twW = parseFloat($tw.outerWidth());
    var twH = parseFloat($tw.outerHeight());

    var curL = parseFloat(element.css('left')) || 0;
    var curT = parseFloat(element.css('top')) || 0;
    var minL, maxL, minT, maxT;

    var vBB = getVisualBoundingBox(element, twW, twH);
    var bbW = vBB.width;
    var bbH = vBB.height;

    // Visual offset from wrapper edge due to rotation centering
    var vOffsetL = (twW - bbW) / 2;
    var vOffsetT = (twH - bbH) / 2;

    minL = -vOffsetL - pad;
    maxL = canvasW - bbW - vOffsetL - pad;
    minT = -vOffsetT - pad;
    maxT = canvasH - bbH - vOffsetT - pad;

    var newL = Math.max(minL, Math.min(curL, maxL));
    var newT = Math.max(minT, Math.min(curT, maxT));

    if(Math.abs(newL - curL) > 0.1 || Math.abs(newT - curT) > 0.1){
      if(noAnim){
        element.css({ left: newL + 'px', top: newT + 'px' });
      } else {
        element.animate({ left: newL, top: newT }, 200, 'swing');
      }
    }
  }

  function pickyEnsureCanvasAffMarkers($canvas){
    var el = $canvas && $canvas[0];
    if(!el || el.getAttribute('data-picky-aff-m') === '1'){ return; }
    var base = 'position:absolute;width:1px;height:1px;margin:0;padding:0;border:0;pointer-events:none;visibility:hidden;z-index:-1';
    var names = ['v', 'w', 'h'];
    for(var i = 0; i < names.length; i++){
      var sp = document.createElement('span');
      sp.className = 'picky-canvas-aff-marker picky-canvas-aff-' + names[i];
      sp.setAttribute('aria-hidden', 'true');
      sp.style.cssText = base;
      el.insertBefore(sp, el.firstChild);
    }
    el.setAttribute('data-picky-aff-m', '1');
  }

  function pickySyncCanvasAffMarkerPositions($canvas){
    var el = $canvas && $canvas[0];
    if(!el){ return; }
    pickyEnsureCanvasAffMarkers($canvas);
    var W = el.offsetWidth || 1;
    var H = el.offsetHeight || 1;
    var mv = el.querySelector('.picky-canvas-aff-v');
    var mw = el.querySelector('.picky-canvas-aff-w');
    var mh = el.querySelector('.picky-canvas-aff-h');
    if(mv){ mv.style.left = '0px'; mv.style.top = '0px'; }
    if(mw){ mw.style.left = W + 'px'; mw.style.top = '0px'; }
    if(mh){ mh.style.left = '0px'; mh.style.top = H + 'px'; }
  }

  /**
   * Pointer (pageX/pageY) → coordinates in .picky-draggable layout space (same as CSS left/top).
   * Uses three transformed reference points so pan/zoom + transform-origin:center map correctly
   * (bounding-rect proportion alone is wrong when the origin is not top-left).
   */
  function pickyPageXYToCanvasLocal($canvas, pageX, pageY){
    var c = $canvas && $canvas[0];
    if(!c){ return { x: 0, y: 0 }; }
    pickySyncCanvasAffMarkerPositions($canvas);
    var mv = c.querySelector('.picky-canvas-aff-v');
    var mw = c.querySelector('.picky-canvas-aff-w');
    var mh = c.querySelector('.picky-canvas-aff-h');
    var W = c.offsetWidth || 1;
    var H = c.offsetHeight || 1;
    var clientX = pageX - (window.pageXOffset != null ? window.pageXOffset : document.documentElement.scrollLeft);
    var clientY = pageY - (window.pageYOffset != null ? window.pageYOffset : document.documentElement.scrollTop);
    if(!mv || !mw || !mh){
      var rect = c.getBoundingClientRect();
      var rw = rect.width || 1;
      var rh = rect.height || 1;
      return {
        x: ((clientX - rect.left) / rw) * W,
        y: ((clientY - rect.top) / rh) * H
      };
    }
    var r0 = mv.getBoundingClientRect();
    var rW = mw.getBoundingClientRect();
    var rH = mh.getBoundingClientRect();
    var sx = clientX - r0.left;
    var sy = clientY - r0.top;
    var ax = (rW.left - r0.left) / W;
    var ay = (rW.top - r0.top) / W;
    var bx = (rH.left - r0.left) / H;
    var by = (rH.top - r0.top) / H;
    var det = ax * by - ay * bx;
    if(Math.abs(det) < 1e-10){
      var rect2 = c.getBoundingClientRect();
      var rw2 = rect2.width || 1;
      var rh2 = rect2.height || 1;
      return {
        x: ((clientX - rect2.left) / rw2) * W,
        y: ((clientY - rect2.top) / rh2) * H
      };
    }
    return {
      x: (sx * by - sy * bx) / det,
      y: (ax * sy - ay * sx) / det
    };
  }

  /** Page-space axis-aligned rect → tight AABB in canvas layout (handles pan/zoom skew). */
  function pickyPageRectToCanvasLocalAABB($canvas, pageLeft, pageTop, w, h){
    w = parseFloat(w);
    h = parseFloat(h);
    if(!$canvas || !$canvas.length || !isFinite(w) || !isFinite(h) || w <= 0 || h <= 0){
      var p0 = pickyPageXYToCanvasLocal($canvas, pageLeft, pageTop);
      return { x: p0.x, y: p0.y, x1: p0.x, y1: p0.y };
    }
    var p1 = pickyPageXYToCanvasLocal($canvas, pageLeft, pageTop);
    var p2 = pickyPageXYToCanvasLocal($canvas, pageLeft + w, pageTop);
    var p3 = pickyPageXYToCanvasLocal($canvas, pageLeft, pageTop + h);
    var p4 = pickyPageXYToCanvasLocal($canvas, pageLeft + w, pageTop + h);
    var minX = Math.min(p1.x, p2.x, p3.x, p4.x);
    var maxX = Math.max(p1.x, p2.x, p3.x, p4.x);
    var minY = Math.min(p1.y, p2.y, p3.y, p4.y);
    var maxY = Math.max(p1.y, p2.y, p3.y, p4.y);
    return { x: minX, y: minY, x1: maxX, y1: maxY };
  }

  /** Recompute jQuery UI draggable click offset from the real mousedown (see `distance` option). */
  function pickyFixDraggableStartOffset($el){
    var md = $el.data('picky-drag-mousedown-page');
    var inst = $el.data('ui-draggable');
    if(!md || !inst || typeof inst._refreshOffsets !== 'function'){ return; }
    inst._refreshOffsets({ pageX: md.x, pageY: md.y });
    inst.originalPageX = md.x;
    inst.originalPageY = md.y;
  }

  function pickyStoreCanvasDragGrab($el, $canvas, pageX, pageY){
    var local = pickyPageXYToCanvasLocal($canvas, pageX, pageY);
    var posL = parseFloat($el.css('left'));
    var posT = parseFloat($el.css('top'));
    if(isNaN(posL)){ posL = 0; }
    if(isNaN(posT)){ posT = 0; }
    $el.data('picky-canvas-drag-grab', { x: local.x - posL, y: local.y - posT });
  }

  function pickyApplyCanvasDragFromMouse($el, $canvas, pageX, pageY, ui){
    var local = pickyPageXYToCanvasLocal($canvas, pageX, pageY);
    var grab = $el.data('picky-canvas-drag-grab');
    if(!grab){ grab = { x: 0, y: 0 }; }
    ui.position.left = Math.round(local.x - grab.x);
    ui.position.top = Math.round(local.y - grab.y);
  }

  /* With draggable `distance`, `start` runs on the first mousemove past the
     threshold — not on mousedown. Grab must use the real press point or the
     element jumps (especially when grabbing away from the top-left). */
  $(document).on('mousedown', '.picky-draggable .picky-element', function(e){
    if(e.which !== 1) return;
    if($(e.target).closest('.picky-element-delete, .picky-element-duplicate, .picky-element-layer-front, .picky-element-layer-back, .picky-element-inline-close, .picky-element-edit-wrap, .picky-element-edit, .ui-resizable-handle, .picky-rotate-handle, .picky-3d-handle, .picky-3d-pad, .picky-wall-hdl, .picky-wall-ctrl-rotate, .picky-custom-resizer').length){
      return;
    }
    var $el = $(this);
    var $canvas = $el.closest('.picky-draggable');
    if(!$canvas.length) return;
    $el.data('picky-drag-mousedown-page', { x: e.pageX, y: e.pageY });
    pickyStoreCanvasDragGrab($el, $canvas, e.pageX, e.pageY);
  });

  // --- Canvas pan/zoom (Photoshop-like) ---
  var pickyZoom = 1;
  var pickyPanX = 0;
  var pickyPanY = 0;
  var isPanning = false;
  var panStart = { x: 0, y: 0, px: 0, py: 0 };

  function applyCanvasTransform(){
    /* Scale around the canvas center (not top-left) so +/- zoom feels centered. */
    $('.picky-draggable').css({
      transform: 'translate(' + pickyPanX + 'px,' + pickyPanY + 'px) scale(' + pickyZoom + ')',
      'transform-origin': 'center center'
    });
    $('.picky-draggable').each(function(){
      pickySyncCanvasAffMarkerPositions($(this));
    });
    var pct = Math.round(pickyZoom * 100);
    $('#picky-zoom-reset').text(pct + '%');
    pickyScheduleInspectorSpotlightUpdate();
  }
  function clampZoom(z){
    return Math.max(0.3, Math.min(2.5, z));
  }

  $(document).on('click', '#picky-zoom-in', function(e){
    e.preventDefault();
    pickyZoom = clampZoom(pickyZoom + 0.1);
    applyCanvasTransform();
  });
  $(document).on('click', '#picky-zoom-out', function(e){
    e.preventDefault();
    pickyZoom = clampZoom(pickyZoom - 0.1);
    applyCanvasTransform();
  });
  $(document).on('click', '#picky-zoom-reset', function(e){
    e.preventDefault();
    pickyZoom = 1;
    pickyPanX = 0;
    pickyPanY = 0;
    applyCanvasTransform();
  });

  // Spacebar enables pan mode (optional)
  $(document).on('keydown', function(e){
    if(e.code === 'Space'){
      $('.picky-draggable-wrap').addClass('picky-pan-active');
    }
  });
  $(document).on('keyup', function(e){
    if(e.code === 'Space'){
      $('.picky-draggable-wrap').removeClass('picky-pan-active picky-panning');
      isPanning = false;
    }
  });

  $(document).on('mousedown', '.picky-draggable-wrap', function(e){
    /* Always skip pan grab when interacting with zoom/undo chrome (fixes lost clicks when Space-pan is active). */
    if($(e.target).closest('.picky-canvas-controls, .picky-canvas-controls--overlay').length){ return; }

    // Allow drag-pan on empty canvas background OR when space is held
    var panEnabled = $('.picky-draggable-wrap').hasClass('picky-pan-active');
    var isEmptyBackground = $(e.target).closest('.picky-element, .ui-resizable-handle, .picky-custom-resizer, .picky-rotate-handle, .picky-3d-handle, .picky-element-edit-wrap, .picky-canvas-controls').length === 0;
    if(isEmptyBackground && !e.shiftKey){
      pickyClearMultiSelect();
    }
    if(!(panEnabled || isEmptyBackground)) return;
    // don't pan when clicking element handles/edit UI
    if($(e.target).closest('.picky-element, .ui-resizable-handle, .picky-custom-resizer, .picky-element-edit-wrap').length) return;
    isPanning = true;
    panStart.x = e.pageX; panStart.y = e.pageY;
    panStart.px = pickyPanX; panStart.py = pickyPanY;
    $(this).addClass('picky-panning');
    e.preventDefault();
  });

  /* Escape clears multi-select (and closes the inspector). */
  $(document).on('keydown', function(e){
    if(e.key !== 'Escape' && e.code !== 'Escape'){ return; }
    if(!pickyMultiSelectCount() && !$('.picky-draggable .picky-element.opened').length){ return; }
    if($(e.target).closest('input, textarea, select, [contenteditable="true"]').length){ return; }
    pickyClearMultiSelect();
    pickyCloseElementEditor();
  });
  $(document).on('mousemove', function(e){
    if(!isPanning) return;
    pickyPanX = panStart.px + (e.pageX - panStart.x);
    pickyPanY = panStart.py + (e.pageY - panStart.y);
    applyCanvasTransform();
  });
  $(document).on('mouseup', function(){
    if(!isPanning) return;
    isPanning = false;
    $('.picky-draggable-wrap').removeClass('picky-panning');
  });

  // Ctrl/Cmd + wheel zoom
  $(document).on('wheel', '.picky-draggable-wrap', function(e){
    if(!(e.ctrlKey || e.metaKey)) return;
    e.preventDefault();
    var delta = e.originalEvent.deltaY;
    pickyZoom = clampZoom(pickyZoom + (delta > 0 ? -0.08 : 0.08));
    applyCanvasTransform();
  });



  // Wall ctrl bar / rotate handle: free drag rotate (same as bookables).
  // Click (no drag) snaps +90°.
  $(document).on('mousedown', '.picky-rotate-handle, .picky-wall-ctrl-rotate', function(e){
    var $handle = $(this);
    var $el = $handle.closest('.picky-element');
    if(!$el.length){ return; }
    e.preventDefault();
    e.stopPropagation();

    $el.addClass('picky-rotate-dragging');
    var $twR = $el.find('.picky-element-table-wrap').first();
    var rect = $twR.length
      ? $twR[0].getBoundingClientRect()
      : $el[0].getBoundingClientRect();
    var cx = rect.left + rect.width / 2 + (window.scrollX != null ? window.scrollX : window.pageXOffset);
    var cy = rect.top + rect.height / 2 + (window.scrollY != null ? window.scrollY : window.pageYOffset);
    var startAngle = parseInt($el.attr('data-rotate-z') || '0', 10);
    if(isNaN(startAngle)){ startAngle = 0; }
    var didDrag = false;
    var startX = e.pageX;
    var startY = e.pageY;

    pickySetInlineCanvasToolsVisibleForElement($el[0], false);

    $(document).on('mousemove.pickyRotate', function(ev){
      if(!didDrag && (Math.abs(ev.pageX - startX) > 3 || Math.abs(ev.pageY - startY) > 3)){
        didDrag = true;
      }
      if(!didDrag){ return; }
      var dx = ev.pageX - cx;
      var dy = ev.pageY - cy;
      var angle = Math.round((Math.atan2(dy, dx) * 180 / Math.PI) + 90);
      if(angle < 0) angle += 360;
      
      // Smooth by default; hold Shift to snap.
      if(ev.shiftKey) {
        angle = Math.round(angle / 15) * 15;
      }
      
      $el.attr('data-rotate-z', angle);
      applyTransforms($el);
      
      // Keep rotation smooth: do not clamp/push during drag.
      // We normalize inside canvas once on mouseup.
    });

    $(document).on('mouseup.pickyRotate', function(){
      $(document).off('mousemove.pickyRotate mouseup.pickyRotate');
      $el.removeClass('picky-rotate-dragging');
      if(!didDrag){
        // Click = quarter turn (walls / text / bookables / shapes).
        var next = (startAngle + 90) % 360;
        if(next < 0){ next += 360; }
        $el.attr('data-rotate-z', next);
      }
      /* Keep rotate mode active after drag; user leaves it explicitly or by
         switching to another option (e.g. 3D). */
      pushIntoCanvas($el, true);
      /* Snap controls back to logical anchors after rotation ends. */
      updateInteractiveHandlesPosition($el);
      applyTransforms($el);
      if($el.hasClass('type-wall')){
        pickyInitWallHandles($el);
      }
      pickyPositionInlineCanvasTools($el);
      pickySetInlineCanvasToolsVisibleForElement($el[0], true);
      if(typeof takeSnapshot === 'function') takeSnapshot();
    });
  });

  // Drag to tilt (3D pad - Robust implementation)
  $(document).on('mousedown', '.picky-3d-handle', function(e){
    e.preventDefault();
    e.stopPropagation();
    var $handle = $(this);
    var $el = $handle.closest('.picky-element');
    var $pad = $handle.closest('.picky-3d-pad');
    if(!$el.length || !$pad.length) return;

    pickySetInlineCanvasToolsVisibleForElement($el[0], false);

    var padRect = $pad[0].getBoundingClientRect();
    var midX = padRect.width / 2;
    var midY = padRect.height / 2;

    $(document).on('mousemove.picky3D', function(ev){
      var cx = ev.clientX - padRect.left;
      var cy = ev.clientY - padRect.top;
      
      // Normalized coordinates (-1 to 1)
      var nx = Math.max(-1, Math.min(1, (cx - midX) / midX));
      var ny = Math.max(-1, Math.min(1, (cy - midY) / midY));
      
      // Calculate angles (max 45 degrees)
      var ry = Math.round(nx * 45);
      var rx = Math.round(-ny * 45);
      
      $el.attr('data-rotate-x', rx);
      $el.attr('data-rotate-y', ry);
      
      // Update visual handle position
      $handle.css({
        left: (nx * 50 + 50) + '%',
        top: (ny * 50 + 50) + '%',
        transition: 'none'
      });
      
      applyTransforms($el);
      // Update padRect in case element moved visually
      padRect = $pad[0].getBoundingClientRect();
    });

    $(document).on('mouseup.picky3D', function(){
      $(document).off('mousemove.picky3D mouseup.picky3D');
      $handle.css({
        left: '50%',
        top: '50%',
        transition: 'all 0.2s'
      });
      pushIntoCanvas($el, true);
      updateInteractiveHandlesPosition($el);
      applyTransforms($el);
      pickyPositionInlineCanvasTools($el);
      pickySetInlineCanvasToolsVisibleForElement($el[0], true);
      if(typeof takeSnapshot === 'function') takeSnapshot();
    });
  });

  // Upload custom image
  $(document).on('click', '.picky-element-custom-upload', function(e){
    e.preventDefault();
    e.stopPropagation();
    var element = getEditorTarget(this);
    if(!element.length || element.hasClass('type-text')){ return; }
    var custom_uploader;
    if (custom_uploader) {
        custom_uploader.open();
        return;
    }
    custom_uploader = wp.media.frames.file_frame = wp.media({
        title: pickyAdminI18n.insert_image,
        button: {
            text: pickyAdminI18n.use_this_image
        },
        multiple: false
    });
    custom_uploader.on('select', function() {
        var attachment = custom_uploader.state().get('selection').first().toJSON();
        element.attr('data-custom-url', attachment.url);
        // Image textures should override prior solid fill with true transparency.
        element.attr('data-color', 'transparent');
        element.find('.picky-element-shape').css({
          'background-image': 'url('+attachment.url+')',
          'background-color': 'transparent'
        });
        takeSnapshot();
    });
    custom_uploader.open();
  });

  // Color tool: wpColorPicker (standard WP swatch + dropdown; not used for type-text)
  $(document).on('click', '.picky-element-edit-color.picky-element-edit-btn', function(e){
    e.preventDefault();
    e.stopPropagation();

    var $colorBtn = $(e.currentTarget);
    var element = getEditorTarget($colorBtn[0]);
    if(!element.length) return;

    /* The color button lives in the detached tool strip on the canvas, so
       .closest('.picky-element-edit-wrap') fails. Locate the active editor
       mounted inside the inline inspector toolbar instead. */
    var $editor = $colorBtn.closest('.picky-element-edit-wrap');
    if(!$editor.length){
      $editor = $('#picky-builder-toolbar .picky-element-edit-wrap').first();
    }
    if(!$editor.length){
      $editor = element.find('.picky-element-edit-wrap').first();
    }
    if(!$editor.length) return;
    var $pickerWrap = $editor.find('.picky-element-color-picker-wrap').first();
    if(!$pickerWrap.length){
      /* May already be portaled to body from a previous open. */
      $pickerWrap = $('body > .picky-element-color-picker-wrap.picky-color-picker-portal').filter(function(){
        var $h = $(this).data('pickyHome');
        if(!$h || !$h.length || !$editor.length){ return false; }
        return $h[0] === $editor[0] || $.contains($editor[0], $h[0]);
      }).first();
    }
    if(!$pickerWrap.length) return;

    var current = element.hasClass('type-text') ? pickyGetElementCurrentTextColor(element) : pickyGetElementCurrentBgColor(element);

    if($pickerWrap.is(':visible')){
      pickyCloseToolStripColorPickerPopup($pickerWrap);
      return;
    }
    $('.picky-element-color-picker-wrap').each(function(){
      pickyCloseToolStripColorPickerPopup($(this));
    });
    /* Close handlers replace the input node — refresh references before init. */
    $pickerWrap = $editor.find('.picky-element-color-picker-wrap').first();
    var $input = $pickerWrap.find('.picky-element-color-input').first();
    if(!$pickerWrap.length || !$input.length) return;
    $input.val(current);
    pickyEnsureToolStripColorPicker($editor, $input);
    try {
      $input.wpColorPicker('color', current);
    } catch (errCol) { /* first bind */ }
    $pickerWrap.data('pickyAnchor', $colorBtn);
    /* Portal out of #picky-element-inspector (transform + overflow:hidden clip fixed popups). */
    pickyPortalToolStripColorPickerWrap($pickerWrap);
    $pickerWrap.show();
    /* Ignore the same click event's bubble-to-document close handler. */
    $pickerWrap.data('pickyIgnoreOutsideUntil', Date.now() + 250);
    pickyPositionToolStripColorPickerWrap($pickerWrap, $colorBtn);
    window.requestAnimationFrame(function(){
      pickyPositionToolStripColorPickerWrap($pickerWrap, $colorBtn);
      var $sw = $pickerWrap.find('.wp-color-result.button').first();
      if($sw.length){ $sw.trigger('click'); }
      window.requestAnimationFrame(function(){
        pickyPositionToolStripColorPickerWrap($pickerWrap, $colorBtn);
        window.setTimeout(function(){
          pickyPositionToolStripColorPickerWrap($pickerWrap, $colorBtn);
        }, 60);
      });
    });
  });

  // Close color picker on click outside
  $(document).on('click', function(e){
    if($(e.target).closest('.picky-element-edit-wrap, .picky-element-color-picker-wrap, .wp-picker-container, .wp-picker-holder, .picky-element-edit-color, .iris-picker').length){
      return;
    }
    $('.picky-element-color-picker-wrap:visible').each(function(){
      var $w = $(this);
      var until = parseInt($w.data('pickyIgnoreOutsideUntil'), 10) || 0;
      if(until && Date.now() < until){ return; }
      pickyCloseToolStripColorPickerPopup($w);
    });
  });

  /* Stacking: DOM order + sequential z-index across ALL canvas elements.
     (Type bands previously made Bring to front / Send to back look broken —
     e.g. a shape could never rise above text/tables.) */
  function pickyRefreshCanvasLayerOrder($canvas){
    if(!$canvas || !$canvas.length){ return; }
    $canvas.children('.picky-element').each(function(i){
      var z = 10 + i;
      $(this).css('z-index', z).attr('data-z-index', String(z));
    });
  }

  function pickyMoveElementLayer($el, toFront){
    if(!$el || !$el.length){ return; }
    var $canvas = $el.closest('.picky-draggable');
    if(!$canvas.length){ return; }

    /* Close color picker husk before reordering (Iris closes on outside click
       but our wrap used to stay open as an empty bar). */
    $('.picky-element-color-picker-wrap:visible').each(function(){
      if(typeof pickyCloseToolStripColorPickerPopup === 'function'){
        pickyCloseToolStripColorPickerPopup($(this));
      }
    });

    if(toFront){
      $canvas.append($el);
    } else {
      $canvas.prepend($el);
    }

    pickyRefreshCanvasLayerOrder($canvas);

    if(typeof pickyPositionInlineCanvasTools === 'function'){
      pickyPositionInlineCanvasTools($el);
    }
    if(typeof refreshCoordinates === 'function'){
      refreshCoordinates();
    }
    if(typeof takeSnapshot === 'function'){
      takeSnapshot();
    }
  }

  /* Any non-color tool click must fully tear down the strip picker (not leave
     a collapsed "Select Color" shell after Iris auto-closes). */
  $(document).on('mousedown', '.picky-element-edit-tools .picky-element-edit-btn:not(.picky-element-edit-color)', function(){
    $('.picky-element-color-picker-wrap:visible').each(function(){
      if(typeof pickyCloseToolStripColorPickerPopup === 'function'){
        pickyCloseToolStripColorPickerPopup($(this));
      }
    });
  });

  $(document).on('click', '.picky-element-layer-front', function(e){
    e.preventDefault();
    e.stopPropagation();
    pickyMoveElementLayer(getEditorTarget(this), true);
  });

  $(document).on('click', '.picky-element-layer-back', function(e){
    e.preventDefault();
    e.stopPropagation();
    pickyMoveElementLayer(getEditorTarget(this), false);
  });

  // Duplicate canvas element(s) — single or Shift+multi-select group
  function pickyDuplicateCanvasElement($src, opts){
    opts = opts || {};
    if(!$src || !$src.length || !$src.closest('.picky-draggable').length){ return $(); }

    var $canvas = $src.closest('.picky-draggable');
    var dtype = ($src.attr('data-type') || '').toString();
    var isBack = dtype === 'type-wall' || dtype === 'type-text' || dtype === 'type-shape' || dtype === 'type-icon';
    var nudgeX = (typeof opts.nudgeX === 'number') ? opts.nudgeX : 18;
    var nudgeY = (typeof opts.nudgeY === 'number') ? opts.nudgeY : 18;

    var $clone = $src.clone(false, false);
    $clone.removeClass('opened ui-draggable ui-draggable-handle ui-draggable-dragging picky-element-deleting ' + Picky_MS_CLASS);

    try{
      if($clone.data('ui-draggable')){ $clone.draggable('destroy'); }
    } catch(ex){}

    $clone.attr('data-unique', makeid(10));

    if(isBack){
      $clone.attr('data-id', 0);
      $clone.find('.picky-element-id').remove();
    } else {
      elementIdRefresh();
      element_id++;
      var newId = element_id;
      $clone.attr('data-id', newId);
      $clone.removeAttr('data-custom-id');
      var $idEl = $clone.find('.picky-element-id').first();
      if($idEl.length){
        $idEl.text(newId).attr('data-default', newId);
      } else {
        $clone.find('.picky-element-overlay').prepend('<div class="picky-element-id" data-default="'+newId+'">'+newId+'</div>');
      }
    }

    var curL = parseFloat($src.css('left'));
    var curT = parseFloat($src.css('top'));
    if(isNaN(curL)){ curL = 0; }
    if(isNaN(curT)){ curT = 0; }
    $clone.css({ left: (curL + nudgeX) + 'px', top: (curT + nudgeY) + 'px' });

    $canvas.append($clone);

    ensureElementEditor($clone);
    $clone.find('.picky-element-edit-wrap').removeClass('opened').removeData('picky-target');

    if(dtype === 'type-text'){
      updateTextPreview($clone);
    }

    pickyNormalizeTableRotateDom($clone);
    applyTransforms($clone);
    applyElementOpacity($clone);

    var url = $clone.attr('data-custom-url') || '';
    if(url && dtype !== 'type-text'){
      $clone.find('.picky-element-shape').css({
        'background-image': 'url('+url+')',
        'background-color': 'transparent',
        'padding': '0'
      });
    }
    var color = $clone.attr('data-color') || '';
    if(color && !url){
      if(dtype === 'type-icon'){
        $clone.find('.picky-element-shape').css({
          color: color,
          'background-color': 'transparent',
          background: 'transparent'
        });
      } else {
        $clone.find('.picky-element-shape').css('background-color', color);
      }
    }
    if(dtype === 'type-icon'){
      pickyApplyCanvasIcon($clone);
    }

    if(dtype === 'type-wall'){
      window.setTimeout(function(){ pickyInitWallHandles($clone); }, 50);
    }

    if(dtype === 'type-shape' || dtype === 'type-icon'){
      pickyDestroyResizable($clone);
      if($src.hasClass('picky-resize-enabled')){
        window.setTimeout(function(){
          pickyEnableResizable($clone, {
            handles: 'n,e,s,w,ne,se,sw,nw',
            containment: '.picky-draggable',
            stop: function(){}
          });
        }, 50);
      }
    } else if(dtype === 'type-text'){
      pickyDestroyResizable($clone);
      if($src.hasClass('picky-resize-enabled')){
        window.setTimeout(function(){
          pickyEnableResizable($clone, {
            handles: 'nw,ne,se,sw',
            stop: function(){}
          });
        }, 50);
      }
    } else {
      $clone.find('.picky-custom-resizer').remove();
    }

    pushIntoCanvas($clone, true);

    try{
      if($clone.data('ui-draggable')){ $clone.draggable('destroy'); }
    } catch(ex2){}
    $clone.removeClass('ui-draggable ui-draggable-handle ui-draggable-dragging');
    pickyInitDraggable($clone);

    return $clone;
  }

  /**
   * Duplicate the current multi-select group (or the single opened element).
   * Clones keep relative spacing and become the new selection.
   */
  function pickyDuplicateSelection(){
    if(!$('.picky-draggable').length){ return; }

    var $group = pickyGetMultiSelected();
    if($group.length < 2){
      var $src = $group.length === 1
        ? $group.first()
        : $('.picky-draggable .picky-element.opened').first();
      if(!$src.length){ return; }
      var $one = pickyDuplicateCanvasElement($src);
      if(!$one.length){ return; }
      pickyClearMultiSelect();
      refreshCoordinates();
      elementIdRefresh();
      takeSnapshot();
      pickyCloseElementEditor();
      pickyOpenElementEditor($one);
      return;
    }

    var sources = [];
    $group.each(function(){ sources.push($(this)); });

    var clones = [];
    var i;
    for(i = 0; i < sources.length; i++){
      var $c = pickyDuplicateCanvasElement(sources[i]);
      if($c && $c.length){ clones.push($c); }
    }
    if(!clones.length){ return; }

    pickyClearMultiSelect();
    for(i = 0; i < clones.length; i++){
      clones[i].addClass(Picky_MS_CLASS);
    }

    refreshCoordinates();
    elementIdRefresh();
    takeSnapshot();

    pickyCloseElementEditor({ keepMulti: true });
    pickyOpenElementEditor(clones[0], { keepMulti: true });
    for(i = 0; i < clones.length; i++){
      clones[i].addClass(Picky_MS_CLASS);
    }
  }

  $(document).on('click', '.picky-element-duplicate', function(e){
    e.preventDefault();
    e.stopPropagation();
    pickyDuplicateSelection();
  });

  /* Ctrl/Cmd+D — duplicate selection (or opened element). */
  $(document).on('keydown', function(e){
    if(!(e.ctrlKey || e.metaKey)){ return; }
    if(e.key !== 'd' && e.key !== 'D' && e.code !== 'KeyD'){ return; }
    if($(e.target).closest('input, textarea, select, [contenteditable="true"]').length){ return; }
    if(!$('.picky-draggable').length){ return; }
    if(pickyMultiSelectCount() < 1 && !$('.picky-draggable .picky-element.opened').length){ return; }
    e.preventDefault();
    pickyDuplicateSelection();
  });

  // Delete element
  $(document).on('click', '.picky-element-delete', function(e){
    e.preventDefault();
    e.stopPropagation();
    var element = getEditorTarget(this);
    if(!element || !element.length) return;
    element.addClass('picky-element-deleting');
    element.fadeOut(200,function(){
      $.when($(this).remove()).then(function(){
        coordinates = refreshCoordinates();
        elementIdRefresh();
        takeSnapshot();
        // Hide editor since element is gone
        pickyCloseElementEditor();
      });
    });
  });
  


  $(document).ready(function () {
    function pickySyncLayoutEventScheduleFields() {
      var mode = $('input[name="picky_layout_event_mode"]:checked').val() || 'calendar';
      var $fields = $('.picky-layout-event-once-fields');
      if (!$fields.length) { return; }
      $fields.css('display', mode === 'once' ? 'flex' : 'none');
    }
    $(document).on('change', 'input[name="picky_layout_event_mode"]', pickySyncLayoutEventScheduleFields);
    pickySyncLayoutEventScheduleFields();

    /* Drop loading shim immediately so ::after / white layers cannot block interaction if init throws. */
    $('.picky-tables-wrap').removeClass('picky-loading');
    try { restoreSettingsControls(); } catch (ex) {}

    pickyMountInspectorInline();
    
    fixEmbeddedPostsListAdminLinks();
    bindEmbeddedPostsListNavigationGuard();
    initSettingsTabsState();
    pickyScrollSettingsSectionFromHash();
    initPickySettingsNavToggle();
    initPickyCollapsibleFilters();
    initSettingsHelpDetailsAccordion();
    try { schedulePickySettingsRailPaddingAndBuilderSync(); } catch (exRail) {}
    elementIdRefresh();
    $('.picky-draggable .picky-element').each(function(){
      var $t = $(this);
      var ty = ($t.attr('data-type') || '').toString();
      if(ty === 'type-wall' || ty === 'type-text' || ty === 'type-shape' || ty === 'type-icon'){ return; }
      if($t.attr('data-show-id') === undefined || $t.attr('data-show-id') === ''){
        $t.attr('data-show-id', '1');
      }
    });
    showSpinner();
    initDraggble();
    ititDroppable();
    initDraggbleLoad();
    
    $('.picky-element').each(function(){
      var $el = $(this);
      ensureElementEditor($el);
      pickyNormalizeTableRotateDom($el);
      
      // 1. Ensure content exists first
      updateTextPreview($el);
      
      // 2. Apply transformations to existing content
      applyTransforms($el);
      applyElementOpacity($el);
      pickyApplyElementFillFromData($el);

      var type = $el.attr('data-type');
      if(type === 'type-wall'){
        pickyInitWallHandles($el);
      }

      $el.find('.picky-custom-resizer').remove();
      if($el.hasClass('picky-resize-enabled')){
        pickyEnableResizable($el);
      }
    });
    // Initial snapshot for undo
    (function(){ try { $(document).trigger('picky:snapshot'); } catch(e){} })();
    
    $('.picky-tables-wrap').removeClass('picky-loading');
    applyCanvasTransform();
    $('.picky-draggable .picky-element.type-text').each(function(){
        updateTextPreview($(this));
    });
    applyCanvasBackgroundMode(
      $('select[name="picky_layout_bg_canvas_mode"]').val() ||
      $('select[name="picky_layout_settings[background-canvas-mode]"]').val() ||
      'cover'
    );
    restoreSettingsControls();
    syncBuilderToolbarWidth();
    syncBuilderShellWidth();
    try { schedulePickySettingsRailPaddingAndBuilderSync(); } catch(exRail2){}
    try { pickySyncLeftColDockRect(); } catch(exLeftDock){}
    try { pickyRenderServicesLibrary(); } catch(ex){}

    try {
      pickyEnsureAlignGuidesOverlay().attr('data-picky-align', window.__PICKY_ALIGN_V || 'align4');
    } catch (exAlign) {}

    window.setTimeout(function(){
      try {
        if(pickyBuilderInteractionsAllowed()){
          pickyReinitBuilderDragDrop();
        }
      } catch(e){}
    }, 120);

  });



  /* ===== Layout-level Services Library ===== */

  function pickyGenSid() {
    return 'svc_' + Math.random().toString(36).substr(2, 8);
  }

  function pickyReadServicesLibrary() {
    var raw = $('#picky-layout-services-library-input').val() || '[]';
    try { var parsed = JSON.parse(raw); return Array.isArray(parsed) ? parsed : []; } catch(e) { return []; }
  }

  function pickyWriteServicesLibrary(arr) {
    $('#picky-layout-services-library-input').val(JSON.stringify(Array.isArray(arr) ? arr : []));
  }

  function pickyLibNameMap() {
    var library = pickyReadServicesLibrary();
    var map = {};
    for (var i = 0; i < library.length; i++) { map[library[i].sid] = library[i].name || library[i].sid; }
    return map;
  }

  function pickyApplyElementFillFromData($el){
    if(!$el || !$el.length){ return; }
    var type = ($el.attr('data-type') || '').toString();
    var url = ($el.attr('data-custom-url') || '').toString().trim();
    if(url && type !== 'type-text'){
      $el.find('.picky-element-shape').css({
        'background-image': 'url('+url+')',
        'background-color': 'transparent',
        'padding': '0'
      });
      return;
    }
    if(type === 'type-icon' || $el.hasClass('type-icon')){
      pickyApplyCanvasIcon($el);
      return;
    }
    var color = $el.attr('data-color') || '';
    if(color){
      $el.find('.picky-element-shape').css('background-color', color);
    }
  }

  function pickyBiconSvg(key) {
    var payload = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.bookingIcons) ? pickyLayoutData.bookingIcons : null;
    if (!payload || !key) { return ''; }
    var icons = payload.icons || {};
    if (!icons[key]) { return ''; }
    var symbol = icons[key].symbol || ('picky-bicon-' + key);
    var href = '#' + symbol;
    return '<svg class="picky-bicon" aria-hidden="true" focusable="false"><use href="' + href + '" xlink:href="' + href + '"></use></svg>';
  }

  function pickyApplyCanvasIcon($el) {
    if (!$el || !$el.length || !$el.hasClass('type-icon')) { return; }
    var key = String($el.attr('data-icon') || '').trim() || 'star';
    var payload = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.bookingIcons) ? pickyLayoutData.bookingIcons : null;
    if (payload && payload.icons && !payload.icons[key]) { key = 'star'; }
    $el.attr('data-icon', key);
    var color = normalizeHexColor($el.attr('data-color') || '', '#334155');
    var $shape = $el.find('.picky-element-shape').first();
    var svg = pickyBiconSvg(key);
    if (!svg) { svg = pickyBiconSvg('star'); }
    $shape.html('<span class="picky-element-icon-wrap">' + svg.replace('class="picky-bicon"', 'class="picky-bicon picky-element-icon"') + '</span>');
    $shape.css({
      color: color,
      'background-color': 'transparent',
      background: 'transparent',
      'background-image': 'none'
    });
  }

  function pickyBiconSetPickerValue($picker, key) {
    key = String(key || '');
    var payload = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.bookingIcons) ? pickyLayoutData.bookingIcons : null;
    if (payload && key && !(payload.icons || {})[key]) { key = ''; }
    $picker.find('.picky-bicon-picker-value').val(key);
    $picker.find('.picky-bicon-picker-none').toggleClass('is-active', key === '');
    $picker.find('.picky-bicon-picker-btn').each(function(){
      $(this).toggleClass('is-selected', String($(this).attr('data-picky-bicon') || '') === key);
    });
    var $preview = $picker.find('.picky-bicon-picker-preview');
    $preview.html(key ? pickyBiconSvg(key) : '');
  }

  $(document).on('click', '.picky-bicon-picker-toggle', function(e){
    e.preventDefault();
    var $picker = $(this).closest('[data-picky-bicon-picker]');
    var $grid = $picker.find('.picky-bicon-picker-grid');
    var open = $grid.prop('hidden');
    $grid.prop('hidden', !open);
    $(this).attr('aria-expanded', open ? 'true' : 'false');
  });

  $(document).on('click', '.picky-bicon-picker-none, .picky-bicon-picker-btn', function(e){
    e.preventDefault();
    var $picker = $(this).closest('[data-picky-bicon-picker]');
    var key = $(this).attr('data-picky-bicon') || '';
    pickyBiconSetPickerValue($picker, key);
    var $editor = $picker.closest('.picky-element-edit-wrap');
    if ($editor.length) {
      var $el = getEditorTarget($picker.get(0));
      if ($el && $el.length && $el.hasClass('type-icon')) {
        $el.attr('data-icon', key || 'star');
        pickyApplyCanvasIcon($el);
        if (typeof takeSnapshot === 'function') { takeSnapshot(); }
      }
    }
  });

  function pickyLibParentSids(s) {
    return (s && Array.isArray(s.parent_sids)) ? s.parent_sids.slice() : [];
  }

  function pickyLibIsAddon(s) {
    return pickyLibParentSids(s).length > 0;
  }

  function pickyRenderServicesLibrary() {
    var library = pickyReadServicesLibrary();
    var $tbody = $('.picky-services-library-list');
    var $empty = $('.picky-services-library-empty');
    $tbody.empty();
    if (library.length === 0) {
      $empty.show();
    } else {
      $empty.hide();
      var nameMap = pickyLibNameMap();
      var addonSids = {};
      for (var k = 0; k < library.length; k++) {
        if (pickyLibIsAddon(library[k])) { addonSids[library[k].sid] = true; }
      }
      for (var i = 0; i < library.length; i++) {
        var s = library[i];
        var parents = pickyLibParentSids(s);
        var isAddon = parents.length > 0;
        var $tr = $('<tr></tr>');
        if (isAddon) { $tr.addClass('picky-lib-row-addon'); }

        var nameCell = pickyEscHtml(s.name || '');
        if (s.icon) {
          nameCell = '<span class="picky-lib-row-icon">' + pickyBiconSvg(s.icon) + '</span>' + nameCell;
        }
        if (isAddon) {
          nameCell = '<span class="picky-lib-row-addon-indent">\u21B3</span> ' + nameCell;
        }
        $tr.append('<td>' + nameCell + '</td>');

        var typeHtml;
        if (!isAddon) {
          typeHtml = '<span class="picky-lib-type-badge picky-lib-type-badge--parent">' + pickyEscHtml('Service') + '</span>';
        } else {
          var validParentNames = [];
          var orphan = true;
          for (var p = 0; p < parents.length; p++) {
            if (nameMap[parents[p]] && !addonSids[parents[p]]) {
              validParentNames.push(nameMap[parents[p]]);
              orphan = false;
            }
          }
          if (orphan) {
            typeHtml = '<span class="picky-lib-type-badge picky-lib-type-badge--warning" title="' + pickyEscAttr('Parent service was deleted. Edit or remove this add-on.') + '">' + pickyEscHtml('Add-on (orphan)') + '</span>';
          } else {
            var tip = 'Add-on of: ' + validParentNames.join(', ');
            typeHtml = '<span class="picky-lib-type-badge picky-lib-type-badge--addon" title="' + pickyEscAttr(tip) + '">' + pickyEscHtml('Add-on') + '</span>';
          }
        }
        $tr.append('<td>' + typeHtml + '</td>');

        $tr.append('<td>' + (parseFloat(s.price || 0).toFixed(2)) + '</td>');
        $tr.append('<td>' + parseInt(s.duration || 30, 10) + ' min</td>');
        $tr.append('<td>' + pickyEscHtml((s.description || '').substring(0, 60)) + '</td>');
        var $actions = $('<td></td>');
        $actions.append('<button type="button" class="button button-small picky-lib-row-edit" data-sid="' + pickyEscAttr(s.sid) + '">' + pickyEscHtml('Edit') + '</button> ');
        $actions.append('<button type="button" class="button button-small picky-lib-row-delete" data-sid="' + pickyEscAttr(s.sid) + '">' + pickyEscHtml('Delete') + '</button>');
        $tr.append($actions);
        $tbody.append($tr);
      }
    }
  }

  /**
   * Render the parent-services picker in the inline editor for the service
   * whose SID is `currentSid` (empty string for a brand-new service).
   * Parents that are themselves add-ons are excluded (strict 2-level hierarchy).
   */
  function pickyRenderLibEditParents($editor, currentSid, preSelected) {
    var library = pickyReadServicesLibrary();
    var $list = $editor.find('.picky-lib-edit-parents-list');
    var $emptyMsg = $editor.find('.picky-lib-edit-parents-empty');
    $list.empty();
    var picks = Array.isArray(preSelected) ? preSelected.slice() : [];
    var anyParent = false;
    for (var i = 0; i < library.length; i++) {
      var s = library[i];
      if (s.sid === currentSid) { continue; }       // can't parent yourself
      if (pickyLibIsAddon(s)) { continue; }            // strict 2-level: parents must be top-level
      anyParent = true;
      var checked = picks.indexOf(s.sid) >= 0 ? ' checked' : '';
      var $lbl = $('<label class="picky-lib-edit-parent-chip" style="display:inline-flex;align-items:center;gap:4px;border:1px solid #c3c4c7;border-radius:4px;padding:4px 8px;background:#fff;cursor:pointer;font-size:12px;"></label>');
      $lbl.append('<input type="checkbox" class="picky-lib-edit-parent-cb" value="' + pickyEscAttr(s.sid) + '"' + checked + ' style="margin:0;">');
      $lbl.append('<span>' + pickyEscHtml(s.name) + '</span>');
      $list.append($lbl);
    }
    if (!anyParent) { $emptyMsg.show(); } else { $emptyMsg.hide(); }
  }

  function pickyReadLibEditParents($editor) {
    var sids = [];
    $editor.find('.picky-lib-edit-parent-cb:checked').each(function(){ sids.push(String($(this).val())); });
    return sids;
  }

  function pickyEscHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function pickyEscAttr(s) { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function pickyLibFindService(sid) {
    var library = pickyReadServicesLibrary();
    for (var i = 0; i < library.length; i++) { if (library[i].sid === sid) return library[i]; }
    return null;
  }

  function pickyLibDeleteService(sid) {
    var library = pickyReadServicesLibrary();
    library = library.filter(function(s){ return s.sid !== sid; });
    // Cascade: drop the deleted SID from every other service's parent_sids so
    // add-ons don't keep dangling references to a parent that no longer exists.
    for (var k = 0; k < library.length; k++) {
      if (Array.isArray(library[k].parent_sids) && library[k].parent_sids.length > 0) {
        library[k].parent_sids = library[k].parent_sids.filter(function(p){ return p !== sid; });
      }
    }
    pickyWriteServicesLibrary(library);
    pickyRenderServicesLibrary();
    // Cascade: remove SID from every element's data-service-ids
    $('.picky-draggable .picky-element').each(function(){
      var ids = pickyParseServiceIds($(this).attr('data-service-ids'));
      ids = ids.filter(function(id){ return id !== sid; });
      $(this).attr('data-service-ids', JSON.stringify(ids));
    });
    // Refresh any open editor chips
    $('.picky-element-edit-wrap').each(function(){
      var editor = $(this);
      var element = getEditorTarget(editor.find('.picky-field-service-ids').get(0));
      if (element && element.length) { pickyRenderServiceIdChips(editor, element); }
    });
  }

  function pickyLibSaveService(sid, data) {
    var library = pickyReadServicesLibrary();
    var idx = -1;
    for (var i = 0; i < library.length; i++) { if (library[i].sid === sid) { idx = i; break; } }

    // Validate & normalize parent_sids: drop self, drop unknown, drop add-on
    // refs (strict 2-level hierarchy).
    var parents = Array.isArray(data.parent_sids) ? data.parent_sids.slice() : [];
    var seen = {};
    var cleanParents = [];
    for (var p = 0; p < parents.length; p++) {
      var pid = String(parents[p] || '').trim();
      if (!pid || pid === sid || seen[pid]) { continue; }
      // Find candidate in library; must exist and be top-level (no parent_sids of its own).
      var match = null;
      for (var m = 0; m < library.length; m++) { if (library[m].sid === pid) { match = library[m]; break; } }
      if (!match) { continue; }
      if (Array.isArray(match.parent_sids) && match.parent_sids.length > 0) { continue; }
      seen[pid] = true;
      cleanParents.push(pid);
    }

    var entry = {
      sid: sid,
      name: String(data.name || '').trim(),
      price: parseFloat(data.price) || 0,
      duration: parseInt(data.duration, 10) || 30,
      description: String(data.description || '').substring(0, 200),
      parent_sids: cleanParents,
      icon: String(data.icon || '').trim()
    };
    if (!entry.name) { return false; }
    var payload = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.bookingIcons) ? pickyLayoutData.bookingIcons : null;
    if (!payload || !payload.icons || !payload.icons[entry.icon]) { entry.icon = ''; }

    // Anti-promotion-of-parent: if we're saving a service that currently has
    // ANY other service pointing at it as a parent, we cannot turn it into an
    // add-on (would break 2-level rule). Strip parent_sids in that case.
    if (entry.parent_sids.length > 0) {
      for (var c = 0; c < library.length; c++) {
        if (library[c].sid === sid) { continue; }
        if (Array.isArray(library[c].parent_sids) && library[c].parent_sids.indexOf(sid) >= 0) {
          entry.parent_sids = [];
          if (typeof window !== 'undefined' && window.alert) {
            window.alert('Cannot turn this service into an add-on: other services already use it as a parent. Remove those references first.');
          }
          break;
        }
      }
    }

    if (idx >= 0) { library[idx] = entry; } else { library.push(entry); }
    pickyWriteServicesLibrary(library);
    pickyRenderServicesLibrary();
    // Refresh any open editor chips
    $('.picky-element-edit-wrap').each(function(){
      var editor = $(this);
      var element = getEditorTarget(editor.find('.picky-field-service-ids').get(0));
      if (element && element.length) { pickyRenderServiceIdChips(editor, element); }
    });
    return true;
  }

  // Library UI events
  $(document).on('click', '.picky-services-library-add-btn', function(){
    var $editor = $('.picky-services-library-editor');
    var newSid = pickyGenSid();
    $editor.find('.picky-lib-edit-sid').val(newSid);
    $editor.find('.picky-lib-edit-name').val('');
    $editor.find('.picky-lib-edit-price').val(0);
    $editor.find('.picky-lib-edit-duration').val(30);
    $editor.find('.picky-lib-edit-description').val('');
    pickyBiconSetPickerValue($editor.find('[data-picky-bicon-picker]'), '');
    pickyRenderLibEditParents($editor, newSid, []);
    $editor.show();
  });

  $(document).on('click', '.picky-lib-edit-cancel', function(){
    $(this).closest('.picky-services-library-editor').hide();
  });

  $(document).on('click', '.picky-lib-edit-save', function(){
    var $editor = $(this).closest('.picky-services-library-editor');
    var sid = $editor.find('.picky-lib-edit-sid').val().trim() || pickyGenSid();
    var ok = pickyLibSaveService(sid, {
      name: $editor.find('.picky-lib-edit-name').val(),
      price: $editor.find('.picky-lib-edit-price').val(),
      duration: $editor.find('.picky-lib-edit-duration').val(),
      description: $editor.find('.picky-lib-edit-description').val(),
      parent_sids: pickyReadLibEditParents($editor),
      icon: $editor.find('.picky-lib-edit-icon').val() || ''
    });
    if (ok) { $editor.hide(); }
  });

  $(document).on('click', '.picky-lib-row-edit', function(){
    var sid = $(this).data('sid');
    var s = pickyLibFindService(sid);
    if (!s) return;
    var $editor = $('.picky-services-library-editor');
    $editor.find('.picky-lib-edit-sid').val(s.sid);
    $editor.find('.picky-lib-edit-name').val(s.name);
    $editor.find('.picky-lib-edit-price').val(s.price);
    $editor.find('.picky-lib-edit-duration').val(s.duration);
    $editor.find('.picky-lib-edit-description').val(s.description || '');
    pickyBiconSetPickerValue($editor.find('[data-picky-bicon-picker]'), s.icon || '');
    pickyRenderLibEditParents($editor, s.sid, pickyLibParentSids(s));
    $editor.show();
  });

  $(document).on('click', '.picky-lib-row-delete', function(){
    var sid = $(this).data('sid');
    if (!confirm('Delete this service? It will be removed from all elements.')) return;
    pickyLibDeleteService(sid);
  });

  /* ===== Element editor chip multiselect ===== */

  function pickyParseServiceIds(attr) {
    if (!attr) return [];
    try { var p = JSON.parse(attr); return Array.isArray(p) ? p : []; } catch(e) { return []; }
  }

  function pickyRenderServiceIdChips(editor, element) {
    var library = pickyReadServicesLibrary();
    var selected = pickyParseServiceIds(element.attr('data-service-ids'));
    var $chips = editor.find('.picky-field-service-ids-chips');
    var $empty = editor.find('.picky-field-service-ids-empty');
    $chips.empty();
    if (library.length === 0) {
      $empty.show();
      return;
    }
    $empty.hide();

    // Split into parents and addons
    var parents = [];
    var addons  = [];
    for (var i = 0; i < library.length; i++) {
      if (pickyLibIsAddon(library[i])) { addons.push(library[i]); } else { parents.push(library[i]); }
    }

    // Build lookup: parentSid -> [addon, ...]
    var parentSidSet = {};
    for (var pp = 0; pp < parents.length; pp++) { parentSidSet[parents[pp].sid] = true; }
    var addonsByParent = {};
    var orphanAddons   = [];
    for (var a = 0; a < addons.length; a++) {
      var pids = pickyLibParentSids(addons[a]);
      var placed = false;
      for (var pi = 0; pi < pids.length; pi++) {
        if (parentSidSet[pids[pi]]) {
          if (!addonsByParent[pids[pi]]) { addonsByParent[pids[pi]] = []; }
          addonsByParent[pids[pi]].push(addons[a]);
          placed = true;
          // no break — addon may be assigned to multiple parents
        }
      }
      if (!placed) { orphanAddons.push(addons[a]); }
    }

    function makeChip(s, isAddon) {
      var checked = selected.indexOf(s.sid) >= 0 ? ' checked' : '';
      var $chip = $('<label class="picky-service-chip' + (isAddon ? ' picky-service-chip--addon' : '') + '"></label>');
      $chip.append('<input type="checkbox" class="picky-service-chip-checkbox" value="' + pickyEscAttr(s.sid) + '"' + checked + ' data-parent-sids=\'' + pickyEscAttr(JSON.stringify(pickyLibParentSids(s))) + '\'>');
      $chip.append('<span class="picky-service-chip-name">' + pickyEscHtml(s.name) + '</span>');
      return $chip;
    }

    function buildTriggerBadge($tr) {
      var count = $tr.closest('.picky-field-service-ids-chips').find('.picky-service-chip:not(.picky-service-chip--addon) .picky-service-chip-checkbox:checked').length;
      $tr.find('.picky-services-popup-trigger-count').remove();
      if (count > 0) {
        $tr.find('.picky-services-popup-trigger-arrow').before('<span class="picky-services-popup-trigger-count">' + count + '</span>');
      }
    }

    // Count selected parent services for initial trigger badge
    var selectedParentCount = 0;
    for (var sp = 0; sp < parents.length; sp++) {
      if (selected.indexOf(parents[sp].sid) >= 0) { selectedParentCount++; }
    }

    // Trigger button
    var $trigger = $('<button type="button" class="picky-services-popup-trigger"></button>');
    $trigger.append('<span class="picky-services-popup-trigger-label">Services</span>');
    if (selectedParentCount > 0) {
      $trigger.append('<span class="picky-services-popup-trigger-count">' + selectedParentCount + '</span>');
    }
    $trigger.append('<span class="picky-services-popup-trigger-arrow">▾</span>');
    $chips.append($trigger);

    // Popup panel
    var $popup = $('<div class="picky-services-popup"></div>');
    var $hdr = $('<div class="picky-services-popup-header"></div>');
    $hdr.append('<span class="picky-services-popup-title">Available Services</span>');
    var $closeBtn = $('<button type="button" class="picky-services-popup-close" title="Close">×</button>');
    $hdr.append($closeBtn);
    $popup.append($hdr);

    var $body = $('<div class="picky-services-popup-body"></div>');

    for (var j = 0; j < parents.length; j++) {
      var p = parents[j];
      var $row = $('<div class="picky-service-group"></div>');
      var $parentRow = $('<div class="picky-service-group-parent-row"></div>');
      $parentRow.append(makeChip(p, false));

      var groupAddons = addonsByParent[p.sid] || [];
      if (groupAddons.length > 0) {
        var addonChecked = 0;
        for (var ac = 0; ac < groupAddons.length; ac++) {
          if (selected.indexOf(groupAddons[ac].sid) >= 0) { addonChecked++; }
        }
        var $toggle = $('<button type="button" class="picky-service-addon-toggle" aria-expanded="false" title="' + pickyEscAttr(groupAddons.length + ' add-on(s)') + '">' +
          (addonChecked > 0 ? '<span class="picky-service-addon-toggle-count">' + addonChecked + '/' + groupAddons.length + '</span>' : '') +
          '<span class="picky-service-addon-toggle-arrow">▾</span>' +
          '</button>');
        $parentRow.append($toggle);

        var $addonGroup = $('<div class="picky-service-addon-group"></div>');
        for (var ag = 0; ag < groupAddons.length; ag++) {
          $addonGroup.append(makeChip(groupAddons[ag], true));
        }
        $toggle.on('click', (function($g, $t) {
          return function(e) {
            e.stopPropagation();
            var open = $t.attr('aria-expanded') === 'true';
            $t.attr('aria-expanded', open ? 'false' : 'true');
            $t.find('.picky-service-addon-toggle-arrow').text(open ? '▾' : '▴');
            $g.toggleClass('is-open', !open);
          };
        })($addonGroup, $toggle));

        $row.append($parentRow).append($addonGroup);
      } else {
        $row.append($parentRow);
      }
      $body.append($row);
    }

    if (orphanAddons.length > 0) {
      var $orphanGroup = $('<div class="picky-service-group picky-service-group--orphan"></div>');
      $orphanGroup.append('<div class="picky-service-group-orphan-label">Add-ons (parent removed)</div>');
      for (var ob = 0; ob < orphanAddons.length; ob++) {
        $orphanGroup.append(makeChip(orphanAddons[ob], true));
      }
      $body.append($orphanGroup);
    }

    $popup.append($body);
    $chips.append($popup);

    $popup.data('picky-chips-host', $chips);
    $popup.data('picky-edit-wrap', $chips.closest('.picky-element-edit-wrap'));

    function pickyCloseServicesPopup() {
      $popup.removeClass('is-open picky-services-popup--portaled');
      $trigger.removeClass('is-active');
      $trigger.find('.picky-services-popup-trigger-arrow').text('▾');
      $popup.css({ left: '', top: '', width: '', maxHeight: '', position: '' });
      if ($popup.parent()[0] !== $chips[0]) {
        $popup.appendTo($chips);
      }
    }

    $trigger.on('click', function(e) {
      e.stopPropagation();
      var opening = !$popup.hasClass('is-open');
      if (!opening) {
        pickyCloseServicesPopup();
        return;
      }
      $popup.addClass('is-open');
      $trigger.addClass('is-active');
      $trigger.find('.picky-services-popup-trigger-arrow').text('▴');
      buildTriggerBadge($trigger);
      /* Inspector uses transform + overflow:hidden — portal to body so popup is not clipped */
      if ($trigger.closest('#picky-element-inspector').length) {
        var r = $trigger[0].getBoundingClientRect();
        var pad = 8;
        var w = Math.min(320, Math.max(220, window.innerWidth - pad * 2));
        var left = Math.round(r.left);
        if (left + w > window.innerWidth - pad) {
          left = Math.max(pad, Math.round(window.innerWidth - pad - w));
        }
        var top = Math.round(r.bottom + 6);
        var maxH = Math.min(420, Math.max(160, window.innerHeight - top - pad));
        $popup
          .addClass('picky-services-popup--portaled')
          .appendTo(document.body)
          .css({
            position: 'fixed',
            left: left + 'px',
            top: top + 'px',
            width: w + 'px',
            maxHeight: maxH + 'px'
          });
      }
    });

    $closeBtn.on('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      pickyCloseServicesPopup();
    });

    /* Keep inspector open while interacting with the portaled popup */
    $popup.on('mousedown click', function(e) {
      e.stopPropagation();
    });
  }

  $(document).on('change', '.picky-service-chip-checkbox', function(){
    var $cb = $(this);
    var $popup = $cb.closest('.picky-services-popup');
    var editor = $cb.closest('.picky-element-edit-wrap');
    if ((!editor || !editor.length) && $popup.length) {
      editor = $popup.data('picky-edit-wrap') || $();
      if ((!editor || !editor.length) && $popup.data('picky-chips-host')) {
        editor = $popup.data('picky-chips-host').closest('.picky-element-edit-wrap');
      }
    }
    var element = getEditorTarget(editor.length ? editor[0] : this);
    if (!element || !element.length) return;

    var $scope = $popup.length ? $popup : editor;

    // Auto-add parent SIDs when an add-on is checked: without at least one of
    // its parents in the element's allowed list, the add-on would never appear
    // to customers (parent gate). This keeps the builder UX foolproof.
    if (this.checked) {
      var parentSidsAttr = $cb.attr('data-parent-sids') || '[]';
      var parentSids = [];
      try { parentSids = JSON.parse(parentSidsAttr) || []; } catch(e) { parentSids = []; }
      if (Array.isArray(parentSids) && parentSids.length > 0) {
        // Has any of its parents already been chosen on this element?
        var alreadyHasParent = false;
        for (var i = 0; i < parentSids.length; i++) {
          var $parentCb = $scope.find('.picky-service-chip-checkbox[value="' + parentSids[i] + '"]');
          if ($parentCb.length && $parentCb.is(':checked')) { alreadyHasParent = true; break; }
        }
        if (!alreadyHasParent) {
          // Tick the first available parent on this element, plus surface a soft notice.
          var addedName = '';
          for (var k = 0; k < parentSids.length; k++) {
            var $cb2 = $scope.find('.picky-service-chip-checkbox[value="' + parentSids[k] + '"]');
            if ($cb2.length) {
              $cb2.prop('checked', true);
              addedName = $cb2.closest('label').find('span').text().split(' (add-on')[0].trim();
              break;
            }
          }
          if (addedName && typeof window !== 'undefined' && window.console) {
            window.console.info('[Picky] Auto-enabled parent service "' + addedName + '" so the add-on can appear on the frontend.');
          }
        }
      }
    }

    var sids = [];
    $scope.find('.picky-service-chip-checkbox:checked').each(function(){ sids.push(String($(this).val())); });
    element.attr('data-service-ids', JSON.stringify(sids));

    // Refresh addon-count badges
    $scope.find('.picky-service-addon-toggle').each(function(){
      var $toggle = $(this);
      var $group  = $toggle.closest('.picky-service-group-parent-row').next('.picky-service-addon-group');
      if (!$group.length) { return; }
      var total = $group.find('.picky-service-chip-checkbox').length;
      var count = $group.find('.picky-service-chip-checkbox:checked').length;
      $toggle.find('.picky-service-addon-toggle-count').remove();
      if (count > 0) {
        $toggle.find('.picky-service-addon-toggle-arrow').before('<span class="picky-service-addon-toggle-count">' + count + '/' + total + '</span>');
      }
    });

    // Refresh trigger button count badge
    var $triggers = editor.find('.picky-services-popup-trigger');
    if (!$triggers.length && $popup.data('picky-chips-host')) {
      $triggers = $popup.data('picky-chips-host').find('.picky-services-popup-trigger');
    }
    $triggers.each(function() {
      var $tr = $(this);
      var count = $scope.find('.picky-service-chip:not(.picky-service-chip--addon) .picky-service-chip-checkbox:checked').length;
      $tr.find('.picky-services-popup-trigger-count').remove();
      if (count > 0) {
        $tr.find('.picky-services-popup-trigger-arrow').before('<span class="picky-services-popup-trigger-count">' + count + '</span>');
      }
    });
  });

  // Close services popup on outside click (outside inspector toolbar + popup), or via ×
  $(document).on('click.picky-addon-dd', function(e) {
    var $t = $(e.target);
    if ($t.closest(
      '.picky-services-popup, .picky-services-popup-trigger, .picky-field-service-ids-chips, ' +
      '#picky-element-inspector, #picky-builder-toolbar'
    ).length) {
      return;
    }
    $('.picky-services-popup.is-open').each(function() {
      var $popup = $(this);
      var $host = $popup.data('picky-chips-host');
      $popup.removeClass('is-open picky-services-popup--portaled')
        .css({ left: '', top: '', width: '', maxHeight: '', position: '' });
      if ($host && $host.length) {
        $popup.appendTo($host);
      }
    });
    $('.picky-services-popup-trigger.is-active')
      .removeClass('is-active')
      .find('.picky-services-popup-trigger-arrow').text('▾');
  });

  /* ===== End services helpers ===== */

  /**
   * Capture a list thumbnail of the builder canvas (JPEG data URI).
   * Uses CSS left/top/size (same as save coords) so zoom/scroll don't skew the shot.
   */
  function pickyCaptureLayoutPreview(){
    var $root = $('.picky-draggable').first();
    if(!$root.length){ return ''; }
    var node = $root.get(0);
    var rw = parseInt($root.css('width'), 10) || node.offsetWidth || 1100;
    var rh = parseInt($root.css('height'), 10) || node.offsetHeight || 900;
    rw = Math.max(100, rw);
    rh = Math.max(100, rh);
    var maxW = 480;
    var scale = Math.min(1, maxW / rw);
    var canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(rw * scale));
    canvas.height = Math.max(1, Math.round(rh * scale));
    var ctx = canvas.getContext('2d');
    if(!ctx){ return ''; }

    var cs = window.getComputedStyle(node);
    var bgColor = cs.backgroundColor || '#f3f4f6';
    if(!bgColor || bgColor === 'rgba(0, 0, 0, 0)' || bgColor === 'transparent'){
      bgColor = '#f3f4f6';
    }
    ctx.fillStyle = bgColor;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    $root.find('.picky-element').not('.picky-element-deleting').each(function(){
      var $el = $(this);
      var elType = ($el.attr('data-type') || '').toString();
      if(elType === 'type-text'){ return; }
      var $wrap = $el.find('.picky-element-table-wrap').first();
      var left = parseFloat($el.css('left')) || 0;
      var top = parseFloat($el.css('top')) || 0;
      var w = ($wrap.length ? $wrap.outerWidth(true) : $el.outerWidth(true)) || 20;
      var h = ($wrap.length ? $wrap.outerHeight(true) : $el.outerHeight(true)) || 20;
      var x = left * scale;
      var y = top * scale;
      w = Math.max(1, w * scale);
      h = Math.max(1, h * scale);
      var color = ($el.attr('data-color') || '').toString().trim();
      if(!color){
        color = elType === 'type-wall' ? '#9ca3af' : '#c5bedc';
      }
      var opacity = parseFloat($el.attr('data-opacity') || '1');
      if(isNaN(opacity)){ opacity = 1; }
      opacity = Math.max(0.2, Math.min(1, opacity));

      var rx = 4 * scale;
      if(elType === 'type-circle' || ($el.attr('data-shape-type') || '') === 'circle'){
        rx = Math.max(w, h);
      } else {
        var sr = parseInt($el.attr('data-square-radius') || $el.attr('data-shape-radius') || '0', 10);
        if(!isNaN(sr) && sr > 0){ rx = Math.min(w, h, sr * scale); }
      }

      var rz = parseFloat($el.attr('data-rotate-z') || '0');
      if(isNaN(rz)){ rz = 0; }

      ctx.save();
      ctx.globalAlpha = opacity;
      ctx.fillStyle = color;
      if(Math.abs(rz) > 0.01){
        ctx.translate(x + w / 2, y + h / 2);
        ctx.rotate(rz * Math.PI / 180);
        x = -w / 2;
        y = -h / 2;
      }
      if(typeof ctx.roundRect === 'function'){
        ctx.beginPath();
        ctx.roundRect(x, y, w, h, rx);
        ctx.fill();
      } else {
        ctx.fillRect(x, y, w, h);
      }
      ctx.restore();
    });

    try {
      return canvas.toDataURL('image/jpeg', 0.72);
    } catch (eOut) {
      return '';
    }
  }

  function pickyDataUrlToBlob(dataUrl){
    try {
      var parts = String(dataUrl || '').split(',');
      if(parts.length < 2){ return null; }
      var meta = parts[0] || '';
      var mimeMatch = meta.match(/data:([^;]+);/);
      var mime = mimeMatch ? mimeMatch[1] : 'image/jpeg';
      var bin = atob(parts[1]);
      var len = bin.length;
      var arr = new Uint8Array(len);
      for (var i = 0; i < len; i++){ arr[i] = bin.charCodeAt(i); }
      return new Blob([arr], { type: mime });
    } catch (e) {
      return null;
    }
  }

  function pickyUploadLayoutPreview(done){
    var finish = (typeof done === 'function') ? done : function(){};
    var layoutId = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.layout_id) ? parseInt(pickyLayoutData.layout_id, 10) : 0;
    if(!layoutId){ finish(false); return; }
    var dataUrl = '';
    try { dataUrl = pickyCaptureLayoutPreview(); } catch (eCap) { dataUrl = ''; }
    if(!dataUrl){ finish(false); return; }

    var ajaxUrl = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.ajax_url) ? pickyLayoutData.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.nonce_layout_preview) ? pickyLayoutData.nonce_layout_preview : '';
    if(!ajaxUrl || !nonce){ finish(false); return; }

    var blob = pickyDataUrlToBlob(dataUrl);
    var fd = new FormData();
    fd.append('action', 'picky_save_layout_preview');
    fd.append('layout_id', String(layoutId));
    fd.append('_wpnonce', nonce);
    if(blob){
      fd.append('preview', blob, 'layout-preview.jpg');
    } else {
      fd.append('preview_data', dataUrl);
    }

    $.ajax({
      url: ajaxUrl,
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      timeout: 30000
    }).done(function(){ finish(true); }).fail(function(){ finish(false); });
  }

  $('#options-save, #picky-options-save').on('submit',function(e){
    function pickySetLayoutSaveBusy(busy) {
      $('.picky-save-btn').each(function(){
        var $btn = $(this);
        var $label = $btn.find('.picky-save-btn__label');
        if (!$btn.data('pickySaveLabel')) {
          $btn.data('pickySaveLabel', ($label.text() || $btn.attr('data-label') || '').toString());
        }
        if (busy) {
          $btn.addClass('is-saving').prop('disabled', true).attr('aria-busy', 'true');
          if ($label.length) {
            $label.text($btn.attr('data-saving-label') || 'Saving…');
          }
        } else {
          $btn.removeClass('is-saving').prop('disabled', false).attr('aria-busy', 'false');
          if ($label.length) {
            $label.text($btn.data('pickySaveLabel'));
          }
        }
      });
    }

    pickySetLayoutSaveBusy(true);

    // Always build from the clean base URL so layout_id (or other builder params) never
    // leak into _wp_http_referer and send WordPress back to the Layout Builder after save.
    pickyPersistSettingsTab(pickyCurrentSettingsTab(), { fromHeaderClick: true });
    // Defensive sync: ensure background hidden values match selected previews before saving.
    $('.picky-background-field').each(function(){
      var $wrap = $(this);
      var key = $wrap.attr('data-background');
      // Only image-background fields. Other panels reuse this class and their
      // hidden inputs (checkboxes, etc.) must not be overwritten with a CSS url.
      if(key !== 'background-canvas' && key !== 'background-table'){
        return;
      }
      var $hidden = (function(){
        if(key === 'background-canvas'){
          return $wrap.find('input[name="picky_layout_bg_canvas"], input[name="picky_background_canvas"]').first();
        }
        return $wrap.find('input[name="picky_layout_bg_table"], input[name="picky_background_table"]').first();
      })();
      if($hidden.length === 0){ return; }
      var url = '';
      var bg = $wrap.find('.picky-background-image-holder-wrap').css('background-image') || '';
      if(bg && bg !== 'none'){
        var m = bg.match(/url\((['"]?)(.*?)\1\)/i);
        if(m && m[2]){
          url = m[2];
        }
      }
      if(!url && key === 'background-canvas'){
        var canvasBg = $('.picky-draggable').css('background-image') || '';
        if(canvasBg && canvasBg !== 'none'){
          var mc = canvasBg.match(/url\((['"]?)(.*?)\1\)/i);
          if(mc && mc[2]){
            url = mc[2];
          }
        }
      }
      $hidden.val(url);
      if(key === 'background-canvas'){
        $('input[name="picky_background_canvas"], input[name="picky_layout_bg_canvas"]').val(url);
      }
      if(key === 'background-table'){
        $('input[name="picky_background_table"], input[name="picky_layout_bg_table"]').val(url);
      }
    });

    // Pre-save: check for duplicate display IDs across all bookable canvas elements.
    var pickySaveIdSeen = {};
    var pickySaveDuplicates = [];
    $('.picky-draggable .picky-element').not('.picky-element-deleting').each(function(){
      var $el = $(this);
      var elType = ($el.attr('data-type') || '').toString();
      if(elType === 'type-wall' || elType === 'type-text' || elType === 'type-shape' || elType === 'type-icon'){ return; }
      var did = pickyGetDisplayId($el);
      if(did === ''){ return; }
      if(pickySaveIdSeen[did]){
        if(pickySaveDuplicates.indexOf(did) === -1){ pickySaveDuplicates.push(did); }
      } else {
        pickySaveIdSeen[did] = true;
      }
    });
    if(pickySaveDuplicates.length > 0){
      pickySetLayoutSaveBusy(false);
      alert('Cannot save: the following IDs are used by more than one element — ' + pickySaveDuplicates.join(', ') + '.\n\nEach bookable element must have a unique ID. Please open the affected elements and assign distinct Custom IDs.');
      return false;
    }

    var coordinates_save = new Array();
    var elements_save = [];
    $('.picky-draggable .picky-element').not('.picky-element-deleting').each(function(e){
      elements_save.push($(this));
    });
    for (var k = 0; k <= elements_save.length - 1; k++) {
      var type;
      var classList = $(elements_save[k]).attr('class').split(/\s+/);
      $.each(classList, function(index, item) {
        if (item.toLowerCase().indexOf("type-") >= 0){
          type = item;
        }
      });

        coordinates_save[k] = {
        x: parseFloat($(elements_save[k]).css('left')) || 0,
        y: parseFloat($(elements_save[k]).css('top')) || 0,
        // Persist size based on actual wrap box
        x1: (parseFloat($(elements_save[k]).css('left')) || 0) + $(elements_save[k]).find('.picky-element-table-wrap').outerWidth(true),
        y1: (parseFloat($(elements_save[k]).css('top')) || 0) + $(elements_save[k]).find('.picky-element-table-wrap').outerHeight(true),
        id: $(elements_save[k]).attr('data-id'),
        unique: $(elements_save[k]).attr('data-unique'),
        class: $(elements_save[k]).attr('data-type'),
        z_index: (function(){
          var dz = parseInt($(elements_save[k]).attr('data-z-index'), 10);
          if(!isNaN(dz) && dz > 0){ return dz; }
          var cz = parseInt($(elements_save[k]).css('z-index'), 10);
          if(!isNaN(cz) && cz > 0){ return cz; }
          return 10 + k;
        })(),
        rotate: $(elements_save[k]).attr('data-rotate'),
        rotate_z: $(elements_save[k]).attr('data-rotate-z') || '0',
        rotate_x: $(elements_save[k]).attr('data-rotate-x') || '0',
        rotate_y: $(elements_save[k]).attr('data-rotate-y') || '0',
        big_side: $(elements_save[k]).attr('data-big-side'),
        seats: $(elements_save[k]).attr('data-seats'),
        max_seats: $(elements_save[k]).attr('data-max-seats'),
        label: $(elements_save[k]).attr('data-label') || '',
        label_position: pickyNormalizeLabelPosition($(elements_save[k]).attr('data-label-position')),
        label_font_size: $(elements_save[k]).attr('data-label-font-size') || '12',
        label_color: $(elements_save[k]).attr('data-label-color') || '#333333',
        label_font_weight: $(elements_save[k]).attr('data-label-font-weight') || 'normal',
        show_id: ($(elements_save[k]).attr('data-show-id') === '0') ? '0' : '1',
        custom_id: $(elements_save[k]).attr('data-custom-id') || '',
        custom_url: $(elements_save[k]).attr('data-custom-url') || '',
        color: $(elements_save[k]).attr('data-color') || '',
        opacity: $(elements_save[k]).attr('data-opacity') || '1',
        text: $(elements_save[k]).attr('data-text') || '',
        text_color: $(elements_save[k]).attr('data-text-color') || '',
        text_color_mode: ($(elements_save[k]).attr('data-text-color-mode') === 'theme') ? 'theme' : 'custom',
        font_size: $(elements_save[k]).attr('data-font-size') || '16',
        line_height: (function(){
          var r = ($(elements_save[k]).attr('data-line-height') || '').toString().trim();
          if(!r){ return ''; }
          var n = parseInt(r, 10);
          if(isNaN(n) || n < 8){ return ''; }
          return String(Math.min(400, n));
        })(),
        font_family: $(elements_save[k]).attr('data-font-family') || 'inherit',
        font_weight: $(elements_save[k]).attr('data-font-weight') || 'normal',
        shape_type: $(elements_save[k]).attr('data-shape-type') || 'rectangle',
        shape_radius: $(elements_save[k]).attr('data-shape-radius') || '',
        shape_border_width: $(elements_save[k]).attr('data-shape-border-width') || '0',
        shape_border_color: $(elements_save[k]).attr('data-shape-border-color') || '',
        icon_key: $(elements_save[k]).attr('data-icon') || '',
        square_radius: (function(){
          var r = ($(elements_save[k]).attr('data-square-radius') || '').toString().trim();
          var n = parseInt(r, 10);
          if(!r || isNaN(n) || n <= 0){ return ''; }
          return String(Math.min(120, n));
        })(),
        wall_radius: (function(){
          if(($(elements_save[k]).attr('data-type') || '') !== 'type-wall' && !$(elements_save[k]).hasClass('type-wall')){
            return '';
          }
          var r = ($(elements_save[k]).attr('data-wall-radius') || '').toString().trim();
          if(r === ''){ return ''; }
          var n = parseInt(r, 10);
          if(isNaN(n) || n < 0){ return ''; }
          return String(Math.min(120, n));
        })(),
        padding: $(elements_save[k]).attr('data-padding') || '10',
        service_ids: pickyParseServiceIds($(elements_save[k]).attr('data-service-ids')),
      }
    }
    var $form = $(this);
    $form.find('input[name="picky_layout_chunks[]"]').remove();
    if(coordinates_save.length > 0){
      var json_coordinates = JSON.stringify(coordinates_save);
      var encodedCoordinates = encodeURIComponent(json_coordinates);
      // Split long payload into chunks to avoid host-level input value truncation.
      var chunkSize = 700;
      if(encodedCoordinates.length > chunkSize){
        $form.find('input[name="picky_layout_position"]').val('__chunked__');
        for (var i = 0; i < encodedCoordinates.length; i += chunkSize) {
          $('<input>', {
            type: 'hidden',
            name: 'picky_layout_chunks[]',
            value: encodedCoordinates.substring(i, i + chunkSize)
          }).appendTo($form);
        }
      } else {
        $form.find('input[name="picky_layout_position"]').val(encodedCoordinates);
      }
    } else {
      $form.find('input[name="picky_layout_position"]').val('');
    }

    // Upload a fresh list thumbnail via AJAX (avoids bloating the main save POST), then continue.
    if($form.attr('id') === 'picky-options-save' && !$form.data('pickyPreviewAttached')){
      e.preventDefault();
      $form.data('pickyPreviewAttached', true);
      pickyUploadLayoutPreview(function(){
        $form.trigger('submit');
      });
      return false;
    }

    return true;

  });


  $(document).on('click', '.picky-test-integration-btn', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var integration = '';
    if ($btn.hasClass('picky-test-integration-btn--mailchimp')) { integration = 'mailchimp'; }
    else if ($btn.hasClass('picky-test-integration-btn--mailchimp_subscriber')) { integration = 'mailchimp_subscriber'; }
    else if ($btn.hasClass('picky-test-integration-btn--hubspot')) { integration = 'hubspot'; }
    else if ($btn.hasClass('picky-test-integration-btn--zapier')) { integration = 'zapier'; }
    else if ($btn.hasClass('picky-test-integration-btn--n8n')) { integration = 'n8n'; }
    else if ($btn.hasClass('picky-test-integration-btn--make')) { integration = 'make'; }
    else if ($btn.hasClass('picky-test-integration-btn--gcal')) { integration = 'gcal'; }
    else if ($btn.hasClass('picky-test-integration-btn--ai') || $btn.hasClass('picky-test-integration-btn--openai') || $btn.hasClass('picky-test-integration-btn--groq')) {
      integration = 'ai';
    }

    var $result = $btn.siblings('.picky-test-integration-result');
    
    var data = {
      action: 'picky_test_integration',
      integration: integration,
      _wpnonce: (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.nonce_integration_test) ? pickyLayoutData.nonce_integration_test : ''
    };

    var $pickySettingsForm = $('#options-save');
    function getVal(slug) {
      var $field = $pickySettingsForm.length ? $pickySettingsForm.find('[name$="[' + slug + ']"]') : $('[name$="[' + slug + ']"]');
      return $field.length ? $field.first().val() : '';
    }
    
    if (integration === 'mailchimp' || integration === 'mailchimp_subscriber') {
      data.api_key = getVal('mailchimp_api_key');
      data.list_id = getVal('mailchimp_list_id');
    } else if (integration === 'hubspot') {
      data.access_token = getVal('hubspot_access_token');
    } else if (integration === 'zapier') {
      data.webhook_url = getVal('zapier_webhook_url');
    } else if (integration === 'n8n') {
      data.webhook_url = getVal('n8n_webhook_url');
    } else if (integration === 'make') {
      data.webhook_url = getVal('make_webhook_url');
    } else if (integration === 'gcal') {
      data.service_account_json = getVal('gcal_service_account_json');
      data.calendar_id = getVal('gcal_calendar_id');
    } else if (integration === 'ai') {
      data.provider = getVal('ai_provider') || 'groq';
      data.api_key = (data.provider === 'openai') ? getVal('ai_openai_key') : getVal('ai_groq_key');
      data.model = getVal('ai_model');
    }

    $result.text('Testing...').css('color', '#666');
    $btn.prop('disabled', true);
    
    $.post(ajaxurl, data, function(response) {
      $btn.prop('disabled', false);
      if (response.success) {
        $result.text('✅ ' + response.data).css('color', 'green');
      } else {
        $result.text('❌ ' + (response.data || 'Unknown error')).css('color', '#d63638');
      }
    }).fail(function() {
      $btn.prop('disabled', false);
      $result.text('❌ Server error').css('color', '#d63638');
    });
  });


  /* ─── Embedded editor: quick-status pill + AJAX save ─── */
  function pickyOeStatusI18n(key, fallback) {
    var i18n = (typeof pickyAdminI18n !== 'undefined') ? pickyAdminI18n : {};
    return (i18n && i18n[key]) ? i18n[key] : fallback;
  }

  function pickyOeSyncStatusUi($form, status, labelText) {
    var $editor = $form.find('.picky-order-editor').first();
    if (!$editor.length) { $editor = $form.closest('.picky-order-editor'); }
    var $select = $form.find('.picky-oe-status-select').first();
    if ($select.length) { $select.val(status); }

    $form.find('.picky-oe-status-pills [data-picky-status-pill]')
      .removeClass('is-active')
      .filter('[data-picky-status-pill="' + status + '"]')
      .addClass('is-active');

    var $badge = $editor.find('.picky-oe-status-badge').first();
    if ($badge.length) {
      var cls = ($badge.attr('class') || '').replace(/\bpicky-oe-status-badge--\S+/g, '').trim();
      $badge.attr('class', cls + ' picky-oe-status-badge--' + status);
      var text = labelText || '';
      if (!text && $select.length) {
        text = $.trim($select.find('option:selected').text());
      }
      $badge.find('.picky-oe-status-badge__dot').nextAll().remove();
      if (text) {
        $badge.append(document.createTextNode(' ' + text));
      }
    }
  }

  function pickyOeSetStatusFeedback($form, state, message) {
    var $feedback = $form.find('.picky-oe-status-feedback').first();
    if (!$feedback.length) { return; }
    $feedback.removeClass('is-saving is-saved is-error');
    if (state) { $feedback.addClass('is-' + state); }
    $feedback.text(message || '');
  }

  function pickyOeSetStatusBusy($form, busy) {
    $form.find('.picky-oe-status-pills').toggleClass('is-busy', !!busy);
    $form.find('.picky-oe-status-select').prop('disabled', !!busy);
  }

  function pickyOePersistStatus($form, status) {
    var entity = ($form.attr('data-picky-status-entity') || '').toString();
    var entityId = parseInt($form.attr('data-picky-entity-id') || '0', 10);
    var nonce = ($form.attr('data-picky-status-nonce') || '').toString();
    var savedStatus = ($form.attr('data-picky-saved-status') || '').toString();
    if (!entity || entityId <= 0 || !status) { return; }
    if (status === savedStatus) {
      pickyOeSetStatusFeedback($form, '', '');
      return;
    }

    var ajaxUrl = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.ajax_url)
      ? pickyLayoutData.ajax_url
      : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    if (!ajaxUrl) { return; }

    var action = entity === 'booking' ? 'picky_save_booking_status' : 'picky_save_order_status';
    var payload = {
      action: action,
      nonce: nonce,
      post_status: status
    };
    if (entity === 'booking') {
      payload.booking_id = entityId;
    } else {
      payload.order_id = entityId;
    }

    pickyOeSetStatusBusy($form, true);
    pickyOeSetStatusFeedback($form, 'saving', pickyOeStatusI18n('status_saving', 'Saving status…'));

    $.post(ajaxUrl, payload).done(function (res) {
      if (res && res.success) {
        var nextStatus = (res.data && res.data.status) ? res.data.status : status;
        var nextLabel = (res.data && res.data.label) ? res.data.label : '';
        $form.attr('data-picky-saved-status', nextStatus);
        pickyOeSyncStatusUi($form, nextStatus, nextLabel);
        pickyOeSetStatusFeedback($form, 'saved', pickyOeStatusI18n('status_saved', 'Status saved'));
        window.setTimeout(function () {
          if (($form.attr('data-picky-saved-status') || '') === nextStatus) {
            pickyOeSetStatusFeedback($form, '', '');
          }
        }, 2500);
      } else {
        var errMsg = (res && res.data && res.data.message) ? res.data.message : pickyOeStatusI18n('status_error', 'Could not save status. Try again.');
        pickyOeSyncStatusUi($form, savedStatus);
        pickyOeSetStatusFeedback($form, 'error', errMsg);
      }
    }).fail(function () {
      pickyOeSyncStatusUi($form, savedStatus);
      pickyOeSetStatusFeedback($form, 'error', pickyOeStatusI18n('status_error', 'Could not save status. Try again.'));
    }).always(function () {
      pickyOeSetStatusBusy($form, false);
    });
  }

  $(document).on('click', '[data-picky-status-pill]', function () {
    var $pill = $(this);
    var status = $pill.attr('data-picky-status-pill') || '';
    if (!status) { return; }

    var $form = $pill.closest('.picky-oe-status-form');
    pickyOeSyncStatusUi($form, status, $.trim($pill.text()));
    pickyOePersistStatus($form, status);
  });

  $(document).on('change', '.picky-oe-status-form .picky-oe-status-select', function () {
    var $select = $(this);
    var status = ($select.val() || '').toString();
    var $form = $select.closest('.picky-oe-status-form');
    pickyOeSyncStatusUi($form, status);
    pickyOePersistStatus($form, status);
  });

  /* ─── Embedded booking editor: payment status AJAX save ─── */
  function pickyOeSyncPaymentUi($wrap, status) {
    var $select = $wrap.find('.picky-oe-payment-select').first();
    if ($select.length) { $select.val(status); }
    $wrap.find('.picky-oe-payment-pills [data-picky-payment-pill]')
      .removeClass('is-active')
      .filter('[data-picky-payment-pill="' + status + '"]')
      .addClass('is-active');
  }

  function pickyOeSetPaymentFeedback($wrap, state, message) {
    var $feedback = $wrap.find('.picky-oe-payment-feedback').first();
    if (!$feedback.length) { return; }
    $feedback.removeClass('is-saving is-saved is-error');
    if (state) { $feedback.addClass('is-' + state); }
    $feedback.text(message || '');
  }

  function pickyOePersistPayment($wrap, status) {
    var bookingId = parseInt($wrap.attr('data-picky-payment-booking-id') || '0', 10);
    var nonce = ($wrap.attr('data-picky-payment-nonce') || '').toString();
    var saved = ($wrap.attr('data-picky-saved-payment') || '').toString();
    if (bookingId <= 0 || !status) { return; }
    if (status === saved) {
      pickyOeSetPaymentFeedback($wrap, '', '');
      return;
    }

    var ajaxUrl = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.ajax_url)
      ? pickyLayoutData.ajax_url
      : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    if (!ajaxUrl) { return; }

    $wrap.find('.picky-oe-payment-pills').addClass('is-busy');
    $wrap.find('.picky-oe-payment-select').prop('disabled', true);
    pickyOeSetPaymentFeedback($wrap, 'saving', pickyOeStatusI18n('payment_saving', 'Saving payment…'));

    $.post(ajaxUrl, {
      action: 'picky_save_booking_payment',
      nonce: nonce,
      booking_id: bookingId,
      payment_status: status
    }).done(function (res) {
      if (res && res.success) {
        var next = (res.data && res.data.status) ? res.data.status : status;
        $wrap.attr('data-picky-saved-payment', next);
        pickyOeSyncPaymentUi($wrap, next);
        pickyOeSetPaymentFeedback($wrap, 'saved', pickyOeStatusI18n('payment_saved', 'Payment status saved'));
        window.setTimeout(function () {
          if (($wrap.attr('data-picky-saved-payment') || '') === next) {
            pickyOeSetPaymentFeedback($wrap, '', '');
          }
        }, 2500);
      } else {
        var errMsg = (res && res.data && res.data.message) ? res.data.message : pickyOeStatusI18n('payment_error', 'Could not save payment status. Try again.');
        pickyOeSyncPaymentUi($wrap, saved);
        pickyOeSetPaymentFeedback($wrap, 'error', errMsg);
      }
    }).fail(function () {
      pickyOeSyncPaymentUi($wrap, saved);
      pickyOeSetPaymentFeedback($wrap, 'error', pickyOeStatusI18n('payment_error', 'Could not save payment status. Try again.'));
    }).always(function () {
      $wrap.find('.picky-oe-payment-pills').removeClass('is-busy');
      $wrap.find('.picky-oe-payment-select').prop('disabled', false);
    });
  }

  $(document).on('click', '[data-picky-payment-pill]', function () {
    var $pill = $(this);
    var status = $pill.attr('data-picky-payment-pill') || '';
    if (!status) { return; }
    var $wrap = $pill.closest('.picky-oe-payment-field');
    pickyOeSyncPaymentUi($wrap, status);
    pickyOePersistPayment($wrap, status);
  });

  $(document).on('change', '.picky-oe-payment-select', function () {
    var $select = $(this);
    var status = ($select.val() || '').toString();
    var $wrap = $select.closest('.picky-oe-payment-field');
    pickyOeSyncPaymentUi($wrap, status);
    pickyOePersistPayment($wrap, status);
  });


  /* ─── AI Layout / Copy ─── */
  function pickyAiEnabled(){
    return (typeof pickyLayoutData !== 'undefined' && Number(pickyLayoutData.ai_enabled) === 1);
  }
  function pickyAiStatus(){
    if (typeof pickyLayoutData === 'undefined') { return 'setup'; }
    return (pickyLayoutData.ai_status || (pickyAiEnabled() ? 'ready' : 'setup')).toString();
  }
  function pickyAiMsg(key, fallback){
    var i18n = (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.i18n_ai) ? pickyLayoutData.i18n_ai : {};
    return (i18n && i18n[key]) ? i18n[key] : (fallback || '');
  }
  function pickyAiAjaxUrl(){
    if (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.ajax_url) { return pickyLayoutData.ajax_url; }
    return (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
  }
  function pickyAiIntegrationsUrl(){
    if (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.ai_integrations_url) {
      return pickyLayoutData.ai_integrations_url;
    }
    return 'admin.php?page=picky_settings&picky-tab=12';
  }
  function pickyAiPost(action, payload){
    var data = $.extend({}, payload || {}, {
      action: action,
      _wpnonce: (typeof pickyLayoutData !== 'undefined' && pickyLayoutData.nonce_ai) ? pickyLayoutData.nonce_ai : ''
    });
    return $.post(pickyAiAjaxUrl(), data);
  }
  function pickyAiErr(res){
    if (res && res.data) {
      if (typeof res.data === 'string') { return res.data; }
      if (res.data.message) { return res.data.message; }
    }
    return pickyAiMsg('error', 'AI request failed.');
  }
  function pickyAiLooksLikeBilling(msg){
    var s = (msg || '').toString().toLowerCase();
    return s.indexOf('credit') >= 0
      || s.indexOf('billing') >= 0
      || s.indexOf('quota') >= 0
      || s.indexOf('insufficient') >= 0
      || s.indexOf('rate limit') >= 0
      || s.indexOf('rate-limit') >= 0;
  }

  function pickyAiApplyConnBanner(status){
    var st = (status || pickyAiStatus()).toString();
    var $b = $('#picky-ai-conn-banner');
    if (!$b.length) { return; }
    var title = '';
    var text = '';
    if (st === 'ready') {
      title = pickyAiMsg('statusReadyTitle');
      text = pickyAiMsg('statusReadyText');
    } else if (st === 'billing') {
      title = pickyAiMsg('statusBillingTitle');
      text = pickyAiMsg('statusBillingText');
    } else if (st === 'disabled') {
      title = pickyAiMsg('statusDisabledTitle');
      text = pickyAiMsg('statusDisabledText');
    } else if (st === 'no_key') {
      title = pickyAiMsg('statusNoKeyTitle');
      text = pickyAiMsg('statusNoKeyText');
    } else {
      st = 'setup';
      title = pickyAiMsg('statusSetupTitle');
      text = pickyAiMsg('statusSetupText');
    }
    $b.attr('data-status', st);
    $b.find('.picky-ai-conn__title').text(title);
    $b.find('.picky-ai-conn__text').text(text);
    var $link = $b.find('.picky-ai-conn__link');
    $link.attr('href', pickyAiIntegrationsUrl()).text(pickyAiMsg('openSettings'));
    $link.toggle(st !== 'ready');
    /* Keep Generate clickable when ready or after a billing warning (retry after topping up). */
    var canGenerate = (st === 'ready' || st === 'billing');
    $('#picky-ai-layout-generate').prop('disabled', !canGenerate);
    $('body').toggleClass('picky-ai-enabled', st === 'ready' || st === 'billing');
  }

  function pickyAiChatStorageKey(){
    var id = (typeof pickyLayoutData !== 'undefined') ? parseInt(pickyLayoutData.layout_id, 10) : 0;
    return id > 0 ? ('picky_ai_layout_chat_' + id) : 'picky_ai_layout_chat_0';
  }
  function pickyAiChatDefaultWelcome(){
    return pickyAiMsg(
      'chatWelcome',
      'Describe rooms and zones. I’ll draft a neat floor plan like Coworking Floor 2: walls, furniture, and matching bookable stations — not a rainbow grid.'
    );
  }
  function pickyAiChatLoadState(){
    var empty = { messages: [{ role: 'bot', text: pickyAiChatDefaultWelcome() }], draft: '' };
    try {
      if (!window.localStorage) { return empty; }
      var raw = localStorage.getItem(pickyAiChatStorageKey());
      if (!raw) { return empty; }
      var parsed = JSON.parse(raw);
      if (!parsed || !Array.isArray(parsed.messages) || !parsed.messages.length) {
        return empty;
      }
      var msgs = [];
      for (var i = 0; i < parsed.messages.length && i < 80; i++) {
        var m = parsed.messages[i];
        if (!m || (m.role !== 'user' && m.role !== 'bot')) { continue; }
        var t = (m.text || '').toString();
        if (!t) { continue; }
        msgs.push({ role: m.role, text: t.substring(0, 4000) });
      }
      if (!msgs.length) { return empty; }
      return {
        messages: msgs,
        draft: (parsed.draft || '').toString().substring(0, 2000)
      };
    } catch (e) {
      return empty;
    }
  }
  function pickyAiChatSaveState(state){
    try {
      if (!window.localStorage || !state) { return; }
      localStorage.setItem(pickyAiChatStorageKey(), JSON.stringify({
        messages: (state.messages || []).slice(-80),
        draft: (state.draft || '').toString().substring(0, 2000),
        updated: Date.now()
      }));
    } catch (e) { /* quota / private mode */ }
  }
  function pickyAiChatGetStateFromDom(){
    var messages = [];
    $('#picky-ai-chat .picky-ai-chat__msg').each(function(){
      var role = $(this).hasClass('picky-ai-chat__msg--user') ? 'user' : 'bot';
      var text = $(this).find('.picky-ai-chat__bubble').first().text();
      if (text) { messages.push({ role: role, text: text }); }
    });
    if (!messages.length) {
      messages = [{ role: 'bot', text: pickyAiChatDefaultWelcome() }];
    }
    return {
      messages: messages,
      draft: ($('#picky-ai-layout-prompt').val() || '').toString()
    };
  }
  function pickyAiChatPersist(){
    pickyAiChatSaveState(pickyAiChatGetStateFromDom());
  }
  function pickyAiChatRender(state){
    var $chat = $('#picky-ai-chat');
    if (!$chat.length) { return; }
    var messages = (state && state.messages && state.messages.length)
      ? state.messages
      : [{ role: 'bot', text: pickyAiChatDefaultWelcome() }];
    $chat.empty();
    for (var i = 0; i < messages.length; i++) {
      var m = messages[i];
      var isUser = m.role === 'user';
      var $row = $('<div class="picky-ai-chat__msg" />').addClass(isUser ? 'picky-ai-chat__msg--user' : 'picky-ai-chat__msg--bot');
      $row.append($('<div class="picky-ai-chat__bubble" />').text(m.text));
      $chat.append($row);
    }
    if (state && typeof state.draft === 'string') {
      $('#picky-ai-layout-prompt').val(state.draft);
    }
    $chat.scrollTop($chat[0].scrollHeight);
  }
  function pickyAiChatClear(){
    var state = {
      messages: [{ role: 'bot', text: pickyAiChatDefaultWelcome() }],
      draft: ''
    };
    pickyAiChatSaveState(state);
    pickyAiChatRender(state);
    $('#picky-ai-layout-status').text('').removeClass('is-error is-ok');
  }

  function pickyAiChatAppend(role, text, opts){
    var $chat = $('#picky-ai-chat');
    if (!$chat.length || !text) { return; }
    opts = opts || {};
    /* Replace last bot bubble when updating "Generating…" → result. */
    if (opts.replaceLastBot) {
      var $last = $chat.children('.picky-ai-chat__msg--bot').last();
      if ($last.length) {
        $last.find('.picky-ai-chat__bubble').text(text);
        $chat.scrollTop($chat[0].scrollHeight);
        pickyAiChatPersist();
        return;
      }
    }
    var isUser = role === 'user';
    var $row = $('<div class="picky-ai-chat__msg" />').addClass(isUser ? 'picky-ai-chat__msg--user' : 'picky-ai-chat__msg--bot');
    var $bubble = $('<div class="picky-ai-chat__bubble" />').text(text);
    $row.append($bubble);
    $chat.append($row);
    $chat.scrollTop($chat[0].scrollHeight);
    pickyAiChatPersist();
  }

  function pickyAiGateUi(){
    pickyAiApplyConnBanner(pickyAiStatus());
  }

  function pickyAiRefreshStatus(done){
    pickyAiPost('picky_ai_status', {}).done(function(res){
      if (res && res.success && res.data) {
        if (typeof pickyLayoutData === 'undefined') {
          window.pickyLayoutData = {};
        }
        pickyLayoutData.ai_enabled = Number(res.data.ai_enabled) === 1 ? 1 : 0;
        pickyLayoutData.ai_status = res.data.ai_status || 'setup';
        pickyLayoutData.ai_provider = res.data.ai_provider || 'groq';
        if (res.data.statusReadyText && pickyLayoutData.i18n_ai) {
          pickyLayoutData.i18n_ai.statusReadyText = res.data.statusReadyText;
        }
        pickyAiApplyConnBanner(pickyLayoutData.ai_status);
      }
      if (typeof done === 'function') { done(); }
    }).fail(function(){
      pickyAiApplyConnBanner(pickyAiStatus());
      if (typeof done === 'function') { done(); }
    });
  }

  function pickyAiOpenLayoutModal(){
    var $m = $('#picky-ai-layout-modal');
    if (!$m.length) { return; }
    /* Portal to body so fixed modal is never clipped by builder overflow. */
    if (!$m.parent().is('body')) {
      $m.appendTo(document.body);
    }
    pickyAiChatRender(pickyAiChatLoadState());
    pickyAiApplyConnBanner(pickyAiStatus());
    $m.removeAttr('hidden').attr('aria-hidden', 'false');
    $('body').addClass('picky-ai-modal-open');
    $('#picky-ai-layout-status').text('').removeClass('is-error is-ok');
    pickyAiRefreshStatus(function(){
      setTimeout(function(){ $('#picky-ai-layout-prompt').trigger('focus'); }, 30);
    });
  }
  function pickyAiCloseLayoutModal(){
    pickyAiChatPersist();
    var $m = $('#picky-ai-layout-modal');
    $m.attr('hidden', true).attr('aria-hidden', 'true');
    $('body').removeClass('picky-ai-modal-open');
    $('#picky-ai-layout-status').text('').removeClass('is-error is-ok');
  }

  $(function(){ pickyAiGateUi(); });

  $(document).on('click', '.picky-ai-layout-btn', function(e){
    e.preventDefault();
    pickyAiOpenLayoutModal();
  });

  $(document).on('click', '#picky-ai-layout-modal [data-picky-ai-close]', function(e){
    e.preventDefault();
    pickyAiCloseLayoutModal();
  });

  $(document).on('click', '#picky-ai-chat-clear', function(e){
    e.preventDefault();
    pickyAiChatClear();
  });

  $(document).on('input', '#picky-ai-layout-prompt', function(){
    pickyAiChatPersist();
  });

  $(document).on('keydown', function(e){
    if (e.key !== 'Escape') { return; }
    var $m = $('#picky-ai-layout-modal');
    if ($m.length && !$m.attr('hidden')) { pickyAiCloseLayoutModal(); }
  });

  $(document).on('click', '#picky-ai-layout-generate', function(e){
    e.preventDefault();
    if (!pickyAiEnabled()) {
      pickyAiApplyConnBanner(pickyAiStatus());
      $('#picky-ai-layout-status').text(pickyAiMsg('generateNeedsSetup')).addClass('is-error');
      return;
    }
    var prompt = ($('#picky-ai-layout-prompt').val() || '').toString().trim();
    if (!prompt) {
      $('#picky-ai-layout-status').text(pickyAiMsg('promptRequired')).addClass('is-error');
      return;
    }
    var $canvas = $('.picky-draggable').first();
    var hasElements = $canvas.find('.picky-element').filter(function(){
      return !$(this).closest('.picky-element-sidebar-wrap, .picky-builder-sidebar').length;
    }).length > 0;
    if (hasElements && !window.confirm(pickyAiMsg('confirmReplace'))) {
      return;
    }

    var layoutId = (typeof pickyLayoutData !== 'undefined') ? parseInt(pickyLayoutData.layout_id, 10) : 0;
    if (!layoutId) {
      $('#picky-ai-layout-status').text(pickyAiMsg('error')).addClass('is-error');
      return;
    }
    var cw = parseInt($('input[name="picky_layout_settings[canvas_width]"]').first().val(), 10) || 1000;
    var ch = parseInt($('input[name="picky_layout_settings[canvas_height]"]').first().val(), 10) || 750;
    var $btn = $(this);
    var $status = $('#picky-ai-layout-status');
    pickyAiChatAppend('user', prompt);
    $btn.prop('disabled', true);
    $status.text(pickyAiMsg('generating')).removeClass('is-error is-ok');
    pickyAiChatAppend('bot', pickyAiMsg('generating'));

    pickyAiPost('picky_ai_layout_draft', {
      layout_id: layoutId,
      prompt: prompt,
      canvas_width: cw,
      canvas_height: ch
    }).done(function(res){
      $btn.prop('disabled', !pickyAiEnabled());
      if (!res || !res.success) {
        var err = pickyAiErr(res);
        $status.text(err).addClass('is-error');
        pickyAiChatAppend('bot', err, { replaceLastBot: true });
        if (pickyAiLooksLikeBilling(err)) {
          pickyAiApplyConnBanner('billing');
        }
        return;
      }
      var msg = (res.data && res.data.message) ? res.data.message : pickyAiMsg('done');
      $status.text(msg).addClass('is-ok');
      pickyAiChatAppend('bot', msg, { replaceLastBot: true });
      /* Keep draft prompt for the next tweak after reload. */
      pickyAiChatPersist();
      if (res.data && res.data.reload) {
        window.setTimeout(function(){ window.location.reload(); }, 700);
      }
    }).fail(function(){
      $btn.prop('disabled', !pickyAiEnabled());
      var err = pickyAiMsg('error');
      $status.text(err).addClass('is-error');
      pickyAiChatAppend('bot', err, { replaceLastBot: true });
    });
  });

  $(document).on('click', '.picky-lib-edit-ai', function(e){
    e.preventDefault();
    if (!pickyAiEnabled()) {
      pickyAiOpenLayoutModal();
      return;
    }
    var $editor = $(this).closest('.picky-services-library-editor');
    var seed = ($editor.find('.picky-lib-edit-name').val() || $editor.find('.picky-lib-edit-description').val() || '').toString().trim();
    var prompt = window.prompt(pickyAiMsg('servicePrompt', 'Describe the service:'), seed);
    if (prompt === null) { return; }
    prompt = String(prompt).trim();
    if (!prompt) {
      window.alert(pickyAiMsg('promptRequired'));
      return;
    }
    var $btn = $(this);
    $btn.prop('disabled', true).addClass('is-busy');
    pickyAiPost('picky_ai_service_copy', {
      prompt: prompt,
      price: $editor.find('.picky-lib-edit-price').val() || '',
      duration: $editor.find('.picky-lib-edit-duration').val() || ''
    }).done(function(res){
      $btn.prop('disabled', false).removeClass('is-busy');
      if (!res || !res.success || !res.data) {
        window.alert(pickyAiErr(res));
        return;
      }
      if (res.data.name) { $editor.find('.picky-lib-edit-name').val(res.data.name); }
      if (typeof res.data.description === 'string') {
        $editor.find('.picky-lib-edit-description').val(res.data.description);
      }
    }).fail(function(){
      $btn.prop('disabled', false).removeClass('is-busy');
      window.alert(pickyAiMsg('error'));
    });
  });

  $(document).on('click', '.picky-ai-element-copy-btn', function(e){
    e.preventDefault();
    if (!pickyAiEnabled()) {
      pickyAiOpenLayoutModal();
      return;
    }
    var $btn = $(this);
    var mode = ($btn.attr('data-picky-ai-mode') || 'label').toString();
    var element = getEditorTarget(this);
    if (!element || !element.length) { return; }
    var type = (element.attr('data-type') || 'type-1').toString();
    var seed = mode === 'text'
      ? (element.attr('data-text') || '')
      : (element.attr('data-label') || '');
    var ask = mode === 'text' ? pickyAiMsg('textPrompt') : pickyAiMsg('labelPrompt');
    var prompt = window.prompt(ask, seed);
    if (prompt === null) { return; }
    prompt = String(prompt).trim();
    if (!prompt) { prompt = 'Generate a short fitting name'; }

    $btn.prop('disabled', true).addClass('is-busy');
    pickyAiPost('picky_ai_element_copy', {
      prompt: prompt,
      element_type: type,
      mode: mode
    }).done(function(res){
      $btn.prop('disabled', false).removeClass('is-busy');
      if (!res || !res.success || !res.data) {
        window.alert(pickyAiErr(res));
        return;
      }
      var $editor = $btn.closest('.picky-element-edit-wrap');
      if (mode === 'text' || type === 'type-text') {
        var text = res.data.text || res.data.label || '';
        var $ti = $editor.find('.picky-element-edit-text-input');
        $ti.val(text).trigger('input').trigger('change');
      } else {
        var label = res.data.label || res.data.text || '';
        var $li = $editor.find('.picky-element-edit-label-input');
        $li.val(label).trigger('input').trigger('change');
      }
      if (typeof takeSnapshot === 'function') { takeSnapshot(); }
    }).fail(function(){
      $btn.prop('disabled', false).removeClass('is-busy');
      window.alert(pickyAiMsg('error'));
    });
  });

  $(document).on('click', '.picky-ai-form-copy-btn', function(e){
    e.preventDefault();
    var $row = $(this).closest('.picky-ai-form-copy-row');
    var $result = $row.find('.picky-ai-form-copy-result');
    if (!pickyAiEnabled()) {
      $result.text(pickyAiMsg('notConfigured')).css('color', '#d63638');
      return;
    }
    var prompt = ($row.find('.picky-ai-form-copy-prompt').val() || '').toString().trim();
    if (!prompt) {
      $result.text(pickyAiMsg('promptRequired')).css('color', '#d63638');
      return;
    }
    var $btn = $(this);
    $btn.prop('disabled', true);
    $result.text(pickyAiMsg('generating')).css('color', '#666');

    pickyAiPost('picky_ai_form_copy', { prompt: prompt }).done(function(res){
      $btn.prop('disabled', false);
      if (!res || !res.success || !res.data) {
        $result.text(pickyAiErr(res)).css('color', '#d63638');
        return;
      }
      var $form = $('#options-save');
      function setSlug(slug, val){
        if (typeof val !== 'string') { return; }
        var $f = $form.length ? $form.find('[name$="[' + slug + ']"]') : $('[name$="[' + slug + ']"]');
        if ($f.length) { $f.first().val(val).trigger('change'); }
      }
      setSlug('form_message', res.data.form_message || '');
      setSlug('form_confirm_text', res.data.form_confirm_text || '');
      setSlug('form_thx', res.data.form_thx || '');
      $result.text(pickyAiMsg('done')).css('color', 'green');
    }).fail(function(){
      $btn.prop('disabled', false);
      $result.text(pickyAiMsg('error')).css('color', '#d63638');
    });
  });


})(jQuery);
