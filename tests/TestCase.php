<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
    }

    protected function runSharedMigrations(): void
    {
        if (!config('activitylog.table_name')) {
            config([
                'activitylog.table_name' => 'activity_log',
                'activitylog.database_connection' => null,
            ]);
        }

        Artisan::call('db:wipe', ['--force' => true]);

        $externalPath = base_path('..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations');
        $relativePrefix = '..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $this->runExternalMigrations($externalPath, $relativePrefix);

        Artisan::call('migrate', ['--force' => true]);
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
            if ($hasMigrationsTable) {
                if (DB::table('migrations')->where('migration', $migration)->exists()) {
                    continue;
                }
            }

            Artisan::call('migrate', [
                '--path' => $relativePrefix . DIRECTORY_SEPARATOR . basename($file),
                '--force' => true,
            ]);

            $hasMigrationsTable = true;
        }
    }
}
