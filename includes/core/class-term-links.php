<?php
/**
 * Every pet taxonomy page is the pet archive with that filter applied.
 *
 * The taxonomies are public, so WordPress gave each term a page of its own,
 * rendered through the theme's generic archive template. Nothing in the plugin
 * linked to those pages and nobody designed them, yet core's sitemaps listed
 * every one. The pet archive already filters by each taxonomy, and a filtered
 * archive is the page worth landing on. So:
 *
 * - a term's link is the archive with its filter in the query string, which is
 *   what menus, the admin's View link and SEO plugins then use;
 * - a request for a term page, including an old indexed /pet_breed/beagle/, is
 *   redirected there permanently;
 * - core sitemaps leave the taxonomies out, because their pages only redirect.
 *
 * @package ShelterKit_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Term_Links {

	public static function init(): void {
		add_filter( 'term_link', [ self::class, 'filter_term_link' ], 10, 3 );
		// Ahead of core's redirect_canonical() at 10. Given a query-var request
		// such as /?vcps_pet_breed=beagle, core sees the term link carries a
		// query string and appends it to the URL it was asked for, sending the
		// visitor to /?filter_breed=beagle: the front page, unfiltered.
		add_action( 'template_redirect', [ self::class, 'redirect_term_page' ], 9 );
		add_filter( 'wp_sitemaps_taxonomies', [ self::class, 'exclude_from_sitemaps' ] );
	}

	/**
	 * The taxonomies this plugin registers.
	 *
	 * @return string[]
	 */
	public static function taxonomies(): array {
		return array_keys( (array) Config::get_item( 'taxonomies', 'taxonomies', [] ) );
	}

	/**
	 * The archive showing pets with this term, or null if there is no archive.
	 *
	 * A term the archive cannot filter by still gets the archive: it is a better
	 * landing page than the theme's generic one.
	 */
	public static function archive_url( \WP_Term $term ): ?string {
		$archive = get_post_type_archive_link( 'vcps_pet' );
		if ( ! $archive ) {
			return null;
		}

		$param = self::filter_param( $term );

		return $param ? add_query_arg( $param[0], $param[1], $archive ) : $archive;
	}

	/**
	 * The archive's query-string filter for a term, in the names the listing grid
	 * reads: filter_{key} for a taxonomy, compat_{key} for an attribute.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function filter_param( \WP_Term $term ): ?array {
		$entity = (array) Config::get_path( 'entities', 'entities.vcps_pet', [] );

		foreach ( (array) ( $entity['taxonomies'] ?? [] ) as $key => $config ) {
			if ( ( $config['taxonomy'] ?? '' ) === $term->taxonomy ) {
				return [ 'filter_' . $key, $term->slug ];
			}
		}

		if ( ( $entity['attribute_taxonomy'] ?? '' ) === $term->taxonomy ) {
			$key = array_search( $term->slug, self::compat_map(), true );
			return false === $key ? null : [ 'compat_' . $key, '1' ];
		}

		return null;
	}

	/**
	 * The grid's compatibility keys, by attribute term slug.
	 *
	 * @return array<string, string> camelCase key => term slug.
	 */
	private static function compat_map(): array {
		// The ability callbacks load lazily, and this map lives with them.
		require_once PETSYNC_DIR . 'includes/abilities/pets.php';
		return \Petsync\Abilities\Pets\COMPAT_MAP;
	}

	/**
	 * @param string   $url      The link WordPress built.
	 * @param \WP_Term $term     The term.
	 * @param string   $taxonomy Its taxonomy.
	 */
	public static function filter_term_link( $url, $term, $taxonomy ) {
		if ( ! $term instanceof \WP_Term || ! in_array( $taxonomy, self::taxonomies(), true ) ) {
			return $url;
		}

		return self::archive_url( $term ) ?? $url;
	}

	/**
	 * Send a term page to the filtered archive.
	 *
	 * Feeds are left alone: a feed reader following a redirect to an HTML page
	 * would only break.
	 */
	public static function redirect_term_page(): void {
		if ( is_feed() || ! is_tax( self::taxonomies() ) ) {
			return;
		}

		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$target = self::archive_url( $term );

		// wp_safe_redirect() returns false when a wp_redirect filter cancels it,
		// which is how a test watches this without the request ending.
		if ( $target && wp_safe_redirect( $target, 301 ) ) {
			exit;
		}
	}

	/**
	 * @param array<string, \WP_Taxonomy> $taxonomies Taxonomies core would list.
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function exclude_from_sitemaps( $taxonomies ) {
		return array_diff_key( (array) $taxonomies, array_flip( self::taxonomies() ) );
	}
}
