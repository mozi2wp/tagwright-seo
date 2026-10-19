<?php
/**
 * Redirects settings: defaults, module card and settings tab.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

use TagwrightSEO\Admin\Icons;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the Redirects & 404 Monitor feature to the core.
 */
final class Settings {

	/**
	 * Default values. Read on every request, so it stays a plain array.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'redirects_log_404'          => true,
			'redirects_log_retention'    => 30,
			'redirects_log_ignore_bots'  => true,
			'redirects_log_ignore_files' => true,
			'redirects_log_exclude'      => '',
			'redirects_auto_slug'        => true,
			'redirects_default_status'   => '301',
			'redirects_case_insensitive' => true,
			'redirects_ignore_query'     => true,
		);
	}

	/**
	 * Card on the Modules screen.
	 *
	 * @return array
	 */
	public static function module(): array {
		return array(
			'title'       => __( 'Redirects & 404 Monitor', 'tagwright-seo' ),
			'description' => __( 'Send old URLs to new ones, keep links working when slugs change and see which missing pages people hit.', 'tagwright-seo' ),
			'icon'        => 'redirect',
			'settings'    => 'redirects',
		);
	}

	/**
	 * Settings tab.
	 *
	 * @return array
	 */
	public static function tab(): array {
		return array(
			'title'       => __( 'Redirects', 'tagwright-seo' ),
			'description' => __( 'How redirects match URLs, and what the 404 monitor records.', 'tagwright-seo' ),
			'icon'        => 'redirect',
			'group'       => 'features',
			'module'      => 'redirects',
			'sections'    => array(
				array(
					'title'  => __( 'Redirects', 'tagwright-seo' ),
					'icon'   => 'redirect',
					'fields' => array(
						array(
							'id'     => 'redirects_manage',
							'type'   => 'html',
							'label'  => __( 'Manage redirects', 'tagwright-seo' ),
							'render' => array( __CLASS__, 'render_manage_link' ),
						),
						array(
							'id'          => 'redirects_default_status',
							'type'        => 'select',
							'label'       => __( 'Default redirect type', 'tagwright-seo' ),
							'description' => __( 'Pre-selected when you add a redirect. Use 301 for pages that moved for good.', 'tagwright-seo' ),
							'options'     => Repository::status_labels(),
						),
						array(
							'id'           => 'redirects_auto_slug',
							'type'         => 'toggle',
							'label'        => __( 'Slug changes', 'tagwright-seo' ),
							'description'  => __( 'When the address of a published post, page or custom post type changes, a 301 redirect from the old address is added for you. Child pages of a moved page are covered too.', 'tagwright-seo' ),
							'toggle_label' => __( 'Redirect old addresses automatically', 'tagwright-seo' ),
						),
						array(
							'id'           => 'redirects_case_insensitive',
							'type'         => 'toggle',
							'label'        => __( 'Letter case', 'tagwright-seo' ),
							'description'  => __( 'Treat /Old-Page and /old-page as the same address.', 'tagwright-seo' ),
							'toggle_label' => __( 'Ignore upper and lower case', 'tagwright-seo' ),
						),
						array(
							'id'           => 'redirects_ignore_query',
							'type'         => 'toggle',
							'label'        => __( 'Query strings', 'tagwright-seo' ),
							'description'  => __( 'Match /old-page?utm_source=x with a redirect for /old-page and pass the query string on to the target. A redirect that includes its own query string still wins.', 'tagwright-seo' ),
							'toggle_label' => __( 'Ignore query strings when matching', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'  => __( '404 monitor', 'tagwright-seo' ),
					'icon'   => 'alert',
					'fields' => array(
						array(
							'id'           => 'redirects_log_404',
							'type'         => 'toggle',
							'label'        => __( 'Log 404 errors', 'tagwright-seo' ),
							'description'  => __( 'Records addresses that ended in "page not found", so you can redirect the ones that matter. Each address is stored once with a hit counter.', 'tagwright-seo' ),
							'toggle_label' => __( 'Keep a 404 log', 'tagwright-seo' ),
						),
						array(
							'id'          => 'redirects_log_retention',
							'type'        => 'number',
							'label'       => __( 'Keep entries for', 'tagwright-seo' ),
							'description' => __( 'Addresses nobody has hit for this long are removed. The log never grows past 10,000 addresses either.', 'tagwright-seo' ),
							'min'         => 1,
							'max'         => 365,
							'suffix'      => __( 'days', 'tagwright-seo' ),
							'show_if'     => array(
								'field'   => 'redirects_log_404',
								'checked' => true,
							),
						),
						array(
							'id'           => 'redirects_log_ignore_bots',
							'type'         => 'toggle',
							'label'        => __( 'Bots', 'tagwright-seo' ),
							'description'  => __( 'Skips search engine crawlers, scrapers and requests without a browser signature. They probe a lot of addresses that never existed.', 'tagwright-seo' ),
							'toggle_label' => __( 'Ignore bots', 'tagwright-seo' ),
							'show_if'      => array(
								'field'   => 'redirects_log_404',
								'checked' => true,
							),
						),
						array(
							'id'           => 'redirects_log_ignore_files',
							'type'         => 'toggle',
							'label'        => __( 'Static files', 'tagwright-seo' ),
							'description'  => __( 'Skips missing images, scripts, stylesheets and fonts, which are usually theme or plugin leftovers rather than pages.', 'tagwright-seo' ),
							'toggle_label' => __( 'Ignore missing static files', 'tagwright-seo' ),
							'show_if'      => array(
								'field'   => 'redirects_log_404',
								'checked' => true,
							),
						),
						array(
							'id'          => 'redirects_log_exclude',
							'type'        => 'textarea',
							'label'       => __( 'Excluded paths', 'tagwright-seo' ),
							/* translators: %s: example paths. */
							'description' => sprintf( __( 'One path per line. Addresses that start with any of them are never logged, for example %s.', 'tagwright-seo' ), '<code>/wp-content/</code>, <code>/.well-known/</code>' ),
							'placeholder' => "/wp-content/\n/.well-known/",
							'rows'        => 4,
							'sanitize'    => array( Repository::class, 'sanitize_exclusions' ),
							'show_if'     => array(
								'field'   => 'redirects_log_404',
								'checked' => true,
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The link to the Redirects screen shown at the top of the tab.
	 *
	 * @return void
	 */
	public static function render_manage_link() {
		printf(
			'<a class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" href="%1$s">%2$s<span>%3$s</span></a> <a class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm" href="%4$s">%5$s</a>',
			esc_url( admin_url( 'admin.php?page=' . Admin_Page::SLUG ) ),
			wp_kses( Icons::get( 'redirect', 16 ), Icons::kses() ),
			esc_html__( 'Open redirects', 'tagwright-seo' ),
			esc_url( admin_url( 'admin.php?page=' . Admin_Page::SLUG . '&view=404' ) ),
			esc_html__( 'View 404 log', 'tagwright-seo' )
		);
	}
}
