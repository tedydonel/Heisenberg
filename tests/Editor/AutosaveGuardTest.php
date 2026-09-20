<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Autosave guardrails (2026-09-19 review fix) in
 * resources/views/components/live/topbar/script.blade.php.
 *
 * There is no JS execution harness wired into PHPUnit here (see the .mjs files
 * under tests/js/, which are real-browser coverage run separately, and the
 * existing pin in EditorSaveWiringTest) — so, consistent with that file and
 * with tests/Editor/EditingLocaleTest.php, this pins the literal emitted JS
 * source for the three guardrails:
 *
 *   (a) skip the network request when nothing actually changed since the last
 *       successful save (byte-identical block tree + titles);
 *   (b) adaptive debounce — a bigger payload gets a longer autosave delay;
 *   (c) never start a new save while one is already in flight (pre-existing —
 *       this test only pins that the guard clause is still there).
 */
class AutosaveGuardTest extends TestCase
{
    use RefreshDatabase;

    private function topbarScript(): string
    {
        return (string) $this->get('/editor')->getContent();
    }

    public function test_never_overlapping_saves_guard_is_present(): void
    {
        $html = $this->topbarScript();

        // (c) pre-existing guard, verified rather than duplicated.
        $this->assertStringContainsString('if (hbSaveInFlight) return;', $html);
        // ...and a save still in flight when it finishes reschedules if dirty.
        $this->assertStringContainsString('if (hbDirty) hbScheduleAutosave();', $html);
    }

    public function test_byte_identical_payload_skips_the_network_request(): void
    {
        $html = $this->topbarScript();

        $this->assertStringContainsString('let hbLastSavedCore = null;', $html);
        $this->assertStringContainsString(
            'const corePayload = JSON.stringify(Object.assign({}, window.hbEditor.buildSavePayload({}), titleExtra));',
            $html
        );
        $this->assertStringContainsString(
            'if (!explicit && hbLastSavedCore !== null && corePayload === hbLastSavedCore) {',
            $html
        );
        // The baseline is recorded on every successful save (autosave or explicit).
        $this->assertStringContainsString('hbLastSavedCore = corePayload;', $html);
    }

    public function test_adaptive_debounce_scales_with_payload_size(): void
    {
        $html = $this->topbarScript();

        $this->assertStringContainsString('const HB_AUTOSAVE_MS_LARGE = 8000;', $html);
        $this->assertStringContainsString('const HB_AUTOSAVE_HUGE_CHARS = 1024 * 1024;', $html);
        $this->assertStringContainsString('const HB_AUTOSAVE_MS_HUGE = 15000;', $html);
        $this->assertStringContainsString('function hbAutosaveDelayMs()', $html);
        // The scheduler actually uses the adaptive delay, not the flat constant.
        $this->assertStringContainsString(
            'hbAutosaveTimer = setTimeout(() => hbPerformSave(false), hbAutosaveDelayMs());',
            $html
        );
    }

    public function test_optimistic_locking_content_version_flow_is_untouched(): void
    {
        $html = $this->topbarScript();

        // Guardrails must not disturb the existing content_version round trip.
        $this->assertStringContainsString('if (hbPostId !== null) extra.content_version = hbContentVersion;', $html);
        $this->assertStringContainsString('if (res.data.post.content_version != null) hbContentVersion = res.data.post.content_version;', $html);
    }
}
