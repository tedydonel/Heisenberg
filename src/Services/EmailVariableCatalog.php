<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * Read-only metadata supplied by the host application for the email builder.
 * Heisenberg never owns values, formatters, users, or substitution.
 */
final class EmailVariableCatalog
{
    /**
     * @return list<array{key:string,label:string,description:string,group:string}>
     */
    public function definitions(): array
    {
        $definitions = (array) config('heisenberg.email.variables', []);
        $out = [];
        $seen = [];

        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $key = trim((string) ($definition['key'] ?? ''));
            if ($key === '' || isset($seen[$key]) || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $key) !== 1) {
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'key' => $key,
                'label' => trim((string) ($definition['label'] ?? $key)) ?: $key,
                'description' => trim((string) ($definition['description'] ?? '')),
                'group' => trim((string) ($definition['group'] ?? '')),
            ];
        }

        return $out;
    }

    /** @return array<string, array{key:string,label:string,description:string,group:string}> */
    public function byKey(): array
    {
        $out = [];
        foreach ($this->definitions() as $definition) {
            $out[$definition['key']] = $definition;
        }

        return $out;
    }
}
