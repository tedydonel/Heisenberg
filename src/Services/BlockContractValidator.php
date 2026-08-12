<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * Validates block-contract *definitions* (not block instances — that is the
 * payload service's job). Pure and stateless; the only Heisenberg change vs the
 * GTC original is the configurable block prefix in the `name` regex (§3.5).
 *
 * Runs nine validators in order: required-keys -> identity -> attributes ->
 * supports -> style -> render -> innerBlocks -> serialization -> security.
 * Returns `['valid' => bool, 'errors' => string[]]`. (Inspector controls are
 * derived from attributes by the registry, so they are not declared here.)
 */
class BlockContractValidator
{
    /** The 16 mandatory top-level keys (§3.2). */
    private const REQUIRED_TOP_LEVEL_KEYS = [
        '$schema', 'apiVersion', 'name', 'title', 'category', 'icon',
        'description', 'keywords', 'version', 'attributes', 'supports',
        'style', 'render', 'innerBlocks', 'serialization', 'security',
    ];

    /** The 10 attribute value types. */
    private const ATTRIBUTE_TYPES = [
        'string', 'boolean', 'integer', 'number', 'rich-text',
        'array', 'object', 'media', 'url', 'token',
    ];

    /** The sanitizer tokens (attribute + style-variable). */
    private const SANITIZERS = [
        'text', 'rich-text-inline', 'rich-text-block', 'url', 'color-token',
        'color-token-or-transparent', 'size-token', 'integer', 'boolean', 'html-safe',
        'border-style', 'font-token',
        // Raw-value kinds — each a strict allowlist (grammars in BlockRenderer::cssValueValid()).
        'size-value', 'color-value', 'font-family', 'font-weight',
        // Supports-capability kinds. LOCKSTEP with BlockRenderer::cssValueValid() and the JS
        // cssValueValid() in block-runtime.blade.php; never let these hit a permissive fallback.
        'opacity', 'angle', 'length-signed', 'shadow',
        'text-align', 'align-3', 'position-mode',
        'flex-direction', 'flex-justify', 'flex-align', 'flex-wrap', 'overflow',
    ];

    /** Allowed style-system support groups (align is special-cased). */
    private const SUPPORT_KEYS = [
        'color', 'typography', 'spacing', 'border', 'dimensions', 'layout', 'size', 'animation',
        'appearance', 'position', 'effects',
    ];

    /**
     * Feature catalog per support group: feature => value shape. Only features the
     * engine (SupportsStyle / BlockRegistryService::derivePanels) actually consumes
     * are legal — an unknown feature would validate and then silently never emit.
     * Shapes: `bool`; `bool-or-sides` (bool or {top,right,bottom,left} bool map);
     * `bool-or-corners` (bool or corner bool map); `axes` ({width,height} bool map).
     */
    private const SUPPORT_FEATURES = [
        'color' => ['text' => 'bool', 'background' => 'bool', 'custom' => 'bool'],
        'typography' => [
            'fontFamily' => 'bool', 'fontWeight' => 'bool', 'fontSize' => 'bool',
            'lineHeight' => 'bool', 'letterSpacing' => 'bool',
            'textAlign' => 'bool', 'textAlignVertical' => 'bool',
        ],
        'spacing' => ['margin' => 'bool-or-sides', 'padding' => 'bool-or-sides', 'blockGap' => 'bool'],
        'border' => [
            'style' => 'bool-or-sides', 'width' => 'bool-or-sides',
            'color' => 'bool-or-sides', 'radius' => 'bool-or-corners',
        ],
        'size' => [
            'width' => 'bool', 'height' => 'bool', 'minWidth' => 'bool', 'minHeight' => 'bool',
            'maxWidth' => 'bool', 'maxHeight' => 'bool',
            'fill' => 'axes', 'hug' => 'axes', 'clip' => 'bool',
        ],
        'layout' => ['direction' => 'bool', 'wrap' => 'bool', 'justify' => 'bool', 'align' => 'bool', 'gap' => 'bool', 'padding' => 'bool'],
        'position' => ['mode' => 'bool', 'x' => 'bool', 'y' => 'bool', 'rotation' => 'bool'],
        'appearance' => ['opacity' => 'bool'],
        'effects' => ['shadow' => 'bool'],
    ];

    private const SIDE_KEYS = ['top', 'right', 'bottom', 'left'];

    private const CORNER_KEYS = ['topLeft', 'topRight', 'bottomLeft', 'bottomRight'];

    private const AXIS_KEYS = ['width', 'height'];

    /** Allowed alignment values. */
    private const ALIGN_VALUES = ['left', 'center', 'right', 'wide', 'full'];

    /**
     * Interaction states a contract may declare under `supports.states`. Must stay in
     * lockstep with {@see \Heisenberg\Services\BlockRenderer::INTERACTION_STATES} — a state this
     * accepts but the renderer cannot compile would validate and then never emit any CSS.
     */
    public const INTERACTION_STATES = ['hover', 'active', 'focus'];

    /** The editor control types. */
    private const CONTROL_TYPES = [
        'text', 'textarea', 'rich-text', 'select', 'toggle', 'checkbox', 'range',
        'number', 'media', 'link', 'button-group', 'repeater', 'chips',
        'unit', 'color', 'font',   // .pen control kit widgets
    ];

    /** Valid render.template node types (`element` is also the default when `type` is absent). */
    private const TEMPLATE_NODE_TYPES = ['element', 'text', 'rich-text', 'inner-blocks', 'text-lines', 'icon'];

    /** Valid innerBlocks layout orientations. */
    private const ORIENTATIONS = ['vertical', 'horizontal', 'auto'];

    /** Valid innerBlocks appender modes (plus the literal `false`). */
    private const APPENDERS = ['default', 'none', 'button'];

    public function __construct(private string $blockPrefix = 'heisenberg')
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
        $this->validateAttributes($contract, $errors);
        $this->validateSupports($contract, $errors);
        $this->validateStyle($contract, $errors);
        $this->validateRender($contract, $errors);
        $this->validateEmail($contract, $errors);
        $this->validateInnerBlocks($contract, $errors);
        $this->validateSerialization($contract, $errors);
        $this->validateSecurity($contract, $errors);

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
        $pattern = '/^' . preg_quote($this->blockPrefix, '/') . '\/[a-z0-9][a-z0-9-]*$/';
        if (! is_string($name) || preg_match($pattern, $name) !== 1) {
            $errors[] = "name must match {$this->blockPrefix}/<slug> (lowercase, e.g. {$this->blockPrefix}/paragraph)";
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
    private function validateAttributes(array $contract, array &$errors): void
    {
        $attributes = $contract['attributes'] ?? null;
        if (! is_array($attributes)) {
            return;
        }

        foreach ($attributes as $name => $def) {
            if (! is_array($def)) {
                $errors[] = "attribute '{$name}' must be an object";
                continue;
            }

            $type = $def['type'] ?? null;
            if (! in_array($type, self::ATTRIBUTE_TYPES, true)) {
                $errors[] = "attribute '{$name}' has unknown type '" . $this->stringify($type) . "'";
            }

            if (array_key_exists('enum', $def) && ! is_array($def['enum'])) {
                $errors[] = "attribute '{$name}' enum must be an array";
            }

            if (array_key_exists('sanitize', $def) && ! in_array($def['sanitize'], self::SANITIZERS, true)) {
                $errors[] = "attribute '{$name}' has unknown sanitize '" . $this->stringify($def['sanitize']) . "'";
            }

            if (in_array($type, ['rich-text', 'url', 'token'], true) && ! isset($def['sanitize'])) {
                $errors[] = "attribute '{$name}' of type '{$type}' requires a sanitize token";
            }

            if ($type === 'array' && ! isset($def['items'])) {
                $errors[] = "attribute '{$name}' of type 'array' requires 'items'";
            }

            if (in_array($type, ['object', 'media'], true) && ! isset($def['properties'])) {
                $errors[] = "attribute '{$name}' of type '{$type}' requires 'properties'";
            }

            $this->validateControlOverride($name, $def, $attributes, $errors);
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateSupports(array $contract, array &$errors): void
    {
        $supports = $contract['supports'] ?? null;
        if (! is_array($supports)) {
            return;
        }

        foreach ($supports as $group => $value) {
            if ($group === 'align') {
                if (! is_array($value)) {
                    $errors[] = 'supports.align must be an array';
                    continue;
                }
                foreach ($value as $align) {
                    if (! in_array($align, self::ALIGN_VALUES, true)) {
                        $errors[] = "supports.align has unknown value '" . $this->stringify($align) . "'";
                    }
                }
                continue;
            }

            // `states` declares WHICH interaction states a block may style. Its instance-side
            // counterpart is the override map BlockRenderer::stateStylesCss() compiles, keyed by
            // the same state names — so the contract side is a plain name => bool map, exactly
            // like `color`/`typography` declare which of their keys are available. Restricted to
            // the states the renderer can actually compile; anything else would validate and then
            // silently never emit.
            if ($group === 'states') {
                if (! is_array($value)) {
                    $errors[] = 'supports.states must be an object';
                    continue;
                }
                foreach ($value as $state => $enabled) {
                    if (! in_array($state, self::INTERACTION_STATES, true)) {
                        $errors[] = "supports.states has unknown state '" . $this->stringify($state) . "'";
                    } elseif (! is_bool($enabled)) {
                        $errors[] = "supports.states.{$state} must be a boolean";
                    }
                }
                continue;
            }

            if (! in_array($group, self::SUPPORT_KEYS, true)) {
                $errors[] = "unknown support group '{$group}'";
                continue;
            }

            if ($group === 'animation') {
                if (! is_bool($value)) {
                    $errors[] = 'supports.animation must be a boolean';
                }
                continue;
            }

            $this->validateSupportGroupShape($group, $value, $errors);
        }
    }

    /**
     * Shape-check a support group's interior against SUPPORT_FEATURES. `dimensions`
     * has no engine backing yet, so it only gets a generic boolean-tree check.
     *
     * @param string[] $errors
     */
    private function validateSupportGroupShape(string $group, mixed $value, array &$errors): void
    {
        if (! is_array($value)) {
            $errors[] = "supports.{$group} must be an object of feature flags";

            return;
        }

        $catalog = self::SUPPORT_FEATURES[$group] ?? null;
        if ($catalog === null) {
            if (! $this->isBoolTree($value, 2)) {
                $errors[] = "supports.{$group} must contain only booleans (or side maps of booleans)";
            }

            return;
        }

        foreach ($value as $feature => $flag) {
            $shape = $catalog[$feature] ?? null;
            if ($shape === null) {
                $errors[] = "supports.{$group} has unknown feature '" . $this->stringify($feature) . "'";
                continue;
            }

            $ok = match ($shape) {
                'bool' => is_bool($flag),
                'bool-or-sides' => is_bool($flag) || $this->isBoolMap($flag, self::SIDE_KEYS),
                'bool-or-corners' => is_bool($flag) || $this->isBoolMap($flag, self::CORNER_KEYS),
                'axes' => $this->isBoolMap($flag, self::AXIS_KEYS),
            };
            if (! $ok) {
                $errors[] = "supports.{$group}.{$feature} has an invalid shape (expected {$shape})";
            }
        }
    }

    /** A non-empty map whose keys are all in $allowedKeys and whose values are all booleans. */
    private function isBoolMap(mixed $value, array $allowedKeys): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }
        foreach ($value as $key => $flag) {
            if (! in_array($key, $allowedKeys, true) || ! is_bool($flag)) {
                return false;
            }
        }

        return true;
    }

    /** Booleans at the leaves, arrays allowed down to $depth levels. */
    private function isBoolTree(mixed $value, int $depth): bool
    {
        if (is_bool($value)) {
            return true;
        }
        if ($depth <= 0 || ! is_array($value)) {
            return false;
        }
        foreach ($value as $child) {
            if (! $this->isBoolTree($child, $depth - 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $errors
     */
    private function validateControlOverride(string $name, array $def, array $declaredAttributes, array &$errors): void
    {
        if (! array_key_exists('control', $def) || $def['control'] === false) {
            return; // no override, or an explicit hide
        }

        $control = $def['control'];
        if (! is_array($control)) {
            $errors[] = "attribute '{$name}' control must be an object or false";

            return;
        }

        if (isset($control['type']) && ! in_array($control['type'], self::CONTROL_TYPES, true)) {
            $errors[] = "attribute '{$name}' control has unknown type '" . $this->stringify($control['type']) . "'";
        }

        if (isset($control['options']) && ! is_array($control['options'])) {
            $errors[] = "attribute '{$name}' control options must be an array";
        }

        foreach (['showWhen', 'disableWhen', 'forceWhen'] as $key) {
            if (! array_key_exists($key, $control)) {
                continue;
            }

            if (! is_array($control[$key])) {
                $errors[] = "attribute '{$name}' control {$key} must be a predicate object";
                continue;
            }

            foreach ($this->validatePredicate($key, $control[$key], $declaredAttributes) as $error) {
                $errors[] = "attribute '{$name}' control {$error}";
            }
        }
    }

    /**
     * @return string[]
     */
    private function validatePredicate(string $key, array $predicate, array $declaredAttributes): array
    {
        $errors = [];
        $attribute = $predicate['attribute'] ?? null;

        if (! is_string($attribute) || ! array_key_exists($attribute, $declaredAttributes)) {
            $errors[] = "{$key}.attribute must name a declared attribute of the same block";
        }

        $hasEquals = array_key_exists('equals', $predicate);
        $hasIn = array_key_exists('in', $predicate);
        if ($hasEquals === $hasIn) {
            $errors[] = "{$key} must contain exactly one of equals or in";
        }

        if ($hasEquals && ! is_scalar($predicate['equals'])) {
            $errors[] = "{$key}.equals must be a scalar";
        }

        if ($hasIn) {
            if (! is_array($predicate['in'])) {
                $errors[] = "{$key}.in must be an array of scalars";
            } else {
                foreach ($predicate['in'] as $value) {
                    if (! is_scalar($value)) {
                        $errors[] = "{$key}.in must contain only scalars";
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param string[] $errors
     */
    private function validateStyle(array $contract, array &$errors): void
    {
        $style = $contract['style'] ?? null;
        if (! is_array($style)) {
            return;
        }

        if (isset($style['css']) && ! $this->isSafeAssetPath($style['css'], '.css')) {
            $errors[] = "style.css must be a safe relative .css path (got '" . $this->stringify($style['css']) . "')";
        }

        if (isset($style['className']) && (! is_string($style['className']) || $style['className'] === '')) {
            $errors[] = 'style.className must be a non-empty string';
        }

        if (array_key_exists('classNames', $style)) {
            if (! is_array($style['classNames'])) {
                $errors[] = 'style.classNames must be an array';
            } else {
                $declaredAttributes = is_array($contract['attributes'] ?? null) ? $contract['attributes'] : [];
                foreach ($style['classNames'] as $index => $binding) {
                    if (! is_array($binding)) {
                        $errors[] = "style.classNames.{$index} must be an object";
                        continue;
                    }

                    $class = $binding['class'] ?? null;
                    if (! is_string($class) || preg_match('/^[a-z][a-z0-9-]*$/', $class) !== 1) {
                        $errors[] = "style.classNames.{$index}.class must match ^[a-z][a-z0-9-]*$";
                    }

                    $when = $binding['when'] ?? null;
                    if (! is_array($when)) {
                        $errors[] = "style.classNames.{$index}.when must be a predicate object";
                        continue;
                    }

                    foreach ($this->validatePredicate("style.classNames.{$index}.when", $when, $declaredAttributes) as $error) {
                        $errors[] = $error;
                    }
                }
            }
        }

        $variables = $style['variables'] ?? null;
        if (is_array($variables)) {
            foreach ($variables as $var => $def) {
                if (! is_array($def)) {
                    $errors[] = "style variable '{$var}' must be an object";
                    continue;
                }

                $source = $def['source'] ?? null;
                if (! is_string($source) || preg_match('/^(attributes|supports)\./', $source) !== 1) {
                    $errors[] = "style variable '{$var}' source must reference attributes.* or supports.*";
                }

                $default = $def['default'] ?? null;
                if (is_string($default) && str_starts_with($default, '#')) {
                    $errors[] = "style variable '{$var}' default may not be a raw hex colour (use a design token)";
                }

                if (isset($def['sanitize']) && ! in_array($def['sanitize'], self::SANITIZERS, true)) {
                    $errors[] = "style variable '{$var}' has unknown sanitize '" . $this->stringify($def['sanitize']) . "'";
                }
            }
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

        if (! isset($render['template']) || ! is_array($render['template'])) {
            $errors[] = 'render.template is required and must be a node tree';
        } else {
            $this->validateTemplateNode($render['template'], $errors);
        }

        if (array_key_exists('script', $render) && $render['script'] !== null
            && ! $this->isSafeAssetPath($render['script'], '.js')) {
            $errors[] = "render.script must be null or a safe relative .js path (got '" . $this->stringify($render['script']) . "')";
        }
    }

    /**
     * OPTIONAL top-level `email` section (docs/email-system.md §4): presence opts the block
     * into the email palette, absence means it never appears there — there is no separate
     * required/forbidden list to keep in sync, the section's mere existence IS the signal
     * `BlockRegistryService::contractsFor('email')` filters on. When present, `email.template`
     * is REQUIRED and validated by the exact same {@see self::validateTemplateNode()} rules as
     * `render.template` — an email template is a node tree through the identical substitution/
     * sanitization engine (`BlockRenderer`), just a different tree, so it earns no looser shape.
     *
     * @param string[] $errors
     */
    private function validateEmail(array $contract, array &$errors): void
    {
        if (! array_key_exists('email', $contract)) {
            return; // no email section: this block simply never appears in the email palette
        }

        $email = $contract['email'];
        if (! is_array($email)) {
            $errors[] = 'email must be an object';

            return;
        }

        if (! isset($email['template']) || ! is_array($email['template'])) {
            $errors[] = 'email.template is required and must be a node tree';

            return;
        }

        $this->validateTemplateNode($email['template'], $errors);
    }

    /**
     * @param string[] $errors
     */
    private function validateTemplateNode(array $node, array &$errors): void
    {
        $type = $node['type'] ?? null;
        if ($type !== null && ! in_array($type, self::TEMPLATE_NODE_TYPES, true)) {
            $errors[] = "render.template node has unknown type '" . $this->stringify($type) . "'";
        }

        if (in_array($type, ['rich-text', 'text-lines', 'icon'], true) && ! (isset($node['attribute']) && is_string($node['attribute']))) {
            $errors[] = "render.template {$type} node requires a string attribute";
        }

        if (isset($node['tag']) && ! is_string($node['tag'])) {
            $errors[] = 'render.template node tag must be a string';
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $this->validateTemplateNode($child, $errors);
                }
            }
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateInnerBlocks(array $contract, array &$errors): void
    {
        $inner = $contract['innerBlocks'] ?? null;
        if (! is_array($inner)) {
            return;
        }

        if (! array_key_exists('enabled', $inner) || ! is_bool($inner['enabled'])) {
            $errors[] = 'innerBlocks.enabled must be a boolean';
            return;
        }

        if ($inner['enabled'] === true && ! isset($inner['allowedBlocks'])) {
            $errors[] = 'innerBlocks.allowedBlocks is required when innerBlocks are enabled';
        }

        if (array_key_exists('allowedBlocks', $inner) && ! $this->isAllowedBlocksValue($inner['allowedBlocks'])) {
            $errors[] = 'innerBlocks.allowedBlocks must be "*" or an array of block names';
        }

        if (array_key_exists('orientation', $inner) && ! in_array($inner['orientation'], self::ORIENTATIONS, true)) {
            $errors[] = 'innerBlocks.orientation must be one of vertical|horizontal|auto';
        }

        if (array_key_exists('appender', $inner)
            && $inner['appender'] !== false
            && ! in_array($inner['appender'], self::APPENDERS, true)) {
            $errors[] = 'innerBlocks.appender must be one of default|none|button|false';
        }

        if (array_key_exists('parent', $inner) && ! $this->isStringArray($inner['parent'])) {
            $errors[] = 'innerBlocks.parent must be an array of block names';
        }

        if (array_key_exists('template', $inner) && ! $this->isInnerBlockTemplate($inner['template'])) {
            $errors[] = 'innerBlocks.template must be an array of [name, attributes?] entries';
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateSerialization(array $contract, array &$errors): void
    {
        $serialization = $contract['serialization'] ?? null;
        if (! is_array($serialization)) {
            return;
        }

        if (($serialization['mode'] ?? null) !== 'json') {
            $errors[] = "serialization.mode must be 'json'";
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateSecurity(array $contract, array &$errors): void
    {
        $security = $contract['security'] ?? null;
        if (! is_array($security)) {
            return;
        }

        if (($security['allowCustomCss'] ?? null) !== false) {
            $errors[] = 'security.allowCustomCss must be false (custom CSS is never permitted)';
        }
    }

    /** An allowedBlocks value: the `"*"` wildcard (open regime) or a list of block names. */
    private function isAllowedBlocksValue(mixed $value): bool
    {
        return $value === '*' || $this->isStringArray($value);
    }

    /** True when every element is a string (an empty array qualifies). */
    private function isStringArray(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /** A seed-children template: a list of `[name, attributes?]` entries. */
    private function isInnerBlockTemplate(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $entry) {
            if (! is_array($entry) || ! isset($entry[0]) || ! is_string($entry[0])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Safe relative asset path: not absolute, not protocol-relative, no traversal,
     * and ending in the required extension.
     */
    private function isSafeAssetPath(mixed $path, string $extension): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }
        if (str_contains($path, '..')) {
            return false;
        }
        if (str_starts_with($path, '/')) {            // absolute and protocol-relative (//)
            return false;
        }
        if (str_contains($path, '\\')) {              // no backslash paths
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
