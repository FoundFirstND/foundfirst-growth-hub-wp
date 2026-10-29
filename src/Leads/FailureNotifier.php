<?php
/**
 * Emails the site admin when leads are failing.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Leads;

use FoundFirst\GrowthHub\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Sends at most one email a day to the site's admin address while leads are failing.
 */
final class FailureNotifier {

	private const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param QueueStore $queue Queue, for the counts in the email.
	 */
	public function __construct( private QueueStore $queue ) {}

	/**
	 * Email the admin unless an email already went out in the last 24 hours.
	 *
	 * @param int $now Current time.
	 */
	public function maybe_alert( int $now ): void {
		$last = (int) get_option( Options::LAST_ALERT, 0 );
		if ( $last > 0 && $now - $last < self::INTERVAL ) {
			return;
		}

		$to = (string) get_option( 'admin_email', '' );
		if ( '' === $to ) {
			return;
		}

		// Record first: if wp_mail is slow or broken we still don't send twice.
		Options::set( Options::LAST_ALERT, $now );

		$error = Status::last_error();
		$lines = array(
			sprintf( 'Website form leads on %s are having trouble reaching FoundFirst Growth Hub.', home_url() ),
			'',
			sprintf( 'Waiting to retry: %d', $this->queue->count( QueueStore::PENDING ) ),
			sprintf( 'Stopped (need attention): %d', $this->queue->count( QueueStore::FAILED ) ),
		);
		if ( null !== $error ) {
			$lines[] = sprintf( 'Last error: %s', $error['message'] );
		}
		$lines[] = '';
		$lines[] = 'If your forms also send email notifications, those still went out as usual.';
		$lines[] = sprintf( 'Details and a "Retry failed leads" button: %s', admin_url( 'options-general.php?page=foundfirst-growth-hub' ) );
		$lines[] = '';
		$lines[] = 'You will get at most one of these emails a day.';

		wp_mail(
			$to,
			sprintf( '[%s] Growth Hub: website leads are not being delivered', wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES ) ),
			implode( "\n", $lines )
		);
	}
}
