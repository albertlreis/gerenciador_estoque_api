<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acesso_usuarios')) {
            return;
        }

        // Estoque não é dono da tabela acesso_usuarios.
        // Quando necessário, executamos explicitamente a migration da API de autenticação.
        $relativePrefix = '..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $migrationFile = '2025_04_14_000001_create_acesso_usuarios_table.php';
        $externalMigration = base_path($relativePrefix . DIRECTORY_SEPARATOR . $migrationFile);

        if (!is_file($externalMigration)) {
            throw new \RuntimeException("Migration de autenticação não encontrada: {$externalMigration}");
        }

        Artisan::call('migrate', [
            '--path' => $relativePrefix . DIRECTORY_SEPARATOR . $migrationFile,
            '--env' => app()->environment(),
            '--force' => true,
        ]);

        if (!Schema::hasTable('acesso_usuarios')) {
            throw new \RuntimeException('Falha ao preparar tabela acesso_usuarios via migration da autenticação.');
        }
    }

    public function down(): void
    {
        // Tabela pertence à API de autenticação; estoque não remove.
    }
};
