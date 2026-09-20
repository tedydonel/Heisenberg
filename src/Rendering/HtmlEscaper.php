<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRenderer;

/**
 * The renderer's single HTML-escaping primitive, extracted verbatim from
 * {@see BlockRenderer::escape()} so every collaborator
 * that emits text into the output shares the exact same escaping — never a
 * hand-rolled copy that could drift from it.
 */
final class HtmlEscaper
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
