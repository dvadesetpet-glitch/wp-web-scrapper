/* global wp */
( function () {
	'use strict';

	var el              = wp.element.createElement;
	var registerBlock   = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody       = wp.components.PanelBody;
	var TextControl     = wp.components.TextControl;
	var SelectControl   = wp.components.SelectControl;
	var ToggleControl   = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	registerBlock( 'wpws/scraper', {
		title      : 'WP Web Scraper',
		description: 'Fetch and display content from any URL.',
		icon       : 'download',
		category   : 'embed',
		attributes : {
			url               : { type: 'string',  default: '' },
			query             : { type: 'string',  default: '' },
			query_type        : { type: 'string',  default: 'cssselector' },
			cache             : { type: 'integer', default: 60 },
			output            : { type: 'string',  default: 'html' },
			auth_type         : { type: 'string',  default: 'none' },
			auth_user         : { type: 'string',  default: '' },
			auth_pass         : { type: 'string',  default: '' },
			auth_token        : { type: 'string',  default: '' },
			background_refresh: { type: 'integer', default: 0 },
		},

		edit: function ( props ) {
			var attr    = props.attributes;
			var setAttr = props.setAttributes;

			var inspector = el( InspectorControls, { key: 'inspector' },
				el( PanelBody, { title: 'Source', initialOpen: true },
					el( TextControl, {
						label   : 'URL',
						value   : attr.url,
						onChange: function ( v ) { setAttr( { url: v } ); },
					} ),
					el( TextControl, {
						label   : 'Query',
						value   : attr.query,
						onChange: function ( v ) { setAttr( { query: v } ); },
					} ),
					el( SelectControl, {
						label   : 'Query type',
						value   : attr.query_type,
						options : [
							{ label: 'CSS Selector', value: 'cssselector' },
							{ label: 'XPath',        value: 'xpath' },
							{ label: 'Regex',        value: 'regex' },
							{ label: 'JSONPath',     value: 'jsonpath' },
						],
						onChange: function ( v ) { setAttr( { query_type: v } ); },
					} ),
					el( SelectControl, {
						label   : 'Output',
						value   : attr.output,
						options : [
							{ label: 'HTML', value: 'html' },
							{ label: 'Text', value: 'text' },
						],
						onChange: function ( v ) { setAttr( { output: v } ); },
					} ),
					el( TextControl, {
						label   : 'Cache (minutes)',
						type    : 'number',
						value   : attr.cache,
						onChange: function ( v ) { setAttr( { cache: parseInt( v, 10 ) || 0 } ); },
					} ),
					el( ToggleControl, {
						label   : 'Background cache refresh',
						checked : !! attr.background_refresh,
						onChange: function ( v ) { setAttr( { background_refresh: v ? 1 : 0 } ); },
					} )
				),
				el( PanelBody, { title: 'Authentication', initialOpen: false },
					el( SelectControl, {
						label   : 'Auth type',
						value   : attr.auth_type,
						options : [
							{ label: 'None',   value: 'none' },
							{ label: 'Basic',  value: 'basic' },
							{ label: 'Bearer', value: 'bearer' },
						],
						onChange: function ( v ) { setAttr( { auth_type: v } ); },
					} ),
					attr.auth_type === 'basic' && el( TextControl, {
						label   : 'Username',
						value   : attr.auth_user,
						onChange: function ( v ) { setAttr( { auth_user: v } ); },
					} ),
					attr.auth_type === 'basic' && el( TextControl, {
						label   : 'Password',
						type    : 'password',
						value   : attr.auth_pass,
						onChange: function ( v ) { setAttr( { auth_pass: v } ); },
					} ),
					attr.auth_type === 'bearer' && el( TextControl, {
						label   : 'Token',
						value   : attr.auth_token,
						onChange: function ( v ) { setAttr( { auth_token: v } ); },
					} )
				)
			);

			var preview = attr.url
				? el( ServerSideRender, { block: 'wpws/scraper', attributes: attr } )
				: el( 'p', { className: 'wpws-block-placeholder' }, 'Enter a URL in the sidebar to preview.' );

			return [ inspector, el( 'div', { className: 'wp-block-wpws-scraper-editor' }, preview ) ];
		},

		save: function () {
			// Rendered server-side; nothing to save.
			return null;
		},
	} );
}() );
