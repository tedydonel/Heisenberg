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
 * Container styling must survive the round trip to the PUBLISHED page, not just paint on
 * the canvas. Written for a reported bug: a group's Fill appeared to work in the editor
 * and did nothing when rendered, and Stroke did nothing anywhere.
 *
 * Both had real causes. Fill wrote `color.text` for every block, which on a frame that
 * paints no text of its own set an invisible property (it now writes `color.background`
 * for containers). Stroke never mounted because no container declared `border`, and
 * `border-radius` had no capability behind it at all.
 *
 * Saves a container tree through the real POST /editor/posts and asserts the preview
 * paints every declaration — the container counterpart of CanvasRenderParityTest.
 */
class ContainerRenderParityTest extends TestCase
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

    private function publish(array $blocks): string
    {
        $response = $this->postJson('/editor/posts', [
            'schemaVersion' => 1,
            'registryHash' => app(BlockRegistryService::class)->computeHash(),
            'title_en' => 'Container parity',
            'locale' => 'en',
            'blocks' => $blocks,
        ])->assertCreated();

        return $this->get('/editor/' . $response->json('post.id') . '/preview')->assertOk()->getContent();
    }

    public function test_a_containers_fill_and_stroke_paint_on_the_published_page(): void
    {
        // Exactly what the inspector writes: Fill composites into color.background (with its
        // raw layer stack alongside), Stroke's per-side widths + the scalar colour, and the
        // per-corner radius from Appearance.
        $html = $this->publish([[
            'id' => 'g1',
            'name' => 'heisenberg/group',
            'schemaVersion' => '1.0.0',
            'attributes' => [],
            'supports' => [
                'color' => [
                    'background' => '#22aa55',
                    'layers' => [['color' => '#22AA55', 'opacity' => '100']],
                ],
                'border' => [
                    'width' => ['top' => '3px', 'right' => '3px', 'bottom' => '3px', 'left' => '3px'],
                    'color' => '#ff0000',
                    'radius' => ['topLeft' => '8px', 'topRight' => '8px'],
                ],
            ],
            'innerBlocks' => [[
                'id' => 'p1',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Nested child'],
                'supports' => [],
                'innerBlocks' => [],
            ]],
        ]]);

        // Fill → the container's background variable (the bug: this used to be color.text).
        $this->assertStringContainsString('--hb-group-bg: #22aa55', $html);

        // Stroke → all four sides from the per-side widths and the ONE scalar colour, plus
        // the style default that makes a width visible without a control for it.
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $this->assertStringContainsString("--hb-border-{$side}-width: 3px", $html);
            $this->assertStringContainsString("--hb-border-{$side}-color: #ff0000", $html);
            $this->assertStringContainsString("--hb-border-{$side}-style: solid", $html);
        }

        // Corners → the capability SupportsStyle gained for them.
        $this->assertStringContainsString('--hb-border-radius-tl: 8px', $html);
        $this->assertStringContainsString('--hb-border-radius-tr: 8px', $html);

        // The stylesheet that consumes all of the above must be on the page, or every
        // variable above is inert — which is what "works in canvas, not when rendered"
        // would look like.
        $this->assertStringContainsString('[data-block-id].hb-supports', $html);
        $this->assertStringContainsString('border-top-left-radius: var(--hb-border-radius-tl', $html);
        $this->assertStringContainsString('background: var(--hb-group-bg', $html);

        // And the nested child still renders through its own contract.
        $this->assertStringContainsString('Nested child', $html);
        $this->assertStringContainsString('hb-block-paragraph', $html);
    }

    public function test_a_columns_child_carries_its_own_styling_to_the_published_page(): void
    {
        // Reported: a column's styling worked on the canvas and none of it survived render.
        // A column is only ever a NESTED child, so this exercises the path a top-level block
        // never touches: contract variables emitted at depth, and that column.css (which
        // consumes them) actually reaches the page.
        $html = $this->publish([[
            'id' => 'cols1',
            'name' => 'heisenberg/columns',
            'schemaVersion' => '1.0.0',
            'attributes' => [],
            'supports' => ['color' => ['background' => '#eeeeee']],
            'innerBlocks' => [[
                'id' => 'col1',
                'name' => 'heisenberg/column',
                'schemaVersion' => '1.0.0',
                'attributes' => [],
                'supports' => [
                    'color' => ['background' => '#22aa55'],
                    'spacing' => ['padding' => ['top' => '10px']],
                    'border' => ['width' => ['top' => '3px'], 'color' => '#ff0000'],
                    'size' => ['minHeight' => '120px'],
                ],
                'innerBlocks' => [[
                    'id' => 'p1',
                    'name' => 'heisenberg/paragraph',
                    'schemaVersion' => '1.0.0',
                    'attributes' => ['content' => 'In a column'],
                    'supports' => [],
                    'innerBlocks' => [],
                ]],
            ]],
        ]]);

        // The nested column's own variables must be emitted on ITS root…
        $this->assertStringContainsString('--hb-column-bg: #22aa55', $html);
        $this->assertStringContainsString('--hb-column-pt: 10px', $html);
        $this->assertStringContainsString('--hb-column-minh: 120px', $html);
        $this->assertStringContainsString('--hb-border-top-width: 3px', $html);

        // …and the stylesheet that consumes them must be on the page, or every one of those
        // variables is inert — which is exactly "works in canvas, nothing on render".
        $this->assertStringContainsString('.hb-block-column', $html);
        $this->assertStringContainsString('background: var(--hb-column-bg', $html);
        $this->assertStringContainsString('min-height: var(--hb-column-minh', $html);
        $this->assertStringContainsString('var(--hb-column-pt', $html);

        $this->assertStringContainsString('In a column', $html);
    }

    public function test_a_containers_border_does_not_bleed_onto_its_children(): void
    {
        // Reported: setting border thickness on a group/column drew the border on every
        // child too. CSS custom properties INHERIT, and every block root carries
        // `.hb-supports`, so a child with no border of its own resolved its parent's
        // `--hb-border-*` values. The capability sheet must neutralise them per element.
        $html = $this->publish([[
            'id' => 'g1',
            'name' => 'heisenberg/group',
            'schemaVersion' => '1.0.0',
            'attributes' => [],
            'supports' => ['border' => [
                'width' => ['top' => '4px', 'right' => '4px', 'bottom' => '4px', 'left' => '4px'],
                'color' => '#ff0000',
                'radius' => ['topLeft' => '9px'],
            ]],
            'innerBlocks' => [[
                'id' => 'p1',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'No border here'],
                'supports' => [],
                'innerBlocks' => [],
            ]],
        ]]);

        // The child's own root must carry none of the parent's border variables…
        $this->assertMatchesRegularExpression('/data-block-id="p1"[^>]*style="([^"]*)"/', $html);
        preg_match('/data-block-id="p1"[^>]*style="([^"]*)"/', $html, $m);
        $this->assertStringNotContainsString('--hb-border', $m[1], 'a child must not be given its parent’s border vars');

        // …and the capability sheet must RESET them on every block root, so the child cannot
        // inherit the parent's value through the cascade.
        $supports = $this->get('/heisenberg-assets/editor-supports.css')->assertOk()->getContent();
        foreach (['--hb-border-top-width', '--hb-border-top-color', '--hb-border-radius-tl', '--hb-shadow', '--hb-opacity'] as $var) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($var, '/') . '\s*:\s*[^;]+;/',
                $supports,
                "{$var} must be reset on the block root so it cannot inherit",
            );
        }
    }

    public function test_the_topbar_preview_button_path_renders_container_styling(): void
    {
        // The Preview BUTTON on an unsaved document does not use the saved-post route the
        // other tests here exercise: it POSTs the live doc to /editor/preview (session) and
        // opens GET /editor/preview. Pinned separately because it is the path a user
        // actually clicks, and it renders from the session copy rather than the DB.
        $doc = [
            'title' => 'Session preview',
            'blocks' => [[
                'id' => 'cols1',
                'name' => 'heisenberg/columns',
                'schemaVersion' => '1.0.0',
                'attributes' => [],
                'supports' => ['color' => ['background' => '#111133']],
                'innerBlocks' => [[
                    'id' => 'col1',
                    'name' => 'heisenberg/column',
                    'schemaVersion' => '1.0.0',
                    'attributes' => [],
                    'supports' => [
                        'color' => ['background' => '#22aa55'],
                        'spacing' => ['padding' => ['top' => '24px']],
                        'border' => ['width' => ['top' => '5px'], 'color' => '#ff0000'],
                        'size' => ['minHeight' => '90px'],
                    ],
                    'innerBlocks' => [],
                ]],
            ]],
        ];

        $this->postJson('/editor/preview', $doc)->assertOk()->assertJson(['stored' => true]);
        $html = $this->get('/editor/preview')->assertOk()->getContent();

        foreach ([
            '--hb-columns-bg: #111133',
            '--hb-column-bg: #22aa55',
            '--hb-column-pt: 24px',
            '--hb-column-minh: 90px',
            '--hb-border-top-width: 5px',
            '--hb-border-top-color: #ff0000',
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $html, "{$declaration} must survive the session preview");
        }

        // The consuming stylesheets must both be on the page.
        $this->assertStringContainsString('background: var(--hb-column-bg', $html);
        $this->assertStringContainsString('[data-block-id].hb-supports', $html);
    }

    public function test_layer_state_never_leaks_into_the_published_markup(): void
    {
        // `layers` is inspector editing state that rides alongside the scalar. It must not
        // reach the page — no variable sources it, and it is not a CSS value.
        $html = $this->publish([[
            'id' => 'g2',
            'name' => 'heisenberg/group',
            'schemaVersion' => '1.0.0',
            'attributes' => [],
            'supports' => ['color' => ['background' => '#123456', 'layers' => [['color' => '#123456', 'opacity' => '100']]]],
            'innerBlocks' => [],
        ]]);

        $this->assertStringContainsString('--hb-group-bg: #123456', $html);

        // Scoped to the block's own style attribute — the page as a whole legitimately says
        // "layers" elsewhere (the editor chrome's Layers panel script is on the preview too),
        // and asserting against the whole document would pass or fail for unrelated reasons.
        $this->assertMatchesRegularExpression('/data-block-id="g2"[^>]*style="([^"]*)"/', $html);
        preg_match('/data-block-id="g2"[^>]*style="([^"]*)"/', $html, $m);
        $this->assertStringNotContainsString('layers', $m[1], 'inspector layer state must not reach the page');
    }

    public function test_a_columns_width_and_height_reach_the_published_page(): void
    {
        // 2026-08-07: column declared only min/max sizes — a cell could never be given an
        // explicit width or height from the inspector. The width folds into the flex-item
        // contract (flex-basis + a width-derived max-width cap) so a set width is exact when
        // the row has room and still shrinks when it overflows; unset stays equal-shares.
        $html = $this->publish([[
            'id' => 'cw1',
            'name' => 'heisenberg/columns',
            'schemaVersion' => '1.0.0',
            'attributes' => ['columns' => 2],
            'supports' => [],
            'innerBlocks' => [
                [
                    'id' => 'cw2',
                    'name' => 'heisenberg/column',
                    'schemaVersion' => '1.0.0',
                    'attributes' => [],
                    'supports' => ['size' => ['width' => '120px', 'height' => '100px']],
                    'innerBlocks' => [],
                ],
                [
                    'id' => 'cw3',
                    'name' => 'heisenberg/column',
                    'schemaVersion' => '1.0.0',
                    'attributes' => [],
                    'supports' => [],
                    'innerBlocks' => [],
                ],
            ],
        ]]);

        $this->assertStringContainsString('--hb-column-w: 120px', $html);
        $this->assertStringContainsString('--hb-column-h: 100px', $html);

        // The stylesheet must consume both: basis + cap for width, plain height.
        $this->assertStringContainsString('flex: 1 1 var(--hb-column-w, 0)', $html);
        $this->assertStringContainsString('max-width: var(--hb-column-maxw, var(--hb-column-w, none))', $html);
        $this->assertStringContainsString('height: var(--hb-column-h, auto)', $html);
    }

    public function test_a_list_renders_one_li_per_line_via_the_text_lines_node(): void
    {
        // 2026-08-08: the list's content used to land in ONE <li> (only the first line got a
        // marker). The `text-lines` template node splits the newline-delimited attribute into
        // real per-item markup; blank lines drop, text is escaped, and the published page and
        // the canvas runtime share the same rule.
        $html = $this->publish([[
            'id' => 'ls1',
            'name' => 'heisenberg/list',
            'schemaVersion' => '1.0.0',
            'attributes' => ['content' => "First item\nSecond item\n\n  Third <b>item</b>  ", 'ordered' => true, 'start' => 3],
            'supports' => [],
            'innerBlocks' => [],
        ]]);

        // Both the ul and the ol carry the node (CSS shows one), so each item appears twice.
        foreach ([
            '<li class="hb-block-list__item">First item</li>',
            '<li class="hb-block-list__item">Second item</li>',
            '<li class="hb-block-list__item">Third &lt;b&gt;item&lt;/b&gt;</li>',
        ] as $item) {
            $this->assertSame(2, substr_count($html, $item), $item);
        }
        $this->assertStringContainsString('start="3"', $html);
        $this->assertStringContainsString('hb-list--ordered', $html);
    }
}
