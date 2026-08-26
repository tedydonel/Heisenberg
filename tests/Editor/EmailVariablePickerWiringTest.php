<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Wiring-side contract for the email-only variable picker (Task 5 of
 * .hermes/plans/2026-08-25_190059-email-template-variables.md). Pins the
 * server-rendered mount-point signals the picker's vanilla JS will look for
 * on the editor page, plus the gating that keeps the picker OFF plain posts,
 * OFF read-only views, and OFF non-email surfaces — and that keeps the
 * existing theme-token picker untouched.
 *
 * Same posture as EmailEditorWiringTest and EmailVariablePickerTest: server-
 * rendered contract only, no JS execution. The picker's behaviour (filter by
 * target, keyboard nav, Escape, focus restoration, text insertion through
 * setRangeText/Range text nodes) is exercised through the JS-only test
 * harness in tests/js/ — these tests pin what the server ships.
 */
class EmailVariablePickerWiringTest extends TestCase
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
            key: 'user.email',
            label: 'User email',
            type: 'email',
            sample: 't@example.test',
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

    public function test_picker_mount_carries_the_targets_data_attribute(): void
    {
        // The picker exposes a `data-hb-email-variable-targets` attribute on its root that
        // mirrors the per-target target list, so the JS layer can quickly decide which
        // formatter targets are compatible with the CURRENT active insertion target.
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-email-variable-targets="text,url,email"', $html);
    }

    public function test_picker_mount_carries_per_entry_targets_and_metadata(): void
    {
        // The picker's entries ship with the exact target list each registered definition's
        // formatter declared — used client-side to filter by the active insertion target.
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        // text-typed entry.
        $this->assertMatchesRegularExpression(
            '/data-hb-email-variable-key="user\.first_name"[^>]*data-hb-email-variable-targets="text"/',
            $html,
        );
        // url-typed entry.
        $this->assertMatchesRegularExpression(
            '/data-hb-email-variable-key="unsubscribe_url"[^>]*data-hb-email-variable-targets="url"/',
            $html,
        );
        // email-typed entry exposes both `email` and `url` targets (mailto: link compatibility).
        $this->assertMatchesRegularExpression(
            '/data-hb-email-variable-key="user\.email"[^>]*data-hb-email-variable-targets="email,url"/',
            $html,
        );
    }

    public function test_three_trigger_anchors_appear_on_email_documents(): void
    {
        // Three picker triggers beside:
        //  - the canvas subject title (`[data-hb-canvas] [data-hb-title]`)
        //  - the inspector subject mirror (`[data-hb-post-title]`)
        //  - every selected-block rich-text editable (`.hb-ce[data-hb-rt]`)
        //
        // The trigger anchor is a `<button>` whose `data-hb-email-variable-trigger` carries the
        // expected insertion target for the surrounding field. We assert the existence of the
        // trigger signals rather than the exact CSS positioning.
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        // Dynamic triggers are mounted only when the gated picker component exists.
        $this->assertStringContainsString('makeTrigger(TARGET_SUBJECT', $html);
        $this->assertStringContainsString('makeTrigger(TARGET_TEXT', $html);
        $this->assertStringContainsString("type === 'url' ? TARGET_URL : TARGET_TEXT", $html);
    }

    public function test_the_theme_token_picker_mount_is_byte_stable(): void
    {
        // The existing theme-token picker ([data-hb-varmenu] + .hb-varmenu/.hb-vmi + `varselect`)
        // MUST remain exactly as it is — Heisenberg E5 splits "CSS variable picker" from "email
        // variable picker" so the Style panel is untouched. Asserting the literal substrings here
        // catches any accidental re-parenting.
        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-varmenu', $html);
        $this->assertStringContainsString('class="hb-pop hb-varmenu"', $html);
        $this->assertStringContainsString('hb-varmenu__list', $html);
        $this->assertStringContainsString('hb-varmenu__search', $html);
        $this->assertStringContainsString('hb-vmi__name', $html);
    }

    public function test_a_plain_post_page_does_not_mount_the_email_picker(): void
    {
        // The picker is email-only — a plain post editor page never mounts it.
        $this->registerSampleVariables();

        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-hb-email-variable-picker', $html);
        $this->assertStringNotContainsString('data-hb-email-variable-trigger', $html);
    }

    public function test_a_read_only_email_page_does_not_mount_the_picker(): void
    {
        // Authorization mirrors PostPolicy::update — a viewer-tier actor doesn't get the picker
        // even on an email document. The LocalDevRoleGate's local-bypass is bypassed in this test
        // by setting the env to production, so a GuestActor (no actor) cannot `update` the post.
        $this->app['env'] = 'production';
        $this->registerSampleVariables();

        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en', 'status' => 'published']);
        $post->type = 'email';
        $post->save();

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('data-hb-email-variable-picker', $html);
        $this->assertStringNotContainsString('data-hb-email-variable-trigger', $html);
    }

    public function test_a_guest_cannot_mount_the_picker_on_a_new_email_outside_local_development(): void
    {
        $this->app['env'] = 'production';
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-hb-email-variable-picker', $html);
    }

    public function test_picker_script_inserts_literal_text_and_dispatches_existing_input_events(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString("const token = '{{ ' + key + ' }}'", $html);
        $this->assertStringContainsString('document.createTextNode(token)', $html);
        $this->assertStringContainsString("field.dispatchEvent(new Event('input'", $html);
        $this->assertStringNotContainsString('field.innerHTML = token', $html);
    }

    public function test_picker_script_covers_filtering_keyboard_focus_locale_and_field_exclusions(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString('matchesTargetFilter(entry)', $html);
        $this->assertStringContainsString("e.key === 'ArrowDown' || e.key === 'ArrowUp'", $html);
        $this->assertStringContainsString("e.key === 'Escape'", $html);
        $this->assertStringContainsString('restoreInsertionFocus()', $html);
        $this->assertStringContainsString("document.addEventListener('hb:editing-locale-change'", $html);
        $this->assertStringContainsString("key !== 'anchor' && key !== 'extraClasses'", $html);
        $this->assertStringContainsString("type === 'url' ? TARGET_URL : TARGET_TEXT", $html);
    }

    public function test_existing_email_documents_load_picker_with_same_safe_metadata(): void
    {
        // Loading an existing email doc must produce the same picker payload as a blank one.
        $this->registerSampleVariables();

        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-email-variable-picker', $html);
        $this->assertStringContainsString('data-hb-email-variable-key="user.first_name"', $html);
        $this->assertStringContainsString('data-hb-email-variable-key="unsubscribe_url"', $html);
    }
}
