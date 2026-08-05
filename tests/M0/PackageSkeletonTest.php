<?php

declare(strict_types=1);

namespace Heisenberg\Tests\M0;

use Heisenberg\Adapters\ConfigRoleGate;
use Heisenberg\Adapters\PhosphorIconProvider;
use Heisenberg\Adapters\NullAuditSink;
use Heisenberg\Adapters\NullMediaResolver;
use Heisenberg\Contracts\AuditSink;
use Heisenberg\Contracts\IconProvider;
use Heisenberg\Contracts\MediaResolver;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\HeisenbergServiceProvider;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * M0 acceptance: the package boots, config merges, and every decoupling contract
 * resolves to its default adapter as a shared singleton. (Blueprint §15 M0)
 */
class PackageSkeletonTest extends TestCase
{
    public function test_provider_is_loaded(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(HeisenbergServiceProvider::class));
    }

    public function test_config_is_merged(): void
    {
        $this->assertSame('heisenberg', config('heisenberg.block_prefix'));
        $this->assertSame('hb', config('heisenberg.css_prefix'));
        $this->assertSame(['super_admin'], config('heisenberg.roles.super'));
        $this->assertSame('admins', config('heisenberg.lifecycle.role_permissions.published'));
    }

    public function test_contracts_resolve_to_default_adapters(): void
    {
        $this->assertInstanceOf(NullMediaResolver::class, app(MediaResolver::class));
        $this->assertInstanceOf(ConfigRoleGate::class, app(RoleGate::class));
        $this->assertInstanceOf(NullAuditSink::class, app(AuditSink::class));
        $this->assertInstanceOf(PhosphorIconProvider::class, app(IconProvider::class));
    }

    public function test_contracts_are_singletons(): void
    {
        $this->assertSame(app(MediaResolver::class), app(MediaResolver::class));
        $this->assertSame(app(RoleGate::class), app(RoleGate::class));
        $this->assertSame(app(AuditSink::class), app(AuditSink::class));
        $this->assertSame(app(IconProvider::class), app(IconProvider::class));
    }

    public function test_null_media_resolver_scheme_checks(): void
    {
        $resolver = app(MediaResolver::class);

        $this->assertSame('https://cdn.example/x.jpg', $resolver->resolve('https://cdn.example/x.jpg', 'inline')['url']);
        $this->assertSame('/local/x.jpg', $resolver->resolve('/local/x.jpg', 'inline')['url']);
        $this->assertSame('//cdn.example/x.jpg', $resolver->resolve('//cdn.example/x.jpg', 'inline')['url']);
        $this->assertSame('', $resolver->resolve('javascript:alert(1)', 'inline')['url']);
        $this->assertSame('', $resolver->resolve('data:text/html;base64,xxx', 'inline')['url']);
        $this->assertNull($resolver->resolve('https://cdn.example/x.jpg', 'inline')['srcset']);
    }

    public function test_config_role_gate_resolves_tiers(): void
    {
        $gate = app(RoleGate::class);
        $user = $this->userWithRoles(['admin']);

        $this->assertTrue($gate->isAny($user, ['admins']));
        $this->assertTrue($gate->isAny($user, ['authors']));
        $this->assertFalse($gate->is($user, 'super'));
        $this->assertSame(['admin'], $gate->rolesOf($user));
    }

    private function userWithRoles(array $roles): AuthUser
    {
        return new class($roles) extends AuthUser {
            /** @param string[] $roles */
            public function __construct(private array $roles)
            {
            }

            public function getRoleNames()
            {
                return collect($this->roles);
            }
        };
    }
}
