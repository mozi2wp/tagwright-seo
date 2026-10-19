<?php
/**
 * SEO column and filters in the posts list.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Data\Objects;
use TagwrightSEO\Data\Stats;
use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Adds score badges, a score filter and sorting to post list tables.
 *
 * Filtering and sorting join our own table on its primary lookup key, so
 * they stay quick even with thousands of posts.
 */
class Post_Columns {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'restrict_manage_posts', array( $this, 'filter_dropdown' ), 20 );
		add_filter( 'posts_clauses', array( $this, 'query_clauses' ), 10, 2 );
	}

	/**
	 * Adds the column for supported post types.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( ! Metabox::is_supported( $post_type ) ) {
				continue;
			}
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'sortable' ) );
		}
	}

	/**
	 * Adds the column header.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['tagwright_seo'] = '<span title="' . esc_attr__( 'SEO score and readability', 'tagwright-seo' ) . '">' . esc_html__( 'SEO', 'tagwright-seo' ) . '</span>';

		return $columns;
	}

	/**
	 * Makes the column sortable by SEO score.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable( $columns ) {
		$columns['tagwright_seo'] = 'tagwright_score';

		return $columns;
	}

	/**
	 * Prints the badges for one row.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		global $wp_query;

		if ( 'tagwright_seo' !== $column ) {
			return;
		}

		// Load every row on the page with one query the first time through.
		static $primed = false;
		if ( ! $primed && $wp_query && ! empty( $wp_query->posts ) ) {
			Objects::get_many( 'post', wp_list_pluck( $wp_query->posts, 'ID' ) );
			$primed = true;
		}

		$row = Objects::get( 'post', $post_id );

		echo '<span class="tagwright-list-scores tagwright-scope">';
		if ( Modules::is_active( 'content_analysis' ) ) {
			echo UI::score_badge( $row['seo_score'] ?? null, __( 'SEO score', 'tagwright-seo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			if ( null !== ( $row['readability_score'] ?? null ) ) {
				echo UI::score_badge( $row['readability_score'], __( 'Readability score', 'tagwright-seo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			}
		}
		if ( isset( $row['robots_noindex'] ) && 1 === $row['robots_noindex'] ) {
			echo '<span class="tagwright-badge tagwright-badge--muted">' . esc_html__( 'noindex', 'tagwright-seo' ) . '</span>';
		}
		echo '</span>';
	}

	/**
	 * Adds the score filter above the list.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public function filter_dropdown( $post_type ) {
		if ( ! Metabox::is_supported( $post_type ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$current = isset( $_GET['tagwright_filter'] ) ? sanitize_key( wp_unslash( $_GET['tagwright_filter'] ) ) : '';
		$options = self::filters();
		?>
		<label for="tagwright-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by SEO', 'tagwright-seo' ); ?></label>
		<select name="tagwright_filter" id="tagwright-filter">
			<option value=""><?php esc_html_e( 'All SEO scores', 'tagwright-seo' ); ?></option>
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Available filters.
	 *
	 * @return array<string, string>
	 */
	public static function filters() {
		return array(
			'good'         => __( 'SEO: good', 'tagwright-seo' ),
			'ok'           => __( 'SEO: needs improvement', 'tagwright-seo' ),
			'poor'         => __( 'SEO: poor', 'tagwright-seo' ),
			'none'         => __( 'SEO: not analyzed', 'tagwright-seo' ),
			'read_poor'    => __( 'Readability: poor', 'tagwright-seo' ),
			'no_keyphrase' => __( 'No focus keyphrase', 'tagwright-seo' ),
			'no_desc'      => __( 'No custom description', 'tagwright-seo' ),
			'noindex'      => __( 'Hidden from search (noindex)', 'tagwright-seo' ),
		);
	}

	/**
	 * Applies the filter and sorting to the list query.
	 *
	 * @param array     $clauses SQL clauses.
	 * @param \WP_Query $query   Query.
	 * @return array
	 */
	public function query_clauses( $clauses, $query ) {
		global $wpdb;

		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $clauses;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$filter  = isset( $_GET['tagwright_filter'] ) ? sanitize_key( wp_unslash( $_GET['tagwright_filter'] ) ) : '';
		$orderby = $query->get( 'orderby' );
		// phpcs:enable

		if ( '' === $filter && 'tagwright_score' !== $orderby ) {
			return $clauses;
		}

		$table             = Objects::table();
		$clauses['join']  .= " LEFT JOIN {$table} AS tagwright ON ( tagwright.object_type = 'post' AND tagwright.object_id = {$wpdb->posts}.ID )";

		$conditions = array(
			'good'         => 'tagwright.seo_score >= ' . Stats::GOOD,
			'ok'           => '( tagwright.seo_score >= ' . Stats::OK . ' AND tagwright.seo_score < ' . Stats::GOOD . ' )',
			'poor'         => 'tagwright.seo_score < ' . Stats::OK,
			'none'         => 'tagwright.seo_score IS NULL',
			'read_poor'    => 'tagwright.readability_score < ' . Stats::OK,
			'no_keyphrase' => "( tagwright.focus_keyphrase IS NULL OR tagwright.focus_keyphrase = '' )",
			'no_desc'      => "( tagwright.description IS NULL OR tagwright.description = '' )",
			'noindex'      => str_replace( array( 'robots_noindex', 'object_sub_type' ), array( 'tagwright.robots_noindex', 'tagwright.object_sub_type' ), Stats::noindex_condition() ),
		);

		if ( isset( $conditions[ $filter ] ) ) {
			$clauses['where'] .= ' AND ' . $conditions[ $filter ];
		}

		if ( 'tagwright_score' === $orderby ) {
			$order              = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
			$clauses['orderby'] = "tagwright.seo_score {$order}, {$wpdb->posts}.post_date DESC";
		}

		return $clauses;
	}
}
