<?php
/**
 * Load the demo shelter into a WordPress site, for screenshots.
 *
 *   wp eval-file bin/demo-site.php            photos, Shelter Details and a CSV
 *   wp eval-file bin/demo-site.php import     the same, then import the pets
 *
 * The data lives in assets-src/demo: sixteen fictional animals, CC0 photos
 * (credits in CREDITS.md) and a fictional shelter with a 555-01xx phone number
 * and a .example domain.
 *
 * The photos go into the Media Library first, and the CSV written out names
 * them by their URLs on this site. The import then uses them where they are,
 * as it does any photo already in the Media Library, so nothing is downloaded
 * and the import preview screenshot shows a real import of a real file. Run
 * without `import` to stop before that, so the preview can be captured first.
 *
 * Refuses to run on a site that has pets, or Shelter Details naming another
 * shelter: demo animals must never end up on a real shelter's site. It is
 * meant for a fresh, throwaway install.
 *
 * Not shipped: .distignore excludes bin/ and assets-src/.
 *
 * @package ShelterKit_Pets
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- a CLI script reading its own data files.

( static function ( array $args ): void {
	$demo   = dirname( __DIR__ ) . '/assets-src/demo';
	$import = in_array( 'import', $args, true );

	if ( ! post_type_exists( 'vcps_pet' ) || ! class_exists( 'ShelterKit_Profile' ) ) {
		WP_CLI::error( 'ShelterKit Pets must be active on this site.' );
	}

	$shelter = json_decode( (string) file_get_contents( $demo . '/shelter.json' ), true );

	// ── Guard: a fresh site only ────────────────────────────────────────────
	$existing = get_posts(
		array(
			'post_type'   => 'vcps_pet',
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	if ( $existing ) {
		WP_CLI::error( 'This site already has pets. The demo shelter loads only into a fresh site, so it can never mix with a real shelter\'s animals.' );
	}

	$current_name = ShelterKit_Profile::get( 'name' );
	if ( '' !== $current_name && $current_name !== $shelter['name'] ) {
		WP_CLI::error( "Shelter Details already name \"{$current_name}\". Use a fresh site." );
	}

	// ── Photos into the Media Library ──────────────────────────────────────
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$urls = array();
	foreach ( glob( $demo . '/photos/*.jpg' ) ?: array() as $path ) {
		$file = basename( $path );

		// Reuse a photo an earlier run added, so running twice does not
		// double the Media Library.
		$found = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_skp_demo_photo', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $file, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$id = (int) ( $found[0] ?? 0 );
		if ( ! $id ) {
			$tmp = wp_tempnam( $file );
			copy( $path, $tmp );
			$id = media_handle_sideload(
				array(
					'name'     => $file,
					'tmp_name' => $tmp,
				),
				0,
				ucwords( str_replace( array( '-', '.jpg' ), array( ' ', '' ), $file ) )
			);
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( "$file: " . $id->get_error_message() );
			}
			update_post_meta( $id, '_skp_demo_photo', $file );
		}

		$urls[ $file ] = (string) wp_get_attachment_url( $id );
	}
	WP_CLI::log( sprintf( 'Photos: %d in the Media Library.', count( $urls ) ) );

	// ── Shelter Details ────────────────────────────────────────────────────
	ShelterKit_Profile::save( $shelter );
	WP_CLI::log( 'Shelter Details: ' . $shelter['name'] . '.' );

	// ── The CSV, with photo file names replaced by their URLs here ─────────
	$in      = fopen( $demo . '/pets.csv', 'r' );
	$headers = fgetcsv( $in, 0, ',', '"', '' );
	$rows    = array();
	while ( false !== ( $row = fgetcsv( $in, 0, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		$rows[] = array_combine( $headers, $row );
	}
	fclose( $in );

	$resolve = static function ( string $cell ) use ( $urls ): string {
		$out = array();
		foreach ( array_filter( explode( '|', $cell ) ) as $file ) {
			if ( ! isset( $urls[ $file ] ) ) {
				WP_CLI::error( "pets.csv names a photo that is not in assets-src/demo/photos: $file" );
			}
			$out[] = $urls[ $file ];
		}
		return implode( '|', $out );
	};

	$csv_path = get_temp_dir() . 'shelterkit-demo-pets.csv';
	$out      = fopen( $csv_path, 'w' );
	fputcsv( $out, $headers, ',', '"', '' );
	foreach ( $rows as $row ) {
		$row['image']   = $resolve( $row['image'] );
		$row['gallery'] = $resolve( $row['gallery'] );
		fputcsv( $out, array_values( $row ), ',', '"', '' );
	}
	fclose( $out );
	WP_CLI::log( sprintf( 'CSV for this site: %s (%d pets).', $csv_path, count( $rows ) ) );

	if ( ! $import ) {
		WP_CLI::success( 'Ready. Import that CSV under Pets → Import, or run again with `import`.' );
		return;
	}

	// ── Import, through the importer the Import screen uses ────────────────
	$report = \Petsync\Import\Importer::run( (string) file_get_contents( $csv_path ), array( 'commit' => true ) );
	if ( is_wp_error( $report ) ) {
		WP_CLI::error( $report->get_error_message() );
	}

	// Listing dates, so New marks recent arrivals only and the newest-first
	// order is the one the screenshots were composed for.
	$listed = json_decode( (string) file_get_contents( $demo . '/listed.json' ), true );
	foreach ( $report['rows'] as $row ) {
		$days = $listed[ $row['name'] ?? '' ] ?? null;
		if ( null === $days || empty( $row['id'] ) ) {
			continue;
		}
		$when = time() - (int) $days * DAY_IN_SECONDS;
		wp_update_post(
			array(
				'ID'            => (int) $row['id'],
				'post_date'     => wp_date( 'Y-m-d H:i:s', $when ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
			)
		);
	}

	$counts = array_count_values( array_column( $report['rows'], 'action' ) );
	foreach ( $report['rows'] as $row ) {
		foreach ( (array) ( $row['errors'] ?? array() ) as $error ) {
			WP_CLI::warning( ( $row['name'] ?? '?' ) . ': ' . $error );
		}
	}
	WP_CLI::success( sprintf( 'Imported: %s.', wp_json_encode( $counts ) ) );
} )( $args ?? array() );
