<?php
/**
 * Add-ons screen.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Addons;
use TagwrightSEO\Features\Registry;
use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Shows what comes free with Tagwright SEO, what Tagwright SEO Pro adds, and any
 * other add-on that registered itself with the core.
 */
class Addons_Page {

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public static function render() {
		$registered = Addons::registered();
		$pro_active = Addons::is_active( 'pro' );
		$settings   = admin_url( 'admin.php?page=tagwright-seo-settings' );
		?>
		<div class="tagwright-app">
			<?php UI::header( 'tagwright-seo-addons' ); ?>
			<div class="tagwright-page">
				<div class="tagwright-page-title">
					<div>
						<h1><?php esc_html_e( 'Add-ons', 'tagwright-seo' ); ?></h1>
						<p><?php esc_html_e( 'Everything below the first heading is already in Tagwright SEO and free. Switch on what you need; anything switched off loads no code.', 'tagwright-seo' ); ?></p>
					</div>
				</div>

				<h2 class="tagwright-section-heading"><?php esc_html_e( 'Included free', 'tagwright-seo' ); ?></h2>
				<div class="tagwright-modules">
					<?php foreach ( Registry::modules() as $id => $module ) : ?>
						<?php $active = Modules::is_active( $id ); ?>
						<div class="tagwright-module<?php echo $active ? ' is-active' : ''; ?>">
							<div class="tagwright-module__top">
								<span class="tagwright-module__icon"><?php Icons::render( $module['icon'] ?? 'layers', 22 ); ?></span>
								<?php if ( $active ) : ?>
									<span class="tagwright-badge tagwright-badge--good"><?php esc_html_e( 'On', 'tagwright-seo' ); ?></span>
								<?php else : ?>
									<span class="tagwright-badge tagwright-badge--muted"><?php esc_html_e( 'Off', 'tagwright-seo' ); ?></span>
								<?php endif; ?>
							</div>
							<h3><?php echo esc_html( $module['title'] ); ?></h3>
							<p><?php echo esc_html( $module['description'] ); ?></p>
							<div class="tagwright-module__footer">
								<a href="<?php echo esc_url( $settings . '#' . ( $module['settings'] ?? $id ) ); ?>"><?php esc_html_e( 'Settings', 'tagwright-seo' ); ?></a>
								<?php if ( ! $active ) : ?>
									<a href="<?php echo esc_url( $settings . '#modules' ); ?>"><?php esc_html_e( 'Switch on', 'tagwright-seo' ); ?></a>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="tagwright-section-heading tagwright-section-heading--split">
					<h2><?php esc_html_e( 'Tagwright SEO Pro', 'tagwright-seo' ); ?></h2>
					<a class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=tagwright-seo-pro' ) ); ?>">
						<?php esc_html_e( 'Compare plans', 'tagwright-seo' ); ?>
						<?php Icons::render( 'arrow-right', 16 ); ?>
					</a>
				</div>
				<div class="tagwright-modules">
					<?php foreach ( Addons::catalogue() as $id => $addon ) : ?>
						<div class="tagwright-module tagwright-module--pro<?php echo self::pro_has( $id ) ? ' is-active' : ''; ?>">
							<div class="tagwright-module__top">
								<span class="tagwright-module__icon"><?php Icons::render( $addon['icon'], 22 ); ?></span>
								<?php self::plan_badge( $addon['plan'], $pro_active, $id ); ?>
							</div>
							<h3><?php echo esc_html( $addon['name'] ); ?></h3>
							<p><?php echo esc_html( $addon['description'] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>

				<?php
				// Add-ons made by others, or by you, that registered with the core.
				$others = array_diff_key( $registered, array( 'pro' => true ) );
				?>
				<?php if ( $others ) : ?>
					<h2 class="tagwright-section-heading"><?php esc_html_e( 'Other installed add-ons', 'tagwright-seo' ); ?></h2>
					<div class="tagwright-modules">
						<?php foreach ( $others as $addon ) : ?>
							<div class="tagwright-module<?php echo $addon['compatible'] ? ' is-active' : ''; ?>">
								<div class="tagwright-module__top">
									<span class="tagwright-module__icon"><?php Icons::render( 'addons', 22 ); ?></span>
									<?php if ( $addon['compatible'] ) : ?>
										<span class="tagwright-badge tagwright-badge--good">
											<?php
											/* translators: %s: version number. */
											echo esc_html( sprintf( __( 'Active · v%s', 'tagwright-seo' ), $addon['version'] ) );
											?>
										</span>
									<?php else : ?>
										<span class="tagwright-badge tagwright-badge--bad"><?php esc_html_e( 'Needs a newer Tagwright SEO', 'tagwright-seo' ); ?></span>
									<?php endif; ?>
								</div>
								<h3><?php echo esc_html( $addon['name'] ); ?></h3>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Whether the installed Tagwright SEO Pro already ships a module. Pro grows
	 * one module at a time, so being active doesn't mean every card is ready.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public static function pro_has( $id ) {
		return Addons::is_active( 'pro' ) && isset( Modules::all()[ $id ] );
	}

	/**
	 * Prints the badge on a Pro module card.
	 *
	 * @param string $plan       Lowest plan that includes the module.
	 * @param bool   $pro_active Whether Tagwright SEO Pro is installed.
	 * @param string $id         Module id.
	 * @return void
	 */
	public static function plan_badge( $plan, $pro_active, $id = '' ) {
		if ( $pro_active ) {
			if ( self::pro_has( $id ) ) {
				echo '<span class="tagwright-badge tagwright-badge--good">' . esc_html__( 'Included', 'tagwright-seo' ) . '</span>';
			} else {
				echo '<span class="tagwright-badge tagwright-badge--muted">' . esc_html__( 'Coming soon', 'tagwright-seo' ) . '</span>';
			}
			return;
		}

		$label = 'agency' === $plan ? __( 'Agency', 'tagwright-seo' ) : __( 'Pro', 'tagwright-seo' );

		echo '<span class="tagwright-badge tagwright-badge--pro">' . esc_html( $label ) . '</span>';
	}
}
