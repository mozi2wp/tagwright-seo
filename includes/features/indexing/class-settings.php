<?php
/**
 * Instant Indexing settings.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults, module card and settings tab for Instant Indexing.
 */
final class Settings {

	/**
	 * Default values. Read on every request, so it stays a plain array.
	 *
	 * The IndexNow key lives here too, but it is created the first time it
	 * is needed rather than shipped as a default, so every site gets its own.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'indexing_indexnow'     => true,
			'indexing_indexnow_key' => '',
			'indexing_google'       => false,
			'indexing_post_types'   => array( 'post', 'page' ),
			'indexing_events'       => array( 'publish', 'update', 'delete' ),
			'indexing_bulk_action'  => true,
		);
	}

	/**
	 * Card on the Modules screen.
	 *
	 * @return array
	 */
	public static function module(): array {
		return array(
			'title'       => __( 'Instant Indexing', 'tagwright-seo' ),
			'description' => __( 'Tells Bing, Yandex and other IndexNow engines (and optionally Google) the moment you publish, update or delete content.', 'tagwright-seo' ),
			'icon'        => 'zap',
			'settings'    => 'indexing',
		);
	}

	/**
	 * Settings tab.
	 *
	 * @return array
	 */
	public static function tab(): array {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( 'attachment' !== $post_type->name ) {
				$post_types[ $post_type->name ] = $post_type->labels->name;
			}
		}

		return array(
			'title'       => __( 'Instant Indexing', 'tagwright-seo' ),
			'description' => __( 'Ping search engines as soon as content changes instead of waiting for them to crawl your sitemap.', 'tagwright-seo' ),
			'icon'        => 'zap',
			'group'       => 'features',
			'module'      => 'indexing',
			'sections'    => array(
				array(
					'title'       => __( 'IndexNow', 'tagwright-seo' ),
					'description' => __( 'One open protocol shared by Bing, Yandex, Seznam, Naver and Yep. A ping to one of them reaches all of them.', 'tagwright-seo' ),
					'icon'        => 'zap',
					'fields'      => array(
						array(
							'id'           => 'indexing_indexnow',
							'type'         => 'toggle',
							'label'        => __( 'IndexNow', 'tagwright-seo' ),
							'description'  => __( 'Sends changed URLs to api.indexnow.org.', 'tagwright-seo' ),
							'toggle_label' => __( 'Submit URLs with IndexNow', 'tagwright-seo' ),
						),
						array(
							'id'      => 'indexing_indexnow_key_box',
							'type'    => 'html',
							'label'   => __( 'API key', 'tagwright-seo' ),
							'render'  => array( Admin_View::class, 'render_key' ),
							'show_if' => array(
								'field'   => 'indexing_indexnow',
								'checked' => true,
							),
						),
					),
				),
				array(
					'title'       => __( 'Google Indexing API', 'tagwright-seo' ),
					'description' => __( 'Optional. Needs a Google Cloud service account that is an owner of your property in Search Console.', 'tagwright-seo' ),
					'icon'        => 'globe',
					'fields'      => array(
						array(
							'id'           => 'indexing_google',
							'type'         => 'toggle',
							'label'        => __( 'Google', 'tagwright-seo' ),
							'description'  => __( 'Google officially supports this API only for pages with JobPosting or BroadcastEvent (livestream) structured data. Other pages may be ignored, and using it for them is against Google\'s guidelines.', 'tagwright-seo' ),
							'toggle_label' => __( 'Submit URLs to the Google Indexing API', 'tagwright-seo' ),
							'badge'        => __( 'Jobs & livestreams', 'tagwright-seo' ),
							'badge_type'   => 'ok',
						),
						array(
							'id'      => 'indexing_google_key_box',
							'type'    => 'html',
							'label'   => __( 'Service account key', 'tagwright-seo' ),
							'render'  => array( Admin_View::class, 'render_google_key' ),
							'show_if' => array(
								'field'   => 'indexing_google',
								'checked' => true,
							),
						),
					),
				),
				array(
					'title'  => __( 'What to submit', 'tagwright-seo' ),
					'icon'   => 'layers',
					'fields' => array(
						array(
							'id'          => 'indexing_post_types',
							'type'        => 'checkboxes',
							'label'       => __( 'Content types', 'tagwright-seo' ),
							'description' => __( 'Only public items that are not set to noindex are ever submitted.', 'tagwright-seo' ),
							'options'     => $post_types,
						),
						array(
							'id'      => 'indexing_events',
							'type'    => 'checkboxes',
							'label'   => __( 'Submit when', 'tagwright-seo' ),
							'options' => array(
								'publish' => __( 'Something is published', 'tagwright-seo' ),
								'update'  => __( 'A published item is updated', 'tagwright-seo' ),
								'delete'  => __( 'A published item is trashed, deleted or unpublished', 'tagwright-seo' ),
							),
						),
						array(
							'id'           => 'indexing_bulk_action',
							'type'         => 'toggle',
							'label'        => __( 'Bulk action', 'tagwright-seo' ),
							'description'  => __( 'Adds "Submit to search engines" to the bulk actions on the posts and pages lists.', 'tagwright-seo' ),
							'toggle_label' => __( 'Show the bulk action', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'       => __( 'Submit URLs now', 'tagwright-seo' ),
					'description' => __( 'Paste up to 500 URLs from this site, one per line. They go to every engine switched on above.', 'tagwright-seo' ),
					'icon'        => 'upload',
					'fields'      => array(
						array(
							'id'     => 'indexing_manual_box',
							'type'   => 'html',
							'wide'   => true,
							'render' => array( Admin_View::class, 'render_manual' ),
						),
					),
				),
				array(
					'title'       => __( 'Recent submissions', 'tagwright-seo' ),
					'description' => __( 'The last 50 requests sent to search engines.', 'tagwright-seo' ),
					'icon'        => 'clock',
					'fields'      => array(
						array(
							'id'     => 'indexing_log_box',
							'type'   => 'html',
							'wide'   => true,
							'render' => array( Admin_View::class, 'render_log' ),
						),
					),
				),
			),
		);
	}
}
