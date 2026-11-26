<?php
/**
 * The stored-data migration rail.
 *
 * Migrations run once, against real installs, and a mistake is not undoable.
 * The rail decides what runs and what gets recorded: a new install runs
 * nothing, data older than the rail can convert is left alone, and a failed
 * migration is retried rather than skipped.
 *
 * 3.0.0 removed migrations 1 to 12 and their tests. Their history is in git
 * up to tag v2.2.0.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

final class MigrationsTest extends PetTestCase {

	public function tear_down(): void {
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	public function test_declared_migrations_run_from_the_floor_to_the_current_version(): void {
		$migrations = petsync_get_migrations();
		$expected   = PETSYNC_DB_VERSION > PETSYNC_DB_VERSION_MIN
			? range( PETSYNC_DB_VERSION_MIN + 1, PETSYNC_DB_VERSION )
			: array();

		$this->assertSame( $expected, array_keys( $migrations ), 'migrations must be contiguous from the floor to PETSYNC_DB_VERSION' );

		foreach ( $migrations as $version => $callback ) {
			$this->assertTrue( is_callable( $callback ), "migration {$version} is not callable" );
		}
	}

	// ── A new install ────────────────────────────────────────────────────────
	//
	// The rail converts data from older builds. A new site has none, and must
	// not run a migration written for names that may be another plugin's.

	/**
	 * A taxonomy with one of the names the pet taxonomies had before 2.0, as
	 * another pet plugin leaves it. Not registered: that plugin may be
	 * inactive, and then nothing shows whose the terms are.
	 *
	 * @return int Its one term's ID.
	 */
	private function foreign_breed(): int {
		register_taxonomy( 'pet_breed', 'post' );
		$term = wp_insert_term( 'Their Beagle', 'pet_breed' );
		wp_set_object_terms( self::factory()->post->create(), (int) $term['term_id'], 'pet_breed' );
		unregister_taxonomy( 'pet_breed' );

		return (int) $term['term_id'];
	}

	private function taxonomy_of( int $term_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the raw row a migration would rewrite.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $term_id ) );
	}

	/**
	 * Found by the WordPress.org plugin review in 2.1.0: a new install started
	 * at version 0 and ran every migration, so the taxonomy rename moved every
	 * pet_* taxonomy on the site and rewrote the block markup that named one.
	 */
	public function test_a_new_install_runs_no_migration(): void {
		delete_option( 'petsync_db_version' );
		$term   = $this->foreign_breed();
		$markup = '<!-- wp:post-terms {"term":"pet_breed"} /-->';
		$part   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_content' => $markup,
			)
		);

		$result = petsync_run_migrations();

		$this->assertTrue( $result['new'] );
		$this->assertFalse( $result['unsupported'] );
		$this->assertSame( array(), $result['ran'] );
		$this->assertSame( PETSYNC_DB_VERSION, (int) get_option( 'petsync_db_version' ) );
		$this->assertSame( 'pet_breed', $this->taxonomy_of( $term ), "another plugin's terms keep their taxonomy" );
		$this->assertSame( $markup, get_post( $part )->post_content );
	}

	public function test_a_dry_run_on_a_new_install_writes_nothing(): void {
		delete_option( 'petsync_db_version' );

		$this->assertTrue( petsync_run_migrations( true )['new'] );
		$this->assertFalse( get_option( 'petsync_db_version' ) );
	}

	/**
	 * Anything the plugin stores means the site is not new.
	 *
	 * @dataProvider stored_data
	 *
	 * @param string $leftover What the site already holds.
	 */
	public function test_stored_data_means_the_site_is_not_new( string $leftover ): void {
		delete_option( 'petsync_db_version' );
		$this->assertTrue( petsync_is_new_install(), 'nothing is stored yet' );

		match ( $leftover ) {
			'a pet'         => $this->make_manual_pet(),
			'a trashed pet' => $this->make_manual_pet( array( 'post_status' => 'trash' ) ),
			'settings'      => update_option( 'petsync_settings', array( 'auto_sync' => false ) ),
			'a sync'        => update_option( 'petsync_last_sync', time() ),
		};

		$this->assertFalse( petsync_is_new_install() );
		$this->assertFalse( petsync_run_migrations()['new'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function stored_data(): array {
		$leftovers = array( 'a pet', 'a trashed pet', 'settings', 'a sync' );

		return array_combine( $leftovers, array_map( static fn( string $leftover ): array => array( $leftover ), $leftovers ) );
	}

	/**
	 * Activation records the version too. A site set up from WP-CLI has pets by
	 * the time its first page load runs the rail, so the rail alone would take
	 * it for an upgrade.
	 */
	public function test_activation_records_the_version_on_a_new_install_only(): void {
		$activate = 'activate_' . plugin_basename( PETSYNC_FILE );

		delete_option( 'petsync_db_version' );
		do_action( $activate );
		$this->assertSame( PETSYNC_DB_VERSION, (int) get_option( 'petsync_db_version' ) );

		delete_option( 'petsync_db_version' );
		$this->make_manual_pet();
		do_action( $activate );
		$this->assertFalse( get_option( 'petsync_db_version' ), 'a site with pets is upgrading' );

		wp_clear_scheduled_hook( 'petsync_scheduled_sync' );
	}

	// ── Data from before 2.2.0 ───────────────────────────────────────────────
	//
	// 3.0.0 removed the migrations that brought it up to version 12. A
	// migration added later and run on such data would record a version whose
	// earlier steps never ran, so the rail leaves it alone and says why.

	/**
	 * @dataProvider versions_below_the_floor
	 *
	 * @param int $version A recorded schema version older than 2.2.0's.
	 */
	public function test_data_older_than_2_2_0_is_left_alone( int $version ): void {
		$this->make_manual_pet();
		update_option( 'petsync_db_version', $version, true );

		$result = petsync_run_migrations();

		$this->assertTrue( $result['unsupported'] );
		$this->assertSame( array(), $result['ran'] );
		$this->assertSame( $version, (int) get_option( 'petsync_db_version' ), 'nothing is recorded' );
		$this->assertTrue( petsync_upgrade_unsupported() );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function versions_below_the_floor(): array {
		return array(
			'1.0.0'        => array( 1 ),
			'1.5.0'        => array( 8 ),
			'2.1.0'        => array( 11 ),
			'0, as stored' => array( 0 ),
		);
	}

	/**
	 * Builds before 1.1.0 recorded no version at all.
	 */
	public function test_pets_without_a_recorded_version_are_too_old(): void {
		delete_option( 'petsync_db_version' );
		$this->make_manual_pet();

		$this->assertTrue( petsync_upgrade_unsupported() );
		$this->assertTrue( petsync_run_migrations()['unsupported'] );
		$this->assertFalse( get_option( 'petsync_db_version' ) );
	}

	public function test_current_and_new_sites_are_not_too_old(): void {
		update_option( 'petsync_db_version', PETSYNC_DB_VERSION_MIN, true );
		$this->assertFalse( petsync_upgrade_unsupported() );
		$this->assertFalse( petsync_run_migrations()['unsupported'] );

		delete_option( 'petsync_db_version' );
		$this->assertFalse( petsync_upgrade_unsupported(), 'a new install' );
	}

	/**
	 * The notice is how an administrator learns why the data was not updated.
	 */
	public function test_the_notice_names_2_2_0_where_an_administrator_will_see_it(): void {
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		$this->make_manual_pet();
		update_option( 'petsync_db_version', 11, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'plugins' );
		$this->assertStringContainsString( '2.2.0', get_echo( 'petsync_unsupported_upgrade_notice' ) );

		set_current_screen( 'edit-vcps_pet' );
		$this->assertStringContainsString( '2.2.0', get_echo( 'petsync_unsupported_upgrade_notice' ), 'the pet list too' );

		set_current_screen( 'dashboard' );
		$this->assertSame( '', get_echo( 'petsync_unsupported_upgrade_notice' ), 'not on unrelated screens' );

		set_current_screen( 'plugins' );
		update_option( 'petsync_db_version', PETSYNC_DB_VERSION_MIN, true );
		$this->assertSame( '', get_echo( 'petsync_unsupported_upgrade_notice' ), 'not once the data is current' );

		update_option( 'petsync_db_version', 11, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', get_echo( 'petsync_unsupported_upgrade_notice' ), 'not to someone who cannot install plugins' );
	}

	// ── The migration rail itself ────────────────────────────────────────────
	//
	// The shipped rail is empty, so these inject one. An injected rail brings
	// its own starting point, which is why they can start below the floor.

	/**
	 * The real rail, end to end, on a new install: the version lands on
	 * PETSYNC_DB_VERSION.
	 */
	public function test_the_rail_records_the_full_version_on_a_new_install(): void {
		delete_option( 'petsync_db_version' );

		petsync_maybe_upgrade();

		$this->assertSame( PETSYNC_DB_VERSION, (int) get_option( 'petsync_db_version' ) );
	}

	public function test_nothing_runs_when_already_up_to_date(): void {
		update_option( 'petsync_db_version', PETSYNC_DB_VERSION, true );

		$result = petsync_run_migrations();

		$this->assertSame( array(), $result['ran'] );
		$this->assertNull( $result['failed'] );
		$this->assertFalse( $result['unsupported'] );
	}

	/**
	 * The rail is the riskiest part of an upgrade and it used to fire on
	 * whichever page load happened to be first — on a live site, a member of
	 * the public's. `wp shelterkit-pets migrate --dry-run` shows what it would
	 * do, and both paths share this one implementation so they cannot drift.
	 */
	public function test_a_dry_run_reports_every_pending_migration_and_writes_nothing(): void {
		update_option( 'petsync_db_version', 0, true );

		$called = false;
		$result = petsync_run_migrations(
			true,
			null,
			array(
				1 => static function () use ( &$called ) {
					$called = true;
				},
				2 => static function () use ( &$called ) {
					$called = true;
				},
			)
		);

		$this->assertSame( array( 1, 2 ), $result['ran'] );
		$this->assertSame( 2, $result['completed'] );
		$this->assertNull( $result['failed'] );
		$this->assertFalse( $called, 'a dry run that calls a migration is not a dry run' );
		$this->assertSame(
			0,
			(int) get_option( 'petsync_db_version' ),
			'a dry run that advances the recorded version is not a dry run'
		);
	}

	public function test_a_real_run_advances_the_recorded_version(): void {
		update_option( 'petsync_db_version', 0, true );

		$result = petsync_run_migrations(
			false,
			null,
			array(
				1 => static fn() => null,
				2 => static fn() => true,
			)
		);

		$this->assertSame( array( 1, 2 ), $result['ran'] );
		$this->assertSame( 2, $result['completed'] );
		$this->assertSame( 2, (int) get_option( 'petsync_db_version' ) );
	}

	/**
	 * The rule the whole rail exists for: a failure records the completed steps
	 * and stops, so the failed one RETRIES rather than being skipped forever.
	 * Before this design the version advanced unconditionally, which made any
	 * migration failure both silent and permanent.
	 */
	public function test_a_failure_stops_the_rail_and_records_only_what_completed(): void {
		update_option( 'petsync_db_version', 0, true );

		$ran        = array();
		$migrations = array(
			1 => static function () use ( &$ran ) {
				$ran[] = 1;
			},
			2 => static function () use ( &$ran ) {
				$ran[] = 2;
				return false;
			},
			3 => static function () use ( &$ran ) {
				$ran[] = 3;
			},
		);

		$result = petsync_run_migrations( false, null, $migrations );

		$this->assertSame( array( 1, 2 ), $ran, 'the rail must stop at the failure, not carry on' );
		$this->assertSame( 2, $result['failed'] );
		$this->assertSame( array( 1 ), $result['ran'] );
		$this->assertSame( 1, $result['completed'] );
		$this->assertSame(
			1,
			(int) get_option( 'petsync_db_version' ),
			'recording 2 would skip the failed migration forever; recording 0 would re-run the one that worked'
		);
	}

	/**
	 * And the retry actually happens: running again re-attempts the failed one
	 * without repeating the completed one.
	 */
	public function test_the_failed_migration_is_retried_and_the_completed_one_is_not(): void {
		update_option( 'petsync_db_version', 0, true );

		$ran   = array();
		$fails = true;
		$rail  = static function () use ( &$ran, &$fails ): array {
			return array(
				1 => static function () use ( &$ran ) {
					$ran[] = 1;
				},
				2 => static function () use ( &$ran, &$fails ) {
					$ran[] = 2;
					return $fails ? false : null;
				},
			);
		};

		petsync_run_migrations( false, null, $rail() );
		$this->assertSame( array( 1, 2 ), $ran );

		$fails = false;
		petsync_run_migrations( false, null, $rail() );

		$this->assertSame( array( 1, 2, 2 ), $ran, 'migration 1 must not run twice; migration 2 must be retried' );
		$this->assertSame( 2, (int) get_option( 'petsync_db_version' ) );
	}

	/**
	 * The reporter is what `wp shelterkit-pets migrate` prints. An upgrade that
	 * reports nothing is the situation this command exists to end.
	 */
	public function test_the_reporter_is_called_for_every_migration(): void {
		update_option( 'petsync_db_version', 0, true );

		$events = array();
		petsync_run_migrations(
			false,
			static function ( int $version, string $event, float $seconds ) use ( &$events ): void {
				$events[] = array( $version, $event );
				// Timings are what tell an operator whether to worry.
				if ( 'done' === $event ) {
					self::assertGreaterThanOrEqual( 0.0, $seconds );
				}
			},
			array(
				1 => static fn() => null,
				2 => static fn() => null,
			)
		);

		$this->assertSame(
			array( array( 1, 'start' ), array( 1, 'done' ), array( 2, 'start' ), array( 2, 'done' ) ),
			$events
		);
	}

	/**
	 * Every migration on the rail needs a line explaining what it does, because
	 * the operator reading `--dry-run` on a live site is deciding whether to
	 * proceed. A version added without one is a blank line in that output.
	 */
	public function test_every_migration_has_an_operator_facing_description(): void {
		$described = ( new \ReflectionClass( \Petsync\CLI\Migrate::class ) )->getConstant( 'DESCRIPTIONS' );

		$this->assertSame(
			array(),
			array_diff( array_keys( (array) $described ), array_keys( petsync_get_migrations() ) ),
			'a description left behind for a migration that no longer exists'
		);

		foreach ( array_keys( petsync_get_migrations() ) as $version ) {
			$this->assertArrayHasKey(
				$version,
				(array) $described,
				"migration $version has no description, so --dry-run would not say what it does"
			);
			$this->assertNotSame( '', trim( (string) $described[ $version ] ) );
		}
	}
}
