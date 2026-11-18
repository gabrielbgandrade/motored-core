<?php
/**
 * Shared "Reviews" settings section.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Several modules (ReviewPhotos, ReviewRequests) edit their options in one "Reviews" section:
 * each module adds its fields through the `motored_reviews_section_fields` filter (admin only).
 */
final class ReviewsSection {

	public const ID = 'reviews';

	/**
	 * Registers the section once (idempotent).
	 */
	public static function ensure(): void {
		if ( null === SectionRegistry::get( self::ID ) ) {
			SectionRegistry::add( self::ID, static fn(): string => __( 'Reviews', 'motored-core' ), array( self::class, 'fields' ), null, 40 );
		}
	}

	/**
	 * Fields contributed by the modules, in filter priority order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function fields(): array {
		$fields = apply_filters( 'motored_reviews_section_fields', array() );
		return is_array( $fields ) ? array_values( $fields ) : array();
	}
}
