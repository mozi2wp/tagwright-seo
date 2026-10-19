<?php
/**
 * FAQ and HowTo schema.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Blocks;

use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Adds FAQPage and HowTo pieces to the core schema graph for pages that
 * contain the FAQ or HowTo block.
 *
 * The schema is printed in <head>, before the content is rendered, so the
 * blocks are read from the saved post content instead of from the render
 * callbacks. has_block() is a plain string search, so pages without either
 * block stop there.
 */
class Schema {

	/**
	 * Adds the pieces.
	 *
	 * @param array[] $graph   Graph pieces.
	 * @param Context $context Page context.
	 * @return array[]
	 */
	public static function add( $graph, $context ) {
		if ( ! $context instanceof Context || ! $context->object instanceof \WP_Post ) {
			return $graph;
		}

		if ( ! in_array( $context->type, array( 'singular', 'front_page', 'posts_page' ), true ) ) {
			return $graph;
		}

		$post  = $context->object;
		$faq   = Options::get( 'blocks_faq_schema' ) && has_block( 'tagwright-seo/faq', $post );
		$howto = Options::get( 'blocks_howto_schema' ) && has_block( 'tagwright-seo/howto', $post );

		if ( ( ! $faq && ! $howto ) || post_password_required( $post ) ) {
			return $graph;
		}

		$blocks = self::find( parse_blocks( $post->post_content ), array( 'tagwright-seo/faq', 'tagwright-seo/howto' ) );

		list( $webpage_key, $url ) = self::webpage( $graph, $post );

		if ( $faq ) {
			$graph = self::add_faq( $graph, $blocks['tagwright-seo/faq'], $webpage_key, $url );
		}

		if ( $howto ) {
			foreach ( $blocks['tagwright-seo/howto'] as $index => $attributes ) {
				$piece = self::howto( $attributes, $index, $url, $post );
				if ( $piece ) {
					$graph[] = $piece;
				}
			}
		}

		return $graph;
	}

	/**
	 * Collects the attributes of the wanted blocks, including blocks nested
	 * in groups or columns, in document order.
	 *
	 * @param array[]  $blocks Parsed blocks.
	 * @param string[] $names  Block names.
	 * @return array<string, array[]>
	 */
	public static function find( array $blocks, array $names ) {
		$found = array_fill_keys( $names, array() );

		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'] ?? '', $names, true ) ) {
				$found[ $block['blockName'] ][] = (array) ( $block['attrs'] ?? array() );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( self::find( $block['innerBlocks'], $names ) as $name => $list ) {
					$found[ $name ] = array_merge( $found[ $name ], $list );
				}
			}
		}

		return $found;
	}

	/**
	 * Finds the core WebPage piece and the URL its @id is built from.
	 *
	 * @param array[]  $graph Graph pieces.
	 * @param \WP_Post $post  Post.
	 * @return array{0: int|null, 1: string} Index of the piece (null when
	 *                                       missing) and the page URL.
	 */
	private static function webpage( array $graph, $post ) {
		foreach ( $graph as $key => $piece ) {
			$id = is_array( $piece ) ? (string) ( $piece['@id'] ?? '' ) : '';
			if ( '#webpage' === substr( $id, -8 ) ) {
				return array( $key, substr( $id, 0, -8 ) );
			}
		}

		return array( null, (string) get_permalink( $post ) );
	}

	/**
	 * Turns the page into an FAQPage.
	 *
	 * Google reads FAQ markup from the page entity itself, so the core
	 * WebPage piece gets FAQPage as an extra type and points at the
	 * questions through mainEntity. The questions are their own pieces,
	 * linked by @id, like the rest of the graph.
	 *
	 * @param array[]  $graph       Graph pieces.
	 * @param array[]  $blocks      Attributes of every FAQ block.
	 * @param int|null $webpage_key Index of the WebPage piece.
	 * @param string   $url         Page URL.
	 * @return array[]
	 */
	private static function add_faq( array $graph, array $blocks, $webpage_key, $url ) {
		$questions = array();
		$number    = 0;

		foreach ( $blocks as $attributes ) {
			foreach ( Renderer::faq_items( $attributes['items'] ?? array() ) as $item ) {
				++$number;
				$questions[] = array(
					'@type'          => 'Question',
					'@id'            => $url . '#faq-question-' . $number,
					'position'       => $number,
					'name'           => Replacer::plain( $item['question'] ),
					'answerCount'    => 1,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => self::answer_html( $item['answer'] ),
					),
				);
			}
		}

		if ( ! $questions ) {
			return $graph;
		}

		$main_entity = array();
		foreach ( $questions as $question ) {
			$main_entity[] = array( '@id' => $question['@id'] );
		}

		if ( null !== $webpage_key ) {
			$types = (array) ( $graph[ $webpage_key ]['@type'] ?? 'WebPage' );
			if ( ! in_array( 'FAQPage', $types, true ) ) {
				$types[] = 'FAQPage';
			}

			$graph[ $webpage_key ]['@type']      = array_values( $types );
			$graph[ $webpage_key ]['mainEntity'] = $main_entity;
		} else {
			// Something removed the core page piece; stand on our own.
			$graph[] = array(
				'@type'      => 'FAQPage',
				'@id'        => $url . '#webpage',
				'url'        => $url,
				'mainEntity' => $main_entity,
			);
		}

		return array_merge( $graph, $questions );
	}

	/**
	 * Answer text for schema. Google allows a few tags in answers (links,
	 * lists, bold and so on); everything else is removed.
	 *
	 * @param string $html Answer HTML.
	 * @return string
	 */
	private static function answer_html( $html ) {
		$allowed = array(
			'a'      => array( 'href' => true ),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
		);

		return trim( wp_kses( (string) $html, $allowed ) );
	}

	/**
	 * HowTo piece for one block.
	 *
	 * @param array    $attributes Block attributes.
	 * @param int      $index      Zero based position among HowTo blocks.
	 * @param string   $url        Page URL.
	 * @param \WP_Post $post       Post.
	 * @return array|null
	 */
	private static function howto( array $attributes, $index, $url, $post ) {
		$steps = Renderer::howto_steps( $attributes['steps'] ?? array() );
		if ( ! $steps ) {
			return null;
		}

		$name = Replacer::plain( (string) ( $attributes['title'] ?? '' ) );
		$name = '' !== $name ? $name : Replacer::plain( get_the_title( $post ) );

		$piece = array(
			'@type'            => 'HowTo',
			'@id'              => $url . '#howto' . ( $index ? '-' . ( $index + 1 ) : '' ),
			'name'             => $name,
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
		);

		$description = Replacer::plain( (string) ( $attributes['description'] ?? '' ) );
		if ( '' !== $description ) {
			$piece['description'] = $description;
		}

		$minutes = (int) ( $attributes['totalTime'] ?? 0 );
		if ( $minutes > 0 ) {
			$piece['totalTime'] = 'PT' . ( intdiv( $minutes, 60 ) ? intdiv( $minutes, 60 ) . 'H' : '' ) . ( $minutes % 60 ? ( $minutes % 60 ) . 'M' : '' );
		}

		$cost = trim( wp_strip_all_tags( (string) ( $attributes['cost'] ?? '' ) ) );
		if ( '' !== $cost ) {
			$piece['estimatedCost'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => strtoupper( trim( wp_strip_all_tags( (string) ( $attributes['currency'] ?? '' ) ) ) ),
				'value'    => $cost,
			);
		}

		foreach ( array(
			'supply' => array( 'supplies', 'HowToSupply' ),
			'tool'   => array( 'tools', 'HowToTool' ),
		) as $property => $source ) {
			$lines = Renderer::lines( $attributes[ $source[0] ] ?? '' );
			foreach ( $lines as $line ) {
				$piece[ $property ][] = array(
					'@type' => $source[1],
					'name'  => $line,
				);
			}
		}

		foreach ( $steps as $number => $step ) {
			$name = Replacer::plain( $step['title'] );
			$text = Replacer::plain( $step['text'] );

			$item = array(
				'@type'    => 'HowToStep',
				'position' => $number + 1,
				'url'      => $url . '#' . Renderer::step_id( $steps, $number ),
				'name'     => '' !== $name ? $name : wp_trim_words( $text, 8, '…' ),
				'text'     => '' !== $text ? $text : $name,
			);

			if ( $step['image_id'] ) {
				$image = wp_get_attachment_image_url( $step['image_id'], 'full' );
				if ( $image ) {
					$item['image'] = $image;
				}
			}

			$piece['step'][] = $item;
		}

		return $piece;
	}
}
