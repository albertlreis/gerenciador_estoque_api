<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AcessoUsuariosSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('acesso_usuarios')) {
            $this->command?->warn('Tabela acesso_usuarios não encontrada. Pulei AcessoUsuariosSeeder.');
            return;
        }

        $now = now();

        $rows = [
            [
                'id' => 1,
                'nome' => 'Administrador Seed',
                'email' => 'admin@seed.local',
                'senha' => bcrypt('123456'),
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'nome' => 'Vendedor Seed',
                'email' => 'vendedor@seed.local',
                'senha' => bcrypt('123456'),
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('acesso_usuarios')->upsert(
            $rows,
            ['id'],
            ['nome', 'email', 'senha', 'ativo', 'updated_at']
        );
    }
}
