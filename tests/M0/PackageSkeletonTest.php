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
        $this->assertSame(['admin'], config('heisenberg.roles.super'));
        $this->assertSame('editors', config('heisenberg.lifecycle.role_permissions.published'));
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
        $admin = $this->userWithRoles(['admin']);
        $viewer = $this->userWithRoles(['viewer']);

        $this->assertTrue($gate->isAny($admin, ['admins']));
        $this->assertTrue($gate->isAny($admin, ['authors']));
        // The bundled default map resolves 'super' to the same role set as
        // 'admins' (see config/heisenberg.php) — 'super' is an unused ceiling
        // tier a host may narrow further, not a stricter one out of the box.
        $this->assertTrue($gate->is($admin, 'super'));
        $this->assertFalse($gate->isAny($viewer, ['admins']));
        $this->assertFalse($gate->is($viewer, 'super'));
        $this->assertSame(['admin'], $gate->rolesOf($admin));
    }

    /**
     * The template registry is container-bound and config-wired (2026-08-10) —
     * before this, every host rendering posts through its own template contract
     * had to hand-construct the validator/registry pair the way
     * TemplatesVerifyCommand does (observed duplicated on a host integration bench).
     */
    public function test_template_registry_resolves_as_a_singleton_and_honors_template_root(): void
    {
        $registry = app(\Heisenberg\Services\PostTemplateRegistryService::class);
        $this->assertSame($registry, app(\Heisenberg\Services\PostTemplateRegistryService::class));

        // Default root: the shipped article template is discovered.
        $slugs = array_column($registry->registry()['templates'] ?? [], 'name');
        $this->assertContains('heisenberg/article', $slugs);

        // A host-set template_root replaces the scan root wholesale: an empty
        // host directory yields an empty registry (fresh instance — the
        // singleton above was already resolved and caches its scan).
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-templates-' . uniqid('', true);
        mkdir($root);
        try {
            config(['heisenberg.template_root' => $root]);
            $this->app->forgetInstance(\Heisenberg\Services\PostTemplateRegistryService::class);
            $hostRegistry = app(\Heisenberg\Services\PostTemplateRegistryService::class);
            $this->assertSame([], $hostRegistry->registry()['templates'] ?? []);
        } finally {
            rmdir($root);
            config(['heisenberg.template_root' => null]);
            $this->app->forgetInstance(\Heisenberg\Services\PostTemplateRegistryService::class);
        }
    }

    /**
     * Registering the uploads disk without its filesystems.links entry left
     * `php artisan storage:link` a documented no-op (2026-08-10, observed on
     * a host integration bench) — the link map is what that command reads.
     */
    public function test_uploads_disk_ships_its_storage_link_entry(): void
    {
        $this->assertNotNull(config('filesystems.disks.uploads'));
        $links = (array) config('filesystems.links');
        $this->assertArrayHasKey(public_path('uploads'), $links);
        $this->assertSame(storage_path('uploads'), $links[public_path('uploads')]);
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
