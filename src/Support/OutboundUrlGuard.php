<?php

declare(strict_types=1);

namespace Heisenberg\Support;

use Heisenberg\Adapters\HttpMcpClient;
use Heisenberg\Http\Controllers\AiMcpController;
use Heisenberg\Services\AiSettingsRepository;

/**
 * SSRF guard for every URL Heisenberg fetches SERVER-SIDE with no human in the
 * loop: an outbound MCP tool call ({@see HttpMcpClient}),
 * the MCP "test connection" probe
 * ({@see AiMcpController::test()}), and a
 * provider's `base_url` — including a custom OpenAI-compatible or Anthropic
 * endpoint (both {@see AiSettingsRepository::validate()}
 * and the two provider adapters themselves). Each of those is a URL a HOST or
 * an operator supplies, fetched with the SERVER's network access rather than
 * a browser's — a target inside the server's own private network or cloud
 * metadata range is a real internal-network pivot, not a theoretical one.
 *
 * docs/ai-mcp-plan.md deliberately supports a self-hosted MCP server running
 * on localhost or inside an operator's own VPC — that is a real, common
 * deployment, not a mistake to guard against — so this is a CONFIGURABLE
 * POLICY, not a hard-coded deny list:
 *
 *  - `heisenberg.ai.outbound.allow_private_networks` (default: null, meaning
 *    "true only when `app()->environment('local')` is true", computed fresh
 *    on every check rather than baked into the config file) opts loopback /
 *    RFC1918 / CGNAT / IPv6 ULA ranges in.
 *  - `heisenberg.ai.outbound.allowed_hosts` names specific hostnames (exact,
 *    case-insensitive match) that bypass the private-network check entirely —
 *    the escape hatch for "yes, I really do run a trusted server here",
 *    independent of environment.
 *  - Link-local (169.254.0.0/16 — this is ALSO the cloud instance metadata
 *    range: 169.254.169.254 on AWS/GCP/Azure/DigitalOcean) and its IPv6
 *    equivalent (fe80::/10) are BLOCKED UNCONDITIONALLY: neither
 *    `allow_private_networks` nor `allowed_hosts` can re-enable them. Nearly
 *    every real-world SSRF-to-credential-theft exploit targets exactly this
 *    range, and there is no legitimate reason for an MCP call or an AI
 *    provider request to ever reach it.
 *
 * Meant to be applied TWICE, deliberately: once when a server/provider entry
 * is SAVED (catches an obviously bad entry immediately, in the settings UI,
 * before it is ever used), and again IMMEDIATELY BEFORE every outbound
 * request. The second check is what actually matters for security — a
 * hostname's DNS answer can change between those two moments (an attacker
 * points a domain at a public IP just long enough to pass validation, then
 * repoints it at 127.0.0.1 or a metadata address for the real request; classic
 * DNS rebinding), and re-resolving right before the request narrows that
 * window from "any time after save" to milliseconds. It does NOT close it:
 * the HTTP client resolves the name again on its own, so an attacker serving
 * TTL-0 answers can still race the two lookups. Closing it fully means
 * pinning the vetted IP into the request (CURLOPT_RESOLVE) — not done yet;
 * these surfaces are admin-gated, and SECURITY.md lists it as a known limit.
 *
 * A host that cannot be resolved to any IP at all is ALLOWED through rather
 * than blocked: this guard cannot classify what it cannot resolve, and a
 * genuinely unreachable host is not an SSRF risk — the outbound HTTP call
 * will simply fail on its own with a connection error. This also means the
 * guard never needs a host's DNS to be reachable in order to correctly reject
 * a literal IP address or a well-known local name (`localhost`), which are
 * classified without any DNS lookup at all.
 */
final class OutboundUrlGuard
{
    /**
     * @return string|null a human-readable rejection reason, or null when the URL is allowed.
     */
    public static function reject(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return "'{$url}' must be an http(s) URL.";
        }

        $host = self::normalizeHost((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return "'{$url}' must include a host.";
        }

        if (self::isAmbiguousIpv4($host)) {
            return "Host '{$host}' is a non-canonical numeric IPv4 form. Write the address as a "
                . 'plain dotted quad (e.g. 203.0.113.10) or use a hostname.';
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            // Cannot classify what cannot be resolved — see class docblock.
            return null;
        }

        // Link-local/cloud-metadata is checked BEFORE the allow-list,
        // deliberately: it is the one category `allowed_hosts` (and
        // `allow_private_networks`) can never re-enable, so it must never be
        // short-circuited by either.
        foreach ($ips as $ip) {
            if (in_array(self::classify($ip), ['link_local', 'ipv6_link_local'], true)) {
                return "Host '{$host}' resolves to a link-local/cloud-metadata address ({$ip}), "
                    . 'which is always blocked and cannot be allowed by any configuration.';
            }
        }

        if (self::hostAllowlisted($host)) {
            return null;
        }

        $allowPrivate = self::allowPrivateNetworks();

        foreach ($ips as $ip) {
            $category = self::classify($ip);

            if ($category !== 'public' && ! $allowPrivate) {
                return "Host '{$host}' resolves to a private/internal address ({$ip}). Set "
                    . 'heisenberg.ai.outbound.allow_private_networks to true, or add this host to '
                    . 'heisenberg.ai.outbound.allowed_hosts, to permit this.';
            }
        }

        return null;
    }

    /** @throws \RuntimeException when the URL is not allowed. */
    public static function assertAllowed(string $url): void
    {
        $reason = self::reject($url);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }
    }

    public static function isAllowed(string $url): bool
    {
        return self::reject($url) === null;
    }

    /**
     * Resolves `heisenberg.ai.outbound.allow_private_networks`: an explicit
     * true/false always wins; leaving it null (the shipped default) auto-
     * detects from the CURRENT environment on every call, so a config cache
     * built once at deploy time still reflects reality rather than freezing
     * whatever the environment happened to be when the cache was built.
     */
    public static function allowPrivateNetworks(): bool
    {
        $configured = config('heisenberg.ai.outbound.allow_private_networks');
        if ($configured !== null) {
            return (bool) $configured;
        }

        return app()->environment('local');
    }

    /**
     * Classify a single already-resolved IP address. Pure and DNS-free —
     * the whole point is that this half of the guard is trivially unit
     * testable with hand-picked addresses (see
     * tests/Security/OutboundUrlGuardTest.php).
     *
     * @return 'public'|'loopback'|'private'|'link_local'|'cgnat'|'ipv6_loopback'|'ipv6_link_local'|'ipv6_ula'|'unknown'
     */
    public static function classify(string $ip): string
    {
        $ip = self::unwrapIpv4MappedIpv6($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::classifyIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::classifyIpv6($ip);
        }

        return 'unknown';
    }

    private static function classifyIpv4(string $ip): string
    {
        if (self::inRange4($ip, '127.0.0.0/8')) {
            return 'loopback';
        }
        // Link-local (RFC 3927) — also where AWS/GCP/Azure/DigitalOcean serve
        // instance metadata (169.254.169.254). Callers must treat this
        // category as unconditionally blocked.
        if (self::inRange4($ip, '169.254.0.0/16')) {
            return 'link_local';
        }
        if (self::inRange4($ip, '10.0.0.0/8')
            || self::inRange4($ip, '172.16.0.0/12')
            || self::inRange4($ip, '192.168.0.0/16')) {
            return 'private';
        }
        // Carrier-grade NAT (RFC 6598) — used by cloud load balancers/proxies
        // for internal-only addressing, same trust level as RFC1918.
        if (self::inRange4($ip, '100.64.0.0/10')) {
            return 'cgnat';
        }
        // "This network" (RFC 791 §3.2) — 0.0.0.0/8 has no legitimate use as
        // an outbound target and behaves like a loopback on most stacks.
        if (self::inRange4($ip, '0.0.0.0/8')) {
            return 'loopback';
        }

        return 'public';
    }

    private static function classifyIpv6(string $ip): string
    {
        if ($ip === '::1') {
            return 'ipv6_loopback';
        }
        // Link-local (fe80::/10) — the IPv6 twin of the IPv4 metadata range;
        // some cloud metadata services are ALSO reachable over link-local
        // IPv6, so this stays in the unconditionally-blocked category too.
        if (self::inRange6($ip, 'fe80::/10')) {
            return 'ipv6_link_local';
        }
        // Unique Local Addresses (RFC 4193) — IPv6's RFC1918 equivalent.
        if (self::inRange6($ip, 'fc00::/7')) {
            return 'ipv6_ula';
        }

        return 'public';
    }

    /**
     * `::ffff:a.b.c.d` (RFC 4291 §2.5.5.2) lets an IPv4 address hide inside
     * IPv6 notation. Without unwrapping this first, `::ffff:127.0.0.1` would
     * classify as an ordinary public IPv6 address and sail straight past the
     * loopback check it is actually describing.
     */
    private static function unwrapIpv4MappedIpv6(string $ip): string
    {
        if (stripos($ip, '::ffff:') === 0) {
            $candidate = substr($ip, 7);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $candidate;
            }
        }

        // Catches the less common hex-group form (e.g. ::ffff:7f00:1) that the
        // textual prefix check above cannot see.
        $binary = @inet_pton($ip);
        if ($binary !== false && strlen($binary) === 16
            && substr($binary, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            $unwrapped = @inet_ntop(substr($binary, 12));
            if (is_string($unwrapped)) {
                return $unwrapped;
            }
        }

        return $ip;
    }

    private static function inRange4(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = (int) $bits === 0 ? 0 : (-1 << (32 - (int) $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function inRange6(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainderBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /**
     * Every IP a host currently resolves to (both A and AAAA records) — ALL
     * of them are classified, not just the first, so a hostname that answers
     * with one public and one private address cannot slip through.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // Resolved without a DNS round-trip: every platform's stub resolver
        // (and hosts file) treats this specially, and hard-coding it keeps
        // the guard's answer for "localhost" deterministic even offline.
        // `*.localhost` is included because curl (and RFC 6761 §6.3) maps the
        // whole suffix to loopback WITHOUT asking DNS — so a name this guard
        // failed to resolve would still connect to 127.0.0.1.
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return ['127.0.0.1', '::1'];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        if (function_exists('dns_get_record')) {
            $aaaaRecords = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaaRecords)) {
                foreach ($aaaaRecords as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * The host exactly as the classifier and the allow-list must see it:
     * lower-cased, without the `[...]` that parse_url() keeps around an IPv6
     * literal (FILTER_VALIDATE_IP rejects the bracketed form, which would
     * otherwise send `[::1]` down the DNS path, fail to resolve, and be
     * allowed), and without a trailing root dot (`localhost.` is `localhost`).
     */
    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return rtrim($host, '.');
    }

    /**
     * `2130706433`, `0x7f.0.0.1`, `0177.0.0.1` and `127.1` are all 127.0.0.1 to
     * curl's inet_aton-style parser, but none of them is a valid IP to
     * FILTER_VALIDATE_IP and gethostbynamel() does not reliably expand them —
     * so they would fall through as "unresolvable" and be allowed. Same rule
     * the WHATWG URL spec uses: a host whose LAST label is numeric (decimal or
     * 0x-hex) is an IPv4 address; anything that is not a canonical dotted quad
     * is refused outright rather than guessed at.
     */
    private static function isAmbiguousIpv4(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);

        return preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) end($labels)) === 1;
    }

    private static function hostAllowlisted(string $host): bool
    {
        $allowed = array_map(
            static fn ($h): string => strtolower(trim((string) $h)),
            (array) config('heisenberg.ai.outbound.allowed_hosts', []),
        );

        return in_array(strtolower($host), $allowed, true);
    }
}
