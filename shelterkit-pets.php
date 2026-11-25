<?php
/**
 * Plugin Name: ShelterKit Pets
 * Plugin URI: https://github.com/jwincek/shelterkit-pets
 * Description: Adoptable pet listings for animal shelters — blocks for cards, grids, filters, galleries, favorites and comparison, with sync from Petstablished.
 * Version: 2.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Jerome Wincek
 * Author URI: https://github.com/jwincek
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shelterkit-pets
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'PETSYNC_VERSION', '2.2.0' );
define( 'PETSYNC_FILE', __FILE__ );
define( 'PETSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PETSYNC_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes.
spl_autoload_register(
	function ( string $class ): void {
		// Legacy classes: Petsync_Foo → includes/class-petsync-foo.php
		if ( str_starts_with( $class, 'Petsync_' ) ) {
				$file = PETSYNC_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}

		// Namespaced classes: Petsync\Core\Config → includes/core/class-config.php
		if ( str_starts_with( $class, 'Petsync\\' ) ) {
			$relative = substr( $class, strlen( 'Petsync\\' ) );
			$parts    = explode( '\\', $relative );
			$name     = array_pop( $parts ); // Class name.
			$dir      = strtolower( implode( '/', $parts ) ); // Sub-directory.
			$file     = PETSYNC_DIR . 'includes/' . $dir . '/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

/**
 * Plugin activation — register CPTs early and flush rewrite rules.
 *
 * register_activation_hook must be called from the main plugin file,
 * not from inside a plugins_loaded callback. CPTs must be registered
 * before flush_rewrite_rules() or the /pets/ archive will 404.
 */
register_activation_hook(
	__FILE__,
	function (): void {
		// Initialize config so CPT_Registry can read post-types.json.
		\Petsync\Core\Config::init( PETSYNC_DIR . 'config/' );

		// Register CPTs and taxonomies so WP knows about the rewrite rules.
		\Petsync\Core\CPT_Registry::register_post_types();
		\Petsync\Core\CPT_Registry::register_taxonomies();

		// A new install has nothing to migrate. Say so now, before anything can
		// create a pet: a site filled from WP-CLI first runs the rail on its
		// first page load, when its pets would make it look like an upgrade.
		if ( petsync_is_new_install() ) {
			update_option( 'petsync_db_version', PETSYNC_DB_VERSION, true );
		}

		// Flush rewrite rules so /pets/ and taxonomy archives work immediately.
		flush_rewrite_rules();

		// Schedule cron sync. Route through the shared helper so the 6pm-anchor
		// and Sunday-skip semantics apply identically at activation and settings save.
		$settings = Petsync_Admin::get_settings();
		if ( ! wp_next_scheduled( 'petsync_scheduled_sync' ) ) {
			Petsync_Admin::reschedule_cron( $settings['auto_sync'], $settings['sync_interval'], (string) $settings['public_key'] );
		}
	}
);

/**
 * Plugin deactivation — clean up cron and flush rewrite rules.
 */
register_deactivation_hook(
	__FILE__,
	function (): void {
		wp_clear_scheduled_hook( 'petsync_scheduled_sync' );
		// Photos an import has yet to download. The pets keep their queue, and
		// the next import schedules it again.
		wp_clear_scheduled_hook( 'petsync_import_photos' );

		// Flush rewrite rules to remove our custom rules cleanly.
		flush_rewrite_rules();
	}
);

/**
 * Initialize the plugin.
 */
function petsync_init(): void {
	// Initialize config loader.
	\Petsync\Core\Config::init( PETSYNC_DIR . 'config/' );

	// Config-driven CPT, taxonomy, and meta registration.
	\Petsync\Core\CPT_Registry::init();

	// Term pages and term links lead to the pet archive with that filter applied.
	\Petsync\Core\Term_Links::init();

	// Which statuses the site shows, and why a synced pet is a draft.
	\Petsync\Core\Pet_Visibility::init();

	// Photos an import named by URL, downloaded after it.
	\Petsync\Import\Photo_Queue::init();

	// Apply compatibility filters (?compat_goodWithDogs=1 etc.) to the pet
	// archive / taxonomy main query. Compatibility data lives in the
	// vcps_pet_attribute taxonomy — it is NOT stored as post meta (the sync
	// keeps it in the _pet_api_response snapshot + attribute terms), so
	// this must be a tax_query. Mirrors Query::whereAttribute() and the
	// filter-pets ability so a no-JS request and the grid block agree.
	add_action(
		'pre_get_posts',
		function ( \WP_Query $query ): void {
			if ( is_admin() || ! $query->is_main_query() ) {
				return;
			}

			$entities   = \Petsync\Core\Config::get_item( 'entities', 'entities', [] );
			$taxonomies = array_column( $entities['vcps_pet']['taxonomies'] ?? [], 'taxonomy' );

			if ( ! $query->is_post_type_archive( 'vcps_pet' ) && ! $query->is_tax( $taxonomies ) ) {
				return;
			}

			// camelCase URL key (compat_<key>) => vcps_pet_attribute term slug.
			// Mirrors \Petsync\Abilities\Pets\COMPAT_MAP and the grid block's URL params.
			$compat_map    = [
				'goodWithDogs'   => 'good-with-dogs',
				'goodWithCats'   => 'good-with-cats',
				'goodWithKids'   => 'good-with-kids',
				'shotsCurrent'   => 'shots-current',
				'spayedNeutered' => 'spayed-neutered',
				'housebroken'    => 'housebroken',
				'specialNeeds'   => 'special-needs',
				'hypoallergenic' => 'hypoallergenic',
				'declawed'       => 'declawed',
			];
			$attribute_tax = $entities['vcps_pet']['attribute_taxonomy'] ?? 'vcps_pet_attribute';

			$compat_clauses = [];
			foreach ( $compat_map as $input_key => $term_slug ) {
				if ( ! empty( $_GET[ 'compat_' . $input_key ] ) ) {
					$compat_clauses[] = [
						'taxonomy' => $attribute_tax,
						'field'    => 'slug',
						'terms'    => $term_slug,
					];
				}
			}

			if ( empty( $compat_clauses ) ) {
				return;
			}

			if ( count( $compat_clauses ) > 1 ) {
				$compat_clauses['relation'] = 'AND';
			}

			// Combine with any pre-existing tax_query (e.g. an explicit one)
			// via AND. On a taxonomy archive the term constraint comes from
			// query vars and WordPress AND-merges it during parse_tax_query.
			$existing = $query->get( 'tax_query' ) ?: [];
			$query->set(
				'tax_query',
				$existing ? [
					'relation' => 'AND',
					$existing,
					$compat_clauses,
				] : $compat_clauses
			);
		}
	);

	// Template helpers — shared functions for block render callbacks.
	require_once PETSYNC_DIR . 'includes/template-helpers.php';

	// Core functionality.
	new Petsync_Blocks();
	new Petsync_Variations();
	new Petsync_Templates();

	// Config-driven abilities registration (replaces old Petsync_Abilities class).
	add_action(
		'wp_abilities_api_categories_init',
		function () {
			wp_register_ability_category(
				'pets',
				[
					'label'       => __( 'Pets', 'shelterkit-pets' ),
					'description' => __( 'Pet adoption data operations.', 'shelterkit-pets' ),
				]
			);
		}
	);
	add_action( 'wp_abilities_api_init', [ \Petsync\Abilities\Provider::class, 'register' ] );

	// Plugin-scoped REST routes for client-side ability execution.
	// The core Abilities REST API at /wp-abilities/v1/ requires an authenticated
	// user for ALL endpoints. Favorites and comparison must work for anonymous
	// front-end visitors, so we register thin routes that delegate to the
	// abilities directly while respecting each ability's permission_callback.
	require_once PETSYNC_DIR . 'includes/class-petsync-rest.php';
	add_action( 'rest_api_init', [ 'Petsync_REST', 'register_routes' ] );

	// Stands a real pet in while the kennel card is designed in the Site
	// Editor, where there is no queried pet. Scoped to petsync/* block-renderer
	// requests that carry no post_id, so nothing on the front end and no other
	// plugin's block preview is affected.
	\Petsync\Core\Editor_Preview::register();

	// Structured data. Off unless switched on — this changes what search engines
	// are told about a site, and where an SEO plugin already describes the
	// organisation, this refines that rather than emitting a second one.
	\Petsync\Schema\Animal_Shelter::register();
	add_action( 'shelterkit_profile_settings', 'petsync_render_schema_setting' );
	add_action( 'shelterkit_profile_saved', 'petsync_save_schema_setting' );

	// Export and import. Petsync\Export\* and Petsync\Import\* resolve through
	// the autoloader above — includes/export/class-schema.php and friends — so
	// nothing needs requiring.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'shelterkit-pets', \Petsync\Export\CLI::class );
		\WP_CLI::add_command( 'shelterkit-pets import', \Petsync\Import\CLI::class );
		\WP_CLI::add_command( 'shelterkit-pets migrate', \Petsync\CLI\Migrate::class );

		// Not `wp shelterkit`. That is the whole ShelterKit family's namespace,
		// not this plugin's: ShelterKit Donations uses `wp shelterkit-donations`,
		// and a sibling's `wp shelterkit migrate` would collide with one here.
		// The 1.1.0 names were deprecated in 1.6.0 and removed in 2.0.0 (#121).
	}

	// Admin & Sync (admin only).
	if ( is_admin() ) {
		// Whichever ShelterKit plugin carries the newest copy renders this
		// screen. If Events won the negotiation, its menu hosts it and this
		// call is simply not reached — the class is loaded either way, so
		// reading the profile works regardless of who owns the UI.
		if ( class_exists( 'ShelterKit_Profile_Versions' )
			&& ShelterKit_Profile_Versions::winner() === PETSYNC_DIR . 'includes/shelterkit/class-shelterkit-profile.php'
		) {
			add_action(
				'admin_menu',
				static function (): void {
					ShelterKit_Profile::add_settings_page( 'edit.php?post_type=vcps_pet' );
				}
			);
		}

		new \Petsync\Export\Admin();
		new \Petsync\Import\Admin();
		new Petsync_Admin();
		new Petsync_Pet_Fields();
		new Petsync_Kennel_Cards();
	}
	new Petsync_Sync();
}
// The shelter profile is a SHARED class, carried byte-identically by every
// ShelterKit plugin — the Action Scheduler pattern. Registering at file scope
// (not on a hook) is what lets the registry see every plugin's copy before it
// picks the highest on plugins_loaded. Nothing here depends on a sibling plugin
// being installed: alone, this copy simply wins.
require_once PETSYNC_DIR . 'includes/shelterkit/class-shelterkit-profile-versions.php';
ShelterKit_Profile_Versions::register( '1.1.0', PETSYNC_DIR . 'includes/shelterkit/class-shelterkit-profile.php' );

/**
 * The structured-data toggle, rendered inside the shared Shelter Details form.
 *
 * Lives beside the address it describes rather than on a sync-settings screen,
 * and says which SEO plugin it detected so the behaviour is not a mystery.
 */
function petsync_render_schema_setting(): void {
	$adapter = \Petsync\Schema\Animal_Shelter::active_adapter();
	$unknown = \Petsync\Schema\Animal_Shelter::unknown_seo_plugin_active();
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Search engines', 'shelterkit-pets' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="petsync_schema_enabled" value="1" <?php checked( \Petsync\Schema\Animal_Shelter::is_enabled() ); ?>>
					<?php esc_html_e( 'Describe this site as an animal shelter in its structured data', 'shelterkit-pets' ); ?>
				</label>
				<p class="description">
					<?php
					if ( null !== $adapter ) {
						printf(
							/* translators: %s: the SEO plugin's name. */
							esc_html__( '%s is active, so this refines the organisation it already describes. Nothing extra is added to your pages.', 'shelterkit-pets' ),
							esc_html( $adapter['label'] )
						);
					} elseif ( $unknown ) {
						esc_html_e( 'An SEO plugin is active that this cannot work with, so nothing will be added — two descriptions of the same organisation are worse than one.', 'shelterkit-pets' );
					} else {
						esc_html_e( 'No SEO plugin was detected, so your shelter details will be described on pet pages. Requires an address or phone number above.', 'shelterkit-pets' );
					}
					?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Save the toggle. The nonce was verified by the profile screen before this runs.
 */
function petsync_save_schema_setting(): void {
	$settings = get_option( 'petsync_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by ShelterKit_Profile::render_page() before this action fires.
	$settings[ \Petsync\Schema\Animal_Shelter::SETTING ] = ! empty( $_POST['petsync_schema_enabled'] );

	update_option( 'petsync_settings', $settings );
}

add_action( 'plugins_loaded', 'petsync_init' );

/**
 * Stored-data schema version this build of the plugin expects.
 *
 * Bump this whenever stored data (options, post meta, cron hook names) changes
 * shape, and add the matching entry to petsync_get_migrations(). This is the
 * plugin's DATA version and is deliberately independent of the release version
 * in the plugin header — most releases change no stored data at all.
 */
define( 'PETSYNC_DB_VERSION', 12 );

/**
 * The oldest stored-data version this build can upgrade from.
 *
 * 12 is what 2.2.0 leaves behind. 3.0.0 removed the twelve migrations that
 * brought older data up to it, after the last site running an older version
 * had upgraded. They worked on names this plugin no longer uses, among them
 * taxonomy names that another plugin may own. A site with older data is told
 * to install 2.2.0 first (see petsync_upgrade_unsupported()), and the rail
 * leaves its data alone: running a later migration on it would record a
 * version whose earlier steps never ran.
 *
 * Raise this only together with removing migrations, and only once no site
 * can still be below the new value.
 */
define( 'PETSYNC_DB_VERSION_MIN', 12 );

/**
 * The ordered migration list.
 *
 * Keyed by the schema version each migration brings the install UP TO, each
 * above PETSYNC_DB_VERSION_MIN. They run in key order, and only those above the
 * installed version run. A new install runs none of them (see
 * petsync_is_new_install()). Every migration must be idempotent: the rail
 * retries one that fails.
 *
 * Empty since 3.0.0. The next change to stored data adds `13 => ...` here.
 *
 * @return array<int, callable> Migration callables keyed by target version.
 */
function petsync_get_migrations(): array {
	return array();
}

/**
 * Whether this site is a new install, with nothing for the migration rail to
 * convert.
 *
 * A new site records the current schema version instead of running the rail.
 * It counts as new only when no version is recorded and nothing this plugin
 * stores exists yet: no pet in any status, and no settings or sync records.
 *
 * Read on the request that would run the rail, and at activation. The latter
 * covers a site set up and filled from WP-CLI, which skips the rail until the
 * first page load, by which time it has pets.
 */
function petsync_is_new_install(): bool {
	if ( false !== get_option( 'petsync_db_version' ) ) {
		return false;
	}

	// 'any' leaves out trashed pets and auto-drafts, so both are named, as in
	// uninstall.php.
	$pets = get_posts(
		array(
			'post_type'     => 'vcps_pet',
			'post_status'   => array( 'any', 'trash', 'auto-draft' ),
			'numberposts'   => 1,
			'fields'        => 'ids',
			'no_found_rows' => true,
		)
	);
	if ( $pets ) {
		return false;
	}

	foreach ( array( 'petsync_settings', 'petsync_last_sync' ) as $option ) {
		if ( false !== get_option( $option ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Whether this site holds data saved by a version older than this build can
 * upgrade (before 2.2.0), so the migration rail will not touch it.
 *
 * A recorded version below PETSYNC_DB_VERSION_MIN, or none at all on a site
 * that is not new: builds before 1.1.0 recorded no version.
 */
function petsync_upgrade_unsupported(): bool {
	$installed = get_option( 'petsync_db_version' );

	if ( false === $installed ) {
		return ! petsync_is_new_install();
	}

	return (int) $installed < PETSYNC_DB_VERSION_MIN;
}

/**
 * Tell an administrator when this site's data is too old to upgrade.
 *
 * Shown on the Plugins screen and the pet screens only, until the data is
 * brought up to date by 2.2.0. Nothing else in the plugin depends on it.
 */
function petsync_unsupported_upgrade_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! ( 'plugins' === $screen->id || str_contains( (string) $screen->id, 'vcps_pet' ) ) ) {
		return;
	}

	if ( ! petsync_upgrade_unsupported() ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
		esc_html__( 'ShelterKit Pets has not updated the pet data on this site.', 'shelterkit-pets' ),
		esc_html__( 'It was saved by a version older than 2.2.0, and this version can no longer convert it. Install ShelterKit Pets 2.2.0, open any admin page so it can update the data, then install this version again.', 'shelterkit-pets' ),
		esc_url( 'https://github.com/jwincek/shelterkit-pets/releases/tag/v2.2.0' ),
		esc_html__( 'Download 2.2.0', 'shelterkit-pets' )
	);
}
add_action( 'admin_notices', 'petsync_unsupported_upgrade_notice' );

/**
 * Run the migration rail.
 *
 * The single implementation, shared by the automatic `init` pass and
 * `wp shelterkit-pets migrate`. A second copy would drift, and the thing most worth
 * not drifting is the rule below about what gets recorded.
 *
 * @param bool               $dry_run    Report what would run without running it.
 * @param callable|null      $reporter   Called as ( int $version, string $event, float $seconds )
 *                                       where $event is 'start', 'done', 'failed' or 'skipped'.
 * @param array<int,callable>|null $migrations Override the rail. For tests only — the
 *                                       failure path decides whether a botched upgrade
 *                                       retries or is skipped forever, and it cannot be
 *                                       exercised through the shipped migrations.
 *                                       An override brings its own starting point,
 *                                       so PETSYNC_DB_VERSION_MIN does not apply to it.
 *                                       Deliberately a parameter rather than
 *                                       a filter: a filter would let any plugin inject
 *                                       migrations into this site's schema.
 * @return array{installed:int, target:int, completed:int, ran:int[], failed:?int, new:bool, unsupported:bool}
 *         `new` is true when this was a new install: nothing ran, and the
 *         current version was recorded. `unsupported` is true when the data
 *         predates PETSYNC_DB_VERSION_MIN: nothing ran, and nothing was recorded.
 */
function petsync_run_migrations( bool $dry_run = false, ?callable $reporter = null, ?array $migrations = null ): array {
	$installed = (int) get_option( 'petsync_db_version', 0 );
	$result    = array(
		'installed'   => $installed,
		'target'      => PETSYNC_DB_VERSION,
		'completed'   => $installed,
		'ran'         => array(),
		'failed'      => null,
		'new'         => false,
		'unsupported' => false,
	);

	if ( $installed >= PETSYNC_DB_VERSION ) {
		return $result;
	}

	if ( petsync_is_new_install() ) {
		if ( ! $dry_run ) {
			update_option( 'petsync_db_version', PETSYNC_DB_VERSION, true );
		}
		$result['completed'] = PETSYNC_DB_VERSION;
		$result['new']       = true;
		return $result;
	}

	if ( null === $migrations && $installed < PETSYNC_DB_VERSION_MIN ) {
		$result['unsupported'] = true;
		return $result;
	}

	// Record only what actually completed. A migration may signal failure by
	// returning false, in which case the rail stops and the stored version
	// keeps the last good step, so the failed one runs again next load.
	//
	// Without this the version advanced unconditionally, which made any
	// migration failure both silent and permanent — the worst combination, and
	// the one this codebase treats as the bug worth designing against.
	//
	// Migrations returning void are unaffected: null is not identical to false.
	$completed = $installed;

	foreach ( $migrations ?? petsync_get_migrations() as $version => $callback ) {
		if ( $version <= $installed ) {
			continue;
		}

		if ( ! is_callable( $callback ) ) {
			if ( $reporter ) {
				$reporter( $version, 'skipped', 0.0 );
			}
			continue;
		}

		if ( $dry_run ) {
			$result['ran'][] = $version;
			if ( $reporter ) {
				$reporter( $version, 'start', 0.0 );
			}
			$completed = $version;
			continue;
		}

		if ( $reporter ) {
			$reporter( $version, 'start', 0.0 );
		}

		$started = microtime( true );
		$outcome = call_user_func( $callback );
		$elapsed = microtime( true ) - $started;

		if ( false === $outcome ) {
			$result['failed'] = $version;
			if ( $reporter ) {
				$reporter( $version, 'failed', $elapsed );
			}
			break;
		}

		$result['ran'][] = $version;
		$completed       = $version;

		if ( $reporter ) {
			$reporter( $version, 'done', $elapsed );
		}
	}

	if ( ! $dry_run && $completed > $installed ) {
		update_option( 'petsync_db_version', $completed, true );
	}

	$result['completed'] = $completed;

	return $result;
}

/**
 * Run any migrations the installed schema version hasn't seen yet.
 *
 * Hooked late on `init` so the CPT (priority 10) and its registered meta
 * (priority 11) both exist before a migration touches pet records.
 *
 * Deliberately does nothing under WP-CLI. Two reasons, and the second is the
 * one that matters: an operator at a terminal can run
 * `wp shelterkit-pets migrate` and watch it, which is the whole point of that
 * command existing; and a
 * multi-second data migration should not fire as a side effect of an unrelated
 * command like `wp plugin list`. Web traffic still triggers the rail normally,
 * so a site that is never migrated from the command line still migrates itself.
 */
function petsync_maybe_upgrade(): void {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	petsync_run_migrations();
}
add_action( 'init', 'petsync_maybe_upgrade', 20 );
