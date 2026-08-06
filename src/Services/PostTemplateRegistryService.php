<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Discovers post-template contract JSON on disk, validates each via
 * {@see PostTemplateContractValidator}, and serves a hashed, localized
 * registry envelope — the post-template mirror of {@see BlockRegistryService}
 * (docs/post-template-schema.md). A separate registry with its own cache and
 * hash: it never touches `BlockRegistryService`'s scan or `computeHash()`.
 *
 * Templates live under `resources/templates` (overridable via
 * `config('heisenberg.template_root')` — not yet wired; see the schema doc's
 * "Wiring" section) rather than a host's view directory, exactly like blocks.
 */
class PostTemplateRegistryService
{
    public const SCHEMA_VERSION = 1;

    /** Translatable string namespace gate for {@see localize()}. */
    private const TRANSLATION_NAMESPACE = 'heisenberg::';

    /** @var array{contracts: array<string, array>, paths: array<string, array{abs: string, rel: string}>, errors: list<array{file: string, error: string}>}|null */
    private ?array $scanCache = null;

    /** Fingerprint of the file set the cache was built from — see {@see scan()}. */
    private ?string $scanFingerprint = null;

    public function __construct(
        private PostTemplateContractValidator $validator,
        private ?string $templateRootPath = null,
    ) {
    }

    /** The raw scan: valid contracts with their on-disk path keys merged in. */
    public function discover(): array
    {
        $scan = $this->scan();
        $templates = [];
        foreach ($scan['contracts'] as $name => $contract) {
            $templates[] = $contract + [
                '_absolutePath' => $scan['paths'][$name]['abs'],
                '_relativePath' => $scan['paths'][$name]['rel'],
            ];
        }

        return ['templates' => $templates, 'errors' => $scan['errors']];
    }

    /** The full registry envelope, analogous to {@see BlockRegistryService::registry()}. */
    public function registry(?string $locale = null): array
    {
        $scan = $this->scan();
        $bare = $this->sortedByName(array_values($scan['contracts']));

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'registryHash'  => $this->computeHash($bare),
            'templates'     => array_map(fn (array $c): array => $this->localizeContract($c, $locale), $bare),
            'categories'    => $this->getCategories($bare),
            'icons'         => $this->referencedIcons($bare),
            'generatedAt'   => now()->toIso8601String(),
            'errors'        => $scan['errors'],
        ];
    }

    /** Canonical, locale-stable hash of the (untranslated) contracts. */
    public function computeHash(?array $templates = null): string
    {
        $templates ??= $this->sortedByName(array_values($this->scan()['contracts']));

        $json = json_encode(
            $templates,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return 'sha256:' . hash('sha256', (string) $json);
    }

    /** @return string[] sorted, distinct category values */
    public function getCategories(?array $templates = null): array
    {
        $templates ??= array_values($this->scan()['contracts']);

        $categories = [];
        foreach ($templates as $contract) {
            if (isset($contract['category']) && is_string($contract['category'])) {
                $categories[] = $contract['category'];
            }
        }
        $categories = array_values(array_unique($categories));
        sort($categories);

        return $categories;
    }

    public function getTemplate(string $name): ?array
    {
        return $this->scan()['contracts'][$name] ?? null;
    }

    public function isTemplateKnown(string $name): bool
    {
        return isset($this->scan()['contracts'][$name]);
    }

    /** Path-traversal guard: realpath-confine a candidate file to the template root. */
    public function validatePath(string $path): bool
    {
        $root = realpath($this->rootPath());
        $real = realpath($path);

        if ($root === false || $real === false) {
            return false;
        }

        return $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    // ── internals ─────────────────────────────────────────────

    /**
     * @return array{contracts: array<string, array>, paths: array<string, array{abs: string, rel: string}>, errors: list<array{file: string, error: string}>}
     */
    private function scan(): array
    {
        $contracts = [];
        $paths = [];
        $errors = [];

        $root = $this->rootPath();
        $realRoot = realpath($root);

        if ($realRoot === false || ! is_dir($realRoot)) {
            $this->scanFingerprint = null;

            return $this->scanCache = compact('contracts', 'paths', 'errors');
        }

        $files = $this->jsonFiles($realRoot);
        sort($files);

        // Singleton-lifetime memo — self-invalidates when a template file is added,
        // removed, or edited, same rationale as BlockRegistryService::scan().
        $fingerprint = $this->fingerprint($realRoot, $files);
        if ($this->scanCache !== null && $this->scanFingerprint === $fingerprint) {
            return $this->scanCache;
        }
        $this->scanFingerprint = $fingerprint;

        foreach ($files as $file) {
            $real = realpath($file);
            if ($real === false || ! str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
                $errors[] = ['file' => $file, 'error' => 'File is outside the template root'];
                continue;
            }

            try {
                $contract = json_decode((string) @file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $errors[] = ['file' => $real, 'error' => 'Invalid JSON: ' . $e->getMessage()];
                continue;
            }

            if (! is_array($contract)) {
                $errors[] = ['file' => $real, 'error' => 'Contract is not a JSON object'];
                continue;
            }

            $result = $this->validator->validate($contract);
            if (! $result['valid']) {
                foreach ($result['errors'] as $message) {
                    $errors[] = ['file' => $real, 'error' => $message];
                }
                continue;
            }

            $name = (string) $contract['name'];
            if (isset($contracts[$name])) {
                $errors[] = ['file' => $real, 'error' => "Duplicate template name: {$name}"];
                continue;
            }

            $contracts[$name] = $contract;
            $paths[$name] = [
                'abs' => $real,
                'rel' => ltrim(str_replace($realRoot, '', $real), DIRECTORY_SEPARATOR),
            ];
        }

        return $this->scanCache = compact('contracts', 'paths', 'errors');
    }

    /** @return string[] absolute paths of every *.json under the root */
    private function jsonFiles(string $root): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'json') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /** @param string[] $files sorted absolute paths */
    private function fingerprint(string $root, array $files): string
    {
        $parts = [$root];
        foreach ($files as $file) {
            $parts[] = $file . '|' . ((int) @filemtime($file)) . '|' . ((int) @filesize($file));
        }

        return sha1(implode("\n", $parts));
    }

    private function sortedByName(array $templates): array
    {
        usort($templates, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $templates;
    }

    private function localizeContract(array $contract, ?string $locale): array
    {
        $contract['title'] = $this->localize($contract['title'] ?? null, $locale);
        $contract['description'] = $this->localize($contract['description'] ?? null, $locale);

        $capabilities = $contract['capabilities'] ?? null;
        if (is_array($capabilities)) {
            foreach (['tableOfContents' => 'title', 'readingTime' => 'label', 'postViews' => 'label', 'breadcrumbs' => 'homeLabel'] as $capability => $field) {
                if (isset($capabilities[$capability][$field])) {
                    $capabilities[$capability][$field] = $this->localize($capabilities[$capability][$field], $locale);
                }
            }
            $contract['capabilities'] = $capabilities;
        }

        return $contract;
    }

    private function localize(mixed $value, ?string $locale): mixed
    {
        if (is_string($value) && str_starts_with($value, self::TRANSLATION_NAMESPACE)) {
            return __($value, [], $locale);
        }

        return $value;
    }

    /** @return string[] sorted, distinct Lucide slugs referenced by the contracts */
    private function referencedIcons(array $templates): array
    {
        $icons = [];
        foreach ($templates as $contract) {
            if (isset($contract['icon']) && is_string($contract['icon'])) {
                $icons[] = $contract['icon'];
            }
        }
        $icons = array_values(array_unique($icons));
        sort($icons);

        return $icons;
    }

    private function rootPath(): string
    {
        if ($this->templateRootPath !== null) {
            return $this->templateRootPath;
        }

        $configured = function_exists('config') ? config('heisenberg.template_root') : null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return __DIR__ . '/../../resources/templates';
    }
}
