<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * The shared "supports capabilities" stylesheet — Phase 1 of the builder
 * full-kit overhaul. Mirrors {@see AnimationCatalog}: a GENERATED stylesheet
 * (no hand-authored per-block CSS) that reads generic `--hb-*` inline vars
 * a contract's `style.variables` sets on the block root, each var already
 * sanitized by {@see \Heisenberg\Services\BlockRenderer} through one of the
 * kinds in {@see \Heisenberg\Services\BlockContractValidator::SANITIZERS}.
 *
 * Contract with the runtime:
 *   - every rule is scoped to `[data-block-id]` — the same hook
 *     `BlockRenderer::stateStylesCss()` scopes state overrides to, and the
 *     one the editor's client JS already queries (`resources/js/builder.js`
 *     uses `[data-block-id]` as its block-root selector too), so the sheet
 *     works identically in the editor canvas and the public/preview page;
 *   - the always-on declarations (opacity, letter-spacing, text-align,
 *     position/transform, box-shadow, overflow, per-side border) additionally
 *     require the `.hb-supports` marker class on the block root. This is
 *     deliberate, and stricter than a bare `[data-block-id]` selector would
 *     be: `pullquote` and `code` — two of the 8 already-working blocks —
 *     set `text-align`/`border-top-width`/`border-bottom-width`/`overflow-x`
 *     directly on their OWN root class, at the SAME CSS specificity as a
 *     bare attribute selector (`.hb-block-pullquote` and `[data-block-id]`
 *     are both one-selector/0,1,0). Without the extra marker, which stylesheet
 *     wins would depend on `<link>`/`<style>` order — exactly the kind of
 *     fragility the "additive only, never touch the 8 working blocks" rule
 *     forbids. None of the 8 contracts carry `hb-supports`, so gating behind
 *     it makes the sheet UNREACHABLE for them regardless of load order —
 *     genuinely a no-op until a (future, Phase 4+) contract opts in by adding
 *     `hb-supports` to `style.className`/`style.classNames`;
 *   - structural, boolean-shaped capabilities (flex container, fill/hug
 *     width or height, clip) are additionally gated behind their OWN
 *     dedicated classes (`hb-flex-layout`, `hb-size-fill-w`, …) rather than
 *     vars, because a bare var can't safely flip `display` — `display`'s
 *     CSS-spec initial value is `inline`, not "whatever the tag defaults
 *     to", so a var-with-fallback can't express "leave display alone";
 *   - `hb-align-wide` / `hb-align-full` (block width breakout) ride on the
 *     EXISTING `supports.align` mechanism (`BlockRenderer::resolveClass()`),
 *     not on `.hb-supports` — same activation path as the already-shipped
 *     `hb-align-left/center/right` (declared in `resources/css/builder.css`);
 *   - unset vars fall back to safe defaults (the property's effective
 *     browser default for that value), so the whole sheet is inert until a
 *     contract actually sets a var / adds a capability class.
 */
final class SupportsStyle
{
    public const DEFAULT_OPACITY = '1';

    public const DEFAULT_LETTER_SPACING = 'normal';

    public const DEFAULT_TEXT_ALIGN = 'initial';

    public const DEFAULT_TEXT_ALIGN_VERTICAL = 'auto';

    public const DEFAULT_POSITION_MODE = 'static';

    public const DEFAULT_TX = '0';

    public const DEFAULT_TY = '0';

    public const DEFAULT_ROTATE = '0deg';

    public const DEFAULT_SHADOW = 'none';

    public const DEFAULT_OVERFLOW = 'visible';

    public const DEFAULT_BORDER_SIDE_WIDTH = '0';

    public const DEFAULT_BORDER_SIDE_STYLE = 'none';

    public const DEFAULT_BORDER_SIDE_COLOR = 'transparent';

    public const DEFAULT_FLEX_DIRECTION = 'row';

    public const DEFAULT_FLEX_JUSTIFY = 'flex-start';

    public const DEFAULT_FLEX_ALIGN = 'stretch';

    public const DEFAULT_FLEX_GAP = '0';

    public const DEFAULT_FLEX_PADDING = '0';

    /** Every border side the per-side capability (Border → Stroke) covers. */
    public const BORDER_SIDES = ['top', 'right', 'bottom', 'left'];

    /** The shared stylesheet: capability rules, each var()'d with a safe, no-op default. */
    public static function css(): string
    {
        $css = [
            '/* Heisenberg supports-capabilities stylesheet — generated from SupportsStyle.',
            '   Additive-only (Phase 1 of the builder full-kit overhaul): a block root only',
            '   picks up a rule below once it carries the matching capability class, so this',
            '   sheet is a no-op for every contract that predates it. */',
        ];

        $css[] = self::baseCapabilitiesRule();
        $css[] = self::flexLayoutRule();
        $css[] = self::sizeUtilityRules();
        $css[] = self::alignBreakoutRules();

        return implode("\n", array_filter($css, static fn (string $block): bool => $block !== ''));
    }

    /**
     * Appearance, typography (letter-spacing/align/vertical-align), position
     * (mode + x/y/rotation transform), effects (shadow), overflow, and the
     * per-side border (Border → Stroke) — one declaration block, gated
     * behind `[data-block-id].hb-supports`.
     */
    private static function baseCapabilitiesRule(): string
    {
        $declarations = [
            'opacity: var(--hb-opacity, ' . self::DEFAULT_OPACITY . ')',
            'letter-spacing: var(--hb-letter-spacing, ' . self::DEFAULT_LETTER_SPACING . ')',
            'text-align: var(--hb-text-align, ' . self::DEFAULT_TEXT_ALIGN . ')',
            'align-self: var(--hb-text-align-v, ' . self::DEFAULT_TEXT_ALIGN_VERTICAL . ')',
            'position: var(--hb-position-mode, ' . self::DEFAULT_POSITION_MODE . ')',
            'transform: translate(var(--hb-tx, ' . self::DEFAULT_TX . 'px), var(--hb-ty, ' . self::DEFAULT_TY . 'px)) rotate(var(--hb-rotate, ' . self::DEFAULT_ROTATE . '))',
            'box-shadow: var(--hb-shadow, ' . self::DEFAULT_SHADOW . ')',
            'overflow: var(--hb-overflow, ' . self::DEFAULT_OVERFLOW . ')',
        ];

        foreach (self::BORDER_SIDES as $side) {
            $declarations[] = "border-{$side}-width: var(--hb-border-{$side}-width, " . self::DEFAULT_BORDER_SIDE_WIDTH . ')';
            $declarations[] = "border-{$side}-style: var(--hb-border-{$side}-style, " . self::DEFAULT_BORDER_SIDE_STYLE . ')';
            $declarations[] = "border-{$side}-color: var(--hb-border-{$side}-color, " . self::DEFAULT_BORDER_SIDE_COLOR . ')';
        }

        return '[data-block-id].hb-supports { ' . implode('; ', $declarations) . '; }';
    }

    /**
     * Flex container (`supports.layout`: direction/justify/align/gap/padding).
     * A SEPARATE class from the base rule — flipping `display` can't be done
     * safely with a var-and-fallback (see class docblock), so it needs its
     * own explicit opt-in class rather than riding on `.hb-supports` alone.
     */
    private static function flexLayoutRule(): string
    {
        $declarations = [
            'display: flex',
            'flex-direction: var(--hb-flex-direction, ' . self::DEFAULT_FLEX_DIRECTION . ')',
            'justify-content: var(--hb-flex-justify, ' . self::DEFAULT_FLEX_JUSTIFY . ')',
            'align-items: var(--hb-flex-align, ' . self::DEFAULT_FLEX_ALIGN . ')',
            'gap: var(--hb-flex-gap, ' . self::DEFAULT_FLEX_GAP . ')',
            'padding: var(--hb-flex-padding, ' . self::DEFAULT_FLEX_PADDING . ')',
        ];

        return '[data-block-id].hb-supports.hb-flex-layout { ' . implode('; ', $declarations) . '; }';
    }

    /**
     * Size — fill/hug/clip (`supports.size.fill|hug|clip`). These are boolean
     * capabilities (no magnitude to carry), so — like `hb-align-*` — they are
     * plain utility classes rather than var-driven declarations.
     */
    private static function sizeUtilityRules(): string
    {
        return implode("\n", [
            '[data-block-id].hb-supports.hb-size-fill-w { width: 100%; }',
            '[data-block-id].hb-supports.hb-size-fill-h { height: 100%; }',
            '[data-block-id].hb-supports.hb-size-hug-w { width: fit-content; max-width: 100%; }',
            '[data-block-id].hb-supports.hb-size-hug-h { height: fit-content; }',
            '[data-block-id].hb-supports.hb-size-clip { overflow: hidden; }',
        ]);
    }

    /**
     * Block-alignment breakout (`hb-align-wide` / `hb-align-full`) — the
     * `resolveClass()` dead-branch fix. Rides on the SAME activation path as
     * the already-shipped `hb-align-left/center/right` (a block declares
     * `wide`/`full` in `supports.align` and an instance picks it), so it is
     * intentionally NOT gated behind `.hb-supports`.
     */
    private static function alignBreakoutRules(): string
    {
        return implode("\n", [
            '[data-block-id].hb-align-wide { width: min(100%, 1200px); max-width: 1200px; margin-left: 50%; transform: translateX(-50%); }',
            '[data-block-id].hb-align-full { width: 100vw; max-width: none; margin-left: 50%; transform: translateX(-50%); }',
        ]);
    }
}
