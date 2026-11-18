<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Motored\Core\Integration\MercadoPago\CheckoutPro;
use Motored\Core\Tests\Unit\TestCase;

final class CheckoutProSettingsTest extends TestCase {

	public function test_discount_and_commission_count_only_when_their_checkbox_is_on(): void {
		$this->fake_storage();
		$this->options['woocommerce_woo-mercado-pago-basic_settings'] = array(
			'gateway_discount'          => '5',
			'gateway_discount_checkbox' => 'yes',
			'commission'                => '3',
			'commission_checkbox'       => 'no',
			'installments'              => '6',
		);
		$this->assertSame(
			array( 'discount' => 5.0, 'commission' => 0.0, 'installments' => 6 ),
			CheckoutPro::settings()
		);
	}

	public function test_missing_settings_are_not_readable_and_zero_installments_means_twelve(): void {
		$this->fake_storage();
		$this->assertSame( array( 'discount' => 0.0, 'commission' => 0.0, 'installments' => null ), CheckoutPro::settings() );
		$this->options['woocommerce_woo-mercado-pago-basic_settings'] = array( 'installments' => '0' );
		$this->assertSame( 12, CheckoutPro::settings()['installments'] );
	}
}
