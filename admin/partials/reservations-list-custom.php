<?php
/**
 * Custom reservations table (embedded Settings tab). Uses WP_List_Table data + actions; markup is plugin-owned.
 *
 * @var \Picky\Admin                  $this
 * @var \Picky\Picky_Reservations_List_Facade $picky_facade
 * @var \WP_Post[]                            $picky_posts
 * @var array<string, string>                 $picky_columns
 * @var \WP_Post_Type                         $pto
 * @var array<string, mixed>                  $picky_embed_query
 * @var int                                   $picky_found_posts
 * @var string                                $picky_embed_list_title
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="picky-cal-head">
	<div class="picky-cal-head__main">
		<div class="picky-cal-head__title-row">
			<h1><?php echo esc_html( $picky_embed_list_title !== '' ? $picky_embed_list_title : __( 'Tables Reservations', 'pickybooking' ) ); ?></h1>
			<div class="picky-cal-stats">
				<span class="picky-cal-stat"><strong><?php echo esc_html( number_format_i18n( $picky_found_posts ) ); ?></strong><?php echo esc_html__( 'Bookings', 'pickybooking' ); ?></span>
			</div>
		</div>
		<p class="picky-cal-subtitle"><?php echo esc_html__( 'Search, filter, and manage table bookings from this list. Status and layout filters match the calendar tab for a consistent workflow.', 'pickybooking' ); ?></p>
	</div>
</div>

<form id="posts-filter" class="picky-reservations-custom__form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
	<input type="hidden" name="page" value="picky_settings" />
	<input type="hidden" name="picky-tab" value="<?php echo esc_attr( isset( $picky_embed_query['picky-tab'] ) ? (string) $picky_embed_query['picky-tab'] : '7' ); ?>" />
	<input type="hidden" name="post_type" value="picky_booking" />

	<?php $picky_facade->picky_display_tablenav( 'top' ); ?>

	<div class="picky-reservations-custom__table-wrap">
		<table class="picky-reservations-custom__table" cellspacing="0">
			<thead>
				<tr>
					<?php $picky_facade->print_column_headers(); ?>
				</tr>
			</thead>
			<tbody id="the-list" data-wp-lists="list:picky_booking">
				<?php
				if ( empty( $picky_posts ) ) {
					$colspan = max( 1, (int) $picky_facade->get_column_count() );
					echo '<tr class="no-items"><td class="picky-reservations-custom__empty" colspan="' . esc_attr( (string) $colspan ) . '">';
					$picky_facade->no_items();
					echo '</td></tr>';
				} else {
					foreach ( $picky_posts as $post ) {
						if ( ! ( $post instanceof \WP_Post ) ) {
							continue;
						}
						$pid = (int) $post->ID;
						$row_classes = get_post_class( '', $pid );
						$row_class   = is_array( $row_classes ) ? implode( ' ', $row_classes ) : '';
						echo '<tr id="post-' . esc_attr( (string) $pid ) . '" class="' . esc_attr( $row_class ) . '">';
						foreach ( $picky_columns as $column_name => $column_display ) {
							$classes = 'picky-reservations-custom__td column-' . sanitize_html_class( $column_name );
							if ( $column_name === 'cb' ) {
								$classes .= ' check-column';
							}
							echo '<td class="' . esc_attr( $classes ) . '" data-colname="' . esc_attr( wp_strip_all_tags( (string) $column_display ) ) . '">';
							switch ( $column_name ) {
								case 'cb':
									if ( current_user_can( 'edit_post', $pid ) ) {
										echo '<input id="cb-select-' . esc_attr( (string) $pid ) . '" type="checkbox" name="post[]" value="' . esc_attr( (string) $pid ) . '" />';
									}
									break;
								case 'title':
									$can_edit = current_user_can( 'edit_post', $pid );
									$title    = get_the_title( $post );
									if ( $title === '' ) {
										$title = __( '(no title)', 'pickybooking' );
									}
									echo '<strong>';
									if ( $can_edit ) {
										$link = get_edit_post_link( $pid );
										if ( $link ) {
											echo '<a class="picky-reservations-custom__title-link" href="' . esc_url( (string) $link ) . '">' . esc_html( (string) $title ) . '</a>';
										} else {
											echo esc_html( (string) $title );
										}
									} else {
										echo esc_html( (string) $title );
									}
									echo '</strong>';
									$actions = array();
									if ( $can_edit ) {
										$el = get_edit_post_link( $pid );
										if ( $el ) {
											$actions['edit'] = '<a href="' . esc_url( (string) $el ) . '">' . esc_html__( 'Edit', 'pickybooking' ) . '</a>';
										}
									}
									if ( current_user_can( 'delete_post', $pid ) ) {
										if ( get_post_status( $post ) === 'trash' ) {
											$actions['untrash'] = '<a href="' . esc_url( (string) wp_nonce_url( admin_url( 'post.php?post=' . $pid . '&action=untrash' ), 'untrash-post_' . $pid ) ) . '">' . esc_html__( 'Restore', 'pickybooking' ) . '</a>';
										} else {
											$del = get_delete_post_link( $pid, '', false );
											if ( $del ) {
												$actions['trash'] = '<a href="' . esc_url( (string) $del ) . '" class="submitdelete">' . esc_html__( 'Trash', 'pickybooking' ) . '</a>';
											}
										}
									}
									$actions = apply_filters( 'post_row_actions', $actions, $post );
									if ( ! empty( $actions ) ) {
										echo '<div class="picky-reservations-custom__row-actions">';
										$sep = '';
										foreach ( $actions as $action ) {
											echo wp_kses(
												$sep . $action,
												array(
													'a'    => array(
														'href'  => true,
														'class' => true,
													),
													'span' => array(
														'class' => true,
													),
												)
											);
											$sep = ' <span class="picky-reservations-custom__row-actions-sep">|</span> ';
										}
										echo '</div>';
									}
									break;
								default:
									/** This action is documented in wp-admin/includes/class-wp-posts-list-table.php */
									do_action( 'manage_picky_booking_posts_custom_column', $column_name, $pid );
									break;
							}
							echo '</td>';
						}
						echo '</tr>';
					}
				}
				?>
			</tbody>
		</table>
	</div>

	<?php $picky_facade->picky_display_tablenav( 'bottom' ); ?>
</form>
