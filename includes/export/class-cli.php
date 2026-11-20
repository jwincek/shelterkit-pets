<?php
/**
 * WP-CLI export command.
 *
 * @package ShelterKit_Pets
 * @since   1.1.0
 */

declare( strict_types = 1 );

namespace Petsync\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLI {

	/**
	 * Export pets to CSV or JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : csv or json.
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - json
	 * ---
	 *
	 * [--mode=<mode>]
	 * : portable re-imports; full includes computed fields and does not.
	 * ---
	 * default: portable
	 * options:
	 *   - portable
	 *   - full
	 * ---
	 *
	 * [--status=<status>]
	 * : Only pets with this status term.
	 *
	 * [--animal=<animal>]
	 * : Only pets of this species.
	 *
	 * [--provider=<provider>]
	 * : Provider slug, or `manual` for hand-entered pets.
	 *
	 * [--include-unpublished]
	 * : Include drafts, pending and private pets. Each row keeps its
	 * post_status, so a restore does not publish them.
	 *
	 * The export goes to STDOUT. Redirect it to keep it in a file, somewhere
	 * the web server does not serve: an export includes staff-only fields,
	 * such as microchip numbers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shelterkit-pets export > pets.csv
	 *     wp shelterkit-pets export --format=json --mode=full --status=available
	 *     wp shelterkit-pets export --provider=manual > hand-entered.csv
	 *     wp shelterkit-pets export --include-unpublished > backup.csv
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function export( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'csv';
		$mode   = ( $assoc_args['mode'] ?? Schema::PORTABLE ) === Schema::FULL ? Schema::FULL : Schema::PORTABLE;

		$ids = Exporter::pet_ids(
			array_filter(
				array(
					'status'      => $assoc_args['status'] ?? null,
					'animal'      => $assoc_args['animal'] ?? null,
					'provider'    => $assoc_args['provider'] ?? null,
					'unpublished' => ! empty( $assoc_args['include-unpublished'] ),
				)
			)
		);

		if ( ! $ids ) {
			\WP_CLI::warning( 'No pets matched.' );
			return;
		}

		$output = 'json' === $format
			? Exporter::to_json( $ids, $mode )
			: Exporter::to_csv( $ids, $mode );

		// STDOUT only, so it can be piped or redirected. The plugin writes no
		// export to disk itself: where one is kept is the operator's choice.
		\WP_CLI::line( $output );
	}
}
