<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$picky_layout_embedded      = ! empty( $picky_layout_embedded );
$picky_builder_surface_id   = $picky_layout_embedded ? 'picky-builder-surface' : 'picky-wrap-content';
?>
<div class="picky-root picky-wrap<?php echo $picky_layout_embedded ? ' picky-wrap--layout-embedded' : ''; ?>" data-picky-context="<?php echo $picky_layout_embedded ? 'builder-embedded' : 'builder'; ?>">
    <?php if ( ! $picky_layout_embedded ) : ?>
    <h1 class="picky-h1"><?php echo esc_html( (string)$heading ); ?> — <span id="picky-layout-title-display"><?php echo esc_html( (string)$layout_title ); ?></span></h1>
    <?php else : ?>
    <h2 class="picky-h1 screen-reader-text"><?php echo esc_html( (string)$heading ); ?> — <span id="picky-layout-title-display"><?php echo esc_html( (string)$layout_title ); ?></span></h2>
    <?php endif; ?>
    <div id="<?php echo esc_attr( (string) $picky_builder_surface_id ); ?>"<?php echo $picky_layout_embedded ? ' class="picky-builder-surface"' : ''; ?>>

        <?php $picky_layout_notice = ( isset( $this ) && is_object( $this ) && method_exists( $this, 'picky_layout_notice' ) ) ? $this->picky_layout_notice() : ''; ?>
        <?php if ( $picky_layout_notice === 'saved' ): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Layout saved.', 'pickybooking' ); ?></p></div>
        <?php endif; ?>
        <?php if ( $picky_layout_notice === 'imported' ): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Layout imported.', 'pickybooking' ); ?></p></div>
        <?php endif; ?>
        <?php if ( $picky_layout_notice === 'duplicated' ): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Layout duplicated. You are editing the copy.', 'pickybooking' ); ?></p></div>
        <?php endif; ?>

        <form class="picky-form picky-form-options-save" id="picky-options-save" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <input type="hidden" name="action"    value="picky_save_layout">
            <input type="hidden" name="layout_id" value="<?php echo esc_attr( (string)$layout_id ); ?>">
            <?php wp_nonce_field( 'picky_save_layout_' . $layout_id, 'picky_layout_nonce' ); ?>
            <input type="hidden" id="picky-position-input" name="picky_layout_position" value="<?php echo esc_attr( (string)$layout_position ); ?>">
            <?php
            $picky_transparent_canvas = isset( $layout_id ) && (int) $layout_id > 0
                && ( get_post_meta( (int) $layout_id, '_picky_layout_transparent_canvas', true ) === '1' );
            $picky_layout_show_seats_meta = ( isset( $layout_id ) && (int) $layout_id > 0 )
                ? (string) get_post_meta( (int) $layout_id, '_picky_layout_show_seats', true )
                : '';
            $picky_layout_show_ids_meta = ( isset( $layout_id ) && (int) $layout_id > 0 )
                ? (string) get_post_meta( (int) $layout_id, '_picky_layout_show_ids', true )
                : '';
            $picky_layout_show_labels_meta = ( isset( $layout_id ) && (int) $layout_id > 0 )
                ? (string) get_post_meta( (int) $layout_id, '_picky_layout_show_labels', true )
                : '';
            $picky_layout_show_item_fill_meta = ( isset( $layout_id ) && (int) $layout_id > 0 )
                ? (string) get_post_meta( (int) $layout_id, '_picky_layout_show_item_fill', true )
                : '';
            $picky_layout_show_booked_avatars_meta = ( isset( $layout_id ) && (int) $layout_id > 0 )
                ? (string) get_post_meta( (int) $layout_id, '_picky_layout_show_booked_avatars', true )
                : '';
            $picky_layout_booked_avatars_tone_meta = ( isset( $layout_id ) && (int) $layout_id > 0 )
                ? (string) get_post_meta( (int) $layout_id, '_picky_layout_booked_avatars_tone', true )
                : '';
            $picky_layout_shortcode_layout_meta = ( isset( $layout_id ) && (int) $layout_id > 0 )
                ? (string) get_post_meta( (int) $layout_id, '_picky_layout_shortcode_layout', true )
                : '';
            if ( $picky_layout_show_seats_meta !== '0' && $picky_layout_show_seats_meta !== '1' ) {
                $picky_layout_show_seats_meta = '';
            }
            if ( $picky_layout_show_ids_meta !== '0' && $picky_layout_show_ids_meta !== '1' ) {
                $picky_layout_show_ids_meta = '';
            }
            if ( $picky_layout_show_labels_meta !== '0' && $picky_layout_show_labels_meta !== '1' ) {
                $picky_layout_show_labels_meta = '';
            }
            if ( $picky_layout_show_item_fill_meta !== '0' && $picky_layout_show_item_fill_meta !== '1' ) {
                $picky_layout_show_item_fill_meta = '';
            }
            if ( $picky_layout_show_booked_avatars_meta !== '0' && $picky_layout_show_booked_avatars_meta !== '1' ) {
                $picky_layout_show_booked_avatars_meta = '0';
            }
            if ( $picky_layout_booked_avatars_tone_meta !== 'bw' ) {
                $picky_layout_booked_avatars_tone_meta = 'color';
            }
            if ( ! in_array( $picky_layout_shortcode_layout_meta, array( '1', '2', '3', '4' ), true ) ) {
                $picky_layout_shortcode_layout_meta = '';
            }
            $picky_builder_show_people = isset( $options['show_people'] ) && (string) $options['show_people'] === '1';
            $picky_builder_show_seats  = isset( $options['show_seats'] ) && (string) $options['show_seats'] === '1';
            $picky_builder_show_ids    = isset( $options['show_id_badge'] ) && (string) $options['show_id_badge'] === '1';
            $picky_builder_show_labels = isset( $options['show_element_label'] ) && (string) $options['show_element_label'] === '1';
            if ( $picky_layout_show_seats_meta === '0' || $picky_layout_show_seats_meta === '1' ) {
                $picky_builder_show_seats = ( $picky_layout_show_seats_meta === '1' );
            }
            if ( $picky_layout_show_ids_meta === '0' || $picky_layout_show_ids_meta === '1' ) {
                $picky_builder_show_ids = ( $picky_layout_show_ids_meta === '1' );
            }
            if ( $picky_layout_show_labels_meta === '0' || $picky_layout_show_labels_meta === '1' ) {
                $picky_builder_show_labels = ( $picky_layout_show_labels_meta === '1' );
            }
            ?>
            <input type="hidden" name="picky_layout_settings[canvas_elements_size]" value="<?php echo esc_attr( (string)$settings['canvas_elements_size'] ); ?>">
        <?php
        $picky_export_href = wp_nonce_url(
            add_query_arg(
                array(
                    'action'    => 'picky_export_layout',
                    'layout_id' => (int) $layout_id,
                ),
                admin_url( 'admin-post.php' )
            ),
            'picky_export_layout_' . (int) $layout_id
        );
        $picky_duplicate_href = '';
        if ( current_user_can( 'edit_post', (int) $layout_id ) && current_user_can( 'publish_posts' ) ) {
            $picky_duplicate_href = (string) wp_nonce_url(
                add_query_arg(
                    array(
                        'action'    => 'picky_duplicate_layout',
                        'layout_id' => (int) $layout_id,
                    ),
                    admin_url( 'admin-post.php' )
                ),
                'picky_duplicate_layout_' . (int) $layout_id
            );
        }
        $picky_delete_href = '';
        if ( current_user_can( 'delete_post', (int) $layout_id ) ) {
            $picky_delete_href = (string) wp_nonce_url(
                add_query_arg(
                    array(
                        'action'    => 'picky_delete_layout',
                        'layout_id' => (int) $layout_id,
                    ),
                    admin_url( 'admin-post.php' )
                ),
                'picky_delete_layout_' . (int) $layout_id
            );
        }
        ?>
        <div class="picky-builder-main-container">
            <div class="picky-layout-wrap">
            
                <?php
                $picky_bg_canvas_mode = \Picky\Info::$DEFAULT_OPTIONS['background-canvas-mode'];
                $postion     = urldecode( (string)$layout_position );
                $jsonIterator = json_decode( (string)$postion, TRUE );

                // Categorize items
                $categories = [
                    'tables' => [
                        'title' => __('Bookable Items', 'pickybooking'),
                        'items' => []
                    ],
                ];

                foreach ($tables as $table) {
                    $categories['tables']['items'][] = $table;
                }
                ?>
                <div class="picky-tables-wrap picky-loading picky-tables-size-<?php echo esc_attr( (string)$settings['canvas_elements_size'] ); ?>">

                    <div class="picky-builder-left-col-slot">
                    <div class="picky-builder-left-col">
                        <div class="picky-builder-layout-meta picky-builder-layout-meta--title" aria-label="<?php esc_attr_e( 'Layout settings', 'pickybooking' ); ?>">
                            <div class="picky-builder-layout-meta__field">
                                <label class="picky-builder-layout-meta__label" for="picky-layout-title-input"><?php esc_html_e( 'Layout Name', 'pickybooking' ); ?></label>
                                <input type="text" id="picky-layout-title-input" class="picky-input" name="picky_layout_title" form="picky-options-save" value="<?php echo esc_attr( (string)$layout_title ); ?>" placeholder="<?php esc_attr_e( 'Layout Name', 'pickybooking' ); ?>" oninput="document.getElementById('picky-layout-title-display').textContent=this.value||'<?php echo esc_js( (string)$layout_title ); ?>'">
                            </div>
                        </div>

                    <div class="picky-element-sidebar">
                        <div class="picky-element-sidebar-title"><?php esc_html_e( 'Drag & Drop elements', 'pickybooking' ); ?></div>
                        <div class="picky-element-sidebar-inner">
                            <?php foreach ($categories as $cat_id => $category): ?>
                                <?php if (!empty($category['items'])): ?>
                                    <div class="picky-sidebar-category">
                                        <div class="picky-sidebar-category-header">
                                            <?php echo esc_html($category['title']); ?>
                                            <span class="picky-cat-count"><?php echo count($category['items']); ?></span>
                                        </div>
                                        <div class="picky-sidebar-category-content">
                                            <?php foreach ($category['items'] as $table): ?>
                                                <?php
                                                $picky_preset_title = isset( $table['title'] ) ? (string) $table['title'] : '';
                                                $picky_shape_type = 'rectangle';
                                                $picky_preset_icon = '';
                                                $picky_preset_color = '';
                                                if ( 'Square' === $picky_preset_title ) { 
                                                    $picky_preset_title = __( 'Square', 'pickybooking' ); 
                                                } elseif ( 'Circle' === $picky_preset_title ) { 
                                                    $picky_preset_title = __( 'Circle', 'pickybooking' ); 
                                                    $picky_shape_type = 'circle';
                                                } elseif ( 'Icon' === $picky_preset_title || (string) $table['data-type'] === 'type-icon' ) {
                                                    $picky_preset_title = __( 'Icon', 'pickybooking' );
                                                    $picky_preset_icon = 'star';
                                                    $picky_preset_color = '#334155';
                                                } elseif ( 'Shape' === $picky_preset_title ) {
                                                    $picky_preset_title = __( 'Shape', 'pickybooking' );
                                                } elseif ( 'Wall' === $picky_preset_title ) {
                                                    $picky_preset_title = __( 'Wall', 'pickybooking' );
                                                } elseif ( 'Text' === $picky_preset_title ) {
                                                    $picky_preset_title = __( 'Text', 'pickybooking' );
                                                }
                                                ?>
                                                <?php
                                                // No data-square-radius on presets — missing attr = CSS default (8px).
                                                ?>
                                                <div class="picky-element-sidebar-wrap" data-type="<?php echo esc_attr( (string)$table['data-type'] ); ?>" title="<?php echo esc_attr($picky_preset_title); ?>">
                                                    <div class="picky-sidebar-icon"></div>
                                                    <div class="picky-element <?php echo esc_attr( (string)$table['data-type'] ); ?>" data-show-id="1" data-max-seats="<?php echo esc_attr( (string)$table['data-max-seats'] ); ?>" data-seats="<?php echo esc_attr( (string)$table['data-seats'] ); ?>" data-label="<?php echo esc_attr( (string)$picky_preset_title ); ?>" data-label-position="bottom" data-label-font-size="12" data-label-color="#333333" data-label-font-weight="normal" data-big-side="<?php echo esc_attr( (string)$table['data-big-side'] ); ?>" data-type="<?php echo esc_attr( (string)$table['data-type'] ); ?>" data-rotate="1" data-rotate-z="0" data-rotate-x="0" data-rotate-y="0" data-opacity="1" data-text="<?php echo esc_attr((string)$picky_preset_title); ?>" data-text-color="#333333" data-text-color-mode="custom" data-font-size="16" data-line-height="" data-font-family="inherit" data-font-weight="normal" data-shape-type="<?php echo esc_attr($picky_shape_type); ?>" data-shape-border-width="0" data-shape-border-color="#333333" data-icon="<?php echo esc_attr( $picky_preset_icon ); ?>"<?php echo $picky_preset_color !== '' ? ' data-color="' . esc_attr( $picky_preset_color ) . '"' : ''; ?>>
                                                        <div class="picky-element-table-wrap">
                                                            <div class="picky-element-table">
                                                                <div class="picky-element-shape"<?php echo $picky_preset_color !== '' && (string) $table['data-type'] === 'type-icon' ? ' style="color:' . esc_attr( $picky_preset_color ) . ';background:transparent;"' : ''; ?>>
                                                                    <?php if( (string)$table['data-type'] === 'type-text'){ ?>
                                                                        <div class="picky-element-text-content" style="color:#333;font-size:14px;--picky-text-line-height:14px;line-height:14px;"><?php echo esc_html((string)$picky_preset_title); ?></div>
                                                                    <?php } elseif ( (string) $table['data-type'] === 'type-icon' ) { ?>
                                                                        <span class="picky-element-icon-wrap"><?php echo wp_kses( \Picky\Booking_Icons::svg_markup( $picky_preset_icon !== '' ? $picky_preset_icon : 'star', 'picky-bicon picky-element-icon' ), \Picky\Booking_Icons::kses_allowed() ); ?></span>
                                                                    <?php } ?>
                                                                </div>
                                                                <div class="picky-rotate-handle" title="<?php esc_attr_e('Drag to rotate', 'pickybooking'); ?>"></div>
                                                                <div class="picky-3d-pad" title="<?php esc_attr_e('Drag to tilt (3D)', 'pickybooking'); ?>"><div class="picky-3d-handle"></div></div>
                                                            </div>
                                                            <div class="picky-element-overlay">
                                                                <button type="button" class="picky-element-edit" aria-label="<?php esc_attr_e('Edit element', 'pickybooking'); ?>"></button>
                                                                <div class="picky-element-seats"<?php if ( ! $picky_builder_show_seats ) : ?> style="display:none;" aria-hidden="true"<?php endif; ?>><?php echo esc_html( (string) $table['data-seats'] ); ?></div>
                                                                <div class="picky-element-label"<?php if ( ! $picky_builder_show_labels ) : ?> style="display:none;" aria-hidden="true"<?php endif; ?>></div>
                                                            </div>
                                                            <div class="picky-element-seats-wrap"></div>
                                                        </div>
                                                        <div class="picky-element-edit-wrap" style="display: none;">
                                                            <div class="picky-element-edit-heading" aria-live="polite"></div>
                                                            <div class="picky-element-edit-fields">
                                                                <div class="picky-element-edit-field picky-field-max-seats">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Max persons', 'pickybooking'); ?></div>
                                                                    <input type="number" min="0" step="1" value="<?php echo esc_attr( (string)$table['data-max-seats'] ); ?>" class="picky-element-edit-max-persons-input picky-input">
                                                                </div>
                                                                <div class="picky-element-edit-field picky-field-label">
                                                                    <div class="picky-element-edit-field-title<?php echo ( class_exists( '\\Picky\\Edition' ) && \Picky\Edition::allows( 'ai' ) ) ? ' picky-ai-field-title' : ''; ?>">
                                                                        <span><?php esc_html_e('Name', 'pickybooking'); ?></span>
                                                                        <?php if ( class_exists( '\\Picky\\Edition' ) && \Picky\Edition::allows( 'ai' ) ) : ?>
                                                                        <button type="button" class="button button-small picky-ai-element-copy-btn" data-picky-ai-mode="label" title="<?php esc_attr_e( 'Generate name with AI', 'pickybooking' ); ?>"><?php esc_html_e( 'AI', 'pickybooking' ); ?></button>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <input type="text" value="<?php echo esc_attr($picky_preset_title); ?>" class="picky-element-edit-label-input picky-input">
                                                                </div>
                                                                <div class="picky-element-edit-field picky-field-label-position">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e( 'Name position', 'pickybooking' ); ?></div>
                                                                    <select class="picky-element-edit-label-position-input picky-input">
                                                                        <option value="bottom" selected><?php esc_html_e( 'Below', 'pickybooking' ); ?></option>
                                                                        <option value="top"><?php esc_html_e( 'Above', 'pickybooking' ); ?></option>
                                                                    </select>
                                                                </div>
                                                                <div class="picky-element-edit-field picky-field-label-color">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e( 'Label color', 'pickybooking' ); ?></div>
                                                                    <input type="text" value="#333333" class="picky-element-edit-label-color-input picky-input" />
                                                                </div>
                                                                <div class="picky-element-edit-field picky-field-show-id">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Show/Hide ID', 'pickybooking'); ?></div>
                                                                    <label class="picky-element-edit-show-id-label"><input type="checkbox" class="picky-element-edit-show-id-input" checked aria-label="<?php esc_attr_e('Show/Hide ID on canvas', 'pickybooking'); ?>"></label>
                                                                </div>
                                                                <div class="picky-element-edit-field picky-field-custom-id picky-bookable-only-field">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Custom ID (optional)', 'pickybooking'); ?></div>
                                                                    <input type="text" value="" class="picky-element-edit-custom-id-input picky-input" placeholder="<?php esc_attr_e('Leave blank to use auto-ID', 'pickybooking'); ?>" maxlength="64">
                                                                </div>
                                                                <div class="picky-element-edit-field picky-type-1-only-field picky-field-square-radius">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e( 'Corner radius (px)', 'pickybooking' ); ?></div>
                                                                    <input type="number" min="0" max="120" step="1" value="" class="picky-element-edit-square-radius-input picky-input" placeholder="<?php esc_attr_e( 'Default', 'pickybooking' ); ?>" title="<?php esc_attr_e( 'Leave empty for default rounding (8px). Enter 1–120 to override.', 'pickybooking' ); ?>" />
                                                                </div>
                                                                <div class="picky-element-edit-field picky-text-only-field">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Text Color', 'pickybooking'); ?></div>
                                                                    <input type="text" value="#333333" class="picky-element-edit-text-color-input picky-input" />
                                                                </div>
                                                                <div class="picky-element-edit-field picky-text-only-field">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Text Color Source', 'pickybooking'); ?></div>
                                                                    <select class="picky-element-edit-text-color-mode-input picky-input">
                                                                        <option value="custom"><?php esc_html_e('Custom color', 'pickybooking'); ?></option>
                                                                        <option value="theme"><?php esc_html_e('Theme (inherit)', 'pickybooking'); ?></option>
                                                                    </select>
                                                                </div>
                                                                <div class="picky-element-edit-field picky-text-only-field picky-field-padding">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Padding', 'pickybooking'); ?></div>
                                                                    <input type="number" min="0" step="1" value="10" class="picky-element-edit-padding-input picky-input">
                                                                </div>
                                                                <div class="picky-element-edit-field picky-text-only-field picky-field-font-size">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Font Size', 'pickybooking'); ?></div>
                                                                    <input type="number" min="8" max="200" step="1" value="16" class="picky-element-edit-font-size-input picky-input">
                                                                </div>
                                                                <div class="picky-element-edit-field picky-text-only-field picky-field-line-height">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e( 'Line height (px)', 'pickybooking' ); ?></div>
                                                                    <input type="number" min="8" max="400" step="1" value="" class="picky-element-edit-line-height-input picky-input" placeholder="<?php esc_attr_e( 'Auto', 'pickybooking' ); ?>" title="<?php esc_attr_e( 'Leave empty to match font size.', 'pickybooking' ); ?>">
                                                                </div>
                                                                <div class="picky-element-edit-field picky-text-only-field picky-field-font-family">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Font Family', 'pickybooking'); ?></div>
                                                                    <select class="picky-element-edit-font-family-input picky-input">
                                                                        <?php
                                                                        $picky_sidebar_fonts = isset( $builder_font_family_options ) && is_array( $builder_font_family_options ) ? $builder_font_family_options : array( 'inherit' => 'Theme default (inherit)', 'sans-serif' => 'Sans Serif' );
                                                                        foreach($picky_sidebar_fonts as $picky_f_val => $picky_f_label){
                                                                            echo '<option value="'.esc_attr((string)$picky_f_val).'"'.selected('inherit', $picky_f_val, false).'>'.esc_html((string)$picky_f_label).'</option>';
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                                <div class="picky-element-edit-field picky-text-only-field picky-field-font-weight">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Font Weight', 'pickybooking'); ?></div>
                                                                    <select class="picky-element-edit-font-weight-input picky-input">
                                                                        <?php
                                                                        $picky_sidebar_weights = array('normal' => 'Normal', 'bold' => 'Bold', '300' => 'Light', '500' => 'Medium', '900' => 'Black');
                                                                        foreach($picky_sidebar_weights as $picky_w_val => $picky_w_label){
                                                                            echo '<option value="'.esc_attr((string)$picky_w_val).'"'.selected('normal', $picky_w_val, false).'>'.esc_html((string)$picky_w_label).'</option>';
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                                <div class="picky-element-edit-field picky-shape-only-field">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Shape Type', 'pickybooking'); ?></div>
                                                                    <select class="picky-element-edit-shape-type-input picky-input">
                                                                        <?php
                                                                        $shape_types = array('rectangle' => 'Rectangle', 'rounded' => 'Rounded', 'circle' => 'Circle', 'triangle' => 'Triangle');
                                                                        $current_shape = 'rectangle';
                                                                        foreach($shape_types as $st_val => $st_label){
                                                                            echo '<option value="'.esc_attr((string)$st_val).'" '.selected($current_shape, $st_val, false).'>'.esc_html((string)$st_label).'</option>';
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                                <div class="picky-element-edit-field picky-shape-only-field picky-field-shape-radius">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e( 'Corner radius (px)', 'pickybooking' ); ?></div>
                                                                    <input type="number" min="0" max="120" step="1" value="" class="picky-element-edit-shape-radius-input picky-input" placeholder="<?php esc_attr_e( 'Auto', 'pickybooking' ); ?>" title="<?php esc_attr_e( 'Leave empty to use the preset for the selected shape type.', 'pickybooking' ); ?>" />
                                                                </div>
                                                                <div class="picky-element-edit-field picky-shape-only-field picky-field-shape-border-width">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e( 'Outline width (px)', 'pickybooking' ); ?></div>
                                                                    <input type="number" min="0" max="32" step="1" value="0" class="picky-element-edit-shape-border-width-input picky-input" />
                                                                </div>
                                                                <div class="picky-element-edit-field picky-shape-only-field picky-field-shape-border-color">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e( 'Outline color', 'pickybooking' ); ?></div>
                                                                    <input type="text" value="#333333" class="picky-element-edit-shape-border-color-input picky-input" />
                                                                </div>
                                                                <div class="picky-element-edit-field picky-icon-only-field">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e( 'Icon', 'pickybooking' ); ?></div>
                                                                    <?php
                                                                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- picker escapes its own output
                                                                    echo wp_kses( \Picky\Booking_Icons::render_picker( '', '', 'star', 'picky-element-edit-icon-input' ), \Picky\Booking_Icons::kses_allowed() );
                                                                    ?>
                                                                </div>
                                                                <div class="picky-element-edit-field picky-text-only-field">
                                                                    <div class="picky-element-edit-field-title">
                                                                        <span><?php esc_html_e('Text Content', 'pickybooking'); ?></span>
                                                                    </div>
                                                                    <input type="text" class="picky-element-edit-text-input picky-input" placeholder="<?php esc_attr_e( 'Edit text field', 'pickybooking' ); ?>" />
                                                                </div>
                                                                <div class="picky-element-edit-field picky-field-opacity">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e('Opacity', 'pickybooking'); ?></div>
                                                                    <button type="button" class="picky-element-edit-opacity-btn picky-input" aria-haspopup="true" aria-expanded="false">
                                                                        <span class="picky-element-edit-opacity-value">100%</span>
                                                                    </button>
                                                                    <div class="picky-element-edit-opacity-popup" style="display:none;" role="dialog">
                                                                        <input type="range" min="0" max="100" step="5" value="100" class="picky-element-edit-opacity-input">
                                                                    </div>
                                                                </div>

                                                                <!-- Available services — chip multiselect from layout library -->
                                                                <div class="picky-element-edit-field picky-field-service-ids picky-bookable-only-field" style="display:none;">
                                                                    <div class="picky-element-edit-field-title"><?php esc_html_e( 'Available services', 'pickybooking' ); ?></div>
                                                                    <div class="picky-field-service-ids-chips">
                                                                        <!-- Chips injected by JS from library -->
                                                                    </div>
                                                                    <p class="picky-field-service-ids-empty description" style="display:none;margin:4px 0 0;"><?php esc_html_e( 'No services in the library yet. Add services in the Services Library panel below the canvas.', 'pickybooking' ); ?></p>
                                                                </div>

                                                            </div>
                                                            <div class="picky-element-edit-tools">
                                                                <button type="button" class="picky-element-edit-resize picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Resize', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Resize', 'pickybooking'); ?>"></button>
                                                                <button type="button" class="picky-element-edit-rotate picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Rotate', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Rotate', 'pickybooking'); ?>"></button>
                                                                <button type="button" class="picky-element-edit-3d picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('3D', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('3D', 'pickybooking'); ?>"></button>
                                                                <button type="button" class="picky-element-custom-upload picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Upload image', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Upload image', 'pickybooking'); ?>"></button>
                                                                <button type="button" class="picky-element-edit-color picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Color', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Color', 'pickybooking'); ?>"></button>
                                                                <button type="button" class="picky-element-duplicate picky-element-edit-btn" data-picky-tip="<?php esc_attr_e( 'Duplicate selection (Ctrl/Cmd+D)', 'pickybooking' ); ?>" aria-label="<?php esc_attr_e( 'Duplicate selection', 'pickybooking' ); ?>"></button>
                                                                <button type="button" class="picky-element-layer-front picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Bring to front', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Bring to front', 'pickybooking'); ?>"></button>
                                                                <button type="button" class="picky-element-layer-back picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Send to back', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Send to back', 'pickybooking'); ?>"></button>
                                                                <button type="button" class="picky-element-delete picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Delete', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Delete', 'pickybooking'); ?>"></button>
                                                            </div>
                                                            <div class="picky-element-color-picker-wrap" style="display:none;">
                                                                <input type="text" class="picky-element-color-input" value="">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="picky-element-sidebar-wrap-label"><?php echo esc_html($picky_preset_title); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                        <div class="picky-builder-layout-meta picky-builder-layout-meta--tools" aria-label="<?php esc_attr_e( 'Canvas tools', 'pickybooking' ); ?>">
                            <div class="picky-builder-layout-meta__field">
                                <span class="picky-builder-layout-meta__label" id="picky-canvas-size-label"><?php esc_html_e( 'Canvas Size', 'pickybooking' ); ?></span>
                                <div class="picky-canvas-size-controls picky-canvas-size-controls--sidebar" role="group" aria-labelledby="picky-canvas-size-label">
                                    <div class="picky-input-pair">
                                        <input id="picky-canvas-width-input" class="picky-header-custom-input picky-input" name="picky_layout_settings[canvas_width]" type="number" min="500" max="2500" value="<?php echo esc_attr( (string)$settings['canvas_width'] ); ?>" title="<?php esc_attr_e( 'Width', 'pickybooking' ); ?>" form="picky-options-save" aria-label="<?php esc_attr_e( 'Width', 'pickybooking' ); ?>">
                                        <span class="picky-separator" aria-hidden="true">×</span>
                                        <input id="picky-canvas-height-input" class="picky-header-custom-input picky-input" name="picky_layout_settings[canvas_height]" type="number" min="500" max="2500" value="<?php echo esc_attr( (string)$settings['canvas_height'] ); ?>" title="<?php esc_attr_e( 'Height', 'pickybooking' ); ?>" form="picky-options-save" aria-label="<?php esc_attr_e( 'Height', 'pickybooking' ); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="picky-builder-layout-meta__actions">
                                <button type="button" class="picky-shortcode-badge" data-clipboard-text="<?php echo esc_attr( '[pickybooking id="' . (string) $layout_id . '"]' ); ?>" title="<?php esc_attr_e( 'Copy shortcode to clipboard', 'pickybooking' ); ?>" aria-label="<?php esc_attr_e( 'Copy shortcode to clipboard', 'pickybooking' ); ?>">
                                    <span class="picky-badge-tag">[shortcode]</span>
                                    <span class="picky-badge-id"><?php
									/* translators: %s: layout ID */
									echo esc_html( sprintf( __( 'ID: %s', 'pickybooking' ), (string) $layout_id ) );
									?></span>
                                    <span class="picky-shortcode-copy-icon" aria-hidden="true"></span>
                                </button>
                                <?php if ( ! class_exists( '\\Picky\\Edition' ) || \Picky\Edition::allows( 'import_export' ) ) : ?>
                                <a class="button picky-header-export-btn" href="<?php echo esc_url( (string) $picky_export_href ); ?>"><?php esc_html_e( 'Export', 'pickybooking' ); ?></a>
                                <?php endif; ?>
                                <?php if ( $picky_duplicate_href !== '' ) : ?>
                                    <a class="button picky-header-duplicate-btn" href="<?php echo esc_url( (string) $picky_duplicate_href ); ?>"><?php esc_html_e( 'Duplicate', 'pickybooking' ); ?></a>
                                <?php endif; ?>
                                <?php if ( $picky_delete_href !== '' ) : ?>
                                    <a class="button picky-header-delete-btn submitdelete" href="<?php echo esc_url( (string) $picky_delete_href ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this layout permanently? This cannot be undone.', 'pickybooking' ) ); ?>');"><?php esc_html_e( 'Delete', 'pickybooking' ); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div id="picky-element-inspector" class="picky-element-inspector is-empty" aria-hidden="true">
                            <?php /* Text-tool UX: slot moves into the active editor; context is on the tab-strip label. */ ?>
                            <div class="picky-inline-editing-text-slot" id="picky-inline-editing-text-slot" style="display:none;"></div>
                            <div class="picky-builder-toolbar picky-builder-toolbar--inspector" id="picky-builder-toolbar"></div>
                        </div>
                    </div><!-- /.picky-builder-left-col -->
                    </div><!-- /.picky-builder-left-col-slot -->

                    <div class="picky-draggable-wrap-all">
                        <div class="picky-draggable-wrap<?php echo $picky_transparent_canvas ? ' picky-canvas-transparent-bg' : ''; ?>">
                            <div class="picky-canvas-controls picky-canvas-controls--overlay">
                                <button type="button" id="picky-undo" class="picky-undo-btn" title="<?php esc_attr_e('Undo', 'pickybooking'); ?>"><i class="icon-rotate-ccw"></i></button>
                                <div class="picky-zoom-controls" role="group" aria-label="<?php esc_attr_e( 'Canvas zoom', 'pickybooking' ); ?>">
                                    <button type="button" id="picky-zoom-out" title="<?php esc_attr_e('Zoom Out', 'pickybooking'); ?>"><i class="icon-minus"></i></button>
                                    <button type="button" id="picky-zoom-reset" title="<?php esc_attr_e('Reset zoom and pan', 'pickybooking'); ?>">100%</button>
                                    <button type="button" id="picky-zoom-in" title="<?php esc_attr_e('Zoom In', 'pickybooking'); ?>"><i class="icon-plus"></i></button>
                                </div>
                            </div>
                            <div class="picky-draggable<?php echo $picky_transparent_canvas ? ' picky-canvas-transparent-bg' : ''; ?>" style="<?php if($options['canvas_width']){ ?>width:<?php echo esc_attr( (string)$options['canvas_width'] );} ?>px;<?php if($options['canvas_height']){ ?>height:<?php echo esc_attr( (string)$options['canvas_height'] );} ?>px;">
                                <?php
                                if(isset($jsonIterator) && !empty($jsonIterator)){
                                    foreach ($jsonIterator as $key => $val) {
                                        if(is_array($val)) {
                                            $w = $val['x1']-$val['x'];
                                            $h = $val['y1']-$val['y'];
                                            $z_rot = isset($val['rotate_z']) ? intval($val['rotate_z']) : 0;
                                            $z_stack = isset( $val['z_index'] ) ? intval( $val['z_index'] ) : ( 10 + (int) $key );
                                            if ( $z_stack < 1 ) {
                                                $z_stack = 10 + (int) $key;
                                            }
                                            $element_style = "left: ".(string)$val['x']."px; top: ".(string)$val['y']."px; z-index: {$z_stack};";
                                            $element_style_table = '';
                                            if(isset($w) && isset($h)){
                                                // Z rotation is applied to the wrap in JS (applyTransforms), not the outer box.
                                                $element_style_table = 'style="width: '.intval($w).'px; height: '.intval($h).'px; transform: rotateZ('.intval($z_rot).'deg); transform-origin: center center;"';
                                            }
                                            $picky_label_position = isset( $val['label_position'] ) ? (string) $val['label_position'] : 'bottom';
                                            if ( ! in_array( $picky_label_position, array( 'top', 'bottom' ), true ) ) {
                                                $picky_label_position = 'bottom';
                                            }
                                            $picky_label_font_size = isset( $val['label_font_size'] ) ? max( 8, min( 200, intval( $val['label_font_size'] ) ) ) : 12;
                                            $picky_label_color_raw = isset( $val['label_color'] ) ? (string) $val['label_color'] : '#333333';
                                            $picky_label_color      = picky_sanitize_hex_color( $picky_label_color_raw );
                                            if ( ! $picky_label_color ) {
                                                $picky_label_color = '#333333';
                                            }
                                            $picky_label_font_weight = isset( $val['label_font_weight'] ) ? (string) $val['label_font_weight'] : 'normal';
                                            $picky_allowed_label_w   = array( 'normal', 'bold', '300', '400', '500', '600', '700', '800', '900' );
                                            if ( ! in_array( $picky_label_font_weight, $picky_allowed_label_w, true ) ) {
                                                $picky_label_font_weight = 'normal';
                                            }
                                            $picky_show_id = '1';
                                            if ( isset( $val['show_id'] ) && (string) $val['show_id'] === '0' ) {
                                                $picky_show_id = '0';
                                            }
                                            $picky_custom_id = '';
                                            if ( isset( $val['custom_id'] ) && (string) $val['custom_id'] !== '' ) {
                                                $picky_custom_id = sanitize_text_field( (string) $val['custom_id'] );
                                            }
                                            $picky_display_id = $picky_custom_id !== '' ? $picky_custom_id : ( isset( $val['id'] ) ? (string) $val['id'] : '' );
                                            $picky_shape_radius      = '';
                                            $picky_shape_bw          = 0;
                                            $picky_shape_bc          = '#333333';
                                            $picky_shape_outer_attrs = '';
                                            if ( isset( $val['class'] ) && $val['class'] === 'type-shape' ) {
                                                $picky_shape_radius = ( isset( $val['shape_radius'] ) && (string) $val['shape_radius'] !== '' ) ? (string) max( 0, min( 120, intval( $val['shape_radius'] ) ) ) : '';
                                                $picky_shape_bw     = isset( $val['shape_border_width'] ) ? max( 0, min( 32, intval( $val['shape_border_width'] ) ) ) : 0;
                                                $picky_shape_bc     = ( isset( $val['shape_border_color'] ) && (string) $val['shape_border_color'] !== '' )
                                                    ? picky_sanitize_hex_color( (string) $val['shape_border_color'] )
                                                    : '#333333';
                                                if ( ! is_string( $picky_shape_bc ) || $picky_shape_bc === '' ) {
                                                    $picky_shape_bc = '#333333';
                                                }
                                                $picky_shape_outer_attrs = ' data-shape-radius="' . esc_attr( $picky_shape_radius ) . '" data-shape-border-width="' . esc_attr( (string) $picky_shape_bw ) . '" data-shape-border-color="' . esc_attr( $picky_shape_bc ) . '"';
                                            }
                                            $picky_square_radius      = '';
                                            $picky_square_outer_attrs = '';
                                            $picky_saved_class        = isset( $val['class'] ) ? (string) $val['class'] : '';
                                            if ( $picky_saved_class !== '' && ! in_array( $picky_saved_class, array( 'type-wall', 'type-text', 'type-shape', 'type-4', 'type-circle' ), true ) ) {
                                                $picky_square_radius = ( isset( $val['square_radius'] ) && (string) $val['square_radius'] !== '' ) ? (string) max( 0, min( 120, intval( $val['square_radius'] ) ) ) : '';
                                                // Only output positive radius; 0/empty = CSS default (8px).
                                                if ( $picky_square_radius !== '' && (int) $picky_square_radius > 0 ) {
                                                    $picky_square_outer_attrs = ' data-square-radius="' . esc_attr( $picky_square_radius ) . '"';
                                                } else {
                                                    $picky_square_radius = '';
                                                }
                                            }
                                            $picky_wall_radius      = '';
                                            $picky_wall_outer_attrs = '';
                                            if ( $picky_saved_class === 'type-wall' ) {
                                                // Empty = CSS default (2px). Explicit 0 = sharp. 1–120 = override.
                                                if ( isset( $val['wall_radius'] ) && (string) $val['wall_radius'] !== '' ) {
                                                    $picky_wall_radius = (string) max( 0, min( 120, intval( $val['wall_radius'] ) ) );
                                                    $picky_wall_outer_attrs = ' data-wall-radius="' . esc_attr( $picky_wall_radius ) . '"';
                                                }
                                            }
                                            ?>
                                            <div
                                            class="picky-element <?php echo isset( $val['class'] ) ? esc_attr( (string)$val['class'] ) : ''; ?> <?php echo ( isset( $val['rotate'] ) && (string)$val['rotate'] !== '' ) ? esc_attr( 'rotate-' . (string)$val['rotate'] ) : ''; ?>"
                                            data-id="<?php echo esc_attr(isset($val['id']) ? (string)$val['id'] : ''); ?>"
                                            data-show-id="<?php echo esc_attr( $picky_show_id ); ?>"
                                            data-unique="<?php echo esc_attr( isset($val['unique']) ? (string)$val['unique'] : '' ); ?>"
                                            data-seats="<?php echo esc_attr( isset($val['seats']) ? (string)$val['seats'] : '' ); ?>"
                                            data-max-seats="<?php echo esc_attr( isset($val['max_seats']) ? (string)$val['max_seats'] : '' ); ?>"
                                            data-label="<?php echo isset($val['label']) ? esc_attr((string)$val['label']) : ''; ?>"
                                            data-label-position="<?php echo esc_attr( $picky_label_position ); ?>"
                                            data-label-font-size="<?php echo esc_attr( (string) $picky_label_font_size ); ?>"
                                            data-label-color="<?php echo esc_attr( $picky_label_color ); ?>"
                                            data-label-font-weight="<?php echo esc_attr( $picky_label_font_weight ); ?>"
                                            data-big-side="<?php echo esc_attr( isset($val['big_side']) ? (string)$val['big_side'] : '' ); ?>"
                                            data-type="<?php echo esc_attr( isset($val['class']) ? (string)$val['class'] : '' ); ?>"
                                            data-rotate="<?php echo esc_attr( isset($val['rotate']) ? (string)$val['rotate'] : '' ); ?>"
                                            data-rotate-z="<?php echo isset($val['rotate_z']) ? esc_attr((string)$val['rotate_z']) : '0'; ?>"
                                            data-rotate-x="<?php echo isset($val['rotate_x']) ? esc_attr((string)$val['rotate_x']) : '0'; ?>"
                                            data-rotate-y="<?php echo isset($val['rotate_y']) ? esc_attr((string)$val['rotate_y']) : '0'; ?>"
                                            data-z-index="<?php echo esc_attr( (string) ( isset( $val['z_index'] ) ? max( 1, intval( $val['z_index'] ) ) : ( 10 + (int) $key ) ) ); ?>"
                                            data-custom-url="<?php echo isset($val['custom_url']) ? esc_attr((string)$val['custom_url']) : ''; ?>"
                                            data-color="<?php echo isset($val['color']) ? esc_attr((string)$val['color']) : ''; ?>"
                                            data-text-color="<?php echo isset($val['text_color']) ? esc_attr((string)$val['text_color']) : '#333333'; ?>"
                                            data-text-color-mode="<?php echo ( isset( $val['text_color_mode'] ) && (string) $val['text_color_mode'] === 'theme' ) ? 'theme' : 'custom'; ?>"
                                            data-opacity="<?php echo isset($val['opacity']) ? esc_attr((string)$val['opacity']) : '1'; ?>"
                                            data-text="<?php echo isset($val['text']) ? esc_attr((string)$val['text']) : ''; ?>"
                                            data-font-size="<?php echo isset($val['font_size']) ? esc_attr((string)$val['font_size']) : '16'; ?>"
                                            data-line-height="<?php echo ( isset( $val['line_height'] ) && (string) $val['line_height'] !== '' ) ? esc_attr( (string) max( 8, min( 400, intval( $val['line_height'] ) ) ) ) : ''; ?>"
                                            data-font-family="<?php echo isset($val['font_family']) ? esc_attr((string)$val['font_family']) : 'inherit'; ?>"
                                            data-font-weight="<?php echo isset($val['font_weight']) ? esc_attr((string)$val['font_weight']) : 'normal'; ?>"
                                            data-shape-type="<?php echo isset($val['shape_type']) ? esc_attr((string)$val['shape_type']) : 'rectangle'; ?>"<?php echo $picky_shape_outer_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr. ?><?php echo $picky_square_outer_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr. ?><?php echo $picky_wall_outer_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr. ?>
                                            data-icon="<?php echo esc_attr( isset( $val['icon_key'] ) ? \Picky\Booking_Icons::sanitize( $val['icon_key'] ) : '' ); ?>"
                                            <?php
                                            $picky_svc_json = ( isset( $val['services'] ) && is_array( $val['services'] ) && count( $val['services'] ) > 0 )
                                                ? wp_json_encode( $val['services'] )
                                                : '[]';
                                            $picky_default_svc = isset( $val['default_service'] ) ? (string) $val['default_service'] : '';
                                            ?>
                                            data-services="<?php echo esc_attr( (string) $picky_svc_json ); ?>"
                                            data-default-service="<?php echo esc_attr( $picky_default_svc ); ?>"
                                            data-service-ids="<?php echo esc_attr( ( isset( $val['service_ids'] ) && is_array( $val['service_ids'] ) ) ? wp_json_encode( $val['service_ids'] ) : '[]' ); ?>"
                                            data-custom-id="<?php echo esc_attr( $picky_custom_id ); ?>"
                                            style="<?php echo esc_attr( (string)$element_style ); ?>">
                                                <div class="picky-element-table-wrap" <?php echo wp_kses_post( $element_style_table ); ?>>
                                                     <div class="picky-element-table">
                                                         <div class="picky-element-shape"<?php
                                                             if ( isset( $val['class'] ) && $val['class'] === 'type-icon' && ! empty( $val['color'] ) ) {
                                                                 echo ' style="color:' . esc_attr( (string) $val['color'] ) . ';background:transparent;"';
                                                             }
                                                         ?>>
                                                             <?php if(isset($val['class']) && $val['class'] === 'type-text' && isset($val['text'])): ?>
                                                                 <?php
                                                                 $picky_fs_render = isset( $val['font_size'] ) ? intval( $val['font_size'] ) : 16;
                                                                 if ( $picky_fs_render < 8 ) { $picky_fs_render = 8; }
                                                                 $picky_lh_render = ( isset( $val['line_height'] ) && (string) $val['line_height'] !== '' )
                                                                     ? max( 8, min( 400, intval( $val['line_height'] ) ) )
                                                                     : $picky_fs_render;
                                                                 $picky_ff_render = isset( $val['font_family'] ) ? (string) $val['font_family'] : 'inherit';
                                                                 /* Same stack as public when "inherit" — keeps builder/front glyphs identical. */
                                                                 if ( $picky_ff_render === '' || $picky_ff_render === 'inherit' ) {
                                                                     $picky_ff_render = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
                                                                 }
                                                                 ?>
                                                                 <div class="picky-element-text-content" style="
                                                                     font-size: <?php echo intval( $picky_fs_render ); ?>px;
                                                                     --picky-text-line-height: <?php echo intval( $picky_lh_render ); ?>px;
                                                                     line-height: <?php echo intval( $picky_lh_render ); ?>px;
                                                                     font-family: <?php echo esc_attr( $picky_ff_render ); ?>;
                                                                     font-weight: <?php echo isset($val['font_weight']) ? esc_attr($val['font_weight']) : 'normal'; ?>;
                                                                    color: <?php echo ( isset( $val['text_color_mode'] ) && (string) $val['text_color_mode'] === 'theme' ) ? 'inherit' : ( isset($val['text_color']) ? esc_attr($val['text_color']) : '#333333' ); ?>;
                                                                     padding: <?php echo isset($val['padding']) ? intval($val['padding']) : 10; ?>px;
                                                                 "><?php echo esc_html( $val['text'] ); ?></div>
                                                             <?php elseif ( isset( $val['class'] ) && $val['class'] === 'type-icon' ) : ?>
                                                                 <?php
                                                                 $picky_el_icon = isset( $val['icon_key'] ) ? \Picky\Booking_Icons::sanitize( $val['icon_key'] ) : '';
                                                                 if ( $picky_el_icon === '' ) {
                                                                     $picky_el_icon = 'star';
                                                                 }
                                                                 ?>
                                                                 <span class="picky-element-icon-wrap"><?php echo wp_kses( \Picky\Booking_Icons::svg_markup( $picky_el_icon, 'picky-bicon picky-element-icon' ), \Picky\Booking_Icons::kses_allowed() ); ?></span>
                                                             <?php endif; ?>
                                                         </div>
                                                    <button type="button" class="picky-rotate-handle" title="<?php esc_attr_e('Drag to rotate', 'pickybooking'); ?>"></button>
                                                    <button type="button" class="picky-3d-pad" title="<?php esc_attr_e('Drag to tilt (3D)', 'pickybooking'); ?>"><div class="picky-3d-handle"></div></button>
                                                     </div>
                                                <div class="picky-element-overlay">
                                                    <button type="button" class="picky-element-edit" aria-label="<?php esc_attr_e('Edit element', 'pickybooking'); ?>"></button>
                                                    <div class="picky-element-id" data-default="<?php echo esc_attr( isset($val['id']) ? (string)$val['id'] : '' ); ?>"<?php if ( ! $picky_builder_show_ids ) : ?> style="display:none;" aria-hidden="true"<?php endif; ?>><?php echo esc_html( $picky_display_id ); ?></div>
                                                    <div class="picky-element-seats"<?php if ( ! $picky_builder_show_seats ) : ?> style="display:none;" aria-hidden="true"<?php endif; ?>><?php echo esc_html( isset($val['seats']) ? (string)$val['seats'] : '' ); ?></div>
                                                    <div class="picky-element-label"<?php if ( ! $picky_builder_show_labels ) : ?> style="display:none;" aria-hidden="true"<?php endif; ?>><?php echo isset($val['label']) ? esc_html((string)$val['label']) : ''; ?></div>
                                                </div>

                                                    <div class="picky-element-seats-wrap"></div>
                                                </div>
                                                <div class="picky-element-edit-wrap" style="display: none;">
                                                    <div class="picky-element-edit-heading" aria-live="polite"></div>
                                                    <div class="picky-element-edit-fields">
                                                        <div class="picky-element-edit-field picky-field-max-seats">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Max persons', 'pickybooking'); ?></div>
                                                            <input type="number" min="0" step="1" value="<?php echo (isset($val['max_seats']) && intval($val['max_seats']) > 0) ? esc_attr((string)$val['max_seats']) : ''; ?>" class="picky-element-edit-max-persons-input picky-input">
                                                        </div>
                                                        <div class="picky-element-edit-field picky-field-label">
                                                            <div class="picky-element-edit-field-title<?php echo ( class_exists( '\\Picky\\Edition' ) && \Picky\Edition::allows( 'ai' ) ) ? ' picky-ai-field-title' : ''; ?>">
                                                                <span><?php esc_html_e('Name', 'pickybooking'); ?></span>
                                                                <?php if ( class_exists( '\\Picky\\Edition' ) && \Picky\Edition::allows( 'ai' ) ) : ?>
                                                                <button type="button" class="button button-small picky-ai-element-copy-btn" data-picky-ai-mode="label" title="<?php esc_attr_e( 'Generate name with AI', 'pickybooking' ); ?>"><?php esc_html_e( 'AI', 'pickybooking' ); ?></button>
                                                                <?php endif; ?>
                                                            </div>
                                                            <input type="text" value="<?php echo isset($val['label']) ? esc_attr((string)$val['label']) : ''; ?>" class="picky-element-edit-label-input picky-input">
                                                        </div>
                                                        <div class="picky-element-edit-field picky-field-label-position">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Name position', 'pickybooking' ); ?></div>
                                                            <select class="picky-element-edit-label-position-input picky-input">
                                                                <option value="bottom" <?php selected( $picky_label_position, 'bottom' ); ?>><?php esc_html_e( 'Below', 'pickybooking' ); ?></option>
                                                                <option value="top" <?php selected( $picky_label_position, 'top' ); ?>><?php esc_html_e( 'Above', 'pickybooking' ); ?></option>
                                                            </select>
                                                        </div>
                                                        <div class="picky-element-edit-field picky-field-label-color">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Label color', 'pickybooking' ); ?></div>
                                                            <input type="text" value="<?php echo esc_attr( $picky_label_color ); ?>" class="picky-element-edit-label-color-input picky-input" />
                                                        </div>
                                                        <div class="picky-element-edit-field picky-field-show-id">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Show/Hide ID', 'pickybooking'); ?></div>
                                                            <label class="picky-element-edit-show-id-label"><input type="checkbox" class="picky-element-edit-show-id-input" <?php checked( '1', $picky_show_id ); ?> aria-label="<?php esc_attr_e('Show/Hide ID on canvas', 'pickybooking'); ?>"></label>
                                                        </div>
                                                        <div class="picky-element-edit-field picky-field-custom-id picky-bookable-only-field">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Custom ID (optional)', 'pickybooking'); ?></div>
                                                            <input type="text" value="<?php echo isset($val['custom_id']) ? esc_attr((string)$val['custom_id']) : ''; ?>" class="picky-element-edit-custom-id-input picky-input" placeholder="<?php esc_attr_e('Leave blank to use auto-ID', 'pickybooking'); ?>" maxlength="64">
                                                        </div>
                                                        <div class="picky-element-edit-field picky-type-1-only-field picky-field-square-radius">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Corner radius (px)', 'pickybooking' ); ?></div>
                                                            <input type="number" min="0" max="120" step="1" value="<?php echo ( $picky_saved_class !== '' && ! in_array( $picky_saved_class, array( 'type-wall', 'type-text', 'type-shape', 'type-4', 'type-circle' ), true ) && isset( $val['square_radius'] ) && (string) $val['square_radius'] !== '' && intval( $val['square_radius'] ) > 0 ) ? esc_attr( (string) max( 1, min( 120, intval( $val['square_radius'] ) ) ) ) : ''; ?>" class="picky-element-edit-square-radius-input picky-input" placeholder="<?php esc_attr_e( 'Default', 'pickybooking' ); ?>" title="<?php esc_attr_e( 'Leave empty for default rounding (8px). Enter 1–120 to override.', 'pickybooking' ); ?>" />
                                                        </div>
                                                        <div class="picky-element-edit-field picky-text-only-field">
                                                            <div class="picky-element-edit-field-title">
                                                                <span><?php esc_html_e('Text Content', 'pickybooking'); ?></span>
                                                            </div>
                                                            <input type="text" class="picky-element-edit-text-input picky-input" placeholder="<?php esc_attr_e( 'Edit text field', 'pickybooking' ); ?>" value="<?php echo isset($val['text']) ? esc_attr((string)$val['text']) : ''; ?>" />
                                                        </div>
                                                        <div class="picky-element-edit-field picky-text-only-field picky-field-font-size">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Font Size', 'pickybooking'); ?></div>
                                                            <input type="number" min="8" max="200" step="1" value="<?php echo isset($val['font_size']) ? intval($val['font_size']) : 16; ?>" class="picky-element-edit-font-size-input picky-input">
                                                        </div>
                                                        <div class="picky-element-edit-field picky-text-only-field picky-field-line-height">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Line height (px)', 'pickybooking' ); ?></div>
                                                            <?php
                                                            $picky_line_height_val = '';
                                                            if ( isset( $val['line_height'] ) && (string) $val['line_height'] !== '' ) {
                                                                $picky_line_height_val = (string) max( 8, min( 400, intval( $val['line_height'] ) ) );
                                                            }
                                                            ?>
                                                            <input type="number" min="8" max="400" step="1" value="<?php echo esc_attr( $picky_line_height_val ); ?>" class="picky-element-edit-line-height-input picky-input" placeholder="<?php esc_attr_e( 'Auto', 'pickybooking' ); ?>" title="<?php esc_attr_e( 'Leave empty to match font size.', 'pickybooking' ); ?>">
                                                        </div>
                                                        <div class="picky-element-edit-field picky-text-only-field picky-field-font-family">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Font Family', 'pickybooking'); ?></div>
                                                            <select class="picky-element-edit-font-family-input picky-input">
                                                                <?php
                                                                $fonts = isset( $builder_font_family_options ) && is_array( $builder_font_family_options ) ? $builder_font_family_options : array('inherit' => 'Theme default (inherit)', 'sans-serif' => 'Sans Serif');
                                                                $current_font = isset($val['font_family']) ? $val['font_family'] : 'inherit';
                                                                foreach($fonts as $f_val => $f_label){
                                                                    echo '<option value="'.esc_attr((string)$f_val).'" '.selected($current_font, $f_val, false).'>'.esc_html((string)$f_label).'</option>';
                                                                }
                                                                ?>
                                                            </select>
                                                        </div>
                                                        <div class="picky-element-edit-field picky-text-only-field picky-field-font-weight">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Font Weight', 'pickybooking'); ?></div>
                                                            <select class="picky-element-edit-font-weight-input picky-input">
                                                                <?php
                                                                $weights = array('normal' => 'Normal', 'bold' => 'Bold', '300' => 'Light', '500' => 'Medium', '900' => 'Black');
                                                                $current_weight = isset($val['font_weight']) ? $val['font_weight'] : 'normal';
                                                                foreach($weights as $w_val => $w_label){
                                                                    echo '<option value="'.esc_attr((string)$w_val).'" '.selected($current_weight, $w_val, false).'>'.esc_html((string)$w_label).'</option>';
                                                                }
                                                                ?>
                                                            </select>
                                                        </div>
                                                        <div class="picky-element-edit-field picky-text-only-field picky-field-padding">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Padding', 'pickybooking'); ?></div>
                                                            <input type="number" min="0" step="1" value="<?php echo isset($val['padding']) ? intval($val['padding']) : 10; ?>" class="picky-element-edit-padding-input picky-input">
                                                        </div>
                                                        <div class="picky-element-edit-field picky-text-only-field">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Text Color', 'pickybooking'); ?></div>
                                                            <input type="text" value="<?php echo isset($val['text_color']) ? esc_attr((string)$val['text_color']) : '#333333'; ?>" class="picky-element-edit-text-color-input picky-input" />
                                                        </div>
                                                        <div class="picky-element-edit-field picky-text-only-field">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Text Color Source', 'pickybooking'); ?></div>
                                                            <select class="picky-element-edit-text-color-mode-input picky-input">
                                                                <?php $picky_text_color_mode = ( isset( $val['text_color_mode'] ) && (string) $val['text_color_mode'] === 'theme' ) ? 'theme' : 'custom'; ?>
                                                                <option value="custom" <?php selected( $picky_text_color_mode, 'custom' ); ?>><?php esc_html_e('Custom color', 'pickybooking'); ?></option>
                                                                <option value="theme" <?php selected( $picky_text_color_mode, 'theme' ); ?>><?php esc_html_e('Theme (inherit)', 'pickybooking'); ?></option>
                                                            </select>
                                                        </div>
                                                        <div class="picky-element-edit-field picky-shape-only-field">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Shape Type', 'pickybooking'); ?></div>
                                                            <select class="picky-element-edit-shape-type-input picky-input">
                                                                <?php
                                                                $shape_types = array('rectangle' => 'Rectangle', 'rounded' => 'Rounded', 'circle' => 'Circle', 'triangle' => 'Triangle');
                                                                $current_shape = isset($val['shape_type']) ? $val['shape_type'] : 'rectangle';
                                                                foreach($shape_types as $st_val => $st_label){
                                                                    echo '<option value="'.esc_attr((string)$st_val).'" '.selected($current_shape, $st_val, false).'>'.esc_html((string)$st_label).'</option>';
                                                                }
                                                                ?>
                                                            </select>
                                                        </div>
                                                        <div class="picky-element-edit-field picky-shape-only-field picky-field-shape-radius">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Corner radius (px)', 'pickybooking' ); ?></div>
                                                            <input type="number" min="0" max="120" step="1" value="<?php echo $picky_shape_radius === '' ? '' : esc_attr( $picky_shape_radius ); ?>" class="picky-element-edit-shape-radius-input picky-input" placeholder="<?php esc_attr_e( 'Auto', 'pickybooking' ); ?>" title="<?php esc_attr_e( 'Leave empty to use the preset for the selected shape type.', 'pickybooking' ); ?>" />
                                                        </div>
                                                        <div class="picky-element-edit-field picky-shape-only-field picky-field-shape-border-width">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Outline width (px)', 'pickybooking' ); ?></div>
                                                            <input type="number" min="0" max="32" step="1" value="<?php echo esc_attr( (string) $picky_shape_bw ); ?>" class="picky-element-edit-shape-border-width-input picky-input" />
                                                        </div>
                                                        <div class="picky-element-edit-field picky-shape-only-field picky-field-shape-border-color">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Outline color', 'pickybooking' ); ?></div>
                                                            <input type="text" value="<?php echo esc_attr( $picky_shape_bc ); ?>" class="picky-element-edit-shape-border-color-input picky-input" />
                                                        </div>
                                                        <div class="picky-element-edit-field picky-icon-only-field">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Icon', 'pickybooking' ); ?></div>
                                                            <?php
                                                            $picky_saved_icon = isset( $val['icon_key'] ) ? \Picky\Booking_Icons::sanitize( $val['icon_key'] ) : '';
                                                            if ( $picky_saved_icon === '' && isset( $val['class'] ) && $val['class'] === 'type-icon' ) {
                                                                $picky_saved_icon = 'star';
                                                            }
                                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- picker escapes its own output
                                                            echo wp_kses( \Picky\Booking_Icons::render_picker( '', '', $picky_saved_icon, 'picky-element-edit-icon-input' ), \Picky\Booking_Icons::kses_allowed() );
                                                            ?>
                                                        </div>
                                                        <div class="picky-element-edit-field picky-wall-only-field">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Thickness (px)', 'pickybooking'); ?></div>
                                                            <?php
                                                            if(isset($val['class']) && $val['class'] === 'type-wall'){
                                                                $wall_thickness = max(4, min(intval($w), intval($h)));
                                                            } else { $wall_thickness = 20; }
                                                            ?>
                                                            <input type="number" min="4" max="500" step="1" value="<?php echo esc_attr((string)$wall_thickness); ?>" class="picky-element-edit-thickness-input picky-input">
                                                        </div>
                                                        <div class="picky-element-edit-field picky-wall-only-field picky-field-wall-radius">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Corner radius (px)', 'pickybooking' ); ?></div>
                                                            <input type="number" min="0" max="120" step="1" value="<?php echo ( $picky_saved_class === 'type-wall' && $picky_wall_radius !== '' ) ? esc_attr( $picky_wall_radius ) : ''; ?>" class="picky-element-edit-wall-radius-input picky-input" placeholder="<?php esc_attr_e( 'Default (2)', 'pickybooking' ); ?>" title="<?php esc_attr_e( 'Leave empty for default rounding (2px). Enter 0 for sharp ends, or 1–120 to override.', 'pickybooking' ); ?>" />
                                                        </div>
                                                        <div class="picky-element-edit-field picky-field-opacity">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e('Opacity', 'pickybooking'); ?></div>
                                                            <?php $opacity_val = isset($val['opacity']) ? floatval($val['opacity']) : 1; ?>
                                                            <?php if($opacity_val < 0){ $opacity_val = 0; } ?>
                                                            <?php if($opacity_val > 1){ $opacity_val = 1; } ?>
                                                            <?php $opacity_percent = intval(round($opacity_val * 100)); ?>
                                                            <button type="button" class="picky-element-edit-opacity-btn picky-input" aria-haspopup="true" aria-expanded="false">
                                                                <span class="picky-element-edit-opacity-value"><?php echo esc_html((string)$opacity_percent); ?>%</span>
                                                            </button>
                                                            <div class="picky-element-edit-opacity-popup" style="display:none;" role="dialog">
                                                                <input type="range" min="0" max="100" step="5" value="<?php echo esc_attr((string)$opacity_percent); ?>" class="picky-element-edit-opacity-input">
                                                            </div>
                                                        </div>
                                                        <!-- Available services — chip multiselect from layout library -->
                                                        <div class="picky-element-edit-field picky-field-service-ids picky-bookable-only-field" style="display:none;">
                                                            <div class="picky-element-edit-field-title"><?php esc_html_e( 'Available services', 'pickybooking' ); ?></div>
                                                            <div class="picky-field-service-ids-chips">
                                                                <!-- Chips injected by JS from library -->
                                                            </div>
                                                            <p class="picky-field-service-ids-empty description" style="display:none;margin:4px 0 0;"><?php esc_html_e( 'No services in the library yet. Add services in the Services Library panel below the canvas.', 'pickybooking' ); ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="picky-element-edit-tools">
                                                        <button type="button" class="picky-element-edit-resize picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Resize', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Resize', 'pickybooking'); ?>"></button>
                                                        <button type="button" class="picky-element-edit-rotate picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Rotate', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Rotate', 'pickybooking'); ?>"></button>
                                                        <button type="button" class="picky-element-edit-3d picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('3D', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('3D', 'pickybooking'); ?>"></button>
                                                        <button type="button" class="picky-element-custom-upload picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Upload image', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Upload image', 'pickybooking'); ?>"></button>
                                                        <button type="button" class="picky-element-edit-color picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Color', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Color', 'pickybooking'); ?>"></button>
                                                        <button type="button" class="picky-element-duplicate picky-element-edit-btn" data-picky-tip="<?php esc_attr_e( 'Duplicate selection (Ctrl/Cmd+D)', 'pickybooking' ); ?>" aria-label="<?php esc_attr_e( 'Duplicate selection', 'pickybooking' ); ?>"></button>
                                                        <button type="button" class="picky-element-layer-front picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Bring to front', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Bring to front', 'pickybooking'); ?>"></button>
                                                        <button type="button" class="picky-element-layer-back picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Send to back', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Send to back', 'pickybooking'); ?>"></button>
                                                        <button type="button" class="picky-element-delete picky-element-edit-btn" data-picky-tip="<?php esc_attr_e('Delete', 'pickybooking'); ?>" aria-label="<?php esc_attr_e('Delete', 'pickybooking'); ?>"></button>
                                                    </div>
                                                    <div class="picky-element-color-picker-wrap" style="display:none;">
                                                        <input type="text" class="picky-element-color-input" value="">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php
                                        }
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div> <!-- End .picky-builder-main-container -->

        <?php
        $picky_layout_library_json = (string) get_post_meta( $layout_id, '_picky_layout_services_library', true );
        $picky_layout_multi_select   = (string) get_post_meta( $layout_id, '_picky_layout_multi_select', true );
        if ( $picky_layout_library_json === '' ) { $picky_layout_library_json = '[]'; }
        ?>
        <input type="hidden" name="picky_layout_services_library" form="picky-options-save" id="picky-layout-services-library-input" value="<?php echo esc_attr( $picky_layout_library_json ); ?>">

        <!-- Services Library Panel -->
        <div id="picky-services-library-panel" class="picky-services-library-panel">
            <div class="picky-services-library-header">
                <h3 class="picky-services-library-title"><?php esc_html_e( 'Services Library', 'pickybooking' ); ?></h3>
                <button type="button" class="button picky-services-library-add-btn"><?php esc_html_e( '+ Add service', 'pickybooking' ); ?></button>
            </div>
            <p class="picky-services-library-empty description" style="display:none;"><?php esc_html_e( 'No services yet. Click "+ Add service" to create one.', 'pickybooking' ); ?></p>
            <table class="picky-services-library-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'pickybooking' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'pickybooking' ); ?></th>
                        <th><?php esc_html_e( 'Price', 'pickybooking' ); ?></th>
                        <th><?php esc_html_e( 'Duration', 'pickybooking' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'pickybooking' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'pickybooking' ); ?></th>
                    </tr>
                </thead>
                <tbody class="picky-services-library-list">
                    <!-- Rows rendered by JS -->
                </tbody>
            </table>
            <!-- Inline editor template -->
            <div class="picky-services-library-editor">
                <input type="hidden" class="picky-lib-edit-sid" value="">
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:140px;">
                        <label><?php esc_html_e( 'Name', 'pickybooking' ); ?></label>
                        <input type="text" class="picky-lib-edit-name picky-input" placeholder="<?php esc_attr_e( 'Service name', 'pickybooking' ); ?>">
                    </div>
                    <div style="flex:0 0 100px;">
                        <label><?php esc_html_e( 'Price', 'pickybooking' ); ?></label>
                        <input type="number" min="0" step="0.01" class="picky-lib-edit-price picky-input" value="0">
                    </div>
                    <div style="flex:0 0 110px;">
                        <label><?php esc_html_e( 'Duration (min)', 'pickybooking' ); ?></label>
                        <input type="number" min="1" step="1" class="picky-lib-edit-duration picky-input" value="30">
                    </div>
                    <div style="flex:1;min-width:180px;">
                        <label><?php esc_html_e( 'Description', 'pickybooking' ); ?></label>
                        <input type="text" class="picky-lib-edit-description picky-input" placeholder="<?php esc_attr_e( 'Short description shown to customers', 'pickybooking' ); ?>" maxlength="200">
                    </div>
                </div>
                    <div class="picky-lib-edit-parents-wrap" style="margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0;">
                    <label><?php esc_html_e( 'Add-on of (parent services)', 'pickybooking' ); ?></label>
                    <p class="description" style="margin:0 0 6px;font-size:11px;"><?php esc_html_e( 'Leave empty to make this a top-level service. Tick parents to turn it into an add-on visible only when those parents are selected.', 'pickybooking' ); ?></p>
                    <div class="picky-lib-edit-parents-list" style="display:flex;flex-wrap:wrap;gap:6px 10px;max-height:140px;overflow:auto;padding:4px 0;">
                        <!-- Parent checkboxes rendered by JS -->
                    </div>
                    <p class="picky-lib-edit-parents-empty description" style="display:none;margin:0;color:#94a3b8;"><?php esc_html_e( 'No top-level services available yet — create one first to allow add-ons.', 'pickybooking' ); ?></p>
                </div>
                <div class="picky-lib-edit-icon-wrap" style="margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0;">
                    <label for="picky_lib_edit_icon"><strong><?php esc_html_e( 'Booking popup icon', 'pickybooking' ); ?></strong></label>
                    <p class="description" style="margin:0 0 6px;font-size:11px;"><?php esc_html_e( 'Optional icon shown beside this service in the booking confirmation popup.', 'pickybooking' ); ?></p>
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- picker escapes its own output
                    echo wp_kses( \Picky\Booking_Icons::render_picker( '', 'picky_lib_edit_icon', '', 'picky-lib-edit-icon' ), \Picky\Booking_Icons::kses_allowed() );
                    ?>
                </div>
                <div style="margin-top:12px;text-align:right;display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
                    <button type="button" class="button picky-lib-edit-cancel"><?php esc_html_e( 'Cancel', 'pickybooking' ); ?></button>
                    <button type="button" class="button button-primary picky-btn-brand picky-lib-edit-save"><?php esc_html_e( 'Save', 'pickybooking' ); ?></button>
                </div>
            </div>
        </div>

        <!-- Floating save + library import — always visible, fixed bottom-right -->
        <div class="picky-floating-save">
            <a class="picky-library-btn" href="<?php echo esc_url( \Picky\Layout_Library::library_url( 'picky-layout-presets' ) ); ?>">
                <span class="picky-library-btn__icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/></svg>
                </span>
                <span class="picky-library-btn__label"><?php esc_html_e( 'Presets', 'pickybooking' ); ?></span>
            </a>
            <button type="submit" form="picky-options-save" class="picky-save-btn" data-label="<?php echo esc_attr( (string) $submit_text ); ?>" data-saving-label="<?php echo esc_attr__( 'Saving…', 'pickybooking' ); ?>">
                <span class="picky-save-btn__spinner" aria-hidden="true"></span>
                <span class="picky-save-btn__label"><?php echo esc_html( (string) $submit_text ); ?></span>
            </button>
        </div>

        <div class="picky-layout-backgrounds-wrap">
            <section class="picky-layout-backgrounds-section" aria-labelledby="picky-layout-bg-title">
                <h2 id="picky-layout-bg-title" class="picky-layout-backgrounds-section__title"><?php esc_html_e( 'Layout appearance', 'pickybooking' ); ?></h2>
                <p class="picky-layout-backgrounds-section__lead description"><?php esc_html_e( 'Canvas and item colors/images, fit mode, transparency, and how the live map shows the center seat/capacity badge.', 'pickybooking' ); ?></p>
                <div class="picky-layout-backgrounds-grid">
                <div class="picky-background-field" data-background="background-canvas">
                    <?php
                    $picky_canvas_mode_value = (string) get_post_meta( $layout_id, '_picky_layout_bg_canvas_mode', true );
                    if ( $picky_canvas_mode_value === '' ) {
                        $picky_canvas_mode_value = isset( $options['background-canvas-mode'] ) ? (string) $options['background-canvas-mode'] : 'cover';
                    }
                    $picky_canvas_mode_choices = array(
                        'cover'    => __( 'Cover', 'pickybooking' ),
                        'contain'  => __( 'Contain', 'pickybooking' ),
                        'stretch'  => __( 'Stretch', 'pickybooking' ),
                        'repeat'   => __( 'Repeat', 'pickybooking' ),
                        'repeat-x' => __( 'Repeat X', 'pickybooking' ),
                        'repeat-y' => __( 'Repeat Y', 'pickybooking' ),
                        'center'   => __( 'Center', 'pickybooking' ),
                    );
                    ?>
                    <h3 class="picky-h3">
                        <label><?php esc_html_e( 'Canvas Background Image', 'pickybooking' ); ?></label>
                    </h3>
                    <?php
                    $picky_canvas_bg_color = picky_layout_canvas_bg_color( (int) $layout_id );
                    ?>
                    <p class="picky-layout-bg-color-field">
                        <label for="picky_layout_bg_canvas_color"><strong><?php esc_html_e( 'Canvas Background Color', 'pickybooking' ); ?></strong></label><br />
                        <input type="text" class="picky-settings-color picky-layout-bg-color" id="picky_layout_bg_canvas_color" name="picky_layout_bg_canvas_color" value="<?php echo esc_attr( $picky_canvas_bg_color ); ?>" data-default-color="<?php echo esc_attr( picky_default_canvas_bg_color() ); ?>" />
                    </p>
                    <p class="description"><?php esc_html_e( 'Solid plate behind the map. Upload an image below only if you want a photo or pattern on top of this color.', 'pickybooking' ); ?></p>
                    <?php echo wp_kses_post( $background_canvas ); ?>
                    <input type="hidden" name="picky_background_canvas" value="<?php echo esc_attr( (string) get_post_meta( $layout_id, '_picky_layout_bg_canvas', true ) ); ?>" />
                    <p>
                        <label for="picky_layout_bg_canvas_mode"><strong><?php esc_html_e( 'Canvas Background Fit Mode', 'pickybooking' ); ?></strong></label><br />
                        <select id="picky_layout_bg_canvas_mode" name="picky_layout_bg_canvas_mode" class="regular-text">
                            <?php foreach ( $picky_canvas_mode_choices as $picky_mode_key => $picky_mode_label ) : ?>
                                <option value="<?php echo esc_attr( (string) $picky_mode_key ); ?>" <?php selected( $picky_canvas_mode_value, $picky_mode_key ); ?>>
                                    <?php echo esc_html( (string) $picky_mode_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="picky-transparent-bg-option">
                        <input type="hidden" name="picky_layout_transparent_canvas" value="0" />
                        <label>
                            <input type="checkbox" name="picky_layout_transparent_canvas" value="1" <?php checked( $picky_transparent_canvas ); ?> />
                            <?php esc_html_e( 'Render Transparent Background', 'pickybooking' ); ?>
                        </label>
                    </p>
                    <p class="description"><?php esc_html_e( 'On the site, hides the gray grid behind the map; your canvas color/image or the page background shows through.', 'pickybooking' ); ?></p>
                </div>

                <div class="picky-background-field" data-background="background-table">
                    <h3 class="picky-h3">
                        <label><?php esc_html_e( 'Bookable items appearance', 'pickybooking' ); ?></label>
                    </h3>
                    <?php
                    $picky_item_bg_color = picky_layout_item_bg_color( (int) $layout_id );
                    $picky_booking_item_icon = \Picky\Booking_Icons::sanitize( (string) get_post_meta( $layout_id, \Picky\Booking_Icons::META_KEY, true ) );
                    ?>
                    <p class="picky-layout-bg-color-field">
                        <label for="picky_layout_bg_table_color"><strong><?php esc_html_e( 'Map fill color', 'pickybooking' ); ?></strong></label><br />
                        <input type="text" class="picky-settings-color picky-layout-bg-color" id="picky_layout_bg_table_color" name="picky_layout_bg_table_color" value="<?php echo esc_attr( $picky_item_bg_color ); ?>" data-default-color="<?php echo esc_attr( picky_default_item_bg_color() ); ?>" />
                    </p>
                    <p class="description"><?php esc_html_e( 'Default fill on the floor map for bookable items that do not have their own color.', 'pickybooking' ); ?></p>
                    <?php echo wp_kses_post( $background_table ); ?>
                    <input type="hidden" name="picky_background_table" value="<?php echo esc_attr( (string) get_post_meta( $layout_id, '_picky_layout_bg_table', true ) ); ?>" />
                    <p class="description"><?php esc_html_e( 'Optional shared texture/image on the map for items without their own image.', 'pickybooking' ); ?></p>
                    <div class="picky-layout-booking-item-icon-field">
                        <label for="picky_layout_booking_item_icon"><strong><?php esc_html_e( 'Booking popup icon', 'pickybooking' ); ?></strong></label>
                        <p class="description"><?php esc_html_e( 'Shown next to selected items in the booking confirmation popup only — not on the map.', 'pickybooking' ); ?></p>
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- picker escapes its own output
                        echo wp_kses( \Picky\Booking_Icons::render_picker( 'picky_layout_booking_item_icon', 'picky_layout_booking_item_icon', $picky_booking_item_icon ), \Picky\Booking_Icons::kses_allowed() );
                        ?>
                    </div>
                </div>

                <div class="picky-background-field picky-background-field--map-visibility" data-background="layout-map-visibility">
                    <h3 class="picky-h3"><?php esc_html_e( 'Map overlays on the public site', 'pickybooking' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Layout-only overrides. "Use site default" follows PickyBooking → Settings → Visibility options.', 'pickybooking' ); ?></p>
                    <div class="picky-layout-map-visibility-group">
                        <div class="picky-layout-map-visibility-item">
                            <label for="picky_layout_show_seats"><strong><?php esc_html_e( 'Center seat/capacity badge', 'pickybooking' ); ?></strong></label>
                            <select id="picky_layout_show_seats" name="picky_layout_show_seats" class="regular-text" form="picky-options-save">
                                <option value="" <?php selected( $picky_layout_show_seats_meta, '' ); ?>><?php esc_html_e( 'Use site default', 'pickybooking' ); ?></option>
                                <option value="1" <?php selected( $picky_layout_show_seats_meta, '1' ); ?>><?php esc_html_e( 'Show', 'pickybooking' ); ?></option>
                                <option value="0" <?php selected( $picky_layout_show_seats_meta, '0' ); ?>><?php esc_html_e( 'Hide', 'pickybooking' ); ?></option>
                            </select>
                        </div>
                        <div class="picky-layout-map-visibility-item">
                            <label for="picky_layout_show_ids"><strong><?php esc_html_e( 'ID badge', 'pickybooking' ); ?></strong></label>
                            <select id="picky_layout_show_ids" name="picky_layout_show_ids" class="regular-text" form="picky-options-save">
                                <option value="" <?php selected( $picky_layout_show_ids_meta, '' ); ?>><?php esc_html_e( 'Use site default', 'pickybooking' ); ?></option>
                                <option value="1" <?php selected( $picky_layout_show_ids_meta, '1' ); ?>><?php esc_html_e( 'Show', 'pickybooking' ); ?></option>
                                <option value="0" <?php selected( $picky_layout_show_ids_meta, '0' ); ?>><?php esc_html_e( 'Hide', 'pickybooking' ); ?></option>
                            </select>
                        </div>
                        <div class="picky-layout-map-visibility-item">
                            <label for="picky_layout_show_labels"><strong><?php esc_html_e( 'Element label', 'pickybooking' ); ?></strong></label>
                            <select id="picky_layout_show_labels" name="picky_layout_show_labels" class="regular-text" form="picky-options-save">
                                <option value="" <?php selected( $picky_layout_show_labels_meta, '' ); ?>><?php esc_html_e( 'Use site default', 'pickybooking' ); ?></option>
                                <option value="1" <?php selected( $picky_layout_show_labels_meta, '1' ); ?>><?php esc_html_e( 'Show', 'pickybooking' ); ?></option>
                                <option value="0" <?php selected( $picky_layout_show_labels_meta, '0' ); ?>><?php esc_html_e( 'Hide', 'pickybooking' ); ?></option>
                            </select>
                        </div>
                        <div class="picky-layout-map-visibility-item">
                            <label for="picky_layout_show_item_fill"><strong><?php esc_html_e( 'Item background fill', 'pickybooking' ); ?></strong></label>
                            <select id="picky_layout_show_item_fill" name="picky_layout_show_item_fill" class="regular-text" form="picky-options-save">
                                <option value="1" <?php selected( $picky_layout_show_item_fill_meta !== '0' ); ?>><?php esc_html_e( 'Show', 'pickybooking' ); ?></option>
                                <option value="0" <?php selected( $picky_layout_show_item_fill_meta, '0' ); ?>><?php esc_html_e( 'Hide', 'pickybooking' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Hide removes the solid plate behind bookable items (per-item color and the site “shape fill” color). Custom images still show.', 'pickybooking' ); ?></p>
                        </div>
                        <div class="picky-layout-map-visibility-item">
                            <label for="picky_layout_show_booked_avatars"><strong><?php esc_html_e( 'Booked guest avatars', 'pickybooking' ); ?></strong></label>
                            <select id="picky_layout_show_booked_avatars" name="picky_layout_show_booked_avatars" class="regular-text" form="picky-options-save">
                                <option value="0" <?php selected( $picky_layout_show_booked_avatars_meta !== '1' ); ?>><?php esc_html_e( 'Hide', 'pickybooking' ); ?></option>
                                <option value="1" <?php selected( $picky_layout_show_booked_avatars_meta, '1' ); ?>><?php esc_html_e( 'Show', 'pickybooking' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Optional: replace the ID badge with the booker’s photo when the seat is taken (logged-in bookings). Requires “Anyone can register” in WordPress Settings → General, or demo avatars.', 'pickybooking' ); ?></p>
                        </div>
                        <div class="picky-layout-map-visibility-item">
                            <label for="picky_layout_booked_avatars_tone"><strong><?php esc_html_e( 'Avatar color', 'pickybooking' ); ?></strong></label>
                            <select id="picky_layout_booked_avatars_tone" name="picky_layout_booked_avatars_tone" class="regular-text" form="picky-options-save">
                                <option value="color" <?php selected( $picky_layout_booked_avatars_tone_meta, 'color' ); ?>><?php esc_html_e( 'Color', 'pickybooking' ); ?></option>
                                <option value="bw" <?php selected( $picky_layout_booked_avatars_tone_meta, 'bw' ); ?>><?php esc_html_e( 'Black & white', 'pickybooking' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Applies a CSS filter on the map. Does not change the uploaded file.', 'pickybooking' ); ?></p>
                        </div>
                    </div>
                </div>

                <div class="picky-background-field picky-layout-wizard-flow" data-background="layout-wizard-flow">
                    <h3 class="picky-h3"><?php esc_html_e( 'Wizard flow on the public site', 'pickybooking' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Layout-only override of Settings → Wizard order. “Use site default” keeps the global Classic / Smart / Smart Map / Simple choice.', 'pickybooking' ); ?></p>
                    <p>
                        <label for="picky_layout_shortcode_layout"><strong><?php esc_html_e( 'Wizard order', 'pickybooking' ); ?></strong></label><br />
                        <select id="picky_layout_shortcode_layout" name="picky_layout_shortcode_layout" class="regular-text" form="picky-options-save">
                            <option value="" <?php selected( $picky_layout_shortcode_layout_meta, '' ); ?>><?php esc_html_e( 'Use site default', 'pickybooking' ); ?></option>
                            <option value="1" <?php selected( $picky_layout_shortcode_layout_meta, '1' ); ?>><?php esc_html_e( 'Classic — pick item first', 'pickybooking' ); ?></option>
                            <option value="2" <?php selected( $picky_layout_shortcode_layout_meta, '2' ); ?>><?php esc_html_e( 'Smart — date & time first', 'pickybooking' ); ?></option>
                            <option value="4" <?php selected( $picky_layout_shortcode_layout_meta, '4' ); ?>><?php esc_html_e( 'Smart Map — layout first', 'pickybooking' ); ?></option>
                            <option value="3" <?php selected( $picky_layout_shortcode_layout_meta, '3' ); ?>><?php esc_html_e( 'Simple — no map, time only', 'pickybooking' ); ?></option>
                        </select>
                    </p>
                </div>

                <div class="picky-background-field picky-layout-event-mode" data-background="layout-event-mode">
                    <h3 class="picky-h3"><?php esc_html_e( 'Event schedule', 'pickybooking' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Calendar (default) lets guests pick a date and time. One-time event hides the calendar and books every seat for a single date and time you set here — weddings, one-off games, premieres.', 'pickybooking' ); ?></p>
                    <?php
                    $picky_event_mode = (string) get_post_meta( (int) $layout_id, '_picky_layout_event_mode', true );
                    if ( $picky_event_mode !== 'once' ) {
                        $picky_event_mode = 'calendar';
                    }
                    $picky_event_date = picky_normalize_booking_date( (string) get_post_meta( (int) $layout_id, '_picky_layout_event_date', true ) );
                    $picky_event_time = picky_normalize_booking_time( (string) get_post_meta( (int) $layout_id, '_picky_layout_event_time', true ) );
                    $picky_event_date_html = picky_booking_date_to_html( $picky_event_date );
                    $picky_time_all = ( isset( \Picky\Info::$TIME_ALL ) && is_array( \Picky\Info::$TIME_ALL ) ) ? \Picky\Info::$TIME_ALL : array();
                    ?>
                    <div class="picky-layout-event-mode-options">
                        <label class="picky-layout-event-mode-option">
                            <input type="radio" name="picky_layout_event_mode" value="calendar" form="picky-options-save" <?php checked( $picky_event_mode, 'calendar' ); ?> />
                            <span><?php esc_html_e( 'Calendar — guests pick date and time', 'pickybooking' ); ?></span>
                        </label>
                        <label class="picky-layout-event-mode-option">
                            <input type="radio" name="picky_layout_event_mode" value="once" form="picky-options-save" <?php checked( $picky_event_mode, 'once' ); ?> />
                            <span><?php esc_html_e( 'One-time event — fixed date and time', 'pickybooking' ); ?></span>
                        </label>
                    </div>
                    <div class="picky-layout-event-once-fields" style="<?php echo ( $picky_event_mode === 'once' ) ? 'display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-top:10px;' : 'display:none;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-top:10px;'; ?>">
                        <p style="margin:0;">
                            <label for="picky_layout_event_date"><strong><?php esc_html_e( 'Event date', 'pickybooking' ); ?></strong></label><br />
                            <input type="date" id="picky_layout_event_date" name="picky_layout_event_date" form="picky-options-save" value="<?php echo esc_attr( $picky_event_date_html ); ?>" />
                        </p>
                        <p style="margin:0;">
                            <label for="picky_layout_event_time"><strong><?php esc_html_e( 'Event time', 'pickybooking' ); ?></strong></label><br />
                            <select id="picky_layout_event_time" name="picky_layout_event_time" form="picky-options-save">
                                <option value=""><?php esc_html_e( 'Select time', 'pickybooking' ); ?></option>
                                <?php foreach ( $picky_time_all as $slot ) : ?>
                                    <option value="<?php echo esc_attr( (string) $slot ); ?>" <?php selected( $picky_event_time, (string) $slot ); ?>><?php echo esc_html( (string) $slot ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <?php
                        $picky_event_show_label = (string) get_post_meta( (int) $layout_id, '_picky_layout_event_show_label', true );
                        if ( $picky_event_show_label !== '0' ) {
                            $picky_event_show_label = '1';
                        }
                        ?>
                        <p style="margin:0;flex:1 1 100%;">
                            <label>
                                <input type="hidden" name="picky_layout_event_show_label" value="0" form="picky-options-save" />
                                <input type="checkbox" id="picky_layout_event_show_label" name="picky_layout_event_show_label" value="1" form="picky-options-save" <?php checked( $picky_event_show_label, '1' ); ?> />
                                <?php esc_html_e( 'Show event date and time on the booking bar', 'pickybooking' ); ?>
                            </label>
                            <span class="description" style="display:block;margin-top:4px;"><?php esc_html_e( 'Guests will see the fixed date and time as text. The calendar and time dropdown stay hidden either way.', 'pickybooking' ); ?></span>
                        </p>
                    </div>
                </div>

                <div class="picky-background-field picky-layout-multi-select-toggle" data-background="layout-multi-select">
                    <h3 class="picky-h3"><?php esc_html_e( 'Booking mode', 'pickybooking' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Allow customers to select multiple items at once and optionally pick different services per item.', 'pickybooking' ); ?></p>
                    <?php /* Hidden ensures POST always includes this key when unchecked (checkbox omitted). PHP uses the last value when both are sent. */ ?>
                    <label style="display:block;margin-top:8px;">
                        <input type="hidden" name="picky_layout_multi_select" value="0" form="picky-options-save" />
                        <input type="checkbox" id="picky_layout_multi_select" name="picky_layout_multi_select" value="1" form="picky-options-save" <?php checked( $picky_layout_multi_select, '1' ); ?> />
                        <?php esc_html_e( 'Enable multi-select', 'pickybooking' ); ?>
                    </label>
                </div>
                </div>
            </section>
        </div>
            <?php
            $canvas_style  = '';
            $element_style = '';
            $bg_canvas_val  = get_post_meta( $layout_id, '_picky_layout_bg_canvas', true );
            $bg_canvas_mode = (string) get_post_meta( $layout_id, '_picky_layout_bg_canvas_mode', true );
            if ( $bg_canvas_mode === '' ) {
                $bg_canvas_mode = isset( $options['background-canvas-mode'] ) ? (string) $options['background-canvas-mode'] : 'cover';
            }
            $bg_table_val = get_post_meta( $layout_id, '_picky_layout_bg_table', true );
            $picky_canvas_bg_color_css = picky_layout_canvas_bg_color( (int) $layout_id );
            $picky_item_bg_color_css   = picky_layout_item_bg_color( (int) $layout_id );
            $canvas_style            = 'background-color: ' . esc_attr( $picky_canvas_bg_color_css ) . ';';
            if ( $bg_canvas_val ) {
                $bg_canvas_url = esc_url( (string) ( is_numeric( $bg_canvas_val ) ? wp_get_attachment_url( $bg_canvas_val ) : $bg_canvas_val ) );
                $canvas_style .= "background-image: url({$bg_canvas_url});";
                $picky_bg_size_map = array(
                    'cover'    => 'background-size: cover; background-repeat: no-repeat; background-position: center center;',
                    'contain'  => 'background-size: contain; background-repeat: no-repeat; background-position: center center;',
                    'stretch'  => 'background-size: 100% 100%; background-repeat: no-repeat; background-position: center center;',
                    'repeat-x' => 'background-size: auto; background-repeat: repeat-x; background-position: top left;',
                    'repeat-y' => 'background-size: auto; background-repeat: repeat-y; background-position: top left;',
                    'center'   => 'background-size: auto; background-repeat: no-repeat; background-position: center center;',
                );
                $canvas_style .= isset( $picky_bg_size_map[ $bg_canvas_mode ] )
                    ? $picky_bg_size_map[ $bg_canvas_mode ]
                    : 'background-size: auto; background-repeat: repeat; background-position: top left;';
            }
            if ( $bg_table_val ) {
                $bg_table_url  = esc_url( (string) ( is_numeric( $bg_table_val ) ? wp_get_attachment_url( $bg_table_val ) : $bg_table_val ) );
                $element_style = "background-image: url({$bg_table_url});";
            }
            ?>
            <div class="picky-layout-bottom-actions">
                <button type="submit" class="picky-save-btn picky-save-btn-bottom" data-label="<?php echo esc_attr( (string) $submit_text ); ?>" data-saving-label="<?php echo esc_attr__( 'Saving…', 'pickybooking' ); ?>">
                    <span class="picky-save-btn__spinner" aria-hidden="true"></span>
                    <span class="picky-save-btn__label"><?php echo esc_html( (string) $submit_text ); ?></span>
                </button>
            </div>
        </form>

        <?php
        /* Canvas background color and image are attached with wp_add_inline_style. */
        $picky_appearance_css  = '.picky-draggable-wrap:not(.picky-canvas-transparent-bg){background-color:' . esc_attr( $picky_canvas_bg_color_css ) . ' !important;}';
        $picky_appearance_css .= '.picky-draggable:not(.no-bg):not(.picky-canvas-transparent-bg){' . esc_attr( $canvas_style ) . '}';
        $picky_appearance_css .= '.picky-draggable .picky-element:not(.type-text):not(.type-wall):not(.type-shape):not(.type-icon):not(.no-bg-table) .picky-element-shape:not([style*="background-image"]):not([style*="background-color"]){background-color:' . esc_attr( $picky_item_bg_color_css ) . ' !important;}';
        if ( $element_style ) {
            $picky_appearance_css .= '.picky-element:not(.type-text):not(.type-wall):not(.type-shape):not(.type-icon):not(.no-bg-table) .picky-element-shape{' . esc_attr( $element_style ) . '}';
        }
        $picky_appearance_ver = class_exists( '\Picky\Info' ) ? \Picky\Info::VERSION : '1.0.2';
        wp_register_style( 'picky-layout-appearance', false, array(), $picky_appearance_ver );
        wp_enqueue_style( 'picky-layout-appearance' );
        wp_add_inline_style( 'picky-layout-appearance', $picky_appearance_css );
        ?>

    </div><!-- /.picky-builder-surface / #picky-wrap-content -->
    <div class="clear"></div>
</div><!-- /.picky-wrap -->
