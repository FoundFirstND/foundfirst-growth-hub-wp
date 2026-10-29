<?php
/**
 * Elementor form action tests: field mapping, consent, extra fields, never throwing.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use Brain\Monkey\Functions;
use FoundFirst\GrowthHub\Api\Response;
use FoundFirst\GrowthHub\Elementor\SendToGrowthHubAction;
use FoundFirst\GrowthHub\Leads\FailureNotifier;
use FoundFirst\GrowthHub\Leads\LeadSender;
use FoundFirst\GrowthHub\Leads\RetryPolicy;
use FoundFirst\GrowthHub\Leads\Status;
use FoundFirst\GrowthHub\Tests\Fakes\FakeFormRecord;
use FoundFirst\GrowthHub\Tests\Fakes\FakeHttpClient;
use FoundFirst\GrowthHub\Tests\Fakes\InMemoryQueue;
use FoundFirst\GrowthHub\Tests\TestCase;

final class SendToGrowthHubActionTest extends TestCase {

	private const CONSENT_HTML = 'Yes, text me about my request. <a href="/sms">Msg &amp; data rates may apply.</a> Reply STOP to opt out.';

	private FakeHttpClient $http;
	private SendToGrowthHubAction $action;

	protected function setUp(): void {
		parent::setUp();

		$this->http   = new FakeHttpClient();
		$queue        = new InMemoryQueue();
		$this->action = new SendToGrowthHubAction(
			new LeadSender( $this->http, $queue, new RetryPolicy(), new FailureNotifier( $queue ) )
		);

		Functions\when( 'wp_generate_uuid4' )->justReturn( '0b8f2c36-6a4e-4d0e-9d55-2f1f0c6f8a11' );
		Functions\when( 'wp_date' )->justReturn( '2026-10-28T14:30:00-05:00' );
		Functions\when( 'wp_strip_all_tags' )->alias( fn ( $text ) => trim( strip_tags( $text ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$_POST   = array( 'referrer' => 'https://redriversnow.com/contact' );
		$_SERVER = array_merge( $_SERVER, array( 'REMOTE_ADDR' => '203.0.113.9' ) );
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'form_name'              => 'Contact Us',
				'ffgh_field_name'        => 'name',
				'ffgh_field_email'       => 'email',
				'ffgh_field_phone'       => 'phone',
				'ffgh_field_message'     => 'message',
				'ffgh_field_sms_consent' => 'sms_consent',
				'ffgh_send_extra_fields' => 'yes',
				'form_fields'            => array(
					array(
						'custom_id'       => 'sms_consent',
						'field_type'      => 'acceptance',
						'field_label'     => '',
						'acceptance_text' => self::CONSENT_HTML,
					),
				),
			),
			$overrides
		);
	}

	private static function field( string $id, string $type, string $title, $value, $raw = null ): array {
		return array(
			'id'        => $id,
			'type'      => $type,
			'title'     => $title,
			'value'     => $value,
			'raw_value' => $raw ?? $value,
		);
	}

	private static function fields( array $extra = array() ): array {
		return array_merge(
			array(
				'name'    => self::field( 'name', 'text', 'Name', 'Jane Doe' ),
				'email'   => self::field( 'email', 'email', 'Email', 'jane@example.com' ),
				'phone'   => self::field( 'phone', 'tel', 'Phone', '(701) 555-0100' ),
				'message' => self::field( 'message', 'textarea', 'Message', 'Do you plow on Saturday?' ),
			),
			$extra
		);
	}

	private function submit( array $fields, array $settings = array() ): array {
		$this->http->push( new Response( 201 ) );
		$this->action->run( new FakeFormRecord( $fields, self::settings( $settings ) ), null );

		return $this->http->requests[0]['body'];
	}

	public function test_maps_fields_and_adds_server_side_details(): void {
		$body = $this->submit(
			self::fields(
				array(
					'service' => self::field( 'service', 'select', 'Service', 'Plowing, Salting', array( 'Plowing', 'Salting' ) ),
					'hp'      => self::field( 'hp', 'honeypot', '', '' ),
					'captcha' => self::field( 'captcha', 'recaptcha_v3', '', 'token' ),
				)
			)
		);

		$this->assertSame(
			array(
				'idempotency_key' => '0b8f2c36-6a4e-4d0e-9d55-2f1f0c6f8a11',
				'name'            => 'Jane Doe',
				'email'           => 'jane@example.com',
				'phone'           => '(701) 555-0100',
				'message'         => 'Do you plow on Saturday?',
				'page_url'        => 'https://redriversnow.com/contact',
				'form_name'       => 'Contact Us',
				'submitted_at'    => '2026-10-28T14:30:00-05:00',
				'ip'              => '203.0.113.9',
				'sms_consent'     => false,
				'fields'          => array( 'Service' => 'Plowing, Salting' ),
			),
			$body
		);
	}

	public function test_ticked_acceptance_sends_consent_with_its_wording(): void {
		$body = $this->submit( self::fields( array( 'sms_consent' => self::field( 'sms_consent', 'acceptance', '', 'on' ) ) ) );

		$this->assertTrue( $body['sms_consent'] );
		$this->assertSame( 'Yes, text me about my request. Msg & data rates may apply. Reply STOP to opt out.', $body['consent_text'] );
		$this->assertArrayNotHasKey( 'fields', $body, 'The consent field is not repeated as an extra field.' );
	}

	public function test_unticked_acceptance_is_not_consent(): void {
		$body = $this->submit( self::fields( array( 'sms_consent' => self::field( 'sms_consent', 'acceptance', '', '' ) ) ) );

		$this->assertFalse( $body['sms_consent'] );
		$this->assertArrayNotHasKey( 'consent_text', $body );
	}

	public function test_checkbox_consent_uses_label_and_ticked_option(): void {
		$body = $this->submit(
			self::fields( array( 'sms_consent' => self::field( 'sms_consent', 'checkbox', 'Text messages', 'I agree to receive texts', array( 'I agree to receive texts' ) ) ) ),
			array(
				'form_fields' => array(
					array(
						'custom_id'   => 'sms_consent',
						'field_type'  => 'checkbox',
						'field_label' => 'Text messages',
					),
				),
			)
		);

		$this->assertTrue( $body['sms_consent'] );
		$this->assertSame( 'Text messages I agree to receive texts', $body['consent_text'] );
	}

	public function test_ticked_consent_without_wording_is_sent_as_no_consent_and_flagged(): void {
		$body = $this->submit(
			self::fields( array( 'sms_consent' => self::field( 'sms_consent', 'acceptance', '', 'on' ) ) ),
			array(
				'form_fields' => array(
					array(
						'custom_id'       => 'sms_consent',
						'field_type'      => 'acceptance',
						'field_label'     => '',
						'acceptance_text' => '',
					),
				),
			)
		);

		$this->assertFalse( $body['sms_consent'] );
		$this->assertStringContainsString( 'no label text', Status::last_error()['message'] );
	}

	public function test_extra_fields_can_be_turned_off(): void {
		$body = $this->submit(
			self::fields( array( 'service' => self::field( 'service', 'text', 'Service', 'Plowing' ) ) ),
			array( 'ffgh_send_extra_fields' => '' )
		);

		$this->assertArrayNotHasKey( 'fields', $body );
	}

	public function test_unlabelled_and_duplicate_labels_stay_distinct(): void {
		$body = $this->submit(
			self::fields(
				array(
					'addr1' => self::field( 'addr1', 'text', 'Address', '123 17th Ave S' ),
					'addr2' => self::field( 'addr2', 'text', 'Address', 'Fargo' ),
					'utm'   => self::field( 'utm', 'hidden', '', 'google' ),
				)
			)
		);

		$this->assertSame(
			array(
				'Address'         => '123 17th Ave S',
				'Address (addr2)' => 'Fargo',
				'utm'             => 'google',
			),
			$body['fields']
		);
	}

	public function test_blank_mapping_skips_that_field(): void {
		$body = $this->submit( self::fields(), array( 'ffgh_field_phone' => '' ) );

		$this->assertArrayNotHasKey( 'phone', $body );
		$this->assertSame( '(701) 555-0100', $body['fields']['Phone'] );
	}

	public function test_invalid_remote_addr_is_dropped(): void {
		$_SERVER['REMOTE_ADDR'] = 'garbage';

		$body = $this->submit( self::fields() );

		$this->assertArrayNotHasKey( 'ip', $body );
	}

	public function test_never_throws_and_records_the_problem(): void {
		// No scripted response: the fake HTTP client throws.
		$this->action->run( new FakeFormRecord( self::fields(), self::settings() ), null );

		$this->assertStringContainsString( 'Form action error', Status::last_error()['message'] );
	}
}
