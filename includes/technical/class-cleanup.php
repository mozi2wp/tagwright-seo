<?php
/**
 * Small technical fixes that stop thin or duplicate URLs.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Technical;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Attachment page redirects and ?replytocom removal.
 */
class Cleanup {

	/**
	 * Registers hooks for the options that are switched on.
	 */
	public function __construct() {
		if ( Options::get( 'attachment_redirect' ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_attachment' ), 1 );
		}

		if ( Options::get( 'remove_replytocom' ) ) {
			add_filter( 'comment_reply_link', array( $this, 'remove_replytocom_link' ) );
			add_action( 'template_redirect', array( $this, 'redirect_replytocom' ), 1 );
		}
	}

	/**
	 * Sends visitors from an attachment page to its parent post, or to the file.
	 *
	 * Attachment pages are almost always empty pages that waste crawl budget.
	 *
	 * @return void
	 */
	public function redirect_attachment() {
		if ( ! is_attachment() ) {
			return;
		}

		$attachment = get_queried_object();
		if ( ! $attachment instanceof \WP_Post ) {
			return;
		}

		$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;

		if ( $parent && 'publish' === $parent->post_status ) {
			$target = get_permalink( $parent );
		} else {
			$target = wp_get_attachment_url( $attachment->ID );
		}

		/**
		 * Filters where an attachment page redirects to.
		 *
		 * @param string   $target     Redirect URL.
		 * @param \WP_Post $attachment Attachment post.
		 */
		$target = apply_filters( 'tagwright_seo_attachment_redirect', $target, $attachment );

		if ( $target ) {
			// The target comes from WordPress, not the request. Offloaded media lives on
			// another host, which wp_safe_redirect() would turn into /wp-admin/.
			wp_redirect( $target, 301, 'Tagwright SEO' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
	}

	/**
	 * Strips ?replytocom from comment reply links. The link still works
	 * because the comment reply script handles it with JavaScript.
	 *
	 * @param string $link Reply link HTML.
	 * @return string
	 */
	public function remove_replytocom_link( $link ) {
		return preg_replace( '/href=([\'"])[^\'"]*?replytocom=\d+[^\'"]*\1/', 'href=$1#respond$1', $link );
	}

	/**
	 * Redirects URLs that still carry ?replytocom to the clean post URL.
	 *
	 * @return void
	 */
	public function redirect_replytocom() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only, used to build a redirect.
		if ( ! isset( $_GET['replytocom'] ) || ! is_singular() ) {
			return;
		}

		$url = remove_query_arg( 'replytocom' );
		$url = $url ? $url . '#comment-' . absint( $_GET['replytocom'] ) : get_permalink(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_safe_redirect( $url, 301, 'Tagwright SEO' );
		exit;
	}
}
