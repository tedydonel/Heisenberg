<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Rendering\BlockTreeRenderer;
use Heisenberg\Rendering\CssValueSanitizer;
use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\EmailBlockCoverageService;
use Heisenberg\Tests\TestCase;

/**
 * docs/email-system.md §4 — {@see EmailBlockCoverageService} answers, purely by reading the
 * live block CONTRACTS, which registered blocks have no `email` template at all (certain to
 * render empty — {@see BlockTreeRenderer}) and which placed instances in a
 * document actually degrade once rendered for email (a gradient background flattened, an
 * alignment or conditional style skipped — {@see BlockTreeRenderer::resolveClass()},
 * {@see CssValueSanitizer}).
 *
 * Every assertion here is derived from the REAL shipped registry via
 * `new BlockRegistryService(new BlockContractValidator('heisenberg'))` (the same pattern
 * tests/Email/EmailContractTest.php uses) — never a hardcoded block-name list — so this test
 * fails the moment a contract changes what it covers, rather than silently going stale.
 */
class EmailBlockCoverageServiceTest extends TestCase
{
    private function registry(): BlockRegistryService
    {
        return new BlockRegistryService(new BlockContractValidator('heisenberg'));
    }

    private function service(): EmailBlockCoverageService
    {
        return new EmailBlockCoverageService($this->registry());
    }

    // ── registry-level coverage (item 1: derived from contracts, not a fixed list) ──────

    public function test_uncovered_block_names_are_exactly_the_ones_whose_own_contract_has_no_email_template(): void
    {
        $registry = $this->registry();
        $service = new EmailBlockCoverageService($registry);

        // Ground truth computed the SAME way the shipped registry itself already does
        // (BlockRegistryService::contractsFor()) — never a hardcoded expectation — so this
        // test can't drift from the renderer's own "presence is the whole signal" rule.
        $allNames = array_map(
            static fn (array $c): string => (string) $c['name'],
            $registry->discover()['blocks']
        );
        $coveredNames = array_map(
            static fn (array $c): string => (string) $c['name'],
            $registry->contractsFor('email')
        );
        $expectedUncovered = array_values(array_diff($allNames, $coveredNames));
        sort($expectedUncovered);

        $actual = $service->uncoveredBlockNames();
        sort($actual);

        $this->assertSame($expectedUncovered, $actual);
        // Documents the two known gaps (§4) without hardcoding them as the SOURCE of truth above.
        $this->assertContains('heisenberg/embed', $actual);
        $this->assertContains('heisenberg/icon', $actual);
        $this->assertCount(2, $actual);
    }

    public function test_is_covered_for_email_agrees_with_uncovered_block_names(): void
    {
        $service = $this->service();
        $uncovered = $service->uncoveredBlockNames();

        foreach ($this->registry()->discover()['blocks'] as $contract) {
            $name = (string) $contract['name'];
            $this->assertSame(
                ! in_array($name, $uncovered, true),
                $service->isCoveredForEmail($name),
                "isCoveredForEmail() disagrees with uncoveredBlockNames() for '{$name}'."
            );
        }
    }

    public function test_is_covered_for_email_is_false_for_an_unknown_block(): void
    {
        $this->assertFalse($this->service()->isCoveredForEmail('heisenberg/does-not-exist'));
    }

    public function test_registry_coverage_reports_every_registered_block(): void
    {
        $registry = $this->registry();
        $coverage = $this->service()->registryCoverage();

        $expectedNames = array_map(static fn (array $c): string => (string) $c['name'], $registry->discover()['blocks']);
        sort($expectedNames);
        $actualNames = array_keys($coverage);
        sort($actualNames);

        $this->assertSame($expectedNames, $actualNames);
    }

    public function test_registry_coverage_signature_shape_for_a_covered_block(): void
    {
        $coverage = $this->service()->registryCoverage();

        $this->assertArrayHasKey('heisenberg/group', $coverage);
        $group = $coverage['heisenberg/group'];

        $this->assertTrue($group['hasEmailTemplate']);
        $this->assertNotSame('', $group['className']);
        $this->assertGreaterThan(0, $group['classNamesCount']);
        $this->assertContains('center', $group['alignSupport']);
        $this->assertContains('--hb-group-bg', $group['gradientCapableVariables']);
        // Capability, not instance data — group supports align/gradient/conditional classes,
        // so its CONTRACT is "degradable" even with no document in view.
        $this->assertTrue($group['degradable']);
    }

    public function test_registry_coverage_signature_for_an_uncovered_block(): void
    {
        $coverage = $this->service()->registryCoverage();

        $this->assertArrayHasKey('heisenberg/icon', $coverage);
        $this->assertFalse($coverage['heisenberg/icon']['hasEmailTemplate']);
        $this->assertArrayHasKey('heisenberg/embed', $coverage);
        $this->assertFalse($coverage['heisenberg/embed']['hasEmailTemplate']);
    }

    // ── document-level report (item 1: per-document dropped/degraded) ──────────────────

    public function test_document_report_is_clean_for_a_document_with_no_email_incompatible_blocks(): void
    {
        $blocks = [
            ['id' => 'h1', 'name' => 'heisenberg/heading', 'attributes' => [], 'supports' => [], 'innerBlocks' => []],
            ['id' => 'p1', 'name' => 'heisenberg/paragraph', 'attributes' => [], 'supports' => [], 'innerBlocks' => []],
        ];

        $report = $this->service()->documentReport($blocks);

        $this->assertSame([], $report['dropped']);
        $this->assertSame([], $report['degraded']);
    }

    public function test_document_report_flags_an_icon_and_an_embed_block_as_dropped(): void
    {
        $blocks = [
            ['id' => 'i1', 'name' => 'heisenberg/icon', 'attributes' => [], 'supports' => [], 'innerBlocks' => []],
            ['id' => 'e1', 'name' => 'heisenberg/embed', 'attributes' => [], 'supports' => [], 'innerBlocks' => []],
            ['id' => 'p1', 'name' => 'heisenberg/paragraph', 'attributes' => [], 'supports' => [], 'innerBlocks' => []],
        ];

        $report = $this->service()->documentReport($blocks);

        $droppedNames = array_column($report['dropped'], 'name');
        sort($droppedNames);
        $this->assertSame(['heisenberg/embed', 'heisenberg/icon'], $droppedNames);
        $this->assertSame(['i1', 'e1'], array_column($report['dropped'], 'id'));
        $this->assertSame([], $report['degraded']);
    }

    public function test_document_report_recurses_into_inner_blocks(): void
    {
        $blocks = [
            [
                'id' => 'col1',
                'name' => 'heisenberg/columns',
                'attributes' => [],
                'supports' => [],
                'innerBlocks' => [
                    [
                        'id' => 'c1',
                        'name' => 'heisenberg/column',
                        'attributes' => [],
                        'supports' => [],
                        'innerBlocks' => [
                            ['id' => 'i1', 'name' => 'heisenberg/icon', 'attributes' => [], 'supports' => [], 'innerBlocks' => []],
                        ],
                    ],
                ],
            ],
        ];

        $report = $this->service()->documentReport($blocks);

        $this->assertSame(['heisenberg/icon'], array_column($report['dropped'], 'name'));
        $this->assertSame(['i1'], array_column($report['dropped'], 'id'));
    }

    public function test_document_report_ignores_an_unregistered_block_name(): void
    {
        $blocks = [
            ['id' => 'x1', 'name' => 'heisenberg/not-a-real-block', 'attributes' => [], 'supports' => [], 'innerBlocks' => []],
        ];

        $report = $this->service()->documentReport($blocks);

        $this->assertSame([], $report['dropped']);
        $this->assertSame([], $report['degraded']);
    }

    public function test_document_report_flags_an_authored_gradient_background_as_degraded(): void
    {
        $blocks = [[
            'id' => 'g1',
            'name' => 'heisenberg/group',
            'attributes' => [],
            'supports' => ['color' => ['background' => 'linear-gradient(90deg, #ff0000, #0000ff)']],
            'innerBlocks' => [],
        ]];

        $report = $this->service()->documentReport($blocks);

        $this->assertSame([], $report['dropped']);
        $this->assertCount(1, $report['degraded']);
        $this->assertSame('heisenberg/group', $report['degraded'][0]['name']);
        $this->assertContains('gradient-background', $report['degraded'][0]['reasons']);
    }

    public function test_document_report_does_not_flag_a_flat_colour_background(): void
    {
        $blocks = [[
            'id' => 'g1',
            'name' => 'heisenberg/group',
            'attributes' => [],
            'supports' => ['color' => ['background' => '#ff0000']],
            'innerBlocks' => [],
        ]];

        $report = $this->service()->documentReport($blocks);

        $this->assertSame([], $report['degraded']);
    }

    public function test_document_report_flags_an_authored_alignment_as_degraded(): void
    {
        $blocks = [[
            'id' => 'g1',
            'name' => 'heisenberg/group',
            'attributes' => [],
            'supports' => ['align' => 'center'],
            'innerBlocks' => [],
        ]];

        $report = $this->service()->documentReport($blocks);

        $this->assertCount(1, $report['degraded']);
        $this->assertSame(['align'], $report['degraded'][0]['reasons']);
    }

    public function test_document_report_does_not_flag_an_unset_alignment(): void
    {
        $blocks = [[
            'id' => 'g1',
            'name' => 'heisenberg/group',
            'attributes' => [],
            'supports' => [],
            'innerBlocks' => [],
        ]];

        $report = $this->service()->documentReport($blocks);

        $this->assertSame([], $report['degraded']);
    }

    public function test_document_report_flags_a_matched_conditional_class_as_degraded(): void
    {
        $blocks = [[
            'id' => 'g1',
            'name' => 'heisenberg/group',
            'attributes' => ['hideMobile' => true],
            'supports' => [],
            'innerBlocks' => [],
        ]];

        $report = $this->service()->documentReport($blocks);

        $this->assertCount(1, $report['degraded']);
        $this->assertSame(['conditional-class'], $report['degraded'][0]['reasons']);
    }

    public function test_document_report_does_not_flag_an_unmatched_conditional_class(): void
    {
        $blocks = [[
            'id' => 'g1',
            'name' => 'heisenberg/group',
            'attributes' => ['hideMobile' => false],
            'supports' => [],
            'innerBlocks' => [],
        ]];

        $report = $this->service()->documentReport($blocks);

        $this->assertSame([], $report['degraded']);
    }

    public function test_document_report_can_combine_multiple_degradation_reasons_on_one_instance(): void
    {
        $blocks = [[
            'id' => 'g1',
            'name' => 'heisenberg/group',
            'attributes' => ['hideMobile' => true],
            'supports' => [
                'align' => 'center',
                'color' => ['background' => 'radial-gradient(circle, #ff0000, #0000ff)'],
            ],
            'innerBlocks' => [],
        ]];

        $report = $this->service()->documentReport($blocks);

        $this->assertCount(1, $report['degraded']);
        $reasons = $report['degraded'][0]['reasons'];
        sort($reasons);
        $this->assertSame(['align', 'conditional-class', 'gradient-background'], $reasons);
    }

    public function test_document_summary_shape(): void
    {
        $blocks = [
            ['id' => 'i1', 'name' => 'heisenberg/icon', 'attributes' => [], 'supports' => [], 'innerBlocks' => []],
            ['id' => 'g1', 'name' => 'heisenberg/group', 'attributes' => [], 'supports' => ['align' => 'center'], 'innerBlocks' => []],
        ];

        $summary = $this->service()->documentSummary($blocks);

        $this->assertSame(1, $summary['dropped_count']);
        $this->assertSame(1, $summary['degraded_count']);
        $this->assertSame(['heisenberg/icon'], $summary['dropped_names']);
        $this->assertSame(['heisenberg/group'], $summary['degraded_names']);
        $this->assertArrayHasKey('report', $summary);
    }

    public function test_document_summary_is_clean_for_an_empty_document(): void
    {
        $summary = $this->service()->documentSummary([]);

        $this->assertSame(0, $summary['dropped_count']);
        $this->assertSame(0, $summary['degraded_count']);
        $this->assertSame([], $summary['dropped_names']);
        $this->assertSame([], $summary['degraded_names']);
    }

    public function test_reason_description_never_returns_an_empty_string(): void
    {
        $service = $this->service();

        foreach (['gradient-background', 'align', 'conditional-class', 'something-unknown'] as $reason) {
            $this->assertNotSame('', $service->reasonDescription($reason));
        }
    }
}
