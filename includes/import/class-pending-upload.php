<?php
/**
 * The file a preview showed, held until it is confirmed.
 *
 * Confirming used to mean choosing the file again, because a browser does not
 * keep a file input across a page load. Nothing tied the second file to the
 * first, so what was imported need not be what was previewed. Now the preview
 * keeps the file, and the confirm button imports exactly that one.
 *
 * It is kept in a transient rather than a file: an upload directory is
 * web-reachable, and the sheet holds every animal's details, microchip
 * numbers included. The transient belongs to the user who uploaded it, lasts
 * an hour, and is deleted once the import is done.
 *
 * @package ShelterKit_Pets
 * @since   1.7.0
 */

declare( strict_types = 1 );

namespace Petsync\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Pending_Upload {

	private const PREFIX = 'petsync_import_';

	private const LIFETIME = HOUR_IN_SECONDS;

	/**
	 * Keep a file for a user, and return the token that retrieves it.
	 *
	 * @param int    $user_id User who uploaded it.
	 * @param string $csv     File contents.
	 * @return string|null Token, or null if it could not be kept, such as a
	 *                     file larger than the database accepts in one value.
	 */
	public static function store( int $user_id, string $csv ): ?string {
		$token = wp_generate_password( 20, false );

		return set_transient( self::key( $user_id, $token ), $csv, self::LIFETIME ) ? $token : null;
	}

	/**
	 * The file kept under a token, if it is this user's and has not expired.
	 *
	 * @param int    $user_id User asking.
	 * @param string $token   Token from store().
	 */
	public static function get( int $user_id, string $token ): ?string {
		if ( ! self::is_token( $token ) ) {
			return null;
		}

		$csv = get_transient( self::key( $user_id, $token ) );

		return is_string( $csv ) ? $csv : null;
	}

	/**
	 * Delete a kept file.
	 *
	 * @param int    $user_id User who uploaded it.
	 * @param string $token   Token from store().
	 */
	public static function forget( int $user_id, string $token ): void {
		if ( self::is_token( $token ) ) {
			delete_transient( self::key( $user_id, $token ) );
		}
	}

	/**
	 * The user is part of the key, so one user's token cannot reach another
	 * user's file.
	 *
	 * @param int    $user_id User.
	 * @param string $token   Token.
	 */
	private static function key( int $user_id, string $token ): string {
		return self::PREFIX . $user_id . '_' . $token;
	}

	/**
	 * @param string $token Token from a request.
	 */
	private static function is_token( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]{20}$/', $token );
	}
}
