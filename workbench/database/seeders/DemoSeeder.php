<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Heisenberg\Models\Block;
use Heisenberg\Models\Category;
use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\Tag;
use Heisenberg\Models\TocEntry;
use Heisenberg\Support\LocalizedAttributes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeds the Heisenberg demo/"client test app" workbench with a couple of realistic,
 * published bilingual posts (a rich one and a simple one), one draft post, and one
 * email document — this is the content a real adopter would author by hand through
 * `/editor`, reproduced here as data so `docs/demo.md` and `tests/Demo/AdopterPathTest`
 * have something real to exercise.
 *
 * DELIBERATELY NOT namespaced under `Workbench\App\...` PSR-4 autoloading: this repo's
 * composer.json has no `Workbench\\` autoload-dev entry (see docs/demo.md "composer.json
 * lines the lead should add"), so this class is always loaded via an explicit
 * `require_once` — from `workbench/routes/console.php` (the `demo:seed` artisan command)
 * and from `tests/Demo/AdopterPathTest`. Never `new`'d without that require.
 *
 * BILINGUAL MODEL (docs/content-translation.md §0, single row): each bilingual post below
 * is ONE `heisenberg_posts` row — `locale` is its AUTHORING/home locale ('en' for both
 * demo articles), `title_en`/`title_fr` and `excerpt_en`/`excerpt_fr` both live on that
 * SAME row, and every translatable block attribute (see each contract's own
 * `"translatable": true` flags — heading/paragraph/quote/list `content`, quote
 * `citation`, image `alt`/`caption`, button `text`) carries a bare (English, the home
 * locale) value AND a `_fr` suffixed variant on the SAME block instance
 * ({@see LocalizedAttributes}, `key_<locale>` first then bare `key`).
 * `PostPublicController::resolvePost()` (fixed 2026-09-19) now serves BOTH
 * `/posts/en/{slug}` and `/posts/fr/{slug}` from this ONE row — no second row needed
 * (earlier versions of this seeder DID create a second `locale=fr` row to work around a
 * bug in that resolution; that workaround is gone now that the bug is fixed — see
 * docs/demo.md's "found by this demo, fixed 2026-09-19" note for the history).
 *
 * The authored table of contents ({@see TocEntry}) is NOT part of this
 * translation mechanism — its `label` column has no `_fr` counterpart at all (see that
 * model's own migration), so the demo's TOC labels are English on both locales; that is
 * a genuine, separate gap this seeder does not attempt to work around.
 */
class DemoSeeder
{
    /** @var array<string, int> */
    private array $categoryIds = [];

    /** @var array<string, int> */
    private array $tagIds = [];

    public function run(): void
    {
        $this->seedTaxonomy();
        $heroImage = $this->seedImage('demo/hero-widgets.png', 'Widget Hero', 960, 540, [66, 133, 244]);
        $inlineImage = $this->seedImage('demo/inline-assembly.png', 'Assembly Diagram', 800, 500, [52, 168, 83]);

        $this->seedRichArticle($heroImage, $inlineImage);
        $this->seedSimpleArticle();
        $this->seedDraftPost();
        $this->seedEmail($inlineImage);
    }

    private function seedTaxonomy(): void
    {
        $guides = Category::query()->firstOrCreate(
            ['slug' => 'guides'],
            ['name_en' => 'Guides', 'name_fr' => 'Guides', 'description_en' => 'How-to guides for getting the most out of your widgets.', 'description_fr' => 'Guides pratiques pour tirer le meilleur parti de vos widgets.', 'order' => 1]
        );
        $this->categoryIds['guides'] = $guides->id;

        foreach ([
            'widgets' => ['name_en' => 'Widgets', 'name_fr' => 'Widgets'],
            'tutorial' => ['name_en' => 'Tutorial', 'name_fr' => 'Tutoriel'],
            'maintenance' => ['name_en' => 'Maintenance', 'name_fr' => 'Entretien'],
        ] as $slug => $names) {
            $tag = Tag::query()->firstOrCreate(['slug' => $slug], $names);
            $this->tagIds[$slug] = $tag->id;
        }
    }

    /**
     * A small solid-colour placeholder PNG with a text label, generated with GD (no binary
     * fixture committed to the repo) and written straight to the `uploads` disk so the
     * demo's <img> tags are real, visible images rather than broken links in screenshots.
     */
    private function seedImage(string $path, string $label, int $width, int $height, array $rgb): PublicFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, ...$rgb));
        $band = imagecolorallocatealpha($image, 255, 255, 255, 90);
        imagefilledrectangle($image, 0, (int) ($height * 0.72), $width, $height, $band);
        $textColor = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 24, (int) ($height * 0.78), $label, $textColor);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('uploads')->put($path, $bytes);

        return PublicFile::query()->create([
            'type' => 'png',
            'disk' => 'uploads',
            'stored_path' => $path,
            'original_name' => basename($path),
            'stored_name' => basename($path),
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'width' => $width,
            'height' => $height,
            'variants' => [],
            'alt_text_en' => $label,
            'alt_text_fr' => $label,
            'caption_en' => '',
            'caption_fr' => '',
        ]);
    }

    private function seedRichArticle(PublicFile $hero, PublicFile $inline): void
    {
        $post = Post::create([
            'locale' => 'en',
            'title_en' => 'Getting Started with Widgets',
            'title_fr' => 'Bien démarrer avec les widgets',
            'slug' => 'getting-started-with-widgets',
            'excerpt_en' => 'Everything you need to unbox, assemble, and configure your first widget.',
            'excerpt_fr' => 'Tout ce qu’il faut savoir pour déballer, assembler et configurer votre premier widget.',
            'status' => 'published',
            'published_at' => now()->subDays(5),
        ]);
        $post->type = 'post';
        $post->allow_comments = true;
        $post->featured_image_id = $hero->id;
        $post->save();
        $post->categories()->attach($this->categoryIds['guides']);
        $post->tags()->attach([$this->tagIds['widgets'], $this->tagIds['tutorial']]);

        $this->writeBlocks($post, [
            $this->heading(
                'Why widgets?', 2, 'why-widgets',
                fr: 'Pourquoi des widgets ?',
            ),
            $this->paragraph(
                'A widget is the smallest building block in your toolkit — small enough to hold in one hand, versatile enough to anchor an entire workflow. This guide walks through unboxing, first assembly, and the handful of settings worth changing on day one.',
                fr: 'Un widget est le plus petit bloc de construction de votre boîte à outils — assez petit pour tenir dans une main, assez polyvalent pour ancrer tout un flux de travail. Ce guide couvre le déballage, le premier assemblage et les quelques réglages à changer dès le premier jour.',
            ),
            $this->image(
                $inline->url, $inline->getAlt('en'), 'The full assembly, laid out before the first screw goes in.',
                altFr: $inline->getAlt('fr'), captionFr: 'L’assemblage complet, avant le premier tour de vis.',
            ),
            $this->quote(
                'Measure twice, tighten once.', 'Widgets Field Manual, 3rd ed.',
                fr: 'Mesurez deux fois, serrez une fois.', citationFr: 'Manuel de terrain des widgets, 3e éd.',
            ),
            $this->heading(
                'What is in the box', 2, 'in-the-box',
                fr: 'Contenu de la boîte',
            ),
            $this->list(
                ['1 widget housing', '4 mounting screws', '1 hex key', 'A quick-start card (which you can now ignore)'],
                false, 'checkmark',
                fr: ['1 boîtier de widget', '4 vis de fixation', '1 clé hexagonale', 'Une carte de démarrage rapide (que vous pouvez ignorer)'],
            ),
            $this->columns([
                [
                    $this->heading('Step 1 — Align', 3, null, fr: 'Étape 1 — Aligner'),
                    $this->paragraph(
                        'Line up the housing tabs with the base plate. They only fit one way, so do not force it.',
                        fr: 'Alignez les languettes du boîtier avec la plaque de base. Elles ne s’emboîtent que dans un sens, ne forcez pas.',
                    ),
                ],
                [
                    $this->heading('Step 2 — Fasten', 3, null, fr: 'Étape 2 — Fixer'),
                    $this->paragraph(
                        'Drive all four screws to a light snug, then a final quarter-turn each in a cross pattern.',
                        fr: 'Serrez légèrement les quatre vis, puis donnez un dernier quart de tour à chacune en croix.',
                    ),
                ],
            ]),
            $this->separator(),
            $this->heading(
                'Watch the 90-second overview', 2, 'watch-the-overview',
                fr: 'Regardez le résumé de 90 secondes',
            ),
            $this->embed('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
            $this->button(
                'Read the maintenance guide next', '/blog/en/widget-maintenance-tips', 'secondary',
                fr: 'Lire le guide d’entretien',
            ),
        ]);

        // English-only labels — TocEntry has no `label_fr` column (see this class's own
        // docblock); anchors match the heading blocks' `anchor` attribute above, which
        // IS locale-neutral, so the jump links themselves work in both locales.
        TocEntry::query()->create(['post_id' => $post->id, 'label' => 'Why widgets?', 'anchor' => 'why-widgets', 'order' => 0]);
        TocEntry::query()->create(['post_id' => $post->id, 'label' => 'What is in the box', 'anchor' => 'in-the-box', 'order' => 1]);
        TocEntry::query()->create(['post_id' => $post->id, 'label' => 'Watch the 90-second overview', 'anchor' => 'watch-the-overview', 'order' => 2]);

        $approved = Comment::query()->create([
            'post_id' => $post->id,
            'author_name' => 'Priya N.',
            'author_email' => 'priya@example.com',
            'body' => 'The cross-pattern tightening tip saved me from stripping a screw. Thank you!',
        ]);
        $approved->status = Comment::STATUS_APPROVED;
        $approved->save();

        $pending = Comment::query()->create([
            'post_id' => $post->id,
            'author_name' => 'New Reader',
            'author_email' => 'reader@example.com',
            'body' => 'Does this work with the older widget housings too?',
        ]);
        $pending->status = Comment::STATUS_PENDING;
        $pending->save();
    }

    private function seedSimpleArticle(): void
    {
        $post = Post::create([
            'locale' => 'en',
            'title_en' => 'Widget Maintenance Tips',
            'title_fr' => 'Conseils d’entretien des widgets',
            'slug' => 'widget-maintenance-tips',
            'excerpt_en' => 'Keep your widget running smoothly with these five-minute checks.',
            'excerpt_fr' => 'Gardez votre widget en bon état grâce à ces vérifications de cinq minutes.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        $post->type = 'post';
        $post->allow_comments = true;
        $post->save();
        $post->categories()->attach($this->categoryIds['guides']);
        $post->tags()->attach($this->tagIds['maintenance']);

        $this->writeBlocks($post, [
            $this->heading('Five-minute checks', 2, 'five-minute-checks', fr: 'Vérifications de cinq minutes'),
            $this->paragraph(
                'A widget rarely fails without warning. These quick checks catch the early signs.',
                fr: 'Un widget tombe rarement en panne sans avertissement. Ces vérifications rapides permettent de repérer les premiers signes.',
            ),
            $this->list(
                ['Listen for a rattle on startup', 'Check the mounting screws are still snug', 'Wipe the housing vents free of dust'],
                false, 'default',
                fr: ['Écoutez un cliquetis au démarrage', 'Vérifiez que les vis de fixation sont bien serrées', 'Dépoussiérez les grilles de ventilation du boîtier'],
            ),
        ]);
    }

    private function seedDraftPost(): void
    {
        $draft = Post::create([
            'locale' => 'en',
            'title_en' => 'Upcoming Widget Roadmap',
            'slug' => 'upcoming-widget-roadmap',
            'excerpt_en' => 'A sneak peek at what is next for the widget lineup (not ready for visitors yet).',
            'status' => 'draft',
        ]);
        $draft->type = 'post';
        $draft->save();

        $this->writeBlocks($draft, [
            $this->heading('Not published yet', 2, null),
            $this->paragraph('This post exists to prove drafts never leak onto the public blog.'),
        ]);
    }

    private function seedEmail(PublicFile $inline): void
    {
        $email = Post::create([
            'locale' => 'en',
            'title_en' => 'Widgets Weekly Welcome',
            'slug' => 'widgets-weekly-welcome',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $email->type = 'email';
        $email->save();

        $this->writeBlocks($email, [
            $this->heading('Hi {{ user.first_name }}, welcome to Widgets Weekly', 2, null),
            $this->paragraph('Thanks for subscribing, {{ user.first_name }}! Every week we send one short tip for getting more out of your widget — starting with this one.'),
            $this->image($inline->url, 'Widget assembly', ''),
            $this->button('Read the getting-started guide', '{{ blog_url }}', 'primary'),
            $this->separator(),
            $this->paragraph('You are receiving this because {{ user.email }} subscribed at widgets.example. Unsubscribe any time: {{ unsubscribe_url }}'),
        ]);
    }

    // -- block builders -----------------------------------------------------
    // Every builder below takes the bare (English/home-locale) value(s) as required
    // params and an optional `fr:`-named argument per translatable attribute — when
    // given, it's written as the `<key>_fr` suffixed variant on the SAME block instance
    // (Heisenberg\Support\LocalizedAttributes' `key_<locale>` convention), never a
    // second block or a second post row. Non-translatable attributes (level, anchor,
    // url, variant, ordered, style, …) are written once, bare, and apply to both
    // locales — exactly as their contracts declare (no `"translatable": true` flag).

    private function id(): string
    {
        return 'b' . Str::random(8);
    }

    private function block(string $name, array $attributes, array $innerBlocks = []): array
    {
        return [
            'id' => $this->id(),
            'name' => "heisenberg/{$name}",
            'schemaVersion' => '1.0.0',
            'attributes' => $attributes,
            'supports' => [],
            'innerBlocks' => $innerBlocks,
        ];
    }

    private function heading(string $content, int $level, ?string $anchor, ?string $fr = null): array
    {
        $attributes = array_filter([
            'content' => $content,
            'level' => $level,
            'anchor' => $anchor,
        ], static fn ($v) => $v !== null);

        if ($fr !== null) {
            $attributes['content_fr'] = $fr;
        }

        return $this->block('heading', $attributes);
    }

    private function paragraph(string $content, ?string $fr = null): array
    {
        $attributes = ['content' => $content];
        if ($fr !== null) {
            $attributes['content_fr'] = $fr;
        }

        return $this->block('paragraph', $attributes);
    }

    private function image(string $url, string $alt, string $caption, ?string $altFr = null, ?string $captionFr = null): array
    {
        $attributes = ['url' => $url, 'alt' => $alt, 'caption' => $caption];
        if ($altFr !== null) {
            $attributes['alt_fr'] = $altFr;
        }
        if ($captionFr !== null) {
            $attributes['caption_fr'] = $captionFr;
        }

        return $this->block('image', $attributes);
    }

    private function quote(string $content, string $citation, ?string $fr = null, ?string $citationFr = null): array
    {
        $attributes = ['content' => $content, 'citation' => $citation];
        if ($fr !== null) {
            $attributes['content_fr'] = $fr;
        }
        if ($citationFr !== null) {
            $attributes['citation_fr'] = $citationFr;
        }

        return $this->block('quote', $attributes);
    }

    /**
     * @param string[] $items
     * @param string[]|null $fr
     */
    private function list(array $items, bool $ordered, string $style, ?array $fr = null): array
    {
        $attributes = [
            'content' => implode("\n", $items),
            'ordered' => $ordered,
            'style' => $style,
        ];
        if ($fr !== null) {
            $attributes['content_fr'] = implode("\n", $fr);
        }

        return $this->block('list', $attributes);
    }

    private function separator(): array
    {
        return $this->block('separator', []);
    }

    private function embed(string $url): array
    {
        return $this->block('embed', ['url' => $url]);
    }

    private function button(string $text, string $url, string $variant, ?string $fr = null): array
    {
        $attributes = ['text' => $text, 'url' => $url, 'variant' => $variant];
        if ($fr !== null) {
            $attributes['text_fr'] = $fr;
        }

        return $this->block('button', $attributes);
    }

    /** @param list<list<array<string, mixed>>> $columnChildren one inner-array of blocks per column */
    private function columns(array $columnChildren): array
    {
        $columns = array_map(
            fn (array $children) => $this->block('column', [], $children),
            $columnChildren
        );

        return $this->block('columns', ['columns' => count($columns)], $columns);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function writeBlocks(Post $post, array $blocks): void
    {
        foreach (array_values($blocks) as $order => $content) {
            $slug = Str::afterLast((string) $content['name'], '/');
            Block::create([
                'post_id' => $post->id,
                'type' => $slug,
                'content' => $content,
                'order' => $order,
            ]);
        }
    }
}
