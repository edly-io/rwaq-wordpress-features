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
				$( '<span class="rwaq-programs__chip-x" aria-hidden="true">×</span>' ).appendTo( $chip );
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
				$resultCount.text( i18n.countLabel.replace( '%s', toArabicDigits( count ) ) );
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

		// ── Search (debounced) ───────────────────────────────────────────────────
		var searchTimer = null;
		$search.on( 'input', function () {
			var value = $.trim( $( this ).val() );
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( function () {
				if ( value === state.search ) {
					return;
				}
				state.search = value;
				load( true );
			}, 350 );
		} );

		// ── Sort ─────────────────────────────────────────────────────────────────
		$sort.on( 'change', function () {
			state.ordering = $( this ).val();
			load( true );
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

	function toArabicDigits( value ) {
		var digits = '٠١٢٣٤٥٦٧٨٩';
		return String( value ).replace( /[0-9]/g, function ( d ) {
			return digits.charAt( Number( d ) );
		} );
	}

	function errorText( data ) {
		if ( data && data.data && data.data.message ) {
			return data.data.message;
		}
		return i18n.error || 'Something went wrong. Please try again.';
	}
} )( jQuery );
