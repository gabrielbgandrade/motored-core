<?php
namespace Motored\Core\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Motored\Core\Admin\Ui;
use Motored\Core\Tests\Unit\TestCase;

/**
 * Guard of the shared admin CSS rule (AGENTS.md): every admin table, box and field uses the classes of
 * assets/admin/motored-admin.css through Admin\Ui; ad hoc per-module CSS and inline styles fail this test.
 */
final class SharedAdminUiTest extends TestCase {

	/**
	 * Admin renderers: settings screens, meta boxes and the Recovery pages.
	 *
	 * @return list<string>
	 */
	private function admin_files(): array {
		$root  = dirname( __DIR__, 3 ) . '/src';
		$files = array_merge(
			(array) glob( $root . '/Admin/*.php' ),
			(array) glob( $root . '/Modules/*/*Admin.php' ),
			(array) glob( $root . '/Modules/Recovery/*ListTable.php' )
		);
		$files = array_values( array_filter( $files, 'is_string' ) );
		$this->assertGreaterThan( 8, count( $files ), 'admin renderers were found' );
		return $files;
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
	}

	public function test_tables_are_only_emitted_through_the_shared_helper(): void {
		foreach ( $this->admin_files() as $file ) {
			if ( str_ends_with( $file, '/Admin/Ui.php' ) ) {
				continue;
			}
			$this->assertStringNotContainsString( '<table', (string) file_get_contents( $file ), basename( $file ) . ': use Ui::table_open() (class motored-table)' );
		}
	}

	public function test_no_inline_styles_or_ad_hoc_css_in_admin_renderers(): void {
		foreach ( $this->admin_files() as $file ) {
			$code = (string) file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/style\s*=|<style|[\'"]css[\'"]\s*=>/i', $code, basename( $file ) . ': use the classes of motored-admin.css' );
			$this->assertDoesNotMatchRegularExpression( '/class=\\\\?"[^"]*\bmotored-(?!admin|table|box|field|col-|cell-|actions|notes|mono|narrow|input-wide|textarea|section-|on-restore)[a-z-]+/', $code, basename( $file ) . ': unknown motored- class (add it to motored-admin.css first)' );
		}
	}

	public function test_every_meta_box_renderer_emits_the_shared_box(): void {
		$found = 0;
		foreach ( (array) glob( dirname( __DIR__, 3 ) . '/src/Modules/*/*.php' ) as $file ) {
			$code = (string) file_get_contents( (string) $file );
			if ( false === strpos( $code, 'add_meta_box(' ) ) {
				continue;
			}
			++$found;
			$this->assertTrue( false !== strpos( $code, 'motored-box' ) || false !== strpos( $code, 'Ui::box_open' ), basename( (string) $file ) . ': a meta box must render inside .motored-box' );
		}
		$this->assertGreaterThanOrEqual( 3, $found );
	}

	public function test_every_class_used_by_the_helpers_exists_in_the_stylesheet(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/motored-admin.css' );
		foreach ( array( 'motored-table', 'motored-table-wrap', 'motored-table--compact', 'motored-box', 'motored-box--flush', 'motored-field', 'motored-field__label', 'motored-field__control', 'motored-field__desc', 'motored-col-xs', 'motored-col-sm', 'motored-col-md', 'motored-col-lg', 'motored-col-grow', 'motored-cell-center' ) as $class ) {
			$this->assertStringContainsString( '.' . $class, $css, $class );
		}
	}

	public function test_helpers_emit_the_shared_classes(): void {
		$out = static function ( callable $render ): string {
			ob_start();
			$render();
			return (string) ob_get_clean();
		};

		$table = $out(
			static function (): void {
				Ui::table_open();
				Ui::table_head( array( 'Name' => 'grow', 'Free' => '', 'Bogus' => 'bogus' ) );
				Ui::table_close();
			}
		);
		$this->assertStringContainsString( 'class="motored-table-wrap"', $table );
		$this->assertStringContainsString( 'widefat striped motored-table"', $table );
		$this->assertStringContainsString( '<th class="motored-col-grow">Name</th><th>Free</th><th>Bogus</th>', $table, 'unknown width is ignored' );
		$this->assertStringContainsString( 'motored-table--compact', $out( static fn() => Ui::table_open( true ) ) );

		$this->assertSame( '<div class="motored-box motored-box--flush">', $out( static fn() => Ui::box_open( true ) ) );
		$this->assertSame( '<div class="motored-box">', $out( static fn() => Ui::box_open() ) );

		$field = $out( static fn() => Ui::field( 'Code', 'f1', '<input id="f1" />', 'Help' ) );
		$this->assertStringContainsString( 'class="motored-field"', $field );
		$this->assertStringContainsString( 'motored-field__label" for="f1"', $field );
		$this->assertStringContainsString( 'motored-field__control', $field );
		$this->assertStringContainsString( 'motored-field__desc', $field );
		$this->assertStringNotContainsString( 'motored-field__desc', $out( static fn() => Ui::field( 'Code', 'f1', '<input />' ) ) );
	}
}
