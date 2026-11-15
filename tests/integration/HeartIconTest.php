<?php
/**
 * Favorite hearts carry no Interactivity directive inside their <svg>.
 *
 * Petsync_Icons::get_heart_interactive() bound `fill` on the heart's <path>.
 * WordPress's server-side processor refuses a directive inside an <svg> and
 * logs "Interactivity directives were detected inside an incompatible SVG
 * tag" on every render, so every pet page on a debug-enabled site logged it.
 * And the directive held an expression, which directives do not evaluate, so
 * the heart never filled. Validator check 15 caught the same mistake in block
 * templates in 1.6.0 but never looked at the helper that builds this one.
 * Found on the 2.0.0 clean-install pass.
 *
 * WP_UnitTestCase fails a test on any unexpected _doing_it_wrong(), so
 * rendering the blocks is the assertion that matters here.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync_Icons;

final class HeartIconTest extends PetTestCase {

	public function test_the_heart_carries_a_class_and_no_directive(): void {
		$svg = Petsync_Icons::get_heart_interactive( array( 'class' => 'pet-favorites-toggle__icon' ) );

		$this->assertStringNotContainsString( 'data-wp-', $svg );
		$this->assertMatchesRegularExpression( '#<svg[^>]*class="[^"]*pet-favorites-toggle__icon[^"]*petsync-heart#', $svg );
		$this->assertStringNotContainsString( 'fill="currentColor"', $svg, 'a fill of its own would outlast the state that set it' );
	}

	/**
	 * Arguments callers used to pass for the directive are ignored.
	 */
	public function test_old_arguments_are_ignored(): void {
		$svg = Petsync_Icons::get_heart_interactive( array(), "state.isFavorited ? 'currentColor' : 'none'", 'currentColor' );

		$this->assertStringNotContainsString( 'data-wp-', $svg );
		$this->assertStringNotContainsString( 'fill="currentColor"', $svg );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function blocks_with_a_heart(): array {
		return array(
			'pet actions'      => array( '<!-- wp:petsync/pet-actions /-->' ),
			'pet card'         => array( '<!-- wp:petsync/pet-card /-->' ),
			'favorites toggle' => array( '<!-- wp:petsync/pet-favorites-toggle /-->' ),
			'listing grid'     => array( '<!-- wp:petsync/pet-listing-grid /-->' ),
			'slider'           => array( '<!-- wp:petsync/pet-slider {"showQuickActions":true} /-->' ),
		);
	}

	/**
	 * @dataProvider blocks_with_a_heart
	 */
	public function test_a_block_with_a_heart_renders_without_a_directive_in_its_svg( string $markup ): void {
		$pet             = $this->make_manual_pet( array( 'post_title' => 'Hazel' ) );
		$GLOBALS['post'] = get_post( $pet ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		setup_postdata( $GLOBALS['post'] );

		$html = do_blocks( $markup );
		wp_reset_postdata();

		$this->assertStringContainsString( 'petsync-heart', $html, 'the block must actually render its heart for this to test anything' );
		preg_match_all( '#<svg\b.*?</svg>#s', $html, $svgs );
		foreach ( $svgs[0] as $svg ) {
			$this->assertStringNotContainsString( 'data-wp-', $svg );
		}
	}
}
