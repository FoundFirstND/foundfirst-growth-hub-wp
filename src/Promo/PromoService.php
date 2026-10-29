<?php
/**
 * Fetches and caches the current promo.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Promo;

use Closure;
use FoundFirst\GrowthHub\Api\HttpClient;
use FoundFirst\GrowthHub\Options;

defined( 'ABSPATH' ) || exit;

/**
 * GET /promo, cached in a transient so page views don't hit the API (it counts toward the
 * 30 requests a minute).
 */
final class PromoService {

	public const TRANSIENT = 'ffgh_promo';

	private const CACHE_TTL = 300;
	private const ERROR_TTL = 60;
	private const TIMEOUT   = 3;

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
	 * The promo to show now, or null.
	 *
	 * @return array{text: string, link: ?string, image_url: ?string, expires_at: ?string}|null
	 */
	public function current(): ?array {
		$promo = null;

		if ( '' !== Options::site_key() ) {
			$cached = get_transient( self::TRANSIENT );
			if ( ! is_array( $cached ) || ! array_key_exists( 'promo', $cached ) ) {
				$cached = $this->fetch();
			}
			$promo = $cached['promo'];
		}

		/**
		 * Filters the promo before it is shown. Return null to hide it.
		 *
		 * @param array|null $promo Promo, or null.
		 */
		$promo = apply_filters( 'ffgh_promo', $promo );

		$promo = self::clean( $promo );
		if ( null === $promo ) {
			return null;
		}

		// Hide an expired promo even if the cached copy is still fresh.
		if ( null !== $promo['expires_at'] ) {
			$expires = strtotime( $promo['expires_at'] );
			if ( false !== $expires && $expires <= ( $this->clock )() ) {
				return null;
			}
		}

		return $promo;
	}

	/**
	 * Clear the cache (after the settings change).
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Call the API and cache the result. Errors are cached as "no promo" for a minute so an
	 * outage doesn't turn every page view into an API call.
	 *
	 * @return array{promo: array|null}
	 */
	private function fetch(): array {
		$response = $this->http->request( 'GET', '/promo', null, self::TIMEOUT );

		if ( 200 === $response->status && is_array( $response->body ) && array_key_exists( 'data', $response->body ) ) {
			$cached = array( 'promo' => self::clean( $response->body['data'] ) );
			set_transient( self::TRANSIENT, $cached, self::CACHE_TTL );
			return $cached;
		}

		$cached = array( 'promo' => null );
		set_transient( self::TRANSIENT, $cached, self::ERROR_TTL );
		return $cached;
	}

	/**
	 * Validate the API's promo shape.
	 *
	 * @param mixed $data API data.
	 * @return array|null
	 */
	private static function clean( $data ): ?array {
		if ( ! is_array( $data ) || ! isset( $data['text'] ) || ! is_string( $data['text'] ) || '' === trim( $data['text'] ) ) {
			return null;
		}

		return array(
			'text'       => trim( $data['text'] ),
			'link'       => self::url( $data['link'] ?? null ),
			'image_url'  => self::url( $data['image_url'] ?? null ),
			'expires_at' => isset( $data['expires_at'] ) && is_string( $data['expires_at'] ) && '' !== $data['expires_at'] ? $data['expires_at'] : null,
		);
	}

	/**
	 * An http(s) URL or null.
	 *
	 * @param mixed $url Value.
	 */
	private static function url( $url ): ?string {
		if ( ! is_string( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}

		return $url;
	}
}
