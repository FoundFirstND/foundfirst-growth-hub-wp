<?php
/**
 * Elementor widget "Growth Hub Promo".
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Plugin;
use FoundFirst\GrowthHub\Promo\PromoRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the current promo, same as [growthhub_promo]. Works with free Elementor.
 */
final class PromoWidget extends Widget_Base {

	/**
	 * Widget id (stored in page data, so never change it).
	 */
	public function get_name() {
		return 'growthhub_promo';
	}

	/**
	 * Title in the editor panel.
	 */
	public function get_title() {
		return __( 'Growth Hub Promo', 'foundfirst-growth-hub' );
	}

	/**
	 * Panel icon.
	 */
	public function get_icon() {
		return 'eicon-banner';
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
		return array( 'promo', 'banner', 'growth hub', 'foundfirst' );
	}

	/**
	 * Stylesheet handles Elementor should load with the widget.
	 */
	public function get_style_depends() {
		return array( PromoRenderer::STYLE );
	}

	/**
	 * "Show photo" switch. Its default follows Settings → Growth Hub → "Show photo on the
	 * banner", so widgets nobody has touched change with the setting.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'ffgh_promo',
			array( 'label' => __( 'Promo', 'foundfirst-growth-hub' ) )
		);

		$this->add_control(
			'show_image',
			array(
				'label'        => __( 'Show photo', 'foundfirst-growth-hub' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'foundfirst-growth-hub' ),
				'label_off'    => __( 'Hide', 'foundfirst-growth-hub' ),
				'return_value' => 'yes',
				'default'      => Options::show_promo_image() ? 'yes' : '',
				'description'  => __( 'The banner text always shows. Off: no photo is output at all.', 'foundfirst-growth-hub' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Front-end and editor output.
	 */
	protected function render() {
		$html = Plugin::promo_renderer()->render( '', self::show_image( $this->get_settings_for_display() ) );

		if ( '' === $html && $this->is_editing() ) {
			echo '<div class="ffgh-promo-placeholder" style="padding:1em;border:1px dashed currentColor;opacity:.6;">'
				. esc_html__( 'Growth Hub promo: nothing to show right now. Visitors see nothing here until a promo is active.', 'foundfirst-growth-hub' )
				. '</div>';
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in PromoRenderer::render().
	}

	/**
	 * The "Show photo" switch as render()'s argument. A widget saved before the switch
	 * existed has no value, so the setting decides.
	 *
	 * @param mixed $settings Widget settings.
	 */
	public static function show_image( $settings ): ?bool {
		if ( ! is_array( $settings ) || ! array_key_exists( 'show_image', $settings ) ) {
			return null;
		}

		return 'yes' === $settings['show_image'];
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
