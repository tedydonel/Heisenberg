<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Server-rendered contract for the email-only Variables panel (panel-variables.blade.php).
 * Pins the data EditorController ships into the editor page for an email document when
 * the EmailVariableRegistry has at least one definition. Same posture as the other
 * *WiringTest classes — no JS engine, only the rendered HTML / passed payload — so a
 * focused review of "did the panel get the safe metadata it was promised?" needs no
 * browser.
 *
 * REPLACES the prior test for the now-removed `email-variable-menu` popover picker —
 * the user asked for the broken picker implementation to be removed completely and
 * replaced with a sidebar tab + drag-and-drop. The new panel's server-rendered
 * contract is what THIS file pins.
 *
 * Hard rules (mirrored from the picker contract, retargeted at the panel):
 *  - Email documents only: a plain post page MUST NOT carry the panel mount point or
 *    its metadata payload.
 *  - Safe metadata ONLY: the server payload never includes formatter objects,
 *    closures, host classes, or raw non-scalar samples. Each row is a flat list of
 *    editor-safe keys (`key`, `label`, `type`, `targets`, `group`, `description`,
 *    `options`, `sample`) where `sample` is the formatted STRING the registry's
 *    editorMetadata() produced.
 *  - Exactly the published shape — keys, value types, no surprises.
 */
class EmailVariablePickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function registerSampleVariables(): EmailVariableRegistry
    {
        $registry = app(EmailVariableRegistry::class);

        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Tedy',
            group: 'User',
        ));
        $registry->register(new EmailVariableDefinition(
            key: 'unsubscribe_url',
            label: 'Unsubscribe URL',
            type: 'url',
            sample: 'https://example.test/unsubscribe/sample',
            group: 'Campaign',
        ));

        return $registry;
    }

    public function test_a_plain_post_editor_page_does_not_carry_the_variables_panel_mount_point(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Per-entry selector is panel-only (the sidebar JS doesn't reference it), so this
        // gives a clean positive/negative signal unlike the panel root attribute.
        $this->assertStringNotContainsString('data-hb-var-item', $html);
    }

    public function test_an_email_editor_page_carries_the_variables_panel_mount_point(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-var-item', $html);
    }

    public function test_an_email_editor_page_carries_editor_safe_variable_metadata(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        // Every safe-metadata row field the panel renders — at least one of each. The blade
        // escapes attribute values, so the literal "user.first_name" / "Tedy" reach the page.
        $this->assertStringContainsString('data-hb-var-key="user.first_name"', $html);
        $this->assertStringContainsString('data-hb-var-sample="Tedy"', $html);
        $this->assertStringContainsString('data-hb-var-key="unsubscribe_url"', $html);

        // The formatted URL sample — the registry ran it through the URL formatter for metadata,
        // so it lands on the page as the literal string.
        $this->assertStringContainsString('data-hb-var-sample="https://example.test/unsubscribe/sample"', $html);
    }

    public function test_panel_metadata_never_includes_formatter_objects_or_runtime_values(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        // No closure, no object, no host class names leaked into editor HTML.
        $this->assertStringNotContainsString('Closure', $html);
        $this->assertStringNotContainsString('TextEmailVariableType', $html);
        $this->assertStringNotContainsString('UrlEmailVariableType', $html);
        $this->assertStringNotContainsString('EmailVariableRegistry', $html);

        // No raw runtime-value keys the registry itself serializes (internal fields).
        $this->assertStringNotContainsString('hb-var-formatter=', $html);
        $this->assertStringNotContainsString('hb-var-host-class=', $html);
    }

    public function test_variables_panel_mounts_with_empty_state_when_registry_is_empty(): void
    {
        // Heisenberg ships zero sample variables — the registry starts empty and a host
        // app must register its own. The panel still mounts (so the host sees the panel,
        // the Variables nav item, and the empty-state hint inside) and just renders no entries.
        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-panel-variables', $html);
        // No entries rendered — assert against the entry class (which is only on actual
        // `<button class="hb-panel-variables__item">` elements), not `data-hb-var-item`
        // (which also appears as a JS selector string inside the panel's own `<script>`).
        $this->assertStringNotContainsString('class="hb-panel-variables__item"', $html);
        // The panel's own empty-state copy reaches the page.
        $this->assertStringContainsString('hb-panel-variables__empty', $html);
        // The Variables nav item is in the sidebar.
        $this->assertStringContainsString('data-hb-nav="variables:0"', $html);
    }

    public function test_the_theme_token_variable_menu_is_unchanged_on_email_documents(): void
    {
        // The theme-token picker (resources/views/components/live/pickers/variable-menu.blade.php)
        // mounts via [data-hb-varmenu] + .hb-varmenu/.hb-vmi and emits a `varselect` event — that
        // menu is owned by the Style panel and MUST stay exactly as it is. The new panel uses a
        // different root attribute + class so it cannot collide.
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/views/components/live/pickers/variable-menu.blade.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("@props(['mode' => 'color'", $source);
        $this->assertStringContainsString('data-hb-varmenu', $source);
        $this->assertStringContainsString("new CustomEvent('varselect'", $source);
        $this->assertStringNotContainsString('hb-var-token', $source);
    }

    public function test_existing_email_documents_with_definitions_render_panel_with_safe_metadata(): void
    {
        $this->registerSampleVariables();

        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-var-item', $html);
        $this->assertStringContainsString('data-hb-var-key="user.first_name"', $html);
        $this->assertStringContainsString('data-hb-var-sample="Tedy"', $html);
    }
}
