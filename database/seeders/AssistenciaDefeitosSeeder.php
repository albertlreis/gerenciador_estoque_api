<?php

namespace Database\Seeders;

use App\Models\AssistenciaDefeito;
use Illuminate\Database\Seeder;

/**
 * Seed do Catálogo de Defeitos.
 */
class AssistenciaDefeitosSeeder extends Seeder
{
    public function run(): void
    {
        $defeitos = [
            ['codigo' => 'EST-TR',  'descricao' => 'Estrutura trincada',        'critico' => true,  'ativo' => true],
            ['codigo' => 'EST-FO',  'descricao' => 'Estrutura fora de esquadro', 'critico' => false, 'ativo' => true],
            ['codigo' => 'REV-SOL', 'descricao' => 'Revestimento descolando',    'critico' => false, 'ativo' => true],
            ['codigo' => 'PED-RS',  'descricao' => 'Pé desalinhado/solto',       'critico' => false, 'ativo' => true],
            ['codigo' => 'PNT-BR',  'descricao' => 'Pintura com bolhas/rachada', 'critico' => false, 'ativo' => true],
            ['codigo' => 'TEC-DEF', 'descricao' => 'Tecido com defeito',         'critico' => false, 'ativo' => true],
            ['codigo' => 'MEC-RUI', 'descricao' => 'Mecanismo ruidoso',          'critico' => false, 'ativo' => true],
            ['codigo' => 'SOLD-FL', 'descricao' => 'Solda falha',                'critico' => true,  'ativo' => true],
        ];

        foreach ($defeitos as $d) {
            AssistenciaDefeito::query()->updateOrCreate(
                ['codigo' => $d['codigo']],
                $d
            );
        }
    }
}
