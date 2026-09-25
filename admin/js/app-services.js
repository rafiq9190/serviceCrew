/**
 * Services screen: an indented service list on the left, a detail/edit form
 * on the right. Talks only to /service-crew/v1/services (see
 * class-service-crew-services-controller.php); there is no server-rendered
 * fallback any more, so every interaction here is a fetch + re-render.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'sc-app-root' );

	if ( ! root || 'services' !== root.getAttribute( 'data-view' ) ) {
		return;
	}

	var state = {
		services: [],
		selectedId: null,
		isNew: false,
		newParentId: 0,
	};

	function byId( id ) {
		return state.services.filter( function ( s ) {
			return s.id === id;
		} )[ 0 ] || null;
	}

	function childrenOf( parentId ) {
		return state.services.filter( function ( s ) {
			return s.parent_id === parentId;
		} );
	}

	function descendantIdsOf( id ) {
		var ids = [];

		childrenOf( id ).forEach( function ( child ) {
			ids.push( child.id );
			ids = ids.concat( descendantIdsOf( child.id ) );
		} );

		return ids;
	}

	function load() {
		return SCApp.request( { path: '/service-crew/v1/services' } ).then( function ( services ) {
			state.services = services;
			render();
		} );
	}

	function selectService( id ) {
		state.selectedId = id;
		state.isNew = false;
		render();
	}

	function startNew( parentId ) {
		state.selectedId = null;
		state.isNew = true;
		state.newParentId = parentId || 0;
		render();
	}

	var HINT_DISMISSED_KEY = 'sc-services-hint-dismissed';

	/**
	 * A short, dismissible explainer of the model this screen enforces
	 * (category vs. leaf, flat vs. per-unit, add-ons) — there's no WP-native
	 * help tab on a custom-rendered page like this one, so it has to live
	 * in the page itself. Persists its dismissal in localStorage (not
	 * sessionStorage) so it stays gone across visits once acknowledged.
	 */
	function renderIntroHint() {
		var hint;

		try {
			if ( localStorage.getItem( HINT_DISMISSED_KEY ) ) {
				return null;
			}
		} catch ( e ) {
			// localStorage unavailable — just always show the hint, harmless.
		}

		hint = SCApp.el( 'div', { class: 'sc-app-hint' }, [
			SCApp.el( 'div', { class: 'sc-app-hint__body' }, [
				SCApp.el( 'strong', { text: 'How this works: ' } ),
				SCApp.el( 'span', {
					text: 'A service with sub-services is a category only — it has no price of its own, so set pricing on each sub-service instead. ' +
						'A service with no sub-services is priced directly: flat, or per unit (e.g. per room) with the customer choosing the quantity. ' +
						'Add optional or required extras under "Add-ons" on any priced service.',
				} ),
			] ),
			SCApp.el( 'button', {
				type: 'button',
				class: 'sc-app-hint__dismiss',
				text: 'Got it',
				onClick: function () {
					try {
						localStorage.setItem( HINT_DISMISSED_KEY, '1' );
					} catch ( e ) {
						// Nothing to persist to — the hint just reappears next render, harmless.
					}
					hint.parentNode && hint.parentNode.removeChild( hint );
				},
			} ),
		] );

		return hint;
	}

	function render() {
		root.innerHTML = '';

		var hint = renderIntroHint();

		if ( hint ) {
			root.appendChild( hint );
		}

		root.appendChild( SCApp.el( 'div', { class: 'sc-app-layout' }, [
			renderListPanel(),
			renderDetailPanel(),
		] ) );
	}

	function renderListPanel() {
		var panel = SCApp.el( 'div', { class: 'sc-app-panel sc-app-panel--list' } );

		panel.appendChild( SCApp.el( 'div', { class: 'sc-app-panel__header' }, [
			SCApp.el( 'h2', { text: 'Services' } ),
			SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--primary',
				text: '+ New service',
				onClick: function () { startNew( 0 ); },
			} ),
		] ) );

		var list = SCApp.el( 'ul', { class: 'sc-app-tree' } );
		renderTreeLevel( list, 0, 0 );

		if ( ! state.services.length ) {
			panel.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'No services yet. Create the first one to get started.' } ) );
		}

		panel.appendChild( list );

		return panel;
	}

	function renderTreeLevel( container, parentId, depth ) {
		childrenOf( parentId ).forEach( function ( service ) {
			var isActive = ! state.isNew && service.id === state.selectedId;

			var row = SCApp.el( 'button', {
				type: 'button',
				class: 'sc-app-tree__row',
				style: 'padding-left:' + ( 14 + depth * 18 ) + 'px',
				onClick: function () { selectService( service.id ); },
			}, [
				SCApp.el( 'span', { class: 'sc-app-tree__name', text: service.title } ),
				service.has_children ? SCApp.el( 'span', { class: 'sc-badge sc-badge--category', text: 'Category' } ) : null,
			] );

			container.appendChild( SCApp.el( 'li', { class: 'sc-app-tree__item' + ( isActive ? ' is-active' : '' ) }, [ row ] ) );

			if ( service.has_children ) {
				renderTreeLevel( container, service.id, depth + 1 );
			}
		} );
	}

	function renderDetailPanel() {
		var panel = SCApp.el( 'div', { class: 'sc-app-panel sc-app-panel--detail' } );

		if ( state.isNew ) {
			panel.appendChild( renderForm( {
				id: null,
				title: '',
				parent_id: state.newParentId,
				has_children: false,
				pricing: { price_mode: 'flat', price: 0, duration_minutes: 0, unit_label: '', unit_qty_min: 1 },
				components: [],
			} ) );
			return panel;
		}

		var service = byId( state.selectedId );

		if ( ! service ) {
			panel.appendChild( SCApp.el( 'div', { class: 'sc-app-empty' }, [
				SCApp.el( 'p', { text: 'Select a service from the list, or create a new one.' } ),
				SCApp.el( 'p', { class: 'sc-help', text: 'Tip: use "+ New service" for a top-level entry, or "+ Add sub-service" on an existing service’s detail view to nest one under it.' } ),
			] ) );
			return panel;
		}

		panel.appendChild( renderForm( service ) );
		return panel;
	}

	function renderParentSelect( service ) {
		var select = SCApp.el( 'select', { class: 'sc-input' } );
		select.appendChild( SCApp.el( 'option', { value: '0', text: '— No parent (standalone service) —' } ) );

		var excluded = service.id ? descendantIdsOf( service.id ).concat( [ service.id ] ) : [];

		state.services
			.filter( function ( candidate ) { return -1 === excluded.indexOf( candidate.id ); } )
			.forEach( function ( candidate ) {
				select.appendChild( SCApp.el( 'option', { value: String( candidate.id ), text: candidate.breadcrumb } ) );
			} );

		select.value = String( service.parent_id || 0 );

		return select;
	}

	function renderPricingFields( service ) {
		var pricing = service.pricing || {};
		var mode = pricing.price_mode || 'flat';

		var priceLabel    = SCApp.el( 'label', { text: 'per_unit' === mode ? 'Price per unit ($)' : 'Price ($)' } );
		var durationLabel = SCApp.el( 'label', { text: 'per_unit' === mode ? 'Duration per unit (minutes)' : 'Duration (minutes)' } );

		var unitFields = SCApp.el( 'div', { class: 'sc-unit-fields' + ( 'per_unit' === mode ? '' : ' sc-hidden' ) }, [
			SCApp.el( 'div', { class: 'sc-field' }, [
				SCApp.el( 'label', { text: 'Unit name (e.g. "room", "window")' } ),
				SCApp.el( 'input', { type: 'text', class: 'sc-input sc-input-unit-label', value: pricing.unit_label || '' } ),
			] ),
			SCApp.el( 'div', { class: 'sc-field' }, [
				SCApp.el( 'label', { text: 'Minimum quantity' } ),
				SCApp.el( 'input', { type: 'number', min: '1', class: 'sc-input sc-input-qty-min', value: pricing.unit_qty_min || 1 } ),
				SCApp.el( 'p', { class: 'sc-help', text: 'The customer’s picker starts here — no maximum.' } ),
			] ),
		] );

		var flatRadio = SCApp.el( 'input', { type: 'radio', name: 'sc_price_mode', class: 'sc-price-mode', value: 'flat' } );
		flatRadio.checked = 'flat' === mode;

		var perUnitRadio = SCApp.el( 'input', { type: 'radio', name: 'sc_price_mode', class: 'sc-price-mode', value: 'per_unit' } );
		perUnitRadio.checked = 'per_unit' === mode;

		function toggleUnitFields() {
			var isPerUnit = perUnitRadio.checked;
			unitFields.classList.toggle( 'sc-hidden', ! isPerUnit );
			priceLabel.textContent = isPerUnit ? 'Price per unit ($)' : 'Price ($)';
			durationLabel.textContent = isPerUnit ? 'Duration per unit (minutes)' : 'Duration (minutes)';
		}

		flatRadio.addEventListener( 'change', toggleUnitFields );
		perUnitRadio.addEventListener( 'change', toggleUnitFields );

		var wrap = SCApp.el( 'div', { class: 'sc-app-pricing' } );

		wrap.appendChild( SCApp.el( 'div', { class: 'sc-toggle-row' }, [
			SCApp.el( 'label', { class: 'sc-toggle' }, [ flatRadio, SCApp.el( 'span', { text: 'Flat price' } ) ] ),
			SCApp.el( 'label', { class: 'sc-toggle' }, [ perUnitRadio, SCApp.el( 'span', { text: 'Price per unit (customer picks a quantity)' } ) ] ),
		] ) );

		wrap.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ priceLabel, SCApp.el( 'input', { type: 'number', step: '0.01', min: '0', class: 'sc-input sc-input-price', value: pricing.price || 0 } ) ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ durationLabel, SCApp.el( 'input', { type: 'number', step: '1', min: '0', class: 'sc-input sc-input-duration', value: pricing.duration_minutes || 0 } ) ] ),
		] ) );

		wrap.appendChild( unitFields );

		return wrap;
	}

	function buildComponentCard( component ) {
		component = component || {};
		var hasQty = Boolean( component.has_quantity );

		var qtyWrap = SCApp.el( 'div', { class: 'sc-component-qty-fields' + ( hasQty ? '' : ' sc-hidden' ) }, [
			SCApp.el( 'div', { class: 'sc-field' }, [
				SCApp.el( 'label', { text: 'Minimum quantity' } ),
				SCApp.el( 'input', { type: 'number', min: '1', class: 'sc-input sc-comp-qty-min', value: component.qty_min || 1 } ),
				SCApp.el( 'p', { class: 'sc-help', text: 'The customer’s picker starts here — no maximum.' } ),
			] ),
		] );

		var hasQtyCheckbox = SCApp.el( 'input', { type: 'checkbox', class: 'sc-comp-has-qty' } );
		hasQtyCheckbox.checked = hasQty;
		hasQtyCheckbox.addEventListener( 'change', function () {
			qtyWrap.classList.toggle( 'sc-hidden', ! hasQtyCheckbox.checked );
		} );

		var requiredCheckbox = SCApp.el( 'input', { type: 'checkbox', class: 'sc-comp-required' } );
		requiredCheckbox.checked = Boolean( component.required );

		var card = SCApp.el( 'div', { class: 'sc-component-card' } );

		card.appendChild( SCApp.el( 'div', { class: 'sc-component-card__header' }, [
			SCApp.el( 'input', {
				type: 'text',
				class: 'sc-input sc-comp-name',
				placeholder: 'Add-on name, e.g. Extra Bathroom Cleaning',
				value: component.name || '',
			} ),
			SCApp.el( 'button', {
				type: 'button',
				class: 'sc-link-danger',
				text: 'Remove',
				onClick: function () {
					if ( card.parentNode ) {
						card.parentNode.removeChild( card );
					}
				},
			} ),
		] ) );

		card.appendChild( SCApp.el( 'div', { class: 'sc-toggle-row' }, [
			SCApp.el( 'label', { class: 'sc-toggle' }, [ requiredCheckbox, SCApp.el( 'span', { text: 'Customer must add this' } ) ] ),
			SCApp.el( 'label', { class: 'sc-toggle' }, [ hasQtyCheckbox, SCApp.el( 'span', { text: 'Let customer choose a quantity' } ) ] ),
		] ) );

		card.appendChild( SCApp.el( 'div', { class: 'sc-field-row' }, [
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Price ($)' } ), SCApp.el( 'input', { type: 'number', step: '0.01', min: '0', class: 'sc-input sc-comp-unit-price', value: component.unit_price || 0 } ) ] ),
			SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Extra time (minutes)' } ), SCApp.el( 'input', { type: 'number', step: '1', min: '0', class: 'sc-input sc-comp-unit-duration', value: component.unit_duration_minutes || 0 } ) ] ),
		] ) );

		card.appendChild( qtyWrap );

		return card;
	}

	function renderComponentsEditor( components ) {
		var wrap = SCApp.el( 'div', { class: 'sc-app-components' } );

		wrap.appendChild( SCApp.el( 'h3', { text: 'Add-ons' } ) );
		wrap.appendChild( SCApp.el( 'p', { class: 'sc-help', text: 'Extras a customer can add to this service for more money. Leave empty if this service has none.' } ) );

		var list = SCApp.el( 'div', { class: 'sc-component-list' } );
		components.forEach( function ( component ) {
			list.appendChild( buildComponentCard( component ) );
		} );
		wrap.appendChild( list );

		wrap.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--secondary',
			text: '+ Add an add-on',
			onClick: function () { list.appendChild( buildComponentCard( {} ) ); },
		} ) );

		return wrap;
	}

	function renderForm( service ) {
		var form = SCApp.el( 'form', { class: 'sc-app-form' } );

		var titleInput   = SCApp.el( 'input', { type: 'text', class: 'sc-input', value: service.title, placeholder: 'Service name' } );
		var parentSelect = renderParentSelect( service );

		form.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Name' } ), titleInput ] ) );
		form.appendChild( SCApp.el( 'div', { class: 'sc-field' }, [ SCApp.el( 'label', { text: 'Parent' } ), parentSelect ] ) );

		var liveHasChildren = service.id ? childrenOf( service.id ).length > 0 : false;
		var pricingSection  = SCApp.el( 'div', { class: 'sc-app-pricing-section' } );

		if ( liveHasChildren ) {
			pricingSection.appendChild( SCApp.el( 'p', {
				class: 'sc-notice',
				text: 'This service has sub-services, so it works as a category only. It has no price of its own — set a price on each sub-service instead.',
			} ) );
		} else {
			pricingSection.appendChild( renderPricingFields( service ) );
			pricingSection.appendChild( renderComponentsEditor( service.components || [] ) );
		}

		form.appendChild( pricingSection );

		var actions = SCApp.el( 'div', { class: 'sc-app-form__actions' } );

		actions.appendChild( SCApp.el( 'button', {
			type: 'button',
			class: 'sc-btn sc-btn--primary',
			text: service.id ? 'Save changes' : 'Create service',
			onClick: function () { save(); },
		} ) );

		if ( service.id ) {
			actions.appendChild( SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--ghost',
				text: '+ Add sub-service',
				onClick: function () { startNew( service.id ); },
			} ) );

			actions.appendChild( SCApp.el( 'button', {
				type: 'button',
				class: 'sc-btn sc-btn--danger',
				text: 'Delete',
				onClick: function () { remove( service.id ); },
			} ) );
		}

		form.appendChild( actions );

		function collectPricing() {
			var modeInput = form.querySelector( '.sc-price-mode:checked' );
			var pricing   = { price_mode: modeInput ? modeInput.value : 'flat' };

			pricing.price            = parseFloat( ( form.querySelector( '.sc-input-price' ) || {} ).value ) || 0;
			pricing.duration_minutes = parseInt( ( form.querySelector( '.sc-input-duration' ) || {} ).value, 10 ) || 0;

			if ( 'per_unit' === pricing.price_mode ) {
				pricing.unit_label   = ( form.querySelector( '.sc-input-unit-label' ) || {} ).value || '';
				pricing.unit_qty_min = parseInt( ( form.querySelector( '.sc-input-qty-min' ) || {} ).value, 10 ) || 1;
			}

			return pricing;
		}

		function collectComponents() {
			return Array.prototype.slice.call( form.querySelectorAll( '.sc-component-card' ) ).map( function ( card ) {
				return {
					name: card.querySelector( '.sc-comp-name' ).value,
					required: card.querySelector( '.sc-comp-required' ).checked,
					has_quantity: card.querySelector( '.sc-comp-has-qty' ).checked,
					qty_min: parseInt( card.querySelector( '.sc-comp-qty-min' ).value, 10 ) || 1,
					unit_price: parseFloat( card.querySelector( '.sc-comp-unit-price' ).value ) || 0,
					unit_duration_minutes: parseInt( card.querySelector( '.sc-comp-unit-duration' ).value, 10 ) || 0,
				};
			} );
		}

		function save() {
			var payload = {
				title: titleInput.value,
				parent_id: parseInt( parentSelect.value, 10 ) || 0,
				pricing: liveHasChildren ? {} : collectPricing(),
				components: liveHasChildren ? [] : collectComponents(),
			};

			var call = service.id
				? SCApp.request( { path: '/service-crew/v1/services/' + service.id, method: 'PUT', data: payload } )
				: SCApp.request( { path: '/service-crew/v1/services', method: 'POST', data: payload } );

			call.then( function ( saved ) {
				SCApp.toast( service.id ? 'Service updated.' : 'Service created.' );
				state.isNew = false;
				state.selectedId = saved.id;
				load();
			} );
		}

		function remove( id ) {
			if ( ! window.confirm( 'Delete this service?' ) ) {
				return;
			}

			SCApp.request( { path: '/service-crew/v1/services/' + id, method: 'DELETE' } ).then( function () {
				SCApp.toast( 'Service deleted.' );
				state.selectedId = null;
				load();
			} );
		}

		return form;
	}

	load();
} )();
