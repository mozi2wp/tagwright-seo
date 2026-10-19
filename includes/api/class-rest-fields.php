<?php
/**
 * SEO data in core REST API responses, for headless sites.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Api;

use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Frontend\Head;
use TagwrightSEO\Frontend\Social;
use TagwrightSEO\Modules;
use TagwrightSEO\Schema\Graph;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a `tagwright_seo` field to posts and terms in the WordPress REST API
 * with the resolved title, description, robots, canonical, social tags,
 * schema graph and a ready-to-print head HTML string.
 */
class Rest_Fields {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Registers the field on public post types and taxonomies.
	 *
	 * @return void
	 */
	public function register() {
		/**
		 * Filters whether SEO data is added to REST responses.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'tagwright_seo_rest_fields_enabled', true ) ) {
			return;
		}

		$schema = array(
			'description' => __( 'Tagwright SEO data for this object.', 'tagwright-seo' ),
			'type'        => 'object',
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
		);

		foreach ( get_post_types( array( 'public' => true, 'show_in_rest' => true ) ) as $post_type ) {
			register_rest_field(
				$post_type,
				'tagwright_seo',
				array(
					'get_callback' => array( $this, 'post_field' ),
					'schema'       => $schema,
				)
			);
		}

		foreach ( get_taxonomies( array( 'public' => true, 'show_in_rest' => true ) ) as $taxonomy ) {
			register_rest_field(
				$taxonomy,
				'tagwright_seo',
				array(
					'get_callback' => array( $this, 'term_field' ),
					'schema'       => $schema,
				)
			);
		}
	}

	/**
	 * Field value for a post.
	 *
	 * @param array $data Prepared post data.
	 * @return array|null
	 */
	public function post_field( $data ) {
		$post = get_post( $data['id'] ?? 0 );
		if ( ! $post || ! is_post_publicly_viewable( $post ) ) {
			return null;
		}

		return $this->build( Context::for_post( $post ) );
	}

	/**
	 * Field value for a term.
	 *
	 * @param array $data Prepared term data.
	 * @return array|null
	 */
	public function term_field( $data ) {
		$term = get_term( $data['id'] ?? 0 );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		return $this->build( Context::for_term( $term ) );
	}

	/**
	 * Returns a loaded component, creating it once when it is not loaded.
	 *
	 * @param string $name  Component name.
	 * @param string $class Class to create as a fallback.
	 * @return object
	 */
	private function component( $name, $class ) {
		static $created = array();

		$component = tagwright_seo()->get( $name );
		if ( $component ) {
			return $component;
		}

		if ( ! isset( $created[ $name ] ) ) {
			$created[ $name ] = new $class();
		}

		return $created[ $name ];
	}

	/**
	 * Resolves everything for a context.
	 *
	 * @param Context $context Context.
	 * @return array
	 */
	private function build( Context $context ) {
		$robots = array();
		foreach ( $context->get_robots() as $directive => $value ) {
			if ( true === $value ) {
				$robots[] = $directive;
			} elseif ( false !== $value ) {
				$robots[] = $directive . ':' . $value;
			}
		}

		// Reuse the front end components the plugin already loaded for this request.
		$head_component   = $this->component( 'head', Head::class );
		$social_component = Modules::is_active( 'social' ) ? $this->component( 'social', Social::class ) : null;
		$schema_component = Modules::is_active( 'schema' ) ? $this->component( 'schema', Graph::class ) : null;

		$social = $social_component ? $social_component->get_tags( $context ) : array();
		$graph  = $schema_component ? $schema_component->build( $context ) : array();

		$tags = array();
		foreach ( $social as $tag ) {
			if ( '' !== (string) $tag[2] ) {
				$tags[] = array(
					$tag[0]   => $tag[1],
					'content' => (string) $tag[2],
				);
			}
		}

		// The same markup the front end prints, so a headless app can drop it in.
		$head  = '<title>' . esc_html( $context->get_title() ) . "</title>\n";
		$head .= '<meta name="robots" content="' . esc_attr( implode( ', ', $robots ) ) . "\" />\n";
		ob_start();
		$head_component->print_description( $context );
		$head_component->print_keywords( $context );
		$head_component->print_canonical( $context );
		if ( $social_component ) {
			$social_component->output( $context );
		}
		if ( $schema_component ) {
			$schema_component->output( $context );
		}
		$head .= ob_get_clean();

		return array(
			'title'       => $context->get_title(),
			'description' => $context->get_description(),
			'keywords'    => $context->get_keywords(),
			'canonical'   => $context->get_canonical(),
			'robots'      => implode( ', ', $robots ),
			'social'      => $tags,
			'schema'      => $graph ? array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			) : null,
			'head' => $head,
		);
	}
}
