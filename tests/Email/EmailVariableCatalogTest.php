<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Services\EmailVariableCatalog;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EmailVariableCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_metadata_is_exposed_without_values_or_formatters(): void
    {
        config()->set('heisenberg.email.variables', [
            ['key' => 'user.first_name', 'label' => 'First name', 'group' => 'User'],
            ['key' => 'unsubscribe_url', 'label' => 'Unsubscribe URL'],
            ['key' => 'invalid key', 'label' => 'Ignored'],
            ['key' => 'user.first_name', 'label' => 'Duplicate'],
        ]);

        $definitions = app(EmailVariableCatalog::class)->definitions();

        $this->assertSame([
            ['key' => 'user.first_name', 'label' => 'First name', 'description' => '', 'group' => 'User'],
            ['key' => 'unsubscribe_url', 'label' => 'Unsubscribe URL', 'description' => '', 'group' => ''],
        ], $definitions);
        $this->assertArrayNotHasKey('value', $definitions[0]);
        $this->assertArrayNotHasKey('formatter', $definitions[0]);
    }

    public function test_variable_metadata_is_email_only_in_the_editor(): void
    {
        config()->set('heisenberg.email.variables', [
            ['key' => 'user.first_name', 'label' => 'First name'],
        ]);

        $postEditor = $this->get('/editor')->assertOk()->getContent();
        $emailEditor = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-hb-email-variables-panel', $postEditor);
        $this->assertStringContainsString('data-hb-email-variables-panel', $emailEditor);
        $this->assertStringContainsString('data-hb-email-variable-key="user.first_name"', $emailEditor);
        $this->assertStringContainsString('emailVariables:', $emailEditor);
    }
}
