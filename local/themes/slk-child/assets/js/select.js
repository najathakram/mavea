/**
 * slk-dd — the house dropdown.
 *
 * The native <select> popup is drawn by the operating system: square corners,
 * a system font, a blue highlight. Nothing about it can be styled, so on this
 * site it was the one surface that broke the glass.
 *
 * This does NOT throw the native control away. Every <select> stays in the
 * form, keeps its name and value, and still emits a bubbling `change`, so
 * WooCommerce's own handlers (ordering auto-submit, country_to_state) are
 * untouched. What gets added is a button + listbox drawn in our own CSS, wired
 * to the ARIA combobox pattern so the keyboard and screen-reader behaviour the
 * platform used to give for free is given back deliberately:
 *
 *   ↓ / ↑        move the active option
 *   Home / End   first / last
 *   Enter, Space commit
 *   Esc          close, keep the previous value
 *   Tab          close and move on
 *   a-z / 0-9    type-ahead, 700ms window
 *
 * The panel is appended to <body> rather than to the field, because several of
 * these live inside panels that clip their overflow; anchoring is recomputed on
 * scroll and resize.
 */
( function () {
	'use strict';

	var TYPEAHEAD_MS = 700;
	var uid = 0;

	function isEnhanceable( select ) {
		return (
			! select.multiple &&
			select.size <= 1 &&
			! select.closest( '.slk-dd' ) &&
			! select.hasAttribute( 'data-slk-native' ) &&
			// select2/selectWoo keeps a mirror copy in the DOM; never wrap it.
			! select.classList.contains( 'select2-hidden-accessible' )
		);
	}

	function labelFor( select ) {
		var byFor = select.id && document.querySelector( 'label[for="' + CSS.escape( select.id ) + '"]' );
		return byFor || select.closest( 'label' );
	}

	function Dropdown( select ) {
		this.select = select;
		this.id = 'slk-dd-' + ++uid;
		this.open = false;
		this.activeIndex = -1;
		this.typed = '';
		this.typedAt = 0;

		this.build();
		this.sync();
		this.bind();
	}

	Dropdown.prototype.build = function () {
		var select = this.select;

		this.wrap = document.createElement( 'div' );
		this.wrap.className = 'slk-dd';

		select.parentNode.insertBefore( this.wrap, select );
		this.wrap.appendChild( select );
		select.classList.add( 'slk-dd__native' );
		select.setAttribute( 'tabindex', '-1' );
		select.setAttribute( 'aria-hidden', 'true' );

		this.button = document.createElement( 'button' );
		this.button.type = 'button';
		this.button.className = 'slk-dd__button';
		this.button.id = this.id + '-button';
		this.button.setAttribute( 'role', 'combobox' );
		this.button.setAttribute( 'aria-haspopup', 'listbox' );
		this.button.setAttribute( 'aria-expanded', 'false' );
		this.button.setAttribute( 'aria-controls', this.id + '-list' );
		if ( select.disabled ) {
			this.button.disabled = true;
		}

		var label = labelFor( select );
		if ( label ) {
			if ( ! label.id ) {
				label.id = this.id + '-label';
			}
			this.button.setAttribute( 'aria-labelledby', label.id + ' ' + this.button.id );
		} else if ( select.getAttribute( 'aria-label' ) ) {
			this.button.setAttribute( 'aria-label', select.getAttribute( 'aria-label' ) );
		}

		this.value = document.createElement( 'span' );
		this.value.className = 'slk-dd__value';
		this.button.appendChild( this.value );

		var caret = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		caret.setAttribute( 'class', 'slk-dd__caret' );
		caret.setAttribute( 'viewBox', '0 0 24 24' );
		caret.setAttribute( 'width', '15' );
		caret.setAttribute( 'height', '15' );
		caret.setAttribute( 'fill', 'none' );
		caret.setAttribute( 'stroke', 'currentColor' );
		caret.setAttribute( 'stroke-width', '1.5' );
		caret.setAttribute( 'stroke-linecap', 'round' );
		caret.setAttribute( 'stroke-linejoin', 'round' );
		caret.setAttribute( 'aria-hidden', 'true' );
		var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		path.setAttribute( 'd', 'M6 9.5 12 15.5 18 9.5' );
		caret.appendChild( path );
		this.button.appendChild( caret );

		this.wrap.appendChild( this.button );

		this.panel = document.createElement( 'div' );
		this.panel.className = 'slk-dd__panel';
		this.panel.hidden = true;

		this.list = document.createElement( 'ul' );
		this.list.className = 'slk-dd__list';
		this.list.id = this.id + '-list';
		this.list.setAttribute( 'role', 'listbox' );
		this.panel.appendChild( this.list );

		document.body.appendChild( this.panel );
	};

	/** Rebuild the options from the native select — it is the source of truth. */
	Dropdown.prototype.sync = function () {
		var self = this;
		this.list.textContent = '';
		this.options = [];

		/*
		 * WooCommerce hardcodes the empty option's text to "Select an option…"
		 * and routes the field's real placeholder into data-placeholder, which
		 * only select2 ever read. select2 is gone, so honour it here — the
		 * native option is rewritten too, so a no-JS visitor sees the same words.
		 */
		var ph = this.select.dataset.placeholder;
		var first = this.select.options[ 0 ];
		if ( ph && first && first.value === '' && first.textContent !== ph ) {
			first.textContent = ph;
		}

		Array.prototype.forEach.call( this.select.options, function ( opt, i ) {
			var li = document.createElement( 'li' );
			li.className = 'slk-dd__option';
			li.id = self.id + '-opt-' + i;
			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'aria-selected', opt.selected ? 'true' : 'false' );
			li.textContent = opt.textContent;
			if ( opt.disabled ) {
				li.setAttribute( 'aria-disabled', 'true' );
			}
			li.dataset.index = String( i );
			self.list.appendChild( li );
			self.options.push( li );
		} );

		this.paint();
	};

	/** Push the native select's current selection into the button + list. */
	Dropdown.prototype.paint = function () {
		var i = this.select.selectedIndex;
		var opt = this.select.options[ i ];
		this.value.textContent = opt ? opt.textContent : '';
		this.button.classList.toggle( 'is-placeholder', !! opt && opt.value === '' );

		for ( var n = 0; n < this.options.length; n++ ) {
			this.options[ n ].setAttribute( 'aria-selected', n === i ? 'true' : 'false' );
		}
	};

	/**
	 * Place the panel.
	 *
	 * @param {boolean} remeasure Choose a direction and lock a height. Pass true
	 *                            when opening, false when merely following a
	 *                            scroll — recomputing the height on every scroll
	 *                            event settles it against whichever viewport
	 *                            position the panel happened to be measured in,
	 *                            which left it overlapping its own button.
	 */
	Dropdown.prototype.position = function ( remeasure ) {
		var r = this.button.getBoundingClientRect();
		var margin = 8;
		var gap = 6;

		var viewport = document.documentElement.clientWidth;

		if ( remeasure ) {
			var below = window.innerHeight - r.bottom - margin;
			var above = r.top - margin;

			this.up = above > below;
			this.panel.classList.toggle( 'slk-dd__panel--up', this.up );
			// Width first: the height read below is only meaningful once the
			// panel is as wide as it will actually be drawn.
			this.panel.style.minWidth = r.width + 'px';
			this.panel.style.maxWidth = ( viewport - margin * 2 ) + 'px';
			this.panel.style.left = '0px';
			this.panel.style.maxHeight =
				Math.max( 140, Math.min( 320, this.up ? above : below ) ) + 'px';
			this.panel.style.top = '0px';
			this.height = this.panel.offsetHeight;
			this.width = this.panel.offsetWidth;
		}

		/*
		 * Both directions are placed with `top`. `bottom` looks like the natural
		 * way to hang a panel above its button, but an absolutely positioned
		 * element on <body> resolves it against the initial containing block —
		 * which is viewport-sized, not document-sized — so on a scrolled page
		 * the drop-up landed far above the field, off screen.
		 */
		// Keep the panel inside the viewport. Without this a field near the right
		// edge pushes a body-level panel past it, and the whole page gains a
		// horizontal scrollbar on a phone.
		var left = Math.min( r.left, viewport - this.width - margin );

		this.panel.style.left = Math.round( window.scrollX + Math.max( margin, left ) ) + 'px';
		this.panel.style.top = Math.round(
			this.up
				? window.scrollY + r.top - this.height - gap
				: window.scrollY + r.bottom + gap
		) + 'px';
	};

	/**
	 * Follow the button while the panel is open.
	 *
	 * Scroll and resize events are not enough on their own: checkout re-renders
	 * over AJAX, images finish loading, fonts swap — any of which moves the
	 * field without firing either event, leaving the panel anchored to where the
	 * button used to be. Comparing the rect each frame catches all of it, and
	 * the loop only runs while something is open.
	 */
	Dropdown.prototype.track = function () {
		var self = this;
		var last = '';

		var tick = function () {
			if ( ! self.open ) {
				self.raf = 0;
				return;
			}
			var r = self.button.getBoundingClientRect();
			var key = r.top + '|' + r.left + '|' + r.width;
			if ( key !== last ) {
				last = key;
				self.position( false );
			}
			self.raf = window.requestAnimationFrame( tick );
		};

		this.raf = window.requestAnimationFrame( tick );
	};

	Dropdown.prototype.setOpen = function ( next ) {
		if ( next === this.open ) {
			return;
		}
		this.open = next;
		this.button.setAttribute( 'aria-expanded', next ? 'true' : 'false' );
		this.wrap.classList.toggle( 'is-open', next );

		if ( next ) {
			this.panel.hidden = false;
			this.position( true );
			// Force a frame so the transition has a start state to run from.
			void this.panel.offsetHeight;
			this.panel.classList.add( 'is-shown' );
			this.setActive( this.select.selectedIndex, true );
			this.track();
		} else {
			if ( this.raf ) {
				window.cancelAnimationFrame( this.raf );
				this.raf = 0;
			}
			this.panel.classList.remove( 'is-shown' );
			this.button.removeAttribute( 'aria-activedescendant' );
			this.activeIndex = -1;
			var panel = this.panel;
			window.setTimeout( function () {
				if ( ! panel.classList.contains( 'is-shown' ) ) {
					panel.hidden = true;
				}
			}, 200 );
		}
	};

	Dropdown.prototype.setActive = function ( index, scroll ) {
		if ( index < 0 || index >= this.options.length ) {
			return;
		}
		if ( this.activeIndex > -1 && this.options[ this.activeIndex ] ) {
			this.options[ this.activeIndex ].classList.remove( 'is-active' );
		}
		this.activeIndex = index;
		var li = this.options[ index ];
		li.classList.add( 'is-active' );
		this.button.setAttribute( 'aria-activedescendant', li.id );

		if ( ! scroll ) {
			return;
		}

		// Scroll the panel by hand. scrollIntoView() would also scroll the
		// window to reveal the panel, which moves the button out from under a
		// panel that was just positioned against it.
		var top = li.offsetTop;
		var bottom = top + li.offsetHeight;
		if ( top < this.panel.scrollTop ) {
			this.panel.scrollTop = top;
		} else if ( bottom > this.panel.scrollTop + this.panel.clientHeight ) {
			this.panel.scrollTop = bottom - this.panel.clientHeight;
		}
	};

	/** Skip disabled entries when arrowing. */
	Dropdown.prototype.step = function ( from, delta ) {
		var i = from;
		for ( var guard = 0; guard < this.options.length; guard++ ) {
			i += delta;
			if ( i < 0 || i >= this.options.length ) {
				return -1;
			}
			if ( ! this.select.options[ i ].disabled ) {
				return i;
			}
		}
		return -1;
	};

	Dropdown.prototype.commit = function ( index ) {
		if ( index < 0 || this.select.options[ index ].disabled ) {
			return;
		}
		var changed = this.select.selectedIndex !== index;
		this.select.selectedIndex = index;
		this.paint();
		this.setOpen( false );
		this.button.focus();

		if ( changed ) {
			this.select.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			this.select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	};

	Dropdown.prototype.typeahead = function ( ch ) {
		var now = Date.now();
		this.typed = now - this.typedAt > TYPEAHEAD_MS ? ch : this.typed + ch;
		this.typedAt = now;

		var needle = this.typed.toLowerCase();
		var prefix = -1;
		var anywhere = -1;

		for ( var i = 0; i < this.options.length; i++ ) {
			if ( this.select.options[ i ].disabled ) {
				continue;
			}
			var text = this.options[ i ].textContent.trim().toLowerCase();
			var at = text.indexOf( needle );
			if ( at === 0 ) {
				prefix = i;
				break;
			}
			// A native select only matches from the start, which is no help
			// when four of five options begin "Sort by" — "p" should still
			// find popularity. Prefix wins when both exist.
			if ( at > 0 && anywhere === -1 ) {
				anywhere = i;
			}
		}

		var hit = prefix > -1 ? prefix : anywhere;
		if ( hit === -1 ) {
			return;
		}
		if ( this.open ) {
			this.setActive( hit, true );
		} else {
			this.commit( hit );
		}
	};

	Dropdown.prototype.bind = function () {
		var self = this;

		this.button.addEventListener( 'click', function () {
			self.setOpen( ! self.open );
		} );

		this.button.addEventListener( 'keydown', function ( e ) {
			var key = e.key;

			if ( key === 'Escape' ) {
				if ( self.open ) {
					e.preventDefault();
					self.setOpen( false );
				}
				return;
			}
			if ( key === 'Tab' ) {
				self.setOpen( false );
				return;
			}
			if ( key === 'ArrowDown' || key === 'ArrowUp' ) {
				e.preventDefault();
				var from = self.open ? self.activeIndex : self.select.selectedIndex;
				if ( ! self.open ) {
					self.setOpen( true );
					return;
				}
				var next = self.step( from, key === 'ArrowDown' ? 1 : -1 );
				if ( next > -1 ) {
					self.setActive( next, true );
				}
				return;
			}
			if ( key === 'Home' || key === 'End' ) {
				if ( ! self.open ) {
					return;
				}
				e.preventDefault();
				var edge = key === 'Home'
					? self.step( -1, 1 )
					: self.step( self.options.length, -1 );
				if ( edge > -1 ) {
					self.setActive( edge, true );
				}
				return;
			}
			if ( key === 'Enter' || key === ' ' || key === 'Spacebar' ) {
				e.preventDefault();
				if ( self.open ) {
					self.commit( self.activeIndex );
				} else {
					self.setOpen( true );
				}
				return;
			}
			if ( key.length === 1 && /\S/.test( key ) && ! e.metaKey && ! e.ctrlKey && ! e.altKey ) {
				e.preventDefault();
				self.typeahead( key );
			}
		} );

		this.list.addEventListener( 'click', function ( e ) {
			var li = e.target.closest( '.slk-dd__option' );
			if ( li ) {
				self.commit( Number( li.dataset.index ) );
			}
		} );

		this.list.addEventListener( 'mousemove', function ( e ) {
			var li = e.target.closest( '.slk-dd__option' );
			if ( li && Number( li.dataset.index ) !== self.activeIndex ) {
				self.setActive( Number( li.dataset.index ), false );
			}
		} );

		document.addEventListener( 'pointerdown', function ( e ) {
			if ( self.open && ! self.panel.contains( e.target ) && ! self.wrap.contains( e.target ) ) {
				self.setOpen( false );
			}
		} );

		// Scrolling only moves the panel. A resize can flip which side has room,
		// so it re-measures.
		window.addEventListener(
			'scroll',
			function () {
				if ( self.open ) {
					self.position( false );
				}
			},
			true
		);
		window.addEventListener( 'resize', function () {
			if ( self.open ) {
				self.position( true );
			}
		} );

		// WooCommerce rewrites the district list when the country changes, and
		// re-enables/disables fields; mirror both back into the button.
		new MutationObserver( function () {
			self.sync();
			self.button.disabled = self.select.disabled;
		} ).observe( self.select, { childList: true, attributes: true, attributeFilter: [ 'disabled' ] } );

		// Anything that sets .value in script (WooCommerce does) must repaint.
		this.select.addEventListener( 'change', function () {
			self.paint();
		} );
	};

	/** The panel lives on <body>, so it outlives a select that gets re-rendered. */
	Dropdown.prototype.destroy = function () {
		this.panel.remove();
	};

	var live = [];

	function enhance() {
		// Drop instances whose select left the document on an AJAX re-render,
		// otherwise their body-level panels pile up invisibly.
		live = live.filter( function ( dd ) {
			if ( document.contains( dd.select ) ) {
				return true;
			}
			dd.destroy();
			return false;
		} );

		Array.prototype.forEach.call( document.querySelectorAll( 'select' ), function ( select ) {
			if ( isEnhanceable( select ) ) {
				live.push( new Dropdown( select ) );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', enhance );
	} else {
		enhance();
	}

	/*
	 * Checkout, the cart totals and the country/district pair all re-render over
	 * AJAX. WooCommerce announces that with jQuery.trigger(), which never
	 * reaches a native addEventListener — these have to be bound through jQuery
	 * itself or the new selects stay unstyled.
	 */
	if ( window.jQuery ) {
		window.jQuery( document.body ).on(
			'updated_checkout updated_wc_div updated_shipping_method updated_cart_totals country_to_state_changed',
			enhance
		);
	}
} )();
