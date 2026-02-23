<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use PDO;
use PDOException;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $this->ensureTestDatabaseExistsBeforeBoot();

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function ensureTestDatabaseExistsBeforeBoot(): void
    {
        $envPath = __DIR__ . '/../.env.testing';
        if (!file_exists($envPath)) {
            return;
        }

        $vars = $this->parseEnvFile($envPath);
        $dbName = $vars['DB_DATABASE'] ?? null;
        if (!$dbName) {
            return;
        }

        $host = $vars['DB_HOST'] ?? '127.0.0.1';
        $port = $vars['DB_PORT'] ?? '3306';
        $user = $vars['DB_USERNAME'] ?? 'root';
        $pass = $vars['DB_PASSWORD'] ?? '';

        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec(
                "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        } catch (PDOException $e) {
            fwrite(STDERR, "[test-db] Não foi possível criar o banco '{$dbName}'. Crie manualmente. Erro: {$e->getMessage()}\n");
        }
    }

    private function parseEnvFile(string $path): array
    {
        $vars = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, "\"'");
            $vars[$key] = $value;
        }
        return $vars;
    }
}
