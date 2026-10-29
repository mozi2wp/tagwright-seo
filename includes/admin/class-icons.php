<?php
/**
 * Inline SVG icons for the admin.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A small, self-contained stroke icon set so the UI looks the same in every
 * add-on without loading an icon font.
 */
class Icons {

	/**
	 * SVG path data, drawn on a 24x24 grid.
	 *
	 * @var array<string, string>
	 */
	const PATHS = array(
		// The two-tag mark. Filled, not stroked: the top tag is white and the
		// lower one takes the current text colour (teal in the header).
		'logo'          => '<path fill="#fff" stroke="none" fill-rule="evenodd" d="M3.63 9.69Q3.63 8.59 4.58 8.05L16.11 1.36Q16.89 0.92 17.68 1.33L19.83 2.56Q20.37 2.87 20.37 3.54V7.33Q20.37 7.67 20.05 7.86L4.17 17.05Q3.63 17.34 3.63 16.74ZM17.21 2.94a1.39 1.39 0 1 0 0 2.78a1.39 1.39 0 1 0 0-2.78Z"/><path fill="currentColor" stroke="none" d="M4.29 18.79L14.08 13.26Q14.46 13.07 14.84 13.07H19.36Q20.37 13.07 20.37 14.08V15.95Q20.37 16.58 19.83 16.89L9.63 22.64Q8.78 23.12 8.02 22.55L4.23 19.64Q3.79 19.23 4.29 18.79Z"/>',
		'dashboard'     => '<rect x="3" y="3" width="7.5" height="9" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="5" rx="1.5"/><rect x="13.5" y="11" width="7.5" height="10" rx="1.5"/><rect x="3" y="15" width="7.5" height="6" rx="1.5"/>',
		'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		'tools'         => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9z"/>',
		'addons'        => '<path d="M10 3h4v3a2 2 0 1 0 4 0V3h3v7h-3a2 2 0 1 0 0 4h3v7h-7v-3a2 2 0 1 0-4 0v3H3v-7h3a2 2 0 1 0 0-4H3V3h7z"/>',
		'search'        => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'title'         => '<path d="M4 7V5h16v2"/><path d="M12 5v14"/><path d="M9 19h6"/>',
		'share'         => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
		'code'          => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
		'sitemap'       => '<rect x="9" y="2.5" width="6" height="5" rx="1"/><rect x="2.5" y="16.5" width="6" height="5" rx="1"/><rect x="15.5" y="16.5" width="6" height="5" rx="1"/><path d="M12 7.5v4.5M5.5 16.5V12h13v4.5"/>',
		'analysis'      => '<path d="M3 3v18h18"/><path d="M7 15v2M11 11v6M15 7v10M19 12v5"/>',
		'robot'         => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01M9.5 17h5"/><circle cx="12" cy="3" r="1"/>',
		'globe'         => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
		'link'          => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
		'image'         => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
		'check'         => '<path d="M20 6 9 17l-5-5"/>',
		'check-circle'  => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
		'x'             => '<path d="M18 6 6 18M6 6l12 12"/>',
		'alert'         => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
		'info'          => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
		'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
		'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
		'arrow-right'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'plus'          => '<path d="M12 5v14M5 12h14"/>',
		'external'      => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		'refresh'       => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>',
		'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
		'upload'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
		'database'      => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
		'file'          => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
		'tag'           => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/>',
		'user'          => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'home'          => '<path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2h-4v-7H9v7H5a2 2 0 0 1-2-2z"/>',
		'eye'           => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		'eye-off'       => '<path d="M17.9 17.9A10 10 0 0 1 12 19c-6.4 0-10-7-10-7a18 18 0 0 1 4.1-5.1M9.9 5.2A9 9 0 0 1 12 5c6.4 0 10 7 10 7a18 18 0 0 1-2.2 3.2M14.1 14.2a3 3 0 0 1-4.2-4.2M2 2l20 20"/>',
		'sparkles'      => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
		'trend'         => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
		'layers'        => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
		'shield'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'zap'           => '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
		'copy'          => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
		'trash'         => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
		'clock'         => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'key'           => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8M17 6l3 3M14.5 8.5l2 2"/>',
		'pen'           => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
		'book'          => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
		'cart'          => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L22 7H6"/>',
		'pin'           => '<path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
		'video'         => '<rect x="2" y="5" width="14" height="14" rx="2"/><path d="m22 8-6 4 6 4z"/>',
		'news'          => '<path d="M4 22h14a2 2 0 0 0 2-2V4H8v16a2 2 0 0 1-4 0V9h4"/><path d="M12 8h4M12 12h4M12 16h4"/>',
		'mic'           => '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v1a7 7 0 0 0 14 0v-1M12 18v4"/>',
		'redirect'      => '<path d="M3 7h13a5 5 0 0 1 0 10H8"/><path d="m7 3-4 4 4 4"/>',
		'blocks'        => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><path d="M17 13v8M13 17h8"/>',
		'keyword'       => '<path d="M4 7h16M4 12h10M4 17h7"/><circle cx="18" cy="16" r="3"/><path d="m20.2 18.2 1.8 1.8"/>',
	);

	/**
	 * Returns an icon's SVG markup.
	 *
	 * @param string $name  Icon name.
	 * @param int    $size  Width and height in pixels.
	 * @param string $class Extra CSS class.
	 * @return string
	 */
	public static function get( $name, $size = 20, $class = '' ) {
		/**
		 * Filters the icon paths so add-ons can register their own icons.
		 *
		 * @param array $paths Icon name => SVG inner markup.
		 */
		$paths = apply_filters( 'tagwright_seo_icons', self::PATHS );

		if ( ! isset( $paths[ $name ] ) ) {
			$name = 'info';
		}

		// Icons registered through the filter are cleaned like any other markup.
		$inner = wp_kses( (string) $paths[ $name ], self::kses() );

		return sprintf(
			'<svg class="tagwright-icon %1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
			esc_attr( $class ),
			(int) $size,
			$inner
		);
	}

	/**
	 * Prints an icon.
	 *
	 * @param string $name  Icon name.
	 * @param int    $size  Size in pixels.
	 * @param string $class Extra CSS class.
	 * @return void
	 */
	public static function render( $name, $size = 20, $class = '' ) {
		echo wp_kses( self::get( $name, $size, $class ), self::kses() );
	}

	/**
	 * Every icon's inner markup, cleaned, for the admin scripts.
	 *
	 * @return array<string, string>
	 */
	public static function all() {
		/** This filter is documented in includes/admin/class-icons.php */
		$paths = apply_filters( 'tagwright_seo_icons', self::PATHS );
		$allow = self::kses();

		return array_map(
			static function ( $markup ) use ( $allow ) {
				return wp_kses( (string) $markup, $allow );
			},
			(array) $paths
		);
	}

	/**
	 * The two-tag mark as a one-colour SVG data URI for the admin menu.
	 * WordPress repaints the fill to match the admin colour scheme, so it
	 * stays a single solid colour on a 20x20 grid.
	 *
	 * @return string
	 */
	public static function menu_icon_uri() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
			. '<path fill="black" fill-rule="evenodd" d="M3.03 8.08Q3.03 7.16 3.82 6.71L13.42 1.13Q14.08 0.76 14.74 1.11L16.53 2.13Q16.97 2.39 16.97 2.95V6.11Q16.97 6.39 16.71 6.55L3.47 14.21Q3.03 14.45 3.03 13.95ZM14.34 2.45a1.16 1.16 0 1 0 0 2.32a1.16 1.16 0 1 0 0-2.32Z"/>'
			. '<path fill="black" d="M3.58 15.66L11.74 11.05Q12.05 10.89 12.37 10.89H16.13Q16.97 10.89 16.97 11.74V13.29Q16.97 13.82 16.53 14.08L8.03 18.87Q7.32 19.26 6.68 18.79L3.53 16.37Q3.16 16.03 3.58 15.66Z"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard way to pass a menu icon.
	}

	/**
	 * Allowed tags for wp_kses when an icon is part of filtered output.
	 *
	 * @return array
	 */
	public static function kses() {
		$shape = array(
			'd'      => true,
			'x'      => true,
			'y'      => true,
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'rx'     => true,
			'ry'     => true,
			'width'  => true,
			'height' => true,
		);

		// Add-ons may draw with more shapes and their own stroke or fill.
		$shape += array(
			'x1'             => true,
			'y1'             => true,
			'x2'             => true,
			'y2'             => true,
			'points'         => true,
			'fill'           => true,
			'stroke'         => true,
			'stroke-width'   => true,
			'opacity'        => true,
			'transform'      => true,
			'fill-rule'      => true,
			'clip-rule'      => true,
			'stroke-linecap' => true,
		);

		return array(
			'svg'      => array(
				'class'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
				'focusable'       => true,
			),
			'path'     => $shape,
			'circle'   => $shape,
			'rect'     => $shape,
			'ellipse'  => $shape,
			'line'     => $shape,
			'polyline' => $shape,
			'polygon'  => $shape,
			'g'        => $shape,
		);
	}
}
