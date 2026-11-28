<?php
/**
 * Browser integration for Mi Garage and its compatibility markers.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\MiGarage;

use Motored\Core\Modules\Compatibility\Config;
use Motored\Core\Modules\Compatibility\RestController as CompatibilityRest;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\StorefrontAssets;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the garage state and rendering script on demand.
 */
final class GarageAssets {

	public const HANDLE = 'motored-compat-garage';

	/**
	 * Whether the script has been enqueued in this request.
	 *
	 * @var bool
	 */
	private static bool $enqueued = false;

	/**
	 * Enqueues the client-side Mi Garage & badge script once per request.
	 */
	public static function enqueue(): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		// Analytics events are emitted through motoredUi.track(); without the shared helper they would silently vanish.
		$deps = array();
		if ( Config::analytics_events() ) {
			StorefrontAssets::enqueue_ui_js();
			$deps[] = StorefrontAssets::HANDLE_UI;
		}
		wp_enqueue_script(
			self::HANDLE,
			MOTORED_CORE_URL . 'assets/js/compat-garage.js',
			$deps,
			MOTORED_CORE_VERSION . '-garage13',
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		$cfg = self::client_config();
		wp_add_inline_script(
			self::HANDLE,
			'window.motoredCompatCfg=' . wp_json_encode( $cfg ) . ';',
			'before'
		);
	}

	/**
	 * Mi Garage URL for the current visitor: the account endpoint for logged-in shoppers, the published
	 * public page for guests (option `motored_mi_garage_page_id`), else the account endpoint ('' without WC).
	 */
	public static function garage_url(): string {
		$url = function_exists( 'wc_get_endpoint_url' ) && function_exists( 'wc_get_page_permalink' )
			? (string) wc_get_endpoint_url( GarageEndpoint::ENDPOINT, '', (string) wc_get_page_permalink( 'myaccount' ) )
			: '';
		if ( ! is_user_logged_in() ) {
			$public_page = (int) get_option( 'motored_mi_garage_page_id', 0 );
			if ( $public_page > 0 && 'publish' === get_post_status( $public_page ) ) {
				$url = (string) get_permalink( $public_page );
			}
		}
		return $url;
	}

	/**
	 * Client-side script configuration (REST URLs, login state, localized UI strings).
	 *
	 * @return array<string,mixed>
	 */
	public static function client_config(): array {
		$logged_in  = is_user_logged_in();
		$garage_url = self::garage_url();
		return array(
			'restBase'      => untrailingslashit( rest_url( CompatibilityRest::NAMESPACE ) ),
			'nonce'         => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
			'loggedIn'      => $logged_in,
			// Without a saved phone the Avisame button asks for WhatsApp instead of silently using e-mail.
			'accountPhone'  => $logged_in && '' !== trim( (string) get_user_meta( get_current_user_id(), 'billing_phone', true ) ),
			'accountId'     => $logged_in ? get_current_user_id() : 0,
			'strict'        => Config::strict_specs(),
			'showIncomp'    => Config::show_incompatible_badge(),
			'maxBikes'      => Config::max_garage_bikes(),
			'shopUrl'       => Config::shop_url(),
			'garageUrl'     => $garage_url,
			'garageCta'     => Config::garage_cta(),
			'events'        => Config::analytics_events(),
			'notifyEnabled' => \Motored\Core\Modules\BikePages\Config::notify_enabled(),
			'notifyNonce'   => wp_create_nonce( 'motored_notify' ),
			'accountUrl'    => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url(),
			'i18n'          => array(
				'brandPlaceholder'     => __( 'Select brand...', 'motored-core' ),
				'modelPlaceholder'     => __( 'Select model...', 'motored-core' ),
				'yearPlaceholder'      => __( 'Select year...', 'motored-core' ),
				'varPlaceholder'       => __( 'Select version...', 'motored-core' ),
				'filterList'           => __( 'Type to filter...', 'motored-core' ),
				'oem'                  => __( 'Original (OEM)', 'motored-core' ),
				'alt'                  => __( 'Approved alternative size for your motorcycle', 'motored-core' ),
				'equiv'                => __( 'Equivalent to factory size', 'motored-core' ),
				'front'                => TireSpecs::position_label( TireSpecs::POS_FRONT ),
				'rear'                 => TireSpecs::position_label( TireSpecs::POS_REAR ),
				'kit'                  => TireSpecs::position_label( TireSpecs::POS_KIT ),
				/* translators: %s: active motorcycle label. */
				'compatWith'           => __( 'This tire fits your %s', 'motored-core' ),
				/* translators: %s: active motorcycle label. */
				'compatWithKit'        => __( 'This kit fits your %s', 'motored-core' ),
				/* translators: %s: active motorcycle label. */
				'compatLoop'           => __( 'Compatible with your %s', 'motored-core' ),
				/* translators: %s: active motorcycle label. */
				'incompatWith'         => __( 'Does not match your %s', 'motored-core' ),
				'seeCompat'            => __( 'View all tires for your motorcycle', 'motored-core' ),
				/* translators: %d: matching tire count. */
				'seeCompatCount'       => __( 'View all tires for your motorcycle (%d)', 'motored-core' ),
				'verifySpecs'          => __( 'Check load/speed rating against your manual.', 'motored-core' ),
				'equivNote'            => __( 'replaces', 'motored-core' ),
				'viewGarage'           => __( 'View my garage', 'motored-core' ),
				'askFit'               => __( 'Does it fit your motorcycle?', 'motored-core' ),
				'pickBike'             => __( 'Select your motorcycle', 'motored-core' ),
				'changeBike'           => __( 'Change motorcycle', 'motored-core' ),
				'savedBikes'           => __( 'Your saved motorcycles:', 'motored-core' ),
				'yourBikeUses'         => __( 'Your motorcycle uses:', 'motored-core' ),
				'loading'              => __( 'Loading...', 'motored-core' ),
				'noModels'             => __( 'No models available', 'motored-core' ),
				'loadSelectErr'        => __( 'Could not load options. Please try again.', 'motored-core' ),
				'savedOk'              => __( 'Motorcycle saved to My Garage.', 'motored-core' ),
				/* translators: %s: motorcycle removed to make room in My Garage. */
				'garageFull'           => __( 'My Garage was full, so %s was removed.', 'motored-core' ),
				/* translators: %s: active motorcycle label. */
				'ctaLead'              => __( 'Your motorcycle: %s.', 'motored-core' ),
				'ctaLink'              => __( 'View only tires that fit it', 'motored-core' ),
				'otherBikes'           => __( 'Your other motorcycles:', 'motored-core' ),
				'pairFront'            => __( 'Complete the set: front tires', 'motored-core' ),
				/* translators: %d: matching front tire count. */
				'pairFrontCount'       => __( 'Complete the set: front tires (%d)', 'motored-core' ),
				'pairRear'             => __( 'Complete the set: rear tires', 'motored-core' ),
				/* translators: %d: matching rear tire count. */
				'pairRearCount'        => __( 'Complete the set: rear tires (%d)', 'motored-core' ),
				/* translators: %s: active motorcycle label. */
				'noPairFront'          => __( 'We do not have front tires for your %s yet', 'motored-core' ),
				/* translators: %s: active motorcycle label. */
				'noPairRear'           => __( 'We do not have rear tires for your %s yet', 'motored-core' ),
				'notifyCta'            => __( 'Notify me when it arrives', 'motored-core' ),
				'notifyContact'        => __( 'WhatsApp with country code, or e-mail', 'motored-core' ),
				'notifyTitle'          => __( 'Get an availability alert', 'motored-core' ),
				'notifyClose'          => __( 'Close', 'motored-core' ),
				'notifyPriority'       => __( 'We prioritize WhatsApp. If you leave only an e-mail, we will use it as a fallback.', 'motored-core' ),
				'notifyAddPhone'       => __( 'We send the alert by WhatsApp. Add your number, or leave it empty to receive it by e-mail.', 'motored-core' ),
				'notifyPhone'          => __( 'WhatsApp with area code', 'motored-core' ),
				'notifyRegister'       => __( 'Optional: create an account or sign in to save your details', 'motored-core' ),
				'notifyAccountConsent' => __( 'By clicking, you request an alert using your saved WhatsApp number, or e-mail as a fallback.', 'motored-core' ),
				'notifyConsent'        => __( 'I agree to be notified by e-mail or WhatsApp', 'motored-core' ),
				'notifySubmit'         => __( 'Notify me', 'motored-core' ),
				'notifyOk'             => __( 'Alert saved. We will prioritize WhatsApp when compatible tires are available; e-mail is the fallback.', 'motored-core' ),
				'notifyErr'            => __( 'Enter a valid e-mail or phone number and accept the consent.', 'motored-core' ),
			),
		);
	}

	/**
	 * Strings used ONLY by compat-garage-page.js (Mi Garage page and drawer); kept out of the config
	 * that every catalog and product page ships.
	 *
	 * @return array<string,string>
	 */
	public static function page_i18n(): array {
		return array(
			'activeBadge'     => __( 'Active motorcycle', 'motored-core' ),
			'useBike'         => __( 'Use this motorcycle', 'motored-core' ),
			'changeBike'      => __( 'Choose another motorcycle', 'motored-core' ),
			'removeBike'      => __( 'Remove motorcycle', 'motored-core' ),
			'otherBikes'      => __( 'Your other motorcycles', 'motored-core' ),
			'filterTires'     => __( 'View tires', 'motored-core' ),
			'remove'          => __( 'Remove', 'motored-core' ),
			'emptyGarage'     => __( 'You have not saved any motorcycles yet.', 'motored-core' ),
			'emptyGarageHint' => __( 'Save your motorcycle to instantly see which tires fit across the store.', 'motored-core' ),
			'oemSizes'        => __( 'Factory sizes:', 'motored-core' ),
			/* translators: %s: removed motorcycle label. */
			'removedBike'     => __( '%s removed.', 'motored-core' ),
			/* translators: %s: comma-separated motorcycle labels that are no longer in the catalog. */
			'bikeUnavailable' => __( '%s is no longer in our catalog. Select your motorcycle version again.', 'motored-core' ),
			'undo'            => __( 'Undo', 'motored-core' ),
			'sessionExpired'  => __( 'Your session expired. Reload the page to save your changes.', 'motored-core' ),
			'actionFailed'    => __( 'Action could not be completed.', 'motored-core' ),
		);
	}

	/**
	 * Resets per-request asset state.
	 */
	public static function reset(): void {
		self::$enqueued = false;
	}
}
