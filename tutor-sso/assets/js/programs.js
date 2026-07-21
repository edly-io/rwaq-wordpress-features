/* global jQuery, tutorSsoPrograms */
/**
 * Programs catalog: infinite scroll + AJAX search, sort and filters.
 *
 * Each `.rwaq-programs` block is self-contained; per-instance config lives in
 * data-* attributes, shared config (ajaxurl / nonce / i18n) in tutorSsoPrograms.
 *
 * Filters: program type + organization (multi-select checkboxes) and featured
 * (single radio). Active selections render as removable chips; "clear all"
 * resets every filter. Any change re-queries page 1 via AJAX.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.tutorSsoPrograms || {};
	var i18n = cfg.i18n || {};
	var icons = cfg.icons || {};

	// Remove ("×") icon used on active-filter chips. Sourced from programs_icon()
	// in PHP (see programs_enqueue_assets) and localized onto tutorSsoPrograms.
	var CHIP_X_SVG = icons.removeChip || '';

	$( '.rwaq-programs' ).each( function () {
		initCatalog( $( this ) );
	} );

	function initCatalog( $root ) {
		var $grid = $root.find( '.rwaq-programs__grid' ).first();
		var $loader = $root.find( '.rwaq-programs__loader' ).first();
		var $overlay = $root.find( '.rwaq-programs__overlay' ).first();
		var $status = $root.find( '.rwaq-programs__status' ).first();
		var $sentinel = $root.find( '.rwaq-programs__sentinel' ).first();
		var $search = $root.find( '.rwaq-programs__search-input' ).first();
		var $sort = $root.find( '.rwaq-programs__sort-select' ).first();
		var $sortWrap = $root.find( '.rwaq-programs__sort' ).first();
		var $sortTrigger = $root.find( '.rwaq-programs__sort-trigger' ).first();
		var $sortValue = $root.find( '.rwaq-programs__sort-value' ).first();
		var $sortMenu = $root.find( '.rwaq-programs__sort-menu' ).first();
		var $chips = $root.find( '.rwaq-programs__chips' ).first();
		var $resultCount = $root.find( '[data-result-count]' ).first();

		var state = {
			page: parseInt( $root.data( 'page' ), 10 ) || 1, // Last page in the grid.
			perPage: parseInt( $root.data( 'per-page' ), 10 ) || 6,
			detailBase: $root.data( 'detail-base' ) || 'program',
			search: '',
			ordering: $sort.length ? $sort.val() : ( $root.data( 'default-sort' ) || '' ),
			filters: { program_type: [], org: [], featured: '' },
			hasMore: toBool( $root.data( 'has-more' ) ),
			loading: false
		};

		// ── Helpers ────────────────────────────────────────────────────────────
		function groupInputs( group ) {
			return $root.find(
				'.rwaq-programs__filter-group[data-filter="' + group + '"] .rwaq-programs__filter-input'
			);
		}

		function readFilters() {
			var f = { program_type: [], org: [], featured: '' };
			$root.find( '.rwaq-programs__filter-group' ).each( function () {
				var group = $( this ).data( 'filter' );
				$( this ).find( '.rwaq-programs__filter-input:checked' ).each( function () {
					var val = $( this ).val();
					if ( group === 'featured' ) {
						f.featured = val;
					} else if ( f[ group ] ) {
						f[ group ].push( val );
					}
				} );
			} );
			return f;
		}

		function labelFor( group, value ) {
			var label = value;
			groupInputs( group ).each( function () {
				if ( String( $( this ).val() ) === String( value ) ) {
					var text = $( this ).siblings( '.rwaq-programs__filter-label' ).text();
					if ( text ) {
						label = $.trim( text );
					}
				}
			} );
			return label;
		}

		function renderChips() {
			$chips.empty();

			var items = [];
			state.filters.program_type.forEach( function ( v ) {
				items.push( { group: 'program_type', value: v } );
			} );
			state.filters.org.forEach( function ( v ) {
				items.push( { group: 'org', value: v } );
			} );
			if ( state.filters.featured ) {
				items.push( { group: 'featured', value: state.filters.featured } );
			}

			items.forEach( function ( it ) {
				var label = labelFor( it.group, it.value );
				var $chip = $( '<button type="button" class="rwaq-programs__chip"></button>' )
					.attr( 'data-group', it.group )
					.attr( 'data-value', it.value )
					.attr( 'aria-label', ( i18n.removeFilter || 'Remove' ) + ': ' + label );
				$( '<span></span>' ).text( label ).appendTo( $chip );
				$( '<span class="rwaq-programs__chip-x" aria-hidden="true"></span>' ).html( CHIP_X_SVG ).appendTo( $chip );
				$chips.append( $chip );
			} );
		}

		// Update the filtered "found N" line. The header badge stays at the
		// unfiltered catalog total (rendered server-side).
		function updateCounts( count ) {
			if ( typeof count === 'undefined' || count === null ) {
				return;
			}
			if ( $resultCount.length && i18n.countLabel ) {
				$resultCount.text( i18n.countLabel.replace( '%s', count ) );
			}
		}

		function applyFilters() {
			state.filters = readFilters();
			renderChips();
			load( true );
		}

		// ── Fetch a page. reset=true clears the grid and loads page 1 (search /
		//    sort / filter change); reset=false appends the next page (scroll).
		function load( reset ) {
			if ( state.loading ) {
				return;
			}
			if ( ! reset && ! state.hasMore ) {
				return;
			}

			state.loading = true;
			$grid.attr( 'aria-busy', 'true' );
			if ( reset ) {
				$overlay.prop( 'hidden', false );
			} else {
				$loader.prop( 'hidden', false );
			}
			$status.text( '' );

			var nextPage = reset ? 1 : state.page + 1;

			$.ajax( {
				url: cfg.ajaxurl,
				type: 'GET',
				dataType: 'json',
				data: {
					action: 'tutor_sso_load_programs',
					nonce: cfg.nonce,
					page: nextPage,
					per_page: state.perPage,
					search: state.search,
					ordering: state.ordering,
					detail_base: state.detailBase,
					org: state.filters.org,
					program_type: state.filters.program_type,
					featured: state.filters.featured
				}
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						$status.text( errorText( response ) );
						return;
					}

					var data = response.data || {};

					if ( reset ) {
						$grid.empty();
					}

					if ( data.html ) {
						$grid.append( data.html );
					}

					state.page = data.page || nextPage;
					state.hasMore = !! data.has_more;
					updateCounts( data.count );

					if ( reset && ! data.html ) {
						$status.text( i18n.noResults || '' );
					} else {
						$status.text( '' );
					}
				} )
				.fail( function ( jqXHR ) {
					$status.text( errorText( jqXHR.responseJSON ) );
				} )
				.always( function () {
					state.loading = false;
					$grid.attr( 'aria-busy', 'false' );
					$loader.prop( 'hidden', true );
					$overlay.prop( 'hidden', true );
				} );
		}

		// ── Infinite scroll ─────────────────────────────────────────────────────
		if ( 'IntersectionObserver' in window && $sentinel.length ) {
			var observer = new window.IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						load( false );
					}
				} );
			}, { rootMargin: '200px' } );

			observer.observe( $sentinel.get( 0 ) );
		}

		// ── Search (debounced input + explicit submit) ───────────────────────────
		var searchTimer = null;

		function runSearch( value ) {
			value = $.trim( value );
			if ( value === state.search ) {
				return;
			}
			state.search = value;
			load( true );
		}

		$search.on( 'input', function () {
			var value = $( this ).val();
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( function () {
				runSearch( value );
			}, 350 );
		} );

		// ── Sort (custom dropdown backed by the hidden native <select>) ───────────
		function renderSortUI() {
			var current = $sort.val();
			if ( $sortValue.length ) {
				$sortValue.text( $sort.find( 'option:selected' ).text() );
			}
			$sortMenu.find( '.rwaq-programs__sort-option' ).each( function () {
				$( this ).toggleClass(
					'is-selected',
					String( $( this ).data( 'value' ) ) === String( current )
				);
			} );
		}

		function closeSort() {
			$sortWrap.removeClass( 'is-open' );
			$sortTrigger.attr( 'aria-expanded', 'false' );
		}

		// Build the custom menu from the native select's options.
		if ( $sortMenu.length && $sortTrigger.length ) {
			$sort.find( 'option' ).each( function () {
				$( '<div class="rwaq-programs__sort-option" role="option"></div>' )
					.attr( 'data-value', $( this ).val() )
					.text( $( this ).text() )
					.appendTo( $sortMenu );
			} );

			$sortTrigger.on( 'click', function ( e ) {
				e.stopPropagation();
				var open = $sortWrap.toggleClass( 'is-open' ).hasClass( 'is-open' );
				$sortTrigger.attr( 'aria-expanded', open ? 'true' : 'false' );
			} );

			$sortMenu.on( 'click', '.rwaq-programs__sort-option', function () {
				var value = String( $( this ).data( 'value' ) );
				if ( $sort.val() !== value ) {
					$sort.val( value ).trigger( 'change' );
				}
				closeSort();
			} );

			$( document ).on( 'click', function ( e ) {
				if ( ! $( e.target ).closest( $sortWrap ).length ) {
					closeSort();
				}
			} );
		}

		// The native select stays the single source of truth for the chosen sort.
		$sort.on( 'change', function () {
			state.ordering = $( this ).val();
			renderSortUI();
			load( true );
		} );

		renderSortUI();

		// ── Collapsible filter groups ────────────────────────────────────────────
		function toggleGroup( $title ) {
			var collapsed = $title
				.closest( '.rwaq-programs__filter-group' )
				.toggleClass( 'is-collapsed' )
				.hasClass( 'is-collapsed' );
			$title.attr( 'aria-expanded', collapsed ? 'false' : 'true' );
		}

		$root.on( 'click', '.rwaq-programs__filter-title', function () {
			toggleGroup( $( this ) );
		} );

		$root.on( 'keydown', '.rwaq-programs__filter-title', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' || e.which === 13 || e.which === 32 ) {
				e.preventDefault();
				toggleGroup( $( this ) );
			}
		} );

		// ── Filters (checkboxes + radios) ────────────────────────────────────────
		$root.on( 'change', '.rwaq-programs__filter-input', function () {
			applyFilters();
		} );

		// ── Remove a single filter via its chip ──────────────────────────────────
		$chips.on( 'click', '.rwaq-programs__chip', function () {
			var group = $( this ).data( 'group' );
			var value = String( $( this ).data( 'value' ) );
			groupInputs( group ).each( function () {
				if ( String( $( this ).val() ) === value ) {
					$( this ).prop( 'checked', false );
				}
			} );
			applyFilters();
		} );

		// ── Clear all filters ────────────────────────────────────────────────────
		$root.find( '.rwaq-programs__clear' ).on( 'click', function () {
			$root.find( '.rwaq-programs__filter-input' ).prop( 'checked', false );
			applyFilters();
		} );

		// ── "Show more / less" organizations toggle. The collapsed label
		//    ("عرض N المزيد") is dynamic, so it is kept on the button's
		//    data-more-text and restored when collapsing.
		$root.on( 'click', '.rwaq-programs__show-more', function () {
			var $btn = $( this );
			var expanded = $btn.attr( 'aria-expanded' ) === 'true';
			$btn.closest( '.rwaq-programs__filter-group' )
				.find( '.rwaq-programs__filter-option--overflow' )
				.prop( 'hidden', expanded );
			$btn.attr( 'aria-expanded', expanded ? 'false' : 'true' )
				.text( expanded ? ( $btn.data( 'more-text' ) || '' ) : ( i18n.showLess || '' ) );
		} );
	}

	function toBool( value ) {
		return value === true || value === 'true' || value === 1 || value === '1';
	}

	function errorText( data ) {
		if ( data && data.data && data.data.message ) {
			return data.data.message;
		}
		return i18n.error || 'Something went wrong. Please try again.';
	}
} )( jQuery );
