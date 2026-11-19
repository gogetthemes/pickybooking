<?php
/**
 * Guided setup checklist (Freemius-style onboarding).
 *
 * Lite and Pro editions use different step trees (no Pro-only steps required in Lite).
 *
 * @package Picky
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects setup progress and renders the Setup tab checklist.
 */
class Setup_Guide {

	const OPTION_CHECKLIST = 'picky_setup_checklist';
	const OPTION_DISMISSED = 'picky_setup_dismissed';

	/**
	 * Soft (manual / settings-save) step keys shared by both editions.
	 *
	 * @return string[]
	 */
	public static function soft_step_ids() {
		$ids = array( 'hours', 'main', 'form' );
		if ( class_exists( __NAMESPACE__ . '\Edition' ) && Edition::is_pro() ) {
			$ids[] = 'payments_review';
			$ids[] = 'sms_review';
			$ids[] = 'integrations_review';
		}
		return $ids;
	}

	/**
	 * Stored checklist flags.
	 *
	 * @return array<string, int>
	 */
	public static function get_checklist() {
		$raw = get_option( self::OPTION_CHECKLIST, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$out = array();
		foreach ( self::soft_step_ids() as $id ) {
			$out[ $id ] = ! empty( $raw[ $id ] ) ? 1 : 0;
		}
		return $out;
	}

	/**
	 * Mark one or more soft steps complete.
	 *
	 * @param string|string[] $ids Step id(s).
	 * @return array<string, int>
	 */
	public static function mark_steps( $ids ) {
		$ids       = array_map( 'sanitize_key', (array) $ids );
		$allowed   = array_flip( self::soft_step_ids() );
		$checklist = self::get_checklist();
		foreach ( $ids as $id ) {
			if ( isset( $allowed[ $id ] ) ) {
				$checklist[ $id ] = 1;
			}
		}
		update_option( self::OPTION_CHECKLIST, $checklist, false );
		return $checklist;
	}

	/**
	 * Hook after options save.
	 *
	 * Soft review steps (hours / main / form / Pro reviews) are completed only via
	 * “Mark as done” in the checklist — never on Save. Layout builder Save also
	 * hits settings_save_validate; auto-completing here made every soft step
	 * strike through after placing a table.
	 *
	 * Auto-detect still covers: layout exists, canvas elements, shortcode page,
	 * first booking.
	 *
	 * @param array<string, mixed>|null $input Unused (kept for call-site compat).
	 */
	public static function on_settings_saved( $input = null ) {
		unset( $input );
	}

	/**
	 * Count layouts.
	 *
	 * @return int
	 */
	public static function layout_count() {
		$q = new \WP_Query(
			array(
				'post_type'              => 'picky_layout',
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Whether any layout has at least one canvas element.
	 *
	 * @return bool
	 */
	public static function has_layout_elements() {
		$ids = get_posts(
			array(
				'post_type'              => 'picky_layout',
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 20,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		if ( empty( $ids ) ) {
			return false;
		}
		foreach ( $ids as $lid ) {
			$raw = get_post_meta( (int) $lid, '_picky_layout_position', true );
			if ( empty( $raw ) ) {
				continue;
			}
			$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			if ( is_array( $data ) && count( $data ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a page/post contains the booking shortcode.
	 *
	 * @return bool
	 */
	public static function has_shortcode_page() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_status IN ('publish','draft','private')
			AND post_type IN ('page','post')
			AND post_content LIKE '%[pickybooking%'
			LIMIT 1"
		);
		return ! empty( $found );
	}

	/**
	 * Whether any booking or order exists.
	 *
	 * @return bool
	 */
	public static function has_first_booking() {
		foreach ( array( 'picky_order', 'picky_booking' ) as $pt ) {
			$q = new \WP_Query(
				array(
					'post_type'              => $pt,
					'post_status'            => array( 'publish', 'pending', 'draft', 'private' ),
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( ! empty( $q->posts ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Settings URL for a tab.
	 *
	 * @param string               $tab Tab index.
	 * @param array<string, mixed> $extra Extra query args.
	 * @return string
	 */
	public static function tab_url( $tab, $extra = array() ) {
		$args = array_merge(
			array(
				'page'      => 'picky_settings',
				'picky-tab' => (string) $tab,
			),
			$extra
		);
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Full step tree with done flags (edition-aware).
	 *
	 * @return array{groups: array<int, array>, done: int, pending: int, total: int}
	 */
	public static function get_progress() {
		$checklist   = self::get_checklist();
		$layout_ok   = self::layout_count() > 0;
		$elements_ok = $layout_ok && self::has_layout_elements();
		$hours_ok    = ! empty( $checklist['hours'] );
		$main_ok     = ! empty( $checklist['main'] );
		$form_ok     = ! empty( $checklist['form'] );
		$short_ok    = self::has_shortcode_page();
		$book_ok     = self::has_first_booking();
		$is_pro      = class_exists( __NAMESPACE__ . '\Edition' ) && Edition::is_pro();

		$get_started_steps = array(
			array(
				'id'          => 'create_layout',
				'number'      => '1',
				'title'       => __( 'Create a floor plan', 'pickybooking' ),
				'description' => $is_pro
					? __( 'Start from a blank canvas or import a ready-made layout from the library.', 'pickybooking' )
					: __( 'Start from a blank canvas or pick a free layout from the library.', 'pickybooking' ),
				'done'        => $layout_ok,
				'action'      => array(
					'label' => __( 'Open Layouts', 'pickybooking' ),
					'url'   => self::tab_url( '9' ),
				),
			),
			array(
				'id'          => 'add_elements',
				'number'      => '2',
				'title'       => __( 'Place bookable items on the map', 'pickybooking' ),
				'description' => $is_pro
					? __( 'Add tables, rooms, seats, and decorative walls/text/shapes in the layout builder.', 'pickybooking' )
					: __( 'Add tables or seats (Square / Circle) in the layout builder so guests have something to pick.', 'pickybooking' ),
				'done'        => $elements_ok,
				'action'      => array(
					'label' => __( 'Open Layout Builder', 'pickybooking' ),
					'url'   => self::tab_url( '9' ),
				),
			),
			array(
				'id'          => 'hours',
				'number'      => '3',
				'title'       => __( 'Set opening hours', 'pickybooking' ),
				'description' => __( 'Choose which weekdays you accept bookings and the hours for each day.', 'pickybooking' ),
				'done'        => $hours_ok,
				'markable'    => true,
				'action'      => array(
					'label' => __( 'Work Days', 'pickybooking' ),
					'url'   => self::tab_url( '2' ),
				),
			),
			array(
				'id'          => 'main',
				'number'      => '4',
				'title'       => __( 'Review main booking rules', 'pickybooking' ),
				'description' => __( 'Set how far ahead guests can book, default duration, and what you call bookable items.', 'pickybooking' ),
				'done'        => $main_ok,
				'markable'    => true,
				'action'      => array(
					'label' => __( 'Main Settings', 'pickybooking' ),
					'url'   => self::tab_url( '1' ),
				),
			),
			array(
				'id'          => 'form',
				'number'      => '5',
				'title'       => __( 'Customize the form and emails', 'pickybooking' ),
				'description' => __( 'Pick which guest fields to collect and tweak confirmation / notification emails.', 'pickybooking' ),
				'done'        => $form_ok,
				'markable'    => true,
				'action'      => array(
					'label' => __( 'Form & Emails', 'pickybooking' ),
					'url'   => self::tab_url( '6' ),
				),
			),
		);

		$groups = array(
			array(
				'id'    => 'get_started',
				'title' => __( 'Get Started', 'pickybooking' ),
				'icon'  => 'icon-check-square',
				'steps' => $get_started_steps,
			),
		);

		if ( $is_pro ) {
			$groups[] = array(
				'id'    => 'pro_features',
				'title' => __( 'Pro Features', 'pickybooking' ),
				'icon'  => 'icon-zap',
				'steps' => array(
					array(
						'id'          => 'payments_review',
						'number'      => '6',
						'title'       => __( 'Set up payments (optional)', 'pickybooking' ),
						'description' => __( 'Require a deposit or full payment via Stripe Checkout or WooCommerce before a booking is confirmed.', 'pickybooking' ),
						'done'        => ! empty( $checklist['payments_review'] ),
						'markable'    => true,
						'action'      => array(
							'label' => __( 'Payments', 'pickybooking' ),
							'url'   => self::tab_url( '5' ),
						),
					),
					array(
						'id'          => 'sms_review',
						'number'      => '7',
						'title'       => __( 'Connect SMS alerts (optional)', 'pickybooking' ),
						'description' => __( 'Send a Vonage SMS to your staff phone when someone books.', 'pickybooking' ),
						'done'        => ! empty( $checklist['sms_review'] ),
						'markable'    => true,
						'action'      => array(
							'label' => __( 'SMS', 'pickybooking' ),
							'url'   => self::tab_url( '3' ),
						),
					),
					array(
						'id'          => 'integrations_review',
						'number'      => '8',
						'title'       => __( 'Connect integrations (optional)', 'pickybooking' ),
						'description' => __( 'Wire HubSpot, Mailchimp, Zapier, Make, n8n, Google Calendar, or AI layout help.', 'pickybooking' ),
						'done'        => ! empty( $checklist['integrations_review'] ),
						'markable'    => true,
						'action'      => array(
							'label' => __( 'Integrations', 'pickybooking' ),
							'url'   => self::tab_url( '12' ),
						),
					),
				),
			);
			$go_live_start = 9;
		} else {
			$groups[] = array(
				'id'    => 'go_pro',
				'title' => __( 'Want more?', 'pickybooking' ),
				'icon'  => 'icon-zap',
				'steps' => array(
					array(
						'id'          => 'explore_pro',
						'number'      => '★',
						'title'       => __( 'Explore PickyBooking Pro', 'pickybooking' ),
						'description' => __( 'Unlock payments, SMS, CRM / webhooks, Smart wizards, decorative builder elements, and layout import/export.', 'pickybooking' ),
						'done'        => false,
						'action'      => array(
							'label' => __( 'Learn about Pro', 'pickybooking' ),
							'url'   => class_exists( __NAMESPACE__ . '\Edition' ) ? Edition::PRO_URL : 'https://pickybooking.com/#pricing',
						),
					),
				),
			);
			$go_live_start = 6;
		}

		$groups[] = array(
			'id'    => 'go_live',
			'title' => __( 'Go Live', 'pickybooking' ),
			'icon'  => 'icon-play',
			'steps' => array(
				array(
					'id'          => 'shortcode',
					'number'      => (string) $go_live_start,
					'title'       => __( 'Add the booking form to a page', 'pickybooking' ),
					'description' => __( 'Copy the shortcode from your layout and paste it into any page or post.', 'pickybooking' ),
					'done'        => $short_ok,
					'action'      => array(
						'label' => __( 'Open Layouts', 'pickybooking' ),
						'url'   => self::tab_url( '9' ),
					),
					'docs'        => array(
						'label' => __( 'Documentation', 'pickybooking' ),
						'url'   => self::tab_url( '11' ),
					),
				),
				array(
					'id'          => 'first_booking',
					'number'      => (string) ( $go_live_start + 1 ),
					'title'       => __( 'Receive your first booking', 'pickybooking' ),
					'description' => __( 'Place a test booking from the front end, then manage it under Bookings.', 'pickybooking' ),
					'done'        => $book_ok,
					'action'      => array(
						'label' => __( 'Open Bookings', 'pickybooking' ),
						'url'   => self::tab_url( '13' ),
					),
				),
			),
		);

		$done  = 0;
		$total = 0;
		self::walk_steps(
			$groups,
			function ( $step ) use ( &$done, &$total ) {
				// Soft upsell row in Lite does not count toward completion.
				if ( ! empty( $step['id'] ) && (string) $step['id'] === 'explore_pro' ) {
					return;
				}
				$total++;
				if ( ! empty( $step['done'] ) ) {
					$done++;
				}
			}
		);

		return array(
			'groups'  => $groups,
			'done'    => $done,
			'pending' => max( 0, $total - $done ),
			'total'   => $total,
		);
	}

	/**
	 * Walk all steps including children.
	 *
	 * @param array    $groups Groups from get_progress().
	 * @param callable $cb     Callback receiving step array.
	 */
	private static function walk_steps( $groups, $cb ) {
		foreach ( $groups as $group ) {
			if ( empty( $group['steps'] ) || ! is_array( $group['steps'] ) ) {
				continue;
			}
			foreach ( $group['steps'] as $step ) {
				call_user_func( $cb, $step );
				if ( ! empty( $step['children'] ) && is_array( $step['children'] ) ) {
					foreach ( $step['children'] as $child ) {
						call_user_func( $cb, $child );
					}
				}
			}
		}
	}

	/**
	 * True when setup should be the default landing tab (no layouts yet).
	 *
	 * @return bool
	 */
	public static function should_land_on_setup() {
		if ( get_option( self::OPTION_DISMISSED, '' ) === '1' ) {
			return false;
		}
		return self::layout_count() < 1;
	}

	/**
	 * Render Setup tab HTML.
	 *
	 * @return string
	 */
	public static function render() {
		$progress = self::get_progress();
		$nonce    = wp_create_nonce( 'picky_setup_guide' );
		$is_pro   = class_exists( __NAMESPACE__ . '\Edition' ) && Edition::is_pro();

		ob_start();
		?>
		<div class="picky-setup" data-picky-setup="1" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<header class="picky-setup__header">
				<h1 class="picky-app-tab-title picky-setup__title"><?php esc_html_e( 'Setup Checklist', 'pickybooking' ); ?></h1>
				<p class="picky-setup__lead">
					<?php
					echo esc_html(
						$is_pro
							? __( 'Follow these steps to create a floor plan, configure Pro features, and start taking bookings.', 'pickybooking' )
							: __( 'Follow these steps to create a floor plan, configure hours, and start taking bookings.', 'pickybooking' )
					);
					?>
				</p>
				<div class="picky-setup__badges" aria-live="polite">
					<span class="picky-setup__badge picky-setup__badge--done">
						<span class="picky-setup__badge-icon" aria-hidden="true">&#10003;</span>
						<span class="picky-setup__badge-count" data-picky-setup-done><?php echo esc_html( (string) (int) $progress['done'] ); ?></span>
						<?php esc_html_e( 'done', 'pickybooking' ); ?>
					</span>
					<span class="picky-setup__badge picky-setup__badge--pending">
						<span class="picky-setup__badge-icon" aria-hidden="true">&#8987;</span>
						<span class="picky-setup__badge-count" data-picky-setup-pending><?php echo esc_html( (string) (int) $progress['pending'] ); ?></span>
						<?php esc_html_e( 'pending', 'pickybooking' ); ?>
					</span>
				</div>
			</header>

			<?php if ( (int) $progress['pending'] === 0 ) : ?>
				<div class="picky-setup__complete">
					<p class="picky-setup__complete-title"><?php esc_html_e( 'You\'re all set', 'pickybooking' ); ?></p>
					<p class="picky-setup__complete-text"><?php esc_html_e( 'Every setup step is complete. You can revisit this checklist anytime under Settings.', 'pickybooking' ); ?></p>
					<p class="picky-setup__complete-actions">
						<a class="button button-primary" href="<?php echo esc_url( self::tab_url( '13' ) ); ?>"><?php esc_html_e( 'Go to Bookings', 'pickybooking' ); ?></a>
						<a class="button" href="<?php echo esc_url( self::tab_url( '9' ) ); ?>"><?php esc_html_e( 'Manage Layouts', 'pickybooking' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php foreach ( $progress['groups'] as $group ) : ?>
				<section class="picky-setup__group">
					<h2 class="picky-setup__group-title">
						<?php if ( ! empty( $group['icon'] ) ) : ?>
							<i class="<?php echo esc_attr( (string) $group['icon'] ); ?>" aria-hidden="true"></i>
						<?php endif; ?>
						<span><?php echo esc_html( (string) $group['title'] ); ?></span>
					</h2>
					<ul class="picky-setup__list">
						<?php
						foreach ( $group['steps'] as $step ) {
							echo self::render_step( $step ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_step.
						}
						?>
					</ul>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one step row (and optional children).
	 *
	 * @param array $step Step data.
	 * @param bool  $child Whether this is a nested step.
	 * @return string
	 */
	private static function render_step( $step, $child = false ) {
		$done      = ! empty( $step['done'] );
		$markable  = ! empty( $step['markable'] ) && ! $done;
		$external  = ! empty( $step['action']['url'] ) && ( strpos( (string) $step['action']['url'], 'http' ) === 0 ) && ( strpos( (string) $step['action']['url'], admin_url() ) !== 0 );
		$li_class  = 'picky-setup__step' . ( $child ? ' picky-setup__step--child' : '' ) . ( $done ? ' is-done' : ' is-pending' );
		$num_class = $child ? 'picky-setup__num picky-setup__num--roman' : 'picky-setup__num';

		ob_start();
		?>
		<li class="<?php echo esc_attr( $li_class ); ?>" data-step-id="<?php echo esc_attr( (string) $step['id'] ); ?>">
			<span class="<?php echo esc_attr( $num_class ); ?>" aria-hidden="true"><?php echo esc_html( (string) $step['number'] ); ?></span>
			<div class="picky-setup__card">
				<div class="picky-setup__card-body">
					<p class="picky-setup__step-title"><?php echo esc_html( (string) $step['title'] ); ?></p>
					<p class="picky-setup__step-desc"><?php echo esc_html( (string) $step['description'] ); ?></p>
					<p class="picky-setup__step-actions">
						<?php if ( ! empty( $step['action']['url'] ) && ! empty( $step['action']['label'] ) ) : ?>
							<a class="picky-setup__link" href="<?php echo esc_url( (string) $step['action']['url'] ); ?>"<?php echo $external ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo esc_html( (string) $step['action']['label'] ); ?> <span aria-hidden="true">&rarr;</span></a>
						<?php endif; ?>
						<?php if ( ! empty( $step['docs']['url'] ) && ! empty( $step['docs']['label'] ) ) : ?>
							<a class="picky-setup__link picky-setup__link--muted" href="<?php echo esc_url( (string) $step['docs']['url'] ); ?>"><?php echo esc_html( (string) $step['docs']['label'] ); ?></a>
						<?php endif; ?>
						<?php if ( $markable ) : ?>
							<button type="button" class="picky-setup__mark button-link" data-picky-setup-mark="<?php echo esc_attr( (string) $step['id'] ); ?>"><?php esc_html_e( 'Mark as done', 'pickybooking' ); ?></button>
						<?php endif; ?>
					</p>
				</div>
				<span class="picky-setup__status" aria-hidden="true">
					<?php if ( $done ) : ?>
						<span class="picky-setup__check">&#10003;</span>
					<?php elseif ( (string) $step['id'] === 'explore_pro' ) : ?>
						<span class="picky-pro-badge"><?php esc_html_e( 'Pro', 'pickybooking' ); ?></span>
					<?php else : ?>
						<span class="picky-setup__pending-dot"></span>
					<?php endif; ?>
				</span>
			</div>
			<?php if ( ! empty( $step['children'] ) && is_array( $step['children'] ) ) : ?>
				<ul class="picky-setup__list picky-setup__list--nested">
					<?php
					foreach ( $step['children'] as $child_step ) {
						echo self::render_step( $child_step, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</ul>
			<?php endif; ?>
		</li>
		<?php
		return (string) ob_get_clean();
	}
}
