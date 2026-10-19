<?php
/**
 * Image SEO: defaults, module card and settings tab.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Image_Seo;

use TagwrightSEO\Admin\Icons;
use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Settings for automatic alt and title text.
 */
final class Settings {

	/**
	 * Default values. Read on every request, so it stays a plain array.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'image_seo_alt_template'    => '%filename%',
			'image_seo_alt_mode'        => 'empty',
			'image_seo_featured'        => true,
			'image_seo_add_title'       => false,
			'image_seo_title_template'  => '%imagetitle%',
			'image_seo_filename_case'   => 'sentence',
			'image_seo_on_upload'       => true,
			'image_seo_clean_filenames' => false,
		);
	}

	/**
	 * Module card.
	 *
	 * @return array
	 */
	public static function module(): array {
		return array(
			'title'       => __( 'Image SEO', 'tagwright-seo' ),
			'description' => __( 'Fills in missing alt and title text from templates, on the page and in the media library.', 'tagwright-seo' ),
			'icon'        => 'image',
			'settings'    => 'image_seo',
		);
	}

	/**
	 * Settings tab.
	 *
	 * @return array
	 */
	public static function tab(): array {
		// Variable names go in as arguments: "%filename%" inside a translatable
		// string looks like a printf placeholder to translation tools.
		$variables = sprintf(
			/* translators: 1: %filename%, 2: %imagetitle%, 3: %imagecaption%, 4: %imagealt%, 5: %title%. */
			__( 'Image variables: %1$s (the file name made readable), %2$s, %3$s and %4$s. %5$s is the post the image appears in, and the other variables work too.', 'tagwright-seo' ),
			'<code>%filename%</code>',
			'<code>%imagetitle%</code>',
			'<code>%imagecaption%</code>',
			'<code>%imagealt%</code>',
			'<code>%title%</code>'
		);

		return array(
			'title'       => __( 'Image SEO', 'tagwright-seo' ),
			'description' => __( 'Search engines and screen readers rely on alt text to understand images. Tagwright SEO can fill in what is missing.', 'tagwright-seo' ),
			'icon'        => 'image',
			'group'       => 'features',
			'module'      => 'image_seo',
			'sections'    => array(
				array(
					'title'       => __( 'Alt and title text', 'tagwright-seo' ),
					'icon'        => 'image',
					'description' => __( 'Applied while pages are shown. Nothing in your posts or media library is changed.', 'tagwright-seo' ),
					'fields'      => array(
						array(
							'id'          => 'image_seo_alt_template',
							'type'        => 'template',
							'label'       => __( 'Alt text', 'tagwright-seo' ),
							'description' => $variables,
						),
						array(
							'id'          => 'image_seo_alt_mode',
							'type'        => 'segmented',
							'label'       => __( 'Use it for', 'tagwright-seo' ),
							'description' => __( 'Replacing all alt text also overrides text you wrote yourself. Most sites should only fill in what is missing.', 'tagwright-seo' ),
							'options'     => array(
								'empty' => __( 'Images without alt text', 'tagwright-seo' ),
								'all'   => __( 'Every image', 'tagwright-seo' ),
							),
						),
						array(
							'id'           => 'image_seo_featured',
							'type'         => 'toggle',
							'label'        => __( 'Featured images', 'tagwright-seo' ),
							'toggle_label' => __( 'Also fill in alt text for featured images', 'tagwright-seo' ),
						),
						array(
							'id'           => 'image_seo_add_title',
							'type'         => 'toggle',
							'label'        => __( 'Title attribute', 'tagwright-seo' ),
							'toggle_label' => __( 'Add a title to images that have none', 'tagwright-seo' ),
							'description'  => __( 'Shown as a tooltip on hover. It has little SEO value, so it is off by default.', 'tagwright-seo' ),
						),
						array(
							'id'      => 'image_seo_title_template',
							'type'    => 'template',
							'label'   => __( 'Title text', 'tagwright-seo' ),
							'show_if' => array(
								'field'   => 'image_seo_add_title',
								'checked' => true,
							),
						),
					),
				),
				array(
					'title'  => __( 'File names and uploads', 'tagwright-seo' ),
					'icon'   => 'upload',
					'fields' => array(
						array(
							'id'          => 'image_seo_filename_case',
							'type'        => 'segmented',
							'label'       => __( 'Capitalization', 'tagwright-seo' ),
							'description' => sprintf(
								/* translators: %s: the %filename% variable. */
								__( 'How %s is written. "red-apple_02.jpg" becomes "Red apple 02".', 'tagwright-seo' ),
								'<code>%filename%</code>'
							),
							'options'     => array(
								'none'     => __( 'As is', 'tagwright-seo' ),
								'sentence' => __( 'First letter', 'tagwright-seo' ),
								'title'    => __( 'Every word', 'tagwright-seo' ),
							),
						),
						array(
							'id'           => 'image_seo_on_upload',
							'type'         => 'toggle',
							'label'        => __( 'On upload', 'tagwright-seo' ),
							'toggle_label' => __( 'Save alt text and a clean title from the file name for new images', 'tagwright-seo' ),
							'description'  => __( 'Only when the image has no alt text yet, and only when the title is still the raw file name.', 'tagwright-seo' ),
						),
						array(
							'id'           => 'image_seo_clean_filenames',
							'type'         => 'toggle',
							'label'        => __( 'Clean file names', 'tagwright-seo' ),
							'toggle_label' => __( 'Rename new uploads to lowercase, with dashes instead of spaces', 'tagwright-seo' ),
							'description'  => __( '"My Photo (1).JPG" is saved as "my-photo-1.jpg". Files already in the media library are not renamed.', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'       => __( 'Fill in existing images', 'tagwright-seo' ),
					'icon'        => 'zap',
					'description' => __( 'Saves alt text to images in your media library that have none, using the saved alt text template. Images that already have alt text are not touched.', 'tagwright-seo' ),
					'fields'      => array(
						array(
							'id'     => 'image_seo_bulk',
							'type'   => 'html',
							'wide'   => true,
							'render' => array( __CLASS__, 'render_bulk' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Bulk fill tool. The count and examples load over REST once the tab is
	 * opened, so the settings screen itself never waits on media queries.
	 *
	 * @return void
	 */
	public static function render_bulk() {
		if ( ! Modules::is_active( 'image_seo' ) ) {
			echo '<p class="tagwright-muted">' . esc_html__( 'Switch the Image SEO module on to use this tool.', 'tagwright-seo' ) . '</p>';
			return;
		}

		wp_enqueue_script( 'tagwright-seo-image-seo', TAGWRIGHT_SEO_URL . 'assets/features/image-seo/admin.js', array( 'tagwright-seo-admin', 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
		wp_set_script_translations( 'tagwright-seo-image-seo', 'tagwright-seo' );
		?>
		<div data-tagwright-image-seo-bulk>
			<p class="tagwright-field__description" data-bulk-summary><?php esc_html_e( 'Counting images…', 'tagwright-seo' ); ?></p>
			<div data-bulk-preview hidden>
				<table class="tagwright-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Image', 'tagwright-seo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'File', 'tagwright-seo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Alt text it will get', 'tagwright-seo' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
			<div class="tagwright-progress" data-bulk-progress hidden><span></span></div>
			<p>
				<button type="button" class="tagwright-btn tagwright-btn--primary" data-bulk-run disabled>
					<?php Icons::render( 'zap', 16 ); ?>
					<?php esc_html_e( 'Fill in missing alt text', 'tagwright-seo' ); ?>
				</button>
			</p>
			<p class="tagwright-field__description"><?php esc_html_e( 'Save your settings first: the tool uses the saved template, not unsaved changes on this screen.', 'tagwright-seo' ); ?></p>
		</div>
		<?php
	}
}
