<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The normaliser the three-way surface diff (tests/Email/ThreeWaySurfaceDiffTest.php) diffs
 * every surface pair through, so a diff reports REAL divergences instead of noise: attribute
 * insertion order (CssToInlineStyles' DOMDocument round-trip does not promise a stable order),
 * insignificant inter-tag whitespace, CSP nonces, and — the one EXPECTED difference between the
 * preview and the real sent MIME payload — the image `src` itself (a live URL on the preview,
 * a `cid:` reference on the real send).
 *
 * `self::assertNormalizerIsSound()` (exercised by
 * ThreeWaySurfaceDiffTest::test_normalizer_self_diff_is_empty) is the load-bearing proof this
 * class asks for: normalising the SAME html twice, or normalising two independently-generated-
 * but-logically-identical renders, must diff to nothing. A normaliser nobody has proven inert on
 * its own output cannot be trusted to prove two DIFFERENT surfaces equal either.
 */
final class SurfaceNormalizer
{
    /**
     * @param bool $stripImageSrc true replaces every `<img src="…">` value with a constant
     *                            placeholder — use this when comparing the preview (live URL) against
     *                            the real send (cid:) so that ONE documented difference never shows up
     *                            as a false positive. false leaves `src` alone (e.g. comparing a
     *                            surface against itself, or two renders that both used the SAME
     *                            image-reference scheme).
     */
    public static function normalize(string $html, bool $stripImageSrc = true): string
    {
        $html = self::normalizeVolatileTokens($html);

        // A FULL document (EmailRenderer's own output always starts this way — wrapShell()) is
        // loaded as-is: wrapping it in an extra <div> would make the HTML5 parser foster-parent
        // its nested <html>/<head>/<body> content out from under that wrapper, silently dropping
        // the <head>/<style> block from the comparison. A fragment (e.g. one block's own markup)
        // is wrapped so it parses as a standalone node we can walk.
        $isFullDocument = (bool) preg_match('/^\s*(<!doctype[^>]*>\s*)?<html[\s>]/i', $html);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = $isFullDocument ? $html : ('<!doctype html><html><body><div id="hb-normalize-root">' . $html . '</div></body></html>');
        $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $isFullDocument ? $dom->documentElement : $dom->getElementById('hb-normalize-root');
        if ($root === null) {
            // Fell back to nothing parseable — return the token-normalized text so the caller
            // still gets a deterministic (if coarse) comparison rather than a fatal error.
            return trim($html);
        }

        self::canonicalize($root, $stripImageSrc);

        if ($isFullDocument) {
            $out = (string) $dom->saveHTML();
        } else {
            $out = '';
            foreach (iterator_to_array($root->childNodes) as $child) {
                $out .= $dom->saveHTML($child);
            }
        }

        // Collapse the whitespace saveHTML() reintroduces between block-level tags so two
        // documents that differ only in indentation/line-wrapping compare equal.
        $out = (string) preg_replace('/>\s+</', '><', $out);
        $out = (string) preg_replace('/\s+/', ' ', $out);

        return trim($out);
    }

    /**
     * Text-level passes that must run BEFORE DOM parsing: a `cid:` token is random per render
     * (Str::random(24)), so two otherwise-identical real sends would never compare equal without
     * this. Timestamps are normalized defensively even though no current email-diff fixture emits
     * one, per this harness's own "normalise ids, nonces, timestamps" mandate.
     */
    private static function normalizeVolatileTokens(string $html): string
    {
        $html = (string) preg_replace('/cid:[a-zA-Z0-9]+@heisenberg/', 'cid:NORMALIZED', $html);

        // ISO-8601-ish timestamps, defensively — none of the current fixtures emit one.
        $html = (string) preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?/', 'TIMESTAMP', $html);

        return $html;
    }

    private static function canonicalize(DOMNode $node, bool $stripImageSrc): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                // Insignificant whitespace: collapse runs, but keep a single space so
                // "Hello</b> <b>World" doesn't fuse into "HelloWorld".
                $child->nodeValue = (string) preg_replace('/\s+/', ' ', $child->nodeValue ?? '');

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            // Volatile / editor-only attributes that must never cause a false-positive diff.
            foreach (['nonce', 'data-block-id'] as $volatile) {
                if ($child->hasAttribute($volatile)) {
                    $child->removeAttribute($volatile);
                }
            }

            if ($stripImageSrc && strtolower($child->tagName) === 'img' && $child->hasAttribute('src')) {
                $child->setAttribute('src', 'NORMALIZED_IMG_SRC');
            }

            self::sortAttributes($child);
            self::canonicalize($child, $stripImageSrc);
        }
    }

    /** Rebuild the attribute list in sorted-by-name order — DOMDocument preserves insertion order on output. */
    private static function sortAttributes(DOMElement $el): void
    {
        $attrs = [];
        foreach (iterator_to_array($el->attributes) as $attr) {
            $attrs[$attr->name] = $attr->value;
        }
        if (count($attrs) < 2) {
            return;
        }

        ksort($attrs);
        foreach (array_keys($attrs) as $name) {
            $el->removeAttribute($name);
        }
        foreach ($attrs as $name => $value) {
            $el->setAttribute($name, $value);
        }
    }

    /**
     * The self-diff proof this class's docblock describes: normalising the same HTML twice (or
     * two byte-different-but-logically-identical renders) must produce byte-identical output.
     * Returns the empty string on success, or a short description of the first divergence.
     */
    public static function selfDiffProof(string $htmlA, string $htmlB, bool $stripImageSrc = true): string
    {
        $a = self::normalize($htmlA, $stripImageSrc);
        $b = self::normalize($htmlB, $stripImageSrc);

        if ($a === $b) {
            return '';
        }

        return self::firstDivergence($a, $b);
    }

    /** A short, human-readable pointer to where two normalized strings first differ. */
    public static function firstDivergence(string $a, string $b): string
    {
        $len = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $len && $a[$i] === $b[$i]) {
            $i++;
        }

        $context = 60;
        $start = max(0, $i - $context);

        return sprintf(
            "diverge at byte %d:\n  A…%s\n  B…%s",
            $i,
            substr($a, $start, $context * 2),
            substr($b, $start, $context * 2)
        );
    }
}
