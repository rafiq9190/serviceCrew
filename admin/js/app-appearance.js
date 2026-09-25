/**
 * Appearance screen: the color palette shared by the public booking widget
 * ([service_crew_services]) and the [service_crew_booking] stepper it's
 * embedded in — including the stepper's own named button/stepper/date-picker
 * tokens (split from the general "accent" so they can each carry a different
 * identity color, by request). Talks only to /service-crew/v1/widget-colors
 * (see class-service-crew-appearance.php). Uses wp-color-picker (core,
 * already bundled with WordPress) instead of a bare <input type="color">.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'appearance' !== root.getAttribute( 'data-view' ) ) {
		return;
	}

	var GROUPS = [
		{
			title: 'General',
			tokens: [
				{ key: 'background', label: 'Widget background' },
				{ key: 'surface', label: 'Column background' },
				{ key: 'border', label: 'Borders' },
				{ key: 'text', label: 'Text' },
				{ key: 'muted_text', label: 'Muted text' },
			],
		},
		{
			title: 'Interaction',
			tokens: [
				{ key: 'accent', label: 'Accent' },
				{ key: 'hover_bg', label: 'Hover background' },
				{ key: 'selected_bg', label: 'Selected background' },
				{ key: 'selected_text', label: 'Selected text' },
			],
		},
		{
			title: 'Total column',
			tokens: [
				{ key: 'total_bg', label: 'Total background' },
				{ key: 'total_text', label: 'Total text' },
				{ key: 'total_accent', label: 'Total accent' },
			],
		},
		{
			title: 'Booking flow (stepper)',
			tokens: [
				{ key: 'button_color', label: 'Buttons' },
				{ key: 'stepper_color', label: 'Stepper' },
				{ key: 'calendar_color', label: 'Date picker' },
			],
		},
	];

	var colors    = {};
	var previewEl = null;

	function load() {
		SCApp.request( { path: '/service-crew/v1/widget-colors' } ).then( function ( saved ) {
			colors = saved || {};
			render();
		} );
	}

	function applyPreviewVars() {
		if ( ! previewEl ) {
			return;
		}

		Object.keys( colors ).forEach( function ( key ) {
			previewEl.style.setProperty( '--sc-w-' + key.replace( /_/g, '-' ), colors[ key ] );
		} );
	}

	function buildPreview() {
		previewEl = SCApp.el( 'div', { class: 'sc-appearance-preview' }, [
			SCApp.el( 'div', { class: 'sc-appearance-preview__col' }, [
				SCApp.el( 'div', { class: 'sc-appearance-preview__row', text: 'Service' } ),
				SCApp.el( 'div', { class: 'sc-appearance-preview__row sc-appearance-preview__row--hover', text: 'Hovered row' } ),
				SCApp.el( 'div', { class: 'sc-appearance-preview__row sc-appearance-preview__row--selected', text: 'Selected row' } ),
			] ),
			SCApp.el( 'div', { class: 'sc-appearance-preview__total', text: 'Total   $75' } ),
		] );

		// Nested inside previewEl (not a sibling) so it inherits the same
		// --sc-w-* custom properties applyPreviewVars() sets on previewEl —
		// CSS custom properties only flow to descendants, not siblings.
		previewEl.appendChild( SCApp.el( 'div', { class: 'sc-appearance-preview__flow' }, [
			SCApp.el( 'span', { class: 'sc-appearance-preview__stepper-dot', text: '1' } ),
			SCApp.el( 'span', { class: 'sc-appearance-preview__datecell', text: '25' } ),
			SCApp.el( 'button', { type: 'button', class: 'sc-appearance-preview__button', text: 'Continue' } ),
		] ) );

		applyPreviewVars();

		return previewEl;
	}

	function buildGroup( group ) {
		var card = SCApp.el( 'div', { class: 'sc-appearance-group' } );
		card.appendChild( SCApp.el( 'h3', { text: group.title } ) );

		var fields = SCApp.el( 'div', { class: 'sc-appearance-group__fields' } );

		group.tokens.forEach( function ( token ) {
			fields.appendChild( SCApp.el( 'div', { class: 'sc-field sc-appearance-field' }, [
				SCApp.el( 'label', { text: token.label } ),
				SCApp.el( 'input', {
					type: 'text',
					class: 'sc-color-field',
					'data-token': token.key,
					value: colors[ token.key ] || '',
				} ),
			] ) );
		} );

		card.appendChild( fields );
		return card;
	}

	function initColorPickers() {
		if ( ! window.jQuery || ! window.jQuery.fn.wpColorPicker ) {
			return;
		}

		window.jQuery( root ).find( '.sc-color-field' ).wpColorPicker( {
			change: function ( event, ui ) {
				var token = event.target.getAttribute( 'data-token' );
				colors[ token ] = ui.color.toString();
				applyPreviewVars();
			},
		} );
	}

	function collect() {
		var values = {};

		Array.prototype.slice.call( root.querySelectorAll( '.sc-color-field' ) ).forEach( function ( input ) {
			values[ input.getAttribute( 'data-token' ) ] = input.value;
		} );

		return values;
	}

	function save() {
		SCApp.request( { path: '/service-crew/v1/widget-colors', method: 'PUT', data: collect() } ).then( function ( saved ) {
			colors = saved;
			SCApp.toast( 'Appearance saved.' );
			render();
		} );
	}

	function render() {
		root.innerHTML = '';

		var panel = SCApp.el( 'div', { class: 'sc-app-panel sc-app-panel--appearance' } );

		panel.appendChild( SCApp.el( 'h2', { text: 'Widget appearance' } ) );
		panel.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			text: 'Colors used by the [service_crew_services] widget and the [service_crew_booking] stepper on the front end. Changes apply everywhere either shortcode is used.',
		} ) );

		panel.appendChild( buildPreview() );

		GROUPS.forEach( function ( group ) {
			panel.appendChild( buildGroup( group ) );
		} );

		panel.appendChild( SCApp.el( 'div', { class: 'sc-app-form__actions' }, [
			SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--primary', text: 'Save', onClick: save } ),
		] ) );

		root.appendChild( panel );
		initColorPickers();
	}

	load();
} )();
