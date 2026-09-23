/**
 * Adds/removes Time Off rows on the sc_crew edit screen by cloning the
 * hidden template row and giving it a fresh, unique index.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var table   = document.getElementById( 'sc_crew_time_off_table' );
		var addBtn  = document.getElementById( 'sc_crew_time_off_add' );
		var template = document.getElementById( 'sc_crew_time_off_template' );

		if ( ! table || ! addBtn || ! template ) {
			return;
		}

		var body = table.querySelector( 'tbody' );
		var nextIndex = body.children.length;

		function bindRemove( row ) {
			var removeBtn = row.querySelector( '.sc-crew-time-off-remove' );
			if ( removeBtn ) {
				removeBtn.addEventListener( 'click', function () {
					row.parentNode.removeChild( row );
				} );
			}
		}

		Array.prototype.forEach.call( body.querySelectorAll( 'tr' ), bindRemove );

		addBtn.addEventListener( 'click', function () {
			var row = template.cloneNode( true );
			row.removeAttribute( 'id' );
			row.innerHTML = row.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
			nextIndex += 1;

			bindRemove( row );
			body.appendChild( row );
		} );
	} );
} )();
