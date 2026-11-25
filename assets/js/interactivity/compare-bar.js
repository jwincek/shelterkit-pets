/**
 * Pet Compare Bar View Module
 *
 * v4.2.0: Fully declarative — no imperative DOM manipulation.
 *
 * Each slot has a context with { slotIndex, petId }. Derived getters
 * read getGlobalState().comparison[slotIndex] and resolve pet data from the
 * global pets cache. This approach works with WP 6.9 SSR because the
 * server renders real HTML with correct initial state, and the client
 * bindings update reactively when comparison state changes.
 *
 * @package
 * @since 4.2.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { announce, storage, executeAbility } from '../utils.js';

const getGlobalState = () => store( 'petsync' ).state;

const { state, actions, callbacks } = store( 'petsync/compare-bar', {
	state: {
		get comparisonCount() {
			return getGlobalState().comparison.length;
		},

		get isEmpty() {
			return getGlobalState().comparison.length === 0;
		},

		get isFull() {
			return (
				getGlobalState().comparison.length >=
				getGlobalState().comparisonMax
			);
		},

		get canCompare() {
			return getGlobalState().comparison.length >= 2;
		},

		get comparisonMax() {
			return getGlobalState().comparisonMax;
		},

		/**
		 * Per-slot derived getters.
		 * Each slot element has context.slotIndex set by the server.
		 */
		get slotPet() {
			const ctx = getContext();
			if ( typeof ctx.slotIndex !== 'number' ) {
				return null;
			}
			const petId = getGlobalState().comparison[ ctx.slotIndex ];
			return petId ? getGlobalState().pets[ petId ] : null;
		},

		get slotHasPet() {
			const ctx = getContext();
			if ( typeof ctx.slotIndex !== 'number' ) {
				return false;
			}
			return ctx.slotIndex < getGlobalState().comparison.length;
		},

		get slotImage() {
			const pet = state.slotPet;
			return pet?.image || pet?.thumb || '';
		},

		get slotName() {
			const pet = state.slotPet;
			return pet?.name || '';
		},

		get slotRemoveLabel() {
			const pet = state.slotPet;
			const name = pet?.name || 'pet';
			return `Remove ${ name } from comparison`;
		},
	},

	actions: {
		/**
		 * Remove the pet in this slot from comparison.
		 * Reads petId from the resolved comparison array via slotIndex.
		 */
		*removeFromSlot() {
			const ctx = getContext();
			const petId = getGlobalState().comparison[ ctx.slotIndex ];
			if ( ! petId ) {
				return;
			}

			// Optimistic update.
			getGlobalState().comparison = getGlobalState().comparison.filter(
				( id ) => id !== petId
			);
			if ( getGlobalState().pets[ petId ] ) {
				getGlobalState().pets[ petId ].compared = false;
			}
			announce( 'Removed from comparison' );

			try {
				const result = yield executeAbility(
					'petsync/update-comparison',
					{
						action: 'remove',
						id: petId,
					}
				);
				getGlobalState().comparison = result.ids;
				storage.set( 'comparison', getGlobalState().comparison );
			} catch {
				storage.set( 'comparison', getGlobalState().comparison );
			}
		},

		*clearComparison() {
			yield store( 'petsync' ).actions.clearComparison();
		},

		/**
		 * Navigate to comparison page.
		 * Uses hard reload because the comparison block sits outside
		 * the grid's router region in the template.
		 */
		*viewComparison() {
			if ( getGlobalState().comparison.length < 2 ) {
				announce( 'Add at least 2 pets to compare' );
				return;
			}

			const ctx = getContext();
			const archiveUrl =
				ctx.archiveUrl ||
				document.querySelector( '[data-pets-archive-url]' )?.dataset
					.petsArchiveUrl ||
				'/pets/';

			const url = new URL( archiveUrl, window.location.origin );
			url.searchParams.set(
				'compare',
				getGlobalState().comparison.join( ',' )
			);

			// On a pet's own page, pass its ID so the comparison page can
			// offer a "Continue viewing [Pet]" link back to it.
			if ( ctx.fromPetId ) {
				url.searchParams.set( 'from', String( ctx.fromPetId ) );
			}

			window.location.href = url.toString();
		},

		*shareComparison() {
			yield store( 'petsync' ).actions.shareComparison();
		},

		/**
		 * Toggle compare bar between expanded (full) and collapsed (pill).
		 */
		toggleBar() {
			const gs = getGlobalState();
			gs._compareBarExpanded = ! gs._compareBarExpanded;
			storage.set( 'compareBarExpanded', gs._compareBarExpanded );
		},

		/**
		 * Expand the bar (e.g. clicking the collapsed pill).
		 */
		expandBar() {
			const gs = getGlobalState();
			gs._compareBarExpanded = true;
			storage.set( 'compareBarExpanded', true );
		},
	},

	callbacks: {
		/**
		 * Initialize — restore expand/collapse preference and sync comparison.
		 */
		init() {
			const gs = getGlobalState();

			// Restore user preference. Default to expanded.
			const savedPref = storage.get( 'compareBarExpanded' );
			gs._compareBarExpanded = savedPref !== null ? savedPref : true;

			// Seed the previous count so watchAutoExpand doesn't
			// false-trigger on the first evaluation.
			gs._compareBarPrevCount = gs.comparison.length;

			// Server state is truth — sync to localStorage.
			storage.set( 'comparison', gs.comparison );
		},

		/**
		 * Auto-expand the bar when the first pet is added to comparison.
		 * Tracks the previous count to detect the 0→1 transition only.
		 */
		watchAutoExpand() {
			const gs = getGlobalState();
			const count = gs.comparison.length;
			const prev = gs._compareBarPrevCount ?? 0;
			gs._compareBarPrevCount = count;

			// Only auto-expand on the 0→1 transition.
			if ( prev === 0 && count === 1 ) {
				gs._compareBarExpanded = true;
				storage.set( 'compareBarExpanded', true );
			}
		},
	},
} );

export { state, actions, callbacks };
