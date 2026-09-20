<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Http\Controllers\EditorController;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * Editor CSS asset caching (2026-09-19 review fix). The /heisenberg-assets/*.css
 * routes (editor.css, editor-animations.css, editor-supports.css) used to
 * concatenate/generate their content and return `Cache-Control: no-store` on
 * every request — the code comment literally said "revisit for production".
 *
 * They now:
 *   - publish a content-derived `?v=` on every <link> tag (see cssAssetVersion()
 *     and friends in EditorController, and the layouts that reference them);
 *   - serve `public, max-age=31536000, immutable` when the request's `v` query
 *     matches the current version (the URL can then never point at different
 *     content, so caching forever is safe);
 *   - otherwise fall back to standard ETag/If-None-Match revalidation (304),
 *     which is what a direct hit on the bare route — as several *other* Editor
 *     tests do — exercises;
 *   - never go immutable in `local`, so editing CSS on disk is visible on the
 *     next reload.
 */
class EditorAssetCachingTest extends TestCase
{
    use RefreshDatabase;

    // No explicit Cache::forget() teardown: testbench gives each test method its
    // own fresh Application (and cache repository), and this host's default cache
    // store may not even have its table migrated in this suite — exactly the
    // "host's cache backend isn't this route's problem" case EditorController's
    // cachedVersion() helper is written to tolerate (see its own doc comment).

    /**
     * Symfony's ResponseHeaderBag re-derives and re-orders Cache-Control from its
     * own parsed directive flags (and adds `private` when neither `public` nor
     * `private` was otherwise implied), so the header actually sent never matches
     * the literal string EditorController writes byte-for-byte. Assert on the
     * directives that matter instead of the full string.
     *
     * @param list<string> $expected
     */
    private function assertCacheControlHasDirectives(TestResponse $response, array $expected): void
    {
        $raw = (string) $response->headers->get('Cache-Control');
        $actual = array_map('trim', explode(',', $raw));

        foreach ($expected as $directive) {
            $this->assertContains($directive, $actual, "Cache-Control [{$raw}] is missing [{$directive}]");
        }
    }

    public function test_editor_page_links_a_versioned_css_url(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertMatchesRegularExpression(
            '#/heisenberg-assets/editor\.css\?v=[0-9a-f]{12}#',
            (string) $html
        );
    }

    public function test_version_helpers_produce_a_stable_twelve_char_hex_hash(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', EditorController::cssAssetVersion());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', EditorController::animationsAssetVersion());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', EditorController::supportsAssetVersion());

        // Stable across repeated calls within the same request/app lifetime.
        $this->assertSame(EditorController::cssAssetVersion(), EditorController::cssAssetVersion());
    }

    public function test_css_route_reports_an_etag_matching_its_own_version(): void
    {
        $version = EditorController::cssAssetVersion();

        $response = $this->get('/heisenberg-assets/editor.css');

        $response->assertOk();
        $response->assertHeader('ETag', '"' . $version . '"');
    }

    public function test_matching_version_query_gets_immutable_cache_control(): void
    {
        $version = EditorController::cssAssetVersion();

        $response = $this->get('/heisenberg-assets/editor.css?v=' . $version);

        $response->assertOk();
        $this->assertCacheControlHasDirectives($response, ['public', 'max-age=31536000', 'immutable']);
    }

    public function test_missing_version_query_falls_back_to_revalidation_not_immutable(): void
    {
        // This is exactly what tests/Editor/EditorRendersTest.php and friends already
        // do — hit the bare route with no `v` — so it must stay a normal 200 with a
        // revalidate-style header, never no-store and never immutable.
        $response = $this->get('/heisenberg-assets/editor.css');

        $response->assertOk();
        $this->assertCacheControlHasDirectives($response, ['public', 'max-age=0', 'must-revalidate']);
        $this->assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_stale_version_query_also_falls_back_to_revalidation(): void
    {
        $response = $this->get('/heisenberg-assets/editor.css?v=0000stale000');

        $response->assertOk();
        $this->assertCacheControlHasDirectives($response, ['public', 'max-age=0', 'must-revalidate']);
        $this->assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_matching_if_none_match_returns_304(): void
    {
        $etag = '"' . EditorController::cssAssetVersion() . '"';

        $response = $this->get('/heisenberg-assets/editor.css', ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $response->assertHeader('ETag', $etag);
    }

    public function test_non_matching_if_none_match_returns_full_body(): void
    {
        $response = $this->get('/heisenberg-assets/editor.css', ['If-None-Match' => '"not-the-real-one"']);

        $response->assertOk();
        $this->assertNotEmpty($response->getContent());
    }

    public function test_animations_and_supports_routes_also_support_etag_304(): void
    {
        $animationsEtag = '"' . EditorController::animationsAssetVersion() . '"';
        $supportsEtag = '"' . EditorController::supportsAssetVersion() . '"';

        $this->get('/heisenberg-assets/editor-animations.css', ['If-None-Match' => $animationsEtag])
            ->assertStatus(304);
        $this->get('/heisenberg-assets/editor-supports.css', ['If-None-Match' => $supportsEtag])
            ->assertStatus(304);
    }

    public function test_local_environment_never_serves_immutable_cache_control(): void
    {
        $this->app['env'] = 'local';

        $version = EditorController::cssAssetVersion();
        $response = $this->get('/heisenberg-assets/editor.css?v=' . $version);

        $response->assertOk();
        $this->assertCacheControlHasDirectives($response, ['no-cache']);
        $this->assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_content_version_changes_when_content_changes(): void
    {
        $this->assertNotSame(
            EditorController::contentVersion('body{}'),
            EditorController::contentVersion('body{color:red}')
        );
        $this->assertSame(
            EditorController::contentVersion('same'),
            EditorController::contentVersion('same')
        );
    }

    public function test_file_fingerprint_changes_when_a_source_file_changes(): void
    {
        $dir = sys_get_temp_dir() . '/hb-css-fingerprint-' . uniqid('', true);
        mkdir($dir);
        $file = $dir . '/a.css';
        file_put_contents($file, 'body{}');

        $before = EditorController::fingerprintFiles([$file]);

        // A different mtime *and* size, so the fingerprint is guaranteed to change
        // even on filesystems with coarse mtime resolution.
        file_put_contents($file, 'body{color:red}');
        touch($file, time() + 5);

        $after = EditorController::fingerprintFiles([$file]);

        $this->assertNotSame($before, $after);

        unlink($file);
        rmdir($dir);
    }
}
