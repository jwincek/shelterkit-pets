<?php
/**
 * CSV import.
 *
 * The third way a pet can enter the site, after the provider sync and the block
 * editor. It exists for the shelter with no management platform at all, which
 * is the audience that most needs this plugin and currently has to type eighty
 * animals in one at a time.
 *
 * Two rules shape everything here:
 *
 * 1. **Imported pets are manual pets.** `_pet_provider` stays empty. Setting it
 *    to a real provider would make the next sync treat every imported pet as a
 *    record that vanished upstream and draft the lot — remove_stale_pets() is
 *    provider-scoped. It is the most damaging mistake this feature could make,
 *    so nothing here writes that meta and a test proves the survival path.
 * 2. **Dry run and commit are the same code path.** A preview produced by
 *    different code from the write is a preview of something else. `run()` takes
 *    a $commit flag and branches only where it must actually persist.
 *
 * @package ShelterKit_Pets
 * @since   1.1.0
 */

declare( strict_types = 1 );

namespace Petsync\Import;

use Petsync\Core\Config;
use Petsync\Core\Pet_Hydrator;
use Petsync\Core\Provider_Map;
use Petsync\Export\Description;
use Petsync\Export\Exporter;
use Petsync\Export\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Importer {

	/**
	 * What to do when an incoming row matches an existing pet.
	 */
	public const ON_MATCH_UPDATE    = 'update';
	public const ON_MATCH_SKIP      = 'skip';
	public const ON_MATCH_DUPLICATE = 'duplicate';

	/**
	 * Rows above this are refused outright rather than half-imported. A shelter
	 * CSV is hundreds of animals; a hundred thousand rows is a wrong file.
	 */
	private const MAX_ROWS = 5000;

	/**
	 * Taxonomy columns an import may only fill with terms the site already has.
	 */
	private const CLOSED_TAXONOMY_COLUMNS = array( 'status' );

	/**
	 * The post statuses a post_status cell may give a new pet. Not trash, and
	 * not future, which needs a date the sheet does not carry.
	 */
	private const POST_STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Columns that are the post itself rather than meta or terms.
	 */
	private const POST_COLUMNS = array( 'id', 'name', 'description', 'post_status', 'image', 'gallery' );

	/**
	 * @return array<string, mixed> The vcps_pet entity config.
	 */
	private static function entity(): array {
		return Config::get_path( 'entities', 'entities.vcps_pet', array() );
	}

	private static function prefix(): string {
		return (string) ( self::entity()['meta_prefix'] ?? '_pet_' );
	}

	/**
	 * Parse CSV text into a header row and data rows.
	 *
	 * @param string $csv Raw file contents.
	 * @return array{headers: string[], rows: array<int, string[]>, encoding: string, delimiter: string}|\WP_Error
	 */
	public static function parse( string $csv ): array|\WP_Error {
		if ( '' === trim( $csv ) ) {
			return new \WP_Error( 'petsync_import_empty', __( 'The file is empty.', 'shelterkit-pets' ) );
		}

		// Excel's plain "CSV (Comma delimited)" on Windows writes Windows-1252,
		// not UTF-8. Read as UTF-8, every accented letter and every curly
		// apostrophe Excel typed for the shelter is an invalid byte, and the
		// row failed with "Could not insert post into the database".
		$encoding = 'UTF-8';
		if ( 1 !== preg_match( '//u', $csv ) ) {
			$csv      = self::from_windows_1252( $csv );
			$encoding = 'Windows-1252';
		}

		$delimiter = self::delimiter( $csv );

		// php://temp rather than a real file: the upload has already been read
		// into memory by the caller, and fgetcsv is the only correct way to
		// parse CSV — a split on commas breaks on any quoted field containing
		// one, which for this data means every multi-word description.
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory stream, not the filesystem.
		if ( false === $handle ) {
			return new \WP_Error( 'petsync_import_stream', __( 'Could not open a temporary stream to read the file.', 'shelterkit-pets' ) );
		}

		fwrite( $handle, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- in-memory stream.
		rewind( $handle );

		$headers = fgetcsv( $handle, 0, $delimiter, '"', '' );
		if ( ! is_array( $headers ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.
			return new \WP_Error( 'petsync_import_no_header', __( 'The file has no header row.', 'shelterkit-pets' ) );
		}

		$rows = array();
		while ( ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the idiomatic fgetcsv loop.
			// fgetcsv yields array( null ) for a blank line. Trailing blank
			// lines are normal in a spreadsheet export and are not errors.
			if ( array( null ) === $row || array( '' ) === $row ) {
				continue;
			}
			$rows[] = array_map( static fn( $v ): string => (string) ( $v ?? '' ), $row );

			if ( count( $rows ) > self::MAX_ROWS ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.
				return new \WP_Error(
					'petsync_import_too_large',
					sprintf(
						/* translators: %s: maximum number of rows. */
						__( 'This file has more than %s rows, which is larger than an import should be. Split it, or check it is the file you meant.', 'shelterkit-pets' ),
						number_format_i18n( self::MAX_ROWS )
					)
				);
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.

		return array(
			'headers'   => array_map( 'strval', $headers ),
			'rows'      => $rows,
			'encoding'  => $encoding,
			'delimiter' => $delimiter,
		);
	}

	/**
	 * The column separator, judged from the header row.
	 *
	 * Where the decimal separator is a comma, as in much of Europe, Excel
	 * separates columns with semicolons. Such a file read as commas is one
	 * column, and was refused as having no name. Only the header decides,
	 * outside quotes, so semicolons in a comma file's cells change nothing.
	 *
	 * @param string $csv File contents, as UTF-8.
	 */
	private static function delimiter( string $csv ): string {
		$header = strtok( $csv, "\n" );
		$bare   = (string) preg_replace( '/"[^"]*"/', '', (string) $header );

		return substr_count( $bare, ';' ) > substr_count( $bare, ',' ) ? ';' : ',';
	}

	/**
	 * Windows-1252 bytes as UTF-8.
	 *
	 * A table rather than mb_convert_encoding(), which WordPress does not
	 * require. 0xA0 to 0xFF are the same code points in Unicode; 0x80 to 0x9F
	 * are Windows-1252's own, where Excel keeps curly quotes, dashes and the
	 * euro sign. The five bytes it leaves undefined become U+FFFD.
	 *
	 * @param string $bytes File contents.
	 */
	private static function from_windows_1252( string $bytes ): string {
		static $map = null;

		if ( null === $map ) {
			$own = array(
				0x80 => 0x20AC,
				0x82 => 0x201A,
				0x83 => 0x0192,
				0x84 => 0x201E,
				0x85 => 0x2026,
				0x86 => 0x2020,
				0x87 => 0x2021,
				0x88 => 0x02C6,
				0x89 => 0x2030,
				0x8A => 0x0160,
				0x8B => 0x2039,
				0x8C => 0x0152,
				0x8E => 0x017D,
				0x91 => 0x2018,
				0x92 => 0x2019,
				0x93 => 0x201C,
				0x94 => 0x201D,
				0x95 => 0x2022,
				0x96 => 0x2013,
				0x97 => 0x2014,
				0x98 => 0x02DC,
				0x99 => 0x2122,
				0x9A => 0x0161,
				0x9B => 0x203A,
				0x9C => 0x0153,
				0x9E => 0x017E,
				0x9F => 0x0178,
			);

			$map = array();
			for ( $byte = 0x80; $byte <= 0xFF; $byte++ ) {
				$code                = $byte >= 0xA0 ? $byte : ( $own[ $byte ] ?? 0xFFFD );
				$map[ chr( $byte ) ] = html_entity_decode( '&#' . $code . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return strtr( $bytes, $map );
	}

	/**
	 * Turn one raw row into canonical column => value.
	 *
	 * @param string[]           $row     Raw cells.
	 * @param array<int, string> $mapping Column index => canonical column.
	 * @return array<string, string>
	 */
	private static function associate( array $row, array $mapping ): array {
		$out = array();
		foreach ( $mapping as $index => $column ) {
			// Undo the export's formula protection, or it becomes part of the value.
			$out[ $column ] = Exporter::unescape_csv_field( trim( (string) ( $row[ $index ] ?? '' ) ) );
		}
		return $out;
	}

	/**
	 * The storage type a column declares, such as 'attachment_ids'.
	 *
	 * @param string $column Canonical column.
	 */
	private static function field_type( string $column ): string {
		$entity = self::entity();

		return (string) ( $entity['fields'][ $column ]['type'] ?? $entity['api_fields'][ $column ]['type'] ?? 'string' );
	}

	/**
	 * Validate one associated row.
	 *
	 * Returns per-row messages rather than throwing, because one bad row in a
	 * sheet of eighty must not cost the other seventy-nine.
	 *
	 * @param array<string, string> $data Canonical column => value.
	 * @return string[] Error messages; empty means valid.
	 */
	private static function validate( array $data ): array {
		$errors = array();

		if ( '' === trim( $data['name'] ?? '' ) ) {
			$errors[] = __( 'Name is required.', 'shelterkit-pets' );
		}

		$api_fields = (array) ( self::entity()['api_fields'] ?? array() );

		foreach ( $data as $column => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$type = $api_fields[ $column ]['type'] ?? 'string';

			// A tristate accepts a wide range of spellings by design, but not
			// anything: 'maybe' resolving to 'unknown' would silently record
			// "asked, inconclusive" for a cell the shelter meant as a note.
			if ( 'tristate' === $type && ! self::is_recognised_tristate( $value ) ) {
				$errors[] = sprintf(
					/* translators: 1: column name, 2: the cell's value. */
					__( '%1$s: "%2$s" is not a yes/no value. Use yes, no, unknown, or leave it blank.', 'shelterkit-pets' ),
					$column,
					$value
				);
			}

			// A status the site does not have would be created, and a pet whose
			// status is not 'available' is left off every adoptable listing.
			// So "Adoptable" for "Available" is refused rather than quietly
			// hiding the pet. Breeds and colours stay open: a shelter's breeds
			// are not our list.
			$taxonomy = Schema::taxonomy_columns()[ $column ] ?? '';
			if ( in_array( $column, self::CLOSED_TAXONOMY_COLUMNS, true ) && taxonomy_exists( $taxonomy ) ) {
				foreach ( Schema::split_list( $value ) as $term ) {
					if ( ! term_exists( $term, $taxonomy ) ) {
						$known    = get_terms(
							array(
								'taxonomy'   => $taxonomy,
								'hide_empty' => false,
								'fields'     => 'names',
							)
						);
						$errors[] = sprintf(
							/* translators: 1: column name, 2: the cell's value, 3: comma-separated list of allowed values. */
							__( '%1$s: "%2$s" is not one of this site\'s values. Use one of: %3$s.', 'shelterkit-pets' ),
							$column,
							$term,
							implode( ', ', is_array( $known ) ? $known : array() )
						);
					}
				}
			}

			if ( 'post_status' === $column && ! in_array( strtolower( $value ), self::POST_STATUSES, true ) ) {
				$errors[] = sprintf(
					/* translators: 1: the cell's value, 2: comma-separated allowed values. */
					__( 'post_status: "%1$s" is not one of: %2$s.', 'shelterkit-pets' ),
					$value,
					implode( ', ', self::POST_STATUSES )
				);
			}

			// numerical_age is deliberately not checked for a number, despite its
			// name. It is shown as written, and Petstablished writes ages like
			// "6 years, 1 months". A check refused every pet in a synced
			// shelter's export.

			// A gallery ID from another site's file names some unrelated
			// attachment here, or nothing. Neither belongs on a pet's page.
			if ( 'attachment_ids' === self::field_type( $column ) ) {
				$bad = array_filter(
					Schema::split_list( $value ),
					static fn( string $id ): bool => ! ctype_digit( $id ) || ! wp_attachment_is_image( (int) $id )
				);

				if ( $bad ) {
					$errors[] = sprintf(
						/* translators: 1: column name, 2: comma-separated attachment IDs. */
						_n(
							'%1$s: %2$s is not an image in this site\'s Media Library.',
							'%1$s: %2$s are not images in this site\'s Media Library.',
							count( $bad ),
							'shelterkit-pets'
						),
						$column,
						implode( ', ', $bad )
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * Whether a cell is a spelling resolve_tristate() genuinely understands.
	 *
	 * resolve_tristate() maps ANY unrecognised non-empty string to 'unknown',
	 * which is right for a provider feed — it is a normaliser, not a validator —
	 * and wrong for a human-authored spreadsheet, where an unrecognised value
	 * usually means a typo or a wrongly-mapped column.
	 *
	 * @param string $value Cell value.
	 */
	private static function is_recognised_tristate( string $value ): bool {
		$known = array( 'yes', 'y', 'true', '1', 'no', 'n', 'false', '0', 'unknown', 'unsure', 'not sure', 'ask', '' );

		return in_array( strtolower( trim( $value ) ), $known, true );
	}

	/**
	 * Normalise a validated cell to what gets stored.
	 *
	 * @param string $column Canonical column.
	 * @param string $value  Raw cell.
	 * @return string|int[]
	 */
	private static function normalise_value( string $column, string $value ): string|array {
		// The export writes a gallery as 11|12. Stored as that string, the
		// field's sanitiser, which expects a list, emptied the gallery.
		if ( 'attachment_ids' === self::field_type( $column ) ) {
			return array_map( 'intval', Schema::split_list( $value ) );
		}

		$type = self::entity()['api_fields'][ $column ]['type'] ?? 'string';

		if ( 'tristate' === $type ) {
			// The hydrator's own resolver, deliberately. Reimplementing it here
			// would let an imported 'Y' and a synced 'Y' drift apart.
			$lower = strtolower( trim( $value ) );
			if ( in_array( $lower, array( 'y', 'n' ), true ) ) {
				$value = ( 'y' === $lower ) ? 'yes' : 'no';
			}
			if ( in_array( $lower, array( 'unsure', 'not sure', 'ask' ), true ) ) {
				$value = 'unknown';
			}

			return Pet_Hydrator::resolve_tristate( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Find an existing pet this row refers to.
	 *
	 * The id column of a file this site exported names the exact pet a row
	 * came from, so it wins, as long as the pet's microchip does not say it is
	 * a different animal. Without it, a pet with no microchip was added again
	 * every time its file was re-imported. A file from another site still
	 * matches pets here by id, which is why the caller notes a name mismatch.
	 *
	 * Otherwise the microchip, the only field genuinely unique per animal.
	 * Name is not: shelters have several Lunas. But a microchip is not unique
	 * per post: a re-listed animal can have an old draft copy, as 50 animals
	 * at VCHS do. So every pet with the number is found, a listed one is
	 * preferred, then the most recently changed, and the count is reported.
	 *
	 * @param array<string, string> $data    Canonical column => value.
	 * @param int[]                 $created Pets this run created, which a
	 *                                       microchip never matches.
	 * @return array{id: int, by: string, also: int[]} by is 'id', 'microchip'
	 *         or ''; also lists other pets with the same microchip.
	 */
	private static function find_existing( array $data, array $created = array() ): array {
		$chip = trim( $data['microchip_id'] ?? '' );

		$id   = trim( $data['id'] ?? '' );
		$post = ctype_digit( $id ) ? get_post( (int) $id ) : null;

		if ( $post && 'vcps_pet' === $post->post_type && 'trash' !== $post->post_status ) {
			$their_chip = self::chip_of( $post->ID );

			if ( '' === $chip || '' === $their_chip || $chip === $their_chip ) {
				return array(
					'id'   => $post->ID,
					'by'   => 'id',
					'also' => array(),
				);
			}
		}

		$found = '' === $chip ? array() : array_values( array_diff( self::pets_with_chip( $chip ), $created ) );

		return array(
			'id'   => (int) ( $found[0] ?? 0 ),
			'by'   => $found ? 'microchip' : '',
			'also' => array_slice( $found, 1 ),
		);
	}

	/**
	 * A pet's microchip number, wherever it is stored.
	 *
	 * In meta when it was entered or imported, in the provider snapshot when
	 * it was synced. Read directly rather than hydrated, because the hydrator
	 * only serves published pets and a match can be a draft.
	 *
	 * @param int $id Post ID.
	 */
	private static function chip_of( int $id ): string {
		$meta = trim( (string) get_post_meta( $id, self::prefix() . 'microchip_id', true ) );
		if ( '' !== $meta ) {
			return $meta;
		}

		$provider = (string) get_post_meta( $id, self::prefix() . 'provider', true );
		$key      = '' === $provider ? null : Provider_Map::key_for( $provider, 'microchip_id' );
		if ( null === $key ) {
			return '';
		}

		$snapshot = json_decode( (string) get_post_meta( $id, self::prefix() . 'api_response', true ), true );

		return is_array( $snapshot ) ? trim( (string) ( $snapshot[ $key ] ?? '' ) ) : '';
	}

	/**
	 * Every pet with this microchip, best match first.
	 *
	 * The snapshot is searched too: a synced pet's number lives only there,
	 * so a shelter's own sheet never matched a synced pet, and added a copy
	 * where it should have said the pet is managed on its platform. A text
	 * search of a JSON snapshot can match a stale value, so each candidate is
	 * confirmed against chip_of().
	 *
	 * @param string $chip Microchip number.
	 * @return int[] Listed pets first, then the most recently modified.
	 */
	private static function pets_with_chip( string $chip ): array {
		$clauses = array(
			'relation' => 'OR',
			array(
				'key'   => self::prefix() . 'microchip_id',
				'value' => $chip,
			),
		);

		$key = Provider_Map::key_for( \Petsync_Sync::PROVIDER, 'microchip_id' );
		if ( null !== $key ) {
			$clauses[] = array(
				'key'     => self::prefix() . 'api_response',
				'value'   => wp_json_encode( $key ) . ':' . wp_json_encode( $chip ),
				'compare' => 'LIKE',
			);
		}

		$ids = get_posts(
			array(
				'post_type'        => 'vcps_pet',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- matching on the one unique field is the point.
				'meta_query'       => $clauses,
			)
		);

		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => self::chip_of( $id ) === $chip ) );

		// Listed first; usort keeps the modified order within each group.
		usort( $ids, static fn( int $a, int $b ): int => (int) ( 'publish' !== get_post_status( $a ) ) <=> (int) ( 'publish' !== get_post_status( $b ) ) );

		return $ids;
	}

	/**
	 * A pet as a person would recognise it in a note: #123 "Luna".
	 *
	 * @param int $id Post ID.
	 */
	private static function describe( int $id ): string {
		return sprintf( '#%d "%s"', $id, get_post_field( 'post_title', $id, 'raw' ) );
	}

	/**
	 * Parse, validate, and optionally write.
	 *
	 * @param string               $csv     Raw file contents.
	 * @param array<string, mixed> $options mapping, on_match, commit.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $csv, array $options = array() ): array|\WP_Error {
		$parsed = self::parse( $csv );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$commit   = ! empty( $options['commit'] );
		$on_match = (string) ( $options['on_match'] ?? self::ON_MATCH_UPDATE );

		// A person's choices from the preview's dropdowns win; otherwise the
		// headers are matched automatically. Both report in the same shape.
		$mapped     = isset( $options['mapping'] ) && is_array( $options['mapping'] )
			? Column_Mapper::from_choices( $parsed['headers'], $options['mapping'] )
			: Column_Mapper::map( $parsed['headers'] );
		$mapping    = $mapped['mapping'];
		$taxonomies = Schema::taxonomy_columns();

		if ( ! in_array( 'name', $mapping, true ) ) {
			return new \WP_Error(
				'petsync_import_no_name',
				__( 'No column could be matched to the pet\'s name, which is required. Check the header row.', 'shelterkit-pets' )
			);
		}

		$report = array(
			// How the file was read, which the preview reports when it is not
			// the plain case: a legacy encoding, or semicolons.
			'encoding'   => $parsed['encoding'],
			'delimiter'  => $parsed['delimiter'],
			'headers'    => $parsed['headers'],
			// The first row's cells, so the preview can show what each column
			// holds next to the field it is about to fill.
			'examples'   => $parsed['rows'][0] ?? array(),
			'mapping'    => $mapping,
			'unmapped'   => $mapped['unmapped'],
			'duplicates' => $mapped['duplicates'],
			// Taxonomy column => terms this file would add to the site. A typo
			// in a breed becomes a new breed, so the preview lists them.
			'new_terms'  => array(),
			// Photos this file would download, after the import.
			'photos'     => 0,
			'committed'  => $commit,
			'created'    => 0,
			'updated'    => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'rows'       => array(),
		);

		// A record's key => the row it first appeared on. See below.
		$seen = array();

		// Pets this run has created. A later row is never matched to one by
		// microchip: in a backup, two records sharing a microchip are two
		// records, and the second must not overwrite the first.
		$created = array();

		foreach ( $parsed['rows'] as $offset => $raw ) {
			// +2: one for the header row, one because humans count from 1. A
			// row number that does not match what the shelter sees in Excel is
			// worse than no row number.
			$line = $offset + 2;

			// A row with the wrong number of cells shifts every value after the
			// gap into the next field. Nothing downstream can detect that: the
			// values are individually plausible, just attached to the wrong
			// columns. Caught here only because it was noticed catching itself
			// by luck — a stray colour landed in a yes/no field and failed
			// validation, where a stray 'yes' would have imported silently.
			$errors = array();
			if ( count( $raw ) !== count( $parsed['headers'] ) ) {
				$errors[] = sprintf(
					/* translators: 1: number of values found, 2: number of columns expected. */
					__( 'This row has %1$d values but the header has %2$d columns, so its values do not line up. Check for a missing or extra comma.', 'shelterkit-pets' ),
					count( $raw ),
					count( $parsed['headers'] )
				);
			}

			$data   = self::associate( $raw, $mapping );
			$errors = array_merge( $errors, self::validate( $data ) );

			// The same record twice in one file. Checked here, in the code both
			// the preview and the import run, because only the import could see
			// it otherwise: the preview found no match for either row and said
			// "2 to add", then the import added the first and overwrote it with
			// the second. A record is its exported id when it has one, since a
			// backup can hold two records for one microchip, such as a
			// re-listed animal's old draft (50 at VCHS); otherwise its microchip.
			$id   = trim( $data['id'] ?? '' );
			$chip = trim( $data['microchip_id'] ?? '' );
			$key  = '' !== $id ? 'id:' . $id : ( '' !== $chip ? 'chip:' . $chip : '' );
			if ( '' !== $key && isset( $seen[ $key ] ) ) {
				$errors[] = sprintf(
					'' !== $id
						/* translators: %d: row number. */
						? __( 'Same id as row %d. Each pet can only appear once in a file.', 'shelterkit-pets' )
						/* translators: %d: row number. */
						: __( 'Same microchip number as row %d. Each animal can only appear once in a file.', 'shelterkit-pets' ),
					$seen[ $key ]
				);
			} elseif ( '' !== $key ) {
				$seen[ $key ] = $line;
			}

			if ( $errors ) {
				++$report['failed'];
				$report['rows'][] = self::report_row( $line, $data['name'] ?? '', 'error', 0, $errors );
				continue;
			}

			foreach ( $taxonomies as $column => $taxonomy ) {
				foreach ( Schema::split_list( $data[ $column ] ?? '' ) as $term ) {
					if ( taxonomy_exists( $taxonomy ) && ! term_exists( $term, $taxonomy ) && ! in_array( $term, $report['new_terms'][ $column ] ?? array(), true ) ) {
						$report['new_terms'][ $column ][] = $term;
					}
				}
			}

			$match    = self::find_existing( $data, $created );
			$existing = $match['id'];
			$action   = 'create';
			$notes    = array();

			if ( $match['also'] ) {
				$notes[] = sprintf(
					/* translators: 1: a pet, as #123 "Luna", 2: other pets' IDs. */
					__( 'Other pets have this microchip number too (%2$s). This row matches %1$s, the listed or most recently changed one.', 'shelterkit-pets' ),
					self::describe( $existing ),
					implode( ', ', array_map( static fn( int $id ): string => '#' . $id, $match['also'] ) )
				);
			}

			if ( $existing ) {
				$provider = (string) get_post_meta( $existing, self::prefix() . 'provider', true );

				// A synced pet is rewritten from its platform on the next sync,
				// so an update here would be undone without a trace. Adding a
				// separate hand-entered copy is still allowed, if asked for.
				$synced = '' !== $provider && self::ON_MATCH_DUPLICATE !== $on_match;
				if ( $synced ) {
					$notes[] = sprintf(
						/* translators: 1: a pet, as #123 "Luna", 2: platform name, such as Petstablished. */
						__( 'Matches %1$s, which is synced from %2$s. Change it there: the next sync would undo an import.', 'shelterkit-pets' ),
						self::describe( $existing ),
						(string) ( Provider_Map::get( $provider )['label'] ?? $provider )
					);
				}

				if ( $synced || self::ON_MATCH_SKIP === $on_match ) {
					++$report['skipped'];
					$report['rows'][] = self::report_row( $line, $data['name'], 'skip', $existing, array(), $notes );
					continue;
				}

				if ( self::ON_MATCH_UPDATE === $on_match ) {
					$action = 'update';

					$wanted = strtolower( trim( $data['post_status'] ?? '' ) );
					$actual = (string) get_post_status( $existing );
					if ( '' !== $wanted && $wanted !== $actual ) {
						$notes[] = sprintf(
							/* translators: 1: the pet's post status, such as draft, 2: the status in the file. */
							__( 'Stays %1$s, not %2$s: an import sets the post status of pets it adds, never of a pet already here. Change it in the editor.', 'shelterkit-pets' ),
							$actual,
							$wanted
						);
					}

					$current = (string) get_post_field( 'post_title', $existing, 'raw' );
					if ( 'id' === $match['by'] && 0 !== strcasecmp( trim( $current ), trim( $data['name'] ) ) ) {
						$notes[] = sprintf(
							/* translators: 1: a pet, as #123 "Luna", 2: the name in the file. */
							__( 'Matched by id to %1$s, and renames it "%2$s". If this file came from another site, its ids belong to different pets here.', 'shelterkit-pets' ),
							self::describe( $existing ),
							$data['name']
						);
					}
				}
			}

			// Counted before writing, so the preview and the import agree. A
			// photo that cannot come is a note, not an error: the pet still
			// imports, since a pet without its photo beats no pet.
			$photos            = self::photos( $data );
			$notes             = array_merge( $notes, $photos['notes'] );
			$report['photos'] += count( array_filter( array_filter( array_merge( array( $photos['image'] ), $photos['gallery'] ), 'strlen' ), array( Photo_Queue::class, 'needs_download' ) ) );

			$post_id = $commit ? self::write( $data, 'update' === $action ? $existing : 0, $taxonomies ) : 0;

			if ( $commit && is_wp_error( $post_id ) ) {
				++$report['failed'];
				$report['rows'][] = self::report_row( $line, $data['name'], 'error', 0, array( $post_id->get_error_message() ) );
				continue;
			}

			if ( 'update' === $action ) {
				++$report['updated'];
			} else {
				++$report['created'];
				if ( $commit ) {
					$created[] = (int) $post_id;
				}
			}

			// A preview has no new post yet, but an update already knows its pet.
			$id = $commit ? (int) $post_id : ( 'update' === $action ? $existing : 0 );

			$report['rows'][] = self::report_row( $line, $data['name'], $action, $id, array(), $notes );
		}

		return $report;
	}

	/**
	 * The photos a row names that can be used, and a note for each that cannot.
	 *
	 * The server fetches what the file names, so each must be a public address
	 * of an image; see Photo_Queue::problem().
	 *
	 * @param array<string, string> $data Canonical column => value.
	 * @return array{image: string, gallery: string[], notes: string[]}
	 */
	private static function photos( array $data ): array {
		$out = array(
			'image'   => '',
			'gallery' => array(),
			'notes'   => array(),
		);

		$images = Schema::split_list( $data['image'] ?? '' );
		if ( count( $images ) > 1 ) {
			$out['notes'][] = __( 'image: one photo only, so it was skipped. Put the others in a gallery column, separated by |.', 'shelterkit-pets' );
			$images         = array();
		}

		$candidates = array(
			'image'   => $images,
			'gallery' => Schema::split_list( $data['gallery'] ?? '' ),
		);

		foreach ( $candidates as $column => $urls ) {
			foreach ( $urls as $url ) {
				$problem = Photo_Queue::problem( $url );
				if ( null !== $problem ) {
					/* translators: 1: column name, 2: a URL, 3: what is wrong with it. */
					$out['notes'][] = sprintf( __( '%1$s: %2$s %3$s, so it was skipped.', 'shelterkit-pets' ), $column, $url, $problem );
				} elseif ( 'image' === $column ) {
					$out['image'] = $url;
				} else {
					$out['gallery'][] = $url;
				}
			}
		}

		return $out;
	}

	/**
	 * One row of the report.
	 *
	 * @param int      $line   Row number as the spreadsheet shows it.
	 * @param string   $name   The row's name cell.
	 * @param string   $action create, update, skip, or error.
	 * @param int      $id     The pet created, updated or matched, or 0.
	 * @param string[] $errors Why the row was refused.
	 * @param string[] $notes  What a person should know about a row that was not.
	 * @return array<string, mixed>
	 */
	private static function report_row( int $line, string $name, string $action, int $id, array $errors, array $notes = array() ): array {
		return array(
			'line'   => $line,
			'name'   => $name,
			'action' => $action,
			'id'     => $id,
			'errors' => $errors,
			'notes'  => $notes,
		);
	}

	/**
	 * Write one row.
	 *
	 * @param array<string, string> $data       Canonical column => value.
	 * @param int                   $existing   Post to update, or 0 to create.
	 * @param array<string, string> $taxonomies Column => taxonomy slug.
	 * @return int|\WP_Error
	 */
	private static function write( array $data, int $existing, array $taxonomies ): int|\WP_Error {
		$postarr = array(
			'post_type'  => 'vcps_pet',
			'post_title' => sanitize_text_field( $data['name'] ),
		);

		// Only when the sheet has the column, as with every other field. A
		// three-column sheet that updated compatibility used to empty every
		// matched pet's description.
		if ( array_key_exists( 'description', $data ) ) {
			$postarr['post_content'] = Description::from_cell( $data['description'] );
		}

		// wp_insert_post() unslashes what it is given, so unslashed input lost
		// every backslash, including the escapes in block attributes' JSON.
		if ( $existing ) {
			// Status is left as it was, whatever the sheet says: updating a
			// draft must not publish it, and an export edited for a week must
			// not put back up a pet unlisted in the meantime.
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			// A restored backup brings each pet back as it was; a sheet without
			// the column publishes, as an import always has.
			$wanted                 = strtolower( trim( $data['post_status'] ?? '' ) );
			$postarr['post_status'] = in_array( $wanted, self::POST_STATUSES, true ) ? $wanted : 'publish';
			$post_id                = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;
		$prefix  = self::prefix();

		// _pet_provider is deliberately NOT written. An imported pet is a manual
		// pet; giving it a provider would make the next sync for that provider
		// find it absent from the feed and draft it. See the class docblock.
		// A gallery given as URLs replaces one given as IDs; the queue sets the
		// IDs once the photos are here.
		$photos       = self::photos( $data );
		$gallery_urls = $photos['gallery'];

		foreach ( $data as $column => $value ) {
			if ( in_array( $column, self::POST_COLUMNS, true ) || isset( $taxonomies[ $column ] ) ) {
				continue;
			}
			if ( 'gallery_ids' === $column && $gallery_urls ) {
				continue;
			}
			if ( ! Schema::is_importable( $column ) ) {
				continue;
			}

			// Slashed for the same reason: update_post_meta() unslashes too.
			update_post_meta( $post_id, $prefix . $column, wp_slash( self::normalise_value( $column, $value ) ) );
		}

		foreach ( $taxonomies as $column => $taxonomy ) {
			// Every term the cell lists: a mixed breed has two, most pets
			// several colours.
			$terms = Schema::split_list( $data[ $column ] ?? '' );
			if ( ! $terms || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			// Terms arrive as names a person typed. wp_set_object_terms creates
			// what does not exist, which is what a shelter starting from a
			// spreadsheet needs — their breeds are not our list.
			wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', $terms ), $taxonomy );
		}

		// Attribute terms are derived from the hydrated entity, so they have to
		// be recomputed after the meta above lands. The editor path gets this
		// from wp_after_insert_post; an import writes meta afterwards, so it
		// must ask explicitly or imported pets get no vcps_pet_attribute terms and
		// silently vanish from compatibility filtering.
		Pet_Hydrator::flush_cache();
		\Petsync\Core\CPT_Registry::sync_attribute_terms( $post_id );

		// Photos last: what this site already has is applied now, and the rest
		// is fetched after the import. A blank cell leaves a pet's photos alone.
		if ( '' !== $photos['image'] || $gallery_urls ) {
			Photo_Queue::queue( $post_id, $photos['image'], $gallery_urls );
		}

		return $post_id;
	}
}
