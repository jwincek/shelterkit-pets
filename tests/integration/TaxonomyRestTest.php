<?php
/**
 * The REST API keeps the taxonomies' old names.
 *
 * Migration 9 renamed the taxonomies, but a REST client on another site, such
 * as a companion plugin listing this site's pets, is upgraded separately. If
 * the routes moved with the names, whichever site updated first would break
 * the other. So each taxonomy keeps its pre-migration-9 name as its rest_base.
 * Both shapes such a client uses are checked here, exactly as it calls them.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use WP_REST_Request;

final class TaxonomyRestTest extends PetTestCase {

	/**
	 * Each taxonomy's name before 2.0, which it keeps as its REST base.
	 */
	private const OLD_NAMES = array(
		'pet_status'    => 'vcps_pet_status',
		'pet_animal'    => 'vcps_pet_animal',
		'pet_breed'     => 'vcps_pet_breed',
		'pet_age'       => 'vcps_pet_age',
		'pet_sex'       => 'vcps_pet_sex',
		'pet_size'      => 'vcps_pet_size',
		'pet_color'     => 'vcps_pet_color',
		'pet_coat'      => 'vcps_pet_coat',
		'pet_attribute' => 'vcps_pet_attribute',
	);

	public function test_every_taxonomy_is_served_under_its_old_name(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( self::OLD_NAMES as $old => $new ) {
			$this->assertSame( $old, get_taxonomy( $new )->rest_base );
			$this->assertArrayHasKey( '/wp/v2/' . $old, $routes, "/wp/v2/$old must still answer" );
		}
	}

	public function test_a_client_lists_terms_by_the_old_route(): void {
		$dog = wp_insert_term( 'Dog', 'vcps_pet_animal' );
		wp_set_object_terms( $this->make_manual_pet(), $dog['term_id'], 'vcps_pet_animal' );

		$request = new WP_REST_Request( 'GET', '/wp/v2/pet_animal' );
		$request->set_param( 'hide_empty', true );
		$names = wp_list_pluck( rest_do_request( $request )->get_data(), 'name' );

		$this->assertContains( 'Dog', $names );
	}

	public function test_a_client_filters_pets_by_the_old_parameter(): void {
		$dog = wp_insert_term( 'Dog', 'vcps_pet_animal' );
		$cat = wp_insert_term( 'Cat', 'vcps_pet_animal' );

		$rex   = $this->make_manual_pet( array( 'post_title' => 'Rex' ) );
		$tabby = $this->make_manual_pet( array( 'post_title' => 'Tabby' ) );
		wp_set_object_terms( $rex, $dog['term_id'], 'vcps_pet_animal' );
		wp_set_object_terms( $tabby, $cat['term_id'], 'vcps_pet_animal' );

		$base    = get_post_type_object( 'vcps_pet' )->rest_base ?: 'vcps_pet';
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . $base );
		$request->set_param( 'pet_animal', (string) $dog['term_id'] );
		$ids = wp_list_pluck( rest_do_request( $request )->get_data(), 'id' );

		$this->assertSame( array( $rex ), $ids );
	}
}
