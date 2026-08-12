<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Wiring pins for the SEO/Social panel (docs/seo-system.md §3-§4, Wave S2a):
 * live/panel-seo-social.blade.php's field markers, the shared slug mechanism with
 * inspector.blade.php's Summary URL row, the score container, and the analyze URL template.
 * Not a script-execution test (no JS engine here) — these pin the SERVER-RENDERED contract the
 * panel's own script depends on, same posture InspectorWiringTest already takes for
 * `data-hb-control`.
 */
class SeoPanelWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function blankEditorHtml(): string
    {
        return $this->get('/editor')->getContent();
    }

    // ── field markers (blank /editor document) ──────────────────────────────

    public function test_every_seo_field_carries_its_data_hb_seo_field_marker(): void
    {
        $html = $this->blankEditorHtml();

        foreach ([
            'meta_title', 'meta_description', 'focus_keyphrase', 'canonical_url',
            'og_image', 'og_title', 'og_description',
            'robots_index', 'robots_follow', 'in_sitemap',
        ] as $key) {
            $this->assertStringContainsString(
                'data-hb-seo-field="' . $key . '"',
                $html,
                "seo field \"{$key}\" has no data-hb-seo-field marker — the panel writes nowhere",
            );
        }
    }

    public function test_seo_fields_are_disabled_until_the_post_is_saved(): void
    {
        $html = $this->blankEditorHtml();

        $this->assertTrue(
            $this->markerFollowedByWithin($html, 'data-hb-seo-field="meta_title"', 'disabled', 300),
            'the meta_title field should render disabled on a never-saved document',
        );
    }

    /** Substring-window check: is `$needle` present within `$window` chars after `$marker`? */
    private function markerFollowedByWithin(string $html, string $marker, string $needle, int $window): bool
    {
        $pos = strpos($html, $marker);
        if ($pos === false) {
            return false;
        }

        return str_contains(substr($html, $pos, $window), $needle);
    }

    // ── the shared slug mechanism (no second write path) ─────────────────────

    public function test_the_seo_panel_slug_field_shares_the_summary_slug_marker_not_a_second_one(): void
    {
        $html = $this->blankEditorHtml();

        // Exactly two REAL markup instances carry the marker as an HTML attribute: the Summary's
        // slug popup (inspector.blade.php, a raw <div> wrapping the real <input>) and the SEO
        // panel's own URL Slug field (an x-ui.field wrapper) — both sharing the SAME
        // data-hb-post-slug-input contract, not a second write path. (The substring also appears
        // inside the two files' own JS as a CSS selector string, `[data-hb-post-slug-input]` —
        // counting THOSE too would over-count, so this pins the two markup shapes directly
        // instead of a raw substr_count.)
        $this->assertStringContainsString('class="hb-pop hb-post-pop hb-post-slugpop" data-hb-post-slug-input', $html);
        $this->assertSame(
            1,
            substr_count($html, 'class="hb-pop hb-post-pop hb-post-slugpop" data-hb-post-slug-input'),
            'the Summary slug popup should render exactly once',
        );
        $this->assertStringContainsString('data-hb-post-slug-input="data-hb-post-slug-input" data-hb-current-slug', $html);
        $this->assertSame(
            1,
            substr_count($html, 'data-hb-post-slug-input="data-hb-post-slug-input" data-hb-current-slug'),
            'the SEO panel URL Slug field should render exactly once',
        );
        $this->assertStringNotContainsString('data-hb-seo-field="slug"', $html);
    }

    // ── score container + analyze URL template ───────────────────────────────

    public function test_the_panel_carries_a_score_container_and_the_analyze_url_template(): void
    {
        $html = $this->blankEditorHtml();

        $this->assertStringContainsString('data-hb-seo-score', $html);
        $this->assertStringContainsString('data-hb-seo-score-value', $html);
        $this->assertStringContainsString('data-hb-seo-score-rating', $html);
        $this->assertStringContainsString('data-hb-seo-checklist', $html);
        $this->assertStringContainsString(
            'data-hb-seo-analyze-url-template="' . route('heisenberg.editor.seo.analyze', ['post' => '__ID__']) . '"',
            $html,
        );
    }

    public function test_the_unsaved_document_shows_the_save_first_state(): void
    {
        $html = $this->blankEditorHtml();

        $this->assertStringContainsString(
            __('heisenberg::editor.panel_seo_social.score_save_first'),
            $html,
        );
    }

    // ── hbPendingSeo mechanism actually shipped ──────────────────────────────

    public function test_topbar_ships_the_pending_seo_mechanism(): void
    {
        $html = $this->blankEditorHtml();

        $this->assertStringContainsString('hbPendingSeo', $html);
        $this->assertStringContainsString('hb:post-seo-change', $html);
        $this->assertStringContainsString('hb:post-seo-rejected', $html);
    }

    // ── seeded values actually render (existing post) ────────────────────────

    public function test_an_existing_posts_seo_meta_seeds_the_panel(): void
    {
        $post = Post::create(['title_en' => 'Seeded Post', 'status' => 'draft', 'slug' => 'seeded-post']);
        SeoMeta::query()->create([
            'able_type' => $post->getMorphClass(),
            'able_id' => $post->getKey(),
            'meta_title_en' => 'Seeded Meta Title',
            'meta_description_en' => 'Seeded meta description',
            'og_image' => 'https://example.com/seed.jpg',
            'robots' => 'noindex, nofollow',
            'in_sitemap' => false,
        ]);

        $html = $this->get("/editor/{$post->id}")->getContent();

        $this->assertStringContainsString('Seeded Meta Title', $html);
        $this->assertStringContainsString('Seeded meta description', $html);
        $this->assertStringContainsString('https://example.com/seed.jpg', $html);
        $this->assertStringContainsString('seeded-post', $html);
        // robots: 'noindex, nofollow' -> neither toggle should render "checked".
        $this->assertFalse($this->markerFollowedByWithin($html, 'data-hb-seo-field="robots_index"', 'checked', 300));
        $this->assertFalse($this->markerFollowedByWithin($html, 'data-hb-seo-field="robots_follow"', 'checked', 300));
    }

    public function test_seo_fields_are_enabled_for_an_existing_post(): void
    {
        $post = Post::create(['title_en' => 'Existing Post', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->getContent();

        $this->assertFalse($this->markerFollowedByWithin($html, 'data-hb-seo-field="meta_title"', 'disabled', 300));
    }

    // ── checklist row color/size (owner-reported: font too big, icons black) ─

    public function test_the_warn_check_icon_uses_the_warning_token_not_a_fixed_hex(): void
    {
        $html = $this->blankEditorHtml();

        $this->assertStringContainsString('.hb-statuscheckrow__icon--warn { color: var(--hb-warning', $html);
        $this->assertStringNotContainsString('.hb-statuscheckrow__icon--warn { color: #8A5A00', $html);
    }

    public function test_the_check_row_prototypes_are_not_inside_an_inert_template_element(): void
    {
        $html = $this->blankEditorHtml();

        // ui/status-check-row emits its stylesheet via @once, at its FIRST render — which is the
        // prototype block below. A <template>'s content is inert, so emitting there put the
        // .hb-statuscheckrow__icon--* color rules somewhere the browser never applies them and
        // every status icon rendered black. The prototypes must live in a plain hidden element.
        $this->assertStringContainsString('data-hb-seo-check-prototypes', $html);
        $this->assertStringNotContainsString('<template data-hb-seo-check-prototypes', $html);

        $stylePos = strpos($html, '.hb-statuscheckrow__icon--pass { color:');
        $this->assertNotFalse($stylePos, 'the status-check-row stylesheet never reached the page');
        $templatePos = strpos($html, '<template');
        if ($templatePos !== false) {
            $this->assertLessThan(
                $templatePos,
                $stylePos,
                'the icon color rules must be emitted before/outside any <template>, or they are inert',
            );
        }
    }

    public function test_the_checklist_scopes_a_smaller_row_font_to_the_seo_panel_only(): void
    {
        $html = $this->blankEditorHtml();

        $this->assertStringContainsString('.hb-seo-checklist .hb-statuscheckrow__text { font-size: var(--hb-fs-xs', $html);
    }

    public function test_the_checklist_script_dedupes_identical_group_status_message_rows(): void
    {
        $html = $this->blankEditorHtml();

        $this->assertStringContainsString('seenKeys', $html);
        $this->assertStringContainsString("group + ' ' + check.status + ' ' + check.message", $html);
    }
}
