<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Security;

use Heisenberg\Support\OutboundUrlGuard;
use Heisenberg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * OutboundUrlGuard — the SSRF policy behind outbound MCP calls
 * (HttpMcpClient, AiMcpController::test()) and the AI provider adapters'
 * custom base URLs (see AiSettingsRepositoryTest for the validation-time
 * integration and tests/Ai for the request-time integration).
 *
 * Split into two halves on purpose, and tested that way: classify() is a
 * pure function over an already-known IP address (no DNS, no config, no
 * network — every case below runs instantly and offline), and reject() is
 * the policy layer around it (config, allow-listing, resolution).
 */
class OutboundUrlGuardTest extends TestCase
{
    // ── classify(): pure IP classification, no DNS involved ────────────────

    /** @return iterable<string, array{string, string}> */
    public static function ipv4Cases(): iterable
    {
        yield 'a public address' => ['8.8.8.8', 'public'];
        yield 'another public address' => ['93.184.216.34', 'public'];
        yield 'loopback' => ['127.0.0.1', 'loopback'];
        yield 'the top of the loopback block' => ['127.255.255.255', 'loopback'];
        yield '"this network" (0.0.0.0/8)' => ['0.0.0.0', 'loopback'];
        yield 'link-local / cloud metadata' => ['169.254.169.254', 'link_local'];
        yield 'bottom of link-local' => ['169.254.0.0', 'link_local'];
        yield 'top of link-local' => ['169.254.255.255', 'link_local'];
        yield 'RFC1918 10/8' => ['10.1.2.3', 'private'];
        yield 'RFC1918 172.16/12 (low end)' => ['172.16.0.1', 'private'];
        yield 'RFC1918 172.16/12 (high end)' => ['172.31.255.255', 'private'];
        yield 'just outside 172.16/12' => ['172.32.0.1', 'public'];
        yield 'RFC1918 192.168/16' => ['192.168.1.1', 'private'];
        yield 'carrier-grade NAT (100.64.0.0/10)' => ['100.64.0.1', 'cgnat'];
        yield 'top of carrier-grade NAT' => ['100.127.255.255', 'cgnat'];
        yield 'just outside carrier-grade NAT' => ['100.128.0.1', 'public'];
        yield 'just below carrier-grade NAT' => ['100.63.255.255', 'public'];
    }

    #[DataProvider('ipv4Cases')]
    public function test_ipv4_classification(string $ip, string $expected): void
    {
        $this->assertSame($expected, OutboundUrlGuard::classify($ip));
    }

    /** @return iterable<string, array{string, string}> */
    public static function ipv6Cases(): iterable
    {
        yield 'loopback' => ['::1', 'ipv6_loopback'];
        yield 'link-local' => ['fe80::1', 'ipv6_link_local'];
        yield 'link-local, full width' => ['fe80:0000:0000:0000:0000:0000:0000:0001', 'ipv6_link_local'];
        yield 'unique local address (fc00::/7), fd half' => ['fd12:3456:789a::1', 'ipv6_ula'];
        yield 'unique local address, fc half' => ['fc00::1', 'ipv6_ula'];
        yield 'a public address (Google DNS)' => ['2001:4860:4860::8888', 'public'];
        yield 'IPv4-mapped loopback (textual form)' => ['::ffff:127.0.0.1', 'loopback'];
        yield 'IPv4-mapped link-local/metadata (textual form)' => ['::ffff:169.254.169.254', 'link_local'];
        yield 'IPv4-mapped public address' => ['::ffff:8.8.8.8', 'public'];
    }

    #[DataProvider('ipv6Cases')]
    public function test_ipv6_classification(string $ip, string $expected): void
    {
        $this->assertSame($expected, OutboundUrlGuard::classify($ip));
    }

    public function test_garbage_input_classifies_as_unknown_rather_than_throwing(): void
    {
        $this->assertSame('unknown', OutboundUrlGuard::classify('not-an-ip'));
        $this->assertSame('unknown', OutboundUrlGuard::classify(''));
    }

    // ── reject(): the policy layer ──────────────────────────────────────────

    public function test_a_non_http_scheme_is_rejected(): void
    {
        $this->assertNotNull(OutboundUrlGuard::reject('ftp://example.test/x'));
        $this->assertNotNull(OutboundUrlGuard::reject('file:///etc/passwd'));
        $this->assertNotNull(OutboundUrlGuard::reject('not a url at all'));
    }

    public function test_a_url_with_no_host_is_rejected(): void
    {
        $this->assertNotNull(OutboundUrlGuard::reject('https:///path-only'));
    }

    public function test_a_literal_public_ip_is_allowed_regardless_of_environment(): void
    {
        config(['heisenberg.ai.outbound.allow_private_networks' => false]);

        $this->assertNull(OutboundUrlGuard::reject('https://8.8.8.8/mcp'));
    }

    public function test_a_literal_loopback_url_is_blocked_by_default_outside_local(): void
    {
        $this->app['env'] = 'testing';
        config(['heisenberg.ai.outbound.allow_private_networks' => null]);

        $this->assertNotNull(OutboundUrlGuard::reject('http://127.0.0.1:11434/v1'));
    }

    public function test_a_literal_private_ip_is_blocked_by_default_outside_local(): void
    {
        $this->app['env'] = 'testing';
        config(['heisenberg.ai.outbound.allow_private_networks' => null]);

        $this->assertNotNull(OutboundUrlGuard::reject('http://10.0.5.5/mcp'));
    }

    public function test_localhost_by_name_is_treated_the_same_as_127_0_0_1(): void
    {
        $this->app['env'] = 'testing';
        config(['heisenberg.ai.outbound.allow_private_networks' => null]);

        $this->assertNotNull(OutboundUrlGuard::reject('http://localhost:11434/v1'));

        config(['heisenberg.ai.outbound.allow_private_networks' => true]);
        $this->assertNull(OutboundUrlGuard::reject('http://localhost:11434/v1'));
    }

    public function test_private_networks_are_allowed_automatically_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';
        config(['heisenberg.ai.outbound.allow_private_networks' => null]); // auto-detect

        $this->assertNull(OutboundUrlGuard::reject('http://127.0.0.1:11434/v1'));
        $this->assertNull(OutboundUrlGuard::reject('http://192.168.1.50/mcp'));
    }

    public function test_an_explicit_false_wins_over_the_local_environment(): void
    {
        $this->app['env'] = 'local';
        config(['heisenberg.ai.outbound.allow_private_networks' => false]);

        $this->assertNotNull(OutboundUrlGuard::reject('http://127.0.0.1:11434/v1'));
    }

    public function test_an_explicit_true_allows_private_networks_outside_local(): void
    {
        $this->app['env'] = 'testing';
        config(['heisenberg.ai.outbound.allow_private_networks' => true]);

        $this->assertNull(OutboundUrlGuard::reject('http://10.0.5.5/mcp'));
    }

    public function test_link_local_and_cloud_metadata_are_blocked_even_when_private_networks_are_allowed(): void
    {
        $this->app['env'] = 'local';
        config(['heisenberg.ai.outbound.allow_private_networks' => true]);

        $reason = OutboundUrlGuard::reject('http://169.254.169.254/latest/meta-data/');
        $this->assertNotNull($reason);
        $this->assertStringContainsString('link-local', $reason);
    }

    public function test_allowed_hosts_bypasses_the_private_network_check(): void
    {
        $this->app['env'] = 'testing';
        config([
            'heisenberg.ai.outbound.allow_private_networks' => false,
            // 'localhost' resolves deterministically to 127.0.0.1/::1 with no
            // real DNS lookup (see OutboundUrlGuard::resolve()), so this
            // exercises the allow-list against a genuinely PRIVATE resolved
            // address rather than an unresolvable one that would be allowed
            // anyway.
            'heisenberg.ai.outbound.allowed_hosts' => ['localhost'],
        ]);

        $this->assertNull(OutboundUrlGuard::reject('http://localhost:11434/mcp'));
        $this->assertNull(OutboundUrlGuard::reject('http://LOCALHOST:11434/mcp'), 'the match must be case-insensitive');
    }

    public function test_allowed_hosts_does_not_bypass_a_link_local_address_even_when_named_exactly(): void
    {
        $this->app['env'] = 'testing';
        config([
            'heisenberg.ai.outbound.allow_private_networks' => true,
            // Named EXACTLY — proving the bypass still cannot reach link-local
            // even when an operator explicitly lists this literal address.
            'heisenberg.ai.outbound.allowed_hosts' => ['169.254.169.254'],
        ]);

        $reason = OutboundUrlGuard::reject('http://169.254.169.254/latest/meta-data/');
        $this->assertNotNull($reason);
        $this->assertStringContainsString('link-local', $reason);
    }

    /**
     * Host spellings that all mean "this machine" (or link-local) to the HTTP
     * client, but that a naive guard sees as an unresolvable hostname — and
     * unresolvable is allowed. Each of these was a real bypass.
     *
     * @return array<string, array{string}>
     */
    public static function disguisedInternalUrls(): array
    {
        return [
            'bracketed ipv6 loopback' => ['http://[::1]/mcp'],
            'bracketed ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/mcp'],
            'bracketed ipv6 link-local' => ['http://[fe80::1]/mcp'],
            'bracketed ipv6 ula' => ['http://[fd00::1]/mcp'],
            'decimal ipv4' => ['http://2130706433/mcp'],
            'hex ipv4' => ['http://0x7f.0.0.1/mcp'],
            'octal ipv4' => ['http://0177.0.0.1/mcp'],
            'short ipv4' => ['http://127.1/mcp'],
            'localhost with root dot' => ['http://localhost./mcp'],
            'localhost subdomain' => ['http://anything.localhost/mcp'],
        ];
    }

    #[DataProvider('disguisedInternalUrls')]
    public function test_disguised_internal_hosts_are_blocked(string $url): void
    {
        $this->app['env'] = 'testing';
        config([
            'heisenberg.ai.outbound.allow_private_networks' => false,
            'heisenberg.ai.outbound.allowed_hosts' => [],
        ]);

        $this->assertNotNull(OutboundUrlGuard::reject($url), "{$url} must not be allowed");
    }

    public function test_a_bracketed_ipv6_link_local_literal_stays_blocked_when_private_networks_are_allowed(): void
    {
        config(['heisenberg.ai.outbound.allow_private_networks' => true]);

        $this->assertNotNull(OutboundUrlGuard::reject('http://[fe80::1]/mcp'));
        $this->assertNull(OutboundUrlGuard::reject('http://[::1]:8080/mcp'), 'plain loopback is opt-in-able');
    }

    public function test_a_hostname_with_a_numeric_label_that_is_not_last_is_not_mistaken_for_an_ip(): void
    {
        config(['heisenberg.ai.outbound.allow_private_networks' => false]);

        $this->assertNull(OutboundUrlGuard::reject('https://123.this-host-does-not-exist.invalid/mcp'));
    }

    public function test_an_unresolvable_hostname_is_allowed_rather_than_blocked(): void
    {
        $this->app['env'] = 'testing';
        config(['heisenberg.ai.outbound.allow_private_networks' => null]);

        // A reserved, guaranteed-non-resolving TLD (RFC 2606) — the guard
        // cannot classify what it cannot resolve, and an unreachable host
        // simply fails on connection, which is not an SSRF risk.
        $this->assertNull(OutboundUrlGuard::reject('https://this-host-does-not-exist.invalid/mcp'));
    }

    public function test_isallowed_and_assertallowed_mirror_reject(): void
    {
        $this->assertTrue(OutboundUrlGuard::isAllowed('https://8.8.8.8/'));
        $this->assertFalse(OutboundUrlGuard::isAllowed('http://169.254.169.254/'));

        $this->expectException(\RuntimeException::class);
        OutboundUrlGuard::assertAllowed('http://169.254.169.254/');
    }
}
