<?php
/**
 * ReviewsWidget tests.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use FoundFirst\GrowthHub\Elementor\ReviewsWidget;
use FoundFirst\GrowthHub\Reviews\ReviewsRenderer;
use FoundFirst\GrowthHub\Tests\TestCase;

final class ReviewsWidgetTest extends TestCase {

	public function test_widget_identity_and_assets(): void {
		$widget = new ReviewsWidget();

		$this->assertSame( 'growthhub_reviews', $widget->get_name(), 'stored in page data and used by reviews.js' );
		$this->assertSame( 'Growth Hub Reviews', $widget->get_title() );
		$this->assertSame( array( ReviewsRenderer::STYLE ), $widget->get_style_depends() );
		$this->assertSame( array( ReviewsRenderer::SCRIPT ), $widget->get_script_depends() );
	}

	public function test_widget_stays_out_of_elementors_element_cache(): void {
		$method = new \ReflectionMethod( ReviewsWidget::class, 'is_dynamic_content' );

		$this->assertTrue( $method->invoke( new ReviewsWidget() ) );
	}

	public function test_settings_map_to_renderer_options(): void {
		$options = ReviewsWidget::options(
			array(
				'layout'     => 'grid',
				'count'      => 9,
				'min_rating' => '4',
				'photos'     => '',
				'dates'      => 'yes',
				'style'      => 'dark',
				'_css_id'    => 'ignored',
			)
		);

		$this->assertSame(
			array(
				'layout'     => 'grid',
				'count'      => 9,
				'min_rating' => '4',
				'style'      => 'dark',
				'photos'     => false,
				'dates'      => true,
			),
			$options
		);
		$this->assertSame(
			array(
				'layout'     => 'grid',
				'count'      => 9,
				'min_rating' => 4,
				'photos'     => false,
				'dates'      => true,
				'style'      => 'dark',
				'class'      => '',
			),
			ReviewsRenderer::options( $options )
		);
	}

	public function test_empty_or_missing_settings_use_the_defaults(): void {
		$this->assertSame(
			ReviewsRenderer::options( array() ),
			ReviewsRenderer::options( ReviewsWidget::options( array( 'count' => '' ) ) )
		);
		$this->assertSame( ReviewsRenderer::options( array() ), ReviewsRenderer::options( ReviewsWidget::options( null ) ) );
	}
}
