<?php
/**
 * Toolbar menu.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Data\Objects;
use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Tagwright SEO menu to the toolbar with the current page's score and
 * one-click links to popular testing tools.
 */
class Admin_Bar {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_bar_menu', array( $this, 'add_menu' ), 90 );
		add_action( 'wp_enqueue_scripts', array( $this, 'styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
	}

	/**
	 * Whether the menu should show for this user.
	 *
	 * @return bool
	 */
	private function enabled() {
		return is_admin_bar_showing() && Options::get( 'admin_bar_menu' ) && current_user_can( 'edit_posts' );
	}

	/**
	 * Small styles for the score badge. The full design system is not
	 * loaded on the front end.
	 *
	 * @return void
	 */
	public function styles() {
		if ( ! $this->enabled() ) {
			return;
		}

		$css = '#wpadminbar #wp-admin-bar-tagwright-seo .tagwright-ab-icon{display:inline-block;width:18px;height:18px;margin-right:6px;vertical-align:-4px}'
			. '#wpadminbar #wp-admin-bar-tagwright-seo .tagwright-ab-score{display:inline-block;min-width:26px;margin-left:6px;padding:0 6px;border-radius:999px;font-size:11px;line-height:18px;text-align:center;font-weight:700;color:#fff;background:#64748b}'
			. '#wpadminbar #wp-admin-bar-tagwright-seo .tagwright-ab-score.is-good{background:#10b981}'
			. '#wpadminbar #wp-admin-bar-tagwright-seo .tagwright-ab-score.is-ok{background:#f59e0b}'
			. '#wpadminbar #wp-admin-bar-tagwright-seo .tagwright-ab-score.is-poor{background:#ef4444}';

		wp_add_inline_style( 'admin-bar', $css );
	}

	/**
	 * Builds the menu.
	 *
	 * @param \WP_Admin_Bar $bar Toolbar.
	 * @return void
	 */
	public function add_menu( $bar ) {
		if ( ! $this->enabled() ) {
			return;
		}

		$icon  = '<svg class="tagwright-ab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5 3.5 7v10L12 21.5 20.5 17V7z"/><path d="M8.5 15.5v-7l3.5 4 3.5-4v7"/></svg>';
		$score = '';
		$post  = null;

		if ( ! is_admin() && is_singular() ) {
			$post = get_queried_object();
			$row  = $post instanceof \WP_Post ? Objects::get( 'post', $post->ID ) : null;
			if ( isset( $row['seo_score'] ) && null !== $row['seo_score'] ) {
				$value = (int) $row['seo_score'];
				$state = $value >= 70 ? 'good' : ( $value >= 40 ? 'ok' : 'poor' );
				$score = sprintf( '<span class="tagwright-ab-score is-%1$s">%2$d</span>', esc_attr( $state ), $value );
			}
		}

		$can_manage = current_user_can( Admin::capability() );

		$bar->add_node(
			array(
				'id'    => 'tagwright-seo',
				'title' => $icon . '<span class="ab-label">' . esc_html__( 'SEO', 'tagwright-seo' ) . '</span>' . $score,
				'href'  => $can_manage ? admin_url( 'admin.php?page=tagwright-seo' ) : false,
				'meta'  => array( 'title' => __( 'Tagwright SEO', 'tagwright-seo' ) ),
			)
		);

		if ( $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
			$bar->add_node(
				array(
					'parent' => 'tagwright-seo',
					'id'     => 'tagwright-seo-edit',
					'title'  => esc_html__( 'Edit SEO for this page', 'tagwright-seo' ),
					'href'   => get_edit_post_link( $post->ID ) . '#tagwright_seo_metabox',
				)
			);
		}

		if ( $can_manage ) {
			$bar->add_node(
				array(
					'parent' => 'tagwright-seo',
					'id'     => 'tagwright-seo-dashboard',
					'title'  => esc_html__( 'Dashboard', 'tagwright-seo' ),
					'href'   => admin_url( 'admin.php?page=tagwright-seo' ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'tagwright-seo',
					'id'     => 'tagwright-seo-settings',
					'title'  => esc_html__( 'Settings', 'tagwright-seo' ),
					'href'   => admin_url( 'admin.php?page=tagwright-seo-settings' ),
				)
			);
		}

		if ( is_admin() ) {
			return;
		}

		$url = Context::current()->get_canonical();
		if ( '' === $url ) {
			$url = home_url( add_query_arg( array() ) );
		}

		$bar->add_group(
			array(
				'parent' => 'tagwright-seo',
				'id'     => 'tagwright-seo-tools',
				'meta'   => array( 'class' => 'ab-sub-secondary' ),
			)
		);

		$tools = array(
			'rich-results' => array( __( 'Google Rich Results Test', 'tagwright-seo' ), 'https://search.google.com/test/rich-results?url=' . rawurlencode( $url ) ),
			'pagespeed'    => array( __( 'PageSpeed Insights', 'tagwright-seo' ), 'https://pagespeed.web.dev/report?url=' . rawurlencode( $url ) ),
			'schema'       => array( __( 'Schema Markup Validator', 'tagwright-seo' ), 'https://validator.schema.org/#url=' . rawurlencode( $url ) ),
			'facebook'     => array( __( 'Facebook Sharing Debugger', 'tagwright-seo' ), 'https://developers.facebook.com/tools/debug/?q=' . rawurlencode( $url ) ),
			'indexed'      => array( __( 'Is this page indexed?', 'tagwright-seo' ), 'https://www.google.com/search?q=' . rawurlencode( 'site:' . $url ) ),
		);

		foreach ( $tools as $id => $tool ) {
			$bar->add_node(
				array(
					'parent' => 'tagwright-seo-tools',
					'id'     => 'tagwright-seo-tool-' . $id,
					'title'  => esc_html( $tool[0] ),
					'href'   => $tool[1],
					'meta'   => array(
						'target' => '_blank',
						'rel'    => 'noopener noreferrer',
					),
				)
			);
		}
	}
}
