<?php
/**
 * Send-time logic of review requests.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewRequests;

use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\OrderNotifications\Renderer;
use Motored\Core\Modules\Recovery\Config as RecoveryConfig;
use Motored\Core\Modules\Recovery\MessageLimiter;
use Motored\Core\Modules\Recovery\Schedule;
use Motored\Core\Modules\Recovery\Suppression;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Modules\Waha\Message;
use Motored\Core\Modules\Waha\Phone;
use Motored\Core\Modules\Waha\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Handler registered with Sender::register_handler( 'review_request', ... ). ALWAYS reloads the order.
 * Returns null (skip) when the request no longer makes sense; outside the window or over the per-number
 * limit it re-enqueues itself instead of skipping. Step 'manual' (admin button) ignores the trigger
 * status, delay, window and "already sent", but never suppression, refunds or invalid phones.
 */
final class RequestHandler {

	public const MANUAL = 'manual';

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $facts      fn( int $order_id ): ?array order facts (tests).
	 * @param \Closure|null $reviewed   fn( array $facts ): list<int> product ids already reviewed (tests).
	 * @param \Closure|null $suppressed fn( string $phone ): bool (tests).
	 * @param \Closure|null $requeue    fn( int $order_id, int $delay ): void (tests).
	 * @param \Closure|null $clock      Returns unix time (tests).
	 * @param \Closure|null $url        fn( int $product_id ): string product URL (tests).
	 */
	public function __construct(
		private ?\Closure $facts = null,
		private ?\Closure $reviewed = null,
		private ?\Closure $suppressed = null,
		private ?\Closure $requeue = null,
		private ?\Closure $clock = null,
		private ?\Closure $url = null
	) {
	}

	/**
	 * Builds the message or returns null to skip.
	 *
	 * @param int         $order_id Order ID.
	 * @param string|null $step     null (automatic) or 'manual'.
	 */
	public function handle( int $order_id, ?string $step ): ?Message {
		$manual = self::MANUAL === $step;
		if ( $order_id <= 0 || ( ! $manual && ! Config::enabled() ) ) {
			return null;
		}
		$facts = null !== $this->facts ? ( $this->facts )( $order_id ) : self::load_facts( $order_id );
		if ( ! is_array( $facts ) || $facts['refunded'] || ! ReviewRules::status_allows( $facts['status'], Config::trigger_status(), $manual ) ) {
			return null;
		}

		$code  = '' !== $facts['calling_code'] ? $facts['calling_code'] : WahaConfig::country_code();
		$phone = '' === $facts['phone'] ? null : Phone::normalize( $facts['phone'], $code );
		if ( null === $phone || $this->is_suppressed( $facts['phone'] ) || $this->is_suppressed( $phone ) ) {
			return null;
		}

		$now = null !== $this->clock ? ( $this->clock )() : time();
		if ( ! $manual ) {
			if ( ! Schedule::is_inside_window( $now, RecoveryConfig::window_start(), RecoveryConfig::window_end() ) ) {
				$this->requeue( $order_id, Schedule::next_window_start( $now, RecoveryConfig::window_start(), RecoveryConfig::window_end() ) - $now );
				return null;
			}
			if ( ! MessageLimiter::can_send( $phone, RecoveryConfig::limit_24h(), RecoveryConfig::limit_week() ) ) {
				$this->requeue( $order_id, 86400 );
				return null;
			}
		}

		$reviewed = null !== $this->reviewed ? ( $this->reviewed )( $facts ) : self::reviewed_products( $facts );
		$products = ReviewRules::pick_products( $facts['lines'], $reviewed, Config::max_products(), $this->url ?? static fn( int $id ): string => self::product_url( $id ) );
		if ( array() === $products ) {
			return null; // Everything reviewed (or nothing linkable).
		}

		$store_url = Config::store_review_url();
		$template  = '' === $store_url ? ReviewRules::drop_lines( Config::text_template(), '{store_review_url}' ) : Config::text_template();
		$text      = Renderer::render(
			$template,
			array(
				'first_name'       => $facts['first_name'],
				'order_number'     => $facts['order_number'],
				'review_links'     => ReviewRules::review_links( $products ),
				'store_review_url' => $store_url,
				'optout_url'       => ReviewOptout::url( $order_id ),
				'store_name'       => (string) get_bloginfo( 'name' ),
			)
		);
		if ( '' === $text ) {
			return null;
		}

		if ( ! $manual ) {
			MessageLimiter::hit( $phone );
		}
		return new Message( $phone, $text );
	}

	/**
	 * Whether a phone (any format) is on the suppression list.
	 *
	 * @param string $phone Phone.
	 */
	private function is_suppressed( string $phone ): bool {
		return null !== $this->suppressed ? (bool) ( $this->suppressed )( $phone ) : Suppression::is_suppressed( $phone, 'whatsapp' );
	}

	/**
	 * Sends the request again later (same priority, automatic).
	 *
	 * @param int $order_id Order ID.
	 * @param int $delay    Seconds.
	 */
	private function requeue( int $order_id, int $delay ): void {
		if ( null !== $this->requeue ) {
			( $this->requeue )( $order_id, $delay );
			return;
		}
		Sender::enqueue( Scheduler::KIND, $order_id, null, max( 60, $delay ), Sender::LOW );
	}

	/**
	 * Order facts from WooCommerce (null when the order is gone).
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,mixed>|null
	 */
	private static function load_facts( int $order_id ): ?array {
		$order = OrderHooks::load_order( $order_id );
		return null === $order ? null : OrderHooks::review_facts( $order );
	}

	/**
	 * Products of the order the customer already reviewed: ONE comments query by user or e-mail
	 * (top-level comments on those products; spam and trash excluded by the default status).
	 *
	 * @param array<string,mixed> $facts Order facts.
	 * @return list<int>
	 */
	private static function reviewed_products( array $facts ): array {
		$ids = array();
		foreach ( $facts['lines'] as $line ) {
			$ids[ (int) $line['id'] ] = (int) $line['id'];
		}
		if ( array() === $ids || ( (int) $facts['user_id'] < 1 && '' === (string) $facts['email'] ) ) {
			return array();
		}
		$args = array(
			'post__in' => array_values( $ids ),
			'parent'   => 0,
			'number'   => 50,
		);
		if ( (int) $facts['user_id'] > 0 ) {
			$args['user_id'] = (int) $facts['user_id'];
		} else {
			$args['author_email'] = (string) $facts['email'];
		}
		$comments = get_comments( $args );
		$found    = array();
		foreach ( is_array( $comments ) ? $comments : array() as $comment ) {
			if ( $comment instanceof \WP_Comment ) {
				$found[ (int) $comment->comment_post_ID ] = (int) $comment->comment_post_ID;
			}
		}
		return array_values( $found );
	}

	/**
	 * Public URL of a product ('' when it is not published).
	 *
	 * @param int $product_id Product ID.
	 */
	private static function product_url( int $product_id ): string {
		if ( 'publish' !== get_post_status( $product_id ) ) {
			return '';
		}
		$url = get_permalink( $product_id );
		return is_string( $url ) ? $url : '';
	}
}
