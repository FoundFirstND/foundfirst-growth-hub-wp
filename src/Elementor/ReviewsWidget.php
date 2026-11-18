<?php
/**
 * Elementor widget "Growth Hub Reviews".
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use FoundFirst\GrowthHub\Plugin;
use FoundFirst\GrowthHub\Reviews\ReviewsRenderer;
use FoundFirst\GrowthHub\Reviews\ReviewsService;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the business's Google reviews, same as [growthhub_reviews]. Works with free Elementor.
 */
final class ReviewsWidget extends Widget_Base {

	/**
	 * Widget id (stored in page data, so never change it).
	 */
	public function get_name() {
		return 'growthhub_reviews';
	}

	/**
	 * Title in the editor panel.
	 */
	public function get_title() {
		return __( 'Growth Hub Reviews', 'foundfirst-growth-hub' );
	}

	/**
	 * Panel icon.
	 */
	public function get_icon() {
		return 'eicon-review';
	}

	/**
	 * Panel category.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords.
	 */
	public function get_keywords() {
		return array( 'reviews', 'google', 'testimonials', 'rating', 'growth hub', 'foundfirst' );
	}

	/**
	 * Stylesheet handles Elementor should load with the widget.
	 */
	public function get_style_depends() {
		return array( ReviewsRenderer::STYLE );
	}

	/**
	 * Script handles Elementor should load with the widget.
	 */
	public function get_script_depends() {
		return array( ReviewsRenderer::SCRIPT );
	}

	/**
	 * Keep the widget out of Elementor's element cache: the reviews have their own one-hour
	 * cache, and reviews that were turned off must disappear within it.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Layout, count, minimum rating, photos, dates and style.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'ffgh_reviews',
			array( 'label' => __( 'Reviews', 'foundfirst-growth-hub' ) )
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'foundfirst-growth-hub' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'carousel',
				'options' => array(
					'carousel' => __( 'Carousel', 'foundfirst-growth-hub' ),
					'grid'     => __( 'Grid', 'foundfirst-growth-hub' ),
					'list'     => __( 'List', 'foundfirst-growth-hub' ),
				),
			)
		);

		$this->add_control(
			'count',
			array(
				'label'   => __( 'How many', 'foundfirst-growth-hub' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => ReviewsService::FETCH_LIMIT,
				'step'    => 1,
				'default' => ReviewsService::DEFAULT_COUNT,
			)
		);

		$this->add_control(
			'min_rating',
			array(
				'label'       => __( 'Minimum rating', 'foundfirst-growth-hub' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '5',
				'options'     => array(
					'5' => __( '5 stars only', 'foundfirst-growth-hub' ),
					'4' => __( '4 stars and up', 'foundfirst-growth-hub' ),
				),
				'description' => __( 'The heading says which you chose. FoundFirst can set a stricter minimum for the business in Growth Hub.', 'foundfirst-growth-hub' ),
			)
		);

		$this->add_control(
			'photos',
			array(
				'label'        => __( 'Show photos', 'foundfirst-growth-hub' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'foundfirst-growth-hub' ),
				'label_off'    => __( 'Hide', 'foundfirst-growth-hub' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Reviewers\' Google profile photos. Off: only their initials, and nothing is loaded from Google.', 'foundfirst-growth-hub' ),
			)
		);

		$this->add_control(
			'dates',
			array(
				'label'        => __( 'Show dates', 'foundfirst-growth-hub' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'foundfirst-growth-hub' ),
				'label_off'    => __( 'Hide', 'foundfirst-growth-hub' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'style',
			array(
				'label'   => __( 'Style', 'foundfirst-growth-hub' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'light',
				'options' => array(
					'light' => __( 'Light', 'foundfirst-growth-hub' ),
					'dark'  => __( 'Dark', 'foundfirst-growth-hub' ),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Front-end and editor output.
	 */
	protected function render() {
		$html = Plugin::reviews_renderer()->render( self::options( $this->get_settings_for_display() ) );

		if ( '' === $html && $this->is_editing() ) {
			echo '<div class="ffgh-reviews-placeholder" style="padding:1em;border:1px dashed currentColor;opacity:.6;">'
				. esc_html__( 'Growth Hub reviews: nothing to show. Reviews appear here once FoundFirst turns on "Show reviews on website" for this business and there are reviews at this rating. Visitors see nothing until then.', 'foundfirst-growth-hub' )
				. '</div>';
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in ReviewsRenderer::render().
	}

	/**
	 * Widget settings as ReviewsRenderer options.
	 *
	 * @param mixed $settings Widget settings.
	 */
	public static function options( $settings ): array {
		$settings = is_array( $settings ) ? $settings : array();
		$options  = array();

		foreach ( array( 'layout', 'count', 'min_rating', 'style' ) as $key ) {
			if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) && '' !== (string) $settings[ $key ] ) {
				$options[ $key ] = $settings[ $key ];
			}
		}

		// A switcher that's off is ''; one saved before it existed has no value (default on).
		foreach ( array( 'photos', 'dates' ) as $key ) {
			$options[ $key ] = array_key_exists( $key, $settings ) ? 'yes' === $settings[ $key ] : true;
		}

		return $options;
	}

	/**
	 * Whether the Elementor editor is showing this widget.
	 */
	private function is_editing(): bool {
		return class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->editor )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}
