<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CategoriasSeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();

        DB::table('categorias')->insert([
            [
                'nome'       => 'Sofás',
                'descricao'  => 'Sofás modernos e confortáveis para ambientes residenciais e corporativos',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Mesas',
                'descricao'  => 'Mesas para sala de jantar, escritório e áreas de convivência',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Cadeiras',
                'descricao'  => 'Cadeiras ergonômicas, de design moderno e materiais nobres',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Camas',
                'descricao'  => 'Camas confortáveis com designs sofisticados para o quarto',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Estantes',
                'descricao'  => 'Estantes para organização e decoração, unindo estilo e funcionalidade',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
