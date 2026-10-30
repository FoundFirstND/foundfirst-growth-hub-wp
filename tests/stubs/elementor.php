<?php
/**
 * Just enough of Elementor / Elementor Pro for the form action and promo widget to load in tests.
 *
 * @package FoundFirst\GrowthHub
 */

// phpcs:disable

namespace Elementor {
	abstract class Widget_Base {
		public function get_settings_for_display() {
			return array();
		}
	}

	class Controls_Manager {
		const TEXT     = 'text';
		const SWITCHER = 'switcher';
		const RAW_HTML = 'raw_html';
	}
}

namespace ElementorPro\Modules\Forms\Classes {
	abstract class Action_Base {
		abstract public function get_name();
		abstract public function get_label();
		abstract public function run( $record, $ajax_handler );
		abstract public function register_settings_section( $widget );
		abstract public function on_export( $element );
	}
}
