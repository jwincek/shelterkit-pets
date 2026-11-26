/**
 * Pet Filters View Module
 *
 * Standalone filter block for cases where filters render separately
 * from the grid. Most filter logic lives in the grid module.
 *
 * v3.0.0: imports from utils.js
 *
 * @package
 * @since 1.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { announce } from '../utils.js';

/* === URL Helpers === */

const updateUrlFilters = ( filters, prefix = 'filter_' ) => {
	const url = new URL( window.location.href );
	Array.from( url.searchParams.keys() )
		.filter( ( key ) => key.startsWith( prefix ) )
		.forEach( ( key ) => url.searchParams.delete( key ) );
	Object.entries( filters || {} ).forEach( ( [ key, value ] ) => {
		if ( value ) {
			url.searchParams.set( prefix + key, value );
		}
	} );
	window.history.replaceState( null, '', url.toString() );
};

const { state, actions } = store( 'petsync/filters', {
	state: {
		compatFiltersExpanded: true,

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

			return false;
		},
	},

	actions: {
		handleFilterChange( event ) {
			const ctx = getContext();
			const select = event.target;

			if ( ctx.filters ) {
				ctx.filters[ select.name ] = select.value;
			}

			updateUrlFilters( ctx.filters );
			announce(
				select.value
					? `Filtered by ${ select.name }`
					: `${ select.name } filter cleared`
			);

			if ( ctx.navigateOnChange ) {
				window.location.href = new URL(
					window.location.href
				).toString();
			}
		},

		handleCompatFilterChange( event ) {
			const ctx = getContext();
			const input = event.target;
			const filterKey = input.dataset.filterKey || input.name;
			const isChecked =
				input.type === 'checkbox'
					? input.checked
					: input.getAttribute( 'aria-pressed' ) === 'true';

			if ( ctx.compatFilters ) {
				ctx.compatFilters[ filterKey ] =
					input.type === 'checkbox' ? input.checked : ! isChecked;
			}

			if ( input.type !== 'checkbox' ) {
				input.setAttribute(
					'aria-pressed',
					ctx.compatFilters[ filterKey ] ? 'true' : 'false'
				);
			}

			announce(
				ctx.compatFilters[ filterKey ]
					? `${ filterKey } filter enabled`
					: `${ filterKey } filter disabled`
			);
		},

		toggleCompatFilters() {
			state.compatFiltersExpanded = ! state.compatFiltersExpanded;
		},

		handleSearchInput( event ) {
			const ctx = getContext();
			ctx.searchQuery = event.target.value;

			clearTimeout( ctx._searchTimeout );
			ctx._searchTimeout = setTimeout( () => {
				const url = new URL( window.location.href );
				if ( ctx.searchQuery ) {
					url.searchParams.set( 'pet_search', ctx.searchQuery );
				} else {
					url.searchParams.delete( 'pet_search' );
				}
				window.history.replaceState( null, '', url.toString() );
			}, 300 );
		},

		clearSearch() {
			const ctx = getContext();
			ctx.searchQuery = '';

			const searchInput = document.getElementById( 'pet-search' );
			if ( searchInput ) {
				searchInput.value = '';
				searchInput.focus();
			}

			const url = new URL( window.location.href );
			url.searchParams.delete( 'pet_search' );
			window.history.replaceState( null, '', url.toString() );

			announce( 'Search cleared' );
		},

		resetFilters() {
			const ctx = getContext();

			ctx.searchQuery = '';

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

			const url = new URL( window.location.href );
			Array.from( url.searchParams.keys() )
				.filter(
					( key ) =>
						key.startsWith( 'filter_' ) ||
						key === 'pet_search' ||
						key === 'favorites'
				)
				.forEach( ( key ) => url.searchParams.delete( key ) );
			window.history.replaceState( null, '', url.toString() );

			announce( 'All filters cleared' );

			if ( ctx.navigateOnChange ) {
				window.location.href = url.toString();
			}
		},
	},

	callbacks: {
		init() {
			const ctx = getContext();
			const params = new URLSearchParams( window.location.search );

			if ( params.has( 'pet_search' ) ) {
				ctx.searchQuery = params.get( 'pet_search' );
			}

			params.forEach( ( value, key ) => {
				if ( key.startsWith( 'filter_' ) && ctx.filters ) {
					ctx.filters[ key.slice( 7 ) ] = value;
				}
			} );
		},
	},
} );

export { state, actions };
