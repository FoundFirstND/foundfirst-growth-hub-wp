<?php
/**
 * Fetches and caches the Google reviews feed.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Reviews;

use Closure;
use FoundFirst\GrowthHub\Api\HttpClient;
use FoundFirst\GrowthHub\Options;

defined( 'ABSPATH' ) || exit;

/**
 * GET /reviews, cached in a transient for an hour (it shares the 30 requests a minute with
 * /leads and /promo).
 *
 * Always asks for the most reviews the API gives (20) and trims to the widget's count here, so
 * a site needs at most two cache entries (5-star and 4+) whatever its widgets ask for.
 *
 * When Growth Hub can't be reached (timeout, connection error, 429, 5xx, a broken body), the
 * last good feed is used for up to 7 days, or nothing. When Growth Hub answers that the business
 * doesn't show reviews (`data: null`) or the key is refused (401/403), the last good copy is
 * deleted so turned-off reviews never come back.
 */
final class ReviewsService {

	public const TRANSIENT = 'ffgh_reviews_'; // + minimum rating.

	public const FETCH_LIMIT   = 20;
	public const RATINGS       = array( 4, 5 );
	public const DEFAULT_COUNT = 6;

	private const CACHE_TTL     = 3600;
	private const ERROR_TTL     = 300;
	private const MAX_STALE_AGE = 604800; // 7 days.
	private const TIMEOUT       = 3;

	/**
	 * Current time source.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param HttpClient   $http  API client.
	 * @param Closure|null $clock Returns the current Unix time (tests).
	 */
	public function __construct( private HttpClient $http, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * The feed to show, with at most $count reviews, or null to show nothing.
	 *
	 * @param int $min_rating 4 or 5 (anything else is treated as 5).
	 * @param int $count      1 to 20.
	 * @return array{rating: ?float, review_count: ?int, review_url: ?string, profile_url: ?string, reviews: list<array{name: string, initials: string, photo_url: ?string, rating: int, text: string, date: string}>}|null
	 */
	public function get( int $min_rating, int $count ): ?array {
		$min_rating = self::min_rating( $min_rating );
		$count      = max( 1, min( self::FETCH_LIMIT, $count ) );
		$feed       = null;

		if ( '' !== Options::site_key() ) {
			$cached = get_transient( self::TRANSIENT . $min_rating );
			if ( ! is_array( $cached ) || ! array_key_exists( 'feed', $cached ) ) {
				$cached = $this->fetch( $min_rating );
			}
			$feed = $cached['feed'];
		}

		/**
		 * Filters the reviews feed before it is shown. Return null to hide it.
		 *
		 * @param array|null $feed       Feed, or null.
		 * @param int        $min_rating Minimum star rating (4 or 5).
		 */
		$feed = self::clean( apply_filters( 'ffgh_reviews', $feed, $min_rating ), $min_rating );

		if ( null === $feed || array() === $feed['reviews'] ) {
			return null;
		}

		$feed['reviews'] = array_slice( $feed['reviews'], 0, $count );

		return $feed;
	}

	/**
	 * 4 or 5.
	 *
	 * @param mixed $value Requested minimum.
	 */
	public static function min_rating( $value ): int {
		return 4 === (int) $value ? 4 : 5;
	}

	/**
	 * Clear the cache and the last good copies (after the site key or API URL changes, which
	 * may mean another business).
	 */
	public static function flush(): void {
		foreach ( self::RATINGS as $rating ) {
			delete_transient( self::TRANSIENT . $rating );
			delete_option( Options::REVIEWS_LAST . $rating );
		}
	}

	/**
	 * Call the API and cache the result.
	 *
	 * @param int $min_rating 4 or 5.
	 * @return array{feed: array|null}
	 */
	private function fetch( int $min_rating ): array {
		$path     = sprintf( '/reviews?min_rating=%d&limit=%d', $min_rating, self::FETCH_LIMIT );
		$response = $this->http->request( 'GET', $path, null, self::TIMEOUT );
		$now      = ( $this->clock )();

		if ( 200 === $response->status && is_array( $response->body ) && array_key_exists( 'data', $response->body ) ) {
			$feed = null === $response->body['data'] ? null : self::clean( $response->body['data'], $min_rating );

			if ( null === $response->body['data'] ) {
				delete_option( Options::REVIEWS_LAST . $min_rating );
			} elseif ( null !== $feed ) {
				Options::set(
					Options::REVIEWS_LAST . $min_rating,
					array(
						'feed'       => $feed,
						'fetched_at' => $now,
					)
				);
			}

			if ( null === $response->body['data'] || null !== $feed ) {
				$cached = array( 'feed' => $feed );
				set_transient( self::TRANSIENT . $min_rating, $cached, self::CACHE_TTL );
				return $cached;
			}
			// A 200 with a feed we can't use: treat it like an outage below.
		}

		if ( 401 === $response->status || 403 === $response->status ) {
			delete_option( Options::REVIEWS_LAST . $min_rating );
			$cached = array( 'feed' => null );
			set_transient( self::TRANSIENT . $min_rating, $cached, self::ERROR_TTL );
			return $cached;
		}

		// Unreachable or a bad answer: the last good copy if it's recent enough. Cached briefly
		// so an outage doesn't turn every page view into an API call.
		$cached = array( 'feed' => $this->last_good( $min_rating, $now ) );
		set_transient( self::TRANSIENT . $min_rating, $cached, self::ERROR_TTL );
		return $cached;
	}

	/**
	 * The last good feed, or null when there is none or it's over 7 days old.
	 *
	 * @param int $min_rating 4 or 5.
	 * @param int $now        Current Unix time.
	 */
	private function last_good( int $min_rating, int $now ): ?array {
		$last = get_option( Options::REVIEWS_LAST . $min_rating, null );

		if ( ! is_array( $last ) || ! isset( $last['fetched_at'] ) || ! is_int( $last['fetched_at'] ) ) {
			return null;
		}

		if ( $now - $last['fetched_at'] > self::MAX_STALE_AGE ) {
			delete_option( Options::REVIEWS_LAST . $min_rating );
			return null;
		}

		return self::clean( $last['feed'] ?? null, $min_rating );
	}

	/**
	 * Validate the API's feed shape. Reviews that don't fit (no text, a bad date, below the
	 * minimum) are dropped; the feed itself is null only when it isn't a feed at all.
	 *
	 * @param mixed $data       API data.
	 * @param int   $min_rating 4 or 5.
	 */
	public static function clean( $data, int $min_rating ): ?array {
		if ( ! is_array( $data ) || ! isset( $data['reviews'] ) || ! is_array( $data['reviews'] ) ) {
			return null;
		}

		$reviews = array();
		foreach ( $data['reviews'] as $review ) {
			$review = self::clean_review( $review, $min_rating );
			if ( null !== $review ) {
				$reviews[] = $review;
			}
		}

		$rating = $data['rating'] ?? null;
		$count  = $data['review_count'] ?? null;

		return array(
			'rating'       => ( is_int( $rating ) || is_float( $rating ) ) && $rating >= 1 && $rating <= 5 ? round( (float) $rating, 1 ) : null,
			'review_count' => is_int( $count ) && $count > 0 ? $count : null,
			'review_url'   => self::url( $data['review_url'] ?? null ),
			'profile_url'  => self::url( $data['profile_url'] ?? null ),
			'reviews'      => $reviews,
		);
	}

	/**
	 * One review, or null to drop it.
	 *
	 * @param mixed $review     API review.
	 * @param int   $min_rating 4 or 5.
	 */
	private static function clean_review( $review, int $min_rating ): ?array {
		if ( ! is_array( $review ) ) {
			return null;
		}

		$name   = isset( $review['name'] ) && is_string( $review['name'] ) ? trim( $review['name'] ) : '';
		$text   = isset( $review['text'] ) && is_string( $review['text'] ) ? trim( $review['text'] ) : '';
		$rating = $review['rating'] ?? null;
		$date   = $review['date'] ?? null;

		if ( '' === $name || '' === $text || ! is_int( $rating ) || $rating < $min_rating || $rating > 5 ) {
			return null;
		}

		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return null;
		}

		$initials = isset( $review['initials'] ) && is_string( $review['initials'] ) ? trim( $review['initials'] ) : '';
		if ( '' === $initials ) {
			$initials = mb_strtoupper( mb_substr( $name, 0, 1 ) );
		}

		return array(
			'name'      => $name,
			'initials'  => mb_substr( $initials, 0, 2 ),
			'photo_url' => self::url( $review['photo_url'] ?? null ),
			'rating'    => $rating,
			'text'      => $text,
			'date'      => $date,
		);
	}

	/**
	 * An https URL or null (http would be mixed content on an https site).
	 *
	 * @param mixed $url Value.
	 */
	private static function url( $url ): ?string {
		if ( ! is_string( $url ) || ! preg_match( '#^https://[^\s"\'<>]+$#i', $url ) ) {
			return null;
		}

		return $url;
	}
}
