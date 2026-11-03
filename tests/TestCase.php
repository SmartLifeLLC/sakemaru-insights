<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing')) {
            return;
        }

        $this->assertSafeTestDatabaseConfiguration();
    }

    private function assertSafeTestDatabaseConfiguration(): void
    {
        foreach (config('database.connections', []) as $name => $connection) {
            if (! $this->shouldGuardConnection((string) $name)) {
                continue;
            }

            $driver = (string) ($connection['driver'] ?? '');
            $database = (string) ($connection['database'] ?? '');

            if (($driver === 'sqlite' && $database === ':memory:') || $database === ':memory:') {
                continue;
            }

            if ($database === '' || ! str_contains(strtolower($database), 'test')) {
                throw new RuntimeException(
                    "Tests must run against a dedicated test database. Connection [{$name}] uses [{$database}]."
                );
            }
        }
    }

    private function shouldGuardConnection(string $name): bool
    {
        return $name === (string) config('database.default')
            || in_array($name, ['mysql', 'mariadb', 'pgsql', 'sakemaru'], true);
    }
}
