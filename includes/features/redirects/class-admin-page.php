<?php
/**
 * The Redirects screen.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

use TagwrightSEO\Admin\Admin;
use TagwrightSEO\Admin\Icons;
use TagwrightSEO\Admin\UI;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page with two views, Redirects and 404 Log. The page prints the
 * frame; the tables load over REST so searching and paging never reload.
 */
class Admin_Page {

	/**
	 * Page slug.
	 */
	const SLUG = 'tagwright-seo-redirects';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_filter( 'tagwright_seo_admin_pages', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * Adds the page to the Tagwright SEO menu.
	 *
	 * @param array $pages Pages keyed by slug.
	 * @return array
	 */
	public static function register( $pages ) {
		$page = array(
			'title'      => __( 'Tagwright SEO Redirects', 'tagwright-seo' ),
			'menu_title' => __( 'Redirects', 'tagwright-seo' ),
			'icon'       => 'redirect',
			'callback'   => array( __CLASS__, 'render' ),
		);

		// Sit right after Tools in the menu and the top navigation.
		$sorted = array();
		foreach ( $pages as $slug => $existing ) {
			$sorted[ $slug ] = $existing;
			if ( 'tagwright-seo-tools' === $slug ) {
				$sorted[ self::SLUG ] = $page;
			}
		}

		if ( ! isset( $sorted[ self::SLUG ] ) ) {
			$sorted[ self::SLUG ] = $page;
		}

		return $sorted;
	}

	/**
	 * Whether the current request is this page.
	 *
	 * @return bool
	 */
	private static function is_current() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the page slug.
		return isset( $_GET['page'] ) && self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Loads the screen's script and styles, on this page only.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::is_current() ) {
			return;
		}

		wp_enqueue_style( 'tagwright-seo-redirects', TAGWRIGHT_SEO_URL . 'assets/features/redirects/admin.css', array( 'tagwright-seo-admin' ), TAGWRIGHT_SEO_VERSION );
		wp_enqueue_script( 'tagwright-seo-redirects', TAGWRIGHT_SEO_URL . 'assets/features/redirects/admin.js', array( 'tagwright-seo-admin', 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
		wp_set_script_translations( 'tagwright-seo-redirects', 'tagwright-seo' );

		wp_localize_script(
			'tagwright-seo-redirects',
			'tagwrightSeoRedirects',
			array(
				'statuses'      => Repository::status_labels(),
				'matchTypes'    => Repository::match_labels(),
				'defaultStatus' => (string) Options::get( 'redirects_default_status', '301' ),
				'homeUrl'       => home_url( '/' ),
				'logEnabled'    => (bool) Options::get( 'redirects_log_404' ),
			)
		);
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Admin::capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the starting view.
		$view     = isset( $_GET['view'] ) && '404' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? '404' : 'rules';
		$settings = admin_url( 'admin.php?page=tagwright-seo-settings#redirects' );
		$tabs     = array(
			'rules' => array( 'redirect', __( 'Redirects', 'tagwright-seo' ) ),
			'404'   => array( 'alert', __( '404 Log', 'tagwright-seo' ) ),
		);
		?>
		<div class="tagwright-app">
			<?php UI::header( self::SLUG ); ?>
			<div class="tagwright-page" id="tagwright-redirects" data-view="<?php echo esc_attr( $view ); ?>">
				<div class="tagwright-page-title">
					<div>
						<h1><?php esc_html_e( 'Redirects', 'tagwright-seo' ); ?></h1>
						<p><?php esc_html_e( 'Send old addresses to new ones and catch the links that end in "page not found".', 'tagwright-seo' ); ?></p>
					</div>
					<div class="tagwright-page-title__actions">
						<a class="tagwright-btn tagwright-btn--ghost" href="<?php echo esc_url( $settings ); ?>"><?php Icons::render( 'settings', 16 ); ?><?php esc_html_e( 'Settings', 'tagwright-seo' ); ?></a>
						<button type="button" class="tagwright-btn tagwright-btn--primary" data-add><?php Icons::render( 'plus', 16 ); ?><?php esc_html_e( 'Add redirect', 'tagwright-seo' ); ?></button>
					</div>
				</div>

				<?php self::render_editor(); ?>
				<?php self::render_migrate(); ?>

				<div class="tagwright-card tagwright-redirects-card">
					<div class="tagwright-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Redirects', 'tagwright-seo' ); ?>">
						<?php foreach ( $tabs as $id => $tab ) : ?>
							<?php $selected = $id === $view; ?>
							<button type="button" role="tab" id="tagwright-redirects-tab-<?php echo esc_attr( $id ); ?>" data-tab="<?php echo esc_attr( $id ); ?>" aria-controls="tagwright-redirects-panel-<?php echo esc_attr( $id ); ?>" aria-selected="<?php echo $selected ? 'true' : 'false'; ?>" tabindex="<?php echo $selected ? '0' : '-1'; ?>">
								<?php Icons::render( $tab[0], 16 ); ?><?php echo esc_html( $tab[1] ); ?>
								<span class="tagwright-badge tagwright-badge--muted" data-count="<?php echo esc_attr( $id ); ?>" hidden></span>
							</button>
						<?php endforeach; ?>
					</div>

					<div class="tagwright-tabpanel" role="tabpanel" id="tagwright-redirects-panel-rules" aria-labelledby="tagwright-redirects-tab-rules" data-panel="rules"<?php echo 'rules' === $view ? '' : ' hidden'; ?>>
						<div class="tagwright-redirects-toolbar">
							<div class="tagwright-redirects-toolbar__group">
								<input type="search" class="tagwright-input" data-search placeholder="<?php esc_attr_e( 'Search redirects…', 'tagwright-seo' ); ?>" aria-label="<?php esc_attr_e( 'Search redirects', 'tagwright-seo' ); ?>" />
								<select class="tagwright-select" data-filter="match_type" aria-label="<?php esc_attr_e( 'Match type', 'tagwright-seo' ); ?>">
									<option value=""><?php esc_html_e( 'All match types', 'tagwright-seo' ); ?></option>
									<?php foreach ( Repository::match_labels() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<select class="tagwright-select" data-filter="status" aria-label="<?php esc_attr_e( 'Redirect type', 'tagwright-seo' ); ?>">
									<option value=""><?php esc_html_e( 'All types', 'tagwright-seo' ); ?></option>
									<?php foreach ( Repository::status_labels() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="tagwright-redirects-toolbar__group">
								<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-migrate-open><?php Icons::render( 'redirect', 16 ); ?><?php esc_html_e( 'Import from plugin', 'tagwright-seo' ); ?></button>
								<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-export><?php Icons::render( 'download', 16 ); ?><?php esc_html_e( 'Export CSV', 'tagwright-seo' ); ?></button>
								<label class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" for="tagwright-redirects-import"><?php Icons::render( 'upload', 16 ); ?><?php esc_html_e( 'Import CSV', 'tagwright-seo' ); ?></label>
								<input type="file" id="tagwright-redirects-import" accept=".csv,text/csv" hidden data-import />
							</div>
						</div>
						<?php self::render_bulkbar( 'rules' ); ?>
						<div class="tagwright-redirects-table" data-table aria-live="polite"><?php self::render_skeleton(); ?></div>
						<?php self::render_footer(); ?>
					</div>

					<div class="tagwright-tabpanel" role="tabpanel" id="tagwright-redirects-panel-404" aria-labelledby="tagwright-redirects-tab-404" data-panel="404"<?php echo '404' === $view ? '' : ' hidden'; ?>>
						<?php if ( ! Options::get( 'redirects_log_404' ) ) : ?>
							<div class="tagwright-redirects-notice">
								<?php
								UI::notice(
									'info',
									sprintf(
										/* translators: %s: link to the Redirects settings. */
										__( 'The 404 log is switched off, so new errors are not recorded. Switch it on in the %s.', 'tagwright-seo' ),
										'<a href="' . esc_url( $settings ) . '">' . esc_html__( 'Redirects settings', 'tagwright-seo' ) . '</a>'
									)
								);
								?>
							</div>
						<?php endif; ?>
						<div class="tagwright-redirects-toolbar">
							<div class="tagwright-redirects-toolbar__group">
								<input type="search" class="tagwright-input" data-search placeholder="<?php esc_attr_e( 'Search addresses…', 'tagwright-seo' ); ?>" aria-label="<?php esc_attr_e( 'Search the 404 log', 'tagwright-seo' ); ?>" />
							</div>
							<div class="tagwright-redirects-toolbar__group">
								<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm" data-clear-log data-confirm="<?php esc_attr_e( 'Delete every entry in the 404 log? This cannot be undone.', 'tagwright-seo' ); ?>"><?php Icons::render( 'trash', 16 ); ?><?php esc_html_e( 'Clear log', 'tagwright-seo' ); ?></button>
							</div>
						</div>
						<?php self::render_bulkbar( '404' ); ?>
						<div class="tagwright-redirects-table" data-table aria-live="polite"><?php self::render_skeleton(); ?></div>
						<?php self::render_footer(); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The add/edit form, an inline card that opens above the table.
	 *
	 * @return void
	 */
	private static function render_editor() {
		$prefix     = 'redirect';
		$is_status  = array(
			'field'  => 'status',
			'value'  => array( '301', '302', '307' ),
			'prefix' => $prefix,
		);
		$match_help = sprintf(
			/* translators: 1: example prefix rule, 2: example target, 3: example regex. */
			__( '<strong>Exact</strong> matches one address. <strong>Starts with</strong> matches everything below it; the rest of the address is available as <code>$1</code>, for example %1$s to %2$s. <strong>Regex</strong> matches a pattern against the path, which starts with a slash, for example %3$s.', 'tagwright-seo' ),
			'<code>/old-blog/</code>',
			'<code>/blog/$1</code>',
			'<code>^/(\d{4})/(.+)$</code>'
		);
		?>
		<form class="tagwright-card tagwright-redirects-editor" data-editor hidden novalidate>
			<input type="hidden" name="redirect[log_url]" value="" />
			<div class="tagwright-card__header">
				<div>
					<h3 class="tagwright-card__title"><?php Icons::render( 'redirect', 18 ); ?><span data-editor-title><?php esc_html_e( 'Add redirect', 'tagwright-seo' ); ?></span></h3>
					<p class="tagwright-card__subtitle" data-editor-hint hidden></p>
				</div>
				<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--icon" data-cancel aria-label="<?php esc_attr_e( 'Close', 'tagwright-seo' ); ?>" title="<?php esc_attr_e( 'Close', 'tagwright-seo' ); ?>"><?php Icons::render( 'x', 18 ); ?></button>
			</div>
			<div class="tagwright-card__body">
				<?php
				UI::field(
					array(
						'id'          => 'source',
						'prefix'      => $prefix,
						'type'        => 'text',
						'label'       => __( 'Old address', 'tagwright-seo' ),
						'description' => __( 'The path people arrive at. A full URL works too.', 'tagwright-seo' ),
						'prefix_text' => untrailingslashit( home_url() ),
						'placeholder' => '/old-page/',
						'attrs'       => array(
							'required'     => true,
							'autocomplete' => 'off',
							'spellcheck'   => 'false',
						),
					)
				);
				UI::field(
					array(
						'id'          => 'match_type',
						'prefix'      => $prefix,
						'type'        => 'segmented',
						'label'       => __( 'Match', 'tagwright-seo' ),
						'description' => $match_help,
						'options'     => Repository::match_labels(),
						'value'       => 'exact',
					)
				);
				UI::field(
					array(
						'id'      => 'status',
						'prefix'  => $prefix,
						'type'    => 'select',
						'label'   => __( 'Type', 'tagwright-seo' ),
						'options' => Repository::status_labels(),
						'value'   => (string) Options::get( 'redirects_default_status', '301' ),
					)
				);
				UI::field(
					array(
						'id'          => 'target',
						'prefix'      => $prefix,
						'type'        => 'text',
						'label'       => __( 'New address', 'tagwright-seo' ),
						'description' => __( 'A path on this site such as /new-page/, or a full URL to another site.', 'tagwright-seo' ),
						'placeholder' => '/new-page/',
						'show_if'     => $is_status,
						'attrs'       => array(
							'autocomplete' => 'off',
							'spellcheck'   => 'false',
						),
					)
				);
				UI::field(
					array(
						'id'          => 'notes',
						'prefix'      => $prefix,
						'type'        => 'text',
						'label'       => __( 'Note', 'tagwright-seo' ),
						'description' => __( 'Optional. Only you see it.', 'tagwright-seo' ),
						'attrs'       => array( 'maxlength' => 255 ),
					)
				);
				UI::field(
					array(
						'id'           => 'enabled',
						'prefix'       => $prefix,
						'type'         => 'toggle',
						'label'        => __( 'Active', 'tagwright-seo' ),
						'toggle_label' => __( 'Redirect is on', 'tagwright-seo' ),
						'value'        => true,
					)
				);
				?>
			</div>
			<div class="tagwright-card__footer">
				<label class="tagwright-check" data-remove-log-wrap hidden><input type="checkbox" data-remove-log checked /><span class="tagwright-check__box"><?php Icons::render( 'check', 12 ); ?></span><span><?php esc_html_e( 'Remove the address from the 404 log', 'tagwright-seo' ); ?></span></label>
				<span></span>
				<div class="tagwright-redirects-editor__actions">
					<button type="button" class="tagwright-btn tagwright-btn--ghost" data-cancel><?php esc_html_e( 'Cancel', 'tagwright-seo' ); ?></button>
					<button type="submit" class="tagwright-btn tagwright-btn--primary" data-save><?php esc_html_e( 'Save redirect', 'tagwright-seo' ); ?></button>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * The "import from another plugin" card. The list of plugins is filled in
	 * over REST when the card opens, so the page itself never runs the
	 * detection queries.
	 *
	 * @return void
	 */
	private static function render_migrate() {
		?>
		<div class="tagwright-card tagwright-redirects-migrate" data-migrate hidden>
			<div class="tagwright-card__header">
				<div>
					<h3 class="tagwright-card__title"><?php Icons::render( 'download', 18 ); ?><?php esc_html_e( 'Import from another plugin', 'tagwright-seo' ); ?></h3>
					<p class="tagwright-card__subtitle"><?php esc_html_e( 'Copies redirects from Rank Math, Yoast SEO Premium, Redirection and All in One SEO Pro, even when that plugin is switched off. Its data is not changed, and redirects you already have here are updated rather than duplicated.', 'tagwright-seo' ); ?></p>
				</div>
				<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--icon" data-migrate-close aria-label="<?php esc_attr_e( 'Close', 'tagwright-seo' ); ?>" title="<?php esc_attr_e( 'Close', 'tagwright-seo' ); ?>"><?php Icons::render( 'x', 18 ); ?></button>
			</div>
			<div class="tagwright-card__body" data-migrate-list aria-live="polite"><?php self::render_skeleton(); ?></div>
		</div>
		<?php
	}

	/**
	 * Bar that appears when rows are selected.
	 *
	 * @param string $view rules or 404.
	 * @return void
	 */
	private static function render_bulkbar( $view ) {
		?>
		<div class="tagwright-redirects-bulk" data-bulk hidden>
			<span data-selected></span>
			<?php if ( 'rules' === $view ) : ?>
				<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-bulk-action="enable"><?php esc_html_e( 'Switch on', 'tagwright-seo' ); ?></button>
				<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-bulk-action="disable"><?php esc_html_e( 'Switch off', 'tagwright-seo' ); ?></button>
			<?php endif; ?>
			<button type="button" class="tagwright-btn tagwright-btn--danger tagwright-btn--sm" data-bulk-action="delete"><?php Icons::render( 'trash', 16 ); ?><?php esc_html_e( 'Delete', 'tagwright-seo' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Loading placeholder for a table.
	 *
	 * @return void
	 */
	private static function render_skeleton() {
		echo '<div class="tagwright-card__body--padded">';
		for ( $i = 0; $i < 4; $i++ ) {
			echo '<div class="tagwright-skeleton" style="height:18px;margin:12px 0"></div>';
		}
		echo '</div>';
	}

	/**
	 * Count and paging controls under a table.
	 *
	 * @return void
	 */
	private static function render_footer() {
		?>
		<div class="tagwright-card__footer" data-footer hidden>
			<span class="tagwright-muted" data-summary></span>
			<div class="tagwright-redirects-pager">
				<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-page="prev"><?php esc_html_e( 'Previous', 'tagwright-seo' ); ?></button>
				<span class="tagwright-muted" data-page-label></span>
				<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-page="next"><?php esc_html_e( 'Next', 'tagwright-seo' ); ?></button>
			</div>
		</div>
		<?php
	}
}
