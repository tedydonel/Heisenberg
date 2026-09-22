<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Support\EmailSupports;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

/**
 * The inspector and the email surface have to be the SAME contract: what the Style tab offers
 * on an email document, what the email template reads, and what the sent markup carries.
 *
 * Every test here pins a defect that shipped: inspector values that never reached the mail,
 * sibling blocks overwriting each other's values, and a Style tab offering controls no email
 * template had a slot for.
 */
class EmailInspectorParityTest extends TestCase
{
    use RefreshDatabase;

    private function email(): Post
    {
        $post = Post::create(['title_en' => 'Parity', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        return $post;
    }

    /** @return array<string, mixed> */
    private function block(string $id, string $slug, array $attributes, array $supports = [], array $inner = []): array
    {
        return [
            'id' => $id,
            'name' => 'heisenberg/' . $slug,
            'schemaVersion' => '1.0.0',
            'attributes' => $attributes,
            'supports' => $supports,
            'innerBlocks' => $inner,
        ];
    }

    private function render(Post $post, array ...$blocks): string
    {
        foreach ($blocks as $order => $content) {
            Block::create([
                'post_id' => $post->id,
                'type' => substr($content['name'], strrpos($content['name'], '/') + 1),
                'content' => $content,
                'order' => $order,
            ]);
        }

        return $this->app->make(EmailRenderer::class)->render($post->fresh(), 'en', true)->html;
    }

    /** The cell that directly contains $needle's text — its own `style` attribute. */
    private function styleOfCellContaining(string $html, string $needle): string
    {
        $this->assertSame(1, preg_match('/<td([^>]*)>' . preg_quote($needle, '/') . '</', $html, $m), "No cell directly contains '{$needle}'.");
        $this->assertSame(1, preg_match('/style="([^"]*)"/', $m[1], $style));

        return $style[1];
    }

    public function test_inspector_values_reach_the_sent_markup(): void
    {
        $html = $this->render($this->email(), $this->block('p1', 'paragraph', ['content' => 'STYLED'], [
            'color' => ['text' => '#112233', 'background' => '#ff0000'],
            'spacing' => ['padding' => ['top' => '40px', 'left' => '8px'], 'margin' => ['bottom' => '32px']],
            'typography' => ['textAlign' => 'center', 'fontWeight' => '700', 'fontSize' => '22px', 'letterSpacing' => '2px'],
        ]));

        $style = $this->styleOfCellContaining($html, 'STYLED');
        $this->assertStringContainsString('padding: 40px 0 0 8px', $style);
        $this->assertStringContainsString('background-color: #ff0000', $style);
        $this->assertStringContainsString('color: #112233', $style);
        $this->assertStringContainsString('text-align: center', $style);
        $this->assertStringContainsString('font-weight: 700', $style);
        $this->assertStringContainsString('font-size: 22px', $style);
        $this->assertStringContainsString('letter-spacing: 2px', $style);
        // margin rides the OUTER cell, so the background never bleeds into it
        $this->assertStringContainsString('padding: 0 0 32px 0', $html);
    }

    public function test_sibling_blocks_in_a_container_keep_their_own_values(): void
    {
        $html = $this->render($this->email(), $this->block('g', 'group', [], ['color' => ['background' => '#eeeeee']], [
            $this->block('r', 'paragraph', ['content' => 'RED'], ['color' => ['text' => '#ff0000']]),
            $this->block('b', 'paragraph', ['content' => 'BLUE'], ['color' => ['text' => '#0000ff']]),
            $this->block('d', 'paragraph', ['content' => 'PLAIN']),
        ]));

        $this->assertStringContainsString('color: #ff0000', $this->styleOfCellContaining($html, 'RED'));
        $this->assertStringContainsString('color: #0000ff', $this->styleOfCellContaining($html, 'BLUE'));
        $this->assertStringContainsString('color: #0a0a0a', $this->styleOfCellContaining($html, 'PLAIN'));
        $this->assertStringContainsString('background-color: #eeeeee', $html);
    }

    public function test_a_containers_shared_variables_do_not_reach_its_children(): void
    {
        // `--hb-border-*` and `--hb-text-align` are the SAME names on every contract.
        $html = $this->render($this->email(), $this->block('g', 'group', [], ['border' => ['width' => ['top' => '3px'], 'color' => '#00ff00']], [
            $this->block('q', 'quote', ['content' => 'QUOTED', 'citation' => 'c']),
        ]));

        $this->assertStringContainsString('border-top: 3px solid #00ff00', $html);
        // the quote keeps its own 4px rule and takes none of the group's border
        $this->assertSame(1, preg_match('/<td style="([^"]*)">\s*<p [^>]*>QUOTED/', $html, $m));
        $this->assertStringContainsString('border-top: 0 solid', $m[1]);
        $this->assertStringContainsString('border-left: 4px solid #0a0a0a', $m[1]);
    }

    /**
     * A text block's "vertical rhythm" (its own bottom-margin-as-padding default — email has no
     * reliable CSS margin, so top-level blocks space themselves this way) only makes sense for a
     * block sitting on the document root. Nested inside a container, the container's own `gap` is
     * the spacing mechanism (exactly mirroring the web canvas, where flex `gap` spaces siblings
     * and a child's own margin defaults to 0) — so the SAME block, nested, must not ALSO add its
     * own rhythm on top of the container's padding/gap. This is the "padding doesn't respect,
     * margin does" bug: the container's own explicit padding/margin always matched (it goes
     * through the identical code path on both surfaces); the invisible, unconditional rhythm
     * default did not exist on the web canvas at all, so nesting always grew extra, uncancellable
     * space in the email that had no web equivalent to compare against.
     */
    public function test_a_nested_text_blocks_own_rhythm_default_is_suppressed(): void
    {
        $html = $this->render($this->email(), $this->block('g', 'group', [], [
            'spacing' => ['padding' => ['top' => '5px', 'right' => '12px', 'bottom' => '5px', 'left' => '12px']],
        ], [
            $this->block('h', 'heading', ['content' => 'PILL', 'level' => 2]),
        ]));
        $flat = (string) preg_replace('/>\s+</', '><', $html);

        $this->assertStringContainsString('padding: 5px 12px 5px 12px', $flat);
        // the heading's own margin cell carries none of its usual 12px bottom rhythm
        $this->assertMatchesRegularExpression('/<td style="padding: 0 0 0 0"><table[^<]*<tr><td[^>]*><h2/', $flat);
    }

    public function test_a_top_level_text_blocks_own_rhythm_default_is_unaffected(): void
    {
        $html = $this->render($this->email(), $this->block('h', 'heading', ['content' => 'TOP', 'level' => 2]));

        $this->assertStringContainsString('<td style="padding: 0 0 12px 0"><table', $html);
    }

    /** An explicit margin on a NESTED block still wins — only the invisible default is suppressed. */
    public function test_an_explicit_margin_on_a_nested_block_still_applies(): void
    {
        $html = $this->render($this->email(), $this->block('g', 'group', [], [], [
            $this->block('h', 'heading', ['content' => 'PILL', 'level' => 2], ['spacing' => ['margin' => ['bottom' => '30px']]]),
        ]));

        $this->assertStringContainsString('padding: 0 0 30px 0', $html);
    }

    public function test_a_quoted_font_family_survives_intact(): void
    {
        // `&#039;` carries its own `;` — the custom-property strip used to split on it.
        $html = $this->render(
            $this->email(),
            $this->block('q', 'quote', ['content' => 'Q', 'citation' => 'c']),
            $this->block('p', 'paragraph', ['content' => 'P'], ['typography' => ['fontFamily' => 'Press Start 2P']]),
        );

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString("Georgia, 'Times New Roman', serif", $decoded);
        $this->assertStringContainsString('font-family: "Press Start 2P";', $decoded);
    }

    public function test_layout_alignment_follows_flex_axes(): void
    {
        $group = fn (array $layout): string => $this->render($this->email(), $this->block('g', 'group', [], ['layout' => $layout], [
            $this->block('p', 'paragraph', ['content' => 'CHILD']),
        ]));

        // column (the default): justify is the vertical main axis (valign on the box), align the
        // horizontal cross axis, which positions each CHILD — flexbox never aligns text with it
        $column = $group(['justify' => 'center', 'align' => 'end']);
        $this->assertStringContainsString('<td valign="middle" style="padding: 0 0 0 0; height: auto', $column);
        $this->assertStringContainsString('<td align="right"><table', $column);
        // row: the axes swap
        $this->assertStringContainsString('<td align="center" valign="bottom" style="padding: 0 0 0 0; height: auto', $group(['direction' => 'row', 'justify' => 'center', 'align' => 'end']));
        // nothing set: no attribute at all, the cell keeps inheriting
        $this->assertStringContainsString('<td style="padding: 0 0 0 0; height: auto', $group([]));
        // children pin nothing of their own, so the container's alignment is what they get
        $this->assertStringContainsString('text-align: inherit', $this->styleOfCellContaining($group(['align' => 'center']), 'CHILD'));
        // and outside any container the shell anchors text left
        $this->assertStringContainsString('<td align="left" style="text-align:left', $group([]));
    }

    public function test_layout_direction_and_gap_become_cells_and_spacers(): void
    {
        $group = fn (array $layout): string => $this->render($this->email(), $this->block('g', 'group', [], ['layout' => $layout], [
            $this->block('a', 'paragraph', ['content' => 'AAA']),
            $this->block('b', 'paragraph', ['content' => 'BBB']),
        ]));
        $flat = static fn (string $html): string => (string) preg_replace('/>\s+</', '><', $html);

        // stacked with a gap: a spacer between the two children, none after the last
        $stacked = $flat($group(['gap' => '24px']));
        $this->assertSame(1, substr_count($stacked, '<td style="height: 24px; line-height: 24px; font-size: 0'));
        $this->assertLessThan(strpos($stacked, 'BBB'), strpos($stacked, 'height: 24px'));
        $this->assertGreaterThan(strpos($stacked, 'AAA'), strpos($stacked, 'height: 24px'));

        // row: one cell per child, a spacer CELL between, cross-axis alignment on each cell
        $row = $flat($group(['direction' => 'row', 'gap' => '10px', 'align' => 'center']));
        $this->assertSame(2, substr_count($row, '<td valign="middle"><table'));
        $this->assertSame(1, substr_count($row, '<td style="width: 10px; font-size: 0; line-height: 0"></td>'));
        $this->assertStringNotContainsString('height: 10px', $row);

        // space-between spreads the row across the full width; packed does not
        $this->assertStringContainsString('border="0" width="100%"><tr><td valign="top">', $flat($group(['direction' => 'row', 'justify' => 'space-between'])));
        $this->assertStringContainsString('border="0"><tr><td valign="top">', $flat($group(['direction' => 'row'])));

        // default direction, no gap: children simply follow one another
        $this->assertStringNotContainsString('<td valign=', $flat($group([])));
    }

    /**
     * The shape that reached a real inbox wrong: a centred group whose children are a pill (a
     * nested group with a background and no width), a heading and paragraph that name width 100%,
     * and a button. In flexbox the pill and button shrink to their content and sit in the middle;
     * the email used to stretch the pill into a full-width bar and leave the button at the left.
     */
    public function test_a_centred_group_shrinks_its_children_to_content_like_flexbox(): void
    {
        $pill = $this->block('pill', 'group', [], [
            'layout' => ['direction' => 'column', 'justify' => 'center', 'align' => 'center'],
            'color' => ['background' => '#79a2f2'],
            'spacing' => ['padding' => ['top' => '4px', 'right' => '16px', 'bottom' => '4px', 'left' => '16px']],
            'align' => 'center',
        ], [$this->block('pt', 'heading', ['content' => 'PILLTEXT', 'level' => 2])]);

        $html = $this->render($this->email(), $this->block('hero', 'group', [], [
            'layout' => ['align' => 'center', 'gap' => '0'],
            'color' => ['background' => '#5b8def'],
        ], [
            $pill,
            $this->block('h', 'heading', ['content' => 'FULLWIDTH', 'level' => 1], ['size' => ['width' => '100%'], 'typography' => ['textAlign' => 'center']]),
            $this->block('b', 'button', ['text' => 'GO', 'url' => 'https://example.com'], ['color' => ['background' => '#ffffff']]),
        ]));
        $flat = (string) preg_replace('/>\s+</', '><', $html);

        // the pill's table has no width of its own: it is as wide as its text
        $this->assertMatchesRegularExpression('/<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width: auto; max-width: 100%[^"]*"><tr><td[^>]*background-color: #79a2f2/', $flat);
        // ...and it (and the button) sit in a centred cell rather than the page's left edge
        $this->assertGreaterThanOrEqual(3, substr_count($flat, '<td align="center"><table'));
        $button = strstr($flat, 'GO') ?: '';
        $this->assertNotSame('', $button);
        // the explicit-width heading is NOT shrunk
        $this->assertMatchesRegularExpression('/<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width: 100%; max-width: 100%[^"]*"><tr><td[^>]*><h1[^>]*>FULLWIDTH/', $flat);
    }

    public function test_children_of_a_stretch_column_are_left_at_full_width(): void
    {
        // No alignment set = `align-items: stretch`: nothing shrinks, nothing is wrapped.
        $html = $this->render($this->email(), $this->block('g', 'group', [], [], [
            $this->block('p', 'paragraph', ['content' => 'STRETCHED']),
        ]));

        $this->assertStringNotContainsString('style="width: auto', $html);
        $this->assertStringNotContainsString('<td align="center"><table', $html);
    }

    public function test_columns_lay_out_as_a_row_or_stack(): void
    {
        $columns = fn (array $layout, array $columnLayout = []): string => (string) preg_replace('/>\s+</', '><', $this->render($this->email(), $this->block('cs', 'columns', [], ['layout' => $layout], [
            $this->block('c1', 'column', [], ['layout' => $columnLayout], [$this->block('p1', 'paragraph', ['content' => 'ONE'])]),
            $this->block('c2', 'column', [], [], [$this->block('p2', 'paragraph', ['content' => 'TWO'])]),
        ])));

        // row (the default): one row, shared widths, cells top-aligned
        $row = $columns([]);
        $this->assertSame(1, substr_count($row, '<tr valign="top">'));
        $this->assertSame(2, substr_count($row, 'class="hb-email-col" width="50%"'));

        // cross-axis alignment moves every cell at once; a gap is a spacer cell
        $this->assertStringContainsString('<tr valign="middle">', $columns(['align' => 'center']));
        $this->assertSame(1, substr_count($columns(['gap' => '16px']), '<td style="width: 16px; font-size: 0; line-height: 0"></td>'));

        // column: one row per cell, each full width
        $stack = $columns(['direction' => 'column', 'gap' => '8px']);
        $this->assertSame(2, substr_count($stack, '<tr valign="top">'));
        $this->assertSame(2, substr_count($stack, 'class="hb-email-col" width="100%"'));
        $this->assertSame(1, substr_count($stack, '<td style="height: 8px'));

        // a column block's own layout positions ITS children with a cell each, like any column
        $this->assertStringContainsString('<td align="center"><table', $columns([], ['align' => 'center']));
    }

    public function test_alignment_reaches_the_cell(): void
    {
        $html = $this->render($this->email(), $this->block('b1', 'button', ['text' => 'GO', 'url' => 'https://example.com'], ['align' => 'right']));

        $this->assertMatchesRegularExpression('/<td align="right" style="padding: 0 0 16px 0/', $html);
    }

    public function test_nothing_unresolved_survives_a_fully_styled_document(): void
    {
        $supports = [
            'align' => 'center',
            'color' => ['text' => 'var(--hb-t-ink)', 'background' => 'linear-gradient(45deg, #ff0000 0%, #0000ff 100%)'],
            'spacing' => ['padding' => ['top' => '10px'], 'margin' => ['top' => '4px']],
            'border' => ['width' => ['top' => '1px', 'left' => '2px'], 'color' => '#333333', 'radius' => ['topLeft' => '6px']],
            'size' => ['width' => '300px'],
            'appearance' => ['opacity' => '0.5'],
            'effects' => ['shadow' => '0 1px 2px #000000'],
        ];
        $html = $this->render(
            $this->email(),
            $this->block('g', 'group', [], $supports, [$this->block('h', 'heading', ['content' => 'H', 'level' => 2], $supports)]),
            $this->block('b', 'button', ['text' => 'B', 'url' => 'https://example.com'], $supports),
            $this->block('s', 'separator', [], $supports),
        );

        $this->assertStringNotContainsString('var(', $html);
        $this->assertDoesNotMatchRegularExpression('/style="[^"]*--hb-/', $html);
        $this->assertStringNotContainsString('gradient', $html);
    }

    public function test_every_email_template_variable_is_declared_by_its_contract(): void
    {
        // An undeclared name is not "ours", so it would be left for the theme-token pass and
        // — on the canvas — for the cascade, where a parent block's value could leak in.
        foreach ($this->app->make(BlockRegistryService::class)->discover()['blocks'] as $contract) {
            $name = (string) $contract['name'];
            $template = $contract['email']['template'] ?? null;
            if (! is_array($template)) {
                continue;
            }
            preg_match_all('/var\(\s*(--[a-z0-9-]+)/', (string) json_encode($template), $m);
            foreach (array_unique($m[1]) as $variable) {
                $this->assertArrayHasKey($variable, $contract['style']['variables'] ?? [], "{$name}: email.template reads {$variable}, which style.variables does not declare.");
            }
        }
    }

    public function test_the_email_style_tab_offers_only_what_the_template_reads(): void
    {
        $contracts = $this->app->make(BlockRegistryService::class)->discover()['blocks'];
        $checked = 0;

        foreach ($contracts as $contract) {
            $name = (string) $contract['name'];
            if (! is_array($contract['email']['template'] ?? null)) {
                $this->assertSame([], EmailSupports::for($contract));

                continue;
            }

            $honoured = EmailSupports::for($contract);
            $this->assertNotSame([], $honoured, "{$name}: an email block with no styleable supports.");

            // Nothing a mail client cannot render may be offered.
            foreach (['states', 'animation', 'position', 'effects', 'appearance'] as $webOnly) {
                $this->assertFalse(Arr::has($honoured, $webOnly), "{$name}: {$webOnly} is offered on email.");
            }

            // Everything offered is both declared by the contract and read by the template.
            $json = (string) json_encode($contract['email']['template']);
            foreach (Arr::dot(Arr::except($honoured, ['align'])) as $path => $on) {
                $this->assertTrue(Arr::get($contract['supports'], $path) === true, "{$name}: {$path} offered but not declared.");
                // `layout` is honoured as one coupled section by a "flow" inner-blocks node.
                $slot = str_contains($json, '{{supports.' . $path . '}}')
                    || (str_starts_with((string) $path, 'layout.') && preg_match('/"flow":"(blocks|cells)"/', $json) === 1);
                foreach ($contract['style']['variables'] as $variableName => $definition) {
                    if (($definition['source'] ?? '') === 'supports.' . $path && str_contains($json, 'var(' . $variableName)) {
                        $slot = true;
                    }
                }
                $this->assertTrue($slot, "{$name}: {$path} offered but no template slot reads it.");
                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked);

        // Layout and its flexbox controls are ONE section: offered whole or not at all.
        foreach ($contracts as $contract) {
            $layout = EmailSupports::for($contract)['layout'] ?? null;
            if ($layout !== null) {
                $this->assertSame($contract['supports']['layout'], $layout, $contract['name'] . ': the Layout section is offered piecemeal on email.');
            }
        }
        $this->assertArrayHasKey('layout', EmailSupports::for($this->app->make(BlockRegistryService::class)->getBlock('heisenberg/group')));
    }
}
