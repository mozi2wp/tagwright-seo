<?php
/**
 * Dashboard tile and widget.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

use TagwrightSEO\Admin\Icons;
use TagwrightSEO\Data\Stats;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "404 errors" KPI tile and a "Top 404s" widget to the dashboard.
 */
class Dashboard {

	/**
	 * Adds the 404 series and KPI tile to the dashboard data.
	 *
	 * @param array $data Dashboard data.
	 * @param int   $days Days in the selected range.
	 * @return array
	 */
	public static function data( $data, $days ) {
		$errors = Stats::series( '404s', $days, 'redirects' );
		$hits   = Stats::series( 'redirect_hits', $days, 'redirects' );

		$data['redirects'] = array(
			'404s'          => $errors,
			'redirect_hits' => $hits,
		);

		$spark = wp_list_pluck( $errors, 'value' );
		$total = (int) array_sum( array_map( 'floatval', array_filter( $spark, 'is_numeric' ) ) );

		$data['kpis'][] = array(
			'label' => __( '404 errors', 'tagwright-seo' ),
			'icon'  => 'alert',
			'value' => Options::get( 'redirects_log_404' ) ? number_format_i18n( $total ) : '–',
			'spark' => $spark,
			'url'   => admin_url( 'admin.php?page=' . Admin_Page::SLUG . '&view=404' ),
		);

		return $data;
	}

	/**
	 * Registers the side widget.
	 *
	 * @param array $widgets Widgets.
	 * @return array
	 */
	public static function widgets( $widgets ) {
		$widgets['redirects_top_404s'] = array(
			'title'    => __( 'Top 404s', 'tagwright-seo' ),
			'subtitle' => __( 'Missing addresses people hit most. Redirect the ones that used to be real pages.', 'tagwright-seo' ),
			'icon'     => 'alert',
			'area'     => 'side',
			'priority' => 20,
			'render'   => array( __CLASS__, 'render_widget' ),
		);

		return $widgets;
	}

	/**
	 * Prints the widget body.
	 *
	 * @return void
	 */
	public static function render_widget() {
		$rows = Log::top( 5 );
		$url  = admin_url( 'admin.php?page=' . Admin_Page::SLUG . '&view=404' );

		if ( ! $rows ) {
			?>
			<div class="tagwright-empty">
				<?php Icons::render( 'check-circle', 32 ); ?>
				<h3><?php esc_html_e( 'No 404s logged', 'tagwright-seo' ); ?></h3>
				<p>
					<?php
					echo Options::get( 'redirects_log_404' )
						? esc_html__( 'Nobody has hit a missing page lately.', 'tagwright-seo' )
						: esc_html__( 'The 404 log is switched off in the Redirects settings.', 'tagwright-seo' );
					?>
				</p>
			</div>
			<?php
			return;
		}
		?>
		<table class="tagwright-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Address', 'tagwright-seo' ); ?></th>
					<th class="is-num"><?php esc_html_e( 'Hits', 'tagwright-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row['url'] ); ?></code></td>
						<td class="is-num"><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin:14px 0 0">
			<a class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open the 404 log', 'tagwright-seo' ); ?><?php Icons::render( 'arrow-right', 16 ); ?></a>
		</p>
		<?php
	}
}
