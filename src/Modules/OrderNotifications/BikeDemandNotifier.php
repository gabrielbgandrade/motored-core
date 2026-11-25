<?php
/**
 * Availability-driven stock alerts: WAHA first, native WooCommerce mail as last resort.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderNotifications;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Integration\BikeDemandHooks;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Modules\BikePages\Availability;
use Motored\Core\Modules\BikePages\Config as BikeConfig;
use Motored\Core\Modules\BikePages\DemandStore;
use Motored\Core\Modules\BikePages\HubLinks;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\OrderNotifications\Emails\StockAlertEmail;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Modules\Waha\Message;
use Motored\Core\Modules\Waha\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriptions wait for a matching purchasable tire, not merely a stock change on any product.
 */
final class BikeDemandNotifier {

	public const KIND       = 'bike_stock_alert';
	public const SWEEP_HOOK = 'motored_bike_demand_scan';
	public const DAILY_HOOK = 'motored_bike_demand_delivery_daily';
	public const MAIL_HOOK  = 'motored_bike_demand_mail';

	/**
	 * Registers listeners only; no reads, HTTP or scheduling on page views.
	 *
	 * @param bool $worker Whether this context runs background work.
	 */
	public function register( bool $worker ): void {
		// Native e-mail: listed in WooCommerce > Settings > Emails (enable, subject, heading, preview).
		add_filter( 'woocommerce_email_classes', array( self::class, 'register_email_class' ) );
		add_action( 'motored_bike_demand_created', array( self::class, 'queue_scan' ), 10, 0 );
		add_action( 'motored_compat_product_index_changed', array( self::class, 'queue_scan' ), 30, 0 );
		add_action( 'motored_compat_catalog_changed', array( self::class, 'queue_scan' ), 30, 0 );
		if ( $worker ) {
			add_action( self::SWEEP_HOOK, array( $this, 'scan' ), 10, 1 );
			add_action( self::DAILY_HOOK, array( self::class, 'queue_scan' ) );
			add_action( self::MAIL_HOOK, array( $this, 'send_mail' ), 10, 2 );
			Sender::register_handler( self::KIND, array( $this, 'build_message' ) );
			add_action( Sender::RESULT_HOOK, array( $this, 'on_result' ), 10, 4 );
		}
	}

	/** Queues bounded work after a real subscription/catalog mutation. */
	public static function queue_scan(): void {
		if ( BikeConfig::notify_enabled() && ! ActionScheduler::has_scheduled( self::SWEEP_HOOK ) ) {
			ActionScheduler::schedule( time() + 10, self::SWEEP_HOOK, array( 0 ), true );
		}
	}

	/** Daily reconciliation is installed only on an administrative request. */
	public static function ensure_schedule(): void {
		if ( BikeConfig::notify_enabled() && ! ActionScheduler::has_scheduled( self::DAILY_HOOK ) ) {
			ActionScheduler::schedule_recurring( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::DAILY_HOOK );
		}
	}

	/**
	 * Claims eligible subscriptions atomically before putting an ID-only job on the transport queue.
	 *
	 * @param int $cursor Last processed subscription ID.
	 */
	public function scan( int $cursor = 0 ): void {
		if ( ! BikeConfig::notify_enabled() ) {
			return;
		}
		$ids = DemandStore::pending_ids( $cursor );
		// Many customers wait for the same bike/axle: evaluate each pair once per batch, not once per row.
		$seen = array();
		foreach ( $ids as $id ) {
			$row = DemandStore::find( $id );
			if ( null === $row ) {
				continue;
			}
			$pair = $row['version_slug'] . '|' . $row['position'];
			if ( ! isset( $seen[ $pair ] ) ) {
				$seen[ $pair ] = self::available( $row );
			}
			if ( ! $seen[ $pair ] ) {
				continue;
			}
			$state = (string) $row['status'];
			if ( WahaConfig::MODE_OFF === WahaConfig::mode() ) {
				DemandStore::transition( $id, $state, 'paused', '', Sender::CODE_DISABLED );
				continue;
			}
			$channel = '' !== (string) $row['phone'] ? 'whatsapp' : 'email';
			if ( 'email' === $channel && WahaConfig::MODE_LIVE !== WahaConfig::mode() ) {
				DemandStore::transition( $id, $state, 'paused', 'email', Sender::CODE_TEST_MODE );
				continue;
			}
			if ( 'email' === $channel && self::email_disabled() ) {
				DemandStore::transition( $id, $state, 'paused', 'email', 'email_disabled' );
				continue;
			}
			if ( ! DemandStore::transition( $id, $state, 'queued', $channel ) ) {
				continue;
			}
			$queued = 'whatsapp' === $channel
				? Sender::enqueue( self::KIND, $id )
				: ActionScheduler::schedule( time() + 1, self::MAIL_HOOK, array( $id, 1 ), true );
			if ( ! $queued ) {
				DemandStore::transition( $id, 'queued', 'pending', '', 'schedule_failed' );
			}
		}
		if ( 50 === count( $ids ) ) {
			ActionScheduler::schedule( time() + 1, self::SWEEP_HOOK, array( (int) end( $ids ) ), true );
		}
	}

	/**
	 * Rechecks stock at send time. A sold-out product must never trigger a stale alert.
	 *
	 * @param array<string,mixed> $row Subscription.
	 */
	public static function available( array $row ): bool {
		$slug = (string) ( $row['version_slug'] ?? '' );
		if ( '' === $slug ) {
			return false; // Legacy rows need an explicit version; never guess another model/year.
		}
		return Availability::count( MatchEngine::matching_product_ids( $slug, 'all' === $row['position'] ? '' : (string) $row['position'] ), 1 ) > 0;
	}

	/**
	 * Builds a WhatsApp message only while its claimed subscription is eligible.
	 *
	 * @param int         $id   Subscription ID.
	 * @param string|null $step Transport step.
	 */
	public function build_message( int $id, ?string $step = null ): ?Message {
		unset( $step );
		$row = DemandStore::find( $id );
		if ( null === $row || ! in_array( $row['status'], array( 'queued', 'sending' ), true ) || ! BikeConfig::notify_enabled() ) {
			return null;
		}
		if ( ! self::available( $row ) ) {
			DemandStore::transition( $id, (string) $row['status'], 'pending' );
			return null;
		}
		if ( 'queued' === $row['status'] && ! DemandStore::transition( $id, 'queued', 'sending', 'whatsapp' ) ) {
			return null;
		}
		return new Message( (string) $row['phone'], self::text( $row ) );
	}

	/**
	 * Handles only terminal transport results (WAHA performs its own bounded retries first).
	 *
	 * @param string      $kind   Message kind.
	 * @param int         $id     Subscription ID.
	 * @param string|null $step   Transport step.
	 * @param Result      $result Terminal transport outcome.
	 */
	public function on_result( string $kind, int $id, ?string $step, Result $result ): void {
		unset( $step );
		if ( self::KIND !== $kind ) {
			return;
		}
		$row = DemandStore::find( $id );
		if ( null === $row || ! in_array( $row['status'], array( 'queued', 'sending' ), true ) ) {
			return;
		}
		$state = (string) $row['status'];
		if ( $result->ok ) {
			if ( DemandStore::transition( $id, $state, 'notified', 'whatsapp' ) ) {
				BikeDemandHooks::notified( $id, 'whatsapp', true );
			}
			return;
		}
		// Operational safety switches must NEVER be bypassed by an email fallback.
		if ( in_array( $result->code, array( Sender::CODE_DISABLED, Sender::CODE_TEST_MODE, 'not_configured', 'auth' ), true ) ) {
			DemandStore::transition( $id, $state, 'paused', 'whatsapp', $result->code );
			return;
		}
		BikeDemandHooks::notified( $id, 'whatsapp', false );
		if ( 'skipped' !== $result->code && '' !== $row['email'] && WahaConfig::MODE_LIVE === WahaConfig::mode() && ! self::email_disabled() ) {
			if ( DemandStore::transition( $id, $state, 'queued', 'email', $result->code ) ) {
				if ( ! ActionScheduler::schedule( time() + 1, self::MAIL_HOOK, array( $id, 1 ), true ) ) {
					DemandStore::transition( $id, 'queued', 'failed', 'email', 'schedule_failed' );
				}
			}
			return;
		}
		DemandStore::transition( $id, $state, 'failed', 'whatsapp', $result->code );
	}

	/**
	 * Native WooCommerce mail, only with no phone or after a terminal WhatsApp failure.
	 *
	 * @param int $id      Subscription ID.
	 * @param int $attempt Bounded email attempt.
	 */
	public function send_mail( int $id, int $attempt = 1 ): void {
		$row = DemandStore::find( $id );
		if ( null === $row || 'queued' !== $row['status'] || 'email' !== $row['channel'] ) {
			return;
		}
		if ( WahaConfig::MODE_LIVE !== WahaConfig::mode() || ! BikeConfig::notify_enabled() ) {
			DemandStore::transition( $id, 'queued', 'paused', 'email', 'delivery_paused' );
			return;
		}
		if ( ! self::available( $row ) ) {
			DemandStore::transition( $id, 'queued', 'pending' );
			return;
		}
		if ( ! DemandStore::transition( $id, 'queued', 'sending', 'email' ) ) {
			return;
		}
		$email = self::stock_alert_email();
		if ( null !== $email && ! $email->is_enabled() ) {
			// The owner switched this e-mail off in WooCommerce > Settings > Emails: hold it, do not fail it.
			DemandStore::transition( $id, 'sending', 'paused', 'email', 'email_disabled' );
			return;
		}
		try {
			$parts = self::parts( $row );
			$ok    = null !== $email && $email->trigger_alert( (string) $row['email'], $parts['bike'], $parts['axle'], $parts['url'], $parts['cancel'] );
		} catch ( \Throwable $error ) {
			// Mailer exceptions must not strand a claimed row or expose contact/provider details in logs.
			$ok = false;
		}
		BikeDemandHooks::notified( $id, 'email', (bool) $ok );
		if ( $ok ) {
			DemandStore::transition( $id, 'sending', 'notified', 'email' );
		} elseif ( $attempt < 3 ) {
			DemandStore::transition( $id, 'sending', 'queued', 'email', 'mail_failed' );
			if ( ! ActionScheduler::schedule( time() + ( 1 === $attempt ? 60 : 300 ), self::MAIL_HOOK, array( $id, $attempt + 1 ), true ) ) {
				DemandStore::transition( $id, 'queued', 'failed', 'email', 'schedule_failed' );
			}
		} else {
			DemandStore::transition( $id, 'sending', 'failed', 'email', 'mail_failed' );
		}
	}

	/**
	 * Plain text shared by both channels; no contact data in URLs or scheduler arguments.
	 *
	 * @param array<string,mixed> $row Subscription.
	 */
	private static function text( array $row ): string {
		$parts = self::parts( $row );
		// translators: 1: motorcycle, 2: axle, 3: catalog URL, 4: unsubscribe URL.
		$text = sprintf( __( 'Compatible tires are available for your %1$s (%2$s). Check current stock and specifications: %3$s\nCancel this alert: %4$s', 'motored-core' ), $parts['bike'], $parts['axle'], $parts['url'], $parts['cancel'] );
		return BikeDemandHooks::filter_message( str_replace( '\\n', "\n", $text ), array( 'demand_id' => (int) $row['id'] ) );
	}

	/**
	 * Message parts shared by WhatsApp and the native e-mail.
	 *
	 * @param array<string,mixed> $row Subscription.
	 * @return array{bike:string,axle:string,url:string,cancel:string}
	 */
	private static function parts( array $row ): array {
		$slug = (string) $row['version_slug'];
		$url  = ( new HubLinks() )->filter_bike_page_url( '', $slug );
		if ( '' === $url ) {
			$url = add_query_arg(
				array(
					'moto' => $slug,
					'pos'  => $row['position'],
				),
				\Motored\Core\Modules\Compatibility\Config::shop_url()
			);
		}
		return array(
			'bike'   => trim( $row['brand_name'] . ' ' . $row['model_name'] . ' ' . $row['years_label'] ),
			'axle'   => \Motored\Core\Modules\Compatibility\TireSpecs::position_label( 'all' === $row['position'] ? 'both' : (string) $row['position'] ),
			'url'    => $url,
			'cancel' => DemandStore::unsubscribe_url( $row ),
		);
	}

	/**
	 * Registers the native stock-alert e-mail with WooCommerce.
	 *
	 * @param array<string,mixed> $emails Existing e-mail classes.
	 * @return array<string,mixed>
	 */
	public static function register_email_class( array $emails ): array {
		if ( class_exists( 'WC_Email' ) ) {
			$emails[ StockAlertEmail::CLASS_KEY ] = new StockAlertEmail();
		}
		return $emails;
	}

	/**
	 * Whether the owner explicitly switched the native e-mail off (unknown without a mailer: let the worker decide).
	 */
	private static function email_disabled(): bool {
		$email = self::stock_alert_email();
		return null !== $email && ! $email->is_enabled();
	}

	/**
	 * The instance WooCommerce loaded (it carries the owner's saved settings), or null without a mailer.
	 */
	private static function stock_alert_email(): ?StockAlertEmail {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ StockAlertEmail::CLASS_KEY ] ?? null;
		return $email instanceof StockAlertEmail ? $email : null;
	}
}
