<?php
/**
 * Which banner a product shows.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ProductBanner;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules (no WordPress calls) so they are unit tested.
 */
final class BannerResolver {

	/**
	 * The banner to print, or null.
	 *
	 * - Product mode "none": nothing.
	 * - Product mode "own" with an image: the product's banner.
	 * - Otherwise the general banner when it is enabled and has an image.
	 * - A banner whose end date has passed is never shown.
	 *
	 * @param array{mode:string,image_id:int,link:string,until:int,countdown:bool}    $own     Product settings.
	 * @param array{enabled:bool,image_id:int,link:string,until:int,countdown:bool} $general General settings.
	 * @param int                                                                    $now     Current Unix time.
	 * @return array{image_id:int,link:string,until:int,countdown:bool}|null
	 */
	public static function resolve( array $own, array $general, int $now ): ?array {
		if ( Config::MODE_NONE === $own['mode'] ) {
			return null;
		}
		if ( Config::MODE_OWN === $own['mode'] && $own['image_id'] > 0 ) {
			$banner = $own;
		} elseif ( $general['enabled'] && $general['image_id'] > 0 ) {
			$banner = $general;
		} else {
			return null;
		}
		if ( $banner['until'] > 0 && $banner['until'] <= $now ) {
			return null;
		}
		return array(
			'image_id'  => $banner['image_id'],
			'link'      => $banner['link'],
			'until'     => $banner['until'],
			'countdown' => $banner['countdown'] && $banner['until'] > 0,
		);
	}

	/**
	 * Days, hours, minutes and seconds left (never negative).
	 *
	 * @param int $until End timestamp.
	 * @param int $now   Current timestamp.
	 * @return array{d:int,h:int,m:int,s:int}
	 */
	public static function remaining( int $until, int $now ): array {
		$left = max( 0, $until - $now );
		return array(
			'd' => intdiv( $left, 86400 ),
			'h' => intdiv( $left % 86400, 3600 ),
			'm' => intdiv( $left % 3600, 60 ),
			's' => $left % 60,
		);
	}
}
