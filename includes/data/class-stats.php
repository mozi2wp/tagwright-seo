<?php
/**
 * Daily SEO statistics for the dashboard trends.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Data;

use TagwrightSEO\Installer;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Records one value per metric per day in the tagwright_stats table.
 *
 * The core writes on-site metrics such as average scores and missing
 * descriptions. Add-ons record their own under a different `source`, for
 * example the Search Console add-on stores clicks and impressions.
 */
class Stats {

	/**
	 * Score at or above which content counts as good.
	 */
	const GOOD = 70;

	/**
	 * Score at or above which content counts as okay.
	 */
	const OK = 40;

	/**
	 * Registers the daily snapshot.
	 */
	public function __construct() {
		add_action( 'tagwright_seo_daily', array( __CLASS__, 'daily' ) );
	}

	/**
	 * Daily cron job.
	 *
	 * @return void
	 */
	public static function daily() {
		self::snapshot();
		self::prune();

		/**
		 * Fires once a day after the core snapshot. Add-ons can record their
		 * own metrics here with Stats::record().
		 */
		do_action( 'tagwright_seo_daily_stats' );
	}

	/**
	 * Stores a value for a metric.
	 *
	 * @param string      $metric    Metric name, for example `clicks`.
	 * @param float       $value     Value.
	 * @param string      $dimension Optional breakdown, for example a post type.
	 * @param string      $source    Who recorded it. Add-ons use their slug.
	 * @param string|null $date      Y-m-d in the site's timezone. Defaults to today.
	 * @return void
	 */
	public static function record( $metric, $value, $dimension = '', $source = 'core', $date = null ) {
		global $wpdb;

		$table = Installer::get_table_names()['stats'];
		$date  = $date ? $date : wp_date( 'Y-m-d' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO %i (stat_date, source, metric, dimension, value) VALUES (%s, %s, %s, %s, %f) ON DUPLICATE KEY UPDATE value = VALUES(value)',
				$table,
				$date,
				substr( sanitize_key( $source ), 0, 40 ),
				substr( sanitize_key( $metric ), 0, 64 ),
				substr( (string) $dimension, 0, 100 ),
				(float) $value
			)
		);
	}

	/**
	 * Takes today's snapshot unless one already exists.
	 *
	 * The dashboard calls this so trends start the day the plugin is installed
	 * instead of waiting for the first cron run.
	 *
	 * @return void
	 */
	public static function maybe_snapshot_today() {
		global $wpdb;

		$table = Installer::get_table_names()['stats'];
		$has   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT 1 FROM %i WHERE stat_date = %s AND source = 'core' LIMIT 1", $table, wp_date( 'Y-m-d' ) )
		);

		if ( ! $has ) {
			self::snapshot();
		}
	}

	/**
	 * Records the core metrics for today.
	 *
	 * @return void
	 */
	public static function snapshot() {
		$summary = self::live_summary();

		foreach ( $summary['totals'] as $metric => $value ) {
			if ( null !== $value ) {
				self::record( $metric, $value );
			}
		}

		foreach ( $summary['post_types'] as $post_type => $row ) {
			self::record( 'posts_published', $row['count'], $post_type );
			if ( null !== $row['avg_score'] ) {
				self::record( 'seo_score_avg', $row['avg_score'], $post_type );
			}
		}
	}

	/**
	 * Calculates the current numbers straight from the objects table.
	 *
	 * A handful of aggregate queries on indexed columns, so it stays fast
	 * even on large sites.
	 *
	 * @return array{totals: array, post_types: array}
	 */
	public static function live_summary() {
		global $wpdb;

		$table = Objects::table();

		// Post types that are noindexed in the settings. Used twice in the first
		// query and once in the second, so the arguments are repeated to match.
		$noindexed = self::noindexed_post_types();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS posts_published,
					AVG(seo_score) AS seo_score_avg,
					AVG(readability_score) AS readability_avg,
					SUM(seo_score >= %d) AS seo_good,
					SUM(seo_score >= %d AND seo_score < %d) AS seo_ok,
					SUM(seo_score < %d) AS seo_poor,
					SUM(seo_score IS NULL) AS seo_none,
					SUM(readability_score >= %d) AS read_good,
					SUM(readability_score >= %d AND readability_score < %d) AS read_ok,
					SUM(readability_score < %d) AS read_poor,
					SUM(readability_score IS NULL) AS read_none,
					SUM( COALESCE( robots_noindex, 0 ) = 1 OR ( robots_noindex IS NULL AND object_sub_type IN (" . implode( ',', array_fill( 0, count( $noindexed ), '%s' ) ) . ") ) ) AS noindex,
					SUM(description IS NULL OR description = '') AS no_description,
					SUM(focus_keyphrase = '') AS no_focus_keyphrase,
					SUM( NOT ( COALESCE( robots_noindex, 0 ) = 1 OR ( robots_noindex IS NULL AND object_sub_type IN (" . implode( ',', array_fill( 0, count( $noindexed ), '%s' ) ) . ") ) ) AND sitemap_exclude = 0 ) AS sitemap_urls
				FROM %i
				WHERE object_type = 'post' AND is_public = 1",
				array_merge(
					array( self::GOOD, self::OK, self::GOOD, self::OK, self::GOOD, self::OK, self::GOOD, self::OK ),
					$noindexed,
					$noindexed,
					array( $table )
				)
			),
			ARRAY_A
		);

		$totals = array();
		foreach ( (array) $row as $key => $value ) {
			if ( null === $value ) {
				$totals[ $key ] = in_array( $key, array( 'seo_score_avg', 'readability_avg' ), true ) ? null : 0;
				continue;
			}
			$totals[ $key ] = in_array( $key, array( 'seo_score_avg', 'readability_avg' ), true ) ? round( (float) $value, 1 ) : (int) $value;
		}

		$totals['terms_public']   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_type = 'term' AND is_public = 1", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$totals['authors_public'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_type = 'user' AND is_public = 1", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// The same issue counts per post type, so the to-do list can link to each list screen.
		$by_type = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT object_sub_type AS post_type, COUNT(*) AS total, AVG(seo_score) AS avg_score, AVG(readability_score) AS avg_readability,
					SUM( COALESCE( robots_noindex, 0 ) = 1 OR ( robots_noindex IS NULL AND object_sub_type IN (' . implode( ',', array_fill( 0, count( $noindexed ), '%s' ) ) . ") ) ) AS noindex,
					SUM(seo_score < %d) AS seo_poor,
					SUM(readability_score < %d) AS read_poor,
					SUM(focus_keyphrase = '') AS no_focus_keyphrase,
					SUM(description IS NULL OR description = '') AS no_description
				FROM %i
				WHERE object_type = 'post' AND is_public = 1
				GROUP BY object_sub_type
				ORDER BY total DESC",
				array_merge( $noindexed, array( self::OK, self::OK, $table ) )
			),
			ARRAY_A
		);

		$post_types = array();
		foreach ( (array) $by_type as $type_row ) {
			$object = get_post_type_object( $type_row['post_type'] );

			$post_types[ $type_row['post_type'] ] = array(
				'label'           => $object ? $object->labels->name : $type_row['post_type'],
				'count'           => (int) $type_row['total'],
				'avg_score'       => null === $type_row['avg_score'] ? null : round( (float) $type_row['avg_score'], 1 ),
				'avg_readability' => null === $type_row['avg_readability'] ? null : round( (float) $type_row['avg_readability'], 1 ),
				'noindex'         => (int) $type_row['noindex'],
				'issues'          => array(
					'seo_poor'           => (int) $type_row['seo_poor'],
					'read_poor'          => (int) $type_row['read_poor'],
					'no_focus_keyphrase' => (int) $type_row['no_focus_keyphrase'],
					'no_description'     => (int) $type_row['no_description'],
					'noindex'            => (int) $type_row['noindex'],
				),
				'list_url'        => admin_url( 'edit.php?post_type=' . rawurlencode( $type_row['post_type'] ) ),
			);
		}

		return array(
			'totals'     => $totals,
			'post_types' => $post_types,
		);
	}

	/**
	 * Returns one value per day for a metric over the last N days.
	 *
	 * Days without data come back as null so charts can show the gap.
	 *
	 * @param string $metric    Metric name.
	 * @param int    $days      Number of days, today included.
	 * @param string $source    Source slug.
	 * @param string $dimension Optional dimension.
	 * @return array[] List of array( 'date' => 'Y-m-d', 'value' => float|null ).
	 */
	public static function series( $metric, $days = 30, $source = 'core', $dimension = '' ) {
		global $wpdb;

		$table = Installer::get_table_names()['stats'];
		$days  = max( 1, min( 730, (int) $days ) );
		$today = new \DateTimeImmutable( wp_date( 'Y-m-d' ), wp_timezone() );
		$start = $today->modify( '-' . ( $days - 1 ) . ' days' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT stat_date, value FROM %i WHERE source = %s AND metric = %s AND dimension = %s AND stat_date >= %s ORDER BY stat_date ASC',
				$table,
				$source,
				$metric,
				$dimension,
				$start->format( 'Y-m-d' )
			),
			OBJECT_K
		);

		$series = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$date     = $start->modify( "+{$i} days" )->format( 'Y-m-d' );
			$series[] = array(
				'date'  => $date,
				'value' => isset( $rows[ $date ] ) ? (float) $rows[ $date ]->value : null,
			);
		}

		return $series;
	}

	/**
	 * Deletes old rows so the table never grows without limit.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		/**
		 * Filters how many days of statistics are kept.
		 *
		 * @param int $days Days to keep. Default 400, a little over a year.
		 */
		$days  = (int) apply_filters( 'tagwright_seo_stats_retention_days', 400 );
		$table = Installer::get_table_names()['stats'];

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM %i WHERE stat_date < %s', $table, wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) )
		);
	}

	/**
	 * Removes every stored statistic.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Installer::get_table_names()['stats'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * SQL expression that is true when a post row resolves to noindex.
	 *
	 * A row is noindexed when the editor ticked noindex, or left it on the
	 * default and the post type is noindexed in the settings.
	 *
	 * @return string Safe SQL fragment.
	 */
	public static function noindex_condition() {
		global $wpdb;

		$types = self::noindexed_post_types();

		return $wpdb->prepare(
			'( COALESCE( robots_noindex, 0 ) = 1 OR ( robots_noindex IS NULL AND object_sub_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') ) )',
			$types
		);
	}

	/**
	 * Public post types that are set to noindex in the settings.
	 *
	 * Never empty: when nothing is noindexed it holds a single empty string,
	 * which no post row has as its sub type. That keeps `IN ( ... )` valid
	 * without having to build two versions of every query.
	 *
	 * @return string[]
	 */
	public static function noindexed_post_types() {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( Options::get( 'pt_' . $post_type . '_noindex' ) ) {
				$types[] = $post_type;
			}
		}

		return $types ? $types : array( '' );
	}
}
