<?php
/**
 * Page builder integration.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Gives the content analysis the text of pages made with a page builder.
 *
 * The analysis reads the classic or block editor, but builders keep the
 * real layout elsewhere: Elementor, Beaver Builder and Bricks in post meta,
 * Divi and Oxygen as shortcodes. When the edit screen opens for such a
 * post, this pulls the text (with headings, links and images, which the
 * checks look at) out of the builder's own data and hands it to the editor
 * script as `tagwrightSeoEditor.builderContent`.
 *
 * Nothing is rendered through the builder itself: running a builder's
 * front end inside wp-admin can enqueue assets, fire hooks and break the
 * edit screen. Reading the stored data is side-effect free and fast.
 */
class Page_Builders {

	/**
	 * Most characters handed to the browser.
	 */
	const MAX_LENGTH = 300000;

	/**
	 * Setting keys builders use for text, in rough reading order.
	 *
	 * @var string[]
	 */
	const TEXT_KEYS = array(
		'title',
		'title_text',
		'heading',
		'editor',
		'text',
		'content',
		'description',
		'description_text',
		'tab_title',
		'tab_content',
		'item_description',
		'testimonial_content',
		'alert_title',
		'alert_description',
		'html',
		'caption',
		'button_text',
	);

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		// After the core editor script (priority 10) has added tagwrightSeoEditor.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Adds the builder content to the editor data.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook || ! wp_script_is( 'tagwright-seo-editor', 'enqueued' ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$content = self::content_for( $post );
		if ( '' === $content ) {
			return;
		}

		wp_add_inline_script(
			'tagwright-seo-editor',
			'window.tagwrightSeoEditor = window.tagwrightSeoEditor || {}; window.tagwrightSeoEditor.builderContent = ' . wp_json_encode( $content ) . ';',
			'before'
		);
	}

	/**
	 * Which builder made a post, if any.
	 *
	 * @param \WP_Post $post Post.
	 * @return string Builder id, or an empty string.
	 */
	public static function builder_for( $post ) {
		$active = Detect::builders();

		if ( isset( $active['elementor'] ) && 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return 'elementor';
		}

		if ( isset( $active['beaver'] ) && get_post_meta( $post->ID, '_fl_builder_enabled', true ) ) {
			return 'beaver';
		}

		if ( isset( $active['bricks'] ) && 'bricks' === get_post_meta( $post->ID, '_bricks_editor_mode', true ) ) {
			return 'bricks';
		}

		if ( isset( $active['divi'] ) && 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) ) {
			return 'divi';
		}

		if ( isset( $active['oxygen'] ) && ( get_post_meta( $post->ID, 'ct_builder_shortcodes', true ) || get_post_meta( $post->ID, '_ct_builder_shortcodes', true ) ) ) {
			return 'oxygen';
		}

		return '';
	}

	/**
	 * The builder content of a post as simple HTML.
	 *
	 * @param \WP_Post $post Post.
	 * @return string Empty when the post is not built with a builder.
	 */
	public static function content_for( $post ) {
		switch ( self::builder_for( $post ) ) {
			case 'elementor':
				$data = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
				$html = is_array( $data ) ? self::from_elementor( $data ) : '';
				break;

			case 'beaver':
				$html = self::from_beaver( get_post_meta( $post->ID, '_fl_builder_data', true ) );
				break;

			case 'bricks':
				$html = self::from_bricks( get_post_meta( $post->ID, '_bricks_page_content_2', true ) );
				break;

			case 'divi':
				$html = self::strip_builder_shortcodes( $post->post_content, 'et_pb_' );
				break;

			case 'oxygen':
				$shortcodes = (string) get_post_meta( $post->ID, '_ct_builder_shortcodes', true );
				if ( '' === $shortcodes ) {
					$shortcodes = (string) get_post_meta( $post->ID, 'ct_builder_shortcodes', true );
				}
				$shortcodes = preg_replace( '/\[ct_headline[^\]]*\]/', '<h2>', $shortcodes );
				$shortcodes = str_replace( '[/ct_headline]', '</h2>', (string) $shortcodes );
				$html       = self::strip_builder_shortcodes( $shortcodes, '(?:ct|oxy)_' );
				break;

			default:
				return '';
		}

		/**
		 * Filters the builder content handed to the content analysis.
		 *
		 * @param string   $html Extracted HTML.
		 * @param \WP_Post $post Post.
		 */
		$html = (string) apply_filters( 'tagwright_seo_builder_content', $html, $post );
		$html = trim( wp_kses_post( $html ) );

		return mb_substr( $html, 0, self::MAX_LENGTH );
	}

	/**
	 * Walks Elementor's element tree.
	 *
	 * @param array $elements Elements.
	 * @param int   $depth    Nesting depth.
	 * @return string
	 */
	public static function from_elementor( array $elements, $depth = 0 ) {
		$html = '';

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$widget   = (string) ( $element['widgetType'] ?? '' );

			if ( 'heading' === $widget && isset( $settings['title'] ) ) {
				$tag   = self::heading_tag( $settings['header_size'] ?? 'h2' );
				$html .= '<' . $tag . '>' . wp_strip_all_tags( (string) $settings['title'] ) . '</' . $tag . '>';
			} elseif ( 'image' === $widget && ! empty( $settings['image']['url'] ) ) {
				$html .= self::image( (string) $settings['image']['url'], (int) ( $settings['image']['id'] ?? 0 ), (string) ( $settings['image']['alt'] ?? '' ) );
			} elseif ( $settings ) {
				$html .= self::texts( $settings );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $depth < 30 ) {
				$html .= self::from_elementor( $element['elements'], $depth + 1 );
			}
		}

		return $html;
	}

	/**
	 * Walks Beaver Builder's nodes in layout order.
	 *
	 * @param mixed $nodes Nodes keyed by id, each with parent, position and settings.
	 * @return string
	 */
	public static function from_beaver( $nodes ) {
		if ( ! is_array( $nodes ) ) {
			return '';
		}

		$children = array();
		foreach ( $nodes as $id => $node ) {
			$node   = (object) $node;
			$parent = isset( $node->parent ) ? (string) $node->parent : '';

			$children[ $parent ][] = array(
				'id'       => (string) ( $node->node ?? $id ),
				'position' => (int) ( $node->position ?? 0 ),
				'node'     => $node,
			);
		}

		foreach ( $children as &$list ) {
			usort( $list, static fn( $a, $b ) => $a['position'] <=> $b['position'] );
		}
		unset( $list );

		$walk = static function ( $parent, $depth ) use ( &$walk, $children ) {
			$html = '';

			foreach ( $children[ $parent ] ?? array() as $child ) {
				$settings = isset( $child['node']->settings ) ? (array) $child['node']->settings : array();
				$type     = (string) ( $settings['type'] ?? '' );

				if ( 'heading' === $type && isset( $settings['heading'] ) ) {
					$tag   = self::heading_tag( $settings['tag'] ?? 'h2' );
					$html .= '<' . $tag . '>' . wp_strip_all_tags( (string) $settings['heading'] ) . '</' . $tag . '>';
				} elseif ( 'photo' === $type && ! empty( $settings['photo_src'] ) ) {
					$html .= self::image( (string) $settings['photo_src'], (int) ( $settings['photo'] ?? 0 ), '' );
				} elseif ( $settings ) {
					$html .= self::texts( $settings );
				}

				if ( $depth < 30 ) {
					$html .= $walk( $child['id'], $depth + 1 );
				}
			}

			return $html;
		};

		return $walk( '', 0 );
	}

	/**
	 * Walks Bricks elements from the top level down.
	 *
	 * @param mixed $elements Flat list of elements with id, parent, children and settings.
	 * @return string
	 */
	public static function from_bricks( $elements ) {
		if ( ! is_array( $elements ) ) {
			return '';
		}

		$by_id = array();
		$roots = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				continue;
			}
			$by_id[ (string) $element['id'] ] = $element;
			if ( empty( $element['parent'] ) ) {
				$roots[] = (string) $element['id'];
			}
		}

		$walk = static function ( array $ids, $depth ) use ( &$walk, $by_id ) {
			$html = '';

			foreach ( $ids as $id ) {
				if ( ! isset( $by_id[ $id ] ) ) {
					continue;
				}

				$element  = $by_id[ $id ];
				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				$name     = (string) ( $element['name'] ?? '' );

				if ( 'heading' === $name && isset( $settings['text'] ) ) {
					$tag   = self::heading_tag( $settings['tag'] ?? 'h3' );
					$html .= '<' . $tag . '>' . wp_strip_all_tags( (string) $settings['text'] ) . '</' . $tag . '>';
				} elseif ( 'image' === $name && ! empty( $settings['image']['url'] ) ) {
					$html .= self::image( (string) $settings['image']['url'], (int) ( $settings['image']['id'] ?? 0 ), (string) ( $settings['altText'] ?? '' ) );
				} elseif ( $settings ) {
					$html .= self::texts( $settings );
				}

				if ( ! empty( $element['children'] ) && is_array( $element['children'] ) && $depth < 30 ) {
					$html .= $walk( array_map( 'strval', $element['children'] ), $depth + 1 );
				}
			}

			return $html;
		};

		return $walk( $roots, 0 );
	}

	/**
	 * Removes a builder's shortcode tags and keeps what is between them.
	 *
	 * @param string $content Content.
	 * @param string $prefix  Shortcode prefix as a regex fragment, for example `et_pb_`.
	 * @return string
	 */
	public static function strip_builder_shortcodes( $content, $prefix ) {
		return (string) preg_replace( '/\[\/?' . $prefix . '[^\]]*\]/', "\n", (string) $content );
	}

	/**
	 * Collects the known text settings of one element, nested lists included
	 * (tabs, accordions, icon lists).
	 *
	 * @param array $settings Settings.
	 * @param int   $depth    Nesting depth.
	 * @return string
	 */
	private static function texts( array $settings, $depth = 0 ) {
		$html = '';

		foreach ( self::TEXT_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				$value = $settings[ $key ];
				$html .= ( false === strpos( $value, '<' ) ) ? '<p>' . $value . '</p>' : $value;
			}
		}

		if ( $depth < 3 ) {
			foreach ( $settings as $value ) {
				if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
					foreach ( $value as $item ) {
						if ( is_array( $item ) ) {
							$html .= self::texts( $item, $depth + 1 );
						}
					}
				}
			}
		}

		return $html;
	}

	/**
	 * A safe heading tag name.
	 *
	 * @param mixed $tag Tag from the builder.
	 * @return string
	 */
	private static function heading_tag( $tag ) {
		$tag = strtolower( (string) $tag );

		return in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $tag : 'p';
	}

	/**
	 * An image tag with the attachment's alt text when the builder has none.
	 *
	 * @param string $url Image URL.
	 * @param int    $id  Attachment id.
	 * @param string $alt Alt text from the builder.
	 * @return string
	 */
	private static function image( $url, $id, $alt ) {
		if ( '' === trim( $alt ) && $id ) {
			$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		}

		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" />';
	}
}
