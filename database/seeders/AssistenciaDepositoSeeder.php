<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cria o Depósito "ASSISTÊNCIA" caso exista tabela de depósitos.
 * Seguro contra diferenças de schema.
 */
class AssistenciaDepositoSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('depositos') || !Schema::hasColumn('depositos', 'nome')) {
            // Nada a fazer – projeto pode não ter módulo de depósitos.
            return;
        }

        // Campos mínimos obrigatórios
        $payload = ['nome' => 'ASSISTÊNCIA'];

        // Acrescenta campos se existirem no schema:
        if (Schema::hasColumn('depositos', 'sigla')) {
            $payload['sigla'] = 'AST';
        }
        if (Schema::hasColumn('depositos', 'ativo')) {
            $payload['ativo'] = true;
        }
        if (Schema::hasColumn('depositos', 'descricao')) {
            $payload['descricao'] = 'Depósito sistêmico para itens em assistência técnica';
        }
        if (Schema::hasColumn('depositos', 'localizacao')) {
            $payload['localizacao'] = 'Centro de Serviços';
        }

        // updateOrInsert pelo nome
        DB::table('depositos')->updateOrInsert(['nome' => $payload['nome']], $payload);
    }
}
