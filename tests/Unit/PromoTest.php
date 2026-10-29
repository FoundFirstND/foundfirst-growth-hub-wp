<?php
/**
 * PromoService + PromoRenderer tests.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use Brain\Monkey\Functions;
use FoundFirst\GrowthHub\Api\Response;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Promo\PromoRenderer;
use FoundFirst\GrowthHub\Promo\PromoService;
use FoundFirst\GrowthHub\Tests\Fakes\FakeHttpClient;
use FoundFirst\GrowthHub\Tests\TestCase;

final class PromoTest extends TestCase {

	private const NOW = 1_793_000_000; // 2026-10-27.

	private FakeHttpClient $http;
	private PromoService $service;
	private PromoRenderer $renderer;

	/** @var string[] */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options[ Options::SITE_KEY ] = 'ghk_test';
		$this->http                         = new FakeHttpClient();
		$this->service                      = new PromoService( $this->http, fn (): int => self::NOW );
		$this->renderer                     = new PromoRenderer( $this->service );

		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle ) {
				$this->enqueued[] = $handle;
			}
		);
		Functions\when( 'sanitize_html_class' )->alias( fn ( $class ) => preg_replace( '/[^A-Za-z0-9_-]/', '', $class ) );
	}

	private static function promo( array $overrides = array() ): Response {
		return new Response(
			200,
			array(
				'data' => array_merge(
					array(
						'text'       => '20% off driveway plowing this weekend only!',
						'link'       => 'https://redriversnow.com/deals',
						'image_url'  => 'https://app.foundfirst.us/storage/promos/412.jpg',
						'expires_at' => '2026-11-01T05:59:59+00:00',
					),
					$overrides
				),
			)
		);
	}

	public function test_null_promo_renders_nothing_and_is_cached_for_five_minutes(): void {
		$this->http->push( new Response( 200, array( 'data' => null ) ) );

		$this->assertSame( '', $this->renderer->render() );
		$this->assertSame( '', $this->renderer->render() );

		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
		$this->assertSame( '/promo', $this->http->requests[0]['path'] );
		$this->assertSame( array( array( 'promo' => null ), 300 ), $this->transients[ PromoService::TRANSIENT ] );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_promo_renders_escaped_markup_with_link_and_image(): void {
		$this->http->push( self::promo( array( 'text' => 'Deal <script>x</script> & more' ) ) );

		$html = $this->renderer->render( 'my-banner bad"class' );

		$this->assertStringStartsWith( '<div class="ffgh-promo ffgh-promo--has-image my-banner badclass"', $html );
		$this->assertStringContainsString( '<a class="ffgh-promo__link" href="https://redriversnow.com/deals">', $html );
		$this->assertStringContainsString( '<img class="ffgh-promo__image" src="https://app.foundfirst.us/storage/promos/412.jpg" alt="" loading="lazy" decoding="async">', $html );
		$this->assertStringContainsString( '<span class="ffgh-promo__text">Deal &lt;script&gt;x&lt;/script&gt; &amp; more</span>', $html );
		$this->assertSame( array( PromoRenderer::STYLE ), $this->enqueued );
	}

	public function test_promo_without_link_or_image(): void {
		$this->http->push(
			self::promo(
				array(
					'link'       => null,
					'image_url'  => null,
					'expires_at' => null,
				)
			)
		);

		$html = $this->renderer->render();

		$this->assertStringContainsString( '<div class="ffgh-promo__inner"><span class="ffgh-promo__text">', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'has-image', $html );
	}

	public function test_non_http_urls_are_ignored(): void {
		$this->http->push(
			self::promo(
				array(
					'link'      => 'javascript:alert(1)',
					'image_url' => 'data:image/png;base64,xx',
				)
			)
		);

		$html = $this->renderer->render();

		$this->assertStringNotContainsString( 'javascript', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_expired_promo_is_hidden_even_when_cached(): void {
		$this->transients[ PromoService::TRANSIENT ] = array(
			array(
				'promo' => array(
					'text'       => 'Old deal',
					'link'       => null,
					'image_url'  => null,
					'expires_at' => '2026-10-01T00:00:00+00:00',
				),
			),
			300,
		);

		$this->assertNull( $this->service->current() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_errors_are_cached_as_no_promo_for_a_minute(): void {
		$this->http->push( Response::transport_error( 'timed out' ) );

		$this->assertSame( '', $this->renderer->render() );
		$this->assertSame( 60, $this->transients[ PromoService::TRANSIENT ][1] );
		$this->assertSame( 3, $this->http->requests[0]['timeout'] );
	}

	public function test_no_site_key_means_no_request(): void {
		unset( $this->options[ Options::SITE_KEY ] );

		$this->assertSame( '', $this->renderer->render() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_exceptions_render_nothing(): void {
		// FakeHttpClient throws when it has no scripted response.
		$this->assertSame( '', $this->renderer->render() );
	}
}
