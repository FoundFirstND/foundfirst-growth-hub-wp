<?php
/**
 * PromoService + PromoRenderer tests.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use Brain\Monkey\Functions;
use FoundFirst\GrowthHub\Api\Response;
use FoundFirst\GrowthHub\Elementor\PromoWidget;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Promo\PromoRenderer;
use FoundFirst\GrowthHub\Promo\PromoService;
use FoundFirst\GrowthHub\Tests\Fakes\FakeHttpClient;
use FoundFirst\GrowthHub\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PromoTest extends TestCase {

	private const NOW = 1_793_000_000; // 2026-10-27.

	private FakeHttpClient $http;
	private PromoService $service;
	private PromoRenderer $renderer;

	/** @var string[] */
	private array $enqueued = array();

	/** @var string[] */
	private array $scripts = array();

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
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->scripts[] = $handle;
			}
		);
		Functions\when( 'sanitize_html_class' )->alias( fn ( $class ) => preg_replace( '/[^A-Za-z0-9_-]/', '', $class ) );
		Functions\when( 'shortcode_atts' )->alias(
			fn ( $defaults, $atts ) => array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) )
		);
	}

	private function show_photos_setting( bool $on ): void {
		$this->options[ Options::PROMO_IMAGE ] = $on ? '1' : '0';
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
		$this->show_photos_setting( true );
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

	public function test_photo_is_off_by_default_and_no_image_is_output(): void {
		$this->http->push( self::promo() );

		$html = $this->renderer->render();

		$this->assertFalse( Options::show_promo_image() );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '412.jpg', $html );
		$this->assertStringNotContainsString( 'has-image', $html );
		$this->assertStringContainsString( '<a class="ffgh-promo__link" href="https://redriversnow.com/deals"><span class="ffgh-promo__text">20% off driveway plowing this weekend only!</span></a>', $html );
	}

	public function test_text_always_renders_next_to_the_photo(): void {
		$this->show_photos_setting( true );
		$this->http->push( self::promo( array( 'link' => null ) ) );

		$html = $this->renderer->render();

		$this->assertMatchesRegularExpression(
			'#<div class="ffgh-promo__inner"><img class="ffgh-promo__image" [^>]+><span class="ffgh-promo__text">20% off driveway plowing this weekend only!</span></div>#',
			$html
		);
	}

	public function test_a_promo_with_a_photo_but_no_text_is_not_shown(): void {
		$this->show_photos_setting( true );
		$this->http->push( self::promo( array( 'text' => '   ' ) ) );

		$this->assertSame( '', $this->renderer->render() );
	}

	#[DataProvider( 'shortcode_image_cases' )]
	public function test_shortcode_image_attribute_overrides_the_setting( bool $setting, array $atts, bool $expect_image ): void {
		$this->show_photos_setting( $setting );
		$this->http->push( self::promo() );

		$html = $this->renderer->shortcode( $atts );

		$this->assertSame( $expect_image, str_contains( $html, '<img' ) );
		$this->assertStringContainsString( '<span class="ffgh-promo__text">20% off driveway plowing this weekend only!</span>', $html );
	}

	public static function shortcode_image_cases(): array {
		return array(
			'no attribute, setting off'  => array( false, array(), false ),
			'no attribute, setting on'   => array( true, array(), true ),
			'image="yes", setting off'   => array( false, array( 'image' => 'yes' ), true ),
			'image="no", setting on'     => array( true, array( 'image' => 'no' ), false ),
			'image="NO " is still no'    => array( true, array( 'image' => 'NO ' ), false ),
			'unknown value uses setting' => array( true, array( 'image' => 'maybe' ), true ),
		);
	}

	#[DataProvider( 'widget_switch_cases' )]
	public function test_widget_switch_maps_to_show_image( $settings, ?bool $expected ): void {
		$this->assertSame( $expected, PromoWidget::show_image( $settings ) );
	}

	public static function widget_switch_cases(): array {
		return array(
			'switch on'                       => array( array( 'show_image' => 'yes' ), true ),
			'switch off'                      => array( array( 'show_image' => '' ), false ),
			'saved before the switch existed' => array( array(), null ),
			'no settings'                     => array( null, null ),
		);
	}

	private const VIDEO_FILE = 'https://app.foundfirst.us/promo-videos/Xk3f9QeL2mTz8VbR1nYp4WcA7sHd0GjUqE5oKiLt';
	private const THUMB      = 'https://app.foundfirst.us/promo-images/Xk3f9QeL2mTz8VbR1nYp4WcA7sHd0GjUqE5oKiLt';
	private const FACEBOOK   = 'https://www.facebook.com/redriversnow/videos/123';

	private static function video_promo( array $overrides = array() ): Response {
		return self::promo(
			array_merge(
				array(
					'text'           => 'First ice on Devils Lake!',
					'image_url'      => self::THUMB,
					'media_type'     => 'video',
					'video_url'      => self::FACEBOOK,
					'video_file_url' => self::VIDEO_FILE,
				),
				$overrides
			)
		);
	}

	public function test_a_video_post_shows_a_player_that_loads_nothing_until_played(): void {
		$this->show_photos_setting( false );
		$this->http->push( self::video_promo() );

		$html = $this->renderer->render();

		$this->assertStringContainsString(
			'<video class="ffgh-promo__video" src="' . self::VIDEO_FILE . '" poster="' . self::THUMB . '" controls playsinline preload="none" aria-label="First ice on Devils Lake!"></video>',
			$html
		);
		$this->assertStringNotContainsString( 'autoplay', $html );
		$this->assertStringNotContainsString( 'muted', $html );
		$this->assertStringContainsString( 'ffgh-promo--video', $html );
		// The caption is linked, not the whole banner (clicking play mustn't follow the link).
		$this->assertStringNotContainsString( 'class="ffgh-promo__link"', $html );
		$this->assertStringContainsString( '<a class="ffgh-promo__text-link" href="https://redriversnow.com/deals"><span class="ffgh-promo__text">First ice on Devils Lake!</span></a>', $html );
		// Hidden fallback for promo.js: the thumbnail linked to the Facebook video.
		$this->assertStringContainsString( '<a class="ffgh-promo__video-link ffgh-promo__video-fallback" href="' . self::FACEBOOK . '" aria-label="Watch the video: First ice on Devils Lake!" hidden>', $html );
		$this->assertSame( array( PromoRenderer::SCRIPT ), $this->scripts );
	}

	public function test_autoplay_plays_muted_and_looping(): void {
		$this->http->push( self::video_promo() );

		$html = $this->renderer->shortcode( array( 'autoplay' => 'yes' ) );

		$this->assertStringContainsString( 'controls muted autoplay loop playsinline aria-label=', $html );
		$this->assertStringNotContainsString( 'preload="none"', $html );
	}

	public function test_without_a_video_file_the_thumbnail_links_to_the_facebook_video(): void {
		$this->http->push( self::video_promo( array( 'video_file_url' => null ) ) );

		$html = $this->renderer->render();

		$this->assertStringNotContainsString( '<video', $html );
		$this->assertStringContainsString( '<a class="ffgh-promo__video-link" href="' . self::FACEBOOK . '" aria-label="Watch the video: First ice on Devils Lake!"><img class="ffgh-promo__image" src="' . self::THUMB . '"', $html );
		$this->assertStringContainsString( '<span class="ffgh-promo__play" aria-hidden="true"></span>', $html );
		$this->assertStringNotContainsString( ' hidden', $html );
		$this->assertSame( array(), $this->scripts );
	}

	public function test_without_a_video_file_or_facebook_link_the_thumbnail_shows_as_a_photo_banner(): void {
		$this->show_photos_setting( false );
		$this->http->push(
			self::video_promo(
				array(
					'video_file_url' => null,
					'video_url'      => null,
				)
			)
		);

		$html = $this->renderer->render();

		$this->assertStringContainsString( '<a class="ffgh-promo__link" href="https://redriversnow.com/deals"><img class="ffgh-promo__image" src="' . self::THUMB . '"', $html );
		$this->assertStringNotContainsString( '<video', $html );
	}

	public function test_image_no_hides_a_video_post_s_player_and_thumbnail(): void {
		$this->http->push( self::video_promo() );

		$html = $this->renderer->shortcode( array( 'image' => 'no' ) );

		$this->assertStringNotContainsString( '<video', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '<span class="ffgh-promo__text">First ice on Devils Lake!</span>', $html );
	}

	public function test_photo_posts_are_unchanged(): void {
		$this->show_photos_setting( true );
		$this->http->push(
			self::promo(
				array(
					'media_type'     => 'photo',
					'video_url'      => null,
					'video_file_url' => null,
				)
			)
		);

		$this->assertSame(
			'<div class="ffgh-promo ffgh-promo--has-image" role="region" aria-label="Promotion"><a class="ffgh-promo__link" href="https://redriversnow.com/deals"><img class="ffgh-promo__image" src="https://app.foundfirst.us/storage/promos/412.jpg" alt="" loading="lazy" decoding="async"><span class="ffgh-promo__text">20% off driveway plowing this weekend only!</span></a></div>',
			$this->renderer->render()
		);
		$this->assertSame( array(), $this->scripts );
	}

	public function test_a_promo_cached_by_0_4_0_still_renders_as_before(): void {
		$this->show_photos_setting( true );
		$this->transients[ PromoService::TRANSIENT ] = array(
			array(
				'promo' => array(
					'text'       => 'Old cached promo',
					'link'       => null,
					'image_url'  => self::THUMB,
					'expires_at' => null,
				),
			),
			300,
		);

		$this->assertStringContainsString( '<div class="ffgh-promo__inner"><img class="ffgh-promo__image" src="' . self::THUMB . '"', $this->renderer->render() );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_the_caption_is_escaped_in_the_player_and_links(): void {
		$this->http->push( self::video_promo( array( 'text' => 'Ice "out" <script>x</script>' ) ) );

		$html = $this->renderer->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '"out"', $html );
		$this->assertStringContainsString( 'aria-label="Ice &quot;out&quot; &lt;script&gt;x&lt;/script&gt;"', $html );
	}

	public function test_non_http_video_urls_are_ignored(): void {
		$this->http->push(
			self::video_promo(
				array(
					'video_file_url' => 'javascript:alert(1)',
					'video_url'      => 'data:x',
				)
			)
		);

		$html = $this->renderer->render();

		$this->assertStringNotContainsString( 'javascript', $html );
		$this->assertStringNotContainsString( '<video', $html );
		$this->assertStringNotContainsString( 'data:x', $html );
	}

	#[DataProvider( 'widget_video_cases' )]
	public function test_widget_video_settings( $display, $saved, bool $autoplay, ?bool $show_video ): void {
		$this->assertSame( $autoplay, PromoWidget::autoplay( $display ) );
		$this->assertSame( $show_video, PromoWidget::show_video( $saved ) );
	}

	public static function widget_video_cases(): array {
		return array(
			'autoplay on'                       => array( array( 'autoplay' => 'yes' ), array( 'autoplay' => 'yes' ), true, null ),
			'autoplay off'                      => array( array( 'autoplay' => '' ), array(), false, null ),
			'saved before the switch existed'   => array( array(), array(), false, null ),
			'Show photo explicitly off'         => array( array( 'show_image' => '' ), array( 'show_image' => '' ), false, false ),
			'Show photo on'                     => array( array( 'show_image' => 'yes' ), array( 'show_image' => 'yes' ), false, null ),
			'default off from the site setting' => array( array( 'show_image' => '' ), array(), false, null ),
			'no settings'                       => array( null, null, false, null ),
		);
	}
}
