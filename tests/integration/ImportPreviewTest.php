<?php
/**
 * The import preview as the confirmation step it claims to be.
 *
 * The screen said nothing was written until the preview was confirmed, and its
 * docblock said the preview is where a shelter notices "Good with dogs?"
 * landed on the wrong field. Neither was true. "Import now" sat beside
 * "Preview" and wrote on the first click. Confirming meant choosing the file
 * again, so the file imported need not be the one previewed. The preview never
 * showed which column filled which field, and nothing could change it. It
 * listed only the first 25 rows, so an error on row 40 went unseen. And a
 * misspelt status quietly became a new status that hid its pets from every
 * adoptable listing.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Import\Admin;
use Petsync\Import\Importer;
use Petsync\Import\Pending_Upload;

final class ImportPreviewTest extends PetTestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function pets_named( string $name ): array {
		return get_posts(
			array(
				'post_type'   => 'vcps_pet',
				'post_status' => 'any',
				'title'       => $name,
				'fields'      => 'ids',
			)
		);
	}

	/**
	 * Submit one of the screen's forms and return the page it renders.
	 *
	 * @param array<string, mixed> $post Form fields.
	 */
	private function submit( array $post ): string {
		$_POST                = $post;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'shelterkit_import' );

		ob_start();
		( new Admin() )->render_page();
		return (string) ob_get_clean();
	}

	// ─── The importer ───────────────────────────────────────────────────────

	public function test_an_unknown_status_is_refused_with_the_statuses_the_site_has(): void {
		$report = Importer::run( "name,status\nNewbie,Adoptable\nPip,pending\nBo,on hold\n" );

		$this->assertSame( 'error', $report['rows'][0]['action'] );
		$errors = implode( ' ', $report['rows'][0]['errors'] );
		$this->assertStringContainsString( 'Adoptable', $errors );
		$this->assertStringContainsString( 'Available', $errors, 'the message lists what the site does have' );
		$this->assertSame( 'create', $report['rows'][1]['action'], 'a status may be given by its slug' );
		$this->assertSame( 'create', $report['rows'][2]['action'], 'or by its name, in any case' );
		$this->assertEmpty( term_exists( 'Adoptable', 'vcps_pet_status' ), 'no status was created' );
	}

	/**
	 * A typo in a breed becomes a new breed. Allowed, since a shelter's breeds
	 * are not our list, but shown, and the same in the preview as the import.
	 */
	public function test_new_terms_are_listed_by_preview_and_import_alike(): void {
		$file = "name,breed\nA,Boxxer\nB,Boxxer|Whippett\n";

		foreach ( array( Importer::run( $file ), Importer::run( $file, array( 'commit' => true ) ) ) as $report ) {
			$this->assertSame( array( 'breed' => array( 'Boxxer', 'Whippett' ) ), $report['new_terms'] );
		}
	}

	public function test_a_person_can_choose_which_field_a_column_fills(): void {
		$report = Importer::run(
			"Name,Notes\nRex,Needs a quiet home\n",
			array(
				'commit'  => true,
				'mapping' => array(
					0 => 'name',
					1 => 'special_needs_detail',
				),
			)
		);

		$pet = $report['rows'][0]['id'];
		$this->assertSame( 'Needs a quiet home', get_post_meta( $pet, $this->prefix . 'special_needs_detail', true ) );
		$this->assertSame( '', get_post( $pet )->post_content, '"Notes" would otherwise have been the description' );
	}

	public function test_a_column_can_be_ignored(): void {
		$report = Importer::run(
			"Name,Notes\nRex,Private remark\n",
			array(
				'commit'  => true,
				'mapping' => array(
					0 => 'name',
					1 => '',
				),
			)
		);

		$this->assertSame( '', get_post( $report['rows'][0]['id'] )->post_content );
		$this->assertSame( array( 1 => 'Notes' ), $report['unmapped'] );
	}

	public function test_two_columns_chosen_for_one_field_keep_the_first(): void {
		$report = Importer::run(
			"Name,Nickname\nRex,Rexy\n",
			array(
				'commit'  => true,
				'mapping' => array(
					0 => 'name',
					1 => 'name',
				),
			)
		);

		$this->assertSame( 'Rex', get_post( $report['rows'][0]['id'] )->post_title );
		$this->assertSame( array( 1 => 'Nickname' ), $report['duplicates'] );
	}

	// ─── The kept file ──────────────────────────────────────────────────────

	public function test_a_kept_file_is_returned_only_to_its_user_until_forgotten(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token = Pending_Upload::store( $this->admin, "name\nRex\n" );

		$this->assertSame( "name\nRex\n", Pending_Upload::get( $this->admin, $token ) );
		$this->assertNull( Pending_Upload::get( $other, $token ), 'another user cannot use the token' );
		$this->assertNull( Pending_Upload::get( $this->admin, '../../etc' ) );

		Pending_Upload::forget( $this->admin, $token );
		$this->assertNull( Pending_Upload::get( $this->admin, $token ) );
	}

	// ─── The screen ─────────────────────────────────────────────────────────

	public function test_the_first_screen_can_only_preview(): void {
		$_POST = array();
		ob_start();
		( new Admin() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'petsync_import_preview', $html );
		$this->assertStringNotContainsString( 'petsync_import_commit', $html );
	}

	/**
	 * The preview's form posts the token and the column choices; the import
	 * is of the kept file, with those choices, and the file is then let go.
	 */
	public function test_confirming_imports_the_previewed_file_with_the_chosen_columns(): void {
		$token = Pending_Upload::store( $this->admin, "Name,Notes\nConfirmed Rex,Needs a quiet home\n" );

		$html = $this->submit(
			array(
				'petsync_import_commit' => '1',
				'petsync_import_token'  => $token,
				'petsync_on_match'      => 'update',
				'petsync_mapping'       => array(
					0 => 'name',
					1 => 'special_needs_detail',
				),
			)
		);

		$this->assertStringContainsString( 'Import complete.', $html );
		$pets = $this->pets_named( 'Confirmed Rex' );
		$this->assertCount( 1, $pets );
		$this->assertSame( 'Needs a quiet home', get_post_meta( $pets[0], $this->prefix . 'special_needs_detail', true ) );
		$this->assertNull( Pending_Upload::get( $this->admin, $token ), 'the kept file is deleted after the import' );
	}

	public function test_a_token_that_is_not_this_users_imports_nothing(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token = Pending_Upload::store( $other, "name\nSomeone Else's Rex\n" );

		$html = $this->submit(
			array(
				'petsync_import_commit' => '1',
				'petsync_import_token'  => $token,
			)
		);

		$this->assertStringContainsString( 'This preview has expired', $html );
		$this->assertSame( array(), $this->pets_named( "Someone Else's Rex" ) );
	}

	/**
	 * Every row with something to say is listed, however far down it is.
	 */
	public function test_an_error_far_down_the_sheet_is_shown(): void {
		$rows = array( 'name,ok_with_dogs' );
		for ( $i = 1; $i <= 40; $i++ ) {
			$rows[] = "Pet {$i}," . ( 40 === $i ? 'perhaps' : 'yes' );
		}
		$token = Pending_Upload::store( $this->admin, implode( "\n", $rows ) . "\n" );

		$html = $this->submit(
			array(
				'petsync_import_refresh' => '1',
				'petsync_import_token'   => $token,
				'petsync_mapping'        => array(
					0 => 'name',
					1 => 'ok_with_dogs',
				),
			)
		);

		$this->assertStringContainsString( '&quot;perhaps&quot; is not a yes/no value', $html );
		$this->assertStringContainsString( '14 further rows with nothing to report', $html );
		$this->assertSame( array(), $this->pets_named( 'Pet 1' ), 'a preview writes nothing' );
	}

	/**
	 * No column matched the name, so there is no report. The column table is
	 * still shown, because choosing the name column is the way out.
	 */
	public function test_a_file_with_no_name_column_can_be_fixed_from_the_preview(): void {
		$token = Pending_Upload::store( $this->admin, "Pet,Dogs\nRex,yes\n" );

		$first = $this->submit(
			array(
				'petsync_import_refresh' => '1',
				'petsync_import_token'   => $token,
				'petsync_mapping'        => array(),
			)
		);
		$this->assertStringContainsString( 'name, which is required', $first );
		$this->assertStringContainsString( 'name="petsync_mapping[0]"', $first );
		$this->assertMatchesRegularExpression( '/name="petsync_import_commit"[^>]*disabled/', $first );

		$second = $this->submit(
			array(
				'petsync_import_refresh' => '1',
				'petsync_import_token'   => $token,
				'petsync_mapping'        => array(
					0 => 'name',
					1 => 'ok_with_dogs',
				),
			)
		);
		$this->assertStringContainsString( '1 to add', $second );
		$this->assertStringContainsString( 'Import 1 pet', $second );
	}
}
