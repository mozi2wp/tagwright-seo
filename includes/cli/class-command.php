<?php
/**
 * WP-CLI commands.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Cli;

use TagwrightSEO\Data\Indexer;
use TagwrightSEO\Data\Objects;
use TagwrightSEO\Data\Stats;
use TagwrightSEO\Importers\Importers;
use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Tagwright SEO from the command line.
 */
class Command {

	/**
	 * Builds or rebuilds the SEO index.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<size>]
	 * : Items per batch.
	 * ---
	 * default: 500
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tagwright-seo index
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function index( $args, $assoc_args ) {
		$size = max( 10, (int) ( $assoc_args['batch'] ?? 500 ) );

		Indexer::queue_full_index();
		wp_clear_scheduled_hook( Indexer::CRON_HOOK );

		$state    = Indexer::get_state();
		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing content', max( 1, (int) $state['total'] ) );
		$done     = 0;

		do {
			$state = Indexer::run_batch( $size );
			$progress->tick( max( 0, (int) $state['done'] - $done ) );
			$done = (int) $state['done'];
		} while ( 'running' === $state['status'] );

		$progress->finish();
		Stats::snapshot();

		\WP_CLI::success( sprintf( 'Indexed %d posts, %d terms and %d authors.', Objects::count( 'post' ), Objects::count( 'term' ), Objects::count( 'user' ) ) );
	}

	/**
	 * Imports SEO data from another plugin.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The plugin to import from.
	 * ---
	 * options:
	 *   - yoast
	 *   - rank_math
	 *   - aioseo
	 * ---
	 *
	 * [--skip-settings]
	 * : Only import per-post data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tagwright-seo import yoast
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$importer = Importers::get( $args[0] );
		if ( ! $importer ) {
			\WP_CLI::error( 'Unknown source.' );
		}

		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-settings', false ) ) {
			$count = $importer->import_settings();
			\WP_CLI::log( sprintf( 'Imported %d settings.', $count ) );
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing from ' . $importer->name(), max( 1, $importer->count_posts() ) );
		$offset   = 0;

		do {
			$state = $importer->import_batch( $offset );
			$progress->tick( $state['offset'] - $offset );
			$offset = $state['offset'];
		} while ( 'running' === $state['status'] );

		$progress->finish();
		\WP_CLI::success( $state['message'] );
	}

	/**
	 * Records today's statistics snapshot.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tagwright-seo snapshot
	 *
	 * @return void
	 */
	public function snapshot() {
		Stats::snapshot();
		\WP_CLI::success( 'Snapshot recorded.' );
	}

	/**
	 * Switches a module on or off.
	 *
	 * ## OPTIONS
	 *
	 * <module>
	 * : Module id, for example sitemaps or llms_txt.
	 *
	 * <state>
	 * : on or off.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tagwright-seo module llms_txt on
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function module( $args ) {
		list( $module, $state ) = $args;

		if ( ! isset( Modules::all()[ $module ] ) ) {
			\WP_CLI::error( 'Unknown module. Available: ' . implode( ', ', array_keys( Modules::all() ) ) );
		}

		Modules::set_active( $module, 'on' === $state );
		\WP_CLI::success( sprintf( '%s is now %s.', $module, 'on' === $state ? 'on' : 'off' ) );
	}
}
