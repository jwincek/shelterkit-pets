<?php
/**
 * The export column schema.
 *
 * Derived from config, never hand-listed, so a field added to entities.json
 * becomes an exportable column with no change here — and so #31's importer can
 * derive its columns from the same place, making the round-trip true by
 * construction rather than by discipline.
 *
 * @package ShelterKit_Pets
 * @since   1.1.0
 */

declare( strict_types = 1 );

namespace Petsync\Export;

use Petsync\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schema {

	/**
	 * Columns that survive a round-trip: identity, taxonomies, editable fields.
	 */
	public const PORTABLE = 'portable';

	/**
	 * Everything the hydrator emits, computed fields included. For reading, not
	 * for re-importing.
	 */
	public const FULL = 'full';

	/**
	 * Separates the values in a cell that holds several: every term of a
	 * taxonomy, every image in a gallery. Chosen because no breed, colour or
	 * attachment ID contains one, and a spreadsheet leaves it alone.
	 */
	public const LIST_SEPARATOR = '|';

	/**
	 * Identity and display columns. `id` is the local post ID, which an import
	 * matches on when a row has no microchip that does; `name` is the post
	 * title; `description` is the post content; `post_status` is whether the
	 * pet is published, which an import applies only to pets it adds, so a
	 * restored backup does not publish its drafts.
	 *
	 * `description` is a COMPUTED field, so it does not arrive via
	 * editable_fields — but omitting it meant a portable export carried every
	 * pet's data and none of its story, and a backup restored through the
	 * importer came back with empty descriptions. Found by #31's round-trip
	 * test, which is exactly the sort of thing only a round trip finds.
	 *
	 * @var string[]
	 */
	private const IDENTITY = array( 'id', 'name', 'description', 'post_status' );

	/**
	 * The main photo, as a URL. Exported so a backup carries it and a restore
	 * brings it back; the pet model's own name for it.
	 */
	private const MEDIA = array( 'image' );

	/**
	 * Columns an import reads that a portable export does not write. gallery
	 * is photo URLs; a portable export carries the gallery as gallery_ids,
	 * which a same-site round trip needs and a full export adds the URLs to.
	 */
	private const IMPORT_ONLY = array( 'gallery' );

	/**
	 * @return array<string, mixed> The vcps_pet entity config.
	 */
	private static function entity(): array {
		return Config::get_path( 'entities', 'entities.vcps_pet', array() );
	}

	/**
	 * Column names for a mode, in a stable order.
	 *
	 * Stable matters: a column order that shifts between exports turns a diff
	 * of two backups into noise.
	 *
	 * @param string $mode Schema::PORTABLE or Schema::FULL.
	 * @return string[]
	 */
	public static function columns( string $mode = self::PORTABLE ): array {
		$entity = self::entity();

		// taxonomies, not taxonomy_source_map: the map is keyed on the
		// PROVIDER's field names and its values are taxonomy slugs, whereas the
		// hydrated entity keys terms by the canonical name (status, animal, …).
		// Exporting vcps_pet_status when the entity says status is the kind of thing
		// that only shows up as a column of empty cells.
		$columns = array_merge(
			self::IDENTITY,
			self::MEDIA,
			array_keys( (array) ( $entity['taxonomies'] ?? array() ) ),
			array_keys( (array) ( $entity['editable_fields'] ?? array() ) )
		);

		if ( self::FULL === $mode ) {
			$columns = array_merge(
				$columns,
				self::slug_columns(),
				array_keys( (array) ( $entity['api_fields'] ?? array() ) ),
				array_keys( (array) ( $entity['computed'] ?? array() ) )
			);
		}

		// Never exported: the raw provider snapshot and the change-detection
		// hash. The snapshot is a cache of a third party's data — normalise
		// already strips its PII, and there is no reason to widen that.
		$columns = array_diff( $columns, array( 'api_response', 'api_hash' ) );

		return array_values( array_unique( $columns ) );
	}

	/**
	 * Whether a column can be written back by an importer.
	 *
	 * @param string $column Column name.
	 */
	public static function is_importable( string $column ): bool {
		return in_array( $column, self::importable_columns(), true );
	}

	/**
	 * Every column an import reads: the portable ones, and the import-only.
	 *
	 * @return string[]
	 */
	public static function importable_columns(): array {
		return array_values( array_unique( array_merge( self::columns( self::PORTABLE ), self::IMPORT_ONLY ) ) );
	}

	/**
	 * Column name => taxonomy slug, so an importer can write terms back.
	 *
	 * @return array<string, string>
	 */
	public static function taxonomy_columns(): array {
		$map = array();
		foreach ( (array) ( self::entity()['taxonomies'] ?? array() ) as $column => $config ) {
			$map[ $column ] = $config['taxonomy'] ?? '';
		}
		return $map;
	}

	/**
	 * The values in a list cell, in order, without blanks.
	 *
	 * @param string $cell Cell contents.
	 * @return string[]
	 */
	public static function split_list( string $cell ): array {
		return array_values( array_filter( array_map( 'trim', explode( self::LIST_SEPARATOR, $cell ) ), 'strlen' ) );
	}

	/**
	 * The hydrator emits a `…Slug` twin for every taxonomy. They are derived
	 * from the term name, so a portable export omits them — a human types
	 * "Available", not "available", and re-importing both would invite the two
	 * to disagree. `full` keeps them, since a report may want to sort on them.
	 *
	 * @return string[]
	 */
	public static function slug_columns(): array {
		return array_map(
			static fn( string $c ): string => $c . 'Slug',
			array_keys( (array) ( self::entity()['taxonomies'] ?? array() ) )
		);
	}
}
