<?php
/**
 * The only asset of the module.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

use Motored\Core\Integration\WooCommerce\ReviewHooks;

defined( 'ABSPATH' ) || exit;

/**
 * One vanilla `defer` script (< 2 KB), only on a product page with open comments and the module on.
 */
final class FormScript {

	public const HANDLE = 'motored-review-photos';

	/**
	 * Form field printer (knows whether PHP already printed the field).
	 *
	 * @var ReviewForm
	 */
	private ReviewForm $form;

	/**
	 * Constructor.
	 *
	 * @param ReviewForm $form Form field printer.
	 */
	public function __construct( ReviewForm $form ) {
		$this->form = $form;
	}

	/**
	 * Hooked on wp_footer (before the footer scripts print): when no PHP hook printed the field
	 * (theme template without any comment-form hook), hand the markup to the script, which injects it
	 * into #commentform inside #reviews. The script does nothing when that form does not exist.
	 */
	public function add_fallback(): void {
		if ( $this->form->was_rendered() || ! ReviewHooks::is_review_form_page() || ! Config::enabled() ) {
			return;
		}
		wp_add_inline_script( self::HANDLE, 'window.motoredRP=' . wp_json_encode( $this->form->field_html() ) . ';', 'before' );
	}

	/**
	 * Hooked on wp_enqueue_scripts.
	 */
	public function enqueue(): void {
		if ( ! ReviewHooks::is_review_form_page() || ! Config::enabled() ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE,
			MOTORED_CORE_URL . 'assets/js/review-photos.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		// Garante enctype=multipart/form-data no formulário de review assim que possível,
		// antes mesmo do script diferido executar. Sem isso o navegador nunca envia $_FILES.
		wp_add_inline_script(
			self::HANDLE,
			"(function(){var i=document.getElementById('motored_review_photos');if(i){var f=i.form||i.closest('form');if(f){f.enctype=f.encoding='multipart/form-data';f.setAttribute('enctype','multipart/form-data');}}})();",
			'before'
		);
	}
}
