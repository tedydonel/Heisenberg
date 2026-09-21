<?php

declare(strict_types=1);

namespace Heisenberg\Support;

use Illuminate\Support\Arr;

/**
 * The subset of a contract's `supports` its EMAIL surface actually honours — what the
 * inspector's Style tab is built from on an email document.
 *
 * DERIVED from `email.template`, never declared beside it. A style control writes
 * `supports.<path>`; on email that value reaches the markup in exactly two ways:
 *
 *  - through a `style.variables` entry whose `source` is that path, IF the template reads the
 *    variable (`var(--hb-paragraph-pt, 0)`) — see BlockStyleCompiler::resolveEmailVars();
 *  - through a literal `{{supports.<path>}}` token (block alignment onto a cell's `align`);
 *  - for `layout`, through a `"flow"` inner-blocks node, which takes the section whole.
 *
 * So a path is honoured exactly when the template mentions its variable, and the panel built
 * from this can never offer a control the sent mail ignores. That was the defect: the panel
 * was built from the WEB `supports` (opacity, shadow, position, flex layout, interaction
 * states — none of which a mail client can render) while the email templates read four
 * variables, so nearly every control wrote a value nothing consumed. A hand-maintained
 * `email.supports` list would drift back into that state the first time a template changed.
 */
final class EmailSupports
{
    /**
     * @param array<string, mixed> $contract a raw contract (`email.template`) or the editor's
     *                                       client shape (BlockViewData: `emailTemplate`)
     * @return array<string, mixed> same shape as `supports` (leaf `true`s; `align` keeps its list)
     */
    public static function for(array $contract): array
    {
        $template = $contract['email']['template'] ?? $contract['emailTemplate'] ?? null;
        $declared = $contract['supports'] ?? [];
        if (! is_array($template) || ! is_array($declared)) {
            return [];
        }

        $haystack = (string) json_encode($template, JSON_UNESCAPED_SLASHES);
        $honoured = [];

        foreach ((array) ($contract['style']['variables'] ?? []) as $name => $definition) {
            $source = is_array($definition) ? (string) ($definition['source'] ?? '') : '';
            if (! str_starts_with($source, 'supports.')) {
                continue;
            }
            if (preg_match('/var\(\s*' . preg_quote((string) $name, '/') . '\s*[,)]/', $haystack) !== 1) {
                continue;
            }

            $path = substr($source, 9);
            // Only what the contract itself switches on: `border.style` is a bare `true`,
            // `spacing.margin.top` a nested one, and a block may declare a variable for a
            // support it never enabled.
            if (Arr::get($declared, $path) === true) {
                Arr::set($honoured, $path, true);
            }
        }

        // A literal `{{supports.<path>}}` token is the other kind of slot (an enum mapped onto
        // a cell's `align`/`valign`).
        preg_match_all('/\{\{\s*supports\.([a-zA-Z0-9_.]+)\s*\}\}/', $haystack, $tokens);
        foreach (array_unique($tokens[1]) as $path) {
            if ($path === 'align') {
                // wide/full are web layout concepts; a table cell's `align` knows three values.
                $align = array_values(array_intersect((array) ($declared['align'] ?? []), ['left', 'center', 'right']));
                if ($align !== []) {
                    $honoured['align'] = $align;
                }
            } elseif (Arr::get($declared, $path) === true) {
                Arr::set($honoured, $path, true);
            }
        }

        // Layout is ONE section — direction, wrap, alignment, gap travel together and are never
        // offered piecemeal. A template opts in by laying its children out with a `"flow"`
        // inner-blocks node (BlockTreeRenderer::renderEmailFlow()), which honours all of them.
        if (is_array($declared['layout'] ?? null) && preg_match('/"flow":"(blocks|cells)"/', $haystack) === 1) {
            $honoured['layout'] = $declared['layout'];
        }

        return $honoured;
    }
}
