<?php
/**
 * What another site may learn about a pet that left the listings.
 *
 * Partner sites feature a shelter's pets through the REST API. When one of
 * those pets stops being listed, the partner used to assume it was adopted and
 * say "found a home!". Since 2.0.0 the site also stops listing pets that were
 * returned, euthanized or transferred, so that guess could celebrate an animal
 * that died. The REST API rightly will not say which: the statuses the site
 * does not show are hidden from visitors.
 *
 * check-adoptions answers the one question a partner needs, whether the pet
 * was adopted, and nothing else. It answers only for a pet the site has shown
 * at some point, since a partner can only have featured one of those.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use WP_REST_Request;

final class AdoptionSignalTest extends PetTestCase {

	private int $next_record = 700001;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		remove_all_filters( 'petsync_adopted_statuses' );
		parent::tear_down();
	}

	/**
	 * A synced pet with a status. Listed, then by default unlisted, as the sync
	 * leaves a pet whose status the site stops showing.
	 *
	 * @param string $status      Status name.
	 * @param string $post_status Post status it ends with.
	 */
	private function pet( string $status, string $post_status = 'draft' ): int {
		$id = $this->make_synced_pet(
			array(
				'id'     => $this->next_record++,
				'status' => $status,
			)
		);
		wp_set_object_terms( $id, $status, 'vcps_pet_status' );

		if ( 'publish' !== $post_status ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $post_status,
				)
			);
		}

		return $id;
	}

	/**
	 * Run one pet through the sync, as a sync run does.
	 *
	 * @param array<string, mixed> $record One pet as the Petstablished API sends it.
	 * @return int The pet's post ID.
	 */
	private function sync( array $record ): int {
		( new \ReflectionMethod( \Petsync_Sync::class, 'process_single_pet' ) )->invoke(
			new \Petsync_Sync(),
			$record + array( 'description' => '<p>Hello.</p>' )
		);

		$ids = get_posts(
			array(
				'post_type'   => 'vcps_pet',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => '_pet_ps_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture lookup.
				'meta_value'  => (string) $record['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture lookup.
			)
		);
		$this->assertCount( 1, $ids );

		return (int) $ids[0];
	}

	/**
	 * Ask the way every caller does, through the ability.
	 *
	 * @param int[] $ids Pet IDs.
	 * @return mixed
	 */
	private function ask( array $ids ) {
		return wp_get_ability( 'petsync/check-adoptions' )->execute( array( 'ids' => $ids ) );
	}

	public function test_only_an_adoption_is_told_listed_or_not(): void {
		$listed_adopted = $this->pet( 'Adopted', 'publish' );
		$adopted        = $this->pet( 'Adopted' );
		$euthanized     = $this->pet( 'Euthanized' );
		$returned       = $this->pet( 'Returned to Owner' );
		$left_feed      = $this->pet( 'Available' );
		$listed         = $this->pet( 'Available', 'publish' );
		$on_hold        = $this->pet( 'On Hold', 'publish' );
		$page           = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = $this->ask( array( $euthanized, $adopted, $returned, $left_feed, $listed, $on_hold, $page, $listed_adopted ) );

		$this->assertSame( array( $adopted, $listed_adopted ), $result['adopted'], 'adopted pets only, in the order asked' );
	}

	/**
	 * Only a pet the site has shown is told. One that never was, because it
	 * arrived from the provider already adopted or staff never published it, is
	 * the shelter's own record: no partner featured it, and an anonymous caller
	 * trying IDs must not learn that it exists.
	 *
	 * Found by the WordPress.org plugin review.
	 */
	public function test_only_a_pet_the_site_has_shown_is_told(): void {
		$this->sync(
			array(
				'id'     => 801,
				'name'   => 'Listed first',
				'status' => 'Available',
			)
		);
		$listed_first    = $this->sync(
			array(
				'id'     => 801,
				'name'   => 'Listed first',
				'status' => 'Adopted',
			)
		);
		$arrived_adopted = $this->sync(
			array(
				'id'     => 802,
				'name'   => 'Never listed',
				'status' => 'Adopted',
			)
		);
		$never_published = $this->make_manual_pet( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $never_published, 'Adopted', 'vcps_pet_status' );

		$this->assertSame( 'draft', get_post_status( $listed_first ), 'the sync unlisted it' );
		$this->assertSame( 'draft', get_post_status( $arrived_adopted ), 'and never listed this one' );

		$this->assertSame(
			array( $listed_first ),
			$this->ask( array( $arrived_adopted, $never_published, $listed_first ) )['adopted']
		);
	}

	/**
	 * The record is made by publishing, whichever way a pet is published, and
	 * outlives going back to draft.
	 */
	public function test_the_first_publication_is_remembered(): void {
		$meta = \Petsync\Core\Pet_Visibility::LISTED_META;

		$pet = $this->make_manual_pet( array( 'post_status' => 'draft' ) );
		$this->assertSame( '', get_post_meta( $pet, $meta, true ), 'a draft has not been shown' );

		wp_update_post(
			array(
				'ID'          => $pet,
				'post_status' => 'publish',
			)
		);
		$first = get_post_meta( $pet, $meta, true );
		$this->assertNotSame( '', $first );

		foreach ( array( 'draft', 'publish' ) as $post_status ) {
			wp_update_post(
				array(
					'ID'          => $pet,
					'post_status' => $post_status,
				)
			);
		}
		$this->assertSame( array( $first ), get_post_meta( $pet, $meta, false ), 'kept, and recorded once' );

		$scheduled = $this->make_manual_pet(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( '', get_post_meta( $scheduled, $meta, true ), 'scheduled is not shown yet' );
		wp_publish_post( $scheduled );
		$this->assertNotSame( '', get_post_meta( $scheduled, $meta, true ) );

		$post = self::factory()->post->create();
		$this->assertSame( '', get_post_meta( $post, $meta, true ), 'pets only' );
	}

	public function test_nothing_else_is_said(): void {
		$result = $this->ask( array( $this->pet( 'Euthanized' ) ) );

		$this->assertSame( array( 'adopted', 'listing_url' ), array_keys( $result ) );
		$this->assertSame( array(), $result['adopted'] );
		$this->assertSame( get_post_type_archive_link( 'vcps_pet' ), $result['listing_url'] );
		$this->assertNotSame( '', $result['listing_url'] );
	}

	public function test_private_and_trashed_pets_are_not_told(): void {
		$private = $this->pet( 'Adopted', 'private' );
		$trashed = $this->pet( 'Adopted', 'publish' );
		wp_trash_post( $trashed );

		$this->assertSame( array(), $this->ask( array( $private, $trashed ) )['adopted'] );
	}

	public function test_ids_are_checked_not_trusted(): void {
		$adopted = $this->pet( 'Adopted' );

		$this->assertSame( array(), $this->ask( array( -$adopted ) )['adopted'], 'absint would have turned this into the adopted pet' );
		$this->assertSame( array( $adopted ), $this->ask( array( $adopted, $adopted ) )['adopted'] );
	}

	public function test_a_long_list_is_refused_and_capped(): void {
		$adopted = $this->pet( 'Adopted' );
		$filler  = range( 1000001, 1000150 );

		$this->assertWPError( $this->ask( array_merge( $filler, array( $adopted ) ) ) );

		// PHP callers skip the schema; the handler holds the line itself.
		$late  = \Petsync\Abilities\Pets\check_adoptions( array( 'ids' => array_merge( $filler, array( $adopted ) ) ) );
		$early = \Petsync\Abilities\Pets\check_adoptions( array( 'ids' => array_merge( array( $adopted ), $filler ) ) );
		$this->assertSame( array(), $late['adopted'] );
		$this->assertSame( array( $adopted ), $early['adopted'] );
	}

	public function test_a_shelter_can_share_nothing(): void {
		$adopted = $this->pet( 'Adopted' );
		add_filter( 'petsync_adopted_statuses', '__return_empty_array' );

		$this->assertSame( array(), $this->ask( array( $adopted ) )['adopted'] );
	}

	public function test_a_shelter_can_name_its_own_adopted_status(): void {
		$adopted = $this->pet( 'Adopted' );
		$foster  = $this->pet( 'Foster to Adopt' );
		add_filter( 'petsync_adopted_statuses', static fn(): array => array( 'adopted', 'foster-to-adopt' ) );

		$this->assertSame( array( $adopted, $foster ), $this->ask( array( $adopted, $foster ) )['adopted'] );
	}

	public function test_another_site_can_ask_without_logging_in(): void {
		$adopted = $this->pet( 'Adopted' );
		$route   = '/petsync/v1/petsync/check-adoptions/run';

		$get = new WP_REST_Request( 'GET', $route );
		$get->set_query_params( array( 'input' => wp_json_encode( array( 'ids' => array( $adopted, $this->pet( 'Euthanized' ) ) ) ) ) );
		$response = rest_do_request( $get );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $adopted ), $response->get_data()['adopted'] );

		$post = new WP_REST_Request( 'POST', $route );
		$post->set_header( 'Content-Type', 'application/json' );
		$post->set_body( (string) wp_json_encode( array( 'input' => array( 'ids' => array( $adopted ) ) ) ) );
		$this->assertSame( array( $adopted ), rest_do_request( $post )->get_data()['adopted'] );
	}

	public function test_bad_input_is_the_callers_error_not_the_sites(): void {
		$request = new WP_REST_Request( 'GET', '/petsync/v1/petsync/check-adoptions/run' );

		$this->assertSame( 400, rest_do_request( $request )->get_status(), 'no ids' );

		$request->set_query_params( array( 'input' => wp_json_encode( array( 'ids' => range( 1, 101 ) ) ) ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status(), 'too many ids' );
	}
}
