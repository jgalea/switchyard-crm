( function ( wp ) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var data = window.switchyardBlockVisibility || { tags: [] };

	wp.hooks.addFilter( 'blocks.registerBlockType', 'switchyard/attributes', function ( settings ) {
		settings.attributes = Object.assign( {}, settings.attributes, {
			switchyardAudience: { type: 'string', default: '' },
			switchyardTags: { type: 'array', default: [] },
			switchyardMatch: { type: 'string', default: 'any' },
		} );

		return settings;
	} );

	var withVisibility = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var attributes = props.attributes;
			var audience = attributes.switchyardAudience || '';
			var controls = [
				el( wp.components.SelectControl, {
					key: 'audience',
					label: __( 'Show to', 'switchyard-crm' ),
					value: audience,
					options: [
						{ value: '', label: __( 'Everyone', 'switchyard-crm' ) },
						{ value: 'logged_in', label: __( 'Logged-in users', 'switchyard-crm' ) },
						{ value: 'logged_out', label: __( 'Logged-out visitors', 'switchyard-crm' ) },
						{ value: 'tags', label: __( 'Users with CRM tags', 'switchyard-crm' ) },
					],
					onChange: function ( value ) {
						props.setAttributes( { switchyardAudience: value } );
					},
				} ),
			];

			if ( 'tags' === audience ) {
				controls.push(
					el( wp.components.SelectControl, {
						key: 'tags',
						multiple: true,
						label: __( 'Tags', 'switchyard-crm' ),
						value: attributes.switchyardTags || [],
						options: data.tags,
						onChange: function ( value ) {
							props.setAttributes( { switchyardTags: value } );
						},
					} ),
					el( wp.components.SelectControl, {
						key: 'match',
						label: __( 'Requires', 'switchyard-crm' ),
						value: attributes.switchyardMatch || 'any',
						options: [
							{ value: 'any', label: __( 'Any of these', 'switchyard-crm' ) },
							{ value: 'all', label: __( 'All of these', 'switchyard-crm' ) },
						],
						onChange: function ( value ) {
							props.setAttributes( { switchyardMatch: value } );
						},
					} )
				);
			}

			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el(
					wp.blockEditor.InspectorControls,
					null,
					el( wp.components.PanelBody, { title: __( 'Visibility', 'switchyard-crm' ), initialOpen: '' !== audience }, controls )
				)
			);
		};
	}, 'withSwitchyardVisibility' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'switchyard/visibility', withVisibility );
} )( window.wp );
