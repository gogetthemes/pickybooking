<?php
/**
 * Layouts gallery — user layouts + presets (Layouts tab).
 *
 * Expects $picky_user_layouts and $picky_preset_layouts (arrays) when included from Admin.
 *
 * @package PickyBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $picky_user_layouts ) || ! is_array( $picky_user_layouts ) ) {
	$picky_user_layouts = array();
}
if ( ! isset( $picky_preset_layouts ) || ! is_array( $picky_preset_layouts ) ) {
	$picky_preset_layouts = array();
}

$picky_library_import_url = admin_url( 'admin-post.php' );
$picky_layouts_can_add    = current_user_can( 'manage_options' );
$picky_layouts_add_url    = wp_nonce_url( admin_url( 'post-new.php?post_type=picky_layout' ), 'picky_new_layout' );
$user_count             = count( $picky_user_layouts );
$preset_count           = count( $picky_preset_layouts );

/**
 * Render one layout card.
 *
 * @param array<string, mixed> $item Card data.
 * @return void
 */
$picky_render_layout_card = static function ( $item ) use ( $picky_library_import_url ) {
	$item_id     = isset( $item['id'] ) ? (string) $item['id'] : '';
	$title       = isset( $item['title'] ) ? (string) $item['title'] : '';
	$desc        = isset( $item['description'] ) ? (string) $item['description'] : '';
	$preview_url = isset( $item['preview_url'] ) ? (string) $item['preview_url'] : '';
	$bg          = isset( $item['preview_bg'] ) ? (string) $item['preview_bg'] : '#efeaf8';
	$fill        = isset( $item['preview_item'] ) ? (string) $item['preview_item'] : '#c5bedc';
	$pid         = isset( $item['preview_id'] ) ? (string) $item['preview_id'] : 'A1';
	$source      = isset( $item['source'] ) ? (string) $item['source'] : '';
	$edit_url    = isset( $item['edit_url'] ) ? (string) $item['edit_url'] : '';
	$layout_id   = isset( $item['layout_id'] ) ? absint( $item['layout_id'] ) : 0;
	$delete_url    = isset( $item['delete_url'] ) ? (string) $item['delete_url'] : '';
	$duplicate_url = isset( $item['duplicate_url'] ) ? (string) $item['duplicate_url'] : '';
	$is_user       = ( $source === 'layout' );
	$badge         = $is_user ? __( 'Your layout', 'pickybooking' ) : __( 'Preset', 'pickybooking' );
	$can_delete    = $is_user && $layout_id > 0 && $delete_url !== '' && current_user_can( 'delete_post', $layout_id );
	$can_duplicate = $is_user && $layout_id > 0 && $duplicate_url !== '' && current_user_can( 'edit_post', $layout_id ) && current_user_can( 'publish_posts' );
	?>
	<article class="picky-layout-library__card<?php echo $is_user ? ' picky-layout-library__card--user' : ' picky-layout-library__card--preset'; ?>">
		<?php if ( $is_user && $edit_url !== '' ) : ?>
			<a class="picky-layout-library__hit" href="<?php echo esc_url( $edit_url ); ?>" aria-label="<?php
				/* translators: %s: layout title */
				echo esc_attr( sprintf( __( 'Open %s', 'pickybooking' ), $title ) );
			?>">
		<?php endif; ?>
			<div class="picky-layout-library__preview" style="background:<?php echo esc_attr( $bg ); ?>;">
				<?php if ( $preview_url !== '' ) : ?>
					<img class="picky-layout-library__thumb" src="<?php echo ( strpos( $preview_url, 'data:' ) === 0 ) ? esc_attr( $preview_url ) : esc_url( $preview_url ); ?>" alt="" loading="lazy" decoding="async" />
				<?php else : ?>
					<span class="picky-layout-library__preview-item" style="background:<?php echo esc_attr( $fill ); ?>;"><?php echo esc_html( strtolower( $pid ) ); ?></span>
				<?php endif; ?>
				<span class="picky-layout-library__badge"><?php echo esc_html( $badge ); ?></span>
			</div>
			<div class="picky-layout-library__body">
				<h2 class="picky-layout-library__title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( $desc !== '' ) : ?>
					<p class="picky-layout-library__desc"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
			</div>
		<?php if ( $is_user && $edit_url !== '' ) : ?>
			</a>
		<?php endif; ?>
		<?php if ( $is_user && $edit_url !== '' ) : ?>
			<div class="picky-layout-library__body picky-layout-library__body--actions">
				<div class="picky-layout-library__actions">
					<a class="button button-secondary picky-layout-library__open" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Open in builder', 'pickybooking' ); ?></a>
					<?php if ( $can_duplicate ) : ?>
						<a class="button button-secondary picky-layout-library__duplicate" href="<?php echo esc_url( $duplicate_url ); ?>">
							<?php esc_html_e( 'Duplicate', 'pickybooking' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $can_delete ) : ?>
						<a class="button picky-layout-library__delete picky-layout-library__delete--icon submitdelete" href="<?php echo esc_url( $delete_url ); ?>" title="<?php esc_attr_e( 'Delete', 'pickybooking' ); ?>" aria-label="<?php esc_attr_e( 'Delete this layout permanently', 'pickybooking' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this layout permanently? This cannot be undone.', 'pickybooking' ) ); ?>');">
							<i class="icon-trash" aria-hidden="true"></i>
						</a>
					<?php endif; ?>
				</div>
			</div>
		<?php elseif ( ! $is_user ) : ?>
			<div class="picky-layout-library__body picky-layout-library__body--actions">
				<form method="post" action="<?php echo esc_url( $picky_library_import_url ); ?>" class="picky-layout-library__form">
					<input type="hidden" name="action" value="picky_library_import">
					<input type="hidden" name="picky_library_item" value="<?php echo esc_attr( $item_id ); ?>">
					<?php wp_nonce_field( 'picky_library_import', 'picky_library_import_nonce' ); ?>
					<button type="submit" class="button button-primary picky-layout-library__import">
						<?php esc_html_e( 'Use this layout', 'pickybooking' ); ?>
					</button>
				</form>
			</div>
		<?php endif; ?>
	</article>
	<?php
};
?>
<div class="picky-root picky-layout-library" data-picky-context="layouts-gallery">
	<?php
	$picky_layout_notice = ( isset( $this ) && is_object( $this ) && method_exists( $this, 'picky_layout_notice' ) ) ? $this->picky_layout_notice() : '';
	if ( $picky_layout_notice === 'deleted' ) :
		?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Layout deleted.', 'pickybooking' ); ?></p></div>
	<?php endif; ?>
	<div class="picky-cal-head">
		<div class="picky-cal-head__main">
			<div class="picky-cal-head__copy">
				<div class="picky-cal-head__title-row">
					<h1 class="picky-app-tab-title"><?php esc_html_e( 'Layouts', 'pickybooking' ); ?></h1>
				</div>
				<p class="picky-cal-subtitle picky-app-tab-subtitle">
					<?php esc_html_e( 'Open a saved floor plan to edit it, or start from a preset below.', 'pickybooking' ); ?>
				</p>
			</div>
			<div class="picky-cal-stats picky-layouts-list-stats" role="group" aria-label="<?php echo esc_attr__( 'Layout stats', 'pickybooking' ); ?>">
				<div class="picky-cal-stat">
					<span class="picky-cal-stat__icon" aria-hidden="true"><i class="icon-layers"></i></span>
					<span class="picky-cal-stat__body">
						<strong class="picky-cal-stat__value"><?php echo esc_html( number_format_i18n( $user_count ) ); ?></strong>
						<span class="picky-cal-stat__label"><?php echo esc_html__( 'Yours', 'pickybooking' ); ?></span>
					</span>
				</div>
				<div class="picky-cal-stat">
					<span class="picky-cal-stat__icon" aria-hidden="true"><i class="icon-layout"></i></span>
					<span class="picky-cal-stat__body">
						<strong class="picky-cal-stat__value"><?php echo esc_html( number_format_i18n( $preset_count ) ); ?></strong>
						<span class="picky-cal-stat__label"><?php echo esc_html__( 'Presets', 'pickybooking' ); ?></span>
					</span>
				</div>
			</div>
		</div>
	</div>

	<section class="picky-layout-library__section" aria-labelledby="picky-layout-yours-heading">
		<div class="picky-layout-library__section-head">
			<h2 id="picky-layout-yours-heading" class="picky-layout-library__section-title"><?php esc_html_e( 'Your layouts', 'pickybooking' ); ?></h2>
			<?php if ( $picky_layouts_can_add ) : ?>
				<a class="button button-primary picky-layouts-list__add" href="<?php echo esc_url( $picky_layouts_add_url ); ?>"><?php echo esc_html__( 'Add new', 'pickybooking' ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( empty( $picky_user_layouts ) ) : ?>
			<p class="description"><?php esc_html_e( 'No saved layouts yet. Add a new layout or import a preset.', 'pickybooking' ); ?></p>
		<?php else : ?>
			<div class="picky-layout-library__grid">
				<?php foreach ( $picky_user_layouts as $item ) : ?>
					<?php $picky_render_layout_card( $item ); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<section id="picky-layout-presets" class="picky-layout-library__section" aria-labelledby="picky-layout-presets-heading">
		<h2 id="picky-layout-presets-heading" class="picky-layout-library__section-title"><?php esc_html_e( 'Presets', 'pickybooking' ); ?></h2>
		<p class="description picky-layout-library__section-lead"><?php esc_html_e( 'Start from a ready-made map. Import creates your own editable copy.', 'pickybooking' ); ?></p>
		<?php if ( empty( $picky_preset_layouts ) ) : ?>
			<p class="description"><?php esc_html_e( 'No presets available.', 'pickybooking' ); ?></p>
		<?php else : ?>
			<div class="picky-layout-library__grid">
				<?php foreach ( $picky_preset_layouts as $item ) : ?>
					<?php $picky_render_layout_card( $item ); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
</div>
