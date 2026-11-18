<?php
/**
 * In-memory fakes of wp_cache_* with the real by-reference signature of wp_cache_get().
 * Brain Monkey cannot propagate by-reference arguments, so these are defined explicitly.
 * Loaded only by tests that exercise the persistent-cache path.
 *
 * @package Motored\Core
 */

// phpcs:disable

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$store = $GLOBALS['motored_fake_cache'][ $group ] ?? array();
		$found = array_key_exists( $key, $store );
		return $found ? $store[ $key ] : false;
	}

	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		$GLOBALS['motored_fake_cache'][ $group ][ $key ] = $data;
		return true;
	}

	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
		if ( array_key_exists( $key, $GLOBALS['motored_fake_cache'][ $group ] ?? array() ) ) {
			return false;
		}
		$GLOBALS['motored_fake_cache'][ $group ][ $key ] = $data;
		return true;
	}

	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['motored_fake_cache'][ $group ][ $key ] );
		return true;
	}

	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		if ( ! array_key_exists( $key, $GLOBALS['motored_fake_cache'][ $group ] ?? array() ) ) {
			return false;
		}
		$GLOBALS['motored_fake_cache'][ $group ][ $key ] += $offset;
		return $GLOBALS['motored_fake_cache'][ $group ][ $key ];
	}
}
