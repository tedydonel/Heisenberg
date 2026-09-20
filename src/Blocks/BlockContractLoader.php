<?php

declare(strict_types=1);

namespace Heisenberg\Blocks;

use FilesystemIterator;
use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Support\AnimationCatalog;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Discovers block-contract JSON on disk, validates each via {@see BlockContractValidator},
 * and expands declared engine capabilities into the contract (§3.4). Extracted verbatim
 * from {@see BlockRegistryService}, whose façade owns caching
 * invalidation semantics for its callers; this class owns the scan itself.
 */
final class BlockContractLoader
{
    /** @var array{contracts: array<string, array>, paths: array<string, array{abs: string, rel: string}>, errors: list<array{file: string, error: string}>}|null */
    private ?array $scanCache = null;

    /** Fingerprint of the file set the cache was built from — see {@see scan()}. */
    private ?string $scanFingerprint = null;

    public function __construct(
        private BlockContractValidator $validator,
        private BlockPathGuard $pathGuard,
    ) {
    }

    /**
     * @return array{contracts: array<string, array>, paths: array<string, array{abs: string, rel: string}>, errors: list<array{file: string, error: string}>}
     */
    public function scan(): array
    {
        $contracts = [];
        $paths = [];
        $errors = [];

        $root = $this->pathGuard->rootPath();
        $realRoot = realpath($root);

        if ($realRoot === false || ! is_dir($realRoot)) {
            $this->scanFingerprint = null;

            return $this->scanCache = compact('contracts', 'paths', 'errors');
        }

        $files = $this->jsonFiles($realRoot);
        sort($files);

        // The cache is a singleton-lifetime memo, so it must self-invalidate when a
        // contract file is added, removed, or edited — otherwise persistent-worker
        // runtimes (Octane, queue workers) keep serving a stale registry forever.
        $fingerprint = $this->fingerprint($realRoot, $files);
        if ($this->scanCache !== null && $this->scanFingerprint === $fingerprint) {
            return $this->scanCache;
        }
        $this->scanFingerprint = $fingerprint;

        foreach ($files as $file) {
            $real = realpath($file);
            if ($real === false || ! str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
                $errors[] = ['file' => $file, 'error' => 'File is outside the block root'];

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
                $errors[] = ['file' => $real, 'error' => "Duplicate block name: {$name}"];

                continue;
            }

            $contracts[$name] = $this->applyCapabilities($contract);
            $paths[$name] = [
                'abs' => $real,
                'rel' => ltrim(str_replace($realRoot, '', $real), DIRECTORY_SEPARATOR),
            ];
        }

        return $this->scanCache = compact('contracts', 'paths', 'errors');
    }

    /**
     * Expand declared engine capabilities into the contract. Runs at scan
     * time (post-validation) so the renderer, the payload validator and the
     * client registry all see the same expanded contract.
     *
     * `supports.animation: true` — the whole animation kit derives from
     * AnimationCatalog: the animate/duration/delay/easing/once attributes
     * (with inspector controls), the per-preset + easing classNames, the
     * duration/delay style variables, and the runtime data attributes on
     * the template root. Contracts never hand-write animation plumbing.
     */
    private function applyCapabilities(array $contract): array
    {
        if (($contract['supports']['animation'] ?? false) !== true) {
            return $contract;
        }

        $attributes = is_array($contract['attributes'] ?? null) ? $contract['attributes'] : [];
        if (! isset($attributes['animate'])) {
            $entranceKeys = AnimationCatalog::keys();
            $show = ['attribute' => 'animate', 'in' => $entranceKeys];
            $attributes['animate'] = [
                'type' => 'string', 'default' => '', 'sanitize' => 'text',
                'enum' => array_merge([''], AnimationCatalog::keys()),
                'control' => [
                    'type' => 'select', 'section' => 'animation', 'label' => 'Animation',
                    'options' => AnimationCatalog::options(),
                ],
            ];
            $attributes['animateDuration'] = [
                'type' => 'integer', 'default' => AnimationCatalog::DEFAULT_DURATION, 'sanitize' => 'integer',
                'control' => [
                    'type' => 'range', 'section' => 'animation', 'label' => 'Duration (ms)',
                    'min' => 100, 'max' => 3000, 'step' => 50, 'showWhen' => $show,
                ],
            ];
            $attributes['animateDelay'] = [
                'type' => 'integer', 'default' => AnimationCatalog::DEFAULT_DELAY, 'sanitize' => 'integer',
                'control' => [
                    'type' => 'range', 'section' => 'animation', 'label' => 'Delay (ms)',
                    'min' => 0, 'max' => 3000, 'step' => 50, 'showWhen' => $show,
                ],
            ];
            $attributes['animateEasing'] = [
                'type' => 'string', 'default' => AnimationCatalog::DEFAULT_EASING, 'sanitize' => 'text',
                'enum' => AnimationCatalog::easingKeys(),
                'control' => [
                    'type' => 'select', 'section' => 'animation', 'label' => 'Easing',
                    'options' => AnimationCatalog::easingOptions(), 'showWhen' => $show,
                ],
            ];
            $attributes['animateOnce'] = [
                'type' => 'boolean', 'default' => true, 'sanitize' => 'boolean',
                'control' => [
                    'type' => 'toggle', 'section' => 'animation', 'label' => 'Play once',
                    'showWhen' => $show,
                ],
            ];
        }
        $contract['attributes'] = $attributes;

        $classNames = is_array($contract['style']['classNames'] ?? null) ? $contract['style']['classNames'] : [];
        foreach (AnimationCatalog::keys() as $key) {
            $classNames[] = ['class' => 'hb-anim-' . $key, 'when' => ['attribute' => 'animate', 'equals' => $key]];
        }
        foreach (AnimationCatalog::easingKeys() as $key) {
            $classNames[] = ['class' => 'hb-ease-' . $key, 'when' => ['attribute' => 'animateEasing', 'equals' => $key]];
        }
        $contract['style']['classNames'] = $classNames;

        $variables = is_array($contract['style']['variables'] ?? null) ? $contract['style']['variables'] : [];
        $variables['--hb-anim-dur'] = [
            'source' => 'attributes.animateDuration',
            'default' => (string) AnimationCatalog::DEFAULT_DURATION,
            'sanitize' => 'integer',
        ];
        $variables['--hb-anim-delay'] = [
            'source' => 'attributes.animateDelay',
            'default' => (string) AnimationCatalog::DEFAULT_DELAY,
            'sanitize' => 'integer',
        ];
        $contract['style']['variables'] = $variables;

        if (is_array($contract['render']['template'] ?? null)) {
            $template = $contract['render']['template'];
            $templateAttributes = is_array($template['attributes'] ?? null) ? $template['attributes'] : [];
            $templateAttributes['data-hb-anim'] = ['value' => '{{attributes.animate}}', 'omitWhenEmpty' => true];
            $templateAttributes['data-hb-anim-once'] = ['boolean' => '{{attributes.animateOnce}}'];
            $template['attributes'] = $templateAttributes;
            $contract['render']['template'] = $template;
        }

        return $contract;
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
}
