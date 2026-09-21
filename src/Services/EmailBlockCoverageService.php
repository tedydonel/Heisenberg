<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Mcp\Support\ContentBlockPipeline;
use Heisenberg\Rendering\BlockTreeRenderer;
use Heisenberg\Rendering\CssValueSanitizer;
use Heisenberg\Rendering\DataPath;
use Heisenberg\Support\EmailSupports;

/**
 * Answers, purely by inspecting the live block CONTRACTS (never a hardcoded block-name
 * list — a new block or a contract edit is picked up automatically), what happens to a
 * block once it is rendered on the `email` surface instead of `render` (docs/email-system.md
 * §4, {@see BlockTreeRenderer}):
 *
 *  - a block whose contract has NO `email` section at all is DROPPED — it renders as
 *    empty, not an error ({@see BlockTreeRenderer::renderJsonBlock()}).
 *    This is certain: {@see self::registryCoverage()}'s `hasEmailTemplate` is exactly the
 *    same "presence is the whole signal" test the renderer itself applies.
 *  - a block WITH an email template can still render DIFFERENTLY, because the email
 *    surface intentionally skips two things every web render applies:
 *      1. contract-level `style.className`/`style.classNames`/`supports.align` root-class
 *         injection ({@see BlockTreeRenderer::resolveClass()});
 *      2. a `color-value-or-gradient` style variable is degraded to its first colour stop
 *         ({@see CssValueSanitizer::sanitizeCssValue()}), because Outlook
 *         cannot render CSS gradients.
 *
 * This class NEVER renders anything and never changes rendering behaviour — it only
 * reasons about the same contracts and the same sanitizer the renderer already uses, so a
 * caller (the editor UI, an MCP write) can warn an author instead of leaving them to
 * discover the gap in their inbox.
 *
 * Two different questions, two different confidence levels (be honest about which):
 *
 *  - {@see self::registryCoverage()} / {@see self::uncoveredBlockNames()} answer "what CAN
 *    this block type ever do on the email surface" — a static, per-CONTRACT capability
 *    check with no document in view. `degradable` there means "this contract has AT LEAST
 *    ONE style feature that behaves differently in email if an author reaches for it" —
 *    it is not a claim that any particular instance actually does.
 *  - {@see self::documentReport()} answers "what will THIS document actually lose" — walking
 *    real placed block instances and only flagging a degradation when the instance's own
 *    attribute/support values would actually trigger it (an authored gradient, an actually-set
 *    alignment, a conditional class whose predicate the instance's own attributes satisfy).
 *    Everything documentReport() reports as `dropped` is certain (the block cannot render at
 *    all); everything under `degraded` is a real, currently-authored difference — not a mere
 *    capability — though "different" does not always mean "worse" for that specific document.
 */
final class EmailBlockCoverageService
{
    /** Mirrors {@see BlockTreeRenderer::MAX_NESTING_DEPTH} — never walk deeper than the renderer itself would. */
    private const MAX_NESTING_DEPTH = 20;

    /** Matches {@see BlockTreeRenderer::resolveClass()}'s own alignment allow-list exactly. */
    private const ALIGN_VALUES = ['left', 'center', 'right', 'wide', 'full'];

    private DataPath $dataPath;

    private CssValueSanitizer $cssValues;

    public function __construct(private BlockRegistryService $registry)
    {
        $this->dataPath = new DataPath();
        $this->cssValues = new CssValueSanitizer();
    }

    /**
     * Every registered block's static email-surface signature, keyed by block name.
     * Derived live from {@see BlockRegistryService::discover()} — the raw, unlocalized
     * scan — never a fixed list of names.
     *
     * @return array<string, array{
     *     name: string,
     *     hasEmailTemplate: bool,
     *     className: string,
     *     classNamesCount: int,
     *     alignSupport: list<string>,
     *     gradientCapableVariables: list<string>,
     *     degradable: bool,
     * }>
     */
    public function registryCoverage(): array
    {
        $out = [];
        foreach ($this->registry->discover()['blocks'] as $contract) {
            if (! is_array($contract)) {
                continue;
            }
            $name = (string) ($contract['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[$name] = $this->contractSignature($contract);
        }

        ksort($out);

        return $out;
    }

    /** @return array{name: string, hasEmailTemplate: bool, className: string, classNamesCount: int, alignSupport: list<string>, gradientCapableVariables: list<string>, degradable: bool} */
    private function contractSignature(array $contract): array
    {
        $name = (string) ($contract['name'] ?? '');
        $hasEmailTemplate = is_array($contract['email']['template'] ?? null);

        $className = (string) ($contract['style']['className'] ?? '');
        $classNames = is_array($contract['style']['classNames'] ?? null) ? $contract['style']['classNames'] : [];
        $align = is_array($contract['supports']['align'] ?? null) ? array_values(array_filter(
            $contract['supports']['align'],
            static fn ($v): bool => is_string($v)
        )) : [];

        $gradientVariables = [];
        foreach ((array) ($contract['style']['variables'] ?? []) as $variableName => $definition) {
            if (is_array($definition) && ($definition['sanitize'] ?? null) === 'color-value-or-gradient') {
                $gradientVariables[] = (string) $variableName;
            }
        }

        return [
            'name' => $name,
            'hasEmailTemplate' => $hasEmailTemplate,
            'className' => $className,
            'classNamesCount' => count($classNames),
            'alignSupport' => $align,
            'gradientCapableVariables' => $gradientVariables,
            // A CONTRACT-level capability signal only — "this block type has at least one
            // style feature the email surface behaves differently for", not "this instance
            // actually uses it". See the class docblock's confidence-level note.
            'degradable' => $gradientVariables !== [] || $align !== [] || count($classNames) > 0,
        ];
    }

    /** @return list<string> every registered block name whose contract has NO `email` template at all — certain to render empty on the email surface. */
    public function uncoveredBlockNames(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['name'],
            array_filter($this->registryCoverage(), static fn (array $row): bool => ! $row['hasEmailTemplate']),
        ));
    }

    /** Whether a single named block has an `email.template` at all. Unknown blocks report false (they render nothing on ANY surface). */
    public function isCoveredForEmail(string $blockName): bool
    {
        $contract = $this->registry->getBlock($blockName);

        return $contract !== null && is_array($contract['email']['template'] ?? null);
    }

    /**
     * Walk a document's block tree (respecting `innerBlocks`, exactly as
     * {@see BlockTreeRenderer} recurses them) and report every placed
     * instance that will be DROPPED or DEGRADED once this document is rendered for email.
     *
     * `dropped` is certain — the block's contract has no `email` section, so
     * {@see BlockTreeRenderer} renders it as nothing. `degraded` is also a
     * real, currently-authored difference (not a bare capability): each entry's `reasons`
     * names which of this INSTANCE's own values would render differently — a gradient
     * background, a set alignment, or a matched conditional class — because
     * {@see contractSignature()}'s `degradable` alone is too broad to act on (see the class
     * docblock).
     *
     * An unregistered block name (a contract that vanished after this document was saved)
     * is silently skipped — that is a different, pre-existing failure mode this service
     * has no opinion on.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array{
     *     dropped: list<array{id: string, name: string}>,
     *     degraded: list<array{id: string, name: string, reasons: list<string>}>,
     * }
     */
    public function documentReport(array $blocks): array
    {
        $dropped = [];
        $degraded = [];
        $this->walk($blocks, $dropped, $degraded);

        return ['dropped' => $dropped, 'degraded' => $degraded];
    }

    /**
     * Convenience summary over {@see self::documentReport()} — the counts and distinct block
     * names every caller (editor summary, MCP write result) actually needs to render a
     * message, without re-deriving them from the raw report every time.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array{
     *     dropped_count: int,
     *     degraded_count: int,
     *     dropped_names: list<string>,
     *     degraded_names: list<string>,
     *     report: array{dropped: list<array<string, mixed>>, degraded: list<array<string, mixed>>},
     * }
     */
    public function documentSummary(array $blocks): array
    {
        $report = $this->documentReport($blocks);

        return [
            'dropped_count' => count($report['dropped']),
            'degraded_count' => count($report['degraded']),
            'dropped_names' => array_values(array_unique(array_map(
                static fn (array $row): string => $row['name'],
                $report['dropped']
            ))),
            'degraded_names' => array_values(array_unique(array_map(
                static fn (array $row): string => $row['name'],
                $report['degraded']
            ))),
            'report' => $report,
        ];
    }

    /** Plain-English description of a `degraded` reason code — for a surface (like MCP) that has no localized strings of its own. */
    public function reasonDescription(string $reason): string
    {
        return match ($reason) {
            'gradient-background' => 'its gradient background will be flattened to a single colour',
            'align' => 'its alignment will not be applied',
            'conditional-class' => 'a conditional style it uses (e.g. hide-on-device, fill/hug sizing) will not be applied',
            default => 'it may render differently',
        };
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<array{id: string, name: string}> $dropped
     * @param list<array{id: string, name: string, reasons: list<string>}> $degraded
     */
    private function walk(array $blocks, array &$dropped, array &$degraded, int $depth = 0): void
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            return; // mirrors the renderer's own cap — nothing past it is ever rendered either
        }

        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_string($block['name'] ?? null)) {
                continue;
            }

            $name = (string) $block['name'];
            $contract = $this->registry->getBlock($name);
            if ($contract === null) {
                continue; // unregistered contract — not this service's failure mode
            }

            $id = (string) ($block['id'] ?? '');

            if (! is_array($contract['email']['template'] ?? null)) {
                $dropped[] = ['id' => $id, 'name' => $name];
            } else {
                $reasons = $this->degradationReasons($block, $contract);
                if ($reasons !== []) {
                    $degraded[] = ['id' => $id, 'name' => $name, 'reasons' => $reasons];
                }
            }

            $inner = $block['innerBlocks'] ?? null;
            if (is_array($inner) && $inner !== []) {
                $this->walk($inner, $dropped, $degraded, $depth + 1);
            }
        }
    }

    /** @return list<string> reason codes: 'gradient-background', 'align', 'conditional-class' */
    private function degradationReasons(array $block, array $contract): array
    {
        $reasons = [];

        if ($this->hasAuthoredGradient($block, $contract)) {
            $reasons[] = 'gradient-background';
        }

        if ($this->hasAuthoredAlign($block, $contract)) {
            $reasons[] = 'align';
        }

        if ($this->hasMatchedConditionalClass($block, $contract)) {
            $reasons[] = 'conditional-class';
        }

        return $reasons;
    }

    /**
     * A `color-value-or-gradient` style variable whose value, for THIS instance, actually
     * sanitizes to a different string on `email` than on `render` — i.e. a real, authored
     * gradient that {@see CssValueSanitizer} would degrade, not merely a
     * contract that supports one. Reuses the exact sanitizer the renderer calls, so this can
     * never disagree with what rendering actually does.
     */
    private function hasAuthoredGradient(array $block, array $contract): bool
    {
        foreach ((array) ($contract['style']['variables'] ?? []) as $definition) {
            if (! is_array($definition) || ($definition['sanitize'] ?? null) !== 'color-value-or-gradient') {
                continue;
            }

            $default = (string) ($definition['default'] ?? '');
            $raw = $this->resolveSource($block, (string) ($definition['source'] ?? ''));
            $value = is_scalar($raw) ? (string) $raw : '';
            if ($value === '') {
                $value = $default;
            }
            if ($value === '') {
                continue;
            }

            $renderValue = $this->cssValues->sanitizeCssValue($value, 'color-value-or-gradient', $default, 'render');
            $emailValue = $this->cssValues->sanitizeCssValue($value, 'color-value-or-gradient', $default, 'email');
            if ($renderValue !== '' && $renderValue !== $emailValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * This instance sets a `supports.align` value the contract allows but its EMAIL template
     * cannot express. The web root gets an `hb-align-*` class; an email template honours
     * alignment only where it reads `{{supports.align}}` onto a cell's `align`, which knows
     * left/center/right — so `wide`/`full`, or any value on a template with no such slot, is
     * still lost. {@see EmailSupports} is the single answer to "what does email honour".
     */
    private function hasAuthoredAlign(array $block, array $contract): bool
    {
        $allowed = is_array($contract['supports']['align'] ?? null) ? $contract['supports']['align'] : [];
        $alignment = $block['supports']['align'] ?? null;

        return $allowed !== []
            && is_string($alignment)
            && in_array($alignment, $allowed, true)
            && in_array($alignment, self::ALIGN_VALUES, true)
            && ! in_array($alignment, EmailSupports::for($contract)['align'] ?? [], true);
    }

    /**
     * At least one contract `style.classNames` binding whose `when` predicate this
     * instance's value for that attribute actually satisfies — a real, currently-visible-
     * on-web class (a visibility toggle, fill/hug sizing, a chosen animation easing, …) that
     * {@see BlockTreeRenderer::resolveClass()} never appends on the email
     * surface.
     *
     * A binding whose OWN CONTRACT DEFAULT already satisfies its predicate is skipped
     * entirely, regardless of the instance's value — e.g. the animation easing picker's
     * `animateEasing` attribute defaults to `"smooth"`, which is exactly what
     * `hb-ease-smooth`'s `equals` targets, so that class is part of every instance's BASELINE
     * appearance, not something an author's choice turned on. Matching it as a "degradation"
     * would flag nearly every instance of nearly every block — the same non-discriminating
     * noise this class's own docblock warns the registry-level `degradable` capability flag
     * already is. Every OTHER binding on that same attribute (e.g. `hb-ease-bounce`) is still
     * evaluated normally: an author who actually picks "bounce" gets flagged, one who leaves
     * the default alone does not — whether or not the hydrated instance happens to carry the
     * default value explicitly (both the editor's own `newBlockModel()` and
     * {@see ContentBlockPipeline::hydrateBlocks()} stamp every
     * declared attribute's default onto a freshly-saved block, so "explicitly stored" alone
     * cannot distinguish authored intent from an untouched default).
     *
     * Does not resolve locale-suffixed attribute overrides the way the renderer's own predicate
     * check does — harmless in practice because every classNames binding shipped today keys off
     * a plain (non-translatable) toggle or enum, never a translatable attribute.
     */
    private function hasMatchedConditionalClass(array $block, array $contract): bool
    {
        $attributes = is_array($block['attributes'] ?? null) ? $block['attributes'] : [];

        foreach ((array) ($contract['style']['classNames'] ?? []) as $binding) {
            if (! is_array($binding) || ! is_string($binding['class'] ?? null)) {
                continue;
            }

            $predicate = $binding['when'] ?? null;
            if (! is_array($predicate) || ! is_string($predicate['attribute'] ?? null)) {
                continue;
            }

            $attribute = $predicate['attribute'];
            $default = $contract['attributes'][$attribute]['default'] ?? null;
            if ($this->predicateSatisfiedBy($predicate, $default)) {
                continue; // baseline default already satisfies this class — not a differentiating signal
            }

            $value = array_key_exists($attribute, $attributes) ? $attributes[$attribute] : $default;
            if ($this->predicateSatisfiedBy($predicate, $value)) {
                return true;
            }
        }

        return false;
    }

    private function predicateSatisfiedBy(array $predicate, mixed $value): bool
    {
        return array_key_exists('equals', $predicate)
            ? $value === $predicate['equals']
            : (isset($predicate['in']) && is_array($predicate['in']) && in_array($value, $predicate['in'], true));
    }

    private function resolveSource(array $block, string $source): mixed
    {
        if (str_starts_with($source, 'supports.')) {
            return $this->dataPath->get($block['supports'] ?? [], substr($source, 9));
        }
        if (str_starts_with($source, 'attributes.')) {
            return $this->dataPath->get($block['attributes'] ?? [], substr($source, 11));
        }

        return null;
    }
}
