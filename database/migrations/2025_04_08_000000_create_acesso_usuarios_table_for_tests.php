<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acesso_usuarios')) {
            return;
        }

        // Estoque não é dono da tabela acesso_usuarios.
        // Quando necessário, executamos explicitamente a migration da API de autenticação.
        $migrationFile = '2025_04_14_000001_create_acesso_usuarios_table.php';
        $externalRelativePath = $this->resolverMigrationAutenticacao($migrationFile);
        $externalMigration = $externalRelativePath ? base_path($externalRelativePath) : null;

        if ($externalRelativePath && is_file((string) $externalMigration)) {
            Artisan::call('migrate', [
                '--path' => $externalRelativePath,
                '--env' => app()->environment(),
                '--force' => true,
            ]);
        } else {
            // Fallback para ambiente de teste quando o repo de autenticação não está montado no container.
            Schema::create('acesso_usuarios', function (Blueprint $table) {
                $table->id();
                $table->string('nome', 255);
                $table->string('email', 100)->unique();
                $table->string('senha', 255);
                $table->boolean('ativo')->default(true);
                $table->timestamp('ultimo_login_em')->nullable();
                $table->string('ultimo_login_ip', 45)->nullable();
                $table->string('ultimo_login_user_agent', 255)->nullable();
                $table->unsignedSmallInteger('tentativas_login')->default(0);
                $table->timestamp('bloqueado_ate')->nullable();
                $table->timestamp('senha_alterada_em')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('acesso_usuarios')) {
            throw new \RuntimeException('Falha ao preparar tabela acesso_usuarios via migration da autenticação.');
        }
    }

    public function down(): void
    {
        // Tabela pertence à API de autenticação; estoque não remove.
    }

    private function resolverMigrationAutenticacao(string $migrationFile): ?string
    {
        $base = '..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        $candidatos = [
            $base . DIRECTORY_SEPARATOR . $migrationFile,
            '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $migrationFile,
        ];

        foreach ($candidatos as $relPath) {
            if (is_file(base_path($relPath))) {
                return $relPath;
            }
        }

        return null;
    }
};
