<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * The shared "supports capabilities" stylesheet. Mirrors {@see AnimationCatalog}:
 * a GENERATED stylesheet
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
 *     position/transform, box-shadow, overflow, per-side border, per-corner
 *     radius) additionally
 *     require the `.hb-supports` marker class on the block root. This is
 *     deliberate, and stricter than a bare `[data-block-id]` selector would
 *     be: `pullquote` and `code` — two of the 8 already-working blocks —
 *     set `text-align`/`border-top-width`/`border-bottom-width`/`overflow-x`
 *     directly on their OWN root class, at the SAME CSS specificity as a
 *     bare attribute selector (`.hb-block-pullquote` and `[data-block-id]`
 *     are both one-selector/0,1,0). Without the extra marker, which stylesheet
 *     wins would depend on `<link>`/`<style>` order — exactly the kind of
 *     fragility the "additive only" rule forbids. A contract opts in by adding
 *     `hb-supports` to `style.className` — both shipped contracts (heading,
 *     paragraph) carry it;
 *   - structural, boolean-shaped capabilities (flex container, fill/hug
 *     width or height, clip) are additionally gated behind their OWN
 *     dedicated classes (`hb-flex-layout`, `hb-size-fill-w`, …) rather than
 *     vars, because a bare var can't safely flip `display` — `display`'s
 *     CSS-spec initial value is `inline`, not "whatever the tag defaults
 *     to", so a var-with-fallback can't express "leave display alone";
 *   - the `hb-align-*` classes (`supports.align` via
 *     `BlockRenderer::resolveClass()` / the canvas runtime) are NOT gated
 *     on `.hb-supports` — see alignBreakoutRules();
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

    public const DEFAULT_BORDER_RADIUS = '0';

    public const DEFAULT_FLEX_DIRECTION = 'row';

    public const DEFAULT_FLEX_JUSTIFY = 'flex-start';

    public const DEFAULT_FLEX_ALIGN = 'stretch';

    public const DEFAULT_FLEX_GAP = '0';

    public const DEFAULT_FLEX_PADDING = '0';

    /** Every border side the per-side capability (Border → Stroke) covers. */
    public const BORDER_SIDES = ['top', 'right', 'bottom', 'left'];

    /**
     * Every corner the radius capability (Border → Appearance's corner fields)
     * covers: variable suffix => the CSS longhand's corner name. Listed in the
     * `border-radius` shorthand's own order so the sheet reads like the property.
     *
     * The suffix stays under the `--hb-border-` prefix rather than a bare
     * `--hb-radius-*`: `--hb-radius-{xs,sm,swatch,md,control,lg}` are EDITOR CHROME
     * design tokens declared on `:root` in resources/css/tokens.css, and those
     * inherit into every block root in the canvas. Sharing that prefix would put a
     * block capability one token-name collision away from the design system.
     */
    public const BORDER_CORNERS = [
        'tl' => 'top-left',
        'tr' => 'top-right',
        'br' => 'bottom-right',
        'bl' => 'bottom-left',
    ];

    /** The shared stylesheet: capability rules, each var()'d with a safe, no-op default. */
    public static function css(): string
    {
        $css = [
            '/* Heisenberg supports-capabilities stylesheet — generated from SupportsStyle.',
            '   Additive-only: a block root only picks up a rule below once it carries the',
            '   matching capability class. */',
        ];

        $css[] = self::inheritanceResetRule();
        $css[] = self::baseCapabilitiesRule();
        $css[] = self::flexLayoutRule();
        $css[] = self::sizeUtilityRules();
        $css[] = self::alignBreakoutRules();

        return implode("\n", array_filter($css, static fn (string $block): bool => $block !== ''));
    }

    /**
     * Neutralise every capability variable on EVERY block root, before any capability
     * rule reads one.
     *
     * CSS custom properties INHERIT. A container sets `--hb-border-top-width` in its own
     * inline style; each nested child is also a block root carrying `.hb-supports`, so
     * without this the child's `border-top-width: var(--hb-border-top-width, 0)` resolved
     * the value it inherited from its PARENT — one border on a group drew a border around
     * every block inside it. Same for the radius, shadow, opacity and transform families.
     *
     * Deliberately selected at `[data-block-id]` (0,1,0), NOT at the capability rule's
     * `[data-block-id].hb-supports` (0,2,0): the reset must lose every tie it can. A block's
     * own inline `style` beats it (that is how a real value still applies), and so do the
     * interaction-state rules `BlockRenderer::stateStylesCss()` emits at
     * `[data-block-id="…"]:hover` (0,2,0) — a hover override must keep winning. Setting the
     * property explicitly is what stops inheritance; the specificity only decides who
     * overrides the reset, and everything does.
     */
    private static function inheritanceResetRule(): string
    {
        // NOT reset: --hb-text-align and --hb-letter-spacing. `text-align` and
        // `letter-spacing` are inherited CSS properties by nature — a container setting
        // them SHOULD reach the text inside it, and neutralising those two would break
        // ordinary typography rather than fix a bleed. Only box-shaped capabilities, which
        // must never cascade, are listed here.
        $declarations = [
            '--hb-opacity: ' . self::DEFAULT_OPACITY,
            '--hb-text-align-v: ' . self::DEFAULT_TEXT_ALIGN_VERTICAL,
            '--hb-position-mode: ' . self::DEFAULT_POSITION_MODE,
            '--hb-tx: ' . self::DEFAULT_TX,
            '--hb-ty: ' . self::DEFAULT_TY,
            '--hb-rotate: ' . self::DEFAULT_ROTATE,
            '--hb-shadow: ' . self::DEFAULT_SHADOW,
            '--hb-overflow: ' . self::DEFAULT_OVERFLOW,
            // The flex family: a container's gap/padding/direction must not become its
            // nested container children's, either.
            '--hb-flex-direction: ' . self::DEFAULT_FLEX_DIRECTION,
            '--hb-flex-wrap: nowrap',
            '--hb-flex-justify: ' . self::DEFAULT_FLEX_JUSTIFY,
            '--hb-flex-align: ' . self::DEFAULT_FLEX_ALIGN,
            '--hb-flex-gap: ' . self::DEFAULT_FLEX_GAP,
            '--hb-flex-padding: ' . self::DEFAULT_FLEX_PADDING,
        ];

        foreach (self::BORDER_SIDES as $side) {
            $declarations[] = "--hb-border-{$side}-width: " . self::DEFAULT_BORDER_SIDE_WIDTH;
            $declarations[] = "--hb-border-{$side}-style: " . self::DEFAULT_BORDER_SIDE_STYLE;
            $declarations[] = "--hb-border-{$side}-color: " . self::DEFAULT_BORDER_SIDE_COLOR;
        }

        foreach (array_keys(self::BORDER_CORNERS) as $suffix) {
            $declarations[] = "--hb-border-radius-{$suffix}: " . self::DEFAULT_BORDER_RADIUS;
        }

        return '[data-block-id] { ' . implode('; ', $declarations) . '; }';
    }

    /**
     * Appearance, typography (letter-spacing/align/vertical-align), position
     * (mode + x/y/rotation transform), effects (shadow), overflow, the
     * per-side border (Border → Stroke) and the per-corner radius
     * (Border → Appearance) — one declaration block, gated
     * behind `[data-block-id].hb-supports`.
     *
     * Radius is written as the four LONGHANDS, not the `border-radius`
     * shorthand: a shorthand takes all four corners at once, so an unset corner
     * would still be reset to the shorthand's own value rather than left alone.
     * Longhand + var-with-fallback keeps each corner independently inert.
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

        foreach (self::BORDER_CORNERS as $suffix => $corner) {
            $declarations[] = "border-{$corner}-radius: var(--hb-border-radius-{$suffix}, " . self::DEFAULT_BORDER_RADIUS . ')';
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
            'flex-wrap: var(--hb-flex-wrap, nowrap)',
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
     * Block-PLACEMENT rules for the `hb-align-<value>` classes both renderers
     * emit from `supports.align`: left/center/right place the block box in its
     * parent via margins (visible once the block is narrower than its parent —
     * hug/fixed width); wide/full are width breakouts. Text alignment is NOT
     * this mechanism — it is Typography's `--hb-text-align` variable. Text
     * contracts (heading, paragraph) deliberately declare no `align`.
     * Intentionally NOT gated behind `.hb-supports`.
     */
    private static function alignBreakoutRules(): string
    {
        return implode("\n", [
            '[data-block-id].hb-align-left { margin-left: 0; margin-right: auto; }',
            '[data-block-id].hb-align-center { margin-left: auto; margin-right: auto; }',
            '[data-block-id].hb-align-right { margin-left: auto; margin-right: 0; }',
            '[data-block-id].hb-align-wide { width: min(100%, 1200px); max-width: 1200px; margin-left: 50%; transform: translateX(-50%); }',
            '[data-block-id].hb-align-full { width: 100vw; max-width: none; margin-left: 50%; transform: translateX(-50%); }',
        ]);
    }
}
