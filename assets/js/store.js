/**
 * Petstablished Global Store
 *
 * Root 'petsync' namespace — shared state across all pet blocks.
 *
 * Provides: favorites, comparison, pets cache, notifications, gallery
 * lightbox, and comparison-page actions.
 *
 * Block-specific UI stores (grid, slider, filters, compare-bar, gallery,
 * favorites-modal) live in assets/js/interactivity/ under their own child
 * namespaces and cross-reference this root store via imports.
 *
 * v4.2.0: Removed favorites-as-filter (toggleShowFavorites, showAllFavorites).
 * Favorites are now a standalone modal, not a grid filter mode.
 * Router-based navigation for comparison actions.
 *
 * @package
 * @since 1.0.0
 */

import {
	store,
	getContext,
	getConfig,
	getElement,
} from '@wordpress/interactivity';
import { storage, announce, executeAbility, copyToClipboard } from './utils.js';

/**
 * Resolve petId from context or DOM — used by actions that operate
 * on "the current pet" (favorite toggle, comparison toggle, etc.).
 */
const getPetIdFromContext = () => {
	try {
		const ctx = getContext();
		if ( ctx?.petId ) {
			return { petId: ctx.petId, petName: ctx.petName };
		}
	} catch {}
	try {
		const el = getElement();
		if ( el?.ref ) {
			const petId =
				el.ref.dataset?.petId ||
				el.ref.closest( '[data-pet-id]' )?.dataset?.petId;
			if ( petId ) {
				return { petId: parseInt( petId, 10 ), petName: null };
			}
			const contextEl = el.ref.closest( '[data-wp-context]' );
			if ( contextEl ) {
				try {
					const ctxData = JSON.parse( contextEl.dataset.wpContext );
					if ( ctxData?.petId ) {
						return {
							petId: ctxData.petId,
							petName: ctxData.petName,
						};
					}
				} catch {}
			}
		}
	} catch {}
	return { petId: null, petName: null };
};

/**
 * Helper: get the archive URL from context, DOM, or fallback.
 */
const getArchiveUrl = () => {
	try {
		const ctx = getContext();
		if ( ctx?.archiveUrl ) {
			return ctx.archiveUrl;
		}
	} catch {}
	return (
		document.querySelector( '[data-pets-archive-url]' )?.dataset
			.petsArchiveUrl || '/pets/'
	);
};

/* =========================================================================
 * Shared helper generators
 *
 * These are importable by child-namespace stores (grid, modal, compare-bar)
 * so they can pass petId explicitly — avoiding the cross-namespace
 * getContext() problem where the root store can't read child context.
 * ========================================================================= */

/**
 * Toast + screen-reader notification.
 *
 * Writes to state.notification, which the petsync/pet-toast block (an
 * aria-live region in the pet-floating-ui part) renders visibly and
 * announces. On pages without that block, fall back to the SR-only
 * announcer so messages are never lost. Calling announce()
 * unconditionally would double-announce wherever the toast exists.
 *
 * @param {string} message The message to show.
 */
let toastTimer;
function showToast( message ) {
	const s = store( 'petsync' ).state;
	s.notification = message;
	if ( ! document.querySelector( '.pet-toast' ) ) {
		announce( message );
	}
	clearTimeout( toastTimer );
	toastTimer = setTimeout( () => {
		s.notification = null;
	}, 3000 );
}

/**
 * Core favorites toggle logic. Accepts petId explicitly.
 *
 * @param {number}      petId   Pet post ID.
 * @param {string|null} petName Pet display name (for announcements).
 */
function* doToggleFavorite( petId, petName ) {
	if ( ! petId ) {
		return;
	}
	const s = store( 'petsync' ).state;
	const config = getConfig( 'petsync' );
	const wasIn = s.favorites.includes( petId );

	// Optimistic update.
	s.favorites = wasIn
		? s.favorites.filter( ( id ) => id !== petId )
		: [ ...s.favorites, petId ];
	if ( s.pets[ petId ] ) {
		s.pets[ petId ].favorited = ! wasIn;
	}

	const name = petName || s.pets[ petId ]?.name || 'Pet';
	showToast(
		wasIn
			? `${ name } removed from favorites`
			: `${ name } added to favorites`
	);

	try {
		const result = yield executeAbility( 'petsync/toggle-favorite', {
			id: petId,
		} );
		s.favorites = result.favorites;
		storage.set( 'favorites', s.favorites );
	} catch ( error ) {
		// Rollback state and localStorage.
		s.favorites = wasIn
			? [ ...s.favorites, petId ]
			: s.favorites.filter( ( id ) => id !== petId );
		if ( s.pets[ petId ] ) {
			s.pets[ petId ].favorited = wasIn;
		}
		storage.set( 'favorites', s.favorites );
		console.error( 'Failed to toggle favorite:', error );
		showToast( config.i18n?.error || 'Failed to update favorites' );
	}
}

/**
 * Core comparison toggle logic. Accepts petId explicitly.
 *
 * @param {number}      petId   Pet post ID.
 * @param {string|null} petName Pet display name (for announcements).
 */
function* doToggleComparison( petId, petName ) {
	if ( ! petId ) {
		return;
	}
	const s = store( 'petsync' ).state;
	const config = getConfig( 'petsync' );
	const wasIn = s.comparison.includes( petId );

	if ( ! wasIn && s.comparison.length >= s.comparisonMax ) {
		showToast(
			config.i18n?.compareFull ||
				'Comparison is full. Remove a pet first.'
		);
		return;
	}

	s.comparison = wasIn
		? s.comparison.filter( ( id ) => id !== petId )
		: [ ...s.comparison, petId ];
	if ( s.pets[ petId ] ) {
		s.pets[ petId ].compared = ! wasIn;
	}

	const name = petName || s.pets[ petId ]?.name || 'Pet';
	showToast(
		wasIn
			? `${ name } removed from comparison`
			: `${ name } added to comparison (${ s.comparison.length }/${ s.comparisonMax })`
	);

	try {
		const result = yield executeAbility( 'petsync/update-comparison', {
			action: wasIn ? 'remove' : 'add',
			id: petId,
		} );
		s.comparison = result.ids;
		s.comparisonMax = result.max;
		storage.set( 'comparison', s.comparison );
	} catch ( error ) {
		s.comparison = wasIn
			? [ ...s.comparison, petId ]
			: s.comparison.filter( ( id ) => id !== petId );
		if ( s.pets[ petId ] ) {
			s.pets[ petId ].compared = wasIn;
		}
		console.error( 'Failed to update comparison:', error );
		showToast( config.i18n?.error || 'Failed to update comparison' );
	}
}

/* =========================================================================
 * Store definition
 * ========================================================================= */

const { state, actions, callbacks } = store( 'petsync', {
	/* -----------------------------------------------------------------
	 * STATE
	 * ----------------------------------------------------------------- */

	state: {
		// favorites and comparison are provided by the server via
		// wp_interactivity_state() in register-stores.php.
		// Do NOT declare defaults here — store() merges client state
		// ON TOP of server state, so [] would overwrite the hydrated values.
		comparisonMax: 4,
		pets: {},
		isLoading: false,
		notification: null,

		// Gallery lightbox state removed — owned by petsync/gallery store
		// (see assets/js/interactivity/gallery.js).

		/* --- Derived: favorites & comparison counts --- */

		get favoritesCount() {
			return state.favorites.length;
		},
		get comparisonCount() {
			return state.comparison.length;
		},
		get isCompareBarHidden() {
			return state.comparison.length === 0;
		},
		get isCompareBarVisible() {
			return state.comparison.length > 0;
		},
		get noNotification() {
			return ! state.notification;
		},
		get isCompareBarExpanded() {
			return state._compareBarExpanded ?? true;
		},
		get canAddToComparison() {
			return state.comparison.length < state.comparisonMax;
		},

		get isFavorited() {
			const { petId } = getPetIdFromContext();
			return petId ? state.favorites.includes( petId ) : false;
		},
		get isInComparison() {
			const { petId } = getPetIdFromContext();
			return petId ? state.comparison.includes( petId ) : false;
		},
		get isCompareDisabled() {
			const { petId } = getPetIdFromContext();
			if ( ! petId ) {
				return true;
			}
			return (
				! state.comparison.includes( petId ) &&
				state.comparison.length >= state.comparisonMax
			);
		},
		get currentPet() {
			const { petId } = getPetIdFromContext();
			return petId ? state.pets[ petId ] : null;
		},
		get comparedPets() {
			return state.comparison
				.map( ( id ) => state.pets[ id ] )
				.filter( Boolean );
		},

		/* --- Derived: accessible labels (pet-card / pet-actions) --- */

		get favoriteLabel() {
			const { petId, petName } = getPetIdFromContext();
			const name = petName || state.pets[ petId ]?.name || 'this pet';
			return state.favorites.includes( petId )
				? `Unfavorite ${ name }`
				: `Favorite ${ name }`;
		},
		get compareLabel() {
			const { petId, petName } = getPetIdFromContext();
			const name = petName || state.pets[ petId ]?.name || 'this pet';
			return state.comparison.includes( petId )
				? `Remove ${ name } from comparison`
				: `Add ${ name } to comparison`;
		},

		/* --- Derived: button text (pet-actions) --- */

		get favoriteButtonText() {
			return state.isFavorited
				? state._i18n?.unfavorite || 'Unfavorite'
				: state._i18n?.favorite || 'Favorite';
		},
		get compareButtonText() {
			return state.isInComparison
				? state._i18n?.comparing || 'Comparing'
				: state._i18n?.compare || 'Compare';
		},

		/* --- Derived: share dropdown (pet-actions) --- */

		get isShareMenuOpen() {
			const ctx = getContext();
			return ctx._shareMenuOpen ?? false;
		},
		get hasNativeShare() {
			return typeof navigator !== 'undefined' && !! navigator.share;
		},
		get isLinkCopied() {
			const ctx = getContext();
			return ctx._linkCopied ?? false;
		},
		get copyButtonText() {
			const ctx = getContext();
			return ctx._linkCopied
				? state._i18n?.copied || 'Copied!'
				: state._i18n?.copyLink || 'Copy link';
		},
	},

	/* -----------------------------------------------------------------
	 * ACTIONS
	 * ----------------------------------------------------------------- */

	actions: {
		/* === Favorites === */

		/**
		 * Toggle favorite — directive handler.
		 *
		 * When called from a data-wp-on--click directive on an element whose
		 * context (in the petsync namespace) contains petId,
		 * getPetIdFromContext() finds it directly. Child namespaces (grid,
		 * modal) should import and call doToggleFavorite() instead.
		 */
		*toggleFavorite() {
			const { petId, petName: ctxPetName } = getPetIdFromContext();
			yield* doToggleFavorite( petId, ctxPetName );
		},

		/**
		 * Open the favorites modal.
		 * Used by the deprecated pet-favorites-toggle block for backward compat.
		 * Finds the favorites modal trigger and clicks it.
		 */
		openFavoritesModal() {
			const trigger = document.querySelector(
				'.pet-favorites-modal__trigger'
			);
			if ( trigger ) {
				trigger.click();
			}
		},

		/* === Comparison === */

		*toggleComparison() {
			const { petId, petName: ctxPetName } = getPetIdFromContext();
			yield* doToggleComparison( petId, ctxPetName );
		},

		*removeFromComparison() {
			const { petId, petName: ctxPetName } = getPetIdFromContext();
			if ( ! petId ) {
				return;
			}
			state.comparison = state.comparison.filter(
				( id ) => id !== petId
			);
			if ( state.pets[ petId ] ) {
				state.pets[ petId ].compared = false;
			}
			const petName = ctxPetName || state.pets[ petId ]?.name || 'Pet';
			announce( `${ petName } removed from comparison` );

			try {
				const result = yield executeAbility(
					'petsync/update-comparison',
					{ action: 'remove', id: petId }
				);
				state.comparison = result.ids;
				storage.set( 'comparison', state.comparison );
			} catch ( error ) {
				state.comparison = [ ...state.comparison, petId ];
				if ( state.pets[ petId ] ) {
					state.pets[ petId ].compared = true;
				}
				console.error( 'Failed to remove from comparison:', error );
			}
		},

		*clearComparison() {
			const oldComparison = [ ...state.comparison ];
			oldComparison.forEach( ( id ) => {
				if ( state.pets[ id ] ) {
					state.pets[ id ].compared = false;
				}
			} );
			state.comparison = [];
			announce( 'Comparison cleared' );

			try {
				yield executeAbility( 'petsync/update-comparison', {
					action: 'clear',
				} );
				storage.set( 'comparison', [] );
			} catch ( error ) {
				state.comparison = oldComparison;
				oldComparison.forEach( ( id ) => {
					if ( state.pets[ id ] ) {
						state.pets[ id ].compared = true;
					}
				} );
				console.error( 'Failed to clear comparison:', error );
			}
		},

		*viewComparison() {
			if ( state.comparison.length < 2 ) {
				announce( 'Add at least 2 pets to compare' );
				return;
			}
			const archiveUrl = getArchiveUrl();
			const compareUrl = new URL( archiveUrl, window.location.origin );
			compareUrl.searchParams.set(
				'compare',
				state.comparison.join( ',' )
			);
			// Hard reload — the comparison block is outside the grid's
			// router region, so router navigation can't render it.
			window.location.href = compareUrl.toString();
		},

		*shareComparison() {
			if ( state.comparison.length < 2 ) {
				announce( 'Add at least 2 pets to share' );
				return;
			}
			try {
				const result = yield executeAbility(
					'petsync/get-comparison',
					null,
					{ method: 'GET' }
				);
				if ( navigator.share ) {
					yield navigator.share( {
						title: 'Compare Pets',
						url: result.shareUrl,
					} );
				} else {
					const copied = yield copyToClipboard( result.shareUrl );
					if ( copied ) {
						showToast( 'Link copied!' );
					}
				}
			} catch ( error ) {
				if ( error.name !== 'AbortError' ) {
					console.error( 'Share failed:', error );
				}
			}
		},

		/* === Pet cache & misc === */

		cachePet( pet ) {
			if ( ! pet?.id ) {
				return;
			}
			state.pets[ pet.id ] = {
				...state.pets[ pet.id ],
				...pet,
				favorited: state.favorites.includes( pet.id ),
				compared: state.comparison.includes( pet.id ),
			};
		},

		cachePets( pets ) {
			if ( ! Array.isArray( pets ) ) {
				return;
			}
			pets.forEach( ( pet ) => actions.cachePet( pet ) );
		},

		notify( message ) {
			showToast( message );
		},

		clearNotification() {
			state.notification = null;
		},

		/* === Share dropdown === */

		toggleShareMenu() {
			const ctx = getContext();
			ctx._shareMenuOpen = ! ( ctx._shareMenuOpen ?? false );
			// Reset copy state when opening.
			if ( ctx._shareMenuOpen ) {
				ctx._linkCopied = false;
			}
		},

		closeShareMenu() {
			const ctx = getContext();
			ctx._shareMenuOpen = false;
		},

		closeShareMenuOnOutsideClick( event ) {
			const ctx = getContext();
			if ( ! ( ctx._shareMenuOpen ?? false ) ) {
				return;
			}
			const { ref } = getElement();
			const wrapper = ref?.closest( '.pet-actions__share-wrapper' );
			if ( wrapper && ! wrapper.contains( event.target ) ) {
				ctx._shareMenuOpen = false;
			}
		},

		closeShareMenuOnEscape( event ) {
			const ctx = getContext();
			if ( event.key === 'Escape' && ( ctx._shareMenuOpen ?? false ) ) {
				ctx._shareMenuOpen = false;
				event.preventDefault();
			}
		},

		nativeShare() {
			const ctx = getContext();
			const petUrl = ctx.petUrl || window.location.href;
			const petName = ctx.petName || 'this pet';
			ctx._shareMenuOpen = false;
			if ( navigator.share ) {
				navigator
					.share( {
						title: petName,
						url: petUrl,
					} )
					.catch( () => {} );
			}
		},

		copyPetLink() {
			const ctx = getContext();
			const petUrl = ctx.petUrl || window.location.href;
			if ( navigator.clipboard ) {
				navigator.clipboard
					.writeText( petUrl )
					.then( () => {
						ctx._linkCopied = true;
						announce(
							state._i18n?.copiedAnnounce ||
								'Link copied to clipboard'
						);
						// Reset after 2 seconds.
						setTimeout( () => {
							ctx._linkCopied = false;
						}, 2000 );
					} )
					.catch( () => {
						announce( state._i18n?.error || 'Failed to copy link' );
					} );
			}
		},
	},

	/* -----------------------------------------------------------------
	 * CALLBACKS
	 * ----------------------------------------------------------------- */

	callbacks: {
		/**
		 * Initialize global state from localStorage on first load.
		 */
		initGlobalState() {
			if ( state.favorites.length === 0 ) {
				const stored = storage.get( 'favorites', [] );
				if ( stored.length > 0 ) {
					state.favorites = stored;
				}
			}
			if ( state.comparison.length === 0 ) {
				const stored = storage.get( 'comparison', [] );
				if ( stored.length > 0 ) {
					state.comparison = stored;
				}
			}
		},

		/**
		 * Register a pet from context into the global pets cache.
		 */
		registerPet() {
			const ctx = getContext();
			if ( ctx.petId ) {
				actions.cachePet( {
					id: ctx.petId,
					name: ctx.petName,
					image: ctx.petImage,
					thumb: ctx.petThumb,
					url: ctx.petUrl,
					status: ctx.petStatus,
					breed: ctx.petBreed,
					animal: ctx.petAnimal,
					age: ctx.petAge,
					sex: ctx.petSex,
					size: ctx.petSize,
				} );
			}
		},
	},
} );

export {
	state,
	actions,
	callbacks,
	storage,
	announce,
	executeAbility,
	doToggleFavorite,
	doToggleComparison,
};
