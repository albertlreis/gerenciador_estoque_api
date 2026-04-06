<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use RuntimeException;
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

        $this->recreateTestingDatabase();

        Artisan::call('migrate', [
            '--env' => 'testing',
            '--force' => true,
        ]);

        $externalPath = base_path('..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations');
        $relativePrefix = '..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $this->runExternalMigrations($externalPath, $relativePrefix);
    }

    protected function recreateTestingDatabase(): void
    {
        if (env('APP_ENV') !== 'testing') {
            return;
        }

        $dbName = (string) env('DB_DATABASE');
        if ($dbName === '' || !str_ends_with($dbName, '_test')) {
            throw new RuntimeException("DB de teste inválido para reset seguro: {$dbName}");
        }

        $host = (string) env('DB_HOST', '127.0.0.1');
        $port = (string) env('DB_PORT', '3306');
        $user = (string) env('DB_USERNAME', 'root');
        $pass = (string) env('DB_PASSWORD', '');

        DB::disconnect();
        DB::purge();

        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        DB::purge();
        DB::reconnect();
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

            if ($hasMigrationsTable) {
                if (DB::table('migrations')->where('migration', $migration)->exists()) {
                    continue;
                }
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
