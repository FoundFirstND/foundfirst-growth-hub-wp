<?php
/**
 * ReviewsRenderer tests.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use Brain\Monkey\Functions;
use FoundFirst\GrowthHub\Api\HttpClient;
use FoundFirst\GrowthHub\Api\Response;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Reviews\ReviewsRenderer;
use FoundFirst\GrowthHub\Reviews\ReviewsService;
use FoundFirst\GrowthHub\Tests\Fakes\FakeHttpClient;
use FoundFirst\GrowthHub\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReviewsRendererTest extends TestCase {

	private FakeHttpClient $http;
	private ReviewsRenderer $renderer;

	/** @var string[] */
	private array $styles = array();

	/** @var string[] */
	private array $scripts = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options[ Options::SITE_KEY ] = 'ghk_secret_site_key_123';
		$this->options['date_format']       = 'F j, Y';
		$this->http                         = new FakeHttpClient();
		$this->renderer                     = new ReviewsRenderer( new ReviewsService( $this->http ) );

		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle ) {
				$this->styles[] = $handle;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->scripts[] = $handle;
			}
		);
		Functions\when( 'sanitize_html_class' )->alias( fn ( $class ) => preg_replace( '/[^A-Za-z0-9_-]/', '', $class ) );
		Functions\when( 'shortcode_atts' )->alias(
			fn ( $defaults, $atts ) => array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) )
		);
		Functions\when( 'number_format_i18n' )->alias( fn ( $number, $decimals = 0 ) => number_format( (float) $number, $decimals ) );
		Functions\when( '_n' )->alias( fn ( $single, $plural, $number ) => 1 === $number ? $single : $plural );
		Functions\when( 'wp_date' )->alias( fn ( $format, $timestamp, $tz = null ) => ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format ) );
	}

	private function serve( array $feed ): void {
		$this->http->push( new Response( 200, array( 'data' => $feed ) ) );
	}

	public function test_full_section_markup(): void {
		$this->serve( ReviewsServiceTest::feed() );

		$html = $this->renderer->render();

		$this->assertMatchesRegularExpression( '#^<section class="ffgh-reviews ffgh-reviews--carousel ffgh-reviews--light" id="(ffgh-reviews-\d+)" aria-labelledby="\1-title">#', $html );
		$this->assertStringContainsString( '<span>Recent 5-star reviews from Google</span></h2>', $html );
		$this->assertStringContainsString( 'class="ffgh-reviews__google"', $html );
		$this->assertStringContainsString( '<span class="ffgh-reviews__score" aria-hidden="true">4.8</span><span class="ffgh-stars" style="--ffgh-fill:96%" aria-hidden="true"></span><span class="ffgh-sr">Rated 4.8 out of 5 on Google</span>', $html );
		$this->assertStringContainsString( '<span class="ffgh-reviews__count">132 reviews</span>', $html );
		$this->assertStringContainsString( '<a class="ffgh-reviews__all" href="https://www.google.com/maps/place/?q=place_id:ChIJ1" target="_blank" rel="noopener">See all reviews on Google<span class="ffgh-sr"> (opens in a new tab)</span></a>', $html );
		$this->assertStringContainsString( '<img class="ffgh-review__photo" src="https://lh3.googleusercontent.com/a/sam" alt="" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer">', $html );
		$this->assertStringContainsString( '<span class="ffgh-review__initials">SO</span>', $html );
		$this->assertStringContainsString( '<span class="ffgh-review__name">Sam O.</span><time class="ffgh-review__date" datetime="2026-11-04">November 4, 2026</time>', $html );
		$this->assertStringContainsString( '<span class="ffgh-stars ffgh-stars--5" aria-hidden="true"></span><span class="ffgh-sr">Rated 5 out of 5</span>', $html );
		$this->assertStringContainsString( 'Great trip, we limited out!</div>', $html );
		$this->assertStringNotContainsString( 'writereview', $html, 'the "write a review" link is not the profile link' );
		$this->assertSame( array( ReviewsRenderer::STYLE ), $this->styles );
		$this->assertSame( array( ReviewsRenderer::SCRIPT ), $this->scripts );
	}

	public function test_carousel_is_a_labelled_keyboard_region_with_slides(): void {
		$this->serve( ReviewsServiceTest::feed( array(), array( ReviewsServiceTest::review(), ReviewsServiceTest::review( array( 'name' => 'Chen' ) ) ) ) );

		$html = $this->renderer->render( array( 'layout' => 'carousel' ) );
		preg_match( '#id="(ffgh-reviews-\d+)"#', $html, $m );
		$id = $m[1];

		$this->assertStringContainsString( '<div class="ffgh-reviews__carousel" role="region" aria-roledescription="carousel" aria-labelledby="' . $id . '-title">', $html );
		$this->assertStringContainsString( '<div class="ffgh-reviews__controls" hidden><button type="button" class="ffgh-reviews__prev" aria-controls="' . $id . '-list">', $html );
		$this->assertStringContainsString( '<span class="ffgh-sr">Previous reviews</span>', $html );
		$this->assertStringContainsString( '<span class="ffgh-sr">Next reviews</span>', $html );
		$this->assertStringContainsString( '<ul class="ffgh-reviews__list" id="' . $id . '-list" tabindex="0" aria-labelledby="' . $id . '-title">', $html );
		$this->assertStringContainsString( '<li class="ffgh-review" role="group" aria-roledescription="slide" aria-label="Review 1 of 2">', $html );
		$this->assertStringContainsString( 'aria-label="Review 2 of 2"', $html );
	}

	#[DataProvider( 'static_layouts' )]
	public function test_grid_and_list_have_no_carousel_parts( string $layout ): void {
		$this->serve( ReviewsServiceTest::feed() );

		$html = $this->renderer->render( array( 'layout' => $layout ) );

		$this->assertStringContainsString( 'ffgh-reviews--' . $layout, $html );
		$this->assertStringNotContainsString( 'ffgh-reviews__carousel', $html );
		$this->assertStringNotContainsString( 'ffgh-reviews__controls', $html );
		$this->assertStringNotContainsString( 'tabindex', $html );
		$this->assertStringNotContainsString( 'role="group"', $html );
	}

	public static function static_layouts(): array {
		return array(
			'grid' => array( 'grid' ),
			'list' => array( 'list' ),
		);
	}

	public function test_four_star_header_and_dark_style(): void {
		$this->serve( ReviewsServiceTest::feed( array(), array( ReviewsServiceTest::review( array( 'rating' => 4 ) ) ) ) );

		$html = $this->renderer->render(
			array(
				'min_rating' => '4+',
				'style'      => 'dark',
			)
		);

		$this->assertStringContainsString( '/reviews?min_rating=4&limit=20', $this->http->requests[0]['path'] );
		$this->assertStringContainsString( 'ffgh-reviews--dark', $html );
		$this->assertStringContainsString( '<span>Recent 4+ star reviews from Google</span>', $html );
		$this->assertStringContainsString( 'ffgh-stars--4', $html );
	}

	public function test_photos_and_dates_can_be_turned_off(): void {
		$this->serve( ReviewsServiceTest::feed() );

		$html = $this->renderer->render(
			array(
				'photos' => 'no',
				'dates'  => 'no',
			)
		);

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'googleusercontent', $html );
		$this->assertStringNotContainsString( '<time', $html );
		$this->assertStringContainsString( '<span class="ffgh-review__initials">SO</span>', $html, 'the letter avatar stays' );
	}

	public function test_no_photo_url_shows_only_initials(): void {
		$this->serve(
			ReviewsServiceTest::feed(
				array(),
				array(
					ReviewsServiceTest::review(
						array(
							'photo_url' => null,
							'name'      => 'A Google user',
							'initials'  => 'G',
						)
					),
				)
			)
		);

		$html = $this->renderer->render();

		$this->assertStringContainsString( '<span class="ffgh-review__avatar" aria-hidden="true"><span class="ffgh-review__initials">G</span></span>', $html );
	}

	public function test_missing_rating_count_and_profile_are_left_out(): void {
		$this->serve(
			ReviewsServiceTest::feed(
				array(
					'rating'       => null,
					'review_count' => null,
					'profile_url'  => null,
				)
			)
		);

		$html = $this->renderer->render();

		$this->assertStringNotContainsString( 'ffgh-reviews__summary', $html );
		$this->assertStringNotContainsString( 'ffgh-reviews__all', $html );
		$this->assertStringContainsString( 'Recent 5-star reviews from Google', $html );
	}

	public function test_one_review_in_total_is_singular(): void {
		$this->serve( ReviewsServiceTest::feed( array( 'review_count' => 1 ) ) );

		$this->assertStringContainsString( '<span class="ffgh-reviews__count">1 review</span>', $this->renderer->render() );
	}

	public function test_text_and_names_are_escaped(): void {
		$this->serve(
			ReviewsServiceTest::feed(
				array(),
				array(
					ReviewsServiceTest::review(
						array(
							'name'     => 'Bo <img src=x onerror=alert(1)>',
							'initials' => '<b',
							'text'     => 'Great <script>alert(1)</script> & "fun"',
						)
					),
				)
			)
		);

		$html = $this->renderer->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<b<', $html );
		$this->assertStringContainsString( 'Great &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;fun&quot;', $html );
	}

	public function test_long_reviews_get_a_hidden_read_more_button(): void {
		$long = str_repeat( 'Wonderful service. ', 20 );
		$this->serve( ReviewsServiceTest::feed( array(), array( ReviewsServiceTest::review( array( 'text' => $long ) ), ReviewsServiceTest::review() ) ) );

		$html = $this->renderer->render();
		preg_match( '#id="(ffgh-reviews-\d+-r1-text)"#', $html, $m );

		$this->assertStringContainsString( '<div class="ffgh-review__text ffgh-review__text--long" id="' . $m[1] . '">', $html );
		$this->assertStringContainsString( '<button type="button" class="ffgh-review__more" aria-expanded="false" aria-controls="' . $m[1] . '" data-less="Show less" hidden><span class="ffgh-review__more-label">Read more</span><span class="ffgh-sr"> (review by Sam O.)</span></button>', $html );
		$this->assertSame( 1, substr_count( $html, 'ffgh-review__more"' ), 'only the long one' );
		$this->assertStringContainsString( trim( $long ), $html, 'full text is in the page for no-JS and search' );
	}

	public function test_ids_are_unique_per_section(): void {
		$this->serve( ReviewsServiceTest::feed() );

		$first  = $this->renderer->render();
		$second = $this->renderer->render(); // Same cache, second widget on the page.

		preg_match( '#id="(ffgh-reviews-\d+)"#', $first, $a );
		preg_match( '#id="(ffgh-reviews-\d+)"#', $second, $b );
		$this->assertNotSame( $a[1], $b[1] );
	}

	#[DataProvider( 'nothing_to_show' )]
	public function test_renders_nothing_and_loads_nothing( ?Response $response, string $key = 'ghk_secret_site_key_123' ): void {
		$this->options[ Options::SITE_KEY ] = $key;
		if ( null !== $response ) {
			$this->http->push( $response );
		}

		$this->assertSame( '', $this->renderer->render() );
		$this->assertSame( array(), $this->styles );
		$this->assertSame( array(), $this->scripts );
	}

	public static function nothing_to_show(): array {
		return array(
			'data null'   => array( new Response( 200, array( 'data' => null ) ) ),
			'no reviews'  => array( new Response( 200, array( 'data' => ReviewsServiceTest::feed( array(), array() ) ) ) ),
			'unreachable' => array( Response::transport_error( 'timed out' ) ),
			'401'         => array( new Response( 401, array( 'message' => 'Invalid or revoked site key.' ) ) ),
			'no site key' => array( null, '' ),
		);
	}

	public function test_an_exception_renders_nothing(): void {
		$http     = new class() implements HttpClient {
			public function request( string $method, string $path, ?array $body, int $timeout ): Response {
				throw new \RuntimeException( 'boom' );
			}
		};
		$renderer = new ReviewsRenderer( new ReviewsService( $http ) );

		$this->assertSame( '', $renderer->render() );
		$this->assertSame( '', $renderer->shortcode( array( 'layout' => 'grid' ) ) );
	}

	public function test_no_schema_markup_and_no_site_key(): void {
		$this->serve( ReviewsServiceTest::feed() );

		$html = $this->renderer->render();

		$this->assertStringNotContainsString( 'application/ld+json', $html );
		$this->assertStringNotContainsString( 'itemprop', $html );
		$this->assertStringNotContainsString( 'itemscope', $html );
		$this->assertStringNotContainsString( 'schema.org', $html );
		$this->assertStringNotContainsString( 'ghk_', $html );
	}

	public function test_shortcode_attributes(): void {
		$this->serve( ReviewsServiceTest::feed( array(), array_fill( 0, 5, ReviewsServiceTest::review() ) ) );

		$html = $this->renderer->shortcode(
			array(
				'layout' => 'list',
				'count'  => '2',
				'style'  => 'dark',
				'photos' => 'no',
				'class'  => 'home-reviews bad"class',
			)
		);

		$this->assertStringStartsWith( '<section class="ffgh-reviews ffgh-reviews--list ffgh-reviews--dark home-reviews badclass"', $html );
		$this->assertSame( 2, substr_count( $html, '<li class="ffgh-review"' ) );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_options_defaults_and_bad_values(): void {
		$this->assertSame(
			array(
				'layout'     => 'carousel',
				'count'      => 6,
				'min_rating' => 5,
				'photos'     => true,
				'dates'      => true,
				'style'      => 'light',
				'class'      => '',
			),
			ReviewsRenderer::options( array() )
		);

		$odd = ReviewsRenderer::options(
			array(
				'layout'     => 'slider',
				'count'      => 'lots',
				'min_rating' => '3',
				'photos'     => '',
				'dates'      => 'maybe',
				'style'      => 'neon',
			)
		);
		$this->assertSame( 'carousel', $odd['layout'] );
		$this->assertSame( 6, $odd['count'] );
		$this->assertSame( 5, $odd['min_rating'] );
		$this->assertFalse( $odd['photos'], 'an Elementor switcher that is off is ""' );
		$this->assertTrue( $odd['dates'] );
		$this->assertSame( 'light', $odd['style'] );

		$this->assertSame( 20, ReviewsRenderer::options( array( 'count' => '99' ) )['count'] );
		$this->assertSame( 1, ReviewsRenderer::options( array( 'count' => '-3' ) )['count'] );
		$this->assertSame( 4, ReviewsRenderer::options( array( 'min_rating' => 4 ) )['min_rating'] );
	}

	public function test_format_date_keeps_the_calendar_day(): void {
		$this->options['date_format'] = 'j M Y';

		$this->assertSame( '1 Jan 2026', ReviewsRenderer::format_date( '2026-01-01' ) );
		$this->assertSame( 'nonsense', ReviewsRenderer::format_date( 'nonsense' ) );
	}
}
