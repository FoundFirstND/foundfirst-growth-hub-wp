<?php
/**
 * ReviewsService tests.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use FoundFirst\GrowthHub\Api\Response;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Reviews\ReviewsService;
use FoundFirst\GrowthHub\Tests\Fakes\FakeHttpClient;
use FoundFirst\GrowthHub\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReviewsServiceTest extends TestCase {

	private const NOW = 1_795_000_000; // 2026-11-19.

	private FakeHttpClient $http;
	private int $now = self::NOW;
	private ReviewsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->options[ Options::SITE_KEY ] = 'ghk_test';
		$this->http                         = new FakeHttpClient();
		$this->service                      = new ReviewsService( $this->http, fn (): int => $this->now );
	}

	public static function review( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'          => 'Sam O.',
				'initials'      => 'SO',
				'photo_url'     => 'https://lh3.googleusercontent.com/a/sam',
				'rating'        => 5,
				'text'          => 'Great trip, we limited out!',
				'date'          => '2026-11-04',
				'relative_date' => '2 weeks ago',
			),
			$overrides
		);
	}

	public static function feed( array $overrides = array(), ?array $reviews = null ): array {
		return array_merge(
			array(
				'rating'       => 4.8,
				'review_count' => 132,
				'review_url'   => 'https://search.google.com/local/writereview?placeid=ChIJ1',
				'profile_url'  => 'https://www.google.com/maps/place/?q=place_id:ChIJ1',
				'reviews'      => $reviews ?? array( self::review() ),
			),
			$overrides
		);
	}

	private static function ok( ?array $data ): Response {
		return new Response( 200, array( 'data' => $data ) );
	}

	public function test_fetches_twenty_at_the_minimum_and_trims_to_the_count(): void {
		$reviews = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$reviews[] = self::review( array( 'name' => "Reviewer {$i}" ) );
		}
		$this->http->push( self::ok( self::feed( array(), $reviews ) ) );

		$feed = $this->service->get( 4, 3 );

		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
		$this->assertSame( '/reviews?min_rating=4&limit=20', $this->http->requests[0]['path'] );
		$this->assertSame( 3, $this->http->requests[0]['timeout'] );
		$this->assertSame( array( 'Reviewer 1', 'Reviewer 2', 'Reviewer 3' ), array_column( $feed['reviews'], 'name' ) );
		$this->assertSame( 4.8, $feed['rating'] );
		$this->assertSame( 132, $feed['review_count'] );
		$this->assertSame( 'https://www.google.com/maps/place/?q=place_id:ChIJ1', $feed['profile_url'] );
		$this->assertArrayNotHasKey( 'relative_date', $feed['reviews'][0] );
	}

	public function test_a_fresh_cache_makes_no_request_and_other_counts_share_it(): void {
		$this->http->push( self::ok( self::feed() ) );

		$this->service->get( 5, 6 );
		$this->service->get( 5, 1 );
		$this->service->get( 5, 20 );

		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 3600, $this->transients['ffgh_reviews_5'][1] );
		$this->assertSame( self::NOW, $this->options[ Options::REVIEWS_LAST . '5' ]['fetched_at'] );
	}

	public function test_each_minimum_rating_has_its_own_cache(): void {
		$this->http->push( self::ok( self::feed() ), self::ok( self::feed() ) );

		$this->service->get( 5, 6 );
		$this->service->get( 4, 6 );

		$this->assertSame( '/reviews?min_rating=5&limit=20', $this->http->requests[0]['path'] );
		$this->assertSame( '/reviews?min_rating=4&limit=20', $this->http->requests[1]['path'] );
	}

	#[DataProvider( 'odd_parameters' )]
	public function test_parameters_are_kept_within_the_api_limits( int $min, int $count, string $path, int $expected_count ): void {
		$reviews = array_fill( 0, 20, self::review() );
		$this->http->push( self::ok( self::feed( array(), $reviews ) ) );

		$feed = $this->service->get( $min, $count );

		$this->assertSame( $path, $this->http->requests[0]['path'] );
		$this->assertCount( $expected_count, $feed['reviews'] );
	}

	public static function odd_parameters(): array {
		return array(
			'3 stars becomes 5' => array( 3, 6, '/reviews?min_rating=5&limit=20', 6 ),
			'0 becomes 1'       => array( 5, 0, '/reviews?min_rating=5&limit=20', 1 ),
			'50 becomes 20'     => array( 4, 50, '/reviews?min_rating=4&limit=20', 20 ),
		);
	}

	public function test_data_null_shows_nothing_and_forgets_the_last_good_copy(): void {
		$this->options[ Options::REVIEWS_LAST . '5' ] = array(
			'feed'       => self::feed(),
			'fetched_at' => self::NOW - 60,
		);
		$this->http->push( self::ok( null ) );

		$this->assertNull( $this->service->get( 5, 6 ) );
		$this->assertArrayNotHasKey( Options::REVIEWS_LAST . '5', $this->options );
		$this->assertSame( array( array( 'feed' => null ), 3600 ), $this->transients['ffgh_reviews_5'] );
	}

	public function test_no_reviews_shows_nothing(): void {
		$this->http->push( self::ok( self::feed( array(), array() ) ) );

		$this->assertNull( $this->service->get( 5, 6 ) );
	}

	#[DataProvider( 'outages' )]
	public function test_an_outage_uses_the_last_good_copy( Response $outage ): void {
		$this->http->push( self::ok( self::feed() ) );
		$this->service->get( 5, 6 );

		// An hour later the cache has expired and Growth Hub is down.
		unset( $this->transients['ffgh_reviews_5'] );
		$this->now += 3601;
		$this->http->push( $outage );

		$feed = $this->service->get( 5, 6 );

		$this->assertSame( 'Sam O.', $feed['reviews'][0]['name'] );
		$this->assertSame( 300, $this->transients['ffgh_reviews_5'][1], 'retries in 5 minutes' );
		$this->assertCount( 2, $this->http->requests );
	}

	public static function outages(): array {
		return array(
			'timeout'         => array( Response::transport_error( 'cURL error 28: Operation timed out' ) ),
			'429'             => array( new Response( 429, array( 'message' => 'Too Many Attempts.' ), 30 ) ),
			'500'             => array( new Response( 500, null ) ),
			'503'             => array( new Response( 503, array( 'message' => 'Down for maintenance' ) ) ),
			'200 not json'    => array( new Response( 200, null ) ),
			'200 wrong shape' => array( self::ok( array( 'reviews' => 'nope' ) ) ),
		);
	}

	public function test_an_outage_without_a_copy_shows_nothing(): void {
		$this->http->push( Response::transport_error( 'Could not resolve host' ) );

		$this->assertNull( $this->service->get( 5, 6 ) );
		$this->assertSame( array( array( 'feed' => null ), 300 ), $this->transients['ffgh_reviews_5'] );
	}

	public function test_a_copy_older_than_seven_days_is_not_used(): void {
		$this->options[ Options::REVIEWS_LAST . '5' ] = array(
			'feed'       => self::feed(),
			'fetched_at' => self::NOW - 7 * 86400 - 1,
		);
		$this->http->push( new Response( 502, null ) );

		$this->assertNull( $this->service->get( 5, 6 ) );
		$this->assertArrayNotHasKey( Options::REVIEWS_LAST . '5', $this->options );
	}

	#[DataProvider( 'refused' )]
	public function test_a_refused_key_shows_nothing_and_forgets_the_copy( int $status ): void {
		$this->options[ Options::REVIEWS_LAST . '5' ] = array(
			'feed'       => self::feed(),
			'fetched_at' => self::NOW - 60,
		);
		$this->http->push( new Response( $status, array( 'message' => 'Invalid or revoked site key.' ) ) );

		$this->assertNull( $this->service->get( 5, 6 ) );
		$this->assertArrayNotHasKey( Options::REVIEWS_LAST . '5', $this->options );
	}

	public static function refused(): array {
		return array(
			'401' => array( 401 ),
			'403' => array( 403 ),
		);
	}

	public function test_no_site_key_makes_no_request(): void {
		$this->options[ Options::SITE_KEY ] = '';

		$this->assertNull( $this->service->get( 5, 6 ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_bad_reviews_and_fields_are_dropped(): void {
		$this->http->push(
			self::ok(
				self::feed(
					array(
						'rating'       => '4.8',
						'review_count' => 0,
						'review_url'   => 'javascript:alert(1)',
						'profile_url'  => 'http://maps.google.com/insecure',
					),
					array(
						self::review( array( 'text' => '   ' ) ),
						self::review( array( 'rating' => 3 ) ),
						self::review( array( 'rating' => '5' ) ),
						self::review( array( 'date' => '2026-02-30' ) ),
						self::review( array( 'date' => '4 Nov 2026' ) ),
						self::review( array( 'name' => '' ) ),
						'not a review',
						self::review(
							array(
								'name'      => 'Chen',
								'initials'  => '',
								'photo_url' => 'http://example.com/me.jpg',
								'rating'    => 4,
								'text'      => "  Two\nlines  ",
							)
						),
					)
				)
			)
		);

		$feed = $this->service->get( 4, 20 );

		$this->assertNull( $feed['rating'] );
		$this->assertNull( $feed['review_count'] );
		$this->assertNull( $feed['review_url'] );
		$this->assertNull( $feed['profile_url'] );
		$this->assertSame(
			array(
				array(
					'name'      => 'Chen',
					'initials'  => 'C',
					'photo_url' => null,
					'rating'    => 4,
					'text'      => "Two\nlines",
					'date'      => '2026-11-04',
				),
			),
			$feed['reviews']
		);
	}

	public function test_a_four_star_review_never_shows_with_a_five_star_minimum(): void {
		$this->http->push( self::ok( self::feed( array(), array( self::review( array( 'rating' => 4 ) ), self::review() ) ) ) );

		$feed = $this->service->get( 5, 6 );

		$this->assertSame( array( 5 ), array_column( $feed['reviews'], 'rating' ) );
	}

	public function test_the_filter_can_hide_the_feed(): void {
		\Brain\Monkey\Filters\expectApplied( 'ffgh_reviews' )->once()->andReturn( null );
		$this->http->push( self::ok( self::feed() ) );

		$this->assertNull( $this->service->get( 5, 6 ) );
	}

	public function test_flush_clears_caches_and_copies(): void {
		foreach ( array( 4, 5 ) as $rating ) {
			$this->transients[ 'ffgh_reviews_' . $rating ]    = array( array( 'feed' => null ), 3600 );
			$this->options[ Options::REVIEWS_LAST . $rating ] = array();
		}
		$this->options['ffgh_reviews_last_other'] = 'kept';

		ReviewsService::flush();

		$this->assertSame( array(), $this->transients );
		$this->assertSame( array( Options::SITE_KEY, 'ffgh_reviews_last_other' ), array_keys( $this->options ) );
	}
}
