<?php
/**
 * A pet's description as a spreadsheet cell, and back.
 *
 * A description is HTML: block markup when it was written in the editor, the
 * provider's own HTML when it was synced. Petstablished sends <p> paragraphs
 * with apostrophes encoded as &#039;, which a page shows as an apostrophe and
 * a spreadsheet shows as &#039;. Neither form is something a person editing a
 * sheet should have to read or keep intact.
 *
 * So a description made only of paragraphs travels as plain text: paragraphs
 * separated by a blank line, line breaks as line breaks, entities decoded.
 * Anything plain text cannot hold, such as a list, a heading, a link or a
 * styled paragraph, travels as its markup, untouched, so nothing is lost.
 *
 * Coming back, plain text becomes paragraph blocks, which is the editor's own
 * format. A description written in the editor returns exactly as it left.
 *
 * @package ShelterKit_Pets
 * @since   1.7.0
 */

declare( strict_types = 1 );

namespace Petsync\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Description {

	/**
	 * The cell for a stored description.
	 *
	 * @param string $content post_content.
	 */
	public static function to_cell( string $content ): string {
		return self::to_text( $content ) ?? $content;
	}

	/**
	 * The description to store for a cell.
	 *
	 * Markup that is only paragraphs, such as a file exported before
	 * descriptions travelled as text, is converted like text, so its &#039;
	 * comes in as an apostrophe.
	 *
	 * @param string $cell The cell, as a person left it.
	 */
	public static function from_cell( string $cell ): string {
		$text = self::is_markup( $cell ) ? self::to_text( $cell ) : $cell;

		return null === $text ? wp_kses_post( $cell ) : self::paragraph_blocks( $text );
	}

	/**
	 * A description as plain text, or null when plain text would lose
	 * something.
	 *
	 * @param string $content Block markup, HTML, or text.
	 */
	public static function to_text( string $content ): ?string {
		$html = has_blocks( $content ) ? self::paragraph_html( $content ) : wpautop( $content );
		if ( null === $html ) {
			return null;
		}

		// Unwrapped from its blocks, or run through wpautop(), a description
		// that is only paragraphs is a run of bare <p> elements. Anything left
		// over, including a <p> with attributes, is structure text cannot hold.
		if ( '' !== trim( (string) preg_replace( '#<p>.*?</p>#s', '', $html ) ) ) {
			return null;
		}

		preg_match_all( '#<p>(.*?)</p>#s', $html, $matches );

		$paragraphs = array();
		foreach ( $matches[1] as $inner ) {
			$inner = (string) preg_replace( '#<br\s*/?>\s*#i', "\n", $inner );

			// A link, bold text, or any other markup inside the paragraph.
			if ( self::is_markup( $inner ) ) {
				return null;
			}

			$paragraphs[] = trim( html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		return implode( "\n\n", array_filter( $paragraphs, 'strlen' ) );
	}

	/**
	 * Whether a string carries markup, as opposed to text that happens to
	 * contain a < sign, such as "I <3 walks".
	 *
	 * @param string $value String to test.
	 */
	private static function is_markup( string $value ): bool {
		return has_blocks( $value ) || 1 === preg_match( '#</?[a-z][a-z0-9]*(?:[\s/>]|$)#i', $value );
	}

	/**
	 * The paragraphs' HTML, or null if the content holds any other block, a
	 * paragraph with settings, or classic content between blocks.
	 *
	 * @param string $content Block markup.
	 */
	private static function paragraph_html( string $content ): ?string {
		$html = '';

		foreach ( parse_blocks( $content ) as $block ) {
			if ( null === $block['blockName'] ) {
				if ( '' !== trim( (string) $block['innerHTML'] ) ) {
					return null;
				}
				continue;
			}

			if ( 'core/paragraph' !== $block['blockName'] || ! empty( $block['attrs'] ) || ! empty( $block['innerBlocks'] ) ) {
				return null;
			}

			$html .= trim( (string) $block['innerHTML'] ) . "\n";
		}

		return $html;
	}

	/**
	 * Text as paragraph blocks, serialised the way the editor saves them.
	 *
	 * Entities are decoded first, so an old file's &#039; is stored as an
	 * apostrophe, then only &, < and > are encoded, which is all HTML needs.
	 *
	 * @param string $text Paragraphs separated by blank lines.
	 */
	private static function paragraph_blocks( string $text ): string {
		$text   = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$blocks = array();

		foreach ( preg_split( '/\n[ \t]*\n/', trim( $text ) ) ?: array() as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}

			$html = htmlspecialchars(
				html_entity_decode( $paragraph, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				ENT_NOQUOTES | ENT_SUBSTITUTE,
				'UTF-8'
			);
			$html = str_replace( array( "\u{00A0}", "\n" ), array( '&nbsp;', '<br>' ), $html );

			$blocks[] = "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
		}

		return implode( "\n\n", $blocks );
	}
}
