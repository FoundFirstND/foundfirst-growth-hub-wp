<?php
/**
 * Plugin bootstrap.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub;

use FoundFirst\GrowthHub\Api\Client;
use FoundFirst\GrowthHub\Elementor\PromoWidget;
use FoundFirst\GrowthHub\Elementor\SendToGrowthHubAction;
use FoundFirst\GrowthHub\Leads\FailureNotifier;
use FoundFirst\GrowthHub\Leads\LeadSender;
use FoundFirst\GrowthHub\Leads\RetryPolicy;
use FoundFirst\GrowthHub\Leads\WpdbQueue;
use FoundFirst\GrowthHub\Promo\PromoRenderer;
use FoundFirst\GrowthHub\Promo\PromoService;
use FoundFirst\GrowthHub\Settings\SettingsPage;
use FoundFirst\GrowthHub\Updates\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Registers hooks and builds the services (lazily, so normal page views do almost nothing).
 */
final class Plugin {

	/** Bump when the queue table changes; the table is upgraded on the next page load. */
	public const DB_VERSION = '1';

	/**
	 * Lead sender instance.
	 *
	 * @var LeadSender|null
	 */
	private static ?LeadSender $sender = null;

	/**
	 * Promo renderer instance.
	 *
	 * @var PromoRenderer|null
	 */
	private static ?PromoRenderer $renderer = null;

	/**
	 * Register hooks. Called once from the main plugin file.
	 */
	public static function boot(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybe_upgrade' ) );
		add_action( 'init', array( self::class, 'init' ) );

		add_action( LeadSender::PROCESS_HOOK, array( self::class, 'process_queue' ) );
		add_action( LeadSender::SWEEP_HOOK, array( self::class, 'process_queue' ) );

		// These hooks only fire when Elementor Pro / Elementor are active.
		add_action( 'elementor_pro/forms/actions/register', array( self::class, 'register_form_action' ) );
		add_action( 'elementor/widgets/register', array( self::class, 'register_widget' ) );

		if ( is_admin() ) {
			( new SettingsPage() )->register();
		}

		Updater::register();
	}

	/**
	 * Activation: create the table and schedule the hourly sweep.
	 */
	public static function activate(): void {
		WpdbQueue::create_table();
		// Autoloaded (unlike our other options): maybe_upgrade() reads it on every request.
		update_option( Options::DB_VERSION, self::DB_VERSION, true );

		if ( false === wp_next_scheduled( LeadSender::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', LeadSender::SWEEP_HOOK );
		}
	}

	/**
	 * Deactivation: stop cron. Queued leads stay in the table and resume on reactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( LeadSender::PROCESS_HOOK );
		wp_clear_scheduled_hook( LeadSender::SWEEP_HOOK );
	}

	/**
	 * Updates don't run the activation hook, so check the schema version on every load.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( Options::DB_VERSION ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Register the shortcode and front-end style.
	 */
	public static function init(): void {
		self::promo_renderer()->register();
	}

	/**
	 * Cron callback.
	 */
	public static function process_queue(): void {
		try {
			self::lead_sender()->process_queue();
		} catch ( \Throwable $e ) {
			Leads\Status::record_error( time(), 0, 'Queue processing error: ' . $e->getMessage() );
		}
	}

	/**
	 * Add "Send to Growth Hub" to Elementor Pro's form actions.
	 *
	 * @param \ElementorPro\Modules\Forms\Registrars\Form_Actions_Registrar $registrar Registrar.
	 */
	public static function register_form_action( $registrar ): void {
		$registrar->register( new SendToGrowthHubAction( self::lead_sender() ) );
	}

	/**
	 * Add the promo widget to Elementor.
	 *
	 * @param \Elementor\Widgets_Manager $widgets Widgets manager.
	 */
	public static function register_widget( $widgets ): void {
		$widgets->register( new PromoWidget() );
	}

	/**
	 * Shared lead sender.
	 */
	public static function lead_sender(): LeadSender {
		if ( null === self::$sender ) {
			$queue        = new WpdbQueue();
			self::$sender = new LeadSender( new Client(), $queue, new RetryPolicy(), new FailureNotifier( $queue ) );
		}

		return self::$sender;
	}

	/**
	 * Shared promo renderer.
	 */
	public static function promo_renderer(): PromoRenderer {
		if ( null === self::$renderer ) {
			self::$renderer = new PromoRenderer( new PromoService( new Client() ) );
		}

		return self::$renderer;
	}
}
