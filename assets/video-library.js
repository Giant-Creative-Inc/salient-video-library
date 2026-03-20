/* global SVL */
jQuery( function( $ ) {
	$( '[data-svl]' ).each( function() {
		var $root    = $( this );
		var $status  = $root.find( '[data-svl-status]' );
		var $loader  = $root.find( '[data-svl-loader]' );
		var $clearBtn = $root.find( '[data-svl-clear]' );
		var $results = $root.find( '[data-svl-results]' );

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
				if ( message ) {
					$loader.find( '.svl__loader-text' ).text( message );
				}
			} else {
				$loader.prop( 'hidden', true );
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
