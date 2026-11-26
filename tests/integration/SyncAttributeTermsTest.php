<?php
/**
 * A synced pet's compatibility terms come from the data that sync brought.
 *
 * The terms are derived from the hydrated pet, and a synced pet hydrates from
 * its provider snapshot. The sync derived them before storing that snapshot.
 * So a new pet got none, and stayed that way, because later syncs skip a pet
 * whose data has not changed. A changed pet got terms from its previous
 * snapshot, one sync behind. On vchs-test the newest pet, Bolta, was missing
 * from the "Good with dogs" filter although Petstablished says she is.
 *
 * Records here are Bolta's, as Petstablished sends them.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync_Sync;
use ReflectionMethod;

final class SyncAttributeTermsTest extends PetTestCase {

	private const RECORD_ID = 2862646;

	/**
	 * @param array<string, mixed> $changes Fields that differ from Bolta's.
	 * @return array<string, mixed> One pet as the Petstablished API sends it.
	 */
	private function record( array $changes = array() ): array {
		return array_merge(
			array(
				'id'                    => self::RECORD_ID,
				'name'                  => 'Bolta',
				'description'           => '<p>Bolta is a sweetheart.</p>',
				'is_ok_with_other_dogs' => 'Yes',
				'is_ok_with_other_cats' => 'Not Sure',
				'is_ok_with_other_kids' => 'Yes',
				'shots_up_to_date'      => 'Yes',
				'is_spayed'             => 'Yes',
				'is_housebroken'        => 'Yes',
				'has_special_need'      => 'No',
				'is_hypoallergenic'     => 'No',
			),
			$changes
		);
	}

	/**
	 * Run one record through the sync, as a sync run does for each pet.
	 *
	 * @param array<string, mixed> $record API record.
	 * @return string created, updated, unchanged or errors.
	 */
	private function sync( array $record ): string {
		return ( new ReflectionMethod( Petsync_Sync::class, 'process_single_pet' ) )->invoke( new Petsync_Sync(), $record );
	}

	private function pet(): int {
		$ids = get_posts(
			array(
				'post_type'   => 'vcps_pet',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => '_pet_ps_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture lookup.
				'meta_value'  => (string) self::RECORD_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture lookup.
			)
		);

		$this->assertCount( 1, $ids );
		return (int) $ids[0];
	}

	/**
	 * @return string[] The pet's compatibility terms, sorted.
	 */
	private function terms( int $id ): array {
		$slugs = wp_get_object_terms( $id, 'vcps_pet_attribute', array( 'fields' => 'slugs' ) );
		sort( $slugs );
		return $slugs;
	}

	public function test_a_newly_synced_pet_gets_its_terms(): void {
		$this->assertSame( 'created', $this->sync( $this->record() ) );

		$this->assertSame(
			array( 'good-with-dogs', 'good-with-kids', 'housebroken', 'shots-current', 'spayed-neutered' ),
			$this->terms( $this->pet() )
		);
	}

	/**
	 * The shelter learns Bolta does not get on with dogs after all, and that
	 * she is fine with cats. The terms follow this sync's data, not the last.
	 */
	public function test_a_changed_pet_gets_terms_from_its_new_data(): void {
		$this->sync( $this->record() );

		$this->assertSame(
			'updated',
			$this->sync(
				$this->record(
					array(
						'is_ok_with_other_dogs' => 'No',
						'is_ok_with_other_cats' => 'Yes',
					)
				)
			)
		);

		$terms = $this->terms( $this->pet() );
		$this->assertContains( 'good-with-cats', $terms );
		$this->assertNotContains( 'good-with-dogs', $terms, 'a pet known not to suit dogs must not be listed as good with them' );
	}
}
