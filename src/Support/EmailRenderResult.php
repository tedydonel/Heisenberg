<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * The output of {@see \Heisenberg\Services\EmailRenderer::render()} (docs/email-system.md §5) —
 * a fully self-contained email: HTML with every design token resolved to a literal value (no
 * `var(--...)` survives), a plain-text alternative, the subject line, and the CID-embed
 * manifest {@see \Heisenberg\Mail\HeisenbergMailable} attaches from.
 *
 * Readonly value object (no behaviour) — a caller with its own mailer reads these five fields
 * directly (docs/email-system.md §6 "hosts with other mailers consume the result object
 * directly"); {@see \Heisenberg\Mail\HeisenbergMailable} is the bundled convenience wrapper for
 * `Mail::to(...)->send(...)`.
 */
final class EmailRenderResult
{
    /**
     * @param list<array{cid: string, path: string, mime: string}> $embeds one entry per unique
     *   embedded image: `cid` is the bare Content-ID (no `cid:`/angle-bracket decoration — the
     *   HTML already carries `src="cid:$cid"`), `path` is the ABSOLUTE filesystem path on the
     *   uploads disk, `mime` is the stored mime type.
     * @param int $sizeBytes strlen($html) + the sum of every embed file's size on disk — a rough
     *   total-message-weight signal (Gmail clips very large mail; docs/email-system.md §2).
     */
    public function __construct(
        public readonly string $html,
        public readonly string $text,
        public readonly string $subject,
        public readonly array $embeds,
        public readonly int $sizeBytes,
    ) {
    }
}
