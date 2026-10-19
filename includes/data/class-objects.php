<?php
/**
 * Access to the tagwright_objects table.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Data;

use TagwrightSEO\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Small repository around the objects table.
 *
 * One row holds every SEO field for a post, term or user. Reading a page's
 * SEO data is therefore a single primary-key lookup instead of a dozen
 * postmeta rows, and sitemaps can be built with one indexed query.
 */
class Objects {

	/**
	 * Object cache group.
	 */
	const CACHE_GROUP = 'tagwright_seo_objects';

	/**
	 * Fields an editor can change from the meta box, term screen or profile.
	 *
	 * Values are the $wpdb format placeholders.
	 *
	 * @var array<string, string>
	 */
	const EDITABLE_FIELDS = array(
		'title'               => '%s',
		'description'         => '%s',
		'meta_keywords'       => '%s',
		'focus_keyphrase'     => '%s',
		'canonical'           => '%s',
		'robots_noindex'      => '%d',
		'robots_nofollow'     => '%d',
		'robots_noarchive'    => '%d',
		'robots_nosnippet'    => '%d',
		'robots_noimageindex' => '%d',
		'max_snippet'         => '%d',
		'max_image_preview'   => '%s',
		'max_video_preview'   => '%d',
		'og_title'            => '%s',
		'og_description'      => '%s',
		'og_image_id'         => '%d',
		'og_image_url'        => '%s',
		'twitter_title'       => '%s',
		'twitter_description' => '%s',
		'schema_page_type'    => '%s',
		'schema_article_type' => '%s',
		'seo_score'           => '%d',
		'readability_score'   => '%d',
		'sitemap_exclude'     => '%d',
		'sitemap_priority'    => '%f',
		'sitemap_changefreq'  => '%s',
	);

	/**
	 * Fields that Tagwright SEO maintains itself from WordPress data.
	 *
	 * @var array<string, string>
	 */
	const SYSTEM_FIELDS = array(
		'object_sub_type' => '%s',
		'author_id'       => '%d',
		'post_status'     => '%s',
		'is_public'       => '%d',
		'permalink'       => '%s',
		'permalink_hash'  => '%s',
		'images'          => '%s',
		'extra'           => '%s',
		'comment_count'   => '%d',
		'published_at'    => '%s',
		'modified_at'     => '%s',
	);

	/**
	 * Fields that may be stored as NULL to mean "use the default".
	 *
	 * @var string[]
	 */
	const NULLABLE_FIELDS = array(
		'robots_noindex',
		'robots_nofollow',
		'robots_noarchive',
		'robots_nosnippet',
		'robots_noimageindex',
		'max_snippet',
		'max_image_preview',
		'max_video_preview',
		'seo_score',
		'readability_score',
		'sitemap_priority',
		'sitemap_changefreq',
		'published_at',
		'modified_at',
	);

	/**
	 * Supported object types.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'post', 'term', 'user' );

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Installer::get_table_names()['objects'];
	}

	/**
	 * Fetches the row for an object.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return array|null
	 */
	public static function get( $type, $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 || ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}

		$cache_key = $type . ':' . $id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );
		if ( $found ) {
			return $cached ? $cached : null;
		}

		$table = self::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table, cached above.
			$wpdb->prepare( 'SELECT * FROM %i WHERE object_type = %s AND object_id = %d', $table, $type, $id ),
			ARRAY_A
		);

		$row = $row ? self::hydrate( $row ) : null;

		// Cache misses too, so pages without a row do not hit the database repeatedly.
		wp_cache_set( $cache_key, $row ? $row : 0, self::CACHE_GROUP );

		return $row;
	}

	/**
	 * Fetches several objects of one type with a single query and primes the cache.
	 *
	 * Handy on archive pages and in the admin list tables.
	 *
	 * @param string $type Object type.
	 * @param int[]  $ids  Object ids.
	 * @return array[] Rows keyed by object id.
	 */
	public static function get_many( $type, array $ids ) {
		global $wpdb;

		$ids = array_filter( array_unique( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) || ! in_array( $type, self::TYPES, true ) ) {
			return array();
		}

		$rows    = array();
		$missing = array();

		foreach ( $ids as $id ) {
			$cached = wp_cache_get( $type . ':' . $id, self::CACHE_GROUP, false, $found );
			if ( $found ) {
				if ( $cached ) {
					$rows[ $id ] = $cached;
				}
			} else {
				$missing[] = $id;
			}
		}

		if ( $missing ) {
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT * FROM %i WHERE object_type = %s AND object_id IN (' . implode( ',', array_fill( 0, count( $missing ), '%d' ) ) . ')',
					array_merge( array( self::table(), $type ), $missing )
				),
				ARRAY_A
			);

			$found_ids = array();
			foreach ( (array) $results as $row ) {
				$row                 = self::hydrate( $row );
				$object_id           = (int) $row['object_id'];
				$rows[ $object_id ]  = $row;
				$found_ids[]         = $object_id;
				wp_cache_set( $type . ':' . $object_id, $row, self::CACHE_GROUP );
			}

			foreach ( array_diff( $missing, $found_ids ) as $id ) {
				wp_cache_set( $type . ':' . $id, 0, self::CACHE_GROUP );
			}
		}

		return $rows;
	}

	/**
	 * Inserts or updates an object row.
	 *
	 * Only the fields passed in are touched, so the meta box and the sync
	 * code can each write their own part without clobbering the other.
	 *
	 * @param string $type   Object type.
	 * @param int    $id     Object id.
	 * @param array  $fields Column values.
	 * @return bool
	 */
	public static function save( $type, $id, array $fields ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 || ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}

		$allowed = array_merge( self::EDITABLE_FIELDS, self::SYSTEM_FIELDS );
		$data    = array();

		foreach ( $fields as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			if ( in_array( $key, array( 'images', 'extra' ), true ) && is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}

			if ( 'permalink' === $key ) {
				$data['permalink_hash'] = $value ? sha1( $value ) : '';
			}

			$data[ $key ] = $value;
		}

		if ( empty( $data ) ) {
			return false;
		}

		$now                = current_time( 'mysql', true );
		$data['updated_at'] = $now;
		$table              = self::table();

		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT id FROM %i WHERE object_type = %s AND object_id = %d', $table, $type, $id )
		);

		if ( $exists ) {
			$result = self::write( 'update', $data, array( 'id' => (int) $exists ) );
		} else {
			$data['object_type'] = $type;
			$data['object_id']   = $id;
			$data['created_at']  = $now;
			$result              = self::write( 'insert', $data );
		}

		self::clean_cache( $type, $id );

		/**
		 * Fires after an object row is saved.
		 *
		 * @param string $type   Object type.
		 * @param int    $id     Object id.
		 * @param array  $fields Fields that were written.
		 */
		do_action( 'tagwright_seo_object_saved', $type, $id, $data );

		return false !== $result;
	}

	/**
	 * Runs an insert or update, writing real NULLs where needed.
	 *
	 * $wpdb->insert() and update() turn PHP null into an empty string or zero
	 * for numeric formats, which would lose the "inherit" meaning of NULL. So
	 * nulls are written with a follow-up query instead.
	 *
	 * @param string $mode  `insert` or `update`.
	 * @param array  $data  Column values.
	 * @param array  $where Where clause for updates.
	 * @return int|false
	 */
	private static function write( $mode, array $data, array $where = array() ) {
		global $wpdb;

		$table   = self::table();
		$formats = array_merge(
			self::EDITABLE_FIELDS,
			self::SYSTEM_FIELDS,
			array(
				'object_type'    => '%s',
				'object_id'      => '%d',
				'permalink_hash' => '%s',
				'created_at'     => '%s',
				'updated_at'     => '%s',
			)
		);

		$nulls  = array();
		$values = array();
		foreach ( $data as $key => $value ) {
			if ( null === $value ) {
				$nulls[] = $key;
				continue;
			}
			$values[ $key ] = $value;
		}

		$value_formats = array();
		foreach ( array_keys( $values ) as $key ) {
			$value_formats[] = $formats[ $key ] ?? '%s';
		}

		if ( 'insert' === $mode ) {
			$result = $wpdb->insert( $table, $values, $value_formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row_id = (int) $wpdb->insert_id;
		} else {
			$result = $wpdb->update( $table, $values, $where, $value_formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row_id = (int) $where['id'];
		}

		if ( $nulls && $row_id && false !== $result ) {
			// Column names come from the whitelists above, never from user input.
			// Usually only one or two columns, so one small query each is fine.
			foreach ( $nulls as $column ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = NULL WHERE id = %d', $table, $column, $row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		return $result;
	}

	/**
	 * Deletes an object row.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return void
	 */
	public static function delete( $type, $id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'object_type' => $type, 'object_id' => (int) $id ), array( '%s', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::clean_cache( $type, $id );

		/**
		 * Fires after an object row is removed.
		 *
		 * @param string $type Object type.
		 * @param int    $id   Object id.
		 */
		do_action( 'tagwright_seo_object_deleted', $type, (int) $id );
	}

	/**
	 * Reads one value from the JSON `extra` column. Add-ons store their
	 * per-object settings here instead of adding columns.
	 *
	 * @param string $type     Object type.
	 * @param int    $id       Object id.
	 * @param string $key      Key inside the extra data.
	 * @param mixed  $fallback Returned when the key is missing.
	 * @return mixed
	 */
	public static function get_extra( $type, $id, $key, $fallback = null ) {
		$row = self::get( $type, $id );

		return $row['extra'][ $key ] ?? $fallback;
	}

	/**
	 * Writes one value into the JSON `extra` column.
	 *
	 * @param string $type  Object type.
	 * @param int    $id    Object id.
	 * @param string $key   Key inside the extra data.
	 * @param mixed  $value Value to store. Null removes the key.
	 * @return bool
	 */
	public static function set_extra( $type, $id, $key, $value ) {
		$row   = self::get( $type, $id );
		$extra = $row['extra'] ?? array();

		if ( null === $value ) {
			unset( $extra[ $key ] );
		} else {
			$extra[ $key ] = $value;
		}

		return self::save( $type, $id, array( 'extra' => $extra ) );
	}

	/**
	 * Bumps the last-modified date of a term or user row, used so sitemaps
	 * show when an archive last changed.
	 *
	 * @param string $type     `term` or `user`.
	 * @param int[]  $ids      Object ids.
	 * @param string $modified MySQL GMT date.
	 * @return void
	 */
	public static function touch( $type, array $ids, $modified ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE %i SET modified_at = %s WHERE object_type = %s AND object_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND ( modified_at IS NULL OR modified_at < %s )',
				array_merge( array( self::table(), $modified, $type ), $ids, array( $modified ) )
			)
		);

		foreach ( $ids as $id ) {
			self::clean_cache( $type, $id );
		}
	}

	/**
	 * Removes a row from the object cache.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return void
	 */
	public static function clean_cache( $type, $id ) {
		wp_cache_delete( $type . ':' . (int) $id, self::CACHE_GROUP );
		wp_cache_set( 'last_changed', microtime(), self::CACHE_GROUP );
	}

	/**
	 * Counts rows, optionally for one object type.
	 *
	 * @param string $type Optional object type.
	 * @return int
	 */
	public static function count( $type = '' ) {
		global $wpdb;

		$table = self::table();

		if ( $type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE object_type = %s', $table, $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Empties the table. Used by the "rebuild index" tool.
	 *
	 * Only system columns are rebuilt from WordPress, so this is never called
	 * on its own; the caller reindexes straight after.
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		} else {
			wp_cache_flush();
		}
	}

	/**
	 * Casts a raw database row into friendly PHP types.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function hydrate( array $row ) {
		foreach ( array( 'id', 'object_id', 'author_id', 'og_image_id', 'is_public', 'sitemap_exclude', 'comment_count' ) as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}

		foreach ( self::NULLABLE_FIELDS as $key ) {
			if ( ! isset( $row[ $key ] ) ) {
				$row[ $key ] = null;
				continue;
			}
			if ( 'sitemap_priority' === $key ) {
				$row[ $key ] = round( (float) $row[ $key ], 1 );
			} elseif ( ! in_array( $key, array( 'max_image_preview', 'sitemap_changefreq', 'published_at', 'modified_at' ), true ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}

		$row['images'] = $row['images'] ? json_decode( $row['images'], true ) : array();
		$row['extra']  = $row['extra'] ? json_decode( $row['extra'], true ) : array();

		$row['images'] = is_array( $row['images'] ) ? $row['images'] : array();
		$row['extra']  = is_array( $row['extra'] ) ? $row['extra'] : array();

		return $row;
	}
}
