<?php
/**
 * Admin menu, assets and shared screen plumbing.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Tagwright SEO admin pages and loads the design system.
 */
class Admin {

	/**
	 * Hook suffixes of our pages, filled when menus are registered.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
		add_filter( 'plugin_action_links_' . TAGWRIGHT_SEO_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ) );
	}

	/**
	 * The capability needed to manage Tagwright SEO.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to access Tagwright SEO settings.
		 *
		 * @param string $capability Capability. Default manage_options.
		 */
		return apply_filters( 'tagwright_seo_capability', 'manage_options' );
	}

	/**
	 * Every Tagwright SEO admin page.
	 *
	 * @return array[] Keyed by page slug.
	 */
	public static function pages() {
		$pages = array(
			'tagwright-seo'          => array(
				'title'      => __( 'Tagwright SEO Dashboard', 'tagwright-seo' ),
				'menu_title' => __( 'Dashboard', 'tagwright-seo' ),
				'icon'       => 'dashboard',
				'callback'   => array( Dashboard::class, 'render' ),
			),
			'tagwright-seo-settings' => array(
				'title'      => __( 'Tagwright SEO Settings', 'tagwright-seo' ),
				'menu_title' => __( 'Settings', 'tagwright-seo' ),
				'icon'       => 'settings',
				'callback'   => array( Settings_Page::class, 'render' ),
			),
			'tagwright-seo-tools'    => array(
				'title'      => __( 'Tagwright SEO Tools', 'tagwright-seo' ),
				'menu_title' => __( 'Tools', 'tagwright-seo' ),
				'icon'       => 'tools',
				'callback'   => array( Tools_Page::class, 'render' ),
			),
			'tagwright-seo-addons'   => array(
				'title'      => __( 'Tagwright SEO Add-ons', 'tagwright-seo' ),
				'menu_title' => __( 'Add-ons', 'tagwright-seo' ),
				'icon'       => 'addons',
				'callback'   => array( Addons_Page::class, 'render' ),
			),
			'tagwright-seo-pro'      => array(
				'title'      => __( 'Tagwright SEO Pro', 'tagwright-seo' ),
				'menu_title' => __( 'Tagwright SEO Pro', 'tagwright-seo' ),
				'icon'       => 'sparkles',
				'callback'   => array( Pro_Page::class, 'render' ),
			),
			'tagwright-seo-setup'    => array(
				'title'      => __( 'Tagwright SEO Setup', 'tagwright-seo' ),
				'menu_title' => __( 'Setup Wizard', 'tagwright-seo' ),
				'icon'       => 'sparkles',
				'callback'   => array( Setup_Wizard::class, 'render' ),
				'hidden'     => true,
			),
		);

		if ( ! Brand::value( 'upsells', true ) ) {
			unset( $pages['tagwright-seo-addons'], $pages['tagwright-seo-pro'] );
		}

		/**
		 * Filters the Tagwright SEO admin pages. Add-ons can add a page here
		 * and it shows up in the menu and in the top navigation.
		 *
		 * Each page has title, menu_title, icon, callback and optionally
		 * hidden and position.
		 *
		 * @param array $pages Pages keyed by slug.
		 */
		return apply_filters( 'tagwright_seo_admin_pages', $pages );
	}

	/**
	 * Adds the menu and sub pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		$capability = self::capability();

		self::$hooks[] = add_menu_page(
			__( 'Tagwright SEO', 'tagwright-seo' ),
			__( 'Tagwright SEO', 'tagwright-seo' ),
			$capability,
			'tagwright-seo',
			array( Dashboard::class, 'render' ),
			$this->menu_icon(),
			80
		);

		foreach ( self::pages() as $slug => $page ) {
			self::$hooks[] = add_submenu_page(
				! empty( $page['hidden'] ) ? '' : 'tagwright-seo',
				$page['title'],
				$page['menu_title'],
				$capability,
				$slug,
				$page['callback']
			);
		}
	}

	/**
	 * Whether the current screen belongs to Tagwright SEO.
	 *
	 * @return bool
	 */
	public static function is_plugin_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the page slug.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return '' !== $page && array_key_exists( $page, self::pages() );
	}

	/**
	 * Registers and enqueues the design system where it is needed.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		self::register_assets();

		$editor_screens = array( 'post.php', 'post-new.php', 'term.php', 'edit-tags.php', 'profile.php', 'user-edit.php', 'edit.php' );

		if ( self::is_plugin_screen() || in_array( $hook, $editor_screens, true ) ) {
			wp_enqueue_style( 'tagwright-seo-admin' );
			wp_enqueue_script( 'tagwright-seo-admin' );
		}

		if ( self::is_plugin_screen() ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Registers the shared assets so add-ons can depend on the handles.
	 *
	 * @return void
	 */
	public static function register_assets() {
		if ( wp_script_is( 'tagwright-seo-admin', 'registered' ) ) {
			return;
		}

		wp_register_style( 'tagwright-seo-admin', TAGWRIGHT_SEO_URL . 'assets/css/admin.css', array(), TAGWRIGHT_SEO_VERSION );

		wp_register_script(
			'tagwright-seo-admin',
			TAGWRIGHT_SEO_URL . 'assets/js/admin.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			TAGWRIGHT_SEO_VERSION,
			true
		);

		wp_localize_script(
			'tagwright-seo-admin',
			'tagwrightSeoAdmin',
			array(
				'icons'      => Icons::all(),
				'variables'  => Replacer::describe(),
				'varLabels'  => Replacer::labels(),
				'separators' => Replacer::separators(),
				'values'     => Replacer::static_values(),
				'adminUrl'   => admin_url(),
				'homeUrl'    => home_url( '/' ),
				'version'    => TAGWRIGHT_SEO_VERSION,
			)
		);

		wp_set_script_translations( 'tagwright-seo-admin', 'tagwright-seo' );
	}

	/**
	 * Sends the site owner to the setup wizard once after activation.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_wizard() {
		if ( ! get_option( Installer::WIZARD_REDIRECT_OPTION ) ) {
			return;
		}

		delete_option( Installer::WIZARD_REDIRECT_OPTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bulk activation check only.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( self::capability() ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tagwright-seo-setup' ) );
		exit;
	}

	/**
	 * Adds quick links on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=tagwright-seo-settings' ) ), esc_html__( 'Settings', 'tagwright-seo' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=tagwright-seo' ) ), esc_html__( 'Dashboard', 'tagwright-seo' ) )
		);

		return $links;
	}

	/**
	 * Adds a body class on our screens so the shell styles can apply.
	 *
	 * @param string $classes Space separated classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		if ( self::is_plugin_screen() ) {
			$classes .= ' tagwright-seo-screen';
		}

		return $classes;
	}

	/**
	 * Friendlier footer on our screens.
	 *
	 * @param string $text Footer text.
	 * @return string
	 */
	public function footer_text( $text ) {
		if ( ! self::is_plugin_screen() || ! Brand::value( 'credits', true ) ) {
			return $text;
		}

		return sprintf(
			/* translators: %s: link to the plugin reviews page. */
			__( 'Enjoying Tagwright SEO? A %s review helps a lot.', 'tagwright-seo' ),
			'<a href="https://wordpress.org/support/plugin/tagwright-seo/reviews/#new-post" target="_blank" rel="noopener">★★★★★</a>'
		);
	}

	/**
	 * Menu icon as an SVG data URI. WordPress recolors the fill to match the
	 * admin color scheme.
	 *
	 * @return string
	 */
	private function menu_icon() {
		$custom = (string) Brand::value( 'menu_icon', '' );
		if ( '' !== $custom ) {
			return $custom;
		}

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M10 1 2 5.5v9L10 19l8-4.5v-9L10 1zm0 2.3 6 3.4v6.6l-6 3.4-6-3.4V6.7l6-3.4z"/><path fill="black" d="M6.3 13.3V6.7h1.6L10 9.4l2.1-2.7h1.6v6.6h-1.6V9.3L10 12 7.9 9.3v4z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard way to pass a menu icon.
	}
}
