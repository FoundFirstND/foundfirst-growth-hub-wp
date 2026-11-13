<?php
/**
 * Stand-in for Elementor Pro's Ajax_Handler: records response data.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Fakes;

/**
 * Records add_response_data() calls.
 */
final class FakeAjaxHandler {

	/** @var array<string, mixed> */
	public array $data = array();

	public function add_response_data( $key, $data ) {
		$this->data[ $key ] = $data;

		return $this;
	}
}
