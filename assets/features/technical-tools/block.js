/**
 * HTML Sitemap block. Rendered on the server, so the editor shows exactly
 * what visitors will see.
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, Disabled } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;

	registerBlockType( 'tagwright-seo/html-sitemap', {
		apiVersion: 3,
		title: __( 'HTML Sitemap', 'tagwright-seo' ),
		description: __( 'A list of links to your pages, posts and categories.', 'tagwright-seo' ),
		category: 'widgets',
		icon: 'networking',
		keywords: [ __( 'sitemap', 'tagwright-seo' ), __( 'links', 'tagwright-seo' ), 'seo' ],
		supports: {
			html: false,
			align: [ 'wide', 'full' ],
		},

		edit( props ) {
			const blockProps = useBlockProps();

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'tagwright-seo' ) },
						el( 'p', null, __( 'Choose what the sitemap lists under Tagwright SEO › Settings › Technical Tools.', 'tagwright-seo' ) )
					)
				),
				el(
					'div',
					blockProps,
					el(
						Disabled,
						null,
						el( ServerSideRender, {
							block: 'tagwright-seo/html-sitemap',
							attributes: props.attributes,
							EmptyResponsePlaceholder: () => el( 'p', null, __( 'Nothing to list yet. Pick content types in the Technical Tools settings.', 'tagwright-seo' ) ),
						} )
					)
				)
			);
		},

		save() {
			return null;
		},
	} );
}( window.wp ) );
