<?php
/**
 * Booking popup icon catalog (SVG sprite keys for layout items + services).
 *
 * @package Picky
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog + helpers for booking confirmation popup icons.
 */
class Booking_Icons {

	const SPRITE_REL = 'assets/icons/picky-booking-icons.svg';
	const META_KEY   = '_picky_layout_booking_item_icon';

	/**
	 * Absolute URL to the SVG sprite.
	 *
	 * @return string
	 */
	public static function sprite_url() {
		return plugins_url( self::SPRITE_REL, dirname( __DIR__ ) . '/' . Info::MAIN_FILE );
	}

	/**
	 * Absolute filesystem path to the SVG sprite.
	 *
	 * @return string
	 */
	public static function sprite_path() {
		return trailingslashit( Info::get_plugin_path() ) . self::SPRITE_REL;
	}

	/**
	 * Print the SVG sprite once per request (hidden). Prefer fragment hrefs in markup.
	 *
	 * @return void
	 */
	public static function print_sprite_once() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$path = self::sprite_path();
		if ( ! is_readable( $path ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset
		$svg = file_get_contents( $path );
		if ( ! is_string( $svg ) || $svg === '' ) {
			return;
		}
		// Keep symbols; force hidden host so it never affects layout.
		$svg = preg_replace( '/^<\?xml[^>]*>\s*/i', '', $svg );
		$svg = preg_replace( '/\sstyle="[^"]*"/i', '', $svg, 1 );
		echo '<div class="picky-booking-icons-sprite" hidden aria-hidden="true">';
		echo wp_kses( $svg, self::kses_allowed() );
		echo '</div>';
	}

	/**
	 * Render icon picker markup (layout or service editor).
	 *
	 * @param string $input_name  Name attribute for the hidden input.
	 * @param string $input_id    Id attribute for the hidden input.
	 * @param string $selected    Currently selected key (or empty).
	 * @param string $input_class Extra classes on the hidden input.
	 * @return string
	 */
	public static function render_picker( $input_name, $input_id, $selected = '', $input_class = '' ) {
		$selected = self::sanitize( $selected );
		$groups   = self::grouped();
		$labels   = self::group_labels();
		ob_start();
		?>
		<div class="picky-bicon-picker" data-picky-bicon-picker>
			<input type="hidden"
				name="<?php echo esc_attr( $input_name ); ?>"
				id="<?php echo esc_attr( $input_id ); ?>"
				class="picky-bicon-picker-value <?php echo esc_attr( $input_class ); ?>"
				value="<?php echo esc_attr( $selected ); ?>" />
			<div class="picky-bicon-picker-toolbar">
				<button type="button" class="button picky-bicon-picker-none<?php echo $selected === '' ? ' is-active' : ''; ?>" data-picky-bicon="">
					<?php esc_html_e( 'None', 'pickybooking' ); ?>
				</button>
				<button type="button" class="button picky-bicon-picker-toggle" aria-expanded="false">
					<?php esc_html_e( 'Choose icon', 'pickybooking' ); ?>
				</button>
				<span class="picky-bicon-picker-preview" aria-hidden="true">
					<?php
					if ( $selected !== '' ) {
						echo wp_kses( self::svg_markup( $selected, 'picky-bicon picky-bicon--preview' ), self::kses_allowed() );
					}
					?>
				</span>
			</div>
			<div class="picky-bicon-picker-grid" hidden>
				<?php foreach ( $groups as $gid => $icons ) : ?>
					<?php if ( empty( $icons ) ) { continue; } ?>
					<div class="picky-bicon-picker-group">
						<div class="picky-bicon-picker-group-label"><?php echo esc_html( isset( $labels[ $gid ] ) ? $labels[ $gid ] : $gid ); ?></div>
						<div class="picky-bicon-picker-group-icons">
							<?php foreach ( $icons as $icon ) : ?>
								<button type="button"
									class="picky-bicon-picker-btn<?php echo ( $selected === $icon['key'] ) ? ' is-selected' : ''; ?>"
									data-picky-bicon="<?php echo esc_attr( $icon['key'] ); ?>"
									title="<?php echo esc_attr( $icon['label'] ); ?>"
									aria-label="<?php echo esc_attr( $icon['label'] ); ?>">
									<?php echo wp_kses( self::svg_markup( $icon['key'] ), self::kses_allowed() ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Icon definitions: key => [ label, group ].
	 *
	 * Groups align with AI layout niches plus a shared "general" bucket.
	 *
	 * @return array<string, array{label:string,group:string}>
	 */
	public static function catalog() {
		return array(
			'cinema-seat'   => array( 'label' => __( 'Cinema seat', 'pickybooking' ), 'group' => 'cinema' ),
			'ticket'        => array( 'label' => __( 'Ticket', 'pickybooking' ), 'group' => 'cinema' ),
			'popcorn'       => array( 'label' => __( 'Popcorn', 'pickybooking' ), 'group' => 'cinema' ),
			'beauty-chair'  => array( 'label' => __( 'Beauty chair', 'pickybooking' ), 'group' => 'beauty' ),
			'scissors'      => array( 'label' => __( 'Scissors', 'pickybooking' ), 'group' => 'beauty' ),
			'nail'          => array( 'label' => __( 'Nail', 'pickybooking' ), 'group' => 'beauty' ),
			'spa'           => array( 'label' => __( 'Spa', 'pickybooking' ), 'group' => 'massage' ),
			'massage'       => array( 'label' => __( 'Massage', 'pickybooking' ), 'group' => 'massage' ),
			'car'           => array( 'label' => __( 'Car', 'pickybooking' ), 'group' => 'parking' ),
			'parking'       => array( 'label' => __( 'Parking', 'pickybooking' ), 'group' => 'parking' ),
			'bolt'          => array( 'label' => __( 'Charging', 'pickybooking' ), 'group' => 'parking' ),
			'umbrella'      => array( 'label' => __( 'Umbrella', 'pickybooking' ), 'group' => 'beach' ),
			'sunbed'        => array( 'label' => __( 'Sunbed', 'pickybooking' ), 'group' => 'beach' ),
			'cocktail'      => array( 'label' => __( 'Cocktail', 'pickybooking' ), 'group' => 'bar' ),
			'wine'          => array( 'label' => __( 'Wine', 'pickybooking' ), 'group' => 'bar' ),
			'coffee'        => array( 'label' => __( 'Coffee', 'pickybooking' ), 'group' => 'bar' ),
			'desk'          => array( 'label' => __( 'Desk', 'pickybooking' ), 'group' => 'office' ),
			'laptop'        => array( 'label' => __( 'Laptop', 'pickybooking' ), 'group' => 'coworking' ),
			'meeting'       => array( 'label' => __( 'Meeting table', 'pickybooking' ), 'group' => 'meeting' ),
			'plate'         => array( 'label' => __( 'Plate', 'pickybooking' ), 'group' => 'restaurant' ),
			'utensils'      => array( 'label' => __( 'Utensils', 'pickybooking' ), 'group' => 'restaurant' ),
			'dumbbell'      => array( 'label' => __( 'Dumbbell', 'pickybooking' ), 'group' => 'gym' ),
			'court'         => array( 'label' => __( 'Court', 'pickybooking' ), 'group' => 'gym' ),
			'bed'           => array( 'label' => __( 'Bed', 'pickybooking' ), 'group' => 'clinic' ),
			'stethoscope'   => array( 'label' => __( 'Clinic', 'pickybooking' ), 'group' => 'clinic' ),
			'vip'           => array( 'label' => __( 'VIP', 'pickybooking' ), 'group' => 'vip' ),
			'star'               => array( 'label' => __( 'Star', 'pickybooking' ), 'group' => 'general' ),
			'calendar'           => array( 'label' => __( 'Calendar', 'pickybooking' ), 'group' => 'general' ),
			'clock'              => array( 'label' => __( 'Clock', 'pickybooking' ), 'group' => 'general' ),
			'user'               => array( 'label' => __( 'Person', 'pickybooking' ), 'group' => 'general' ),
			'gift'               => array( 'label' => __( 'Gift', 'pickybooking' ), 'group' => 'general' ),
			'arrow-up'           => array( 'label' => __( 'Arrow up', 'pickybooking' ), 'group' => 'directions' ),
			'arrow-down'         => array( 'label' => __( 'Arrow down', 'pickybooking' ), 'group' => 'directions' ),
			'arrow-left'         => array( 'label' => __( 'Arrow left', 'pickybooking' ), 'group' => 'directions' ),
			'arrow-right'        => array( 'label' => __( 'Arrow right', 'pickybooking' ), 'group' => 'directions' ),
			'arrow-up-left'      => array( 'label' => __( 'Arrow up-left', 'pickybooking' ), 'group' => 'directions' ),
			'arrow-up-right'     => array( 'label' => __( 'Arrow up-right', 'pickybooking' ), 'group' => 'directions' ),
			'arrow-down-left'    => array( 'label' => __( 'Arrow down-left', 'pickybooking' ), 'group' => 'directions' ),
			'arrow-down-right'   => array( 'label' => __( 'Arrow down-right', 'pickybooking' ), 'group' => 'directions' ),
			'arrows-left-right'  => array( 'label' => __( 'Two-way horizontal', 'pickybooking' ), 'group' => 'directions' ),
			'arrows-up-down'     => array( 'label' => __( 'Two-way vertical', 'pickybooking' ), 'group' => 'directions' ),
		);
	}

	/**
	 * Human-readable group labels for the picker UI.
	 *
	 * @return array<string, string>
	 */
	public static function group_labels() {
		return array(
			'cinema'     => __( 'Cinema', 'pickybooking' ),
			'beauty'     => __( 'Beauty', 'pickybooking' ),
			'massage'    => __( 'Spa & massage', 'pickybooking' ),
			'parking'    => __( 'Parking', 'pickybooking' ),
			'beach'      => __( 'Beach', 'pickybooking' ),
			'bar'        => __( 'Bar', 'pickybooking' ),
			'office'     => __( 'Office', 'pickybooking' ),
			'coworking'  => __( 'Coworking', 'pickybooking' ),
			'meeting'    => __( 'Meeting', 'pickybooking' ),
			'restaurant' => __( 'Restaurant', 'pickybooking' ),
			'gym'        => __( 'Gym & sports', 'pickybooking' ),
			'clinic'     => __( 'Clinic', 'pickybooking' ),
			'vip'         => __( 'VIP', 'pickybooking' ),
			'general'     => __( 'General', 'pickybooking' ),
			'directions'  => __( 'Directions', 'pickybooking' ),
		);
	}

	/**
	 * Icons grouped for picker rendering.
	 *
	 * @return array<string, array<int, array{key:string,label:string}>>
	 */
	public static function grouped() {
		$out = array();
		foreach ( self::group_labels() as $gid => $_label ) {
			$out[ $gid ] = array();
		}
		foreach ( self::catalog() as $key => $meta ) {
			$g = isset( $meta['group'] ) ? (string) $meta['group'] : 'general';
			if ( ! isset( $out[ $g ] ) ) {
				$out[ $g ] = array();
			}
			$out[ $g ][] = array(
				'key'   => $key,
				'label' => $meta['label'],
			);
		}
		return array_filter( $out );
	}

	/**
	 * Whether a key exists in the catalog.
	 *
	 * @param string $key Icon key.
	 * @return bool
	 */
	public static function is_valid( $key ) {
		$key = sanitize_key( (string) $key );
		if ( $key === '' ) {
			return false;
		}
		$catalog = self::catalog();
		return isset( $catalog[ $key ] );
	}

	/**
	 * Sanitize a stored icon key (empty string if invalid / none).
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function sanitize( $raw ) {
		$key = sanitize_key( (string) $raw );
		return self::is_valid( $key ) ? $key : '';
	}

	/**
	 * Symbol id inside the sprite for a catalog key.
	 *
	 * @param string $key Icon key.
	 * @return string
	 */
	public static function symbol_id( $key ) {
		$key = self::sanitize( $key );
		return $key !== '' ? 'picky-bicon-' . $key : '';
	}

	/**
	 * Tags allowed when icon markup is printed.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function kses_allowed() {
		$svg_attrs = array(
			'class'            => true,
			'aria-hidden'      => true,
			'focusable'        => true,
			'xmlns'            => true,
			'xmlns:xlink'      => true,
			'viewbox'          => true,
			'fill'             => true,
			'stroke'           => true,
			'stroke-width'     => true,
			'stroke-linecap'   => true,
			'stroke-linejoin'  => true,
			'id'               => true,
			'hidden'           => true,
		);
		return array(
			'div'    => array(
				'class'       => true,
				'hidden'      => true,
				'aria-hidden' => true,
				'data-*'      => true,
			),
			'input'  => array(
				'type'  => true,
				'name'  => true,
				'id'    => true,
				'class' => true,
				'value' => true,
			),
			'button' => array(
				'type'          => true,
				'class'         => true,
				'data-*'        => true,
				'aria-expanded' => true,
				'aria-label'    => true,
				'title'         => true,
			),
			'span'   => array(
				'class'       => true,
				'aria-hidden' => true,
			),
			'svg'    => $svg_attrs,
			'symbol' => $svg_attrs,
			'g'      => $svg_attrs,
			'path'   => array(
				'd'               => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
			'circle' => array(
				'cx'              => true,
				'cy'              => true,
				'r'               => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
			),
			'rect'   => array(
				'x'               => true,
				'y'               => true,
				'width'           => true,
				'height'          => true,
				'rx'              => true,
				'ry'              => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
			),
			'line'   => array(
				'x1'     => true,
				'y1'     => true,
				'x2'     => true,
				'y2'     => true,
				'stroke' => true,
			),
			'use'    => array(
				'href'       => true,
				'xlink:href' => true,
			),
		);
	}

	/**
	 * Markup for an inline SVG that references the sprite.
	 *
	 * @param string $key   Icon key.
	 * @param string $class Extra CSS classes.
	 * @return string HTML or empty.
	 */
	public static function svg_markup( $key, $class = 'picky-bicon' ) {
		$key = self::sanitize( $key );
		if ( $key === '' ) {
			return '';
		}
		// Fragment-only: sprite is inlined once via print_sprite_once().
		$href  = '#' . self::symbol_id( $key );
		$class = trim( (string) $class );
		return '<svg class="' . esc_attr( $class ) . '" aria-hidden="true" focusable="false"><use href="' . esc_attr( $href ) . '" xlink:href="' . esc_attr( $href ) . '"></use></svg>';
	}

	/**
	 * Data payload for admin/frontend JS (catalog + sprite URL).
	 *
	 * @return array{sprite:string,groups:array,icons:array}
	 */
	public static function js_payload() {
		$icons = array();
		foreach ( self::catalog() as $key => $meta ) {
			$icons[ $key ] = array(
				'label' => $meta['label'],
				'group' => $meta['group'],
				'symbol'=> self::symbol_id( $key ),
			);
		}
		$groups = array();
		foreach ( self::group_labels() as $gid => $label ) {
			$groups[] = array(
				'id'    => $gid,
				'label' => $label,
			);
		}
		return array(
			'sprite' => self::sprite_url(),
			'groups' => $groups,
			'icons'  => $icons,
		);
	}
}
