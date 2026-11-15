<?php
/**
 * Who a pet is bonded with (#137).
 *
 * Petstablished sends two different things, and #42 treated them as one:
 *
 *   - the bonded group, `group_id` plus `grouped_pet_ids`;
 *   - `siblings_names`, "Lefty PS2795284, Nico PS2795285, …", which on the
 *     VCHS install lists littermates who are NOT in the group (Chong's
 *     siblings include Cheech; Patch, Lefty, Nico and Roxy are two pairs).
 *
 * Partners therefore come from the group's IDs, and a siblings entry is used
 * only to name a member of that group. And because `bonded_group_id` has no
 * manual equivalent, a hand-entered pet's "Bonded with" names must make it
 * bonded on their own, or a shelter not on Petstablished cannot mark one.
 *
 * The fixtures are shaped on real VCHS records.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Core\Pet_Hydrator;
use Petsync\Export\Exporter;
use Petsync_Kennel_Cards;
use ReflectionMethod;

final class BondedPairsTest extends PetTestCase {

	/**
	 * A synced pet in a provider group.
	 *
	 * @param string   $name     Pet name.
	 * @param int      $ps_id    Provider record ID.
	 * @param int[]    $group    The group's member IDs, including this pet's.
	 * @param string   $siblings Petstablished's siblings_names string.
	 * @param string   $status   Post status.
	 * @return int Post ID.
	 */
	private function grouped( string $name, int $ps_id, array $group, string $siblings = '', string $status = 'publish' ): int {
		return $this->make_synced_pet(
			array(
				'id'              => $ps_id,
				'name'            => $name,
				'group_id'        => $group ? 60064 : null,
				'grouped_pet_ids' => $group,
				'siblings_names'  => $siblings,
			),
			array( 'post_status' => $status )
		);
	}

	/**
	 * @param int $id Post ID.
	 * @return string[] Partner names, in order.
	 */
	private function partner_names( int $id ): array {
		Pet_Hydrator::flush_cache();
		return array_column( (array) Pet_Hydrator::get_any_status( $id )['bonded_pair_names'], 'name' );
	}

	/**
	 * Patch's siblings are Lefty, Nico and Roxy, but his group is Lefty alone.
	 * Lefty is unlisted, so no partner resolves to a published post — which is
	 * exactly when the old code fell back to the whole siblings list.
	 */
	public function test_siblings_outside_the_group_are_never_partners(): void {
		$patch = $this->grouped( 'Patch', 2795283, array( 2795283, 2795284 ), 'Lefty PS2795284, Nico PS2795285, Roxy PS2795286' );
		$this->grouped( 'Lefty', 2795284, array( 2795283, 2795284 ), 'Patch PS2795283, Nico PS2795285, Roxy PS2795286', 'draft' );

		$this->assertSame( array( 'Lefty' ), $this->partner_names( $patch ) );
	}

	/**
	 * The sharpest form of the old fallback: nobody in the group can be named,
	 * and the siblings string lists only animals outside it. Naming nobody is
	 * right; naming the siblings puts the wrong animals on the card.
	 */
	public function test_an_unnameable_partner_is_not_replaced_by_other_siblings(): void {
		$patch = $this->grouped( 'Patch', 2795283, array( 2795283, 2795284 ), 'Nico PS2795285, Roxy PS2795286' );
		$pet   = Pet_Hydrator::get_any_status( $patch );

		$this->assertSame( array(), $pet['bonded_pair_names'] );
		$this->assertTrue( $pet['is_bonded_pair'], 'still bonded, just without a name' );
		$this->assertSame( 'Bonded pair: adopt together.', $pet['bonded_pair_summary'] );
	}

	/**
	 * An unlisted partner is named but not linked: its page would 404.
	 */
	public function test_an_unlisted_partner_is_named_without_a_link(): void {
		$patch = $this->grouped( 'Patch', 2795283, array( 2795283, 2795284 ), 'Lefty PS2795284, Nico PS2795285' );
		$this->grouped( 'Lefty', 2795284, array( 2795283, 2795284 ), '', 'draft' );

		$partners = Pet_Hydrator::get_any_status( $patch )['bonded_pair_names'];

		$this->assertCount( 1, $partners );
		$this->assertNull( $partners[0]['id'] );
		$this->assertSame( '', $partners[0]['url'] );
	}

	public function test_a_listed_partner_links_and_a_sibling_outside_the_group_is_dropped(): void {
		$chong = $this->grouped( 'Chong', 2602082, array( 2602082, 2602083 ), 'Cheech PS2591864, Val PS2602083' );
		$val   = $this->grouped( 'Val', 2602083, array( 2602082, 2602083 ), 'Cheech PS2591864, Chong PS2602082' );

		$partners = Pet_Hydrator::get_any_status( $chong )['bonded_pair_names'];

		$this->assertSame( array( 'Val' ), array_column( $partners, 'name' ) );
		$this->assertSame( $val, $partners[0]['id'] );
		$this->assertSame( get_permalink( $val ), $partners[0]['url'] );
	}

	/**
	 * The old fallback was all-or-nothing: one listed partner suppressed every
	 * unlisted one. In a group of three, each member is resolved on its own.
	 */
	public function test_each_member_of_a_larger_group_is_resolved_on_its_own(): void {
		$group = array( 101, 102, 103 );
		$a     = $this->grouped( 'Kit Kat', 101, $group, 'Rolo PS102, Snickers PS103' );
		$this->grouped( 'Rolo', 102, $group );

		$this->assertSame( array( 'Rolo', 'Snickers' ), $this->partner_names( $a ) );
	}

	/**
	 * Spike: four siblings, no group. Siblings are not a bond.
	 */
	public function test_siblings_without_a_group_are_not_a_bond(): void {
		$spike = $this->grouped( 'Spike', 1821817, array(), 'Bart PS1821818, Rookie PS1821819' );
		$pet   = Pet_Hydrator::get_any_status( $spike );

		$this->assertFalse( $pet['is_bonded_pair'] );
		$this->assertSame( array(), $pet['bonded_pair_names'] );
		$this->assertSame( '', $pet['bonded_names'] );
		$this->assertSame( '', $pet['bonded_pair_summary'] );
	}

	/**
	 * Some groups arrive with no siblings string at all (Em and Hayes on VCHS).
	 * The partner's name then comes from the listed partner itself.
	 */
	public function test_a_group_without_a_siblings_string_still_names_a_listed_partner(): void {
		$em = $this->grouped( 'Em', 2059580, array( 2059580, 2059581 ) );
		$this->grouped( 'Hayes', 2059581, array( 2059580, 2059581 ) );

		$this->assertSame( array( 'Hayes' ), $this->partner_names( $em ) );
	}

	/**
	 * A group whose partner cannot be named is still a bond — the pet must not
	 * lose its badge because a name is missing.
	 */
	public function test_a_group_with_no_nameable_partner_is_still_bonded(): void {
		$ruthie = $this->grouped( 'Ruthie', 2309389, array( 2309389, 2309390 ) );
		$pet    = Pet_Hydrator::get_any_status( $ruthie );

		$this->assertTrue( $pet['is_bonded_pair'] );
		$this->assertSame( array(), $pet['bonded_pair_names'] );
		$this->assertSame( 'Bonded pair: adopt together.', $pet['bonded_pair_summary'] );
	}

	/**
	 * bonded_names is what the export carries, so for a synced pet it must hold
	 * the bonded partners, not Petstablished's siblings. Otherwise a shelter
	 * moving off Petstablished re-imports every litter as a bonded group.
	 */
	public function test_a_synced_pets_bonded_names_are_its_partners_not_its_siblings(): void {
		$chong = $this->grouped( 'Chong', 2602082, array( 2602082, 2602083 ), 'Cheech PS2591864, Val PS2602083' );
		$this->grouped( 'Val', 2602083, array( 2602082, 2602083 ), 'Cheech PS2591864, Chong PS2602082' );

		Pet_Hydrator::flush_cache();
		$this->assertSame( 'Val', Pet_Hydrator::get_any_status( $chong )['bonded_names'] );

		$rows = Exporter::rows( array( $chong ) );
		$this->assertSame( 'Val', $rows[0]['bonded_names'] );
	}

	// ── Hand entry ───────────────────────────────────────────────────────────

	public function test_hand_entered_names_make_a_pet_bonded(): void {
		$id = $this->make_manual_pet( array( 'post_title' => 'Pepper' ) );
		update_post_meta( $id, $this->prefix . 'bonded_names', 'Salt, Sage' );
		Pet_Hydrator::flush_cache();

		$pet = Pet_Hydrator::get( $id );

		$this->assertTrue( $pet['is_bonded_pair'], 'a shelter not on Petstablished must be able to mark a pet bonded' );
		$this->assertSame( array( 'Salt', 'Sage' ), array_column( $pet['bonded_pair_names'], 'name' ) );
		$this->assertSame( 'Salt, Sage', $pet['bonded_names'], 'what staff typed is kept as typed' );
	}

	/**
	 * Hand-entered names are only names. Linking "Max" to whichever listed pet
	 * happens to be called Max would send visitors to the wrong animal.
	 */
	public function test_hand_entered_partners_are_not_linked(): void {
		$this->make_manual_pet( array( 'post_title' => 'Salt' ) );
		$id = $this->make_manual_pet( array( 'post_title' => 'Pepper' ) );
		update_post_meta( $id, $this->prefix . 'bonded_names', 'Salt' );
		Pet_Hydrator::flush_cache();

		$partners = Pet_Hydrator::get( $id )['bonded_pair_names'];

		$this->assertSame( '', $partners[0]['url'] );
		$this->assertNull( $partners[0]['id'] );
	}

	public function test_hand_entered_names_accept_the_usual_separators(): void {
		$id = $this->make_manual_pet();
		update_post_meta( $id, $this->prefix . 'bonded_names', ' Salt ;Sage| Thyme,, ' );

		$this->assertSame( array( 'Salt', 'Sage', 'Thyme' ), $this->partner_names( $id ) );
	}

	/**
	 * A name carrying an "and" is one pet, not two. Only punctuation splits.
	 */
	public function test_a_name_containing_and_is_one_pet(): void {
		$id = $this->make_manual_pet();
		update_post_meta( $id, $this->prefix . 'bonded_names', 'Salt and Pepper' );

		$this->assertSame( array( 'Salt and Pepper' ), $this->partner_names( $id ) );
	}

	public function test_a_pet_with_nothing_entered_is_not_bonded(): void {
		$pet = Pet_Hydrator::get( $this->make_manual_pet() );

		$this->assertFalse( $pet['is_bonded_pair'] );
		$this->assertSame( '', $pet['bonded_pair_summary'] );
	}

	// ── The summary line ─────────────────────────────────────────────────────

	public function test_the_summary_names_one_partner(): void {
		$id = $this->make_manual_pet();
		update_post_meta( $id, $this->prefix . 'bonded_names', 'Val' );
		Pet_Hydrator::flush_cache();

		$this->assertSame( 'Bonded with Val: adopt together.', Pet_Hydrator::get( $id )['bonded_pair_summary'] );
	}

	public function test_the_summary_lists_several_partners(): void {
		$id = $this->make_manual_pet();
		update_post_meta( $id, $this->prefix . 'bonded_names', 'Lefty, Nico, Roxy' );
		Pet_Hydrator::flush_cache();

		$this->assertSame(
			'Bonded with Lefty, Nico, and Roxy: adopt together.',
			Pet_Hydrator::get( $id )['bonded_pair_summary']
		);
	}

	// ── Bindings and the kennel card ─────────────────────────────────────────

	/**
	 * @param string $key Binding key.
	 * @param int    $id  Pet post ID.
	 * @return string Rendered paragraph.
	 */
	private function render_bound_paragraph( string $key, int $id ): string {
		$markup = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"petsync/pet-data","args":{"key":"' . $key . '"}}}}} --><p>SAVED</p><!-- /wp:paragraph -->';

		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		setup_postdata( $GLOBALS['post'] );
		$html = do_blocks( $markup );
		wp_reset_postdata();

		return $html;
	}

	/**
	 * The key was advertised among the binding keys but is a list, and the
	 * source returned null for anything not scalar — so a bound block kept its
	 * saved content.
	 */
	public function test_bonded_pair_names_binds_as_a_list_of_names(): void {
		$id = $this->make_manual_pet();
		update_post_meta( $id, $this->prefix . 'bonded_names', 'Lefty, Nico' );
		Pet_Hydrator::flush_cache();

		$html = $this->render_bound_paragraph( 'bonded_pair_names', $id );

		$this->assertStringContainsString( 'Lefty and Nico', $html );
		$this->assertStringNotContainsString( 'SAVED', $html );
	}

	public function test_bonded_pair_summary_binds(): void {
		$id = $this->make_manual_pet();
		update_post_meta( $id, $this->prefix . 'bonded_names', 'Val' );
		Pet_Hydrator::flush_cache();

		$this->assertStringContainsString(
			'Bonded with Val: adopt together.',
			$this->render_bound_paragraph( 'bonded_pair_summary', $id )
		);
	}

	/**
	 * @param int $id Pet post ID.
	 * @return string The card's HTML.
	 */
	private function card( int $id ): string {
		Pet_Hydrator::flush_cache();
		return ( new ReflectionMethod( Petsync_Kennel_Cards::class, 'render_card' ) )
			->invoke( new Petsync_Kennel_Cards(), $id );
	}

	public function test_the_shipped_card_says_who_a_pet_is_bonded_with(): void {
		$chong = $this->grouped( 'Chong', 2602082, array( 2602082, 2602083 ), 'Cheech PS2591864, Val PS2602083' );
		$this->grouped( 'Val', 2602083, array( 2602082, 2602083 ) );

		$html = $this->card( $chong );

		$this->assertMatchesRegularExpression(
			'#<p class="kennel-card__bonded[^"]*">Bonded with Val: adopt together\.</p>#',
			$html
		);
		$this->assertStringNotContainsString( 'Cheech', $html );
	}

	/**
	 * Not bonded, the line renders empty, which the card's `p:empty` rule
	 * collapses — so the card is laid out exactly as before.
	 */
	public function test_an_unbonded_pets_card_has_an_empty_line(): void {
		$html = $this->card( $this->make_manual_pet( array( 'post_title' => 'Solo' ) ) );

		// WordPress 7.1 adds a wp-block-paragraph class; 6.9 does not.
		$this->assertMatchesRegularExpression( '#<p class="kennel-card__bonded[^"]*"></p>#', $html );
	}
}
