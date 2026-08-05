<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Media;

use Heisenberg\Adapters\PublicFileMediaResolver;
use Heisenberg\Models\PublicFile;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * Coverage for PublicFileMediaResolver (docs/media-library-backend-blueprint.md
 * §7, src/Adapters/PublicFileMediaResolver.php): resolve() must return the real
 * url/srcset/sizes payload for a known `/uploads/...` URL and degrade safely
 * (passthrough URL, null srcset/sizes) for anything it can't match; browse()
 * must return the requested page of records ordered newest-first. No users
 * table is needed here — resolver logic never touches uploaded_by.
 */
class PublicFileMediaResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_resolve_returns_the_records_url_srcset_and_sizes_for_a_known_url(): void
    {
        $file = PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/07/photo.jpg',
            'original_name' => 'photo.jpg',
            'stored_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 204800,
            'width' => 1600,
            'height' => 1200,
            'variants' => [
                'small' => ['path' => 'media/2026/07/photo-small.jpg', 'width' => 320, 'height' => 240, 'size_bytes' => 18000],
                'medium' => ['path' => 'media/2026/07/photo-medium.jpg', 'width' => 768, 'height' => 576, 'size_bytes' => 60000],
            ],
        ]);

        $resolver = new PublicFileMediaResolver();
        $payload = $resolver->resolve('/uploads/media/2026/07/photo.jpg', 'hero');

        $this->assertSame($file->url, $payload['url']);
        $this->assertNotNull($payload['srcset']);
        $this->assertStringContainsString('photo-small.jpg 320w', $payload['srcset']);
        $this->assertStringContainsString('photo-medium.jpg 768w', $payload['srcset']);
        $this->assertSame('100vw', $payload['sizes']); // hero context (PublicFile::CONTEXT_SIZES)
    }

    public function test_resolve_degrades_safely_for_an_unknown_url(): void
    {
        $resolver = new PublicFileMediaResolver();

        $payload = $resolver->resolve('/uploads/media/2026/07/does-not-exist.jpg', 'article');

        $this->assertSame('/uploads/media/2026/07/does-not-exist.jpg', $payload['url'], 'unmatched URLs pass through unchanged');
        $this->assertNull($payload['srcset']);
        $this->assertNull($payload['sizes']);
    }

    public function test_resolve_degrades_safely_for_a_non_uploads_url(): void
    {
        $resolver = new PublicFileMediaResolver();

        $payload = $resolver->resolve('https://example.com/not-a-library-file.jpg', 'card');

        $this->assertSame('https://example.com/not-a-library-file.jpg', $payload['url']);
        $this->assertNull($payload['srcset']);
        $this->assertNull($payload['sizes']);
    }

    public function test_browse_returns_the_requested_page_ordered_newest_first(): void
    {
        foreach (range(1, 5) as $i) {
            // 'created_at' is deliberately not fillable (mass-assignment
            // protection, blueprint §12), so it has to be forced in after
            // create() to get distinct, ordered timestamps instead of every
            // row landing on the same "now" and leaving order() to an
            // unspecified tie-break.
            $file = PublicFile::create([
                'type' => 'jpg',
                'disk' => 'uploads',
                'stored_path' => "media/2026/07/photo{$i}.jpg",
                'original_name' => "photo{$i}.jpg",
                'stored_name' => "photo{$i}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
            ]);
            $file->forceFill(['created_at' => now()->addMinutes($i)])->save();
        }

        $resolver = new PublicFileMediaResolver();

        $firstPage = collect($resolver->browse(1, 2));
        $this->assertCount(2, $firstPage);
        $this->assertSame('photo5.jpg', $firstPage->first()->original_name, 'newest first');

        $secondPage = collect($resolver->browse(2, 2));
        $this->assertCount(2, $secondPage);
        $this->assertSame('photo3.jpg', $secondPage->first()->original_name);

        $this->assertEmpty(collect($resolver->browse(10, 2)), 'a page past the end is empty, not an error');
    }
}
