<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Blocks\BlockContractLoader;
use Heisenberg\Blocks\BlockIconCollector;
use Heisenberg\Blocks\BlockPathGuard;
use Heisenberg\Blocks\ContractLocalizer;
use Heisenberg\Blocks\DesignTokenCatalog;
use Heisenberg\Blocks\InspectorControlDeriver;
use Heisenberg\Blocks\StylePanelDeriver;
use Heisenberg\Blocks\StylePanelFieldRows;

/**
 * Discovers block-contract JSON on disk, validates each via {@see BlockContractValidator},
 * and serves the editor a hashed, localized registry envelope (§3.4).
 *
 * Heisenberg internalizes the contracts under the package `resources/blocks`
 * (overridable via `config('heisenberg.block_root')`) rather than the GTC host's
 * view directory (§3.10). The registry hash is computed on the *untranslated*
 * contracts so it is locale-stable; the public `blocks` are localized.
 *
 * This class is the container-resolved FAÇADE over the registry's collaborators
 * (`src/Blocks/*`): {@see BlockPathGuard} confines every candidate file to the
 * block root (SECURITY-CRITICAL); {@see BlockContractLoader} scans/validates/caches
 * the contracts on disk; {@see ContractLocalizer}, {@see InspectorControlDeriver}
 * and {@see StylePanelDeriver} (with {@see StylePanelFieldRows}) turn a bare
 * contract into the localized, control/panel-bearing shape the editor consumes;
 * {@see BlockIconCollector} gathers the icon references. Every collaborator is
 * constructed once, here, and reused for the lifetime of this service — no
 * per-block container resolution, no repeated disk reads beyond the loader's
 * own self-invalidating cache. Every public method below existed on this class
 * before the split and keeps its exact signature and behavior.
 */
class BlockRegistryService
{
    public const SCHEMA_VERSION = 1;

    private BlockPathGuard $pathGuard;

    private BlockContractLoader $loader;

    private ContractLocalizer $localizer;

    private InspectorControlDeriver $controlDeriver;

    private StylePanelDeriver $panelDeriver;

    private BlockIconCollector $iconCollector;

    public function __construct(
        private BlockContractValidator $validator,
        private ?string $blockRootPath = null,
    ) {
        $this->pathGuard = new BlockPathGuard($this->blockRootPath);
        $this->loader = new BlockContractLoader($this->validator, $this->pathGuard);
        $this->localizer = new ContractLocalizer();
        $tokens = new DesignTokenCatalog();
        $this->controlDeriver = new InspectorControlDeriver($this->localizer, $tokens);
        $this->panelDeriver = new StylePanelDeriver(new StylePanelFieldRows($tokens));
        $this->iconCollector = new BlockIconCollector();
    }

    /** The raw scan: valid contracts with their on-disk path keys merged in. */
    public function discover(): array
    {
        $scan = $this->loader->scan();
        $blocks = [];
        foreach ($scan['contracts'] as $name => $contract) {
            $blocks[] = $contract + [
                '_absolutePath' => $scan['paths'][$name]['abs'],
                '_relativePath' => $scan['paths'][$name]['rel'],
            ];
        }

        return ['blocks' => $blocks, 'errors' => $scan['errors']];
    }

    /** The full registry envelope served to the editor. */
    public function registry(?string $locale = null): array
    {
        $scan = $this->loader->scan();
        $bare = $this->sortedByName(array_values($scan['contracts']));

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'registryHash' => $this->computeHash($bare),
            'blocks' => array_map(fn (array $c): array => $this->localizeContract($c, $locale), $bare),
            'categories' => $this->getCategories($bare),
            'icons' => $this->iconCollector->referencedIcons($bare),
            'generatedAt' => now()->toIso8601String(),
            'errors' => $scan['errors'],
        ];
    }

    /**
     * Contracts that opt into an alternate render SURFACE — today only `'email'`
     * (docs/email-system.md §4): a block appears here exactly when its contract declares a
     * top-level `email` section (`{"template": {...}}`, validated by
     * {@see BlockContractValidator}), same "presence is the whole signal" rule the palette
     * filtering itself follows. Localized the same way {@see registry()}'s `blocks` are —
     * title/description/controls/panels all resolve for `$locale` — so a caller building an
     * email-surface palette gets the identical shape the web palette gets, just filtered.
     *
     * @return list<array<string, mixed>>
     */
    public function contractsFor(string $surface, ?string $locale = null): array
    {
        $scan = $this->loader->scan();
        $bare = $this->sortedByName(array_values($scan['contracts']));

        $filtered = array_values(array_filter(
            $bare,
            static fn (array $c): bool => is_array($c[$surface] ?? null) && is_array($c[$surface]['template'] ?? null)
        ));

        return array_map(fn (array $c): array => $this->localizeContract($c, $locale), $filtered);
    }

    /** Canonical, locale-stable hash of the (untranslated) contracts. */
    public function computeHash(?array $blocks = null): string
    {
        $blocks ??= $this->sortedByName(array_values($this->loader->scan()['contracts']));

        $json = json_encode(
            $blocks,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return 'sha256:' . hash('sha256', (string) $json);
    }

    /** @return string[] sorted, distinct category values */
    public function getCategories(?array $blocks = null): array
    {
        $blocks ??= array_values($this->loader->scan()['contracts']);

        $categories = [];
        foreach ($blocks as $contract) {
            if (isset($contract['category']) && is_string($contract['category'])) {
                $categories[] = $contract['category'];
            }
        }
        $categories = array_values(array_unique($categories));
        sort($categories);

        return $categories;
    }

    public function getBlock(string $name): ?array
    {
        return $this->loader->scan()['contracts'][$name] ?? null;
    }

    /**
     * Attribute names this contract marks `"translatable": true` (docs/content-translation.md
     * §0) — the human-language attributes whose value lives in locale-suffixed variants
     * (`content_en`, `content_fr`, …). Empty for an unknown block or one with none declared
     * (most container/design blocks). Used server-side by {@see TranslationStatusService}
     * and any other caller that needs the list without the full localized registry envelope.
     *
     * @return string[]
     */
    public function translatableAttributes(string $name): array
    {
        return $this->localizer->translatableAttributesOf($this->getBlock($name) ?? []);
    }

    public function isBlockKnown(string $name): bool
    {
        return isset($this->loader->scan()['contracts'][$name]);
    }

    /** Path-traversal guard: realpath-confine a candidate file to the block root. */
    public function validatePath(string $path): bool
    {
        return $this->pathGuard->validatePath($path);
    }

    private function sortedByName(array $blocks): array
    {
        usort($blocks, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $blocks;
    }

    private function localizeContract(array $contract, ?string $locale): array
    {
        $contract['title'] = $this->localizer->localize($contract['title'] ?? null, $locale);
        $contract['description'] = $this->localizer->localize($contract['description'] ?? null, $locale);
        $contract['controls'] = array_merge(
            $this->controlDeriver->deriveControls($contract, $locale),
            $this->controlDeriver->deriveSupportControls($contract, $locale),
        );
        $contract['panels'] = $this->panelDeriver->derivePanels($contract);
        // The editor wave's per-attribute locale-scoping needs this list without re-deriving it
        // from `attributes` client-side — same "derive once, serve alongside controls/panels"
        // posture as those two (docs/content-translation.md §0).
        $contract['translatableAttributes'] = $this->localizer->translatableAttributesOf($contract);

        return $contract;
    }
}
