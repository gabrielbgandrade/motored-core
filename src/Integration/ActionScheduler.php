<?php
/**
 * Action Scheduler adapter (functions are not in the stubs, see docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Guarded wrapper: every call degrades to "not scheduled" when Action Scheduler is missing.
 */
final class ActionScheduler {

	public const GROUP = 'motored-core';

	/**
	 * Whether Action Scheduler functions exist.
	 */
	public static function available(): bool {
		return function_exists( 'as_schedule_single_action' )
			&& ( ! class_exists( \Action_Scheduler::class ) || \Action_Scheduler::is_initialized() );
	}

	/**
	 * Schedules a single action in the motored-core group.
	 *
	 * @param int              $timestamp Unix time.
	 * @param string           $hook      Action hook.
	 * @param array<int,mixed> $args      Scalar args only (IDs; never phone numbers or text).
	 * @param bool             $unique    Whether to suppress duplicates.
	 * @return bool True when scheduled.
	 */
	public static function schedule( int $timestamp, string $hook, array $args, bool $unique = false ): bool {
		if ( ! self::available() ) {
			return false;
		}
		return (int) ( $unique
			? as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, true )
			: as_schedule_single_action( $timestamp, $hook, $args, self::GROUP ) ) > 0;
	}

	/**
	 * Schedules a recurring action in the motored-core group.
	 *
	 * @param int    $timestamp First run (unix time).
	 * @param int    $interval  Seconds between runs.
	 * @param string $hook      Action hook.
	 * @return bool True when scheduled.
	 */
	public static function schedule_recurring( int $timestamp, int $interval, string $hook ): bool {
		if ( ! self::available() || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return false;
		}
		return (int) as_schedule_recurring_action( $timestamp, $interval, $hook, array(), self::GROUP, true ) > 0;
	}

	/**
	 * Whether a pending action of the hook exists in our group.
	 *
	 * @param string $hook Action hook.
	 */
	public static function has_scheduled( string $hook ): bool {
		return self::available() && function_exists( 'as_has_scheduled_action' ) && (bool) as_has_scheduled_action( $hook, null, self::GROUP );
	}

	/**
	 * Unschedules every pending action of a hook (uninstall / tooling).
	 *
	 * @param string $hook Action hook.
	 */
	public static function unschedule_all( string $hook = '' ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
	}
}
