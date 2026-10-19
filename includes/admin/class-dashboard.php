<?php
/**
 * Dashboard screen.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Data\Indexer;
use TagwrightSEO\Data\Stats;
use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * The Tagwright SEO dashboard: site health, score trends, content issues and
 * a launch pad into every part of the plugin.
 *
 * The page renders a skeleton and fetches its numbers over REST, so it
 * opens instantly and the date range can change without a reload.
 */
class Dashboard {

	/**
	 * Widgets added by add-ons.
	 *
	 * Each widget is an array with title, icon, area (`full`, `main` or
	 * `side`), priority and a render callback. The callback prints the
	 * widget body; add-ons draw their charts in JavaScript when the
	 * `tagwright-seo:dashboard` event fires with the dashboard data.
	 *
	 * Registering a widget with the id `search_performance` replaces the
	 * Search Console placeholder.
	 *
	 * @return array[]
	 */
	public static function widgets() {
		$widgets = apply_filters( 'tagwright_seo_dashboard_widgets', array() );

		uasort( $widgets, static fn( $a, $b ) => ( $a['priority'] ?? 10 ) <=> ( $b['priority'] ?? 10 ) );

		return $widgets;
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public static function render() {
		Admin::register_assets();
		wp_enqueue_script( 'tagwright-seo-charts', TAGWRIGHT_SEO_URL . 'assets/js/charts.js', array( 'tagwright-seo-admin' ), TAGWRIGHT_SEO_VERSION, true );
		wp_enqueue_script( 'tagwright-seo-dashboard', TAGWRIGHT_SEO_URL . 'assets/js/dashboard.js', array( 'tagwright-seo-charts', 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
		wp_set_script_translations( 'tagwright-seo-dashboard', 'tagwright-seo' );

		Stats::maybe_snapshot_today();

		$widgets = self::widgets();
		$state   = Indexer::get_state();
		$user    = wp_get_current_user();
		?>
		<div class="tagwright-app">
			<?php UI::header( 'tagwright-seo' ); ?>
			<div class="tagwright-page" id="tagwright-dashboard" data-edit-url="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">

				<div class="tagwright-page-title">
					<div>
						<h1><?php esc_html_e( 'Dashboard', 'tagwright-seo' ); ?></h1>
						<p><?php esc_html_e( 'How your content is doing in search, at a glance.', 'tagwright-seo' ); ?></p>
					</div>
					<div class="tagwright-page-title__actions tagwright-range">
						<div class="tagwright-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Date range', 'tagwright-seo' ); ?>">
							<?php foreach ( array( 7, 30, 90, 365 ) as $days ) : ?>
								<input type="radio" name="tagwright_range" id="tagwright-range-<?php echo (int) $days; ?>" value="<?php echo (int) $days; ?>" <?php checked( 30, $days ); ?> />
								<label for="tagwright-range-<?php echo (int) $days; ?>">
									<?php
									/* translators: %d: number of days. */
									echo esc_html( 365 === $days ? __( '1 year', 'tagwright-seo' ) : sprintf( __( '%d days', 'tagwright-seo' ), $days ) );
									?>
								</label>
							<?php endforeach; ?>
						</div>
						<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--icon" data-refresh title="<?php esc_attr_e( 'Refresh', 'tagwright-seo' ); ?>" aria-label="<?php esc_attr_e( 'Refresh', 'tagwright-seo' ); ?>"><?php Icons::render( 'refresh', 18 ); ?></button>
					</div>
				</div>

				<?php if ( 'running' === $state['status'] ) : ?>
					<div class="tagwright-notice tagwright-notice--info" data-index-notice>
						<span class="tagwright-notice__icon"><?php Icons::render( 'database', 18 ); ?></span>
						<div class="tagwright-notice__body" style="flex:1">
							<strong><?php esc_html_e( 'Building the Tagwright SEO index', 'tagwright-seo' ); ?></strong>
							<p><?php esc_html_e( 'Your existing content is being indexed in the background. Numbers fill in as it goes, and you can speed it up here.', 'tagwright-seo' ); ?></p>
							<div class="tagwright-progress" style="margin-top:10px"><span style="width:<?php echo (int) $state['percent']; ?>%"></span></div>
						</div>
						<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-run-index><?php esc_html_e( 'Index now', 'tagwright-seo' ); ?></button>
					</div>
				<?php endif; ?>

				<section class="tagwright-hero">
					<div>
						<h2>
							<?php
							/* translators: %s: user's display name. */
							echo esc_html( sprintf( __( 'Welcome back, %s', 'tagwright-seo' ), $user->display_name ) );
							?>
						</h2>
						<p data-hero-text><?php esc_html_e( 'Crunching the numbers…', 'tagwright-seo' ); ?></p>
						<div class="tagwright-hero__actions">
							<a class="tagwright-btn tagwright-btn--light" href="<?php echo esc_url( admin_url( 'edit.php?tagwright_filter=poor' ) ); ?>"><?php Icons::render( 'zap', 16 ); ?><?php esc_html_e( 'Fix weakest posts', 'tagwright-seo' ); ?></a>
							<a class="tagwright-btn tagwright-btn--accent" href="<?php echo esc_url( admin_url( 'admin.php?page=tagwright-seo-settings' ) ); ?>"><?php Icons::render( 'settings', 16 ); ?><?php esc_html_e( 'Settings', 'tagwright-seo' ); ?></a>
						</div>
					</div>
					<div class="tagwright-hero__score">
						<div class="tagwright-ring" data-hero-ring>
							<svg width="112" height="112" viewBox="0 0 112 112" aria-hidden="true"><circle class="tagwright-ring__track" cx="56" cy="56" r="48" stroke-width="10" fill="none"/><circle class="tagwright-ring__value" cx="56" cy="56" r="48" stroke-width="10" fill="none" stroke-linecap="round" stroke-dasharray="301.6" stroke-dashoffset="301.6"/></svg>
							<span class="tagwright-ring__label">–</span>
						</div>
						<div class="tagwright-hero__score-text">
							<strong><?php esc_html_e( 'Site SEO score', 'tagwright-seo' ); ?></strong>
							<span><?php esc_html_e( 'Average of analyzed, published content', 'tagwright-seo' ); ?></span>
						</div>
					</div>
				</section>

				<div class="tagwright-kpis" data-kpis>
					<?php for ( $i = 0; $i < 4; $i++ ) : ?>
						<div class="tagwright-kpi"><div class="tagwright-skeleton" style="height:14px;width:50%"></div><div class="tagwright-skeleton" style="height:30px;width:40%;margin:12px 0"></div><div class="tagwright-skeleton" style="height:36px"></div></div>
					<?php endfor; ?>
				</div>

				<?php self::render_widgets( $widgets, 'full' ); ?>

				<div class="tagwright-dashboard-grid">
					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'trend', 18 ); ?><?php esc_html_e( 'Score trends', 'tagwright-seo' ); ?></h3>
								<p class="tagwright-card__subtitle"><?php esc_html_e( 'Average SEO and readability scores of published content, day by day.', 'tagwright-seo' ); ?></p>
							</div>
						</div>
						<div class="tagwright-card__body tagwright-card__body--padded"><div class="tagwright-chart" data-chart="scores"></div></div>
					</div>

					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'analysis', 18 ); ?><?php esc_html_e( 'Score distribution', 'tagwright-seo' ); ?></h3>
								<p class="tagwright-card__subtitle"><?php esc_html_e( 'Published content by SEO score.', 'tagwright-seo' ); ?></p>
							</div>
						</div>
						<div class="tagwright-card__body tagwright-card__body--padded"><div data-chart="distribution"></div></div>
					</div>
				</div>

				<?php if ( ! isset( $widgets['search_performance'] ) ) : ?>
					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'search', 18 ); ?><?php esc_html_e( 'Search performance', 'tagwright-seo' ); ?></h3>
								<p class="tagwright-card__subtitle"><?php esc_html_e( 'Clicks, impressions, average position and top keywords from Google.', 'tagwright-seo' ); ?></p>
							</div>
							<span class="tagwright-badge tagwright-badge--accent"><?php esc_html_e( 'Add-on', 'tagwright-seo' ); ?></span>
						</div>
						<div class="tagwright-card__body tagwright-card__body--padded">
							<div class="tagwright-addon-promo">
								<span class="tagwright-addon-promo__icon"><?php Icons::render( 'trend', 24 ); ?></span>
								<div style="flex:1">
									<strong><?php esc_html_e( 'Bring Google Search Console into this dashboard', 'tagwright-seo' ); ?></strong>
									<p><?php esc_html_e( 'The Search Console & Analytics add-on plugs traffic, keyword and ranking charts right here, next to your on-site scores.', 'tagwright-seo' ); ?></p>
								</div>
								<a class="tagwright-btn tagwright-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=tagwright-seo-addons' ) ); ?>"><?php esc_html_e( 'See add-ons', 'tagwright-seo' ); ?></a>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<div class="tagwright-dashboard-grid">
					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'alert', 18 ); ?><?php esc_html_e( 'Issues over time', 'tagwright-seo' ); ?></h3>
								<p class="tagwright-card__subtitle"><?php esc_html_e( 'Fewer is better. Watch these lines go down as you work through your content.', 'tagwright-seo' ); ?></p>
							</div>
						</div>
						<div class="tagwright-card__body tagwright-card__body--padded"><div class="tagwright-chart" data-chart="issues"></div></div>
					</div>

					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'check-circle', 18 ); ?><?php esc_html_e( 'To do', 'tagwright-seo' ); ?></h3>
								<p class="tagwright-card__subtitle"><?php esc_html_e( 'Click an item to see the posts.', 'tagwright-seo' ); ?></p>
							</div>
						</div>
						<div class="tagwright-card__body"><ul class="tagwright-issues" data-issues></ul></div>
					</div>
				</div>

				<?php self::render_widgets( $widgets, 'main' ); ?>

				<div class="tagwright-dashboard-grid">
					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'file', 18 ); ?><?php esc_html_e( 'Content by type', 'tagwright-seo' ); ?></h3>
								<p class="tagwright-card__subtitle"><?php esc_html_e( 'Published items, average scores and hidden items per content type.', 'tagwright-seo' ); ?></p>
							</div>
						</div>
						<div class="tagwright-card__body" style="padding:0"><div data-types></div></div>
					</div>

					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'arrow-right', 18 ); ?><?php esc_html_e( 'Jump to', 'tagwright-seo' ); ?></h3>
							</div>
						</div>
						<div class="tagwright-card__body tagwright-card__body--padded">
							<div class="tagwright-quicknav">
								<?php foreach ( self::quick_links() as $link ) : ?>
									<a href="<?php echo esc_url( $link['url'] ); ?>"<?php echo ! empty( $link['external'] ) ? ' target="_blank" rel="noopener"' : ''; ?>><?php Icons::render( $link['icon'], 18 ); ?><span><?php echo esc_html( $link['label'] ); ?></span></a>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div>

				<?php self::render_widgets( $widgets, 'side' ); ?>

				<div class="tagwright-card">
					<div class="tagwright-card__header">
						<div>
							<h3 class="tagwright-card__title"><?php Icons::render( 'layers', 18 ); ?><?php esc_html_e( 'Modules', 'tagwright-seo' ); ?></h3>
							<p class="tagwright-card__subtitle"><?php esc_html_e( 'Switch features on and off. Modules that are off load no code.', 'tagwright-seo' ); ?></p>
						</div>
					</div>
					<div class="tagwright-card__body tagwright-card__body--padded">
						<?php Settings_Page::render_modules_compact(); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints add-on widgets for an area.
	 *
	 * @param array[] $widgets Widgets.
	 * @param string  $area    Area.
	 * @return void
	 */
	private static function render_widgets( array $widgets, $area ) {
		foreach ( $widgets as $id => $widget ) {
			if ( ( $widget['area'] ?? 'main' ) !== $area || empty( $widget['render'] ) || ! is_callable( $widget['render'] ) ) {
				continue;
			}
			?>
			<div class="tagwright-card" data-widget="<?php echo esc_attr( $id ); ?>">
				<div class="tagwright-card__header">
					<div>
						<h3 class="tagwright-card__title"><?php Icons::render( $widget['icon'] ?? 'analysis', 18 ); ?><?php echo esc_html( $widget['title'] ?? '' ); ?></h3>
						<?php if ( ! empty( $widget['subtitle'] ) ) : ?>
							<p class="tagwright-card__subtitle"><?php echo esc_html( $widget['subtitle'] ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<div class="tagwright-card__body tagwright-card__body--padded">
					<?php call_user_func( $widget['render'] ); ?>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Links on the "Jump to" card.
	 *
	 * @return array[]
	 */
	private static function quick_links() {
		$settings = admin_url( 'admin.php?page=tagwright-seo-settings' );
		$links    = array(
			array(
				'label' => __( 'Titles & meta', 'tagwright-seo' ),
				'icon'  => 'title',
				'url'   => $settings . '#general',
			),
			array(
				'label' => __( 'Content types', 'tagwright-seo' ),
				'icon'  => 'file',
				'url'   => $settings . '#content',
			),
			array(
				'label' => __( 'Social cards', 'tagwright-seo' ),
				'icon'  => 'share',
				'url'   => $settings . '#social',
			),
			array(
				'label' => __( 'Schema', 'tagwright-seo' ),
				'icon'  => 'code',
				'url'   => $settings . '#schema',
			),
			array(
				'label' => __( 'Sitemaps', 'tagwright-seo' ),
				'icon'  => 'sitemap',
				'url'   => $settings . '#sitemaps',
			),
			array(
				'label' => __( 'Webmaster tools', 'tagwright-seo' ),
				'icon'  => 'key',
				'url'   => $settings . '#webmaster',
			),
			array(
				'label' => __( 'Import & export', 'tagwright-seo' ),
				'icon'  => 'upload',
				'url'   => admin_url( 'admin.php?page=tagwright-seo-tools' ),
			),
			array(
				'label' => __( 'Setup wizard', 'tagwright-seo' ),
				'icon'  => 'sparkles',
				'url'   => admin_url( 'admin.php?page=tagwright-seo-setup' ),
			),
		);

		if ( Modules::is_active( 'sitemaps' ) ) {
			$links[] = array(
				'label'    => __( 'View sitemap', 'tagwright-seo' ),
				'icon'     => 'external',
				'url'      => \TagwrightSEO\Sitemaps\Router::index_url(),
				'external' => true,
			);
		}

		/**
		 * Filters the quick links on the dashboard.
		 *
		 * @param array[] $links Links with label, icon, url and optional external.
		 */
		return apply_filters( 'tagwright_seo_dashboard_links', $links );
	}
}
