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
 * was adopted, and nothing else.
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
	 * A synced pet with a status, published or not.
	 *
	 * @param string $status      Status name.
	 * @param string $post_status Post status.
	 */
	private function pet( string $status, string $post_status = 'draft' ): int {
		$id = $this->make_synced_pet(
			array(
				'id'     => $this->next_record++,
				'status' => $status,
			),
			array( 'post_status' => $post_status )
		);
		wp_set_object_terms( $id, $status, 'vcps_pet_status' );

		return $id;
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
