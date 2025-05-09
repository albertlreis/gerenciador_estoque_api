<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $produtos = [];

        $base = [
            ['nome' => 'Produto Modelo', 'descricao' => 'Produto de demonstração.', 'id_categoria' => 1],
            ['nome' => 'Mesa Decorativa', 'descricao' => 'Mesa pequena para decoração.', 'id_categoria' => 2],
            ['nome' => 'Cadeira Dobravél', 'descricao' => 'Cadeira leve e dobrável.', 'id_categoria' => 3],
            ['nome' => 'Cama Infantil', 'descricao' => 'Cama segura para crianças.', 'id_categoria' => 4],
            ['nome' => 'Estante Alta', 'descricao' => 'Estante com prateleiras altas.', 'id_categoria' => 5],
        ];

        for ($i = 1; $i <= 40; $i++) {
            $ref = $base[$i % count($base)];
            $produtos[] = [
                'nome' => $ref['nome'] . ' #' . $i,
                'descricao' => $ref['descricao'],
                'id_categoria' => $ref['id_categoria'],
                'ativo' => true,
                'altura' => rand(50, 200),
                'largura' => rand(80, 220),
                'profundidade' => rand(50, 150),
                'peso' => rand(10, 100),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('produtos')->insert($produtos);
    }
}
