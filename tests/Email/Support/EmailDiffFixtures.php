<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email\Support;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Loads the block-tree fixtures under tests/Fixtures/email-diff/*.json into real Post + Block
 * rows, for the three-way surface comparison (tests/Email/ThreeWaySurfaceDiffTest.php).
 *
 * Each JSON fixture is a plain document: `title_en` (+ optional `title_fr`), `slug`, and a
 * `blocks` array using the SAME shorthand shape EmailRendererTest's own `addBlock()` helper
 * builds by hand (name/attributes/supports/innerBlocks) — `id`/`schemaVersion` are filled in
 * here so the fixture files themselves stay readable. A leaf `attributes.url` block tagged
 * `"_mediaLibraryFixture": true` gets a matching {@see PublicFile} row created automatically
 * (mirroring EmailRendererTest::makeImageFile()) so `EmailRenderer::rewriteImages()` treats it
 * as a real media-library upload and embeds it — every OTHER image `url` (no such tag) is left
 * exactly as authored, e.g. an external CDN URL, which `rewriteImages()` deliberately does not
 * resolve (best-effort, no host network fetch).
 */
final class EmailDiffFixtures
{
    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/email-diff';
    }

    /** @return list<string> fixture names (JSON basenames without extension), sorted. */
    public static function names(): array
    {
        $files = glob(self::directory() . '/*.json') ?: [];
        sort($files);

        return array_map(static fn (string $f): string => basename($f, '.json'), $files);
    }

    /** A tiny (1x1) real GIF, so file reads (size/mime) never fail — same bytes EmailRendererTest uses. */
    public static function tinyImageBytes(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
    }

    /**
     * Load one fixture by name (no `.json`) into a fresh, published `type = 'email'` Post with
     * its block tree persisted. Call `Storage::fake('uploads')` in the caller's setUp() first —
     * this method writes into it for any `_mediaLibraryFixture` image.
     */
    public static function load(string $name): Post
    {
        $path = self::directory() . '/' . $name . '.json';
        if (! is_file($path)) {
            throw new \RuntimeException("No such email-diff fixture: {$name} ({$path})");
        }

        /** @var array<string, mixed> $doc */
        $doc = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $post = Post::create([
            'locale' => 'en',
            'title_en' => (string) ($doc['title_en'] ?? $name),
            'title_fr' => isset($doc['title_fr']) ? (string) $doc['title_fr'] : null,
            'slug' => (string) ($doc['slug'] ?? $name),
            'status' => 'published',
            'published_at' => now(),
        ]);
        $post->type = 'email';
        $post->save();

        $order = 1;
        foreach ((array) ($doc['blocks'] ?? []) as $blockDoc) {
            if (! is_array($blockDoc)) {
                continue;
            }
            $content = self::normalizeNode($blockDoc);
            Block::create([
                'post_id' => $post->id,
                'type' => self::bareName((string) $content['name']),
                'content' => $content,
                'order' => $order++,
            ]);
        }

        return $post->fresh(['blocks']);
    }

    /**
     * Recursively fill in `id`/`schemaVersion`/`supports`/`innerBlocks` defaults, materialize any
     * `_mediaLibraryFixture` image as a real {@see PublicFile}, and strip that fixture-only key
     * before it becomes part of the stored block payload.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function normalizeNode(array $node): array
    {
        $isMediaFixture = (bool) ($node['_mediaLibraryFixture'] ?? false);
        unset($node['_mediaLibraryFixture'], $node['_comment']);

        $attributes = is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
        if ($isMediaFixture && is_string($attributes['url'] ?? null)) {
            self::ensurePublicFileFor($attributes['url']);
        }

        $inner = [];
        foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
            if (is_array($child)) {
                $inner[] = self::normalizeNode($child);
            }
        }

        return [
            'id' => $node['id'] ?? ('b' . Str::random(8)),
            'name' => (string) $node['name'],
            'schemaVersion' => $node['schemaVersion'] ?? '1.0.0',
            'attributes' => $attributes,
            'supports' => is_array($node['supports'] ?? null) ? $node['supports'] : [],
            'innerBlocks' => $inner,
        ];
    }

    private static function bareName(string $name): string
    {
        $pos = strrpos($name, '/');

        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /**
     * Create the PublicFile (+ underlying faked disk files) that `url` resolves to, with variants
     * either side of EmailRenderer::CONTENT_WIDTH (600px) — same shape as
     * EmailRendererTest::makeImageFile(), keyed off $url so two fixtures can each carry their own
     * distinct media-library image without colliding.
     */
    private static function ensurePublicFileFor(string $url): void
    {
        $storedPath = ltrim(parse_url($url, PHP_URL_PATH) ?: $url, '/');
        if (str_starts_with($storedPath, 'uploads/')) {
            $storedPath = substr($storedPath, strlen('uploads/'));
        }

        if (PublicFile::query()->where('stored_path', $storedPath)->exists()) {
            return;
        }

        $dir = dirname($storedPath);
        $base = pathinfo($storedPath, PATHINFO_FILENAME);
        $ext = pathinfo($storedPath, PATHINFO_EXTENSION) ?: 'jpg';
        $smallPath = $dir . '/' . $base . '-small.' . $ext;

        Storage::disk('uploads')->put($storedPath, self::tinyImageBytes());
        Storage::disk('uploads')->put($smallPath, self::tinyImageBytes());

        PublicFile::create([
            'type' => $ext,
            'disk' => 'uploads',
            'stored_path' => $storedPath,
            'original_name' => basename($storedPath),
            'stored_name' => basename($storedPath),
            'mime_type' => 'image/jpeg',
            'size_bytes' => 204800,
            'width' => 1600,
            'height' => 1200,
            'variants' => [
                'small' => ['path' => $smallPath, 'width' => 320, 'height' => 240],
            ],
        ]);
    }
}
