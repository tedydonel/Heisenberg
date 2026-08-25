<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Heisenberg block-palette preview generator
|--------------------------------------------------------------------------
|
| Heisenberg has no editor UI of its own (the block-editor SPA is a host
| concern — blueprint §0.2). The closest visual of "current builder state" is
| the registry: the catalogue of block types the editor would be fed. This
| script discovers the shipped contracts and writes:
|
|   examples/registry.json        — the contract catalogue as data
|   examples/blocks-preview.html   — a styled palette you can open in a browser
|
| It uses only the app-free parts of the registry (discover + computeHash), so
| run it with plain PHP — no Laravel app required:
|
|   php examples/preview.php
|
| Re-run any time the contracts change.
*/

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;

require __DIR__ . '/../vendor/autoload.php';

$registry = new BlockRegistryService(
    new BlockContractValidator('heisenberg'),
    __DIR__ . '/../resources/blocks'
);

$discovered = $registry->discover();

/** Strip the on-disk path keys so the hash matches the editor-facing registry. */
$blocks = array_map(static function (array $contract): array {
    unset($contract['_absolutePath'], $contract['_relativePath']);
    return $contract;
}, $discovered['blocks']);

usort($blocks, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

$hash = $registry->computeHash($blocks);
$categories = $registry->getCategories($blocks);

// registry.json (app-free subset of the editor envelope).
$envelope = [
    'schemaVersion' => BlockRegistryService::SCHEMA_VERSION,
    'registryHash'  => $hash,
    'blockCount'    => count($blocks),
    'categories'    => $categories,
    'errors'        => $discovered['errors'],
    'blocks'        => $blocks,
];

file_put_contents(
    __DIR__ . '/registry.json',
    json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

// blocks-preview.html (the visual palette).
$slugToTitle = static fn (string $slug): string => ucwords(str_replace('-', ' ', $slug));
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$sections = '';
foreach ($categories as $category) {
    $cards = '';
    foreach ($blocks as $block) {
        if (($block['category'] ?? '') !== $category) {
            continue;
        }
        $slug = substr($block['name'], strlen('heisenberg/'));
        $attrs = count($block['attributes'] ?? []);
        $supports = implode(', ', array_keys($block['supports'] ?? []));
        $keywords = implode(' · ', $block['keywords'] ?? []);

        $cards .= '<article class="card">'
            . '<div class="card__icon" title="Lucide: ' . $e($block['icon'] ?? '') . '">' . $e($block['icon'] ?? '') . '</div>'
            . '<h3 class="card__title">' . $e($slugToTitle($slug)) . '</h3>'
            . '<code class="card__name">' . $e($block['name']) . '</code>'
            . '<div class="card__meta">' . $attrs . ' attributes' . ($supports !== '' ? ' · supports: ' . $e($supports) : '') . '</div>'
            . '<div class="card__kw">' . $e($keywords) . '</div>'
            . '</article>';
    }

    $sections .= '<section><h2 class="cat">' . $e($category) . '</h2><div class="grid">' . $cards . '</div></section>';
}

$count = count($blocks);
$errorNote = $discovered['errors'] === []
    ? '<span class="ok">0 errors</span>'
    : '<span class="bad">' . count($discovered['errors']) . ' errors</span>';

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Heisenberg — Block Palette</title>
<style>
  :root { --bg:#0d1117; --panel:#161b22; --line:#30363d; --txt:#e6edf3; --mut:#8b949e; --accent:#58a6ff; --ok:#3fb950; --bad:#f85149; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--txt); font:15px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; }
  header { padding:32px 32px 16px; border-bottom:1px solid var(--line); }
  header h1 { margin:0 0 6px; font-size:22px; }
  header p { margin:0; color:var(--mut); max-width:70ch; }
  .pills { margin-top:14px; display:flex; gap:8px; flex-wrap:wrap; }
  .pill { font-size:12px; padding:3px 10px; border:1px solid var(--line); border-radius:999px; color:var(--mut); }
  .pill code { color:var(--accent); }
  .ok { color:var(--ok); } .bad { color:var(--bad); }
  main { padding:24px 32px 64px; }
  .cat { font-size:13px; text-transform:uppercase; letter-spacing:.08em; color:var(--mut); margin:28px 0 12px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:16px; transition:border-color .15s; }
  .card:hover { border-color:var(--accent); }
  .card__icon { display:inline-block; font-size:11px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; color:var(--accent); background:rgba(88,166,255,.1); border:1px solid rgba(88,166,255,.25); padding:2px 8px; border-radius:6px; }
  .card__title { margin:12px 0 4px; font-size:16px; }
  .card__name { display:block; font-size:12px; color:var(--mut); margin-bottom:10px; }
  .card__meta { font-size:12px; color:var(--mut); }
  .card__kw { font-size:11px; color:#6e7681; margin-top:8px; }
  footer { padding:20px 32px; color:var(--mut); font-size:12px; border-top:1px solid var(--line); }
</style>
</head>
<body>
<header>
  <h1>Heisenberg — Block Palette</h1>
  <p>This is the current state of the block builder: the <strong>catalogue of block types</strong> the editor would offer in its inserter. There are no block <em>instances</em> yet (no rendered content) — the renderer lands later. Heisenberg ships the backend; the visual editor SPA is a host concern.</p>
  <div class="pills">
    <span class="pill"><strong>$count</strong> block types</span>
    <span class="pill">$errorNote</span>
    <span class="pill">hash <code>$hash</code></span>
  </div>
</header>
<main>
$sections
</main>
<footer>Generated by <code>php examples/preview.php</code> from <code>resources/blocks/</code>. Regenerate any time the contracts change.</footer>
</body>
</html>

HTML;

file_put_contents(__DIR__ . '/blocks-preview.html', $html);

echo "Wrote:\n";
echo "  examples/registry.json\n";
echo "  examples/blocks-preview.html\n";
echo "Blocks discovered: {$count} | errors: " . count($discovered['errors']) . " | hash: {$hash}\n";
