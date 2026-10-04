/**
 * Agent screen: mode/greeting/tone/confidence/content-source/sales-nudge/
 * legacy-widget-suppression settings (/service-crew/v1/agent-settings,
 * class-service-crew-agent-settings.php), push notifications (Phase D), the
 * knowledge-base manager (/service-crew/v1/agent/kb,
 * class-service-crew-agent-controller.php), the "Pending escalations" inbox
 * (/service-crew/v1/agent/escalations[/{id}/answer]), and a read-only
 * "Conversations" log (/service-crew/v1/agent/sessions[/{id}/messages] —
 * Phase E).
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'agent' !== root.getAttribute( 'data-view' ) ) {
		return;
	}

	function load() {
		Promise.all( [
			SCApp.request( { path: '/service-crew/v1/agent-settings' } ),
			SCApp.request( { path: '/service-crew/v1/agent/kb' } ),
			SCApp.request( { path: '/service-crew/v1/agent/escalations' } ),
			SCApp.request( { path: '/service-crew/v1/agent/sessions' } ),
		] ).then( function ( results ) {
			render( results[ 0 ], results[ 1 ] || [], results[ 2 ] || [], results[ 3 ] || [] );
		} );
	}

	function buildSettingsCard( settings ) {
		var card = SCApp.sectionCard( 'dashicons-format-chat', 'Mode & setup', 'Turn the chat agent on and tune how it greets visitors and decides when to answer confidently.', true );

		var enabledToggle = SCApp.el( 'input', { type: 'checkbox' } );
		if ( settings.mode_enabled ) {
			enabledToggle.setAttribute( 'checked', 'checked' );
		}
		card.appendChild( SCApp.el( 'label', { class: 'sc-toggle', style: 'margin-bottom: 14px;' }, [
			enabledToggle,
			SCApp.el( 'span', { text: 'Show the chat agent on the site' } ),
		] ) );

		var suppressToggle = SCApp.el( 'input', { type: 'checkbox' } );
		if ( settings.suppress_legacy_widgets ) {
			suppressToggle.setAttribute( 'checked', 'checked' );
		}
		card.appendChild( SCApp.el( 'label', { class: 'sc-toggle', style: 'margin-bottom: 6px;' }, [
			suppressToggle,
			SCApp.el( 'span', { text: 'Replace the [service_crew_services]/[service_crew_booking] shortcodes with the chat agent' } ),
		] ) );
		card.appendChild( SCApp.el( 'p', { class: 'sc-help', style: 'margin-bottom: 14px;', text: 'Only takes effect while the agent is also on above — a page using either shortcode will show nothing to visitors (a short note instead, for admins) until the agent is enabled.' } ) );

		var greetingInput = SCApp.el( 'textarea', { class: 'sc-input', rows: '2', text: settings.greeting } );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Greeting message' } ),
			greetingInput,
		] ) );

		var toneSelect = SCApp.el( 'select', { class: 'sc-input' }, [
			SCApp.el( 'option', { value: 'friendly', text: 'Friendly' } ),
			SCApp.el( 'option', { value: 'professional', text: 'Professional' } ),
			SCApp.el( 'option', { value: 'concise', text: 'Concise' } ),
		] );
		toneSelect.value = settings.tone;
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Tone' } ),
			toneSelect,
		] ) );

		var confidentInput = SCApp.el( 'input', { type: 'number', min: '0', max: '1', step: '0.05', class: 'sc-input', value: settings.confident_threshold } );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Confident-answer threshold (0-1)' } ),
			confidentInput,
			SCApp.el( 'p', { class: 'sc-help', text: 'A match scoring at or above this answers automatically. Lower = more auto-answers, but more guesses.' } ),
		] ) );

		var plausibleInput = SCApp.el( 'input', { type: 'number', min: '0', max: '1', step: '0.05', class: 'sc-input', value: settings.plausible_threshold } );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Minimum plausible match (0-1)' } ),
			plausibleInput,
			SCApp.el( 'p', { class: 'sc-help', text: 'Below this, the agent gives up and escalates rather than guessing.' } ),
		] ) );

		var delayInput = SCApp.el( 'input', { type: 'number', min: '0', class: 'sc-input', value: settings.proactive_delay_seconds } );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Proactive greeting delay (seconds)' } ),
			delayInput,
		] ) );

		var contentToggle = SCApp.el( 'input', { type: 'checkbox' } );
		if ( settings.learn_from_content ) {
			contentToggle.setAttribute( 'checked', 'checked' );
		}
		card.appendChild( SCApp.el( 'label', { class: 'sc-toggle', style: 'margin: 14px 0;' }, [
			contentToggle,
			SCApp.el( 'span', { text: 'Also learn FAQs from published Pages/Posts' } ),
		] ) );
		card.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'Looks only for headings phrased as questions (e.g. "What are your business hours?") plus the text under them — other page content is never used. Note: a page gated by a separate membership/restriction plugin is still read, since this reads the saved content directly rather than the page as rendered.' } ) );

		var nudgeToggle = SCApp.el( 'input', { type: 'checkbox' } );
		if ( settings.sales_nudge_enabled ) {
			nudgeToggle.setAttribute( 'checked', 'checked' );
		}
		card.appendChild( SCApp.el( 'label', { class: 'sc-toggle', style: 'margin: 14px 0 6px;' }, [
			nudgeToggle,
			SCApp.el( 'span', { text: 'Nudge toward booking/a quote after answering' } ),
		] ) );
		var nudgeTextInput = SCApp.el( 'textarea', { class: 'sc-input', rows: '2', text: settings.sales_nudge_text } );
		card.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Nudge text (appended after a confident answer)' } ),
			nudgeTextInput,
		] ) );

		card.appendChild( SCApp.el( 'div', { class: 'sc-app-form__actions' }, [
			SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--primary',
				text: 'Save settings',
				onClick: function () {
					SCApp.request( {
						path: '/service-crew/v1/agent-settings',
						method: 'PUT',
						data: {
							mode_enabled: enabledToggle.checked,
						suppress_legacy_widgets: suppressToggle.checked,
							greeting: greetingInput.value,
							tone: toneSelect.value,
							confident_threshold: parseFloat( confidentInput.value ) || 0,
							plausible_threshold: parseFloat( plausibleInput.value ) || 0,
							proactive_delay_seconds: parseInt( delayInput.value, 10 ) || 0,
							learn_from_content: contentToggle.checked,
							sales_nudge_enabled: nudgeToggle.checked,
							sales_nudge_text: nudgeTextInput.value,
						},
					} ).then( function () {
						SCApp.toast( 'Agent settings saved.' );
					} );
				},
			} ),
		] ) );

		return card;
	}

	function buildKbRow( entry, onChange ) {
		var questionInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: entry.question || '' } );
		var answerInput = SCApp.el( 'textarea', { class: 'sc-input', rows: '2', text: entry.answer || '' } );
		var keywordsInput = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: entry.keywords || '' } );
		var activeToggle = SCApp.el( 'input', { type: 'checkbox' } );
		if ( false !== entry.is_active ) {
			activeToggle.setAttribute( 'checked', 'checked' );
		}

		var row = SCApp.el( 'div', { class: 'sc-tier-row sc-agent-kb-row' } );

		row.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Question' } ), questionInput ] ) );
		row.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Answer' } ), answerInput ] ) );
		row.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Keywords (comma-separated, optional)' } ), keywordsInput ] ) );

		var metaBits = [];
		if ( entry.source ) {
			metaBits.push( 'learned' === entry.source ? 'Learned from an escalation' : 'Manually added' );
		}
		if ( entry.hit_count ) {
			metaBits.push( entry.hit_count + ' use' + ( 1 === entry.hit_count ? '' : 's' ) );
		}
		if ( metaBits.length ) {
			row.appendChild( SCApp.el( 'p', { class: 'sc-help', text: metaBits.join( ' · ' ) } ) );
		}

		row.appendChild( SCApp.el( 'label', { class: 'sc-toggle' }, [ activeToggle, SCApp.el( 'span', { text: 'Active' } ) ] ) );

		var actions = SCApp.el( 'div', { class: 'sc-app-form__actions' } );

		actions.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary',
			text: entry.id ? 'Save' : 'Add',
			onClick: function () {
				var payload = {
					question: questionInput.value,
					answer: answerInput.value,
					keywords: keywordsInput.value,
					is_active: activeToggle.checked,
				};

				var request = entry.id
					? SCApp.request( { path: '/service-crew/v1/agent/kb/' + entry.id, method: 'PUT', data: payload } )
					: SCApp.request( { path: '/service-crew/v1/agent/kb', method: 'POST', data: payload } );

				request.then( function () {
					SCApp.toast( entry.id ? 'Entry updated.' : 'Entry added.' );
					onChange();
				} );
			},
		} ) );

		if ( entry.id ) {
			actions.appendChild( SCApp.el( 'button', {
				type: 'button',
				class: 'sc-link-danger',
				text: 'Delete',
				onClick: function () {
					SCApp.request( { path: '/service-crew/v1/agent/kb/' + entry.id, method: 'DELETE' } ).then( function () {
						SCApp.toast( 'Entry deleted.' );
						onChange();
					} );
				},
			} ) );
		} else {
			actions.appendChild( SCApp.el( 'button', {
				type: 'button',
				class: 'sc-link-danger',
				text: 'Cancel',
				onClick: function () {
					if ( row.parentNode ) {
						row.parentNode.removeChild( row );
					}
				},
			} ) );
		}

		row.appendChild( actions );

		return row;
	}

	function buildKbCard( entries ) {
		var card = SCApp.sectionCard( 'dashicons-info', 'Knowledge base', "What the agent knows — one row per question/answer pair it can match a visitor's message against.", true );

		var list = SCApp.el( 'div', { class: 'sc-tier-list' } );
		entries.forEach( function ( entry ) {
			list.appendChild( buildKbRow( entry, load ) );
		} );
		card.appendChild( list );

		card.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary',
			text: '+ Add an entry',
			onClick: function () {
				list.appendChild( buildKbRow( {}, load ) );
			},
		} ) );

		return card;
	}

	function urlBase64ToUint8Array( base64String ) {
		var padding = '='.repeat( ( 4 - base64String.length % 4 ) % 4 );
		var base64 = ( base64String + padding ).replace( /-/g, '+' ).replace( /_/g, '/' );
		var rawData = window.atob( base64 );
		var outputArray = new Uint8Array( rawData.length );
		for ( var i = 0; i < rawData.length; i++ ) {
			outputArray[ i ] = rawData.charCodeAt( i );
		}
		return outputArray;
	}

	/**
	 * "Enable notifications" card — Phase D. Push requires HTTPS (or
	 * literally "localhost") in every browser, so this card degrades to a
	 * plain explanatory message rather than a broken button wherever that
	 * isn't the case (e.g. this plugin's own local dev site, served over
	 * plain http on a non-localhost hostname).
	 */
	function buildPushCard() {
		var card = SCApp.sectionCard( 'dashicons-megaphone', 'Push notifications', 'Get a browser notification on this device the moment a chat visitor needs a human answer.', true );

		var status = SCApp.el( 'p', { class: 'sc-help', text: 'Checking status…' } );
		card.appendChild( status );

		if ( ! ( 'serviceWorker' in navigator && 'PushManager' in window ) ) {
			status.textContent = 'Push notifications are not supported in this browser.';
			return card;
		}

		if ( 'https:' !== window.location.protocol && 'localhost' !== window.location.hostname ) {
			status.textContent = 'Push notifications need this site to be served over HTTPS.';
			return card;
		}

		var button = SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: 'Enable notifications' } );
		card.appendChild( button );

		function refreshStatus() {
			navigator.serviceWorker.getRegistration( '/sc-agent-sw.js' ).then( function ( registration ) {
				if ( ! registration ) {
					status.textContent = 'Not enabled on this device.';
					button.textContent = 'Enable notifications';
					return;
				}
				registration.pushManager.getSubscription().then( function ( subscription ) {
					status.textContent = subscription ? 'Enabled on this device.' : 'Not enabled on this device.';
					button.textContent = subscription ? 'Disable notifications' : 'Enable notifications';
				} );
			} );
		}

		button.addEventListener( 'click', function () {
			if ( 'Disable notifications' === button.textContent ) {
				navigator.serviceWorker.getRegistration( '/sc-agent-sw.js' ).then( function ( registration ) {
					if ( ! registration ) {
						return;
					}
					registration.pushManager.getSubscription().then( function ( subscription ) {
						if ( subscription ) {
							subscription.unsubscribe().then( refreshStatus );
						}
					} );
				} );
				return;
			}

			navigator.serviceWorker.register( '/sc-agent-sw.js' ).then( function ( registration ) {
				return SCApp.request( { path: '/service-crew/v1/agent/push-vapid' } ).then( function ( data ) {
					return registration.pushManager.subscribe( {
						userVisibleOnly: true,
						applicationServerKey: urlBase64ToUint8Array( data.public_key ),
					} );
				} ).then( function ( subscription ) {
					var json = subscription.toJSON();
					return SCApp.request( {
						path: '/service-crew/v1/agent/push-subscribe',
						method: 'POST',
						data: { endpoint: json.endpoint, keys: json.keys },
					} );
				} );
			} ).then( function () {
				SCApp.toast( 'Push notifications enabled on this device.' );
				refreshStatus();
			} ).catch( function () {
				SCApp.toast( 'Could not enable notifications on this device.' );
			} );
		} );

		refreshStatus();

		return card;
	}

	function buildEscalationRow( escalation, onAnswered ) {
		var answerInput = SCApp.el( 'textarea', { class: 'sc-input', rows: '2', placeholder: 'Type the answer…' } );

		var row = SCApp.el( 'div', { class: 'sc-tier-row sc-agent-escalation-row' } );

		row.appendChild( SCApp.el( 'p', { class: 'sc-agent-escalation-row__question', text: escalation.question } ) );
		row.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [
			SCApp.el( 'label', { text: 'Your answer' } ),
			answerInput,
		] ) );

		row.appendChild( SCApp.el( 'div', { class: 'sc-app-form__actions' }, [
			SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--secondary',
				text: 'Send answer',
				onClick: function () {
					var answer = answerInput.value.trim();
					if ( ! answer ) {
						return;
					}

					SCApp.request( {
						path: '/service-crew/v1/agent/escalations/' + escalation.id + '/answer',
						method: 'POST',
						data: { answer: answer },
					} ).then( function () {
						SCApp.toast( 'Answer sent — learned into the knowledge base.' );
						onAnswered();
					} );
				},
			} ),
		] ) );

		return row;
	}

	function buildEscalationsCard( escalations ) {
		var card = SCApp.sectionCard( 'dashicons-sos', 'Pending escalations', "Questions the agent couldn't confidently answer. Answering one teaches the agent — it's saved into the knowledge base automatically.", true );

		if ( ! escalations.length ) {
			card.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'Nothing waiting — the agent has answered everything it was asked.' } ) );
			return card;
		}

		var list = SCApp.el( 'div', { class: 'sc-tier-list' } );
		escalations.forEach( function ( escalation ) {
			list.appendChild( buildEscalationRow( escalation, load ) );
		} );
		card.appendChild( list );

		return card;
	}

	/**
	 * One session's row — collapsed to who/how-many-messages/last-message
	 * preview, expanding to the full transcript on demand (fetched once,
	 * cached in the DOM rather than re-requested on every toggle).
	 *
	 * @param {Object} session One item from GET /agent/sessions.
	 * @return {HTMLElement}
	 */
	function buildConversationRow( session ) {
		var who = session.visitor_name || session.visitor_email || ( 'Visitor #' + session.id );
		var count = session.message_count || 0;

		var row = SCApp.el( 'div', { class: 'sc-tier-row sc-agent-conversation-row' } );
		row.appendChild( SCApp.el( 'p', { class: 'sc-agent-conversation-row__who', text: who + ' — ' + count + ' message' + ( 1 === count ? '' : 's' ) } ) );
		row.appendChild( SCApp.el( 'p', { class: 'sc-help', text: session.last_message || '' } ) );

		var transcript = SCApp.el( 'div', { class: 'sc-agent-conversation-row__transcript', style: 'display:none;' } );
		var toggle = SCApp.el( 'button', { type: 'button', class: 'sc-btn sc-btn--secondary', text: 'View transcript' } );

		toggle.addEventListener( 'click', function () {
			var isHidden = 'none' === transcript.style.display;

			if ( isHidden && ! transcript.childNodes.length ) {
				SCApp.request( { path: '/service-crew/v1/agent/sessions/' + session.id + '/messages' } ).then( function ( messages ) {
					messages.forEach( function ( message ) {
						transcript.appendChild( SCApp.el( 'p', { class: 'sc-agent-conversation-row__msg sc-agent-conversation-row__msg--' + message.sender, text: message.sender + ': ' + message.body } ) );
					} );
				} );
			}

			transcript.style.display = isHidden ? '' : 'none';
			toggle.textContent = isHidden ? 'Hide transcript' : 'View transcript';
		} );

		row.appendChild( toggle );
		row.appendChild( transcript );

		return row;
	}

	function buildConversationsCard( sessions ) {
		var card = SCApp.sectionCard( 'dashicons-format-chat', 'Conversations', 'Recent chat sessions, newest-active first — read-only.', false );

		if ( ! sessions.length ) {
			card.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'No conversations yet.' } ) );
			return card;
		}

		var list = SCApp.el( 'div', { class: 'sc-tier-list' } );
		sessions.forEach( function ( session ) {
			list.appendChild( buildConversationRow( session ) );
		} );
		card.appendChild( list );

		return card;
	}

	function render( settings, entries, escalations, sessions ) {
		root.innerHTML = '';

		var panel = SCApp.el( 'div', { class: 'sc-app-panel sc-app-panel--agent' } );
		var grid = SCApp.el( 'div', { class: 'sc-settings-grid' } );

		grid.appendChild( buildSettingsCard( settings ) );
		grid.appendChild( buildPushCard() );
		grid.appendChild( buildEscalationsCard( escalations ) );
		grid.appendChild( buildKbCard( entries ) );
		grid.appendChild( buildConversationsCard( sessions ) );

		panel.appendChild( grid );
		root.appendChild( panel );
	}

	load();
} )();
