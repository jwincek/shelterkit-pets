/**
 * Pet Listing Grid View Module
 *
 * v3.0.0 changes:
 * - All async actions migrated to generator functions
 * - Stale-navigation guard (monotonic navCounter)
 * - withSyncEvent for actions that need event.preventDefault()
 * - Imports from utils.js instead of store.js
 * - Improved search highlighting via data-wp-watch + rAF
 *
 * @package
 * @since 1.0.0
 */

import {
	store,
	getContext,
	getElement,
	withSyncEvent,
} from '@wordpress/interactivity';
import { doToggleFavorite, doToggleComparison } from '../store.js';
import { announce } from '../utils.js';

const getGlobalState = () => store( 'petsync' ).state;
const getGlobalActions = () => store( 'petsync' ).actions;

/* === URL Helpers === */

const parseFiltersFromUrl = () => {
	const params = new URLSearchParams( window.location.search );
	const filters = {};
	const compat = {};
	params.forEach( ( value, key ) => {
		if ( key.startsWith( 'filter_' ) ) {
			filters[ key.slice( 7 ) ] = value;
		} else if ( key.startsWith( 'compat_' ) ) {
			compat[ key.slice( 7 ) ] = value === '1';
		}
	} );
	return { filters, compat };
};

/**
 * Build a URL from current context filters for router navigation.
 *
 * URL parameter naming matches render.php:
 * - filter_{key} for taxonomy filters
 * - compat_{key} for compatibility filters
 * - pet_search for search
 * - favorites for favorites-only mode
 * - paged for pagination
 *
 * @param {Object} ctx  Grid context.
 * @param {number} page Page number.
 * @return {string} Fully-qualified URL.
 */
const buildFilterUrl = ( ctx, page = 1 ) => {
	const url = new URL(
		ctx.archiveUrl || window.location.pathname,
		window.location.origin
	);

	// Taxonomy filters.
	const filters = ctx.filters || {};
	Object.entries( filters ).forEach( ( [ key, value ] ) => {
		if ( value ) {
			url.searchParams.set( 'filter_' + key, value );
		}
	} );

	// Compatibility filters.
	const compat = ctx.compatFilters || {};
	Object.entries( compat ).forEach( ( [ key, value ] ) => {
		if ( value ) {
			url.searchParams.set( 'compat_' + key, '1' );
		}
	} );

	// Search.
	if ( ctx.searchQuery?.trim() ) {
		url.searchParams.set( 'pet_search', ctx.searchQuery.trim() );
	}

	// Sort.
	if ( ctx.sort ) {
		url.searchParams.set( 'sort', ctx.sort );
	}

	// Pagination.
	if ( page > 1 ) {
		url.searchParams.set( 'paged', String( page ) );
	}

	return url.toString();
};

/* === Stale-Navigation Guard === */

/**
 * Monotonic counter to prevent stale navigation callbacks from
 * resetting isNavigating when a newer navigation has started.
 */
let navCounter = 0;

/**
 * Perform router navigation with stale-nav guard.
 * Adopted from the shelter plugin's doNavigate pattern.
 *
 * @param {Object} ctx    Grid context.
 * @param {string} href   Target URL (optional — builds from ctx if omitted).
 * @param {number} [page] Page number if building URL from ctx.
 */
function* doNavigate( ctx, href, page ) {
	const myNavId = ++navCounter;
	state.isNavigating = true;

	try {
		const { actions: routerActions } = yield import(
			'@wordpress/interactivity-router'
		);

		const targetUrl = href || buildFilterUrl( ctx, page || 1 );
		yield routerActions.navigate( targetUrl, { force: false } );

		// Scroll to the grid region after navigation.
		const region = document.querySelector(
			`[data-wp-router-region="${ ctx.routerRegionId || 'pet-grid' }"]`
		);
		if ( region ) {
			region.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	} catch ( error ) {
		console.error( 'Pet Grid: Navigation failed, falling back', error );
		window.location.href = href || buildFilterUrl( ctx, page || 1 );
	} finally {
		// Only reset if we're still the latest navigation.
		if ( navCounter === myNavId ) {
			state.isNavigating = false;
		}
	}
}

/* === Store Definition === */

const { state, actions, callbacks } = store( 'petsync/grid', {
	state: {
		isNavigating: false,
		compatFiltersExpanded: true,
		filterDrawerOpen: false,
		showFavoritesOnly: false,

		// === Pet-Level Derived State ===

		get isFavorited() {
			const ctx = getContext();
			return ctx.petId
				? getGlobalState().favorites.includes( ctx.petId )
				: false;
		},

		get isInComparison() {
			const ctx = getContext();
			return ctx.petId
				? getGlobalState().comparison.includes( ctx.petId )
				: false;
		},

		get isCompareDisabled() {
			const ctx = getContext();
			const petId = ctx.petId;
			if ( ! petId ) {
				return false;
			}
			return (
				! getGlobalState().comparison.includes( petId ) &&
				getGlobalState().comparison.length >=
					getGlobalState().comparisonMax
			);
		},

		/**
		 * Per-card bonded pair expansion. Reads from context so each
		 * card tracks its own state independently.
		 */
		get isBondedExpanded() {
			const ctx = getContext();
			return ctx.bondedExpanded ?? false;
		},

		// === Grid Derived State ===

		get hasSearchQuery() {
			const ctx = getContext();
			return !! ctx.searchQuery?.trim();
		},

		get hasActiveFilters() {
			const ctx = getContext();

			if ( ctx.filters ) {
				for ( const value of Object.values( ctx.filters ) ) {
					if ( value ) {
						return true;
					}
				}
			}

			if ( ctx.compatFilters ) {
				for ( const value of Object.values( ctx.compatFilters ) ) {
					if ( value ) {
						return true;
					}
				}
			}

			if ( ctx.searchQuery?.trim() ) {
				return true;
			}

			if ( ctx.sort ) {
				return true;
			}

			return false;
		},

		/**
		 * Count of active taxonomy + compatibility filters (not search/sort).
		 * Used for the filter toggle badge.
		 */
		get activeFilterCount() {
			const ctx = getContext();
			let count = 0;

			if ( ctx.filters ) {
				for ( const value of Object.values( ctx.filters ) ) {
					if ( value ) {
						count++;
					}
				}
			}

			if ( ctx.compatFilters ) {
				for ( const value of Object.values( ctx.compatFilters ) ) {
					if ( value ) {
						count++;
					}
				}
			}

			return count;
		},

		get hasActiveFilterCount() {
			return state.activeFilterCount > 0;
		},

		get visibleCount() {
			const ctx = getContext();
			// Server total is authoritative. petIds length is a fallback.
			return ctx.total ?? ctx.petIds?.length ?? 0;
		},

		get hasVisiblePets() {
			return state.visibleCount > 0;
		},

		get resultsText() {
			if ( state.showFavoritesOnly ) {
				const inView = state.favoritesInViewCount;
				if ( inView === 0 ) {
					return 'No favorites match your current filters';
				}
				return inView === 1
					? 'Showing 1 favorite'
					: `Showing ${ inView } favorites`;
			}

			const count = state.visibleCount;

			if ( state.hasActiveFilters ) {
				return `Showing ${ count } ${ count === 1 ? 'pet' : 'pets' }`;
			}

			return count === 1 ? 'Showing 1 pet' : `Showing ${ count } pets`;
		},

		get isPetVisible() {
			// Server-side filters (taxonomy, compat, search) are handled
			// via doNavigate. The favorites toggle is client-side only.
			if ( state.showFavoritesOnly ) {
				const ctx = getContext();
				return ctx.petId
					? getGlobalState().favorites.includes( ctx.petId )
					: true;
			}
			return true;
		},

		/**
		 * Count of favourited pets in the current server-rendered result set.
		 * If the user filtered by "Cats", this only counts favourited cats.
		 */
		get favoritesInViewCount() {
			const ctx = getContext();
			const allFavs = getGlobalState().favorites;
			const viewIds = ctx.petIds?.map( ( p ) => p.id ?? p ) ?? [];
			if ( ! viewIds.length ) {
				return allFavs.length;
			}
			return allFavs.filter( ( id ) => viewIds.includes( id ) ).length;
		},

		get favoritesFilterText() {
			const inView = state.favoritesInViewCount;
			const total = getGlobalState().favorites.length;

			if ( state.showFavoritesOnly ) {
				if ( inView === 0 ) {
					return 'No favorites match filters';
				}
				return inView === 1
					? 'Showing 1 favorite'
					: `Showing ${ inView } favorites`;
			}

			if ( total === 0 ) {
				return '♥ Favorites';
			}

			// Show "2 of 5 saved" when filters reduce the visible set,
			// or just "5 saved" when viewing all pets.
			if ( inView < total ) {
				return `♥ ${ inView } of ${ total } saved`;
			}
			return `♥ ${ total } saved`;
		},
	},

	actions: {
		// === Favorites Filter ===

		toggleFavoritesFilter() {
			state.showFavoritesOnly = ! state.showFavoritesOnly;
			if ( state.showFavoritesOnly ) {
				const inView = state.favoritesInViewCount;
				if ( inView === 0 ) {
					announce( 'No favorites match your current filters' );
				} else {
					announce(
						inView === 1
							? 'Showing 1 favorite'
							: `Showing ${ inView } favorites`
					);
				}
			} else {
				announce( 'Showing all pets' );
			}
		},

		// === Favorites & Comparison ===
		//
		// The grid's pet cards set petId in the petsync/grid context.
		// The global store's toggleFavorite uses getPetIdFromContext() which
		// resolves against the petstablished namespace — where petId doesn't
		// exist. We read petId from our own context and pass it to the
		// exported helper generators, bypassing the namespace mismatch.

		*toggleFavorite() {
			const ctx = getContext();
			yield* doToggleFavorite( ctx.petId, ctx.petName );
		},

		*toggleComparison() {
			const ctx = getContext();
			yield* doToggleComparison( ctx.petId, ctx.petName );
		},

		/**
		 * Toggle the bonded pair popover on a card.
		 * Sets bondedExpanded in the per-card context.
		 * Registers a one-time document click listener to close
		 * the popover when clicking outside.
		 * @param event
		 */
		toggleBondedInfo( event ) {
			event.stopPropagation();
			const ctx = getContext();
			const wasExpanded = ctx.bondedExpanded ?? false;
			ctx.bondedExpanded = ! wasExpanded;

			if ( ! wasExpanded ) {
				// Close on next outside click.
				const close = () => {
					ctx.bondedExpanded = false;
					document.removeEventListener( 'click', close, true );
				};
				// Defer so this click doesn't immediately close it.
				requestAnimationFrame( () => {
					document.addEventListener( 'click', close, true );
				} );
			}
		},

		// === Router Navigation (generator + stale-nav guard) ===

		/**
		 * Navigate to a pagination link.
		 * Wrapped in withSyncEvent because we need event.preventDefault()
		 * before the generator yields.
		 */
		navigateToPage: withSyncEvent( function* ( event ) {
			event.preventDefault();

			const { ref } = getElement();
			const href = ref?.getAttribute( 'href' ) || event.target?.href;
			if ( ! href ) {
				return;
			}

			const ctx = getContext();
			yield* doNavigate( ctx, href );
		} ),

		/**
		 * Prefetch a pagination link on hover.
		 */
		*prefetchPage() {
			const { ref } = getElement();
			const href = ref?.getAttribute( 'href' );
			if ( ! href ) {
				return;
			}

			try {
				const { actions: routerActions } = yield import(
					'@wordpress/interactivity-router'
				);
				routerActions.prefetch( href );
			} catch {
				// Router not available — ignore.
			}
		},

		// === Filter Actions ===

		*handleFilterChange( event ) {
			const ctx = getContext();
			const select = event.target;
			const filterKey = select.dataset.filterKey || select.name;

			if ( ctx.filters ) {
				ctx.filters[ filterKey ] = select.value;
			}

			announce(
				select.value
					? `Filtered by ${ filterKey }`
					: `${ filterKey } filter cleared`
			);

			// Server-side navigate to get fresh results and updated counts.
			yield* doNavigate( ctx, null, 1 );
		},

		updateFilter( event ) {
			actions.handleFilterChange( event );
		},

		*handleCompatFilterChange( event ) {
			const el = event.target.closest( '[data-compat-key]' );
			if ( ! el ) {
				return;
			}
			const ctx = getContext();

			const filterKey = el.dataset.compatKey;
			const isCheckbox = el.type === 'checkbox';
			const isChecked = isCheckbox
				? el.checked
				: el.getAttribute( 'aria-pressed' ) === 'true';

			if ( ctx.compatFilters ) {
				ctx.compatFilters[ filterKey ] = isCheckbox
					? el.checked
					: ! isChecked;
			}

			if ( ! isCheckbox ) {
				el.setAttribute(
					'aria-pressed',
					ctx.compatFilters[ filterKey ] ? 'true' : 'false'
				);
			}

			announce(
				ctx.compatFilters[ filterKey ]
					? `${ filterKey } filter enabled`
					: `${ filterKey } filter disabled`
			);

			// Server-side navigate to get fresh results and updated counts.
			yield* doNavigate( ctx, null, 1 );
		},

		toggleCompatFilter( event ) {
			actions.handleCompatFilterChange( event );
		},

		toggleCompatFilters() {
			state.compatFiltersExpanded = ! state.compatFiltersExpanded;
		},

		// === Search ===

		handleSearchInput( event ) {
			const ctx = getContext();
			ctx.searchQuery = event.target.value;
		},

		*submitSearch() {
			const ctx = getContext();
			announce(
				ctx.searchQuery
					? `Searching for "${ ctx.searchQuery }"`
					: 'Search cleared'
			);
			yield* doNavigate( ctx, null, 1 );
		},

		/**
		 * Submit search on Enter key.
		 * @param event
		 */
		*handleSearchKeydown( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				const ctx = getContext();
				announce(
					ctx.searchQuery
						? `Searching for "${ ctx.searchQuery }"`
						: 'Search cleared'
				);
				yield* doNavigate( ctx, null, 1 );
			}
		},

		*clearSearch() {
			const ctx = getContext();
			ctx.searchQuery = '';

			const searchInput = document.getElementById( 'pet-search' );
			if ( searchInput ) {
				searchInput.value = '';
				searchInput.focus();
			}

			announce( 'Search cleared' );
			yield* doNavigate( ctx, null, 1 );
		},

		// === Individual Filter Removal (from active filter chips) ===

		*removeFilter() {
			const { ref } = getElement();
			const ctx = getContext();
			const type = ref.dataset.filterType;
			const key = ref.dataset.filterKey;

			if ( type === 'filter' && ctx.filters ) {
				ctx.filters[ key ] = '';
			} else if ( type === 'compat' && ctx.compatFilters ) {
				ctx.compatFilters[ key ] = false;
			} else if ( type === 'search' ) {
				ctx.searchQuery = '';
				const searchInput = document.getElementById( 'pet-search' );
				if ( searchInput ) {
					searchInput.value = '';
				}
			}

			announce( 'Filter removed' );
			yield* doNavigate( ctx, null, 1 );
		},

		// === Reset ===

		*resetFilters() {
			const ctx = getContext();

			ctx.searchQuery = '';
			ctx.sort = '';

			if ( ctx.filters ) {
				Object.keys( ctx.filters ).forEach(
					( key ) => ( ctx.filters[ key ] = '' )
				);
			}
			if ( ctx.compatFilters ) {
				Object.keys( ctx.compatFilters ).forEach(
					( key ) => ( ctx.compatFilters[ key ] = false )
				);
			}

			announce( 'All filters cleared' );

			// Server-side navigate to get unfiltered results and fresh counts.
			yield* doNavigate( ctx, null, 1 );
		},

		// === Sort ===

		*updateSort( event ) {
			const ctx = getContext();
			ctx.sort = event.target.value;

			announce(
				event.target.value ? 'Sort updated' : 'Sort reset to default'
			);

			// Server-side navigate to get re-ordered results.
			yield* doNavigate( ctx, null, 1 );
		},

		// === Filter Drawer ===

		toggleFilterDrawer() {
			state.filterDrawerOpen = ! state.filterDrawerOpen;
		},
	},

	callbacks: {
		/**
		 * Initialize grid — restore state from URL, cache pets.
		 */
		init() {
			const ctx = getContext();

			const { filters: urlFilters, compat: urlCompat } =
				parseFiltersFromUrl();

			if ( ctx.filters && Object.keys( urlFilters ).length ) {
				Object.assign( ctx.filters, urlFilters );
			}

			if ( ctx.compatFilters && Object.keys( urlCompat ).length ) {
				Object.assign( ctx.compatFilters, urlCompat );
			}

			const params = new URLSearchParams( window.location.search );
			if ( params.has( 'pet_search' ) ) {
				ctx.searchQuery = params.get( 'pet_search' );
			}
			if ( params.has( 'sort' ) ) {
				ctx.sort = params.get( 'sort' );
			}

			if ( ctx.petIds?.length ) {
				getGlobalActions().cachePets( ctx.petIds );
			}

			// Auto-open filter drawer if filters are active on page load.
			if ( state.activeFilterCount > 0 ) {
				state.filterDrawerOpen = true;
			}

			// On mobile, close the compat details section unless compat
			// filters are active. The HTML has open for desktop; we remove
			// it here for mobile to keep the filter area compact.
			if ( window.innerWidth <= 600 ) {
				const compat = document.querySelector(
					'.pet-listing-grid__compat-details'
				);
				if ( compat && compat.dataset.hasActive !== '1' ) {
					compat.removeAttribute( 'open' );
				}
			}
		},
	},
} );

export { state, actions, callbacks, buildFilterUrl, doNavigate };
