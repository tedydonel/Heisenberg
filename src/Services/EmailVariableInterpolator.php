<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Support\EmailVariableContext;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Support\EmailVariableResolutionException;
use Throwable;

/**
 * Context-aware, strict interpolator that resolves host-registered `{{ dotted.key }}`
 * tokens into a copied email-subject + email-block tree BEFORE
 * {@see \Heisenberg\Services\BlockRenderer} sanitizes rich text / runs the URL
 * gate (.hermes/plans/2026-08-25_190059-email-template-variables.md, Task 2).
 *
 * PIPELINE (per the plan's "Interpolation algorithm" §1–9):
 *  1. Extract only valid `{{ dotted.key }}` tokens. Optional whitespace around
 *     the key is accepted; `{{ user.}}` (empty segment) is left alone, and
 *     `{{ user.first_name }` (unclosed) is left alone.
 *  2. Resolve each token against a registered {@see EmailVariableDefinition}
 *     and the supplied {@see EmailVariableContext}. Explicit `null` reaches
 *     the formatter when the key is present in the context (the formatter
 *     decides whether null is acceptable).
 *  3. Ask the definition's formatter type to produce a string for the
 *     substitution TARGET (`text` for subject / ordinary string attributes /
 *     rich-text; `url` for `type: "url"` contract attributes; `email` for
 *     `mailto:`-flavoured URLs).
 *  4. For rich-text attributes, HTML-escape the formatted replacement BEFORE
 *     {@see BlockRenderer::sanitizeRichText()} runs — the sanitizer sees a
 *     plain-text token, not raw markup.
 *  5. For URL attributes, substitute the raw formatted URL before
 *     {@see BlockRenderer::safeUrl()} runs — that gate enforces the actual
 *     `javascript:` / `data:` rejection.
 *  6. For ordinary string attributes (e.g. `titleAttr`, `caption`, `alt`),
 *     substitute raw text and let the existing text/attribute escaping run.
 *  7. Recursively copy `innerBlocks`. NEVER mutate the input block tree —
 *     an Eloquent model or a persisted JSON payload the caller hands in must
 *     come back with its original tokens intact, every call.
 *  8. The subject is resolved through the `text` target as plain text. MIME
 *     subjects stay raw; HTML consumers escape at their own boundary.
 *  9. Aggregate every resolution failure into ONE
 *     {@see EmailVariableResolutionException} carrying keys + safe reasons
 *     only. Runtime values, sample values, formatter-internal exception
 *     messages NEVER reach the exception's message string.
 *
 * TOKEN-AWARE ATTRIBUTES — which contract attributes participate?
 * The block's contract (loaded via {@see BlockRegistryService::getBlock()})
 * drives the decision:
 *  - `attributes.<name>.type === "rich-text"` → escape mode (the replacement
 *    is HTML-escaped before {@see BlockRenderer::sanitizeRichText()} sees it).
 *  - `attributes.<name>.type === "url"` → raw URL mode (`safeUrl()` is the
 *    gate; the interpolator substitutes the literal URL string).
 *  - `attributes.<name>.translatable === true` and not rich-text → raw text
 *    mode (the block renderer already escapes the attribute value as text;
 *    we pass the formatted replacement through verbatim). This is the path
 *    `titleAttr`, `caption`, `alt`, and similar human-language strings take.
 *
 * Anything else is ignored:
 *  - `anchor`, `extraClasses` (CSS identifier / chips input) → not substituted.
 *  - `class`, `style`, `supports.*` → never recursed into.
 *  - `innerBlocks` → recursively walked; each child gets the same treatment.
 *
 * SECURITY:
 *  - The interpolator's own escaping covers rich-text and subject; the block
 *    renderer's sanitizers cover everything else. We do NOT rely on the
 *    host for any sanitization step.
 *  - Formatter exceptions are caught and replaced with a safe
 *    `REASON_FORMATTER_FAILED` reason — the wrapped exception's message is
 *    discarded, not surfaced.
 *  - A token whose formatter's `targets()` does not include the target it
 *    is substituting into (e.g. an `email`-only formatter pushed into a text
 *    attribute) fails as `REASON_INCOMPATIBLE_TARGET`, aggregated.
 *
 * NOT IN SCOPE (Task 2 explicitly defers these):
 *  - Wiring this into {@see EmailRenderer::render()} — that's Task 3.
 *  - Threading it through the email preview / export / batch endpoints —
 *    Tasks 4 / 6.
 *  - Editor picker UI — Task 5.
 */
final class EmailVariableInterpolator
{
    /**
     * Token pattern: `{{ optional-whitespace <dotted-key> optional-whitespace }}`.
     * The pattern enforces:
     *  - the key is a conservative dotted identifier (`a-z`, digits, underscore,
     *    single dots between segments),
     *  - optional whitespace is permitted around the key,
     *  - the segment charset mirrors {@see EmailVariableRegistry::KEY_PATTERN}
     *    exactly so a host registering `user.first_name` matches the same key
     *    here that they registered there.
     */
    private const TOKEN_PATTERN = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\s*\}\}/';

    /**
     * Variable-chip pattern (panel-variables.blade.php). The chip is the inline atom the
     * Variables sidebar drags into the canvas — a `<span class="hb-var-token" contenteditable="false"
     * data-hb-var-key="...">label</span>` — and the interpolator's job is to swap it for the
     * same formatted value a `{{ key }}` text token in the same attribute would produce.
     *
     * Pattern notes:
     *  - Class match requires the literal token `hb-var-token` somewhere in the class
     *    attribute (other classes may be present; the chip CSS class is the contract).
     *  - `data-hb-var-key` carries the dotted key the registry registered. Validation of the
     *    key shape (charset, dots) is the registry's job, NOT the regex's — a malformed key
     *    flows into `definition()` and resolves to null, producing REASON_UNKNOWN_TOKEN like
     *    any other unknown key.
     *  - Inner content is matched non-greedily to the first `</span>`. The chip's label is
     *    plain text (the registry's label, or the key itself), so there's no nested-element
     *    hazard in practice — but `[\s\S]*?` is the right tool if a future change ever nests.
     *  - The chip's whole HTML element is consumed by the substitution; the formatter's
     *    output takes the chip's place. Escape policy is HTML-escape (`ENT_QUOTES | ENT_HTML5`)
     *    because the chip lives inside a rich-text attribute that {@see BlockRenderer::sanitizeRichText()}
     *    has not yet run on.
     */
    private const CHIP_PATTERN = '/<span\b[^>]*\bclass="[^"]*\bhb-var-token\b[^"]*"[^>]*\bdata-hb-var-key="([^"]+)"[^>]*>[\s\S]*?<\/span>/';

    public function __construct(
        private EmailVariableRegistry $registry,
        private BlockRegistryService $blocks,
    ) {
    }

    /**
     * Resolve tokens in a plain-text subject. HTML consumers such as
     * {@see EmailRenderer::wrapShell()} escape the result at their boundary.
     *
     * Throws {@see EmailVariableResolutionException} aggregated on the first
     * resolution failure.
     */
    public function interpolateSubject(string $subject, EmailVariableContext $context, string $locale): string
    {
        return $this->substituteInString($subject, $context, $locale, 'text', escape: false);
    }

    /**
     * Resolve a subject and block tree with one shared failure accumulator.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array{subject: string, blocks: list<array<string, mixed>>}
     * @throws EmailVariableResolutionException
     */
    public function interpolate(
        string $subject,
        array $blocks,
        EmailVariableContext $context,
        string $locale,
    ): array {
        $failures = [];
        $resolvedSubject = $this->substituteInString(
            $subject,
            $context,
            $locale,
            'text',
            false,
            $failures,
        );
        $resolvedBlocks = $this->interpolateBlockTree($blocks, $context, $locale, $failures);

        if ($failures !== []) {
            throw new EmailVariableResolutionException($failures);
        }

        return ['subject' => $resolvedSubject, 'blocks' => $resolvedBlocks];
    }

    /**
     * Resolve tokens in a copied email-block tree. The input block tree is
     * NEVER mutated; the returned tree is a deep-enough copy carrying the
     * resolved values (every nested `innerBlocks` is walked recursively).
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     *
     * @throws EmailVariableResolutionException aggregated
     */
    public function interpolateBlocks(array $blocks, EmailVariableContext $context, string $locale): array
    {
        $failures = [];
        $out = $this->interpolateBlockTree($blocks, $context, $locale, $failures);

        if ($failures !== []) {
            throw new EmailVariableResolutionException($failures);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<array{key: string, reason: string}> $failures
     * @return list<array<string, mixed>>
     */
    private function interpolateBlockTree(
        array $blocks,
        EmailVariableContext $context,
        string $locale,
        array &$failures,
    ): array {
        $out = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                $out[] = $block;
                continue;
            }

            $out[] = $this->interpolateBlock($block, $context, $locale, $failures);
        }

        return $out;
    }

    /**
     * Recurse into one block. Walks `attributes` for token-aware slots and
     * recurses into `innerBlocks`. Never mutates the input.
     *
     * @param array<string, mixed> $block
     * @param list<array{key: string, reason: string}> $failures accumulated by reference
     * @return array<string, mixed>
     */
    private function interpolateBlock(array $block, EmailVariableContext $context, string $locale, array &$failures): array
    {
        $copy = $block;

        $attributes = is_array($block['attributes'] ?? null) ? $block['attributes'] : [];
        if ($attributes !== []) {
            $copy['attributes'] = $this->interpolateAttributes($block, $attributes, $context, $locale, $failures);
        }

        $inner = $block['innerBlocks'] ?? null;
        if (is_array($inner) && $inner !== []) {
            $copiedInner = [];
            foreach ($inner as $child) {
                if (is_array($child)) {
                    $copiedInner[] = $this->interpolateBlock($child, $context, $locale, $failures);
                } else {
                    $copiedInner[] = $child;
                }
            }
            $copy['innerBlocks'] = $copiedInner;
        }

        return $copy;
    }

    /**
     * Walk the block's `attributes`, substituting tokens ONLY into token-aware
     * slots. The contract (looked up by block name) decides which slots those
     * are — see the class docblock for the full rule. Non-token-aware string
     * attributes (`anchor`, …) and ALL non-string slots (`supports`, `class`,
     * arrays) are passed through untouched.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $attributes
     * @param list<array{key: string, reason: string}> $failures accumulated by reference
     * @return array<string, mixed>
     */
    private function interpolateAttributes(array $block, array $attributes, EmailVariableContext $context, string $locale, array &$failures): array
    {
        $blockName = is_string($block['name'] ?? null) ? (string) $block['name'] : '';
        $contract = $blockName !== '' ? $this->blocks->getBlock($blockName) : null;
        $contractAttributes = is_array($contract['attributes'] ?? null) ? $contract['attributes'] : [];

        $copy = $attributes;
        foreach ($attributes as $name => $value) {
            if (! is_string($value) || $value === '') {
                // Non-string attributes (arrays, objects, null) are NEVER
                // substituted — `extraClasses`, `properties`, etc.
                continue;
            }

            $definitionName = $name;
            $localeSuffix = '_' . $locale;
            if (! isset($contractAttributes[$definitionName]) && str_ends_with($name, $localeSuffix)) {
                $candidate = substr($name, 0, -strlen($localeSuffix));
                if (is_array($contractAttributes[$candidate] ?? null)
                    && ($contractAttributes[$candidate]['translatable'] ?? false) === true) {
                    $definitionName = $candidate;
                }
            }
            $attributeDef = is_array($contractAttributes[$definitionName] ?? null) ? $contractAttributes[$definitionName] : [];
            $attributeType = is_string($attributeDef['type'] ?? null) ? (string) $attributeDef['type'] : '';
            $translatable = ($attributeDef['translatable'] ?? false) === true;

            if ($translatable
                && $definitionName === $name
                && array_key_exists($name . $localeSuffix, $attributes)) {
                continue;
            }

            $target = null;
            $escape = false;
            if ($attributeType === 'rich-text') {
                // Rich-text content: HTML-escape the formatted replacement so
                // the block renderer's sanitizeRichText() sees a plain-text
                // token, not raw markup.
                $target = 'text';
                $escape = true;
            } elseif ($attributeType === 'url') {
                // URL attribute: substitute raw, BlockRenderer::safeUrl() is
                // the gate that enforces scheme policy.
                $target = 'url';
                $escape = false;
            } elseif ($translatable) {
                // Human-language string (caption, alt, titleAttr, …):
                // substitute raw; the block renderer already escapes this as
                // a plain text attribute.
                $target = 'text';
                $escape = false;
            } else {
                // Not a token-aware slot (anchor, extraClasses as string,
                // unknown contract types, etc.). Leave it untouched.
                continue;
            }

            // Pre-scan: do any `{{ ... }}` tokens OR `<span class="hb-var-token">` chips
            // actually appear in this string? Cheap short-circuit — a no-variable attribute
            // is the common case for a long block list, and we must not allocate a $failures
            // entry for a string that was never going to fail.
            if (! preg_match(self::TOKEN_PATTERN, $value) && ! preg_match(self::CHIP_PATTERN, $value)) {
                continue;
            }

            // Chips first: each chip is a complete <span>…</span> element whose entire HTML
            // is replaced by the formatted value, so the residue that flows into the token
            // regex contains only text outside the chip. Tokens-in-chip-labels cannot happen
            // — the chip's visible label is the variable's label/key, a plain string the host
            // cannot make emit `{{ … }}` markup without going out of its way.
            $value = $this->substituteChipsInString($value, $context, $locale, $target, $failures);
            $copy[$name] = $this->substituteInString($value, $context, $locale, $target, $escape, $failures);
        }

        return $copy;
    }

    /**
     * Run {@see self::TOKEN_PATTERN} across a string, resolving each match
     * through the registry + context + formatter. Failures collected into
     * `$failures` (aggregated by the caller) — the caller is responsible for
     * deciding when to throw.
     *
     * The same routine is reused for `text` (with or without escaping) and
     * `url` substitution; only the per-replacement escaping differs. Subject
     * interpolation is the same path with `$failures` left untouched — the
     * subject call throws directly, since a failed subject is a single
     * failure path, not a per-block collection.
     *
     * @param 'text'|'url'|'email' $target
     * @param list<array{key: string, reason: string}>|null $failures when null, throw on the first failure
     */
    private function substituteInString(string $value, EmailVariableContext $context, string $locale, string $target, bool $escape, ?array &$failures = null): string
    {
        $localFailures = [];

        $replaced = (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            function (array $match) use ($context, $locale, $target, $escape, &$localFailures): string {
                $key = $match[1];

                $definition = $this->registry->definition($key);
                if ($definition === null) {
                    $localFailures[] = ['key' => $key, 'reason' => EmailVariableResolutionException::REASON_UNKNOWN_TOKEN];

                    return $match[0]; // leave the original token in place; the caller throws
                }

                // Runtime contexts MUST carry the key; sample contexts MAY fall
                // back to the registered sample (and may also carry it
                // explicitly). For runtime, missing keys fail loud here. For
                // sample, the registry's `editorMetadata()` already formats the
                // sample — interpolating a sample context uses that exact same
                // sample value, so a missing-keyed sample context also fails
                // loud (no silent empty substitution).
                if (! $context->has($key)) {
                    $localFailures[] = ['key' => $key, 'reason' => EmailVariableResolutionException::REASON_MISSING_VALUE];

                    return $match[0];
                }

                $formatterType = $this->registry->type($definition->type);
                if (! $this->isTargetCompatible($formatterType, $target)) {
                    $localFailures[] = ['key' => $key, 'reason' => EmailVariableResolutionException::REASON_INCOMPATIBLE_TARGET];

                    return $match[0];
                }

                $value = $context->get($key);

                try {
                    $formatted = $this->registry->format($definition, $value, $locale);
                } catch (Throwable) {
                    // Discard the wrapped message; the safe reason carries no
                    // host value, no formatter-internal secret, no sample.
                    $localFailures[] = ['key' => $key, 'reason' => EmailVariableResolutionException::REASON_FORMATTER_FAILED];

                    return $match[0];
                }

                if ($escape) {
                    return htmlspecialchars($formatted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                return $formatted;
            },
            $value,
        );

        if ($localFailures !== []) {
            if ($failures === null) {
                // Subject path: aggregate every failure in the string, then throw once.
                throw new EmailVariableResolutionException($localFailures);
            }
            foreach ($localFailures as $failure) {
                $failures[] = $failure;
            }
        }

        return $replaced ?? $value;
    }

    /**
     * Resolve every `<span class="hb-var-token" data-hb-var-key="…">` chip in $value to the
     * formatted value the same `{{ key }}` text token would produce. The chip is the inline atom
     * the Variables sidebar drags into the canvas (panel-variables.blade.php); its WHOLE HTML
     * element is replaced by the formatted value, then {@see BlockRenderer::sanitizeRichText()}
     * runs on the residue as it would on any other rich-text content.
     *
     * Failure modes mirror {@see self::substituteInString()} exactly (UNKNOWN_TOKEN, MISSING_VALUE,
     * INCOMPATIBLE_TARGET, FORMATTER_FAILED) — same fail-closed posture, aggregated into the
     * caller's `$failures` accumulator, never a partial substitution that silently leaves the
     * chip in place. The one policy difference: chips always run with HTML escaping (the
     * `escape` flag the rich-text attribute carries is implicit here — chips only live in rich-text
     * attributes, so target is always `text` and escape is always on).
     *
     * @param 'text'|'url'|'email' $target
     * @param list<array{key: string, reason: string}> $failures accumulated by reference
     */
    private function substituteChipsInString(
        string $value,
        EmailVariableContext $context,
        string $locale,
        string $target,
        array &$failures,
    ): string {
        $localFailures = [];

        $replaced = (string) preg_replace_callback(
            self::CHIP_PATTERN,
            function (array $match) use ($context, $locale, $target, &$localFailures): string {
                $key = $match[1];

                $definition = $this->registry->definition($key);
                if ($definition === null) {
                    $localFailures[] = ['key' => $key, 'reason' => EmailVariableResolutionException::REASON_UNKNOWN_TOKEN];

                    return $match[0];
                }

                if (! $context->has($key)) {
                    $localFailures[] = ['key' => $key, 'reason' => EmailVariableResolutionException::REASON_MISSING_VALUE];

                    return $match[0];
                }

                $formatterType = $this->registry->type($definition->type);
                if (! $this->isTargetCompatible($formatterType, $target)) {
                    $localFailures[] = ['key' => $key, 'reason' => EmailVariableResolutionException::REASON_INCOMPATIBLE_TARGET];

                    return $match[0];
                }

                try {
                    $formatted = $this->registry->format($definition, $context->get($key), $locale);
                } catch (Throwable) {
                    // Discard the wrapped message; the safe reason carries no host value, no
                    // formatter-internal secret, no sample.
                    $localFailures[] = ['key' => $key, 'reason' => EmailVariableResolutionException::REASON_FORMATTER_FAILED];

                    return $match[0];
                }

                // Rich-text target: HTML-escape so the sanitizer sees plain text.
                return htmlspecialchars($formatted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $value,
        );

        if ($localFailures !== []) {
            foreach ($localFailures as $failure) {
                $failures[] = $failure;
            }
        }

        return $replaced ?? $value;
    }

    /**
     * @param 'text'|'url'|'email' $target
     */
    private function isTargetCompatible(EmailVariableType $type, string $target): bool
    {
        return in_array($target, $type->targets(), true);
    }
}
