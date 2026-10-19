<?php
/**
 * Bulk alt text tool.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Image_Seo;

use TagwrightSEO\Admin\Admin;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes that count, preview and fill missing alt text in the media
 * library.
 *
 * Filling runs in batches. The "offset" is the id of the last image looked
 * at, not a row count: filled images drop out of the "missing" set as we
 * go, so a plain row offset would skip images. Images whose template comes
 * out empty stay behind the cursor, so the job always finishes.
 */
class Bulk_Tool {

	/**
	 * Images per batch.
	 */
	const BATCH = 50;

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			'tagwright-seo/v1',
			'/image-seo/bulk-alt',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'batch' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'offset' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * Settings-level access plus the right to edit media.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( Admin::capability() ) && current_user_can( 'upload_files' );
	}

	/*
	 * The three queries below share the same FROM and WHERE: images without
	 * alt text, trashed attachments left alone. They are written out in full
	 * so each one is a single prepared statement.
	 */

	/**
	 * Count of images still missing alt text.
	 *
	 * @return int
	 */
	public function missing_count() {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live count for an admin tool.
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT p.ID )
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' )
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type LIKE %s
				AND ( m.meta_id IS NULL OR TRIM( m.meta_value ) = '' )",
				$wpdb->esc_like( 'image/' ) . '%'
			)
		);
	}

	/**
	 * Alt text an image would get, from the saved template.
	 *
	 * @param \WP_Post $attachment Attachment.
	 * @return string
	 */
	public function suggest( $attachment ) {
		$image  = Variables::image_data( $attachment->ID );
		$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		$alt    = Variables::replace( (string) Options::get( 'image_seo_alt_template', '%filename%' ), $image, $parent );

		return '' !== $alt ? $alt : $image['filename'];
	}

	/**
	 * GET: how many images miss alt text, with a few examples.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' )
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type LIKE %s
				AND ( m.meta_id IS NULL OR TRIM( m.meta_value ) = '' )
				ORDER BY p.ID DESC LIMIT 5",
				$wpdb->esc_like( 'image/' ) . '%'
			)
		);

		$examples = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$attachment = get_post( $id );
			if ( ! $attachment ) {
				continue;
			}

			$examples[] = array(
				'id'    => $id,
				'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
				'file'  => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
				'alt'   => $this->suggest( $attachment ),
				'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			);
		}

		return rest_ensure_response(
			array(
				'missing'  => $this->missing_count(),
				'examples' => $examples,
			)
		);
	}

	/**
	 * POST: fills one batch.
	 *
	 * @param \WP_REST_Request $request Request with the cursor in "offset".
	 * @return \WP_REST_Response
	 */
	public function batch( $request ) {
		global $wpdb;

		$cursor = absint( $request['offset'] );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' )
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type LIKE %s
				AND ( m.meta_id IS NULL OR TRIM( m.meta_value ) = '' )
				AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
				$wpdb->esc_like( 'image/' ) . '%',
				$cursor,
				self::BATCH
			)
		);

		$filled = 0;
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$cursor     = $id;
			$attachment = get_post( $id );
			if ( ! $attachment ) {
				continue;
			}

			$alt = $this->suggest( $attachment );
			if ( '' !== $alt ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				++$filled;
			}
		}

		$done  = count( $ids ) < self::BATCH;
		$total = $this->image_count();

		// Progress is the share of the library the cursor has passed.
		$percent = 100;
		if ( ! $done && $total ) {
			$percent = min( 99, (int) floor( $this->image_count( $cursor ) / $total * 100 ) );
		}

		return rest_ensure_response(
			array(
				'status'    => $done ? 'complete' : 'running',
				'offset'    => $cursor,
				'total'     => $total,
				'percent'   => $percent,
				'filled'    => $filled,
				'remaining' => $done ? $this->missing_count() : null,
			)
		);
	}

	/**
	 * Number of images in the library.
	 *
	 * @param int $up_to Only count ids up to this one, 0 for all.
	 * @return int
	 */
	private function image_count( $up_to = 0 ) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash' AND post_mime_type LIKE %s",
			$wpdb->esc_like( 'image/' ) . '%'
		);

		if ( $up_to ) {
			$sql .= $wpdb->prepare( ' AND ID <= %d', $up_to );
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Prepared above.
	}
}
