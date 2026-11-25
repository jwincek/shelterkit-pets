<?php
/**
 * Which species the sync asks Petstablished for.
 *
 * It asked for cats and dogs only, hard-coded since before this repository,
 * most likely because the plugin began as one shelter's. A shelter listing
 * rabbits or birds never saw them, and nothing said why.
 *
 * Petstablished is never contacted here: pre_http_request answers in its
 * place, with a feed shaped like the real one, and records what was asked.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync_Admin;
use Petsync_Sync;
use ReflectionMethod;

final class SyncSpeciesTest extends PetTestCase {

	/**
	 * URLs the sync requested.
	 *
	 * @var string[]
	 */
	private array $requested = array();

	/**
	 * What the stand-in Petstablished lists.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $feed = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'answer_as_petstablished' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_as_petstablished' ), 10 );
		delete_option( 'petsync_settings' );
		parent::tear_down();
	}

	public function answer_as_petstablished( $preempt, $args, $url ) {
		if ( ! str_starts_with( (string) $url, 'https://petstablished.com/' ) ) {
			return $preempt;
		}

		$this->requested[] = (string) $url;

		// Filter by species as Petstablished does, so a request that names
		// only cats and dogs gets no rabbit back.
		parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
		$wanted = isset( $query['search']['animal'] ) ? array_map( 'strtolower', explode( ',', (string) $query['search']['animal'] ) ) : null;
		$feed   = null === $wanted
			? $this->feed
			: array_values( array_filter( $this->feed, static fn( array $pet ): bool => in_array( strtolower( (string) $pet['animal'] ), $wanted, true ) ) );

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'collection' => $feed,
					'pagination' => array( 'total_pages' => 1 ),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * @param array<string, mixed> $settings Settings to save.
	 */
	private function settings( array $settings ): void {
		update_option( 'petsync_settings', array_merge( array( 'public_key' => 'pk_test' ), $settings ) );
	}

	/**
	 * @return string|null The species asked for, or null if none were named.
	 */
	private function species_asked_for(): ?string {
		$this->requested = array();
		( new ReflectionMethod( Petsync_Sync::class, 'fetch_page' ) )->invoke( new Petsync_Sync(), 'pk_test', 1 );

		$this->assertCount( 1, $this->requested );
		parse_str( (string) wp_parse_url( $this->requested[0], PHP_URL_QUERY ), $query );

		return $query['search']['animal'] ?? null;
	}

	private function record( int $id, string $name, string $animal ): array {
		return array(
			'id'          => $id,
			'name'        => $name,
			'animal'      => $animal,
			'status'      => 'Available',
			'description' => '<p>Hello.</p>',
		);
	}

	public function test_every_species_is_asked_for_by_default(): void {
		$this->settings( array() );

		$this->assertNull( $this->species_asked_for() );
	}

	public function test_only_the_chosen_species_are_asked_for(): void {
		$this->settings( array( 'sync_species' => array( 'Cat', 'Dog' ) ) );

		$this->assertSame( 'Cat,Dog', $this->species_asked_for() );
	}

	/**
	 * End to end: a sync run brings in a rabbit.
	 */
	public function test_a_rabbit_on_petstablished_is_synced(): void {
		$this->settings( array() );
		$this->feed = array( $this->record( 7001, 'Clover', 'Rabbit' ) );

		( new Petsync_Sync() )->run_sync( 'manual' );

		$pets = get_posts(
			array(
				'post_type' => 'vcps_pet',
				'title'     => 'Clover',
				'fields'    => 'ids',
			)
		);
		$this->assertCount( 1, $pets );
		$this->assertSame( array( 'Rabbit' ), wp_get_object_terms( $pets[0], 'vcps_pet_animal', array( 'fields' => 'names' ) ) );
	}

	public function test_saving_the_settings_keeps_the_species(): void {
		$saved = ( new Petsync_Admin() )->sanitize_settings( array( 'sync_species' => ' Cat, Dog ,, Rabbit, cat ' ) );

		$this->assertSame( array( 'Cat', 'Dog', 'Rabbit' ), $saved['sync_species'] );
	}

	/**
	 * Narrowing the list makes the other species leave the feed. The pets
	 * list says that is why, not that Petstablished stopped listing them.
	 */
	public function test_a_pet_of_a_species_no_longer_synced_says_so(): void {
		$this->settings( array() );
		$this->feed = array( $this->record( 7002, 'Thumper', 'Rabbit' ) );
		( new Petsync_Sync() )->run_sync( 'manual' );
		$rabbit = get_posts(
			array(
				'post_type' => 'vcps_pet',
				'title'     => 'Thumper',
				'fields'    => 'ids',
			)
		)[0];

		$this->settings( array( 'sync_species' => array( 'Cat', 'Dog' ) ) );
		$this->feed = array( $this->record( 7003, 'Rex', 'Dog' ) );
		( new Petsync_Sync() )->run_sync( 'manual' );

		$this->assertSame( 'draft', get_post_status( $rabbit ) );
		$states = (array) apply_filters( 'display_post_states', array(), get_post( $rabbit ) );
		$this->assertStringContainsString( 'Rabbit: not a species this site syncs', implode( ' ', $states ) );
	}
}
