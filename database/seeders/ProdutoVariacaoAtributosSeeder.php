<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProdutoVariacaoAtributosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $atributos = [];

        $valores = [
            ['cor', 'vermelho'], ['cor', 'azul'], ['cor', 'preto'], ['cor', 'branco'], ['cor', 'verde'],
            ['tipo_metal', 'inox'], ['tipo_metal', 'ferro'], ['tipo_metal', 'aço escovado'], ['tipo_metal', 'alumínio'],
            ['tipo_madeira', 'carvalho'], ['tipo_madeira', 'nogueira'], ['tipo_madeira', 'pinus'], ['tipo_madeira', 'imbuia'],
        ];

        $variacoes = DB::table('produto_variacoes')->get();
        $i = 0;

        foreach ($variacoes as $variacao) {
            $totalAttrs = rand(2, 3);
            for ($j = 0; $j < $totalAttrs; $j++) {
                $index = ($i + $j) % count($valores);
                $atributos[] = [
                    'id_variacao' => $variacao->id,
                    'atributo'    => $valores[$index][0],
                    'valor'       => $valores[$index][1],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
            $i++;
        }

        DB::table('produto_variacao_atributos')->insert($atributos);
    }
}
