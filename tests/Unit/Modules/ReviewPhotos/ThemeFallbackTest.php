<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewPhotos;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ReviewPhotos\ReviewDisplay;
use Motored\Core\Modules\ReviewPhotos\ReviewForm;
use Motored\Core\Tests\Unit\TestCase;

final class ThemeFallbackTest extends TestCase {

	private function enable(): void {
		$this->fake_storage();
		$this->options['motored_review_photos'] = array( 'enabled' => 'yes' );
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'comments_open' )->justReturn( true );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();
	}

	public function test_form_field_is_added_once_across_both_hooks(): void {
		$this->enable();
		$form = new ReviewForm();
		$args = $form->filter_args( array( 'comment_field' => '<textarea></textarea>' ) );
		$this->assertSame( 1, substr_count( $args['comment_field'], 'motored-rp-field' ) );
		// comment_form() then runs the core filter over the same markup: no second field.
		$this->assertSame( $args['comment_field'], $form->filter_field( $args['comment_field'] ) );
		$this->assertTrue( $form->was_rendered() );
	}

	public function test_fallback_field_when_theme_skips_woocommerce_args(): void {
		$this->enable();
		$form = new ReviewForm();
		$out  = $form->filter_field( '<textarea></textarea>' );
		$this->assertSame( 1, substr_count( $out, 'motored-rp-field' ) );
		$this->assertSame( $out, $form->filter_field( $out ) );
		$this->assertStringNotContainsString( 'comment-form-motored', $out );
	}

	public function test_no_field_when_module_disabled_or_not_product_page(): void {
		$this->fake_storage();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'comments_open' )->justReturn( true );
		$form = new ReviewForm();
		$this->assertSame( '<t>', $form->filter_field( '<t>' ) );
		$this->assertFalse( $form->was_rendered() );
	}

	public function test_text_fallback_ignores_reviews_without_photos(): void {
		$this->fake_storage();
		Functions\when( 'get_comment_meta' )->justReturn( array() );
		$c = (object) array( 'comment_ID' => 5, 'comment_approved' => '1', 'comment_type' => 'review' );
		$this->assertSame( 'text', ( new ReviewDisplay() )->filter_text( 'text', $c ) );
	}

	public function test_text_fallback_skips_reviews_whose_template_fires_the_wc_hooks(): void {
		$this->fake_storage();
		$display = new ReviewDisplay();
		$c       = (object) array( 'comment_ID' => 7, 'comment_approved' => '1', 'comment_type' => 'review' );
		$display->mark_template_hooked( $c );
		$this->assertSame( 'text', $display->filter_text( 'text', $c ) );
	}
}
