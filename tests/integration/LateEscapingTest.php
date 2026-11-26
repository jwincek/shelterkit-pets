<?php
/**
 * Output is escaped where it is echoed, and the query string is never printed.
 *
 * From the second WordPress.org review of 2.2.0. It flagged the heart icon's
 * echo, which relied on the SVG being built safely somewhere else, and the
 * comparison page's `?from=`, which printed any URL as a "Continue viewing"
 * link on the shelter's own page. The same review asked for every such case,
 * not just the ones it named, so these cover the whole class:
 *
 *  - every icon is escaped against an SVG allowlist that must not change it;
 *  - the adoption action, which was built as an HTML string and echoed later,
 *    now escapes each value as it prints it, in all three of its modes;
 *  - `?from=` is a pet ID, and the link is that pet's own permalink;
 *  - the query string is read in one place, which takes single values only.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync_Helpers;
use Petsync_Icons;

final class LateEscapingTest extends PetTestCase {

	public function tear_down(): void {
		unset( $_GET['compare'], $_GET['from'], $_GET['filter_animal'] );
		wp_reset_postdata();
		parent::tear_down();
	}

	// ── Icons ────────────────────────────────────────────────────────────────

	/**
	 * The elements and attributes of some markup, as a browser reads them.
	 *
	 * wp_kses() lowercases attribute names, so `viewBox` comes out `viewbox`,
	 * and writes `/>` as ` />`. Neither changes the page: an HTML parser
	 * restores the case of SVG attributes. So the markup is compared as
	 * parsed, not as text.
	 *
	 * @return array<int, array{string, array<string, string|true>}>
	 */
	private static function parsed( string $html ): array {
		$tags      = array();
		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$attributes = array();
			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				$attributes[ $name ] = $processor->get_attribute( $name );
			}
			ksort( $attributes );
			$tags[] = array( ( $processor->is_tag_closer() ? '/' : '' ) . $processor->get_tag(), $attributes );
		}

		return $tags;
	}

	/**
	 * The allowlist must keep everything an icon is made of. Anything it
	 * dropped would vanish silently from the page: a missing viewBox shrinks
	 * the icon, a missing stroke makes it invisible.
	 */
	public function test_escaping_leaves_every_icon_exactly_as_built(): void {
		foreach ( Petsync_Icons::get_available_icons() as $name ) {
			$svg = Petsync_Icons::get(
				$name,
				array(
					'width'        => 16,
					'height'       => 16,
					'class'        => 'some-icon',
					'stroke-width' => 1.5,
				)
			);

			$this->assertNotSame( '', $svg, "$name built nothing" );
			$this->assertSame( self::parsed( $svg ), self::parsed( wp_kses( $svg, Petsync_Icons::allowed_html() ) ), "escaping changed the $name icon" );
		}

		$heart = Petsync_Icons::get_heart_interactive( array( 'class' => 'pet-favorites-toggle__icon' ) );
		$this->assertSame( self::parsed( $heart ), self::parsed( wp_kses( $heart, Petsync_Icons::allowed_html() ) ), 'escaping changed the heart' );
	}

	public function test_render_prints_the_escaped_icon(): void {
		$this->assertSame( self::parsed( Petsync_Icons::get( 'paw' ) ), self::parsed( get_echo( array( Petsync_Icons::class, 'render' ), array( 'paw' ) ) ) );
		$this->assertSame( self::parsed( Petsync_Icons::get_heart_interactive() ), self::parsed( get_echo( array( Petsync_Icons::class, 'render_heart' ) ) ) );
	}

	public function test_the_allowlist_admits_nothing_but_icon_markup(): void {
		$hostile = '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><path d="M0 0" onclick="alert(3)"/><a href="javascript:alert(4)">x</a></svg>';

		$escaped = wp_kses( $hostile, Petsync_Icons::allowed_html() );

		$this->assertStringNotContainsString( 'onload', $escaped );
		$this->assertStringNotContainsString( 'onclick', $escaped );
		$this->assertStringNotContainsString( '<script', $escaped );
		$this->assertStringNotContainsString( '<a', $escaped );
		$svg = new \WP_HTML_Tag_Processor( $escaped );
		$svg->next_tag( 'svg' );
		$this->assertSame( '0 0 24 24', $svg->get_attribute( 'viewbox' ), 'the svg itself stays' );
		$this->assertStringContainsString( '<path d="M0 0"', $escaped );
	}

	// ── The adoption action ─────────────────────────────────────────────────

	private function render_action( int $pet, array $attributes ): string {
		$GLOBALS['post'] = get_post( $pet ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		setup_postdata( $GLOBALS['post'] );

		return do_blocks( '<!-- wp:petsync/adoption-action ' . wp_json_encode( $attributes ) . ' /-->' );
	}

	private function available_pet( array $meta = array() ): int {
		$pet = $this->make_manual_pet( array( 'post_title' => 'Juniper' ) );
		wp_set_object_terms( $pet, 'Available', 'vcps_pet_status' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $pet, $this->prefix . $key, $value );
		}

		return $pet;
	}

	public function test_the_provider_form_opens_in_a_new_tab(): void {
		$pet  = $this->available_pet( array( 'adoption_form_url' => 'https://forms.example.org/apply?pet=1&x="y"' ) );
		$html = $this->render_action( $pet, array( 'buttonText' => 'Apply for <Juniper> & co' ) );

		$this->assertStringContainsString( 'href="https://forms.example.org/apply?pet=1&#038;x=y"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( '<span>Apply for &lt;Juniper&gt; &amp; co</span>', $html );
		$this->assertMatchesRegularExpression( '#</span>\s*<svg#', $html, 'the icon follows the text' );
	}

	public function test_a_page_link_stays_in_the_same_tab(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'How to adopt',
			)
		);
		$html = $this->render_action(
			$this->available_pet(),
			array(
				'formMode' => 'page',
				'pageId'   => $page,
			)
		);

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $page ) ) . '"', $html );
		$this->assertStringNotContainsString( 'target=', $html );
		$this->assertStringContainsString( 'View Adoption Resources', $html );
	}

	public function test_an_unpublished_page_gives_no_button(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$html = $this->render_action(
			$this->available_pet(),
			array(
				'formMode' => 'page',
				'pageId'   => $page,
			)
		);

		$this->assertSame( '', trim( $html ) );
	}

	public function test_a_pdf_downloads_with_its_name_and_size(): void {
		$file = wp_upload_bits( 'adoption-form.pdf', null, str_repeat( 'x', 2048 ) );
		$pdf  = self::factory()->attachment->create_object(
			$file['file'],
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);

		try {
			$html = $this->render_action(
				$this->available_pet(),
				array(
					'formMode'        => 'pdf',
					'pdfAttachmentId' => $pdf,
				)
			);
		} finally {
			wp_delete_file( $file['file'] );
		}

		$this->assertStringContainsString( 'download', $html );
		$this->assertStringContainsString( basename( $file['file'] ), $html );
		$this->assertStringContainsString( '(2.0 KB)', $html );
		$this->assertStringNotContainsString( 'target=', $html );
		$this->assertMatchesRegularExpression( '#<svg[^>]*>.*?</svg>\s*<span>Download Adoption Application</span>#s', $html, 'the icon leads the text' );
	}

	/**
	 * A PDF whose file is not on this server, as with offloaded media, still
	 * gives a button. It used to print a warning and an empty "()".
	 */
	public function test_a_pdf_not_on_this_server_leaves_out_the_size(): void {
		$pdf = self::factory()->attachment->create_object(
			'offloaded-form.pdf',
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);

		$html = $this->render_action(
			$this->available_pet(),
			array(
				'formMode'        => 'pdf',
				'pdfAttachmentId' => $pdf,
			)
		);

		$this->assertStringContainsString( 'offloaded-form.pdf', $html );
		$this->assertStringNotContainsString( 'pet-adoption-cta__file-size', $html );
	}

	// ── The comparison page's link back ──────────────────────────────────────

	private function render_comparison( string $from ): string {
		$a = $this->make_manual_pet( array( 'post_title' => 'Biscuit' ) );
		$b = $this->make_manual_pet( array( 'post_title' => 'Pepper' ) );

		$_GET['compare'] = "$a,$b";
		$_GET['from']    = $from;

		return do_blocks( '<!-- wp:petsync/pet-comparison /-->' );
	}

	public function test_the_link_back_goes_to_the_pet_named_by_id(): void {
		$came_from = $this->make_manual_pet( array( 'post_title' => 'Clementine' ) );

		$html = $this->render_comparison( (string) $came_from );

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $came_from ) ) . '"', $html );
		$this->assertStringContainsString( 'Continue viewing Clementine', $html );
	}

	/**
	 * @dataProvider not_a_published_pet
	 *
	 * @param string $from What arrives in ?from=.
	 */
	public function test_anything_but_a_published_pet_gives_no_link_back( string $from ): void {
		$from = match ( $from ) {
			'a draft pet' => (string) $this->make_manual_pet( array( 'post_status' => 'draft' ) ),
			'a page'      => (string) self::factory()->post->create( array( 'post_type' => 'page' ) ),
			default       => $from,
		};

		// The global post is a pet, as it is on the archive: get_post( 0 )
		// would return it.
		$GLOBALS['post'] = get_post( $this->make_manual_pet( array( 'post_title' => 'Global Pet' ) ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.

		$html = $this->render_comparison( $from );

		$this->assertStringContainsString( 'pet-comparison__footer', $html, 'the page must render for this to test anything' );
		$this->assertStringNotContainsString( 'pet-comparison__back-link--pet', $html );
		$this->assertStringNotContainsString( 'evil.example', $html );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function not_a_published_pet(): array {
		return array(
			'another site'           => array( 'https://evil.example/phish' ),
			'a URL on this site'     => array( 'http://example.org/?p=1' ),
			'nothing'                => array( '' ),
			'zero'                   => array( '0' ),
			'a negative ID'          => array( '-1' ),
			'a draft pet'            => array( 'a draft pet' ),
			'a page'                 => array( 'a page' ),
			'an ID that is not there' => array( '999999' ),
		);
	}

	// ── The query string ────────────────────────────────────────────────────

	public function test_the_query_string_yields_single_sanitized_values_only(): void {
		$_GET['filter_animal'] = wp_slash( "Dog's <b>best</b>" );
		$this->assertSame( "Dog's best", Petsync_Helpers::query_param( 'filter_animal' ) );

		$_GET['filter_animal'] = array( 'Dog', 'Cat' );
		$this->assertSame( '', Petsync_Helpers::query_param( 'filter_animal' ) );
		$this->assertTrue( Petsync_Helpers::has_query_param( 'filter_animal' ) );

		unset( $_GET['filter_animal'] );
		$this->assertSame( '', Petsync_Helpers::query_param( 'filter_animal' ) );
		$this->assertFalse( Petsync_Helpers::has_query_param( 'filter_animal' ) );
	}
}
