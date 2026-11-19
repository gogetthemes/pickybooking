<?php
/**
 * AI integration for layout drafts and copy generation.
 *
 * Supports Groq (free tier, OpenAI-compatible) and OpenAI (paid).
 * Uses the site owner's API key from plugin settings (not a shared SaaS key).
 *
 * @package Picky
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Completions helper (Groq / OpenAI).
 */
class AI_Integration {

	private const API_URL_OPENAI = 'https://api.openai.com/v1/chat/completions';
	private const API_URL_GROQ   = 'https://api.groq.com/openai/v1/chat/completions';

	/** Max AI requests per user per window. */
	private const RATE_MAX = 20;

	/** Rate window in seconds. */
	private const RATE_WINDOW = 300;

	/**
	 * @return array<string, string> provider_id => label
	 */
	public static function providers() {
		return array(
			'groq'   => __( 'Groq (free tier)', 'pickybooking' ),
			'openai' => __( 'OpenAI (paid)', 'pickybooking' ),
		);
	}

	/**
	 * @param string $provider Provider id.
	 * @return array<string, string> model_id => label
	 */
	public static function models_for_provider( $provider ) {
		$provider = self::normalize_provider( $provider );
		if ( $provider === 'groq' ) {
			return array(
				'meta-llama/llama-4-scout-17b-16e-instruct' => 'Llama 4 Scout (free, fast)',
				'openai/gpt-oss-20b'                        => 'GPT OSS 20B (free)',
				'openai/gpt-oss-120b'                       => 'GPT OSS 120B (free, higher quality)',
			);
		}
		return array(
			'gpt-4o-mini'  => 'gpt-4o-mini',
			'gpt-4o'       => 'gpt-4o',
			'gpt-4.1-mini' => 'gpt-4.1-mini',
			'gpt-4.1'      => 'gpt-4.1',
		);
	}

	/**
	 * Whether the model spends completion budget on internal reasoning first.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public static function is_reasoning_model( $model ) {
		$model = sanitize_text_field( (string) $model );
		return ( strpos( $model, 'openai/gpt-oss' ) === 0
			|| strpos( $model, 'gpt-oss-safeguard' ) !== false
			|| strpos( $model, 'qwen/' ) === 0 );
	}

	/**
	 * Map retired / inaccessible model IDs to a current default.
	 *
	 * @param string $model    Saved model id.
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function migrate_model_id( $model, $provider = 'groq' ) {
		$model    = sanitize_text_field( (string) $model );
		$provider = self::normalize_provider( $provider );
		$default  = ( $provider === 'openai' ) ? 'gpt-4o-mini' : 'meta-llama/llama-4-scout-17b-16e-instruct';
		$retired  = array(
			'llama-3.1-8b-instant'                => $default,
			'llama-3.3-70b-versatile'              => 'openai/gpt-oss-120b',
			'llama-3.1-70b-versatile'              => 'openai/gpt-oss-120b',
			'llama3-70b-8192'                     => 'openai/gpt-oss-120b',
			'llama3-8b-8192'                      => $default,
			'mixtral-8x7b-32768'                  => $default,
			'llama/llama-4-scout-17b-16e-instruct' => 'meta-llama/llama-4-scout-17b-16e-instruct',
			'llama/llama-4-maverick-17b-128e-instruct' => 'meta-llama/llama-4-maverick-17b-128e-instruct',
		);
		if ( isset( $retired[ $model ] ) ) {
			$model = $retired[ $model ];
		}
		$models = self::models_for_provider( $provider );
		if ( ! isset( $models[ $model ] ) ) {
			return $default;
		}
		return $model;
	}

	/**
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function normalize_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );
		return isset( self::providers()[ $provider ] ) ? $provider : 'groq';
	}

	/**
	 * @param array<string, mixed>|null $options Plugin settings or null to load.
	 * @return string
	 */
	public static function get_provider( $options = null ) {
		$options  = is_array( $options ) ? $options : Info::get_settings();
		$provider = isset( $options['ai_provider'] ) ? (string) $options['ai_provider'] : 'groq';
		$provider = self::normalize_provider( $provider );

		// Soft migration: use whichever provider actually has a saved key.
		$groq_key   = isset( $options['ai_groq_key'] ) ? trim( (string) $options['ai_groq_key'] ) : '';
		$openai_key = isset( $options['ai_openai_key'] ) ? trim( (string) $options['ai_openai_key'] ) : '';
		if ( $provider === 'groq' && $groq_key === '' && $openai_key !== '' ) {
			return 'openai';
		}
		if ( $provider === 'openai' && $openai_key === '' && $groq_key !== '' ) {
			return 'groq';
		}
		return $provider;
	}

	/**
	 * @param array<string, mixed>|null $options Plugin settings or null to load.
	 * @return bool
	 */
	public static function is_enabled( $options = null ) {
		$options = is_array( $options ) ? $options : Info::get_settings();
		return ! empty( $options['ai_enabled'] ) && (string) $options['ai_enabled'] === '1';
	}

	/**
	 * Active provider API key.
	 *
	 * @param array<string, mixed>|null $options Plugin settings or null to load.
	 * @return string
	 */
	public static function get_api_key( $options = null ) {
		$options  = is_array( $options ) ? $options : Info::get_settings();
		$provider = self::get_provider( $options );
		if ( $provider === 'openai' ) {
			return isset( $options['ai_openai_key'] ) ? trim( (string) $options['ai_openai_key'] ) : '';
		}
		return isset( $options['ai_groq_key'] ) ? trim( (string) $options['ai_groq_key'] ) : '';
	}

	/**
	 * @param array<string, mixed>|null $options Plugin settings or null to load.
	 * @return string
	 */
	public static function get_model( $options = null ) {
		$options  = is_array( $options ) ? $options : Info::get_settings();
		$provider = self::get_provider( $options );
		$default  = ( $provider === 'groq' ) ? 'meta-llama/llama-4-scout-17b-16e-instruct' : 'gpt-4o-mini';
		$model    = isset( $options['ai_model'] ) ? sanitize_text_field( (string) $options['ai_model'] ) : $default;
		return self::migrate_model_id( $model, $provider );
	}

	/**
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function api_url_for_provider( $provider ) {
		$provider = self::normalize_provider( $provider );
		return ( $provider === 'openai' ) ? self::API_URL_OPENAI : self::API_URL_GROQ;
	}

	/**
	 * System prompt for floor-plan drafts (Coworking Floor 2 / Beauty Salon wall language).
	 *
	 * @param int $canvas_w Canvas width.
	 * @param int $canvas_h Canvas height.
	 * @return string
	 */
	public static function layout_system_prompt( $canvas_w, $canvas_h ) {
		$canvas_w = max( 500, min( 2500, (int) $canvas_w ) );
		$canvas_h = max( 500, min( 2500, (int) $canvas_h ) );

		return 'You design dense top-down booking floor plans for PickyBooking, matching "Coworking Floor 2" / Beauty Salon: architectural WALL lines + furniture plates + bookable stations. Empty sparse boxes with only 3 identical seats are WRONG.

Return ONLY valid JSON: {"elements":[...]} — no markdown.

Canvas: ' . $canvas_w . 'x' . $canvas_h . 'px. Integers only; keep everything inside. Outer margin ≈ 32–48px.

=== FORBIDDEN ===
- Large filled type-shape room/floor plates (no colored room backgrounds).
- Rainbow / neon colors.
- type-text that repeats a seat code (A1, B2…). Seat IDs show on the bookable itself (show_id "1") — do NOT add duplicate text under each seat.
- Tiny junk shapes under 20×20 unless they are wall thickness.
- Plans with fewer than ~28 elements, or rooms that look empty.

=== WALLS (mandatory) ===
- class "type-wall", thickness exactly 10px, color #c5d4cc, seats/max_seats "0", show_id "0".
- Outer frame: left + top + right + bottom (bottom may have a 60–100px door gap).
- Interior divider(s) for every room split in the request (vertical and/or horizontal).
- Example left wall: {"x":40,"y":40,"x1":50,"y1":760,"class":"type-wall","unique":"ai_wl","color":"#c5d4cc","seats":"0","max_seats":"0","show_id":"0","id":"0"}

=== FURNITURE (mandatory — this makes the plan look real) ===
- Decorative type-shape only (NOT bookable): color #dde8e2 or #b4c7bd, shape_type "rounded", shape_radius "8", shape_border_width "0", opacity "1", show_id "0".
- CHAIRS / STOOLS (Coworking Floor 2 style): place type-shape UNDER the bookable with LOWER z_index so only ~half the chair is visible (tucked under the desk/table). Overlap the bookable edge by ~12px. Do NOT leave chairs floating beside/below with a gap.
- Per styling/beauty station: counter plate near the top wall + wash-bowl on the counter; chair tucked under the seat bottom.
- Per massage table: thin pad shape under the table (lower z); stool tucked under the right edge (lower z).
- Never larger than ~220×140 for a single furniture plate.
- No orphan mid-air walls or disconnected bars — every wall connects to the frame or a divider.

=== BOOKABLES (visually distinct by size) ===
- Beauty / salon chair: type-1 ≈ 72×56, square_radius "10".
- Massage table: type-1 ≈ 110×48 (clearly wider/longer than chairs), square_radius "8".
- Round stool: type-4 ≈ 56×56.
- All bookables: color #7ebfa0, opacity "1", seats/max_seats "1", show_id "1", label + custom_id = short code (A1,A2,B1…).
- Gaps 36–56px between stations; ≥20px from walls. Align to a grid.

=== ZONE LABELS ===
- One type-text per room/zone only (e.g. "Beauty Salon", "Massage"), font_size "12", font_weight "700", text_color "#999999", near the top of the room. No seat-code texts.

=== BUILD ORDER ===
1) Outer walls + interior dividers.
2) Zone type-text labels.
3) Furniture (counters, bowls, sofas, underlays) — several per room.
4) Bookable stations.

=== DENSITY TARGET ===
Aim for 32–55 elements. A two-room salon/massage plan should include: frame walls, 1 divider, 2 zone labels, counters/furniture in EACH room, and all requested bookables with distinct sizes.

=== SCHEMA ===
Every element needs: x,y,x1,y1, class, id, unique, seats, max_seats, show_id, color.
Allowed class: type-1 | type-4 | type-wall | type-shape | type-text.
unique like "ai_wall_1", "ai_a1". No overlapping bookables.';
	}

	/**
	 * System prompt: extract structured intent only (geometry is built in PHP).
	 *
	 * @return string
	 */
	public static function layout_intent_system_prompt() {
		return 'You extract booking floor-plan intent for PickyBooking. Plans vary widely: parking lots, beaches, beauty salons, coworking, offices, bars, cinemas, gyms, clinics, restaurants, VIP cabanas, etc.

Return ONLY JSON (no markdown), e.g.:
{"split":"vertical","rooms":[{"name":"Beach Lounge","kind":"beach","stations":30,"cols":3,"rows":10},{"name":"VIP Zone","kind":"vip","stations":4}]}

Rules:
- split: "vertical" | "horizontal". If user says справа/справа/right → vertical with that zone as the LAST room. If слева/left → that zone FIRST.
- 1–4 rooms/zones.
- kind MUST be one of: parking | beach | vip | bar | beauty | massage | office | coworking | meeting | restaurant | cinema | gym | clinic | generic
- stations: EXACT count requested. If user says "10 рядов по 3" set cols=3, rows=10, stations=30.
- cols/rows: optional grid hints (cols = items per row, rows = number of rows).
- name: short English zone label.
- VIP / gazebo / беседка → kind "vip".
- Beach loungers are bookables only (no furniture shapes under them).';
	}

	/**
	 * Registry of supported layout kinds (detection + build hints).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function layout_kind_defs() {
		return array(
			'parking'    => array(
				'label'  => 'Parking',
				'max'    => 100,
				'detect' => '/park(?:ing)?|парков|машиномест|garage|гараж|car\s*spot/u',
				'count'  => '/(\d+)\s*(?:park|парков|машиномест|stall|spot|space|мест)/u',
				'style'  => 'parking',
				'w'      => 58,
				'h'      => 30,
				'gapx'   => 14,
				'gapy'   => 16,
				'radius' => 4,
				'tuck'   => '',
			),
			'beach'      => array(
				'label'  => 'Beach Lounge',
				'max'    => 80,
				'detect' => '/beach|пляж|лежак|lounger|sunbed|шезлонг|sun\s*lounge/u',
				'count'  => '/(\d+)\s*(?:лежак|lounger|sunbed|шезлонг|sun\s*bed|мест)/u',
				'style'  => 'beach',
				'w'      => 56,
				'h'      => 32,
				'gapx'   => 16,
				'gapy'   => 18,
				'radius' => 8,
				'tuck'   => '', // loungers = bookable only, no table/chair shapes
			),
			'vip'        => array(
				'label'  => 'VIP Zone',
				'max'    => 20,
				'detect' => '/\bvip\b|вип|беседк|gazebo|cabana|bungalow|шатер|шатёр/u',
				'count'  => '/(\d+)\s*(?:vip|вип|беседк|gazebo|cabana|bungalow|шатер|шатёр)/u',
				'style'  => 'vip',
				'w'      => 88,
				'h'      => 72,
				'gapx'   => 24,
				'gapy'   => 28,
				'radius' => 12,
				'tuck'   => '',
			),
			'bar'        => array(
				'label'  => 'Bar',
				'max'    => 12,
				'detect' => '/\bbar\b|бар(?:а|ом|е|ная|ной|ной)?(?!\w)|стойк/u',
				'count'  => '/(\d+)\s*(?:барн|bar\s*stool|табурет|stool)/u',
				'style'  => 'bar',
				'w'      => 40,
				'h'      => 36,
				'gapx'   => 12,
				'gapy'   => 12,
				'radius' => 10,
				'tuck'   => 'left',
			),
			'beauty'     => array(
				'label'  => 'Beauty Salon',
				'max'    => 16,
				'detect' => '/beauty|salon|салон|кресе?л|парик|styling|hair|маник|nail|мойк/u',
				'count'  => '/(\d+)\s*(?:кресе?л\w*|chair|styling|station)/u',
				'style'  => 'beauty',
				'w'      => 72,
				'h'      => 56,
				'gapx'   => 40,
				'gapy'   => 40,
				'radius' => 10,
				'tuck'   => 'bottom',
			),
			'massage'    => array(
				'label'  => 'Massage Room',
				'max'    => 16,
				'detect' => '/massag|массаж|\bspa\b/u',
				'count'  => '/(\d+)\s*(?:массаж|massage|стол(?:а|ов|ы)?|tables?)/u',
				'style'  => 'massage',
				'w'      => 110,
				'h'      => 48,
				'gapx'   => 36,
				'gapy'   => 40,
				'radius' => 8,
				'tuck'   => 'right',
			),
			'office'     => array(
				'label'  => 'Offices',
				'max'    => 24,
				'detect' => '/\boffice|офис|кабинет|private\s*office/u',
				'count'  => '/(\d+)\s*(?:office|офис|кабинет|desk)/u',
				'style'  => 'office',
				'w'      => 72,
				'h'      => 56,
				'gapx'   => 36,
				'gapy'   => 48,
				'radius' => 10,
				'tuck'   => 'bottom',
			),
			'coworking'  => array(
				'label'  => 'Open Desks',
				'max'    => 60,
				'detect' => '/cowork|коворк|hot\s*desk|open\s*desk|open\s*space/u',
				'count'  => '/(\d+)\s*(?:desk|стол|мест|station)/u',
				'style'  => 'office',
				'w'      => 72,
				'h'      => 56,
				'gapx'   => 20,
				'gapy'   => 24,
				'radius' => 10,
				'tuck'   => 'bottom',
			),
			'meeting'    => array(
				'label'  => 'Meeting',
				'max'    => 16,
				'detect' => '/meeting|переговор|конференц|boardroom/u',
				'count'  => '/(\d+)\s*(?:seat|мест|chair)/u',
				'style'  => 'meeting',
				'w'      => 70,
				'h'      => 56,
				'gapx'   => 24,
				'gapy'   => 24,
				'radius' => 10,
				'tuck'   => 'bottom',
			),
			'restaurant' => array(
				'label'  => 'Dining',
				'max'    => 40,
				'detect' => '/restaurant|ресторан|cafe|кафе|dining|столов/u',
				'count'  => '/(\d+)\s*(?:table|стол(?:а|ов|ик)?|мест)/u',
				'style'  => 'restaurant',
				'w'      => 64,
				'h'      => 64,
				'gapx'   => 28,
				'gapy'   => 32,
				'radius' => 10,
				'tuck'   => 'bottom',
			),
			'cinema'     => array(
				'label'  => 'Auditorium',
				'max'    => 120,
				'detect' => '/cinema|theater|theatre|кино(?:театр|зал)?|auditorium|зрительн|кинотеатр/u',
				'count'  => '/(\d+)\s*(?:seat|мест|кресл|row)/u',
				'style'  => 'cinema',
				'w'      => 34,
				'h'      => 30,
				'gapx'   => 8,
				'gapy'   => 12,
				'radius' => 6,
				'tuck'   => '',
				// Bookable fills: standard #c5bedc, premium mid-rows #b2a8d2 (see build_layout_from_intent).
			),
			'gym'        => array(
				'label'  => 'Gym',
				'max'    => 40,
				'detect' => '/\bgym\b|фитнес|fitness|тренаж|workout/u',
				'count'  => '/(\d+)\s*(?:station|тренаж|машин|мест)/u',
				'style'  => 'gym',
				'w'      => 70,
				'h'      => 40,
				'gapx'   => 20,
				'gapy'   => 24,
				'radius' => 8,
				'tuck'   => '',
			),
			'clinic'     => array(
				'label'  => 'Clinic',
				'max'    => 20,
				'detect' => '/clinic|клиник|dental|стомат|medical|мед(?:ицин)?|doctor|врач|кабинет\s*врач/u',
				'count'  => '/(\d+)\s*(?:room|кабинет|station|мест)/u',
				'style'  => 'clinic',
				'w'      => 80,
				'h'      => 56,
				'gapx'   => 28,
				'gapy'   => 32,
				'radius' => 8,
				'tuck'   => 'bottom',
			),
			'generic'    => array(
				'label'  => 'Main Hall',
				'max'    => 60,
				'detect' => '/./u',
				'count'  => '/(\d+)\s*(?:мест|spot|station|seat)/u',
				'style'  => 'office',
				'w'      => 72,
				'h'      => 56,
				'gapx'   => 28,
				'gapy'   => 32,
				'radius' => 10,
				'tuck'   => 'bottom',
			),
		);
	}

	/**
	 * @param string $kind Kind id.
	 * @return array<string, mixed>
	 */
	public static function kind_def( $kind ) {
		$defs = self::layout_kind_defs();
		$kind = (string) $kind;
		if ( isset( $defs[ $kind ] ) ) {
			return $defs[ $kind ];
		}
		// Aliases.
		$aliases = array(
			'desk'       => 'coworking',
			'cowork'     => 'coworking',
			'salon'      => 'beauty',
			'spa'        => 'massage',
			'theater'    => 'cinema',
			'theatre'    => 'cinema',
			'cafe'       => 'restaurant',
			'dining'     => 'restaurant',
			'fitness'    => 'gym',
			'medical'    => 'clinic',
			'dental'     => 'clinic',
			'lounge'     => 'beach',
			'lounger'    => 'beach',
			'gazebo'     => 'vip',
			'cabana'     => 'vip',
			'bungalow'   => 'vip',
		);
		if ( isset( $aliases[ $kind ] ) && isset( $defs[ $aliases[ $kind ] ] ) ) {
			return $defs[ $aliases[ $kind ] ];
		}
		return $defs['generic'];
	}

	/**
	 * Normalize kind id (aliases → canonical).
	 *
	 * @param string $kind Raw kind.
	 * @return string
	 */
	public static function normalize_kind_id( $kind ) {
		$kind = strtolower( sanitize_key( (string) $kind ) );
		$defs = self::layout_kind_defs();
		if ( isset( $defs[ $kind ] ) ) {
			return $kind;
		}
		$aliases = array(
			'desk'       => 'coworking',
			'cowork'     => 'coworking',
			'salon'      => 'beauty',
			'spa'        => 'massage',
			'theater'    => 'cinema',
			'theatre'    => 'cinema',
			'cafe'       => 'restaurant',
			'dining'     => 'restaurant',
			'fitness'    => 'gym',
			'medical'    => 'clinic',
			'dental'     => 'clinic',
			'lounge'     => 'beach',
			'lounger'    => 'beach',
			'garage'     => 'parking',
			'gazebo'     => 'vip',
			'cabana'     => 'vip',
			'bungalow'   => 'vip',
		);
		if ( isset( $aliases[ $kind ] ) ) {
			return $aliases[ $kind ];
		}
		// Fuzzy from free text kind.
		$raw = strtolower( (string) $kind );
		foreach ( $defs as $id => $def ) {
			if ( $id === 'generic' ) {
				continue;
			}
			if ( ! empty( $def['detect'] ) && preg_match( $def['detect'], $raw ) ) {
				return $id;
			}
		}
		return 'generic';
	}

	/**
	 * Max stations allowed per room kind.
	 *
	 * @param string $kind Room kind.
	 * @return int
	 */
	public static function max_stations_for_kind( $kind ) {
		$def = self::kind_def( self::normalize_kind_id( $kind ) );
		return isset( $def['max'] ) ? (int) $def['max'] : 48;
	}

	/**
	 * Clamp station count for a kind.
	 *
	 * @param string $kind Kind.
	 * @param int    $n    Requested count.
	 * @return int
	 */
	public static function clamp_stations( $kind, $n ) {
		$max = self::max_stations_for_kind( $kind );
		return max( 1, min( $max, (int) $n ) );
	}

	/**
	 * Heuristic intent when the model fails or returns junk.
	 *
	 * @param string $prompt User prompt.
	 * @return array<string, mixed>
	 */
	public static function guess_layout_intent_from_prompt( $prompt ) {
		$p    = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $prompt ) : strtolower( (string) $prompt );
		$defs = self::layout_kind_defs();

		// "10 рядов по 3" / "10 rows of 3" / "3x10"
		$grid_cols = 0;
		$grid_rows = 0;
		if ( preg_match( '/(\d+)\s*ряд(?:ов|а|ы)?\s*по\s*(\d+)/u', $p, $gm ) ) {
			$grid_rows = max( 1, (int) $gm[1] );
			$grid_cols = max( 1, (int) $gm[2] );
		} elseif ( preg_match( '/(\d+)\s*rows?\s*(?:of|x|×|\*)\s*(\d+)/u', $p, $gm ) ) {
			$grid_rows = max( 1, (int) $gm[1] );
			$grid_cols = max( 1, (int) $gm[2] );
		} elseif ( preg_match( '/(\d+)\s*[xх×*]\s*(\d+)/u', $p, $gm ) ) {
			// Prefer smaller as cols when ambiguous (3x10 vs 10x3); if first is larger treat as rows×cols.
			$a = (int) $gm[1];
			$b = (int) $gm[2];
			if ( $a >= $b ) {
				$grid_rows = $a;
				$grid_cols = $b;
			} else {
				$grid_cols = $a;
				$grid_rows = $b;
			}
		}

		$priority = array( 'parking', 'beach', 'vip', 'cinema', 'beauty', 'massage', 'bar', 'restaurant', 'gym', 'clinic', 'meeting', 'office', 'coworking' );

		$found = array(); // kind => stations
		foreach ( $priority as $kind ) {
			$def = $defs[ $kind ];
			if ( empty( $def['detect'] ) || ! preg_match( $def['detect'], $p ) ) {
				continue;
			}
			$n = 8;
			if ( $kind === 'beach' ) {
				$n = 40;
			} elseif ( $kind === 'parking' || $kind === 'cinema' ) {
				$n = 30;
			} elseif ( $kind === 'coworking' ) {
				$n = 12;
			} elseif ( $kind === 'bar' ) {
				$n = 8;
			} elseif ( $kind === 'vip' ) {
				$n = 4;
			} elseif ( in_array( $kind, array( 'beauty', 'massage', 'meeting', 'office', 'clinic' ), true ) ) {
				$n = 4;
			} elseif ( $kind === 'restaurant' || $kind === 'gym' ) {
				$n = 12;
			}
			if ( ! empty( $def['count'] ) && preg_match( $def['count'], $p, $m ) ) {
				$n = (int) $m[1];
			} elseif ( preg_match( '/(\d+)\s*(?:мест|spot|station|seat|stall)/u', $p, $m ) && count( $found ) === 0 ) {
				$n = (int) $m[1];
			}
			// Beach grid overrides station count when explicit.
			if ( $kind === 'beach' && $grid_cols > 0 && $grid_rows > 0 ) {
				$n = $grid_cols * $grid_rows;
			}
			$found[ $kind ] = self::clamp_stations( $kind, $n );
		}

		$rooms = array();
		if ( empty( $found ) ) {
			$n = 8;
			if ( preg_match( '/(\d+)/u', $p, $m ) ) {
				$n = self::clamp_stations( 'generic', (int) $m[1] );
			}
			$rooms[] = array(
				'name'     => 'Main Hall',
				'kind'     => 'generic',
				'stations' => $n,
			);
		} else {
			$i = 0;
			foreach ( $found as $kind => $stations ) {
				$room = array(
					'name'     => $defs[ $kind ]['label'],
					'kind'     => $kind,
					'stations' => $stations,
				);
				if ( $kind === 'beach' && $grid_cols > 0 && $grid_rows > 0 ) {
					$room['cols'] = $grid_cols;
					$room['rows'] = $grid_rows;
					$room['stations'] = self::clamp_stations( 'beach', $grid_cols * $grid_rows );
				}
				$rooms[] = $room;
				$i++;
				if ( $i >= 3 ) {
					break;
				}
			}
		}

		// Side keywords: справа → VIP/bar last + vertical; слева → that zone first.
		$split = 'vertical';
		$right = (bool) preg_match( '/справа|справа|on\s*the\s*right|\bright\b/u', $p );
		$left  = (bool) preg_match( '/слева|on\s*the\s*left|\bleft\b/u', $p );
		if ( $right || $left ) {
			$split = 'vertical';
			$side_kinds = array( 'vip', 'bar' );
			$main       = array();
			$side       = array();
			foreach ( $rooms as $room ) {
				if ( in_array( $room['kind'], $side_kinds, true ) ) {
					$side[] = $room;
				} else {
					$main[] = $room;
				}
			}
			if ( ! empty( $side ) ) {
				$rooms = $left ? array_merge( $side, $main ) : array_merge( $main, $side );
			}
		} elseif ( count( $rooms ) === 1 && in_array( $rooms[0]['kind'], array( 'cinema' ), true ) ) {
			$split = 'horizontal';
		}

		return array(
			'split' => $split,
			'rooms' => $rooms,
		);
	}

	/**
	 * Normalize intent payload from AI or heuristics.
	 *
	 * @param mixed  $raw            Raw decoded JSON.
	 * @param string $fallback_prompt Prompt for heuristic fallback (never empty-string guess).
	 * @return array<string, mixed>
	 */
	public static function normalize_layout_intent( $raw, $fallback_prompt = '' ) {
		$defs  = self::layout_kind_defs();
		$split = 'vertical';
		$rooms = array();

		if ( is_array( $raw ) ) {
			if ( ! empty( $raw['split'] ) && in_array( (string) $raw['split'], array( 'vertical', 'horizontal' ), true ) ) {
				$split = (string) $raw['split'];
			}
			$list = array();
			if ( ! empty( $raw['rooms'] ) && is_array( $raw['rooms'] ) ) {
				$list = $raw['rooms'];
			}
			foreach ( $list as $room ) {
				if ( ! is_array( $room ) ) {
					continue;
				}
				if ( isset( $room['class'] ) || isset( $room['x1'] ) ) {
					continue;
				}
				$kind = self::normalize_kind_id( isset( $room['kind'] ) ? (string) $room['kind'] : 'generic' );
				// Also sniff name for kind if generic.
				if ( $kind === 'generic' && ! empty( $room['name'] ) ) {
					$kind = self::normalize_kind_id( (string) $room['name'] );
				}
				$name = isset( $room['name'] ) ? sanitize_text_field( (string) $room['name'] ) : '';
				if ( $name === '' || strtolower( $name ) === 'layout name' ) {
					$name = isset( $defs[ $kind ]['label'] ) ? (string) $defs[ $kind ]['label'] : ucfirst( $kind );
				}
				$stations = isset( $room['stations'] ) ? absint( $room['stations'] ) : 3;
				$stations = self::clamp_stations( $kind, $stations );
				$entry    = array(
					'name'     => $name,
					'kind'     => $kind,
					'stations' => $stations,
				);
				if ( ! empty( $room['cols'] ) ) {
					$entry['cols'] = max( 1, min( 40, absint( $room['cols'] ) ) );
				}
				if ( ! empty( $room['rows'] ) ) {
					$entry['rows'] = max( 1, min( 40, absint( $room['rows'] ) ) );
				}
				if ( ! empty( $entry['cols'] ) && ! empty( $entry['rows'] ) ) {
					$entry['stations'] = self::clamp_stations( $kind, (int) $entry['cols'] * (int) $entry['rows'] );
				}
				$rooms[] = $entry;
				if ( count( $rooms ) >= 4 ) {
					break;
				}
			}
		}

		if ( empty( $rooms ) ) {
			return self::guess_layout_intent_from_prompt( (string) $fallback_prompt );
		}

		return array(
			'split' => $split,
			'rooms' => $rooms,
		);
	}

	/**
	 * Merge AI intent with heuristics — heuristics win on room count / specialty kinds.
	 *
	 * @param array<string, mixed> $guess  Heuristic intent.
	 * @param array<string, mixed> $ai     AI intent (already normalized).
	 * @param string               $prompt Original prompt.
	 * @return array<string, mixed>
	 */
	public static function merge_layout_intent( array $guess, array $ai, $prompt = '' ) {
		$guess = self::normalize_layout_intent( $guess, $prompt );
		$ai    = self::normalize_layout_intent( $ai, $prompt );

		$guess_n = count( $guess['rooms'] );
		$ai_n    = count( $ai['rooms'] );

		$guess_kinds = array_map(
			static function ( $r ) {
				return $r['kind'];
			},
			$guess['rooms']
		);
		$ai_kinds = array_map(
			static function ( $r ) {
				return $r['kind'];
			},
			$ai['rooms']
		);

		$specialty = array( 'parking', 'beach', 'vip', 'cinema', 'beauty', 'massage', 'bar', 'restaurant', 'gym', 'clinic', 'office', 'coworking', 'meeting' );
		$guess_special = (bool) array_intersect( $guess_kinds, $specialty );
		$guess_has_pair = count( array_intersect( $guess_kinds, $specialty ) ) >= 2;
		$ai_has_same_pair = count( array_intersect( $ai_kinds, $guess_kinds ) ) >= min( 2, count( $guess_kinds ) );

		if ( $guess_has_pair && ! $ai_has_same_pair ) {
			return $guess;
		}
		if ( $guess_n > $ai_n && $guess_special ) {
			return $guess;
		}
		if ( $ai_n < 2 && $guess_n >= 2 && (bool) preg_match( '/\b(два|two|2)\b|слева|справа|left|right|соседн|две\s*комнат|отдельн|бар|bar|вип|vip|и\s/u', (string) $prompt ) ) {
			return $guess;
		}

		// Side + grid hints from heuristics are authoritative.
		if ( (bool) preg_match( '/справа|слева|on\s*the\s*right|on\s*the\s*left|\bright\b|\bleft\b|ряд(?:ов|а)?\s*по/u', (string) $prompt ) ) {
			$guess['split'] = 'vertical';
			return $guess;
		}

		$guess_seats = 0;
		$ai_seats    = 0;
		foreach ( $guess['rooms'] as $r ) {
			$guess_seats += (int) $r['stations'];
		}
		foreach ( $ai['rooms'] as $r ) {
			$ai_seats += (int) $r['stations'];
		}
		if ( $guess_seats >= ( $ai_seats * 2 ) && $guess_seats >= 20 ) {
			return $guess;
		}

		foreach ( $guess_kinds as $gk ) {
			if ( in_array( $gk, array( 'parking', 'beach', 'cinema', 'gym', 'clinic', 'restaurant', 'vip' ), true )
				&& ! in_array( $gk, $ai_kinds, true ) ) {
				return $guess;
			}
		}

		if ( $ai_n === $guess_n && $ai_n > 0 ) {
			$rooms = array();
			for ( $i = 0; $i < $ai_n; $i++ ) {
				$g    = $guess['rooms'][ $i ];
				$a    = $ai['rooms'][ $i ];
				$kind = $g['kind'];
				if ( $a['kind'] !== 'generic' ) {
					$kind = self::normalize_kind_id( $a['kind'] );
				}
				$name = $a['name'];
				if ( $name === '' || strtolower( $name ) === 'layout name' || strtolower( $name ) === $a['kind'] ) {
					$name = $g['name'];
				}
				$stations = max( (int) $g['stations'], (int) $a['stations'] );
				$stations = self::clamp_stations( $kind, $stations );
				$room     = array(
					'name'     => $name,
					'kind'     => $kind,
					'stations' => $stations,
				);
				if ( ! empty( $g['cols'] ) ) {
					$room['cols'] = (int) $g['cols'];
				} elseif ( ! empty( $a['cols'] ) ) {
					$room['cols'] = (int) $a['cols'];
				}
				if ( ! empty( $g['rows'] ) ) {
					$room['rows'] = (int) $g['rows'];
				} elseif ( ! empty( $a['rows'] ) ) {
					$room['rows'] = (int) $a['rows'];
				}
				if ( ! empty( $room['cols'] ) && ! empty( $room['rows'] ) ) {
					$room['stations'] = self::clamp_stations( $kind, (int) $room['cols'] * (int) $room['rows'] );
				}
				$rooms[] = $room;
			}
			return array(
				'split' => ! empty( $ai['split'] ) ? $ai['split'] : $guess['split'],
				'rooms' => $rooms,
			);
		}

		return $ai_n >= $guess_n ? $ai : $guess;
	}

	/**
	 * Suggest a snug canvas size for the intent (avoids huge empty margins).
	 *
	 * @param array<string, mixed> $intent     Intent.
	 * @param int                  $fallback_w Current canvas width.
	 * @param int                  $fallback_h Current canvas height.
	 * @return array{0:int,1:int} Width, height.
	 */
	public static function suggest_canvas_for_intent( array $intent, $fallback_w = 900, $fallback_h = 700 ) {
		$intent = self::normalize_layout_intent( $intent );
		$rooms  = $intent['rooms'];
		$split  = isset( $intent['split'] ) ? (string) $intent['split'] : 'vertical';
		$m      = 40;
		$tw     = 10;
		$pad    = 32;

		if ( empty( $rooms ) ) {
			return array( max( 560, (int) $fallback_w ), max( 480, (int) $fallback_h ) );
		}

		$zone = array();
		foreach ( $rooms as $room ) {
			$kind = self::normalize_kind_id( isset( $room['kind'] ) ? $room['kind'] : 'generic' );
			$def  = self::kind_def( $kind );
			$st   = isset( $room['stations'] ) ? max( 1, (int) $room['stations'] ) : 4;
			$cols = ! empty( $room['cols'] ) ? max( 1, (int) $room['cols'] ) : 0;
			$rows = ! empty( $room['rows'] ) ? max( 1, (int) $room['rows'] ) : 0;
			if ( $cols > 0 && $rows > 0 ) {
				$st = $cols * $rows;
			} elseif ( $cols <= 0 ) {
				if ( in_array( $kind, array( 'vip', 'bar', 'meeting' ), true ) ) {
					$cols = min( 2, $st );
				} elseif ( in_array( $kind, array( 'beach', 'parking', 'cinema' ), true ) ) {
					$cols = min( max( 3, (int) ceil( sqrt( $st ) ) ), $st );
				} else {
					$cols = min( max( 2, (int) ceil( sqrt( $st ) ) ), $st );
				}
				$rows = (int) ceil( $st / max( 1, $cols ) );
			} else {
				$rows = (int) ceil( $st / $cols );
			}

			$sw = max( 36, (int) $def['w'] + 12 );
			$sh = max( 28, (int) $def['h'] + 10 );
			if ( $kind === 'vip' ) {
				$sw = 96;
				$sh = 78;
			} elseif ( $kind === 'beach' ) {
				$sw = 64;
				$sh = 40;
			}
			$gapx = max( 14, (int) $def['gapx'] );
			$gapy = max( 14, (int) $def['gapy'] );
			$cw   = ( $cols * $sw ) + ( ( $cols - 1 ) * $gapx ) + ( $pad * 2 );
			$ch   = ( $rows * $sh ) + ( ( $rows - 1 ) * $gapy ) + ( $pad * 2 ) + 28;
			if ( in_array( $kind, array( 'bar', 'vip' ), true ) && count( $rooms ) > 1 ) {
				$cw = max( $cw, 240 );
			}
			$zone[] = array(
				'w'    => $cw,
				'h'    => $ch,
				'kind' => $kind,
			);
		}

		$n = count( $zone );
		if ( $n === 1 ) {
			$w = ( 2 * $m ) + ( 2 * $tw ) + $zone[0]['w'];
			$h = ( 2 * $m ) + ( 2 * $tw ) + $zone[0]['h'];
		} elseif ( $split === 'horizontal' ) {
			$w = ( 2 * $m ) + ( 2 * $tw ) + max( $zone[0]['w'], $zone[1]['w'] );
			$h = ( 2 * $m ) + ( 3 * $tw ) + $zone[0]['h'] + $zone[1]['h'];
		} else {
			$sum_w = 0;
			$max_h = 0;
			foreach ( $zone as $z ) {
				$sum_w += $z['w'];
				$max_h  = max( $max_h, $z['h'] );
			}
			$w = ( 2 * $m ) + ( ( $n + 1 ) * $tw ) + $sum_w;
			$h = ( 2 * $m ) + ( 2 * $tw ) + $max_h;
		}

		// Always size to content — oversized current canvas is what creates empty voids.
		$w = max( 560, min( 2500, (int) $w ) );
		$h = max( 480, min( 2500, (int) $h ) );

		return array( $w, $h );
	}

	/**
	 * Build a CF2/Beauty-Salon style plan from structured intent (deterministic).
	 *
	 * @param array<string, mixed> $intent    Intent.
	 * @param int                  $canvas_w  Width.
	 * @param int                  $canvas_h  Height.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_layout_from_intent( array $intent, $canvas_w, $canvas_h ) {
		$canvas_w = max( 500, min( 2500, (int) $canvas_w ) );
		$canvas_h = max( 500, min( 2500, (int) $canvas_h ) );
		$intent   = self::normalize_layout_intent( $intent );
		$rooms    = $intent['rooms'];
		$split    = $intent['split'];
		$tw       = 10;
		$m        = 36;
		$wall_c   = '#c5d4cc';
		$furn     = '#dde8e2';
		$furn2    = '#b4c7bd';
		$item     = '#7ebfa0';
		$ink      = '#999999';

		$elements = array();
		$n        = 0;
		$add      = static function ( array $o, $z = null ) use ( &$elements, &$n ) {
			$n++;
			if ( empty( $o['unique'] ) ) {
				$o['unique'] = 'ai_built_' . $n;
			}
			$o['z_index'] = null !== $z ? (int) $z : ( 10 + $n );
			$elements[]   = $o;
		};

		$wall = static function ( $x, $y, $w, $h, $id ) use ( $add, $wall_c, $tw ) {
			if ( $w >= $h ) {
				$h = $tw;
			} else {
				$w = $tw;
			}
			$add(
				array(
					'x'         => $x,
					'y'         => $y,
					'x1'        => $x + $w,
					'y1'        => $y + $h,
					'class'     => 'type-wall',
					'unique'    => 'ai_wall_' . $id,
					'id'        => '0',
					'seats'     => '0',
					'max_seats' => '0',
					'show_id'   => '0',
					'color'     => $wall_c,
					'opacity'   => '1',
				),
				5
			);
		};

		// Decorative shape. Pass $z LOWER than bookables so chairs tuck under desks (CF2 style).
		$shape = static function ( $x, $y, $w, $h, $id, $color, $radius = 8, $z = 12 ) use ( $add ) {
			$add(
				array(
					'x'                  => $x,
					'y'                  => $y,
					'x1'                 => $x + $w,
					'y1'                 => $y + $h,
					'class'              => 'type-shape',
					'unique'             => 'ai_sh_' . $id,
					'id'                 => '0',
					'seats'              => '0',
					'max_seats'          => '0',
					'show_id'            => '0',
					'color'              => $color,
					'opacity'            => '1',
					'shape_type'         => 'rounded',
					'shape_radius'       => (string) $radius,
					'shape_border_width' => '0',
				),
				$z
			);
		};

		$text = static function ( $x, $y, $w, $h, $label, $id ) use ( $add, $ink ) {
			$add(
				array(
					'x'           => $x,
					'y'           => $y,
					'x1'          => $x + $w,
					'y1'          => $y + $h,
					'class'       => 'type-text',
					'unique'      => 'ai_tx_' . $id,
					'id'          => '0',
					'seats'       => '0',
					'max_seats'   => '0',
					'show_id'     => '0',
					'text'        => $label,
					'text_color'  => $ink,
					'font_size'   => '12',
					'font_weight' => '700',
					'padding'     => '2',
				),
				40
			);
		};

		$seat = static function ( $x, $y, $w, $h, $code, $radius = 10, $z = 30, $color = null ) use ( $add, $item ) {
			$add(
				array(
					'x'              => $x,
					'y'              => $y,
					'x1'             => $x + $w,
					'y1'             => $y + $h,
					'class'          => 'type-1',
					'unique'         => 'ai_seat_' . strtolower( $code ),
					'id'             => $code,
					'custom_id'      => $code,
					'label'          => '',
					'label_position' => 'bottom',
					'seats'          => '1',
					'max_seats'      => '1',
					'show_id'        => '1',
					'color'          => ( null !== $color && $color !== '' ) ? $color : $item,
					'opacity'        => '1',
					'square_radius'  => (string) $radius,
					'label_color'    => '#333333',
				),
				$z
			);
		};

		/**
		 * Chair/stool shape tucked under a bookable (only ~half visible) — CF2 Offices / MR style.
		 *
		 * @param string $side bottom|top|left|right
		 */
		$tuck_seat = static function ( $sx, $sy, $sw, $sh, $code, $side, $color ) use ( $shape ) {
			$cw = 34;
			$ch = 26;
			if ( $side === 'bottom' ) {
				$shape( $sx + (int) floor( ( $sw - $cw ) / 2 ), $sy + $sh - 12, $cw, $ch, $code . '_ch', $color, 8, 12 );
			} elseif ( $side === 'top' ) {
				$shape( $sx + (int) floor( ( $sw - $cw ) / 2 ), $sy - $ch + 12, $cw, $ch, $code . '_ch', $color, 8, 12 );
			} elseif ( $side === 'left' ) {
				$shape( $sx - $cw + 12, $sy + (int) floor( ( $sh - $ch ) / 2 ), $cw, $ch, $code . '_ch', $color, 8, 12 );
			} else {
				// right
				$shape( $sx + $sw - 12, $sy + (int) floor( ( $sh - $ch ) / 2 ), $cw, $ch, $code . '_ch', $color, 8, 12 );
			}
		};

		$inner_x0 = $m;
		$inner_y0 = $m;
		$inner_x1 = $canvas_w - $m;
		$inner_y1 = $canvas_h - $m;
		$inner_w  = $inner_x1 - $inner_x0;
		$inner_h  = $inner_y1 - $inner_y0;

		// Outer frame with door gap on bottom.
		$wall( $inner_x0, $inner_y0, $tw, $inner_h, 'ol' );
		$wall( $inner_x0, $inner_y0, $inner_w, $tw, 'ot' );
		$wall( $inner_x1 - $tw, $inner_y0, $tw, $inner_h, 'or' );
		$gap   = min( 100, max( 60, (int) floor( $inner_w * 0.12 ) ) );
		$gap_x = (int) floor( $inner_x0 + ( $inner_w - $gap ) / 2 );
		$wall( $inner_x0, $inner_y1 - $tw, max( $tw, $gap_x - $inner_x0 ), $tw, 'ob_l' );
		$wall( $gap_x + $gap, $inner_y1 - $tw, max( $tw, $inner_x1 - ( $gap_x + $gap ) ), $tw, 'ob_r' );

		$count = count( $rooms );
		$rects = array();
		if ( $count <= 1 ) {
			$rects[] = array( $inner_x0 + $tw + 8, $inner_y0 + $tw + 8, $inner_x1 - $tw - 8, $inner_y1 - $tw - 8 );
		} elseif ( $split === 'horizontal' ) {
			$mid = (int) floor( ( $inner_y0 + $inner_y1 ) / 2 );
			$wall( $inner_x0 + $tw, $mid - (int) floor( $tw / 2 ), $inner_w - ( 2 * $tw ), $tw, 'div' );
			$rects[] = array( $inner_x0 + $tw + 8, $inner_y0 + $tw + 8, $inner_x1 - $tw - 8, $mid - $tw - 8 );
			$rects[] = array( $inner_x0 + $tw + 8, $mid + $tw + 8, $inner_x1 - $tw - 8, $inner_y1 - $tw - 8 );
		} else {
			// vertical split (default)
			if ( $count === 2 ) {
				$k0 = $rooms[0]['kind'];
				$k1 = $rooms[1]['kind'];
				$n0 = (int) $rooms[0]['stations'];
				$n1 = (int) $rooms[1]['stations'];
				// Narrow strip for small service zones (bar / vip) next to a large field.
				// Keep VIP wide enough for a 2-col gazebo grid (~38% of width).
				if ( ( in_array( 'bar', array( $k0, $k1 ), true ) || in_array( 'vip', array( $k0, $k1 ), true ) )
					&& max( $n0, $n1 ) >= 8 ) {
					$narrow_right = in_array( $k1, array( 'bar', 'vip' ), true );
					$main_share  = in_array( 'vip', array( $k0, $k1 ), true ) ? 0.60 : 0.70;
					$mid         = $narrow_right
						? (int) floor( $inner_x0 + ( $inner_w * $main_share ) )
						: (int) floor( $inner_x0 + ( $inner_w * ( 1 - $main_share ) ) );
				} elseif ( $n0 >= ( $n1 * 3 ) || $n1 >= ( $n0 * 3 ) ) {
					$mid = $n0 >= $n1
						? (int) floor( $inner_x0 + ( $inner_w * 0.7 ) )
						: (int) floor( $inner_x0 + ( $inner_w * 0.3 ) );
				} else {
					$mid = (int) floor( ( $inner_x0 + $inner_x1 ) / 2 );
				}
				$wall( $mid - (int) floor( $tw / 2 ), $inner_y0 + $tw, $tw, $inner_h - ( 2 * $tw ), 'div' );
				$rects[] = array( $inner_x0 + $tw + 8, $inner_y0 + $tw + 8, $mid - $tw - 8, $inner_y1 - $tw - 8 );
				$rects[] = array( $mid + $tw + 8, $inner_y0 + $tw + 8, $inner_x1 - $tw - 8, $inner_y1 - $tw - 8 );
			} else {
				// 3–4 rooms: 2×2 grid.
				$mx = (int) floor( ( $inner_x0 + $inner_x1 ) / 2 );
				$my = (int) floor( ( $inner_y0 + $inner_y1 ) / 2 );
				$wall( $mx - (int) floor( $tw / 2 ), $inner_y0 + $tw, $tw, $inner_h - ( 2 * $tw ), 'div_v' );
				$wall( $inner_x0 + $tw, $my - (int) floor( $tw / 2 ), $inner_w - ( 2 * $tw ), $tw, 'div_h' );
				$rects[] = array( $inner_x0 + $tw + 8, $inner_y0 + $tw + 8, $mx - $tw - 8, $my - $tw - 8 );
				$rects[] = array( $mx + $tw + 8, $inner_y0 + $tw + 8, $inner_x1 - $tw - 8, $my - $tw - 8 );
				$rects[] = array( $inner_x0 + $tw + 8, $my + $tw + 8, $mx - $tw - 8, $inner_y1 - $tw - 8 );
				$rects[] = array( $mx + $tw + 8, $my + $tw + 8, $inner_x1 - $tw - 8, $inner_y1 - $tw - 8 );
			}
		}

		$letters = array( 'A', 'B', 'C', 'D' );
		foreach ( $rooms as $ri => $room ) {
			if ( ! isset( $rects[ $ri ] ) ) {
				break;
			}
			$r     = $rects[ $ri ];
			$rx0   = $r[0];
			$ry0   = $r[1];
			$rx1   = $r[2];
			$ry1   = $r[3];
			$rw    = $rx1 - $rx0;
			$rh    = $ry1 - $ry0;
			$kind  = $room['kind'];
			$name  = $room['name'];
			$st    = (int) $room['stations'];
			$force_cols = ! empty( $room['cols'] ) ? max( 1, (int) $room['cols'] ) : 0;
			$force_rows = ! empty( $room['rows'] ) ? max( 1, (int) $room['rows'] ) : 0;
			if ( $force_cols > 0 && $force_rows > 0 ) {
				$st = $force_cols * $force_rows;
			}
			$letter = $letters[ $ri ] ?? 'X';

			$text( $rx0 + 8, $ry0 + 6, min( 180, $rw - 16 ), 18, $name, 'zone_' . $ri );

			$kind  = self::normalize_kind_id( $kind );
			$def   = self::kind_def( $kind );
			$style = isset( $def['style'] ) ? (string) $def['style'] : 'office';

			// VIP / bar: prefer a compact 2-column grid when the prompt didn't specify cols.
			if ( $force_cols <= 0 && in_array( $style, array( 'vip', 'bar', 'meeting' ), true ) && $st >= 2 ) {
				$force_cols = min( 2, $st );
			}
			// Shared dense grid — spreads items across the room via gaps; grows size modestly.
			$fill_grid = static function ( $sw, $sh, $gapx, $gapy, $radius, $tuck_side, $scale_dense = true, $force_cols = 0 ) use ( $st, $rw, $rh, $rx0, $ry0, $letter, $seat, $tuck_seat, $furn ) {
				$label_pad = 40;
				$edge      = 20;
				$usable_w  = max( 80, $rw - ( 2 * $edge ) );
				$usable_h  = max( 60, $rh - $label_pad - $edge );
				$base_sw   = max( 24, (int) $sw );
				$base_sh   = max( 18, (int) $sh );

				if ( $force_cols > 0 ) {
					$cols = min( $force_cols, $st );
				} else {
					$base_cell = max( $base_sw + max( 8, (int) $gapx ), 40 );
					$cols      = max( 1, min( $st, (int) floor( $usable_w / $base_cell ) ) );
					while ( $cols < $st ) {
						$rows_try = (int) ceil( $st / $cols );
						if ( $rows_try * ( $base_sh + 12 ) <= $usable_h ) {
							break;
						}
						$next = $cols + 1;
						if ( $next * 28 > $usable_w ) {
							break;
						}
						$cols = $next;
					}
				}
				$rows = (int) ceil( $st / max( 1, $cols ) );

				$min_gap = 10;
				$max_sw  = min( 140, (int) round( $base_sw * 1.65 ) );
				$max_sh  = min( 110, (int) round( $base_sh * 1.65 ) );
				$sw      = $base_sw;
				$sh      = $base_sh;

				// Fit columns into width first (VIP strip can be narrow).
				$need_w = ( $cols * $sw ) + ( ( $cols - 1 ) * $min_gap );
				if ( $need_w > $usable_w ) {
					$sw = max( 24, (int) floor( ( $usable_w - ( ( $cols - 1 ) * $min_gap ) ) / $cols ) );
				}
				$need_h = ( $rows * $sh ) + ( ( $rows - 1 ) * $min_gap );
				if ( $need_h > $usable_h ) {
					$sh = max( 18, (int) floor( ( $usable_h - ( ( $rows - 1 ) * $min_gap ) ) / $rows ) );
				}

				// Leftover → gaps; if gaps huge, grow items modestly.
				$gapx = ( $cols > 1 )
					? max( $min_gap, (int) floor( ( $usable_w - ( $cols * $sw ) ) / ( $cols - 1 ) ) )
					: 0;
				$gapy = ( $rows > 1 )
					? max( $min_gap, (int) floor( ( $usable_h - ( $rows * $sh ) ) / ( $rows - 1 ) ) )
					: 0;
				if ( $gapx > 56 && $cols > 1 ) {
					$extra = $gapx - 40;
					$sw    = min( $max_sw, $sw + (int) floor( ( $extra * ( $cols - 1 ) ) / $cols ) );
					$gapx  = max( $min_gap, (int) floor( ( $usable_w - ( $cols * $sw ) ) / ( $cols - 1 ) ) );
				}
				if ( $gapy > 56 && $rows > 1 ) {
					$extra = $gapy - 40;
					$sh    = min( $max_sh, $sh + (int) floor( ( $extra * ( $rows - 1 ) ) / $rows ) );
					$gapy  = max( $min_gap, (int) floor( ( $usable_h - ( $rows * $sh ) ) / ( $rows - 1 ) ) );
				}
				// Block tall towers after any size changes.
				if ( $sh > (int) round( $sw * 1.25 ) ) {
					$sh   = max( 18, (int) round( $sw * 1.15 ) );
					$gapy = ( $rows > 1 ) ? max( $min_gap, (int) floor( ( $usable_h - ( $rows * $sh ) ) / ( $rows - 1 ) ) ) : 0;
				}
				$gapx = min( $gapx, 48 );
				$gapy = min( $gapy, 48 );
				if ( ( $cols * $sw ) + ( ( $cols - 1 ) * $gapx ) > $usable_w ) {
					$sw   = max( 24, (int) floor( ( $usable_w - ( ( $cols - 1 ) * $min_gap ) ) / $cols ) );
					$gapx = ( $cols > 1 ) ? max( $min_gap, (int) floor( ( $usable_w - ( $cols * $sw ) ) / ( $cols - 1 ) ) ) : 0;
					if ( $sh > (int) round( $sw * 1.25 ) ) {
						$sh   = max( 18, (int) round( $sw * 1.15 ) );
						$gapy = ( $rows > 1 ) ? max( $min_gap, (int) floor( ( $usable_h - ( $rows * $sh ) ) / ( $rows - 1 ) ) ) : 0;
						$gapy = min( $gapy, 48 );
					}
				}
				if ( ( $rows * $sh ) + ( ( $rows - 1 ) * $gapy ) > $usable_h ) {
					$sh   = max( 18, (int) floor( ( $usable_h - ( ( $rows - 1 ) * $min_gap ) ) / $rows ) );
					$gapy = ( $rows > 1 ) ? max( $min_gap, (int) floor( ( $usable_h - ( $rows * $sh ) ) / ( $rows - 1 ) ) ) : 0;
				}
				$grid_w = ( $cols * $sw ) + ( ( $cols - 1 ) * $gapx );
				$grid_h = ( $rows * $sh ) + ( ( $rows - 1 ) * $gapy );
				$ox     = $rx0 + $edge + max( 0, (int) floor( ( $usable_w - $grid_w ) / 2 ) );
				$oy     = $ry0 + $label_pad + max( 0, (int) floor( ( $usable_h - $grid_h ) / 2 ) );
				for ( $i = 0; $i < $st; $i++ ) {
					$code = $letter . ( $i + 1 );
					$col  = $i % $cols;
					$row  = (int) floor( $i / $cols );
					$sx   = $ox + ( $col * ( $sw + $gapx ) );
					$sy   = $oy + ( $row * ( $sh + $gapy ) );
					if ( $tuck_side !== '' ) {
						$tuck_seat( $sx, $sy, $sw, $sh, $code, $tuck_side, $furn );
					}
					$seat( $sx, $sy, $sw, $sh, $code, $radius, 30 );
				}
			};

			if ( $style === 'beauty' ) {
				$sw     = (int) $def['w'];
				$sh     = (int) $def['h'];
				$gap    = (int) $def['gapx'];
				$row_w  = ( $st * $sw ) + ( ( $st - 1 ) * $gap );
				$start_x = $rx0 + max( 16, (int) floor( ( $rw - $row_w ) / 2 ) );
				$counter_y = $ry0 + 36;
				$seat_y    = $counter_y + 40;
				$shape( $start_x - 10, $counter_y, $row_w + 20, 32, 'cnt_' . $ri, $furn, 6, 14 );
				for ( $i = 0; $i < $st; $i++ ) {
					$code = $letter . ( $i + 1 );
					$sx   = $start_x + ( $i * ( $sw + $gap ) );
					$shape( $sx + 18, $counter_y + 5, 35, 20, $code . '_bowl', $furn2, 10, 16 );
					$tuck_seat( $sx, $seat_y, $sw, $sh, $code, 'bottom', $furn );
					$seat( $sx, $seat_y, $sw, $sh, $code, (int) $def['radius'], 30 );
				}
				if ( $rh > 340 ) {
					$bw = min( 150, (int) floor( $rw * 0.38 ) );
					$shape( $rx0 + 16, $ry1 - 44, $bw, 36, 'wait_' . $ri, $furn2, 10, 14 );
				}
			} elseif ( $style === 'massage' ) {
				$sw  = (int) $def['w'];
				$sh  = (int) $def['h'];
				$gap = (int) $def['gapy'];
				$total_h = ( $st * $sh ) + ( ( $st - 1 ) * $gap );
				$fit_col = ( $total_h + 100 ) <= $rh;
				if ( $fit_col ) {
					$start_y = $ry0 + max( 52, (int) floor( ( $rh - $total_h ) / 2 ) );
					$sx      = $rx0 + max( 28, (int) floor( ( $rw - $sw ) / 2 ) );
					for ( $i = 0; $i < $st; $i++ ) {
						$code = $letter . ( $i + 1 );
						$sy   = $start_y + ( $i * ( $sh + $gap ) );
						$shape( $sx - 4, $sy - 4, $sw + 8, $sh + 8, $code . '_pad', $furn2, 10, 11 );
						$tuck_seat( $sx, $sy, $sw, $sh, $code, 'right', $furn );
						$seat( $sx, $sy, $sw, $sh, $code, (int) $def['radius'], 30 );
					}
				} else {
					$fill_grid( $sw, $sh, (int) $def['gapx'], $gap, (int) $def['radius'], 'bottom', false, 0 );
				}
			} elseif ( $style === 'bar' ) {
				$stools = max( 4, min( 12, $st ) );
				$counter_w = min( 44, max( 32, (int) floor( $rw * 0.22 ) ) );
				$counter_h = max( 160, (int) floor( $rh * 0.72 ) );
				$cx        = $rx0 + 14;
				$cy        = $ry0 + max( 40, (int) floor( ( $rh - $counter_h ) / 2 ) );
				$shape( $cx, $cy, $counter_w, $counter_h, 'bar_cnt_' . $ri, $furn2, 6, 14 );
				$shape( $cx + 4, $cy + 10, max( 16, $counter_w - 8 ), 28, 'bar_shelf_' . $ri, $furn, 6, 15 );
				$sw = (int) $def['w'];
				$sh = (int) $def['h'];
				$gap = max( 12, (int) floor( ( $counter_h - 20 - ( $stools * $sh ) ) / max( 1, $stools - 1 ) ) );
				$stack_h = ( $stools * $sh ) + ( ( $stools - 1 ) * $gap );
				$sy0     = $cy + max( 8, (int) floor( ( $counter_h - $stack_h ) / 2 ) );
				$sx      = $cx + $counter_w - 10;
				for ( $i = 0; $i < $stools; $i++ ) {
					$code = $letter . ( $i + 1 );
					$sy   = $sy0 + ( $i * ( $sh + $gap ) );
					$tuck_seat( $sx, $sy, $sw, $sh, $code, 'left', $furn );
					$seat( $sx, $sy, $sw, $sh, $code, (int) $def['radius'], 30 );
				}
			} elseif ( $style === 'meeting' ) {
				$tw_table = min( 160, max( 100, (int) floor( $rw * 0.45 ) ) );
				$th_table = min( 90, max( 60, (int) floor( $rh * 0.28 ) ) );
				$tx       = $rx0 + (int) floor( ( $rw - $tw_table ) / 2 );
				$ty       = $ry0 + (int) floor( ( $rh - $th_table ) / 2 ) - 10;
				$shape( $tx, $ty, $tw_table, $th_table, 'meet_' . $ri, $furn, 10, 14 );
				$sw = (int) $def['w'];
				$sh = (int) $def['h'];
				$mx = $tx + (int) floor( ( $tw_table - $sw ) / 2 );
				$my = $ty + (int) floor( ( $th_table - $sh ) / 2 );
				for ( $i = 0; $i < min( $st, 4 ); $i++ ) {
					$code = $letter . ( $i + 1 );
					$side = array( 'top', 'bottom', 'left', 'right' )[ $i ];
					if ( $side === 'top' ) {
						$shape( $tx + 20 + ( $i * 28 ), $ty - 14, 30, 24, $code . '_ch', $furn2, 8, 12 );
					} elseif ( $side === 'bottom' ) {
						$shape( $tx + 20 + ( ( $i - 1 ) * 28 ), $ty + $th_table - 10, 30, 24, $code . '_ch', $furn2, 8, 12 );
					} elseif ( $side === 'left' ) {
						$shape( $tx - 14, $ty + (int) floor( $th_table / 2 ) - 12, 30, 24, $code . '_ch', $furn2, 8, 12 );
					} else {
						$shape( $tx + $tw_table - 14, $ty + (int) floor( $th_table / 2 ) - 12, 30, 24, $code . '_ch', $furn2, 8, 12 );
					}
				}
				$seat( $mx, $my, $sw, $sh, $letter . '1', (int) $def['radius'], 30 );
				for ( $i = 1; $i < $st; $i++ ) {
					$code = $letter . ( $i + 1 );
					$ang  = ( $i / max( 1, $st ) ) * 2 * M_PI;
					$csx  = (int) round( $tx + ( $tw_table / 2 ) + cos( $ang ) * ( $tw_table / 2 + 8 ) - $sw / 2 );
					$csy  = (int) round( $ty + ( $th_table / 2 ) + sin( $ang ) * ( $th_table / 2 + 8 ) - $sh / 2 );
					$csx  = max( $rx0 + 4, min( $csx, $rx1 - $sw - 4 ) );
					$csy  = max( $ry0 + 28, min( $csy, $ry1 - $sh - 4 ) );
					$tuck_seat( $csx, $csy, $sw, $sh, $code, 'bottom', $furn );
					$seat( $csx, $csy, $sw, $sh, $code, (int) $def['radius'], 30 );
				}
			} elseif ( $style === 'cinema' ) {
				// Rows with a center aisle. Standard seats #c5bedc, premium mid-block #b2a8d2.
				$sw   = (int) $def['w'];
				$sh   = (int) $def['h'];
				$gapx = (int) $def['gapx'];
				$gapy = (int) $def['gapy'];
				$aisle = 28;
				$seat_std = '#c5bedc';
				$seat_vip = '#b2a8d2';
				$cols = max( 2, (int) floor( ( $rw - $aisle + $gapx ) / ( $sw + $gapx ) ) );
				$cols = min( $cols, $st );
				$rows = (int) ceil( $st / $cols );
				$half = (int) floor( $cols / 2 );
				$grid_w = ( $cols * $sw ) + ( ( $cols - 1 ) * $gapx ) + $aisle;
				$grid_h = ( $rows * $sh ) + ( ( $rows - 1 ) * $gapy );
				$ox = $rx0 + max( 8, (int) floor( ( $rw - $grid_w ) / 2 ) );
				$oy = $ry0 + max( 40, (int) floor( ( $rh - $grid_h ) / 2 ) );
				$vip_from = max( 1, (int) floor( $rows * 0.2 ) );
				$vip_to   = max( $vip_from, (int) floor( $rows * 0.75 ) );
				for ( $i = 0; $i < $st; $i++ ) {
					$code = $letter . ( $i + 1 );
					$col  = $i % $cols;
					$row  = (int) floor( $i / $cols );
					$sx   = $ox + ( $col * ( $sw + $gapx ) );
					if ( $col >= $half ) {
						$sx += $aisle;
					}
					$sy   = $oy + ( $row * ( $sh + $gapy ) );
					$fill = ( $row >= $vip_from && $row <= $vip_to ) ? $seat_vip : $seat_std;
					$seat( $sx, $sy, $sw, $sh, $code, (int) $def['radius'], 30, $fill );
				}
			} else {
				// parking | beach | vip | gym | office | coworking | restaurant | clinic | generic
				// Beach / VIP / parking: bookable items only (no chair/table shapes under them).
				$tuck = isset( $def['tuck'] ) ? (string) $def['tuck'] : 'bottom';
				if ( in_array( $style, array( 'beach', 'vip', 'parking', 'gym' ), true ) ) {
					$tuck = '';
				}
				$fill_grid(
					(int) $def['w'],
					(int) $def['h'],
					(int) $def['gapx'],
					(int) $def['gapy'],
					(int) $def['radius'],
					$tuck,
					true,
					$force_cols
				);
			}
		}

		// Stable paint order: low z first.
		usort(
			$elements,
			static function ( $a, $b ) {
				$za = isset( $a['z_index'] ) ? (int) $a['z_index'] : 0;
				$zb = isset( $b['z_index'] ) ? (int) $b['z_index'] : 0;
				if ( $za === $zb ) {
					return 0;
				}
				return ( $za < $zb ) ? -1 : 1;
			}
		);
		foreach ( $elements as $k => $el ) {
			$elements[ $k ]['z_index'] = 10 + (int) $k;
		}

		return $elements;
	}

	/**
	 * Whether freeform AI geometry is too broken to keep.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return bool
	 */
	public static function layout_quality_is_poor( array $elements ) {
		$walls = 0;
		$thick = 0;
		$seats = 0;
		foreach ( $elements as $el ) {
			$class = isset( $el['class'] ) ? (string) $el['class'] : '';
			$w     = isset( $el['x1'], $el['x'] ) ? abs( floatval( $el['x1'] ) - floatval( $el['x'] ) ) : 0;
			$h     = isset( $el['y1'], $el['y'] ) ? abs( floatval( $el['y1'] ) - floatval( $el['y'] ) ) : 0;
			if ( $class === 'type-wall' ) {
				$walls++;
				if ( min( $w, $h ) > 18 ) {
					$thick++;
				}
			}
			if ( in_array( $class, array( 'type-1', 'type-4', 'type-6' ), true ) ) {
				$seats++;
			}
			// Fat bars posing as walls.
			if ( $class === 'type-shape' && max( $w, $h ) > 200 && min( $w, $h ) > 24 && min( $w, $h ) < 120 ) {
				$thick++;
			}
		}
		if ( $seats < 1 ) {
			return true;
		}
		if ( $walls < 3 ) {
			return true;
		}
		if ( $thick >= 1 ) {
			return true;
		}
		return false;
	}

	/**
	 * Force wall-like bars to exactly 10px type-wall segments.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalize_wall_thickness( array $elements ) {
		$out = array();
		$t   = 10.0;
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$class = isset( $el['class'] ) ? (string) $el['class'] : '';
			$x     = floatval( $el['x'] ?? 0 );
			$y     = floatval( $el['y'] ?? 0 );
			$x1    = floatval( $el['x1'] ?? ( $x + 10 ) );
			$y1    = floatval( $el['y1'] ?? ( $y + 10 ) );
			if ( $x1 < $x ) {
				$tmp = $x;
				$x   = $x1;
				$x1  = $tmp;
			}
			if ( $y1 < $y ) {
				$tmp = $y;
				$y   = $y1;
				$y1  = $tmp;
			}
			$w = $x1 - $x;
			$h = $y1 - $y;

			$is_wallish = ( $class === 'type-wall' );
			// Thick elongated shapes → walls (the "жесть" pale columns).
			if ( $class === 'type-shape' && max( $w, $h ) >= 120 && min( $w, $h ) >= 20 && min( $w, $h ) <= 110 ) {
				$is_wallish = true;
			}

			if ( $is_wallish ) {
				if ( $w >= $h ) {
					// horizontal bar — keep length, center 10px height
					$cy = ( $y + $y1 ) / 2;
					$el['y']     = $cy - ( $t / 2 );
					$el['y1']    = $cy + ( $t / 2 );
					$el['x']     = $x;
					$el['x1']    = $x1;
				} else {
					$cx = ( $x + $x1 ) / 2;
					$el['x']     = $cx - ( $t / 2 );
					$el['x1']    = $cx + ( $t / 2 );
					$el['y']     = $y;
					$el['y1']    = $y1;
				}
				$el['class']     = 'type-wall';
				$el['color']     = '#c5d4cc';
				$el['seats']     = '0';
				$el['max_seats'] = '0';
				$el['show_id']   = '0';
				$el['opacity']   = '1';
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Whether a shape is a forbidden "room wash" / floor plate (should become walls or be dropped).
	 *
	 * @param float $w Width.
	 * @param float $h Height.
	 * @param int   $canvas_w Canvas width.
	 * @param int   $canvas_h Canvas height.
	 * @return bool
	 */
	public static function is_room_background_shape( $w, $h, $canvas_w = 0, $canvas_h = 0 ) {
		$w = abs( (float) $w );
		$h = abs( (float) $h );
		if ( $w <= 14 || $h <= 14 ) {
			return false; // thin bar — ok as divider-like shape
		}
		// Large plate used as a "room fill".
		if ( $w >= 140 && $h >= 140 ) {
			return true;
		}
		if ( $canvas_w > 0 && $canvas_h > 0 ) {
			$area_ratio = ( $w * $h ) / max( 1, $canvas_w * $canvas_h );
			if ( $area_ratio >= 0.12 && $w >= 120 && $h >= 120 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert a room-fill rectangle into four type-wall segments (10px thick).
	 *
	 * @param array<string, mixed> $el Room shape element.
	 * @param int                  $start_i Index seed for unique ids.
	 * @return array<int, array<string, mixed>>
	 */
	public static function walls_from_room_rect( array $el, $start_i = 0 ) {
		$x  = floatval( $el['x'] ?? 0 );
		$y  = floatval( $el['y'] ?? 0 );
		$x1 = floatval( $el['x1'] ?? ( $x + 100 ) );
		$y1 = floatval( $el['y1'] ?? ( $y + 100 ) );
		if ( $x1 < $x ) {
			$t = $x;
			$x = $x1;
			$x1 = $t;
		}
		if ( $y1 < $y ) {
			$t = $y;
			$y = $y1;
			$y1 = $t;
		}
		$t    = 10.0;
		$uid  = isset( $el['unique'] ) ? preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $el['unique'] ) : ( 'ai_room_' . (int) $start_i );
		$base = array(
			'class'         => 'type-wall',
			'seats'         => '0',
			'max_seats'     => '0',
			'show_id'       => '0',
			'color'         => '#c5d4cc',
			'opacity'       => '1',
			'id'            => '0',
			'shape_type'    => 'rectangle',
			'shape_radius'  => '',
			'square_radius' => '',
		);
		$walls = array(
			array_merge( $base, array( 'x' => $x, 'y' => $y, 'x1' => $x + $t, 'y1' => $y1, 'unique' => $uid . '_wl' ) ),
			array_merge( $base, array( 'x' => $x, 'y' => $y, 'x1' => $x1, 'y1' => $y + $t, 'unique' => $uid . '_wt' ) ),
			array_merge( $base, array( 'x' => $x1 - $t, 'y' => $y, 'x1' => $x1, 'y1' => $y1, 'unique' => $uid . '_wr' ) ),
			array_merge( $base, array( 'x' => $x, 'y' => $y1 - $t, 'x1' => $x1, 'y1' => $y1, 'unique' => $uid . '_wb' ) ),
		);
		return $walls;
	}

	/**
	 * Ensure at least an outer wall frame exists.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param int                              $canvas_w Width.
	 * @param int                              $canvas_h Height.
	 * @return array<int, array<string, mixed>>
	 */
	public static function ensure_outer_walls( array $elements, $canvas_w, $canvas_h ) {
		$has_wall = false;
		foreach ( $elements as $el ) {
			if ( isset( $el['class'] ) && (string) $el['class'] === 'type-wall' ) {
				$has_wall = true;
				break;
			}
		}
		if ( $has_wall ) {
			return self::maybe_add_room_divider( $elements, $canvas_w, $canvas_h );
		}
		$m = 40;
		$w = max( 200, (int) $canvas_w );
		$h = max( 200, (int) $canvas_h );
		$frame = array(
			'x'      => $m,
			'y'      => $m,
			'x1'     => $w - $m,
			'y1'     => $h - $m,
			'unique' => 'ai_outer_frame',
		);
		$elements = array_merge( self::walls_from_room_rect( $frame, 0 ), $elements );
		return self::maybe_add_room_divider( $elements, $canvas_w, $canvas_h );
	}

	/**
	 * If bookables clearly split left/right and no tall interior wall, add a divider.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param int                              $canvas_w Width.
	 * @param int                              $canvas_h Height.
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_add_room_divider( array $elements, $canvas_w, $canvas_h ) {
		$bookable = array( 'type-1', 'type-4', 'type-6' );
		$xs       = array();
		$min_y    = null;
		$max_y    = null;
		foreach ( $elements as $el ) {
			if ( ! isset( $el['class'] ) || ! in_array( (string) $el['class'], $bookable, true ) ) {
				continue;
			}
			$cx   = ( floatval( $el['x'] ) + floatval( $el['x1'] ) ) / 2;
			$xs[] = $cx;
			$y0   = floatval( $el['y'] );
			$y1   = floatval( $el['y1'] );
			$min_y = ( $min_y === null ) ? $y0 : min( $min_y, $y0 );
			$max_y = ( $max_y === null ) ? $y1 : max( $max_y, $y1 );
		}
		if ( count( $xs ) < 2 || $min_y === null || $max_y === null ) {
			return $elements;
		}
		sort( $xs );
		$mid   = ( $xs[0] + $xs[ count( $xs ) - 1 ] ) / 2;
		$left  = 0;
		$right = 0;
		foreach ( $xs as $cx ) {
			if ( $cx < $mid - 40 ) {
				$left++;
			} elseif ( $cx > $mid + 40 ) {
				$right++;
			}
		}
		if ( $left < 1 || $right < 1 ) {
			return $elements;
		}
		// Gap between left cluster max and right cluster min.
		$left_max  = null;
		$right_min = null;
		foreach ( $xs as $cx ) {
			if ( $cx < $mid ) {
				$left_max = ( $left_max === null ) ? $cx : max( $left_max, $cx );
			} else {
				$right_min = ( $right_min === null ) ? $cx : min( $right_min, $cx );
			}
		}
		if ( $left_max === null || $right_min === null || ( $right_min - $left_max ) < 50 ) {
			return $elements;
		}
		$div_x = ( $left_max + $right_min ) / 2;
		// Already have a tall vertical wall near this x?
		foreach ( $elements as $el ) {
			if ( ! isset( $el['class'] ) || (string) $el['class'] !== 'type-wall' ) {
				continue;
			}
			$w = abs( floatval( $el['x1'] ) - floatval( $el['x'] ) );
			$h = abs( floatval( $el['y1'] ) - floatval( $el['y'] ) );
			$cx = ( floatval( $el['x'] ) + floatval( $el['x1'] ) ) / 2;
			if ( $w <= 16 && $h >= 120 && abs( $cx - $div_x ) < 40 ) {
				return $elements;
			}
		}
		$pad = 24;
		$t   = 10;
		$y0  = max( 40, $min_y - $pad );
		$y1  = min( (float) $canvas_h - 40, $max_y + $pad );
		if ( $y1 - $y0 < 100 ) {
			$y0 = 40;
			$y1 = max( 200, (float) $canvas_h - 40 );
		}
		$elements[] = array(
			'x'         => $div_x - ( $t / 2 ),
			'y'         => $y0,
			'x1'        => $div_x + ( $t / 2 ),
			'y1'        => $y1,
			'class'     => 'type-wall',
			'unique'    => 'ai_divider_v',
			'id'        => '0',
			'seats'     => '0',
			'max_seats' => '0',
			'show_id'   => '0',
			'color'     => '#c5d4cc',
			'opacity'   => '1',
		);
		return $elements;
	}

	/**
	 * Force Coworking-style muted colors; drop/convert bad room fills.
	 *
	 * @param array<string, mixed> $el        Normalized element.
	 * @param int                  $canvas_w  Canvas width.
	 * @param int                  $canvas_h  Canvas height.
	 * @return array<string, mixed>|null Null = drop element.
	 */
	public static function apply_layout_style_defaults( array $el, $canvas_w = 0, $canvas_h = 0 ) {
		$class    = isset( $el['class'] ) ? (string) $el['class'] : '';
		$bookable = array( 'type-1', 'type-4', 'type-6' );

		if ( in_array( $class, $bookable, true ) ) {
			$el['color']   = '#7ebfa0';
			$el['opacity'] = '1';
			$el['show_id'] = isset( $el['show_id'] ) && (string) $el['show_id'] !== '' ? (string) $el['show_id'] : '1';
			if ( $class === 'type-1' && ( empty( $el['square_radius'] ) || (string) $el['square_radius'] === '' ) ) {
				$el['square_radius'] = '10';
			}
			if ( ! empty( $el['label'] ) && is_string( $el['label'] ) ) {
				$label = trim( $el['label'] );
				if ( function_exists( 'mb_strlen' ) ? mb_strlen( $label ) > 18 : strlen( $label ) > 18 ) {
					if ( ! empty( $el['custom_id'] ) ) {
						$el['label'] = (string) $el['custom_id'];
					} elseif ( ! empty( $el['id'] ) && ! is_numeric( $el['id'] ) ) {
						$el['label'] = (string) $el['id'];
					}
				}
			}
		} elseif ( $class === 'type-wall' ) {
			$el['color']     = '#c5d4cc';
			$el['opacity']   = '1';
			$el['show_id']   = '0';
			$el['seats']     = '0';
			$el['max_seats'] = '0';
		} elseif ( $class === 'type-shape' ) {
			$el['opacity']            = '1';
			$el['shape_border_width'] = '0';
			$el['show_id']            = '0';
			$el['seats']              = '0';
			$el['max_seats']          = '0';
			$w = isset( $el['x1'], $el['x'] ) ? abs( floatval( $el['x1'] ) - floatval( $el['x'] ) ) : 0;
			$h = isset( $el['y1'], $el['y'] ) ? abs( floatval( $el['y1'] ) - floatval( $el['y'] ) ) : 0;
			if ( self::is_room_background_shape( $w, $h, $canvas_w, $canvas_h ) ) {
				// Caller converts to walls; mark for drop.
				$el['_picky_convert_to_walls'] = 1;
				return $el;
			}
			if ( $w <= 14 || $h <= 14 ) {
				// Thin decorative bar → prefer wall semantics.
				$el['class']     = 'type-wall';
				$el['color']     = '#c5d4cc';
				$el['shape_type'] = 'rectangle';
			} else {
				// Cap furniture size; oversized → drop.
				if ( $w > 220 || $h > 140 ) {
					return null;
				}
				$color = isset( $el['color'] ) ? strtolower( (string) $el['color'] ) : '';
				if ( $color === '' || self::is_loud_hex( $color ) ) {
					$el['color'] = '#dde8e2';
				}
				if ( empty( $el['shape_type'] ) ) {
					$el['shape_type'] = 'rounded';
				}
				if ( empty( $el['shape_radius'] ) ) {
					$el['shape_radius'] = '8';
				}
			}
		} elseif ( $class === 'type-text' ) {
			$el['show_id']     = '0';
			$el['seats']       = '0';
			$el['max_seats']   = '0';
			$el['text_color']  = '#999999';
			$el['font_weight'] = ! empty( $el['font_weight'] ) ? (string) $el['font_weight'] : '700';
			if ( empty( $el['font_size'] ) ) {
				$el['font_size'] = '12';
			}
		}

		return $el;
	}

	/**
	 * Post-process AI elements: room fills → walls, drop floor washes, ensure frame.
	 *
	 * @param array<int, array<string, mixed>> $elements Normalized elements.
	 * @param int                              $canvas_w Canvas width.
	 * @param int                              $canvas_h Canvas height.
	 * @return array<int, array<string, mixed>>
	 */
	public static function polish_layout_elements( array $elements, $canvas_w, $canvas_h ) {
		$out = array();
		$i   = 0;
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$styled = self::apply_layout_style_defaults( $el, $canvas_w, $canvas_h );
			if ( $styled === null ) {
				continue;
			}
			if ( ! empty( $styled['_picky_convert_to_walls'] ) ) {
				unset( $styled['_picky_convert_to_walls'] );
				foreach ( self::walls_from_room_rect( $styled, $i ) as $wall ) {
					$wall['z_index'] = 10 + $i;
					$out[]           = $wall;
					$i++;
				}
				continue;
			}
			$styled['z_index'] = 10 + $i;
			$out[]             = $styled;
			$i++;
		}
		$out = self::ensure_outer_walls( $out, $canvas_w, $canvas_h );
		$out = self::normalize_wall_thickness( $out );
		$out = self::strip_weak_layout_noise( $out );
		$out = self::reshape_bookable_sizes( $out, $canvas_w );
		// Skip enrich_sparse_stations for freeform — it made messy overlaps; builder handles furniture.
		foreach ( $out as $k => $el ) {
			$out[ $k ]['z_index'] = 10 + (int) $k;
		}
		return $out;
	}

	/**
	 * Differentiate salon chairs vs elongated massage tables when the model made them identical.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param int                              $canvas_w Canvas width.
	 * @return array<int, array<string, mixed>>
	 */
	public static function reshape_bookable_sizes( array $elements, $canvas_w = 1000 ) {
		$zone_hint = '';
		$max_x     = (float) $canvas_w;
		foreach ( $elements as $el ) {
			if ( isset( $el['class'] ) && (string) $el['class'] === 'type-text' && ! empty( $el['text'] ) ) {
				$zone_hint .= ' ' . strtolower( (string) $el['text'] );
			}
			if ( isset( $el['x1'] ) ) {
				$max_x = max( $max_x, floatval( $el['x1'] ) );
			}
		}
		$want_massage = (bool) preg_match( '/massag|массаж|spa|table|стол/', $zone_hint );

		foreach ( $elements as $k => $el ) {
			$class = isset( $el['class'] ) ? (string) $el['class'] : '';
			if ( $class !== 'type-1' ) {
				continue;
			}
			$x  = floatval( $el['x'] );
			$y  = floatval( $el['y'] );
			$x1 = floatval( $el['x1'] );
			$y1 = floatval( $el['y1'] );
			$w  = abs( $x1 - $x );
			$h  = abs( $y1 - $y );
			$code = '';
			foreach ( array( 'custom_id', 'label', 'id' ) as $key ) {
				if ( ! empty( $el[ $key ] ) && is_string( $el[ $key ] ) && preg_match( '/^[A-Za-z]\d{1,2}$/', trim( $el[ $key ] ) ) ) {
					$code = strtoupper( trim( $el[ $key ] ) );
					break;
				}
			}
			$letter = $code !== '' ? substr( $code, 0, 1 ) : '';

			// B+ codes → massage tables; A stays chair-sized.
			$make_table = ( $letter !== '' && $letter >= 'B' && $letter <= 'Z' );
			if ( ! $make_table && $want_massage && $letter === '' ) {
				$cx_all     = ( $x + $x1 ) / 2;
				$make_table = $cx_all > ( $max_x * 0.48 );
			}
			if ( $letter === 'A' ) {
				$make_table = false;
			}
			if ( $make_table && $w < 100 && $h > 0 && ( $w / $h ) < 1.6 ) {
				$nw = 110;
				$nh = 48;
				$cx = ( $x + $x1 ) / 2;
				$cy = ( $y + $y1 ) / 2;
				$elements[ $k ]['x']             = $cx - ( $nw / 2 );
				$elements[ $k ]['y']             = $cy - ( $nh / 2 );
				$elements[ $k ]['x1']            = $cx + ( $nw / 2 );
				$elements[ $k ]['y1']            = $cy + ( $nh / 2 );
				$elements[ $k ]['square_radius'] = '8';
			} elseif ( ! $make_table && ( $w < 60 || $h < 45 ) ) {
				$nw = 72;
				$nh = 56;
				$cx = ( $x + $x1 ) / 2;
				$cy = ( $y + $y1 ) / 2;
				$elements[ $k ]['x']             = $cx - ( $nw / 2 );
				$elements[ $k ]['y']             = $cy - ( $nh / 2 );
				$elements[ $k ]['x1']            = $cx + ( $nw / 2 );
				$elements[ $k ]['y1']            = $cy + ( $nh / 2 );
				$elements[ $k ]['square_radius'] = '10';
			}
		}
		return $elements;
	}

	/**
	 * Drop duplicate seat-code texts and tiny junk shapes.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return array<int, array<string, mixed>>
	 */
	public static function strip_weak_layout_noise( array $elements ) {
		$seat_codes = array();
		foreach ( $elements as $el ) {
			$class = isset( $el['class'] ) ? (string) $el['class'] : '';
			if ( ! in_array( $class, array( 'type-1', 'type-4', 'type-6' ), true ) ) {
				continue;
			}
			foreach ( array( 'label', 'custom_id', 'id' ) as $key ) {
				if ( empty( $el[ $key ] ) || ! is_string( $el[ $key ] ) ) {
					continue;
				}
				$code = strtoupper( trim( (string) $el[ $key ] ) );
				if ( preg_match( '/^[A-Z]\d{1,2}$/', $code ) ) {
					$seat_codes[ $code ] = true;
				}
			}
		}

		$out = array();
		foreach ( $elements as $el ) {
			$class = isset( $el['class'] ) ? (string) $el['class'] : '';
			$w     = isset( $el['x1'], $el['x'] ) ? abs( floatval( $el['x1'] ) - floatval( $el['x'] ) ) : 0;
			$h     = isset( $el['y1'], $el['y'] ) ? abs( floatval( $el['y1'] ) - floatval( $el['y'] ) ) : 0;

			if ( $class === 'type-text' ) {
				$text = isset( $el['text'] ) ? strtoupper( trim( (string) $el['text'] ) ) : '';
				if ( $text !== '' && isset( $seat_codes[ $text ] ) ) {
					continue; // duplicate "A1" under the seat
				}
				if ( preg_match( '/^[A-Z]\d{1,2}$/', $text ) ) {
					continue;
				}
			}

			if ( $class === 'type-shape' && $w > 0 && $h > 0 && $w < 18 && $h < 18 ) {
				continue; // orphan dots / junk
			}

			$out[] = $el;
		}
		return $out;
	}

	/**
	 * If the model left empty stations, add light furniture near each bookable.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrich_sparse_stations( array $elements ) {
		$shapes = 0;
		$seats  = array();
		foreach ( $elements as $el ) {
			$class = isset( $el['class'] ) ? (string) $el['class'] : '';
			if ( $class === 'type-shape' ) {
				$shapes++;
			}
			if ( in_array( $class, array( 'type-1', 'type-4', 'type-6' ), true ) ) {
				$seats[] = $el;
			}
		}
		// Already furnished enough.
		if ( $shapes >= max( 4, (int) floor( count( $seats ) * 0.8 ) ) || count( $seats ) === 0 ) {
			return $elements;
		}

		$extra = array();
		$n     = 0;
		foreach ( $seats as $seat ) {
			$x  = floatval( $seat['x'] );
			$y  = floatval( $seat['y'] );
			$x1 = floatval( $seat['x1'] );
			$y1 = floatval( $seat['y1'] );
			$w  = abs( $x1 - $x );
			$h  = abs( $y1 - $y );
			$uid = isset( $seat['unique'] ) ? preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $seat['unique'] ) : ( 'seat_' . $n );
			$n++;

			// Wide/long → massage underlay + side stool; else beauty counter bowl + chair stub.
			$is_table = ( $w >= 95 || ( $w / max( 1.0, $h ) ) >= 1.7 );
			if ( $is_table ) {
				$extra[] = array(
					'x'                  => $x - 8,
					'y'                  => $y - 6,
					'x1'                 => $x1 + 8,
					'y1'                 => $y1 + 6,
					'class'              => 'type-shape',
					'unique'             => $uid . '_pad',
					'id'                 => '0',
					'seats'              => '0',
					'max_seats'          => '0',
					'show_id'            => '0',
					'color'              => '#b4c7bd',
					'opacity'            => '1',
					'shape_type'         => 'rounded',
					'shape_radius'       => '10',
					'shape_border_width' => '0',
					'z_index'            => 5,
				);
				$extra[] = array(
					'x'                  => $x1 + 12,
					'y'                  => $y + max( 4, ( $h - 22 ) / 2 ),
					'x1'                 => $x1 + 40,
					'y1'                 => $y + max( 4, ( $h - 22 ) / 2 ) + 22,
					'class'              => 'type-shape',
					'unique'             => $uid . '_stool',
					'id'                 => '0',
					'seats'              => '0',
					'max_seats'          => '0',
					'show_id'            => '0',
					'color'              => '#dde8e2',
					'opacity'            => '1',
					'shape_type'         => 'rounded',
					'shape_radius'       => '8',
					'shape_border_width' => '0',
					'z_index'            => 6,
				);
			} else {
				$extra[] = array(
					'x'                  => $x + 18,
					'y'                  => max( 0, $y - 28 ),
					'x1'                 => $x + 18 + 35,
					'y1'                 => max( 0, $y - 28 ) + 20,
					'class'              => 'type-shape',
					'unique'             => $uid . '_bowl',
					'id'                 => '0',
					'seats'              => '0',
					'max_seats'          => '0',
					'show_id'            => '0',
					'color'              => '#b4c7bd',
					'opacity'            => '1',
					'shape_type'         => 'rounded',
					'shape_radius'       => '10',
					'shape_border_width' => '0',
					'z_index'            => 6,
				);
				$extra[] = array(
					'x'                  => $x + max( 8, ( $w - 34 ) / 2 ),
					'y'                  => $y1 + 10,
					'x1'                 => $x + max( 8, ( $w - 34 ) / 2 ) + 34,
					'y1'                 => $y1 + 32,
					'class'              => 'type-shape',
					'unique'             => $uid . '_chair',
					'id'                 => '0',
					'seats'              => '0',
					'max_seats'          => '0',
					'show_id'            => '0',
					'color'              => '#dde8e2',
					'opacity'            => '1',
					'shape_type'         => 'rounded',
					'shape_radius'       => '8',
					'shape_border_width' => '0',
					'z_index'            => 6,
				);
			}
		}

		// One shared counter per left/right cluster if still sparse.
		if ( count( $seats ) >= 2 ) {
			$xs = array_map(
				static function ( $s ) {
					return ( floatval( $s['x'] ) + floatval( $s['x1'] ) ) / 2;
				},
				$seats
			);
			sort( $xs );
			$mid = ( $xs[0] + $xs[ count( $xs ) - 1 ] ) / 2;
			foreach ( array( 'L', 'R' ) as $side ) {
				$cluster = array();
				foreach ( $seats as $seat ) {
					$cx = ( floatval( $seat['x'] ) + floatval( $seat['x1'] ) ) / 2;
					if ( $side === 'L' && $cx <= $mid ) {
						$cluster[] = $seat;
					}
					if ( $side === 'R' && $cx > $mid ) {
						$cluster[] = $seat;
					}
				}
				if ( count( $cluster ) < 1 ) {
					continue;
				}
				$min_x = null;
				$max_x = null;
				$min_y = null;
				foreach ( $cluster as $seat ) {
					$min_x = ( $min_x === null ) ? floatval( $seat['x'] ) : min( $min_x, floatval( $seat['x'] ) );
					$max_x = ( $max_x === null ) ? floatval( $seat['x1'] ) : max( $max_x, floatval( $seat['x1'] ) );
					$min_y = ( $min_y === null ) ? floatval( $seat['y'] ) : min( $min_y, floatval( $seat['y'] ) );
				}
				$extra[] = array(
					'x'                  => $min_x,
					'y'                  => max( 40, $min_y - 44 ),
					'x1'                 => $max_x,
					'y1'                 => max( 40, $min_y - 44 ) + 32,
					'class'              => 'type-shape',
					'unique'             => 'ai_counter_' . strtolower( $side ),
					'id'                 => '0',
					'seats'              => '0',
					'max_seats'          => '0',
					'show_id'            => '0',
					'color'              => '#dde8e2',
					'opacity'            => '1',
					'shape_type'         => 'rounded',
					'shape_radius'       => '6',
					'shape_border_width' => '0',
					'z_index'            => 4,
				);
			}
		}

		return array_merge( $extra, $elements );
	}

	/**
	 * Detect saturated / primary-ish hex that breaks the muted plan look.
	 *
	 * @param string $hex Color.
	 * @return bool
	 */
	private static function is_loud_hex( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
			return true;
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		// High chroma = loud.
		return ( $max - $min ) > 90 && $max > 160;
	}

	/**
	 * @param array<string, mixed>|null $options Plugin settings or null to load.
	 * @return bool
	 */
	public static function is_configured( $options = null ) {
		$options = is_array( $options ) ? $options : Info::get_settings();
		// API key is enough — the enable checkbox is auto-synced when a key is saved.
		return self::get_api_key( $options ) !== '';
	}

	/**
	 * Status payload for the AI Layout modal / localize.
	 *
	 * @param array<string, mixed>|null $options Plugin settings or null to load.
	 * @return array{ai_enabled:int,ai_status:string,ai_provider:string,statusReadyText:string}
	 */
	public static function status_payload( $options = null ) {
		$options  = is_array( $options ) ? $options : Info::get_settings();
		$has_key  = self::get_api_key( $options ) !== '';
		$flag_on  = self::is_enabled( $options );
		$provider = self::get_provider( $options );
		$ready    = $has_key;

		if ( $ready ) {
			$status = 'ready';
		} elseif ( ! $flag_on && ! $has_key ) {
			$status = 'setup';
		} elseif ( ! $has_key ) {
			$status = 'no_key';
		} else {
			$status = 'setup';
		}

		$ready_text = sprintf(
			/* translators: %s: provider label */
			__( '%s is configured. Groq free tier has rate limits; OpenAI usage is billed to your account.', 'pickybooking' ),
			self::provider_label( $options )
		);

		return array(
			'ai_enabled'      => $ready ? 1 : 0,
			'ai_status'       => $status,
			'ai_provider'     => $provider,
			'statusReadyText' => $ready_text,
		);
	}

	/**
	 * Human label for the active provider (status banners).
	 *
	 * @param array<string, mixed>|null $options Plugin settings or null to load.
	 * @return string
	 */
	public static function provider_label( $options = null ) {
		$provider = self::get_provider( $options );
		$labels   = self::providers();
		return isset( $labels[ $provider ] ) ? $labels[ $provider ] : $provider;
	}

	/**
	 * Lightweight rate limit (per WP user).
	 *
	 * @return true|\WP_Error
	 */
	public static function check_rate_limit() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'picky_ai_auth', __( 'You must be logged in.', 'pickybooking' ) );
		}
		$key   = 'picky_ai_rate_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_MAX ) {
			return new \WP_Error(
				'picky_ai_rate',
				__( 'AI rate limit reached. Please wait a few minutes and try again.', 'pickybooking' )
			);
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	/**
	 * Test that the stored (or provided) key can call the active provider.
	 *
	 * @param string $api_key  Optional key from the settings form (may be blank = use stored).
	 * @param string $provider Optional provider override from the form.
	 * @param string $model    Optional model override from the form.
	 * @return true|\WP_Error
	 */
	public static function test_connection( $api_key = '', $provider = '', $model = '' ) {
		$options = Info::get_settings();
		if ( $provider !== '' ) {
			$provider = self::normalize_provider( $provider );
		} else {
			$provider = self::get_provider( $options );
		}
		$key = trim( (string) $api_key );
		if ( $key === '' ) {
			if ( $provider === 'openai' ) {
				$key = isset( $options['ai_openai_key'] ) ? trim( (string) $options['ai_openai_key'] ) : '';
			} else {
				$key = isset( $options['ai_groq_key'] ) ? trim( (string) $options['ai_groq_key'] ) : '';
			}
		}
		if ( $key === '' ) {
			return new \WP_Error(
				'picky_ai_key',
				( $provider === 'openai' )
					? __( 'OpenAI API key is required.', 'pickybooking' )
					: __( 'Groq API key is required.', 'pickybooking' )
			);
		}

		$model = sanitize_text_field( (string) $model );
		if ( $model === '' ) {
			$model = self::get_model( $options );
		} else {
			$model = self::migrate_model_id( $model, $provider );
		}

		$result = self::chat(
			'Reply with the single word: ok',
			'Say ok',
			array(
				'api_key'          => $key,
				'provider'         => $provider,
				'model'            => $model,
				'max_tokens'       => self::is_reasoning_model( $model ) ? 1024 : 32,
				'temperature'      => 0,
				'reasoning_effort' => 'low',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Chat and parse a JSON object/array from the model reply.
	 *
	 * @param string               $system System prompt.
	 * @param string               $user   User prompt.
	 * @param array<string, mixed> $args   Optional overrides (api_key, provider, model, max_tokens, temperature).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function chat_json( $system, $user, $args = array() ) {
		$raw = self::chat( $system, $user, $args );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$decoded = self::extract_json( $raw );
		if ( $decoded === null ) {
			return new \WP_Error( 'picky_ai_json', __( 'AI returned invalid JSON. Please try again.', 'pickybooking' ) );
		}
		return $decoded;
	}

	/**
	 * @param string               $system System prompt.
	 * @param string               $user   User prompt.
	 * @param array<string, mixed> $args   Optional overrides.
	 * @return string|\WP_Error Raw assistant content.
	 */
	public static function chat( $system, $user, $args = array() ) {
		$options  = Info::get_settings();
		$provider = isset( $args['provider'] ) ? self::normalize_provider( (string) $args['provider'] ) : self::get_provider( $options );
		$key      = isset( $args['api_key'] ) ? trim( (string) $args['api_key'] ) : '';
		if ( $key === '' ) {
			if ( $provider === 'openai' ) {
				$key = isset( $options['ai_openai_key'] ) ? trim( (string) $options['ai_openai_key'] ) : '';
			} else {
				$key = isset( $options['ai_groq_key'] ) ? trim( (string) $options['ai_groq_key'] ) : '';
			}
		}
		if ( $key === '' ) {
			return new \WP_Error(
				'picky_ai_key',
				( $provider === 'openai' )
					? __( 'OpenAI API key is not configured.', 'pickybooking' )
					: __( 'Groq API key is not configured.', 'pickybooking' )
			);
		}

		$model       = isset( $args['model'] ) ? sanitize_text_field( (string) $args['model'] ) : self::get_model( $options );
		$model       = self::migrate_model_id( $model, $provider );
		$max_tokens  = isset( $args['max_tokens'] ) ? max( 16, min( 8000, (int) $args['max_tokens'] ) ) : 2500;
		$temperature = isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.4;

		// Reasoning models (GPT-OSS, etc.) spend the completion budget on chain-of-thought first.
		// A tiny max_tokens budget returns HTTP 200 with empty content.
		if ( self::is_reasoning_model( $model ) && $max_tokens < 512 ) {
			$max_tokens = 512;
		}

		$body = array(
			'model'                 => $model,
			'temperature'           => $temperature,
			'max_completion_tokens' => $max_tokens,
			'messages'              => array(
				array(
					'role'    => 'system',
					'content' => (string) $system,
				),
				array(
					'role'    => 'user',
					'content' => (string) $user,
				),
			),
		);

		if ( self::is_reasoning_model( $model ) ) {
			$effort = isset( $args['reasoning_effort'] ) ? sanitize_key( (string) $args['reasoning_effort'] ) : 'low';
			if ( ! in_array( $effort, array( 'low', 'medium', 'high' ), true ) ) {
				$effort = 'low';
			}
			$body['reasoning_effort'] = $effort;
		}

		$response = wp_remote_post(
			self::api_url_for_provider( $provider ),
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = ( $provider === 'openai' )
				? __( 'OpenAI request failed.', 'pickybooking' )
				: __( 'Groq request failed.', 'pickybooking' );
			if ( is_array( $data ) && isset( $data['error']['message'] ) ) {
				$msg = sanitize_text_field( (string) $data['error']['message'] );
			} else {
				$msg .= ' HTTP ' . $code;
			}
			$lower = strtolower( $msg );
			if ( $provider === 'openai'
				&& ( strpos( $lower, 'credit' ) !== false
					|| strpos( $lower, 'billing' ) !== false
					|| strpos( $lower, 'quota' ) !== false
					|| strpos( $lower, 'insufficient' ) !== false ) ) {
				$msg = __( 'OpenAI accepted your API key, but this account has no credits left. Add billing credits at https://platform.openai.com/settings/organization/billing/ — or switch Provider to Groq (free tier).', 'pickybooking' );
			}
			if ( $provider === 'groq' && ( $code === 429 || strpos( $lower, 'rate' ) !== false ) ) {
				$msg = __( 'Groq free-tier rate limit reached. Wait a minute and try again, or switch to Llama 4 Scout.', 'pickybooking' );
			}
			return new \WP_Error( 'picky_ai_http', $msg );
		}

		$content = '';
		if ( is_array( $data ) && isset( $data['choices'][0]['message']['content'] ) ) {
			$raw_content = $data['choices'][0]['message']['content'];
			if ( is_array( $raw_content ) ) {
				$parts = array();
				foreach ( $raw_content as $part ) {
					if ( is_string( $part ) ) {
						$parts[] = $part;
					} elseif ( is_array( $part ) && isset( $part['text'] ) ) {
						$parts[] = (string) $part['text'];
					}
				}
				$content = trim( implode( '', $parts ) );
			} else {
				$content = trim( (string) $raw_content );
			}
		}
		if ( $content === '' && is_array( $data ) && isset( $data['choices'][0]['message']['reasoning'] ) ) {
			$content = trim( (string) $data['choices'][0]['message']['reasoning'] );
		}
		if ( $content === '' ) {
			$finish = is_array( $data ) && isset( $data['choices'][0]['finish_reason'] )
				? (string) $data['choices'][0]['finish_reason']
				: '';
			if ( $finish === 'length' && self::is_reasoning_model( $model ) ) {
				return new \WP_Error(
					'picky_ai_empty',
					__( 'AI used all tokens on internal reasoning. Try again, or switch to Llama 4 Scout.', 'pickybooking' )
				);
			}
			return new \WP_Error( 'picky_ai_empty', __( 'AI returned an empty response.', 'pickybooking' ) );
		}
		return $content;
	}

	/**
	 * Extract first JSON object/array from a model string (strips markdown fences).
	 *
	 * @param string $text Model text.
	 * @return array<string, mixed>|null
	 */
	public static function extract_json( $text ) {
		$text = trim( (string) $text );
		if ( $text === '' ) {
			return null;
		}
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/i', $text, $m ) ) {
			$text = trim( $m[1] );
		}
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		$start_obj = strpos( $text, '{' );
		$start_arr = strpos( $text, '[' );
		$start     = false;
		if ( $start_obj === false ) {
			$start = $start_arr;
		} elseif ( $start_arr === false ) {
			$start = $start_obj;
		} else {
			$start = min( $start_obj, $start_arr );
		}
		if ( $start === false ) {
			return null;
		}
		$slice   = substr( $text, $start );
		$decoded = json_decode( $slice, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Clamp / sanitize a prompt string.
	 *
	 * @param string $prompt Raw prompt.
	 * @param int    $max    Max length.
	 * @return string
	 */
	public static function sanitize_prompt( $prompt, $max = 2000 ) {
		$prompt = wp_strip_all_tags( (string) $prompt );
		$prompt = trim( $prompt );
		$max    = max( 50, (int) $max );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $prompt, 0, $max );
		}
		return substr( $prompt, 0, $max );
	}
}
