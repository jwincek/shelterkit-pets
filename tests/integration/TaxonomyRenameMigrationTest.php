<?php
/**
 * Migration 9: pet_* taxonomies become vcps_pet_*.
 *
 * Seeded the way an upgrading site actually looks on the request that runs
 * the migration. Its pets and terms sit under the old names, while
 * registration, which runs earlier on that request, has already created the
 * default status and attribute terms under the new ones. So the migration has
 * to merge, not just rename, and these tests are mostly about the merge.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

final class TaxonomyRenameMigrationTest extends PetTestCase {

	public function set_up(): void {
		parent::set_up();

		// The old names, registered only so the test can seed data under them,
		// as a site from before migration 9 has it.
		foreach ( array_keys( petsync_taxonomy_renames() ) as $old ) {
			register_taxonomy( $old, 'vcps_pet' );
		}
	}

	public function tear_down(): void {
		foreach ( array_keys( petsync_taxonomy_renames() ) as $old ) {
			if ( taxonomy_exists( $old ) ) {
				unregister_taxonomy( $old );
			}
		}
		parent::tear_down();
	}

	/**
	 * A pet as it was before the upgrade: terms under the old names only.
	 *
	 * @param array<string, string> $terms Old taxonomy => term slug.
	 */
	private function make_old_pet( array $terms ): int {
		$id = $this->make_manual_pet();
		// Saving a pet now assigns the new default status; an old pet never had it.
		wp_delete_object_term_relationships( $id, 'vcps_pet_status' );

		foreach ( $terms as $taxonomy => $slug ) {
			wp_set_object_terms( $id, $slug, $taxonomy );
		}

		return $id;
	}

	private function slugs( int $id, string $taxonomy ): array {
		clean_object_term_cache( $id, 'vcps_pet' );
		$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'slugs' ) );
		sort( $terms );
		return $terms;
	}

	private function rows( string $taxonomy ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the raw rows the migration's SQL wrote.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) );
	}

	public function test_pets_keep_their_terms_under_the_new_names(): void {
		$beagle  = $this->make_old_pet(
			array(
				'pet_breed'     => 'beagle',
				'pet_status'    => 'available',
				'pet_attribute' => 'good-with-dogs',
			)
		);
		$adopted = $this->make_old_pet( array( 'pet_status' => 'adopted' ) );

		$this->assertTrue( petsync_migrate_9_taxonomy_prefix() );

		foreach ( array_keys( petsync_taxonomy_renames() ) as $old ) {
			$this->assertSame( 0, $this->rows( $old ), "nothing may stay filed under $old" );
		}

		$this->assertSame( array( 'beagle' ), $this->slugs( $beagle, 'vcps_pet_breed' ) );
		$this->assertSame( array( 'available' ), $this->slugs( $beagle, 'vcps_pet_status' ) );
		$this->assertSame( array( 'good-with-dogs' ), $this->slugs( $beagle, 'vcps_pet_attribute' ) );
		$this->assertSame( array( 'adopted' ), $this->slugs( $adopted, 'vcps_pet_status' ) );
	}

	/**
	 * Registration created "available", "adopted" and the attribute terms under
	 * the new names before the migration ran. Each must end up as one term.
	 */
	public function test_terms_registration_created_first_are_merged_not_duplicated(): void {
		global $wpdb;

		$this->make_old_pet( array( 'pet_status' => 'adopted' ) );

		// A pet saved after the code updated but before the rail ran: it is on
		// the NEW "adopted" term, which the merge deletes. It must not lose it.
		$saved_between = $this->make_manual_pet();
		wp_set_object_terms( $saved_between, 'adopted', 'vcps_pet_status' );

		petsync_migrate_9_taxonomy_prefix();

		foreach ( petsync_taxonomy_renames() as $new ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the raw rows the migration's SQL wrote.
			$duplicates = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT t.slug FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s GROUP BY t.slug HAVING COUNT(*) > 1",
					$new
				)
			);
			$this->assertSame( array(), $duplicates, "$new has duplicate terms after the merge" );
		}

		$this->assertSame( array( 'adopted' ), $this->slugs( $saved_between, 'vcps_pet_status' ), 'a relationship on the merged-away term must move, not vanish' );

		$adopted = get_term_by( 'slug', 'adopted', 'vcps_pet_status' );
		$this->assertSame( 2, (int) $adopted->count, 'term counts are recomputed after the merge' );
	}

	public function test_the_default_status_points_at_the_surviving_term(): void {
		$this->make_old_pet( array( 'pet_status' => 'available' ) );
		$old_available = get_term_by( 'slug', 'available', 'pet_status' );
		update_option( 'default_term_pet_status', $old_available->term_id );

		petsync_migrate_9_taxonomy_prefix();

		$this->assertSame( (int) $old_available->term_id, (int) get_option( 'default_term_vcps_pet_status' ) );
		$this->assertFalse( get_option( 'default_term_pet_status' ) );
	}

	public function test_core_options_keyed_by_the_old_names_are_cleared(): void {
		// pet_breed was hierarchical once, and core cached its hierarchy here.
		update_option( 'pet_breed_children', array() );

		petsync_migrate_9_taxonomy_prefix();

		$this->assertFalse( get_option( 'pet_breed_children' ) );
	}

	public function test_menu_items_follow_the_rename(): void {
		$item = self::factory()->post->create( array( 'post_type' => 'nav_menu_item' ) );
		update_post_meta( $item, '_menu_item_object', 'pet_breed' );
		update_post_meta( $item, '_menu_item_type', 'taxonomy' );

		petsync_migrate_9_taxonomy_prefix();

		$this->assertSame( 'vcps_pet_breed', get_post_meta( $item, '_menu_item_object', true ) );
	}

	/**
	 * Core blocks name taxonomies in their attributes. Only the attribute JSON
	 * inside block delimiters is rewritten; prose is not.
	 */
	public function test_saved_block_markup_follows_the_rename_and_prose_does_not(): void {
		$markup = '<!-- wp:post-terms {"term":"pet_breed"} /-->'
			. '<!-- wp:query {"query":{"taxQuery":{"pet_animal":[5]}}} --><!-- /wp:query -->'
			. '<!-- wp:paragraph --><p>Our "pet_breed" pages moved.</p><!-- /wp:paragraph -->';
		$part   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_content' => $markup,
			)
		);

		petsync_migrate_9_taxonomy_prefix();

		$content = get_post( $part )->post_content;
		$this->assertStringContainsString( '{"term":"vcps_pet_breed"}', $content );
		$this->assertStringContainsString( '"taxQuery":{"vcps_pet_animal":[5]}', $content );
		$this->assertStringContainsString( 'Our "pet_breed" pages moved.', $content, 'prose is not block attributes' );
	}

	/**
	 * Where another plugin registers one of the old names, the name is that
	 * plugin's: its terms and the block markup naming it stay as they are. The
	 * other names are still renamed.
	 */
	public function test_an_old_name_another_plugin_registers_is_left_alone(): void {
		$dog = $this->make_old_pet( array( 'pet_animal' => 'dog' ) );

		unregister_taxonomy( 'pet_breed' );
		register_taxonomy( 'pet_breed', 'post' );
		wp_set_object_terms( self::factory()->post->create(), 'their-beagle', 'pet_breed' );
		$part = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_content' => '<!-- wp:post-terms {"term":"pet_breed"} /--><!-- wp:post-terms {"term":"pet_animal"} /-->',
			)
		);

		$this->assertTrue( petsync_migrate_9_taxonomy_prefix() );

		$this->assertSame( 1, $this->rows( 'pet_breed' ), "the other plugin's term keeps its taxonomy" );
		$this->assertNotNull( term_exists( 'their-beagle', 'pet_breed' ) );
		$this->assertSame( array( 'dog' ), $this->slugs( $dog, 'vcps_pet_animal' ), 'the names that are ours still move' );
		$this->assertSame(
			'<!-- wp:post-terms {"term":"pet_breed"} /--><!-- wp:post-terms {"term":"vcps_pet_animal"} /-->',
			get_post( $part )->post_content
		);
	}

	public function test_running_it_again_changes_nothing(): void {
		global $wpdb;

		$this->make_old_pet( array( 'pet_breed' => 'beagle' ) );
		petsync_migrate_9_taxonomy_prefix();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the raw rows the migration's SQL wrote.
		$before = $wpdb->get_results( "SELECT term_taxonomy_id, taxonomy, count FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_A );
		$this->assertTrue( petsync_migrate_9_taxonomy_prefix() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the raw rows the migration's SQL wrote.
		$after = $wpdb->get_results( "SELECT term_taxonomy_id, taxonomy, count FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_A );

		$this->assertSame( $before, $after );
	}
}
