/**
 * Private employee job-status page: renders the right action button(s) for
 * the booking's current status (SC_JOB_PAGE.status, set server-side by
 * class-service-crew-job-status-page.php) and posts to
 * POST /service-crew/v1/job-status/{token}/on-the-way|start|complete.
 * Deliberately reloads the page after a successful action rather than
 * managing client-side state — the server is the source of truth for what
 * comes next, same as this page's own initial render.
 */
( function () {
	'use strict';

	if ( ! window.SC_JOB_PAGE ) {
		return;
	}

	var actionsEl = document.getElementById( 'sc-job-page-actions' );
	var errorEl = document.getElementById( 'sc-job-page-error' );

	if ( ! actionsEl ) {
		return;
	}

	function showError( message ) {
		if ( errorEl ) {
			errorEl.textContent = message;
		}
	}

	function el( tag, attrs ) {
		var node = document.createElement( tag );
		attrs = attrs || {};

		Object.keys( attrs ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );

		return node;
	}

	function post( path, body, isMultipart ) {
		var options = {
			method: 'POST',
			headers: { 'X-WP-Nonce': SC_JOB_PAGE.nonce },
		};

		if ( isMultipart ) {
			options.body = body;
		} else {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify( body || {} );
		}

		return window.fetch( SC_JOB_PAGE.restUrl + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				return { ok: response.ok, data: data };
			} );
		} );
	}

	function runAction( button, path, body, isMultipart ) {
		if ( button.classList.contains( 'is-loading' ) ) {
			return;
		}

		button.classList.add( 'is-loading' );
		button.disabled = true;
		showError( '' );

		post( 'job-status/' + encodeURIComponent( SC_JOB_PAGE.token ) + '/' + path, body, isMultipart )
			.then( function ( result ) {
				if ( ! result.ok ) {
					throw new Error( ( result.data && result.data.message ) || 'Something went wrong. Please try again.' );
				}

				window.location.reload();
			} )
			.catch( function ( error ) {
				button.classList.remove( 'is-loading' );
				button.disabled = false;
				showError( error.message || 'Something went wrong. Please try again.' );
			} );
	}

	function renderButton( label, path, body, isMultipart ) {
		var button = el( 'button', { type: 'button', class: 'sc-job-page__button' } );
		button.textContent = label;
		button.addEventListener( 'click', function () {
			runAction( button, path, body, isMultipart );
		} );
		actionsEl.appendChild( button );
	}

	function renderMessage( text ) {
		actionsEl.appendChild( el( 'p', { class: 'sc-job-page__meta', text: text } ) );
	}

	function renderCompleteForm() {
		var noteInput = el( 'textarea', { class: 'sc-job-page__input', rows: '3', placeholder: 'Completion note (optional)' } );
		var fileInput = el( 'input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', multiple: 'multiple' } );

		actionsEl.appendChild( noteInput );
		actionsEl.appendChild( fileInput );

		var button = el( 'button', { type: 'button', class: 'sc-job-page__button', text: 'Mark complete' } );
		button.addEventListener( 'click', function () {
			if ( button.classList.contains( 'is-loading' ) ) {
				return;
			}

			var formData = new window.FormData();
			formData.append( 'note', noteInput.value );

			Array.prototype.forEach.call( fileInput.files || [], function ( file ) {
				formData.append( 'photos[]', file );
			} );

			button.classList.add( 'is-loading' );
			button.disabled = true;
			showError( '' );

			post( 'job-status/' + encodeURIComponent( SC_JOB_PAGE.token ) + '/complete', formData, true )
				.then( function ( result ) {
					if ( ! result.ok ) {
						throw new Error( ( result.data && result.data.message ) || 'Something went wrong. Please try again.' );
					}

					window.location.reload();
				} )
				.catch( function ( error ) {
					button.classList.remove( 'is-loading' );
					button.disabled = false;
					showError( error.message || 'Something went wrong. Please try again.' );
				} );
		} );

		actionsEl.appendChild( button );
	}

	switch ( SC_JOB_PAGE.status ) {
		case 'assigned':
			renderButton( 'On the way', 'on-the-way' );
			break;
		case 'on_the_way':
			renderButton( 'Start job', 'start' );
			break;
		case 'in_progress':
			renderCompleteForm();
			break;
		case 'completed':
			renderMessage( 'This job is marked complete. Thanks!' );
			break;
		default:
			renderMessage( 'This job is no longer active.' );
			break;
	}
} )();
