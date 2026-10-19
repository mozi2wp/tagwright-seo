<?php
/**
 * JSON-LD schema graph.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Schema;

use TagwrightSEO\Frontend\Breadcrumb_Trail;
use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Frontend\Social;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one connected schema graph per page.
 *
 * Pieces reference each other through @id, the way Google recommends, so
 * the page, the article, its author and the site's publisher are all linked
 * instead of floating around as separate blobs.
 */
class Graph {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'tagwright_seo_head', array( $this, 'output' ), 30 );
	}

	/**
	 * Page types an editor can choose for a post.
	 *
	 * @return array<string, string>
	 */
	public static function page_types() {
		return apply_filters(
			'tagwright_seo_schema_page_types',
			array(
				'WebPage'           => __( 'Web Page (default)', 'tagwright-seo' ),
				'AboutPage'         => __( 'About Page', 'tagwright-seo' ),
				'ContactPage'       => __( 'Contact Page', 'tagwright-seo' ),
				'CollectionPage'    => __( 'Collection Page', 'tagwright-seo' ),
				'ItemPage'          => __( 'Item Page', 'tagwright-seo' ),
				'FAQPage'           => __( 'FAQ Page', 'tagwright-seo' ),
				'QAPage'            => __( 'Q&A Page', 'tagwright-seo' ),
				'ProfilePage'       => __( 'Profile Page', 'tagwright-seo' ),
				'CheckoutPage'      => __( 'Checkout Page', 'tagwright-seo' ),
				'MedicalWebPage'    => __( 'Medical Web Page', 'tagwright-seo' ),
				'RealEstateListing' => __( 'Real Estate Listing', 'tagwright-seo' ),
				'SearchResultsPage' => __( 'Search Results Page', 'tagwright-seo' ),
			)
		);
	}

	/**
	 * Article types an editor can choose for a post.
	 *
	 * @return array<string, string>
	 */
	public static function article_types() {
		return apply_filters(
			'tagwright_seo_schema_article_types',
			array(
				'None'             => __( 'None', 'tagwright-seo' ),
				'Article'          => __( 'Article', 'tagwright-seo' ),
				'BlogPosting'      => __( 'Blog Post', 'tagwright-seo' ),
				'NewsArticle'      => __( 'News Article', 'tagwright-seo' ),
				'TechArticle'      => __( 'Tech Article', 'tagwright-seo' ),
				'ScholarlyArticle' => __( 'Scholarly Article', 'tagwright-seo' ),
				'Report'           => __( 'Report', 'tagwright-seo' ),
			)
		);
	}

	/**
	 * Prints the graph.
	 *
	 * @param Context $context Current context.
	 * @return void
	 */
	public function output( $context ) {
		if ( 'not_found' === $context->type ) {
			return;
		}

		$graph = $this->build( $context );
		if ( empty( $graph ) ) {
			return;
		}

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		// Make sure nothing inside the JSON can close the script tag early.
		$json = str_replace( '</', '<\/', $json );

		wp_print_inline_script_tag(
			$json,
			array(
				'type'  => 'application/ld+json',
				'class' => 'tagwright-seo-schema',
			)
		);
	}

	/**
	 * Builds the list of graph pieces.
	 *
	 * @param Context $context Page context.
	 * @return array[]
	 */
	public function build( Context $context ) {
		$graph = array();
		$url   = $this->page_url( $context );

		$publisher = $this->publisher();
		if ( $publisher ) {
			$graph[] = $publisher;
		}

		$graph[] = $this->website( $publisher );

		$image = null;
		if ( $context->object instanceof \WP_Post ) {
			$image = $this->primary_image( $context, $url );
			if ( $image ) {
				$graph[] = $image;
			}
		}

		$breadcrumb = $this->breadcrumb( $context, $url );
		if ( $breadcrumb ) {
			$graph[] = $breadcrumb;
		}

		$graph[] = $this->webpage( $context, $url, $image, $breadcrumb );

		if ( 'singular' === $context->type ) {
			$article = $this->article( $context, $url, $image, $publisher );
			if ( $article ) {
				$graph[] = $article;
				$graph[] = $this->author( $context->object->post_author );
			}
		}

		if ( 'author' === $context->type ) {
			$graph[] = $this->author( $context->object->ID );
		}

		/**
		 * Filters the schema graph. Add-ons append pieces here, for example
		 * Product, Recipe or LocalBusiness.
		 *
		 * @param array[] $graph   Graph pieces.
		 * @param Context $context Page context.
		 */
		$graph = apply_filters( 'tagwright_seo_schema_graph', $graph, $context );

		return array_values( array_filter( $graph ) );
	}

	/**
	 * The Organization or Person behind the site.
	 *
	 * @return array|null
	 */
	public function publisher() {
		$site_url = home_url( '/' );
		$same_as  = $this->same_as();

		if ( 'person' === Options::get( 'site_represents' ) ) {
			$user_id = (int) Options::get( 'person_user_id' );
			if ( ! $user_id ) {
				return null;
			}

			// The chosen user may have been deleted; no publisher beats a broken one.
			$person = $this->author( $user_id );
			if ( ! $person ) {
				return null;
			}

			$image = Social::image_data( (int) Options::get( 'person_image_id' ) );
			if ( $image ) {
				$person['image'] = $this->image_object( $image, $site_url . '#personlogo' );
				$person['logo']  = array( '@id' => $site_url . '#personlogo' );
			}
			if ( $same_as ) {
				$person['sameAs'] = array_values( array_unique( array_merge( $person['sameAs'] ?? array(), $same_as ) ) );
			}
			$person['@type'] = array( 'Person', 'Organization' );

			return $person;
		}

		$name = trim( (string) Options::get( 'org_name' ) );
		$name = '' !== $name ? $name : get_bloginfo( 'name' );

		$organization = array(
			'@type' => 'Organization',
			'@id'   => $site_url . '#organization',
			'name'  => $name,
			'url'   => $site_url,
		);

		$alternate = trim( (string) Options::get( 'org_alternate_name' ) );
		if ( '' !== $alternate ) {
			$organization['alternateName'] = $alternate;
		}

		$logo = Social::image_data( (int) Options::get( 'org_logo_id' ) );
		if ( ! $logo && has_custom_logo() ) {
			$logo = Social::image_data( (int) get_theme_mod( 'custom_logo' ) );
		}
		if ( $logo ) {
			$organization['logo']  = $this->image_object( $logo, $site_url . '#logo', $name );
			$organization['image'] = array( '@id' => $site_url . '#logo' );
		}

		if ( Options::get( 'org_email' ) ) {
			$organization['email'] = sanitize_email( Options::get( 'org_email' ) );
		}
		if ( Options::get( 'org_phone' ) ) {
			$organization['telephone'] = (string) Options::get( 'org_phone' );
		}
		if ( $same_as ) {
			$organization['sameAs'] = $same_as;
		}

		return $organization;
	}

	/**
	 * WebSite piece.
	 *
	 * @param array|null $publisher Publisher piece.
	 * @return array
	 */
	private function website( $publisher ) {
		$site_url = home_url( '/' );

		$website = array(
			'@type'      => 'WebSite',
			'@id'        => $site_url . '#website',
			'url'        => $site_url,
			'name'       => get_bloginfo( 'name' ),
			'inLanguage' => get_bloginfo( 'language' ),
		);

		$description = get_bloginfo( 'description' );
		if ( $description ) {
			$website['description'] = $description;
		}

		if ( $publisher ) {
			$website['publisher'] = array( '@id' => $publisher['@id'] );
		}

		if ( Options::get( 'website_search_action' ) ) {
			$website['potentialAction'] = array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => add_query_arg( 's', '{search_term_string}', $site_url ),
					),
					'query-input' => 'required name=search_term_string',
				),
			);
		}

		return $website;
	}

	/**
	 * WebPage piece, with the right subtype for the page.
	 *
	 * @param Context    $context    Page context.
	 * @param string     $url        Page URL.
	 * @param array|null $image      Primary image piece.
	 * @param array|null $breadcrumb Breadcrumb piece.
	 * @return array
	 */
	private function webpage( Context $context, $url, $image, $breadcrumb ) {
		$site_url = home_url( '/' );

		$page = array(
			'@type'      => $this->webpage_type( $context ),
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => $context->get_title(),
			'isPartOf'   => array( '@id' => $site_url . '#website' ),
			'inLanguage' => get_bloginfo( 'language' ),
		);

		$description = $context->get_description();
		if ( '' !== $description ) {
			$page['description'] = $description;
		}

		if ( $context->object instanceof \WP_Post ) {
			$page['datePublished'] = get_post_time( 'c', true, $context->object );
			$page['dateModified']  = get_post_modified_time( 'c', true, $context->object );
		}

		if ( $context->is_front() ) {
			$publisher = $this->publisher();
			if ( $publisher ) {
				$page['about'] = array( '@id' => $publisher['@id'] );
			}
		}

		if ( $image ) {
			$page['primaryImageOfPage'] = array( '@id' => $image['@id'] );
			$page['image']              = array( '@id' => $image['@id'] );
			$page['thumbnailUrl']       = $image['url'];
		}

		if ( $breadcrumb ) {
			$page['breadcrumb'] = array( '@id' => $breadcrumb['@id'] );
		}

		$page['potentialAction'] = array(
			array(
				'@type'  => 'ReadAction',
				'target' => array( $url ),
			),
		);

		return $page;
	}

	/**
	 * Picks the WebPage subtype.
	 *
	 * @param Context $context Page context.
	 * @return string
	 */
	private function webpage_type( Context $context ) {
		switch ( $context->type ) {
			case 'search':
				return 'SearchResultsPage';
			case 'author':
				return 'ProfilePage';
			case 'term':
			case 'post_type_archive':
			case 'date':
			case 'home':
			case 'posts_page':
				return 'CollectionPage';
		}

		if ( $context->object instanceof \WP_Post ) {
			$type = $context->row_value( 'schema_page_type' );
			if ( '' === $type ) {
				$type = (string) Options::get( 'pt_' . $context->object->post_type . '_page_type', 'WebPage' );
			}
			return isset( self::page_types()[ $type ] ) ? $type : 'WebPage';
		}

		return 'WebPage';
	}

	/**
	 * Article piece for posts that should have one.
	 *
	 * @param Context    $context   Page context.
	 * @param string     $url       Page URL.
	 * @param array|null $image     Primary image piece.
	 * @param array|null $publisher Publisher piece.
	 * @return array|null
	 */
	private function article( Context $context, $url, $image, $publisher ) {
		$post = $context->object;
		$type = $context->row_value( 'schema_article_type' );

		if ( '' === $type ) {
			$type = (string) Options::get( 'pt_' . $post->post_type . '_article_type', 'None' );
		}

		if ( 'None' === $type || ! isset( self::article_types()[ $type ] ) ) {
			return null;
		}

		$author_id = $this->person_id( $post->post_author );

		$article = array(
			'@type'            => $type,
			'@id'              => $url . '#article',
			'isPartOf'         => array( '@id' => $url . '#webpage' ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'headline'         => Replacer::plain( get_the_title( $post ), 110 ),
			'datePublished'    => get_post_time( 'c', true, $post ),
			'dateModified'     => get_post_modified_time( 'c', true, $post ),
			'author'           => array(
				'@id'  => $author_id,
				'name' => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'wordCount'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			'commentCount'     => (int) get_comments_number( $post ),
			'inLanguage'       => get_bloginfo( 'language' ),
		);

		if ( $publisher ) {
			$article['publisher'] = array( '@id' => $publisher['@id'] );
		}

		if ( $image ) {
			$article['image']        = array( '@id' => $image['@id'] );
			$article['thumbnailUrl'] = $image['url'];
		}

		$tags = get_the_tags( $post->ID );
		if ( is_array( $tags ) && $tags ) {
			$article['keywords'] = wp_list_pluck( $tags, 'name' );
		}

		$categories = get_the_category( $post->ID );
		if ( $categories ) {
			$article['articleSection'] = wp_list_pluck( $categories, 'name' );
		}

		if ( comments_open( $post ) ) {
			$article['potentialAction'] = array(
				array(
					'@type'  => 'CommentAction',
					'name'   => 'Comment',
					'target' => array( $url . '#respond' ),
				),
			);
		}

		return $article;
	}

	/**
	 * Person piece for an author.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public function author( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return array();
		}

		$person = array(
			'@type' => 'Person',
			'@id'   => $this->person_id( $user->ID ),
			'name'  => $user->display_name,
			'url'   => get_author_posts_url( $user->ID ),
		);

		$bio = get_the_author_meta( 'description', $user->ID );
		if ( $bio ) {
			$person['description'] = Replacer::plain( $bio, 0 );
		}

		$avatar = get_avatar_url( $user->ID, array( 'size' => 96 ) );
		if ( $avatar ) {
			$person['image'] = array(
				'@type'      => 'ImageObject',
				'@id'        => home_url( '/' ) . '#/schema/person/image/' . md5( (string) $user->ID ),
				'url'        => $avatar,
				'contentUrl' => $avatar,
				'caption'    => $user->display_name,
			);
		}

		$same_as = array();
		if ( $user->user_url ) {
			$same_as[] = $user->user_url;
		}
		foreach ( array( 'facebook', 'twitter', 'instagram', 'linkedin', 'youtube' ) as $network ) {
			$value = get_the_author_meta( $network, $user->ID );
			if ( $value ) {
				$same_as[] = 'twitter' === $network && false === strpos( $value, '://' ) ? 'https://x.com/' . ltrim( $value, '@' ) : $value;
			}
		}
		if ( $same_as ) {
			$person['sameAs'] = array_values( array_unique( array_filter( $same_as, array( __CLASS__, 'is_url' ) ) ) );
		}

		return $person;
	}

	/**
	 * Primary image of a post.
	 *
	 * @param Context $context Page context.
	 * @param string  $url     Page URL.
	 * @return array|null
	 */
	private function primary_image( Context $context, $url ) {
		$image = ( new Social() )->get_image( $context );

		return $image ? $this->image_object( $image, $url . '#primaryimage' ) : null;
	}

	/**
	 * BreadcrumbList piece.
	 *
	 * @param Context $context Page context.
	 * @param string  $url     Page URL.
	 * @return array|null
	 */
	private function breadcrumb( Context $context, $url ) {
		if ( $context->is_front() ) {
			return null;
		}

		$crumbs = Breadcrumb_Trail::build( $context );
		if ( count( $crumbs ) < 2 ) {
			return null;
		}

		$items    = array();
		$position = 1;
		$last     = count( $crumbs );

		foreach ( $crumbs as $crumb ) {
			$item = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => wp_strip_all_tags( $crumb['text'] ),
			);

			// Google wants the item URL on every crumb except the current page.
			if ( $position < $last && ! empty( $crumb['url'] ) ) {
				$item['item'] = $crumb['url'];
			}

			$items[] = $item;
			++$position;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * ImageObject piece.
	 *
	 * @param array  $image   Image data from Social::image_data().
	 * @param string $id      @id to use.
	 * @param string $caption Optional caption.
	 * @return array
	 */
	private function image_object( array $image, $id, $caption = '' ) {
		$object = array(
			'@type'      => 'ImageObject',
			'@id'        => $id,
			'url'        => $image['url'],
			'contentUrl' => $image['url'],
		);

		if ( ! empty( $image['width'] ) ) {
			$object['width']  = (int) $image['width'];
			$object['height'] = (int) $image['height'];
		}

		$caption = $caption ? $caption : ( $image['alt'] ?? '' );
		if ( $caption ) {
			$object['caption'] = $caption;
		}

		return $object;
	}

	/**
	 * Social profile URLs from the settings.
	 *
	 * @return string[]
	 */
	private function same_as() {
		$profiles = preg_split( '/[\r\n]+/', (string) Options::get( 'social_profiles' ) );
		$profiles = array_map( 'trim', (array) $profiles );

		$facebook = (string) Options::get( 'facebook_page_url' );
		if ( $facebook ) {
			$profiles[] = $facebook;
		}

		$twitter = trim( (string) Options::get( 'twitter_username' ) );
		if ( $twitter ) {
			$profiles[] = false === strpos( $twitter, '://' ) ? 'https://x.com/' . ltrim( $twitter, '@' ) : $twitter;
		}

		return array_values( array_unique( array_filter( $profiles, array( __CLASS__, 'is_url' ) ) ) );
	}

	/**
	 * Cheap URL check. wp_http_validate_url() does DNS lookups, which we do
	 * not want on every page view.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_url( $url ) {
		return (bool) filter_var( $url, FILTER_VALIDATE_URL ) && preg_match( '#^https?://#i', $url );
	}

	/**
	 * Stable @id for a person.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function person_id( $user_id ) {
		return home_url( '/' ) . '#/schema/person/' . md5( (string) $user_id );
	}

	/**
	 * URL used as the base for the page's @ids.
	 *
	 * @param Context $context Page context.
	 * @return string
	 */
	private function page_url( Context $context ) {
		$url = $context->get_canonical();

		if ( '' === $url ) {
			if ( $context->object instanceof \WP_Post ) {
				$url = (string) get_permalink( $context->object );
			} elseif ( 'search' === $context->type ) {
				$url = get_search_link();
			} else {
				$url = home_url( add_query_arg( array() ) );
			}
		}

		return $url;
	}
}
