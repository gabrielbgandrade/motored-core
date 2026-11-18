<?php
namespace Motored\Core\Tests\Unit\Modules\OrderNotifications;

use Motored\Core\Modules\OrderNotifications\Renderer;
use Motored\Core\Tests\Unit\TestCase;

final class RendererTest extends TestCase {

	public function test_replaces_all_placeholders_and_keeps_whatsapp_bold(): void {
		$template = "Hola {first_name}, pedido *#{order_number}* en *{store_name}* por *{order_total}*.\n{order_items}\nNota: {customer_note}\n{order_url}";
		$result   = Renderer::render(
			$template,
			array(
				'order_number'  => '1042',
				'first_name'    => '<b>Lucía</b>',
				'order_total'   => '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#36;</span>&nbsp;45.000,00</bdi></span>',
				'order_items'   => "- 2x Cubierta 205/55 R16",
				'order_url'     => 'https://motored.com.ar/checkout/order-received/1042/?key=wc_order_abc123',
				'store_name'    => 'Motored &amp; Neumáticos',
				'customer_note' => 'Pasá después de las <script>alert(1)</script>14 hs.',
			)
		);

		$expected = "Hola Lucía, pedido *#1042* en *Motored & Neumáticos* por *\$ 45.000,00*.\n- 2x Cubierta 205/55 R16\nNota: Pasá después de las 14 hs.\nhttps://motored.com.ar/checkout/order-received/1042/?key=wc_order_abc123";
		$this->assertSame( $expected, $result );
	}

	public function test_empty_or_whitespace_template_returns_empty_string(): void {
		$this->assertSame( '', Renderer::render( '', array( 'order_number' => '1' ) ) );
		$this->assertSame( '', Renderer::render( "   \n\t  ", array( 'order_number' => '1' ) ) );
	}

	public function test_placeholders_are_not_double_expanded(): void {
		$result = Renderer::render(
			'Hola {first_name} — {order_url}',
			array(
				'first_name' => '{order_url}',
				'order_url'  => 'https://motored.com.ar/pedido',
			)
		);

		$this->assertSame( 'Hola {order_url} — https://motored.com.ar/pedido', $result );
	}

	public function test_format_items_lists_up_to_five_without_summary(): void {
		$items = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$items[] = array(
				'name' => 'Producto ' . $i,
				'qty'  => $i,
			);
		}

		$formatted = Renderer::format_items( $items );
		$expected  = "- 1x Producto 1\n- 2x Producto 2\n- 3x Producto 3\n- 4x Producto 4\n- 5x Producto 5";
		$this->assertSame( $expected, $formatted );
	}

	public function test_format_items_truncates_over_five_with_y_n_mas(): void {
		$items = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$items[] = array(
				'name' => '<span>Item ' . $i . '</span>',
				'qty'  => 1,
			);
		}

		$formatted = Renderer::format_items( $items );
		$expected  = "- 1x Item 1\n- 1x Item 2\n- 1x Item 3\n- 1x Item 4\n- 1x Item 5\ny 3 más";
		$this->assertSame( $expected, $formatted );
	}

	public function test_format_items_empty_returns_empty_string(): void {
		$this->assertSame( '', Renderer::format_items( array() ) );
	}

	public function test_format_price_strips_html_and_normalizes_nbsp(): void {
		$html = '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#36;</span>&nbsp;128.500,50</bdi></span>';
		$this->assertSame( '$ 128.500,50', Renderer::format_price( $html ) );
		$this->assertSame( 'ARS 1.200', Renderer::format_price( 'ARS&nbsp;&nbsp;1.200' ) );
	}
}
