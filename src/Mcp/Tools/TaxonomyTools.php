<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\PostAccess;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Models\Category;
use Heisenberg\Models\Tag;
use Heisenberg\Services\McpToolException;

/**
 * Categories and tags: listing, CRUD, and attach/detach against a post. Bilingual name/
 * description fields live on ONE row (docs/content-translation.md §6) — unlike Post,
 * which splits per locale (§1) — so "translating" a category or tag is just filling in
 * its `name_fr`/`description_fr` columns via {@see ToolSchema::bilingualUpdateFields()},
 * the same "at least one field, each capped at its column's length" rule `update_media`
 * also uses.
 */
final class TaxonomyTools implements McpToolProvider
{
    private const TOOLS = [
        'list_categories', 'list_tags', 'create_category', 'update_category',
        'create_tag', 'update_tag', 'attach_category', 'detach_category',
        'attach_tag', 'detach_tag',
    ];

    public function __construct(private PostAccess $posts)
    {
    }

    public function definitions(): array
    {
        return [
            'list_categories' => [
                'description' => 'Every category.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([]),
            ],

            'list_tags' => [
                'description' => 'Every tag.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([]),
            ],

            'create_category' => [
                'description' => 'Create a category. The slug is derived from name_en automatically (numeric-suffixed on collision) unless an explicit `slug` is supplied.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'name_en' => ['type' => 'string'],
                    'name_fr' => ['type' => 'string'],
                    'slug' => ['type' => 'string', 'description' => 'Optional explicit slug; auto-derived from name_en when omitted.'],
                    'parent_id' => ['type' => 'integer'],
                    'description_en' => ['type' => 'string'],
                    'description_fr' => ['type' => 'string'],
                ], ['name_en']),
            ],

            // Bilingual taxonomy edit (docs/content-translation.md §6): category/tag names and
            // descriptions live on ONE row (unlike Post, which splits per locale — §1), so
            // "translating" a category is just filling in its name_fr/description_fr columns.
            'update_category' => [
                'description' => 'Update a category\'s bilingual name/description (e.g. fill in name_fr after create_category only set name_en). Supply at least one field; any field left out keeps its current value.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'category_id' => ['type' => 'integer'],
                    'name_en' => ['type' => 'string'],
                    'name_fr' => ['type' => 'string'],
                    'description_en' => ['type' => 'string'],
                    'description_fr' => ['type' => 'string'],
                ], ['category_id']),
            ],

            'create_tag' => [
                'description' => 'Create a tag. The slug is derived from name_en automatically (numeric-suffixed on collision) unless an explicit `slug` is supplied.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'name_en' => ['type' => 'string'],
                    'name_fr' => ['type' => 'string'],
                    'slug' => ['type' => 'string', 'description' => 'Optional explicit slug; auto-derived from name_en when omitted.'],
                ], ['name_en']),
            ],

            'update_tag' => [
                'description' => 'Update a tag\'s bilingual name (e.g. fill in name_fr after create_tag only set name_en). Supply at least one field; any field left out keeps its current value.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'tag_id' => ['type' => 'integer'],
                    'name_en' => ['type' => 'string'],
                    'name_fr' => ['type' => 'string'],
                ], ['tag_id']),
            ],

            'attach_category' => [
                'description' => 'Attach a category to a post.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'category_id' => ['type' => 'integer'],
                ], ['post_id', 'category_id']),
            ],

            'detach_category' => [
                'description' => 'Detach a category from a post.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'category_id' => ['type' => 'integer'],
                ], ['post_id', 'category_id']),
            ],

            'attach_tag' => [
                'description' => 'Attach a tag to a post.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'tag_id' => ['type' => 'integer'],
                ], ['post_id', 'tag_id']),
            ],

            'detach_tag' => [
                'description' => 'Detach a tag from a post.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'tag_id' => ['type' => 'integer'],
                ], ['post_id', 'tag_id']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, self::TOOLS, true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'list_categories' => $this->taxonomy(Category::class, 'category'),
            'list_tags' => $this->taxonomy(Tag::class, 'tag'),
            'create_category' => $this->createCategory($arguments),
            'update_category' => $this->updateCategory($arguments),
            'create_tag' => $this->createTag($arguments),
            'update_tag' => $this->updateTag($arguments),
            'attach_category' => $this->attachCategory($arguments),
            'detach_category' => $this->detachCategory($arguments),
            'attach_tag' => $this->attachTag($arguments),
            'detach_tag' => $this->detachTag($arguments),
            default => throw new \LogicException("TaxonomyTools does not handle '{$tool}'."),
        };
    }

    /** @return list<array<string, mixed>> */
    private function taxonomy(string $default, string $configKey): array
    {
        $class = (string) config("heisenberg.models.{$configKey}", $default);

        return $class::query()->orderBy('name_en')->get()
            ->map(static fn ($row): array => [
                'id' => $row->getKey(),
                'name' => (string) ($row->name_en ?? ''),
                'slug' => (string) ($row->slug ?? ''),
            ])->all();
    }

    /** @param array<string, mixed> $args */
    private function createCategory(array $args): array
    {
        $name = trim((string) ($args['name_en'] ?? ''));
        if ($name === '') {
            throw new McpToolException('name_en is required.');
        }
        $class = (string) config('heisenberg.models.category', Category::class);
        $category = $class::create(array_filter([
            'name_en' => $name,
            'name_fr' => $args['name_fr'] ?? null,
            'slug' => $args['slug'] ?? null,
            'parent_id' => $args['parent_id'] ?? null,
            'description_en' => $args['description_en'] ?? null,
            'description_fr' => $args['description_fr'] ?? null,
        ], static fn (mixed $v): bool => $v !== null));

        return ['id' => $category->getKey(), 'name_en' => $category->name_en, 'slug' => $category->slug];
    }

    /** @param array<string, mixed> $args */
    private function updateCategory(array $args): array
    {
        $class = (string) config('heisenberg.models.category', Category::class);
        $category = $class::query()->find((int) ($args['category_id'] ?? 0));
        if ($category === null) {
            throw new McpToolException('No category with id ' . (int) ($args['category_id'] ?? 0) . '.');
        }

        $fields = ToolSchema::bilingualUpdateFields(
            $args,
            ['name_en', 'name_fr', 'description_en', 'description_fr'],
            ['name_en' => 255, 'name_fr' => 255],
        );
        if (array_key_exists('name_en', $fields) && trim($fields['name_en']) === '') {
            throw new McpToolException('name_en cannot be set to an empty string.');
        }

        foreach ($fields as $field => $value) {
            $category->{$field} = $value;
        }
        $category->save();

        return [
            'id' => $category->getKey(),
            'name_en' => $category->name_en,
            'name_fr' => $category->name_fr,
            'description_en' => $category->description_en,
            'description_fr' => $category->description_fr,
        ];
    }

    /** @param array<string, mixed> $args */
    private function createTag(array $args): array
    {
        $name = trim((string) ($args['name_en'] ?? ''));
        if ($name === '') {
            throw new McpToolException('name_en is required.');
        }
        $class = (string) config('heisenberg.models.tag', Tag::class);
        $tag = $class::create(array_filter([
            'name_en' => $name,
            'name_fr' => $args['name_fr'] ?? null,
            'slug' => $args['slug'] ?? null,
        ], static fn (mixed $v): bool => $v !== null));

        return ['id' => $tag->getKey(), 'name_en' => $tag->name_en, 'slug' => $tag->slug];
    }

    /** @param array<string, mixed> $args */
    private function updateTag(array $args): array
    {
        $class = (string) config('heisenberg.models.tag', Tag::class);
        $tag = $class::query()->find((int) ($args['tag_id'] ?? 0));
        if ($tag === null) {
            throw new McpToolException('No tag with id ' . (int) ($args['tag_id'] ?? 0) . '.');
        }

        $fields = ToolSchema::bilingualUpdateFields($args, ['name_en', 'name_fr'], ['name_en' => 255, 'name_fr' => 255]);
        if (array_key_exists('name_en', $fields) && trim($fields['name_en']) === '') {
            throw new McpToolException('name_en cannot be set to an empty string.');
        }

        foreach ($fields as $field => $value) {
            $tag->{$field} = $value;
        }
        $tag->save();

        return ['id' => $tag->getKey(), 'name_en' => $tag->name_en, 'name_fr' => $tag->name_fr];
    }

    /** @param array<string, mixed> $args */
    private function attachCategory(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $post->categories()->syncWithoutDetaching([(int) $args['category_id']]);

        return ['attached' => true, 'post_id' => $post->getKey()];
    }

    /** @param array<string, mixed> $args */
    private function detachCategory(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $post->categories()->detach((int) $args['category_id']);

        return ['detached' => true, 'post_id' => $post->getKey()];
    }

    /** @param array<string, mixed> $args */
    private function attachTag(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $post->tags()->syncWithoutDetaching([(int) $args['tag_id']]);

        return ['attached' => true, 'post_id' => $post->getKey()];
    }

    /** @param array<string, mixed> $args */
    private function detachTag(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $post->tags()->detach((int) $args['tag_id']);

        return ['detached' => true, 'post_id' => $post->getKey()];
    }
}
