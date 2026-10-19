<?php
/**
 * Table of contents.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a table of contents from the headings in the rendered content.
 *
 * The block cannot see the finished page while it is being rendered: the
 * blocks after it don't exist yet. So it leaves a placeholder comment and
 * hooks the_content once. After every block and shortcode has run, that
 * filter gives the headings ids where they lack one and swaps the
 * placeholder for the list. Working on the final HTML means headings from
 * classic content, shortcodes and nested blocks all count, and the links
 * always point at ids that really exist.
 *
 * Posts without a table of contents never reach that filter, so their
 * headings are left exactly as they were.
 */
class Toc {

	/**
	 * Settings of each table of contents rendered in this request.
	 *
	 * @var array[]
	 */
	private static $pending = array();

	/**
	 * Ids WordPress themes commonly use for page landmarks. A heading called
	 * "Comments" must not take #comments away from the comment form.
	 *
	 * @var string[]
	 */
	const RESERVED_IDS = array( 'content', 'main', 'primary', 'secondary', 'page', 'comments', 'respond', 'header', 'footer', 'masthead', 'colophon', 'sidebar', 'site-navigation' );

	/**
	 * Stores a table of contents and returns its placeholder.
	 *
	 * @param array $args title, levels, list_style, collapsible, wrapper.
	 * @return string
	 */
	public static function placeholder( array $args ) {
		$levels = array();
		foreach ( (array) ( $args['levels'] ?? array() ) as $level ) {
			$level = (int) $level;
			if ( $level >= 2 && $level <= 6 ) {
				$levels[] = $level;
			}
		}

		$index                   = count( self::$pending );
		self::$pending[ $index ] = array(
			'title'       => (string) ( $args['title'] ?? '' ),
			'levels'      => $levels ? array_values( array_unique( $levels ) ) : array( 2, 3 ),
			'list_style'  => 'ol' === ( $args['list_style'] ?? '' ) ? 'ol' : 'ul',
			'collapsible' => ! empty( $args['collapsible'] ),
			'wrapper'     => (string) ( $args['wrapper'] ?? 'class="tagwright-toc"' ),
		);

		// Priority 13 runs after blocks (9), shortcodes (11) and wp_filter_content_tags (12).
		// WordPress picks up a filter added at a later priority while the hook is running.
		if ( ! has_filter( 'the_content', array( __CLASS__, 'fill' ) ) ) {
			add_filter( 'the_content', array( __CLASS__, 'fill' ), 13 );
		}

		return '<!--tagwright-seo-toc:' . $index . '-->';
	}

	/**
	 * Adds heading ids and replaces placeholders with the lists.
	 *
	 * @param string $content Rendered content.
	 * @return string
	 */
	public static function fill( $content ) {
		$content = (string) $content;

		if ( false === strpos( $content, '<!--tagwright-seo-toc:' ) ) {
			return $content;
		}

		list( $content, $headings ) = self::process_headings( $content );

		return (string) preg_replace_callback(
			'/<!--tagwright-seo-toc:(\d+)-->/',
			static function ( $match ) use ( $headings ) {
				$args = self::$pending[ (int) $match[1] ] ?? null;

				return $args ? self::render( $headings, $args ) : '';
			},
			$content
		);
	}

	/**
	 * Collects the h2–h6 headings and gives those without an id a readable,
	 * unique one.
	 *
	 * Two passes: the first reads every heading and every id already in the
	 * page, the second writes the new ids. Handing ids out only once all
	 * existing ones are known means a heading further down that already has
	 * "#setup" keeps it, and the new one becomes "setup-2".
	 *
	 * Headings with the class "tagwright-toc-skip" keep their place in the page
	 * but stay out of the list.
	 *
	 * @param string $html HTML.
	 * @return array{0: string, 1: array[]} Updated HTML and the listed
	 *                                      headings as array( 'level', 'text', 'id' ).
	 */
	public static function process_headings( $html ) {
		$reader   = new \WP_HTML_Tag_Processor( $html );
		$headings = array();
		$used     = array_fill_keys( self::RESERVED_IDS, true );

		while ( $reader->next_token() ) {
			if ( '#tag' !== $reader->get_token_type() || $reader->is_tag_closer() ) {
				continue;
			}

			$id = $reader->get_attribute( 'id' );
			$id = is_string( $id ) ? trim( $id ) : '';
			if ( '' !== $id ) {
				$used[ $id ] = true;
			}

			$tag = (string) $reader->get_tag();
			if ( ! preg_match( '/^H([2-6])$/', $tag, $match ) ) {
				continue;
			}

			$skip = (bool) $reader->has_class( 'tagwright-toc-skip' );
			$text = '';

			while ( $reader->next_token() ) {
				$type = $reader->get_token_type();
				if ( '#text' === $type ) {
					$text .= $reader->get_modifiable_text();
				} elseif ( '#tag' === $type && $tag === $reader->get_tag() && $reader->is_tag_closer() ) {
					break;
				}
			}

			$headings[] = array(
				'level' => (int) $match[1],
				'text'  => trim( (string) preg_replace( '/\s+/u', ' ', $text ) ),
				'id'    => $id,
				'skip'  => $skip,
			);
		}

		$new_ids = array();
		foreach ( $headings as $index => $heading ) {
			if ( '' !== $heading['id'] ) {
				continue;
			}

			$base = sanitize_title( $heading['text'] );
			$base = '' !== $base ? $base : 'section';
			$id   = $base;

			for ( $n = 2; isset( $used[ $id ] ); $n++ ) {
				$id = $base . '-' . $n;
			}

			$used[ $id ]              = true;
			$new_ids[ $index ]        = $id;
			$headings[ $index ]['id'] = $id;
		}

		if ( $new_ids ) {
			$writer = new \WP_HTML_Tag_Processor( $html );
			$index  = 0;

			while ( $writer->next_tag() ) {
				if ( ! preg_match( '/^H[2-6]$/', (string) $writer->get_tag() ) ) {
					continue;
				}
				if ( isset( $new_ids[ $index ] ) ) {
					$writer->set_attribute( 'id', $new_ids[ $index ] );
				}
				++$index;
			}

			$html = $writer->get_updated_html();
		}

		$listed = array();
		foreach ( $headings as $heading ) {
			if ( ! $heading['skip'] && '' !== $heading['text'] ) {
				unset( $heading['skip'] );
				$listed[] = $heading;
			}
		}

		return array( $html, $listed );
	}

	/**
	 * Markup for one table of contents.
	 *
	 * @param array[] $headings All headings in the content.
	 * @param array   $args     Stored settings.
	 * @return string
	 */
	public static function render( array $headings, array $args ) {
		$headings = array_values(
			array_filter(
				$headings,
				static fn( $heading ) => in_array( $heading['level'], $args['levels'], true )
			)
		);

		if ( ! $headings ) {
			return '';
		}

		Renderer::enqueue_style();

		$list  = self::nested_list( $headings, $args['list_style'] );
		$title = trim( $args['title'] );
		$label = '' !== $title ? $title : __( 'Table of contents', 'tagwright-seo' );

		// The title is not a heading on purpose: it would end up in its own list.
		if ( $args['collapsible'] ) {
			$body = '<details class="tagwright-toc__details"><summary class="tagwright-toc__title">' . esc_html( $label ) . '</summary>' . $list . '</details>';
		} else {
			$body = ( '' !== $title ? '<p class="tagwright-toc__title">' . esc_html( $title ) . '</p>' : '' ) . $list;
		}

		// The wrapper attributes were escaped when the block was rendered.
		return '<nav ' . $args['wrapper'] . ' aria-label="' . esc_attr( $label ) . '">' . $body . '</nav>';
	}

	/**
	 * Turns a flat list of headings into nested lists.
	 *
	 * A jump of more than one level (h2 straight to h4) nests one step
	 * deeper instead of inventing empty levels, and a heading that is
	 * higher than the first one simply starts a new top level item.
	 *
	 * @param array[] $headings Headings with level, text and id.
	 * @param string  $tag      ul or ol.
	 * @return string
	 */
	public static function nested_list( array $headings, $tag ) {
		$tag   = 'ol' === $tag ? 'ol' : 'ul';
		$open  = '<' . $tag . ' class="tagwright-toc__list">';
		$close = '</' . $tag . '>';
		$stack = array();
		$html  = '';

		foreach ( $headings as $heading ) {
			$level = $heading['level'];

			if ( ! $stack ) {
				$html   .= $open;
				$stack[] = $level;
			} elseif ( $level > end( $stack ) ) {
				$html   .= $open;
				$stack[] = $level;
			} else {
				$html .= '</li>';

				while ( count( $stack ) > 1 && $level < end( $stack ) ) {
					$parent = $stack[ count( $stack ) - 2 ];
					if ( $level > $parent ) {
						// Between the parent and this list: stay in this list.
						$stack[ count( $stack ) - 1 ] = $level;
						break;
					}
					array_pop( $stack );
					$html .= $close . '</li>';
				}

				if ( $level < end( $stack ) ) {
					$stack[ count( $stack ) - 1 ] = $level;
				}
			}

			$html .= sprintf( '<li><a href="#%1$s">%2$s</a>', esc_attr( $heading['id'] ), esc_html( $heading['text'] ) );
		}

		$html .= '</li>';
		while ( count( $stack ) > 1 ) {
			array_pop( $stack );
			$html .= $close . '</li>';
		}

		return $html . $close;
	}
}
