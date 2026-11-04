/**
 * Sync Settings and Sync Log screens (No Build)
 *
 * The batched "Sync Now" runner, and the Show/Hide details toggle on the
 * log. Both were inline <script> blocks printed by Petsync_Admin; they live
 * here so the screens enqueue them like any other asset.
 *
 * Settings, the nonce and every string arrive in the localized
 * `petsyncAdmin` object. Strings are written with textContent, never
 * innerHTML, so nothing a translation contains is parsed as markup.
 *
 * @package
 */

( function () {
	const config = window.petsyncAdmin;

	if ( ! config ) {
		return;
	}

	const i18n = config.i18n;

	/**
	 * Batched sync: start, process every batch, finish.
	 */
	function initSync() {
		const syncButton = document.getElementById( 'sync-button' );
		if ( ! syncButton ) {
			return;
		}

		const progressDiv = document.getElementById( 'sync-progress' );
		const progressBar = document.getElementById( 'sync-progress-bar' );
		const progressText = document.getElementById( 'sync-progress-text' );
		const resultsDiv = document.getElementById( 'sync-results' );
		const statusDiv = document.getElementById( 'sync-status' );

		let totalPets = 0;
		let processed = 0;
		let batchSize = config.batchSize;
		let stats = { created: 0, updated: 0, unchanged: 0 };

		async function post( params ) {
			const res = await fetch( config.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( { nonce: config.nonce, ...params } ),
			} );
			return res.json();
		}

		async function processBatch() {
			const data = await post( {
				action: 'petsync_process_batch',
				offset: processed,
				limit: batchSize,
			} );

			if ( ! data.success ) {
				throw new Error( data.data || 'Batch processing failed' );
			}

			processed += data.data.processed;
			stats.created += data.data.stats.created || 0;
			stats.updated += data.data.stats.updated || 0;
			stats.unchanged += data.data.stats.unchanged || 0;

			const percent = Math.round( ( processed / totalPets ) * 100 );
			progressBar.style.width = percent + '%';
			progressText.textContent = `${ i18n.processing } ${ processed } / ${ totalPets } (${ percent }%)`;
		}

		async function finishSync() {
			progressText.textContent = i18n.finishing;

			const data = await post( { action: 'petsync_finish_sync' } );

			progressBar.style.width = '100%';
			progressDiv.style.display = 'none';

			let summary = `${ i18n.created } ${ stats.created } | ${ i18n.updated } ${ stats.updated } | ${ i18n.unchanged } ${ stats.unchanged }`;
			if ( data.data?.removed ) {
				summary += ` | ${ i18n.removed } ${ data.data.removed }`;
			}

			const heading = document.createElement( 'strong' );
			heading.textContent = i18n.complete;
			resultsDiv.replaceChildren(
				heading,
				document.createElement( 'br' ),
				summary
			);
			resultsDiv.style.display = 'block';

			const label = document.createElement( 'strong' );
			label.textContent = i18n.lastSync;
			const line = document.createElement( 'p' );
			line.append( label, ' ' + i18n.justNow );
			statusDiv.replaceChildren( line );
		}

		async function startSync() {
			syncButton.disabled = true;
			syncButton.textContent = i18n.starting;
			progressDiv.style.display = 'block';
			resultsDiv.style.display = 'none';
			progressBar.style.width = '0%';
			progressText.textContent = i18n.fetching;

			try {
				const startData = await post( {
					action: 'petsync_start_sync',
				} );

				if ( ! startData.success ) {
					throw new Error( startData.data || 'Failed to start sync' );
				}

				totalPets = startData.data.total;
				batchSize = startData.data.batchSize;
				processed = 0;
				stats = { created: 0, updated: 0, unchanged: 0 };

				progressText.textContent = `${ i18n.found } ${ totalPets } ${ i18n.processingPets }`;

				while ( processed < totalPets ) {
					await processBatch();
				}

				await finishSync();
			} catch ( error ) {
				progressText.textContent = i18n.error + ' ' + error.message;
				progressText.style.color = '#d63638';
			}

			syncButton.disabled = false;
			syncButton.textContent = i18n.syncNow;
		}

		syncButton.addEventListener( 'click', startSync );
	}

	/**
	 * Sync Log: each row's details expand in place.
	 */
	function initLogDetails() {
		document.querySelectorAll( '.ps-detail-toggle' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				const row = document.getElementById( btn.dataset.target );
				if ( ! row ) {
					return;
				}
				const isHidden = row.style.display === 'none';
				row.style.display = isHidden ? '' : 'none';
				btn.textContent = isHidden
					? i18n.hideDetails
					: i18n.showDetails;
			} );
		} );
	}

	initSync();
	initLogDetails();
} )();
