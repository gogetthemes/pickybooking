jQuery(function($){
  function pickyHasPreviewImage($holder){
    if(!$holder || !$holder.length){ return false; }
    var bg = ($holder.css('background-image') || '').toString();
    return bg !== '' && bg !== 'none' && bg.indexOf('url(') !== -1 && bg.indexOf('url("")') === -1;
  }

  function pickySyncPreviewState($wrap){
    if(!$wrap || !$wrap.length){ return; }
    var $holder = $wrap.find('.picky-background-image-holder-wrap').first();
    if(!$holder.length){ return; }
    var hasHiddenValue = String(pickyBgHiddenInput($wrap).val() || '').trim() !== '';
    var hasImage = hasHiddenValue || pickyHasPreviewImage($holder);
    $holder.toggleClass('picky-has-image', hasImage);
    $holder.toggleClass('picky-no-image', !hasImage);
  }

  function pickyBgHiddenInput($wrap){
    if(!$wrap || !$wrap.length){ return $(); }
    var bg = ($wrap.attr('data-background') || '').toString();
    if(bg === 'background-canvas'){
      return $wrap.find('input[name="picky_layout_bg_canvas"], input[name="picky_background_canvas"]').first();
    }
    if(bg === 'background-table'){
      return $wrap.find('input[name="picky_layout_bg_table"], input[name="picky_background_table"]').first();
    }
    return $wrap.find('input[type="hidden"]').first();
  }
  /*
   * Select/Upload image(s) event
   */
  jQuery(document).ready( function($){ 
    $('.picky-background-field').each(function(){
      pickySyncPreviewState($(this));
    });

    $('.picky-custom_upload_image_button').on('click', function(e){
      e.preventDefault();
   
      var button = $(this);
      var custom_uploader = wp.media({
        title: (window.pickyAdminI18n && window.pickyAdminI18n.insert_image) ? window.pickyAdminI18n.insert_image : '',
        library : {
          // uncomment the next line if you want to attach image to the current post
          // uploadedTo : wp.media.view.settings.post.id, 
          type : 'image'
        },
        button: {
          text: (window.pickyAdminI18n && window.pickyAdminI18n.use_this_image) ? window.pickyAdminI18n.use_this_image : '' // button label (localized via wp_localize_script)
        },
        multiple: false // for multiple image selection set to true
      }).on('select', function() { // it also has "open" and "close" events 
        var attachment = custom_uploader.state().get('selection').first().toJSON();
        var wrap = $(button).closest('.picky-background-field');
        wrap.find('.picky-background-image-holder-wrap').css('background-image', 'url('+ attachment.url +')');
        pickyBgHiddenInput(wrap).val(attachment.url);
        if(wrap.attr('data-background') == 'background-canvas'){
          $('input[name="picky_background_canvas"], input[name="picky_layout_bg_canvas"]').val(attachment.url);
        }
        if(wrap.attr('data-background') == 'background-table'){
          $('input[name="picky_background_table"], input[name="picky_layout_bg_table"]').val(attachment.url);
        }
        wrap.find('.picky-custom_remove_image_button').show();
        pickySyncPreviewState(wrap);
        if(wrap.attr('data-background') == 'background-canvas'){
          $('.picky-draggable').css('background-image', 'url('+ attachment.url +')');
          var mode = $('select[name="picky_layout_settings[background-canvas-mode]"]').val() || 'cover';
          $(document).trigger('picky:canvas-bg-mode', [mode]);
          $('.picky-draggable').removeClass('no-bg');
        }
        if(wrap.attr('data-background') == 'background-table'){
          $('.picky-element:not(.type-text):not(.type-wall):not(.type-shape):not(.type-icon) .picky-element-shape').css('background-image', 'url('+ attachment.url +')');
          $('.picky-element:not(.type-text):not(.type-wall):not(.type-shape):not(.type-icon)').removeClass('no-bg-table');
        }
        if(wrap.attr('data-background') == 'background-seat'){
          $('.picky-element .picky-element-seats-wrap .picky-element-seat').css('background-image', 'url('+ attachment.url +')');
        }
      })
      .open();
    });

    /*
     * Remove image event
     */
    $('.picky-custom_remove_image_button').on('click', function(){
      var wrap = $(this).closest('.picky-background-field');
      wrap.find('.picky-background-image-holder-wrap').css('background-image', '');
      pickyBgHiddenInput(wrap).val('');
      if(wrap.attr('data-background') == 'background-canvas'){
        $('input[name="picky_background_canvas"], input[name="picky_layout_bg_canvas"]').val('');
      }
      if(wrap.attr('data-background') == 'background-table'){
        $('input[name="picky_background_table"], input[name="picky_layout_bg_table"]').val('');
      }
      wrap.find('.picky-custom_remove_image_button').hide();
      pickySyncPreviewState(wrap);
      if(wrap.attr('data-background') == 'background-canvas'){
        wrap.find('.picky-background-image-holder-wrap').css('background-image', '');
        $('.picky-draggable').css('background-image', '');
        var mode = $('select[name="picky_layout_settings[background-canvas-mode]"]').val() || 'cover';
        $(document).trigger('picky:canvas-bg-mode', [mode]);
        $('.picky-draggable').addClass('no-bg');
      }
      if(wrap.attr('data-background') == 'background-table'){
        $('.picky-element:not(.type-text):not(.type-wall):not(.type-shape):not(.type-icon) .picky-element-shape').css('background-image', '');
        $('.picky-element').addClass('no-bg-table');
      }
      if(wrap.attr('data-background') == 'background-seat'){
        $('.picky-element .picky-element-seats-wrap .picky-element-seat').css('background-image', '');
        $('.picky-element').addClass('no-bg-seats');
      }

      return false;
    });

  });
 
});