<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Support;

use Heisenberg\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The trait itself is test infrastructure other suites lean on to stop pinning literal HTML/JS —
 * a bug in the parsing or selector translation would silently make every converted assertion
 * either always pass or always fail for the wrong reason, so it gets the same coverage as any
 * other shared library code.
 */
class AssertsHtmlStructureTest extends TestCase
{
    use AssertsHtmlStructure;

    private const SAMPLE = <<<'HTML'
        <!doctype html>
        <html>
        <head><title>Sample</title></head>
        <body>
            <div id="root" class="hb-panel hb-panel--open" data-hb-control="typography.fontFamily" data-hb-control-type="combobox">
                <span class="hb-section__title">Position</span>
                <span class="hb-section__title">Effects</span>
                <input name="post_title" type="text" value="Hello">
                <input name="post_published" type="checkbox" checked>
                <select name="locale"><option value="en">English</option></select>
                <textarea name="body">content</textarea>
                <button data-hb-ai-settings-open>Open</button>
                <script type="application/json" id="hb-config">{"editingLocale": "fr", "count": 3}</script>
                <script>function hbAutosaveDelayMs() { return 800; }</script>
            </div>
        </body>
        </html>
        HTML;

    // -- existence / absence -------------------------------------------------

    public function test_asserts_an_element_exists_by_class(): void
    {
        $this->assertElementExists(self::SAMPLE, '.hb-panel');
    }

    public function test_asserts_an_element_exists_by_attribute_value(): void
    {
        $this->assertElementExists(self::SAMPLE, '[data-hb-control="typography.fontFamily"]');
    }

    public function test_asserts_an_element_exists_by_boolean_attribute_presence(): void
    {
        $this->assertElementExists(self::SAMPLE, '[data-hb-ai-settings-open]');
    }

    public function test_asserts_element_missing_passes_when_truly_absent(): void
    {
        $this->assertElementMissing(self::SAMPLE, '[data-hb-ai-insert]');
    }

    public function test_asserts_element_missing_fails_when_present(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertElementMissing(self::SAMPLE, '.hb-panel');
    }

    public function test_asserts_element_exists_fails_when_absent(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertElementExists(self::SAMPLE, '.does-not-exist');
    }

    // -- counting -------------------------------------------------------------

    public function test_asserts_element_count(): void
    {
        $this->assertElementCount(self::SAMPLE, '.hb-section__title', 2);
    }

    public function test_asserts_element_count_fails_on_mismatch(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertElementCount(self::SAMPLE, '.hb-section__title', 5);
    }

    // -- attributes -------------------------------------------------------------

    public function test_asserts_attribute_presence_without_value(): void
    {
        $this->assertElementHasAttribute(self::SAMPLE, 'input[name="post_published"]', 'checked');
    }

    public function test_asserts_attribute_with_expected_value(): void
    {
        $this->assertElementHasAttribute(self::SAMPLE, '#root', 'data-hb-control-type', 'combobox');
    }

    public function test_asserts_attribute_value_mismatch_fails(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertElementHasAttribute(self::SAMPLE, '#root', 'data-hb-control-type', 'text');
    }

    public function test_asserts_attribute_missing(): void
    {
        $this->assertElementMissingAttribute(self::SAMPLE, 'input[name="post_title"]', 'disabled');
    }

    public function test_asserts_has_class_among_several(): void
    {
        $this->assertElementHasClass(self::SAMPLE, '#root', 'hb-panel--open');
    }

    public function test_asserts_missing_class(): void
    {
        $this->assertElementMissingClass(self::SAMPLE, '#root', 'hb-panel--closed');
    }

    public function test_asserts_missing_class_fails_when_present(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertElementMissingClass(self::SAMPLE, '#root', 'hb-panel--open');
    }

    // -- text content -----------------------------------------------------------

    public function test_text_contains_is_whitespace_normalized(): void
    {
        $html = '<div id="x">   Hello    <b>World</b>   </div>';

        $this->assertElementTextContains($html, '#x', 'Hello World');
    }

    public function test_text_same_normalizes_both_sides(): void
    {
        $html = "<div id=\"x\">\n   Hello\n   World  \n</div>";

        $this->assertElementTextSame($html, '#x', 'Hello World');
    }

    // -- form controls -----------------------------------------------------------

    public function test_form_control_input_type(): void
    {
        $this->assertFormControl(self::SAMPLE, 'post_title', 'text');
        $this->assertFormControl(self::SAMPLE, 'post_published', 'checkbox');
    }

    public function test_form_control_select_and_textarea(): void
    {
        $this->assertFormControl(self::SAMPLE, 'locale', 'select');
        $this->assertFormControl(self::SAMPLE, 'body', 'textarea');
    }

    public function test_form_control_type_mismatch_fails(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertFormControl(self::SAMPLE, 'post_title', 'checkbox');
    }

    // -- data extraction: JSON islands / data-* attributes ------------------------

    public function test_json_script_island_decodes(): void
    {
        $data = $this->hbJsonScript(self::SAMPLE, '#hb-config');

        $this->assertSame('fr', $data['editingLocale']);
        $this->assertSame(3, $data['count']);
    }

    public function test_data_json_attribute_decodes(): void
    {
        $html = '<div id="x" data-hb-title-by-locale="' . e(json_encode(['en' => 'Hello', 'fr' => 'Bonjour'])) . '"></div>';

        $decoded = $this->hbDataJson($html, '#x', 'data-hb-title-by-locale');

        $this->assertSame(['en' => 'Hello', 'fr' => 'Bonjour'], $decoded);
    }

    public function test_hb_attr_and_hb_text_and_hb_count_helpers(): void
    {
        $this->assertSame('combobox', $this->hbAttr(self::SAMPLE, '#root', 'data-hb-control-type'));
        $this->assertNull($this->hbAttr(self::SAMPLE, '#root', 'data-does-not-exist'));
        $this->assertSame('Position', $this->hbText(self::SAMPLE, '.hb-section__title'));
        $this->assertSame(2, $this->hbCount(self::SAMPLE, '.hb-section__title'));
    }

    // -- inline script bodies -----------------------------------------------------

    public function test_inline_scripts_excludes_json_islands_and_external_scripts(): void
    {
        $html = self::SAMPLE . '<script src="/app.js"></script>';

        $bodies = $this->hbInlineScripts($html);

        // Only the plain executable <script> should come back — not the application/json data
        // island (not JS source) and not the external <script src> (no body to inspect at all).
        $this->assertCount(1, $bodies, 'json islands and external scripts must not be treated as inline JS');
        $this->assertStringContainsString('hbAutosaveDelayMs', $bodies[0]);
    }

    public function test_assert_inline_script_matches_identifier_regex(): void
    {
        $this->assertInlineScriptMatches(self::SAMPLE, '/\bfunction\s+hbAutosaveDelayMs\s*\(/');
    }

    public function test_assert_inline_script_matches_fails_when_absent(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertInlineScriptMatches(self::SAMPLE, '/\bfunction\s+hbNoSuchFunction\s*\(/');
    }

    public function test_assert_inline_script_contains_is_whitespace_tolerant(): void
    {
        $html = "<script>const raw = (on   === null &&\n off === null) ? checked\n\t: (checked ? on : off);</script>";

        // Same tokens, different spacing/line-wrapping/indentation than the source.
        $this->assertInlineScriptContains(
            $html,
            'const raw = (on === null && off === null) ? checked : (checked ? on : off);',
        );
    }

    public function test_assert_inline_script_contains_tolerates_whitespace_inserted_at_new_positions(): void
    {
        // A formatter wrapping a short signature onto multiple lines inserts whitespace where the
        // ORIGINAL literal had none at all (immediately before "query" and before the closing
        // paren) — this must still match, since only the token sequence is the contract.
        $html = "<script>function hbSearchFonts(\n    combobox,\n    query\n) {}</script>";

        $this->assertInlineScriptContains($html, 'function hbSearchFonts(combobox, query)');
    }

    public function test_assert_inline_script_contains_fails_when_a_token_differs(): void
    {
        $this->expectException(AssertionFailedError::class);

        $html = '<script>const raw = (on === null) ? checked : off;</script>';

        $this->assertInlineScriptContains($html, 'const raw = (on === undefined) ? checked : off;');
    }

    public function test_assert_inline_script_not_contains(): void
    {
        $html = '<script>function keep() { return 1; }</script>';

        $this->assertInlineScriptNotContains($html, 'function removed() { return 2; }');
    }

    /**
     * The editor's inline JS builds markup in strings. libxml's HTML parser ends a <script> at
     * that markup-looking text, so a DOM-based reader sees only the head of the script and every
     * negative assertion about the rest passes vacuously. Script bodies must follow the browser's
     * rule instead: a script ends at `</script`, nothing else.
     */
    public function test_inline_script_bodies_are_not_truncated_at_markup_inside_js_strings(): void
    {
        $body = "var a = '<div class=\"x\">' + label + '<\\/div>';\n"
            . "var b = '</span><p>tail</p>';\n"
            . 'function afterTheMarkup() { return secretToken; }';
        $html = '<html><body><div id="app"></div><script nonce="n">' . $body . '</script></body></html>';

        $this->assertSame([$body], $this->hbInlineScripts($html));
        $this->assertInlineScriptMatches($html, '/\bfunction\s+afterTheMarkup\s*\(/');
    }

    public function test_a_negative_script_assertion_still_sees_code_after_markup_in_a_js_string(): void
    {
        $this->expectException(AssertionFailedError::class);

        $html = "<script>var a = '</span><p>x</p>'; function afterTheMarkup() { return secretToken; }</script>";

        $this->assertInlineScriptDoesNotMatch($html, '/secretToken/');
    }

    public function test_inline_scripts_skip_external_scripts_and_json_islands(): void
    {
        $html = '<script src="/app.js"></script>'
            . '<script type="application/json" id="data">{"a":1}</script>'
            . '<script>inlineOnly();</script>';

        $this->assertSame(['inlineOnly();'], $this->hbInlineScripts($html));
    }

    public function test_assert_inline_script_not_contains_fails_when_present(): void
    {
        $this->expectException(AssertionFailedError::class);

        $html = "<script>function keep() {\n    return 1;\n}</script>";

        $this->assertInlineScriptNotContains($html, 'function keep() { return 1; }');
    }

    // -- UTF-8 handling -------------------------------------------------------------

    public function test_utf8_content_survives_the_round_trip(): void
    {
        $html = '<div id="x" data-label="Créé le résumé">Le résumé complet est en français</div>';

        $this->assertElementTextContains($html, '#x', 'résumé complet est en français');
        $this->assertElementHasAttribute($html, '#x', 'data-label', 'Créé le résumé');
    }

    // -- descendant combinator ---------------------------------------------------

    public function test_descendant_combinator_selector(): void
    {
        $this->assertElementExists(self::SAMPLE, '#root .hb-section__title');
        $this->assertElementCount(self::SAMPLE, 'body div span', 2);
    }

    // -- xpath passthrough ---------------------------------------------------------

    public function test_raw_xpath_selector_is_honored(): void
    {
        $this->assertElementExists(self::SAMPLE, "//span[text()='Position']");
        $this->assertElementExists(self::SAMPLE, "xpath://span[contains(text(), 'Effects')]");
    }

    // -- the built-in fallback converter, exercised directly ------------------------

    public function test_fallback_css_to_xpath_handles_the_documented_subset(): void
    {
        $this->assertSame(
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' hb-panel ') and @id='root']",
            AssertsHtmlStructure::hbFallbackCssToXPath('div.hb-panel#root'),
        );
        $this->assertSame(
            "//*[@data-hb-control='typography.fontFamily']",
            AssertsHtmlStructure::hbFallbackCssToXPath('[data-hb-control=typography.fontFamily]'),
        );
        $this->assertSame(
            '//div//span',
            AssertsHtmlStructure::hbFallbackCssToXPath('div span'),
        );
    }

    /**
     * Selector GROUPS, the case that only ever broke where symfony/css-selector was absent.
     * assertFormControl() builds one, so on a Laravel lane whose dependency tree omits that
     * package every form-control assertion errored while passing locally. The fallback is
     * exercised directly here so the group support is covered on EVERY machine, not just the
     * ones that happen to take this branch.
     */
    public function test_fallback_css_to_xpath_supports_selector_groups(): void
    {
        $this->assertSame(
            "//input[@name='post_title'] | //select[@name='post_title'] | //*[@id='post_title']",
            AssertsHtmlStructure::hbFallbackCssToXPath('input[name="post_title"], select[name="post_title"], #post_title'),
        );
    }

    public function test_form_control_assertions_work_on_the_fallback_converter_too(): void
    {
        // The same assertion assertFormControl() makes, but forced through the fallback's
        // XPath rather than symfony/css-selector's, proving the group translation is usable.
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8"><body>' . self::SAMPLE . '</body>');
        $xpath = new \DOMXPath($dom);

        $found = $xpath->query(AssertsHtmlStructure::hbFallbackCssToXPath(
            'input[name="post_title"], select[name="post_title"], textarea[name="post_title"], #post_title'
        ));

        $this->assertNotFalse($found);
        $this->assertGreaterThan(0, $found->length);
    }

    /**
     * The two gaps that broke the PHP 8.2 CI lane (where symfony/css-selector is absent, so this
     * fallback is the only converter). Both are exercised through real DOMXPath queries, because
     * the ^= failure was not a wrong match — it produced XPath that DOMXPath rejected outright.
     */
    public function test_an_attribute_value_containing_spaces_survives_the_descendant_split(): void
    {
        // Was split on /\s+/ into '[data-hb-ai-suggest="Write', 'a', 'post"]'.
        $this->assertSame(
            "//*[@data-hb-ai-suggest='Write a post']",
            AssertsHtmlStructure::hbFallbackCssToXPath('[data-hb-ai-suggest="Write a post"]'),
        );

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8"><body><button data-hb-ai-suggest="Write a post">go</button></body>');
        $found = (new \DOMXPath($dom))->query(
            AssertsHtmlStructure::hbFallbackCssToXPath('[data-hb-ai-suggest="Write a post"]')
        );

        $this->assertNotFalse($found);
        $this->assertSame(1, $found->length);
    }

    public function test_attribute_operators_translate_to_valid_xpath(): void
    {
        // Two values chosen so that every operator below selects exactly one of them — the
        // first version of this fixture had both ending in "-ink)", so $= matched both and the
        // assertion was really testing document order.
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8"><body>'
            . '<i id="token" data-vm-name="var(--hb-t-ink)"></i>'
            . '<i id="plain" data-vm-name="plainvalue"></i>'
            . '</body>');
        $xpath = new \DOMXPath($dom);

        $cases = [
            '[data-vm-name^="var(--hb-t-"]' => ['token'],
            '[data-vm-name^="plain"]' => ['plain'],
            '[data-vm-name$="ink)"]' => ['token'],
            '[data-vm-name$="value"]' => ['plain'],
            '[data-vm-name*="hb-t"]' => ['token'],
            '[data-vm-name="plainvalue"]' => ['plain'],
        ];

        foreach ($cases as $selector => $expectedIds) {
            $expression = AssertsHtmlStructure::hbFallbackCssToXPath($selector);
            $found = @$xpath->query($expression);

            $this->assertNotFalse($found, "DOMXPath rejected [{$selector}] -> {$expression}");

            $ids = [];
            foreach ($found as $node) {
                $ids[] = $node->getAttribute('id');
            }
            $this->assertSame($expectedIds, $ids, "[{$selector}] -> {$expression}");
        }
    }

    public function test_a_bare_attribute_presence_selector_still_works(): void
    {
        $this->assertSame('//*[@data-flag]', AssertsHtmlStructure::hbFallbackCssToXPath('[data-flag]'));
    }
}
