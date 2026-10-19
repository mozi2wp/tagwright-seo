<?php
/**
 * Shared admin UI components.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Tagwright SEO form controls and layout pieces.
 *
 * Add-ons should build their screens with these helpers too. That keeps
 * every screen on the same design system and saves add-ons from shipping
 * their own CSS.
 */
class UI {

	/**
	 * Prints a complete field row: label, control and help text.
	 *
	 * @param array $args Field arguments. See normalize().
	 * @return void
	 */
	public static function field( array $args ) {
		$args = self::normalize( $args );

		if ( 'heading' === $args['type'] ) {
			printf(
				'<div class="tagwright-field-heading"%3$s><h4>%1$s</h4>%2$s</div>',
				esc_html( $args['label'] ),
				$args['description'] ? '<p>' . wp_kses_post( $args['description'] ) . '</p>' : '',
				self::show_if_attr( $args ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
			);
			return;
		}

		$classes = array( 'tagwright-field', 'tagwright-field--' . $args['type'] );
		if ( $args['wide'] ) {
			$classes[] = 'tagwright-field--wide';
		}
		if ( $args['class'] ) {
			$classes[] = $args['class'];
		}

		printf(
			'<div class="%1$s" data-field="%2$s"%3$s>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $args['id'] ),
			self::show_if_attr( $args ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		);

		echo '<div class="tagwright-field__label">';
		if ( $args['label'] ) {
			printf(
				'<label for="%1$s">%2$s</label>',
				esc_attr( self::control_id( $args ) ),
				esc_html( $args['label'] )
			);
		}
		if ( $args['badge'] ) {
			printf( ' <span class="tagwright-badge tagwright-badge--%1$s">%2$s</span>', esc_attr( $args['badge_type'] ), esc_html( $args['badge'] ) );
		}
		if ( $args['description'] ) {
			echo '<p class="tagwright-field__description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
		echo '</div>';

		echo '<div class="tagwright-field__control">';
		self::control( $args );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Prints just the control for a field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function control( array $args ) {
		$args  = self::normalize( $args );
		$id    = self::control_id( $args );
		$name  = $args['name'];
		$value = $args['value'];
		$attrs = self::attributes( $args['attrs'] );

		switch ( $args['type'] ) {
			case 'text':
			case 'url':
			case 'email':
			case 'number':
			case 'password':
			case 'date':
				$extra = '';
				foreach ( array( 'min', 'max', 'step' ) as $key ) {
					if ( null !== $args[ $key ] ) {
						$extra .= sprintf( ' %s="%s"', $key, esc_attr( $args[ $key ] ) );
					}
				}
				printf(
					'<div class="tagwright-input-wrap%6$s">%10$s<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="tagwright-input" placeholder="%5$s"%7$s%8$s />%9$s</div>',
					esc_attr( $args['type'] ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $args['placeholder'] ),
					$args['suffix'] ? ' has-suffix' : '',
					$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					$args['suffix'] ? '<span class="tagwright-input-suffix">' . esc_html( $args['suffix'] ) . '</span>' : '',
					$args['prefix_text'] ? '<span class="tagwright-input-prefix">' . esc_html( $args['prefix_text'] ) . '</span>' : ''
				);
				break;

			case 'group':
				echo '<div class="tagwright-group">';
				foreach ( $args['fields'] as $child ) {
					$child = self::normalize( array_merge( array( 'prefix' => $args['prefix'] ), $child ) );
					if ( array_key_exists( 'values', $args ) && isset( $args['values'][ $child['id'] ] ) ) {
						$child['value'] = $args['values'][ $child['id'] ];
					}
					printf( '<div class="tagwright-group__item tagwright-group__item--%1$s"%2$s>', esc_attr( $child['type'] ), self::show_if_attr( $child ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					if ( $child['label'] ) {
						printf( '<label class="tagwright-group__label" for="%1$s">%2$s</label>', esc_attr( self::control_id( $child ) ), esc_html( $child['label'] ) );
					}
					self::control( $child );
					echo '</div>';
				}
				echo '</div>';
				break;

			case 'repeater':
				self::repeater( $args );
				break;

			case 'template':
			case 'template_textarea':
				$is_area = 'template_textarea' === $args['type'];
				echo '<div class="tagwright-template" data-counter="' . esc_attr( $args['counter'] ) . '">';
				echo '<div class="tagwright-template__row">';
				if ( $is_area ) {
					printf(
						'<textarea id="%1$s" name="%2$s" class="tagwright-textarea tagwright-template__input" rows="%3$d" placeholder="%4$s"%5$s>%6$s</textarea>',
						esc_attr( $id ),
						esc_attr( $name ),
						(int) $args['rows'],
						esc_attr( $args['placeholder'] ),
						$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
						esc_textarea( (string) $value )
					);
				} else {
					printf(
						'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="tagwright-input tagwright-template__input" placeholder="%4$s"%5$s />',
						esc_attr( $id ),
						esc_attr( $name ),
						esc_attr( (string) $value ),
						esc_attr( $args['placeholder'] ),
						$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					);
				}
				printf(
					'<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--icon tagwright-var-toggle" aria-label="%1$s" title="%1$s">%2$s</button>',
					esc_attr__( 'Insert variable', 'tagwright-seo' ),
					wp_kses( Icons::get( 'plus', 16 ), Icons::kses() )
				);
				echo '</div>';
				echo '<p class="tagwright-template__hint">' . wp_kses( __( 'Type <kbd>@</kbd> or <kbd>/</kbd> to insert a variable.', 'tagwright-seo' ), array( 'kbd' => array() ) ) . '</p>';
				if ( $args['counter'] ) {
					echo '<div class="tagwright-counter"><div class="tagwright-counter__bar"><span></span></div><span class="tagwright-counter__text"></span></div>';
				}
				echo '</div>';
				break;

			case 'textarea':
			case 'code':
				printf(
					'<textarea id="%1$s" name="%2$s" class="tagwright-textarea%3$s" rows="%4$d" placeholder="%5$s"%6$s>%7$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					'code' === $args['type'] ? ' tagwright-textarea--code' : '',
					(int) $args['rows'],
					esc_attr( $args['placeholder'] ),
					$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					esc_textarea( (string) $value )
				);
				break;

			case 'toggle':
				printf(
					'<label class="tagwright-toggle"><input type="hidden" name="%2$s" value="0" /><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s%4$s /><span class="tagwright-toggle__track" aria-hidden="true"><span class="tagwright-toggle__thumb"></span></span><span class="tagwright-toggle__label">%5$s</span></label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
					esc_html( $args['toggle_label'] )
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s" class="tagwright-select"%3$s>', esc_attr( $id ), esc_attr( $name ), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
				foreach ( $args['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $option_value ),
						selected( (string) $value, (string) $option_value, false ),
						esc_html( $option_label )
					);
				}
				echo '</select>';
				break;

			case 'tristate':
				// Used in the editor: inherit the default, force on, or force off.
				$current = null === $value || '' === $value ? '' : (string) (int) $value;
				echo '<div class="tagwright-segmented" role="radiogroup">';
				foreach ( $args['options'] as $option_value => $option_label ) {
					$option_id = $id . '-' . ( '' === (string) $option_value ? 'default' : $option_value );
					printf(
						'<input type="radio" id="%1$s" name="%2$s" value="%3$s" %4$s /><label for="%1$s">%5$s</label>',
						esc_attr( $option_id ),
						esc_attr( $name ),
						esc_attr( $option_value ),
						checked( $current, (string) $option_value, false ),
						esc_html( $option_label )
					);
				}
				echo '</div>';
				break;

			case 'segmented':
				echo '<div class="tagwright-segmented" role="radiogroup">';
				foreach ( $args['options'] as $option_value => $option_label ) {
					$option_id = $id . '-' . self::option_slug( $option_value );
					printf(
						'<input type="radio" id="%1$s" name="%2$s" value="%3$s" %4$s /><label for="%1$s">%5$s</label>',
						esc_attr( $option_id ),
						esc_attr( $name ),
						esc_attr( $option_value ),
						checked( (string) $value, (string) $option_value, false ),
						esc_html( $option_label )
					);
				}
				echo '</div>';
				break;

			case 'radio_cards':
				echo '<div class="tagwright-radio-cards">';
				foreach ( $args['options'] as $option_value => $option ) {
					$option    = is_array( $option ) ? $option : array( 'label' => $option );
					$option_id = $id . '-' . self::option_slug( $option_value );
					printf(
						'<label class="tagwright-radio-card" for="%1$s"><input type="radio" id="%1$s" name="%2$s" value="%3$s" %4$s /><span class="tagwright-radio-card__body">%5$s<strong>%6$s</strong>%7$s</span><span class="tagwright-radio-card__check">%8$s</span></label>',
						esc_attr( $option_id ),
						esc_attr( $name ),
						esc_attr( $option_value ),
						checked( (string) $value, (string) $option_value, false ),
						! empty( $option['icon'] ) ? '<span class="tagwright-radio-card__icon">' . wp_kses( Icons::get( $option['icon'], 22 ), Icons::kses() ) . '</span>' : '',
						esc_html( $option['label'] ),
						! empty( $option['description'] ) ? '<small>' . esc_html( $option['description'] ) . '</small>' : '',
						wp_kses( Icons::get( 'check', 14 ), Icons::kses() )
					);
				}
				echo '</div>';
				break;

			case 'checkboxes':
				$value = (array) $value;
				printf( '<input type="hidden" name="%s[]" value="" />', esc_attr( $name ) );
				echo '<div class="tagwright-checkboxes">';
				foreach ( $args['options'] as $option_value => $option_label ) {
					printf(
						'<label class="tagwright-check"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /><span class="tagwright-check__box">%5$s</span><span>%4$s</span></label>',
						esc_attr( $name ),
						esc_attr( $option_value ),
						checked( in_array( (string) $option_value, array_map( 'strval', $value ), true ), true, false ),
						esc_html( $option_label ),
						wp_kses( Icons::get( 'check', 12 ), Icons::kses() )
					);
				}
				echo '</div>';
				break;

			case 'image':
				$image_id  = absint( $value );
				$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
				printf(
					'<div class="tagwright-media%1$s" data-title="%2$s" data-button="%3$s">',
					$image_url ? ' has-image' : '',
					esc_attr__( 'Choose an image', 'tagwright-seo' ),
					esc_attr__( 'Use this image', 'tagwright-seo' )
				);
				printf( '<input type="hidden" id="%1$s" name="%2$s" value="%3$s" class="tagwright-media__input" />', esc_attr( $id ), esc_attr( $name ), esc_attr( $image_id ? (string) $image_id : '' ) );
				printf(
					'<div class="tagwright-media__preview">%1$s<span class="tagwright-media__empty">%2$s%3$s</span></div>',
					$image_url ? '<img src="' . esc_url( $image_url ) . '" alt="" />' : '',
					wp_kses( Icons::get( 'image', 28 ), Icons::kses() ),
					esc_html__( 'No image selected', 'tagwright-seo' )
				);
				echo '<div class="tagwright-media__actions">';
				printf( '<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm tagwright-media__choose">%s</button>', esc_html__( 'Choose image', 'tagwright-seo' ) );
				printf( '<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm tagwright-media__remove">%s</button>', esc_html__( 'Remove', 'tagwright-seo' ) );
				echo '</div>';
				if ( $args['recommended'] ) {
					echo '<p class="tagwright-media__hint">' . esc_html( $args['recommended'] ) . '</p>';
				}
				echo '</div>';
				break;

			case 'html':
				if ( is_callable( $args['render'] ) ) {
					call_user_func( $args['render'], $args );
				}
				break;
		}
	}

	/**
	 * Prints a repeater: a table of rows the user can add and remove.
	 *
	 * Rows are numbered fields such as tagwright[key][0][url]. A <template> holds
	 * an empty row that the script clones when "Add" is clicked.
	 *
	 * @param array $args Field arguments, with `columns` describing each cell.
	 * @return void
	 */
	private static function repeater( array $args ) {
		$rows = is_array( $args['value'] ) ? array_values( $args['value'] ) : array();

		$render_row = static function ( $index, array $row ) use ( $args ) {
			echo '<div class="tagwright-repeater__row">';
			foreach ( $args['columns'] as $column ) {
				$column = self::normalize(
					array_merge(
						$column,
						array(
							'id'    => $args['id'] . '-' . $index . '-' . $column['id'],
							'name'  => $args['name'] . '[' . $index . '][' . $column['id'] . ']',
							'value' => $row[ $column['id'] ] ?? ( $column['default'] ?? '' ),
						)
					)
				);
				echo '<div class="tagwright-repeater__cell tagwright-repeater__cell--' . esc_attr( $column['type'] ) . '">';
				self::control( $column );
				echo '</div>';
			}
			printf(
				'<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--icon tagwright-btn--sm tagwright-repeater__remove" aria-label="%1$s" title="%1$s">%2$s</button>',
				esc_attr__( 'Remove row', 'tagwright-seo' ),
				wp_kses( Icons::get( 'trash', 16 ), Icons::kses() )
			);
			echo '</div>';
		};

		printf( '<div class="tagwright-repeater" data-name="%s">', esc_attr( $args['name'] ) );

		// Hidden marker so an empty list still saves as "no rows".
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $args['name'] ) );

		echo '<div class="tagwright-repeater__head" aria-hidden="true">';
		foreach ( $args['columns'] as $column ) {
			echo '<span class="tagwright-repeater__cell--' . esc_attr( $column['type'] ?? 'text' ) . '">' . esc_html( $column['label'] ?? '' ) . '</span>';
		}
		echo '<span></span></div>';

		echo '<div class="tagwright-repeater__rows">';
		foreach ( $rows as $index => $row ) {
			$render_row( $index, (array) $row );
		}
		echo '</div>';

		echo '<template class="tagwright-repeater__template">';
		$render_row( '__INDEX__', array() );
		echo '</template>';

		printf(
			'<div class="tagwright-repeater__empty"%1$s>%2$s</div>',
			$rows ? ' hidden' : '',
			esc_html__( 'Nothing added yet.', 'tagwright-seo' )
		);

		printf(
			'<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm tagwright-repeater__add">%1$s<span>%2$s</span></button>',
			wp_kses( Icons::get( 'plus', 16 ), Icons::kses() ),
			esc_html( $args['add_label'] ? $args['add_label'] : __( 'Add row', 'tagwright-seo' ) )
		);

		echo '</div>';
	}

	/**
	 * Fills in missing field arguments.
	 *
	 * @param array $args Raw arguments.
	 * @return array
	 */
	public static function normalize( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'fields'       => array(),
				'columns'      => array(),
				'add_label'    => '',
				'prefix_text'  => '',
				'id'           => '',
				'name'         => '',
				'type'         => 'text',
				'label'        => '',
				'description'  => '',
				'value'        => null,
				'default'      => null,
				'options'      => array(),
				'placeholder'  => '',
				'attrs'        => array(),
				'show_if'      => array(),
				'class'        => '',
				'rows'         => 3,
				'counter'      => '',
				'min'          => null,
				'max'          => null,
				'step'         => null,
				'suffix'       => '',
				'toggle_label' => '',
				'recommended'  => '',
				'badge'        => '',
				'badge_type'   => 'info',
				'wide'         => false,
				'render'       => null,
				'prefix'       => 'tagwright',
			)
		);

		if ( '' === $args['name'] && '' !== $args['id'] ) {
			$args['name'] = $args['prefix'] . '[' . $args['id'] . ']';
		}

		if ( null === $args['value'] ) {
			$args['value'] = $args['default'];
		}

		if ( in_array( $args['type'], array( 'template_textarea', 'textarea' ), true ) && 3 === $args['rows'] ) {
			$args['rows'] = 2;
		}

		return $args;
	}

	/**
	 * HTML id for a control.
	 *
	 * @param array $args Field arguments.
	 * @return string
	 */
	private static function control_id( array $args ) {
		return $args['prefix'] . '-' . str_replace( '_', '-', sanitize_key( $args['id'] ) );
	}

	/**
	 * A safe, unique id fragment for an option value. Values such as "–" or
	 * "&raquo;" would otherwise collapse to the same id.
	 *
	 * @param string $value Option value.
	 * @return string
	 */
	private static function option_slug( $value ) {
		$slug = sanitize_html_class( (string) $value );

		return ( '' !== $slug && $slug === (string) $value ) ? $slug : substr( md5( (string) $value ), 0, 8 );
	}

	/**
	 * Builds the data attribute that hides a row unless another field matches.
	 *
	 * @param array $args Field arguments.
	 * @return string
	 */
	private static function show_if_attr( array $args ) {
		if ( empty( $args['show_if'] ) ) {
			return '';
		}

		return ' data-show-if="' . esc_attr( wp_json_encode( $args['show_if'] ) ) . '"';
	}

	/**
	 * Turns an attribute array into an HTML string.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function attributes( array $attrs ) {
		$html = '';
		foreach ( $attrs as $key => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			$html .= true === $value ? ' ' . esc_attr( $key ) : sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}

		return $html;
	}

	/**
	 * Prints the top bar shared by every Tagwright SEO screen.
	 *
	 * @param string $current Current page slug.
	 * @return void
	 */
	public static function header( $current ) {
		$pages = Admin::pages();
		?>
		<header class="tagwright-header">
			<div class="tagwright-header__inner">
				<a class="tagwright-brand" href="<?php echo esc_url( admin_url( 'admin.php?page=tagwright-seo' ) ); ?>">
					<?php $logo = (string) Brand::value( 'logo', '' ); ?>
					<?php if ( '' !== $logo ) : ?>
						<span class="tagwright-brand__mark tagwright-brand__mark--image"><img src="<?php echo esc_url( $logo ); ?>" alt="" height="24" /></span>
					<?php else : ?>
						<span class="tagwright-brand__mark"><?php Icons::render( 'logo', 24 ); ?></span>
					<?php endif; ?>
					<span class="tagwright-brand__name"><?php esc_html_e( 'Tagwright SEO', 'tagwright-seo' ); ?></span>
					<?php if ( Brand::value( 'version', true ) ) : ?>
						<span class="tagwright-brand__version"><?php echo esc_html( 'v' . TAGWRIGHT_SEO_VERSION ); ?></span>
					<?php endif; ?>
				</a>
				<nav class="tagwright-topnav" aria-label="<?php esc_attr_e( 'Tagwright SEO', 'tagwright-seo' ); ?>">
					<?php foreach ( $pages as $slug => $page ) : ?>
						<?php
						if ( ! empty( $page['hidden'] ) ) {
							continue;
						}
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="<?php echo $current === $slug ? 'is-active' : ''; ?>"<?php echo $current === $slug ? ' aria-current="page"' : ''; ?>>
							<?php Icons::render( $page['icon'], 18 ); ?>
							<span><?php echo esc_html( $page['menu_title'] ); ?></span>
						</a>
					<?php endforeach; ?>
					<?php // Filled by the script with the links that don't fit; without it, the links wrap instead. ?>
					<div class="tagwright-topnav__more" hidden>
						<button type="button" class="tagwright-topnav__more-button" aria-expanded="false" aria-controls="tagwright-topnav-more">
							<span><?php esc_html_e( 'More', 'tagwright-seo' ); ?></span>
							<span class="tagwright-topnav__count" aria-hidden="true"></span>
							<?php Icons::render( 'chevron-down', 16 ); ?>
						</button>
						<ul class="tagwright-topnav__menu" id="tagwright-topnav-more" hidden></ul>
					</div>
				</nav>
				<div class="tagwright-header__actions">
					<?php if ( 'tagwright-seo-setup' !== $current ) : ?>
						<a class="tagwright-header__link tagwright-header__link--button" href="<?php echo esc_url( admin_url( 'admin.php?page=tagwright-seo-setup' ) ); ?>" title="<?php esc_attr_e( 'Setup wizard', 'tagwright-seo' ); ?>">
							<?php Icons::render( 'sparkles', 16 ); ?>
							<span><?php esc_html_e( 'Setup wizard', 'tagwright-seo' ); ?></span>
						</a>
					<?php endif; ?>
					<a class="tagwright-header__link" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'View site (opens in a new tab)', 'tagwright-seo' ); ?>">
						<?php Icons::render( 'external', 16 ); ?>
						<span><?php esc_html_e( 'View site', 'tagwright-seo' ); ?></span><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'tagwright-seo' ); ?></span>
					</a>
				</div>
			</div>
		</header>
		<?php
	}

	/**
	 * Prints a notice box.
	 *
	 * @param string $type    info, success, warning or error.
	 * @param string $message Message. Basic HTML allowed.
	 * @param string $title   Optional title.
	 * @return void
	 */
	public static function notice( $type, $message, $title = '' ) {
		$icons = array(
			'info'    => 'info',
			'success' => 'check-circle',
			'warning' => 'alert',
			'error'   => 'alert',
		);
		?>
		<div class="tagwright-notice tagwright-notice--<?php echo esc_attr( $type ); ?>">
			<span class="tagwright-notice__icon"><?php Icons::render( $icons[ $type ] ?? 'info', 18 ); ?></span>
			<div class="tagwright-notice__body">
				<?php if ( $title ) : ?>
					<strong><?php echo esc_html( $title ); ?></strong>
				<?php endif; ?>
				<p><?php echo wp_kses_post( $message ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns a small badge showing a score.
	 *
	 * @param int|null $score Score from 0 to 100, or null.
	 * @param string   $label Accessible label.
	 * @return string
	 */
	public static function score_badge( $score, $label = '' ) {
		if ( null === $score || '' === $score ) {
			return sprintf( '<span class="tagwright-score-dot is-none" title="%1$s"><span class="screen-reader-text">%1$s</span></span>', esc_attr__( 'Not analyzed yet', 'tagwright-seo' ) );
		}

		$score = (int) $score;
		$state = $score >= 70 ? 'good' : ( $score >= 40 ? 'ok' : 'poor' );

		return sprintf(
			'<span class="tagwright-score-pill is-%1$s" title="%3$s">%2$d</span>',
			esc_attr( $state ),
			$score,
			esc_attr( $label )
		);
	}
}
