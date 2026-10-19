<?php
/**
 * Imports redirects from other plugins.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Reads redirects straight from the tables and options other plugins leave
 * behind and saves them as Tagwright SEO rules.
 *
 * Sources are detected by their data, not by the plugin being active: most
 * people switch the old plugin off before they move over, and its data
 * stays where it was.
 *
 * Every source is read in batches by position, so the browser can drive a
 * large import one request at a time. Rules that already exist with the
 * same source are updated, which makes running an import twice harmless.
 */
class Importer {

	/**
	 * Source rows per request.
	 */
	const BATCH = 200;

	/**
	 * Skip messages sent back per batch. The count is always exact; the
	 * list is only there to show people a few examples.
	 */
	const MAX_MESSAGES = 50;

	/**
	 * Supported sources. The names are product names, so not translated.
	 *
	 * @return array<string, string>
	 */
	public static function sources() {
		return array(
			'rank_math'   => 'Rank Math',
			'yoast'       => 'Yoast SEO Premium',
			'redirection' => 'Redirection',
			'aioseo'      => 'All in One SEO Pro',
		);
	}

	/**
	 * Sources that have redirects on this site.
	 *
	 * @return array[] Lists of id, name and count.
	 */
	public static function detected() {
		$found = array();

		foreach ( self::sources() as $id => $name ) {
			$count = self::count( $id );
			if ( $count > 0 ) {
				$found[] = array(
					'id'    => $id,
					'name'  => $name,
					'count' => $count,
				);
			}
		}

		return $found;
	}

	/**
	 * Number of source entries for a plugin. For Rank Math this counts its
	 * redirections, each of which may hold several source patterns.
	 *
	 * @param string $source Source id.
	 * @return int
	 */
	public static function count( $source ) {
		global $wpdb;

		if ( 'yoast' === $source ) {
			return count( self::yoast_items() );
		}

		$table = self::table( $source );
		if ( '' === $table || ! self::table_exists( $table ) ) {
			return 0;
		}

		if ( 'rank_math' === $source ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status <> 'trashed'", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Imports one batch.
	 *
	 * @param string $source Source id.
	 * @param int    $offset Source entries already processed.
	 * @return array status, offset, total, percent, imported, updated, skipped, errors.
	 */
	public static function import_batch( $source, $offset ) {
		$offset  = max( 0, (int) $offset );
		$total   = self::count( $source );
		$entries = self::entries( $source, $offset, self::BATCH );
		$rules   = array();
		$labels  = array();
		$skipped = array();

		foreach ( $entries as $entry ) {
			foreach ( self::map( $source, $entry ) as $rule ) {
				if ( isset( $rule['skip'] ) ) {
					$skipped[] = self::message( $rule['label'], $rule['skip'] );
					continue;
				}
				$labels[] = $rule['label'];
				unset( $rule['label'] );
				$rules[] = $rule;
			}
		}

		$result = $rules ? Repository::import_rules( $rules ) : array(
			'added'   => 0,
			'updated' => 0,
			'errors'  => array(),
		);

		foreach ( $result['errors'] as $key => $error ) {
			$skipped[] = self::message( $labels[ $key ] ?? '', $error );
		}

		$offset += count( $entries );
		$done    = count( $entries ) < self::BATCH || $offset >= $total;

		return array(
			'status'   => $done ? 'complete' : 'running',
			'offset'   => $offset,
			'total'    => $total,
			'percent'  => $total ? min( 100, (int) floor( $offset / $total * 100 ) ) : 100,
			'imported' => (int) $result['added'],
			'updated'  => (int) $result['updated'],
			'skipped'  => count( $skipped ),
			'errors'   => array_slice( $skipped, 0, self::MAX_MESSAGES ),
		);
	}

	/* ------------------------------------------------------------------
	 * Reading the sources
	 * --------------------------------------------------------------- */

	/**
	 * Table a source keeps its redirects in.
	 *
	 * @param string $source Source id.
	 * @return string Empty for option based sources.
	 */
	private static function table( $source ) {
		global $wpdb;

		$tables = array(
			'rank_math'   => 'rank_math_redirections',
			'redirection' => 'redirection_items',
			'aioseo'      => 'aioseo_redirects',
		);

		return isset( $tables[ $source ] ) ? $wpdb->prefix . $tables[ $source ] : '';
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One batch of raw entries from a source.
	 *
	 * Tables are read with SELECT * on purpose: columns differ between
	 * versions of these plugins, and a missing column then simply reads as
	 * absent instead of breaking the query.
	 *
	 * @param string $source Source id.
	 * @param int    $offset Offset.
	 * @param int    $limit  Limit.
	 * @return array[]
	 */
	private static function entries( $source, $offset, $limit ) {
		global $wpdb;

		if ( 'yoast' === $source ) {
			return array_slice( self::yoast_items(), $offset, $limit );
		}

		$table = self::table( $source );
		if ( '' === $table || ! self::table_exists( $table ) ) {
			return array();
		}

		// Only Rank Math's table has a status column, with trashed rules to skip.
		if ( 'rank_math' === $source ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT * FROM %i WHERE status <> 'trashed' ORDER BY id ASC LIMIT %d OFFSET %d", $table, $limit, $offset ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC LIMIT %d OFFSET %d', $table, $limit, $offset ),
				ARRAY_A
			);
		}
		$rows = is_array( $rows ) ? $rows : array();

		if ( 'aioseo' === $source && $rows ) {
			$rows = self::add_aioseo_hits( $rows );
		}

		return $rows;
	}

	/**
	 * Yoast redirects as one flat list of origin, url, type and format.
	 *
	 * Current versions keep everything in `wpseo-premium-redirects-base` as a
	 * list. Older ones keyed it by origin, or only had the two export
	 * options, so those shapes are read too.
	 *
	 * @return array[]
	 */
	private static function yoast_items() {
		$items = array();
		$base  = get_option( 'wpseo-premium-redirects-base' );

		if ( is_array( $base ) ) {
			foreach ( $base as $key => $value ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				if ( isset( $value['origin'] ) ) {
					$items[] = self::yoast_item( $value['origin'], $value );
				} elseif ( is_string( $key ) && '' !== $key ) {
					$items[] = self::yoast_item( $key, $value );
				}
			}
		}

		// The export options mirror the base option, so only fall back to them.
		if ( ! $items ) {
			foreach ( array( 'plain', 'regex' ) as $format ) {
				$export = get_option( 'wpseo-premium-redirects-export-' . $format );
				if ( ! is_array( $export ) ) {
					continue;
				}
				foreach ( $export as $origin => $value ) {
					if ( is_array( $value ) && '' !== (string) $origin ) {
						$items[] = self::yoast_item( $origin, array_merge( $value, array( 'format' => $format ) ) );
					}
				}
			}
		}

		return $items;
	}

	/**
	 * One Yoast redirect in a fixed shape.
	 *
	 * @param string $origin Origin.
	 * @param array  $value  Redirect data.
	 * @return array
	 */
	private static function yoast_item( $origin, array $value ) {
		return array(
			'origin' => (string) $origin,
			'url'    => (string) ( $value['url'] ?? $value['target'] ?? '' ),
			'type'   => (int) ( $value['type'] ?? 301 ),
			'format' => 'regex' === ( $value['format'] ?? 'plain' ) ? 'regex' : 'plain',
		);
	}

	/**
	 * Adds hit counts to AIOSEO rows. Depending on the version they live in
	 * a column of the main table or in a separate hits table.
	 *
	 * @param array[] $rows Redirect rows.
	 * @return array[]
	 */
	private static function add_aioseo_hits( array $rows ) {
		global $wpdb;

		if ( array_key_exists( 'hits', $rows[0] ) ) {
			return $rows;
		}

		$table = $wpdb->prefix . 'aioseo_redirects_hits';
		if ( ! self::table_exists( $table ) ) {
			return $rows;
		}

		$columns = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! in_array( 'redirect_id', $columns, true ) || ! in_array( 'count', $columns, true ) ) {
			return $rows;
		}

		$ids    = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$counts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT redirect_id, SUM(`count`) AS hits FROM %i WHERE redirect_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY redirect_id',
				array_merge( array( $table ), $ids )
			),
			ARRAY_A
		);

		$hits = array();
		foreach ( (array) $counts as $count ) {
			$hits[ (int) $count['redirect_id'] ] = (int) $count['hits'];
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['hits'] = $hits[ (int) $row['id'] ] ?? 0;
		}

		return $rows;
	}

	/* ------------------------------------------------------------------
	 * Mapping to Tagwright SEO rules
	 * --------------------------------------------------------------- */

	/**
	 * Turns one source entry into rules.
	 *
	 * @param string $source Source id.
	 * @param array  $entry  Raw entry.
	 * @return array[] Rules for Repository::import_rules(), each with a
	 *                 label, or a label and a `skip` reason.
	 */
	public static function map( $source, array $entry ) {
		switch ( $source ) {
			case 'rank_math':
				return self::map_rank_math( $entry );
			case 'yoast':
				return self::map_yoast( $entry );
			case 'redirection':
				return self::map_redirection( $entry );
			case 'aioseo':
				return self::map_aioseo( $entry );
		}

		return array();
	}

	/**
	 * Rank Math: one redirection can list several sources, each with its
	 * own comparison. Every source becomes its own rule.
	 *
	 * @param array $row Table row.
	 * @return array[]
	 */
	private static function map_rank_math( array $row ) {
		$id      = (int) ( $row['id'] ?? 0 );
		$sources = self::unserialize( $row['sources'] ?? '' );

		if ( ! is_array( $sources ) || ! $sources ) {
			return array( self::skip( '#' . $id, __( 'It has no source address.', 'tagwright-seo' ) ) );
		}

		$status = self::status( $row['header_code'] ?? 301 );
		$rules  = array();
		$first  = true;

		foreach ( $sources as $item ) {
			$pattern = is_array( $item ) ? trim( (string) ( $item['pattern'] ?? '' ) ) : '';
			$label   = '#' . $id . ' ' . $pattern;

			if ( '' === $pattern ) {
				continue;
			}

			$mapped = self::pattern(
				$pattern,
				(string) ( $item['comparison'] ?? 'exact' ),
				'case' === ( $item['ignore'] ?? '' ),
				true
			);

			if ( ! $mapped ) {
				$rules[] = self::skip( $label, __( 'Its comparison type is not supported.', 'tagwright-seo' ) );
				continue;
			}

			$rules[] = self::rule(
				$label,
				$mapped,
				(string) ( $row['url_to'] ?? '' ),
				$status,
				'active' === ( $row['status'] ?? 'active' ),
				'Rank Math',
				// The hit counter belongs to the whole redirection, so it goes to the first rule only.
				$first ? (int) ( $row['hits'] ?? 0 ) : 0,
				$first ? self::local_date( $row['last_accessed'] ?? '' ) : ''
			);
			$first = false;
		}

		if ( ! $rules ) {
			$rules[] = self::skip( '#' . $id, __( 'It has no source address.', 'tagwright-seo' ) );
		}

		return $rules;
	}

	/**
	 * Yoast: plain origins are exact rules, regex origins stay regex. Yoast
	 * matches regex redirects without case, so that is kept.
	 *
	 * @param array $item Item from yoast_items().
	 * @return array[]
	 */
	private static function map_yoast( array $item ) {
		$origin = trim( $item['origin'] );
		$regex  = 'regex' === $item['format'];
		$mapped = $regex
			? array( self::regex_source( $origin, true, true ), 'regex' )
			: array( $origin, 'exact' );

		return array( self::rule( $origin, $mapped, $item['url'], self::status( $item['type'] ), true, 'Yoast SEO Premium' ) );
	}

	/**
	 * Redirection: only rules that match on the URL alone and either
	 * redirect or answer with an error code. Rules that depend on the
	 * visitor (login state, referrer, cookies and so on) have no equivalent.
	 *
	 * @param array $row Table row.
	 * @return array[]
	 */
	private static function map_redirection( array $row ) {
		$url   = trim( (string) ( $row['url'] ?? '' ) );
		$label = '#' . (int) ( $row['id'] ?? 0 ) . ' ' . $url;
		$match = (string) ( $row['match_type'] ?? 'url' );
		$type  = (string) ( $row['action_type'] ?? 'url' );
		$code  = (int) ( $row['action_code'] ?? 301 );

		if ( 'url' !== $match ) {
			return array(
				self::skip(
					$label,
					/* translators: %s: Redirection match type such as "login" or "referrer". */
					sprintf( __( 'It matches on "%s", which Tagwright SEO does not support.', 'tagwright-seo' ), $match )
				),
			);
		}

		if ( 'url' === $type ) {
			$target = trim( (string) ( $row['action_data'] ?? '' ) );
			// Other match types keep structured data here; a plain target never starts like this.
			if ( preg_match( '/^(\{|\[|a:\d+:)/', $target ) ) {
				return array( self::skip( $label, __( 'Its target could not be read.', 'tagwright-seo' ) ) );
			}
		} elseif ( 'error' === $type ) {
			$target = '';
			if ( ! in_array( $code, Repository::gone_statuses(), true ) ) {
				return array(
					self::skip(
						$label,
						/* translators: %d: HTTP status code. */
						sprintf( __( 'It answers with a %d error, which Tagwright SEO does not create.', 'tagwright-seo' ), $code )
					),
				);
			}
		} else {
			return array(
				self::skip(
					$label,
					/* translators: %s: Redirection action type such as "random" or "pass". */
					sprintf( __( 'Its action "%s" is not supported.', 'tagwright-seo' ), $type )
				),
			);
		}

		$data   = json_decode( (string) ( $row['match_data'] ?? '' ), true );
		$nocase = is_array( $data ) && ! empty( $data['source']['flag_case'] );
		$mapped = ! empty( $row['regex'] )
			? array( self::regex_source( $url, false, $nocase ), 'regex' )
			: array( $url, 'exact' );

		return array(
			self::rule(
				$label,
				$mapped,
				$target,
				self::status( $code ),
				'disabled' !== ( $row['status'] ?? 'enabled' ),
				'Redirection',
				(int) ( $row['last_count'] ?? 0 ),
				self::local_date( $row['last_access'] ?? '' )
			),
		);
	}

	/**
	 * All in One SEO Pro.
	 *
	 * @param array $row Table row.
	 * @return array[]
	 */
	private static function map_aioseo( array $row ) {
		$url    = trim( (string) ( $row['source_url'] ?? '' ) );
		$label  = '#' . (int) ( $row['id'] ?? 0 ) . ' ' . $url;
		$mapped = ! empty( $row['regex'] )
			? array( self::regex_source( $url, true, ! empty( $row['ignore_case'] ) ), 'regex' )
			: array( $url, 'exact' );

		return array(
			self::rule(
				$label,
				$mapped,
				(string) ( $row['target_url'] ?? '' ),
				self::status( $row['type'] ?? 301 ),
				! isset( $row['enabled'] ) || ! empty( $row['enabled'] ),
				'All in One SEO',
				(int) ( $row['hits'] ?? 0 )
			),
		);
	}

	/**
	 * Maps a pattern and its comparison to a source and match type.
	 *
	 * "Contains" and "ends with" have no match type of their own, so they
	 * become anchored regex rules with the text quoted.
	 *
	 * @param string $pattern     Pattern.
	 * @param string $comparison  exact, start, regex, contains or end.
	 * @param bool   $ignore_case Match without case.
	 * @param bool   $slashless   The plugin matched against paths without a leading slash.
	 * @return array{0: string, 1: string}|null Source and match type.
	 */
	public static function pattern( $pattern, $comparison, $ignore_case, $slashless ) {
		$flag = $ignore_case ? '(?i)' : '';

		switch ( $comparison ) {
			case 'exact':
				return array( $pattern, 'exact' );

			case 'start':
				return array( $pattern, 'prefix' );

			case 'regex':
				return array( self::regex_source( $pattern, $slashless, $ignore_case ), 'regex' );

			case 'contains':
				return array( $flag . '.*' . preg_quote( $pattern ) . '.*', 'regex' );

			case 'end':
				// Tagwright SEO compares paths without their trailing slash.
				$end = rtrim( $pattern, '/' );
				return array( $flag . preg_quote( '' === $end ? $pattern : $end ) . '$', 'regex' );
		}

		return null;
	}

	/**
	 * Adapts a regex written for another plugin.
	 *
	 * Tagwright SEO matches regex rules against the path with its leading slash.
	 * Rank Math, Yoast and AIOSEO match without it, so a pattern anchored
	 * with ^ gets the slash added, otherwise "^blog/(.*)" would never match.
	 * Case-insensitive rules get an inline (?i), which works whatever the
	 * site-wide case setting is.
	 *
	 * @param string $pattern     Pattern without delimiters.
	 * @param bool   $slashless   Add the leading slash after ^.
	 * @param bool   $ignore_case Match without case.
	 * @return string
	 */
	public static function regex_source( $pattern, $slashless, $ignore_case ) {
		$pattern = trim( (string) $pattern );

		if ( $slashless && preg_match( '#^\^(?!/|\\\\/)#', $pattern ) ) {
			$pattern = '^/' . substr( $pattern, 1 );
		}

		if ( $ignore_case && 0 !== strpos( $pattern, '(?i)' ) ) {
			$pattern = '(?i)' . $pattern;
		}

		return $pattern;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	/**
	 * Builds a rule for Repository::import_rules().
	 *
	 * @param string $label    Shown in skip messages.
	 * @param array  $mapped   Source and match type.
	 * @param string $target   Target.
	 * @param int    $status   Status code.
	 * @param bool   $enabled  Active.
	 * @param string $from     Plugin name for the note.
	 * @param int    $hits     Hits so far.
	 * @param string $last_hit Last hit, GMT.
	 * @return array
	 */
	private static function rule( $label, array $mapped, $target, $status, $enabled, $from, $hits = 0, $last_hit = '' ) {
		return array(
			'label'      => $label,
			'source'     => $mapped[0],
			'match_type' => $mapped[1],
			'target'     => $target,
			'status'     => $status,
			'enabled'    => $enabled,
			/* translators: %s: plugin name. */
			'notes'      => sprintf( __( 'Imported from %s', 'tagwright-seo' ), $from ),
			'hits'       => max( 0, $hits ),
			'last_hit'   => $last_hit,
		);
	}

	/**
	 * A skipped entry.
	 *
	 * @param string $label  Label.
	 * @param string $reason Why.
	 * @return array
	 */
	private static function skip( $label, $reason ) {
		return array(
			'label' => $label,
			'skip'  => $reason,
		);
	}

	/**
	 * Formats a skip message.
	 *
	 * @param string $label   Which entry.
	 * @param string $message Why.
	 * @return string
	 */
	private static function message( $label, $message ) {
		$label = trim( (string) $label );
		if ( strlen( $label ) > 80 ) {
			$label = substr( $label, 0, 77 ) . '…';
		}

		/* translators: 1: redirect id and address in the other plugin, 2: reason it was skipped. */
		return '' === $label ? $message : sprintf( __( '%1$s: %2$s', 'tagwright-seo' ), $label, $message );
	}

	/**
	 * Status code from another plugin. 308 is the permanent sibling of 307,
	 * so it is treated as a 301; anything else unknown fails validation and
	 * is reported as skipped.
	 *
	 * @param mixed $code Code.
	 * @return int
	 */
	private static function status( $code ) {
		$code = (int) $code;

		return 308 === $code ? 301 : $code;
	}

	/**
	 * Converts a site-time MySQL date (how Rank Math and Redirection store
	 * them) to GMT, which is what Tagwright SEO stores.
	 *
	 * @param mixed $date Date.
	 * @return string Empty when unset or invalid.
	 */
	private static function local_date( $date ) {
		$date = (string) $date;

		if ( ! preg_match( '/^(\d{4})-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date, $year ) || (int) $year[1] < 1990 ) {
			return '';
		}

		return get_gmt_from_date( $date );
	}

	/**
	 * Unserializes data from another plugin's table without allowing
	 * objects, so a tampered row can't instantiate classes.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function unserialize( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Objects are refused, a broken string just reads as false.
		return @unserialize( trim( $value ), array( 'allowed_classes' => false ) );
	}
}
