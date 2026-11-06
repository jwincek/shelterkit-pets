<?php
/**
 * The nightly sync is scheduled only for a site that has something to sync.
 *
 * Found on a clean install of the built zip. Activation scheduled the sync on
 * every site because auto_sync defaults to on, though most shelters never
 * enter a Petstablished key, since no account is needed. Each evening's run
 * could only fail, and each failure went into the Sync Log, so a standalone
 * shelter's log held nothing but errors about an account it never had.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

final class SyncScheduleTest extends PetTestCase {

	private const HOOK = 'petsync_scheduled_sync';

	public function set_up(): void {
		parent::set_up();
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( 'petsync_settings' );
		delete_option( 'petsync_sync_log' );
	}

	public function test_nothing_is_scheduled_without_a_key(): void {
		\Petsync_Admin::reschedule_cron( true, \Petsync_Admin::SCHEDULE_6PM_SKIP_SUNDAY, '' );
		$this->assertFalse( wp_next_scheduled( self::HOOK ) );

		\Petsync_Admin::reschedule_cron( true, \Petsync_Admin::SCHEDULE_6PM_SKIP_SUNDAY, 'pk_test' );
		$this->assertNotFalse( wp_next_scheduled( self::HOOK ), 'with a key, the default schedule still applies' );
	}

	/**
	 * auto_sync is on by default, so entering a key for the first time changes
	 * neither of the two settings the save used to watch. The key itself has to
	 * trigger the reschedule, or no keyed site would ever sync on its own.
	 */
	public function test_entering_a_key_schedules_the_sync_and_removing_it_stops_it(): void {
		$admin = new \Petsync_Admin();
		$input = array(
			'auto_sync'     => '1',
			'sync_interval' => \Petsync_Admin::SCHEDULE_6PM_SKIP_SUNDAY,
		);

		update_option( 'petsync_settings', $admin->sanitize_settings( $input + array( 'public_key' => 'pk_test' ) ) );
		$this->assertNotFalse( wp_next_scheduled( self::HOOK ) );

		update_option( 'petsync_settings', $admin->sanitize_settings( $input + array( 'public_key' => '' ) ) );
		$this->assertFalse( wp_next_scheduled( self::HOOK ) );
	}

	/**
	 * Sites that older versions already scheduled stop by themselves, without
	 * a failure in the log.
	 */
	public function test_a_keyless_scheduled_run_stops_the_schedule_quietly(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );

		$this->assertFalse( ( new \Petsync_Sync() )->run_sync( 'cron' ) );

		$this->assertFalse( wp_next_scheduled( self::HOOK ) );
		$this->assertSame( array(), \Petsync_Sync_Log::all(), 'no key is a choice, not an error' );
	}

	/**
	 * Asking for a sync by hand with no key is still a mistake worth recording.
	 */
	public function test_a_keyless_manual_run_is_still_logged(): void {
		( new \Petsync_Sync() )->run_sync( 'manual' );

		$this->assertCount( 1, \Petsync_Sync_Log::all() );
	}
}
