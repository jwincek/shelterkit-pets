<?php
/**
 * Which pets the site shows, and what staff are told about the rest.
 *
 * The sync published every pet in the feed unless Petstablished flagged it
 * hidden. The feed is not filtered by status, and VCHS's synced pets carry
 * Adopted, Euthanized and Returned to Owner, so such a pet had a public page
 * with its status as a badge and a "Start Adoption Application" button. The
 * status names themselves were public too, through the REST API.
 *
 * Now a synced pet is published only if its status is one the site shows
 * (Available, Adoption Pending and On Hold unless a shelter chooses
 * otherwise); only an available pet offers the application; visitors cannot
 * list the statuses the site does not show; and a synced draft says why it is
 * a draft in the pets list.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync_Admin;
use Petsync_Sync;
use ReflectionMethod;
use WP_Block;
use WP_REST_Request;

final class PetVisibilityTest extends PetTestCase {

	public function tear_down(): void {
		delete_option( 'petsync_settings' );
		delete_option( 'petsync_last_sync' );
		parent::tear_down();
	}

	/**
	 * @param int                  $id      Provider record ID.
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed> One pet as the Petstablished API sends it.
	 */
	private function record( int $id, array $changes = array() ): array {
		return array_merge(
			array(
				'id'          => $id,
				'name'        => "Pet {$id}",
				'status'      => 'Available',
				'description' => '<p>Hello.</p>',
			),
			$changes
		);
	}

	/**
	 * @param string $step    Sync method.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function sync_step( string $step, ...$args ) {
		return ( new ReflectionMethod( Petsync_Sync::class, $step ) )->invoke( new Petsync_Sync(), ...$args );
	}

	private function synced( array $record ): int {
		$this->sync_step( 'process_single_pet', $record );

		$ids = get_posts(
			array(
				'post_type'   => 'vcps_pet',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => '_pet_ps_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture lookup.
				'meta_value'  => (string) $record['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture lookup.
			)
		);
		return (int) $ids[0];
	}

	private function states( int $id ): string {
		return implode( ' | ', (array) apply_filters( 'display_post_states', array(), get_post( $id ) ) );
	}

	// ─── The sync ───────────────────────────────────────────────────────────

	public function test_a_pet_whose_status_the_site_does_not_show_is_synced_as_a_draft(): void {
		$this->assertSame( 'draft', get_post_status( $this->synced( $this->record( 1, array( 'status' => 'Adopted' ) ) ) ) );
		$this->assertSame( 'draft', get_post_status( $this->synced( $this->record( 2, array( 'status' => 'Euthanized' ) ) ) ), 'a status the site has never seen is not shown either' );
	}

	public function test_pending_and_on_hold_pets_are_shown_by_default(): void {
		$this->assertSame( 'publish', get_post_status( $this->synced( $this->record( 3, array( 'status' => 'Adoption Pending' ) ) ) ) );
		$this->assertSame( 'publish', get_post_status( $this->synced( $this->record( 4, array( 'status' => 'On Hold' ) ) ) ) );
	}

	public function test_a_pet_back_to_available_is_published_again(): void {
		$pet = $this->synced( $this->record( 5, array( 'status' => 'On Hold' ) ) );
		$this->synced( $this->record( 5, array( 'status' => 'Adopted' ) ) );
		$this->assertSame( 'draft', get_post_status( $pet ) );

		$this->synced( $this->record( 5 ) );

		$this->assertSame( 'publish', get_post_status( $pet ) );
		$this->assertSame( '', $this->states( $pet ) );
	}

	/**
	 * A shelter that wants adopted pets shown, say as success stories, ticks
	 * Adopted. The next sync publishes them, though their data is unchanged.
	 */
	public function test_a_changed_setting_takes_effect_at_the_next_sync(): void {
		$pet = $this->synced( $this->record( 6, array( 'status' => 'Adopted' ) ) );

		update_option( 'petsync_settings', array( 'public_statuses' => array( 'available', 'adopted' ) ) );
		$this->synced( $this->record( 6, array( 'status' => 'Adopted' ) ) );

		$this->assertSame( 'publish', get_post_status( $pet ) );
	}

	/**
	 * The settings screen rebuilds the option from a whitelist on save, which
	 * also dropped the two staleness settings, so a save reset them.
	 */
	public function test_saving_the_settings_keeps_the_status_list_and_the_staleness_days(): void {
		$saved = ( new Petsync_Admin() )->sanitize_settings(
			array(
				'public_statuses'   => array( 'pending', 'not-a-status' ),
				'stale_notice_days' => '14',
				'stale_draft_days'  => '30',
			)
		);

		$this->assertSame( array( 'available', 'pending' ), $saved['public_statuses'], 'available is always shown, and only real statuses are kept' );
		$this->assertSame( 14, $saved['stale_notice_days'] );
		$this->assertSame( 30, $saved['stale_draft_days'] );
	}

	// ─── The application ────────────────────────────────────────────────────

	public function test_only_an_available_pet_offers_the_application(): void {
		$pet = $this->make_manual_pet( array( 'post_title' => 'Biscuit' ) );
		update_post_meta( $pet, $this->prefix . 'adoption_form_url', 'https://example.invalid/apply' );
		$render = static fn(): string => ( new WP_Block(
			array(
				'blockName'    => 'petsync/adoption-action',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'postId' => $pet )
		) )->render();

		wp_set_object_terms( $pet, 'available', 'vcps_pet_status' );
		$this->assertStringContainsString( 'https://example.invalid/apply', $render() );

		wp_set_object_terms( $pet, 'pending', 'vcps_pet_status' );
		\Petsync\Core\Pet_Hydrator::flush_cache();
		$closed = $render();
		$this->assertStringNotContainsString( 'https://example.invalid/apply', $closed );
		$this->assertStringContainsString( 'Adoption Pending: not taking applications right now.', $closed );
	}

	// ─── The REST API ───────────────────────────────────────────────────────

	public function test_visitors_cannot_list_the_statuses_the_site_does_not_show(): void {
		$euthanized = wp_insert_term( 'Euthanized', 'vcps_pet_status' );
		$names      = static fn(): array => wp_list_pluck( rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pet_status' ) )->get_data(), 'name' );

		wp_set_current_user( 0 );
		$public = $names();
		$this->assertContains( 'Available', $public );
		$this->assertContains( 'Adoption Pending', $public );
		$this->assertNotContains( 'Adopted', $public );
		$this->assertNotContains( 'Euthanized', $public );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pet_status/' . $euthanized['term_id'] ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertContains( 'Euthanized', $names(), 'the editor still needs every status' );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pet_status/' . $euthanized['term_id'] ) )->get_status() );
	}

	// ─── What staff see ─────────────────────────────────────────────────────

	public function test_staff_see_why_a_synced_pet_is_a_draft(): void {
		$left = $this->synced( $this->record( 10 ) );
		$this->sync_step( 'remove_stale_pets', array( $this->record( 99 ) ) );
		$this->assertStringContainsString( 'No longer listed on Petstablished since ' . wp_date( get_option( 'date_format' ) ), $this->states( $left ) );

		$adopted = $this->synced( $this->record( 11, array( 'status' => 'Adopted' ) ) );
		$this->assertStringContainsString( 'Adopted: not shown on this site', $this->states( $adopted ) );

		$hidden = $this->synced( $this->record( 12, array( 'dont_show_in_public_search' => true ) ) );
		$this->assertStringContainsString( 'Hidden on Petstablished', $this->states( $hidden ) );
	}

	/**
	 * Drafting a synced pet here no longer lasts: the next sync publishes it
	 * again, because Petstablished is its record. Staff are told so.
	 */
	public function test_a_synced_pet_drafted_here_says_the_next_sync_will_publish_it(): void {
		$pet = $this->synced( $this->record( 13 ) );
		update_option( 'petsync_last_sync', time() - HOUR_IN_SECONDS );
		wp_update_post(
			array(
				'ID'          => $pet,
				'post_status' => 'draft',
			)
		);

		$this->assertStringContainsString( 'Listed on Petstablished: the next sync will publish it', $this->states( $pet ) );
	}

	public function test_a_hand_entered_draft_gets_no_such_note(): void {
		$this->assertSame( '', $this->states( $this->make_manual_pet( array( 'post_status' => 'draft' ) ) ) );
	}
}
