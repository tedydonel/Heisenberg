<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRenderer;

/**
 * Token-validates every CSS value the renderer ever materializes into an inline
 * style or state-styles stylesheet (§4.5) — colours confined to the design palette,
 * lengths/angles/shadows/gradients each validated on their own terms. Extracted
 * verbatim from {@see BlockRenderer}.
 *
 * SECURITY-CRITICAL: this is the ONLY place a `supports.*`/`attributes.*` value
 * becomes a CSS declaration. A sanitizer kind not explicitly matched here falls
 * through to the generic (still restrictive) default in {@see cssValueValid()} —
 * never to an unchecked pass-through.
 */
final class CssValueSanitizer
{
    /**
     * CSS `<alpha-value>`: a 0–1 number (any number of decimals, leading zero optional) or a
     * percentage. The colour picker emits three decimals — `rgba(208,64,64,1.000)` — so a
     * pattern that only accepted `1` or `0.5` rejected the picker's own output, and with it
     * every gradient built from it, since a gradient is only as valid as its stop colours.
     */
    private const ALPHA_VALUE = '(?:0|1|0?\.\d+|1\.0+|(?:100|\d{1,2})(?:\.\d+)?%)';

    /** A `<length-percentage>` token for a gradient's optional stop position — `%` or a length unit. */
    private const GRADIENT_POSITION = '-?\d+(?:\.\d+)?(?:%|px|rem|em|vw|vh)';

    /**
     * `$surface === 'email'` degrades a validated gradient to its first colour stop (§Bug A
     * step 5) — Outlook cannot render `linear-gradient()`/`radial-gradient()`, and shipping one
     * anyway would just render as no background at all. The check runs on whatever value ended
     * up safe (value or fallback), so a gradient default degrades exactly like an authored one.
     */
    public function sanitizeCssValue(string $value, string $sanitizer, string $fallback, string $surface = 'render'): string
    {
        $value = $this->normalizeCssNumber(trim($value), $sanitizer);
        $safe = $this->cssValueValid($value, $sanitizer) ? $value : '';

        if ($safe === '' && $fallback !== '') {
            $fallback = $this->normalizeCssNumber(trim($fallback), $sanitizer);
            $safe = $this->cssValueValid($fallback, $sanitizer) ? $fallback : '';
        }

        if ($safe !== '' && $surface === 'email' && $this->isSafeGradientValue($safe)) {
            $safe = $this->firstGradientStopColor($safe);
        }

        return $safe;
    }

    public function isSafeColorValue(string $value): bool
    {
        return preg_match('/^var\(--(?:accent-[a-z0-9-]+|ink|faint|paper)\)$/', $value) === 1
            || preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1
            || preg_match('/^rgba?\(\s*(25[0-5]|2[0-4]\d|1?\d?\d)\s*,\s*(25[0-5]|2[0-4]\d|1?\d?\d)\s*,\s*(25[0-5]|2[0-4]\d|1?\d?\d)(\s*,\s*' . self::ALPHA_VALUE . ')?\s*\)$/i', $value) === 1
            || preg_match('/^hsla?\(\s*(360|3[0-5]\d|[12]?\d?\d)\s*,\s*(100|\d?\d)%\s*,\s*(100|\d?\d)%(\s*,\s*' . self::ALPHA_VALUE . ')?\s*\)$/i', $value) === 1;
    }

    /**
     * A bare number carries no CSS unit and would fail its sanitizer; resolve the implied
     * unit (px for lengths, deg for angles, % for 0-100 opacity). Lockstep with the JS
     * normalizeCssNumber() in block-runtime.blade.php.
     */
    private function normalizeCssNumber(string $value, string $sanitizer): string
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            return $value;
        }

        return match ($sanitizer) {
            'size-value', 'length-signed' => $value . 'px',
            'angle' => $value . 'deg',
            'opacity' => (float) $value > 1 ? $value . '%' : $value,
            default => $value,
        };
    }

    private function cssValueValid(string $value, string $sanitizer): bool
    {
        if ($value === '') {
            return false;
        }

        return match ($sanitizer) {
            'color-token' => $this->isSafeColorValue($value),
            'color-token-or-transparent' => $value === 'transparent' || $this->isSafeColorValue($value),
            'border-style' => in_array($value, ['none', 'solid', 'dashed', 'dotted'], true),
            'font-token' => preg_match('/^var\(--[a-z0-9-]+\)$/i', $value) === 1,
            'size-value' => preg_match('/^(var\(--[a-z0-9-]+\)|-?\d+(\.\d+)?(px|rem|em|%|vw|vh))$/i', $value) === 1,
            'color-value' => $value === 'transparent' || preg_match('/^var\(--[a-z0-9-]+\)$/i', $value) === 1 || $this->isSafeColorValue($value),
            // `color-value` plus `linear-gradient()`/`radial-gradient()` — BACKGROUND/fill kinds
            // only (see isSafeGradientValue()'s docblock for why text colour never gets this).
            'color-value-or-gradient' => $value === 'transparent' || preg_match('/^var\(--[a-z0-9-]+\)$/i', $value) === 1 || $this->isSafeColorValue($value) || $this->isSafeGradientValue($value),
            'font-family' => preg_match('/^(var\(--[a-z0-9-]+\)|[a-z0-9][a-z0-9 \-]{0,80})$/i', $value) === 1,
            'font-weight' => preg_match('/^(var\(--[a-z0-9-]+\)|[1-9]00)$/i', $value) === 1,
            'size-token' => preg_match('/^(0|auto|100%|var\(--[a-z0-9-]+(,\s*var\(--[a-z0-9-]+\))?\)|calc\([a-z0-9\s().,%*\/+-]+\)|-?\d+(\.\d+)?(px|rem|em|vw|%)?)$/i', $value) === 1,
            'integer' => preg_match('/^-?\d+$/', $value) === 1,
            // Supports-capability kinds. LOCKSTEP with BlockContractValidator::SANITIZERS and
            // the JS cssValueValid(); every kind gets its OWN case, never the permissive default.
            'opacity' => preg_match('/^(0|1|0?\.\d{1,3}|(100|[1-9]?\d)%)$/', $value) === 1,
            'angle' => preg_match('/^-?\d{1,3}(\.\d+)?deg$/i', $value) === 1,
            'length-signed' => $this->isSafeLengthSignedValue($value),
            'shadow' => $this->isSafeShadowValue($value),
            'text-align' => in_array($value, ['left', 'center', 'right', 'justify'], true),
            'align-3' => in_array($value, ['start', 'center', 'end'], true),
            'position-mode' => in_array($value, ['static', 'relative', 'absolute'], true),
            'flex-direction' => in_array($value, ['row', 'column', 'row-reverse', 'column-reverse'], true),
            'flex-justify' => in_array($value, ['start', 'center', 'end', 'space-between', 'space-around'], true),
            'flex-align' => in_array($value, ['start', 'center', 'end', 'stretch'], true),
            'flex-wrap' => in_array($value, ['wrap', 'nowrap', 'wrap-reverse'], true),
            'overflow' => in_array($value, ['visible', 'hidden', 'clip'], true),
            'box-sizing' => in_array($value, ['border-box', 'content-box'], true),
            'filter' => $this->isSafeFilterValue($value),
            default => preg_match('#^[a-z0-9\s().,%_/-]+$#i', $value) === 1,
        };
    }

    /**
     * `filter` (Effects → layer blur, and background blur's `backdrop-filter`): `none`, or a
     * space-separated list of `blur(Npx)` — nothing else. No `url()` (an SVG filter reference
     * could fetch anything), no drop-shadow() (shadows live in box-shadow) and no colour filters.
     * LOCKSTEP with the JS isSafeFilterValue().
     */
    private function isSafeFilterValue(string $value): bool
    {
        if ($value === 'none') {
            return true;
        }

        $functions = preg_split('/\s+/', trim($value)) ?: [];
        if ($functions === [] || count($functions) > 12) {
            return false;
        }

        foreach ($functions as $function) {
            if (preg_match('/^blur\(\d{1,3}(\.\d+)?px\)$/', $function) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** `length-signed`: a signed length (letter-spacing, translate x/y, per-side border width) or the bare `0`. */
    private function isSafeLengthSignedValue(string $value): bool
    {
        return preg_match('/^(0|-?\d+(\.\d+)?(px|rem|em|%|vw|vh))$/i', $value) === 1;
    }

    /**
     * `shadow`: one or more comma-separated box-shadow layers. Per layer: an
     * optional `inset`, 2–4 signed lengths, and exactly one colour — each
     * length re-validated via {@see isSafeLengthSignedValue()} and the colour
     * via the existing {@see isSafeColorValue()}. Deliberately NOT one mega
     * regex (study doc §Phase-1 spec) so every component is validated on its
     * own terms; comma/paren-aware splitting keeps rgba()/hsla() layers intact.
     */
    private function isSafeShadowValue(string $value): bool
    {
        if ($value === 'none') {
            return true; // the real box-shadow keyword for "no shadow" — not a layer list.
        }

        $layers = $this->splitTopLevel($value, ',');
        if ($layers === []) {
            return false;
        }

        foreach ($layers as $layer) {
            if (! $this->isSafeShadowLayer($layer)) {
                return false;
            }
        }

        return true;
    }

    private function isSafeShadowLayer(string $layer): bool
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', trim($layer)));
        if ($normalized === '') {
            return false;
        }

        $tokens = array_values(array_filter(
            $this->splitTopLevel($normalized, ' '),
            static fn (string $t): bool => $t !== ''
        ));
        if ($tokens === []) {
            return false;
        }

        $insetCount = 0;
        $colorTokens = [];
        $lengthTokens = [];
        foreach ($tokens as $token) {
            if (strcasecmp($token, 'inset') === 0) {
                $insetCount++;

                continue;
            }
            if ($this->isSafeColorValue($token)) {
                $colorTokens[] = $token;

                continue;
            }
            $lengthTokens[] = $token;
        }

        if ($insetCount > 1 || count($colorTokens) !== 1) {
            return false;
        }
        if (count($lengthTokens) < 2 || count($lengthTokens) > 4) {
            return false;
        }

        foreach ($lengthTokens as $length) {
            if (! $this->isSafeLengthSignedValue($length)) {
                return false;
            }
        }

        return true;
    }

    /** Split on a delimiter char, but only at paren-depth 0 (keeps rgba(0, 0, 0, .2) etc. intact). */
    private function splitTopLevel(string $value, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $ch = $value[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && $ch === $delimiter) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $ch;
        }
        $parts[] = $current;

        return array_map('trim', $parts);
    }

    /**
     * `color-value-or-gradient`: `linear-gradient(...)` or `radial-gradient(...)`, BACKGROUND/fill
     * only — never wired to text colour (a `color: linear-gradient(...)` declaration is invalid
     * CSS and would just make the text disappear). Deliberately fail-closed and NOT one mega
     * regex: an optional direction/shape preamble, then 2+ comma-separated stops, each stop's
     * colour re-validated through the EXISTING {@see isSafeColorValue()} (so a gradient can never
     * smuggle a colour the flat sanitizer would reject) and each stop's optional position a bare
     * `%`/length. `splitTopLevel()` (already used by the shadow sanitizer) keeps commas/spaces
     * inside `rgba()`/`hsla()` stop colours intact while splitting the gradient's own structure.
     *
     * `repeating-linear-gradient()`/`repeating-radial-gradient()` are DELIBERATELY NOT accepted:
     * they take the same grammar, but nothing here bounds the repeat density, and an attacker-sized
     * stop list (or a pathologically small repeat interval) is a rendering-cost DoS this validator
     * has no way to price — narrower allow-list beats a guessed cap.
     */
    private function isSafeGradientValue(string $value): bool
    {
        $value = trim($value);
        if (preg_match('/^(linear|radial)-gradient\(/i', $value, $prefix) !== 1 || ! str_ends_with($value, ')')) {
            return false;
        }

        $kind = strtolower($prefix[1]);
        $inner = substr($value, strlen($prefix[0]), -1);
        if (substr_count($inner, '(') !== substr_count($inner, ')')) {
            return false; // unbalanced parens — never hand an unbalanced string to splitTopLevel
        }

        $parts = $this->splitTopLevel($inner, ',');
        if ($parts === [] || $parts[0] === '') {
            return false;
        }

        $isPreamble = $kind === 'linear' ? $this->isSafeLinearPreamble(...) : $this->isSafeRadialPreamble(...);
        if ($isPreamble($parts[0])) {
            array_shift($parts);
        }

        if (count($parts) < 2) {
            return false; // 2+ colour stops required
        }

        foreach ($parts as $stop) {
            if (! $this->isSafeGradientStop($stop)) {
                return false;
            }
        }

        return true;
    }

    /** `linear-gradient()`'s optional first argument: an angle, or `to <side-or-corner>`. */
    private function isSafeLinearPreamble(string $part): bool
    {
        $part = trim($part);

        return preg_match('/^-?\d{1,3}(\.\d+)?deg$/i', $part) === 1
            || preg_match('/^to\s+(?:(?:top|bottom)(?:\s+(?:left|right))?|(?:left|right)(?:\s+(?:top|bottom))?)$/i', $part) === 1;
    }

    /** `radial-gradient()`'s optional first argument: a shape and/or an `at <position>` clause. */
    private function isSafeRadialPreamble(string $part): bool
    {
        $part = trim($part);
        if ($part === '') {
            return false;
        }

        $pos = '(?:center|top|bottom|left|right|' . self::GRADIENT_POSITION . ')';

        return preg_match('/^(?:circle|ellipse)$/i', $part) === 1
            || preg_match('/^(?:(?:circle|ellipse)\s+)?at\s+' . $pos . '(?:\s+' . $pos . ')?$/i', $part) === 1;
    }

    /** One gradient colour stop: a safe colour, plus an optional `%`/length position. */
    private function isSafeGradientStop(string $stop): bool
    {
        $tokens = array_values(array_filter(
            $this->splitTopLevel(trim($stop), ' '),
            static fn (string $t): bool => $t !== ''
        ));

        if (count($tokens) < 1 || count($tokens) > 2) {
            return false;
        }
        if (! $this->isSafeColorValue($tokens[0])) {
            return false;
        }

        return count($tokens) === 1 || preg_match('/^' . self::GRADIENT_POSITION . '$/i', $tokens[1]) === 1;
    }

    /**
     * The email degrade for a validated gradient (§Bug A step 5): Outlook cannot render a CSS
     * gradient, so the email surface substitutes the gradient's FIRST colour stop as a flat
     * fallback rather than shipping a value the client will just ignore. Scans comma-parts for
     * the first one whose leading token is a safe colour — that skips the optional
     * direction/shape preamble without re-deriving which gradient kind produced it.
     */
    private function firstGradientStopColor(string $gradient): string
    {
        if (preg_match('/^(?:linear|radial)-gradient\((.*)\)$/is', $gradient, $inner) !== 1) {
            return '';
        }

        foreach ($this->splitTopLevel($inner[1], ',') as $part) {
            $tokens = array_values(array_filter(
                $this->splitTopLevel(trim($part), ' '),
                static fn (string $t): bool => $t !== ''
            ));
            if ($tokens !== [] && $this->isSafeColorValue($tokens[0])) {
                return $tokens[0];
            }
        }

        return '';
    }
}
