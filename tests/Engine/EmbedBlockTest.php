<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The `heisenberg/embed` video block and the URL normalizer behind it
 * ({@see BlockRenderer::embedSrcFor()}).
 *
 * Two halves:
 *  - a unit sweep over embedSrcFor() — every accepted paste form (params, case,
 *    www, scheme variants) and the rejections that matter (foreign hosts,
 *    lookalike hosts, userinfo tricks, javascript: URLs, an id-less watch URL);
 *  - a render pass through the SHIPPED contract, proving the `{"embed": …}`
 *    template attribute resolves to the privacy-enhanced src and — critically —
 *    that a garbage URL ships an iframe with NO src at all (fail closed).
 *
 * Every accepted result must also satisfy BlockRenderer's own EMBED_SRC_PATTERN,
 * which stays the final gate in renderNode(); embedSrcFor may narrow it, never widen it.
 */
class EmbedBlockTest extends TestCase
{
    private const VIDEO_ID = 'dQw4w9WgXcQ';

    private const YT = 'https://www.youtube-nocookie.com/embed/' . self::VIDEO_ID;

    private function renderer(): BlockRenderer
    {
        // No explicit root -> the package default (resources/blocks), i.e. the shipped contract.
        return new BlockRenderer(new BlockRegistryService(new BlockContractValidator('heisenberg')));
    }

    private function renderEmbed(array $attributes): string
    {
        return $this->renderer()->renderBlock(
            ['id' => 'b1', 'name' => 'heisenberg/embed', 'attributes' => $attributes, 'supports' => [], 'innerBlocks' => []],
            'en'
        );
    }

    // ── embedSrcFor: accepted forms ───────────────────────────

    #[DataProvider('acceptedUrls')]
    public function test_accepted_url_forms_normalize_to_the_privacy_enhanced_player(string $input, string $expected): void
    {
        $this->assertSame($expected, BlockRenderer::embedSrcFor($input), $input);
    }

    public static function acceptedUrls(): array
    {
        $id = self::VIDEO_ID;
        $yt = self::YT;

        return [
            // YouTube watch — the canonical paste, plus every param/scheme/host variant.
            'watch' => ["https://www.youtube.com/watch?v={$id}", $yt],
            'watch without www' => ["https://youtube.com/watch?v={$id}", $yt],
            'watch over http' => ["http://www.youtube.com/watch?v={$id}", $yt],
            'watch scheme-relative' => ["//www.youtube.com/watch?v={$id}", $yt],
            'watch without scheme' => ["www.youtube.com/watch?v={$id}", $yt],
            'watch bare host' => ["youtube.com/watch?v={$id}", $yt],
            'watch with trailing params' => ["https://www.youtube.com/watch?v={$id}&t=30s&feature=share", $yt . '?start=30'],
            'watch with leading params' => ["https://www.youtube.com/watch?list=PL123&index=2&v={$id}", $yt],
            'watch with fragment' => ["https://www.youtube.com/watch?v={$id}#t=10", $yt . '?start=10'],
            'watch uppercase host' => ["HTTPS://WWW.YOUTUBE.COM/WATCH?V={$id}", $yt],
            'watch with surrounding whitespace' => ["   https://www.youtube.com/watch?v={$id}   ", $yt],

            // youtu.be short links.
            'youtu.be' => ["https://youtu.be/{$id}", $yt],
            'youtu.be with params' => ["https://youtu.be/{$id}?t=42", $yt . '?start=42'],
            'youtu.be without scheme' => ["youtu.be/{$id}", $yt],

            // Shorts.
            'shorts' => ["https://www.youtube.com/shorts/{$id}", $yt],
            'shorts without www' => ["https://youtube.com/shorts/{$id}", $yt],
            'shorts with params' => ["https://www.youtube.com/shorts/{$id}?feature=share", $yt],

            // Already-an-embed forms: plain YouTube is upgraded to nocookie, nocookie normalized.
            'embed' => ["https://www.youtube.com/embed/{$id}", $yt],
            'embed nocookie' => ["https://www.youtube-nocookie.com/embed/{$id}", $yt],
            'embed nocookie without www' => ["https://youtube-nocookie.com/embed/{$id}", $yt],
            'embed with params' => ["https://www.youtube.com/embed/{$id}?rel=0&start=5", $yt . '?start=5'],

            // Vimeo.
            'vimeo' => ['https://vimeo.com/12345', 'https://player.vimeo.com/video/12345'],
            'vimeo with www' => ['https://www.vimeo.com/76979871', 'https://player.vimeo.com/video/76979871'],
            'vimeo without scheme' => ['vimeo.com/12345', 'https://player.vimeo.com/video/12345'],
            'vimeo with params' => ['https://vimeo.com/12345?share=copy', 'https://player.vimeo.com/video/12345'],
            'vimeo player' => ['https://player.vimeo.com/video/12345', 'https://player.vimeo.com/video/12345'],
            'vimeo player with params' => ['https://player.vimeo.com/video/12345?h=abc&title=0', 'https://player.vimeo.com/video/12345'],
        ];
    }

    public function test_every_accepted_result_satisfies_the_renderers_own_iframe_allowlist(): void
    {
        // The contract embedSrcFor() promises: whatever it returns must survive the
        // EMBED_SRC_PATTERN gate renderNode() applies to every iframe src.
        $pattern = '#^https://(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)[A-Za-z0-9_/?=&.-]+$#';

        foreach (self::acceptedUrls() as $label => [$input, $expected]) {
            $this->assertMatchesRegularExpression($pattern, BlockRenderer::embedSrcFor($input), (string) $label);
        }
    }

    public function test_the_video_id_case_is_preserved_verbatim(): void
    {
        // YouTube ids are case-sensitive: the host match is case-insensitive, the id is not.
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/aB_cD-eF1gH',
            BlockRenderer::embedSrcFor('https://www.youtube.com/watch?v=aB_cD-eF1gH')
        );
    }

    // ── embedSrcFor: rejections (fail closed) ─────────────────

    #[DataProvider('rejectedUrls')]
    public function test_rejected_urls_yield_no_src(string $input): void
    {
        $this->assertSame('', BlockRenderer::embedSrcFor($input), $input);
    }

    public static function rejectedUrls(): array
    {
        $id = self::VIDEO_ID;

        return [
            'empty' => [''],
            'whitespace only' => ['   '],

            // Other hosts entirely.
            'unrelated host' => ['https://example.com/watch?v=' . $id],
            'unrelated video host' => ['https://dailymotion.com/video/x123'],
            'vimeo-lookalike host' => ['https://vimeo.evil.com/12345'],

            // Lookalike / suffix-prefix hosts.
            'notyoutube.com' => ["https://notyoutube.com/watch?v={$id}"],
            'notyoutube.com no scheme' => ["notyoutube.com/watch?v={$id}"],
            'youtube.com.evil.com' => ["https://www.youtube.com.evil.com/watch?v={$id}"],
            'youtube-nocookie lookalike' => ["https://youtube-nocookie.evil.com/embed/{$id}"],
            'youtu.be lookalike' => ["https://youtu.be.evil.com/{$id}"],
            'subdomain of an attacker domain' => ["https://evil.com/www.youtube.com/watch?v={$id}"],
            'youtube path on another host' => ["https://evil.com/https://www.youtube.com/watch?v={$id}"],

            // Userinfo / authority tricks — the host is evil.com in every one of these.
            'userinfo before the real host' => ["https://youtube.com@evil.com/watch?v={$id}"],
            'userinfo with www' => ["https://www.youtube.com@evil.com/watch?v={$id}"],
            'userinfo with password' => ["https://youtube.com:pass@evil.com/watch?v={$id}"],
            'backslash authority' => ["https://youtube.com\\@evil.com/watch?v={$id}"],

            // Dangerous schemes.
            'javascript' => ['javascript:alert(1)'],
            'javascript wearing a youtube path' => ["javascript://www.youtube.com/watch?v={$id}"],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],

            // Well-formed YouTube URLs that carry no usable id.
            'watch with no v param' => ['https://www.youtube.com/watch'],
            'watch with an empty v param' => ['https://www.youtube.com/watch?v='],
            'watch with only other params' => ['https://www.youtube.com/watch?list=PL123'],
            'watch with a decoy v param name' => ["https://www.youtube.com/watch?av={$id}"],
            'watch with a suffixed v param name' => ["https://www.youtube.com/watch?vv={$id}"],
            'id too short' => ['https://www.youtube.com/watch?v=abc'],
            'id too long' => ['https://www.youtube.com/watch?v=abcdefghijklmnopqrstuvwxyz'],
            'id with an illegal character' => ['https://www.youtube.com/watch?v=abcde+fghij'],
            'shorts with no id' => ['https://www.youtube.com/shorts/'],
            'embed with no id' => ['https://www.youtube.com/embed/'],
            'youtube channel page' => ['https://www.youtube.com/@somechannel'],

            // Vimeo without a numeric id.
            'vimeo channel page' => ['https://vimeo.com/channels/staffpicks'],
            'vimeo non-numeric id' => ['https://vimeo.com/abcdef'],
            'vimeo player with no id' => ['https://player.vimeo.com/video/'],
            'vimeo player non-numeric' => ['https://player.vimeo.com/video/abc'],

            // Injection-flavoured payloads.
            'quote break-out' => ['https://www.youtube.com/watch?v=' . $id . '" onload="alert(1)'],
            'angle brackets' => ['https://www.youtube.com/watch?v=<script>'],
        ];
    }

    // ── render through the shipped contract ───────────────────

    public function test_a_watch_url_renders_the_privacy_enhanced_iframe(): void
    {
        $html = $this->renderEmbed(['url' => 'https://www.youtube.com/watch?v=' . self::VIDEO_ID . '&t=30s']);

        $this->assertStringContainsString('<iframe', $html);
        // The pasted link carries a start offset, so the player URL keeps it.
        $this->assertStringContainsString('src="' . self::YT . '?start=30"', $html);
        $this->assertStringContainsString('hb-block-embed__frame', $html);
        $this->assertStringContainsString('allowfullscreen', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $html);
        // Never the tracking host, even though that is what was pasted.
        $this->assertStringNotContainsString('www.youtube.com/embed', $html);
    }

    public function test_a_vimeo_url_renders_the_player_iframe(): void
    {
        $html = $this->renderEmbed(['url' => 'https://vimeo.com/12345']);

        $this->assertStringContainsString('src="https://player.vimeo.com/video/12345"', $html);
    }

    public function test_a_garbage_url_renders_an_iframe_with_no_src(): void
    {
        $html = $this->renderEmbed(['url' => 'https://evil.example.com/pwn?v=' . self::VIDEO_ID]);

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringNotContainsString('src=', $html);
        $this->assertStringNotContainsString('evil.example.com', $html);
    }

    public function test_a_javascript_url_renders_an_iframe_with_no_src(): void
    {
        $html = $this->renderEmbed(['url' => 'javascript:alert(document.domain)']);

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringNotContainsString('src=', $html);
        $this->assertStringNotContainsString('javascript', $html);
    }

    public function test_an_empty_url_renders_the_placeholder_frame_with_no_src(): void
    {
        $html = $this->renderEmbed(['url' => '']);

        $this->assertStringContainsString('hb-block-embed__frame', $html);
        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringNotContainsString('src=', $html);
    }

    public function test_the_contract_is_discovered_valid_and_leaf_only(): void
    {
        $registry = (new BlockRegistryService(new BlockContractValidator('heisenberg')))->registry();
        $embed = collect($registry['blocks'])->firstWhere('name', 'heisenberg/embed');

        $this->assertIsArray($embed, 'the embed contract must be discovered');
        $this->assertSame([], $registry['errors']);
        $this->assertFalse($embed['innerBlocks']['enabled']);
        $this->assertStringContainsString('hb-supports', $embed['style']['className']);
        $this->assertSame('url', $embed['attributes']['url']['type']);
        $this->assertSame('url', $embed['attributes']['url']['sanitize']);
        $this->assertSame('text', $embed['attributes']['url']['control']['type']);
    }

    public function test_the_general_and_visibility_attributes_reach_the_rendered_root(): void
    {
        $html = $this->renderEmbed([
            'url' => 'https://youtu.be/' . self::VIDEO_ID,
            'anchor' => 'my-video',
            'titleAttr' => 'Our launch film',
            'extraClasses' => 'is-featured',
            'hideXs' => true,
        ]);

        $this->assertStringContainsString('id="my-video"', $html);
        $this->assertStringContainsString('title="Our launch film"', $html);
        $this->assertStringContainsString('is-featured', $html);
        $this->assertStringContainsString('hb-hide-xs', $html);
    }

    public function test_the_id_attribute_is_omitted_when_anchor_is_blank(): void
    {
        // render.template's `id` is `{ "value": "{{attributes.anchor}}", "omitWhenEmpty": true }`
        // — an unset anchor must not leave a stray id="" on the rendered root.
        $html = $this->renderEmbed(['url' => 'https://youtu.be/' . self::VIDEO_ID]);

        $this->assertStringNotContainsString(' id=', $html);
    }
}
