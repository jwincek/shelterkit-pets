<?php
/**
 * Pets → Import.
 *
 * Upload, preview, then commit. The preview is not decoration: it is the only
 * point at which a shelter can notice that "Good with dogs?" landed on the
 * wrong field, so it shows how every column will be read and lets that be
 * changed. It is produced by the same code path as the write, and the confirm
 * button imports the very file it previewed.
 *
 * @package ShelterKit_Pets
 * @since   1.1.0
 */

declare( strict_types = 1 );

namespace Petsync\Import;

use Petsync\Export\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	private const PAGE_SLUG = 'shelterkit-pets-import';

	/**
	 * Core gates Tools → Import with this, and this is the same job. It also
	 * keeps the capability aligned with Export, which uses `export` for the
	 * mirror-image reason.
	 */
	private const CAPABILITY = 'import';

	private const NONCE = 'shelterkit_import';

	/**
	 * Rows with nothing to report shown in the preview, besides every row
	 * that has an error or a note. Enough to judge the import by.
	 */
	private const PREVIEW_ROWS = 25;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			'edit.php?post_type=vcps_pet',
			__( 'Import Pets', 'shelterkit-pets' ),
			__( 'Import', 'shelterkit-pets' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Read the uploaded CSV, or return '' when there is nothing to read.
	 *
	 * Validates with wp_check_filetype_and_ext() rather than trusting the
	 * extension, and reads from the tmp_name rather than moving the file into
	 * uploads — an import has no reason to leave a copy of the shelter's
	 * spreadsheet, containing every animal's details, sitting in a
	 * web-reachable directory.
	 */
	private function uploaded_csv(): string|\WP_Error {
		// Verified here as well as in the caller. Not belt-and-braces for its
		// own sake: it means this method cannot be reached without a valid
		// nonce however it is called later, and it is what lets the sniff see
		// what is actually true rather than being told to look away.
		check_admin_referer( self::NONCE );

		if ( empty( $_FILES['petsync_csv']['tmp_name'] ) ) {
			return '';
		}

		$file = array_map( 'sanitize_text_field', wp_unslash( (array) $_FILES['petsync_csv'] ) );

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'petsync_import_upload', __( 'The file did not upload correctly. It may be larger than this server allows.', 'shelterkit-pets' ) );
		}

		$tmp = isset( $_FILES['petsync_csv']['tmp_name'] )
			? sanitize_text_field( wp_unslash( $_FILES['petsync_csv']['tmp_name'] ) )
			: '';

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new \WP_Error( 'petsync_import_upload', __( 'The upload could not be verified.', 'shelterkit-pets' ) );
		}

		$checked = wp_check_filetype_and_ext( $tmp, (string) ( $file['name'] ?? '' ), array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== ( $checked['ext'] ?? '' ) ) {
			return new \WP_Error( 'petsync_import_type', __( 'That file is not a CSV. Export your spreadsheet as CSV and try again.', 'shelterkit-pets' ) );
		}

		$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- the verified upload tmp path.

		return false === $contents
			? new \WP_Error( 'petsync_import_read', __( 'The uploaded file could not be read.', 'shelterkit-pets' ) )
			: $contents;
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import pets.', 'shelterkit-pets' ) );
		}

		$state = $this->handle_request();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Pets', 'shelterkit-pets' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Upload a spreadsheet saved as CSV. Nothing is written until you review the preview and confirm.', 'shelterkit-pets' ); ?>
				<?php esc_html_e( 'Imported pets are treated as entered by hand, so a later sync from a connected platform will not touch them.', 'shelterkit-pets' ); ?>
			</p>

			<?php if ( $state['error'] ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $state['error']->get_error_message() ); ?></p></div>
			<?php endif; ?>

			<?php
			if ( '' !== $state['token'] ) {
				$this->render_preview( $state );
			} else {
				$this->render_report( $state['report'] );
				$this->render_upload_form();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Act on the request, if it is one of the page's forms.
	 *
	 * Three requests: preview a new upload, preview a kept file again with the
	 * person's column choices, or import a kept file. Only the last one writes,
	 * and it can only be reached from a preview, which is what holds the token.
	 *
	 * @return array{report: array<string, mixed>|null, error: \WP_Error|null, token: string, on_match: string, headers: string[], examples: string[], mapped: array<string, array<int, string>>}
	 */
	private function handle_request(): array {
		$state = array(
			'report'   => null,
			'error'    => null,
			'token'    => '',
			'on_match' => Importer::ON_MATCH_UPDATE,
			'headers'  => array(),
			'examples' => array(),
			'mapped'   => array(),
		);

		$upload  = ! empty( $_POST['petsync_import_preview'] );
		$refresh = ! empty( $_POST['petsync_import_refresh'] );
		$commit  = ! empty( $_POST['petsync_import_commit'] );

		if ( ! $upload && ! $refresh && ! $commit ) {
			return $state;
		}

		check_admin_referer( self::NONCE );

		$on_match = sanitize_key( wp_unslash( $_POST['petsync_on_match'] ?? '' ) );
		if ( in_array( $on_match, array( Importer::ON_MATCH_UPDATE, Importer::ON_MATCH_SKIP, Importer::ON_MATCH_DUPLICATE ), true ) ) {
			$state['on_match'] = $on_match;
		}

		$user    = get_current_user_id();
		$mapping = null;

		if ( $upload ) {
			$csv = $this->uploaded_csv();

			if ( is_wp_error( $csv ) || '' === $csv ) {
				$state['error'] = is_wp_error( $csv ) ? $csv : new \WP_Error( 'petsync_import_none', __( 'Choose a CSV file first.', 'shelterkit-pets' ) );
				return $state;
			}

			$token = Pending_Upload::store( $user, $csv );
			if ( null === $token ) {
				$state['error'] = new \WP_Error( 'petsync_import_keep', __( 'This file is too large to hold for a preview. Import it with WP-CLI instead: wp shelterkit-pets import <file>', 'shelterkit-pets' ) );
				return $state;
			}
		} else {
			$token = sanitize_text_field( wp_unslash( $_POST['petsync_import_token'] ?? '' ) );
			$csv   = Pending_Upload::get( $user, $token );

			if ( null === $csv ) {
				$state['error'] = new \WP_Error( 'petsync_import_expired', __( 'This preview has expired. Choose the file again.', 'shelterkit-pets' ) );
				return $state;
			}

			$mapping = array_map(
				static fn( $column ): string => sanitize_key( (string) $column ),
				(array) wp_unslash( $_POST['petsync_mapping'] ?? array() ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised by the map; Importer accepts only importable column names.
			);
		}

		// The column table is drawn from the file, not the report, so it still
		// appears when the report is refused for want of a name column: that
		// is exactly when someone needs to choose one.
		$parsed = Importer::parse( $csv );
		if ( ! is_wp_error( $parsed ) ) {
			$state['headers']  = $parsed['headers'];
			$state['examples'] = $parsed['rows'][0] ?? array();
			$state['mapped']   = null === $mapping
				? Column_Mapper::map( $parsed['headers'] )
				: Column_Mapper::from_choices( $parsed['headers'], $mapping );
		}

		$options = array(
			'commit'   => $commit,
			'on_match' => $state['on_match'],
		);
		if ( null !== $mapping ) {
			$options['mapping'] = $mapping;
		}

		$report = Importer::run( $csv, $options );

		if ( is_wp_error( $report ) ) {
			$state['error'] = $report;
		} else {
			$state['report'] = $report;
		}

		if ( $commit && ! is_wp_error( $report ) ) {
			Pending_Upload::forget( $user, $token );
		} elseif ( ! is_wp_error( $parsed ) ) {
			$state['token'] = $token;
		}

		return $state;
	}

	private function render_upload_form(): void {
		?>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( self::NONCE ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="petsync_csv"><?php esc_html_e( 'CSV file', 'shelterkit-pets' ); ?></label></th>
					<td><input type="file" name="petsync_csv" id="petsync_csv" accept=".csv,text/csv" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="petsync_on_match"><?php esc_html_e( 'If a pet already exists', 'shelterkit-pets' ); ?></label></th>
					<td><?php $this->render_on_match( Importer::ON_MATCH_UPDATE ); ?></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="petsync_import_preview" value="1" class="button button-primary">
					<?php esc_html_e( 'Preview import', 'shelterkit-pets' ); ?>
				</button>
			</p>
		</form>

		<h2><?php esc_html_e( 'Columns you can include', 'shelterkit-pets' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Headers are matched loosely, so "Good with dogs?" finds ok_with_dogs. Only the name column is required. Separate several breeds or colours with |.', 'shelterkit-pets' ); ?>
		</p>
		<p><code><?php echo esc_html( implode( ', ', Schema::columns( Schema::PORTABLE ) ) ); ?></code></p>
		<?php
	}

	/**
	 * @param string $selected The option to select.
	 */
	private function render_on_match( string $selected ): void {
		$options = array(
			Importer::ON_MATCH_UPDATE    => __( 'Update it', 'shelterkit-pets' ),
			Importer::ON_MATCH_SKIP      => __( 'Leave it alone', 'shelterkit-pets' ),
			Importer::ON_MATCH_DUPLICATE => __( 'Add another', 'shelterkit-pets' ),
		);
		?>
		<select name="petsync_on_match" id="petsync_on_match">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Pets are matched on microchip number, or else on the id column of a file exported from this site. Other rows are added.', 'shelterkit-pets' ); ?></p>
		<p class="description"><?php esc_html_e( 'A pet synced from a connected platform is never changed by an import. Edit it on the platform instead.', 'shelterkit-pets' ); ?></p>
		<?php
	}

	/**
	 * The preview: the report, how each column will be read, and the two ways
	 * on, which act on the file kept for this preview.
	 *
	 * @param array<string, mixed> $state From handle_request().
	 */
	private function render_preview( array $state ): void {
		$report  = $state['report'];
		$mapping = (array) ( $state['mapped']['mapping'] ?? array() );
		$count   = $report ? (int) $report['created'] + (int) $report['updated'] : 0;

		$this->render_report( $report );
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="petsync_import_token" value="<?php echo esc_attr( $state['token'] ); ?>">

			<h2><?php esc_html_e( 'How each column will be read', 'shelterkit-pets' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Check the example from your first row against the field it will fill. Change a field and update the preview before importing.', 'shelterkit-pets' ); ?></p>

			<table class="widefat striped petsync-import-columns">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Column in your file', 'shelterkit-pets' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Imports as', 'shelterkit-pets' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Example', 'shelterkit-pets' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $state['headers'] as $index => $header ) : ?>
					<?php $field_id = 'petsync_mapping_' . (int) $index; ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( '' === trim( $header ) ? __( '(no heading)', 'shelterkit-pets' ) : $header ); ?></label></th>
						<td>
							<select name="petsync_mapping[<?php echo (int) $index; ?>]" id="<?php echo esc_attr( $field_id ); ?>">
								<option value=""><?php esc_html_e( '— Ignore this column —', 'shelterkit-pets' ); ?></option>
								<?php foreach ( Column_Mapper::available_columns() as $column ) : ?>
									<option value="<?php echo esc_attr( $column ); ?>" <?php selected( $mapping[ $index ] ?? '', $column ); ?>><?php echo esc_html( $column ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><?php echo esc_html( wp_html_excerpt( (string) ( $state['examples'][ $index ] ?? '' ), 60, '…' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="petsync_on_match"><?php esc_html_e( 'If a pet already exists', 'shelterkit-pets' ); ?></label></th>
					<td><?php $this->render_on_match( $state['on_match'] ); ?></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="petsync_import_commit" value="1" class="button button-primary" <?php disabled( 0, $count ); ?>>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of pets that will be added or updated. */
							_n( 'Import %d pet', 'Import %d pets', $count, 'shelterkit-pets' ),
							$count
						)
					);
					?>
				</button>
				<button type="submit" name="petsync_import_refresh" value="1" class="button">
					<?php esc_html_e( 'Update preview', 'shelterkit-pets' ); ?>
				</button>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=vcps_pet&page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Start over', 'shelterkit-pets' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * @param array<string, mixed>|null $report Result of Importer::run().
	 */
	private function render_report( ?array $report ): void {
		if ( null === $report ) {
			return;
		}

		$committed = ! empty( $report['committed'] );
		?>
		<div class="notice <?php echo $report['failed'] ? 'notice-warning' : 'notice-success'; ?>">
			<p>
				<strong>
					<?php
					echo esc_html(
						$committed
							? __( 'Import complete.', 'shelterkit-pets' )
							: __( 'Preview only — nothing has been written yet.', 'shelterkit-pets' )
					);
					?>
				</strong>
				<?php
				printf(
					esc_html(
						$committed
							/* translators: 1: created count, 2: updated count, 3: skipped count, 4: failed count. */
							? __( '%1$d added, %2$d updated, %3$d skipped, %4$d with errors.', 'shelterkit-pets' )
							/* translators: 1: created count, 2: updated count, 3: skipped count, 4: failed count. */
							: __( '%1$d to add, %2$d to update, %3$d skipped, %4$d with errors.', 'shelterkit-pets' )
					),
					(int) $report['created'],
					(int) $report['updated'],
					(int) $report['skipped'],
					(int) $report['failed']
				);
				?>
				<?php if ( $committed ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=vcps_pet' ) ); ?>"><?php esc_html_e( 'View pets', 'shelterkit-pets' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( 'UTF-8' !== ( $report['encoding'] ?? 'UTF-8' ) ) : ?>
				<p><?php esc_html_e( 'This file was saved in Excel\'s older "CSV" format (Windows-1252) and was converted. If accented letters look wrong in the examples, save it as "CSV UTF-8" and upload it again.', 'shelterkit-pets' ); ?></p>
			<?php endif; ?>
			<?php if ( ';' === ( $report['delimiter'] ?? ',' ) ) : ?>
				<p><?php esc_html_e( 'This file separates its columns with semicolons, and was read that way.', 'shelterkit-pets' ); ?></p>
			<?php endif; ?>
			<?php foreach ( (array) $report['unmapped'] as $header ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: the spreadsheet column heading. */
						esc_html__( 'Column "%s" is not imported.', 'shelterkit-pets' ),
						esc_html( (string) $header )
					);
					?>
				</p>
			<?php endforeach; ?>
			<?php foreach ( (array) $report['duplicates'] as $header ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: the spreadsheet column heading. */
						esc_html__( 'Column "%s" fills a field an earlier column already fills, so it is not imported.', 'shelterkit-pets' ),
						esc_html( (string) $header )
					);
					?>
				</p>
			<?php endforeach; ?>
			<?php foreach ( (array) ( $report['new_terms'] ?? array() ) as $column => $terms ) : ?>
				<p>
					<?php
					$taxonomy = get_taxonomy( Schema::taxonomy_columns()[ $column ] ?? '' );
					$label    = $taxonomy ? $taxonomy->labels->name : $column;
					printf(
						esc_html(
							$committed
								/* translators: 1: a taxonomy's plural name, such as Breeds, 2: comma-separated new terms. */
								? __( 'New %1$s added: %2$s', 'shelterkit-pets' )
								/* translators: 1: a taxonomy's plural name, such as Breeds, 2: comma-separated new terms. */
								: __( 'New %1$s this file will add: %2$s', 'shelterkit-pets' )
						),
						esc_html( $label ),
						esc_html( implode( ', ', (array) $terms ) )
					);
					?>
				</p>
			<?php endforeach; ?>
		</div>

		<?php
		$rows  = (array) $report['rows'];
		$shown = $this->rows_to_show( $rows );
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Row', 'shelterkit-pets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'shelterkit-pets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'shelterkit-pets' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Notes', 'shelterkit-pets' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $shown as $row ) : ?>
				<?php $edit = ! empty( $row['id'] ) && current_user_can( 'edit_post', (int) $row['id'] ) ? get_edit_post_link( (int) $row['id'] ) : ''; ?>
				<tr>
					<td><?php echo (int) $row['line']; ?></td>
					<td>
						<?php if ( $edit ) : ?>
							<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( (string) $row['name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) $row['name'] ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) $row['action'] ); ?></td>
					<td><?php echo esc_html( implode( ' ', array_merge( (array) $row['errors'], (array) ( $row['notes'] ?? array() ) ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$extra = count( $rows ) - count( $shown );
		if ( $extra > 0 ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of rows not shown. */
						_n( '%d further row with nothing to report is not shown.', '%d further rows with nothing to report are not shown.', $extra, 'shelterkit-pets' ),
						$extra
					)
				)
			);
		}
	}

	/**
	 * Every row with an error or a note, and enough of the rest to judge by.
	 *
	 * Only the first 25 rows were shown, so an error on row 40 of an
	 * 80-row sheet was never seen.
	 *
	 * @param array<int, array<string, mixed>> $rows Report rows, in file order.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_to_show( array $rows ): array {
		$plain = 0;

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( &$plain ): bool {
					if ( ! empty( $row['errors'] ) || ! empty( $row['notes'] ) ) {
						return true;
					}
					return ++$plain <= self::PREVIEW_ROWS;
				}
			)
		);
	}
}
