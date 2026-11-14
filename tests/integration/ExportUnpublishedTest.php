<?php
/**
 * An export that can include every pet, and a status that survives the trip.
 *
 * Export took published pets only, so it was not a backup: drafts, pending
 * and private pets were left out. Including them is only safe if their status
 * comes back too, because an import publishes every new pet. VCHS keeps 220
 * drafts, mostly animals already adopted, and a restore that published them
 * would put them all back up for adoption.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Core\Pet_Hydrator;
use Petsync\Export\Exporter;
use Petsync\Import\Importer;

final class ExportUnpublishedTest extends PetTestCase {

	private function pet( string $name, string $status = 'publish' ): int {
		return $this->make_manual_pet(
			array(
				'post_title'  => $name,
				'post_status' => $status,
			)
		);
	}

	public function test_an_export_takes_published_pets_unless_asked_for_more(): void {
		$listed = $this->pet( 'Listed' );
		$draft  = $this->pet( 'Drafted', 'draft' );

		$this->assertSame( array( $listed ), Exporter::pet_ids() );
		$this->assertEqualsCanonicalizing( array( $listed, $draft ), Exporter::pet_ids( array( 'unpublished' => true ) ) );
	}

	public function test_unpublished_pets_are_exported_with_their_status(): void {
		$draft = $this->pet( 'Drafted', 'draft' );
		update_post_meta( $draft, $this->prefix . 'weight', '9 lb' );

		$row = Exporter::rows( array( $draft ) )[0];

		$this->assertSame( 'Drafted', $row['name'] );
		$this->assertSame( '9 lb', $row['weight'] );
		$this->assertSame( 'draft', $row['post_status'] );
	}

	/**
	 * Hydrating a draft for an export must not leave it where get(), which
	 * serves the public, would find it.
	 */
	public function test_exporting_a_draft_does_not_make_it_public(): void {
		$draft = $this->pet( 'Drafted', 'draft' );

		Exporter::rows( array( $draft ) );

		$this->assertNull( Pet_Hydrator::get( $draft, 'full' ) );
	}

	/**
	 * Restoring into a site without these pets: each comes back as what it
	 * was, not all of them published.
	 */
	public function test_a_restore_keeps_each_pets_status(): void {
		$ids = array(
			$this->pet( 'Up For Adoption' ),
			$this->pet( 'Being Written Up', 'draft' ),
			$this->pet( 'Staff Only', 'private' ),
		);
		$csv = Exporter::to_csv( $ids );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}

		$report = Importer::run( $csv, array( 'commit' => true ) );

		$this->assertSame( 3, $report['created'] );
		$statuses = array();
		foreach ( $report['rows'] as $row ) {
			$statuses[ $row['name'] ] = get_post_status( $row['id'] );
		}
		$this->assertSame(
			array(
				'Up For Adoption'  => 'publish',
				'Being Written Up' => 'draft',
				'Staff Only'       => 'private',
			),
			$statuses
		);
	}

	/**
	 * Unlike other columns, post_status only sets up the pets an import adds.
	 * An export is often edited for days, and a pet unlisted meanwhile, say
	 * because it was adopted, would be put back up by the stale "publish" in
	 * its row. Changing whether a pet is listed stays a deliberate act in the
	 * editor; the preview says when the sheet disagrees.
	 */
	public function test_a_post_status_cell_does_not_change_an_existing_pet(): void {
		$unlisted = $this->pet( 'Adopted Midweek', 'draft' );

		$report = Importer::run( "id,name,post_status\n{$unlisted},Adopted Midweek,publish\n", array( 'commit' => true ) );

		$this->assertSame( 'draft', get_post_status( $unlisted ) );
		$this->assertSame( 'update', $report['rows'][0]['action'] );
		$this->assertStringContainsString( 'draft', implode( ' ', $report['rows'][0]['notes'] ) );
	}

	/**
	 * VCHS has 50 animals with two records under one microchip: an old draft
	 * copy and the listed pet. A backup carries both, each with its own id, and
	 * a restore must bring back both, not refuse one as a duplicate row or
	 * overwrite the first with the second.
	 */
	public function test_a_backup_with_two_records_for_one_microchip_restores_both(): void {
		$old = $this->pet( 'Bramble (2024)', 'draft' );
		$new = $this->pet( 'Bramble' );
		update_post_meta( $old, $this->prefix . 'microchip_id', 'RESTORE-1' );
		update_post_meta( $new, $this->prefix . 'microchip_id', 'RESTORE-1' );
		Pet_Hydrator::flush_cache(); // Saving the pet hydrated it before its microchip was set.
		$csv = Exporter::to_csv( Exporter::pet_ids( array( 'unpublished' => true ) ) );
		wp_delete_post( $old, true );
		wp_delete_post( $new, true );

		$preview = Importer::run( $csv );
		$import  = Importer::run( $csv, array( 'commit' => true ) );

		foreach ( array( $preview, $import ) as $report ) {
			$this->assertSame( 2, $report['created'] );
			$this->assertSame( 0, $report['failed'] );
		}
		$this->assertNotSame( $import['rows'][0]['id'], $import['rows'][1]['id'] );
	}

	/**
	 * Without ids, one microchip on two rows is still one animal listed twice.
	 */
	public function test_the_same_id_twice_is_refused(): void {
		$pet    = $this->pet( 'Twice' );
		$report = Importer::run( "id,name\n{$pet},Twice\n{$pet},Twice Again\n" );

		$this->assertSame( 1, $report['failed'] );
		$this->assertStringContainsString( 'row 2', implode( ' ', $report['rows'][1]['errors'] ) );
	}

	public function test_an_unknown_post_status_is_refused(): void {
		$report = Importer::run( "name,post_status\nRex,trash\nBo,published\n" );

		$this->assertSame( 2, $report['failed'] );
		$this->assertStringContainsString( 'publish, draft, pending, private', implode( ' ', $report['rows'][1]['errors'] ) );
	}

	/**
	 * Nothing imports JSON, so the file no longer says it can be imported.
	 * It says whether its columns are the portable ones a CSV import reads.
	 */
	public function test_json_no_longer_claims_it_can_be_imported(): void {
		$json = json_decode( Exporter::to_json( array( $this->pet( 'Jay' ) ) ), true );

		$this->assertArrayNotHasKey( 'importable', $json );
		$this->assertTrue( $json['portable'] );
	}
}
