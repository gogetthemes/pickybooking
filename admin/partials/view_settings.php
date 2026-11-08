<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="picky-root picky-wrap picky-wrap--settings" data-picky-context="settings">
    <!-- <h1 class="picky-settings-page-title"><?php echo esc_html( (string)$heading ); ?></h1> -->
    <?php
    // Show all admin notices for this screen. WordPress uses the 'general' slug for
    // "Settings saved"; SMS balance check uses 'sms_invalid_data' — calling only the
    // latter hid every notice when SMS feedback was skipped.
    settings_errors();
    ?>

    <?php
    $picky_settings_tab_q = isset( $picky_settings_tab_q ) ? sanitize_text_field( (string) $picky_settings_tab_q ) : '';
    $picky_settings_tab_n = (int) $picky_settings_tab_q;
    if ( $picky_settings_tab_q === '' || $picky_settings_tab_n < 1 ) {
        $picky_shell_layout_class = ' picky-settings-shell--app-tabs';
    } elseif ( ( $picky_settings_tab_n >= 1 && $picky_settings_tab_n <= 6 ) || 12 === $picky_settings_tab_n ) {
        $picky_shell_layout_class = ' picky-settings-shell--option-tabs';
    } else {
        $picky_shell_layout_class = ' picky-settings-shell--app-tabs';
    }
    ?>
    <div id="picky-wrap-content" class="picky-settings-surface<?php echo esc_attr( $picky_shell_layout_class ); ?>" data-picky-settings-url="<?php echo esc_attr( admin_url( 'admin.php?page=picky_settings' ) ); ?>">
        <?php
        // Panel collapse state is applied by the enqueued admin script.
        ?>
        <?php
        $options = get_option( $option_name );
        $allowed_html = wp_kses_allowed_html( 'post' );
        $allowed_html['div'] = array_merge(
            isset( $allowed_html['div'] ) ? $allowed_html['div'] : array(),
            array(
                'id'       => true,
                'class'    => true,
                'style'    => true,
                'data-tab' => true,
                'role'     => true,
            )
        );
        $allowed_html['p'] = array_merge(
            isset( $allowed_html['p'] ) ? $allowed_html['p'] : array(),
            array(
                'class' => true,
                'style' => true,
            )
        );
        $allowed_html['span'] = array_merge(
            isset( $allowed_html['span'] ) ? $allowed_html['span'] : array(),
            array(
                'class'       => true,
                'style'       => true,
                'role'        => true,
                'aria-hidden' => true,
            )
        );
        $allowed_html['h2'] = array(
            'class' => true,
            'id'    => true,
        );
        $allowed_html['h3'] = array(
            'class' => true,
        );
        $allowed_html['h4'] = array(
            'class' => true,
        );
        $allowed_html['ul'] = array(
            'class'       => true,
            'id'          => true,
            'role'        => true,
            'aria-label'  => true,
        );
        $allowed_html['ol'] = array(
            'class' => true,
        );
        $allowed_html['li'] = array(
            'class' => true,
        );
        $allowed_html['section'] = array(
            'class' => true,
        );
        $allowed_html['article'] = array(
            'class' => true,
        );
        $allowed_html['img'] = array(
            'class'     => true,
            'src'       => true,
            'alt'       => true,
            'width'     => true,
            'height'    => true,
            'decoding'  => true,
            'loading'   => true,
        );
        $allowed_html['label'] = array(
            'for'   => true,
            'class' => true,
        );
        $allowed_html['fieldset'] = array(
            'class' => true,
        );
        $allowed_html['input'] = array(
            'type'     => true,
            'id'       => true,
            'name'     => true,
            'value'    => true,
            'class'    => true,
            'min'      => true,
            'max'      => true,
            'step'     => true,
            'checked'  => true,
            'selected' => true,
            'accept'   => true,
            'placeholder' => true,
            'multiple' => true,
        );
        $allowed_html['textarea'] = array(
            'id'       => true,
            'name'     => true,
            'class'    => true,
            'rows'     => true,
            'cols'     => true,
            'style'    => true,
            'placeholder' => true,
        );
        $allowed_html['select'] = array(
            'id'       => true,
            'name'     => true,
            'class'    => true,
            'multiple' => true,
            'style'    => true,
        );
        $allowed_html['option'] = array(
            'value'    => true,
            'selected' => true,
            'disabled' => true,
        );
        $allowed_html['details'] = array(
            'class' => true,
            'id'    => true,
            'open'  => true,
        );
        $allowed_html['summary'] = array(
            'class'       => true,
            'aria-label'  => true,
        );
        $allowed_html['iframe'] = array(
            'src'    => true,
            'class'  => true,
            'title'  => true,
            'loading'=> true,
            'style'  => true,
        );
        $allowed_html['table'] = array(
            'class' => true,
            'role'  => true,
        );
        $allowed_html['tbody'] = array( 'class' => true );
        $allowed_html['thead'] = array( 'class' => true );
        $allowed_html['tr']    = array( 'class' => true );
        $allowed_html['th']    = array(
            'scope' => true,
            'class' => true,
        );
        $allowed_html['td']    = array( 'class' => true );
        $allowed_html['strong'] = array( 'class' => true );
        $allowed_html['code']   = array( 'class' => true );
        $allowed_html['a']     = array_merge(
            isset( $allowed_html['a'] ) ? $allowed_html['a'] : array(),
            array(
                'href'     => true,
                'class'    => true,
                'id'       => true,
                'title'    => true,
                'data-tab' => true,
                'role'     => true,
            )
        );
        $allowed_html['button'] = array(
            'type'                => true,
            'class'               => true,
            'title'               => true,
            'aria-expanded'       => true,
            'aria-hidden'         => true,
            'hidden'              => true,
            'data-title-expanded' => true,
            'data-title-collapsed'=> true,
        );
        $allowed_html['aside'] = array(
            'class'      => true,
            'aria-label' => true,
        );
        $has_markers = strpos( $fields, '<!--picky_settings_form_start-->' ) !== false && strpos( $fields, '<!--picky_settings_form_end-->' ) !== false;

        if ( $has_markers ) {
            $before_after = explode( '<!--picky_settings_form_start-->', $fields, 2 );
            $before_form  = $before_after[0];
            $rest         = isset( $before_after[1] ) ? $before_after[1] : '';
            $mid_after    = explode( '<!--picky_settings_form_end-->', $rest, 2 );
            $inside_form  = $mid_after[0];
            $after_form   = isset( $mid_after[1] ) ? $mid_after[1] : '';

            /*
             * Before the markers: nav + app panels + embedded layout builder. Do not pass through wp_kses()
             * using "post" rules: those disallow <form>, strip many attributes from buttons/inputs, and can
             * remove style elements — breaking Save Layout and all JS hooks (#picky-undo, etc.). This HTML is produced
             * only by Admin::custom_settings_fields(), not end-user content.
             */
            echo $before_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            ob_start();
            settings_fields( $settings_group );
            $settings_fields_out = ob_get_clean();

            echo '<form id="options-save" action="options.php" method="post">';
            echo $settings_fields_out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core settings_fields() markup.
            echo wp_kses( $inside_form, $allowed_html );
            echo '<div class="submit-wrap picky-settings-submit-wrap">';
            submit_button( $submit_text );
            echo '<div class="spinner"></div></div>';
            echo '</form>';

            echo $after_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Closing wrappers only; same trusted output pipeline as $before_form.
        } else {
            ?>
            <form id="options-save" action="options.php" method="post">
                <?php settings_fields( $settings_group ); ?>
                <?php echo wp_kses( $fields, $allowed_html ); ?>
                <div class="submit-wrap picky-settings-submit-wrap">
                    <?php submit_button( $submit_text ); ?>
                    <div class="spinner"></div>
                </div>
            </form>
            <?php
        }
        ?>

    </div>
    <br class="clear">
</div>
