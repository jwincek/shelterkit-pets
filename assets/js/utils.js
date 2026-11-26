/**
 * Petstablished — Shared Utilities
 *
 * Common functions used across all stores. Extracted from the global store
 * to break the dependency chain — block stores import from here instead
 * of importing the entire global store.
 *
 * @package
 * @since 1.0.0
 */

import { getConfig } from '@wordpress/interactivity';

/* === Configuration === */

/**
 * Get shared configuration.
 *
 * @return {Object} Configuration object from wp_interactivity_config().
 */
export function getSharedConfig() {
	return getConfig( 'petsync' ) || {};
}

/* === Storage (localStorage + cookie fallback for anonymous users) === */

export const storage = {
	get( key, defaultValue = null ) {
		try {
			const stored = localStorage.getItem( `petstablished_${ key }` );
			return stored ? JSON.parse( stored ) : defaultValue;
		} catch {
			return defaultValue;
		}
	},
	set( key, value ) {
		try {
			localStorage.setItem(
				`petstablished_${ key }`,
				JSON.stringify( value )
			);
			// Also set cookie for server-side access.
			const expires = new Date(
				Date.now() + 30 * 24 * 60 * 60 * 1000
			).toUTCString();
			document.cookie = `pet_${ key }=${ encodeURIComponent(
				JSON.stringify( value )
			) };expires=${ expires };path=/`;
		} catch {
			// Storage unavailable.
		}
	},
};

/* === Accessibility Announcer === */

/**
 * Announce a message to screen readers via a live region.
 *
 * @param {string} message  The message to announce.
 * @param {string} priority 'polite' or 'assertive'.
 */
export function announce( message, priority = 'polite' ) {
	if ( window.wp?.a11y?.speak ) {
		window.wp.a11y.speak( message, priority );
		return;
	}
	let region = document.getElementById( 'pet-live-region' );
	if ( ! region ) {
		region = document.createElement( 'div' );
		region.id = 'pet-live-region';
		region.setAttribute( 'role', 'status' );
		region.setAttribute( 'aria-live', priority );
		region.className = 'screen-reader-text';
		document.body.appendChild( region );
	}
	region.textContent = '';
	setTimeout( () => ( region.textContent = message ), 50 );
}

/* === Abilities API Client === */

/**
 * Execute a registered Ability via REST.
 *
 * Routes through the plugin's own thin REST controller at:
 *   /wp-json/petsync/v1/{namespace}/{ability}/run
 *
 * This is necessary because the WP 6.9 core Abilities REST API at
 * /wp-abilities/v1/ requires an authenticated user for ALL endpoints,
 * but favorites and comparison must work for anonymous front-end visitors.
 *
 * Follows WP 6.9 Abilities REST conventions:
 * - POST input is wrapped as { "input": { ... } }
 * - GET input is passed as a URL-encoded `input` query parameter
 * - Endpoint path ends in /run
 *
 * @param {string} abilityName Namespaced ability, e.g. 'petsync/toggle-favorite'.
 * @param {Object} [input]     Input data matching the ability's input_schema.
 * @param {Object} [options]   Override method ('GET'|'POST'|'DELETE') — auto-detected if omitted.
 * @return {Promise<any>}       The ability's output.
 */
export async function executeAbility(
	abilityName,
	input = null,
	options = {}
) {
	const config = getSharedConfig();
	const restUrl = config.restUrl || '/wp-json/';
	let url = `${ restUrl }petsync/v1/${ abilityName }/run`;
	const method = options.method || ( input !== null ? 'POST' : 'GET' );

	const fetchOptions = {
		method,
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
	};

	if ( method === 'POST' && input !== null ) {
		// POST: input wrapped in { input: ... } body.
		fetchOptions.body = JSON.stringify( { input } );
	} else if ( input !== null ) {
		// GET/DELETE with input: URL-encoded `input` query parameter.
		url += `?input=${ encodeURIComponent( JSON.stringify( input ) ) }`;
	}

	const response = await fetch( url, fetchOptions );

	if ( ! response.ok ) {
		const error = await response.json().catch( () => ( {} ) );
		throw new Error( error.message || `Ability ${ abilityName } failed` );
	}

	return response.json();
}

/* === Utility Functions === */

/**
 * Debounce function execution.
 *
 * @param {Function} fn    Function to debounce.
 * @param {number}   delay Delay in milliseconds.
 * @return {Function} Debounced function.
 */
export function debounce( fn, delay = 300 ) {
	let timeoutId;
	return function ( ...args ) {
		clearTimeout( timeoutId );
		timeoutId = setTimeout( () => fn.apply( this, args ), delay );
	};
}

/**
 * Copy text to clipboard with fallback for non-secure contexts.
 *
 * navigator.clipboard requires HTTPS. Falls back to the legacy
 * execCommand('copy') approach for HTTP dev environments.
 *
 * @param {string} text Text to copy.
 * @return {Promise<boolean>} Whether the copy succeeded.
 */
export async function copyToClipboard( text ) {
	if ( navigator.clipboard ) {
		try {
			await navigator.clipboard.writeText( text );
			return true;
		} catch {
			// clipboard.writeText can throw even when available
			// (e.g. document not focused). Fall through to legacy.
		}
	}

	// Legacy fallback — works on HTTP.
	try {
		const textarea = document.createElement( 'textarea' );
		textarea.value = text;
		textarea.style.cssText = 'position:fixed;opacity:0;left:-9999px';
		document.body.appendChild( textarea );
		textarea.select();
		const ok = document.execCommand( 'copy' );
		document.body.removeChild( textarea );
		return ok;
	} catch {
		return false;
	}
}
export function escapeHtml( str ) {
	return ( str || '' )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );
}

/**
 * Highlight search matches in text with <mark> tags.
 *
 * @param {string} text  The text to search within.
 * @param {string} query The search query to highlight.
 * @return {string} HTML string with matches wrapped in <mark>.
 */
export function highlightText( text, query ) {
	if ( ! text || ! query ) {
		return escapeHtml( text || '' );
	}

	const escapedText = escapeHtml( text );
	const escapedQuery = query.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	return escapedText.replace(
		new RegExp( `(${ escapedQuery })`, 'gi' ),
		'<mark class="pet-search-highlight">$1</mark>'
	);
}
