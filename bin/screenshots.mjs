#!/usr/bin/env node
/**
 * Capture the WordPress.org screenshots.
 *
 *   SKP_URL=http://larkspurlane.local SKP_SITE_ROOT=~/Local\ Sites/larkspurlane/app/public npm run screenshots
 *
 * Runs against a DEMO site, never a real shelter's: a fresh WordPress with
 * ShelterKit Pets active and the demo shelter loaded (assets-src/demo/README.md).
 * The script checks Shelter Details name the demo shelter and refuses otherwise.
 * Onsite Spam Guard captures its settings screens on the dev site and tidies up
 * afterwards; here the animals are the picture, so they cannot share a site
 * with real ones.
 *
 * Prerequisites: `npm install`, then `npx playwright install chromium` once.
 * Drives Playwright's own Chromium, pinned by the exact Playwright version in
 * package.json, so the renderer does not drift with a browser update. (An
 * older Playwright paired with a newer cached browser stopped drawing frames
 * after every form submission.)
 *
 * Environment:
 *   SKP_URL        the site's address, as its pages link to it (required)
 *   SKP_SITE_ROOT  the site's WordPress directory, for wp-cli (required)
 *   SKP_WP_ARGS    extra wp-cli arguments, e.g. --require=/path/dbhost.php
 *   SKP_ORIGIN     where the site actually listens, when that differs from
 *                  SKP_URL. Requests to SKP_URL are then served from here, so a
 *                  site run with `php -S 127.0.0.1:8080` can print a tidy
 *                  domain on its kennel cards. The site must then believe it is
 *                  SKP_URL: in wp-config.php, set WP_HOME and WP_SITEURL to it
 *                  and $_SERVER['HTTP_HOST'] to its host.
 *   SKP_OUT        output directory (default .wordpress-org)
 *   SKP_TIMEOUT    navigation timeout in ms (default 120000)
 *
 * Numbering is load-bearing: `screenshot-N.png` pairs with the Nth line of
 * readme.txt's `== Screenshots ==` block, and a mismatch fails silently on
 * WordPress.org. SHOTS below is the source of that order, and the script
 * refuses to run if it and the readme disagree on how many there are.
 */

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PLUGIN_DIR = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const DEMO_DIR   = path.join( PLUGIN_DIR, 'assets-src/demo' );

const SITE      = ( process.env.SKP_URL || '' ).replace( /\/$/, '' );
const ORIGIN    = ( process.env.SKP_ORIGIN || '' ).replace( /\/$/, '' );
const SITE_ROOT = process.env.SKP_SITE_ROOT || '';
const WP_ARGS   = ( process.env.SKP_WP_ARGS || '' ).split( ' ' ).filter( Boolean );
const OUT_DIR   = path.resolve( PLUGIN_DIR, process.env.SKP_OUT || '.wordpress-org' );
const TIMEOUT   = Number( process.env.SKP_TIMEOUT || 120000 );

// As Onsite Spam Guard: 1440 CSS px wide at 2x.
const WIDTH = 1440;
const SCALE = 2;

const HIDE = `
	#wpadminbar, #wpfooter, .notice, .update-nag, #screen-meta, #screen-meta-links { display: none !important; }
	html.wp-toolbar { padding-top: 0 !important; }
	html { scroll-behavior: auto !important; }
`;

/** The published order. Keep in step with readme.txt `== Screenshots ==`. */
const SHOTS = [
	{ n: 1, name: 'Archive with filters', run: archive },
	{ n: 2, name: 'Printed kennel cards', run: kennelSheet },
	{ n: 3, name: 'Kennel card picker', run: kennelPicker },
	{ n: 4, name: 'A pet\'s page', run: petPage },
	{ n: 5, name: 'Editor panels', run: editor },
	{ n: 6, name: 'Import preview', run: importPreview },
	{ n: 7, name: 'Comparison', run: comparison },
	{ n: 8, name: 'Sync settings, unconnected', run: syncSettings },
];

// ── wp-cli ──────────────────────────────────────────────────────────────────

/**
 * Run PHP through wp-cli and return only what it deliberately printed.
 * Delimited, because other code's notices share the stream (see Onsite Spam
 * Guard's bin/screenshots.mjs, where this comes from).
 */
function wp( php ) {
	const START = '<<<SKP';
	const END   = 'SKP>>>';
	const raw   = execFileSync(
		'wp',
		[ 'eval', `echo "${ START }"; ${ php } ; echo "${ END }";`, `--path=${ SITE_ROOT }`, ...WP_ARGS, '--skip-themes' ],
		{ encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }
	);
	const start = raw.indexOf( START );
	const end   = raw.indexOf( END );
	if ( start === -1 || end === -1 ) {
		throw new Error( `wp-cli produced no delimited output. Raw tail: ${ raw.slice( -300 ) }` );
	}
	return raw.slice( start + START.length, end ).trim();
}

/** Auth cookies minted through wp-cli, so the script never needs a password. */
function authCookies() {
	const out = wp( `
		$user = get_users( [ "role" => "administrator", "number" => 1 ] );
		if ( ! $user ) { echo "NO_ADMIN"; } else {
			$id = $user[0]->ID; $exp = time() + 3600;
			$token = WP_Session_Tokens::get_instance( $id )->create( $exp );
			echo AUTH_COOKIE . "\\t" . wp_generate_auth_cookie( $id, $exp, "auth", $token ) . "\\n";
			echo LOGGED_IN_COOKIE . "\\t" . wp_generate_auth_cookie( $id, $exp, "logged_in", $token );
		}
	` );
	if ( out === 'NO_ADMIN' || ! out.includes( '\t' ) ) {
		throw new Error( `Could not mint auth cookies. wp-cli said: ${ out }` );
	}
	const host = new URL( SITE ).hostname;
	return out.split( '\n' ).map( ( line ) => {
		const [ name, value ] = line.trim().split( '\t' );
		return { name, value, domain: host, path: '/' };
	} );
}

/** The demo pets' IDs and addresses by slug, and the archive's address. */
function siteFacts() {
	return JSON.parse( wp( `
		$pets = []; $links = [];
		foreach ( get_posts( [ "post_type" => "vcps_pet", "post_status" => "any", "numberposts" => -1 ] ) as $p ) { $pets[ $p->post_name ] = $p->ID; $links[ $p->post_name ] = get_permalink( $p ); }
		echo wp_json_encode( [
			"shelter" => ShelterKit_Profile::get( "name" ),
			"home"    => home_url(),
			"archive" => get_post_type_archive_link( "vcps_pet" ),
			"pets"    => $pets,
			"links"   => $links,
		] );
	` ) );
}

// ── Browser ─────────────────────────────────────────────────────────────────

async function newContext( browser, { admin = false, cookies = [] } = {} ) {
	const context = await browser.newContext( {
		viewport: { width: WIDTH, height: 1000 },
		deviceScaleFactor: SCALE,
	} );

	// Nothing leaves the machine: anything not on the site is refused.
	await context.route( ( url ) => ! url.href.startsWith( SITE ) && ! [ 'data:', 'blob:' ].includes( url.protocol ), ( route ) => route.abort() );

	if ( ORIGIN ) {
		await context.route( `${ SITE }/**`, async ( route ) => {
			const request = route.request();
			try {
				const response = await route.fetch( {
					url: ORIGIN + request.url().slice( SITE.length ),
					maxRedirects: 0,
					// Uncompressed: wp-admin's load-scripts.php sends raw
					// deflate, which Playwright's fetch cannot decode.
					headers: { ...request.headers(), 'accept-encoding': 'identity' },
				} );
				await route.fulfill( { response } );
			} catch ( error ) {
				console.error( `\n  could not serve ${ request.url() }: ${ error.message.split( '\n' )[ 0 ] }` );
				await route.abort();
			}
		} );
	}

	if ( admin ) {
		await context.addCookies( cookies );
	}

	return context;
}

async function open( context, url ) {
	const page = await context.newPage();
	await page.goto( url, { waitUntil: 'networkidle', timeout: TIMEOUT } );
	return page;
}

/**
 * An element screenshot, with WordPress's chrome hidden and, optionally, the
 * element cut to a height so a long page does not become a strip that the
 * listing page shrinks to nothing. Fails rather than publishing a blank.
 *
 * `jpeg` for shots that are mostly photographs: the archive grid was 3.8 MB as
 * a PNG. Whichever format a number is written in, the other is removed, so a
 * screenshot cannot exist twice.
 */
async function shot( page, n, selector, { cap = 0, css = '', settle = 800, jpeg = false } = {} ) {
	await page.addStyleTag( { content: HIDE + css } );
	if ( cap ) {
		await page.addStyleTag( { content: `${ selector } { max-height: ${ cap }px !important; overflow: hidden !important; }` } );
	}
	await page.waitForTimeout( settle );
	const el = page.locator( selector ).first();
	if ( ! ( await el.count() ) ) {
		throw new Error( `screenshot-${ n }: nothing matches ${ selector }` );
	}
	for ( const ext of [ 'png', 'jpg' ] ) {
		rmSync( path.join( OUT_DIR, `screenshot-${ n }.${ ext }` ), { force: true } );
	}
	await el.screenshot( {
		path: path.join( OUT_DIR, `screenshot-${ n }.${ jpeg ? 'jpg' : 'png' }` ),
		animations: 'disabled',
		...( jpeg ? { type: 'jpeg', quality: 85 } : {} ),
	} );
}

/**
 * How far below the top of `from` an edge of `to` sits, in CSS px, so a shot
 * can end at a natural break instead of mid-card. `edge` is 'top' or 'bottom'.
 */
async function reach( page, from, to, edge, pad ) {
	return page.evaluate( ( [ f, t, e, p ] ) => {
		const a = document.querySelector( f );
		const b = document.querySelector( t );
		return a && b ? Math.ceil( b.getBoundingClientRect()[ e ] - a.getBoundingClientRect().top + p ) : 0;
	}, [ from, to, edge, pad ] );
}

/** Drop the focus ring a click leaves on the last control. */
async function blur( page ) {
	await page.evaluate( () => document.activeElement?.blur() );
}

// ── The shots ───────────────────────────────────────────────────────────────

// 1. The archive: the shelter's own site, its filters and the first rows.
async function archive( { visitor, facts } ) {
	const page = await open( visitor, facts.archive );
	await page.waitForSelector( '.pet-listing-grid__item' );
	// End below the second row of cards rather than cutting into a third: the
	// sixth card's bottom edge, padded by less than the gap between rows.
	const cap = await page.evaluate( () => {
		const top  = document.querySelector( '.wp-site-blocks' ).getBoundingClientRect().top;
		const card = [ ...document.querySelectorAll( '.pet-listing-grid__item' ) ].filter( ( el ) => el.offsetParent )[ 5 ];
		return card ? Math.ceil( card.getBoundingClientRect().bottom - top + 12 ) : 0;
	} );
	await shot( page, 1, '.wp-site-blocks', { cap: cap || 1500, settle: 1500, jpeg: true } );
}

// 2. Kennel cards as printed, four to a sheet. The bonded pair show their
// line, and every card is checked to fit its cell first: a screenshot of a
// clipped card would advertise the problem #107 is about.
async function kennelSheet( { admin, facts } ) {
	const ids  = [ 'rocco', 'oreo', 'domino', 'cocoa' ].map( ( slug ) => facts.pets[ slug ] );
	const page = await open( admin, `${ SITE }/wp-admin/edit.php?post_type=vcps_pet&page=petsync-kennel-cards&view=print&size=index&${ ids.map( ( id ) => `pets%5B%5D=${ id }` ).join( '&' ) }` );
	const clipped = await page.$$eval( '.petsync-cards__cell', ( cells ) => cells.filter( ( cell ) => {
		const card = cell.querySelector( '.kennel-card' );
		return card && card.scrollHeight > cell.clientHeight;
	} ).map( ( cell ) => cell.querySelector( '.kennel-card__name' )?.textContent.trim() ) );
	if ( clipped.length ) {
		throw new Error( `screenshot-2: these cards overflow their cell: ${ clipped.join( ', ' ) }` );
	}
	await shot( page, 2, '.petsync-cards__page', { settle: 1200 } );
}

// 3. The picker, mid-selection.
async function kennelPicker( { admin } ) {
	const page  = await open( admin, `${ SITE }/wp-admin/edit.php?post_type=vcps_pet&page=petsync-kennel-cards` );
	const boxes = page.locator( 'input[name="pets[]"]' );
	const count = Math.min( 6, await boxes.count() );
	for ( let i = 0; i < count; i++ ) {
		await boxes.nth( i ).check();
	}
	await blur( page );
	await shot( page, 3, '#wpbody-content > .wrap', { cap: 1100 } );
}

// 4. A pet's page, as a visitor sees it: logged out, so the theme header
// carries no "Log out" link that no visitor ever sees.
async function petPage( { visitor, facts } ) {
	const page = await open( visitor, facts.links.rocco );
	// End above "Similar Pets", which only half fits.
	const cap = await reach( page, '.wp-site-blocks', '.wp-block-petsync-pet-slider', 'top', 8 );
	await shot( page, 4, '.wp-site-blocks', { cap: cap || 1500, settle: 1500, jpeg: true } );
}

// 5. Entering an animal by hand: the Pet Details panels in the editor.
// Lessons kept from the earlier capture script:
// - open only the panels the caption names, or the sidebar's own scroll hides
//   them and the shot shows Adoption and an empty Media panel;
// - hide core's document panels above them, whose red "Move to trash" was the
//   most prominent thing in the frame;
// - frame the editor with its sidebar, not the sidebar alone, which made a
//   560x3820 strip.
async function editor( { admin, facts } ) {
	const page = await admin.newPage();
	await page.setViewportSize( { width: 1600, height: 1150 } );
	await page.goto( `${ SITE }/wp-admin/post.php?post=${ facts.pets.rocco }&action=edit`, { waitUntil: 'domcontentloaded', timeout: TIMEOUT } );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId(), null, { timeout: TIMEOUT } );
	await page.evaluate( () => {
		wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' );
	} );
	await page.waitForSelector( '.shelterkit-pets-field-panel', { timeout: TIMEOUT } );

	// The welcome guide, or any other first-run modal, already open: switching
	// the preference off does not close it.
	for ( let i = 0; i < 3 && ( await page.locator( '.components-modal__screen-overlay' ).count() ); i++ ) {
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 400 );
	}
	if ( await page.locator( '.components-modal__screen-overlay' ).count() ) {
		throw new Error( 'screenshot-5: a modal in the editor would not close' );
	}

	const WANTED = [ 'Health', 'Good with', 'Adoption' ];
	for ( const label of [ 'Basics', 'Health', 'Good with', 'Adoption', 'Media' ] ) {
		const toggle = page.locator( `.shelterkit-pets-field-panel button.components-panel__body-toggle:has-text("${ label }")` ).first();
		if ( ! ( await toggle.count() ) ) {
			continue;
		}
		const isOpen = ( await toggle.getAttribute( 'aria-expanded' ) ) === 'true';
		if ( isOpen !== WANTED.includes( label ) ) {
			await toggle.click();
			await page.waitForTimeout( 300 );
		}
	}
	await blur( page );
	await page.waitForTimeout( 800 ); // Let the panels finish opening.
	await page.evaluate( () => {
		const sidebar = document.querySelector( '.interface-complementary-area, .editor-sidebar' );
		const first   = sidebar?.querySelector( '.shelterkit-pets-field-panel' );
		for ( let node = first?.previousElementSibling; node; node = node.previousElementSibling ) {
			node.style.display = 'none';
		}
	} );

	// The sidebar scrolls inside its own container, and an element screenshot
	// takes the box, not what is scrolled into it. So grow the window until
	// the last open panel ends inside it, with every scroller back at the top.
	const lastPanelBottom = () => page.evaluate( () => {
		document.querySelectorAll( '.interface-interface-skeleton__sidebar, .interface-interface-skeleton__sidebar *' ).forEach( ( el ) => {
			el.scrollTop = 0;
		} );
		const open = [ ...document.querySelectorAll( '.shelterkit-pets-field-panel.is-opened' ) ].pop();
		return open ? open.getBoundingClientRect().bottom : 0;
	} );
	for ( let i = 0; i < 4; i++ ) {
		const bottom = await lastPanelBottom();
		const height = page.viewportSize().height;
		if ( bottom + 16 <= height || height >= 2600 ) {
			break;
		}
		await page.setViewportSize( { width: 1600, height: Math.min( 2600, Math.ceil( bottom ) + 40 ) } );
		await page.waitForTimeout( 500 );
	}
	await lastPanelBottom();
	await shot( page, 5, '.interface-interface-skeleton', { settle: 1500 } );
}

// 6. The import preview, for a sheet that tells the story: two animals to
// add, Sunny marked adopted (an update, matched on the id column), a status
// the site does not have (refused), and a column nothing maps to. A sheet
// where every row is fine photographs as a table of identical rows and shows
// nothing of what the preview is for. Preview only: nothing is written.
async function importPreview( { admin, facts } ) {
	const csv = readFileSync( path.join( DEMO_DIR, 'intake.csv' ), 'utf8' ).replace( /^@([A-Za-z]+)/gm, ( match, name ) => {
		const id = facts.pets[ name.toLowerCase() ];
		if ( ! id ) {
			throw new Error( `intake.csv refers to @${ name }, which is not a pet on this site` );
		}
		return String( id );
	} );

	const page = await open( admin, `${ SITE }/wp-admin/edit.php?post_type=vcps_pet&page=shelterkit-pets-import` );
	await page.setInputFiles( '#petsync_csv', { name: 'intake.csv', mimeType: 'text/csv', buffer: Buffer.from( csv ) } );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'networkidle', timeout: TIMEOUT } ),
		page.click( 'button[name="petsync_import_preview"]' ),
	] );
	await shot( page, 6, '#wpbody-content > .wrap', { cap: 1700 } );
}

// 7. Two dogs side by side. The block renders only for ?compare=, which is
// read ahead of the visitor's cookie, so the address is the whole setup.
async function comparison( { visitor, facts } ) {
	const page = await open( visitor, `${ facts.archive }?compare=${ facts.pets.rocco },${ facts.pets.cocoa }` );
	// The floating favorites button sits over the table's corner here.
	await shot( page, 7, '.wp-block-petsync-pet-comparison', { settle: 1500, css: '.pet-favorites-modal__trigger { display: none !important; }' } );
}

// 8. Sync Settings with no Petstablished key: the caption's "connect it" is
// shown as a site owner first meets it. No sync history is faked.
async function syncSettings( { admin } ) {
	const page = await open( admin, `${ SITE }/wp-admin/edit.php?post_type=vcps_pet&page=shelterkit-pets` );
	await shot( page, 8, '#wpbody-content > .wrap', { cap: 1500 } );
}

// ── Run ─────────────────────────────────────────────────────────────────────

function readmeCaptions() {
	const readme = readFileSync( path.join( PLUGIN_DIR, 'readme.txt' ), 'utf8' );
	const block  = readme.split( '== Screenshots ==' )[ 1 ]?.split( /\n== / )[ 0 ] || '';
	return [ ...block.matchAll( /^(\d+)\.\s+(.*)$/gm ) ].map( ( m ) => m[ 2 ] );
}

async function main() {
	if ( ! SITE || ! SITE_ROOT ) {
		throw new Error( 'Set SKP_URL and SKP_SITE_ROOT. See the header of bin/screenshots.mjs.' );
	}

	const captions = readmeCaptions();
	if ( captions.length !== SHOTS.length || SHOTS.some( ( s, i ) => s.n !== i + 1 ) ) {
		throw new Error( `readme.txt has ${ captions.length } screenshot captions and SHOTS has ${ SHOTS.length }; they pair by number.` );
	}

	const facts   = siteFacts();
	const shelter = JSON.parse( readFileSync( path.join( DEMO_DIR, 'shelter.json' ), 'utf8' ) );
	if ( facts.shelter !== shelter.name ) {
		throw new Error( `This is not the demo site: Shelter Details name "${ facts.shelter }", not "${ shelter.name }". Load the demo shelter into a fresh site first.` );
	}
	if ( facts.home.replace( /\/$/, '' ) !== SITE ) {
		throw new Error( `The site's address is ${ facts.home }, but SKP_URL is ${ SITE }. Kennel cards print the site's address, so they must agree.` );
	}

	mkdirSync( OUT_DIR, { recursive: true } );
	const browser = await chromium.launch();
	const admin   = await newContext( browser, { admin: true, cookies: authCookies() } );
	const visitor = await newContext( browser );

	try {
		for ( const s of SHOTS ) {
			process.stdout.write( `  ${ s.n }. ${ s.name } … ` );
			await s.run( { admin, visitor, facts } );
			console.log( 'ok' );
		}
	} finally {
		await browser.close();
	}

	console.log( `\n${ SHOTS.length } screenshots in ${ path.relative( process.cwd(), OUT_DIR ) || '.' }. Look at every one before committing.` );
}

main().catch( ( error ) => {
	console.error( `\n${ error.message }` );
	process.exit( 1 );
} );
