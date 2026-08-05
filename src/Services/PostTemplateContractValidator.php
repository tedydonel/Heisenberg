<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * Validates post-template *contract* definitions (not a specific post's
 * rendered output — there is no renderer in this milestone). Pure and
 * stateless, deliberately mirroring {@see BlockContractValidator}'s shape and
 * ordered-pass style (docs/post-template-schema.md) so an adopter who has
 * learned the block contract recognises this immediately.
 *
 * Runs four validators in order: required-keys -> identity -> render ->
 * capabilities. Returns `['valid' => bool, 'errors' => string[]]`.
 *
 * Unlike a block contract, a post template is never "instantiated" with
 * per-use attribute values — it is picked by name once per post, like a
 * layout. So there is deliberately no `attributes`/`supports`/`innerBlocks`/
 * `serialization`/`security` here (see the schema doc's "Divergences from the
 * block contract" section for the full reasoning).
 */
class PostTemplateContractValidator
{
    /** The 11 mandatory top-level keys (docs/post-template-schema.md §1). */
    private const REQUIRED_TOP_LEVEL_KEYS = [
        '$schema', 'apiVersion', 'name', 'title', 'category', 'icon',
        'description', 'keywords', 'version', 'capabilities', 'render',
    ];

    /**
     * The 11 recognized capability keys (docs/post-template-schema.md §2).
     * Every key is OPTIONAL inside `capabilities` — an absent key behaves as
     * `{"enabled": false}`. This is the schema's forward-compatibility rule:
     * a future 12th capability is additive and never invalidates an
     * on-disk template written against an earlier package version. An
     * unrecognized key, however, is always rejected (catches typos and
     * version skew the other way).
     */
    private const CAPABILITY_KEYS = [
        'tableOfContents', 'featuredImage', 'readingTime', 'authorBox',
        'shareButtons', 'breadcrumbs', 'pagination',
        'postViews', 'comments', 'relatedPosts', 'seoMeta',
    ];

    private const SHARE_NETWORKS = ['x', 'facebook', 'linkedin', 'email', 'copy-link', 'whatsapp', 'pinterest'];

    private const AUTHOR_BOX_FIELDS = ['name', 'avatar', 'bio'];

    private const SEO_FIELDS = ['title', 'description', 'canonical', 'ogImage', 'robots'];

    private const PAGINATION_MODES = ['prev-next', 'numbered'];

    private const COMMENT_SORT_ORDERS = ['newest', 'oldest'];

    private const TOC_SOURCES = ['headings'];

    private const FEATURED_IMAGE_SOURCES = ['first-image-block'];

    public function __construct(private string $templatePrefix = 'heisenberg')
    {
    }

    /**
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(array $contract): array
    {
        $errors = [];

        $this->validateRequiredKeys($contract, $errors);
        $this->validateIdentity($contract, $errors);
        $this->validateRender($contract, $errors);
        $this->validateCapabilities($contract, $errors);

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param string[] $errors
     */
    private function validateRequiredKeys(array $contract, array &$errors): void
    {
        foreach (self::REQUIRED_TOP_LEVEL_KEYS as $key) {
            if (! array_key_exists($key, $contract)) {
                $errors[] = "Missing required key: {$key}";
            }
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateIdentity(array $contract, array &$errors): void
    {
        if (! (isset($contract['apiVersion']) && $contract['apiVersion'] === 1 && is_int($contract['apiVersion']))) {
            $errors[] = 'apiVersion must be the integer 1';
        }

        $name = $contract['name'] ?? null;
        $pattern = '/^' . preg_quote($this->templatePrefix, '/') . '\/[a-z0-9][a-z0-9-]*$/';
        if (! is_string($name) || preg_match($pattern, $name) !== 1) {
            $errors[] = "name must match {$this->templatePrefix}/<slug> (lowercase, e.g. {$this->templatePrefix}/article)";
        }

        $version = $contract['version'] ?? null;
        if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            $errors[] = 'version must be semver (e.g. 1.0.0)';
        }

        if (array_key_exists('keywords', $contract) && ! is_array($contract['keywords'])) {
            $errors[] = 'keywords must be an array';
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateRender(array $contract, array &$errors): void
    {
        $render = $contract['render'] ?? null;
        if (! is_array($render)) {
            return;
        }

        $view = $render['view'] ?? null;
        if (! $this->isSafeViewName($view)) {
            $errors[] = "render.view must be a safe Blade view name (got '" . $this->stringify($view) . "')";
        }

        if (array_key_exists('script', $render) && $render['script'] !== null
            && ! $this->isSafeAssetPath($render['script'], '.js')) {
            $errors[] = "render.script must be null or a safe relative .js path (got '" . $this->stringify($render['script']) . "')";
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateCapabilities(array $contract, array &$errors): void
    {
        $capabilities = $contract['capabilities'] ?? null;
        if (! is_array($capabilities)) {
            return;
        }

        foreach ($capabilities as $key => $def) {
            if (! in_array($key, self::CAPABILITY_KEYS, true)) {
                $errors[] = "unknown capability '{$this->stringify($key)}'";
                continue;
            }

            if (! is_array($def)) {
                $errors[] = "capability '{$key}' must be an object";
                continue;
            }

            if (! array_key_exists('enabled', $def) || ! is_bool($def['enabled'])) {
                $errors[] = "capability '{$key}' requires a boolean 'enabled'";
                continue;
            }

            match ($key) {
                'tableOfContents' => $this->validateTableOfContents($def, $errors),
                'featuredImage' => $this->validateFeaturedImage($def, $errors),
                'readingTime' => $this->validateReadingTime($def, $errors),
                'authorBox' => $this->validateAuthorBox($def, $errors),
                'shareButtons' => $this->validateShareButtons($def, $errors),
                'breadcrumbs' => $this->validateBreadcrumbs($def, $errors),
                'pagination' => $this->validatePagination($def, $errors),
                'postViews' => $this->validatePostViews($def, $errors),
                'comments' => $this->validateComments($def, $errors),
                'relatedPosts' => $this->validateRelatedPosts($def, $errors),
                'seoMeta' => $this->validateSeoMeta($def, $errors),
                default => null,
            };
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validateTableOfContents(array $def, array &$errors): void
    {
        if (array_key_exists('source', $def) && ! in_array($def['source'], self::TOC_SOURCES, true)) {
            $errors[] = "capability 'tableOfContents' source must be one of: " . implode(', ', self::TOC_SOURCES);
        }

        foreach (['minLevel', 'maxLevel'] as $bound) {
            if (array_key_exists($bound, $def) && ! $this->isHeadingLevel($def[$bound])) {
                $errors[] = "capability 'tableOfContents' {$bound} must be an integer 1-6";
            }
        }

        if (isset($def['minLevel'], $def['maxLevel'])
            && $this->isHeadingLevel($def['minLevel']) && $this->isHeadingLevel($def['maxLevel'])
            && $def['minLevel'] > $def['maxLevel']) {
            $errors[] = "capability 'tableOfContents' minLevel must be <= maxLevel";
        }

        if (array_key_exists('title', $def) && ! is_string($def['title'])) {
            $errors[] = "capability 'tableOfContents' title must be a string";
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validateFeaturedImage(array $def, array &$errors): void
    {
        if ($def['enabled'] === true && ! isset($def['source'])) {
            $errors[] = "capability 'featuredImage' source is required when enabled";
        }

        if (array_key_exists('source', $def) && ! in_array($def['source'], self::FEATURED_IMAGE_SOURCES, true)) {
            $errors[] = "capability 'featuredImage' source must be one of: " . implode(', ', self::FEATURED_IMAGE_SOURCES);
        }

        if (array_key_exists('context', $def) && ! is_string($def['context'])) {
            $errors[] = "capability 'featuredImage' context must be a string";
        }

        if (array_key_exists('fallback', $def) && $def['fallback'] !== null && ! is_string($def['fallback'])) {
            $errors[] = "capability 'featuredImage' fallback must be null or a string";
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validateReadingTime(array $def, array &$errors): void
    {
        if (array_key_exists('wordsPerMinute', $def) && ! (is_int($def['wordsPerMinute']) && $def['wordsPerMinute'] > 0)) {
            $errors[] = "capability 'readingTime' wordsPerMinute must be a positive integer";
        }

        if (array_key_exists('label', $def) && ! is_string($def['label'])) {
            $errors[] = "capability 'readingTime' label must be a string";
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validateAuthorBox(array $def, array &$errors): void
    {
        if (! array_key_exists('fields', $def)) {
            return;
        }

        if (! is_array($def['fields'])) {
            $errors[] = "capability 'authorBox' fields must be an object";
            return;
        }

        foreach ($def['fields'] as $field => $attribute) {
            if (! in_array($field, self::AUTHOR_BOX_FIELDS, true)) {
                $errors[] = "capability 'authorBox' fields has unknown field '{$this->stringify($field)}'";
                continue;
            }
            if ($attribute !== null && ! is_string($attribute)) {
                $errors[] = "capability 'authorBox' fields.{$field} must be a string attribute name or null";
            }
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validateShareButtons(array $def, array &$errors): void
    {
        if ($def['enabled'] === true && (! isset($def['networks']) || ! is_array($def['networks']) || $def['networks'] === [])) {
            $errors[] = "capability 'shareButtons' networks must be a non-empty array when enabled";
            return;
        }

        if (! array_key_exists('networks', $def)) {
            return;
        }

        if (! is_array($def['networks'])) {
            $errors[] = "capability 'shareButtons' networks must be an array";
            return;
        }

        foreach ($def['networks'] as $network) {
            if (! in_array($network, self::SHARE_NETWORKS, true)) {
                $errors[] = "capability 'shareButtons' has unknown network '" . $this->stringify($network) . "'";
            }
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validateBreadcrumbs(array $def, array &$errors): void
    {
        if (array_key_exists('homeLabel', $def) && ! is_string($def['homeLabel'])) {
            $errors[] = "capability 'breadcrumbs' homeLabel must be a string";
        }

        if (array_key_exists('categoryAttribute', $def) && $def['categoryAttribute'] !== null && ! is_string($def['categoryAttribute'])) {
            $errors[] = "capability 'breadcrumbs' categoryAttribute must be null or a string";
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validatePagination(array $def, array &$errors): void
    {
        if ($def['enabled'] === true && ! isset($def['mode'])) {
            $errors[] = "capability 'pagination' mode is required when enabled";
        }

        if (array_key_exists('mode', $def) && ! in_array($def['mode'], self::PAGINATION_MODES, true)) {
            $errors[] = "capability 'pagination' mode must be one of: " . implode(', ', self::PAGINATION_MODES);
        }

        if (($def['mode'] ?? null) === 'numbered'
            && (! isset($def['perPage']) || ! (is_int($def['perPage']) && $def['perPage'] > 0))) {
            $errors[] = "capability 'pagination' perPage must be a positive integer when mode is 'numbered'";
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validatePostViews(array $def, array &$errors): void
    {
        if (array_key_exists('label', $def) && ! is_string($def['label'])) {
            $errors[] = "capability 'postViews' label must be a string";
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validateComments(array $def, array &$errors): void
    {
        if (array_key_exists('allowGuests', $def) && ! is_bool($def['allowGuests'])) {
            $errors[] = "capability 'comments' allowGuests must be a boolean";
        }

        if (array_key_exists('sortOrder', $def) && ! in_array($def['sortOrder'], self::COMMENT_SORT_ORDERS, true)) {
            $errors[] = "capability 'comments' sortOrder must be one of: " . implode(', ', self::COMMENT_SORT_ORDERS);
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validateRelatedPosts(array $def, array &$errors): void
    {
        if ($def['enabled'] === true && (! isset($def['limit']) || ! (is_int($def['limit']) && $def['limit'] > 0))) {
            $errors[] = "capability 'relatedPosts' limit must be a positive integer when enabled";
            return;
        }

        if (array_key_exists('limit', $def) && ! (is_int($def['limit']) && $def['limit'] > 0)) {
            $errors[] = "capability 'relatedPosts' limit must be a positive integer";
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param string[] $errors
     */
    private function validateSeoMeta(array $def, array &$errors): void
    {
        if (! array_key_exists('fields', $def)) {
            return;
        }

        if (! is_array($def['fields'])) {
            $errors[] = "capability 'seoMeta' fields must be an array";
            return;
        }

        foreach ($def['fields'] as $field) {
            if (! in_array($field, self::SEO_FIELDS, true)) {
                $errors[] = "capability 'seoMeta' has unknown field '" . $this->stringify($field) . "'";
            }
        }
    }

    private function isHeadingLevel(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 6;
    }

    /**
     * A safe Blade view reference: an optional `namespace::` prefix followed by
     * a dot-path, letters/digits/underscore/hyphen/dot only, no traversal and
     * no leading/trailing dot (mirrors the intent of
     * {@see BlockContractValidator::isSafeAssetPath()} for a view name rather
     * than a filesystem path — Blade view names are resolved by the view
     * finder, never treated as a raw path).
     */
    private function isSafeViewName(mixed $view): bool
    {
        if (! is_string($view) || $view === '') {
            return false;
        }
        if (str_contains($view, '..') || str_starts_with($view, '.') || str_ends_with($view, '.')) {
            return false;
        }

        return preg_match('/^(?:[a-zA-Z][a-zA-Z0-9_-]*::)?[a-zA-Z][a-zA-Z0-9_.-]*$/', $view) === 1;
    }

    /**
     * Safe relative asset path: not absolute, not protocol-relative, no
     * traversal, and ending in the required extension. Identical rule to
     * {@see BlockContractValidator::isSafeAssetPath()}.
     */
    private function isSafeAssetPath(mixed $path, string $extension): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }
        if (str_contains($path, '..')) {
            return false;
        }
        if (str_starts_with($path, '/')) {
            return false;
        }
        if (str_contains($path, '\\')) {
            return false;
        }

        return str_ends_with(strtolower($path), $extension);
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value === null => 'null',
            default => gettype($value),
        };
    }
}
