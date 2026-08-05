<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Taxonomy;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A minimal, real (non-GuestActor) Authenticatable stand-in — mirrors
 * tests/Editor/PostPersistenceTest.php's own FakeActor. Given its own file
 * (rather than declared inline inside a single test class, as
 * PostPersistenceTest does) because it is shared across several test classes
 * in this namespace (CategoryControllerTest, TagControllerTest,
 * PostTaxonomyAttachDetachTest) — declaring it a second time inline would
 * fatal with "Cannot redeclare class". Named like SkipsWhenMysqlUnreachable.php
 * (no `Test` suffix), so PHPUnit's directory-scan discovery never mistakes it
 * for a test case.
 *
 * ConfigRoleGate::rolesOf() falls back to a plain `role` string property when
 * the user exposes no getRoleNames() (Spatie) method — exactly what this
 * class provides, so tests can exercise real (non-local-dev-bypass) RoleGate
 * tier checks without needing a database-backed user model.
 */
final class FakeActor implements Authenticatable
{
    public function __construct(public int $id, public string $role)
    {
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return null;
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
    }

    public function getRememberTokenName()
    {
        return '';
    }
}
