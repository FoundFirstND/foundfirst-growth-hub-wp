<?php
/**
 * Builds the POST /leads body.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw lead data into a body that fits API.md's limits, so a long message or an odd
 * field never turns into a 422 (which is never retried, so the lead would be lost).
 */
final class LeadPayload {

	private const MAX_FIELDS      = 50;
	private const MAX_FIELD_NAME  = 100;
	private const MAX_FIELD_VALUE = 2000;

	/**
	 * Build the request body.
	 *
	 * @param array $input Keys: idempotency_key, name, email, phone, message, fields (label => value),
	 *                     page_url, form_name, sms_consent, consent_text, submitted_at, ip.
	 * @return array
	 */
	public static function build( array $input ): array {
		$fields = is_array( $input['fields'] ?? null ) ? $input['fields'] : array();

		$body = array(
			'idempotency_key' => self::text( $input['idempotency_key'] ?? '', 100 ),
			'name'            => self::text( $input['name'] ?? '', 255 ),
			'email'           => self::text( $input['email'] ?? '', 255 ),
			'phone'           => self::text( $input['phone'] ?? '', 50 ),
			'message'         => self::text( $input['message'] ?? '', 5000 ),
			'page_url'        => self::text( $input['page_url'] ?? '', 2048 ),
			'form_name'       => self::text( $input['form_name'] ?? '', 255 ),
			'submitted_at'    => self::text( $input['submitted_at'] ?? '', 64 ),
			'ip'              => self::text( $input['ip'] ?? '', 64 ),
		);

		// An email the API would reject must not sink the whole lead: keep what was typed as an extra field.
		if ( '' !== $body['email'] && false === filter_var( $body['email'], FILTER_VALIDATE_EMAIL ) ) {
			$fields        = array( 'Email (as typed)' => $body['email'] ) + $fields;
			$body['email'] = '';
		}

		if ( '' !== $body['page_url'] && ! preg_match( '#^https?://#i', $body['page_url'] ) ) {
			$body['page_url'] = '';
		}

		if ( '' !== $body['ip'] && false === filter_var( $body['ip'], FILTER_VALIDATE_IP ) ) {
			$body['ip'] = '';
		}

		$body = array_filter( $body, static fn ( $value ) => '' !== $value );

		$consent_text = self::text( $input['consent_text'] ?? '', 1000 );
		if ( true === ( $input['sms_consent'] ?? false ) && '' !== $consent_text ) {
			$body['sms_consent']  = true;
			$body['consent_text'] = $consent_text;
		} else {
			$body['sms_consent'] = false;
		}

		$extra = self::fields( $fields );
		if ( array() !== $extra ) {
			$body['fields'] = $extra;
		}

		return $body;
	}

	/**
	 * Trim extra fields to the API's limits: max 50 entries, names max 100 chars, values
	 * text/number/bool max 2000 chars. Arrays are joined with ", ".
	 *
	 * @param array $fields Label => value.
	 * @return array
	 */
	private static function fields( array $fields ): array {
		$out = array();

		foreach ( $fields as $label => $value ) {
			if ( count( $out ) >= self::MAX_FIELDS ) {
				break;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
			}

			if ( is_string( $value ) ) {
				$value = self::text( $value, self::MAX_FIELD_VALUE );
				if ( '' === $value ) {
					continue;
				}
			} elseif ( ! is_int( $value ) && ! is_float( $value ) && ! is_bool( $value ) ) {
				continue;
			}

			$name = self::text( (string) $label, self::MAX_FIELD_NAME );
			if ( '' === $name ) {
				continue;
			}

			$out[ $name ] = $value;
		}

		return $out;
	}

	/**
	 * Trimmed string cut to a maximum length.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max characters.
	 */
	private static function text( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return mb_substr( trim( (string) $value ), 0, $max );
	}
}
