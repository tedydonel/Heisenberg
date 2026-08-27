<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Vite;

if (! function_exists('heisenberg_csp_nonce')) {
    /**
     * Retrieve the current CSP nonce for inline <style> and <script> tags.
     *
     * Returns the nonce the host app stored via Vite::useCspNonce() when
     * available, or an empty string when no CSP nonce is in use. An empty
     * string is harmless — the nonce="" attribute is simply omitted by the
     * browser when empty, matching Heisenberg's pre-fix behaviour.
     */
    function heisenberg_csp_nonce(): string
    {
        try {
            return (string) Vite::cspNonce();
        } catch (\Throwable) {
            return '';
        }
    }
}
