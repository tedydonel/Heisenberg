<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Adapters\EncryptedFileCredentialStore;
use Heisenberg\Tests\TestCase;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * Unit coverage for {@see EncryptedFileCredentialStore} — AI provider API keys
 * encrypted at rest with the app key, in their own file. Exercised directly
 * (bypassing HTTP) with an isolated temp path/dir cleaned up in tearDown();
 * AiControllerTest already covers the HTTP-level "never in a response body"
 * assertion, this suite focuses on the store's own on-disk contract.
 */
class EncryptedFileCredentialStoreTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-cred-store-' . uniqid('', true);
        $this->path = $this->dir . DIRECTORY_SEPARATOR . 'credentials.json';
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->dir);
        parent::tearDown();
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->deleteTree($full) : @unlink($full);
        }
        @rmdir($dir);
    }

    private function store(): EncryptedFileCredentialStore
    {
        return new EncryptedFileCredentialStore($this->path);
    }

    // ------------------------------------------------------------------
    // Round trip
    // ------------------------------------------------------------------

    public function test_put_then_get_round_trips_the_plaintext_key(): void
    {
        $store = $this->store();

        $store->put('openai', 'sk-super-secret');

        $this->assertSame('sk-super-secret', $store->get('openai'));
        $this->assertTrue($store->has('openai'));
    }

    public function test_a_fresh_store_instance_reading_the_same_file_gets_the_same_key(): void
    {
        $this->store()->put('openai', 'sk-shared-file');

        $reloaded = $this->store();

        $this->assertSame('sk-shared-file', $reloaded->get('openai'));
    }

    public function test_forget_removes_the_key(): void
    {
        $store = $this->store();
        $store->put('openai', 'sk-to-remove');

        $store->forget('openai');

        $this->assertNull($store->get('openai'));
        $this->assertFalse($store->has('openai'));
    }

    public function test_putting_a_blank_key_is_the_same_as_forgetting_it(): void
    {
        $store = $this->store();
        $store->put('openai', 'sk-existing');

        $store->put('openai', '   ');

        $this->assertNull($store->get('openai'));
    }

    public function test_multiple_providers_are_stored_independently(): void
    {
        $store = $this->store();
        $store->put('openai', 'sk-openai');
        $store->put('anthropic', 'sk-anthropic');

        $this->assertSame('sk-openai', $store->get('openai'));
        $this->assertSame('sk-anthropic', $store->get('anthropic'));

        $store->forget('openai');

        $this->assertNull($store->get('openai'));
        $this->assertSame('sk-anthropic', $store->get('anthropic'), 'forgetting one provider must not disturb another');
    }

    // ------------------------------------------------------------------
    // Environment precedence
    // ------------------------------------------------------------------

    public function test_an_environment_variable_outranks_a_stored_key(): void
    {
        $store = $this->store();
        $store->put('openai', 'sk-from-store');

        putenv('HB_CRED_TEST=sk-from-env');
        $_ENV['HB_CRED_TEST'] = 'sk-from-env';
        $_SERVER['HB_CRED_TEST'] = 'sk-from-env';

        try {
            $this->assertSame('sk-from-env', $store->get('openai', 'HB_CRED_TEST'));
            $this->assertTrue($store->isFromEnvironment('openai', 'HB_CRED_TEST'));
        } finally {
            putenv('HB_CRED_TEST');
            unset($_ENV['HB_CRED_TEST'], $_SERVER['HB_CRED_TEST']);
        }
    }

    public function test_is_from_environment_is_false_without_an_env_var_or_when_it_is_unset(): void
    {
        $store = $this->store();

        $this->assertFalse($store->isFromEnvironment('openai', null));
        $this->assertFalse($store->isFromEnvironment('openai', 'HB_CRED_TEST_UNSET_VAR'));
    }

    // ------------------------------------------------------------------
    // Security-relevant on-disk behaviour
    // ------------------------------------------------------------------

    public function test_the_plaintext_key_never_appears_in_the_file_on_disk(): void
    {
        $this->store()->put('openai', 'sk-must-not-leak-anywhere');

        $raw = (string) file_get_contents($this->path);

        $this->assertStringNotContainsString('sk-must-not-leak-anywhere', $raw);
    }

    public function test_a_tampered_ciphertext_is_handled_safely_and_returns_null(): void
    {
        $store = $this->store();
        $store->put('openai', 'sk-original');

        $raw = json_decode((string) file_get_contents($this->path), true);
        $raw['openai'] = substr($raw['openai'], 0, -8) . 'tampered!';
        file_put_contents($this->path, json_encode($raw));

        $this->assertNull($store->get('openai'));
    }

    public function test_completely_garbage_ciphertext_is_handled_safely_and_returns_null(): void
    {
        mkdir($this->dir, 0775, true);
        file_put_contents($this->path, json_encode(['openai' => 'not-even-close-to-a-real-payload']));

        $this->assertNull($this->store()->get('openai'));
    }

    /**
     * A key encrypted under a DIFFERENT app key cannot be decrypted by this
     * store's (real, TestCase-configured) app key — and fails safely (null),
     * not with an uncaught DecryptException.
     */
    public function test_a_value_encrypted_under_a_different_app_key_fails_safely(): void
    {
        $otherKeyEncrypter = new Encrypter(str_repeat('x', 32), config('app.cipher', 'AES-256-CBC'));
        $foreignCiphertext = $otherKeyEncrypter->encryptString('sk-from-a-different-app-key');

        mkdir($this->dir, 0775, true);
        file_put_contents($this->path, json_encode(['openai' => $foreignCiphertext]));

        $this->assertNull($this->store()->get('openai'));
    }

    public function test_get_on_a_missing_file_returns_null_and_has_is_false(): void
    {
        $store = $this->store();

        $this->assertNull($store->get('openai'));
        $this->assertFalse($store->has('openai'));
    }

    public function test_get_on_a_file_containing_invalid_json_returns_null(): void
    {
        mkdir($this->dir, 0775, true);
        file_put_contents($this->path, '{not valid json');

        $this->assertNull($this->store()->get('openai'));
    }

    public function test_put_creates_a_missing_parent_directory(): void
    {
        $this->assertDirectoryDoesNotExist($this->dir);

        $this->store()->put('openai', 'sk-creates-dir');

        $this->assertDirectoryExists($this->dir);
        $this->assertFileExists($this->path);
        $this->assertSame('sk-creates-dir', $this->store()->get('openai'));
    }

    public function test_stored_ciphertext_is_independently_decryptable_via_the_crypt_facade(): void
    {
        // Confirms the store really does use the standard app-key Crypt facade
        // (not some bespoke scheme) — encryptString()/decryptString() are the
        // exact pair Crypt::encryptString() produces.
        $this->store()->put('openai', 'sk-standard-crypt');

        $raw = json_decode((string) file_get_contents($this->path), true);

        $this->assertSame('sk-standard-crypt', Crypt::decryptString($raw['openai']));
    }

    public function test_file_permissions_are_restricted_to_owner_read_write(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX file permission bits are not meaningful on Windows (chmod() is a no-op there per the class\'s own docblock comment).');
        }

        $this->store()->put('openai', 'sk-perm-check');

        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->path)), -4));
    }
}
