<?php
/**
 * Background indexing of existing content.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Walks through posts, terms and users in small batches and fills the
 * objects table.
 *
 * Batches run through WP-Cron in the background, and the Tools screen can
 * also drive them over REST to show a progress bar. Both paths share the
 * same state, so they never repeat work.
 */
class Indexer {

	/**
	 * Option holding the progress. Not autoloaded.
	 */
	const STATE_OPTION = 'tagwright_seo_index_state';

	/**
	 * Cron hook for one batch.
	 */
	const CRON_HOOK = 'tagwright_seo_index_batch';

	/**
	 * Order in which object types are processed.
	 *
	 * @var string[]
	 */
	const PHASES = array( 'post', 'term', 'user' );

	/**
	 * Registers the cron callback.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron_batch' ) );
	}

	/**
	 * Starts (or restarts) a full index in the background.
	 *
	 * @return void
	 */
	public static function queue_full_index() {
		update_option(
			self::STATE_OPTION,
			array(
				'status'     => 'running',
				'phase'      => 'post',
				'last_id'    => 0,
				'done'       => 0,
				'total'      => self::count_total(),
				'started_at' => time(),
			),
			false
		);

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: run one batch and schedule the next if needed.
	 *
	 * @return void
	 */
	public static function run_cron_batch() {
		$state = self::run_batch();

		if ( 'running' === $state['status'] ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Processes one batch.
	 *
	 * @param int $size Items per batch.
	 * @return array The updated state.
	 */
	public static function run_batch( $size = 0 ) {
		$state = self::get_state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		/**
		 * Filters how many items are indexed per batch.
		 *
		 * @param int $size Batch size.
		 */
		$size = $size ? $size : (int) apply_filters( 'tagwright_seo_index_batch_size', 100 );
		$ids  = self::next_ids( $state['phase'], (int) $state['last_id'], $size );

		foreach ( $ids as $id ) {
			switch ( $state['phase'] ) {
				case 'post':
					Object_Sync::index_post( get_post( $id ) );
					break;

				case 'term':
					$term = get_term( $id );
					if ( $term && ! is_wp_error( $term ) ) {
						Object_Sync::index_term( $term->term_id, $term->taxonomy );
					}
					break;

				case 'user':
					Object_Sync::index_user( $id );
					break;
			}
		}

		$state['done'] += count( $ids );

		if ( count( $ids ) < $size ) {
			$next = array_search( $state['phase'], self::PHASES, true ) + 1;

			if ( isset( self::PHASES[ $next ] ) ) {
				$state['phase']   = self::PHASES[ $next ];
				$state['last_id'] = 0;
			} else {
				$state['status']      = 'complete';
				$state['finished_at'] = time();
				$state['done']        = max( $state['done'], $state['total'] );
			}
		} else {
			$state['last_id'] = (int) end( $ids );
		}

		update_option( self::STATE_OPTION, $state, false );

		if ( 'complete' === $state['status'] ) {
			/**
			 * Fires when a full index run finishes.
			 */
			do_action( 'tagwright_seo_index_complete' );
		}

		return $state;
	}

	/**
	 * Current progress.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION );

		if ( ! is_array( $state ) ) {
			$state = array(
				'status'  => 'idle',
				'phase'   => 'post',
				'last_id' => 0,
				'done'    => 0,
				'total'   => 0,
			);
		}

		$state['percent'] = $state['total'] > 0 ? min( 100, (int) floor( $state['done'] / $state['total'] * 100 ) ) : ( 'complete' === $state['status'] ? 100 : 0 );

		return $state;
	}

	/**
	 * Grabs the next batch of ids for a phase, in ascending order.
	 *
	 * @param string $phase   post, term or user.
	 * @param int    $last_id Last processed id.
	 * @param int    $size    Batch size.
	 * @return int[]
	 */
	private static function next_ids( $phase, $last_id, $size ) {
		global $wpdb;

		switch ( $phase ) {
			case 'post':
				$post_types = self::indexable_post_types();
				if ( empty( $post_types ) ) {
					return array();
				}
				$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

				return array_map(
					'intval',
					$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$placeholders}) AND post_status NOT IN ('auto-draft','trash') ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
							array_merge( array( $last_id ), $post_types, array( $size ) )
						)
					)
				);

			case 'term':
				$taxonomies = array_values( get_taxonomies( array( 'public' => true ) ) );
				if ( empty( $taxonomies ) ) {
					return array();
				}
				$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

				return array_map(
					'intval',
					$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"SELECT DISTINCT term_id FROM {$wpdb->term_taxonomy} WHERE term_id > %d AND taxonomy IN ({$placeholders}) ORDER BY term_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
							array_merge( array( $last_id ), $taxonomies, array( $size ) )
						)
					)
				);

			case 'user':
				// Only people who actually have an author archive. Shop customers are skipped.
				$post_types = self::indexable_post_types();
				if ( empty( $post_types ) ) {
					return array();
				}
				$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

				return array_map(
					'intval',
					$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_author > %d AND post_status = 'publish' AND post_type IN ({$placeholders}) ORDER BY post_author ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
							array_merge( array( $last_id ), $post_types, array( $size ) )
						)
					)
				);
		}

		return array();
	}

	/**
	 * Rough total for the progress bar.
	 *
	 * @return int
	 */
	private static function count_total() {
		global $wpdb;

		$post_types = self::indexable_post_types();
		$total      = 0;

		if ( $post_types ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$total       += (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status NOT IN ('auto-draft','trash')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$post_types
				)
			);
		}

		$total += (int) wp_count_terms( array( 'taxonomy' => array_values( get_taxonomies( array( 'public' => true ) ) ), 'hide_empty' => false ) );

		return $total;
	}

	/**
	 * Post types we keep rows for.
	 *
	 * @return string[]
	 */
	public static function indexable_post_types() {
		$post_types = array_values( get_post_types( array( 'public' => true ) ) );

		/**
		 * Filters which post types get indexed.
		 *
		 * @param string[] $post_types Post type names.
		 */
		return apply_filters( 'tagwright_seo_indexable_post_types', $post_types );
	}
}
