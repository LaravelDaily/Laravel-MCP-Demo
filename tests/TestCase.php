<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * One RSA key pair per test process, so Passport can sign tokens without
     * reading the gitignored storage/oauth-*.key files.
     *
     * @var array{private: string, public: string}|null
     */
    private static ?array $passportKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$passportKeys ??= $this->generatePassportKeys();

        config([
            'passport.private_key' => self::$passportKeys['private'],
            'passport.public_key' => self::$passportKeys['public'],
        ]);
    }

    /**
     * @return array{private: string, public: string}
     */
    private function generatePassportKeys(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);

        return ['private' => $privateKey, 'public' => openssl_pkey_get_details($key)['key']];
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
