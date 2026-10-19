<?php
/**
 * SEO fields on user profiles.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Data\Objects;

defined( 'ABSPATH' ) || exit;

/**
 * Adds author archive SEO fields and social profile links to user profiles.
 * The social links feed the Person schema.
 */
class User_Fields {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_filter( 'user_contactmethods', array( $this, 'contact_methods' ) );
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
	}

	/**
	 * Adds social profile fields to the contact info section.
	 *
	 * @param array $methods Contact methods.
	 * @return array
	 */
	public function contact_methods( $methods ) {
		$methods['facebook']  = __( 'Facebook profile URL', 'tagwright-seo' );
		$methods['twitter']   = __( 'X (Twitter) username', 'tagwright-seo' );
		$methods['instagram'] = __( 'Instagram profile URL', 'tagwright-seo' );
		$methods['linkedin']  = __( 'LinkedIn profile URL', 'tagwright-seo' );
		$methods['youtube']   = __( 'YouTube channel URL', 'tagwright-seo' );

		return $methods;
	}

	/**
	 * Prints the author archive fields.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	public function render( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$row = Objects::get( 'user', $user->ID );
		wp_nonce_field( 'tagwright_seo_user', 'tagwright_seo_user_nonce' );
		?>
		<div class="tagwright-scope" style="max-width: 1000px; margin: 24px 0;">
			<div class="tagwright-card">
				<div class="tagwright-card__header">
					<div>
						<h2 class="tagwright-card__title"><?php Icons::render( 'logo', 18 ); ?><?php esc_html_e( 'Tagwright SEO: author archive', 'tagwright-seo' ); ?></h2>
						<p class="tagwright-card__subtitle"><?php esc_html_e( 'How this person\'s author page appears in search results. Leave empty to use the defaults.', 'tagwright-seo' ); ?></p>
					</div>
				</div>
				<div class="tagwright-card__body">
					<?php
					UI::field(
						array(
							'id'     => 'title',
							'prefix' => Metabox::PREFIX,
							'type'   => 'template',
							'label'  => __( 'SEO title', 'tagwright-seo' ),
							'value'  => $row['title'] ?? '',
						)
					);
					UI::field(
						array(
							'id'     => 'description',
							'prefix' => Metabox::PREFIX,
							'type'   => 'template_textarea',
							'label'  => __( 'Meta description', 'tagwright-seo' ),
							'value'  => $row['description'] ?? '',
						)
					);
					UI::field(
						array(
							'id'      => 'robots_noindex',
							'prefix'  => Metabox::PREFIX,
							'type'    => 'tristate',
							'label'   => __( 'Hide from search results', 'tagwright-seo' ),
							'value'   => $row['robots_noindex'] ?? null,
							'options' => array(
								''  => __( 'Default', 'tagwright-seo' ),
								'0' => __( 'No', 'tagwright-seo' ),
								'1' => __( 'Yes', 'tagwright-seo' ),
							),
						)
					);
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves the fields.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function save( $user_id ) {
		if ( ! isset( $_POST['tagwright_seo_user_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tagwright_seo_user_nonce'] ) ), 'tagwright_seo_user' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) || empty( $_POST[ Metabox::PREFIX ] ) || ! is_array( $_POST[ Metabox::PREFIX ] ) ) {
			return;
		}

		$input = wp_unslash( $_POST[ Metabox::PREFIX ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$input = array_intersect_key( $input, array_flip( array( 'title', 'description', 'robots_noindex' ) ) );

		Objects::save( 'user', $user_id, Metabox::sanitize_fields( $input ) );
	}
}
