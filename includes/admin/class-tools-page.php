<?php
/**
 * Tools screen.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Data\Indexer;
use TagwrightSEO\Data\Objects;
use TagwrightSEO\Importers\Importers;
use TagwrightSEO\Installer;
use TagwrightSEO\Technical\Robots_Txt;

defined( 'ABSPATH' ) || exit;

/**
 * Import, export, index maintenance and a system status overview.
 */
class Tools_Page {

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public static function render() {
		wp_enqueue_script( 'tagwright-seo-tools', TAGWRIGHT_SEO_URL . 'assets/js/tools.js', array( 'tagwright-seo-admin', 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
		wp_set_script_translations( 'tagwright-seo-tools', 'tagwright-seo' );

		$state     = Indexer::get_state();
		$importers = Importers::all();
		?>
		<div class="tagwright-app">
			<?php UI::header( 'tagwright-seo-tools' ); ?>
			<div class="tagwright-page" id="tagwright-tools">
				<div class="tagwright-page-title">
					<div>
						<h1><?php esc_html_e( 'Tools', 'tagwright-seo' ); ?></h1>
						<p><?php esc_html_e( 'Move your data in and out, and keep the Tagwright SEO index healthy.', 'tagwright-seo' ); ?></p>
					</div>
				</div>

				<div class="tagwright-grid tagwright-grid--2">
					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'download', 18 ); ?><?php esc_html_e( 'Import from another SEO plugin', 'tagwright-seo' ); ?></h3>
								<p class="tagwright-card__subtitle"><?php esc_html_e( 'Copies titles, descriptions, keyphrases, robots and social data. The other plugin\'s data is not changed.', 'tagwright-seo' ); ?></p>
							</div>
						</div>
						<div class="tagwright-card__body">
							<?php foreach ( $importers as $id => $importer ) : ?>
								<?php $detected = $importer->is_detected(); ?>
								<div class="tagwright-field tagwright-field--wide" data-importer="<?php echo esc_attr( $id ); ?>">
									<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
										<div>
											<strong style="color:var(--tagwright-ink)"><?php echo esc_html( $importer->name() ); ?></strong>
											<?php if ( $detected ) : ?>
												<span class="tagwright-badge tagwright-badge--good">
													<?php
													/* translators: %d: number of posts. */
													echo esc_html( sprintf( _n( '%d post with data', '%d posts with data', $importer->count_posts(), 'tagwright-seo' ), $importer->count_posts() ) );
													?>
												</span>
											<?php else : ?>
												<span class="tagwright-badge tagwright-badge--muted"><?php esc_html_e( 'No data found', 'tagwright-seo' ); ?></span>
											<?php endif; ?>
										</div>
										<div style="display:flex;align-items:center;gap:12px">
											<label class="tagwright-check"><input type="checkbox" data-import-settings checked /><span class="tagwright-check__box"><?php Icons::render( 'check', 12 ); ?></span><span><?php esc_html_e( 'Settings too', 'tagwright-seo' ); ?></span></label>
											<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-run-import <?php disabled( ! $detected ); ?>><?php esc_html_e( 'Import', 'tagwright-seo' ); ?></button>
										</div>
									</div>
									<div class="tagwright-progress" hidden style="margin-top:12px"><span></span></div>
								</div>
							<?php endforeach; ?>
							<?php if ( \TagwrightSEO\Modules::is_active( 'redirects' ) ) : ?>
								<div class="tagwright-field tagwright-field--wide">
									<p class="tagwright-muted" style="margin:0">
										<?php
										echo wp_kses_post(
											sprintf(
												/* translators: %s: link to the Redirects screen. */
												esc_html__( 'Redirects are imported separately, from Rank Math, Yoast SEO Premium, Redirection or All in One SEO Pro, on the %s.', 'tagwright-seo' ),
												'<a href="' . esc_url( admin_url( 'admin.php?page=tagwright-seo-redirects&import=1' ) ) . '">' . esc_html__( 'Redirects screen', 'tagwright-seo' ) . '</a>'
											)
										);
										?>
									</p>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'upload', 18 ); ?><?php esc_html_e( 'Settings backup', 'tagwright-seo' ); ?></h3>
								<p class="tagwright-card__subtitle"><?php esc_html_e( 'Export your settings to a file, or copy them to another site.', 'tagwright-seo' ); ?></p>
							</div>
						</div>
						<div class="tagwright-card__body tagwright-card__body--padded">
							<p class="tagwright-muted" style="margin-top:0"><?php esc_html_e( 'The export contains settings only, not per-post SEO data.', 'tagwright-seo' ); ?></p>
							<div style="display:flex;gap:10px;flex-wrap:wrap">
								<button type="button" class="tagwright-btn tagwright-btn--primary" data-export><?php Icons::render( 'download', 16 ); ?><?php esc_html_e( 'Export settings', 'tagwright-seo' ); ?></button>
								<label class="tagwright-btn tagwright-btn--secondary" for="tagwright-import-file"><?php Icons::render( 'upload', 16 ); ?><?php esc_html_e( 'Import settings', 'tagwright-seo' ); ?></label>
								<input type="file" id="tagwright-import-file" accept="application/json,.json" hidden data-import-file />
							</div>
						</div>
					</div>
				</div>

				<div class="tagwright-grid tagwright-grid--2">
					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'database', 18 ); ?><?php esc_html_e( 'SEO index', 'tagwright-seo' ); ?></h3>
								<p class="tagwright-card__subtitle"><?php esc_html_e( 'Tagwright SEO keeps permalinks, dates and images in its own table so sitemaps and the dashboard stay fast. Rebuild it if something looks out of date.', 'tagwright-seo' ); ?></p>
							</div>
						</div>
						<div class="tagwright-card__body tagwright-card__body--padded" data-index-card>
							<p style="margin-top:0" data-index-status>
								<?php
								if ( 'running' === $state['status'] ) {
									/* translators: %d: percentage. */
									echo esc_html( sprintf( __( 'Indexing in the background: %d%% done.', 'tagwright-seo' ), $state['percent'] ) );
								} elseif ( 'complete' === $state['status'] ) {
									/* translators: %s: time since the last index run. */
									echo esc_html( sprintf( __( 'Index is up to date. Last full run %s ago.', 'tagwright-seo' ), human_time_diff( (int) ( $state['finished_at'] ?? time() ) ) ) );
								} else {
									esc_html_e( 'The index has not been built yet.', 'tagwright-seo' );
								}
								?>
							</p>
							<div class="tagwright-progress" style="margin-bottom:16px"><span style="width:<?php echo (int) $state['percent']; ?>%"></span></div>
							<button type="button" class="tagwright-btn tagwright-btn--primary" data-rebuild-index><?php Icons::render( 'refresh', 16 ); ?><?php esc_html_e( 'Rebuild index', 'tagwright-seo' ); ?></button>
						</div>
					</div>

					<div class="tagwright-card">
						<div class="tagwright-card__header">
							<div>
								<h3 class="tagwright-card__title"><?php Icons::render( 'tools', 18 ); ?><?php esc_html_e( 'Maintenance', 'tagwright-seo' ); ?></h3>
							</div>
						</div>
						<div class="tagwright-card__body">
							<div class="tagwright-field" style="grid-template-columns:1fr auto;align-items:center">
								<div class="tagwright-field__label">
									<strong style="color:var(--tagwright-ink)"><?php esc_html_e( 'Setup wizard', 'tagwright-seo' ); ?></strong>
									<p class="tagwright-field__description"><?php esc_html_e( 'Walk through the essentials again. Your current settings are filled in, so nothing is lost.', 'tagwright-seo' ); ?></p>
								</div>
								<div>
									<a class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=tagwright-seo-setup' ) ); ?>"><?php esc_html_e( 'Open', 'tagwright-seo' ); ?></a>
								</div>
							</div>
							<?php
							$tools = array(
								'snapshot'       => array( __( 'Refresh statistics', 'tagwright-seo' ), __( 'Recalculates today\'s dashboard numbers.', 'tagwright-seo' ), 'secondary' ),
								'flush_rewrite'  => array( __( 'Refresh permalinks', 'tagwright-seo' ), __( 'Fixes sitemap or llms.txt URLs that return a 404.', 'tagwright-seo' ), 'secondary' ),
								'flush_cache'    => array( __( 'Clear cache', 'tagwright-seo' ), __( 'Clears cached SEO rows from the object cache.', 'tagwright-seo' ), 'secondary' ),
								'clear_stats'    => array( __( 'Clear statistics history', 'tagwright-seo' ), __( 'Deletes the trend history. Today\'s numbers are kept.', 'tagwright-seo' ), 'danger' ),
								'reset_settings' => array( __( 'Reset settings', 'tagwright-seo' ), __( 'Puts every setting back to its default. Per-post data is kept.', 'tagwright-seo' ), 'danger' ),
							);
							foreach ( $tools as $action => $tool ) :
								?>
								<div class="tagwright-field" style="grid-template-columns:1fr auto;align-items:center">
									<div class="tagwright-field__label">
										<strong style="color:var(--tagwright-ink)"><?php echo esc_html( $tool[0] ); ?></strong>
										<p class="tagwright-field__description"><?php echo esc_html( $tool[1] ); ?></p>
									</div>
									<div>
										<button type="button" class="tagwright-btn tagwright-btn--<?php echo esc_attr( $tool[2] ); ?> tagwright-btn--sm" data-tool="<?php echo esc_attr( $action ); ?>" <?php echo 'danger' === $tool[2] ? 'data-confirm="' . esc_attr__( 'Are you sure? This cannot be undone.', 'tagwright-seo' ) . '"' : ''; ?>><?php esc_html_e( 'Run', 'tagwright-seo' ); ?></button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="tagwright-card">
					<div class="tagwright-card__header">
						<div>
							<h3 class="tagwright-card__title"><?php Icons::render( 'info', 18 ); ?><?php esc_html_e( 'System status', 'tagwright-seo' ); ?></h3>
							<p class="tagwright-card__subtitle"><?php esc_html_e( 'Handy when asking for support.', 'tagwright-seo' ); ?></p>
						</div>
					</div>
					<div class="tagwright-card__body" style="padding:0">
						<table class="tagwright-table">
							<tbody>
								<?php foreach ( self::status_rows() as $row ) : ?>
									<tr>
										<td style="width:40%"><strong><?php echo esc_html( $row[0] ); ?></strong></td>
										<td><?php echo wp_kses_post( $row[1] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Name of a static sitemap file left in the site root by another plugin.
	 *
	 * @return string Empty when there is none.
	 */
	private static function physical_sitemap() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$candidates = array_unique( array( \TagwrightSEO\Sitemaps\Router::index_name() . '.xml', 'sitemap.xml', 'sitemap.xml.gz' ) );
		foreach ( $candidates as $file ) {
			if ( file_exists( trailingslashit( get_home_path() ) . $file ) ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * Rows for the status table.
	 *
	 * @return array[]
	 */
	private static function status_rows() {
		global $wpdb;

		$tables = Installer::get_table_names();
		$sizes  = array();

		foreach ( $tables as $table ) {
			$size = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT table_rows, (data_length + index_length) AS bytes FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s', DB_NAME, $table ),
				ARRAY_A
			);
			$sizes[ $table ] = $size ? size_format( (int) $size['bytes'], 1 ) : '–';
		}

		$yes = '<span class="tagwright-badge tagwright-badge--good">' . esc_html__( 'Yes', 'tagwright-seo' ) . '</span>';
		$no  = '<span class="tagwright-badge tagwright-badge--muted">' . esc_html__( 'No', 'tagwright-seo' ) . '</span>';

		return array(
			array( __( 'Plugin version', 'tagwright-seo' ), esc_html( TAGWRIGHT_SEO_VERSION ) ),
			array( __( 'Database version', 'tagwright-seo' ), esc_html( (string) get_option( Installer::DB_VERSION_OPTION ) ) ),
			array(
				__( 'Indexed objects', 'tagwright-seo' ),
				sprintf(
					/* translators: 1: posts, 2: terms, 3: authors. */
					esc_html__( '%1$s posts, %2$s terms, %3$s authors', 'tagwright-seo' ),
					number_format_i18n( Objects::count( 'post' ) ),
					number_format_i18n( Objects::count( 'term' ) ),
					number_format_i18n( Objects::count( 'user' ) )
				),
			),
			array( __( 'Objects table size', 'tagwright-seo' ), esc_html( $sizes[ $tables['objects'] ] ) ),
			array( __( 'Statistics table size', 'tagwright-seo' ), esc_html( $sizes[ $tables['stats'] ] ) ),
			array( __( 'Persistent object cache', 'tagwright-seo' ), wp_using_ext_object_cache() ? $yes : $no ),
			array( __( 'Physical robots.txt file', 'tagwright-seo' ), Robots_Txt::has_physical_file() ? $yes : $no ),
			array( __( 'Sitemap URL', 'tagwright-seo' ), '<a href="' . esc_url( \TagwrightSEO\Sitemaps\Router::index_url() ) . '" target="_blank" rel="noopener">' . esc_html( \TagwrightSEO\Sitemaps\Router::index_url() ) . '</a>' ),
			array( __( 'Physical sitemap file in site root', 'tagwright-seo' ), self::physical_sitemap() ? '<span class="tagwright-badge tagwright-badge--bad">' . esc_html( self::physical_sitemap() ) . '</span> ' . esc_html__( 'The server serves this file instead of Tagwright SEO. Delete it, or the plugin that keeps creating it.', 'tagwright-seo' ) : $no ),
			array( __( 'Search engines allowed', 'tagwright-seo' ), get_option( 'blog_public' ) ? $yes : '<span class="tagwright-badge tagwright-badge--bad">' . esc_html__( 'No, discouraged in Settings > Reading', 'tagwright-seo' ) . '</span>' ),
			array( __( 'Pretty permalinks', 'tagwright-seo' ), get_option( 'permalink_structure' ) ? $yes : $no ),
			array( __( 'WordPress version', 'tagwright-seo' ), esc_html( get_bloginfo( 'version' ) ) ),
			array( __( 'PHP version', 'tagwright-seo' ), esc_html( PHP_VERSION ) ),
			array( __( 'Database server', 'tagwright-seo' ), esc_html( $wpdb->db_server_info() ) ),
		);
	}
}
