<?php
/**
 * A synced pet whose photo file is gone gets it back on the next sync.
 *
 * Restore the database without wp-content/uploads/ and every attachment
 * record comes back, with `_pet_source_image_url` still matching the
 * provider's address, while the files do not. The sync took "has a thumbnail
 * and the address has not changed" to mean the photo was there, and an
 * unchanged pet never reached that check at all because of the hash shortcut.
 * So the pets stayed without photos for good (#59).
 *
 * The same shortcut kept a photo whose download failed once from ever being
 * tried again: the snapshot and hash are stored either way.
 *
 * A photo served from elsewhere is left alone. Media-offload plugins delete
 * the local copy on purpose and rewrite the attachment's address, and
 * downloading those again on every sync would churn the whole library.
 *
 * Found only on real data: WordPress files a sideloaded photo under the pet
 * post's date and the address's file name, so the download lands on exactly
 * the path the stale record names, which is free because its file is gone.
 * Deleting the stale record then deleted the new photo with it. 41 of 52
 * VCHS pets lost their photo again that way, and every later sync downloaded
 * them once more. Each test therefore uses a photo address of its own: a name
 * left over from another test would hide the collision.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync_Sync;
use ReflectionMethod;

final class SyncPhotoRecoveryTest extends PetTestCase {

	private string $photo = '';

	/** Which test image the stand-in serves. */
	private string $image = 'canola.jpg';

	/** @var string[] */
	private array $requests = array();

	private bool $host_down = false;

	public function set_up(): void {
		parent::set_up();
		$this->photo = 'https://photos.example.test/petsync-recovery-' . strtolower( wp_generate_password( 10, false ) ) . '.jpg';
		add_filter( 'pre_http_request', array( $this, 'serve_photo' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'serve_photo' ), 10 );
		remove_all_filters( 'wp_get_attachment_url' );
		parent::tear_down();
	}

	/**
	 * Stand in for Petstablished's photo storage, so nothing leaves the machine.
	 */
	public function serve_photo( $preempt, $args, $url ) {
		if ( ! str_starts_with( (string) $url, 'https://photos.example.test/' ) ) {
			return $preempt;
		}

		$this->requests[] = (string) $url;

		if ( $this->host_down ) {
			return new \WP_Error( 'http_request_failed', 'Connection timed out' );
		}

		$target = $args['filename'] ?? wp_tempnam( 'petsync-recovery.jpg' );
		copy( DIR_TESTDATA . '/images/' . $this->image, $target );

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array( 'content-type' => 'image/jpeg' ),
			'body'     => '',
			'cookies'  => array(),
			'filename' => $target,
		);
	}

	/**
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed> One pet as the Petstablished API sends it.
	 */
	private function record( array $changes = array() ): array {
		return array_merge(
			array(
				'id'     => 7001,
				'name'   => 'Mistletoe',
				'status' => 'Available',
				'images' => array( array( 'image' => array( 'url' => $this->photo ) ) ),
			),
			$changes
		);
	}

	private function sync( array $record ): string {
		return ( new ReflectionMethod( Petsync_Sync::class, 'process_single_pet' ) )
			->invoke( new Petsync_Sync(), $record );
	}

	private function pet(): int {
		$ids = get_posts(
			array(
				'post_type'   => 'vcps_pet',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => '_pet_ps_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture lookup.
				'meta_value'  => '7001', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture lookup.
			)
		);

		return (int) ( $ids[0] ?? 0 );
	}

	/**
	 * What a database restored without uploads/ leaves: the record, and none
	 * of its files. Every file, not just the main one: WordPress also refuses a
	 * name whose resized copies exist, so leaving those behind hid the bug.
	 */
	private function lose_the_file( int $attachment ): void {
		$file   = (string) get_attached_file( $attachment );
		$meta   = wp_get_attachment_metadata( $attachment );
		$backup = get_post_meta( $attachment, '_wp_attachment_backup_sizes', true );
		$this->assertFileExists( $file );
		wp_delete_attachment_files( $attachment, is_array( $meta ) ? $meta : array(), is_array( $backup ) ? $backup : array(), $file );
		clearstatcache();
		$this->assertFileDoesNotExist( $file );
	}

	public function test_an_unchanged_pet_whose_photo_file_is_gone_gets_it_back(): void {
		$this->sync( $this->record() );
		$old = (int) get_post_thumbnail_id( $this->pet() );
		$this->lose_the_file( $old );

		$this->assertSame( 'updated', $this->sync( $this->record() ) );

		$new = (int) get_post_thumbnail_id( $this->pet() );
		$this->assertNotSame( $old, $new );
		$this->assertFileExists( (string) get_attached_file( $new ), 'deleting the stale record must not take the new file with it' );
		$this->assertNull( get_post( $old ), 'the record with no file is cleaned up, not left as an orphan' );

		$this->assertSame( 'unchanged', $this->sync( $this->record() ), 'recovered once, then left alone' );
		$this->assertCount( 2, $this->requests );
	}

	/**
	 * A photo over the size limit is stored as name-scaled.jpg, so the stale
	 * record claims that name rather than the one the download asks for.
	 */
	public function test_a_large_photo_whose_file_is_gone_gets_it_back(): void {
		$this->image = '33772.jpg';
		$this->sync( $this->record() );
		$old = (int) get_post_thumbnail_id( $this->pet() );
		$this->assertStringContainsString( '-scaled', (string) get_attached_file( $old ), 'the fixture must exercise the scaled name' );
		$this->lose_the_file( $old );

		$this->sync( $this->record() );

		$new = (int) get_post_thumbnail_id( $this->pet() );
		$this->assertFileExists( (string) get_attached_file( $new ) );
		$this->assertSame( 'unchanged', $this->sync( $this->record() ) );
	}

	/**
	 * The check inside maybe_set_featured_image() itself, reached when the
	 * pet's other data changed.
	 */
	public function test_a_changed_pet_whose_photo_file_is_gone_gets_it_back(): void {
		$this->sync( $this->record() );
		$this->lose_the_file( (int) get_post_thumbnail_id( $this->pet() ) );

		$this->sync( $this->record( array( 'name' => 'Mistletoe Renamed' ) ) );

		$this->assertFileExists( (string) get_attached_file( (int) get_post_thumbnail_id( $this->pet() ) ) );
		$this->assertCount( 2, $this->requests );
	}

	/**
	 * Most pets on most syncs: nothing to do, and nothing downloaded.
	 */
	public function test_a_photo_that_is_there_is_not_downloaded_again(): void {
		$this->sync( $this->record() );
		$thumbnail = (int) get_post_thumbnail_id( $this->pet() );

		$this->assertSame( 'unchanged', $this->sync( $this->record() ) );
		$this->assertSame( 'updated', $this->sync( $this->record( array( 'name' => 'Renamed' ) ) ) );

		$this->assertSame( $thumbnail, (int) get_post_thumbnail_id( $this->pet() ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_photo_whose_download_failed_is_tried_again(): void {
		$this->host_down = true;
		$this->sync( $this->record() );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $this->pet() ), 'the first download failed' );

		$this->host_down = false;
		$this->assertSame( 'updated', $this->sync( $this->record() ) );

		$this->assertGreaterThan( 0, (int) get_post_thumbnail_id( $this->pet() ) );
	}

	/**
	 * A media-offload plugin deletes the local copy on purpose and serves the
	 * photo from its own storage. That photo is not missing.
	 */
	public function test_a_photo_served_from_elsewhere_is_left_alone(): void {
		$this->sync( $this->record() );
		$thumbnail = (int) get_post_thumbnail_id( $this->pet() );
		$this->lose_the_file( $thumbnail );
		add_filter(
			'wp_get_attachment_url',
			static fn( string $url, int $id ): string => $id === $thumbnail ? 'https://cdn.example.test/offloaded.jpg' : $url,
			10,
			2
		);

		$this->assertSame( 'unchanged', $this->sync( $this->record() ) );
		$this->assertSame( $thumbnail, (int) get_post_thumbnail_id( $this->pet() ) );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * A pet the provider sends without a photo has nothing to recover, and
	 * must not be counted as updated on every sync for want of one.
	 */
	public function test_a_pet_without_a_photo_stays_unchanged(): void {
		$this->sync( $this->record( array( 'images' => array() ) ) );

		$this->assertSame( 'unchanged', $this->sync( $this->record( array( 'images' => array() ) ) ) );
		$this->assertCount( 0, $this->requests );
	}
}
