<?php
/**
 * Embedded WP_Posts_List_Table: native WordPress builds sort links and pagination from
 * $_SERVER['REQUEST_URI']. Pagination() restores REQUEST_URI after *each* top/bottom block,
 * but column headers run between them — URI drift could still leak another embed's tab.
 * Lock REQUEST_URI for the whole display() output instead.
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
class Picky_Embedded_Posts_List_Table extends \WP_Posts_List_Table {

	/**
	 * Full URL from add_query_arg( $embed_query, admin_url( 'admin.php' ) ).
	 *
	 * @var string
	 */
	protected $picky_embed_base_url = '';

	/**
	 * @param string $url Full admin URL for this embedded list.
	 * @return void
	 */
	public function set_picky_embed_base_url( $url ) {
		$this->picky_embed_base_url = is_string( $url ) ? $url : '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function display() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI for form action only
		if ( $this->picky_embed_base_url === '' ) {
			parent::display();
			return;
		}

		$backup_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$parsed     = wp_parse_url( $this->picky_embed_base_url );

		if ( is_array( $parsed ) && isset( $parsed['path'] ) && is_string( $parsed['path'] ) && $parsed['path'] !== '' ) {
			$q                      = ( isset( $parsed['query'] ) && is_string( $parsed['query'] ) ) ? $parsed['query'] : '';
			$_SERVER['REQUEST_URI'] = $parsed['path'] . ( $q !== '' ? '?' . $q : '' );
		}

		parent::display();

		$_SERVER['REQUEST_URI'] = $backup_uri;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
}
