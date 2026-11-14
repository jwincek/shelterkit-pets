<?php
/**
 * A pet taxonomy page is the pet archive with that filter applied.
 *
 * Term links point at the archive with the listing grid's own query-string
 * filter. Requests for a term page, whether the old pretty URL search engines
 * indexed or a query-var URL, redirect there. Core sitemaps stop listing pages
 * that only redirect. And the last test checks the grid actually filters by
 * what the redirect hands it.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

use Petsync\Core\Term_Links;

final class TermLinksTest extends PetTestCase {

	private ?string $redirected_to = null;

	private ?int $redirect_status = null;

	private array $get = array();

	public function set_up(): void {
		parent::set_up();

		$this->redirected_to   = null;
		$this->redirect_status = null;
		$this->get             = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved to restore after tests that set filters.

		// Capture a redirect instead of following it: a falsy location makes
		// wp_safe_redirect() return false, so the code under test does not exit.
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		$_GET = $this->get;
		parent::tear_down();
	}

	public function capture_redirect( $location, $status ) {
		$this->redirected_to   = $location;
		$this->redirect_status = $status;
		return false;
	}

	private function archive(): string {
		return get_post_type_archive_link( 'vcps_pet' );
	}

	private function term( string $slug, string $taxonomy ): \WP_Term {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) {
			$term = get_term( wp_insert_term( ucfirst( $slug ), $taxonomy, array( 'slug' => $slug ) )['term_id'], $taxonomy );
		}
		return $term;
	}

	public function test_a_taxonomy_term_links_to_the_archive_with_its_filter(): void {
		$this->assertSame(
			add_query_arg( 'filter_breed', 'beagle', $this->archive() ),
			get_term_link( $this->term( 'beagle', 'vcps_pet_breed' ) )
		);
		$this->assertSame(
			add_query_arg( 'filter_status', 'adopted', $this->archive() ),
			get_term_link( $this->term( 'adopted', 'vcps_pet_status' ) )
		);
		$this->assertSame(
			add_query_arg( 'filter_color', 'black', $this->archive() ),
			get_term_link( $this->term( 'black', 'vcps_pet_color' ) )
		);
	}

	public function test_an_attribute_term_links_to_its_compatibility_filter(): void {
		$this->assertSame(
			add_query_arg( 'compat_goodWithDogs', '1', $this->archive() ),
			get_term_link( $this->term( 'good-with-dogs', 'vcps_pet_attribute' ) )
		);
	}

	/**
	 * The archive is still a better landing page than the theme's generic one.
	 */
	public function test_an_attribute_the_archive_cannot_filter_by_links_to_the_archive(): void {
		$this->assertSame( $this->archive(), get_term_link( $this->term( 'not-a-filter', 'vcps_pet_attribute' ) ) );
	}

	public function test_other_taxonomies_are_left_alone(): void {
		$category = self::factory()->category->create_and_get( array( 'slug' => 'news' ) );

		$this->assertStringNotContainsString( 'post_type=vcps_pet', get_term_link( $category ) );
	}

	public function test_a_term_page_redirects_permanently_to_the_filtered_archive(): void {
		$this->term( 'beagle', 'vcps_pet_breed' );
		$this->go_to( add_query_arg( 'vcps_pet_breed', 'beagle', home_url( '/' ) ) );

		Term_Links::redirect_term_page();

		$this->assertSame( add_query_arg( 'filter_breed', 'beagle', $this->archive() ), $this->redirected_to );
		$this->assertSame( 301, $this->redirect_status );
	}

	/**
	 * Search engines indexed /pet_breed/beagle/. The taxonomy keeps that slug,
	 * so the old URL still resolves, and lands on the archive.
	 */
	public function test_the_old_pretty_url_still_resolves_and_redirects(): void {
		$this->set_permalink_structure( '/%postname%/' );
		\Petsync\Core\CPT_Registry::register_post_types();
		\Petsync\Core\CPT_Registry::register_taxonomies();
		flush_rewrite_rules();

		$this->term( 'beagle', 'vcps_pet_breed' );
		$this->go_to( home_url( '/pet_breed/beagle/' ) );

		$this->assertTrue( is_tax( 'vcps_pet_breed', 'beagle' ), 'the old slug must still route to the taxonomy' );

		Term_Links::redirect_term_page();

		$this->assertSame( add_query_arg( 'filter_breed', 'beagle', $this->archive() ), $this->redirected_to );
	}

	/**
	 * Core's canonical redirect would get to a query-var request first and send
	 * it to the front page: it appends the term link's query string to the
	 * requested URL. It exits after redirecting, so a test cannot run the whole
	 * action and watch. The order is what decides it, so the order is checked.
	 */
	public function test_the_redirect_runs_before_core_canonical_redirect(): void {
		$canonical = has_action( 'template_redirect', 'redirect_canonical' );
		$this->assertIsInt( $canonical, 'core hooks redirect_canonical() by default' );

		$this->assertLessThan( $canonical, has_action( 'template_redirect', array( Term_Links::class, 'redirect_term_page' ) ) );
	}

	/**
	 * Term_Links names its parameters from config, while the grid reads a list
	 * of its own. A link the grid does not read lands on the archive
	 * unfiltered, so every link one can produce must show as an active filter
	 * in the other.
	 */
	public function test_every_term_link_is_a_filter_the_grid_applies(): void {
		require_once PETSYNC_DIR . 'includes/abilities/pets.php';
		$this->make_manual_pet();

		$entity = \Petsync\Core\Config::get_path( 'entities', 'entities.vcps_pet', array() );
		$terms  = array();
		foreach ( $entity['taxonomies'] as $config ) {
			$terms[] = $this->term( 'probe', $config['taxonomy'] );
		}
		foreach ( \Petsync\Abilities\Pets\COMPAT_MAP as $slug ) {
			$terms[] = $this->term( $slug, $entity['attribute_taxonomy'] );
		}

		// Without pretty permalinks the archive link has a query string of its own.
		$archive = array();
		wp_parse_str( (string) wp_parse_url( $this->archive(), PHP_URL_QUERY ), $archive );

		foreach ( $terms as $term ) {
			$query = array();
			wp_parse_str( (string) wp_parse_url( get_term_link( $term ), PHP_URL_QUERY ), $query );
			$filter = array_diff_key( $query, $archive );
			$this->assertCount( 1, $filter, "$term->taxonomy/$term->slug links to a filter" );

			list( $prefix, $key ) = explode( '_', (string) key( $filter ), 2 );

			$_GET = $query;
			$html = do_blocks( '<!-- wp:petsync/pet-listing-grid /-->' );

			$this->assertMatchesRegularExpression(
				sprintf( '/data-filter-type="%s"\s+data-filter-key="%s"/', 'filter' === $prefix ? 'filter' : 'compat', preg_quote( $key, '/' ) ),
				$html,
				"the grid ignores the link for $term->taxonomy/$term->slug"
			);
		}
	}

	public function test_feeds_are_not_redirected(): void {
		$this->term( 'beagle', 'vcps_pet_breed' );
		$this->go_to(
			add_query_arg(
				array(
					'vcps_pet_breed' => 'beagle',
					'feed'           => 'rss2',
				),
				home_url( '/' )
			)
		);

		Term_Links::redirect_term_page();

		$this->assertNull( $this->redirected_to );
	}

	public function test_core_sitemaps_leave_the_taxonomies_out(): void {
		$listed = array_keys( apply_filters( 'wp_sitemaps_taxonomies', get_taxonomies( array( 'public' => true ), 'objects' ) ) );

		$this->assertSame( array(), array_intersect( Term_Links::taxonomies(), $listed ) );
		$this->assertContains( 'category', $listed, 'only this plugin\'s taxonomies are removed' );
	}

	/**
	 * Color had no URL filter before the redirects needed one. Check the grid
	 * honours it, and shows it as a chip the visitor can remove.
	 */
	public function test_the_archive_grid_applies_the_filter_a_redirect_lands_with(): void {
		$black = $this->make_manual_pet( array( 'post_title' => 'Inkwell' ) );
		$white = $this->make_manual_pet( array( 'post_title' => 'Snowdrop' ) );
		wp_set_object_terms( $black, 'black', 'vcps_pet_color' );
		wp_set_object_terms( $white, 'white', 'vcps_pet_color' );

		$_GET['filter_color'] = 'black';
		$html                 = do_blocks( '<!-- wp:petsync/pet-listing-grid /-->' );

		$this->assertStringContainsString( 'Inkwell', $html );
		$this->assertStringNotContainsString( 'Snowdrop', $html );
		$this->assertStringContainsString( 'Color: Black', $html );
	}
}
