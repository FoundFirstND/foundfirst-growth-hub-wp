<?php
/**
 * Promo banner HTML and the [growthhub_promo] shortcode.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Promo;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the banner. Outputs nothing at all when there is no promo or anything goes wrong.
 *
 * Markup (override with these classes):
 *   .ffgh-promo[.ffgh-promo--has-image]
 *     a.ffgh-promo__link | div.ffgh-promo__inner
 *       img.ffgh-promo__image
 *       span.ffgh-promo__text
 */
final class PromoRenderer {

	public const SHORTCODE = 'growthhub_promo';
	public const STYLE     = 'ffgh-promo';

	/**
	 * Constructor.
	 *
	 * @param PromoService $service Promo source.
	 */
	public function __construct( private PromoService $service ) {}

	/**
	 * Register the shortcode and stylesheet.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		wp_register_style( self::STYLE, FFGH_URL . 'assets/promo.css', array(), FFGH_VERSION );
	}

	/**
	 * [growthhub_promo class="extra-class"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'class' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );

		return $this->render( (string) $atts['class'] );
	}

	/**
	 * Banner HTML (already escaped), or ''.
	 *
	 * @param string $extra_class Extra classes for the wrapper.
	 */
	public function render( string $extra_class = '' ): string {
		try {
			$promo = $this->service->current();
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( null === $promo ) {
			return '';
		}

		wp_enqueue_style( self::STYLE );

		$classes = array( 'ffgh-promo' );
		if ( null !== $promo['image_url'] ) {
			$classes[] = 'ffgh-promo--has-image';
		}
		foreach ( preg_split( '/\s+/', $extra_class, -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			$classes[] = sanitize_html_class( $class );
		}

		$inner = '';
		if ( null !== $promo['image_url'] ) {
			$inner .= sprintf(
				'<img class="ffgh-promo__image" src="%s" alt="" loading="lazy" decoding="async">',
				esc_url( $promo['image_url'] )
			);
		}
		$inner .= sprintf( '<span class="ffgh-promo__text">%s</span>', esc_html( $promo['text'] ) );

		$body = null !== $promo['link']
			? sprintf( '<a class="ffgh-promo__link" href="%s">%s</a>', esc_url( $promo['link'] ), $inner )
			: sprintf( '<div class="ffgh-promo__inner">%s</div>', $inner );

		return sprintf(
			'<div class="%s" role="region" aria-label="%s">%s</div>',
			esc_attr( implode( ' ', array_filter( $classes ) ) ),
			esc_attr__( 'Promotion', 'foundfirst-growth-hub' ),
			$body
		);
	}
}
