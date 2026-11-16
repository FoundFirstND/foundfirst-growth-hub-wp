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
 * The text is always shown.
 *
 * Photo posts: the photo is shown only when "Show photo on the banner" is on in the settings,
 * or the shortcode / widget asks for it; otherwise no <img> is output at all.
 *
 * Video posts: a <video> player (or, without a video file, the thumbnail linked to the video on
 * Facebook) is always shown, unless the shortcode says image="no" or the widget's Show photo
 * switch was explicitly turned off. A player loads nothing until it's played, unless "Autoplay
 * video" is on (then muted, looping). assets/promo.js swaps in the thumbnail if the video fails.
 *
 * Markup (override with these classes):
 *   .ffgh-promo[.ffgh-promo--has-image][.ffgh-promo--video]
 *     a.ffgh-promo__link | div.ffgh-promo__inner
 *       img.ffgh-promo__image
 *       video.ffgh-promo__video + (a|img).ffgh-promo__video-fallback[hidden]
 *       a.ffgh-promo__video-link > img.ffgh-promo__image + span.ffgh-promo__play
 *       span.ffgh-promo__text | a.ffgh-promo__text-link > span.ffgh-promo__text
 */
final class PromoRenderer {

	public const SHORTCODE = 'growthhub_promo';
	public const STYLE     = 'ffgh-promo';
	public const SCRIPT    = 'ffgh-promo';

	/**
	 * Constructor.
	 *
	 * @param PromoService $service Promo source.
	 */
	public function __construct( private PromoService $service ) {}

	/**
	 * Register the shortcode, stylesheet and the video fallback script.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		wp_register_style( self::STYLE, FFGH_URL . 'assets/promo.css', array(), FFGH_VERSION );
		wp_register_script( self::SCRIPT, FFGH_URL . 'assets/promo.js', array(), FFGH_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * [growthhub_promo class="extra-class" image="yes|no" autoplay="yes|no"]
	 *
	 * Without `image`, the "Show photo on the banner" setting decides for photos; video posts
	 * show unless image="no". `autoplay="yes"` plays a video muted and looping.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'class'    => '',
				'image'    => '',
				'autoplay' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$image    = self::parse_image( (string) $atts['image'] );
		$autoplay = self::parse_image( (string) $atts['autoplay'] );

		return $this->render(
			(string) $atts['class'],
			$image,
			true === $autoplay,
			false === $image ? false : null
		);
	}

	/**
	 * A yes/no attribute: true for yes, false for no, null (use the default) for anything else.
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
	 * @param bool|null $show_image  Show a photo post's photo; null uses the "Show photo on the banner" setting.
	 * @param bool      $autoplay    Play a video muted, looping and without waiting for a click.
	 * @param bool|null $show_video  False hides a video post's player and thumbnail; otherwise they show.
	 */
	public function render( string $extra_class = '', ?bool $show_image = null, bool $autoplay = false, ?bool $show_video = null ): string {
		try {
			$promo = $this->service->current();
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( null === $promo ) {
			return '';
		}

		wp_enqueue_style( self::STYLE );

		$is_video  = 'video' === ( $promo['media_type'] ?? 'photo' );
		$image_url = $promo['image_url'] ?? null;
		$video_url = $promo['video_url'] ?? null;
		$file_url  = $promo['video_file_url'] ?? null;

		if ( $is_video && false !== $show_video && null !== $file_url ) {
			wp_enqueue_script( self::SCRIPT );
			$body    = $this->video_player( $promo, $file_url, $image_url, $video_url, $autoplay );
			$classes = array( 'ffgh-promo', 'ffgh-promo--has-image', 'ffgh-promo--video' );
		} elseif ( $is_video && false !== $show_video && null !== $image_url && null !== $video_url ) {
			$body    = sprintf(
				'<div class="ffgh-promo__inner">%s%s</div>',
				$this->video_link( $promo['text'], $video_url, $image_url, 'ffgh-promo__video-link', false ),
				$this->text( $promo )
			);
			$classes = array( 'ffgh-promo', 'ffgh-promo--has-image', 'ffgh-promo--video' );
		} else {
			// Photo posts (and video posts with only a thumbnail): the banner as before.
			$show      = $is_video ? false !== $show_video : ( $show_image ?? Options::show_promo_image() );
			$image_url = $show ? $image_url : null;
			$classes   = array( 'ffgh-promo' );
			if ( null !== $image_url ) {
				$classes[] = 'ffgh-promo--has-image';
			}

			$inner = '';
			if ( null !== $image_url ) {
				$inner .= self::image( $image_url );
			}
			$inner .= sprintf( '<span class="ffgh-promo__text">%s</span>', esc_html( $promo['text'] ) );

			$body = null !== $promo['link']
				? sprintf( '<a class="ffgh-promo__link" href="%s">%s</a>', esc_url( $promo['link'] ), $inner )
				: sprintf( '<div class="ffgh-promo__inner">%s</div>', $inner );
		}

		foreach ( preg_split( '/\s+/', $extra_class, -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			$classes[] = sanitize_html_class( $class );
		}

		return sprintf(
			'<div class="%s" role="region" aria-label="%s">%s</div>',
			esc_attr( implode( ' ', array_filter( $classes ) ) ),
			esc_attr__( 'Promotion', 'foundfirst-growth-hub' ),
			$body
		);
	}

	/**
	 * The player, a hidden fallback for promo.js, and the caption. The banner isn't one big link
	 * here (clicking play would follow it), so only the caption is linked.
	 *
	 * @param array   $promo     Promo.
	 * @param string  $file_url  Video file URL.
	 * @param ?string $image_url Thumbnail.
	 * @param ?string $video_url The video on Facebook.
	 * @param bool    $autoplay  Muted, looping autoplay.
	 */
	private function video_player( array $promo, string $file_url, ?string $image_url, ?string $video_url, bool $autoplay ): string {
		$attributes = $autoplay ? 'controls muted autoplay loop playsinline' : 'controls playsinline preload="none"';

		$video = sprintf(
			'<video class="ffgh-promo__video" src="%s"%s %s aria-label="%s"></video>',
			esc_url( $file_url ),
			null !== $image_url ? sprintf( ' poster="%s"', esc_url( $image_url ) ) : '',
			$attributes,
			esc_attr( $promo['text'] )
		);

		$fallback = '';
		if ( null !== $image_url && null !== $video_url ) {
			$fallback = $this->video_link( $promo['text'], $video_url, $image_url, 'ffgh-promo__video-link ffgh-promo__video-fallback', true );
		} elseif ( null !== $image_url ) {
			$fallback = sprintf(
				'<img class="ffgh-promo__image ffgh-promo__video-fallback" src="%s" alt="" loading="lazy" decoding="async" hidden>',
				esc_url( $image_url )
			);
		}

		return sprintf( '<div class="ffgh-promo__inner">%s%s%s</div>', $video, $fallback, $this->text( $promo ) );
	}

	/**
	 * The thumbnail with a play badge, linked to the video on Facebook.
	 *
	 * @param string $text      Caption.
	 * @param string $video_url The video on Facebook.
	 * @param string $image_url Thumbnail.
	 * @param string $classes   Link classes.
	 * @param bool   $hidden    Output hidden (the player's fallback).
	 */
	private function video_link( string $text, string $video_url, string $image_url, string $classes, bool $hidden ): string {
		return sprintf(
			'<a class="%s" href="%s" aria-label="%s"%s>%s<span class="ffgh-promo__play" aria-hidden="true"></span></a>',
			esc_attr( $classes ),
			esc_url( $video_url ),
			/* translators: %s: the promo's caption. */
			esc_attr( sprintf( __( 'Watch the video: %s', 'foundfirst-growth-hub' ), $text ) ),
			$hidden ? ' hidden' : '',
			self::image( $image_url )
		);
	}

	/**
	 * The caption, linked when the promo has a link.
	 *
	 * @param array $promo Promo.
	 */
	private function text( array $promo ): string {
		$text = sprintf( '<span class="ffgh-promo__text">%s</span>', esc_html( $promo['text'] ) );

		return null !== $promo['link']
			? sprintf( '<a class="ffgh-promo__text-link" href="%s">%s</a>', esc_url( $promo['link'] ), $text )
			: $text;
	}

	/**
	 * The photo / thumbnail.
	 *
	 * @param string $url Image URL.
	 */
	private static function image( string $url ): string {
		return sprintf( '<img class="ffgh-promo__image" src="%s" alt="" loading="lazy" decoding="async">', esc_url( $url ) );
	}
}
