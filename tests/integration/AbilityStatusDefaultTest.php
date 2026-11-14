<?php
/**
 * The pet-listing abilities default to available pets, as their schemas say.
 *
 * list-pets and filter-pets declare `"default": "available"` for status, but
 * the Abilities API does not fill in defaults, and the shared query read
 * `$input['status'] ?? null`, which means every status. The site's own blocks
 * always pass a status, so only outside callers saw it: an MCP client asking
 * for adoptable pets without naming a status got adopted ones as well.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

final class AbilityStatusDefaultTest extends PetTestCase {

	private int $available;

	private int $adopted;

	public function set_up(): void {
		parent::set_up();

		$this->available = $this->make_manual_pet( array( 'post_title' => 'Still Here' ) );
		$this->adopted   = $this->make_manual_pet( array( 'post_title' => 'Gone Home' ) );
		wp_set_object_terms( $this->available, 'available', 'vcps_pet_status' );
		wp_set_object_terms( $this->adopted, 'adopted', 'vcps_pet_status' );
	}

	/**
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $input   Input.
	 * @return int[] The pets it returned.
	 */
	private function ids( string $ability, array $input ): array {
		$result = wp_get_ability( $ability )->execute( $input );

		return array_map( 'intval', wp_list_pluck( (array) ( $result['pets'] ?? array() ), 'id' ) );
	}

	/**
	 * @return string[][]
	 */
	public static function listing_abilities(): array {
		return array(
			'list-pets'   => array( 'petsync/list-pets' ),
			'filter-pets' => array( 'petsync/filter-pets' ),
		);
	}

	/**
	 * @dataProvider listing_abilities
	 *
	 * @param string $ability Ability name.
	 */
	public function test_without_a_status_only_available_pets_are_listed( string $ability ): void {
		$ids = $this->ids( $ability, array() );

		$this->assertContains( $this->available, $ids );
		$this->assertNotContains( $this->adopted, $ids );
	}

	/**
	 * @dataProvider listing_abilities
	 *
	 * @param string $ability Ability name.
	 */
	public function test_an_empty_status_asks_for_every_status( string $ability ): void {
		$ids = $this->ids( $ability, array( 'status' => '' ) );

		$this->assertContains( $this->available, $ids );
		$this->assertContains( $this->adopted, $ids );
	}
}
