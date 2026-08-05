<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Templates;

use Heisenberg\Console\Commands\TemplatesVerifyCommand;
use Heisenberg\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `templates:verify` is not yet registered with the console kernel (see
 * docs/post-template-schema.md "Wiring" — that requires a one-line addition
 * to HeisenbergServiceProvider::boot(), a file this work was scoped to leave
 * untouched). The command class itself is fully self-contained, so it is
 * exercised here the same way any Symfony console command can be run
 * directly, without `Artisan::call()`.
 */
class TemplatesVerifyCommandTest extends TestCase
{
    private function runCommand(TemplatesVerifyCommand $command, array $input = []): array
    {
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput($input), $output);

        return [$exitCode, $output->fetch()];
    }

    public function test_exits_successfully_when_the_shipped_reference_template_is_valid(): void
    {
        [$exitCode, $text] = $this->runCommand(new TemplatesVerifyCommand());

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('heisenberg/article', $text);
        $this->assertStringContainsString('All post-template contracts are valid.', $text);
    }

    public function test_json_output_reports_valid_and_template_names(): void
    {
        [$exitCode, $text] = $this->runCommand(new TemplatesVerifyCommand(), ['--json' => true]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode($text, true);
        $this->assertTrue($payload['valid']);
        $this->assertContains('heisenberg/article', $payload['templates']);
        $this->assertSame([], $payload['errors']);
    }

    public function test_exits_non_zero_when_a_contract_is_invalid(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-templates-verify-' . uniqid('', true);
        mkdir($root . DIRECTORY_SEPARATOR . 'broken', 0775, true);
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'broken' . DIRECTORY_SEPARATOR . 'broken.json',
            json_encode(['name' => 'not-a-valid-name'])
        );

        config(['heisenberg.template_root' => $root]);

        try {
            [$exitCode, $text] = $this->runCommand(new TemplatesVerifyCommand(), ['--json' => true]);

            $this->assertSame(1, $exitCode);
            $payload = json_decode($text, true);
            $this->assertFalse($payload['valid']);
            $this->assertNotEmpty($payload['errors']);
        } finally {
            @unlink($root . DIRECTORY_SEPARATOR . 'broken' . DIRECTORY_SEPARATOR . 'broken.json');
            @rmdir($root . DIRECTORY_SEPARATOR . 'broken');
            @rmdir($root);
        }
    }
}
