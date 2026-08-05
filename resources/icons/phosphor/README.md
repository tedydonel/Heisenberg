# Phosphor Icons (vendored)

Local copy of the [Phosphor Icons](https://phosphoricons.com) SVG assets so the
builder needs no CDN and no extra Composer/npm dependency at runtime.

- Source: `@phosphor-icons/core` **v2.1.1** (npm), `assets/regular/*.svg`
- License: MIT — see [LICENSE](LICENSE)
- Format: `viewBox="0 0 256 256"`, single `fill="currentColor"` path per file
- Consumed by `\Heisenberg\Support\Icons` (server-rendered chrome) and by the
  icon route/provider for contract blocks (`data-lucide` slugs are mapped to
  Phosphor equivalents).

To upgrade: download the new `@phosphor-icons/core` tarball from npm and replace
`regular/` wholesale, then re-run the builder test-suite.
