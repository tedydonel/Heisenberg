<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\McpToolException;

/**
 * Media metadata: `list_media` is read-only on purpose — an agent may reference existing
 * media, but writing bytes to the host's disk is a bigger grant than authoring text and
 * is not part of this surface. `update_media` only ever touches alt/caption/credit
 * metadata (never file bytes), which is a much narrower grant than uploading, so it is
 * fine on the AUTHORS tier even though the surface stays upload-free.
 */
final class MediaTools implements McpToolProvider
{
    public function definitions(): array
    {
        return [
            'list_media' => [
                'description' => 'List uploaded media files so their URLs can be referenced in blocks. Read-only — this surface cannot upload.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([
                    'limit' => ['type' => 'integer', 'description' => 'Max rows (1-100, default 20).'],
                ]),
            ],

            // `alt_text_en/_fr` and `caption_en/_fr` are the bilingual fields the media
            // library panel already draws; write REAL French, not a copy of the English
            // text (docs/content-translation.md's own posture, applied to media).
            'update_media' => [
                'description' => 'Update a media file\'s alt text, caption and/or credit line (both locales for alt/caption). Supply at least one field; any field left out keeps its current value. Does not touch the file bytes.',
                'tier' => self::TIER_AUTHORS,
                'inputSchema' => ToolSchema::schema([
                    'file_id' => ['type' => 'integer', 'description' => 'Public file id (see list_media).'],
                    'alt_text_en' => ['type' => 'string', 'description' => 'Alt text, English. Max 255 characters.'],
                    'alt_text_fr' => ['type' => 'string', 'description' => 'Alt text, French. Max 255 characters.'],
                    'caption_en' => ['type' => 'string', 'description' => 'Caption, English. Max 500 characters.'],
                    'caption_fr' => ['type' => 'string', 'description' => 'Caption, French. Max 500 characters.'],
                    'credit' => ['type' => 'string', 'description' => 'Credit/attribution line. Max 255 characters.'],
                ], ['file_id']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['list_media', 'update_media'], true);
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        return match ($tool) {
            'list_media' => $this->listMedia($arguments),
            'update_media' => $this->updateMedia($arguments),
            default => throw new \LogicException("MediaTools does not handle '{$tool}'."),
        };
    }

    /** @param array<string, mixed> $args */
    private function listMedia(array $args): array
    {
        $class = (string) config('heisenberg.models.public_file', PublicFile::class);

        return $class::query()->orderByDesc('id')->limit(ToolSchema::boundedLimit($args))->get()
            ->map(static fn ($f): array => [
                'id' => $f->getKey(),
                'name' => (string) ($f->original_name ?? ''),
                'url' => method_exists($f, 'url') ? (string) $f->url() : '',
            ])->all();
    }

    /** @param array<string, mixed> $args */
    private function updateMedia(array $args): array
    {
        $class = (string) config('heisenberg.models.public_file', PublicFile::class);
        $file = $class::query()->find((int) ($args['file_id'] ?? 0));
        if ($file === null) {
            throw new McpToolException('No media file with id ' . (int) ($args['file_id'] ?? 0) . '.');
        }

        $fields = ToolSchema::bilingualUpdateFields(
            $args,
            ['alt_text_en', 'alt_text_fr', 'caption_en', 'caption_fr', 'credit'],
            ['alt_text_en' => 255, 'alt_text_fr' => 255, 'caption_en' => 500, 'caption_fr' => 500, 'credit' => 255],
        );

        foreach ($fields as $field => $value) {
            $file->{$field} = $value;
        }
        $file->save();

        return [
            'id' => $file->getKey(),
            'url' => (string) $file->url,
            'alt_text_en' => $file->alt_text_en,
            'alt_text_fr' => $file->alt_text_fr,
            'caption_en' => $file->caption_en,
            'caption_fr' => $file->caption_fr,
            'credit' => $file->credit,
        ];
    }
}
