<?php
/**
 * Elementor widget "Growth Hub Promo".
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Elementor;

use Elementor\Widget_Base;
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
	 * Front-end and editor output.
	 */
	protected function render() {
		$html = Plugin::promo_renderer()->render();

		if ( '' === $html && $this->is_editing() ) {
			echo '<div class="ffgh-promo-placeholder" style="padding:1em;border:1px dashed currentColor;opacity:.6;">'
				. esc_html__( 'Growth Hub promo: nothing to show right now. Visitors see nothing here until a promo is active.', 'foundfirst-growth-hub' )
				. '</div>';
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in PromoRenderer::render().
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
