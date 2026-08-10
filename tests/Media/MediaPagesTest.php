<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Media;

use Heisenberg\Models\PublicFile;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * The two page-level contracts the 2026-08-10 blueprint audit caught broken:
 *
 * 1. `GET /media` without a JSON Accept header promises the server-rendered
 *    grid (blueprint §10) — but the view never existed, so it was a hard 500
 *    since the day the controller shipped. No test hit the HTML branch, which
 *    is exactly how it stayed invisible.
 * 2. `GET /uploads/{path}` (EditorController::servedUpload) is a dev-only
 *    stand-in for the web server's static file handling — but nothing gated
 *    it, so a production mount would have silently served the whole uploads
 *    disk through PHP, against the blueprint's no-PHP-read-path invariant.
 */
class MediaPagesTest extends TestCase
{
    use RefreshDatabase;

    private function fileRow(string $name = 'grid-photo.jpg'): PublicFile
    {
        return PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/08/' . uniqid('', true) . '.jpg',
            'original_name' => $name,
            'stored_name' => $name,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ]);
    }

    public function test_media_index_renders_the_html_grid_without_a_json_accept_header(): void
    {
        $this->app['env'] = 'local'; // the guest bypass authorizes viewAny, same as the JSON branch
        $this->fileRow('holiday-hero.jpg');

        $page = $this->get('/media');

        $page->assertOk();
        $html = (string) $page->getContent();
        $this->assertStringContainsString('holiday-hero.jpg', $html);
        $this->assertStringContainsString('hb-media-page__grid', $html);
        $this->assertStringContainsString('name="search"', $html);
    }

    public function test_media_index_html_branch_still_authorizes(): void
    {
        // Default testing env: no local bypass, no user — the HTML branch must
        // deny exactly like the JSON one does.
        $this->fileRow();

        $this->get('/media')->assertForbidden();
    }

    public function test_served_upload_works_in_local_dev(): void
    {
        $this->app['env'] = 'local';
        Storage::fake('uploads');
        Storage::disk('uploads')->put('media/2026/08/dev.txt', 'dev bytes');

        $response = $this->get('/uploads/media/2026/08/dev.txt');

        $response->assertOk();
        $this->assertSame('dev bytes', $response->getContent());
    }

    public function test_served_upload_is_a_404_outside_local_and_testing(): void
    {
        Storage::fake('uploads');
        Storage::disk('uploads')->put('media/2026/08/prod.txt', 'secret bytes');

        $this->app['env'] = 'production';
        $response = $this->get('/uploads/media/2026/08/prod.txt');

        $response->assertNotFound();
        $this->assertStringNotContainsString('secret bytes', (string) $response->getContent());
    }
}
