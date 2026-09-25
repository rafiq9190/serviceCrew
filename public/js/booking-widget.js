/**
 * [service_crew_services] column browser: Service -> Sub-service -> Add-ons,
 * with an accumulating cart-style Total column. Reads its data from the
 * <script type="application/json"> tag
 * class-service-crew-services-shortcode.php embeds in each
 * .sc-booking-widget instance — the tree plus the site-wide tax rate/mode,
 * advance-payment discount tiers and minimum-deposit brackets, so the Total
 * column's summary can preview subtotal/discount/tax/minimum-deposit/total
 * without its own request. This is a live price *estimate* for browsing only
 * (no submit action) — the plan's authoritative POST /calculate-price
 * belongs to the not-yet-built booking backend (Phase 1b-2); the discount
 * preview specifically assumes paying 100% now, since the real
 * percent-paid-now choice is a checkout-time decision this widget has no
 * checkout step to make.
 *
 * Selection model: at most one leaf is ever "active" (being configured —
 * qty stepper / add-ons live-editable). Navigating to any other node
 * (a different leaf, or drilling into a category) commits the active leaf
 * as its own line in the cart and starts a fresh active selection — picks
 * are additive, never replace an earlier pick, per the confirmed behavior.
 * A category's hover-preview flyout shows its *entire* sub-tree at once
 * (nested, indented — not just the immediate children), so hovering once
 * tells the customer everything underneath, and every node in it is
 * directly clickable — selectPreviewPath() applies the whole
 * hovered-parent-through-clicked-node chain in one step, so picking/drilling
 * to any depth never requires first clicking into an intermediate column.
 *
 * Also exposes window.SCBookingWidget (init() plus the pure pricing/tax/
 * discount math) so booking-flow.js's multi-step booking shell can embed
 * this exact browser as its Step 1 and reuse the same math for its Payment
 * step's order summary, instead of a second copy of either.
 */
( function () {
	'use strict';

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

	function formatMoney( amount ) {
		return '$' + ( Math.round( amount * 100 ) / 100 ).toFixed( 2 );
	}

	function defaultAddonSelections( node ) {
		var addons = {};

		( node.components || [] ).forEach( function ( addon, index ) {
			addons[ index ] = {
				checked: Boolean( addon.required ),
				qty: addon.qty_min || 1,
			};
		} );

		return addons;
	}

	/**
	 * Computes one item's (cart line or active selection) price/duration and
	 * an itemized line breakdown — the one place this math happens, shared
	 * by every card the Total column renders.
	 *
	 * @param {Object} item { node, qty, addons }
	 * @return {{price: number, duration: number, lines: Array}}
	 */
	function computeItemTotal( item ) {
		var pricing     = item.node.pricing || {};
		var isPerUnit   = 'per_unit' === pricing.price_mode;
		var basePrice   = isPerUnit ? ( pricing.price || 0 ) * item.qty : ( pricing.price || 0 );
		var baseMinutes = isPerUnit ? ( pricing.duration_minutes || 0 ) * item.qty : ( pricing.duration_minutes || 0 );

		var price    = basePrice;
		var duration = baseMinutes;
		var lines    = [ {
			label: item.node.title + ( isPerUnit ? ' (' + item.qty + ' × ' + formatMoney( pricing.price || 0 ) + ')' : '' ),
			amount: basePrice,
		} ];

		( item.node.components || [] ).forEach( function ( addon, index ) {
			var sel = item.addons[ index ];

			if ( ! sel || ! sel.checked ) {
				return;
			}

			var qty    = addon.has_quantity ? sel.qty : 1;
			var amount = ( addon.unit_price || 0 ) * qty;

			price    += amount;
			duration += ( addon.unit_duration_minutes || 0 ) * qty;

			lines.push( {
				label: addon.name + ( addon.has_quantity ? ' (' + qty + ')' : '' ),
				amount: amount,
			} );
		} );

		return { price: price, duration: duration, lines: lines };
	}

	/**
	 * The best advance-payment tier that would apply at a given
	 * percent-paid-now — mirrors Service_Crew_Pricing::get_matching_tier()
	 * on the PHP side (kept in sync by hand; this widget has no submit
	 * action to call the server for, see the file banner above).
	 */
	function getMatchingTier( tiers, percentPaidNow ) {
		var best = null;

		( tiers || [] ).forEach( function ( tier ) {
			if ( tier.min_percent_paid > percentPaidNow ) {
				return;
			}

			if ( ! best || tier.min_percent_paid > best.min_percent_paid ) {
				best = tier;
			}
		} );

		return best;
	}

	/**
	 * Mirrors Service_Crew_Pricing::calculate_discount_amount().
	 */
	function computeDiscountAmount( subtotal, tier ) {
		if ( ! tier ) {
			return 0;
		}

		if ( 'fixed' === tier.discount_type ) {
			return Math.min( subtotal, tier.discount_value );
		}

		return subtotal * ( tier.discount_value / 100 );
	}

	/**
	 * Mirrors Service_Crew_Pricing::calculate_tax_amount(). 'inclusive'
	 * treats $amount as already containing tax and backs the tax portion out
	 * instead of adding it on top — the admin's "tax deduction" mode.
	 */
	function computeTaxAmount( amount, taxRatePercent, taxMode ) {
		if ( 'inclusive' === taxMode ) {
			return amount - ( amount / ( 1 + ( taxRatePercent / 100 ) ) );
		}

		return amount * ( taxRatePercent / 100 );
	}

	/**
	 * Mirrors Service_Crew_Pricing::get_matching_deposit_tier(): the
	 * bracket with the highest min_booking_amount at or below the amount.
	 */
	function getMatchingDepositTier( tiers, amount ) {
		var best = null;

		( tiers || [] ).forEach( function ( tier ) {
			if ( tier.min_booking_amount > amount ) {
				return;
			}

			if ( ! best || tier.min_booking_amount > best.min_booking_amount ) {
				best = tier;
			}
		} );

		return best;
	}

	function initWidget( root ) {
		var dataEl = root.querySelector( '.sc-booking-data' );
		var payload;

		try {
			payload = JSON.parse( dataEl ? dataEl.textContent : '{}' );
		} catch ( e ) {
			return;
		}

		var tree                = payload.tree || [];
		var taxRatePercent      = Number( payload.taxRatePercent ) || 0;
		var taxMode             = payload.taxMode || 'exclusive';
		var discountTiers       = payload.discountTiers || [];
		var minimumDepositTiers = payload.minimumDepositTiers || [];

		var container   = el( 'div', { class: 'sc-w-columns' } );
		var storageKey  = 'sc-booking-widget-state:' + ( root.id || 'default' );
		root.appendChild( container );

		var state = {
			columns: [ tree ],
			selectedPath: [],
			activeLeaf: null, // { node, qty, addons, parentTitle } — being configured, not yet committed.
			cart: [],         // committed items, same shape as activeLeaf.
		};

		/*
		 * A refresh otherwise loses every pick — this is a browsing cart, not
		 * a submitted booking, so sessionStorage (cleared when the tab
		 * closes, unlike localStorage) is the right lifetime for it.
		 *
		 * Only the customer's own choices are persisted (which node id, qty,
		 * which add-ons) — never the node/pricing data itself. `tree` below
		 * is always the copy the server just embedded on THIS page load, so
		 * it always reflects whatever the admin has saved most recently;
		 * saving/restoring full node objects would freeze prices/add-ons at
		 * whatever they were the moment the customer first picked something,
		 * silently hiding any later admin edit.
		 */
		function findInTree( nodes, id ) {
			for ( var i = 0; i < nodes.length; i++ ) {
				if ( nodes[ i ].id === id ) {
					return nodes[ i ];
				}

				if ( nodes[ i ].has_children ) {
					var found = findInTree( nodes[ i ].children, id );

					if ( found ) {
						return found;
					}
				}
			}

			return null;
		}

		function serializeItem( item ) {
			return {
				nodeId: item.node.id,
				qty: item.qty,
				addons: item.addons,
				parentTitle: item.parentTitle,
			};
		}

		/**
		 * Re-attaches a saved pick to today's node data. Returns null (drops
		 * the pick) if the node no longer exists, or has structurally
		 * changed into a category since it was picked (an admin edit, not
		 * something the customer can act on here).
		 */
		function hydrateItem( saved ) {
			if ( ! saved ) {
				return null;
			}

			var node = findInTree( tree, saved.nodeId );

			if ( ! node || node.has_children ) {
				return null;
			}

			return {
				node: node,
				qty: saved.qty,
				addons: saved.addons || {},
				parentTitle: saved.parentTitle,
			};
		}

		function saveState() {
			try {
				sessionStorage.setItem( storageKey, JSON.stringify( {
					selectedPath: state.selectedPath,
					cart: state.cart.map( serializeItem ),
					activeLeaf: state.activeLeaf ? serializeItem( state.activeLeaf ) : null,
				} ) );
			} catch ( e ) {
				// Nothing to do — persistence is a nice-to-have, not required.
			}
		}

		function loadState() {
			try {
				var raw = sessionStorage.getItem( storageKey );

				if ( ! raw ) {
					return;
				}

				var saved = JSON.parse( raw );

				if ( ! saved ) {
					return;
				}

				state.cart       = ( saved.cart || [] ).map( hydrateItem ).filter( Boolean );
				state.activeLeaf = hydrateItem( saved.activeLeaf );

				// Re-walk the saved drill-down path against today's tree,
				// stopping as soon as a step no longer resolves (the admin
				// renamed/removed/restructured something) rather than
				// restoring a stale column list.
				var path        = Array.isArray( saved.selectedPath ) ? saved.selectedPath : [];
				var columns     = [ tree ];
				var selectedPath = [];

				for ( var i = 0; i < path.length; i++ ) {
					var level = columns[ i ] || [];
					var match = level.filter( function ( n ) { return n.id === path[ i ]; } )[ 0 ];

					if ( ! match ) {
						break;
					}

					selectedPath[ i ] = match.id;

					if ( match.has_children ) {
						columns.push( match.children );
					} else {
						break;
					}
				}

				state.columns      = columns;
				state.selectedPath = selectedPath;
			} catch ( e ) {
				// Corrupt/incompatible saved state — start fresh instead of throwing.
			}
		}

		function findNode( colIndex, id ) {
			var nodes = state.columns[ colIndex ] || [];
			return nodes.filter( function ( n ) { return n.id === id; } )[ 0 ] || null;
		}

		function isInCart( nodeId ) {
			return state.cart.some( function ( item ) { return item.node.id === nodeId; } );
		}

		function commitActiveLeaf() {
			if ( state.activeLeaf ) {
				state.cart.push( state.activeLeaf );
				state.activeLeaf = null;
			}
		}

		/**
		 * The state mutations behind picking a node at a given column, with no
		 * render() of its own — selectNode() (a single pick) and
		 * selectPreviewPath() (a whole ancestor-chain pick made in one click,
		 * from the hover flyout) both build on this so a multi-level pick
		 * still only repaints once.
		 */
		function applySelection( colIndex, node ) {
			if ( state.activeLeaf && state.activeLeaf.node.id !== node.id ) {
				commitActiveLeaf();
			}

			state.selectedPath = state.selectedPath.slice( 0, colIndex );
			state.selectedPath[ colIndex ] = node.id;
			state.columns = state.columns.slice( 0, colIndex + 1 );

			if ( node.has_children ) {
				state.columns.push( node.children );
				state.activeLeaf = null;
			} else if ( ! state.activeLeaf || state.activeLeaf.node.id !== node.id ) {
				var parent = colIndex > 0 ? findNode( colIndex - 1, state.selectedPath[ colIndex - 1 ] ) : null;

				state.activeLeaf = {
					node: node,
					qty: ( node.pricing && node.pricing.unit_qty_min ) || 1,
					addons: defaultAddonSelections( node ),
					parentTitle: parent ? parent.title : null,
				};
			}
		}

		function selectNode( colIndex, node ) {
			applySelection( colIndex, node );
			render();
		}

		/**
		 * Picks a node straight from its ancestor's hover-preview flyout —
		 * applies the whole chain from the hovered row down through the
		 * clicked node (which may be several levels deep, since the preview
		 * now shows the entire sub-tree at once, not just one level) in a
		 * single repaint. See renderColumn()'s preview-item click handler.
		 *
		 * @param {number} colIndex Column index the hovered (topmost) row lives in.
		 * @param {Array}  path     [hoveredNode, ..., clickedNode], root first.
		 */
		function selectPreviewPath( colIndex, path ) {
			path.forEach( function ( node, depth ) {
				applySelection( colIndex + depth, node );
			} );
			render();
		}

		/*
		 * A disabled-looking (not just inert) "−" at the minimum matters here
		 * specifically because there's no upper cap at all any more (by
		 * request) — "+" always works, so the only boundary a customer can
		 * ever hit is the floor, and it should read as a limit, not a
		 * broken button.
		 */
		function renderStepperButton( symbol, atLimit, onClick ) {
			return el( 'button', {
				type: 'button',
				class: 'sc-w-qty__btn' + ( atLimit ? ' is-disabled' : '' ),
				text: symbol,
				onClick: function () {
					if ( ! atLimit ) {
						onClick();
					}
				},
			} );
		}

		function renderQtyStepper( node ) {
			var min   = node.pricing.unit_qty_min || 1;
			var label = node.pricing.unit_label || 'unit';
			var qty   = state.activeLeaf.qty;

			return el( 'div', { class: 'sc-w-qty', onClick: function ( e ) { e.stopPropagation(); } }, [
				renderStepperButton( '−', qty <= min, function () {
					state.activeLeaf.qty = Math.max( min, state.activeLeaf.qty - 1 );
					render();
				} ),
				el( 'span', { class: 'sc-w-qty__value', text: String( qty ) } ),
				renderStepperButton( '+', false, function () {
					state.activeLeaf.qty += 1;
					render();
				} ),
				el( 'span', { class: 'sc-w-qty__label', text: label + ( 1 === qty ? '' : 's' ) } ),
			] );
		}

		function renderAddonQtyStepper( addon, index, sel ) {
			var min = addon.qty_min || 1;

			return el( 'div', { class: 'sc-w-qty sc-w-qty--addon' }, [
				renderStepperButton( '−', sel.qty <= min, function () {
					sel.qty = Math.max( min, sel.qty - 1 );
					render();
				} ),
				el( 'span', { class: 'sc-w-qty__value', text: String( sel.qty ) } ),
				renderStepperButton( '+', false, function () {
					sel.qty += 1;
					render();
				} ),
			] );
		}

		/**
		 * A category's hover preview: its *entire* sub-tree, nested and
		 * indented one level per depth, so hovering once shows every
		 * sub-service underneath rather than just the immediate children —
		 * every row (at any depth) is directly clickable via
		 * selectPreviewPath(), which replays the full ancestorPath-plus-this-
		 * node chain in a single repaint.
		 *
		 * @param {Array}  nodes        Nodes to render at this level (a parent's `.children`).
		 * @param {Array}  ancestorPath Path from the originally-hovered node down to and including `nodes`' own parent.
		 * @param {number} colIndex     Column index the originally-hovered row lives in.
		 * @return {HTMLElement}
		 */
		function buildPreviewTree( nodes, ancestorPath, colIndex ) {
			var isRoot = 1 === ancestorPath.length;
			var list   = el( 'div', { class: isRoot ? 'sc-w-preview' : 'sc-w-preview__sublist' } );

			nodes.forEach( function ( node ) {
				var currentPath = ancestorPath.concat( [ node ] );
				var hasKids     = node.has_children && node.children && node.children.length > 0;

				var previewRow = el( 'div', { class: 'sc-w-preview__item is-selectable' }, [
					el( 'span', { class: 'sc-w-preview__item-name', text: node.title } ),
					hasKids ? el( 'span', { class: 'sc-w-preview__item-arrow', text: '›' } ) : null,
				] );

				previewRow.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					selectPreviewPath( colIndex, currentPath );
				} );

				var itemWrap = el( 'div', { class: 'sc-w-preview__node' }, [ previewRow ] );

				if ( hasKids ) {
					itemWrap.appendChild( buildPreviewTree( node.children, currentPath, colIndex ) );
				}

				list.appendChild( itemWrap );
			} );

			return list;
		}

		function renderColumn( nodes, colIndex, headerLabel ) {
			var column = el( 'div', { class: 'sc-w-column' } );

			if ( headerLabel ) {
				column.appendChild( el( 'div', { class: 'sc-w-column__header', text: headerLabel } ) );
			}

			nodes.forEach( function ( node ) {
				var isSelected  = state.selectedPath[ colIndex ] === node.id;
				var isLeafActive = Boolean( state.activeLeaf ) && state.activeLeaf.node.id === node.id;
				var hasComponents = ! node.has_children && node.components && node.components.length > 0;

				// A top-level leaf's add-ons get their own column 2 (see
				// render()); only a leaf found deeper (colIndex > 0) expands
				// its add-ons inline under its own row.
				var expandInline = isLeafActive && node.components.length > 0 && colIndex > 0;
				var row = el( 'div', { class: 'sc-w-row' + ( isSelected ? ' is-selected' : '' ) + ( expandInline ? ' is-expanded' : '' ) } );

				var nameParts = [
					el( 'span', { class: 'sc-w-row__name', text: node.title } ),
				];

				// Identifies, at a glance, what selecting this row will
				// reveal — "Sub-services" (with the prominent chevron
				// affordance) or "Add-ons" — since a leaf with add-ons no
				// longer gets a hover preview (see the "no hover preview for
				// add-ons" note below), this badge is its only static hint.
				if ( node.has_children ) {
					nameParts.push( el( 'span', { class: 'sc-w-row__type-badge sc-w-row__type-badge--sub', text: 'Sub-services' } ) );
					nameParts.push( el( 'span', { class: 'sc-w-row__arrow', text: '›' } ) );
				} else if ( hasComponents ) {
					nameParts.push( el( 'span', { class: 'sc-w-row__type-badge sc-w-row__type-badge--addon', text: 'Add-ons' } ) );
				}

				if ( ! node.has_children && isInCart( node.id ) ) {
					nameParts.push( el( 'span', { class: 'sc-w-row__added', text: '✓ Added' } ) );
				}

				row.appendChild( el( 'div', { class: 'sc-w-row__label', onClick: function () { selectNode( colIndex, node ); } }, nameParts ) );

				// Hover preview only for a category's own children — real,
				// selectable tree nodes. A leaf's add-ons are never shown on
				// hover (by request): they only appear once the leaf is
				// actually selected (inline / the add-ons panel), not before.
				if ( node.has_children && node.children.length > 0 ) {
					var preview = buildPreviewTree( node.children, [ node ], colIndex );

					row.appendChild( preview );
					row.addEventListener( 'mouseenter', function () { row.classList.add( 'is-hovering' ); } );
					row.addEventListener( 'mouseleave', function () { row.classList.remove( 'is-hovering' ); } );
				}

				if ( isLeafActive && node.pricing && 'per_unit' === node.pricing.price_mode ) {
					row.appendChild( renderQtyStepper( node ) );
				}

				if ( expandInline ) {
					row.appendChild( renderInlineAddons( node ) );
				}

				column.appendChild( row );
			} );

			return column;
		}

		/**
		 * Builds one add-on's row (checkbox, price, required badge, its own
		 * qty stepper) — shared by both places add-ons can appear: inline
		 * under a sub-service's row (renderInlineAddons()) and as column 2's
		 * own content for a top-level leaf (renderAddonsPanel()).
		 */
		function buildAddonRow( addon, index ) {
			var sel = state.activeLeaf.addons[ index ];

			var checkbox = el( 'input', { type: 'checkbox' } );
			checkbox.checked  = sel.checked;
			checkbox.disabled = Boolean( addon.required );
			checkbox.addEventListener( 'change', function () {
				sel.checked = checkbox.checked;
				render();
			} );

			var priceText = '+' + formatMoney( addon.unit_price ) + ( addon.has_quantity ? ' / ' + ( addon.unit_label || 'unit' ) : '' );

			var row = el( 'div', { class: 'sc-w-row sc-w-row--addon' } );

			row.appendChild( el( 'label', { class: 'sc-w-addon-label' }, [
				checkbox,
				el( 'span', { class: 'sc-w-row__name', text: addon.name } ),
				el( 'span', { class: 'sc-w-addon-price', text: priceText } ),
			] ) );

			if ( addon.required ) {
				row.appendChild( el( 'span', { class: 'sc-w-badge', text: 'Required' } ) );
			}

			if ( addon.has_quantity && sel.checked ) {
				row.appendChild( renderAddonQtyStepper( addon, index, sel ) );
			}

			return row;
		}

		/**
		 * Add-ons for a sub-service (colIndex > 0), expanded inline directly
		 * under its own row (accordion-style) rather than as a separate
		 * column — the column stays put and grows taller instead of the
		 * layout gaining a 4th column.
		 */
		function renderInlineAddons( leafNode ) {
			var wrap = el( 'div', { class: 'sc-w-addons-inline', onClick: function ( e ) { e.stopPropagation(); } } );

			leafNode.components.forEach( function ( addon, index ) {
				wrap.appendChild( buildAddonRow( addon, index ) );
			} );

			return wrap;
		}

		/**
		 * Add-ons for a top-level leaf (no sub-services of its own) — these
		 * get column 2 to themselves, since there's nothing else to show
		 * there (no sub-services column needed).
		 */
		function renderAddonsPanel( leafNode, headerLabel ) {
			var column = el( 'div', { class: 'sc-w-column sc-w-column--addons' } );

			column.appendChild( el( 'div', { class: 'sc-w-column__header', text: headerLabel } ) );

			leafNode.components.forEach( function ( addon, index ) {
				column.appendChild( buildAddonRow( addon, index ) );
			} );

			return column;
		}

		/**
		 * Stand-in for the middle column before anything is picked — keeps
		 * the three-column layout (see render()'s banner comment) even at
		 * the very start, so the Total column doesn't stretch to fill the
		 * empty middle slot only to snap back narrower the moment a service
		 * is chosen.
		 */
		function renderPlaceholderColumn() {
			var column = el( 'div', { class: 'sc-w-column sc-w-placeholder' } );

			column.appendChild( el( 'p', {
				class: 'sc-w-placeholder__text',
				text: 'Choose a service on the left to see its sub-services and add-ons here.',
			} ) );

			return column;
		}

		function renderCartCard( item, isActive ) {
			var computed = computeItemTotal( item );

			var header = el( 'div', { class: 'sc-w-cart-card__header' } );

			if ( item.parentTitle ) {
				header.appendChild( el( 'span', { class: 'sc-w-cart-card__parent', text: item.parentTitle } ) );
			}

			header.appendChild( el( 'span', { class: 'sc-w-cart-card__name', text: item.node.title } ) );

			header.appendChild( el( 'button', {
				type: 'button',
				class: 'sc-w-cart-card__remove',
				text: '×',
				onClick: function () {
					if ( isActive ) {
						state.activeLeaf = null;
					} else {
						var index = state.cart.indexOf( item );
						if ( index > -1 ) {
							state.cart.splice( index, 1 );
						}
					}
					render();
				},
			} ) );

			var card = el( 'div', { class: 'sc-w-cart-card' + ( isActive ? ' is-active' : '' ) }, [ header ] );

			computed.lines.forEach( function ( line ) {
				card.appendChild( el( 'div', { class: 'sc-w-total__line' }, [
					el( 'span', { class: 'sc-w-total__line-label', text: line.label } ),
					el( 'span', { class: 'sc-w-total__line-amount', text: formatMoney( line.amount ) } ),
				] ) );
			} );

			return { card: card, price: computed.price };
		}

		/**
		 * The always-visible summary pinned under the (independently
		 * scrolling) cart-card list — see renderTotalColumn(). Line items:
		 * subtotal, the best advance-payment discount if the customer paid
		 * in full today (a preview only; the real percent-paid-now choice
		 * happens at checkout, not built yet), tax at the admin's site-wide
		 * rate/mode, the grand total, then the minimum deposit required to
		 * book at all (looked up from the admin's booking-amount brackets).
		 * No duration line — dropped by request.
		 */
		function renderTotalSummary( subtotal ) {
			var tier           = getMatchingTier( discountTiers, 100 );
			var discountAmount = computeDiscountAmount( subtotal, tier );
			var afterDiscount  = Math.max( 0, subtotal - discountAmount );
			var taxAmount      = computeTaxAmount( afterDiscount, taxRatePercent, taxMode );
			// Inclusive mode: the tax portion is already inside afterDiscount,
			// so it's reported as a deduction, not added again.
			var grandTotal     = 'inclusive' === taxMode ? afterDiscount : afterDiscount + taxAmount;

			var depositTier    = getMatchingDepositTier( minimumDepositTiers, grandTotal );
			var depositPercent = depositTier ? depositTier.deposit_percent : 0;
			var depositAmount  = grandTotal * ( depositPercent / 100 );

			var summary = el( 'div', { class: 'sc-w-total__summary' } );

			summary.appendChild( el( 'div', { class: 'sc-w-total__row' }, [
				el( 'span', { text: 'Subtotal' } ),
				el( 'span', { text: formatMoney( subtotal ) } ),
			] ) );

			if ( discountAmount > 0 ) {
				summary.appendChild( el( 'div', { class: 'sc-w-total__row sc-w-total__row--discount' }, [
					el( 'span', { text: 'Discount (pay 100% now)' } ),
					el( 'span', { text: '-' + formatMoney( discountAmount ) } ),
				] ) );
			}

			if ( taxRatePercent > 0 ) {
				summary.appendChild( el( 'div', { class: 'sc-w-total__row' }, [
					el( 'span', { text: 'Tax (' + taxRatePercent + '%' + ( 'inclusive' === taxMode ? ', included' : '' ) + ')' } ),
					el( 'span', { text: formatMoney( taxAmount ) } ),
				] ) );
			}

			summary.appendChild( el( 'div', { class: 'sc-w-total__grand' }, [
				el( 'span', { text: 'Total' } ),
				el( 'span', { text: formatMoney( grandTotal ) } ),
			] ) );

			if ( depositAmount > 0 ) {
				summary.appendChild( el( 'div', { class: 'sc-w-total__row sc-w-total__row--deposit' }, [
					el( 'span', { text: 'Minimum to book (' + depositPercent + '%)' } ),
					el( 'span', { text: formatMoney( depositAmount ) } ),
				] ) );
			}

			return summary;
		}

		function renderTotalColumn() {
			var column = el( 'div', { class: 'sc-w-column sc-w-total' } );
			var scroll = el( 'div', { class: 'sc-w-total__scroll' } );

			scroll.appendChild( el( 'h3', { class: 'sc-w-total__heading', text: 'Your selections' } ) );
			column.appendChild( scroll );

			var items = state.cart.map( function ( item ) { return { item: item, active: false }; } );

			if ( state.activeLeaf ) {
				items.push( { item: state.activeLeaf, active: true } );
			}

			if ( ! items.length ) {
				scroll.appendChild( el( 'p', { class: 'sc-w-total__empty', text: 'Select a service to see pricing.' } ) );
				return column;
			}

			var subtotal = 0;

			items.forEach( function ( entry ) {
				var rendered = renderCartCard( entry.item, entry.active );
				subtotal += rendered.price;
				scroll.appendChild( rendered.card );
			} );

			column.appendChild( renderTotalSummary( subtotal ) );

			return column;
		}

		/*
		 * Exactly three columns, always: Services, a single middle column,
		 * then the cart — Total never stretches into an empty middle slot,
		 * even before anything is picked. The middle column is exactly one
		 * of three things:
		 *  - if the selected top-level service HAS sub-services: the deepest
		 *    sub-services level currently drilled into (state.columns can
		 *    grow past two entries internally for nested categories, but
		 *    only the first and last are ever rendered — drilling one level
		 *    deeper replaces this column's contents in place, never adds a
		 *    4th column). A sub-service's own add-ons expand inline under
		 *    its row within this same column (renderInlineAddons()).
		 *  - if the selected top-level service is itself a leaf with add-ons
		 *    (no sub-services): those add-ons get this column to themselves
		 *    (renderAddonsPanel()), since there's no sub-services list to
		 *    show there instead.
		 *  - if nothing has been picked at all yet: a placeholder
		 *    (renderPlaceholderColumn()), so the layout doesn't jump from
		 *    two columns to three the moment the customer picks something.
		 */
		function render() {
			container.innerHTML = '';

			container.appendChild( renderColumn( state.columns[ 0 ], 0, 'Services' ) );

			if ( state.columns.length > 1 ) {
				var lastIndex   = state.columns.length - 1;
				var parent      = findNode( lastIndex - 1, state.selectedPath[ lastIndex - 1 ] );
				var headerLabel = parent ? 'Sub-services of ' + parent.title : 'Sub-services';

				container.appendChild( renderColumn( state.columns[ lastIndex ], lastIndex, headerLabel ) );
			} else if ( state.activeLeaf && state.activeLeaf.node.components.length ) {
				container.appendChild( renderAddonsPanel( state.activeLeaf.node, 'Add-ons for ' + state.activeLeaf.node.title ) );
			} else if ( ! state.activeLeaf ) {
				// Nothing picked yet at all — hold the middle column's place
				// so Total doesn't stretch into it (see renderPlaceholderColumn()).
				container.appendChild( renderPlaceholderColumn() );
			}

			container.appendChild( renderTotalColumn() );
			saveState();
		}

		loadState();
		render();

		/*
		 * Public handle for an embedding page (booking-flow.js's Step 1) to
		 * read the customer's picks without duplicating any of the tree/cart
		 * logic above. commitActiveLeaf() folds in whatever the customer was
		 * still configuring (qty/add-ons) at the moment they moved on, same
		 * as navigating away from it inside the widget itself would.
		 */
		return {
			getCart: function () {
				commitActiveLeaf();
				render();
				return state.cart.slice();
			},
		};
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice.call( document.querySelectorAll( '.sc-booking-widget' ) ).forEach( initWidget );
	} );

	/*
	 * Exposes the tree-browser/pricing internals to booking-flow.js (the
	 * multi-step booking shell) so its own Step 1 (service selection) and
	 * Payment-step order summary reuse this exact math instead of a second,
	 * driftable copy of it.
	 */
	window.SCBookingWidget = {
		init: initWidget,
		formatMoney: formatMoney,
		computeItemTotal: computeItemTotal,
		getMatchingTier: getMatchingTier,
		computeDiscountAmount: computeDiscountAmount,
		computeTaxAmount: computeTaxAmount,
		getMatchingDepositTier: getMatchingDepositTier,
	};
} )();
