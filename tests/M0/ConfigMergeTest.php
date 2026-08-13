<?php

declare(strict_types=1);

namespace Heisenberg\Tests\M0;

use Heisenberg\Support\ConfigMerge;
use PHPUnit\Framework\TestCase;

/**
 * Pure array-logic tests for {@see ConfigMerge} — no app boot needed, on
 * purpose (see its docblock: "pure array logic, no framework coupling, easy
 * to unit test"). This is the merge that replaces `mergeConfigFrom()`'s
 * shallow `array_merge()` in HeisenbergServiceProvider::register(), because
 * the shallow merge is what let a host's published config/heisenberg.php
 * silently fall behind the package three separate times (stale provider
 * defaults, a missing ability, a lifecycle edge that made publishing
 * impossible for every role — see ConfigMerge's docblock for the full story).
 */
class ConfigMergeTest extends TestCase
{
    public function test_a_nested_key_absent_from_the_host_is_added_from_the_package_defaults(): void
    {
        $defaults = ['post_template' => ['comments_provider' => 'Native', 'seo_meta_provider' => 'Native']];
        $host = ['post_template' => ['comments_provider' => 'Native']];

        $merged = ConfigMerge::merge($defaults, $host);

        $this->assertSame('Native', $merged['post_template']['seo_meta_provider']);
    }

    public function test_a_host_scalar_is_never_overwritten_by_the_package_default(): void
    {
        $defaults = ['css_prefix' => 'hb'];
        $host = ['css_prefix' => 'acme'];

        $merged = ConfigMerge::merge($defaults, $host);

        $this->assertSame('acme', $merged['css_prefix']);
    }

    /**
     * A LIST (sequential integer keys) is an ATOMIC value, not a collection to
     * merge element-wise. A host that deliberately narrowed a list — e.g.
     * dropping a locale, or tightening middleware — must not have the
     * package's entries silently re-added underneath it.
     */
    public function test_a_host_list_is_preserved_atomically_and_the_package_list_is_never_merged_in(): void
    {
        $defaults = ['locales' => ['en', 'fr', 'de']];
        $host = ['locales' => ['en']];

        $merged = ConfigMerge::merge($defaults, $host);

        $this->assertSame(['en'], $merged['locales']);
    }

    /**
     * Same atomic rule, but for the exact real-world shape that motivated this
     * class: `lifecycle.transitions.draft` is a list of allowed target
     * statuses. Even though the package's list here carries an edge
     * (`published`) the host's list lacks, the merge must NOT inject it — that
     * is the documented, honest limitation: a deep merge fixes MISSING KEYS,
     * not stale CONTENT inside a list the host already owns.
     */
    public function test_lifecycle_transitions_draft_list_content_is_not_reconciled_by_the_merge(): void
    {
        $defaults = ['lifecycle' => ['transitions' => ['draft' => ['pending_review', 'published', 'scheduled', 'archived']]]];
        $host = ['lifecycle' => ['transitions' => ['draft' => ['pending_review']]]];

        $merged = ConfigMerge::merge($defaults, $host);

        $this->assertSame(['pending_review'], $merged['lifecycle']['transitions']['draft']);
    }

    public function test_deeply_nested_associative_arrays_merge_at_every_level(): void
    {
        $defaults = [
            'ai' => [
                'mcp' => [
                    'client' => ['enabled' => false, 'timeout' => 30, 'max_iterations' => 16],
                    'server' => ['enabled' => false, 'path' => 'heisenberg/mcp'],
                ],
            ],
        ];
        $host = [
            'ai' => [
                'mcp' => [
                    'client' => ['enabled' => true], // host turned it on, nothing else set
                ],
            ],
        ];

        $merged = ConfigMerge::merge($defaults, $host);

        $this->assertTrue($merged['ai']['mcp']['client']['enabled']); // host value preserved
        $this->assertSame(30, $merged['ai']['mcp']['client']['timeout']); // package fill-in
        $this->assertSame(16, $merged['ai']['mcp']['client']['max_iterations']); // package fill-in
        $this->assertSame(['enabled' => false, 'path' => 'heisenberg/mcp'], $merged['ai']['mcp']['server']); // whole missing branch added
    }

    /**
     * A host may deliberately set a key to `null` (e.g. to disable a feature
     * whose presence, not absence, is what the package checks for). `null` is
     * not "absent" — `array_key_exists()` is true for it — so it must be
     * preserved exactly like any other host value, never replaced by the
     * package's non-null default.
     */
    public function test_a_host_key_explicitly_set_to_null_is_preserved(): void
    {
        $defaults = ['seo' => ['url_template' => 'https://example.com/{locale}/blog/{slug}']];
        $host = ['seo' => ['url_template' => null]];

        $merged = ConfigMerge::merge($defaults, $host);

        $this->assertNull($merged['seo']['url_template']);
        $this->assertArrayHasKey('url_template', $merged['seo']);
    }

    public function test_a_key_entirely_absent_from_the_host_top_level_is_added_wholesale(): void
    {
        $defaults = ['roles' => ['comments.moderate' => ['admin', 'editor']]];
        $host = [];

        $merged = ConfigMerge::merge($defaults, $host);

        $this->assertSame(['admin', 'editor'], $merged['roles']['comments.moderate']);
    }

    public function test_an_associative_array_on_the_package_side_facing_a_list_on_the_host_side_is_left_to_the_host(): void
    {
        // Type mismatch (package now ships an associative map where the host's
        // published value is a plain list, or vice versa) is not recursed into
        // — the host's value wins whole, same as any other leaf.
        $defaults = ['components' => ['article_card' => ['blade' => 'x']]];
        $host = ['components' => []];

        $merged = ConfigMerge::merge($defaults, $host);

        $this->assertSame([], $merged['components']);
    }
}
