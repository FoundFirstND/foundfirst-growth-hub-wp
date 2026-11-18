<?php
/**
 * Google reviews HTML and the [growthhub_reviews] shortcode.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the reviews section. Outputs nothing at all when there are no reviews or anything
 * goes wrong. No review schema markup (Google ignores self-served review stars for local
 * businesses).
 *
 * Works without JavaScript: dates show as dates, long reviews show in full, and the carousel is a
 * row you can scroll. assets/reviews.js turns dates into "2 weeks ago" in the browser (so a
 * cached page never shows a stale age), adds Read more, the carousel's buttons and arrow keys,
 * and swaps a photo that fails to load for the reviewer's initials.
 *
 * Markup (override with these classes):
 *   section.ffgh-reviews.ffgh-reviews--{carousel|grid|list}.ffgh-reviews--{light|dark}
 *     header.ffgh-reviews__header
 *       h2.ffgh-reviews__title > svg.ffgh-reviews__google + span
 *       p.ffgh-reviews__summary > .ffgh-reviews__score + .ffgh-stars + .ffgh-reviews__count
 *       a.ffgh-reviews__all
 *     div.ffgh-reviews__controls[hidden] > button.ffgh-reviews__prev + button.ffgh-reviews__next   (carousel)
 *     ul.ffgh-reviews__list > li.ffgh-review
 *       .ffgh-review__head > .ffgh-review__avatar (.ffgh-review__initials, img.ffgh-review__photo)
 *                          + .ffgh-review__meta (.ffgh-review__name, time.ffgh-review__date)
 *       .ffgh-stars.ffgh-stars--{4|5}
 *       .ffgh-review__text[.ffgh-review__text--long] + button.ffgh-review__more[hidden]
 */
final class ReviewsRenderer {

	public const SHORTCODE = 'growthhub_reviews';
	public const STYLE     = 'ffgh-reviews';
	public const SCRIPT    = 'ffgh-reviews';

	public const LAYOUTS = array( 'carousel', 'grid', 'list' );
	public const STYLES  = array( 'light', 'dark' );

	/** Reviews longer than this (characters) get Read more. */
	public const LONG_TEXT = 280;

	/**
	 * Sections rendered in this request, for unique ids.
	 *
	 * @var int
	 */
	private static int $instance = 0;

	/**
	 * Constructor.
	 *
	 * @param ReviewsService $service Reviews source.
	 */
	public function __construct( private ReviewsService $service ) {}

	/**
	 * Register the shortcode, stylesheet and script (enqueued only when reviews are output).
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		wp_register_style( self::STYLE, FFGH_URL . 'assets/reviews.css', array(), FFGH_VERSION );
		wp_register_script( self::SCRIPT, FFGH_URL . 'assets/reviews.js', array(), FFGH_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * [growthhub_reviews layout="carousel|grid|list" count="6" min_rating="5|4" photos="yes|no"
	 * dates="yes|no" style="light|dark" class="extra-class"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'layout'     => 'carousel',
				'count'      => (string) ReviewsService::DEFAULT_COUNT,
				'min_rating' => '5',
				'photos'     => 'yes',
				'dates'      => 'yes',
				'style'      => 'light',
				'class'      => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		return $this->render( self::options( $atts ) );
	}

	/**
	 * Normalise raw options (shortcode attributes or widget settings). Anything unexpected falls
	 * back to the default.
	 *
	 * @param array $raw Raw options.
	 * @return array{layout: string, count: int, min_rating: int, photos: bool, dates: bool, style: string, class: string}
	 */
	public static function options( array $raw ): array {
		$layout = strtolower( trim( (string) ( $raw['layout'] ?? '' ) ) );
		$style  = strtolower( trim( (string) ( $raw['style'] ?? '' ) ) );
		$count  = $raw['count'] ?? null;
		$count  = is_numeric( $count ) ? (int) $count : ReviewsService::DEFAULT_COUNT;

		return array(
			'layout'     => in_array( $layout, self::LAYOUTS, true ) ? $layout : 'carousel',
			'count'      => max( 1, min( ReviewsService::FETCH_LIMIT, $count ) ),
			'min_rating' => ReviewsService::min_rating( rtrim( trim( (string) ( $raw['min_rating'] ?? '5' ) ), '+' ) ),
			'photos'     => self::yes( $raw['photos'] ?? true, true ),
			'dates'      => self::yes( $raw['dates'] ?? true, true ),
			'style'      => in_array( $style, self::STYLES, true ) ? $style : 'light',
			'class'      => (string) ( $raw['class'] ?? '' ),
		);
	}

	/**
	 * Section HTML (already escaped), or ''.
	 *
	 * @param array $options Options from options().
	 */
	public function render( array $options = array() ): string {
		try {
			$options = self::options( $options );
			$feed    = $this->service->get( $options['min_rating'], $options['count'] );

			if ( null === $feed ) {
				return '';
			}

			$html = $this->section( $feed, $options, ++self::$instance );
		} catch ( \Throwable $e ) {
			return '';
		}

		wp_enqueue_style( self::STYLE );
		wp_enqueue_script( self::SCRIPT );

		return $html;
	}

	/**
	 * The whole section.
	 *
	 * @param array $feed    Feed from ReviewsService.
	 * @param array $options Options.
	 * @param int   $n       Instance number.
	 */
	private function section( array $feed, array $options, int $n ): string {
		$id       = 'ffgh-reviews-' . $n;
		$carousel = 'carousel' === $options['layout'];
		$classes  = array( 'ffgh-reviews', 'ffgh-reviews--' . $options['layout'], 'ffgh-reviews--' . $options['style'] );

		foreach ( preg_split( '/\s+/', $options['class'], -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			$classes[] = sanitize_html_class( $class );
		}

		$items = '';
		$total = count( $feed['reviews'] );
		foreach ( $feed['reviews'] as $i => $review ) {
			$items .= $this->card( $review, $options, $id . '-r' . ( $i + 1 ), $carousel ? array( $i + 1, $total ) : null );
		}

		$list = sprintf(
			'<ul class="ffgh-reviews__list" id="%s"%s>%s</ul>',
			esc_attr( $id . '-list' ),
			$carousel ? sprintf( ' tabindex="0" aria-labelledby="%s"', esc_attr( $id . '-title' ) ) : '',
			$items
		);

		if ( $carousel ) {
			$list = sprintf(
				'<div class="ffgh-reviews__carousel" role="region" aria-roledescription="%s" aria-labelledby="%s">%s%s</div>',
				esc_attr__( 'carousel', 'foundfirst-growth-hub' ),
				esc_attr( $id . '-title' ),
				$this->controls( $id ),
				$list
			);
		}

		return sprintf(
			'<section class="%s" id="%s" aria-labelledby="%s">%s%s</section>',
			esc_attr( implode( ' ', array_filter( $classes ) ) ),
			esc_attr( $id ),
			esc_attr( $id . '-title' ),
			$this->header( $feed, $options, $id ),
			$list
		);
	}

	/**
	 * Title, overall rating and the link to Google.
	 *
	 * @param array  $feed    Feed.
	 * @param array  $options Options.
	 * @param string $id      Section id.
	 */
	private function header( array $feed, array $options, string $id ): string {
		$title = 5 === $options['min_rating']
			? __( 'Recent 5-star reviews from Google', 'foundfirst-growth-hub' )
			: __( 'Recent 4+ star reviews from Google', 'foundfirst-growth-hub' );

		$html = sprintf(
			'<h2 class="ffgh-reviews__title" id="%s">%s<span>%s</span></h2>',
			esc_attr( $id . '-title' ),
			self::google_logo(),
			esc_html( $title )
		);

		$summary = '';
		if ( null !== $feed['rating'] ) {
			$summary .= sprintf(
				'<span class="ffgh-reviews__score" aria-hidden="true">%s</span>%s<span class="ffgh-sr">%s</span>',
				esc_html( number_format_i18n( $feed['rating'], 1 ) ),
				sprintf( '<span class="ffgh-stars" style="--ffgh-fill:%s%%" aria-hidden="true"></span>', esc_attr( (string) round( $feed['rating'] / 5 * 100, 1 ) ) ),
				/* translators: %s: average rating, e.g. 4.8. */
				esc_html( sprintf( __( 'Rated %s out of 5 on Google', 'foundfirst-growth-hub' ), number_format_i18n( $feed['rating'], 1 ) ) )
			);
		}
		if ( null !== $feed['review_count'] ) {
			$summary .= sprintf(
				'<span class="ffgh-reviews__count">%s</span>',
				/* translators: %s: total number of Google reviews. */
				esc_html( sprintf( _n( '%s review', '%s reviews', $feed['review_count'], 'foundfirst-growth-hub' ), number_format_i18n( $feed['review_count'] ) ) )
			);
		}
		if ( '' !== $summary ) {
			$html .= '<p class="ffgh-reviews__summary">' . $summary . '</p>';
		}

		if ( null !== $feed['profile_url'] ) {
			$html .= sprintf(
				'<a class="ffgh-reviews__all" href="%s" target="_blank" rel="noopener">%s<span class="ffgh-sr"> %s</span></a>',
				esc_url( $feed['profile_url'] ),
				esc_html__( 'See all reviews on Google', 'foundfirst-growth-hub' ),
				esc_html__( '(opens in a new tab)', 'foundfirst-growth-hub' )
			);
		}

		return '<header class="ffgh-reviews__header">' . $html . '</header>';
	}

	/**
	 * Previous / Next buttons, shown by reviews.js.
	 *
	 * @param string $id Section id.
	 */
	private function controls( string $id ): string {
		return sprintf(
			'<div class="ffgh-reviews__controls" hidden><button type="button" class="ffgh-reviews__prev" aria-controls="%1$s"><span aria-hidden="true">&#8249;</span><span class="ffgh-sr">%2$s</span></button><button type="button" class="ffgh-reviews__next" aria-controls="%1$s"><span aria-hidden="true">&#8250;</span><span class="ffgh-sr">%3$s</span></button></div>',
			esc_attr( $id . '-list' ),
			esc_html__( 'Previous reviews', 'foundfirst-growth-hub' ),
			esc_html__( 'Next reviews', 'foundfirst-growth-hub' )
		);
	}

	/**
	 * One review.
	 *
	 * @param array      $review   Review.
	 * @param array      $options  Options.
	 * @param string     $id       Card id.
	 * @param array|null $position [n, total] in a carousel.
	 */
	private function card( array $review, array $options, string $id, ?array $position ): string {
		$avatar = sprintf( '<span class="ffgh-review__initials">%s</span>', esc_html( $review['initials'] ) );
		if ( $options['photos'] && null !== $review['photo_url'] ) {
			// alt="" because the name is printed right next to it.
			$avatar .= sprintf(
				'<img class="ffgh-review__photo" src="%s" alt="" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer">',
				esc_url( $review['photo_url'] )
			);
		}

		$meta = sprintf( '<span class="ffgh-review__name">%s</span>', esc_html( $review['name'] ) );
		if ( $options['dates'] ) {
			$meta .= sprintf(
				'<time class="ffgh-review__date" datetime="%s">%s</time>',
				esc_attr( $review['date'] ),
				esc_html( self::format_date( $review['date'] ) )
			);
		}

		$long = mb_strlen( $review['text'] ) > self::LONG_TEXT;
		$text = sprintf(
			'<div class="ffgh-review__text%s" id="%s">%s</div>',
			$long ? ' ffgh-review__text--long' : '',
			esc_attr( $id . '-text' ),
			esc_html( $review['text'] )
		);
		if ( $long ) {
			$text .= sprintf(
				'<button type="button" class="ffgh-review__more" aria-expanded="false" aria-controls="%s" data-less="%s" hidden><span class="ffgh-review__more-label">%s</span><span class="ffgh-sr"> %s</span></button>',
				esc_attr( $id . '-text' ),
				esc_attr__( 'Show less', 'foundfirst-growth-hub' ),
				esc_html__( 'Read more', 'foundfirst-growth-hub' ),
				/* translators: %s: reviewer name, e.g. Sam O. */
				esc_html( sprintf( __( '(review by %s)', 'foundfirst-growth-hub' ), $review['name'] ) )
			);
		}

		$slide = '';
		if ( null !== $position ) {
			$slide = sprintf(
				' role="group" aria-roledescription="%s" aria-label="%s"',
				esc_attr__( 'slide', 'foundfirst-growth-hub' ),
				/* translators: 1: position, 2: number of reviews. */
				esc_attr( sprintf( __( 'Review %1$d of %2$d', 'foundfirst-growth-hub' ), $position[0], $position[1] ) )
			);
		}

		return sprintf(
			'<li class="ffgh-review"%s><div class="ffgh-review__head"><span class="ffgh-review__avatar" aria-hidden="true">%s</span><span class="ffgh-review__meta">%s</span></div><span class="ffgh-stars ffgh-stars--%d" aria-hidden="true"></span><span class="ffgh-sr">%s</span>%s</li>',
			$slide,
			$avatar,
			$meta,
			$review['rating'],
			/* translators: %d: star rating, 1 to 5. */
			esc_html( sprintf( __( 'Rated %d out of 5', 'foundfirst-growth-hub' ), $review['rating'] ) ),
			$text
		);
	}

	/**
	 * YYYY-MM-DD in the site's date format. The calendar date is kept as is (no timezone shift).
	 *
	 * @param string $date YYYY-MM-DD.
	 */
	public static function format_date( string $date ): string {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
		if ( false === $day ) {
			return $date;
		}

		$formatted = wp_date( (string) get_option( 'date_format', 'F j, Y' ), $day->getTimestamp(), new \DateTimeZone( 'UTC' ) );

		return is_string( $formatted ) && '' !== $formatted ? $formatted : $date;
	}

	/**
	 * Google's "G" logo, unmodified, for the attribution.
	 */
	private static function google_logo(): string {
		return '<svg class="ffgh-reviews__google" viewBox="0 0 48 48" width="20" height="20" aria-hidden="true" focusable="false">'
			. '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
			. '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
			. '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
			. '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>'
			. '</svg>';
	}

	/**
	 * A yes/no value (shortcode "yes"/"no", Elementor "yes"/"", or a bool).
	 *
	 * @param mixed $value    Value.
	 * @param bool  $fallback For anything unrecognised.
	 */
	private static function yes( $value, bool $fallback ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$value = strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( 'yes', '1', 'true', 'on', 'show' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 'no', '0', 'false', 'off', 'hide', '' ), true ) ) {
			return false;
		}

		return $fallback;
	}
}
