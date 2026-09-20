<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\PostAccess;
use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Services\McpToolException;
use Heisenberg\Services\SeoAnalyzer;
use Heisenberg\Support\LocaleConfig;

/**
 * docs/seo-system.md §6. `get_seo`/`analyze_seo` are read-only; `update_seo` is the one
 * write path, matching NativeSeoMetaProvider/PostController::applySeo's own
 * updateOrCreate-on-(able_type,able_id) shape so the DB never carries two rows for one
 * post. Available on both surfaces — SEO metadata is a post attribute, not the live
 * canvas, so it makes as much sense to an external agent as the post-settings tools
 * ({@see PostSettingsTools}) — same "no `surface` entry" posture.
 */
final class SeoTools implements McpToolProvider
{
    public function __construct(private PostAccess $posts)
    {
    }

    public function definitions(): array
    {
        return [
            'get_seo' => [
                'description' => 'Read a post\'s SEO/social metadata row (all fields, both locales) — the SeoMeta row create_post/update_post never touch. `has_seo` is false when the post has no row yet (nothing has been set). Does not run the SEO analyzer — call analyze_seo for that.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema(['post_id' => ['type' => 'integer']], ['post_id']),
            ],

            'update_seo' => [
                'description' => 'Set a post\'s SEO/social metadata. `locale` routes meta_title/meta_description/og_title/og_description/focus_keyphrase to that locale\'s column (defaults to the post\'s own locale); og_image/canonical_url/robots/schema_type/schema_data/in_sitemap are locale-neutral and apply directly. Supply at least one field. updateOrCreate\'s the row (no prior get_seo call required). robots is comma-separated tokens from index/noindex/follow/nofollow. schema_data is a JSON object merged under the computed JSON-LD defaults (see get_seo). Returns the updated row, same shape as get_seo.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'locale' => ['type' => 'string', 'description' => 'Which locale meta_title/meta_description/og_title/og_description/focus_keyphrase apply to. Defaults to the post\'s own locale.'],
                    'meta_title' => ['type' => 'string', 'description' => 'Localized. Ideal length 30-60 characters. Max 255.'],
                    'meta_description' => ['type' => 'string', 'description' => 'Localized. Ideal length 50-160 characters. Max 255.'],
                    'og_title' => ['type' => 'string', 'description' => 'Localized. Max 255.'],
                    'og_description' => ['type' => 'string', 'description' => 'Localized. Max 255.'],
                    'og_image' => ['type' => 'string', 'description' => 'Locale-neutral. An image URL. Max 255.'],
                    'canonical_url' => ['type' => 'string', 'description' => 'Locale-neutral. An absolute URL. Max 255.'],
                    'robots' => ['type' => 'string', 'description' => 'Locale-neutral. Comma-separated tokens from index/noindex/follow/nofollow, e.g. "index, follow". Max 255.'],
                    'focus_keyphrase' => ['type' => 'string', 'description' => 'Localized. The phrase analyze_seo scores this post against. Max 255.'],
                    'in_sitemap' => ['type' => 'boolean', 'description' => 'Locale-neutral. Include this post in /sitemap.xml.'],
                    'schema_type' => ['type' => 'string', 'description' => 'Locale-neutral. Schema.org @type, e.g. "Article". Max 255.'],
                    'schema_data' => ['type' => 'object', 'description' => 'Locale-neutral. Extra JSON-LD keys merged under the computed defaults.'],
                ], ['post_id']),
            ],

            'analyze_seo' => [
                'description' => 'Run the SEO checklist/score against a post\'s SAVED SeoMeta + content (no draft overrides — this is the tool surface, not the editor panel\'s live re-scoring). Returns {score, rating, checks[]} — each check has id/group/status(pass|warn|fail|na)/weight/message. `na` means "nothing to score against" (e.g. no images on a text-only post) and does NOT count toward the score. Workflow: analyze_seo, fix the worst-weighted fail/warn checks with update_seo (or by editing content), analyze_seo again.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([
                    'post_id' => ['type' => 'integer'],
                    'locale' => ['type' => 'string', 'description' => 'Defaults to the post\'s own locale.'],
                ], ['post_id']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['get_seo', 'update_seo', 'analyze_seo'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'get_seo' => $this->getSeo($arguments),
            'update_seo' => $this->updateSeo($arguments),
            'analyze_seo' => $this->analyzeSeo($arguments),
            default => throw new \LogicException("SeoTools does not handle '{$tool}'."),
        };
    }

    /** @param array<string, mixed> $args */
    private function getSeo(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $seo = $this->seoMetaClass()::query()
            ->where('able_type', $post->getMorphClass())
            ->where('able_id', $post->getKey())
            ->first();

        return [
            'post_id' => $post->getKey(),
            'has_seo' => $seo !== null,
            'seo' => $seo === null ? null : $this->seoMetaPayload($seo),
        ];
    }

    /** @param array<string, mixed> $args */
    private function analyzeSeo(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $locale = $this->resolveSeoLocale($args, $post);
        $analyzer = app(SeoAnalyzer::class);

        return $analyzer->analyze($post, $locale);
    }

    /**
     * `update_seo` — validates and `updateOrCreate`s a post's {@see SeoMeta} row
     * (docs/seo-system.md §6). The localized fields (meta_title, meta_description, og_title,
     * og_description, focus_keyphrase) route to `{field}_{locale}`; the rest are locale-neutral
     * columns written as-is. At least one field must be present — an update with nothing to
     * change is a caller mistake, same posture as {@see ToolSchema::bilingualUpdateFields()}.
     *
     * @param array<string, mixed> $args
     */
    private function updateSeo(array $args): array
    {
        $post = $this->posts->findPost($args['post_id'] ?? null);
        $locale = $this->resolveSeoLocale($args, $post);

        $localizedFields = ['meta_title', 'meta_description', 'og_title', 'og_description', 'focus_keyphrase'];
        $neutralStringFields = ['og_image' => 255, 'canonical_url' => 255, 'robots' => 255, 'schema_type' => 255];
        $allFields = [...$localizedFields, ...array_keys($neutralStringFields), 'in_sitemap', 'schema_data'];

        if (array_intersect_key($args, array_flip($allFields)) === []) {
            throw new McpToolException('Supply at least one of: ' . implode(', ', $allFields) . '.');
        }

        $data = [];

        foreach ($localizedFields as $field) {
            if (! array_key_exists($field, $args) || ! is_string($args[$field])) {
                continue;
            }
            $value = trim($args[$field]);
            if (mb_strlen($value) > 255) {
                throw new McpToolException("{$field} must be 255 characters or fewer (got " . mb_strlen($value) . ').');
            }
            $data["{$field}_{$locale}"] = $value;
        }

        foreach ($neutralStringFields as $field => $cap) {
            if (! array_key_exists($field, $args) || ! is_string($args[$field])) {
                continue;
            }
            $value = trim($args[$field]);
            if (mb_strlen($value) > $cap) {
                throw new McpToolException("{$field} must be {$cap} characters or fewer (got " . mb_strlen($value) . ').');
            }
            if ($field === 'robots' && $value !== '') {
                $this->validateRobots($value);
            }
            $data[$field] = $value;
        }

        if (array_key_exists('in_sitemap', $args)) {
            if (! is_bool($args['in_sitemap'])) {
                throw new McpToolException('in_sitemap must be a boolean.');
            }
            $data['in_sitemap'] = $args['in_sitemap'];
        }

        if (array_key_exists('schema_data', $args)) {
            if (! is_array($args['schema_data'])) {
                throw new McpToolException('schema_data must be a JSON object.');
            }
            $data['schema_data'] = $args['schema_data'];
        }

        $seo = $this->seoMetaClass()::query()->updateOrCreate(
            ['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()],
            $data,
        );

        return [
            'post_id' => $post->getKey(),
            'has_seo' => true,
            'seo' => $this->seoMetaPayload($seo),
        ];
    }

    /** Comma-separated tokens, each one of index/noindex/follow/nofollow (case-insensitive). */
    private function validateRobots(string $robots): void
    {
        $allowed = ['index', 'noindex', 'follow', 'nofollow'];
        foreach (explode(',', $robots) as $token) {
            $token = strtolower(trim($token));
            if (! in_array($token, $allowed, true)) {
                throw new McpToolException(
                    'robots may only contain comma-separated tokens from index/noindex/follow/nofollow (got "' . trim($token) . '").'
                );
            }
        }
    }

    /** @return array<string, mixed> same shape get_seo and update_seo both return under `seo`. */
    private function seoMetaPayload(SeoMeta $seo): array
    {
        return [
            'meta_title_en' => $seo->meta_title_en,
            'meta_title_fr' => $seo->meta_title_fr,
            'meta_description_en' => $seo->meta_description_en,
            'meta_description_fr' => $seo->meta_description_fr,
            'og_title_en' => $seo->og_title_en,
            'og_title_fr' => $seo->og_title_fr,
            'og_description_en' => $seo->og_description_en,
            'og_description_fr' => $seo->og_description_fr,
            'focus_keyphrase_en' => $seo->focus_keyphrase_en,
            'focus_keyphrase_fr' => $seo->focus_keyphrase_fr,
            'og_image' => $seo->og_image,
            'canonical_url' => $seo->canonical_url,
            'robots' => $seo->robots,
            'schema_type' => $seo->schema_type,
            'schema_data' => $seo->schema_data,
            'in_sitemap' => (bool) $seo->in_sitemap,
        ];
    }

    /** `locale` argument when valid, else the post's own locale, else the app default — used by every SEO tool. */
    private function resolveSeoLocale(array $args, Post $post): string
    {
        $locale = trim((string) ($args['locale'] ?? ''));
        if ($locale === '') {
            $locale = (string) ($post->locale ?: LocaleConfig::default());
        }
        if (! LocaleConfig::isValid($locale)) {
            $allowed = implode(', ', LocaleConfig::locales());
            throw new McpToolException("locale must be one of: {$allowed} (got '{$locale}').");
        }

        return $locale;
    }

    /** @return class-string<SeoMeta> */
    private function seoMetaClass(): string
    {
        return (string) config('heisenberg.models.seo_meta', SeoMeta::class);
    }
}
