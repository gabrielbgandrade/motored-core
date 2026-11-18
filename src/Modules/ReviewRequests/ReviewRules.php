<?php
/**
 * Pure rules of review requests.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewRequests;

use Motored\Core\Modules\Recovery\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Status rules, delay with sending window, product choice and link text. No WordPress state.
 */
final class ReviewRules {

	/**
	 * Anchor of the reviews section of a product page (theme dependent, VERIFY.md).
	 */
	public const ANCHOR = '#reviews';

	/**
	 * Statuses that never get a request (not even manually).
	 */
	private const BLOCKED = array( 'trash', 'cancelled', 'refunded', 'failed', 'pending', 'draft', 'checkout-draft', 'auto-draft' );

	/**
	 * Whether the order status allows a request (trigger status when automatic).
	 *
	 * @param string $status  Current order status.
	 * @param string $trigger Trigger status.
	 * @param bool   $manual  Sent by an administrator.
	 */
	public static function status_allows( string $status, string $trigger, bool $manual ): bool {
		return $manual ? ! in_array( $status, self::BLOCKED, true ) : $status === $trigger;
	}

	/**
	 * Seconds from now until the send time: now + days, moved to the next sending window start.
	 *
	 * @param int                $now   Unix time.
	 * @param int                $days  Delay in days.
	 * @param string             $start Window start (HH:MM).
	 * @param string             $end   Window end (HH:MM).
	 * @param \DateTimeZone|null $tz    Timezone (site timezone when null).
	 */
	public static function delay_seconds( int $now, int $days, string $start, string $end, ?\DateTimeZone $tz = null ): int {
		$at = $now + max( 1, $days ) * 86400;
		if ( ! Schedule::is_inside_window( $at, $start, $end, $tz ) ) {
			$at = Schedule::next_window_start( $at, $start, $end, $tz );
		}
		return max( 0, $at - $now );
	}

	/**
	 * Products to ask about: one entry per product (variations share the parent), already reviewed
	 * ones excluded, products without a link skipped, at most $max.
	 *
	 * @param list<array{id:int,name:string}> $lines    Order lines.
	 * @param array<int,int>                  $reviewed Product ids the customer already reviewed.
	 * @param int                             $max      Maximum products.
	 * @param \Closure                        $url      fn( int $product_id ): string product page URL ('' = unavailable).
	 * @return list<array{name:string,url:string}>
	 */
	public static function pick_products( array $lines, array $reviewed, int $max, \Closure $url ): array {
		$picked = array();
		$seen   = array();
		foreach ( $lines as $line ) {
			$id = (int) $line['id'];
			if ( $id < 1 || isset( $seen[ $id ] ) || in_array( $id, $reviewed, true ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$link        = (string) $url( $id );
			if ( '' === $link || '' === trim( $line['name'] ) ) {
				continue;
			}
			$picked[] = array(
				'name' => trim( $line['name'] ),
				'url'  => self::with_anchor( $link ),
			);
			if ( count( $picked ) >= max( 1, $max ) ) {
				break;
			}
		}
		return $picked;
	}

	/**
	 * Lines "- Name: URL" for the {review_links} placeholder.
	 *
	 * @param list<array{name:string,url:string}> $products Products.
	 */
	public static function review_links( array $products ): string {
		$lines = array();
		foreach ( $products as $product ) {
			$lines[] = '- ' . $product['name'] . ': ' . $product['url'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Removes the lines of a template that contain a placeholder (used when its value is empty).
	 *
	 * @param string $template    Template.
	 * @param string $placeholder Placeholder with braces.
	 */
	public static function drop_lines( string $template, string $placeholder ): string {
		$lines = array_filter(
			explode( "\n", $template ),
			static fn( string $line ): bool => false === strpos( $line, $placeholder )
		);
		return implode( "\n", $lines );
	}

	/**
	 * Adds the reviews anchor to a product URL.
	 *
	 * @param string $url Product URL.
	 */
	private static function with_anchor( string $url ): string {
		$hash = strpos( $url, '#' );
		return ( false === $hash ? $url : substr( $url, 0, $hash ) ) . self::ANCHOR;
	}
}
