<?php
/**
 * Every script that calls __() is tied to the text domain.
 *
 * translate.wordpress.org extracts strings from JavaScript as well as PHP, and
 * the language packs ship a JSON file per script. WordPress loads that file
 * only for a handle registered with wp_set_script_translations(). Without it
 * the editor stays in English however much of it has been translated, and
 * nothing anywhere reports it: Plugin Check does not look, and an English
 * site cannot show the difference.
 *
 * None of the editor scripts had it. Nor did pet-details' block.json editor
 * script. Core wires those itself, but only when the script's .asset.php lists
 * wp-i18n, and a plugin with no build step has no .asset.php.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

final class ScriptTranslationsTest extends PetTestCase {

	public function test_every_plugin_script_that_translates_has_the_text_domain(): void {
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		// Enqueue everything the pet editor loads. The panel is only constructed
		// in admin, and only enqueues on a pet's edit screen.
		set_current_screen( 'vcps_pet' );
		( new \Petsync_Blocks() )->enqueue_editor_assets();
		( new \Petsync_Pet_Fields() )->enqueue();

		$checked = array();

		foreach ( wp_scripts()->registered as $handle => $script ) {
			if ( ! is_string( $script->src ) || ! str_starts_with( $script->src, PETSYNC_URL ) ) {
				continue;
			}

			$file = PETSYNC_DIR . substr( strtok( $script->src, '?' ), strlen( PETSYNC_URL ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a script file inside this plugin.
			if ( ! is_readable( $file ) || ! preg_match( '/\b_[_nx]\(/', (string) file_get_contents( $file ) ) ) {
				continue;
			}

			$checked[] = $handle;
			$this->assertSame(
				'shelterkit-pets',
				$script->textdomain ?? null,
				"$handle calls __() but has no text domain, so its translations would never load"
			);
		}

		// Not vacuous: the four editor scripts the plugin enqueues itself, plus
		// the one core registers from pet-details' block.json.
		$this->assertContains( 'petsync-pet-fields', $checked );
		$this->assertContains( generate_block_asset_handle( 'petsync/pet-details', 'editorScript' ), $checked );
		$this->assertGreaterThanOrEqual( 5, count( $checked ) );
	}
}
