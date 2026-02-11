<?php

namespace Tests\Concerns;

use PDO;
use PDOException;

trait CreatesTestDatabase
{
    protected function ensureTestDatabaseExists(): void
    {
        if (env('APP_ENV') !== 'testing') {
            return;
        }

        $dbName = env('DB_DATABASE');
        if (!$dbName) {
            $this->logTestDbWarning('DB_DATABASE não definido para testes.');
            return;
        }

        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $user = env('DB_USERNAME', 'root');
        $pass = env('DB_PASSWORD', '');

        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec(
                "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        } catch (PDOException $e) {
            $this->logTestDbWarning(
                "Não foi possível criar o banco de testes '{$dbName}'. Crie manualmente. Erro: {$e->getMessage()}"
            );
        }
    }

    protected function logTestDbWarning(string $message): void
    {
        fwrite(STDERR, "[test-db] {$message}\n");
    }
}
