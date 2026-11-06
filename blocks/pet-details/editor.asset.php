<?php
/**
 * Dependencies for editor.js, read by register_block_type().
 *
 * A build step would generate this file; this plugin has none, so it is kept
 * by hand. Without it the script declared no dependencies, printed before
 * wp-element and wp-block-editor, and died on its first line ("wp is not
 * defined") on every clean install. The Pet Details block never got its
 * editing interface. ScriptDependenciesTest fails if a wp.* global used in
 * editor.js is missing from this list.
 *
 * It still runs before assets/js/blocks-editor.js, which it must: it extends
 * that file's registerBlockType() call through the blocks.registerBlockType
 * filter, and blocks-editor.js is enqueued in the footer.
 *
 * @package ShelterKit_Pets
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array( 'wp-block-editor', 'wp-components', 'wp-element', 'wp-hooks', 'wp-i18n' ),
	'version'      => PETSYNC_VERSION,
);
