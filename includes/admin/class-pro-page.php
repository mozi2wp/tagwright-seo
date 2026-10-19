<?php
/**
 * Tagwright SEO Pro screen.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Addons;
use TagwrightSEO\Features\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * A plain comparison of the free plugin and the Pro plans.
 *
 * This page is the only place Tagwright SEO talks about Pro. There are no
 * dashboard-wide notices and nothing in the free plugin is locked; the
 * features listed under Pro simply aren't part of this plugin.
 */
class Pro_Page {

	/**
	 * Where people will be able to buy Pro. Empty until the website is live,
	 * which turns the buttons into a "Coming soon" label.
	 *
	 * @return string
	 */
	public static function store_url() {
		/**
		 * Filters the Tagwright SEO Pro store URL.
		 *
		 * @param string $url Store URL, empty while Pro is not on sale.
		 */
		return (string) apply_filters( 'tagwright_seo_pro_url', '' );
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public static function render() {
		$pro_active = Addons::is_active( 'pro' );
		$plans      = Addons::plans();
		$catalogue  = Addons::catalogue();
		$store      = self::store_url();
		?>
		<div class="tagwright-app">
			<?php UI::header( 'tagwright-seo-pro' ); ?>
			<div class="tagwright-page">
				<div class="tagwright-pro-hero">
					<div class="tagwright-pro-hero__text">
						<span class="tagwright-badge tagwright-badge--pro"><?php esc_html_e( 'Tagwright SEO Pro', 'tagwright-seo' ); ?></span>
						<?php if ( $pro_active ) : ?>
							<h1><?php esc_html_e( 'Pro is active. Thank you!', 'tagwright-seo' ); ?></h1>
							<p><?php esc_html_e( 'Every Pro module shows up under Settings and Modules, next to the free ones.', 'tagwright-seo' ); ?></p>
							<?php
							/**
							 * Fires inside the Pro screen's header while Tagwright SEO Pro
							 * is active, so it can show the licence and plan.
							 */
							do_action( 'tagwright_seo_pro_page_status' );
							?>
						<?php else : ?>
							<h1><?php esc_html_e( 'More tools for sites that grow', 'tagwright-seo' ); ?></h1>
							<p><?php esc_html_e( 'The free plugin is complete on its own and stays free. Pro is a separate plugin that adds deeper analysis, Search Console data, site audits, more schema and tools for shops, local businesses and agencies. Same fast tables, same modules you can switch off.', 'tagwright-seo' ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( ! $pro_active ) : ?>
						<div class="tagwright-pro-hero__action">
							<?php self::cta( $store, true ); ?>
						</div>
					<?php endif; ?>
				</div>

				<div class="tagwright-plans">
					<?php foreach ( $plans as $id => $plan ) : ?>
						<div class="tagwright-plan<?php echo 'business' === $id ? ' is-featured' : ''; ?>">
							<?php if ( 'business' === $id ) : ?>
								<span class="tagwright-plan__flag"><?php esc_html_e( 'Most popular', 'tagwright-seo' ); ?></span>
							<?php endif; ?>
							<h3><?php echo esc_html( $plan['name'] ); ?></h3>
							<p class="tagwright-plan__sites"><?php echo esc_html( $plan['sites'] ); ?></p>
							<p><?php echo esc_html( $plan['description'] ); ?></p>
							<?php if ( 'free' === $id ) : ?>
								<span class="tagwright-badge tagwright-badge--good"><?php esc_html_e( 'You have this', 'tagwright-seo' ); ?></span>
							<?php elseif ( ! $pro_active ) : ?>
								<?php self::cta( $store ? add_query_arg( 'plan', $id, $store ) : '', false ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="tagwright-card">
					<div class="tagwright-card__header">
						<div>
							<h2 class="tagwright-card__title"><?php esc_html_e( 'Compare plans', 'tagwright-seo' ); ?></h2>
							<p class="tagwright-card__subtitle"><?php esc_html_e( 'Paid plans differ only in the number of sites, except Agency Tools.', 'tagwright-seo' ); ?></p>
						</div>
					</div>
					<div class="tagwright-card__body tagwright-compare-wrap">
						<table class="tagwright-table tagwright-compare">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Feature', 'tagwright-seo' ); ?></th>
									<?php foreach ( $plans as $plan ) : ?>
										<th scope="col" class="is-center"><?php echo esc_html( $plan['name'] ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<tr class="tagwright-compare__group"><th colspan="<?php echo esc_attr( count( $plans ) + 1 ); ?>" scope="rowgroup"><?php esc_html_e( 'In the free plugin', 'tagwright-seo' ); ?></th></tr>
								<?php foreach ( self::free_rows() as $label ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $label ); ?></th>
										<?php foreach ( array_keys( $plans ) as $plan ) : ?>
											<td class="is-center"><?php self::mark( true ); ?></td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>

								<tr class="tagwright-compare__group"><th colspan="<?php echo esc_attr( count( $plans ) + 1 ); ?>" scope="rowgroup"><?php esc_html_e( 'Added by Pro', 'tagwright-seo' ); ?></th></tr>
								<?php foreach ( $catalogue as $addon ) : ?>
									<tr>
										<th scope="row">
											<?php echo esc_html( $addon['name'] ); ?>
											<span class="tagwright-compare__hint"><?php echo esc_html( $addon['description'] ); ?></span>
										</th>
										<?php foreach ( array_keys( $plans ) as $plan ) : ?>
											<td class="is-center"><?php self::mark( self::plan_includes( $plan, $addon['plan'] ) ); ?></td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>

								<tr class="tagwright-compare__group"><th colspan="<?php echo esc_attr( count( $plans ) + 1 ); ?>" scope="rowgroup"><?php esc_html_e( 'Support and updates', 'tagwright-seo' ); ?></th></tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Updates', 'tagwright-seo' ); ?></th>
									<td class="is-center"><?php esc_html_e( 'WordPress.org', 'tagwright-seo' ); ?></td>
									<td class="is-center" colspan="3"><?php esc_html_e( 'Automatic, for as long as your licence is active', 'tagwright-seo' ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Support', 'tagwright-seo' ); ?></th>
									<td class="is-center"><?php esc_html_e( 'Community forum', 'tagwright-seo' ); ?></td>
									<td class="is-center" colspan="3"><?php esc_html_e( 'Priority email support', 'tagwright-seo' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Rows for the free part of the comparison: the core features plus the
	 * built-in feature modules.
	 *
	 * @return string[]
	 */
	private static function free_rows() {
		$rows = array(
			__( 'Titles, descriptions and robots for every content type', 'tagwright-seo' ),
			__( 'Social cards (Open Graph and X)', 'tagwright-seo' ),
			__( 'Schema graph', 'tagwright-seo' ),
			__( 'XML sitemaps with priority, change frequency and last modified', 'tagwright-seo' ),
			__( 'Content and readability analysis', 'tagwright-seo' ),
			__( 'Dashboard with trends', 'tagwright-seo' ),
			__( 'Import from Yoast SEO, Rank Math and AIOSEO', 'tagwright-seo' ),
			__( 'robots.txt and llms.txt', 'tagwright-seo' ),
		);

		foreach ( Registry::modules() as $module ) {
			$rows[] = $module['title'];
		}

		return $rows;
	}

	/**
	 * Whether a plan includes a module that needs `$needed` or better.
	 *
	 * @param string $plan   Plan id.
	 * @param string $needed Lowest plan that includes the module.
	 * @return bool
	 */
	private static function plan_includes( $plan, $needed ) {
		$order = array( 'free', 'pro', 'business', 'agency' );

		return array_search( $plan, $order, true ) >= array_search( $needed, $order, true );
	}

	/**
	 * Prints a tick or a dash for the comparison table.
	 *
	 * @param bool $included Whether the plan includes the row.
	 * @return void
	 */
	private static function mark( $included ) {
		if ( $included ) {
			echo '<span class="tagwright-compare__yes">';
			Icons::render( 'check', 18 );
			echo '<span class="screen-reader-text">' . esc_html__( 'Included', 'tagwright-seo' ) . '</span></span>';
			return;
		}

		echo '<span class="tagwright-compare__no" aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'Not included', 'tagwright-seo' ) . '</span>';
	}

	/**
	 * Prints the buy button, or a "Coming soon" label while Pro isn't on sale.
	 *
	 * @param string $url     Store URL.
	 * @param bool   $primary Whether this is the main call to action.
	 * @return void
	 */
	private static function cta( $url, $primary ) {
		$class = $primary ? 'tagwright-btn tagwright-btn--primary tagwright-btn--lg' : 'tagwright-btn tagwright-btn--secondary';

		if ( '' === $url ) {
			printf(
				'<span class="%1$s is-disabled" aria-disabled="true">%2$s</span>',
				esc_attr( $class ),
				esc_html__( 'Coming soon', 'tagwright-seo' )
			);
			return;
		}

		printf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener">%3$s</a>',
			esc_attr( $class ),
			esc_url( $url ),
			$primary ? esc_html__( 'Get Tagwright SEO Pro', 'tagwright-seo' ) : esc_html__( 'Choose plan', 'tagwright-seo' )
		);
	}
}
