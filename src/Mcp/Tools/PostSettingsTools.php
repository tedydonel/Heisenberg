<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\PostAccess;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\McpToolException;
use Illuminate\Support\Facades\DB;

/**
 * Lightweight post settings that mirror single editor panels one-for-one — page
 * padding, comments, featured image — none of which touch content or content_version.
 * No `surface` entry on any of these three: a post setting makes as much sense to an
 * external agent as to the in-editor assistant, unlike a content write (create_post/
 * update_post) which stays draft-only externally.
 */
final class PostSettingsTools implements McpToolProvider
{
    public function __construct(private PostAccess $posts)
    {
    }

    public function definitions(): array
    {
        return [
            'set_page_layout' => [
                'description' => 'Set a post\'s page padding, in pixels (0-400). Mirrors the editor\'s Page Layout panel. Does not touch content or content_version.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'page_padding_x' => ['type' => 'integer', 'description' => '0-400.'],
                    'page_padding_y' => ['type' => 'integer', 'description' => '0-400.'],
                ], ['post_id', 'page_padding_x', 'page_padding_y']),
            ],

            'set_discussion' => [
                'description' => 'Set whether comments are allowed on a post. Mirrors the editor\'s Discussion panel. Does not touch content or content_version.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'allow_comments' => ['type' => 'boolean'],
                ], ['post_id', 'allow_comments']),
            ],

            // Mirrors PostSettingsController::updateFeaturedImage's posture exactly (direct
            // property write on the guarded `featured_image_id` column — see Post::$fillable's
            // own docblock) — no `surface` entry, same as set_page_layout/set_discussion just
            // above: a featured image is a lightweight post setting, not a content write that
            // needs the draft-only external-surface posture create_post/update_post hold.
            'set_featured_image' => [
                'description' => 'Set (or clear) a post\'s featured image. Pass file_id to set it, or omit/null to clear it. Mirrors the editor\'s Featured image setting. Does not touch content or content_version.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'file_id' => ['type' => 'integer', 'description' => 'Public file id (see list_media). Omit or pass null to clear the featured image.'],
                ], ['post_id']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['set_page_layout', 'set_discussion', 'set_featured_image'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'set_page_layout' => $this->setPageLayout($arguments),
            'set_discussion' => $this->setDiscussion($arguments),
            'set_featured_image' => $this->setFeaturedImage($arguments),
            default => throw new \LogicException("PostSettingsTools does not handle '{$tool}'."),
        };
    }

    /** @param array<string, mixed> $args */
    private function setPageLayout(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        if (! array_key_exists('page_padding_x', $args) || ! array_key_exists('page_padding_y', $args)) {
            throw new McpToolException('page_padding_x and page_padding_y are both required.');
        }
        $x = (int) $args['page_padding_x'];
        $y = (int) $args['page_padding_y'];
        foreach (['page_padding_x' => $x, 'page_padding_y' => $y] as $label => $value) {
            if ($value < 0 || $value > 400) {
                throw new McpToolException("{$label} must be between 0 and 400 (got {$value}).");
            }
        }
        $post->page_padding_x = $x;
        $post->page_padding_y = $y;
        $post->save();

        return ['post_id' => $post->getKey(), 'page_padding_x' => $post->page_padding_x, 'page_padding_y' => $post->page_padding_y];
    }

    /** @param array<string, mixed> $args */
    private function setDiscussion(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        if (! array_key_exists('allow_comments', $args) || ! is_bool($args['allow_comments'])) {
            throw new McpToolException('allow_comments must be a boolean.');
        }
        $post->allow_comments = $args['allow_comments'];
        $post->save();

        return ['post_id' => $post->getKey(), 'allow_comments' => $post->allow_comments];
    }

    /**
     * `file_id` null/omitted clears it; a non-null id must point at a real, image-type
     * {@see PublicFile} — {@see PublicFile::isImageType()} makes that check cheap enough not to
     * skip: a featured image slot rendered as a PDF icon is a worse failure mode than refusing
     * the write here.
     *
     * No propagation: the single-row translation model (docs/content-translation.md §0) means
     * one post row owns one featured image — there is no sibling row left to keep in sync.
     *
     * @param array<string, mixed> $args
     */
    private function setFeaturedImage(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $fileId = $args['file_id'] ?? null;

        if ($fileId === null) {
            return DB::transaction(function () use ($post): array {
                $post->featured_image_id = null;
                $post->save();

                return ['post_id' => $post->getKey(), 'featured_image_id' => null];
            });
        }

        $class = (string) config('heisenberg.models.public_file', PublicFile::class);
        $file = $class::query()->find((int) $fileId);
        if ($file === null) {
            throw new McpToolException('No media file with id ' . (int) $fileId . '.');
        }
        if (! $file->isImageType()) {
            throw new McpToolException("File {$file->getKey()} is type \"{$file->type}\", not an image — the featured image must be an image file.");
        }

        return DB::transaction(function () use ($post, $file): array {
            $post->featured_image_id = $file->getKey();
            $post->save();

            return ['post_id' => $post->getKey(), 'featured_image_id' => $post->featured_image_id];
        });
    }
}
