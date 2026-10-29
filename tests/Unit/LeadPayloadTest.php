<?php
/**
 * LeadPayload tests.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use FoundFirst\GrowthHub\Leads\LeadPayload;
use FoundFirst\GrowthHub\Tests\TestCase;

final class LeadPayloadTest extends TestCase {

	public function test_builds_the_documented_body(): void {
		$body = LeadPayload::build(
			array(
				'idempotency_key' => '0b8f2c36-6a4e-4d0e-9d55-2f1f0c6f8a11',
				'name'            => ' Jane Doe ',
				'email'           => 'jane@example.com',
				'phone'           => '(701) 555-0100',
				'message'         => 'Do you plow driveways on Saturday?',
				'fields'          => array(
					'Service' => 'Driveway plowing',
					'Extras'  => array( 'Plowing', 'Salting' ),
				),
				'page_url'        => 'https://redriversnow.com/contact',
				'form_name'       => 'Contact Us',
				'sms_consent'     => true,
				'consent_text'    => 'Yes, text me about my request.',
				'submitted_at'    => '2026-10-28T14:30:00-05:00',
				'ip'              => '203.0.113.9',
			)
		);

		$this->assertSame(
			array(
				'idempotency_key' => '0b8f2c36-6a4e-4d0e-9d55-2f1f0c6f8a11',
				'name'            => 'Jane Doe',
				'email'           => 'jane@example.com',
				'phone'           => '(701) 555-0100',
				'message'         => 'Do you plow driveways on Saturday?',
				'page_url'        => 'https://redriversnow.com/contact',
				'form_name'       => 'Contact Us',
				'submitted_at'    => '2026-10-28T14:30:00-05:00',
				'ip'              => '203.0.113.9',
				'sms_consent'     => true,
				'consent_text'    => 'Yes, text me about my request.',
				'fields'          => array(
					'Service' => 'Driveway plowing',
					'Extras'  => 'Plowing, Salting',
				),
			),
			$body
		);
	}

	public function test_empty_values_are_left_out_and_consent_defaults_to_false(): void {
		$body = LeadPayload::build(
			array(
				'idempotency_key' => 'k',
				'email'           => 'a@b.co',
				'name'            => '',
				'fields'          => array( 'Empty' => '' ),
			)
		);

		$this->assertSame(
			array(
				'idempotency_key' => 'k',
				'email'           => 'a@b.co',
				'sms_consent'     => false,
			),
			$body
		);
	}

	public function test_consent_without_wording_is_not_sent_as_consent(): void {
		$body = LeadPayload::build(
			array(
				'idempotency_key' => 'k',
				'phone'           => '7015550100',
				'sms_consent'     => true,
				'consent_text'    => '  ',
			)
		);

		$this->assertFalse( $body['sms_consent'] );
		$this->assertArrayNotHasKey( 'consent_text', $body );
	}

	public function test_consent_must_be_strictly_true(): void {
		$body = LeadPayload::build(
			array(
				'idempotency_key' => 'k',
				'phone'           => '7015550100',
				'sms_consent'     => 'on',
				'consent_text'    => 'Text me',
			)
		);

		$this->assertFalse( $body['sms_consent'] );
	}

	public function test_invalid_email_moves_to_extra_fields(): void {
		$body = LeadPayload::build(
			array(
				'idempotency_key' => 'k',
				'email'           => 'jane at example',
				'phone'           => '7015550100',
			)
		);

		$this->assertArrayNotHasKey( 'email', $body );
		$this->assertSame( array( 'Email (as typed)' => 'jane at example' ), $body['fields'] );
	}

	public function test_invalid_url_and_ip_are_dropped(): void {
		$body = LeadPayload::build(
			array(
				'idempotency_key' => 'k',
				'email'           => 'a@b.co',
				'page_url'        => 'javascript:alert(1)',
				'ip'              => 'not-an-ip',
			)
		);

		$this->assertArrayNotHasKey( 'page_url', $body );
		$this->assertArrayNotHasKey( 'ip', $body );
	}

	public function test_values_are_cut_to_api_limits(): void {
		$fields = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$fields[ "Field {$i}" ] = "Value {$i}";
		}
		$fields['Field 1'] = str_repeat( 'x', 2500 );
		$long_label        = str_repeat( 'L', 150 );

		$body = LeadPayload::build(
			array(
				'idempotency_key' => 'k',
				'email'           => 'a@b.co',
				'name'            => str_repeat( 'n', 300 ),
				'message'         => str_repeat( 'é', 6000 ),
				'phone'           => str_repeat( '1', 60 ),
				'consent_text'    => str_repeat( 'c', 1200 ),
				'sms_consent'     => true,
				'fields'          => array( $long_label => 'v' ) + $fields,
			)
		);

		$this->assertSame( 255, mb_strlen( $body['name'] ) );
		$this->assertSame( 5000, mb_strlen( $body['message'] ) );
		$this->assertSame( 50, mb_strlen( $body['phone'] ) );
		$this->assertSame( 1000, mb_strlen( $body['consent_text'] ) );
		$this->assertCount( 50, $body['fields'] );
		$this->assertArrayHasKey( str_repeat( 'L', 100 ), $body['fields'] );
		$this->assertSame( 2000, mb_strlen( $body['fields']['Field 1'] ) );
	}

	public function test_extra_field_values_keep_numbers_and_booleans_and_drop_other_types(): void {
		$body = LeadPayload::build(
			array(
				'idempotency_key' => 'k',
				'email'           => 'a@b.co',
				'fields'          => array(
					'Count'  => 3,
					'Price'  => 9.5,
					'Agreed' => true,
					'Object' => new \stdClass(),
					'Null'   => null,
				),
			)
		);

		$this->assertSame(
			array(
				'Count'  => 3,
				'Price'  => 9.5,
				'Agreed' => true,
			),
			$body['fields']
		);
	}
}
