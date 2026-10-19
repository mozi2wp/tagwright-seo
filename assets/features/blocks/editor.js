/**
 * Tagwright SEO Content Blocks: block editor side.
 *
 * Plain JavaScript, no build step. Attributes, titles and descriptions come
 * from the server registration, so this file only adds icons and the edit
 * interfaces. Every block saves nothing but its attributes; the markup is
 * rendered by PHP.
 */
( function ( wp, window ) {
	'use strict';

	const { registerBlockType, updateCategory } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, RichText, useBlockProps, MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { PanelBody, TextControl, TextareaControl, ToggleControl, SelectControl, CheckboxControl, RangeControl, Button, Placeholder, Disabled } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __, sprintf } = wp.i18n;
	const { useSelect } = wp.data;

	const icons = ( window.tagwrightSeoBlocks && window.tagwrightSeoBlocks.icons ) || {};
	const INLINE_FORMATS = [ 'core/bold', 'core/italic', 'core/link', 'core/code' ];

	/**
	 * Draws one of the core Tagwright SEO icons. The paths come from PHP, the
	 * same ones the admin screens use.
	 *
	 * @param {string} name Icon name.
	 * @return {Object} Element.
	 */
	function icon( name ) {
		return el( 'svg', {
			width: 24,
			height: 24,
			viewBox: '0 0 24 24',
			fill: 'none',
			stroke: 'currentColor',
			strokeWidth: 1.8,
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			'aria-hidden': true,
			focusable: false,
			dangerouslySetInnerHTML: { __html: icons[ name ] || icons.logo || '' },
		} );
	}

	if ( updateCategory ) {
		updateCategory( 'tagwright-seo', { icon: icon( 'logo' ) } );
	}

	/**
	 * Plain text of a RichText value. DOMParser builds an inert document,
	 * so nothing in the markup runs or loads.
	 *
	 * @param {*} value String or RichTextData.
	 * @return {string} Text.
	 */
	function toText( value ) {
		const html = value == null ? '' : String( value );
		if ( ! html ) {
			return '';
		}
		const doc = new window.DOMParser().parseFromString( html, 'text/html' );
		return ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	/**
	 * Server preview, wrapped so links inside can't navigate away.
	 *
	 * @param {string} name       Block name.
	 * @param {Object} attributes Attributes.
	 * @param {string} emptyText  Shown when the server returns nothing.
	 * @return {Object} Element.
	 */
	function preview( name, attributes, emptyText ) {
		return el( Disabled, null, el( ServerSideRender, {
			block: name,
			attributes,
			EmptyResponsePlaceholder: () => el( Placeholder, { icon: icon( 'logo' ), label: __( 'Tagwright SEO', 'tagwright-seo' ), instructions: emptyText } ),
		} ) );
	}

	/**
	 * Updates one field of one item in a list attribute.
	 *
	 * @param {Array}  list  Items.
	 * @param {number} index Item index.
	 * @param {string} key   Field.
	 * @param {*}      value New value.
	 * @return {Array} New list.
	 */
	function updateItem( list, index, key, value ) {
		return list.map( ( item, i ) => ( i === index ? Object.assign( {}, item, { [ key ]: value } ) : item ) );
	}

	/**
	 * Moves an item up or down.
	 *
	 * @param {Array}  list  Items.
	 * @param {number} index Item index.
	 * @param {number} by    -1 or 1.
	 * @return {Array} New list.
	 */
	function moveItem( list, index, by ) {
		const target = index + by;
		if ( target < 0 || target >= list.length ) {
			return list;
		}
		const next = list.slice();
		next.splice( target, 0, next.splice( index, 1 )[ 0 ] );
		return next;
	}

	/**
	 * Move up, move down and remove buttons for a list item.
	 *
	 * @param {Array}    list     Items.
	 * @param {number}   index    Item index.
	 * @param {Function} onChange Receives the new list.
	 * @return {Object} Element.
	 */
	function itemActions( list, index, onChange ) {
		return el( 'div', { className: 'tagwright-seo-block-actions' },
			el( Button, { size: 'small', icon: 'arrow-up-alt2', label: __( 'Move up', 'tagwright-seo' ), disabled: 0 === index, onClick: () => onChange( moveItem( list, index, -1 ) ) } ),
			el( Button, { size: 'small', icon: 'arrow-down-alt2', label: __( 'Move down', 'tagwright-seo' ), disabled: index === list.length - 1, onClick: () => onChange( moveItem( list, index, 1 ) ) } ),
			el( Button, { size: 'small', icon: 'trash', label: __( 'Remove', 'tagwright-seo' ), isDestructive: true, onClick: () => onChange( list.filter( ( item, i ) => i !== index ) ) } )
		);
	}

	/* ------------------------------------------------------------------
	 * Breadcrumbs
	 * --------------------------------------------------------------- */

	registerBlockType( 'tagwright-seo/breadcrumbs', {
		icon: icon( 'arrow-right' ),
		edit( { attributes, setAttributes } ) {
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Breadcrumbs', 'tagwright-seo' ) },
						el( TextControl, {
							label: __( 'Separator', 'tagwright-seo' ),
							help: __( 'Leave empty to use the one from the Tagwright SEO settings.', 'tagwright-seo' ),
							value: attributes.separator,
							onChange: ( separator ) => setAttributes( { separator } ),
						} ),
						el( TextControl, {
							label: __( 'Text before the trail', 'tagwright-seo' ),
							help: __( 'Leave empty to use the one from the Tagwright SEO settings.', 'tagwright-seo' ),
							value: attributes.prefix,
							onChange: ( prefix ) => setAttributes( { prefix } ),
						} )
					)
				),
				el( 'div', useBlockProps(), preview( 'tagwright-seo/breadcrumbs', attributes, __( 'Breadcrumbs appear here on every page except the front page.', 'tagwright-seo' ) ) )
			);
		},
		save: () => null,
	} );

	/* ------------------------------------------------------------------
	 * Table of contents
	 * --------------------------------------------------------------- */

	/**
	 * Headings in the editor, in document order, including nested blocks.
	 *
	 * @param {Array} blocks Blocks.
	 * @param {Array} out    Collected headings.
	 * @return {Array} Headings.
	 */
	function collectHeadings( blocks, out ) {
		blocks.forEach( ( block ) => {
			if ( 'core/heading' === block.name ) {
				const text = toText( block.attributes.content );
				if ( text ) {
					out.push( { level: block.attributes.level || 2, text } );
				}
			}
			if ( block.innerBlocks && block.innerBlocks.length ) {
				collectHeadings( block.innerBlocks, out );
			}
		} );
		return out;
	}

	registerBlockType( 'tagwright-seo/toc', {
		icon: icon( 'book' ),
		edit( { attributes, setAttributes } ) {
			const levels = attributes.levels || [];
			const blocks = useSelect( ( select ) => select( 'core/block-editor' ).getBlocks(), [] );
			const headings = collectHeadings( blocks, [] ).filter( ( heading ) => levels.indexOf( heading.level ) !== -1 );
			const top = headings.reduce( ( min, heading ) => Math.min( min, heading.level ), 6 );
			const ListTag = 'ol' === attributes.listStyle ? 'ol' : 'ul';

			const list = headings.length
				? el( ListTag, { className: 'tagwright-toc__list' }, headings.map( ( heading, index ) => el( 'li', {
					key: index,
					style: { marginLeft: ( heading.level - top ) * 1.25 + 'em' },
				}, heading.text ) ) )
				: el( 'p', { className: 'tagwright-toc__empty' }, __( 'Add headings to this post and they will be listed here.', 'tagwright-seo' ) );

			const toggleLevel = ( level, checked ) => {
				const next = checked ? levels.concat( [ level ] ) : levels.filter( ( value ) => value !== level );
				setAttributes( { levels: next.sort() } );
			};

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Table of contents', 'tagwright-seo' ) },
						el( TextControl, {
							label: __( 'Title', 'tagwright-seo' ),
							value: attributes.title,
							onChange: ( title ) => setAttributes( { title } ),
						} ),
						el( 'p', { className: 'components-base-control__label' }, __( 'Headings to include', 'tagwright-seo' ) ),
						[ 2, 3, 4, 5, 6 ].map( ( level ) => el( CheckboxControl, {
							key: level,
							label: 'H' + level,
							checked: levels.indexOf( level ) !== -1,
							onChange: ( checked ) => toggleLevel( level, checked ),
						} ) ),
						el( SelectControl, {
							label: __( 'List style', 'tagwright-seo' ),
							value: attributes.listStyle,
							options: [
								{ value: 'ul', label: __( 'Bullets', 'tagwright-seo' ) },
								{ value: 'ol', label: __( 'Numbers', 'tagwright-seo' ) },
							],
							onChange: ( listStyle ) => setAttributes( { listStyle } ),
						} ),
						el( ToggleControl, {
							label: __( 'Collapsible', 'tagwright-seo' ),
							checked: !! attributes.collapsible,
							onChange: ( collapsible ) => setAttributes( { collapsible } ),
						} )
					)
				),
				el( 'nav', useBlockProps( { className: 'tagwright-toc' } ),
					attributes.title ? el( 'p', { className: 'tagwright-toc__title' }, attributes.title ) : null,
					list
				)
			);
		},
		save: () => null,
	} );

	/* ------------------------------------------------------------------
	 * FAQ
	 * --------------------------------------------------------------- */

	registerBlockType( 'tagwright-seo/faq', {
		icon: icon( 'info' ),
		edit( { attributes, setAttributes } ) {
			const items = attributes.items || [];
			const setItems = ( next ) => setAttributes( { items: next } );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'FAQ', 'tagwright-seo' ) },
						el( ToggleControl, {
							label: __( 'Collapsible answers', 'tagwright-seo' ),
							help: __( 'Visitors click a question to see its answer.', 'tagwright-seo' ),
							checked: !! attributes.collapsible,
							onChange: ( collapsible ) => setAttributes( { collapsible } ),
						} )
					)
				),
				el( 'div', useBlockProps( { className: 'tagwright-faq' } ),
					items.map( ( item, index ) => el( 'div', { className: 'tagwright-faq__item', key: index },
						el( RichText, {
							tagName: 'h3',
							className: 'tagwright-faq__question',
							value: item.question,
							allowedFormats: INLINE_FORMATS,
							placeholder: __( 'Question', 'tagwright-seo' ),
							onChange: ( value ) => setItems( updateItem( items, index, 'question', value ) ),
						} ),
						el( RichText, {
							tagName: 'p',
							className: 'tagwright-faq__answer',
							value: item.answer,
							allowedFormats: INLINE_FORMATS,
							placeholder: __( 'Answer', 'tagwright-seo' ),
							onChange: ( value ) => setItems( updateItem( items, index, 'answer', value ) ),
						} ),
						itemActions( items, index, setItems )
					) ),
					el( Button, {
						variant: 'secondary',
						icon: 'plus-alt2',
						onClick: () => setItems( items.concat( [ { question: '', answer: '' } ] ) ),
					}, __( 'Add question', 'tagwright-seo' ) )
				)
			);
		},
		save: () => null,
	} );

	/* ------------------------------------------------------------------
	 * HowTo
	 * --------------------------------------------------------------- */

	/**
	 * Image picker for a step, with a thumbnail of the chosen image.
	 *
	 * @param {Object}   props          Props.
	 * @param {number}   props.id       Attachment id.
	 * @param {Function} props.onChange Receives the new id.
	 * @return {Object} Element.
	 */
	function StepImage( { id, onChange } ) {
		const media = useSelect( ( select ) => ( id ? select( 'core' ).getMedia( id ) : null ), [ id ] );
		const url = media && ( ( media.media_details && media.media_details.sizes && media.media_details.sizes.medium && media.media_details.sizes.medium.source_url ) || media.source_url );

		return el( MediaUploadCheck, null,
			el( 'div', { className: 'tagwright-howto__step-media' },
				url ? el( 'img', { src: url, alt: '', className: 'tagwright-howto__image' } ) : null,
				el( MediaUpload, {
					allowedTypes: [ 'image' ],
					value: id,
					onSelect: ( image ) => onChange( image.id ),
					render: ( { open } ) => el( Button, { variant: 'secondary', size: 'small', onClick: open }, id ? __( 'Replace image', 'tagwright-seo' ) : __( 'Add image', 'tagwright-seo' ) ),
				} ),
				id ? el( Button, { variant: 'link', isDestructive: true, size: 'small', onClick: () => onChange( 0 ) }, __( 'Remove image', 'tagwright-seo' ) ) : null
			)
		);
	}

	registerBlockType( 'tagwright-seo/howto', {
		icon: icon( 'layers' ),
		edit( { attributes, setAttributes } ) {
			const steps = attributes.steps || [];
			const setSteps = ( next ) => setAttributes( { steps: next } );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'HowTo details', 'tagwright-seo' ) },
						el( TextControl, {
							label: __( 'Name', 'tagwright-seo' ),
							help: __( 'Used in the schema. Leave empty to use the post title.', 'tagwright-seo' ),
							value: attributes.title,
							onChange: ( title ) => setAttributes( { title } ),
						} ),
						el( TextControl, {
							type: 'number',
							min: 0,
							label: __( 'Total time (minutes)', 'tagwright-seo' ),
							value: attributes.totalTime || '',
							onChange: ( value ) => setAttributes( { totalTime: Math.max( 0, parseInt( value, 10 ) || 0 ) } ),
						} ),
						el( TextControl, {
							label: __( 'Estimated cost', 'tagwright-seo' ),
							value: attributes.cost,
							onChange: ( cost ) => setAttributes( { cost } ),
						} ),
						el( TextControl, {
							label: __( 'Currency', 'tagwright-seo' ),
							help: __( 'Three-letter code, for example USD or EUR.', 'tagwright-seo' ),
							maxLength: 3,
							value: attributes.currency,
							onChange: ( currency ) => setAttributes( { currency: currency.toUpperCase() } ),
						} ),
						el( TextareaControl, {
							label: __( 'Supplies', 'tagwright-seo' ),
							help: __( 'One per line.', 'tagwright-seo' ),
							value: attributes.supplies,
							onChange: ( supplies ) => setAttributes( { supplies } ),
						} ),
						el( TextareaControl, {
							label: __( 'Tools', 'tagwright-seo' ),
							help: __( 'One per line.', 'tagwright-seo' ),
							value: attributes.tools,
							onChange: ( tools ) => setAttributes( { tools } ),
						} )
					)
				),
				el( 'div', useBlockProps( { className: 'tagwright-howto' } ),
					el( RichText, {
						tagName: 'p',
						className: 'tagwright-howto__description',
						value: attributes.description,
						allowedFormats: INLINE_FORMATS,
						placeholder: __( 'What will the reader be able to do?', 'tagwright-seo' ),
						onChange: ( description ) => setAttributes( { description } ),
					} ),
					el( 'ol', { className: 'tagwright-howto__steps' },
						steps.map( ( step, index ) => el( 'li', { className: 'tagwright-howto__step', key: index },
							el( RichText, {
								tagName: 'h3',
								className: 'tagwright-howto__step-title',
								value: step.title,
								allowedFormats: INLINE_FORMATS,
								/* translators: %d: step number. */
								placeholder: sprintf( __( 'Step %d', 'tagwright-seo' ), index + 1 ),
								onChange: ( value ) => setSteps( updateItem( steps, index, 'title', value ) ),
							} ),
							el( RichText, {
								tagName: 'p',
								className: 'tagwright-howto__step-text',
								value: step.text,
								allowedFormats: INLINE_FORMATS,
								placeholder: __( 'Describe this step', 'tagwright-seo' ),
								onChange: ( value ) => setSteps( updateItem( steps, index, 'text', value ) ),
							} ),
							el( StepImage, {
								id: step.imageId || 0,
								onChange: ( value ) => setSteps( updateItem( steps, index, 'imageId', value ) ),
							} ),
							itemActions( steps, index, setSteps )
						) )
					),
					el( Button, {
						variant: 'secondary',
						icon: 'plus-alt2',
						onClick: () => setSteps( steps.concat( [ { title: '', text: '', imageId: 0 } ] ) ),
					}, __( 'Add step', 'tagwright-seo' ) )
				)
			);
		},
		save: () => null,
	} );

	/* ------------------------------------------------------------------
	 * Reading time
	 * --------------------------------------------------------------- */

	registerBlockType( 'tagwright-seo/reading-time', {
		icon: icon( 'clock' ),
		edit( { attributes } ) {
			return el( 'div', useBlockProps(), preview( 'tagwright-seo/reading-time', attributes, __( 'Save the post to see its reading time.', 'tagwright-seo' ) ) );
		},
		save: () => null,
	} );

	/* ------------------------------------------------------------------
	 * Related posts
	 * --------------------------------------------------------------- */

	registerBlockType( 'tagwright-seo/related', {
		icon: icon( 'link' ),
		edit( { attributes, setAttributes } ) {
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Related posts', 'tagwright-seo' ) },
						el( TextControl, {
							label: __( 'Title', 'tagwright-seo' ),
							value: attributes.title,
							onChange: ( title ) => setAttributes( { title } ),
						} ),
						el( RangeControl, {
							label: __( 'Number of posts', 'tagwright-seo' ),
							min: 1,
							max: 12,
							value: attributes.count,
							onChange: ( count ) => setAttributes( { count } ),
						} ),
						el( ToggleControl, {
							label: __( 'Show featured images', 'tagwright-seo' ),
							checked: !! attributes.showImage,
							onChange: ( showImage ) => setAttributes( { showImage } ),
						} )
					)
				),
				el( 'div', useBlockProps(), preview( 'tagwright-seo/related', attributes, __( 'Related posts appear here once this post shares a category or tag with other published posts.', 'tagwright-seo' ) ) )
			);
		},
		save: () => null,
	} );
}( window.wp, window ) );
