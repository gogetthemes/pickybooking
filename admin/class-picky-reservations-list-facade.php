<?php
/**
 * Exposes protected WP_List_Table navigation for the custom reservations UI.
 *
 * @package Picky
 */

namespace Picky;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @internal
 */
class Picky_Reservations_List_Facade extends Picky_Embedded_Posts_List_Table {

	/**
	 * Embed sandbox query (page, picky-tab, post_type, filters) for URL canonicalization.
	 *
	 * @var array<string, mixed>
	 */
	protected $picky_embed_canonical_query = array();

	/**
	 * @var Admin|null
	 */
	protected $picky_embed_admin = null;

	/**
	 * @param array<string, mixed> $query
	 * @return void
	 */
	public function set_picky_embed_canonical_query( array $query ) {
		$this->picky_embed_canonical_query = $query;
	}

	/**
	 * @param Admin $admin
	 * @return void
	 */
	public function set_picky_embed_admin( Admin $admin ) {
		$this->picky_embed_admin = $admin;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Same as core WP_List_Table::search_box(), but the submit is a native `<button type="submit">`
	 * (`button button-secondary`) for clearer styling next to the search field.
	 *
	 * @param string $text     Search label (post type search_items).
	 * @param string $input_id Input id prefix; rendered as "{prefix}-search-input".
	 */
	public function search_box( $text, $input_id ) {
		$query = picky_request_query_bag();
		$search = isset( $query['s'] ) ? $query['s'] : '';
		if ( ( ! is_scalar( $search ) || (string) $search === '' ) && ! $this->has_items() ) {
			return;
		}

		$input_id = $input_id . '-search-input';

		if ( ! empty( $query['orderby'] ) ) {
			if ( is_array( $query['orderby'] ) ) {
				foreach ( $query['orderby'] as $key => $value ) {
					$ob_key = sanitize_key( (string) $key );
					$ob_val = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
					echo '<input type="hidden" name="orderby[' . esc_attr( $ob_key ) . ']" value="' . esc_attr( $ob_val ) . '" />';
				}
			} elseif ( is_scalar( $query['orderby'] ) ) {
				echo '<input type="hidden" name="orderby" value="' . esc_attr( sanitize_text_field( (string) $query['orderby'] ) ) . '" />';
			}
		}
		if ( ! empty( $query['order'] ) && is_scalar( $query['order'] ) ) {
			echo '<input type="hidden" name="order" value="' . esc_attr( sanitize_text_field( (string) $query['order'] ) ) . '" />';
		}
		if ( ! empty( $query['post_mime_type'] ) && is_scalar( $query['post_mime_type'] ) ) {
			echo '<input type="hidden" name="post_mime_type" value="' . esc_attr( sanitize_text_field( (string) $query['post_mime_type'] ) ) . '" />';
		}
		if ( ! empty( $query['detached'] ) && is_scalar( $query['detached'] ) ) {
			echo '<input type="hidden" name="detached" value="' . esc_attr( sanitize_text_field( (string) $query['detached'] ) ) . '" />';
		}
		?>
<p class="search-box">
	<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
	<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php _admin_search_query(); ?>" />
	<button type="submit" id="search-submit" class="button button-secondary picky-list-toolbar-btn picky-list-toolbar-btn--search"><?php echo esc_html( $text ); ?></button>
</p>
		<?php
	}

	/**
	 * {@inheritdoc}
	 *
	 * Embedded lists: same markup as core, but Apply is a `<button type="submit">` with
	 * `button-primary` for a clearer primary action next to the bulk dropdown.
	 *
	 * @param string $which `top` or `bottom`.
	 */
	protected function bulk_actions( $which = '' ) {
		if ( $this->picky_embed_base_url === '' ) {
			parent::bulk_actions( $which );
			return;
		}

		if ( is_null( $this->_actions ) ) {
			$this->_actions = $this->get_bulk_actions();

			/** This filter is documented in wp-admin/includes/class-wp-list-table.php */
			$this->_actions = apply_filters( "bulk_actions-{$this->screen->id}", $this->_actions ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

			$two = '';
		} else {
			$two = '2';
		}

		if ( empty( $this->_actions ) ) {
			return;
		}

		echo '<label for="bulk-action-selector-' . esc_attr( $which ) . '" class="screen-reader-text">' .
			esc_html__( 'Select bulk action', 'pickybooking' ) .
		'</label>';
		echo '<select name="action' . esc_attr( $two ) . '" id="bulk-action-selector-' . esc_attr( $which ) . "\">\n";
		echo '<option value="-1">' . esc_html__( 'Bulk actions', 'pickybooking' ) . "</option>\n";

		foreach ( $this->_actions as $key => $value ) {
			if ( is_array( $value ) ) {
				echo "\t" . '<optgroup label="' . esc_attr( $key ) . '">' . "\n";

				foreach ( $value as $name => $title ) {
					$option_class = ( 'edit' === $name ) ? 'hide-if-no-js' : '';

					echo "\t\t" . '<option value="' . esc_attr( $name ) . '"' . ( $option_class !== '' ? ' class="' . esc_attr( $option_class ) . '"' : '' ) . '>' . esc_html( $title ) . "</option>\n";
				}
				echo "\t" . "</optgroup>\n";
			} else {
				$option_class = ( 'edit' === $key ) ? 'hide-if-no-js' : '';

				echo "\t" . '<option value="' . esc_attr( $key ) . '"' . ( $option_class !== '' ? ' class="' . esc_attr( $option_class ) . '"' : '' ) . '>' . esc_html( $value ) . "</option>\n";
			}
		}

		echo "</select>\n";

		$apply_text = __( 'Apply', 'pickybooking' );
		printf(
			'<button type="submit" name="bulk_action" id="%1$s" class="button button-primary picky-list-toolbar-btn picky-list-toolbar-btn--apply" value="%2$s">%3$s</button>' . "\n",
			esc_attr( 'doaction' . $two ),
			esc_attr( $apply_text ),
			esc_html( $apply_text )
		);
	}

	/**
	 * Renders the views &lt;select&gt; with a visible label (calendar-style) for the embedded list.
	 *
	 * @return void
	 */
	protected function picky_render_views_select_embedded() {
		if ( ! ( $this->picky_embed_admin instanceof Admin ) || ! is_array( $this->picky_embed_canonical_query ) ) {
			return;
		}
		$views = $this->get_views();
		/** This filter is documented in wp-admin/includes/class-wp-list-table.php */
		$views = apply_filters( 'views_' . $this->screen->id, $views );
		if ( ! is_array( $views ) || count( $views ) < 2 ) {
			return;
		}
		echo '<label class="picky-reservations-custom__view-filter">';
		echo esc_html__( 'View', 'pickybooking' );
		echo '<select id="picky-reservations-view-select" class="picky-reservations-custom__view-select" autocomplete="off">';
		foreach ( $views as $html ) {
			if ( ! is_string( $html ) || $html === '' ) {
				continue;
			}
			$href = '';
			if ( preg_match( '/\bhref\s*=\s*(["\'])([^"\']*)\1/i', $html, $m ) ) {
				$href = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			if ( $href === '' ) {
				continue;
			}
			$canonical = $this->picky_embed_admin->picky_canonicalize_embedded_list_href_for_markup( $href, $this->picky_embed_canonical_query );
			if ( is_string( $canonical ) && $canonical !== '' ) {
				$href = $canonical;
			}
			$is_current = ( false !== strpos( $html, 'class="current"' ) || false !== strpos( $html, "class='current'" )
				|| false !== strpos( $html, 'aria-current="page"' ) || false !== strpos( $html, "aria-current='page'" ) );
			$label = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( $html ) ) );
			echo '<option value="' . esc_attr( $href ) . '"' . ( $is_current ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
	}

	/**
	 * Replace WP core screen-reader-only dropdown labels with visible wrapping labels (matches calendar tab pattern).
	 *
	 * @param string $html Raw HTML from months/categories/formats + restrict_manage_posts.
	 * @return string
	 */
	protected function picky_embed_expose_core_dropdown_labels( $html ) {
		if ( ! is_string( $html ) || $html === '' ) {
			return $html;
		}
		$patterns = array(
			'/<label for="filter-by-date" class="screen-reader-text">\s*(.+?)\s*<\/label>\s*(<select name="m" id="filter-by-date">[\s\S]*?<\/select>)/u',
			'/<label class="screen-reader-text" for="cat">\s*(.+?)\s*<\/label>\s*(<select[^>]*name=[\'"]cat[\'"][^>]*>[\s\S]*?<\/select>)/u',
			'/<label for="filter-by-format" class="screen-reader-text">\s*(.+?)\s*<\/label>\s*(<select name="post_format" id="filter-by-format">[\s\S]*?<\/select>)/u',
		);
		foreach ( $patterns as $pattern ) {
			$next = preg_replace( $pattern, '<label>$1 $2</label>', $html, 1 );
			if ( is_string( $next ) ) {
				$html = $next;
			}
		}
		return $html;
	}

	/**
	 * {@inheritdoc}
	 *
	 * In the embedded Settings list, output the views &lt;select&gt; immediately before the core "All dates" dropdown.
	 */
	protected function extra_tablenav( $which ) {
		if ( $this->picky_embed_base_url !== '' && 'top' === $which ) {
			?>
			<div class="picky-reservations-filter-fields">
			<?php
			ob_start();
			$this->picky_render_views_select_embedded();
			$this->months_dropdown( $this->screen->post_type );
			$this->categories_dropdown( $this->screen->post_type );
			$this->formats_dropdown( $this->screen->post_type );
			/** This action is documented in wp-admin/includes/class-wp-posts-list-table.php */
			do_action( 'restrict_manage_posts', $this->screen->post_type, $which );
			$output = $this->picky_embed_expose_core_dropdown_labels( (string) ob_get_clean() );
			if ( ! empty( $output ) ) {
				echo wp_kses(
					$output,
					array(
						'div'    => array(
							'class' => true,
						),
						'label'  => array(
							'for'   => true,
							'class' => true,
						),
						'select' => array(
							'name'         => true,
							'id'           => true,
							'class'        => true,
							'autocomplete' => true,
						),
						'option' => array(
							'value'    => true,
							'selected' => true,
							'class'    => true,
						),
						'input'  => array(
							'type'  => true,
							'name'  => true,
							'id'    => true,
							'class' => true,
							'value' => true,
						),
					)
				);
				$filter_label = __( 'Filter', 'pickybooking' );
				printf(
					'<button type="submit" name="filter_action" id="post-query-submit" class="button button-secondary picky-list-toolbar-btn picky-list-toolbar-btn--filter" value="%1$s">%2$s</button>',
					esc_attr( $filter_label ),
					esc_html( $filter_label )
				);
			}
			if ( $this->is_trash && $this->has_items()
				&& current_user_can( get_post_type_object( $this->screen->post_type )->cap->edit_others_posts )
			) {
				submit_button( __( 'Empty Trash', 'pickybooking' ), 'apply', 'delete_all', false );
			}
			?>
			</div>
			<?php
			/** This action is documented in wp-admin/includes/class-wp-posts-list-table.php */
			do_action( 'manage_posts_extra_tablenav', $which );
			return;
		}

		parent::extra_tablenav( $which );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Embedded reservations: one horizontal top bar (same layout as calendar filter strip), then bulk + pagination row.
	 */
	protected function display_tablenav( $which ) {
		if ( $this->picky_embed_base_url === '' ) {
			parent::display_tablenav( $which );
			return;
		}
		if ( 'bottom' === $which ) {
			parent::display_tablenav( $which );
			return;
		}

		wp_nonce_field( 'bulk-' . $this->_args['plural'] );
		?>
		<div class="picky-reservations-top-bar" role="region" aria-label="<?php esc_attr_e( 'Filters and search', 'pickybooking' ); ?>">
			<?php $this->extra_tablenav( 'top' ); ?>
			<?php
			$pto_obj = get_post_type_object( $this->screen->post_type );
			if ( $pto_obj && isset( $pto_obj->labels->search_items ) && is_string( $pto_obj->labels->search_items ) ) {
				$this->search_box( $pto_obj->labels->search_items, 'post' );
			}
			?>
		</div>
		<div class="tablenav top picky-reservations-toolbar">
			<?php if ( $this->has_items() ) : ?>
			<div class="alignleft actions bulkactions">
				<?php $this->bulk_actions( 'top' ); ?>
			</div>
			<?php endif; ?>
			<div class="picky-reservations-toolbar__pager">
				<?php $this->pagination( 'top' ); ?>
			</div>
			<br class="clear" />
		</div>
		<?php
	}

	/**
	 * @param string $which top|bottom
	 * @return void
	 */
	public function picky_display_tablenav( $which ) {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI for form action only
		if ( $this->picky_embed_base_url === '' ) {
			$this->display_tablenav( $which );
			return;
		}
        $backup_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$parsed     = wp_parse_url( $this->picky_embed_base_url );
		if ( is_array( $parsed ) && isset( $parsed['path'] ) && is_string( $parsed['path'] ) && $parsed['path'] !== '' ) {
			$q                      = ( isset( $parsed['query'] ) && is_string( $parsed['query'] ) ) ? $parsed['query'] : '';
			$_SERVER['REQUEST_URI'] = $parsed['path'] . ( $q !== '' ? '?' . $q : '' );
		}
		$this->display_tablenav( $which );
		$_SERVER['REQUEST_URI'] = $backup_uri;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
}
