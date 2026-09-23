/**
 * Adds/removes Add-on blocks on the sc_service edit screen by cloning the
 * hidden template block, and shows/hides the quantity + discount-tier
 * fields based on the "Let customer choose a quantity" checkbox.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var list     = document.getElementById( 'sc_components_list' );
		var addBtn   = document.getElementById( 'sc_component_add' );
		var template = document.getElementById( 'sc_component_template' );

		if ( ! list || ! addBtn || ! template ) {
			return;
		}

		var nextIndex = list.children.length;

		function bindRemove( block ) {
			var removeBtn = block.querySelector( '.sc-component-remove' );
			if ( removeBtn ) {
				removeBtn.addEventListener( 'click', function () {
					block.parentNode.removeChild( block );
				} );
			}
		}

		function bindQuantityToggle( block ) {
			var checkbox      = block.querySelector( '.sc-addon-has-quantity' );
			var quantityFields = block.querySelector( '.sc-addon-quantity-fields' );

			if ( ! checkbox || ! quantityFields ) {
				return;
			}

			checkbox.addEventListener( 'change', function () {
				quantityFields.classList.toggle( 'sc-addon-hidden', ! checkbox.checked );
			} );
		}

		function initBlock( block ) {
			bindRemove( block );
			bindQuantityToggle( block );
		}

		Array.prototype.forEach.call( list.querySelectorAll( '.sc-component-block' ), initBlock );

		addBtn.addEventListener( 'click', function () {
			var block = template.cloneNode( true );
			block.removeAttribute( 'id' );
			block.innerHTML = block.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
			nextIndex += 1;

			initBlock( block );
			list.appendChild( block );
		} );
	} );
} )();
