<?php
/**
 * Deleting the plugin, run for real.
 *
 * uninstall.php runs with the plugin NOT loaded, so neither the pet post type
 * nor any pet_* taxonomy is registered while it works. A test suite never sees
 * that on its own: the plugin registers them at init and they stay registered
 * for the whole run. run_uninstall() takes them away for the duration, or a
 * pass here would say nothing about a real uninstall.
 *
 * Before this, the only uninstall test read the file as text, and the file had
 * a defect that reading could not see: Kennel Cards wrote the editor-preview
 * option and nothing deleted it.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Core\CPT_Registry;
use Petsync\Core\Editor_Preview;

final class UninstallTest extends PetTestCase {

	/**
	 * Everything the destructive tier promises to remove is gone, and the two
	 * things it deliberately keeps are not.
	 */
	public function test_opting_in_removes_what_it_says_and_keeps_what_it_says(): void {
		$this->opt_in( true );

		$manual = $this->make_manual_pet();
		$synced = $this->make_synced_pet();
		wp_set_object_terms( $manual, 'Uninstall Beagle', 'pet_breed' );

		$photo = self::factory()->attachment->create_object(
			'uninstall-photo.jpg',
			$synced,
			array( 'post_mime_type' => 'image/jpeg' )
		);
		set_post_thumbnail( $synced, $photo );

		update_option( Editor_Preview::OPTION, $manual );
		update_option( 'petsync_last_sync', time() );
		update_option( 'petsync_db_version', PETSYNC_DB_VERSION );
		update_option( 'shelterkit_organization', array( 'name' => 'Shared Shelter' ) );

		$user = self::factory()->user->create();
		update_user_meta( $user, '_pet_favorites', array( $manual ) );

		// Customised kennel cards, under the current namespace and a legacy one
		// that migration 4 would normally have consolidated.
		$parts = array(
			$this->make_customised_part( \Petsync_Templates::THEME_NAMESPACE ),
			$this->make_customised_part( 'petstablished-sync' ),
		);

		$this->run_uninstall();

		$this->assertNull( get_post( $manual ), 'a hand-entered pet is deleted too' );
		$this->assertNull( get_post( $synced ) );
		$this->assertNull( term_exists( 'Uninstall Beagle', 'pet_breed' ), 'terms are deleted although the taxonomy was unregistered' );

		$this->assertFalse( get_option( 'petsync_settings' ) );
		$this->assertFalse( get_option( 'petsync_last_sync' ) );
		$this->assertFalse( get_option( 'petsync_db_version' ) );
		$this->assertFalse( get_option( Editor_Preview::OPTION ), 'the preview pet is plugin state like any other' );

		$this->assertSame( '', get_user_meta( $user, '_pet_favorites', true ) );

		foreach ( $parts as $part ) {
			$this->assertNull( get_post( $part ), 'Site Editor customisations go, legacy namespace included' );
		}
		$this->assertFalse( get_term_by( 'name', \Petsync_Templates::THEME_NAMESPACE, 'wp_theme' ) );

		$this->assertSame(
			array( 'name' => 'Shared Shelter' ),
			get_option( 'shelterkit_organization' ),
			'the shelter profile is shared with the other ShelterKit plugins'
		);

		// Core re-parents a deleted post's attachments with a raw UPDATE and
		// leaves the post cache alone, so read the row rather than the cache.
		clean_post_cache( $photo );
		$kept = get_post( $photo );
		$this->assertNotNull( $kept, 'photos stay in the Media Library, as the readme says' );
		$this->assertSame( 0, (int) $kept->post_parent, 'and are left unattached' );
	}

	/**
	 * The default keeps all data, so delete-and-reinstall loses nothing, but the
	 * schedule and transients go regardless.
	 */
	public function test_by_default_only_ephemeral_state_is_removed(): void {
		$this->opt_in( false );

		$pet = $this->make_manual_pet();
		update_option( Editor_Preview::OPTION, $pet );
		set_transient( 'petsync_sync_session', array( 'total' => 3 ), HOUR_IN_SECONDS );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'petsync_scheduled_sync' );

		$this->run_uninstall();

		$this->assertNotNull( get_post( $pet ) );
		$this->assertNotFalse( get_option( 'petsync_settings' ) );
		$this->assertSame( $pet, (int) get_option( Editor_Preview::OPTION ) );

		$this->assertFalse( get_transient( 'petsync_sync_session' ) );
		$this->assertFalse( wp_next_scheduled( 'petsync_scheduled_sync' ) );
	}

	private function opt_in( bool $delete ): void {
		update_option(
			'petsync_settings',
			array_merge( \Petsync_Admin::get_settings(), array( 'delete_data_on_uninstall' => $delete ) )
		);
	}

	private function make_customised_part( string $theme ): int {
		$part = self::factory()->post->create(
			array(
				'post_type'   => 'wp_template_part',
				'post_name'   => 'kennel-card',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $part, $theme, 'wp_theme' );

		return $part;
	}

	/**
	 * Run uninstall.php as WordPress does: with nothing of this plugin's
	 * registered. Registrations come back from the plugin's own registry
	 * afterwards, exactly as PetTestCase::set_up() rebuilds them.
	 */
	private function run_uninstall(): void {
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'shelterkit-pets/shelterkit-pets.php' );

		$taxonomies = get_object_taxonomies( 'vcps_pet' );
		foreach ( $taxonomies as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
		}
		unregister_post_type( 'vcps_pet' );

		try {
			// The file calls petsync_uninstall() itself, and can only be loaded once.
			if ( function_exists( 'petsync_uninstall' ) ) {
				petsync_uninstall();
			} else {
				require PETSYNC_DIR . 'uninstall.php';
			}
		} finally {
			CPT_Registry::register_post_types();
			CPT_Registry::register_taxonomies();
			CPT_Registry::register_meta();
		}
	}
}
