<?php
/**
 * Content Blocks feature.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Blocks;

use TagwrightSEO\Admin\Icons;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the blocks, their shortcodes, the theme function and the schema
 * the FAQ and HowTo blocks add.
 *
 * Every block is dynamic: the post only stores the block comment and its
 * attributes, and the markup is built when the page is shown. That keeps
 * breadcrumbs, reading time and related posts current, and means a markup
 * fix here reaches every post without re-saving it.
 */
class Feature {

	/**
	 * Script handle for the block editor code.
	 */
	const EDITOR_HANDLE = 'tagwright-seo-blocks';

	/**
	 * Style handle for the front end stylesheet.
	 */
	const STYLE_HANDLE = 'tagwright-seo-blocks-front';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		require_once __DIR__ . '/functions.php';

		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );

		if ( Options::get( 'blocks_faq_schema' ) || Options::get( 'blocks_howto_schema' ) ) {
			add_filter( 'tagwright_seo_schema_graph', array( Schema::class, 'add' ), 10, 2 );
		}
	}

	/**
	 * Registers assets, blocks and shortcodes.
	 *
	 * @return void
	 */
	public function register() {
		wp_register_style( self::STYLE_HANDLE, TAGWRIGHT_SEO_URL . 'assets/features/blocks/front.css', array(), TAGWRIGHT_SEO_VERSION );

		wp_register_script(
			self::EDITOR_HANDLE,
			TAGWRIGHT_SEO_URL . 'assets/features/blocks/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n', 'wp-data' ),
			TAGWRIGHT_SEO_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'tagwright-seo' );

		foreach ( $this->blocks() as $name => $args ) {
			register_block_type(
				'tagwright-seo/' . $name,
				array_merge(
					array(
						'api_version'           => 3,
						'category'              => 'tagwright-seo',
						'supports'              => array(
							'html'      => false,
							'className' => true,
						),
						'editor_script_handles' => array( self::EDITOR_HANDLE ),
						'editor_style_handles'  => array( self::STYLE_HANDLE ),
					),
					$args
				)
			);
		}

		add_shortcode( 'tagwright_breadcrumbs', array( Renderer::class, 'breadcrumbs_shortcode' ) );
		add_shortcode( 'tagwright_toc', array( Renderer::class, 'toc_shortcode' ) );
		add_shortcode( 'tagwright_reading_time', array( Renderer::class, 'reading_time_shortcode' ) );
		add_shortcode( 'tagwright_related', array( Renderer::class, 'related_shortcode' ) );
	}

	/**
	 * Block definitions. Attributes live here only; the editor script picks
	 * them up from the server so the two can never drift apart.
	 *
	 * Where a block has a site-wide setting, the attribute default is that
	 * setting. The editor leaves attributes that equal their default out of
	 * the saved block, so blocks nobody customised follow the setting when
	 * it changes later.
	 *
	 * @return array[]
	 */
	private function blocks() {
		$levels = array();
		foreach ( (array) Options::get( 'blocks_toc_levels', array( 'h2', 'h3' ) ) as $level ) {
			$levels[] = (int) substr( (string) $level, 1 );
		}
		$levels = array_values( array_filter( $levels ) );

		$toc_title = (string) Options::get( 'blocks_toc_title', '' );

		return array(
			'breadcrumbs'  => array(
				'title'           => __( 'Breadcrumbs', 'tagwright-seo' ),
				'description'     => __( 'The trail of links leading to the current page. Matches the breadcrumb schema.', 'tagwright-seo' ),
				'keywords'        => array( 'breadcrumb', 'trail', 'navigation' ),
				'attributes'      => array(
					'separator' => array(
						'type'    => 'string',
						'default' => '',
					),
					'prefix'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( Renderer::class, 'breadcrumbs_block' ),
			),
			'toc'          => array(
				'title'           => __( 'Table of Contents', 'tagwright-seo' ),
				'description'     => __( 'A linked list of the headings on this page.', 'tagwright-seo' ),
				'keywords'        => array( 'toc', 'contents', 'headings' ),
				'attributes'      => array(
					'title'       => array(
						'type'    => 'string',
						'default' => '' !== $toc_title ? $toc_title : __( 'Table of contents', 'tagwright-seo' ),
					),
					'levels'      => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => $levels ? $levels : array( 2, 3 ),
					),
					'listStyle'   => array(
						'type'    => 'string',
						'enum'    => array( 'ul', 'ol' ),
						'default' => 'ol' === Options::get( 'blocks_toc_list_style' ) ? 'ol' : 'ul',
					),
					'collapsible' => array(
						'type'    => 'boolean',
						'default' => (bool) Options::get( 'blocks_toc_collapsible' ),
					),
				),
				'render_callback' => array( Renderer::class, 'toc_block' ),
			),
			'faq'          => array(
				'title'           => __( 'FAQ', 'tagwright-seo' ),
				'description'     => __( 'Questions and answers, with FAQPage schema.', 'tagwright-seo' ),
				'keywords'        => array( 'faq', 'questions', 'schema' ),
				'attributes'      => array(
					'items'       => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array(
							'type'       => 'object',
							'properties' => array(
								'question' => array( 'type' => 'string' ),
								'answer'   => array( 'type' => 'string' ),
							),
						),
					),
					'collapsible' => array(
						'type'    => 'boolean',
						'default' => (bool) Options::get( 'blocks_faq_collapsible' ),
					),
				),
				'render_callback' => array( Renderer::class, 'faq_block' ),
			),
			'howto'        => array(
				'title'           => __( 'HowTo', 'tagwright-seo' ),
				'description'     => __( 'Step-by-step instructions, with HowTo schema.', 'tagwright-seo' ),
				'keywords'        => array( 'howto', 'steps', 'guide', 'schema' ),
				'attributes'      => array(
					'title'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'description' => array(
						'type'    => 'string',
						'default' => '',
					),
					'totalTime'   => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'cost'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'currency'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'supplies'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'tools'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'steps'       => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array(
							'type'       => 'object',
							'properties' => array(
								'title'   => array( 'type' => 'string' ),
								'text'    => array( 'type' => 'string' ),
								'imageId' => array( 'type' => 'integer' ),
							),
						),
					),
				),
				'render_callback' => array( Renderer::class, 'howto_block' ),
			),
			'reading-time' => array(
				'title'           => __( 'Reading Time', 'tagwright-seo' ),
				'description'     => __( 'Estimated time to read this post.', 'tagwright-seo' ),
				'keywords'        => array( 'reading', 'time', 'minutes' ),
				'render_callback' => array( Renderer::class, 'reading_time_block' ),
			),
			'related'      => array(
				'title'           => __( 'Related Posts', 'tagwright-seo' ),
				'description'     => __( 'Posts that share a category or tag with this one.', 'tagwright-seo' ),
				'keywords'        => array( 'related', 'similar', 'posts' ),
				'attributes'      => array(
					'title'     => array(
						'type'    => 'string',
						'default' => __( 'Related posts', 'tagwright-seo' ),
					),
					'count'     => array(
						'type'    => 'integer',
						'default' => 4,
					),
					'showImage' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => array( Renderer::class, 'related_block' ),
			),
		);
	}

	/**
	 * Adds the Tagwright SEO block category.
	 *
	 * @param array[] $categories Block categories.
	 * @return array[]
	 */
	public function category( $categories ) {
		foreach ( $categories as $category ) {
			if ( 'tagwright-seo' === ( $category['slug'] ?? '' ) ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => 'tagwright-seo',
			'title' => __( 'Tagwright SEO', 'tagwright-seo' ),
			'icon'  => null,
		);

		return $categories;
	}

	/**
	 * Hands the editor script the core icons it draws the block icons with.
	 *
	 * Done here rather than on init so front end requests never load the
	 * icon set.
	 *
	 * @return void
	 */
	public function editor_data() {
		$icons = array();
		foreach ( array( 'logo', 'arrow-right', 'book', 'info', 'layers', 'clock', 'link' ) as $name ) {
			$icons[ $name ] = Icons::PATHS[ $name ] ?? '';
		}

		wp_add_inline_script(
			self::EDITOR_HANDLE,
			'window.tagwrightSeoBlocks = ' . wp_json_encode( array( 'icons' => $icons ) ) . ';',
			'before'
		);
	}
}
