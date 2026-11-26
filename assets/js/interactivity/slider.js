/**
 * Pet Slider View Module
 *
 * v3.0.0: generators for async pet actions, imports from store.js
 *
 * @package
 * @since 1.0.0
 */

import { store, getContext, getElement } from '@wordpress/interactivity';
import { actions as globalActions, state as globalState } from '../store.js';

/* === Autoplay Controller === */

class SliderAutoplay {
	constructor( sliderElement, options = {} ) {
		this.slider = sliderElement;
		this.hoverTarget = options.hoverTarget || sliderElement;
		this.speed = options.speed || 5000;
		this.timerId = null;
		this.isPaused = false;
		this.isDestroyed = false;
		this.prefersReducedMotion = window.matchMedia(
			'(prefers-reduced-motion: reduce)'
		).matches;

		this.tick = this.tick.bind( this );
		this.pause = this.pause.bind( this );
		this.resume = this.resume.bind( this );
		this.handleVisibilityChange = this.handleVisibilityChange.bind( this );

		this.setupListeners();
		if ( ! this.prefersReducedMotion ) {
			this.start();
		}
	}

	setupListeners() {
		this.hoverTarget.addEventListener( 'mouseenter', this.pause );
		this.hoverTarget.addEventListener( 'mouseleave', this.resume );
		this.hoverTarget.addEventListener( 'focusin', this.pause );
		this.hoverTarget.addEventListener( 'focusout', this.resume );
		document.addEventListener(
			'visibilitychange',
			this.handleVisibilityChange
		);
	}

	handleVisibilityChange() {
		if ( document.hidden ) {
			this.pause();
		} else {
			this.resume();
		}
	}

	start() {
		if ( this.isDestroyed || this.prefersReducedMotion ) {
			return;
		}
		this.stop();
		this.timerId = setInterval( this.tick, this.speed );
	}

	stop() {
		if ( this.timerId ) {
			clearInterval( this.timerId );
			this.timerId = null;
		}
	}

	pause() {
		this.isPaused = true;
		this.slider.classList.add( 'is-paused' );
	}

	resume() {
		if (
			this.hoverTarget.contains(
				this.hoverTarget.ownerDocument.activeElement
			) ||
			document.hidden
		) {
			return;
		}
		this.isPaused = false;
		this.slider.classList.remove( 'is-paused' );
	}

	reset() {
		if ( this.isDestroyed || this.prefersReducedMotion ) {
			return;
		}
		this.stop();
		this.start();
	}

	tick() {
		if ( this.isPaused || this.isDestroyed ) {
			return;
		}
		this.slider.dispatchEvent(
			new CustomEvent( 'petstablished-autoplay-tick' )
		);
	}

	destroy() {
		if ( this.isDestroyed ) {
			return;
		}
		this.isDestroyed = true;
		this.stop();
		this.hoverTarget.removeEventListener( 'mouseenter', this.pause );
		this.hoverTarget.removeEventListener( 'mouseleave', this.resume );
		this.hoverTarget.removeEventListener( 'focusin', this.pause );
		this.hoverTarget.removeEventListener( 'focusout', this.resume );
		document.removeEventListener(
			'visibilitychange',
			this.handleVisibilityChange
		);
		delete this.slider._autoplay;
	}
}

/* === Store Definition === */

const { state, actions } = store( 'petsync/slider', {
	state: {
		get currentPet() {
			const ctx = getContext();
			return ( ctx.pets || [] )[ ctx.currentIndex || 0 ] || null;
		},

		get currentPetId() {
			return state.currentPet?.id || null;
		},
		get currentPetName() {
			return state.currentPet?.name || '';
		},
		get currentPetUrl() {
			return state.currentPet?.url || '';
		},
		get currentPetImage() {
			return state.currentPet?.image || '';
		},

		get currentPetMeta() {
			const pet = state.currentPet;
			if ( ! pet ) {
				return '';
			}
			return [ pet.breed, pet.age, pet.sex ]
				.filter( Boolean )
				.join( ' · ' );
		},

		get currentNumber() {
			const ctx = getContext();
			return ( ctx.currentIndex || 0 ) + 1;
		},

		get totalSlides() {
			const ctx = getContext();
			return ctx.pets?.length || 0;
		},

		get hasMultipleSlides() {
			const ctx = getContext();
			return ( ctx.pets?.length || 0 ) > 1;
		},

		get isActiveSlide() {
			const ctx = getContext();
			return ctx.petIndex === ctx.currentIndex;
		},

		get isDotActive() {
			const ctx = getContext();
			return ctx.dotIndex === ctx.currentIndex;
		},

		get isCurrentPetFavorited() {
			const petId = state.currentPetId;
			return petId ? globalState.favorites.includes( petId ) : false;
		},

		get isCurrentPetInComparison() {
			const petId = state.currentPetId;
			return petId ? globalState.comparison.includes( petId ) : false;
		},

		get isFavorited() {
			const ctx = getContext();
			return ctx.petId
				? globalState.favorites.includes( ctx.petId )
				: false;
		},

		get isInComparison() {
			const ctx = getContext();
			return ctx.petId
				? globalState.comparison.includes( ctx.petId )
				: false;
		},

		get isCompareDisabled() {
			const ctx = getContext();
			const petId = ctx.petId;
			if ( ! petId ) {
				return false;
			}
			return (
				! globalState.comparison.includes( petId ) &&
				globalState.comparison.length >= globalState.comparisonMax
			);
		},
	},

	actions: {
		next() {
			const ctx = getContext();
			const pets = ctx.pets || [];
			if ( pets.length <= 1 ) {
				return;
			}
			const el = getElement();
			ctx.currentIndex = ( ( ctx.currentIndex || 0 ) + 1 ) % pets.length;
			el.ref?._autoplay?.reset();
		},

		prev() {
			const ctx = getContext();
			const pets = ctx.pets || [];
			if ( pets.length <= 1 ) {
				return;
			}
			const el = getElement();
			const len = pets.length;
			ctx.currentIndex = ( ( ctx.currentIndex || 0 ) - 1 + len ) % len;
			el.ref?._autoplay?.reset();
		},

		goTo() {
			const ctx = getContext();
			const el = getElement();
			if ( typeof ctx.dotIndex === 'number' ) {
				ctx.currentIndex = ctx.dotIndex;
				el.ref?._autoplay?.reset();
			}
		},

		pause() {
			getElement().ref?._autoplay?.pause();
		},
		resume() {
			getElement().ref?._autoplay?.resume();
		},

		// Card-mode: petId already in context.
		toggleFavorite() {
			globalActions.toggleFavorite();
		},
		toggleComparison() {
			globalActions.toggleComparison();
		},

		// Current-slide mode: temporarily set context to active slide's pet.
		*toggleCurrentPetFavorite() {
			const petId = state.currentPetId;
			if ( ! petId ) {
				return;
			}

			const ctx = getContext();
			const originalPetId = ctx.petId;
			const originalPetName = ctx.petName;

			ctx.petId = petId;
			ctx.petName = state.currentPetName;

			try {
				yield globalActions.toggleFavorite();
			} finally {
				ctx.petId = originalPetId;
				ctx.petName = originalPetName;
			}
		},

		*toggleCurrentPetComparison() {
			const petId = state.currentPetId;
			if ( ! petId ) {
				return;
			}

			const ctx = getContext();
			const originalPetId = ctx.petId;
			const originalPetName = ctx.petName;

			ctx.petId = petId;
			ctx.petName = state.currentPetName;

			try {
				yield globalActions.toggleComparison();
			} finally {
				ctx.petId = originalPetId;
				ctx.petName = originalPetName;
			}
		},

		// Touch handling.
		handleTouchStart( event ) {
			getContext().touchStartX = event.touches[ 0 ].clientX;
		},

		handleTouchEnd( event ) {
			const ctx = getContext();
			if ( typeof ctx.touchStartX !== 'number' ) {
				return;
			}
			const diff = ctx.touchStartX - event.changedTouches[ 0 ].clientX;
			if ( Math.abs( diff ) > 50 ) {
				if ( diff > 0 ) {
					actions.next();
				} else {
					actions.prev();
				}
			}
			ctx.touchStartX = null;
		},
	},

	callbacks: {
		init() {
			const el = getElement();
			const ctx = getContext();

			if ( typeof ctx.currentIndex !== 'number' ) {
				ctx.currentIndex = 0;
			}
			ctx._prevIndex = ctx.currentIndex;

			if ( ctx.autoplay && ! el.ref._autoplay ) {
				// Only pause when hovering over the card track or hero image,
				// not the surrounding header, nav buttons, or dots.
				const hoverEl =
					el.ref.querySelector( '.pet-slider__track' ) ||
					el.ref.querySelector( '.pet-slider__hero-image-wrap' ) ||
					el.ref;
				el.ref._autoplay = new SliderAutoplay( el.ref, {
					speed: ctx.autoplaySpeed || 5000,
					hoverTarget: hoverEl,
				} );

				// Advance to next slide on autoplay tick.
				// We capture ctx here because the raw event listener
				// runs outside the Interactivity API directive scope,
				// where getContext() is not available.
				const pets = ctx.pets || [];
				el.ref.addEventListener( 'petstablished-autoplay-tick', () => {
					if ( pets.length <= 1 ) {
						return;
					}
					ctx.currentIndex =
						( ( ctx.currentIndex || 0 ) + 1 ) % pets.length;
					el.ref._autoplay?.reset();
				} );
			}

			if ( ctx.pets?.length ) {
				globalActions.cachePets( ctx.pets );
			}
		},

		/**
		 * Watches currentIndex and scrolls the matching slide into view.
		 * Runs on the viewport element via data-wp-watch.
		 */
		syncScroll() {
			const el = getElement();
			const ctx = getContext();
			const index = ctx.currentIndex || 0;
			const viewport = el.ref;
			if ( ! viewport ) {
				return;
			}

			const slides = viewport.querySelectorAll( '.pet-slider__slide' );
			const target = slides[ index ];
			if ( ! target ) {
				return;
			}

			// Use scrollTo for precise positioning that respects the track padding.
			const trackPadding =
				parseFloat(
					getComputedStyle(
						viewport.querySelector( '.pet-slider__track' ) ||
							viewport
					).paddingLeft
				) || 0;

			viewport.scrollTo( {
				left: target.offsetLeft - trackPadding,
				behavior: 'smooth',
			} );
		},

		/**
		 * Crossfade transition for hero mode.
		 * Briefly sets isTransitioning on the context when currentIndex changes,
		 * which triggers a CSS opacity fade on the hero image.
		 */
		heroCrossfade() {
			const ctx = getContext();
			const index = ctx.currentIndex || 0;

			// Only fire on actual index changes.
			if ( ctx._prevIndex === index ) {
				return;
			}
			ctx._prevIndex = index;

			ctx.isTransitioning = true;
			// Allow the fade-out to play, then the src binding updates, then fade back in.
			setTimeout( () => {
				ctx.isTransitioning = false;
			}, 300 );
		},

		destroy() {
			getElement().ref?._autoplay?.destroy();
		},
	},
} );

export { state, actions, SliderAutoplay };
