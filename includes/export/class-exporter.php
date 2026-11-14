<?php
/**
 * Export pets to CSV or JSON.
 *
 * @package ShelterKit_Pets
 * @since   1.1.0
 */

declare( strict_types = 1 );

namespace Petsync\Export;

use Petsync\Core\Pet_Hydrator;
use Petsync\Core\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Exporter {

	/**
	 * Characters that make a spreadsheet treat a cell as a formula.
	 *
	 * Excel and Sheets also honour tab and carriage return as leading
	 * separators, which is why they are here alongside the obvious four.
	 *
	 * @var string[]
	 */
	private const FORMULA_TRIGGERS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Neutralise a cell that a spreadsheet would otherwise execute.
	 *
	 * Pet names, breeds, `special_needs_detail` and `bonded_names` are free text
	 * an adopter or volunteer can influence, and they land in a file whose whole
	 * purpose is to be opened in Excel. A cell beginning `=`, `+`, `-` or `@`
	 * runs as a formula, and HYPERLINK or WEBSERVICE turn that into data
	 * exfiltration against whoever opens the sheet.
	 *
	 * Genuine numbers are left alone, so adoption_fee and weight still import as
	 * numbers rather than as text that has to be cleaned up.
	 *
	 * @param mixed $value Cell value.
	 * @return string Safe to write.
	 */
	public static function esc_csv_field( $value ): string {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		}

		$value = (string) $value;

		if ( '' === $value || is_numeric( $value ) ) {
			return $value;
		}

		if ( in_array( $value[0], self::FORMULA_TRIGGERS, true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Undo esc_csv_field() on a cell read back in.
	 *
	 * Only the exact prefix it adds is removed: an apostrophe followed by a
	 * formula trigger. Without this, `+Plus` came back as `'+Plus` and stayed
	 * that way. An apostrophe a person typed in front of anything else, as in
	 * `'Twas`, is data and stays.
	 *
	 * @param string $value Cell value.
	 */
	public static function unescape_csv_field( string $value ): string {
		if ( strlen( $value ) > 1 && "'" === $value[0] && in_array( $value[1], self::FORMULA_TRIGGERS, true ) ) {
			return substr( $value, 1 );
		}

		return $value;
	}

	/**
	 * The main photo's URL at full size.
	 *
	 * The entity's image is a resized copy, which the Media Library cannot map
	 * back to its attachment, so a same-site round trip would download every
	 * photo again. A synced pet with no featured image yet falls back to the
	 * provider's URL, which another site can download.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $pet     Hydrated pet.
	 */
	private static function image_url( int $post_id, array $pet ): string {
		$thumbnail = (int) get_post_thumbnail_id( $post_id );
		if ( $thumbnail ) {
			return (string) wp_get_attachment_url( $thumbnail );
		}

		return (string) ( $pet['image'] ?? '' );
	}

	/**
	 * Every term a pet has in a taxonomy, by name or by slug.
	 *
	 * The hydrated entity carries only the first, which is what a pet card
	 * shows. An export that used it dropped a mixed breed's second breed and
	 * all but one colour, which is most pets: 45 of VCHS's 54 listed pets have
	 * more than one colour.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @param string $field    'names' or 'slugs'.
	 * @return string[]
	 */
	private static function terms( int $post_id, string $taxonomy, string $field = 'names' ): array {
		$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => $field ) );

		return is_wp_error( $terms ) ? array() : array_map( 'strval', $terms );
	}

	/**
	 * A taxonomy column's terms, or null if the column is not a taxonomy's.
	 *
	 * Covers the `…Slug` twins a full export carries as well.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return string[]|null
	 */
	private static function taxonomy_values( string $column, int $post_id ): ?array {
		$taxonomies = Schema::taxonomy_columns();

		if ( isset( $taxonomies[ $column ] ) ) {
			return self::terms( $post_id, $taxonomies[ $column ] );
		}

		$base = substr( $column, 0, -strlen( 'Slug' ) );
		if ( str_ends_with( $column, 'Slug' ) && isset( $taxonomies[ $base ] ) ) {
			return self::terms( $post_id, $taxonomies[ $base ], 'slugs' );
		}

		return null;
	}

	/**
	 * Flatten one hydrated pet to the given columns.
	 *
	 * Falls back to post meta for anything the entity does not carry.
	 * gallery_ids is the live example: it is an editable field a shelter curates
	 * by hand, but the hydrator surfaces the resolved `gallery` URLs instead and
	 * never emits the ids themselves. Reading only the entity silently drops the
	 * one column somebody actually chose.
	 *
	 * @param array<string, mixed> $pet     Hydrated entity.
	 * @param string[]             $columns Column names.
	 * @param int                  $post_id Post ID, for the meta fallback.
	 * @return array<string, string>
	 */
	public static function row( array $pet, array $columns, int $post_id = 0 ): array {
		$prefix = \Petsync\Core\Config::get_path( 'entities', 'entities.vcps_pet.meta_prefix', '_pet_' );
		$row    = array();

		foreach ( $columns as $column ) {
			if ( $post_id ) {
				$terms = self::taxonomy_values( $column, $post_id );
				if ( null !== $terms ) {
					$row[ $column ] = implode( Schema::LIST_SEPARATOR, $terms );
					continue;
				}

				if ( 'post_status' === $column ) {
					$row[ $column ] = (string) get_post_status( $post_id );
					continue;
				}

				if ( 'image' === $column ) {
					$row[ $column ] = self::image_url( $post_id, $pet );
					continue;
				}

				// Readable text, not the display HTML. See Description.
				if ( 'description' === $column ) {
					$row[ $column ] = Description::to_cell( (string) get_post_field( 'post_content', $post_id, 'raw' ) );
					continue;
				}
			}

			$value = $pet[ $column ] ?? null;

			if ( null === $value && $post_id && Schema::is_importable( $column ) ) {
				$value = get_post_meta( $post_id, $prefix . $column, true );
			}

			$value = $value ?? '';

			if ( is_array( $value ) ) {
				// gallery_ids is a list of ints; gallery is a list of maps. Only
				// the scalar list round-trips, so the other is flattened for
				// reading rather than pretending it could come back.
				$flat  = array_map(
					static fn( $v ) => is_array( $v ) ? ( $v['url'] ?? $v['name'] ?? '' ) : $v,
					$value
				);
				$value = implode( Schema::LIST_SEPARATOR, array_filter( array_map( 'strval', $flat ), 'strlen' ) );
			}

			$row[ $column ] = (string) $value;
		}

		return $row;
	}

	/**
	 * Post IDs to export.
	 *
	 * @param array<string, mixed> $filters status / animal / provider, and
	 *                                      unpublished to include drafts,
	 *                                      pending and private pets.
	 * @return int[]
	 */
	public static function pet_ids( array $filters = array() ): array {
		$query = Query::for( 'vcps_pet' );

		// Published only by default, as the export always was. With this, the
		// export is a whole backup; post_status travels with each row, so a
		// restore does not publish what was not.
		if ( ! empty( $filters['unpublished'] ) ) {
			$query->withArgs( array( 'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ) ) );
		}

		if ( ! empty( $filters['status'] ) ) {
			$query->status( (string) $filters['status'] );
		}
		if ( ! empty( $filters['animal'] ) ) {
			$query->where( 'animal', (string) $filters['animal'] );
		}

		$ids = $query->ids();

		if ( ! empty( $filters['provider'] ) ) {
			$provider = (string) $filters['provider'];
			$ids      = array_values(
				array_filter(
					$ids,
					static function ( int $id ) use ( $provider ): bool {
						$actual = (string) get_post_meta( $id, '_pet_provider', true );
						return 'manual' === $provider ? '' === $actual : $actual === $provider;
					}
				)
			);
		}

		return $ids;
	}

	/**
	 * Rows for the given pets, in column order.
	 *
	 * @param int[]  $ids  Post IDs.
	 * @param string $mode Schema mode.
	 * @return array<int, array<string, string>>
	 */
	public static function rows( array $ids, string $mode = Schema::PORTABLE ): array {
		$columns = Schema::columns( $mode );
		$rows    = array();

		foreach ( $ids as $id ) {
			$pet = Pet_Hydrator::get_any_status( (int) $id, 'full' );
			if ( $pet ) {
				$rows[] = self::row( $pet, $columns, (int) $id );
			}
		}

		return $rows;
	}

	/**
	 * CSV as a string.
	 *
	 * @param int[]  $ids  Post IDs.
	 * @param string $mode Schema mode.
	 */
	public static function to_csv( array $ids, string $mode = Schema::PORTABLE ): string {
		$columns = Schema::columns( $mode );

		// php://temp is an in-memory buffer, not a file: nothing touches the
		// filesystem and WP_Filesystem has no equivalent for building a string.
		// fputcsv is used rather than hand-rolled joining because CSV quoting —
		// embedded commas, quotes and newlines in a pet's description — is
		// exactly the thing that looks simple and is not.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( 'php://temp', 'r+' );

		// Escape passed explicitly: PHP 8.4 deprecates relying on the default,
		// and CI runs the 8.1 floor so it would not surface there.
		// Escape '' is RFC 4180: quotes are doubled, and there is no backslash
		// escape. It is what Excel and Google Sheets both write and read, it is
		// what the importer parses with, and PHP 8.5 deprecates leaving the
		// parameter to its default.
		fputcsv( $handle, $columns, ',', '"', '' );

		foreach ( self::rows( $ids, $mode ) as $row ) {
			fputcsv( $handle, array_map( array( self::class, 'esc_csv_field' ), array_values( $row ) ), ',', '"', '' );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return $csv;
	}

	/**
	 * JSON as a string. Keeps arrays as arrays, which is the reason to offer it
	 * at all — CSV flattens gallery_ids and the taxonomy sets.
	 *
	 * @param int[]  $ids  Post IDs.
	 * @param string $mode Schema mode.
	 */
	public static function to_json( array $ids, string $mode = Schema::PORTABLE ): string {
		$columns = Schema::columns( $mode );
		$prefix  = \Petsync\Core\Config::get_path( 'entities', 'entities.vcps_pet.meta_prefix', '_pet_' );
		$pets    = array();

		foreach ( $ids as $id ) {
			$pet = Pet_Hydrator::get_any_status( (int) $id, 'full' );
			if ( ! $pet ) {
				continue;
			}
			// Same meta fallback as the CSV path, so both formats carry the
			// same columns rather than JSON quietly having fewer.
			$record = array();
			foreach ( $columns as $column ) {
				// Every term, as a list: keeping lists as lists is why JSON is offered.
				$terms = self::taxonomy_values( $column, (int) $id );
				if ( null !== $terms ) {
					$record[ $column ] = $terms;
					continue;
				}

				if ( 'post_status' === $column ) {
					$record[ $column ] = (string) get_post_status( (int) $id );
					continue;
				}

				if ( 'image' === $column ) {
					$record[ $column ] = self::image_url( (int) $id, $pet );
					continue;
				}

				// The stored description, which is what can be edited and
				// imported. A full export keeps the display HTML.
				if ( 'description' === $column && Schema::PORTABLE === $mode ) {
					$record[ $column ] = (string) get_post_field( 'post_content', (int) $id, 'raw' );
					continue;
				}

				if ( array_key_exists( $column, $pet ) ) {
					$record[ $column ] = $pet[ $column ];
					continue;
				}
				if ( Schema::is_importable( $column ) ) {
					$meta              = get_post_meta( (int) $id, $prefix . $column, true );
					$record[ $column ] = '' === $meta ? null : $meta;
				}
			}
			$pets[] = $record;
		}

		return (string) wp_json_encode(
			array(
				'generator' => 'ShelterKit Pets ' . PETSYNC_VERSION,
				'exported'  => gmdate( 'c' ),
				'mode'      => $mode,
				// Whether these are the portable columns a CSV import reads. Not
				// "importable": nothing imports JSON.
				'portable'  => Schema::PORTABLE === $mode,
				'columns'   => $columns,
				'pets'      => $pets,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}
}
