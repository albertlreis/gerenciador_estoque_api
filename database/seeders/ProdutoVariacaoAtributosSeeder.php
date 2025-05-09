<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seed responsável por criar atributos dinâmicos para cada variação de produto.
 * Cada variação recebe dois atributos aleatórios entre cor, tipo de madeira e tipo de metal.
 */
class ProdutoVariacaoAtributosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $atributos = [];

        $valores = [
            ['cor', 'vermelho'],
            ['cor', 'azul'],
            ['cor', 'preto'],
            ['tipo_metal', 'inox'],
            ['tipo_metal', 'ferro'],
            ['tipo_metal', 'aço escovado'],
            ['tipo_metal', 'alumínio'],
            ['tipo_madeira', 'carvalho'],
            ['tipo_madeira', 'nogueira'],
            ['tipo_madeira', 'pinus'],
            ['tipo_madeira', 'imbuia'],
            ['cor', 'branco'],
            ['cor', 'cinza'],
            ['cor', 'bege'],
            ['cor', 'verde musgo'],
        ];

        $variacoes = DB::table('produto_variacoes')->get();
        $i = 0;

        foreach ($variacoes as $variacao) {
            for ($j = 0; $j < 2; $j++) {
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
