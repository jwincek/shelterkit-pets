<?php
/**
 * Shared base for pet integration tests.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Core\CPT_Registry;
use Petsync\Core\Pet_Hydrator;
use WP_UnitTestCase;

abstract class PetTestCase extends WP_UnitTestCase {

	protected string $prefix = '_pet_';

	public function set_up(): void {
		parent::set_up();

		// Insurance, not a reset the library forgets. WP_UnitTestCase resets
		// post types and taxonomies only when WP_RUN_CORE_TESTS is set, which a
		// plugin suite never does, so what the plugin registered at `init`
		// normally survives every test. But a test that unregisters the post
		// type takes its registered meta with it (unregister_post_type() calls
		// unregister_meta_key()), and `init` does not fire again. Rebuilding
		// here means one such test cannot leave the rest without sanitize
		// callbacks and REST schemas, which fails confusingly: reads and writes
		// still work, because get/update_post_meta do not require registration.
		CPT_Registry::register_post_types();
		CPT_Registry::register_taxonomies();
		CPT_Registry::register_meta();

		// Hydration is memoised per request. A test writes and reads back
		// within one request, so every test starts from a clean slate.
		Pet_Hydrator::flush_cache();
	}

	/**
	 * A pet with no provider — as if created by hand in the editor.
	 *
	 * @param array<string, mixed> $args Overrides for wp_insert_post.
	 * @return int Post ID.
	 */
	protected function make_manual_pet( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'vcps_pet',
					'post_status' => 'publish',
					'post_title'  => 'Manual Pet',
				),
				$args
			)
		);
	}

	/**
	 * A pet as the sync would leave it: provider, record ID, and a snapshot.
	 *
	 * @param array<string, mixed> $api   Snapshot payload.
	 * @param array<string, mixed> $args  Overrides for wp_insert_post.
	 * @return int Post ID.
	 */
	protected function make_synced_pet( array $api = array(), array $args = array() ): int {
		$api = array_merge(
			array(
				'id'   => 900001,
				'name' => 'Synced Pet',
			),
			$api
		);

		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'vcps_pet',
					'post_status' => 'publish',
					'post_title'  => $api['name'],
				),
				$args
			)
		);

		update_post_meta( $id, $this->prefix . 'ps_id', (string) $api['id'] );
		update_post_meta( $id, $this->prefix . 'provider', \Petsync_Sync::PROVIDER );
		update_post_meta( $id, $this->prefix . 'api_response', wp_json_encode( $api ) );

		Pet_Hydrator::flush_cache();

		return $id;
	}
}
