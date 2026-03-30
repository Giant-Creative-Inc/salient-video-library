/* global SVL */

jQuery( function( $ ) {

/**
 * Replaces native <select data-svl-filter> elements with an accessible
 * custom listbox dropdown (black/dark theme, matches gallery mosaic plugin).
 *
 * The native <select> stays visually hidden in the DOM so that the existing
 * JS can still read/set its value via .val() and .trigger("change").
 *
 * Skips selects inside hidden wrappers (e.g. locked category on archive pages).
 *
 * ARIA pattern: button (aria-haspopup="listbox") + ul (role="listbox")
 * Keyboard: Enter/Space open; arrows navigate; Enter/Space select;
 *           Escape closes; Home/End jump; Tab closes.
 */
function svlInitCustomSelects( $root ) {
	$root.find( '[data-svl-filter]' ).each( function() {
		var $native = $( this );

		// Skip selects inside hidden wrappers (locked category on archives).
		if ( $native.closest( '[hidden]' ).length || $native.prop( 'disabled' ) ) {
			return;
		}

		var origId    = $native.attr( 'id' ) || '';
		var filterKey = $native.data( 'svl-filter' );
		var listboxId = 'svl-listbox-' + filterKey;

		// Move id to trigger so <label for="..."> still points at the button.
		$native.removeAttr( 'id' );

		// Wrap and hide native select (kept for .val() reads by gallery JS).
		$native.wrap( '<div class="svl__select-wrapper"></div>' );
		var $wrapper = $native.parent();
		$native.addClass( 'svl__select--hidden' ).attr( {
			'aria-hidden': 'true',
			'tabindex':    '-1',
		} );

		// Build trigger button.
		var initialText = $native.find( 'option:selected' ).text() || $native.find( 'option:first' ).text();

		var $trigger = $( '<button type="button" class="svl__select-trigger"></button>' ).attr( {
			id:              origId,
			'aria-haspopup': 'listbox',
			'aria-expanded': 'false',
			'aria-controls': listboxId,
		} );

		$( '<span class="svl__select-value"></span>' ).text( initialText ).appendTo( $trigger );
		$( '<span class="svl__select-arrow" aria-hidden="true">' +
			'<svg xmlns="http://www.w3.org/2000/svg" width="12" height="8" viewBox="0 0 12 8" fill="none">' +
				'<path d="M1 1L6 7L11 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
			'</svg>' +
		'</span>' ).appendTo( $trigger );

		// Build listbox.
		var $listbox = $( '<ul role="listbox" class="svl__select-listbox" hidden></ul>' ).attr( 'id', listboxId );

		$native.find( 'option' ).each( function( i ) {
			var val        = $( this ).val();
			var text       = $( this ).text();
			var isSelected = $( this ).is( ':selected' );
			$( '<li role="option" class="svl__select-option"></li>' ).attr( {
				'data-value':    val,
				'aria-selected': isSelected ? 'true' : 'false',
				id:              listboxId + '-' + i,
			} ).text( text ).appendTo( $listbox );
		} );

		$wrapper.append( $trigger ).append( $listbox );

		var focusedIdx = -1;
		var isOpen     = function() { return $trigger.attr( 'aria-expanded' ) === 'true'; };
		var $opts      = function() { return $listbox.find( '[role="option"]' ); };

		function openList() {
			$trigger.attr( 'aria-expanded', 'true' );
			$listbox.prop( 'hidden', false );
			var opts   = $opts();
			var selIdx = opts.index( opts.filter( '[aria-selected="true"]' ) );
			setFocus( selIdx >= 0 ? selIdx : 0 );
		}

		function closeList() {
			$trigger.attr( { 'aria-expanded': 'false', 'aria-activedescendant': '' } );
			$listbox.prop( 'hidden', true );
			$opts().removeClass( 'svl__select-option--focused' );
			focusedIdx = -1;
		}

		function setFocus( idx ) {
			var opts = $opts();
			opts.removeClass( 'svl__select-option--focused' );
			if ( idx < 0 || idx >= opts.length ) { return; }
			var $opt  = opts.eq( idx );
			$opt.addClass( 'svl__select-option--focused' );
			$trigger.attr( 'aria-activedescendant', $opt.attr( 'id' ) || '' );
			var listEl = $listbox[ 0 ];
			var optEl  = $opt[ 0 ];
			if ( optEl.offsetTop < listEl.scrollTop ) {
				listEl.scrollTop = optEl.offsetTop;
			} else if ( optEl.offsetTop + optEl.offsetHeight > listEl.scrollTop + listEl.clientHeight ) {
				listEl.scrollTop = optEl.offsetTop + optEl.offsetHeight - listEl.clientHeight;
			}
			focusedIdx = idx;
		}

		function selectByIndex( idx ) {
			var opts = $opts();
			if ( idx < 0 || idx >= opts.length ) { return; }
			var $opt = opts.eq( idx );
			opts.attr( 'aria-selected', 'false' );
			$opt.attr( 'aria-selected', 'true' );
			$trigger.find( '.svl__select-value' ).text( $opt.text() );
			$trigger.toggleClass( 'svl__select-trigger--active', String( $opt.data( 'value' ) ) !== '0' );
			$native.val( $opt.data( 'value' ) ).trigger( 'change' );
			closeList();
			$trigger.focus();
		}

		$trigger.on( 'click', function() {
			isOpen() ? closeList() : openList();
		} );

		$trigger.on( 'keydown', function( e ) {
			var opts = $opts();
			var open = isOpen();
			switch ( e.key ) {
				case 'Enter':
				case ' ':
					e.preventDefault();
					if ( open ) { if ( focusedIdx >= 0 ) { selectByIndex( focusedIdx ); } } else { openList(); }
					break;
				case 'ArrowDown':
					e.preventDefault();
					if ( ! open ) { openList(); break; }
					setFocus( Math.min( focusedIdx + 1, opts.length - 1 ) );
					break;
				case 'ArrowUp':
					e.preventDefault();
					if ( ! open ) { openList(); break; }
					setFocus( Math.max( focusedIdx - 1, 0 ) );
					break;
				case 'Home': e.preventDefault(); if ( open ) { setFocus( 0 ); } break;
				case 'End':  e.preventDefault(); if ( open ) { setFocus( opts.length - 1 ); } break;
				case 'Escape': if ( open ) { e.preventDefault(); closeList(); } break;
				case 'Tab':    if ( open ) { closeList(); } break;
			}
		} );

		// mousedown (not click) — keeps focus on trigger and prevents premature close.
		$listbox.on( 'mousedown', function( e ) { e.preventDefault(); } );
		$listbox.on( 'mousedown', '[role="option"]', function( e ) {
			e.preventDefault();
			selectByIndex( $( this ).index() );
		} );

		$listbox.on( 'mousemove', '[role="option"]', function() {
			var idx = $( this ).index();
			if ( idx !== focusedIdx ) { setFocus( idx ); }
		} );

		// Close when focus leaves the wrapper (keyboard Tab away etc.).
		$wrapper.on( 'focusout', function() {
			setTimeout( function() {
				if ( ! $wrapper[ 0 ].contains( document.activeElement ) ) { closeList(); }
			}, 0 );
		} );

		// Close on outside click.
		$( document ).on( 'click.svl-select-' + filterKey, function( e ) {
			if ( ! $wrapper[ 0 ].contains( e.target ) ) { closeList(); }
		} );
	} );

	// When the clear button resets native selects, also reset custom UI labels.
	$root.on( 'click.svl-select-clear', '[data-svl-clear]', function() {
		$root.find( '.svl__select-wrapper' ).each( function() {
			var $wrapper = $( this );
			var $native  = $wrapper.find( '[data-svl-filter]' );
			var $trigger = $wrapper.find( '.svl__select-trigger' );
			var $listbox = $wrapper.find( '.svl__select-listbox' );
			$trigger.find( '.svl__select-value' ).text( $native.find( 'option:first' ).text() );
			$trigger.removeClass( 'svl__select-trigger--active' );
			$listbox.find( '[role="option"]' ).attr( 'aria-selected', 'false' );
			$listbox.find( '[role="option"]:first' ).attr( 'aria-selected', 'true' );
		} );
	} );
}

$( '[data-svl]' ).each( function() {
		var $root         = $( this );
		var $status       = $root.find( '[data-svl-status]' );
		var $loader       = $root.find( '[data-svl-loader]' );
		var $filterLoader = $root.find( '[data-svl-filter-loader]' );
		var $clearBtn     = $root.find( '[data-svl-clear]' );
		var $results      = $root.find( '[data-svl-results]' );

		// Initialise custom dropdowns before any other setup.
		svlInitCustomSelects( $root );

		// If on a video-category archive, PHP passes this non-zero value.
		var lockedCategoryId = parseInt( SVL && SVL.config && SVL.config.lockedCategoryId || 0, 10 );

		var isLoading     = false;
		var responseCache = {};
		var debounceTimer = null;

		function setLoading( on, message ) {
			isLoading = !! on;
			$status.attr( 'aria-busy', on ? 'true' : 'false' );

			if ( on ) {
				$loader.prop( 'hidden', false );
				$filterLoader.prop( 'hidden', false );
				if ( message ) {
					$loader.find( '.svl__loader-text' ).text( message );
				}
			} else {
				$loader.prop( 'hidden', true );
				$filterLoader.prop( 'hidden', true );
			}
		}

		function getFilters() {
			var f = {
				market:        $root.find( '[data-svl-filter="market"]' ).val() || '0',
				product:       $root.find( '[data-svl-filter="product"]' ).val() || '0',
				project:       $root.find( '[data-svl-filter="project"]' ).val() || '0',
				videoCategory: $root.find( '[data-svl-filter="video-category"]' ).val() || '0',
			};

			// Lock category to the archive term so AJAX can't drift.
			if ( lockedCategoryId > 0 ) {
				f.videoCategory = String( lockedCategoryId );
			}

			return f;
		}

		function updateClearButtonVisibility() {
			var f = getFilters();

			// When locked, category shouldn't count as "active" for Clear Filters.
			var active = f.market !== '0' || f.product !== '0' || f.project !== '0';

			$clearBtn.prop( 'hidden', ! active );
		}

		/**
		 * Rebuild a native select's options and sync the custom dropdown UI.
		 * Called by applyTermOptions() after each AJAX filter response.
		 */
		function rebuildSelect( $select, placeholder, options, keepValue ) {
			var current = keepValue || '0';
			$select.empty();
			$select.append( $( '<option/>' ).attr( 'value', '0' ).text( placeholder ) );

			( options || [] ).forEach( function( opt ) {
				$select.append(
					$( '<option/>' ).attr( 'value', String( opt.id ) ).text( opt.name )
				);
			} );

			var has = $select.find( 'option[value="' + current + '"]' ).length > 0;
			$select.val( has ? current : '0' );

			// Sync custom dropdown UI if it exists.
			var $wrapper = $select.closest( '.svl__select-wrapper' );
			if ( ! $wrapper.length ) { return; }

			var $listbox    = $wrapper.find( '.svl__select-listbox' );
			var $trigger    = $wrapper.find( '.svl__select-trigger' );
			var selectedVal = $select.val();
			var listboxId   = $listbox.attr( 'id' ) || '';

			// Rebuild listbox options from the freshly-rebuilt native select.
			$listbox.empty();
			$select.find( 'option' ).each( function( i ) {
				var val  = $( this ).val();
				var text = $( this ).text();
				$( '<li role="option" class="svl__select-option"></li>' ).attr( {
					'data-value':    val,
					'aria-selected': val === selectedVal ? 'true' : 'false',
					id:              listboxId + '-' + i,
				} ).text( text ).appendTo( $listbox );
			} );

			// Update trigger label and active state.
			$trigger.find( '.svl__select-value' ).text( $select.find( 'option:selected' ).text() || placeholder );
			$trigger.toggleClass( 'svl__select-trigger--active', selectedVal !== '0' );
		}

		function applyTermOptions( termPayload ) {
			var current = getFilters();

			rebuildSelect(
				$root.find( '[data-svl-filter="market"]' ),
				'Market',
				termPayload.market,
				current.market
			);
			rebuildSelect(
				$root.find( '[data-svl-filter="product"]' ),
				'Product',
				termPayload.product,
				current.product
			);
			rebuildSelect(
				$root.find( '[data-svl-filter="project"]' ),
				'Project',
				termPayload.project,
				current.project
			);

			// If the category is locked, do not rebuild it (hidden/disabled in PHP).
			if ( lockedCategoryId <= 0 ) {
				rebuildSelect(
					$root.find( '[data-svl-filter="video-category"]' ),
					'Category',
					termPayload.videoCategory,
					current.videoCategory
				);
			}
		}

		/**
		 * Build a cache key from the current filter state + pagination config.
		 * Used to short-circuit AJAX when the same combo was fetched before.
		 */
		function getCacheKey() {
			var f           = getFilters();
			var perCategory = lockedCategoryId > 0 ? -1 : ( SVL && SVL.config && SVL.config.perCategory != null ? SVL.config.perCategory : 3 );
			var maxCats     = lockedCategoryId > 0 ? '1' : ( SVL && SVL.config && SVL.config.maxCategories != null ? SVL.config.maxCategories : '' );

			return JSON.stringify( {
				m:  f.market,
				p:  f.product,
				r:  f.project,
				c:  f.videoCategory,
				pc: perCategory,
				mc: maxCats,
			} );
		}

		/**
		 * Apply a (possibly cached) AJAX response payload to the UI.
		 *
		 * @param {Object} data The res.data object from a successful SVL AJAX response.
		 */
		function applyResponse( data ) {
			if ( data.terms ) {
				applyTermOptions( data.terms );
			}
			if ( typeof data.html === 'string' ) {
				$results.html( data.html );
			}
			if ( typeof data.schema === 'string' ) {
				$root.find( 'script[type="application/ld+json"]' ).remove();
				$root.append( data.schema );
			}
			bindVideoLightboxDelegated();
		}

		function bindVideoLightboxDelegated() {
			// Prevent double-binding if init runs twice.
			if ( $root.data( 'svlLightboxBound' ) ) {
				return;
			}
			$root.data( 'svlLightboxBound', true );

			$root.on( 'click', 'a.nectar_video_lightbox', function( e ) {
				// If fancybox exists, force open with fancybox (works after AJAX).
				if ( typeof $.fancybox === 'function' ) {
					e.preventDefault();

					var $a = $( this );
					var url = $a.attr( 'href' ) ||
						$a.data( 'video-url' ) ||
						$a.attr( 'data-video-url' ) ||
						'';

					if ( ! url ) {
						return;
					}

					$.fancybox.open( {
						src:  url,
						type: 'iframe',
						opts: {
							iframe:   { preload: false },
							smallBtn: true,
							toolbar:  true,
						},
					} );
				}
				// If fancybox isn't present, let the default behaviour happen.
			} );
		}

		function requestUpdate() {
			if ( isLoading ) {
				return;
			}

			updateClearButtonVisibility();

			// Serve from in-memory cache when the same filter combo was already fetched.
			var cacheKey = getCacheKey();
			if ( responseCache[ cacheKey ] ) {
				applyResponse( responseCache[ cacheKey ] );
				return;
			}

			setLoading( true, SVL && SVL.strings && SVL.strings.loading || 'Loading videos\u2026' );

			var f = getFilters();

			// Force correct values when category is locked (taxonomy archive).
			var perCategory   = lockedCategoryId > 0 ? -1 : ( SVL && SVL.config && SVL.config.perCategory != null ? SVL.config.perCategory : 3 );
			var maxCategories = lockedCategoryId > 0 ? '1' : ( SVL && SVL.config && SVL.config.maxCategories != null ? SVL.config.maxCategories : '' );

			$.ajax( {
				url:      SVL.ajaxUrl,
				method:   'POST',
				dataType: 'json',
				data: {
					action:        'svl_filter',
					nonce:         SVL.nonce,
					market:        f.market,
					product:       f.product,
					project:       f.project,
					videoCategory: f.videoCategory,
					perCategory:   perCategory,
					maxCategories: maxCategories,
					eagerFirst:    SVL && SVL.config && SVL.config.eagerFirst != null ? SVL.config.eagerFirst : 3,
				},
			} )
				.done( function( res ) {
					if ( ! res || ! res.success ) {
						return;
					}
					// Store in cache before applying so back-navigation is instant.
					responseCache[ cacheKey ] = res.data;
					applyResponse( res.data );
				} )
				.always( function() {
					setLoading( false );
					updateClearButtonVisibility();
				} );
		}

		// If locked, ensure the hidden/disabled select matches the lock.
		if ( lockedCategoryId > 0 ) {
			$root
				.find( '[data-svl-filter="video-category"]' )
				.val( String( lockedCategoryId ) )
				.prop( 'disabled', true );

			$root.find( '.svl__category-wrap' ).prop( 'hidden', true );
		}

		$root.on( 'change', '[data-svl-filter]', function( e ) {
			// If category select somehow triggers while locked, revert and bail.
			if ( lockedCategoryId > 0 && $( e.target ).is( '[data-svl-filter="video-category"]' ) ) {
				$( e.target ).val( String( lockedCategoryId ) );
				return;
			}

			// Debounce: wait 250 ms so keyboard navigation through options
			// doesn't fire a request for every intermediate selection.
			clearTimeout( debounceTimer );
			debounceTimer = setTimeout( requestUpdate, 250 );
		} );

		$root.on( 'click', '[data-svl-clear]', function() {
			$root.find( '[data-svl-filter="market"]' ).val( '0' );
			$root.find( '[data-svl-filter="product"]' ).val( '0' );
			$root.find( '[data-svl-filter="project"]' ).val( '0' );

			if ( lockedCategoryId <= 0 ) {
				$root.find( '[data-svl-filter="video-category"]' ).val( '0' );
			}

			updateClearButtonVisibility();
			requestUpdate();
		} );

		// Initialise state.
		updateClearButtonVisibility();
		bindVideoLightboxDelegated();
	} );
} );
