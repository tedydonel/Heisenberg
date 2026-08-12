<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Models\Post;

/**
 * Resolves a post's public URL — the full override seam behind {@see \Heisenberg\Services\SeoUrlResolver}
 * (docs/seo-system.md §5), same house pattern as {@see RoleGate}/{@see MediaResolver}/
 * {@see PostCommentProvider}: an interface, a bundled default, and a config key naming the bound
 * class (`heisenberg.seo.url_resolver`).
 *
 * `SitemapController` and `PreviewController`'s hreflang alternates both resolve THIS contract from
 * the container (never the concrete `SeoUrlResolver` class directly) — so a host that binds its own
 * implementation controls every public URL Heisenberg emits, with full access to its own route
 * helpers, per-locale domains/subdomains, id-based URLs, or any other shape the bundled
 * template-substitution default cannot express. The bundled default (config's `url_template`, string
 * or per-locale map) covers the common case; this contract exists for everything it doesn't.
 */
interface PostUrlResolver
{
    /** The post's public URL — a fully-qualified address a visitor (or a crawler) can load. */
    public function url(Post $post): string;
}
