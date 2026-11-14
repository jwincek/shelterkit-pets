<?php
/**
 * Photos from a spreadsheet's URL columns (#126).
 *
 * An import could not add photos at all: no column set the main photo, and
 * gallery_ids only took attachments already in the Media Library. A shelter
 * with no platform still uploaded every photo by hand after importing eighty
 * pets, and a portable export left the main photo out, so a restore brought
 * pets back without their pictures.
 *
 * The columns are named as the pet model names them, image and gallery, the
 * names a full export already writes. Downloads happen after the import, in
 * time-boxed batches, because eighty photos in one request would outlast
 * most hosts' time limits.
 *
 * Nothing leaves the machine: pre_http_request serves a test image for the
 * made-up host below, and records what was fetched.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Core\Pet_Hydrator;
use Petsync\Export\Exporter;
use Petsync\Import\Importer;
use Petsync\Import\Photo_Queue;

final class ImportPhotosTest extends PetTestCase {

	/**
	 * URLs downloaded.
	 *
	 * @var string[]
	 */
	private array $downloads = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'serve_photo' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'serve_photo' ), 10 );
		wp_clear_scheduled_hook( Photo_Queue::HOOK );
		parent::tear_down();
	}

	/**
	 * Stand in for a photo host. A path containing "missing" is a 404.
	 */
	public function serve_photo( $preempt, $args, $url ) {
		if ( ! str_starts_with( (string) $url, 'https://photos.example.test/' ) ) {
			return $preempt;
		}

		$this->downloads[] = (string) $url;

		if ( str_contains( (string) $url, 'missing' ) ) {
			return array(
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
				'headers'  => array(),
				'body'     => '',
				'cookies'  => array(),
				'filename' => null,
			);
		}

		$target = $args['filename'] ?? wp_tempnam( 'petsync-photo.jpg' );
		copy( DIR_TESTDATA . '/images/canola.jpg', $target );

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

	private function photo( string $name ): string {
		return "https://photos.example.test/{$name}.jpg";
	}

	/**
	 * A photo already in the Media Library. The default is large enough to
	 * get resized copies, as a real photo does; canola.jpg is not.
	 */
	private function local_attachment( string $file = '33772.jpg' ): int {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file );
	}

	/**
	 * @return array<string, mixed> The import report.
	 */
	private function import( string $csv, bool $commit = true ): array {
		return Importer::run( $csv, array( 'commit' => $commit ) );
	}

	public function test_a_new_pet_gets_its_photo_after_the_import(): void {
		$report = $this->import( "name,image\nClover,{$this->photo( 'clover' )}\n" );
		$pet    = $report['rows'][0]['id'];

		$this->assertSame( 1, $report['photos'] );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $pet ), 'downloads wait for the queue, not the import request' );
		$this->assertNotFalse( wp_next_scheduled( Photo_Queue::HOOK ) );

		Photo_Queue::process( 60 );

		$this->assertGreaterThan( 0, (int) get_post_thumbnail_id( $pet ) );
		$this->assertSame( array( $this->photo( 'clover' ) ), $this->downloads );
	}

	public function test_gallery_urls_become_the_gallery(): void {
		$report = $this->import( "name,gallery\nPip,\"{$this->photo( 'pip-1' )}|{$this->photo( 'pip-2' )}\"\n" );
		Photo_Queue::process( 60 );

		$gallery = get_post_meta( $report['rows'][0]['id'], $this->prefix . 'gallery_ids', true );
		$this->assertCount( 2, $gallery );
		foreach ( $gallery as $id ) {
			$this->assertTrue( wp_attachment_is_image( $id ) );
		}
	}

	public function test_the_preview_counts_photos_and_downloads_nothing(): void {
		$report = $this->import( "name,image,gallery\nClover,{$this->photo( 'a' )},{$this->photo( 'b' )}|{$this->photo( 'c' )}\n", false );

		$this->assertSame( 3, $report['photos'] );
		$this->assertSame( array(), $this->downloads );
		$this->assertFalse( wp_next_scheduled( Photo_Queue::HOOK ) );
	}

	/**
	 * A same-site export carries this site's own upload URLs. They are the
	 * attachments already here, so nothing is downloaded or queued.
	 */
	public function test_a_photo_already_in_the_media_library_is_used_as_it_is(): void {
		$attachment = $this->local_attachment();
		$url        = wp_get_attachment_url( $attachment );

		$report = $this->import( "name,image\nLocal,{$url}\n" );

		$this->assertSame( 0, $report['photos'] );
		$this->assertSame( $attachment, (int) get_post_thumbnail_id( $report['rows'][0]['id'] ) );
		$this->assertFalse( wp_next_scheduled( Photo_Queue::HOOK ) );
	}

	public function test_re_importing_the_same_url_does_not_download_it_again(): void {
		$csv = "name,microchip_id,image\nClover,PHOTO-CHIP,{$this->photo( 'clover' )}\n";

		$this->import( $csv );
		Photo_Queue::process( 60 );
		$first = (int) get_post_thumbnail_id( $this->import( $csv )['rows'][0]['id'] );
		Photo_Queue::process( 60 );

		$this->assertCount( 1, $this->downloads );
		$this->assertGreaterThan( 0, $first );
	}

	public function test_an_export_carries_the_photo_and_a_round_trip_downloads_nothing(): void {
		$attachment = $this->local_attachment();
		$pet        = $this->make_manual_pet( array( 'post_title' => 'Pictured' ) );
		update_post_meta( $pet, $this->prefix . 'microchip_id', 'PHOTO-ROUND' );
		set_post_thumbnail( $pet, $attachment );
		Pet_Hydrator::flush_cache();

		// Full size: the entity's image is a resized copy (-768x432), which a
		// lookup by URL does not recognise as this attachment.
		$this->assertSame( wp_get_attachment_url( $attachment ), Exporter::rows( array( $pet ) )[0]['image'] );

		$report = Importer::run( Exporter::to_csv( array( $pet ) ), array( 'commit' => true ) );

		$this->assertSame( 'update', $report['rows'][0]['action'] );
		$this->assertSame( $attachment, (int) get_post_thumbnail_id( $pet ) );
		$this->assertSame( array(), $this->downloads );
	}

	/**
	 * The server fetches what the file names, so the file must name a public
	 * web address, and an image. A photo that cannot come is skipped with a
	 * note: the pet still imports, since a pet without its photo beats no pet,
	 * and some reasons, such as a host that blocks outgoing requests, are not
	 * the shelter's to fix.
	 */
	public function test_unusable_or_unsafe_addresses_are_skipped_with_a_note(): void {
		$report = $this->import(
			"name,image\n" .
			"Ftp,ftp://photos.example.test/a.jpg\n" .
			"Loopback,http://127.0.0.1/a.jpg\n" .
			"Private,http://10.0.0.5/a.jpg\n" .
			"Localhost,http://localhost/a.jpg\n" .
			"Page,https://photos.example.test/gallery-page\n"
		);

		$this->assertSame( 0, $report['failed'] );
		$this->assertSame( 5, $report['created'] );
		$this->assertSame( 0, $report['photos'] );
		foreach ( $report['rows'] as $row ) {
			$this->assertStringContainsString( 'image:', implode( ' ', $row['notes'] ) );
			$this->assertSame( 0, (int) get_post_thumbnail_id( $row['id'] ) );
		}
		$this->assertFalse( wp_next_scheduled( Photo_Queue::HOOK ), 'nothing was queued' );
		$this->assertSame( array(), $this->downloads );
	}

	public function test_a_failed_download_is_retried_then_given_up_with_a_record(): void {
		$report = $this->import( "name,image\nGhost,{$this->photo( 'missing' )}\n" );
		$pet    = $report['rows'][0]['id'];

		Photo_Queue::process( 60 );
		Photo_Queue::process( 60 );
		$this->assertNotSame( '', get_post_meta( $pet, Photo_Queue::META, true ), 'still queued after two failures' );
		Photo_Queue::process( 60 );

		$this->assertSame( '', get_post_meta( $pet, Photo_Queue::META, true ) );
		$this->assertArrayHasKey( $this->photo( 'missing' ), (array) get_post_meta( $pet, Photo_Queue::FAILED, true ) );
		$this->assertCount( 3, $this->downloads );
	}

	/**
	 * Each run downloads a batch and leaves the rest for the next one.
	 */
	public function test_the_queue_runs_in_batches_until_done(): void {
		$one_at_a_time = static fn(): int => 1;
		add_filter( 'petsync_photo_batch_size', $one_at_a_time );

		$this->import( "name,image\nOne,{$this->photo( 'one' )}\nTwo,{$this->photo( 'two' )}\n" );

		Photo_Queue::run();
		$this->assertSame( 1, Photo_Queue::pending() );
		$this->assertNotFalse( wp_next_scheduled( Photo_Queue::HOOK ), 'the rest is scheduled' );

		Photo_Queue::run();
		$this->assertSame( 0, Photo_Queue::pending() );

		remove_filter( 'petsync_photo_batch_size', $one_at_a_time );
	}
}
