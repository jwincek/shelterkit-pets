<?php
/**
 * What a sync does with a pet whose data has not changed.
 *
 * The sync skips a pet whose hashed data matches what it stored last time.
 * The skip returned before anything else, and two things depended on it not
 * doing so:
 *
 * - Whether the pet is published. A pet drafted for leaving the feed, then
 *   back with the same data, stayed a draft for good.
 * - Its last-seen stamp, which says the provider still lists it. Not
 *   refreshing it made a listed pet look stale after a week, so the staleness
 *   notice counted it and the opt-in auto-draft would take it off the site.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync_Sync;
use ReflectionMethod;

final class SyncPublishStateTest extends PetTestCase {

	/**
	 * @param int                  $id      Provider record ID.
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed> One pet as the Petstablished API sends it.
	 */
	private function record( int $id, array $changes = array() ): array {
		return array_merge(
			array(
				'id'          => $id,
				'name'        => "Pet {$id}",
				'status'      => 'Available',
				'description' => '<p>Hello.</p>',
			),
			$changes
		);
	}

	/**
	 * Call one of the sync's steps, as a sync run does.
	 *
	 * @param string $step Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function sync_step( string $step, ...$args ) {
		return ( new ReflectionMethod( Petsync_Sync::class, $step ) )->invoke( new Petsync_Sync(), ...$args );
	}

	private function pet( int $record_id ): int {
		$ids = get_posts(
			array(
				'post_type'   => 'vcps_pet',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => '_pet_ps_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture lookup.
				'meta_value'  => (string) $record_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture lookup.
			)
		);

		$this->assertCount( 1, $ids );
		return (int) $ids[0];
	}

	public function test_a_pet_back_in_the_feed_unchanged_is_published_again(): void {
		$this->sync_step( 'process_single_pet', $this->record( 1 ) );
		$pet = $this->pet( 1 );

		// A complete sync without it: the pet has left the feed.
		$this->sync_step( 'remove_stale_pets', array( $this->record( 2 ) ) );
		$this->assertSame( 'draft', get_post_status( $pet ) );

		// Back, exactly as it was.
		$this->sync_step( 'process_single_pet', $this->record( 1 ) );

		$this->assertSame( 'publish', get_post_status( $pet ) );
	}

	/**
	 * The skip is still taken when nothing needs doing, which is most pets on
	 * most syncs.
	 */
	public function test_an_unchanged_listed_pet_is_still_skipped(): void {
		$this->sync_step( 'process_single_pet', $this->record( 3 ) );

		$this->assertSame( 'unchanged', $this->sync_step( 'process_single_pet', $this->record( 3 ) ) );
	}

	public function test_an_unchanged_pet_is_still_marked_seen(): void {
		$this->sync_step( 'process_single_pet', $this->record( 4 ) );
		$pet = $this->pet( 4 );
		update_post_meta( $pet, '_pet_last_seen', (string) ( time() - 30 * DAY_IN_SECONDS ) );

		$this->sync_step( 'process_single_pet', $this->record( 4 ) );

		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, (int) get_post_meta( $pet, '_pet_last_seen', true ) );
		$this->assertNotContains( $pet, Petsync_Sync::get_stale_pets( 7 ), 'a pet the provider still lists is not stale' );
	}
}
