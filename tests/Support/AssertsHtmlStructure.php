<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Structural assertions against rendered HTML, in place of literal substring/line matching.
 *
 * WHY: `assertStringContainsString('<span class="...">Position</span>', $html)` fails the moment
 * anything cosmetic changes — attribute order, whitespace, an added wrapper class — even though
 * the thing the test actually cares about (a "Position" section exists) is unchanged. This trait
 * parses the HTML ONCE into a DOMDocument/DOMXPath pair and offers assertions that name the
 * INTENT (an element exists / is missing / appears N times / carries an attribute or text) so the
 * assertion only fails when that intent is actually violated.
 *
 * Selectors accept a pragmatic CSS subset — tag, #id, .class, [attr], [attr=value], descendant
 * combinator (space) — translated to XPath via symfony/css-selector when it is autoloadable
 * (it ships transitively through Laravel's own dev/test dependencies; this package does NOT
 * declare it directly) and via a small built-in fallback converter otherwise. A selector may also
 * be given as a raw XPath expression by prefixing it with "xpath:", or by simply starting the
 * string with "/" or "(" (already unambiguous XPath syntax).
 *
 * This trait intentionally does NOT provide a "assert this JS source line is present" helper.
 * Prefer, in order:
 *   1. Assert the DATA the server handed to the script — a data-* attribute, or a JSON island
 *      (see hbJsonScript()/hbDataJson()) — rather than the code that reads it.
 *   2. If behaviour genuinely lives only in inline JS with no server-side data to key off, assert
 *      the presence of a named function/identifier via a tolerant regex (see hbInlineScripts()
 *      to fetch just the script bodies, keeping the regex out of the surrounding markup).
 *   3. Only pin an exact literal line when the literal wording IS the contract (e.g. a security
 *      allow-list constant, an exact error message another layer matches against).
 */
trait AssertsHtmlStructure
{
    /** @var array<string, DOMDocument> keyed by a hash of the source HTML, so repeated calls in one test reuse the parse. */
    private array $hbDomCache = [];

    /** @var array<string, DOMXPath> */
    private array $hbXPathCache = [];

    // ---------------------------------------------------------------------
    // Assertions
    // ---------------------------------------------------------------------

    public function assertElementExists(string $html, string $selector, string $message = ''): void
    {
        $count = $this->hbQuery($html, $selector)->length;

        $this->assertGreaterThan(
            0,
            $count,
            $message !== '' ? $message : "Failed asserting that an element matching [{$selector}] exists in the given HTML.",
        );
    }

    public function assertElementMissing(string $html, string $selector, string $message = ''): void
    {
        $count = $this->hbQuery($html, $selector)->length;

        $this->assertSame(
            0,
            $count,
            $message !== '' ? $message : "Failed asserting that no element matching [{$selector}] exists in the given HTML (found {$count}).",
        );
    }

    public function assertElementCount(string $html, string $selector, int $expected, string $message = ''): void
    {
        $count = $this->hbQuery($html, $selector)->length;

        $this->assertSame(
            $expected,
            $count,
            $message !== '' ? $message : "Failed asserting that [{$selector}] matches {$expected} element(s) (found {$count}).",
        );
    }

    /**
     * Asserts the FIRST element matching $selector carries $attribute. When $expected is given,
     * also asserts the attribute's value equals it exactly; pass null (default) to only require
     * the attribute's presence, regardless of value.
     */
    public function assertElementHasAttribute(string $html, string $selector, string $attribute, ?string $expected = null, string $message = ''): void
    {
        $node = $this->hbFirst($html, $selector, $message !== '' ? $message : "Failed asserting that an element matching [{$selector}] exists.");

        $this->assertTrue(
            $node->hasAttribute($attribute),
            $message !== '' ? $message : "Failed asserting that the element matching [{$selector}] has the [{$attribute}] attribute.",
        );

        if ($expected !== null) {
            $this->assertSame(
                $expected,
                $node->getAttribute($attribute),
                $message !== '' ? $message : "Failed asserting that [{$selector}]'s [{$attribute}] attribute equals '{$expected}'.",
            );
        }
    }

    /** Asserts the first element matching $selector does NOT carry $attribute at all. */
    public function assertElementMissingAttribute(string $html, string $selector, string $attribute, string $message = ''): void
    {
        $node = $this->hbFirst($html, $selector, $message !== '' ? $message : "Failed asserting that an element matching [{$selector}] exists.");

        $this->assertFalse(
            $node->hasAttribute($attribute),
            $message !== '' ? $message : "Failed asserting that the element matching [{$selector}] has no [{$attribute}] attribute.",
        );
    }

    /** Asserts the first element matching $selector carries $class among (possibly several) space-separated classes. */
    public function assertElementHasClass(string $html, string $selector, string $class, string $message = ''): void
    {
        $node = $this->hbFirst($html, $selector, $message !== '' ? $message : "Failed asserting that an element matching [{$selector}] exists.");
        $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];

        $this->assertContains(
            $class,
            $classes,
            $message !== '' ? $message : "Failed asserting that [{$selector}] carries the class '{$class}' (has: " . implode(' ', $classes) . ').',
        );
    }

    /** Asserts the first element matching $selector does NOT carry $class. */
    public function assertElementMissingClass(string $html, string $selector, string $class, string $message = ''): void
    {
        $node = $this->hbFirst($html, $selector, $message !== '' ? $message : "Failed asserting that an element matching [{$selector}] exists.");
        $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];

        $this->assertNotContains(
            $class,
            $classes,
            $message !== '' ? $message : "Failed asserting that [{$selector}] does not carry the class '{$class}'.",
        );
    }

    /** Whitespace-normalized (collapsed runs of whitespace, trimmed) text-content containment check. */
    public function assertElementTextContains(string $html, string $selector, string $text, string $message = ''): void
    {
        $node = $this->hbFirst($html, $selector, $message !== '' ? $message : "Failed asserting that an element matching [{$selector}] exists.");

        $this->assertStringContainsString(
            self::hbNormalizeWhitespace($text),
            self::hbNormalizeWhitespace($node->textContent),
            $message !== '' ? $message : "Failed asserting that [{$selector}]'s text contains '{$text}'.",
        );
    }

    /** Exact (whitespace-normalized) text-content equality, for when "contains" is too loose. */
    public function assertElementTextSame(string $html, string $selector, string $text, string $message = ''): void
    {
        $node = $this->hbFirst($html, $selector, $message !== '' ? $message : "Failed asserting that an element matching [{$selector}] exists.");

        $this->assertSame(
            self::hbNormalizeWhitespace($text),
            self::hbNormalizeWhitespace($node->textContent),
            $message,
        );
    }

    /**
     * Asserts a form control identified by name= or id= of $nameOrId exists with the given $type:
     * for an <input>, compares the `type` attribute (defaulting to the HTML-implied "text" when
     * absent); "select" and "textarea" match those tags directly regardless of any type attribute.
     */
    public function assertFormControl(string $html, string $nameOrId, string $type, string $message = ''): void
    {
        $selector = "input[name=\"{$nameOrId}\"], select[name=\"{$nameOrId}\"], textarea[name=\"{$nameOrId}\"], "
            . "#{$nameOrId}";
        $matches = $this->hbQuery($html, $selector);

        $this->assertGreaterThan(
            0,
            $matches->length,
            $message !== '' ? $message : "Failed asserting that a form control named or identified '{$nameOrId}' exists.",
        );

        $tag = strtolower($matches->item(0)->nodeName);
        $actualType = $tag === 'input'
            ? (($matches->item(0) instanceof DOMElement ? $matches->item(0)->getAttribute('type') : '') ?: 'text')
            : $tag;

        $this->assertSame(
            strtolower($type),
            strtolower($actualType),
            $message !== '' ? $message : "Failed asserting that '{$nameOrId}' is a '{$type}' control (found '{$actualType}').",
        );
    }

    // ---------------------------------------------------------------------
    // Data extraction helpers — prefer these over asserting on JS source.
    // ---------------------------------------------------------------------

    /**
     * Decodes a `<script type="application/json">` data island. $selector defaults to matching
     * any application/json script; pass a more specific selector (e.g. carrying an id) when the
     * page has more than one island.
     *
     * @return mixed the json_decode()'d value (associative arrays for objects)
     */
    public function hbJsonScript(string $html, string $selector = 'script[type="application/json"]'): mixed
    {
        $node = $this->hbFirst($html, $selector, "No <script type=\"application/json\"> matched [{$selector}].");

        return json_decode($node->textContent, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Decodes a JSON-valued data-* (or any) attribute on the first element matching $selector. */
    public function hbDataJson(string $html, string $selector, string $attribute): mixed
    {
        $node = $this->hbFirst($html, $selector, "No element matched [{$selector}] while reading [{$attribute}].");

        if (! $node->hasAttribute($attribute)) {
            throw new \RuntimeException("Element matching [{$selector}] has no [{$attribute}] attribute to decode as JSON.");
        }

        return json_decode($node->getAttribute($attribute), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Raw attribute value (already HTML-entity-decoded by the DOM parser) of the first match, or null. */
    public function hbAttr(string $html, string $selector, string $attribute): ?string
    {
        $node = $this->hbQuery($html, $selector)->item(0);
        if (! $node instanceof DOMElement || ! $node->hasAttribute($attribute)) {
            return null;
        }

        return $node->getAttribute($attribute);
    }

    /** Whitespace-normalized text content of the first element matching $selector, or null if none matches. */
    public function hbText(string $html, string $selector): ?string
    {
        $node = $this->hbQuery($html, $selector)->item(0);

        return $node === null ? null : self::hbNormalizeWhitespace($node->textContent);
    }

    /** Number of elements matching $selector. */
    public function hbCount(string $html, string $selector): int
    {
        return $this->hbQuery($html, $selector)->length;
    }

    /**
     * The text bodies of every inline `<script>` in $html (elements with no `src`), in document
     * order — for a tolerant regex against an identifier/function name, scoped to actual script
     * code rather than the surrounding markup.
     *
     * @return list<string>
     */
    public function hbInlineScripts(string $html): array
    {
        // Read from the RAW markup, deliberately NOT from the DOM. libxml's HTML4 parser ends a
        // <script> at markup-looking text inside a JS string (`'<\/div>'`, `'</span>'`), where a
        // browser ends it only at `</script` — on the real /editor page that silently cut 5 of
        // 59 scripts short, two of them by ~75%. A positive assertion then fails loudly, but a
        // NEGATIVE one (assertInlineScriptDoesNotMatch) passes vacuously over the missing text,
        // which is the dangerous direction. This regex is the browser's rule.
        preg_match_all('#<script\b([^>]*)>(.*?)</script\s*>#is', $html, $matches, PREG_SET_ORDER);

        $bodies = [];
        foreach ($matches as [, $attributes, $body]) {
            // Excludes external scripts (src=) and JSON data islands (type="application/json") —
            // neither is "inline JS source" in the sense this helper exists for.
            if (preg_match('/(?:^|\s)src\s*=/i', $attributes) === 1
                || preg_match('#type\s*=\s*["\']?application/json#i', $attributes) === 1) {
                continue;
            }

            $bodies[] = $body;
        }

        return $bodies;
    }

    /** True if any inline script body matches $pattern (a full preg_match() pattern, delimiters included). */
    public function assertInlineScriptMatches(string $html, string $pattern, string $message = ''): void
    {
        foreach ($this->hbInlineScripts($html) as $body) {
            if (preg_match($pattern, $body) === 1) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail($message !== '' ? $message : "Failed asserting that any inline <script> matches {$pattern}.");
    }

    /** Asserts NO inline script body matches $pattern — the negative counterpart of assertInlineScriptMatches(). */
    public function assertInlineScriptDoesNotMatch(string $html, string $pattern, string $message = ''): void
    {
        foreach ($this->hbInlineScripts($html) as $body) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $body,
                $message !== '' ? $message : "Failed asserting that no inline <script> matches {$pattern}.",
            );
        }
    }

    /**
     * Asserts an inline script contains $snippet, tolerant of reformatting: the snippet is split
     * on whitespace, each token is matched literally, and any run of whitespace between tokens
     * (spaces, wrapped lines, reindentation) may vary freely. Prefer this over pinning a whole
     * line of JS verbatim — exact spacing/semicolon placement is rarely the thing a test means to
     * protect; the sequence of tokens (identifiers, operators, punctuation) usually is.
     */
    public function assertInlineScriptContains(string $html, string $snippet, string $message = ''): void
    {
        $this->assertInlineScriptMatches(
            $html,
            self::hbWhitespaceTolerantPattern($snippet),
            $message !== '' ? $message : "Failed asserting that an inline <script> contains (whitespace-tolerant): {$snippet}",
        );
    }

    /** The negative counterpart of assertInlineScriptContains(). */
    public function assertInlineScriptNotContains(string $html, string $snippet, string $message = ''): void
    {
        $this->assertInlineScriptDoesNotMatch(
            $html,
            self::hbWhitespaceTolerantPattern($snippet),
            $message !== '' ? $message : "Failed asserting that no inline <script> contains: {$snippet}",
        );
    }

    /**
     * Builds a preg pattern from a literal code snippet that tolerates ANY reformatting of the
     * whitespace AROUND tokens — including whitespace inserted where the original had none (e.g.
     * a function signature wrapped one argument per line) — while still requiring the exact same
     * identifiers/numbers/punctuation in the exact same order.
     *
     * Splits the literal into "word" tokens (identifiers/numbers) and single-character
     * "punctuation" tokens (operators, brackets, quotes, commas, …). Two consecutive WORD tokens
     * must still be separated by at least one whitespace character (otherwise "return x" could
     * wrongly match a subject containing "returnx"); everywhere else — word/punctuation,
     * punctuation/word, punctuation/punctuation — whitespace is optional, since code never
     * relies on it there (e.g. "===" or "query)" may or may not have a line break inserted by a
     * formatter without changing meaning).
     */
    private static function hbWhitespaceTolerantPattern(string $literal): string
    {
        preg_match_all('/[A-Za-z_$][A-Za-z0-9_$]*|[0-9]+(?:\.[0-9]+)?|\S/us', $literal, $m);
        $tokens = $m[0];

        $isWord = static fn (string $token): bool => preg_match('/^[A-Za-z_$0-9]/', $token) === 1;

        $pattern = '';
        foreach ($tokens as $i => $token) {
            if ($i > 0) {
                $pattern .= ($isWord($tokens[$i - 1]) && $isWord($token)) ? '\s+' : '\s*';
            }
            $pattern .= preg_quote($token, '/');
        }

        return '/' . $pattern . '/s';
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function hbFirst(string $html, string $selector, string $failureMessage): DOMElement
    {
        $node = $this->hbQuery($html, $selector)->item(0);

        if (! $node instanceof DOMElement) {
            $this->fail($failureMessage);
        }

        return $node;
    }

    private function hbQuery(string $html, string $selector): \DOMNodeList
    {
        $xpath = $this->hbXPath($html);
        $expression = $this->hbSelectorToXPath($selector);

        $result = @$xpath->query($expression);

        if ($result === false) {
            throw new \RuntimeException("Invalid selector [{$selector}] (resolved XPath: {$expression}).");
        }

        return $result;
    }

    private function hbSelectorToXPath(string $selector): string
    {
        $trimmed = trim($selector);

        if (str_starts_with($trimmed, 'xpath:')) {
            return substr($trimmed, strlen('xpath:'));
        }

        // Already unambiguous XPath.
        if ($trimmed !== '' && ($trimmed[0] === '/' || $trimmed[0] === '(')) {
            return $trimmed;
        }

        if (class_exists(CssSelectorConverter::class)) {
            static $converter = null;
            $converter ??= new CssSelectorConverter();

            // CssSelectorConverter emits paths relative to the document root context node
            // (descendant-or-self::*/...), which is exactly what we want when querying from
            // the DOMDocument itself.
            return $converter->toXPath($trimmed);
        }

        return self::hbFallbackCssToXPath($trimmed);
    }

    /**
     * Minimal CSS-selector-subset -> XPath converter, used only when symfony/css-selector is not
     * autoloadable. Supports: tag, #id, .class, [attr], [attr=value] / [attr="value"], chained on
     * one compound (e.g. tag.class[attr=value]), and the descendant combinator (plain whitespace).
     * Deliberately does NOT support combinators (>, +, ~), pseudo-classes, or attribute operators
     * other than exact match — reach for symfony/css-selector (already a transitive dependency
     * here) for anything past this.
     */
    public static function hbFallbackCssToXPath(string $selector): string
    {
        // Selector GROUPS ("a, b, c") first: symfony/css-selector handles these natively, so
        // until this fallback did too, any grouped selector threw here — but ONLY on a machine
        // where symfony/css-selector was absent. It ships as a transitive dependency of some
        // Laravel versions and not others, so assertFormControl() (which builds an
        // input/select/textarea/#id group) passed locally and errored on other CI lanes.
        $group = array_filter(array_map('trim', explode(',', trim($selector))), 'strlen');
        if (count($group) > 1) {
            return implode(' | ', array_map([self::class, 'hbFallbackCssToXPath'], $group));
        }

        $compounds = preg_split('/\s+/', trim((string) reset($group))) ?: [];
        $steps = array_map([self::class, 'hbFallbackCompoundToXPathStep'], $compounds);

        return '//' . implode('//', $steps);
    }

    private static function hbFallbackCompoundToXPathStep(string $compound): string
    {
        // Tokenize a compound selector into: optional tag, then any number of #id/.class/[attr...]
        // parts. Each alternative is anchored on its own distinct leading character (., #, [) and
        // is greedy, so — unlike matching .class/#id/[attr] separately against the whole $rest —
        // a "." inside a bracketed attribute VALUE (e.g. [data-x=typography.fontFamily]) can never
        // be mistaken for a class selector: it is already consumed as part of the single [...] token.
        if (! preg_match('/^([a-zA-Z][a-zA-Z0-9_-]*|\*)?((?:\.[a-zA-Z0-9_-]+|#[a-zA-Z0-9_-]+|\[[^\]]+\])*)$/', $compound, $m)) {
            throw new \RuntimeException("Unsupported selector fragment: '{$compound}'.");
        }

        $tag = ($m[1] ?? '') !== '' ? $m[1] : '*';
        $rest = $m[2] ?? '';

        preg_match_all('/\.[a-zA-Z0-9_-]+|#[a-zA-Z0-9_-]+|\[[^\]]+\]/', $rest, $tokens);

        $conditions = [];
        foreach ($tokens[0] as $token) {
            if ($token[0] === '.') {
                $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . substr($token, 1) . " ')";

                continue;
            }
            if ($token[0] === '#') {
                $conditions[] = "@id='" . substr($token, 1) . "'";

                continue;
            }

            // [name] or [name=value] / [name="value"] / [name='value'] — value may itself
            // legally contain "=" or ".", so split on the FIRST "=" only.
            $inner = substr($token, 1, -1);
            if (! str_contains($inner, '=')) {
                $conditions[] = "@{$inner}";

                continue;
            }
            [$name, $value] = explode('=', $inner, 2);
            $conditions[] = "@{$name}='" . trim($value, "\"'") . "'";
        }

        return $tag . ($conditions !== [] ? '[' . implode(' and ', $conditions) . ']' : '');
    }

    private function hbXPath(string $html): DOMXPath
    {
        $key = md5($html);
        if (isset($this->hbXPathCache[$key])) {
            return $this->hbXPathCache[$key];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        // DOMDocument::loadHTML() otherwise assumes ISO-8859-1 unless the markup carries an
        // explicit <meta charset>, which mangles multi-byte UTF-8 (e.g. French "é") into mojibake.
        // Prepending an XML encoding declaration is the standard libxml trick to force UTF-8
        // interpretation without altering the parsed tree (libxml consumes the declaration as
        // encoding metadata rather than emitting it as a node).
        $prefixed = '<?xml encoding="UTF-8">' . $html;

        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_PARSEHUGE lifts libxml's default ~255-level nesting / node-size guard —
            // the editor page nests panels many levels deep and is several MB of markup, and
            // without this flag libxml silently truncates the tree partway through, which
            // reads as elements "not existing" for every selector past the truncation point.
            $dom->loadHTML($prefixed, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_PARSEHUGE);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($dom);

        $this->hbDomCache[$key] = $dom;
        $this->hbXPathCache[$key] = $xpath;

        return $xpath;
    }

    private static function hbNormalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
