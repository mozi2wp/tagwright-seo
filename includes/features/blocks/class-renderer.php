<?php
/**
 * Front end markup for the Content Blocks.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Blocks;

use TagwrightSEO\Frontend\Breadcrumb_Trail;
use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the HTML for each block and shortcode.
 *
 * Blocks and shortcodes share one renderer so a page builder user and a
 * block editor user get exactly the same markup.
 */
class Renderer {

	/**
	 * Loads the front end stylesheet. Called from render callbacks, so the
	 * file is only requested on pages that actually show one of the blocks.
	 *
	 * @return void
	 */
	public static function enqueue_style() {
		wp_enqueue_style( Feature::STYLE_HANDLE );
	}

	/**
	 * Attributes for a block's outer element.
	 *
	 * Inside a block render, get_block_wrapper_attributes() adds the custom
	 * class name and alignment from the editor. Shortcodes and the theme
	 * function have no block, so they get a plain class attribute.
	 *
	 * @param string $base_class Base class.
	 * @param bool   $is_block   Whether a block is being rendered.
	 * @param array  $extra      Extra attributes.
	 * @return string Escaped attribute string.
	 */
	public static function wrapper( $base_class, $is_block, array $extra = array() ) {
		$attributes = array_merge( array( 'class' => $base_class ), $extra );

		if ( $is_block && function_exists( 'get_block_wrapper_attributes' ) ) {
			return get_block_wrapper_attributes( $attributes );
		}

		$parts = array();
		foreach ( $attributes as $name => $value ) {
			$parts[] = sprintf( '%s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Reads a yes/no value from a shortcode attribute.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Whether this request is the editor asking for a block preview.
	 *
	 * @return bool
	 */
	private static function is_preview() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/* ------------------------------------------------------------------
	 * Breadcrumbs
	 * --------------------------------------------------------------- */

	/**
	 * Breadcrumb markup.
	 *
	 * @param array $args     Overrides: separator, home, prefix, show_current, link_current.
	 *                        Empty strings fall back to the settings.
	 * @param bool  $is_block Whether a block is being rendered.
	 * @return string
	 */
	public static function breadcrumbs( array $args = array(), $is_block = false ) {
		$args = array_filter(
			$args,
			static fn( $value ) => null !== $value && '' !== $value
		);

		$args = array_merge(
			array(
				'separator'    => (string) Options::get( 'blocks_bc_separator', '›' ),
				'home'         => (string) Options::get( 'blocks_bc_home_label', '' ),
				'prefix'       => (string) Options::get( 'blocks_bc_prefix', '' ),
				'show_current' => (bool) Options::get( 'blocks_bc_show_current', true ),
				'link_current' => (bool) Options::get( 'blocks_bc_link_current', false ),
			),
			$args
		);

		$crumbs = self::trail();

		// A trail that is only "Home" adds nothing, so the front page shows none.
		if ( count( $crumbs ) < 2 ) {
			return '';
		}

		if ( '' !== trim( (string) $args['home'] ) ) {
			$crumbs[0]['text'] = $args['home'];
		}

		$show_current = self::to_bool( $args['show_current'] );
		$link_current = self::to_bool( $args['link_current'] );

		if ( ! $show_current ) {
			array_pop( $crumbs );
		}

		$separator = '' !== trim( (string) $args['separator'] ) ? trim( (string) $args['separator'] ) : '›';
		$last      = count( $crumbs ) - 1;
		$items     = '';

		foreach ( $crumbs as $index => $crumb ) {
			$text       = esc_html( wp_strip_all_tags( (string) $crumb['text'] ) );
			$url        = (string) ( $crumb['url'] ?? '' );
			$is_current = $show_current && $index === $last;

			if ( $is_current ) {
				$item = $link_current && '' !== $url
					? sprintf( '<a href="%1$s" aria-current="page">%2$s</a>', esc_url( $url ), $text )
					: sprintf( '<span aria-current="page">%s</span>', $text );
			} else {
				$item = '' !== $url ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), $text ) : '<span>' . $text . '</span>';
			}

			if ( $index < $last ) {
				$item .= '<span class="tagwright-breadcrumbs__sep" aria-hidden="true">' . esc_html( $separator ) . '</span>';
			}

			$items .= '<li class="tagwright-breadcrumbs__item">' . $item . '</li>';
		}

		self::enqueue_style();

		$prefix = trim( (string) $args['prefix'] );

		return sprintf(
			'<nav %1$s>%2$s<ol class="tagwright-breadcrumbs__list">%3$s</ol></nav>',
			self::wrapper( 'tagwright-breadcrumbs', $is_block, array( 'aria-label' => __( 'Breadcrumbs', 'tagwright-seo' ) ) ),
			'' !== $prefix ? '<span class="tagwright-breadcrumbs__prefix">' . esc_html( $prefix ) . '</span> ' : '',
			$items
		);
	}

	/**
	 * The trail to show. Editor previews have no front end query, so they
	 * use the post being edited, or a sample when it isn't saved yet.
	 *
	 * @return array[]
	 */
	private static function trail() {
		if ( ! self::is_preview() ) {
			return tagwright_seo_get_breadcrumbs();
		}

		$post = get_post();
		if ( $post && 'auto-draft' !== $post->post_status ) {
			return Breadcrumb_Trail::build( Context::for_post( $post ) );
		}

		return array(
			array(
				'text' => __( 'Home', 'tagwright-seo' ),
				'url'  => home_url( '/' ),
			),
			array(
				'text' => __( 'Category', 'tagwright-seo' ),
				'url'  => home_url( '/' ),
			),
			array(
				'text' => __( 'This page', 'tagwright-seo' ),
				'url'  => home_url( '/' ),
			),
		);
	}

	/**
	 * Breadcrumbs block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function breadcrumbs_block( $attributes ) {
		return self::breadcrumbs(
			array(
				'separator' => (string) ( $attributes['separator'] ?? '' ),
				'prefix'    => (string) ( $attributes['prefix'] ?? '' ),
			),
			true
		);
	}

	/**
	 * [tagwright_breadcrumbs] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function breadcrumbs_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'separator'    => '',
				'prefix'       => '',
				'home'         => '',
				'show_current' => '',
				'link_current' => '',
			),
			$atts,
			'tagwright_breadcrumbs'
		);

		return self::breadcrumbs( $atts );
	}

	/* ------------------------------------------------------------------
	 * Table of contents
	 * --------------------------------------------------------------- */

	/**
	 * Table of contents block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function toc_block( $attributes ) {
		return Toc::placeholder(
			array(
				'title'       => (string) ( $attributes['title'] ?? '' ),
				'levels'      => (array) ( $attributes['levels'] ?? array( 2, 3 ) ),
				'list_style'  => (string) ( $attributes['listStyle'] ?? 'ul' ),
				'collapsible' => ! empty( $attributes['collapsible'] ),
				'wrapper'     => self::wrapper( 'tagwright-toc', true ),
			)
		);
	}

	/**
	 * [tagwright_toc] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function toc_shortcode( $atts ) {
		$title = (string) Options::get( 'blocks_toc_title', '' );

		$atts = shortcode_atts(
			array(
				'title'       => '' !== $title ? $title : __( 'Table of contents', 'tagwright-seo' ),
				'levels'      => implode( ',', (array) Options::get( 'blocks_toc_levels', array( 'h2', 'h3' ) ) ),
				'style'       => (string) Options::get( 'blocks_toc_list_style', 'ul' ),
				'collapsible' => Options::get( 'blocks_toc_collapsible' ) ? 'yes' : 'no',
			),
			$atts,
			'tagwright_toc'
		);

		// Accepts "2,3", "h2,h3" or "2 3".
		$levels = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) $atts['levels'] ) ) as $level ) {
			$levels[] = (int) ltrim( $level, 'h' );
		}

		return Toc::placeholder(
			array(
				'title'       => (string) $atts['title'],
				'levels'      => $levels,
				'list_style'  => (string) $atts['style'],
				'collapsible' => self::to_bool( $atts['collapsible'] ),
				'wrapper'     => self::wrapper( 'tagwright-toc', false ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * FAQ
	 * --------------------------------------------------------------- */

	/**
	 * Tags allowed inside questions, answers and step text. These are
	 * inline formats the RichText fields produce, and also the subset
	 * Google accepts inside FAQ answers.
	 *
	 * @return array
	 */
	public static function inline_tags() {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'code'   => array(),
			'sub'    => array(),
			'sup'    => array(),
		);
	}

	/**
	 * FAQ items with both a question and an answer.
	 *
	 * @param mixed $items Raw items attribute.
	 * @return array[] List of array( 'question' => html, 'answer' => html ).
	 */
	public static function faq_items( $items ) {
		$clean = array();

		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = trim( wp_kses( (string) ( $item['question'] ?? '' ), self::inline_tags() ) );
			$answer   = trim( wp_kses( (string) ( $item['answer'] ?? '' ), self::inline_tags() ) );

			if ( '' === wp_strip_all_tags( $question ) || '' === wp_strip_all_tags( $answer ) ) {
				continue;
			}

			$clean[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		return $clean;
	}

	/**
	 * FAQ block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function faq_block( $attributes ) {
		$items = self::faq_items( $attributes['items'] ?? array() );
		if ( ! $items ) {
			return '';
		}

		$collapsible = ! empty( $attributes['collapsible'] );
		$html        = '';

		foreach ( $items as $item ) {
			// Both values went through wp_kses() in faq_items().
			if ( $collapsible ) {
				$html .= '<details class="tagwright-faq__item"><summary class="tagwright-faq__question">' . $item['question'] . '</summary><div class="tagwright-faq__answer"><p>' . $item['answer'] . '</p></div></details>';
			} else {
				$html .= '<div class="tagwright-faq__item"><h3 class="tagwright-faq__question">' . $item['question'] . '</h3><div class="tagwright-faq__answer"><p>' . $item['answer'] . '</p></div></div>';
			}
		}

		self::enqueue_style();

		return '<div ' . self::wrapper( 'tagwright-faq', true ) . '>' . $html . '</div>';
	}

	/* ------------------------------------------------------------------
	 * HowTo
	 * --------------------------------------------------------------- */

	/**
	 * Steps with a title or text.
	 *
	 * @param mixed $steps Raw steps attribute.
	 * @return array[] List of array( 'title', 'text', 'image_id' ).
	 */
	public static function howto_steps( $steps ) {
		$clean = array();

		foreach ( (array) $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$title = trim( wp_kses( (string) ( $step['title'] ?? '' ), self::inline_tags() ) );
			$text  = trim( wp_kses( (string) ( $step['text'] ?? '' ), self::inline_tags() ) );

			if ( '' === wp_strip_all_tags( $title ) && '' === wp_strip_all_tags( $text ) ) {
				continue;
			}

			$clean[] = array(
				'title'    => $title,
				'text'     => $text,
				'image_id' => absint( $step['imageId'] ?? 0 ),
			);
		}

		return $clean;
	}

	/**
	 * Anchor id for a step. Built from the steps themselves, so the visible
	 * block and the schema agree without having to count blocks, even when
	 * a page has several HowTo blocks or the content is rendered twice.
	 *
	 * @param array[] $steps Clean steps.
	 * @param int     $index Zero based step index.
	 * @return string
	 */
	public static function step_id( array $steps, $index ) {
		return 'howto-' . substr( md5( (string) wp_json_encode( $steps ) ), 0, 6 ) . '-step-' . ( $index + 1 );
	}

	/**
	 * Lines of a one-item-per-line text field.
	 *
	 * @param string $text Raw text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$lines = preg_split( '/\R/u', (string) $text );

		return array_values( array_filter( array_map( 'trim', array_map( 'wp_strip_all_tags', (array) $lines ) ), 'strlen' ) );
	}

	/**
	 * Minutes as readable text, for example "1 hour 30 minutes".
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	public static function duration_text( $minutes ) {
		$minutes = max( 0, (int) $minutes );
		$hours   = intdiv( $minutes, 60 );
		$rest    = $minutes % 60;
		$parts   = array();

		if ( $hours ) {
			/* translators: %s: number of hours. */
			$parts[] = sprintf( _n( '%s hour', '%s hours', $hours, 'tagwright-seo' ), number_format_i18n( $hours ) );
		}
		if ( $rest ) {
			/* translators: %s: number of minutes. */
			$parts[] = sprintf( _n( '%s minute', '%s minutes', $rest, 'tagwright-seo' ), number_format_i18n( $rest ) );
		}

		return implode( ' ', $parts );
	}

	/**
	 * HowTo block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function howto_block( $attributes ) {
		$steps = self::howto_steps( $attributes['steps'] ?? array() );
		if ( ! $steps ) {
			return '';
		}

		$html        = '';
		$description = trim( wp_kses( (string) ( $attributes['description'] ?? '' ), self::inline_tags() ) );

		if ( '' !== $description ) {
			$html .= '<p class="tagwright-howto__description">' . $description . '</p>';
		}

		$meta = array();
		$time = self::duration_text( $attributes['totalTime'] ?? 0 );
		if ( '' !== $time ) {
			$meta[] = '<span class="tagwright-howto__time"><strong>' . esc_html__( 'Total time:', 'tagwright-seo' ) . '</strong> ' . esc_html( $time ) . '</span>';
		}

		$cost = trim( wp_strip_all_tags( (string) ( $attributes['cost'] ?? '' ) ) );
		if ( '' !== $cost ) {
			$cost  .= ' ' . strtoupper( trim( wp_strip_all_tags( (string) ( $attributes['currency'] ?? '' ) ) ) );
			$meta[] = '<span class="tagwright-howto__cost"><strong>' . esc_html__( 'Estimated cost:', 'tagwright-seo' ) . '</strong> ' . esc_html( trim( $cost ) ) . '</span>';
		}

		if ( $meta ) {
			$html .= '<p class="tagwright-howto__meta">' . implode( ' ', $meta ) . '</p>';
		}

		$lists = array(
			'supplies' => __( 'You will need', 'tagwright-seo' ),
			'tools'    => __( 'Tools', 'tagwright-seo' ),
		);
		foreach ( $lists as $key => $label ) {
			$lines = self::lines( $attributes[ $key ] ?? '' );
			if ( $lines ) {
				$html .= '<div class="tagwright-howto__list tagwright-howto__' . esc_attr( $key ) . '"><p class="tagwright-howto__label"><strong>' . esc_html( $label ) . '</strong></p><ul>';
				foreach ( $lines as $line ) {
					$html .= '<li>' . esc_html( $line ) . '</li>';
				}
				$html .= '</ul></div>';
			}
		}

		$html .= '<ol class="tagwright-howto__steps">';
		foreach ( $steps as $index => $step ) {
			$html .= '<li class="tagwright-howto__step" id="' . esc_attr( self::step_id( $steps, $index ) ) . '">';
			if ( '' !== $step['title'] ) {
				$html .= '<h3 class="tagwright-howto__step-title">' . $step['title'] . '</h3>';
			}
			if ( '' !== $step['text'] ) {
				$html .= '<p class="tagwright-howto__step-text">' . $step['text'] . '</p>';
			}
			if ( $step['image_id'] ) {
				$html .= wp_get_attachment_image( $step['image_id'], 'large', false, array( 'class' => 'tagwright-howto__image' ) );
			}
			$html .= '</li>';
		}
		$html .= '</ol>';

		self::enqueue_style();

		return '<div ' . self::wrapper( 'tagwright-howto', true ) . '>' . $html . '</div>';
	}

	/* ------------------------------------------------------------------
	 * Reading time
	 * --------------------------------------------------------------- */

	/**
	 * Estimated reading time of a post in minutes, at least 1.
	 *
	 * Text in Chinese, Japanese and Korean has no spaces between words, so
	 * those characters are counted separately: roughly two of them read in
	 * the time of one English word.
	 *
	 * @param \WP_Post $post Post.
	 * @return int
	 */
	public static function minutes( $post ) {
		static $cache = array();

		$key = $post->ID . ':' . $post->post_modified;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$cjk  = '[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]';

		$characters = (int) preg_match_all( '/' . $cjk . '/u', $text );
		$text       = (string) preg_replace( '/' . $cjk . '/u', ' ', $text );
		$words      = (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text );

		$wpm = max( 50, (int) Options::get( 'blocks_reading_wpm', 200 ) );

		$cache[ $key ] = max( 1, (int) ceil( ( $words + $characters / 2 ) / $wpm ) );

		return $cache[ $key ];
	}

	/**
	 * Reading time markup.
	 *
	 * @param \WP_Post|int|null $post     Post, defaults to the current one.
	 * @param bool              $is_block Whether a block is being rendered.
	 * @return string
	 */
	public static function reading_time( $post = null, $is_block = false ) {
		$post = get_post( $post );
		if ( ! $post || post_password_required( $post ) ) {
			return '';
		}

		$minutes = self::minutes( $post );
		$prefix  = trim( (string) Options::get( 'blocks_reading_prefix', '' ) );
		$suffix  = trim( (string) Options::get( 'blocks_reading_suffix', '' ) );

		/* translators: %s: number of minutes. */
		$text = sprintf( _n( '%s min read', '%s min read', $minutes, 'tagwright-seo' ), number_format_i18n( $minutes ) );

		$inner = esc_html( $text );
		if ( '' !== $prefix ) {
			$inner = '<span class="tagwright-reading-time__prefix">' . esc_html( $prefix ) . '</span> ' . $inner;
		}
		if ( '' !== $suffix ) {
			$inner .= ' <span class="tagwright-reading-time__suffix">' . esc_html( $suffix ) . '</span>';
		}

		self::enqueue_style();

		// A paragraph as a block, inline when dropped into text as a shortcode.
		$tag = $is_block ? 'p' : 'span';

		return sprintf( '<%1$s %2$s>%3$s</%1$s>', $tag, self::wrapper( 'tagwright-reading-time', $is_block ), $inner );
	}

	/**
	 * Reading time block.
	 *
	 * @return string
	 */
	public static function reading_time_block() {
		return self::reading_time( null, true );
	}

	/**
	 * [tagwright_reading_time] shortcode. Takes an optional post id.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function reading_time_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'tagwright_reading_time' );
		$id   = absint( $atts['id'] );

		if ( $id && ! is_post_publicly_viewable( $id ) ) {
			return '';
		}

		return self::reading_time( $id ? $id : null );
	}

	/* ------------------------------------------------------------------
	 * Related posts
	 * --------------------------------------------------------------- */

	/**
	 * Related posts block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function related_block( $attributes ) {
		return Related::render(
			get_post(),
			array(
				'title'      => (string) ( $attributes['title'] ?? '' ),
				'count'      => (int) ( $attributes['count'] ?? 4 ),
				'show_image' => ! empty( $attributes['showImage'] ),
				'wrapper'    => self::wrapper( 'tagwright-related', true ),
			)
		);
	}

	/**
	 * [tagwright_related] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function related_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'count' => 4,
				'title' => __( 'Related posts', 'tagwright-seo' ),
				'image' => 'yes',
			),
			$atts,
			'tagwright_related'
		);

		return Related::render(
			get_post(),
			array(
				'title'      => (string) $atts['title'],
				'count'      => (int) $atts['count'],
				'show_image' => self::to_bool( $atts['image'] ),
				'wrapper'    => self::wrapper( 'tagwright-related', false ),
			)
		);
	}
}
