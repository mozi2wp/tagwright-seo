<?php
/**
 * Settings screen.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Renders every settings tab from the registry inside one form. Saving goes
 * through the REST API so the page never reloads.
 */
class Settings_Page {

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public static function render() {
		$tabs   = Settings_Registry::tabs();
		$groups = Settings_Registry::groups();
		?>
		<div class="tagwright-app">
			<?php UI::header( 'tagwright-seo-settings' ); ?>
			<div class="tagwright-page">
				<div class="tagwright-page-title">
					<div>
						<h1><?php esc_html_e( 'Settings', 'tagwright-seo' ); ?></h1>
						<p><?php esc_html_e( 'Fine-tune how your site appears in search engines and on social media.', 'tagwright-seo' ); ?></p>
					</div>
				</div>

				<div class="tagwright-layout">
					<?php // On narrow screens the script shows this picker instead of the long list of sections. ?>
					<div class="tagwright-section-picker">
						<label class="tagwright-section-picker__label" for="tagwright-section-picker"><?php esc_html_e( 'Settings section', 'tagwright-seo' ); ?></label>
						<select id="tagwright-section-picker" class="tagwright-select" data-section-picker>
							<?php
							foreach ( $groups as $group_id => $group_label ) :
								$group_tabs = array_filter( $tabs, static fn( $tab ) => ( $tab['group'] ?? 'addons' ) === $group_id );
								if ( empty( $group_tabs ) ) {
									continue;
								}
								?>
								<optgroup label="<?php echo esc_attr( $group_label ); ?>">
									<?php foreach ( $group_tabs as $tab_id => $tab ) : ?>
										<?php
										$label = $tab['title'];
										if ( ! empty( $tab['module'] ) && ! Modules::is_active( $tab['module'] ) ) {
											/* translators: %s: settings section name. */
											$label = sprintf( __( '%s (off)', 'tagwright-seo' ), $label );
										}
										?>
										<option value="<?php echo esc_attr( $tab_id ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
					</div>
					<nav class="tagwright-sidenav" aria-label="<?php esc_attr_e( 'Settings sections', 'tagwright-seo' ); ?>">
						<?php
						foreach ( $groups as $group_id => $group_label ) :
							$group_tabs = array_filter( $tabs, static fn( $tab ) => ( $tab['group'] ?? 'addons' ) === $group_id );
							if ( empty( $group_tabs ) ) {
								continue;
							}
							?>
							<div class="tagwright-sidenav__group"><?php echo esc_html( $group_label ); ?></div>
							<?php foreach ( $group_tabs as $tab_id => $tab ) : ?>
								<a href="#<?php echo esc_attr( $tab_id ); ?>" data-panel="<?php echo esc_attr( $tab_id ); ?>">
									<?php Icons::render( $tab['icon'] ?? 'settings', 18 ); ?>
									<span><?php echo esc_html( $tab['title'] ); ?></span>
									<?php if ( ! empty( $tab['module'] ) && ! Modules::is_active( $tab['module'] ) ) : ?>
										<span class="tagwright-sidenav__addon"><?php esc_html_e( 'Off', 'tagwright-seo' ); ?></span>
									<?php endif; ?>
								</a>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</nav>

					<form data-tagwright-settings novalidate>
						<?php
						foreach ( $tabs as $tab_id => $tab ) {
							self::render_tab( $tab_id, $tab );
						}
						?>
						<div class="tagwright-savebar">
							<div class="tagwright-savebar__status">
								<span class="tagwright-savebar__dot"></span>
								<span class="tagwright-savebar__text"><?php esc_html_e( 'All changes saved', 'tagwright-seo' ); ?></span>
							</div>
							<button type="submit" class="tagwright-btn tagwright-btn--primary tagwright-save">
								<?php Icons::render( 'check', 16 ); ?>
								<span><?php esc_html_e( 'Save changes', 'tagwright-seo' ); ?></span>
							</button>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints one tab panel.
	 *
	 * @param string $tab_id Tab id.
	 * @param array  $tab    Tab definition.
	 * @return void
	 */
	private static function render_tab( $tab_id, array $tab ) {
		?>
		<section class="tagwright-panel" id="tagwright-panel-<?php echo esc_attr( $tab_id ); ?>" aria-labelledby="tagwright-panel-title-<?php echo esc_attr( $tab_id ); ?>">
			<div class="tagwright-panel__intro">
				<h2 id="tagwright-panel-title-<?php echo esc_attr( $tab_id ); ?>"><?php echo esc_html( $tab['title'] ); ?></h2>
				<?php if ( ! empty( $tab['description'] ) ) : ?>
					<p><?php echo wp_kses_post( $tab['description'] ); ?></p>
				<?php endif; ?>
			</div>

			<?php
			if ( ! empty( $tab['module'] ) && ! Modules::is_active( $tab['module'] ) ) {
				UI::notice(
					'warning',
					sprintf(
						/* translators: %s: link to the modules tab. */
						__( 'This module is switched off, so these settings are not used right now. You can turn it on under %s.', 'tagwright-seo' ),
						'<a href="#modules" data-panel-link="modules">' . esc_html__( 'Modules', 'tagwright-seo' ) . '</a>'
					),
					__( 'Module is off', 'tagwright-seo' )
				);
			}

			if ( ! empty( $tab['render'] ) && is_callable( $tab['render'] ) ) {
				call_user_func( $tab['render'], $tab_id, $tab );
			}

			foreach ( $tab['sections'] ?? array() as $section ) {
				self::render_section( $section );
			}
			?>
		</section>
		<?php
	}

	/**
	 * Prints a section as a card, or as a collapsible card.
	 *
	 * @param array $section Section definition.
	 * @return void
	 */
	private static function render_section( array $section ) {
		$accordion = ! empty( $section['accordion'] );
		$tag       = $accordion ? 'details' : 'div';
		$open      = $accordion && ! empty( $section['open'] ) ? ' open' : '';

		printf( '<%1$s class="tagwright-card%2$s"%3$s>', esc_attr( $tag ), $accordion ? ' tagwright-accordion' : '', $open ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.

		printf( '<%s class="tagwright-card__header">', $accordion ? 'summary' : 'div' );
		echo '<div>';
		echo '<h3 class="tagwright-card__title">';
		Icons::render( $section['icon'] ?? 'settings', 18 );
		echo esc_html( $section['title'] ?? '' );
		echo '</h3>';
		if ( ! empty( $section['description'] ) ) {
			echo '<p class="tagwright-card__subtitle">' . wp_kses_post( $section['description'] ) . '</p>';
		}
		echo '</div>';
		if ( $accordion ) {
			Icons::render( 'chevron-down', 18, 'tagwright-accordion__chevron' );
		}
		printf( '</%s>', $accordion ? 'summary' : 'div' );

		echo '<div class="tagwright-card__body">';
		if ( ! empty( $section['preview'] ) ) {
			Settings_Preview::render( $section['preview'] );
		}
		foreach ( $section['fields'] ?? array() as $field ) {
			$type = $field['type'] ?? 'text';

			if ( 'group' === $type ) {
				$field['values'] = array();
				foreach ( $field['fields'] as $child ) {
					$field['values'][ $child['id'] ] = Settings_Registry::value( $child );
				}
			} elseif ( ! in_array( $type, array( 'heading', 'html' ), true ) ) {
				$field['value'] = Settings_Registry::value( $field );
			}

			UI::field( $field );
		}
		echo '</div>';

		printf( '</%s>', esc_attr( $tag ) );
	}

	/**
	 * Module cards for the dashboard. Settings links open the settings page.
	 *
	 * @return void
	 */
	public static function render_modules_compact() {
		ob_start();
		self::render_modules();
		$html = ob_get_clean();

		// On the dashboard the settings links need to point at the settings screen.
		echo str_replace( 'href="#', 'href="' . esc_url( admin_url( 'admin.php?page=tagwright-seo-settings' ) ) . '#', $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering.
	}

	/**
	 * Prints the module cards on the Modules tab.
	 *
	 * @return void
	 */
	public static function render_modules() {
		echo '<div class="tagwright-modules">';

		foreach ( Modules::all() as $id => $module ) {
			$active = Modules::is_active( $id );
			?>
			<div class="tagwright-module<?php echo $active ? ' is-active' : ''; ?>">
				<div class="tagwright-module__top">
					<span class="tagwright-module__icon"><?php Icons::render( $module['icon'] ?? 'layers', 22 ); ?></span>
					<?php if ( ! empty( $module['addon'] ) ) : ?>
						<span class="tagwright-badge tagwright-badge--accent"><?php echo esc_html( $module['addon'] ); ?></span>
					<?php endif; ?>
				</div>
				<h3><?php echo esc_html( $module['title'] ); ?></h3>
				<p><?php echo esc_html( $module['description'] ); ?></p>
				<div class="tagwright-module__footer">
					<?php if ( ! empty( $module['settings'] ) ) : ?>
						<a href="#<?php echo esc_attr( $module['settings'] ); ?>" data-panel-link="<?php echo esc_attr( $module['settings'] ); ?>"><?php esc_html_e( 'Settings', 'tagwright-seo' ); ?></a>
					<?php else : ?>
						<span></span>
					<?php endif; ?>
					<label class="tagwright-toggle">
						<input type="checkbox" data-tagwright-module="<?php echo esc_attr( $id ); ?>" <?php checked( $active ); ?> aria-label="<?php echo esc_attr( $module['title'] ); ?>" />
						<span class="tagwright-toggle__track" aria-hidden="true"><span class="tagwright-toggle__thumb"></span></span>
					</label>
				</div>
			</div>
			<?php
		}

		echo '</div>';
	}
}
