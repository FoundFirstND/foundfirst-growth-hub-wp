<?php
/**
 * Promo banner HTML and the [growthhub_promo] shortcode.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Promo;

use FoundFirst\GrowthHub\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the banner. Outputs nothing at all when there is no promo or anything goes wrong.
 * The text is always shown. The photo is shown only when "Show photo on the banner" is on in
 * the settings, or the shortcode / widget asks for it; otherwise no <img> is output at all.
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
	 * [growthhub_promo class="extra-class" image="yes|no"]
	 *
	 * Without `image`, the "Show photo on the banner" setting decides.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'class' => '',
				'image' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		return $this->render( (string) $atts['class'], self::parse_image( (string) $atts['image'] ) );
	}

	/**
	 * The shortcode's image="" value: true for yes, false for no, null (use the setting) for
	 * anything else.
	 *
	 * @param string $value Attribute value.
	 */
	public static function parse_image( string $value ): ?bool {
		$value = strtolower( trim( $value ) );

		if ( in_array( $value, array( 'yes', '1', 'true', 'on', 'show' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 'no', '0', 'false', 'off', 'hide' ), true ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Banner HTML (already escaped), or ''.
	 *
	 * @param string    $extra_class Extra classes for the wrapper.
	 * @param bool|null $show_image  Show the photo; null uses the "Show photo on the banner" setting.
	 */
	public function render( string $extra_class = '', ?bool $show_image = null ): string {
		try {
			$promo = $this->service->current();
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( null === $promo ) {
			return '';
		}

		wp_enqueue_style( self::STYLE );

		$image_url = ( $show_image ?? Options::show_promo_image() ) ? $promo['image_url'] : null;

		$classes = array( 'ffgh-promo' );
		if ( null !== $image_url ) {
			$classes[] = 'ffgh-promo--has-image';
		}
		foreach ( preg_split( '/\s+/', $extra_class, -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			$classes[] = sanitize_html_class( $class );
		}

		$inner = '';
		if ( null !== $image_url ) {
			$inner .= sprintf(
				'<img class="ffgh-promo__image" src="%s" alt="" loading="lazy" decoding="async">',
				esc_url( $image_url )
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
