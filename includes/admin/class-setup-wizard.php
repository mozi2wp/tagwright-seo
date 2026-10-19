<?php
/**
 * Setup wizard.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Importers\Importers;
use TagwrightSEO\Modules;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A short guided setup that covers the settings that matter most. Everything
 * here can be changed later on the Settings screen.
 */
class Setup_Wizard {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'reminder' ) );
		add_action( 'admin_post_tagwright_seo_dismiss_setup', array( $this, 'dismiss' ) );
	}

	/**
	 * Hides the setup reminder without running the wizard.
	 *
	 * @return void
	 */
	public function dismiss() {
		check_admin_referer( 'tagwright_seo_dismiss_setup' );

		if ( current_user_can( Admin::capability() ) ) {
			update_option( 'tagwright_seo_setup_completed', time(), false );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Gentle reminder until the wizard is finished or skipped.
	 *
	 * @return void
	 */
	public function reminder() {
		if ( get_option( 'tagwright_seo_setup_completed' ) || Admin::is_plugin_screen() || ! current_user_can( Admin::capability() ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s <a href="%3$s" class="button button-primary" style="margin-left:8px">%4$s</a> <a href="%5$s" style="margin-left:8px">%6$s</a></p></div>',
			esc_html__( 'Tagwright SEO is almost ready.', 'tagwright-seo' ),
			esc_html__( 'Take two minutes to set up the essentials.', 'tagwright-seo' ),
			esc_url( admin_url( 'admin.php?page=tagwright-seo-setup' ) ),
			esc_html__( 'Start setup', 'tagwright-seo' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tagwright_seo_dismiss_setup' ), 'tagwright_seo_dismiss_setup' ) ),
			esc_html__( 'Dismiss', 'tagwright-seo' )
		);
	}

	/**
	 * Prints the wizard.
	 *
	 * @return void
	 */
	public static function render() {
		wp_enqueue_script( 'tagwright-seo-wizard', TAGWRIGHT_SEO_URL . 'assets/js/wizard.js', array( 'tagwright-seo-admin', 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
		wp_set_script_translations( 'tagwright-seo-wizard', 'tagwright-seo' );

		$steps = array(
			'welcome'  => __( 'Welcome', 'tagwright-seo' ),
			'site'     => __( 'Your site', 'tagwright-seo' ),
			'search'   => __( 'Search', 'tagwright-seo' ),
			'social'   => __( 'Social', 'tagwright-seo' ),
			'features' => __( 'Features', 'tagwright-seo' ),
			'import'   => __( 'Import', 'tagwright-seo' ),
		);

		$value = static fn( $key ) => Options::get( $key );
		$field = static function ( array $field ) use ( $value ) {
			$field['value'] = $field['value'] ?? $value( $field['id'] );
			UI::field( $field );
		};

		$importers = array_filter( Importers::all(), static fn( $importer ) => $importer->is_detected() );
		?>
		<div class="tagwright-app">
			<?php UI::header( 'tagwright-seo-setup' ); ?>
			<div class="tagwright-wizard" id="tagwright-wizard">
				<ol class="tagwright-wizard__steps">
					<?php foreach ( $steps as $id => $label ) : ?>
						<li data-step-label="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></li>
					<?php endforeach; ?>
				</ol>

				<form class="tagwright-card" data-wizard novalidate>
					<?php // Welcome. ?>
					<section class="tagwright-wizard__step" data-step="welcome">
						<div class="tagwright-wizard__intro">
							<span class="tagwright-brand__mark" style="display:grid"><?php Icons::render( 'logo', 32 ); ?></span>
							<h2><?php esc_html_e( 'Welcome to Tagwright SEO', 'tagwright-seo' ); ?></h2>
							<?php if ( get_option( 'tagwright_seo_setup_completed' ) ) : ?>
								<p><?php esc_html_e( 'Welcome back. Your current settings are already filled in, so you can review them step by step and change only what you need.', 'tagwright-seo' ); ?></p>
							<?php else : ?>
								<p><?php esc_html_e( 'A few quick questions and your site is ready for search engines and social media. It takes about two minutes, and you can change everything later.', 'tagwright-seo' ); ?></p>
							<?php endif; ?>
						</div>
						<div class="tagwright-card__body tagwright-card__body--padded">
							<div class="tagwright-grid tagwright-grid--3">
								<?php
								$highlights = array(
									array( 'zap', __( 'Fast by design', 'tagwright-seo' ), __( 'SEO data lives in its own indexed tables, not scattered across post meta.', 'tagwright-seo' ) ),
									array( 'layers', __( 'Only what you use', 'tagwright-seo' ), __( 'Switch modules off and their code never loads.', 'tagwright-seo' ) ),
									array( 'trend', __( 'Clear insights', 'tagwright-seo' ), __( 'A dashboard that tracks how your content improves over time.', 'tagwright-seo' ) ),
								);
								foreach ( $highlights as $highlight ) :
									?>
									<div class="tagwright-addon-promo" style="flex-direction:column;align-items:flex-start;border-style:solid">
										<span class="tagwright-addon-promo__icon"><?php Icons::render( $highlight[0], 22 ); ?></span>
										<div><strong><?php echo esc_html( $highlight[1] ); ?></strong><p><?php echo esc_html( $highlight[2] ); ?></p></div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</section>

					<?php // Site. ?>
					<section class="tagwright-wizard__step" data-step="site" hidden>
						<div class="tagwright-card__body">
							<?php
							$field(
								array(
									'id'      => 'site_represents',
									'type'    => 'radio_cards',
									'label'   => __( 'This site represents', 'tagwright-seo' ),
									'options' => array(
										'organization' => array(
											'label'       => __( 'An organization', 'tagwright-seo' ),
											'description' => __( 'A company, brand, shop or publication.', 'tagwright-seo' ),
											'icon'        => 'globe',
										),
										'person'       => array(
											'label'       => __( 'A person', 'tagwright-seo' ),
											'description' => __( 'A personal site, portfolio or blog.', 'tagwright-seo' ),
											'icon'        => 'user',
										),
									),
								)
							);
							$field(
								array(
									'id'          => 'org_name',
									'label'       => __( 'Organization name', 'tagwright-seo' ),
									'placeholder' => get_bloginfo( 'name' ),
									'show_if'     => array(
										'field' => 'site_represents',
										'value' => 'organization',
									),
								)
							);
							$field(
								array(
									'id'          => 'org_logo_id',
									'type'        => 'image',
									'label'       => __( 'Logo', 'tagwright-seo' ),
									'recommended' => __( 'Square, at least 112 × 112 pixels.', 'tagwright-seo' ),
									'show_if'     => array(
										'field' => 'site_represents',
										'value' => 'organization',
									),
								)
							);
							$users = Settings_Registry::person_options();
							$field(
								array(
									'id'      => 'person_user_id',
									'type'    => 'select',
									'label'   => __( 'Who is it?', 'tagwright-seo' ),
									'options' => $users,
									'value'   => (string) ( $value( 'person_user_id' ) ? $value( 'person_user_id' ) : get_current_user_id() ),
									'show_if' => array(
										'field' => 'site_represents',
										'value' => 'person',
									),
								)
							);
							?>
						</div>
					</section>

					<?php // Search appearance. ?>
					<section class="tagwright-wizard__step" data-step="search" hidden>
						<div class="tagwright-card__body">
							<?php
							$field(
								array(
									'id'      => 'separator',
									'type'    => 'segmented',
									'label'   => __( 'Title separator', 'tagwright-seo' ),
									'options' => Replacer::separators(),
								)
							);
							$field(
								array(
									'id'    => 'home_title',
									'type'  => 'template',
									'label' => __( 'Homepage title', 'tagwright-seo' ),
								)
							);
							$field(
								array(
									'id'          => 'home_description',
									'type'        => 'template_textarea',
									'label'       => __( 'Homepage description', 'tagwright-seo' ),
									'description' => __( 'One or two sentences that make people want to visit.', 'tagwright-seo' ),
								)
							);
							$field(
								array(
									'id'           => 'author_archive_noindex',
									'type'         => 'toggle',
									'label'        => __( 'Single author?', 'tagwright-seo' ),
									'description'  => __( 'If you are the only author, the author archive duplicates your blog. Hiding it avoids duplicate content.', 'tagwright-seo' ),
									'toggle_label' => __( 'Hide author archives from search results', 'tagwright-seo' ),
								)
							);
							$field(
								array(
									'id'           => 'meta_keywords',
									'type'         => 'toggle',
									'label'        => __( 'Meta keywords', 'tagwright-seo' ),
									'description'  => __( 'A legacy tag most search engines ignore. Only turn it on if you know you need it.', 'tagwright-seo' ),
									'toggle_label' => __( 'Output meta keywords', 'tagwright-seo' ),
								)
							);
							?>
						</div>
					</section>

					<?php // Social. ?>
					<section class="tagwright-wizard__step" data-step="social" hidden>
						<div class="tagwright-card__body">
							<?php
							$field(
								array(
									'id'          => 'og_default_image_id',
									'type'        => 'image',
									'label'       => __( 'Default sharing image', 'tagwright-seo' ),
									'description' => __( 'Shown when a shared page has no image of its own.', 'tagwright-seo' ),
									'recommended' => __( '1200 × 630 pixels works best.', 'tagwright-seo' ),
								)
							);
							$field(
								array(
									'id'          => 'facebook_page_url',
									'type'        => 'url',
									'label'       => __( 'Facebook page', 'tagwright-seo' ),
									'placeholder' => 'https://www.facebook.com/yourpage',
								)
							);
							$field(
								array(
									'id'          => 'twitter_username',
									'label'       => __( 'X (Twitter) username', 'tagwright-seo' ),
									'placeholder' => '@yourhandle',
								)
							);
							$field(
								array(
									'id'          => 'social_profiles',
									'type'        => 'textarea',
									'label'       => __( 'Other profiles', 'tagwright-seo' ),
									'description' => __( 'One URL per line.', 'tagwright-seo' ),
									'rows'        => 3,
								)
							);
							?>
						</div>
					</section>

					<?php // Features. ?>
					<section class="tagwright-wizard__step" data-step="features" hidden>
						<div class="tagwright-card__body tagwright-card__body--padded">
							<p class="tagwright-muted" style="margin-top:0"><?php esc_html_e( 'Pick the features you want. Anything switched off loads no code at all.', 'tagwright-seo' ); ?></p>
							<div class="tagwright-modules">
								<?php foreach ( Modules::all() as $id => $module ) : ?>
									<?php $active = Modules::is_active( $id ); ?>
									<label class="tagwright-module<?php echo $active ? ' is-active' : ''; ?>" style="cursor:pointer">
										<span class="tagwright-module__top">
											<span class="tagwright-module__icon"><?php Icons::render( $module['icon'] ?? 'layers', 22 ); ?></span>
											<span class="tagwright-toggle">
												<input type="hidden" name="tagwright_modules[<?php echo esc_attr( $id ); ?>]" value="0" />
												<input type="checkbox" name="tagwright_modules[<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $active ); ?> aria-label="<?php echo esc_attr( $module['title'] ); ?>" />
												<span class="tagwright-toggle__track" aria-hidden="true"><span class="tagwright-toggle__thumb"></span></span>
											</span>
										</span>
										<h3><?php echo esc_html( $module['title'] ); ?></h3>
										<p><?php echo esc_html( $module['description'] ); ?></p>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</section>

					<?php // Import. ?>
					<section class="tagwright-wizard__step" data-step="import" hidden>
						<div class="tagwright-card__body tagwright-card__body--padded">
							<?php if ( $importers ) : ?>
								<p class="tagwright-muted" style="margin-top:0"><?php esc_html_e( 'We found data from another SEO plugin. Import it so you do not lose any work. The original data stays untouched.', 'tagwright-seo' ); ?></p>
								<?php foreach ( $importers as $id => $importer ) : ?>
									<div class="tagwright-addon-promo" style="margin-bottom:12px;border-style:solid" data-wizard-importer="<?php echo esc_attr( $id ); ?>">
										<span class="tagwright-addon-promo__icon"><?php Icons::render( 'download', 22 ); ?></span>
										<div style="flex:1">
											<strong><?php echo esc_html( $importer->name() ); ?></strong>
											<div class="tagwright-progress" hidden style="margin-top:8px"><span></span></div>
										</div>
										<button type="button" class="tagwright-btn tagwright-btn--primary tagwright-btn--sm" data-wizard-import><?php esc_html_e( 'Import', 'tagwright-seo' ); ?></button>
									</div>
								<?php endforeach; ?>
							<?php else : ?>
								<div class="tagwright-empty">
									<?php Icons::render( 'check-circle', 36 ); ?>
									<h3><?php esc_html_e( 'Nothing to import', 'tagwright-seo' ); ?></h3>
									<p><?php esc_html_e( 'No other SEO plugin data was found. You are all set.', 'tagwright-seo' ); ?></p>
								</div>
							<?php endif; ?>
						</div>
					</section>

					<div class="tagwright-wizard__nav">
						<div>
							<button type="button" class="tagwright-btn tagwright-btn--ghost" data-wizard-back hidden><?php esc_html_e( 'Back', 'tagwright-seo' ); ?></button>
							<a class="tagwright-btn tagwright-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=tagwright-seo' ) ); ?>" data-wizard-skip><?php esc_html_e( 'Skip setup', 'tagwright-seo' ); ?></a>
						</div>
						<div>
							<button type="button" class="tagwright-btn tagwright-btn--primary" data-wizard-next><?php esc_html_e( 'Let\'s go', 'tagwright-seo' ); ?><?php Icons::render( 'arrow-right', 16 ); ?></button>
							<button type="submit" class="tagwright-btn tagwright-btn--accent" data-wizard-finish hidden><?php Icons::render( 'check', 16 ); ?><?php esc_html_e( 'Finish setup', 'tagwright-seo' ); ?></button>
						</div>
					</div>
				</form>
			</div>
		</div>
		<?php
	}
}
