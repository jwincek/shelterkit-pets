<?php
/**
 * Every script declares the WordPress packages it uses.
 *
 * Found on a clean install of the built zip. The Pet Details block's
 * block.json editor script had no .asset.php, so it declared no dependencies,
 * printed before wp-element, and threw "wp is not defined" on its first line.
 * The block never got its editing interface. On a site where other plugins
 * happened to load those packages earlier, nothing showed it.
 *
 * Script order is not something a test can reproduce, so this checks the
 * declaration instead. Every wp.* global a script's source uses must be loaded
 * by its dependency closure.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

final class ScriptDependenciesTest extends PetTestCase {

	/**
	 * Globals a script deliberately does without, keyed by handle.
	 *
	 * @var array<string, string[]>
	 */
	private const OPTIONAL = array(
		// Prefers wp.editor and falls back to wp.editPost, where
		// PluginDocumentSettingPanel lived before it moved.
		'petsync-pet-fields' => array( 'wp-edit-post' ),
	);

	public function test_every_plugin_script_declares_what_it_uses(): void {
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( 'vcps_pet' );
		( new \Petsync_Blocks() )->enqueue_editor_assets();
		( new \Petsync_Pet_Fields() )->enqueue();

		$scripts = wp_scripts();
		$checked = array();

		foreach ( $scripts->registered as $handle => $script ) {
			if ( ! is_string( $script->src ) || ! str_starts_with( $script->src, PETSYNC_URL ) ) {
				continue;
			}

			$file = PETSYNC_DIR . substr( strtok( $script->src, '?' ), strlen( PETSYNC_URL ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a script file inside this plugin.
			preg_match_all( '/\bwp\.([a-zA-Z0-9]+)/', (string) file_get_contents( $file ), $m );

			$used = array_unique(
				array_map(
					static fn( string $g ): string => 'wp-' . strtolower( (string) preg_replace( '/([a-z])([A-Z])/', '$1-$2', $g ) ),
					$m[1]
				)
			);

			$missing = array_diff( $used, $this->closure( $handle ), self::OPTIONAL[ $handle ] ?? array() );

			$this->assertSame( array(), array_values( $missing ), "$handle uses these packages without depending on them, so it can run before they exist" );
			$checked[] = $handle;
		}

		$this->assertContains( generate_block_asset_handle( 'petsync/pet-details', 'editorScript' ), $checked );
		$this->assertGreaterThanOrEqual( 6, count( $checked ) );
	}

	/**
	 * Every handle a script loads, directly or through its dependencies.
	 *
	 * @return string[]
	 */
	private function closure( string $handle, array &$seen = array() ): array {
		foreach ( wp_scripts()->registered[ $handle ]->deps ?? array() as $dep ) {
			if ( ! isset( $seen[ $dep ] ) ) {
				$seen[ $dep ] = true;
				$this->closure( $dep, $seen );
			}
		}

		return array_keys( $seen );
	}
}
