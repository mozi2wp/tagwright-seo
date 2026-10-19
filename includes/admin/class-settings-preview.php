<?php
/**
 * Live previews on the settings screen.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Shows how a template will look in Google or on social media, using a real
 * post, term or author from the site as the example. The preview updates in
 * the browser as the template is edited.
 */
class Settings_Preview {

	/**
	 * Prints a preview block.
	 *
	 * @param array $preview Preview definition from the settings registry:
	 *                       type (serp or social), context, title, description
	 *                       and image (lists of setting keys, first non-empty wins).
	 * @return void
	 */
	public static function render( array $preview ) {
		$sample = self::sample( $preview['context'] ?? array( 'kind' => 'home' ) );
		$type   = $preview['type'] ?? 'serp';

		$config = array(
			'type'        => $type,
			'title'       => (array) ( $preview['title'] ?? array() ),
			'description' => (array) ( $preview['description'] ?? array() ),
			'image'       => (array) ( $preview['image'] ?? array() ),
			'values'      => $sample['values'],
			'url'         => $sample['url'],
			'imageUrl'    => $sample['image'],
			'objectType'  => $sample['object_type'],
			'objectId'    => $sample['object_id'],
			'siteName'    => get_bloginfo( 'name' ),
			'favicon'     => get_site_icon_url( 32 ),
		);
		?>
		<div class="tagwright-preview-block tagwright-settings-preview" data-settings-preview="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="tagwright-preview-block__bar">
				<h4>
					<?php echo 'social' === $type ? esc_html__( 'Sharing preview', 'tagwright-seo' ) : esc_html__( 'Search preview', 'tagwright-seo' ); ?>
					<?php if ( $sample['label'] ) : ?>
						<span class="tagwright-preview-block__sample">
							<?php
							/* translators: %s: name of the example post, term or page. */
							echo esc_html( sprintf( __( 'Example: %s', 'tagwright-seo' ), $sample['label'] ) );
							?>
						</span>
					<?php endif; ?>
				</h4>
				<?php if ( 'serp' === $type ) : ?>
					<div class="tagwright-segmented tagwright-segmented--sm" role="radiogroup" aria-label="<?php esc_attr_e( 'Preview device', 'tagwright-seo' ); ?>">
						<button type="button" class="is-active" data-device="desktop" aria-pressed="true"><?php esc_html_e( 'Desktop', 'tagwright-seo' ); ?></button>
						<button type="button" data-device="mobile" aria-pressed="false"><?php esc_html_e( 'Mobile', 'tagwright-seo' ); ?></button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( 'social' === $type ) : ?>
				<div class="tagwright-social-card">
					<div class="tagwright-social-card__image"><?php Icons::render( 'image', 36 ); ?></div>
					<div class="tagwright-social-card__body">
						<div class="tagwright-social-card__domain"></div>
						<div class="tagwright-social-card__title"></div>
						<p class="tagwright-social-card__desc"></p>
					</div>
				</div>
			<?php else : ?>
				<div class="tagwright-serp">
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
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Finds an example object and resolves every variable for it.
	 *
	 * @param array $context kind (home, post_type, post_type_archive, taxonomy,
	 *                       author, date, search, not_found) and name.
	 * @return array{values: array, url: string, image: string, label: string}
	 */
	private static function sample( array $context ) {
		$kind    = $context['kind'] ?? 'home';
		$name    = $context['name'] ?? '';
		$sample  = new Context();
		$url     = home_url( '/' );
		$image   = '';
		$label   = '';
		$extra   = array();

		switch ( $kind ) {
			case 'post_type':
				$posts = get_posts(
					array(
						'post_type'      => $name,
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'orderby'        => 'date',
						'order'          => 'DESC',
						'no_found_rows'  => true,
					)
				);
				if ( $posts ) {
					$sample = Context::for_post( $posts[0] );
					$url    = (string) get_permalink( $posts[0] );
					$image  = (string) get_the_post_thumbnail_url( $posts[0], 'large' );
					$label  = get_the_title( $posts[0] );
				} else {
					$object = get_post_type_object( $name );
					$extra  = array(
						'title' => $object ? sprintf(
							/* translators: %s: post type singular name. */
							__( 'Your first %s', 'tagwright-seo' ),
							strtolower( $object->labels->singular_name )
						) : '',
						'excerpt' => __( 'This is where a short summary of the content will appear in search results.', 'tagwright-seo' ),
					);
					$url    = home_url( '/example/' );
				}
				break;

			case 'post_type_archive':
				$sample->type   = 'post_type_archive';
				$sample->object = get_post_type_object( $name );
				$url            = (string) get_post_type_archive_link( $name );
				break;

			case 'taxonomy':
				$terms = get_terms(
					array(
						'taxonomy'   => $name,
						'number'     => 1,
						'orderby'    => 'count',
						'order'      => 'DESC',
						'hide_empty' => false,
					)
				);
				if ( is_array( $terms ) && $terms ) {
					$sample = Context::for_term( $terms[0] );
					$link   = get_term_link( $terms[0] );
					$url    = is_wp_error( $link ) ? $url : $link;
					$label  = $terms[0]->name;
				} else {
					$extra = array(
						'term_title'       => __( 'Example term', 'tagwright-seo' ),
						'term_description' => __( 'A short description of this archive.', 'tagwright-seo' ),
					);
				}
				break;

			case 'author':
				$user           = wp_get_current_user();
				$sample->type   = 'author';
				$sample->object = $user;
				$url            = get_author_posts_url( $user->ID );
				$label          = $user->display_name;
				break;

			case 'date':
				$sample->type = 'date';
				$extra        = array( 'date' => wp_date( 'F Y' ) );
				$url          = get_month_link( (int) wp_date( 'Y' ), (int) wp_date( 'n' ) );
				break;

			case 'search':
				$sample->type = 'search';
				$extra        = array( 'searchphrase' => __( 'example search', 'tagwright-seo' ) );
				$url          = add_query_arg( 's', rawurlencode( __( 'example search', 'tagwright-seo' ) ), home_url( '/' ) );
				break;

			case 'not_found':
				$sample->type = 'not_found';
				$url          = home_url( '/no-such-page/' );
				break;

			default:
				$sample->type = 'home';
				$image        = (string) wp_get_attachment_image_url( (int) Options::get( 'og_default_image_id' ), 'large' );
		}

		// Remember which object the example is, so custom fields can be looked up.
		$object_type = '';
		$object_id   = 0;
		if ( $sample->object instanceof \WP_Post ) {
			$object_type = 'post';
			$object_id   = (int) $sample->object->ID;
		} elseif ( $sample->object instanceof \WP_Term ) {
			$object_type = 'term';
			$object_id   = (int) $sample->object->term_id;
		} elseif ( $sample->object instanceof \WP_User ) {
			$object_type = 'user';
			$object_id   = (int) $sample->object->ID;
		}

		return array(
			'values'      => array_merge( Metabox::variable_values( $sample ), $extra ),
			'url'         => $url,
			'image'       => $image,
			'label'       => $label,
			'object_type' => $object_type,
			'object_id'   => $object_id,
		);
	}
}
