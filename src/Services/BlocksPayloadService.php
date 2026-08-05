<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * Validates a block-instance payload (the editor's save envelope) against the
 * live registry (blueprint §3.6). Every method returns the triple
 * `['valid' => bool, 'errors' => string[], 'errorMap' => array<dotPath, string[]>]`.
 *
 * This is the fail-closed gate that runs before persistence: an invalid batch is
 * rejected before any write.
 */
class BlocksPayloadService
{
    private const ATTRIBUTE_TYPES = [
        'string', 'boolean', 'integer', 'number', 'rich-text',
        'array', 'object', 'media', 'url', 'token',
    ];

    private const REQUIRED_BLOCK_KEYS = ['id', 'name', 'schemaVersion', 'attributes', 'supports', 'innerBlocks'];

    /** @var array<string, array>|null contracts keyed by name */
    private ?array $contractsByName = null;

    public function __construct(private BlockRegistryService $registry)
    {
    }

    /**
     * @return array{valid: bool, errors: string[], errorMap: array<string, string[]>}
     */
    public function validatePayload(array $payload): array
    {
        $errors = [];
        $errorMap = [];

        if (($payload['schemaVersion'] ?? null) !== 1) {
            $this->add($errors, $errorMap, 'schemaVersion', 'schemaVersion must be 1');
        }

        $hash = $payload['registryHash'] ?? null;
        if (! is_string($hash) || ! str_starts_with($hash, 'sha256:')) {
            $this->add($errors, $errorMap, 'registryHash', 'registryHash must be a sha256: string');
        } elseif ($hash !== $this->registry->computeHash()) {
            // Stale-schema detection: the editor's registry snapshot no longer
            // matches the live contracts (e.g. a block's attributes changed
            // server-side since the page loaded). Reject rather than silently
            // accepting a payload validated against a schema that no longer exists.
            $this->add($errors, $errorMap, 'registryHash', 'registryHash does not match the live block registry — reload the editor and retry');
        }

        if (array_key_exists('computedStyles', $payload) && ! is_string($payload['computedStyles'])) {
            $this->add($errors, $errorMap, 'computedStyles', 'computedStyles must be a string');
        }

        if (array_key_exists('autosave', $payload) && ! is_bool($payload['autosave'])) {
            $this->add($errors, $errorMap, 'autosave', 'autosave must be a boolean');
        }

        $blocks = $payload['blocks'] ?? null;
        if (! is_array($blocks)) {
            $this->add($errors, $errorMap, 'blocks', 'blocks must be an array');

            return $this->result($errors, $errorMap);
        }

        $contracts = $this->contracts();
        foreach (array_values($blocks) as $i => $block) {
            if (! is_array($block)) {
                $this->add($errors, $errorMap, "blocks.{$i}", "blocks.{$i} must be an object");
                continue;
            }
            $this->validateBlockInstance($block, $contracts, "blocks.{$i}", $errors, $errorMap, 0);
        }

        return $this->result($errors, $errorMap);
    }

    /**
     * Validates one block instance against its contract, then recurses into its
     * `innerBlocks` — each nested child is validated against its OWN contract
     * (name/schemaVersion/attributes), exactly like a top-level block. `$depth`
     * mirrors {@see BlockRenderer::renderBlockAtDepth()}: 0 for a top-level block,
     * incremented per nesting level, capped at {@see BlockRenderer::MAX_NESTING_DEPTH}
     * — the SAME limit the renderer enforces, so a payload that would silently be
     * truncated at render time is instead rejected up front, before persistence.
     *
     * @param array<string, array> $contracts
     * @param string[] $errors
     * @param array<string, string[]> $errorMap
     */
    private function validateBlockInstance(array $block, array $contracts, string $basePath, array &$errors, array &$errorMap, int $depth): void
    {
        if ($depth > BlockRenderer::MAX_NESTING_DEPTH) {
            $this->add($errors, $errorMap, $basePath,
                "{$basePath}: exceeds the maximum inner-block nesting depth (" . BlockRenderer::MAX_NESTING_DEPTH . ')');

            return; // fail closed — do not descend any further into a runaway tree
        }

        foreach (self::REQUIRED_BLOCK_KEYS as $key) {
            if (! array_key_exists($key, $block)) {
                $this->add($errors, $errorMap, $basePath, "{$basePath}: missing key '{$key}'");
            }
        }

        $name = $block['name'] ?? null;
        if (! is_string($name) || ! isset($contracts[$name])) {
            $this->add($errors, $errorMap, $basePath . '.name', "{$basePath}: Unknown block name: " . (is_string($name) ? $name : gettype($name)));

            return; // short-circuit — nothing more to validate without a contract
        }

        $contract = $contracts[$name];

        if (($block['schemaVersion'] ?? null) !== ($contract['version'] ?? null)) {
            $this->add($errors, $errorMap, $basePath . '.schemaVersion',
                "{$basePath}: schemaVersion must equal the contract version ({$contract['version']})");
        }

        if (isset($block['attributes']) && is_array($block['attributes'])) {
            $this->validateAttributes($block['attributes'], $contract, $basePath . '.attributes', $errors, $errorMap);
        }

        if (array_key_exists('supports', $block) && ! is_array($block['supports'])) {
            $this->add($errors, $errorMap, $basePath . '.supports', "{$basePath}: supports must be an array");
        }

        if (! array_key_exists('innerBlocks', $block)) {
            return; // already flagged by the required-keys check above
        }
        if (! is_array($block['innerBlocks'])) {
            $this->add($errors, $errorMap, $basePath . '.innerBlocks', "{$basePath}: innerBlocks must be an array");

            return;
        }

        foreach (array_values($block['innerBlocks']) as $i => $child) {
            $childPath = "{$basePath}.innerBlocks.{$i}";
            if (! is_array($child)) {
                $this->add($errors, $errorMap, $childPath, "{$childPath} must be an object");
                continue;
            }
            $this->validateBlockInstance($child, $contracts, $childPath, $errors, $errorMap, $depth + 1);
        }
    }

    /**
     * Only declared, present attributes are checked (missing ones fall back to
     * the contract default). Locale-suffixed keys (content_en) map to their base.
     *
     * @param string[] $errors
     * @param array<string, string[]> $errorMap
     */
    private function validateAttributes(array $attributes, array $contract, string $basePath, array &$errors, array &$errorMap): void
    {
        $declared = $contract['attributes'] ?? [];
        if (! is_array($declared)) {
            return;
        }

        foreach ($attributes as $key => $value) {
            $base = preg_replace('/_(?:[a-z]{2})$/', '', (string) $key) ?? (string) $key;
            $definition = $declared[$key] ?? $declared[$base] ?? null;
            if (! is_array($definition)) {
                continue; // unknown attribute — not rejected
            }

            $type = $definition['type'] ?? null;
            if (in_array($type, self::ATTRIBUTE_TYPES, true) && ! $this->valueMatchesType($value, $type)) {
                $this->add($errors, $errorMap, "{$basePath}.{$key}", "{$basePath}.{$key}: expected type {$type}");
                continue;
            }

            if (isset($definition['enum']) && is_array($definition['enum']) && ! in_array($value, $definition['enum'], true)) {
                $this->add($errors, $errorMap, "{$basePath}.{$key}", "{$basePath}.{$key}: value not in the allowed set");
            }
        }
    }

    private function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string', 'rich-text', 'url', 'token' => is_string($value),
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object', 'media' => is_array($value),
            default => true,
        };
    }

    /** @return array<string, array> */
    private function contracts(): array
    {
        if ($this->contractsByName !== null) {
            return $this->contractsByName;
        }

        $map = [];
        foreach ($this->registry->registry()['blocks'] as $contract) {
            if (isset($contract['name'])) {
                $map[$contract['name']] = $contract;
            }
        }

        return $this->contractsByName = $map;
    }

    /**
     * @param string[] $errors
     * @param array<string, string[]> $errorMap
     */
    private function add(array &$errors, array &$errorMap, string $path, string $message): void
    {
        $errors[] = $message;
        $errorMap[$path][] = $message;
    }

    /**
     * @param string[] $errors
     * @param array<string, string[]> $errorMap
     * @return array{valid: bool, errors: string[], errorMap: array<string, string[]>}
     */
    private function result(array $errors, array $errorMap): array
    {
        return ['valid' => $errors === [], 'errors' => $errors, 'errorMap' => $errorMap];
    }
}
