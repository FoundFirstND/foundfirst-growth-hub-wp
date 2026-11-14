<?php
/**
 * Elementor Pro form action "Send to Growth Hub".
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Elementor;

use Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Classes\Action_Base;
use FoundFirst\GrowthHub\Analytics\Tracking;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Leads\LeadPayload;
use FoundFirst\GrowthHub\Leads\LeadSender;
use FoundFirst\GrowthHub\Leads\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Appears under "Actions After Submit". Runs inside the visitor's form submission, so it must
 * never throw and never add an error to the AJAX handler: the visitor always sees the form's
 * normal success message, and the other actions (email etc.) run as usual.
 */
final class SendToGrowthHubAction extends Action_Base {

	/** Field types that carry no visitor input. */
	private const SKIP_TYPES = array( 'honeypot', 'recaptcha', 'recaptcha_v3', 'step', 'html', 'submit' );

	/** Field types that can carry a tick: the only ones accepted for the VIP and age boxes. */
	private const BOX_TYPES = array( 'acceptance', 'checkbox' );

	/** Values that mean "unchecked". */
	private const UNCHECKED = array( '', 'off', 'no', 'false', '0' );

	/**
	 * Constructor.
	 *
	 * @param LeadSender $sender Lead delivery.
	 */
	public function __construct( private LeadSender $sender ) {}

	/**
	 * Action id (stored in the form's settings, so never change it).
	 */
	public function get_name() {
		return 'growthhub';
	}

	/**
	 * Label in the editor.
	 */
	public function get_label() {
		return __( 'Send to Growth Hub', 'foundfirst-growth-hub' );
	}

	/**
	 * Per-form settings panel.
	 *
	 * @param \Elementor\Widget_Base $widget Form widget.
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'section_growthhub',
			array(
				'label'     => __( 'Growth Hub', 'foundfirst-growth-hub' ),
				'condition' => array( 'submit_actions' => $this->get_name() ),
			)
		);

		$widget->add_control(
			'ffgh_mapping_help',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Enter the field ID (Advanced tab of each form field) for each item. Leave blank to skip. Email or phone is required. The text club (VIP) and age (21+) boxes are optional: map them only on forms that have those checkboxes.', 'foundfirst-growth-hub' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$mapping = array(
			'ffgh_field_name'              => array( __( 'Name field ID', 'foundfirst-growth-hub' ), 'name' ),
			'ffgh_field_email'             => array( __( 'Email field ID', 'foundfirst-growth-hub' ), 'email' ),
			'ffgh_field_phone'             => array( __( 'Phone field ID', 'foundfirst-growth-hub' ), 'phone' ),
			'ffgh_field_message'           => array( __( 'Message field ID', 'foundfirst-growth-hub' ), 'message' ),
			'ffgh_field_sms_consent'       => array( __( 'SMS consent checkbox field ID', 'foundfirst-growth-hub' ), 'sms_consent' ),
			// Blank by default: a form only sends VIP consent or age confirmation once someone maps the box.
			'ffgh_field_marketing_consent' => array( __( 'Text club (VIP) consent field ID', 'foundfirst-growth-hub' ), '' ),
			'ffgh_field_age_confirmation'  => array( __( 'Age confirmation (21+) field ID', 'foundfirst-growth-hub' ), '' ),
		);

		foreach ( $mapping as $control => [ $label, $default ] ) {
			$widget->add_control(
				$control,
				array(
					'label'       => $label,
					'type'        => Controls_Manager::TEXT,
					'default'     => $default,
					'label_block' => false,
					'ai'          => array( 'active' => false ),
				)
			);
		}

		$widget->add_control(
			'ffgh_send_extra_fields',
			array(
				'label'        => __( 'Send all other fields as extra fields', 'foundfirst-growth-hub' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * Form export. Nothing to strip: our settings are field IDs, not secrets.
	 *
	 * @param array $element Exported element.
	 */
	public function on_export( $element ) {
		return $element;
	}

	/**
	 * Form submitted.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       Submission.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Response builder (never used for errors).
	 */
	public function run( $record, $ajax_handler ) {
		// Tell assets/tracking.js this form went to Growth Hub. It sends generate_lead on Elementor's
		// submit_success, which only fires when the whole submission succeeded. Only the form's name.
		try {
			if ( Options::analytics_enabled() && is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_response_data' ) ) {
				$ajax_handler->add_response_data(
					Tracking::RESPONSE_KEY,
					array( 'form_name' => Tracking::form_name( $record->get_form_settings( 'form_name' ), $record->get_form_settings( 'id' ) ) )
				);
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		try {
			$this->sender->submit( $this->build_payload( $record ) );
		} catch ( \Throwable $e ) {
			// Never surface Growth Hub problems to the visitor. Record them for the settings page.
			Status::record_error( time(), 0, 'Form action error: ' . $e->getMessage() );
		}
	}

	/**
	 * Build the POST /leads body from an Elementor submission.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Submission.
	 * @return array
	 */
	private function build_payload( $record ): array {
		$fields      = (array) $record->get( 'fields' );
		$definitions = $this->field_definitions( $record );

		$map = array();
		foreach ( array( 'name', 'email', 'phone', 'message', 'sms_consent', 'marketing_consent', 'age_confirmation' ) as $key ) {
			$map[ $key ] = trim( (string) $record->get_form_settings( 'ffgh_field_' . $key ) );
		}

		$value = static function ( string $id ) use ( $fields ): string {
			return '' !== $id && isset( $fields[ $id ]['value'] ) ? (string) $fields[ $id ]['value'] : '';
		};

		// Each box is only true when its mapped checkbox was ticked in this submission, and is sent
		// with the exact wording the visitor saw. Nothing is inferred from other fields.
		$consent_text   = $this->ticked_wording(
			$fields,
			$definitions,
			$map['sms_consent'],
			false,
			'SMS consent was ticked but the consent field has no label text, so consent was not sent. Add the wording to the field.'
		);
		$marketing_text = $this->ticked_wording(
			$fields,
			$definitions,
			$map['marketing_consent'],
			true,
			'Text club (VIP) consent was ticked but the field has no label text, so VIP consent was not sent. Add the wording to the field.'
		);
		$age_text       = $this->ticked_wording(
			$fields,
			$definitions,
			$map['age_confirmation'],
			true,
			'Age confirmation (21+) was ticked but the field has no label text, so it was not sent. Add the wording to the field.'
		);

		$extra = array();
		if ( 'yes' === $record->get_form_settings( 'ffgh_send_extra_fields' ) ) {
			$mapped = array_filter( $map, 'strlen' );
			foreach ( $fields as $id => $field ) {
				if ( in_array( (string) $id, $mapped, true ) || in_array( $field['type'] ?? '', self::SKIP_TYPES, true ) ) {
					continue;
				}
				$label = trim( wp_strip_all_tags( (string) ( $field['title'] ?? '' ) ) );
				$label = '' !== $label ? $label : (string) $id;
				if ( isset( $extra[ $label ] ) ) {
					$label .= ' (' . $id . ')';
				}
				$extra[ $label ] = $field['raw_value'] ?? $field['value'] ?? '';
			}
		}

		return LeadPayload::build(
			array(
				'idempotency_key'        => wp_generate_uuid4(),
				'name'                   => $value( $map['name'] ),
				'email'                  => $value( $map['email'] ),
				'phone'                  => $value( $map['phone'] ),
				'message'                => $value( $map['message'] ),
				'fields'                 => $extra,
				'form_name'              => (string) $record->get_form_settings( 'form_name' ),
				'page_url'               => $this->page_url(),
				'sms_consent'            => '' !== $consent_text,
				'consent_text'           => $consent_text,
				'marketing_consent'      => '' !== $marketing_text,
				'marketing_consent_text' => $marketing_text,
				'age_confirmed'          => '' !== $age_text,
				'age_confirmation_text'  => $age_text,
				'submitted_at'           => wp_date( 'c' ),
				'ip'                     => $this->visitor_ip(),
			)
		);
	}

	/**
	 * The wording of a mapped box, if the visitor ticked it in this submission; otherwise ''.
	 *
	 * @param array  $fields       Submitted fields.
	 * @param array  $definitions  Field settings by ID.
	 * @param string $id           Mapped field ID ('' = not mapped).
	 * @param bool   $require_box  Only accept Acceptance / Checkbox fields (a ticked box, never typed text).
	 * @param string $no_wording   Settings-page warning when the box was ticked but has no wording.
	 */
	private function ticked_wording( array $fields, array $definitions, string $id, bool $require_box, string $no_wording ): string {
		if ( '' === $id || ! isset( $fields[ $id ] ) || ! is_array( $fields[ $id ] ) ) {
			return '';
		}

		$field = $fields[ $id ];
		if ( $require_box && ! in_array( $field['type'] ?? '', self::BOX_TYPES, true ) ) {
			Status::record_error( time(), 0, sprintf( 'The field "%s" is mapped as a text club or age box but is not an Acceptance or Checkbox field, so it was sent as not ticked.', $id ) );
			return '';
		}

		$raw     = $field['raw_value'] ?? $field['value'] ?? '';
		$checked = is_array( $raw ) ? array() !== array_filter( $raw ) : ! in_array( strtolower( trim( (string) $raw ) ), self::UNCHECKED, true );
		if ( ! $checked ) {
			return '';
		}

		$wording = $this->consent_text( $field, $definitions[ $id ] ?? array() );
		if ( '' === $wording ) {
			Status::record_error( time(), 0, $no_wording );
		}

		return $wording;
	}

	/**
	 * The form's field settings keyed by field ID.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Submission.
	 * @return array<string, array>
	 */
	private function field_definitions( $record ): array {
		$out = array();
		foreach ( (array) $record->get_form_settings( 'form_fields' ) as $definition ) {
			if ( is_array( $definition ) && isset( $definition['custom_id'] ) ) {
				$out[ (string) $definition['custom_id'] ] = $definition;
			}
		}

		return $out;
	}

	/**
	 * The consent wording the visitor saw: the field label plus the acceptance text (or the
	 * ticked checkbox option), without HTML.
	 *
	 * @param array $field      Submitted field.
	 * @param array $definition Field settings.
	 */
	private function consent_text( array $field, array $definition ): string {
		$parts = array( (string) ( $definition['field_label'] ?? $field['title'] ?? '' ) );

		if ( 'acceptance' === ( $field['type'] ?? '' ) ) {
			$parts[] = (string) ( $definition['acceptance_text'] ?? '' );
		} elseif ( 'checkbox' === ( $field['type'] ?? '' ) ) {
			$parts[] = (string) ( $field['value'] ?? '' );
		}

		$clean = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $part ), ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' !== $part && ! in_array( $part, $clean, true ) ) {
				$clean[] = $part;
			}
		}

		return implode( ' ', $clean );
	}

	/**
	 * The page the form was on. Elementor posts it as "referrer" (its own nonce covers the request).
	 */
	private function page_url(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Elementor verified this submission.
		$url = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( (string) $_POST['referrer'] ) ) : '';
		if ( '' === $url && isset( $_POST['queried_id'] ) ) {
			$permalink = get_permalink( absint( $_POST['queried_id'] ) );
			$url       = is_string( $permalink ) ? $permalink : '';
		}
		// phpcs:enable

		return $url;
	}

	/**
	 * The visitor's IP. Uses REMOTE_ADDR only, because forwarded headers can be faked; sites
	 * behind Cloudflare or a proxy can use the ffgh_visitor_ip filter.
	 */
	private function visitor_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the visitor IP sent with leads.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'ffgh_visitor_ip', $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
