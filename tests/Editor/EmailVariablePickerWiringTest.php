<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Wiring-side contract for the email-only Variables panel (panel-variables.blade.php).
 *
 * REPLACES the prior test for the now-removed `email-variable-menu` popover
 * picker — the user asked for the broken picker implementation to be removed
 * completely and replaced with a sidebar tab + drag-and-drop. The new panel's
 * server-rendered contract is what THIS file pins: data attributes the runtime
 * JS reads, the gating that keeps the panel OFF plain posts and read-only views,
 * and the rule that leaves the theme-token picker untouched.
 *
 * The panel's client-side behaviour (drag-drop, drag image, chip insertion at
 * caret, contenteditable=false atom semantics) is exercised through the JS-only
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

    public function test_panel_root_is_mounted_on_a_new_email_document(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-panel-variables', $html);
    }

    public function test_each_entry_carries_the_safe_metadata_attributes(): void
    {
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        // text-typed entry exposes key + label + type + sample + group + targets.
        $this->assertMatchesRegularExpression(
            '/data-hb-var-key="user\.first_name"[^>]*data-hb-var-type="text"/',
            $html,
        );
        // url-typed entry.
        $this->assertMatchesRegularExpression(
            '/data-hb-var-key="unsubscribe_url"[^>]*data-hb-var-type="url"/',
            $html,
        );
        // email-typed entry exposes both `email` and `url` targets (mailto: link compatibility).
        $this->assertMatchesRegularExpression(
            '/data-hb-var-key="user\.email"[^>]*data-hb-var-targets="email,url"/',
            $html,
        );
        // sample + group metadata survive the panel's grouping pass.
        $this->assertMatchesRegularExpression(
            '/data-hb-var-sample="Tedy"[^>]*data-hb-var-group="User"/',
            $html,
        );
        // Every entry is draggable — drag-and-drop is the only insertion UX.
        $this->assertStringContainsString('draggable="true"', $html);
    }

    public function test_the_theme_token_picker_mount_is_byte_stable(): void
    {
        // The existing theme-token picker ([data-hb-varmenu] + .hb-varmenu/.hb-vmi + `varselect`)
        // MUST remain exactly as it is — Heisenberg E5 splits "CSS variable picker" from "email
        // Variables panel" so the Style panel is untouched. Asserting the literal substrings here
        // catches any accidental re-parenting.
        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-varmenu', $html);
        $this->assertStringContainsString('class="hb-pop hb-varmenu"', $html);
        $this->assertStringContainsString('hb-varmenu__list', $html);
        $this->assertStringContainsString('hb-varmenu__search', $html);
        $this->assertStringContainsString('hb-vmi__name', $html);
    }

    public function test_a_plain_post_page_does_not_mount_the_variables_panel(): void
    {
        // The panel is email-only — a plain post editor page never mounts it. We assert
        // against `class="hb-panel-variables__item"` (the per-entry class) rather than the
        // data-attribute, because the panel's own `<script>` block contains the substring
        // `data-hb-var-item` as a JS selector even when no entries are rendered.
        $this->registerSampleVariables();

        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringNotContainsString('class="hb-panel-variables__item"', $html);
    }

    public function test_a_read_only_email_page_does_not_mount_the_panel(): void
    {
        // Authorization mirrors PostPolicy::update — a viewer-tier actor doesn't get the panel
        // even on an email document. The LocalDevRoleGate's local-bypass is bypassed in this test
        // by setting the env to production, so a GuestActor (no actor) cannot `update` the post.
        $this->app['env'] = 'production';
        $this->registerSampleVariables();

        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en', 'status' => 'published']);
        $post->type = 'email';
        $post->save();

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('class="hb-panel-variables__item"', $html);
    }

    public function test_a_guest_cannot_mount_the_panel_on_a_new_email_outside_local_development(): void
    {
        $this->app['env'] = 'production';
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringNotContainsString('class="hb-panel-variables__item"', $html);
    }

    public function test_panel_drop_script_handles_drop_targets_and_chip_insertion(): void
    {
        // The panel's runtime JS wires HTML5 drag-drop into the canvas's rich-text editables
        // and the canvas subject. The chip it inserts is a non-editable atom carrying the
        // variable's key — the SAME persistence path `{{ key }}` text tokens take, so the
        // block-runtime's input listener saves the chip verbatim.
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        // Drop target selector — `.hb-ce[data-hb-rt]` (canvas rich-text) + the canvas title.
        $this->assertStringContainsString('.hb-ce[data-hb-rt], h1[data-hb-title][contenteditable="true"]', $html);
        // The chip created on drop carries the key as a data attribute (what the renderer reads).
        $this->assertStringContainsString("setAttribute('data-hb-var-key', payload.key)", $html);
        // The chip is non-editable — contenteditable=false makes it an inline atom (Backspace
        // deletes the whole chip; arrow keys skip past it; no half-edit inside the chip).
        $this->assertStringContainsString("setAttribute('contenteditable', 'false')", $html);
        // The chip's insertion dispatches an InputEvent so the existing input listener in the
        // block-runtime persists the rich-text attribute's innerHTML unchanged.
        $this->assertStringContainsString("dispatchEvent(new InputCtor('input'", $html);
        // The dropped payload is NEVER inserted via innerHTML — chips are DOM nodes, not raw text.
        $this->assertStringNotContainsString("field.innerHTML = token", $html);
    }

    public function test_panel_lists_grouped_sections_per_registry_group(): void
    {
        // The panel renders a `<x-heisenberg::ui.category-head>` per non-empty registry group;
        // ungrouped entries follow after the named groups (panel-variables.blade.php's uksort).
        $this->registerSampleVariables();

        $html = $this->get('/editor/email')->assertOk()->getContent();

        // Both seeded groups land on the page (User, Campaign).
        $this->assertStringContainsString('data-hb-var-group="User"', $html);
        $this->assertStringContainsString('data-hb-var-group="Campaign"', $html);
    }

    public function test_existing_email_documents_load_the_panel_with_the_same_metadata(): void
    {
        // Loading an existing email doc must produce the same panel payload as a blank one —
        // registry entries are read at render time and never differ between new and existing
        // documents of the same type.
        $this->registerSampleVariables();

        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-panel-variables', $html);
        $this->assertStringContainsString('data-hb-var-key="user.first_name"', $html);
        $this->assertStringContainsString('data-hb-var-key="unsubscribe_url"', $html);
    }
}
