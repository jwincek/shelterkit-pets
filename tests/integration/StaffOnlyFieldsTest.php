<?php
/**
 * Staff-only fields never reach someone who cannot edit the pet.
 *
 * Until staff_only_fields existed, a logged-out visitor could read every listed
 * pet's microchip number four ways: the core REST API's `meta`, get-pet,
 * batch-get-pets and the comparison abilities. Nothing on a public page shows
 * one, so none of that was visible from the front end, and no test asked.
 *
 * The abilities are exercised through WP_Ability::execute(), the path REST, MCP
 * and the block bindings all take, and through every registered ability rather
 * than the four that leaked. A fifth route added later is covered without
 * anyone remembering to add it, which is the point.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Core\Pet_Hydrator;
use WP_REST_Request;

final class StaffOnlyFieldsTest extends PetTestCase {

	private const CHIP = '985141000424242';

	private int $pet;

	public function set_up(): void {
		parent::set_up();

		$this->pet = $this->make_manual_pet( array( 'post_title' => 'Chipped' ) );
		wp_set_object_terms( $this->pet, 'Dog', 'pet_animal' );
		wp_set_object_terms( $this->pet, 'available', 'pet_status' );
		update_post_meta( $this->pet, $this->prefix . 'microchip_id', self::CHIP );
		update_post_meta( $this->pet, $this->prefix . 'ps_id', '777' );
	}

	/**
	 * One input per ability, deliberately exhaustive, as in
	 * AbilityOutputSchemaTest: an ability with no entry fails the coverage test
	 * below instead of going unchecked.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function inputs(): array {
		return array(
			'petsync/get-pet'            => array( 'id' => $this->pet ),
			'petsync/list-pets'          => array(),
			'petsync/filter-pets'        => array(),
			'petsync/batch-get-pets'     => array( 'ids' => array( $this->pet ) ),
			'petsync/get-filter-options' => array(),
			'petsync/toggle-favorite'    => array( 'id' => $this->pet ),
			'petsync/get-favorites'      => array(),
			'petsync/clear-favorites'    => array(),
			'petsync/update-comparison'  => array(
				'action' => 'add',
				'id'     => $this->pet,
			),
			'petsync/get-comparison'     => array(),
			'petsync/get-adoption-stats' => array(),
			'petsync/set-pet-gallery'    => array(
				'id'             => $this->pet,
				'attachment_ids' => array(),
			),
		);
	}

	/**
	 * @return string[]
	 */
	private function registered(): array {
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( str_starts_with( $ability->get_name(), 'petsync/' ) ) {
				$names[] = $ability->get_name();
			}
		}
		sort( $names );
		return $names;
	}

	/**
	 * Every key named in staff_only_fields, at any depth, with the path to it.
	 *
	 * @return string[]
	 */
	private function leaks( mixed $data, string $path = '' ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$found = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, Pet_Hydrator::staff_only_fields(), true ) ) {
				$found[] = $path . '/' . $key;
			}
			$found = array_merge( $found, $this->leaks( $value, $path . '/' . $key ) );
		}

		return $found;
	}

	public function test_no_ability_shows_a_visitor_a_staff_only_field(): void {
		wp_set_current_user( 0 );

		$inputs   = $this->inputs();
		$answered = array();

		foreach ( $this->registered() as $name ) {
			$result = wp_get_ability( $name )->execute( $inputs[ $name ] ?? array() );

			if ( is_wp_error( $result ) ) {
				continue; // Refused, so it leaked nothing.
			}

			$answered[] = $name;
			$this->assertSame( array(), $this->leaks( $result ), "$name gave a logged-out visitor a staff-only field" );
		}

		// The four that leaked before must actually have answered, or the
		// assertions above passed by never seeing the pet.
		foreach ( array( 'petsync/get-pet', 'petsync/batch-get-pets', 'petsync/update-comparison', 'petsync/get-comparison' ) as $name ) {
			$this->assertContains( $name, $answered, "$name must answer a visitor for this test to mean anything" );
		}
	}

	/**
	 * Redaction must not overreach: staff still read the field through the
	 * same abilities, and the hydrator, which export and import use, is untouched.
	 */
	public function test_staff_still_see_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$pet = wp_get_ability( 'petsync/get-pet' )->execute( array( 'id' => $this->pet ) );
		$this->assertSame( self::CHIP, $pet['microchip_id'] ?? null );

		wp_set_current_user( 0 );
		// get() memoises per request, and setting up the pet hydrated it before
		// the chip was written.
		Pet_Hydrator::flush_cache();
		$this->assertSame(
			self::CHIP,
			Pet_Hydrator::get( $this->pet, 'full' )['microchip_id'],
			'hydration itself never redacts: a WP-CLI export runs as no user and still needs the chip'
		);
	}

	public function test_rest_shows_it_only_in_the_edit_context(): void {
		$route = '/wp/v2/' . ( get_post_type_object( 'vcps_pet' )->rest_base ?: 'vcps_pet' ) . '/' . $this->pet;

		wp_set_current_user( 0 );
		$meta = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data()['meta'] ?? array();
		$this->assertArrayNotHasKey( $this->prefix . 'microchip_id', $meta, 'a public response must not carry the chip' );
		$this->assertArrayNotHasKey( $this->prefix . 'ps_id', $meta, 'the sync key is internal' );
		$this->assertArrayHasKey( $this->prefix . 'weight', $meta, 'and ordinary meta is still public, or this proves nothing' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'context', 'edit' );
		$this->assertSame( self::CHIP, rest_do_request( $request )->get_data()['meta'][ $this->prefix . 'microchip_id' ] ?? null );

		// The editor's sidebar saves through the same field, so it must still
		// accept a write.
		$update = new WP_REST_Request( 'POST', $route );
		$update->set_param( 'meta', array( $this->prefix . 'microchip_id' => '985141000555555' ) );
		$this->assertSame( 200, rest_do_request( $update )->get_status() );
		$this->assertSame( '985141000555555', get_post_meta( $this->pet, $this->prefix . 'microchip_id', true ) );
	}

	public function test_it_is_not_offered_as_a_binding(): void {
		$blocks = new \Petsync_Blocks();
		$method = new \ReflectionMethod( $blocks, 'get_binding_keys' );
		$keys   = array_column( $method->invoke( $blocks ), 'key' );

		$this->assertNotContains( 'microchip_id', $keys );
		$this->assertContains( 'weight', $keys );
	}

	public function test_every_registered_ability_is_exercised_here(): void {
		$this->assertSame(
			array(),
			array_diff( $this->registered(), array_keys( $this->inputs() ) ),
			'an ability is registered but has no input here, so nobody checked it for staff-only fields'
		);
	}
}
