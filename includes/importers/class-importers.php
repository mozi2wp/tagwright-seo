<?php
/**
 * Importer registry.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the available importers.
 */
class Importers {

	/**
	 * All importers keyed by id.
	 *
	 * @return Importer[]
	 */
	public static function all() {
		$importers = array(
			'yoast'     => new Yoast(),
			'rank_math' => new Rank_Math(),
			'aioseo'    => new Aioseo(),
		);

		/**
		 * Filters the importers. Each must extend TagwrightSEO\Importers\Importer.
		 *
		 * @param Importer[] $importers Importers keyed by id.
		 */
		return array_filter(
			apply_filters( 'tagwright_seo_importers', $importers ),
			static fn( $importer ) => $importer instanceof Importer
		);
	}

	/**
	 * One importer.
	 *
	 * @param string $id Importer id.
	 * @return Importer|null
	 */
	public static function get( $id ) {
		$all = self::all();

		return $all[ $id ] ?? null;
	}
}
