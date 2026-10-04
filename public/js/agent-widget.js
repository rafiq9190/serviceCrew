/**
 * Sitewide chat launcher + panel for the Agent chat sales agent. Talks to
 * class-service-crew-agent-controller.php's public routes via plain fetch()
 * (not wp.apiFetch — that's an admin-only script, never loaded on the front
 * end). Every piece of visitor/agent text still only ever becomes a text
 * node (never innerHTML, same XSS-safety rule admin/js/app-core.js's el()
 * enforces) — appendMessage()'s one exception is a single http(s) URL
 * inside a reply (Phase B's FLOW_BOOK/FLOW_PAY hand back a Stripe/pay-page
 * link), which becomes a real <a> built via plain DOM calls, never through
 * a string of HTML.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-agent-widget-root' );

	if ( ! root || 'undefined' === typeof SC_AGENT_WIDGET ) {
		return;
	}

	var TOKEN_KEY = 'sc_agent_session_token';
	var DISMISSED_GREETING_KEY = 'sc_agent_greeting_dismissed';
	var LAST_SEEN_COUNT_KEY = 'sc_agent_last_seen_count';

	// A live poll is only a fast path for someone still on the page right
	// after escalating — the real delivery mechanism for a visitor who
	// closes the tab is syncHistory() re-checking on the next page load
	// (see its own docblock below), since the admin's eventual answer is
	// always durably logged into the session's own transcript regardless.
	var ESCALATION_POLL_INTERVAL_MS = 15000;
	var ESCALATION_POLL_MAX_MS = 5 * 60 * 1000;

	var state = {
		token: localStorage.getItem( TOKEN_KEY ) || null,
		isOpen: false,
		isLoading: false,
		historySyncing: false,
		// How many of this session's server-logged messages have already
		// been rendered into the panel (across page loads) — lets
		// syncHistory() append only what's new instead of the whole
		// transcript every time.
		messageCount: parseInt( localStorage.getItem( LAST_SEEN_COUNT_KEY ), 10 ) || 0,
	};

	function setLastSeenCount( count ) {
		state.messageCount = count;
		localStorage.setItem( LAST_SEEN_COUNT_KEY, String( count ) );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];

			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 0 === key.indexOf( 'on' ) && 'function' === typeof value ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else {
				node.setAttribute( key, value );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );

		return node;
	}

	function request( path, body ) {
		return fetch( SC_AGENT_WIDGET.restUrl + path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': SC_AGENT_WIDGET.nonce,
			},
			body: JSON.stringify( body || {} ),
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Request failed' );
			}
			return response.json();
		} );
	}

	function getHistory( token ) {
		return fetch( SC_AGENT_WIDGET.restUrl + 'agent/history?token=' + encodeURIComponent( token ), {
			headers: { 'X-WP-Nonce': SC_AGENT_WIDGET.nonce },
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Session expired' );
			}
			return response.json();
		} );
	}

	// --- Launcher ---

	var launcher = el( 'button', {
		type: 'button',
		class: 'sc-agent-launcher sc-agent-launcher--pulse',
		'aria-label': 'Chat with us',
		onClick: togglePanel,
	}, [
		el( 'span', { class: 'dashicons dashicons-format-chat', 'aria-hidden': 'true' } ),
		el( 'span', { class: 'sc-agent-launcher__badge', style: 'display:none;' } ),
	] );

	setTimeout( function () {
		launcher.classList.remove( 'sc-agent-launcher--pulse' );
	}, 10000 );

	// --- Panel ---

	var messageList = el( 'div', { class: 'sc-agent-panel__messages', 'aria-live': 'polite' } );

	var input = el( 'input', {
		type: 'text',
		class: 'sc-agent-panel__input',
		placeholder: 'Type your message…',
		onKeydown: function ( event ) {
			if ( 'Enter' === event.key ) {
				sendCurrentMessage();
			}
		},
	} );

	var sendButton = el( 'button', {
		type: 'button',
		class: 'sc-agent-panel__send',
		'aria-label': 'Send',
		onClick: sendCurrentMessage,
	}, [ el( 'span', { class: 'dashicons dashicons-arrow-right-alt', 'aria-hidden': 'true' } ) ] );

	// Only shown while Phase B's FLOW_QUOTE is on its 'photos' step (see
	// sendCurrentMessage()'s response handling, which toggles this via the
	// `awaiting_photos` response field) — a visitor can't attach a photo
	// outside that one step's context, server-side-enforced too
	// (class-service-crew-agent-controller.php's upload_photo()).
	var photoInput = el( 'input', { type: 'file', accept: 'image/*', style: 'display:none;' } );

	var attachButton = el( 'button', {
		type: 'button',
		class: 'sc-agent-panel__attach',
		'aria-label': 'Attach a photo',
		style: 'display:none;',
		onClick: function () {
			photoInput.click();
		},
	}, [ el( 'span', { class: 'dashicons dashicons-camera', 'aria-hidden': 'true' } ) ] );

	photoInput.addEventListener( 'change', uploadSelectedPhoto );

	var panel = el( 'div', { class: 'sc-agent-panel', hidden: 'hidden' }, [
		el( 'div', { class: 'sc-agent-panel__header' }, [
			el( 'span', { class: 'sc-agent-panel__title', text: "Let's chat" } ),
			el( 'button', { type: 'button', class: 'sc-agent-panel__close', 'aria-label': 'Close chat', onClick: togglePanel }, [
				el( 'span', { class: 'dashicons dashicons-no-alt', 'aria-hidden': 'true' } ),
			] ),
		] ),
		messageList,
		el( 'div', { class: 'sc-agent-panel__composer' }, [ attachButton, photoInput, input, sendButton ] ),
	] );

	// --- Proactive greeting bubble ---

	var greetingBubble = null;

	function maybeShowGreeting() {
		if ( sessionStorage.getItem( DISMISSED_GREETING_KEY ) || state.isOpen ) {
			return;
		}

		greetingBubble = el( 'div', { class: 'sc-agent-greeting' }, [
			el( 'button', {
				type: 'button',
				class: 'sc-agent-greeting__close',
				'aria-label': 'Dismiss',
				onClick: function ( event ) {
					event.stopPropagation();
					dismissGreeting();
				},
			}, [ el( 'span', { class: 'dashicons dashicons-no-alt', 'aria-hidden': 'true' } ) ] ),
			el( 'p', { text: SC_AGENT_WIDGET.greeting } ),
		] );

		greetingBubble.addEventListener( 'click', function () {
			dismissGreeting();
			togglePanel();
		} );

		root.appendChild( greetingBubble );
	}

	function dismissGreeting() {
		sessionStorage.setItem( DISMISSED_GREETING_KEY, '1' );
		if ( greetingBubble && greetingBubble.parentNode ) {
			greetingBubble.parentNode.removeChild( greetingBubble );
		}
		greetingBubble = null;
	}

	// --- Messages ---

	var URL_PATTERN = /(https?:\/\/[^\s]+)/;

	/**
	 * Builds the message paragraph — plain textContent unless the body
	 * contains an http(s) URL (Phase B's FLOW_BOOK/FLOW_PAY replies), in
	 * which case that one URL becomes a real, clickable <a> assembled from
	 * three DOM nodes (text before, the link, text after) — never a string
	 * of HTML, so this can't become an innerHTML/XSS path regardless of
	 * what the URL or surrounding text contains.
	 *
	 * @param {string} body Message text.
	 * @return {HTMLParagraphElement}
	 */
	function buildMessageParagraph( body ) {
		var match = URL_PATTERN.exec( body );

		if ( ! match ) {
			return el( 'p', { text: body } );
		}

		var url = match[ 1 ];
		var before = body.slice( 0, match.index );
		var after = body.slice( match.index + url.length );

		var paragraph = document.createElement( 'p' );

		if ( before ) {
			paragraph.appendChild( document.createTextNode( before ) );
		}

		var link = el( 'a', { href: url, target: '_blank', rel: 'noopener noreferrer', text: url } );
		paragraph.appendChild( link );

		if ( after ) {
			paragraph.appendChild( document.createTextNode( after ) );
		}

		return paragraph;
	}

	function appendMessage( sender, body ) {
		var row = el( 'div', { class: 'sc-agent-message sc-agent-message--' + sender }, [
			buildMessageParagraph( body ),
		] );
		messageList.appendChild( row );
		messageList.scrollTop = messageList.scrollHeight;
	}

	function appendTyping() {
		var row = el( 'div', { class: 'sc-agent-message sc-agent-message--agent sc-agent-message--typing' }, [
			el( 'span', {} ), el( 'span', {} ), el( 'span', {} ),
		] );
		messageList.appendChild( row );
		messageList.scrollTop = messageList.scrollHeight;
		return row;
	}

	function showUnreadBadge() {
		if ( state.isOpen ) {
			return;
		}
		var badge = launcher.querySelector( '.sc-agent-launcher__badge' );
		badge.style.display = '';
	}

	function clearUnreadBadge() {
		launcher.querySelector( '.sc-agent-launcher__badge' ).style.display = 'none';
	}

	/**
	 * Resumes a returning visitor's conversation without requiring the tab
	 * to have stayed open — called once on every page load (see the bottom
	 * of this file). The admin's answer to an earlier escalation is already
	 * durably logged into the session's transcript the moment they send it
	 * (Service_Crew_Agent_Escalations::answer()), so re-checking
	 * /agent/history here surfaces it any time within the session token's
	 * 30-day lifetime — no open tab/live poll required.
	 */
	function syncHistory() {
		if ( ! state.token || state.historySyncing ) {
			return;
		}

		state.historySyncing = true;

		getHistory( state.token )
			.then( function ( messages ) {
				messages.slice( state.messageCount ).forEach( function ( message ) {
					appendMessage( message.sender, message.body );
				} );

				if ( messages.length > state.messageCount ) {
					showUnreadBadge();
				}

				setLastSeenCount( messages.length );
			} )
			.catch( function () {
				// Session expired/not found — nothing to resume.
			} )
			.finally( function () {
				state.historySyncing = false;
			} );
	}

	/**
	 * Fast-path live poll for a visitor still on the page right after
	 * escalating — see the ESCALATION_POLL_* constants' own comment for why
	 * this isn't the only delivery mechanism.
	 *
	 * @param {number} escalationId Escalation id from /agent/message's response.
	 */
	function startEscalationPoll( escalationId ) {
		var elapsed = 0;
		var intervalId = setInterval( function () {
			elapsed += ESCALATION_POLL_INTERVAL_MS;

			if ( elapsed >= ESCALATION_POLL_MAX_MS ) {
				clearInterval( intervalId );
				return;
			}

			fetch( SC_AGENT_WIDGET.restUrl + 'agent/escalation-status?token=' + encodeURIComponent( state.token ) + '&escalation_id=' + encodeURIComponent( escalationId ), {
				headers: { 'X-WP-Nonce': SC_AGENT_WIDGET.nonce },
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'Escalation status request failed' );
					}
					return response.json();
				} )
				.then( function ( data ) {
					if ( 'answered' === data.status && data.answer ) {
						clearInterval( intervalId );
						appendMessage( 'agent', data.answer );
						setLastSeenCount( state.messageCount + 1 );
						showUnreadBadge();
					}
				} )
				.catch( function () {
					// Transient hiccup — next tick retries; syncHistory() on
					// a future page load is the real safety net regardless.
				} );
		}, ESCALATION_POLL_INTERVAL_MS );
	}

	/**
	 * Uploads whatever file the visitor just picked via the attach button —
	 * multipart POST (not the JSON request() helper, which can't carry a
	 * file), reusing the session token as a form field so the server can
	 * verify this session is actually mid-FLOW_QUOTE before accepting it.
	 */
	function uploadSelectedPhoto() {
		var file = photoInput.files && photoInput.files[ 0 ];

		if ( ! file || ! state.token ) {
			return;
		}

		var formData = new FormData();
		formData.append( 'token', state.token );
		formData.append( 'photo', file );

		appendMessage( 'visitor', '📷 ' + file.name );

		fetch( SC_AGENT_WIDGET.restUrl + 'agent/upload', {
			method: 'POST',
			headers: { 'X-WP-Nonce': SC_AGENT_WIDGET.nonce },
			body: formData,
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Upload failed' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				appendMessage( 'agent', 'Got it — ' + data.count + ' photo' + ( 1 === data.count ? '' : 's' ) + ' attached. Add another, or say "done" to continue.' );
			} )
			.catch( function () {
				appendMessage( 'agent', 'Sorry, that photo didn\'t upload. You can try again, or say "done" to continue without it.' );
			} );

		photoInput.value = '';
	}

	function ensureSession() {
		if ( state.token ) {
			return Promise.resolve( state.token );
		}

		return request( 'agent/session' ).then( function ( data ) {
			state.token = data.token;
			localStorage.setItem( TOKEN_KEY, data.token );
			return state.token;
		} );
	}

	function sendCurrentMessage() {
		var text = input.value.trim();
		if ( '' === text || state.isLoading ) {
			return;
		}

		input.value = '';
		appendMessage( 'visitor', text );
		state.isLoading = true;

		var typingRow = appendTyping();

		ensureSession()
			.then( function ( token ) {
				return request( 'agent/message', { token: token, message: text } );
			} )
			.then( function ( data ) {
				messageList.removeChild( typingRow );
				appendMessage( 'agent', data.reply );
				// Both the visitor's message and this reply are now logged
				// server-side (handle_message() always logs both).
				setLastSeenCount( state.messageCount + 2 );
				showUnreadBadge();

				attachButton.style.display = data.awaiting_photos ? '' : 'none';

				if ( 'escalate' === data.decision && data.escalation_id ) {
					startEscalationPoll( data.escalation_id );
				}
			} )
			.catch( function () {
				messageList.removeChild( typingRow );
				appendMessage( 'agent', "Sorry, something went wrong. Please try again." );
			} )
			.finally( function () {
				state.isLoading = false;
			} );
	}

	function togglePanel() {
		state.isOpen = ! state.isOpen;
		panel.hidden = ! state.isOpen;
		launcher.classList.toggle( 'sc-agent-launcher--open', state.isOpen );

		if ( state.isOpen ) {
			clearUnreadBadge();
			dismissGreeting();
			input.focus();

			if ( state.token && 0 === messageList.children.length && ! state.historySyncing ) {
				getHistory( state.token )
					.then( function ( messages ) {
						messages.forEach( function ( message ) {
							appendMessage( message.sender, message.body );
						} );
						setLastSeenCount( messages.length );
					} )
					.catch( function () {
						localStorage.removeItem( TOKEN_KEY );
						state.token = null;
						appendMessage( 'agent', SC_AGENT_WIDGET.greeting );
					} );
			} else if ( 0 === messageList.children.length && ! state.historySyncing ) {
				appendMessage( 'agent', SC_AGENT_WIDGET.greeting );
			}
		}
	}

	root.appendChild( launcher );
	root.appendChild( panel );

	syncHistory();

	setTimeout( maybeShowGreeting, Math.max( 0, 1000 * ( SC_AGENT_WIDGET.proactiveDelaySeconds || 8 ) ) );
} )();
