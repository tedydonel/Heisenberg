<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRenderer;

/**
 * Dot-path lookup into a nested array (`supports.color.text` -> $data['supports']['color']['text']),
 * shared by every renderer collaborator that resolves a `supports.*`/`attributes.*` source path.
 * Extracted verbatim from {@see BlockRenderer::dataGet()}.
 */
final class DataPath
{
    public function get(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return null;
            }
        }

        return $data;
    }
}
