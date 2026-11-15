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
	 * wp.editPost's sidebar components moved to wp.editor in WordPress 6.6, and
	 * every editor load logged a deprecation for each one. Found on the 2.0.0
	 * clean-install pass. pet-fields.js keeps wp.editPost only as a fallback.
	 */
	public function test_no_script_reaches_for_the_deprecated_editpost_sidebar(): void {
		$files = array_merge( glob( PETSYNC_DIR . 'assets/js/*.js' ) ?: array(), glob( PETSYNC_DIR . 'blocks/*/*.js' ) ?: array() );
		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a script file inside this plugin.
			$src = (string) file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression(
				'/\{[^}]*\bPluginSidebar\b[^}]*\}\s*=\s*wp\.editPost\b/',
				$src,
				basename( $file ) . ' takes PluginSidebar from wp.editPost, deprecated since 6.6; use wp.editor'
			);
		}
	}

	/**
	 * The block-preview styles belong in the editor canvas, which is an iframe.
	 * Enqueued on enqueue_block_editor_assets they reach it only through
	 * WordPress's fallback copy, which logs "was added to the iframe
	 * incorrectly" on every editor load. Found on the 2.0.0 clean-install pass.
	 */
	public function test_the_editor_styles_load_through_the_canvas_hook_in_admin_only(): void {
		$handle = 'petsync-editor';

		( new \Petsync_Blocks() )->enqueue_editor_assets();
		$this->assertFalse( wp_style_is( $handle, 'enqueued' ), 'not on enqueue_block_editor_assets' );

		$blocks = new \Petsync_Blocks();
		$blocks->enqueue_canvas_styles();
		$this->assertFalse( wp_style_is( $handle, 'enqueued' ), 'enqueue_block_assets fires on the front end too, where these do not belong' );

		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( 'vcps_pet' );
		$blocks->enqueue_canvas_styles();
		$this->assertTrue( wp_style_is( $handle, 'enqueued' ) );
		$this->assertNotFalse( has_action( 'enqueue_block_assets', array( $blocks, 'enqueue_canvas_styles' ) ) );
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
