<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Templates;

use Heisenberg\Services\PostTemplateContractValidator;
use PHPUnit\Framework\TestCase;

/**
 * Validates post-template contract *definitions*. Pure/stateless, zero host
 * couplings — mirrors {@see \Heisenberg\Tests\M1\BlockContractValidatorTest}'s
 * style for the post-template contract (docs/post-template-schema.md).
 */
class PostTemplateContractValidatorTest extends TestCase
{
    private function validator(string $prefix = 'heisenberg'): PostTemplateContractValidator
    {
        return new PostTemplateContractValidator($prefix);
    }

    /** A complete, well-formed contract that must pass every validator. */
    private function validContract(): array
    {
        return [
            '$schema' => '../../../docs/post-template-schema.md',
            'apiVersion' => 1,
            'name' => 'heisenberg/article',
            'title' => 'heisenberg::templates.article.title',
            'category' => 'post',
            'icon' => 'newspaper',
            'description' => 'heisenberg::templates.article.description',
            'keywords' => ['article', 'post'],
            'version' => '1.0.0',
            'render' => [
                'view' => 'theme::posts.article',
                'script' => null,
            ],
            'capabilities' => [
                'tableOfContents' => ['enabled' => true, 'source' => 'headings', 'minLevel' => 2, 'maxLevel' => 3, 'title' => 'x'],
                'featuredImage' => ['enabled' => true, 'source' => 'first-image-block', 'context' => 'hero', 'fallback' => null],
                'readingTime' => ['enabled' => true, 'wordsPerMinute' => 200, 'label' => 'x'],
                'authorBox' => ['enabled' => true, 'fields' => ['name' => 'name', 'avatar' => 'avatar_url', 'bio' => 'bio']],
                'shareButtons' => ['enabled' => true, 'networks' => ['x', 'facebook']],
                'breadcrumbs' => ['enabled' => true, 'homeLabel' => 'x', 'categoryAttribute' => null],
                'pagination' => ['enabled' => true, 'mode' => 'prev-next'],
                'postViews' => ['enabled' => true, 'label' => 'x'],
                'comments' => ['enabled' => true, 'allowGuests' => true, 'sortOrder' => 'newest'],
                'relatedPosts' => ['enabled' => true, 'limit' => 3],
                'seoMeta' => ['enabled' => false, 'fields' => ['title']],
            ],
        ];
    }

    private function assertValid(array $contract, string $message = ''): void
    {
        $result = $this->validator()->validate($contract);
        $this->assertTrue($result['valid'], $message . ' :: ' . implode(' | ', $result['errors']));
        $this->assertSame([], $result['errors']);
    }

    private function assertInvalid(array $contract, ?string $needle = null): void
    {
        $result = $this->validator()->validate($contract);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        if ($needle !== null) {
            $joined = strtolower(implode(' | ', $result['errors']));
            $this->assertStringContainsString(strtolower($needle), $joined);
        }
    }

    public function test_a_well_formed_contract_is_valid(): void
    {
        $this->assertValid($this->validContract());
    }

    public function test_a_contract_with_every_capability_disabled_is_valid(): void
    {
        $contract = $this->validContract();
        $contract['capabilities'] = [];
        $this->assertValid($contract, 'an empty capabilities object means everything is off');
    }

    public function test_template_prefix_is_configurable(): void
    {
        $contract = $this->validContract();
        $contract['name'] = 'gtc/article';

        $this->assertFalse($this->validator('heisenberg')->validate($contract)['valid']);
        $this->assertTrue($this->validator('gtc')->validate($contract)['valid']);
    }

    public function test_each_required_top_level_key_is_mandatory(): void
    {
        $keys = [
            '$schema', 'apiVersion', 'name', 'title', 'category', 'icon',
            'description', 'keywords', 'version', 'capabilities', 'render',
        ];
        $this->assertCount(11, $keys);

        foreach ($keys as $key) {
            $contract = $this->validContract();
            unset($contract[$key]);
            $this->assertInvalid($contract, $key);
        }
    }

    public function test_api_version_must_be_integer_one(): void
    {
        foreach ([2, '1', 1.0, true] as $bad) {
            $contract = $this->validContract();
            $contract['apiVersion'] = $bad;
            $this->assertInvalid($contract, 'apiVersion');
        }
    }

    public function test_name_must_match_prefixed_slug(): void
    {
        foreach (['article', 'heisenberg/Article', 'heisenberg/', 'other/article', 'heisenberg/-bad'] as $bad) {
            $contract = $this->validContract();
            $contract['name'] = $bad;
            $this->assertInvalid($contract, 'name');
        }
    }

    public function test_version_must_be_semver(): void
    {
        foreach (['1.0', '1', 'v1.0.0', '1.0.0.0', '1.0.x'] as $bad) {
            $contract = $this->validContract();
            $contract['version'] = $bad;
            $this->assertInvalid($contract, 'version');
        }
    }

    public function test_keywords_must_be_an_array(): void
    {
        $contract = $this->validContract();
        $contract['keywords'] = 'not-an-array';
        $this->assertInvalid($contract, 'keywords');
    }

    public function test_render_view_rejects_unsafe_or_missing_names(): void
    {
        foreach ([null, '', '../evil', '/abs.view', 'has space', 'trailing.', '.leading', 'a//b'] as $bad) {
            $contract = $this->validContract();
            $contract['render']['view'] = $bad;
            $this->assertInvalid($contract, 'render.view');
        }
    }

    public function test_render_view_accepts_namespaced_and_plain_dot_paths(): void
    {
        foreach (['theme::posts.article', 'posts.article', 'heisenberg::templates.article'] as $good) {
            $contract = $this->validContract();
            $contract['render']['view'] = $good;
            $this->assertValid($contract, "view '{$good}' should be accepted");
        }
    }

    public function test_render_script_must_be_null_or_a_safe_js_path(): void
    {
        foreach (['../evil.js', '/abs.js', './x.exe'] as $bad) {
            $contract = $this->validContract();
            $contract['render']['script'] = $bad;
            $this->assertInvalid($contract, 'render.script');
        }

        $contract = $this->validContract();
        $contract['render']['script'] = './article.js';
        $this->assertValid($contract, 'a safe relative .js path is accepted');
    }

    public function test_unknown_capability_is_rejected(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['telekinesis'] = ['enabled' => true];
        $this->assertInvalid($contract, 'telekinesis');
    }

    public function test_capability_enabled_must_be_a_boolean(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['comments']['enabled'] = 'yes';
        $this->assertInvalid($contract, "capability 'comments'");
    }

    public function test_capability_must_be_an_object(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['comments'] = true;
        $this->assertInvalid($contract, "capability 'comments'");
    }

    public function test_table_of_contents_min_level_must_not_exceed_max_level(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['tableOfContents']['minLevel'] = 4;
        $contract['capabilities']['tableOfContents']['maxLevel'] = 2;
        $this->assertInvalid($contract, 'tableOfContents');
    }

    public function test_table_of_contents_source_is_constrained(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['tableOfContents']['source'] = 'curated';
        $this->assertInvalid($contract, 'tableOfContents');
    }

    /**
     * 'entries' (2026-08-10) — the post's own AUTHORED table of contents (Post::tocEntries(),
     * editor Post-tab modal), as opposed to 'headings' deriving the list from the block tree.
     * minLevel/maxLevel don't apply to this source (no heading levels to filter), so a contract
     * that omits them entirely must still be valid.
     */
    public function test_table_of_contents_source_accepts_entries(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['tableOfContents'] = ['enabled' => true, 'source' => 'entries', 'title' => 'x'];
        $this->assertValid($contract);
    }

    public function test_featured_image_requires_a_source_when_enabled(): void
    {
        $contract = $this->validContract();
        unset($contract['capabilities']['featuredImage']['source']);
        $this->assertInvalid($contract, 'featuredImage');
    }

    /**
     * 'post-attribute' (2026-08-10): the post's own featured_image_id FK — what
     * the editor's picker sets. Before it joined the enum, a host template that
     * really rendered the FK had to declare 'first-image-block' for validity.
     */
    public function test_featured_image_accepts_the_post_attribute_source(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['featuredImage']['source'] = 'post-attribute';
        $this->assertValid($contract);
    }

    public function test_featured_image_rejects_an_unknown_source(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['featuredImage']['source'] = 'divination';
        $this->assertInvalid($contract, 'featuredImage');
    }

    public function test_share_buttons_requires_non_empty_networks_when_enabled(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['shareButtons']['networks'] = [];
        $this->assertInvalid($contract, 'shareButtons');
    }

    public function test_share_buttons_rejects_unknown_network(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['shareButtons']['networks'] = ['carrier-pigeon'];
        $this->assertInvalid($contract, 'shareButtons');
    }

    public function test_pagination_requires_mode_when_enabled(): void
    {
        $contract = $this->validContract();
        unset($contract['capabilities']['pagination']['mode']);
        $this->assertInvalid($contract, 'pagination');
    }

    public function test_pagination_numbered_mode_requires_per_page(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['pagination']['mode'] = 'numbered';
        $this->assertInvalid($contract, 'perPage');

        $contract['capabilities']['pagination']['perPage'] = 10;
        $this->assertValid($contract, 'numbered mode with a positive perPage is valid');
    }

    public function test_related_posts_requires_a_positive_limit_when_enabled(): void
    {
        $contract = $this->validContract();
        unset($contract['capabilities']['relatedPosts']['limit']);
        $this->assertInvalid($contract, 'relatedPosts');

        $contract['capabilities']['relatedPosts']['limit'] = 0;
        $this->assertInvalid($contract, 'relatedPosts');
    }

    public function test_author_box_rejects_unknown_field(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['authorBox']['fields'] = ['handle' => 'twitter_handle'];
        $this->assertInvalid($contract, 'authorBox');
    }

    public function test_comments_sort_order_is_constrained(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['comments']['sortOrder'] = 'random';
        $this->assertInvalid($contract, 'comments');
    }

    public function test_comments_accepts_threaded_and_max_depth(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['comments']['threaded'] = true;
        $contract['capabilities']['comments']['maxDepth'] = 3;
        $this->assertValid($contract);
    }

    public function test_comments_rejects_a_non_boolean_threaded(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['comments']['threaded'] = 'yes';
        $this->assertInvalid($contract, "comments' threaded must be a boolean");
    }

    public function test_comments_rejects_a_max_depth_of_zero(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['comments']['maxDepth'] = 0;
        $this->assertInvalid($contract, 'maxDepth');
    }

    public function test_comments_rejects_a_max_depth_above_ten(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['comments']['maxDepth'] = 11;
        $this->assertInvalid($contract, 'maxDepth');
    }

    public function test_seo_meta_rejects_unknown_field(): void
    {
        $contract = $this->validContract();
        $contract['capabilities']['seoMeta']['fields'] = ['keywords'];
        $this->assertInvalid($contract, 'seoMeta');
    }
}
