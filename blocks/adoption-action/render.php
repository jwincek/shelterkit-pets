<?php
/**
 * Pet Adoption Action Block — Button or PDF download
 *
 * Renders the adoption application action for a pet. Supports three modes:
 *   - petstablished: links to the Petstablished adoption form URL (per-pet, from API)
 *   - pdf: renders a download link for an editor-selected PDF from the media library
 *   - page: links to an editor-selected internal page (e.g. Adoption Resources)
 *
 * @package ShelterKit_Pets
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = $block->context['postId'] ?? get_the_ID();

if ( ! $post_id || 'vcps_pet' !== get_post_type( $post_id ) ) {
	return;
}

$pet = petsync_get_pet( (int) $post_id );
if ( ! $pet ) {
	return;
}

// Only an available pet takes applications. A pending, on-hold or adopted pet
// used to offer one anyway; it now says why it does not.
if ( ! \Petsync\Core\Pet_Visibility::takes_applications( $pet ) ) {
	printf(
		'<div %1$s><p class="pet-adoption-cta__status">%2$s</p></div>',
		get_block_wrapper_attributes( array( 'class' => 'pet-adoption-cta__actions pet-adoption-cta__actions--closed' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML.
		esc_html(
			sprintf(
				/* translators: %s: the pet's status, such as Adoption Pending. */
				__( '%s: not taking applications right now.', 'shelterkit-pets' ),
				(string) ( $pet['status'] ?? '' )
			)
		)
	);
	return;
}

$form_mode = $attributes['formMode'] ?? 'petstablished';

// What the button does, worked out first and escaped only where it is output.
// The download link adds the file's name and size below it.
$action = null;

if ( $form_mode === 'pdf' ) {
	$pdf_id   = (int) ( $attributes['pdfAttachmentId'] ?? 0 );
	$pdf_url  = $pdf_id && get_post( $pdf_id ) ? wp_get_attachment_url( $pdf_id ) : '';
	$pdf_file = $pdf_url ? get_attached_file( $pdf_id ) : '';

	if ( $pdf_url && $pdf_file ) {
		$action = array(
			'url'      => $pdf_url,
			'text'     => $attributes['pdfButtonText'] ?? __( 'Download Adoption Application', 'shelterkit-pets' ),
			'icon'     => 'download',
			'filename' => basename( $pdf_file ),
			// Empty where the file is not on this server, as with offloaded media.
			'filesize' => file_exists( $pdf_file ) ? (string) size_format( filesize( $pdf_file ), 1 ) : '',
		);
	}
} elseif ( $form_mode === 'page' ) {
	$page_id  = (int) ( $attributes['pageId'] ?? 0 );
	$page     = $page_id ? get_post( $page_id ) : null;
	$page_url = $page && 'publish' === $page->post_status ? get_permalink( $page ) : '';

	if ( $page_url ) {
		$action = array(
			'url'  => $page_url,
			'text' => $attributes['pageButtonText'] ?? __( 'View Adoption Resources', 'shelterkit-pets' ),
			'icon' => 'arrow-right',
		);
	}
} elseif ( ! empty( $pet['adoption_form_url'] ) ) {
	$action = array(
		'url'      => $pet['adoption_form_url'],
		'text'     => $attributes['buttonText'] ?? __( 'Start Adoption Application', 'shelterkit-pets' ),
		'icon'     => 'external-link',
		'external' => true,
	);
}

if ( ! $action ) {
	return;
}

$icon_size = array(
	'width'  => 18,
	'height' => 18,
);

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'pet-adoption-cta__actions',
	)
);
?>
<div <?php echo $wrapper_attributes; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped HTML. */ ?>>
	<?php if ( isset( $action['filename'] ) ) : ?>
		<div class="pet-adoption-cta__file">
			<a
				href="<?php echo esc_url( $action['url'] ); ?>"
				class="pet-adoption-cta__action-btn"
				download
			>
				<?php Petsync_Icons::render( $action['icon'], $icon_size ); ?>
				<span><?php echo esc_html( $action['text'] ); ?></span>
			</a>
			<span class="pet-adoption-cta__file-meta">
				<?php echo esc_html( $action['filename'] ); ?>
				<?php if ( '' !== $action['filesize'] ) : ?>
					<span class="pet-adoption-cta__file-size">(<?php echo esc_html( $action['filesize'] ); ?>)</span>
				<?php endif; ?>
			</span>
		</div>
	<?php else : ?>
		<a
			href="<?php echo esc_url( $action['url'] ); ?>"
			class="pet-adoption-cta__action-btn"
			<?php if ( ! empty( $action['external'] ) ) : ?>
				target="_blank"
				rel="noopener noreferrer"
			<?php endif; ?>
		>
			<span><?php echo esc_html( $action['text'] ); ?></span>
			<?php Petsync_Icons::render( $action['icon'], $icon_size ); ?>
		</a>
	<?php endif; ?>
</div>
