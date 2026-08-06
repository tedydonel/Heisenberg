<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side HTML sanitization — the XSS boundary (blueprint §5). Wraps two
 * lazily-built, cached HTMLPurifier instances: a hardened config for `html_raw`
 * blocks and the final render-cache pass, and a strict inline config for every
 * other block type.
 *
 * SECURITY-CRITICAL. Reproduced from docs/BLUEPRINT.md §5 and NOT verified
 * against live GTC source — do not loosen these allow-lists without review.
 */
class HtmlSanitizationService
{
    private ?HTMLPurifier $raw = null;

    private ?HTMLPurifier $rich = null;

    /** Final render-cache pass: the hardened config. */
    public function purify(string $html): string
    {
        if ($html === '') {
            return '';
        }

        return $this->rawPurifier()->purify($html);
    }

    /** Route by block type: only `html_raw` gets the hardened config. */
    public function purifyForBlockType(string $html, string $blockType): string
    {
        if ($html === '') {
            return '';
        }

        return match ($blockType) {
            'html_raw' => $this->rawPurifier()->purify($html),
            default => $this->richTextPurifier()->purify($html),
        };
    }

    private function rawPurifier(): HTMLPurifier
    {
        return $this->raw ??= $this->buildRawPurifier();
    }

    private function richTextPurifier(): HTMLPurifier
    {
        return $this->rich ??= $this->buildRichTextPurifier();
    }

    /** Config A — hardened, for `html_raw` (§5.2). */
    private function buildRawPurifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'strong', 'em',
            'a[href|title|target|rel]',
            'ul', 'ol', 'li',
            'blockquote',
            'h2', 'h3', 'h4', 'h5', 'h6',
            'img[src|alt|title|width|height]',
            'figure', 'figcaption',
            'pre', 'code',
            'hr',
            'div[class]', 'span[class]',
            'table', 'thead', 'tbody', 'tr',
            'th[scope|colspan|rowspan]',
            'td[colspan|rowspan]',
            'iframe[src|width|height|frameborder|allowfullscreen|loading|title]',
        ]));
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);

        // Embeds restricted to YouTube + Vimeo only.
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', '%^https://(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%');

        $config->set('HTML.TargetBlank', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Core.RemoveInvalidImg', true);
        $config->set('AutoFormat.RemoveEmpty', false);
        $config->set('AutoFormat.AutoParagraph', false);

        // Cache path MUST be configured before the custom definition.
        $this->applyCache($config);

        // The HTML 4.01 doctype doesn't know figure/figcaption; without this,
        // cold-cache sanitization strips the image wrappers.
        $config->set('HTML.DefinitionID', 'heisenberg-raw');
        $config->set('HTML.DefinitionRev', 2);
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement('figure', 'Block', 'Flow', 'Common');
            $def->addElement('figcaption', 'Block', 'Flow', 'Common');
            $def->addAttribute('iframe', 'allowfullscreen', 'Bool');
            $def->addAttribute('iframe', 'loading', 'Enum#lazy,eager,auto');
        }

        return new HTMLPurifier($config);
    }

    /**
     * Config B — strict inline, for all non-`html_raw` (§5.3).
     *
     * Deliberate extension for the block-toolbar formats:
     * u/s/code/mark tags, plus span[style] with CSS confined to
     * color/background-color (text color + highlight). Reviewed against
     * BlockRenderer::sanitizeRichText, which enforces the same envelope
     * earlier in the pipeline.
     */
    private function buildRichTextPurifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', 'p,br,strong,em,b,i,u,s,code,a[href|title|rel],ul,ol,li,span[style]');
        $config->set('CSS.AllowedProperties', ['color', 'background-color']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('AutoFormat.RemoveEmpty', true);

        $this->applyCache($config);

        // HTML 4.01 has no <mark>; register it so highlights survive the
        // backstop pass instead of being silently unwrapped.
        $config->set('HTML.DefinitionID', 'heisenberg-rich');
        $config->set('HTML.DefinitionRev', 1);
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement('mark', 'Inline', 'Inline', 'Common');
        }

        return new HTMLPurifier($config);
    }

    /**
     * Point HTMLPurifier's definition cache at a writable dir, or disable it so
     * a permissions problem never crashes sanitization (§5.4).
     */
    private function applyCache(HTMLPurifier_Config $config): void
    {
        $dir = $this->ensureCacheDirectory();

        if ($dir !== null) {
            $config->set('Cache.SerializerPath', $dir);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }
    }

    private function ensureCacheDirectory(): ?string
    {
        $path = function_exists('config')
            ? config('heisenberg.purifier_cache_path')
            : null;

        if (! is_string($path) || $path === '') {
            $path = function_exists('storage_path')
                ? storage_path('framework/cache/heisenberg-purifier')
                : sys_get_temp_dir() . '/heisenberg-purifier';
        }

        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        if (is_dir($path) && is_writable($path)) {
            return $path;
        }

        if (function_exists('logger')) {
            logger()->warning('Heisenberg: HTMLPurifier cache directory is not writable; definition cache disabled.', ['path' => $path]);
        }

        return null;
    }
}
