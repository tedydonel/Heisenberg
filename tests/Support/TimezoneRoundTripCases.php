<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Support;

use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Owner-reported bug (2026-08-12): "when I set a schedule post for eg when I set 11:13am,
 * it shows when I close the date picker 12:13pm" — a +1h drift on the owner's Africa/Douala
 * (UTC+1) machine. Root cause: PostController::payload() echoed published_at/scheduled_at
 * with `toIso8601String()` (an offset-bearing string), while EditorController::show() seeds
 * the SAME page's date-pickers with a naive `Y-m-d\TH:i` string. post-meta-live-script.blade.php's
 * `hb:post-saved` handler fed that offset-bearing echo through `new Date(iso)` and read back
 * BROWSER-local getters — reinterpreting an app-timezone wall clock through whatever zone the
 * browser happens to be in, which is exactly what differs when config('app.timezone') != the
 * browser's own zone.
 *
 * Fixed by making payload() echo the same naive `Y-m-d\TH:i` app-timezone wall clock the page
 * seed already used (PostController's own TIMEZONE docblock), and by rewriting the client's
 * `toDatetimeLocal`/`formatSummaryDate` to never round-trip through an ISO/UTC `Date` parse.
 *
 * A trait rather than an abstract TestCase — PHPUnit's directory-based test discovery here
 * (phpunit.xml.dist: `failOnWarning="true"`) does not reliably pick up multiple concrete
 * TestCase subclasses sharing one file, and an abstract TestCase subclass on its own emits a
 * runner warning that fails the whole suite under that flag. Two concrete, single-class-per-file
 * tests (TimezoneRoundTripUtcTest, TimezoneRoundTripDoualaTest) `use` this instead, each
 * supplying its own app.timezone via getEnvironmentSetUp() — so neither the app.timezone ==
 * UTC no-op case nor the +1h-drift case can hide the bug.
 */
trait TimezoneRoundTripCases
{
    private function registry(): BlockRegistryService
    {
        return app(BlockRegistryService::class);
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function envelope(array $blocks, array $overrides = []): array
    {
        return array_merge([
            'schemaVersion' => 1,
            'registryHash' => $this->registry()->computeHash(),
            'computedStyles' => '',
            'autosave' => false,
            'blocks' => $blocks,
            'title_en' => 'A Test Post',
            'locale' => 'en',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function block(string $name, array $attributes = [], array $overrides = []): array
    {
        return array_merge([
            'id' => 'b1',
            'name' => $name,
            'schemaVersion' => '1.0.0',
            'attributes' => $attributes,
            'supports' => [],
            'innerBlocks' => [],
        ], $overrides);
    }

    /** @return array{id: mixed, version: mixed} */
    private function createDraft(Authenticatable $actor, string $title = 'Timezone Round Trip Test'): array
    {
        $this->actingAs($actor);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => $title],
        ));
        $store->assertCreated();

        return ['id' => $store->json('post.id'), 'version' => $store->json('post.content_version')];
    }

    public function test_scheduled_at_round_trip_preserves_the_typed_wall_clock(): void
    {
        $editor = new TimezoneRoundTripFakeActor(1, 'editor');
        $post = $this->createDraft($editor);

        $wallClock = '2026-09-01T11:13';

        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Timezone Round Trip Test', 'content_version' => $post['version'],
                'status' => 'scheduled', 'scheduled_at' => $wallClock,
            ],
        ));

        $response->assertOk();

        // The save's own echo must hand back the EXACT wall clock that was sent — not a
        // UTC/offset-shifted reinterpretation of it.
        $this->assertSame($wallClock, $response->json('post.scheduled_at'));

        $fresh = Post::find($post['id']);
        $this->assertSame($wallClock, $fresh->scheduled_at->format('Y-m-d\TH:i'));

        // EditorController::show()'s seed for the SAME post must match too — the picker's
        // hidden value and the Summary row's data attribute both carry the naive string.
        $page = $this->get("/editor/{$post['id']}");
        $page->assertOk();
        $page->assertSee('data-hb-current-scheduled-at="' . $wallClock . '"', false);
    }

    public function test_published_at_round_trip_preserves_the_typed_wall_clock_when_backdated(): void
    {
        $editor = new TimezoneRoundTripFakeActor(2, 'editor');
        $post = $this->createDraft($editor);

        $wallClock = '2020-01-15T09:30';

        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Timezone Round Trip Test', 'content_version' => $post['version'],
                'published_at' => $wallClock,
            ],
        ));

        $response->assertOk();
        $this->assertSame($wallClock, $response->json('post.published_at'));

        $fresh = Post::find($post['id']);
        $this->assertSame($wallClock, $fresh->published_at->format('Y-m-d\TH:i'));

        $page = $this->get("/editor/{$post['id']}");
        $page->assertOk();
        $page->assertSee('data-hb-current-published-at="' . $wallClock . '"', false);
    }
}

/**
 * A minimal, real (non-GuestActor) Authenticatable stand-in — same shape as
 * LifecycleTransitionsTest's own fixture, kept separate so it has no
 * load-order dependency on that file.
 */
final class TimezoneRoundTripFakeActor implements Authenticatable
{
    public function __construct(public int $id, public string $role)
    {
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return null;
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
    }

    public function getRememberTokenName()
    {
        return '';
    }
}
