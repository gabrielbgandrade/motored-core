<?php
namespace Motored\Core\Tests\Unit\Support;

use Motored\Core\Api;
use Motored\Core\Tests\Unit\TestCase;

/**
 * Guard of the engine/skin contract (docs/THEME-CONTRACT.md, AGENTS.md rule 9).
 *
 * The plugin owns structure and behavior; the theme owns the look. Storefront CSS may only reach visual
 * values through --motored-* tokens (literals only as var() fallbacks), never uses !important, and no
 * storefront asset or PHP class knows a theme. Breaking this is what made plugin and theme overwrite each other.
 */
final class ThemeContractTest extends TestCase {

	/**
	 * Plugin root directory.
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Storefront stylesheets (admin CSS has its own guard).
	 *
	 * @return list<string>
	 */
	private function storefront_css(): array {
		$files = array_values( array_filter( (array) glob( $this->root() . '/assets/css/*.css' ), 'is_string' ) );
		$this->assertGreaterThanOrEqual( 3, count( $files ), 'storefront stylesheets were found' );
		return $files;
	}

	/**
	 * CSS without comments.
	 *
	 * @param string $file Path.
	 */
	private function css( string $file ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $file ) );
	}

	public function test_storefront_css_never_uses_important(): void {
		foreach ( $this->storefront_css() as $file ) {
			$this->assertStringNotContainsString( '!important', $this->css( $file ), basename( $file ) . ': win with specificity, or leave the look to the theme' );
		}
	}

	public function test_storefront_css_reads_no_theme_tokens_or_selectors(): void {
		foreach ( $this->storefront_css() as $file ) {
			$this->assertDoesNotMatchRegularExpression(
				'/--mt-|flatsome|storefront-|\.mfp-|#search-lightbox|\.header\b|\.nav-dropdown/i',
				$this->css( $file ),
				basename( $file ) . ': theme tokens and selectors belong to the theme'
			);
		}
	}

	public function test_color_literals_are_only_token_fallbacks(): void {
		foreach ( $this->storefront_css() as $file ) {
			preg_match_all( '/(--?[a-z][a-z0-9-]*|[a-z][a-z-]*)\s*:\s*([^;{}]+)(?=[;}])/i', $this->css( $file ), $decls, PREG_SET_ORDER );
			$this->assertNotEmpty( $decls, basename( $file ) . ': declarations were parsed' );
			foreach ( $decls as $decl ) {
				$value = $decl[2];
				if ( ! preg_match_all( '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\(/i', $value, $hits, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $hits[0] as $hit ) {
					$before = substr( $value, 0, (int) $hit[1] );
					$this->assertMatchesRegularExpression(
						'/var\(\s*--motored-[a-z0-9-]+\s*,/',
						$before,
						basename( $file ) . ": '{$decl[1]}: " . trim( $value ) . "' hard-codes a color; read a --motored-* token"
					);
				}
			}
		}
	}

	public function test_storefront_js_never_calls_theme_code(): void {
		$files = array_values( array_filter( (array) glob( $this->root() . '/assets/js/*.js' ), 'is_string' ) );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$code = (string) preg_replace( '#/\*.*?\*/|^\s*//.*$#sm', '', (string) file_get_contents( $file ) );
			$this->assertDoesNotMatchRegularExpression( '/Flatsome|flatsome|magnificPopup|\.mfp-/', $code, basename( $file ) . ': dispatch a motored:* event and let the theme react' );
		}
	}

	public function test_php_never_names_a_theme(): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root() . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression( '/flatsome|get_template_directory|get_stylesheet_directory_uri/i', (string) file_get_contents( $file->getPathname() ), $file->getFilename() );
		}
	}

	public function test_contract_version_is_documented(): void {
		$doc = (string) file_get_contents( $this->root() . '/docs/THEME-CONTRACT.md' );
		$this->assertStringContainsString( 'Contract version: **' . Api::CONTRACT . '**', $doc );
		$this->assertTrue( Api::supports( (string) strtok( Api::CONTRACT, '.' ) ) );
		$this->assertFalse( Api::supports( '0' ) );
	}

	/**
	 * The store theme states which contract major it implements (its docs/MOTORED-CONTRACT.md). When the theme
	 * sits next to the plugin (the Studio site), both sides must agree, so a contract change cannot land on one
	 * side only. Skipped when the plugin is checked out alone (CI, zip build).
	 */
	public function test_paired_theme_implements_the_same_contract_major(): void {
		$theme_side = dirname( $this->root(), 2 ) . '/themes/flatsome/docs/MOTORED-CONTRACT.md';
		if ( ! is_file( $theme_side ) ) {
			$this->markTestSkipped( 'Store theme not found next to the plugin.' );
		}
		$this->assertMatchesRegularExpression( '/Implements contract version: \*\*(\d+)\*\*/', (string) file_get_contents( $theme_side ), 'theme side states its contract major' );
		preg_match( '/Implements contract version: \*\*(\d+)\*\*/', (string) file_get_contents( $theme_side ), $m );
		$this->assertTrue( Api::supports( $m[1] ), 'theme implements contract ' . $m[1] . ', plugin is ' . Api::CONTRACT . ': update both sides together' );
	}
}
