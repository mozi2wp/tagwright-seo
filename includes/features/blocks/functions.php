<?php
/**
 * Template functions for themes. Only loaded while Content Blocks is on, so
 * themes should wrap calls in function_exists().
 *
 * @package TagwrightSEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tagwright_seo_breadcrumbs' ) ) {
	/**
	 * Prints or returns the breadcrumb trail.
	 *
	 * Takes the same options as the [tagwright_breadcrumbs] shortcode; anything
	 * left out uses the Content Blocks settings.
	 *
	 *     <?php if ( function_exists( 'tagwright_seo_breadcrumbs' ) ) { tagwright_seo_breadcrumbs(); } ?>
	 *
	 * @param array $args {
	 *     Optional overrides.
	 *
	 *     @type string $separator    Character between items.
	 *     @type string $home         Label of the first item.
	 *     @type string $prefix       Text before the trail.
	 *     @type bool   $show_current Show the current page.
	 *     @type bool   $link_current Link the current page.
	 * }
	 * @param bool  $echo Print the markup. It is returned either way.
	 * @return string
	 */
	function tagwright_seo_breadcrumbs( $args = array(), $echo = true ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.echoFound -- Matches the WordPress template tag convention.
		$html = TagwrightSEO\Features\Blocks\Renderer::breadcrumbs( (array) $args );

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped in the renderer.
		}

		return $html;
	}
}
