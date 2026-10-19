<?php
/**
 * SEO fields on the term edit screen.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Data\Objects;
use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Modules;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the same SEO box used for posts on category, tag and custom
 * taxonomy edit screens.
 */
class Term_Fields {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'edited_term', array( $this, 'save' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Hooks into every public taxonomy.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form", array( $this, 'render' ), 20, 2 );
		}
	}

	/**
	 * Loads scripts on the term edit screen.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'term.php' !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the term being edited.
		$term_id = isset( $_GET['tag_ID'] ) ? absint( $_GET['tag_ID'] ) : 0;
		$term    = $term_id ? get_term( $term_id ) : null;

		if ( ! $term || is_wp_error( $term ) || ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return;
		}

		$context = Context::for_term( $term );
		$link    = get_term_link( $term );

		Metabox::enqueue_editor_assets(
			array(
				'objectType'   => 'term',
				'objectId'     => (int) $term->term_id,
				'editor'       => 'term',
				'defaults'     => array(
					'title'       => $context->setting_for( 'title' ),
					'description' => $context->setting_for( 'description' ),
				),
				'variables'    => Metabox::variable_values( $context ),
				'permalink'    => is_wp_error( $link ) ? home_url( '/' ) : $link,
				'siteName'     => get_bloginfo( 'name' ),
				'homeUrl'      => home_url( '/' ),
				'favicon'      => get_site_icon_url( 32 ),
				'analysis'     => false,
				'readability'  => false,
				'locale'       => get_locale(),
				'defaultImage' => (string) wp_get_attachment_image_url( (int) Options::get( 'og_default_image_id' ), 'large' ),
			)
		);
	}

	/**
	 * Prints the box below the term form fields.
	 *
	 * @param \WP_Term $term     Term.
	 * @param string   $taxonomy Taxonomy.
	 * @return void
	 */
	public function render( $term, $taxonomy ) {
		?>
		<div class="postbox" id="tagwright_seo_metabox" style="margin-top: 24px; max-width: 1100px;">
			<div class="postbox-header"><h2 style="padding: 0 16px;"><?php esc_html_e( 'Tagwright SEO', 'tagwright-seo' ); ?></h2></div>
			<div class="inside">
				<?php
				wp_nonce_field( Metabox::NONCE, Metabox::NONCE . '_nonce' );
				Metabox::render_box(
					Objects::get( 'term', $term->term_id ),
					array(
						'object_type' => 'term',
						'social'      => Modules::is_active( 'social' ),
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves the fields.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function save( $term_id, $tt_id, $taxonomy ) {
		if ( ! isset( $_POST[ Metabox::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ Metabox::NONCE . '_nonce' ] ) ), Metabox::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) || empty( $_POST[ Metabox::PREFIX ] ) || ! is_array( $_POST[ Metabox::PREFIX ] ) ) {
			return;
		}

		$input = wp_unslash( $_POST[ Metabox::PREFIX ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_fields().

		Objects::save( 'term', $term_id, Metabox::sanitize_fields( $input ) );

		/**
		 * Fires after the SEO box is saved for a term.
		 *
		 * @param int    $term_id  Term id.
		 * @param string $taxonomy Taxonomy.
		 * @param array  $input    Submitted values, cleaned with Metabox::sanitize_extension_input().
		 */
		do_action( 'tagwright_seo_term_saved', $term_id, $taxonomy, Metabox::sanitize_extension_input( $input ) );
	}
}
