<?php

declare(strict_types=1);

namespace Heisenberg\Tests\M1;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;

/**
 * Integration guard for the shipped contracts under resources/blocks
 * (Appendix C, renamed gtc/->heisenberg/, blog::->heisenberg::, gtc-block->hb-block).
 * Any transcription mistake fails here rather than slipping into the editor.
 *
 * Pruned to heading + paragraph only (2026-08-02, alongside the builder's removal —
 * see TODO.md). The other 13 shipped contracts existed for the builder; deleted with it.
 */
class ShippedContractsTest extends TestCase
{
    private const EXPECTED = [
        'heisenberg/heading',
        'heisenberg/paragraph',
    ];

    private function registry(): BlockRegistryService
    {
        // No explicit root -> the package default (resources/blocks).
        return new BlockRegistryService(new BlockContractValidator('heisenberg'));
    }

    public function test_all_shipped_contracts_are_discovered_and_valid(): void
    {
        $registry = $this->registry()->registry();

        $this->assertSame([], $registry['errors'], 'shipped contracts must all be valid');

        $names = array_map(static fn (array $b): string => $b['name'], $registry['blocks']);
        sort($names);
        $this->assertSame(self::EXPECTED, $names);
    }

    public function test_each_shipped_contract_is_individually_valid(): void
    {
        $validator = new BlockContractValidator('heisenberg');

        foreach ($this->registry()->discover()['blocks'] as $contract) {
            $bare = $contract;
            unset($bare['_absolutePath'], $bare['_relativePath']);
            $result = $validator->validate($bare);
            $this->assertTrue($result['valid'], "{$contract['name']}: " . implode(' | ', $result['errors']));
        }
    }

    public function test_registry_hash_is_present_and_stable(): void
    {
        $a = $this->registry()->registry('en')['registryHash'];
        $b = $this->registry()->registry('fr')['registryHash'];

        $this->assertStringStartsWith('sha256:', $a);
        $this->assertSame($a, $b);
    }

    public function test_paragraph_contract_matches_the_study_and_derives_its_inspector(): void
    {
        $registry = $this->registry()->registry();
        $paragraph = collect($registry['blocks'])->firstWhere('name', 'heisenberg/paragraph');

        $this->assertIsArray($paragraph);
        $this->assertSame(['content', 'dropCap', 'anchor', 'titleAttr', 'extraClasses', 'hideXs', 'hideSm', 'hideMd', 'hideLg', 'hideXl', 'hideXxl', 'fillWidth', 'fillHeight', 'hugWidth', 'hugHeight', 'clipContent', 'animate', 'animateDuration', 'animateDelay', 'animateEasing', 'animateOnce'], array_keys($paragraph['attributes']));

        $anchor = collect($paragraph['controls'])->firstWhere('attribute', 'anchor');
        $this->assertSame('general', $anchor['section'] ?? null, 'anchor lives in the Content tab General section');
        $extraClasses = collect($paragraph['controls'])->firstWhere('attribute', 'extraClasses');
        $this->assertSame('general', $extraClasses['section'] ?? null, 'extraClasses lives in the Content tab General section');
        $this->assertFalse($paragraph['attributes']['dropCap']['default']);
        $this->assertSame(['left', 'center', 'right'], $paragraph['supports']['align']);
        // textAlign/textAlignVertical/letterSpacing added 2026-08-05 (TODO 7.1/7.4) — the Style
        // panel already offered all three; the contract now backs them via SupportsStyle's
        // --hb-text-align / --hb-text-align-v / --hb-letter-spacing. No lineHeight: paragraph
        // still declares none, so that field stays gated off for it.
        $this->assertSame(
            ['fontFamily' => true, 'fontWeight' => true, 'fontSize' => true, 'textAlign' => true, 'textAlignVertical' => true, 'letterSpacing' => true],
            $paragraph['supports']['typography'],
        );
        // Full-kit overhaul 2026-07-19 (Phase 1) — the new Alignment panel is
        // derived from supports.align, which paragraph already declares.
        $this->assertSame(['align', 'position', 'appearance', 'typography', 'size', 'color', 'margin', 'padding', 'effects'], array_column($paragraph['panels'], 'key')); // no border/radius (7.2); appearance from opacity (7.1)

        $dropCap = collect($paragraph['controls'])->firstWhere('attribute', 'dropCap');
        $this->assertSame('toggle', $dropCap['type'] ?? null);
        $classNames = array_column($paragraph['style']['classNames'] ?? [], 'class');
        $this->assertContains('has-drop-cap', $classNames);
        foreach (['hb-hide-xs', 'hb-hide-sm', 'hb-hide-md', 'hb-hide-lg', 'hb-hide-xl', 'hb-hide-xxl', 'hb-anim-fade', 'hb-anim-slide-up', 'hb-anim-zoom'] as $conditional) {
            $this->assertContains($conditional, $classNames);
        }
    }

    public function test_heading_contract_matches_the_study_and_derives_its_inspector(): void
    {
        $registry = $this->registry()->registry();
        $heading = collect($registry['blocks'])->firstWhere('name', 'heisenberg/heading');

        $this->assertIsArray($heading);
        $this->assertSame(['content', 'level', 'anchor', 'titleAttr', 'extraClasses', 'hideXs', 'hideSm', 'hideMd', 'hideLg', 'hideXl', 'hideXxl', 'fillWidth', 'fillHeight', 'hugWidth', 'hugHeight', 'clipContent', 'animate', 'animateDuration', 'animateDelay', 'animateEasing', 'animateOnce'], array_keys($heading['attributes']));
        $this->assertSame([1, 2, 3, 4, 5, 6], $heading['attributes']['level']['enum']);
        // Same additions as paragraph (TODO 7.1/7.4); heading keeps its lineHeight.
        $this->assertSame(
            ['fontFamily' => true, 'fontWeight' => true, 'fontSize' => true, 'lineHeight' => true, 'textAlign' => true, 'textAlignVertical' => true, 'letterSpacing' => true],
            $heading['supports']['typography'],
        );
        // Full-kit overhaul 2026-07-19 (Phase 1) — see the paragraph test above.
        $this->assertSame(['align', 'position', 'appearance', 'typography', 'size', 'color', 'margin', 'padding', 'effects'], array_column($heading['panels'], 'key')); // no border/radius (7.2); appearance from opacity (7.1)

        $level = collect($heading['controls'])->firstWhere('attribute', 'level');
        $this->assertSame('select', $level['type'] ?? null);
        $this->assertSame([
            ['value' => 1, 'label' => 'H1'],
            ['value' => 2, 'label' => 'H2'],
            ['value' => 3, 'label' => 'H3'],
            ['value' => 4, 'label' => 'H4'],
            ['value' => 5, 'label' => 'H5'],
            ['value' => 6, 'label' => 'H6'],
        ], $level['options'] ?? null);

        $typography = collect($heading['panels'])->firstWhere('key', 'typography');
        $this->assertContains('supports.typography.lineHeight', array_column($typography['controls'], 'source'));
    }
}
