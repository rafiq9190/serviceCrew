/**
 * Discounts screen: advance-payment tiers ("pay at least X% now, get Y%
 * off the whole job"). Talks only to /service-crew/v1/discount-tiers — see
 * class-service-crew-discounts.php. Deliberately has no connection to
 * services/add-ons; a discount here always applies to the whole booking.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'discounts' !== root.getAttribute( 'data-view' ) ) {
		return;
	}

	function load() {
		SCApp.request( { path: '/service-crew/v1/discount-tiers' } ).then( function ( tiers ) {
			render( tiers || [] );
		} );
	}

	function buildTierRow( tier ) {
		tier = tier || {};

		var typeSelect = SCApp.el( 'select', { class: 'sc-input sc-tier-type' }, [
			SCApp.el( 'option', { value: 'percent', text: 'Percent off' } ),
			SCApp.el( 'option', { value: 'fixed', text: 'Fixed amount off' } ),
		] );
		typeSelect.value = 'fixed' === tier.discount_type ? 'fixed' : 'percent';

		var row = SCApp.el( 'div', { class: 'sc-tier-row' } );

		row.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'If at least this % is paid now' } ),
			SCApp.el( 'input', { type: 'number', min: '1', max: '100', class: 'sc-input sc-tier-percent', value: tier.min_percent_paid || '' } ),
		] ) );

		row.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Discount type' } ),
			typeSelect,
		] ) );

		row.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Amount off' } ),
			SCApp.el( 'input', { type: 'number', step: '0.01', min: '0', class: 'sc-input sc-tier-value', value: tier.discount_value || '' } ),
		] ) );

		row.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-link-danger',
			text: 'Remove',
			onClick: function () {
				if ( row.parentNode ) {
					row.parentNode.removeChild( row );
				}
			},
		} ) );

		return row;
	}

	function render( tiers ) {
		root.innerHTML = '';

		var panel = SCApp.el( 'div', { class: 'sc-app-panel sc-app-panel--discounts' } );

		panel.appendChild( SCApp.el( 'h2', { text: 'Advance-payment discounts' } ) );
		panel.appendChild( SCApp.el( 'p', {
			class: 'sc-help',
			text: 'Applies to the whole booking based on how much the customer pays up front — never to an individual service or add-on.',
		} ) );

		var list = SCApp.el( 'div', { class: 'sc-tier-list' } );
		tiers.forEach( function ( tier ) {
			list.appendChild( buildTierRow( tier ) );
		} );
		panel.appendChild( list );

		panel.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary',
			text: '+ Add a tier',
			onClick: function () { list.appendChild( buildTierRow( {} ) ); },
		} ) );

		panel.appendChild( SCApp.el( 'div', { class: 'sc-app-form__actions' }, [
			SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--primary',
				text: 'Save',
				onClick: function () { save( list ); },
			} ),
		] ) );

		root.appendChild( panel );
	}

	function save( list ) {
		var rows = Array.prototype.slice.call( list.querySelectorAll( '.sc-tier-row' ) ).map( function ( row ) {
			return {
				min_percent_paid: parseInt( row.querySelector( '.sc-tier-percent' ).value, 10 ) || 0,
				discount_type: row.querySelector( '.sc-tier-type' ).value,
				discount_value: parseFloat( row.querySelector( '.sc-tier-value' ).value ) || 0,
			};
		} );

		SCApp.request( { path: '/service-crew/v1/discount-tiers', method: 'PUT', data: { tiers: rows } } ).then( function ( saved ) {
			SCApp.toast( 'Discount tiers saved.' );
			render( saved );
		} );
	}

	load();
} )();
