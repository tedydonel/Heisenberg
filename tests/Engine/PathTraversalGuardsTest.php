<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Adapters\LucideIconProvider;
use Heisenberg\Adapters\PhosphorIconProvider;
use Heisenberg\Http\Controllers\EditorController;
use Heisenberg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression coverage for the two path-traversal guards the review (§4.7)
 * flagged as untested: the icon providers' allow-list-then-realpath-confine
 * SVG lookup, and EditorController::font()'s strict woff2 filename allow-list
 * (originally BuilderController::font() — identical guard, moved here when
 * the builder was removed 2026-08-02).
 * Both looked correct on inspection but had zero test coverage — a silent
 * regression in either is a same-process file-read primitive.
 */
class PathTraversalGuardsTest extends TestCase
{
    private string $iconRoot;
    private string $secretFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->iconRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-icons-' . uniqid('', true);
        mkdir($this->iconRoot, 0775, true);
        file_put_contents($this->iconRoot . DIRECTORY_SEPARATOR . 'plus.svg', '<svg>plus</svg>');

        // A "secret" file living OUTSIDE the icon root — the traversal target.
        $this->secretFile = dirname($this->iconRoot) . DIRECTORY_SEPARATOR . 'secret-' . basename($this->iconRoot) . '.txt';
        file_put_contents($this->secretFile, 'TOP SECRET');
    }

    protected function tearDown(): void
    {
        @unlink($this->iconRoot . DIRECTORY_SEPARATOR . 'plus.svg');
        @rmdir($this->iconRoot);
        @unlink($this->secretFile);

        parent::tearDown();
    }

    public static function traversalPayloads(): array
    {
        return [
            'parent traversal' => ['../secret'],
            'nested parent traversal' => ['../../../../../../etc/passwd'],
            'literal encoded-slash text' => ['..%2fsecret'],
            'backslash traversal' => ['..\\secret'],
            'absolute unix path' => ['/etc/passwd'],
            'absolute windows path' => ['C:\\Windows\\win.ini'],
            'null byte' => ["plus\0.svg"],
            'dot segment only' => ['.'],
            'double dot only' => ['..'],
            'mixed case with slash' => ['LUCIDE-../../secret'],
        ];
    }

    // ── LucideIconProvider ───────────────────────────────────────

    public function test_lucide_provider_serves_a_legitimate_slug(): void
    {
        $provider = new LucideIconProvider($this->iconRoot);

        $this->assertSame('<svg>plus</svg>', $provider->svg('plus'));
        $this->assertSame('<svg>plus</svg>', $provider->svg('lucide-plus'), 'the lucide- prefix must be stripped');
    }

    #[DataProvider('traversalPayloads')]
    public function test_lucide_provider_rejects_traversal_slugs(string $payload): void
    {
        $provider = new LucideIconProvider($this->iconRoot);

        $this->assertNull($provider->svg($payload), 'traversal payload leaked: ' . json_encode($payload));
    }

    public function test_lucide_provider_confines_to_root_even_for_a_similarly_named_sibling(): void
    {
        // A sibling directory whose name merely starts with the root's name
        // ("<root>-evil") must never be reachable through any slug the
        // allow-list can produce — this exercises the trailing-separator
        // confinement check the guard's docblock calls out (defense-in-depth
        // behind the slug regex, which already blocks any path separator).
        $evilSibling = $this->iconRoot . '-evil';
        mkdir($evilSibling, 0775, true);
        file_put_contents($evilSibling . DIRECTORY_SEPARATOR . 'plus.svg', '<svg>evil</svg>');

        try {
            $provider = new LucideIconProvider($this->iconRoot);

            $this->assertSame('<svg>plus</svg>', $provider->svg('plus'), 'the real root must still resolve normally');
            $this->assertNull(
                $provider->svg('../' . basename($evilSibling) . '/plus'),
                'a slash-bearing slug must never reach the sibling directory'
            );
        } finally {
            @unlink($evilSibling . DIRECTORY_SEPARATOR . 'plus.svg');
            @rmdir($evilSibling);
        }
    }

    public function test_lucide_provider_returns_null_when_the_root_does_not_exist(): void
    {
        $provider = new LucideIconProvider($this->iconRoot . DIRECTORY_SEPARATOR . 'nope');

        $this->assertNull($provider->svg('plus'));
    }

    // ── PhosphorIconProvider ─────────────────────────────────────

    public function test_phosphor_provider_serves_a_legitimate_slug(): void
    {
        $provider = new PhosphorIconProvider($this->iconRoot);

        $this->assertSame('<svg>plus</svg>', $provider->svg('plus'));
    }

    #[DataProvider('traversalPayloads')]
    public function test_phosphor_provider_rejects_traversal_slugs(string $payload): void
    {
        $provider = new PhosphorIconProvider($this->iconRoot);

        $this->assertNull($provider->svg($payload), 'traversal payload leaked: ' . json_encode($payload));
    }

    public function test_phosphor_provider_serves_a_real_vendored_icon_by_default(): void
    {
        // No explicit root -> the package's own vendored Phosphor set
        // (resources/icons/phosphor/regular), confirming the guard also
        // holds against the real shipped icon directory, not just a fixture.
        $provider = new PhosphorIconProvider();

        $this->assertNotNull($provider->svg('plus'));
        $this->assertNull($provider->svg('../../../../etc/passwd'));
    }

    // ── EditorController::font() ────────────────────────────────

    public function test_editor_controller_font_serves_an_allowlisted_vendored_file(): void
    {
        $response = $this->fontController()->font('rubik-300-900-normal-latin.woff2');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('font/woff2', (string) $response->headers->get('Content-Type'));
        $this->assertNotSame('', $response->getContent());
    }

    public static function fontTraversalPayloads(): array
    {
        return [
            'parent traversal' => ['../../../../composer.json'],
            'literal encoded parent traversal' => ['..%2f..%2f..%2fetc%2fpasswd'],
            'absolute unix path' => ['/etc/passwd'],
            'absolute windows path' => ['C:\\Windows\\win.ini'],
            'wrong extension' => ['rubik-300-900-normal-latin.woff'],
            'no extension' => ['rubik-300-900-normal-latin'],
            'php extension' => ['shell.php'],
            'traversal with legit-looking suffix' => ['..%2fbuilder.css%2fx.woff2'],
            'uppercase extension' => ['rubik-300-900-normal-latin.WOFF2'],
            'null byte' => ["rubik-300-900-normal-latin.woff2\0.php"],
            'embedded slash before extension' => ['a/b.woff2'],
            'embedded backslash before extension' => ['a\\b.woff2'],
            'leading dot' => ['.woff2'],
        ];
    }

    #[DataProvider('fontTraversalPayloads')]
    public function test_editor_controller_font_rejects_disallowed_filenames(string $payload): void
    {
        $response = $this->fontController()->font($payload);

        $this->assertSame(404, $response->getStatusCode(), 'disallowed filename was not rejected: ' . json_encode($payload));
    }

    public function test_editor_controller_font_404s_for_an_allowlisted_but_nonexistent_file(): void
    {
        // Passes the regex (name-shape allow-list) but no such file is vendored —
        // the is_file() check is the second half of the guard.
        $response = $this->fontController()->font('totally-not-a-real-font.woff2');

        $this->assertSame(404, $response->getStatusCode());
    }

    private function fontController(): EditorController
    {
        return $this->app->make(EditorController::class);
    }
}
