<?php
/**
 * Stand-in for Elementor Pro's Form_Record.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Fakes;

/**
 * Holds submitted fields and form settings.
 */
final class FakeFormRecord {

	public function __construct( private array $fields, private array $settings ) {}

	public function get( string $property ) {
		return 'fields' === $property ? $this->fields : null;
	}

	public function get_form_settings( string $setting ) {
		return $this->settings[ $setting ] ?? null;
	}
}
