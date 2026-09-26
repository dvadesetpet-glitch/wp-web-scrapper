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
	var Notice          = wp.components.Notice;
	var Button          = wp.components.Button;
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
			auth_profile      : { type: 'string',  default: '' },
			// Legacy inline credentials — kept so existing blocks still render.
			auth_type         : { type: 'string',  default: 'none' },
			auth_user         : { type: 'string',  default: '' },
			auth_pass         : { type: 'string',  default: '' },
			auth_token        : { type: 'string',  default: '' },
			background_refresh: { type: 'integer', default: 0 },
		},

		edit: function ( props ) {
			var attr    = props.attributes;
			var setAttr = props.setAttributes;
			var hasLegacySecrets = !! ( attr.auth_user || attr.auth_pass || attr.auth_token );

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
				el( PanelBody, { title: 'Authentication', initialOpen: hasLegacySecrets },
					el( TextControl, {
						label   : 'Auth profile',
						help    : 'Name of a profile defined in Settings → WP WS Reborn 2026 → Authentication profiles. Credentials are no longer stored in the block.',
						value   : attr.auth_profile,
						onChange: function ( v ) { setAttr( { auth_profile: v } ); },
					} ),
					hasLegacySecrets && el( Notice, { status: 'warning', isDismissible: false },
						'This block still contains a password or token saved in the post content (visible to every editor and in revisions). Move it to an auth profile, then remove it here.'
					),
					hasLegacySecrets && el( Button, {
						variant : 'secondary',
						isDestructive: true,
						onClick : function () {
							setAttr( { auth_type: 'none', auth_user: '', auth_pass: '', auth_token: '' } );
						},
					}, 'Remove stored credentials' )
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
