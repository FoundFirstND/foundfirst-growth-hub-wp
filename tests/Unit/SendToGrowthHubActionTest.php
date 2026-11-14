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
use FoundFirst\GrowthHub\Analytics\Tracking;
use FoundFirst\GrowthHub\Leads\FailureNotifier;
use FoundFirst\GrowthHub\Leads\LeadSender;
use FoundFirst\GrowthHub\Leads\RetryPolicy;
use FoundFirst\GrowthHub\Leads\Status;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Tests\Fakes\FakeAjaxHandler;
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
				'idempotency_key'   => '0b8f2c36-6a4e-4d0e-9d55-2f1f0c6f8a11',
				'name'              => 'Jane Doe',
				'email'             => 'jane@example.com',
				'phone'             => '(701) 555-0100',
				'message'           => 'Do you plow on Saturday?',
				'page_url'          => 'https://redriversnow.com/contact',
				'form_name'         => 'Contact Us',
				'submitted_at'      => '2026-10-28T14:30:00-05:00',
				'ip'                => '203.0.113.9',
				'sms_consent'       => false,
				'marketing_consent' => false,
				'age_confirmed'     => false,
				'fields'            => array( 'Service' => 'Plowing, Salting' ),
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

	private function run_with_handler( array $settings = array(), bool $deliver = true ): FakeAjaxHandler {
		if ( $deliver ) {
			$this->http->push( new Response( 201 ) );
		}
		$handler = new FakeAjaxHandler();
		$this->action->run( new FakeFormRecord( self::fields(), self::settings( $settings ) ), $handler );

		return $handler;
	}

	public function test_flags_the_response_for_google_analytics_with_the_form_name_only(): void {
		$handler = $this->run_with_handler();

		$this->assertSame( array( Tracking::RESPONSE_KEY => array( 'form_name' => 'Contact Us' ) ), $handler->data );

		$json = (string) json_encode( $handler->data );
		foreach ( array( 'Jane Doe', 'jane@example.com', '(701) 555-0100', '555-0100', 'Do you plow on Saturday?', '203.0.113.9' ) as $pii ) {
			$this->assertStringNotContainsString( $pii, $json );
		}
	}

	public function test_form_without_a_name_uses_its_element_id(): void {
		$handler = $this->run_with_handler(
			array(
				'form_name' => '',
				'id'        => '3f2a1b9',
			)
		);

		$this->assertSame( 'form-3f2a1b9', $handler->data[ Tracking::RESPONSE_KEY ]['form_name'] );
	}

	public function test_no_flag_when_the_setting_is_off(): void {
		$this->options[ Options::ANALYTICS ] = '0';

		$this->assertSame( array(), $this->run_with_handler()->data );
	}

	public function test_flag_is_added_even_when_growth_hub_delivery_throws(): void {
		// No scripted response: the fake HTTP client throws.
		$handler = $this->run_with_handler( array(), false );

		$this->assertArrayHasKey( Tracking::RESPONSE_KEY, $handler->data );
		$this->assertStringContainsString( 'Form action error', Status::last_error()['message'] );
	}

	private const VIP_HTML = '<strong>Yes</strong>, text me happy hour deals from ForksOnTap. Up to 4 msgs/week. Msg &amp; data rates may apply. Reply STOP to opt out. <a href="https://forksontap.com/privacy/#sms">Terms</a>';
	private const VIP_TEXT = 'Yes, text me happy hour deals from ForksOnTap. Up to 4 msgs/week. Msg & data rates may apply. Reply STOP to opt out. Terms';
	private const AGE_TEXT = "I confirm I'm 21 or older.";

	/**
	 * Form settings with the VIP and age boxes mapped (Acceptance fields with their wording).
	 */
	private static function club_settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'ffgh_field_marketing_consent' => 'vip',
				'ffgh_field_age_confirmation'  => 'age21',
				'form_fields'                  => array(
					array(
						'custom_id'       => 'vip',
						'field_type'      => 'acceptance',
						'field_label'     => '',
						'acceptance_text' => self::VIP_HTML,
					),
					array(
						'custom_id'       => 'age21',
						'field_type'      => 'acceptance',
						'field_label'     => '',
						'acceptance_text' => '<em>I confirm I&#039;m 21 or older.</em>',
					),
				),
			),
			$overrides
		);
	}

	/**
	 * A text club form: name, phone and the two boxes. No email, no message.
	 */
	private static function club_fields( string $vip, string $age ): array {
		return array(
			'name'  => self::field( 'name', 'text', 'Name', 'Sam Lee' ),
			'phone' => self::field( 'phone', 'tel', 'Phone', '(701) 555-0142' ),
			'vip'   => self::field( 'vip', 'acceptance', '', $vip ),
			'age21' => self::field( 'age21', 'acceptance', '', $age ),
		);
	}

	public function test_mapped_and_ticked_vip_and_age_send_true_with_exact_wording(): void {
		$body = $this->submit( self::club_fields( 'on', 'on' ), self::club_settings() );

		$this->assertTrue( $body['marketing_consent'] );
		$this->assertSame( self::VIP_TEXT, $body['marketing_consent_text'] );
		$this->assertTrue( $body['age_confirmed'] );
		$this->assertSame( self::AGE_TEXT, $body['age_confirmation_text'] );
		$this->assertFalse( $body['sms_consent'], 'The VIP box never counts as SMS consent.' );
		$this->assertArrayNotHasKey( 'fields', $body, 'The boxes are not repeated as extra fields.' );
	}

	public function test_html_is_stripped_but_the_wording_is_kept(): void {
		$body = $this->submit( self::club_fields( 'on', '' ), self::club_settings() );

		$this->assertStringNotContainsString( '<', $body['marketing_consent_text'] );
		$this->assertStringNotContainsString( '&amp;', $body['marketing_consent_text'] );
		$this->assertStringContainsString( 'Up to 4 msgs/week. Msg & data rates may apply.', $body['marketing_consent_text'] );
	}

	public function test_unticked_boxes_send_false_and_no_wording(): void {
		$body = $this->submit( self::club_fields( '', '' ), self::club_settings() );

		$this->assertFalse( $body['marketing_consent'] );
		$this->assertFalse( $body['age_confirmed'] );
		$this->assertArrayNotHasKey( 'marketing_consent_text', $body );
		$this->assertArrayNotHasKey( 'age_confirmation_text', $body );
	}

	public function test_unticked_boxes_left_out_of_the_submission_send_false(): void {
		$fields = self::club_fields( '', '' );
		unset( $fields['vip'], $fields['age21'] ); // Elementor may omit an unticked acceptance field.

		$body = $this->submit( $fields, self::club_settings() );

		$this->assertFalse( $body['marketing_consent'] );
		$this->assertFalse( $body['age_confirmed'] );
	}

	public function test_unmapped_boxes_send_false_even_when_a_box_is_ticked(): void {
		$body = $this->submit(
			self::club_fields( 'on', 'on' ),
			self::club_settings(
				array(
					'ffgh_field_marketing_consent' => '',
					'ffgh_field_age_confirmation'  => null, // Forms saved before 0.4.0 have no setting.
				)
			)
		);

		$this->assertFalse( $body['marketing_consent'] );
		$this->assertFalse( $body['age_confirmed'] );
		$this->assertArrayNotHasKey( 'marketing_consent_text', $body );
		$this->assertArrayNotHasKey( 'age_confirmation_text', $body );
	}

	public function test_age_and_vip_are_independent(): void {
		$age_only = $this->submit( self::club_fields( '', 'on' ), self::club_settings() );
		$this->assertFalse( $age_only['marketing_consent'] );
		$this->assertTrue( $age_only['age_confirmed'] );
		$this->assertSame( self::AGE_TEXT, $age_only['age_confirmation_text'] );

		$this->http->requests = array();
		$vip_only             = $this->submit( self::club_fields( 'on', '' ), self::club_settings() );
		$this->assertTrue( $vip_only['marketing_consent'] );
		$this->assertFalse( $vip_only['age_confirmed'] );
		$this->assertArrayNotHasKey( 'age_confirmation_text', $vip_only );
	}

	public function test_phone_only_text_club_form_is_a_valid_lead(): void {
		$body = $this->submit( self::club_fields( 'on', 'on' ), self::club_settings() );

		$this->assertSame( 'Sam Lee', $body['name'] );
		$this->assertSame( '(701) 555-0142', $body['phone'] );
		$this->assertArrayNotHasKey( 'message', $body );
		$this->assertArrayNotHasKey( 'email', $body );
		$this->assertCount( 1, $this->http->requests, 'Sent once and accepted (201), so not queued for a retry.' );
		$this->assertSame( '/leads', $this->http->requests[0]['path'] );
		$this->assertNull( Status::last_error(), 'Nothing went wrong.' );
	}

	public function test_a_mapped_field_that_is_not_a_box_never_counts_as_consent(): void {
		$fields        = self::club_fields( 'on', 'on' );
		$fields['vip'] = self::field( 'vip', 'text', 'Comments', 'yes please text me' );

		$body = $this->submit( $fields, self::club_settings() );

		$this->assertFalse( $body['marketing_consent'] );
		$this->assertArrayNotHasKey( 'marketing_consent_text', $body );
		$this->assertTrue( $body['age_confirmed'] );
		$this->assertStringContainsString( 'not an Acceptance or Checkbox field', Status::last_error()['message'] );
	}

	public function test_ticked_box_without_wording_is_sent_as_false_and_flagged(): void {
		$settings                                      = self::club_settings();
		$settings['form_fields'][0]['acceptance_text'] = '';

		$body = $this->submit( self::club_fields( 'on', '' ), $settings );

		$this->assertFalse( $body['marketing_consent'] );
		$this->assertArrayNotHasKey( 'marketing_consent_text', $body );
		$this->assertStringContainsString( 'VIP consent was not sent', Status::last_error()['message'] );
	}

	public function test_sms_consent_is_unaffected_by_the_new_boxes(): void {
		$fields                                   = self::fields( array( 'sms_consent' => self::field( 'sms_consent', 'acceptance', '', 'on' ) ) );
		$fields['vip']                            = self::field( 'vip', 'acceptance', '', '' );
		$settings                                 = self::settings();
		$settings['form_fields'][]                = array(
			'custom_id'       => 'vip',
			'field_type'      => 'acceptance',
			'acceptance_text' => self::VIP_HTML,
		);
		$settings['ffgh_field_marketing_consent'] = 'vip';
		$this->http->push( new Response( 201 ) );
		$this->action->run( new FakeFormRecord( $fields, $settings ), null );
		$body = $this->http->requests[0]['body'];

		$this->assertTrue( $body['sms_consent'] );
		$this->assertSame( 'Yes, text me about my request. Msg & data rates may apply. Reply STOP to opt out.', $body['consent_text'] );
		$this->assertFalse( $body['marketing_consent'] );
	}
}
