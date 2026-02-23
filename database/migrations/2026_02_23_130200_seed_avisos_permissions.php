<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('acesso_permissoes')) {
            return;
        }

        $now = now();
        $permissoes = [
            [
                'slug' => 'avisos.view',
                'nome' => 'Avisos: Visualizar',
                'descricao' => 'Permite visualizar o mural de avisos',
            ],
            [
                'slug' => 'avisos.manage',
                'nome' => 'Avisos: Gerenciar',
                'descricao' => 'Permite criar, editar e arquivar avisos',
            ],
        ];

        foreach ($permissoes as $permissao) {
            DB::table('acesso_permissoes')->updateOrInsert(
                ['slug' => $permissao['slug']],
                [
                    ...$permissao,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('acesso_permissoes')) {
            return;
        }

        DB::table('acesso_permissoes')
            ->whereIn('slug', ['avisos.view', 'avisos.manage'])
            ->delete();
    }
};

