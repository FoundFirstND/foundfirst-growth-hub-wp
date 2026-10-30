<?php
/**
 * Settings → Growth Hub.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Settings;

use FoundFirst\GrowthHub\Api\Client;
use FoundFirst\GrowthHub\Leads\QueueStore;
use FoundFirst\GrowthHub\Leads\Status;
use FoundFirst\GrowthHub\Leads\WpdbQueue;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Plugin;
use FoundFirst\GrowthHub\Promo\PromoService;

defined( 'ABSPATH' ) || exit;

/**
 * The admin screen. Forms post to admin-post.php (not options.php) so we control how the site
 * key is saved: autoload off, and a blank field keeps the saved key. The key is never printed.
 */
final class SettingsPage {

	public const SLUG       = 'foundfirst-growth-hub';
	private const CAP       = 'manage_options';
	private const SAVE      = 'ffgh_save_settings';
	private const TEST      = 'ffgh_test_connection';
	private const RETRY     = 'ffgh_retry_failed';
	private const NOTICE    = 'ffgh_notice_';
	private const KEY_REGEX = '/^ghk_[A-Za-z0-9]{8,200}$/';

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::TEST, array( $this, 'handle_test' ) );
		add_action( 'admin_post_' . self::RETRY, array( $this, 'handle_retry' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FFGH_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Settings → Growth Hub.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'FoundFirst Growth Hub', 'foundfirst-growth-hub' ),
			__( 'Growth Hub', 'foundfirst-growth-hub' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'foundfirst-growth-hub' ) )
		);

		return $links;
	}

	/**
	 * Save the base URL and site key.
	 */
	public function handle_save(): void {
		$this->require_capability();
		check_admin_referer( self::SAVE );

		$url = isset( $_POST['ffgh_base_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $_POST['ffgh_base_url'] ) ), array( 'http', 'https' ) ) : '';
		if ( '' === $url ) {
			$url = Options::DEFAULT_BASE_URL;
		}
		$url = untrailingslashit( preg_replace( '#/api/v1/?$#', '', $url ) );

		$old_url = Options::base_url();
		$old_key = Options::site_key();
		Options::set( Options::BASE_URL, $url );
		Options::set( Options::PROMO_IMAGE, empty( $_POST['ffgh_show_promo_image'] ) ? '0' : '1' );

		$key    = isset( $_POST['ffgh_site_key'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['ffgh_site_key'] ) ) ) : '';
		$remove = ! empty( $_POST['ffgh_remove_key'] );

		if ( $remove ) {
			delete_option( Options::SITE_KEY );
			$this->notice( 'success', __( 'Settings saved. The site key was removed.', 'foundfirst-growth-hub' ) );
		} elseif ( '' !== $key && ! preg_match( self::KEY_REGEX, $key ) ) {
			$this->notice( 'error', __( 'That doesn\'t look like a Growth Hub site key (they start with ghk_). The key was not changed.', 'foundfirst-growth-hub' ) );
		} else {
			if ( '' !== $key ) {
				Options::set( Options::SITE_KEY, $key );
			}
			$this->notice( 'success', __( 'Settings saved.', 'foundfirst-growth-hub' ) );
		}

		if ( Options::base_url() !== $old_url || Options::site_key() !== $old_key ) {
			PromoService::flush();
			// Leads that failed because of a bad key or URL can go through now.
			if ( '' !== Options::site_key() ) {
				Plugin::lead_sender()->retry_failed();
			}
		}

		$this->back();
	}

	/**
	 * Test connection: an authenticated GET /promo from the server.
	 */
	public function handle_test(): void {
		$this->require_capability();
		check_admin_referer( self::TEST );

		if ( '' === Options::site_key() ) {
			$this->notice( 'error', __( 'Save a site key first.', 'foundfirst-growth-hub' ) );
			$this->back();
		}

		$response = ( new Client() )->request( 'GET', '/promo', null, 10 );

		if ( 200 === $response->status ) {
			$this->notice( 'success', __( 'Connected to Growth Hub. The site key works.', 'foundfirst-growth-hub' ) );
		} elseif ( 401 === $response->status ) {
			$this->notice( 'error', __( 'Growth Hub rejected the site key (invalid or revoked). Create a new connection in Growth Hub and paste the new key. Don\'t test a bad key repeatedly: too many wrong keys block this server for a minute.', 'foundfirst-growth-hub' ) );
		} elseif ( 429 === $response->status ) {
			$this->notice( 'warning', __( 'Growth Hub is rate limiting this site right now. Try again in a minute.', 'foundfirst-growth-hub' ) );
		} elseif ( 0 === $response->status ) {
			/* translators: %s: error message */
			$this->notice( 'error', sprintf( __( 'Couldn\'t reach Growth Hub: %s', 'foundfirst-growth-hub' ), $response->describe() ) );
		} else {
			/* translators: %s: HTTP status and message */
			$this->notice( 'error', sprintf( __( 'Unexpected response from Growth Hub: %s', 'foundfirst-growth-hub' ), $response->describe() ) );
		}

		$this->back();
	}

	/**
	 * Requeue failed leads.
	 */
	public function handle_retry(): void {
		$this->require_capability();
		check_admin_referer( self::RETRY );

		$count = Plugin::lead_sender()->retry_failed();
		$this->notice(
			'success',
			/* translators: %d: number of leads */
			sprintf( _n( '%d lead will be retried in the next minute or so.', '%d leads will be retried in the next minute or so.', $count, 'foundfirst-growth-hub' ), $count )
		);

		$this->back();
	}

	/**
	 * The settings screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$queue      = new WpdbQueue();
		$pending    = $queue->count( QueueStore::PENDING );
		$failed     = $queue->count( QueueStore::FAILED );
		$has_key    = '' !== Options::site_key();
		$success_at = Status::last_success();
		$error      = Status::last_error();
		$notice     = get_transient( self::NOTICE . get_current_user_id() );
		delete_transient( self::NOTICE . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FoundFirst Growth Hub', 'foundfirst-growth-hub' ); ?></h1>

			<?php if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
				<?php wp_nonce_field( self::SAVE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffgh_base_url"><?php esc_html_e( 'API base URL', 'foundfirst-growth-hub' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="ffgh_base_url" name="ffgh_base_url" value="<?php echo esc_attr( Options::base_url() ); ?>">
							<p class="description">
								<?php
								/* translators: %s: default URL */
								printf( esc_html__( 'Leave as %s unless FoundFirst tells you otherwise.', 'foundfirst-growth-hub' ), '<code>' . esc_html( Options::DEFAULT_BASE_URL ) . '</code>' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffgh_site_key"><?php esc_html_e( 'Site key', 'foundfirst-growth-hub' ); ?></label></th>
						<td>
							<input type="password" class="regular-text code" id="ffgh_site_key" name="ffgh_site_key" value="" autocomplete="new-password" spellcheck="false"
								placeholder="<?php echo $has_key ? esc_attr__( '•••••••• (saved)', 'foundfirst-growth-hub' ) : 'ghk_…'; ?>">
							<p class="description">
								<?php
								echo $has_key
									? esc_html__( 'A site key is saved. Leave this blank to keep it, or paste a new key to replace it.', 'foundfirst-growth-hub' )
									: esc_html__( 'Create one in Growth Hub under Businesses → (business) → Website connections → New connection. It is shown only once.', 'foundfirst-growth-hub' );
								?>
							</p>
							<?php if ( $has_key ) : ?>
								<label><input type="checkbox" name="ffgh_remove_key" value="1"> <?php esc_html_e( 'Remove the saved key', 'foundfirst-growth-hub' ); ?></label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Promo banner', 'foundfirst-growth-hub' ); ?></th>
						<td>
							<label><input type="checkbox" name="ffgh_show_promo_image" value="1" <?php checked( Options::show_promo_image() ); ?>> <?php esc_html_e( 'Show photo on the banner', 'foundfirst-growth-hub' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default: the banner shows only the text. Override per banner with [growthhub_promo image="yes"] or image="no", or the Elementor widget\'s Show photo switch.', 'foundfirst-growth-hub' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'foundfirst-growth-hub' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST ); ?>">
				<?php wp_nonce_field( self::TEST ); ?>
				<?php submit_button( __( 'Test connection', 'foundfirst-growth-hub' ), 'secondary', 'submit', false, $has_key ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Lead delivery', 'foundfirst-growth-hub' ); ?></h2>
			<table class="widefat striped" style="max-width:48em">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last lead delivered', 'foundfirst-growth-hub' ); ?></th>
						<td><?php echo null !== $success_at ? esc_html( self::format_time( $success_at ) ) : esc_html__( 'Never', 'foundfirst-growth-hub' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last error', 'foundfirst-growth-hub' ); ?></th>
						<td>
							<?php
							if ( null === $error ) {
								esc_html_e( 'None', 'foundfirst-growth-hub' );
							} else {
								echo esc_html( self::format_time( (int) $error['time'] ) . ': ' . $error['message'] );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Leads waiting to retry', 'foundfirst-growth-hub' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $pending ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Leads that stopped retrying', 'foundfirst-growth-hub' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $failed ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( $failed > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RETRY ); ?>">
					<?php wp_nonce_field( self::RETRY ); ?>
					<?php submit_button( __( 'Retry failed leads now', 'foundfirst-growth-hub' ), 'secondary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Use this after fixing the site key or URL. Leads Growth Hub rejected as invalid (for example, no email or phone) are not retried. Failed leads are deleted after 30 days.', 'foundfirst-growth-hub' ); ?></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Capability check for admin-post handlers (each handler checks its nonce right after).
	 */
	private function require_capability(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'foundfirst-growth-hub' ), 403 );
		}
	}

	/**
	 * Store a one-time notice for the current user.
	 *
	 * @param string $type    success, warning or error.
	 * @param string $message Message (never include the site key).
	 */
	private function notice( string $type, string $message ): void {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Redirect back to the settings page.
	 */
	private function back(): void {
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Settings page URL.
	 */
	private static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Site-timezone date and time.
	 *
	 * @param int $timestamp Unix time.
	 */
	private static function format_time( int $timestamp ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
