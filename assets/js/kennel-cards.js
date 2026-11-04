/**
 * Kennel Cards picker (No Build)
 *
 * "Select all / none": ticks every pet unless every pet is already ticked.
 *
 * @package
 */

( function () {
	document
		.querySelector( '[data-petsync-toggle-all]' )
		?.addEventListener( 'click', () => {
			const boxes = document.querySelectorAll(
				'.petsync-kennel__picker input[type=checkbox]'
			);
			const target = ! Array.from( boxes ).every( ( b ) => b.checked );
			boxes.forEach( ( b ) => {
				b.checked = target;
			} );
		} );
} )();
