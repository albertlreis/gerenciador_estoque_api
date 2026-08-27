<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTestDatabase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use CreatesTestDatabase;

    protected static bool $migrationsReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$migrationsReady) {
            return;
        }

        $this->ensureTestDatabaseExists();
        $this->runSharedMigrations();
        self::$migrationsReady = true;
        RefreshDatabaseState::$migrated = true;
    }

    protected function setUpTraits()
    {
        $uses = class_uses_recursive(static::class);

        if (in_array(RefreshDatabase::class, $uses, true) && !self::$migrationsReady) {
            // Toda DDL precisa terminar antes de RefreshDatabase abrir a
            // transação. No MySQL, DDL confirma a transação implicitamente.
            $this->ensureTestDatabaseExists();
            $this->runSharedMigrations();
            self::$migrationsReady = true;
            RefreshDatabaseState::$migrated = true;
        }

        return parent::setUpTraits();
    }

    protected function runSharedMigrations(): void
    {
        $this->assertIsolatedTestDatabase();

        if (!config('activitylog.table_name')) {
            config([
                'activitylog.table_name' => 'activity_log',
                'activitylog.database_connection' => null,
            ]);
        }

        Artisan::call('migrate:fresh', [
            '--env' => 'testing',
            '--force' => true,
        ]);

        $externalPath = base_path('..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations');
        $relativePrefix = '..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $this->runExternalMigrations($externalPath, $relativePrefix);
    }

    protected function assertIsolatedTestDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === '' || !str_ends_with($database, '_test')) {
            throw new \LogicException(
                "A suíte só pode recriar bancos dedicados com sufixo _test; recebido: '{$database}'."
            );
        }
    }

    protected function runExternalMigrations(string $path, string $relativePrefix): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $hasMigrationsTable = Schema::hasTable('migrations');

        foreach ($files as $file) {
            $migration = pathinfo($file, PATHINFO_FILENAME);

            if (str_contains($migration, 'create_personal_access_tokens_table') && Schema::hasTable('personal_access_tokens')) {
                continue;
            }

            if ($hasMigrationsTable && DB::table('migrations')->where('migration', $migration)->exists()) {
                continue;
            }

            Artisan::call('migrate', [
                '--path' => $relativePrefix . DIRECTORY_SEPARATOR . basename($file),
                '--env' => 'testing',
                '--force' => true,
            ]);

            $hasMigrationsTable = true;
        }
    }
}
