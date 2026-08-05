<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Engine;

use Heisenberg\Services\BlockContractValidator;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlocksPayloadService;
use Heisenberg\Tests\TestCase;

/**
 * Validates a block *instance* payload (what the editor sends) against the live
 * registry (blueprint §3.6). The gate that runs before any persistence.
 */
class BlocksPayloadServiceTest extends TestCase
{
    private function registry(): BlockRegistryService
    {
        return new BlockRegistryService(new BlockContractValidator('heisenberg'));
    }

    private function service(): BlocksPayloadService
    {
        return new BlocksPayloadService($this->registry());
    }

    private function envelope(array $blocks): array
    {
        return [
            'schemaVersion' => 1,
            // Must be the REAL live registry hash — validatePayload() now compares
            // this against BlockRegistryService::computeHash(), not just its shape.
            'registryHash' => $this->registry()->computeHash(),
            'computedStyles' => '',
            'blocks' => $blocks,
        ];
    }

    private function block(string $name, array $attributes = [], array $overrides = []): array
    {
        return array_merge([
            'id' => 'b1',
            'name' => $name,
            'schemaVersion' => '1.0.0',
            'attributes' => $attributes,
            'supports' => [],
            'innerBlocks' => [],
        ], $overrides);
    }

    public function test_a_well_formed_payload_is_valid(): void
    {
        $result = $this->service()->validatePayload($this->envelope([
            $this->block('heisenberg/paragraph', ['content' => 'Hello']),
            $this->block('heisenberg/heading', ['content' => 'Title', 'level' => 2]),
        ]));

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
        $this->assertSame([], $result['errors']);
    }

    public function test_envelope_schema_version_must_be_one(): void
    {
        $payload = $this->envelope([]);
        $payload['schemaVersion'] = 2;

        $this->assertFalse($this->service()->validatePayload($payload)['valid']);
    }

    public function test_registry_hash_must_be_sha256_prefixed(): void
    {
        $payload = $this->envelope([]);
        $payload['registryHash'] = 'nope';

        $this->assertFalse($this->service()->validatePayload($payload)['valid']);
    }

    /**
     * A well-formed-but-stale hash (right shape, wrong value — e.g. the editor's
     * registry snapshot predates a server-side contract change) must be rejected,
     * not just format-checked. Regression for the no-op described in review §4.9.
     */
    public function test_registry_hash_mismatch_is_rejected(): void
    {
        $payload = $this->envelope([]);
        $payload['registryHash'] = 'sha256:' . str_repeat('a', 64); // well-formed, but not the live hash

        $result = $this->service()->validatePayload($payload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsStringIgnoringCase('registryhash', implode(' | ', $result['errors']));
        $this->assertArrayHasKey('registryHash', $result['errorMap']);
    }

    public function test_blocks_must_be_an_array(): void
    {
        $payload = $this->envelope([]);
        $payload['blocks'] = 'not-an-array';

        $this->assertFalse($this->service()->validatePayload($payload)['valid']);
    }

    public function test_unknown_block_name_is_rejected(): void
    {
        $result = $this->service()->validatePayload($this->envelope([
            $this->block('heisenberg/nope'),
        ]));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsStringIgnoringCase('unknown block', implode(' | ', $result['errors']));
    }

    public function test_schema_version_mismatch_is_rejected(): void
    {
        $result = $this->service()->validatePayload($this->envelope([
            $this->block('heisenberg/paragraph', ['content' => 'x'], ['schemaVersion' => '9.9.9']),
        ]));

        $this->assertFalse($result['valid']);
    }

    public function test_enum_violation_is_rejected(): void
    {
        $result = $this->service()->validatePayload($this->envelope([
            $this->block('heisenberg/heading', ['content' => 'x', 'level' => 9]),
        ]));

        $this->assertFalse($result['valid']);
    }

    public function test_missing_optional_attribute_is_allowed(): void
    {
        // paragraph with no content provided -> falls back to the contract default.
        $result = $this->service()->validatePayload($this->envelope([
            $this->block('heisenberg/paragraph', []),
        ]));

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
    }

    public function test_missing_required_block_key_is_rejected(): void
    {
        $block = $this->block('heisenberg/paragraph', ['content' => 'x']);
        unset($block['supports']);

        $result = $this->service()->validatePayload($this->envelope([$block]));

        $this->assertFalse($result['valid']);
    }

    public function test_errors_are_keyed_by_dot_path(): void
    {
        $result = $this->service()->validatePayload($this->envelope([
            $this->block('heisenberg/heading', ['content' => 'x', 'level' => 9]),
        ]));

        $this->assertArrayHasKey('errorMap', $result);
        $keys = implode(' ', array_keys($result['errorMap']));
        $this->assertStringContainsString('blocks.0', $keys);
    }
}
