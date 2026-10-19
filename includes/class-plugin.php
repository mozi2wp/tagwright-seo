<?php
/**
 * Main plugin class.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin and loads only the parts that the current request needs.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Loaded components, keyed by a short name so add-ons can reach them.
	 *
	 * @var object[]
	 */
	private $components = array();

	/**
	 * Returns the shared instance, booting it on first call.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Use Plugin::instance() instead.
	 */
	private function __construct() {}

	/**
	 * Wires everything up.
	 *
	 * @return void
	 */
	private function boot() {
		Installer::init();

		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );

		// The index has to stay in sync no matter which screen saved the content.
		$this->components['sync']    = new Data\Object_Sync();
		$this->components['indexer'] = new Data\Indexer();
		$this->components['stats']   = new Data\Stats();

		$this->load_modules();

		if ( is_admin() ) {
			$this->load_admin();
		} else {
			$this->load_frontend();
		}

		$this->components['admin_bar'] = new Admin\Admin_Bar();
		$this->components['rest']      = new Api\Rest_Controller();
		$this->components['rest_head'] = new Api\Rest_Fields();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'tagwright-seo', Cli\Command::class );
		}

		/**
		 * Fires once Tagwright SEO has loaded. Add-ons should bootstrap here.
		 *
		 * @param Plugin $plugin Main plugin instance.
		 */
		do_action( 'tagwright_seo_loaded', $this );

		// Settings were read during boot, before add-ons registered their
		// defaults and modules. Drop those caches once add-ons are loaded.
		add_action( 'init', array( Options::class, 'flush_cache' ), 0 );
		add_action( 'init', array( Modules::class, 'flush_cache' ), 0 );
		Options::flush_cache();
		Modules::flush_cache();
	}

	/**
	 * Components that work on both sides of the site.
	 *
	 * @return void
	 */
	private function load_modules() {
		$this->components['cleanup']       = new Technical\Cleanup();
		$this->components['category_base'] = new Technical\Category_Base();
		$this->components['robots_txt']    = new Technical\Robots_Txt();

		if ( Modules::is_active( 'sitemaps' ) ) {
			$this->components['sitemaps'] = new Sitemaps\Router();
		}

		if ( Modules::is_active( 'llms_txt' ) ) {
			$this->components['llms_txt'] = new Technical\Llms_Txt();
		}

		// Built-in features: only the ones switched on are created.
		$this->components = array_merge( $this->components, Features\Registry::boot() );
		add_action( 'tagwright_seo_module_toggled', array( Features\Registry::class, 'on_module_toggled' ), 10, 2 );
		add_filter( 'wpmu_drop_tables', array( Features\Registry::class, 'drop_tables_for_site' ) );
	}

	/**
	 * Front end only components.
	 *
	 * @return void
	 */
	private function load_frontend() {
		$this->components['head'] = new Frontend\Head();

		if ( Modules::is_active( 'social' ) ) {
			$this->components['social'] = new Frontend\Social();
		}

		if ( Modules::is_active( 'schema' ) ) {
			$this->components['schema'] = new Schema\Graph();
		}
	}

	/**
	 * Admin only components.
	 *
	 * @return void
	 */
	private function load_admin() {
		$this->components['admin']        = new Admin\Admin();
		$this->components['metabox']      = new Admin\Metabox();
		$this->components['term_fields']  = new Admin\Term_Fields();
		$this->components['user_fields']  = new Admin\User_Fields();
		$this->components['post_columns'] = new Admin\Post_Columns();
		$this->components['wizard']       = new Admin\Setup_Wizard();
	}

	/**
	 * Returns a loaded component, or null when it is not loaded for this request.
	 *
	 * @param string $name Component name, for example `head` or `sitemaps`.
	 * @return object|null
	 */
	public function get( $name ) {
		return $this->components[ $name ] ?? null;
	}


	/**
	 * Flushes rewrite rules once after something asked for it.
	 *
	 * Flushing is expensive, so we only do it when a flag is set, for example
	 * after activation or when the sitemap module is switched on.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( get_option( 'tagwright_seo_flush_rewrite' ) ) {
			delete_option( 'tagwright_seo_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}
}
