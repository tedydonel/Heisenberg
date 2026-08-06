<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Contracts\RoleGate;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * End-to-end canvas→publish parity: saves a block through the real POST /editor/posts
 * endpoint carrying exactly the supports shape the canvas runtime produces (raw bare
 * numbers, layer stacks, state overrides), then asserts the published preview paints
 * every one of them. Guards the "what you edit is what renders" contract.
 */
class CanvasRenderParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new GenericUser(['id' => 7]));
        $this->app->instance(RoleGate::class, new class implements RoleGate
        {
            public function is(Authenticatable $user, string $tier): bool
            {
                return true;
            }

            public function isAny(Authenticatable $user, array $tiers): bool
            {
                return true;
            }

            public function rolesOf(Authenticatable $user): array
            {
                return ['authors', 'admins'];
            }

            public function systemActor(): ?Authenticatable
            {
                return null;
            }
        });
    }

    public function test_canvas_supports_shape_survives_save_and_paints_on_the_published_preview(): void
    {
        $registryHash = app(BlockRegistryService::class)->computeHash();

        // Exactly what window.hbEditor.buildSavePayload() emits after a real editing session
        // (verified against the jsdom pipeline harness): bare numbers from numeric fields,
        // the composited colour + its layer stack, and a hover-state override.
        $payload = [
            'schemaVersion' => 1,
            'registryHash' => $registryHash,
            'title_en' => 'Parity',
            'locale' => 'en',
            'blocks' => [[
                'id' => 'hb1',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Alpha'],
                'supports' => [
                    'color' => [
                        'text' => '#ff0080',
                        'layers' => [['color' => '#ff0080', 'opacity' => '100']],
                    ],
                    'appearance' => ['opacity' => 37],
                    'typography' => ['fontSize' => '28', 'letterSpacing' => '2', 'textAlign' => 'center'],
                    'spacing' => ['padding' => ['top' => '24']],
                    'position' => ['x' => '15', 'y' => '-8', 'rotation' => '45'],
                    'effects' => ['shadow' => '2px 4px 10px rgba(0, 0, 0, 0.14)'],
                    'states' => ['hover' => ['color' => ['text' => '#0000ff']]],
                ],
                'innerBlocks' => [],
            ]],
        ];

        $save = $this->postJson('/editor/posts', $payload);
        $save->assertStatus(201);
        $postId = $save->json('id') ?? $save->json('post.id');
        $this->assertNotNull($postId, 'save response carries the post id: ' . $save->getContent());

        $html = $this->get("/editor/{$postId}/preview")->assertOk()->getContent();

        // Every canvas-authored declaration must reach the published page, normalized
        // identically to the canvas (px/deg/% for bare numbers).
        foreach ([
            '--hb-paragraph-color: #ff0080',
            '--hb-opacity: 37%',
            '--hb-paragraph-fs: 28px',
            '--hb-letter-spacing: 2px',
            '--hb-text-align: center',
            '--hb-paragraph-pt: 24px',
            '--hb-tx: 15px',
            '--hb-ty: -8px',
            '--hb-rotate: 45deg',
            '--hb-shadow: 2px 4px 10px rgba(0, 0, 0, 0.14)',
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $html, "published preview must paint {$declaration}");
        }

        // The hover-state override compiles to real state CSS.
        $this->assertStringContainsString(':hover', $html);
        $this->assertStringContainsString('#0000ff', $html);
    }
}
