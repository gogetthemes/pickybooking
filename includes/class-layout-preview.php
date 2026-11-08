<?php
/**
 * Layout preview thumbnails (SVG + media attachment from builder screenshot).
 *
 * @package PickyBooking
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build and store layout list thumbnails.
 */
class Layout_Preview {

	const META_ATTACHMENT = '_picky_layout_preview_id';
	const META_SVG        = '_picky_layout_preview_svg';

	/**
	 * Decode layout position JSON (raw or URL-encoded).
	 *
	 * @param mixed $raw Meta value.
	 * @return array<int, array<string, mixed>>
	 */
	public static function decode_elements( $raw ) {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$decoded = json_decode( rawurldecode( $raw ), true );
		if ( ! is_array( $decoded ) ) {
			$decoded = json_decode( $raw, true );
		}
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return $decoded;
	}

	/**
	 * Build an SVG preview string from elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param int                               $canvas_w Canvas width.
	 * @param int                               $canvas_h Canvas height.
	 * @param string                            $bg       Background fill.
	 * @param bool                              $crop     Crop viewBox to content (for small thumbs).
	 * @return string
	 */
	public static function elements_to_svg( $elements, $canvas_w = 1100, $canvas_h = 900, $bg = '#f3f4f6', $crop = false ) {
		$canvas_w = max( 100, (int) $canvas_w );
		$canvas_h = max( 100, (int) $canvas_h );
		$bg       = self::sanitize_color( $bg, '#f3f4f6' );
		$canvas_area = max( 1.0, (float) $canvas_w * (float) $canvas_h );

		$rects = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$x  = isset( $el['x'] ) ? (float) $el['x'] : 0;
			$y  = isset( $el['y'] ) ? (float) $el['y'] : 0;
			$x1 = isset( $el['x1'] ) ? (float) $el['x1'] : ( $x + 40 );
			$y1 = isset( $el['y1'] ) ? (float) $el['y1'] : ( $y + 40 );
			$w  = max( 1, $x1 - $x );
			$h  = max( 1, $y1 - $y );
			$class = isset( $el['class'] ) ? (string) $el['class'] : '';
			$color = isset( $el['color'] ) ? self::sanitize_color( (string) $el['color'], '' ) : '';
			if ( $color === '' ) {
				$color = ( $class === 'type-wall' ) ? '#9ca3af' : ( ( $class === 'type-text' ) ? 'transparent' : '#c5bedc' );
			}
			$opacity = isset( $el['opacity'] ) ? (float) $el['opacity'] : 1;
			$opacity = max( 0.15, min( 1, $opacity ) );
			$rx      = 4;
			if ( $class === 'type-circle' || ( isset( $el['shape_type'] ) && (string) $el['shape_type'] === 'circle' ) ) {
				$rx = (int) max( $w, $h );
			} elseif ( isset( $el['square_radius'] ) && (int) $el['square_radius'] > 0 ) {
				$rx = min( 40, (int) $el['square_radius'] );
			} elseif ( isset( $el['shape_radius'] ) && (string) $el['shape_radius'] !== '' ) {
				$rx = min( 40, (int) $el['shape_radius'] );
			}
			if ( $class === 'type-text' ) {
				continue;
			}
			$rz = 0.0;
			if ( isset( $el['rotate_z'] ) && (string) $el['rotate_z'] !== '' ) {
				$rz = (float) $el['rotate_z'];
			}
			$unique = isset( $el['unique'] ) ? (string) $el['unique'] : '';
			$is_floor = ( $class === 'type-shape' && $unique !== '' && stripos( $unique, 'floor' ) !== false )
				|| ( ( $w * $h ) >= ( $canvas_area * 0.4 ) );
			$rects[] = array(
				'x'       => $x,
				'y'       => $y,
				'w'       => $w,
				'h'       => $h,
				'fill'    => $color,
				'opacity' => $opacity,
				'rx'      => $rx,
				'rz'      => $rz,
				'z'       => ( $class === 'type-shape' ) ? 0 : 1,
				'floor'   => $is_floor,
			);
		}

		usort(
			$rects,
			static function ( $a, $b ) {
				return ( (int) $a['z'] ) <=> ( (int) $b['z'] );
			}
		);

		/*
		 * Optional crop: tight viewBox around content (skip huge floor washes)
		 * so tall/sparse layouts stay readable in small calendar thumbs.
		 */
		$vb_x = 0.0;
		$vb_y = 0.0;
		$vb_w = (float) $canvas_w;
		$vb_h = (float) $canvas_h;
		if ( $crop ) {
			$min_x = $canvas_w;
			$min_y = $canvas_h;
			$max_x = 0.0;
			$max_y = 0.0;
			$has_crop = false;
			foreach ( $rects as $r ) {
				if ( ! empty( $r['floor'] ) ) {
					continue;
				}
				$has_crop = true;
				$min_x = min( $min_x, (float) $r['x'] );
				$min_y = min( $min_y, (float) $r['y'] );
				$max_x = max( $max_x, (float) $r['x'] + (float) $r['w'] );
				$max_y = max( $max_y, (float) $r['y'] + (float) $r['h'] );
			}
			if ( $has_crop && $max_x > $min_x && $max_y > $min_y ) {
				$pad   = max( 28.0, min( $canvas_w, $canvas_h ) * 0.05 );
				$vb_x  = max( 0.0, $min_x - $pad );
				$vb_y  = max( 0.0, $min_y - $pad );
				$vb_w  = min( (float) $canvas_w - $vb_x, ( $max_x - $min_x ) + ( 2 * $pad ) );
				$vb_h  = min( (float) $canvas_h - $vb_y, ( $max_y - $min_y ) + ( 2 * $pad ) );
				$vb_w  = max( 80.0, $vb_w );
				$vb_h  = max( 80.0, $vb_h );
			}
		}

		$max_w = 720;
		$scale = min( 1, $max_w / max( 1.0, $vb_w ) );
		$out_w = max( 80, (int) round( $vb_w * $scale ) );
		$out_h = max( 80, (int) round( $vb_h * $scale ) );

		$parts   = array();
		$parts[] = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="%s %s %s %s" preserveAspectRatio="xMidYMid meet">',
			$out_w,
			$out_h,
			esc_attr( (string) round( $vb_x, 2 ) ),
			esc_attr( (string) round( $vb_y, 2 ) ),
			esc_attr( (string) round( $vb_w, 2 ) ),
			esc_attr( (string) round( $vb_h, 2 ) )
		);
		$parts[] = sprintf(
			'<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>',
			esc_attr( (string) round( $vb_x, 2 ) ),
			esc_attr( (string) round( $vb_y, 2 ) ),
			esc_attr( (string) round( $vb_w, 2 ) ),
			esc_attr( (string) round( $vb_h, 2 ) ),
			esc_attr( $bg )
		);
		foreach ( $rects as $r ) {
			$transform = '';
			$rz        = isset( $r['rz'] ) ? (float) $r['rz'] : 0.0;
			/* Normalize near-cardinal angles so thumbs match builder snaps. */
			if ( abs( $rz ) > 0.01 ) {
				$cx        = $r['x'] + ( $r['w'] / 2 );
				$cy        = $r['y'] + ( $r['h'] / 2 );
				$transform = sprintf( ' transform="rotate(%s %s %s)"', esc_attr( (string) round( $rz, 2 ) ), esc_attr( (string) round( $cx, 2 ) ), esc_attr( (string) round( $cy, 2 ) ) );
			}
			$parts[] = sprintf(
				'<rect x="%s" y="%s" width="%s" height="%s" rx="%s" fill="%s" opacity="%s"%s/>',
				esc_attr( (string) round( $r['x'], 2 ) ),
				esc_attr( (string) round( $r['y'], 2 ) ),
				esc_attr( (string) round( $r['w'], 2 ) ),
				esc_attr( (string) round( $r['h'], 2 ) ),
				esc_attr( (string) (int) $r['rx'] ),
				esc_attr( (string) $r['fill'] ),
				esc_attr( (string) $r['opacity'] ),
				$transform
			);
		}
		$parts[] = '</svg>';

		return implode( '', $parts );
	}

	/**
	 * Sanitize a CSS color for SVG attributes.
	 *
	 * @param string $color   Raw color.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function sanitize_color( $color, $fallback = '#cccccc' ) {
		$color = trim( (string) $color );
		if ( $color === '' || strtolower( $color ) === 'transparent' ) {
			return $fallback;
		}
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color ) ) {
			return $color;
		}
		if ( preg_match( '/^rgba?\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+(?:\s*,\s*[\d.]+)?\s*\)$/i', $color ) ) {
			return $color;
		}
		return $fallback;
	}

	/**
	 * SVG data URI for a layout post.
	 *
	 * @param int  $layout_id Layout ID.
	 * @param bool $fresh     When true, rebuild from elements (skip cached meta).
	 * @param bool $crop      When true (and rebuilding), crop to content for small thumbs.
	 * @return string
	 */
	public static function svg_data_uri_for_layout( $layout_id, $fresh = false, $crop = false ) {
		$layout_id = absint( $layout_id );
		if ( ! $fresh && ! $crop ) {
			$cached = get_post_meta( $layout_id, self::META_SVG, true );
			if ( is_string( $cached ) && strpos( $cached, '<svg' ) === 0 ) {
				return 'data:image/svg+xml;charset=utf-8,' . rawurlencode( $cached );
			}
		}

		$elements = self::decode_elements( get_post_meta( $layout_id, '_picky_layout_position', true ) );
		$cw       = (int) get_post_meta( $layout_id, '_picky_layout_canvas_width', true );
		$ch       = (int) get_post_meta( $layout_id, '_picky_layout_canvas_height', true );
		$bg       = '#f3f4f6';
		if ( class_exists( __NAMESPACE__ . '\\Layout_Library' ) ) {
			$colors = Layout_Library::preview_colors_for_layout( $layout_id );
			$bg     = isset( $colors['bg'] ) ? (string) $colors['bg'] : $bg;
		}
		$svg = self::elements_to_svg( $elements, $cw > 0 ? $cw : 1100, $ch > 0 ? $ch : 900, $bg, $crop );
		return 'data:image/svg+xml;charset=utf-8,' . rawurlencode( $svg );
	}

	/**
	 * Preview URL for a saved layout (attachment preferred, SVG fallback).
	 *
	 * @param int $layout_id Layout ID.
	 * @return string
	 */
	public static function url_for_layout( $layout_id ) {
		$layout_id = absint( $layout_id );
		$aid       = (int) get_post_meta( $layout_id, self::META_ATTACHMENT, true );
		if ( $aid > 0 ) {
			$url = wp_get_attachment_image_url( $aid, 'medium_large' );
			if ( ! $url ) {
				$url = wp_get_attachment_url( $aid );
			}
			if ( is_string( $url ) && $url !== '' ) {
				// Bust browser/CDN cache after re-save.
				$ver = (string) get_post_modified_time( 'U', true, $aid );
				if ( $ver === '' || $ver === '0' ) {
					$ver = (string) time();
				}
				return add_query_arg( 'ver', $ver, $url );
			}
		}
		return self::svg_data_uri_for_layout( $layout_id );
	}

	/**
	 * Preview URL for a bundled JSON preset.
	 *
	 * @param string $file Absolute JSON path.
	 * @param string $fallback_bg Background.
	 * @return string
	 */
	public static function url_for_json_file( $file, $fallback_bg = '#efeaf8' ) {
		if ( ! is_readable( $file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $file );
		if ( ! is_string( $json ) || $json === '' ) {
			return '';
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		$elements = array();
		if ( isset( $decoded['elements'] ) && is_array( $decoded['elements'] ) ) {
			$elements = $decoded['elements'];
		}
		$cw  = isset( $decoded['canvas_width'] ) ? (int) $decoded['canvas_width'] : 1100;
		$ch  = isset( $decoded['canvas_height'] ) ? (int) $decoded['canvas_height'] : 900;
		$bg  = $fallback_bg;
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$class = isset( $el['class'] ) ? (string) $el['class'] : '';
			$color = isset( $el['color'] ) ? (string) $el['color'] : '';
			if ( $class === 'type-shape' && isset( $el['unique'] ) && strpos( (string) $el['unique'], 'floor' ) !== false && $color !== '' ) {
				$bg = $color;
				break;
			}
		}
		$svg = self::elements_to_svg( $elements, $cw, $ch, $bg );
		return 'data:image/svg+xml;charset=utf-8,' . rawurlencode( $svg );
	}

	/**
	 * Persist SVG snapshot meta from current layout position.
	 *
	 * @param int $layout_id Layout ID.
	 * @return string SVG markup (empty on failure).
	 */
	public static function refresh_svg_meta( $layout_id ) {
		$layout_id = absint( $layout_id );
		if ( ! $layout_id ) {
			return '';
		}
		$elements = self::decode_elements( get_post_meta( $layout_id, '_picky_layout_position', true ) );
		$cw       = (int) get_post_meta( $layout_id, '_picky_layout_canvas_width', true );
		$ch       = (int) get_post_meta( $layout_id, '_picky_layout_canvas_height', true );
		$bg       = '#f3f4f6';
		if ( class_exists( __NAMESPACE__ . '\\Layout_Library' ) ) {
			$colors = Layout_Library::preview_colors_for_layout( $layout_id );
			$bg     = isset( $colors['bg'] ) ? (string) $colors['bg'] : $bg;
		}
		$svg = self::elements_to_svg( $elements, $cw > 0 ? $cw : 1100, $ch > 0 ? $ch : 900, $bg );
		update_post_meta( $layout_id, self::META_SVG, $svg );
		return $svg;
	}

	/**
	 * Write/replace the list thumbnail attachment from current SVG meta.
	 * Guarantees the gallery preview updates on every save even without a client screenshot.
	 *
	 * @param int $layout_id Layout ID.
	 * @return int Attachment ID or 0.
	 */
	public static function publish_svg_attachment( $layout_id ) {
		$layout_id = absint( $layout_id );
		if ( ! $layout_id ) {
			return 0;
		}
		$svg = get_post_meta( $layout_id, self::META_SVG, true );
		if ( ! is_string( $svg ) || strpos( $svg, '<svg' ) !== 0 ) {
			$svg = self::refresh_svg_meta( $layout_id );
		}
		if ( ! is_string( $svg ) || $svg === '' ) {
			return 0;
		}
		return self::store_binary_attachment( $layout_id, $svg, 'image/svg+xml', 'svg' );
	}

	/**
	 * Remove the stored preview attachment (forces SVG / data-URI fallback).
	 *
	 * @param int $layout_id Layout ID.
	 * @return void
	 */
	public static function clear_attachment( $layout_id ) {
		$layout_id = absint( $layout_id );
		if ( ! $layout_id ) {
			return;
		}
		$old = (int) get_post_meta( $layout_id, self::META_ATTACHMENT, true );
		delete_post_meta( $layout_id, self::META_ATTACHMENT );
		if ( $old > 0 ) {
			wp_delete_attachment( $old, true );
		}
	}

	/**
	 * Mark that a fresh raster screenshot was stored (so save_layout won't overwrite with SVG).
	 *
	 * @param int $layout_id Layout ID.
	 * @return void
	 */
	public static function mark_raster_fresh( $layout_id ) {
		$layout_id = absint( $layout_id );
		if ( $layout_id ) {
			set_transient( 'picky_layout_preview_fresh_' . $layout_id, '1', 120 );
		}
	}

	/**
	 * Whether a raster screenshot was just uploaded for this layout.
	 *
	 * @param int $layout_id Layout ID.
	 * @return bool
	 */
	public static function consume_raster_fresh( $layout_id ) {
		$layout_id = absint( $layout_id );
		$key       = 'picky_layout_preview_fresh_' . $layout_id;
		$fresh     = (string) get_transient( $key ) === '1';
		delete_transient( $key );
		return $fresh;
	}

	/**
	 * Store raw image bytes as the layout preview attachment (replaces previous).
	 *
	 * @param int    $layout_id Layout ID.
	 * @param string $bin       File contents.
	 * @param string $mime      Mime type.
	 * @param string $ext       File extension.
	 * @return int Attachment ID or 0.
	 */
	public static function store_binary_attachment( $layout_id, $bin, $mime, $ext ) {
		$layout_id = absint( $layout_id );
		$bin       = is_string( $bin ) ? $bin : '';
		$ext       = sanitize_key( $ext );
		$mime      = is_string( $mime ) ? $mime : '';
		if ( ! $layout_id || $bin === '' || $ext === '' ) {
			return 0;
		}
		if ( strlen( $bin ) > 4 * 1024 * 1024 ) {
			return 0;
		}

		$filename = 'picky-layout-preview-' . $layout_id . '-' . time() . '.' . $ext;
		$upload   = wp_upload_bits( $filename, null, $bin );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}

		$filetype   = wp_check_filetype( (string) $upload['file'], null );
		$attachment = array(
			'post_mime_type' => ( ! empty( $filetype['type'] ) ) ? (string) $filetype['type'] : $mime,
			'post_title'     => sprintf( 'Layout %d preview', $layout_id ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $layout_id,
		);
		$attach_id = wp_insert_attachment( $attachment, (string) $upload['file'], $layout_id );
		if ( ! $attach_id || is_wp_error( $attach_id ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( (int) $attach_id, (string) $upload['file'] );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( (int) $attach_id, $meta );
		}

		$old = (int) get_post_meta( $layout_id, self::META_ATTACHMENT, true );
		update_post_meta( $layout_id, self::META_ATTACHMENT, (int) $attach_id );
		if ( $old > 0 && $old !== (int) $attach_id ) {
			wp_delete_attachment( $old, true );
		}

		return (int) $attach_id;
	}

	/**
	 * Save a data-URI screenshot as an attachment and attach to the layout.
	 *
	 * @param int    $layout_id Layout ID.
	 * @param string $data_uri  image/*;base64,… payload.
	 * @return int Attachment ID or 0.
	 */
	public static function save_screenshot_data_uri( $layout_id, $data_uri ) {
		$layout_id = absint( $layout_id );
		$data_uri  = is_string( $data_uri ) ? trim( $data_uri ) : '';
		if ( ! $layout_id || $data_uri === '' ) {
			return 0;
		}
		if ( ! preg_match( '#^data:image/(jpeg|jpg|png|webp);base64,(.+)$#is', $data_uri, $m ) ) {
			return 0;
		}
		$ext = strtolower( (string) $m[1] );
		if ( $ext === 'jpeg' ) {
			$ext = 'jpg';
		}
		$mime = 'image/' . ( $ext === 'jpg' ? 'jpeg' : $ext );
		$bin  = base64_decode( (string) $m[2], true );
		if ( false === $bin || $bin === '' ) {
			return 0;
		}
		$aid = self::store_binary_attachment( $layout_id, $bin, $mime, $ext );
		if ( $aid > 0 ) {
			self::mark_raster_fresh( $layout_id );
		}
		return $aid;
	}

	/**
	 * Save an uploaded preview file ($_FILES style).
	 *
	 * @param int   $layout_id Layout ID.
	 * @param array $file      Single $_FILES entry.
	 * @return int Attachment ID or 0.
	 */
	public static function save_screenshot_upload( $layout_id, $file ) {
		$layout_id = absint( $layout_id );
		if ( ! $layout_id || ! is_array( $file ) ) {
			return 0;
		}
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( $error !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return 0;
		}
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 || $size > 4 * 1024 * 1024 ) {
			return 0;
		}

		$ft   = wp_check_filetype_and_ext( (string) $file['tmp_name'], isset( $file['name'] ) ? (string) $file['name'] : 'preview.jpg' );
		$mime = ( ! empty( $ft['type'] ) ) ? (string) $ft['type'] : ( isset( $file['type'] ) ? (string) $file['type'] : '' );
		$ext  = ( ! empty( $ft['ext'] ) ) ? (string) $ft['ext'] : 'jpg';
		$allowed_mimes = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' );
		if ( ! in_array( $mime, $allowed_mimes, true ) && ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
			return 0;
		}
		if ( $ext === 'jpeg' ) {
			$ext = 'jpg';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bin = file_get_contents( (string) $file['tmp_name'] );
		if ( ! is_string( $bin ) || $bin === '' ) {
			return 0;
		}
		$aid = self::store_binary_attachment( $layout_id, $bin, $mime !== '' ? $mime : 'image/jpeg', $ext );
		if ( $aid > 0 ) {
			self::mark_raster_fresh( $layout_id );
		}
		return $aid;
	}
}
