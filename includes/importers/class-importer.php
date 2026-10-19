<?php
/**
 * Base class for importers.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Importers;

use TagwrightSEO\Data\Object_Sync;
use TagwrightSEO\Data\Objects;

defined( 'ABSPATH' ) || exit;

/**
 * Shared logic for moving SEO data over from another plugin.
 *
 * Posts are processed in batches so even very large sites import without
 * timeouts. Terms and users follow once the posts are done.
 */
abstract class Importer {

	/**
	 * Posts per batch.
	 */
	const BATCH = 100;

	/**
	 * Machine name, used in URLs.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human name.
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * Whether there is data from this plugin on the site.
	 *
	 * @return bool
	 */
	abstract public function is_detected();

	/**
	 * Post ids with data, for one batch.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int[]
	 */
	abstract protected function post_ids( $offset, $limit );

	/**
	 * Number of posts with data.
	 *
	 * @return int
	 */
	abstract public function count_posts();

	/**
	 * Maps one post's data to Tagwright SEO fields.
	 *
	 * @param int $post_id Post id.
	 * @return array Fields for Objects::save().
	 */
	abstract protected function post_fields( $post_id );

	/**
	 * Imports terms and users. Called once, after the last post batch.
	 *
	 * @return int Number of objects imported.
	 */
	abstract protected function import_terms_and_users();

	/**
	 * Imports site-wide settings.
	 *
	 * @return int Number of settings imported.
	 */
	abstract public function import_settings();

	/**
	 * Runs one batch.
	 *
	 * @param int $offset Number of posts already processed.
	 * @return array Progress.
	 */
	public function import_batch( $offset ) {
		$total = $this->count_posts();
		$ids   = $this->post_ids( $offset, self::BATCH );

		foreach ( $ids as $post_id ) {
			$fields = array_filter(
				$this->post_fields( (int) $post_id ),
				static fn( $value ) => null !== $value && '' !== $value
			);

			if ( $fields ) {
				Objects::save( 'post', (int) $post_id, $fields );
			}

			// Make sure permalink, status and dates are filled in as well.
			Object_Sync::index_post( get_post( (int) $post_id ) );
		}

		$offset += count( $ids );
		$done    = count( $ids ) < self::BATCH;
		$extra   = $done ? $this->import_terms_and_users() : 0;

		return array(
			'status'  => $done ? 'complete' : 'running',
			'offset'  => $offset,
			'total'   => $total,
			'percent' => $total ? min( 100, (int) floor( $offset / $total * 100 ) ) : 100,
			'terms'   => $extra,
			'message' => $done
				? sprintf(
					/* translators: 1: number of posts, 2: number of terms and users, 3: plugin name. */
					__( 'Imported %1$d posts and %2$d terms and authors from %3$s.', 'tagwright-seo' ),
					$offset,
					$extra,
					$this->name()
				)
				: '',
		);
	}

	/**
	 * Converts template variables from another plugin to ours.
	 *
	 * @param string $text Template.
	 * @return string
	 */
	protected function convert_variables( $text ) {
		return (string) $text;
	}

	/**
	 * Keeps only page and article types Tagwright SEO knows.
	 *
	 * @param string $type   Type from the other plugin.
	 * @param string $kind   `page` or `article`.
	 * @return string
	 */
	protected function schema_type( $type, $kind ) {
		$types = 'page' === $kind ? \TagwrightSEO\Schema\Graph::page_types() : \TagwrightSEO\Schema\Graph::article_types();

		return isset( $types[ $type ] ) ? $type : '';
	}

	/**
	 * Reads a single post meta value as a string.
	 *
	 * @param array  $meta All meta for the post, from get_post_meta( $id ).
	 * @param string $key  Meta key.
	 * @return string
	 */
	protected function meta( array $meta, $key ) {
		return isset( $meta[ $key ][0] ) ? (string) $meta[ $key ][0] : '';
	}
}
