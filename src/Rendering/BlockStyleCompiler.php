<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;

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
        $variables = $contract['style']['variables'] ?? [];
        if (! is_array($variables)) {
            return '';
        }

        $declarations = [];
        foreach ($variables as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $value = $this->resolveStyleSource($block, (string) ($definition['source'] ?? ''));
            $default = (string) ($definition['default'] ?? '');
            if ($value === null || $value === '') {
                $value = $default;
            }

            $safe = $this->cssValues->sanitizeCssValue((string) $value, (string) ($definition['sanitize'] ?? 'text'), $default, $surface);
            if ($safe !== '') {
                // font-family values are token streams inside the property — an unquoted
                // multi-word family name like "Press Start 2P" parses as a sequence of idents
                // inside the css parser, but the browser's font-family parser only treats the
                // first one as the family and discards the rest, so the font silently falls
                // back. Wrap any value that contains a space and isn't already quoted so the
                // font-family parser sees a single string token.
                $sanitize = (string) ($definition['sanitize'] ?? 'text');
                if ($sanitize === 'font-family' && $safe !== '' && $safe[0] !== '"' && $safe[0] !== "'"
                    && preg_match('/\s/', $safe) === 1) {
                    $safe = '"' . str_replace('"', '\\"', $safe) . '"';
                }
                $declarations[] = $name . ': ' . $safe;
            }
        }

        return $declarations === [] ? '' : implode('; ', $declarations) . ';';
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
