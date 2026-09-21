<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\EmailRenderer;

/**
 * Materializes a contract's `style.variables` into CSS (§4.5): the root inline
 * `style` attribute {@see BlockTreeRenderer} attaches to
 * every rendered block, and the interaction-state stylesheet
 * ({@see stateStylesCss()}) GTC calls the computedStyles channel. Extracted
 * verbatim from {@see BlockRenderer}.
 *
 * SECURITY-CRITICAL: every declaration value is routed through
 * {@see CssValueSanitizer} — this class never emits an unsanitized value.
 */
final class BlockStyleCompiler
{
    /**
     * Hard cap on inner-block nesting depth when compiling state-styles CSS —
     * mirrors {@see BlockTreeRenderer::MAX_NESTING_DEPTH}
     * (the same limit the tree walker enforces), so a pathologically deep tree
     * can never make the CSS pass recurse further than the HTML pass did.
     */
    private const MAX_NESTING_DEPTH = BlockTreeRenderer::MAX_NESTING_DEPTH;

    /**
     * Interaction states the model may style. Keys are the model paths under
     * `supports.states`; values are the CSS selector suffixes they compile to.
     * `.hb-state-preview` rides along on every state so the editor can force
     * a state's look while the user edits it.
     */
    public const INTERACTION_STATES = [
        'hover' => ':hover',
        'active' => ':active',
        'focus' => ':focus-within',
    ];

    public function __construct(
        private BlockRegistryService $registry,
        private CssValueSanitizer $cssValues = new CssValueSanitizer(),
        private DataPath $dataPath = new DataPath(),
        private TemplateInterpolator $interpolator = new TemplateInterpolator(),
    ) {
    }

    /**
     * Compile the per-instance interaction-state CSS for a list of blocks
     * (recursing inner blocks). Each state override re-declares the SAME
     * contract style variables the base inline style uses, scoped to the
     * block instance + pseudo-class, every value run through the variable's
     * own sanitize kind. Emitted as one stylesheet (GTC's computedStyles
     * channel) — never inline in the sanitized HTML.
     */
    public function stateStylesCss(array $blocks, int $depth = 0): string
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            return '';
        }

        $css = [];
        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_string($block['name'] ?? null)) {
                continue;
            }
            $contract = $this->registry->getBlock($block['name']);
            if ($contract === null) {
                continue;
            }

            $id = (string) ($block['id'] ?? '');
            $states = $block['supports']['states'] ?? null;
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1 && is_array($states)) {
                foreach (self::INTERACTION_STATES as $state => $pseudo) {
                    $declarations = $this->stateDeclarations($states[$state] ?? null, $contract);
                    if ($declarations === '') {
                        continue;
                    }
                    $scope = '[data-block-id="' . $id . '"]';
                    $css[] = $scope . $pseudo . ', ' . $scope . '.hb-state-preview-' . $state
                        . ' { ' . $declarations . ' }';
                }
            }

            $inner = $block['innerBlocks'] ?? null;
            if (is_array($inner) && $inner !== []) {
                $nested = $this->stateStylesCss($inner, $depth + 1);
                if ($nested !== '') {
                    $css[] = $nested;
                }
            }
        }

        return implode("\n", $css);
    }

    /** Declarations for one state's override map, keyed off the contract's style.variables. */
    private function stateDeclarations(mixed $overrides, array $contract): string
    {
        if (! is_array($overrides)) {
            return '';
        }

        $variables = $contract['style']['variables'] ?? [];
        if (! is_array($variables)) {
            return '';
        }

        $declarations = [];
        foreach ($variables as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $source = (string) ($definition['source'] ?? '');
            if (! str_starts_with($source, 'supports.')) {
                continue; // states override style-bearing supports only
            }

            $value = $this->dataPath->get($overrides, substr($source, 9));
            if ($value === null || $value === '') {
                continue; // unset for this state — base value cascades
            }

            $safe = $this->cssValues->sanitizeCssValue(
                $this->interpolator->scalarToString($value),
                (string) ($definition['sanitize'] ?? 'text'),
                ''
            );
            if ($safe !== '') {
                // !important: the base variable value sits in the root's
                // INLINE style, which otherwise beats any stylesheet rule.
                $declarations[] = $name . ': ' . $safe . ' !important';
            }
        }

        return implode('; ', $declarations);
    }

    /** Materialize the contract's style.variables into a CSS declaration string (§4.5). */
    public function blockStyleDeclarations(array $block, array $contract, string $surface = 'render'): string
    {
        $declarations = [];
        foreach ($this->blockStyleMap($block, $contract, $surface) as $name => $safe) {
            $declarations[] = $name . ': ' . $safe;
        }

        return $declarations === [] ? '' : implode('; ', $declarations) . ';';
    }

    /**
     * The contract's style.variables resolved for ONE block instance: variable name -> sanitized
     * value, in contract order. A variable with no authored value and no default is ABSENT (never
     * an empty string), so a `var(--name, fallback)` reading it takes its fallback.
     *
     * @return array<string, string>
     */
    public function blockStyleMap(array $block, array $contract, string $surface = 'render'): array
    {
        $variables = $contract['style']['variables'] ?? [];
        if (! is_array($variables)) {
            return [];
        }

        $map = [];
        foreach ($variables as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $value = $this->resolveStyleSource($block, (string) ($definition['source'] ?? ''));
            $default = (string) ($definition['default'] ?? '');
            if ($value === null || $value === '') {
                $value = $default;
            }

            $safe = $this->cssValues->sanitizeCssValue($this->interpolator->scalarToString($value), (string) ($definition['sanitize'] ?? 'text'), $default, $surface);
            if ($safe !== '') {
                // font-family values are token streams inside the property — an unquoted
                // multi-word family name like "Press Start 2P" parses as a sequence of idents
                // inside the css parser, but the browser's font-family parser only treats the
                // first one as the family and discards the rest, so the font silently falls
                // back. Wrap any value that contains a space and isn't already quoted so the
                // font-family parser sees a single string token.
                $sanitize = (string) ($definition['sanitize'] ?? 'text');
                if ($sanitize === 'font-family' && $safe[0] !== '"' && $safe[0] !== "'"
                    && preg_match('/\s/', $safe) === 1) {
                    $safe = '"' . str_replace('"', '\\"', $safe) . '"';
                }
                $map[(string) $name] = $safe;
            }
        }

        return $map;
    }

    /**
     * EMAIL SURFACE: replace every `var(--x[, fallback])` that reads one of THIS contract's own
     * style variables with this block instance's literal value (or the usage's fallback when the
     * instance sets nothing). Mail clients have no custom properties, so an email template's
     * `var()` is a substitution slot, not CSS — and it has to be filled per BLOCK. Filling it
     * per rendered fragment (what {@see EmailRenderer::resolveTokens()}
     * used to do alone) let the last block in a container overwrite every sibling's value: two
     * paragraphs in one group both shipped the second one's colour.
     *
     * A `var()` naming anything else — a design token such as `var(--ink)`, including one that
     * arrives INSIDE a resolved value — is left verbatim for EmailRenderer's theme-token pass.
     * Fallbacks may nest (`var(--hb-group-pt, var(--hb-flex-padding, 0))`).
     *
     * The canvas runs the identical algorithm (04-render-support's resolveEmailVars()), which is
     * what makes the email canvas and the sent mail agree by construction.
     */
    public function resolveEmailVars(string $css, array $block, array $contract): string
    {
        if (stripos($css, 'var(') === false) {
            return $css;
        }

        $variables = $contract['style']['variables'] ?? [];

        return $this->substituteVars($css, is_array($variables) ? $variables : [], $this->blockStyleMap($block, $contract, 'email'), 0);
    }

    /**
     * EMAIL SURFACE: a container's flex layout, translated into the only layout a mail client
     * has — table cells. Flexbox itself cannot ship, but what the Layout panel asks for can:
     *
     *   direction  column -> children stacked; row (and wrap) -> one cell per child
     *   gap        a spacer row (column) or spacer cell (row) between children
     *   justify    the MAIN axis: vertical in a column, horizontal in a row;
     *              space-between/space-around spread a row across the full width
     *   align      the CROSS axis: horizontal in a column, vertical in a row
     *
     * `align`/`valign` are the cell attribute values (or '' = leave the cell inheriting). The
     * axis swap is why a template cannot map these itself — it cannot branch on direction — so
     * they are handed to it as the synthetic `_emailAlign`/`_emailValign` attributes, and the
     * `inner-blocks` node (`"flow"`) lays the children out from the rest.
     *
     * The canvas mirror is emailLayoutFor() (04-render-support).
     *
     * @return array{row: bool, gap: string, spread: bool, align: string, valign: string}
     */
    public function emailLayout(array $block, array $contract): array
    {
        $map = $this->blockStyleMap($block, $contract, 'email');
        $row = str_starts_with($map['--hb-flex-direction'] ?? 'column', 'row');
        $justify = $map['--hb-flex-justify'] ?? '';
        $align = $map['--hb-flex-align'] ?? '';

        $horizontal = ['start' => 'left', 'center' => 'center', 'end' => 'right'];
        $vertical = ['start' => 'top', 'center' => 'middle', 'end' => 'bottom'];

        return [
            'row' => $row,
            'gap' => $map['--hb-flex-gap'] ?? '',
            'spread' => $row && in_array($justify, ['space-between', 'space-around'], true),
            'align' => $horizontal[$row ? $justify : $align] ?? '',
            'valign' => $vertical[$row ? $align : $justify] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $variables the contract's own style.variables (the "local" names)
     * @param array<string, string> $map this instance's resolved values
     */
    private function substituteVars(string $css, array $variables, array $map, int $depth): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($css);

        while ($depth < 8 && ($start = stripos($css, 'var(', $offset)) !== false) {
            $level = 0;
            $end = null;
            for ($i = $start + 3; $i < $length; $i++) {
                if ($css[$i] === '(') {
                    $level++;
                } elseif ($css[$i] === ')' && --$level === 0) {
                    $end = $i;
                    break;
                }
            }
            if ($end === null) {
                break; // unbalanced — leave the remainder untouched
            }

            $inner = substr($css, $start + 4, $end - $start - 4);
            $comma = strpos($inner, ',');
            $name = trim($comma === false ? $inner : substr($inner, 0, $comma));
            $fallback = $comma === false ? null : trim(substr($inner, $comma + 1));

            $out .= substr($css, $offset, $start - $offset);
            if (! array_key_exists($name, $variables)) {
                $out .= substr($css, $start, $end - $start + 1); // not ours: a design token
            } elseif (isset($map[$name])) {
                $out .= $map[$name];
            } elseif ($fallback !== null) {
                $out .= $this->substituteVars($fallback, $variables, $map, $depth + 1);
            }
            $offset = $end + 1;
        }

        return $out . substr($css, $offset);
    }

    private function resolveStyleSource(array $block, string $source): mixed
    {
        if (str_starts_with($source, 'supports.')) {
            return $this->dataPath->get($block['supports'] ?? [], substr($source, 9));
        }
        if (str_starts_with($source, 'attributes.')) {
            return $block['attributes'][substr($source, 11)] ?? null;
        }

        return null;
    }
}
