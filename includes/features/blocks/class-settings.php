<?php
/**
 * Content Blocks: defaults, module card and settings tab.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Blocks;

use TagwrightSEO\Admin\Icons;
use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Settings for the breadcrumbs, table of contents, FAQ, HowTo, reading
 * time and related posts blocks.
 */
final class Settings {

	/**
	 * Default values. Read on every request, so it stays a plain array.
	 *
	 * Empty text settings mean "use the built-in wording", which keeps the
	 * defaults translatable without calling __() this early.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'blocks_bc_separator'    => '›',
			'blocks_bc_home_label'   => '',
			'blocks_bc_prefix'       => '',
			'blocks_bc_show_current' => true,
			'blocks_bc_link_current' => false,
			'blocks_toc_title'       => '',
			'blocks_toc_levels'      => array( 'h2', 'h3' ),
			'blocks_toc_list_style'  => 'ul',
			'blocks_toc_collapsible' => false,
			'blocks_faq_schema'      => true,
			'blocks_faq_collapsible' => false,
			'blocks_howto_schema'    => true,
			'blocks_reading_wpm'     => 200,
			'blocks_reading_prefix'  => '',
			'blocks_reading_suffix'  => '',
		);
	}

	/**
	 * Module card.
	 *
	 * @return array
	 */
	public static function module(): array {
		return array(
			'title'       => __( 'Content Blocks', 'tagwright-seo' ),
			'description' => __( 'Breadcrumbs, table of contents, FAQ, HowTo, reading time and related posts, as blocks and shortcodes.', 'tagwright-seo' ),
			'icon'        => 'blocks',
			'settings'    => 'blocks',
		);
	}

	/**
	 * Settings tab.
	 *
	 * @return array
	 */
	public static function tab(): array {
		$levels = array();
		foreach ( range( 2, 6 ) as $level ) {
			$levels[ 'h' . $level ] = 'H' . $level;
		}

		return array(
			'title'       => __( 'Content Blocks', 'tagwright-seo' ),
			'description' => __( 'Defaults for the Tagwright SEO blocks and shortcodes. Each block can still be adjusted where you use it.', 'tagwright-seo' ),
			'icon'        => 'blocks',
			'group'       => 'features',
			'module'      => 'blocks',
			'sections'    => array(
				array(
					'title'       => __( 'Breadcrumbs', 'tagwright-seo' ),
					'icon'        => 'arrow-right',
					'description' => __( 'The visible trail uses the same data as the BreadcrumbList schema, so the two always match.', 'tagwright-seo' ),
					'fields'      => array(
						array(
							'id'          => 'blocks_bc_separator',
							'type'        => 'text',
							'label'       => __( 'Separator', 'tagwright-seo' ),
							'placeholder' => '›',
						),
						array(
							'id'          => 'blocks_bc_home_label',
							'type'        => 'text',
							'label'       => __( 'Home label', 'tagwright-seo' ),
							'placeholder' => __( 'Home', 'tagwright-seo' ),
						),
						array(
							'id'          => 'blocks_bc_prefix',
							'type'        => 'text',
							'label'       => __( 'Text before the trail', 'tagwright-seo' ),
							'description' => __( 'Optional, for example "You are here:".', 'tagwright-seo' ),
						),
						array(
							'id'           => 'blocks_bc_show_current',
							'type'         => 'toggle',
							'label'        => __( 'Current page', 'tagwright-seo' ),
							'toggle_label' => __( 'Show the current page at the end of the trail', 'tagwright-seo' ),
						),
						array(
							'id'           => 'blocks_bc_link_current',
							'type'         => 'toggle',
							'label'        => __( 'Link the current page', 'tagwright-seo' ),
							'toggle_label' => __( 'Make the last item a link', 'tagwright-seo' ),
							'show_if'      => array(
								'field'   => 'blocks_bc_show_current',
								'checked' => true,
							),
						),
					),
				),
				array(
					'title'  => __( 'Table of contents', 'tagwright-seo' ),
					'icon'   => 'book',
					'fields' => array(
						array(
							'id'          => 'blocks_toc_title',
							'type'        => 'text',
							'label'       => __( 'Title', 'tagwright-seo' ),
							'placeholder' => __( 'Table of contents', 'tagwright-seo' ),
						),
						array(
							'id'          => 'blocks_toc_levels',
							'type'        => 'checkboxes',
							'label'       => __( 'Headings to include', 'tagwright-seo' ),
							'description' => __( 'Headings without an id get one automatically, but only on pages that show a table of contents.', 'tagwright-seo' ),
							'options'     => $levels,
						),
						array(
							'id'      => 'blocks_toc_list_style',
							'type'    => 'segmented',
							'label'   => __( 'List style', 'tagwright-seo' ),
							'options' => array(
								'ul' => __( 'Bullets', 'tagwright-seo' ),
								'ol' => __( 'Numbers', 'tagwright-seo' ),
							),
						),
						array(
							'id'           => 'blocks_toc_collapsible',
							'type'         => 'toggle',
							'label'        => __( 'Collapsible', 'tagwright-seo' ),
							'toggle_label' => __( 'Let visitors fold the list away', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'  => __( 'FAQ and HowTo', 'tagwright-seo' ),
					'icon'   => 'code',
					'fields' => array(
						array(
							'id'           => 'blocks_faq_schema',
							'type'         => 'toggle',
							'label'        => __( 'FAQ schema', 'tagwright-seo' ),
							'toggle_label' => __( 'Add FAQPage schema to pages with an FAQ block', 'tagwright-seo' ),
						),
						array(
							'id'           => 'blocks_faq_collapsible',
							'type'         => 'toggle',
							'label'        => __( 'Collapsible answers', 'tagwright-seo' ),
							'toggle_label' => __( 'New FAQ blocks show answers behind a click', 'tagwright-seo' ),
						),
						array(
							'id'           => 'blocks_howto_schema',
							'type'         => 'toggle',
							'label'        => __( 'HowTo schema', 'tagwright-seo' ),
							'toggle_label' => __( 'Add HowTo schema to pages with a HowTo block', 'tagwright-seo' ),
							'description'  => __( 'Google no longer shows HowTo rich results, but the markup is valid and other search engines and AI tools still read it.', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'  => __( 'Reading time', 'tagwright-seo' ),
					'icon'   => 'clock',
					'fields' => array(
						array(
							'id'     => 'blocks_reading_wpm',
							'type'   => 'number',
							'label'  => __( 'Reading speed', 'tagwright-seo' ),
							'min'    => 50,
							'max'    => 1000,
							'suffix' => __( 'words/min', 'tagwright-seo' ),
						),
						array(
							'id'          => 'blocks_reading_prefix',
							'type'        => 'text',
							'label'       => __( 'Text before', 'tagwright-seo' ),
							'placeholder' => __( 'Reading time:', 'tagwright-seo' ),
						),
						array(
							'id'    => 'blocks_reading_suffix',
							'type'  => 'text',
							'label' => __( 'Text after', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'  => __( 'How to add them', 'tagwright-seo' ),
					'icon'   => 'info',
					'fields' => array(
						array(
							'id'     => 'blocks_usage',
							'type'   => 'html',
							'wide'   => true,
							'render' => array( __CLASS__, 'render_usage' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Explains the three ways to use the blocks, with snippets to copy.
	 *
	 * @return void
	 */
	public static function render_usage() {
		if ( Modules::is_active( 'blocks' ) ) {
			wp_enqueue_script( 'tagwright-seo-blocks-admin', TAGWRIGHT_SEO_URL . 'assets/features/blocks/admin.js', array( 'tagwright-seo-admin', 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
			wp_set_script_translations( 'tagwright-seo-blocks-admin', 'tagwright-seo' );
		}

		$snippets = array(
			array(
				'label' => __( 'Breadcrumbs in a classic theme (header.php or single.php)', 'tagwright-seo' ),
				'code'  => "<?php if ( function_exists( 'tagwright_seo_breadcrumbs' ) ) { tagwright_seo_breadcrumbs(); } ?>",
			),
			array(
				'label' => __( 'Breadcrumbs shortcode', 'tagwright-seo' ),
				'code'  => '[tagwright_breadcrumbs]',
			),
			array(
				'label' => __( 'Table of contents shortcode', 'tagwright-seo' ),
				'code'  => '[tagwright_toc levels="2,3" style="ul" collapsible="no"]',
			),
			array(
				'label' => __( 'Reading time shortcode', 'tagwright-seo' ),
				'code'  => '[tagwright_reading_time]',
			),
			array(
				'label' => __( 'Related posts shortcode', 'tagwright-seo' ),
				'code'  => '[tagwright_related count="4" title="Related posts"]',
			),
		);
		?>
		<p class="tagwright-field__description">
			<?php esc_html_e( 'In the block editor, search for "Tagwright SEO" in the block inserter: every block lives in its own Tagwright SEO category. In the classic editor, widgets or page builders, use the shortcodes. Themes can print the breadcrumbs with the template function.', 'tagwright-seo' ); ?>
		</p>
		<div class="tagwright-grid">
			<?php foreach ( $snippets as $index => $snippet ) : ?>
				<?php $snippet_id = 'tagwright-blocks-snippet-' . $index; ?>
				<div>
					<label class="tagwright-group__label" for="<?php echo esc_attr( $snippet_id ); ?>"><?php echo esc_html( $snippet['label'] ); ?></label>
					<div class="tagwright-input-wrap">
						<input type="text" id="<?php echo esc_attr( $snippet_id ); ?>" class="tagwright-input tagwright-textarea--code" value="<?php echo esc_attr( $snippet['code'] ); ?>" readonly />
					</div>
					<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm" data-tagwright-copy="<?php echo esc_attr( $snippet_id ); ?>">
						<?php Icons::render( 'copy', 14 ); ?>
						<?php esc_html_e( 'Copy', 'tagwright-seo' ); ?>
					</button>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
