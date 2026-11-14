<?php
/**
 * Export, edit in a spreadsheet, import back: nothing may be lost.
 *
 * The Export screen says its "Everything you can edit" file can be imported
 * back, and that trip is a shelter's natural bulk edit: export, change a column
 * in Excel, re-import. Every test here is a way that trip used to lose or
 * corrupt data without a word: a curated gallery wiped, every term but the
 * first dropped, descriptions emptied or broken, drafts published, pets without
 * a microchip duplicated, and a synced pet "updated" until its next sync.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Core\Pet_Hydrator;
use Petsync\Export\Exporter;
use Petsync\Import\Importer;

final class CsvRoundTripTest extends PetTestCase {

	/**
	 * Export these pets and import the file straight back.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<string, mixed> The import report.
	 */
	private function round_trip( array $ids ): array {
		Pet_Hydrator::flush_cache();
		$report = Importer::run( Exporter::to_csv( $ids ), array( 'commit' => true ) );
		Pet_Hydrator::flush_cache();

		return $report;
	}

	private function chipped_pet( string $chip, array $args = array() ): int {
		$id = $this->make_manual_pet( $args );
		update_post_meta( $id, $this->prefix . 'microchip_id', $chip );
		return $id;
	}

	private function image(): int {
		return self::factory()->attachment->create_object(
			'photo-' . wp_generate_password( 6, false ) . '.jpg',
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);
	}

	/**
	 * @return string[] Term names, sorted.
	 */
	private function names( int $id, string $taxonomy ): array {
		$names = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'names' ) );
		sort( $names );
		return $names;
	}

	private function notes( array $row ): string {
		return implode( ' ', array_merge( $row['errors'], $row['notes'] ?? array() ) );
	}

	// ─── Fields ─────────────────────────────────────────────────────────────

	/**
	 * The export writes 11|12. The import stored that string, and the field's
	 * sanitiser turns anything that is not a list into an empty one.
	 */
	public function test_a_curated_gallery_survives(): void {
		$images = array( $this->image(), $this->image() );
		$pet    = $this->chipped_pet( 'RT-GALLERY' );
		update_post_meta( $pet, $this->prefix . 'gallery_ids', $images );

		$this->round_trip( array( $pet ) );

		$this->assertSame( $images, get_post_meta( $pet, $this->prefix . 'gallery_ids', true ) );
	}

	/**
	 * A gallery ID from another site's file names some unrelated attachment
	 * here, or none. Better refused than shown on a pet's page.
	 */
	public function test_a_gallery_id_that_is_not_an_image_here_is_refused(): void {
		$pdf = self::factory()->attachment->create_object( 'notes.pdf', 0, array( 'post_mime_type' => 'application/pdf' ) );

		$report = Importer::run( "name,gallery_ids\nPip,{$pdf}|999999\n" );

		$this->assertSame( 1, $report['failed'] );
		$this->assertStringContainsString( (string) $pdf, $this->notes( $report['rows'][0] ) );
		$this->assertStringContainsString( '999999', $this->notes( $report['rows'][0] ) );
	}

	/**
	 * Every term, joined with the separator the gallery already uses. 45 of
	 * VCHS's 54 listed pets have more than one colour.
	 */
	public function test_every_term_survives_not_just_the_first(): void {
		$pet = $this->chipped_pet( 'RT-TERMS' );
		wp_set_object_terms( $pet, array( 'Labrador Retriever', 'Boxer' ), 'vcps_pet_breed' );
		wp_set_object_terms( $pet, array( 'Black', 'White', 'Tan' ), 'vcps_pet_color' );

		$this->assertSame( 'Boxer|Labrador Retriever', Exporter::rows( array( $pet ) )[0]['breed'] );

		$this->round_trip( array( $pet ) );

		$this->assertSame( array( 'Boxer', 'Labrador Retriever' ), $this->names( $pet, 'vcps_pet_breed' ) );
		$this->assertSame( array( 'Black', 'Tan', 'White' ), $this->names( $pet, 'vcps_pet_color' ) );
	}

	public function test_json_lists_every_term(): void {
		$pet = $this->make_manual_pet();
		wp_set_object_terms( $pet, array( 'Labrador Retriever', 'Boxer' ), 'vcps_pet_breed' );

		$json = json_decode( Exporter::to_json( array( $pet ) ), true );

		$this->assertSame( array( 'Boxer', 'Labrador Retriever' ), $json['pets'][0]['breed'] );
	}

	/**
	 * The export puts an apostrophe in front of a cell Excel would run as a
	 * formula. The import has to take it off again, or it stays forever.
	 */
	public function test_values_the_export_protected_from_excel_come_back_as_they_were(): void {
		$pet = $this->chipped_pet( 'RT-FORMULA', array( 'post_title' => '+Plus' ) );
		update_post_meta( $pet, $this->prefix . 'bonded_names', '-' );
		update_post_meta( $pet, $this->prefix . 'special_needs_detail', '=takes meds daily' );

		$this->assertStringContainsString( "'+Plus", Exporter::to_csv( array( $pet ) ), 'the export still protects the cell' );

		$this->round_trip( array( $pet ) );

		$this->assertSame( '+Plus', get_post( $pet )->post_title );
		$this->assertSame( '-', get_post_meta( $pet, $this->prefix . 'bonded_names', true ) );
		$this->assertSame( '=takes meds daily', get_post_meta( $pet, $this->prefix . 'special_needs_detail', true ) );
	}

	/**
	 * The importer required numerical_age to be a number, but the field is
	 * shown as written, and Petstablished writes "6 years, 1 months". Every
	 * pet in a real export of a synced shelter failed on it: 54 of 54 at VCHS.
	 */
	public function test_an_age_in_words_imports(): void {
		$report = Importer::run( "name,numerical_age\nSalsa,\"11 Months, 1 Week and 4 Days\"\n", array( 'commit' => true ) );

		$this->assertSame( 0, $report['failed'], $this->notes( $report['rows'][0] ) );
		$this->assertSame( '11 Months, 1 Week and 4 Days', get_post_meta( $report['rows'][0]['id'], $this->prefix . 'numerical_age', true ) );
	}

	public function test_an_apostrophe_a_person_typed_is_kept(): void {
		$report = Importer::run( "name\n'Twas\n", array( 'commit' => true ) );

		// The raw title: get_the_title() would curl the apostrophe for display.
		$this->assertSame( "'Twas", get_post( $report['rows'][0]['id'] )->post_title );
	}

	/**
	 * wp_insert_post() and update_post_meta() both unslash what they are given,
	 * so an unslashed backslash vanished, along with the escapes that block
	 * attributes use in their JSON.
	 */
	public function test_backslashes_survive(): void {
		$content = "<!-- wp:list {\"className\":\"likes\\u002d\\u002dlist\"} -->\n<ul class=\"wp-block-list likes--list\"><!-- wp:list-item -->\n<li>Walks</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->";
		$pet     = $this->chipped_pet( 'RT-SLASH', array( 'post_content' => wp_slash( $content ) ) );
		update_post_meta( $pet, $this->prefix . 'special_needs_detail', wp_slash( 'Meds at 8\\9' ) );

		$this->round_trip( array( $pet ) );

		$this->assertSame( $content, get_post( $pet )->post_content );
		$this->assertSame( 'Meds at 8\\9', get_post_meta( $pet, $this->prefix . 'special_needs_detail', true ) );
	}

	// ─── What an update leaves alone ────────────────────────────────────────

	public function test_a_sheet_without_a_description_column_keeps_descriptions(): void {
		$pet = $this->chipped_pet(
			'RT-PARTIAL',
			array(
				'post_title'   => 'Rex',
				'post_content' => 'Loves walks.',
			)
		);

		Importer::run( "name,microchip_id,ok_with_dogs\nRex,RT-PARTIAL,yes\n", array( 'commit' => true ) );

		$this->assertSame( 'Loves walks.', get_post( $pet )->post_content );
		$this->assertSame( 'yes', get_post_meta( $pet, $this->prefix . 'ok_with_dogs', true ) );
	}

	public function test_updating_a_draft_leaves_it_a_draft(): void {
		$pet = $this->chipped_pet(
			'RT-DRAFT',
			array(
				'post_title'  => 'Drafty',
				'post_status' => 'draft',
			)
		);

		Importer::run( "name,microchip_id\nDrafty,RT-DRAFT\n", array( 'commit' => true ) );

		$this->assertSame( 'draft', get_post_status( $pet ) );
	}

	public function test_a_new_pet_is_still_published(): void {
		$report = Importer::run( "name\nNewcomer\n", array( 'commit' => true ) );

		$this->assertSame( 'publish', get_post_status( $report['rows'][0]['id'] ) );
	}

	// ─── Matching ───────────────────────────────────────────────────────────

	/**
	 * The preview said "2 to add"; the import added one and overwrote it with
	 * the second row. Now both say the same thing, because both run this check.
	 */
	public function test_the_same_microchip_twice_in_one_file_is_an_error_in_preview_and_import_alike(): void {
		$file    = "name,microchip_id\nTwinA,RT-TWIN\nTwinB,RT-TWIN\n";
		$preview = Importer::run( $file );
		$import  = Importer::run( $file, array( 'commit' => true ) );

		foreach ( array( $preview, $import ) as $report ) {
			$this->assertSame( 1, $report['created'] );
			$this->assertSame( 1, $report['failed'] );
			$this->assertStringContainsString( 'row 2', $this->notes( $report['rows'][1] ) );
		}
		$this->assertSame( 'TwinA', get_the_title( $import['rows'][0]['id'] ) );
	}

	public function test_pets_without_a_microchip_are_matched_by_their_exported_id(): void {
		$a = $this->make_manual_pet( array( 'post_title' => 'Chipless A' ) );
		$b = $this->make_manual_pet( array( 'post_title' => 'Chipless B' ) );

		$report = $this->round_trip( array( $a, $b ) );

		$this->assertSame( 2, $report['updated'] );
		$this->assertSame( 0, $report['created'] );
	}

	/**
	 * Renaming is a fair edit, so it still updates. But a file from another
	 * site would match unrelated pets by ID, and the preview is where someone
	 * can see that happening.
	 */
	public function test_an_id_match_under_a_different_name_says_so(): void {
		$pet = $this->make_manual_pet( array( 'post_title' => 'Luna' ) );

		$report = Importer::run( "id,name\n{$pet},Lunar\n" );

		$this->assertSame( 'update', $report['rows'][0]['action'] );
		$this->assertStringContainsString( 'Luna', $this->notes( $report['rows'][0] ) );
	}

	public function test_an_id_that_is_not_a_pet_here_does_not_match(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);

		$report = Importer::run( "id,name\n{$page},Visitor\n", array( 'commit' => true ) );

		$this->assertSame( 1, $report['created'] );
		$this->assertSame( 'About', get_the_title( $page ) );
	}

	/**
	 * The next sync rewrites a synced pet from its platform, so an import that
	 * updated one would be undone without a trace.
	 */
	public function test_a_row_matching_a_synced_pet_is_skipped_with_the_reason(): void {
		$pet = $this->make_synced_pet( array( 'name' => 'Synced Sam' ) );
		update_post_meta( $pet, $this->prefix . 'microchip_id', 'RT-SYNCED' );

		$report = Importer::run( "name,microchip_id,weight\nRenamed,RT-SYNCED,9 lb\n", array( 'commit' => true ) );

		$this->assertSame( 1, $report['skipped'] );
		$this->assertStringContainsString( 'Petstablished', $this->notes( $report['rows'][0] ) );
		$this->assertSame( 'Synced Sam', get_the_title( $pet ) );
	}

	/**
	 * A synced pet's microchip is in its provider snapshot, not its meta, so a
	 * shelter's own sheet never matched it and added a hand-entered copy.
	 */
	public function test_a_synced_pets_microchip_is_found_in_its_snapshot(): void {
		$pet = $this->make_synced_pet(
			array(
				'name'         => 'Snap',
				'microchip_id' => 'RT-SNAPSHOT',
			)
		);

		$report = Importer::run( "name,microchip_id\nSnap,RT-SNAPSHOT\n", array( 'commit' => true ) );

		$this->assertSame( 0, $report['created'], 'a copy of a synced pet must not be added' );
		$this->assertSame( 1, $report['skipped'] );
		$this->assertSame( $pet, $report['rows'][0]['id'] );
	}

	/**
	 * VCHS has 50 animals with an old draft copy carrying the same microchip.
	 * Matching on the microchip alone picked the stale copy over the pet the
	 * row was exported from. The exported id names the right one.
	 */
	public function test_the_exported_id_wins_over_an_old_copy_with_the_same_microchip(): void {
		$old     = $this->chipped_pet(
			'RT-RELISTED',
			array(
				'post_title'  => 'Bramble',
				'post_status' => 'draft',
			)
		);
		$current = $this->chipped_pet( 'RT-RELISTED', array( 'post_title' => 'Bramble' ) );
		update_post_meta( $current, $this->prefix . 'weight', '12 lb' );

		$report = $this->round_trip( array( $current ) );

		$this->assertSame( 'update', $report['rows'][0]['action'] );
		$this->assertSame( $current, $report['rows'][0]['id'] );
		$this->assertSame( '', get_post_meta( $old, $this->prefix . 'weight', true ), 'the old copy is untouched' );
	}

	public function test_without_an_id_the_listed_pet_with_the_microchip_is_matched_and_the_others_named(): void {
		$draft  = $this->chipped_pet(
			'RT-SHARED',
			array(
				'post_title'  => 'Old Copy',
				'post_status' => 'draft',
			)
		);
		$listed = $this->chipped_pet( 'RT-SHARED', array( 'post_title' => 'Listed' ) );

		$report = Importer::run( "name,microchip_id,weight\nListed,RT-SHARED,10 lb\n", array( 'commit' => true ) );

		$this->assertSame( $listed, $report['rows'][0]['id'] );
		$this->assertStringContainsString( "#{$draft}", $this->notes( $report['rows'][0] ) );
		$this->assertSame( '10 lb', get_post_meta( $listed, $this->prefix . 'weight', true ) );
	}

	// ─── Descriptions ───────────────────────────────────────────────────────

	/**
	 * What the block editor saves comes out as readable paragraphs and goes
	 * back in as exactly what the editor saved.
	 */
	public function test_editor_paragraphs_export_as_text_and_return_identical(): void {
		$content = "<!-- wp:paragraph -->\n<p>He's sweet &amp; shy.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Second<br>line.</p>\n<!-- /wp:paragraph -->";
		$pet     = $this->chipped_pet( 'RT-BLOCKS', array( 'post_content' => $content ) );

		$this->assertSame( "He's sweet & shy.\n\nSecond\nline.", Exporter::rows( array( $pet ) )[0]['description'] );

		$this->round_trip( array( $pet ) );

		$this->assertSame( $content, get_post( $pet )->post_content );
	}

	/**
	 * Petstablished sends HTML with its apostrophes encoded. In a page that
	 * shows as an apostrophe; in a spreadsheet it showed as &#039;.
	 */
	public function test_provider_html_exports_without_entities(): void {
		$pet = $this->make_manual_pet( array( 'post_content' => "<p>Meet Ryder, who&#039;s ready.</p>\n<p>Tom &amp; Jerry.</p>" ) );

		$this->assertSame( "Meet Ryder, who's ready.\n\nTom & Jerry.", Exporter::rows( array( $pet ) )[0]['description'] );
	}

	/**
	 * A file exported before this change carries &#039; inside <p> tags. It
	 * imports as clean paragraphs, apostrophes and all.
	 */
	public function test_an_older_export_imports_with_real_apostrophes(): void {
		$report = Importer::run( "name,description\nRyder,\"<p>Meet Ryder, who&#039;s ready.</p>\n\"\n", array( 'commit' => true ) );

		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Meet Ryder, who's ready.</p>\n<!-- /wp:paragraph -->",
			get_post( $report['rows'][0]['id'] )->post_content
		);
	}

	/**
	 * Text is text: a < that starts no tag survives, rather than being taken
	 * for a broken tag and stripped with everything after it.
	 */
	public function test_a_plain_text_cell_becomes_paragraph_blocks(): void {
		$report = Importer::run( "name,description\nBo,\"Bo's here.\n\nI <3 walks & treats.\"\n", array( 'commit' => true ) );

		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Bo's here.</p>\n<!-- /wp:paragraph -->\n\n"
				. "<!-- wp:paragraph -->\n<p>I &lt;3 walks &amp; treats.</p>\n<!-- /wp:paragraph -->",
			get_post( $report['rows'][0]['id'] )->post_content
		);
	}

	/**
	 * A list or a styled paragraph has no plain-text form. Such a description
	 * travels as its markup, untouched, rather than losing its structure.
	 */
	public function test_a_description_text_cannot_hold_travels_as_markup(): void {
		$content = "<!-- wp:paragraph -->\n<p>Likes:</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Walks</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->";
		$pet     = $this->chipped_pet( 'RT-LIST', array( 'post_content' => $content ) );

		$this->assertSame( $content, Exporter::rows( array( $pet ) )[0]['description'] );

		$this->round_trip( array( $pet ) );

		$this->assertSame( $content, get_post( $pet )->post_content );
	}

	/**
	 * Unrelated to the file, found on the way: wpautop() wrapped every block
	 * delimiter in a paragraph, so a description written in the block editor
	 * displayed with an empty paragraph around each real one.
	 */
	public function test_an_editor_written_description_displays_without_empty_paragraphs(): void {
		$pet = $this->make_manual_pet( array( 'post_content' => "<!-- wp:paragraph -->\n<p>Hello.</p>\n<!-- /wp:paragraph -->" ) );

		$description = Pet_Hydrator::get( $pet, 'full' )['description'];

		$this->assertStringContainsString( '<p>Hello.</p>', $description );
		$this->assertStringNotContainsString( '<!--', $description );
		$this->assertSame( 1, substr_count( $description, '<p>' ) );
	}
}
