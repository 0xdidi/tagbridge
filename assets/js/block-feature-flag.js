( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var InnerBlocks = blockEditor.InnerBlocks;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var __ = i18n.__;

	blocks.registerBlockType( 'tagbridge/feature-flag', {
		title: __( 'PostHog Feature Flag', 'tagbridge' ),
		description: __( 'Show inner blocks only when a server-evaluated PostHog feature flag matches.', 'tagbridge' ),
		category: 'widgets',
		icon: 'flag',
		supports: {
			html: false,
		},
		attributes: {
			flagKey: {
				type: 'string',
				default: '',
			},
			matchValue: {
				type: 'string',
				default: 'true',
			},
			defaultValue: {
				type: 'boolean',
				default: false,
			},
		},
		edit: function ( props ) {
			var attrs = props.attributes;
			var blockProps = useBlockProps( {
				style: {
					border: '1px dashed #c8c5b8',
					padding: '12px',
					borderRadius: '6px',
				},
			} );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Feature Flag Settings', 'tagbridge' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Flag key', 'tagbridge' ),
							value: attrs.flagKey,
							onChange: function ( val ) {
								props.setAttributes( { flagKey: val } );
							},
						} ),
						el( TextControl, {
							label: __( 'Match value', 'tagbridge' ),
							help: __( 'Use true, false, or a multivariate variant name.', 'tagbridge' ),
							value: attrs.matchValue,
							onChange: function ( val ) {
								props.setAttributes( { matchValue: val } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Fallback enabled when unconfigured', 'tagbridge' ),
							checked: !! attrs.defaultValue,
							onChange: function ( val ) {
								props.setAttributes( { defaultValue: !! val } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'div',
						{ style: { fontSize: '12px', opacity: 0.75, marginBottom: '8px' } },
						attrs.flagKey
							? __( 'PostHog Flag:', 'tagbridge' ) + ' ' + attrs.flagKey + ' = ' + ( attrs.matchValue || 'true' )
							: __( 'PostHog Feature Flag (set Flag key in sidebar)', 'tagbridge' )
					),
					el( InnerBlocks )
				)
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );
}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
) );
