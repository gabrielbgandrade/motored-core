<?php
namespace Motored\Core\Tests\Unit\Admin;

require_once dirname( __DIR__ ) . '/Fixtures/WcSettingsDoubles.php';

use Brain\Monkey\Functions;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Admin\SettingsTab;
use Motored\Core\Tests\Unit\TestCase;

final class SettingsTabAccessTest extends TestCase {

	/** @var list<string> */
	private array $caps = array();

	/** @var list<string> */
	private array $referer = array();

	protected function setUp(): void {
		parent::setUp();
		\WC_Settings_Page::$calls  = array();
		\WC_Admin_Settings::$errors = array();
		unset( $GLOBALS['hide_save_button'], $GLOBALS['current_section'] );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();
		$this->referer = array();
		Functions\when( 'check_admin_referer' )->alias(
			function ( $action ) {
				$this->referer[] = $action;
				return 1;
			}
		);
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['hide_save_button'], $GLOBALS['current_section'] );
		parent::tearDown();
	}

	private function as_manager(): SettingsTab {
		$this->caps = array( 'manage_woocommerce' );
		return $this->tab();
	}

	private function as_admin(): SettingsTab {
		$this->caps = array( 'manage_woocommerce', 'manage_options' );
		return $this->tab();
	}

	private function tab(): SettingsTab {
		SectionRegistry::reset();
		SectionRegistry::add( 'whatsapp', fn() => 'WhatsApp', fn() => array( array( 'id' => 'x' ) ), null, 20 );
		return new SettingsTab();
	}

	/**
	 * @return array<string,string>
	 */
	private function sections( SettingsTab $tab ): array {
		$method = new \ReflectionMethod( $tab, 'get_own_sections' );
		return $method->invoke( $tab );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function fields( SettingsTab $tab, string $section ): array {
		$method = new \ReflectionMethod( $tab, 'get_settings_for_section_core' );
		return $method->invoke( $tab, $section );
	}

	private function render_html( SettingsTab $tab, string $section ): string {
		$GLOBALS['current_section'] = $section;
		ob_start();
		$tab->output();
		return (string) ob_get_clean();
	}

	public function test_registry_allows_by_capability_and_unknown_sections_never(): void {
		$this->as_manager();
		$this->assertTrue( SectionRegistry::allowed( 'whatsapp' ) );
		$this->assertTrue( SectionRegistry::allowed( 'status' ) );
		$this->assertFalse( SectionRegistry::allowed( 'advanced' ) );
		$this->assertFalse( SectionRegistry::allowed( 'nope' ) );
		$this->assertArrayNotHasKey( 'advanced', SectionRegistry::visible() );
		$this->assertArrayHasKey( 'advanced', SectionRegistry::all(), 'registered, only hidden' );
	}

	public function test_the_section_is_hidden_from_the_menu_for_shop_managers(): void {
		$sections = $this->sections( $this->as_manager() );
		$this->assertSame( array( '', 'status' ), array_keys( $sections ), 'default section first, no Avanzado' );
	}

	public function test_administrators_see_avanzado_as_the_last_section(): void {
		$sections = $this->sections( $this->as_admin() );
		$this->assertSame( array( '', 'status', 'advanced' ), array_keys( $sections ) );
		$this->assertSame( 'Advanced', end( $sections ) );
	}

	public function test_fields_are_not_read_without_the_capability(): void {
		$manager = $this->as_manager();
		$this->assertSame( array(), $this->fields( $manager, 'advanced' ) );
		$this->assertSame( array(), $manager->advanced_settings() );

		$admin  = $this->as_admin();
		$fields = $this->fields( $admin, 'advanced' );
		$ids    = array_column( $fields, 'id' );
		$this->assertContains( 'motored_general[delete_data_on_uninstall]', $ids );
		$this->assertContains( 'motored_general[log_level]', $ids );
		$this->assertSame( array( array( 'id' => 'x' ) ), $this->fields( $manager, '' ), 'the default section is the first visible one' );
	}

	public function test_direct_url_shows_an_error_and_no_form_for_shop_managers(): void {
		$html = $this->render_html( $this->as_manager(), 'advanced' );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'You do not have permission', $html );
		$this->assertTrue( $GLOBALS['hide_save_button'] );
		$this->assertSame( array(), \WC_Settings_Page::$calls, 'no settings table was rendered' );
	}

	public function test_administrators_render_the_section_normally(): void {
		$html = $this->render_html( $this->as_admin(), 'advanced' );
		$this->assertStringNotContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'motored-section-advanced', $html );
		$this->assertSame( array( 'parent_output' ), \WC_Settings_Page::$calls );
	}

	public function test_posting_to_the_section_is_ignored_with_a_notice_for_shop_managers(): void {
		$GLOBALS['current_section'] = 'advanced';
		$this->as_manager()->save();
		$this->assertSame( array(), \WC_Settings_Page::$calls, 'nothing saved' );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
		$this->assertStringContainsString( 'permission', \WC_Admin_Settings::$errors[0] );
	}

	public function test_administrators_can_save_and_the_nonce_is_checked_first(): void {
		$GLOBALS['current_section'] = 'advanced';
		$this->as_admin()->save();
		$this->assertSame( array( 'woocommerce-settings' ), $this->referer );
		$this->assertSame( array( 'parent_save' ), \WC_Settings_Page::$calls );
		$this->assertSame( array(), \WC_Admin_Settings::$errors );
	}

	public function test_other_sections_still_save_for_shop_managers(): void {
		$GLOBALS['current_section'] = 'status';
		$this->as_manager()->save();
		$this->assertSame( array( 'parent_save' ), \WC_Settings_Page::$calls );
	}

	public function test_uninstall_keeps_the_capability_guard(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );
		$this->assertStringContainsString( "current_user_can( 'delete_plugins' )", $source );
	}
}
