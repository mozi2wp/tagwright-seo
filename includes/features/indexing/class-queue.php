<?php
/**
 * Submission queue.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Indexing;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Collects changed URLs and sends them a few seconds later in one go.
 *
 * Why a queue plus a single cron event, rather than a non-blocking request
 * on shutdown: saving in the block editor is often two requests (the post,
 * then the meta boxes), bulk edits touch many posts, and a non-blocking
 * request never tells us whether it worked. Queueing in an option and
 * flushing once shortly after collapses all of that into one request per
 * engine, keeps the editor fast and gives us a real status for the log.
 * The cron event is spawned by the very next page load, so in practice
 * the ping goes out within seconds.
 */
class Queue {

	/**
	 * Option holding URL => action pairs waiting to be sent.
	 */
	const OPTION = 'tagwright_seo_indexing_queue';

	/**
	 * Cron hook that sends the queue.
	 */
	const CRON_HOOK = 'tagwright_seo_indexing_flush';

	/**
	 * Seconds to wait before sending, so related saves land in one batch.
	 */
	const DELAY = 10;

	/**
	 * URLs collected during this request, written on shutdown.
	 *
	 * @var array<string, string>
	 */
	private static $pending = array();

	/**
	 * Adds a URL to the queue. Later actions for the same URL win, so a
	 * post published and then trashed in one batch is sent as a deletion.
	 *
	 * @param string $url    URL.
	 * @param string $action `update` or `delete`.
	 * @return void
	 */
	public static function add( $url, $action = 'update' ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return;
		}

		$action = 'delete' === $action ? 'delete' : 'update';

		// Bulk edits can touch hundreds of posts; write the option once at the end.
		if ( empty( self::$pending ) ) {
			add_action( 'shutdown', array( __CLASS__, 'persist' ), 1 );
		}

		self::$pending[ $url ] = $action;
	}

	/**
	 * Writes what this request collected and schedules the flush.
	 *
	 * @return void
	 */
	public static function persist() {
		if ( empty( self::$pending ) ) {
			return;
		}

		update_option( self::OPTION, array_merge( self::get(), self::$pending ), false );
		self::$pending = array();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::CRON_HOOK );
		}
	}

	/**
	 * Queued items.
	 *
	 * @return array<string, string>
	 */
	public static function get() {
		$queue = get_option( self::OPTION, array() );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Sends everything in the queue to each engine that is switched on.
	 *
	 * @return void
	 */
	public static function flush() {
		$queue = self::get();
		delete_option( self::OPTION );

		if ( empty( $queue ) ) {
			return;
		}

		self::send( $queue );
	}

	/**
	 * Sends items straight away. Used by the queue, the manual form and the
	 * bulk action.
	 *
	 * @param array<string, string> $items URL => `update` or `delete`.
	 * @return array[] Result per engine, keyed by engine id.
	 */
	public static function send( array $items ) {
		$results = array();

		if ( Options::get( 'indexing_indexnow' ) ) {
			$results['indexnow'] = Indexnow::submit( array_keys( $items ) );
		}

		if ( Options::get( 'indexing_google' ) && Google::credentials() ) {
			$results['google'] = Google::submit( $items );
		}

		return $results;
	}

	/**
	 * Keeps only valid http(s) URLs on this site's host, without duplicates.
	 *
	 * @param string[] $urls URLs.
	 * @return string[]
	 */
	public static function filter_urls( array $urls ) {
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$clean = array();

		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
			if ( '' === $url ) {
				continue;
			}

			if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
				continue;
			}

			$clean[ $url ] = true;
		}

		return array_keys( $clean );
	}

	/**
	 * Removes the queue and its event. Used on uninstall.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
