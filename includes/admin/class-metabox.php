<?php
/**
 * The SEO box in the post editor.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Data\Objects;
use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Modules;
use TagwrightSEO\Options;
use TagwrightSEO\Schema\Graph;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a tabbed meta box below the content in both the block editor and
 * the classic editor, and saves its fields to the objects table.
 */
class Metabox {

	/**
	 * Form field prefix.
	 */
	const PREFIX = 'tagwright_seo';

	/**
	 * Nonce action.
	 */
	const NONCE = 'tagwright_seo_save_meta';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the meta box to public post types.
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post      Post being edited.
	 * @return void
	 */
	public function add( $post_type, $post ) {
		if ( ! self::is_supported( $post_type ) ) {
			return;
		}

		add_meta_box(
			'tagwright_seo_metabox',
			__( 'Tagwright SEO', 'tagwright-seo' ),
			array( $this, 'render' ),
			$post_type,
			'normal',
			'high',
			array( '__back_compat_meta_box' => false )
		);
	}

	/**
	 * Whether the box is shown for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_supported( $post_type ) {
		$supported = is_post_type_viewable( $post_type ) && 'attachment' !== $post_type;

		/**
		 * Filters whether the Tagwright SEO box shows for a post type.
		 *
		 * @param bool   $supported Whether it is shown.
		 * @param string $post_type Post type.
		 */
		return (bool) apply_filters( 'tagwright_seo_metabox_supported', $supported, $post_type );
	}

	/**
	 * Loads the editor script on post screens.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! self::is_supported( $post->post_type ) ) {
			return;
		}

		self::enqueue_editor_assets( self::editor_data_for_post( $post ) );
	}

	/**
	 * Enqueues the editor scripts with their data. Shared with the term screen.
	 *
	 * @param array $data Data for tagwrightSeoEditor.
	 * @return void
	 */
	public static function enqueue_editor_assets( array $data ) {
		Admin::register_assets();
		wp_enqueue_media();

		wp_enqueue_script( 'tagwright-seo-analysis', TAGWRIGHT_SEO_URL . 'assets/js/analysis.js', array( 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
		wp_enqueue_script( 'tagwright-seo-editor', TAGWRIGHT_SEO_URL . 'assets/js/editor.js', array( 'tagwright-seo-admin', 'tagwright-seo-analysis', 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
		wp_add_inline_script( 'tagwright-seo-editor', 'window.tagwrightSeoEditor = ' . wp_json_encode( $data ) . ';', 'before' );

		wp_set_script_translations( 'tagwright-seo-analysis', 'tagwright-seo' );
		wp_set_script_translations( 'tagwright-seo-editor', 'tagwright-seo' );
	}

	/**
	 * Data the editor script needs for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function editor_data_for_post( $post ) {
		$context = Context::for_post( $post );

		return array(
			'objectType'    => 'post',
			'objectId'      => (int) $post->ID,
			'postType'      => $post->post_type,
			'editor'        => use_block_editor_for_post( $post ) ? 'block' : 'classic',
			'defaults'      => array(
				'title'       => $context->setting_for( 'title' ),
				'description' => $context->setting_for( 'description' ),
			),
			'variables'     => self::variable_values( $context ),
			'permalink'     => (string) get_permalink( $post ),
			'siteName'      => get_bloginfo( 'name' ),
			'homeUrl'       => home_url( '/' ),
			'favicon'       => get_site_icon_url( 32 ),
			'analysis'      => Modules::is_active( 'content_analysis' ) && Options::get( 'pt_' . $post->post_type . '_analysis' ),
			'readability'   => (bool) Options::get( 'analysis_readability' ),
			'locale'        => get_locale(),
			'defaultImage'  => (string) wp_get_attachment_image_url( (int) Options::get( 'og_default_image_id' ), 'large' ),
			'featuredImage' => (string) get_the_post_thumbnail_url( $post, 'large' ),
		);
	}

	/**
	 * Resolves every variable once so the preview can replace them in the browser.
	 *
	 * @param Context $context Context for the object.
	 * @return array<string, string>
	 */
	public static function variable_values( Context $context ) {
		$values = array();

		foreach ( array_keys( Replacer::describe() ) as $name ) {
			if ( false !== strpos( $name, '(' ) ) {
				continue;
			}
			$values[ $name ] = Replacer::replace( '%' . $name . '%', $context );
		}

		return array_merge( $values, Replacer::static_values() );
	}

	/**
	 * Prints the meta box.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render( $post ) {
		$row = Objects::get( 'post', $post->ID );

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		self::render_box(
			$row,
			array(
				'object_type'  => 'post',
				'post_type'    => $post->post_type,
				'analysis'     => Modules::is_active( 'content_analysis' ) && Options::get( 'pt_' . $post->post_type . '_analysis' ),
				'schema'       => Modules::is_active( 'schema' ),
				'social'       => Modules::is_active( 'social' ),
				'page_type'    => (string) Options::get( 'pt_' . $post->post_type . '_page_type', 'WebPage' ),
				'article_type' => (string) Options::get( 'pt_' . $post->post_type . '_article_type', 'None' ),
			)
		);
	}

	/**
	 * Prints the tabbed box. Used by posts and terms.
	 *
	 * @param array|null $row  Stored row.
	 * @param array      $args Box options.
	 * @return void
	 */
	public static function render_box( $row, array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'object_type'  => 'post',
				'post_type'    => '',
				'analysis'     => false,
				'schema'       => false,
				'social'       => true,
				'page_type'    => 'WebPage',
				'article_type' => 'None',
			)
		);

		$value = static function ( $key ) use ( $row ) {
			return $row[ $key ] ?? null;
		};

		$field = static function ( array $field ) use ( $value ) {
			$field['prefix'] = self::PREFIX;
			if ( ! array_key_exists( 'value', $field ) ) {
				$field['value'] = $value( $field['id'] );
			}
			UI::field( $field );
		};

		$tabs = array( 'general' => __( 'General', 'tagwright-seo' ) );
		if ( $args['social'] ) {
			$tabs['social'] = __( 'Social', 'tagwright-seo' );
		}
		$tabs['advanced'] = __( 'Advanced', 'tagwright-seo' );
		if ( $args['schema'] && 'post' === $args['object_type'] ) {
			$tabs['schema'] = __( 'Schema', 'tagwright-seo' );
		}

		/**
		 * Filters the tabs in the editor box. Add-ons add a tab here and
		 * print its panel on the `tagwright_seo_metabox_panel_{tab}` action.
		 *
		 * @param array $tabs Tab id => label.
		 * @param array $args Box options.
		 */
		$tabs = apply_filters( 'tagwright_seo_metabox_tabs', $tabs, $args );

		$tristate = array(
			''  => __( 'Default', 'tagwright-seo' ),
			'0' => __( 'No', 'tagwright-seo' ),
			'1' => __( 'Yes', 'tagwright-seo' ),
		);
		?>
		<div class="tagwright-metabox" data-object-type="<?php echo esc_attr( $args['object_type'] ); ?>">
			<div class="tagwright-metabox__head">
				<div class="tagwright-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Tagwright SEO', 'tagwright-seo' ); ?>">
					<?php $first = true; ?>
					<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
						<button type="button" role="tab" id="tagwright-tab-<?php echo esc_attr( $tab_id ); ?>" data-tab="<?php echo esc_attr( $tab_id ); ?>" aria-controls="tagwright-tabpanel-<?php echo esc_attr( $tab_id ); ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>">
							<?php echo esc_html( $tab_label ); ?>
						</button>
						<?php $first = false; ?>
					<?php endforeach; ?>
				</div>
				<?php if ( $args['analysis'] ) : ?>
					<div class="tagwright-metabox__scores" aria-live="polite">
						<span class="tagwright-metabox__score"><?php esc_html_e( 'SEO', 'tagwright-seo' ); ?> <span data-score="seo"><?php echo UI::score_badge( $value( 'seo_score' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?></span></span>
						<?php if ( Options::get( 'analysis_readability' ) ) : ?>
							<span class="tagwright-metabox__score"><?php esc_html_e( 'Readability', 'tagwright-seo' ); ?> <span data-score="readability"><?php echo UI::score_badge( $value( 'readability_score' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?></span></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="tagwright-metabox__body">
				<?php // General. ?>
				<div class="tagwright-tabpanel" role="tabpanel" id="tagwright-tabpanel-general" aria-labelledby="tagwright-tab-general">
					<div class="tagwright-preview-block">
						<div class="tagwright-preview-block__bar">
							<h4><?php esc_html_e( 'Search preview', 'tagwright-seo' ); ?></h4>
							<div class="tagwright-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Preview device', 'tagwright-seo' ); ?>">
								<input type="radio" id="tagwright-device-desktop" name="tagwright_preview_device" value="desktop" checked /><label for="tagwright-device-desktop"><?php esc_html_e( 'Desktop', 'tagwright-seo' ); ?></label>
								<input type="radio" id="tagwright-device-mobile" name="tagwright_preview_device" value="mobile" /><label for="tagwright-device-mobile"><?php esc_html_e( 'Mobile', 'tagwright-seo' ); ?></label>
							</div>
						</div>
						<div class="tagwright-serp" data-preview="serp">
							<div class="tagwright-serp__site">
								<span class="tagwright-serp__favicon"></span>
								<span>
									<span class="tagwright-serp__name"></span>
									<span class="tagwright-serp__url"></span>
								</span>
							</div>
							<span class="tagwright-serp__title"></span>
							<p class="tagwright-serp__description"></p>
						</div>
					</div>

					<?php
					$field(
						array(
							'id'          => 'title',
							'type'        => 'template',
							'label'       => __( 'SEO title', 'tagwright-seo' ),
							'description' => __( 'Leave empty to use the default template.', 'tagwright-seo' ),
							'counter'     => 'title',
						)
					);
					$field(
						array(
							'id'          => 'description',
							'type'        => 'template_textarea',
							'label'       => __( 'Meta description', 'tagwright-seo' ),
							'description' => __( 'A short pitch for searchers. Leave empty to use the default.', 'tagwright-seo' ),
							'counter'     => 'description',
							'rows'        => 3,
						)
					);

					if ( Options::get( 'meta_keywords' ) ) {
						$field(
							array(
								'id'          => 'meta_keywords',
								'type'        => 'text',
								'label'       => __( 'Meta keywords', 'tagwright-seo' ),
								'description' => __( 'Comma separated. Leave empty to use the default for this content type.', 'tagwright-seo' ),
								'badge'       => __( 'Legacy', 'tagwright-seo' ),
								'badge_type'  => 'muted',
							)
						);
					}

					if ( $args['analysis'] ) {
						$field(
							array(
								'id'          => 'focus_keyphrase',
								'type'        => 'text',
								'label'       => __( 'Focus keyphrase', 'tagwright-seo' ),
								'description' => __( 'The search term you most want this page to rank for.', 'tagwright-seo' ),
								'attrs'       => array(
									'maxlength'    => 191,
									'autocomplete' => 'off',
								),
							)
						);
						?>
						<input type="hidden" name="<?php echo esc_attr( self::PREFIX ); ?>[seo_score]" value="<?php echo esc_attr( (string) $value( 'seo_score' ) ); ?>" data-score-input="seo" />
						<input type="hidden" name="<?php echo esc_attr( self::PREFIX ); ?>[readability_score]" value="<?php echo esc_attr( (string) $value( 'readability_score' ) ); ?>" data-score-input="readability" />
						<div class="tagwright-analysis" data-analysis>
							<div class="tagwright-analysis__summary">
								<div class="tagwright-ring" data-ring="seo">
									<svg width="64" height="64" viewBox="0 0 64 64" aria-hidden="true"><circle class="tagwright-ring__track" cx="32" cy="32" r="27" stroke-width="7" fill="none"/><circle class="tagwright-ring__value" cx="32" cy="32" r="27" stroke-width="7" fill="none" stroke-linecap="round" stroke-dasharray="169.6" stroke-dashoffset="169.6"/></svg>
									<span class="tagwright-ring__label">–</span>
								</div>
								<div class="tagwright-analysis__headline">
									<strong data-analysis-headline><?php esc_html_e( 'Add a focus keyphrase to start', 'tagwright-seo' ); ?></strong>
									<span data-analysis-sub><?php esc_html_e( 'Checks update as you write.', 'tagwright-seo' ); ?></span>
								</div>
							</div>
							<ul class="tagwright-checks" data-checks></ul>
						</div>
						<?php
					}
					?>
				</div>

				<?php // Social. ?>
				<?php if ( $args['social'] && isset( $tabs['social'] ) ) : ?>
					<div class="tagwright-tabpanel" role="tabpanel" id="tagwright-tabpanel-social" aria-labelledby="tagwright-tab-social" hidden>
						<div class="tagwright-preview-block">
							<div class="tagwright-preview-block__bar"><h4><?php esc_html_e( 'Facebook, LinkedIn and others', 'tagwright-seo' ); ?></h4></div>
							<div class="tagwright-social-card" data-preview="facebook">
								<div class="tagwright-social-card__image"><?php Icons::render( 'image', 36 ); ?></div>
								<div class="tagwright-social-card__body">
									<div class="tagwright-social-card__domain"></div>
									<div class="tagwright-social-card__title"></div>
									<p class="tagwright-social-card__desc"></p>
								</div>
							</div>
						</div>
						<?php
						$field(
							array(
								'id'          => 'og_title',
								'type'        => 'template',
								'label'       => __( 'Social title', 'tagwright-seo' ),
								'description' => __( 'Leave empty to use the SEO title.', 'tagwright-seo' ),
							)
						);
						$field(
							array(
								'id'          => 'og_description',
								'type'        => 'template_textarea',
								'label'       => __( 'Social description', 'tagwright-seo' ),
								'description' => __( 'Leave empty to use the meta description.', 'tagwright-seo' ),
							)
						);
						$field(
							array(
								'id'          => 'og_image_id',
								'type'        => 'image',
								'label'       => __( 'Social image', 'tagwright-seo' ),
								'description' => __( 'Leave empty to use the featured image.', 'tagwright-seo' ),
								'recommended' => __( 'Recommended size: 1200 × 630 pixels.', 'tagwright-seo' ),
							)
						);
						?>
						<div class="tagwright-preview-block">
							<div class="tagwright-preview-block__bar"><h4><?php esc_html_e( 'X (Twitter)', 'tagwright-seo' ); ?></h4></div>
							<div class="tagwright-social-card tagwright-social-card--x" data-preview="x">
								<div class="tagwright-social-card__image"><?php Icons::render( 'image', 36 ); ?></div>
								<div class="tagwright-social-card__body">
									<div class="tagwright-social-card__title"></div>
									<p class="tagwright-social-card__desc"></p>
									<div class="tagwright-social-card__domain"></div>
								</div>
							</div>
						</div>
						<?php
						$field(
							array(
								'id'          => 'twitter_title',
								'type'        => 'template',
								'label'       => __( 'X title', 'tagwright-seo' ),
								'description' => __( 'Leave empty to use the social title.', 'tagwright-seo' ),
							)
						);
						$field(
							array(
								'id'          => 'twitter_description',
								'type'        => 'template_textarea',
								'label'       => __( 'X description', 'tagwright-seo' ),
								'description' => __( 'Leave empty to use the social description.', 'tagwright-seo' ),
							)
						);

						/**
						 * Prints extra social settings at the end of the Social tab.
						 * Tagwright SEO Pro adds branded images and Pinterest here.
						 *
						 * @since 1.5.0
						 *
						 * @param array|null $row  Stored row.
						 * @param array      $args Box options.
						 */
						do_action( 'tagwright_seo_metabox_social_panel', $row, $args );
						?>
					</div>
				<?php endif; ?>

				<?php // Advanced. ?>
				<?php if ( isset( $tabs['advanced'] ) ) : ?>
					<div class="tagwright-tabpanel" role="tabpanel" id="tagwright-tabpanel-advanced" aria-labelledby="tagwright-tab-advanced" hidden>
						<?php
						$field(
							array(
								'id'          => 'robots_noindex',
								'type'        => 'tristate',
								'label'       => __( 'Hide from search results', 'tagwright-seo' ),
								'description' => __( '"Default" follows the setting for this content type.', 'tagwright-seo' ),
								'options'     => $tristate,
							)
						);
						$field(
							array(
								'id'          => 'robots_nofollow',
								'type'        => 'tristate',
								'label'       => __( 'Ask search engines not to follow links', 'tagwright-seo' ),
								'description' => __( 'Adds nofollow to the robots tag.', 'tagwright-seo' ),
								'options'     => $tristate,
							)
						);
						$field(
							array(
								'id'           => 'robots_noarchive',
								'type'         => 'toggle',
								'label'        => __( 'No archive', 'tagwright-seo' ),
								'toggle_label' => __( 'Don\'t show a cached copy', 'tagwright-seo' ),
							)
						);
						$field(
							array(
								'id'           => 'robots_nosnippet',
								'type'         => 'toggle',
								'label'        => __( 'No snippet', 'tagwright-seo' ),
								'toggle_label' => __( 'Don\'t show a text snippet in results', 'tagwright-seo' ),
							)
						);
						$field(
							array(
								'id'           => 'robots_noimageindex',
								'type'         => 'toggle',
								'label'        => __( 'No image index', 'tagwright-seo' ),
								'toggle_label' => __( 'Don\'t index images on this page', 'tagwright-seo' ),
							)
						);
						$field(
							array(
								'id'          => 'max_snippet',
								'type'        => 'number',
								'label'       => __( 'Max snippet length', 'tagwright-seo' ),
								'description' => __( 'Characters. -1 means no limit, empty uses the default.', 'tagwright-seo' ),
								'min'         => -1,
								'placeholder' => '-1',
							)
						);
						$field(
							array(
								'id'      => 'max_image_preview',
								'type'    => 'select',
								'label'   => __( 'Max image preview', 'tagwright-seo' ),
								'options' => array(
									''         => __( 'Default (large)', 'tagwright-seo' ),
									'large'    => __( 'Large', 'tagwright-seo' ),
									'standard' => __( 'Standard', 'tagwright-seo' ),
									'none'     => __( 'None', 'tagwright-seo' ),
								),
							)
						);
						$field(
							array(
								'id'          => 'max_video_preview',
								'type'        => 'number',
								'label'       => __( 'Max video preview', 'tagwright-seo' ),
								'description' => __( 'Seconds. -1 means no limit, empty uses the default.', 'tagwright-seo' ),
								'min'         => -1,
								'placeholder' => '-1',
							)
						);
						$field(
							array(
								'id'          => 'canonical',
								'type'        => 'url',
								'label'       => __( 'Canonical URL', 'tagwright-seo' ),
								'description' => __( 'Only set this when the same content lives at another address that should rank instead.', 'tagwright-seo' ),
								'placeholder' => __( 'Automatic', 'tagwright-seo' ),
							)
						);
						$field(
							array(
								'id'           => 'sitemap_exclude',
								'type'         => 'toggle',
								'label'        => __( 'XML sitemap', 'tagwright-seo' ),
								'toggle_label' => __( 'Leave this out of the sitemap', 'tagwright-seo' ),
							)
						);
						foreach ( array( 'sitemap_priority', 'sitemap_changefreq' ) as $sitemap_field ) {
							$is_priority = 'sitemap_priority' === $sitemap_field;
							$field(
								array(
									'id'          => $sitemap_field,
									'type'        => 'select',
									'label'       => $is_priority ? __( 'Sitemap priority', 'tagwright-seo' ) : __( 'Sitemap change frequency', 'tagwright-seo' ),
									'description' => $is_priority ? __( 'Overrides the priority set for this content type.', 'tagwright-seo' ) : __( 'How often this page usually changes.', 'tagwright-seo' ),
									'options'     => array( '' => __( 'Default', 'tagwright-seo' ) ) + ( $is_priority ? \TagwrightSEO\Sitemaps\Builder::priority_options() : \TagwrightSEO\Sitemaps\Builder::frequency_options() ),
									'value'       => $is_priority && null !== $value( 'sitemap_priority' ) ? \TagwrightSEO\Sitemaps\Builder::format_priority( $value( 'sitemap_priority' ) ) : (string) $value( $sitemap_field ),
									'show_if'     => array(
										'prefix'  => self::PREFIX,
										'field'   => 'sitemap_exclude',
										'checked' => false,
									),
								)
							);
						}
						?>
					</div>
				<?php endif; ?>

				<?php // Schema. ?>
				<?php if ( isset( $tabs['schema'] ) ) : ?>
					<div class="tagwright-tabpanel" role="tabpanel" id="tagwright-tabpanel-schema" aria-labelledby="tagwright-tab-schema" hidden>
						<?php
						$page_types = Graph::page_types();
						$field(
							array(
								'id'      => 'schema_page_type',
								'type'    => 'select',
								'label'   => __( 'Page type', 'tagwright-seo' ),
								'options' => array_merge(
									/* translators: %s: default schema type. */
									array( '' => sprintf( __( 'Default (%s)', 'tagwright-seo' ), $page_types[ $args['page_type'] ] ?? $args['page_type'] ) ),
									$page_types
								),
							)
						);
						$article_types = Graph::article_types();
						$field(
							array(
								'id'          => 'schema_article_type',
								'type'        => 'select',
								'label'       => __( 'Article type', 'tagwright-seo' ),
								'description' => __( 'Articles add author, dates and headline details that help with rich results.', 'tagwright-seo' ),
								'options'     => array_merge(
									/* translators: %s: default article type. */
									array( '' => sprintf( __( 'Default (%s)', 'tagwright-seo' ), $article_types[ $args['article_type'] ] ?? $args['article_type'] ) ),
									$article_types
								),
							)
						);

						/**
						 * Prints extra schema settings at the end of the Schema tab.
						 * Tagwright SEO Pro adds its schema builder here.
						 *
						 * @since 1.5.0
						 *
						 * @param array|null $row  Stored row.
						 * @param array      $args Box options.
						 */
						do_action( 'tagwright_seo_metabox_schema_panel', $row, $args );
						?>
					</div>
				<?php endif; ?>

				<?php
				foreach ( array_keys( $tabs ) as $tab_id ) {
					if ( in_array( $tab_id, array( 'general', 'social', 'advanced', 'schema' ), true ) ) {
						continue;
					}
					printf( '<div class="tagwright-tabpanel" role="tabpanel" id="tagwright-tabpanel-%1$s" aria-labelledby="tagwright-tab-%1$s" hidden>', esc_attr( $tab_id ) );
					/**
					 * Prints an add-on tab inside the editor box.
					 *
					 * @param array|null $row  Stored row.
					 * @param array      $args Box options.
					 */
					do_action( 'tagwright_seo_metabox_panel_' . $tab_id, $row, $args );
					echo '</div>';
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves the box when the post is saved.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST[ self::PREFIX ] ) || ! is_array( $_POST[ self::PREFIX ] ) ) {
			return;
		}

		$input  = wp_unslash( $_POST[ self::PREFIX ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field below.
		$fields = self::sanitize_fields( $input );

		Objects::save( 'post', $post_id, $fields );

		/**
		 * Fires after the editor box is saved for a post. Add-ons save their
		 * own tab here.
		 *
		 * @param int   $post_id Post id.
		 * @param array $input   Submitted values, cleaned with sanitize_extension_input().
		 *                       Sanitize each field for its own type before saving it.
		 */
		do_action( 'tagwright_seo_metabox_saved', $post_id, self::sanitize_extension_input( $input ) );
	}

	/**
	 * Cleans the whole submitted box before add-ons see it.
	 *
	 * Every value goes through wp_kses_post(), so nothing unsafe reaches the
	 * save hooks. Keys are kept as they are because add-ons look their fields
	 * up by name. Add-ons still sanitize each field for the exact type they
	 * expect.
	 *
	 * @param array $input Unslashed values.
	 * @return array
	 */
	public static function sanitize_extension_input( array $input ) {
		$clean = array();

		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_extension_input( $value );
			} elseif ( is_scalar( $value ) ) {
				$clean[ $key ] = wp_kses_post( (string) $value );
			}
		}

		return $clean;
	}

	/**
	 * Sanitizes the editable fields.
	 *
	 * @param array $input Raw values.
	 * @return array
	 */
	public static function sanitize_fields( array $input ) {
		$clean = array();

		// Templates keep their %variables%, so they get their own sanitizer.
		foreach ( array( 'title', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'meta_keywords' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = Replacer::sanitize_template( $input[ $key ] );
			}
		}

		if ( array_key_exists( 'focus_keyphrase', $input ) ) {
			$clean['focus_keyphrase'] = mb_substr( sanitize_text_field( (string) $input['focus_keyphrase'] ), 0, 191 );
		}

		if ( array_key_exists( 'canonical', $input ) ) {
			$clean['canonical'] = esc_url_raw( trim( (string) $input['canonical'] ) );
		}

		foreach ( array( 'robots_noindex', 'robots_nofollow' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = '' === (string) $input[ $key ] ? null : ( (int) $input[ $key ] ? 1 : 0 );
			}
		}

		// Checkbox style robots flags: on means 1, off means "not set".
		foreach ( array( 'robots_noarchive', 'robots_nosnippet', 'robots_noimageindex' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = (int) $input[ $key ] ? 1 : null;
			}
		}

		foreach ( array( 'max_snippet', 'max_video_preview' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = '' === trim( (string) $input[ $key ] ) ? null : max( -1, (int) $input[ $key ] );
			}
		}

		if ( array_key_exists( 'max_image_preview', $input ) ) {
			$preview                    = (string) $input['max_image_preview'];
			$clean['max_image_preview'] = in_array( $preview, array( 'none', 'standard', 'large' ), true ) ? $preview : null;
		}

		if ( array_key_exists( 'og_image_id', $input ) ) {
			$clean['og_image_id'] = absint( $input['og_image_id'] );
		}

		if ( array_key_exists( 'sitemap_priority', $input ) ) {
			$priority                  = trim( (string) $input['sitemap_priority'] );
			$clean['sitemap_priority'] = '' === $priority ? null : (float) \TagwrightSEO\Sitemaps\Builder::format_priority( $priority );
		}

		if ( array_key_exists( 'sitemap_changefreq', $input ) ) {
			$frequency                   = (string) $input['sitemap_changefreq'];
			$clean['sitemap_changefreq'] = in_array( $frequency, \TagwrightSEO\Sitemaps\Builder::FREQUENCIES, true ) ? $frequency : null;
		}

		if ( array_key_exists( 'sitemap_exclude', $input ) ) {
			$clean['sitemap_exclude'] = (int) $input['sitemap_exclude'] ? 1 : 0;
		}

		if ( array_key_exists( 'schema_page_type', $input ) ) {
			$type                      = (string) $input['schema_page_type'];
			$clean['schema_page_type'] = isset( Graph::page_types()[ $type ] ) ? $type : '';
		}

		if ( array_key_exists( 'schema_article_type', $input ) ) {
			$type                         = (string) $input['schema_article_type'];
			$clean['schema_article_type'] = isset( Graph::article_types()[ $type ] ) ? $type : '';
		}

		foreach ( array( 'seo_score', 'readability_score' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = '' === (string) $input[ $key ] ? null : max( 0, min( 100, (int) $input[ $key ] ) );
			}
		}

		return $clean;
	}
}
