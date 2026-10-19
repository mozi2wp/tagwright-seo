<?php
/**
 * Public helper functions for themes and add-ons.
 *
 * @package TagwrightSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a Tagwright SEO setting.
 *
 * @param string $key      Setting key.
 * @param mixed  $fallback Value to return when the setting does not exist.
 * @return mixed
 */
function tagwright_seo_get_option( $key, $fallback = null ) {
	return TagwrightSEO\Options::get( $key, $fallback );
}

/**
 * Whether a Tagwright SEO module is switched on.
 *
 * @param string $module Module id, for example `sitemaps`.
 * @return bool
 */
function tagwright_seo_is_module_active( $module ) {
	return TagwrightSEO\Modules::is_active( $module );
}

/**
 * Returns the stored SEO data for a post, term or user.
 *
 * @param string $type Object type: post, term or user.
 * @param int    $id   Object id.
 * @return array|null Row from the index, or null when the object is not indexed yet.
 */
function tagwright_seo_get_object( $type, $id ) {
	return TagwrightSEO\Data\Objects::get( $type, (int) $id );
}

/**
 * Returns the SEO title for the current page.
 *
 * @return string
 */
function tagwright_seo_get_title() {
	return TagwrightSEO\Frontend\Context::current()->get_title();
}

/**
 * Returns the meta description for the current page.
 *
 * @return string
 */
function tagwright_seo_get_description() {
	return TagwrightSEO\Frontend\Context::current()->get_description();
}

/**
 * Returns the breadcrumb trail for the current page as a list of
 * `array( 'text' => ..., 'url' => ... )` items.
 *
 * Tagwright SEO only uses this for schema. Display is handled by the Content
 * Blocks add-on or by your theme.
 *
 * @return array[]
 */
function tagwright_seo_get_breadcrumbs() {
	return TagwrightSEO\Frontend\Breadcrumb_Trail::for_current_page();
}
