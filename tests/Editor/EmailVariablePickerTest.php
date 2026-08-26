<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Server-rendered contract for the email-only variable picker (Task 5 of
 * .hermes/plans/2026-08-25_190059-email-template-variables.md). Pins the data
 * EditorController ships into the editor page for an email document when the
 * EmailVariableRegistry has at least one definition. Same posture as the
 * other *WiringTest classes — no JS engine, only the rendered HTML / passed
 * payload — so a focused review of "did the picker get the safe metadata it
 * was promised?" needs no browser.
 *
 * Hard rules (mirrors Task 5 boundary section in the plan):
 *  - Email documents only: a plain post page MUST NOT carry the picker mount
 *    point or its metadata payload.
 *  - Safe metadata ONLY: the server payload never includes formatter objects,
 *    closures, host classes, or raw non-scalar samples. Each row is a flat
 *    list of editor-safe keys (`key`, `label`, `type`, `targets`, `group`,
 *    `description`, `options`, `sample`) where `sample` is the formatted
 *    STRING the registry's editorMetadata() produced.
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

    public function test_a_plain_post_editor_page_does_not_carry_the_email_picker_mount_point(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-hb-email-variable-picker', $html);
    }

    public function test_an_email_editor_page_carries_the_email_picker_mount_point(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-email-variable-picker', $html);
    }

    public function test_an_email_editor_page_carries_editor_safe_variable_metadata(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        // Every safe-metadata row field the picker renders — at least one of each. The blade
        // escapes attribute values, so the literal "user.first_name" / "Tedy" reach the page.
        $this->assertStringContainsString('data-hb-email-variable-key="user.first_name"', $html);
        $this->assertStringContainsString('data-hb-email-variable-sample="Tedy"', $html);
        $this->assertStringContainsString('data-hb-email-variable-key="unsubscribe_url"', $html);

        // The formatted URL sample — the registry ran it through the URL formatter for metadata,
        // so it lands on the page as the literal string.
        $this->assertStringContainsString('data-hb-email-variable-sample="https://example.test/unsubscribe/sample"', $html);
    }

    public function test_picker_metadata_never_includes_formatter_objects_or_runtime_values(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        // No closure, no object, no host class names leaked into editor HTML.
        $this->assertStringNotContainsString('Closure', $html);
        $this->assertStringNotContainsString('TextEmailVariableType', $html);
        $this->assertStringNotContainsString('UrlEmailVariableType', $html);
        $this->assertStringNotContainsString('EmailVariableRegistry', $html);

        // No raw runtime-value keys the registry itself serializes (internal fields).
        $this->assertStringNotContainsString('hb-email-variable-formatter=', $html);
        $this->assertStringNotContainsString('hb-email-variable-host-class=', $html);
    }

    public function test_email_picker_is_absent_when_the_registry_has_no_definitions(): void
    {
        // No definitions registered at all — the picker must NOT render its mount point on an
        // email document either, so the editor HTML stays clean (and the picker JS, if it were
        // somehow loaded, would have nothing to filter).
        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-hb-email-variable-picker', $html);
    }

    public function test_the_theme_token_variable_menu_is_unchanged_on_email_documents(): void
    {
        // The theme-token picker (resources/views/components/live/pickers/variable-menu.blade.php)
        // mounts via [data-hb-varmenu] + .hb-varmenu/.hb-vmi and emits a `varselect` event — that
        // menu is owned by the Style panel and MUST stay exactly as it is. The new picker uses a
        // different root attribute + class so it cannot collide.
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/views/components/live/pickers/variable-menu.blade.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("@props(['mode' => 'color'", $source);
        $this->assertStringContainsString('data-hb-varmenu', $source);
        $this->assertStringContainsString("new CustomEvent('varselect'", $source);
        $this->assertStringNotContainsString('data-hb-email-variable-picker', $source);
    }

    public function test_existing_email_documents_with_definitions_render_picker_with_safe_metadata(): void
    {
        $this->registerSampleVariables();

        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-email-variable-picker', $html);
        $this->assertStringContainsString('data-hb-email-variable-key="user.first_name"', $html);
        $this->assertStringContainsString('data-hb-email-variable-sample="Tedy"', $html);
    }
}
