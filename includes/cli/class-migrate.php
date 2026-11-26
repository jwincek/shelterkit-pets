<?php
/**
 * WP-CLI migration command.
 *
 * The rail runs itself on `init`, which is right for an ordinary site and wrong
 * for a deploy: the riskiest step of an upgrade happens implicitly, on whichever
 * page load happens to be first, with no output and nobody watching. On a live
 * site that page load belongs to a member of the public.
 *
 * This makes it deliberate, observable, and — since CLI has no
 * max_execution_time — safe to run against a large catalogue.
 *
 * @package ShelterKit_Pets
 * @since   1.1.0
 */

declare( strict_types = 1 );

namespace Petsync\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrate {

	/**
	 * What each migration does, for the operator who is about to run it.
	 *
	 * Keyed by version. A migration with no entry still runs; it just gets no
	 * description, which is a prompt to add one rather than a failure.
	 *
	 * Empty since 3.0.0, which removed migrations 1 to 12 from the rail.
	 *
	 * @var array<int, string>
	 */
	private const DESCRIPTIONS = array();

	/**
	 * Run pending database migrations.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List the migrations that would run, and stop. Nothing is written.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what an upgrade would do, before doing it.
	 *     wp shelterkit-pets migrate --dry-run
	 *
	 *     # Run them, deliberately, with timings.
	 *     wp shelterkit-pets migrate
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );

		$installed = (int) get_option( 'petsync_db_version', 0 );

		\WP_CLI::log( sprintf( 'Schema version %d; this build expects %d.', $installed, PETSYNC_DB_VERSION ) );

		if ( $installed >= PETSYNC_DB_VERSION ) {
			\WP_CLI::success( 'Nothing to do.' );
			return;
		}

		if ( petsync_is_new_install() ) {
			if ( ! $dry_run ) {
				petsync_run_migrations();
			}
			\WP_CLI::success(
				sprintf(
					'A new install: nothing to convert. %s schema version %d.',
					$dry_run ? 'Would record' : 'Recorded',
					PETSYNC_DB_VERSION
				)
			);
			return;
		}

		if ( petsync_upgrade_unsupported() ) {
			\WP_CLI::error(
				sprintf(
					'This site\'s pet data was saved by a version older than 2.2.0 (schema %d), which this version '
					. 'can no longer convert. Install ShelterKit Pets 2.2.0, run `wp shelterkit-pets migrate`, '
					. 'then install this version again.',
					$installed
				)
			);
		}

		$reporter = static function ( int $version, string $event, float $seconds ) use ( $dry_run ): void {
			$what = self::DESCRIPTIONS[ $version ] ?? 'no description recorded';

			switch ( $event ) {
				case 'start':
					\WP_CLI::log( sprintf( '  %s %d: %s', $dry_run ? 'would run' : 'running', $version, $what ) );
					break;
				case 'done':
					\WP_CLI::log( sprintf( '    done in %.2fs', $seconds ) );
					break;
				case 'failed':
					\WP_CLI::warning( sprintf( 'Migration %d reported failure after %.2fs. The rail stops here.', $version, $seconds ) );
					break;
				case 'skipped':
					\WP_CLI::warning( sprintf( 'Migration %d is not callable and was skipped.', $version ) );
					break;
			}
		};

		$started = microtime( true );
		$result  = petsync_run_migrations( $dry_run, $reporter );
		$elapsed = microtime( true ) - $started;

		if ( $dry_run ) {
			\WP_CLI::success(
				sprintf(
					'%d migration(s) would run, taking the schema from %d to %d. Nothing was written.',
					count( $result['ran'] ),
					$result['installed'],
					$result['completed']
				)
			);
			return;
		}

		if ( null !== $result['failed'] ) {
			// Not WP_CLI::error: the rail is designed so a failure leaves the
			// version at the last good step and retries. Saying "failed" without
			// saying "and the completed ones are recorded" invites someone to
			// restore a backup they did not need to.
			\WP_CLI::warning(
				sprintf(
					'Stopped at migration %d. Schema is recorded as %d, so the completed migrations will not re-run; '
					. 'migration %d will be retried on the next attempt.',
					$result['failed'],
					$result['completed'],
					$result['failed']
				)
			);
			\WP_CLI::halt( 1 );
		}

		\WP_CLI::success(
			sprintf(
				'%d migration(s) in %.2fs. Schema is now %d.',
				count( $result['ran'] ),
				$elapsed,
				$result['completed']
			)
		);
	}
}
