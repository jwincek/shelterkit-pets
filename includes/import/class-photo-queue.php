<?php
/**
 * Photos an import names by URL, downloaded after the import (#126).
 *
 * The import writes the pets and queues their photos here; WP-Cron downloads
 * them in time-boxed batches, rescheduling itself until the queue is empty.
 * Downloading during the import itself would put eighty photos into one
 * request, which outlasts most hosts' time limits. WP-CLI imports, with no
 * such limit, empty the queue on the spot.
 *
 * Three rules keep a round trip cheap and a file safe:
 *
 * - A URL that is this site's own upload is that attachment. Nothing is
 *   downloaded, so re-importing a same-site export costs nothing.
 * - A URL downloaded before is that earlier download, found by the source URL
 *   recorded on it, so re-importing a file does not duplicate its photos.
 * - The server fetches what the file names, so a URL must be a public http(s)
 *   address of an image file. The preview checks the form of each address;
 *   the download itself goes through download_url(), whose
 *   wp_safe_remote_get() refuses internal hosts after resolving them.
 *
 * Downloads use the sync's image budget: the same sizes, the same longest
 * edge, as a synced pet's photo.
 *
 * @package ShelterKit_Pets
 * @since   1.7.0
 */

declare( strict_types = 1 );

namespace Petsync\Import;

use Petsync\Core\Pet_Hydrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Photo_Queue {

	public const HOOK = 'petsync_import_photos';

	/**
	 * On a pet: the photos still to fetch, and how many attempts were made.
	 */
	public const META = '_pet_photo_queue';

	/**
	 * On a pet: photos given up on after ATTEMPTS tries, URL => reason.
	 */
	public const FAILED = '_pet_photo_failed';

	/**
	 * On an attachment: the URL it was downloaded from.
	 */
	public const SOURCE = '_pet_source_url';

	private const ATTEMPTS = 3;

	/**
	 * Image files media_sideload_image() accepts, by extension.
	 */
	private const IMAGE_PATH = '/\.(jpe?g|jpe|gif|png|webp|avif)$/i';

	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	// ─── Checking addresses ─────────────────────────────────────────────────

	/**
	 * Why a URL cannot be used for a photo, or null if it can.
	 *
	 * Checks the address's form only, with no DNS lookup, so a preview of a
	 * long sheet stays quick. A host name that resolves to an internal address
	 * is refused at download time by wp_safe_remote_get().
	 *
	 * @param string $url Cell value.
	 */
	public static function problem( string $url ): ?string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return __( 'is not a web address starting with http or https', 'shelterkit-pets' );
		}

		if ( self::local_attachment( $url ) ) {
			return null;
		}

		$host = strtolower( (string) $parts['host'] );
		$ip   = filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP );
		if ( 'localhost' === $host || ( false !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) ) {
			return __( 'is not a public address', 'shelterkit-pets' );
		}

		if ( ! preg_match( self::IMAGE_PATH, (string) ( $parts['path'] ?? '' ) ) ) {
			return __( 'is not an image file (.jpg, .png, .gif or .webp); link to the image itself, not a page showing it', 'shelterkit-pets' );
		}

		if ( ( new \WP_Http() )->block_request( $url ) ) {
			return __( 'cannot be downloaded: this site blocks outgoing requests to that host', 'shelterkit-pets' );
		}

		return null;
	}

	/**
	 * Whether a usable URL still has to be downloaded.
	 *
	 * @param string $url Photo URL.
	 */
	public static function needs_download( string $url ): bool {
		if ( '' === trim( $url ) ) {
			return false;
		}

		return ! self::local_attachment( $url ) && ! self::downloaded( $url );
	}

	/**
	 * The attachment a URL names on this site, or 0.
	 *
	 * A resized copy's URL (photo-768x512.jpg) names its attachment too.
	 *
	 * @param string $url Photo URL.
	 */
	private static function local_attachment( string $url ): int {
		$id = attachment_url_to_postid( $url );
		if ( ! $id ) {
			$full = (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
			$id   = $full === $url ? 0 : attachment_url_to_postid( $full );
		}

		return (int) $id;
	}

	/**
	 * An attachment downloaded from this URL before whose file is still here, or 0.
	 *
	 * @param string $url Photo URL.
	 */
	private static function downloaded( string $url ): int {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key lookup per photo, during an import only.
				'meta_query'  => array(
					array(
						'key'   => self::SOURCE,
						'value' => $url,
					),
				),
			)
		);

		// Only a copy whose file is still there. After a database restore
		// without uploads/, the record of an earlier download comes back with
		// no file, and reusing it gave the pet a broken photo; downloading the
		// address again is the only way to get the picture back.
		foreach ( $ids as $id ) {
			if ( ! \Petsync_Sync::attachment_file_missing( (int) $id ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	// ─── Queueing ───────────────────────────────────────────────────────────

	/**
	 * Give a pet the photos a row names, now if nothing has to be downloaded,
	 * otherwise once the queue reaches it.
	 *
	 * @param int      $post_id Pet.
	 * @param string   $image   Main photo URL, or ''.
	 * @param string[] $gallery Gallery photo URLs; empty to leave the gallery.
	 */
	public static function queue( int $post_id, string $image, array $gallery ): void {
		$job = array(
			'image'    => $image,
			'gallery'  => array_values( $gallery ),
			'attempts' => 0,
		);

		$urls = array_filter( array_merge( array( $image ), $gallery ) );
		if ( ! array_filter( $urls, array( self::class, 'needs_download' ) ) ) {
			self::work( $post_id, $job );
			return;
		}

		update_post_meta( $post_id, self::META, $job );
		self::schedule( 0 );
	}

	/**
	 * Pets with photos still to fetch.
	 */
	public static function pending(): int {
		return count( self::queued( -1 ) );
	}

	/**
	 * @param int $limit Most pets to return; -1 for all.
	 * @return int[]
	 */
	private static function queued( int $limit ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => 'vcps_pet',
					'post_status' => 'any',
					'numberposts' => $limit,
					'fields'      => 'ids',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the queue is the pets carrying this key.
				)
			)
		);
	}

	private static function schedule( int $delay ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}

	// ─── Downloading ────────────────────────────────────────────────────────

	/**
	 * The cron job: one batch, then another run if anything is left.
	 */
	public static function run(): void {
		if ( self::process( 20 ) > 0 ) {
			self::schedule( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Work through queued pets until the batch or the time runs out.
	 *
	 * @param int $seconds Time budget.
	 * @return int Pets still queued.
	 */
	public static function process( int $seconds ): int {
		$deadline = microtime( true ) + $seconds;

		/**
		 * Pets whose photos one queue run downloads.
		 *
		 * @since 2.0.0
		 *
		 * @param int $batch Pets per run.
		 */
		$batch = max( 1, (int) apply_filters( 'petsync_photo_batch_size', 5 ) );

		foreach ( self::queued( $batch ) as $post_id ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}

			$job = get_post_meta( $post_id, self::META, true );
			if ( is_array( $job ) ) {
				self::work( $post_id, $job );
			} else {
				delete_post_meta( $post_id, self::META );
			}
		}

		return self::pending();
	}

	/**
	 * Fetch one pet's photos and apply what arrived.
	 *
	 * A failure keeps the job for the next run; after ATTEMPTS it is given up,
	 * recorded under FAILED, and whatever did arrive is kept.
	 *
	 * @param int                  $post_id Pet.
	 * @param array<string, mixed> $job     image, gallery, attempts.
	 */
	private static function work( int $post_id, array $job ): void {
		$failed  = array();
		$image   = (string) ( $job['image'] ?? '' );
		$gallery = array_values( array_filter( array_map( 'strval', (array) ( $job['gallery'] ?? array() ) ), 'strlen' ) );

		if ( '' !== $image ) {
			$id = self::resolve( $image, $post_id );
			if ( is_wp_error( $id ) ) {
				$failed[ $image ] = $id->get_error_message();
			} else {
				set_post_thumbnail( $post_id, $id );
				$job['image'] = '';
			}
		}

		if ( $gallery ) {
			$ids = array();
			foreach ( $gallery as $url ) {
				$id = self::resolve( $url, $post_id );
				if ( is_wp_error( $id ) ) {
					$failed[ $url ] = $id->get_error_message();
				} else {
					$ids[] = $id;
				}
			}

			// The whole gallery or nothing, until the last attempt: a partial
			// gallery would replace a complete one with fewer photos. Photos
			// that did arrive are found again next time, not downloaded again.
			$complete = count( $ids ) === count( $gallery );
			$give_up  = ! $complete && ( (int) ( $job['attempts'] ?? 0 ) + 1 ) >= self::ATTEMPTS;
			if ( $complete || $give_up ) {
				if ( $ids ) {
					$prefix = (string) \Petsync\Core\Config::get_path( 'entities', 'entities.vcps_pet.meta_prefix', '_pet_' );
					update_post_meta( $post_id, $prefix . 'gallery_ids', $ids );
				}
				$job['gallery'] = array();
			}
		}

		Pet_Hydrator::flush_cache();

		if ( ! $failed ) {
			delete_post_meta( $post_id, self::META );
			delete_post_meta( $post_id, self::FAILED );
			return;
		}

		$job['attempts'] = (int) ( $job['attempts'] ?? 0 ) + 1;

		if ( $job['attempts'] >= self::ATTEMPTS ) {
			update_post_meta( $post_id, self::FAILED, $failed );
			delete_post_meta( $post_id, self::META );
			return;
		}

		update_post_meta( $post_id, self::META, $job );
	}

	/**
	 * The attachment for a URL: this site's own, an earlier download, or a
	 * new one.
	 *
	 * @param string $url     Photo URL.
	 * @param int    $post_id Pet the photo belongs to.
	 * @return int|\WP_Error
	 */
	private static function resolve( string $url, int $post_id ): int|\WP_Error {
		$id = self::local_attachment( $url ) ?: self::downloaded( $url );
		if ( $id ) {
			return $id;
		}

		$problem = self::problem( $url );
		if ( null !== $problem ) {
			return new \WP_Error( 'petsync_photo_url', $url . ' ' . $problem );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = \Petsync_Sync::sideload_within_budget( $url, $post_id, (string) get_post_field( 'post_title', $post_id, 'raw' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( (int) $id, self::SOURCE, $url );

		return (int) $id;
	}
}
