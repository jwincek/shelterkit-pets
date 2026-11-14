<?php
/**
 * Files as spreadsheets actually save them.
 *
 * Excel's plain "CSV (Comma delimited)" on Windows writes Windows-1252, not
 * UTF-8. Any row with an accented letter or a curly apostrophe, which Word and
 * Excel insert as you type, then failed with "Could not insert post into the
 * database", and the shelter had no way to know why. In much of Europe Excel
 * separates with semicolons, and such a file was refused as having no name
 * column.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Import\Importer;

final class SpreadsheetFormatsTest extends PetTestCase {

	private function windows_1252( string $utf8 ): string {
		return mb_convert_encoding( $utf8, 'Windows-1252', 'UTF-8' );
	}

	public function test_a_windows_1252_file_imports_with_its_accents_and_apostrophes(): void {
		$file = $this->windows_1252( "name,breed,description\nZoë,Löwchen,He’s a sweet boy — truly.\n" );

		$report = Importer::run( $file, array( 'commit' => true ) );

		$this->assertSame( 0, $report['failed'], implode( ' ', $report['rows'][0]['errors'] ) );
		$pet = $report['rows'][0]['id'];
		$this->assertSame( 'Zoë', get_post( $pet )->post_title );
		$this->assertSame( array( 'Löwchen' ), wp_get_object_terms( $pet, 'vcps_pet_breed', array( 'fields' => 'names' ) ) );
		$this->assertStringContainsString( 'He’s a sweet boy — truly.', get_post( $pet )->post_content );
	}

	/**
	 * The preview says the file was read as Windows-1252, so a file in some
	 * other legacy encoding, whose letters would then look wrong, is noticed.
	 */
	public function test_the_report_says_when_a_file_was_converted(): void {
		$converted = Importer::run( $this->windows_1252( "name\nZoë\n" ) );
		$utf8      = Importer::run( "name\nZoë\n" );

		$this->assertSame( 'Windows-1252', $converted['encoding'] );
		$this->assertSame( 'UTF-8', $utf8['encoding'] );
		$this->assertSame( 'Zoë', $utf8['rows'][0]['name'], 'a UTF-8 file is read as it is' );
	}

	public function test_a_semicolon_separated_file_imports(): void {
		$report = Importer::run( "Name;Breed;Good with dogs?\nMarlo;Beagle, mostly;yes\n", array( 'commit' => true ) );

		$this->assertSame( 1, $report['created'] );
		$this->assertSame( ';', $report['delimiter'] );
		$pet = $report['rows'][0]['id'];
		$this->assertSame( array( 'Beagle, mostly' ), wp_get_object_terms( $pet, 'vcps_pet_breed', array( 'fields' => 'names' ) ) );
		$this->assertSame( 'yes', get_post_meta( $pet, $this->prefix . 'ok_with_dogs', true ) );
	}

	/**
	 * Only the header row decides. A comma file whose cells contain
	 * semicolons stays a comma file.
	 */
	public function test_semicolons_inside_a_comma_files_cells_do_not_change_its_separator(): void {
		$report = Importer::run( "name,description\nPip,\"Shy; then playful; then asleep\"\n" );

		$this->assertSame( ',', $report['delimiter'] );
		$this->assertSame( 0, $report['failed'] );
	}
}
