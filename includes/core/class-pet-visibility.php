<?php
/**
 * Which pets the site shows, and what staff are told about the rest.
 *
 * A pet has two statuses that answer different questions. WordPress's post
 * status says whether its page exists for visitors; the vcps_pet_status term
 * says where it is in adoption, and the listings filter on it. They drifted
 * apart: the sync published every pet in the feed unless Petstablished
 * flagged it hidden, and the feed is not filtered by status, so an adopted,
 * returned or euthanized pet got a public page offering an adoption
 * application. The status names themselves were public, through the REST API.
 *
 * The rules, all here:
 *
 * - A synced pet is published only if its status is one the site shows.
 *   Available, Adoption Pending and On Hold by default; a shelter can choose
 *   others on the Sync Settings screen. Pets entered by hand are left to
 *   staff, who publish them or not themselves.
 * - Only an available pet offers the adoption application.
 * - Visitors cannot list the statuses the site does not show. Staff can.
 * - A synced draft says why it is one in the pets list, and since when.
 *
 * @package ShelterKit_Pets
 * @since   1.7.0
 */

declare( strict_types = 1 );

namespace Petsync\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Pet_Visibility {

	/**
	 * Why the sync made a pet a draft. Stored with the time it did so.
	 */
	public const META = '_pet_unlisted';

	/**
	 * When the site first published a pet, as a Unix time. Never cleared, so it
	 * means the pet was shown at some point, whatever its status now.
	 *
	 * @since 2.2.0
	 */
	public const LISTED_META = '_pet_first_listed';

	public const HIDDEN_UPSTREAM    = 'hidden';
	public const STATUS_NOT_SHOWN   = 'status';
	public const LEFT_FEED          = 'left_feed';
	public const NOT_SEEN           = 'not_seen';
	public const SPECIES_NOT_SYNCED = 'species';

	/**
	 * Shown unless a shelter chooses otherwise.
	 */
	public const DEFAULT_PUBLIC_STATUSES = array( 'available', 'pending', 'hold' );

	public static function init(): void {
		$taxonomy = self::taxonomy();

		add_filter( "rest_{$taxonomy}_query", array( self::class, 'hide_from_rest_listing' ) );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'refuse_hidden_term' ), 10, 3 );
		add_filter( 'display_post_states', array( self::class, 'post_states' ), 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'remember_listed' ), 10, 3 );
	}

	/**
	 * Record the first time a pet is published, however it happens: the sync,
	 * an import, the editor, or a scheduled pet going live.
	 *
	 * @since 2.2.0
	 *
	 * @param string        $new_status New post status.
	 * @param string        $old_status Old post status.
	 * @param \WP_Post|null $post       Post.
	 */
	public static function remember_listed( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || ! $post instanceof \WP_Post || 'vcps_pet' !== $post->post_type ) {
			return;
		}

		add_post_meta( $post->ID, self::LISTED_META, time(), true );
	}

	/**
	 * Whether the site has ever shown a pet: it is published now, or was.
	 *
	 * @since 2.2.0
	 *
	 * @param \WP_Post $post Pet.
	 */
	public static function was_listed( \WP_Post $post ): bool {
		return 'publish' === $post->post_status || '' !== get_post_meta( $post->ID, self::LISTED_META, true );
	}

	public static function animal_taxonomy(): string {
		$entity = (array) Config::get_path( 'entities', 'entities.vcps_pet', array() );

		return (string) ( $entity['taxonomies']['animal']['taxonomy'] ?? 'vcps_pet_animal' );
	}

	public static function taxonomy(): string {
		$entity = (array) Config::get_path( 'entities', 'entities.vcps_pet', array() );

		return (string) ( $entity['taxonomies']['status']['taxonomy'] ?? 'vcps_pet_status' );
	}

	/**
	 * The status a pet must have to take adoption applications: the one a pet
	 * saved without a status gets.
	 */
	public static function adoptable_status(): string {
		$config = (array) Config::get_item( 'taxonomies', 'taxonomies', array() );

		return (string) ( $config[ self::taxonomy() ]['default_term'] ?? 'available' );
	}

	/**
	 * Status slugs the site shows. The adoptable status always is.
	 *
	 * @return string[]
	 */
	public static function public_statuses(): array {
		$chosen = \Petsync_Admin::get_settings()['public_statuses'] ?? self::DEFAULT_PUBLIC_STATUSES;

		return array_values( array_unique( array_merge( array( self::adoptable_status() ), array_map( 'strval', (array) $chosen ) ) ) );
	}

	/**
	 * Status slugs that mean a pet was adopted.
	 *
	 * The one outcome the site tells anyone about, for a pet it no longer
	 * shows: the check-adoptions ability says whether a pet has one of these,
	 * so a site that featured the pet can say it found a home.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public static function adopted_statuses(): array {
		/**
		 * Filters the statuses that count as adopted.
		 *
		 * Anyone may ask whether a pet has one of these, including a pet the
		 * site no longer shows, but only of a pet the site has shown at some
		 * point. Nothing else about an unlisted pet is shared. Return an empty
		 * array to share nothing.
		 *
		 * @since 2.1.0
		 *
		 * @param string[] $statuses Status slugs. Default `adopted`.
		 */
		$statuses = apply_filters( 'petsync_adopted_statuses', array( 'adopted' ) );

		return array_values( array_filter( array_map( 'strval', (array) $statuses ), static fn( string $slug ): bool => '' !== $slug ) );
	}

	/**
	 * A status's slug, from its name as a provider sends it or from a slug.
	 *
	 * Petstablished sends "Adoption Pending"; the term is `pending`. A status
	 * the site has never seen has no term yet, and gets the slug its term
	 * will have.
	 *
	 * @param string $status Status name or slug.
	 */
	public static function slug_for( string $status ): string {
		$term = get_term_by( 'name', $status, self::taxonomy() );
		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $status ), self::taxonomy() );
		}

		return $term ? (string) $term->slug : sanitize_title( $status );
	}

	/**
	 * @param string $status Status name or slug; empty is the adoptable status.
	 */
	public static function is_shown( string $status ): bool {
		return '' === $status || in_array( self::slug_for( $status ), self::public_statuses(), true );
	}

	/**
	 * Whether a hydrated pet takes adoption applications. A pet with no status
	 * at all predates the default status and is treated as available.
	 *
	 * @param array<string, mixed> $pet Hydrated pet.
	 */
	public static function takes_applications( array $pet ): bool {
		$slug = (string) ( $pet['statusSlug'] ?? '' );

		return '' === $slug || self::adoptable_status() === $slug;
	}

	// ─── Why the sync drafted a pet ─────────────────────────────────────────

	/**
	 * Record why the sync left a pet a draft, or that it did not.
	 *
	 * The time is kept from the first time a reason applied, so "since" means
	 * since the pet left the site, not since the last sync noticed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $reason  One of the reason constants, or '' when published.
	 */
	public static function record( int $post_id, string $reason ): void {
		$current = get_post_meta( $post_id, self::META, true );

		if ( '' === $reason ) {
			if ( '' !== $current ) {
				delete_post_meta( $post_id, self::META );
			}
			return;
		}

		if ( is_array( $current ) && ( $current['reason'] ?? '' ) === $reason ) {
			return;
		}

		update_post_meta(
			$post_id,
			self::META,
			array(
				'reason' => $reason,
				'since'  => time(),
			)
		);
	}

	/**
	 * Tell staff, beside a synced draft's title, why it is a draft.
	 *
	 * Drafts from before this was recorded get the best answer the data
	 * allows: their status if the site does not show it, otherwise that the
	 * provider no longer lists them.
	 *
	 * @param array<string, string> $states Post states.
	 * @param \WP_Post|null         $post   Post.
	 * @return array<string, string>
	 */
	public static function post_states( $states, $post ) {
		if ( ! $post instanceof \WP_Post || 'vcps_pet' !== $post->post_type || 'draft' !== $post->post_status ) {
			return $states;
		}

		$provider = (string) get_post_meta( $post->ID, '_pet_provider', true );
		if ( '' === $provider ) {
			return $states;
		}

		$label  = (string) ( Provider_Map::get( $provider )['label'] ?? ucfirst( $provider ) );
		$record = get_post_meta( $post->ID, self::META, true );
		$reason = is_array( $record ) ? (string) ( $record['reason'] ?? '' ) : '';
		$since  = is_array( $record ) && ! empty( $record['since'] ) ? wp_date( (string) get_option( 'date_format' ), (int) $record['since'] ) : '';

		$terms  = wp_get_object_terms( $post->ID, self::taxonomy(), array( 'fields' => 'names' ) );
		$status = is_array( $terms ) && $terms ? (string) $terms[0] : '';

		if ( '' === $reason && '' !== $status && ! self::is_shown( $status ) ) {
			$reason = self::STATUS_NOT_SHOWN;
		}

		if ( '' === $reason ) {
			$seen      = (int) get_post_meta( $post->ID, '_pet_last_seen', true );
			$last_sync = (int) get_option( 'petsync_last_sync', 0 );
			$reason    = $seen && $last_sync && $seen >= $last_sync ? 'listed' : self::LEFT_FEED;
		}

		$states['petsync_unlisted'] = match ( $reason ) {
			/* translators: %s: platform name, such as Petstablished. */
			self::HIDDEN_UPSTREAM  => sprintf( __( 'Hidden on %s', 'shelterkit-pets' ), $label ),
			/* translators: %s: a pet status, such as Adopted. */
			self::STATUS_NOT_SHOWN => sprintf( __( '%s: not shown on this site', 'shelterkit-pets' ), $status ),
			self::SPECIES_NOT_SYNCED => sprintf(
				/* translators: %s: a species, such as Rabbit. */
				__( '%s: not a species this site syncs', 'shelterkit-pets' ),
				self::first_term_name( $post->ID, self::animal_taxonomy() )
			),
			/* translators: %s: platform name, such as Petstablished. */
			'listed'               => sprintf( __( 'Listed on %s: the next sync will publish it', 'shelterkit-pets' ), $label ),
			self::NOT_SEEN         => '' !== $since
				/* translators: 1: platform name, such as Petstablished, 2: a date. */
				? sprintf( __( 'Not seen on %1$s since %2$s', 'shelterkit-pets' ), $label, $since )
				/* translators: %s: platform name, such as Petstablished. */
				: sprintf( __( 'Not seen on %s lately', 'shelterkit-pets' ), $label ),
			default                => '' !== $since
				/* translators: 1: platform name, such as Petstablished, 2: a date. */
				? sprintf( __( 'No longer listed on %1$s since %2$s', 'shelterkit-pets' ), $label, $since )
				/* translators: %s: platform name, such as Petstablished. */
				: sprintf( __( 'No longer listed on %s', 'shelterkit-pets' ), $label ),
		};

		return $states;
	}

	/**
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 */
	private static function first_term_name( int $post_id, string $taxonomy ): string {
		$names = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );

		return is_array( $names ) && $names ? (string) $names[0] : '';
	}

	// ─── The REST API ───────────────────────────────────────────────────────

	/**
	 * Status terms the site does not show.
	 *
	 * @return int[]
	 */
	private static function hidden_term_ids(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::taxonomy(),
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$public = self::public_statuses();

		return array_values(
			array_map(
				static fn( \WP_Term $t ): int => (int) $t->term_id,
				array_filter( $terms, static fn( \WP_Term $t ): bool => ! in_array( $t->slug, $public, true ) )
			)
		);
	}

	/**
	 * Leave the statuses the site does not show out of a visitor's listing.
	 *
	 * Staff keep them: the editor's status panel reads this same route.
	 *
	 * @param array<string, mixed> $args get_terms() arguments.
	 * @return array<string, mixed>
	 */
	public static function hide_from_rest_listing( $args ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return $args;
		}

		// A handful of terms in one small taxonomy; excluding them is the point.
		$args['exclude'] = array_merge( (array) ( $args['exclude'] ?? array() ), self::hidden_term_ids() ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude

		return $args;
	}

	/**
	 * Answer a visitor's request for one hidden status as if it did not exist.
	 *
	 * @param mixed            $response Response so far; null to carry on.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function refuse_hidden_term( $response, $handler, $request ) {
		if ( null !== $response || ! $request instanceof \WP_REST_Request || current_user_can( 'edit_posts' ) ) {
			return $response;
		}

		$taxonomy = get_taxonomy( self::taxonomy() );
		$base     = $taxonomy ? ( $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name ) : '';

		if ( '' === $base || ! preg_match( '#^/wp/v2/' . preg_quote( $base, '#' ) . '/(\d+)$#', $request->get_route(), $m ) ) {
			return $response;
		}

		if ( in_array( (int) $m[1], self::hidden_term_ids(), true ) ) {
			return new \WP_Error( 'rest_term_invalid', __( 'Term does not exist.', 'shelterkit-pets' ), array( 'status' => 404 ) );
		}

		return $response;
	}
}
