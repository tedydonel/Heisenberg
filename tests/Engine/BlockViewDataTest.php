<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Support\BlockViewData;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/**
 * BlockViewData::blocksCss() used to silently skip a contract whose declared
 * `style.css` file is missing on disk (review §4.11) — the block would render
 * unstyled with zero signal anywhere. It must now log a dev-time warning while
 * leaving the skip behavior itself unchanged (still no CSS chunk emitted for
 * the missing file, and present files are unaffected).
 */
class BlockViewDataTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-viewdata-' . uniqid('', true);
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        parent::tearDown();
    }

    private function registry(): BlockRegistryService
    {
        return new BlockRegistryService(new BlockContractValidator('heisenberg'), $this->root);
    }

    /** Write a minimal valid contract; only writes the CSS file when asked to. */
    private function writeContract(string $slug, bool $withCss): void
    {
        $dir = $this->root . DIRECTORY_SEPARATOR . $slug;
        mkdir($dir, 0775, true);

        $contract = [
            '$schema' => './schema.md',
            'apiVersion' => 1,
            'name' => "heisenberg/{$slug}",
            'title' => "heisenberg::blocks.{$slug}.title",
            'category' => 'text',
            'icon' => 'pilcrow',
            'description' => "heisenberg::blocks.{$slug}.description",
            'keywords' => [$slug],
            'version' => '1.0.0',
            'attributes' => [
                'content' => ['type' => 'rich-text', 'default' => '', 'sanitize' => 'rich-text-block'],
            ],
            'supports' => [],
            'style' => ['css' => "./{$slug}.css", 'className' => "hb-block-{$slug}"],
            'render' => [
                'template' => [
                    'tag' => 'p',
                    'class' => "hb-block-{$slug}",
                    'children' => [['type' => 'rich-text', 'attribute' => 'content']],
                ],
                'publicPartial' => "blocks.{$slug}",
                'script' => null,
            ],
            'innerBlocks' => ['enabled' => false],
            'serialization' => ['mode' => 'json', 'saveAttributes' => true, 'saveSupports' => true, 'saveInnerBlocks' => true, 'migrations' => []],
            'security' => ['richText' => 'inline-basic', 'allowRawHtml' => false, 'allowCustomCss' => false],
        ];

        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . "{$slug}.json",
            json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if ($withCss) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . "{$slug}.css", ".hb-block-{$slug} { color: red; }");
        }
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_missing_css_file_is_skipped_but_logged(): void
    {
        $this->writeContract('haslogged', false); // declares ./haslogged.css — never written to disk

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'heisenberg/haslogged')
                && str_contains($message, 'does not exist'));

        $css = BlockViewData::blocksCss($this->registry());

        // blocksCss now always prepends the shared capability stylesheet (TODO 7.1) — nothing
        // else loads SupportsStyle into the editor, so every capability it implements was
        // unreachable in the canvas. The assertion is therefore "no chunk for THIS block",
        // not "no output at all".
        $this->assertStringNotContainsString('haslogged', $css, 'skip behavior must be unchanged: no chunk for a missing CSS file');
        $this->assertStringContainsString('.hb-supports', $css, 'the capability sheet is always present');
    }

    public function test_present_css_file_is_included_and_not_logged(): void
    {
        $this->writeContract('haspresent', true);

        Log::shouldReceive('warning')->never();

        $css = BlockViewData::blocksCss($this->registry());

        $this->assertStringContainsString('hb-block-haspresent', $css);
    }
}
