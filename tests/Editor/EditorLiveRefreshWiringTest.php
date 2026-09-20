<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Tests\Support\AssertsHtmlStructure;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * LIVE EDITOR UPDATES wiring: resources/views/components/live/editor-live-refresh.blade.php (the
 * client-side poller + dismissible notice) is mounted on every /editor page, carries the
 * PostLiveController URLs + i18n strings the script needs as a JSON data island (per
 * AssertsHtmlStructure's own guidance: assert the DATA handed to the script, not the JS that
 * reads it), and the topbar's minimal window.hbTopbarState bridge (live/topbar/script.blade.php)
 * is present for it to read/write. Distinct from PostLiveControllerTest, which covers the HTTP
 * endpoint itself.
 */
class EditorLiveRefreshWiringTest extends TestCase
{
    use AssertsHtmlStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutCsrfProtection();
    }

    public function test_the_live_refresh_notice_and_its_config_island_ship_on_the_editor_page(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertElementExists($html, '[data-hb-live-refresh]');
        $this->assertElementHasAttribute($html, '[data-hb-live-refresh]', 'hidden');
        $this->assertElementExists($html, '[data-hb-live-refresh-text]');
        $this->assertElementExists($html, '[data-hb-live-refresh-load]');
        $this->assertElementExists($html, '[data-hb-live-refresh-dismiss]');

        $config = $this->hbJsonScript($html, 'script[data-hb-live-refresh-config]');
        $this->assertSame(route('heisenberg.editor.posts.live-status', ['post' => '__ID__']), $config['statusUrlTemplate']);
        $this->assertSame(route('heisenberg.editor.posts.show', ['post' => '__ID__']), $config['showUrlTemplate']);
        $this->assertSame((int) config('heisenberg.editor.live_refresh.interval_ms'), $config['intervalMs']);
        $this->assertSame(__('heisenberg::editor.live_refresh.applied'), $config['msgApplied']);
        $this->assertSame(__('heisenberg::editor.live_refresh.available'), $config['msgAvailable']);
    }

    public function test_the_load_button_text_and_dismiss_button_text_are_localized(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertElementTextSame($html, '[data-hb-live-refresh-load]', __('heisenberg::editor.live_refresh.load'));
        $this->assertElementTextSame($html, '[data-hb-live-refresh-dismiss]', __('heisenberg::editor.live_refresh.dismiss'));
    }

    public function test_disabling_the_feature_via_config_omits_the_notice_and_its_config_island_entirely(): void
    {
        config(['heisenberg.editor.live_refresh.enabled' => false]);

        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertElementMissing($html, '[data-hb-live-refresh]');
        $this->assertElementMissing($html, 'script[data-hb-live-refresh-config]');
    }

    public function test_a_configured_interval_below_the_one_second_floor_is_clamped(): void
    {
        config(['heisenberg.editor.live_refresh.interval_ms' => 10]);

        $html = $this->get('/editor')->assertOk()->getContent();
        $config = $this->hbJsonScript($html, 'script[data-hb-live-refresh-config]');

        $this->assertSame(1000, $config['intervalMs']);
    }

    public function test_the_notice_only_ever_calls_the_shared_replace_doc_and_acknowledge_external_sync_bridge(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // No second document-replacement path — reuses the exact call the in-editor AI
        // assistant already uses to swap the canvas.
        $this->assertInlineScriptContains($html, 'window.hbEditor.replaceDoc(data.blocks || [])');
        // Adopts the fetched version + clears dirty/autosave state through the topbar's own
        // bridge, rather than keeping a second, divergent copy of that state here.
        $this->assertInlineScriptContains($html, 'window.hbTopbarState.acknowledgeExternalSync(data.post.content_version)');
        // Never applies while a save is in flight, and never overwrites an unsaved local edit
        // without the user's own confirmation via the Load button.
        $this->assertInlineScriptContains($html, 'window.hbTopbarState.isSaving()');
        // The unsaved-work guard consults the topbar's REAL-change check (falling back to the
        // bare flag on an older bridge), rather than gating on hbDirty alone — gating on the
        // flag meant one stray click stopped live updates permanently. Matched as identifiers
        // so the guard can be restructured without rewriting this test.
        $this->assertInlineScriptMatches($html, '/\btopbar\.hasUnsavedChanges\s*\(\s*\)/');
        $this->assertInlineScriptMatches($html, '/\btopbar\.isDirty\s*\(\s*\)/');
    }

    public function test_topbar_script_exposes_the_read_write_bridge_the_notice_depends_on(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertInlineScriptContains($html, 'window.hbTopbarState = {');
        $this->assertInlineScriptContains($html, 'getContentVersion: () => hbContentVersion');
        $this->assertInlineScriptContains($html, 'isDirty: () => hbDirty');
        $this->assertInlineScriptContains($html, 'isSaving: () => !!hbSaveInFlight');
        $this->assertInlineScriptContains($html, 'acknowledgeExternalSync: (newVersion) => {');
    }

    public function test_polling_stops_when_the_tab_is_hidden_and_resumes_on_visibilitychange(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertInlineScriptContains($html, "document.visibilityState !== 'visible'");
        $this->assertInlineScriptContains($html, "document.addEventListener('visibilitychange'");
    }

    public function test_the_notice_starts_polling_a_brand_new_post_once_it_gets_an_id(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertInlineScriptContains($html, "document.addEventListener('hb:post-id'");
    }

    public function test_email_editor_also_ships_the_live_refresh_notice(): void
    {
        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertElementExists($html, '[data-hb-live-refresh]');
    }
}
